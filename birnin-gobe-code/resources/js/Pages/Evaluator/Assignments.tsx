import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, FileSignature, Inbox } from 'lucide-react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { EVALUATOR_LOGOUT, evaluatorNav } from '@/Layouts/evaluatorNav';
import { Card, Pill, SectionTitle } from '@/Components/Ui';

/**
 * Le plan de travail de l'évaluateur — §11.1.
 *
 * **Trois états, jamais confondus** : la charte reste à accepter, la notation
 * est en cours, l'évaluation est verrouillée. C'est la distinction utile, parce
 * que les trois appellent des gestes différents — signer, reprendre, ne plus
 * rien faire. Un unique libellé « à évaluer » les aurait aplatis, et le dossier
 * dont la charte n'est pas signée est justement celui qu'on oublie.
 *
 * **L'avancement se compte en critères notés, pas en pourcentage de note.**
 * 8/8 dit que la grille est remplie, pas que le dossier est bon : un dossier
 * entièrement noté peut valoir douze points sur cent. Afficher une barre de
 * progression à côté d'un score ferait lire l'un pour l'autre.
 *
 * **Aucune donnée d'un autre évaluateur.** Ni le nombre de personnes affectées
 * au même dossier, ni leurs notes : le §11.3 veut les évaluations indépendantes
 * jusqu'au verrouillage, et savoir que deux collègues ont déjà tranché suffirait
 * à faire hésiter sur une note isolée.
 */
type Affectation = {
  id: number;
  submissionNumber: string | null;
  campaignName: string;
  themeLabel: string;
  assignedAt: string | null;
  charterAccepted: boolean;
  evaluationStatus: string | null;
  evaluationStatusLabel: string | null;
  lockedAt: string | null;
  scoredCriteria: number;
  totalCriteria: number;
  totalScore: number | null;
  showUrl: string;
};

type Props = { assignments: Affectation[]; remaining: number };

function etat(affectation: Affectation): { tone: 'green' | 'gold' | 'neutral'; label: string } {
  if (affectation.evaluationStatus === 'LOCKED') return { tone: 'neutral', label: 'Verrouillée' };
  if (!affectation.charterAccepted) return { tone: 'gold', label: 'Charte à accepter' };
  return { tone: 'green', label: 'Notation en cours' };
}

function jour(iso: string | null): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' });
}

export default function Assignments({ assignments, remaining }: Props) {
  const flash = (usePage().props as { flash?: { status?: string } }).flash;

  return (
    <DarkSidebarLayout
      items={evaluatorNav}
      active="Mes dossiers"
      title="Mes dossiers affectés"
      subtitle={
        remaining === 0
          ? 'Toutes vos évaluations sont verrouillées.'
          : `${remaining} dossier${remaining > 1 ? 's' : ''} restant${remaining > 1 ? 's' : ''} à évaluer`
      }
      logoutHref={EVALUATOR_LOGOUT}
    >
      <Head title="Mes dossiers — Évaluation BIRNIN GOBE" />

      <div className="mx-auto max-w-[980px] p-5 sm:p-7">
        {flash?.status ? (
          <p
            className="mb-4 rounded-xl border border-brand-200 bg-brand-50 p-3 text-sm font-bold text-brand-900"
            role="status"
          >
            {flash.status}
          </p>
        ) : null}

        <Card className="p-4 sm:p-5">
          <SectionTitle
            eyebrow="§11.1 — Affectation"
            title={`${assignments.length} dossier${assignments.length > 1 ? 's' : ''} affecté${assignments.length > 1 ? 's' : ''}`}
            aside={
              <span className="text-xs font-bold text-slate-500">
                {assignments.filter((a) => a.evaluationStatus === 'LOCKED').length} verrouillée(s)
              </span>
            }
          />

          {assignments.length === 0 ? (
            <div className="mt-6 flex flex-col items-center gap-3 rounded-xl border border-dashed border-slate-300 p-8 text-center">
              <Inbox className="h-8 w-8 text-slate-400" aria-hidden />
              <p className="text-sm font-bold text-slate-700">Aucun dossier ne vous est affecté.</p>
              <p className="max-w-md text-xs text-slate-500">
                Les dossiers vous sont confiés par le responsable de campagne une fois leur recevabilité tranchée.
                Vous serez averti dès qu’un lot vous parvient.
              </p>
            </div>
          ) : (
            <ul className="mt-4 space-y-3" data-testid="affectations">
              {assignments.map((affectation) => {
                const { tone, label } = etat(affectation);

                return (
                  <li key={affectation.id}>
                    <Link
                      href={affectation.showUrl}
                      className="focus-ring press-feedback block rounded-xl border border-slate-200 p-4 transition-colors hover:bg-slate-50"
                      data-testid={`affectation-${affectation.id}`}
                    >
                      <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                          <p className="text-xs font-black text-brand-900">
                            {affectation.submissionNumber ?? 'Dossier sans numéro'}
                          </p>
                          <p className="mt-1 text-sm font-bold text-slate-800">{affectation.themeLabel}</p>
                          <p className="mt-1 text-[11px] text-slate-500">
                            {affectation.campaignName} · affecté le {jour(affectation.assignedAt)}
                          </p>
                        </div>

                        <div className="flex shrink-0 flex-col items-end gap-2">
                          <Pill tone={tone}>{label}</Pill>
                          {affectation.evaluationStatus === 'LOCKED' ? (
                            <span className="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600">
                              <CheckCircle2 className="h-3.5 w-3.5" aria-hidden />
                              {affectation.totalScore === null
                                ? 'Verrouillée'
                                : `${affectation.totalScore.toFixed(2).replace('.', ',')} / 100`}
                            </span>
                          ) : affectation.charterAccepted ? (
                            <span className="text-xs font-bold text-slate-600">
                              {affectation.scoredCriteria} / {affectation.totalCriteria} critères notés
                            </span>
                          ) : (
                            <span className="inline-flex items-center gap-1.5 text-xs font-bold text-amber-800">
                              <FileSignature className="h-3.5 w-3.5" aria-hidden />
                              Engagement requis
                            </span>
                          )}
                          <span className="inline-flex items-center gap-1 text-xs font-bold text-brand-800">
                            Ouvrir
                            <ArrowRight className="h-3.5 w-3.5" aria-hidden />
                          </span>
                        </div>
                      </div>
                    </Link>
                  </li>
                );
              })}
            </ul>
          )}
        </Card>
      </div>
    </DarkSidebarLayout>
  );
}
