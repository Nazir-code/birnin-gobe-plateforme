import { Check, LockKeyhole } from 'lucide-react';
import { Reveal } from '@/Components/Reveal';

export type Step = { label: string; state: 'done' | 'active' | 'pending' };

export function ProgressSteps({ steps }: { steps: Step[] }) {
  return (
    <div className="grid grid-cols-3 gap-x-2 gap-y-5 sm:grid-cols-5 xl:grid-cols-9">
      {steps.map((step, index) => (
        <Reveal key={step.label} delay={index * 40} className="relative text-center">
          <div className={`mx-auto grid h-9 w-9 place-items-center rounded-full border-2 text-xs font-extrabold transition-colors duration-[250ms] ${
            step.state === 'done' ? 'border-brand-800 bg-brand-800 text-white' : step.state === 'active' ? 'border-gold-500 bg-gold-500 text-slate-950 ring-4 ring-amber-100' : 'border-slate-200 bg-slate-100 text-slate-400'
          }`}>
            {step.state === 'done' ? <Check size={16} /> : step.state === 'pending' ? <LockKeyhole size={14} /> : index + 1}
          </div>
          <div className={`mt-2 text-[11px] font-semibold leading-tight ${step.state === 'active' ? 'text-amber-700' : step.state === 'done' ? 'text-brand-900' : 'text-slate-500'}`}>{step.label}</div>
        </Reveal>
      ))}
    </div>
  );
}
