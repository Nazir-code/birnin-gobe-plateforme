# ADR-019 — Ce qu'on sait vraiment d'un message envoyé

*Statut : accepté. Portée : §8.3 (les six événements), §9.3 (alerte sur les échecs d'envoi),
§15.1 (analyse des pièces). Corrige ADR-018 et clôt l'action n° 2 du document de passation.*

## Contexte

ADR-018 a fait partir les six messages du §8.3 et posé une table de traces, avec une promesse
explicite : pouvoir répondre à « **le candidat a-t-il été prévenu de son rejet, et quand ?** ».
La phrase qui la justifiait est encore la bonne — « une plateforme qui écarte un dossier sans
pouvoir prouver qu'elle l'a dit ne peut pas défendre sa décision ».

Cette promesse n'était pas tenue. Deux défauts, indépendants, qui se renforçaient.

### 1. La trace disait « envoyé » sans que rien ne soit parti

`MessageTransactionnel implements ShouldQueue` — décision d'ADR-018, et la bonne : un serveur
SMTP lent ne doit pas faire attendre un dépôt. Mais `SendNotification` écrivait `SENT` juste
après `Notifier::send()`, c'est-à-dire juste après avoir **écrit dans Redis**. Personne
n'avait encore parlé à un serveur de courriel.

Conséquences, dans l'ordre de gravité :

- La trace affirmait qu'un candidat avait été prévenu alors que le message pouvait encore
  échouer. C'est précisément la question à laquelle la table existe pour répondre, et elle y
  répondait faux.
- Le `try/catch` d'ADR-018 n'attrapait plus rien d'utile : il entourait une mise en file, pas
  un envoi. Un SMTP en panne ne lève pas là — il lève dans le `worker`, plus tard.
- Donc **`FAILED` ne pouvait jamais s'écrire pour une panne d'envoi réelle**, et l'alerte
  `notifications.echecs` du §9.3, créée par ADR-018 pour ce cas exact, restait à zéro pendant
  toute la panne qu'elle était censée signaler.

ADR-014 refusait « un compteur toujours à zéro, qui apprend à ignorer l'écran ». Celui-ci
était pire : il était à zéro *en apparaissant fonctionner*.

### 2. La table où les échecs devaient atterrir n'existait pas

`config/queue.php` désigne `failed_jobs` (pilote `database-uuids`) depuis le premier commit,
et aucune migration ne la créait. Le document de passation l'avait relevé — action n° 2,
criticité haute — en la classant « ouverte, sans effet » : à l'époque `grep -r "dispatch("` ne
rendait rien.

Ce n'est plus vrai depuis deux incréments. L'analyse antivirus du §15.1 et les six messages du
§8.3 sont mis en file. La première tâche à épuiser ses essais faisait donc lever un
`SQLSTATE[42P01] relation « failed_jobs » does not exist` **au moment d'enregistrer son
échec** : la tâche perdue, l'erreur d'origine perdue avec elle, et dans le journal une erreur
SQL qui masquait la vraie.

Mis bout à bout : un courriel de rejet qu'un SMTP en panne refusait trois fois disparaissait
sans laisser de trace exploitable, pendant que la trace de la base affirmait `SENT` et que
l'écran de pilotage restait vert.

## Décisions

### 1. Un quatrième statut, parce que « confié » n'est pas « parti »

`DeliveryStatus::QUEUED` s'ajoute aux trois d'ADR-018. Une ligne **naît ouverte** et se
referme quand son issue est connue.

Deux réponses étaient possibles, une seule est honnête :

- **garder trois statuts et écrire `SENT` à la mise en file** — c'était l'état des lieux, et
  le défaut ;
- **garder trois statuts et n'écrire la ligne qu'à la fin, depuis le `worker`** — écarté : un
  processus tué entre l'envoi et l'écriture laisserait un courriel parti sans aucune trace,
  soit un candidat prévenu que la plateforme croit n'avoir jamais prévenu. Le pire des deux
  sens d'erreur.
- **ouvrir la ligne avant, la refermer après** — retenu. Le seul ordre où une défaillance
  laisse un `QUEUED` visible plutôt qu'un silence.

`settled_at` date la fermeture. `created_at` dit quand on a confié, `settled_at` quand on a
su ; **l'écart entre les deux est ce qui révèle un répartiteur arrêté**, et aucune des deux
colonnes seule ne le dit.

### 2. Cela ne rouvre pas la règle d'ajout seul

`NotificationDelivery` reste en ajout seul : `UPDATED_AT` est nul, une seconde tentative reste
une seconde ligne. `refermer()` n'y contrevient pas — ce n'est pas un envoi réécrit, c'est un
envoi **dont on apprend le sort**, une fois.

La garde est dans la requête : `refermer()` ne touche qu'une ligne encore `QUEUED`. Elle doit
être idempotente parce que trois signaux peuvent arriver pour un même envoi — l'événement
`NotificationSent`, le `failed()` du message, et l'exception attrapée par `SendNotification` —
et que sur une file synchrone les deux derniers se produisent **tous deux** pour le même
échec. Le premier arrivé fixe l'issue ; les suivants ne la contredisent pas.

### 3. Le succès est signalé par un écouteur, l'échec par le message lui-même

L'envoi n'a pas lieu là où il est demandé. `SendNotification` ne peut honnêtement dire que
« confié » ; seul le processus qui a parlé au serveur peut dire « parti ».

- **Succès** : `RefermerLaTraceDEnvoi`, sur `NotificationSent`. Il filtre sur le **type** du
  message, pas sur une liste de classes : la réinitialisation de mot de passe passe par le
  même événement Laravel sans être au tableau du §8.3, et n'a pas de trace à refermer.
- **Échec** : `MessageTransactionnel::failed()`, que `SendQueuedNotifications::failed()`
  appelle **après le dernier essai**.

**Pourquoi ne pas écouter `NotificationFailed`**, qui existe pourtant. Parce qu'il est émis à
*chaque* tentative. Un SMTP qui bafouille une fois puis répond allumerait une alerte
`CRITICAL` que la réussite suivante éteindrait, et un responsable qui voit ce compteur
clignoter sans conséquence apprend à ne plus le regarder. On n'alerte que sur ce qui est
acquis — même raisonnement qu'ADR-014 sur le bruit.

**`$traceId` voyage dans la charge sérialisée du message.** C'est ce qui rend la boucle
possible : sans identifiant embarqué, le `worker` n'aurait aucun moyen de retrouver la ligne à
refermer. Il est nullable, et une trace absente ne fait jamais échouer un envoi — un message
construit hors de `SendNotification` doit partir quand même.

### 4. Une alerte pour la panne que ni `SENT` ni `FAILED` ne peut dire

Un envoi qui échoue produit un `FAILED`. Un envoi que **personne ne dépile** ne produit rien
du tout : le conteneur `worker` à l'arrêt est à la fois la panne la plus totale — plus aucun
candidat n'est prévenu de quoi que ce soit — et la plus silencieuse, puisqu'aucun des deux
statuts terminaux ne s'écrira jamais.

`notifications.file_bloquee` compte les lignes `QUEUED` depuis plus d'une heure. `CRITICAL`,
pour la raison qui vaut déjà pour les échecs : qu'un message soit tombé ou qu'il dorme dans une
file ne change rien pour le candidat qui attend sa décision.

**Une heure, et le seuil est large exprès.** Un seuil serré transformerait chaque pointe de
charge — les vingt courriels d'un lot d'affectation, l'afflux de la veille de clôture — en
alerte qui se résout seule. Une heure d'attente, elle, ne se rattrape pas toute seule.

**Non filtrée par campagne**, à la différence des autres. Une file arrêtée l'est pour tout le
monde ; et le message de création de compte, qui n'a pas de campagne, serait invisible sous un
filtre. L'action à mener n'est pas de l'ordre de l'édition en cours, elle est sur le serveur.

### 5. La garde du rappel de clôture compte désormais ce qui est en file

ADR-018 §7 veut « un seul rappel par personne et par jalon ». La garde interrogeait `SENT`
seul ; avec `QUEUED`, elle aurait laissé la commande du lendemain produire un second message
dès que le répartiteur prend une nuit de retard — exactement le doublon qu'elle existe pour
éviter.

`DeliveryStatus::vautPourUnEnvoi()` répond `SENT` **ou** `QUEUED`. Un échec, lui, ne compte
toujours pas : il mérite d'être retenté, sinon la panne d'un soir prive définitivement
quelqu'un de son rappel — la règle d'ADR-018 est conservée telle quelle.

### 6. `failed_jobs` est créée

Sans commentaire particulier : c'est la table que la configuration désignait depuis le début.
`uuid` est unique parce que le pilote `database-uuids` retrouve une tâche par cet identifiant
— c'est ce que `queue:retry <uuid>` prend en argument.

Un test vérifie que la table et ses colonnes existent. Une configuration qui désigne une table
absente est un défaut qui ne se voit qu'au premier échec, c'est-à-dire au plus mauvais moment ;
une assertion la rend visible en CI.

## Conséquences

- **La trace ne ment plus, mais elle en dit moins.** Sous `Notification::fake()`, les tests
  observent désormais `QUEUED` et non `SENT` : rien n'a réellement été envoyé, et c'est ce que
  la trace doit dire. Deux assertions de la suite d'ADR-018 ont été corrigées en ce sens.
- **L'alerte `notifications.echecs` peut enfin compter quelque chose.** Elle existait depuis
  ADR-018 ; elle n'était atteignable que par une panne de Redis.
- **Le §9.3 gagne une alerte de plus**, `notifications.file_bloquee` — la onzième de
  `ComputeAlerts::pour()`.
- **L'action n° 2 du document de passation est close**, et la ligne « sans effet » qui
  l'accompagnait était devenue fausse depuis deux incréments.
- **`app/Listeners/` apparaît**, et l'écouteur y est déclaré explicitement dans
  `AppServiceProvider` plutôt que laissé à la découverte par convention — même raison que pour
  la policy : une trace qui cesserait silencieusement de se refermer ferait croire à un
  répartiteur en panne alors que tout fonctionne.

## Ce qui reste ouvert

- **La reprise des messages en attente.** L'alerte dit qu'une file est bloquée ; la remettre en
  route reste un geste d'exploitation (`queue:retry`), pas un bouton dans le back-office. À
  arbitrer quand quelqu'un exploitera réellement la plateforme.
- ~~**La purge de `failed_jobs`.**~~ **Traité par ADR-020**, qui retourne le raisonnement : le
  bon moment pour poser une purge est celui où la table est créée, pas celui où elle est déjà
  pleine — d'autant que la charge sérialisée d'une notification en échec contient le dossier
  et le destinataire.
- ~~**Le sort d'une pièce dont l'analyse antivirus échoue définitivement**~~ (§15.1).
  **Traité par ADR-020** : `ScanAttachment::failed()` écrit `UNAVAILABLE`. Rester `PENDING`
  n'était pas seulement imprécis — c'est l'état dont le libellé promet « analyse en cours » et
  dit au candidat de réessayer dans un instant.
- **Tout ce qu'ADR-018 laissait ouvert** reste ouvert : fournisseur SMS, adresse du
  secrétariat, accusé téléchargeable, modèles éditables, désabonnement, vérification d'adresse,
  langues.
