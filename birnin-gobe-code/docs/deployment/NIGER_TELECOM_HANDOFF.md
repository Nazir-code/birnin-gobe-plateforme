# BIRNI'NGOBE — Passation technique / Déploiement Niger Télécom

Document destiné au développeur senior qui reprend le projet.
Les commandes d'exploitation sont dans [`NIGER_TELECOM_RUNBOOK.md`](NIGER_TELECOM_RUNBOOK.md).

**Établi par audit du dépôt**, pas d'après l'architecture cible. Chaque
affirmation ci-dessous a été vérifiée dans le code. Les écarts entre l'intention
documentée (README, ADR) et le code réel sont signalés explicitement.

---

## 1. Ce qu'est BIRNI'NGOBE

Plateforme nationale de gestion d'une compétition d'innovation (candidatures,
admissibilité, évaluation/jury, back-office administratif), pour le PIDUREM et
l'ANSI, Niger.

Le cahier des charges, l'analyse technique de passation et les maquettes V1.0
**ne sont pas dans ce dépôt** (retirés volontairement). À demander au porteur du
projet — ils sont la référence fonctionnelle.

---

## 2. Verdict d'audit : ce qui est réellement là

> **Le dépôt est un prototype d'interface posé sur une fondation technique.
> Ce n'est pas une application fonctionnelle.**

### Ce qui fonctionne réellement

| Élément | État |
|---|---|
| 5 écrans React/Inertia rendus et responsives (320 → 1920 px) | Fonctionnel |
| Design system centralisé (`resources/css/app.css`, ADR-002) | Fonctionnel |
| Build frontend Vite | Fonctionnel |
| Pile Docker Compose (8 services) | Se construit et démarre |
| Migrations initiales | 6 tables créées |
| `ApplicationStatus` (enum, 15 statuts) | Code complet |
| `ApplicationStateMachine` | Code complet |
| `SubmitApplication` (transaction, snapshot, audit) | Code complet |
| `AuditWriter` | Code complet |
| Endpoints de santé `/up` et `/api/v1/health` | Fonctionnels |
| Suite E2E Playwright | 26 tests, passants |

### Ce qui n'existe pas dans le code

Vérifié par recherche exhaustive dans `app/`, `config/`, `routes/`,
`database/` :

| Capacité | Constat |
|---|---|
| **Contrôleurs** | **Aucun.** `app/Http/` ne contient que le middleware Inertia. |
| **Authentification** | Aucune route, aucun middleware `auth`. `auth.user` partagé par Inertia vaut toujours `null`. |
| **RBAC / policies** | Aucune policy, aucun rôle. |
| **Appel à `SubmitApplication`** | **Aucun appelant.** Le cas d'usage est du code mort tant qu'aucun contrôleur ne l'invoque. |
| **Données serveur dans les écrans** | **Aucune page ne reçoit de props Inertia.** Les 5 écrans affichent des données statiques (`resources/js/data/demo.ts` + tableaux locaux). |
| **ClamAV** | 0 référence dans le code PHP. |
| **Filament** | 0 référence. Installé par Composer, aucun panel provider déclaré (`bootstrap/providers.php` ne contient que `AppServiceProvider`). |
| **Horizon** | 0 référence. Installé par Composer, non branché. |
| **Téléversements / S3** | 0 usage de `Storage::` ou d'un disque `s3`. |
| **Notifications / mail** | 0 usage. Pas de `config/mail.php`, aucune variable `MAIL_*`. |
| **CMS** | Absent. |
| **Autosauvegarde persistante** | Absente. |
| **Tests PHP** | **Aucun test, aucun `phpunit.xml`** — voir §8. |
| **Traductions ha/dje** | Volontairement absentes (`resources/js/i18n/README.md`). |

La liste de capacités manquantes fournie dans la demande initiale est donc
**exacte**, et plus sévère qu'annoncé sur deux points : il n'y a aucun
contrôleur, et aucun écran n'est relié au backend.

---

## 3. Stack confirmée (versions verrouillées)

Relevées dans `composer.lock` et `package-lock.json`, pas dans les contraintes.

