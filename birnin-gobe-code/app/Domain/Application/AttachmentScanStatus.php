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
 * `PENDING` est aussi le **défaut de la colonne** en base. C'est le seul état
 * qui puisse l'être sans mentir : une ligne insérée sans passer par
 * `StoreApplicationDocument` attend un verdict, elle n'en a pas reçu un. Le
 * défaut d'origine était `QUARANTINE`, prudent mais faux — il faisait naître
 * chaque insertion en accusant le fichier de quelqu'un.
 *
 * **Deux questions, pas une.** Servir la pièce d'un inconnu à un vérificateur
 * est une *redistribution*, et seul `CLEAN` l'autorise. La rendre au candidat
 * qui vient de la déposer est un *aller-retour* : le fichier vient de sa
 * machine, et tout sauf la quarantaine le laisse passer. Les confondre aurait
 * fermé au candidat la relecture de son propre dossier, sans rien protéger.
 *
 * Les deux règles vivent ici plutôt que dans les trois contrôleurs qui servent
 * des fichiers. Trois `if` finiraient par diverger, et celui qu'on oublierait
 * serait le chemin par lequel un fichier vérolé sortirait.
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
     * La pièce peut-elle être servie à quelqu'un d'autre que son déposant ?
     *
     * **Fermé par défaut : seul `CLEAN` ouvre.** Un fichier dont l'analyse n'a
     * pas abouti n'est pas un fichier innocent, c'est un fichier sur lequel on
     * ne sait rien ; l'ouvrir « en attendant » ferait porter le doute par la
     * personne qui clique — un vérificateur, un évaluateur, sur son poste de
     * travail. C'est le sens de la protection : elle protège de ce que des
     * inconnus déposent.
     */
    public function autoriseLaRedistribution(): bool
    {
        return $this === self::CLEAN;
    }

    /**
     * La pièce peut-elle revenir à celui qui l'a déposée ?
     *
     * **Tout sauf la quarantaine.** Un candidat qui retélécharge son propre
     * fichier ne reçoit rien qu'il n'ait déjà : le fichier vient de sa machine,
     * et le lui rendre n'ajoute aucun risque. Lui fermer la porte n'aurait donc
     * rien protégé, et lui aurait coûté cher — sans analyseur configuré, aucun
     * candidat ne pourrait jamais relire ce qu'il a envoyé, ce qui est
     * précisément le geste qu'on fait depuis un cybercafé avant de déposer.
     *
     * `QUARANTINE` reste fermée même à son déposant. Ce n'est pas pour le
     * protéger — il a déjà le fichier — mais parce qu'une plateforme publique
     * ne sert pas un binaire dont elle sait qu'il porte une menace. Le message
     * lui dit quoi faire : redéposer une pièce saine.
     */
    public function autoriseLeRetourAuDeposant(): bool
    {
        return $this !== self::QUARANTINE;
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

    /** Les états qui interdisent de servir la pièce à un tiers. */
    public static function bloquants(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $etat): bool => ! $etat->autoriseLaRedistribution(),
        ));
    }
}
