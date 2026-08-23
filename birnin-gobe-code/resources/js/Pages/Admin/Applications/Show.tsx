import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CheckCircle2, CircleDashed, HelpCircle, Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { ADMIN_LOGOUT, adminNav } from '@/Layouts/adminNav';
import { Card, Pill, SectionTitle } from '@/Components/Ui';

/**
 * Détail d'une candidature, en lecture seule.
 *
 * Aucun formulaire, aucun bouton d'action : avant la soumission, le candidat
 * reste propriétaire de ses réponses. Cet écran répond à « où en est ce
 * dossier, et pourquoi ce résultat d'éligibilité », rien de plus. La décision
 * d'admissibilité viendra avec son propre workflow, et écrira à côté du
 * dossier — pas dedans.
 *
 * Les neuf sections viennent du serveur dans l'ordre du concours, chacune avec
 * son état. Une section qu'une phase ultérieure ajoutera apparaîtra ici
 * automatiquement : l'écran affiche l'état et le nombre de réponses de toute
 * section, et détaille les champs de celles dont le serveur connaît les
 * libellés.
 *
 * Le verdict et ses cinq règles sont rendus par le moteur d'éligibilité, jamais
 * recalculés ici — et sur les critères de la campagne **de ce dossier**.
 */
type Champ = { label: string; value: string };

type Section = {
  key: string;
  label: string;
  position: number;
  implemented: boolean;
  onOpenPath: boolean;
  state: 'complete' | 'incomplete' | 'non-commencee' | 'hors-parcours' | 'non-implementee';
  completedAt: string | null;
  answeredCount: number;
  fields: Champ[];
  members: Membre[] | null;
  team: SyntheseEquipe | null;
};

/** Fiche d'un membre d'equipe, en lecture seule. */
type Membre = {
  name: string;
  role: string;
  email: string;
  phone: string;
  skills: string;
  availability: string;
  founder: boolean;
  consent: boolean;
};

/** Verdict de l'etape 3, rendu par le domaine — jamais recalcule ici. */
type SyntheseEquipe = {
  complete: boolean;
  type: string | null;
  typeLabel: string | null;
  declaredSize: number | null;
  describedSize: number;
  sizeMismatch: boolean;
  missing: string[];
};

type Props = {
  application: {
    id: number;
    candidate: { name: string; email: string };
    campaign: {
      name: string;
      code: string;
      statusLabel: string | null;
      opensAt: string | null;
      closesAt: string | null;
      timezone: string | null;
    };
    status: string;
    statusLabel: string;
    completionPercent: number;
    completedSections: number;
    totalSections: number;
    currentStep: string | null;
    currentStepLabel: string | null;
    submissionNumber: string | null;
    submittedAt: string | null;
    createdAt: string | null;
    updatedAt: string | null;
    eligibility: {
      outcome: string;
      label: string;
      blocksNextSections: boolean;
      findings: { rule: string; label: string; status: string; message: string }[];
    };
    sections: Section[];
  };
  backUrl: string;
};

const tonParVerdict: Record<string, 'green' | 'gold' | 'neutral' | 'red'> = {
  ELIGIBLE: 'green',
  TO_CONFIRM: 'gold',
  INELIGIBLE: 'red',
  INCOMPLETE: 'neutral',
};

const etatSection: Record<Section['state'], { libelle: string; classe: string }> = {
  complete: { libelle: 'Complète', classe: 'border-emerald-200 bg-emerald-50/60' },
  incomplete: { libelle: 'Commencée, incomplète', classe: 'border-amber-200 bg-amber-50/60' },
  'non-commencee': { libelle: 'Non commencée', classe: 'border-slate-200 bg-slate-50/60' },
  'hors-parcours': { libelle: 'Hors du parcours ouvert', classe: 'border-slate-200 bg-slate-50/60' },
  'non-implementee': { libelle: 'Section non encore ouverte', classe: 'border-slate-200 bg-slate-50/40' },
};

function iconeRegle(statut: string) {
  if (statut === 'SATISFIED') return <CheckCircle2 size={16} className="mt-0.5 shrink-0 text-emerald-600" aria-hidden />;
  if (statut === 'BLOCKING') return <AlertTriangle size={16} className="mt-0.5 shrink-0 text-red-600" aria-hidden />;
  return <HelpCircle size={16} className="mt-0.5 shrink-0 text-slate-400" aria-hidden />;
}

function motRegle(statut: string) {
  return { SATISFIED: 'Remplie', BLOCKING: 'Bloquante', NOT_CONFIGURED: 'Critère non publié', UNANSWERED: 'Sans réponse' }[statut] ?? statut;
}

function dateLongue(iso: string | null) {
  if (!iso) return '—';
  return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
}

