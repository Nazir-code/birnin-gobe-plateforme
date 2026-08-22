import { usePage } from '@inertiajs/react';

export type AuthUser = { name: string; email: string; role: string };

/**
 * Utilisateur authentifie partage par Inertia, ou `null` pour un visiteur.
 *
 * Sert uniquement a l'affichage. L'autorisation est faite cote serveur par le
 * middleware `role` : ne jamais conditionner un acces a cette valeur.
 */
export function useAuthUser(): AuthUser | null {
  return usePage<{ auth?: { user: AuthUser | null } }>().props.auth?.user ?? null;
}

/** Initiales pour l'avatar, ex. « Amina Issa » -> « AI ». */
export function initiales(nom: string): string {
  return nom
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((mot) => mot[0]?.toUpperCase() ?? '')
    .join('');
}
