import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, CircleDashed, Info, Save } from 'lucide-react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { ADMIN_LOGOUT, adminNav } from '@/Layouts/adminNav';
import { Button, Card, SectionTitle } from '@/Components/Ui';
import { CasesACocher, Champ, Selecteur, type Option } from '@/Components/Champs';

/**
 * Critères d'éligibilité d'une campagne (ADR-009).
 *
 * Cet écran est la contrepartie administrable de l'auto-test candidat : ce qui
 * est saisi ici décide, pour tous les dossiers de cette édition, de ce que le
 * serveur répond à l'étape 1.
 *
 * Le parti pris de l'écran tient en une phrase : **un critère laissé vide n'est
 * pas un critère permissif**. Il n'est pas publié, donc il n'écarte personne et
 * ne rassure personne — le candidat lit « sous réserve ». C'est le principe
 * d'ADR-007, et l'écran le répète à chaque bloc plutôt que de laisser croire
 * qu'un champ vide vaut « pas de limite ».
 *
 * Le lien avec le Niger est le seul champ à trois états, et c'est délibéré :
 * « non renseigné » (la campagne ne s'est pas prononcée) ne dit pas la même
 * chose que « aucune condition », qui est une décision du comité de pilotage.
 * Une case à cocher confondrait les deux.
 *
 * Le panneau « État actuel » montre ce que le moteur retient après le dernier
 * enregistrement : il vient du serveur, jamais d'un calcul local — sans quoi
 * l'administration lirait un état que les candidats ne voient pas.
 */
type Critere = { rule: string; label: string; configured: boolean; summary: string };

type Props = {
  campaign: { id: number; code: string; name: string; statusLabel: string; timezone: string };
  form: {
    age_min: string;
    age_max: string;
    age_reference_date: string;
    requires_niger_link: string;
    regions: string[];
    candidate_types: string[];
    team_size_min: string;
    team_size_max: string;
  };
  regionOptions: Option[];
  candidateTypeOptions: Option[];
  criteria: Critere[];
  defaultReferenceDate: string | null;
  submitUrl: string;
};

/** Les trois états du lien avec le Niger, dans l'ordre où on les lit. */
const LIEN_NIGER: Option[] = [
  { value: '', label: 'Non renseigné — la campagne ne s’est pas prononcée' },
  { value: 'false', label: 'Aucune condition de nationalité ni de résidence' },
  { value: 'true', label: 'Nationalité nigérienne ou résidence au Niger exigée' },
];

function dateLisible(iso: string) {
  return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long' }).format(new Date(`${iso}T00:00:00`));
}

