import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, Layers3, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { ADMIN_LOGOUT, adminNav } from '@/Layouts/adminNav';
import { Card, Pill, SectionTitle } from '@/Components/Ui';
import type { Option } from '@/Components/Champs';

/**
 * File de vérification — §10.1.
 *
 * Une file, pas une liste : l'ordre par défaut est le plus ancien dépôt
 * d'abord, et l'ancienneté est affichée en toutes lettres. Un écran de file
 * dont la colonne la plus visible serait la date brute laisserait le
 * vérificateur calculer lui-même qui attend depuis trop longtemps.
 *
 * Deux vides à ne pas confondre, et l'écran les distingue : une file vide est
 * le but d'une campagne bien tenue ; « aucun résultat » n'est qu'un filtre trop
 * étroit. Les présenter du même mot ferait chercher une panne là où il n'y a
 * rien à faire.
 *
 * L'avancement de la grille (`x / 7`) est là pour une raison précise : deux
 * vérificateurs qui travaillent la même file doivent voir qu'un dossier est
 * déjà entamé avant de l'ouvrir.
 *
 * L'état des filtres vit dans l'URL : un lien se colle dans un fil d'équipe, un
 * rechargement ne perd rien, et « Réinitialiser » n'est qu'un retour à
 * l'adresse nue.
 */
type Dossier = {
  id: number;
  candidateName: string;
  candidateEmail: string;
  campaignName: string;
  submissionNumber: string | null;
  status: string;
  statusLabel: string;
  submittedAt: string | null;
  waitingDays: number | null;
  checksDone: number;
  checksTotal: number;
  completionPercent: number;
  showUrl: string;
};

type Filtres = { campaign: string; status: string; search: string; scope: string; sort: string };

type Props = {
  applications: Dossier[];
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
  totalWaiting: number;
  options: { campaigns: Option[]; statuses: Option[]; scopes: Option[]; sorts: Option[] };
  resetUrl: string;
};

/** Un dossier décidé se lit autrement qu'un dossier qui attend. */
const tonParStatut: Record<string, 'green' | 'gold' | 'neutral' | 'red'> = {
  SUBMITTED: 'gold',
  PENDING_REVIEW: 'gold',
  CLARIFICATION_REQUESTED: 'neutral',
  CLARIFICATION_RECEIVED: 'neutral',
  ADMISSIBLE: 'green',
  INADMISSIBLE: 'red',
};

function dateCourte(iso: string | null) {
  if (!iso) return '—';
  return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium' }).format(new Date(iso));
}

/** L'attente en mots. Zéro jour est « aujourd'hui », pas « 0 jour ». */
function attente(jours: number | null) {
  if (jours === null) return '—';
  if (jours === 0) return 'aujourd’hui';
  if (jours === 1) return '1 jour';
  return `${jours} jours`;
}

