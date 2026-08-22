# BIRNI'NGOBE — Runbook de déploiement et d'exploitation

Commandes pour déployer et exploiter la pile. Le contexte, l'état réel du
dépôt et l'audit serveur sont dans
[`NIGER_TELECOM_HANDOFF.md`](NIGER_TELECOM_HANDOFF.md) — **à lire d'abord**.

Toutes les commandes se lancent depuis `birnin-gobe-code/`.

> Ces commandes ont été vérifiées contre le `docker-compose.yml` et le
> `Dockerfile` du dépôt. Aucune n'est théorique.

---

## 1. Mise en route locale

```bash
git clone https://github.com/Nazir-code/birnin-gobe-plateforme.git
cd birnin-gobe-plateforme/birnin-gobe-code

cp .env.example .env
# éditer .env — voir §3

docker compose build
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose up -d
```

Application sur **http://localhost:8080**.

> `docker compose` lit `.env` à la fois pour ses propres substitutions
> (`${DB_DATABASE}`, `${AWS_ACCESS_KEY_ID}`…) et via `env_file` pour `app`,
> `worker` et `scheduler`. **Le fichier doit exister avant toute commande
> Compose**, y compris `build`.

---

## 2. Premier déploiement en recette

Identique au §1, avec les valeurs de recette dans `.env` et `--force` sur la
migration pour éviter la confirmation interactive.

```bash
cp .env.example .env
# éditer .env : APP_ENV, APP_DEBUG=false, APP_URL, DB_PASSWORD… (§3)

docker compose build
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate --force
docker compose up -d

docker compose ps
```

Vérifier ensuite les points de contrôle du §6.

### Exposition réseau

Le Compose publie **`8080:80`** (HTTP) et **`9001:9001`** (console MinIO).
Aucun port 443. Selon la stratégie retenue au §9 du handoff :

- **Apache frontal** → configurer un reverse proxy CWP vers `127.0.0.1:8080`.
  Ne rien changer dans le dépôt. Penser à transmettre `X-Forwarded-Proto`.
- **Caddy frontal** → remplacer `:80` par le domaine dans
  `infrastructure/caddy/Caddyfile`, publier `443:443` (et `80:80`) dans le
  Compose, reconstruire l'image `caddy`. Le certificat est alors obtenu et
  renouvelé automatiquement.

> **Ne pas exposer `9001` (console MinIO) publiquement.** Le restreindre au
> réseau local ou le retirer si MinIO n'est pas utilisé — aucun code ne
> l'utilise aujourd'hui.

---

## 3. Variables d'environnement

Base : `.env.example`. **Aucun secret réel ne doit être committé ni transmis
par messagerie.**

### Présentes dans `.env.example`

