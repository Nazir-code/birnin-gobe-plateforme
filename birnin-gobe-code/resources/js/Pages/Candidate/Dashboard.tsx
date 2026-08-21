import { Head, Link } from '@inertiajs/react';
import { ArrowRight, CalendarDays, FileText, Mail, UploadCloud } from 'lucide-react';
import { CandidateLayout } from '@/Layouts/CandidateLayout';
import { Card, Pill, SectionTitle } from '@/Components/Ui';
import { ProgressSteps, type Step } from '@/Components/ProgressSteps';
import { AnimatedCounter } from '@/Components/AnimatedCounter';
import { Reveal } from '@/Components/Reveal';
import { useReveal } from '@/hooks/useReveal';
import { candidateSteps } from '@/data/demo';

const steps: Step[] = candidateSteps.map((label, i) => ({ label, state: i < 3 ? 'done' : i === 3 ? 'active' : 'pending' }));
const messages = [
  ['Équipe BIRNIN GOBE', 'Webinaire d’information : préparez votre candidature comme un pro !', '24 mai 2026'],
  ['Équipe BIRNIN GOBE', 'Rappel : date limite de soumission', '20 mai 2026'],
  ['Mahamadou A.', 'Re : question sur le budget du projet', '18 mai 2026'],
];
const docs = [
  ['Présentation du projet', 'PDF • 1,2 Mo', 'Ajouté'],
  ['Budget prévisionnel', 'XLSX • 240 Ko', 'Ajouté'],
  ['Plan d’action détaillé', 'XLSX • 300 Ko', 'Ajouté'],
  ['Lettre de motivation', 'PDF • 450 Ko', 'À ajouter'],
];

const COMPLETION_PERCENT = 65;

export default function CandidateDashboard() {
  const { ref: statsRef, visible: statsVisible } = useReveal<HTMLElement>();

  return (
    <CandidateLayout active="Tableau de bord">
      <Head title="Tableau de bord candidat — BIRNIN GOBE" />
      <div className="mx-auto max-w-[1500px] p-5 sm:p-8">
        <div className="mb-6"><h1 className="text-3xl font-black tracking-tight text-slate-950">Bonjour, Amina 👋</h1><p className="mt-1 text-sm text-slate-500">Bienvenue dans votre espace candidat BIRNIN GOBE.</p></div>
        <section ref={statsRef} className={`surface-card reveal ${statsVisible ? 'is-visible' : ''} grid overflow-hidden md:grid-cols-3`}>
          <div className="p-6 md:border-r md:border-slate-200"><div className="text-sm font-extrabold">Statut de ma candidature</div><div className="mt-4 flex items-center gap-4"><div className="grid h-14 w-14 place-items-center rounded-full bg-amber-100 text-amber-600"><FileText /></div><div><div className="text-xs text-slate-500">Statut actuel</div><div className="text-2xl font-black">Brouillon</div><div className="text-xs text-slate-500">Dernière sauvegarde il y a quelques secondes</div></div></div></div>
          <div className="border-t border-slate-200 p-6 md:border-r md:border-t-0"><div className="text-sm font-extrabold">Complétude du dossier</div><div className="mt-4 text-4xl font-black text-brand-800">{statsVisible ? <AnimatedCounter value={`${COMPLETION_PERCENT}%`} /> : '0%'}</div><div className="mt-3 h-2.5 overflow-hidden rounded-full bg-slate-100"><div className="animate-width h-full rounded-full bg-brand-800" style={{ width: statsVisible ? `${COMPLETION_PERCENT}%` : '0%' }} /></div><p className="mt-3 text-xs leading-5 text-slate-500">Continuez ainsi : il reste quelques étapes avant la soumission.</p></div>
          <div className="border-t border-slate-200 p-6 md:border-t-0"><div className="text-sm font-extrabold">Date limite de soumission</div><div className="mt-4 flex items-center gap-4"><div className="grid h-14 w-14 place-items-center rounded-full bg-emerald-50 text-brand-800"><CalendarDays /></div><div><div className="text-2xl font-black text-brand-800">30 juin 2026</div><div className="mt-1 text-sm text-slate-600">Il vous reste <strong className="text-gold-600">24 jours</strong></div></div></div></div>
        </section>

        <Reveal delay={80}><Card className="mt-5 p-6">
          <SectionTitle title="Progression de ma candidature (9 étapes)" aside={<Link className="text-xs font-bold text-brand-800" href="/candidate/application/challenge">Continuer <ArrowRight className="inline" size={14}/></Link>} />
          <ProgressSteps steps={steps} />
        </Card></Reveal>

        <div className="mt-5 grid gap-5 xl:grid-cols-2">
          <Reveal delay={140}><Card className="p-6">
            <SectionTitle title="Messages récents" aside={<a href="#" className="text-xs font-bold text-brand-800">Voir tout</a>} />
            <div className="divide-y divide-slate-100">{messages.map(([from, subject, date], i) => <div key={subject} className="flex gap-4 py-4 first:pt-0"><div className={`grid h-11 w-11 shrink-0 place-items-center rounded-full ${i === 2 ? 'bg-amber-100 text-amber-700' : 'border border-brand-700 bg-white text-brand-800'}`}>{i === 2 ? 'MA' : <Mail size={18}/>}</div><div className="min-w-0 flex-1"><div className="flex items-start justify-between gap-3"><div className="font-bold text-slate-800">{from}</div><div className="whitespace-nowrap text-[11px] text-slate-400">{date}</div></div><div className="mt-0.5 truncate text-sm font-semibold text-slate-700">{subject}</div><div className="mt-1 truncate text-xs text-slate-400">Consultez le détail du message depuis votre centre de notifications…</div></div></div>)}</div>
          </Card></Reveal>
          <Reveal delay={200}><Card className="p-6">
            <SectionTitle title="Mes documents" aside={<a href="#" className="text-xs font-bold text-brand-800">Voir tout</a>} />
            <div className="space-y-3">{docs.map(([name, meta, status]) => <div key={name} className="flex items-center gap-3"><div className="grid h-10 w-10 place-items-center rounded-lg bg-slate-50 text-brand-800"><FileText size={18}/></div><div className="min-w-0 flex-1"><div className="truncate text-sm font-semibold text-slate-800">{name}</div><div className="text-[11px] text-slate-400">{meta}</div></div><Pill tone={status === 'Ajouté' ? 'green' : 'gold'}>{status}</Pill></div>)}</div>
            <button className="focus-ring press-feedback mt-5 flex min-h-14 w-full items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 bg-slate-50/40 text-sm font-bold text-slate-700 hover:bg-brand-50"><UploadCloud size={18}/> Ajouter un document</button>
          </Card></Reveal>
        </div>
      </div>
    </CandidateLayout>
  );
}
