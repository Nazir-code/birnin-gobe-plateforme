import { expect, test, type Page } from '@playwright/test';

/**
 * Etapes 5, 6 et 7 — Solution, Impact / viabilite, Plan de mise en oeuvre.
 *
 * Aucun mock : chaque etape passe par Laravel et PostgreSQL. Le scenario
 * principal part d'un dossier arrete a « Defi » — l'etat des brouillons
 * anterieurs a cette phase — et verifie que le parcours se prolonge jusqu'a 7/9
 * sans qu'aucune reponse ne se perde, ni au rechargement, ni apres une
 * reconnexion.
 */
const MOT_DE_PASSE = 'MotDePasseSolide!2026';

function compteUnique() {
  const jeton = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
  return { nom: 'Ramatou Garba', email: `sip-e2e-${jeton}@example.test` };
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
 * Clique « Enregistrer » et attend la reponse du serveur qui porte le
 * `completed` attendu.
 *
 * Attendre que l'indicateur affiche « Enregistre » ne prouverait rien : il peut
 * deja porter le resultat d'une sauvegarde automatique partielle. C'est le corps
 * du PATCH — `ApplicationPresenter::savedPayload()` — qui dit la verite.
 *
 * Le predicat lit ce corps plutot que de prendre la premiere reponse venue : la
 * saisie declenche ses propres sauvegardes automatiques, et l'une d'elles peut
 * encore etre en vol, avec une section forcement incomplete, au moment du clic.
 * Attendre la bonne reponse plutot que la premiere, c'est la difference entre un
 * scenario qui verifie quelque chose et un scenario qui echoue une fois sur
 * trois.
 */
async function attendreSection(page: Page, section: string, complete: boolean, action: () => Promise<void>) {
  // Le guetteur est arme AVANT la saisie : selon le rythme de frappe, la
  // sauvegarde automatique peut avoir devance le clic, et la bonne reponse etre
  // deja passee au moment ou l'on clique.
  const attendue = page.waitForResponse(async (r) => {
    if (!r.url().includes(`/${section}`) || r.request().method() !== 'PATCH' || r.status() !== 200) {
      return false;
    }

    const corps = await r.json().catch(() => null);

    return corps?.completed === complete;
  }, { timeout: 25_000 });

  await action();

  return (await attendue).json();
}

/** Clique « Enregistrer » et attend la reponse qui porte le `completed` attendu. */
async function enregistrerEtAttendre(page: Page, section: string, complete: boolean) {
  return attendreSection(page, section, complete, async () => {
    await page.getByRole('button', { name: /^enregistrer$/i }).click();
  });
}

/** Ouvre un brouillon et traverse les etapes 1 a 4, en remplissant « Defi ». */
async function allerJusquAuDefi(page: Page) {
  await page.getByRole('button', { name: /commencer ma candidature/i }).click();
  await expect(page).toHaveURL(/\/candidate\/application\/\d+\/eligibility$/);

  await page.getByLabel(/Quelle est votre date de naissance/).fill('1996-05-20');
  await page.getByRole('group', { name: /nationalité nigérienne/i }).getByRole('radio', { name: 'Oui' }).check();
  await page.getByRole('group', { name: /Résidez-vous/i }).getByRole('radio', { name: 'Oui' }).check();
  await page.getByLabel(/Dans quelle région/).selectOption({ label: 'Niamey' });
  await page.getByLabel(/Sous quelle forme candidatez-vous/).selectOption({ label: 'Candidature individuelle' });
  await page.getByLabel(/Sous quelle forme candidatez-vous/).blur();
  await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });

  // Etapes 2 et 3. Elles ne sont pas le sujet, mais elles doivent etre faites :
  // sans elles la progression ne pourrait pas atteindre 7/9, et le scenario ne
  // prouverait rien du parcours complet.
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/profile$/);
  await remplirProfil(page);
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/team$/);
  // Candidature individuelle : le §6.2 ne prevoit ni structure ni membres.
  // « Enregistrer » est le seul moyen d'achever une section sans champ.
  await enregistrerEtAttendre(page, 'team', true);
  await page.getByTestId('suivant').click();
  await expect(page).toHaveURL(/\/challenge$/);

  await page.getByRole('radio', { name: 'Gestion urbaine et services de base' }).check();
  await page.getByLabel(/Quel est le défi principal/).fill('Les bornes-fontaines en panne le restent des semaines.');
  await page.getByLabel(/Qui est le plus affecté/).fill('Les ménages non raccordés des quartiers périphériques.');
  await page.getByLabel(/Où ce défi se pose-t-il/).selectOption({ label: 'Niamey' });
  await page.getByLabel(/causes profondes/).fill('Aucun circuit de signalement, et un service des eaux sans visibilité.');
  await page.getByLabel(/causes profondes/).blur();
  await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Enregistré', { timeout: 15_000 });
}

