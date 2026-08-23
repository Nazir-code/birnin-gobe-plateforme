import { expect, test, type Page } from '@playwright/test';

/**
 * Etape 3 — Structure / equipe, de bout en bout.
 *
 * Aucun mock : chaque etape passe par Laravel et PostgreSQL. C'est aussi le
 * scenario qui prouve que le parcours candidat ne comporte plus de trou —
 * l'etape 3 ouverte, « Defi » redevient la suite naturelle.
 */
const MOT_DE_PASSE = 'MotDePasseSolide!2026';

const MEMBRE = {
  nom: 'Aicha Ibrahim',
  role: 'Developpeuse',
  telephone: '90 11 22 33',
  telephoneNormalise: '+22790112233',
  competences: 'Applications mobiles, cartographie',
};

function compteUnique() {
  const jeton = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
  return { nom: 'Ramatou Garba', email: `structure-e2e-${jeton}@example.test` };
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

/** Ouvre un brouillon et repond a l'etape 1 avec la forme demandee. */
async function repondreEligibilite(page: Page, forme: 'Candidature individuelle' | 'Équipe' | 'Startup', effectif?: string) {
  await page.getByRole('button', { name: /commencer ma candidature/i }).click();
  await expect(page).toHaveURL(/\/candidate\/application\/\d+\/eligibility$/);

  await page.getByLabel(/Quelle est votre date de naissance/).fill('1996-05-20');
  await page.getByRole('group', { name: /nationalité nigérienne/i }).getByRole('radio', { name: 'Oui' }).check();
  await page.getByRole('group', { name: /Résidez-vous/i }).getByRole('radio', { name: 'Oui' }).check();
  await page.getByLabel(/Dans quelle région/).selectOption({ label: 'Niamey' });
  await page.getByLabel(/Sous quelle forme candidatez-vous/).selectOption({ label: forme });

  if (effectif) {
    await page.getByLabel(/Combien de personnes/).fill(effectif);
  }

  await page.getByLabel(/Sous quelle forme candidatez-vous/).blur();
  await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });
}

/** Traverse l'etape 2 sans la remplir : elle n'est pas le sujet ici. */
async function allerALEtape3(page: Page) {
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/candidate\/application\/\d+\/profile$/);
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/candidate\/application\/\d+\/team$/);
}

async function remplirUnMembre(page: Page) {
  await page.getByTestId('ajouter-membre').click();
  await page.getByLabel('Nom complet').fill(MEMBRE.nom);
  await page.getByLabel('Rôle dans le projet').fill(MEMBRE.role);
  await page.getByLabel('Téléphone').fill(MEMBRE.telephone);
  await page.getByLabel('Compétences').fill(MEMBRE.competences);
  await page.getByLabel(/accepte de figurer/).check();
}

