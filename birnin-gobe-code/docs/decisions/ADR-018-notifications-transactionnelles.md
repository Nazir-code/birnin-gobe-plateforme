# ADR-018 — Notifications transactionnelles

*Statut : accepté. Portée : §8.3 (les six événements), §9.2 « Communication », §9.3 (alerte
sur les échecs d'envoi).*

## Contexte

Jusqu'ici la plateforme n'envoyait **rien**. Un candidat déposait son dossier sans accusé, un
vérificateur demandait un complément que personne ne lisait, un évaluateur ne savait qu'un
dossier lui était confié qu'en ouvrant son plan de travail. Le seul courriel existant était
la réinitialisation de mot de passe, qui n'est pas au tableau du §8.3.

ADR-014 en tirait la conséquence explicite : l'alerte du §9.3 sur les échecs de notification
n'existait pas, parce qu'« aucun envoi n'existe, donc rien n'échoue, et une alerte toujours à
zéro apprend à ignorer l'écran ». Cet incrément retourne cette phrase.

## Décisions

### 1. Le tableau du §8.3 devient un enum, et un test le compare au cahier des charges

`NotificationEvent` porte les six événements, et chacun déclare **son destinataire, ses
canaux et son contenu minimum**, repris mot pour mot. Un test vérifie que les six existent et
que chacun déclare les trois.

C'est la seule façon qu'un événement oublié se voie ailleurs qu'en production, du côté de la
personne qui n'a rien reçu. Une liste de six classes dans un dossier ne dit pas qu'il en
manque une septième ; un enum comparé à sa source, si.

### 2. Le SMS est déclaré, jamais servi — et le produit le dit

Le §8.3 veut « Email/SMS » sur quatre événements. Aucun fournisseur n'est choisi, et ce choix
n'est pas technique : il engage un opérateur, une identité d'expéditeur, un coût par message
et quelqu'un pour lire les réponses.

Trois réponses étaient possibles, deux sont mauvaises :

- **prétendre que le SMS part** — le produit mentirait sur une communication ;
- **retirer le SMS du modèle** — l'exigence du §8.3 disparaîtrait, et personne ne saurait
  qu'elle manque ;
- **déclarer le canal et enregistrer l'envoi comme non servi** — retenu.

C'est exactement le traitement de l'analyse antivirus (ADR-017 côté fichiers) : fermé et
visible plutôt que silencieux. Le jour où un fournisseur existe, `NotificationChannel::servi()`
le dit et rien d'autre ne bouge.

**Le §8.3 demande aussi que la soumission parte au « candidat *et* secrétariat ».** Aucune
adresse de secrétariat n'est configurée ; l'inventer enverrait des dossiers vers une boîte
qui n'existe pas. Le destinataire est déclaré dans `NotificationRecipient`, et reste non
servi.

### 3. « Tenté et échoué » n'est pas « jamais tenté »

`DeliveryStatus` a trois cas, et la distinction entre les deux derniers est celle qui compte.
Un SMS qui ne part pas faute de fournisseur n'est pas une panne : c'est une fonctionnalité
absente. Les compter ensemble produirait une alerte permanente — précisément ce qu'ADR-014
refusait — et noierait les vraies pannes.

L'alerte du §9.3 ne compte donc que `FAILED`, et elle est `CRITICAL` : un candidat qu'on n'a
pas pu prévenir d'un rejet ou d'un délai de réponse subit une conséquence réelle, et le temps
joue contre lui.

### 4. L'e-mail part toujours, quelle que soit la préférence

Un candidat qui a coché « SMS » dans son profil doit quand même apprendre qu'il est déclaré
irrecevable. Le §8.3 écrit « Email/SMS », pas « Email ou SMS au choix » : l'e-mail est le
canal de référence, le SMS un doublage.

`NotificationEvent::estOpposable()` marque les trois événements qui portent une décision —
soumission, clarification, décision d'étape. Ceux-là ne se tairont jamais, y compris le jour
où un désabonnement existera. Les trois autres sont des services rendus, et pourront se
couper.

### 5. Un seul point de passage, et il ne fait jamais échouer ce qui l'appelle

`SendNotification` est le seul endroit qui choisit les canaux, tente, et enregistre. Aucun cas
d'usage n'appelle `Notification::send()` directement : recopier ces trois gestes à six
endroits garantirait qu'un événement finisse par partir sans laisser de trace. Même
raisonnement que `StoreApplicationDocument::servir()` pour les téléchargements.

**Une panne d'envoi n'annule jamais le geste métier.** Un serveur SMTP indisponible ne doit
pas faire échouer un dépôt de candidature ni une décision d'admissibilité : le geste est fait,
committé, et la notification en est une conséquence. L'exception est attrapée, tracée en
`FAILED`, et l'alerte la remonte — plutôt que d'être rendue à un candidat qui n'y peut rien.
Un test le vérifie en remplaçant le répartiteur par un double qui lève.

**Les envois partent après le `commit`, jamais dedans** — la règle que le docblock
d'`AssignApplications` annonçait depuis ADR-014. Un accusé de dépôt envoyé dans une
transaction qui échoue ensuite laisserait un candidat en possession de la preuve d'un dépôt
qui n'a pas eu lieu.

### 6. Une table de traces, malgré la règle d'ADR-014

ADR-014 pose que ce qui se recalcule ne se persiste pas. `notification_deliveries` n'y
contrevient pas : **un envoi ne se recalcule pas**. C'est un fait daté, et la question qu'on
posera — « le candidat a-t-il été prévenu de son rejet, et quand ? » — n'a pas d'autre source.
Une plateforme qui écarte un dossier sans pouvoir prouver qu'elle l'a dit ne peut pas défendre
sa décision.

**Une ligne par couple (événement, canal)**, pas par notification : un même fait produit
plusieurs tentatives, dont certaines n'aboutissent pas, et « partiellement envoyé » n'apprend
rien à qui cherche si quelqu'un a été joint.

**Ce qui est enregistré n'est pas le message.** La trace dit qui, quoi, quel canal, quand, et
pourquoi en cas d'échec. Conserver le contenu ferait une seconde copie de données
personnelles, à protéger et à purger, pour une question à laquelle le gabarit répond déjà.

### 7. Le rappel de clôture ne part qu'aux jalons, et une seule fois

Une commande planifiée chaque matin à 9 h, heure de Niamey — un rappel reçu la nuit est lu au
matin parmi vingt autres messages, et celui-ci demande une action dans la journée.

**Les jalons sont J-7 et J-1, en constantes nommées.** Le §8.3 demande un rappel sans fixer de
délai, et le §9.2 ne fait pas figurer ce seuil parmi les paramètres administrables : l'exposer
donnerait à croire qu'il a été arbitré. Même raisonnement que les seuils d'alerte d'ADR-014.

**Un seul rappel par personne et par jalon.** Sans cette garde, la commande enverrait le même
message chaque matin pendant une semaine, et le candidat cesserait de les lire — puis les
filtrerait, y compris le dernier, qui est celui qui compte. C'est l'une des raisons d'être de
la table de traces.

**`--dry-run` existe parce qu'un envoi ne se rattrape pas.** Avant la première campagne
réelle, on voudra savoir combien de personnes seraient jointes sans les joindre.

### 8. Un message par lot d'affectation, pas un par dossier

Le §11.1 fait affecter en lot : un responsable répartit une vingtaine de dossiers d'un geste.
Vingt courriels en trois minutes se lisent comme une panne, puis se filtrent.

**Aucun numéro de dossier dans ce message.** Un courriel voyage ; la liste des dossiers
confiés à quelqu'un est protégée par le §11.3, et reste derrière l'authentification. Le
message dit combien, pour quand, et rappelle la récusation — au moment précis où l'évaluateur
peut se rendre compte qu'il connaît un dossier.

### 9. Ce que les messages ne disent pas

- **L'observation interne du §10.3 n'est jamais envoyée.** Le cahier des charges sépare le
  message candidat de l'observation interne précisément pour que l'envoi soit possible sans
  divulgation ; cette séparation ne vaut que si elle est tenue au moment de l'envoi.
- **Le motif de rejet codifié non plus.** C'est un terme d'instruction interne ; ce qui part
  est le message que le vérificateur a écrit pour le candidat.
- **Le courriel de création de compte ne prétend pas vérifier l'adresse.** Le §8.3 suppose un
  lien de vérification ; ce parcours n'existe pas, et fabriquer un lien qui ne vérifie rien
  serait pire que de ne rien envoyer. Le message dit l'état réel — cette adresse servira à
  vous joindre, corrigez-la si elle est fausse — ce qui est l'information utile.

## Conséquences

- Le §9.2 « Communication » passe d'**absent** à **partiel**, et nomme ce qui manque : pas de
  fournisseur SMS, pas d'adresse de secrétariat, et des modèles qui vivent dans
  `app/Notifications` plutôt que dans un CMS.
- Le §9.3 gagne une neuvième alerte, `notifications.echecs`, que ADR-014 avait explicitement
  écartée faute d'envois.
- Le conteneur `scheduler` du `docker-compose` sert enfin à quelque chose : `routes/console.php`
  était vide.

## Ce qui reste ouvert

- **Le fournisseur SMS.** C'est une décision, pas un développement.
- **L'adresse du secrétariat**, pour le second destinataire de la soumission reçue.
- **L'accusé de réception téléchargeable** que le §8.3 appelle « reçu ». Le courriel en tient
  lieu — il porte les mêmes éléments et un courriel horodaté est déjà opposable — mais un
  document reste attendu.
- **Les modèles éditables sans code** (§9.2 « Communication »), qui supposent le CMS du §4.2.
- **Le désabonnement**, qui ne pourra jamais couper les trois événements opposables. La
  distinction est déjà posée dans le modèle ; le parcours ne l'est pas.
- **La vérification d'adresse électronique**, qui rendrait au message de création de compte le
  contenu que le §8.3 lui prête.
- **Les langues.** Les messages sont en français seulement, comme le reste de la plateforme —
  voir `resources/js/i18n/README.md` : les textes sensibles en haoussa et zarma ne sont pas
  inventés tant qu'ils ne sont pas validés institutionnellement. Un courriel de rejet est
  précisément un texte sensible.
