import { useForm, usePage, Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2 } from 'lucide-react';
import { Button } from '@/Components/Ui';
import { AuthShell, Champ } from './AuthShell';

/**
 * « Mot de passe oublié » — demande d'un lien de réinitialisation.
 *
 * L'écran affiche exactement la même confirmation dans tous les cas : adresse
 * inconnue, adresse connue, ou demande déjà faite il y a quelques secondes.
 * C'est délibéré et c'est le serveur qui l'impose — un formulaire public ne
 * doit pas permettre de savoir qui est inscrit sur cette plateforme.
 *
 * Le formulaire reste affiché après l'envoi, plutôt que d'être remplacé par un
 * message seul : quelqu'un qui s'est trompé d'adresse doit pouvoir corriger
 * sans revenir en arrière.
 */
export default function ForgotPassword() {
  const status = usePage<{ flash?: { status: string | null } }>().props.flash?.status ?? null;
  const { data, setData, post, processing, errors } = useForm({ email: '' });

  return (
    <AuthShell
      titre="Mot de passe oublié"
      sousTitre="Indiquez votre adresse e-mail : nous vous enverrons un lien pour choisir un nouveau mot de passe."
      bas={
        <>
          Vous vous en souvenez ?{' '}
          <Link href="/login" className="focus-ring rounded font-bold text-brand-800 hover:underline">
            Se connecter
          </Link>
        </>
      }
    >
      {status ? (
        <div
          role="status"
          data-testid="lien-envoye"
          className="mb-5 flex items-start gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold leading-6 text-emerald-800"
        >
          <CheckCircle2 size={17} className="mt-0.5 shrink-0" /> {status}
        </div>
      ) : null}

      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          post('/forgot-password');
        }}
      >
        <Champ
          id="email"
          label="Adresse e-mail"
          type="email"
          value={data.email}
          onChange={(v) => setData('email', v)}
          erreur={errors.email}
          autoComplete="email"
          autoFocus
        />

        <Button type="submit" className="w-full" disabled={processing}>
          {processing ? 'Envoi…' : 'Envoyer le lien'} <ArrowRight size={17} />
        </Button>
      </form>

      <p className="mt-5 text-xs leading-5 text-slate-500">
        Le lien reste valable une heure. Passé ce délai, revenez ici en demander un nouveau.
      </p>
    </AuthShell>
  );
}
