<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\CreerUtilisateurInterne;
use App\Domain\Auth\EnvoyerInvitationInterne;
use App\Domain\Auth\ResultatDInvitation;
use App\Domain\Auth\UserRole;
use App\Domain\Campaign\ActiveCampaign;
use App\Domain\Evaluation\AssignApplications;
use App\Domain\Evaluation\AssignmentBoardQuery;
use App\Domain\Evaluation\EvaluationSettings;
use App\Domain\Evaluation\ReleaseAssignment;
use App\Http\Presenters\AdminEvaluatorPresenter;
use App\Http\Requests\Admin\AssignApplicationsRequest;
use App\Http\Requests\Admin\InviterEvaluateurRequest;
use App\Http\Requests\Admin\ReleaseAssignmentRequest;
use App\Models\Application;
use App\Models\Campaign;
use App\Models\EvaluationAssignment;
use App\Models\User;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Affectation des dossiers aux évaluateurs — §11.1.
 *
 * Un seul écran, trois panneaux : les évaluateurs et leur charge, les dossiers
 * à affecter, les affectations en vigueur. Les séparer en trois pages aurait
 * obligé le responsable à mémoriser une charge lue ailleurs pour décider ici —
 * or équilibrer, c'est précisément comparer.
 *
 * Deux routes d'écriture, toutes deux en lot ou unitaires selon leur nature :
 * affecter (un lot vers un évaluateur) et lever (une affectation, avec motif).
 *
 * **La consultation n'est pas journalisée**, comme partout dans
 * l'administration. Les affectations et les levées, elles, le sont — le §13.3
 * demande explicitement que le journal couvre « les affectations ».
 *
 * Ce que cet écran **ne fait pas** : proposer un équilibrage automatique. Le
 * §11.1 prévoit que l'algorithme tienne compte « de l'expertise, de la charge,
 * de la disponibilité et des conflits déclarés ». Seules la charge et les
 * conflits existent en base ; l'expertise et la disponibilité ne sont modélisées
 * nulle part. Un « équilibrage automatique » qui n'équilibrerait que sur la
 * charge porterait un nom mensonger, et le responsable lui ferait confiance pour
 * ce qu'il ne fait pas. L'écran classe donc les dossiers les moins couverts en
 * tête et affiche la charge de chacun : l'arbitrage reste humain, et il est
 * outillé.
 */
