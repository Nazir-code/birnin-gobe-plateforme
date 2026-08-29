<?php

namespace App\Domain\Application\Scanning;

use Throwable;

/**
 * Analyseur ClamAV, par le protocole `INSTREAM` de `clamd` — §15.1.
 *
 * **Pourquoi parler à `clamd` en TCP plutôt qu'appeler `clamscan`.** Le binaire
 * charge la base de signatures à chaque exécution : plusieurs secondes et
 * plusieurs centaines de mégaoctets de mémoire, par fichier. Le démon la garde
 * en mémoire et répond en millisecondes. Sur un dépôt de campagne, la
 * différence n'est pas un confort, c'est la faisabilité.
 *
 * **`INSTREAM` plutôt que `SCAN <chemin>`.** `clamd` tourne dans son propre
 * conteneur et ne voit pas le disque de l'application ; lui donner un chemin
 * fonctionnerait sur une machine de développement où tout partage un volume, et
 * échouerait en production. Le contenu voyage donc par la socket, découpé en
 * blocs préfixés de leur taille, terminés par un bloc de taille zéro.
 *
 * **Aucune exception ne sort d'ici.** Une socket fermée, un délai dépassé, une
 * réponse illisible : tout devient `ScanVerdict::unavailable()`. C'est ce qui
 * permet à l'appelant de traiter une panne comme un état de la pièce — à
 * réessayer — plutôt que comme un incident. Le seul comportement interdit
 * serait de rendre `clean()` faute de mieux.
 *
 * **La limite de taille est celle de `clamd`, et elle est vérifiée avant
 * l'envoi.** Dépasser `StreamMaxLength` fait fermer la connexion par le démon
 * au milieu du transfert, ce qui se lit comme une panne réseau ; le dire
 * d'avance donne un message exploitable.
 */
final readonly class ClamAvScanner implements VirusScanner
{
    /** Taille des blocs `INSTREAM`. 64 Kio : le compromis usuel de clamd. */
    private const CHUNK = 65_536;

    public function __construct(
        private string $host,
        private int $port,
        private int $timeout,
        /** Doit rester sous le `StreamMaxLength` de clamd (25 Mio par défaut). */
        private int $maxBytes,
    ) {}

    public function scan(string $contenu): ScanVerdict
    {
        $taille = strlen($contenu);

        if ($taille === 0) {
            // Un fichier vide n'a rien à analyser, et clamd le refuserait.
            return ScanVerdict::clean();
        }

        if ($taille > $this->maxBytes) {
            return ScanVerdict::unavailable(
                sprintf('Fichier de %d octets, au-delà de la limite d’analyse de %d.', $taille, $this->maxBytes),
            );
        }

        $socket = @fsockopen($this->host, $this->port, $code, $message, $this->timeout);

        if ($socket === false) {
            return ScanVerdict::unavailable(sprintf('clamd injoignable sur %s:%d — %s', $this->host, $this->port, $message ?: 'erreur inconnue'));
        }

        try {
            stream_set_timeout($socket, $this->timeout);

            return $this->dialoguer($socket, $contenu, $taille);
        } catch (Throwable $erreur) {
            return ScanVerdict::unavailable($erreur->getMessage());
        } finally {
            @fclose($socket);
        }
    }

    /**
     * @param  resource  $socket
     */
    private function dialoguer($socket, string $contenu, int $taille): ScanVerdict
    {
        // Le `z` demande une réponse terminée par un octet nul plutôt que par
        // un saut de ligne : c'est la forme que clamd documente comme sûre,
        // parce qu'un nom de menace peut contenir un saut de ligne.
        if (@fwrite($socket, "zINSTREAM\0") === false) {
            return ScanVerdict::unavailable('Écriture impossible sur la socket clamd.');
        }

        for ($offset = 0; $offset < $taille; $offset += self::CHUNK) {
            $bloc = substr($contenu, $offset, self::CHUNK);

            if (@fwrite($socket, pack('N', strlen($bloc)).$bloc) === false) {
                return ScanVerdict::unavailable('Transfert interrompu vers clamd.');
            }
        }

        // Bloc de taille zéro : fin du flux.
        @fwrite($socket, pack('N', 0));

        $reponse = '';

        while (! feof($socket)) {
            $morceau = @fread($socket, 4096);

            if ($morceau === false || $morceau === '') {
                break;
            }

            $reponse .= $morceau;
        }

        if (stream_get_meta_data($socket)['timed_out'] ?? false) {
            return ScanVerdict::unavailable('Délai dépassé en attendant la réponse de clamd.');
        }

        return $this->lire(trim($reponse, " \t\n\r\0"));
    }

    /**
     * Traduit la réponse de clamd.
     *
     * Trois formes attendues : `stream: OK`, `stream: <signature> FOUND`, et
     * `... ERROR`. Toute autre réponse est traitée comme une indisponibilité
     * plutôt que devinée — se tromper ici accuserait un candidat ou ouvrirait
     * la porte.
     */
    private function lire(string $reponse): ScanVerdict
    {
        if ($reponse === '') {
            return ScanVerdict::unavailable('Réponse vide de clamd.');
        }

        if (str_ends_with($reponse, 'OK')) {
            return ScanVerdict::clean();
        }

        if (str_ends_with($reponse, 'FOUND')) {
            // « stream: Eicar-Test-Signature FOUND » → la signature au milieu.
            $signature = trim(substr($reponse, strpos($reponse, ':') + 1, -strlen(' FOUND')));

            return ScanVerdict::infected($signature !== '' ? $signature : 'menace non nommée');
        }

        return ScanVerdict::unavailable('Réponse inattendue de clamd : '.$reponse);
    }
}
