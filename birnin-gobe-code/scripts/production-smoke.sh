#!/usr/bin/env bash
#
# Contrôles de fumée après déploiement — partie non authentifiée.
#
#   ./scripts/production-smoke.sh https://LE-DOMAINE
#
# Ce script est **strictement en lecture** : que des requêtes GET, aucun compte
# créé, aucune donnée écrite, aucun secret affiché. Il peut être relancé autant
# de fois que voulu, y compris sur une production ouverte.
#
# Il ne remplace pas la checklist : les points authentifiés — inscription,
# connexion, candidature, cloisonnement des espaces — se vérifient au
# navigateur. Voir docs/deployment/LAUNCH_CHECKLIST.md §7.

set -uo pipefail

base="${1:-}"

if [[ -z "$base" ]]; then
  echo "Usage : $0 https://LE-DOMAINE" >&2
  exit 64
fi

base="${base%/}"
reussis=0
echoues=0

# Affiche le résultat et tient le compte, sans jamais interrompre : un premier
# échec ne doit pas masquer les suivants.
verifie() {
  local intitule="$1" attendu="$2" obtenu="$3"
  if [[ "$obtenu" == "$attendu" ]]; then
    printf '  OK    %-52s %s\n' "$intitule" "$obtenu"
    reussis=$((reussis + 1))
  else
    printf '  ECHEC %-52s attendu %s, obtenu %s\n' "$intitule" "$attendu" "$obtenu"
    echoues=$((echoues + 1))
  fi
}

code() { curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$1"; }

echo "Contrôles de fumée sur ${base}"
echo

# — Disponibilité
verifie "GET /" 200 "$(code "${base}/")"
verifie "GET /up" 200 "$(code "${base}/up")"
verifie "GET /api/v1/health" 200 "$(code "${base}/api/v1/health")"

corps_sante="$(curl -s --max-time 15 "${base}/api/v1/health")"
verifie "corps de /api/v1/health" '{"status":"ok"}' "$corps_sante"

# — Cloisonnement : un visiteur anonyme n'entre pas dans l'administration
verifie "GET /admin/applications (anonyme)" 302 "$(code "${base}/admin/applications")"
verifie "GET /admin/campaigns (anonyme)" 302 "$(code "${base}/admin/campaigns")"
verifie "GET /candidate/dashboard (anonyme)" 302 "$(code "${base}/candidate/dashboard")"

# — Écrans publics attendus
verifie "GET /login" 200 "$(code "${base}/login")"
verifie "GET /register" 200 "$(code "${base}/register")"
verifie "GET /admin/login" 200 "$(code "${base}/admin/login")"

# — En-têtes de sécurité posés par Caddy
entetes="$(curl -s -D - -o /dev/null --max-time 15 "${base}/" | tr -d '\r')"
presence() { grep -qi "^$1:" <<< "$entetes" && echo present || echo absent; }

verifie "en-tête X-Content-Type-Options" present "$(presence X-Content-Type-Options)"
verifie "en-tête Referrer-Policy" present "$(presence Referrer-Policy)"
verifie "en-tête X-Frame-Options" present "$(presence X-Frame-Options)"

# — TLS : uniquement si l'adresse testée est en https
if [[ "$base" == https://* ]]; then
  verifie "en-tête Strict-Transport-Security" present "$(presence Strict-Transport-Security)"

  sans_tls="${base/https:/http:}"
  verifie "http:// redirige vers https://" 308 "$(code "$sans_tls")"
else
  echo "  NOTE  adresse en clair : TLS et HSTS non vérifiés."
  echo "        En production, SITE_ADDRESS doit porter le domaine réel."
fi

# — Aucune trace d'exécution ne doit fuir (APP_DEBUG=false)
page_absente="$(curl -s --max-time 15 "${base}/page-qui-nexiste-pas")"
if grep -qiE 'stack trace|vendor/laravel|APP_KEY|DB_PASSWORD' <<< "$page_absente"; then
  printf '  ECHEC %-52s trace d'"'"'exécution exposée — vérifier APP_DEBUG=false\n' "page inconnue sans trace d'exécution"
  echoues=$((echoues + 1))
else
  printf '  OK    %-52s aucune fuite\n' "page inconnue sans trace d'exécution"
  reussis=$((reussis + 1))
fi

echo
echo "Réussis : ${reussis}    Échoués : ${echoues}"
echo
echo "Reste à vérifier au navigateur (checklist §7) : inscription, connexion"
echo "candidat et administrateur, création d'une candidature, persistance après"
echo "rechargement, 403 candidat vers l'administration, 404 sur un dossier absent."

[[ "$echoues" -eq 0 ]]