export default function CampaignEligibility({
  campaign,
  form,
  regionOptions,
  candidateTypeOptions,
  criteria,
  defaultReferenceDate,
  submitUrl,
}: Props) {
  const flash = usePage<{ flash?: { status: string | null } }>().props.flash?.status ?? null;
  const { data, setData, put, processing, errors } = useForm(form);

  const publies = criteria.filter((c) => c.configured).length;

  return (
    <DarkSidebarLayout
      items={adminNav}
      active="Campagnes"
      title={`Éligibilité — ${campaign.name}`}
      subtitle="Critères applicables aux dossiers de cette édition"
      logoutHref={ADMIN_LOGOUT}
    >
      <Head title={`Éligibilité de ${campaign.name} — BIRNIN GOBE`} />
      <div className="mx-auto max-w-[900px] p-5 sm:p-7">
        <Link
          href="/admin/campaigns"
          className="focus-ring inline-flex min-h-9 items-center gap-1.5 rounded-lg text-sm font-bold text-brand-800 hover:underline"
        >
          <ArrowLeft size={16} /> Retour aux campagnes
        </Link>

        {flash ? (
          <div
            role="status"
            className="mt-4 flex items-center gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800"
          >
            <CheckCircle2 size={17} className="shrink-0" /> {flash}
          </div>
        ) : null}

        <Card className="mt-4 p-5 sm:p-6">
          <SectionTitle
            title="État actuel"
            aside={
              <span className="text-xs font-bold text-slate-500" data-testid="criteres-publies">
                {publies} critère{publies > 1 ? 's' : ''} publié{publies > 1 ? 's' : ''} sur {criteria.length}
              </span>
            }
          />
          <ul className="grid gap-2">
            {criteria.map((critere) => (
              <li
                key={critere.rule}
                data-testid={`critere-${critere.rule}`}
                data-configure={critere.configured ? 'oui' : 'non'}
                className={`flex items-start gap-2.5 rounded-xl border px-3.5 py-2.5 text-sm ${critere.configured ? 'border-emerald-200 bg-emerald-50/60' : 'border-slate-200 bg-slate-50/60'}`}
              >
                {critere.configured ? (
                  <CheckCircle2 size={16} className="mt-0.5 shrink-0 text-emerald-600" aria-hidden />
                ) : (
                  <CircleDashed size={16} className="mt-0.5 shrink-0 text-slate-400" aria-hidden />
                )}
                <span>
                  <strong className="font-bold text-slate-800">{critere.label}</strong>
                  <span className="sr-only">{critere.configured ? ' : publié.' : ' : non publié.'}</span>
                  <span className="ml-1.5 text-slate-600">{critere.summary}</span>
                </span>
              </li>
            ))}
          </ul>

          <p className="mt-4 flex items-start gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-xs leading-5 text-slate-600">
            <Info size={15} className="mt-0.5 shrink-0 text-slate-400" aria-hidden />
            <span>
              Tant qu’un critère n’est pas publié, le candidat n’est jamais déclaré définitivement éligible : son
              auto-test répond « sous réserve » et lui laisse poursuivre son dossier. Un critère vide n’est donc pas un
              critère permissif — c’est un critère que le comité de pilotage n’a pas encore arrêté.
            </span>
          </p>
        </Card>

        <form
          className="mt-4"
          onSubmit={(e) => {
            e.preventDefault();
            put(submitUrl, { preserveScroll: true });
          }}
        >
          <Card className="p-5 sm:p-6">
            <SectionTitle title="Âge" />
            <div className="grid gap-4 sm:grid-cols-3">
              <Champ
                id="age_min"
                type="number"
                inputMode="numeric"
                min={0}
                max={120}
                label="Âge minimum"
                value={data.age_min}
                onChange={(v) => setData('age_min', v)}
                erreur={errors.age_min}
                required={false}
                aide="Laisser vide pour ne pas fixer de plancher."
              />
              <Champ
                id="age_max"
                type="number"
                inputMode="numeric"
                min={0}
                max={120}
                label="Âge maximum"
                value={data.age_max}
                onChange={(v) => setData('age_max', v)}
                erreur={errors.age_max}
                required={false}
                aide="Laisser vide pour ne pas fixer de plafond."
              />
              <Champ
                id="age_reference_date"
                type="date"
                label="Date de référence"
                value={data.age_reference_date}
                onChange={(v) => setData('age_reference_date', v)}
                erreur={errors.age_reference_date}
                required={false}
                aide={
                  defaultReferenceDate
                    ? `Par défaut, la clôture de la campagne : ${dateLisible(defaultReferenceDate)}.`
                    : 'Par défaut, la clôture de la campagne — non fixée pour l’instant.'
                }
              />
            </div>
            <p className="mt-3 text-xs leading-5 text-slate-500">
              L’âge est calculé à la date de référence, et non au jour où le candidat remplit son dossier : deux
              candidats nés le même jour obtiennent ainsi le même résultat, quel que soit le moment de leur dépôt.
            </p>
          </Card>

          <Card className="mt-4 p-5 sm:p-6">
            <SectionTitle title="Lien avec le Niger" />
            <Selecteur
              id="requires_niger_link"
              label="Condition de nationalité ou de résidence"
              value={data.requires_niger_link}
              onChange={(v) => setData('requires_niger_link', v)}
              erreur={errors.requires_niger_link}
              options={LIEN_NIGER}
              aide="« Aucune condition » est une décision qui rassure le candidat ; « non renseigné » laisse son résultat sous réserve."
            />
            <p className="mt-3 text-xs leading-5 text-slate-500">
              Lorsque la condition s’applique, la nationalité <strong>ou</strong> la résidence suffit : exiger les deux
              écarterait aussi bien la diaspora nigérienne que les résidents étrangers.
            </p>
          </Card>

          <Card className="mt-4 p-5 sm:p-6">
            <SectionTitle title="Zones d’intervention" />
            <CasesACocher
              id="regions"
              legende="Régions ouvertes à cette campagne"
              options={regionOptions}
              valeurs={data.regions}
              onChange={(v) => setData('regions', v)}
              erreur={errors.regions ?? errors['regions.0']}
              aide="Aucune case cochée : la liste des zones n’est pas publiée. Cocher les huit régions est une décision différente — celle d’ouvrir tout le pays."
            />
          </Card>

          <Card className="mt-4 p-5 sm:p-6">
            <SectionTitle title="Formes de candidature" />
            <CasesACocher
              id="candidate_types"
              legende="Types de candidat acceptés"
              options={candidateTypeOptions}
              valeurs={data.candidate_types}
              onChange={(v) => setData('candidate_types', v)}
              erreur={errors.candidate_types ?? errors['candidate_types.0']}
              aide="Aucune case cochée : les formes acceptées ne sont pas publiées."
            />
          </Card>

          <Card className="mt-4 p-5 sm:p-6">
            <SectionTitle title="Taille de l’équipe" />
            <div className="grid gap-4 sm:grid-cols-2">
              <Champ
                id="team_size_min"
                type="number"
                inputMode="numeric"
                min={1}
                max={1000}
                label="Effectif minimum"
                value={data.team_size_min}
                onChange={(v) => setData('team_size_min', v)}
                erreur={errors.team_size_min}
                required={false}
                aide="Laisser vide pour ne pas fixer de plancher."
              />
              <Champ
                id="team_size_max"
                type="number"
                inputMode="numeric"
                min={1}
                max={1000}
                label="Effectif maximum"
                value={data.team_size_max}
                onChange={(v) => setData('team_size_max', v)}
                erreur={errors.team_size_max}
                required={false}
                aide="Laisser vide pour ne pas fixer de plafond."
              />
            </div>
            <p className="mt-3 text-xs leading-5 text-slate-500">
              Ne s’applique qu’aux candidatures collectives — équipe et startup. Une candidature individuelle n’a pas
              d’effectif à déclarer, quelle que soit la valeur saisie ici.
            </p>
          </Card>

          <div className="mt-4 flex flex-wrap items-center gap-3">
            <Button type="submit" disabled={processing}>
              <Save size={17} /> {processing ? 'Enregistrement…' : 'Enregistrer'}
            </Button>
            <Link
              href="/admin/campaigns"
              className="focus-ring min-h-11 rounded-xl px-4 py-3 text-sm font-bold text-slate-600 hover:bg-slate-100"
            >
              Annuler
            </Link>
          </div>

          <p className="mt-4 text-[11px] leading-5 text-slate-400">
            Ces critères s’appliquent aux dossiers de l’édition {campaign.code} et à eux seuls. Les modifier change le
            résultat affiché aux candidats de cette campagne, y compris pour les dossiers déjà commencés : chaque
            enregistrement est inscrit au journal d’audit.
          </p>
        </form>
      </div>
    </DarkSidebarLayout>
  );
}
