<?php

namespace App\Domain\Application;

use App\Domain\Candidate\CandidateType;
use App\Domain\Reference\NigerRegion;
use App\Models\Application;
use App\Models\ApplicationSectionAnswers;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * La requête de la liste des candidatures de l'administration.
 *
 * Objet de requête plutôt que méthode de contrôleur : recherche, filtres, tri,
 * chargement anticipé et pagination se tiennent — un filtre ajouté sans son
 * chargement anticipé transforme la page en N+1, un tri ajouté sans sa liste
 * blanche transforme un paramètre d'URL en fragment de SQL. Les avoir sous les
 * yeux ensemble est ce qui rend ces deux fautes visibles.
 *
 * Trois partis pris qui gouvernent la classe :
 *
 * 1. **Tout se filtre et se trie dans PostgreSQL**, jamais après pagination.
 *    Filtrer en PHP une page déjà découpée rendrait des pages de tailles
 *    variables et un total faux : le compte annoncé ne correspondrait plus aux
 *    lignes affichées.
 *
 * 2. **Le tri est une liste blanche**, jamais une colonne venue de l'URL.
 *    `sort=nom` désigne une intention, pas un nom de colonne.
 *
 * 3. **Le verdict d'éligibilité ne filtre pas.** Il se déduit des réponses, des
 *    paramètres de la campagne et des cinq règles d'ADR-007 ; l'exprimer en SQL
 *    reviendrait à en écrire une seconde version, qui divergerait de la
 *    première. Il est donc calculé pour les lignes visibles seulement — voir
 *    `AdminApplicationPresenter` — et n'apparaît pas parmi les filtres.
 *
 * Ce que la liste **ne fait pas** : charger les réponses des sections « Profil »
 * et « Défi ». Seule « Éligibilité » est chargée, parce que le type de
 * candidature, la zone et le verdict en viennent. Une liste n'a pas à
 * transporter le téléphone et le lieu de naissance de vingt-cinq personnes.
 */
