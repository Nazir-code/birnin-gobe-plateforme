<?php

namespace App\Http\Controllers\Candidate;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\AttachmentsSection;
use App\Domain\Application\DocumentType;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\SaveApplicationSection;
use App\Domain\Application\StoreApplicationDocument;
use App\Domain\Candidate\CandidateType;
use App\Http\Presenters\ApplicationPresenter;
use App\Http\Requests\Candidate\SaveAttachmentsSectionRequest;
use App\Http\Requests\Candidate\UploadApplicationDocumentRequest;
use App\Models\Application;
use App\Models\Attachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Section « Pièces / déclarations » — étape 8 du formulaire.
 *
 * Dernière étape de contenu du dossier. « Relecture / envoi » (étape 9) reste
 * fermée : `nextOnOpenPath()` renvoie donc `null` et l'écran le dit, plutôt que
 * de proposer un lien vers une page qui n'existe pas encore. **Aucun bouton de
 * soumission ici** — le dépôt appartient à l'étape 9.
 *
 * L'étape mêle deux natures que le §5.2 réunit sous un même intitulé :
 *
 *   les **déclarations** suivent le chemin des sept sections précédentes —
 *     `FormRequest`, `SaveApplicationSection`, sauvegarde automatique ;
 *   les **pièces** ont leurs propres routes. Un fichier ne se sauvegarde pas
 *     toutes les deux secondes pendant la frappe : il se dépose une fois, se
 *     remplace ou se retire. Les mêler à la sauvegarde automatique aurait fait
 *     remonter le fichier à chaque déclaration cochée — indéfendable sur une
 *     connexion mobile partagée (§8.2).
 *
 * Les deux se rejoignent au même endroit : après chaque écriture, la complétude
 * de la section est recalculée sur les **deux** — pièces exigées présentes et
 * déclarations exigées acceptées — et `completed_at` en découle. C'est cette
 * date, et rien d'autre, qui fait basculer `SubmissionReadiness`.
 *
 * L'accès à `$application` est autorisé par la policy déclarée sur la route :
 * `can:view` pour lire et télécharger, `can:update` pour écrire — et
 * `can:update` porte déjà « brouillon », d'où le verrouillage après soumission
 * sans un seul `if` ici.
 */
final class AttachmentsSectionController
{
    public function edit(Application $application, ApplicationPresenter $presenter): Response
    {
        $section = ApplicationSection::ATTACHMENTS;
        $reponses = $application->sectionAnswers($section);
        $declarations = $this->declarations($reponses?->answers ?? []);
        $type = $this->typeDeCandidature($application);

        return Inertia::render('Candidate/Application/Attachments', [
            'application' => $presenter->summary($application),
            'steps' => $presenter->steps($application),
            'section' => $presenter->section($section, $reponses),
            'answers' => $declarations,
            'requiredDeclarations' => AttachmentsSection::requiredFor($type),
            'documents' => $this->pieces($application),
            'documentTypes' => DocumentType::catalogueFor($type),
            'missing' => AttachmentsSection::missing(
                $declarations,
                StoreApplicationDocument::typesFor($application),
                $type,
            ),
            'uploadUrl' => route('candidate.application.attachments.documents.store', $application),
            'saveUrl' => route('candidate.application.attachments.update', $application),
            ...$presenter->navigation($application, $section),
        ]);
    }

    /** Sauvegarde des déclarations. Les pièces ne passent pas par ici. */
    public function update(
        SaveAttachmentsSectionRequest $request,
        Application $application,
        SaveApplicationSection $save,
        ApplicationPresenter $presenter,
    ): JsonResponse|RedirectResponse {
        $declarations = $request->answers();

        $application = $save->handle(
            $application,
            ApplicationSection::ATTACHMENTS,
            $declarations,
            $this->estComplete($application, $declarations),
        );

        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json($this->etat($application, $presenter, $declarations));
        }

