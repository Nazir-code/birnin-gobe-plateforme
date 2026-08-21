import { Head } from '@inertiajs/react';
import { AlertTriangle, BarChart3, Bell, CheckCircle2, ClipboardCheck, FileStack, FolderKanban, Gauge, Layers3, Search, Settings, ShieldCheck, SlidersHorizontal, UsersRound } from 'lucide-react';
import { DarkSidebarLayout, type DarkNavItem } from '@/Layouts/DarkSidebarLayout';
import { Card, Pill, SectionTitle } from '@/Components/Ui';
import { StatCard } from '@/Components/StatCard';
import { Reveal } from '@/Components/Reveal';
import { NigerRegionsMap } from '@/Components/NigerRegionsMap';
import { useReveal } from '@/hooks/useReveal';
import { demoRegionIntensity } from '@/data/demo';

const nav: DarkNavItem[] = [
  { icon: Gauge, label: 'Tableau de bord', href: '/admin/dashboard' },
  { icon: Layers3, label: 'Files de vérification' },
  { icon: FolderKanban, label: 'Dossiers' },
  { icon: UsersRound, label: 'Évaluateurs' },
  { icon: BarChart3, label: 'Indicateurs' },
  { icon: Bell, label: 'Alertes' },
  { icon: Settings, label: 'Paramètres' },
  { icon: FileStack, label: 'Journal d’audit' },
];

const queues = [
  ['Complétude des dossiers', 'Vérifier la complétude documentaire et administrative', '152', 'blue'],
  ['Conformité réglementaire', 'Vérifier l’éligibilité et la conformité aux critères', '98', 'gold'],
  ['Évaluation technique initiale', 'Analyse technique et financière sommaire', '86', 'blue'],
  ['Comité d’admissibilité', 'Revue et décision d’admissibilité', '50', 'green'],
] as const;
const evaluators = [['Moussa K.',24,30],['Aïcha D.',18,30],['Ousmane B.',27,30],['Fatoumata T.',12,30],['Ibrahim S.',21,30]] as const;

