import { expect, test, type Page } from '@playwright/test';

/**
 * Etape 8 — Pieces / declarations, de bout en bout.
 *
 * Aucun mock : le fichier traverse reellement Laravel, atterrit sur le disque
 * prive et revient par une route qui verifie la propriete. Le scenario principal
 * remplit les etapes 1 a 7, puis prouve que l'etape 8 mene le dossier a 8/9 et
 * que rien ne se perd au rechargement ni apres une reconnexion.
 *
 * Aucun depot ici : le bouton final et l'ecran de relecture appartiennent a
 * l'etape 9, developpee ailleurs.
 */
const MOT_DE_PASSE = 'MotDePasseSolide!2026';

/** Un PDF minimal, valide pour le controle de type par le contenu. */
const PDF = {
  name: 'presentation-ruwa-link.pdf',
  mimeType: 'application/pdf',
  buffer: Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF'),
};

function compteUnique() {
  const jeton = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
  return { nom: 'Ramatou Garba', email: `pieces-e2e-${jeton}@example.test` };
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

/**
 * Arme le guetteur AVANT l'action, et attend la reponse qui porte le
 * `completed` voulu.
 *
 * La saisie declenche ses propres sauvegardes automatiques : l'une d'elles peut
 * etre encore en vol, forcement incomplete, au moment du clic. Prendre la
 * premiere reponse venue rendrait le scenario aleatoire.
 */
async function attendreSection(page: Page, section: string, complete: boolean, action: () => Promise<void>) {
  const attendue = page.waitForResponse(async (r) => {
    if (!r.url().includes(`/${section}`) || !['PATCH', 'POST', 'DELETE'].includes(r.request().method()) || r.status() !== 200) {
      return false;
    }

    const corps = await r.json().catch(() => null);

    return corps?.completed === complete;
  }, { timeout: 25_000 });

  await action();

  return (await attendue).json();
}

async function enregistrerEtAttendre(page: Page, section: string, complete: boolean) {
  return attendreSection(page, section, complete, async () => {
    await page.getByRole('button', { name: /^enregistrer$/i }).click();
  });
}

/** Ouvre un brouillon et remplit les etapes 1 a 7. */
async function remplirLesSeptPremieresEtapes(page: Page) {
  await page.getByRole('button', { name: /commencer ma candidature/i }).click();
  await expect(page).toHaveURL(/\/candidate\/application\/\d+\/eligibility$/);

  await page.getByLabel(/Quelle est votre date de naissance/).fill('1996-05-20');
  await page.getByRole('group', { name: /nationalité nigérienne/i }).getByRole('radio', { name: 'Oui' }).check();
  await page.getByRole('group', { name: /Résidez-vous/i }).getByRole('radio', { name: 'Oui' }).check();
  await page.getByLabel(/Dans quelle région/).selectOption({ label: 'Niamey' });
  await page.getByLabel(/Sous quelle forme candidatez-vous/).selectOption({ label: 'Candidature individuelle' });
  await page.getByLabel(/Sous quelle forme candidatez-vous/).blur();
  await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });

  // Etape 2
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/profile$/);
  await attendreSection(page, 'profile', true, async () => {
    await page.getByLabel(/Où êtes-vous né/).fill('Niamey');
    await page.getByLabel('Téléphone principal').fill('90 12 34 56');
    await page.getByLabel(/Comment préférez-vous être contacté/).selectOption({ label: 'SMS' });
    await page.getByLabel('Région de résidence').selectOption({ label: 'Niamey' });
    await page.getByLabel('Quartier ou village').fill('Yantala');
    await page.getByLabel(/occupation principale/).fill('Développeuse indépendante');
    await page.getByLabel(/Niveau d’études/).selectOption({ label: 'Licence' });
    await page.getByRole('button', { name: /^enregistrer$/i }).click();
  });

  // Etape 3 : une candidature individuelle n'a rien a renseigner.
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/team$/);
  await enregistrerEtAttendre(page, 'team', true);

  // Etape 4
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/challenge$/);
  // La thematique ouvre l'etape depuis l'integration de la branche « theme » :
  // sans elle, le defi n'est pas acheve quelles que soient les quatre autres
  // reponses, et le dossier n'atteindrait jamais 8/9.
  await page.getByRole('radio', { name: 'Gestion urbaine et services de base' }).check();
  await page.getByLabel(/Quel est le défi principal/).fill('Les bornes-fontaines en panne le restent des semaines.');
  await page.getByLabel(/Qui est le plus affecté/).fill('Les ménages non raccordés des quartiers périphériques.');
  await page.getByLabel(/Où ce défi se pose-t-il/).selectOption({ label: 'Niamey' });
  await page.getByLabel(/causes profondes/).fill('Aucun circuit de signalement, et un service des eaux sans visibilité.');
  await page.getByLabel(/causes profondes/).blur();
  await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });

  // Etape 5
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/solution$/);
  await attendreSection(page, 'solution', true, async () => {
    await page.getByLabel(/Comment s’appelle votre solution/).fill('Ruwa Link');
    await page.getByLabel(/proposition de valeur/).fill('Signaler une borne en panne par SMS et suivre sa remise en service.');
    await page.getByLabel(/fonctionnalités principales/).fill('Signalement SMS, tableau de bord communal, alerte au technicien.');
    await page.getByLabel(/distingue de ce qui existe/).fill('Les signalements se perdent aujourd’hui ; ici tout est tracé.');
    await page.getByLabel(/À quel stade en êtes-vous/).selectOption({ label: 'Prototype — une première version existe' });
    await page.getByLabel(/où en est concrètement votre prototype/i).fill('Une version SMS tourne depuis trois mois sur deux quartiers.');
    await page.getByLabel(/quelles technologies repose/i).fill('Passerelle SMS, PostgreSQL, interface web légère.');
    await page.getByRole('button', { name: /^enregistrer$/i }).click();
  });

  // Etape 6
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/impact$/);
  await attendreSection(page, 'impact', true, async () => {
    await page.getByLabel(/Qui bénéficiera de votre solution/).fill('Environ 4 000 habitants et le service des eaux de la commune.');
    await page.getByLabel(/Quels résultats attendez-vous/).fill('Le délai de réparation passe de trois semaines à quatre jours.');
    await page.getByLabel(/Comment mesurerez-vous/).fill('Signalements traités et délai moyen, relevés chaque mois.');
    await page.getByLabel(/accessible à tous/).fill('SMS simple sans smartphone ; messages en haoussa et en zarma.');
    await page.getByLabel(/résilience du territoire/).fill('Un réseau qui se répare vite encaisse mieux les sécheresses.');
    await page.getByLabel(/modèle économique/).fill('Abonnement annuel de la commune ; 2 M FCFA de fonctionnement par an.');
    await page.getByLabel(/adoptée puis maintenue/).fill('Deux agents formés la première année, puis reprise par le service.');
    await page.getByRole('button', { name: /^enregistrer$/i }).click();
  });

  // Etape 7
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/implementation$/);
  await attendreSection(page, 'implementation', true, async () => {
    await page.getByLabel(/combien de mois/i).fill('9');
    await page.getByLabel(/activités principales/).fill('Cartographier les bornes, brancher la passerelle SMS, former les agents.');
    await page.getByLabel(/Quels sont vos jalons/).fill('Mois 2 : bornes cartographiées. Mois 5 : passerelle en service.');
    await page.getByLabel(/quels moyens avez-vous besoin/i).fill('Un ordinateur portable, un forfait SMS, l’accès au registre communal.');
    await page.getByLabel(/risques et quelles hypothèses/).fill('Coupures réseau prolongées ; nous supposons l’accès au registre.');
    await page.getByLabel(/quel accompagnement/i).fill('Appui juridique pour la convention, et un terrain de test.');
    await page.getByLabel(/Quel budget estimez-vous/).fill('5 000 000');
    await page.getByRole('button', { name: /^enregistrer$/i }).click();
  });
}

