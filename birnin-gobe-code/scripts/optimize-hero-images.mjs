/**
 * Encode les visuels du hero pour le web.
 *
 * Les PNG d'origine pesaient environ 2 Mo chacun, soit pres de 10 Mo charges
 * des l'ouverture de la page d'accueil — inacceptable pour un public
 * majoritairement en donnees mobiles, et suffisant pour empecher l'evenement
 * `load` de se declencher sur un reseau reel.
 *
 * Les masters PNG restent dans `resources/images/hero/` : ils ne sont pas
 * servis, ils servent de source de verite pour re-encoder si besoin. Ce script
 * en derive, dans `public/assets/` :
 *  - un WebP, format retenu par les navigateurs via <picture><source> ;
 *  - un JPEG, porte par l'attribut `src` et donc utilise comme repli.
 *
 * Aucune image n'est creee ni telechargee : ce sont les memes photographies,
 * re-encodees. Le cadrage est inchange, donc les `objectPosition` restent
 * valables.
 *
 * Usage : node scripts/optimize-hero-images.mjs
 */
import { mkdir, readdir, stat, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const SOURCE = join(ROOT, 'resources/images/hero');
const OUT = join(ROOT, 'public/assets');

/**
 * La zone image du hero ne depasse jamais ~840 px de large sur desktop. 1400 px
 * couvre donc les ecrans a haute densite avec de la marge, sans payer pour des
 * pixels invisibles.
 */
const MAX_WIDTH = 1400;
const WEBP_QUALITY = 74;
const JPEG_QUALITY = 78;

const ko = (n) => `${Math.round(n / 1024)} Ko`;

await mkdir(OUT, { recursive: true });

const masters = (await readdir(SOURCE)).filter((f) => f.endsWith('.png')).sort();
if (masters.length === 0) throw new Error(`Aucun master PNG dans ${SOURCE}`);

let totalBefore = 0;
let totalAfter = 0;
const manifest = [];

for (const file of masters) {
  const name = file.replace(/\.png$/, '');
  const input = join(SOURCE, file);
  const before = (await stat(input)).size;
  totalBefore += before;

  const base = sharp(input).resize({ width: MAX_WIDTH, withoutEnlargement: true });

  const webp = await base.clone().webp({ quality: WEBP_QUALITY, effort: 6 }).toBuffer();
  const jpeg = await base.clone().jpeg({ quality: JPEG_QUALITY, progressive: true, mozjpeg: true }).toBuffer();

  await writeFile(join(OUT, `${name}.webp`), webp);
  await writeFile(join(OUT, `${name}.jpg`), jpeg);

  // Le WebP est ce que telechargeront les navigateurs actuels : c'est lui qui
  // compte dans le poids reel de la page.
  totalAfter += webp.length;
  manifest.push({ name, before, webp: webp.length, jpeg: jpeg.length });

  console.log(`${name}  PNG ${ko(before).padStart(8)} -> WebP ${ko(webp.length).padStart(7)}  (repli JPEG ${ko(jpeg.length)})`);
}

const { width, height } = await sharp(join(SOURCE, masters[0])).resize({ width: MAX_WIDTH, withoutEnlargement: true }).toBuffer({ resolveWithObject: true }).then((r) => r.info);

console.log(`\nDimensions servies : ${width}x${height}`);
console.log(`Poids des photos du hero : ${ko(totalBefore)} -> ${ko(totalAfter)} (${Math.round((1 - totalAfter / totalBefore) * 100)} % de moins)`);
