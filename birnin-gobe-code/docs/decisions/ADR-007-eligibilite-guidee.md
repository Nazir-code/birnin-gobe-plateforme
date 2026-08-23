# ADR-007 — Éligibilité guidée : règles côté serveur, seuils côté campagne

Statut : accepté — Phase 1D
Contexte : fait suite à ADR-005 (persistance des candidatures).

## Contexte

L'étape 1 du formulaire est l'auto-test d'éligibilité. Le cahier des charges la
décrit précisément (§5.2) : « questions courtes; résultat indicatif; explication
en cas de risque d'inéligibilité; possibilité de poursuivre tant qu'aucune règle
bloquante n'est validée ».

Trois questions structurantes se posaient : quelles questions poser, qui décide
du verdict, et où vivent les seuils.

## Décisions

### 1. Les questions viennent des sources, pas de l'intuition

Les six questions sont celles que le cahier des charges énumère :

| Question | Source |
|---|---|
| Date de naissance | §6.1 « date et lieu de naissance », §9.2 « Âge et date de référence » |
| Nationalité nigérienne | §4.1 et §9.2 « nationalité/résidence » |
| Résidence au Niger | idem |
| Région d'intervention | §4.1 « zones », §6.1 « zone d'intervention » |
| Forme de candidature | §4.1 « cas individu/équipe/startup » |
| Effectif de l'équipe | §9.2 « taille d'équipe » |

Deux axes du §9.2 sont volontairement absents. Les **pièces** sont l'étape 8 et
supposent le stockage de fichiers, hors périmètre. Les **motifs d'exclusion**
sont annoncés comme paramétrables sans qu'aucun ne soit énoncé : une règle sans
contenu ne s'implémente pas, elle s'attend.

### 2. Aucun seuil n'est codé en dur

Le cahier des charges est explicite à deux endroits : « la tranche d'âge […]
reste configurable par campagne » (§1.1), et le comité de pilotage « doit
arrêter […] tranche d'âge et date de référence; zones et quotas; règles
d'éligibilité » (§18.3). Ces valeurs **n'existent pas encore**.

Écrire `18` et `35` dans le code aurait produit une plateforme qui a l'air de
fonctionner tout en appliquant un critère que personne n'a décidé. La règle
d'âge a donc un troisième état, à côté de « satisfaite » et « bloquante » :

    NOT_CONFIGURED — « La tranche d'âge de cette campagne n'est pas encore
    publiée : cette condition sera vérifiée lors du contrôle administratif. »

Le candidat poursuit. Le jour où l'administration renseigne la tranche, la règle
conclut — sans redéploiement, et sans réécrire les réponses déjà données.

Une seule condition est active par défaut : le **lien avec le Niger**. Elle ne
repose pas sur un seuil mais sur la nature du programme — PIDUREM — Galey Ma
Zaada mobilise « l'intelligence créative nigérienne » (§1) et la plateforme est
le point d'entrée *national* de la compétition. La nationalité **ou** la
résidence suffit : exiger les deux exclurait la diaspora comme les résidents
étrangers, ce qu'aucune source ne demande. Une campagne peut lever la condition.

Le minimum de deux personnes pour une candidature collective n'est pas non plus
un seuil : c'est ce qui distingue une équipe d'une candidature individuelle.

### 3. Le verdict est calculé, jamais stocké

    Réponses (jsonb)  +  Paramètres de campagne  →  EvaluateEligibility  →  verdict

Le navigateur n'envoie que des réponses. Un `eligible: true` glissé dans la
charge utile est ignoré par la `FormRequest`, qui ne conserve que les champs
déclarés — un test le vérifie explicitement.

Le verdict n'est pas persisté. Ce n'est pas un oubli : les paramètres de
campagne peuvent changer après coup, et un verdict figé deviendrait faux ce
jour-là sans que rien ne le signale. Le recalculer à chaque lecture le rend
reproductible par construction, ce qu'un test vérifie en modifiant les zones
d'une campagne et en constatant que le verdict bascule sans qu'aucune réponse
n'ait bougé.

### 4. Éligibilité ≠ admissibilité

Le résultat est **indicatif**, comme l'annonçait déjà le dictionnaire d'interface
(`fr.eligibility.warning`). L'admissibilité est une décision administrative,
humaine, prise plus tard sur pièces par un vérificateur (§10.2). `EligibilityOutcome`
n'est donc jamais écrit dans `ApplicationStatus`, et la candidature reste `DRAFT`
quel que soit le verdict.

Quatre résultats, dans cet ordre de priorité :

