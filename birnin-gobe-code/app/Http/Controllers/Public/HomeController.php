<?php

namespace App\Http\Controllers\Public;

use App\Domain\Campaign\ActiveCampaign;
use App\Models\Campaign;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page d'accueil publique.
 *
 * La route rendait `Public/Home` sans rien lui passer, et l'écran comblait le
 * vide avec `resources/js/data/demo.ts` : un nom d'édition, une date de clôture
 * au 30 juin 2026 et un décompte figé à 24 j 12 h 45 m 30 s. Des valeurs de
 * maquette, présentées au public comme des informations officielles.
 *
 * C'est le genre d'erreur qui ne se rattrape pas : un candidat qui lit une date
 * limite fausse sur le site officiel et dépose après la vraie clôture n'a rien
 * fait de mal. La page lit donc désormais la campagne, ou dit qu'il n'y en a pas.
 *
 * Ce qui est envoyé à l'écran se limite à ce qu'une page publique doit savoir :
 * le nom, le code, la clôture et le fuseau dans lequel elle s'entend. Ni
 * paramètres d'éligibilité, ni statistiques, ni compteurs de dossiers — la page
 * d'accueil n'est pas un tableau de bord, et rien de ce qui n'est pas mesuré ne
 * doit y être affiché.
 *
 * `null` est une réponse à part entière : hors période de dépôt, l'écran
 * l'annonce et ferme son appel à candidature plutôt que d'inventer un décompte.
 */
final class HomeController
{
    public function __invoke(ActiveCampaign $campagnes): Response
    {
        $active = $campagnes->resolve();

        return Inertia::render('Public/Home', [
            'campaign' => $active === null ? null : $this->campagne($active),
        ]);
    }

    /**
     * @return array{name: string, code: string, closesAt: string|null, timezone: string}
     */
    private function campagne(Campaign $campagne): array
    {
        return [
            'name' => $campagne->name,
            'code' => $campagne->code,
            // ISO 8601 avec décalage : le navigateur en tire l'instant exact, et
            // le décompte reste juste où que se trouve le visiteur. Le fuseau
            // part à côté pour que la date affichée reste celle annoncée par le
            // concours, et non celle du téléphone de qui la lit.
            'closesAt' => $campagne->closes_at?->toIso8601String(),
            'timezone' => $campagne->timezone ?: config('app.timezone'),
        ];
    }
}
