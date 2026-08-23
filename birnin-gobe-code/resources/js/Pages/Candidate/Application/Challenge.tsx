import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Check, ChevronDown, Download, Lightbulb, Save, UserCircle2 } from 'lucide-react';
import { CandidateLayout } from '@/Layouts/CandidateLayout';
import { Button, Card } from '@/Components/Ui';
import { Reveal } from '@/Components/Reveal';
import { SaveIndicator } from '@/Components/SaveIndicator';
import { SectionStepsAside, type SectionStep } from '@/Components/SectionStepsAside';
import { useAuthUser } from '@/hooks/useAuth';
import { useAutosave } from '@/hooks/useAutosave';

const advice = [
  ['Soyez spécifique', 'Décrivez un défi précis, pas un problème trop large.'],
  ['Appuyez-vous sur des faits', 'Utilisez des données, des chiffres ou des exemples concrets.'],
  ["Concentrez-vous sur l’essentiel", 'Expliquez pourquoi ce défi est important à résoudre.'],
  ['Pensez aux bénéficiaires', 'Mettez en avant les personnes les plus touchées.'],
  ['Restez clair et concis', 'Utilisez un langage simple et direct.'],
];

/** Champs réellement persistés, dans l’ordre de l’écran. */
type Answers = {
  main_challenge: string;
  affected_people: string;
  location: string;
  root_causes: string;
};

type Props = {
  steps: SectionStep[];
  section: { key: string; label: string; position: number; total: number; completedAt: string | null };
  answers: Record<keyof Answers, string | null>;
  regions: { value: string; label: string }[];
  maxLength: number;
  saveUrl: string;
  previousUrl: string | null;
};

