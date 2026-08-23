# ADR-009 — Parcours de candidature : source de vérité des données et ordre des étapes

Statut : accepté — Phase 1E
Contexte : fait suite à ADR-005 (persistance des candidatures) et ADR-007 (éligibilité guidée).

## Contexte

« Profil du candidat » est la troisième section développée, après « Éligibilité »
(étape 1) et « Défi » (étape 4). Trois questions se sont posées ensemble, et
elles sont liées :

1. Où vit une donnée que plusieurs écrans affichent ?
2. Que faire d'une section développée avant celle qui la précède ?
3. Qu'est-ce qui, après trois sections, mérite d'être factorisé ?

## Décision 1 — Une donnée, une source

Le cahier des charges décrit l'étape 2 (§5.2) comme « Identité, contacts,
localisation, situation professionnelle, besoins d'accessibilité et préférences
de communication », détaillée au §6.1. Plusieurs de ces données existent déjà
ailleurs dans le système.

| Donnée | Source de vérité | Ce que fait « Profil » |
|---|---|---|
| Nom complet, adresse e-mail | `users` — le compte | affiche en lecture seule |
| Date de naissance, nationalité, résidence au Niger | section `eligibility` | affiche en lecture seule, avec un lien pour corriger |
| Région d'**intervention** du projet | section `eligibility` | ne l'affiche pas : autre question |
| Contacts, résidence, situation professionnelle, accessibilité | section `profile` | détient et écrit |

**Modèle retenu : C — référencer sans recopier.** Les trois modèles proposés se
distinguent par ce qu'ils font en cas de divergence ; le seul qui n'en produise
jamais est celui qui n'a qu'un exemplaire de la donnée. « Profil » lit la date de
naissance de l'étape 1 et affiche un lien vers elle ; il ne la stocke pas, donc
`Eligibility.birth_date` et `Profile.birth_date` ne peuvent pas se contredire —
le second n'existe pas.

Un test le verrouille : une requête forgée contenant `name`, `email` ou
`birth_date` est acceptée sans que ces clés n'entrent dans `answers`, et sans que
le compte ne bouge.

**Deux localisations, pas un doublon.** Le §6.1 distingue « adresse de résidence /
Région, département, commune » de « zone d'intervention ». `residence_region`
(étape 2) répond à « où vivez-vous ? », `intervention_region` (étape 1) à « où
votre projet agira-t-il ? ». L'écran le dit explicitement pour éviter la
confusion.

**Pas de table `profiles`.** La question posée pour chaque donnée a été : appartient-elle
à la personne ou à cette candidature ? Le nom et l'adresse e-mail appartiennent à
la personne — ils sont déjà dans `users`. Tout le reste décrit la situation du
candidat **au moment de cette candidature** : une occupation, un numéro, un besoin
d'accessibilité changent d'une édition à l'autre, et le cahier des charges ne
demande nulle part qu'un profil survive entre campagnes. La section utilise donc
`application_sections` comme les deux autres. Créer une table parce que l'écran
s'appelle « Profil » aurait été suivre le vocabulaire de l'interface plutôt que
la durée de vie réelle de la donnée.

## Décision 2 — Le parcours ouvert s'arrête à la première étape manquante

L'ordre du cahier des charges (§5.2) est déjà celui de l'enum : éligibilité,
profil, structure/équipe, défi, solution, impact, plan, pièces, relecture.
Aucune renumérotation n'était nécessaire.

Mais « Défi » (étape 4) a été développé avant « Structure / équipe » (étape 3).
D'où deux notions désormais distinctes :

| | Signification |
|---|---|
| `isImplemented()` | la section a un écran, des champs, une validation |
| `isOnOpenPath()` | elle est de surcroît atteignable depuis l'étape 1 sans sauter d'étape |

Aujourd'hui : `openPath() = [éligibilité, profil]`. « Défi » est développé mais
hors parcours.

**Conséquences, toutes dans le même sens — ne rien annoncer de faux :**

- **Navigation.** « Suivant » suit `nextOnOpenPath()`. Depuis « Profil », il n'y
  a pas de suite : le bouton est désactivé et le dit. Sauter vers « Défi » aurait
  fait croire que l'étape 3 était franchie.
- **Progression.** Seules les sections achevées **du parcours ouvert** comptent.
  Remplir « Défi » ne fait pas avancer le pourcentage.
