import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, FileStack, RotateCcw, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { ADMIN_LOGOUT, adminNav } from '@/Layouts/adminNav';
import { Card, Pill, SectionTitle } from '@/Components/Ui';
import type { Option } from '@/Components/Champs';

/**
 * Journal d'audit.
 *
 * Un écran de lecture, et rien d'autre : aucun bouton n'écrit, ne corrige ni ne
 * supprime. C'est la propriété qui fait qu'un journal prouve quelque chose.
 *
 * Trois choses que cet écran s'impose :
 *
 *   afficher ce qu'il ne comprend pas — une action inconnue du domaine paraît
 *     telle qu'elle est stockée, plutôt que d'être masquée ;
 *   nommer un acteur disparu — un compte supprimé garde ses événements, et la
 *     ligne dit « compte supprimé » plutôt que de laisser un vide, qui se
 *     lirait comme une action sans auteur ;
 *   ne rien recalculer — libellés, poids et changements arrivent déjà mis en
 *     forme par `AdminAuditPresenter`.
 *
 * L'état des filtres vit dans l'URL : un lien se colle dans un compte rendu
 * d'incident, un rechargement ne perd rien, et « Réinitialiser » n'est qu'un
 * retour à l'adresse nue.
 */
type Changement = { field: string; before: string | null; after: string | null };

type Evenement = {
  id: number;
  action: string;
  actionLabel: string;
  weight: 'DECISIVE' | 'NOTABLE' | 'ROUTINE';
  actor: { id: number | null; name: string; email: string | null; known: boolean };
  target: { type: string; typeLabel: string; id: string; url: string | null };
  changes: Changement[];
  source: string | null;
  reason: string | null;
  occurredAt: string | null;
};

type Filtres = { action: string; target: string; actor: string; id: string; since: string; until: string; sort: string };

type Props = {
  events: Evenement[];
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
  options: { actions: Option[]; targets: Option[]; actors: Option[]; sorts: Option[] };
  resetUrl: string;
};

const tonParPoids: Record<Evenement['weight'], 'green' | 'gold' | 'neutral' | 'red'> = {
  DECISIVE: 'gold',
  NOTABLE: 'neutral',
  ROUTINE: 'neutral',
};

function dateComplete(iso: string | null) {
  if (!iso) return '—';
  return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'medium' }).format(new Date(iso));
}

