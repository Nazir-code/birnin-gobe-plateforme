# ADR-014 — Pilotage du back-office : affectation, indicateurs, alertes, paramètres

*Statut : accepté. Portée : §9.2, §9.3, §11.1, §13.1 et §13.4 du cahier des charges.*

## Contexte

Quatre entrées de la navigation d'administration étaient inertes : Évaluateurs,
Indicateurs, Alertes, Paramètres. Elles décrivaient l'architecture cible du back-office
sans rien derrière. Cet incrément les ouvre. Avec ADR-013 (contrôle d'admissibilité), les
neuf écrans du back-office existent désormais.

Le fil commun des quatre est le même que celui d'ADR-007 et d'ADR-013 : **ne jamais
afficher un verdict fondé sur un seuil que personne n'a arrêté, ni un zéro qui veut dire
« pas mesuré »**. Chacun des quatre écrans a eu à trancher ce point, et chacun l'a tranché
dans le même sens.

## Décisions communes

### 1. « Non mesuré », « inconnu » et « zéro » sont trois états distincts

- Un indicateur sans source rend `null` et l'écran affiche « — », jamais 0.
- La couverture d'un dossier vaut `null` tant que le nombre minimal d'évaluations n'est
  pas arrêté (§9.2) — ni « couvert », ni « à compléter ».
- L'alerte de sous-couverture reste **muette** dans ce cas : alerter sur un seuil inventé
  ferait courir après un objectif que personne n'a fixé.

Le coût de l'erreur inverse est concret : afficher 0 candidature féminine parce qu'aucune
mesure n'existe, ou colorer en rouge des dossiers jugés sous-couverts contre un seuil
imaginaire, produit des décisions de pilotage fausses.

### 2. Ce qui n'est pas instrumenté reste visible, et dit pourquoi

Les six familles d'indicateurs du §13.1 sont toutes affichées, dont trois qu'on ne sait pas
renseigner (Mobilisation, Finale, Qualité de service). Les neuf domaines paramétrables du
§9.2 sont tous listés, dont six qu'aucun écran n'administre.

La tentation inverse — n'afficher que ce qui est outillé — ferait paraître le back-office
complet, et le comité de pilotage découvrirait le reste en production. Chaque absence nomme
sa **dépendance manquante**, pas un « à faire » : le CMS n'existe pas, aucune notification
n'est envoyée, aucune purge n'est implémentée.

### 3. Ce qui se recalcule ne se persiste pas

Alertes et indicateurs sont calculés à chaque lecture. Aucune table d'alertes, aucune table
d'agrégats.

Pour les alertes, c'est décisif : une alerte persistée demanderait un mécanisme
d'extinction, et une alerte qui survit à sa cause apprend à ignorer l'écran. **Il n'y a donc
aucun bouton « ignorer »** : le seul moyen de faire taire une alerte est de traiter ce
qu'elle signale, et chacune porte le lien qui y mène.

Pour les indicateurs, `IndicatorRefresh` existe pour que le fait soit *dit* : tous sont en
`LIVE`. Le jour où le volume imposera une agrégation nocturne, la fréquence changera là, et
l'écran cessera de laisser croire au temps réel.

## Décisions par écran

### Évaluateurs (§11.1)

**Un écran, trois panneaux** — évaluateurs et charge, dossiers à affecter, affectations en
vigueur. Équilibrer, c'est comparer ; trois pages obligeraient à mémoriser une charge lue
ailleurs.

**Pas d'équilibrage automatique.** Le §11.1 veut un algorithme tenant compte « de
l'expertise, de la charge, de la disponibilité et des conflits déclarés ». Seules la charge
et les conflits existent en base. Un bouton « équilibrage automatique » qui n'équilibrerait
que sur la charge porterait un nom mensonger, et le responsable lui ferait confiance pour ce
qu'il ne fait pas. L'écran classe les dossiers les moins couverts en tête, affiche la charge
de chacun et signale le moins chargé — l'arbitrage reste humain, et il est outillé.

**Retrait et conflit ne sont pas le même geste.** Les deux libèrent le dossier ; seul le
conflit interdit durablement de le reproposer à la même personne. Les confondre reviendrait
à réaffecter un dossier à quelqu'un qui s'en est récusé. D'où deux statuts distincts, et un
index unique **partiel** (`WHERE released_at IS NULL`) qui autorise la réaffectation après
retrait sans permettre le doublon en vigueur.

**Rien n'est supprimé, et le motif est exigé.** Une affectation levée sans explication n'est
compréhensible ni par l'évaluateur qui la perd, ni par le responsable qui reprendra le
dossier.

**Le statut ne recule pas.** `IN_EVALUATION → ADMISSIBLE` n'existe pas dans la machine à
états : lever la dernière affectation ne fait pas reculer un dossier dont une évaluation a
peut-être commencé. Le découvert est visible sur le tableau, et se comble en réaffectant.

**Un événement d'audit par dossier**, visant le dossier — pas un événement de lot visant
l'évaluateur. La question qu'on posera au journal est « à qui ce dossier a-t-il été confié »,
et un événement de lot ne serait jamais trouvé par le filtre par dossier.

### Indicateurs (§13.1, §13.4)

**Chaque indicateur porte sa fiche** — définition, formule, source, fréquence, niveau
d'accès — sur l'objet lui-même, donc à l'écran comme à l'export. Un chiffre exporté sans sa
définition finit dans une note de synthèse où il veut dire autre chose.

**Les petits effectifs sont masqués côté serveur.** Le §13.4 l'exige pour le genre, l'âge,
le handicap et la localisation. La valeur ne quitte pas le serveur : l'écran reçoit `null`
et un drapeau, jamais un chiffre accompagné d'une consigne de ne pas le montrer. Le seuil
s'applique **aussi à l'export CSV**, qui est le vrai chemin par lequel une donnée
ré-identifiante sortirait de l'application.

**Un zéro n'est pas masqué.** « Personne » n'identifie personne, et masquer les zéros ferait
disparaître les modalités vides — une région sans aucune candidature est précisément une
information de pilotage.

**Les modalités viennent du référentiel, pas des données.** Une ventilation qui ne montrerait
que ce qui existe cacherait les régions et les thématiques que personne n'a choisies.

**CSV et non XLSX** : le §13.2 demande « XLSX/CSV », et un vrai XLSX supposerait une
dépendance de plus pour un format que le tableur ouvrira de toute façon. Séparateur
point-virgule et BOM UTF-8, parce que c'est ce qu'Excel en configuration française attend —
un export que le destinataire doit réparer à la main n'est pas un export.

### Alertes (§9.3)

**Sept alertes, toutes chiffrées, toutes reliées à l'écran qui permet d'agir.** Une alerte
qui ne dit ni combien ni où n'est qu'une inquiétude, et laisse chercher soi-même les dossiers
concernés.

**Trois gravités, et `CRITICAL` est rare** : un délai de clarification dépassé, une clôture
franchie avec la file non vidée. Un écran où tout est urgent ne signale plus rien.

**Les seuils sont des constantes nommées, pas des réglages.** Le §9.3 demande des alertes sur
les retards sans fixer de délai, et le §9.2 ne fait pas figurer ces seuils parmi les
paramètres administrables. Les exposer comme réglage donnerait à croire qu'ils ont été
arbitrés.

**Ce qui n'est pas alerté** : les échecs de notification (aucun envoi n'existe, donc rien
n'échoue, et une alerte toujours à zéro apprend à ignorer l'écran) et les anomalies de saisie
(déjà présentées dossier par dossier au vérificateur par `AutomaticFindings` — les remonter
ici disperserait la même information sur deux écrans).

