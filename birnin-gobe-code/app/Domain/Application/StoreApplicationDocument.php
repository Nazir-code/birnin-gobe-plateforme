<?php

namespace App\Domain\Application;

use App\Domain\Audit\AuditWriter;
use App\Models\Application;
use App\Models\Attachment;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dépose, remplace et supprime les pièces d'une candidature.
 *
 * Trois soins qu'un contrôleur ne peut pas prendre tout seul :
 *
 * 1. **Un seul fichier par pièce.** Déposer une présentation de projet quand il
 *    y en a déjà une est un remplacement, pas un second envoi. L'ancienne ligne
 *    part et l'ancien fichier avec elle : deux « Présentation du projet » dans
 *    un dossier, c'est un jury qui ne sait pas laquelle lire.
 *
 * 2. **Aucun fichier orphelin, aucune ligne orpheline.** L'écriture en base et
 *    l'écriture sur le disque ne peuvent pas partager une transaction — le
 *    disque ne sait pas revenir en arrière. L'ordre choisi est donc : écrire le
 *    nouveau fichier, committer la base, puis seulement effacer l'ancien
 *    fichier. Si quelque chose casse en chemin, ce qui reste est un fichier de
 *    trop sur le disque, jamais une ligne pointant vers un fichier absent — le
 *    candidat garde une pièce téléchargeable dans tous les cas.
 *
 * 3. **Un nom de stockage tiré au sort.** Le nom d'origine est conservé comme
 *    métadonnée, pour le téléchargement, et n'entre jamais dans le chemin : il
 *    vient du navigateur, il peut contenir n'importe quoi, et un chemin
 *    devinable est un chemin qu'on devine.
 *
 * Le disque est celui que `config('filesystems.documents')` désigne — privé par
 * construction : ni `local` (hors de `public/`) ni S3 n'exposent d'URL publique.
 */
final readonly class StoreApplicationDocument
{
    public function __construct(private AuditWriter $audit) {}

    /**
     * Dépose une pièce, en remplaçant celle du même type s'il y en a une.
     */
    public function handle(Application $application, DocumentType $type, UploadedFile $fichier, int $actorId): Attachment
    {
        $disque = self::disk();
        $ancienne = self::pieceExistante($application, $type);

        // Le contenu part sur le disque avant toute écriture en base : une ligne
        // qui pointe vers un fichier inexistant serait un dossier cassé, là
        // qu'un fichier sans ligne n'est qu'un octet perdu.
        $chemin = $disque->putFileAs(
            self::dossierDe($application),
            $fichier,
            Str::ulid()->toBase32().'.'.$fichier->getClientOriginalExtension(),
        );

        if ($chemin === false) {
            throw new \RuntimeException('Le fichier n’a pas pu être écrit sur le disque des pièces.');
        }

        $piece = DB::transaction(function () use ($application, $type, $fichier, $chemin, $ancienne): Attachment {
            $ancienne?->delete();

            return Attachment::query()->create([
                'application_id' => $application->getKey(),
                'type' => $type->value,
                'storage_key' => $chemin,
                // Le nom d'origine est du texte fourni par le client : il est
                // conservé pour le seul affichage et le seul téléchargement,
                // jamais interprété comme un chemin.
                'original_filename' => self::nomLisible($fichier),
                // Le type MIME est celui que PHP déduit du contenu, pas celui
                // que le navigateur annonce.
                'mime_type' => $fichier->getMimeType() ?? 'application/octet-stream',
                'size' => $fichier->getSize() ?: 0,
                'checksum' => hash_file('sha256', $fichier->getRealPath()) ?: '',
                'scan_status' => AttachmentScanStatus::NOT_SCANNED->value,
            ]);
        });

        // La base est à jour : l'ancien fichier ne sert plus, et personne ne
        // peut plus l'atteindre.
        if ($ancienne !== null) {
            $disque->delete($ancienne->storage_key);
        }

        $this->audit->write(
            actorId: $actorId,
            action: $ancienne === null ? 'APPLICATION_DOCUMENT_UPLOADED' : 'APPLICATION_DOCUMENT_REPLACED',
            targetType: Application::class,
            targetId: (string) $application->getKey(),
            oldValue: $ancienne === null ? null : ['type' => $type->value, 'filename' => $ancienne->original_filename],
            newValue: ['type' => $type->value, 'filename' => $piece->original_filename, 'size' => $piece->size],
            reason: null,
        );

        return $piece;
    }

    /**
     * Retire une pièce et son fichier.
     *
     * Ordre inverse du dépôt, pour la même raison : la ligne part d'abord, le
     * fichier ensuite. Si l'effacement du fichier échoue, il reste un octet
     * inutile sur le disque — pas une pièce fantôme dans le dossier.
     */
    public function delete(Application $application, DocumentType $type, int $actorId): bool
    {
        $piece = self::pieceExistante($application, $type);

        if ($piece === null) {
            return false;
        }

        $chemin = $piece->storage_key;
        $nom = $piece->original_filename;

        $piece->delete();
        self::disk()->delete($chemin);

        $this->audit->write(
            actorId: $actorId,
            action: 'APPLICATION_DOCUMENT_DELETED',
            targetType: Application::class,
            targetId: (string) $application->getKey(),
            oldValue: ['type' => $type->value, 'filename' => $nom],
            newValue: null,
            reason: null,
        );

        return true;
    }

    /** Le disque privé des pièces, nommé une seule fois pour tout le domaine. */
    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    public static function diskName(): string
    {
        return (string) config('filesystems.documents', 'documents');
    }

    /**
     * Les pièces déjà déposées, indexées par type.
     *
     * @return array<string, Attachment>
     */
    public static function existingFor(Application $application): array
    {
        $pieces = [];

        foreach ($application->attachments()->whereNotNull('type')->get() as $piece) {
            $pieces[$piece->type->value] = $piece;
        }

        return $pieces;
    }

    /**
     * Les types réellement déposés, tels que `AttachmentsSection` les attend.
     *
     * @return list<DocumentType>
     */
    public static function typesFor(Application $application): array
    {
        return array_values(array_map(
            static fn (Attachment $piece): DocumentType => $piece->type,
            self::existingFor($application),
        ));
    }

    private static function pieceExistante(Application $application, DocumentType $type): ?Attachment
    {
        return Attachment::query()
            ->where('application_id', $application->getKey())
            ->where('type', $type->value)
            ->first();
    }

    /** Un dossier par candidature : rien ne se mélange entre deux candidats. */
    private static function dossierDe(Application $application): string
    {
        return 'applications/'.$application->getKey();
    }

    /**
     * Nom d'origine ramené à ce qui peut être réaffiché sans risque.
     *
     * Le chemin est retiré — un navigateur peut envoyer `..\\..\\passwd.pdf` —
     * et la longueur est bornée à ce que la colonne accepte.
     */
    private static function nomLisible(UploadedFile $fichier): string
    {
        $nom = basename(str_replace('\\', '/', $fichier->getClientOriginalName()));

        return Str::limit(trim($nom) === '' ? 'document' : $nom, 200, '');
    }
}
