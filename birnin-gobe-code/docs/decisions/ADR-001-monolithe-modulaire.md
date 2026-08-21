# ADR-001 — Monolithe modulaire

**Décision :** construire BIRNIN GOBE comme un seul déployable Laravel, avec frontières internes par capacité métier.

**Pourquoi :** l'équipe cible est petite et les exigences ne justifient pas la charge opérationnelle des microservices au démarrage.

**Conséquence :** les modules partagent le déploiement et PostgreSQL, mais les règles métier ne doivent pas être dispersées entre contrôleurs.
