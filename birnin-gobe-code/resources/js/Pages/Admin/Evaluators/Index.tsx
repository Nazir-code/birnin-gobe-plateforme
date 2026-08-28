import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { RotateCcw, Scale, UsersRound } from 'lucide-react';
import { useMemo, useState } from 'react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { ADMIN_LOGOUT, adminNav } from '@/Layouts/adminNav';
import { Button, Card, Pill, SectionTitle } from '@/Components/Ui';
import type { Option } from '@/Components/Champs';

/**
 * Affectation des dossiers aux évaluateurs — §11.1.
 *
 * Un écran, trois panneaux, et c'est délibéré : équilibrer une charge, c'est
 * comparer. Séparer « les évaluateurs » de « les dossiers à affecter »
 * obligerait à mémoriser une charge lue sur une autre page.
 *
 * Trois choses que cet écran s'impose :
 *
 *   **ne jamais proposer ce que le serveur refusera** — un évaluateur déjà
 *     affecté sur un dossier, ou qui s'en est récusé, sort de la liste des
 *     destinataires pour ce lot ;
 *   **dire quand il ne sait pas** — tant que le nombre minimal d'évaluations
 *     n'est pas arrêté (§9.2), la couverture d'un dossier est *inconnue*, pas
 *     insuffisante. Aucun rouge n'est affiché sur un seuil que personne n'a
 *     décidé ;
 *   **ne pas promettre d'équilibrage automatique** — l'expertise et la
 *     disponibilité du §11.1 ne sont modélisées nulle part. L'écran outille
 *     l'arbitrage humain, il ne le remplace pas.
 */
type Dossier = {
  id: number;
  submissionNumber: string | null;
  candidateName: string;
  campaignName: string;
  status: string;
  statusLabel: string;
  submittedAt: string | null;
  assignmentCount: number;
  covered: boolean | null;
  excludedEvaluators: number[];
  showUrl: string;
};

type Evaluateur = { id: number; name: string; email: string; load: number; accepted: number; conflicts: number };

type Affectation = {
  id: number;
  applicationId: number;
  submissionNumber: string | null;
  candidateName: string;
  evaluatorId: number;
  evaluatorName: string;
  status: string;
  statusLabel: string;
  assignedAt: string | null;
};

type MotifDeLevee = { value: string; label: string; definitive: boolean };

type Props = {
  applications: Dossier[];
  pagination: {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    from: number | null;
    to: number | null;
    previousUrl: string | null;
    nextUrl: string | null;
  };
  evaluators: Evaluateur[];
  assignments: Affectation[];
  settings: { minEvaluations: number | null; scoreGapThreshold: number | null; configured: boolean };
  filters: { campaign: string; search: string; sort: string };
  totalAssignable: number;
  options: { campaigns: Option[]; sorts: Option[]; releaseReasons: MotifDeLevee[] };
  assignUrl: string;
  resetUrl: string;
};

function dateCourte(iso: string | null) {
  if (!iso) return '—';
  return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium' }).format(new Date(iso));
}

