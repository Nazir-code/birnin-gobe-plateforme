<?php

namespace App\Domain\Application;

use App\Domain\Candidate\CandidateType;
use App\Models\Application;

/**
 * Ce qu'il reste à faire sur la section « Structure / équipe », et pourquoi.
 *
 * Cette section est la première dont la complétude dépend d'une **autre**
 * section : le type de candidature et l'effectif déclaré vivent à l'étape 1.
 * Les lire ici plutôt que de les recopier est ce qui garantit qu'il n'existe
 * jamais deux vérités sur « combien êtes-vous » — voir ADR-011.
 *
 * Le même calcul sert à trois choses, ce qui est précisément l'intérêt de le
 * faire à un seul endroit : décider de `completed_at`, dire au candidat ce qui
 * manque, et alimenter les tests.
 */
final readonly class TeamSectionAssessment
{
    /**
     * @param  list<string>  $missing  motifs en langage candidat, écran compris
     */
    private function __construct(
        public bool $complete,
        public ?CandidateType $type,
        /** Effectif annoncé à l'étape 1, porteur principal compris. */
        public ?int $declaredSize,
        /** Effectif réellement décrit ici, porteur principal compris. */
        public int $describedSize,
        public bool $sizeMismatch,
        public array $missing,
    ) {}

    public static function forApplication(Application $application): self
    {
        $eligibilite = $application->sectionAnswers(ApplicationSection::ELIGIBILITY)?->answers ?? [];
        $equipe = $application->sectionAnswers(ApplicationSection::TEAM)?->answers ?? [];

        return self::evaluer($equipe, $eligibilite);
    }

    /**
     * @param  array<string, mixed>  $answers  réponses de la section « équipe »
     * @param  array<string, mixed>  $eligibilite  réponses de l'étape 1
     */
    public static function evaluer(array $answers, array $eligibilite): self
    {
        $type = CandidateType::tryFrom((string) ($eligibilite[EligibilitySection::CANDIDATE_TYPE] ?? ''));
        $declare = $eligibilite[EligibilitySection::TEAM_SIZE] ?? null;
        $declare = is_int($declare) ? $declare : null;

        $membres = is_array($answers[TeamSection::MEMBERS] ?? null) ? array_values($answers[TeamSection::MEMBERS]) : [];
        $decrit = TeamSection::effectifDecrit($membres);

        $manquants = [];

        // Tant que l'étape 1 n'a pas dit sous quelle forme on candidate, cette
        // section ne sait pas quoi demander. Elle le dit au lieu de deviner.
        if ($type === null) {
            return new self(
                complete: false,
                type: null,
                declaredSize: $declare,
                describedSize: $decrit,
                sizeMismatch: false,
                missing: ['Indiquez d’abord à l’étape « Éligibilité » sous quelle forme vous candidatez.'],
            );
        }

        // Candidature individuelle : le §6.2 ne prévoit ni structure ni membres.
        // Rien à remplir, donc rien à reprocher.
        if (! TeamSection::attendDesMembres($type)) {
            return new self(
                complete: true,
                type: $type,
                declaredSize: $declare,
                describedSize: 1,
                sizeMismatch: false,
                missing: [],
            );
        }

        if (TeamSection::attendUneStructure($type)) {
            $manquants = array_merge($manquants, self::structureIncomplete($answers));
        }

        $manquants = array_merge($manquants, self::membresIncomplets($membres, $type));

        // Cohérence entre l'effectif annoncé à l'étape 1 et l'équipe décrite
        // ici. Aucune des deux valeurs n'est réécrite en douce : c'est au
        // candidat de trancher, et l'écran lui montre les deux chemins.
        $ecart = $declare !== null && $membres !== [] && $decrit !== $declare;

        if ($ecart) {
            $manquants[] = "Vous avez annoncé {$declare} personnes à l’étape « Éligibilité » et décrit une équipe de {$decrit}. Ajustez l’une ou l’autre.";
        }

        return new self(
            complete: $manquants === [],
            type: $type,
            declaredSize: $declare,
            describedSize: $decrit,
            sizeMismatch: $ecart,
            missing: array_values($manquants),
        );
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return list<string>
     */
    private static function structureIncomplete(array $answers): array
    {
        $libelles = [
            TeamSection::STRUCTURE_NAME => 'la dénomination de la structure',
            TeamSection::STRUCTURE_FOUNDED_YEAR => 'son année de création',
            TeamSection::STRUCTURE_SECTOR => 'son secteur d’activité',
            TeamSection::STRUCTURE_ADDRESS => 'son adresse',
        ];

        $manquants = [];

        foreach (TeamSection::REQUIRED_STRUCTURE_FIELDS as $champ) {
            if (trim((string) ($answers[$champ] ?? '')) === '') {
                $manquants[] = 'Renseignez '.$libelles[$champ].'.';
            }
        }

        return $manquants;
    }

    /**
     * @param  array<int, mixed>  $membres
     * @return list<string>
     */
    private static function membresIncomplets(array $membres, CandidateType $type): array
    {
        if ($membres === []) {
            return ["Une candidature « {$type->label()} » compte au moins une autre personne que vous : ajoutez-la."];
        }

        $manquants = [];

        foreach ($membres as $rang => $membre) {
            if (! is_array($membre) || ! TeamSection::membreEstComplet($membre)) {
                $nom = is_array($membre) ? trim((string) ($membre[TeamSection::MEMBER_NAME] ?? '')) : '';
                $designation = $nom === '' ? 'Le membre '.($rang + 1) : $nom;

                $manquants[] = "{$designation} : complétez son nom, son rôle, un moyen de le joindre et son consentement.";
            }
        }

        return $manquants;
    }

    /**
     * @return array{
     *     complete: bool,
     *     type: string|null,
     *     typeLabel: string|null,
     *     declaredSize: int|null,
     *     describedSize: int,
     *     sizeMismatch: bool,
     *     missing: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'complete' => $this->complete,
            'type' => $this->type?->value,
            'typeLabel' => $this->type?->label(),
            'declaredSize' => $this->declaredSize,
            'describedSize' => $this->describedSize,
            'sizeMismatch' => $this->sizeMismatch,
            'missing' => $this->missing,
        ];
    }
}
