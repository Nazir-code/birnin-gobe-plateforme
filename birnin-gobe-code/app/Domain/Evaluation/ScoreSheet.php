<?php

namespace App\Domain\Evaluation;

/**
 * Une feuille de notes, et ce qu'on peut en dire — §11.2, §11.3.
 *
 * Objet de valeur, sans base de données : il est construit aussi bien depuis
 * les lignes enregistrées (pour afficher le total courant) que depuis une
 * saisie qui n'a pas encore été écrite (pour décider si le verrouillage est
 * permis). C'est ce qui garantit que **le total affiché pendant la saisie et le
 * total enregistré au verrouillage sortent du même calcul** : les recalculer
 * séparément, une fois en TypeScript pour l'écran et une fois en PHP pour la
 * base, ferait diverger le chiffre que l'évaluateur a vu de celui qu'il a signé.
 *
 * Trois questions, et une seule règle chacune :
 *
 *  - `complete()` — les huit critères sont-ils notés ? `null` n'est pas zéro.
 *  - `total()` — le score sur 100, ou `null` tant que la feuille est incomplète.
 *    Un total partiel serait lu comme une note faible alors qu'il ne dit que
 *    « pas fini », et c'est exactement le malentendu qui ferait écarter un bon
 *    dossier.
 *  - `extremesSansJustification()` — les notes 0 et 5 laissées sans commentaire,
 *    que le §11.3 interdit.
 */
final readonly class ScoreSheet
{
    /**
     * @param  array<string, array{score: ?int, comment: ?string}>  $lignes  indexé par critère
     */
    private function __construct(private array $lignes) {}

    /**
     * @param  array<string, array{score: ?int, comment: ?string}>  $lignes
     */
    public static function make(array $lignes): self
    {
        $normalisees = [];

        foreach (EvaluationCriterion::cases() as $critere) {
            $ligne = $lignes[$critere->value] ?? null;
            $note = $ligne['score'] ?? null;
            $commentaire = trim((string) ($ligne['comment'] ?? ''));

            $normalisees[$critere->value] = [
                'score' => is_int($note) ? $note : null,
                'comment' => $commentaire === '' ? null : $commentaire,
            ];
        }

        return new self($normalisees);
    }

    public function score(EvaluationCriterion $critere): ?int
    {
        return $this->lignes[$critere->value]['score'];
    }

    public function comment(EvaluationCriterion $critere): ?string
    {
        return $this->lignes[$critere->value]['comment'];
    }

    /**
     * Les critères encore sans note.
     *
     * @return list<EvaluationCriterion>
     */
    public function manquants(): array
    {
        return array_values(array_filter(
            EvaluationCriterion::cases(),
            fn (EvaluationCriterion $critere): bool => $this->score($critere) === null,
        ));
    }

    public function complete(): bool
    {
        return $this->manquants() === [];
    }

    /**
     * Le score pondéré sur 100, ou `null` si la feuille est incomplète.
     *
     * Arrondi une seule fois, à la fin : arrondir chaque critère avant de
     * sommer ferait dériver le total de plusieurs dixièmes, et deux dossiers
     * séparés par un dixième ne se départagent pas sur une erreur d'arrondi.
     */
    public function total(): ?float
    {
        if (! $this->complete()) {
            return null;
        }

        $total = 0.0;

        foreach (EvaluationCriterion::cases() as $critere) {
            $total += $critere->weightedScore((int) $this->score($critere));
        }

        return round($total, 2);
    }

    /**
     * Les notes extrêmes laissées sans justification — §11.3.
     *
     * @return list<EvaluationCriterion>
     */
    public function extremesSansJustification(): array
    {
        return array_values(array_filter(
            EvaluationCriterion::cases(),
            function (EvaluationCriterion $critere): bool {
                $note = $this->score($critere);

                if ($note === null) {
                    return false; // Pas encore noté : ce n'est pas ce défaut-là.
                }

                return ScoreAnchor::from($note)->estExtreme() && $this->comment($critere) === null;
            },
        ));
    }

    /**
     * La forme persistée, une ligne par critère.
     *
     * @return array<string, array{score: ?int, comment: ?string}>
     */
    public function toArray(): array
    {
        return $this->lignes;
    }
}
