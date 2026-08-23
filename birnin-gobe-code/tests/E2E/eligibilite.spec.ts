import { expect, test, type Page } from '@playwright/test';

/**
 * Etape 1 — Eligibilite guidee, de bout en bout.
 *
 * Aucun mock : chaque etape passe par Laravel et PostgreSQL. Le verdict affiche
 * vient du serveur, jamais d'un calcul cote navigateur.
 *
 * La campagne de developpement ne publie aucune regle d'eligibilite. Depuis la
 * correction « configuration explicite », aucune regle ne peut donc bloquer :
 * c'est l'etat reel du projet, et c'est ce que ces scenarios verifient dans
 * l'interface. Le parcours bloquant (redirection, 403, reponses conservees,
 * reouverture apres correction) est couvert par EligibiliteCandidatTest, qui
 * peut configurer une campagne sans perturber les autres tests.
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

    // L'etape suivante dans l'ordre du concours est « Profil » (etape 2).
    await page.getByTestId('suivant').click();
    await expect(page).toHaveURL(/\/candidate\/application\/\d+\/profile$/);

    // Et l'on revient en arriere sans rien perdre.
    await page.getByTestId('precedent').click();
    await expect(page).toHaveURL(/\/candidate\/application\/\d+\/eligibility$/);
    await verifierLesReponses(page);
  });

  test('un critère non publié est expliqué au candidat sans le bloquer', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await commencerUneCandidature(page);

    // Reponses parfaitement coherentes, et pourtant : la campagne n'a publie
    // aucune regle, donc le serveur ne declare personne definitivement eligible.
    await remplirLEligibilite(page);
    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });

    const resultat = page.getByTestId('resultat-eligibilite');
    await expect(page.getByTestId('resultat-libelle')).toContainText(/sous réserve/i);
    await expect(page.getByTestId('resultat-libelle')).not.toContainText(/remplissez les conditions/i);

    // — Le motif est dit en langage candidat
    await expect(resultat).toContainText(/pas encore publiée/i);
    await expect(resultat).toContainText(/reste indicatif/i);
    await expect(resultat).toContainText(/ne remplace pas la vérification administrative/i);

    // — Et jamais en jargon technique
    const texte = (await resultat.innerText()).toLowerCase();
    for (const jargon of ['not_configured', 'settings', 'campaign.', 'null']) {
      expect(texte, `jargon visible : ${jargon}`).not.toContain(jargon);
    }

    // — Rien ne bloque : le parcours continue
    await expect(page.getByTestId('suivant')).toBeVisible();
    await page.getByTestId('suivant').click();
    await expect(page).toHaveURL(/\/candidate\/application\/\d+\/profile$/);
  });

  test('l’effectif reste validé par le serveur même sans règle publiée', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await commencerUneCandidature(page);

    await remplirLEligibilite(page);
    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });

    // « Non configure » ne veut pas dire « tout est accepte » : la validation
    // technique est independante des regles metier de la campagne.
    await page.getByLabel(/Combien de personnes/).fill('-15');
    await page.getByLabel(/Combien de personnes/).blur();

    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Erreur d’enregistrement', { timeout: 15_000 });

    // La saisie fautive n'est pas enregistree : le rechargement rend la
    // derniere valeur acceptee par le serveur.
    await page.reload();
    await expect(page.getByLabel(/Combien de personnes/)).toHaveValue(REPONSES.effectif);
  });
});
