<?php

namespace App\Http\Middleware;

use App\Domain\Eligibility\EvaluateEligibility;
use App\Domain\Eligibility\RuleFinding;
use App\Models\Application;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ferme les sections postérieures à l'éligibilité quand une règle bloquante
 * est déclenchée.
 *
 * Cahier des charges §5.2 : « possibilité de poursuivre tant qu'aucune règle
 * bloquante n'est validée ». La lecture en creux est ce que fait ce
 * middleware : dès qu'une règle bloquante l'est, la suite se ferme.
 *
 * Déclaré sur la route, comme `can:` et `role:` — une section ajoutée sans
 * cette déclaration se voit à la relecture, un `if` oublié au fond d'un
 * contrôleur non.
 *
 * Redirection, et non 403 : le candidat n'est pas un intrus, il a répondu
 * quelque chose qui ferme la porte. Il doit atterrir sur ses réponses, pouvoir
 * les corriger, et lire pourquoi. Ses réponses ne sont ni effacées, ni
 * modifiées — rien n'est perdu.
 *
 * Ce n'est pas une décision d'admissibilité : celle-ci est administrative,
 * humaine et postérieure (§10.2).
 */
final class EnsureApplicationIsEligible
{
    public function __construct(private readonly EvaluateEligibility $evaluer) {}

    public function handle(Request $request, Closure $next): Response
    {
        $application = $request->route('application');

        // Le paramètre est résolu par le model binding ; s'il n'est pas là,
        // c'est une erreur de câblage de route, pas un cas métier.
        if (! $application instanceof Application) {
            return $next($request);
        }

        $verdict = $this->evaluer->forApplication($application);

        if (! $verdict->outcome->blocksNextSections()) {
            return $next($request);
        }

        $motifs = array_map(
            static fn (RuleFinding $constat): string => $constat->message,
            $verdict->blocking(),
        );

        $redirection = redirect()
            ->route('candidate.application.eligibility', $application)
            ->with('eligibiliteBloquante', $motifs);

        // Une sauvegarde automatique attend du JSON : lui renvoyer une
        // redirection la ferait échouer sans rien expliquer. 403 plutôt que
        // 409, qu'Inertia réserve aux ruptures de version d'assets.
        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json([
                'message' => 'Cette étape est fermée tant que les conditions d’éligibilité ne sont pas remplies.',
                'eligibility' => $verdict->toArray(),
            ], 403);
        }

        return $redirection;
    }
}
