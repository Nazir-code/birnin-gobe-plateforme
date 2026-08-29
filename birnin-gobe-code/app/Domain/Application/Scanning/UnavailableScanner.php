<?php

namespace App\Domain\Application\Scanning;

/**
 * L'analyseur qu'on obtient quand aucun analyseur n'est configuré — §15.1.
 *
 * **Il ne rend jamais « sain ».** C'est toute sa raison d'être. L'absence
 * d'implémentation aurait pu se traduire de trois façons : lever une exception
 * (chaque dépôt casse), rendre `clean()` (le produit ment sur des fichiers que
 * personne n'a lus), ou rendre `unavailable()`. Seule la troisième dit la
 * vérité, et elle a la bonne conséquence : les pièces restent fermées au
 * téléchargement, et l'alerte du §9.3 les compte.
 *
 * Un environnement sans ClamAV reste donc parfaitement utilisable — on dépose,
 * on remplace, on relit son dossier — mais personne n'y télécharge une pièce
 * qu'aucun antivirus n'a vue. C'est le comportement qu'on veut aussi le jour où
 * le conteneur tombe en production.
 */
final readonly class UnavailableScanner implements VirusScanner
{
    public function __construct(private string $raison) {}

    public function scan(string $contenu): ScanVerdict
    {
        return ScanVerdict::unavailable($this->raison);
    }
}
