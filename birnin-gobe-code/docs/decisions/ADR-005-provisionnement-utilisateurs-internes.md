# ADR-005 — Provisionnement et authentification des utilisateurs internes

**Statut :** accepté
**Date :** 2026-08-22

## Contexte

ADR-004 a posé l'authentification candidat et l'enum `UserRole`, qui contient
déjà `admin`, `evaluator` et `jury`. Mais aucun compte interne ne pouvait
exister : l'inscription publique produit exclusivement des candidats, il n'y
avait ni seeder, ni commande, ni écran de connexion interne.

Conséquence concrète : `/admin/dashboard` était protégé par `auth` + `role:admin`
et donc **inatteignable par qui que ce soit**. Le back-office existait
visuellement, avec une identité de démonstration en dur (« Aminata S. ») et les
chiffres de la maquette.

## Décision

### Aucune inscription interne, jamais

Il n'existe pas de `/admin/register`, et il ne doit jamais en exister. Un
formulaire public capable de produire un administrateur est une élévation de
privilège offerte, quelles que soient les protections qu'on lui ajoute ensuite.

Un test le vérifie explicitement : `GET` et `POST /admin/register` répondent 404.

### Provisionnement en ligne de commande

```bash
php artisan admin:create
php artisan admin:create --name="Aïcha Diallo" --email=aicha@exemple.ne
printf '%s' "$MDP" | php artisan admin:create --name=… --email=… --password-stdin
```

**Plutôt qu'un seeder.** Un seeder porte des identifiants figés et versionnés,
rejoués à chaque `migrate --seed` — y compris là où ce n'est pas voulu. Un
`AdminSeeder` avec un mot de passe par défaut est un compte administrateur
connu de quiconque lit le dépôt. La commande, elle, ne s'exécute que quand on
la lance, avec un secret que seul l'opérateur connaît.

**Le mot de passe n'est jamais un argument.** Il finirait dans l'historique du
shell et dans la table des processus de la machine. Deux entrées sont
possibles :

- saisie masquée (`secret()`), demandée deux fois et comparée en `hash_equals` ;
- `--password-stdin`, lu directement sur l'entrée standard.

Le second existe parce que Symfony Console désactive l'interactivité dès que
l'entrée n'est pas un terminal : `secret()` renverrait alors sa valeur par
défaut. La lecture directe rend le provisionnement scriptable (CI, E2E,
déploiement) sans jamais exposer le secret sur la ligne de commande.

Le mot de passe n'est **pas réaffiché** après création. Celui qui l'a saisi le
connaît, et une trace dans un terminal ou un journal de CI n'aurait aucune
raison d'exister.

### Validation identique à l'inscription publique

Mêmes règles, `Password::defaults()` compris : la politique de mot de passe du
projet ne se relâche pas parce que la saisie vient d'un terminal. Unicité de
l'e-mail vérifiée en base ; la commande **crée**, elle ne promeut pas — un
compte candidat existant ne peut pas être converti en administrateur par
inadvertance.

Le rôle est assigné côté serveur (`UserRole::ADMIN`), jamais lu depuis une
saisie. `role` reste hors de `$fillable`, comme pour l'inscription publique :
les deux barrières d'ADR-004 valent aussi ici.

### Connexion interne séparée

`/admin/login`, servi par `AdminSessionController`, distinct de la connexion
candidat.

La page est **joignable** mais annoncée nulle part : ni le portail public, ni
l'espace candidat, ni les écrans d'authentification candidat n'y renvoient
(ADR-003). Elle ne propose ni création de compte, ni mot de passe oublié, ni
lien vers l'espace candidat — il n'y a rien à proposer à qui n'a pas de compte
interne.

### Le rôle est vérifié *avant* que la session existe

Point le plus important de cette ADR.

```php
Auth::attemptWhen(
    $identifiants,
    static fn (User $user): bool => $user->hasRole(UserRole::ADMIN),
    false, // pas de « rester connecté »
);
```

`attemptWhen` valide les identifiants, exécute la garde, et n'ouvre la session
que si elle passe. Un candidat qui connaît `/admin/login` et fournit ses **bons**
identifiants n'est à aucun instant authentifié sur l'espace interne.

