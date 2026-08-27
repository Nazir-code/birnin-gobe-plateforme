import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowLeft, Check, Save } from 'lucide-react';
import { Button, Card } from '@/Components/Ui';

/**
 * Briques communes aux ecrans de section du candidat.
 *
 * Extraites lors de l'ouverture des etapes 5 a 7 : trois ecrans de plus
 * auraient signifie trois copies du meme champ, du meme bas de page et du meme
 * encart d'etat. Ce qui diverge en silence dans une copie, c'est
 * l'accessibilite — le rattachement du libelle, l'annonce de l'erreur — et c'est
 * la moins visible des divergences. Meme raison que pour `Champs.tsx` cote
 * administration.
 *
 * Ce qui n'est PAS ici : les champs eux-memes, leur validation et leur notion de
 * completude. Ils appartiennent a leur section, cote serveur. Ces composants ne
 * savent que presenter.
 *
 * Contrat d'accessibilite, conforme a docs/architecture/BLUEPRINT-UI-FOUNDATION.md :
 *   le libelle est lie au controle (`htmlFor` / `id`), jamais seulement visuel ;
 *   l'aide et l'erreur sont rattachees par `aria-describedby` ;
 *   l'erreur porte `role="alert"` ;
 *   la cible tactile ne descend pas sous 44 px, et le focus reste visible.
 */

/**
 * Entete d'un ecran de section : le rang de l'etape, son titre et ce qu'elle
 * demande. Les valeurs viennent de `ApplicationPresenter::section()`.
 */
export function EnteteSection({ position, total, titre, intro }: {
  position: number; total: number; titre: string; intro: string;
}) {
  return (
    <div>
      <div className="text-xs font-black uppercase tracking-[.15em] text-brand-800">Étape {position} sur {total}</div>
      <h1 className="mt-2 text-3xl font-black tracking-tight text-brand-950 sm:text-4xl">{titre}</h1>
      <div className="mt-3 h-1.5 w-14 rounded-full bg-gold-500" />
      <p className="mt-5 max-w-2xl text-slate-600">{intro}</p>
    </div>
  );
}

/** Classe commune des controles de saisie, avec l'etat d'erreur. */
export function saisie(erreur?: string): string {
  return `focus-ring min-h-12 w-full rounded-lg border bg-white px-4 text-sm text-slate-800 ${erreur ? 'border-red-400' : 'border-slate-300'}`;
}

export function Groupe({ icone, titre, aide }: { icone: ReactNode; titre: string; aide?: string }) {
  return (
    <div className="mb-6 flex gap-3 border-b border-slate-100 pb-4">
      <div className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-800">{icone}</div>
      <div>
        <h2 className="text-lg font-extrabold tracking-tight text-ink-950">{titre}</h2>
        {aide ? <p className="mt-1 text-xs leading-5 text-slate-500">{aide}</p> : null}
      </div>
    </div>
  );
}

/** Un champ, son aide et son erreur. */
export function Champ({ nom, label, aide, requis, erreur, children }: {
  nom: string; label: string; aide: string; requis: boolean; erreur?: string; children: ReactNode;
}) {
  const aideId = `${nom}-aide`;
  const erreurId = `${nom}-erreur`;

  return (
    <div className="mb-6 last:mb-0">
      <label className="mb-1.5 block text-sm font-extrabold text-slate-800" htmlFor={nom}>
        {label} {requis ? <span className="text-red-500" aria-hidden>*</span> : <span className="font-semibold text-slate-400">(facultatif)</span>}
        {requis ? <span className="sr-only">(obligatoire)</span> : null}
      </label>
      <p className="mb-2 text-xs leading-5 text-slate-500" id={aideId}>{aide}</p>
      <div aria-describedby={erreur ? `${aideId} ${erreurId}` : aideId}>{children}</div>
      {erreur ? <span className="mt-1 block text-xs font-semibold text-red-600" id={erreurId} role="alert">{erreur}</span> : null}
    </div>
  );
}

/**
 * Zone de texte redigee, avec son compteur.
 *
 * `maxLength` epargne une frappe inutile au candidat ; la limite qui fait foi
 * est celle de la FormRequest, cote serveur.
 */
export function Redaction({ nom, label, aide, requis, erreur, max, valeur, onChange, onBlur, lignes = 'min-h-28' }: {
  nom: string; label: string; aide: string; requis: boolean; erreur?: string; max: number;
  valeur: string; onChange: (v: string) => void; onBlur: () => void; lignes?: string;
}) {
  return (
    <Champ nom={nom} label={label} aide={aide} requis={requis} erreur={erreur}>
      <textarea
        id={nom}
        name={nom}
        maxLength={max}
        className={`${saisie(erreur)} ${lignes} resize-y py-3 leading-6`}
        value={valeur}
        onChange={(e) => onChange(e.target.value)}
        onBlur={onBlur}
      />
      <span className="mt-1 block text-right text-[11px] text-slate-400">{valeur.length} / {max}</span>
    </Champ>
  );
}

