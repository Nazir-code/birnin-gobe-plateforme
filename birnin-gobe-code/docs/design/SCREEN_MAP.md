# BIRNIN GOBE — correspondance maquettes → code

| Maquette | Route | Implémentation |
|---|---|---|
| Accueil public | `/` | `resources/js/Pages/Public/Home.tsx` |
| Tableau de bord candidat | `/candidate/dashboard` | `resources/js/Pages/Candidate/Dashboard.tsx` |
| Formulaire — Étape 4 Défi | `/candidate/application/challenge` | `resources/js/Pages/Candidate/Application/Challenge.tsx` |
| Mobile-first | mêmes routes, responsive | layouts + breakpoints Tailwind |
| Back-office administratif | `/admin/dashboard` | `resources/js/Pages/Admin/Dashboard.tsx` |
| Évaluation / jury | `/evaluator/assignments` | `resources/js/Pages/Evaluator/Assignments.tsx` |

## Règle de fidélité

1. La présentation est la référence visuelle : densité, hiérarchie, palette vert/or/crème, cartes, rayons, navigation, stepper, placement des actions.
2. Le cahier des charges et l'analyse technique restent la référence fonctionnelle et de sécurité.
3. Les valeurs de démonstration des maquettes (dates, compteurs, noms, scores) ne sont pas codées comme règles métier : elles doivent venir de `Campaign`, du CMS ou des données applicatives.
4. Les incohérences d'une image générée (ex. mauvaise géographie/année/libellé) ne doivent pas être reproduites si elles contredisent BIRNIN GOBE / PIDUREM / ANSI.
5. Les textes sensibles haoussa/zarma ne sont pas inventés : fallback français tant qu'ils ne sont pas validés.
