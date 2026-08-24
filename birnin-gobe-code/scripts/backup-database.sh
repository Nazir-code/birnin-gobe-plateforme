#!/usr/bin/env bash
#
# Sauvegarde de la base PostgreSQL de BIRNI'NGOBE.
#
#   ./scripts/backup-database.sh [destination]
#
# Sans argument, écrit dans `storage/backups/`. Avec un argument, dans le
# dossier indiqué — un volume ou un point de montage distinct du serveur est
# préférable : une sauvegarde qui vit sur la machine qu'elle protège ne protège
# de rien.
#
# Le format retenu est le format « custom » de PostgreSQL, et non du SQL brut :
# il est compressé, et `pg_restore` peut en restaurer une table seule.
#
# Ce script ne modifie rien. Il lit la base et écrit un fichier.

set -euo pipefail

racine="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$racine"

destination="${1:-storage/backups}"
mkdir -p "$destination"

# Les identifiants viennent de `.env`, jamais de ce fichier.
if [[ ! -f .env ]]; then
  echo "Erreur : aucun fichier .env. Voir docs/deployment/LAUNCH_CHECKLIST.md §2." >&2
  exit 1
fi

lire_env() {
  local cle="$1" defaut="${2:-}"
  local valeur
  valeur="$(grep -E "^${cle}=" .env | tail -n 1 | cut -d= -f2- | tr -d '"'"'"'' || true)"
  echo "${valeur:-$defaut}"
}

base="$(lire_env DB_DATABASE birnin_gobe)"
utilisateur="$(lire_env DB_USERNAME birnin_gobe)"

compose=(docker compose -f docker-compose.yml)
[[ -f docker-compose.prod.yml ]] && compose+=(-f docker-compose.prod.yml)

horodatage="$(date +%Y%m%d-%H%M%S)"
fichier="${destination}/${base}-${horodatage}.dump"

echo "Sauvegarde de « ${base} » vers ${fichier}…"

# `-T` : pas de pseudo-terminal, sans quoi le flux binaire serait corrompu.
"${compose[@]}" exec -T postgres \
  pg_dump -U "$utilisateur" -d "$base" --format=custom --no-owner \
  > "$fichier"

octets="$(wc -c < "$fichier")"

# Un dump vide ou minuscule signale un échec que `pg_dump` n'a pas signalé.
if [[ "$octets" -lt 1024 ]]; then
  echo "Erreur : le fichier produit fait ${octets} octets. Sauvegarde suspecte, non conservée." >&2
  rm -f "$fichier"
  exit 1
fi

echo "Terminé : ${fichier} (${octets} octets)"
echo
echo "Copier ce fichier hors du serveur, puis vérifier qu'il se relit :"
echo "  pg_restore --list \"${fichier}\" | head"
