<?php

namespace App\Domain\Application;

/**
 * Les neuf sections du formulaire de candidature.
 *
 * Comme `ApplicationStatus`, les valeurs persistées sont des clés stables en
 * anglais : `current_step` et `application_sections.section` les référencent,
 * et un changement de libellé ou de numérotation ne doit réécrire aucune ligne.
 *
 * L'ordre des cas est celui du cahier des charges §5.2 — éligibilité, profil,
 * structure/équipe, défi, solution, impact, plan, pièces, relecture — et non
 * l'ordre dans lequel les sections ont été développées.
 *
 * Deux notions distinctes, qu'il ne faut pas confondre :
 *
 *   `isImplemented()`  la section a un écran, des champs et une validation ;
 *   `isOnOpenPath()`   la section est de surcroît atteignable en suivant le
 *                      parcours depuis l'étape 1, sans sauter d'étape.
 *
 * Les deux notions coïncident tant qu'aucune section n'est développée en avance
 * sur celles qui la précèdent — c'est le cas depuis l'ouverture de l'étape 3, et
 * ce l'est encore avec les étapes 5 à 7. Elles restent néanmoins distinctes :
 * « Défi » a vécu développée mais hors parcours, derrière une étape 3 qui ne
 * l'était pas, et rien n'interdit que cela se reproduise. Voir ADR-009.
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
     * Section que le produit propose réellement au candidat.
     *
     * Les neuf y sont désormais. Les huit premières parce qu'elles ont un
     * écran, des champs et une validation ; la neuvième parce qu'elle a un
     * écran — sa route, `ReviewController` et `Review.tsx` sont livrés.
     *
     * **« Implementée » ne veut pas dire « persistée ».** « Relecture / envoi »
     * n'écrit aucune ligne dans `application_sections`, et ne doit jamais en
     * écrire : c'est une projection en lecture de ce que les huit précédentes
     * ont enregistré. Elle est listée ici parce que cette méthode répond à
     * « le candidat peut-il y aller ? », pas à « a-t-il quelque chose à y
     * remplir ? ».
     *
     * Elle en était absente, et l'oubli se voyait à l'écran : `steps()`
     * annonçait l'étape 9 comme indisponible, et `nextOnOpenPath()` rendait
     * `null` depuis l'étape 8 — un dossier recevable n'avait plus de bouton
     * « Suivant » et le parcours s'arrêtait net, alors que la route répondait
     * 200. La branche qui a livré l'étape 9 n'a pas touché ce fichier, et la
     * fusion ne pouvait pas le signaler.
     *
     * Ce que l'ajout ne change pas, et qui a été vérifié : la progression, qui
     * compte des `completed_at` et n'en trouvera jamais pour « Relecture » ; et
     * la recevabilité, dont `SubmissionReadiness::requiredSections()` exclut
     * explicitement cette section.
     */
    public function isImplemented(): bool
    {
        return in_array($this, [
            self::ELIGIBILITY,
            self::PROFILE,
            self::TEAM,
            self::CHALLENGE,
            self::SOLUTION,
            self::IMPACT,
            self::IMPLEMENTATION,
            self::ATTACHMENTS,
            self::REVIEW,
        ], strict: true);
    }

    /**
     * Section atteignable sans sauter d'étape depuis l'étape 1.
     *
     * Le parcours s'arrête à la première étape non développée : aujourd'hui
     * « Relecture / envoi » (étape 9). Les huit premières sont donc toutes sur
     * le parcours ouvert.
     *
     * C'est ce qui empêche l'écran d'annoncer un parcours que le produit ne
     * tient pas, et la progression de compter une étape que le candidat n'aurait
     * pas dû pouvoir atteindre en suivant le fil normal.
     */
    public function isOnOpenPath(): bool
    {
        foreach (self::cases() as $section) {
            if ($section->position() > $this->position()) {
                break;
            }

            if (! $section->isImplemented()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Étape suivante du parcours ouvert, ou `null` si le parcours s'arrête ici.
     *
     * Sert au bouton « Suivant ». Renvoyer `null` est une réponse honnête : la
     * suite n'existe pas encore, et sauter par-dessus une étape non développée
     * ferait croire au candidat qu'il a terminé ce qu'il n'a pas commencé.
     */
    public function nextOnOpenPath(): ?self
    {
        foreach (self::cases() as $section) {
            if ($section->position() > $this->position()) {
                return $section->isOnOpenPath() ? $section : null;
            }
        }

        return null;
    }

    /**
     * Étape développée précédente, quelle que soit sa place dans le parcours.
     *
     * Sert au bouton « Précédent ». On remonte vers une section *développée* et
     * non vers le parcours ouvert : depuis « Défi », qui vit hors parcours, le
     * candidat doit tout de même pouvoir revenir à « Profil ».
     */
    public function previousImplemented(): ?self
    {
        $precedentes = array_filter(
            self::cases(),
            fn (self $section): bool => $section->position() < $this->position() && $section->isImplemented(),
        );

        return $precedentes === [] ? null : end($precedentes);
    }

    /**
     * Les sections que la progression a le droit de compter.
     *
     * @return list<self>
     */
    public static function openPath(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $section): bool => $section->isOnOpenPath(),
        ));
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

    /**
     * Point d'entrée du formulaire.
     *
     * C'est aussi, par construction, la première section du parcours ouvert :
     * si l'étape 1 n'était pas développée, aucune ne serait atteignable.
     */
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
