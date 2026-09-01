import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

/**
 * Le bouton « Actualiser » des espaces internes.
 *
 * Ce qu'il vaut la peine de verifier de bout en bout, plutot qu'en test
 * unitaire : qu'il est reellement present sur les ecrans de pilotage, qu'il
 * declenche une requete Inertia et non un rechargement complet du document, et
 * qu'il ne suit pas sur les ecrans ou il n'a rien a faire.
 *
 * La distinction requete Inertia / rechargement complet est le coeur du sujet :
 * un rechargement ferait perdre le defilement et l'etat de la page, ce qui est
 * exactement ce que ce bouton existe pour eviter.
 */
const ARTISAN = process.env.E2E_ARTISAN ?? 'docker compose exec -T app php artisan';
const MOT_DE_PASSE = 'MotDePasseSolide!2026';

function jetonUnique() {
  return `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

function provisionnerAdmin(nom: string, email: string) {
  const [programme, ...args] = ARTISAN.split(' ');

  execFileSync(
    programme,
    [...args, 'admin:create', `--name=${nom}`, `--email=${email}`, '--password-stdin'],
    { input: MOT_DE_PASSE, stdio: ['pipe', 'pipe', 'pipe'] },
  );
}

async function connecterAdmin(page: Page, email: string) {
  await page.goto('/admin/login');
  await page.getByLabel('Adresse e-mail').fill(email);
  await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
  await page.getByRole('button', { name: /accéder à l’administration/i }).click();
  await expect(page).toHaveURL(/\/admin\/dashboard$/);
}

test.describe('Bouton d’actualisation', () => {
  test('present sur les ecrans internes, absent des ecrans candidat', async ({ page }) => {
    const email = `admin-refresh-${jetonUnique()}@example.test`;
    provisionnerAdmin('Aicha Diallo', email);
    await connecterAdmin(page, email);

    // Il vit dans l'ossature : quelques ecrans suffisent a le prouver.
    for (const chemin of ['/admin/dashboard', '/admin/applications', '/admin/alerts', '/admin/audit']) {
      await page.goto(chemin);
      await expect(page.getByTestId('actualiser')).toBeVisible();
    }

    // Le portail public n'affiche aucune donnee vivante : pas de bouton.
    await page.goto('/');
    await expect(page.getByTestId('actualiser')).toHaveCount(0);
  });

  test('recharge les donnees sans recharger le document', async ({ page }) => {
    const email = `admin-refresh-${jetonUnique()}@example.test`;
    provisionnerAdmin('Aicha Diallo', email);
    await connecterAdmin(page, email);

    // Un marqueur pose dans la page : il survit a une requete Inertia, et
    // disparait a un rechargement complet du document.
    await page.evaluate(() => {
      (window as unknown as { marqueur?: string }).marqueur = 'intact';
    });

    const requete = page.waitForResponse(
      (r) => r.url().includes('/admin/dashboard') && r.request().headers()['x-inertia'] === 'true',
    );

    await page.getByTestId('actualiser').click();
    await requete;

    const marqueur = await page.evaluate(() => (window as unknown as { marqueur?: string }).marqueur);

    expect(marqueur, 'Une actualisation Inertia ne doit pas recharger le document.').toBe('intact');
    await expect(page.getByTestId('actualiser')).toBeEnabled();
  });
});
