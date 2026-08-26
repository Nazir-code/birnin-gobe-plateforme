import { useState, type PropsWithChildren, type ReactNode } from 'react';
import { Head, Link } from '@inertiajs/react';
import { Eye, EyeOff } from 'lucide-react';
import { BrandLogo } from '@/Components/Brand';
import { Card } from '@/Components/Ui';

/**
 * Cadre commun aux écrans d'authentification.
 *
 * Volontairement sobre et sans navigation : rien d'autre que le parcours
 * candidat n'a sa place ici — ni sélecteur de rôle, ni accès interne (ADR-003).
 */
export function AuthShell({
  titre,
  sousTitre,
  bas,
  children,
}: PropsWithChildren<{ titre: string; sousTitre: string; bas: ReactNode }>) {
  return (
    <div className="bg-pattern flex min-h-screen flex-col items-center justify-center px-5 py-10">
      <Head title={`${titre} — BIRNIN GOBE`} />

      <Link href="/" className="focus-ring rounded-lg" aria-label="Retour à l'accueil BIRNIN GOBE">
        <BrandLogo size="header" />
      </Link>

      <Card className="mt-7 w-full max-w-[440px] p-6 sm:p-8">
        <h1 className="text-2xl font-black tracking-tight text-brand-950">{titre}</h1>
        <p className="mt-1.5 text-sm leading-6 text-slate-500">{sousTitre}</p>
        <div className="mt-6">{children}</div>
      </Card>

      <p className="mt-5 text-center text-sm text-slate-600">{bas}</p>
    </div>
  );
}

/** Champ de formulaire : libellé, saisie, message d'erreur serveur. */
export function Champ({
  id,
  label,
  type = 'text',
  value,
  onChange,
  erreur,
  autoComplete,
  autoFocus,
  required = true,
  basculeDeVisibilite = false,
}: {
  id: string;
  label: string;
  type?: string;
  value: string;
  onChange: (valeur: string) => void;
  erreur?: string;
  autoComplete?: string;
  autoFocus?: boolean;
  required?: boolean;
  /**
   * Ajoute l'œil qui montre ou masque la saisie. Volontairement à la demande
   * plutôt que systématique sur tout champ `password` : les écrans de création
   * et de réinitialisation demandent une confirmation, et rien ne doit changer
   * pour eux sans qu'on l'ait voulu.
   */
  basculeDeVisibilite?: boolean;
}) {
  const idErreur = `${id}-erreur`;
  const [visible, setVisible] = useState(false);
  const bascule = basculeDeVisibilite && type === 'password';

  return (
    <div>
      <label htmlFor={id} className="block text-sm font-bold text-slate-700">
        {label}
      </label>
      <div className="relative mt-1.5">
        <input
          id={id}
          name={id}
          // Seul l'attribut change : la valeur saisie n'est ni relue, ni
          // recopiée, ni réécrite en basculant.
          type={bascule && visible ? 'text' : type}
          value={value}
          required={required}
          autoComplete={autoComplete}
          autoFocus={autoFocus}
          aria-invalid={erreur ? true : undefined}
          aria-describedby={erreur ? idErreur : undefined}
          onChange={(e) => onChange(e.target.value)}
          // `pr-12` dégage la place du bouton : le texte saisi ne passe jamais
          // dessous, si long soit-il.
          className={`focus-ring h-12 w-full rounded-xl border pl-4 text-sm transition-shadow ${
            bascule ? 'pr-12' : 'pr-4'
          } ${erreur ? 'border-red-400' : 'border-slate-300'}`}
        />
        {bascule ? (
          <button
            type="button"
            onClick={() => setVisible((affiche) => !affiche)}
            // Le clic ne déplace pas le focus hors du champ : sur mobile, le
            // clavier resterait sinon ouvert puis se refermerait pour rien.
            // Le bouton reste atteignable au clavier, par tabulation.
            onMouseDown={(e) => e.preventDefault()}
            aria-label={visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
            // 48 px de côté : la cible reste confortable au doigt.
            className="focus-ring absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-xl text-slate-500 transition-colors hover:text-brand-800"
          >
            {visible ? <EyeOff size={18} aria-hidden /> : <Eye size={18} aria-hidden />}
          </button>
        ) : null}
      </div>
      {erreur ? (
        <p id={idErreur} role="alert" className="mt-1.5 text-xs font-semibold text-red-600">
          {erreur}
        </p>
      ) : null}
    </div>
  );
}
