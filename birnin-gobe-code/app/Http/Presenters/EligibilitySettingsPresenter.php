<?php

namespace App\Http\Presenters;

use App\Domain\Candidate\CandidateType;
use App\Domain\Eligibility\EligibilityRule;
use App\Domain\Eligibility\EligibilitySettings;
use App\Domain\Reference\NigerRegion;
use App\Models\Campaign;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Met les paramètres d'éligibilité d'une campagne en forme pour l'écran
 * d'administration (ADR-009).
 *
 * Deux jeux de données, et il faut les deux :
 *
 * - `form` : ce que l'administrateur va modifier. Les valeurs absentes sont des
 *   chaînes vides, jamais des zéros — un `0` dans « âge minimum » se relirait
 *   comme un critère publié.
 *
 * - `criteria` : ce que le moteur retient **aujourd'hui**, règle par règle.
 *   C'est la seule façon pour un administrateur de savoir ce qu'un candidat lit
 *   en ce moment sans ouvrir un compte candidat. Les libellés y sont ceux
 *   d'`EligibilityRule`, donc les mêmes que côté candidat : deux vocabulaires
 *   pour un même critère rendraient l'écran inutilisable pour arbitrer.
 *
 * Aucun libellé français n'est une valeur métier : les valeurs partent comme
 * codes d'enum, accompagnées de leur libellé (ADR-004).
 */
final readonly class EligibilitySettingsPresenter
{
    /**
     * @return array{
     *     campaign: array{id: int, code: string, name: string, statusLabel: string, timezone: string},
     *     form: array<string, mixed>,
     *     regionOptions: list<array{value: string, label: string}>,
     *     candidateTypeOptions: list<array{value: string, label: string}>,
     *     criteria: list<array{rule: string, label: string, configured: bool, summary: string}>,
     *     defaultReferenceDate: string|null
     * }
     */
    public function form(Campaign $campagne): array
    {
        $reglages = EligibilitySettings::fromCampaign($campagne);

        return [
            'campaign' => [
                'id' => $campagne->getKey(),
                'code' => $campagne->code,
                'name' => $campagne->name,
                'statusLabel' => $campagne->status->label(),
                'timezone' => $campagne->timezone,
            ],
            'form' => [
                'age_min' => $this->nombre($reglages->ageMin),
                'age_max' => $this->nombre($reglages->ageMax),
                'age_reference_date' => $reglages->ageReferenceDate ?? '',
                // Trois états, donc trois valeurs distinctes : la chaîne vide
                // dit « la campagne ne s'est pas prononcée », et se distingue
                // de « false », qui est une décision.
                'requires_niger_link' => match ($reglages->requiresNigerLink) {
                    true => 'true',
                    false => 'false',
                    null => '',
                },
                'regions' => array_map(static fn (NigerRegion $r): string => $r->value, $reglages->regions),
                'candidate_types' => array_map(static fn (CandidateType $t): string => $t->value, $reglages->candidateTypes),
                'team_size_min' => $this->nombre($reglages->teamSizeMin),
                'team_size_max' => $this->nombre($reglages->teamSizeMax),
            ],
            'regionOptions' => NigerRegion::options(),
            'candidateTypeOptions' => CandidateType::options(),
            'criteria' => $this->criteres($reglages),
            // Ce que le moteur retiendra si la date de référence reste vide :
            // la clôture de la campagne. L'écran l'annonce plutôt que de
            // pré-remplir le champ avec une date que personne n'a saisie.
            'defaultReferenceDate' => $campagne->closes_at
                ?->setTimezone(new DateTimeZone($campagne->timezone))
                ->format('Y-m-d'),
        ];
    }

    /**
     * État réel de chaque règle, tel que le moteur le lit.
     *
     * @return list<array{rule: string, label: string, configured: bool, summary: string}>
     */
    private function criteres(EligibilitySettings $reglages): array
    {
        $zones = implode(', ', array_map(static fn (NigerRegion $r): string => $r->label(), $reglages->regions));
        $formes = implode(', ', array_map(static fn (CandidateType $t): string => $t->label(), $reglages->candidateTypes));

        return [
            $this->critere(
                EligibilityRule::AGE,
                $reglages->ageMin !== null || $reglages->ageMax !== null,
                $this->resumeAge($reglages),
            ),
            $this->critere(
                EligibilityRule::NATIONALITY_RESIDENCE,
                $reglages->requiresNigerLink !== null,
                match ($reglages->requiresNigerLink) {
                    true => 'Nationalité nigérienne ou résidence au Niger exigée.',
                    false => 'Aucune condition de nationalité ni de résidence.',
                    null => '',
                },
            ),
            $this->critere(
                EligibilityRule::ZONE,
                $reglages->regions !== [],
                $this->accord(count($reglages->regions), 'zone ouverte', 'zones ouvertes').' : '.$zones.'.',
            ),
            $this->critere(
                EligibilityRule::CANDIDATE_TYPE,
                $reglages->candidateTypes !== [],
                'Formes acceptées : '.$formes.'.',
            ),
            $this->critere(
                EligibilityRule::TEAM_SIZE,
                $reglages->teamSizeMin !== null || $reglages->teamSizeMax !== null,
                $this->resumeTaille($reglages),
            ),
        ];
    }

    /**
     * @return array{rule: string, label: string, configured: bool, summary: string}
     */
    private function critere(EligibilityRule $regle, bool $configure, string $resume): array
    {
        return [
            'rule' => $regle->value,
            'label' => $regle->label(),
            'configured' => $configure,
            // Le message « non publié » est unique et volontairement identique
            // pour les cinq règles : c'est une seule et même conséquence.
            'summary' => $configure
                ? $resume
                : 'Critère non publié : il ne peut ni écarter ni rassurer un candidat.',
        ];
    }

    /** Accord en nombre : « 1 zone ouverte », « 8 zones ouvertes ». */
    private function accord(int $nombre, string $singulier, string $pluriel): string
    {
        return $nombre.' '.($nombre > 1 ? $pluriel : $singulier);
    }

    private function resumeAge(EligibilitySettings $reglages): string
    {
        $borne = match (true) {
            $reglages->ageMin !== null && $reglages->ageMax !== null => "De {$reglages->ageMin} à {$reglages->ageMax} ans",
            $reglages->ageMin !== null => "À partir de {$reglages->ageMin} ans",
            default => "Jusqu’à {$reglages->ageMax} ans",
        };

        if ($reglages->ageReferenceDate === null) {
            return $borne.', à la clôture de la campagne.';
        }

        $reference = DateTimeImmutable::createFromFormat('!Y-m-d', $reglages->ageReferenceDate);

        return $borne.', au '.($reference === false ? $reglages->ageReferenceDate : $reference->format('d/m/Y')).'.';
    }

    private function resumeTaille(EligibilitySettings $reglages): string
    {
        return match (true) {
            $reglages->teamSizeMin !== null && $reglages->teamSizeMax !== null => "De {$reglages->teamSizeMin} à {$reglages->teamSizeMax} personnes.",
            $reglages->teamSizeMin !== null => "Au moins {$reglages->teamSizeMin} personnes.",
            default => "Au plus {$reglages->teamSizeMax} personnes.",
        };
    }

    /** Un paramètre absent reste une chaîne vide : `0` serait un critère publié. */
    private function nombre(?int $valeur): string
    {
        return $valeur === null ? '' : (string) $valeur;
    }
}