| Technologie | Version | Statut |
|---|---|---|
| Laravel Framework | 13.26.1 | **Installé et utilisé** |
| PHP | `^8.3` requis ; image d'exécution **8.4** | **Installé et utilisé** |
| Inertia Laravel | 3.3.1 | **Installé et utilisé** |
| Inertia React | 3.7.0 | **Installé et utilisé** |
| React | 19.2.8 | **Installé et utilisé** |
| TypeScript | 5.9.3 | **Installé et utilisé** |
| Tailwind CSS | 4.3.3 | **Installé et utilisé** |
| Vite | 7.3.6 | **Installé et utilisé** |
| PostgreSQL | 17-alpine | **Installé et utilisé** (config `pgsql` par défaut) |
| Redis | 8-alpine | **Installé et utilisé** (cache + queues) |
| Caddy | 2-alpine | **Installé et utilisé** |
| Playwright | 1.62.1 | **Installé et utilisé** (26 tests E2E) |
| Pint | 1.30.5 | **Installé, non branché en CI** |
| **Filament** | 5.7.6 | **Installé, NON branché** |
| **Horizon** | 5.48.3 | **Installé, NON branché** |
| **PHPUnit** | 12.5.33 | **Installé, aucun test, aucune configuration** |
| **MinIO** | latest | **Déclaré dans Compose, non utilisé par le code** |
| **ClamAV** | stable | **Déclaré dans Compose, non utilisé par le code** |
| GitHub Actions | — | **Actif** (voir §8) |

---

## 4. Cartographie Docker (`docker-compose.yml`)

8 services, réseau unique `birnin`, 6 volumes nommés.

| Service | Image / build | Ports publiés | Volumes | Dépend de | Healthcheck | Rôle | Obligatoire |
|---|---|---|---|---|---|---|---|
| `app` | build `.` | — | — | postgres (healthy), redis (healthy) | **non** | PHP-FPM :9000 | **Oui** |
| `caddy` | build `infrastructure/caddy/Dockerfile` | **8080→80** | `caddy_data`, `caddy_config` | app | **non** | Reverse proxy + fichiers statiques | **Oui** |
| `postgres` | `postgres:17-alpine` | — | `postgres_data` | — | **oui** (`pg_isready`) | Base de données | **Oui** |
| `redis` | `redis:8-alpine` (`--appendonly yes`) | — | `redis_data` | — | **oui** (`redis-cli ping`) | Cache + queues | **Oui** |
| `worker` | build `.` | — | — | app, redis, postgres | **non** | Processus long, files d'attente | Oui (dès qu'un job existe) |
| `scheduler` | build `.` | — | — | app, redis, postgres | **non** | Processus long, tâches planifiées | Oui (dès qu'une tâche existe) |
| `minio` | `minio/minio:latest` | **9001→9001** (console) | `minio_data` | — | **non** | Stockage S3 de développement | **Non** aujourd'hui (aucun code ne l'utilise) |
| `clamav` | `clamav/clamav:stable` | — | `clamav_data` | — | **non** | Antivirus | **Non** aujourd'hui (aucun code ne l'appelle) |

**Volumes** : `postgres_data`, `redis_data`, `minio_data`, `clamav_data`,
`caddy_data`, `caddy_config`.

### Points d'attention relevés

1. **Aucun volume ne persiste `storage/`** de l'application. Les logs Laravel et
   les sessions (voir §6) vivent dans le conteneur et disparaissent à chaque
   `docker compose down` ou recréation.
2. **Healthchecks absents** sur `app`, `caddy`, `worker`, `scheduler`, `minio`,
   `clamav`. Seuls `postgres` et `redis` en ont. → dette technique. `app` expose
   pourtant `/up` et `/api/v1/health`, exploitables.
3. **L'API MinIO (9000) n'est pas publiée**, seule la console (9001) l'est.
   Accessible uniquement depuis le réseau Docker interne.
4. **Le frontend est compilé deux fois** : dans `Dockerfile` (image `app`) et
   dans `infrastructure/caddy/Dockerfile` (image `caddy`, qui sert réellement
   les fichiers). Le premier utilise `npm ci` avec le verrou, **le second
   utilise encore `npm install` sans copier `package-lock.json`**. Les deux
   images peuvent donc diverger. → **action senior**, voir §10.
