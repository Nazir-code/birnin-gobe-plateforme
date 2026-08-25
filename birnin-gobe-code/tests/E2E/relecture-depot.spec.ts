import { expect, test, type Page } from '@playwright/test';

/**
 * Etape 9 — relire son dossier, le deposer, en garder la preuve.
 *
 * Le scenario va au bout du parcours reel : inscription, etapes 1 a 7 remplies
 * par les vrais ecrans, relecture, confirmation, depot, accuse. Puis
 * deconnexion et retour, parce que la seule preuve qu'un candidat possede est
 * son numero — aucun courriel n'etant envoye — et qu'il doit pouvoir le
 * retrouver.
 *
 * **Ce spec ne fabrique plus rien.** Il achevait l'etape 8 par une commande de
 * fixture, parce que son formulaire vivait sur une autre branche. Cette branche
 * est integree : le scenario televerse la piece exigee et coche les
 * declarations par le vrai ecran, comme un candidat. La commande, devenue sans
 * appelant, a ete retiree — c'est ce que son propre commentaire annoncait.
 *
 * Aucune assertion de depot n'a eu a bouger pour cela : `SubmissionReadiness`
 * n'a jamais regarde que les dates d'achevement, ce qui etait exactement la
 * promesse tenue par la fixture.
 */
const MOT_DE_PASSE = 'MotDePasseSolide!2026';

/** La piece exigee d'un porteur individuel (§7.2, « Presentation du projet »). */
const PDF = {
  name: 'presentation-ruwa-link.pdf',
  mimeType: 'application/pdf',
  buffer: Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF'),
};

