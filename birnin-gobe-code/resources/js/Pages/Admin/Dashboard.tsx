import { Head } from '@inertiajs/react';
import { AlertTriangle, BarChart3, Bell, ClipboardCheck, FileStack, FolderKanban, Gauge, Layers3, Settings, ShieldCheck, UsersRound } from 'lucide-react';
import { DarkSidebarLayout, type DarkNavItem } from '@/Layouts/DarkSidebarLayout';
import { Card, SectionTitle } from '@/Components/Ui';
import { StatCard } from '@/Components/StatCard';
import { Reveal } from '@/Components/Reveal';
import { NigerRegionsMap } from '@/Components/NigerRegionsMap';
import { useAuthUser } from '@/hooks/useAuth';

/**
 * Tableau de bord de l'administration.
 *
 * L'identité affichée est celle de l'utilisateur authentifié, partagée par
 * Inertia — plus de nom de démonstration en dur.
 *
 * Les indicateurs, eux, restent vides et le disent : ils dépendent des modèles
 * `Campaign` et `Application`, qui ne sont pas encore branchés (Admin Phase 2
 * et 3). Afficher les chiffres de la maquette sur un écran authentifié
 * reviendrait à présenter des données inventées comme des données de
 * production ; un état d'attente explicite est la seule chose honnête à
 * montrer tant que la campagne n'est pas configurée.
 */
const nav: DarkNavItem[] = [
  { icon: Gauge, label: 'Tableau de bord', href: '/admin/dashboard' },
  { icon: Layers3, label: 'Files de vérification' },
  { icon: FolderKanban, label: 'Dossiers' },
  { icon: UsersRound, label: 'Évaluateurs' },
  { icon: BarChart3, label: 'Indicateurs' },
  { icon: Bell, label: 'Alertes' },
  { icon: Settings, label: 'Paramètres' },
  { icon: FileStack, label: 'Journal d’audit' },
];

const EN_ATTENTE = 'Données disponibles après configuration de la campagne.';

export default function AdminDashboard() {
  const user = useAuthUser();

  return (
    <DarkSidebarLayout
      items={nav}
      active="Tableau de bord"
      title="Back-office administratif"
      subtitle="Présélection & admissibilité — PIDUREM / ANSI"
      logoutHref="/admin/logout"
    >
      <Head title="Back-office administratif — BIRNIN GOBE" />
      <div className="mx-auto max-w-[1650px] p-5 sm:p-7">
        <Reveal>
          <Card className="p-5">
            <h2 className="text-lg font-extrabold text-brand-950">
              Bonjour {user?.name ?? ''}
            </h2>
            <p className="mt-1.5 text-sm leading-6 text-slate-600">
              Aucune campagne n’est encore configurée. Les dossiers, les files de vérification et
              les indicateurs apparaîtront ici dès qu’une campagne sera ouverte.
            </p>
          </Card>
        </Reveal>

        {/* Les compteurs existent, leur valeur est explicitement inconnue :
            un tiret se lit sans ambiguïté, là où un « 0 » affirmerait qu'on a
            compté et trouvé zéro. */}
        <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
          <StatCard icon={FolderKanban} value="—" label="Dossiers soumis" hint="Aucune campagne" tone="blue" />
          <StatCard icon={Gauge} value="—" label="En présélection" hint="Aucune campagne" tone="gold" />
          <StatCard icon={ClipboardCheck} value="—" label="Admissibles" hint="Aucune campagne" tone="blue" />
          <StatCard icon={ShieldCheck} value="—" label="Non admissibles" hint="Aucune campagne" tone="green" />
          <StatCard icon={AlertTriangle} value="—" label="Alertes actives" hint="Aucune campagne" tone="red" />
        </div>

        <div className="mt-4 grid gap-4 xl:grid-cols-[1.1fr_1.1fr_1.15fr]">
          <Reveal delay={100}>
            <Card className="p-5">
              <SectionTitle title="Files de vérification" />
              <EtatVide texte={EN_ATTENTE} />
            </Card>
          </Reveal>
          <Reveal delay={150}>
            <Card className="p-5">
              <SectionTitle title="Charge des évaluateurs" />
              <EtatVide texte="Aucun évaluateur n’est affecté. La gestion des évaluateurs viendra avec les campagnes." />
            </Card>
          </Reveal>
          <Reveal delay={200}>
            <Card className="p-5">
              <SectionTitle title="Répartition géographique" />
              {/* Sans `intensities`, la carte se rend en gris neutre : la
                  géographie est réelle, la donnée est absente et se voit. */}
              <NigerRegionsMap
                label="Carte du Niger : aucune donnée de répartition disponible"
                className="mx-auto mt-3 block h-auto w-full max-w-[260px]"
              />
              <p className="mt-3 text-center text-[11px] text-slate-400">Aucun dossier à répartir.</p>
            </Card>
          </Reveal>
        </div>

        <div className="mt-4 grid gap-4 xl:grid-cols-3">
          <Reveal delay={60}>
            <Card className="p-5">
              <SectionTitle title="Indicateurs thématiques" />
              <EtatVide texte={EN_ATTENTE} />
            </Card>
          </Reveal>
          <Reveal delay={120}>
            <Card className="p-5">
              <SectionTitle title="Alertes récentes" />
              <EtatVide texte="Aucune alerte." />
            </Card>
          </Reveal>
          <Reveal delay={180}>
            <Card className="p-5">
              <SectionTitle title="Journal d’audit" />
              <EtatVide texte="Aucun événement enregistré sur cet écran pour le moment." />
            </Card>
          </Reveal>
        </div>
      </div>
    </DarkSidebarLayout>
  );
}

function EtatVide({ texte }: { texte: string }) {
  return (
    <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50/60 px-4 py-8 text-center text-xs leading-5 text-slate-500">
      {texte}
    </div>
  );
}
