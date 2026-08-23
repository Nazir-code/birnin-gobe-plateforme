<?php

namespace App\Console\Commands;

use App\Domain\Auth\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Provisionnement d'un compte administrateur (ADR-006).
 *
 * C'est le seul chemin d'existence d'un compte interne. Il n'y a pas de
 * `/admin/register`, et il ne doit jamais y en avoir : une inscription publique
 * capable de produire un administrateur est une élévation de privilège offerte.
 *
 * Pas de seeder non plus : un seeder porte des identifiants figés, versionnés,
 * et rejoués à chaque `migrate --seed` — y compris là où ce n'est pas voulu.
 *
 *   php artisan admin:create
 *   php artisan admin:create --name="Aïcha Diallo" --email=aicha@exemple.ne
 *   printf '%s' "$MDP" | php artisan admin:create --name=… --email=… --password-stdin
 *
 * Le mot de passe n'est jamais un argument de ligne de commande : il finirait
 * dans l'historique du shell et dans la liste des processus de la machine.
 */
final class CreerAdministrateur extends Command
{
    protected $signature = 'admin:create
                            {--name= : Nom complet de l\'administrateur}
                            {--email= : Adresse e-mail, qui sert d\'identifiant}
                            {--password-stdin : Lire le mot de passe sur l\'entrée standard, sans invite}';

    protected $description = 'Crée un compte administrateur. Aucun formulaire public ne peut en produire.';

    public function handle(): int
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

        $utilisateur = new User;
        $utilisateur->fill($donnees);
        // Imposé ici, jamais lu depuis une saisie : `role` est de toute façon
        // hors de `$fillable`, la ligne au-dessus ne pourrait pas l'écrire.
        $utilisateur->role = UserRole::ADMIN;
        // Haché par le cast `password` du modèle (argon2id).
        $utilisateur->save();

        // Le mot de passe n'est jamais réaffiché : celui qui l'a saisi le
        // connaît, et une trace dans un terminal ou un journal de CI n'aurait
        // aucune raison d'exister.
        $this->components->info("Administrateur créé : {$utilisateur->email}");

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
