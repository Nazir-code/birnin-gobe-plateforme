import { expect, test, type Page } from '@playwright/test';

const SLIDE_COUNT = 5;
const AUTOPLAY_MS = 5000;
const FADE_MS = 800;

const slides = (page: Page) => page.locator('.crossfade-layer');
const dots = (page: Page) => page.getByRole('tab');

/** Index de la photo actuellement affichee. */
async function activeIndex(page: Page): Promise<number> {
  return page.evaluate(() =>
    [...document.querySelectorAll('.crossfade-layer')].findIndex((s) => s.classList.contains('is-active')),
  );
}

test.describe('Hero — carrousel de la page d’accueil', () => {
  test('affiche les 5 photos, sans 404 ni erreur console', async ({ page }) => {
    const consoleErrors: string[] = [];
    const failed: string[] = [];
    page.on('console', (m) => m.type() === 'error' && consoleErrors.push(m.text()));
    page.on('pageerror', (e) => consoleErrors.push(e.message));
    page.on('response', (r) => r.status() >= 400 && failed.push(`${r.status()} ${r.url()}`));

    await page.goto('/');
    await expect(slides(page)).toHaveCount(SLIDE_COUNT);

    // `src` porte le repli JPEG ; le WebP est propose par <source> et c'est lui
    // que les navigateurs actuels telechargent.
    const sources = await slides(page)
      .locator('img')
      .evaluateAll((imgs) => imgs.map((i) => i.getAttribute('src')));
    expect(sources).toEqual([1, 2, 3, 4, 5].map((n) => `/assets/hero-accueil-${n}.jpg`));

    const webp = await slides(page)
      .locator('source[type="image/webp"]')
      .evaluateAll((els) => els.map((e) => e.getAttribute('srcset')));
    expect(webp).toEqual([1, 2, 3, 4, 5].map((n) => `/assets/hero-accueil-${n}.webp`));

    // Chaque photo est reellement decodee : une image manquante produirait un fondu vers le vide.
    await expect
      .poll(
        () =>
          slides(page)
            .locator('img')
            .evaluateAll((imgs) => imgs.every((i) => (i as HTMLImageElement).naturalWidth > 0)),
        { timeout: 30_000 },
      )
      .toBe(true);

    // Un alt distinct et descriptif par photo (pas « image1 » / « slide2 »).
    const alts = await slides(page)
      .locator('img')
      .evaluateAll((imgs) => imgs.map((i) => i.getAttribute('alt') ?? ''));
    expect(new Set(alts).size).toBe(SLIDE_COUNT);
    for (const alt of alts) {
      expect(alt.length).toBeGreaterThan(30);
      expect(alt).not.toMatch(/^(image|photo|slide)\s*\d+$/i);
    }

    expect(failed).toEqual([]);
    expect(consoleErrors).toEqual([]);
  });

  test('defile 1 vers 5 puis revient a la premiere, toutes les 5 s', async ({ page }) => {
    // 6 transitions a 5 s : au-dela du delai par defaut de Playwright (30 s).
    test.setTimeout(75_000);
    // On attend qu'une diapo soit active, pas que ce soit la premiere : sur un
    // reseau reel le carrousel a deja pu avancer, et il faudrait 25 s pour que
    // la premiere redevienne active — bien au-dela du delai d'assertion.
    await page.goto('/');
    await expect(page.locator('.crossfade-layer.is-active')).toHaveCount(1);

    // Un tour complet (5 changements) + un cran de plus : le 6e sert a mesurer
    // 5 intervalles reels entre transitions. Le tout premier intervalle n'est
    // pas mesurable ici — la minuterie demarre au montage du composant, avant
    // que la sonde ne soit posee — il serait donc systematiquement trop court.
    const observed = await page.evaluate(
      async ({ count, autoplay, fade }) => {
        const nodes = [...document.querySelectorAll('.crossfade-layer')];
        const idx = () => nodes.findIndex((s) => s.classList.contains('is-active'));
        const log: { i: number; t: number }[] = [];
        const t0 = performance.now();
        let last = idx();

        await new Promise<void>((resolve) => {
          const observer = new MutationObserver(() => {
            const next = idx();
            if (next === last) return;
            last = next;
            log.push({ i: next, t: Math.round(performance.now() - t0) });
            if (log.length === count + 1) {
              observer.disconnect();
              resolve();
            }
          });
          nodes.forEach((s) => observer.observe(s, { attributes: true, attributeFilter: ['class'] }));
          setTimeout(
            () => {
              observer.disconnect();
              resolve();
            },
            (count + 1) * (autoplay + fade) + 5000,
          );
        });

        return log;
      },
      { count: SLIDE_COUNT, autoplay: AUTOPLAY_MS, fade: FADE_MS },
    );

    // Ordre impose : chaque photo est suivie de la suivante, et la 5e ramene a
    // la 1re. On verifie la rotation a partir de la diapo reellement active au
    // demarrage de la sonde — sur reseau lent, ce n'est pas forcement la 1re.
    const start = observed[0].i === 0 ? SLIDE_COUNT - 1 : observed[0].i - 1;
    const expected = Array.from({ length: SLIDE_COUNT + 1 }, (_, k) => (start + 1 + k) % SLIDE_COUNT);
    expect(observed.map((e) => e.i)).toEqual(expected);
    // Le tour complet doit repasser par les cinq photos.
    expect(new Set(observed.map((e) => e.i)).size).toBe(SLIDE_COUNT);

    // Chaque photo reste visible ~5 000 ms (tolerance ordonnanceur / charge CI).
    const gaps = observed.slice(1).map((e, k) => e.t - observed[k].t);
    expect(gaps).toHaveLength(SLIDE_COUNT);
    for (const gap of gaps) {
      expect(gap).toBeGreaterThan(AUTOPLAY_MS - 400);
      expect(gap).toBeLessThan(AUTOPLAY_MS + 900);
    }
  });

  test('le fondu dure 800 ms et le zoom reste discret', async ({ page }) => {
    await page.goto('/');
    // La diapo ACTIVE, pas la premiere : sur un reseau lent le carrousel a
    // deja pu avancer avant la mesure, et une diapo inactive est au repos.
    const styles = await page.locator('.crossfade-layer.is-active').evaluate((el) => {
      const img = el.querySelector('img')!;
      return {
        fade: getComputedStyle(el).transitionDuration,
        transform: getComputedStyle(img).transform,
        objectFit: getComputedStyle(img).objectFit,
      };
    });

    expect(styles.fade).toBe('0.8s');
    expect(styles.objectFit).toBe('cover');
    // matrix(1.04, 0, 0, 1.04, 0, 0) — zoom leger, jamais agressif.
    const scale = Number(styles.transform.replace(/^matrix\(/, '').split(',')[0]);
    expect(scale).toBeGreaterThan(1);
    expect(scale).toBeLessThanOrEqual(1.05);
  });

  test('les indicateurs sont synchronises, cliquables et reinitialisent la minuterie', async ({ page }) => {
    await page.goto('/');
    await expect(dots(page)).toHaveCount(SLIDE_COUNT);
    await expect(dots(page).nth(0)).toHaveAttribute('aria-selected', 'true');

    await dots(page).nth(3).click();
    await expect(dots(page).nth(3)).toHaveAttribute('aria-selected', 'true');
    await expect(dots(page).nth(0)).toHaveAttribute('aria-selected', 'false');
    expect(await activeIndex(page)).toBe(3);

    // La minuterie repart de zero apres un clic : toujours sur la 4e photo a
    // +2,5 s. On garde une marge confortable sous les 5 s — les etapes entre le
    // clic et l'attente coutent elles-memes du temps sur un site distant, et une
    // marge d'une seconde suffisait a rendre le test instable.
    await page.waitForTimeout(2500);
    expect(await activeIndex(page)).toBe(3);
    // ...puis elle enchaine sur la 5e (une seule minuterie, pas de saut de deux crans).
    await expect.poll(() => activeIndex(page), { timeout: 6000 }).toBe(4);
  });

  test('le texte du Hero ne change pas quand la photo change', async ({ page }) => {
    await page.goto('/');
    const heading = page.getByRole('heading', { level: 1 });
    const before = await heading.textContent();

    await dots(page).nth(2).click();
    await page.waitForTimeout(1200);

    expect(await heading.textContent()).toBe(before);
  });

  test('aucun debordement horizontal ni zone image vide', async ({ page }) => {
    for (const width of [320, 360, 375, 390, 430, 768, 1024, 1280, 1440, 1920]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/');
      await expect(page.locator('.crossfade-layer.is-active')).toHaveCount(1);

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      );
      expect(overflow, `debordement horizontal a ${width}px`).toBeLessThanOrEqual(0);

      const box = await page.locator('.crossfade-layer.is-active').boundingBox();
      expect(box!.width, `zone image vide a ${width}px`).toBeGreaterThan(0);
      expect(box!.height, `zone image trop courte a ${width}px`).toBeGreaterThan(300);
    }
  });

  test('prefers-reduced-motion : plus de zoom, transition simplifiee', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/');

    const styles = await slides(page)
      .first()
      .evaluate((el) => ({
        fade: getComputedStyle(el).transitionDuration,
        transform: getComputedStyle(el.querySelector('img')!).transform,
      }));

    // Le garde-fou prefers-reduced-motion global de app.css ecrase toutes les
    // durees (Chrome serialise « 1e-05s ») : on compare en secondes, pas en texte.
    expect(Number.parseFloat(styles.fade)).toBeLessThan(0.05);
    expect(styles.transform === 'none' || styles.transform === 'matrix(1, 0, 0, 1, 0, 0)').toBe(true);
  });

  test('le defilement peut etre mis en pause (WCAG 2.2.2)', async ({ page }) => {
    await page.goto('/');
    // Le libelle bascule en « Reprendre… » apres le clic : on cible l'etat, pas le texte.
    const pause = page.locator('.hero-carousel button[aria-pressed]');
    await expect(pause).toHaveAccessibleName(/mettre en pause/i);
    await pause.click();
    await expect(pause).toHaveAttribute('aria-pressed', 'true');

    const before = await activeIndex(page);
    await page.waitForTimeout(6500);
    expect(await activeIndex(page)).toBe(before);
  });
});
