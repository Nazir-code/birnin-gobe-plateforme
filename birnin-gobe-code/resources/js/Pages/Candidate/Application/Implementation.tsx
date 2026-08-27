import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { AlertTriangle, Boxes, CalendarRange, Check, ChevronDown, LifeBuoy, UserCircle2, Wallet } from 'lucide-react';
import { CandidateLayout } from '@/Layouts/CandidateLayout';
import { Card } from '@/Components/Ui';
import { Reveal } from '@/Components/Reveal';
import { SaveIndicator } from '@/Components/SaveIndicator';
import { SaveConfirmation } from '@/Components/SaveConfirmation';
import { SectionStepsAside, type SectionStep } from '@/Components/SectionStepsAside';
import { BarreNavigation, Champ, DejaRenseigne, EnteteSection, EtatSection, Groupe, Redaction, saisie } from '@/Components/SectionForm';
import { useAuthUser } from '@/hooks/useAuth';
import { useAutosave } from '@/hooks/useAutosave';

/** Champs reellement persistes par la section « Plan de mise en oeuvre » (etape 7). */
type Answers = {
  duration_months: string;
  activities: string;
  milestones: string;
  resources: string;
  partners: string;
  risks: string;
  support_needs: string;
  budget_amount: string;
  budget_breakdown: string;
};

type Props = {
  steps: SectionStep[];
  section: { key: string; label: string; position: number; total: number; completedAt: string | null };
  answers: Answers;
  /** L'equipe de l'etape 3 : rappelee, jamais redemandee. */
  known: { teamSize: number; teamUrl: string };
  requiredFields: (keyof Answers)[];
  /** Champs stockes comme entiers : leur case vide se lit autrement qu'un texte vide. */
  numericFields: (keyof Answers)[];
  longTextMax: number;
  durationMin: number;
  durationMax: number;
  budgetCeiling: number;
  saveUrl: string;
  previousUrl: string | null;
  nextUrl: string | null;
};

const montant = new Intl.NumberFormat('fr-FR');

