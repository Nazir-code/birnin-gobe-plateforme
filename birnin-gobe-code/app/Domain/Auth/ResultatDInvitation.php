<?php

namespace App\Domain\Auth;

/**
 * Ce qu'il est advenu d'une invitation — ADR-022.
 *
 * Trois états, et la distinction entre les deux derniers est celle qui compte,
 * exactement comme pour `DeliveryStatus` (ADR-018) : **« pas remis » n'est pas
 * « échoué »**. Un transport `log` ne tombe pas en panne, il n'existe pas comme
 * moyen de joindre quelqu'un. Les confondre ferait passer une configuration
 * d'environnement pour un incident, et masquerait les vraies pannes.
 */
final readonly class ResultatDInvitation
{
    public function __construct(
        /** Le lien de définition du mot de passe, toujours utilisable. */
        public string $lien,
        /** Le message est-il parti vers un destinataire réel ? */
        public bool $remise,
        /** L'envoi a-t-il levé une exception ? */
        public bool $echec,
    ) {}

    /**
     * Le lien doit-il être montré à l'administrateur ?
     *
     * Dès que personne ne l'a reçu, oui : sans transport de courriel, il est la
     * seule façon d'ouvrir le compte, et le taire rendrait la création
     * inutilisable. Avec un transport réel, le cacher est au contraire la bonne
     * réponse — un lien affiché à l'écran finit dans une capture, un ticket ou
     * un tableau blanc.
     */
    public function lienAMontrer(): bool
    {
        return ! $this->remise;
    }

    /** Le message rendu à l'administrateur, qui ne prétend jamais plus que ce qui s'est passé. */
    public function message(string $nomDuCompte, string $adresse): string
    {
        if ($this->echec) {
            return sprintf(
                'Le compte de %s est créé, mais l’invitation n’a pas pu être envoyée. Transmettez-lui le lien ci-dessous.',
                $nomDuCompte,
            );
        }

        if (! $this->remise) {
            return sprintf(
                'Le compte de %s est créé. Aucun service d’envoi de courriel n’est configuré : transmettez-lui vous-même le lien ci-dessous.',
                $nomDuCompte,
            );
        }

        return sprintf(
            'Le compte de %s est créé. Une invitation à définir son mot de passe vient de partir vers %s.',
            $nomDuCompte,
            $adresse,
        );
    }
}
