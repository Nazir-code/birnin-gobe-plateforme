# BIRNIN GOBE — implémentation UI/UX + fondation technique

Première traduction en code des maquettes BIRNIN GOBE, en gardant la présentation comme **référence visuelle** et le cahier des charges / document de passation comme **référence fonctionnelle et technique**.

## Écrans déjà codés

- Accueil public.
- Dashboard candidat.
- Formulaire multi-étapes — étape 4 « Défi ».
- Responsive/mobile-first sur ces écrans.
- Dashboard back-office administratif.
- Interface évaluateur / notation.

Voir `docs/design/SCREEN_MAP.md`.

## Stack

- Laravel 13 (monolithe modulaire)
- React 19 + TypeScript
- Inertia 3
- Filament 5 pour l'administration standard
- Tailwind CSS 4
- PostgreSQL
- Redis / workers
- S3 compatible (MinIO en local)
- ClamAV
- Caddy
- Docker Compose
- Playwright prévu pour E2E

## Principe important

Les dates, statistiques, noms et compteurs visibles dans les maquettes sont des **données de démonstration**. Ils ne deviennent pas des règles codées en dur. La configuration de campagne doit être stockée côté backend/CMS.

## Démarrage prévu

```bash
cp .env.example .env
# remplacer les secrets de développement

docker compose build
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose up -d
```

Application : `http://localhost:8080`

> Le dépôt livré ici est un **starter d'implémentation** : les pages UI sont codées et l'ossature d'architecture est posée. L'authentification réelle, les policies RBAC, le CMS, Filament, l'autosave persistant, les uploads S3/antivirus et les workflows métier complets doivent être branchés dans les prochains incréments verticaux.

## Routes

- `/`
- `/candidate/dashboard` — données de candidature lues en base (ADR-005)
- `/candidate/application` — redirige vers la section en cours du dossier
- `/candidate/application/{application}/eligibility` — étape 1 « Éligibilité », persistée (ADR-007)
- `/candidate/application/{application}/challenge` — étape 4 « Défi », persistée
- `/admin/dashboard`
- `/admin/campaigns` — administration des éditions (ADR-008)
- `/admin/campaigns/{campaign}/eligibility` — critères d'éligibilité de l'édition (ADR-009)
- `/evaluator/assignments`

## Sécurité / métier déjà préparés

- Enum de statuts stable (`ApplicationStatus`).
- Machine à états explicite.
- Use case `SubmitApplication` pour éviter un simple changement arbitraire de statut.
- Structure d'audit centralisée.
- Migrations initiales `campaigns`, `applications`, `attachments`, `audit_events`.
- Candidature persistante rattachée au candidat : `ApplicationPolicy`, unicité
  candidat/campagne, sauvegarde automatique réelle et `application_sections`
  (ADR-005).
- Infrastructure PostgreSQL / Redis / S3-compatible / ClamAV / Caddy.

## Déploiement / Niger Télécom

La passation technique destinée au développeur senior — état réel audité du
dépôt, stack confirmée, cartographie Docker, checklist d'audit serveur et
matrice d'évolution — se trouve dans
[`docs/deployment/NIGER_TELECOM_HANDOFF.md`](docs/deployment/NIGER_TELECOM_HANDOFF.md).

Les commandes de déploiement, de test, de redéploiement et de retour arrière
sont dans [`docs/deployment/NIGER_TELECOM_RUNBOOK.md`](docs/deployment/NIGER_TELECOM_RUNBOOK.md).

## Références visuelles

Les captures de la présentation sont conservées dans `docs/design/reference/` uniquement comme références de comparaison ; les écrans sont reconstruits en composants React/CSS et ne sont pas rendus comme une grande image.