final readonly class ApplicationIndexQuery
{
    /** Assez pour une vue d'exploitation, assez peu pour rester lisible. */
    public const PER_PAGE = 25;

    /** Tris proposés. Toute autre valeur retombe sur `recent`. */
    public const SORTS = ['recent', 'ancien', 'nom', 'progression'];

    public function __construct(
        public ?int $campaignId = null,
        public ?ApplicationStatus $status = null,
        public ?CandidateType $candidateType = null,
        public ?NigerRegion $region = null,
        public ?ProjectTheme $theme = null,
        public ?string $search = null,
        public string $sort = 'recent',
    ) {}

    /** @return LengthAwarePaginator<int, Application> */
    public function paginate(): LengthAwarePaginator
    {
        return $this->builder()
            ->paginate(self::PER_PAGE)
            // Les filtres survivent au changement de page : sans cela, la page 2
            // rendrait la liste entière et l'administrateur croirait à un bug.
            ->withQueryString();
    }

    /** Nombre total de candidatures, filtres ignorés — sert à distinguer les deux vides. */
    public static function total(): int
    {
        return Application::query()->count();
    }

    /** @return Builder<Application> */
    private function builder(): Builder
    {
        $requete = Application::query()
            ->with([
                // Le strict nécessaire à l'affichage d'une ligne : pas de mot de
                // passe, pas de jeton de session.
                'candidate:id,name,email',
                'campaign',
                // Une seule section chargée, celle dont la liste a besoin.
                'sections' => fn ($q) => $q->where('section', ApplicationSection::ELIGIBILITY->value),
            ])
            // Le compte des sections achevées du parcours ouvert, en une seule
            // requête pour toute la page, avec la règle du candidat.
            ->withCount(['sections as completed_sections_count' => ApplicationProgress::countConstraint()])
            // La thématique, extraite par une sous-requête scalaire plutôt qu'en
            // chargeant la section « Défi » entière : la liste l'affiche, mais
            // n'a que faire des trois récits de cinq cents caractères qui
            // l'accompagnent. Une sous-requête, aucune requête supplémentaire
            // par ligne.
            ->addSelect(['project_theme' => ApplicationSectionAnswers::query()
                ->selectRaw("answers->>'".ChallengeSection::THEME_FIELD."'")
                ->whereColumn('application_id', 'applications.id')
                ->where('section', ApplicationSection::CHALLENGE->value)
                ->limit(1),
            ]);

        $this->appliquerFiltres($requete);
        $this->appliquerRecherche($requete);
        $this->appliquerTri($requete);

        return $requete;
    }

    /** @param Builder<Application> $requete */
    private function appliquerFiltres(Builder $requete): void
    {
        $requete
            ->when($this->campaignId !== null, fn (Builder $q) => $q->where('campaign_id', $this->campaignId))
            ->when($this->status !== null, fn (Builder $q) => $q->where('status', $this->status->value))
            ->when(
                $this->candidateType !== null,
                fn (Builder $q) => $this->filtrerSurUneReponse($q, EligibilitySection::CANDIDATE_TYPE, $this->candidateType->value),
            )
            ->when(
                $this->region !== null,
                fn (Builder $q) => $this->filtrerSurUneReponse($q, EligibilitySection::INTERVENTION_REGION, $this->region->value),
            )
            // La thématique vit dans la section « Défi », pas dans l'étape 1 :
            // c'est une propriété du projet, pas une condition d'éligibilité.
            ->when(
                $this->theme !== null,
                fn (Builder $q) => $this->filtrerSurUneReponse(
                    $q,
                    ChallengeSection::THEME_FIELD,
                    $this->theme->value,
                    ApplicationSection::CHALLENGE,
                ),
            );
    }

    /**
     * Filtre sur une réponse d'une section, par défaut « Éligibilité ».
     *
     * Le type de candidature, la zone d'intervention et la thématique n'ont pas
     * de colonne : ce sont des réponses, rangées dans le `jsonb` de leur
     * section. Le filtre est donc porté par PostgreSQL —
     * `answers->>'champ' = ?` — à l'intérieur d'un `EXISTS` que l'index unique
     * `(application_id, section)` sert directement.
     *
     * Un dossier qui n'a pas encore la section visée ne remonte pas : c'est le
     * bon comportement, il n'a pas encore répondu à la question posée.
     *
     * @param  Builder<Application>  $requete
     */
    private function filtrerSurUneReponse(
        Builder $requete,
        string $champ,
        string $valeur,
        ApplicationSection $section = ApplicationSection::ELIGIBILITY,
    ): Builder {
        return $requete->whereHas(
            'sections',
            fn ($q) => $q
                ->where('section', $section->value)
                ->where('answers->'.$champ, $valeur),
        );
    }

    /**
     * Recherche sur les seules sources qui identifient un dossier.
     *
     * Nom et adresse du compte, numéro de dossier. Rien d'autre : parcourir tout
     * le `jsonb` des réponses ferait remonter un dossier parce que le mot cherché
     * apparaît dans la description de son défi, ce qui n'est pas une recherche
     * mais une coïncidence.
     *
     * `submission_number` est cherché bien qu'il soit encore toujours nul : la
     * colonne existe, elle est unique et indexée, et elle se remplira le jour où
     * la soumission sera câblée. L'écran ne le promet pas au-delà de ça.
     *
     * @param  Builder<Application>  $requete
     */
    private function appliquerRecherche(Builder $requete): void
    {
        $terme = trim((string) $this->search);

        if ($terme === '') {
            return;
        }

        $motif = '%'.str_replace(['%', '_'], ['\%', '\_'], $terme).'%';

        $requete->where(function (Builder $q) use ($motif): void {
            $q->whereHas('candidate', fn ($c) => $c
                ->where('name', 'ilike', $motif)
                ->orWhere('email', 'ilike', $motif))
                ->orWhere('submission_number', 'ilike', $motif);
        });
    }

    /**
     * Tri, exclusivement par liste blanche.
     *
     * `id` complète chaque tri : sans départage stable, deux dossiers portant le
     * même horodatage peuvent changer de page entre deux chargements, et une
     * ligne se retrouve affichée deux fois ou pas du tout.
     *
     * @param  Builder<Application>  $requete
     */
    private function appliquerTri(Builder $requete): void
    {
        match (in_array($this->sort, self::SORTS, strict: true) ? $this->sort : 'recent') {
            'ancien' => $requete->orderBy('updated_at')->orderBy('id'),
            // Tri par nom de compte, en sous-requête plutôt qu'en jointure : la
            // jointure dupliquerait les lignes si la relation changeait un jour.
            'nom' => $requete
                ->orderBy(User::query()->select('name')->whereColumn('users.id', 'applications.candidate_id'))
                ->orderBy('id'),
            'progression' => $requete->orderByDesc('completed_sections_count')->orderByDesc('updated_at')->orderByDesc('id'),
            default => $requete->orderByDesc('updated_at')->orderByDesc('id'),
        };
    }
}
