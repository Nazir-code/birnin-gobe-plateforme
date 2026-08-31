# ADR-015 — L'espace évaluateur : charte, grille de présélection et verrouillage

*Statut : accepté. Portée : §11.1 (charte et récusation), §11.2 (grille sur 100 points), §11.3 (mécanique de notation). Corrige au passage les critères annoncés sur la page publique.*

## Contexte

ADR-014 a ouvert l'affectation des dossiers aux évaluateurs, et notait en conséquence
que `/evaluator/assignments` affichait encore des données de démonstration et **ne lisait
pas** les affectations créées. Cet incrément ferme cet écart : l'espace évaluateur lit les
affectations réelles, ouvre la grille du §11.2 et rend exécutables les règles du §11.3.

Le fil commun avec ADR-013 et ADR-014 se déplace ici d'un cran. Là où le contrôle
d'admissibilité protégeait le candidat contre une exclusion mal fondée, la notation doit
protéger **la comparaison entre dossiers** : une note sur 100 n'a de sens que si tous les
dossiers ont été notés sur la même grille, avec la même échelle, et que les notes ne
peuvent plus bouger une fois les écarts connus.

## Décisions

### 1. La charte est une porte, dossier par dossier

Le §11.1 dit « avant d'accéder à un dossier, chaque évaluateur accepte la charte, la
confidentialité et la déclaration d'impartialité ». Trois conséquences ont été tirées
littéralement :

- **L'écran de charte remplace l'écran du dossier**, il ne le surmonte pas. Tant que
  l'engagement n'est pas signé, la réponse ne contient ni sections, ni pièces, ni nom du
  porteur — seulement le numéro, la campagne et la thématique, qui suffisent à savoir si
  l'on a un lien avec le dossier. Un bandeau « pensez à accepter la charte » au-dessus d'un
  dossier déjà lisible ferait signer *après* avoir lu, ce qui vide la déclaration
  d'impartialité de son objet.
- **L'acceptation est par affectation**, pas par compte. On ne déclare pas être impartial
  en général : une acceptation unique à la première connexion serait signée avant de savoir
  sur quoi elle porte.
- **Aucune écriture de note n'est possible sans elle.** Poster directement sur la route de
  sauvegarde rend 404, pas seulement l'écran.

La **récusation est proposée au même niveau que l'acceptation**, sur le même écran. C'est
le moment où l'on découvre de quel dossier il s'agit, donc le moment où l'on sait. La
reléguer à un lien discret ferait accepter par défaut ceux qui hésitent.

Le texte de la charte est dans le code, pas en base : il n'y a pas de CMS, et publier une
charte « administrable » qu'aucun écran ne permet d'éditer donnerait à croire qu'elle a été
validée institutionnellement.

### 2. Enregistrer n'exige rien ; verrouiller exige tout

C'est la décision structurante de l'incrément. `SaveEvaluationDraft` ne vérifie **aucune**
règle du §11.3 : une feuille à moitié remplie, une note extrême encore sans justification,
aucune recommandation — tout s'enregistre. Refuser un brouillon incomplet obligerait à
noter un dossier en une seule séance, ou à perdre son travail ; c'est le contrat
d'enregistrement sans perte du candidat, appliqué à l'évaluateur.

`LockEvaluation` oppose les quatre exigences du §11.3, dans l'ordre où elles s'y lisent :
les huit critères notés, les notes extrêmes justifiées, une recommandation portée, un rejet
ou une short-list motivés. Les messages **nomment les critères fautifs** — « feuille
incomplète » obligerait à chercher lesquels.

Conséquence de forme : **le bouton « verrouiller » poste la saisie courante**. Un
verrouillage qui exigerait d'avoir enregistré d'abord perdrait la dernière modification de
quiconque l'oublie, et la perdrait au moment le plus coûteux. Si le verrouillage est
refusé, la saisie est déjà écrite : rien n'est perdu.

### 3. Le verrou est définitif, et c'est ce qui fait l'indépendance

