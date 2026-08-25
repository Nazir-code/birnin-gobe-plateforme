<?php

namespace App\Domain\Application;

/**
 * État de l'analyse antivirus d'une pièce.
 *
 * Le squelette initial a créé la colonne `attachments.scan_status` avec
 * `QUARANTINE` par défaut, en prévision de ClamAV. L'analyseur n'est pas branché
 * et ne l'est pas dans cette phase.
 *
 * D'où cet enum, et surtout d'où `NOT_SCANNED` : laisser les pièces prendre la
 * valeur par défaut ferait dire à la base que chaque fichier a été mis en
 * quarantaine, c'est-à-dire qu'une analyse a eu lieu et a mal tourné. Une
 * colonne qui ment est pire qu'une colonne vide, parce qu'on finit par
 * construire une décision dessus.
 *
 * `NOT_SCANNED` dit l'exacte vérité : le fichier est là, personne ne l'a
 * examiné. Le jour où l'analyse existera, elle fera passer les pièces à `CLEAN`
 * ou `QUARANTINE`, et c'est à ce moment-là — pas avant — qu'un écran pourra
 * refuser un téléchargement sur ce motif.
 */
enum AttachmentScanStatus: string
{
    case NOT_SCANNED = 'NOT_SCANNED';
    case CLEAN = 'CLEAN';
    case QUARANTINE = 'QUARANTINE';
}
