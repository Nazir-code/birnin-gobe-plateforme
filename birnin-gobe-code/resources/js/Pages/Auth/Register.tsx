import { useForm, Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Button } from '@/Components/Ui';
import { AuthShell, Champ } from './AuthShell';

export default function Register() {
  const { data, setData, post, processing, errors } = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
  });

  return (
    <AuthShell
      titre="Créer un compte candidat"
      sousTitre="Quelques informations suffisent pour commencer votre candidature."
      bas={
        <>
          Vous avez déjà un compte ?{' '}
          <Link href="/login" className="focus-ring rounded font-bold text-brand-800 hover:underline">
            Se connecter
          </Link>
        </>
      }
    >
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          post('/register');
        }}
      >
        <Champ id="name" label="Nom complet" value={data.name} onChange={(v) => setData('name', v)} erreur={errors.name} autoComplete="name" autoFocus />
        <Champ id="email" label="Adresse e-mail" type="email" value={data.email} onChange={(v) => setData('email', v)} erreur={errors.email} autoComplete="email" />
        <Champ id="password" label="Mot de passe" type="password" value={data.password} onChange={(v) => setData('password', v)} erreur={errors.password} autoComplete="new-password" />
        <Champ id="password_confirmation" label="Confirmer le mot de passe" type="password" value={data.password_confirmation} onChange={(v) => setData('password_confirmation', v)} autoComplete="new-password" />

        <Button type="submit" className="w-full" disabled={processing}>
          {processing ? 'Création…' : 'Créer mon compte'} <ArrowRight size={17} />
        </Button>
      </form>
    </AuthShell>
  );
}
