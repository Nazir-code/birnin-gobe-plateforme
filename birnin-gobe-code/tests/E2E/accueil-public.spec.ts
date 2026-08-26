import { expect, test, type Page } from '@playwright/test';

/**
 * La page d'accueil telle qu'un visiteur la voit.
 *
 * Ce que ce fichier eprouve, et que les tests serveur ne peuvent pas atteindre :
 * la page est rendue par le navigateur, donc ses libelles, son ancrage et sa
 * mise en page n'existent nulle part dans le HTML servi. Les chiffres et le
 * contenu officiel, eux, sont deja verifies cote serveur — ils sont servis en
 * props par `HomeController` — et ne sont repris ici que pour prouver qu'ils
 * arrivent bien a l'ecran.
 *
 * Les scenarios ne creent aucune donnee : ils lisent la page publique dans
 * l'etat ou la base se trouve. Les assertions portent donc sur des faits
 * structurels — un libelle, un lien, une ancre, un nombre de cartes — jamais sur
 * une valeur qui dependrait d'un autre scenario.
 */
const TAILLES = [
  { nom: 'desktop', largeur: 1440, hauteur: 900 },
  { nom: 'tablette', largeur: 834, hauteur: 1112 },
  { nom: 'mobile', largeur: 390, hauteur: 844 },
] as const;

async function accueil(page: Page) {
  await page.goto('/');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
}

