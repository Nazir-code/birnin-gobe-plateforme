import { useMemo, useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, FileText, Lock, ShieldCheck, TriangleAlert } from 'lucide-react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { EVALUATOR_LOGOUT, evaluatorNav } from '@/Layouts/evaluatorNav';
import { Button, Card, Pill, SectionTitle } from '@/Components/Ui';

/**
 * La grille de notation — §11.2, §11.3.
 *
 * **Le total est recalculé à chaque frappe, avec la formule du serveur.**
 * `poids × note / 5`, sommé, arrondi une seule fois à la fin. C'est la même
 * opération que `ScoreSheet` : l'évaluateur doit signer le chiffre qu'il a vu,
 * et deux calculs séparés — l'un pour l'écran, l'autre pour la base — auraient
 * fini par en afficher deux.
 *
 * **Le total reste « — » tant que les huit critères ne sont pas notés.** Un
 * total partiel se lirait comme une note faible alors qu'il ne dit que « pas
 * fini », et c'est le malentendu qui fait écarter un bon dossier.
 *
 * **La justification apparaît d'elle-même sur une note extrême.** Le §11.3
 * l'exige pour 0 et 5 ; l'écran ouvre alors le champ et le signale, plutôt que
 * de laisser découvrir l'exigence au moment du verrouillage. Le serveur la
 * revérifie : ce composant sert la saisie, il ne garantit rien.
 *
 * **Enregistrer et verrouiller postent la même chose.** Le second ajoute
 * l'irréversibilité, pas un second formulaire à remplir — un bouton
 * « verrouiller » qui exigerait d'avoir enregistré d'abord perdrait la dernière
 * modification de quiconque l'oublie.
 *
 * **Verrouillée, la page devient une lecture.** Tous les champs sont désactivés
 * et les boutons disparaissent : le §11.3 ne prévoit aucun déverrouillage, et
 * afficher un formulaire modifiable qui échoue à l'envoi serait pire que de ne
 * rien afficher.
 */
type Critere = { value: string; label: string; weight: number; elements: string };
type Note = { criterion: string; score: number | null; comment: string | null };
type Ancre = { value: number; label: string; extreme: boolean };
type Recommandation = { value: string; label: string; help: string; requiresComment: boolean };

type Champ = { label: string; value: string };
type Piece = { type: string; label: string; filename: string; downloadUrl: string };
type Section = {
  key: string;
  label: string;
  position: number;
  answeredCount: number;
  fields: Champ[];
  documents: Piece[] | null;
  members: { name: string; role: string }[] | null;
};

type Props = {
  assignment: { id: number; assignedAt: string | null; acceptedAt: string | null };
  application: {
    submissionNumber: string | null;
    campaignName: string;
    themeLabel: string;
    submittedAt: string | null;
    candidateName: string;
  };
  sections: Section[];
  evaluation: {
    id: number;
    status: string;
    statusLabel: string;
    locked: boolean;
    lockedAt: string | null;
    recommendation: string | null;
    comment: string | null;
    totalScore: number | null;
  };
  criteria: Critere[];
  scores: Note[];
  anchors: Ancre[];
  recommendations: Recommandation[];
  limits: { maxScore: number; totalWeight: number };
  urls: { save: string; lock: string; conflict: string; back: string };
};

type Formulaire = {
  scores: { criterion: string; score: string; comment: string }[];
  recommendation: string;
  comment: string;
};

/** Le score pondéré d'un critère, ou `null` si le critère n'est pas noté. */
function pondere(note: string, poids: number, max: number): number | null {
  if (note === '') return null;
  const valeur = Number(note);
  return Number.isFinite(valeur) ? (poids * valeur) / max : null;
}

function fr(nombre: number): string {
  return nombre.toFixed(2).replace('.', ',');
}

