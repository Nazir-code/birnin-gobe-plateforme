import { Head } from '@inertiajs/react';
import { Check, ChevronDown, Cloud, Download, Headphones, Lightbulb, Save, UserCircle2 } from 'lucide-react';
import { CandidateLayout } from '@/Layouts/CandidateLayout';
import { Button, Card } from '@/Components/Ui';
import { Reveal } from '@/Components/Reveal';
import { candidateSteps } from '@/data/demo';

const advice = [
  ['Soyez spécifique', 'Décrivez un défi précis, pas un problème trop large.'],
  ['Appuyez-vous sur des faits', 'Utilisez des données, des chiffres ou des exemples concrets.'],
  ["Concentrez-vous sur l’essentiel", 'Expliquez pourquoi ce défi est important à résoudre.'],
  ['Pensez aux bénéficiaires', 'Mettez en avant les personnes les plus touchées.'],
  ['Restez clair et concis', 'Utilisez un langage simple et direct.'],
];

export default function Challenge() {
  return (
    <CandidateLayout active="Ma candidature" topSlot={<div className="hidden items-center gap-2 text-xs text-brand-800 md:flex"><Cloud size={18}/><div><strong>Enregistré automatiquement</strong><br/><span className="text-slate-400">il y a quelques secondes</span></div></div>}>
      <Head title="Défi — Ma candidature BIRNIN GOBE" />
      <div className="grid min-h-[calc(100vh-76px)] lg:grid-cols-[260px_1fr]">
        <aside className="hidden bg-gradient-to-b from-brand-800 to-brand-950 px-6 py-8 text-white lg:block">
          <div className="space-y-1.5">
            {candidateSteps.map((step, index) => {
              const done = index < 3; const active = index === 3;
              return <div key={step} className={`relative flex min-h-12 items-center gap-3 rounded-xl px-3 transition-colors duration-[250ms] ${active ? 'bg-white/10 font-extrabold' : 'text-white/85'}`}><div className={`grid h-8 w-8 shrink-0 place-items-center rounded-full border text-xs font-black transition-colors duration-[250ms] ${done ? 'border-white bg-white text-brand-900' : active ? 'border-gold-500 bg-gold-500 text-slate-950' : 'border-white/45'}`}>{done ? <Check size={14}/> : index + 1}</div><span className="text-sm">{step}</span></div>
            })}
          </div>
          <div className="mt-10 rounded-2xl border border-white/25 p-4"><div className="flex gap-3"><Headphones size={23}/><div><div className="text-sm font-extrabold">Besoin d’aide ?</div><p className="mt-1 text-xs leading-5 text-white/75">Consultez notre FAQ ou contactez l’assistance.</p></div></div></div>
        </aside>
        <div className="min-w-0 bg-white px-5 py-8 sm:px-8 xl:px-12">
          <div className="mx-auto max-w-[1220px]">
            <div className="mb-6 flex items-start justify-between gap-4"><div><div className="text-xs font-black uppercase tracking-[.15em] text-brand-800">Étape 4 sur 9</div><h1 className="mt-2 text-4xl font-black tracking-tight text-brand-950">Défi</h1><div className="mt-3 h-1.5 w-14 rounded-full bg-gold-500"/><p className="mt-5 text-slate-600">Décrivez le défi que vous souhaitez adresser à travers votre projet.</p></div><div className="hidden items-center gap-2 text-slate-700 sm:flex"><UserCircle2 size={34} className="text-brand-800"/><span className="text-sm font-semibold">Bonjour, Aïssata</span><ChevronDown size={15}/></div></div>
            <div className="grid gap-6 xl:grid-cols-[1fr_340px]">
              <Reveal><Card className="p-6 sm:p-7">
                <FormField index="1" label="Quel est le défi principal que vous souhaitez résoudre ?" placeholder="Décrivez clairement le défi en quelques phrases." max="500" />
                <FormField index="2" label="Qui est le plus affecté par ce défi ?" placeholder="Décrivez les personnes ou communautés concernées." max="500" />
                <label className="mb-6 block"><span className="mb-2 block text-sm font-extrabold text-slate-800">3. Où ce défi se pose-t-il principalement ? <span className="text-red-500">*</span></span><span className="mb-2 block text-xs text-slate-500">Précisez la région, la ville ou le contexte.</span><div className="flex min-h-12 items-center justify-between rounded-lg border border-slate-300 px-4 text-sm text-slate-400">Sélectionnez une option <ChevronDown size={17}/></div></label>
                <FormField index="4" label="Quelles sont les causes profondes de ce défi ?" placeholder="Expliquez les facteurs à l’origine de ce défi." max="500" />
                <div className="mt-2 flex flex-wrap justify-between gap-3"><Button variant="ghost"><Save size={17}/> Enregistrer</Button><Button variant="secondary" className="min-w-44">Suivant <span aria-hidden>→</span></Button></div>
              </Card></Reveal>
              <Reveal delay={100}><Card className="self-start border-amber-200 bg-[#fffdf5] p-6">
                <div className="flex items-center gap-3"><div className="grid h-10 w-10 place-items-center rounded-full bg-amber-100 text-amber-700"><Lightbulb size={22}/></div><h2 className="text-xl font-black text-brand-950">Conseils</h2></div>
                <div className="mt-5 divide-y divide-amber-100">{advice.map(([title, text]) => <div key={title} className="flex gap-3 py-4"><div className="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full bg-amber-50 text-amber-700"><Lightbulb size={16}/></div><div><div className="text-sm font-extrabold text-slate-800">{title}</div><p className="mt-1 text-xs leading-5 text-slate-600">{text}</p></div></div>)}</div>
                <button className="focus-ring press-feedback mt-5 flex w-full items-center gap-3 rounded-xl border border-amber-200 bg-white p-4 text-left transition-colors hover:bg-amber-50/60"><Download className="text-brand-800"/><span><strong className="block text-sm text-brand-900">Guide : Identifier un défi</strong><span className="text-xs text-slate-500">Télécharger le guide (PDF)</span></span></button>
              </Card></Reveal>
            </div>
            <div className="mt-6 flex items-center gap-2 text-xs text-slate-500"><Check className="text-brand-700" size={16}/> Vos données sont sécurisées et confidentielles.</div>
          </div>
        </div>
      </div>
    </CandidateLayout>
  );
}

function FormField({ index, label, placeholder, max }: { index: string; label: string; placeholder: string; max: string }) {
  return <label className="mb-6 block"><span className="mb-2 block text-sm font-extrabold text-slate-800">{index}. {label} <span className="text-red-500">*</span></span><textarea className="focus-ring mt-0 min-h-24 w-full resize-y rounded-lg border border-slate-300 px-4 py-3 text-sm text-slate-800 transition-shadow placeholder:text-slate-400" placeholder={placeholder}/><span className="mt-1 block text-right text-[11px] text-slate-400">0 / {max}</span></label>;
}
