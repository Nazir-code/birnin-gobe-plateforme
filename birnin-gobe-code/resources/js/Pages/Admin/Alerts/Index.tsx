import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, BellRing, CheckCircle2, Info } from 'lucide-react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { ADMIN_LOGOUT, adminNav } from '@/Layouts/adminNav';
import { Card, Pill, SectionTitle } from '@/Components/Ui';
import type { Option } from '@/Components/Champs';

/**
 * Alertes de pilotage — §9.3.
 *
 * **Aucun bouton « ignorer », et c'est le fond de l'écran.** Une alerte est un
 * calcul sur l'état réel, pas un enregistrement qu'on acquitte : elle
 * disparaît quand sa cause disparaît. Un acquittement laisserait une alerte
 * éteinte alors que la situation persiste — exactement ce qui apprend à ne plus
 * lire l'écran.
 *
 * Chaque alerte dit trois choses, et l'ordre compte : **ce qui se passe**,
 * **combien**, **quoi faire**. La dernière est un lien vers l'écran qui permet
 * d'agir, filtré sur exactement le périmètre compté — une alerte qui laisse
 * chercher soi-même les dossiers concernés finit par n'être plus lue.
 *
 * L'absence d'alerte n'est pas un écran vide mais un état annoncé : « rien ne
 * requiert d'attention » est une information de pilotage, et la confondre avec
 * un défaut de chargement ferait douter de l'écran au mauvais moment.
 */
type Alerte = {
  key: string;
  severity: 'CRITICAL' | 'WARNING' | 'INFO';
  severityLabel: string;
  label: string;
  detail: string;
  action: string;
  count: number;
  url: string | null;
};

type Props = {
  alerts: Alerte[];
  campaign: { id: number; name: string; code: string } | null;
  filters: { campaign: string };
  options: { campaigns: Option[] };
  thresholds: { controlDelayDays: number; closingHorizonDays: number };
};

const tonParGravite: Record<Alerte['severity'], 'red' | 'gold' | 'neutral'> = {
  CRITICAL: 'red',
  WARNING: 'gold',
  INFO: 'neutral',
};

const bordureParGravite: Record<Alerte['severity'], string> = {
  CRITICAL: 'border-rose-200 bg-rose-50',
  WARNING: 'border-amber-200 bg-amber-50',
  INFO: 'border-slate-200 bg-white',
};

function Icone({ severity }: { severity: Alerte['severity'] }) {
  if (severity === 'INFO') return <Info className="mt-0.5 h-4 w-4 shrink-0 text-slate-500" aria-hidden />;
  return (
    <AlertTriangle
      className={`mt-0.5 h-4 w-4 shrink-0 ${severity === 'CRITICAL' ? 'text-rose-700' : 'text-amber-700'}`}
      aria-hidden
    />
  );
}

export default function AlertsIndex({ alerts, campaign, filters, options, thresholds }: Props) {
  const critiques = alerts.filter((a) => a.severity === 'CRITICAL').length;

  return (
    <DarkSidebarLayout
      items={adminNav}
      active="Alertes"
      title="Alertes"
      subtitle={campaign ? `${campaign.name} (${campaign.code})` : 'Toutes campagnes confondues'}
      logoutHref={ADMIN_LOGOUT}
    >
      <Head title="Alertes — BIRNIN GOBE" />

      <div className="mx-auto max-w-[1080px] p-5 sm:p-7">
        <Card className="p-4 sm:p-5">
          <SectionTitle
            eyebrow="Retards et anomalies"
            title={
              alerts.length === 0
                ? 'Aucune alerte'
                : `${alerts.length} alerte${alerts.length > 1 ? 's' : ''}`
            }
            aside={
              critiques > 0 ? (
                <Pill tone="red">
                  {critiques} critique{critiques > 1 ? 's' : ''}
                </Pill>
              ) : undefined
            }
          />

          <div className="mt-4 max-w-md">
            <label htmlFor="campaign" className="block text-sm font-bold text-slate-700">
              Campagne
            </label>
            <select
              id="campaign"
              value={filters.campaign}
              onChange={(e) =>
                router.get(
                  '/admin/alerts',
                  e.target.value === '' ? {} : { campaign: e.target.value },
                  { preserveState: true, preserveScroll: true, replace: true },
                )
              }
              className="focus-ring mt-1.5 h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm"
            >
              <option value="">Campagne active</option>
              <option value="0">Toutes campagnes confondues</option>
              {options.campaigns.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </div>
        </Card>

        {alerts.length === 0 ? (
          <Card className="mt-5 p-5">
            <p className="flex items-start gap-2 text-sm text-slate-700" data-testid="aucune-alerte">
              <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-brand-700" aria-hidden />
              Rien ne requiert d’attention sur ce périmètre : aucun retard de contrôle, aucun délai de clarification
              dépassé, aucun dossier recevable sans évaluateur.
            </p>
          </Card>
        ) : (
          <ul className="mt-5 space-y-3" data-testid="alertes">
            {alerts.map((alerte) => (
              <li key={alerte.key}>
                <Card className={`border p-4 sm:p-5 ${bordureParGravite[alerte.severity]}`}>
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex min-w-0 gap-2">
                      <Icone severity={alerte.severity} />
                      <div className="min-w-0">
                        <p className="text-sm font-bold text-slate-900">{alerte.label}</p>
                        <p className="mt-1 text-sm text-slate-700">{alerte.detail}</p>
                        <p className="mt-2 text-xs text-slate-600">{alerte.action}</p>
                      </div>
                    </div>

                    <div className="flex shrink-0 flex-col items-end gap-2">
                      <Pill tone={tonParGravite[alerte.severity]}>{alerte.severityLabel}</Pill>
                      {alerte.url ? (
                        <Link
                          href={alerte.url}
                          className="focus-ring inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-bold text-brand-800 hover:underline"
                          data-testid={`aller-${alerte.key}`}
                        >
                          Traiter
                          <ArrowRight className="h-3.5 w-3.5" aria-hidden />
                        </Link>
                      ) : null}
                    </div>
                  </div>
                </Card>
              </li>
            ))}
          </ul>
        )}

        <p className="mt-4 flex items-start gap-2 text-xs text-slate-500">
          <BellRing className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
          Une alerte s’éteint quand sa cause disparaît — il n’y a rien à acquitter. Seuils de lancement : contrôle en
          retard au-delà de {thresholds.controlDelayDays} jours, clôture signalée {thresholds.closingHorizonDays} jours
          avant. Ils ne sont pas encore administrables (§9.2).
        </p>
      </div>
    </DarkSidebarLayout>
  );
}
