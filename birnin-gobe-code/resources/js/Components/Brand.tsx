const LOGO_SRC = '/assets/branding/birnin-gobe-logo-final.jpg';
const LOGO_ALT = 'BIRNI’NGOBE — Startup Jeune Talent';

// header: navbar publique (60-75px desktop, 48-60px mobile via breakpoint).
// sidebar: sidebars candidat/admin/évaluateur. mobile: header mobile compact.
// footer: bloc marque du pied de page public (posé sur une plaque blanche).
const sizeClasses = {
  header: 'h-[56px] sm:h-[68px]',
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