5. Le `Caddyfile` déclare un matcher `@notStatic` qui n'est **jamais utilisé**.

---

## 5. Processus longs

`worker` et `scheduler` sont des **processus persistants**, pas des tâches
ponctuelles. Commandes exactes du Compose :

```
worker      php artisan queue:work redis --sleep=1 --tries=3 --timeout=120
scheduler   php artisan schedule:work
```

L'hébergement doit permettre des conteneurs qui tournent en continu. Si Niger
Télécom l'interdit, les alternatives sont :

- `scheduler` → remplaçable par un `cron` hôte appelant
  `php artisan schedule:run` chaque minute ;
- `worker` → **non remplaçable proprement**. `queue:work` doit tourner en
  permanence. Un contournement par cron (`queue:work --stop-when-empty`)
  dégrade fortement la latence de traitement.

Aujourd'hui aucun job n'est mis en file par le code : ces services ne
traiteront rien tant que le métier n'est pas branché. Ils doivent néanmoins
être validés au premier déploiement, car c'est le jalon suivant.

---

## 6. Sessions, cache, files d'attente

| Fonction | Driver par défaut | Source |
|---|---|---|
| Cache | `redis` | `config/cache.php` — `CACHE_STORE` |
| Files d'attente | `redis` | `config/queue.php` — `QUEUE_CONNECTION` |
| **Sessions** | **`file`** | `config/session.php` — `SESSION_DRIVER` |

Redis utilise deux bases : `0` pour les queues (`REDIS_DB`), `1` pour le cache
(`REDIS_CACHE_DB`). `--appendonly yes` est activé, les données survivent au
redémarrage du conteneur.

**Deux problèmes à traiter avant une vraie mise en service :**

1. **Les sessions sont en fichiers**, dans `storage/framework/sessions` du
   conteneur, sans volume. Elles sont perdues à chaque redéploiement, et ne
   fonctionneraient pas avec plusieurs répliques de `app`. Basculer
   `SESSION_DRIVER=redis` est le correctif naturel — **décision d'architecture,
   à prendre par le senior**, non appliquée ici.
2. **`config/queue.php` déclare une table `failed_jobs`** (driver
   `database-uuids`) **qui n'existe dans aucune migration**. Le premier job en
   échec provoquera une erreur SQL. → dette technique.

`SESSION_DRIVER`, `SESSION_SECURE_COOKIE`, `REDIS_PASSWORD` et les variables
`MAIL_*` **ne figurent pas dans `.env.example`** alors que le code les lit.

---

## 7. Base de données

- Cible : **PostgreSQL** (`config/database.php`, défaut `pgsql`).
- 6 tables migrées : `users`, `password_reset_tokens`, `campaigns`,
  `applications`, `attachments`, `audit_events`.

> **NE PAS migrer vers MariaDB sans décision d'architecture explicite.**

Niger Télécom expose MariaDB via CWP. Ce n'est pas une raison de changer :
si Docker est disponible, le conteneur `postgres:17-alpine` fournit la base
attendue. Si Niger Télécom propose plus tard un PostgreSQL managé ou natif, le
senior pourra pointer dessus via les variables `DB_*` sans toucher au code.

Un basculement vers MariaDB impliquerait de revoir les types de colonnes, les
migrations et potentiellement le stockage JSON des snapshots de soumission :
c'est un chantier, pas un réglage.

---

## 8. Tests et CI — écart important

### Ce que la CI fait réellement (`.github/workflows/ci.yml`)

| Job | Contenu |
|---|---|
| `backend` | PHP 8.4 + `pdo_pgsql`, `redis` → `composer install` → **`php artisan test`** |
| `frontend` | Node 22 → **`npm install`** → `npm run build` |

### Ce qu'elle ne fait pas

- **Ne lance pas Playwright.** Les 26 tests E2E ne sont exécutés que
  manuellement.
- **Ne lance pas Pint.** Pint est installé mais jamais appelé.

### Le point critique

