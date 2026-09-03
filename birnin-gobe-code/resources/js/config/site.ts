import { usePage } from '@inertiajs/react';
import { useAuthUser } from '@/hooks/useAuth';
import type { Dictionary } from '@/i18n';

export type FooterLinkKey = keyof Dictionary['footer']['links'];

/**
 * Destination d'un lien de pied de page.
 *
 * `href: null` = la route / la page n'existe pas encore dans ce prototype.
 * Aucune URL n'est inventée : l'entrée reste visible dans le plan du site mais
 * n'est pas cliquable tant que la route réelle (ou le contenu CMS) n'existe pas.
 * Renseigner le `href` suffit à activer le lien, sans toucher au composant.
 */
export type SiteLink = { key: FooterLinkKey; href: string | null };

/**
 * Les pages publiques du cahier des charges (§4.1) ne sont pas encore routées :
 * seules `/` et les ancres réellement présentes sur la page d'accueil sont actives.
 * Les ancres suivent la convention déjà utilisée par la navigation publique.
 */
export const quickLinks: SiteLink[] = [
  { key: 'home', href: '/' },
  { key: 'about', href: null },
  { key: 'themes', href: '/#thematiques' },
  { key: 'criteria', href: '/#criteres' },
  { key: 'eligibility', href: '/#eligibilite' },
  { key: 'calendar', href: '/#calendrier' },
  { key: 'process', href: '/#processus' },
  { key: 'howToApply', href: null },
];

export const legalLinks: SiteLink[] = [
  { key: 'legalNotice', href: null },
  { key: 'privacy', href: null },
  { key: 'dataProtection', href: null },
  { key: 'accessibility', href: null },
];

export const supportLink: SiteLink = { key: 'support', href: null };

/**
 * Un réalisateur de la plateforme, tel qu'il est crédité au pied de page.
 *
 * Le nom est porté ici et non dans le dictionnaire : c'est un nom propre, il ne
 * se traduit pas, et une entrée par locale finirait par en produire des
 * variantes. Le pied de page ne connaît que cette liste.
 */
export type SiteMaker = { name: string; href: string | null };

/**
 * Les réalisateurs crédités au pied de page.
 *
 * Même règle que pour `SiteLink` : `href: null` = **aucune URL inventée**. Le
 * nom reste alors affiché en texte simple, pas en lien mort. Renseigner le
 * `href` suffit à le rendre cliquable, sans toucher au composant.
 *
 * Ce sont des sites tiers : ils s'ouvrent dans un nouvel onglet, comme les liens
 * sociaux, pour ne pas arracher un candidat au formulaire qu'il est en train de
 * remplir.
 */
export const siteMakers: SiteMaker[] = [
  { name: 'NOVATECH', href: 'https://novatech.ne/' },
  { name: 'FME Consult', href: 'https://www.fmeconsult.com/' },
];

export const publicSiteLink: SiteLink = { key: 'publicSite', href: '/' };

/**
 * Entrée candidat depuis le portail public.
 *
 * **Contrainte d'architecture non négociable** : l'interface publique et
 * l'espace candidat n'exposent qu'un seul parcours d'accès, celui du candidat.
 * Aucun lien, bouton ou sélecteur ne doit mener aux espaces internes
 * (`/admin`, `/evaluator`, `/jury`) — voir
 * `docs/decisions/ADR-003-separation-des-espaces.md`.
 *
 * Point de vérité unique pour l'entrée candidat depuis le portail.
 */
export const candidateEntryTarget = '/login';

/** Inscription publique — crée exclusivement des comptes candidat. */
export const candidateSignupTarget = '/register';

/** Espace du candidat déjà inscrit. */
export const candidateDashboardTarget = '/candidate/dashboard';

