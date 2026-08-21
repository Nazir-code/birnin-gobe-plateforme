import { useEffect, useRef, useState, type PropsWithChildren } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronDown, UserRound } from 'lucide-react';
import { BrandLogo } from '@/Components/Brand';
import { SiteFooter } from '@/Components/SiteFooter';
import { prototypeApplyTarget, quickLinks } from '@/config/site';
import { useI18n } from '@/i18n';

// Sélecteur de rôle temporaire : il n'y a pas encore de vraie authentification.
// À remplacer par un vrai login quand l'auth/RBAC sera branché.
const demoRoles = [
  { label: 'Candidat', href: '/candidate/dashboard' },
  { label: 'Admin', href: '/admin/dashboard' },
  { label: 'Évaluateur / Jury', href: '/evaluator/assignments' },
];

function LoginRoleSwitcher() {
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const onClickOutside = (e: MouseEvent) => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onClickOutside);
    return () => document.removeEventListener('mousedown', onClickOutside);
  }, [open]);

  return (
    <div className="relative hidden md:block" ref={rootRef}>
      <button
        className="focus-ring flex min-h-11 items-center gap-2 rounded-xl border border-brand-900 px-4 text-sm font-bold text-brand-900"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
      >
        <UserRound size={17} /> Se connecter <ChevronDown size={15} className={`transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      {open ? (
        <div className="absolute right-0 top-[calc(100%+8px)] w-64 rounded-xl border border-slate-200 bg-white p-2 shadow-lg">
          <div className="px-3 pb-2 pt-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Accès démonstration</div>
          {demoRoles.map((role) => (
            <Link key={role.href} href={role.href} onClick={() => setOpen(false)} className="focus-ring flex min-h-11 items-center rounded-lg px-3 text-sm font-semibold text-slate-700 hover:bg-brand-50 hover:text-brand-900">
              {role.label}
            </Link>
          ))}
        </div>
      ) : null}
    </div>
  );
}

export function PublicLayout({ children }: PropsWithChildren) {
  const t = useI18n();
  const navLinks = quickLinks.filter((link): link is { key: typeof link.key; href: string } => link.href !== null);

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
            <LoginRoleSwitcher />
            <a href={prototypeApplyTarget} className="focus-ring inline-flex min-h-11 items-center rounded-xl bg-gold-500 px-5 text-sm font-extrabold text-slate-950 hover:bg-gold-600">{t.footer.ctaApply}</a>
          </div>
        </div>
      </header>
      <main className="flex-1">{children}</main>
      <SiteFooter variant="public" />
    </div>
  );
}
