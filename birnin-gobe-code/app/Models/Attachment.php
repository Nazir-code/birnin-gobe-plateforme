<?php

namespace App\Models;

use App\Domain\Application\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une pièce jointe à une candidature — les métadonnées, jamais le binaire.
 *
 * Le fichier lui-même vit sur un disque privé ; la base ne garde que de quoi le
 * retrouver, le nommer au téléchargement et le contrôler. Mettre le contenu en
 * PostgreSQL rendrait chaque lecture de dossier proportionnelle au poids des
 * pièces, sauvegarde comprise.
 *
 * `storage_key` est le chemin sur le disque, et il est tiré au sort (ULID) :
 * connaître le nom d'origine d'une pièce ne permet pas d'en deviner
 * l'emplacement, et l'emplacement ne sort jamais vers le navigateur — le
 * téléchargement passe par une route qui vérifie la propriété.
 *
 * `scan_status` vient du squelette initial et attend l'analyse antivirus. Tant
 * qu'aucun analyseur ne tourne, la valeur écrite le dit — voir
 * `AttachmentScanStatus`. Rien dans le produit ne prétend qu'une pièce est
 * saine.
 */
class Attachment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'size' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
