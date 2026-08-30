<?php

namespace App\Notifications\Evaluateur;

use App\Domain\Notification\NotificationEvent;
use App\Models\Campaign;
use App\Notifications\MessageTransactionnel;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * « Affectation » — §8.3, ligne 6.
 *
 * Contenu minimum exigé : nombre de dossiers, échéance, déclaration de conflit.
 *
 * **Un message par lot, pas un par dossier.** Le §11.1 fait affecter en lot —
 * un responsable répartit une vingtaine de dossiers d'un geste — et six
 * courriels en trois minutes se lisent comme une panne, puis se filtrent.
 *
 * **La déclaration de conflit est nommée dès le courriel**, et pas seulement à
 * l'écran. C'est le moment où l'évaluateur apprend quels dossiers lui reviennent,
 * donc le moment où il peut se rendre compte qu'il en connaît un. Attendre qu'il
 * ouvre la plateforme pour le lui dire, c'est le laisser découvrir la règle après
 * avoir lu.
 *
 * **Aucun numéro de dossier dans le message.** Un courriel voyage ; la liste des
 * dossiers confiés à quelqu'un est une information que le §11.3 protège, et elle
 * reste derrière l'authentification.
 */
final class DossiersAffectes extends MessageTransactionnel
{
    public function __construct(
        private readonly int $nombre,
        private readonly ?Campaign $campagne,
    ) {}

    public function evenement(): NotificationEvent
    {
        return NotificationEvent::ASSIGNMENT;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $cloture = $this->campagne?->closes_at;

        $message = (new MailMessage)
            ->subject($this->nombre > 1
                ? $this->nombre.' dossiers vous sont confiés — BIRNIN GOBE'
                : 'Un dossier vous est confié — BIRNIN GOBE')
            ->greeting('Bonjour,')
            ->line('**'.$this->nombre.' dossier'.($this->nombre > 1 ? 's' : '').'** vous '.($this->nombre > 1 ? 'ont' : 'a').' été confié'.($this->nombre > 1 ? 's' : '')
                .' pour évaluation'.($this->campagne !== null ? ' au titre de '.$this->campagne->name : '').'.');

        if ($cloture !== null) {
            $message->line('**Échéance** — les évaluations sont attendues avant le '
                .$cloture->timezone(config('app.timezone'))->format('d/m/Y').'.');
        } else {
            $message->line('**Échéance** — aucune date limite n’a encore été arrêtée pour cette campagne ; nous vous la communiquerons dès qu’elle le sera.');
        }

        return $message
            ->action('Ouvrir mon plan de travail', url('/evaluator/assignments'))
            ->line('**Avant de lire un dossier**, vous devrez accepter la charte, la confidentialité et la déclaration d’impartialité. Si vous avez un lien personnel, professionnel ou financier avec un dossier, **récusez-vous depuis cet écran** plutôt que de le noter : c’est un geste normal, et c’est ce qui protège la notation.')
            ->salutation('L’équipe BIRNIN GOBE');
    }
}
