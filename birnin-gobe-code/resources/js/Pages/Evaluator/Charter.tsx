import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ShieldCheck, TriangleAlert } from 'lucide-react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { EVALUATOR_LOGOUT, evaluatorNav } from '@/Layouts/evaluatorNav';
import { Button, Card, SectionTitle } from '@/Components/Ui';

/**
 * La charte et la déclaration d'impartialité — §11.1.
 *
 * **C'est la porte du dossier, pas un bandeau au-dessus de lui.** Le cahier des
 * charges dit « avant d'accéder à un dossier » : l'écran ne montre donc du
 * dossier que son numéro, sa campagne et sa thématique. Afficher le contenu
 * derrière un rappel à signer ferait signer après avoir lu, ce qui vide la
 * déclaration d'impartialité de son objet.
 *
 * **La récusation est proposée ici, au même niveau que l'acceptation.** C'est le
 * moment où l'on découvre de quel dossier il s'agit, donc le moment où l'on sait
 * si l'on a un lien avec lui. La reléguer à un lien discret ferait accepter par
 * défaut ceux qui hésitent.
 *
 * La case à cocher n'est pas une formalité de plus : elle sépare le clic
 * distrait de l'engagement. C'est elle, et non le bouton, qui porte la mention
 * « je déclare ».
 */
type Props = {
  assignment: { id: number; assignedAt: string | null };
  application: { submissionNumber: string | null; campaignName: string; themeLabel: string };
  engagements: { title: string; text: string }[];
  urls: { accept: string; conflict: string; back: string };
};

export default function Charter({ assignment, application, engagements, urls }: Props) {
  const [engage, setEngage] = useState(false);
  const [recusationOuverte, setRecusationOuverte] = useState(false);

  const acceptation = useForm({});
  const recusation = useForm<{ reason: string }>({ reason: '' });

  return (
    <DarkSidebarLayout
      items={evaluatorNav}
      active="Mes dossiers"
      title="Engagement de l’évaluateur"
      subtitle={application.submissionNumber ?? 'Dossier affecté'}
      logoutHref={EVALUATOR_LOGOUT}
    >
      <Head title="Charte d’évaluation — BIRNIN GOBE" />

      <div className="mx-auto max-w-[820px] p-5 sm:p-7">
        <Link
          href={urls.back}
          className="focus-ring inline-flex items-center gap-1.5 rounded-lg text-sm font-bold text-brand-800 hover:underline"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden />
          Revenir à mes dossiers
        </Link>

        <Card className="mt-4 p-4 sm:p-6">
          <SectionTitle
            eyebrow="§11.1 — Avant d’accéder au dossier"
            title="Charte, confidentialité et déclaration d’impartialité"
          />

          <p className="mt-3 text-sm text-slate-600">
            Vous n’avez pas encore accès au contenu de ce dossier. Il porte le numéro{' '}
            <strong>{application.submissionNumber ?? '—'}</strong>, concourt dans la thématique{' '}
            <strong>{application.themeLabel}</strong> pour la campagne <strong>{application.campaignName}</strong>.
            Ces trois éléments suffisent à savoir si vous avez un lien avec lui.
          </p>

          <ul className="mt-5 space-y-3">
            {engagements.map((engagement) => (
              <li key={engagement.title} className="rounded-xl border border-slate-200 p-4">
                <p className="flex items-center gap-2 text-sm font-bold text-slate-900">
                  <ShieldCheck className="h-4 w-4 text-brand-700" aria-hidden />
                  {engagement.title}
                </p>
                <p className="mt-1.5 text-sm leading-6 text-slate-600">{engagement.text}</p>
              </li>
            ))}
          </ul>

          <form
            className="mt-6"
            onSubmit={(e) => {
              e.preventDefault();
              acceptation.post(urls.accept);
            }}
          >
            <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-300 bg-slate-50 p-4">
              <input
                type="checkbox"
                checked={engage}
                onChange={(e) => setEngage(e.target.checked)}
                className="focus-ring mt-0.5 h-5 w-5 shrink-0 rounded border-slate-400"
                data-testid="engagement"
              />
              <span className="text-sm font-bold text-slate-800">
                Je déclare accepter la charte, respecter la confidentialité et n’avoir aucun conflit d’intérêts sur ce
                dossier.
              </span>
            </label>

            <div className="mt-4 flex flex-wrap items-center gap-3">
              <Button type="submit" disabled={!engage || acceptation.processing} data-testid="accepter-charte">
                Accéder au dossier
              </Button>
              <Button
                type="button"
                variant="ghost"
                onClick={() => setRecusationOuverte((ouvert) => !ouvert)}
                data-testid="ouvrir-recusation"
              >
                Je me récuse sur ce dossier
              </Button>
            </div>
          </form>
        </Card>

        {recusationOuverte ? (
          <Card className="mt-5 border-amber-300 p-4 sm:p-5">
            <SectionTitle eyebrow="§11.1 — Conflit déclaré" title="Récusation" />

            <p className="mt-2 flex items-start gap-2 text-sm text-amber-900">
              <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
              <span>
                Ce dossier vous sera retiré et ne vous sera plus reproposé. Il sera confié à un autre évaluateur.
                Se récuser est un geste normal, et c’est ce qui protège la notation.
              </span>
            </p>

            <form
              className="mt-4"
              onSubmit={(e) => {
                e.preventDefault();
                recusation.post(urls.conflict);
              }}
            >
              <label htmlFor="reason" className="block text-sm font-bold text-slate-700">
                Nature du conflit
              </label>
              <textarea
                id="reason"
                value={recusation.data.reason}
                onChange={(e) => recusation.setData('reason', e.target.value)}
                className="focus-ring mt-1.5 min-h-24 w-full rounded-xl border border-slate-300 p-3 text-sm"
                placeholder="Lien personnel, professionnel ou financier avec le dossier, ses porteurs ou leur structure…"
                data-testid="motif-recusation"
              />
              {recusation.errors.reason ? (
                <p className="mt-1 text-xs font-bold text-rose-700">{recusation.errors.reason}</p>
              ) : null}

              <div className="mt-4 flex justify-end">
                <Button type="submit" variant="danger" disabled={recusation.processing} data-testid="confirmer-recusation">
                  Confirmer la récusation
                </Button>
              </div>
            </form>
          </Card>
        ) : null}

        <p className="mt-4 text-xs text-slate-500">
          Affecté le{' '}
          {assignment.assignedAt
            ? new Date(assignment.assignedAt).toLocaleDateString('fr-FR', {
                day: '2-digit',
                month: 'long',
                year: 'numeric',
              })
            : '—'}
          .
        </p>
      </div>
    </DarkSidebarLayout>
  );
}
