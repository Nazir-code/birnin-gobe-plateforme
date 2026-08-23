import type { ReactNode } from 'react';

/**
 * Champs de formulaire de l'administration.
 *
 * Extraits de `Admin/Campaigns/Form.tsx`, qui les portait seul, le jour où un
 * second écran d'administration en a eu besoin : deux copies finissent toujours
 * par diverger sur l'accessibilité — c'est-à-dire sur ce qu'un lecteur d'écran
 * annonce — et c'est la moins visible des divergences.
 *
 * Contrat commun aux trois composants, conforme aux exigences non négociables
 * de `docs/architecture/BLUEPRINT-UI-FOUNDATION.md` :
 *   le libellé est lié au contrôle (`htmlFor` / `id`), jamais seulement visuel ;
 *   l'aide et l'erreur sont rattachées par `aria-describedby` ;
 *   l'erreur porte `role="alert"` et `aria-invalid` ;
 *   la cible tactile ne descend pas sous 44 px, et le focus reste visible.
 */
export type Option = { value: string; label: string };

export function Champ({
  id,
  label,
  value,
  onChange,
  erreur,
  aide,
  type = 'text',
  required = true,
  autoFocus,
  mono,
  inputMode,
  min,
  max,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (v: string) => void;
  erreur?: string;
  aide?: ReactNode;
  type?: string;
  required?: boolean;
  autoFocus?: boolean;
  mono?: boolean;
  inputMode?: 'numeric';
  min?: number;
  max?: number;
}) {
  const idErreur = `${id}-erreur`;
  const idAide = `${id}-aide`;

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
        autoFocus={autoFocus}
        inputMode={inputMode}
        min={min}
        max={max}
        aria-invalid={erreur ? true : undefined}
        aria-describedby={[erreur ? idErreur : null, aide ? idAide : null].filter(Boolean).join(' ') || undefined}
        onChange={(e) => onChange(e.target.value)}
        className={`focus-ring mt-1.5 h-12 w-full rounded-xl border px-4 text-sm transition-shadow ${mono ? 'font-mono' : ''} ${erreur ? 'border-red-400' : 'border-slate-300'}`}
      />
      {aide ? <p id={idAide} className="mt-1.5 text-[11px] leading-4 text-slate-400">{aide}</p> : null}
      {erreur ? <p id={idErreur} role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{erreur}</p> : null}
    </div>
  );
}

export function Selecteur({
  id,
  label,
  value,
  onChange,
  erreur,
  options,
  aide,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (v: string) => void;
  erreur?: string;
  options: Option[];
  aide?: ReactNode;
}) {
  const idErreur = `${id}-erreur`;
  const idAide = `${id}-aide`;

  return (
    <div>
      <label htmlFor={id} className="block text-sm font-bold text-slate-700">
        {label}
      </label>
      <select
        id={id}
        name={id}
        value={value}
        aria-invalid={erreur ? true : undefined}
        aria-describedby={[erreur ? idErreur : null, aide ? idAide : null].filter(Boolean).join(' ') || undefined}
        onChange={(e) => onChange(e.target.value)}
        className={`focus-ring mt-1.5 h-12 w-full rounded-xl border bg-white px-4 text-sm transition-shadow ${erreur ? 'border-red-400' : 'border-slate-300'}`}
      >
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
      {aide ? <p id={idAide} className="mt-1.5 text-[11px] leading-4 text-slate-400">{aide}</p> : null}
      {erreur ? <p id={idErreur} role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{erreur}</p> : null}
    </div>
  );
}

/**
 * Liste de cases à cocher, dans un `fieldset` : c'est le groupe qui porte la
 * question, chaque case ne portant que sa propre réponse.
 *
 * Aucune case cochée n'est un état légitime et distinct de « tout cocher » : il
 * signifie « la campagne n'a pas arrêté cette liste ». L'appelant l'explique,
 * le composant ne l'interprète pas.
 */
export function CasesACocher({
  id,
  legende,
  options,
  valeurs,
  onChange,
  erreur,
  aide,
}: {
  id: string;
  legende: string;
  options: Option[];
  valeurs: string[];
  onChange: (v: string[]) => void;
  erreur?: string;
  aide?: ReactNode;
}) {
  const idErreur = `${id}-erreur`;
  const idAide = `${id}-aide`;
  const toutes = valeurs.length === options.length;

  return (
    <fieldset
      aria-describedby={[erreur ? idErreur : null, aide ? idAide : null].filter(Boolean).join(' ') || undefined}
    >
      {/* `legend` est enfant direct de `fieldset` : imbriqué dans un `div`, il
          ne nomme plus le groupe, et le lecteur d'écran annonce des cases sans
          question. C'est pourquoi le raccourci « tout cocher » est sur sa
          propre ligne plutôt qu'aligné avec le titre. */}
      <legend className="text-sm font-bold text-slate-700">{legende}</legend>

      <div className="mt-1 flex justify-end">
        <button
          type="button"
          onClick={() => onChange(toutes ? [] : options.map((o) => o.value))}
          className="focus-ring min-h-9 rounded-lg px-2 text-xs font-bold text-brand-800 hover:underline"
        >
          {toutes ? 'Tout décocher' : 'Tout cocher'}
        </button>
      </div>

      <div className="grid gap-1.5 sm:grid-cols-2">
        {options.map((option) => {
          const coche = valeurs.includes(option.value);

          return (
            <label
              key={option.value}
              className={`focus-within:ring-brand-700/40 flex min-h-11 cursor-pointer items-center gap-2.5 rounded-xl border px-3 text-sm transition-colors focus-within:ring-2 ${coche ? 'border-brand-800 bg-brand-50 font-semibold text-brand-900' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'}`}
            >
              <input
                type="checkbox"
                name={`${id}[]`}
                value={option.value}
                checked={coche}
                onChange={(e) =>
                  onChange(
                    e.target.checked
                      ? [...valeurs, option.value]
                      : valeurs.filter((v) => v !== option.value),
                  )
                }
                className="size-4 accent-brand-800"
              />
              {option.label}
            </label>
          );
        })}
      </div>

      {aide ? <p id={idAide} className="mt-2 text-[11px] leading-4 text-slate-400">{aide}</p> : null}
      {erreur ? <p id={idErreur} role="alert" className="mt-2 text-xs font-semibold text-red-600">{erreur}</p> : null}
    </fieldset>
  );
}
