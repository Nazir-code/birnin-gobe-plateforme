# BIRNI'NGOBE — Checklist de mise en service

Document destiné au responsable du serveur. Il tient en une page d'écran par
section et se déroule dans l'ordre.

Le [runbook](NIGER_TELECOM_RUNBOOK.md) reste la référence détaillée
(variables, journaux, mise à jour des dépendances). Cette checklist en est le
chemin le plus court, du serveur nu au trafic ouvert.

Toutes les commandes se lancent depuis `birnin-gobe-code/`, avec les **deux**
fichiers Compose :

```bash
alias bg='docker compose -f docker-compose.yml -f docker-compose.prod.yml'
```

> `docker-compose.prod.yml` n'est pas optionnel en production : c'est lui qui
> apporte le redémarrage automatique, les ports 80/443, la persistance de
> `storage/` et la rotation des journaux. Sans lui, la pile démarre sur le
> port 8080, en clair, et ne se relève pas après un reboot.

---

## 1. Prérequis

| Élément | Attendu | Vérification |
|---|---|---|
| Docker Engine | ≥ 24 | `docker --version` |
| Docker Compose | v2 (plugin) | `docker compose version` |
| Accès SSH | compte non-root avec `sudo`, clé publique | `ssh serveur` |
| DNS | `A` (et `AAAA` si IPv6) du domaine → IP du serveur, **propagé** | `dig +short LE-DOMAINE` |
| Port 80 | ouvert depuis Internet | requis pour la validation du certificat |
| Port 443 | ouvert depuis Internet, TCP **et UDP** | UDP pour HTTP/3 |
| Disque | 20 Go libres minimum | `df -h` |
| RAM | 4 Go minimum | `free -h` |
| Destination de sauvegarde | volume ou hôte **distinct** du serveur | voir §6 |
| Horloge | NTP actif | une dérive fait échouer la validation TLS |

Le DNS doit être propagé **avant** le premier démarrage : Caddy demande le
certificat dès qu'il voit un domaine, et une demande échouée se retente avec un
délai croissant.

---

## 2. Déploiement

```bash
# 1. Récupérer le code
git clone <dépôt> birnin-gobe && cd birnin-gobe/birnin-gobe-code
git checkout <tag ou commit de lancement>

# 2. Configurer l'environnement
cp .env.production.example .env
${EDITOR:-nano} .env      # renseigner SITE_ADDRESS, APP_URL, DB_PASSWORD, ACME_EMAIL

# 3. Générer la clé applicative — une seule fois, jamais versionnée.
#    `--show` affiche la clé sans l'écrire : l'image ne contient aucun `.env`
#    (il est exclu par .dockerignore et fourni par `env_file`), donc un
#    `key:generate` sans `--show` écrirait dans un conteneur jetable et la
#    clé serait perdue au redémarrage. C'est bien l'hôte qui garde le fichier.
bg run --rm -T app php artisan key:generate --show    # → base64:…
${EDITOR:-nano} .env                                   # coller dans APP_KEY=

# 4. Construire les images
#    Les DEUX : `caddy` embarque sa propre copie des assets compilés.
#    Reconstruire `app` seul laisserait l'ancienne interface en place.
bg build app caddy

# 5. Démarrer l'infrastructure
bg up -d

# 6. Migrer la base
bg exec -T app php artisan migrate --force

# 7. Créer le premier administrateur (voir §4)
bg exec app php artisan admin:create

# 8. Créer et ouvrir la campagne, puis publier ses critères (voir §5)

# 9. Dérouler la checklist de fumée (voir §7)

# 10. Ouvrir le trafic
```

Vérifier après l'étape 5 que les six services attendus tournent :

```bash
bg ps
# app, caddy, postgres, redis, scheduler, worker
```

`minio` et `clamav` ne démarrent volontairement pas : ils sont derrière le
profil `fichiers` et n'auront d'objet qu'avec l'étape « Pièces justificatives ».

---

## 3. Migrations

**Toujours sauvegarder avant.** Une migration se rejoue rarement à l'envers en
production sans perte.

```bash
# 1. Sauvegarde (voir §6) — non négociable
./scripts/backup-database.sh

# 2. Relire ce qui va s'appliquer
bg exec -T app php artisan migrate:status

# 3. Appliquer
bg exec -T app php artisan migrate --force

# 4. Vérifier
bg exec -T app php artisan migrate:status   # tout en « Ran »
```

