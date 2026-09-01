import { useId } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowRight, Clock3, Mail, MapPin, Phone } from 'lucide-react';
import { BrandLogo } from '@/Components/Brand';
import { Reveal } from '@/Components/Reveal';
import { useI18n } from '@/i18n';
import {
  institutionalPartners,
  isAnchorHref,
  legalLinks,
  prototypeApplyTarget,
  publicSiteLink,
  supportLink,
  useSiteData,
  type SiteLink,
} from '@/config/site';

/**
 * Pied de page unique de la plateforme.
 *
 * - `variant="public"` : footer institutionnel complet (portail public).
 * - `variant="compact"` : footer sobre des espaces de travail (candidat, admin,
 *   évaluateur, jury, authentification) — sans logos partenaires ni appel à candidater.
 *
 * Il est monté par les layouts partagés, jamais page par page.
 */
export function SiteFooter({ variant = 'public' }: { variant?: 'public' | 'compact' }) {
  return variant === 'compact' ? <CompactFooter /> : <PublicFooter />;
}

const currentYear = () => new Date().getFullYear();

/**
 * Ne garde que les entrées qui mènent réellement quelque part.
 *
 * Le pied compact filtrait déjà ; le pied public, non — d'où les quatre
 * marqueurs « Bientôt » sur les mentions légales. Un seul prédicat pour les
 * deux, sans quoi la prochaine divergence se rejouera.
 */
function destinationsExistantes(liens: SiteLink[]): (SiteLink & { href: string })[] {
  return liens.filter((lien): lien is SiteLink & { href: string } => lien.href !== null);
}

function useLinkLabel() {
  const t = useI18n();
  return (link: SiteLink) => t.footer.links[link.key];
}

/**
 * Un lien du pied de page, toujours cliquable.
 *
 * Le type l'impose : `href` n'est plus nullable ici. Les entrées sans
 * destination sont écartées par l'appelant, et ne peuvent donc plus produire un
 * libellé inerte — ni le marqueur « Bientôt » qu'elles portaient, qui donnait
 * au pied de page l'air d'un chantier.
 */
function FooterLink({ link, tone = 'column' }: { link: SiteLink & { href: string }; tone?: 'column' | 'inline' }) {
  const label = useLinkLabel()(link);
  const size = tone === 'column' ? 'text-sm' : 'text-xs';

  const className = `footer-link focus-ring rounded-sm ${size} text-white/75 hover:text-white`;
  return isAnchorHref(link.href) ? (
    <a href={link.href} className={className}>{label}</a>
  ) : (
    <Link href={link.href} className={className}>{label}</Link>
  );
}


function ApplyCta() {
  const t = useI18n();
  const { campaign } = useSiteData();

  // Aucun état de campagne n'est codé en dur : le libellé suit le statut partagé
  // par le serveur quand il existe, sinon l'action reprend la cible de l'appel à
  // candidater déjà présent dans l'en-tête public et le hero.
  if (campaign?.status === 'CLOSED' || campaign?.status === 'UPCOMING') {
    return (
      <span className="inline-flex min-h-11 items-center rounded-xl border border-white/25 px-5 text-sm font-bold text-white/70">
        {campaign.status === 'CLOSED' ? t.footer.ctaClosed : t.footer.ctaUpcoming}
      </span>
    );
  }

  const href = campaign?.applyUrl ?? prototypeApplyTarget;
  const className =
    'focus-ring press-feedback group inline-flex min-h-11 shrink-0 items-center gap-2 rounded-xl bg-gold-500 px-6 text-sm font-extrabold text-slate-950 transition-colors hover:bg-gold-600';
  const content = (
    <>
      {t.footer.ctaApply}
      <ArrowRight size={17} className="transition-transform duration-200 group-hover:translate-x-1" />
    </>
  );

  return isAnchorHref(href) ? (
    <a href={href} className={className}>{content}</a>
  ) : (
    <Link href={href} className={className}>{content}</Link>
  );
}

