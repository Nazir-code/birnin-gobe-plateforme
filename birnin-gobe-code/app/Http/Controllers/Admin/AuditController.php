<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditIndexQuery;
use App\Domain\Audit\AuditTargetType;
use App\Http\Presenters\AdminAuditPresenter;
use App\Http\Requests\Admin\AuditIndexRequest;
use App\Models\AuditEvent;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le journal d'audit, consulté par l'administration.
 *
 * **Lecture seule, et définitivement.** Aucune route d'écriture n'est déclarée
 * ici, et il ne faudra jamais en ajouter : un journal qu'on peut corriger ne
 * prouve plus rien. Ni modification, ni suppression, ni « archivage » — la
 * rétention, le jour où elle sera décidée, sera une tâche d'exploitation avec
 * sa propre trace, pas un bouton sur cet écran.
 *
 * **La consultation n'est pas journalisée.** C'est la même règle que sur la
 * liste des candidatures, et elle compte doublement ici : consigner chaque
 * ouverture du journal dans le journal le ferait grossir de sa propre lecture,
 * et noierait les décisions sous les consultations. Si le cahier des charges
 * impose plus tard une traçabilité des accès aux données personnelles, ce sera
 * un mécanisme distinct, avec sa propre rétention.
 *
 * Le contrôleur reste mince : filtres et tri dans `AuditIndexQuery`, réduction
 * des paramètres dans `AuditIndexRequest`, mise en forme dans
 * `AdminAuditPresenter`.
 */
final class AuditController
{
    public function index(AuditIndexRequest $request): Response
    {
        $page = $request->toIndexQuery()->paginate();

        // Le présentateur résout les acteurs de la page en une seule requête.
        // `actor_id` n'ayant pas de clé étrangère, aucune relation ne peut le
        // faire à sa place, et une résolution ligne par ligne coûterait trente
        // requêtes par page.
        $presenter = AdminAuditPresenter::pour($page->items());

        return Inertia::render('Admin/Audit/Index', [
            'events' => $page
                ->through(fn (AuditEvent $evenement): array => $presenter->row($evenement))
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
            // Distingue « le journal est vide » de « aucun résultat » : ce sont
            // deux écrans, et les confondre ferait chercher une panne là où il
            // n'y a qu'un filtre trop étroit.
            'totalWithoutFilters' => AuditIndexQuery::total(),
            'options' => [
                'actions' => AuditAction::options(),
                'targets' => AuditTargetType::options(),
                'actors' => AuditIndexQuery::actorOptions(),
                'sorts' => [
                    ['value' => 'recent', 'label' => 'Du plus récent au plus ancien'],
                    ['value' => 'ancien', 'label' => 'Du plus ancien au plus récent'],
                ],
            ],
            'resetUrl' => route('admin.audit.index'),
        ]);
    }
}
