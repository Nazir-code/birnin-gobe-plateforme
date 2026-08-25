import { Head, Link, router } from '@inertiajs/react';
import { FolderKanban, RotateCcw, Search, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { ADMIN_LOGOUT, adminNav } from '@/Layouts/adminNav';
import { Card, Pill, SectionTitle } from '@/Components/Ui';
import type { Option } from '@/Components/Champs';

/**
 * Liste des candidatures.
 *
 * Tout vient de PostgreSQL : recherche, filtres, tri et découpage en pages sont
 * faits par le serveur. Aucun filtrage n'a lieu ici — filtrer une page déjà
 * découpée rendrait des pages de tailles inégales et un total faux.
 *
 * Trois choses que cet écran s'interdit :
 *
 *   modifier un dossier — le candidat en reste propriétaire avant soumission ;
 *   recalculer la progression ou l'éligibilité — elles arrivent déjà calculées
 *     par le domaine, et un second calcul finirait par contredire le premier ;
 *   promettre un filtre par verdict d'éligibilité — celui-ci se déduit des
 *     réponses et des paramètres de campagne, il n'existe pas en base et ne
 *     peut donc pas trier la table sans en écrire une seconde version.
 *
 * L'état des filtres vit dans l'URL : un lien se partage, un rechargement ne
 * perd rien, et le bouton « Réinitialiser » n'est qu'un retour à l'adresse nue.
 */
type Candidature = {
  id: number;
  candidateName: string;
  candidateEmail: string;
  campaignName: string;
  campaignCode: string;
  status: string;
  statusLabel: string;
  completionPercent: number;
  completedSections: number;
  totalSections: number;
  currentStep: string | null;
  currentStepLabel: string | null;
  theme: string | null;
  themeLabel: string | null;
  candidateType: string | null;
  candidateTypeLabel: string | null;
  region: string | null;
  regionLabel: string | null;
  eligibility: { outcome: string; label: string };
  /** Null tant que le dossier est un brouillon — l'ecran rend alors un tiret. */
  submissionNumber: string | null;
  submittedAt: string | null;
  updatedAt: string | null;
  showUrl: string;
};

type Filtres = { campaign: string; status: string; type: string; region: string; theme: string; q: string; sort: string };

type Props = {
  applications: Candidature[];
  pagination: {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    from: number | null;
    to: number | null;
    previousUrl: string | null;
    nextUrl: string | null;
  };
  filters: Filtres;
  hasActiveFilters: boolean;
  totalWithoutFilters: number;
  options: {
    campaigns: Option[];
    statuses: Option[];
    types: Option[];
    themes: Option[];
    regions: Option[];
    sorts: Option[];
  };
  resetUrl: string;
};

const tonParVerdict: Record<string, 'green' | 'gold' | 'neutral' | 'red'> = {
  ELIGIBLE: 'green',
  TO_CONFIRM: 'gold',
  INELIGIBLE: 'red',
  INCOMPLETE: 'neutral',
};

function dateCourte(iso: string | null) {
  if (!iso) return '—';
  return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
}

export default function ApplicationsIndex({
  applications,
  pagination,
  filters,
  hasActiveFilters,
  totalWithoutFilters,
  options,
  resetUrl,
}: Props) {
  const [saisie, setSaisie] = useState(filters);

  /** Une seule porte de sortie vers le serveur : l'URL porte tout l'état. */
  function appliquer(modifications: Partial<Filtres>) {
    const prochain = { ...saisie, ...modifications };
    setSaisie(prochain);

    router.get('/admin/applications', Object.fromEntries(Object.entries(prochain).filter(([, v]) => v !== '')), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  }

  const aucuneCandidature = totalWithoutFilters === 0;

  return (
    <DarkSidebarLayout
      items={adminNav}
      active="Candidatures"
      title="Candidatures"
      subtitle="Dossiers déposés — PIDUREM / ANSI"
      logoutHref={ADMIN_LOGOUT}
    >
      <Head title="Candidatures — BIRNIN GOBE" />
      <div className="mx-auto max-w-[1280px] p-5 sm:p-7">
        <Card className="p-4 sm:p-5">
          <SectionTitle
            title="Filtres"
            aside={
              hasActiveFilters ? (
                <Link
                  href={resetUrl}
                  className="focus-ring inline-flex min-h-9 items-center gap-1.5 rounded-lg px-2 text-xs font-bold text-brand-800 hover:underline"
                  data-testid="reinitialiser-filtres"
                >
                  <RotateCcw size={14} /> Réinitialiser les filtres
                </Link>
              ) : null
            }
          />

          <form
            className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
            onSubmit={(e) => {
              e.preventDefault();
              appliquer({});
            }}
          >
            <div className="lg:col-span-2">
              <label htmlFor="q" className="block text-sm font-bold text-slate-700">
                Rechercher
              </label>
              <div className="relative mt-1.5">
                <Search size={16} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden />
                <input
                  id="q"
                  name="q"
                  type="search"
                  value={saisie.q}
                  onChange={(e) => setSaisie({ ...saisie, q: e.target.value })}
                  placeholder="Nom, adresse e-mail ou numéro de dossier"
                  aria-describedby="q-aide"
                  className="focus-ring h-12 w-full rounded-xl border border-slate-300 pl-10 pr-4 text-sm transition-shadow"
                />
              </div>
              <p id="q-aide" className="mt-1.5 text-[11px] leading-4 text-slate-400">
                La recherche porte sur le compte du candidat et sur le numéro de dossier, jamais sur le contenu des
                réponses.
              </p>
            </div>

            <Filtre id="sort" label="Trier par" value={saisie.sort} options={options.sorts} onChange={(v) => appliquer({ sort: v })} vide={null} />
            <Filtre id="campaign" label="Campagne" value={saisie.campaign} options={options.campaigns} onChange={(v) => appliquer({ campaign: v })} vide="Toutes les campagnes" />
            <Filtre id="status" label="Statut" value={saisie.status} options={options.statuses} onChange={(v) => appliquer({ status: v })} vide="Tous les statuts" />
            <Filtre id="type" label="Forme de candidature" value={saisie.type} options={options.types} onChange={(v) => appliquer({ type: v })} vide="Toutes les formes" />
            <Filtre id="theme" label="Thématique du projet" value={saisie.theme} options={options.themes} onChange={(v) => appliquer({ theme: v })} vide="Toutes les thématiques" />
            <Filtre id="region" label="Zone d’intervention" value={saisie.region} options={options.regions} onChange={(v) => appliquer({ region: v })} vide="Toutes les zones" />

            <div className="flex items-end">
              <button
                type="submit"
                className="focus-ring press-feedback inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-brand-800 px-4 text-sm font-bold text-white hover:bg-brand-900 sm:w-auto"
              >
                <SlidersHorizontal size={16} /> Appliquer
              </button>
            </div>
          </form>

          <p className="mt-3 text-[11px] leading-5 text-slate-400">
            Le résultat d’éligibilité est recalculé pour chaque dossier affiché, à partir des critères de sa propre
            campagne. Il n’est pas stocké, et ne sert donc pas de filtre.
          </p>
        </Card>

        <Card className="mt-4 p-4 sm:p-5">
          <SectionTitle
            title={pagination.total > 0 ? `${pagination.total} candidature${pagination.total > 1 ? 's' : ''}` : 'Candidatures'}
            aside={
              pagination.total > 0 ? (
                <span className="text-xs font-bold text-slate-500" data-testid="compteur-page">
                  {pagination.from}–{pagination.to} sur {pagination.total}
                </span>
              ) : null
            }
          />

          {applications.length === 0 ? (
            <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50/60 px-4 py-10 text-center" data-testid="etat-vide">
              <FolderKanban size={26} className="mx-auto text-slate-300" />
              {aucuneCandidature ? (
                <>
                  <p className="mt-3 text-sm font-semibold text-slate-700">Aucune candidature déposée.</p>
                  <p className="mx-auto mt-1 max-w-md text-xs leading-5 text-slate-500">
                    Les dossiers apparaîtront ici dès qu’un candidat aura commencé sa candidature sur une campagne
                    ouverte.
                  </p>
                </>
              ) : (
                <>
                  <p className="mt-3 text-sm font-semibold text-slate-700">Aucun dossier ne correspond à ces filtres.</p>
                  <p className="mx-auto mt-1 max-w-md text-xs leading-5 text-slate-500">
                    {totalWithoutFilters} candidature{totalWithoutFilters > 1 ? 's' : ''} existe
                    {totalWithoutFilters > 1 ? 'nt' : ''} au total.
                  </p>
                  <Link
                    href={resetUrl}
                    className="focus-ring press-feedback mt-5 inline-flex min-h-11 items-center gap-2 rounded-xl bg-brand-800 px-4 text-sm font-bold text-white hover:bg-brand-900"
                  >
                    <RotateCcw size={16} /> Réinitialiser les filtres
                  </Link>
                </>
              )}
            </div>
          ) : (
            <>
              {/* Sous 1024 px, des cartes : un tableau de neuf colonnes forcerait
                  un défilement horizontal illisible sur un téléphone. */}
              <ul className="grid gap-2.5 lg:hidden">
                {applications.map((candidature) => (
                  <li key={candidature.id}>
                    <Link
                      href={candidature.showUrl}
                      className="focus-ring block rounded-xl border border-slate-200 p-3.5 hover:bg-slate-50"
                    >
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="font-bold text-slate-800">{candidature.candidateName}</span>
                        <Pill tone={tonParVerdict[candidature.eligibility.outcome] ?? 'neutral'}>
                          {candidature.eligibility.label}
                        </Pill>
                      </div>
                      <div className="mt-1 text-xs text-slate-500">{candidature.candidateEmail}</div>
                      <dl className="mt-2.5 grid grid-cols-2 gap-x-3 gap-y-1.5 text-xs">
                        <Donnee libelle="Campagne" valeur={candidature.campaignCode} />
                        <Donnee libelle="Statut" valeur={candidature.statusLabel} />
                        <Donnee libelle="N° de dépôt" valeur={candidature.submissionNumber ?? '—'} />
                        <Donnee libelle="Déposée" valeur={candidature.submittedAt === null ? '—' : dateCourte(candidature.submittedAt)} />
                        <Donnee libelle="Thématique" valeur={candidature.themeLabel ?? '—'} />
                        <Donnee libelle="Forme" valeur={candidature.candidateTypeLabel ?? '—'} />
                        <Donnee libelle="Zone" valeur={candidature.regionLabel ?? '—'} />
                        <Donnee
                          libelle="Progression"
                          valeur={`${candidature.completionPercent} % · ${candidature.completedSections}/${candidature.totalSections}`}
                        />
                        <Donnee libelle="Modifiée" valeur={dateCourte(candidature.updatedAt)} />
                      </dl>
                    </Link>
                  </li>
                ))}
              </ul>

              <div className="-mx-2 hidden overflow-x-auto px-2 lg:block">
                <table className="w-full min-w-[1080px] border-collapse text-sm">
                  <caption className="sr-only">Candidatures, filtrées et triées par le serveur</caption>
                  <thead>
                    <tr className="border-b border-slate-200 text-left text-[11px] uppercase tracking-wide text-slate-400">
                      <th scope="col" className="py-2.5 pr-3 font-bold">Candidat</th>
                      <th scope="col" className="py-2.5 pr-3 font-bold">Campagne</th>
                      <th scope="col" className="py-2.5 pr-3 font-bold">Statut</th>
                      <th scope="col" className="py-2.5 pr-3 font-bold">Progression</th>
                      <th scope="col" className="py-2.5 pr-3 font-bold">Étape</th>
                      <th scope="col" className="py-2.5 pr-3 font-bold">Thématique</th>
                      <th scope="col" className="py-2.5 pr-3 font-bold">Forme</th>
                      <th scope="col" className="py-2.5 pr-3 font-bold">Zone</th>
                      <th scope="col" className="py-2.5 pr-3 font-bold">Éligibilité</th>
                      <th scope="col" className="py-2.5 pr-3 font-bold">Modifiée</th>
                    </tr>
                  </thead>
                  <tbody>
                    {applications.map((candidature) => (
                      <tr key={candidature.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
                        <td className="py-3 pr-3">
                          <Link
                            href={candidature.showUrl}
                            className="focus-ring rounded font-bold text-brand-800 hover:underline"
                            aria-label={`Ouvrir la candidature de ${candidature.candidateName}`}
                          >
                            {candidature.candidateName}
                          </Link>
                          <div className="mt-0.5 text-[11px] text-slate-400">{candidature.candidateEmail}</div>
                        </td>
                        <td className="py-3 pr-3">
                          <div className="text-slate-700">{candidature.campaignName}</div>
                          <div className="mt-0.5 font-mono text-[11px] text-slate-400">{candidature.campaignCode}</div>
                        </td>
                        <td className="py-3 pr-3">
                          <Pill tone="neutral">{candidature.statusLabel}</Pill>
                          {/* Un brouillon n'a ni numero ni date : le tiret le dit,
                              plutot qu'une ligne vide qui se lirait comme un bug. */}
                          <div className="mt-1 font-mono text-[11px] text-slate-500" data-testid="numero-depot">
                            {candidature.submissionNumber ?? '—'}
                          </div>
                          <div className="text-[11px] text-slate-400">
                            {candidature.submittedAt === null ? '—' : dateCourte(candidature.submittedAt)}
                          </div>
                        </td>
                        <td className="py-3 pr-3">
                          <div className="flex items-center gap-2">
                            <div className="h-1.5 w-16 overflow-hidden rounded-full bg-slate-200" aria-hidden>
                              <div className="h-full rounded-full bg-brand-700" style={{ width: `${candidature.completionPercent}%` }} />
                            </div>
                            <span className="text-xs font-bold text-slate-600">{candidature.completionPercent} %</span>
                          </div>
                          <div className="mt-0.5 text-[11px] text-slate-400">
                            {candidature.completedSections}/{candidature.totalSections} sections
                          </div>
                        </td>
                        <td className="py-3 pr-3 text-slate-600">{candidature.currentStepLabel ?? '—'}</td>
                        <td className="py-3 pr-3 text-slate-600">{candidature.themeLabel ?? '—'}</td>
                        <td className="py-3 pr-3 text-slate-600">{candidature.candidateTypeLabel ?? '—'}</td>
                        <td className="py-3 pr-3 text-slate-600">{candidature.regionLabel ?? '—'}</td>
                        <td className="py-3 pr-3">
                          <Pill tone={tonParVerdict[candidature.eligibility.outcome] ?? 'neutral'}>
                            {candidature.eligibility.label}
                          </Pill>
                        </td>
                        <td className="py-3 pr-3 text-[11px] text-slate-400">{dateCourte(candidature.updatedAt)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <nav className="mt-4 flex flex-wrap items-center justify-between gap-3" aria-label="Pagination">
                <span className="text-xs text-slate-500">
                  Page {pagination.currentPage} sur {pagination.lastPage}
                </span>
                <div className="flex gap-2">
                  <LienPage href={pagination.previousUrl} libelle="Précédent" testid="page-precedente" />
                  <LienPage href={pagination.nextUrl} libelle="Suivant" testid="page-suivante" />
                </div>
              </nav>
            </>
          )}
        </Card>
      </div>
    </DarkSidebarLayout>
  );
}

function Filtre({
  id,
  label,
  value,
  options,
  onChange,
  vide,
}: {
  id: string;
  label: string;
  value: string;
  options: Option[];
  onChange: (v: string) => void;
  vide: string | null;
}) {
  return (
    <div>
      <label htmlFor={id} className="block text-sm font-bold text-slate-700">
        {label}
      </label>
      <select
        id={id}
        name={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="focus-ring mt-1.5 h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm transition-shadow"
      >
        {vide === null ? null : <option value="">{vide}</option>}
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </div>
  );
}

function Donnee({ libelle, valeur }: { libelle: string; valeur: string }) {
  return (
    <div>
      <dt className="text-[10px] uppercase tracking-wide text-slate-400">{libelle}</dt>
      <dd className="font-semibold text-slate-700">{valeur}</dd>
    </div>
  );
}

/** Un lien de pagination absent devient un bouton inerte, pas un lien mort. */
function LienPage({ href, libelle, testid }: { href: string | null; libelle: string; testid: string }) {
  if (href === null) {
    return (
      <span className="min-h-11 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold text-slate-300" aria-disabled>
        {libelle}
      </span>
    );
  }

  return (
    <Link
      href={href}
      preserveScroll
      data-testid={testid}
      className="focus-ring min-h-11 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50"
    >
      {libelle}
    </Link>
  );
}
