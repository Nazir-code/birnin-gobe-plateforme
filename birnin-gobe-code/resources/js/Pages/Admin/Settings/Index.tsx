import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, CircleSlash, Settings as SettingsIcon, TriangleAlert } from 'lucide-react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { ADMIN_LOGOUT, adminNav } from '@/Layouts/adminNav';
import { Button, Card, Pill, SectionTitle } from '@/Components/Ui';
import type { Option } from '@/Components/Champs';

/**
 * Paramètres administrables — §9.2.
 *
 * **Cet écran est d'abord un inventaire, et il assume de dire non.** Les neuf
 * domaines du cahier des charges y figurent tous : deux sont administrables,
 * un l'est partiellement, six ne le sont pas. N'afficher que les trois outillés
 * ferait paraître le back-office complet, et le comité de pilotage
 * découvrirait le reste en production.
 *
 * L'état `PARTIEL` est signalé aussi nettement que l'absence, parce que c'est
 * lui qui trompe : croire l'évaluation paramétrée parce qu'on a fixé le nombre
 * d'évaluateurs, alors que les critères et leurs poids restent dans le code.
 *
 * Les deux domaines déjà outillés ne sont pas réimplémentés ici : l'écran
 * renvoie vers leurs formulaires. Un second formulaire écrivant les mêmes
 * colonnes finirait par diverger du premier.
 */
type Domaine = {
  value: string;
  label: string;
  scope: string;
  state: 'ADMINISTRABLE' | 'PARTIEL' | 'ABSENT';
  stateLabel: string;
  detail: string;
};

type Props = {
  domains: Domaine[];
  campaign: { id: number; name: string; code: string; campaignUrl: string; eligibilityUrl: string } | null;
  evaluation: { minEvaluations: number | null; scoreGapThreshold: number | null; configured: boolean };
  limits: { maxEvaluations: number; maxScoreGap: number };
  filters: { campaign: string };
  options: { campaigns: Option[] };
  campaignsUrl: string;
};

const tonParEtat: Record<Domaine['state'], 'green' | 'gold' | 'neutral'> = {
  ADMINISTRABLE: 'green',
  PARTIEL: 'gold',
  ABSENT: 'neutral',
};

