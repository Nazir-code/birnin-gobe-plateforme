# ADR-013 — Contrôle d'admissibilité : grille cochée, décision versionnée

*Statut : accepté. Portée : §10 du cahier des charges (contrôle d'admissibilité et traitement administratif).*

## Contexte

Après le dépôt (ADR-005, `SubmitApplication`), un dossier doit être déclaré recevable
ou non avant d'entrer en évaluation. Le §10 décrit trois choses : un écran unique par
dossier (§10.1), une matrice minimale de sept contrôles (§10.2), et un jeu de statuts
avec des exigences de motivation (§10.3).

C'est le premier endroit où l'administration **écrit** sur une candidature. Jusqu'ici,
`ApplicationController` était en lecture seule et le disait explicitement : « tant que le
dossier n'est pas soumis, le candidat en reste propriétaire ». Cette règle ne change pas ;
elle se complète. Après le dépôt, l'admissibilité appartient à l'administration — mais
le **contenu** du dossier continue d'appartenir au candidat.

## Décisions

### 1. Le contrôle écrit à côté du dossier, jamais dedans

Aucune route de `VerificationController` ne réécrit une réponse ni une pièce. Le contrôle
ajoute deux choses : des lignes dans `verification_checks` et `verification_decisions`, et
un changement de `applications.status`. Un test le vérifie explicitement
(`test_le_controle_ne_touche_pas_aux_reponses_du_candidat`).

Conséquence pratique : la relecture du candidat (étape 9) et l'écran du vérificateur
rendent le **même** dossier, par le même présentateur (`AdminApplicationPresenter::detail`).
Deux mises en forme auraient fini par diverger, et la divergence aurait porté sur la pièce
même qui fonde la décision.

### 2. Deux tables, parce que la grille et la décision n'ont pas la même durée de vie

`verification_checks` porte **l'état courant** : une ligne par contrôle, mise à jour sur
place tant qu'aucune décision n'est prise. Un vérificateur qui se reprend corrige sa coche.

`verification_decisions` est **en ajout seul** : pas d'`updated_at`, aucune route de mise à
jour ni de suppression. Le §10.3 exige qu'une modification ultérieure « crée une nouvelle
version, identifie l'auteur ». Réécrire une décision ferait disparaître la version qu'on est
censé pouvoir comparer.

Option écartée : un `jsonb` sur `applications`. La file filtre sur l'avancement de la grille,
et l'historique se compte ; un document JSON aurait rendu chaque coche dépendante d'une
réécriture de la ligne du dossier.

### 3. Le motif de rejet est un contrôle, pas un second référentiel

Le §10.3 veut un « motif principal » codifié. Le motif naturel d'un rejet est le contrôle du
§10.2 qui a bloqué : `verification_decisions.primary_reason` stocke donc une valeur de
`VerificationControl`. Créer une liste de motifs à côté de la grille aurait produit deux
référentiels qui divergent dès la première campagne, et des rapports (§13) impossibles à
agréger.

### 4. La garantie du §10.3 est rendue exécutable, pas seulement documentée

> « Un signalement automatique — doublon, incohérence ou document suspect — n'entraîne jamais
> à lui seul l'exclusion d'un candidat. »

Trois mécanismes tiennent cette phrase :

- `VerificationSeverity` distingue `ATTENTION` de `BLOCKING`. « Doublon probable » et
  « alerte » d'intégrité sont en `ATTENTION` ; seuls « doublon confirmé » et « exclusion »
  sont bloquants, et ce sont des constatations humaines ;
- `DecideAdmissibility` refuse un rejet dont le motif ne désigne pas un contrôle portant un
  verdict `BLOCKING` ;
- `AutomaticFindings` ne rend que des `AutomaticFinding`, un type sans verdict, que rien
  n'écrit dans `verification_checks`. L'écran ne pré-coche donc rien : un défaut pré-rempli
  serait accepté tel quel neuf fois sur dix.

Le test `test_un_signalement_seul_ne_peut_pas_fonder_une_exclusion` est le test central de
la suite : il porte sur une phrase qui protège des candidats.

### 5. Les signalements automatiques ne couvrent que ce qu'on sait réellement voir

Sont calculés : dépôt hors délai (comparé à la clôture de la campagne **du dossier**), règles
d'éligibilité bloquantes, sections inachevées, pièces exigées absentes, absence d'analyse
antivirus, et doublons exacts (même compte, même téléphone, sur la même campagne).

Ne sont **pas** calculés, et c'est délibéré : la similarité de contenu (elle demanderait un
index trigramme et un seuil que personne n'a arbitré), le plagiat et la fraude documentaire.
Le contrôle « Intégrité » ne reçoit donc aucun signalement automatique. Une ligne « aucune
anomalie détectée » sur un contrôle que personne n'exerce inviterait à cocher « aucune
alerte » sur la foi d'une analyse qui n'a pas eu lieu.

### 6. Cocher la grille est la prise en charge

Un dossier `SUBMITTED` sur lequel quelqu'un coche passe à `PENDING_REVIEW` — le « en
contrôle » du §10.3. C'est le premier geste de contrôle qui marque la prise en charge, et
non l'ouverture de l'écran : consulter n'est pas travailler, et un statut qui bougerait à la
lecture ferait apparaître comme « en contrôle » tout dossier qu'un gestionnaire a entrouvert.

Corollaire, cohérent avec le reste de l'administration : **la consultation n'est pas
journalisée**.

### 7. Deux statuts du §10.3 sont volontairement absents

`AdmissibilityDecision` ne propose que `ADMISSIBLE`, `CLARIFICATION` et `INADMISSIBLE`.

- **« En arbitrage »** suppose la « seconde validation » du §10.1, donc un second rôle
  habilité et une file qui lui soit propre. Tant que ce rôle n'existe pas, une décision
  « mettre en arbitrage » laisserait des dossiers dans un statut que personne ne peut lever.
- **« Retiré »** appartient au candidat, et il n'a pas d'écran pour le faire.

Les inventer maintenant reviendrait à arbitrer par le code deux questions que le cahier des
charges laisse à l'organisation.

Dans le même esprit, la transition `CLARIFICATION_REQUESTED → CLARIFICATION_RECEIVED` existe
déjà dans `ApplicationStateMachine` mais **aucun écran ne la déclenche** : c'est un geste du
candidat, et le parcours de réponse à une clarification n'est pas dans cet incrément.

### 8. La notification du candidat n'est pas dans la transaction

Comme dans `SubmitApplication`, l'envoi du message au candidat appartient à la file d'attente,
après le `commit`. Envoyer un refus depuis la transaction enverrait un courriel pour une
décision qui peut encore être annulée. **Cet incrément n'envoie rien** : le message est
conservé dans `verification_decisions.candidate_message`, prêt pour le module de notification
(§8.3).

## Conséquences

- Le vérificateur ne peut pas décider sans avoir renseigné les sept contrôles. C'est une
  contrainte assumée : le §10.2 s'intitule « matrice **minimale** ».
- Un dossier décidé fige sa grille. La corriger supposerait une nouvelle version de décision,
  qui est le mécanisme prévu par le §10.3 mais qu'aucun écran n'ouvre encore.
- La file par défaut est FIFO. Un tri antichronologique enterrerait au fond de la file
  exactement les dossiers qui attendent depuis le plus longtemps.
- `actor_id` reste sans clé étrangère, comme dans `audit_events` : supprimer un compte interne
  n'efface pas la trace de ce qu'il a contrôlé ou décidé.

## Ce qui reste ouvert

- La seconde validation des rejets (§10.1) et le statut « en arbitrage ».
- Le parcours candidat de réponse à une clarification.
- La notification effective (§8.3).
- Les actions en lot du §9.3 (affecter, notifier, exporter en masse).
- Le gel par version de la liste des admissibles (§10.3), qui suppose un objet « liste » que
  cet incrément n'introduit pas.
