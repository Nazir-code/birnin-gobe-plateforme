# ADR-012 — Pièces et déclarations : stockage privé local et copie figée v2

Statut : accepté — Phase 1G
Contexte : fait suite à ADR-005 (persistance), ADR-009 (parcours et sections) et ADR-011 (structure / équipe).

## Contexte

L'étape 8 « Pièces / déclarations » était la dernière section de contenu du
dossier, et la seule que `SubmissionReadiness` exigeait sans qu'elle existe.
Tant qu'elle manquait, **aucune candidature n'était déposable** : le moteur de
dépôt était complet et testé, mais aucun chemin utilisateur ne pouvait le
satisfaire.

Cette étape n'est pas une section comme les sept précédentes. Elle mêle deux
natures que le §5.2 réunit sous un même intitulé :

- les **déclarations** (§7.3) sont des réponses comme les autres ;
- les **pièces** (§7.2) sont des fichiers, avec un poids, un type MIME et un
  emplacement de stockage.

## Décision

### Les déclarations suivent le chemin ordinaire

Elles vivent dans `application_sections`, sont validées par une `FormRequest`,
écrites par `SaveApplicationSection` et sauvegardées automatiquement comme les
sept sections précédentes. Rien de nouveau, et c'est voulu : une case cochée
n'est pas un cas particulier de persistance.

Les six déclarations sont les trois puces du §7.3, éclatées là où la source
elle-même distingue. Deux nuances sont tenues :

- le consentement à la communication publique est **facultatif** — le §7.3 le
  sépare explicitement (« distinct », « le cas échéant ») ; un consentement
  qu'il faut donner pour pouvoir déposer n'en est plus un ;
- l'autorisation de représentation n'est demandée qu'aux candidatures
  collectives, par la règle déjà posée à l'étape 3.

### Les pièces ont leurs propres routes

Un fichier ne se sauvegarde pas toutes les deux secondes pendant la frappe. Les
mêler à la sauvegarde automatique aurait fait remonter le fichier à chaque case
cochée — indéfendable sur une connexion mobile partagée (§8.2). Elles se
déposent, se remplacent et se retirent par des routes dédiées.

### Le stockage est un disque privé **local**, pas un objet distant

Un disque Laravel nommé `documents`, distinct du disque par défaut :

```
'documents' => [
    'driver' => 'local',
    'root' => storage_path('app/private/documents'),
    'visibility' => 'private',
    'serve' => false,
],
```

Nommé séparément et non déduit du disque par défaut : ce que le §15.2 protège —
une pièce d'identité, un RCCM — ne doit pas changer d'emplacement parce qu'un
autre réglage a bougé. `DOCUMENTS_DISK` permet de le pointer ailleurs sans
toucher au code.

**MinIO n'a pas été déployé.** L'architecture le prévoit, le `docker-compose`
le décrit derrière le profil `fichiers`, mais un objet de plus à exploiter n'est
pas une condition pour recevoir des candidatures. La durabilité ne vient pas du
driver : elle vient du volume nommé `documents_data` monté sur
`storage/app/private`. Le `Dockerfile` crée ce dossier **avant** le `chown`,
sans quoi le démon Docker créerait le point de montage en `root:root` et
PHP-FPM, qui tourne en `www-data`, ne pourrait plus écrire — en production
seulement.

En production, `documents_data` cohabite avec `app_storage` : le montage le plus
profond l'emporte, les deux persistent.

### Les métadonnées en base, jamais le binaire

Le modèle `Attachment` reprend la table `attachments` du squelette initial
plutôt que d'en créer une seconde : deux tables pour une même chose, et la
question « laquelle fait foi ? » se pose au pire moment. Une colonne `type` y a
été ajoutée, avec un index `(application_id, type)`.

Ce qui est conservé : nom d'origine, type MIME **déduit du contenu** par PHP,
taille, empreinte SHA-256, `scan_status`. Le binaire reste sur le disque : le
mettre en PostgreSQL rendrait chaque lecture de dossier, et chaque sauvegarde de
la base, proportionnelle au poids des pièces.

Le nom de stockage est un **ULID tiré au sort** : connaître le nom d'origine
d'une pièce ne permet pas d'en deviner l'emplacement, et l'emplacement ne sort
jamais vers le navigateur.

### Aucun fichier orphelin, aucune ligne orpheline

L'écriture en base et l'écriture sur le disque ne peuvent pas partager une
transaction — le disque ne sait pas revenir en arrière. L'ordre est donc :

- **dépôt** : écrire le nouveau fichier → committer la base → effacer l'ancien ;
- **retrait** : supprimer la ligne → effacer le fichier.

Ce qui reste après un incident est un octet de trop sur le disque, jamais une
ligne pointant vers un fichier absent. Le candidat garde une pièce
téléchargeable dans tous les cas.

Un seul fichier par type de pièce : redéposer est un **remplacement**, pas un
second envoi. Deux « Présentation du projet » dans un dossier, c'est un jury qui
ne sait pas laquelle lire.

### L'appartenance et le verrou viennent des routes

`can:view,application` pour lire et télécharger, `can:update,application` pour
écrire. `ApplicationPolicy::update()` porte déjà les deux gardes qui comptent —
« c'est son dossier » et « il est encore un brouillon » — si bien qu'un
téléversement, un remplacement ou une suppression après soumission tombe en 403
**sans jamais atteindre le disque**, et sans un seul `if` dans le contrôleur.

