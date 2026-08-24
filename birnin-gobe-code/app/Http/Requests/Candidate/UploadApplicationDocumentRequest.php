<?php

namespace App\Http\Requests\Candidate;

use App\Domain\Application\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation serveur d'un téléversement de pièce.
 *
 * Quatre contrôles, et aucun ne fait confiance au navigateur :
 *
 *   `file`      un fichier est réellement arrivé, et son envoi n'a pas échoué
 *               en route. Une charge utile qui prétend en contenir un sans en
 *               contenir échoue ici, pas plus loin ;
 *   `mimetypes` le type est déduit du **contenu** par PHP, pas de l'en-tête que
 *               le navigateur annonce ni de la fin du nom de fichier. Un
 *               exécutable renommé `presentation.pdf` est refusé ;
 *   `extensions` l'extension doit en outre concorder avec ce que le §7.2 admet
 *               pour cette pièce précise — un PDF valide déposé comme « CV »
 *               passe, un XLSX non ;
 *   `max`       la taille, en kilo-octets, bornée par `DocumentType`.
 *
 * Le type de pièce lui-même est contraint à l'enum : une valeur inventée ne
 * crée pas une septième catégorie de document.
 *
 * L'autorisation n'est pas refaite ici : `can:update,application` porte à la
 * fois « c'est son dossier » et « il est encore un brouillon », si bien qu'un
 * téléversement après soumission tombe en 403 sans jamais atteindre le disque.
 */
final class UploadApplicationDocumentRequest extends FormRequest
{
    public const TYPE = 'type';

    public const FILE = 'document';

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $type = $this->documentType();

        // Type inconnu : on refuse sur le type, et on ne prétend pas savoir
        // quelles extensions accepter pour une pièce qui n'existe pas.
        if ($type === null) {
            return [
                self::TYPE => ['required', 'string', Rule::enum(DocumentType::class)],
                self::FILE => ['required', 'file'],
            ];
        }

        return [
            self::TYPE => ['required', 'string', Rule::enum(DocumentType::class)],
            self::FILE => [
                'required',
                'file',
                'mimetypes:'.implode(',', $type->mimeTypes()),
                'extensions:'.implode(',', $type->extensions()),
                'max:'.DocumentType::MAX_KILOBYTES,
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            self::TYPE => 'type de pièce',
            self::FILE => 'fichier',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $type = $this->documentType();
        $formats = $type === null ? '' : mb_strtoupper(implode(', ', $type->extensions()));
        $mega = round(DocumentType::MAX_KILOBYTES / 1024, 1);

        return [
            self::FILE.'.required' => 'Choisissez un fichier à téléverser.',
            self::FILE.'.file' => 'Le fichier n’est pas arrivé en entier. Réessayez.',
            self::FILE.'.mimetypes' => "Ce fichier n’est pas au format attendu ({$formats}).",
            self::FILE.'.extensions' => "Formats acceptés pour cette pièce : {$formats}.",
            self::FILE.'.max' => "Le fichier dépasse {$mega} Mo. Réduisez-le avant de le téléverser.",
            self::FILE.'.uploaded' => "Le fichier dépasse {$mega} Mo, ou son envoi a été interrompu.",
            self::TYPE.'.required' => 'Indiquez de quelle pièce il s’agit.',
        ];
    }

    /** La pièce visée, ou `null` si la valeur envoyée n'en désigne aucune. */
    public function documentType(): ?DocumentType
    {
        $valeur = $this->input(self::TYPE);

        return is_string($valeur) ? DocumentType::tryFrom($valeur) : null;
    }
}
