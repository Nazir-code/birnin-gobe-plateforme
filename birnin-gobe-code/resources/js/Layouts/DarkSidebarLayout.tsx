import { useState, type PropsWithChildren, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { LogOut, Menu } from 'lucide-react';
import { BrandLogo } from '@/Components/Brand';
import { MobileNavDrawer } from '@/Components/Ui';
import { SiteFooter } from '@/Components/SiteFooter';
import { initiales, useAuthUser } from '@/hooks/useAuth';

export type DarkNavItem = { icon: LucideIcon; label: string; href?: string };

/**
 * Ossature des espaces internes — administration, évaluation, jury.
 *
 * **Il n'y a pas de cloche de notifications dans l'en-tête, et c'est
 * délibéré.** Elle y a figuré, inerte, sur les douze écrans internes : un
 * bouton au curseur de pointeur qui ne menait à rien. Le §8.3 n'est pas
 * implémenté — aucune notification n'est envoyée, aucun centre ne les
 * rassemble — et une cloche muette n'est pas un emplacement réservé, c'est une
 * promesse que l'écran ne tient pas. Même règle que pour `adminNav` et
 * `evaluatorNav` : pas d'écran derrière, pas de commande devant. Elle
 * reviendra avec ce qu'elle ouvre.
 */

export function DarkSidebarLayout({
  children,
  items,
  active,
  title,
  subtitle,
  user,
  logoutHref,
  headerActions,
}: PropsWithChildren<{
  items: DarkNavItem[];
  active: string;
  title: string;
  subtitle?: string;
  /**
   * Identité affichée. À omettre : elle vient alors de l'utilisateur
   * authentifié partagé par Inertia, qui est la seule source réelle.
   * Le paramètre ne subsiste que pour les écrans encore non authentifiés.
   */
  user?: string;
  /** Rend le bouton de déconnexion, qui poste vers cette URL. */
  logoutHref?: string;
  headerActions?: ReactNode;
}>) {
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const authUser = useAuthUser();
  const nom = user ?? authUser?.name ?? null;

  const navLinks = (onNavigate?: () => void) => (
    <nav className="space-y-1.5">
      {items.map(({ icon: Icon, label, href = '#' }) => (
        <Link key={label} href={href} onClick={onNavigate} className={`sidebar-link ${active === label ? 'active' : ''}`}><Icon size={20} strokeWidth={1.8} /><span className="text-sm font-semibold">{label}</span></Link>
      ))}
    </nav>
  );

  const bottomCard = (
    <div className="mt-auto">
      <div className="rounded-2xl border border-white/10 bg-black/10 p-5">
        <div className="text-sm font-extrabold text-gold-500">PIDUREM / ANSI</div>
        <p className="mt-1 text-xs leading-5 text-white/75">Un pilotage transparent, sécurisé et responsable.</p>
      </div>
      {logoutHref ? (
        <Link href={logoutHref} method="post" as="button" className="focus-ring mt-3 flex min-h-11 w-full items-center gap-3 rounded-xl px-4 text-sm font-semibold text-white/80 transition hover:bg-white/10 hover:text-white">
          <LogOut size={17} /> Se déconnecter
        </Link>
      ) : null}
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
          <div className="ml-auto flex items-center gap-4">
            {headerActions}
            {nom ? (
              <div className="hidden items-center gap-3 sm:flex">
                <div className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-brand-50 text-xs font-extrabold text-brand-900">{initiales(nom)}</div>
                <div className="text-right"><div className="text-sm font-bold text-slate-800">{nom}</div><div className="text-[11px] text-slate-500">Connecté</div></div>
              </div>
            ) : null}
          </div>
        </header>
        <div className="flex-1">{children}</div>
        <SiteFooter variant="compact" />
      </main>
    </div>
  );
}
