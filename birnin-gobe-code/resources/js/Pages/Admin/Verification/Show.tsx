import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, FileText, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { ADMIN_LOGOUT, adminNav } from '@/Layouts/adminNav';
import { Button, Card, Pill, SectionTitle } from '@/Components/Ui';

/**
 * Écran de contrôle d'admissibilité — §10.1.
 *
 * « Le module d'admissibilité présente le dossier dans un écran unique :
 * résumé, formulaire, pièces, règles applicables, anomalies automatiques et
 * historique. » C'est littéralement le plan de cette page, et c'est pourquoi le
 * dossier y est rendu en entier plutôt que derrière un lien : un vérificateur
 * qui doit changer d'écran pour relire une réponse coche de mémoire.
 *
 * Trois choses que cet écran s'interdit :
 *
 *   **décider à la place du vérificateur** — les signalements automatiques sont
 *     présentés comme des signalements, séparés de la grille, et aucune coche
 *     n'est pré-remplie à partir d'eux. Le §10.3 l'exige, et un défaut
 *     pré-coché serait accepté tel quel neuf fois sur dix ;
 *   **recalculer quoi que ce soit** — libellés, verdicts possibles, gravités et
 *     signalements arrivent mis en forme par le serveur ;
 *   **confondre les deux textes d'une décision** — l'observation interne et le
 *     message au candidat sont deux champs, côte à côte, jamais l'un recopié
 *     dans l'autre (§10.3).
 *
 * Les règles de cohérence (grille complète, motif réellement bloquant) sont
 * rejouées côté serveur sous verrou : cet écran les annonce, il ne les fait pas
 * respecter.
 */
type Verdict = { value: string; label: string; severity: 'SATISFIED' | 'ATTENTION' | 'BLOCKING'; requiresObservation: boolean };
type Controle = { value: string; label: string; help: string; outcomes: Verdict[] };
type Coche = { control: string; outcome: string | null; observation: string | null; actor: string | null; recordedAt: string | null };
type Signalement = { control: string; controlLabel: string; label: string; detail: string };

/** Une ligne de grille telle qu'elle part au serveur. */
type LigneGrille = { control: string; outcome: string; observation: string };

type Decision = {
  id: number;
  decision: string;
  decisionLabel: string;
  primaryReasonLabel: string | null;
  secondaryReasonLabel: string | null;
  internalNote: string | null;
  candidateMessage: string | null;
  respondBy: string | null;
  previousStatusLabel: string;
  newStatusLabel: string;
  actor: string | null;
  decidedAt: string | null;
};

type Champ = { label: string; value: string };
type Piece = { type: string; label: string; filename: string; size: number; uploadedAt: string | null; downloadUrl: string };
type Section = {
  key: string;
  label: string;
  position: number;
  state: string;
  answeredCount: number;
  fields: Champ[];
  documents: Piece[] | null;
};

type Props = {
  application: {
    id: number;
    candidate: { name: string; email: string };
    campaign: { name: string; code: string; closesAt: string | null };
    status: string;
    statusLabel: string;
    completionPercent: number;
    submissionNumber: string | null;
    submittedAt: string | null;
    eligibility: { outcome: string; label: string };
    sections: Section[];
  };
  checks: Coche[];
  findings: Signalement[];
  decisions: Decision[];
  matrix: Controle[];
  decisionOptions: {
    value: string;
    label: string;
    requiresPrimaryReason: boolean;
    requiresRespondBy: boolean;
    requiresCandidateMessage: boolean;
  }[];
  reasonOptions: { value: string; label: string }[];
  editable: boolean;
  queueUrl: string;
  saveChecksUrl: string;
  decideUrl: string;
};

const tonParGravite: Record<Verdict['severity'], 'green' | 'gold' | 'neutral' | 'red'> = {
  SATISFIED: 'green',
  ATTENTION: 'gold',
  BLOCKING: 'red',
};

function dateComplete(iso: string | null) {
  if (!iso) return '—';
  return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
}

