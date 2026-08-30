import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

/**
 * Les compteurs du tableau de bord — §9.1.
 *
 * Ce test mesure les cartes plutot que de s'en remettre a l'oeil. Une rangee
 * desalignee ne casse rien et ne leve aucune erreur : elle se voit, se signale,
 * et revient a chaque intitule un peu plus long. La verifier ici la fige.
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

const CARTES = [
  'carte-candidatures',
  'carte-brouillons',
  'carte-soumis',
  'carte-admissibles',
  'carte-alertes',
];

test.describe('Tableau de bord — compteurs', () => {
  test('les cinq cartes sont cliquables et de meme taille', async ({ page }) => {
    const email = `admin-tdb-${jetonUnique()}@example.test`;
    provisionnerAdmin('Aicha Diallo', email);
    await connecterAdmin(page, email);

    const tailles: { hauteur: number; largeur: number }[] = [];

    for (const id of CARTES) {
      const carte = page.getByTestId(id);

      await expect(carte).toBeVisible();
      // Chacune est un vrai lien : un compteur qui a l'air cliquable sans
      // l'etre se lit comme une page cassee.
      await expect(carte).toHaveAttribute('href', /.+/);

      const boite = await carte.boundingBox();
      expect(boite, `La carte ${id} doit avoir une boite mesurable.`).not.toBeNull();
      tailles.push({ hauteur: boite!.height, largeur: boite!.width });
    }

    // Meme hauteur, au pixel de rendu pres. Sans `h-full`, la carte dont
    // l'intitule secondaire passe a deux lignes depasse les autres.
    const hauteurs = tailles.map((t) => Math.round(t.hauteur));
    expect(new Set(hauteurs).size, `Hauteurs relevees : ${hauteurs.join(', ')}`).toBe(1);

    // Meme largeur : les cinq colonnes de la grille sont egales en desktop.
    const largeurs = tailles.map((t) => Math.round(t.largeur));
    expect(new Set(largeurs).size, `Largeurs relevees : ${largeurs.join(', ')}`).toBe(1);
  });

  test('un compteur ouvre la liste filtree sur ce qu il annonce', async ({ page }) => {
    const email = `admin-tdb-${jetonUnique()}@example.test`;
    provisionnerAdmin('Aicha Diallo', email);
    await connecterAdmin(page, email);

    await page.getByTestId('carte-brouillons').click();

    await expect(page).toHaveURL(/\/admin\/applications\?status=DRAFT$/);
  });
});
