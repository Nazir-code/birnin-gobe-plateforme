import { expect, test, type Page } from '@playwright/test';

/**
 * Persistance reelle de la candidature (Phase 1C).
 *
 * Aucun mock : chaque etape passe par Laravel et PostgreSQL. Ce que ce scenario
 * cherche a prendre en defaut, c'est precisement une interface qui donnerait
 * l'impression de sauvegarder — d'ou le rechargement, puis la deconnexion et la
 * reconnexion, avant chaque verification.
 */
const MOT_DE_PASSE = 'MotDePasseSolide!2026';

const REPONSES = {
  defi: 'L’acces a l’eau potable dans les quartiers peripheriques de la ville.',
  affectes: 'Les menages non raccordes au reseau, en particulier les femmes et les enfants.',
  region: 'Niamey',
  causes: 'Une extension urbaine plus rapide que le reseau de distribution.',
};

function compteUnique() {
  const jeton = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
  return { nom: 'Aissata Moussa', email: `candidature-e2e-${jeton}@example.test` };
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

async function seConnecter(page: Page, email: string) {
  await page.goto('/login');
  await page.getByLabel('Adresse e-mail').fill(email);
  await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
  await page.getByRole('button', { name: /^se connecter$/i }).click();
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

/** Les quatre champs reellement persistes de la section « Defi ». */
async function verifierLesReponses(page: Page) {
  await expect(page.getByLabel(/Quel est le défi principal/)).toHaveValue(REPONSES.defi);
  await expect(page.getByLabel(/Qui est le plus affecté/)).toHaveValue(REPONSES.affectes);
  await expect(page.getByLabel(/Où ce défi se pose-t-il/)).toHaveValue('NE-8');
  await expect(page.getByLabel(/Quelles sont les causes profondes/)).toHaveValue(REPONSES.causes);
}

/**
 * Ouvre un brouillon et rejoint la section « Defi ».
 *
 * Depuis la Phase 1E, « Defi » (etape 4) ne figure plus sur le parcours
 * propose : il a ete developpe avant « Structure / equipe » (etape 3), qui ne
 * l'est pas encore. Le bouton « Suivant » de l'etape 1 mene donc a « Profil ».
 * On rejoint « Defi » par son URL, exactement comme y arrivent les brouillons
 * anterieurs qui s'y trouvent deja. Voir ADR-009.
 */
async function ouvrirLeDefi(page: Page) {
  await page.getByRole('button', { name: /commencer ma candidature/i }).click();
  await expect(page).toHaveURL(/\/candidate\/application\/\d+\/eligibility$/);

  await page.goto(page.url().replace('/eligibility', '/challenge'));
  await expect(page).toHaveURL(/\/candidate\/application\/\d+\/challenge$/);
}

test.describe('Candidature persistante', () => {
  test('brouillon, saisie, rechargement, reconnexion', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);

    // — Aucune candidature : le tableau de bord propose d'en ouvrir une
    await expect(page.getByTestId('aucune-candidature')).toBeVisible();

    // — Laravel cree le brouillon, puis on rejoint « Defi » depuis l'etape 1
    await ouvrirLeDefi(page);
    const urlCandidature = page.url();

    // — Saisie des quatre champs
    await page.getByLabel(/Quel est le défi principal/).fill(REPONSES.defi);
    await page.getByLabel(/Qui est le plus affecté/).fill(REPONSES.affectes);
    await page.getByLabel(/Où ce défi se pose-t-il/).selectOption({ label: REPONSES.region });
    await page.getByLabel(/Quelles sont les causes profondes/).fill(REPONSES.causes);

    // — L'etat vient de la reponse du serveur, pas d'une animation
    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });

    // — Rechargement : les valeurs reviennent de PostgreSQL
    await page.reload();
    await verifierLesReponses(page);

    // — Deconnexion puis reconnexion avec le meme compte
    await seDeconnecter(page);
    await seConnecter(page, email);

    // — Le tableau de bord retrouve le dossier et propose de le reprendre
    await expect(page.getByTestId('candidature-existante')).toBeVisible();
    await expect(page.getByTestId('statut-candidature')).toHaveText('Brouillon');

    // Depuis l'ouverture de l'etape 3 (Phase 1F), le parcours n'a plus de trou :
    // « Defi » y est revenu et compte pour un neuvieme du dossier.
    await expect(page.getByTestId('progression')).toContainText('11%');
    await expect(page.getByTestId('etapes-hors-parcours')).toHaveCount(0);

    // La derniere section editee est « Defi » : c'est la que la reprise ramene.
    await page.getByRole('link', { name: /continuer ma candidature/i }).click();
    await expect(page).toHaveURL(urlCandidature);

    // — Les memes donnees, apres un cycle complet de session
    await verifierLesReponses(page);
  });

  test('un candidat ne peut pas ouvrir la candidature d’un autre', async ({ page }) => {
    const proprietaire = compteUnique();
    await sInscrire(page, proprietaire.nom, proprietaire.email);
    await ouvrirLeDefi(page);
    const urlDuProprietaire = page.url();

    await seDeconnecter(page);

    // Un second candidat, qui saisit l'URL de l'autre a la main.
    const intrus = compteUnique();
    await sInscrire(page, intrus.nom, intrus.email);

    const reponse = await page.goto(urlDuProprietaire);
    expect(reponse?.status()).toBe(403);
  });

  test('la sauvegarde automatique ne cree pas de second brouillon', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);

    await page.getByRole('button', { name: /commencer ma candidature/i }).click();
    // Un brouillon neuf s'ouvre sur l'etape 1 : c'est aussi la que « Continuer »
    // doit ramener tant qu'aucune autre section n'a ete editee.
    await expect(page).toHaveURL(/\/candidate\/application\/\d+\/eligibility$/);
    const premiere = page.url();

    // Retour au tableau de bord : le dossier existe deja, l'ecran doit proposer
    // de le reprendre et non d'en ouvrir un second.
    await page.goto('/candidate/dashboard');
    await expect(page.getByTestId('candidature-existante')).toBeVisible();
    await expect(page.getByRole('button', { name: /commencer ma candidature/i })).toHaveCount(0);

    await page.getByRole('link', { name: /continuer ma candidature/i }).click();
    await expect(page).toHaveURL(premiere);
  });
});