/**
 * Etape 2, remplie puis achevee.
 *
 * Le bouton « Enregistrer » de cet ecran appelle `flush()`, qui ne renvoie rien
 * si la sauvegarde automatique a deja tout envoye : c'est le guetteur arme avant
 * la saisie qui rend le scenario fiable, pas le clic.
 */
async function remplirProfil(page: Page) {
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
}

async function remplirSolution(page: Page) {
  await page.getByLabel(/Comment s’appelle votre solution/).fill('Ruwa Link');
  await page.getByLabel(/proposition de valeur/).fill('Signaler une borne en panne par SMS et suivre sa remise en service.');
  await page.getByLabel(/fonctionnalités principales/).fill('Signalement SMS, tableau de bord communal, alerte au technicien.');
  await page.getByLabel(/distingue de ce qui existe/).fill('Les signalements se perdent aujourd’hui ; ici tout est tracé.');
  await page.getByLabel(/À quel stade en êtes-vous/).selectOption({ label: 'Prototype — une première version existe' });
  await page.getByLabel(/où en est concrètement votre prototype/i).fill('Une version SMS tourne depuis trois mois sur deux quartiers.');
  await page.getByLabel(/quelles technologies repose/i).fill('Passerelle SMS, PostgreSQL, interface web légère.');
}

async function remplirImpact(page: Page) {
  await page.getByLabel(/Qui bénéficiera de votre solution/).fill('Environ 4 000 habitants et le service des eaux de la commune.');
  await page.getByLabel(/Quels résultats attendez-vous/).fill('Le délai de réparation passe de trois semaines à quatre jours.');
  await page.getByLabel(/Comment mesurerez-vous/).fill('Signalements traités et délai moyen, relevés chaque mois.');
  await page.getByLabel(/accessible à tous/).fill('SMS simple sans smartphone ; messages en haoussa et en zarma.');
  await page.getByLabel(/résilience du territoire/).fill('Un réseau qui se répare vite encaisse mieux les sécheresses.');
  await page.getByLabel(/modèle économique/).fill('Abonnement annuel de la commune ; 2 M FCFA de fonctionnement par an.');
  await page.getByLabel(/adoptée puis maintenue/).fill('Deux agents formés la première année, puis reprise par le service.');
}

async function remplirPlan(page: Page) {
  await page.getByLabel(/combien de mois/i).fill('9');
  await page.getByLabel(/activités principales/).fill('Cartographier les bornes, brancher la passerelle SMS, former les agents.');
  await page.getByLabel(/Quels sont vos jalons/).fill('Mois 2 : bornes cartographiées. Mois 5 : passerelle en service.');
  await page.getByLabel(/quels moyens avez-vous besoin/i).fill('Un ordinateur portable, un forfait SMS, l’accès au registre communal.');
  await page.getByLabel(/risques et quelles hypothèses/).fill('Coupures réseau prolongées ; nous supposons l’accès au registre.');
  await page.getByLabel(/quel accompagnement/i).fill('Appui juridique pour la convention, et un terrain de test.');
  await page.getByLabel(/Quel budget estimez-vous/).fill('5 000 000');
}

