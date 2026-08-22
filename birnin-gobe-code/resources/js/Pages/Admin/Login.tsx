import { useForm } from '@inertiajs/react';
import { ArrowRight, ShieldCheck } from 'lucide-react';
import { Button } from '@/Components/Ui';
import { AuthShell, Champ } from '@/Pages/Auth/AuthShell';

/**
 * Accès interne à l'administration (ADR-003, ADR-005).
 *
 * Volontairement dépouillé, et distinct de la connexion candidat : ni « créer
 * un compte », ni « mot de passe oublié », ni lien vers l'espace candidat. Les
 * comptes internes sont provisionnés en ligne de commande, il n'y a donc rien
 * à proposer à qui n'en a pas.
 *
 * Aucun écran public ou candidat ne pointe vers cette page.
 */
export default function AdminLogin() {
  const { data, setData, post, processing, errors } = useForm({
    email: '',
    password: '',
  });

  return (
    <AuthShell
      titre="Accès interne"
      sousTitre="Administration BIRNIN GOBE — PIDUREM / ANSI."
      bas="Les comptes internes sont provisionnés par l’administrateur système. Aucune inscription n’est possible depuis cet écran."
    >
      <p className="mb-6 flex items-start gap-2.5 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-3 text-xs leading-5 text-slate-600">
        <ShieldCheck size={16} className="mt-0.5 shrink-0 text-brand-800" />
        Espace réservé aux personnels habilités. Les accès sont nominatifs et journalisés.
      </p>

      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          post('/admin/login');
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
        />

        {/* Pas de « rester connecté » : une session interne ne survit pas au
            navigateur fermé. */}
        <Button type="submit" className="w-full" disabled={processing}>
          {processing ? 'Connexion…' : 'Accéder à l’administration'} <ArrowRight size={17} />
        </Button>
      </form>
    </AuthShell>
  );
}