**Sur le retour arrière.** `php artisan migrate:rollback` exécute la méthode
`down()` de la migration — elle existe dans ce dépôt, mais elle ne restitue pas
les données qu'un `dropColumn` a emportées. Elle convient à une migration
purement additive constatée fautive dans la minute ; elle ne remplace jamais
une restauration. La règle : **si la migration a supprimé ou transformé des
données, le retour arrière passe par la restauration de la sauvegarde**, pas
par `migrate:rollback`.

---

## 4. Premier administrateur

Aucun compte n'est créé par le déploiement, et l'inscription publique ne crée
que des comptes candidats : le seul chemin est la commande (ADR-006).

```bash
bg exec app php artisan admin:create
# invites : nom complet, adresse e-mail, mot de passe masqué + confirmation
```

Le mot de passe est saisi de façon masquée. Ne le passer **jamais** en
argument de ligne de commande — il resterait dans l'historique du shell — et ne
l'écrire dans aucun fichier du dépôt.

---

## 5. Campagne et critères d'éligibilité

Après connexion sur `https://LE-DOMAINE/admin/login` :

1. **Campagnes → Nouvelle campagne**
   - nom et code de l'édition ;
   - fuseau horaire — `Africa/Niamey` ; c'est lui qui donne son sens aux heures
     saisies, une clôture à 23:59 étant 23:59 à Niamey, pas sur le serveur ;
   - ouverture et clôture ;
   - statut « En préparation » tant que rien n'est prêt.
2. **Passer la campagne à « Ouverte »** quand les dates sont bonnes.
   Rappel : **une seule campagne peut être ouverte à la fois** (ADR-008) —
   l'application refuse la seconde, et un index PostgreSQL la refuse aussi.
3. **Campagnes → Éligibilité** : publier les critères de l'édition — âge, lien
   avec le Niger, zones, formes de candidature, taille d'équipe (ADR-010).

> Tant qu'un critère n'est pas publié, l'auto-test répond « sous réserve » à
> tous les candidats et n'écarte personne. Ce n'est pas une panne : c'est le
> comportement voulu tant que le comité de pilotage n'a pas arrêté ce critère.
> Une campagne ouverte sans aucun critère publié est signalée sur l'écran des
> campagnes.

---

## 6. Sauvegarde et restauration

### Sauvegarder

```bash
./scripts/backup-database.sh                 # → storage/backups/
./scripts/backup-database.sh /mnt/sauvegardes   # ou un emplacement externe
```

Le script produit un `pg_dump` au format « custom » — compressé, horodaté, et
restaurable table par table. Il refuse d'écrire un fichier suspect (moins d'un
kilo-octet). **Le copier hors du
serveur** : une sauvegarde qui vit sur la machine qu'elle protège ne protège de
rien.

À la main, si le script n'est pas disponible :

```bash
bg exec -T postgres pg_dump -U birnin_gobe -d birnin_gobe --format=custom \
  > birnin_gobe-$(date +%Y%m%d-%H%M%S).dump
```

### Restaurer

```bash
# 1. Couper le trafic applicatif — la base ne doit plus être écrite
bg stop app worker scheduler

# 2. Restaurer — le format « custom » est déjà compressé, rien à décompresser
bg exec -T postgres pg_restore -U birnin_gobe -d birnin_gobe \
  --clean --if-exists --no-owner < storage/backups/LA-SAUVEGARDE.dump

# 3. Relancer
bg up -d
bg exec -T app php artisan migrate:status   # cohérence schéma / code
```

`--clean --if-exists` remplace le contenu existant : à ne lancer que sur la
base que l'on veut effectivement écraser.

> Cette procédure a été éprouvée sur la pile réelle : ligne témoin insérée,
> sauvegarde, suppression de la ligne, restauration — la ligne est revenue.
> Une procédure de restauration qu'on n'a jamais exécutée n'est pas une
> procédure, c'est une intention.

### Cadence minimale au lancement

| Moment | Action |
|---|---|
| Avant chaque migration | sauvegarde manuelle |
| Chaque jour de la période de candidature | sauvegarde, copiée hors serveur |
| Avant chaque redéploiement | sauvegarde |

---

## 7. Checklist de fumée après déploiement

À dérouler **avant** d'ouvrir le trafic. Le script `./scripts/production-smoke.sh`
couvre les points non authentifiés ; le reste se fait au navigateur.

