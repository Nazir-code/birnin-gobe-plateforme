import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Check, ChevronDown, Coins, HeartHandshake, Info, Target, UserCircle2 } from 'lucide-react';
import { CandidateLayout } from '@/Layouts/CandidateLayout';
import { Card } from '@/Components/Ui';
import { Reveal } from '@/Components/Reveal';
import { SaveIndicator } from '@/Components/SaveIndicator';
import { SectionStepsAside, type SectionStep } from '@/Components/SectionStepsAside';
import { BarreNavigation, DejaRenseigne, EnteteSection, EtatSection, Groupe, Redaction } from '@/Components/SectionForm';
import { useAuthUser } from '@/hooks/useAuth';
import { useAutosave } from '@/hooks/useAutosave';

/** Champs reellement persistes par la section « Impact / viabilite » (etape 6). */
type Answers = {
  beneficiaries: string;
  expected_results: string;
  impact_indicators: string;
  inclusion_measures: string;
  resilience_contribution: string;
  business_model: string;
  sustainability: string;
  scaling_plan: string;
};

type Props = {
  steps: SectionStep[];
  section: { key: string; label: string; position: number; total: number; completedAt: string | null };
  answers: Answers;
  /** La solution de l'etape 5 : rappelee, jamais redemandee. */
  known: { solutionName: string | null; solutionUrl: string };
  requiredFields: (keyof Answers)[];
  longTextMax: number;
  saveUrl: string;
  previousUrl: string | null;
  nextUrl: string | null;
};

