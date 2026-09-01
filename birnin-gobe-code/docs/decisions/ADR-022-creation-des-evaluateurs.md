# ADR-022 — Créer un évaluateur depuis le back-office

*Statut : accepté. Portée : §11.1 (répartition et évaluateurs), §14 (rôles et habilitations),
§13.3 (journal des décisions). Renverse une décision d'ADR-021, étend ADR-006.*

## Contexte

ADR-021 a ouvert l'accès évaluateur — commande `evaluator:create`, écran de connexion — et a
explicitement refusé un écran de création dans le back-office :

> Le §11.1 confie au responsable la répartition des dossiers, pas le recrutement : celui-ci
> relève d'une décision institutionnelle hors plateforme, et lui donner un formulaire dans le
> back-office laisserait croire le contraire.

**Le porteur du concours a tranché l'inverse.** Le raisonnement n'était pas faux, mais il
arbitrait une question d'organisation qui ne revient pas au code : si l'administrateur est
celui qui décide qui évalue, lui imposer un accès SSH pour le faire n'est pas une garantie,
c'est un obstacle.

## Ce que cette ADR ne remet pas en cause

**ADR-006 interdit l'inscription interne, et cela reste vrai.** La phrase qui l'explique vise
un formulaire *public* : « un formulaire public capable de produire un administrateur est une
élévation de privilège offerte ». Un formulaire derrière `auth` + `role:admin`, rempli par
quelqu'un qui détient déjà le privilège maximal, n'est pas une inscription — c'est un acte
d'administration, journalisé comme tel.

**`evaluator:create` reste.** Une plateforme dont le seul chemin de provisionnement passe par
son propre back-office ne peut plus créer le premier compte quand personne ne peut s'y
connecter. C'est la raison d'être d'`admin:create`, et elle vaut ici.

## Décisions

### 1. L'évaluateur définit lui-même son mot de passe

Trois options existaient : l'administrateur en choisit un, le système en génère un affiché une
fois, ou l'invité le définit par lien. **La troisième est retenue**, et c'est la seule qui
donne au compte **un seul détenteur**.

Le compte naît avec 64 caractères aléatoires que personne ne connaît — pas même
l'administrateur qui vient de le créer. Un test le vérifie en tentant la connexion avec les
identifiants qu'un administrateur pourrait supposer.

Cela compte parce qu'un compte évaluateur note des candidatures. Un mot de passe connu de deux
personnes rend indéfendable la question « qui a mis cette note ? ».

### 2. Une table d'invitations distincte, et un délai distinct

Les jetons de réinitialisation expirent en **60 minutes** — un délai correct pour un lien que
l'on vient de demander soi-même, inutilisable pour une invitation envoyée par un tiers. Une
invitation partie vendredi soir serait morte lundi matin.

`internal_invitations`, **sept jours**. Deux raisons de ne pas partager la table des
réinitialisations :

- la durée serait alors portée par le **courtier utilisé à la vérification**, pas par le
  jeton : un lien de réinitialisation ordinaire présenté au courtier des invitations vivrait
  sept jours ;
- choisir le courtier par un paramètre d'URL rendrait cette rallonge **falsifiable**.

Le choix du courtier est donc fixé par la route, jamais par une donnée d'entrée.

### 3. Le rôle n'est ni un champ ni une option

Le formulaire porte deux champs : nom et adresse. Le rôle est écrit en dur dans l'action, comme
il l'est dans chaque commande. Un test envoie `role=admin` avec la requête et vérifie que le
compte créé est bien évaluateur.

`role` reste hors de `$fillable` : les deux barrières d'ADR-004 tiennent toujours.

### 4. Un seul cas d'usage, deux appelants

La création vivait dans une commande Artisan, hors de portée d'un contrôleur. La dupliquer
aurait produit deux copies des règles de mot de passe, d'unicité et de rôle — ADR-006 dit ce
qu'il advient de deux copies d'une règle de sécurité.

`App\Domain\Auth\CreerUtilisateurInterne` est désormais le seul endroit où un compte interne
naît. La commande et le contrôleur l'appellent. Même chose pour l'émission :
`EnvoyerInvitationInterne` sert la création **et** la relance, qui produisent donc
nécessairement le même jeton, le même message et le même lien.

### 5. Habiliter quelqu'un est une décision, et le journal le dit

Nouvelle action `INTERNAL_USER_CREATED`, de poids **décisif** : le geste crée un pouvoir, il
n'enregistre pas un fait. Le §13.3 sert à savoir qui a décidé quoi, et « qui a habilité qui »
en fait partie.

