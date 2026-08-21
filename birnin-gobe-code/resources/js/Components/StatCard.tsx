import type { LucideIcon } from 'lucide-react';
import { AnimatedCounter } from '@/Components/AnimatedCounter';
import { Reveal } from '@/Components/Reveal';

export function StatCard({ icon: Icon, value, label, hint, tone = 'green' }: { icon: LucideIcon; value: string | number; label: string; hint?: string; tone?: 'green' | 'gold' | 'blue' | 'red' }) {
  const palette = {
    green: 'bg-emerald-50 text-emerald-700',
    gold: 'bg-amber-50 text-amber-700',
    blue: 'bg-sky-50 text-sky-700',
    red: 'bg-red-50 text-red-700',
  }[tone];
  return (
    <Reveal className="hover-lift metric-card flex items-center gap-4 px-5 py-4">
      <div className={`grid h-12 w-12 place-items-center rounded-full ${palette}`}><Icon size={23} strokeWidth={1.8} /></div>
      <div className="min-w-0">
        <div className="text-2xl font-extrabold tracking-tight text-slate-900"><AnimatedCounter value={String(value)} /></div>
        <div className="text-sm font-bold text-slate-700">{label}</div>
        {hint ? <div className="mt-0.5 text-[11px] text-slate-400">{hint}</div> : null}
      </div>
    </Reveal>
  );
}