| # | Vérification | Attendu |
|---|---|---|
| 1 | `GET /` | 200, page d'accueil |
| 2 | HTTPS | certificat valide, `http://` redirige vers `https://` |
| 3 | `GET /up` | 200 |
| 4 | `GET /api/v1/health` | `{"status":"ok"}` |
| 5 | En-têtes | `X-Content-Type-Options`, `Strict-Transport-Security` présents |
| 6 | `GET /admin/applications` sans session | redirection vers `/admin/login` |
| 7 | Inscription candidat | compte créé, arrivée sur le tableau de bord |
| 8 | Connexion candidat | session ouverte |
| 9 | Connexion administrateur | arrivée sur `/admin/dashboard` |
| 10 | Campagne ouverte, critères publiés | visibles dans l'administration |
| 11 | Candidat : commencer une candidature | brouillon créé |
| 12 | Candidat : remplir une section | « Enregistré » affiché |
| 13 | Rechargement de la page | les réponses sont toujours là |
| 14 | Déconnexion puis reconnexion | reprise à la bonne étape |
| 15 | Administration → Candidatures | le dossier du candidat apparaît |
| 16 | Candidat sur `/admin/applications` | **403** |
| 17 | `/admin/applications/999999` en administrateur | **404** |
| 18 | Persistance : `bg restart` puis rechargement | les données sont intactes |

> Les étapes « Solution / Impact / Plan » et la soumission des dossiers ne
> figurent pas encore ici : elles seront ajoutées à cette checklist quand elles
> auront été intégrées.

### Test de persistance — la seule version qui prouve quelque chose

```bash
bg down          # SANS -v : `-v` détruirait les volumes, donc la base
bg up -d
# rouvrir un compte existant : il est toujours là
```

`docker compose down -v` supprime les volumes nommés, donc PostgreSQL, donc
tout. La commande ne doit apparaître dans aucune procédure de production.

---

## 8. Retour arrière

Trois situations, trois réponses différentes :

| Situation | Réponse |
|---|---|
| Le code se comporte mal, **le schéma n'a pas changé** | revenir au commit précédent, reconstruire `app` **et** `caddy`, redémarrer. La base n'est pas touchée. |
| Le schéma a changé et la migration est **additive** (colonne ou table ajoutée, non lue par l'ancien code) | revenir au code précédent suffit : l'ancien code ignore ce qu'il ne connaît pas. |
| Le schéma a changé de façon **destructive**, ou les données sont corrompues | restaurer la sauvegarde (§6), puis revenir au code correspondant à cette sauvegarde. |

```bash
# Retour arrière applicatif
git checkout <tag précédent>
bg build app caddy
bg up -d
bg exec -T app php artisan migrate:status   # confirmer la cohérence
```

Code et schéma vont par paires : revenir au code sans revenir au schéma ne vaut
que si la migration était additive.

---

## 9. Exploitation courante

```bash
bg ps                          # état des services
bg logs -f app                 # journaux applicatifs (LOG_CHANNEL=stderr)
bg logs -f caddy               # accès et TLS
bg exec -T app php artisan about   # configuration effective
```

Après un changement de `.env` :

```bash
bg up -d --force-recreate app worker scheduler
```

`.env` est lu au démarrage du conteneur : le modifier ne suffit pas, il faut
recréer les conteneurs qui le lisent.

### Sur `config:cache`

`php artisan config:cache` accélère le démarrage, mais **désactive la lecture
de `.env` à l'exécution** : tout `env()` situé hors de `config/*.php` retombe
alors sur sa valeur par défaut. Ce dépôt n'en compte qu'un, `TRUSTED_PROXIES`
dans `bootstrap/app.php`, dont le défaut `*` est justement la valeur de
production — la mise en cache est donc sans effet ici. Le rester : après toute
modification de `.env`, refaire `config:cache`, ou ne pas l'activer du tout.

---

## 10. Ce qui reste à faire après ce lancement

| Sujet | État |
|---|---|
| Page d'accueil publique | affiche encore un calendrier et un compte à rebours de démonstration ; correctif identifié, à appliquer à l'intégration suivante |
| Limitation des inscriptions | les deux écrans de connexion sont protégés ; l'inscription ne l'est pas encore |
| Table `failed_jobs` | déclarée par `config/queue.php`, absente des migrations ; sans effet tant qu'aucune tâche n'est mise en file |
| Pièces justificatives | `minio` et `clamav` attendent derrière le profil `fichiers` |
| Courriels | aucun envoi n'est configuré : ni vérification d'adresse, ni réinitialisation de mot de passe |
