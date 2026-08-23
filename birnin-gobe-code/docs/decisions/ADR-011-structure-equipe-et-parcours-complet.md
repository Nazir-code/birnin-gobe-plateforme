# ADR-011 — Structure / équipe : variantes conditionnelles et parcours refermé

Statut : accepté — Phase 1F
Contexte : fait suite à ADR-005 (persistance), ADR-007 (éligibilité) et ADR-009 (parcours et sections).

## Contexte

L'étape 3 est la dernière pièce manquante du début de parcours. Trois questions
la distinguent des trois sections déjà développées :

1. Son contenu **dépend d'une autre section** — le type de candidature vient de
   l'étape 1.
2. Elle porte une **collection** (les membres), et non des champs plats.
3. Son ouverture **change la règle du parcours pour tous les dossiers**, y
   compris ceux déjà en base.

## Décision 1 — Trois variantes, une seule source du type

Le §6.2 est explicite : « Type de candidature : Individuelle, équipe informelle
ou startup constituée. **Détermine les champs et pièces conditionnels**. »

| Variante | Ce que le §6.2 prévoit |
|---|---|
| `INDIVIDUAL` | rien — ni personne morale, ni autre membre |
| `TEAM` | des membres ; « équipe informelle », donc la mention « non constituée » |
| `STARTUP` | les données légales **et** les membres |

Le type n'est **jamais écrit par cette section**. La `FormRequest` le relit
depuis la section `eligibility` du dossier, et c'est lui qui décide des règles
appliquées. Conséquence testée : une requête forgée annonçant
`candidate_type=STARTUP` n'ouvre aucun champ de structure, et ne modifie pas la
réponse de l'étape 1.

Le corollaire est que les réponses hors variante ne sont pas persistées. Une
équipe informelle qui enverrait un RCCM ne le voit pas enregistré : la mention
« non constituée » du §6.2 n'est pas un champ à cocher, c'est l'absence de
personne morale.

**Candidature individuelle : l'écran ne demande rien.** C'est le point le plus
facile à mal faire. Le §6.2 ne prévoit aucune donnée pour cette variante, et
fabriquer des champs pour meubler l'écran aurait été pire que l'écran vide. La
section est donc complète sans contenu — mais seulement après un **acte
explicite** du candidat (« Enregistrer » ou « Suivant »), jamais par la simple
visite : ADR-009 tenait déjà cette ligne, elle ne bouge pas.

## Décision 2 — L'effectif : une valeur déclarée, une équipe décrite

L'étape 1 collecte déjà `team_size`, dont la règle d'éligibilité a besoin avant
que l'équipe ne soit décrite. L'étape 3 décrit la même équipe, autrement.

Deux valeurs, deux rôles, aucune n'écrase l'autre :

| | Rôle |
|---|---|
| `eligibility.team_size` | **déclaration préliminaire**, ce sur quoi l'éligibilité s'est prononcée |
| liste des membres | **description réelle**, ce que le dossier contient |

L'effectif décrit vaut `membres + 1` : le porteur principal n'est pas dans la
liste. Son identité vit dans son compte et dans l'étape 2 — l'ajouter comme
membre créerait un doublon de la personne qui remplit le formulaire.

Un écart entre les deux **empêche l'achèvement de la section** et est expliqué au
candidat, qui reste seul juge : soit il ajuste sa liste, soit il corrige son
annonce à l'étape 1. Aucune écriture silencieuse dans l'autre sens — c'est la
même règle qu'ADR-007 sur les paramètres de campagne : le logiciel constate, il
ne décide pas à la place de l'utilisateur.

Les bornes minimale et maximale du §6.2 (« nombre minimal/maximal configurable »)
existent déjà : elles vivent dans `campaign.settings.eligibility.team_size` et
sont appliquées par le moteur d'éligibilité (ADR-010). Cette section ne les
redéclare pas.

## Décision 3 — Les membres restent en `jsonb`, et pourquoi

La question méritait d'être posée : une liste de membres avec identité, contact,
rôle, compétences et consentement ressemble à une entité. Elle a été tranchée en
faveur du `jsonb` de `application_sections`, section `team`.

**Ce qu'une table `application_team_members` aurait apporté** — rien qui soit
utile aujourd'hui. Aucune requête ne cherche un membre indépendamment de son
dossier ; le reporting du §11 agrège des candidatures, pas des personnes.

