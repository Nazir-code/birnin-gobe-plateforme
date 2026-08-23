import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Check, ChevronDown, CircleAlert, CircleHelp, Info, Save, UserCircle2 } from 'lucide-react';
import { CandidateLayout } from '@/Layouts/CandidateLayout';
import { Button, Card } from '@/Components/Ui';
import { Reveal } from '@/Components/Reveal';
import { SaveIndicator } from '@/Components/SaveIndicator';
import { SectionStepsAside, type SectionStep } from '@/Components/SectionStepsAside';
import { useAuthUser } from '@/hooks/useAuth';
import { useAutosave } from '@/hooks/useAutosave';
import { useI18n } from '@/i18n';

/** Champs reellement persistes, dans l'ordre de l'ecran. */
type Answers = {
  birth_date: string;
  is_nigerien_national: string;
  resides_in_niger: string;
  intervention_region: string;
  candidate_type: string;
  team_size: string;
};

type RuleStatus = 'UNANSWERED' | 'NOT_CONFIGURED' | 'SATISFIED' | 'BLOCKING';

type Eligibility = {
  outcome: 'INCOMPLETE' | 'ELIGIBLE' | 'TO_CONFIRM' | 'INELIGIBLE';
  label: string;
  blocksNextSections: boolean;
  findings: { rule: string; label: string; status: RuleStatus; message: string }[];
};

type Props = {
  steps: SectionStep[];
  section: { key: string; label: string; position: number; total: number; completedAt: string | null };
  answers: Answers;
  regions: { value: string; label: string }[];
  candidateTypes: { value: string; label: string }[];
  eligibility: Eligibility;
  saveUrl: string;
  previousUrl: string | null;
  nextUrl: string | null;
};

type SaveResponse = { eligibility?: Eligibility };

const tonesParResultat = {
  INCOMPLETE: { card: 'border-slate-200 bg-slate-50', icon: CircleHelp, iconTone: 'bg-slate-100 text-slate-600' },
  ELIGIBLE: { card: 'border-emerald-200 bg-emerald-50/60', icon: Check, iconTone: 'bg-emerald-100 text-emerald-700' },
  TO_CONFIRM: { card: 'border-amber-200 bg-[#fffdf5]', icon: Info, iconTone: 'bg-amber-100 text-amber-700' },
  INELIGIBLE: { card: 'border-red-200 bg-red-50/60', icon: AlertTriangle, iconTone: 'bg-red-100 text-red-700' },
} as const;

const tonesParRegle: Record<RuleStatus, string> = {
  SATISFIED: 'text-emerald-700',
  BLOCKING: 'text-red-700',
  NOT_CONFIGURED: 'text-amber-700',
  UNANSWERED: 'text-slate-500',
};