export default function AuditIndex({
  events,
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

    router.get('/admin/audit', Object.fromEntries(Object.entries(prochain).filter(([, v]) => v !== '')), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  }

  const journalVide = totalWithoutFilters === 0;

  return (
    <DarkSidebarLayout
      items={adminNav}
      active="Journal d’audit"
      title="Journal d’audit"
      subtitle="Qui a fait quoi, quand — PIDUREM / ANSI"
      logoutHref={ADMIN_LOGOUT}
    >
      <Head title="Journal d’audit — BIRNIN GOBE" />
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
            <Filtre id="action" label="Action" value={saisie.action} options={options.actions} onChange={(v) => appliquer({ action: v })} vide="Toutes les actions" />
            <Filtre id="target" label="Objet visé" value={saisie.target} options={options.targets} onChange={(v) => appliquer({ target: v })} vide="Tous les objets" />
            <Filtre id="actor" label="Auteur" value={saisie.actor} options={options.actors} onChange={(v) => appliquer({ actor: v })} vide="Tous les auteurs" />

            <div>
              <label htmlFor="id" className="block text-sm font-bold text-slate-700">
                Identifiant de l’objet
              </label>
              <input
                id="id"
                name="id"
                type="text"
                inputMode="numeric"
                value={saisie.id}
                onChange={(e) => setSaisie({ ...saisie, id: e.target.value })}
                placeholder="Par exemple 42"
                aria-describedby="id-aide"
                className="focus-ring mt-1.5 h-12 w-full rounded-xl border border-slate-300 px-4 text-sm transition-shadow"
              />
              <p id="id-aide" className="mt-1.5 text-[11px] leading-4 text-slate-400">
                Retrouve toute l’histoire d’une candidature ou d’une campagne.
              </p>
            </div>

            <Filtre id="since" label="Depuis le" value={saisie.since} onChange={(v) => appliquer({ since: v })} type="date" />
            <Filtre id="until" label="Jusqu’au" value={saisie.until} onChange={(v) => appliquer({ until: v })} type="date" />

            <Filtre id="sort" label="Ordre" value={saisie.sort} options={options.sorts} onChange={(v) => appliquer({ sort: v })} vide={null} />

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
            Le journal est en lecture seule : rien ici ne peut le corriger ni l’effacer. Ouvrir cette page n’y ajoute
            aucune ligne.
          </p>
        </Card>

        <Card className="mt-4 p-4 sm:p-5">
          <SectionTitle
            title={pagination.total > 0 ? `${pagination.total} événement${pagination.total > 1 ? 's' : ''}` : 'Événements'}
            aside={
              pagination.total > 0 ? (
                <span className="text-xs font-bold text-slate-500" data-testid="compteur-page">
                  {pagination.from}–{pagination.to} sur {pagination.total}
                </span>
              ) : null
            }
          />

          {events.length === 0 ? (
            <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50/60 px-4 py-10 text-center" data-testid="etat-vide">
              <FileStack size={26} className="mx-auto text-slate-300" />
              {journalVide ? (
                <>
                  <p className="mt-3 text-sm font-semibold text-slate-700">Le journal est vide.</p>
                  <p className="mx-auto mt-1 max-w-md text-xs leading-5 text-slate-500">
                    Les événements apparaîtront ici dès qu’une candidature sera ouverte ou qu’une campagne sera
                    modifiée.
                  </p>
                </>
              ) : (
                <>
                  <p className="mt-3 text-sm font-semibold text-slate-700">Aucun événement ne correspond à ces filtres.</p>
                  <p className="mx-auto mt-1 max-w-md text-xs leading-5 text-slate-500">
                    {totalWithoutFilters} événement{totalWithoutFilters > 1 ? 's' : ''} existe
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
              {/* Une liste plutôt qu'un tableau : chaque événement porte un
                  nombre variable de changements, qu'aucune grille de colonnes
                  fixes ne loge sans tronquer. */}
              <ul className="space-y-2.5" data-testid="liste-evenements">
                {events.map((evenement) => (
                  <li
                    key={evenement.id}
                    data-testid={`evenement-${evenement.id}`}
                    data-action={evenement.action}
                    className="rounded-xl border border-slate-200 p-3.5 sm:p-4"
                  >
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <div className="flex flex-wrap items-center gap-2">
                        <Pill tone={tonParPoids[evenement.weight]}>{evenement.actionLabel}</Pill>
                        <span className="text-xs text-slate-500">
                          {evenement.target.typeLabel}
                          {evenement.target.url ? (
                            <>
                              {' '}
                              <Link
                                href={evenement.target.url}
                                className="focus-ring rounded font-bold text-brand-800 hover:underline"
                              >
                                n° {evenement.target.id} <ArrowRight size={12} className="inline" aria-hidden />
                              </Link>
                            </>
                          ) : (
                            <span className="font-bold text-slate-600"> n° {evenement.target.id}</span>
                          )}
                        </span>
                      </div>
                      <time className="font-mono text-[11px] text-slate-400" dateTime={evenement.occurredAt ?? undefined}>
                        {dateComplete(evenement.occurredAt)}
                      </time>
                    </div>

                    <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                      <span className={evenement.actor.known ? 'font-semibold text-slate-700' : 'font-semibold text-slate-400 italic'}>
                        {evenement.actor.name}
                      </span>
                      {evenement.actor.email ? <span className="text-slate-400">{evenement.actor.email}</span> : null}
                      {evenement.source ? (
                        <span className="font-mono text-[11px] text-slate-400" title="Origine technique de la requête">
                          {evenement.source}
                        </span>
                      ) : null}
                    </div>

                    {evenement.changes.length > 0 ? (
                      <dl className="mt-2.5 grid gap-1.5 border-t border-slate-100 pt-2.5 sm:grid-cols-2">
                        {evenement.changes.map((changement) => (
                          <div key={changement.field} className="min-w-0">
                            <dt className="text-[10px] uppercase tracking-wide text-slate-400">{changement.field}</dt>
                            <dd className="break-words text-xs text-slate-700">
                              {changement.before === null ? null : (
                                <>
                                  <span className="text-slate-400 line-through">{changement.before}</span>{' '}
                                  <span aria-hidden>→</span>{' '}
                                </>
                              )}
                              <span className="font-semibold">{changement.after ?? '—'}</span>
                            </dd>
                          </div>
                        ))}
                      </dl>
                    ) : null}

                    {evenement.reason ? (
                      <p className="mt-2.5 border-t border-slate-100 pt-2.5 text-xs italic leading-5 text-slate-600">
                        « {evenement.reason} »
                      </p>
                    ) : null}
                  </li>
                ))}
              </ul>

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

/** Un filtre : une liste déroulante, ou un champ de date quand `type` le dit. */
function Filtre({
  id,
  label,
  value,
  options,
  onChange,
  vide,
  type,
}: {
  id: string;
  label: string;
  value: string;
  options?: Option[];
  onChange: (v: string) => void;
  vide?: string | null;
  type?: 'date';
}) {
  const classe = 'focus-ring mt-1.5 h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm transition-shadow';

  return (
    <div>
      <label htmlFor={id} className="block text-sm font-bold text-slate-700">
        {label}
      </label>
      {type === 'date' ? (
        <input id={id} name={id} type="date" value={value} onChange={(e) => onChange(e.target.value)} className={classe} />
      ) : (
        <select id={id} name={id} value={value} onChange={(e) => onChange(e.target.value)} className={classe}>
          {vide === null || vide === undefined ? null : <option value="">{vide}</option>}
          {(options ?? []).map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      )}
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
