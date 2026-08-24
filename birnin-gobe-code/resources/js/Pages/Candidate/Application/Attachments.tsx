import { useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import { Check, ChevronDown, FileText, Info, Paperclip, ShieldCheck, Trash2, Upload, UserCircle2 } from 'lucide-react';
import { CandidateLayout } from '@/Layouts/CandidateLayout';
import { Card } from '@/Components/Ui';
import { Reveal } from '@/Components/Reveal';
import { SaveIndicator } from '@/Components/SaveIndicator';
import { SectionStepsAside, type SectionStep } from '@/Components/SectionStepsAside';
import { BarreNavigation, EnteteSection, Groupe } from '@/Components/SectionForm';
import { useAuthUser } from '@/hooks/useAuth';
import { useAutosave } from '@/hooks/useAutosave';

/** Declarations du §7.3, persistees comme les reponses des sept autres etapes. */
type Declarations = {
  accuracy_and_control: boolean;
  no_fraud_or_plagiarism: boolean;
  team_representation: boolean;
  rules_acknowledgement: boolean;
  data_processing_consent: boolean;
  public_communication_consent: boolean;
};

type DocumentType = {
  value: string;
  label: string;
  help: string;
  required: boolean;
  extensions: string[];
  maxKilobytes: number;
};

/**
 * Une piece deja deposee.
 *
 * Le serveur n'envoie ni chemin de stockage ni empreinte : le nom, le poids, la
 * date et une URL de telechargement qui verifie la propriete.
 */
type StoredDocument = {
  type: string;
  filename: string;
  size: number;
  uploadedAt: string | null;
  downloadUrl: string;
  deleteUrl: string;
};

type Props = {
  steps: SectionStep[];
  section: { key: string; label: string; position: number; total: number; completedAt: string | null };
  answers: Declarations;
  requiredDeclarations: (keyof Declarations)[];
  documents: Record<string, StoredDocument>;
  documentTypes: DocumentType[];
  missing: { documents: string[]; declarations: string[] };
  uploadUrl: string;
  saveUrl: string;
  previousUrl: string | null;
  nextUrl: string | null;
};

/** Jeton CSRF depose par Laravel, comme dans `useAutosave`. */
function jetonCsrf(): string {
  const cookie = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
  return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

/** Poids lisible, sans dependance : le fichier n'est jamais charge pour l'afficher. */
function poids(octets: number): string {
  if (octets < 1024) return `${octets} o`;
  if (octets < 1024 * 1024) return `${Math.round(octets / 1024)} Ko`;
  return `${(octets / (1024 * 1024)).toFixed(1)} Mo`;
}

type EtatPiece = 'idle' | 'sending' | 'error';

export default function Attachments({
  steps, section, answers, requiredDeclarations, documents, documentTypes,
  missing, uploadUrl, saveUrl, previousUrl, nextUrl,
}: Props) {
  const user = useAuthUser();
  const [values, setValues] = useState<Declarations>(answers);
  // Les pieces viennent du serveur a chaque ecriture : l'ecran n'en tient
  // jamais sa propre version.
  const [pieces, setPieces] = useState<Record<string, StoredDocument>>(documents);
  const [restant, setRestant] = useState(missing);
  const [etats, setEtats] = useState<Record<string, EtatPiece>>({});
  const [erreurs, setErreurs] = useState<Record<string, string>>({});

  const { state, savedAt, errors, flush, save } = useAutosave<Declarations, {
    documents: Record<string, StoredDocument>;
    missing: { documents: string[]; declarations: string[] };
  }>(saveUrl, values);

  const set = (champ: keyof Declarations) => (valeur: boolean) => setValues((v) => ({ ...v, [champ]: valeur }));
  const requise = (champ: keyof Declarations) => requiredDeclarations.includes(champ);

  /**
   * Depot ou remplacement d'une piece.
   *
   * Une requete par fichier, declenchee par le choix du candidat — jamais par la
   * sauvegarde automatique. La reponse porte l'etat complet des pieces et ce
   * qu'il reste a faire : l'ecran n'a rien a recalculer.
   */
  async function televerser(type: string, fichier: File) {
    setEtats((e) => ({ ...e, [type]: 'sending' }));
    setErreurs((e) => ({ ...e, [type]: '' }));

    const charge = new FormData();
    charge.append('type', type);
    charge.append('document', fichier);

    try {
      const reponse = await fetch(uploadUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': jetonCsrf() },
        body: charge,
      });

      if (reponse.status === 422) {
        const corps = (await reponse.json()) as { errors?: Record<string, string[]> };
        setErreurs((e) => ({ ...e, [type]: corps.errors?.document?.[0] ?? 'Ce fichier a été refusé.' }));
        setEtats((et) => ({ ...et, [type]: 'error' }));
        return;
      }

      if (!reponse.ok) throw new Error(`HTTP ${reponse.status}`);

      const corps = await reponse.json();
      setPieces(corps.documents ?? {});
      setRestant(corps.missing ?? { documents: [], declarations: [] });
      setEtats((et) => ({ ...et, [type]: 'idle' }));
    } catch {
      setErreurs((e) => ({ ...e, [type]: 'L’envoi a échoué. Vérifiez votre connexion et réessayez.' }));
      setEtats((et) => ({ ...et, [type]: 'error' }));
    }
  }

  async function supprimer(type: string, url: string) {
    setEtats((e) => ({ ...e, [type]: 'sending' }));

    try {
      const reponse = await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': jetonCsrf() },
      });

      if (!reponse.ok) throw new Error(`HTTP ${reponse.status}`);

      const corps = await reponse.json();
      setPieces(corps.documents ?? {});
      setRestant(corps.missing ?? { documents: [], declarations: [] });
      setEtats((et) => ({ ...et, [type]: 'idle' }));
      setErreurs((e) => ({ ...e, [type]: '' }));
    } catch {
      setErreurs((e) => ({ ...e, [type]: 'La suppression a échoué. Réessayez.' }));
      setEtats((et) => ({ ...et, [type]: 'error' }));
    }
  }

  const manquants = restant.documents.length + restant.declarations.length;

  return (
    <CandidateLayout active="Ma candidature" topSlot={<div className="hidden md:flex"><SaveIndicator state={state} savedAt={savedAt} /></div>}>
      <Head title={`${section.label} — Ma candidature BIRNIN GOBE`} />
      <div className="grid min-h-[calc(100vh-76px)] lg:grid-cols-[260px_1fr]">
        <SectionStepsAside steps={steps} activeKey={section.key} />
        <div className="min-w-0 bg-white px-5 py-8 sm:px-8 xl:px-12">
          <div className="mx-auto max-w-[1220px]">
            <div className="mb-6 flex items-start justify-between gap-4">
              <EnteteSection
                position={section.position}
                total={section.total}
                titre={section.label}
                intro="Les documents qui accompagnent votre dossier, et les déclarations que vous signez en le déposant."
              />
              {user ? <div className="hidden items-center gap-2 text-slate-700 sm:flex"><UserCircle2 size={34} className="text-brand-800" /><span className="text-sm font-semibold">Bonjour, {user.name.split(' ')[0]}</span><ChevronDown size={15} /></div> : null}
            </div>
            <div className="md:hidden"><SaveIndicator state={state} savedAt={savedAt} /></div>

            <div className="mt-4 grid gap-6 xl:grid-cols-[1fr_340px]">
              <div className="min-w-0 space-y-6">
                <Reveal><Card className="p-6 sm:p-7" data-testid="bloc-pieces">
                  <Groupe icone={<Paperclip size={18} />} titre="Pièces jointes" aide="Un fichier par pièce. Vous pouvez les remplacer ou les retirer tant que votre dossier n’est pas déposé." />
                  <div className="space-y-4">
                    {documentTypes.map((doc) => (
                      <LignePiece
                        key={doc.value}
                        doc={doc}
                        piece={pieces[doc.value]}
                        etat={etats[doc.value] ?? 'idle'}
                        erreur={erreurs[doc.value]}
                        onChoisir={(fichier) => televerser(doc.value, fichier)}
                        onSupprimer={() => { const p = pieces[doc.value]; if (p) void supprimer(doc.value, p.deleteUrl); }}
                      />
                    ))}
                  </div>
                </Card></Reveal>

                <Reveal delay={60}><Card className="p-6 sm:p-7" data-testid="bloc-declarations">
                  <Groupe icone={<ShieldCheck size={18} />} titre="Déclarations" aide="Elles engagent votre responsabilité. Lisez-les avant de cocher." />
                  <div className="space-y-1">
                    <Declaration nom="accuracy_and_control" requise={requise('accuracy_and_control')} valeur={values.accuracy_and_control} onChange={set('accuracy_and_control')} onBlur={flush} erreur={errors.accuracy_and_control}
                      texte="Je certifie l’exactitude des renseignements fournis et j’accepte tout contrôle des informations et des pièces de mon dossier." />
                    <Declaration nom="no_fraud_or_plagiarism" requise={requise('no_fraud_or_plagiarism')} valeur={values.no_fraud_or_plagiarism} onChange={set('no_fraud_or_plagiarism')} onBlur={flush} erreur={errors.no_fraud_or_plagiarism}
                      texte="Je déclare que mon dossier ne contient aucun contenu frauduleux, plagié ou illicite." />
                    {requise('team_representation') ? (
                      <Declaration nom="team_representation" requise valeur={values.team_representation} onChange={set('team_representation')} onBlur={flush} erreur={errors.team_representation}
                        texte="Je déclare être autorisé·e à représenter mon équipe et à déposer ce dossier en son nom." />
                    ) : null}
                    <Declaration nom="rules_acknowledgement" requise={requise('rules_acknowledgement')} valeur={values.rules_acknowledgement} onChange={set('rules_acknowledgement')} onBlur={flush} erreur={errors.rules_acknowledgement}
                      texte="Je reconnais avoir pris connaissance du règlement, de la grille de sélection, des règles de propriété intellectuelle, de confidentialité et de publication des résultats." />
                    <Declaration nom="data_processing_consent" requise={requise('data_processing_consent')} valeur={values.data_processing_consent} onChange={set('data_processing_consent')} onBlur={flush} erreur={errors.data_processing_consent}
                      texte="Je consens au traitement des données nécessaires à l’examen de ma candidature." />
                    <Declaration nom="public_communication_consent" requise={false} valeur={values.public_communication_consent} onChange={set('public_communication_consent')} onBlur={flush} erreur={errors.public_communication_consent}
                      texte="J’accepte que mon projet puisse être cité dans la communication publique du concours et les actualités à venir."
                      aide="Facultatif. Votre candidature est examinée de la même façon si vous refusez." />
                  </div>
                </Card></Reveal>

                {/* Aucun bouton de depot ici : l'envoi definitif appartient a
                    l'etape 9, « Relecture / envoi », qui n'est pas ouverte. */}
                <BarreNavigation precedentUrl={previousUrl} suivantUrl={nextUrl} onFlush={flush} onSave={save} />
              </div>

              <div className="space-y-5">
                <Reveal delay={100}>
                  {manquants > 0 ? (
                    <Card className="self-start border-amber-200 bg-[#fffdf5] p-6" data-testid="etat-section">
                      <h2 className="text-base font-black leading-tight text-brand-950">
                        Il reste {manquants} élément{manquants > 1 ? 's' : ''} à fournir
                      </h2>
                      <ul className="mt-3 space-y-1.5 text-xs leading-5 text-slate-600">
                        {restant.documents.map((type) => (
                          <li key={type}>• {documentTypes.find((d) => d.value === type)?.label ?? type}</li>
                        ))}
                        {restant.declarations.length > 0 ? (
                          <li>• {restant.declarations.length} déclaration{restant.declarations.length > 1 ? 's' : ''} à accepter</li>
                        ) : null}
                      </ul>
                    </Card>
                  ) : (
                    <Card className="self-start border-emerald-200 bg-emerald-50/60 p-6" data-testid="etat-section">
                      <div className="flex items-center gap-3">
                        <div className="grid h-10 w-10 place-items-center rounded-full bg-emerald-100 text-emerald-700"><Check size={20} /></div>
                        <h2 className="text-base font-black leading-tight text-brand-950">Étape complète</h2>
                      </div>
                      <p className="mt-3 text-xs leading-5 text-slate-600">Vous pourrez revenir sur vos pièces tant que votre dossier n’est pas déposé.</p>
                    </Card>
                  )}
                </Reveal>

                <Reveal delay={160}>
                  <Card className="self-start border-slate-200 bg-slate-50 p-6">
                    <div className="flex items-center gap-3">
                      <div className="grid h-10 w-10 place-items-center rounded-full bg-slate-200 text-slate-700"><Info size={20} /></div>
                      <h2 className="text-base font-black leading-tight text-brand-950">Vos fichiers restent privés</h2>
                    </div>
                    <p className="mt-3 text-xs leading-5 text-slate-600">
                      Vos pièces ne sont accessibles ni par une adresse publique ni par un autre candidat. Seuls vous et l’équipe d’organisation pouvez les ouvrir.
                    </p>
                  </Card>
                </Reveal>
              </div>
            </div>

            <div className="mt-6 flex items-center gap-2 text-xs text-slate-500"><Check className="text-brand-700" size={16} /> Vos données sont sécurisées et confidentielles.</div>
          </div>
        </div>
      </div>
    </CandidateLayout>
  );
}