/** Coche les declarations exigees, sans le consentement facultatif. */
async function cocherLesDeclarations(page: Page) {
  await page.getByLabel(/certifie l’exactitude/).check();
  await page.getByLabel(/aucun contenu frauduleux/).check();
  await page.getByLabel(/reconnais avoir pris connaissance/).check();
  await page.getByLabel(/consens au traitement/).check();
}

test.describe('Étape 8 — Pièces / déclarations', () => {
  test('depuis un dossier complet à 7/9 : pièces, déclarations, puis 8/9', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await remplirLesSeptPremieresEtapes(page);

    // — L'etape 7 mene desormais a l'etape 8.
    await page.getByTestId('suivant').click();
    await expect(page).toHaveURL(/\/candidate\/application\/\d+\/attachments$/);
    const urlPieces = page.url();

    // Le parcours continue vers la relecture : « Suivant » y mene. Ce qui reste
    // vrai, en revanche, c'est qu'aucun bouton de depot ne vit sur cet ecran —
    // deposer appartient a l'etape 9.
    await expect(page.getByTestId('suivant')).toHaveAttribute('href', /\/review$/);
    await expect(page.getByRole('button', { name: /soumettre|déposer/i })).toHaveCount(0);

    // Six pieces proposees ; une seule exigee d'un porteur individuel.
    await expect(page.getByTestId('piece-PROJECT_PRESENTATION')).toHaveAttribute('data-etat', 'absente');
    await expect(page.getByTestId('etat-section')).toContainText('Il reste');

    // — Les declarations seules n'achevent pas l'etape : la piece manque.
    await attendreSection(page, 'attachments', false, async () => {
      await cocherLesDeclarations(page);
      await page.getByRole('button', { name: /^enregistrer$/i }).click();
    });
    await expect(page.getByTestId('etat-section')).toContainText('Présentation du projet');

    // — La piece arrive : l'etape s'acheve sans qu'on recoche quoi que ce soit.
    await attendreSection(page, 'attachments', true, async () => {
      await page.getByTestId('piece-PROJECT_PRESENTATION').getByLabel(/téléverser/i).setInputFiles(PDF);
    });

    await expect(page.getByTestId('piece-PROJECT_PRESENTATION')).toHaveAttribute('data-etat', 'deposee');
    await expect(page.getByTestId('piece-PROJECT_PRESENTATION-nom')).toHaveText(PDF.name);
    await expect(page.getByTestId('piece-PROJECT_PRESENTATION-poids')).toContainText(/o$|Ko$|Mo$/);
    await expect(page.getByTestId('etat-section')).toContainText('Étape complète');

    // — Rechargement : l'etat revient de PostgreSQL, pas d'un `<input file>`.
    await page.reload();
    await expect(page.getByLabel(/certifie l’exactitude/)).toBeChecked();
    await expect(page.getByLabel(/citation dans la communication|communication publique/i).first()).not.toBeChecked();
    await expect(page.getByTestId('piece-PROJECT_PRESENTATION-nom')).toHaveText(PDF.name);
    await expect(page.getByTestId('etat-section')).toContainText('Étape complète');

    // — Deconnexion puis reconnexion : le dossier reprend ou il en etait
    await seDeconnecter(page);
    await seConnecter(page, email);

    const attendu = `${Math.round((8 / 9) * 100)}%`;
    await expect(page.getByTestId('progression')).toHaveText(attendu, { timeout: 15_000 });

    await page.getByRole('link', { name: /continuer ma candidature/i }).click();
    await expect(page).toHaveURL(urlPieces);
    await expect(page.getByTestId('piece-PROJECT_PRESENTATION-nom')).toHaveText(PDF.name);
    await expect(page.getByLabel(/consens au traitement/)).toBeChecked();
  });

  test('remplacer puis retirer une pièce, sans rien perdre', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await remplirLesSeptPremieresEtapes(page);
    await page.getByTestId('suivant').click();
    await expect(page).toHaveURL(/\/attachments$/);

    await attendreSection(page, 'attachments', false, async () => {
      await page.getByTestId('piece-PROJECT_PRESENTATION').getByLabel(/téléverser/i).setInputFiles(PDF);
    });
    await expect(page.getByTestId('piece-PROJECT_PRESENTATION-nom')).toHaveText(PDF.name);

    // — Remplacement : un seul fichier par piece, le nom suit.
    await attendreSection(page, 'attachments', false, async () => {
      await page.getByTestId('piece-PROJECT_PRESENTATION').getByLabel(/remplacer/i)
        .setInputFiles({ ...PDF, name: 'version-corrigee.pdf' });
    });
    await expect(page.getByTestId('piece-PROJECT_PRESENTATION-nom')).toHaveText('version-corrigee.pdf');

    await page.reload();
    await expect(page.getByTestId('piece-PROJECT_PRESENTATION-nom')).toHaveText('version-corrigee.pdf');

    // — Retrait : l'ecran revient a l'etat « absente ».
    await attendreSection(page, 'attachments', false, async () => {
      await page.getByTestId('piece-PROJECT_PRESENTATION-supprimer').click();
    });
    await expect(page.getByTestId('piece-PROJECT_PRESENTATION')).toHaveAttribute('data-etat', 'absente');

    await page.reload();
    await expect(page.getByTestId('piece-PROJECT_PRESENTATION')).toHaveAttribute('data-etat', 'absente');
  });

  test('le serveur refuse un fichier au mauvais format', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await remplirLesSeptPremieresEtapes(page);
    await page.getByTestId('suivant').click();
    await expect(page).toHaveURL(/\/attachments$/);

    // Le §7.2 n'admet que le PDF pour la presentation du projet. Le nom du
    // fichier ne decide de rien : c'est le contenu que Laravel inspecte.
    await page.getByTestId('piece-PROJECT_PRESENTATION').getByLabel(/téléverser/i).setInputFiles({
      name: 'faux.pdf',
      mimeType: 'application/pdf',
      buffer: Buffer.from('MZ ceci est un executable, pas un PDF'),
    });

    await expect(page.getByTestId('piece-PROJECT_PRESENTATION').getByRole('alert')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByTestId('piece-PROJECT_PRESENTATION')).toHaveAttribute('data-etat', 'absente');

    // Rien n'est entre en base.
    await page.reload();
    await expect(page.getByTestId('piece-PROJECT_PRESENTATION')).toHaveAttribute('data-etat', 'absente');
  });

  test('un candidat ne peut pas ouvrir les pièces d’un autre', async ({ page }) => {
    const proprietaire = compteUnique();
    await sInscrire(page, proprietaire.nom, proprietaire.email);
    await remplirLesSeptPremieresEtapes(page);
    await page.getByTestId('suivant').click();
    await expect(page).toHaveURL(/\/attachments$/);
    const urlDuProprietaire = page.url();

    await attendreSection(page, 'attachments', false, async () => {
      await page.getByTestId('piece-PROJECT_PRESENTATION').getByLabel(/téléverser/i).setInputFiles(PDF);
    });

    await seDeconnecter(page);

    const intrus = compteUnique();
    await sInscrire(page, intrus.nom, intrus.email);

    expect((await page.goto(urlDuProprietaire))?.status()).toBe(403);
    // Le telechargement direct est ferme lui aussi, et ne dit rien du fichier.
    const telechargement = await page.goto(`${urlDuProprietaire}/documents/PROJECT_PRESENTATION`);
    expect(telechargement?.status()).toBe(403);
    expect(await page.content()).not.toContain(PDF.name);
  });
});
