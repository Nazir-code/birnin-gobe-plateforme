# ADR-010 — Administration des critères d'éligibilité par campagne

**Statut :** accepté
**Date :** 2026-08-23

## Contexte

ADR-007 a posé le moteur d'éligibilité et sa règle cardinale : **un critère que
la campagne n'a pas configuré ne conclut pas**. `CampaignEligibilityRules` lit
`campaigns.settings.eligibility`, `EvaluateEligibility` en tire cinq verdicts de
règle, et tout paramètre absent produit `NOT_CONFIGURED`, donc un résultat
« sous réserve » pour le candidat.

Il manquait la moitié écriture. Les critères n'étaient modifiables que par une
requête SQL ou un `forceFill` de test — la suite d'intégration le notait
explicitement : *« l'écriture de `settings.eligibility` se fait ici directement
en base : l'écran d'administration ne les expose pas encore »*.

Conséquence pratique : aucune campagne ne pouvait publier ses critères, donc
**aucun candidat ne pouvait être déclaré éligible**. Le moteur fonctionnait, et
répondait « sous réserve » à tout le monde. Le cahier des charges §9.2 range
pourtant ces paramètres parmi les réglages administrables sans code : « Âge et
date de référence, nationalité/résidence, zones, types de candidats, taille
d'équipe ».

## Décision

### Un écran distinct de celui de la campagne

`/admin/campaigns/{campaign}/eligibility`, et non un bloc supplémentaire dans le
formulaire de campagne.

Les deux écrans écrivent la même ligne, mais pas au même moment ni sous la même
responsabilité : le nom, le code et les dates existent dès la création, tandis
que les critères sont arbitrés par le comité de pilotage et republiés
séparément. Les fondre en un seul formulaire ferait de chaque correction de
libellé une occasion de republier des critères — et l'inverse. Le formulaire de
campagne porte désormais un lien vers l'écran, visible en modification
seulement : les critères se fixent sur une campagne qui existe.

### Le vide n'est jamais écrit

Le corollaire d'ADR-007 côté écriture. Un champ laissé vide n'est enregistré ni
à `null`, ni à `0`, ni à `[]` : **sa clé est absente de `settings`**.

Pour le moteur d'aujourd'hui, `null` et l'absence produiraient le même
`NOT_CONFIGURED`. La distinction est faite pour le lecteur : un `"age": {"min":
null}` en base laisse croire que quelqu'un s'est prononcé. `EligibilitySettings`
porte cette normalisation, et un test vérifie qu'un formulaire entièrement vide
ne laisse aucune trace dans `settings`.

### Le lien avec le Niger a trois états, pas deux

C'est le point le plus facile à casser par simplification, d'où un champ à trois
valeurs explicites plutôt qu'une case à cocher :

| État | Stocké | Ce que le candidat lit |
|---|---|---|
| Non renseigné | clé absente | « Les conditions de nationalité et de résidence ne sont pas encore publiées. Votre résultat reste indicatif. » |
| Aucune condition | `false` | « Cette campagne n'impose aucune condition de nationalité ou de résidence. » |
| Condition exigée | `true` | Nationalité **ou** résidence suffit ; à défaut, règle bloquante. |

`false` est une décision du comité — elle rassure le candidat. L'absence est un
silence — elle le laisse sous réserve. Une case à cocher confondrait les deux,
et le ferait silencieusement.

Même logique pour les listes : « aucune zone cochée » signifie *liste non
publiée*, et se distingue de « les huit régions cochées », qui est la décision
d'ouvrir tout le pays.

### Les autres clés de `settings` survivent

`SaveEligibilitySettings` remplace le sous-tableau `eligibility`, jamais
`settings`. Le §9.2 prévoit d'y loger compte à rebours, période de grâce,
contacts et textes légaux : ces écrans n'existent pas encore, mais rien ne
garantit qu'ils seront écrits après celui-ci. Une phase qui n'expose pas une clé
n'a pas à l'effacer. Symétriquement, quand le bloc devient vide, sa clé est
retirée plutôt qu'enregistrée comme objet vide.

