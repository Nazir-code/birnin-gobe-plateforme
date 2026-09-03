import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

/**
 * L'appel à candidater, selon qui regarde la page d'accueil.
 *
 * **Ce fichier existe parce que le meme bouton a ete casse deux fois de suite,
 * et que les deux fois, la suite publique passait au vert.**
 *
 * Le 2 septembre 2026, une candidate signale que « ca ne montre rien » : le
 * bouton du pied de page pointait sur l'ancre `/#candidater`, heritee du
 * prototype. Corrige, deploye, verifie — par des tests qui visitent tous la page
 * en visiteur anonyme.
 *
 * Le 3 septembre, la meme plainte revient, cette fois de l'exploitant. Cause
 * differente : `/register` est derriere le middleware `guest`, et Laravel y
 * renvoie un utilisateur connecte vers `/`. Le bouton lancait donc une visite
 * Inertia vers la page ou l'on se trouvait deja. Aucun test ne se connectait
 * avant d'aller voir l'accueil : le trou etait exactement la.
 *
 * D'ou trois scenarios, un par role, et non un seul enrichi : un role qui casse
 * doit nommer lequel dans le rapport d'echec.
 */
const ARTISAN = process.env.E2E_ARTISAN ?? 'docker compose exec -T app php artisan';
const MOT_DE_PASSE = 'MotDePasseSolide!2026';

const LIBELLE_CTA = /Commencer ma candidature|Reprendre ma candidature/i;

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

/** Un candidat reel, cree par le vrai parcours : il reste connecte ensuite. */
async function inscrireCandidat(page: Page) {
  const email = `candidat-cta-${jetonUnique()}@example.test`;

  await page.goto('/register');
  await page.getByLabel('Nom complet').fill('Amina Issa');
  await page.getByLabel('Adresse e-mail').fill(email);
  await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
  await page.getByLabel('Confirmer le mot de passe').fill(MOT_DE_PASSE);
  await page.getByRole('button', { name: /créer mon compte/i }).click();
  await expect(page).toHaveURL(/\/candidate\/dashboard$/);

  return email;
}

test.describe('Appel à candidater', () => {
  test('visiteur anonyme : tous les boutons mènent à l’inscription', async ({ page }) => {
    await page.goto('/');

    const boutons = page.getByRole('link', { name: LIBELLE_CTA });
    const total = await boutons.count();

    expect(total).toBeGreaterThan(0);

    for (let i = 0; i < total; i++) {
      await expect(boutons.nth(i)).toHaveAttribute('href', '/register');
      await expect(boutons.nth(i)).toContainText('Commencer ma candidature');
    }
  });

  /**
   * Le candidat connecte reprend son dossier — et le libelle le dit.
   *
   * « Commencer » serait faux : son compte existe. Le test verifie les deux
   * moities, la destination et le mot, parce qu'un bouton qui mene au bon
   * endroit en annoncant le mauvais geste reste un defaut d'interface.
   */
  test('candidat connecté : le bouton mène à son espace et dit « reprendre »', async ({ page }) => {
    await inscrireCandidat(page);

    await page.goto('/');

    const boutons = page.getByRole('link', { name: LIBELLE_CTA });
    const total = await boutons.count();

    expect(total).toBeGreaterThan(0);

    for (let i = 0; i < total; i++) {
      await expect(boutons.nth(i)).toHaveAttribute('href', '/candidate/dashboard');
      await expect(boutons.nth(i)).toContainText('Reprendre ma candidature');
    }

    // Et le clic aboutit vraiment : c'est ce qu'aucune assertion sur `href`
    // n'etablit, et c'est precisement ce qui manquait le 3 septembre.
    await boutons.last().scrollIntoViewIfNeeded();
    await boutons.last().click();
    await expect(page).toHaveURL(/\/candidate\/dashboard$/);
  });

  /**
   * Un role interne ne se voit rien proposer.
   *
   * Ce n'est pas une commodite d'affichage : ADR-003 interdit au portail public
   * de mener aux espaces internes, et inviter un administrateur a deposer une
   * candidature n'aurait aucun sens. Le bouton disparait plutot que de mener a
   * une page qui le renverrait d'ou il vient.
   */
  test('administrateur connecté : aucun appel à candidater sur l’accueil', async ({ page }) => {
    const email = `admin-cta-${jetonUnique()}@example.test`;
    provisionnerAdmin('Aicha Diallo', email);
    await connecterAdmin(page, email);

    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

    await expect(page.getByRole('link', { name: LIBELLE_CTA })).toHaveCount(0);
    await expect(page.getByTestId('cta-candidater')).toHaveCount(0);
  });
});