test.describe('Étapes 5 à 7 — Solution, Impact, Plan', () => {
  test('depuis un dossier arrêté au Défi : Solution → Impact → Plan, puis 7/9', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await allerJusquAuDefi(page);

    // — Etape 5. « Defi » mene desormais a « Solution ».
    await page.getByTestId('suivant').click();
    await expect(page).toHaveURL(/\/candidate\/application\/\d+\/solution$/);
    const urlSolution = page.url();

    // Le defi de l'etape 4 est rappele, pas redemande.
    await expect(page.getByTestId('deja-renseigne')).toContainText('bornes-fontaines');
    await expect(page.getByTestId('etat-section')).toContainText('Il reste');

    await remplirSolution(page);
    await enregistrerEtAttendre(page, 'solution', true);
    await expect(page.getByTestId('etat-section')).toContainText('Étape complète');

    // Rechargement : les valeurs reviennent de PostgreSQL.
    await page.reload();
    await expect(page.getByLabel(/Comment s’appelle votre solution/)).toHaveValue('Ruwa Link');
    await expect(page.getByTestId('etat-section')).toContainText('Étape complète');

    // — Etape 6.
    await page.getByTestId('suivant').click();
    await expect(page).toHaveURL(/\/candidate\/application\/\d+\/impact$/);
    const urlImpact = page.url();

    // Le nom de la solution vient de l'etape 5, et l'ecran dit qu'il ne note rien.
    await expect(page.getByTestId('deja-renseigne')).toContainText('Ruwa Link');
    await expect(page.getByTestId('pas-de-notation')).toContainText('ne vous note pas');

    await remplirImpact(page);
    await enregistrerEtAttendre(page, 'impact', true);
    await expect(page.getByTestId('etat-section')).toContainText('Étape complète');

    // — Etape 7.
    await page.getByTestId('suivant').click();
    await expect(page).toHaveURL(/\/candidate\/application\/\d+\/implementation$/);
    const urlPlan = page.url();

    // Candidature individuelle : le porteur seul, rappele depuis l'etape 3.
    await expect(page.getByTestId('effectif-rappele')).toContainText('1 personne');

    await remplirPlan(page);
    await enregistrerEtAttendre(page, 'implementation', true);
    await expect(page.getByTestId('etat-section')).toContainText('Étape complète');
    await expect(page.getByTestId('budget-lisible')).toContainText('FCFA');

    // Le parcours ne s'arrete plus ici : l'etape 8 a ouvert depuis, et « Plan »
    // y mene. Ce que ce scenario verifie reste la navigation calculee par le
    // serveur, pas un lien ecrit en dur dans la page.
    await expect(page.getByTestId('suivant')).toHaveAttribute('href', /\/attachments$/);

    // — Aller-retour sans perte
    await page.getByTestId('precedent').click();
    await expect(page).toHaveURL(urlImpact);
    await expect(page.getByLabel(/modèle économique/)).toHaveValue(/Abonnement annuel/);
    await page.getByTestId('precedent').click();
    await expect(page).toHaveURL(urlSolution);
    await expect(page.getByLabel(/Comment s’appelle votre solution/)).toHaveValue('Ruwa Link');

    // — Deconnexion puis reconnexion : le dossier reprend ou il en etait
    await seDeconnecter(page);
    await seConnecter(page, email);

    // Sept sections achevees sur neuf. La valeur est animee : on attend qu'elle
    // se stabilise plutot que de lire le premier chiffre affiche.
    const attendu = `${Math.round((7 / 9) * 100)}%`;
    await expect(page.getByTestId('progression')).toHaveText(attendu, { timeout: 15_000 });

    await page.getByRole('link', { name: /continuer ma candidature/i }).click();
    await expect(page).toHaveURL(urlPlan);
    await expect(page.getByLabel(/combien de mois/i)).toHaveValue('9');
    await expect(page.getByLabel(/Quel budget estimez-vous/)).toHaveValue('5000000');
  });

  test('le serveur refuse une durée hors des bornes du cahier', async ({ page }) => {
    const { nom, email } = compteUnique();
    await sInscrire(page, nom, email);
    await allerJusquAuDefi(page);

    await page.goto(page.url().replace('/challenge', '/implementation'));
    await remplirPlan(page);
    await enregistrerEtAttendre(page, 'implementation', true);

    // 24 mois : la borne du §7.1 est tenue par Laravel, pas par le navigateur.
    await page.getByLabel(/combien de mois/i).fill('24');
    await page.getByLabel(/combien de mois/i).blur();

    await expect(page.getByTestId('etat-sauvegarde').first()).toContainText('Erreur d’enregistrement', { timeout: 15_000 });
    await expect(page.getByRole('alert')).toContainText(/3 à 12 mois/);

    // Rien de fautif n'est entre en base.
    await page.reload();
    await expect(page.getByLabel(/combien de mois/i)).toHaveValue('9');
  });

  test('un candidat ne peut pas ouvrir la solution d’un autre', async ({ page }) => {
    const proprietaire = compteUnique();
    await sInscrire(page, proprietaire.nom, proprietaire.email);
    await allerJusquAuDefi(page);
    await page.getByTestId('suivant').click();
    await expect(page).toHaveURL(/\/solution$/);
    const urlDuProprietaire = page.url();

    await seDeconnecter(page);

    const intrus = compteUnique();
    await sInscrire(page, intrus.nom, intrus.email);

    const reponse = await page.goto(urlDuProprietaire);
    expect(reponse?.status()).toBe(403);
  });
});