export default function AdminDashboard() {
  const { ref: evaluatorsRef, visible: evaluatorsVisible } = useReveal<HTMLElement>();

  return (
    <DarkSidebarLayout items={nav} active="Tableau de bord" title="Back-office Administratif" subtitle="Présélection & Admissibilité — PIDUREM / ANSI" user="Aminata S.">
      <Head title="Back-office administratif — BIRNIN GOBE" />
      <div className="mx-auto max-w-[1650px] p-5 sm:p-7">
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
          <StatCard icon={FolderKanban} value="1 248" label="Dossiers soumis" hint="Campagne active" tone="blue" />
          <StatCard icon={Gauge} value="386" label="En présélection" hint="À traiter" tone="gold" />
          <StatCard icon={ClipboardCheck} value="214" label="Admissibles" hint="Validés" tone="blue" />
          <StatCard icon={CheckCircle2} value="648" label="Non admissibles" hint="Clôturés" tone="green" />
          <StatCard icon={AlertTriangle} value="12" label="Alertes actives" hint="Requièrent action" tone="red" />
        </div>

        <Reveal delay={60}><Card className="mt-4 flex flex-wrap gap-3 p-4">
          <label className="flex min-h-11 min-w-[260px] flex-1 items-center gap-2 rounded-xl border border-slate-200 px-3"><Search size={17} className="text-slate-400"/><input className="w-full border-0 bg-transparent text-sm outline-none" placeholder="Rechercher un dossier, porteur, référence…"/></label>
          {['Programme', 'Région', 'Thématique', 'Statut'].map((label) => <button key={label} className="focus-ring press-feedback min-h-11 min-w-36 rounded-xl border border-slate-200 bg-white px-4 text-left text-xs font-semibold text-slate-600"><span className="block text-[9px] uppercase tracking-wide text-slate-400">{label}</span>Tous</button>)}
          <button className="focus-ring press-feedback inline-flex min-h-11 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-600"><SlidersHorizontal size={16}/> Réinitialiser</button>
        </Card></Reveal>

        <div className="mt-4 grid gap-4 xl:grid-cols-[1.1fr_1.1fr_1.15fr]">
          <Reveal delay={100}><Card className="p-5"><SectionTitle title="Files de vérification" aside={<a href="#" className="text-xs font-bold text-brand-800">Voir tout</a>}/><div className="space-y-3">{queues.map(([name, desc, count, tone]) => <div key={name} className="hover-lift flex items-center gap-3 rounded-xl border border-slate-100 p-3"><div className={`grid h-10 w-10 place-items-center rounded-xl ${tone === 'gold' ? 'bg-amber-50 text-amber-600' : tone === 'green' ? 'bg-emerald-50 text-emerald-700' : 'bg-sky-50 text-sky-700'}`}><ShieldCheck size={18}/></div><div className="min-w-0 flex-1"><div className="text-sm font-bold text-slate-800">{name}</div><div className="truncate text-[11px] text-slate-400">{desc}</div></div><div className="text-right"><div className="text-lg font-black">{count}</div><div className="text-[10px] text-slate-400">À traiter</div></div></div>)}</div></Card></Reveal>
          <section ref={evaluatorsRef} className={`reveal ${evaluatorsVisible ? 'is-visible' : ''} surface-card p-5`} style={{ transitionDelay: '150ms' }}>
            <SectionTitle title="Charge des évaluateurs" aside={<a href="#" className="text-xs font-bold text-brand-800">Voir tout</a>}/>
            <div className="space-y-4">{evaluators.map(([name,current,capacity]) => {const pct=Math.round(current/capacity*100); return <div key={name} className="grid grid-cols-[1fr_55px_1fr] items-center gap-3"><div className="flex items-center gap-2"><div className="grid h-8 w-8 place-items-center rounded-full bg-slate-100 text-xs font-extrabold text-brand-800">{name.split(' ').map(s=>s[0]).join('')}</div><span className="text-sm font-semibold">{name}</span></div><div className="text-xs text-slate-500">{current}/{capacity}</div><div className="h-2 overflow-hidden rounded-full bg-slate-100"><div className={`animate-width ${pct >= 85 ? 'bg-red-500' : pct >= 70 ? 'bg-amber-500' : 'bg-emerald-500'} h-full rounded-full`} style={{width: evaluatorsVisible ? `${pct}%` : '0%'}}/></div></div>})}</div>
          </section>
          <Reveal delay={200}><Card className="p-5"><SectionTitle title="Répartition géographique" aside={<a href="#" className="text-xs font-bold text-brand-800">Voir tout</a>}/><div className="@container"><div className="grid items-center gap-4 @[20rem]:grid-cols-[104px_1fr]"><div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500 @[20rem]:block @[20rem]:space-y-2"><div><span className="mr-2 inline-block h-2.5 w-2.5 rounded-sm bg-brand-900"/>Très élevé</div><div><span className="mr-2 inline-block h-2.5 w-2.5 rounded-sm bg-emerald-600"/>Élevé</div><div><span className="mr-2 inline-block h-2.5 w-2.5 rounded-sm bg-gold-500"/>Moyen</div><div><span className="mr-2 inline-block h-2.5 w-2.5 rounded-sm bg-emerald-100"/>Faible</div></div><NigerRegionsMap intensities={demoRegionIntensity} className="mx-auto block h-auto w-full max-w-[260px]"/></div></div><div className="mt-2 text-right"><span className="text-[11px] text-slate-400">Dossiers par région</span><div className="text-2xl font-black">1 248</div></div></Card></Reveal>
        </div>

        <div className="mt-4 grid gap-4 xl:grid-cols-4">
          <Reveal delay={60}><Card className="p-5"><SectionTitle title="Indicateurs thématiques"/><Donut segments={['#075b39','#188759','#f3b71b','#2c74a4','#9db09f']} /><div className="mt-4 space-y-2 text-xs">{['Infrastructures 28%','Énergie 22%','Agriculture 18%','Éducation 12%','Autres 20%'].map(x=><div key={x} className="flex justify-between text-slate-600"><span>{x.split(' ')[0]}</span><strong>{x.split(' ').slice(1).join(' ')}</strong></div>)}</div></Card></Reveal>
          <Reveal delay={120}><Card className="p-5"><SectionTitle title="Statut des dossiers"/><Donut segments={['#f3b71b','#11835a','#184c72']} /><div className="mt-5 flex flex-wrap gap-2"><Pill tone="gold">Présélection 31%</Pill><Pill tone="green">Admissibles 17%</Pill><Pill tone="neutral">Autres 52%</Pill></div></Card></Reveal>
          <Reveal delay={180}><Card className="p-5"><SectionTitle title="Alertes récentes"/><div className="space-y-4 text-xs"><Alert tone="red" title="Dossier incomplet depuis +7 jours" meta="BG/2026/0001 — Tahoua"/><Alert tone="gold" title="Échéance évaluation dépassée" meta="BG/2026/0456 — Zinder"/><Alert tone="blue" title="Conflit d’intérêt détecté" meta="BG/2026/0789 — Niamey"/></div></Card></Reveal>
          <Reveal delay={240}><Card className="p-5"><SectionTitle title="Journal d’audit"/><div className="space-y-3 text-xs text-slate-600">{['Aminata S. a validé le dossier BG/2026/0432','Moussa K. a mis à jour un statut','Ousmane B. a ajouté une note','Fatoumata T. a consulté un document sensible','Ibrahim S. a clôturé un contrôle'].map((x,i)=><div key={x} className="flex gap-2"><span className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-800"/><div className="flex-1">{x}</div><span className="text-[10px] text-slate-400">{i+1}h</span></div>)}</div></Card></Reveal>
        </div>
      </div>
    </DarkSidebarLayout>
  );
}

function Donut({segments}:{segments:string[]}) { const step=100/segments.length; const stops=segments.map((c,i)=>`${c} ${i*step}% ${(i+1)*step}%`).join(','); return <div className="mx-auto h-32 w-32 rounded-full" style={{background:`conic-gradient(${stops})`}}><div className="relative left-1/2 top-1/2 h-16 w-16 -translate-x-1/2 -translate-y-1/2 rounded-full bg-white"/></div>; }
function Alert({tone,title,meta}:{tone:'red'|'gold'|'blue';title:string;meta:string}) { const cls={red:'bg-red-50 text-red-600',gold:'bg-amber-50 text-amber-600',blue:'bg-sky-50 text-sky-600'}[tone]; return <div className="flex gap-3"><div className={`grid h-8 w-8 shrink-0 place-items-center rounded-lg ${cls}`}><AlertTriangle size={15}/></div><div><div className="font-bold text-slate-700">{title}</div><div className="mt-0.5 text-[10px] text-slate-400">{meta}</div></div></div>; }
