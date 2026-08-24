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

# — La page d'accueil ne sert aucune valeur de maquette
#
# Elle a longtemps affiché une édition, une date limite et des statistiques
# venues de `resources/js/data/demo.ts`. Une date limite fausse sur le site
# officiel induit en erreur un candidat qui n'a rien fait de mal : ce contrôle
# vérifie qu'aucune de ces chaînes ne revient dans le HTML servi.
accueil="$(curl -s --max-time 15 "${base}/")"

demo_trouvee=""
for trace in '30 juin 2026' '5 000+' '1 200+' 'Jeunes impactés' 'Projets accompagnés' 'Partenaires engagés'; do
  if grep -qF "$trace" <<< "$accueil"; then
    demo_trouvee="$trace"
    break
  fi
done

if [[ -n "$demo_trouvee" ]]; then
  printf '  ECHEC %-52s « %s » encore servi
' "accueil sans donnée de maquette" "$demo_trouvee"
  echoues=$((echoues + 1))
else
  printf '  OK    %-52s aucune
' "accueil sans donnée de maquette"
  reussis=$((reussis + 1))
fi

# — L'accueil dit l'état réel du dépôt : une édition, ou aucune.
#
# On lit la charge utile Inertia (`data-page`), pas le DOM : la page est rendue
# par le navigateur, et un `curl` ne verrait jamais le décompte ni le message de
# fermeture. `"campaign":null` et `"campaign":{...}` sont les deux réponses
# légitimes du serveur ; c'est leur absence qui signalerait une régression.
if grep -qF '"campaign":null' <<< "$accueil"; then
  printf '  OK    %-52s aucune édition ouverte, dit comme tel
' "accueil : état du dépôt annoncé"
  reussis=$((reussis + 1))
elif grep -qE '"campaign":\{[^}]*"closesAt":"[0-9]{4}-' <<< "$accueil"; then
  printf '  OK    %-52s édition ouverte, clôture réelle servie
' "accueil : état du dépôt annoncé"
  reussis=$((reussis + 1))
else
  printf '  ECHEC %-52s le serveur n'"'"'annonce ni édition ni fermeture
' "accueil : état du dépôt annoncé"
  echoues=$((echoues + 1))
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
echo
echo "La limitation des inscriptions (10 comptes / 15 min par origine) crée des"
echo "comptes : elle est donc prouvée par AccueilPublicTest, pas ici — ce script"
echo "reste strictement en lecture et rejouable sur une production ouverte."

[[ "$echoues" -eq 0 ]]
