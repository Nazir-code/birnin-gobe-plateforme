import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CheckCircle2, PencilLine, Send, ShieldAlert } from 'lucide-react';
import { CandidateLayout } from '@/Layouts/CandidateLayout';
import { Button, Card, Pill, SectionTitle } from '@/Components/Ui';

/**
 * Etape 9 — relecture avant depot.
 *
 * **Cet ecran ne decide rien.** Il affiche ce que le serveur lui donne : les
 * reponses deja enregistrees, et le verdict de `SubmissionReadiness`. Aucune
 * regle de recevabilite n'est rejouee ici — pas de « si la progression atteint
 * 100 % alors on peut deposer ». Le navigateur ne connait pas les regles du
 * concours et n'a pas a les deviner ; il rend lisible ce qui a ete decide
 * ailleurs.
 *
 * Le bouton d'envoi n'est donc pas une autorisation : c'est un raccourci vers
 * une requete que le serveur reevaluera de toute facon, sous verrou, au moment
 * de l'ecriture. Un dossier devenu irrecevable entre l'affichage et le clic est
 * refuse, et l'ecran le dit.
 */
type Champ = { label: string; value: string };

type Membre = {
  name: string;
  role: string;
  email: string;
  phone: string;
  skills: string;
  availability: string;
  founder: boolean;
  consent: boolean;
};

type Section = {
  key: string;
  label: string;
  position: number;
  implemented: boolean;
  onOpenPath: boolean;
  state: string;
  completedAt: string | null;
  answeredCount: number;
  fields: Champ[];
  members: Membre[] | null;
  team: { missing: string[] } | null;
  /**
   * Pieces jointes de l'etape 8. `null` pour toutes les autres sections.
   *
   * Un fichier ne se relit pas en couple libelle/valeur : ce que le candidat
   * verifie ici, c'est qu'il a joint le bon document sous le bon intitule, et
   * que son poids n'est pas celui d'un fichier vide. Le nom d'origine et la
   * taille y suffisent — la page n'ouvre rien et ne telecharge rien d'elle-meme
   * (§8.2).
   */
  documents: Piece[] | null;
  /** Null quand la section n'a pas encore d'ecran : pas de lien mort. */
  editUrl: string | null;
};

type Piece = { type: string; label: string; filename: string; size: number };

/**
 * Poids d'une piece, ecrit pour etre lu.
 *
 * Meme formatage que l'ecran de l'etape 8 : le candidat qui relit doit
 * retrouver exactement le chiffre qu'il a vu en televersant, sinon il croit
 * que le fichier a change. La fonction est recopiee plutot qu'importee — elle
 * vit deja en double dans le depot (etape 8, detail administration), et
 * l'extraire en utilitaire partage touche trois ecrans que cette integration
 * n'a pas a rouvrir.
 */
function poids(octets: number): string {
  if (octets < 1024) return `${octets} o`;
  if (octets < 1024 * 1024) return `${Math.round(octets / 1024)} Ko`;
  return `${(octets / (1024 * 1024)).toFixed(1)} Mo`;
}

type Submission = {
  ready: boolean;
  blockers: { code: string; label: string }[];
  missingSections: { key: string; label: string; position: number }[];
  eligibility: string;
};

type Props = {
  application: {
    id: number;
    statusLabel: string;
    completionPercent: number;
    updatedAt: string | null;
  };
  campaign: { name: string; code: string; closesAt: string | null; daysLeft: number | null } | null;
  steps: { key: string; label: string; position: number }[];
  sections: Section[];
  submission: Submission;
  submitUrl: string;
  dashboardUrl: string;
};

const dateLongue = (iso: string | null) =>
  iso === null ? '—' : new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(iso));

/** Le vocabulaire des etats vient du serveur ; l'ecran ne fait que l'habiller. */
const etats: Record<string, { texte: string; ton: 'green' | 'gold' | 'neutral' | 'red' }> = {
  complete: { texte: 'Terminée', ton: 'green' },
  incomplete: { texte: 'À compléter', ton: 'gold' },
  'non-commencee': { texte: 'Non commencée', ton: 'red' },
  'hors-parcours': { texte: 'Hors parcours', ton: 'neutral' },
  'non-implementee': { texte: 'Pas encore disponible', ton: 'neutral' },
};