/**
 * Une pièce : son état, et les seules actions possibles avant dépôt.
 *
 * Aucune prévisualisation : afficher la page ne télécharge aucun fichier. Le
 * poids est écrit en toutes lettres pour qu'un candidat sur forfait mobile sache
 * ce qu'il a envoyé et ce qu'il rechargerait.
 */
function LignePiece({ doc, piece, etat, erreur, onChoisir, onSupprimer }: {
  doc: DocumentType;
  piece?: StoredDocument;
  etat: EtatPiece;
  erreur?: string;
  onChoisir: (fichier: File) => void;
  onSupprimer: () => void;
}) {
  const champ = useRef<HTMLInputElement>(null);
  const formats = doc.extensions.map((e) => e.toUpperCase()).join(', ');
  const mega = (doc.maxKilobytes / 1024).toFixed(0);
  const erreurId = `${doc.value}-erreur`;

  return (
    <div className="rounded-xl border border-slate-200 p-4" data-testid={`piece-${doc.value}`} data-etat={piece ? 'deposee' : 'absente'}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="text-sm font-extrabold text-slate-800">
            {doc.label}{' '}
            {doc.required
              ? <><span className="text-red-500" aria-hidden>*</span><span className="sr-only">(obligatoire)</span></>
              : <span className="font-semibold text-slate-400">(facultatif)</span>}
          </div>
          <p className="mt-1 text-xs leading-5 text-slate-500">{doc.help}</p>
          <p className="mt-1 text-[11px] text-slate-400">{formats} — {mega} Mo maximum</p>
        </div>
        {piece ? (
          <span className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-bold text-emerald-800 ring-1 ring-emerald-200">
            <Check size={13} /> Déposée
          </span>
        ) : null}
      </div>

      {piece ? (
        <div className="mt-3 flex flex-wrap items-center gap-3 rounded-lg bg-slate-50 p-3" data-testid={`piece-${doc.value}-fichier`}>
          <FileText size={18} className="shrink-0 text-brand-800" />
          <div className="min-w-0 flex-1">
            <a href={piece.downloadUrl} className="focus-ring block truncate text-sm font-semibold text-brand-900 underline underline-offset-2" data-testid={`piece-${doc.value}-nom`}>
              {piece.filename}
            </a>
            <span className="text-[11px] text-slate-500" data-testid={`piece-${doc.value}-poids`}>{poids(piece.size)}</span>
          </div>
        </div>
      ) : null}

      <div className="mt-3 flex flex-wrap items-center gap-2">
        <input
          ref={champ}
          type="file"
          className="sr-only"
          id={`fichier-${doc.value}`}
          accept={doc.extensions.map((e) => `.${e}`).join(',')}
          onChange={(e) => { const f = e.target.files?.[0]; if (f) onChoisir(f); e.target.value = ''; }}
        />
        <label
          htmlFor={`fichier-${doc.value}`}
          className="focus-ring press-feedback inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-xl border border-brand-900/35 bg-white px-4 text-sm font-bold text-brand-900 transition-colors hover:bg-brand-50"
          data-testid={`piece-${doc.value}-choisir`}
        >
          <Upload size={16} /> {piece ? 'Remplacer' : 'Téléverser'}
        </label>
        {piece ? (
          <button
            type="button"
            onClick={onSupprimer}
            className="focus-ring press-feedback inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-red-300 bg-white px-4 text-sm font-bold text-red-600 transition-colors hover:bg-red-50"
            data-testid={`piece-${doc.value}-supprimer`}
          >
            <Trash2 size={16} /> Retirer
          </button>
        ) : null}
        {etat === 'sending' ? <span className="text-xs font-semibold text-slate-500" data-testid={`piece-${doc.value}-etat`}>Envoi en cours…</span> : null}
      </div>

      {erreur ? <p className="mt-2 text-xs font-semibold text-red-600" id={erreurId} role="alert">{erreur}</p> : null}
    </div>
  );
}

