import { expect, test, type Page } from '@playwright/test';

/**
 * Etape 2 — Profil du candidat, de bout en bout.
 *
 * Aucun mock : chaque etape passe par Laravel et PostgreSQL. Le scenario
 * cherche a prendre en defaut une interface qui donnerait seulement
 * l'impression d'enregistrer — d'ou le rechargement, puis le cycle complet
 * deconnexion/reconnexion, avant chaque verification.
 */
const MOT_DE_PASSE = 'MotDePasseSolide!2026';

const PROFIL = {
  naissance: 'Tahoua',
  telephone: '90 12 34 56',
  telephoneNormalise: '+22790123456',
  telephoneSecondaire: '96 55 44 33',
  quartier: 'Yantala Haut',
  occupation: 'Développeuse indépendante',
  specialite: 'Systèmes d’information',
  accessibilite: 'Salle accessible en fauteuil pour le pitch.',
};

function compteUnique() {
  const jeton = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
  return { nom: 'Zeinabou Ali', email: `profil-e2e-${jeton}@example.test` };
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

/** Ouvre un brouillon puis rejoint l'etape 2 par le vrai bouton « Suivant ». */
async function allerAuProfil(page: Page) {
  await page.getByRole('button', { name: /commencer ma candidature/i }).click();
  await expect(page).toHaveURL(/\/candidate\/application\/\d+\/eligibility$/);
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/candidate\/application\/\d+\/profile$/);
}

async function remplirLeProfil(page: Page) {
  await page.getByLabel(/Où êtes-vous né/).fill(PROFIL.naissance);
  await page.getByLabel(/^Sexe/).selectOption({ label: 'Femme' });
  await page.getByLabel(/Téléphone principal/).fill(PROFIL.telephone);
  await page.getByLabel(/Téléphone secondaire/).fill(PROFIL.telephoneSecondaire);
  await page.getByLabel(/Comment préférez-vous être contacté/).selectOption({ label: 'SMS' });
  await page.getByLabel(/Région de résidence/).selectOption({ label: 'Niamey' });
  await page.getByLabel(/Quartier ou village/).fill(PROFIL.quartier);
  await page.getByLabel(/occupation principale/).fill(PROFIL.occupation);
  await page.getByLabel(/Niveau d’études/).selectOption({ label: 'Licence' });
  await page.getByLabel(/Spécialité ou domaine/).fill(PROFIL.specialite);
  await page.getByLabel(/aménagement particulier/).fill(PROFIL.accessibilite);
  await page.getByLabel(/aménagement particulier/).blur();
}

async function verifierLeProfil(page: Page) {
  await expect(page.getByLabel(/Où êtes-vous né/)).toHaveValue(PROFIL.naissance);
  await expect(page.getByLabel(/^Sexe/)).toHaveValue('FEMALE');
  // Le serveur a normalise le numero : c'est sa version qui revient.
  await expect(page.getByLabel(/Téléphone principal/)).toHaveValue(PROFIL.telephoneNormalise);
  await expect(page.getByLabel(/Comment préférez-vous être contacté/)).toHaveValue('SMS');
  await expect(page.getByLabel(/Région de résidence/)).toHaveValue('NE-8');
  await expect(page.getByLabel(/Quartier ou village/)).toHaveValue(PROFIL.quartier);
  await expect(page.getByLabel(/occupation principale/)).toHaveValue(PROFIL.occupation);
  await expect(page.getByLabel(/Niveau d’études/)).toHaveValue('BACHELOR');
  await expect(page.getByLabel(/aménagement particulier/)).toHaveValue(PROFIL.accessibilite);
}

