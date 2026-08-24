const LOGO_SRC = '/assets/branding/birnin-gobe-logo-final.jpg';
const LOGO_ALT = 'BIRNI’NGOBE — Startup Jeune Talent';

// header: navbar publique. Agrandi a la demande — le logo est le premier repere
// institutionnel de la page et se lisait mal. Seule la hauteur change :
// `w-auto object-contain` preserve les proportions, l'image n'est jamais etiree.
// La navbar suit (h-[104px] dans PublicLayout) pour ne pas serrer le logo.
// sidebar: sidebars candidat/admin/évaluateur. mobile: header mobile compact.
// footer: bloc marque du pied de page public (posé sur une plaque blanche).
const sizeClasses = {
  header: 'h-[64px] sm:h-[84px]',
  sidebar: 'h-16',
  mobile: 'h-[52px]',
  footer: 'h-[60px] sm:h-[72px]',
} as const;

export function BrandLogo({ size = 'header' }: { size?: keyof typeof sizeClasses }) {
  return (
    <div className="flex items-center gap-3">
      <img src={LOGO_SRC} alt={LOGO_ALT} className={`${sizeClasses[size]} w-auto object-contain`} />
      {size === 'header' ? (
        <span className="hidden text-[11px] font-semibold uppercase tracking-[.14em] text-slate-500 lg:block">
          Innovation • Résilience • Jeunesse
        </span>
      ) : null}
    </div>
  );
}
