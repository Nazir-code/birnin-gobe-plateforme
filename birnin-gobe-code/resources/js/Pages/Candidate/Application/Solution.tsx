import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Check, ChevronDown, Cpu, Lightbulb, Sparkles, UserCircle2 } from 'lucide-react';
import { CandidateLayout } from '@/Layouts/CandidateLayout';
import { Card } from '@/Components/Ui';
import { Reveal } from '@/Components/Reveal';
import { SaveIndicator } from '@/Components/SaveIndicator';
import { SaveConfirmation } from '@/Components/SaveConfirmation';
import { SectionStepsAside, type SectionStep } from '@/Components/SectionStepsAside';
import { BarreNavigation, Champ, DejaRenseigne, EnteteSection, EtatSection, Groupe, Redaction, saisie } from '@/Components/SectionForm';
import { useAuthUser } from '@/hooks/useAuth';
import { useAutosave } from '@/hooks/useAutosave';

/** Champs reellement persistes par la section « Solution » (etape 5). */
type Answers = {
  solution_name: string;
  value_proposition: string;
  key_features: string;
  usage_scenario: string;
  innovation: string;
  maturity_stage: string;
  technologies: string;
  interoperability: string;
};

type Option = { value: string; label: string };

type Props = {
  steps: SectionStep[];
  section: { key: string; label: string; position: number; total: number; completedAt: string | null };
  answers: Answers;
  /** Le defi de l'etape 4 : affiche pour etre relu, jamais recopie. */
  known: { mainChallenge: string | null; challengeUrl: string };
  maturityStages: Option[];
  requiredFields: (keyof Answers)[];
  shortTextMax: number;
  longTextMax: number;
  saveUrl: string;
  previousUrl: string | null;
  nextUrl: string | null;
};

