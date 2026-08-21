<?php
namespace App\Http\Middleware;
use Illuminate\Http\Request;
use Inertia\Middleware;
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';
    public function share(Request $request): array
    {
        return [...parent::share($request), 'auth' => ['user' => $request->user()], 'locale' => app()->getLocale()];
    }
}
