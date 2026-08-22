import { useEffect, useState, type PropsWithChildren } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronDown, Menu, UserRound, X } from 'lucide-react';
import { BrandLogo } from '@/Components/Brand';
import { SiteFooter } from '@/Components/SiteFooter';
import { candidateEntryTarget, candidateSignupTarget, quickLinks } from '@/config/site';
import { useI18n } from '@/i18n';

export function PublicLayout({ children }: PropsWithChildren) {
  const t = useI18n();
  const navLinks = quickLinks.filter((link): link is { key: typeof link.key; href: string } => link.href !== null);
  const [menuOpen, setMenuOpen] = useState(false);

  // La navigation principale est masquee sous `xl`, et « Se connecter » sous
  // `md` : sans ce panneau, un visiteur mobile n'avait acces a aucune rubrique,
  // seul « Candidater » restait visible.
  useEffect(() => {
    if (!menuOpen) return;
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setMenuOpen(false);
    };
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [menuOpen]);

  return (
    <div className="flex min-h-screen flex-col bg-white">
      <header className="sticky top-0 z-40 border-b border-black/5 bg-white/95 backdrop-blur">
        <div className="mx-auto flex h-[82px] max-w-[1500px] items-center justify-between gap-6 px-5 lg:px-10">
          <BrandLogo size="header" />
          <nav className="hidden items-center gap-7 xl:flex" aria-label="Navigation principale">
            {/* Mêmes destinations que le pied de page (config/site.ts) : une rubrique
                sans route réelle n'apparaît pas, plutôt que de pointer vers une ancre morte. */}
            {navLinks.map((link, index) => (
              <a key={link.key} href={link.href} className={`focus-ring rounded-md text-sm font-semibold ${index === 0 ? 'text-brand-800' : 'text-slate-700 hover:text-brand-800'}`}>
                {t.footer.links[link.key]}
              </a>
            ))}
          </nav>
          <div className="flex items-center gap-2.5">
            <button className="focus-ring hidden min-h-10 items-center gap-1 rounded-lg px-3 text-sm font-bold text-slate-700 sm:flex">FR <ChevronDown size={15} /></button>
            <Link
              href={candidateEntryTarget}
              className="focus-ring hidden min-h-11 items-center gap-2 rounded-xl border border-brand-900 px-4 text-sm font-bold text-brand-900 md:flex"
            >
              <UserRound size={17} /> Se connecter
            </Link>
            <Link href={candidateSignupTarget} className="focus-ring inline-flex min-h-11 items-center rounded-xl bg-gold-500 px-5 text-sm font-extrabold text-slate-950 hover:bg-gold-600">{t.footer.ctaApply}</Link>
            <button
              type="button"
              className="focus-ring grid h-11 w-11 place-items-center rounded-xl border border-slate-200 text-brand-900 xl:hidden"
              aria-expanded={menuOpen}
              aria-controls="menu-mobile"
              aria-label={menuOpen ? 'Fermer le menu' : 'Ouvrir le menu'}
              onClick={() => setMenuOpen((v) => !v)}
            >
              {menuOpen ? <X size={20} /> : <Menu size={20} />}
            </button>
          </div>
        </div>

        {menuOpen ? (
          <nav id="menu-mobile" className="border-t border-slate-100 bg-white px-5 pb-5 pt-2 xl:hidden" aria-label="Navigation principale (mobile)">
            {/* Le slogan est masque sous `lg` faute de place a cote du logo :
                le panneau deplie est le seul endroit ou il tient. */}
            <div className="px-3 pb-2 pt-1 text-[11px] font-semibold uppercase tracking-[.14em] text-slate-500 lg:hidden">
              Innovation • Résilience • Jeunesse
            </div>
            <ul>
              {navLinks.map((link) => (
                <li key={link.key}>
                  <a
                    href={link.href}
                    className="focus-ring flex min-h-12 items-center rounded-lg px-3 text-base font-semibold text-slate-700 hover:bg-brand-50 hover:text-brand-900"
                    onClick={() => setMenuOpen(false)}
                  >
                    {t.footer.links[link.key]}
                  </a>
                </li>
              ))}
            </ul>

            {/* Entree candidat uniquement. Le menu public ne doit exposer aucun
                acces aux espaces internes — voir ADR-003. */}
            <div className="mt-3 border-t border-slate-100 pt-3 md:hidden">
              <Link
                href={candidateEntryTarget}
                className="focus-ring flex min-h-12 items-center gap-2 rounded-lg px-3 text-base font-semibold text-slate-700 hover:bg-brand-50 hover:text-brand-900"
                onClick={() => setMenuOpen(false)}
              >
                <UserRound size={17} /> Se connecter
              </Link>
            </div>

            <button className="focus-ring mt-3 flex min-h-12 items-center gap-1 rounded-lg px-3 text-base font-bold text-slate-700 sm:hidden">
              FR <ChevronDown size={16} />
            </button>
          </nav>
        ) : null}
      </header>
      <main className="flex-1">{children}</main>
      <SiteFooter variant="public" />
    </div>
  );
}
