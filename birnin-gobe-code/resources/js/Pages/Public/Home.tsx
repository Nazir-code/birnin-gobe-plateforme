import { useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, CalendarDays, CheckCircle2, GraduationCap, Leaf, Lightbulb, MapPinned, PlayCircle, UsersRound } from 'lucide-react';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { Button, Card, SectionTitle } from '@/Components/Ui';
import { AnimatedCounter } from '@/Components/AnimatedCounter';
import { Reveal } from '@/Components/Reveal';
import { HeroCarousel, type HeroImage } from '@/Components/HeroCarousel';
import { themes } from '@/data/demo';

const themeIcons = [Leaf, Lightbulb, MapPinned, GraduationCap, UsersRound];
const countdownLabels: Record<string, string> = { days: 'jours', hours: 'heures', minutes: 'minutes', seconds: 'secondes' };

/**
 * Les cinq visuels officiels de la campagne, dans leur ordre de défilement.
 *
 * Aucune image téléchargée ni générée : ce sont les cinq visuels fournis, dont
 * les masters PNG vivent dans `resources/images/hero/` (hors du dossier servi)
 * et dont `scripts/optimize-hero-images.mjs` derive le WebP servi et le JPEG de
 * repli. Les noms d'origine (« Image d'accueil 1 »…) ont été normalisés en
 * kebab-case : une URL contenant espaces et apostrophes doit être ré-encodée à
 * chaque usage et finit tôt ou tard en 404.
 *
 * `objectPosition` : les cinq photos sont en 16/9 avec les personnes dans la
 * moitié droite, alors que la zone image devient quasi carrée sous 1024 px.
 * Sans ce décalage, `object-cover` rogne la largeur et coupe les visages.
 */
const heroImages: HeroImage[] = [
  {
    webp: '/assets/hero-accueil-1.webp',
    src: '/assets/hero-accueil-1.jpg',
    alt: "Quatre jeunes innovateurs nigériens réunis autour d'un ordinateur portable et d'un prototype électronique dans un espace de travail partagé",
    objectPosition: '70% center',
  },
  {
    webp: '/assets/hero-accueil-2.webp',
    src: '/assets/hero-accueil-2.jpg',
    alt: 'Une jeune femme présente les résultats de son projet sur une tablette à son équipe pendant une réunion de travail',
    objectPosition: '72% center',
  },
  {
    webp: '/assets/hero-accueil-3.webp',
    src: '/assets/hero-accueil-3.jpg',
    alt: "Une équipe de jeunes porteurs de projet examine la maquette d'un quartier durable équipé de panneaux solaires et d'une éolienne",
    objectPosition: '72% center',
  },
  {
    webp: '/assets/hero-accueil-4.webp',
    src: '/assets/hero-accueil-4.jpg',
    alt: "Trois jeunes innovateurs assemblent un robot à roues à partir de leurs plans techniques, ordinateur portable ouvert à côté d'eux",
    objectPosition: '68% center',
  },
  {
    webp: '/assets/hero-accueil-5.webp',
    src: '/assets/hero-accueil-5.jpg',
    alt: "Un jeune homme présente une solution numérique sur tablette à des habitants d'un village nigérien équipé de panneaux solaires",
    objectPosition: '76% center',
  },
];

/** Ce que la page publique sait de l'edition en cours. Rien de plus. */
type Campagne = {
  name: string;
  code: string;
  closesAt: string | null;
  timezone: string;
};

type Restant = { days: number; hours: number; minutes: number; seconds: number };

function restantDepuis(closesAt: string): Restant | null {
  const ecart = new Date(closesAt).getTime() - Date.now();

  if (!Number.isFinite(ecart) || ecart <= 0) return null;

  const secondes = Math.floor(ecart / 1000);

  return {
    days: Math.floor(secondes / 86400),
    hours: Math.floor((secondes % 86400) / 3600),
    minutes: Math.floor((secondes % 3600) / 60),
    seconds: secondes % 60,
  };
}