| Résultat | Condition | Suite du formulaire |
|---|---|---|
| `INELIGIBLE` | au moins une règle bloquante | fermée |
| `INCOMPLETE` | des réponses manquent | ouverte |
| `TO_CONFIRM` | un paramètre de campagne manque | ouverte |
| `ELIGIBLE` | tout est satisfait | ouverte |

Une règle bloquante l'emporte sur des réponses manquantes : dès qu'on sait
qu'une condition n'est pas remplie, le dire tout de suite vaut mieux que de
laisser remplir huit sections pour l'apprendre après. C'est l'« explication en
cas de risque d'inéligibilité » du §5.2.

### 5. Cas non éligible : rien n'est perdu, la porte se referme

- Les réponses restent en base, intactes et modifiables.
- La candidature n'est ni supprimée, ni changée de statut.
- Les sections postérieures redirigent vers l'éligibilité — pas un 403 : le
  candidat n'est pas un intrus, il a répondu quelque chose qui ferme la porte et
  doit pouvoir corriger. Une sauvegarde automatique sur une section fermée reçoit
  un 403 JSON, avec le motif.
- Corriger une réponse rouvre la suite immédiatement.

La barrière est portée par le middleware `eligible`, **déclaré sur la route**
comme `can:` et `role:` — une section ajoutée sans cette déclaration se voit à la
relecture, un `if` oublié au fond d'un contrôleur non.

### 6. Ce qui a été factorisé, et ce qui ne l'a pas été

ADR-005 annonçait que la généralisation du contrôleur de section viendrait « avec
la deuxième section, quand la forme commune sera connue plutôt que devinée ». La
deuxième section est là ; voici la comparaison réelle.

**Factorisé** — c'était de la duplication littérale, côté React :

| Extrait | Ce qui se répétait |
|---|---|
| `Components/SaveIndicator.tsx` | mêmes libellés, même rôle ARIA, même code d'icône |
| `Components/SectionStepsAside.tsx` | colonne des neuf étapes, identique au pixel |

**Non factorisé** — les deux contrôleurs de section. Ce qu'ils partagent tient
en quatre lignes d'ossature HTTP (`Inertia::render`, l'aiguillage JSON/Inertia).
Tout le reste diffère : champs, règles de validation, typage des réponses, props
transmises, et pour l'éligibilité un verdict à calculer que « Défi » n'a pas. Une
fabrique paramétrée par section devrait accepter des paramètres pour chacune de
ces différences — elle remplacerait quatre lignes répétées par une indirection
qui rend chaque section plus difficile à lire isolément.

La décision est donc de **ne pas** généraliser maintenant. Le vrai signal sera
une troisième section dont la validation et les props se rangent naturellement
dans la même forme — pas le simple fait qu'il y en ait plusieurs.

## Contrat avec l'administration des campagnes

Les paramètres sont **lus** dans `campaigns.settings`, colonne `jsonb` existante.
Aucun fichier du domaine Campagne n'est modifié par cette phase : ni le modèle,
ni la migration, ni la factory, ni le semis. L'écriture de ces paramètres relève
de l'écran d'administration des campagnes (§9.2 « paramètres administrables sans
code »).

Forme attendue, toutes les clés étant facultatives :

```json
{
  "eligibility": {
    "age":             { "min": 18, "max": 35, "reference_date": "2026-11-20" },
    "requires_niger_link": true,
    "regions":         ["NE-8", "NE-4"],
    "candidate_types": ["INDIVIDUAL", "TEAM", "STARTUP"],
    "team_size":       { "min": 2, "max": 10 }
  }
}
```

Une clé absente ne vaut jamais « refusé » : elle vaut « non paramétré ». À défaut
de `reference_date`, l'âge se calcule à la clôture de la campagne — la seule date
qui ne dépende pas du jour où le candidat consulte l'écran.

## Conséquences

- L'ordre métier prime sur l'ordre de développement : un nouveau brouillon
  s'ouvre sur `eligibility` (étape 1) et non plus sur `challenge` (étape 4).
  Les brouillons antérieurs pointant sur `challenge` restent valides — la
  section est toujours ouverte — et ne subissent aucune migration de données.
- Deux sections sur neuf sont persistées : la progression plafonne à 22 %.
- Sans paramètres de campagne, un dossier complet et cohérent aboutit à
  `TO_CONFIRM` et non à `ELIGIBLE`. C'est l'état réel du projet, pas une
  limitation technique : la tranche d'âge n'a pas encore été arrêtée.
