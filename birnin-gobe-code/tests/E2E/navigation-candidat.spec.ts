import { expect, test, type Page } from '@playwright/test';

/**
 * Navigation de l'espace candidat, vue du navigateur.
 *
 * Le menu est rendu par React et l'application n'a pas de rendu serveur : le
 * HTML que Laravel renvoie ne contient aucune etiquette de menu. C'est donc
 * **ici**, et nulle part ailleurs, qu'on peut verifier ce que le candidat voit
 * reellement — un `assertDontSee` cote PHP passerait meme si l'entree etait
 * toujours la.
 *
 * Ce que ces scenarios tiennent : trois entrees, toutes vivantes, aucune
 * promesse morte, et le bon element surligne sur chaque ecran.
 */
const MOT_DE_PASSE = 'MotDePasseSolide!2026';

/** Les quatre modules non developpes, retires du menu. */
const MODULES_RETIRES = ['Mes messages', 'Mes documents', 'Assistance', 'Paramètres'];

function compteUnique() {
  const jeton = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
  return { nom: 'Ramatou Garba', email: `nav-e2e-${jeton}@example.test` };
}

/**
 * Cree un compte : la condition prealable de chaque scenario, jamais son sujet.
 *
 * L'attente de navigation est explicite (15 s) et non laissee au defaut de 5 s.
 * L'inscription hache un mot de passe puis ouvre une session : c'est le seul
 * aller-retour reellement couteux du parcours, et sur une machine de
 * developpement qui fait tourner plusieurs piles Docker, il depasse
 * regulierement 5 s. Le meme delai est deja utilise ailleurs dans cette suite
 * pour les allers-retours serveur.
 *
 * Ce delai ne masque rien : aucune assertion de navigation **du sujet teste**
 * n'est allongee — le menu, l'etat actif et la reprise gardent le defaut, et
 * echoueront si le produit met trop de temps a repondre.
 */
async function sInscrire(page: Page, nom: string, email: string) {
  await page.goto('/register');
  await page.getByLabel('Nom complet').fill(nom);
  await page.getByLabel('Adresse e-mail').fill(email);
  await page.getByLabel('Mot de passe', { exact: true }).fill(MOT_DE_PASSE);
  await page.getByLabel('Confirmer le mot de passe').fill(MOT_DE_PASSE);
  await page.getByRole('button', { name: /créer mon compte/i }).click();
  await page.waitForURL(/\/candidate\/dashboard$/, { timeout: 15_000 });
}

/**
 * Une entree du menu, celle que le candidat voit reellement.
 *
 * Le layout rend deux exemplaires du menu : la colonne laterale, masquee sous le
 * point de rupture, et le tiroir mobile, masque au-dessus. `.first()` prendrait
 * toujours la colonne — invisible sur telephone, donc jamais cliquable. On cible
 * l'exemplaire visible, et le scenario marche des deux cotes.
 */
function lienDuMenu(page: Page, cle: 'dashboard' | 'profile' | 'application') {
  return page.locator(`[data-testid="nav-${cle}"]:visible`).first();
}

/**
 * Ouvre un dossier : l'autre condition prealable, elle aussi hors sujet.
 *
 * Comme l'inscription, c'est une ecriture serveur — `StartApplication` cree le
 * brouillon — et elle depasse regulierement 5 s sur une machine qui fait tourner
 * plusieurs piles Docker. L'attente est donc explicite ici, et seulement ici :
 * les assertions qui portent sur le sujet de ces scenarios — le menu, l'entree
 * active, la reprise — gardent le defaut.
 */
async function ouvrirUnDossier(page: Page) {
  await page.getByRole('button', { name: /commencer ma candidature/i }).click();
  await page.waitForURL(/\/candidate\/application\/\d+\/eligibility$/, { timeout: 15_000 });
}

/** Ouvre le menu sur mobile ; sans effet sur un ecran large, ou il est deja la. */
async function ouvrirLeMenuSiMobile(page: Page) {
  const bouton = page.getByRole('button', { name: /ouvrir le menu/i });

  if (await bouton.isVisible().catch(() => false)) {
    await bouton.click();
  }
}