export default function SettingsIndex({
  domains,
  campaign,
  evaluation,
  limits,
  filters,
  options,
  campaignsUrl,
}: Props) {
  const flash = (usePage().props as { flash?: { status?: string } }).flash;

  const form = useForm({
    min_evaluations: evaluation.minEvaluations === null ? '' : String(evaluation.minEvaluations),
    score_gap_threshold: evaluation.scoreGapThreshold === null ? '' : String(evaluation.scoreGapThreshold),
  });

  /** Le lien d'un domaine outillé, quand la campagne visée est connue. */
  function lien(domaine: Domaine): string | null {
    if (!campaign) return null;
    if (domaine.value === 'CAMPAGNE') return campaign.campaignUrl;
    if (domaine.value === 'ELIGIBILITE') return campaign.eligibilityUrl;
    return null;
  }

  return (
    <DarkSidebarLayout
      items={adminNav}
      active="Paramètres"
      title="Paramètres"
      subtitle={campaign ? `${campaign.name} (${campaign.code})` : 'Aucune campagne active'}
      logoutHref={ADMIN_LOGOUT}
    >
      <Head title="Paramètres — BIRNIN GOBE" />

      <div className="mx-auto max-w-[1080px] p-5 sm:p-7">
        {flash?.status ? (
          <p className="mb-4 rounded-xl border border-brand-200 bg-brand-50 p-3 text-sm font-bold text-brand-900" role="status">
            {flash.status}
          </p>
        ) : null}

        <Card className="p-4 sm:p-5">
          <SectionTitle eyebrow="Périmètre" title="Campagne paramétrée" />

          <div className="mt-4 max-w-md">
            <label htmlFor="campaign" className="block text-sm font-bold text-slate-700">
              Campagne
            </label>
            <select
              id="campaign"
              value={filters.campaign}
              onChange={(e) =>
                router.get(
                  '/admin/settings',
                  e.target.value === '' ? {} : { campaign: e.target.value },
                  { preserveState: false, preserveScroll: true, replace: true },
                )
              }
              className="focus-ring mt-1.5 h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm"
            >
              <option value="">Campagne active</option>
              {options.campaigns.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </div>

          {campaign === null ? (
            <p className="mt-4 text-sm text-slate-600" data-testid="aucune-campagne">
              Aucune campagne n’est ouverte. Les paramètres se rattachent à une édition :{' '}
              <Link href={campaignsUrl} className="focus-ring rounded font-bold text-brand-800 hover:underline">
                créez ou ouvrez une campagne
              </Link>{' '}
              avant de régler quoi que ce soit.
            </p>
          ) : null}
        </Card>

        {/* Le seul réglage que cet écran porte lui-même. */}
        {campaign !== null ? (
          <Card className="mt-5 p-4 sm:p-5">
            <SectionTitle
              eyebrow="§9.2 — Évaluation"
              title="Nombre d’évaluations et seuil d’écart"
              aside={<Pill tone={evaluation.configured ? 'green' : 'neutral'}>{evaluation.configured ? 'Arrêté' : 'Non arrêté'}</Pill>}
            />

            <p className="mt-2 text-sm text-slate-600">
              Tant que le nombre minimal d’évaluations n’est pas arrêté, la couverture d’un dossier reste{' '}
              <strong>inconnue</strong> — jamais « insuffisante ». Aucune alerte ne se déclenche sur un seuil que
              personne n’a décidé.
            </p>

            <form
              className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2"
              onSubmit={(e) => {
                e.preventDefault();
                form.put(`/admin/settings/campaigns/${campaign.id}/evaluation`, { preserveScroll: true });
              }}
            >
              <div>
                <label htmlFor="min_evaluations" className="block text-sm font-bold text-slate-700">
                  Évaluations minimales par dossier
                </label>
                <p className="mt-0.5 text-xs text-slate-500">Laisser vide tant que le comité ne l’a pas arrêté.</p>
                <input
                  id="min_evaluations"
                  type="number"
                  min={1}
                  max={limits.maxEvaluations}
                  value={form.data.min_evaluations}
                  onChange={(e) => form.setData('min_evaluations', e.target.value)}
                  className="focus-ring mt-1.5 h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm"
                />
                {form.errors.min_evaluations ? (
                  <p className="mt-1 text-xs font-bold text-rose-700">{form.errors.min_evaluations}</p>
                ) : null}
              </div>

              <div>
                <label htmlFor="score_gap_threshold" className="block text-sm font-bold text-slate-700">
                  Seuil d’écart déclenchant une revue
                </label>
                <p className="mt-0.5 text-xs text-slate-500">
                  Sur l’échelle 0 à {limits.maxScoreGap} du §11.3. La notation existe (ADR-015), mais la revue
                  d’écart qu’elle déclenche reste à construire : ce seuil est enregistré, rien ne le lit encore.
                </p>
                <input
                  id="score_gap_threshold"
                  type="number"
                  step="0.5"
                  min={0}
                  max={limits.maxScoreGap}
                  value={form.data.score_gap_threshold}
                  onChange={(e) => form.setData('score_gap_threshold', e.target.value)}
                  className="focus-ring mt-1.5 h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm"
                />
                {form.errors.score_gap_threshold ? (
                  <p className="mt-1 text-xs font-bold text-rose-700">{form.errors.score_gap_threshold}</p>
                ) : null}
              </div>

              <div className="sm:col-span-2 flex justify-end">
                <Button type="submit" disabled={form.processing} data-testid="enregistrer-evaluation">
                  Enregistrer
                </Button>
              </div>
            </form>
          </Card>
        ) : null}

        {/* L'inventaire des neuf domaines du §9.2. */}
        <Card className="mt-5 p-4 sm:p-5">
          <SectionTitle
            eyebrow="§9.2 — Inventaire"
            title={`${domains.length} domaines paramétrables`}
            aside={
              <span className="text-xs font-bold text-slate-500">
                {domains.filter((d) => d.state === 'ADMINISTRABLE').length} outillés ·{' '}
                {domains.filter((d) => d.state === 'ABSENT').length} pas encore
              </span>
            }
          />

          <ul className="mt-4 space-y-3" data-testid="domaines">
            {domains.map((domaine) => {
              const href = lien(domaine);

              return (
                <li key={domaine.value} className="rounded-xl border border-slate-200 p-4">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="text-sm font-bold text-slate-900">{domaine.label}</p>
                      <p className="mt-1 text-xs text-slate-500">{domaine.scope}</p>
                    </div>
                    <div className="flex shrink-0 flex-col items-end gap-2">
                      <Pill tone={tonParEtat[domaine.state]}>{domaine.stateLabel}</Pill>
                      {href ? (
                        <Link
                          href={href}
                          className="focus-ring inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-bold text-brand-800 hover:underline"
                          data-testid={`ouvrir-${domaine.value}`}
                        >
                          Régler
                          <ArrowRight className="h-3.5 w-3.5" aria-hidden />
                        </Link>
                      ) : null}
                    </div>
                  </div>

                  <p
                    className={`mt-3 flex items-start gap-2 text-xs ${
                      domaine.state === 'ABSENT' ? 'text-slate-600' : 'text-amber-800'
                    }`}
                  >
                    {domaine.state === 'ABSENT' ? (
                      <CircleSlash className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
                    ) : domaine.state === 'PARTIEL' ? (
                      <TriangleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
                    ) : (
                      <SettingsIcon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-500" aria-hidden />
                    )}
                    <span className={domaine.state === 'ADMINISTRABLE' ? 'text-slate-600' : undefined}>
                      {domaine.detail}
                    </span>
                  </p>
                </li>
              );
            })}
          </ul>
        </Card>
      </div>
    </DarkSidebarLayout>
  );
}
