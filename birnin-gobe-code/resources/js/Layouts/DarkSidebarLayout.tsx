import { useState, type PropsWithChildren, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Bell, Menu } from 'lucide-react';
import { BrandLogo } from '@/Components/Brand';
import { MobileNavDrawer } from '@/Components/Ui';
import { SiteFooter } from '@/Components/SiteFooter';

export type DarkNavItem = { icon: LucideIcon; label: string; href?: string };

export function DarkSidebarLayout({ children, items, active, title, subtitle, user, headerActions }: PropsWithChildren<{ items: DarkNavItem[]; active: string; title: string; subtitle?: string; user: string; headerActions?: ReactNode }>) {
  const [mobileNavOpen, setMobileNavOpen] = useState(false);

  const navLinks = (onNavigate?: () => void) => (
    <nav className="space-y-1.5">
      {items.map(({ icon: Icon, label, href = '#' }) => (
        <Link key={label} href={href} onClick={onNavigate} className={`sidebar-link ${active === label ? 'active' : ''}`}><Icon size={20} strokeWidth={1.8} /><span className="text-sm font-semibold">{label}</span></Link>
      ))}
    </nav>
  );

  const bottomCard = (
    <div className="mt-auto rounded-2xl border border-white/10 bg-black/10 p-5">
      <div className="text-sm font-extrabold text-gold-500">PIDUREM / ANSI</div>
      <p className="mt-1 text-xs leading-5 text-white/75">Un pilotage transparent, sécurisé et responsable.</p>
    </div>
  );

  return (
    <div className="min-h-screen bg-[#f7f8f7] lg:grid lg:grid-cols-[300px_1fr]">
      <aside className="sidebar-shell hidden min-h-screen flex-col px-5 py-6 lg:flex">
        <div className="px-2 pb-6"><div className="inline-block rounded-xl bg-white/95 p-2"><BrandLogo size="sidebar" /></div></div>
        {navLinks()}
        {bottomCard}
      </aside>
      <MobileNavDrawer open={mobileNavOpen} onClose={() => setMobileNavOpen(false)} panelClassName="sidebar-shell px-5 py-3">
        <div className="px-2 pb-6"><div className="inline-block rounded-xl bg-white/95 p-2"><BrandLogo size="sidebar" /></div></div>
        {navLinks(() => setMobileNavOpen(false))}
        {bottomCard}
      </MobileNavDrawer>
      <main className="flex min-h-screen min-w-0 flex-col">
        {/* `flex-wrap` + une base de 10rem sur le titre : sous ~640 px les actions
            passent a la ligne au lieu de comprimer le titre, qui se brisait
            sinon a un ou deux mots par ligne. */}
        <header className="flex min-h-[88px] flex-wrap items-center gap-x-5 gap-y-3 border-b border-slate-200 bg-white px-5 py-3 sm:px-8">
          <button className="focus-ring flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-slate-700 hover:bg-slate-50 lg:hidden" onClick={() => setMobileNavOpen(true)} aria-label="Ouvrir le menu">
            <Menu size={22} />
          </button>
          <div className="min-w-0 flex-1 basis-40 [overflow-wrap:anywhere]">
            <h1 className="text-xl font-extrabold text-brand-950 sm:text-2xl">{title}</h1>
            {subtitle ? <p className="mt-1 text-sm text-slate-500">{subtitle}</p> : null}
          </div>
          <div className="ml-auto flex items-center gap-4">{headerActions}<button className="relative grid h-10 w-10 place-items-center rounded-full hover:bg-slate-50"><Bell size={20} /><span className="absolute right-1 top-0.5 grid h-5 min-w-5 place-items-center rounded-full bg-gold-500 px-1 text-[10px] font-bold">3</span></button><div className="hidden text-right sm:block"><div className="text-sm font-bold text-slate-800">{user}</div><div className="text-[11px] text-slate-500">Connecté</div></div></div>
        </header>
        <div className="flex-1">{children}</div>
        <SiteFooter variant="compact" />
      </main>
    </div>
  );
}