function compteUnique() {
  const jeton = `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;

  return { nom: `Zeinabou Ali ${jeton}`, email: `relecture-${jeton}@example.test` };
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
 * Attend que le serveur ait declare la section achevee.
 *
 * L'indicateur d'ecran ne suffit pas : il peut deja porter le resultat d'une
 * sauvegarde automatique partielle. La reponse du PATCH, elle, dit `completed`.
 */
function attendreAchevement(page: Page, motif: string) {
  return page.waitForResponse(
    async (r) => {
      if (r.request().method() !== 'PATCH' || !r.url().includes(motif) || r.status() !== 200) return false;
      const corps = await r.json().catch(() => ({}));

      return corps.completed === true;
    },
    { timeout: 30_000 },
  );
}

/** Les etapes 1 a 7, par les vrais ecrans. */
async function remplirLeDossier(page: Page) {
  const eligibilite = attendreAchevement(page, '/eligibility');
  await page.getByRole('button', { name: /commencer ma candidature/i }).click();
  await expect(page).toHaveURL(/\/candidate\/application\/\d+\/eligibility$/);
  await page.getByLabel(/Quelle est votre date de naissance/).fill('1996-05-20');
  await page.getByRole('group', { name: /nationalité nigérienne/i }).getByRole('radio', { name: 'Oui' }).check();
  await page.getByRole('group', { name: /Résidez-vous/i }).getByRole('radio', { name: 'Oui' }).check();
  await page.getByLabel(/Dans quelle région/).selectOption({ label: 'Niamey' });
  await page.getByLabel(/Sous quelle forme candidatez-vous/).selectOption({ label: 'Candidature individuelle' });
  await page.getByLabel(/Sous quelle forme candidatez-vous/).blur();
  await eligibilite;

  const profil = attendreAchevement(page, '/profile');
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/profile$/);
  await page.getByLabel(/Où êtes-vous né/).fill('Niamey');
  await page.getByLabel('Téléphone principal').fill('90 12 34 56');
  await page.getByLabel(/Comment préférez-vous être contacté/).selectOption({ label: 'SMS' });
  await page.getByLabel('Région de résidence').selectOption({ label: 'Niamey' });
  await page.getByLabel('Quartier ou village').fill('Yantala');
  await page.getByLabel(/occupation principale/).fill('Développeuse indépendante');
  await page.getByLabel(/Niveau d’études/).selectOption({ label: 'Licence' });
  await page.getByRole('button', { name: /^enregistrer$/i }).click();
  await profil;

  // Etape 3 — candidature individuelle : aucun champ, l'enregistrement explicite
  // est le seul moyen d'achever la section.
  const equipe = attendreAchevement(page, '/team');
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/team$/);
  await page.getByRole('button', { name: /^enregistrer$/i }).click();
  await equipe;

  const defi = attendreAchevement(page, '/challenge');
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/challenge$/);
  // La thematique officielle acheve l'etape depuis l'integration de la branche
  // « theme » : sans elle, le defi reste inacheve et le dossier n'atteint
  // jamais la recevabilite.
  await page.getByRole('radio', { name: 'Gestion urbaine et services de base' }).check();
  await page.getByLabel(/Quel est le défi principal/).fill('Les bornes-fontaines en panne le restent des semaines.');
  await page.getByLabel(/Qui est le plus affecté/).fill('Les ménages non raccordés des quartiers périphériques.');
  await page.getByLabel(/Où ce défi se pose-t-il/).selectOption({ label: 'Niamey' });
  await page.getByLabel(/causes profondes/).fill('Aucun circuit de signalement, et un service des eaux sans visibilité.');
  await page.getByLabel(/causes profondes/).blur();
  await defi;

  const solution = attendreAchevement(page, '/solution');
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/solution$/);
  await page.getByLabel(/Comment s’appelle votre solution/).fill('Ruwa Link');
  await page.getByLabel(/proposition de valeur/).fill('Signaler une borne en panne par SMS et suivre sa remise en service.');
  await page.getByLabel(/fonctionnalités principales/).fill('Signalement SMS, tableau de bord communal, alerte au technicien.');
  await page.getByLabel(/distingue de ce qui existe/).fill('Les signalements se perdent aujourd’hui ; ici tout est tracé.');
  await page.getByLabel(/À quel stade en êtes-vous/).selectOption({ label: 'Prototype — une première version existe' });
  await page.getByLabel(/où en est concrètement votre prototype/i).fill('Une version SMS tourne depuis trois mois sur deux quartiers.');
  await page.getByLabel(/quelles technologies repose/i).fill('Passerelle SMS, PostgreSQL, interface web légère.');
  await page.getByLabel(/quelles technologies repose/i).blur();
  await solution;

  const impact = attendreAchevement(page, '/impact');
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/impact$/);
  await page.getByLabel(/Qui bénéficiera de votre solution/).fill('Environ 4 000 habitants et le service des eaux de la commune.');
  await page.getByLabel(/Quels résultats attendez-vous/).fill('Le délai de réparation passe de trois semaines à quatre jours.');
  await page.getByLabel(/Comment mesurerez-vous/).fill('Signalements traités et délai moyen, relevés chaque mois.');
  await page.getByLabel(/accessible à tous/).fill('SMS simple sans smartphone ; messages en haoussa et en zarma.');
  await page.getByLabel(/résilience du territoire/).fill('Un réseau qui se répare vite encaisse mieux les sécheresses.');
  await page.getByLabel(/modèle économique/).fill('Abonnement annuel de la commune ; 2 M FCFA de fonctionnement par an.');
  await page.getByLabel(/adoptée puis maintenue/).fill('Deux agents formés la première année, puis reprise par le service.');
  await page.getByLabel(/adoptée puis maintenue/).blur();
  await impact;

  const plan = attendreAchevement(page, '/implementation');
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/implementation$/);
  await page.getByLabel(/combien de mois/i).fill('9');
  await page.getByLabel(/activités principales/).fill('Cartographier les bornes, brancher la passerelle SMS, former les agents.');
  await page.getByLabel(/Quels sont vos jalons/).fill('Mois 2 : bornes cartographiées. Mois 5 : passerelle en service.');
  await page.getByLabel(/quels moyens avez-vous besoin/i).fill('Un ordinateur portable, un forfait SMS, l’accès au registre communal.');
  await page.getByLabel(/risques et quelles hypothèses/).fill('Coupures réseau prolongées ; nous supposons l’accès au registre.');
  await page.getByLabel(/quel accompagnement/i).fill('Appui juridique pour la convention, et un terrain de test.');
  await page.getByLabel(/Quel budget estimez-vous/).fill('5 000 000');
  await page.getByLabel(/Quel budget estimez-vous/).blur();
  await plan;
}

/**
 * Etape 8, par son vrai ecran : la piece exigee, puis les declarations.
 *
 * Deux attentes distinctes, et dans cet ordre, parce que la section ne s'acheve
 * qu'avec les deux : le televersement seul laisse les declarations en attente,
 * et les declarations seules laissent la piece manquante.
 */
async function deposerLesPiecesEtDeclarations(page: Page) {
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/attachments$/);

  const pieceDeposee = page.waitForResponse(
    (r) => r.url().includes('/attachments/documents') && r.request().method() === 'POST' && r.status() === 200,
    { timeout: 30_000 },
  );
  await page.getByTestId('piece-PROJECT_PRESENTATION').getByLabel(/téléverser/i).setInputFiles(PDF);
  await pieceDeposee;

  const sectionAchevee = attendreAchevement(page, '/attachments');
  await page.getByLabel(/certifie l’exactitude/).check();
  await page.getByLabel(/aucun contenu frauduleux/).check();
  await page.getByLabel(/reconnais avoir pris connaissance/).check();
  await page.getByLabel(/consens au traitement/).check();
  await page.getByRole('button', { name: /^enregistrer$/i }).click();
  await sectionAchevee;
}

async function allerALaRelecture(page: Page) {
  await page.goto('/candidate/dashboard');
  await page.getByTestId('aller-relecture').click();
  await expect(page).toHaveURL(/\/candidate\/application\/\d+\/review$/);
}

test.describe('Étape 9 — relecture et dépôt', () => {
  test('dossier complet : relire, confirmer, déposer, retrouver son numéro', async ({ page }) => {
    const { nom, email } = compteUnique();

    await sInscrire(page, nom, email);
    await remplirLeDossier(page);
    await deposerLesPiecesEtDeclarations(page);

    await allerALaRelecture(page);

    // — Les neuf etapes sont la, lisibles
    await expect(page.getByTestId('relecture-eligibility')).toHaveAttribute('data-etat', 'complete');
    await expect(page.getByTestId('relecture-implementation')).toHaveAttribute('data-etat', 'complete');
    // L'etape 8 a desormais son ecran : elle est achevee comme les autres, et
    // ce n'est plus une fixture qui l'affirme.
    await expect(page.getByTestId('relecture-attachments')).toHaveAttribute('data-etat', 'complete');
    await expect(page.getByTestId('recevabilite')).toContainText('Votre dossier est complet');
    // Des libelles, jamais des cles techniques.
    await expect(page.getByTestId('relecture-solution')).toContainText('Ruwa Link');
    await expect(page.getByTestId('relecture-solution')).not.toContainText('solution_name');

    // — Ce que les deux vagues integrees ajoutent a la relecture : la
    //   thematique officielle, la piece deposee, les declarations acceptees.
    await expect(page.getByTestId('relecture-challenge')).toContainText('Gestion urbaine et services de base');
    await expect(page.getByTestId('relecture-challenge')).not.toContainText('project_theme');
    await expect(page.getByTestId('pieces-attachments')).toContainText('Présentation du projet');
    await expect(page.getByTestId('pieces-attachments')).toContainText(PDF.name);
    await expect(page.getByTestId('relecture-attachments')).toContainText(/exactitude/i);

    // — Chaque etape developpee se corrige
    await expect(page.getByTestId('modifier-implementation')).toBeVisible();

    // — Le depot ne part pas sur un simple clic
    await expect(page.getByTestId('confirmation-depot')).toHaveCount(0);
    await page.getByTestId('soumettre').click();
    await expect(page.getByTestId('confirmation-depot')).toBeVisible();
    await expect(page.getByTestId('confirmation-depot')).toContainText('ne pourra plus être modifié');

    // — On peut renoncer
    await page.getByTestId('annuler-depot').click();
    await expect(page.getByTestId('confirmation-depot')).toHaveCount(0);
    await expect(page).toHaveURL(/\/review$/);

    // — Puis confirmer
    await page.getByTestId('soumettre').click();
    await page.getByTestId('confirmer-depot').click();

    await expect(page).toHaveURL(/\/candidate\/application\/\d+\/submitted$/);
    await expect(page.getByTestId('accuse-depot')).toContainText('Candidature soumise avec succès');
    await expect(page.getByTestId('numero-depot')).toHaveText(/^BG-\d{4}-\d{6}$/);
    await expect(page.getByTestId('statut-depot')).toContainText('Soumise');

    const numero = (await page.getByTestId('numero-depot').innerText()).trim();

    // — L'ecran ne promet rien qui n'existe pas
    const accuse = await page.locator('body').innerText();
    expect(accuse).not.toMatch(/e-mail de confirmation|SMS|évaluation a commencé/i);

    // — Deconnexion, retour : le numero est toujours la, le dossier verrouille
    await seDeconnecter(page);
    await seConnecter(page, email);

    await expect(page.getByTestId('statut-candidature')).toContainText('Soumise');
    await expect(page.getByTestId('depot-resume')).toContainText(numero);
    await expect(page.getByTestId('aller-relecture')).toHaveCount(0);

    await page.getByTestId('voir-accuse').click();
    await expect(page).toHaveURL(/\/submitted$/);
    await expect(page.getByTestId('numero-depot')).toHaveText(numero);

    // — Et la relecture ne propose plus rien a modifier
    const id = page.url().match(/application\/(\d+)\//)?.[1];
    await page.goto(`/candidate/application/${id}/review`);
    await expect(page).toHaveURL(/\/submitted$/);
  });

  test('sans les pièces : la relecture refuse le dépôt et dit pourquoi', async ({ page }) => {
    const { nom, email } = compteUnique();

    await sInscrire(page, nom, email);
    await remplirLeDossier(page);
    // Volontairement : l'etape 8 n'est pas achevee.

    await allerALaRelecture(page);

    await expect(page.getByTestId('recevabilite')).toContainText('ne peut pas encore être déposé');
    await expect(page.getByTestId('motifs-blocage')).toContainText('Toutes les étapes du dossier ne sont pas terminées');
    await expect(page.getByTestId('etapes-manquantes')).toContainText('Pièces / déclarations');

    // Le bouton existe mais n'agit pas : desactive, et aucune confirmation.
    await expect(page.getByTestId('soumettre-desactive')).toBeDisabled();
    await expect(page.getByTestId('soumettre')).toHaveCount(0);
    await expect(page.getByTestId('confirmation-depot')).toHaveCount(0);
  });
});
