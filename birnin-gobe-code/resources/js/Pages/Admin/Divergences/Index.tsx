import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, Scale, Settings as SettingsIcon, TriangleAlert } from 'lucide-react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { ADMIN_LOGOUT, adminNav } from '@/Layouts/adminNav';
import { Card, Pill, SectionTitle } from '@/Components/Ui';
import type { Option } from '@/Components/Champs';

/**
 * La file des écarts de notation — §11.3.
 *
 * **Sans seuil arrêté, l'écran ne se tait pas : il dit pourquoi il ne peut rien
 * dire.** Une file vide laisserait croire qu'aucun dossier ne diverge, alors
 * que rien n'a été comparé — c'est le malentendu qu'ADR-014 refuse déjà pour la
 * couverture. Le bandeau renvoie vers les paramètres, qui sont le préalable.
 *
 * **L'écart s'exprime sur l'échelle 0–5, pas sur la note globale.** « 4 points
 * d'écart sur Faisabilité technique » nomme le désaccord ; « 22 points d'écart
 * sur 100 » dit seulement qu'il existe. L'écart des totaux est affiché à côté,
 * comme contexte, jamais comme motif.
 *
 * **Une revue périmée est signalée comme telle.** Un dossier revu à deux
 * évaluations qui en porte trois n'est plus arbitré : le désaccord n'est plus
 * le même. C'est ce qui empêche l'acquittement définitif — on ne fait pas taire
 * un écart, on le revoit tel qu'il est devenu.
 */
type Evaluateur = { name: string; total: number | null };

type Divergence = {
  applicationId: number;
  submissionNumber: string | null;
  statusLabel: string;
  campaignName: string;
  lockedCount: number;
  maxGap: number;
  totalSpread: number | null;
  threshold: number | null;
  reviewDue: boolean | null;
  divergentCriteria: { criterion: string; label: string; gap: number }[];
  evaluators: Evaluateur[];
  lastReview: {
    outcome: string;
    outcomeLabel: string;
    coveredEvaluations: number;
    reviewedAt: string | null;
    stale: boolean;
  } | null;
  showUrl: string;
};

type Props = {
  divergences: Divergence[];
  campaign: { id: number; name: string; code: string } | null;
  threshold: number | null;
  totalDue: number;
  filters: { scope: string; campaign: string };
  options: { scopes: Option[]; campaigns: Option[] };
  urls: { settings: string; reset: string };
};

function fr(n: number): string {
  return n.toFixed(2).replace('.', ',').replace(/,00$/, '');
}

