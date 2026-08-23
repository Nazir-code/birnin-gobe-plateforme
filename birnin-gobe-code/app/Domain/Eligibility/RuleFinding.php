<?php

namespace App\Domain\Eligibility;

/**
 * Verdict d'une règle, avec son explication.
 *
 * Le message est produit ici, avec le serveur qui décide — pas côté React. Le
 * cahier des charges demande une « explication en cas de risque d'inéligibilité »
 * (§5.2) : cette explication doit venir de la règle qui a tranché, sinon elle
 * finit par décrire autre chose que ce qui s'est réellement passé.
 */
final readonly class RuleFinding
{
    public function __construct(
        public EligibilityRule $rule,
        public RuleStatus $status,
        public string $message,
    ) {}

    public static function unanswered(EligibilityRule $rule, string $message): self
    {
        return new self($rule, RuleStatus::UNANSWERED, $message);
    }

    public static function notConfigured(EligibilityRule $rule, string $message): self
    {
        return new self($rule, RuleStatus::NOT_CONFIGURED, $message);
    }

    public static function satisfied(EligibilityRule $rule, string $message): self
    {
        return new self($rule, RuleStatus::SATISFIED, $message);
    }

    public static function blocking(EligibilityRule $rule, string $message): self
    {
        return new self($rule, RuleStatus::BLOCKING, $message);
    }

    /** @return array{rule: string, label: string, status: string, message: string} */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule->value,
            'label' => $this->rule->label(),
            'status' => $this->status->value,
            'message' => $this->message,
        ];
    }
}
