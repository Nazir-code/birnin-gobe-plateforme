import { useState, type ReactNode } from 'react';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Building2, Check, ChevronDown, CircleAlert, Info, Save, Trash2, UserPlus, UserRound, UsersRound } from 'lucide-react';
import { CandidateLayout } from '@/Layouts/CandidateLayout';
import { Button, Card } from '@/Components/Ui';
import { Reveal } from '@/Components/Reveal';
import { SaveIndicator } from '@/Components/SaveIndicator';
import { SectionStepsAside, type SectionStep } from '@/Components/SectionStepsAside';
import { useAuthUser } from '@/hooks/useAuth';
import { useAutosave } from '@/hooks/useAutosave';

type Member = {
  full_name: string;
  email: string;
  phone: string;
  role: string;
  skills: string;
  availability: string;
  is_founder: boolean;
  consent: boolean;
};

type Structure = {
  structure_name: string;
  structure_acronym: string;
  structure_founded_year: string;
  structure_sector: string;
  structure_address: string;
  structure_rccm: string;
  structure_nif: string;
  structure_website: string;
  structure_social: string;
};

type Assessment = {
  complete: boolean;
  type: 'INDIVIDUAL' | 'TEAM' | 'STARTUP' | null;
  typeLabel: string | null;
  declaredSize: number | null;
  describedSize: number;
  sizeMismatch: boolean;
  missing: string[];
};

type Props = {
  steps: SectionStep[];
  section: { key: string; label: string; position: number; total: number; completedAt: string | null };
  structure: Structure;
  members: Member[];
  assessment: Assessment;
  representative: { name: string | null; email: string | null };
  eligibilityUrl: string;
  limits: { shortTextMax: number; longTextMax: number; membersCeiling: number; foundedYearFloor: number; foundedYearCeiling: number };
  saveUrl: string;
  previousUrl: string | null;
  nextUrl: string | null;
};

type SaveResponse = { assessment?: Assessment };

const membreVide: Member = {
  full_name: '', email: '', phone: '', role: '', skills: '', availability: '', is_founder: false, consent: false,
};