Le téléchargement désigne une pièce par son **type**, jamais par un identifiant
numérique : le seul dossier interrogeable est celui que la policy a déjà
autorisé. L'administration dispose d'une route de lecture seule ; aucune route
d'écriture documentaire n'existe côté back-office, parce qu'aucun workflow de
validation documentaire n'a été arbitré et qu'inventer son premier geste
reviendrait à l'arbitrer.

### La copie de dépôt passe en schéma v2

`SubmissionSnapshot` ne copiait que `application_sections`. Les déclarations y
étaient donc préservées — ce sont des réponses — mais les pièces ne laissaient
aucune trace d'avoir fait partie du dépôt.

Un dépôt se conteste. Le jour où quelqu'un affirme que la présentation lue par
le jury n'est pas celle qu'il a envoyée, la seule réponse est une empreinte
prise à l'instant du dépôt. La copie porte donc, par pièce : nature, libellé,
nom d'origine, type MIME, poids, **empreinte** et date de dépôt.

Ce qu'elle ne porte pas :

- **le binaire** — une colonne `jsonb` chargée de PDF reproduirait ce que le
  disque conserve déjà, au prix de chaque lecture et de chaque sauvegarde ;
- **`storage_key`** — un emplacement est une donnée d'exploitation qu'un
  déménagement vers S3 réécrirait, et une copie figée ne doit rien contenir qui
  puisse devenir faux.

`SCHEMA_VERSION` passe de 1 à 2 : une copie v1 n'a pas de pièces parce que la
plateforme n'en recevait pas encore, ce qui n'est pas la même chose qu'un dépôt
fait sans pièce jointe. C'est exactement la distinction que ce numéro existe
pour permettre.

`SubmitApplication` n'a pas été modifié.

## Limites actuelles, assumées

- **Taille maximale : 5 Mo par pièce**, constante de code. Le §7.2 dit
  « configurable » ; l'écran d'administration des pièces (§9.2) n'existe pas
  encore. 5 Mo est large pour une présentation de quelques pages et représente
  déjà plusieurs minutes d'envoi sur une connexion mobile partagée.
- **La vidéo courte du §7.2 n'est pas téléversable** pour la pièce
  « Prototype / démonstration ». Le §8.2 fait de la faible connectivité une
  contrainte de conception ; captures et document en tiennent lieu.
- **Trois pièces sur six sont acceptées sans être exigées** — « Budget et plan
  d'action » (configurable), « Prototype / démonstration » (selon phase) et
  « Lettres et autorisations » (selon des cas qu'aucune donnée du dossier ne
  permet de trancher). Rendre obligatoire ce que la source laisse ouvert
  fermerait le dépôt à des candidats que le règlement autorise.
- **Supprimer une candidature laisserait ses fichiers sur le disque** : les
  lignes partent en cascade, pas les octets. Aucun chemin produit ne supprime de
  candidature aujourd'hui.
- **Pas de téléversement par blocs avec reprise** (§8.2) : un envoi interrompu
  est à recommencer.

## Dette de conformité restante

Les points ci-dessous sont exigés par le cahier des charges et **ne sont pas
implémentés**. Aucun code de cette phase ne prétend le contraire.

| Exigence | État réel |
|---|---|
| **Stockage objet S3-compatible** ou équivalent | Non fait. Disque local sur volume Docker. `DOCUMENTS_DISK` est le point de bascule prévu, jamais exercé. |
| **Analyse antivirus** (ClamAV) | Non fait. Aucun analyseur n'est branché. `scan_status` vaut `NOT_SCANNED` — et non `QUARANTINE`, qui ferait croire qu'une analyse a eu lieu et mal tourné. Rien dans le produit ne prétend qu'une pièce est saine. |
| **URL temporaires signées** | Non fait. Le téléchargement passe par une route authentifiée qui rejoue la policy à chaque appel. Il n'existe aucun lien public, mais il n'existe pas non plus d'URL à durée de vie limitée. |
| **Quotas configurables** par campagne | Non fait. La taille maximale et la liste des pièces sont des constantes de code, pas des paramètres de campagne. |
| **Versioning des pièces** | Non fait. Un remplacement écrase : l'ancienne version est effacée, seule l'empreinte de la version déposée survit dans la copie figée. |
| **Sauvegarde des fichiers hors serveur** | Non fait. `scripts/backup-database.sh` ne sauvegarde que PostgreSQL. Le volume `documents_data` vit sur la machine qu'il est censé protéger. |

Ces six points doivent être traités avant une exploitation à l'échelle
nationale. Le dernier est le plus urgent des six : une sauvegarde de base sans
les pièces qu'elle référence ne restaure pas un dossier.

## Conséquences

- L'étape 8 est développée et sur le parcours ouvert : `openPath()` compte huit
  étapes, et un dossier complet atteint **8/9**.
- `SubmissionReadiness` n'a pas été modifié. Il s'est ouvert de lui-même, par le
  seul chemin qu'il connaisse — `completed_at` — dès que la section a pu être
  achevée. Un dossier complet de 1 à 8 est désormais réellement **déposable**.
- « Relecture / envoi » (étape 9) reste fermée. Aucun bouton de dépôt ne vit sur
  l'écran de l'étape 8 : le dépôt appartient à l'étape 9.