export default function Review({ application, campaign, sections, submission, submitUrl, dashboardUrl }: Props) {
  const [confirmation, setConfirmation] = useState(false);
  const [envoi, setEnvoi] = useState(false);

  /**
   * Le clic n'est jamais direct : une confirmation s'interpose, parce que le
   * depot est irreversible et qu'aucun ecran ne permettra de revenir dessus.
   */
  const deposer = () => {
    if (envoi) return;

    setEnvoi(true);
    router.post(submitUrl, {}, {
      // Le bouton reste desactive jusqu'a la reponse : c'est la parade au
      // double-clic cote navigateur. Le serveur, lui, a son verrou.
      onFinish: () => {
        setEnvoi(false);
        setConfirmation(false);
      },
    });
  };

  return (
    <CandidateLayout active="Ma candidature">
      <Head title="Relecture de ma candidature — BIRNIN GOBE" />

      <div className="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
        <Link href={dashboardUrl} className="focus-ring inline-flex items-center gap-2 rounded text-sm font-semibold text-brand-800">
          <ArrowLeft size={16} /> Retour au tableau de bord
        </Link>

        <header className="mt-4">
          <div className="kicker">Étape 9 sur 9</div>
          <h1 className="mt-2 text-3xl font-black tracking-tight text-brand-950 sm:text-4xl">Relecture et envoi</h1>
          <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
            Relisez chaque étape avant de déposer. Vous pouvez encore corriger ce que vous voulez :
            tant que le dossier n’est pas déposé, rien n’est définitif.
          </p>
        </header>

        {/* — Ce que le serveur pense du dossier, tel quel */}
        <Card className="mt-5 p-5 sm:p-6" data-testid="recevabilite">
          {submission.ready ? (
            <div className="flex flex-wrap items-start gap-3">
              <CheckCircle2 className="mt-0.5 shrink-0 text-green-700" size={22} />
              <div className="min-w-0 flex-1">
                <div className="text-sm font-black text-slate-800">Votre dossier est complet.</div>
                <p className="mt-1 text-sm leading-6 text-slate-600">
                  Vous pouvez le déposer. Après le dépôt, il ne sera plus modifiable.
                </p>
              </div>
            </div>
          ) : (
            <div className="flex flex-wrap items-start gap-3">
              <ShieldAlert className="mt-0.5 shrink-0 text-amber-600" size={22} />
              <div className="min-w-0 flex-1">
                <div className="text-sm font-black text-slate-800">Votre dossier ne peut pas encore être déposé.</div>

                <ul className="mt-2 space-y-1.5 text-sm leading-6 text-slate-700" data-testid="motifs-blocage">
                  {submission.blockers.map((motif) => (
                    <li key={motif.code} className="flex items-start gap-2" data-motif={motif.code}>
                      <AlertTriangle size={15} className="mt-1 shrink-0 text-amber-600" />
                      <span>{motif.label}</span>
                    </li>
                  ))}
                </ul>

                {submission.missingSections.length > 0 ? (
                  <div className="mt-3" data-testid="etapes-manquantes">
                    <div className="text-xs font-bold uppercase tracking-wide text-slate-500">Étapes à terminer</div>
                    <ul className="mt-1.5 flex flex-wrap gap-2">
                      {submission.missingSections.map((etape) => {
                        const cible = sections.find((s) => s.key === etape.key);

                        return (
                          <li key={etape.key}>
                            {cible?.editUrl ? (
                              <Link
                                href={cible.editUrl}
                                className="focus-ring inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-900"
                              >
                                {etape.position}. {etape.label} <PencilLine size={13} />
                              </Link>
                            ) : (
                              <span className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-500">
                                {etape.position}. {etape.label} — bientôt disponible
                              </span>
                            )}
                          </li>
                        );
                      })}
                    </ul>
                  </div>
                ) : null}
              </div>
            </div>
          )}
        </Card>

        {/* — Le dossier, section par section */}
        <div className="mt-5 space-y-4">
          {sections.map((section) => {
            const etat = etats[section.state] ?? { texte: section.state, ton: 'neutral' as const };

            return (
              <Card key={section.key} className="p-5 sm:p-6" data-testid={`relecture-${section.key}`} data-etat={section.state}>
                <SectionTitle
                  eyebrow={`Étape ${section.position}`}
                  title={section.label}
                  aside={
                    <div className="flex items-center gap-2">
                      <Pill tone={etat.ton}>{etat.texte}</Pill>
                      {section.editUrl ? (
                        <Link
                          href={section.editUrl}
                          className="focus-ring inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-slate-200 px-3 text-xs font-bold text-brand-800 hover:bg-slate-50"
                          data-testid={`modifier-${section.key}`}
                        >
                          <PencilLine size={14} /> Modifier
                        </Link>
                      ) : null}
                    </div>
                  }
                />

                {section.fields.length > 0 ? (
                  <dl className="grid gap-3 sm:grid-cols-2">
                    {section.fields.map((champ) => (
                      <div key={champ.label}>
                        <dt className="text-xs font-bold uppercase tracking-wide text-slate-400">{champ.label}</dt>
                        <dd className="mt-0.5 text-sm leading-6 text-slate-800">{champ.value}</dd>
                      </div>
                    ))}
                  </dl>
                ) : null}

                {section.members && section.members.length > 0 ? (
                  <ul className="mt-3 grid gap-2" data-testid={`membres-${section.key}`}>
                    {section.members.map((membre, rang) => (
                      <li key={`${membre.name}-${rang}`} className="rounded-xl border border-slate-200 p-3">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="font-bold text-slate-800">{membre.name || '—'}</span>
                          {membre.role ? <span className="text-xs text-slate-500">{membre.role}</span> : null}
                          <Pill tone={membre.consent ? 'green' : 'red'}>
                            {membre.consent ? 'Consentement donné' : 'Consentement manquant'}
                          </Pill>
                        </div>
                        {membre.email || membre.phone ? (
                          <div className="mt-1 text-xs text-slate-500">{[membre.email, membre.phone].filter(Boolean).join(' · ')}</div>
                        ) : null}
                      </li>
                    ))}
                  </ul>
                ) : null}

                {section.documents && section.documents.length > 0 ? (
                  <ul className="mt-3 grid gap-2" data-testid={`pieces-${section.key}`}>
                    {section.documents.map((piece) => (
                      <li key={piece.type} className="rounded-xl border border-slate-200 p-3">
                        <div className="text-xs font-bold uppercase tracking-wide text-slate-400">{piece.label}</div>
                        <div className="mt-0.5 flex flex-wrap items-baseline gap-2">
                          <span className="break-all text-sm font-semibold text-slate-800">{piece.filename}</span>
                          <span className="text-xs text-slate-500">{poids(piece.size)}</span>
                        </div>
                      </li>
                    ))}
                  </ul>
                ) : null}

                {section.fields.length === 0
                  && (section.members?.length ?? 0) === 0
                  && (section.documents?.length ?? 0) === 0 ? (
                  <p className="text-sm leading-6 text-slate-500">
                    {section.implemented
                      ? 'Rien n’est encore enregistré pour cette étape.'
                      : 'Cette étape n’est pas encore disponible sur la plateforme.'}
                  </p>
                ) : null}
              </Card>
            );
          })}
        </div>

        {/* — Le dépôt */}
        <Card className="mt-5 p-5 sm:p-6">
          <SectionTitle title="Déposer ma candidature" />

          {campaign?.closesAt ? (
            <p className="text-sm leading-6 text-slate-600">
              Édition {campaign.name} — clôture le {dateLongue(campaign.closesAt)}.
            </p>
          ) : null}

          {submission.ready ? (
            <div className="mt-4">
              {confirmation ? (
                <div className="rounded-2xl border border-brand-200 bg-brand-50/50 p-4" data-testid="confirmation-depot" role="alertdialog" aria-label="Confirmer la soumission">
                  <div className="text-sm font-black text-brand-950">En soumettant votre candidature :</div>
                  <ul className="mt-2 list-disc space-y-1 pl-5 text-sm leading-6 text-slate-700">
                    <li>vous confirmez avoir relu les informations ;</li>
                    <li>le dossier sera officiellement déposé ;</li>
                    <li>il ne pourra plus être modifié après soumission.</li>
                  </ul>

                  <div className="mt-4 flex flex-wrap gap-3">
                    <Button variant="ghost" type="button" onClick={() => setConfirmation(false)} disabled={envoi} data-testid="annuler-depot">
                      Annuler
                    </Button>
                    <Button type="button" onClick={deposer} disabled={envoi} data-testid="confirmer-depot">
                      <Send size={17} /> {envoi ? 'Soumission…' : 'Confirmer la soumission'}
                    </Button>
                  </div>
                </div>
              ) : (
                <Button type="button" className="min-w-56" onClick={() => setConfirmation(true)} data-testid="soumettre">
                  <Send size={17} /> Soumettre ma candidature
                </Button>
              )}
            </div>
          ) : (
            <div className="mt-4">
              <Button className="min-w-56" disabled data-testid="soumettre-desactive">
                <Send size={17} /> Soumettre ma candidature
              </Button>
              <p className="mt-2 text-sm leading-6 text-slate-500">
                Terminez les points listés plus haut pour pouvoir déposer votre dossier.
              </p>
            </div>
          )}
        </Card>
      </div>
    </CandidateLayout>
  );
}
