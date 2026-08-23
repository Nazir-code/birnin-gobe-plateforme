import { execFileSync } from 'node:child_process';
import { expect, test, type Browser, type Page } from '@playwright/test';

/**
 * Des criteres publies par l'administration au verdict lu par le candidat.
 *
 * Aucun mock, aucune ecriture directe en base : un administrateur publie les
 * criteres par l'ecran d'administration, puis un candidat deroule le vrai
 * parcours et lit ce que le serveur en conclut. C'est le seul scenario qui
 * prouve que les deux espaces parlent bien de la meme campagne.
 *
 * Les trois verdicts que ces criteres produisent y sont couverts :
 *   ELIGIBLE     — les cinq criteres publies, reponses conformes ;
 *   INELIGIBLE   — un critere publie que les reponses ne remplissent pas ;
 *   TO_CONFIRM   — un critere laisse non publie, donc « sous reserve ».
 *
 * Ce fichier ecrit dans les parametres de la campagne active, que les autres
 * scenarios lisent. D'ou deux precautions : il s'execute en serie, et il rend
 * la campagne a son etat « aucun critere publie » a la fin — etat reel du
 * projet, sur lequel `eligibilite.spec.ts` s'appuie. C'est aussi la raison pour
 * laquelle la configuration Playwright n'autorise qu'un seul worker.
 */
const ARTISAN = process.env.E2E_ARTISAN ?? 'docker compose exec -T app php artisan';
const MOT_DE_PASSE = 'MotDePasseSolide!2026';

/**
 * Origine du serveur teste, reprise de `playwright.config.ts`.
 *
 * Elle est redite ici parce que les contextes crees a la main — l'onglet
 * candidat, l'onglet de nettoyage — n'heritent pas des options `use` du projet.
 */
const BASE_URL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8080';

/** Un candidat de 26 ans : dans la tranche 18-35 publiee par ces scenarios. */
const NAISSANCE = `${new Date().getFullYear() - 26}-04-12`;

const ZONES = /régions ouvertes/i;
const FORMES = /types de candidat/i;

const TOUTES_LES_ZONES = [
  'Agadez', 'Diffa', 'Dosso', 'Maradi', 'Tahoua', 'Tillabéri', 'Zinder', 'Niamey',
];
const TOUTES_LES_FORMES = ['Candidature individuelle', 'Équipe', 'Startup'];

test.describe.configure({ mode: 'serial' });

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

async function seConnecterAdmin(page: Page) {
  const email = `admin-eligibilite-${jeton()}@example.test`;
  provisionnerAdmin('Ousmane Ba', email);

  await page.goto('/admin/login');
  await page.getByLabel('Adresse e-mail').fill(email);
  await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
  await page.getByRole('button', { name: /accéder à l’administration/i }).click();
  await expect(page).toHaveURL(/\/admin\/dashboard$/);
}

/** Ouvre les criteres de la campagne qui recoit reellement les dossiers. */
async function ouvrirLesCriteresDeLaCampagneActive(page: Page) {
  await page.goto('/admin/campaigns');

  // La liste peut contenir des campagnes laissees par d'autres scenarios : on
  // vise celle que `ActiveCampaign` designe, la seule ou les dossiers arrivent.
  const code = (await page.getByTestId('campagne-active-code').innerText()).trim();

  const lien = page
    .getByRole('row')
    .filter({ hasText: code })
    .getByRole('link', { name: /critères d’éligibilité/i });

  // Le lien est bien present et nomme pour les lecteurs d'ecran...
  await expect(lien).toBeVisible();

  // ...mais on suit son `href` plutot que de le cliquer. La colonne d'actions
  // vit dans le conteneur a defilement horizontal du tableau (contrainte
  // mobile-first : c'est le tableau qui defile, jamais la page), et sous 400 px
  // le clic simule s'y fait intercepter par les cartes animees au defilement.
  // C'est une limite du pilotage automatique, pas de l'ecran : ce qui est
  // verifie ici reste que la liste mene aux criteres de CETTE campagne.
  await page.goto(new URL((await lien.getAttribute('href')) ?? '', BASE_URL).toString());

  await expect(page).toHaveURL(/\/admin\/campaigns\/\d+\/eligibility$/);
}

async function enregistrer(page: Page) {
  await page.getByRole('button', { name: /^enregistrer$/i }).click();
  await expect(page.getByRole('status')).toBeVisible();
}

/**
 * Amene un groupe de cases a cocher a l'etat exact demande.
 *
 * Case par case plutot que par le bouton « Tout cocher » : celui-ci depend de
 * l'etat courant, et une case deja cochee par un scenario precedent rendrait le
 * resultat imprevisible.
 */
async function definirCases(page: Page, legende: RegExp, libelles: string[]) {
  const groupe = page.getByRole('group', { name: legende });
  const cases = groupe.getByRole('checkbox');

  for (let i = 0; i < (await cases.count()); i++) {
    const item = cases.nth(i);
    const libelle = (await item.evaluate((el) => el.closest('label')?.textContent ?? '')).trim();

    if (libelles.includes(libelle)) {
      await item.check();
    } else {
      await item.uncheck();
    }
  }
}