function ContactBlock() {
  const t = useI18n();
  const { contact, social } = useSiteData();

  const entries = [
    contact?.email ? { icon: Mail, value: contact.email, href: `mailto:${contact.email}` } : null,
    contact?.phone ? { icon: Phone, value: contact.phone, href: `tel:${contact.phone.replace(/\s/g, '')}` } : null,
    contact?.address ? { icon: MapPin, value: contact.address, href: null } : null,
    contact?.hours ? { icon: Clock3, value: contact.hours, href: null } : null,
  ].filter((entry) => entry !== null);

  // Ni contact ni réseau social ne sont inventés : le bloc disparaît tant que la
  // configuration de campagne / le CMS ne fournit pas ces valeurs.
  if (entries.length === 0 && !social?.length) return null;

  return (
    <div className="mt-6">
      <h3 className="text-[12px] font-extrabold uppercase tracking-[.16em] text-white">{t.footer.contact}</h3>
      <ul className="mt-3 flex flex-col gap-2.5">
        {entries.map(({ icon: Icon, value, href }) => (
          <li key={value} className="flex items-start gap-2.5 text-sm text-white/75">
            <Icon size={16} className="mt-0.5 shrink-0 text-gold-500" />
            {href ? (
              <a className="footer-link focus-ring rounded-sm hover:text-white" href={href}>{value}</a>
            ) : (
              <span>{value}</span>
            )}
          </li>
        ))}
      </ul>
      {social?.length ? (
        <ul className="mt-4 flex flex-wrap gap-4">
          {social.map((item) => (
            <li key={item.url}>
              <a
                className="footer-link focus-ring rounded-sm text-sm text-white/75 hover:text-white"
                href={item.url}
                rel="noopener noreferrer"
                target="_blank"
              >
                {item.label}
              </a>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}

function PublicFooter() {
  const mentionsDisponibles = destinationsExistantes(legalLinks);
  const t = useI18n();
  const partnersHeadingId = useId();

  return (
    <footer className="footer-shell">
      <div className="mx-auto max-w-[1500px] px-6 lg:px-12 xl:px-16">
        <Reveal className="flex flex-col gap-5 border-b border-white/10 py-9 sm:flex-row sm:items-center sm:justify-between lg:py-11">
          <div>
            <p className="text-xl font-extrabold leading-tight tracking-tight text-white sm:text-2xl">{t.footer.ctaTitle}</p>
            <p className="mt-2 text-sm text-white/70">{t.footer.ctaText}</p>
          </div>
          <ApplyCta />
        </Reveal>

        <div className="grid gap-x-10 gap-y-6 py-10 md:grid-cols-2 md:gap-y-10 lg:grid-cols-[1.7fr_1.2fr] lg:py-14">
          <Reveal>
            {/* Comme dans l'en-tete : le logo ramene a l'accueil public.
                `aria-label` nomme la destination — le texte alternatif de
                l'image decrit la marque, pas ou le lien mene. */}
            <Link href="/" className="focus-ring inline-flex rounded-2xl bg-white p-3" aria-label="Retour à l'accueil BIRNIN GOBE">
              <BrandLogo size="footer" />
            </Link>
            <p className="mt-5 max-w-md text-sm leading-6 text-white/75">{t.footer.about}</p>
            <ContactBlock />
          </Reveal>

          <Reveal delay={200}>
            <h3 className="text-[12px] font-extrabold uppercase tracking-[.16em] text-white">{t.footer.help}</h3>
            <p className="mt-4 text-sm leading-6 text-white/75">
              {t.footer.helpText}{' '}
              <a href={`tel:${t.footer.helpPhone.replace(/\s/g, '')}`} className="focus-ring rounded-sm font-bold text-white hover:text-gold-500">
                {t.footer.helpPhone}
              </a>
            </p>
            <p className="mt-3 text-sm leading-6 text-white/75">
              {t.footer.helpEmailLabel}{' '}
              <a href={`mailto:${t.footer.helpEmail}`} className="focus-ring rounded-sm font-bold text-white hover:text-gold-500">
                {t.footer.helpEmail}
              </a>
            </p>
          </Reveal>
        </div>

        <Reveal className="border-t border-white/10 py-9">
          <h2 id={partnersHeadingId} className="text-[12px] font-extrabold uppercase tracking-[.16em] text-white/70">
            {t.footer.institutionalPartners}
          </h2>
          <p className="mt-2 max-w-2xl text-sm text-white/70">{t.footer.partnersNote}</p>
          <ul aria-labelledby={partnersHeadingId} className="mt-5 flex flex-wrap items-center gap-4">
            {institutionalPartners.map((partner) => (
              <li
                key={partner.name}
                className="flex min-h-[96px] min-w-[124px] items-center justify-center rounded-2xl bg-white px-5 py-4 sm:min-h-[112px] sm:min-w-[164px] sm:px-7"
              >
                <img
                  src={partner.src}
                  alt={partner.name}
                  width={partner.width}
                  height={partner.height}
                  loading="lazy"
                  decoding="async"
                  className="h-14 w-auto max-w-[168px] object-contain sm:h-[76px] sm:max-w-[190px]"
                />
              </li>
            ))}
          </ul>
        </Reveal>

        <div className="flex flex-col gap-4 border-t border-white/10 py-7 lg:flex-row lg:items-center lg:justify-between">
          <p className="text-xs text-white/65">
            © {currentYear()} BIRNI’NGOBE. {t.footer.rights}
          </p>
          {/* Bloc de droite : les mentions légales quand elles existeront, puis
              la mention de réalisation, qui ferme la ligne.

              Seules les destinations qui existent sont annoncées. Les quatre
              mentions du bas de page n'ont pas encore de page derrière : elles
              portaient un marqueur « Bientôt », qui donnait au pied de page
              l'air d'un chantier. Un pied de page ne promet rien ; il mène
              quelque part, ou il se tait. Le jour où l'une de ces pages
              existera, lui donner un `href` dans `config/site.ts` la fera
              réapparaître ici sans toucher à ce composant. */}
          <div className="flex flex-wrap items-center gap-x-5 gap-y-2 lg:justify-end">
            {mentionsDisponibles.length ? (
              <ul className="flex flex-wrap items-center gap-x-5 gap-y-2">
                {mentionsDisponibles.map((link) => (
                  <li key={link.key}>
                    <FooterLink link={link} tone="inline" />
                  </li>
                ))}
              </ul>
            ) : null}
            <p className="text-xs text-white/50">{t.footer.credits}</p>
          </div>
        </div>
      </div>
    </footer>
  );
}

/**
 * Espaces de travail : footer dans le flux du document, sans logos partenaires ni
 * contenu marketing, limité aux destinations réellement disponibles.
 */
function CompactFooter() {
  const t = useI18n();
  const label = useLinkLabel();
  const links = destinationsExistantes([publicSiteLink, supportLink, ...legalLinks]);
  const linkClass = 'focus-ring rounded-sm text-xs font-semibold text-slate-500 transition-colors hover:text-brand-800';

  return (
    <footer className="border-t border-slate-200 bg-white">
      <div className="mx-auto flex max-w-[1500px] flex-col gap-2.5 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-8">
        <p className="text-xs text-slate-500">
          © {currentYear()} BIRNI’NGOBE — {t.footer.brandTagline}. {t.footer.rights}
        </p>
        {/* Bloc de droite : les destinations disponibles, puis la mention de
            réalisation, qui ferme la ligne comme sur le pied public. */}
        <div className="flex flex-wrap items-center gap-x-5 gap-y-2 sm:justify-end">
          {links.length ? (
            <ul className="flex flex-wrap items-center gap-x-5 gap-y-2">
              {links.map((link) => (
                <li key={link.key}>
                  {isAnchorHref(link.href) ? (
                    <a href={link.href} className={linkClass}>{label(link)}</a>
                  ) : (
                    <Link href={link.href} className={linkClass}>{label(link)}</Link>
                  )}
                </li>
              ))}
            </ul>
          ) : null}
          <p className="text-xs text-slate-400">{t.footer.credits}</p>
        </div>
      </div>
    </footer>
  );
}
