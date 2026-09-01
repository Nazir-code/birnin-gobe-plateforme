# ADR-020 — Les garde-fous qui ne gardaient rien

*Statut : accepté. Portée : intégration continue, tâches planifiées, §15.1 (analyse des
pièces), et l'état documenté du dépôt. Clôt plusieurs points laissés ouverts par ADR-019 et
par le document de passation.*

## Contexte

Cet incrément ne livre aucune fonctionnalité. Il corrige une famille de défauts qui ont tous
la même forme : **un dispositif de contrôle existe, il est vert, et il ne contrôle pas ce
qu'on croit.** Un contrôle qui échoue se répare ; un contrôle qui passe à côté de son objet
sans le dire installe une confiance qui n'est adossée à rien.

Quatre cas, plus une documentation qui décrivait un dépôt qui n'existe plus.

## Décisions

### 1. Le contrôle de style était une liste blanche, et elle avait vieilli

La CI appelait Pint avec une énumération de dossiers :

```
vendor/bin/pint --test app/Console app/Domain app/Http app/Models app/Policies \
                       app/Providers database/factories database/seeders tests/Feature
```

Rien dans cette ligne ne dit ce qu'elle **ne** couvre pas. Elle omettait `bootstrap/`,
`config/`, `database/migrations/`, `public/`, `tests/TestCase.php` — et surtout, tout dossier
créé après elle. `app/Jobs` et `app/Notifications` existent depuis plusieurs incréments sans
avoir jamais été vérifiés ; `app/Listeners`, ajouté par ADR-019, ne l'aurait pas été non plus.

Vérification faite sur une copie du dépôt en LF : **quinze fichiers avaient dérivé**, dont
une migration écrite sur une seule ligne (`$table->id(); $table->string('name'); …`) et un
`TestCase` aux imports non triés. Le badge CI était vert pendant tout ce temps.

Pint tourne désormais **sans liste**. Il lit `.gitignore`, donc `vendor/` et `node_modules/`
restent hors du champ sans avoir à être nommés, et un dossier créé demain est couvert le jour
de sa création. Les quinze fichiers sont corrigés dans le même geste ; aucun changement de
comportement, uniquement de la mise en forme.

**Note pour qui vérifie sous Windows** : `core.autocrlf=true` sort les fichiers versionnés en
CRLF, et Pint signale alors `line_ending` sur la quasi-totalité du dépôt, ce qui noie les
vraies violations. Pour distinguer, copier le dépôt, y retirer les `\r`, et lancer Pint sur la
copie — c'est ainsi que les quinze fichiers ont été isolés. La CI, sur Linux, lit du LF et ne
connaît pas ce bruit.

### 2. Une table qui ne se purge jamais

ADR-019 a créé `failed_jobs` et a laissé sa purge ouverte, au motif qu'« sans volume d'échecs,
la planifier maintenant serait prématuré ». C'était le mauvais raisonnement : le bon moment
pour poser une purge est celui où la table est créée, pas celui où elle est déjà pleine.

Ce qu'on y accumule n'est pas anodin. La charge sérialisée d'une notification en échec
contient le dossier et le destinataire — des données personnelles, conservées pour une raison
qui s'épuise en quelques jours.

`queue:prune-failed --hours=168`, une fois par semaine. **Sept jours** parce qu'au-delà une
tâche échouée ne se rejoue plus utilement : un courriel d'admissibilité vieux d'une semaine ne
se renvoie pas tel quel, on reprend contact autrement. Le délai laisse largement le temps de
voir l'alerte du §9.3 et d'agir.

**Et un test qui vérifie que les tâches sont déclarées.** Une commande planifiée est le seul
code qui ne s'exécute jamais pendant le développement : personne ne l'appelle, aucune route
n'y mène, et sa disparition ne casse rien de visible — elle se remarque des semaines plus
tard, quand le rappel n'est pas parti. Une ligne effacée par erreur dans `routes/console.php`
ne laissait aucune trace jusque-là.

### 3. Une pièce dont l'analyse est abandonnée restait « en cours » pour toujours