export default function Eligibility({ steps, section, answers, regions, candidateTypes, eligibility, saveUrl, previousUrl, nextUrl }: Props) {
  const user = useAuthUser();
  const t = useI18n();
  const [values, setValues] = useState<Answers>(answers);

  const { state, savedAt, errors, response, flush } = useAutosave<Answers, SaveResponse>(saveUrl, values);

  /**
   * Le verdict vient toujours du serveur : celui du dernier enregistrement
   * s'il y en a eu un, sinon celui calcule au chargement de la page. React ne
   * derive jamais l'eligibilite lui-meme — il l'affiche.
   */
  const verdict = response?.eligibility ?? eligibility;
  const collectif = values.candidate_type === 'TEAM' || values.candidate_type === 'STARTUP';

  const set = (champ: keyof Answers) => (valeur: string) => setValues((v) => ({ ...v, [champ]: valeur }));

  const apparence = tonesParResultat[verdict.outcome];
  const Icone = apparence.icon;

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
                <div className="mt-3 h-1.5 w-14 rounded-full bg-gold-500" />
                <p className="mt-5 text-slate-600">Quelques questions courtes pour situer votre candidature. Vous pouvez revenir les modifier à tout moment.</p>
              </div>
              {user ? <div className="hidden items-center gap-2 text-slate-700 sm:flex"><UserCircle2 size={34} className="text-brand-800" /><span className="text-sm font-semibold">Bonjour, {user.name.split(' ')[0]}</span><ChevronDown size={15} /></div> : null}
            </div>
            <div className="md:hidden"><SaveIndicator state={state} savedAt={savedAt} /></div>

            <div className="mt-4 grid gap-6 xl:grid-cols-[1fr_340px]">
              <Reveal><Card className="p-6 sm:p-7">
                <Field index={1} htmlFor="birth_date" label="Quelle est votre date de naissance ?" hint="Votre âge est calculé à la date de référence de la campagne." error={errors.birth_date}>
                  <input
                    id="birth_date"
                    name="birth_date"
                    type="date"
                    className={`focus-ring min-h-12 w-full rounded-lg border bg-white px-4 text-sm text-slate-800 sm:max-w-xs ${errors.birth_date ? 'border-red-400' : 'border-slate-300'}`}
                    value={values.birth_date}
                    onChange={(e) => set('birth_date')(e.target.value)}
                    onBlur={flush}
                  />
                </Field>

                <BooleanField index={2} name="is_nigerien_national" label="Êtes-vous de nationalité nigérienne ?" value={values.is_nigerien_national} onChange={set('is_nigerien_national')} onSettled={flush} error={errors.is_nigerien_national} />
                <BooleanField index={3} name="resides_in_niger" label="Résidez-vous actuellement au Niger ?" value={values.resides_in_niger} onChange={set('resides_in_niger')} onSettled={flush} error={errors.resides_in_niger} />

                <Field index={4} htmlFor="intervention_region" label="Dans quelle région votre projet interviendra-t-il ?" hint="Choisissez la région principale concernée." error={errors.intervention_region}>
                  <select id="intervention_region" name="intervention_region" className={`focus-ring min-h-12 w-full rounded-lg border bg-white px-4 text-sm text-slate-800 ${errors.intervention_region ? 'border-red-400' : 'border-slate-300'}`} value={values.intervention_region} onChange={(e) => set('intervention_region')(e.target.value)} onBlur={flush}>
                    <option value="">Sélectionnez une option</option>
                    {regions.map((region) => <option key={region.value} value={region.value}>{region.label}</option>)}
                  </select>
                </Field>

                <Field index={5} htmlFor="candidate_type" label="Sous quelle forme candidatez-vous ?" hint="Individuellement, en équipe ou au nom d’une startup." error={errors.candidate_type}>
                  <select id="candidate_type" name="candidate_type" className={`focus-ring min-h-12 w-full rounded-lg border bg-white px-4 text-sm text-slate-800 ${errors.candidate_type ? 'border-red-400' : 'border-slate-300'}`} value={values.candidate_type} onChange={(e) => set('candidate_type')(e.target.value)} onBlur={flush}>
                    <option value="">Sélectionnez une option</option>
                    {candidateTypes.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
                  </select>
                </Field>

                {/* Question posee uniquement aux candidatures collectives : une
                    personne seule n'a pas d'effectif a declarer. */}
                {collectif ? (
                  <Field index={6} htmlFor="team_size" label="Combien de personnes compte votre équipe ?" hint="Vous compris. Une candidature collective réunit au moins deux personnes." error={errors.team_size}>
                    <input
                      id="team_size"
                      name="team_size"
                      type="number"
                      min={1}
                      className={`focus-ring min-h-12 w-full rounded-lg border bg-white px-4 text-sm text-slate-800 sm:max-w-[10rem] ${errors.team_size ? 'border-red-400' : 'border-slate-300'}`}
                      value={values.team_size}
                      onChange={(e) => set('team_size')(e.target.value)}
                      onBlur={flush}
                    />
                  </Field>
                ) : null}

                <div className="mt-2 flex flex-wrap items-center justify-between gap-3">
                  <Button variant="ghost" type="button" onClick={flush}><Save size={17} /> Enregistrer</Button>
                  {nextUrl && !verdict.blocksNextSections ? (
                    <Link href={nextUrl} onClick={flush} className="focus-ring press-feedback inline-flex min-h-11 min-w-44 items-center justify-center gap-2 rounded-xl bg-gold-500 px-5 text-sm font-bold text-ink-950 transition-colors hover:bg-gold-600" data-testid="suivant">
                      Suivant <span aria-hidden>→</span>
                    </Link>
                  ) : (
                    <Button variant="secondary" className="min-w-44" disabled title="Les conditions d’éligibilité doivent être remplies pour poursuivre.">
                      Suivant <span aria-hidden>→</span>
                    </Button>
                  )}
                </div>
              </Card></Reveal>

              <Reveal delay={100}>
                <Card className={`self-start p-6 ${apparence.card}`} data-testid="resultat-eligibilite">
                  <div className="flex items-center gap-3">
                    <div className={`grid h-10 w-10 place-items-center rounded-full ${apparence.iconTone}`}><Icone size={22} /></div>
                    <h2 className="text-lg font-black leading-tight text-brand-950" data-testid="resultat-libelle">{verdict.label}</h2>
                  </div>

                  <ul className="mt-5 space-y-3">
                    {verdict.findings.map((constat) => (
                      <li key={constat.rule} className="flex gap-3">
                        <CircleAlert size={16} className={`mt-0.5 shrink-0 ${tonesParRegle[constat.status]}`} aria-hidden />
                        <div>
                          <div className="text-xs font-extrabold uppercase tracking-wide text-slate-500">{constat.label}</div>
                          <p className={`mt-0.5 text-xs leading-5 ${constat.status === 'BLOCKING' ? 'font-semibold text-red-700' : 'text-slate-600'}`}>{constat.message}</p>
                        </div>
                      </li>
                    ))}
                  </ul>

                  {/* Contrat UX deja porte par le dictionnaire : le resultat est
                      indicatif et ne prejuge pas du controle administratif. */}
                  <p className="mt-5 border-t border-black/5 pt-4 text-[11px] leading-5 text-slate-500">{t.eligibility.warning}</p>
                </Card>
              </Reveal>
            </div>

            <div className="mt-6 flex items-center gap-2 text-xs text-slate-500"><Check className="text-brand-700" size={16} /> Vos réponses sont enregistrées automatiquement, même si vous quittez cette page.</div>
          </div>
        </div>
      </div>
    </CandidateLayout>
  );
}