export default function ApplicationShow({ application, backUrl }: Props) {
  const a = application;

  return (
    <DarkSidebarLayout
      items={adminNav}
      active="Candidatures"
      title={`Candidature de ${a.candidate.name}`}
      subtitle={`${a.campaign.name} (${a.campaign.code})`}
      logoutHref={ADMIN_LOGOUT}
    >
      <Head title={`Candidature — ${a.candidate.name} — BIRNIN GOBE`} />
      <div className="mx-auto max-w-[980px] p-5 sm:p-7">
        <Link
          href={backUrl}
          className="focus-ring inline-flex min-h-9 items-center gap-1.5 rounded-lg text-sm font-bold text-brand-800 hover:underline"
        >
          <ArrowLeft size={16} /> Retour aux candidatures
        </Link>

        <p className="mt-4 flex items-start gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-3 text-xs leading-5 text-slate-600">
          <Lock size={15} className="mt-0.5 shrink-0 text-slate-400" aria-hidden />
          <span>
            Consultation seule. Tant que le dossier n’est pas soumis, ses réponses appartiennent au candidat :
            l’administration les lit, ne les corrige pas.
          </span>
        </p>

        <Card className="mt-4 p-5 sm:p-6">
          <SectionTitle title="Dossier" aside={<Pill tone="neutral">{a.statusLabel}</Pill>} />
          <dl className="grid gap-4 sm:grid-cols-2">
            <Donnee libelle="Candidat" valeur={a.candidate.name} />
            <Donnee libelle="Adresse e-mail" valeur={a.candidate.email} />
            <Donnee libelle="Campagne" valeur={`${a.campaign.name} (${a.campaign.code})`} />
            <Donnee libelle="Statut de la campagne" valeur={a.campaign.statusLabel ?? '—'} />
            <Donnee libelle="Étape en cours" valeur={a.currentStepLabel ?? '—'} />
            <Donnee
              libelle="Progression"
              valeur={`${a.completionPercent} % — ${a.completedSections} section${a.completedSections > 1 ? 's' : ''} achevée${a.completedSections > 1 ? 's' : ''} sur ${a.totalSections}`}
            />
            <Donnee libelle="Créée le" valeur={dateLongue(a.createdAt)} />
            <Donnee libelle="Dernière modification" valeur={dateLongue(a.updatedAt)} />
            <Donnee
              libelle="Numéro de dossier"
              valeur={a.submissionNumber ?? '—'}
              aide={a.submissionNumber === null ? 'Attribué à la soumission, qui n’est pas encore ouverte.' : undefined}
            />
            <Donnee
              libelle="Soumise le"
              valeur={dateLongue(a.submittedAt)}
              aide={a.submittedAt === null ? 'Le dossier n’a pas été soumis.' : undefined}
            />
          </dl>
        </Card>

        <Card className="mt-4 p-5 sm:p-6">
          <SectionTitle
            title="Éligibilité"
            aside={<Pill tone={tonParVerdict[a.eligibility.outcome] ?? 'neutral'}>{a.eligibility.label}</Pill>}
          />
          <ul className="grid gap-2" data-testid="regles-eligibilite">
            {a.eligibility.findings.map((constat) => (
              <li
                key={constat.rule}
                data-testid={`regle-${constat.rule}`}
                data-statut={constat.status}
                className="flex items-start gap-2.5 rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm"
              >
                {iconeRegle(constat.status)}
                <span>
                  <strong className="font-bold text-slate-800">{constat.label}</strong>
                  <span className="ml-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    {motRegle(constat.status)}
                  </span>
                  <span className="mt-0.5 block text-slate-600">{constat.message}</span>
                </span>
              </li>
            ))}
          </ul>
          <p className="mt-3 text-[11px] leading-5 text-slate-400">
            Résultat recalculé à l’instant sur les critères de {a.campaign.code}, la campagne de ce dossier. Il reste
            indicatif et ne vaut pas décision d’admissibilité.
          </p>
        </Card>

        <Card className="mt-4 p-5 sm:p-6">
          <SectionTitle title="Sections du dossier" />
          <div className="grid gap-3">
            {a.sections.map((section) => (
              <section
                key={section.key}
                data-testid={`section-${section.key}`}
                data-etat={section.state}
                className={`rounded-xl border p-3.5 ${etatSection[section.state].classe}`}
              >
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <h3 className="text-sm font-bold text-slate-800">
                    <span className="text-slate-400">{section.position}.</span> {section.label}
                  </h3>
                  <span className="inline-flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-slate-500">
                    {section.state === 'complete' ? (
                      <CheckCircle2 size={14} className="text-emerald-600" aria-hidden />
                    ) : (
                      <CircleDashed size={14} className="text-slate-400" aria-hidden />
                    )}
                    {etatSection[section.state].libelle}
                  </span>
                </div>

                {section.team ? <Equipe synthese={section.team} membres={section.members ?? []} /> : null}

                {section.fields.length > 0 ? (
                  <dl className="mt-3 grid gap-3 sm:grid-cols-2">
                    {section.fields.map((champ) => (
                      <Donnee key={champ.label} libelle={champ.label} valeur={champ.value} />
                    ))}
                  </dl>
                ) : section.team ? null : (
                  <p className="mt-1.5 text-xs leading-5 text-slate-500">
                    {section.answeredCount > 0
                      ? `${section.answeredCount} réponse${section.answeredCount > 1 ? 's' : ''} enregistrée${section.answeredCount > 1 ? 's' : ''}.`
                      : section.implemented
                        ? 'Aucune réponse enregistrée.'
                        : 'Cette étape n’est pas encore ouverte aux candidats.'}
                  </p>
                )}

                {section.implemented && !section.onOpenPath ? (
                  <p className="mt-2 text-[11px] leading-4 text-slate-500">
                    Développée, mais située derrière une étape qui ne l’est pas : elle ne compte pas dans la
                    progression.
                  </p>
                ) : null}
              </section>
            ))}
          </div>
        </Card>
      </div>
    </DarkSidebarLayout>
  );
}

