<?php

namespace App\Domain\Application;

/**
 * Les neuf sections du formulaire de candidature.
 *
 * Comme `ApplicationStatus`, les valeurs persistées sont des clés stables en
 * anglais : `current_step` et `application_sections.section` les référencent,
 * et un changement de libellé ou de numérotation ne doit réécrire aucune ligne.
 *
 * `isImplemented()` dit la vérité sur l'état du produit : seules les sections
 * réellement branchées sur la base sont ouvertes à l'édition. Les autres sont
 * affichées, verrouillées, et ne comptent pas comme faites.
 */
enum ApplicationSection: string
{
    case ELIGIBILITY = 'eligibility';
    case PROFILE = 'profile';
    case TEAM = 'team';
    case CHALLENGE = 'challenge';
    case SOLUTION = 'solution';
    case IMPACT = 'impact';
    case IMPLEMENTATION = 'implementation';
    case ATTACHMENTS = 'attachments';
    case REVIEW = 'review';

    /** Libellé d'affichage. Jamais persisté, jamais comparé. */
    public function label(): string
    {
        return match ($this) {
            self::ELIGIBILITY => 'Éligibilité',
            self::PROFILE => 'Profil',
            self::TEAM => 'Structure / équipe',
            self::CHALLENGE => 'Défi',
            self::SOLUTION => 'Solution',
            self::IMPACT => 'Impact / viabilité',
            self::IMPLEMENTATION => 'Plan de mise en œuvre',
            self::ATTACHMENTS => 'Pièces / déclarations',
            self::REVIEW => 'Relecture / envoi',
        };
    }

    /**
     * Section effectivement persistée aujourd'hui.
     *
     * Deux sections sont branchees : « Eligibilite » (etape 1) et « Defi »
     * (etape 4). Ouvrir une autre section, c'est l'ajouter ici en meme temps
     * que son ecran et sa validation — pas avant.
     */
    public function isImplemented(): bool
    {
        return in_array($this, [self::ELIGIBILITY, self::CHALLENGE], strict: true);
    }

    /**
     * Section ouverte suivante, dans l'ordre metier.
     *
     * Sert au bouton « Suivant » : il ne doit mener qu'a un ecran qui existe.
     * Les sections non developpees sont sautees, pas simulees.
     */
    public function nextImplemented(): ?self
    {
        $suivantes = array_filter(
            self::cases(),
            fn (self $section): bool => $section->position() > $this->position() && $section->isImplemented(),
        );

        return $suivantes === [] ? null : reset($suivantes);
    }

    /** Rang affiché au candidat, de 1 à 9. */
    public function position(): int
    {
        return array_search($this, self::cases(), strict: true) + 1;
    }

    public static function total(): int
    {
        return count(self::cases());
    }

    /** Point d'entrée du formulaire : la première section ouverte à l'édition. */
    public static function firstImplemented(): self
    {
        foreach (self::cases() as $section) {
            if ($section->isImplemented()) {
                return $section;
            }
        }

        throw new \LogicException('Aucune section de candidature n’est implémentée.');
    }
}
