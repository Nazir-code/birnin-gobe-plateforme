import { Head, router } from '@inertiajs/react';
import { BarChart3, Download, EyeOff, Info } from 'lucide-react';
import { useState } from 'react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { ADMIN_LOGOUT, adminNav } from '@/Layouts/adminNav';
import { Card, Pill, SectionTitle } from '@/Components/Ui';
import type { Option } from '@/Components/Champs';

/**
 * Tableaux de bord d'indicateurs — §13.1 et §13.4.
 *
 * Trois règles gouvernent cet écran, et toutes trois portent sur ce qu'il
 * **refuse** d'afficher :
 *
 *   **« non mesuré » n'est pas « zéro »** — un indicateur sans source affiche
 *     un tiret et sa raison. Un 0 ferait conclure à l'absence de candidatures
 *     là où il n'y a qu'une absence de mesure ;
 *   **un petit effectif est masqué** — le §13.4 l'exige pour les données de
 *     genre, d'âge, de handicap et de localisation. La valeur ne quitte pas le
 *     serveur : l'écran reçoit un drapeau, pas un chiffre à cacher ;
 *   **les familles non instrumentées restent visibles** — Mobilisation, Finale
 *     et Qualité de service apparaissent vides et expliquées. Les retirer
 *     ferait croire à une vue complète du pilotage.
 *
 * Chaque indicateur porte sa fiche du §13.4 — définition, formule, source,
 * fréquence, niveau d'accès — dépliable sur place. Un chiffre lu sans sa
 * définition finit par être cité pour autre chose que ce qu'il mesure.
 */
type Indicateur = {
  key: string;
  family: string;
  label: string;
  definition: string;
  formula: string;
  source: string;
  refresh: string;
  refreshLabel: string;
  access: string;
  accessLabel: string;
  value: number | null;
  unit: string | null;
  masked: boolean;
  measured: boolean;
};

type LigneVentilation = { label: string; value: number | null; masked: boolean };

type Ventilation = {
  key: string;
  family: string;
  label: string;
  definition: string;
  formula: string;
  source: string;
  refreshLabel: string;
  access: string;
  accessLabel: string;
  threshold: number;
  rows: LigneVentilation[];
};

type Famille = { value: string; label: string; available: boolean; reason: string | null };

type Props = {
  indicators: Indicateur[];
  breakdowns: Ventilation[];
  families: Famille[];
  campaign: { id: number; name: string; code: string } | null;
  filters: { campaign: string };
  options: { campaigns: Option[] };
  exportUrl: string;
  maskingThreshold: number;
};

function valeurLisible(indicateur: Indicateur) {
  if (!indicateur.measured) return '—';
  if (indicateur.masked) return 'masqué';
  const nombre = new Intl.NumberFormat('fr-FR').format(indicateur.value ?? 0);
  return indicateur.unit ? `${nombre} ${indicateur.unit}` : nombre;
}