export default function Challenge({ steps, section, answers, regions, maxLength, saveUrl, previousUrl }: Props) {
  const user = useAuthUser();
  const [values, setValues] = useState<Answers>({
    main_challenge: answers.main_challenge ?? '',
    affected_people: answers.affected_people ?? '',
    location: answers.location ?? '',
    root_causes: answers.root_causes ?? '',
  });

  // `values` vient de `useState` : son identite ne change qu'a la saisie, donc
  // le minuteur de la sauvegarde automatique n'est relance que sur une vraie
  // modification, pas a chaque rendu.
  const { state, savedAt, errors, flush } = useAutosave<Answers>(saveUrl, values);

  const set = (champ: keyof Answers) => (valeur: string) => setValues((v) => ({ ...v, [champ]: valeur }));

  return (
    <CandidateLayout active="Ma candidature" topSlot={<div className="hidden md:flex"><SaveIndicator state={state} savedAt={savedAt} /></div>}>
      <Head title={`${section.label} — Ma candidature BIRNIN GOBE`} />
      <div className="grid min-h-[calc(100vh-76px)] lg:grid-cols-[260px_1fr]">
        <SectionStepsAside steps={steps} activeKey={section.key} />
        <div className="min-w-0 bg-white px-5 py-8 sm:px-8 xl:px-12">
          <div className="mx-auto max-w-[1220px]">
            <div className="mb-6 flex items-start justify-between gap-4">
              <div>
                <div className="text-xs font-black uppercase tracking-[.15em] text-brand-800">Étape {section.position} sur {section.total}</div>
                <h1 className="mt-2 text-4xl font-black tracking-tight text-brand-950">{section.label}</h1>
                <div className="mt-3 h-1.5 w-14 rounded-full bg-gold-500"/>
                <p className="mt-5 text-slate-600">Décrivez le défi que vous souhaitez adresser à travers votre projet.</p>
              </div>
              {user ? <div className="hidden items-center gap-2 text-slate-700 sm:flex"><UserCircle2 size={34} className="text-brand-800"/><span className="text-sm font-semibold">Bonjour, {user.name.split(' ')[0]}</span><ChevronDown size={15}/></div> : null}
            </div>
            <div className="md:hidden"><SaveIndicator state={state} savedAt={savedAt} /></div>
            <div className="mt-4 grid gap-6 xl:grid-cols-[1fr_340px]">
              <Reveal><Card className="p-6 sm:p-7">
                <TextField index={1} name="main_challenge" label="Quel est le défi principal que vous souhaitez résoudre ?" placeholder="Décrivez clairement le défi en quelques phrases." max={maxLength} value={values.main_challenge} onChange={set('main_challenge')} onBlur={flush} error={errors.main_challenge} />
                <TextField index={2} name="affected_people" label="Qui est le plus affecté par ce défi ?" placeholder="Décrivez les personnes ou communautés concernées." max={maxLength} value={values.affected_people} onChange={set('affected_people')} onBlur={flush} error={errors.affected_people} />
                <label className="mb-6 block" htmlFor="location">
                  <span className="mb-2 block text-sm font-extrabold text-slate-800">3. Où ce défi se pose-t-il principalement ? <span className="text-red-500">*</span></span>
                  <span className="mb-2 block text-xs text-slate-500">Précisez la région concernée.</span>
                  <select id="location" name="location" className="focus-ring min-h-12 w-full rounded-lg border border-slate-300 bg-white px-4 text-sm text-slate-800" value={values.location} onChange={(e) => { set('location')(e.target.value); }} onBlur={flush}>
                    <option value="">Sélectionnez une option</option>
                    {regions.map((region) => <option key={region.value} value={region.value}>{region.label}</option>)}
                  </select>
                  {errors.location ? <span className="mt-1 block text-xs font-semibold text-red-600">{errors.location}</span> : null}
                </label>
                <TextField index={4} name="root_causes" label="Quelles sont les causes profondes de ce défi ?" placeholder="Expliquez les facteurs à l’origine de ce défi." max={maxLength} value={values.root_causes} onChange={set('root_causes')} onBlur={flush} error={errors.root_causes} />
                <div className="mt-2 flex flex-wrap items-center justify-between gap-3">
                  <div className="flex flex-wrap items-center gap-3">
                    {/* Revenir en arriere sans rien perdre : la sauvegarde est
                        declenchee avant la navigation, et les reponses viennent
                        de toute facon de la base au retour. */}
                    {previousUrl ? (
                      <Link href={previousUrl} onClick={flush} className="focus-ring press-feedback inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-brand-900/35 bg-white px-5 text-sm font-bold text-brand-900 transition-colors hover:bg-brand-50" data-testid="precedent">
                        <ArrowLeft size={16} /> Précédent
                      </Link>
                    ) : null}
                    <Button variant="ghost" type="button" onClick={flush}><Save size={17}/> Enregistrer</Button>
                  </div>
                  {/* Les sections suivantes ne sont pas encore developpees : un
                      bouton qui ne mene nulle part vaut moins qu'un bouton qui
                      dit pourquoi il est inactif. */}
                  <Button variant="secondary" className="min-w-44" disabled title="Les étapes suivantes seront ouvertes dans une prochaine version.">Suivant <span aria-hidden>→</span></Button>
                </div>
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

function TextField({ index, name, label, placeholder, max, value, onChange, onBlur, error }: { index: number; name: string; label: string; placeholder: string; max: number; value: string; onChange: (v: string) => void; onBlur: () => void; error?: string }) {
  return (
    <label className="mb-6 block" htmlFor={name}>
      <span className="mb-2 block text-sm font-extrabold text-slate-800">{index}. {label} <span className="text-red-500">*</span></span>
      <textarea
        id={name}
        name={name}
        // `maxLength` epargne une frappe inutile au candidat ; la limite qui
        // fait foi est celle de la FormRequest, cote serveur.
        maxLength={max}
        className={`focus-ring mt-0 min-h-24 w-full resize-y rounded-lg border px-4 py-3 text-sm text-slate-800 transition-shadow placeholder:text-slate-400 ${error ? 'border-red-400' : 'border-slate-300'}`}
        placeholder={placeholder}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onBlur={onBlur}
      />
      <span className="mt-1 block text-right text-[11px] text-slate-400">{value.length} / {max}</span>
      {error ? <span className="mt-1 block text-xs font-semibold text-red-600">{error}</span> : null}
    </label>
  );
}