`AuditWriter::write()` accepte maintenant un acteur nul. La colonne l'était depuis l'origine,
la signature ne l'était pas — et une création en ligne de commande n'a personne à nommer. Lui
attribuer un acteur serait pire que de n'en nommer aucun.

### 6. L'écran ne prétend pas avoir envoyé ce qui n'est pas parti

**C'est le défaut qui a réellement bloqué quelqu'un.** La première version affichait « une
invitation vient de partir vers… » alors que `MAIL_MAILER=log` l'écrivait dans un fichier de
journal. L'administrateur croyait avoir prévenu, l'évaluateur ne recevait rien, et le compte
restait inaccessible sans que rien ne le dise. Les deux évaluateurs créés ce jour-là n'ont pas
pu se connecter, et le message « Identifiants incorrects » était exact sans être utile.

C'est exactement le silence qu'ADR-019 a corrigé pour les notifications : une trace qui affirme
un envoi que personne n'a fait.

`ResultatDInvitation` distingue donc trois états, et **« pas remis » n'est pas « échoué »** —
même règle qu'ADR-018 entre `SKIPPED` et `FAILED`. Un transport `log` ne tombe pas en panne :
il n'existe pas comme moyen de joindre quelqu'un.

Quand personne ne reçoit rien, **le lien est rendu à l'administrateur**, avec la phrase qui
l'explique. Sans transport de courriel, il est la seule façon d'ouvrir le compte, et le taire
rendrait la fonctionnalité inutilisable dans l'environnement où elle tourne aujourd'hui.

**Avec un transport réel, le lien n'est pas affiché** : un lien d'accès montré à l'écran finit
dans une capture, un ticket ou un tableau blanc.

### 7. Un compte jamais activé se distingue d'un compte actif

Rien ne les séparait : on pouvait affecter des dossiers à quelqu'un qui n'ouvrirait jamais la
plateforme. La liste porte désormais « n'a pas encore défini son mot de passe », déduit de la
présence d'un jeton non consommé — le jeton disparaît dès que le mot de passe est défini, ce
qui fait de son absence une preuve suffisante.

L'état est lu en **une requête** pour toute la liste : une par évaluateur serait un N+1 sur un
écran ouvert à chaque affectation.

### 8. La relance existe, et elle est bornée

Une invitation se perd, atterrit en indésirables, ou expire. Sans relance, le seul recours
serait de supprimer le compte pour le recréer — en effaçant ses affectations.

Émettre un nouveau jeton **invalide le précédent** (`email` est la clé primaire) : deux liens
valides pour un compte multiplieraient les chemins d'entrée sans rien apporter.

**Refusée si le compte a déjà son mot de passe.** Envoyer un lien de définition à quelqu'un qui
n'a rien demandé ressemblerait à une usurpation.

### 9. Le retour se fait vers l'espace de la personne

Après avoir défini son mot de passe, l'invité arrive sur `/evaluator/login`. Le parcours de
réinitialisation existant renvoie tout le monde vers `/login`, l'écran candidat, incapable de
connecter un interne — le même défaut que celui corrigé pour les visiteurs anonymes.

## Conséquences

- Le back-office devient un chemin de création de comptes habilités. C'est un pouvoir nouveau,
  et c'est pourquoi il est journalisé au poids décisif.
- `HandleInertiaRequests` partage une seconde clé de session, `flash.invitationLink`.
- Le document de passation devra citer les deux chemins de provisionnement.

## Ce qui reste ouvert

- **La révocation.** Rien ne retire un rôle ni ne ferme un compte. Un évaluateur qui quitte le
  concours garde son accès **et ses affectations** jusqu'à intervention en base. Ouvrir la
  création rend ce manque plus visible qu'il ne l'était : on peut désormais créer sans limite
  ce qu'on ne sait pas défaire.
- **Le transport de courriel.** `MAIL_MAILER` vaut toujours `log`. L'affichage du lien rend la
  fonctionnalité utilisable sans lui, il ne le remplace pas : à vingt évaluateurs, transmettre
  vingt liens à la main est un travail que personne ne devrait avoir à faire.
- **La purge des invitations expirées.** `internal_invitations` n'est purgée par rien. Un jeton
  périmé n'ouvre aucun accès, mais la ligne subsiste — et fait apparaître le compte comme « en
  attente » indéfiniment, ce qui est vrai mais ne distingue pas une invitation d'hier d'une
  invitation d'il y a six mois.
- **Le jury.** `UserRole::JURY` reste sans porte ni écran. Les classes abstraites rendront
  l'ajout mécanique le jour où le §12 existera.
