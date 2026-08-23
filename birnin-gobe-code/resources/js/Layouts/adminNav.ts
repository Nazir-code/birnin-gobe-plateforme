import { BarChart3, Bell, CalendarRange, FileStack, FolderKanban, Gauge, Layers3, Settings, UsersRound } from 'lucide-react';
import type { DarkNavItem } from '@/Layouts/DarkSidebarLayout';

/**
 * Navigation de l'administration, partagée par tous ses écrans.
 *
 * Une seule liste : dupliquée par page, elle finit par diverger et l'entrée
 * active ne correspond plus à l'écran affiché.
 *
 * Les entrées sans `href` désignent des écrans qui n'existent pas encore
 * (Admin Phase 3 et suivantes). Elles restent visibles parce qu'elles décrivent
 * l'architecture cible du back-office, et inertes parce que les rendre
 * cliquables sur un écran absent serait pire que de ne rien promettre.
 */
export const adminNav: DarkNavItem[] = [
  { icon: Gauge, label: 'Tableau de bord', href: '/admin/dashboard' },
  { icon: CalendarRange, label: 'Campagnes', href: '/admin/campaigns' },
  { icon: Layers3, label: 'Files de vérification' },
  { icon: FolderKanban, label: 'Candidatures', href: '/admin/applications' },
  { icon: UsersRound, label: 'Évaluateurs' },
  { icon: BarChart3, label: 'Indicateurs' },
  { icon: Bell, label: 'Alertes' },
  { icon: Settings, label: 'Paramètres' },
  { icon: FileStack, label: 'Journal d’audit' },
];

/** URL de déconnexion interne, commune aux écrans d'administration. */
export const ADMIN_LOGOUT = '/admin/logout';
