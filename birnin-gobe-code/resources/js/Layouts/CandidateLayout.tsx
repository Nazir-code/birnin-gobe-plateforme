import { useState, type PropsWithChildren, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { FileText, Gauge, LogOut, Menu, UserRound } from 'lucide-react';
import { BrandLogo } from '@/Components/Brand';
import { initiales, useAuthUser } from '@/hooks/useAuth';
import { MobileNavDrawer } from '@/Components/Ui';
import { SiteFooter } from '@/Components/SiteFooter';

/**
 * Le menu du candidat : trois entrees, et chacune mene quelque part.
 *
 * Il en comptait sept. Les quatre autres — « Mes messages », « Mes documents »,
 * « Assistance », « Parametres » — pointaient sur `#`, c'est-a-dire nulle part.
 * Un candidat qui clique et ne voit rien se produire ne conclut pas « ce module
 * arrive plus tard » : il conclut que la plateforme est cassee, et il doute
 * aussi des entrees qui, elles, fonctionnent. C'est le retour d'usage qui a
 * motive ce retrait.
 *
 * Rien n'a ete supprime de ces modules — ils n'existent pas encore. Ils
 * reviendront dans ce tableau le jour ou ils auront un ecran, et pas avant :
 * une entree de menu est une promesse.
 *
 * Les deux entrees ajoutees ici sont des points d'entree serveur qui resolvent
 * le dossier du candidat authentifie puis redirigent. Le menu n'a donc a
 * connaitre ni l'identifiant du dossier, ni l'etape courante — et ne peut pas
 * mener au dossier d'un autre.
 */
const nav = [
  [Gauge, 'Tableau de bord', '/candidate/dashboard'],
  [UserRound, 'Mon profil', '/candidate/profile'],
  [FileText, 'Ma candidature', '/candidate/application'],
] as const;

export function CandidateLayout({ children, active = 'Tableau de bord', topSlot }: PropsWithChildren<{ active?: string; topSlot?: ReactNode }>) {
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const user = useAuthUser();

  const navLinks = (onNavigate?: () => void) => (
    <nav className="space-y-1 px-4">
      {nav.map(([Icon, label, href]) => {
        const courant = active === label;

        return (
          <Link
            href={href}
            key={label}
            onClick={onNavigate}
            data-testid={`nav-${href.split('/').pop()}`}
            // `aria-current` porte l'etat actif pour un lecteur d'ecran ; la
            // couleur seule ne le dirait qu'a ceux qui la voient.
            aria-current={courant ? 'page' : undefined}
            className={`focus-ring flex min-h-12 items-center gap-3 rounded-xl px-4 text-sm font-semibold transition ${courant ? 'bg-brand-900 text-white shadow-sm' : 'text-slate-700 hover:bg-slate-50'}`}
          >
            <Icon size={18} strokeWidth={1.7} /> <span className="truncate">{label}</span>
          </Link>
        );
      })}
    </nav>
  );

  const bottomCard = (
    <div className="mt-auto px-5 pb-5">
      <div className="rounded-2xl bg-gradient-to-br from-brand-900 to-brand-950 p-5 text-white">
        <div className="text-xs font-bold text-gold-500">BIRNIN GOBE</div>
        <p className="mt-2 text-sm font-semibold leading-6">Construisons ensemble l'innovation de demain.</p>
      </div>
      <Link href="/logout" method="post" as="button" className="mt-3 flex min-h-11 w-full items-center gap-3 rounded-xl px-4 text-sm font-semibold text-red-600 hover:bg-red-50"><LogOut size={17} /> Se déconnecter</Link>
    </div>
  );

  return (
    <div className="min-h-screen bg-[#fafaf7] lg:grid lg:grid-cols-[265px_1fr]">
      <aside className="hidden border-r border-slate-200 bg-white lg:flex lg:min-h-screen lg:flex-col">
        <div className="px-7 py-6"><BrandLogo size="sidebar" /></div>
        {navLinks()}
        {bottomCard}
      </aside>
      <MobileNavDrawer open={mobileNavOpen} onClose={() => setMobileNavOpen(false)} panelClassName="bg-white" testId="menu-mobile">
        <div className="px-3 pb-3"><BrandLogo size="sidebar" /></div>
        {navLinks(() => setMobileNavOpen(false))}
        {bottomCard}
      </MobileNavDrawer>
      <main className="flex min-h-screen min-w-0 flex-col">
        <div className="flex min-h-[76px] items-center justify-between border-b border-slate-200 bg-white px-5 sm:px-8">
          <div className="flex items-center gap-3 lg:hidden">
            <button className="focus-ring flex h-10 w-10 items-center justify-center rounded-lg text-slate-700 hover:bg-slate-50" onClick={() => setMobileNavOpen(true)} aria-label="Ouvrir le menu">
              <Menu size={22} />
            </button>
            <BrandLogo size="mobile" />
          </div>
          <div className="ml-auto flex items-center gap-5">
            {topSlot}
            {user ? <div className="hidden items-center gap-3 sm:flex"><div className="grid h-10 w-10 place-items-center rounded-full bg-amber-100 font-extrabold text-amber-700">{initiales(user.name)}</div><div className="text-sm font-bold">{user.name}</div></div> : null}
          </div>
        </div>
        <div className="flex-1">{children}</div>
        <SiteFooter variant="compact" />
      </main>
    </div>
  );
}
