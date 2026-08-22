import { useForm, Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Button } from '@/Components/Ui';
import { AuthShell, Champ } from './AuthShell';

export default function Login() {
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
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          post('/login');
        }}
      >
        <Champ id="email" label="Adresse e-mail" type="email" value={data.email} onChange={(v) => setData('email', v)} erreur={errors.email} autoComplete="email" autoFocus />
        <Champ id="password" label="Mot de passe" type="password" value={data.password} onChange={(v) => setData('password', v)} erreur={errors.password} autoComplete="current-password" />

        <label className="flex items-center gap-2.5 text-sm text-slate-600">
          <input
            type="checkbox"
            checked={data.remember}
            onChange={(e) => setData('remember', e.target.checked)}
            className="focus-ring h-4 w-4 rounded border-slate-300"
          />
          Rester connecté
        </label>

        <Button type="submit" className="w-full" disabled={processing}>
          {processing ? 'Connexion…' : 'Se connecter'} <ArrowRight size={17} />
        </Button>
      </form>
    </AuthShell>
  );
}