`php artisan test` est exécuté par la CI, mais **le dépôt ne contient aucun
test PHP et aucun `phpunit.xml`**. Le dossier `tests/` ne contient que les deux
specs Playwright. Ce job ne valide donc rien, et échouera probablement faute de
configuration PHPUnit.

**Ne pas considérer le badge CI comme une garantie de qualité backend.**
→ action senior, voir §10.

Par ailleurs, l'image `app` est construite avec `--no-dev` : PHPUnit et Pint
**ne sont pas présents dans le conteneur déployé**. Les commandes Laravel
habituelles (`php artisan test`, `vendor/bin/pint`) échouent donc telles
quelles — vérifié. Le runbook §8 documente la voie de contournement.

---

## 9. TLS et exposition réseau

**Confirmé : le `Caddyfile` écoute en HTTP clair sur `:80`**, sans nom de
domaine et sans TLS. Le Compose publie `8080:80`. Aucun port 443 n'est exposé.

Caddy pose déjà trois en-têtes : `X-Content-Type-Options`, `Referrer-Policy`,
`X-Frame-Options`.

### Apache (CWP) contre Caddy

Niger Télécom fait tourner Apache 2.4.65 sous CWP. Apache et Caddy **ne peuvent
pas écouter simultanément sur 80/443 pour la même adresse**. Deux stratégies,
à trancher **après** l'audit serveur — aucune n'est imposée ici :

**Option A — Caddy en frontal**

```
Internet → Caddy :80/:443 → app (PHP-FPM :9000)
```

Nécessite de libérer 80/443 côté Apache et d'en avoir le droit. Avantage :
Caddy obtient et renouvelle le certificat automatiquement (déclarer le domaine
à la place de `:80` dans le `Caddyfile`, et publier `443:443` dans le Compose).

**Option B — Apache reste frontal**

```
Internet → Apache/CWP :80/:443 → reverse proxy → Caddy :8080 (déjà publié)
```

Ne demande aucun changement au dépôt. Le certificat est géré par CWP. Il faut
alors transmettre `X-Forwarded-Proto` et configurer `TrustProxies` côté Laravel,
sinon les URL générées resteront en `http://`.

### Exigences communes une fois le TLS en place

```
APP_URL=https://<domaine>
APP_DEBUG=false
SESSION_SECURE_COOKIE=true
redirection HTTP → HTTPS
aucun contenu mixte
```

---

## 10. Actions techniques identifiées — à réaliser par le senior

Relevées pendant l'audit, **volontairement non appliquées** : ce sont des
décisions d'architecture ou des modifications de code hors périmètre d'une
passation.

| # | Sujet | Constat | Criticité |
|---|---|---|---|
| 1 | `infrastructure/caddy/Dockerfile` | Compile le frontend avec `npm install` sans copier `package-lock.json`, alors que le `Dockerfile` racine utilise `npm ci`. Les deux images peuvent servir des builds différents. | **Haute** |
| 2 | Table `failed_jobs` | Déclarée dans `config/queue.php`, absente des migrations. | **Haute** |
| 3 | `SESSION_DRIVER` | `file` par défaut, sans volume : sessions perdues à chaque redéploiement. | **Haute** |
| 4 | `phpunit.xml` + tests PHP | Absents, alors que la CI lance `php artisan test`. | **Haute** |
| 5 | Persistance de `storage/` | Aucun volume : logs Laravel perdus au redéploiement. | Moyenne |
| 6 | Healthchecks | Absents sur `app`, `caddy`, `worker`, `scheduler`. `/up` et `/api/v1/health` sont disponibles pour les écrire. | Moyenne |
| 7 | `.env.example` | Ne documente ni `SESSION_DRIVER`, ni `SESSION_SECURE_COOKIE`, ni `REDIS_PASSWORD`, ni `MAIL_*`. | Moyenne |
| 8 | CI | N'exécute ni Playwright ni Pint. | Moyenne |
| 8b | **Style de code** | **Pint échoue : 28 fichiers, 19 violations.** Le dépôt ne respecte pas son propre formateur. | Moyenne |
| 8c | **Outillage PHP indisponible** | L'image `app` étant construite `--no-dev`, `php artisan test` et `vendor/bin/pint` **n'existent pas dans le conteneur**. Voir runbook §8 pour la voie de contournement vérifiée. | Moyenne |
| 9 | `Caddyfile` | Matcher `@notStatic` déclaré et jamais utilisé. | Faible |

