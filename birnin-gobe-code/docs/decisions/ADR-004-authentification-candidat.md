# ADR-004 — Fondation d'authentification candidat

**Statut :** accepté
**Date :** 2026-08-22

## Contexte

Le dépôt n'avait aucune authentification : pas de starter (ni Fortify, Breeze,
Jetstream, Sanctum), aucun contrôleur, aucune route protégée. La séparation des
espaces d'ADR-003 n'était donc que visuelle.

Rattacher une candidature à son propriétaire (`applications.user_id`) exige
d'abord une identité persistante.

## Décision

Authentification par **session native Laravel**, sans starter.

```
Navigateur → route (guest | auth + role) → garde session → User → middleware role → ressource
```

Un starter aurait apporté un ensemble d'écrans et de conventions à désapprendre,
là où le besoin tient en deux contrôleurs. `config/auth.php` était déjà
correctement configuré (garde `web`, provider eloquent).

### Rôles

Enum PHP `App\Domain\Auth\UserRole` : `candidate`, `admin`, `evaluator`, `jury`.
Valeurs stables en anglais, jamais de libellé français persisté — même contrat
que `ApplicationStatus`.

Colonne `users.role` de type `string(32)`, indexée, défaut `candidate`. Pas de
type ENUM natif PostgreSQL : y ajouter une valeur demanderait un `ALTER TYPE`,
alors que l'enum PHP reste la source de vérité.

**Pas de tables `candidate_users` / `admin_users` séparées** : une identité, un
rôle, ce qui permet d'ajouter un rôle sans dupliquer le socle d'authentification.

### Élévation de privilège — deux barrières indépendantes

1. `role` est **hors de `$fillable`** sur le modèle `User`. Même un
   `User::create($request->all())` ne pourrait pas l'écrire.
2. `RegisteredUserController` assigne `UserRole::PUBLIC_SIGNUP` explicitement,
   sans jamais lire la requête.

Vérifié par un test qui poste `role=admin` et contrôle non seulement le rôle
obtenu, mais aussi que l'accès à `/admin` reste refusé.

**Aucune inscription publique ne peut produire un compte interne.** Les comptes
admin, évaluateur et jury seront provisionnés séparément.

### Contrôle d'accès

Middleware `EnsureUserHasRole`, alias `role`, appliqué **au groupe de routes** :
un espace entier est protégé par une déclaration, et toute route ajoutée au
groupe l'est automatiquement. Pas de `if ($user->role === …)` dispersé.

Il répond **403**, pas une redirection : guider un candidat qui saisit `/admin`
vers un écran de connexion reviendrait à lui annoncer l'existence du back-office.

Un visiteur anonyme, lui, est redirigé vers `/login` — c'est `auth` qui s'en
charge, en amont.

### Sessions en base plutôt qu'en fichiers

`SESSION_DRIVER=database`, nouvelle table `sessions`.

Le driver `file` écrivait dans `storage/framework/sessions`, qu'aucun volume
Docker ne persiste : toute recréation de conteneur déconnectait tout le monde, et
le driver empêchait de faire tourner plusieurs répliques du service `app`.

PostgreSQL est déjà une dépendance obligatoire et persistée : **aucun composant
d'infrastructure n'est ajouté**. Redis reste une cible de production possible,
c'est alors un simple changement de `SESSION_DRIVER`.

### Limitation des tentatives

Clé sur **e-mail + adresse IP**, 5 tentatives puis 60 secondes de blocage.

Limiter sur la seule IP punirait tous les candidats derrière un même opérateur
ou un même cybercafé dès qu'une personne se trompe — courant au Niger. Limiter
sur le seul e-mail laisserait balayer les comptes. La combinaison est la
pratique Laravel.

Le message d'échec est identique pour un e-mail inconnu et un mot de passe faux :
les distinguer permettrait d'énumérer les comptes.

### Autres points de sécurité

- Mots de passe hachés en **argon2id** (`config/hashing.php`), via le cast
  `password => 'hashed'`. Vérifié en base : aucun mot de passe en clair.
- `session()->regenerate()` à l'inscription et à la connexion — fixation de
  session.
- `session()->invalidate()` + `regenerateToken()` à la déconnexion.
- CSRF : mécanisme standard Laravel/Inertia, non désactivé.
- Validation exclusivement côté serveur, y compris l'unicité de l'e-mail.

## Reporté en Phase 1B — explicitement non implémenté

| Fonctionnalité | Raison |
|---|---|
| **Vérification d'e-mail** | Aucun `config/mail.php`, aucune variable `MAIL_*`, aucune passerelle SMTP. L'événement `Registered` est bien émis à l'inscription : un listener pourra s'y brancher sans retoucher le contrôleur. **Rien n'est simulé, aucun message n'est prétendu envoyé.** |
| **Mot de passe oublié** | La table `password_reset_tokens` existe et `config/auth.php` est configuré, mais l'envoi du lien exige la même passerelle SMTP. |

Ces deux points dépendent d'une seule décision d'infrastructure : quelle
passerelle d'envoi. Ils ne bloquent pas la persistance des candidatures.

## Vérification

- `tests/Feature/AuthentificationCandidatTest.php` — 20 tests, 57 assertions.
  Premiers tests PHP réels du dépôt.
- `tests/E2E/auth-candidat.spec.ts` — parcours complet inscription → déconnexion
  → reconnexion, sur desktop et mobile.
- La CI exécute désormais réellement PHPUnit avec un service PostgreSQL, plus
  Pint et le typecheck TypeScript.
