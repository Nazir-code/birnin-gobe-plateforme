<?php

namespace App\Domain\Application\Scanning;

use App\Domain\Application\AttachmentScanStatus;

/**
 * Le verdict d'une analyse antivirus — §15.1.
 *
 * Trois issues, et la troisième est celle qui compte : **l'analyseur peut ne
 * pas se prononcer**. Une interface qui ne rendrait qu'un booléen forcerait
 * l'appelant à traduire une panne en « infecté » ou en « sain », et les deux
 * traductions sont fausses — l'une accuse un candidat, l'autre ouvre la porte.
 *
 * `signature` porte le nom de la menace tel que l'analyseur l'a rendu. Il n'est
 * jamais montré au candidat : c'est une donnée d'exploitation, utile au
 * responsable qui devra dire pourquoi une pièce a été écartée, et inutilement
 * inquiétante pour qui a simplement envoyé un document infecté à son insu.
 */
final readonly class ScanVerdict
{
    private function __construct(
        public AttachmentScanStatus $status,
        public ?string $signature,
        public ?string $detail,
    ) {}

    public static function clean(): self
    {
        return new self(AttachmentScanStatus::CLEAN, null, null);
    }

    public static function infected(string $signature): self
    {
        return new self(AttachmentScanStatus::QUARANTINE, $signature, null);
    }

    /** L'analyseur n'a pas répondu, ou a répondu ce qu'on ne sait pas lire. */
    public static function unavailable(string $detail): self
    {
        return new self(AttachmentScanStatus::UNAVAILABLE, null, $detail);
    }

    public function estSain(): bool
    {
        return $this->status === AttachmentScanStatus::CLEAN;
    }

    public function estInfecte(): bool
    {
        return $this->status === AttachmentScanStatus::QUARANTINE;
    }
}
