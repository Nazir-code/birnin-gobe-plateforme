<?php

namespace App\Domain\Eligibility;

use App\Domain\Candidate\CandidateType;
use App\Domain\Reference\NigerRegion;
use App\Models\Campaign;
use DateTimeImmutable;

/**
 * Paramètres d'éligibilité d'une campagne, lus depuis `campaigns.settings`.
 *
 * Le cahier des charges est explicite : ces valeurs sont **administrables sans
 * code** (§9.2 « Éligibilité : Âge et date de référence, nationalité/résidence,
 * zones, types de candidats, taille d'équipe, restrictions et motifs
 * d'exclusion ») et le comité de pilotage ne les a **pas encore arrêtées**
 * (§1.1, §18.3). Aucune tranche d'âge n'est donc codée ici : elle serait
 * inventée, et une valeur inventée finit par être prise pour une décision.
 *
 * Cette classe ne fait que **lire** la colonne `settings`, déjà castée en
 * tableau par le modèle `Campaign`. Elle ne modifie ni le modèle, ni la
 * migration, ni la factory : l'écriture de ces paramètres appartient à l'écran
 * d'administration des campagnes.
 *
 * Forme attendue dans `settings`, toutes les clés étant facultatives :
 *
 *   "eligibility": {
 *     "age":             { "min": 18, "max": 35, "reference_date": "2026-11-20" },
 *     "requires_niger_link": true,
 *     "regions":         ["NE-8", "NE-4"],
 *     "candidate_types": ["INDIVIDUAL", "TEAM", "STARTUP"],
 *     "team_size":       { "min": 2, "max": 10 }
 *   }
 *
 * Une clé absente ne vaut jamais « refusé » : elle vaut « non paramétré », et
 * la règle correspondante le dit au candidat.
 */
final readonly class CampaignEligibilityRules
{
    private function __construct(
        public ?int $ageMin,
        public ?int $ageMax,
        public ?DateTimeImmutable $ageReferenceDate,
        public bool $requiresNigerLink,
        /** @var list<NigerRegion>|null Zones ouvertes ; `null` = aucune restriction. */
        public ?array $regions,
        /** @var list<CandidateType>|null Types acceptés ; `null` = les trois. */
        public ?array $candidateTypes,
        public ?int $teamSizeMax,
    ) {}

    public static function forCampaign(?Campaign $campaign): self
    {
        $settings = is_array($campaign?->settings) ? $campaign->settings : [];
        $eligibility = is_array($settings['eligibility'] ?? null) ? $settings['eligibility'] : [];

        $age = is_array($eligibility['age'] ?? null) ? $eligibility['age'] : [];
        $taille = is_array($eligibility['team_size'] ?? null) ? $eligibility['team_size'] : [];

        return new self(
            ageMin: self::entier($age['min'] ?? null),
            ageMax: self::entier($age['max'] ?? null),
            ageReferenceDate: self::dateReference($age['reference_date'] ?? null, $campaign),
            // Le lien avec le Niger est la seule condition que les sources
            // affirment sans réserve : PIDUREM — Galey Ma Zaada mobilise
            // « l'intelligence créative nigérienne » (§1) et la plateforme est
            // le point d'entrée national de la compétition. Une campagne peut
            // la lever explicitement ; elle est active par défaut.
            requiresNigerLink: (bool) ($eligibility['requires_niger_link'] ?? true),
            regions: self::regions($eligibility['regions'] ?? null),
            candidateTypes: self::types($eligibility['candidate_types'] ?? null),
            teamSizeMax: self::entier($taille['max'] ?? null),
        );
    }

    /** La règle d'âge ne peut conclure que si la campagne a fixé une borne. */
    public function hasAgeRange(): bool
    {
        return $this->ageMin !== null || $this->ageMax !== null;
    }

    /**
     * Effectif minimal d'une candidature collective.
     *
     * Deux personnes : ce n'est pas un seuil arbitraire mais la définition
     * même d'une équipe. Une campagne peut exiger davantage, jamais moins.
     */
    public function teamSizeMin(): int
    {
        return 2;
    }

    private static function entier(mixed $valeur): ?int
    {
        return is_int($valeur) || (is_string($valeur) && ctype_digit($valeur)) ? (int) $valeur : null;
    }

    /**
     * Date à laquelle l'âge se calcule.
     *
     * À défaut de date explicite, la clôture de la campagne : c'est la date à
     * laquelle le dossier est déposé au plus tard, donc la seule qui ne dépende
     * pas du jour où le candidat consulte l'écran. Sans clôture connue, la date
     * du jour.
     */
    private static function dateReference(mixed $valeur, ?Campaign $campaign): ?DateTimeImmutable
    {
        if (is_string($valeur) && $valeur !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);

            if ($date instanceof DateTimeImmutable) {
                return $date;
            }
        }

        $cloture = $campaign?->closes_at;

        return $cloture === null
            ? new DateTimeImmutable(now()->toDateString())
            : new DateTimeImmutable($cloture->toDateString());
    }

    /** @return list<NigerRegion>|null */
    private static function regions(mixed $valeur): ?array
    {
        if (! is_array($valeur) || $valeur === []) {
            return null;
        }

        $regions = array_filter(array_map(
            static fn (mixed $code): ?NigerRegion => is_string($code) ? NigerRegion::tryFrom($code) : null,
            $valeur,
        ));

        return $regions === [] ? null : array_values($regions);
    }

    /** @return list<CandidateType>|null */
    private static function types(mixed $valeur): ?array
    {
        if (! is_array($valeur) || $valeur === []) {
            return null;
        }

        $types = array_filter(array_map(
            static fn (mixed $code): ?CandidateType => is_string($code) ? CandidateType::tryFrom($code) : null,
            $valeur,
        ));

        return $types === [] ? null : array_values($types);
    }
}
