import { useState, type ReactNode } from 'react';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Accessibility, BriefcaseBusiness, Check, ChevronDown, Info, MapPin, Phone, Save, UserCircle2 } from 'lucide-react';
import { CandidateLayout } from '@/Layouts/CandidateLayout';
import { Button, Card } from '@/Components/Ui';
import { Reveal } from '@/Components/Reveal';
import { SaveIndicator } from '@/Components/SaveIndicator';
import { SectionStepsAside, type SectionStep } from '@/Components/SectionStepsAside';
import { useAuthUser } from '@/hooks/useAuth';
import { useAutosave } from '@/hooks/useAutosave';

/** Champs reellement persistes par la section « Profil ». */
type Answers = {
  birth_place: string;
  gender: string;
  phone_primary: string;
  phone_secondary: string;
  preferred_channel: string;
  residence_region: string;
  residence_locality: string;
  occupation: string;
  education_level: string;
  specialty: string;
  accessibility_need: string;
};

type Option = { value: string; label: string };

type Props = {
  steps: SectionStep[];
  section: { key: string; label: string; position: number; total: number; completedAt: string | null };
  answers: Answers;
  /** Donnees que le dossier detient deja ailleurs : affichees, jamais recopiees. */
  known: {
    accountName: string | null;
    accountEmail: string | null;
    birthDate: string | null;
    nigerienNational: boolean | null;
    eligibilityUrl: string;
  };
  regions: Option[];
  genders: Option[];
  channels: Option[];
  educationLevels: Option[];
  requiredFields: (keyof Answers)[];
  shortTextMax: number;
  longTextMax: number;
  saveUrl: string;
  previousUrl: string | null;
  nextUrl: string | null;
};

const dateCourte = new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });

function dateLisible(iso: string | null): string {
  if (!iso) return 'Non renseignée';
  const date = new Date(`${iso}T00:00:00`);
  return Number.isNaN(date.getTime()) ? 'Non renseignée' : dateCourte.format(date);
}

