<?php

namespace App\Http\Requests\Candidate;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\ProfileSection;
use App\Domain\Application\TeamSection;
use App\Domain\Candidate\CandidateType;
use App\Models\Application;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation serveur de la section « Structure / équipe ».
 *
 * La validation est **conditionnelle au type de candidature**, comme l'exige le
 * §6.2 (« détermine les champs et pièces conditionnels »). Mais le type ne vient
 * pas de la requête : il est relu depuis la section « Éligibilité » du dossier.
 * Un formulaire forgé annonçant `candidate_type=STARTUP` n'ouvre donc aucun
 * champ que l'étape 1 n'a pas ouvert — et `candidate_type` n'est jamais écrit
 * ici, ce qui interdit une seconde source de vérité (ADR-011).
 *
 * Ce qui est refusé l'est définitivement : un numéro hors format E.164, une
 * année de création absurde, un membre de trop.
 *
 * L'autorisation n'est pas refaite ici : elle est portée par `role:candidate`,
 * `can:update,application` et le middleware `eligible` sur la route.
 */
final class SaveTeamSectionRequest extends FormRequest
{
    /**
     * Normalisation avant validation.
     *
     * Les numéros des membres passent par la même normalisation que ceux du
     * profil : la règle de format porte ainsi sur la valeur qui sera réellement
     * stockée, et un membre saisi « 90 12 34 56 » est joignable par la
     * passerelle SMS (§14) au même titre que le candidat.
     */
    protected function prepareForValidation(): void
    {
        $membres = $this->input(TeamSection::MEMBERS);

        if (! is_array($membres)) {
            return;
        }

        foreach ($membres as $rang => $membre) {
            if (is_array($membre) && is_string($membre[TeamSection::MEMBER_PHONE] ?? null)) {
                $membres[$rang][TeamSection::MEMBER_PHONE] = ProfileSection::normalizePhone($membre[TeamSection::MEMBER_PHONE]);
            }
        }

        $this->merge([TeamSection::MEMBERS => $membres]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $type = $this->typeDeCandidature();
        $texteCourt = ['nullable', 'string', 'max:'.TeamSection::SHORT_TEXT_MAX];

        // Une candidature individuelle n'a ni structure ni membres : aucun de
        // ces champs n'est accepté, même envoyé.
        if (! TeamSection::attendDesMembres($type)) {
            return [];
        }

        $regles = [
            TeamSection::MEMBERS => ['nullable', 'array', 'max:'.TeamSection::MEMBERS_CEILING],
            TeamSection::MEMBERS.'.*.'.TeamSection::MEMBER_NAME => $texteCourt,
            TeamSection::MEMBERS.'.*.'.TeamSection::MEMBER_EMAIL => ['nullable', 'string', 'email', 'max:255'],
            TeamSection::MEMBERS.'.*.'.TeamSection::MEMBER_PHONE => ['nullable', 'string', 'regex:'.ProfileSection::E164_PATTERN],
            TeamSection::MEMBERS.'.*.'.TeamSection::MEMBER_ROLE => $texteCourt,
            TeamSection::MEMBERS.'.*.'.TeamSection::MEMBER_SKILLS => ['nullable', 'string', 'max:'.TeamSection::LONG_TEXT_MAX],
            TeamSection::MEMBERS.'.*.'.TeamSection::MEMBER_AVAILABILITY => $texteCourt,
            TeamSection::MEMBERS.'.*.'.TeamSection::MEMBER_IS_FOUNDER => ['nullable', 'boolean'],
            TeamSection::MEMBERS.'.*.'.TeamSection::MEMBER_CONSENT => ['nullable', 'boolean'],
        ];

        if (! TeamSection::attendUneStructure($type)) {
            return $regles;
        }

        return [
            ...$regles,
            TeamSection::STRUCTURE_NAME => $texteCourt,
            TeamSection::STRUCTURE_ACRONYM => ['nullable', 'string', 'max:32'],
            TeamSection::STRUCTURE_FOUNDED_YEAR => ['nullable', 'integer', 'min:'.TeamSection::FOUNDED_YEAR_FLOOR, 'max:'.(int) date('Y')],
            TeamSection::STRUCTURE_SECTOR => $texteCourt,
            TeamSection::STRUCTURE_ADDRESS => ['nullable', 'string', 'max:'.TeamSection::LONG_TEXT_MAX],
            TeamSection::STRUCTURE_RCCM => ['nullable', 'string', 'max:64'],
            TeamSection::STRUCTURE_NIF => ['nullable', 'string', 'max:64'],
            TeamSection::STRUCTURE_WEBSITE => ['nullable', 'string', 'url', 'max:255'],
            TeamSection::STRUCTURE_SOCIAL => ['nullable', 'string', 'max:'.TeamSection::LONG_TEXT_MAX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            TeamSection::MEMBERS.'.*.'.TeamSection::MEMBER_PHONE.'.regex' => 'Indiquez un numéro joignable, par exemple 90 12 34 56 ou +227 90 12 34 56.',
            TeamSection::MEMBERS.'.max' => 'Vous ne pouvez pas déclarer plus de :max membres.',
            TeamSection::STRUCTURE_FOUNDED_YEAR.'.max' => 'L’année de création ne peut pas être dans le futur.',
            TeamSection::STRUCTURE_WEBSITE.'.url' => 'Indiquez une adresse complète, par exemple https://exemple.ne.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            TeamSection::STRUCTURE_NAME => 'dénomination',
            TeamSection::STRUCTURE_ACRONYM => 'sigle',
            TeamSection::STRUCTURE_FOUNDED_YEAR => 'année de création',
            TeamSection::STRUCTURE_SECTOR => 'secteur d’activité',
            TeamSection::STRUCTURE_ADDRESS => 'adresse',
            TeamSection::STRUCTURE_RCCM => 'RCCM',
            TeamSection::STRUCTURE_NIF => 'NIF',
            TeamSection::STRUCTURE_WEBSITE => 'site internet',
            TeamSection::STRUCTURE_SOCIAL => 'réseaux',
        ];
    }

    /**
     * Réponses normalisées, prêtes à être persistées.
     *
     * Seules les clés pertinentes pour le type sont conservées : une
     * candidature repassée d'équipe à individuelle ne traîne pas des membres
     * fantômes, et une équipe informelle n'emporte pas de données légales.
     *
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        $type = $this->typeDeCandidature();

        if (! TeamSection::attendDesMembres($type)) {
            return [];
        }

        $valide = $this->validated();
        $answers = [TeamSection::MEMBERS => $this->membres($valide[TeamSection::MEMBERS] ?? [])];

        if (! TeamSection::attendUneStructure($type)) {
            return $answers;
        }

        foreach (TeamSection::structureFields() as $champ) {
            $valeur = $valide[$champ] ?? null;

            $answers[$champ] = match (true) {
                $champ === TeamSection::STRUCTURE_FOUNDED_YEAR => is_numeric($valeur) ? (int) $valeur : null,
                trim((string) $valeur) === '' => null,
                default => trim((string) $valeur),
            };
        }

        return $answers;
    }

    /**
     * Membres nettoyés : clés connues seulement, chaînes vides ramenées à
     * `null`, booléens typés une fois pour toutes.
     *
     * @param  array<int, mixed>  $bruts
     * @return list<array<string, mixed>>
     */
    private function membres(array $bruts): array
    {
        $membres = [];

        foreach ($bruts as $brut) {
            if (! is_array($brut)) {
                continue;
            }

            $membre = [];

            foreach (TeamSection::memberFields() as $champ) {
                $valeur = $brut[$champ] ?? null;

                $membre[$champ] = match (true) {
                    in_array($champ, [TeamSection::MEMBER_IS_FOUNDER, TeamSection::MEMBER_CONSENT], strict: true) => (bool) $valeur,
                    $valeur === null || trim((string) $valeur) === '' => null,
                    default => trim((string) $valeur),
                };
            }

            // Une ligne entièrement vide est une ligne que le candidat vient
            // d'ouvrir puis d'abandonner : on ne la persiste pas.
            $renseigne = array_filter(
                $membre,
                static fn (mixed $valeur): bool => $valeur !== null && $valeur !== false,
            );

            if ($renseigne !== []) {
                $membres[] = $membre;
            }
        }

        return $membres;
    }

    /**
     * Type de candidature du dossier — lu à l'étape 1, jamais dans la requête.
     */
    private function typeDeCandidature(): ?CandidateType
    {
        $application = $this->route('application');

        if (! $application instanceof Application) {
            return null;
        }

        $eligibilite = $application->sectionAnswers(ApplicationSection::ELIGIBILITY)?->answers ?? [];

        return CandidateType::tryFrom((string) ($eligibilite[EligibilitySection::CANDIDATE_TYPE] ?? ''));
    }
}
