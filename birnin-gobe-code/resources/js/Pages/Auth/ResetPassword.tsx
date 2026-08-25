import { useForm, Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Button } from '@/Components/Ui';
import { AuthShell, Champ } from './AuthShell';

/**
 * Choix d'un nouveau mot de passe, depuis le lien reçu par courriel.
 *
 * Le jeton et l'adresse viennent du lien et ne sont pas modifiables : les
 * réafficher en champs éditables inviterait à les corriger, alors qu'un jeton
 * retouché ne peut que devenir invalide. L'adresse est montrée en lecture
 * seule pour que la personne voie de quel compte il s'agit, et le jeton voyage
 * en champ caché.
 *
 * Un lien périmé ou déjà utilisé ne produit qu'un seul message, sur le champ
 * adresse — le serveur ne dit jamais laquelle des trois causes s'applique.
 */
export default function ResetPassword({ token, email }: { token: string; email: string }) {
  const { data, setData, post, processing, errors } = useForm({
    token,
    email,
    password: '',
    password_confirmation: '',
  });

  return (
    <AuthShell
      titre="Nouveau mot de passe"
      sousTitre="Choisissez un mot de passe pour votre compte BIRNIN GOBE."
      bas={
        <>
          Lien expiré ?{' '}
          <Link href="/forgot-password" className="focus-ring rounded font-bold text-brand-800 hover:underline">
            En demander un nouveau
          </Link>
        </>
      }
    >
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          post('/reset-password');
        }}
      >
        <div>
          <span className="block text-sm font-bold text-slate-700">Adresse e-mail</span>
          <p
            className="focus-ring mt-1.5 flex min-h-12 items-center rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-600"
            data-testid="adresse-du-lien"
          >
            {email || '—'}
          </p>
          {errors.email ? (
            <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">
              {errors.email}
            </p>
          ) : null}
        </div>

        <Champ
          id="password"
          label="Nouveau mot de passe"
          type="password"
          value={data.password}
          onChange={(v) => setData('password', v)}
          erreur={errors.password}
          autoComplete="new-password"
          autoFocus
        />
        <Champ
          id="password_confirmation"
          label="Confirmer le mot de passe"
          type="password"
          value={data.password_confirmation}
          onChange={(v) => setData('password_confirmation', v)}
          autoComplete="new-password"
        />

        <Button type="submit" className="w-full" disabled={processing}>
          {processing ? 'Enregistrement…' : 'Enregistrer le mot de passe'} <ArrowRight size={17} />
        </Button>
      </form>

      <p className="mt-5 text-xs leading-5 text-slate-500">
        Au moins 8 caractères. Les appareils où vous aviez coché « Rester connecté » devront ressaisir le
        mot de passe.
      </p>
    </AuthShell>
  );
}
