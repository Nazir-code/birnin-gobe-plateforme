# ADR-023 — Les critères annoncés au portail ne sont plus ceux de la grille

*Statut : accepté. Portée : §11.2 (grille d'évaluation), portail public.
Renverse une décision d'ADR-015, sur arbitrage du porteur du concours.*

## Contexte

Le portail public annonce huit critères d'évaluation. Jusqu'ici, il lisait
`EvaluationCriterion` — les huit critères du §11.2, ceux que les évaluateurs notent
réellement.

Le porteur du concours a demandé une autre liste, celle de la maquette :

| Portail (demandé) | Grille du §11.2 (notée) |
|---|---|
| Pertinence | Pertinence par rapport au défi |
| Impact usager | Impact et résilience |
| Faisabilité | Faisabilité technique |
| **Qualité technique** | — |
| Innovation utile | Innovation |
| **Sécurité** | — |
| Durabilité | Durabilité et mise à l'échelle |
| Équipe et pitch | Équipe |
| — | **Viabilité économique et institutionnelle** |
| — | **Inclusion et ancrage territorial** |

Quatre entrées sur huit divergent. La demande a été formulée deux fois ; la première
fois, la conséquence a été exposée et une autre option retenue — garder le fond du
§11.2 et n'adopter que la forme. La seconde fois, la liste complète a été fournie,
questions comprises. **C'est une décision, pas un malentendu**, et elle est appliquée
telle quelle.

## Ce que cette décision coûte, énoncé une fois

Un candidat lit qu'il sera jugé sur la **sécurité** et la **qualité technique**. Aucun
évaluateur ne note ces deux points : ils ne figurent dans aucune ligne de la grille.

Réciproquement, personne ne lui dit que l'**inclusion et l'ancrage territorial** pèsent
5 points de sa note, ni que la **viabilité économique et institutionnelle** en pèse 10.
Il ne saura donc pas qu'il doit les traiter.

Le §11.2 est une grille publique, et l'écart entre ce qui est annoncé et ce qui est noté
est le genre de chose qui se conteste après coup. Ce n'est pas une objection technique —
c'est le risque que porte la décision, et il appartient au porteur.

## Décisions

### 1. Deux listes, deux fichiers, deux rôles

`App\Domain\Content\PortalCriterion` porte le texte annoncé au public.
`App\Domain\Evaluation\EvaluationCriterion` porte la grille et ses poids, **inchangée**.

Modifier l'enum d'évaluation aurait changé ce que les évaluateurs notent — ce qui n'a pas
été demandé, et qui aurait invalidé les six évaluations en cours de campagne. Le portail
parle ; la grille note.

### 2. La leçon d'ADR-015 est conservée, mais reformulée

ADR-015 avait supprimé une liste de portail distincte, au motif que « deux listes de
critères d'évaluation dans le même dépôt ne peuvent que diverger ». C'était vrai : elles
avaient divergé, et personne ne l'avait vu.

**Le problème n'était pas la seconde liste — c'était son invisibilité.** Elle vivait dans
`HomeController`, mêlée à du code de présentation, sans rien qui la désigne comme une
liste concurrente. Rien n'obligeait les deux à rester d'accord, et rien ne signalait
qu'elles ne l'étaient plus.

Ce qui change ici :

- la liste est **nommée pour ce qu'elle est** — `PortalCriterion`, dans `Domain\Content` ;
- elle vit dans **son propre fichier**, pas au milieu d'un contrôleur ;
- son docblock dit en première ligne qu'elle n'est pas la grille de notation ;
- et un test **mesure l'écart** au lieu de l'interdire.

### 3. Le test affiche l'écart plutôt que de le refuser

`test_l_ecart_entre_le_portail_et_la_grille_est_connu` vérifie nommément :

- que « Qualité technique » et « Sécurité » sont annoncés et non notés ;
- que « Viabilité économique et institutionnelle » et « Inclusion et ancrage territorial »
  sont notés et non annoncés ;
- que les deux listes comptent bien huit entrées, ce que la page affirme.

**Ce test échouera le jour où le comité alignera les deux listes** — et ce sera le signal
que `PortalCriterion` peut disparaître. Un test qui se périme au bon moment vaut mieux
qu'un commentaire que personne ne relit.

Le garde-fou précédent, qui interdisait le retour de ces intitulés, est supprimé : il
interdisait exactement ce qui est désormais voulu.

### 4. La mention des 100 points est retirée

Le paragraphe d'introduction annonçait « sur un total de 100 points ». Ce total est celui
de la grille, pas de la liste affichée : les critères du portail n'ont aucune pondération,
et l'invariant « la somme vaut 100 » porte sur `EvaluationCriterion`.

Le conserver aurait laissé croire que les huit critères affichés se répartissent ces cent
points — ce qui est faux pour quatre d'entre eux.

La pondération reste absente du portail, conformément à la décision antérieure : ni
pastille, ni valeur dans les props Inertia.

## Conséquences

- Le portail et l'espace évaluateur affichent désormais des listes différentes. C'est
  visible, testé, et daté.
- `HomeController` ne lit plus `EvaluationCriterion`.
- Le scénario E2E de l'accueil vérifie les nouveaux intitulés.

## Ce qui reste ouvert

- **L'alignement des deux listes.** C'est une décision du comité, pas un développement :
  soit le portail revient au §11.2, soit la grille adopte les critères annoncés — et ce
  second choix supposerait de redéfinir huit poids et de reprendre ADR-015.
- **Les six évaluations déjà verrouillées** l'ont été sur la grille du §11.2. Si la grille
  changeait un jour, elles deviendraient incomparables avec les suivantes.
- **Ce que le candidat ne sait pas.** Rien ne lui indique aujourd'hui que l'inclusion
  territoriale et la viabilité comptent dans sa note. Le règlement du concours, s'il est
  publié ailleurs, reste la seule source qui le lui dise.
