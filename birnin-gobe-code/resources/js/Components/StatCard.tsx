import { Link } from '@inertiajs/react';
import { ArrowRight, type LucideIcon } from 'lucide-react';
import { AnimatedCounter } from '@/Components/AnimatedCounter';
import { Reveal } from '@/Components/Reveal';

/**
 * Un compteur de tableau de bord.
 *
 * **`href` le rend cliquable, et son absence le laisse inerte.** C'est la règle
 * déjà posée pour la navigation et les commandes : un chiffre qui a l'air d'un
 * lien sans en être un se lit comme une page cassée. Une carte sans destination
 * garde donc l'apparence d'une carte, sans curseur de pointeur ni flèche.
 *
 * **Toutes les cartes ont la même hauteur, dans toutes les dispositions.**
 * Deux mécanismes le tiennent, et il faut les deux. `h-full` — sur l'enveloppe,
 * qui est la cellule de grille, et sur le lien, qui porte le fond blanc — aligne
 * les cartes d'une même rangée. Mais sous 640 px la grille passe à une colonne :
 * chaque carte est alors seule dans sa rangée, et `h-full` n'a plus rien à quoi
 * s'aligner. D'où la hauteur réservée de l'intitulé secondaire, qui rend la
 * hauteur du contenu indépendante du texte.
 *
 * **La destination doit montrer exactement ce que la carte a compté.** Un
 * compteur qui annonce trois dossiers soumis et ouvre une liste de douze fait
 * douter du compteur. C'est la même exigence que pour les alertes du §9.3, dont
 * l'URL pointe une liste déjà filtrée sur le périmètre mesuré.
 */
export function StatCard({
  icon: Icon,
  value,
  label,
  hint,
  tone = 'green',
  href,
  testId,
}: {
  icon: LucideIcon;
  value: string | number;
  label: string;
  hint?: string;
  tone?: 'green' | 'gold' | 'blue' | 'red';
  /** Destination du clic. Omis : la carte reste un simple affichage. */
  href?: string;
  testId?: string;
}) {
  const palette = {
    green: 'bg-emerald-50 text-emerald-700',
    gold: 'bg-amber-50 text-amber-700',
    blue: 'bg-sky-50 text-sky-700',
    red: 'bg-red-50 text-red-700',
  }[tone];

  const contenu = (
    <>
      <div className={`grid h-12 w-12 place-items-center rounded-full ${palette}`}>
        <Icon size={23} strokeWidth={1.8} />
      </div>
      <div className="min-w-0 flex-1">
        <div className="text-2xl font-extrabold tracking-tight text-slate-900">
          <AnimatedCounter value={String(value)} />
        </div>
        <div className="text-sm font-bold text-slate-700">{label}</div>
        {/* Deux lignes réservées, jamais plus, jamais moins : c'est
            l'intitulé secondaire qui faisait varier la hauteur des cartes, et
            en colonne unique aucune rangée ne vient les réaligner. `line-clamp`
            borne le haut, `min-h` réserve le bas. */}
        {hint ? <div className="mt-0.5 line-clamp-2 min-h-[2rem] text-[11px] leading-4 text-slate-400">{hint}</div> : null}
      </div>
      {href ? <ArrowRight size={16} className="shrink-0 text-slate-300" aria-hidden /> : null}
    </>
  );

  if (href) {
    return (
      <Reveal className="h-full">
        <Link
          href={href}
          data-testid={testId}
          className="hover-lift press-feedback focus-ring metric-card flex h-full w-full items-center gap-4 px-5 py-4 text-left transition-colors hover:bg-slate-50"
        >
          {contenu}
        </Link>
      </Reveal>
    );
  }

  return (
    <Reveal className="hover-lift metric-card flex h-full items-center gap-4 px-5 py-4" testId={testId}>
      {contenu}
    </Reveal>
  );
}
