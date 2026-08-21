export const demoCampaign = {
  code: 'BG-2026',
  name: 'BIRNIN GOBE 2026',
  deadlineLabel: '30 juin 2026 à 23h59 (GMT+1)',
  countdown: { days: 24, hours: 12, minutes: 45, seconds: 30 },
  // Presentation/demo values only. In production these come from Campaign/CMS configuration.
  stats: [
    ['5 000+', 'Jeunes impactés'],
    ['1 200+', 'Projets accompagnés'],
    ['500+', 'Partenaires engagés'],
    ['8', 'Régions couvertes'],
  ],
};

export const themes = [
  ['Agroalimentaire', 'Agriculture durable et sécurité alimentaire'],
  ['Numérique', 'Solutions digitales, IA et technologies émergentes'],
  ['Énergie & Environnement', 'Énergies renouvelables et résilience'],
  ['Éducation', 'Apprentissage, EdTech et employabilité'],
  ['Culture & Créativité', 'Design, arts et industries culturelles'],
];

export const candidateSteps = [
  'Éligibilité', 'Profil', 'Structure / équipe', 'Défi', 'Solution', 'Impact / viabilité', 'Plan de mise en œuvre', 'Pièces / déclarations', 'Relecture / envoi',
];

// Répartition géographique de démonstration (voir ADR-002) : en production, les
// paliers sont calculés à partir du nombre réel de candidatures par région.
export const demoRegionIntensity = {
  'NE-7': 'high',      // Zinder
  'NE-4': 'high',      // Maradi
  'NE-5': 'elevated',  // Tahoua
  'NE-6': 'elevated',  // Tillabéri
  'NE-8': 'medium',    // Niamey
  'NE-3': 'medium',    // Dosso
  'NE-1': 'low',       // Agadez
  'NE-2': 'low',       // Diffa
} as const;
