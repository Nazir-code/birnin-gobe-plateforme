import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Lock, TriangleAlert, UsersRound } from 'lucide-react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { ADMIN_LOGOUT, adminNav } from '@/Layouts/adminNav';
import { Button, Card, Pill, SectionTitle } from '@/Components/Ui';

/**
 * La comparaison de deux notations et son arbitrage — §11.3.
 *
 * **Les notes sont côte à côte, jamais résumées.** Aucune moyenne, aucune
 * médiane, aucun classement : le §11.3 veut cette règle « choisie et documentée
 * avant l'ouverture », et l'écran ne l'invente pas. Un chiffre unique
 * masquerait précisément ce qu'on est venu regarder.
 *
 * **Les notes sont nominatives.** C'est l'inverse de l'espace évaluateur, où
 * rien d'un collègue n'est visible — et ce n'est pas une contradiction :
 * l'indépendance protège la notation *pendant* qu'elle se fait. Après le
 * verrouillage, savoir qu'un évaluateur est systématiquement plus sévère est
 * une information que le §11.1 demande justement de prendre en compte.
 *
 * **Rien ici ne modifie une note**, et il n'y a aucun champ pour le faire. Le
 * responsable peut demander un avis de plus ou acter le désaccord ; retoucher
 * une notation lui est interdit, et une notation qu'un gestionnaire peut
 * retoucher n'est plus indépendante.
 *
 * **Les justifications de critère sont montrées avec les notes.** C'est là que
 * se lit la raison d'un écart, et la reléguer ailleurs obligerait à recoller
 * deux écrans pour comprendre un désaccord.
 */
type NoteEvaluateur = { evaluator: string; score: number | null; comment: string | null };

type Critere = {
  criterion: string;
  label: string;
  weight: number;
  min: number;
  max: number;
  gap: number;
  divergent: boolean;
  scores: NoteEvaluateur[];
};

type Revue = {
  id: number;
  outcome: string;
  outcomeLabel: string;
  reason: string;
  coveredEvaluations: number;
  observedGap: number;
  actor: string | null;
  reviewedAt: string | null;
};

type Props = {
  application: { id: number; submissionNumber: string | null; campaignName: string; statusLabel: string };
  threshold: number | null;
  reviewDue: boolean | null;
  maxGap: number;
  totalSpread: number | null;
  lockedCount: number;
  evaluators: {
    id: number;
    name: string;
    total: number | null;
    recommendation: string | null;
    comment: string | null;
    lockedAt: string | null;
  }[];
  criteria: Critere[];
  reviews: Revue[];
  outcomes: { value: string; label: string; help: string }[];
  limits: { maxScore: number };
  urls: { store: string; assignments: string; settings: string; back: string };
};

function fr(n: number): string {
  return n.toFixed(2).replace('.', ',').replace(/,00$/, '');
}

function jour(iso: string | null): string {
  return iso
    ? new Date(iso).toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' })
    : '—';
}

