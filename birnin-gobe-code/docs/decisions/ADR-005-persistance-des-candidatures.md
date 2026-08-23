# ADR-005 — Persistance de la candidature du candidat

Statut : accepté — Phase 1C
Contexte : fait suite à ADR-003 (séparation des espaces) et ADR-004 (authentification candidat).

## Contexte

L'espace candidat existait avec une vraie authentification, mais son contenu
restait une maquette : statut « Brouillon » écrit en dur, complétude à 65 %,
date limite au 30 juin 2026, neuf étapes dont trois marquées faites. Aucune de
ces valeurs ne venait de la base, et le formulaire n'écrivait nulle part.

Cette décision porte sur ce qui rend la candidature réelle : à qui elle
appartient, comment ses réponses sont stockées, et comment elles sont
enregistrées pendant la saisie.

## Décisions

### 1. Propriété — `applications.candidate_id` vers `users.id`

La colonne `candidate_id` existait depuis la migration initiale, sans contrainte
d'intégrité. Elle est conservée plutôt que remplacée par un `user_id` : elle dit
à quel titre l'utilisateur figure sur la candidature, ce qui compte sur une
plateforme où un même compte pourra plus tard apparaître comme évaluateur ou
membre du jury sur d'autres objets.

Ajouté par nouvelle migration, sans toucher à celle d'origine déjà appliquée :

- clé étrangère `candidate_id → users.id` ;
- `restrictOnDelete` et non `cascadeOnDelete` — une candidature est une pièce du
  dossier de la compétition. Supprimer un compte ne doit pas faire disparaître
  silencieusement un dossier déposé ; un effacement devra être une décision
  explicite et tracée ;
- index dédié sur `candidate_id` — l'unique `(campaign_id, candidate_id)` ne sert
  pas les recherches par candidat seul, qui sont le cas courant de l'espace
  candidat.

### 2. Unicité — une candidature par candidat et par campagne

L'unique `(campaign_id, candidate_id)` existait déjà en base ; la règle est
désormais appliquée des deux côtés :

- `StartApplication` lit d'abord le brouillon existant et le renvoie tel quel ;
- la contrainte d'unicité tranche la course entre deux requêtes simultanées,
  cas qu'une simple lecture préalable laisse passer. La violation est rattrapée
  et résolue en renvoyant le dossier gagnant.

Un double-clic, un rafraîchissement après envoi ou une requête rejouée
aboutissent donc au même dossier — comportement attendu sur un réseau mobile.

### 3. Ownership — une policy, pas des `if`

`ApplicationPolicy` est le point unique du contrôle d'accès à la ressource, et
les routes la déclarent (`can:view,application`, `can:update,application`). Les
contrôleurs ne comparent aucun identifiant.

Deux niveaux, tous deux nécessaires :

| Niveau | Mécanisme | Répond à |
|---|---|---|
| Espace | `role:candidate` | qui a le droit d'être ici |
| Ressource | `ApplicationPolicy` | quel dossier est le sien |

`update` refuse également toute candidature qui n'est plus au statut `DRAFT` :
c'est la traduction du contrat « formulaire en lecture seule après soumission ».

### 4. Modèle des sections — une ligne par section, pas une colonne par champ

Table `application_sections` : `(application_id, section)` unique, `answers`
en `jsonb`, `completed_at`.

Deux options écartées :

- **une colonne par champ sur `applications`** — le formulaire compte neuf
  sections et le cahier des charges prévoit qu'il soit paramétrable par
  campagne. Chaque ajustement de contenu deviendrait une migration, et la table
  dépasserait rapidement la cinquantaine de colonnes ;
- **un unique `jsonb` sur `applications`** — chaque sauvegarde automatique
  réécrirait la ligne entière de la candidature, réponses des autres sections
  comprises, et l'avancement par section n'aurait pas de support.

La granularité par section donne : une écriture limitée à la section éditée, un
emplacement naturel pour la date d'achèvement, et une progression déduite plutôt
que déclarée.

`jsonb` n'est pas une porte d'entrée pour des données non validées : chaque
section a sa `FormRequest`, et seuls les champs déclarés par sa classe de domaine
sont conservés.

### 5. Étape courante et progression, côté serveur

- `applications.current_step` porte la clé d'une section (`challenge`), jamais
  un libellé français ni un numéro : renuméroter les sections ne doit réécrire
  aucune ligne. « Reprendre ma candidature » fonctionne donc depuis un autre
  appareil, ou après vidage du navigateur.
- `completion_percent` = sections achevées sur neuf, calculé par le serveur.
  Volontairement grossier et honnête : une seule section étant branchée, le
  maximum atteignable aujourd'hui est 1/9, soit 11 %. Une pondération par nombre
  de champs supposerait connaître les huit sections restantes.

### 6. Sauvegarde automatique — une requête réelle, un état réel

L'indicateur « Enregistrement… / Enregistré / Erreur d'enregistrement » suit une
requête HTTP, plus une animation. Un cinquième état, « Modifications non
enregistrées », est ajouté : sans lui, l'écran afficherait « Enregistré »
pendant que le candidat tape, c'est-à-dire au seul moment où c'est faux.

Stratégie retenue, délibérément simple :

| Risque | Réponse |
|---|---|
| une requête par frappe | anti-rebond de 900 ms, plus envoi immédiat à la sortie d'un champ |
| requêtes concurrentes | une seule requête en vol ; les modifications survenues pendant l'envoi repartent à la réponse |
| réponse ancienne écrasant la nouvelle | numéro de séquence croissant, une réponse dépassée est ignorée |
| écriture après démontage | la requête en vol n'est pas annulée — ce serait perdre la saisie — mais plus aucun état React n'est écrit |

La source de vérité reste PostgreSQL. Rien n'est conservé en `localStorage` : un
rechargement repart des props Inertia, elles-mêmes issues de la base.

### 7. Audit

`APPLICATION_CREATED` est journalisé à l'ouverture du brouillon, via
`AuditWriter` et selon la convention existante (`APPLICATION_SUBMITTED`).

Les sauvegardes de brouillon ne sont **pas** auditées. Elles se déclenchent
toutes les quelques secondes pendant la saisie ; en tracer chacune noierait un
journal métier qui doit rester lisible lors d'un contrôle. `updated_at` et les
journaux techniques portent cette information.

## Conséquences

- Les écrans de candidature vivent sous l'identifiant du dossier
  (`/candidate/application/{application}/challenge`), ce qui rend l'ownership
  testable : changer l'identifiant dans l'URL donne 403.
- `/candidate/application` reste l'entrée stable de la navigation : elle redirige
  vers la section en cours, ou vers le tableau de bord si aucun dossier n'existe.
  Une navigation n'écrit jamais en base ; seul le `POST` crée.
- Une campagne ouverte est nécessaire pour déposer. `CampaignSeeder` en fournit
  une aux environnements de développement, en attendant l'administration.
- Une seule section est persistée. Ouvrir la suivante, c'est basculer son
  `isImplemented()`, écrire son écran et sa validation — la généralisation du
  contrôleur viendra avec la deuxième, quand la forme commune sera connue plutôt
  que devinée.

> **Mis à jour en Phase 1D.** « Éligibilité » est la deuxième section persistée,
> et devient l'étape 1 : un nouveau brouillon s'ouvre désormais sur
> `eligibility`. La comparaison des deux implémentations promise ci-dessus a été
> faite — voir ADR-007, qui conclut à factoriser deux composants React et à ne
> **pas** généraliser les contrôleurs.