export default function Profile({
  steps, section, answers, known, regions, genders, channels, educationLevels,
  requiredFields, shortTextMax, longTextMax, saveUrl, previousUrl, nextUrl,
}: Props) {
  const user = useAuthUser();
  const [values, setValues] = useState<Answers>(answers);

  const { state, savedAt, errors, flush } = useAutosave<Answers>(saveUrl, values);

  const set = (champ: keyof Answers) => (valeur: string) => setValues((v) => ({ ...v, [champ]: valeur }));
  const requis = (champ: keyof Answers) => requiredFields.includes(champ);

  /** Champs obligatoires encore vides : le resume d'erreurs par etape du CDC 5.3. */
  const manquants = requiredFields.filter((champ) => values[champ].trim() === '');

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
                <p className="mt-5 max-w-2xl text-slate-600">Vos coordonnées et votre situation, pour que l’équipe puisse vous joindre et situer votre candidature. Vos réponses sont enregistrées au fur et à mesure.</p>
              </div>
              {user ? <div className="hidden items-center gap-2 text-slate-700 sm:flex"><UserCircle2 size={34} className="text-brand-800" /><span className="text-sm font-semibold">Bonjour, {user.name.split(' ')[0]}</span><ChevronDown size={15} /></div> : null}
            </div>
            <div className="md:hidden"><SaveIndicator state={state} savedAt={savedAt} /></div>

            <div className="mt-4 grid gap-6 xl:grid-cols-[1fr_340px]">
              <div className="min-w-0 space-y-6">
                <Reveal><Card className="p-6 sm:p-7">
                  <Groupe icone={<UserCircle2 size={18} />} titre="Identité" aide="Complète ce que votre compte et l’étape 1 savent déjà de vous." />

                  <Champ nom="birth_place" label="Où êtes-vous né·e ?" aide="Ville ou village de naissance." requis={requis('birth_place')} erreur={errors.birth_place}>
                    <input id="birth_place" name="birth_place" type="text" maxLength={shortTextMax} autoComplete="off"
                      className={saisie(errors.birth_place)} value={values.birth_place}
                      onChange={(e) => set('birth_place')(e.target.value)} onBlur={flush} />
                  </Champ>

                  <Champ nom="gender" label="Sexe" aide="Facultatif. Utilisé uniquement pour le suivi statistique de l’inclusion, jamais pour la notation." requis={false} erreur={errors.gender}>
                    <select id="gender" name="gender" className={saisie(errors.gender)} value={values.gender}
                      onChange={(e) => { set('gender')(e.target.value); }} onBlur={flush}>
                      <option value="">Ne pas renseigner</option>
                      {genders.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                  </Champ>
                </Card></Reveal>

                <Reveal delay={60}><Card className="p-6 sm:p-7">
                  <Groupe icone={<Phone size={18} />} titre="Contacts" aide="C’est par là que l’équipe vous joindra pendant tout le processus." />

                  <Champ nom="phone_primary" label="Téléphone principal" aide="Par exemple 90 12 34 56, ou +227 90 12 34 56 depuis l’étranger." requis={requis('phone_primary')} erreur={errors.phone_primary}>
                    <input id="phone_primary" name="phone_primary" type="tel" inputMode="tel" autoComplete="tel"
                      className={saisie(errors.phone_primary)} value={values.phone_primary}
                      onChange={(e) => set('phone_primary')(e.target.value)} onBlur={flush} />
                  </Champ>

                  <Champ nom="phone_secondary" label="Téléphone secondaire" aide="Facultatif. Un second numéro où vous joindre." requis={false} erreur={errors.phone_secondary}>
                    <input id="phone_secondary" name="phone_secondary" type="tel" inputMode="tel"
                      className={saisie(errors.phone_secondary)} value={values.phone_secondary}
                      onChange={(e) => set('phone_secondary')(e.target.value)} onBlur={flush} />
                  </Champ>

                  <Champ nom="preferred_channel" label="Comment préférez-vous être contacté·e ?" aide="Les avis officiels vous parviendront par ce canal en priorité." requis={requis('preferred_channel')} erreur={errors.preferred_channel}>
                    <select id="preferred_channel" name="preferred_channel" className={saisie(errors.preferred_channel)} value={values.preferred_channel}
                      onChange={(e) => { set('preferred_channel')(e.target.value); }} onBlur={flush}>
                      <option value="">Sélectionnez une option</option>
                      {channels.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                  </Champ>
                </Card></Reveal>

                <Reveal delay={120}><Card className="p-6 sm:p-7">
                  <Groupe icone={<MapPin size={18} />} titre="Où vivez-vous ?" aide="Votre lieu de résidence, à ne pas confondre avec la région où votre projet interviendra — celle-ci a été indiquée à l’étape 1." />

                  <Champ nom="residence_region" label="Région de résidence" aide="La région où vous vivez actuellement." requis={requis('residence_region')} erreur={errors.residence_region}>
                    <select id="residence_region" name="residence_region" className={saisie(errors.residence_region)} value={values.residence_region}
                      onChange={(e) => { set('residence_region')(e.target.value); }} onBlur={flush}>
                      <option value="">Sélectionnez une option</option>
                      {regions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                  </Champ>

                  <Champ nom="residence_locality" label="Quartier ou village" aide="Le nom suffit : aucune adresse précise n’est demandée." requis={requis('residence_locality')} erreur={errors.residence_locality}>
                    <input id="residence_locality" name="residence_locality" type="text" maxLength={shortTextMax}
                      className={saisie(errors.residence_locality)} value={values.residence_locality}
                      onChange={(e) => set('residence_locality')(e.target.value)} onBlur={flush} />
                  </Champ>
                </Card></Reveal>

                <Reveal delay={180}><Card className="p-6 sm:p-7">
                  <Groupe icone={<BriefcaseBusiness size={18} />} titre="Situation professionnelle" aide="Ce que vous faites aujourd’hui et le parcours qui vous y a mené." />

                  <Champ nom="occupation" label="Quelle est votre occupation principale ?" aide="Par exemple : étudiante en informatique, développeur indépendant, enseignant." requis={requis('occupation')} erreur={errors.occupation}>
                    <input id="occupation" name="occupation" type="text" maxLength={shortTextMax}
                      className={saisie(errors.occupation)} value={values.occupation}
                      onChange={(e) => set('occupation')(e.target.value)} onBlur={flush} />
                  </Champ>

                  <Champ nom="education_level" label="Niveau d’études le plus élevé atteint" aide="Choisissez le niveau le plus proche de votre situation." requis={requis('education_level')} erreur={errors.education_level}>
                    <select id="education_level" name="education_level" className={saisie(errors.education_level)} value={values.education_level}
                      onChange={(e) => { set('education_level')(e.target.value); }} onBlur={flush}>
                      <option value="">Sélectionnez une option</option>
                      {educationLevels.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                  </Champ>

                  <Champ nom="specialty" label="Spécialité ou domaine" aide="Facultatif. Par exemple : réseaux, agronomie, design." requis={false} erreur={errors.specialty}>
                    <input id="specialty" name="specialty" type="text" maxLength={shortTextMax}
                      className={saisie(errors.specialty)} value={values.specialty}
                      onChange={(e) => set('specialty')(e.target.value)} onBlur={flush} />
                  </Champ>
                </Card></Reveal>

                <Reveal delay={240}><Card className="p-6 sm:p-7">
                  <Groupe icone={<Accessibility size={18} />} titre="Accessibilité" aide="Facultatif, et visible uniquement par l’équipe d’assistance." />

                  <Champ nom="accessibility_need" label="Avez-vous besoin d’un aménagement particulier ?" aide="Pour déposer votre dossier, échanger avec l’équipe ou présenter votre projet." requis={false} erreur={errors.accessibility_need}>
                    <textarea id="accessibility_need" name="accessibility_need" maxLength={longTextMax}
                      className={`${saisie(errors.accessibility_need)} min-h-24 resize-y py-3`} value={values.accessibility_need}
                      onChange={(e) => set('accessibility_need')(e.target.value)} onBlur={flush} />
                    <span className="mt-1 block text-right text-[11px] text-slate-400">{values.accessibility_need.length} / {longTextMax}</span>
                  </Champ>
                </Card></Reveal>

                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div className="flex flex-wrap items-center gap-3">
                    {previousUrl ? (
                      <Link href={previousUrl} onClick={flush} data-testid="precedent"
                        className="focus-ring press-feedback inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-brand-900/35 bg-white px-5 text-sm font-bold text-brand-900 transition-colors hover:bg-brand-50">
                        <ArrowLeft size={16} /> Précédent
                      </Link>
                    ) : null}
                    <Button variant="ghost" type="button" onClick={flush}><Save size={17} /> Enregistrer</Button>
                  </div>
                  {nextUrl ? (
                    <Link href={nextUrl} onClick={flush} data-testid="suivant"
                      className="focus-ring press-feedback inline-flex min-h-11 min-w-44 items-center justify-center gap-2 rounded-xl bg-gold-500 px-5 text-sm font-bold text-ink-950 transition-colors hover:bg-gold-600">
                      Suivant <span aria-hidden>→</span>
                    </Link>
                  ) : (
                    <Button variant="secondary" className="min-w-44" disabled title="L’étape suivante n’est pas encore ouverte.">
                      Suivant <span aria-hidden>→</span>
                    </Button>
                  )}
                </div>
              </div>

              <div className="space-y-5">
                <Reveal delay={80}>
                  <Card className="self-start border-brand-100 bg-brand-50/40 p-6" data-testid="donnees-connues">
                    <div className="flex items-center gap-3">
                      <div className="grid h-10 w-10 place-items-center rounded-full bg-brand-100 text-brand-800"><Info size={20} /></div>
                      <h2 className="text-base font-black leading-tight text-brand-950">Déjà renseigné</h2>
                    </div>
                    <p className="mt-3 text-xs leading-5 text-slate-600">Ces informations viennent de votre compte et de l’étape 1. Elles ne vous sont pas redemandées ici.</p>
                    <dl className="mt-4 space-y-3">
                      <Connu terme="Nom complet" valeur={known.accountName ?? '—'} />
                      <Connu terme="Adresse e-mail" valeur={known.accountEmail ?? '—'} />
                      <Connu terme="Date de naissance" valeur={dateLisible(known.birthDate)} />
                      <Connu terme="Nationalité nigérienne" valeur={known.nigerienNational === null ? 'Non renseignée' : known.nigerienNational ? 'Oui' : 'Non'} />
                    </dl>
                    <Link href={known.eligibilityUrl} className="focus-ring mt-4 inline-flex text-xs font-bold text-brand-800 underline underline-offset-2">
                      Modifier à l’étape Éligibilité
                    </Link>
                  </Card>
                </Reveal>

                {manquants.length > 0 ? (
                  <Reveal delay={140}>
                    <Card className="self-start border-amber-200 bg-[#fffdf5] p-6" data-testid="champs-manquants">
                      <h2 className="text-base font-black leading-tight text-brand-950">Il reste {manquants.length} réponse{manquants.length > 1 ? 's' : ''} à donner</h2>
                      <p className="mt-2 text-xs leading-5 text-slate-600">Votre saisie est enregistrée même incomplète. Cette étape ne comptera comme terminée qu’une fois ces champs remplis.</p>
                    </Card>
                  </Reveal>
                ) : (
                  <Reveal delay={140}>
                    <Card className="self-start border-emerald-200 bg-emerald-50/60 p-6" data-testid="section-complete">
                      <div className="flex items-center gap-3">
                        <div className="grid h-10 w-10 place-items-center rounded-full bg-emerald-100 text-emerald-700"><Check size={20} /></div>
                        <h2 className="text-base font-black leading-tight text-brand-950">Étape complète</h2>
                      </div>
                      <p className="mt-3 text-xs leading-5 text-slate-600">Vous pourrez y revenir tant que votre dossier n’est pas soumis.</p>
                    </Card>
                  </Reveal>
                )}
              </div>
            </div>

            <div className="mt-6 flex items-center gap-2 text-xs text-slate-500"><Check className="text-brand-700" size={16} /> Vos coordonnées ne sont visibles que par l’équipe d’organisation.</div>
          </div>
        </div>
      </div>
    </CandidateLayout>
  );
}

/** Classe commune des champs de saisie, avec l'etat d'erreur. */
function saisie(erreur?: string): string {
  return `focus-ring min-h-12 w-full rounded-lg border bg-white px-4 text-sm text-slate-800 ${erreur ? 'border-red-400' : 'border-slate-300'}`;
}

function Groupe({ icone, titre, aide }: { icone: ReactNode; titre: string; aide: string }) {
  return (
    <div className="mb-6 flex gap-3 border-b border-slate-100 pb-4">
      <div className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-800">{icone}</div>
      <div>
        <h2 className="text-lg font-extrabold tracking-tight text-ink-950">{titre}</h2>
        <p className="mt-1 text-xs leading-5 text-slate-500">{aide}</p>
      </div>
    </div>
  );
}

/**
 * Un champ, son aide et son erreur.
 *
 * L'erreur est liee au champ par `aria-describedby` et annoncee par
 * `role="alert"` : elle est lue par un lecteur d'ecran au moment ou elle
 * apparait, pas seulement affichee en rouge.
 */
function Champ({ nom, label, aide, requis, erreur, children }: { nom: string; label: string; aide: string; requis: boolean; erreur?: string; children: ReactNode }) {
  const aideId = `${nom}-aide`;
  const erreurId = `${nom}-erreur`;

  return (
    <div className="mb-6 last:mb-0">
      <label className="mb-1.5 block text-sm font-extrabold text-slate-800" htmlFor={nom}>
        {label} {requis ? <span className="text-red-500" aria-hidden>*</span> : <span className="font-semibold text-slate-400">(facultatif)</span>}
        {requis ? <span className="sr-only">(obligatoire)</span> : null}
      </label>
      <p className="mb-2 text-xs leading-5 text-slate-500" id={aideId}>{aide}</p>
      <div aria-describedby={erreur ? `${aideId} ${erreurId}` : aideId}>{children}</div>
      {erreur ? <span className="mt-1 block text-xs font-semibold text-red-600" id={erreurId} role="alert">{erreur}</span> : null}
    </div>
  );
}

function Connu({ terme, valeur }: { terme: string; valeur: string }) {
  return (
    <div>
      <dt className="text-[11px] font-extrabold uppercase tracking-wide text-slate-500">{terme}</dt>
      <dd className="mt-0.5 break-words text-sm font-semibold text-slate-800">{valeur}</dd>
    </div>
  );
}
