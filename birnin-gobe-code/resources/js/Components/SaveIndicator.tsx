import { Cloud, CloudOff, LoaderCircle } from 'lucide-react';
import type { SaveState } from '@/hooks/useAutosave';

/**
 * Etat de sauvegarde affiche au candidat.
 *
 * Il decrit une requete HTTP reelle, pas une animation : « Enregistre » ne
 * s'affiche qu'apres la reponse de Laravel confirmant l'ecriture en base.
 *
 * Extrait de l'ecran « Defi » a l'arrivee de « Eligibilite » : deux ecrans
 * affichaient le meme bloc, avec le meme role ARIA et les memes libelles. C'est
 * de la duplication reelle, pas une generalisation anticipee.
 */
const labels: Record<SaveState, string | null> = {
  idle: null,
  dirty: 'Modifications non enregistrées',
  saving: 'Enregistrement…',
  saved: 'Enregistré',
  error: 'Erreur d’enregistrement',
};

function heure(iso: string | null): string {
  if (!iso) return '';
  return new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
}

export function SaveIndicator({ state, savedAt }: { state: SaveState; savedAt: string | null }) {
  const label = labels[state];
  if (!label) return null;

  const Icon = state === 'saving' ? LoaderCircle : state === 'error' ? CloudOff : Cloud;
  const tone = state === 'error' ? 'text-red-600' : state === 'dirty' ? 'text-slate-500' : 'text-brand-800';

  return (
    <div className={`flex items-center gap-2 text-xs ${tone}`} role="status" aria-live="polite" data-testid="etat-sauvegarde">
      <Icon size={18} className={state === 'saving' ? 'animate-spin' : undefined} />
      <div>
        <strong>{label}</strong>
        {state === 'saved' && savedAt ? <><br /><span className="text-slate-400">à {heure(savedAt)}</span></> : null}
      </div>
    </div>
  );
}
