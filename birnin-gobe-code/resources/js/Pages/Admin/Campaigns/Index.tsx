import { Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, CalendarRange, CheckCircle2, ListChecks, Pencil, Plus } from 'lucide-react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { ADMIN_LOGOUT, adminNav } from '@/Layouts/adminNav';
import { Card, Pill, SectionTitle } from '@/Components/Ui';
import { Reveal } from '@/Components/Reveal';

/**
 * Liste des campagnes.
 *
 * Tout vient de PostgreSQL : aucune donnée de démonstration sur cet écran.
 *
 * Deux notions y cohabitent volontairement, parce que les confondre empêche de
 * comprendre pourquoi une campagne ne reçoit rien :
 *   le **statut**, qui est une décision administrative ;
 *   la **fenêtre**, qui est un fait de calendrier.
 * Une campagne n'accepte de candidature que déclarée ouverte ET dans sa fenêtre
 * — c'est la règle d'`ActiveCampaign`, et l'écran l'énonce.
 */
type Campagne = {
  id: number;
  code: string;
  name: string;
  status: string;
  statusLabel: string;
  timezone: string;
  opensAt: string | null;
  closesAt: string | null;
  updatedAt: string | null;
  window: 'sans-calendrier' | 'a-venir' | 'en-cours' | 'echue';
  active: boolean;
  editUrl: string;
  eligibilityUrl: string;
  criteriaPublished: number;
  criteriaTotal: number;
};

const tonParStatut: Record<string, 'green' | 'gold' | 'neutral' | 'red'> = {
  DRAFT: 'gold',
  OPEN: 'green',
  CLOSED: 'neutral',
  ARCHIVED: 'neutral',
};

const fenetreLabel: Record<Campagne['window'], string> = {
  'sans-calendrier': 'Aucune date fixée',
  'a-venir': 'Fenêtre à venir',
  'en-cours': 'Fenêtre en cours',
  echue: 'Fenêtre échue',
};

/** Date lue dans le fuseau de la campagne : c'est celui qui fait foi. */
function dateLocale(iso: string | null, fuseau: string) {
  if (!iso) return '—';
  return new Intl.DateTimeFormat('fr-FR', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: fuseau,
  }).format(new Date(iso));
}

function dateCourte(iso: string | null) {
  if (!iso) return '—';
  return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
}

