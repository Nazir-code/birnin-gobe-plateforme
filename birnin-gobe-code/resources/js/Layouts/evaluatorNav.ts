import { BriefcaseBusiness } from 'lucide-react';
import type { DarkNavItem } from '@/Layouts/DarkSidebarLayout';

/**
 * Navigation de l'espace évaluateur.
 *
 * **Une seule entrée, et c'est délibéré.** La maquette en proposait sept
 * — tableau de bord, évaluations, conflits signalés, ressources, profil — dont
 * six ne correspondaient à aucun écran. La règle posée pour `adminNav` vaut ici
 * : une entrée sans `href` désigne un écran absent, et une liste de six liens
 * inertes promet un espace qui n'existe pas.
 *
 * Les cinq entrées retirées ne sont pas perdues pour autant, elles sont
 * ailleurs : les conflits se déclarent depuis le dossier concerné, au moment où
 * on découvre le lien — un écran « conflits signalés » séparé obligerait à s'en
 * souvenir plus tard. L'avancement tient dans le compteur du plan de travail,
 * qui est la seule chose qu'un tableau de bord d'évaluateur aurait à dire.
 */
export const evaluatorNav: DarkNavItem[] = [
  { icon: BriefcaseBusiness, label: 'Mes dossiers', href: '/evaluator/assignments' },
];

/** URL de déconnexion, commune aux écrans de l'espace évaluateur. */
export const EVALUATOR_LOGOUT = '/logout';