export default function Solution({
  steps, section, answers, known, maturityStages, requiredFields,
  shortTextMax, longTextMax, saveUrl, previousUrl, nextUrl,
}: Props) {
  const user = useAuthUser();
  const [values, setValues] = useState<Answers>(answers);

  // `values` vient de `useState` : son identite ne change qu'a la saisie, donc
  // le minuteur de la sauvegarde automatique n'est relance que sur une vraie
  // modification, pas a chaque rendu.
  const { state, savedAt, errors, confirmation, flush, save, acquitter } = useAutosave<Answers>(saveUrl, values);

  const set = (champ: keyof Answers) => (valeur: string) => setValues((v) => ({ ...v, [champ]: valeur }));
  const requis = (champ: keyof Answers) => requiredFields.includes(champ);
  const manquants = requiredFields.filter((champ) => values[champ].trim() === '').length;

  return (
    <CandidateLayout active="Ma candidature" topSlot={<div className="hidden md:flex"><SaveIndicator state={state} savedAt={savedAt} /></div>}>
      <Head title={`${section.label} — Ma candidature BIRNIN GOBE`} />
      <div className="grid min-h-[calc(100vh-76px)] lg:grid-cols-[260px_1fr]">
        <SectionStepsAside steps={steps} activeKey={section.key} />
        <div className="min-w-0 bg-white px-5 py-8 sm:px-8 xl:px-12">
          <div className="mx-auto max-w-[1220px]">
            <div className="mb-6 flex items-start justify-between gap-4">
              <EnteteSection
                position={section.position}
                total={section.total}
                titre={section.label}
                intro="Décrivez la solution que vous proposez face au défi de l’étape précédente : ce qu’elle apporte, ce qu’elle fait, et où elle en est aujourd’hui."
              />
              {user ? <div className="hidden items-center gap-2 text-slate-700 sm:flex"><UserCircle2 size={34} className="text-brand-800" /><span className="text-sm font-semibold">Bonjour, {user.name.split(' ')[0]}</span><ChevronDown size={15} /></div> : null}
            </div>
            <div className="md:hidden"><SaveIndicator state={state} savedAt={savedAt} /></div>

            <div className="mt-4 grid gap-6 xl:grid-cols-[1fr_340px]">
              <div className="min-w-0 space-y-6">
                <Reveal><Card className="p-6 sm:p-7">
                  <Groupe icone={<Sparkles size={18} />} titre="Votre solution" aide="Ce que vous proposez, et pourquoi cela vaut la peine d’être fait." />

                  <Champ nom="solution_name" label="Comment s’appelle votre solution ?" aide="Un nom court, celui qui figurera sur votre dossier." requis={requis('solution_name')} erreur={errors.solution_name}>
                    <input id="solution_name" name="solution_name" type="text" maxLength={shortTextMax} autoComplete="off"
                      className={saisie(errors.solution_name)} value={values.solution_name}
                      onChange={(e) => set('solution_name')(e.target.value)} onBlur={flush} />
                  </Champ>

                  <Redaction nom="value_proposition" label="Quelle est votre proposition de valeur ?"
                    aide="En quoi votre solution change concrètement la situation décrite à l’étape précédente."
                    requis={requis('value_proposition')} erreur={errors.value_proposition} max={longTextMax}
                    valeur={values.value_proposition} onChange={set('value_proposition')} onBlur={flush} />

                  <Redaction nom="key_features" label="Quelles sont ses fonctionnalités principales ?"
                    aide="Les trois ou quatre choses que votre solution permet de faire."
                    requis={requis('key_features')} erreur={errors.key_features} max={longTextMax}
                    valeur={values.key_features} onChange={set('key_features')} onBlur={flush} />

                  <Redaction nom="usage_scenario" label="Racontez un scénario d’usage"
                    aide="Une situation réelle, du début à la fin : qui s’en sert, quand, et ce qui se passe."
                    requis={requis('usage_scenario')} erreur={errors.usage_scenario} max={longTextMax}
                    valeur={values.usage_scenario} onChange={set('usage_scenario')} onBlur={flush} />

                  <Redaction nom="innovation" label="Qu’est-ce qui la distingue de ce qui existe déjà ?"
                    aide="Les pratiques ou outils actuels, et ce que vous faites autrement."
                    requis={requis('innovation')} erreur={errors.innovation} max={longTextMax}
                    valeur={values.innovation} onChange={set('innovation')} onBlur={flush} />
                </Card></Reveal>

                <Reveal delay={60}><Card className="p-6 sm:p-7">
                  <Groupe icone={<Cpu size={18} />} titre="Maturité et technique" aide="Où en est votre solution, et sur quoi elle repose." />

                  <Champ nom="maturity_stage" label="À quel stade en êtes-vous ?" aide="Choisissez le stade le plus proche de votre situation réelle. Aucun stade n’est éliminatoire." requis={requis('maturity_stage')} erreur={errors.maturity_stage}>
                    <select id="maturity_stage" name="maturity_stage" className={saisie(errors.maturity_stage)} value={values.maturity_stage}
                      onChange={(e) => { set('maturity_stage')(e.target.value); }} onBlur={flush}>
                      <option value="">Sélectionnez une option</option>
                      {maturityStages.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                  </Champ>

                  <Redaction nom="technologies" label="Sur quelles technologies repose-t-elle ?"
                    aide="Outils, langages, matériel, canaux — SMS et USSD comptent autant qu’une application."
                    requis={requis('technologies')} erreur={errors.technologies} max={longTextMax}
                    valeur={values.technologies} onChange={set('technologies')} onBlur={flush} />

                  <Redaction nom="interoperability" label="Doit-elle dialoguer avec d’autres systèmes ?"
                    aide="Services d’une collectivité, registre national, opérateur mobile… Si aucun échange n’est prévu, laissez vide."
                    requis={requis('interoperability')} erreur={errors.interoperability} max={longTextMax}
                    valeur={values.interoperability} onChange={set('interoperability')} onBlur={flush} />
                </Card></Reveal>

                <BarreNavigation precedentUrl={previousUrl} suivantUrl={nextUrl} onFlush={flush} onSave={save} />
              </div>

              <div className="space-y-5">
                <Reveal delay={80}>
                  <DejaRenseigne titre="Le défi que vous traitez" lien={{ href: known.challengeUrl, label: 'Modifier à l’étape Défi' }}>
                    {known.mainChallenge
                      ? <p className="whitespace-pre-line">{known.mainChallenge}</p>
                      : <p>Vous n’avez pas encore décrit votre défi. Votre solution se lira mieux une fois l’étape 4 remplie.</p>}
                  </DejaRenseigne>
                </Reveal>

                <Reveal delay={140}><EtatSection manquants={manquants} /></Reveal>

                <Reveal delay={200}>
                  <Card className="self-start border-amber-200 bg-[#fffdf5] p-6">
                    <div className="flex items-center gap-3">
                      <div className="grid h-10 w-10 place-items-center rounded-full bg-amber-100 text-amber-700"><Lightbulb size={20} /></div>
                      <h2 className="text-base font-black leading-tight text-brand-950">Conseils</h2>
                    </div>
                    <ul className="mt-4 space-y-3 text-xs leading-5 text-slate-600">
                      <li><strong className="text-slate-800">Restez concret.</strong> Décrivez ce que fait la solution, pas ce qu’elle pourrait faire un jour.</li>
                      <li><strong className="text-slate-800">Dites où vous en êtes vraiment.</strong> Une idée bien posée vaut mieux qu’un prototype annoncé qui n’existe pas.</li>
                      <li><strong className="text-slate-800">Pensez au terrain.</strong> Faible débit, coupures, téléphones simples : dites comment vous y faites face.</li>
                    </ul>
                  </Card>
                </Reveal>
              </div>
            </div>

            <div className="mt-6 flex items-center gap-2 text-xs text-slate-500"><Check className="text-brand-700" size={16} /> Vos données sont sécurisées et confidentielles.</div>
          </div>
        </div>
      </div>
      <SaveConfirmation confirmation={confirmation} onAcquitter={acquitter} />
    </CandidateLayout>
  );
}
