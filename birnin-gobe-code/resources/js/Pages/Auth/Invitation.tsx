import { useForm, Link } from '@inertiajs/react';
import { ArrowRight, ShieldCheck } from 'lucide-react';
import { Button } from '@/Components/Ui';
import { AuthShell, Champ } from './AuthShell';

/**
 * Première définition du mot de passe d'un compte interne — ADR-022.
 *
 * Proche de `ResetPassword`, et volontairement pas le même écran : les mots
 * diffèrent parce que la situation diffère. Personne n'a rien demandé ici ;
 * c'est un administrateur qui a créé le compte. Écrire « nouveau mot de
 * passe » laisserait croire qu'il en existait un avant — or il n'y en a jamais
 * eu, et c'est précisément la garantie que ce parcours offre : le compte n'a
 * qu'un détenteur, celui qui remplit ce formulaire.
 *
 * Le jeton et l'adresse viennent du lien et ne sont pas modifiables : les
 * réafficher en champs éditables inviterait à les corriger, alors qu'un jeton
 * retouché ne peut que devenir invalide.
 *
 * Un lien périmé ou déjà utilisé ne produit qu'un seul message — le serveur ne
 * dit jamais laquelle des causes s'applique.
 */
export default function Invitation({ token, email }: { token: string; email: string }) {
  const { data, setData, post, processing, errors } = useForm({
    token,
    email,
    password: '',
    password_confirmation: '',
  });

  return (
    <AuthShell
      titre="Définir mon mot de passe"
      sousTitre="Votre accès à la plateforme BIRNIN GOBE."
      bas={
        <>
          Invitation expirée ?{' '}
          <Link href="/forgot-password" className="focus-ring rounded font-bold text-brand-800 hover:underline">
            Demander un nouveau lien
          </Link>
        </>
      }
    >
      <p className="mb-6 flex items-start gap-2.5 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-3 text-xs leading-5 text-slate-600">
        <ShieldCheck size={16} className="mt-0.5 shrink-0 text-brand-800" />
        Vous serez seul à connaître ce mot de passe : il n’a été communiqué à personne, pas même à
        l’administrateur qui a créé votre accès.
      </p>

      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          post('/invitation');
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
          label="Mot de passe"
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
          {processing ? 'Enregistrement…' : 'Définir mon mot de passe'} <ArrowRight size={17} />
        </Button>
      </form>
    </AuthShell>
  );
}
