# Blueprint — fondation UI & architecture

## Architecture cible

- Monolithe modulaire Laravel.
- React + TypeScript + Inertia pour le portail public et candidat.
- Filament pour les écrans administratifs standards ; écrans dédiés pour les workflows riches.
- Tailwind CSS et design tokens centralisés.
- PostgreSQL pour les données relationnelles.
- Redis pour cache / queues / workers.
- Stockage objet S3 compatible pour les pièces.
- Caddy comme reverse proxy.
- Docker Compose pour développement, recette et production initiale.

## Frontières métier préparées

`Auth`, `Campaign`, `Candidate`, `Application`, `Eligibility`, `Evaluation`, `Jury`, `Notification`, `Reporting`, `Audit`, `Storage`, `Content`, `Administration`.

## Contrats UX non négociables

- Mobile-first à partir de 320 px.
- États de sauvegarde explicites.
- Autosave sans perte de saisie.
- Navigation avant/arrière sans perte.
- Formulaire en lecture seule après soumission.
- Focus visible / navigation clavier / contrastes AA.
- Aucun statut métier stocké sous forme de libellé français.
- Données sensibles masquées selon rôle et ressource.
