<?php

namespace App\Domain\Evaluation;

/**
 * État d'une affectation de dossier à un évaluateur — §11.1.
 *
 * Quatre états, et deux d'entre eux sortent l'affectation de la couverture du
 * dossier. La distinction entre `WITHDRAWN` et `CONFLICT` compte : les deux
 * libèrent le dossier, mais le §11.1 demande que l'algorithme « tienne compte
 * des conflits déclarés ». Un retrait de convenance peut être refait demain ;
 * un conflit interdit durablement de reproposer ce dossier à cet évaluateur.
 * Les confondre reviendrait à réaffecter un dossier à quelqu'un qui s'en est
 * récusé.
 *
 * `ACCEPTED` correspond à la charte du §11.1 — « avant d'accéder à un dossier,
 * chaque évaluateur accepte la charte, la confidentialité et la déclaration
 * d'impartialité ». Cet incrément **ne câble pas** ce geste : il appartient à
 * l'espace évaluateur, qui n'a pas encore d'écran réel. L'état existe parce que
 * l'administration doit pouvoir distinguer « affecté » de « pris en charge »,
 * et parce que l'inventer plus tard obligerait à réécrire les lignes déjà
 * posées.
 */
enum AssignmentStatus: string
{
    /** Affecté, charte non encore acceptée. */
    case ASSIGNED = 'ASSIGNED';

    /** Charte et déclaration d'impartialité acceptées. */
    case ACCEPTED = 'ACCEPTED';

    /** Retiré par le responsable. Le dossier redevient affectable à cette personne. */
    case WITHDRAWN = 'WITHDRAWN';

    /** Conflit déclaré. Ce dossier ne doit plus être proposé à cet évaluateur. */
    case CONFLICT = 'CONFLICT';

    public function label(): string
    {
        return match ($this) {
            self::ASSIGNED => 'Affecté',
            self::ACCEPTED => 'Pris en charge',
            self::WITHDRAWN => 'Retiré',
            self::CONFLICT => 'Conflit déclaré',
        };
    }

    /** L'affectation compte-t-elle dans la couverture du dossier ? */
    public function compteDansLaCouverture(): bool
    {
        return match ($this) {
            self::ASSIGNED, self::ACCEPTED => true,
            self::WITHDRAWN, self::CONFLICT => false,
        };
    }

    /**
     * Les états qui occupent encore l'évaluateur.
     *
     * @return list<string>
     */
    public static function enVigueur(): array
    {
        return [self::ASSIGNED->value, self::ACCEPTED->value];
    }
}
