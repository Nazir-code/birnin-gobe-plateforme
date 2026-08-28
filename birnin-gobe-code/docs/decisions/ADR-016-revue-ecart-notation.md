# ADR-016 — La revue d'écart entre évaluateurs

*Statut : accepté. Portée : §11.3, phrase « écart supérieur à un seuil configurable déclenche une revue ».*

## Contexte

ADR-014 a rendu le seuil d'écart administrable ; ADR-015 a produit les notes. Entre les
deux, rien ne lisait le seuil : il était enregistré, et l'écran de paramètres le disait
lui-même. Cet incrément le branche.

C'est aussi le premier écran d'administration qui **regarde des notations**, et il fallait
donc trancher précisément ce que l'administration a le droit d'en faire. Le §11.3 est net :
« le gestionnaire voit l'avancement mais pas une modification silencieuse des notes ».
Jusqu'ici cette phrase était tenue par omission — aucun écran ne montrait de note. Elle
devient maintenant une contrainte à faire respecter.

## Décisions

### 1. L'écart se mesure critère par critère, pas sur la note globale

Le §11.3 ne dit pas écart entre quoi. Deux lectures étaient possibles, et le choix décide de
ce que la revue permet de faire :

- **l'écart entre les notes sur 100** dit qu'on n'est pas d'accord, mais pas sur quoi. Vingt
  points peuvent venir d'un désaccord franc sur la faisabilité technique comme de huit
  petits désaccords sans objet ;
- **l'écart par critère, sur l'échelle 0–5**, nomme le désaccord. Deux évaluateurs qui
  mettent 1 et 5 sur « Faisabilité technique » ne lisent pas le même dossier, et c'est cette
  phrase-là qu'une revue doit pouvoir écrire.

C'est la seconde. L'échelle du seuil déjà enregistré le confirmait d'ailleurs :
`EvaluationSettings::MAX_SCORE_GAP` vaut 5 depuis ADR-014 parce que le réglage a toujours
porté sur l'échelle du §11.3. L'écart des totaux reste calculé et affiché, mais comme
contexte de lecture — jamais comme déclencheur.

### 2. Aucune note consolidée n'est produite

Ni moyenne, ni médiane, ni note de consensus, ni classement. Le §11.3 veut cette règle
« choisie et documentée avant l'ouverture » ; ADR-015 l'avait laissée ouverte pour cette
raison, et l'inventer ici produirait un classement fondé sur une règle que personne n'a
arrêtée.

L'écran montre les notes côte à côte et nomme les critères qui divergent. C'est ce qui
permet d'arbitrer — un chiffre unique masquerait précisément ce qu'on vient regarder. Un
test vérifie qu'aucune clé d'agrégat n'apparaît dans les propriétés de la page : c'est une
propriété qu'on perd sans s'en apercevoir, en ajoutant « juste la moyenne, pour situer ».

### 3. Sans seuil arrêté, rien n'est déclaré divergent — et l'écran le dit

`CriterionSpread::exceeds()` rend `null`, jamais `false` : sans seuil, un écart n'est ni
acceptable ni excessif, il est **non comparé**. Trois conséquences, toutes testées :

- la file affiche les dossiers comparables avec le verdict « non comparé », jamais
  « conforme » ;
- l'alerte du §9.3 reste muette — même règle que la sous-couverture d'ADR-014 : alerter
  contre un seuil inventé ferait rouvrir des notations qui n'avaient rien d'anormal ;
- enregistrer une revue est **refusé**, parce qu'arbitrer reviendrait à trancher contre une
  règle que personne n'a fixée.

L'écran ne se contente pas de rester vide : une file vide laisserait croire qu'aucun dossier
ne diverge. Il dit pourquoi il ne peut rien dire, et renvoie vers les paramètres.

### 4. Une revue vaut pour l'état qu'elle a vu

C'est le cœur du mécanisme. `evaluation_reviews.covered_evaluations` retient le nombre
d'évaluations verrouillées **au moment de la revue**. Une évaluation ne se déverrouille
jamais (ADR-015), donc ce nombre ne peut que croître ; quand il croît, la revue cesse de
valoir et l'écart redevient à revoir.

C'est ce qui évite l'acquittement définitif qu'ADR-014 refuse pour les alertes : **on ne
fait pas taire un écart, on le revoit tel qu'il est devenu**. Un dossier arbitré à deux avis
qui en porte trois n'est plus arbitré — le désaccord n'est plus le même — et la file le
signale comme « périmé » plutôt que de le taire ou de le rouvrir sans explication.

### 5. Deux issues, et aucune ne touche à une note

