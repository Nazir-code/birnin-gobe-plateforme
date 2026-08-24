import { execFileSync } from 'node:child_process';
import { expect, test, type Browser, type Page } from '@playwright/test';

/**
 * Consultation des candidatures par l'administration, de bout en bout.
 *
 * Aucun mock : un candidat depose un vrai dossier par le parcours candidat,
 * puis un administrateur le retrouve dans la liste, le filtre, le cherche et
 * l'ouvre. C'est le seul scenario qui prouve que l'ecran d'administration lit
 * bien ce que le candidat a ecrit.
 *
 * La base E2E est partagee et contient les dossiers laisses par les autres
 * scenarios : chaque test cree donc son propre candidat, avec un nom unique, et
 * se sert de la recherche pour le retrouver — jamais de la premiere ligne.
 *
 * Ce fichier ne suppose en revanche **rien** de ce que les autres scenarios ont
 * laisse. Il a longtemps dependu, sans le dire, d'une edition ouverte creee par
 * `admin-campagnes.spec.ts` : sur une base fraiche, le candidat n'avait aucune
 * candidature a commencer et le scenario echouait — un echec qui ne disait rien
 * du code teste, seulement de l'ordre des fichiers. `beforeAll` etablit
 * desormais cette condition lui-meme.
 */
const ARTISAN = process.env.E2E_ARTISAN ?? 'docker compose exec -T app php artisan';
const MOT_DE_PASSE = 'MotDePasseSolide!2026';
const BASE_URL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8080';

