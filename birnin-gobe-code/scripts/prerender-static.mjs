/**
 * Pre-rend le portail en site statique deployable (Vercel, Netlify, S3...).
 *
 * L'application reste un monolithe Laravel : ce script ne la remplace pas, il
 * capture l'etat rendu des routes prototypes — qui sont des closures
 * `Inertia::render` sans aucune requete base de donnees — pour en faire une
 * vitrine consultable sans backend.
 *
 * Deux artefacts par route :
 *  - le HTML complet, avec son attribut `data-page` : React s'hydrate donc
 *    normalement et le carrousel du hero fonctionne cote client ;
 *  - la reponse JSON Inertia, servie aux requetes XHR portant l'en-tete
 *    `x-inertia` (voir les `rewrites` de vercel.json). Sans elle, la navigation
 *    entre pages casserait : le client recevrait du HTML la ou il attend du JSON.
 *
 * Usage : node scripts/prerender-static.mjs [origine] [dossier de sortie]
 */
import { cp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ORIGIN = process.argv[2] ?? 'http://localhost:8080';
const OUT = resolve(process.argv[3] ?? 'dist-static');
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/** Les routes prototypes de routes/web.php. */
const ROUTES = [
  { path: '/', file: 'index.html', data: 'inertia-data/home.json' },
  { path: '/candidate/dashboard', file: 'candidate/dashboard.html', data: 'inertia-data/candidate-dashboard.json' },
  { path: '/candidate/application/challenge', file: 'candidate/application/challenge.html', data: 'inertia-data/candidate-application-challenge.json' },
  { path: '/admin/dashboard', file: 'admin/dashboard.html', data: 'inertia-data/admin-dashboard.json' },
  { path: '/evaluator/assignments', file: 'evaluator/assignments.html', data: 'inertia-data/evaluator-assignments.json' },
];

async function write(relativePath, content) {
  const target = join(OUT, relativePath);
  await mkdir(dirname(target), { recursive: true });
  await writeFile(target, content);
}

/** Laravel emet des URLs absolues (APP_URL) : le site statique doit etre relatif a son domaine. */
function toRelativeUrls(html) {
  return html.replaceAll(`${ORIGIN}/`, '/').replaceAll(ORIGIN, '');
}

/** La version Inertia doit correspondre a celle du bundle, sinon le client force un rechargement complet. */
async function inertiaVersion() {
  const probe = await fetch(`${ORIGIN}/`, { headers: { 'X-Inertia': 'true', 'X-Inertia-Version': 'probe' } });
  const version = probe.headers.get('x-inertia-version');
  if (!version) throw new Error("Impossible de lire l'en-tete x-inertia-version — l'application repond-elle ?");
  return version;
}

const version = await inertiaVersion();
console.log(`Version Inertia : ${version}`);

await rm(OUT, { recursive: true, force: true });

for (const route of ROUTES) {
  const page = await fetch(`${ORIGIN}${route.path}`);
  if (!page.ok) throw new Error(`${route.path} a repondu ${page.status}`);
  const html = toRelativeUrls(await page.text());
  if (!html.includes('data-page')) throw new Error(`${route.path} : attribut data-page absent, l'hydratation echouerait`);
  await write(route.file, html);

  const xhr = await fetch(`${ORIGIN}${route.path}`, {
    headers: { 'X-Inertia': 'true', 'X-Inertia-Version': version },
  });
  if (!xhr.ok) throw new Error(`${route.path} (XHR) a repondu ${xhr.status}`);
  await write(route.data, await xhr.text());

  console.log(`  ${route.path} -> ${route.file} + ${route.data}`);
}

// Les binaires servis tels quels : photos du hero, logos officiels, bundle Vite.
await cp(join(ROOT, 'public/assets'), join(OUT, 'assets'), { recursive: true });
await cp(join(ROOT, 'public/build'), join(OUT, 'build'), { recursive: true });

// Le routage est genere ici pour rester coherent avec ROUTES.
const rewrites = ROUTES.map((route) => ({
  source: route.path,
  has: [{ type: 'header', key: 'x-inertia' }],
  destination: `/${route.data}`,
}));

await write(
  'vercel.json',
  `${JSON.stringify(
    {
      $schema: 'https://openapi.vercel.sh/vercel.json',
      cleanUrls: true,
      trailingSlash: false,
      rewrites,
      headers: [
        {
          // Inertia ne reconnait une reponse que si cet en-tete est present.
          source: '/inertia-data/(.*)',
          headers: [
            { key: 'X-Inertia', value: 'true' },
            { key: 'Content-Type', value: 'application/json; charset=utf-8' },
          ],
        },
        {
          // Le bundle Vite est nomme par empreinte : il est immuable.
          source: '/build/assets/(.*)',
          headers: [{ key: 'Cache-Control', value: 'public, max-age=31536000, immutable' }],
        },
        {
          source: '/(.*)',
          headers: [
            { key: 'X-Content-Type-Options', value: 'nosniff' },
            { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
            { key: 'X-Frame-Options', value: 'SAMEORIGIN' },
          ],
        },
      ],
    },
    null,
    2,
  )}\n`,
);

const readme = await readFile(join(ROOT, 'README.md'), 'utf8').catch(() => '');
await write(
  'NOTICE.txt',
  [
    'BIRNIN GOBE — export statique de demonstration',
    '',
    "Cet export est une capture des routes prototypes de l'application Laravel.",
    "Il sert a montrer l'interface ; ce n'est pas la plateforme en fonctionnement.",
    "Aucune authentification, aucune base de donnees, aucun depot de candidature.",
    '',
    `Genere le ${new Date().toISOString()} depuis ${ORIGIN}`,
    readme ? '' : '',
  ].join('\n'),
);

console.log(`\nExport statique ecrit dans ${OUT}`);
