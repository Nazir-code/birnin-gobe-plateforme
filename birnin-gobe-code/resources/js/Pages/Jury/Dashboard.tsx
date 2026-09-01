import { Head } from '@inertiajs/react';
import { Construction } from 'lucide-react';
import { DarkSidebarLayout } from '@/Layouts/DarkSidebarLayout';
import { Card, SectionTitle } from '@/Components/Ui';

/**
 * L'espace jury — §12, pas encore construit.
 *
 * **Cet écran existe pour ne pas mentir.** La route `/jury/dashboard` rendait
 * jusqu'ici `Evaluator/Assignments`, qui affichait des données de
 * démonstration : un juré connecté y voyait cinq dossiers inventés et une
 * grille de notation qui n'enregistrait rien. Depuis qu'ADR-015 a branché
 * l'espace évaluateur sur les vraies affectations, cet emprunt ne tient plus —
 * la page attend des propriétés que la route jury ne lui donne pas.
 *
 * La réponse n'est pas de rendre la page évaluateur tolérante à l'absence de
 * données : ce serait montrer au jury une file vide en lui laissant croire
 * qu'il n'a rien à faire. C'est de dire que l'écran n'existe pas.
 *
 * La route, elle, reste : elle porte la règle d'accès du §12, et
 * `AuthentificationCandidatTest` la vérifie. Une règle d'accès posée tôt est
 * testable ; posée en même temps que l'écran, elle s'oublie.
 *
 * La navigation est vide, et non copiée de l'espace évaluateur : la même règle
 * que pour `adminNav` et `evaluatorNav` — une entrée sans écran ne doit pas
 * être cliquable.
 */
export default function JuryDashboard() {
  return (
    <DarkSidebarLayout
      items={[]}
      active=""
      title="Espace jury"
      subtitle="Sélection finale — §12"
      logoutHref="/logout"
    >
      <Head title="Espace jury — BIRNIN GOBE" />

      <div className="mx-auto max-w-[720px] p-5 sm:p-7">
        <Card className="p-6">
          <SectionTitle eyebrow="§12 — Sélection finale" title="Cet espace n’est pas encore ouvert" />

          <p className="mt-3 flex items-start gap-3 text-sm leading-6 text-slate-600">
            <Construction className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" aria-hidden />
            <span>
              La finale — convocation, créneaux, ordre de passage, grille de sélection, délibération et
              procès-verbal — reste à construire. Votre accès est configuré : vous verrez cet espace dès qu’il
              existera, sans nouvelle démarche.
            </span>
          </p>

          <p className="mt-4 text-sm leading-6 text-slate-600">
            La présélection, elle, est en cours dans l’espace évaluateur. Les dossiers qui vous seront soumis en
            sortiront.
          </p>
        </Card>
      </div>
    </DarkSidebarLayout>
  );
}
