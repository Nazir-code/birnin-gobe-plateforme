import { expect, test } from '@playwright/test';

for (const [name, path] of [
  ['public-home', '/'],
  ['candidate-dashboard', '/candidate/dashboard'],
  ['challenge-step', '/candidate/application/challenge'],
  ['admin-dashboard', '/admin/dashboard'],
  ['evaluator', '/evaluator/assignments'],
] as const) {
  test(`${name} renders`, async ({ page }) => {
    await page.goto(path);
    await expect(page.locator('body')).toBeVisible();
    await expect(page).toHaveTitle(/BIRNIN GOBE/i);
  });
}