function Field({ index, htmlFor, label, hint, error, children }: { index: number; htmlFor: string; label: string; hint: string; error?: string; children: React.ReactNode }) {
  return (
    <div className="mb-6">
      <label className="mb-2 block text-sm font-extrabold text-slate-800" htmlFor={htmlFor}>{index}. {label} <span className="text-red-500">*</span></label>
      <p className="mb-2 text-xs text-slate-500">{hint}</p>
      {children}
      {error ? <span className="mt-1 block text-xs font-semibold text-red-600">{error}</span> : null}
    </div>
  );
}

/**
 * Question fermee, en boutons radio plutot qu'en case a cocher : une case
 * decochee ne distingue pas « non » de « pas encore repondu », alors que la
 * difference compte pour un brouillon.
 */
function BooleanField({ index, name, label, value, onChange, onSettled, error }: { index: number; name: string; label: string; value: string; onChange: (v: string) => void; onSettled: () => void; error?: string }) {
  return (
    <fieldset className="mb-6">
      <legend className="mb-2 block text-sm font-extrabold text-slate-800">{index}. {label} <span className="text-red-500">*</span></legend>
      <div className="flex flex-wrap gap-3">
        {[['1', 'Oui'], ['0', 'Non']].map(([valeur, libelle]) => (
          <label key={valeur} className={`focus-within:ring-brand-700/40 flex min-h-12 cursor-pointer items-center gap-2 rounded-lg border px-4 text-sm font-semibold transition-colors focus-within:ring-2 ${value === valeur ? 'border-brand-800 bg-brand-50 text-brand-900' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'}`}>
            <input
              type="radio"
              name={name}
              value={valeur}
              checked={value === valeur}
              onChange={() => { onChange(valeur); }}
              onBlur={onSettled}
              className="h-4 w-4 accent-brand-800"
            />
            {libelle}
          </label>
        ))}
      </div>
      {error ? <span className="mt-1 block text-xs font-semibold text-red-600">{error}</span> : null}
    </fieldset>
  );
}
