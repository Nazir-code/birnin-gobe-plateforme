import { useState } from 'react';
import { router } from '@inertiajs/react';
import { RotateCw } from 'lucide-react';

/**
 * Recharge les données de l'écran courant, sans quitter la page.
 *
 * **Pourquoi un bouton plutôt que le rechargement du navigateur.** `F5` refait
 * la requête complète : nouveau document, nouveaux assets, retour en haut de
 * page, filtres de l'URL rejoués mais défilement perdu. `router.reload()` ne
 * redemande que les propriétés de la page et les réapplique en place — c'est le
 * geste qu'on veut sur un écran de pilotage qu'on regarde en continu, avec ses
 * filtres posés et sa liste défilée à mi-hauteur.
 *
 * **`only` sert les écrans lourds.** Un tableau de bord peut ne redemander que
 * ses compteurs, sans reconstruire la liste des campagnes. Omis, tout est
 * rechargé — ce qui est le bon défaut : mieux vaut une requête complète qu'une
 * fraîcheur partielle dont personne ne connaît le périmètre.
 *
 * **Le défilement et l'état de la page sont conservés d'office.**
 * `router.reload()` ne prend pas `preserveScroll` : il les préserve toujours,
 * c'est ce qui le distingue d'une visite. On actualise pour revoir la même
 * chose à jour, pas pour repartir du haut.
 *
 * **L'état d'attente est visible et le bouton se désactive.** Sans cela, un clic
 * sur un réseau lent ne produit rien de perceptible, et la personne reclique —
 * chaque clic relançant une requête sur un serveur déjà occupé.
 */
export function BoutonActualiser({ only, label = 'Actualiser' }: { only?: string[]; label?: string }) {
  const [enCours, setEnCours] = useState(false);

  return (
    <button
      type="button"
      disabled={enCours}
      onClick={() =>
        router.reload({
          ...(only ? { only } : {}),
          onStart: () => setEnCours(true),
          onFinish: () => setEnCours(false),
        })
      }
      className="focus-ring press-feedback inline-flex min-h-10 items-center gap-2 rounded-xl border border-slate-200 px-3 text-sm font-bold text-slate-700 transition-colors hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60"
      aria-label={enCours ? 'Actualisation en cours' : label}
      aria-live="polite"
      data-testid="actualiser"
    >
      <RotateCw size={16} className={enCours ? 'anim-rotation' : undefined} aria-hidden />
      <span className="hidden sm:inline">{enCours ? 'Actualisation…' : label}</span>
    </button>
  );
}