Aucun déverrouillage n'existe, et ce n'est pas un oubli. « Les évaluations restent
indépendantes jusqu'au verrouillage » (§11.3) n'a de sens que si le verrou tient : une note
révisable après coup permettrait de s'aligner sur celle d'un collègue une fois l'écart
connu — exactement ce que l'indépendance interdit. Une erreur se corrige en levant
l'affectation et en réaffectant le dossier, ce qui laisse une trace, à la différence d'une
correction silencieuse.

Verrouillée, la page devient une lecture : tous les champs sont désactivés et les boutons
disparaissent. Afficher un formulaire modifiable qui échoue à l'envoi serait pire que de ne
rien afficher.

### 4. « Non noté » n'est pas « zéro », et un total partiel n'est pas une note

Zéro est une note réelle de l'échelle du §11.3 — « absent ou non recevable ». La colonne
`score` est donc nullable, et les confondre ferait apparaître comme jugé ce qui n'a pas été
lu.

De même, **le total reste `null` tant que les huit critères ne sont pas notés**. Un total
partiel se lirait comme une note faible alors qu'il ne dit que « pas fini », et c'est
précisément le malentendu qui fait écarter un bon dossier. Même règle qu'ADR-007 et
qu'ADR-014, appliquée au chiffre que le comité lira.

Le total est **calculé et enregistré par le serveur**, jamais reçu de l'écran : une note
sur 100 postée par le client serait une note sur 100 modifiable par le client. Le
navigateur affiche le même chiffre parce qu'il applique la même formule — `poids × note / 5`,
sommé, arrondi **une seule fois** à la fin. Arrondir chaque critère avant de sommer ferait
dériver le total de plusieurs dixièmes, et deux dossiers séparés par un dixième ne se
départagent pas sur une erreur d'arrondi.

### 5. La grille est dans le code, et sa somme vaut 100

Les huit critères et leurs poids du §11.2 sont un enum, pas un réglage. Le §9.2 prévoit bien
de les rendre configurables, mais tant que rien ne lit une grille alternative, un formulaire
qui laisserait modifier les poids publierait un réglage sans effet — c'est la raison déjà
retenue dans ADR-014 pour ne pas les exposer dans les paramètres d'évaluation.

**Un test vérifie que la somme des poids vaut exactement 100.** Ce n'est pas de la
coquetterie : le score pondéré est présenté au comité comme une note sur 100, et une somme
à 95 rendrait ce chiffre faux sans que rien ne l'affiche. Une faute de frappe dans un poids
est le genre d'erreur qu'aucune relecture ne rattrape.

Les **éléments d'appréciation sont affichés sous chaque critère**, pas dans une aide
séparée. Ce sont eux qui font que deux évaluateurs notent la même chose ; une ancre qu'il
faut aller chercher n'est pas une ancre.

### 6. Une troisième recommandation, parce que deux ne suffisent pas

Le §11.3 nomme la recommandation de rejet et celle de short-list. Il en manque une pour que
l'évaluateur puisse rendre son avis sans forcer la main du comité : un dossier honorable qui
n'est ni à écarter ni à distinguer. Sans elle, l'évaluateur devrait choisir entre deux avis
qu'il ne pense pas.

Le commentaire n'est obligatoire **que pour les deux recommandations du §11.3**. Le rendre
obligatoire partout paraîtrait plus rigoureux et le serait moins : une exigence systématique
produit des « RAS » qui n'apprennent rien, et noie les justifications qui comptent.

La recommandation **n'est pas la décision** : rien ici ne fait passer un dossier en
`SHORTLISTED`. Le §11.3 veut la short-list « générée comme proposition, puis validée par le
comité compétent » — cette étape relève du §12.

### 7. Le périmètre de lecture est réduit, sans être anonymisé

L'évaluateur ne reçoit pas les neuf sections : l'éligibilité (pièces d'identité, date de
naissance, coordonnées) et les déclarations sont retirées, ainsi que l'adresse électronique
du candidat et les coordonnées des membres de l'équipe.

