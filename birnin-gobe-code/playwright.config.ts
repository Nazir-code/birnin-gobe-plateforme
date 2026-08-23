import { defineConfig, devices } from '@playwright/test';

/**
 * Les scenarios partagent une seule base PostgreSQL et, dans cette base, une
 * seule campagne ouverte — l'invariant d'ADR-008. Depuis que
 * `admin-eligibilite.spec.ts` publie et retire les criteres de cette campagne,
 * cet etat global est ecrit, plus seulement lu : deux fichiers executes en
 * parallele se marcheraient dessus, et le scenario le plus lent echouerait sur
 * des criteres publies par un autre.
 *
 * Un seul worker, donc — au prix de la duree, qui reste le bon echange pour une
 * suite de bout en bout qu'aucune CI n'execute encore.
 */
export default defineConfig({
  testDir: './tests/E2E',
  workers: 1,
  use: { baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8080', trace: 'on-first-retry' },
  projects: [
    { name: 'chromium-desktop', use: { ...devices['Desktop Chrome'] } },
    { name: 'android-mobile', use: { ...devices['Pixel 5'] } },
  ],
});