---

## 11. Audit Niger Télécom — checklist à remplir

**Aucune de ces cases n'a été vérifiée** : le serveur n'était pas accessible
depuis l'environnement de développement. À compléter par le senior.

### Accès et outillage

```
[ ] SSH disponible ?                        →
[ ] root / sudo disponible ?                →
[ ] docker --version                        →
[ ] docker compose version                  →
```

### Ressources

```
[ ] RAM totale                              →
[ ] CPU (cœurs)                             →
[ ] Espace disque total                     →
[ ] Espace disque réellement disponible     →
```

### Réseau

```
[ ] Ports ouvrables (80, 443, autres)       →
[ ] Pare-feu contrôlable ?                  →
[ ] DNS contrôlable ?                       →
[ ] HTTPS possible ?                        →
[ ] Connexions sortantes autorisées ?       →
[ ] S3 externe joignable ?                  →
```

### Serveur web

```
[ ] Apache obligatoire ?                    →
[ ] Caddy peut-il écouter sur 80/443 ?      →
[ ] Apache peut-il faire reverse proxy ?    →
```

### Services et exécution

```
[ ] PostgreSQL autorisé ?                   →
[ ] Redis autorisé ?                        →
[ ] Processus persistants autorisés ?       →
[ ] Volumes Docker persistants autorisés ?  →
[ ] cron disponible ?                       →
```

### Exploitation

```
[ ] Logs système accessibles ?              →
[ ] Monitoring disponible ?                 →
[ ] Sauvegardes disponibles ?               →
```

### PHP 7.2 sur l'hôte — à ne pas conclure trop vite

L'hôte expose PHP 7.2.3. **Ce n'est pas nécessairement bloquant.** Laravel
tourne dans son propre conteneur avec PHP 8.4 ; le PHP de l'hôte n'est alors pas
utilisé par l'application.

Vérifier d'abord :

```bash
docker --version
docker compose version
```

- **Docker disponible** → PHP 7.2 est hors sujet pour l'application.
- **Docker indisponible** → PHP 7.2 devient un blocage réel. Laravel 13 exige
  PHP ≥ 8.3. **Ne pas rétrograder l'application.** Documenter le besoin
  d'évolution du serveur (PHP 8.3+ ou installation de Docker) et le remonter à
  Niger Télécom.

---

## 12. Matrice d'évolution — support de discussion avec Niger Télécom

À compléter après l'audit du §11. Ne rien inventer.

| Capacité | Disponible actuellement | Requis pour | Statut |
|---|---|---|---|
| SSH | À vérifier | Déploiement | |
| Docker | À vérifier | Déploiement | |
| Docker Compose | À vérifier | Déploiement | |
| RAM / CPU / disque | À vérifier | Déploiement | |
| PostgreSQL | Via Docker, sinon à confirmer | Backend | |
| Redis | Via Docker, sinon à confirmer | Cache + files d'attente | |
| Processus persistants (worker) | À vérifier | Traitement asynchrone | |
| Scheduler (ou cron hôte) | À vérifier | Tâches planifiées | |
| Ports 80/443 | À arbitrer avec Apache/CWP | Recette publique | |
| TLS | À configurer | Recette publique | |
| Stockage S3 | À décider | Téléversements (jalon suivant) | |
| Antivirus | À décider | Téléversements (jalon suivant) | |
| Sauvegardes | À définir | Production | |
| Monitoring | À définir | Production | |
| SMTP / passerelle mail | À définir | Notifications (jalon suivant) | |

---

## 13. Ce qui peut être déployé aujourd'hui

```
RECETTE / DÉMONSTRATION TECHNIQUE
```

**Ni production, ni recette fonctionnelle.** Le premier déploiement valide la
chaîne d'infrastructure, pas le métier — il n'y a pas de métier joignable.

Objet du premier déploiement :