/**
 * Le temps qu'il reste, recalcule chaque seconde depuis la vraie cloture.
 *
 * Rend `null` quand il n'y a pas de date, ou quand elle est passee pendant que
 * la page etait ouverte : l'ecran doit alors dire que c'est fini, pas afficher
 * un decompte negatif.
 */
function useCompteARebours(closesAt: string | null): Restant | null {
  const [restant, setRestant] = useState<Restant | null>(() => (closesAt ? restantDepuis(closesAt) : null));

  useEffect(() => {
    if (!closesAt) {
      setRestant(null);
      return;
    }

    setRestant(restantDepuis(closesAt));
    const minuterie = window.setInterval(() => setRestant(restantDepuis(closesAt)), 1000);

    return () => window.clearInterval(minuterie);
  }, [closesAt]);

  return restant;
}

/** La cloture telle que le concours l'annonce : dans le fuseau de l'edition. */
function clotureLisible(closesAt: string, timezone: string): string {
  const date = new Intl.DateTimeFormat('fr-FR', {
    dateStyle: 'long',
    timeStyle: 'short',
    timeZone: timezone,
  }).format(new Date(closesAt));

  return `${date} (${timezone})`;
}

export default function Home({ campaign }: { campaign: Campagne | null }) {
  const restant = useCompteARebours(campaign?.closesAt ?? null);
  const depotOuvert = campaign !== null && restant !== null;

  return (
    <PublicLayout>
      <Head title="BIRNIN GOBE — Compétition nationale d'innovation" />
      <section className="bg-pattern overflow-hidden border-b border-slate-100">
        <div className="mx-auto grid max-w-[1500px] lg:grid-cols-[0.88fr_1.12fr]">
          <div className="relative z-10 flex min-h-[470px] flex-col justify-center px-6 py-12 lg:px-12 xl:px-16">
            <div className="kicker">Innover aujourd'hui, transformer demain</div>
            <h1 className="mt-5 max-w-3xl text-[2.7rem] font-black leading-[1.02] tracking-[-.045em] text-brand-950 sm:text-6xl xl:text-[4.35rem]">
              La plateforme nationale qui propulse les jeunes <span className="text-gold-600">innovateurs du Niger.</span>
            </h1>
            <p className="mt-6 max-w-2xl text-base leading-7 text-slate-600 sm:text-lg">BIRNIN GOBE accompagne les jeunes talents et leurs idées innovantes pour bâtir un Niger créatif, inclusif et durable.</p>
            <div className="mt-8 flex flex-wrap items-center gap-3" id="candidater">
              {/* Le bouton ne promet une candidature que s'il y en a une à
                  déposer. Hors période, il est désactivé et la raison est dite —
                  un lien qui mène à un formulaire fermé est pire qu'un bouton
                  éteint. */}
              {depotOuvert ? (
                <Link href="/register" className="focus-ring press-feedback inline-flex min-h-11 min-w-44 items-center justify-center gap-2 rounded-xl bg-gold-500 px-5 text-sm font-bold text-ink-950 transition-colors hover:bg-gold-600" data-testid="cta-candidater">
                  Candidater <ArrowRight size={17} />
                </Link>
              ) : (
                <Button className="min-w-44" disabled data-testid="cta-candidater-ferme">Candidatures fermées</Button>
              )}
              <Button variant="ghost" className="min-w-48"><PlayCircle size={17} /> Découvrir le processus</Button>
            </div>
            {depotOuvert ? null : (
              <p className="mt-3 text-sm font-semibold text-slate-500" data-testid="aucune-campagne">
                Les candidatures ne sont pas ouvertes actuellement.
              </p>
            )}
          </div>
          <div className="relative min-h-[380px] overflow-hidden lg:min-h-[470px]">
            <HeroCarousel images={heroImages} className="absolute inset-0 h-full w-full" />
            <div className="pointer-events-none absolute inset-y-0 left-0 z-10 w-28 bg-gradient-to-r from-[#fbf8f1] to-transparent" />
          </div>
        </div>
        <div className="mx-auto grid max-w-[1500px] gap-4 px-6 pb-8 lg:grid-cols-[1.05fr_.95fr] lg:px-12 xl:px-16">
          {/* Calendrier réel. Le libellé vient de la campagne, le décompte de
              sa date de clôture — plus aucune valeur figée. */}
          <Reveal id="calendrier" className="hover-lift"><Card className="flex flex-wrap items-center justify-between gap-4 px-5 py-4">
            <div className="flex items-center gap-3">
              <div className="grid h-12 w-12 place-items-center rounded-xl bg-brand-50 text-brand-900"><CalendarDays size={23} /></div>
              <div>
                <div className="text-sm font-bold text-slate-700">
                  {campaign === null ? 'Aucune édition ouverte' : `Clôture des candidatures — ${campaign.name}`}
                </div>
                <div className="mt-0.5 text-xs text-slate-400" data-testid="cloture">
                  {campaign?.closesAt
                    ? clotureLisible(campaign.closesAt, campaign.timezone)
                    : 'Les candidatures ne sont pas ouvertes actuellement.'}
                </div>
              </div>
            </div>
            {restant === null ? null : (
              <div className="grid grid-cols-4 divide-x divide-slate-200" data-testid="compte-a-rebours">
                {(['days', 'hours', 'minutes', 'seconds'] as const).map((cle) => (
                  <div key={cle} className="px-3 text-center sm:px-5">
                    <div className="text-2xl font-black text-brand-800"><AnimatedCounter value={String(restant[cle])} durationMs={800} /></div>
                    <div className="mt-1 text-[9px] font-bold uppercase tracking-wide text-slate-500">{countdownLabels[cle]}</div>
                  </div>
                ))}
              </div>
            )}
          </Card></Reveal>
        </div>
      </section>

      <section className="mx-auto max-w-[1500px] px-6 py-12 lg:px-12 xl:px-16" id="thématiques">
        <div className="grid gap-8 xl:grid-cols-[1.25fr_.75fr]">
          <div>
            <SectionTitle eyebrow="Nos thématiques" title="Des défis concrets, des solutions utiles" />
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
              {themes.map(([title, text], index) => { const Icon = themeIcons[index]; return (
                <Reveal key={title} delay={index * 60}>
                  <Card className="hover-lift min-h-[205px] p-5 text-center">
                    <div className="mx-auto grid h-12 w-12 place-items-center rounded-full bg-brand-900 text-white"><Icon size={22} /></div>
                    <h3 className="mt-4 text-sm font-extrabold text-brand-950">{title}</h3>
                    <p className="mt-2 text-xs leading-5 text-slate-500">{text}</p>
                  </Card>
                </Reveal>
              ); })}
            </div>
            <div className="mt-5 text-center"><Button variant="ghost">Voir toutes les thématiques <ArrowRight size={16} /></Button></div>
          </div>
          <div className="grid gap-4">
            <Reveal id="éligibilité" delay={120}><Card className="p-6">
              <SectionTitle eyebrow="Qui peut candidater ?" title="L'éligibilité en un coup d'œil" />
              <ul className="space-y-3 text-sm text-slate-700">
                {['Jeunes répondant aux règles de la campagne', 'Porteurs d’idées ou de projets innovants', 'Candidature individuelle, équipe ou startup'].map((item) => <li key={item} className="flex items-start gap-3"><CheckCircle2 className="mt-0.5 text-brand-700" size={18} />{item}</li>)}
              </ul>
            </Card></Reveal>
            <Reveal id="processus" delay={180}><Card className="p-6">
              <SectionTitle eyebrow="Notre processus" title="De la candidature aux résultats" />
              <div className="grid grid-cols-5 gap-2">
                {['Candidature', 'Admissibilité', 'Présélection', 'Jury final', 'Publication'].map((step, i) => <div key={step} className="text-center"><div className="mx-auto grid h-9 w-9 place-items-center rounded-full border border-brand-800 text-xs font-black text-brand-800">{i + 1}</div><div className="mt-2 text-[10px] font-semibold leading-tight text-slate-600">{step}</div></div>)}
              </div>
            </Card></Reveal>
          </div>
        </div>
      </section>
    </PublicLayout>
  );
}
