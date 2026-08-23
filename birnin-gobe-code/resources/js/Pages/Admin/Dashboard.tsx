import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CalendarRange, ClipboardCheck, FolderKanban, Gauge, ShieldCheck } from 'lucide-react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { ADMIN_LOGOUT, adminNav } from '@/Layouts/adminNav';
import { Card, Pill, SectionTitle } from '@/Components/Ui';
import { StatCard } from '@/Components/StatCard';
import { Reveal } from '@/Components/Reveal';
import { NigerRegionsMap } from '@/Components/NigerRegionsMap';
import { useAuthUser } from '@/hooks/useAuth';

/**
 * Tableau de bord de l'administration.
 *
 * L'identité et l'état de campagne viennent de la base. Les indicateurs de
 * candidatures — dossiers, files de vérification, charge des évaluateurs,
 * répartition géographique — restent en attente : ils dépendent d'Admin Phase 3.
 * Les brancher sur des requêtes improvisées donnerait l'illusion d'un pilotage
 * qui n'existe pas.
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
  window: 'sans-calendrier' | 'a-venir' | 'en-cours' | 'echue';
  active: boolean;
  editUrl: string;
};

const EN_ATTENTE = 'Données disponibles après l’ouverture des candidatures.';

function dateLocale(iso: string | null, fuseau: string) {
  if (!iso) return 'non fixée';
  return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long', timeStyle: 'short', timeZone: fuseau }).format(new Date(iso));
}

export default function AdminDashboard({
  campaign,
  campaignsCount,
  campaignsUrl,
  applications,
}: {
  campaign: Campagne | null;
  campaignsCount: number;
  campaignsUrl: string;
  applications: { total: number; drafts: number; url: string };
}) {
  const user = useAuthUser();

  return (
    <DarkSidebarLayout
      items={adminNav}
      active="Tableau de bord"
      title="Back-office administratif"
      subtitle="Présélection & admissibilité — PIDUREM / ANSI"
      logoutHref={ADMIN_LOGOUT}
    >
      <Head title="Back-office administratif — BIRNIN GOBE" />
      <div className="mx-auto max-w-[1650px] p-5 sm:p-7">
        <Reveal>
          <Card className="p-5">
            <div className="flex flex-wrap items-start justify-between gap-4">
              <div className="min-w-0">
                <h2 className="text-lg font-extrabold text-brand-950">Bonjour {user?.name ?? ''}</h2>
                {campaign ? (
                  <>
                    <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2">
                      <span className="text-sm font-bold text-slate-800">{campaign.name}</span>
                      <span className="rounded-lg bg-slate-100 px-2 py-0.5 font-mono text-[11px] font-bold text-slate-600">{campaign.code}</span>
                      <Pill tone={campaign.active ? 'green' : 'gold'}>
                        {campaign.active ? 'Reçoit les candidatures' : campaign.statusLabel}
                      </Pill>
                    </div>
                    <p className="mt-2 text-sm leading-6 text-slate-600">
                      Ouverture le {dateLocale(campaign.opensAt, campaign.timezone)}, clôture le{' '}
                      {dateLocale(campaign.closesAt, campaign.timezone)} ({campaign.timezone}).
                    </p>
                    {!campaign.active ? (
                      <p className="mt-1.5 flex items-start gap-2 text-sm text-amber-700">
                        <AlertTriangle size={16} className="mt-0.5 shrink-0" />
                        <span>
                          Cette campagne est déclarée ouverte, mais la date du jour est hors de sa fenêtre : aucun
                          candidat ne peut déposer de dossier.
                        </span>
                      </p>
                    ) : null}
                  </>
                ) : (
                  <p className="mt-1.5 text-sm leading-6 text-slate-600">
                    {campaignsCount === 0
                      ? 'Aucune campagne n’est enregistrée. Tant qu’aucune n’est ouverte, aucun candidat ne peut déposer de dossier.'
                      : 'Aucune campagne n’est ouverte. Les candidats ne peuvent pas déposer de dossier.'}
                  </p>
                )}
              </div>
              <Link
                href={campaignsUrl}
                className="focus-ring press-feedback inline-flex min-h-11 shrink-0 items-center gap-2 rounded-xl border border-slate-200 px-4 text-sm font-bold text-brand-800 hover:bg-slate-50"
              >
                <CalendarRange size={17} /> Gérer les campagnes
              </Link>
            </div>
          </Card>
        </Reveal>

        {/* Deux compteurs sont désormais comptés pour de bon : ils viennent de
            `applications`. Les trois autres gardent leur tiret — un « 0 »
            affirmerait qu'on a compté et trouvé zéro, alors que le workflow qui
            produirait ces nombres n'existe pas encore. Un tiret dit « inconnu »,
            un zéro dit « aucun » : la différence compte pour qui pilote. */}
        <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
          <StatCard
            icon={FolderKanban}
            value={applications.total}
            label="Candidatures"
            hint="Tous statuts, toutes campagnes"
            tone="blue"
          />
          <StatCard
            icon={Gauge}
            value={applications.drafts}
            label="Brouillons en cours"
            hint="Dossiers commencés, non soumis"
            tone="gold"
          />
          <StatCard icon={ClipboardCheck} value="—" label="Dossiers soumis" hint="Soumission non encore ouverte" tone="blue" />
          <StatCard icon={ShieldCheck} value="—" label="Admissibles" hint="Admissibilité à venir" tone="green" />
          <StatCard icon={AlertTriangle} value="—" label="Alertes actives" hint="À venir" tone="red" />
        </div>

        {applications.total > 0 ? (
          <div className="mt-3">
            <Link
              href={applications.url}
              className="focus-ring inline-flex min-h-9 items-center gap-1.5 rounded-lg text-sm font-bold text-brand-800 hover:underline"
            >
              <FolderKanban size={16} /> Consulter les candidatures
            </Link>
          </div>
        ) : null}

        <div className="mt-4 grid gap-4 xl:grid-cols-[1.1fr_1.1fr_1.15fr]">
          <Reveal delay={100}>
            <Card className="p-5">
              <SectionTitle title="Files de vérification" />
              <EtatVide texte={EN_ATTENTE} />
            </Card>
          </Reveal>
          <Reveal delay={150}>
            <Card className="p-5">
              <SectionTitle title="Charge des évaluateurs" />
              <EtatVide texte="Aucun évaluateur n’est affecté." />
            </Card>
          </Reveal>
          <Reveal delay={200}>
            <Card className="p-5">
              <SectionTitle title="Répartition géographique" />
              {/* Sans `intensities`, la carte se rend en gris neutre : la
                  géographie est réelle, la donnée est absente et se voit. */}
              <NigerRegionsMap
                label="Carte du Niger : aucune donnée de répartition disponible"
                className="mx-auto mt-3 block h-auto w-full max-w-[260px]"
              />
              <p className="mt-3 text-center text-[11px] text-slate-400">Aucun dossier à répartir.</p>
            </Card>
          </Reveal>
        </div>

        <div className="mt-4 grid gap-4 xl:grid-cols-3">
          <Reveal delay={60}>
            <Card className="p-5">
              <SectionTitle title="Indicateurs thématiques" />
              <EtatVide texte={EN_ATTENTE} />
            </Card>
          </Reveal>
          <Reveal delay={120}>
            <Card className="p-5">
              <SectionTitle title="Alertes récentes" />
              <EtatVide texte="Aucune alerte." />
            </Card>
          </Reveal>
          <Reveal delay={180}>
            <Card className="p-5">
              <SectionTitle title="Journal d’audit" />
              <EtatVide texte="Aucun événement enregistré sur cet écran pour le moment." />
            </Card>
          </Reveal>
        </div>
      </div>
    </DarkSidebarLayout>
  );
}

function EtatVide({ texte }: { texte: string }) {
  return (
    <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50/60 px-4 py-8 text-center text-xs leading-5 text-slate-500">
      {texte}
    </div>
  );
}
