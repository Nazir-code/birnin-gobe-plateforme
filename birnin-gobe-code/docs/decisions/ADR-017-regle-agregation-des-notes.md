# ADR-017 — Règle d'agrégation des notes de présélection

*Statut : **proposé**. En attente de la décision du comité de pilotage.
Portée : §11.3, phrase « la moyenne, médiane ou note de consensus est choisie et documentée
avant l'ouverture ».*

> **Cet ADR ne décide rien.** Il pose la question, les options et leurs conséquences, pour
> que le comité tranche sur pièces. Il passera en *accepté* quand la décision sera rendue,
> et la section « Décision » sera alors remplie par ce qui aura été arbitré — pas par ce que
> ce document recommande.
>
> Une version mise en page pour le comité accompagne cet ADR ; son contenu est le même.

## Contexte

ADR-014 a rendu le seuil d'écart administrable. ADR-015 a produit les notes. ADR-016 a
branché la revue d'écart : on sait désormais **où** deux évaluateurs divergent, critère par
critère, et un responsable doit arbitrer avant tout classement.

Ce qui manque est le dernier maillon : **rien ne consolide deux évaluations en un chiffre**.
Donc rien ne classe, donc pas de short-list, donc pas de §12. C'est le verrou de toute la
suite du concours.

Cette absence est délibérée depuis ADR-015 : le §11.3 veut la règle « choisie et documentée
avant l'ouverture », et l'inventer dans le code aurait produit un classement fondé sur un
arbitrage que personne n'a rendu. C'est une décision de comité, pas une décision technique.

## Le fait qui reformule la question

**Avec deux évaluateurs, la moyenne et la médiane donnent exactement le même chiffre.**
Toujours, par construction : la médiane de deux nombres est leur moyenne.

Le choix du §11.3 n'a donc **aucun effet** tant qu'un dossier ne reçoit que deux avis. Il est
inséparable d'une seconde décision, réglée au §9.2 : **le nombre minimal d'évaluations par
dossier**. Les deux se prennent ensemble, sans quoi le comité choisit un outil sans savoir
s'il servira.

Vérifié sur les deux dossiers réellement notés dans l'environnement de démonstration :

| Dossier | Avis 1 | Avis 2 | Écart | Moyenne | Médiane |
|---|---:|---:|---:|---:|---:|
| BG-2026-009 | 69,00 | 51,00 | 18,00 | 60,00 | 60,00 |
| BG-2026-010 | 66,00 | 57,00 | 9,00 | 61,50 | 61,50 |

D'où vient l'écart de BG-2026-009 : sur huit critères, les deux évaluateurs sont d'accord sur
six. Tout tient dans deux lignes — faisabilité technique 5 contre 1, innovation 4 contre 2.
L'un a vu un prototype qui tourne, l'autre a lu une intention d'architecture. **La moyenne,
60,00, ne dit rien de cela** ; c'est précisément ce que l'écran de revue d'ADR-016 existe pour
montrer.

À trois évaluations, la règle se met à compter. Même dossier, troisième avis à 67,00 :
moyenne **62,33**, médiane **67,00**. Près de cinq points — largement de quoi faire entrer ou
sortir un dossier d'une short-list de trois à quatre par thématique.

## Les options

### Moyenne pondérée

Chaque évaluateur pèse autant que les autres ; un avis minoritaire déplace le résultat à
proportion.

**Ce qu'elle coûte** : un évaluateur systématiquement sévère fait baisser tous ses dossiers,
et un seul avis très bas peut écarter un bon dossier.

**Ce qui la rend praticable ici** : le contrepoids existe déjà. La revue d'écart (ADR-016)
oblige un responsable à examiner tout désaccord au-delà du seuil *avant* qu'il n'entre dans un
classement, et à écrire pourquoi il l'accepte ou demande un avis de plus.

### Médiane

Retient la valeur du milieu ; insensible aux extrêmes.

**Ce qu'elle coûte** : elle écarte l'avis isolé **sans distinguer s'il a tort**. L'évaluateur
qui est le seul à avoir repéré une faille technique réelle est traité exactement comme un
évaluateur distrait. Elle suppose aussi un nombre impair d'avis, sans quoi elle retombe sur
une moyenne.