`ADDITIONAL_EVALUATION` et `DIVERGENCE_ACCEPTED`. Ce que le responsable peut réellement
faire est soit demander un avis de plus, soit acter que le désaccord est légitime. Tout le
reste — modifier une note, écarter une évaluation — lui est interdit et doit le rester : une
notation qu'un gestionnaire peut retoucher n'est plus une notation indépendante.

`ADDITIONAL_EVALUATION` **n'affecte personne** : elle enregistre une intention, et
l'affectation se fait sur l'écran du §11.1, qui est le seul à connaître la charge de chacun.
Faire les deux d'un clic aurait affecté au hasard.

**Acter n'est pas faire taire** : une divergence acceptée reste affichée, avec son motif et
sa date ; elle cesse seulement d'appeler un geste.

Un test vérifie qu'aucune route d'administration ne modifie `evaluation_scores` — c'est la
forme exécutable de la phrase du §11.3, plutôt que sa forme déclarative.

### 6. Le motif est exigé, et l'état vu est figé

Quinze caractères minimum. Ce n'est pas une contrainte de forme : elle écarte le « vu » ou
le « ok » qui n'apprend rien. Une revue sans explication ne prouve pas qu'elle a eu lieu,
elle prouve qu'on a cliqué — et la question qu'un contrôle posera est « pourquoi ce
désaccord a-t-il été jugé acceptable ».

`observed_gap` fige l'écart constaté ce jour-là. Il n'est **jamais relu pour décider** — le
calcul courant fait foi. Il répond à « qu'avait-on sous les yeux en actant ce désaccord ? »,
question à laquelle un écart recalculé sur des données depuis enrichies ne répondrait pas.

La lecture et l'écriture se font dans la même transaction, sous verrou : deux gestionnaires
qui revoient le même dossier pendant qu'une évaluation se verrouille ne doivent pas écrire
deux revues portant sur des états différents en se croyant d'accord.

### 7. Les notes sont nominatives ici, invisibles chez l'évaluateur

Apparente contradiction avec ADR-015, qui interdit à un évaluateur de voir quoi que ce soit
d'un collègue. Ce n'en est pas une : **l'indépendance protège la notation pendant qu'elle se
fait**. Après le verrouillage, savoir qu'un évaluateur est systématiquement plus sévère est
une information de pilotage que le §11.1 demande justement de prendre en compte, et
comparer deux notations anonymes ne permettrait pas d'arbitrer.

Les justifications de critère sont montrées avec les notes : c'est là que se lit la raison
d'un écart, et la reléguer ailleurs obligerait à recoller deux écrans.

### 8. Ce qui se recalcule ne se persiste toujours pas

La divergence est un calcul sur l'état réel, comme les alertes d'ADR-014. Ce qui est
persisté, c'est **l'acte humain** — la revue — et non son objet. Persister l'écart obligerait
à le corriger quand une évaluation arrive, donc à maintenir un chiffre qui se déduit déjà.

Le filtrage se fait en mémoire, et c'est assumé : l'exprimer en SQL supposerait une jointure
croisée sur `evaluation_scores` reconstruisant en base ce que `CriterionSpread` exprime en
une ligne, pour une volumétrie qui reste celle d'une campagne. Les évaluations et leurs
notes sont chargées en deux requêtes ; c'est le nombre de requêtes qui compte, pas le lieu
du calcul.

## Conséquences

- `adminNav` compte dix entrées ; « Écarts de notation » s'intercale entre Évaluateurs et
  Indicateurs, dans l'ordre du travail.
- Le §9.3 gagne une huitième alerte, `evaluation.ecarts_a_revoir`, `WARNING` et non
  `CRITICAL` : un écart n'a de conséquence ni pour un candidat ni pour le calendrier tant
  que la présélection n'est pas close. Il en aurait une si l'on classait sans avoir arbitré,
  mais le classement relève du §12.
- L'écran de paramètres ne peut plus dire que le seuil d'écart n'est lu par rien.

## Ce qui reste ouvert

- **La règle d'agrégation du §11.3** — moyenne, médiane ou consensus — reste à arbitrer, et
  c'est elle qui débloquera le classement. Cet incrément la rend plus urgente qu'il ne la
  résout : on sait maintenant où les avis divergent, pas comment les additionner.
- **La short-list du §11.3** (3 à 4 dossiers par thématique, générée comme proposition).
- **La sélection finale (§12)** et l'espace jury.
- **La détection de biais systématique** : l'écran montre les écarts dossier par dossier,
  pas la sévérité moyenne d'un évaluateur sur l'ensemble de ses notations. C'est une lecture
  différente, qui suppose d'arbitrer ce qu'on en fait — signaler un évaluateur sur une
  statistique sans règle décidée serait exactement ce que cet ADR refuse par ailleurs.
