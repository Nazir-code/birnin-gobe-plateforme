import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

/**
 * Acces interne a l'administration, de bout en bout (ADR-003, ADR-006).
 *
 * L'administrateur est provisionne par le vrai mecanisme — `admin:create` —
 * et non par une inscription : il n'en existe aucune, c'est precisement ce
 * que cette suite verifie.
 *
 * La commande tourne dans le conteneur applicatif. `E2E_ARTISAN` permet de
 * viser une autre pile (nom de projet Compose different, execution hors
 * Docker) sans toucher au test :
 *
 *   E2E_ARTISAN="docker compose -p ma-pile exec -T app php artisan" npx playwright test
 */
const ARTISAN = process.env.E2E_ARTISAN ?? 'docker compose exec -T app php artisan';
const MOT_DE_PASSE = 'MotDePasseSolide!2026';

function jetonUnique() {
  return `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

/**
 * Provisionne un administrateur reel en base.
 *
 * Le mot de passe passe par l'entree standard, jamais par la ligne de
 * commande : il n'a a apparaitre ni dans l'historique du shell, ni dans la
 * table des processus de la machine qui execute les tests.
 */
function provisionnerAdmin(nom: string, email: string) {
  const [programme, ...args] = ARTISAN.split(' ');

  execFileSync(
    programme,
    [...args, 'admin:create', `--name=${nom}`, `--email=${email}`, '--password-stdin'],
    { input: MOT_DE_PASSE, stdio: ['pipe', 'pipe', 'pipe'] },
  );
}

/** Cree un compte candidat par le seul chemin public qui existe. */
async function inscrireCandidat(page: Page, email: string) {
  await page.goto('/register');
  await page.getByLabel('Nom complet').fill('Amina Issa');
  await page.getByLabel('Adresse e-mail').fill(email);
  await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
  await page.getByLabel('Confirmer le mot de passe').fill(MOT_DE_PASSE);
  await page.getByRole('button', { name: /créer mon compte/i }).click();
  await expect(page).toHaveURL(/\/candidate\/dashboard$/);
}

/**
 * Deconnexion par le vrai parcours : le bouton vit dans la barre laterale, qui
 * est repliee dans un tiroir sous 1024 px. On ouvre le tiroir si necessaire.
 */
async function seDeconnecter(page: Page) {
  const bouton = page.getByRole('button', { name: /se déconnecter/i });
  if (!(await bouton.first().isVisible().catch(() => false))) {
    await page.getByRole('button', { name: /ouvrir le menu/i }).click();
  }
  await bouton.first().click();
  await expect(page).toHaveURL(/\/admin\/login$/);
}

test.describe('Acces interne — administration', () => {
  test('provisionnement, connexion, identite reelle, deconnexion', async ({ page }) => {
    const nom = 'Aicha Diallo';
    const email = `admin-e2e-${jetonUnique()}@example.test`;

    provisionnerAdmin(nom, email);

    // — L'ecran interne est joignable, et ne propose aucune inscription
    await page.goto('/admin/login');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Accès interne');
    await expect(page.getByRole('link', { name: /créer un compte/i })).toHaveCount(0);

    // — Connexion
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
    await page.getByRole('button', { name: /accéder à l’administration/i }).click();

    // — Tableau de bord, avec le nom du compte reel et non celui de la maquette
    await expect(page).toHaveURL(/\/admin\/dashboard$/);
    await expect(page.getByRole('heading', { level: 2 }).first()).toContainText(nom);
    await expect(page.getByText('Aminata S.')).toHaveCount(0);

    // — La session survit a un rechargement
    await page.reload();
    await expect(page).toHaveURL(/\/admin\/dashboard$/);

    // — Deconnexion, puis acces immediatement retire
    await seDeconnecter(page);
    await page.goto('/admin/dashboard');
    await expect(page).toHaveURL(/\/admin\/login$/);

    // — Reconnexion possible avec le meme compte
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
    await page.getByRole('button', { name: /accéder à l’administration/i }).click();
    await expect(page).toHaveURL(/\/admin\/dashboard$/);
  });

  test('un visiteur est renvoye vers l’acces interne', async ({ page }) => {
    await page.goto('/admin/dashboard');
    await expect(page).toHaveURL(/\/admin\/login$/);
  });

  test('un candidat connecte recoit 403 sur le tableau de bord admin', async ({ page }) => {
    await inscrireCandidat(page, `candidat-e2e-${jetonUnique()}@example.test`);

    const reponse = await page.goto('/admin/dashboard');
    expect(reponse?.status()).toBe(403);
  });

  test('les identifiants d’un candidat n’ouvrent pas l’espace interne', async ({ page }) => {
    const email = `candidat-e2e-${jetonUnique()}@example.test`;
    await inscrireCandidat(page, email);

    // Deconnexion candidat : le bouton vit dans sa propre barre laterale.
    const bouton = page.getByRole('button', { name: /se déconnecter/i });
    if (!(await bouton.first().isVisible().catch(() => false))) {
      await page.getByRole('button', { name: /ouvrir le menu/i }).click();
    }
    await bouton.first().click();
    await expect(page).toHaveURL(/\/$/);

    await page.goto('/admin/login');
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
    await page.getByRole('button', { name: /accéder à l’administration/i }).click();

    // Refuse, et aucune session ouverte : le tableau de bord reste hors d'atteinte.
    await expect(page).toHaveURL(/\/admin\/login$/);
    await expect(page.getByRole('alert')).toBeVisible();

    await page.goto('/admin/dashboard');
    await expect(page).toHaveURL(/\/admin\/login$/);
  });

  test('l’acces interne n’est annonce nulle part cote public', async ({ page }) => {
    for (const url of ['/', '/login', '/register']) {
      await page.goto(url);

      const hrefs = await page.locator('a[href]').evaluateAll((els) =>
        els.map((e) => e.getAttribute('href') ?? ''),
      );
      const internes = hrefs.filter((h) => h === '/admin' || h.startsWith('/admin/'));
      expect(internes, `${url} expose : ${internes.join(', ')}`).toEqual([]);
    }
  });
});