test.describe('Étape 2 — Profil du candidat', () => {
  test('remplir, recharger, se reconnecter : les réponses restent', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await allerAuProfil(page);
    const urlProfil = page.url();

    await remplirLeProfil(page);

    // L'etat vient de la reponse du serveur, pas d'une animation.
    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });
    await expect(page.getByTestId('section-complete')).toBeVisible();

    // — Rechargement : les valeurs reviennent de PostgreSQL
    await page.reload();
    await verifierLeProfil(page);

    // — Deconnexion puis reconnexion
    await seDeconnecter(page);
    await seConnecter(page, email);

    await expect(page.getByTestId('candidature-existante')).toBeVisible();
    await page.getByRole('link', { name: /continuer ma candidature/i }).click();
    await expect(page).toHaveURL(urlProfil);

    await verifierLeProfil(page);
  });

  test('un clic sur « Enregistrer » est confirmé au candidat', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await allerAuProfil(page);

    // Rien n'a ete saisi : le clic doit malgre tout atteindre le serveur et
    // repondre. C'est le cas que `flush` ne couvrait pas — sans modification en
    // attente, il ne partait pas, et le candidat cliquait dans le vide.
    const confirmation = page.getByTestId('confirmation-sauvegarde');
    await expect(confirmation).toHaveCount(0);

    await page.getByRole('button', { name: /^enregistrer$/i }).click();
    await expect(confirmation).toContainText(/enregistr/i, { timeout: 15_000 });

    // Le message se referme a la main, sans attendre son effacement.
    await confirmation.getByRole('button', { name: /fermer le message/i }).click();
    await expect(confirmation).toHaveCount(0);

    // Et il revient au clic suivant, apres une vraie saisie.
    await page.getByLabel(/Où êtes-vous né/).fill(PROFIL.naissance);
    await page.getByRole('button', { name: /^enregistrer$/i }).click();
    await expect(confirmation).toBeVisible({ timeout: 15_000 });
  });

  test('les données déjà connues sont montrées, jamais redemandées', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);

    // Date de naissance saisie a l'etape 1.
    await page.getByRole('button', { name: /commencer ma candidature/i }).click();
    await page.getByLabel(/Quelle est votre date de naissance/).fill('1998-04-12');
    await page.getByRole('group', { name: /nationalité nigérienne/i }).getByRole('radio', { name: 'Oui' }).check();
    await page.getByRole('group', { name: /nationalité nigérienne/i }).getByRole('radio', { name: 'Oui' }).blur();
    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });

    await page.getByTestId('suivant').click();
    await expect(page).toHaveURL(/\/candidate\/application\/\d+\/profile$/);

    // L'etape 2 les affiche en lecture seule, avec le chemin pour les corriger.
    const connues = page.getByTestId('donnees-connues');
    await expect(connues).toContainText(nom);
    await expect(connues).toContainText(email);
    await expect(connues).toContainText('12/04/1998');
    await expect(connues).toContainText(/Modifier à l’étape Éligibilité/);

    // Et elles ne sont pas redemandees dans le formulaire.
    await expect(page.getByLabel(/Quelle est votre date de naissance/)).toHaveCount(0);
    await expect(page.getByLabel(/Nom complet/)).toHaveCount(0);
  });

  test('le serveur refuse un numéro invalide et n’enregistre rien', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await allerAuProfil(page);

    await page.getByLabel(/occupation principale/).fill(PROFIL.occupation);
    await page.getByLabel(/occupation principale/).blur();
    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });

    await page.getByLabel(/Téléphone principal/).fill('appelez-moi');
    await page.getByLabel(/Téléphone principal/).blur();

    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Erreur d’enregistrement', { timeout: 15_000 });
    await expect(page.getByRole('alert')).toContainText(/numéro joignable/i);

    // Rien de fautif n'est entre en base : le rechargement rend le dernier
    // etat accepte par le serveur.
    await page.reload();
    await expect(page.getByLabel(/Téléphone principal/)).toHaveValue('');
    await expect(page.getByLabel(/occupation principale/)).toHaveValue(PROFIL.occupation);
  });

  test('le parcours continue vers l’étape 3 et revient sans rien perdre', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await allerAuProfil(page);

    // Depuis la Phase 1F, l'etape 3 existe : « Suivant » y mene, dans l'ordre
    // du concours, sans sauter vers « Defi » qui vient apres.
    await page.getByTestId('suivant').click();
    await expect(page).toHaveURL(/\/candidate\/application\/\d+\/team$/);

    await page.getByTestId('precedent').click();
    await expect(page).toHaveURL(/\/candidate\/application\/\d+\/profile$/);

    // Le retour en arriere fonctionne aussi vers l'etape 1.
    await page.getByTestId('precedent').click();
    await expect(page).toHaveURL(/\/candidate\/application\/\d+\/eligibility$/);
  });

  test('un candidat ne peut pas ouvrir le profil d’un autre', async ({ page }) => {
    const proprietaire = compteUnique();
    await sInscrire(page, proprietaire.nom, proprietaire.email);
    await allerAuProfil(page);
    const urlDuProprietaire = page.url();

    await seDeconnecter(page);

    const intrus = compteUnique();
    await sInscrire(page, intrus.nom, intrus.email);

    const reponse = await page.goto(urlDuProprietaire);
    expect(reponse?.status()).toBe(403);
  });
});