export default function VerificationIndex({
  applications,
  pagination,
  filters,
  hasActiveFilters,
  totalWaiting,
  options,
  resetUrl,
}: Props) {
  const [saisie, setSaisie] = useState(filters);

  /** Une seule porte de sortie vers le serveur : l'URL porte tout l'état. */
  function appliquer(modifications: Partial<Filtres>) {
    const prochain = { ...saisie, ...modifications };
    setSaisie(prochain);

    router.get('/admin/verification', Object.fromEntries(Object.entries(prochain).filter(([, v]) => v !== '')), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  }

  const fileVide = totalWaiting === 0;

  return (
    <DarkSidebarLayout
      items={adminNav}
      active="Files de vérification"
      title="File de vérification"
      subtitle="Contrôle d’admissibilité — PIDUREM / ANSI"
      logoutHref={ADMIN_LOGOUT}
    >
      <Head title="File de vérification — BIRNIN GOBE" />
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
                  <RotateCcw className="h-3.5 w-3.5" aria-hidden />
                  Réinitialiser
                </Link>
              ) : undefined
            }
          />

          <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div className="sm:col-span-2">
              <label htmlFor="search" className="block text-sm font-bold text-slate-700">
                Recherche
              </label>
              <input
                id="search"
                name="search"
                type="search"
                value={saisie.search}
                onChange={(e) => setSaisie({ ...saisie, search: e.target.value })}
                onBlur={() => appliquer({ search: saisie.search })}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') appliquer({ search: saisie.search });
                }}
                placeholder="Numéro de dépôt, nom ou courriel"
                className="focus-ring mt-1.5 h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm transition-shadow"
              />
            </div>

            <Filtre
              id="scope"
              label="Périmètre"
              value={saisie.scope}
              options={options.scopes}
              vide={null}
              onChange={(v) => appliquer({ scope: v })}
            />
            <Filtre
              id="status"
              label="Statut"
              value={saisie.status}
              options={options.statuses}
              vide="Tous les statuts"
              onChange={(v) => appliquer({ status: v })}
            />
            <Filtre
              id="campaign"
              label="Campagne"
              value={saisie.campaign}
              options={options.campaigns}
              vide="Toutes les campagnes"
              onChange={(v) => appliquer({ campaign: v })}
            />
          </div>

          <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <Filtre
              id="sort"
              label="Tri"
              value={saisie.sort}
              options={options.sorts}
              vide={null}
              onChange={(v) => appliquer({ sort: v })}
            />
          </div>
        </Card>

        <Card className="mt-5 p-4 sm:p-5">
          <SectionTitle
            eyebrow="Contrôle d’admissibilité"
            title={`${pagination.total} dossier${pagination.total > 1 ? 's' : ''}`}
            aside={
              <span className="text-xs font-bold text-slate-500" data-testid="attente-totale">
                {totalWaiting} en attente de contrôle
              </span>
            }
          />

          {applications.length === 0 ? (
            <p className="mt-6 text-sm text-slate-600" data-testid="file-vide">
              {fileVide
                ? 'Aucun dossier n’attend de contrôle. La file est à jour.'
                : 'Aucun dossier ne correspond à ces filtres. Élargissez le périmètre ou réinitialisez.'}
            </p>
          ) : (
            <ul className="mt-4 space-y-3" data-testid="file-dossiers">
              {applications.map((dossier) => (
                <li key={dossier.id}>
                  <Link
                    href={dossier.showUrl}
                    className="focus-ring block rounded-xl border border-slate-200 p-4 transition-colors hover:bg-slate-50"
                    data-testid={`dossier-${dossier.id}`}
                  >
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0">
                        <p className="truncate text-sm font-bold text-slate-900">
                          {dossier.submissionNumber ?? 'Sans numéro'} — {dossier.candidateName}
                        </p>
                        <p className="truncate text-xs text-slate-500">{dossier.candidateEmail}</p>
                        <p className="mt-1 truncate text-xs text-slate-500">{dossier.campaignName}</p>
                      </div>
                      <div className="flex flex-wrap items-center gap-2">
                        <Pill tone={tonParStatut[dossier.status] ?? 'neutral'}>{dossier.statusLabel}</Pill>
                        <ArrowRight className="h-4 w-4 text-slate-400" aria-hidden />
                      </div>
                    </div>

                    <dl className="mt-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
                      <Donnee libelle="Déposé le" valeur={dateCourte(dossier.submittedAt)} />
                      <Donnee libelle="En attente depuis" valeur={attente(dossier.waitingDays)} />
                      <Donnee
                        libelle="Grille"
                        valeur={`${dossier.checksDone} / ${dossier.checksTotal} contrôles`}
                      />
                      <Donnee libelle="Dossier rempli" valeur={`${dossier.completionPercent} %`} />
                    </dl>
                  </Link>
                </li>
              ))}
            </ul>
          )}

          {pagination.lastPage > 1 ? (
            <nav className="mt-4 flex flex-wrap items-center justify-between gap-3" aria-label="Pagination">
              <p className="text-xs text-slate-500">
                {pagination.from}–{pagination.to} sur {pagination.total}
              </p>
              <div className="flex gap-2">
                <LienPage href={pagination.previousUrl} libelle="Précédent" testid="page-precedente" />
                <LienPage href={pagination.nextUrl} libelle="Suivant" testid="page-suivante" />
              </div>
            </nav>
          ) : null}
        </Card>

        <p className="mt-4 flex items-start gap-2 text-xs text-slate-500">
          <Layers3 className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
          Un signalement automatique — doublon, incohérence, document suspect — n’exclut jamais un candidat à lui
          seul&nbsp;: chaque contrôle est coché par une personne (§10.3).
        </p>
      </div>
    </DarkSidebarLayout>
  );
}

function Donnee({ libelle, valeur }: { libelle: string; valeur: string }) {
  return (
    <div>
      <dt className="font-bold text-slate-500">{libelle}</dt>
      <dd className="text-slate-800">{valeur}</dd>
    </div>
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
  vide?: string | null;
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
        {vide === null || vide === undefined ? null : <option value="">{vide}</option>}
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
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