ADR-019 avait relevé le cas sans le traiter. `ScanAttachment::handle()` traite les cas prévus —
pièce disparue, fichier illisible, analyseur muet — mais pas l'imprévu : base injoignable,
disque objet en panne, mémoire épuisée. Après trois essais, le job était abandonné.

La pièce restait alors `PENDING`, dont le libellé est « Analyse en cours » et le message au
candidat « Réessayez dans un instant ». Un instant qui ne finissait jamais, et un échec
consigné dans `failed_jobs` que rien ne rapprochait du fichier concerné.

`failed()` écrit `UNAVAILABLE`. **Jamais `CLEAN`** : c'est la règle de tout le §15.1 — ce qui
n'a pas été vérifié ne s'ouvre pas. La pièce reste fermée au téléchargement, l'alerte
`pieces.non_analysees` la voit, et la commande de rattrapage peut la reprendre. Un état
terminal et honnête plutôt qu'une promesse de verdict imminent.

### 4. `data/demo.ts` est supprimé

Plus aucun écran ne l'importait : la page d'accueil lit la campagne réelle depuis
`HomeController`, et les autres écrans reçoivent leurs props d'Inertia. Le fichier subsistait,
et il contenait encore exactement ce qu'il ne fallait pas voir revenir — « 5 000+ jeunes
impactés », « 1 200+ projets accompagnés », une clôture au 30 juin 2026 en dur, cinq
thématiques qui ne sont pas celles du concours.

Un fichier mort rempli de constantes plausibles n'est pas neutre : c'est un appel à
l'importer. Le prochain écran qui a besoin d'un chiffre le trouve tout fait, bien nommé, et
déjà dans le dépôt. La règle d'ADR-002 — les valeurs de maquette ne deviennent jamais des
règles codées — se tient mieux sans le fichier qu'avec.

### 5. La documentation décrivait un dépôt qui n'existe plus

Trois affirmations étaient devenues fausses, et toutes trois dans les documents qu'on lit pour
décider quoi faire ensuite :

- La checklist de lancement annonçait que **la page d'accueil affichait encore un calendrier
  de démonstration**. Corrigé depuis, par `HomeController`.
- Elle annonçait que **l'inscription n'était pas limitée en débit**. `LimiteurDInscriptions`
  existe et est branché.
- Le document de passation classait la page d'accueil « **Ouvert** — en attente d'une fenêtre
  sans branche parallèle sur `routes/web.php` ».

Une checklist de lancement qui liste des travaux déjà faits fait perdre du temps ; pire, elle
jette le doute sur les lignes qui, elles, sont exactes. Ces lignes sont retirées ou marquées
résolues.

**Le corps de l'audit de passation n'est pas touché.** Il porte déjà en tête « instantané daté
— à lire comme un historique » et renvoie au tableau du §10 pour l'état courant. Réécrire un
audit daté le priverait de sa seule valeur, qui est de dire d'où l'on part.

## Ce qui n'a pas été fait, et pourquoi

**Playwright ne rejoint pas la CI.** C'était l'intention de départ de cet incrément ; la
mesure l'a écartée.

La voie légère — servir l'application avec `php artisan serve` et surcharger
`PLAYWRIGHT_BASE_URL` / `E2E_ARTISAN`, ce que les specs prévoient explicitement — a été
essayée sur une base neuve. Elle ne tient pas : `artisan serve` est un serveur **mono-processus**
qui sérialise les requêtes, et chaque requête dynamique y a pris **1 à 2 secondes** contre des
attentes de 5 s dans les specs. Plusieurs scénarios échouent sur un formulaire encore en cours
d'envoi — un défaut du banc d'essai, pas de l'application.

La voie documentée reste la pile Compose, que le défaut `E2E_ARTISAN` vise déjà
(`docker compose exec -T app php artisan`). La mettre en CI suppose d'y construire deux images
puis de démarrer six services, et ce travail ne peut pas être validé depuis un poste de
développement. **Poser dans la CI un job qu'on n'a pas pu exécuter serait pire que l'absence
actuelle** : il bloquerait chaque `push` sur des échecs qu'on découvrirait en production de la
CI.

La suite E2E reste donc manuelle — mais la raison est désormais écrite, avec la mesure qui la
fonde, plutôt que le simple constat « Playwright reste manuel ».
