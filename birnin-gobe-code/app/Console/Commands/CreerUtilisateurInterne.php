<?php

namespace App\Console\Commands;

use App\Domain\Auth\CreerUtilisateurInterne as CasDUsage;
use App\Domain\Auth\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Provisionnement d'un compte interne (ADR-006, etendu par ADR-021).
 *
 * C'est le seul chemin d'existence d'un compte interne. Il n'y a pas de
 * `/admin/register` ni de `/evaluator/register`, et il ne doit jamais y en
 * avoir : une inscription publique capable de produire un administrateur ou un
 * evaluateur est une elevation de privilege offerte.
 *
 * Pas de seeder non plus : un seeder porte des identifiants figes, versionnes,
 * et rejoues a chaque `migrate --seed` — y compris la ou ce n'est pas voulu.
 *
 * **Une seule implementation, deux roles.** ADR-006 pose que dupliquer une
 * regle de securite, c'est accepter que les deux copies divergent. La politique
 * de mot de passe, la normalisation de l'adresse, l'unicite et le refus de
 * promouvoir un compte existant vivent donc ici ; chaque commande concrete
 * n'ajoute que le role qu'elle impose.
 *
 * **Le role est une constante de la sous-classe, jamais une option.** Un
 * `--role=` ferait dependre le privilege d'une saisie, et il n'y a aucune bonne
 * raison de rendre modifiable ce qui distingue les deux commandes.
 *
 * Le mot de passe n'est jamais un argument de ligne de commande : il finirait
 * dans l'historique du shell et dans la liste des processus de la machine.
 */
abstract class CreerUtilisateurInterne extends Command
{
    /** Le role impose au compte cree. Jamais lu depuis une saisie. */
    abstract protected function role(): UserRole;

    /** L'intitule affiche a l'operateur — « Administrateur », « Evaluateur ». */
    abstract protected function intitule(): string;

    public function handle(CasDUsage $creer): int
    {
        $nom = $this->option('name') ?? $this->ask('Nom complet');
        $email = $this->option('email') ?? $this->ask('Adresse e-mail');

        $motDePasse = $this->lireMotDePasse();

        if ($motDePasse === null) {
            return self::FAILURE;
        }

        $donnees = [
            'name' => trim((string) $nom),
            // Normalisé avant validation : la règle `lowercase` refuserait
            // « Admin@… », alors que l'intention est sans ambiguïté.
            'email' => Str::lower(trim((string) $email)),
            'password' => $motDePasse,
        ];

        // Mêmes règles que l'inscription publique, `Password::defaults()`
        // compris : la politique de mot de passe du projet ne se relâche pas
        // parce que la saisie vient d'un terminal.
        $validateur = Validator::make($donnees, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults()],
        ], [
            // Le dépôt n'embarque pas les fichiers de langue de Laravel : sans
            // ces messages, l'opérateur lirait « validation.unique. » et n'aurait
            // aucune idée de ce qu'il doit corriger.
            'name.required' => 'Le nom est obligatoire.',
            'name.max' => 'Le nom ne doit pas dépasser 120 caractères.',
            'email.required' => 'L\'adresse e-mail est obligatoire.',
            'email.email' => 'L\'adresse e-mail n\'est pas valide.',
            'email.max' => 'L\'adresse e-mail ne doit pas dépasser 255 caractères.',
            'email.unique' => 'Un compte existe déjà avec cette adresse. La commande crée un compte, elle ne change pas le rôle d\'un compte existant.',
            'password.required' => 'Le mot de passe est obligatoire.',
        ]);

        if ($validateur->fails()) {
            foreach ($validateur->errors()->all() as $message) {
                $this->components->error($this->lisible($message));
            }

            return self::FAILURE;
        }

        // Le compte naît dans le cas d'usage, jamais ici : c'est le même chemin
        // que celui du back-office (ADR-022), et deux chemins de création
        // divergeraient tôt ou tard sur le rôle, l'audit ou le hachage.
        // L'acteur est nul — personne n'est authentifié dans un terminal — et
        // le journal du §13.3 l'écrit ainsi plutôt que d'attribuer le geste à
        // quelqu'un qui ne l'a pas fait.
        $utilisateur = $creer->handle(
            nom: $donnees['name'],
            email: $donnees['email'],
            role: $this->role(),
            motDePasse: $donnees['password'],
            acteur: null,
        );

        // Le mot de passe n'est jamais réaffiché : celui qui l'a saisi le
        // connaît, et une trace dans un terminal ou un journal de CI n'aurait
        // aucune raison d'exister.
        $this->components->info("{$this->intitule()} créé : {$utilisateur->email}");

        return self::SUCCESS;
    }

    /**
     * Message affichable par l'opérateur.
     *
     * Les règles de `Password::defaults()` produisent leurs propres clés de
     * traduction et ignorent le tableau de messages du validateur. Faute de
     * fichiers de langue dans le dépôt, elles s'afficheraient brutes
     * (« validation.min.string »). On leur substitue l'énoncé de la politique,
     * qui suit `Password::defaults()` — à ajuster si elle est durcie.
     */
    private function lisible(string $message): string
    {
        return str_starts_with($message, 'validation.')
            ? 'Le mot de passe ne respecte pas la politique du projet : au moins 8 caractères.'
            : $message;
    }

    /** Le mot de passe saisi, ou `null` si la saisie a échoué. */
    private function lireMotDePasse(): ?string
    {
        if ($this->option('password-stdin')) {
            // Lecture directe plutôt qu'une invite : Symfony désactive
            // l'interactivité dès que l'entrée standard n'est pas un terminal,
            // et `secret()` renverrait alors sa valeur par défaut.
            $ligne = fgets(STDIN);

            if ($ligne === false || rtrim($ligne, "\r\n") === '') {
                $this->components->error('Aucun mot de passe reçu sur l\'entrée standard.');

                return null;
            }

            return rtrim($ligne, "\r\n");
        }

        // Saisie masquée : le mot de passe n'apparaît pas à l'écran.
        $motDePasse = (string) $this->secret('Mot de passe');
        $confirmation = (string) $this->secret('Confirmer le mot de passe');

        if (! hash_equals($motDePasse, $confirmation)) {
            $this->components->error('La confirmation ne correspond pas au mot de passe.');

            return null;
        }

        return $motDePasse;
    }
}
