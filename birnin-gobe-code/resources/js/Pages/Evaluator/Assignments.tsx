import { Head } from '@inertiajs/react';
import { AlertTriangle, BriefcaseBusiness, CircleHelp, FileCheck2, Gauge, LogOut, Search, ShieldAlert, Star, UserRound } from 'lucide-react';
import { DarkSidebarLayout, type DarkNavItem } from '@/Layouts/DarkSidebarLayout';
import { Button, Card, Pill } from '@/Components/Ui';
import { Reveal } from '@/Components/Reveal';

const nav: DarkNavItem[] = [
  { icon: Gauge, label: 'Tableau de bord' },
  { icon: BriefcaseBusiness, label: 'Mes dossiers', href: '/evaluator/assignments' },
  { icon: FileCheck2, label: 'Évaluations' },
  { icon: ShieldAlert, label: 'Conflits signalés' },
  { icon: CircleHelp, label: 'Ressources' },
  { icon: UserRound, label: 'Mon profil' },
  { icon: LogOut, label: 'Déconnexion' },
];

const applications = [
  ['BG-2026-087', 'AgriTech Solutions', 'Gestion urbaine'],
  ['BG-2026-102', 'Sunu Digital', 'Services numériques'],
  ['BG-2026-115', 'EcoPack Niger', 'Environnement'],
  ['BG-2026-129', 'Santé & Nous', 'Santé communautaire'],
  ['BG-2026-138', 'Kaara Fashion', 'Mode & artisanat'],
];

const criteria = [
  ['Pertinence par rapport au défi', 20, 'Compréhension du problème et alignement avec la thématique'],
  ['Innovation', 15, 'Originalité utile et différenciation de la solution'],
  ['Faisabilité technique', 15, 'Architecture, maturité, sécurité et dépendances'],
  ['Viabilité économique / institutionnelle', 10, 'Coûts, maintenance, adoption et soutenabilité'],
  ['Impact et résilience', 15, 'Résultats mesurables et bénéfices attendus'],
  ['Durabilité et mise à l’échelle', 10, 'Interopérabilité, réplication et appropriation'],
  ['Équipe', 10, 'Complémentarité, expérience et disponibilité'],
  ['Inclusion et ancrage territorial', 5, 'Accessibilité, réalités locales et publics visés'],
] as const;