export default function Evaluate({
  assignment,
  application,
  sections,
  evaluation,
  criteria,
  scores,
  anchors,
  recommendations,
  limits,
  urls,
}: Props) {
  const [recusationOuverte, setRecusationOuverte] = useState(false);

  // Les refus du domaine (`lock`, `evaluation`) et les erreurs de champ à clé
  // pointée (`scores.3.comment`) ne sont pas des champs du formulaire : ils se
  // lisent sur le sac d'erreurs partagé, pas sur `form.errors`, dont le type
  // n'admet que les clés de la saisie.
  const erreurs = ((usePage().props as { errors?: Record<string, string> }).errors ?? {});

  const form = useForm<Formulaire>({
    scores: criteria.map((critere) => {
      const note = scores.find((s) => s.criterion === critere.value);
      return {
        criterion: critere.value,
        score: note?.score === null || note?.score === undefined ? '' : String(note.score),
        comment: note?.comment ?? '',
      };
    }),
    recommendation: evaluation.recommendation ?? '',
    comment: evaluation.comment ?? '',
  });

  const recusation = useForm<{ reason: string }>({ reason: '' });

  const verrouillee = evaluation.locked;

  /** Le total sur 100, ou `null` tant qu'un critère n'est pas noté. */
  const total = useMemo(() => {
    let somme = 0;
    for (const critere of criteria) {
      const ligne = form.data.scores.find((s) => s.criterion === critere.value);
      const points = pondere(ligne?.score ?? '', critere.weight, limits.maxScore);
      if (points === null) return null;
      somme += points;
    }
    return Math.round(somme * 100) / 100;
  }, [form.data.scores, criteria, limits.maxScore]);

  const recommandationChoisie = recommendations.find((r) => r.value === form.data.recommendation);

  function majNote(critere: string, champ: 'score' | 'comment', valeur: string) {
    form.setData(
      'scores',
      form.data.scores.map((ligne) => (ligne.criterion === critere ? { ...ligne, [champ]: valeur } : ligne)),
    );
  }

  const affiche = verrouillee && evaluation.totalScore !== null ? evaluation.totalScore : total;

  return (
    <DarkSidebarLayout
      items={evaluatorNav}
      active="Mes dossiers"
      title={application.submissionNumber ?? 'Dossier affecté'}
      subtitle={`${application.themeLabel} · ${application.campaignName}`}
      logoutHref={EVALUATOR_LOGOUT}
    >
      <Head title={`Évaluation ${application.submissionNumber ?? ''} — BIRNIN GOBE`} />

      <div className="mx-auto max-w-[1080px] p-5 sm:p-7">
        <Link
          href={urls.back}
          className="focus-ring inline-flex items-center gap-1.5 rounded-lg text-sm font-bold text-brand-800 hover:underline"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden />
          Revenir à mes dossiers
        </Link>

        {verrouillee ? (
          <p
            className="mt-4 flex items-start gap-2 rounded-xl border border-slate-300 bg-slate-50 p-4 text-sm text-slate-700"
            role="status"
            data-testid="bandeau-verrouille"
          >
            <Lock className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
            <span>
              Évaluation verrouillée
              {evaluation.lockedAt
                ? ` le ${new Date(evaluation.lockedAt).toLocaleDateString('fr-FR', {
                    day: '2-digit',
                    month: 'long',
                    year: 'numeric',
                  })}`
                : ''}
              . Elle ne peut plus être modifiée : c’est ce qui garantit l’indépendance des notations (§11.3).
            </span>
          </p>
        ) : (
          <p className="mt-4 flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
            <span>
              Vous avez signé la charte pour ce dossier
              {assignment.acceptedAt
                ? ` le ${new Date(assignment.acceptedAt).toLocaleDateString('fr-FR', {
                    day: '2-digit',
                    month: 'long',
                    year: 'numeric',
                  })}`
                : ''}
              . Votre notation reste un brouillon modifiable jusqu’au verrouillage.
            </span>
          </p>
        )}

        {/* Le dossier, en lecture seule */}
        <Card className="mt-5 p-4 sm:p-5">
          <SectionTitle
            eyebrow="Dossier"
            title={application.candidateName}
            aside={<span className="text-xs font-bold text-slate-500">Lecture seule</span>}
          />

          <div className="mt-4 space-y-4">
            {sections.map((section) => (
              <section key={section.key} className="rounded-xl border border-slate-200 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-sm font-bold text-slate-900">
                    {section.position}. {section.label}
                  </p>
                  <span className="text-xs text-slate-500">{section.answeredCount} réponse(s)</span>
                </div>

                {section.fields.length > 0 ? (
                  <dl className="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    {section.fields.map((champ) => (
                      <div key={champ.label}>
                        <dt className="text-xs font-bold text-slate-500">{champ.label}</dt>
                        <dd className="text-sm text-slate-800">{champ.value}</dd>
                      </div>
                    ))}
                  </dl>
                ) : null}

                {section.members && section.members.length > 0 ? (
                  <ul className="mt-3 space-y-1.5 text-sm text-slate-700">
                    {section.members.map((membre, index) => (
                      <li key={`${membre.name}-${index}`}>
                        <strong>{membre.name}</strong> — {membre.role}
                      </li>
                    ))}
                  </ul>
                ) : null}

                {section.documents && section.documents.length > 0 ? (
                  <ul className="mt-3 space-y-2">
                    {section.documents.map((piece) => (
                      <li key={piece.type}>
                        <a
                          href={piece.downloadUrl}
                          className="focus-ring inline-flex items-center gap-2 rounded-lg text-sm font-bold text-brand-800 hover:underline"
                        >
                          <FileText className="h-4 w-4" aria-hidden />
                          {piece.label} — {piece.filename}
                        </a>
                      </li>
                    ))}
                  </ul>
                ) : null}
              </section>
            ))}
          </div>
        </Card>

        {/* La grille du §11.2 */}
        <form
          onSubmit={(e) => {
            e.preventDefault();
            form.put(urls.save, { preserveScroll: true });
          }}
        >
          <Card className="mt-5 p-4 sm:p-5">
            <SectionTitle
              eyebrow="§11.2 — Grille de présélection"
              title={`Notation sur ${limits.totalWeight} points`}
              aside={
                <span
                  className="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-black text-slate-800"
                  data-testid="total"
                >
                  {affiche === null ? '—' : fr(affiche)} / {limits.totalWeight}
                </span>
              }
            />

            <p className="mt-2 text-sm text-slate-600">
              Échelle 0 à {limits.maxScore} (§11.3) :{' '}
              {anchors.map((ancre) => `${ancre.value} ${ancre.label.toLowerCase()}`).join(' · ')}. Le total ne
              s’affiche qu’une fois les {criteria.length} critères notés — un total partiel se lirait comme une note
              faible.
            </p>

            {erreurs.lock || erreurs.evaluation ? (
              <p
                className="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-800"
                role="alert"
                data-testid="refus"
              >
                {erreurs.lock ?? erreurs.evaluation}
              </p>
            ) : null}

            <ul className="mt-4 space-y-3" data-testid="criteres">
              {criteria.map((critere, index) => {
                const ligne = form.data.scores.find((s) => s.criterion === critere.value);
                const note = ligne?.score ?? '';
                const ancre = anchors.find((a) => String(a.value) === note);
                const extreme = ancre?.extreme ?? false;
                const points = pondere(note, critere.weight, limits.maxScore);
                const noteId = `score-${critere.value}`;

                return (
                  <li key={critere.value} className="rounded-xl border border-slate-200 p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      {/* `flex-1 basis-64` plutôt qu'une largeur laissée au
                          contenu : sans base de flex, un texte long occupait
                          toute la ligne et renvoyait les contrôles en dessous,
                          pour ce seul critère. « Innovation » fait 126
                          caractères d'éléments d'appréciation contre 58 à 92
                          pour les autres — sa rangée décrochait donc seule,
                          et l'écart se lisait comme un défaut d'alignement.
                          Le bloc se rétrécit désormais au lieu de pousser, et
                          les huit rangées s'alignent quelle que soit la
                          longueur du texte. La base de 16rem garde le repli
                          sous les contrôles sur les écrans étroits — pour les
                          huit à la fois, jamais pour un seul. */}
                      <div className="min-w-0 flex-1 basis-64">
                        <label htmlFor={noteId} className="text-sm font-bold text-slate-900">
                          {critere.label}
                        </label>
                        <p className="mt-1 text-xs leading-5 text-slate-500">{critere.elements}</p>
                      </div>
                      <div className="flex shrink-0 items-center gap-3">
                        <span className="text-xs font-bold text-slate-500">{critere.weight} pts</span>
                        <select
                          id={noteId}
                          value={note}
                          disabled={verrouillee}
                          onChange={(e) => majNote(critere.value, 'score', e.target.value)}
                          className="focus-ring h-11 rounded-xl border border-slate-300 bg-white px-3 text-sm disabled:bg-slate-100"
                          data-testid={`note-${critere.value}`}
                        >
                          <option value="">Non noté</option>
                          {anchors.map((a) => (
                            <option key={a.value} value={a.value}>
                              {a.value} — {a.label}
                            </option>
                          ))}
                        </select>
                        <span className="w-16 text-right text-sm font-bold text-slate-700">
                          {points === null ? '—' : fr(points)}
                        </span>
                      </div>
                    </div>

                    {/* La justification s'ouvre d'elle-même sur une note extrême. */}
                    {extreme || (ligne?.comment ?? '') !== '' ? (
                      <div className="mt-3">
                        <label htmlFor={`comment-${critere.value}`} className="block text-xs font-bold text-slate-700">
                          Justification{extreme ? ' — obligatoire pour une note de 0 ou de ' + limits.maxScore : ''}
                        </label>
                        <textarea
                          id={`comment-${critere.value}`}
                          value={ligne?.comment ?? ''}
                          disabled={verrouillee}
                          onChange={(e) => majNote(critere.value, 'comment', e.target.value)}
                          className={`focus-ring mt-1.5 min-h-20 w-full rounded-xl border p-3 text-sm disabled:bg-slate-100 ${
                            extreme && (ligne?.comment ?? '') === '' ? 'border-amber-400' : 'border-slate-300'
                          }`}
                          data-testid={`justification-${critere.value}`}
                        />
                        {erreurs[`scores.${index}.comment`] ? (
                          <p className="mt-1 text-xs font-bold text-rose-700">
                            {erreurs[`scores.${index}.comment`]}
                          </p>
                        ) : null}
                      </div>
                    ) : null}
                  </li>
                );
              })}
            </ul>
          </Card>

          {/* La recommandation du §11.3 */}
          <Card className="mt-5 p-4 sm:p-5">
            <SectionTitle
              eyebrow="§11.3 — Avis"
              title="Recommandation"
              aside={<Pill tone={verrouillee ? 'neutral' : 'green'}>{evaluation.statusLabel}</Pill>}
            />

            <p className="mt-2 text-sm text-slate-600">
              La short-list est une proposition, pas une décision : le comité tranche (§11.3).
            </p>

            <fieldset className="mt-4 space-y-2" disabled={verrouillee}>
              <legend className="sr-only">Recommandation</legend>
              {recommendations.map((option) => (
                <label
                  key={option.value}
                  className={`flex cursor-pointer items-start gap-3 rounded-xl border p-3 ${
                    form.data.recommendation === option.value ? 'border-brand-700 bg-brand-50' : 'border-slate-200'
                  }`}
                >
                  <input
                    type="radio"
                    name="recommendation"
                    value={option.value}
                    checked={form.data.recommendation === option.value}
                    onChange={(e) => form.setData('recommendation', e.target.value)}
                    className="focus-ring mt-0.5 h-4 w-4"
                    data-testid={`recommandation-${option.value}`}
                  />
                  <span className="min-w-0">
                    <span className="block text-sm font-bold text-slate-900">{option.label}</span>
                    <span className="mt-0.5 block text-xs text-slate-500">{option.help}</span>
                  </span>
                </label>
              ))}
            </fieldset>

            <div className="mt-4">
              <label htmlFor="comment" className="block text-sm font-bold text-slate-700">
                Commentaire général
                {recommandationChoisie?.requiresComment ? ' — obligatoire pour cette recommandation' : ''}
              </label>
              <textarea
                id="comment"
                value={form.data.comment}
                disabled={verrouillee}
                onChange={(e) => form.setData('comment', e.target.value)}
                className={`focus-ring mt-1.5 min-h-28 w-full rounded-xl border p-3 text-sm disabled:bg-slate-100 ${
                  recommandationChoisie?.requiresComment && form.data.comment.trim() === ''
                    ? 'border-amber-400'
                    : 'border-slate-300'
                }`}
                placeholder="Ce qui justifie votre avis, et ce que le comité doit savoir avant de trancher…"
                data-testid="commentaire"
              />
              {form.errors.comment ? (
                <p className="mt-1 text-xs font-bold text-rose-700">{form.errors.comment}</p>
              ) : null}
            </div>

            {!verrouillee ? (
              <div className="mt-5 flex flex-wrap items-center gap-3">
                <Button
                  type="button"
                  variant="danger"
                  onClick={() => setRecusationOuverte((ouvert) => !ouvert)}
                  data-testid="ouvrir-recusation"
                >
                  Me récuser sur ce dossier
                </Button>
                <Button type="submit" variant="ghost" className="ml-auto" disabled={form.processing} data-testid="enregistrer">
                  Enregistrer le brouillon
                </Button>
                <Button
                  type="button"
                  variant="secondary"
                  disabled={form.processing}
                  onClick={() => form.post(urls.lock, { preserveScroll: true })}
                  data-testid="verrouiller"
                >
                  <Lock size={16} /> Verrouiller l’évaluation
                </Button>
              </div>
            ) : null}
          </Card>
        </form>

        {recusationOuverte && !verrouillee ? (
          <Card className="mt-5 border-amber-300 p-4 sm:p-5">
            <SectionTitle eyebrow="§11.1 — Conflit déclaré" title="Récusation" />

            <p className="mt-2 flex items-start gap-2 text-sm text-amber-900">
              <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
              <span>
                Ce dossier vous sera retiré, votre brouillon sera abandonné, et il ne vous sera plus reproposé.
              </span>
            </p>

            <form
              className="mt-4"
              onSubmit={(e) => {
                e.preventDefault();
                recusation.post(urls.conflict);
              }}
            >
              <label htmlFor="reason" className="block text-sm font-bold text-slate-700">
                Nature du conflit
              </label>
              <textarea
                id="reason"
                value={recusation.data.reason}
                onChange={(e) => recusation.setData('reason', e.target.value)}
                className="focus-ring mt-1.5 min-h-24 w-full rounded-xl border border-slate-300 p-3 text-sm"
                data-testid="motif-recusation"
              />
              {recusation.errors.reason ? (
                <p className="mt-1 text-xs font-bold text-rose-700">{recusation.errors.reason}</p>
              ) : null}

              <div className="mt-4 flex justify-end">
                <Button type="submit" variant="danger" disabled={recusation.processing} data-testid="confirmer-recusation">
                  Confirmer la récusation
                </Button>
              </div>
            </form>
          </Card>
        ) : null}
      </div>
    </DarkSidebarLayout>
  );
}
