import { useForm, usePage, Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2 } from 'lucide-react';
import { Button } from '@/Components/Ui';
import { AuthShell, Champ } from './AuthShell';

export default function Login() {
  // Le message qui suit une réinitialisation réussie : la personne arrive
  // ici depuis l'écran de nouveau mot de passe, et doit savoir que le
  // changement a bien été enregistré avant de saisir ses identifiants.
  const status = usePage<{ flash?: { status: string | null } }>().props.flash?.status ?? null;
  const { data, setData, post, processing, errors } = useForm({
    email: '',
    password: '',
    remember: false,
  });

  return (
    <AuthShell
      titre="Se connecter"
      sousTitre="Accédez à votre espace candidat BIRNIN GOBE."
      bas={
        <>
          Pas encore de compte ?{' '}
          <Link href="/register" className="focus-ring rounded font-bold text-brand-800 hover:underline">
            Créer un compte
          </Link>
        </>
      }
    >
      {status ? (
        <div
          role="status"
          data-testid="mot-de-passe-modifie"
          className="mb-5 flex items-start gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold leading-6 text-emerald-800"
        >
          <CheckCircle2 size={17} className="mt-0.5 shrink-0" /> {status}
        </div>
      ) : null}

      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          post('/login');
        }}
      >
        <Champ id="email" label="Adresse e-mail" type="email" value={data.email} onChange={(v) => setData('email', v)} erreur={errors.email} autoComplete="email" autoFocus />
        <Champ id="password" label="Mot de passe" type="password" value={data.password} onChange={(v) => setData('password', v)} erreur={errors.password} autoComplete="current-password" basculeDeVisibilite />

        <div className="flex flex-wrap items-center justify-between gap-3">
          <label className="flex items-center gap-2.5 text-sm text-slate-600">
            <input
              type="checkbox"
              checked={data.remember}
              onChange={(e) => setData('remember', e.target.checked)}
              className="focus-ring h-4 w-4 rounded border-slate-300"
            />
            Rester connecté
          </label>

          <Link href="/forgot-password" className="focus-ring rounded text-sm font-bold text-brand-800 hover:underline">
            Mot de passe oublié ?
          </Link>
        </div>

        <Button type="submit" className="w-full" disabled={processing}>
          {processing ? 'Connexion…' : 'Se connecter'} <ArrowRight size={17} />
        </Button>
      </form>
    </AuthShell>
  );
}