export default function Assignments() {
  return (
    <DarkSidebarLayout items={nav} active="Mes dossiers" title="Mes dossiers affectés" subtitle="Évaluation indépendante — campagne BIRNIN GOBE 2026" user="Mouhamadou Kane" headerActions={<Button variant="ghost" className="hidden sm:inline-flex"><CircleHelp size={16}/> Besoin d’aide ?</Button>}>
      <Head title="Mes dossiers — Évaluation BIRNIN GOBE" />
      <div className="grid min-h-[calc(100vh-88px)] xl:grid-cols-[330px_1fr]">
        <aside className="border-r border-slate-200 bg-white p-5">
          <div className="mb-4"><h2 className="text-lg font-black">Dossiers affectés (8)</h2><p className="mt-1 text-xs leading-5 text-slate-500">Vous devez évaluer tous les dossiers qui vous sont assignés.</p></div>
          <label className="flex min-h-11 items-center gap-2 rounded-xl border border-slate-200 px-3"><Search size={16} className="text-slate-400"/><input className="w-full border-0 bg-transparent text-sm outline-none" placeholder="Rechercher un dossier…"/></label>
          <div className="mt-4 space-y-3">{applications.map(([ref,name,theme],index)=><button key={ref} className={`focus-ring press-feedback w-full rounded-xl border p-4 text-left transition-colors ${index === 0 ? 'border-brand-700 bg-brand-50' : 'border-slate-200 bg-white hover:bg-slate-50'}`}><div className="flex items-start justify-between"><div><div className="text-xs font-black text-brand-900">{ref}</div><div className="mt-1 text-sm font-bold text-slate-800">{name}</div><div className="mt-1 text-[11px] text-slate-400">{theme}</div></div><span className="text-slate-400">›</span></div><div className="mt-2"><Pill tone="gold">À évaluer</Pill></div></button>)}</div>
        </aside>

        <main className="min-w-0 p-5 sm:p-7">
          <div className="mx-auto max-w-[1100px]">
            <Reveal className="flex flex-wrap items-start justify-between gap-4"><div><div className="flex items-center gap-2"><h2 className="text-lg font-black text-brand-900">BG-2026-087</h2><Pill tone="green">À évaluer</Pill></div><div className="mt-2 text-lg font-extrabold text-slate-900">AgriTech Solutions</div><div className="mt-1 text-xs text-slate-500">Gestion urbaine et transformation numérique</div></div><Button variant="ghost">Voir le dossier complet ↗</Button></Reveal>

            <Reveal delay={60} className="mt-5 flex flex-wrap items-center gap-4 rounded-2xl border border-amber-300 bg-amber-50 px-5 py-4"><div className="grid h-10 w-10 place-items-center rounded-full bg-amber-100 text-amber-700"><AlertTriangle size={20}/></div><div className="min-w-0 flex-1"><div className="text-sm font-extrabold text-slate-800">Déclaration de conflit d’intérêts</div><div className="mt-1 text-xs text-slate-600">Veuillez déclarer tout conflit avant de commencer l’évaluation de ce dossier.</div></div><Button variant="ghost">Déclarer un conflit</Button></Reveal>

            <Reveal delay={120}><Card className="mt-5 overflow-hidden">
              <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4"><h3 className="text-lg font-black">Grille d’évaluation (100 points)</h3><div className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-extrabold">Total <span className="ml-2">0 / 100</span></div></div>
              <div className="overflow-x-auto soft-scrollbar">
                <table className="w-full min-w-[800px] text-left">
                  <thead className="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Critères d’évaluation</th><th className="px-4 py-3">Pondération</th><th className="px-4 py-3">Note (0–5)</th><th className="px-4 py-3 text-right">Score pondéré</th></tr></thead>
                  <tbody className="divide-y divide-slate-100">{criteria.map(([name,weight,desc])=><tr key={name} className="align-top transition-colors hover:bg-slate-50/70"><td className="px-5 py-4"><div className="flex gap-3"><div className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-emerald-50 text-brand-800"><Star size={15}/></div><div><div className="text-sm font-extrabold text-slate-800">{name}</div><div className="mt-0.5 text-[11px] leading-4 text-slate-400">{desc}</div></div></div></td><td className="px-4 py-4 text-sm font-bold text-slate-600">{weight} pts</td><td className="px-4 py-4"><input type="number" min="0" max="5" defaultValue="0" className="focus-ring h-10 w-20 rounded-lg border border-slate-300 px-3 text-sm transition-shadow"/></td><td className="px-4 py-4 text-right text-sm font-bold text-slate-700">0,00</td></tr>)}</tbody>
                </table>
              </div>
              <div className="border-t border-slate-200 p-5"><label className="block"><span className="text-sm font-extrabold text-slate-800">Commentaires obligatoires</span><span className="mt-1 block text-xs text-slate-500">Vos commentaires doivent justifier les scores et recommandations.</span><textarea className="focus-ring mt-3 min-h-28 w-full rounded-xl border border-slate-300 p-4 text-sm transition-shadow" placeholder="Saisissez vos commentaires ici…"/></label><div className="mt-5 flex flex-wrap gap-3"><Button variant="danger">Signaler un conflit sur ce dossier</Button><Button variant="ghost" className="ml-auto">Enregistrer en brouillon</Button><Button variant="secondary">🔒 Verrouiller l’évaluation</Button></div></div>
            </Card></Reveal>
          </div>
        </main>
      </div>
    </DarkSidebarLayout>
  );
}
