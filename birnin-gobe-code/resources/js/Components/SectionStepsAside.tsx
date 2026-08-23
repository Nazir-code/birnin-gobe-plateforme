import { Check, Headphones, LockKeyhole } from 'lucide-react';

export type SectionStep = {
  key: string;
  label: string;
  position: number;
  state: 'done' | 'active' | 'pending';
  implemented: boolean;
};

/**
 * Colonne des neuf etapes, a gauche des ecrans de saisie.
 *
 * Les etats viennent du serveur (`ApplicationPresenter::steps`) : « faite »
 * signifie `completed_at` renseignee en base, pas un compteur cote navigateur.
 * Une etape non developpee porte un cadenas — elle n'est ni cliquable, ni
 * presentee comme disponible.
 *
 * Extrait de « Defi » quand « Eligibilite » a repris le meme bloc a
 * l'identique. Voir ADR-007.
 */
export function SectionStepsAside({ steps, activeKey }: { steps: SectionStep[]; activeKey: string }) {
  return (
    <aside className="hidden bg-gradient-to-b from-brand-800 to-brand-950 px-6 py-8 text-white lg:block">
      <div className="space-y-1.5">
        {steps.map((step) => {
          const active = step.key === activeKey;
          return (
            <div
              key={step.key}
              aria-current={active ? 'step' : undefined}
              className={`relative flex min-h-12 items-center gap-3 rounded-xl px-3 transition-colors duration-[250ms] ${active ? 'bg-white/10 font-extrabold' : 'text-white/85'}`}
            >
              <div className={`grid h-8 w-8 shrink-0 place-items-center rounded-full border text-xs font-black transition-colors duration-[250ms] ${step.state === 'done' ? 'border-white bg-white text-brand-900' : active ? 'border-gold-500 bg-gold-500 text-slate-950' : 'border-white/45'}`}>
                {step.state === 'done' ? <Check size={14} /> : step.implemented ? step.position : <LockKeyhole size={13} />}
              </div>
              <span className="text-sm">{step.label}</span>
            </div>
          );
        })}
      </div>
      <div className="mt-10 rounded-2xl border border-white/25 p-4">
        <div className="flex gap-3">
          <Headphones size={23} />
          <div>
            <div className="text-sm font-extrabold">Besoin d’aide ?</div>
            <p className="mt-1 text-xs leading-5 text-white/75">Consultez notre FAQ ou contactez l’assistance.</p>
          </div>
        </div>
      </div>
    </aside>
  );
}
