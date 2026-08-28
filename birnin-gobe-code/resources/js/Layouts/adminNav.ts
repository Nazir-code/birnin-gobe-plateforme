import { BarChart3, Bell, CalendarRange, FileStack, FolderKanban, Gauge, Layers3, Settings, UsersRound } from 'lucide-react';
import type { DarkNavItem } from '@/Layouts/DarkSidebarLayout';

/**
 * Navigation de l'administration, partagée par tous ses écrans.
 *
 * Une seule liste : dupliquée par page, elle finit par diverger et l'entrée
 * active ne correspond plus à l'écran affiché.
 *
 * Toutes les entrées portent désormais un `href` : les neuf écrans du
 * back-office existent. La règle qui a présidé à leur ouverture reste valable
 * pour toute entrée future — une entrée sans `href` désigne un écran absent, et
 * elle reste inerte, parce que la rendre cliquable serait pire que de ne rien
 * promettre.
 *
 * « Existe » ne veut pas dire « couvre tout son domaine » : Paramètres, par
 * exemple, inventorie les neuf domaines du §9.2 et n'en rend administrables que
 * trois. C'est l'écran lui-même qui dit ce qu'il ne fait pas — pas l'absence de
 * lien.
 */
export const adminNav: DarkNavItem[] = [
  { icon: Gauge, label: 'Tableau de bord', href: '/admin/dashboard' },
  { icon: CalendarRange, label: 'Campagnes', href: '/admin/campaigns' },
  { icon: Layers3, label: 'Files de vérification', href: '/admin/verification' },
  { icon: FolderKanban, label: 'Candidatures', href: '/admin/applications' },
  { icon: UsersRound, label: 'Évaluateurs', href: '/admin/evaluators' },
  { icon: BarChart3, label: 'Indicateurs', href: '/admin/indicators' },
  { icon: Bell, label: 'Alertes', href: '/admin/alerts' },
  { icon: Settings, label: 'Paramètres', href: '/admin/settings' },
  { icon: FileStack, label: 'Journal d’audit', href: '/admin/audit' },
];

/** URL de déconnexion interne, commune aux écrans d'administration. */
export const ADMIN_LOGOUT = '/admin/logout';