Ce n'est pas de l'anonymat — la section « Structure / équipe » reste entière, et il le faut,
puisque le §11.2 fait noter l'équipe sur dix points. C'est le contrat « données sensibles
masquées selon le rôle » appliqué au cas réel : la recevabilité a déjà été tranchée au §10,
et rien dans la grille de notation ne se juge sur un numéro de téléphone.

**Les pièces jointes passent par une route de l'espace évaluateur**, pas par celle de
l'administration. Réutiliser le lien admin aurait donné des liens qui échouent
silencieusement sur la seule section dont le §11.2 fait dépendre la faisabilité technique et
le prototype. L'habilitation s'y lit sur l'affectation, pas sur le rôle.

### 8. 404 plutôt que 403, et rien d'un autre évaluateur

Un dossier qui n'est pas le sien rend **404**. Un 403 confirmerait l'existence de
l'affectation, donc qu'un dossier donné a été confié à quelqu'un — ce que l'indépendance
des notations interdit de laisser deviner. Une affectation levée est traitée comme absente :
le dossier a cessé d'être le sien, et le laisser ouvert « en lecture » donnerait accès à un
dossier qu'on ne peut plus juger.

L'écran ne montre **rien d'un autre évaluateur** : ni note, ni recommandation, ni même le
nombre de personnes affectées au même dossier. Savoir que deux collègues ont déjà verrouillé
suffirait à faire hésiter sur une note isolée.

### 9. Ce qui est journalisé, et ce qui ne l'est pas

Deux événements : l'acceptation de la charte (`EVALUATION_CHARTER_ACCEPTED`, notable) et le
verrouillage (`EVALUATION_LOCKED`, décisif — il est irréversible), qui porte le score et la
recommandation.

**Les brouillons ne sont pas journalisés.** Le journal du §13.3 sert à retrouver des
décisions ; un brouillon enregistré toutes les trente secondes n'en est pas une, et le
versement de ces écritures noierait les décisions réelles.

L'acceptation étant idempotente, un double clic n'écrit ni une seconde date ni un second
événement : c'est la première acceptation qui engage, et c'est elle qu'on produira si
l'engagement est contesté.

### 10. Le dossier ne sort de l'évaluation que sur un minimum arrêté

Un dossier passe en `EVALUATED` quand le nombre d'évaluations **verrouillées** atteint le
minimum fixé au §9.2. Tant que ce minimum n'est pas arrêté, **rien ne se passe** : le
conclure sur un minimum inventé ferait sortir le dossier de la file avec une seule notation,
alors que le comité en attendait peut-être trois. C'est la règle d'ADR-007 poussée jusqu'à
sa conséquence sur un statut visible du candidat.

### 11. La page publique annonce la vraie grille

`HomeController::criteres()` portait sa propre liste de huit intitulés — « Impact usager »,
« Sécurité », « Qualité technique », « Équipe et pitch » — qui ne correspondaient à aucun
critère du §11.2, et qui omettaient « Inclusion et ancrage territorial ». La page publique
annonçait donc aux candidats qu'ils seraient jugés sur autre chose que ce que les
évaluateurs notent.

Le défaut préexistait à cet incrément ; c'est l'écriture de `EvaluationCriterion` qui l'a
rendu visible, et qui donne le moyen de le corriger sans en créer un second : **la page lit
désormais l'enum**. Deux listes de « critères d'évaluation » dans le même dépôt ne peuvent
que diverger, et celle qui diverge est celle que lisent les candidats.

Deux conséquences de forme :