/**
 * Ce que l'appel à candidater doit faire, selon qui regarde la page.
 *
 * **`/register` est derrière le middleware `guest`.** Pour quelqu'un de déjà
 * connecté, Laravel le renvoie vers la première route nommée `dashboard` ou
 * `home` ; la route candidat s'appelant `candidate.dashboard`, c'est `home` qui
 * gagne — c'est-à-dire la page d'accueil. Un visiteur connecté qui cliquait
 * depuis l'accueil déclenchait donc une visite Inertia vers la page où il se
 * trouvait déjà : aucune erreur, aucune navigation, aucun retour visuel. Le
 * bouton paraissait mort.
 *
 * Le défaut n'était visible qu'en étant connecté, ce qu'aucun test public ne
 * fait — signalé le 3 septembre 2026, après la correction d'un premier défaut
 * sur le même bouton.
 *
 * Trois cas, et le troisième n'est pas une commodité :
 *
 *  - **visiteur anonyme** — l'inscription, cas nominal ;
 *  - **candidat connecté** — son espace. Lui proposer de créer un second compte
 *    n'a pas de sens, et « Commencer » est faux quand un dossier existe déjà :
 *    l'appelant lit `reprise` pour changer le libellé ;
 *  - **rôle interne** — rien. ADR-003 interdit au portail public de mener aux
 *    espaces internes, et inviter un administrateur à déposer une candidature
 *    n'aurait pas de sens. Le bouton disparaît plutôt que de mentir.
 *
 * Sert à l'affichage seulement, comme `useAuthUser` : le contrôle d'accès reste
 * au serveur.
 */
/**
 * L'entrée « Se connecter » du portail, ou `null` si elle n'a plus lieu d'être.
 *
 * **Le même piège que l'appel à candidater, sur le bouton d'à côté.** `/login`
 * est aussi derrière `guest` : pour quelqu'un de déjà connecté, le clic
 * déclenchait une visite vers `/`, c'est-à-dire nulle part. Le défaut est resté
 * invisible parce qu'on cliquait sur l'autre bouton.
 *
 * Connecté, l'entrée disparaît, et ce n'est pas un pis-aller :
 *
 *  - **un candidat** a déjà son chemin de retour, l'appel à candidater dit
 *    « Reprendre ma candidature » et mène à son espace. Un second bouton vers la
 *    même page n'ajouterait rien qu'une hésitation ;
 *  - **un rôle interne** ne doit pas se voir proposer la connexion *candidat* —
 *    c'est le seul parcours que le portail public expose (ADR-003). L'entrée de
 *    son propre espace n'a pas sa place ici non plus, pour la même raison.
 */
export function useCandidateEntry(): string | null {
  return useAuthUser() === null ? candidateEntryTarget : null;
}

export type ApplyCta = { href: string; reprise: boolean } | null;

export function useApplyCta(): ApplyCta {
  const role = useAuthUser()?.role;

  if (role === undefined) {
    return { href: candidateSignupTarget, reprise: false };
  }

  if (role === 'candidate') {
    return { href: candidateDashboardTarget, reprise: true };
  }

  return null;
}

/**
 * Logos officiels déjà présents dans le dépôt (aucune copie, aucun logo recréé).
 *
 * `width`/`height` sont les dimensions intrinsèques réelles des fichiers. Elles
 * sont indispensables et pas seulement confortables : rendus en `h-14 w-auto`
 * et `loading="lazy"`, ces logos occupaient une boîte de 0 px de large tant
 * qu'ils n'étaient pas chargés — donc une aire nulle, donc un
 * IntersectionObserver qui ne se déclenche jamais, donc un chargement qui ne
 * démarre jamais. Les deux logos partenaires restaient invisibles. Le ratio
 * déclaré casse cet interblocage et supprime au passage le décalage de mise
 * en page à l'arrivée de l'image.
 */
export const institutionalPartners = [
  { name: 'PIDUREM', src: '/assets/pidurem%20logo.jpeg', width: 561, height: 356 },
  { name: 'ANSI', src: '/assets/logo%20ANSI.png', width: 447, height: 447 },
] as const;

/**
 * Données institutionnelles susceptibles d'être servies par la configuration de
 * campagne / le CMS (§9.2 du cahier des charges : « contacts et textes légaux »).
 * Rien n'est affiché tant que le serveur ne partage pas ces valeurs : pas de
 * faux contact, pas de faux réseau social.
 */
export type SiteData = {
  campaign?: { status?: 'UPCOMING' | 'OPEN' | 'CLOSED'; applyUrl?: string | null };
  contact?: { email?: string | null; phone?: string | null; address?: string | null; hours?: string | null };
  social?: { label: string; url: string }[];
};

export function useSiteData(): SiteData {
  return usePage<{ site?: SiteData }>().props.site ?? {};
}

/** Une ancre reste un lien natif : Inertia ne gère pas le défilement d'ancre. */
export function isAnchorHref(href: string): boolean {
  return href.startsWith('#') || href.includes('/#');
}
