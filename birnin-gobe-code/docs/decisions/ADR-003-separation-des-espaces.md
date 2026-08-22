# ADR-003 — Séparation des espaces et des rôles

**Statut :** accepté — contrainte d'architecture **non négociable**
**Date :** 2026-08-22

## Contexte

Le prototype exposait dans l'en-tête public un menu « Se connecter » intitulé
« Accès démonstration », qui permettait à n'importe quel visiteur de basculer
vers l'espace administrateur ou l'espace évaluateur / jury en un clic.

Pratique en développement, inacceptable pour une plateforme d'État : le
parcours candidat annonçait l'existence et l'emplacement du back-office.

## Décision

L'expérience candidat est **strictement séparée** des espaces internes.

### Parcours candidat — le seul exposé publiquement

```
Portail public → Créer un compte → Connexion candidat → Dashboard → Candidature
```

### Interdits dans l'interface publique et candidate

Aucun de ces éléments ne doit apparaître, ni dans l'en-tête, ni dans la
navigation, ni dans le pied de page :

- « Se connecter comme administrateur / jury / évaluateur »
- sélecteur de rôle, bouton de changement de rôle
- lien vers `/admin`, `/evaluator`, `/jury`
- toute entrée de navigation réservée au back-office

Le candidat ne voit que ce qui le concerne : *Créer un compte*, *Se connecter*,
*Mon espace*, *Ma candidature*, *Se déconnecter*, selon le contexte.

### Espaces internes

`/admin`, `/evaluator` et `/jury` (quand il existera) sont des espaces internes
avec leur propre flux d'accès. Leur URL peut exister techniquement, elle n'a pas
à être annoncée dans l'interface publique.

### La séparation n'est pas visuelle

Masquer un bouton n'est **jamais** une autorisation. La chaîne cible est :

```
Utilisateur authentifié → Rôle → Middleware / Policy → Ressource autorisée
```

| Rôle | Accès |
|---|---|
| `candidate` | espace candidat uniquement |
| `admin` | administration |
| `evaluator` | évaluations qui lui sont affectées |
| `jury` | espace jury |

Un candidat saisissant `/admin`, `/evaluator` ou `/jury` doit recevoir un **403**
une fois l'authentification et le RBAC branchés.

## Ce qui est fait, ce qui reste

| Élément | État |
|---|---|
| Sélecteur de rôle de démonstration | **Supprimé** de `PublicLayout` |
| Liens internes dans l'interface publique/candidate | **Aucun** |
| Groupes de routes séparés (`public` / `candidate` / interne) | **En place**, `routes/web.php` |
| Emplacement des middlewares documenté | **En place**, en commentaire de groupe |
| Protection backend réelle | **Bloquée** — l'authentification n'existe pas |

**Aucun RBAC de façade n'a été introduit.** Les routes internes restent
publiquement joignables aujourd'hui ; c'est documenté comme le blocage principal
avant toute mise en ligne avec de vraies données
(`docs/deployment/NIGER_TELECOM_HANDOFF.md` §13-14).

## Mode démonstration

Un drapeau du type `VITE_DEMO_ROLE_SWITCHER` **n'a pas été introduit**, pour
trois raisons :

1. aucune variable `VITE_*` ni `DEMO` n'existait dans la configuration — il
   aurait fallu créer le mécanisme entier ;
2. un drapeau destiné à exposer les tableaux de bord internes est lui-même un
   risque : il suffit qu'il soit activé par erreur dans un build déployé ;
3. il n'apporte rien à un développeur, qui atteint ces écrans en saisissant
   l'URL tant qu'aucune authentification ne les protège.

Si un besoin réel apparaît plus tard, il devra passer par une configuration
explicite de développement, jamais par le mécanisme d'autorisation.

## Vérification

`tests/E2E/separation-espaces.spec.ts` vérifie l'absence de tout accès interne
dans l'interface publique et candidate. Ces tests couvrent la surface visible ;
ils ne remplacent pas les tests backend à écrire quand le RBAC existera :

```
candidate → /admin      = 403
candidate → /evaluator  = 403
candidate → /jury       = 403
```
