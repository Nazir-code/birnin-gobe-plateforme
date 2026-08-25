import { expect, test, type Page } from '@playwright/test';

/**
 * « Mot de passe oublie », de bout en bout.
 *
 * Ce que ce scenario peut verifier dans un navigateur, et ce qu'il ne peut pas.
 *
 * Il peut : le lien depuis la connexion, les deux ecrans, la reponse identique
 * pour une adresse connue et une inconnue, le refus d'un lien invente, et le
 * fait qu'un formulaire arrose finisse par etre bloque.
 *
 * Il ne peut pas suivre le courriel jusqu'a la boite de reception : aucun
 * serveur SMTP n'est branche, et le transport de developpement ecrit dans les
 * journaux. Le parcours complet — lien recu, jeton consomme, mot de passe
 * change, ancien mot de passe refuse — est couvert par
 * `ReinitialisationMotDePasseTest`, qui intercepte la notification.
 */
const MOT_DE_PASSE = 'MotDePasseSolide!2026';

function compteUnique() {
  const jeton = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;

  return { nom: 'Amina Issa', email: `oubli-e2e-${jeton}@example.test` };
}

async function sInscrire(page: Page, nom: string, email: string) {
  await page.goto('/register');
  await page.getByLabel('Nom complet').fill(nom);
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
  await expect(page).toHaveURL(/\/$/);
}

test.describe('Mot de passe oublié', () => {
  test('la connexion mène au formulaire, qui confirme sans rien révéler', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await seDeconnecter(page);

    // — Le lien existe la ou on le cherche : sur l'ecran de connexion.
    await page.goto('/login');
    await page.getByRole('link', { name: /mot de passe oublié/i }).click();
    await expect(page).toHaveURL(/\/forgot-password$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Mot de passe oublié');

    // — Une adresse qui existe : confirmation, sans dire qu'elle existe.
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByRole('button', { name: /envoyer le lien/i }).click();

    const confirmation = page.getByTestId('lien-envoye');
    await expect(confirmation).toBeVisible();
    const messageConnu = (await confirmation.innerText()).trim();
    expect(messageConnu).toMatch(/si un compte existe/i);

    // — Une adresse qui n'existe pas : exactement le meme message.
    await page.goto('/forgot-password');
    await page.getByLabel('Adresse e-mail').fill(`inconnu-${Date.now()}@example.test`);
    await page.getByRole('button', { name: /envoyer le lien/i }).click();

    await expect(page.getByTestId('lien-envoye')).toBeVisible();
    expect((await page.getByTestId('lien-envoye').innerText()).trim()).toBe(messageConnu);
  });

  test('un lien de réinitialisation inventé est refusé', async ({ page }) => {
    const email = `inconnu-${Date.now()}@example.test`;

    await page.goto(`/reset-password/jeton-invente?email=${encodeURIComponent(email)}`);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Nouveau mot de passe');

    // L'adresse du lien est montree, non modifiable : un jeton retouche ne peut
    // que devenir invalide.
    await expect(page.getByTestId('adresse-du-lien')).toContainText(email);

    await page.getByLabel('Nouveau mot de passe').fill('UnAutreMotDePasse!2027');
    await page.getByLabel('Confirmer le mot de passe').fill('UnAutreMotDePasse!2027');
    await page.getByRole('button', { name: /enregistrer le mot de passe/i }).click();

    await expect(page).toHaveURL(/\/reset-password\//);
    await expect(page.getByRole('alert').first()).toContainText(/n’est plus valide|n'est plus valide/i);
  });

  test('les demandes répétées finissent par être bloquées', async ({ page }) => {
    const email = `arrosage-${Date.now()}@example.test`;

    // Cinq demandes passent, la sixieme est refusee — la limitation compte
    // toutes les demandes, y compris pour une adresse inconnue : sinon il
    // suffirait d'en changer a chaque essai.
    for (let i = 0; i < 5; i++) {
      await page.goto('/forgot-password');
      await page.getByLabel('Adresse e-mail').fill(email);
      await page.getByRole('button', { name: /envoyer le lien/i }).click();
      await expect(page.getByTestId('lien-envoye')).toBeVisible();
    }

    await page.goto('/forgot-password');
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByRole('button', { name: /envoyer le lien/i }).click();

    await expect(page.getByRole('alert').first()).toContainText(/trop de tentatives/i);
  });

  test('un visiteur déjà connecté n’a pas accès au formulaire', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);

    await page.goto('/forgot-password');
    await expect(page).not.toHaveURL(/\/forgot-password$/);
  });
});
