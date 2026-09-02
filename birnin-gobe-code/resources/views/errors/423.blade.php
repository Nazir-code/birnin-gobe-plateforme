{{--
    Refus de servir une pièce dont l'analyse antivirus n'autorise pas la
    diffusion — §15.1.

    **Pourquoi ce fichier existe.** `StoreApplicationDocument::servir()` refuse
    avec un 423 et joint le motif exact : analyse en cours, analyse
    indisponible, quarantaine. Sans gabarit pour ce code, Laravel se rabat sur
    la page générique de Symfony — « Oops! An Error Occurred... Something is
    broken » — et le motif est perdu. Le refus se lisait alors comme une panne :
    la personne réessayait en boucle, puis signalait un serveur cassé, pour un
    blocage qui était volontaire et souvent temporaire.

    **La page est autonome.** Pas de `@vite`, pas de React : une page d'erreur
    qui dépend du manifeste de compilation tombe en même temps que ce qu'elle
    devait expliquer. Les couleurs sont donc recopiées de `resources/css/app.css`
    plutôt qu'importées — une duplication assumée, et le seul endroit du projet
    où elle l'est.

    Le ton ne dit pas « erreur ». Dans quatre cas sur cinq, rien n'est cassé :
    l'attente est le fonctionnement normal du contrôle du §15.1.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#075b39">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/branding/favicon-32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/branding/favicon-192.png">
    <title>Pièce non téléchargeable — {{ config('app.name', 'BIRNIN GOBE') }}</title>
    <style>
        :root {
            --bg: #fbfaf7;
            --surface: #ffffff;
            --green: #075b39;
            --gold: #f3b71b;
            --ink: #132234;
            --muted: #667085;
            --line: #e7e7e2;
            --shadow: 0 18px 50px rgb(16 34 52 / 8%);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            background: var(--bg);
            color: var(--ink);
            font-family: "Inter", "Aptos", "Segoe UI", system-ui, sans-serif;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        .carte {
            width: 100%;
            max-width: 34rem;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 28px 24px;
        }

        .filet {
            width: 44px;
            height: 4px;
            border-radius: 999px;
            background: var(--gold);
            margin-bottom: 20px;
        }

        .surtitre {
            margin: 0 0 6px;
            font-size: 0.8125rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--green);
        }

        h1 {
            margin: 0 0 14px;
            font-size: 1.375rem;
            line-height: 1.3;
            font-weight: 700;
        }

        .motif {
            margin: 0;
            font-size: 1rem;
            color: var(--ink);
        }

        .suite {
            margin: 20px 0 0;
            padding-top: 18px;
            border-top: 1px solid var(--line);
            font-size: 0.9375rem;
            color: var(--muted);
        }

        .retour {
            display: inline-block;
            margin-top: 18px;
            padding: 11px 18px;
            border-radius: 10px;
            background: var(--green);
            color: #fff;
            font-weight: 600;
            font-size: 0.9375rem;
            text-decoration: none;
        }

        .retour:hover { background: #04442e; }

        .retour:focus-visible {
            outline: 3px solid var(--gold);
            outline-offset: 2px;
        }

        @media (min-width: 640px) {
            .carte { padding: 36px 34px; }
            h1 { font-size: 1.5rem; }
        }
    </style>
</head>
<body>
    @php
        // Le motif vient de `AttachmentScanStatus::explication()`, porté par
        // l'exception. La solution de repli ne doit jamais servir : elle existe
        // pour qu'un 423 levé ailleurs un jour ne produise pas une page muette.
        $motif = trim((string) $exception->getMessage())
            ?: 'Cette pièce ne peut pas être téléchargée pour le moment.';

        // Le lien de retour s'appuie sur le référent, pas sur `url()->previous()` :
        // le téléchargement est lui-même une requête GET, et Laravel l'aurait
        // enregistrée comme page précédente — le bouton aurait rejoué le refus.
        $referent = request()->headers->get('referer');
        $retour = $referent && $referent !== request()->fullUrl() ? $referent : null;
    @endphp

    <main class="carte">
        <div class="filet"></div>
        <p class="surtitre">Contrôle des pièces</p>
        <h1>Cette pièce n’est pas téléchargeable pour l’instant</h1>

        <p class="motif">{{ $motif }}</p>

        <p class="suite">
            Chaque pièce déposée est contrôlée avant d’être ouverte. Le dossier
            lui-même reste consultable ; seul le fichier est retenu.
        </p>

        @if ($retour)
            <a class="retour" href="{{ $retour }}">Revenir au dossier</a>
        @endif
    </main>
</body>
</html>