test.describe('Étape 3 — Structure / équipe', () => {
  test('une équipe : remplir, recharger, se reconnecter, puis rejoindre le Défi', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await repondreEligibilite(page, 'Équipe', '2');
    await allerALEtape3(page);
    const urlEquipe = page.url();

    // La forme vient de l'etape 1, elle n'est pas redemandee ici.
    await expect(page.getByTestId('type-libelle')).toHaveText('Équipe');
    await expect(page.getByTestId('aucun-membre')).toBeVisible();

    await remplirUnMembre(page);
    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });

    // L'effectif annonce (2) correspond au membre ajoute plus le porteur.
    await expect(page.getByTestId('effectif-decrit')).toContainText('2 personnes');
    await expect(page.getByTestId('etat-section')).toContainText('Étape complète');

    // — Rechargement : les valeurs reviennent de PostgreSQL, normalisees
    await page.reload();
    await expect(page.getByLabel('Nom complet')).toHaveValue(MEMBRE.nom);
    await expect(page.getByLabel('Téléphone')).toHaveValue(MEMBRE.telephoneNormalise);
    await expect(page.getByLabel(/accepte de figurer/)).toBeChecked();

    // — Le parcours continue : « Defi » est enfin la suite naturelle
    await page.getByTestId('suivant').click();
    await expect(page).toHaveURL(/\/candidate\/application\/\d+\/challenge$/);
    await page.getByTestId('precedent').click();
    await expect(page).toHaveURL(urlEquipe);

    // — Deconnexion puis reconnexion
    await seDeconnecter(page);
    await seConnecter(page, email);
    await page.getByRole('link', { name: /continuer ma candidature/i }).click();
    await expect(page).toHaveURL(urlEquipe);
    await expect(page.getByLabel('Nom complet')).toHaveValue(MEMBRE.nom);
  });

  test('une candidature individuelle n’a rien à renseigner', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await repondreEligibilite(page, 'Candidature individuelle');
    await allerALEtape3(page);

    // Le §6.2 ne prevoit ni structure ni membres : l'ecran le dit au lieu
    // d'inventer des champs.
    await expect(page.getByTestId('rien-a-renseigner')).toBeVisible();
    await expect(page.getByTestId('bloc-membres')).toHaveCount(0);
    await expect(page.getByTestId('bloc-structure')).toHaveCount(0);

    // Enregistrer est un acte explicite, pas une visite de page.
    await page.getByRole('button', { name: /^enregistrer$/i }).click();
    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });

    await page.getByTestId('suivant').click();
    await expect(page).toHaveURL(/\/candidate\/application\/\d+\/challenge$/);
  });

  test('une startup renseigne ses données légales', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await repondreEligibilite(page, 'Startup', '2');
    await allerALEtape3(page);

    await expect(page.getByTestId('bloc-structure')).toBeVisible();
    await page.getByLabel('Dénomination').fill('Sahel Data');
    await page.getByLabel('Année de création').fill('2023');
    await page.getByLabel('Secteur d’activité').fill('Numérique');
    await page.getByLabel('Adresse').fill('Quartier Yantala, Niamey');
    await remplirUnMembre(page);

    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });
    await expect(page.getByTestId('etat-section')).toContainText('Étape complète');

    await page.reload();
    await expect(page.getByLabel('Dénomination')).toHaveValue('Sahel Data');
    await expect(page.getByLabel('Année de création')).toHaveValue('2023');
  });

  test('un écart avec l’effectif annoncé est signalé sans rien réécrire', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await repondreEligibilite(page, 'Équipe', '4');
    await allerALEtape3(page);

    await remplirUnMembre(page);
    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });

    // 4 annonces a l'etape 1, 2 decrits ici : l'ecran le dit et propose les
    // deux chemins, sans corriger l'un ou l'autre en douce.
    const etat = page.getByTestId('etat-section');
    await expect(etat).toContainText('Il reste à faire');
    await expect(etat).toContainText(/4 personnes/);
    await expect(page.getByTestId('effectif-decrit')).toContainText('2 personnes');
  });

  test('le serveur refuse un numéro de membre invalide', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await repondreEligibilite(page, 'Équipe', '2');
    await allerALEtape3(page);

    await remplirUnMembre(page);
    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });

    await page.getByLabel('Téléphone').fill('appelez-moi');
    await page.getByLabel('Téléphone').blur();

    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Erreur d’enregistrement', { timeout: 15_000 });
    await expect(page.getByRole('alert')).toContainText(/numéro joignable/i);

    // Rien de fautif n'est entre en base.
    await page.reload();
    await expect(page.getByLabel('Téléphone')).toHaveValue(MEMBRE.telephoneNormalise);
  });

  test('un candidat ne peut pas ouvrir l’équipe d’un autre', async ({ page }) => {
    const proprietaire = compteUnique();
    await sInscrire(page, proprietaire.nom, proprietaire.email);
    await repondreEligibilite(page, 'Équipe', '2');
    await allerALEtape3(page);
    const urlDuProprietaire = page.url();

    await seDeconnecter(page);

    const intrus = compteUnique();
    await sInscrire(page, intrus.nom, intrus.email);

    const reponse = await page.goto(urlDuProprietaire);
    expect(reponse?.status()).toBe(403);
  });
});
