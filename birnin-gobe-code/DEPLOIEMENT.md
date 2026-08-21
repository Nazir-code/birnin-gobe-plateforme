# Déploiement — BIRNIN GOBE

Document de passation. À lire avant toute mise en service.

---

## 1. Ce que ce dépôt contient — et ce qu'il ne contient pas

**C'est un prototype d'interface, pas une application en état de production.**

Les écrans sont codés et le squelette métier existe : enum de statut, machine à
états des transitions, cas d'usage `SubmitApplication`, journal d'audit,
migrations initiales (`campaigns`, `applications`, `attachments`,
`audit_events`).

En revanche, `routes/web.php` ne contient que des closures `Inertia::render` :
**aucune authentification, aucune politique d'accès, aucune requête base de
données**. L'auth, le RBAC, le CMS, l'admin Filament, l'autosauvegarde
persistante, les téléversements S3 avec analyse antivirus et les workflows
métier réels restent à brancher. Voir `README.md` et `docs/decisions/`.

Deux éléments ne sont volontairement pas versionnés :

| Élément | Où le trouver |
|---|---|
| `.env` | À créer depuis `.env.example` (voir §3). Ne jamais committer. |
| Cahier des charges PIDUREM, analyse technique de passation, maquettes V1.0 | Hors dépôt. À demander au porteur du projet. |

> Une vitrine statique des écrans est publiée à titre de démonstration. Ce n'est
> pas l'application : c'est un rendu figé, sans backend, sans base de données.

---

## 2. Architecture

Monolithe modulaire Laravel 13 (PHP 8.4) + React 19 / TypeScript via Inertia 3,
Filament 5 pour l'admin standard, Tailwind 4. Voir
`docs/decisions/ADR-001-monolithe-modulaire.md`.

`docker-compose.yml` décrit huit services :

| Service | Rôle |
|---|---|
| `app` | PHP-FPM (Laravel) |
| `caddy` | Reverse proxy, expose le port 80 |
| `postgres` | Base de données (PostgreSQL 17) |
| `redis` | Cache, sessions, files d'attente |
| `worker` | `queue:work` — processus long |
| `scheduler` | `schedule:work` — processus long |
| `minio` | Stockage S3-compatible (développement) |
| `clamav` | Analyse antivirus des téléversements |

`worker` et `scheduler` sont des **processus longs**. Toute cible d'hébergement
doit pouvoir les faire tourner en continu : un hébergeur serverless ne convient
pas.

En production, `minio` est normalement remplacé par le service S3 réel (variables
`AWS_*`), et `postgres` / `redis` par des instances managées si vous en disposez.

---

## 3. Variables d'environnement

Créer `.env` à partir de `.env.example`, puis générer la clé applicative :

```bash
cp .env.example .env
docker compose run --rm app php artisan key:generate
```

`.env.example` est un modèle de **développement**. À changer impérativement
avant toute mise en ligne :

| Variable | Valeur du modèle | Attendu en production |
|---|---|---|
| `APP_ENV` | `local` | `production` |
| `APP_DEBUG` | `true` | `false` — sinon les traces d'erreur sont publiques |
| `APP_URL` | `http://localhost` | le domaine réel, en `https://` |
| `APP_KEY` | vide | généré par `artisan key:generate` |
| `DB_PASSWORD` | `change-me` | un secret réel |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | identifiants MinIO locaux | identifiants du stockage réel |
| `AWS_ENDPOINT` | `http://minio:9000` | l'endpoint S3 réel, ou vide pour AWS |

`APP_KEY` chiffre les sessions et les données sensibles : la changer après mise
en service invalide l'existant. La fixer une fois, la conserver dans le
gestionnaire de secrets.

---

## 4. Mise en service

```bash
cp .env.example .env          # puis éditer selon §3
docker compose build
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose up -d
```

L'application est servie par Caddy sur le port `8080` en local
(`docker-compose.yml`), à adapter derrière votre terminaison TLS.

**Le TLS n'est pas configuré dans ce dépôt.** `infrastructure/caddy/Caddyfile`
écoute en clair sur `:80`. Sur un serveur public, soit vous déclarez le domaine
dans le Caddyfile pour que Caddy obtienne un certificat automatiquement, soit
vous placez le tout derrière un reverse proxy qui termine le TLS.

---

## 5. Reproductibilité des dépendances

`composer.lock` et `package-lock.json` sont versionnés, et le `Dockerfile` les
copie tous les deux avant d'installer. Les constructions sont donc
reproductibles : la même image contient les mêmes versions que celles testées.

`composer.json` déclare la plateforme cible (`config.platform`) — PHP 8.4 et les
extensions installées par l'image d'exécution. La résolution ne dépend donc pas
des extensions présentes dans le conteneur de build, qui n'a pas `ext-intl`.

Pour mettre à jour les dépendances PHP sans installer PHP localement :

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 update --no-install
```

---

## 6. Vérifications

```bash
docker compose run --rm app php artisan test   # PHPUnit
docker compose run --rm app vendor/bin/pint    # style de code
npm ci && npm run build                        # build frontend
npx playwright test                            # E2E (application démarrée)
```

La CI (`.github/workflows/ci.yml`) exécute les tests PHP sur PHP 8.4 avec
`pdo_pgsql` et `redis`, plus le build frontend sur Node 22.

Les tests E2E visent `http://127.0.0.1:8080` par défaut, surchargeable par
`PLAYWRIGHT_BASE_URL`.

---

## 7. Points à traiter avant une vraie mise en production

Par ordre de criticité :

1. **Authentification et RBAC.** Les routes sont ouvertes. Ajouter
   `auth`/`verified`, les policies de ressources et le cloisonnement par
   campagne — le commentaire en tête de `routes/web.php` le rappelle.
2. **TLS et en-têtes de sécurité.** Voir §4. Caddy pose déjà
   `X-Content-Type-Options`, `Referrer-Policy` et `X-Frame-Options`.
3. **Secrets.** Aucun secret réel ne doit transiter par le dépôt ni par
   messagerie. Utiliser le gestionnaire de secrets de la cible.
4. **Sauvegardes.** Aucune politique de sauvegarde PostgreSQL n'est définie ici.
5. **Antivirus.** Le service `clamav` est déclaré mais l'analyse n'est pas
   encore branchée sur le dépôt de fichiers.
6. **Traductions.** Seul le français est peuplé. Le haoussa et le zarma
   retombent volontairement sur le français tant qu'aucune traduction validée
   institutionnellement n'existe (`resources/js/i18n/README.md`).

---

## 8. Contraintes UX non négociables

Documentées dans `docs/architecture/BLUEPRINT-UI-FOUNDATION.md`, à respecter
dans toute évolution : mobile-first dès 320 px, états de sauvegarde explicites,
autosauvegarde sans perte, navigation avant/arrière sans perte de données,
formulaires en lecture seule après soumission, focus visible et navigation au
clavier, contraste AA, aucun statut métier stocké sous forme de libellé
français, données sensibles masquées selon le rôle et la ressource.
