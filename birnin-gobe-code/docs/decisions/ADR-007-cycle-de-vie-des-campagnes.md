# ADR-007 — Cycle de vie et unicité de la campagne ouverte

**Statut :** accepté
**Date :** 2026-08-23

## Contexte

La table `campaigns` existait depuis la migration initiale, et la Phase 1C lui a
donné un modèle, un enum de statut et `ActiveCampaign`. Mais aucune campagne ne
pouvait être créée ni modifiée autrement que par un seeder de développement : le
calendrier de la compétition n'était administrable par personne.

Deux règles manquaient, et le cahier des charges ne les fournit pas. Son §9.2
énumère les paramètres administrables d'une campagne — « Nom, édition, statut,
dates, fuseau horaire, compte à rebours, période de grâce, domaine, contacts et
textes légaux » — sans décrire ni les transitions de statut, ni le nombre de
campagnes ouvertes simultanément.

## Décision

### Une seule campagne peut porter le statut `OPEN`

`ActiveCampaign::resolve()` renvoie **une** campagne. Quand plusieurs sont
ouvertes, il en choisit une par tri (`opens_at` décroissant, puis `id`). Ce
départage est arbitraire et invisible, et il décide vers quelle campagne partent
toutes les candidatures. L'invariant rend le tri inutile : il ne peut y avoir
qu'une candidate.

Cette règle n'est **pas** dans le cahier des charges. Elle a été arbitrée
explicitement, contre l'alternative — plusieurs campagnes ouvertes, avec un
avertissement dans l'interface — parce qu'un départage silencieux entre deux
campagnes ouvertes est le genre de comportement dont personne ne se souvient au
moment où il fait des dégâts.

**Deux barrières, comme pour l'élévation de privilège d'ADR-004 :**

1. `SaveCampaign` interroge la base avant d'écrire et rend une erreur de
   validation nommant la campagne déjà ouverte, avec la marche à suivre.
2. Un index partiel PostgreSQL `campaigns_une_seule_ouverte` — unique sur
   `status` restreint aux lignes `OPEN`.

La seconde n'est pas redondante. Entre la lecture « aucune autre campagne n'est
ouverte » et l'écriture, une requête concurrente peut en ouvrir une : seule la
base tranche cette course. La première existe pour que le cas courant produise
un message lisible plutôt qu'une erreur SQL.

**Conséquence sur la Phase 1C.** `test_un_meme_candidat_peut_candidater_a_une_autre_campagne`
créait une seconde campagne avec le statut par défaut de la factory, `OPEN`. La
fixture passe en `draft()`. Ce que le test vérifie — un même candidat peut avoir
un dossier dans deux campagnes — n'en dépend pas, et son assertion est inchangée.

### Transitions

```
DRAFT ──→ OPEN ──→ CLOSED ──→ ARCHIVED
  │                  │
  └──→ ARCHIVED ←────┘
```

Déduit de ce que le statut commande réellement : `ActiveCampaign` n'accepte de
candidature que sur une campagne `OPEN`.

| Interdit | Pourquoi |
|---|---|
| `OPEN → DRAFT` | Revenir en préparation alors que des dossiers existent efface la lisibilité du calendrier. |
| `OPEN → ARCHIVED` | On archive ce qui est clos ; sauter la clôture rend l'historique illisible. |
| Toute sortie d'`ARCHIVED` | L'archivage est terminal. |

`CLOSED → OPEN` est en revanche **autorisé**, contrairement à ce que ferait un
décalque d'`ApplicationStateMachine`. Une clôture est une décision
administrative, pas un fait irréversible : prolonger un délai annoncé, ou revenir
sur une clôture déclenchée un jour trop tôt, sont des situations réelles.
L'interdire obligerait à corriger la base à la main — moins sûr, pas plus.

Les deux domaines ont donc des cycles différents, et c'est voulu : un statut de
candidature engage le candidat, un statut de campagne engage l'administration.

`CampaignLifecycle` porte cette table. Le formulaire ne propose que les
transitions légales, mais `SaveCampaign` revalide : un menu réduit n'est jamais
une autorisation (ADR-003).

### Pas de suppression

`applications.campaign_id` est déclaré `cascadeOnDelete`. Supprimer une campagne
emporterait silencieusement tous ses dossiers — déposés par des personnes
réelles, et référencés par le journal d'audit.

Aucune route de suppression n'existe donc, et il n'y a pas non plus de bouton
désactivé qui en suggérerait une. L'archivage retire une campagne de
l'exploitation sans rien détruire ; un test vérifie que les candidatures
survivent à l'archivage.