```
accès serveur · Docker · réseau · DNS · HTTPS · Laravel qui répond ·
frontend compilé servi · PostgreSQL · Redis · worker · scheduler ·
persistance des volumes · logs · redémarrage propre
```

### Environnement de démonstration uniquement — données fictives

Tant que l'authentification réelle, le RBAC, le stockage S3, l'analyse
antivirus, les sauvegardes, le TLS complet et les workflows métier ne sont pas
livrés et validés :

```
Aucune vraie candidature.
Aucune pièce d'identité réelle.
Aucune donnée personnelle de production.
```

Ce n'est pas une précaution de principe : sans authentification ni policies,
**toutes les routes sont publiques** et n'importe quel visiteur atteint les
écrans candidat, administrateur et évaluateur.

---

## 14. Blocages actuels

### Blocages applicatifs (dans le dépôt)

1. Aucun contrôleur, aucune route protégée : les écrans ne sont pas reliés au
   backend.
2. `SubmitApplication` n'a aucun appelant.
3. Table `failed_jobs` déclarée mais non migrée.
4. Aucun test PHP alors que la CI en lance.

### Blocages d'infrastructure (à lever avec Niger Télécom)

1. Disponibilité de Docker — détermine toute la stratégie (§11).
2. Arbitrage Apache/Caddy sur les ports 80/443 (§9).
3. Autorisation des processus persistants (§5).
4. Volumes Docker persistants.

### Blocages avant production

1. Authentification réelle + RBAC + policies de ressources.
2. TLS complet et cookies sécurisés.
3. Sauvegardes PostgreSQL — **aucune politique définie à ce jour**.
4. Stockage S3 réel et analyse antivirus effective.
5. Sessions en Redis plutôt qu'en fichiers.
6. Monitoring et supervision.

---

## 15. Ce qu'il ne faut surtout pas faire

```
✗ Rétrograder Laravel ou PHP pour correspondre au serveur actuel.
✗ Utiliser le PHP 7.2 de l'hôte pour contourner Docker.
✗ Remplacer PostgreSQL par MariaDB sans décision d'architecture.
✗ Supprimer Redis, worker, scheduler, S3 ou ClamAV du Compose pour
  « réussir » un déploiement immédiat.
✗ Mettre de vraies données candidates dans cet environnement.
✗ Committer un fichier .env ou un secret.
✗ Utiliser `npm run dev` comme mécanisme de production.
✗ Régénérer APP_KEY à chaque déploiement.
✗ Lancer une migration destructive sans sauvegarde et revue.
```

L'infrastructure Niger Télécom évoluera avec le projet : c'est le serveur qui
doit rejoindre l'architecture cible, pas l'inverse.

---

## 16. Premières actions à la réception du dépôt

1. Lire ce document, puis [`NIGER_TELECOM_RUNBOOK.md`](NIGER_TELECOM_RUNBOOK.md).
2. Demander le cahier des charges PIDUREM et l'analyse de passation (hors dépôt).
3. Faire tourner la pile en local : runbook §1 — c'est le moyen le plus rapide
   de se faire une idée exacte de l'état.
4. Remplir la checklist d'audit serveur (§11), en commençant par
   `docker --version`.
5. Compléter la matrice (§12) et la transmettre à Niger Télécom.
6. Trancher l'arbitrage Apache/Caddy (§9).
7. Traiter les actions techniques du §10, par criticité.
8. Déployer en recette (runbook §2) et exécuter les smoke tests (runbook §6).

---

## 17. Références internes

| Document | Contenu |
|---|---|
| `README.md` | Vue d'ensemble, écrans codés |
| `DEPLOIEMENT.md` | Passation générique, non spécifique à Niger Télécom |
| `docs/decisions/ADR-001-monolithe-modulaire.md` | Choix du monolithe modulaire, frontières de domaine |
| `docs/decisions/ADR-002-design-system.md` | Design system et tokens |
| `docs/architecture/BLUEPRINT-UI-FOUNDATION.md` | Contraintes UX non négociables |
| `docs/design/SCREEN_MAP.md` | Correspondance maquette → route → fichier |