export default function DivergencesShow({
  application,
  threshold,
  reviewDue,
  maxGap,
  totalSpread,
  lockedCount,
  evaluators,
  criteria,
  reviews,
  outcomes,
  limits,
  urls,
}: Props) {
  const erreurs = (usePage().props as { errors?: Record<string, string> }).errors ?? {};

  const form = useForm<{ outcome: string; reason: string }>({ outcome: '', reason: '' });
  const choisie = outcomes.find((o) => o.value === form.data.outcome);

  return (
    <DarkSidebarLayout
      items={adminNav}
      active="Écarts de notation"
      title={application.submissionNumber ?? 'Dossier'}
      subtitle={`${application.campaignName} · ${application.statusLabel}`}
      logoutHref={ADMIN_LOGOUT}
    >
      <Head title={`Écart de notation ${application.submissionNumber ?? ''} — BIRNIN GOBE`} />

      <div className="mx-auto max-w-[1080px] p-5 sm:p-7">
        <Link
          href={urls.back}
          className="focus-ring inline-flex items-center gap-1.5 rounded-lg text-sm font-bold text-brand-800 hover:underline"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden />
          Revenir à la file des écarts
        </Link>

        {/* Les notations arrêtées, côte à côte */}
        <Card className="mt-4 p-4 sm:p-5">
          <SectionTitle
            eyebrow="§11.3 — Notations verrouillées"
            title={`${lockedCount} avis arrêtés`}
            aside={
              reviewDue === null ? (
                <Pill tone="neutral">Seuil non arrêté</Pill>
              ) : reviewDue ? (
                <Pill tone="gold">Revue due</Pill>
              ) : (
                <Pill tone="green">Arbitré</Pill>
              )
            }
          />

          <p className="mt-2 text-sm text-slate-600">
            Écart maximal <strong>{maxGap} / {limits.maxScore}</strong> sur un critère
            {threshold !== null ? ` (seuil : au-delà de ${fr(threshold)})` : ' — aucun seuil arrêté'}
            {totalSpread !== null ? ` · ${fr(totalSpread)} points d’écart sur la note globale` : ''}. Aucune note
            consolidée n’est calculée : la règle d’agrégation du §11.3 n’est pas arrêtée, et l’inventer produirait
            un classement fondé sur rien.
          </p>

          <div className="mt-4 grid gap-3 sm:grid-cols-2">
            {evaluators.map((e) => (
              <div key={e.id} className="rounded-xl border border-slate-200 p-4" data-testid={`evaluateur-${e.id}`}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-sm font-bold text-slate-900">{e.name}</p>
                    <p className="mt-0.5 text-[11px] text-slate-500">
                      <Lock className="mr-1 inline h-3 w-3" aria-hidden />
                      Verrouillée le {jour(e.lockedAt)}
                    </p>
                  </div>
                  <span className="shrink-0 text-sm font-black text-brand-900">
                    {e.total === null ? '—' : `${fr(e.total)} / 100`}
                  </span>
                </div>
                {e.recommendation ? (
                  <p className="mt-2 text-xs font-bold text-slate-700">{e.recommendation}</p>
                ) : null}
                {e.comment ? <p className="mt-1.5 text-xs leading-5 text-slate-600">{e.comment}</p> : null}
              </div>
            ))}
          </div>
        </Card>

        {/* Critère par critère */}
        <Card className="mt-5 p-4 sm:p-5">
          <SectionTitle eyebrow="§11.2 — Grille" title="Où porte le désaccord" />

          <ul className="mt-4 space-y-3" data-testid="criteres">
            {criteria.map((c) => (
              <li
                key={c.criterion}
                className={`rounded-xl border p-4 ${c.divergent ? 'border-amber-300 bg-amber-50/40' : 'border-slate-200'}`}
                data-testid={`critere-${c.criterion}`}
              >
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-sm font-bold text-slate-900">{c.label}</p>
                    <p className="mt-0.5 text-[11px] text-slate-500">{c.weight} pts</p>
                  </div>
                  <span
                    className={`shrink-0 text-xs font-bold ${c.divergent ? 'text-amber-800' : 'text-slate-500'}`}
                  >
                    Écart {c.gap} ({c.min} → {c.max})
                  </span>
                </div>

                <dl className="mt-3 grid gap-2 sm:grid-cols-2">
                  {c.scores.map((s, i) => (
                    <div key={`${c.criterion}-${i}`} className="rounded-lg bg-white/70 p-2.5">
                      <dt className="text-[11px] font-bold text-slate-500">{s.evaluator}</dt>
                      <dd className="text-sm font-bold text-slate-800">
                        {s.score === null ? '—' : `${s.score} / ${limits.maxScore}`}
                      </dd>
                      {s.comment ? (
                        <dd className="mt-1 text-xs leading-5 text-slate-600">{s.comment}</dd>
                      ) : null}
                    </div>
                  ))}
                </dl>
              </li>
            ))}
          </ul>
        </Card>

        {/* L'arbitrage */}
        <Card className="mt-5 p-4 sm:p-5">
          <SectionTitle eyebrow="§11.3 — Revue" title="Arbitrer le désaccord" />

          {threshold === null ? (
            <p className="mt-2 flex items-start gap-2 text-sm text-amber-900" data-testid="sans-seuil">
              <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
              <span>
                Aucun seuil d’écart n’est arrêté pour cette campagne : arbitrer reviendrait à trancher contre une
                règle que personne n’a fixée.{' '}
                <Link href={urls.settings} className="focus-ring rounded font-bold text-brand-800 hover:underline">
                  Réglez le seuil
                </Link>{' '}
                avant de revoir cet écart.
              </span>
            </p>
          ) : (
            <>
              <p className="mt-2 text-sm text-slate-600">
                Aucune note ne sera modifiée : les deux issues portent sur la suite, pas sur les notations.
              </p>

              {erreurs.review ? (
                <p className="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-800" role="alert" data-testid="refus">
                  {erreurs.review}
                </p>
              ) : null}

              <form
                className="mt-4"
                onSubmit={(e) => {
                  e.preventDefault();
                  form.post(urls.store, { preserveScroll: true });
                }}
              >
                <fieldset className="space-y-2">
                  <legend className="sr-only">Issue de la revue</legend>
                  {outcomes.map((o) => (
                    <label
                      key={o.value}
                      className={`flex cursor-pointer items-start gap-3 rounded-xl border p-3 ${
                        form.data.outcome === o.value ? 'border-brand-700 bg-brand-50' : 'border-slate-200'
                      }`}
                    >
                      <input
                        type="radio"
                        name="outcome"
                        value={o.value}
                        checked={form.data.outcome === o.value}
                        onChange={(e) => form.setData('outcome', e.target.value)}
                        className="focus-ring mt-0.5 h-4 w-4"
                        data-testid={`issue-${o.value}`}
                      />
                      <span className="min-w-0">
                        <span className="block text-sm font-bold text-slate-900">{o.label}</span>
                        <span className="mt-0.5 block text-xs text-slate-500">{o.help}</span>
                      </span>
                    </label>
                  ))}
                </fieldset>
                {form.errors.outcome ? (
                  <p className="mt-1 text-xs font-bold text-rose-700">{form.errors.outcome}</p>
                ) : null}

                <div className="mt-4">
                  <label htmlFor="reason" className="block text-sm font-bold text-slate-700">
                    Motif de l’arbitrage
                  </label>
                  <p className="mt-0.5 text-xs text-slate-500">
                    C’est cette phrase qui répondra plus tard à « pourquoi ce désaccord a-t-il été jugé
                    acceptable ? ».
                  </p>
                  <textarea
                    id="reason"
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    className="focus-ring mt-1.5 min-h-24 w-full rounded-xl border border-slate-300 p-3 text-sm"
                    data-testid="motif"
                  />
                  {form.errors.reason ? (
                    <p className="mt-1 text-xs font-bold text-rose-700">{form.errors.reason}</p>
                  ) : null}
                </div>

                <div className="mt-4 flex flex-wrap items-center gap-3">
                  {choisie?.value === 'ADDITIONAL_EVALUATION' ? (
                    <Link
                      href={urls.assignments}
                      className="focus-ring inline-flex items-center gap-1.5 rounded-lg text-sm font-bold text-brand-800 hover:underline"
                    >
                      <UsersRound className="h-4 w-4" aria-hidden />
                      Ouvrir l’écran d’affectation
                    </Link>
                  ) : null}
                  <Button type="submit" className="ml-auto" disabled={form.processing} data-testid="enregistrer-revue">
                    Enregistrer la revue
                  </Button>
                </div>
              </form>
            </>
          )}
        </Card>

        {/* L'historique */}
        {reviews.length > 0 ? (
          <Card className="mt-5 p-4 sm:p-5">
            <SectionTitle eyebrow="Historique" title={`${reviews.length} revue(s)`} />

            <ol className="mt-4 space-y-3" data-testid="historique">
              {reviews.map((r) => (
                <li key={r.id} className="rounded-xl border border-slate-200 p-4">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <p className="text-sm font-bold text-slate-900">{r.outcomeLabel}</p>
                    <span className="text-[11px] text-slate-500">
                      {jour(r.reviewedAt)} · {r.actor ?? 'compte supprimé'}
                    </span>
                  </div>
                  <p className="mt-1.5 text-sm leading-6 text-slate-600">{r.reason}</p>
                  <p className="mt-2 text-[11px] text-slate-500">
                    Vue sur {r.coveredEvaluations} notation(s), écart maximal constaté {fr(r.observedGap)}.
                  </p>
                </li>
              ))}
            </ol>
          </Card>
        ) : null}
      </div>
    </DarkSidebarLayout>
  );
}