### Paramètres (§9.2)

**L'écran est d'abord un inventaire.** Neuf domaines, trois états : administrable (campagne,
éligibilité), partiel (évaluation), absent (les six autres).

**`PARTIEL` est signalé aussi nettement qu'`ABSENT`**, parce que c'est lui qui trompe :
croire l'évaluation paramétrée parce qu'on a fixé le nombre d'évaluateurs, alors que les
critères et leurs poids du §11.2 restent dans le code.

**Les domaines déjà outillés ne sont pas réimplémentés** : l'écran renvoie vers
`admin.campaigns.edit` et `admin.campaigns.eligibility.edit`. Un second formulaire écrivant
les mêmes colonnes finirait par diverger du premier.

**Un seul réglage est ajouté** — nombre minimal d'évaluations et seuil d'écart — parce que
l'affectation du §11.1 en dépend. Il suit la forme de `SaveEligibilitySettings` : le bloc
`evaluation` de `campaigns.settings` est remplacé, jamais `settings` entier, et rien n'est
écrit ni journalisé quand rien ne change.

**La campagne est dans l'URL, jamais dans le corps** : un réglage se rattache à une édition,
et laisser le formulaire désigner sa cible permettrait d'écrire sur une campagne qu'on ne
regardait pas.

## Conséquences

- Les neuf entrées de `adminNav` portent un `href`. La règle reste valable pour toute entrée
  future : sans écran, pas de lien.
- « L'écran existe » ne veut pas dire « le domaine est couvert ». Paramètres le dit de
  lui-même ; c'est à l'écran de le dire, pas à l'absence de lien.
- L'espace évaluateur (`/evaluator/assignments`) affichait encore des données de
  démonstration et **ne lisait pas** les affectations créées ici. C'est ADR-015 qui a fermé
  cet écart : charte du §11.1, grille du §11.2 et verrouillage du §11.3.

## Ce qui reste ouvert

- ~~L'espace évaluateur réel : lecture des affectations, charte, grille de notation sur 100
  points, commentaire obligatoire pour les notes extrêmes, verrouillage.~~ — fait, ADR-015.
- La revue d'écart du §11.3 : le seuil est enregistré ici, les notes existent depuis
  ADR-015, mais rien ne compare encore deux évaluations verrouillées.
- La sélection finale (§12), qui débloquerait la famille d'indicateurs « Finale ».
- Les notifications (§8.3), qui débloqueraient le domaine « Communication » et l'alerte sur
  les échecs d'envoi.
- Le CMS (§9.2 « Publication »), les thématiques et le formulaire administrables.
- La conservation des données (§9.2) : aucune purge n'est implémentée, et une durée affichée
  mais jamais appliquée serait une promesse fausse, opposable en cas de contrôle.
- La traçabilité des exports (§13.3 cite les exports parmi les actions à journaliser) : elle
  suppose de tracer aussi ce qui a été exporté et pour qui, donc d'arbitrer une rétention.
