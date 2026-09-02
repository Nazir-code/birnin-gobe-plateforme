<?php

namespace App\Domain\Application;

use App\Domain\Audit\AuditWriter;
use App\Jobs\ScanAttachment;
use App\Models\Application;
use App\Models\Attachment;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
                // `PENDING`, jamais `NOT_SCANNED` : l'analyse existe désormais
                // et va tourner. `NOT_SCANNED` est réservé aux pièces déposées
                // avant sa mise en service — voir `AttachmentScanStatus`.
                'scan_status' => AttachmentScanStatus::PENDING->value,
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

        // Après le `commit`, jamais dedans : un job mis en file dans une
        // transaction peut être consommé avant qu'elle ne soit validée, et
        // chercherait alors une ligne qui n'existe pas encore.
        ScanAttachment::dispatch($piece->getKey());

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

    /**
     * Sert une pièce, si et seulement si son analyse antivirus l'autorise — §15.1.
     *
     * **Le contrôle est ici, et nulle part ailleurs.** Trois écrans servent des
     * pièces : le candidat qui relit son dossier, le vérificateur du §10, et
     * l'évaluateur du §11.2. Trois `if` recopiés auraient fini par diverger, et
     * celui qu'on aurait oublié de mettre à jour serait devenu le chemin par
     * lequel un fichier vérolé sort — sur le poste de quelqu'un qui n'avait
     * aucune raison de se méfier.
     *
     * **`$versLeDeposant` sépare deux questions qu'on aurait eu tort de
     * confondre.** Servir la pièce d'un inconnu à un vérificateur est une
     * redistribution, et elle exige un verdict. La rendre au candidat qui vient
     * de l'envoyer est un aller-retour : le fichier vient de sa machine, la lui
     * refuser n'aurait rien protégé — et sans analyseur configuré, plus aucun
     * candidat ne pourrait relire ce qu'il a déposé. Seule la quarantaine
     * ferme aussi ce chemin-là, parce qu'une plateforme publique ne sert pas un
     * binaire dont elle sait qu'il porte une menace.
     *
     * Le refus est un **423 Locked** et non un 403 : la pièce existe, la
     * personne a le droit de la voir, et l'obstacle est temporaire dans quatre
     * cas sur cinq. Un 403 laisserait croire à un problème d'habilitation, et
     * enverrait chercher un droit qu'on a déjà.
     *
     * Le message vient de l'état lui-même : un refus muet se lit comme une
     * panne, et la personne réessaie en boucle.
     */
    public static function servir(Attachment $piece, bool $versLeDeposant = false): StreamedResponse
    {
        $etat = $piece->scan_status;

        $autorise = $versLeDeposant
            ? $etat->autoriseLeRetourAuDeposant()
            : $etat->autoriseLaRedistribution();

        abort_unless($autorise, 423, $etat->explication());

        // Une pièce servie à un tiers sans verdict ne l'est que par dérogation
        // (§15.1). Elle laisse une trace nominative — ce que le §15.1 réclame
        // par ailleurs pour tout accès aux pièces, et qui manquait. Sans elle,
        // la dérogation serait un risque qu'on prend sans pouvoir dire qui en a
        // usé, ni sur quel dossier.
        if (! $versLeDeposant && $etat !== AttachmentScanStatus::CLEAN) {
            app(AuditWriter::class)->write(
                actorId: (int) auth()->id(),
                action: 'APPLICATION_DOCUMENT_SERVED_UNSCANNED',
                targetType: Application::class,
                targetId: (string) $piece->application_id,
                oldValue: null,
                newValue: ['type' => $piece->type->value, 'scan_status' => $etat->value],
                reason: $etat->label(),
            );
        }

        return self::disk()->download(
            $piece->storage_key,
            $piece->original_filename,
            ['Content-Type' => $piece->mime_type],
        );
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
