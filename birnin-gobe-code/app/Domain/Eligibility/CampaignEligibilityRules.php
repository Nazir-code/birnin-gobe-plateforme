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
 * (§1.1, §18.3).
 *
 * D'où la règle qui gouverne toute cette classe : **aucun paramètre absent n'a
 * de valeur par défaut**. Chaque accesseur renvoie `null` — « non configuré » —
 * et jamais une convention qui deviendrait, en pratique, une décision du
 * comité prise par le logiciel. Ni tranche d'âge, ni liste de zones, ni
 * effectif minimal d'équipe : voir ADR-007.
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
        /** `null` = la campagne ne s'est pas prononcée sur le lien avec le Niger. */
        public ?bool $requiresNigerLink,
        /** @var list<NigerRegion>|null Zones ouvertes ; `null` = non configuré. */
        public ?array $regions,
        /** @var list<CandidateType>|null Types acceptés ; `null` = non configuré. */
        public ?array $candidateTypes,
        public ?int $teamSizeMin,
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
            // Le caractère national du programme (§1) rend la condition de lien
            // avec le Niger vraisemblable — pas officielle. Le §9.2 la range
            // parmi les paramètres administrables : tant que la campagne ne
            // l'a pas posée, la règle ne conclut pas.
            requiresNigerLink: self::booleen($eligibility['requires_niger_link'] ?? null),
            regions: self::regions($eligibility['regions'] ?? null),
            candidateTypes: self::types($eligibility['candidate_types'] ?? null),
            teamSizeMin: self::entier($taille['min'] ?? null),
            teamSizeMax: self::entier($taille['max'] ?? null),
        );
    }

    /** La règle d'âge ne peut conclure que si la campagne a fixé une borne. */
    public function hasAgeRange(): bool
    {
        return $this->ageMin !== null || $this->ageMax !== null;
    }

    /**
     * La règle d'effectif ne peut conclure que si la campagne a fixé une borne.
     *
     * « Une équipe compte au moins deux personnes » paraît aller de soi. Le
     * §9.2 range pourtant la taille d'équipe parmi les paramètres de campagne :
     * l'écrire en dur en ferait un critère officiel que personne n'a arrêté.
     */
    public function hasTeamSizeRange(): bool
    {
        return $this->teamSizeMin !== null || $this->teamSizeMax !== null;
    }

    private static function entier(mixed $valeur): ?int
    {
        return is_int($valeur) || (is_string($valeur) && ctype_digit($valeur)) ? (int) $valeur : null;
    }

    /** Seul un booléen explicite compte : tout le reste vaut « non configuré ». */
    private static function booleen(mixed $valeur): ?bool
    {
        return is_bool($valeur) ? $valeur : null;
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
