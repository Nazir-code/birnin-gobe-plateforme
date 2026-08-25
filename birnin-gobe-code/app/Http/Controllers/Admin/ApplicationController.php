<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Application\ApplicationIndexQuery;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\DocumentType;
use App\Domain\Application\ProjectTheme;
use App\Domain\Application\StoreApplicationDocument;
use App\Domain\Candidate\CandidateType;
use App\Domain\Reference\NigerRegion;
use App\Http\Presenters\AdminApplicationPresenter;
use App\Http\Requests\Admin\ApplicationIndexRequest;
use App\Models\Application;
use App\Models\Campaign;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Consultation des candidatures par l'administration (Admin Phase 3).
 *
 * **Lecture seule, et c'est une règle, pas un état d'avancement.** Aucune route
 * d'écriture n'est déclarée ici : tant que le dossier n'est pas soumis, le
 * candidat en reste propriétaire, et l'administration le consulte sans jamais
 * réécrire une réponse. Le jour où l'admissibilité arrivera, elle écrira son
 * propre verdict à côté du dossier — pas dedans.
 *
 * Ce contrôleur reste mince : la recherche, les filtres, le tri et la
 * pagination sont dans `ApplicationIndexQuery`, la mise en forme dans
 * `AdminApplicationPresenter`, le verdict d'éligibilité dans le moteur du
 * candidat.
 *
 * Aucun événement d'audit n'est écrit à la consultation. Le journal sert à
 * retrouver des décisions ; y verser chaque ouverture de dossier noierait les
 * décisions sous des lignes de lecture. Si le cahier des charges impose plus
 * tard une traçabilité des accès aux données personnelles, ce sera un
 * mécanisme distinct, avec sa propre rétention — pas `AuditEvent`.
 */
final class ApplicationController
{
    public function index(ApplicationIndexRequest $request, AdminApplicationPresenter $presenter): Response
    {
        $page = $request->toIndexQuery()->paginate();

        return Inertia::render('Admin/Applications/Index', [
            'applications' => $page
                ->through(fn (Application $application): array => $presenter->row($application))
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
            'filters' => $request->filters(),
            'hasActiveFilters' => $request->hasActiveFilters(),
            // Distingue « aucune candidature » de « aucun résultat » : ce sont
            // deux écrans différents, et les confondre ferait chercher un bug
            // là où il n'y a qu'un filtre trop étroit.
            'totalWithoutFilters' => ApplicationIndexQuery::total(),
            'options' => $this->options(),
            'resetUrl' => route('admin.applications.index'),
        ]);
    }

    public function show(Application $application, AdminApplicationPresenter $presenter): Response
    {
        // Toutes les sections cette fois, réponses comprises : c'est l'écran qui
        // les détaille. La campagne est celle du dossier — jamais la campagne
        // active — pour que le verdict soit rendu sur les critères sous lesquels
        // le dossier a été déposé.
        $application->load(['candidate:id,name,email', 'campaign', 'sections']);

        return Inertia::render('Admin/Applications/Show', [
            'application' => $presenter->detail($application),
            'backUrl' => route('admin.applications.index'),
        ]);
    }

    /**
     * Téléchargement d'une pièce par l'administration — lecture seule.
     *
     * Le §8.1 demande que le contrôle avant soumission signale les « pièces
     * illisibles » : encore faut-il pouvoir les ouvrir. C'est tout ce que fait
     * cette action. Aucune écriture n'existe côté administration sur les pièces
     * — ni remplacement, ni suppression, ni statut de validation — parce
     * qu'aucun workflow documentaire n'a été arbitré, et qu'inventer le premier
     * geste d'un workflow revient à l'arbitrer.
     *
     * L'accès est porté par le groupe de routes (`auth`, `role:admin`) ; la
     * pièce est désignée par son type, jamais par son emplacement.
     */
    public function downloadDocument(Application $application, string $type): StreamedResponse
    {
        $piece = DocumentType::tryFrom($type);

        if ($piece === null) {
            abort(404);
        }

        $ligne = $application->attachments()->where('type', $piece->value)->first();

        if ($ligne === null) {
            abort(404);
        }

        return StoreApplicationDocument::disk()->download(
            $ligne->storage_key,
            $ligne->original_filename,
            ['Content-Type' => $ligne->mime_type],
        );
    }

    /**
     * Ce que les listes déroulantes ont le droit de proposer.
     *
     * Les statuts sont ceux **présents dans les données**, pas les quinze de
     * l'enum : la soumission n'étant pas encore câblée, tout dossier est un
     * brouillon, et offrir quatorze filtres qui ne rendent jamais rien ferait
     * croire à un écran cassé. La liste s'étoffera d'elle-même quand les
     * statuts apparaîtront.
     *
     * @return array{campaigns: list<array{value: string, label: string}>, statuses: list<array{value: string, label: string}>, types: list<array{value: string, label: string}>, themes: list<array{value: string, label: string}>, regions: list<array{value: string, label: string}>, sorts: list<array{value: string, label: string}>}
     */
    private function options(): array
    {
        $statuts = Application::query()
            ->select('status')
            ->distinct()
            ->pluck('status')
            ->map(static fn (ApplicationStatus $statut): array => [
                'value' => $statut->value,
                'label' => $statut->label(),
            ])
            ->values()
            ->all();

        return [
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
            'statuses' => $statuts,
            'types' => CandidateType::options(),
            'themes' => ProjectTheme::options(),
            'regions' => NigerRegion::options(),
            'sorts' => [
                ['value' => 'recent', 'label' => 'Modifiée récemment'],
                ['value' => 'ancien', 'label' => 'Modifiée il y a longtemps'],
                ['value' => 'nom', 'label' => 'Nom du candidat'],
                ['value' => 'progression', 'label' => 'Progression'],
            ],
        ];
    }
}