        return back();
    }

    /**
     * Dépôt ou remplacement d'une pièce.
     *
     * Un dépôt change la complétude de la section : la sauvegarde de section est
     * donc rejouée avec les déclarations déjà en base, sans les toucher. Sans
     * cela, un candidat qui aurait tout coché puis téléversé sa dernière pièce
     * resterait bloqué à `completed_at` nul jusqu'à ce qu'il recoche une case.
     */
    public function storeDocument(
        UploadApplicationDocumentRequest $request,
        Application $application,
        StoreApplicationDocument $depot,
        SaveApplicationSection $save,
        ApplicationPresenter $presenter,
    ): JsonResponse|RedirectResponse {
        $type = $request->documentType();

        // `null` est déjà refusé par la validation ; la garde tient la promesse
        // du type de retour pour l'analyse statique.
        if ($type === null) {
            abort(422);
        }

        $depot->handle($application, $type, $request->file(UploadApplicationDocumentRequest::FILE), (int) $request->user()->getKey());

        return $this->apresEcritureDePiece($request, $application, $save, $presenter);
    }

    /** Retrait d'une pièce. Même recalcul de complétude que le dépôt. */
    public function destroyDocument(
        Application $application,
        string $type,
        StoreApplicationDocument $depot,
        SaveApplicationSection $save,
        ApplicationPresenter $presenter,
    ): JsonResponse|RedirectResponse {
        $piece = DocumentType::tryFrom($type);

        if ($piece === null) {
            abort(404);
        }

        $depot->delete($application, $piece, (int) request()->user()->getKey());

        return $this->apresEcritureDePiece(request(), $application, $save, $presenter);
    }

    /**
     * Téléchargement d'une pièce par son propriétaire.
     *
     * Le chemin de stockage ne sort jamais : la route désigne une pièce par son
     * **type**, et c'est le serveur qui retrouve le fichier. Un identifiant
     * numérique de pièce aurait permis d'en essayer d'autres ; ici, le seul
     * dossier interrogeable est celui que la policy a déjà autorisé.
     *
     * Le nom rendu au navigateur est le nom d'origine, pas le nom de stockage —
     * le candidat récupère `presentation-ruwa-link.pdf`, pas un ULID.
     */
    public function downloadDocument(Application $application, string $type): StreamedResponse
    {
        $piece = $this->pieceOu404($application, $type);

        return StoreApplicationDocument::disk()->download(
            $piece->storage_key,
            $piece->original_filename,
            ['Content-Type' => $piece->mime_type],
        );
    }

    /**
     * Réponse commune au dépôt et au retrait.
     *
     * Rejoue la sauvegarde de section avec les déclarations **déjà en base** :
     * une pièce en plus ou en moins peut achever la section ou l'ouvrir à
     * nouveau, mais ne dit rien de ce que le candidat a déclaré.
     */
    private function apresEcritureDePiece(
        Request $request,
        Application $application,
        SaveApplicationSection $save,
        ApplicationPresenter $presenter,
    ): JsonResponse|RedirectResponse {
        $declarations = $this->declarations(
            $application->sectionAnswers(ApplicationSection::ATTACHMENTS)?->answers ?? [],
        );

        $application = $save->handle(
            $application,
            ApplicationSection::ATTACHMENTS,
            $declarations,
            $this->estComplete($application, $declarations),
        );

        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json($this->etat($application, $presenter, $declarations));
        }

        return back();
    }

    /**
     * Corps de réponse commun : ce que `savedPayload()` rend pour toutes les
     * sections, plus l'état des pièces et ce qu'il reste à faire.
     *
     * @param  array<string, bool>  $declarations
     * @return array<string, mixed>
     */
    private function etat(Application $application, ApplicationPresenter $presenter, array $declarations): array
    {
        $type = $this->typeDeCandidature($application);

        return [
            ...$presenter->savedPayload($application, $this->estComplete($application, $declarations)),
            'documents' => $this->pieces($application),
            'missing' => AttachmentsSection::missing(
                $declarations,
                StoreApplicationDocument::typesFor($application),
                $type,
            ),
        ];
    }

    /**
     * @param  array<string, bool>  $declarations
     */
    private function estComplete(Application $application, array $declarations): bool
    {
        return AttachmentsSection::isComplete(
            $declarations,
            StoreApplicationDocument::typesFor($application->fresh() ?? $application),
            $this->typeDeCandidature($application),
        );
    }

    /**
     * Les pièces déposées, décrites sans jamais nommer leur emplacement.
     *
     * Ce que l'écran reçoit : le nom d'origine, la taille et la date. Ni
     * `storage_key`, ni empreinte — connaître l'un ne doit jamais rapprocher de
     * l'autre. Le contenu lui-même n'est pas chargé : afficher la page ne lit
     * aucun fichier, ce qui compte quand la page s'ouvre sur un forfait mobile.
     *
     * @return array<string, array{type: string, filename: string, size: int, uploadedAt: string|null, downloadUrl: string, deleteUrl: string}>
     */
    private function pieces(Application $application): array
    {
        $pieces = [];

        foreach (StoreApplicationDocument::existingFor($application) as $cle => $piece) {
            $pieces[$cle] = [
                'type' => $cle,
                'filename' => $piece->original_filename,
                'size' => (int) $piece->size,
                'uploadedAt' => $piece->created_at?->toIso8601String(),
                'downloadUrl' => route('candidate.application.attachments.documents.download', [$application, $cle]),
                'deleteUrl' => route('candidate.application.attachments.documents.destroy', [$application, $cle]),
            ];
        }

        return $pieces;
    }

    private function pieceOu404(Application $application, string $type): Attachment
    {
        $piece = DocumentType::tryFrom($type);

        if ($piece === null) {
            abort(404);
        }

        $ligne = $application->attachments()->where('type', $piece->value)->first();

        if ($ligne === null) {
            abort(404);
        }

        return $ligne;
    }

    /**
     * Le type de candidature déclaré à l'étape 1.
     *
     * Relu depuis le dossier, jamais depuis la requête : c'est lui qui décide
     * quelles pièces et quelles déclarations sont exigées, et une charge utile
     * annonçant `INDIVIDUAL` ne doit pas pouvoir faire disparaître l'exigence de
     * CV d'une candidature en équipe.
     */
    private function typeDeCandidature(Application $application): ?CandidateType
    {
        $eligibilite = $application->sectionAnswers(ApplicationSection::ELIGIBILITY)?->answers ?? [];
        $valeur = $eligibilite[EligibilitySection::CANDIDATE_TYPE] ?? null;

        return is_string($valeur) ? CandidateType::tryFrom($valeur) : null;
    }

    /**
     * Déclarations mises en forme pour un formulaire React contrôlé : chaque
     * case a une valeur booléenne dès le premier rendu.
     *
     * @param  array<string, mixed>  $enregistrees
     * @return array<string, bool>
     */
    private function declarations(array $enregistrees): array
    {
        $declarations = [];

        foreach (AttachmentsSection::fields() as $champ) {
            $declarations[$champ] = ($enregistrees[$champ] ?? false) === true;
        }

        return $declarations;
    }
}
