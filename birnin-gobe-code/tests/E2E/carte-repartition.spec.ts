import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

/**
 * Le survol de la carte du Niger — §9.1, §13.4.
 *
 * Ce qui se verifie de bout en bout, et pas ailleurs : que survoler une region
 * fait bien apparaitre son effectif, et que la ligne de detail existe avant tout
 * survol plutot que de surgir et decaler la mise en page.
 *
 * Le respect du seuil de discretion, lui, est verifie cote serveur par
 * CarteRepartitionTest : le nombre d'une region masquee n'est pas dans la charge
 * envoyee au navigateur, donc aucune interaction ne peut le reveler.
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

test.describe('Carte de repartition — survol', () => {
  test('survoler une region affiche son effectif', async ({ page }) => {
    const email = `admin-carte-${jetonUnique()}@example.test`;
    provisionnerAdmin('Aicha Diallo', email);
    await connecterAdmin(page, email);

    const detail = page.getByTestId('region-detail');

    // La ligne existe avant tout survol : sa hauteur est reservee, sinon la
    // carte se decalerait au premier passage de souris.
    await expect(detail).toBeVisible();
    await expect(detail).toContainText('Survolez une région');

    await page.getByTestId('region-NE-8').hover();

    // Le nom de la region, et une phrase sur son effectif — un nombre, « aucun
    // dossier », ou la mention de masquage selon les donnees en base.
    await expect(detail).toContainText('Niamey');
    await expect(detail).toContainText(/dossier|non communiqué|non mesurée/);
  });

  test('le detail est accessible au clavier, pas seulement a la souris', async ({ page }) => {
    const email = `admin-carte-${jetonUnique()}@example.test`;
    provisionnerAdmin('Aicha Diallo', email);
    await connecterAdmin(page, email);

    // WCAG 1.4.13 : une information disponible au survol doit l'etre au focus.
    await page.getByTestId('region-NE-4').focus();

    await expect(page.getByTestId('region-detail')).toContainText('Maradi');
  });
});