export default function Implementation({
  steps, section, answers, known, requiredFields, numericFields, longTextMax,
  durationMin, durationMax, budgetCeiling, saveUrl, previousUrl, nextUrl,
}: Props) {
  const user = useAuthUser();
  const [values, setValues] = useState<Answers>(answers);

  const { state, savedAt, errors, confirmation, flush, save, acquitter } = useAutosave<Answers>(saveUrl, values);

  const set = (champ: keyof Answers) => (valeur: string) => setValues((v) => ({ ...v, [champ]: valeur }));
  const requis = (champ: keyof Answers) => requiredFields.includes(champ);
  const manquants = requiredFields.filter((champ) => values[champ].trim() === '').length;

  // Le budget se relit plus vite avec ses separateurs de milliers, mais on
  // n'ecrit pas dans le champ : ce qui part au serveur reste ce qui a ete tape,
  // et c'est lui qui decide de ce qu'il en fait.
  const budgetLisible = numericFields.includes('budget_amount') && /^\d+$/.test(values.budget_amount.replace(/\s/g, ''))
    ? montant.format(Number(values.budget_amount.replace(/\s/g, '')))
    : null;

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
                intro="Comment vous comptez passer de l’idée à la réalisation : les activités, le calendrier, les moyens et ce que vous savez déjà des difficultés."
              />
              {user ? <div className="hidden items-center gap-2 text-slate-700 sm:flex"><UserCircle2 size={34} className="text-brand-800" /><span className="text-sm font-semibold">Bonjour, {user.name.split(' ')[0]}</span><ChevronDown size={15} /></div> : null}
            </div>
            <div className="md:hidden"><SaveIndicator state={state} savedAt={savedAt} /></div>

            <div className="mt-4 grid gap-6 xl:grid-cols-[1fr_340px]">
              <div className="min-w-0 space-y-6">
                <Reveal><Card className="p-6 sm:p-7">
                  <Groupe icone={<CalendarRange size={18} />} titre="Calendrier et activités" aide="Ce que vous ferez, dans quel ordre, et en combien de temps." />

                  <Champ nom="duration_months" label="Sur combien de mois s’étend votre plan ?"
                    aide={`Le cahier des charges attend un plan de ${durationMin} à ${durationMax} mois.`}
                    requis={requis('duration_months')} erreur={errors.duration_months}>
                    <input id="duration_months" name="duration_months" type="number" inputMode="numeric"
                      min={durationMin} max={durationMax} step={1}
                      className={`${saisie(errors.duration_months)} max-w-40`} value={values.duration_months}
                      onChange={(e) => set('duration_months')(e.target.value)} onBlur={flush} />
                  </Champ>

                  <Redaction nom="activities" label="Quelles sont les activités principales ?"
                    aide="Les grandes tâches à mener, sans entrer dans le détail du planning."
                    requis={requis('activities')} erreur={errors.activities} max={longTextMax}
                    valeur={values.activities} onChange={set('activities')} onBlur={flush} />

                  <Redaction nom="milestones" label="Quels sont vos jalons ?"
                    aide="Les étapes qui marqueront l’avancement, avec le mois où vous les attendez. Par exemple : prototype testé au mois 4."
                    requis={requis('milestones')} erreur={errors.milestones} max={longTextMax}
                    valeur={values.milestones} onChange={set('milestones')} onBlur={flush} />
                </Card></Reveal>

                <Reveal delay={60}><Card className="p-6 sm:p-7">
                  <Groupe icone={<Boxes size={18} />} titre="Moyens et partenaires" />

                  <Redaction nom="resources" label="De quels moyens avez-vous besoin ?"
                    aide="Matériel, locaux, connectivité, compétences externes. Votre équipe a été décrite à l’étape 3, inutile de la reprendre ici."
                    requis={requis('resources')} erreur={errors.resources} max={longTextMax}
                    valeur={values.resources} onChange={set('resources')} onBlur={flush} />

                  <Redaction nom="partners" label="Avec quels partenaires travaillez-vous ?"
                    aide="Collectivités, structures d’appui, entreprises, associations — ceux qui sont déjà engagés comme ceux que vous visez. Si vous n’en avez aucun, laissez vide."
                    requis={requis('partners')} erreur={errors.partners} max={longTextMax}
                    valeur={values.partners} onChange={set('partners')} onBlur={flush} />
                </Card></Reveal>

                <Reveal delay={120}><Card className="p-6 sm:p-7">
                  <Groupe icone={<AlertTriangle size={18} />} titre="Risques et accompagnement" aide="Ce qui peut mal se passer, et l’aide dont vous auriez besoin." />

                  <Redaction nom="risks" label="Quels risques et quelles hypothèses avez-vous identifiés ?"
                    aide="Ce qui pourrait empêcher la réussite, et ce que vous tenez pour acquis sans en être certain. Dire un risque n’affaiblit pas un dossier."
                    requis={requis('risks')} erreur={errors.risks} max={longTextMax}
                    valeur={values.risks} onChange={set('risks')} onBlur={flush} />

                  <Redaction nom="support_needs" label="De quel accompagnement auriez-vous besoin ?"
                    aide="Appui technique, juridique ou commercial, moyens de prototypage, terrain d’expérimentation."
                    requis={requis('support_needs')} erreur={errors.support_needs} max={longTextMax}
                    valeur={values.support_needs} onChange={set('support_needs')} onBlur={flush} />
                </Card></Reveal>

                <Reveal delay={180}><Card className="p-6 sm:p-7">
                  <Groupe icone={<Wallet size={18} />} titre="Budget indicatif" aide="Un ordre de grandeur. Le budget détaillé sera une pièce jointe, à l’étape 8." />

                  <Champ nom="budget_amount" label="Quel budget estimez-vous nécessaire ?"
                    aide="En francs CFA, pour toute la durée du plan. Les espaces sont acceptés : 5 000 000."
                    requis={requis('budget_amount')} erreur={errors.budget_amount}>
                    <input id="budget_amount" name="budget_amount" type="text" inputMode="numeric"
                      maxLength={String(budgetCeiling).length + 6}
                      className={`${saisie(errors.budget_amount)} max-w-64`} value={values.budget_amount}
                      onChange={(e) => set('budget_amount')(e.target.value)} onBlur={flush} />
                    {budgetLisible ? (
                      <span className="mt-1 block text-xs font-semibold text-slate-500" data-testid="budget-lisible">{budgetLisible} FCFA</span>
                    ) : null}
                  </Champ>

                  <Redaction nom="budget_breakdown" label="Comment se répartit-il ?"
                    aide="Les grandes masses seulement : équipement, personnel, déplacements, communication."
                    requis={requis('budget_breakdown')} erreur={errors.budget_breakdown} max={longTextMax}
                    valeur={values.budget_breakdown} onChange={set('budget_breakdown')} onBlur={flush} />
                </Card></Reveal>

                <BarreNavigation precedentUrl={previousUrl} suivantUrl={nextUrl} onFlush={flush} onSave={save} />
              </div>

              <div className="space-y-5">
                <Reveal delay={80}>
                  <DejaRenseigne titre="L’équipe qui portera le plan" lien={{ href: known.teamUrl, label: 'Modifier à l’étape Structure / équipe' }}>
                    <p data-testid="effectif-rappele">
                      {known.teamSize} personne{known.teamSize > 1 ? 's' : ''} décrite{known.teamSize > 1 ? 's' : ''} à l’étape 3, vous compris.
                    </p>
                  </DejaRenseigne>
                </Reveal>

                <Reveal delay={140}><EtatSection manquants={manquants} /></Reveal>

                <Reveal delay={200}>
                  <Card className="self-start border-slate-200 bg-slate-50 p-6">
                    <div className="flex items-center gap-3">
                      <div className="grid h-10 w-10 place-items-center rounded-full bg-slate-200 text-slate-700"><LifeBuoy size={20} /></div>
                      <h2 className="text-base font-black leading-tight text-brand-950">Un plan, pas un logiciel de gestion</h2>
                    </div>
                    <p className="mt-3 text-xs leading-5 text-slate-600">
                      Rédigez vos activités et vos jalons en toutes lettres : aucun diagramme ni tableau n’est attendu. Le budget détaillé et le plan d’action se déposeront en pièce jointe à l’étape 8.
                    </p>
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
