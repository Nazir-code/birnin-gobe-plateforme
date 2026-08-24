<?php

namespace App\Http\Requests\Candidate;

use App\Domain\Application\AttachmentsSection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation serveur des déclarations de l'étape 8.
 *
 * Une case cochée dans le navigateur n'est pas une déclaration : c'est un pixel.
 * Ce qui engage le candidat est la valeur booléenne que cette classe accepte et
 * que `SaveApplicationSection` écrit — et elle seule est relue par
 * `AttachmentsSection::isComplete()` puis par `SubmissionReadiness`.
 *
 * Seuls les six champs déclarés par `AttachmentsSection` entrent en base. Un
 * champ inconnu glissé dans la charge utile est ignoré ; en particulier, les
 * pièces ne passent pas par ici — elles ont leur propre route et leur propre
 * validation de fichier.
 *
 * L'autorisation n'est pas refaite ici : elle est portée par `role:candidate`,
 * `can:update,application` et le middleware `eligible` sur la route.
 */
final class SaveAttachmentsSectionRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return AttachmentsSection::rules();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $libelles = [];

        foreach (AttachmentsSection::fields() as $declaration) {
            $libelles[$declaration] = AttachmentsSection::label($declaration);
        }

        return $libelles;
    }

    /**
     * Déclarations normalisées, prêtes à être persistées.
     *
     * Trois états possibles à l'écran — cochée, décochée, jamais vue — et deux
     * seulement en base : `true` ou `false`. « Pas encore lue » et « refusée »
     * se valent pour la complétude (aucune des deux n'autorise le dépôt), et
     * garder trois valeurs pour deux sens finirait par diverger.
     *
     * @return array<string, bool>
     */
    public function answers(): array
    {
        $valide = $this->validated();

        $declarations = [];

        foreach (AttachmentsSection::fields() as $declaration) {
            $declarations[$declaration] = ($valide[$declaration] ?? false) === true;
        }

        return $declarations;
    }
}