final class EvaluatorController
{
    public function index(
        Request $request,
        AdminEvaluatorPresenter $presenter,
        ActiveCampaign $campagneActive,
    ): Response {
        $campagneId = $this->campagneDemandee($request);
        $requete = new AssignmentBoardQuery(
            campaignId: $campagneId,
            search: $this->chaine($request, 'search') ?: null,
            sort: $this->chaine($request, 'sort'),
        );

        $page = $requete->paginate();

        // Sans filtre de campagne, les paramètres lus sont ceux de la campagne
        // active : c'est sous son seuil que le responsable travaille. Avec
        // filtre, ce sont ceux de la campagne visée — un dossier se juge sous
        // les règles de sa propre édition.
        $reglages = EvaluationSettings::fromCampaign(
            $campagneId === null ? $campagneActive->resolve() : Campaign::query()->find($campagneId),
        );

        // Les évaluateurs écartés sont calculés pour la page entière, en une
        // requête : dossier par dossier, l'écran coûterait une requête par ligne.
        $ecartes = AssignmentBoardQuery::evaluateursEcartes(
            array_map(static fn (Application $dossier): int => $dossier->getKey(), $page->items()),
        );

        return Inertia::render('Admin/Evaluators/Index', [
            'applications' => $page
                ->through(fn (Application $dossier): array => $presenter->dossierRow($dossier, $reglages, $ecartes))
                ->items(),
            'pagination' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'previousUrl' => $page->previousPageUrl(),
                'nextUrl' => $page->nextPageUrl(),
            ],
            // Les invitations en attente sont lues en **une** requête, jamais
            // une par évaluateur : la liste en compte autant qu'il y a de
            // personnes, et interroger la table pour chacune serait un N+1 sur
            // un écran que le responsable ouvre à chaque affectation.
            'evaluators' => (function () use ($presenter): array {
                $evaluateurs = AssignmentBoardQuery::evaluateurs();

                $enAttente = DB::table(config('auth.passwords.invitations.table'))
                    ->whereIn('email', $evaluateurs->pluck('email')->all())
                    ->pluck('email')
                    ->flip();

                return $evaluateurs
                    ->map(fn (User $evaluateur): array => $presenter->evaluateurRow(
                        $evaluateur,
                        $enAttente->has($evaluateur->email),
                    ))
                    ->all();
            })(),
            'assignments' => $presenter->affectations(
                EvaluationAssignment::query()
                    ->enVigueur()
                    ->with(['application:id,submission_number,candidate_id', 'application.candidate:id,name', 'evaluator:id,name'])
                    ->when($campagneId !== null, fn ($q) => $q->whereHas(
                        'application',
                        fn ($a) => $a->where('campaign_id', $campagneId),
                    ))
                    ->orderByDesc('assigned_at')
                    ->orderByDesc('id')
                    ->limit(100)
                    ->get(),
            ),
            'settings' => $reglages->toArray(),
            'filters' => [
                'campaign' => $campagneId === null ? '' : (string) $campagneId,
                'search' => $this->chaine($request, 'search'),
                'sort' => in_array($this->chaine($request, 'sort'), AssignmentBoardQuery::SORTS, strict: true)
                    ? $this->chaine($request, 'sort')
                    : 'decouvert',
            ],
            // Distingue « rien à affecter » — l'état normal d'une campagne dont
            // le contrôle d'admissibilité n'a encore rien produit — de « aucun
            // résultat », qui n'est qu'un filtre trop étroit.
            'totalAssignable' => AssignmentBoardQuery::totalAffectables(),
            'options' => [
                'campaigns' => Campaign::query()
                    ->orderByRaw('opens_at IS NULL')
                    ->orderByDesc('opens_at')
                    ->orderByDesc('id')
                    ->get()
                    ->map(static fn (Campaign $campagne): array => [
                        'value' => (string) $campagne->getKey(),
                        'label' => $campagne->name.' ('.$campagne->code.')',
                    ])
                    ->all(),
                'sorts' => AdminEvaluatorPresenter::sortOptions(),
                'releaseReasons' => AdminEvaluatorPresenter::motifsDeLevee(),
            ],
            'assignUrl' => route('admin.evaluators.assignments.store'),
            'resetUrl' => route('admin.evaluators.index'),
        ]);
    }

    /**
     * Crée un évaluateur et lui envoie son invitation — ADR-022.
     *
     * **Le rôle est écrit ici, en dur.** Il ne vient ni du formulaire ni de la
     * requête : cette action ne sait créer qu'un évaluateur, et si l'on veut un
     * jour créer un jury depuis le back-office, ce sera une autre action — pas
     * un paramètre de celle-ci.
     *
     * **L'invitation part après le commit du compte.** Un lien envoyé dans une
     * transaction qui échouerait ensuite pointerait vers un compte inexistant,
     * et le destinataire n'aurait aucun moyen de comprendre. C'est la règle
     * qu'ADR-018 pose pour les notifications.
     *
     * **L'échec d'envoi ne défait pas la création.** Le compte est valide, il
     * est simplement inaccessible tant que personne n'a reçu de lien — l'écran
     * le dit, et une relance depuis « mot de passe oublié » suffit. Faire
     * échouer la création laisserait un administrateur devant une erreur sans
     * savoir si le compte existe.
     */
    public function storeEvaluator(
        InviterEvaluateurRequest $request,
        CreerUtilisateurInterne $creer,
        EnvoyerInvitationInterne $inviter,
    ): RedirectResponse {
        $evaluateur = $creer->handle(
            nom: $request->string('name')->toString(),
            email: $request->string('email')->toString(),
            role: UserRole::EVALUATOR,
            motDePasse: null,
            acteur: $request->user(),
        );

        return $this->retourDInvitation($inviter->handle($evaluateur), $evaluateur);
    }

    /**
     * Relance l'invitation d'un évaluateur qui n'a pas défini son mot de passe.
     *
     * **Nécessaire même avec un vrai transport de courriel.** Une invitation se
     * perd, atterrit en indésirables, ou expire au bout de sept jours. Sans ce
     * geste, le seul recours serait de supprimer le compte pour le recréer —
     * ce qui effacerait ses affectations.
     *
     * Émettre un nouveau jeton **invalide le précédent** : `email` est la clé
     * primaire de la table, la nouvelle ligne remplace l'ancienne. C'est le
     * comportement voulu — deux liens valides pour un même compte
     * multiplieraient les chemins d'entrée sans rien apporter.
     *
     * Refusé si le compte a déjà son mot de passe : relancer alors enverrait à
     * quelqu'un un lien de définition qu'il n'a pas demandé, et le message
     * ressemblerait à une usurpation.
     */
    public function resendInvitation(
        Request $request,
        User $user,
        EnvoyerInvitationInterne $inviter,
    ): RedirectResponse {
        if ($user->role !== UserRole::EVALUATOR) {
            abort(404);
        }

        if (! $this->invitationEnAttente($user)) {
            return back()->withErrors([
                'status' => sprintf('%s a déjà défini son mot de passe : il n’y a pas d’invitation à relancer.', $user->name),
            ]);
        }

        return $this->retourDInvitation($inviter->handle($user), $user);
    }

    /**
     * Le retour commun à la création et à la relance.
     *
     * Le lien n'est montré que si personne ne l'a reçu — voir
     * `ResultatDInvitation::lienAMontrer()`. Avec un transport réel, l'afficher
     * le ferait finir dans une capture d'écran ou un ticket.
     */
    private function retourDInvitation(ResultatDInvitation $resultat, User $destinataire): RedirectResponse
    {
        $retour = back()->with('status', $resultat->message($destinataire->name, $destinataire->email));

        // La clé n'est posée que s'il y a un lien à montrer. Flasher `null`
        // ferait exister une clé vide dans la session, que l'écran devrait
        // alors distinguer de son absence — deux façons de dire « rien ».
        return $resultat->lienAMontrer() ? $retour->with('invitationLink', $resultat->lien) : $retour;
    }

    /** Une invitation non consommée subsiste-t-elle pour ce compte ? */
    private function invitationEnAttente(User $utilisateur): bool
    {
        return DB::table(config('auth.passwords.invitations.table'))
            ->where('email', $utilisateur->email)
            ->exists();
    }

    public function storeAssignments(
        AssignApplicationsRequest $request,
        AssignApplications $affecter,
    ): RedirectResponse {
        $evaluateur = User::query()->findOrFail($request->evaluateurId());

        try {
            $crees = $affecter->handle($request->dossiers(), $evaluateur, $request->user());
        } catch (DomainException $refus) {
            return back()->withErrors(['application_ids' => $this->message($refus)]);
        }

        return back()->with('status', sprintf(
            '%d dossier%s affecté%s à %s.',
            $crees,
            $crees > 1 ? 's' : '',
            $crees > 1 ? 's' : '',
            $evaluateur->name,
        ));
    }

    public function destroyAssignment(
        ReleaseAssignmentRequest $request,
        EvaluationAssignment $assignment,
        ReleaseAssignment $lever,
    ): RedirectResponse {
        try {
            $lever->handle($assignment, $request->motif(), $request->raison(), $request->user());
        } catch (DomainException $refus) {
            return back()->withErrors(['status' => $this->message($refus)]);
        }

        return back()->with('status', 'Affectation levée.');
    }

    /**
     * Un refus du domaine, dit au responsable.
     *
     * Les codes servent aux journaux et aux tests ; l'écran d'un gestionnaire
     * n'est pas une console.
     */
    private function message(DomainException $refus): string
    {
        $code = explode(':', $refus->getMessage(), 2)[0];

        return match ($code) {
            'ASSIGNMENT_NOT_AN_EVALUATOR' => 'Ce compte n’est pas un évaluateur.',
            'ASSIGNMENT_NO_APPLICATION' => 'Choisissez au moins un dossier à affecter.',
            'ASSIGNMENT_APPLICATION_MISSING' => 'Un des dossiers choisis n’existe plus. Rechargez la page.',
            'ASSIGNMENT_NOT_ADMISSIBLE' => 'Un dossier du lot n’est pas recevable : seul un dossier déclaré recevable s’affecte.',
            'ASSIGNMENT_CONFLICT_DECLARED' => 'Un conflit a été déclaré sur ce dossier pour cet évaluateur : il ne peut plus lui être confié.',
            'ASSIGNMENT_ALREADY_ASSIGNED' => 'Ce dossier lui est déjà affecté.',
            'RELEASE_ALREADY_RELEASED' => 'Cette affectation a déjà été levée.',
            'RELEASE_REASON_REQUIRED' => 'Un motif écrit est exigé pour lever une affectation.',
            'RELEASE_NOT_A_RELEASE' => 'Ce motif ne lève pas une affectation.',
            default => 'Cette opération a été refusée : les dossiers ne sont plus dans l’état attendu.',
        };
    }

    private function campagneDemandee(Request $request): ?int
    {
        $valeur = $request->input('campaign');

        return is_numeric($valeur) && (int) $valeur > 0 ? (int) $valeur : null;
    }

    private function chaine(Request $request, string $champ): string
    {
        $valeur = $request->input($champ);

        return is_string($valeur) ? trim($valeur) : '';
    }
}
