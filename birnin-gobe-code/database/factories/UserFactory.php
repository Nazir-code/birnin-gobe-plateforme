<?php

namespace Database\Factories;

use App\Domain\Auth\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 *
 * Valeurs déterministes plutôt que Faker : le projet n'a pas cette dépendance,
 * et un test qui échoue doit échouer de la même façon à chaque exécution.
 * Seule l'unicité de l'e-mail est nécessaire, un compteur y suffit.
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    private static int $compteur = 0;

    public function definition(): array
    {
        $n = ++self::$compteur;

        return [
            'name' => "Candidat Test {$n}",
            'email' => "candidat.test.{$n}@example.test",
            // Haché par le cast `password` du modèle.
            'password' => 'MotDePasseSolide!2026',
            'preferred_locale' => 'fr',
            'role' => UserRole::CANDIDATE,
        ];
    }

    public function role(UserRole $role): static
    {
        return $this->state(fn (): array => ['role' => $role]);
    }
}
