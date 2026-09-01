<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Campaign\ActiveCampaign;
use App\Domain\Reporting\ComputeIndicators;
use App\Domain\Reporting\Indicator;
use App\Domain\Reporting\IndicatorBreakdown;
use App\Domain\Reporting\IndicatorFamily;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tableaux de bord d'indicateurs — §13.1 et §13.4.
 *
 * Deux routes : l'écran, et l'export CSV du §13.2.
 *
 * **Chaque indicateur voyage avec sa fiche.** Le §9.1 demande des indicateurs
 * « accompagnés de leur définition », le §13.4 exige en plus une formule, une
 * source, une fréquence et un niveau d'accès. Ces cinq champs sont portés par
 * l'objet `Indicator` lui-même, donc présents à l'écran comme dans l'export :
 * un chiffre exporté sans sa définition finit dans une note de synthèse où il
 * veut dire autre chose.
 *
 * **Les familles non mesurées restent affichées.** Mobilisation, Finale et
 * Qualité de service n'ont pas de source aujourd'hui ; elles apparaissent
 * vides, avec la raison. Les retirer ferait croire à une vue complète.
 *
 * **La consultation n'est pas journalisée**, comme le reste de
 * l'administration. L'export, lui, mériterait de l'être — le §13.3 cite
 * explicitement les exports parmi les actions à tracer — mais il faudrait alors
 * tracer aussi ce qui a été exporté et pour qui, c'est-à-dire arbitrer une
 * rétention. Ce n'est pas fait ici, et c'est signalé plutôt que bâclé.
 */
final class IndicatorController
{
    public function index(Request $request, ComputeIndicators $moteur, ActiveCampaign $campagneActive): Response
    {
        $campagne = $this->campagne($request, $campagneActive);

        return Inertia::render('Admin/Indicators/Index', [
            'indicators' => array_map(
                static fn (Indicator $indicateur): array => $indicateur->toArray(),
                $moteur->indicateurs($campagne),
            ),
            'breakdowns' => array_map(
                static fn (IndicatorBreakdown $ventilation): array => $ventilation->toArray(),
                $moteur->ventilations($campagne),
            ),
            'families' => array_map(
                static fn (IndicatorFamily $famille): array => [
                    'value' => $famille->value,
                    'label' => $famille->label(),
                    'available' => $famille->disponible(),
                    'reason' => $famille->raisonDIndisponibilite(),
                ],
                IndicatorFamily::cases(),
            ),
            'campaign' => $campagne === null ? null : [
                'id' => $campagne->getKey(),
                'name' => $campagne->name,
                'code' => $campagne->code,
            ],
            'filters' => ['campaign' => $campagne === null ? '' : (string) $campagne->getKey()],
            'options' => [
                'campaigns' => Campaign::query()
                    ->orderByRaw('opens_at IS NULL')
                    ->orderByDesc('opens_at')
                    ->orderByDesc('id')
                    ->get()
                    ->map(static fn (Campaign $c): array => [
                        'value' => (string) $c->getKey(),
                        'label' => $c->name.' ('.$c->code.')',
                    ])
                    ->all(),
            ],
            'exportUrl' => route('admin.indicators.export', $request->query()),
            'maskingThreshold' => IndicatorBreakdown::SEUIL_PETITS_EFFECTIFS,
        ]);
    }

    /**
     * Export CSV — §13.2.
     *
     * CSV et non XLSX : le §13.2 demande « XLSX/CSV », et produire un vrai XLSX
     * supposerait une dépendance de plus pour un format que le tableur ouvrira
     * de toute façon. Le séparateur est le point-virgule et le fichier porte un
     * BOM UTF-8, parce que c'est ce qu'Excel en configuration française attend :
     * un export que le destinataire doit réparer à la main n'est pas un export.
     *
     * **Les valeurs masquées le restent.** Un effectif sous le seuil du §13.4
     * sort du serveur comme « masqué », jamais comme un nombre : l'export est
     * précisément le chemin par lequel une donnée ré-identifiante quitterait
     * l'application.
     */
    public function export(Request $request, ComputeIndicators $moteur, ActiveCampaign $campagneActive): StreamedResponse
    {
        $campagne = $this->campagne($request, $campagneActive);

        $lignes = [
            ['Famille', 'Indicateur', 'Modalité', 'Valeur', 'Unité', 'Définition', 'Formule', 'Source', 'Rafraîchissement', 'Accès'],
        ];

        foreach ($moteur->indicateurs($campagne) as $indicateur) {
            $lignes[] = [
                $indicateur->family->label(),
                $indicateur->label,
                '',
                $indicateur->value === null ? 'non mesuré' : (string) $indicateur->value,
                $indicateur->unit ?? '',
                $indicateur->definition,
                $indicateur->formula,
                $indicateur->source,
                $indicateur->refresh->label(),
                $indicateur->access->label(),
            ];
        }

        foreach ($moteur->ventilations($campagne) as $ventilation) {
            foreach ($ventilation->rows as $ligne) {
                $lignes[] = [
                    $ventilation->family->label(),
                    $ventilation->label,
                    $ligne['label'],
                    $ligne['masked'] ? 'masqué (petit effectif)' : (string) $ligne['value'],
                    '',
                    $ventilation->definition,
                    $ventilation->formula,
                    $ventilation->source,
                    $ventilation->refresh->label(),
                    $ventilation->access->label(),
                ];
            }
        }

        $nom = sprintf(
            'indicateurs-%s-%s.csv',
            $campagne?->code ?? 'toutes-campagnes',
            now()->format('Y-m-d'),
        );

        return response()->streamDownload(function () use ($lignes): void {
            $sortie = fopen('php://output', 'w');

            // BOM UTF-8 : sans lui, Excel lit « Éligibilité » en « Ã‰ligibilitÃ© ».
            fwrite($sortie, "\xEF\xBB\xBF");

            foreach ($lignes as $ligne) {
                fputcsv($sortie, $ligne, ';', '"', '\\');
            }

            fclose($sortie);
        }, $nom, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * La campagne du tableau de bord.
     *
     * Par défaut la campagne active : c'est celle qu'on pilote. Un chiffre
     * toutes éditions confondues répond à une autre question, et l'écran ne
     * doit pas y répondre sans qu'on l'ait demandé — d'où `campaign=0`, qui est
     * le choix explicite de tout cumuler.
     */
    private function campagne(Request $request, ActiveCampaign $campagneActive): ?Campaign
    {
        $demande = $request->input('campaign');

        if ($demande === null || $demande === '') {
            return $campagneActive->resolve();
        }

        if (! is_numeric($demande) || (int) $demande <= 0) {
            return null;
        }

        return Campaign::query()->find((int) $demande);
    }
}