export default function IndicatorsIndex({
  indicators,
  breakdowns,
  families,
  campaign,
  filters,
  options,
  exportUrl,
  maskingThreshold,
}: Props) {
  const [ouvert, setOuvert] = useState<string | null>(null);

  return (
    <DarkSidebarLayout
      items={adminNav}
      active="Indicateurs"
      title="Indicateurs"
      subtitle={campaign ? `${campaign.name} (${campaign.code})` : 'Toutes campagnes confondues'}
      logoutHref={ADMIN_LOGOUT}
    >
      <Head title="Indicateurs — BIRNIN GOBE" />

      <div className="mx-auto max-w-[1280px] p-5 sm:p-7">
        <Card className="p-4 sm:p-5">
          <SectionTitle
            title="Périmètre"
            aside={
              <a
                href={exportUrl}
                className="focus-ring inline-flex min-h-9 items-center gap-1.5 rounded-lg px-2 text-xs font-bold text-brand-800 hover:underline"
                data-testid="exporter"
              >
                <Download className="h-3.5 w-3.5" aria-hidden />
                Exporter en CSV
              </a>
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
                  '/admin/indicators',
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
            {filters.campaign === '0' ? (
              <p className="mt-1.5 text-xs text-slate-500">
                Les chiffres cumulent toutes les éditions : ils répondent à une autre question que le pilotage d’une
                campagne.
              </p>
            ) : null}
          </div>
        </Card>

        {families.map((famille) => {
          const chiffres = indicators.filter((i) => i.family === famille.value);
          const tables = breakdowns.filter((b) => b.family === famille.value);

          return (
            <Card key={famille.value} className="mt-5 p-4 sm:p-5">
              <SectionTitle
                eyebrow="Famille d’indicateurs"
                title={famille.label}
                aside={famille.available ? undefined : <Pill tone="neutral">Pas encore mesurée</Pill>}
              />

              {!famille.available ? (
                <p
                  className="mt-3 flex items-start gap-2 text-sm text-slate-600"
                  data-testid={`famille-indisponible-${famille.value}`}
                >
                  <Info className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
                  {famille.reason}
                </p>
              ) : null}

              {chiffres.length > 0 ? (
                <ul className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3" data-testid={`chiffres-${famille.value}`}>
                  {chiffres.map((indicateur) => (
                    <li key={indicateur.key} className="rounded-xl border border-slate-200 p-4">
                      <p className="text-xs font-bold text-slate-500">{indicateur.label}</p>
                      <p className="mt-1 text-2xl font-bold text-slate-900" data-testid={`valeur-${indicateur.key}`}>
                        {valeurLisible(indicateur)}
                      </p>

                      <button
                        type="button"
                        onClick={() => setOuvert(ouvert === indicateur.key ? null : indicateur.key)}
                        className="focus-ring mt-2 inline-flex items-center gap-1 rounded-lg text-xs font-bold text-brand-800 hover:underline"
                        aria-expanded={ouvert === indicateur.key}
                      >
                        <Info className="h-3.5 w-3.5" aria-hidden />
                        {ouvert === indicateur.key ? 'Masquer la définition' : 'Définition'}
                      </button>

                      {ouvert === indicateur.key ? (
                        <dl className="mt-2 space-y-1.5 border-t border-slate-200 pt-2 text-xs">
                          <Fiche libelle="Définition" valeur={indicateur.definition} />
                          <Fiche libelle="Formule" valeur={indicateur.formula} mono />
                          <Fiche libelle="Source" valeur={indicateur.source} mono />
                          <Fiche libelle="Rafraîchissement" valeur={indicateur.refreshLabel} />
                          <Fiche libelle="Accès" valeur={indicateur.accessLabel} />
                        </dl>
                      ) : null}
                    </li>
                  ))}
                </ul>
              ) : null}

              {tables.map((ventilation) => (
                <div key={ventilation.key} className="mt-5 rounded-xl border border-slate-200 p-4">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <p className="text-sm font-bold text-slate-900">{ventilation.label}</p>
                    {ventilation.access === 'SENSITIVE' ? (
                      <Pill tone="gold">Effectifs &lt; {ventilation.threshold} masqués</Pill>
                    ) : null}
                  </div>
                  <p className="mt-1 text-xs text-slate-500">{ventilation.definition}</p>

                  <div className="mt-3 overflow-x-auto">
                    <table className="w-full min-w-[320px] text-sm">
                      <caption className="sr-only">{ventilation.label}</caption>
                      <thead>
                        <tr className="border-b border-slate-200 text-left text-xs font-bold text-slate-500">
                          <th scope="col" className="py-2">Modalité</th>
                          <th scope="col" className="py-2 text-right">Dossiers</th>
                        </tr>
                      </thead>
                      <tbody data-testid={`ventilation-${ventilation.key}`}>
                        {ventilation.rows.map((ligne) => (
                          <tr key={ligne.label} className="border-b border-slate-100 last:border-0">
                            <td className="py-2 text-slate-700">{ligne.label}</td>
                            <td className="py-2 text-right font-bold text-slate-900">
                              {ligne.masked ? (
                                <span className="inline-flex items-center gap-1 text-slate-500">
                                  <EyeOff className="h-3.5 w-3.5" aria-hidden />
                                  masqué
                                </span>
                              ) : (
                                new Intl.NumberFormat('fr-FR').format(ligne.value ?? 0)
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>

                  <dl className="mt-3 space-y-1.5 border-t border-slate-200 pt-2 text-xs">
                    <Fiche libelle="Formule" valeur={ventilation.formula} mono />
                    <Fiche libelle="Source" valeur={ventilation.source} mono />
                    <Fiche libelle="Accès" valeur={ventilation.accessLabel} />
                  </dl>
                </div>
              ))}
            </Card>
          );
        })}

        <p className="mt-4 flex items-start gap-2 text-xs text-slate-500">
          <BarChart3 className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
          Un effectif inférieur à {maskingThreshold} est masqué sur les ventilations sensibles (§13.4), à l’écran comme
          à l’export. « — » signifie « pas encore mesuré », jamais « zéro ».
        </p>
      </div>
    </DarkSidebarLayout>
  );
}

function Fiche({ libelle, valeur, mono = false }: { libelle: string; valeur: string; mono?: boolean }) {
  return (
    <div>
      <dt className="font-bold text-slate-500">{libelle}</dt>
      <dd className={mono ? 'break-words font-mono text-[11px] text-slate-700' : 'text-slate-700'}>{valeur}</dd>
    </div>
  );
}