export default function CampaignsIndex({ campaigns, createUrl }: { campaigns: Campagne[]; createUrl: string }) {
  const flash = usePage<{ flash?: { status: string | null } }>().props.flash?.status ?? null;
  const active = campaigns.find((c) => c.active) ?? null;
  const ouverteHorsFenetre = campaigns.find((c) => c.status === 'OPEN' && !c.active) ?? null;

  return (
    <DarkSidebarLayout
      items={adminNav}
      active="Campagnes"
      title="Campagnes"
      subtitle="Éditions de la compétition — PIDUREM / ANSI"
      logoutHref={ADMIN_LOGOUT}
      headerActions={
        <Link href={createUrl} className="focus-ring press-feedback inline-flex min-h-11 items-center gap-2 rounded-xl bg-brand-800 px-4 text-sm font-bold text-white hover:bg-brand-900">
          <Plus size={17} /> Nouvelle campagne
        </Link>
      }
    >
      <Head title="Campagnes — BIRNIN GOBE" />
      <div className="mx-auto max-w-[1200px] p-5 sm:p-7">
        {flash ? (
          <div role="status" className="mb-4 flex items-center gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
            <CheckCircle2 size={17} className="shrink-0" /> {flash}
          </div>
        ) : null}

        <Reveal>
          <Card className="p-5">
            <SectionTitle title="Campagne active" />
            {active ? (
              <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <span className="text-lg font-extrabold text-brand-950">{active.name}</span>
                <span data-testid="campagne-active-code" className="rounded-lg bg-slate-100 px-2 py-0.5 font-mono text-xs font-bold text-slate-600">{active.code}</span>
                <Pill tone="green">Reçoit les candidatures</Pill>
                <span className="text-xs text-slate-500">
                  Clôture le {dateLocale(active.closesAt, active.timezone)} ({active.timezone})
                </span>
                {/* Une campagne peut recevoir des dossiers sans qu'aucun critère
                    ne soit publié : rien ne l'interdit, mais alors l'auto-test
                    ne peut écarter personne et répond « sous réserve » à tout le
                    monde. Le dire ici évite de le découvrir en fin de campagne. */}
                {active.criteriaPublished === 0 ? (
                  <p className="flex w-full items-start gap-2 text-xs text-amber-700" data-testid="alerte-criteres">
                    <AlertTriangle size={15} className="mt-0.5 shrink-0" />
                    <span>
                      Aucun critère d’éligibilité n’est publié pour cette édition : l’auto-test répond « sous réserve »
                      à tous les candidats.{' '}
                      <Link href={active.eligibilityUrl} className="focus-ring rounded font-bold underline">
                        Publier les critères
                      </Link>
                    </span>
                  </p>
                ) : null}
              </div>
            ) : (
              <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50/60 px-4 py-6 text-sm leading-6 text-slate-600">
                <p className="font-semibold text-slate-700">Aucune campagne ne reçoit de candidature.</p>
                {ouverteHorsFenetre ? (
                  <p className="mt-1.5 flex items-start gap-2 text-amber-700">
                    <AlertTriangle size={16} className="mt-0.5 shrink-0" />
                    <span>
                      « {ouverteHorsFenetre.name} » est déclarée ouverte mais sa {fenetreLabel[ouverteHorsFenetre.window].toLowerCase()} :
                      corrigez ses dates pour qu’elle accepte des dossiers.
                    </span>
                  </p>
                ) : (
                  <p className="mt-1.5">
                    Une campagne reçoit des dossiers lorsqu’elle est <strong>ouverte</strong> et que la date du jour tombe
                    dans sa fenêtre.
                  </p>
                )}
              </div>
            )}
          </Card>
        </Reveal>

        <Reveal delay={80}>
          <Card className="mt-4 p-5">
            <SectionTitle
              title={campaigns.length > 0 ? `Toutes les éditions (${campaigns.length})` : 'Toutes les éditions'}
            />

            {campaigns.length === 0 ? (
              <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50/60 px-4 py-10 text-center">
                <CalendarRange size={26} className="mx-auto text-slate-300" />
                <p className="mt-3 text-sm font-semibold text-slate-700">Aucune campagne enregistrée.</p>
                <p className="mx-auto mt-1 max-w-md text-xs leading-5 text-slate-500">
                  Tant qu’aucune campagne n’est ouverte, aucun candidat ne peut déposer de dossier.
                </p>
                <Link href={createUrl} className="focus-ring press-feedback mt-5 inline-flex min-h-11 items-center gap-2 rounded-xl bg-brand-800 px-4 text-sm font-bold text-white hover:bg-brand-900">
                  <Plus size={17} /> Créer la première campagne
                </Link>
              </div>
            ) : (
              /* Le tableau défile dans son propre conteneur : sous 320 px la page
                 elle-même ne doit jamais défiler horizontalement. */
              <div className="-mx-2 overflow-x-auto px-2">
                <table className="w-full min-w-[760px] border-collapse text-sm">
                  <caption className="sr-only">Campagnes enregistrées, de la plus récente à la plus ancienne</caption>
                  <thead>
                    <tr className="border-b border-slate-200 text-left text-[11px] uppercase tracking-wide text-slate-400">
                      <th scope="col" className="py-2.5 pr-3 font-bold">Campagne</th>
                      <th scope="col" className="py-2.5 pr-3 font-bold">Statut</th>
                      <th scope="col" className="py-2.5 pr-3 font-bold">Ouverture</th>
                      <th scope="col" className="py-2.5 pr-3 font-bold">Clôture</th>
                      <th scope="col" className="py-2.5 pr-3 font-bold">Critères</th>
                      <th scope="col" className="py-2.5 pr-3 font-bold">Modifiée</th>
                      <th scope="col" className="py-2.5 font-bold"><span className="sr-only">Actions</span></th>
                    </tr>
                  </thead>
                  <tbody>
                    {campaigns.map((campagne) => (
                      <tr key={campagne.id} className="border-b border-slate-100 last:border-0">
                        <td className="py-3 pr-3">
                          <div className="flex flex-wrap items-center gap-2">
                            <span className="font-bold text-slate-800">{campagne.name}</span>
                            {campagne.active ? <Pill tone="green">Active</Pill> : null}
                          </div>
                          <div className="mt-0.5 font-mono text-[11px] text-slate-400">{campagne.code}</div>
                        </td>
                        <td className="py-3 pr-3">
                          <Pill tone={tonParStatut[campagne.status] ?? 'neutral'}>{campagne.statusLabel}</Pill>
                          <div className="mt-1 text-[11px] text-slate-400">{fenetreLabel[campagne.window]}</div>
                        </td>
                        <td className="py-3 pr-3 text-slate-600">{dateLocale(campagne.opensAt, campagne.timezone)}</td>
                        <td className="py-3 pr-3 text-slate-600">{dateLocale(campagne.closesAt, campagne.timezone)}</td>
                        <td className="py-3 pr-3">
                          <span
                            data-testid={`criteres-${campagne.code}`}
                            className={`text-xs font-bold ${campagne.criteriaPublished === 0 ? 'text-amber-700' : 'text-slate-600'}`}
                          >
                            {campagne.criteriaPublished} / {campagne.criteriaTotal}
                          </span>
                          <div className="mt-0.5 text-[11px] text-slate-400">
                            {campagne.criteriaPublished === 0
                              ? 'Aucun critère publié'
                              : `critère${campagne.criteriaPublished > 1 ? 's' : ''} publié${campagne.criteriaPublished > 1 ? 's' : ''}`}
                          </div>
                        </td>
                        <td className="py-3 pr-3 text-[11px] text-slate-400">{dateCourte(campagne.updatedAt)}</td>
                        <td className="py-3 text-right">
                          <div className="flex flex-wrap justify-end gap-2">
                            <Link
                              href={campagne.eligibilityUrl}
                              aria-label={`Critères d’éligibilité de la campagne ${campagne.name}`}
                              className="focus-ring inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-slate-200 px-3 text-xs font-bold text-slate-600 hover:bg-slate-50"
                            >
                              <ListChecks size={14} /> Éligibilité
                            </Link>
                            <Link
                              href={campagne.editUrl}
                              aria-label={`Modifier la campagne ${campagne.name}`}
                              className="focus-ring inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-slate-200 px-3 text-xs font-bold text-slate-600 hover:bg-slate-50"
                            >
                              <Pencil size={14} /> Modifier
                            </Link>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            <p className="mt-4 text-[11px] leading-5 text-slate-400">
              Les heures sont affichées dans le fuseau déclaré par chaque campagne. Une campagne ne se supprime pas :
              les dossiers déposés lui restent rattachés. Archivez-la pour la retirer de l’exploitation.
            </p>
          </Card>
        </Reveal>
      </div>
    </DarkSidebarLayout>
  );
}