test.describe('Navigation candidate', () => {
  test('le menu ne propose que des entrées qui mènent quelque part', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await ouvrirLeMenuSiMobile(page);

    // — Les trois entrees vivantes, et leur destination
    await expect(lienDuMenu(page, 'dashboard')).toHaveAttribute('href', /\/candidate\/dashboard$/);
    await expect(lienDuMenu(page, 'profile')).toHaveAttribute('href', /\/candidate\/profile$/);
    await expect(lienDuMenu(page, 'application')).toHaveAttribute('href', /\/candidate\/application$/);

    // — Les quatre modules non developpes ne sont plus annonces
    for (const module of MODULES_RETIRES) {
      await expect(page.getByRole('link', { name: module })).toHaveCount(0);
    }

    // — Et plus aucun lien mort nulle part sur l'ecran
    await expect(page.locator('a[href="#"]')).toHaveCount(0);

    // — Sur le tableau de bord, c'est bien son entree qui est active
    await expect(lienDuMenu(page, 'dashboard')).toHaveAttribute('aria-current', 'page');
    await expect(lienDuMenu(page, 'profile')).not.toHaveAttribute('aria-current', 'page');

    // — La deconnexion, elle, reste
    await expect(page.getByRole('button', { name: /se déconnecter/i }).first()).toBeVisible();
  });

  test('le tableau de bord ne montre plus de messages ni de documents fictifs', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);

    // Ces cartes affichaient trois messages et quatre fichiers qui n'ont jamais
    // existe, sous un « Voir tout » qui ne menait nulle part.
    await expect(page.getByText('Messages récents')).toHaveCount(0);
    await expect(page.getByText('Lettre de motivation')).toHaveCount(0);
    await expect(page.getByRole('button', { name: /ajouter un document/i })).toHaveCount(0);
  });

  test('« Mon profil » ouvre la vraie page Profil et devient l’entrée active', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);

    // Un dossier doit exister : sans lui, il n'y a pas encore de profil a remplir.
    await ouvrirUnDossier(page);

    await ouvrirLeMenuSiMobile(page);
    await lienDuMenu(page, 'profile').click();

    await expect(page).toHaveURL(/\/candidate\/application\/\d+\/profile$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Profil');

    // L'entree cliquee est celle qui apparait active.
    await ouvrirLeMenuSiMobile(page);
    await expect(lienDuMenu(page, 'profile')).toHaveAttribute('aria-current', 'page');
    await expect(lienDuMenu(page, 'dashboard')).not.toHaveAttribute('aria-current', 'page');

    // — Et cela survit a un rechargement : l'etat vient du serveur.
    await page.reload();
    await ouvrirLeMenuSiMobile(page);
    await expect(lienDuMenu(page, 'profile')).toHaveAttribute('aria-current', 'page');
  });

  test('« Ma candidature » reprend le dossier à l’étape en cours', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);

    await ouvrirUnDossier(page);

    // On avance d'une etape, pour que la reprise ait quelque chose a retrouver.
    await page.getByLabel(/Quelle est votre date de naissance/).fill('1996-05-20');
    await page.getByRole('group', { name: /nationalité nigérienne/i }).getByRole('radio', { name: 'Oui' }).check();
    await page.getByRole('group', { name: /Résidez-vous/i }).getByRole('radio', { name: 'Oui' }).check();
    await page.getByLabel(/Dans quelle région/).selectOption({ label: 'Niamey' });
    await page.getByLabel(/Sous quelle forme candidatez-vous/).selectOption({ label: 'Candidature individuelle' });
    await page.getByLabel(/Sous quelle forme candidatez-vous/).blur();
    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });

    await page.getByTestId('suivant').click();
    await expect(page).toHaveURL(/\/profile$/);
    const urlDeReprise = page.url();

    // Une reponse suffit a faire avancer la reprise. `current_step` est ecrit
    // par la sauvegarde, jamais par la simple visite d'un ecran : une navigation
    // n'ecrit rien en base, et la reprise suit donc le travail reel du candidat
    // plutot que sa derniere page ouverte.
    await page.getByLabel(/Où êtes-vous né/).fill('Niamey');
    await page.getByLabel(/Où êtes-vous né/).blur();
    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });

    // Le point d'entree, appele depuis ailleurs : il ramene a l'etape ou le
    // candidat travaillait, sans que le menu ait a connaitre ni l'identifiant du
    // dossier ni l'etape courante.
    await page.goto('/candidate/application');
    await expect(page).toHaveURL(urlDeReprise);

    // Et il reste stable : un second passage mene au meme endroit.
    await page.goto('/candidate/application');
    await expect(page).toHaveURL(urlDeReprise);
  });

  test('sur mobile : le tiroir s’ouvre, navigue et se referme', async ({ page, isMobile }) => {
    test.skip(!isMobile, 'Le tiroir n’existe que sous le point de rupture mobile.');

    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);

    // Le tiroir reste monte, ouvert ou ferme : c'est son etat qu'on lit, pas sa
    // presence dans le DOM.
    const tiroir = page.getByTestId('menu-mobile');
    await expect(tiroir).toHaveAttribute('data-ouvert', 'non');
    await expect(tiroir).toHaveAttribute('aria-hidden', 'true');

    await page.getByRole('button', { name: /ouvrir le menu/i }).click();
    await expect(tiroir).toHaveAttribute('data-ouvert', 'oui');

    const profil = tiroir.getByTestId('nav-profile');
    await expect(profil).toBeVisible();

    // Aucun debordement horizontal : le tiroir ne pousse pas la page de cote.
    const debordement = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(debordement).toBeLessThanOrEqual(1);

    // Libelle entier, et cible tactile au-dessus des 44 px exiges par
    // docs/architecture/BLUEPRINT-UI-FOUNDATION.md.
    await expect(profil).toContainText('Mon profil');
    const boite = await profil.boundingBox();
    expect(boite?.height ?? 0).toBeGreaterThanOrEqual(44);
    expect(boite?.width ?? 0).toBeGreaterThan(0);

    // Le tiroir ne propose aucun module non developpe non plus.
    for (const module of MODULES_RETIRES) {
      await expect(tiroir.getByRole('link', { name: module })).toHaveCount(0);
    }

    // Navigation depuis le tiroir : il se referme derriere nous.
    await tiroir.getByTestId('nav-application').click();
    await expect(page).toHaveURL(/\/candidate\/dashboard$/);
    await expect(tiroir).toHaveAttribute('data-ouvert', 'non');
  });
});