L'alternative — `Auth::attempt()` puis `logout()` si le rôle ne convient pas —
laisse exactement la fenêtre qu'on cherche à fermer : une session valide, même
brève, écrite avant la vérification.

Le message d'échec est **identique** pour un e-mail inconnu, un mot de passe
faux et un compte non administrateur. Les distinguer reviendrait à confirmer
qui est administrateur.

Pas de « rester connecté » : une session interne ne survit pas au navigateur
fermé.

### Même infrastructure de session

`SESSION_DRIVER=database`, garde `web`, table `sessions` — rien de nouveau.
Ce qui est séparé, ce sont le **parcours** et l'**autorisation**, pas
l'infrastructure. Un second système de session serait un second endroit où se
tromper.

### Limitation des tentatives, cloisonnée par espace

La règle d'ADR-004 (5 tentatives, 60 secondes, clé e-mail + IP) a été extraite
d'`AuthenticatedSessionController` vers `App\Domain\Auth\LimiteurDeTentatives`,
partagé par les deux flux. Dupliquer une règle de sécurité, c'est accepter que
les deux copies divergent.

La clé est en plus **préfixée par l'espace**. Sans cela, marteler le formulaire
candidat avec l'adresse d'un administrateur suffirait à lui interdire l'accès au
back-office : un déni de service à un seul paramètre. Un espace ne doit pas
pouvoir en bloquer un autre.

### Redirection des visiteurs anonymes

`redirectGuestsTo` dépend désormais de l'espace visé : `/admin/*` renvoie vers
`/admin/login`, tout le reste vers `/login`. Envoyer vers la connexion candidat
quelqu'un qui tape `/admin/...` le placerait dans un formulaire incapable de le
connecter.

Un administrateur déjà connecté qui ouvre `/admin/login` est renvoyé vers son
tableau de bord ; toute autre identité est simplement sortie de l'espace
interne. Ces deux cas sont traités dans le contrôleur, pas par le middleware
`guest`, dont la destination est globale — et sans passer par `auth`, donc sans
boucle de redirection possible.

### Identité réelle sur le tableau de bord

Le nom affiché vient de `auth.user`, déjà partagé par `HandleInertiaRequests`,
via le hook `useAuthUser()` existant. Le paramètre `user` de
`DarkSidebarLayout` devient facultatif et ne subsiste que pour les écrans
encore non authentifiés (`Evaluator/Assignments`).

Le rôle partagé sert **uniquement** à l'affichage. L'autorisation reste faite
par le middleware `role`.

## Ce qui n'est délibérément pas fait

| Sujet | Raison |
|---|---|
| **Évaluateur et jury** | `UserRole` les connaît et leurs routes sont gardées, mais ils n'ont ni provisionnement ni accès interne. Ils viendront avec leurs écrans, pas avant. `admin:create` ne crée que des administrateurs. |
| **Indicateurs du tableau de bord** | Ils dépendent des modèles `Campaign` et `Application` (Admin Phase 2 et 3). Les brancher sur des requêtes factices donnerait l'illusion de données réelles. Le tableau de bord affiche un état d'attente explicite. |
| **Vérification d'e-mail interne** | Même blocage qu'ADR-004 : aucune passerelle SMTP. Un compte provisionné en ligne de commande par un opérateur habilité n'en a de toute façon pas le même besoin qu'une inscription publique. |
| **Second facteur** | Souhaitable pour un back-office d'État, mais c'est une décision d'infrastructure (canal, coût, couverture réseau) qui n'a pas encore été prise. À traiter avant mise en ligne avec de vraies données. |
| **Journal d'audit des connexions internes** | `AuditWriter` existe. Le brancher ici demande de décider ce qu'on conserve et combien de temps — une question de conformité, pas de code. |

## Vérification

- `tests/Feature/ProvisionnementAdministrateurTest.php` — la commande, ses
  refus, et l'absence de tout autre chemin de création.
- `tests/Feature/AdministrationAuthentificationTest.php` — connexion, rôle
  vérifié avant session, 403 pour les autres rôles, déconnexion, cloisonnement
  de la limitation.
- `tests/E2E/admin-acces-interne.spec.ts` — parcours réel : provisionnement,
  connexion, identité affichée, déconnexion, accès retiré.
