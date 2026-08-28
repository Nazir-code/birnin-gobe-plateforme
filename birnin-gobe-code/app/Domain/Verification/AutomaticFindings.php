<?php

namespace App\Domain\Verification;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\AttachmentScanStatus;
use App\Domain\Application\DocumentType;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\ProfileSection;
use App\Domain\Candidate\CandidateType;
use App\Domain\Eligibility\EvaluateEligibility;
use App\Domain\Eligibility\RuleFinding;
use App\Models\Application;
use App\Models\ApplicationSectionAnswers;
use App\Models\Attachment;

/**
 * Les « anomalies automatiques » que l'écran de contrôle présente — §10.1.
 *
 * **Rien ici ne conclut.** Chaque signalement se range sous le contrôle qu'il
 * éclaire, et le vérificateur reste seul à cocher. C'est la garantie du §10.3,
 * et elle tient par construction : cette classe ne rend que des
 * `AutomaticFinding`, un type qui n'a pas de verdict et que rien n'écrit dans
 * `verification_checks`.
 *
 * **On ne signale que ce qu'on sait réellement voir.** Le §10.2 mentionne le
 * plagiat présumé et la fraude documentaire ; aucun moteur ne les détecte ici,
 * donc le contrôle « Intégrité » ne reçoit aucun signalement automatique. Une
 * ligne « aucune anomalie détectée » sur un contrôle que personne n'exerce
 * serait un mensonge coûteux : elle inviterait à cocher « aucune alerte » sur
 * la foi d'une analyse qui n'a pas eu lieu.
 *
 * De même pour l'antivirus : `AttachmentScanStatus::NOT_SCANNED` dit qu'aucune
 * analyse n'a été faite, et le signalement le répète au vérificateur plutôt que
 * de laisser croire qu'une pièce est saine.
 *
 * Aucun de ces calculs n'est persisté : le dossier est relu à chaque ouverture,
 * pour la même raison que le verdict d'éligibilité — une règle de campagne peut
 * avoir changé depuis le dépôt.
 */
