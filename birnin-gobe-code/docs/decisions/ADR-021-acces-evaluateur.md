# ADR-021 — Provisionner et connecter les évaluateurs

*Statut : accepté. Portée : §11.1 à §11.3 (espace évaluateur), §14 (rôles et habilitations).
Étend ADR-006 aux comptes évaluateurs, sans en modifier une seule règle.*

## Contexte

L'espace évaluateur était **protégé mais inatteignable**. Trois pièces existaient depuis
longtemps, et la quatrième manquait :

- `UserRole::EVALUATOR` est dans l'enum depuis ADR-004 ;
- les cinq routes de `/evaluator/` sont livrées depuis ADR-015, sous
  `middleware(['auth', 'role:evaluator'])` ;
- `AssignApplications`, `AssignmentBoardQuery` et `AssignApplicationsRequest` **lisent** ce
  rôle pour répartir les dossiers ;
- **rien ne l'écrivait**, et **aucun écran de connexion ne l'admettait**.

Le seul endroit qui attribuait `EVALUATOR` était `DemonstrationSeeder` — un jeu de
démonstration. Un compte évaluateur ne pouvait donc naître que d'une écriture directe en base.
Et même provisionné ainsi, il n'avait pas de porte : `/admin/login` filtre sur
`UserRole::ADMIN` via `attemptWhen`, `/login` est l'écran candidat. Un évaluateur y saisissant
ses bons identifiants était refusé — correctement, mais définitivement.

C'est exactement la situation qu'ADR-006 décrivait pour l'administration — « `/admin/dashboard`
était protégé par `auth` + `role:admin` et donc **inatteignable par qui que ce soit** » — et
qu'elle a corrigée pour ce seul espace. L'évaluation est restée dans l'état d'avant.

Le §11.1 confie au responsable la **répartition** des dossiers, et le back-office la sert déjà
(`EvaluatorController::storeAssignments`). Il ne dit rien du recrutement des évaluateurs : il
suppose qu'ils existent.

## Décisions

### 1. Provisionnement en ligne de commande, et rien d'autre

`php artisan evaluator:create`, sur le modèle exact d'`admin:create`. Toutes les raisons
d'ADR-006 valent sans transposition : pas d'inscription interne, pas de seeder à identifiants
figés, mot de passe jamais en argument de ligne de commande, rôle imposé côté serveur.

~~**Pas d'écran « Créer un évaluateur » dans le back-office.** Le §11.1 confie au responsable la
répartition des dossiers, pas le recrutement : celui-ci relève d'une décision institutionnelle
qui se prend hors de la plateforme. Lui donner un formulaire laisserait croire le contraire, et
ferait du back-office une fabrique de comptes habilités — le §14 encadre les habilitations,
il ne les distribue pas.~~

> **Renversé le 01/09/2026 par ADR-022, sur décision du porteur du concours.** Le raisonnement
> n'était pas faux, mais il arbitrait une question d'organisation qui ne revient pas au code :
> si l'administrateur est celui qui décide qui évalue, lui imposer un accès SSH pour le faire
> n'est pas une garantie, c'est un obstacle.
>
> Ce qui est conservé : `evaluator:create` reste — une plateforme dont le seul chemin de
> provisionnement passe par son propre back-office ne peut plus créer le premier compte ; le
> rôle n'est ni un champ ni une option ; et la création est désormais journalisée au poids
> décisif, parce qu'habiliter quelqu'un crée un pouvoir.

### 2. Une seule implémentation, pour la commande comme pour la connexion

ADR-006 pose que « dupliquer une règle de sécurité, c'est accepter que les deux copies
divergent ». Copier `CreerAdministrateur` et `AdminSessionController` en changeant `ADMIN` en
`EVALUATOR` aurait produit exactement ces deux copies.

Deux classes abstraites portent donc tout le comportement :

- `CreerUtilisateurInterne` — politique de mot de passe, normalisation de l'adresse, unicité,
  refus de promouvoir un compte existant, saisie masquée ou `--password-stdin` ;
- `ConnexionInterne` — vérification du rôle **avant** l'ouverture de session, message d'échec
  indistinct, absence de « rester connecté », refus de rejouer une URL mémorisée hors de
  l'espace.

Les quatre classes concrètes ne déclarent que ce qui les distingue. `AdminSessionController` a
perdu 90 lignes sans changer d'un comportement : ses tests, inchangés, le vérifient.