- **Rien n'est perdu.** « Défi » reste consultable et modifiable, ses réponses
  restent en base avec leur `completed_at`, et elles reprendront leur place dans
  le compte le jour où l'étape 3 ouvrira.
- **Le candidat est prévenu.** Le tableau de bord affiche une note nommant les
  sections concernées. Un pourcentage qui ne bouge pas après une étape entière
  remplie serait vécu comme un bug ; l'expliquer coûte une phrase.
- **Retour en arrière.** « Précédent » suit `previousImplemented()`, pas le
  parcours ouvert : depuis « Défi », un brouillon antérieur doit pouvoir revenir
  vers « Profil » et rejoindre le fil normal.

**Brouillons antérieurs.** Un dossier créé en Phase 1C/1D porte
`current_step = challenge`. Il reste récupérable tel quel : `/candidate/application`
y redirige, l'écran s'ouvre, les réponses sont intactes. Aucune migration de
données, aucune réinitialisation de progression — seul le pourcentage affiché
change, et la note du tableau de bord en donne la raison.

## Décision 3 — Ce que la troisième section a justifié d'extraire

ADR-007 avait refusé de généraliser avec deux sections, en annonçant que le vrai
signal serait « une troisième section dont la validation et les props se rangent
naturellement dans la même forme ». Audit fait, section par section.

**Extrait — réellement identique, même cycle de vie, même contrat :**

| Brique | Où | Ce qui se répétait |
|---|---|---|
| `ApplicationPresenter::section()` | entête d'écran | `key`/`label`/`position`/`total`/`completedAt`, mot pour mot 3× |
| `ApplicationPresenter::navigation()` | boutons | calcul de `previousUrl`/`nextUrl` — surtout : **la règle du parcours ouvert doit être appliquée au même endroit partout**, sinon deux écrans finiront par proposer deux parcours différents |
| `ApplicationPresenter::savedPayload()` | réponse d'autosave | `savedAt`/`application`/`steps`/`completed`, identique 3× |
| `SaveIndicator`, `SectionStepsAside` | React | déjà extraits en Phase 1D |

**Refusé — ce qui se ressemble sans être identique :**

- **Les contrôleurs.** Ce qui reste après extraction tient en six lignes par
  méthode, et chacune diffère : props transmises, règles de complétude, verdict
  d'éligibilité pour l'une, données déjà connues pour l'autre. Une fabrique
  paramétrée par section devrait accepter un paramètre pour chacune de ces
  différences ; elle remplacerait six lignes lisibles par une indirection qui
  rend chaque section plus difficile à comprendre isolément.
- **Les `FormRequest`.** Leur normalisation est le cœur du sujet et n'a rien de
  commun : « Éligibilité » retype en booléens et entiers, « Profil » normalise
  des numéros en E.164, « Défi » ne fait que rogner des chaînes.
- **Les définitions de champs.** `EligibilitySection`, `ProfileSection` et
  `ChallengeSection` restent chacune la seule source de leurs champs, règles et
  condition d'achèvement.
- **`EvaluateEligibility`** reste dans son domaine : aucune autre section n'a de
  verdict à rendre.

Aucun `GenericSectionController`, aucun moteur de formulaire dynamique. Le
critère appliqué : on factorise ce dont la **divergence serait un défaut**, pas
ce dont la répétition est simplement visible.

## Conséquences

- Trois sections sur neuf sont développées ; deux sont sur le parcours ouvert.
  La progression plafonne donc à 22 % pour un candidat qui suit le fil normal.
- Ouvrir « Structure / équipe » suffira à faire rentrer « Défi » dans le parcours
  et dans la progression, sans toucher aux données existantes.
- Les champs du §6.1 dont le référentiel n'existe pas encore — département,
  commune — ne sont pas collectés : le §6 impose que ces listes soient
  administrables et non codées en dur. Même raisonnement qu'ADR-007, appliqué à
  un référentiel plutôt qu'à un seuil.
- Le niveau d'études, lui, **est** proposé sous forme de liste : ce n'est pas un
  critère d'exclusion mais une donnée descriptive, et refuser d'en proposer une
  échelle reviendrait à ne pas collecter la donnée du tout. Le §9.2 range les
  options de formulaire parmi les paramètres administrables : elle pourra être
  ajustée sans redéploiement.
