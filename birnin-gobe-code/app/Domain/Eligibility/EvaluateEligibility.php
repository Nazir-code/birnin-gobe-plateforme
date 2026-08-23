<?php

namespace App\Domain\Eligibility;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\EligibilitySection;
use App\Domain\Candidate\CandidateType;
use App\Domain\Reference\NigerRegion;
use App\Models\Application;
use DateTimeImmutable;

/**
 * Calcule l'éligibilité à partir des réponses enregistrées.
 *
 * Le navigateur n'envoie jamais de verdict : il envoie des réponses, et le
 * serveur en tire les conséquences. C'est pour cela que le résultat n'est pas
 * persisté — il est recalculé à chaque lecture, donc toujours reproductible et
 * toujours cohérent avec les paramètres actuels de la campagne.
 *
 * Le résultat reste **indicatif** (cahier des charges §5.2). Il ne décide pas
 * de l'admissibilité, qui relève d'un contrôle humain sur pièces (§10.2).
 */
final readonly class EvaluateEligibility
{
    /** Verdict pour une candidature : ses réponses, sa campagne. */
    public function forApplication(Application $application): EligibilityAssessment
    {
        $reponses = $application->sectionAnswers(ApplicationSection::ELIGIBILITY)?->answers ?? [];

        return $this->handle($reponses, CampaignEligibilityRules::forCampaign($application->campaign));
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function handle(array $answers, CampaignEligibilityRules $rules): EligibilityAssessment
    {
        return EligibilityAssessment::fromFindings([
            $this->age($answers, $rules),
            $this->nationaliteOuResidence($answers, $rules),
            $this->zone($answers, $rules),
            $this->typeDeCandidature($answers, $rules),
            $this->tailleEquipe($answers, $rules),
        ]);
    }

    /**
     * Âge à la date de référence.
     *
     * Aucune tranche n'est codée : le comité de pilotage ne l'a pas arrêtée
     * (cahier des charges §1.1 et §18.3). Tant qu'elle ne figure pas dans les
     * paramètres de la campagne, la règle le dit au lieu de conclure.
     */
    private function age(array $answers, CampaignEligibilityRules $rules): RuleFinding
    {
        $naissance = $this->date($answers[EligibilitySection::BIRTH_DATE] ?? null);

        if ($naissance === null) {
            return RuleFinding::unanswered(EligibilityRule::AGE, 'Indiquez votre date de naissance.');
        }

        if (! $rules->hasAgeRange()) {
            return RuleFinding::notConfigured(
                EligibilityRule::AGE,
                'La tranche d’âge de cette campagne n’est pas encore publiée : cette condition sera vérifiée lors du contrôle administratif.',
            );
        }

        $reference = $rules->ageReferenceDate ?? new DateTimeImmutable(now()->toDateString());
        $age = (int) $naissance->diff($reference)->y;
        $dateLisible = $reference->format('d/m/Y');

        if ($rules->ageMin !== null && $age < $rules->ageMin) {
            return RuleFinding::blocking(
                EligibilityRule::AGE,
                "Vous aurez {$age} ans au {$dateLisible}, alors que la campagne s’adresse aux personnes d’au moins {$rules->ageMin} ans.",
            );
        }

        if ($rules->ageMax !== null && $age > $rules->ageMax) {
            return RuleFinding::blocking(
                EligibilityRule::AGE,
                "Vous aurez {$age} ans au {$dateLisible}, alors que la campagne s’adresse aux personnes d’au plus {$rules->ageMax} ans.",
            );
        }

        return RuleFinding::satisfied(EligibilityRule::AGE, "Vous aurez {$age} ans au {$dateLisible}.");
    }

    /**
     * Lien avec le Niger.
     *
     * La nationalité **ou** la résidence suffit : le cahier des charges traite
     * les deux comme un couple (« nationalité/résidence »), et exiger les deux
     * exclurait la diaspora comme les résidents étrangers, ce qu'aucune source
     * ne demande.
     */
    private function nationaliteOuResidence(array $answers, CampaignEligibilityRules $rules): RuleFinding
    {
        $nationalite = $this->booleen($answers[EligibilitySection::NIGERIEN_NATIONAL] ?? null);
        $residence = $this->booleen($answers[EligibilitySection::RESIDES_IN_NIGER] ?? null);

        if ($nationalite === null || $residence === null) {
            return RuleFinding::unanswered(
                EligibilityRule::NATIONALITY_RESIDENCE,
                'Répondez aux deux questions sur votre nationalité et votre résidence.',
            );
        }

        if (! $rules->requiresNigerLink) {
            return RuleFinding::satisfied(
                EligibilityRule::NATIONALITY_RESIDENCE,
                'Cette campagne n’impose aucune condition de nationalité ou de résidence.',
            );
        }

        if ($nationalite || $residence) {
            return RuleFinding::satisfied(
                EligibilityRule::NATIONALITY_RESIDENCE,
                $nationalite ? 'Vous êtes de nationalité nigérienne.' : 'Vous résidez au Niger.',
            );
        }

        return RuleFinding::blocking(
            EligibilityRule::NATIONALITY_RESIDENCE,
            'La compétition s’adresse aux personnes de nationalité nigérienne ou résidant au Niger.',
        );
    }

    /**
     * Zone d'intervention.
     *
     * Sans liste de zones dans les paramètres de la campagne, toutes les
     * régions du référentiel conviennent : la validation a déjà écarté ce qui
     * n'est pas une région du Niger.
     */
    private function zone(array $answers, CampaignEligibilityRules $rules): RuleFinding
    {
        $region = NigerRegion::tryFrom((string) ($answers[EligibilitySection::INTERVENTION_REGION] ?? ''));

        if ($region === null) {
            return RuleFinding::unanswered(EligibilityRule::ZONE, 'Indiquez la région où votre projet interviendra.');
        }

        if ($rules->regions === null) {
            return RuleFinding::satisfied(
                EligibilityRule::ZONE,
                'Cette campagne est ouverte à toutes les régions du Niger.',
            );
        }

        if (in_array($region, $rules->regions, strict: true)) {
            return RuleFinding::satisfied(EligibilityRule::ZONE, "La région {$region->label()} fait partie des zones ouvertes.");
        }

        $ouvertes = implode(', ', array_map(static fn (NigerRegion $r): string => $r->label(), $rules->regions));

        return RuleFinding::blocking(
            EligibilityRule::ZONE,
            "Cette campagne ne couvre pas la région {$region->label()}. Zones ouvertes : {$ouvertes}.",
        );
    }

    /**
     * Type de candidature.
     *
     * Les trois formes sont acceptées par défaut : le cahier des charges (§4.1)
     * et le portail public les annoncent toutes les trois. Une campagne peut
     * restreindre la liste, elle n'a pas à la rouvrir.
     */
    private function typeDeCandidature(array $answers, CampaignEligibilityRules $rules): RuleFinding
    {
        $type = CandidateType::tryFrom((string) ($answers[EligibilitySection::CANDIDATE_TYPE] ?? ''));

        if ($type === null) {
            return RuleFinding::unanswered(EligibilityRule::CANDIDATE_TYPE, 'Indiquez sous quelle forme vous candidatez.');
        }

        if ($rules->candidateTypes === null || in_array($type, $rules->candidateTypes, strict: true)) {
            return RuleFinding::satisfied(EligibilityRule::CANDIDATE_TYPE, "Forme retenue : {$type->label()}.");
        }

        $acceptes = implode(', ', array_map(static fn (CandidateType $t): string => $t->label(), $rules->candidateTypes));

        return RuleFinding::blocking(
            EligibilityRule::CANDIDATE_TYPE,
            "Cette campagne n’accepte pas les candidatures « {$type->label()} ». Formes acceptées : {$acceptes}.",
        );
    }

    /**
     * Effectif d'une candidature collective.
     *
     * Le minimum de deux personnes n'est pas un seuil arbitraire : c'est ce qui
     * distingue une équipe d'une candidature individuelle. Le maximum, lui,
     * n'existe que si la campagne en fixe un.
     */
    private function tailleEquipe(array $answers, CampaignEligibilityRules $rules): RuleFinding
    {
        $type = CandidateType::tryFrom((string) ($answers[EligibilitySection::CANDIDATE_TYPE] ?? ''));

        if ($type === null) {
            return RuleFinding::unanswered(EligibilityRule::TEAM_SIZE, 'Indiquez d’abord sous quelle forme vous candidatez.');
        }

        if (! $type->isCollective()) {
            return RuleFinding::satisfied(EligibilityRule::TEAM_SIZE, 'Candidature individuelle : aucun effectif à déclarer.');
        }

        $effectif = $answers[EligibilitySection::TEAM_SIZE] ?? null;

        if (! is_int($effectif)) {
            return RuleFinding::unanswered(EligibilityRule::TEAM_SIZE, 'Indiquez le nombre de personnes de votre équipe.');
        }

        $minimum = $rules->teamSizeMin();

        if ($effectif < $minimum) {
            return RuleFinding::blocking(
                EligibilityRule::TEAM_SIZE,
                "Une candidature « {$type->label()} » compte au moins {$minimum} personnes. Candidatez à titre individuel si vous êtes seul.",
            );
        }

        if ($rules->teamSizeMax !== null && $effectif > $rules->teamSizeMax) {
            return RuleFinding::blocking(
                EligibilityRule::TEAM_SIZE,
                "Cette campagne limite les équipes à {$rules->teamSizeMax} personnes.",
            );
        }

        return RuleFinding::satisfied(EligibilityRule::TEAM_SIZE, "Équipe de {$effectif} personnes.");
    }

    private function date(mixed $valeur): ?DateTimeImmutable
    {
        if (! is_string($valeur) || $valeur === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);

        return $date instanceof DateTimeImmutable ? $date : null;
    }

    private function booleen(mixed $valeur): ?bool
    {
        return is_bool($valeur) ? $valeur : null;
    }
}
