// Static UI strings live here instead of being embedded in domain logic.
export const fr = {
  common: { save: 'Enregistrer', next: 'Suivant', cancel: 'Annuler', viewAll: 'Voir tout' },
  application: { draftSaved: 'Enregistré automatiquement', submit: 'Soumettre ma candidature' },
  eligibility: { warning: 'Ce résultat est indicatif et ne remplace pas la vérification administrative.' },
  footer: {
    brandTagline: 'Startup Jeune Talent',
    // Description institutionnelle dérivée du cahier des charges (§1 Contexte, §4 Front-office),
    // pas d'une promesse inventée : la plateforme est le point d'entrée national de la compétition.
    about:
      "BIRNI’NGOBE est le point d’entrée national de la compétition dédiée aux startups et aux jeunes talents qui conçoivent des solutions numériques répondant aux défis des villes et territoires du Niger.",
    quickLinks: 'Liens rapides',
    resources: 'Ressources',
    help: 'Besoin d’Aide ?',
    helpText: 'Une question sur votre candidature ? Contactez-nous au',
    helpPhone: '+227 99 99 93 19',
    helpEmailLabel: 'Email :',
    helpEmail: 'hello@birningobe.ne',
    institutionalPartners: 'Partenaires institutionnels',
    // Maîtrise d'ouvrage telle qu'énoncée dans le cahier des charges.
    partnersNote: 'Une initiative du PIDUREM — Galey Ma Zaada, en coordination avec l’ANSI.',
    ctaTitle: 'Prêt à transformer votre idée en solution ?',
    ctaText: 'Déposez votre dossier en plusieurs étapes, avec sauvegarde automatique.',
    ctaApply: 'Commencer ma candidature',
    ctaUpcoming: 'Ouverture prochaine',
    ctaClosed: 'Candidatures closes',
    contact: 'Contact',
    rights: 'Tous droits réservés.',
    // Le cœur est du contenu, pas une icône : il porte le sens de la phrase et
    // doit être lu par une synthèse vocale comme il est vu.
    //
    // La phrase est coupée parce que deux de ses mots sont des liens. Les noms
    // des réalisateurs ne sont **pas** ici : un nom propre ne se traduit pas, et
    // le laisser dans le dictionnaire inviterait à en écrire une variante par
    // locale. Ils vivent dans `config/site.ts`, avec leur destination.
    credits: {
      prefix: 'Développé avec ❤️ par',
      separator: '&',
    },
    links: {
      home: 'Accueil',
      about: 'À propos',
      themes: 'Thématiques',
      eligibility: 'Éligibilité',
      calendar: 'Calendrier',
      process: 'Le processus',
      howToApply: 'Comment postuler',
      criteria: 'Critères',
      faq: 'FAQ',
      news: 'Actualités',
      resources: 'Ressources',
      results: 'Résultats',
      support: 'Assistance',
      legalNotice: 'Mentions légales',
      privacy: 'Politique de confidentialité',
      dataProtection: 'Protection des données',
      accessibility: 'Accessibilité',
      publicSite: 'Site public',
    },
  },
};
