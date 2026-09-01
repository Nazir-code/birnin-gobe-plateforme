import { Link, useForm } from '@inertiajs/react';
import { ArrowRight, ShieldCheck } from 'lucide-react';
import { Button } from '@/Components/Ui';
import { AuthShell, Champ } from '@/Pages/Auth/AuthShell';

type SessionEnCours = { name: string; roleLabel: string; logoutUrl: string } | null;

/**
 * Bandeau affiché quand une autre identité est déjà connectée.
 *
 * Sans lui, la page renvoyait vers l'accueil sans un mot : quelqu'un qui tapait
 * l'URL de son espace atterrissait sur la page publique, sans rien pour
 * comprendre ni rien à faire. Nommer la session en cours et offrir le geste qui
 * la ferme coûte trois lignes et lève un cul-de-sac.
 */
function SessionAFermer({ session }: { session: NonNullable<SessionEnCours> }) {
  return (
    <div className="mb-6 rounded-xl border border-amber-300 bg-amber-50 px-3.5 py-3 text-xs leading-5 text-amber-900">
      <p className="font-bold">Une autre session est ouverte.</p>
      <p className="mt-1">
        Vous êtes connecté en tant que <strong>{session.name}</strong> ({session.roleLabel}). Fermez cette
        session pour accéder à cet espace.
      </p>
      <Link
        href={session.logoutUrl}
        method="post"
        as="button"
        className="focus-ring mt-2.5 inline-flex min-h-9 items-center rounded-lg bg-amber-800 px-3 text-xs font-bold text-white transition hover:bg-amber-900"
      >
        Fermer la session en cours
      </Link>
    </div>
  );
}

/**
 * Accès interne à l'espace évaluateur (ADR-003, ADR-021).
 *
 * Même dépouillement que l'accès administration, et pour les mêmes raisons : ni
 * « créer un compte », ni « mot de passe oublié », ni lien vers l'espace
 * candidat. Les comptes évaluateurs sont provisionnés en ligne de commande
 * (`evaluator:create`), il n'y a donc rien à proposer à qui n'en a pas.
 *
 * Aucun écran public ou candidat ne pointe vers cette page.
 */
export default function EvaluatorLogin({ sessionEnCours = null }: { sessionEnCours?: SessionEnCours }) {
  const { data, setData, post, processing, errors } = useForm({
    email: '',
    password: '',
  });

  return (
    <AuthShell
      titre="Accès évaluateur"
      sousTitre="Évaluation BIRNIN GOBE — PIDUREM / ANSI."
      bas="Les comptes évaluateurs sont provisionnés par l’administrateur système. Aucune inscription n’est possible depuis cet écran."
    >
      {sessionEnCours ? <SessionAFermer session={sessionEnCours} /> : null}

      <p className="mb-6 flex items-start gap-2.5 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-3 text-xs leading-5 text-slate-600">
        <ShieldCheck size={16} className="mt-0.5 shrink-0 text-brand-800" />
        Espace réservé aux évaluateurs habilités. Les accès sont nominatifs et journalisés.
      </p>

      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          post('/evaluator/login');
        }}
      >
        <Champ
          id="email"
          label="Adresse e-mail"
          type="email"
          value={data.email}
          onChange={(v) => setData('email', v)}
          erreur={errors.email}
          autoComplete="username"
          autoFocus
        />
        <Champ
          id="password"
          label="Mot de passe"
          type="password"
          value={data.password}
          onChange={(v) => setData('password', v)}
          erreur={errors.password}
          autoComplete="current-password"
          basculeDeVisibilite
        />

        {/* Pas de « rester connecté » : une session interne ne survit pas au
            navigateur fermé. */}
        <Button type="submit" className="w-full" disabled={processing}>
          {processing ? 'Connexion…' : 'Accéder à mon plan de travail'} <ArrowRight size={17} />
        </Button>
      </form>
    </AuthShell>
  );
}