- ~~**Le poids est affiché** (« 20 pts », « 5 pts »), à la place du numéro d'ordre. Le §11.2
  est une grille publique : un candidat qui sait que la pertinence pèse vingt points et
  l'inclusion cinq n'écrit pas le même dossier, et taire la pondération en ferait une
  information réservée.~~

  > **Renversé le 31/08/2026, sur décision du porteur du concours.** La pondération n'est
  > plus publiée sur le portail : ni pastille, ni valeur dans les props Inertia. Le
  > raisonnement ci-dessus n'était pas faux, mais il tranchait un arbitrage de communication
  > qui n'appartient pas au code.
  >
  > **Le retrait est fait dans `HomeController`, pas dans le composant React.** Retirer la
  > seule pastille aurait laissé `weight` dans la charge Inertia, donc en clair dans le HTML
  > de chaque visiteur : masqué à l'œil, lisible à qui regarde la source. C'est l'illusion du
  > retrait, pire que l'affichage assumé — et c'est ce que `AccueilPublicTest` vérifie
  > désormais, sur les props et sur le HTML rendu.
  >
  > Ce qui ne change pas : les cartes lisent toujours `EvaluationCriterion`, jamais une liste
  > recopiée ; le total de 100 points reste annoncé (c'est l'échelle, pas sa répartition) ; et
  > l'invariant « la somme des poids vaut 100 » reste vérifié sur l'enum dans
  > `EspaceEvaluateurTest`, indépendamment de ce que le portail en publie. **L'espace
  > évaluateur, lui, continue d'afficher les poids** : c'est là qu'ils servent à noter.
- **Le texte de chaque carte est celui des éléments d'appréciation du cahier des charges**,
  mot pour mot, à la place des questions directrices. Les reformuler était plus engageant,
  et c'est précisément ce qui avait laissé la liste dériver.

Le test correspondant assied ses assertions sur `EvaluationCriterion`, pas sur des libellés
recopiés — recopier serait refaire la seconde liste qu'il existe pour interdire.

### 12. Une seule entrée de navigation

La maquette de l'espace évaluateur en proposait sept — tableau de bord, évaluations,
conflits signalés, ressources, profil — dont six ne correspondaient à aucun écran. La règle
posée pour `adminNav` vaut ici : une entrée sans `href` désigne un écran absent, et une
liste de six liens inertes promet un espace qui n'existe pas.

Les entrées retirées ne sont pas perdues, elles sont ailleurs : les conflits se déclarent
depuis le dossier concerné, au moment où l'on découvre le lien — un écran « conflits
signalés » séparé obligerait à s'en souvenir plus tard. L'avancement tient dans le compteur
du plan de travail, qui est la seule chose qu'un tableau de bord d'évaluateur aurait à dire.

## Conséquences

- `evaluation_assignments` porte désormais `accepted_at`. La colonne double
  `status = ACCEPTED`, et c'est voulu : le statut dit l'état, la date dit quand — et c'est la
  date qu'on produira si l'engagement est contesté.
- L'évaluation est rattachée à l'**affectation**, pas au couple (dossier, évaluateur). Une
  affectation levée emporte donc le brouillon, et une réaffectation ultérieure repart d'une
  feuille vierge : le brouillon d'une personne qui s'est récusée ne doit pas ressurgir dans
  une notation qui n'est plus la sienne.
- Le tableau d'affectation d'ADR-014 continue de ne montrer que la charge, pas les notes.
  Le §11.3 n'accorde à l'administration que l'avancement avant le verrouillage, et **aucune
  route ne permet à un administrateur d'écrire une note** — c'est la forme exécutable de
  « le gestionnaire voit l'avancement mais pas une modification silencieuse des notes ».

## Ce qui reste ouvert

- ~~**La revue d'écart du §11.3.**~~ — faite, ADR-016 : l'écart se mesure critère par
  critère, et l'arbitrage n'autorise jamais la retouche d'une note.
- **La règle d'agrégation** — moyenne, médiane ou note de consensus. Le §11.3 demande
  qu'elle soit « choisie et documentée avant l'ouverture » ; l'inventer ici aurait produit un
  classement fondé sur une règle que personne n'a arrêtée.
- **La short-list du §11.3** (3 à 4 dossiers par thématique, générée comme proposition), et
  toute la sélection finale du §12.
- **Les notifications d'affectation** (§8.3, « Affectation — Évaluateur — Email ») : aucun
  envoi n'existe encore, donc un évaluateur ne sait qu'un dossier lui est confié qu'en
  ouvrant son plan de travail.
- **Les collèges et jurys par thématique** (§11.1) : l'affectation est aujourd'hui
  nominative, sans regroupement.
- **L'écran d'aide de l'évaluateur** (« Ressources » de la maquette), qui suppose le CMS.
