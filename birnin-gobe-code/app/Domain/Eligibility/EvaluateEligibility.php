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
 *
 * Principe qui gouverne les cinq règles : **une règle que la campagne n'a pas
 * configurée ne conclut pas**. Elle ne se replie ni sur « accepté », ni sur une
 * convention de bon sens — elle renvoie `NOT_CONFIGURED`, ce qui maintient le
 * dossier à `TO_CONFIRM`. Le logiciel n'a pas à trancher à la place du comité
 * de pilotage, et un candidat ne doit jamais être annoncé définitivement
 * éligible sur des critères incomplets. Voir ADR-007.
 *
 * Chaque règle interroge d'abord les réponses, ensuite la configuration : une
 * question sans réponse doit ressortir comme telle, et non être masquée par un
 * paramètre manquant.
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
                'La tranche d’âge de cette campagne n’est pas encore publiée. Votre résultat reste indicatif.',
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
     * Le caractère national du programme rend la condition vraisemblable, mais
     * le §9.2 la range parmi les paramètres de campagne : elle n'est appliquée
     * que si la campagne l'a explicitement posée.
     *
     * Quand elle l'est, la nationalité **ou** la résidence suffit : le cahier
     * des charges traite les deux comme un couple (« nationalité/résidence »),
     * et exiger les deux exclurait la diaspora comme les résidents étrangers,
     * ce qu'aucune source ne demande.
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

        if ($rules->requiresNigerLink === null) {
            return RuleFinding::notConfigured(
                EligibilityRule::NATIONALITY_RESIDENCE,
                'Les conditions de nationalité et de résidence de cette campagne ne sont pas encore publiées. Votre résultat reste indicatif.',
            );
        }

        if ($rules->requiresNigerLink === false) {
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
     * Deux choses distinctes, à ne pas confondre : le **référentiel** garantit
     * que la réponse est une vraie région du Niger — c'est de la validation, et
     * elle s'applique toujours — tandis que la **liste des zones ouvertes** est
     * un paramètre de campagne (§9.2). Sans cette liste, on ne peut pas
     * affirmer que la campagne couvre la région indiquée : « toutes les
     * régions » serait une décision, pas un repli technique.
     */
    private function zone(array $answers, CampaignEligibilityRules $rules): RuleFinding
    {
        $region = NigerRegion::tryFrom((string) ($answers[EligibilitySection::INTERVENTION_REGION] ?? ''));

        if ($region === null) {
            return RuleFinding::unanswered(EligibilityRule::ZONE, 'Indiquez la région où votre projet interviendra.');
        }

        if ($rules->regions === null) {
            return RuleFinding::notConfigured(
                EligibilityRule::ZONE,
                'Les zones couvertes par cette campagne ne sont pas encore publiées. Votre résultat reste indicatif.',
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
     * Le logiciel connaît trois formes (§4.1) ; cela ne dit pas lesquelles une
     * édition donnée accepte. Le §9.2 fait des « types de candidats » un
     * paramètre de campagne : sans liste publiée, la règle ne conclut pas.
     */
    private function typeDeCandidature(array $answers, CampaignEligibilityRules $rules): RuleFinding
    {
        $type = CandidateType::tryFrom((string) ($answers[EligibilitySection::CANDIDATE_TYPE] ?? ''));

        if ($type === null) {
            return RuleFinding::unanswered(EligibilityRule::CANDIDATE_TYPE, 'Indiquez sous quelle forme vous candidatez.');
        }

        if ($rules->candidateTypes === null) {
            return RuleFinding::notConfigured(
                EligibilityRule::CANDIDATE_TYPE,
                'Les formes de candidature acceptées par cette campagne ne sont pas encore publiées. Votre résultat reste indicatif.',
            );
        }

        if (in_array($type, $rules->candidateTypes, strict: true)) {
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
     * « Une équipe compte au moins deux personnes » a l'évidence pour elle,
     * mais le §9.2 fait de la taille d'équipe un paramètre de campagne. Coder
     * ce minimum en dur reviendrait à publier, au nom du comité de pilotage, un
     * critère qu'il n'a pas arrêté : sans borne configurée, la règle ne conclut
     * pas.
     *
     * Cela ne relâche rien du côté des données : l'effectif reste validé comme
     * un entier positif par la `FormRequest`, configuré ou non.
     */
    private function tailleEquipe(array $answers, CampaignEligibilityRules $rules): RuleFinding
    {
        $type = CandidateType::tryFrom((string) ($answers[EligibilitySection::CANDIDATE_TYPE] ?? ''));

        if ($type === null) {
            return RuleFinding::unanswered(EligibilityRule::TEAM_SIZE, 'Indiquez d’abord sous quelle forme vous candidatez.');
        }

        // Ce n'est pas un seuil, c'est le sens de la réponse : une candidature
        // individuelle n'a pas d'effectif, quelle que soit la configuration.
        if (! $type->isCollective()) {
            return RuleFinding::satisfied(EligibilityRule::TEAM_SIZE, 'Candidature individuelle : aucun effectif à déclarer.');
        }

        $effectif = $answers[EligibilitySection::TEAM_SIZE] ?? null;

        if (! is_int($effectif)) {
            return RuleFinding::unanswered(EligibilityRule::TEAM_SIZE, 'Indiquez le nombre de personnes de votre équipe.');
        }

        if (! $rules->hasTeamSizeRange()) {
            return RuleFinding::notConfigured(
                EligibilityRule::TEAM_SIZE,
                'La taille d’équipe attendue pour cette campagne n’est pas encore publiée. Votre résultat reste indicatif.',
            );
        }

        if ($rules->teamSizeMin !== null && $effectif < $rules->teamSizeMin) {
            return RuleFinding::blocking(
                EligibilityRule::TEAM_SIZE,
                "Cette campagne attend au moins {$rules->teamSizeMin} personnes pour une candidature « {$type->label()} ».",
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