/**
 * L'etape 3 vue par l'administration : forme de candidature, effectifs, membres.
 *
 * Tout vient du serveur — `TeamSectionAssessment` pour la synthese, les
 * reponses pour les fiches. Rien n'est recalcule ici, et rien n'est modifiable.
 *
 * Une candidature individuelle n'a ni structure ni membres : l'ecran le dit
 * plutot que d'afficher une equipe vide qui se lirait comme une donnee
 * manquante.
 */
function Equipe({ synthese, membres }: { synthese: SyntheseEquipe; membres: Membre[] }) {
  return (
    <div className="mt-3" data-testid="structure-equipe">
      <dl className="grid gap-3 sm:grid-cols-3">
        <Donnee libelle="Forme de candidature" valeur={synthese.typeLabel ?? '—'} />
        <Donnee
          libelle="Effectif déclaré (étape 1)"
          valeur={synthese.declaredSize === null ? '—' : `${synthese.declaredSize}`}
        />
        <Donnee libelle="Effectif décrit ici" valeur={`${synthese.describedSize}`} />
      </dl>

      {synthese.sizeMismatch ? (
        <p className="mt-2 text-xs font-semibold text-amber-700" data-testid="ecart-effectif">
          L’effectif déclaré à l’étape 1 et le nombre de membres décrits ici ne coïncident pas.
        </p>
      ) : null}

      {membres.length > 0 ? (
        <ul className="mt-3 grid gap-2" data-testid="membres-equipe">
          {membres.map((membre, index) => (
            <li key={`${membre.name}-${index}`} className="rounded-xl border border-slate-200 bg-white p-3">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-bold text-slate-800">{membre.name || '—'}</span>
                {membre.role ? <span className="text-xs text-slate-500">{membre.role}</span> : null}
                {membre.founder ? <Pill tone="gold">Fondateur</Pill> : null}
                <Pill tone={membre.consent ? 'green' : 'red'}>
                  {membre.consent ? 'Consentement donné' : 'Consentement manquant'}
                </Pill>
              </div>
              <dl className="mt-2 grid gap-2 sm:grid-cols-2">
                {membre.email ? <Donnee libelle="Adresse e-mail" valeur={membre.email} /> : null}
                {membre.phone ? <Donnee libelle="Téléphone" valeur={membre.phone} /> : null}
                {membre.skills ? <Donnee libelle="Compétences" valeur={membre.skills} /> : null}
                {membre.availability ? <Donnee libelle="Disponibilité" valeur={membre.availability} /> : null}
              </dl>
            </li>
          ))}
        </ul>
      ) : (
        <p className="mt-2 text-xs leading-5 text-slate-500" data-testid="aucun-membre">
          {synthese.type === 'INDIVIDUAL'
            ? 'Candidature individuelle : aucun membre à déclarer.'
            : 'Aucun membre décrit pour l’instant.'}
        </p>
      )}

      {synthese.missing.length > 0 ? (
        <div className="mt-3 rounded-xl border border-amber-200 bg-amber-50/60 p-3" data-testid="manques-equipe">
          <p className="text-xs font-bold text-amber-800">Ce qui manque à cette étape</p>
          <ul className="mt-1 list-disc pl-5 text-xs leading-5 text-amber-900">
            {synthese.missing.map((motif) => (
              <li key={motif}>{motif}</li>
            ))}
          </ul>
        </div>
      ) : null}
    </div>
  );
}

function Donnee({ libelle, valeur, aide }: { libelle: string; valeur: string; aide?: ReactNode }) {
  return (
    <div>
      <dt className="text-[11px] uppercase tracking-wide text-slate-400">{libelle}</dt>
      <dd className="mt-0.5 whitespace-pre-line text-sm font-semibold text-slate-700">{valeur}</dd>
      {aide ? <p className="mt-0.5 text-[11px] leading-4 text-slate-400">{aide}</p> : null}
    </div>
  );
}
