<?php

namespace App\Domain\Application;

/**
 * État de l'analyse antivirus d'une pièce — §15.1.
 *
 * Cinq états, et chacun dit une chose différente. La distinction qui compte le
 * plus n'est pas entre « sain » et « infecté » : c'est entre **« analysé et
 * infecté »** et **« pas d'analyse disponible »**. Les confondre ferait accuser
 * un candidat parce qu'un conteneur était éteint — et un candidat écarté pour
 * un fichier prétendument vérolé n'a aucun moyen de se défendre.
 *
 * `NOT_SCANNED` est conservé pour les pièces déposées **avant** que l'analyse
 * existe. Le supprimer réécrirait l'histoire : ces fichiers-là n'ont jamais été
 * examinés, et la base doit continuer de le dire. Aucune écriture nouvelle ne
 * produit cette valeur.
 *
 * **Un seul état autorise le téléchargement.** C'est la règle centrale, et elle
 * est ici plutôt que dans les trois contrôleurs qui servent des fichiers — le
 * candidat, le vérificateur et l'évaluateur. Trois `if` finiraient par diverger,
 * et celui qu'on oublierait serait le chemin par lequel un fichier vérolé
 * sortirait.
 */
enum AttachmentScanStatus: string
{
    /** Déposée avant que l'analyse antivirus existe. Historique seulement. */
    case NOT_SCANNED = 'NOT_SCANNED';

    /** Déposée, analyse en file d'attente ou en cours. */
    case PENDING = 'PENDING';

    /** Analysée, aucune menace détectée. */
    case CLEAN = 'CLEAN';

    /** Analysée, menace détectée. */
    case QUARANTINE = 'QUARANTINE';

    /** L'analyseur n'a pas pu se prononcer — panne, délai dépassé, absence. */
    case UNAVAILABLE = 'UNAVAILABLE';

    public function label(): string
    {
        return match ($this) {
            self::NOT_SCANNED => 'Jamais analysée',
            self::PENDING => 'Analyse en cours',
            self::CLEAN => 'Analysée, saine',
            self::QUARANTINE => 'En quarantaine',
            self::UNAVAILABLE => 'Analyse indisponible',
        };
    }

    /**
     * Ce que l'écran doit dire à qui bute sur un téléchargement refusé.
     *
     * Un refus sans explication se lit comme une panne, et la personne
     * réessaie. Chaque message dit donc **ce qui se passe** et **ce qui va
     * suivre** — y compris quand la suite est « rien, pour l'instant ».
     */
    public function explication(): string
    {
        return match ($this) {
            self::NOT_SCANNED => 'Cette pièce a été déposée avant la mise en service de l’analyse antivirus et n’a jamais été examinée. Elle sera analysée à la prochaine passe.',
            self::PENDING => 'Cette pièce vient d’être déposée et son analyse antivirus est en cours. Réessayez dans un instant.',
            self::CLEAN => 'Cette pièce a été analysée et ne présente pas de menace connue.',
            self::QUARANTINE => 'Cette pièce a été placée en quarantaine : l’analyse antivirus y a détecté une menace. Elle ne peut pas être téléchargée.',
            self::UNAVAILABLE => 'L’analyse antivirus de cette pièce n’a pas pu aboutir. Par précaution, le téléchargement reste fermé jusqu’à ce qu’un verdict soit rendu.',
        };
    }

    /**
     * Le téléchargement est-il autorisé ?
     *
     * **Fermé par défaut.** Seul `CLEAN` ouvre. Un fichier dont l'analyse n'a
     * pas abouti n'est pas un fichier innocent : c'est un fichier sur lequel on
     * ne sait rien, et l'ouvrir « en attendant » reviendrait à faire porter le
     * doute par la personne qui clique — un vérificateur, un évaluateur, sur
     * son poste de travail.
     */
    public function autoriseLeTelechargement(): bool
    {
        return $this === self::CLEAN;
    }

    /**
     * L'état appelle-t-il une nouvelle tentative d'analyse ?
     *
     * `QUARANTINE` non : le verdict est rendu, le relancer ne ferait que
     * redétecter la même menace. `CLEAN` non plus. Les trois autres oui — une
     * panne se répare, une file se vide, et les pièces historiques doivent
     * finir par être examinées.
     */
    public function seRejoue(): bool
    {
        return match ($this) {
            self::NOT_SCANNED, self::PENDING, self::UNAVAILABLE => true,
            self::CLEAN, self::QUARANTINE => false,
        };
    }

    /** Les états qui interdisent le téléchargement. */
    public static function bloquants(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $etat): bool => ! $etat->autoriseLeTelechargement(),
        ));
    }
}
