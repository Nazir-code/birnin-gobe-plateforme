<?php

namespace App\Domain\Application;

use App\Models\Campaign;
use Illuminate\Support\Facades\DB;

/**
 * Attribution du numéro officiel de dépôt.
 *
 * Format : `BG-2026-000001` — le sigle du concours, l'année de l'édition, puis
 * un rang sur six chiffres. Lisible au téléphone, recherchable, et suffisamment
 * large pour une édition nationale.
 *
 * L'année est celle de **l'édition**, pas celle du clic. Une campagne qui
 * ouvrirait en décembre pour clore en janvier délivrerait sinon deux préfixes
 * pour un même concours, et le numéro cesserait de désigner une édition.
 *
 * Le rang vient d'une séquence PostgreSQL, jamais d'un `MAX(...) + 1` : voir la
 * migration `create_submission_number_sequence` pour le pourquoi. La séquence
 * est unique pour toute la plateforme et non par édition — deux éditions ne
 * peuvent donc pas produire le même numéro même si leurs années coïncidaient.
 */
final readonly class SubmissionNumber
{
    private const SEQUENCE = 'application_submission_numbers';

    private const PREFIXE = 'BG';

    public static function next(Campaign $campaign): string
    {
        return sprintf('%s-%d-%06d', self::PREFIXE, self::anneeDeLEdition($campaign), self::rang());
    }

    /**
     * Le rang suivant, servi par PostgreSQL.
     *
     * Le nom de la séquence est une constante de cette classe : rien de ce qui
     * vient d'une requête n'entre dans ce SQL.
     */
    private static function rang(): int
    {
        return (int) DB::selectOne('SELECT nextval(\''.self::SEQUENCE.'\') AS rang')->rang;
    }

    /**
     * Année de référence de l'édition, lue dans son propre fuseau.
     *
     * On prend l'ouverture — c'est elle qui date une édition. À défaut la
     * clôture, à défaut la création de la campagne : une édition sans calendrier
     * ne doit pas empêcher un dépôt d'aboutir, elle doit juste porter une année
     * plausible.
     */
    private static function anneeDeLEdition(Campaign $campaign): int
    {
        $fuseau = $campaign->timezone ?: config('app.timezone');
        $reference = $campaign->opens_at ?? $campaign->closes_at ?? $campaign->created_at;

        return (int) $reference->setTimezone($fuseau)->format('Y');
    }
}
