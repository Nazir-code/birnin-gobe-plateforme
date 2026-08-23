import { expect, test, type Page } from '@playwright/test';

/**
 * Etape 1 — Eligibilite guidee, de bout en bout.
 *
 * Aucun mock : chaque etape passe par Laravel et PostgreSQL. Le verdict affiche
 * vient du serveur, jamais d'un calcul cote navigateur — c'est precisement ce
 * que le scenario « non eligible » cherche a prouver.
 */
const MOT_DE_PASSE = 'MotDePasseSolide!2026';

const REPONSES = {
  naissance: '1998-04-12',
  region: 'Niamey',
  type: 'Équipe',
  effectif: '4',
};

function compteUnique() {
  const jeton = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
  return { nom: 'Hadiza Souley', email: `eligibilite-e2e-${jeton}@example.test` };
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

/** Ouvre un brouillon : la premiere section est desormais l'eligibilite. */
async function commencerUneCandidature(page: Page) {
  await page.getByRole('button', { name: /commencer ma candidature/i }).click();
  await expect(page).toHaveURL(/\/candidate\/application\/\d+\/eligibility$/);
}

async function remplirLEligibilite(page: Page, { nigerien = true, resident = true } = {}) {
  await page.getByLabel(/Quelle est votre date de naissance/).fill(REPONSES.naissance);
  await page.getByRole('group', { name: /nationalité nigérienne/i }).getByRole('radio', { name: nigerien ? 'Oui' : 'Non' }).check();
  await page.getByRole('group', { name: /Résidez-vous/i }).getByRole('radio', { name: resident ? 'Oui' : 'Non' }).check();
  await page.getByLabel(/Dans quelle région/).selectOption({ label: REPONSES.region });
  await page.getByLabel(/Sous quelle forme candidatez-vous/).selectOption({ label: REPONSES.type });
  await page.getByLabel(/Combien de personnes/).fill(REPONSES.effectif);
  // Sortir du dernier champ declenche la sauvegarde immediate.
  await page.getByLabel(/Combien de personnes/).blur();
}

async function verifierLesReponses(page: Page, { nigerien = true, resident = true } = {}) {
  await expect(page.getByLabel(/Quelle est votre date de naissance/)).toHaveValue(REPONSES.naissance);
  await expect(page.getByRole('group', { name: /nationalité nigérienne/i }).getByRole('radio', { name: nigerien ? 'Oui' : 'Non' })).toBeChecked();
  await expect(page.getByRole('group', { name: /Résidez-vous/i }).getByRole('radio', { name: resident ? 'Oui' : 'Non' })).toBeChecked();
  await expect(page.getByLabel(/Dans quelle région/)).toHaveValue('NE-8');
  await expect(page.getByLabel(/Sous quelle forme candidatez-vous/)).toHaveValue('TEAM');
  await expect(page.getByLabel(/Combien de personnes/)).toHaveValue(REPONSES.effectif);
}

test.describe('Étape 1 — Éligibilité', () => {
  test('répondre, recharger, se reconnecter : les réponses restent', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await commencerUneCandidature(page);
    const urlEligibilite = page.url();

    await remplirLEligibilite(page);

    // L'etat vient de la reponse du serveur, pas d'une animation.
    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });

    // — Rechargement : les valeurs reviennent de PostgreSQL
    await page.reload();
    await verifierLesReponses(page);

    // — Deconnexion puis reconnexion
    await seDeconnecter(page);
    await seConnecter(page, email);

    // Le tableau de bord reprend a l'etape 1, pas a une etape devinee.
    await expect(page.getByTestId('candidature-existante')).toBeVisible();
    await page.getByRole('link', { name: /continuer ma candidature/i }).click();
    await expect(page).toHaveURL(urlEligibilite);

    await verifierLesReponses(page);
  });

  test('un dossier sans règle bloquante ouvre l’étape suivante', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await commencerUneCandidature(page);

    await remplirLEligibilite(page);
    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });

    await page.getByTestId('suivant').click();
    await expect(page).toHaveURL(/\/candidate\/application\/\d+\/challenge$/);

    // Et l'on revient en arriere sans rien perdre.
    await page.getByTestId('precedent').click();
    await expect(page).toHaveURL(/\/candidate\/application\/\d+\/eligibility$/);
    await verifierLesReponses(page);
  });

  test('un candidat sans lien avec le Niger est déclaré non éligible', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await commencerUneCandidature(page);
    const urlEligibilite = page.url();

    await remplirLEligibilite(page, { nigerien: false, resident: false });
    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });

    // — Le verdict vient du serveur et s'affiche avec son motif
    const resultat = page.getByTestId('resultat-eligibilite');
    await expect(page.getByTestId('resultat-libelle')).toContainText(/ne remplissez pas les conditions/i);
    await expect(resultat).toContainText(/nationalité nigérienne ou résidant au Niger/i);
    await expect(resultat).toContainText(/ne remplace pas la vérification administrative/i);

    // — La suite est fermée : plus de lien « Suivant » actif
    await expect(page.getByTestId('suivant')).toHaveCount(0);
    await expect(page.getByRole('button', { name: /suivant/i })).toBeDisabled();

    // — Et l'URL saisie a la main ramene sur l'eligibilite, sans rien effacer
    const urlDefi = urlEligibilite.replace('/eligibility', '/challenge');
    await page.goto(urlDefi);
    await expect(page).toHaveURL(urlEligibilite);
    await verifierLesReponses(page, { nigerien: false, resident: false });

    // — Corriger rouvre la suite : les donnees n'ont jamais ete perdues
    await page.getByRole('group', { name: /Résidez-vous/i }).getByRole('radio', { name: 'Oui' }).check();
    await page.getByRole('group', { name: /Résidez-vous/i }).getByRole('radio', { name: 'Oui' }).blur();
    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });
    await expect(page.getByTestId('suivant')).toBeVisible();
  });

  test('une équipe d’une seule personne est refusée par le serveur', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await commencerUneCandidature(page);

    await remplirLEligibilite(page);
    await page.getByLabel(/Combien de personnes/).fill('1');
    await page.getByLabel(/Combien de personnes/).blur();

    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });
    await expect(page.getByTestId('resultat-eligibilite')).toContainText(/au moins 2 personnes/i);
    await expect(page.getByTestId('suivant')).toHaveCount(0);
  });
});
