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
${EDITOR:-nano} .env      # SITE_ADDRESS, APP_URL, DB_PASSWORD, ACME_EMAIL,
                          # et le bloc MAIL_* (voir §6 bis)

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

## 6 bis. Courriels

La plateforme n'envoie aujourd'hui qu'un seul message, et il est
indispensable : le **lien de réinitialisation de mot de passe**. Sans lui, un
candidat qui oublie son mot de passe perd l'accès à son dossier, et personne
ne peut le lui rendre.

### Un transport par environnement

| Environnement | `MAIL_MAILER` | Ce qui se passe |
|---|---|---|
| Développement | `log` | Le message s'écrit dans `storage/logs`, en clair. On relit le lien sans serveur SMTP, et aucun courriel ne peut atteindre une vraie adresse. |
| Test | `array` | Imposé par `phpunit.xml`. Les messages restent en mémoire et sont vérifiés par la suite ; rien ne part. |
| **Production** | **`smtp`** | **Obligatoire.** C'est le seul transport qui atteint une boîte de réception. |

> `log` et `array` ne sont pas des erreurs de configuration : ce sont les
> réglages attendus hors production. `log` ne devient un défaut qu'ici, et il
> est particulièrement traître — laissé en production, le lien part dans les
> journaux du serveur, le candidat ne le reçoit jamais, et **aucune erreur
> n'est levée**. Rien dans les journaux applicatifs ne signalera l'incident.

### Variables à renseigner

| Variable | Nature | Remarque |
|---|---|---|
| `MAIL_MAILER` | obligatoire | `smtp` |
| `MAIL_HOST` | fourni par Niger Télécom | serveur d'envoi |
| `MAIL_PORT` / `MAIL_SCHEME` | obligatoire | 587 avec `smtp` (STARTTLS) **ou** 465 avec `smtps` (TLS implicite) — les deux vont par paire |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | **secrets** | compte d'envoi |
| `MAIL_FROM_ADDRESS` | fourni | doit appartenir à un domaine que le relais est autorisé à utiliser, sans quoi les messages finissent en indésirables |
| `MAIL_FROM_NAME` | obligatoire | |
| `MAIL_EHLO_DOMAIN` | facultatif | si le relais refuse un `HELO` non concordant |

### Vérification de l'envoi réel — obligatoire

**À faire après chaque déploiement qui touche à `MAIL_*`, et avant d'ouvrir le
trafic.** Une configuration SMTP qui n'a jamais servi n'est pas une
configuration vérifiée : c'est une hypothèse.

1. Créer un compte candidat de test avec une adresse réellement relevable.
2. Sur `https://LE-DOMAINE/forgot-password`, demander un lien pour cette adresse.
3. **Vérifier la réception effective**, en n'oubliant pas les indésirables —
   c'est le point qui échoue le plus souvent, et il tient à `MAIL_FROM_ADDRESS`.
4. Suivre le lien, choisir un nouveau mot de passe, se connecter avec.
5. En cas de non-réception, lire les journaux du conteneur applicatif :

```bash
bg logs --tail 100 app | grep -i mail
```

Un message resté dans les journaux au lieu de partir signale un `MAIL_MAILER`
encore à `log`. Une erreur d'authentification ou de connexion signale, elle,
un problème d'identifiants, de port ou de `MAIL_SCHEME`.

> L'écran de demande répond la même chose que l'adresse existe ou non — c'est
> voulu, pour qu'un formulaire public ne permette pas d'établir la liste des
> personnes inscrites. Il ne faut donc **pas** se fier à son message de
> confirmation pour conclure que l'envoi a fonctionné : seule la réception
> effective le prouve.

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
| 19 | Mot de passe oublié : demande sur une adresse réelle | **courriel effectivement reçu** (voir §6 bis) |
| 20 | Le lien reçu mène à l'écran, le mot de passe change | connexion possible avec le nouveau |

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
| Reprise des messages en attente | l'alerte `notifications.file_bloquee` signale une file arrêtée (ADR-019) ; la relance reste un geste d'exploitation (`queue:retry`), pas un bouton du back-office |
| Pièces justificatives | `minio` et `clamav` attendent derrière le profil `fichiers` |
| Vérification d'adresse e-mail | pas encore implémentée ; le courriel de création de compte ne prétend pas vérifier l'adresse (ADR-018), ce qui reste l'écart au §8.3 |
| Transport de courriel | les six messages du §8.3 partent (ADR-018) et leur issue est tracée (ADR-019), mais `MAIL_MAILER` vaut encore `log` : aucun transport réel n'est choisi |
