<?php

namespace App\Domain\Auth;

use App\Models\User;
use App\Notifications\InvitationCompteInterne;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * Émission d'une invitation à définir son mot de passe — ADR-022.
 *
 * **Un seul chemin pour l'émission, comme pour la création.** La création d'un
 * compte et la relance d'une invitation produisent le même jeton, le même
 * message et le même lien ; les écrire à deux endroits garantirait qu'ils
 * finissent par différer.
 *
 * **Le résultat dit si quelqu'un a réellement reçu quelque chose.** C'est le
 * point qui a manqué à la première version : l'écran annonçait « une invitation
 * vient de partir » alors que `MAIL_MAILER=log` l'écrivait dans un fichier. Un
 * administrateur croyait avoir prévenu quelqu'un, l'évaluateur ne recevait
 * rien, et le compte restait inaccessible sans que rien ne le dise. Le même
 * silence qu'ADR-019 a corrigé pour les notifications — une trace qui affirme
 * un envoi que personne n'a fait.
 *
 * `lien` est donc rendu à l'appelant : sans transport de courriel, il est la
 * seule façon d'ouvrir l'accès, et le cacher rendrait la fonctionnalité
 * inutilisable dans l'environnement où elle tourne aujourd'hui.
 */
final readonly class EnvoyerInvitationInterne
{
    /**
     * Les transports qui ne remettent le message à personne.
     *
     * `log` l'écrit dans un fichier, `array` le garde en mémoire pour les
     * tests. Dans les deux cas le destinataire ne verra jamais rien, et le
     * prétendre serait mentir sur une communication.
     */
    private const TRANSPORTS_MUETS = ['log', 'array'];

    public function handle(User $destinataire): ResultatDInvitation
    {
        $jeton = Password::broker('invitations')->createToken($destinataire);

        $lien = url(route('invitation.create', [
            'token' => $jeton,
            'email' => $destinataire->email,
        ], absolute: false));

        try {
            $destinataire->notify(new InvitationCompteInterne($jeton, $destinataire->role));
        } catch (Throwable $erreur) {
            // L'échec d'envoi ne défait rien : le compte existe, le jeton est
            // valide, et le lien rendu ci-dessous ouvre l'accès. Faire échouer
            // l'appelant laisserait un administrateur devant une erreur sans
            // savoir ce qui a été fait.
            Log::error('Invitation d’un compte interne non partie.', [
                'user' => $destinataire->getKey(),
                'message' => $erreur->getMessage(),
            ]);

            return new ResultatDInvitation($lien, remise: false, echec: true);
        }

        return new ResultatDInvitation($lien, remise: ! in_array(
            (string) config('mail.default'),
            self::TRANSPORTS_MUETS,
            strict: true,
        ), echec: false);
    }
}