**Le rôle est une constante de la sous-classe, jamais une option.** Un `--role=` ferait
dépendre le privilège d'une saisie, et transformerait la différence entre deux commandes en un
argument qu'on peut se tromper de taper. Un test l'assied sur la *définition* des commandes —
la seule formulation qu'un futur `--role` ferait échouer.

### 3. Une clé de limitation propre à l'espace

ADR-006 préfixe la clé du limiteur par espace, pour qu'« un espace ne puisse pas en bloquer un
autre ». Deux espaces internes qui partageraient la leur rouvriraient précisément ce déni de
service : saturer `/evaluator/login` avec l'adresse d'un administrateur lui fermerait le
back-office.

`interne-admin` et `interne-evaluateur` sont donc distinctes, et deux tests vérifient les deux
sens du cloisonnement.

### 4. La redirection des visiteurs anonymes couvre enfin l'évaluation

`redirectGuestsTo` connaissait deux cas : `/admin/*` vers l'accès interne, tout le reste vers
la connexion candidat. `/evaluator/*` tombait donc dans « tout le reste » — un évaluateur
anonyme atterrissait sur un formulaire incapable de l'authentifier. **Une porte fermée dont la
sonnette mène chez le voisin.**

C'est le raisonnement littéral d'ADR-006 — « envoyer vers la connexion candidat quelqu'un qui
tape `/admin/...` le placerait dans un formulaire qui ne peut pas le connecter » — appliqué à
l'espace qu'elle n'avait pas traité.

### 5. La déconnexion évaluateur ramène à l'accès évaluateur

L'interface pointait sur `/logout`, la déconnexion candidat, qui renvoie vers `/login`. Un
évaluateur se déconnectait donc pour atterrir sur un écran qui ne peut pas le reconnecter.
`evaluator.logout` corrige le retour.

**La déconnexion exige `auth` mais pas `role:evaluator`.** Quelqu'un dont le rôle vient d'être
retiré doit pouvoir fermer sa session, et non rester enfermé dedans avec une session valide
qu'aucune route ne lui permet plus de terminer.

### 6. Ce que l'écran de connexion ne propose pas

Ni « créer un compte », ni « mot de passe oublié », ni lien vers l'espace candidat — même
dépouillement que l'accès administration, et pour la même raison : les comptes sont
provisionnés hors ligne, il n'y a rien à proposer à qui n'en a pas. La page est joignable et
annoncée nulle part (ADR-003).

## Conséquences

- `UserRole::EVALUATOR` cesse d'être un rôle que le code sait lire et ne sait pas écrire.
- Les cinq routes d'ADR-015 deviennent franchissables ; ADR-016 (arbitrage des écarts) et le
  §11.1 supposaient déjà des évaluateurs connectés.
- `DemonstrationSeeder` n'est plus le seul chemin d'existence d'un évaluateur — il reste utile
  pour parcourir le back-office, mais n'est plus un contournement obligé.
- Le document de passation devra citer `evaluator:create` à côté d'`admin:create`.

## Ce qui reste ouvert

- **Le jury.** `UserRole::JURY` est dans la même situation que `EVALUATOR` avant cette ADR :
  une règle d'accès posée, aucun compte possible, aucune porte. La différence est qu'aucun
  écran jury n'existe encore — il n'y a donc rien derrière la porte, et l'ouvrir maintenant
  serait prématuré. Les deux classes abstraites rendront l'ajout mécanique le jour venu.
- **La révocation.** Aucune commande ne retire un rôle ni ne désactive un compte. Un évaluateur
  qui quitte le concours garde son accès jusqu'à intervention en base — et ses affectations
  avec lui. ADR-022 rend ce manque plus visible : on peut désormais créer sans limite ce qu'on
  ne sait pas défaire.
- ~~**Le premier mot de passe.**~~ **Traité par ADR-022** pour la création depuis le
  back-office : l'invité le définit lui-même par lien, et le compte n'a qu'un détenteur. La
  commande `evaluator:create`, elle, continue de le faire choisir par l'opérateur — elle sert
  à amorcer, pas à recruter.
- **La récusation à l'inscription.** Le §11.3 prévoit qu'un évaluateur signale un conflit
  d'intérêt sur un dossier, et `declareConflict` le sert. Rien ne recueille ses liens déclarés
  au moment où son compte est créé.
