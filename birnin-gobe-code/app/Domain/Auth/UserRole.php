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
    /**
     * L'écran de connexion de l'espace auquel ce rôle appartient — ADR-022.
     *
     * **Porté par le rôle, et non par chaque contrôleur.** Deux parcours
     * rendent la main après avoir défini un mot de passe — l'invitation et la
     * réinitialisation — et chacun doit renvoyer la personne là où elle peut
     * réellement se connecter. Recopier la correspondance dans les deux
     * garantirait qu'elles divergent le jour où un espace s'ajoute.
     *
     * Le jury n'a pas encore d'accès interne : il retombe sur l'écran candidat,
     * qui ne le connectera pas — mais il n'a pas non plus de compte, donc le cas
     * ne se produit pas. Il se corrigera de lui-même quand le §12 existera.
     */
    public function routeDeConnexion(): string
    {
        return match ($this) {
            self::ADMIN => 'admin.login',
            self::EVALUATOR => 'evaluator.login',
            self::CANDIDATE, self::JURY => 'login',
        };
    }

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