### Validation serveur

Ce qui entre dans `settings` décide du verdict de tous les candidats de la
campagne : la validation ne peut pas être une politesse d'interface.

- bornes d'âge entières, `0..120`, maximum ≥ minimum ;
- date de référence au format `Y-m-d` — et **refusée seule**, sans borne d'âge :
  le moteur ne calcule un âge que s'il a une borne à lui opposer, et l'accepter
  laisserait croire que le critère est publié ;
- lien avec le Niger strictement dans les trois états — une quatrième valeur est
  refusée, pas ramenée silencieusement à l'absence ;
- régions et types validés contre les référentiels serveur (`NigerRegion`,
  `CandidateType`), sans doublon ;
- effectifs entiers `1..1000`, maximum ≥ minimum.

Une saisie refusée n'écrit rien — pas même la partie valide du formulaire.

### Audit : les changements, pas les enregistrements

`CAMPAIGN_ELIGIBILITY_UPDATED`, avec l'ancien et le nouveau bloc. C'est ce qui
permet de répondre plus tard à « selon quels critères ce dossier a-t-il été jugé,
et depuis quand ? ».

Un enregistrement qui ne modifie aucun critère **n'écrit rien**, ni en base ni au
journal. Ce n'est pas une décision, et des lignes sans contenu noieraient celles
qui en ont.

### Republier change le verdict des dossiers en cours

Assumé, et testé. Le verdict n'est pas persisté (ADR-007) : il est recalculé à
chaque lecture depuis les réponses et les critères **de la campagne du dossier**.
Modifier les critères après l'ouverture change donc ce que lisent les candidats
déjà engagés — y compris en refermant la suite du formulaire pour un dossier
devenu inéligible.

C'est la contrepartie voulue d'un résultat dérivé : le jour où le comité fixe
enfin la tranche d'âge, les dossiers déjà commencés en tiennent compte au lieu
de rester sur un verdict figé et faux. L'écran l'annonce à l'administrateur.

## Ce qui n'est délibérément pas fait

| Sujet | Raison |
|---|---|
| **Motifs d'exclusion** (§9.2) | Le cahier les annonce paramétrables sans en énoncer aucun. `EligibilityRule` ne les porte pas : une règle sans contenu ne s'implémente pas, elle s'attend. |
| **Pièces justificatives par campagne** (§9.2) | Relève du domaine `Storage` et de l'étape « pièces », pas de l'auto-test. |
| **Valeurs par défaut à la création d'une campagne** | Ce serait publier des critères au nom du comité de pilotage. Une campagne naît sans aucun critère, et l'écran des campagnes le signale. |
| **Historique des versions de critères** | Le journal d'audit conserve l'ancien et le nouveau bloc à chaque changement. Une table dédiée attendra un écran qui la lise. |
| **Autres paramètres du §9.2** (compte à rebours, période de grâce, contacts, textes légaux) | Toujours sans consommateur — même raison qu'en ADR-008. Ils vivront dans `settings`, que cet écran préserve. |
| **Espace candidat** | Non touché. L'écran candidat lit déjà les critères par le moteur ; il n'avait rien à changer, et c'est le signe que la séparation lecture/écriture tient. |

## Vérification

- `tests/Feature/AdministrationEligibiliteTest.php` — accès et refus, écriture
  des cinq critères, absence d'écriture pour les critères vides, trois états du
  lien avec le Niger, préservation des autres clés de `settings`, isolation
  entre campagnes, validation serveur, audit (et absence d'audit sans
  changement), puis le parcours administration → candidat pour les trois
  verdicts `ELIGIBLE`, `INELIGIBLE` et `TO_CONFIRM`.
- `tests/E2E/admin-eligibilite.spec.ts` — le même parcours dans un vrai
  navigateur : l'administrateur publie par l'écran, des candidats répondent, et
  le verdict lu vient du serveur. La campagne active y étant écrite, la suite E2E
  passe à un seul worker et l'état de départ est restauré en fin de fichier.
