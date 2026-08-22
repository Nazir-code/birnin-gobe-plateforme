<?php

namespace App\Models;

use App\Domain\Auth\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * `role` est volontairement absent de `$fillable`.
     *
     * C'est la protection structurelle contre l'élévation de privilège : même
     * si un formulaire renvoyait `role=admin`, `User::create($request->all())`
     * ne pourrait pas l'écrire. Le rôle est assigné explicitement, côté serveur,
     * par le code qui a l'autorité de le décider.
     */
    protected $fillable = ['name', 'email', 'password', 'preferred_locale'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, strict: true);
    }

    public function isCandidate(): bool
    {
        return $this->hasRole(UserRole::CANDIDATE);
    }
}
