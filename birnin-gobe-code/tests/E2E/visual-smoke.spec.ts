import { expect, test } from '@playwright/test';

for (const [name, path] of [
  ['public-home', '/'],
  ['candidate-dashboard', '/candidate/dashboard'],
  // Les ecrans de candidature vivent sous l'identifiant du dossier ; ce chemin
  // est l'entree stable qui y redirige (ou vers la connexion pour un visiteur).
  ['challenge-step', '/candidate/application'],
  ['admin-dashboard', '/admin/dashboard'],
  ['evaluator', '/evaluator/assignments'],
] as const) {
  test(`${name} renders`, async ({ page }) => {
    await page.goto(path);
    await expect(page.locator('body')).toBeVisible();
    await expect(page).toHaveTitle(/BIRNIN GOBE/i);
  });
}