export default function Team({
  steps, section, structure, members, assessment, representative,
  eligibilityUrl, limits, saveUrl, previousUrl, nextUrl,
}: Props) {
  const user = useAuthUser();
  const [values, setValues] = useState({ ...structure, members });

  const { state, savedAt, errors, response, flush, save } = useAutosave<typeof values, SaveResponse>(saveUrl, values);

  /** Le verdict vient toujours du serveur : celui du dernier enregistrement, sinon celui du chargement. */
  const verdict = response?.assessment ?? assessment;
  const type = verdict.type;
  const attendMembres = type === 'TEAM' || type === 'STARTUP';
  const attendStructure = type === 'STARTUP';

  const setChamp = (champ: keyof Structure) => (valeur: string) => setValues((v) => ({ ...v, [champ]: valeur }));

  const setMembre = (rang: number, champ: keyof Member, valeur: string | boolean) =>
    setValues((v) => ({ ...v, members: v.members.map((m, i) => (i === rang ? { ...m, [champ]: valeur } : m)) }));

  const ajouterMembre = () => setValues((v) => ({ ...v, members: [...v.members, { ...membreVide }] }));

  const retirerMembre = (rang: number) =>
    setValues((v) => ({ ...v, members: v.members.filter((_, i) => i !== rang) }));

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
                <p className="mt-5 max-w-2xl text-slate-600">
                  {type === 'INDIVIDUAL'
                    ? 'Vous candidatez à titre individuel : cette étape ne vous demande rien de plus.'
                    : 'Qui porte ce projet avec vous, et sous quelle forme. Vos réponses sont enregistrées au fur et à mesure.'}
                </p>
              </div>
              {user ? <div className="hidden items-center gap-2 text-slate-700 sm:flex"><UserRound size={30} className="text-brand-800" /><span className="text-sm font-semibold">Bonjour, {user.name.split(' ')[0]}</span><ChevronDown size={15} /></div> : null}
            </div>
            <div className="md:hidden"><SaveIndicator state={state} savedAt={savedAt} /></div>

            <div className="mt-4 grid gap-6 xl:grid-cols-[1fr_340px]">
              <div className="min-w-0 space-y-6">
                {type === null ? (
                  <Reveal><Card className="border-amber-200 bg-[#fffdf5] p-6" data-testid="type-inconnu">
                    <h2 className="text-lg font-black text-brand-950">Indiquez d’abord sous quelle forme vous candidatez</h2>
                    <p className="mt-2 text-sm leading-6 text-slate-700">Cette étape s’adapte à votre réponse : individuellement, en équipe ou au nom d’une startup.</p>
                    <Link href={eligibilityUrl} className="focus-ring mt-4 inline-flex text-sm font-bold text-brand-800 underline underline-offset-2">Aller à l’étape Éligibilité</Link>
                  </Card></Reveal>
                ) : null}

                {type === 'INDIVIDUAL' ? (
                  <Reveal><Card className="p-6 sm:p-7" data-testid="rien-a-renseigner">
                    <div className="flex gap-4">
                      <div className="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-800"><UserRound size={20} /></div>
                      <div>
                        <h2 className="text-lg font-extrabold tracking-tight text-ink-950">Aucune structure ni équipe à déclarer</h2>
                        <p className="mt-2 text-sm leading-6 text-slate-600">
                          Une candidature individuelle n’a ni personne morale ni autre membre à renseigner. Vous êtes le porteur du projet, et votre profil a déjà été renseigné à l’étape 2.
                        </p>
                        <p className="mt-2 text-sm leading-6 text-slate-600">
                          Si vous candidatez en réalité à plusieurs, revenez à l’étape 1 pour changer la forme de votre candidature.
                        </p>
                      </div>
                    </div>
                  </Card></Reveal>
                ) : null}

                {attendStructure ? (
                  <Reveal><Card className="p-6 sm:p-7" data-testid="bloc-structure">
                    <Groupe icone={<Building2 size={18} />} titre="La structure" aide="Les informations légales de votre startup constituée." />

                    <div className="grid gap-x-5 sm:grid-cols-2">
                      <Champ nom="structure_name" label="Dénomination" aide="Le nom officiel de la structure." requis erreur={errors.structure_name}>
                        <input id="structure_name" type="text" maxLength={limits.shortTextMax} className={saisie(errors.structure_name)} value={values.structure_name} onChange={(e) => setChamp('structure_name')(e.target.value)} onBlur={flush} />
                      </Champ>
                      <Champ nom="structure_acronym" label="Sigle" aide="S’il en existe un." requis={false} erreur={errors.structure_acronym}>
                        <input id="structure_acronym" type="text" maxLength={32} className={saisie(errors.structure_acronym)} value={values.structure_acronym} onChange={(e) => setChamp('structure_acronym')(e.target.value)} onBlur={flush} />
                      </Champ>
                      <Champ nom="structure_founded_year" label="Année de création" aide="L’année d’immatriculation ou de fondation." requis erreur={errors.structure_founded_year}>
                        <input id="structure_founded_year" type="number" inputMode="numeric" min={limits.foundedYearFloor} max={limits.foundedYearCeiling} className={saisie(errors.structure_founded_year)} value={values.structure_founded_year} onChange={(e) => setChamp('structure_founded_year')(e.target.value)} onBlur={flush} />
                      </Champ>
                      <Champ nom="structure_sector" label="Secteur d’activité" aide="Par exemple : numérique, agroalimentaire, énergie." requis erreur={errors.structure_sector}>
                        <input id="structure_sector" type="text" maxLength={limits.shortTextMax} className={saisie(errors.structure_sector)} value={values.structure_sector} onChange={(e) => setChamp('structure_sector')(e.target.value)} onBlur={flush} />
                      </Champ>
                    </div>

                    <Champ nom="structure_address" label="Adresse" aide="Où la structure est établie." requis erreur={errors.structure_address}>
                      <input id="structure_address" type="text" maxLength={limits.longTextMax} className={saisie(errors.structure_address)} value={values.structure_address} onChange={(e) => setChamp('structure_address')(e.target.value)} onBlur={flush} />
                    </Champ>

                    <div className="grid gap-x-5 sm:grid-cols-2">
                      <Champ nom="structure_rccm" label="RCCM" aide="Si votre structure en dispose." requis={false} erreur={errors.structure_rccm}>
                        <input id="structure_rccm" type="text" maxLength={64} className={saisie(errors.structure_rccm)} value={values.structure_rccm} onChange={(e) => setChamp('structure_rccm')(e.target.value)} onBlur={flush} />
                      </Champ>
                      <Champ nom="structure_nif" label="NIF" aide="Si votre structure en dispose." requis={false} erreur={errors.structure_nif}>
                        <input id="structure_nif" type="text" maxLength={64} className={saisie(errors.structure_nif)} value={values.structure_nif} onChange={(e) => setChamp('structure_nif')(e.target.value)} onBlur={flush} />
                      </Champ>
                      <Champ nom="structure_website" label="Site internet" aide="Adresse complète, par exemple https://exemple.ne" requis={false} erreur={errors.structure_website}>
                        <input id="structure_website" type="url" inputMode="url" className={saisie(errors.structure_website)} value={values.structure_website} onChange={(e) => setChamp('structure_website')(e.target.value)} onBlur={flush} />
                      </Champ>
                      <Champ nom="structure_social" label="Réseaux" aide="Vos pages ou comptes, séparés par des virgules." requis={false} erreur={errors.structure_social}>
                        <input id="structure_social" type="text" maxLength={limits.longTextMax} className={saisie(errors.structure_social)} value={values.structure_social} onChange={(e) => setChamp('structure_social')(e.target.value)} onBlur={flush} />
                      </Champ>
                    </div>
                  </Card></Reveal>
                ) : null}

                {attendMembres ? (
                  <Reveal delay={60}><Card className="p-6 sm:p-7" data-testid="bloc-membres">
                    <Groupe icone={<UsersRound size={18} />} titre="Les autres membres" aide="Vous n’avez pas à vous ajouter : vous êtes déjà connu comme représentant de cette candidature." />

                    {values.members.length === 0 ? (
                      <p className="mb-5 rounded-xl border border-dashed border-slate-300 bg-slate-50/50 p-5 text-sm leading-6 text-slate-600" data-testid="aucun-membre">
                        Aucun membre déclaré pour l’instant. Ajoutez les personnes qui portent le projet avec vous.
                      </p>
                    ) : null}

                    <div className="space-y-5">
                      {values.members.map((membre, rang) => (
                        <fieldset key={rang} className="rounded-2xl border border-slate-200 p-5" data-testid="membre">
                          <legend className="px-2 text-xs font-extrabold uppercase tracking-wide text-brand-800">Membre {rang + 1}</legend>

                          <div className="grid gap-x-5 sm:grid-cols-2">
                            <Champ nom={`members.${rang}.full_name`} label="Nom complet" aide="Prénom et nom." requis erreur={errors[`members.${rang}.full_name`]}>
                              <input id={`members.${rang}.full_name`} type="text" maxLength={limits.shortTextMax} className={saisie(errors[`members.${rang}.full_name`])} value={membre.full_name} onChange={(e) => setMembre(rang, 'full_name', e.target.value)} onBlur={flush} />
                            </Champ>
                            <Champ nom={`members.${rang}.role`} label="Rôle dans le projet" aide="Par exemple : développeuse, responsable terrain." requis erreur={errors[`members.${rang}.role`]}>
                              <input id={`members.${rang}.role`} type="text" maxLength={limits.shortTextMax} className={saisie(errors[`members.${rang}.role`])} value={membre.role} onChange={(e) => setMembre(rang, 'role', e.target.value)} onBlur={flush} />
                            </Champ>
                            <Champ nom={`members.${rang}.email`} label="Adresse e-mail" aide="E-mail ou téléphone : au moins un des deux." requis={false} erreur={errors[`members.${rang}.email`]}>
                              <input id={`members.${rang}.email`} type="email" inputMode="email" className={saisie(errors[`members.${rang}.email`])} value={membre.email} onChange={(e) => setMembre(rang, 'email', e.target.value)} onBlur={flush} />
                            </Champ>
                            <Champ nom={`members.${rang}.phone`} label="Téléphone" aide="Par exemple 90 12 34 56." requis={false} erreur={errors[`members.${rang}.phone`]}>
                              <input id={`members.${rang}.phone`} type="tel" inputMode="tel" className={saisie(errors[`members.${rang}.phone`])} value={membre.phone} onChange={(e) => setMembre(rang, 'phone', e.target.value)} onBlur={flush} />
                            </Champ>
                            <Champ nom={`members.${rang}.skills`} label="Compétences" aide="Ce que cette personne apporte au projet." requis={false} erreur={errors[`members.${rang}.skills`]}>
                              <input id={`members.${rang}.skills`} type="text" maxLength={limits.longTextMax} className={saisie(errors[`members.${rang}.skills`])} value={membre.skills} onChange={(e) => setMembre(rang, 'skills', e.target.value)} onBlur={flush} />
                            </Champ>
                            <Champ nom={`members.${rang}.availability`} label="Disponibilité" aide="Par exemple : temps plein, deux jours par semaine." requis={false} erreur={errors[`members.${rang}.availability`]}>
                              <input id={`members.${rang}.availability`} type="text" maxLength={limits.shortTextMax} className={saisie(errors[`members.${rang}.availability`])} value={membre.availability} onChange={(e) => setMembre(rang, 'availability', e.target.value)} onBlur={flush} />
                            </Champ>
                          </div>

                          {attendStructure ? (
                            <Case id={`members.${rang}.is_founder`} coche={membre.is_founder} onChange={(v) => { setMembre(rang, 'is_founder', v); flush(); }}>
                              Cette personne est cofondatrice de la structure
                            </Case>
                          ) : null}

                          <Case id={`members.${rang}.consent`} coche={membre.consent} onChange={(v) => { setMembre(rang, 'consent', v); flush(); }} requis>
                            Cette personne accepte de figurer dans cette candidature et que ses coordonnées y soient enregistrées
                          </Case>

                          <button type="button" onClick={() => { retirerMembre(rang); flush(); }} data-testid="retirer-membre"
                            className="focus-ring mt-4 inline-flex min-h-11 items-center gap-2 rounded-xl border border-red-200 bg-white px-4 text-sm font-bold text-red-600 transition-colors hover:bg-red-50">
                            <Trash2 size={16} /> Retirer ce membre
                          </button>
                        </fieldset>
                      ))}
                    </div>

                    <button type="button" onClick={ajouterMembre} data-testid="ajouter-membre"
                      disabled={values.members.length >= limits.membersCeiling}
                      className="focus-ring press-feedback mt-5 flex min-h-14 w-full items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 bg-slate-50/40 text-sm font-bold text-slate-700 transition-colors hover:bg-brand-50 disabled:cursor-not-allowed disabled:opacity-50">
                      <UserPlus size={18} /> Ajouter un membre
                    </button>
                  </Card></Reveal>
                ) : null}

                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div className="flex flex-wrap items-center gap-3">
                    {previousUrl ? (
                      <Link href={previousUrl} onClick={flush} data-testid="precedent"
                        className="focus-ring press-feedback inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-brand-900/35 bg-white px-5 text-sm font-bold text-brand-900 transition-colors hover:bg-brand-50">
                        <ArrowLeft size={16} /> Précédent
                      </Link>
                    ) : null}
                    <Button variant="ghost" type="button" onClick={save}><Save size={17} /> Enregistrer</Button>
                  </div>
                  {nextUrl ? (
                    <Link href={nextUrl} onClick={save} data-testid="suivant"
                      className="focus-ring press-feedback inline-flex min-h-11 min-w-44 items-center justify-center gap-2 rounded-xl bg-gold-500 px-5 text-sm font-bold text-ink-950 transition-colors hover:bg-gold-600">
                      Suivant <span aria-hidden>→</span>
                    </Link>
                  ) : (
                    <Button variant="secondary" className="min-w-44" disabled title="L’étape suivante n’est pas encore ouverte.">Suivant <span aria-hidden>→</span></Button>
                  )}
                </div>
              </div>

              <div className="space-y-5">
                <Reveal delay={80}>
                  <Card className="self-start border-brand-100 bg-brand-50/40 p-6" data-testid="type-candidature">
                    <div className="flex items-center gap-3">
                      <div className="grid h-10 w-10 place-items-center rounded-full bg-brand-100 text-brand-800"><Info size={20} /></div>
                      <h2 className="text-base font-black leading-tight text-brand-950">Votre candidature</h2>
                    </div>
                    <dl className="mt-4 space-y-3">
                      <div>
                        <dt className="text-[11px] font-extrabold uppercase tracking-wide text-slate-500">Forme</dt>
                        <dd className="mt-0.5 text-sm font-semibold text-slate-800" data-testid="type-libelle">{verdict.typeLabel ?? 'Non renseignée'}</dd>
                      </div>
                      {verdict.declaredSize !== null ? (
                        <div>
                          <dt className="text-[11px] font-extrabold uppercase tracking-wide text-slate-500">Effectif annoncé</dt>
                          <dd className="mt-0.5 text-sm font-semibold text-slate-800">{verdict.declaredSize} personnes</dd>
                        </div>
                      ) : null}
                      <div>
                        <dt className="text-[11px] font-extrabold uppercase tracking-wide text-slate-500">Représentant</dt>
                        <dd className="mt-0.5 break-words text-sm font-semibold text-slate-800">{representative.name ?? '—'}</dd>
                        <dd className="text-xs text-slate-500">{representative.email ?? ''}</dd>
                      </div>
                    </dl>
                    <p className="mt-4 text-xs leading-5 text-slate-500">La forme et l’effectif ont été indiqués à l’étape 1.</p>
                    <Link href={eligibilityUrl} className="focus-ring mt-2 inline-flex text-xs font-bold text-brand-800 underline underline-offset-2">Modifier à l’étape Éligibilité</Link>
                  </Card>
                </Reveal>

                {attendMembres ? (
                  <Reveal delay={140}>
                    <Card className={`self-start p-6 ${verdict.complete ? 'border-emerald-200 bg-emerald-50/60' : 'border-amber-200 bg-[#fffdf5]'}`} data-testid="etat-section">
                      <div className="flex items-center gap-3">
                        <div className={`grid h-10 w-10 place-items-center rounded-full ${verdict.complete ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'}`}>
                          {verdict.complete ? <Check size={20} /> : <CircleAlert size={20} />}
                        </div>
                        <h2 className="text-base font-black leading-tight text-brand-950">
                          {verdict.complete ? 'Étape complète' : 'Il reste à faire'}
                        </h2>
                      </div>

                      <p className="mt-3 text-xs leading-5 text-slate-600" data-testid="effectif-decrit">
                        Équipe décrite : {verdict.describedSize} personnes, vous compris.
                      </p>

                      {verdict.complete ? (
                        <p className="mt-2 text-xs leading-5 text-slate-600">Vous pourrez y revenir tant que votre dossier n’est pas soumis.</p>
                      ) : (
                        <ul className="mt-3 space-y-2">
                          {verdict.missing.map((motif) => (
                            <li key={motif} className="flex gap-2 text-xs leading-5 text-slate-700">
                              <CircleAlert size={14} className="mt-0.5 shrink-0 text-amber-600" aria-hidden />
                              {motif}
                            </li>
                          ))}
                        </ul>
                      )}
                    </Card>
                  </Reveal>
                ) : null}
              </div>
            </div>

            <div className="mt-6 flex items-center gap-2 text-xs text-slate-500"><Check className="text-brand-700" size={16} /> Les coordonnées des membres ne sont visibles que par l’équipe d’organisation.</div>
          </div>
        </div>
      </div>
    </CandidateLayout>
  );
}

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

function Case({ id, coche, onChange, requis = false, children }: { id: string; coche: boolean; onChange: (v: boolean) => void; requis?: boolean; children: ReactNode }) {
  return (
    <label className="mt-1 flex cursor-pointer items-start gap-3 py-2 text-sm leading-6 text-slate-700" htmlFor={id}>
      <input id={id} type="checkbox" checked={coche} onChange={(e) => onChange(e.target.checked)} className="mt-1 h-4 w-4 shrink-0 accent-brand-800" />
      <span>{children} {requis ? <span className="text-red-500" aria-hidden>*</span> : null}</span>
    </label>
  );
}
