import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';
import type { ReactNode } from 'react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { ADMIN_LOGOUT, adminNav } from '@/Layouts/adminNav';
import { Button, Card, SectionTitle } from '@/Components/Ui';

/**
 * Création et modification d'une campagne.
 *
 * Un seul écran pour les deux : les champs sont identiques, et deux formulaires
 * jumeaux finissent toujours par diverger sur une règle.
 *
 * La liste des statuts proposés vient du serveur, qui n'y met que les
 * transitions légales depuis le statut courant. Ce n'est qu'un confort :
 * `SaveCampaign` revalide, un menu réduit n'est jamais une autorisation.
 *
 * Les paramètres prévus par le cahier (§9.2) qui n'ont pas encore de
 * consommateur — compte à rebours, période de grâce, domaine, contacts, textes
 * légaux — ne sont volontairement pas exposés : ils vivront dans `settings`
 * quand un écran les lira. Une modification ici ne les efface pas.
 */
type Champ = { value: string; label: string };

type Campagne = {
  id: number | null;
  code: string;
  name: string;
  status: string;
  timezone: string;
  opensAt: string;
  closesAt: string;
  statusOptions: Champ[];
};

/** Fuseaux réellement plausibles pour cette compétition, plus celui déjà enregistré. */
const FUSEAUX = ['Africa/Niamey', 'Africa/Lagos', 'Africa/Abidjan', 'UTC', 'Europe/Paris'];

export default function CampaignForm({
  campaign,
  submitUrl,
  method,
}: {
  campaign: Campagne;
  submitUrl: string;
  method: 'post' | 'put';
}) {
  const creation = campaign.id === null;

  const { data, setData, post, put, processing, errors } = useForm({
    code: campaign.code,
    name: campaign.name,
    status: campaign.status,
    timezone: campaign.timezone,
    opens_at: campaign.opensAt,
    closes_at: campaign.closesAt,
  });

  const fuseaux = FUSEAUX.includes(campaign.timezone) ? FUSEAUX : [campaign.timezone, ...FUSEAUX];

  return (
    <DarkSidebarLayout
      items={adminNav}
      active="Campagnes"
      title={creation ? 'Nouvelle campagne' : `Modifier « ${campaign.name} »`}
      subtitle="Éditions de la compétition — PIDUREM / ANSI"
      logoutHref={ADMIN_LOGOUT}
    >
      <Head title={`${creation ? 'Nouvelle campagne' : 'Modifier une campagne'} — BIRNIN GOBE`} />
      <div className="mx-auto max-w-[820px] p-5 sm:p-7">
        <Link href="/admin/campaigns" className="focus-ring inline-flex min-h-9 items-center gap-1.5 rounded-lg text-sm font-bold text-brand-800 hover:underline">
          <ArrowLeft size={16} /> Retour aux campagnes
        </Link>

        <form
          className="mt-4"
          onSubmit={(e) => {
            e.preventDefault();
            (method === 'put' ? put : post)(submitUrl, { preserveScroll: true });
          }}
        >
          <Card className="p-5 sm:p-6">
            <SectionTitle title="Identité" />
            <div className="grid gap-4 sm:grid-cols-2">
              <Champ
                id="name"
                label="Nom de la campagne"
                value={data.name}
                onChange={(v) => setData('name', v)}
                erreur={errors.name}
                aide="Tel qu’il apparaîtra pour les candidats."
                autoFocus
              />
              <Champ
                id="code"
                label="Code de l’édition"
                value={data.code}
                onChange={(v) => setData('code', v.toUpperCase())}
                erreur={errors.code}
                aide="Majuscules, chiffres et tirets. Sert de référence externe, par exemple BG-2026."
                mono
              />
            </div>
          </Card>

          <Card className="mt-4 p-5 sm:p-6">
            <SectionTitle title="Calendrier" />
            <div className="grid gap-4 sm:grid-cols-2">
              <Selecteur
                id="timezone"
                label="Fuseau horaire"
                value={data.timezone}
                onChange={(v) => setData('timezone', v)}
                erreur={errors.timezone}
                options={fuseaux.map((f) => ({ value: f, label: f }))}
                aide="Les heures ci-dessous sont interprétées dans ce fuseau."
              />
              <Selecteur
                id="status"
                label="Statut"
                value={data.status}
                onChange={(v) => setData('status', v)}
                erreur={errors.status}
                options={campaign.statusOptions}
                aide="Une seule campagne peut être ouverte à la fois."
              />
              <Champ
                id="opens_at"
                type="datetime-local"
                label="Ouverture des candidatures"
                value={data.opens_at}
                onChange={(v) => setData('opens_at', v)}
                erreur={errors.opens_at}
                required={false}
              />
              <Champ
                id="closes_at"
                type="datetime-local"
                label="Clôture des candidatures"
                value={data.closes_at}
                onChange={(v) => setData('closes_at', v)}
                erreur={errors.closes_at}
                required={false}
                aide="Laisser vide pour une campagne sans date de fin."
              />
            </div>

            <p className="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-3 text-xs leading-5 text-slate-600">
              Une campagne ne reçoit des dossiers que si elle est <strong>ouverte</strong> et que la date du jour tombe
              dans sa fenêtre. Déclarer une campagne ouverte ne suffit pas si son ouverture est encore à venir.
            </p>
          </Card>

          <div className="mt-4 flex flex-wrap items-center gap-3">
            <Button type="submit" disabled={processing}>
              <Save size={17} /> {processing ? 'Enregistrement…' : 'Enregistrer'}
            </Button>
            <Link href="/admin/campaigns" className="focus-ring min-h-11 rounded-xl px-4 py-3 text-sm font-bold text-slate-600 hover:bg-slate-100">
              Annuler
            </Link>
          </div>
        </form>
      </div>
    </DarkSidebarLayout>
  );
}

function Champ({
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

function Selecteur({
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
  options: Champ[];
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