final readonly class AutomaticFindings
{
    public function __construct(private EvaluateEligibility $eligibilite) {}

    /**
     * @return list<AutomaticFinding>
     */
    public function for(Application $application): array
    {
        return array_merge(
            $this->delai($application),
            $this->profil($application),
            $this->completude($application),
            $this->pieces($application),
            $this->unicite($application),
        );
    }

    /**
     * Dépôt hors délai — le §10.2 le veut « calcul serveur ».
     *
     * Comparé à la clôture de **la campagne du dossier**, jamais à celle de la
     * campagne active : un dossier se juge sous le calendrier auquel il a été
     * déposé.
     *
     * @return list<AutomaticFinding>
     */
    private function delai(Application $application): array
    {
        $cloture = $application->campaign?->closes_at;
        $depot = $application->submitted_at;

        if ($cloture === null || $depot === null) {
            return [];
        }

        if ($depot->lessThanOrEqualTo($cloture)) {
            return [];
        }

        return [new AutomaticFinding(
            VerificationControl::DEPOSIT_DEADLINE,
            'Dépôt postérieur à la clôture',
            sprintf(
                'Déposé le %s, clôture le %s. Une recevabilité suppose une dérogation motivée.',
                $depot->locale('fr')->isoFormat('D MMMM YYYY à HH:mm'),
                $cloture->locale('fr')->isoFormat('D MMMM YYYY à HH:mm'),
            ),
        )];
    }

    /**
     * Le verdict d'éligibilité, rejoué à l'instant de la lecture.
     *
     * Une règle bloquante n'est pas une irrecevabilité : c'est l'auto-test du
     * §5.2, explicitement « indicatif ». Le vérificateur en tient compte, il ne
     * le recopie pas.
     *
     * @return list<AutomaticFinding>
     */
    private function profil(Application $application): array
    {
        $verdict = $this->eligibilite->forApplication($application);

        return array_map(
            static fn (RuleFinding $regle): AutomaticFinding => new AutomaticFinding(
                VerificationControl::PROFILE,
                'Règle d’éligibilité non remplie : '.$regle->rule->label(),
                $regle->message,
            ),
            $verdict->blocking(),
        );
    }

    /**
     * Sections du parcours restées inachevées.
     *
     * Le §10.2 demande, pour un dossier incomplet, « la liste exacte des
     * éléments manquants ». C'est cette liste, et elle vaut d'être calculée :
     * la recopier à la main dans un message au candidat produit des oublis.
     *
     * @return list<AutomaticFinding>
     */
    private function completude(Application $application): array
    {
        // `section` est casté en enum : le `pluck` rend des cas, pas des
        // chaînes, et la comparaison doit donc se faire entre cas. Comparer un
        // `BackedEnum` à sa valeur textuelle est toujours faux en PHP 8 — le
        // filtre rendrait alors les neuf sections comme manquantes.
        $achevees = ApplicationSectionAnswers::query()
            ->where('application_id', $application->getKey())
            ->whereNotNull('completed_at')
            ->pluck('section')
            ->all();

        $manquantes = array_values(array_filter(
            ApplicationSection::openPath(),
            static fn (ApplicationSection $section): bool => ! in_array($section, $achevees, true),
        ));

        if ($manquantes === []) {
            return [];
        }

        return [new AutomaticFinding(
            VerificationControl::COMPLETENESS,
            'Sections non achevées : '.count($manquantes),
            implode(', ', array_map(
                static fn (ApplicationSection $section): string => $section->label(),
                $manquantes,
            )).'.',
        )];
    }

    /**
     * Pièces exigées absentes, et absence d'analyse antivirus.
     *
     * Les pièces exigées dépendent du type de candidature déclaré à l'étape 1 :
     * la règle est appelée (`DocumentType::requiredFor`), jamais recopiée — on
     * n'exige pas de RCCM d'un porteur individuel.
     *
     * @return list<AutomaticFinding>
     */
    private function pieces(Application $application): array
    {
        $type = CandidateType::tryFrom((string) $this->reponse($application, ApplicationSection::ELIGIBILITY, EligibilitySection::CANDIDATE_TYPE));

        $deposees = $application->attachments->map(
            static fn (Attachment $piece): string => $piece->type->value,
        )->all();

        $signalements = [];

        $manquantes = array_values(array_filter(
            DocumentType::requiredFor($type),
            static fn (DocumentType $piece): bool => ! in_array($piece->value, $deposees, true),
        ));

        if ($manquantes !== []) {
            $signalements[] = new AutomaticFinding(
                VerificationControl::DOCUMENTS,
                'Pièces exigées absentes : '.count($manquantes),
                implode(', ', array_map(
                    static fn (DocumentType $piece): string => $piece->label(),
                    $manquantes,
                )).'.',
            );
        }

        $nonAnalysees = $application->attachments->filter(
            static fn (Attachment $piece): bool => $piece->scan_status !== AttachmentScanStatus::CLEAN->value,
        )->count();

        if ($nonAnalysees > 0) {
            $signalements[] = new AutomaticFinding(
                VerificationControl::DOCUMENTS,
                'Aucune analyse antivirus',
                sprintf(
                    '%d pièce(s) n’ont pas été analysées. Ouvrez-les dans la visionneuse, jamais depuis le poste de travail.',
                    $nonAnalysees,
                ),
            );
        }

        return $signalements;
    }

    /**
     * Rapprochements qui font soupçonner un doublon — §10.2 « signalement par
     * email, téléphone, identifiant et similarité ».
     *
     * **Trois des quatre axes ne peuvent rien signaler ici, et c'est une bonne
     * nouvelle.** Le compte et le courriel sont déjà tenus par la base :
     * `applications` porte un unique `(campaign_id, candidate_id)` et
     * `users.email` est unique, donc un même compte ne peut pas déposer deux
     * fois sur une campagne. Les signaler reviendrait à écrire du code
     * inatteignable, qui donnerait l'illusion d'une surveillance là où c'est une
     * contrainte d'intégrité qui protège.
     *
     * La similarité de contenu n'est pas calculée non plus : elle demanderait un
     * index trigramme et un seuil que personne n'a arbitré, et un seuil inventé
     * produirait des soupçons dont on ne saurait pas quoi faire.
     *
     * Reste le téléphone, qui est le seul axe réellement ouvert : deux comptes
     * distincts peuvent déclarer le même numéro. Il est exact, donc dénombrable
     * et explicable — un signalement qu'on ne peut pas expliquer au candidat ne
     * devrait pas exister.
     *
     * @return list<AutomaticFinding>
     */
    private function unicite(Application $application): array
    {
        $signalements = [];

        $telephone = trim((string) $this->reponse($application, ApplicationSection::PROFILE, ProfileSection::PHONE_PRIMARY));

        if ($telephone !== '') {
            $memeTelephone = Application::query()
                ->where('campaign_id', $application->campaign_id)
                ->whereKeyNot($application->getKey())
                ->whereHas('sections', fn ($q) => $q
                    ->where('section', ApplicationSection::PROFILE->value)
                    ->where('answers->'.ProfileSection::PHONE_PRIMARY, $telephone))
                ->count();

            if ($memeTelephone > 0) {
                $signalements[] = new AutomaticFinding(
                    VerificationControl::UNIQUENESS,
                    'Même numéro de téléphone',
                    sprintf('%d autre(s) dossier(s) déclarent ce numéro sur la même campagne.', $memeTelephone),
                );
            }
        }

        return $signalements;
    }

    /**
     * Une réponse d'une section du dossier déjà chargé, sans requête supplémentaire.
     *
     * La comparaison porte sur le cas de l'enum, pas sur sa valeur : `section`
     * est casté, et `firstWhere` compare de façon lâche — un `BackedEnum` face
     * à une chaîne ne correspond jamais.
     */
    private function reponse(Application $application, ApplicationSection $section, string $champ): mixed
    {
        $ligne = $application->sections->firstWhere('section', $section);

        return is_array($ligne?->answers) ? ($ligne->answers[$champ] ?? null) : null;
    }
}