function jeton() {
  return `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
}

function provisionnerAdmin(email: string) {
  const [programme, ...args] = ARTISAN.split(' ');
  execFileSync(
    programme,
    [...args, 'admin:create', '--name=Aicha Diallo', `--email=${email}`, '--password-stdin'],
    { input: MOT_DE_PASSE, stdio: ['pipe', 'pipe', 'pipe'] },
  );
}

async function seConnecterAdmin(page: Page) {
  const email = `admin-candidatures-${jeton()}@example.test`;
  provisionnerAdmin(email);

  await page.goto('/admin/login');
  await page.getByLabel('Adresse e-mail').fill(email);
  await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
  await page.getByRole('button', { name: /accéder à l’administration/i }).click();
  await expect(page).toHaveURL(/\/admin\/dashboard$/);
}

/** Depose un vrai dossier : inscription, brouillon, reponses d'eligibilite. */
async function candidatQuiDepose(browser: Browser, nom: string) {
  const contexte = await browser.newContext({ baseURL: BASE_URL });
  const page = await contexte.newPage();
  const email = `candidature-e2e-${jeton()}@example.test`;

  await page.goto('/register');
  await page.getByLabel('Nom complet').fill(nom);
  await page.getByLabel('Adresse e-mail').fill(email);
  await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
  await page.getByLabel('Confirmer le mot de passe').fill(MOT_DE_PASSE);
  await page.getByRole('button', { name: /créer mon compte/i }).click();
  await expect(page).toHaveURL(/\/candidate\/dashboard$/);

  await page.getByRole('button', { name: /commencer ma candidature/i }).click();
  await expect(page).toHaveURL(/\/candidate\/application\/\d+\/eligibility$/);

  await page.getByLabel(/Quelle est votre date de naissance/).fill(`${new Date().getFullYear() - 26}-04-12`);
  await page.getByRole('group', { name: /nationalité nigérienne/i }).getByRole('radio', { name: 'Oui' }).check();
  await page.getByRole('group', { name: /Résidez-vous/i }).getByRole('radio', { name: 'Oui' }).check();
  await page.getByLabel(/Dans quelle région/).selectOption({ label: 'Niamey' });
  await page.getByLabel(/Sous quelle forme candidatez-vous/).selectOption({ label: 'Équipe' });
  await page.getByLabel(/Combien de personnes/).fill('4');
  await page.getByLabel(/Combien de personnes/).blur();

  // Le verdict cesse d'annoncer des reponses incompletes quand le serveur a
  // recu la derniere : signal plus fiable que l'indicateur de sauvegarde.
  await expect(page.getByTestId('resultat-libelle')).not.toContainText(/incomplètes/i, { timeout: 20_000 });

  await contexte.close();

  return { nom, email };
}

/** Ouvre les Candidatures par la barre laterale, repliee en tiroir sous 1024 px. */
async function allerAuxCandidatures(page: Page) {
  const lien = page.getByRole('link', { name: 'Candidatures', exact: true });
  if (!(await lien.first().isVisible().catch(() => false))) {
    await page.getByRole('button', { name: /ouvrir le menu/i }).click();
  }
  await lien.first().click();
  await expect(page).toHaveURL(/\/admin\/applications$/);
}

/** Format attendu par un champ `datetime-local`. */
function saisieDate(decalageJours: number): string {
  const date = new Date(Date.now() + decalageJours * 86_400_000);
  const deuxChiffres = (n: number) => String(n).padStart(2, '0');

  return `${date.getFullYear()}-${deuxChiffres(date.getMonth() + 1)}-${deuxChiffres(date.getDate())}`
    + `T${deuxChiffres(date.getHours())}:${deuxChiffres(date.getMinutes())}`;
}

/**
 * Garantit qu'une edition recoit les candidatures.
 *
 * Idempotente, et il le faut : l'invariant d'ADR-008 n'admet qu'une seule
 * edition ouverte a la fois, et ce fichier s'execute sous deux profils
 * Playwright contre la meme base. On regarde donc d'abord si une edition active
 * existe — `campagne-active-code` n'est rendu que dans ce cas — et on n'en cree
 * une que s'il n'y en a pas.
 *
 * Tout passe par les vrais ecrans d'administration : creation puis ouverture.
 * Une insertion directe en base irait plus vite mais testerait autre chose que
 * ce que fait un administrateur.
 */
async function garantirUneEditionOuverte(page: Page) {
  await page.goto('/admin/campaigns');

  if ((await page.getByTestId('campagne-active-code').count()) > 0) {
    return;
  }

  const suffixe = jeton();
  const nom = `Edition candidatures ${suffixe}`;

  await page.goto('/admin/campaigns/create');
  await page.getByLabel('Nom de la campagne').fill(nom);
  await page.getByLabel('Code de l’édition').fill(`E2E-CAND-${suffixe}`);
  await page.getByLabel('Ouverture des candidatures').fill(saisieDate(-1));
  await page.getByLabel('Clôture des candidatures').fill(saisieDate(60));
  await page.getByRole('button', { name: /^enregistrer$/i }).click();
  await expect(page).toHaveURL(/\/admin\/campaigns$/);

  // Creee en preparation : il faut encore l'ouvrir pour qu'elle recoive.
  await page.getByRole('link', { name: `Modifier la campagne ${nom}` }).click();
  await page.getByLabel('Statut').selectOption({ label: 'Candidatures ouvertes' });
  await page.getByRole('button', { name: /^enregistrer$/i }).click();

  await expect(page).toHaveURL(/\/admin\/campaigns$/);
  await expect(page.getByTestId('campagne-active-code')).toBeVisible();
}

test.describe('Administration — consultation des candidatures', () => {
  // La condition dont tout ce fichier depend, etablie explicitement.
  test.beforeAll(async ({ browser }) => {
    const contexte = await browser.newContext({ baseURL: BASE_URL });
    const page = await contexte.newPage();

    await seConnecterAdmin(page);
    await garantirUneEditionOuverte(page);

    await contexte.close();
  });

  test('la barre laterale mene a la liste, qui se filtre, se cherche et s’ouvre', async ({ page, browser }) => {
    const nom = `Hadiza Souley ${jeton()}`;
    const { email } = await candidatQuiDepose(browser, nom);

    await seConnecterAdmin(page);
    await allerAuxCandidatures(page);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Candidatures');

    // — Recherche par nom : le dossier depose plus haut remonte
    await page.getByLabel('Rechercher').fill(nom);
    await page.getByRole('button', { name: /appliquer/i }).click();
    await expect(page.getByTestId('compteur-page')).toContainText('1 sur 1');
    // La liste rend deux fois la meme donnee — cartes sous 1024 px, tableau
    // au-dessus — et une seule des deux est affichee. On vise donc celle qui
    // l'est, quel que soit le profil.
    await expect(page.getByText(email).filter({ visible: true })).toHaveCount(1);

    // — Les filtres vivent dans l'URL : un rechargement ne les perd pas
    await expect(page).toHaveURL(/[?&]q=/);
    await page.reload();
    await expect(page.getByLabel('Rechercher')).toHaveValue(nom);
    await expect(page.getByTestId('compteur-page')).toContainText('1 sur 1');

    // — Filtre par campagne, cumule a la recherche
    await page.getByLabel('Campagne').selectOption({ index: 1 });
    await expect(page.getByTestId('compteur-page')).toContainText('1 sur 1');

    // — Un filtre qui ne correspond a rien : l'ecran le dit sans se casser
    await page.getByLabel('Zone d’intervention').selectOption({ label: 'Zinder' });
    await expect(page.getByTestId('etat-vide')).toContainText(/aucun dossier ne correspond/i);

    // — Reinitialisation
    await page.getByTestId('reinitialiser-filtres').first().click();
    await expect(page).toHaveURL(/\/admin\/applications$/);
    await expect(page.getByLabel('Rechercher')).toHaveValue('');

    // — Ouverture du detail
    await page.getByLabel('Rechercher').fill(nom);
    await page.getByRole('button', { name: /appliquer/i }).click();
    await expect(page.getByTestId('compteur-page')).toContainText('1 sur 1');
    await page.getByRole('link').filter({ hasText: nom }).first().click();
    await expect(page).toHaveURL(/\/admin\/applications\/\d+$/);

    // — Le detail montre les sections, pas du JSON
    await expect(page.getByTestId('section-eligibility')).toHaveAttribute('data-etat', 'complete');
    await expect(page.getByTestId('section-eligibility')).toContainText('Forme de candidature');
    await expect(page.getByTestId('section-eligibility')).toContainText('Équipe');
    await expect(page.getByTestId('section-eligibility')).not.toContainText('candidate_type');

    // Les etapes 2 a 7 sont desormais toutes ouvertes : ce candidat n'a rempli
    // que l'etape 1, elles sont donc simplement non commencees. La premiere
    // encore fermee est « Pieces / declarations », la huitieme.
    await expect(page.getByTestId('section-profile')).toHaveAttribute('data-etat', 'non-commencee');
    await expect(page.getByTestId('section-team')).toHaveAttribute('data-etat', 'non-commencee');
    await expect(page.getByTestId('section-challenge')).toHaveAttribute('data-etat', 'non-commencee');
    await expect(page.getByTestId('section-solution')).toHaveAttribute('data-etat', 'non-commencee');
    await expect(page.getByTestId('section-impact')).toHaveAttribute('data-etat', 'non-commencee');
    await expect(page.getByTestId('section-implementation')).toHaveAttribute('data-etat', 'non-commencee');
    await expect(page.getByTestId('section-attachments')).toHaveAttribute('data-etat', 'non-implementee');

    // — Les cinq regles d'eligibilite viennent du moteur
    await expect(page.getByTestId('regles-eligibilite').getByRole('listitem')).toHaveCount(5);
    await expect(page.getByTestId('regle-ZONE')).toBeVisible();

    // — Et rien ne permet de modifier le dossier
    await expect(page.getByRole('button', { name: /enregistrer/i })).toHaveCount(0);
  });

  test('un candidat ne peut pas ouvrir les candidatures', async ({ page }) => {
    const email = `candidat-admin-${jeton()}@example.test`;

    await page.goto('/register');
    await page.getByLabel('Nom complet').fill('Amina Issa');
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
    await page.getByLabel('Confirmer le mot de passe').fill(MOT_DE_PASSE);
    await page.getByRole('button', { name: /créer mon compte/i }).click();
    await expect(page).toHaveURL(/\/candidate\/dashboard$/);

    const reponse = await page.goto('/admin/applications');
    expect(reponse?.status()).toBe(403);
  });

  test('un visiteur est renvoye vers l’acces interne', async ({ page }) => {
    await page.goto('/admin/applications');
    await expect(page).toHaveURL(/\/admin\/login$/);
  });
});
