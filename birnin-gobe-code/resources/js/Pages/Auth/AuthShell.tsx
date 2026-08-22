import type { PropsWithChildren, ReactNode } from 'react';
import { Head, Link } from '@inertiajs/react';
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
}) {
  const idErreur = `${id}-erreur`;

  return (
    <div>
      <label htmlFor={id} className="block text-sm font-bold text-slate-700">
        {label}
      </label>
      <input
        id={id}
        name={id}
        type={type}
        value={value}
        required={required}
        autoComplete={autoComplete}
        autoFocus={autoFocus}
        aria-invalid={erreur ? true : undefined}
        aria-describedby={erreur ? idErreur : undefined}
        onChange={(e) => onChange(e.target.value)}
        className={`focus-ring mt-1.5 h-12 w-full rounded-xl border px-4 text-sm transition-shadow ${
          erreur ? 'border-red-400' : 'border-slate-300'
        }`}
      />
      {erreur ? (
        <p id={idErreur} role="alert" className="mt-1.5 text-xs font-semibold text-red-600">
          {erreur}
        </p>
      ) : null}
    </div>
  );
}