export default function EvaluatorsIndex({
  applications,
  pagination,
  evaluators,
  assignments,
  settings,
  filters,
  totalAssignable,
  options,
  assignUrl,
  resetUrl,
}: Props) {
  const flash = (usePage().props as { flash?: { status?: string } }).flash;

  const [saisie, setSaisie] = useState(filters);
  const [selection, setSelection] = useState<number[]>([]);

  const affectation = useForm<{ evaluator_id: string; application_ids: number[] }>({
    evaluator_id: '',
    application_ids: [],
  });

  const levee = useForm({ status: '', reason: '' });
  const [leveeOuverte, setLeveeOuverte] = useState<number | null>(null);

  function appliquer(modifications: Partial<typeof filters>) {
    const prochain = { ...saisie, ...modifications };
    setSaisie(prochain);
    setSelection([]);

    router.get('/admin/evaluators', Object.fromEntries(Object.entries(prochain).filter(([, v]) => v !== '')), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  }

  /**
   * Les destinataires possibles pour la sélection courante.
   *
   * Un évaluateur écarté sur ne serait-ce qu'un dossier du lot est retiré :
   * le lot part en une transaction, donc un seul refus le ferait échouer en
   * entier. Mieux vaut ne pas le proposer.
   */
  const destinataires = useMemo(() => {
    if (selection.length === 0) return evaluators;

    const ecartes = new Set<number>();
    applications
      .filter((dossier) => selection.includes(dossier.id))
      .forEach((dossier) => dossier.excludedEvaluators.forEach((id) => ecartes.add(id)));

    return evaluators.filter((evaluateur) => !ecartes.has(evaluateur.id));
  }, [applications, evaluators, selection]);

  /** Le moins chargé d'abord : c'est la seule aide à l'équilibrage qui soit honnête. */
  const suggere = useMemo(
    () => [...destinataires].sort((a, b) => a.load - b.load)[0] ?? null,
    [destinataires],
  );

  function basculer(id: number) {
    setSelection((courant) => (courant.includes(id) ? courant.filter((n) => n !== id) : [...courant, id]));
  }

  function affecter() {
    affectation.transform(() => ({
      evaluator_id: affectation.data.evaluator_id,
      application_ids: selection,
    }));

    affectation.post(assignUrl, {
      preserveScroll: true,
      onSuccess: () => {
        setSelection([]);
        affectation.setData('evaluator_id', '');
      },
    });
  }

  return (
    <DarkSidebarLayout
      items={adminNav}
      active="Évaluateurs"
      title="Évaluateurs et affectations"
      subtitle="Présélection — PIDUREM / ANSI"
      logoutHref={ADMIN_LOGOUT}
    >
      <Head title="Évaluateurs — BIRNIN GOBE" />

      <div className="mx-auto max-w-[1280px] p-5 sm:p-7">
        {flash?.status ? (
          <p className="mb-4 rounded-xl border border-brand-200 bg-brand-50 p-3 text-sm font-bold text-brand-900" role="status">
            {flash.status}
          </p>
        ) : null}

        {/* Panneau 1 — les évaluateurs et leur charge */}
        <Card className="p-4 sm:p-5">
          <SectionTitle
            eyebrow="Charge courante"
            title={`${evaluators.length} évaluateur${evaluators.length > 1 ? 's' : ''}`}
            aside={
              <span className="text-xs font-bold text-slate-500">
                {settings.configured
                  ? `${settings.minEvaluations} évaluation(s) minimum par dossier`
                  : 'Nombre minimal d’évaluations non arrêté'}
              </span>
            }
          />

          {evaluators.length === 0 ? (
            <p className="mt-4 text-sm text-slate-600" data-testid="aucun-evaluateur">
              Aucun compte évaluateur n’existe encore. Ils sont provisionnés en ligne de commande (ADR-006), jamais
              par une inscription.
            </p>
          ) : (
            <ul className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3" data-testid="evaluateurs">
              {evaluators.map((evaluateur) => (
                <li key={evaluateur.id} className="rounded-xl border border-slate-200 p-3">
                  <p className="truncate text-sm font-bold text-slate-900">{evaluateur.name}</p>
                  <p className="truncate text-xs text-slate-500">{evaluateur.email}</p>
                  <div className="mt-2 flex flex-wrap gap-1.5">
                    <Pill tone={evaluateur.load === 0 ? 'neutral' : 'green'}>{evaluateur.load} dossier(s)</Pill>
                    {evaluateur.accepted > 0 ? <Pill tone="green">{evaluateur.accepted} pris en charge</Pill> : null}
                    {evaluateur.conflicts > 0 ? <Pill tone="red">{evaluateur.conflicts} conflit(s)</Pill> : null}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>

        {/* Panneau 2 — les dossiers à affecter */}
        <Card className="mt-5 p-4 sm:p-5">
          <SectionTitle
            eyebrow="À affecter"
            title={`${pagination.total} dossier${pagination.total > 1 ? 's' : ''} recevable${pagination.total > 1 ? 's' : ''}`}
            aside={
              filters.campaign !== '' || filters.search !== '' ? (
                <Link
                  href={resetUrl}
                  className="focus-ring inline-flex min-h-9 items-center gap-1.5 rounded-lg px-2 text-xs font-bold text-brand-800 hover:underline"
                  data-testid="reinitialiser-filtres"
                >
                  <RotateCcw className="h-3.5 w-3.5" aria-hidden />
                  Réinitialiser
                </Link>
              ) : undefined
            }
          />

          <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
              <label htmlFor="search" className="block text-sm font-bold text-slate-700">
                Recherche
              </label>
              <input
                id="search"
                type="search"
                value={saisie.search}
                onChange={(e) => setSaisie({ ...saisie, search: e.target.value })}
                onBlur={() => appliquer({ search: saisie.search })}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') appliquer({ search: saisie.search });
                }}
                placeholder="Numéro de dépôt, nom ou courriel"
                className="focus-ring mt-1.5 h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm"
              />
            </div>
            <Selecteur
              id="campaign"
              label="Campagne"
              value={saisie.campaign}
              options={options.campaigns}
              vide="Toutes les campagnes"
              onChange={(v) => appliquer({ campaign: v })}
            />
            <Selecteur
              id="sort"
              label="Tri"
              value={saisie.sort}
              options={options.sorts}
              onChange={(v) => appliquer({ sort: v })}
            />
          </div>

          {affectation.errors.application_ids ? (
            <p className="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-900" role="alert">
              {affectation.errors.application_ids}
            </p>
          ) : null}

          {applications.length === 0 ? (
            <p className="mt-6 text-sm text-slate-600" data-testid="rien-a-affecter">
              {totalAssignable === 0
                ? 'Aucun dossier n’a encore été déclaré recevable. L’affectation vient après le contrôle d’admissibilité.'
                : 'Aucun dossier ne correspond à ces filtres.'}
            </p>
          ) : (
            <ul className="mt-4 space-y-2" data-testid="dossiers-a-affecter">
              {applications.map((dossier) => (
                <li key={dossier.id} className="rounded-xl border border-slate-200 p-3">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <label className="flex min-w-0 cursor-pointer items-start gap-3">
                      <input
                        type="checkbox"
                        checked={selection.includes(dossier.id)}
                        onChange={() => basculer(dossier.id)}
                        className="mt-1 h-4 w-4"
                        data-testid={`selection-${dossier.id}`}
                        aria-label={`Sélectionner ${dossier.submissionNumber ?? dossier.candidateName}`}
                      />
                      <span className="min-w-0">
                        <span className="block truncate text-sm font-bold text-slate-900">
                          {dossier.submissionNumber ?? 'Sans numéro'} — {dossier.candidateName}
                        </span>
                        <span className="block truncate text-xs text-slate-500">
                          {dossier.campaignName} · déposé le {dateCourte(dossier.submittedAt)}
                        </span>
                      </span>
                    </label>

                    <div className="flex flex-wrap items-center gap-2">
                      <Pill tone={couvertureTon(dossier.covered)}>
                        {dossier.assignmentCount} affectation(s){couvertureSuffixe(dossier.covered)}
                      </Pill>
                      <Link
                        href={dossier.showUrl}
                        className="focus-ring rounded-lg px-2 py-1 text-xs font-bold text-brand-800 hover:underline"
                      >
                        Voir le dossier
                      </Link>
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          )}

          {pagination.lastPage > 1 ? (
            <nav className="mt-4 flex flex-wrap items-center justify-between gap-3" aria-label="Pagination">
              <p className="text-xs text-slate-500">
                {pagination.from}–{pagination.to} sur {pagination.total}
              </p>
              <div className="flex gap-2">
                <LienPage href={pagination.previousUrl} libelle="Précédent" testid="page-precedente" />
                <LienPage href={pagination.nextUrl} libelle="Suivant" testid="page-suivante" />
              </div>
            </nav>
          ) : null}

          {/* La barre d'affectation n'apparaît qu'une fois une sélection faite. */}
          {selection.length > 0 ? (
            <div className="mt-5 rounded-xl border border-brand-200 bg-brand-50 p-4" data-testid="barre-affectation">
              <p className="text-sm font-bold text-brand-900">
                {selection.length} dossier{selection.length > 1 ? 's' : ''} sélectionné
                {selection.length > 1 ? 's' : ''}
              </p>

              {destinataires.length === 0 ? (
                <p className="mt-2 text-sm text-brand-900" data-testid="aucun-destinataire">
                  Aucun évaluateur disponible pour cette sélection : tous sont déjà affectés ou en conflit déclaré sur
                  l’un de ces dossiers.
                </p>
              ) : (
                <div className="mt-3 flex flex-wrap items-end gap-3">
                  <div className="min-w-[240px] flex-1">
                    <label htmlFor="evaluator_id" className="block text-xs font-bold text-brand-900">
                      Confier à
                    </label>
                    <select
                      id="evaluator_id"
                      value={affectation.data.evaluator_id}
                      onChange={(e) => affectation.setData('evaluator_id', e.target.value)}
                      className="focus-ring mt-1.5 h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm"
                    >
                      <option value="">Choisir un évaluateur…</option>
                      {destinataires.map((evaluateur) => (
                        <option key={evaluateur.id} value={String(evaluateur.id)}>
                          {evaluateur.name} — {evaluateur.load} dossier(s)
                        </option>
                      ))}
                    </select>
                    {suggere ? (
                      <p className="mt-1 flex items-center gap-1 text-xs text-brand-900">
                        <Scale className="h-3.5 w-3.5" aria-hidden />
                        Le moins chargé : {suggere.name} ({suggere.load}). L’expertise et la disponibilité ne sont pas
                        connues du système.
                      </p>
                    ) : null}
                  </div>

                  <Button
                    onClick={affecter}
                    disabled={affectation.processing || affectation.data.evaluator_id === ''}
                    data-testid="affecter"
                  >
                    Affecter
                  </Button>
                </div>
              )}
            </div>
          ) : null}
        </Card>

        {/* Panneau 3 — les affectations en vigueur */}
        <Card className="mt-5 p-4 sm:p-5">
          <SectionTitle
            eyebrow="Affectations en vigueur"
            title={`${assignments.length} affectation${assignments.length > 1 ? 's' : ''}`}
          />

          {assignments.length === 0 ? (
            <p className="mt-4 text-sm text-slate-600" data-testid="aucune-affectation">
              Aucun dossier n’est actuellement confié.
            </p>
          ) : (
            <ul className="mt-4 space-y-2" data-testid="affectations">
              {assignments.map((ligne) => (
                <li key={ligne.id} className="rounded-xl border border-slate-200 p-3">
                  <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-bold text-slate-900">
                        {ligne.submissionNumber ?? 'Sans numéro'} — {ligne.candidateName}
                      </p>
                      <p className="truncate text-xs text-slate-500">
                        {ligne.evaluatorName} · {ligne.statusLabel} · {dateCourte(ligne.assignedAt)}
                      </p>
                    </div>
                    <Button
                      variant="ghost"
                      onClick={() => {
                        setLeveeOuverte(leveeOuverte === ligne.id ? null : ligne.id);
                        levee.setData({ status: '', reason: '' });
                      }}
                      data-testid={`lever-${ligne.id}`}
                    >
                      Lever
                    </Button>
                  </div>

                  {leveeOuverte === ligne.id ? (
                    <form
                      className="mt-3 grid grid-cols-1 gap-3 border-t border-slate-200 pt-3 sm:grid-cols-[220px_1fr_auto]"
                      onSubmit={(e) => {
                        e.preventDefault();
                        levee.delete(`/admin/evaluators/assignments/${ligne.id}`, {
                          preserveScroll: true,
                          onSuccess: () => setLeveeOuverte(null),
                        });
                      }}
                    >
                      <div>
                        <label htmlFor={`status-${ligne.id}`} className="block text-xs font-bold text-slate-700">
                          Motif
                        </label>
                        <select
                          id={`status-${ligne.id}`}
                          value={levee.data.status}
                          onChange={(e) => levee.setData('status', e.target.value)}
                          className="focus-ring mt-1.5 h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                        >
                          <option value="">Choisir…</option>
                          {options.releaseReasons.map((motif) => (
                            <option key={motif.value} value={motif.value}>
                              {motif.label}
                              {motif.definitive ? ' (définitif)' : ''}
                            </option>
                          ))}
                        </select>
                      </div>

                      <div>
                        <label htmlFor={`reason-${ligne.id}`} className="block text-xs font-bold text-slate-700">
                          Explication — exigée
                        </label>
                        <input
                          id={`reason-${ligne.id}`}
                          value={levee.data.reason}
                          onChange={(e) => levee.setData('reason', e.target.value)}
                          className="focus-ring mt-1.5 h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                        />
                      </div>

                      <div className="flex items-end">
                        <Button type="submit" variant="danger" disabled={levee.processing}>
                          Confirmer
                        </Button>
                      </div>

                      {levee.errors.status || levee.errors.reason ? (
                        <p className="text-xs font-bold text-rose-700 sm:col-span-3" role="alert">
                          {levee.errors.status ?? levee.errors.reason}
                        </p>
                      ) : null}
                    </form>
                  ) : null}
                </li>
              ))}
            </ul>
          )}
        </Card>

        <p className="mt-4 flex items-start gap-2 text-xs text-slate-500">
          <UsersRound className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
          Chaque affectation ouvre un dossier dans l’espace évaluateur, après acceptation de la charte (§11.1).
          Cet écran ne montre pas les notes : le §11.3 n’accorde au pilotage que l’avancement avant le
          verrouillage, et aucune route ne permet d’écrire une note depuis l’administration.
        </p>
      </div>
    </DarkSidebarLayout>
  );
}

/** « Inconnu » n'est pas « insuffisant » : sans seuil arrêté, aucune couleur d'alerte. */
function couvertureTon(couvert: boolean | null): 'green' | 'gold' | 'neutral' {
  if (couvert === null) return 'neutral';
  return couvert ? 'green' : 'gold';
}

function couvertureSuffixe(couvert: boolean | null): string {
  if (couvert === null) return '';
  return couvert ? ' · couvert' : ' · à compléter';
}

function Selecteur({
  id,
  label,
  value,
  options,
  vide,
  onChange,
}: {
  id: string;
  label: string;
  value: string;
  options: Option[];
  vide?: string;
  onChange: (v: string) => void;
}) {
  return (
    <div>
      <label htmlFor={id} className="block text-sm font-bold text-slate-700">
        {label}
      </label>
      <select
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="focus-ring mt-1.5 h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm"
      >
        {vide === undefined ? null : <option value="">{vide}</option>}
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </div>
  );
}

/** Un lien de pagination absent devient un bouton inerte, pas un lien mort. */
function LienPage({ href, libelle, testid }: { href: string | null; libelle: string; testid: string }) {
  if (href === null) {
    return (
      <span className="min-h-11 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold text-slate-300" aria-disabled>
        {libelle}
      </span>
    );
  }

  return (
    <Link
      href={href}
      preserveScroll
      data-testid={testid}
      className="focus-ring min-h-11 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50"
    >
      {libelle}
    </Link>
  );
}
