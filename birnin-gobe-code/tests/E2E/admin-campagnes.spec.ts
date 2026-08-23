import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

/**
 * Administration des campagnes, de bout en bout (ADR-007).
 *
 * Aucun mock : chaque etape passe par Laravel et PostgreSQL. Ce que ce scenario
 * cherche a prendre en defaut, c'est une interface qui donnerait l'impression
 * d'enregistrer — d'ou le rechargement avant chaque verification.
 *
 * L'administrateur est provisionne par le vrai mecanisme, `admin:create` : il
 * n'existe aucune inscription interne (ADR-006).
 */
const ARTISAN = process.env.E2E_ARTISAN ?? 'docker compose exec -T app php artisan';
const MOT_DE_PASSE = 'MotDePasseSolide!2026';

function jeton() {
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

async function seConnecterAdmin(page: Page, email: string) {
  await page.goto('/admin/login');
  await page.getByLabel('Adresse e-mail').fill(email);
  await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
  await page.getByRole('button', { name: /accéder à l’administration/i }).click();
  await expect(page).toHaveURL(/\/admin\/dashboard$/);
}

/** Ouvre les Campagnes par la barre laterale, repliee en tiroir sous 1024 px. */
async function allerAuxCampagnes(page: Page) {
  const lien = page.getByRole('link', { name: 'Campagnes', exact: true });
  if (!(await lien.first().isVisible().catch(() => false))) {
    await page.getByRole('button', { name: /ouvrir le menu/i }).click();
  }
  await lien.first().click();
  await expect(page).toHaveURL(/\/admin\/campaigns$/);
}

async function remplir(page: Page, valeurs: { nom: string; code: string; ouverture: string; cloture: string }) {
  await page.getByLabel('Nom de la campagne').fill(valeurs.nom);
  await page.getByLabel('Code de l’édition').fill(valeurs.code);
  await page.getByLabel('Ouverture des candidatures').fill(valeurs.ouverture);
  await page.getByLabel('Clôture des candidatures').fill(valeurs.cloture);
}

test.describe('Administration des campagnes', () => {
  test('creation, liste, modification, persistance', async ({ page }) => {
    const suffixe = jeton();
    const email = `admin-campagnes-${suffixe}@example.test`;
    const code = `E2E-${Date.now()}`;
    // Nom unique par execution : les deux profils Playwright ecrivent dans la
    // meme base, et deux lignes homonymes rendraient les selecteurs ambigus.
    const nom = `Edition E2E ${suffixe}`;

    provisionnerAdmin('Aicha Diallo', email);
    await seConnecterAdmin(page, email);

    // — La barre laterale mene reellement aux campagnes
    await allerAuxCampagnes(page);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Campagnes');

    // — Creation
    await page.getByRole('link', { name: /nouvelle campagne/i }).first().click();
    await expect(page).toHaveURL(/\/admin\/campaigns\/create$/);

    await remplir(page, {
      nom,
      code,
      ouverture: '2027-01-15T08:00',
      cloture: '2027-04-30T23:59',
    });
    await page.getByRole('button', { name: /^enregistrer$/i }).click();

    // — Retour a la liste, avec confirmation explicite de la sauvegarde
    await expect(page).toHaveURL(/\/admin\/campaigns$/);
    await expect(page.getByRole('status')).toContainText(nom);
    await expect(page.getByRole('cell', { name: code })).toBeVisible();

    // — Les valeurs survivent au rechargement
    await page.reload();
    await expect(page.getByRole('cell', { name: code })).toBeVisible();

    // — Modification
    await page.getByRole('link', { name: `Modifier la campagne ${nom}` }).click();
    await expect(page).toHaveURL(/\/admin\/campaigns\/\d+\/edit$/);
    await expect(page.getByLabel('Code de l’édition')).toHaveValue(code);

    await page.getByLabel('Nom de la campagne').fill(`${nom} renommee`);
    await page.getByRole('button', { name: /^enregistrer$/i }).click();

    await expect(page).toHaveURL(/\/admin\/campaigns$/);
    // Une ligne, pas deux : la modification remplace, elle ne duplique pas.
    // On vise la ligne plutot qu'une cellule — le lien « Modifier » porte le nom
    // dans son aria-label, donc plusieurs cellules le contiennent.
    await expect(page.getByRole('row').filter({ hasText: `${nom} renommee` })).toHaveCount(1);

    // — Et apres rechargement, c'est bien la base qui parle
    await page.reload();
    // Une ligne, pas deux : la modification remplace, elle ne duplique pas.
    // On vise la ligne plutot qu'une cellule — le lien « Modifier » porte le nom
    // dans son aria-label, donc plusieurs cellules le contiennent.
    await expect(page.getByRole('row').filter({ hasText: `${nom} renommee` })).toHaveCount(1);
  });

  test('la validation serveur refuse une cloture anterieure a l’ouverture', async ({ page }) => {
    const email = `admin-validation-${jeton()}@example.test`;
    provisionnerAdmin('Ousmane Ba', email);
    await seConnecterAdmin(page, email);

    await page.goto('/admin/campaigns/create');
    await remplir(page, {
      nom: `Edition incoherente ${jeton()}`,
      code: `E2E-KO-${Date.now()}`,
      ouverture: '2027-04-30T08:00',
      cloture: '2027-01-15T08:00',
    });
    await page.getByRole('button', { name: /^enregistrer$/i }).click();

    // On reste sur le formulaire, avec l'erreur annoncee aux lecteurs d'ecran.
    await expect(page).toHaveURL(/\/admin\/campaigns\/create$/);
    await expect(page.getByRole('alert')).toContainText(/postérieure/i);
  });

  test('un candidat ne peut pas ouvrir les campagnes', async ({ page }) => {
    const email = `candidat-campagnes-${jeton()}@example.test`;

    await page.goto('/register');
    await page.getByLabel('Nom complet').fill('Amina Issa');
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
    await page.getByLabel('Confirmer le mot de passe').fill(MOT_DE_PASSE);
    await page.getByRole('button', { name: /créer mon compte/i }).click();
    await expect(page).toHaveURL(/\/candidate\/dashboard$/);

    const reponse = await page.goto('/admin/campaigns');
    expect(reponse?.status()).toBe(403);
  });

  test('un visiteur est renvoye vers l’acces interne', async ({ page }) => {
    await page.goto('/admin/campaigns');
    await expect(page).toHaveURL(/\/admin\/login$/);
  });
});