### Les dates sont des heures murales, lues dans le fuseau de la campagne

`campaigns.timezone` existait sans usage. Il en a un désormais : le champ
`datetime-local` ne transporte aucun décalage, « 23:59 » est une heure de
pendule. C'est le fuseau **de la campagne** qui lui donne un instant, pas celui
du serveur ni celui du navigateur — un gestionnaire à Paris qui fixe la clôture
au 30 novembre à 23:59 la fixe à Niamey, pas chez lui.

Les instants sont ramenés à UTC avant d'être confiés au modèle. Le cast de date
de Laravel sérialise `Y-m-d H:i:s` sans décalage ; PostgreSQL interpréterait
sinon cette heure dans le fuseau de la session, et 08:00 à Niamey serait
enregistré comme 08:00 UTC. Une heure d'écart, silencieuse, sur une date de
clôture.

### Statut et fenêtre sont deux choses distinctes

Une campagne n'accepte de candidature que déclarée `OPEN` **et** dans sa fenêtre
de dates — c'est la règle d'`ActiveCampaign`, inchangée par cette phase.

L'administration affiche les deux séparément, parce que les confondre empêche de
comprendre pourquoi une campagne ouverte ne reçoit rien. Le tableau de bord et la
liste signalent explicitement le cas « déclarée ouverte, hors de sa fenêtre »,
qui est une configuration à corriger et non un état d'attente normal.

### `settings` n'est pas exposé

La colonne `jsonb` existe et le cahier (§9.2) prévoit d'y loger compte à rebours,
période de grâce, domaine, contacts et textes légaux. **Rien ne la lit
aujourd'hui.**

Cette phase n'expose donc rien de tout cela, et surtout pas un éditeur JSON
générique : un formulaire qui accepte n'importe quelle structure ne valide rien
et devient la source des incohérences qu'il était censé éviter. Ces paramètres
viendront un par un, avec l'écran qui les consomme.

`SaveCampaign` n'écrit pas `settings` : une campagne modifiée conserve les siens.
Une phase qui n'expose pas un champ ne doit pas l'effacer au passage.

### Audit

Trois événements, sur le contrat d'`AuditWriter` et la convention de nommage de
la Phase 1C (`APPLICATION_CREATED`) :

| Action | Quand |
|---|---|
| `CAMPAIGN_CREATED` | création |
| `CAMPAIGN_UPDATED` | modification à statut inchangé |
| `CAMPAIGN_STATUS_CHANGED` | modification qui change le statut |

Un changement de statut est une décision, une correction de libellé n'en est pas
une : les relire mélangés dans le journal ne rendrait service à personne.

La consultation n'est pas journalisée. Un journal qui enregistre chaque
affichage noie les décisions sous le bruit ; un test vérifie qu'ouvrir la liste,
le formulaire et le tableau de bord n'écrit rien.

## Ce qui n'est délibérément pas fait

| Sujet | Raison |
|---|---|
| **Paramètres de campagne du §9.2** (compte à rebours, période de grâce, domaine, contacts, textes légaux) | Aucun écran ne les lit. Ils viendront avec leur consommateur, dans `settings`. |
| **Éligibilité, thématiques, formulaire, évaluation** | Autres domaines du §9.2, avec leurs propres entités. Pas des paramètres de campagne. |
| **Indicateurs de candidatures sur le tableau de bord** | Admin Phase 3. Les brancher maintenant donnerait l'illusion d'un pilotage qui n'existe pas. |
| **Portail public** | La page d'accueil affiche encore un compte à rebours de démonstration (`demoCampaign`). C'est une dette identifiée : elle devra lire la campagne active. Hors périmètre de cette phase, qui ne touche pas au portail. |
| **`CampaignSeeder`** | Conservé. Il donne à un environnement local de quoi dérouler le parcours candidat sans passer par l'administration, et `firstOrCreate` le rend rejouable. Il n'est appelé que par `DatabaseSeeder`, jamais en production. |

## Vérification

- `tests/Feature/AdministrationCampagnesTest.php` — accès, création,
  validation, transitions légales et interdites, invariant de campagne ouverte
  (applicatif **et** base), campagne active, tableau de bord, absence de
  suppression, audit.
- `tests/E2E/admin-campagnes.spec.ts` — parcours réel : provisionnement d'un
  administrateur, création, liste, modification, persistance après rechargement.