| Variable | Valeur du modèle | Attendu en recette/production |
|---|---|---|
| `APP_NAME` | `BIRNIN GOBE` | inchangé |
| `APP_ENV` | `local` | `production` |
| `APP_DEBUG` | `true` | **`false`** — sinon les traces sont publiques |
| `APP_URL` | `http://localhost` | `https://<domaine>` |
| `APP_KEY` | vide | généré une fois — voir §4 |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | `fr` | `fr` |
| `DB_CONNECTION` | `pgsql` | `pgsql` — ne pas changer sans décision |
| `DB_HOST` | `postgres` | nom du service Compose, ou hôte managé |
| `DB_PORT` | `5432` | |
| `DB_DATABASE` / `DB_USERNAME` | `birnin_gobe` | |
| `DB_PASSWORD` | `change-me` | **secret réel** |
| `CACHE_STORE` | `redis` | `redis` |
| `QUEUE_CONNECTION` | `redis` | `redis` |
| `REDIS_HOST` / `REDIS_PORT` | `redis` / `6379` | |
| `FILESYSTEM_DISK` | `s3` | `s3` si stockage objet, sinon `local` |
| `AWS_ACCESS_KEY_ID` | identifiant MinIO local | **identifiant réel** |
| `AWS_SECRET_ACCESS_KEY` | `change-me` | **secret réel** |
| `AWS_DEFAULT_REGION` | `us-east-1` | région du fournisseur |
| `AWS_BUCKET` | `birnin-gobe` | bucket réel |
| `AWS_ENDPOINT` | `http://minio:9000` | endpoint S3 réel, ou vide pour AWS |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` | `true` pour MinIO, `false` pour AWS |

### Lues par le code mais **absentes** de `.env.example`

À ajouter explicitement dans le `.env` de recette :

| Variable | Défaut du code | Recommandation |
|---|---|---|
| `SESSION_DRIVER` | `file` | `redis` — voir handoff §6 |
| `SESSION_SECURE_COOKIE` | non défini | `true` dès que HTTPS est en place |
| `SESSION_LIFETIME` | `120` | selon besoin |
| `SESSION_COOKIE` | `birnin_gobe_session` | |
| `SESSION_DOMAIN` | non défini | le domaine si sous-domaines |
| `REDIS_PASSWORD` | non défini | à définir si Redis est exposé |
| `REDIS_DB` / `REDIS_CACHE_DB` | `0` / `1` | |
| `QUEUE_FAILED_DRIVER` | `database-uuids` | table `failed_jobs` **non migrée** |
| `MAIL_*` | — | aucun `config/mail.php` : mail non implémenté |

---

## 4. APP_KEY

```bash
docker compose run --rm app php artisan key:generate
```

`APP_KEY` chiffre les sessions et les données sensibles.

> **Générer une seule fois par environnement, puis conserver la valeur** dans
> le gestionnaire de secrets. La régénérer invalide toutes les sessions et rend
> illisible tout ce qui a été chiffré avec l'ancienne clé.
> **Ne jamais la régénérer lors d'un redéploiement.**

---

## 5. Build frontend

**Aucune action manuelle nécessaire au déploiement.** Le frontend est compilé
pendant `docker compose build`, dans les deux images :

- `Dockerfile` (image `app`) — étape `frontend` : `npm ci` puis `npm run build`.
- `infrastructure/caddy/Dockerfile` (image `caddy`, celle qui **sert réellement**
  les fichiers) — `npm install` puis `npm run build`.

> Voir handoff §10 action n°1 : la seconde image n'utilise pas le verrou. Tant
> que ce n'est pas aligné, **reconstruire les deux images ensemble**
> (`docker compose build` sans cibler un service) pour limiter la divergence.

Pour compiler hors Docker :

```bash
npm ci
npm run build
```

> **`npm run dev` est un serveur de développement. Ne jamais l'utiliser en
> recette ou en production.**

---

## 6. Points de contrôle après déploiement

### Santé de la pile

```bash
docker compose ps                 # tous les services en "Up" / "healthy"
curl -i http://localhost:8080/up                 # santé Laravel
curl -i http://localhost:8080/api/v1/health      # doit renvoyer {"status":"ok"}
```

### Services individuels

```bash
docker compose exec postgres pg_isready -U birnin_gobe -d birnin_gobe
docker compose exec redis redis-cli ping                    # PONG
docker compose exec app php artisan about                   # env, cache, queue
docker compose exec app php artisan migrate:status
```

### Processus longs

```bash
docker compose logs worker --tail=50       # doit rester actif, sans boucle d'erreur
docker compose logs scheduler --tail=50
```

### Smoke tests des écrans

| URL | Attendu |
|---|---|
| `/` | Accueil, carrousel du hero (5 photos, 5 s), logos, footer |
| `/candidate/dashboard` | Tableau de bord candidat |
| `/candidate/application` | Redirige vers la section en cours (connexion requise) |
| `/admin/dashboard` | Back-office administratif |
| `/evaluator/assignments` | Interface évaluateur / grille de notation |

Vérifier aussi : les assets (`/build/assets/*`) répondent en 200, le logo et le
footer s'affichent, et le menu mobile s'ouvre sous 1280 px.

> **Ces écrans affichent des données statiques.** Aucun ne reçoit de données du
> serveur (handoff §2). Ces tests valident le **déploiement du prototype**, pas
> un workflow métier. Il n'y a pas d'écran « jury » distinct : le jury est
> couvert par l'interface évaluateur.

### Persistance — à tester explicitement

```bash
docker compose exec app php artisan migrate:status   # noter l'état
docker compose down                                   # SANS -v
docker compose up -d
docker compose exec app php artisan migrate:status   # doit être identique
```

Survivent à `docker compose down` et au redémarrage du serveur :
`postgres_data`, `redis_data`, `minio_data`, `clamav_data`, `caddy_data`,
`caddy_config`.

**Ne survivent pas** : `storage/` de l'application — logs Laravel et sessions
(`SESSION_DRIVER=file`). Voir handoff §10, actions 3 et 5.

> **`docker compose down -v` détruit tous les volumes, base de données
> comprise.** Ne jamais l'utiliser sur un environnement contenant des données à
> conserver.

---

## 7. Logs

```bash
docker compose ps
docker compose logs -f                 # tout, en continu
docker compose logs app --tail=100
docker compose logs caddy --tail=100
docker compose logs worker --tail=100
docker compose logs scheduler --tail=100
docker compose logs postgres --tail=100
docker compose logs redis --tail=100
```

Logs applicatifs Laravel :

```bash
docker compose exec app tail -f storage/logs/laravel.log
```

> Ces fichiers sont dans le conteneur et **disparaissent au redéploiement**
> (aucun volume). Pour une recette durable, monter `storage/logs` ou envoyer
> les journaux vers un collecteur.

---

## 8. Tests avant déploiement

### Frontend et E2E — fonctionnels

```bash
npm ci
npm run build

# E2E, application démarrée
npx playwright test
PLAYWRIGHT_BASE_URL=https://<domaine> npx playwright test   # contre un déploiement
```

`npx playwright test` vise `http://127.0.0.1:8080` par défaut
(`playwright.config.ts`) et couvre deux navigateurs : Desktop Chrome et
Pixel 5. 26 tests, tous passants au moment de la passation.

### Outillage PHP — non exécutable en l'état

L'image `app` est construite avec `composer install --no-dev` : **PHPUnit,
Pint et Collision n'y sont pas**. Vérifié :

```
docker compose run --rm app php artisan test
  → ERROR  Command "test" is not defined.

docker compose run --rm app vendor/bin/pint --test
  → stat vendor/bin/pint: no such file or directory
```

Ces deux commandes, souvent citées pour Laravel, **ne fonctionnent pas sur ce
dépôt tel qu'il est déployé**. Pour disposer de l'outillage PHP, installer les
dépendances de développement dans un conteneur jetable :

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 install --no-interaction
docker run --rm -v "$PWD":/app -w /app composer:2 dump-autoload --no-interaction
# Le script post-dump (artisan package:discover) échoue : l'image composer n'a
# pas ext-intl. Sans conséquence, vendor/autoload.php est bien généré.

docker run --rm -v "$PWD":/app -w /app --entrypoint php composer:2   vendor/bin/pint --test
```

**État constaté au moment de la passation : Pint échoue — 28 fichiers analysés,
19 violations de style.** Le dépôt ne respecte pas son propre formateur. Non
corrigé ici (hors périmètre d'une passation), voir handoff §10.

Quant à `php artisan test` : même avec les dépendances de développement, il n'y
a **aucun test PHP et aucun `phpunit.xml`**. Mettre en place l'outillage de test
backend est une action à part entière (handoff §10, action 4).

---

## 9. Redéploiement

```bash
# 1. Sauvegarder la base AVANT toute migration
docker compose exec -T postgres pg_dump -U birnin_gobe birnin_gobe \
  > sauvegarde-$(date +%F-%H%M).sql

# 2. Récupérer le code
git pull

# 3. Reconstruire les deux images (voir §5)
docker compose build

# 4. Migrer — revoir le diff des migrations avant
docker compose run --rm app php artisan migrate --force

# 5. Recréer les services
docker compose up -d

# 6. Vider les caches applicatifs
docker compose exec app php artisan config:clear
docker compose exec app php artisan cache:clear

# 7. Rejouer les points de contrôle du §6
```

> **Ne jamais lancer une migration destructive sans sauvegarde et sans revue du
> code de migration.** Relire le diff de `database/migrations/` avant l'étape 4.

---

## 10. Retour arrière

> **Le projet ne supporte pas encore un rollback propre.** Il n'y a ni registre
> d'images, ni tags de version, ni migrations `down` vérifiées. Ce qui suit est
> un repli manuel, à améliorer avant la production.

```bash
# 1. Revenir au commit précédent (ou à un tag)
git log --oneline -10
git checkout <commit-ou-tag>

# 2. Reconstruire et relancer
docker compose build
docker compose up -d

# 3. Si le schéma a changé, restaurer la sauvegarde
docker compose exec -T postgres psql -U birnin_gobe -d birnin_gobe \
  < sauvegarde-<horodatage>.sql
```

À mettre en place pour rendre le retour arrière fiable :

- taguer chaque déploiement (`git tag recette-YYYY-MM-DD`) ;
- publier les images dans un registre pour pouvoir redéployer un artefact
  identique plutôt que le reconstruire ;
- vérifier les méthodes `down()` des migrations ;
- automatiser la sauvegarde avant chaque migration.

---

## 11. Mise à jour des dépendances

Sans installer PHP sur l'hôte :

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 update --no-install
```

`composer.json` déclare la plateforme cible (`config.platform`) : PHP 8.4 et les
extensions installées par l'image d'exécution. La résolution ne dépend donc pas
des extensions du conteneur de build, qui n'a pas `ext-intl`.

Côté frontend :

```bash
npm update
npm ci && npm run build      # vérifier que le build passe
```

`composer.lock` et `package-lock.json` sont versionnés : les committer après
toute mise à jour.
