import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

/**
 * Montrer ou masquer le mot de passe, sur les deux ecrans de connexion.
 *
 * Ce que ces scenarios cherchent a prendre en defaut : un ceil qui changerait
 * autre chose que l'affichage. La saisie doit traverser la bascule intacte, la
 * touche Entree doit continuer de soumettre, « Rester connecte » et « Mot de
 * passe oublie ? » doivent rester ce qu'ils etaient. Une commodite d'affichage
 * qui abimerait la connexion serait un mauvais echange.
 */
const MOT_DE_PASSE = 'MotDePasseSolide!2026';
const ARTISAN = process.env.E2E_ARTISAN ?? 'docker compose exec -T app php artisan';

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

async function inscrireCandidat(page: Page, email: string) {
  await page.goto('/register');
  await page.getByLabel('Nom complet').fill('Amina Issa');
  await page.getByLabel('Adresse e-mail').fill(email);
  await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
  await page.getByLabel('Confirmer le mot de passe').fill(MOT_DE_PASSE);
  await page.getByRole('button', { name: /créer mon compte/i }).click();
  await expect(page).toHaveURL(/\/candidate\/dashboard$/);
}

async function seDeconnecter(page: Page) {
  const bouton = page.getByRole('button', { name: /se déconnecter/i });
  if (!(await bouton.first().isVisible().catch(() => false))) {
    await page.getByRole('button', { name: /ouvrir le menu/i }).click();
  }
  await bouton.first().click();

  // Attendre l'atterrissage : sans cela, le `goto('/login')` qui suit part
  // alors que la session existe encore, et le middleware `guest` renvoie au
  // tableau de bord.
  await expect(page).toHaveURL(/\/$/);
}

/**
 * Le coeur du comportement, identique sur les deux ecrans : la valeur saisie
 * ne bouge pas, seul l'attribut `type` change, et le libelle du bouton dit
 * toujours ce que le clic suivant fera.
 */
async function verifierLaBascule(page: Page) {
  const champ = page.getByLabel('Mot de passe', { exact: true });
  await champ.fill(MOT_DE_PASSE);

  // — Masque par defaut : rien n'est montre sans qu'on l'ait demande
  await expect(champ).toHaveAttribute('type', 'password');
  const afficher = page.getByRole('button', { name: 'Afficher le mot de passe' });
  await expect(afficher).toBeVisible();

  // — La cible reste confortable au doigt (>= 44 px de cote)
  const cible = await afficher.boundingBox();
  expect(cible).not.toBeNull();
  expect(cible!.width).toBeGreaterThanOrEqual(44);
  expect(cible!.height).toBeGreaterThanOrEqual(44);

  // — Montrer
  await afficher.click();
  await expect(champ).toHaveAttribute('type', 'text');
  await expect(champ).toHaveValue(MOT_DE_PASSE);

  // — Le libelle annonce desormais l'action inverse
  const masquer = page.getByRole('button', { name: 'Masquer le mot de passe' });
  await expect(masquer).toBeVisible();
  await expect(page.getByRole('button', { name: 'Afficher le mot de passe' })).toHaveCount(0);

  // — Et l'on revient a l'etat masque
  await masquer.click();
  await expect(champ).toHaveAttribute('type', 'password');
  await expect(champ).toHaveValue(MOT_DE_PASSE);

  // — Au clavier seul : le bouton s'atteint par tabulation et s'actionne
  await champ.focus();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'Afficher le mot de passe' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(champ).toHaveAttribute('type', 'text');
  await page.keyboard.press('Space');
  await expect(champ).toHaveAttribute('type', 'password');

  // — Le clic a la souris ne vole pas le focus du champ : sur mobile, le
  //   clavier resterait sinon ouvert pour se refermer aussitot.
  await champ.focus();
  await page.getByRole('button', { name: 'Afficher le mot de passe' }).click();
  await expect(champ).toBeFocused();
  await page.getByRole('button', { name: 'Masquer le mot de passe' }).click();
  await expect(champ).toHaveValue(MOT_DE_PASSE);
}

test.describe('Mot de passe visible — connexion', () => {
  test('candidat : montrer, masquer, et se connecter comme avant', async ({ page }) => {
    const email = `visibilite-e2e-${jetonUnique()}@example.test`;
    await inscrireCandidat(page, email);
    await seDeconnecter(page);

    await page.goto('/login');
    await page.getByLabel('Adresse e-mail').fill(email);
    await verifierLaBascule(page);

    // — « Rester connecte » repond toujours
    const resterConnecte = page.getByLabel('Rester connecté');
    await resterConnecte.check();
    await expect(resterConnecte).toBeChecked();

    // — Entree depuis le champ soumet le formulaire
    await page.getByLabel('Mot de passe', { exact: true }).press('Enter');
    await expect(page).toHaveURL(/\/candidate\/dashboard$/);
  });

  test('candidat : le lien « Mot de passe oublié ? » mène toujours où il faut', async ({ page }) => {
    await page.goto('/login');
    await page.getByRole('link', { name: /mot de passe oublié/i }).click();
    await expect(page).toHaveURL(/\/forgot-password$/);
  });

  test('administrateur : le même comportement, et rien d’autre ne bouge', async ({ page }) => {
    const nom = 'Aicha Diallo';
    const email = `admin-visibilite-${jetonUnique()}@example.test`;
    provisionnerAdmin(nom, email);

    await page.goto('/admin/login');
    await page.getByLabel('Adresse e-mail').fill(email);
    await verifierLaBascule(page);

    // — L'ecran interne reste ce qu'il etait : ni inscription, ni mot de passe
    //   oublie, ni « rester connecte » (ADR-003).
    await expect(page.getByRole('link', { name: /créer un compte/i })).toHaveCount(0);
    await expect(page.getByRole('link', { name: /mot de passe oublié/i })).toHaveCount(0);
    await expect(page.getByLabel('Rester connecté')).toHaveCount(0);

    // — Entree soumet, et la connexion aboutit
    await page.getByLabel('Mot de passe', { exact: true }).press('Enter');
    await expect(page).toHaveURL(/\/admin\/dashboard$/);
    await expect(page.getByRole('heading', { level: 2 }).first()).toContainText(nom);
  });

  test('les autres écrans de mot de passe sont inchangés', async ({ page }) => {
    // La bascule est demandee ecran par ecran, jamais imposee a tout champ
    // `password` : creation de compte et reinitialisation restent tels quels.
    await page.goto('/register');
    await expect(page.getByRole('button', { name: /^(Afficher|Masquer) le mot de passe$/ })).toHaveCount(0);

    await page.goto('/reset-password/jeton-de-test?email=personne%40example.test');
    await expect(page.getByRole('button', { name: /^(Afficher|Masquer) le mot de passe$/ })).toHaveCount(0);
  });
});
