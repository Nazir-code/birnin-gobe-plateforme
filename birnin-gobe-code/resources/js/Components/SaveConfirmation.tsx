import { useEffect } from 'react';
import { AlertTriangle, Check, X } from 'lucide-react';
import type { SaveConfirmation as Confirmation } from '@/hooks/useAutosave';

/** Delai avant effacement. Assez pour etre lu, pas assez pour gener. */
const DUREE_MS = 6000;

/**
 * Reponse visible a un clic sur « Enregistrer ».
 *
 * `SaveIndicator` dit l'etat en continu, sauvegarde automatique comprise ; il
 * est discret, et c'est voulu. Ce message-ci ne parait qu'apres un
 * enregistrement demande, et rapporte ce que le serveur a repondu — succes
 * comme echec. Une confirmation qui ne saurait pas dire non ne confirmerait
 * rien.
 *
 * Deux details d'accessibilite, imposes par le contrat UI :
 *
 *   `role="status"` pour un succes, `role="alert"` pour un echec — un lecteur
 *     d'ecran interrompt son enonce pour le second, pas pour le premier ;
 *   le message reste fermable a la main, et son bouton est atteignable au
 *     clavier : l'effacement automatique est un confort, jamais le seul moyen
 *     de s'en debarrasser.
 *
 * Le conteneur ne capte pas le pointeur (`pointer-events-none`) : pose au-dessus
 * du formulaire, il ne doit rien intercepter en dehors de sa propre carte.
 */
export function SaveConfirmation({ confirmation, onAcquitter }: {
  confirmation: Confirmation | null;
  onAcquitter: () => void;
}) {
  const id = confirmation?.id ?? null;

  // Le minuteur suit `id`, pas l'objet : deux enregistrements de suite rendent
  // le meme message, et le second doit repartir pour une duree pleine.
  useEffect(() => {
    if (id === null) return;

    const minuteur = setTimeout(onAcquitter, DUREE_MS);

    return () => clearTimeout(minuteur);
  }, [id, onAcquitter]);

  if (!confirmation) return null;

  const succes = confirmation.tone === 'success';
  const Icone = succes ? Check : AlertTriangle;

  return (
    <div className="pointer-events-none fixed inset-x-0 bottom-4 z-50 flex justify-center px-4">
      {/* `key` sur l'identifiant : remonter l'element rejoue l'animation
          d'entree, sans quoi un second enregistrement ne se verrait pas. */}
      <div
        key={confirmation.id}
        role={succes ? 'status' : 'alert'}
        aria-live={succes ? 'polite' : 'assertive'}
        data-testid="confirmation-sauvegarde"
        className={`toast-sauvegarde pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-xl border px-4 py-3 shadow-lg ${
          succes ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-red-200 bg-red-50 text-red-900'
        }`}
      >
        <span className={`mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full ${succes ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'}`}>
          <Icone size={15} aria-hidden />
        </span>
        <p className="min-w-0 flex-1 text-sm font-semibold leading-5">{confirmation.message}</p>
        <button
          type="button"
          onClick={onAcquitter}
          aria-label="Fermer le message"
          className="focus-ring -mr-1 mt-0.5 shrink-0 rounded-md p-1 opacity-70 transition-opacity hover:opacity-100"
        >
          <X size={16} aria-hidden />
        </button>
      </div>
    </div>
  );
}