**Ce qu'elle aurait coûté, en revanche, immédiatement :**

- des identifiants de membres exposés au navigateur, donc une surface
  d'ownership supplémentaire à défendre — alors que la règle actuelle est
  simple et déjà éprouvée : on autorise **le dossier**, et tout ce qu'il contient
  suit ;
- des points d'entrée par membre (ajouter, modifier, retirer), là où la
  sauvegarde automatique envoie aujourd'hui la section entière en une requête —
  ce qui compte sur les réseaux visés (§1.1, « connectivité instable ») ;
- une seconde mécanique de complétude, à côté de `completed_at`.

**Ce qui justifierait de migrer**, et qu'il faudra surveiller : le CV par membre
(§7.2), qui suppose une pièce jointe rattachée à une personne, et un consentement
qui deviendrait un acte du membre lui-même — horodaté, tracé, peut-être lié à un
compte. Le jour où l'un des deux arrive, la table se justifie ; elle appartiendra
alors à `Application` (clé étrangère, suppression en cascade), pas à une entité
`teams` globale : une équipe n'existe que dans une candidature.

## Décision 4 — Le parcours se referme, et les anciens dossiers en profitent

Avec l'étape 3 développée, `openPath()` devient
`[éligibilité, profil, structure, défi]` : les quatre premières sections se
suivent sans trou. « Défi », qui vivait hors parcours depuis ADR-009, y rentre
**mécaniquement** — aucune ligne de code ne le mentionne, c'est la définition
d'`isOnOpenPath()` qui le fait. La progression peut enfin atteindre 4/9.

Cela a révélé un défaut d'ADR-009 : `applications.completion_percent` est une
valeur dérivée, mais elle n'était recalculée qu'à l'écriture. Ouvrir une étape
change la règle pour tout le monde, or les dossiers déjà en base ne sont pas
réécrits — un brouillon d'avant cette phase aurait continué d'afficher un
pourcentage calculé sous l'ancienne règle jusqu'à sa prochaine sauvegarde.

D'où `ApplicationProgress`, extrait de `SaveApplicationSection` :

- la **lecture** recalcule toujours, à partir des sections réellement achevées ;
- la colonne reste écrite à chaque sauvegarde, comme cache pour les requêtes
  d'administration et de reporting, qui ne peuvent pas charger chaque dossier.

Aucune migration de données. Un brouillon dont « Défi » était rempli voit son
pourcentage passer de lui-même de 1/9 à 2/9, son `completed_at` intact.

**`current_step` n'est pas réécrit.** Un ancien dossier positionné sur « Défi »
y reste : c'est un fait historique, pas une recommandation. Ce que l'interface
change, c'est le chemin de retour — « Précédent » depuis « Défi » mène désormais
à l'étape 3, par où le candidat rejoint le parcours complet.

## Décision 5 — L'abstraction de section n'a pas bougé

ADR-009 avait extrait trois briques : `section()`, `navigation()` et
`savedPayload()`. La quatrième section les a utilisées telles quelles, sans
paramètre supplémentaire — c'est le meilleur signe qu'elles étaient au bon
niveau.

Rien de plus n'a été généralisé. Ce qui reste propre à chaque section — champs,
validation, notion de complétude — a **divergé davantage**, pas moins : cette
étape est la première dont la validation dépend d'une autre section et dont la
complétude se calcule par une classe dédiée (`TeamSectionAssessment`). Un
contrôleur générique aurait dû accepter un paramètre pour chacune de ces
différences.

Une seule signature a été élargie : `useAutosave` accepte désormais
`Record<string, unknown>` au lieu de `Record<string, string>`, parce que cette
section envoie un tableau d'objets. Le hook ne fait que comparer et sérialiser
sa charge utile — la nature des valeurs ne le concerne pas.

## Conséquences

- Quatre sections sur neuf sont développées, et pour la première fois elles se
  suivent : la progression maximale honnête passe à 44 %.
- Les champs du §6.2 non implémentés le sont pour des raisons nommées : le CV
  des membres est une pièce (§7.2, étape 8, stockage objet) ; la diversité par
  membre est conditionnée à des « besoins de suivi » que rien n'énonce, et le §6
  exige qu'un champ sensible soit justifié par une finalité précise ; le
  changement de représentant tracé (§6.2) suppose un second compte.
- Ouvrir l'étape 5 suffira à prolonger le parcours, sans toucher à l'existant.
