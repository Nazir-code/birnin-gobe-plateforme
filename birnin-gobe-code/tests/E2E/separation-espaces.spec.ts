import { expect, test, type Page } from '@playwright/test';

/**
 * Verrouille la contrainte d'architecture d'ADR-003 : l'interface publique et
 * l'espace candidat n'exposent aucun acces aux espaces internes.
 *
 * Ces tests couvrent la surface VISIBLE. Ils ne remplacent pas les tests
 * backend (candidate -> /admin = 403) a ecrire quand le RBAC existera :
 * masquer un lien n'est pas une autorisation.
 */

/** Pages du parcours candidat, seules concernees par cette contrainte. */
const PARCOURS_CANDIDAT = [
  ['portail public', '/'],
  ['tableau de bord candidat', '/candidate/dashboard'],
  ['candidature', '/candidate/application/challenge'],
] as const;

/** Destinations internes qui ne doivent apparaitre dans aucun lien. */
const CIBLES_INTERNES = ['/admin', '/evaluator', '/jury'];

/**
 * Libelles interdits. « Jury final » est volontairement absent : c'est une
 * etape publique du processus de la competition, pas un acces au back-office.
 */
const LIBELLES_INTERDITS = [
  /se connecter comme/i,
  /acc[eè]s d[eé]monstration/i,
  /changer de r[oô]le/i,
  /back-?office/i,
  /^administration$/i,
  /^admin$/i,
  /^[eé]valuateur$/i,
  /^jury$/i,
];

/** Tous les liens de la page, hors contenu principal des pages internes. */
async function liens(page: Page) {
  return page.locator('a[href], [role="tab"]').evaluateAll((els) =>
    els.map((e) => ({
      href: e.getAttribute('href') ?? '',
      texte: (e.textContent ?? '').trim(),
    })),
  );
}


/**
 * Les pages candidat exigent desormais une authentification (Phase 1).
 * On cree un compte reel : ces tests verifient ce que voit un candidat
 * connecte, pas ce que voit un visiteur redirige vers la connexion.
 */
async function connecterUnCandidat(page: Page) {
  const email = `separation-e2e-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.test`;
  await page.goto('/register');
  await page.getByLabel('Nom complet').fill('Amina Issa');
  await page.getByLabel('Adresse e-mail').fill(email);
  await page.getByLabel('Mot de passe', { exact: true }).fill('MotDePasseSolide!2026');
  await page.getByLabel('Confirmer le mot de passe').fill('MotDePasseSolide!2026');
  await page.getByRole('button', { name: /créer mon compte/i }).click();
  await expect(page).toHaveURL(/\/candidate\/dashboard$/);
}

test.describe('ADR-003 — separation des espaces', () => {
  for (const [nom, url] of PARCOURS_CANDIDAT) {
    test(`${nom} : aucun lien vers un espace interne`, async ({ page }) => {
      if (url.startsWith('/candidate')) await connecterUnCandidat(page);
      await page.goto(url);
      await expect(page.locator('body')).toBeVisible();

      const trouves = (await liens(page)).filter((l) =>
        CIBLES_INTERNES.some((cible) => l.href === cible || l.href.startsWith(`${cible}/`)),
      );

      expect(
        trouves,
        `Liens internes exposes sur ${url} : ${JSON.stringify(trouves)}`,
      ).toEqual([]);
    });

    test(`${nom} : aucun libelle de bascule de role`, async ({ page }) => {
      if (url.startsWith('/candidate')) await connecterUnCandidat(page);
      await page.goto(url);
      await expect(page.locator('body')).toBeVisible();

      const textes = (await liens(page)).map((l) => l.texte).filter(Boolean);
      const boutons = await page.locator('button').allInnerTexts();
      const tous = [...textes, ...boutons.map((t) => t.trim())].filter(Boolean);

      for (const motif of LIBELLES_INTERDITS) {
        const fautifs = tous.filter((t) => motif.test(t));
        expect(fautifs, `Libelle interdit ${motif} sur ${url} : ${fautifs.join(', ')}`).toEqual([]);
      }
    });
  }

  test('le menu mobile public n’expose pas non plus les espaces internes', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 800 });
    await page.goto('/');

    await page.getByRole('button', { name: /ouvrir le menu/i }).click();
    await expect(page.locator('#menu-mobile')).toBeVisible();

    const dansLeMenu = await page.locator('#menu-mobile a').evaluateAll((els) =>
      els.map((e) => ({ href: e.getAttribute('href') ?? '', texte: (e.textContent ?? '').trim() })),
    );

    const internes = dansLeMenu.filter((l) =>
      CIBLES_INTERNES.some((cible) => l.href === cible || l.href.startsWith(`${cible}/`)),
    );
    expect(internes, `Menu mobile : ${JSON.stringify(internes)}`).toEqual([]);

    // Le parcours candidat, lui, doit rester accessible.
    await expect(page.locator('#menu-mobile').getByRole('link', { name: /se connecter/i })).toBeVisible();
  });

  test('le pied de page candidat ne mene qu’a des destinations candidat', async ({ page }) => {
    await connecterUnCandidat(page);
    await page.goto('/candidate/dashboard');
    const pied = page.locator('footer');
    await expect(pied).toBeVisible();

    const hrefs = await pied.locator('a[href]').evaluateAll((els) =>
      els.map((e) => e.getAttribute('href') ?? ''),
    );

    const internes = hrefs.filter((h) =>
      CIBLES_INTERNES.some((cible) => h === cible || h.startsWith(`${cible}/`)),
    );
    expect(internes, `Pied de page candidat : ${internes.join(', ')}`).toEqual([]);
  });

  test('la navigation candidat ne contient que des entrees candidat', async ({ page }) => {
    await connecterUnCandidat(page);
    await page.goto('/candidate/dashboard');

    const hrefs = await page.locator('aside a[href], #menu-mobile a[href], nav a[href]').evaluateAll((els) =>
      els.map((e) => e.getAttribute('href') ?? '').filter((h) => h.startsWith('/')),
    );

    for (const href of hrefs) {
      expect(
        href === '/' || href.startsWith('/candidate'),
        `Destination hors espace candidat dans la navigation : ${href}`,
      ).toBe(true);
    }
  });
});
