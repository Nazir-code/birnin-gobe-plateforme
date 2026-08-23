import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ListChecks, Save } from 'lucide-react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { ADMIN_LOGOUT, adminNav } from '@/Layouts/adminNav';
import { Button, Card, SectionTitle } from '@/Components/Ui';
import { Champ, Selecteur, type Option } from '@/Components/Champs';

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
 * Les critères d'éligibilité, eux aussi rangés dans `settings`, ont leur propre
 * écran (ADR-010) : ils s'arbitrent à un autre moment que le nom et les dates,
 * et une correction de libellé ne doit pas être l'occasion de les republier.
 * Le lien vers cet écran n'apparaît qu'en modification — les critères se fixent
 * sur une campagne qui existe.
 *
 * Les autres paramètres prévus par le cahier (§9.2) qui n'ont pas encore de
 * consommateur — compte à rebours, période de grâce, domaine, contacts, textes
 * légaux — ne sont volontairement pas exposés : ils vivront dans `settings`
 * quand un écran les lira. Une modification ici ne les efface pas.
 */

type Campagne = {
  id: number | null;
  code: string;
  name: string;
  status: string;
  timezone: string;
  opensAt: string;
  closesAt: string;
  statusOptions: Option[];
  eligibilityUrl: string | null;
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

          {campaign.eligibilityUrl ? (
            <Card className="mt-4 p-5 sm:p-6">
              <SectionTitle title="Éligibilité" />
              <p className="text-xs leading-5 text-slate-600">
                Âge, lien avec le Niger, zones, formes de candidature et taille d’équipe se règlent sur un écran
                distinct : ils ne s’enregistrent pas avec ce formulaire, et ce formulaire ne les efface pas.
              </p>
              <Link
                href={campaign.eligibilityUrl}
                className="focus-ring mt-3 inline-flex min-h-11 items-center gap-2 rounded-xl border border-slate-200 px-4 text-sm font-bold text-brand-800 hover:bg-slate-50"
              >
                <ListChecks size={16} /> Critères d’éligibilité de cette campagne
              </Link>
            </Card>
          ) : null}

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