test.describe('Page d’accueil publique', () => {
  test('le contenu officiel est affiché, sans donnée de maquette', async ({ page }) => {
    await accueil(page);

    // — Les quatre thematiques, avec leurs deux volets distincts
    for (let rang = 1; rang <= 4; rang += 1) {
      await expect(page.getByTestId(`thematique-${rang}`)).toBeVisible();
    }
    await expect(page.getByTestId('thematique-1')).toContainText('Gestion urbaine et services de base');
    await expect(page.getByTestId('thematique-1')).toContainText('Problèmes prioritaires');
    await expect(page.getByTestId('thematique-1')).toContainText('Résultats attendus');
    await expect(page.getByTestId('thematique-4')).toContainText('Cartographie, géolocalisation, risques et résilience');

    // — Les huit criteres d'evaluation, annonces comme tels
    for (let rang = 1; rang <= 8; rang += 1) {
      await expect(page.getByTestId(`critere-${rang}`)).toBeVisible();
    }
    await expect(page.getByTestId('critere-1')).toContainText('Pertinence');
    await expect(page.getByTestId('critere-8')).toContainText('Équipe et pitch');
    await expect(page.locator('#criteres')).toContainText('Critères d’évaluation');
    // La distinction avec l'eligibilite doit etre ecrite : sans elle, un candidat
    // croira devoir satisfaire les huit pour avoir le droit de deposer.
    await expect(page.locator('#criteres')).toContainText('Ils ne décident pas');

    // — Aucune valeur de maquette
    const texte = await page.locator('body').innerText();
    for (const trace of ['5 000+', '1 200+', '500+', 'Jeunes impactés', 'Agroalimentaire', '30 juin 2026']) {
      expect(texte, `« ${trace} » est encore affiché`).not.toContain(trace);
    }
  });

  test('le libellé de clôture est exact et accompagné du verrou', async ({ page }) => {
    await accueil(page);

    const texte = await page.locator('body').innerText();
    expect(texte).toContain('Clôture des candidatures');
    expect(texte).not.toContain('Prochaine clôture');

    // La consequence de la date est dite, pas seulement suggeree par l'icone —
    // et elle dit ce que le serveur fait vraiment : le depot se ferme. Un
    // brouillon, lui, reste modifiable, la policy ne connaissant aucune date.
    // Voir ClotureEtVerrouillageTest.
    expect(texte).toContain('aucune candidature ne pourra être soumise');
    await expect(page.getByTestId('cloture')).toBeVisible();
  });

  test('le sélecteur de langue a disparu', async ({ page }) => {
    await accueil(page);

    await expect(page.getByRole('button', { name: /^FR/ })).toHaveCount(0);
    expect(await page.locator('header').innerText()).not.toMatch(/\bFR\b/);
  });

  test('les deux entrées sont distinctes : se connecter, ou commencer', async ({ page }) => {
    await accueil(page);

    // L'entree « se connecter » existe a toutes les tailles, mais pas au meme
    // endroit : dans la navbar au-dessus de `md`, dans le tiroir et dans l'appel
    // a l'action final en dessous. C'est la destination qui compte, pas le
    // libelle ni sa place.
    await expect(page.locator('a[href="/login"]').first()).toBeAttached();

    // Le CTA public nomme l'action. Selon qu'une edition est ouverte, il mene a
    // l'inscription ou il est eteint — les deux etats sont legitimes, un lien
    // vers un formulaire ferme ne le serait pas.
    const ouvert = await page.getByTestId('cta-candidater').count();

    if (ouvert > 0) {
      await expect(page.getByTestId('cta-candidater')).toHaveAttribute('href', '/register');
      await expect(page.getByTestId('cta-candidater')).toContainText('Commencer ma candidature');
    } else {
      // Hors periode, plus de bouton eteint : seule la raison reste ecrite.
      await expect(page.getByTestId('cta-candidater')).toHaveCount(0);
      await expect(page.getByTestId('aucune-campagne')).toContainText('ne sont pas ouvertes');
    }
  });

  /**
   * Ce que la page ne doit plus porter.
   *
   * Une suppression se verifie par l'absence, pas par la relecture du diff :
   * un bloc retire d'un composant mais toujours servi par une autre branche du
   * rendu ne se voit qu'ici.
   */
  test('les blocs retires ne sont plus rendus', async ({ page }) => {
    await accueil(page);
    const corps = await page.locator('body').innerText();

    for (const trace of [
      // Hero et appel a l'action final
      'Candidatures fermees', 'Candidatures fermées', 'Decouvrir le processus', 'Découvrir le processus',
      'Pret a presenter votre projet', 'Prêt à présenter votre projet', 'J’ai deja un compte', 'J’ai déjà un compte',
      // Chiffres cles
      'Candidats inscrits', 'Candidatures en cours', 'Candidatures soumises',
      // Bloc en quatre points
      'Un dossier en 9 étapes', 'Un dépôt officiel', 'Une évaluation annoncée', 'Des données protégées',
      // Texte explicatif du parcours
      'Vous remplissez votre dossier en neuf étapes',
      // Descriptions sous « Resultats attendus »
      'Collecte terrain', 'Indexation sécurisée', 'Orientation des usagers', 'Données géoréférencées',
      // Colonnes de pied de page
      'Liens rapides', 'Centre d’aide',
    ]) {
      expect(corps, `« ${trace} » est encore affiché`).not.toContain(trace);
    }

    await expect(page.getByTestId('chiffres-cles')).toHaveCount(0);
    await expect(page.getByTestId('cta-final')).toHaveCount(0);
    await expect(page.getByTestId('lien-processus')).toHaveCount(0);
  });

  test('les cinq conditions d’éligibilité sont affichées, dans l’ordre', async ({ page }) => {
    await accueil(page);

    const conditions = page.getByTestId('criteres-eligibilite').getByRole('listitem');
    await expect(conditions).toHaveCount(5);
    await expect(conditions.nth(0)).toContainText('18 à 35 ans');
    await expect(conditions.nth(1)).toContainText('nationalité nigérienne');
    await expect(conditions.nth(2)).toContainText('solution numérique innovante');
    await expect(conditions.nth(3)).toContainText('complet, sincère et conforme');
    await expect(conditions.nth(4)).toContainText('toutes les étapes de la compétition');

    // Les criteres d'evaluation, eux, n'ont pas bouge.
    await expect(page.getByTestId('critere-1')).toContainText('Pertinence');
    await expect(page.getByTestId('critere-8')).toContainText('Équipe et pitch');
  });

  test('le pied de page ne garde ni colonne vide ni centre d’aide', async ({ page }) => {
    await accueil(page);
    const pied = page.locator('footer');

    await expect(pied).toContainText('Besoin d’Aide ?');
    await expect(pied.getByRole('link', { name: '+227 99 99 93 19' })).toHaveAttribute('href', 'tel:+22799999319');
    await expect(pied.getByRole('link', { name: 'hello@birningobe.ne' })).toHaveAttribute('href', 'mailto:hello@birningobe.ne');
    await expect(pied).toContainText('Email :');
  });

  /**
   * Le titre du hero arrive en deux temps, puis ne bouge plus.
   *
   * Ce test ne mesure aucune duree : une assertion sur un timing depend de la
   * machine qui l execute et finit par clignoter en CI. Il verifie ce qui doit
   * rester vrai une fois l animation finie — la phrase, mot pour mot, et le
   * trait qui souligne sa seconde moitie.
   */
  test('le titre du hero est intact et souligné', async ({ page }) => {
    await accueil(page);

    const titre = page.getByRole('heading', { level: 1 });
    await expect(titre).toHaveText('La plateforme nationale qui propulse les jeunes innovateurs du Niger.');

    const accent = page.getByTestId('hero-accent');
    await expect(accent).toBeVisible();
    await expect(accent).toHaveText('jeunes innovateurs du Niger.');

    const fond = await accent.evaluate((n) => getComputedStyle(n).backgroundImage);
    expect(fond, 'le trait vert-orange doit etre un degrade').toContain('gradient');
  });

  test('le titre du hero reste visible sans animation', async ({ page }) => {
    // Mouvement reduit : le contenu doit etre la tout de suite, jamais masque
    // par une opacite de depart ni retarde par un delai.
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await accueil(page);

    const accent = page.getByTestId('hero-accent');
    await expect(accent).toBeVisible();
    await expect(accent).toHaveCSS('opacity', '1');
    await expect(page.locator('.hero-part-1')).toHaveCSS('opacity', '1');
    await expect(page.getByRole('heading', { level: 1 }))
      .toContainText('propulse les jeunes innovateurs du Niger.');
  });

  /**
   * Les cinq rubriques de la navigation, et le titre qu'on doit lire en
   * arrivant.
   *
   * Le titre compte autant que la section : une ancre qui amene la bonne
   * section sous l'en-tete collant a l'air de marcher et ne montre rien.
   *
   * L'ancien test de cette navigation lisait `href="#..."` et vivait ici ; la
   * branche « ancres » l'a remplace par la boucle qui clique, plus bas. La
   * fusion le ramenait, a moitie, depuis la branche « hero » qui ne l'avait pas
   * touche : il est ecarte, pas reintroduit.
   */
  const RUBRIQUES = [
    ['Thématiques', '#thematiques', /Quatre domaines, des défis concrets/i],
    // Les titres portent des apostrophes — droites ici, typographiques la — et
    // un editeur en change sans prevenir. Les motifs s'arretent avant, plutot
    // que de faire dependre le test d'un caractere invisible a la relecture.
    ['Critères', '#criteres', /Critères d/i],
    ['Éligibilité', '#eligibilite', /éligibilité en un coup d/i],
    ['Calendrier', '#calendrier', /Les dates de l/i],
    ['Le processus', '#processus', /Comment candidater/i],
  ] as const;

  for (const { nom, largeur, hauteur } of [TAILLES[0], TAILLES[2]] as const) {
    /**
     * Ce test clique, il ne lit pas un attribut.
     *
     * Verifier `href="#..."` ne prouvait rien : les trois ancres accentuees
     * portaient l'attribut attendu et n'amenaient nulle part. Seul le
     * deplacement reel du viewport dit si la navigation fonctionne.
     */
    test(`la navigation mène réellement aux sections — ${nom}`, async ({ page }) => {
      await page.setViewportSize({ width: largeur, height: hauteur });
      await accueil(page);

      const surMobile = largeur < 1280;

      for (const [libelle, ancre, titre] of RUBRIQUES) {
        // Sous `xl`, la navigation vit dans le panneau deplie.
        if (surMobile) {
          await page.getByRole('button', { name: /ouvrir le menu/i }).click();
        }

        const menu = page.getByRole('navigation', {
          name: surMobile ? 'Navigation principale (mobile)' : 'Navigation principale',
        });
        const lien = menu.getByRole('link', { name: libelle });

        await expect(lien, `le menu ne porte pas « ${libelle} »`).toBeVisible();
        await lien.click();

        // Le panneau mobile se referme sur la selection, sinon il masque la
        // section qu'on vient de demander.
        if (surMobile) {
          await expect(menu, `le menu mobile reste ouvert apres « ${libelle} »`).toBeHidden();
        }

        // L'URL porte l'ancre, et la page n'a pas ete rechargee.
        await expect(page).toHaveURL(new RegExp(`${ancre.slice(1)}$`));

        const section = page.locator(ancre);
        await expect(section, `« ${libelle} » n'amene pas sa section`).toBeInViewport();
        await expect(
          section.getByRole('heading', { name: titre }),
          `le titre de « ${libelle} » est masque par l'en-tete collant`,
        ).toBeInViewport();
      }
    });
  }

  for (const { nom, largeur, hauteur } of TAILLES) {
    test(`aucun débordement horizontal en ${nom}`, async ({ page }) => {
      await page.setViewportSize({ width: largeur, height: hauteur });
      await accueil(page);

      // Le logo agrandi et le menu elargi ne doivent pas pousser la page hors
      // de son cadre : c'est le risque concret de ces deux changements.
      const debordement = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      );
      expect(debordement, `${nom} déborde de ${debordement}px`).toBeLessThanOrEqual(1);

      // Le logo reste visible et proportionne a toutes les tailles.
      const logo = page.locator('header img').first();
      await expect(logo).toBeVisible();
      const boite = await logo.boundingBox();
      expect(boite?.height ?? 0).toBeGreaterThan(48);
    });
  }
});