/**
 * Une déclaration.
 *
 * La case n'est que l'entrée : ce qui engage est la valeur booléenne enregistrée
 * par Laravel, et c'est elle que la complétude de l'étape relit.
 */
function Declaration({ nom, texte, aide, requise, valeur, onChange, onBlur, erreur }: {
  nom: string;
  texte: string;
  aide?: string;
  requise: boolean;
  valeur: boolean;
  onChange: (v: boolean) => void;
  onBlur: () => void;
  erreur?: string;
}) {
  const aideId = `${nom}-aide`;

  return (
    <div className="border-b border-slate-100 py-3 last:border-b-0">
      <label className="flex cursor-pointer items-start gap-3" htmlFor={nom}>
        <input
          id={nom}
          name={nom}
          type="checkbox"
          checked={valeur}
          onChange={(e) => onChange(e.target.checked)}
          onBlur={onBlur}
          aria-describedby={aide ? aideId : undefined}
          className="focus-ring mt-0.5 h-5 w-5 shrink-0 rounded border-slate-400 text-brand-800"
        />
        <span className="text-sm leading-6 text-slate-700">
          {texte}{' '}
          {requise
            ? <><span className="text-red-500" aria-hidden>*</span><span className="sr-only">(obligatoire)</span></>
            : <span className="font-semibold text-slate-400">(facultatif)</span>}
        </span>
      </label>
      {aide ? <p className="ml-8 mt-1 text-xs leading-5 text-slate-500" id={aideId}>{aide}</p> : null}
      {erreur ? <p className="ml-8 mt-1 text-xs font-semibold text-red-600" role="alert">{erreur}</p> : null}
    </div>
  );
}