export default function DivergencesIndex({
  divergences,
  campaign,
  threshold,
  totalDue,
  filters,
  options,
  urls,
}: Props) {
  const flash = (usePage().props as { flash?: { status?: string } }).flash;

  function naviguer(champ: 'scope' | 'campaign', valeur: string) {
    const params: Record<string, string> = { ...filters };
    params[champ] = valeur;
    router.get('/admin/divergences', Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '')), {
      preserveState: false,
      preserveScroll: true,
      replace: true,
    });
  }

  return (
    <DarkSidebarLayout
      items={adminNav}
      active="Écarts de notation"
      title="Écarts de notation"
      subtitle={campaign ? `${campaign.name} (${campaign.code})` : 'Aucune campagne active'}
      logoutHref={ADMIN_LOGOUT}
    >
      <Head title="Écarts de notation — BIRNIN GOBE" />

      <div className="mx-auto max-w-[1080px] p-5 sm:p-7">
        {flash?.status ? (
          <p className="mb-4 rounded-xl border border-brand-200 bg-brand-50 p-3 text-sm font-bold text-brand-900" role="status">
            {flash.status}
          </p>
        ) : null}

        {threshold === null ? (
          <Card className="border-amber-300 p-4 sm:p-5" data-testid="sans-seuil">
            <SectionTitle eyebrow="§9.2 — Préalable" title="Aucun seuil d’écart n’est arrêté" />
            <p className="mt-2 flex items-start gap-2 text-sm leading-6 text-amber-900">
              <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
              <span>
                Cet écran ne peut rien signaler tant que le comité n’a pas décidé à partir de quel écart une revue
                s’impose. Ce n’est pas qu’aucun dossier ne diverge : c’est qu’aucun n’a été comparé. Un seuil
                inventé ici ferait rouvrir des notations qui n’avaient rien d’anormal.
              </span>
            </p>
            <Link
              href={urls.settings}
              className="focus-ring mt-4 inline-flex items-center gap-2 rounded-lg text-sm font-bold text-brand-800 hover:underline"
            >
              <SettingsIcon className="h-4 w-4" aria-hidden />
              Régler le seuil d’écart
            </Link>
          </Card>
        ) : null}

        <Card className={`p-4 sm:p-5 ${threshold === null ? 'mt-5' : ''}`}>
          <SectionTitle
            eyebrow="§11.3 — Mécanique de notation"
            title={`${divergences.length} dossier${divergences.length > 1 ? 's' : ''} comparable${divergences.length > 1 ? 's' : ''}`}
            aside={
              threshold === null ? (
                <span className="text-xs font-bold text-slate-500">Seuil non arrêté</span>
              ) : (
                <span className="text-xs font-bold text-slate-500">
                  Seuil : écart &gt; {fr(threshold)} · {totalDue} à revoir
                </span>
              )
            }
          />

          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="scope" className="block text-sm font-bold text-slate-700">
                Périmètre
              </label>
              <select
                id="scope"
                value={filters.scope}
                onChange={(e) => naviguer('scope', e.target.value)}
                className="focus-ring mt-1.5 h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm"
              >
                {options.scopes.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label htmlFor="campaign" className="block text-sm font-bold text-slate-700">
                Campagne
              </label>
              <select
                id="campaign"
                value={filters.campaign}
                onChange={(e) => naviguer('campaign', e.target.value)}
                className="focus-ring mt-1.5 h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm"
              >
                <option value="">Campagne active</option>
                {options.campaigns.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </div>
          </div>

          {divergences.length === 0 ? (
            <div className="mt-6 flex flex-col items-center gap-3 rounded-xl border border-dashed border-slate-300 p-8 text-center">
              <CheckCircle2 className="h-8 w-8 text-slate-400" aria-hidden />
              <p className="text-sm font-bold text-slate-700">
                {filters.scope === 'a_revoir'
                  ? 'Aucun écart n’attend d’arbitrage.'
                  : 'Aucun dossier ne porte deux évaluations verrouillées.'}
              </p>
              <p className="max-w-md text-xs text-slate-500">
                Un dossier n’apparaît ici qu’à partir de deux notations arrêtées : avec une seule, il n’y a pas
                d’écart, seulement une notation en cours.
              </p>
            </div>
          ) : (
            <ul className="mt-4 space-y-3" data-testid="divergences">
              {divergences.map((d) => (
                <li key={d.applicationId}>
                  <Link
                    href={d.showUrl}
                    className="focus-ring press-feedback block rounded-xl border border-slate-200 p-4 transition-colors hover:bg-slate-50"
                    data-testid={`divergence-${d.applicationId}`}
                  >
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0">
                        <p className="text-xs font-black text-brand-900">
                          {d.submissionNumber ?? 'Dossier sans numéro'}
                        </p>
                        <p className="mt-1 text-sm font-bold text-slate-800">
                          {d.evaluators.map((e) => e.name).join(' · ')}
                        </p>
                        <p className="mt-1 text-[11px] text-slate-500">
                          {d.lockedCount} notation(s) verrouillée(s)
                          {d.totalSpread !== null ? ` · ${fr(d.totalSpread)} points d’écart sur 100` : ''}
                        </p>

                        {d.divergentCriteria.length > 0 ? (
                          <p className="mt-2 text-xs text-amber-800">
                            {d.divergentCriteria
                              .map((c) => `${c.label} (écart ${c.gap})`)
                              .join(' · ')}
                          </p>
                        ) : null}
                      </div>

                      <div className="flex shrink-0 flex-col items-end gap-2">
                        {d.reviewDue === null ? (
                          <Pill tone="neutral">Non comparé</Pill>
                        ) : d.reviewDue ? (
                          <Pill tone="gold">Revue due</Pill>
                        ) : (
                          <Pill tone="green">Arbitré</Pill>
                        )}

                        <span className="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600">
                          <Scale className="h-3.5 w-3.5" aria-hidden />
                          Écart max {d.maxGap} / 5
                        </span>

                        {d.lastReview ? (
                          <span className={`text-[11px] ${d.lastReview.stale ? 'text-amber-800' : 'text-slate-500'}`}>
                            {d.lastReview.stale
                              ? `Revu à ${d.lastReview.coveredEvaluations} avis — périmé`
                              : d.lastReview.outcomeLabel}
                          </span>
                        ) : null}

                        <span className="inline-flex items-center gap-1 text-xs font-bold text-brand-800">
                          Comparer
                          <ArrowRight className="h-3.5 w-3.5" aria-hidden />
                        </span>
                      </div>
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </DarkSidebarLayout>
  );
}
