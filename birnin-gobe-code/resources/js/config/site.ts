import { usePage } from '@inertiajs/react';
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

export const publicSiteLink: SiteLink = { key: 'publicSite', href: '/' };

/**
 * Cible du bouton « Candidater » tant qu'aucune route de dépôt n'existe : la même
 * ancre que les appels à l'action déjà présents dans l'en-tête et le hero.
 * Remplacée dès que la campagne expose son `applyUrl` (voir `SiteData`).
 */
export const prototypeApplyTarget = '/#candidater';

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