/**
 * Bas de page : revenir, enregistrer, continuer.
 *
 * La sauvegarde est declenchee avant toute navigation, et les reponses viennent
 * de toute facon de la base au retour : aller-retour sans perte.
 *
 * `suivantUrl` a `null` n'est pas un oubli — c'est la reponse honnete du serveur
 * quand l'etape suivante n'est pas encore ouverte. Un bouton inactif qui dit
 * pourquoi vaut mieux qu'un lien vers une page qui n'existe pas.
 */
export function BarreNavigation({ precedentUrl, suivantUrl, onFlush, onSave }: {
  precedentUrl: string | null; suivantUrl: string | null; onFlush: () => void; onSave: () => void;
}) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div className="flex flex-wrap items-center gap-3">
        {precedentUrl ? (
          <Link href={precedentUrl} onClick={onFlush} data-testid="precedent"
            className="focus-ring press-feedback inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-brand-900/35 bg-white px-5 text-sm font-bold text-brand-900 transition-colors hover:bg-brand-50">
            <ArrowLeft size={16} /> Précédent
          </Link>
        ) : null}
        <Button variant="ghost" type="button" onClick={onSave}><Save size={17} /> Enregistrer</Button>
      </div>
      {suivantUrl ? (
        <Link href={suivantUrl} onClick={onFlush} data-testid="suivant"
          className="focus-ring press-feedback inline-flex min-h-11 min-w-44 items-center justify-center gap-2 rounded-xl bg-gold-500 px-5 text-sm font-bold text-ink-950 transition-colors hover:bg-gold-600">
          Suivant <span aria-hidden>→</span>
        </Link>
      ) : (
        <Button variant="secondary" className="min-w-44" disabled title="L’étape suivante n’est pas encore ouverte.">
          Suivant <span aria-hidden>→</span>
        </Button>
      )}
    </div>
  );
}

/**
 * Encart d'etat : ce qu'il reste a faire, ou la confirmation que l'etape est
 * complete.
 *
 * Le decompte se fait sur les champs obligatoires, exactement comme
 * `isComplete()` cote serveur — c'est le « resume des erreurs par etape » du
 * §5.3, et il ne doit jamais annoncer une etape faite que le serveur refuserait
 * de compter.
 */
export function EtatSection({ manquants }: { manquants: number }) {
  if (manquants > 0) {
    return (
      <Card className="self-start border-amber-200 bg-[#fffdf5] p-6" data-testid="etat-section">
        <h2 className="text-base font-black leading-tight text-brand-950">
          Il reste {manquants} réponse{manquants > 1 ? 's' : ''} à donner
        </h2>
        <p className="mt-2 text-xs leading-5 text-slate-600">
          Votre saisie est enregistrée même incomplète. Cette étape ne comptera comme terminée qu’une fois ces champs remplis.
        </p>
      </Card>
    );
  }

  return (
    <Card className="self-start border-emerald-200 bg-emerald-50/60 p-6" data-testid="etat-section">
      <div className="flex items-center gap-3">
        <div className="grid h-10 w-10 place-items-center rounded-full bg-emerald-100 text-emerald-700"><Check size={20} /></div>
        <h2 className="text-base font-black leading-tight text-brand-950">Étape complète</h2>
      </div>
      <p className="mt-3 text-xs leading-5 text-slate-600">Vous pourrez y revenir tant que votre dossier n’est pas soumis.</p>
    </Card>
  );
}

/**
 * Encart « deja renseigne » : ce que le dossier detient ailleurs, rappele sans
 * etre redemande, avec le lien vers l'etape qui en est la source.
 */
export function DejaRenseigne({ titre, children, lien }: {
  titre: string; children: ReactNode; lien: { href: string; label: string };
}) {
  return (
    <Card className="self-start border-brand-100 bg-brand-50/40 p-6" data-testid="deja-renseigne">
      <h2 className="text-base font-black leading-tight text-brand-950">{titre}</h2>
      <div className="mt-3 text-xs leading-5 text-slate-600">{children}</div>
      <Link href={lien.href} className="focus-ring mt-4 inline-flex text-xs font-bold text-brand-800 underline underline-offset-2">
        {lien.label}
      </Link>
    </Card>
  );
}
