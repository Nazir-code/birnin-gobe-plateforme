<?php

namespace App\Domain\Auth;

/**
 * Rôles applicatifs.
 *
 * Les valeurs stockées sont des identifiants stables en anglais, jamais des
 * libellés français : un libellé change avec la traduction, un rôle non.
 * Même contrat que `ApplicationStatus` pour les statuts métier.
 *
 * La séparation des espaces (ADR-003) s'appuie sur cet enum : chaque espace
 * n'est accessible qu'aux rôles listés par son middleware.
 */
enum UserRole: string
{
    case CANDIDATE = 'candidate';
    case ADMIN = 'admin';
    case EVALUATOR = 'evaluator';
    case JURY = 'jury';

    /**
     * Seul rôle que l'inscription publique peut produire.
     *
     * Les comptes internes sont provisionnés séparément : aucun formulaire
     * public ne doit permettre de devenir admin, évaluateur ou jury.
     */
    public const PUBLIC_SIGNUP = self::CANDIDATE;

    /** Libellé d'affichage. Jamais persisté, jamais comparé. */
    public function label(): string
    {
        return match ($this) {
            self::CANDIDATE => 'Candidat',
            self::ADMIN => 'Administrateur',
            self::EVALUATOR => 'Évaluateur',
            self::JURY => 'Jury',
        };
    }
}
