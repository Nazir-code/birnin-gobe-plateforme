<?php

namespace App\Domain\Eligibility;

use App\Domain\Candidate\CandidateType;
use App\Domain\Reference\NigerRegion;
use App\Models\Campaign;
use DateTimeImmutable;

/**
 * Paramètres d'éligibilité d'une campagne, côté **écriture** (ADR-010).
 *
 * `CampaignEligibilityRules` lit `campaigns.settings.eligibility` pour le
 * moteur ; cette classe produit ce même bloc depuis l'écran d'administration.
 * Les deux sont volontairement distinctes : la lecture doit tolérer tout ce
 * qu'une base peut contenir, l'écriture ne doit produire qu'une seule forme.
 *
 * Règle qui gouverne toute la classe, corollaire d'ADR-007 : **un paramètre non
 * renseigné n'est pas écrit**. Il n'est stocké ni à `null`, ni à `0`, ni à une
 * liste vide — sa clé est absente. « Absent » est un état à part entière, que
 * le moteur traduit par `NOT_CONFIGURED`, donc par un résultat « sous réserve »
 * annoncé au candidat. L'écrire à `null` reviendrait au même pour le moteur
 * d'aujourd'hui, mais laisserait croire à la relecture que le comité s'est
 * prononcé.
 *
 * Le cas le plus visible est le lien avec le Niger, qui a **trois** états et non
 * deux : absent (« la campagne ne s'est pas prononcée »), `false` (« aucune
 * condition de nationalité ni de résidence » — une décision) et `true`
 * (« nationalité nigérienne ou résidence au Niger exigée »). Une case à cocher
 * n'en exprimerait que deux, et confondrait la décision avec le silence.
 */
final readonly class EligibilitySettings
{
    /**
     * @param  list<NigerRegion>  $regions  liste vide = zones non arrêtées
     * @param  list<CandidateType>  $candidateTypes  liste vide = formes non arrêtées
     */
    private function __construct(
        public ?int $ageMin,
        public ?int $ageMax,
        /** Date de référence au format `Y-m-d`, telle qu'elle sera stockée. */
        public ?string $ageReferenceDate,
        public ?bool $requiresNigerLink,
        public array $regions,
        public array $candidateTypes,
        public ?int $teamSizeMin,
        public ?int $teamSizeMax,
    ) {}

    /**
     * Ce que la campagne a déjà enregistré, relu dans la forme normalisée.
     *
     * Passer par `CampaignEligibilityRules` plutôt que par le tableau brut :
     * l'écran d'administration doit montrer ce que **le moteur** retient, pas
     * ce qu'une écriture antérieure a pu laisser en base.
     */
    public static function fromCampaign(?Campaign $campagne): self
    {
        $regles = CampaignEligibilityRules::forCampaign($campagne);

        $brut = is_array($campagne?->settings['eligibility'] ?? null) ? $campagne->settings['eligibility'] : [];
        $age = is_array($brut['age'] ?? null) ? $brut['age'] : [];

        return new self(
            ageMin: $regles->ageMin,
            ageMax: $regles->ageMax,
            // La date de référence est reprise du stockage, pas de
            // `CampaignEligibilityRules` : celui-ci comble le silence par la
            // clôture de la campagne, ce qui remplirait le champ du formulaire
            // avec une valeur que personne n'a saisie.
            ageReferenceDate: self::dateStockee($age['reference_date'] ?? null),
            requiresNigerLink: $regles->requiresNigerLink,
            regions: $regles->regions ?? [],
            candidateTypes: $regles->candidateTypes ?? [],
            teamSizeMin: $regles->teamSizeMin,
            teamSizeMax: $regles->teamSizeMax,
        );
    }

    /**
     * Paramètres tels que la requête validée les a normalisés.
     *
     * @param  array{age_min: ?int, age_max: ?int, age_reference_date: ?string, requires_niger_link: ?bool, regions: list<NigerRegion>, candidate_types: list<CandidateType>, team_size_min: ?int, team_size_max: ?int}  $donnees
     */
    public static function fromValidated(array $donnees): self
    {
        return new self(
            ageMin: $donnees['age_min'],
            ageMax: $donnees['age_max'],
            ageReferenceDate: $donnees['age_reference_date'],
            requiresNigerLink: $donnees['requires_niger_link'],
            regions: array_values($donnees['regions']),
            candidateTypes: array_values($donnees['candidate_types']),
            teamSizeMin: $donnees['team_size_min'],
            teamSizeMax: $donnees['team_size_max'],
        );
    }

    /**
     * Le bloc `eligibility` à écrire dans `settings`.
     *
     * Seules les clés renseignées y figurent : c'est ce qui donne au moteur la
     * distinction entre « absent » et « fixé ». Un bloc entièrement vide rend
     * `[]`, et l'appelant retire alors la clé plutôt que d'enregistrer un objet
     * vide qui ressemblerait à une configuration.
     *
     * @return array<string, mixed>
     */
    public function toStoredArray(): array
    {
        $bloc = [];

        $age = array_filter([
            'min' => $this->ageMin,
            'max' => $this->ageMax,
            'reference_date' => $this->ageReferenceDate,
        ], static fn (mixed $valeur): bool => $valeur !== null);

        if ($age !== []) {
            $bloc['age'] = $age;
        }

        if ($this->requiresNigerLink !== null) {
            $bloc['requires_niger_link'] = $this->requiresNigerLink;
        }

        if ($this->regions !== []) {
            $bloc['regions'] = array_map(static fn (NigerRegion $r): string => $r->value, $this->regions);
        }

        if ($this->candidateTypes !== []) {
            $bloc['candidate_types'] = array_map(static fn (CandidateType $t): string => $t->value, $this->candidateTypes);
        }

        $taille = array_filter([
            'min' => $this->teamSizeMin,
            'max' => $this->teamSizeMax,
        ], static fn (?int $valeur): bool => $valeur !== null);

        if ($taille !== []) {
            $bloc['team_size'] = $taille;
        }

        return $bloc;
    }

    /** Une date de référence n'est reprise que si elle a la forme attendue. */
    private static function dateStockee(mixed $valeur): ?string
    {
        if (! is_string($valeur) || $valeur === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);

        return $date instanceof DateTimeImmutable ? $date->format('Y-m-d') : null;
    }
}
