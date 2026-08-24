import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, Lock } from 'lucide-react';
import { CandidateLayout } from '@/Layouts/CandidateLayout';
import { Card, Pill, SectionTitle } from '@/Components/Ui';

/**
 * Accuse de depot.
 *
 * Tout ce qui est affiche ici vient de la base : le numero et l'horodatage ont
 * ete ecrits par `SubmitApplication`, dans la meme transaction que le changement
 * de statut. Le navigateur n'en fabrique aucun.
 *
 * Ce que cet ecran s'interdit d'annoncer : un courriel ou un SMS de
 * confirmation, le demarrage d'une evaluation, une quelconque acceptation. Rien
 * de tout cela n'existe encore, et promettre un message qui n'arrivera jamais
 * ferait attendre le candidat pour rien.
 */
type Props = {
  application: {
    statusLabel: string;
    submissionNumber: string | null;
    submittedAt: string | null;
  };
  campaign: { name: string; code: string } | null;
  dashboardUrl: string;
};

const dateLongue = (iso: string | null) =>
  iso === null ? '—' : new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(iso));

export default function Submitted({ application, campaign, dashboardUrl }: Props) {
  return (
    <CandidateLayout active="Ma candidature">
      <Head title="Candidature soumise — BIRNIN GOBE" />

      <div className="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
        <Card className="p-6 sm:p-8" data-testid="accuse-depot">
          <div className="flex flex-wrap items-start gap-3">
            <CheckCircle2 className="mt-0.5 shrink-0 text-green-700" size={28} />
            <div className="min-w-0 flex-1">
              <h1 className="text-2xl font-black tracking-tight text-brand-950 sm:text-3xl">
                Candidature soumise avec succès
              </h1>
              <p className="mt-2 text-sm leading-6 text-slate-600">
                Votre dossier a bien été enregistré. Il est désormais verrouillé et ne peut plus être modifié.
              </p>
            </div>
          </div>

          <dl className="mt-6 grid gap-4 sm:grid-cols-3">
            <div>
              <dt className="text-xs font-bold uppercase tracking-wide text-slate-400">Numéro de candidature</dt>
              <dd className="mt-1 font-mono text-lg font-black text-brand-800" data-testid="numero-depot">
                {application.submissionNumber ?? '—'}
              </dd>
            </div>
            <div>
              <dt className="text-xs font-bold uppercase tracking-wide text-slate-400">Date et heure de soumission</dt>
              <dd className="mt-1 text-sm font-semibold text-slate-800" data-testid="date-depot">
                {dateLongue(application.submittedAt)}
              </dd>
            </div>
            <div>
              <dt className="text-xs font-bold uppercase tracking-wide text-slate-400">Statut</dt>
              <dd className="mt-1" data-testid="statut-depot">
                <Pill tone="green">{application.statusLabel}</Pill>
              </dd>
            </div>
          </dl>

          {campaign ? (
            <p className="mt-5 text-sm leading-6 text-slate-500">
              Édition {campaign.name} ({campaign.code}).
            </p>
          ) : null}

          <div className="mt-6 flex items-start gap-2 rounded-xl border border-slate-200 bg-slate-50/70 p-3.5">
            <Lock size={16} className="mt-0.5 shrink-0 text-slate-500" />
            <p className="text-sm leading-6 text-slate-600">
              Conservez ce numéro : c’est la référence de votre dossier. Cette page reste accessible
              depuis votre tableau de bord.
            </p>
          </div>

          <div className="mt-6">
            <Link
              href={dashboardUrl}
              className="focus-ring press-feedback inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-brand-900/35 bg-white px-5 text-sm font-bold text-brand-900 hover:bg-slate-50"
            >
              Retour au tableau de bord
            </Link>
          </div>
        </Card>
      </div>
    </CandidateLayout>
  );
}