export default function VerificationShow({
  application,
  checks,
  findings,
  decisions,
  matrix,
  decisionOptions,
  reasonOptions,
  editable,
  queueUrl,
  saveChecksUrl,
  decideUrl,
}: Props) {
  const flash = (usePage().props as { flash?: { status?: string } }).flash;

  /** La grille locale part de ce qui est déjà coché — jamais des signalements. */
  const [grille, setGrille] = useState<Record<string, { outcome: string; observation: string }>>(() =>
    Object.fromEntries(
      checks.map((coche) => [coche.control, { outcome: coche.outcome ?? '', observation: coche.observation ?? '' }]),
    ),
  );

  /**
   * La grille est postée telle que le serveur l'attend : un tableau de lignes.
   * Le type est déclaré ici et non déduit d'un objet vide, sans quoi
   * `errors.checks` — le refus que renvoie le domaine — n'existerait pas.
   */
  const formGrille = useForm<{ checks: LigneGrille[] }>({ checks: [] });
  const decision = useForm({
    decision: '',
    primary_reason: '',
    secondary_reason: '',
    internal_note: '',
    candidate_message: '',
    respond_by: '',
  });

  /** Les verdicts, à plat, pour retrouver la gravité d'une coche sans la recalculer. */
  const verdicts = useMemo(() => {
    const table: Record<string, Verdict> = {};
    matrix.forEach((controle) => controle.outcomes.forEach((verdict) => (table[verdict.value] = verdict)));
    return table;
  }, [matrix]);

  const signalementsParControle = useMemo(() => {
    const table: Record<string, Signalement[]> = {};
    findings.forEach((signalement) => {
      table[signalement.control] = [...(table[signalement.control] ?? []), signalement];
    });
    return table;
  }, [findings]);

  const choisie = decisionOptions.find((option) => option.value === decision.data.decision);

  /**
   * Les motifs proposés au rejet se limitent aux contrôles réellement bloquants.
   * Le serveur refuse les autres ; les masquer évite de faire choisir un motif
   * qui sera rejeté.
   */
  const motifsPossibles = reasonOptions.filter(
    (motif) => verdicts[grille[motif.value]?.outcome ?? '']?.severity === 'BLOCKING',
  );

  const grilleComplete = matrix.every((controle) => (grille[controle.value]?.outcome ?? '') !== '');

  function enregistrerGrille() {
    formGrille.transform(() => ({
      checks: Object.entries(grille)
        .filter(([, saisie]) => saisie.outcome !== '')
        .map(([control, saisie]) => ({ control, outcome: saisie.outcome, observation: saisie.observation })),
    }));

    formGrille.post(saveChecksUrl, { preserveScroll: true });
  }

  return (
    <DarkSidebarLayout
      items={adminNav}
      active="Files de vérification"
      title="Contrôle d’admissibilité"
      subtitle={application.submissionNumber ?? 'Dossier sans numéro'}
      logoutHref={ADMIN_LOGOUT}
    >
      <Head title={`Contrôle — ${application.submissionNumber ?? application.candidate.name}`} />

      <div className="mx-auto max-w-[1080px] p-5 sm:p-7">
        <Link
          href={queueUrl}
          className="focus-ring inline-flex min-h-9 items-center gap-1.5 rounded-lg px-2 text-sm font-bold text-brand-800 hover:underline"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden />
          Retour à la file
        </Link>

        {flash?.status ? (
          <p className="mt-4 rounded-xl border border-brand-200 bg-brand-50 p-3 text-sm font-bold text-brand-900" role="status">
            {flash.status}
          </p>
        ) : null}

        {/* Résumé */}
        <Card className="mt-4 p-4 sm:p-5">
          <SectionTitle
            eyebrow="Résumé"
            title={application.candidate.name}
            aside={<Pill tone={application.status === 'INADMISSIBLE' ? 'red' : 'gold'}>{application.statusLabel}</Pill>}
          />
          <dl className="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <Donnee libelle="Courriel" valeur={application.candidate.email} />
            <Donnee libelle="Campagne" valeur={`${application.campaign.name} (${application.campaign.code})`} />
            <Donnee libelle="Déposé le" valeur={dateComplete(application.submittedAt)} />
            <Donnee libelle="Clôture" valeur={dateComplete(application.campaign.closesAt)} />
            <Donnee libelle="Dossier rempli" valeur={`${application.completionPercent} %`} />
            <Donnee libelle="Auto-test d’éligibilité" valeur={application.eligibility.label} />
          </dl>
        </Card>

        {/* Anomalies automatiques */}
        <Card className="mt-5 p-4 sm:p-5">
          <SectionTitle eyebrow="Anomalies automatiques" title={`${findings.length} signalement${findings.length > 1 ? 's' : ''}`} />
          <p className="mt-2 flex items-start gap-2 text-xs text-slate-500">
            <ShieldCheck className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
            Un signalement n’exclut jamais un candidat à lui seul (§10.3). Aucune coche n’est pré-remplie à partir
            d’eux.
          </p>

          {findings.length === 0 ? (
            <p className="mt-4 text-sm text-slate-600" data-testid="aucun-signalement">
              Aucune anomalie détectée automatiquement. Les contrôles restent à faire.
            </p>
          ) : (
            <ul className="mt-4 space-y-2" data-testid="signalements">
              {findings.map((signalement, rang) => (
                <li key={`${signalement.control}-${rang}`} className="rounded-xl border border-amber-200 bg-amber-50 p-3">
                  <p className="text-sm font-bold text-amber-900">
                    {signalement.controlLabel} — {signalement.label}
                  </p>
                  <p className="mt-1 text-xs text-amber-800">{signalement.detail}</p>
                </li>
              ))}
            </ul>
          )}
        </Card>

        {/* Grille du §10.2 */}
        <Card className="mt-5 p-4 sm:p-5">
          <SectionTitle
            eyebrow="Matrice minimale d’admissibilité (§10.2)"
            title="Grille de contrôle"
            aside={
              <span className="text-xs font-bold text-slate-500" data-testid="grille-avancement">
                {matrix.filter((c) => (grille[c.value]?.outcome ?? '') !== '').length} / {matrix.length}
              </span>
            }
          />

          {formGrille.errors.checks ? (
            <p className="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-900" role="alert">
              {formGrille.errors.checks}
            </p>
          ) : null}

          <ul className="mt-4 space-y-4" data-testid="grille">
            {matrix.map((controle) => {
              const saisie = grille[controle.value] ?? { outcome: '', observation: '' };
              const verdict = verdicts[saisie.outcome];
              const signalements = signalementsParControle[controle.value] ?? [];

              return (
                <li key={controle.value} className="rounded-xl border border-slate-200 p-4">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                      <p className="text-sm font-bold text-slate-900">{controle.label}</p>
                      <p className="mt-0.5 text-xs text-slate-500">{controle.help}</p>
                    </div>
                    {verdict ? <Pill tone={tonParGravite[verdict.severity]}>{verdict.label}</Pill> : null}
                  </div>

                  {signalements.length > 0 ? (
                    <p className="mt-2 flex items-start gap-1.5 text-xs text-amber-800">
                      <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
                      {signalements.map((s) => s.label).join(' · ')}
                    </p>
                  ) : null}

                  <fieldset className="mt-3" disabled={!editable}>
                    <legend className="sr-only">Verdict pour « {controle.label} »</legend>
                    <div className="flex flex-wrap gap-2">
                      {controle.outcomes.map((option) => (
                        <label
                          key={option.value}
                          className={`focus-ring inline-flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-xs font-bold ${
                            saisie.outcome === option.value
                              ? 'border-brand-500 bg-brand-50 text-brand-900'
                              : 'border-slate-300 text-slate-700'
                          }`}
                        >
                          <input
                            type="radio"
                            name={`outcome-${controle.value}`}
                            value={option.value}
                            checked={saisie.outcome === option.value}
                            onChange={() =>
                              setGrille({
                                ...grille,
                                [controle.value]: { ...saisie, outcome: option.value },
                              })
                            }
                            className="h-4 w-4"
                          />
                          {option.label}
                        </label>
                      ))}
                    </div>

                    {verdict?.requiresObservation ? (
                      <div className="mt-3">
                        <label
                          htmlFor={`observation-${controle.value}`}
                          className="block text-xs font-bold text-slate-700"
                        >
                          Observation — exigée pour « {verdict.label} »
                        </label>
                        <textarea
                          id={`observation-${controle.value}`}
                          rows={2}
                          value={saisie.observation}
                          onChange={(e) =>
                            setGrille({
                              ...grille,
                              [controle.value]: { ...saisie, observation: e.target.value },
                            })
                          }
                          className="focus-ring mt-1.5 w-full rounded-xl border border-slate-300 bg-white p-3 text-sm"
                        />
                      </div>
                    ) : null}
                  </fieldset>

                  {checks.find((c) => c.control === controle.value)?.recordedAt ? (
                    <p className="mt-2 text-xs text-slate-400">
                      Enregistré le {dateComplete(checks.find((c) => c.control === controle.value)?.recordedAt ?? null)}
                      {checks.find((c) => c.control === controle.value)?.actor
                        ? ` par ${checks.find((c) => c.control === controle.value)?.actor}`
                        : ''}
                    </p>
                  ) : null}
                </li>
              );
            })}
          </ul>

          {editable ? (
            <div className="mt-4 flex justify-end">
              <Button onClick={enregistrerGrille} disabled={formGrille.processing} data-testid="enregistrer-grille">
                Enregistrer la grille
              </Button>
            </div>
          ) : (
            <p className="mt-4 text-sm text-slate-600">
              Ce dossier a été décidé : sa grille n’est plus modifiable.
            </p>
          )}
        </Card>

        {/* Décision */}
        {editable ? (
          <Card className="mt-5 p-4 sm:p-5">
            <SectionTitle eyebrow="Décision (§10.3)" title="Trancher l’admissibilité" />

            {decision.errors.decision ? (
              <p className="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-900" role="alert">
                {decision.errors.decision}
              </p>
            ) : null}

            {!grilleComplete ? (
              <p className="mt-3 text-sm text-slate-600" data-testid="grille-incomplete">
                Les sept contrôles doivent être renseignés et enregistrés avant toute décision.
              </p>
            ) : null}

            <form
              className="mt-4 space-y-4"
              onSubmit={(e) => {
                e.preventDefault();
                decision.post(decideUrl, { preserveScroll: true });
              }}
            >
              <div>
                <label htmlFor="decision" className="block text-sm font-bold text-slate-700">
                  Décision
                </label>
                <select
                  id="decision"
                  value={decision.data.decision}
                  onChange={(e) => decision.setData('decision', e.target.value)}
                  className="focus-ring mt-1.5 h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm"
                >
                  <option value="">Choisir…</option>
                  {decisionOptions.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
              </div>

              {choisie?.requiresPrimaryReason ? (
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Motif
                    id="primary_reason"
                    label="Motif principal"
                    aide="Un contrôle de la grille dont le verdict bloque."
                    value={decision.data.primary_reason}
                    options={motifsPossibles}
                    erreur={decision.errors.primary_reason}
                    onChange={(v) => decision.setData('primary_reason', v)}
                  />
                  <Motif
                    id="secondary_reason"
                    label="Motif secondaire (facultatif)"
                    aide="Différent du motif principal."
                    value={decision.data.secondary_reason}
                    options={motifsPossibles}
                    erreur={decision.errors.secondary_reason}
                    onChange={(v) => decision.setData('secondary_reason', v)}
                  />
                </div>
              ) : null}

              {choisie?.requiresRespondBy ? (
                <div>
                  <label htmlFor="respond_by" className="block text-sm font-bold text-slate-700">
                    Date limite de réponse
                  </label>
                  <input
                    id="respond_by"
                    type="date"
                    value={decision.data.respond_by}
                    onChange={(e) => decision.setData('respond_by', e.target.value)}
                    className="focus-ring mt-1.5 h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm"
                  />
                  {decision.errors.respond_by ? (
                    <p className="mt-1 text-xs font-bold text-rose-700">{decision.errors.respond_by}</p>
                  ) : null}
                </div>
              ) : null}

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <label htmlFor="internal_note" className="block text-sm font-bold text-slate-700">
                    Observation interne
                  </label>
                  <p className="mt-0.5 text-xs text-slate-500">Ne quitte jamais l’administration.</p>
                  <textarea
                    id="internal_note"
                    rows={4}
                    value={decision.data.internal_note}
                    onChange={(e) => decision.setData('internal_note', e.target.value)}
                    className="focus-ring mt-1.5 w-full rounded-xl border border-slate-300 bg-white p-3 text-sm"
                  />
                </div>
                <div>
                  <label htmlFor="candidate_message" className="block text-sm font-bold text-slate-700">
                    Message au candidat
                  </label>
                  <p className="mt-0.5 text-xs text-slate-500">
                    Distinct de l’observation : il ne doit rien divulguer de sensible.
                  </p>
                  <textarea
                    id="candidate_message"
                    rows={4}
                    value={decision.data.candidate_message}
                    onChange={(e) => decision.setData('candidate_message', e.target.value)}
                    className="focus-ring mt-1.5 w-full rounded-xl border border-slate-300 bg-white p-3 text-sm"
                  />
                  {decision.errors.candidate_message ? (
                    <p className="mt-1 text-xs font-bold text-rose-700">{decision.errors.candidate_message}</p>
                  ) : null}
                </div>
              </div>

              <div className="flex justify-end">
                <Button
                  type="submit"
                  disabled={decision.processing || decision.data.decision === ''}
                  data-testid="enregistrer-decision"
                >
                  Enregistrer la décision
                </Button>
              </div>
            </form>
          </Card>
        ) : null}

        {/* Historique */}
        <Card className="mt-5 p-4 sm:p-5">
          <SectionTitle eyebrow="Historique" title={`${decisions.length} décision${decisions.length > 1 ? 's' : ''}`} />
          {decisions.length === 0 ? (
            <p className="mt-4 text-sm text-slate-600" data-testid="historique-vide">
              Aucune décision n’a encore été prise sur ce dossier.
            </p>
          ) : (
            <ol className="mt-4 space-y-3" data-testid="historique">
              {decisions.map((ligne) => (
                <li key={ligne.id} className="rounded-xl border border-slate-200 p-4">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm font-bold text-slate-900">{ligne.decisionLabel}</p>
                    <p className="text-xs text-slate-500">{dateComplete(ligne.decidedAt)}</p>
                  </div>
                  <p className="mt-1 text-xs text-slate-500">
                    {ligne.previousStatusLabel} → {ligne.newStatusLabel}
                    {ligne.actor ? ` · ${ligne.actor}` : ''}
                  </p>
                  {ligne.primaryReasonLabel ? (
                    <p className="mt-2 text-xs text-slate-700">
                      Motif : {ligne.primaryReasonLabel}
                      {ligne.secondaryReasonLabel ? ` · ${ligne.secondaryReasonLabel}` : ''}
                    </p>
                  ) : null}
                  {ligne.respondBy ? (
                    <p className="mt-1 text-xs text-slate-700">Réponse attendue avant le {ligne.respondBy}</p>
                  ) : null}
                  {ligne.internalNote ? (
                    <p className="mt-2 whitespace-pre-line text-xs text-slate-600">Interne : {ligne.internalNote}</p>
                  ) : null}
                  {ligne.candidateMessage ? (
                    <p className="mt-1 whitespace-pre-line text-xs text-slate-600">
                      Au candidat : {ligne.candidateMessage}
                    </p>
                  ) : null}
                </li>
              ))}
            </ol>
          )}
        </Card>

        {/* Le dossier lui-même */}
        <Card className="mt-5 p-4 sm:p-5">
          <SectionTitle eyebrow="Dossier" title="Formulaire et pièces" />
          <div className="mt-4 space-y-4">
            {application.sections.map((section) => (
              <section key={section.key} className="rounded-xl border border-slate-200 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-sm font-bold text-slate-900">
                    {section.position}. {section.label}
                  </p>
                  <span className="text-xs text-slate-500">{section.answeredCount} réponse(s)</span>
                </div>

                {section.fields.length > 0 ? (
                  <dl className="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    {section.fields.map((champ) => (
                      <Donnee key={champ.label} libelle={champ.label} valeur={champ.value} />
                    ))}
                  </dl>
                ) : null}

                {section.documents && section.documents.length > 0 ? (
                  <ul className="mt-3 space-y-2">
                    {section.documents.map((piece) => (
                      <li key={piece.type}>
                        <a
                          href={piece.downloadUrl}
                          className="focus-ring inline-flex items-center gap-2 rounded-lg text-sm font-bold text-brand-800 hover:underline"
                        >
                          <FileText className="h-4 w-4" aria-hidden />
                          {piece.label} — {piece.filename}
                        </a>
                      </li>
                    ))}
                  </ul>
                ) : null}
              </section>
            ))}
          </div>
        </Card>
      </div>
    </DarkSidebarLayout>
  );
}

function Donnee({ libelle, valeur }: { libelle: string; valeur: string }) {
  return (
    <div>
      <dt className="text-xs font-bold text-slate-500">{libelle}</dt>
      <dd className="text-sm text-slate-800">{valeur}</dd>
    </div>
  );
}

function Motif({
  id,
  label,
  aide,
  value,
  options,
  erreur,
  onChange,
}: {
  id: string;
  label: string;
  aide: string;
  value: string;
  options: { value: string; label: string }[];
  erreur?: string;
  onChange: (v: string) => void;
}) {
  return (
    <div>
      <label htmlFor={id} className="block text-sm font-bold text-slate-700">
        {label}
      </label>
      <p className="mt-0.5 text-xs text-slate-500">{aide}</p>
      <select
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="focus-ring mt-1.5 h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm"
      >
        <option value="">Aucun</option>
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
      {erreur ? <p className="mt-1 text-xs font-bold text-rose-700">{erreur}</p> : null}
    </div>
  );
}