/** Publie les cinq criteres, zones comprises, sur la campagne active. */
async function publierLesCinqCriteres(page: Page) {
  await ouvrirLesCriteresDeLaCampagneActive(page);

  await page.getByLabel('Âge minimum').fill('18');
  await page.getByLabel('Âge maximum').fill('35');
  await page.getByLabel('Condition de nationalité ou de résidence').selectOption('true');
  await definirCases(page, ZONES, TOUTES_LES_ZONES);
  await definirCases(page, FORMES, TOUTES_LES_FORMES);
  await page.getByLabel('Effectif minimum').fill('2');
  await page.getByLabel('Effectif maximum').fill('10');

  await enregistrer(page);
  await expect(page.getByTestId('criteres-publies')).toContainText('5 critères publiés sur 5');
}

/** Remet la campagne dans l'etat « aucun critere publie ». */
async function effacerLesCriteres(page: Page) {
  await ouvrirLesCriteresDeLaCampagneActive(page);

  await page.getByLabel('Âge minimum').fill('');
  await page.getByLabel('Âge maximum').fill('');
  await page.getByLabel('Date de référence').fill('');
  await page.getByLabel('Condition de nationalité ou de résidence').selectOption('');
  await definirCases(page, ZONES, []);
  await definirCases(page, FORMES, []);
  await page.getByLabel('Effectif minimum').fill('');
  await page.getByLabel('Effectif maximum').fill('');

  await enregistrer(page);
  await expect(page.getByTestId('criteres-publies')).toContainText('0 critère publié sur 5');
}

/**
 * Un onglet candidat, dans un contexte distinct de celui de l'administrateur.
 *
 * Les deux espaces ne partagent pas de session (ADR-003) ; les melanger dans un
 * meme contexte masquerait une regression de separation.
 */
async function ouvrirUnOngletIsole(browser: Browser) {
  const contexte = await browser.newContext({ baseURL: BASE_URL });

  return contexte.newPage();
}

/** Inscrit un candidat, ouvre son dossier et repond a l'auto-test. */
async function candidatQuiRepond(page: Page, { region = 'Niamey' } = {}) {
  await page.goto('/register');
  await page.getByLabel('Nom complet').fill('Hadiza Souley');
  await page.getByLabel('Adresse e-mail').fill(`candidat-eligibilite-${jeton()}@example.test`);
  await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
  await page.getByLabel('Confirmer le mot de passe').fill(MOT_DE_PASSE);
  await page.getByRole('button', { name: /créer mon compte/i }).click();
  await expect(page).toHaveURL(/\/candidate\/dashboard$/);

  await page.getByRole('button', { name: /commencer ma candidature/i }).click();
  await expect(page).toHaveURL(/\/candidate\/application\/\d+\/eligibility$/);

  await page.getByLabel(/Quelle est votre date de naissance/).fill(NAISSANCE);
  await page.getByRole('group', { name: /nationalité nigérienne/i }).getByRole('radio', { name: 'Oui' }).check();
  await page.getByRole('group', { name: /Résidez-vous/i }).getByRole('radio', { name: 'Oui' }).check();
  await page.getByLabel(/Dans quelle région/).selectOption({ label: region });
  await page.getByLabel(/Sous quelle forme candidatez-vous/).selectOption({ label: 'Candidature individuelle' });
  await page.getByLabel(/Sous quelle forme candidatez-vous/).blur();

  // Attendre « Enregistré » ne suffirait pas : l'indicateur peut encore porter
  // la sauvegarde du champ precedent pendant que la derniere est en vol. Le
  // signal fiable est le verdict lui-meme — il cesse d'annoncer des reponses
  // incompletes seulement quand le serveur a recu la derniere.
  await expect(page.getByTestId('resultat-libelle')).not.toContainText(/incomplètes/i, { timeout: 20_000 });
  await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });

  // Le verdict est rendu par le serveur : on relit la page plutot que de faire
  // confiance a l'etat laisse par la derniere reponse XHR.
  await page.reload();

  return page.url();
}

