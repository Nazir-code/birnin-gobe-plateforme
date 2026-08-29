<?php

namespace App\Domain\Application\Scanning;

/**
 * Le contrat d'un analyseur antivirus — §15.1.
 *
 * L'interface existe pour une raison précise : **la suite de tests ne doit pas
 * dépendre d'un conteneur ClamAV**. Les règles qu'on veut protéger — un fichier
 * infecté ne se télécharge pas, une panne d'analyseur n'accuse personne — sont
 * des règles applicatives, et les vérifier en fabriquant un vrai virus de test
 * ferait dépendre la CI d'une base de signatures à jour.
 *
 * La méthode reçoit **le contenu**, pas un chemin. L'analyseur peut tourner
 * dans un autre conteneur, qui ne voit pas le disque de l'application : lui
 * passer un chemin ne marcherait qu'en développement, et échouerait
 * silencieusement en production — le pire des deux mondes.
 */
interface VirusScanner
{
    /**
     * Analyse un contenu et rend un verdict.
     *
     * Ne lève jamais : une panne d'analyseur est un verdict
     * (`ScanVerdict::unavailable()`), pas une exception. C'est ce qui permet à
     * l'appelant de traiter l'indisponibilité comme un état durable — une pièce
     * qu'on réessaiera — plutôt que comme un incident à faire remonter.
     */
    public function scan(string $contenu): ScanVerdict;
}