export default function Impact({
  steps, section, answers, known, requiredFields, longTextMax, saveUrl, previousUrl, nextUrl,
}: Props) {
  const user = useAuthUser();
  const [values, setValues] = useState<Answers>(answers);

  const { state, savedAt, errors, flush, save } = useAutosave<Answers>(saveUrl, values);

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
                intro="Ce que votre solution changera, pour qui, et comment elle tiendra dans la durée. Vous décrivez ici vos propres attentes : rien n’est noté à cette étape."
              />
              {user ? <div className="hidden items-center gap-2 text-slate-700 sm:flex"><UserCircle2 size={34} className="text-brand-800" /><span className="text-sm font-semibold">Bonjour, {user.name.split(' ')[0]}</span><ChevronDown size={15} /></div> : null}
            </div>
            <div className="md:hidden"><SaveIndicator state={state} savedAt={savedAt} /></div>

            <div className="mt-4 grid gap-6 xl:grid-cols-[1fr_340px]">
              <div className="min-w-0 space-y-6">
                <Reveal><Card className="p-6 sm:p-7">
                  <Groupe icone={<Target size={18} />} titre="Impact attendu" aide="Qui en profite, ce que cela change, et comment vous le saurez." />

                  <Redaction nom="beneficiaries" label="Qui bénéficiera de votre solution ?"
                    aide="Les bénéficiaires directs, puis ceux qui en profiteront indirectement. Donnez un ordre de grandeur si vous en avez un."
                    requis={requis('beneficiaries')} erreur={errors.beneficiaries} max={longTextMax}
                    valeur={values.beneficiaries} onChange={set('beneficiaries')} onBlur={flush} />

                  <Redaction nom="expected_results" label="Quels résultats attendez-vous ?"
                    aide="Ce qui aura changé si votre projet réussit, à l’échelle du territoire visé."
                    requis={requis('expected_results')} erreur={errors.expected_results} max={longTextMax}
                    valeur={values.expected_results} onChange={set('expected_results')} onBlur={flush} />

                  <Redaction nom="impact_indicators" label="Comment mesurerez-vous ces résultats ?"
                    aide="Les indicateurs que vous suivrez vous-même, et à quelle fréquence. Par exemple : nombre de foyers raccordés par trimestre."
                    requis={requis('impact_indicators')} erreur={errors.impact_indicators} max={longTextMax}
                    valeur={values.impact_indicators} onChange={set('impact_indicators')} onBlur={flush} />

                  <Redaction nom="resilience_contribution" label="En quoi contribue-t-elle à la résilience du territoire ?"
                    aide="Sa contribution à la qualité des services urbains et à la capacité du territoire à encaisser les chocs."
                    requis={requis('resilience_contribution')} erreur={errors.resilience_contribution} max={longTextMax}
                    valeur={values.resilience_contribution} onChange={set('resilience_contribution')} onBlur={flush} />
                </Card></Reveal>

                <Reveal delay={60}><Card className="p-6 sm:p-7">
                  <Groupe icone={<HeartHandshake size={18} />} titre="Inclusion" aide="Qui risque d’être laissé de côté, et ce que vous faites pour l’éviter." />

                  <Redaction nom="inclusion_measures" label="Comment rendez-vous votre solution accessible à tous ?"
                    aide="Accès des femmes, des jeunes, des personnes vulnérables ou handicapées, et des zones moins connectées — et les mesures concrètes que vous prévoyez."
                    requis={requis('inclusion_measures')} erreur={errors.inclusion_measures} max={longTextMax}
                    valeur={values.inclusion_measures} onChange={set('inclusion_measures')} onBlur={flush} />
                </Card></Reveal>

                <Reveal delay={120}><Card className="p-6 sm:p-7">
                  <Groupe icone={<Coins size={18} />} titre="Viabilité" aide="Ce qui fera vivre votre solution une fois le concours terminé." />

                  <Redaction nom="business_model" label="Quel est votre modèle économique ?"
                    aide="Qui paie, combien coûte le fonctionnement, et d’où viennent les recettes. Un modèle institutionnel — porté par une collectivité — est une réponse valable."
                    requis={requis('business_model')} erreur={errors.business_model} max={longTextMax}
                    valeur={values.business_model} onChange={set('business_model')} onBlur={flush} />

                  <Redaction nom="sustainability" label="Comment sera-t-elle adoptée puis maintenue dans la durée ?"
                    aide="Comment les utilisateurs s’en saisiront, qui l’entretiendra, et comment une collectivité pourrait se l’approprier."
                    requis={requis('sustainability')} erreur={errors.sustainability} max={longTextMax}
                    valeur={values.sustainability} onChange={set('sustainability')} onBlur={flush} />

                  <Redaction nom="scaling_plan" label="Comment pourrait-elle s’étendre ?"
                    aide="D’autres quartiers, d’autres communes, d’autres usages. Si c’est trop tôt pour le dire, laissez vide."
                    requis={requis('scaling_plan')} erreur={errors.scaling_plan} max={longTextMax}
                    valeur={values.scaling_plan} onChange={set('scaling_plan')} onBlur={flush} />
                </Card></Reveal>

                <BarreNavigation precedentUrl={previousUrl} suivantUrl={nextUrl} onFlush={flush} onSave={save} />
              </div>

              <div className="space-y-5">
                <Reveal delay={80}>
                  <DejaRenseigne titre="La solution que vous décrivez" lien={{ href: known.solutionUrl, label: 'Modifier à l’étape Solution' }}>
                    {known.solutionName
                      ? <p className="text-sm font-semibold text-slate-800">{known.solutionName}</p>
                      : <p>Vous n’avez pas encore nommé votre solution à l’étape 5.</p>}
                  </DejaRenseigne>
                </Reveal>

                <Reveal delay={140}><EtatSection manquants={manquants} /></Reveal>

                <Reveal delay={200}>
                  <Card className="self-start border-slate-200 bg-slate-50 p-6" data-testid="pas-de-notation">
                    <div className="flex items-center gap-3">
                      <div className="grid h-10 w-10 place-items-center rounded-full bg-slate-200 text-slate-700"><Info size={20} /></div>
                      <h2 className="text-base font-black leading-tight text-brand-950">Cette étape ne vous note pas</h2>
                    </div>
                    <p className="mt-3 text-xs leading-5 text-slate-600">
                      Vous décrivez ici votre projet. Aucune note, aucun score et aucun classement n’est calculé à partir de ces réponses : l’évaluation interviendra plus tard, après la clôture des candidatures.
                    </p>
                  </Card>
                </Reveal>
              </div>
            </div>

            <div className="mt-6 flex items-center gap-2 text-xs text-slate-500"><Check className="text-brand-700" size={16} /> Vos données sont sécurisées et confidentielles.</div>
          </div>
        </div>
      </div>
    </CandidateLayout>
  );
}