test.describe('Critères d’éligibilité — de l’administration au candidat', () => {
  // La campagne active est une ressource partagee par toute la suite E2E : elle
  // est rendue a son etat de depart, quel que soit le sort des scenarios.
  test.afterAll(async ({ browser }) => {
    const page = await ouvrirUnOngletIsole(browser);
    await seConnecterAdmin(page);
    await effacerLesCriteres(page);
    await page.context().close();
  });

  test('les cinq critères publiés rendent un candidat conforme éligible', async ({ page, browser }) => {
    await seConnecterAdmin(page);
    await publierLesCinqCriteres(page);

    // Les valeurs viennent bien de PostgreSQL, pas de l'etat du formulaire.
    await page.reload();
    await expect(page.getByLabel('Âge minimum')).toHaveValue('18');
    await expect(page.getByLabel('Condition de nationalité ou de résidence')).toHaveValue('true');
    await expect(page.getByTestId('critere-ZONE')).toHaveAttribute('data-configure', 'oui');

    const candidat = await ouvrirUnOngletIsole(browser);
    await candidatQuiRepond(candidat);

    await expect(candidat.getByTestId('resultat-libelle')).toContainText(/remplissez les conditions/i);
    await expect(candidat.getByTestId('resultat-eligibilite')).not.toContainText(/pas encore publié/i);

    // Rien ne bloque : l'etape suivante du parcours ouvert s'ouvre — « Profil »,
    // etape 2 (ADR-009), et non « Defi » qui vit derriere l'etape 3 non developpee.
    await candidat.getByTestId('suivant').click();
    await expect(candidat).toHaveURL(/\/candidate\/application\/\d+\/profile$/);

    await candidat.context().close();
  });

  test('un critère publié que le candidat ne remplit pas ferme la suite du dossier', async ({ page, browser }) => {
    await seConnecterAdmin(page);
    await publierLesCinqCriteres(page);

    // Une seule zone ouverte, et ce n'est pas celle du candidat.
    await definirCases(page, ZONES, ['Agadez']);
    await enregistrer(page);

    const candidat = await ouvrirUnOngletIsole(browser);
    const urlEligibilite = await candidatQuiRepond(candidat, { region: 'Niamey' });

    await expect(candidat.getByTestId('resultat-libelle')).toContainText(/ne remplissez pas les conditions/i);
    // Le motif est dit en langage candidat, avec la liste des zones ouvertes.
    await expect(candidat.getByTestId('resultat-eligibilite')).toContainText(/Agadez/);

    // La suite du dossier n'est pas proposee...
    await expect(candidat.getByTestId('suivant')).toHaveCount(0);

    // ...et ne s'ouvre pas davantage en tapant l'URL : la barriere est portee
    // par le serveur, pas par l'absence de lien. Le candidat est renvoye sur
    // l'etape 1, ou le motif lui est explique.
    // Les deux sections posterieures developpees sont verifiees : « Profil »,
    // etape suivante du parcours, et « Defi », developpee mais hors parcours.
    for (const section of ['/profile', '/challenge']) {
      await candidat.goto(urlEligibilite.replace('/eligibility', section));
      await expect(candidat).toHaveURL(/\/candidate\/application\/\d+\/eligibility$/);
    }

    await candidat.context().close();
  });

  test('un critère laissé non publié laisse le candidat poursuivre sous réserve', async ({ page, browser }) => {
    await seConnecterAdmin(page);
    await publierLesCinqCriteres(page);

    // Les zones redeviennent non publiees : quatre criteres sur cinq.
    await definirCases(page, ZONES, []);
    await enregistrer(page);
    await expect(page.getByTestId('criteres-publies')).toContainText('4 critères publiés sur 5');
    await expect(page.getByTestId('critere-ZONE')).toHaveAttribute('data-configure', 'non');

    const candidat = await ouvrirUnOngletIsole(browser);
    await candidatQuiRepond(candidat);

    await expect(candidat.getByTestId('resultat-libelle')).toContainText(/sous réserve/i);
    await expect(candidat.getByTestId('resultat-eligibilite')).toContainText(/pas encore publiées/i);

    // « Sous reserve » n'est pas un refus : le parcours continue vers l'etape 2.
    await candidat.getByTestId('suivant').click();
    await expect(candidat).toHaveURL(/\/candidate\/application\/\d+\/profile$/);

    await candidat.context().close();
  });

  test('la validation serveur refuse un âge maximum inférieur au minimum', async ({ page }) => {
    await seConnecterAdmin(page);
    await ouvrirLesCriteresDeLaCampagneActive(page);

    await page.getByLabel('Âge minimum').fill('35');
    await page.getByLabel('Âge maximum').fill('18');
    await page.getByRole('button', { name: /^enregistrer$/i }).click();

    // On reste sur l'ecran, avec l'erreur annoncee aux lecteurs d'ecran.
    await expect(page).toHaveURL(/\/admin\/campaigns\/\d+\/eligibility$/);
    await expect(page.getByRole('alert')).toContainText(/inférieur/i);
  });

  test('un candidat ne peut pas ouvrir les critères d’une campagne', async ({ page, browser }) => {
    await seConnecterAdmin(page);
    await ouvrirLesCriteresDeLaCampagneActive(page);
    const urlCriteres = page.url();

    const candidat = await ouvrirUnOngletIsole(browser);
    await candidat.goto('/register');
    await candidat.getByLabel('Nom complet').fill('Amina Issa');
    await candidat.getByLabel('Adresse e-mail').fill(`candidat-criteres-${jeton()}@example.test`);
    await candidat.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
    await candidat.getByLabel('Confirmer le mot de passe').fill(MOT_DE_PASSE);
    await candidat.getByRole('button', { name: /créer mon compte/i }).click();
    await expect(candidat).toHaveURL(/\/candidate\/dashboard$/);

    const reponse = await candidat.goto(urlCriteres);
    expect(reponse?.status()).toBe(403);

    await candidat.context().close();
  });
});
