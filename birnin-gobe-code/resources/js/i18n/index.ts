import { usePage } from '@inertiajs/react';
import { fr } from './fr';

export type Dictionary = typeof fr;

/**
 * Registre des dictionnaires statiques. Seul le français est peuplé : le haoussa
 * (`ha`) et le zarma (`dje`) ne sont pas inventés et retombent sur le français
 * tant qu'aucune traduction validée institutionnellement n'existe (voir README.md).
 * Ajouter une locale = ajouter une entrée ici, sans toucher aux composants.
 */
const dictionaries: Record<string, Dictionary> = { fr };

/** Dictionnaire correspondant à la locale partagée par Inertia, fallback français. */
export function useI18n(): Dictionary {
  const { locale } = usePage<{ locale?: string }>().props;
  return (locale && dictionaries[locale]) || fr;
}
