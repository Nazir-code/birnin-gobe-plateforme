<?php

namespace App\Domain\Content;

/**
 * Les critères annoncés sur le portail public — ADR-023.
 *
 * **Cette liste n'est pas la grille de notation.** `EvaluationCriterion` porte
 * les huit critères du §11.2, avec leurs poids, et c'est sur eux que les
 * évaluateurs notent. Celle-ci est le texte que le portail présente aux
 * candidats, choisi par le porteur du concours.
 *
 * **Les deux divergent, et c'est une décision assumée**, pas un oubli. Le
 * portail annonce « Sécurité » et « Qualité technique », qui ne sont pas des
 * critères du §11.2 ; il ne mentionne ni « Viabilité économique et
 * institutionnelle » ni « Inclusion et ancrage territorial », que les
 * évaluateurs notent pourtant.
 *
 * ADR-015 avait supprimé une liste de portail distincte, précisément parce
 * qu'elle avait dérivé sans que personne le voie. La leçon retenue n'est pas
 * qu'une liste distincte est interdite — c'est qu'elle ne doit pas être
 * **invisible**. D'où cette classe, nommée pour ce qu'elle est, séparée de la
 * grille, et dont un test compare le contenu à `EvaluationCriterion` pour que
 * l'écart soit affiché plutôt que subi.
 *
 * Le jour où le comité alignera les deux, il suffira de faire lire
 * `EvaluationCriterion` au portail et de supprimer ce fichier.
 */
enum PortalCriterion: string
{
    case RELEVANCE = 'pertinence';
    case USER_IMPACT = 'impact-usager';
    case FEASIBILITY = 'faisabilite';
    case TECHNICAL_QUALITY = 'qualite-technique';
    case USEFUL_INNOVATION = 'innovation-utile';
    case SECURITY = 'securite';
    case SUSTAINABILITY = 'durabilite';
    case TEAM_AND_PITCH = 'equipe-et-pitch';

    public function label(): string
    {
        return match ($this) {
            self::RELEVANCE => 'Pertinence',
            self::USER_IMPACT => 'Impact usager',
            self::FEASIBILITY => 'Faisabilité',
            self::TECHNICAL_QUALITY => 'Qualité technique',
            self::USEFUL_INNOVATION => 'Innovation utile',
            self::SECURITY => 'Sécurité',
            self::SUSTAINABILITY => 'Durabilité',
            self::TEAM_AND_PITCH => 'Équipe et pitch',
        };
    }

    /** La question directrice affichée sous chaque intitulé. */
    public function question(): string
    {
        return match ($this) {
            self::RELEVANCE => 'La solution répond-elle précisément au défi et aux usages prioritaires ?',
            self::USER_IMPACT => 'Le bénéfice attendu est-il mesurable, utile et inclusif ?',
            self::FEASIBILITY => 'Le MVP peut-il fonctionner avec les ressources, données et délais disponibles ?',
            self::TECHNICAL_QUALITY => 'L’architecture, les performances, les données et la documentation sont-elles solides ?',
            self::USEFUL_INNOVATION => 'La proposition améliore-t-elle significativement l’existant sans complexité inutile ?',
            self::SECURITY => 'Les accès, données, sauvegardes et risques sont-ils correctement maîtrisés ?',
            self::SUSTAINABILITY => 'Maintenance, support, interopérabilité et coût total sont-ils crédibles ?',
            self::TEAM_AND_PITCH => 'L’équipe réunit-elle les compétences et la capacité d’exécution requises ?',
        };
    }

    /**
     * La liste mise en forme pour le portail.
     *
     * Même contrat que celui servi auparavant — `key`, `title`, `question` —
     * pour que le composant React n'ait pas à changer.
     *
     * @return list<array{key: string, title: string, question: string}>
     */
    public static function content(): array
    {
        return array_map(
            static fn (self $critere): array => [
                'key' => $critere->value,
                'title' => $critere->label(),
                'question' => $critere->question(),
            ],
            self::cases(),
        );
    }
}