**Quand elle serait le bon choix** : des évaluateurs nombreux, peu formés, et aucune revue
humaine des écarts. Ce n'est pas la situation.

### Note de consensus

Les évaluateurs d'un dossier se réunissent et arrêtent ensemble une note unique. Ce n'est pas
une formule, c'est une séance.

**Ce qu'elle coûte**, doublement : sur le calendrier, une séance par dossier divergent, entre
évaluateurs bénévoles et dispersés ; sur la méthode, elle contredit frontalement
l'indépendance que le §11.3 exige par ailleurs, puisqu'elle fait discuter les notes entre
évaluateurs.

**Quand elle serait le bon choix** : la finale du §12, où le jury siège de toute façon et où
les dossiers sont peu nombreux. Pas une présélection de plusieurs centaines de dossiers.

## Ce qui doit être décidé dans la même délibération

1. **Le nombre minimal d'évaluations par dossier.** C'est lui qui donne un sens au choix
   ci-dessus. À arbitrer contre la charge : trois avis sur trois cents dossiers font neuf
   cents notations.
2. **Le sort d'un dossier qui n'atteint pas ce nombre.** Il reste aujourd'hui hors du
   classement, et l'écran d'affectation montre le découvert. Faut-il un délai au-delà duquel
   on classe avec les avis disponibles ?
3. **L'arrondi et les égalités.** Le score est calculé au centième. Le §12.3 publie déjà un
   ordre de départage pour la finale — pertinence, impact, faisabilité, inclusion — et il
   serait cohérent de l'appliquer ici plutôt que d'en inventer un second.
4. **Le seuil d'écart déclenchant la revue.** Il vaut 2 sur l'échelle 0–5 dans
   l'environnement de démonstration. **Ce chiffre n'a jamais été arbitré** : il a été posé
   pour que l'écran ait quelque chose à montrer.

## Recommandation soumise au comité

**Moyenne pondérée, minimum de trois évaluations par dossier**, revue d'écart maintenue comme
préalable obligatoire au classement.

L'argument tient en une phrase : *la plateforme a déjà un mécanisme pour traiter les
désaccords, et il est meilleur qu'une formule*. La médiane écarte l'avis minoritaire sans que
personne ne se prononce ; la revue d'écart force quelqu'un à le regarder et à écrire ce qu'il
en pense. Ce qui est écrit est opposable ; ce qui est silencieusement moyenné ne l'est pas.

Si la charge de trois évaluations est jugée impossible, la recommandation change de nature
plutôt que de contenu : à deux avis, le choix de la règle n'a aucun effet, et la seule
protection réelle de la notation reste la revue d'écart. Il faudra alors se demander si un
classement fondé sur deux avis est défendable — question plus lourde que celle de la formule.

## Décision

*À remplir après la délibération du comité.*

## Conséquences attendues

- La règle se placera là où le calcul est déjà préparé, et l'écran de comparaison des écarts
  cessera d'afficher « aucune note consolidée n'est calculée » — mention volontaire d'ADR-016,
  parce qu'un chiffre unique masquerait le désaccord qu'on vient regarder.
- Suivront, dans cet ordre : le classement par thématique, la short-list de trois à quatre
  dossiers générée **comme proposition**, puis la validation par le comité que le §11.3 exige.
- Le nombre minimal d'évaluations retenu se règle dans l'écran des paramètres (§9.2) et
  n'exige aucun développement.

## Ce qui reste ouvert quoi qu'il soit décidé

- La détection d'un biais systématique — la sévérité moyenne d'un évaluateur sur l'ensemble
  de ses notations, par opposition aux écarts dossier par dossier qu'ADR-016 montre déjà.
  Signaler quelqu'un sur une statistique sans règle décidée serait exactement ce que cet ADR
  refuse par ailleurs.
- La pondération des évaluateurs entre eux (expertise, ancienneté). Aucune des trois options
  ne la prévoit, et le §11.3 ne la mentionne pas.
