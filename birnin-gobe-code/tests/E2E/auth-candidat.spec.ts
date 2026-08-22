import { expect, test } from '@playwright/test';

/**
 * Parcours candidat de bout en bout : inscription, deconnexion, reconnexion.
 *
 * Chaque execution cree un compte reel en base. L'adresse est unique par run
 * pour que la suite soit rejouable sans nettoyage prealable.
 */
const MOT_DE_PASSE = 'MotDePasseSolide!2026';

function compteUnique() {
  const jeton = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
  return {
    nom: 'Amina Issa',
    email: `candidat-e2e-${jeton}@example.test`,
  };
}


/**
 * Deconnexion par le vrai parcours : le bouton vit dans la barre laterale, qui
 * est repliee dans un tiroir sous . On ouvre le tiroir si necessaire.
 */
async function seDeconnecter(page: import('@playwright/test').Page) {
  const bouton = page.getByRole('button', { name: /se déconnecter/i });
  if (!(await bouton.first().isVisible().catch(() => false))) {
    await page.getByRole('button', { name: /ouvrir le menu/i }).click();
  }
  await bouton.first().click();

  // Attendre l'atterrissage sur l'accueil : sans cela, un `goto('/login')`
  // immediat part alors que la session existe encore, et le middleware `guest`
  // renvoie vers le tableau de bord.
  await expect(page).toHaveURL(/\/$/);
}

test.describe('Parcours d’authentification candidat', () => {
  test('inscription, deconnexion, reconnexion', async ({ page }) => {
    const { nom, email } = compteUnique();

    // — Depuis l'accueil, le CTA public mene a l'inscription candidat
    await page.goto('/');
    await page.getByRole('link', { name: /candidater/i }).first().click();
    await expect(page).toHaveURL(/\/register$/);

    // — Inscription
    await page.getByLabel('Nom complet').fill(nom);
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
    await page.getByLabel('Confirmer le mot de passe').fill(MOT_DE_PASSE);
    await page.getByRole('button', { name: /créer mon compte/i }).click();

    // — Le serveur redirige vers l'espace candidat, avec le vrai prenom
    await expect(page).toHaveURL(/\/candidate\/dashboard$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Amina');

    // — La session survit a un rechargement
    await page.reload();
    await expect(page).toHaveURL(/\/candidate\/dashboard$/);

    // — Deconnexion
    await seDeconnecter(page);
    await expect(page).toHaveURL(/\/$/);

    // — L'acces candidat est immediatement refuse
    await page.goto('/candidate/dashboard');
    await expect(page).toHaveURL(/\/login$/);

    // — Reconnexion avec le meme compte
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
    await page.getByRole('button', { name: /^se connecter$/i }).click();

    await expect(page).toHaveURL(/\/candidate\/dashboard$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Amina');
  });

  test('un mot de passe errone est refuse', async ({ page }) => {
    const { nom, email } = compteUnique();

    await page.goto('/register');
    await page.getByLabel('Nom complet').fill(nom);
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
    await page.getByLabel('Confirmer le mot de passe').fill(MOT_DE_PASSE);
    await page.getByRole('button', { name: /créer mon compte/i }).click();
    await expect(page).toHaveURL(/\/candidate\/dashboard$/);

    await seDeconnecter(page);

    await page.goto('/login');
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill('mauvais-mot-de-passe');
    await page.getByRole('button', { name: /^se connecter$/i }).click();

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('alert')).toBeVisible();
  });

  test('un visiteur est redirige vers la connexion', async ({ page }) => {
    await page.goto('/candidate/dashboard');
    await expect(page).toHaveURL(/\/login$/);
  });

  test('les ecrans d’authentification n’exposent aucun acces interne', async ({ page }) => {
    for (const url of ['/login', '/register']) {
      await page.goto(url);

      const hrefs = await page.locator('a[href]').evaluateAll((els) =>
        els.map((e) => e.getAttribute('href') ?? ''),
      );
      const internes = hrefs.filter((h) =>
        ['/admin', '/evaluator', '/jury'].some((c) => h === c || h.startsWith(`${c}/`)),
      );
      expect(internes, `${url} expose : ${internes.join(', ')}`).toEqual([]);

      const textes = await page.locator('a, button').allInnerTexts();
      for (const motif of [/se connecter comme/i, /acc[eè]s d[eé]monstration/i, /changer de r[oô]le/i]) {
        expect(textes.filter((t) => motif.test(t)), `${url} : libelle interdit`).toEqual([]);
      }
    }
  });

  test('l’espace candidat connecte n’expose aucun acces interne', async ({ page }) => {
    const { nom, email } = compteUnique();

    await page.goto('/register');
    await page.getByLabel('Nom complet').fill(nom);
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
    await page.getByLabel('Confirmer le mot de passe').fill(MOT_DE_PASSE);
    await page.getByRole('button', { name: /créer mon compte/i }).click();
    await expect(page).toHaveURL(/\/candidate\/dashboard$/);

    const hrefs = await page.locator('a[href]').evaluateAll((els) =>
      els.map((e) => e.getAttribute('href') ?? ''),
    );
    const internes = hrefs.filter((h) =>
      ['/admin', '/evaluator', '/jury'].some((c) => h === c || h.startsWith(`${c}/`)),
    );
    expect(internes, `Dashboard candidat expose : ${internes.join(', ')}`).toEqual([]);
  });
});
