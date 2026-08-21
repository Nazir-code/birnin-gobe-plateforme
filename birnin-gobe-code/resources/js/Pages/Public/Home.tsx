import { Head } from '@inertiajs/react';
import { ArrowRight, CalendarDays, CheckCircle2, GraduationCap, Handshake, Leaf, Lightbulb, MapPinned, PlayCircle, UsersRound } from 'lucide-react';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { Button, Card, SectionTitle } from '@/Components/Ui';
import { AnimatedCounter } from '@/Components/AnimatedCounter';
import { Reveal } from '@/Components/Reveal';
import { HeroCarousel, type HeroImage } from '@/Components/HeroCarousel';
import { demoCampaign, themes } from '@/data/demo';

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

export default function Home() {
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
            <div className="mt-8 flex flex-wrap gap-3" id="candidater">
              <Button className="min-w-44">Candidater <ArrowRight size={17} /></Button>
              <Button variant="ghost" className="min-w-48"><PlayCircle size={17} /> Découvrir le processus</Button>
            </div>
          </div>
          <div className="relative min-h-[380px] overflow-hidden lg:min-h-[470px]">
            <HeroCarousel images={heroImages} className="absolute inset-0 h-full w-full" />
            <div className="pointer-events-none absolute inset-y-0 left-0 z-10 w-28 bg-gradient-to-r from-[#fbf8f1] to-transparent" />
          </div>
        </div>
        <div className="mx-auto grid max-w-[1500px] gap-4 px-6 pb-8 lg:grid-cols-[1.05fr_.95fr] lg:px-12 xl:px-16">
          <Reveal id="calendrier" className="hover-lift"><Card className="flex flex-wrap items-center justify-between gap-4 px-5 py-4">
            <div className="flex items-center gap-3"><div className="grid h-12 w-12 place-items-center rounded-xl bg-brand-50 text-brand-900"><CalendarDays size={23} /></div><div><div className="text-sm font-bold text-slate-700">Prochaine clôture des candidatures</div><div className="mt-0.5 text-xs text-slate-400">{demoCampaign.deadlineLabel}</div></div></div>
            <div className="grid grid-cols-4 divide-x divide-slate-200">
              {Object.entries(demoCampaign.countdown).map(([key, value]) => <div key={key} className="px-3 text-center sm:px-5"><div className="text-2xl font-black text-brand-800"><AnimatedCounter value={String(value)} durationMs={800} /></div><div className="mt-1 text-[9px] font-bold uppercase tracking-wide text-slate-500">{countdownLabels[key]}</div></div>)}
            </div>
          </Card></Reveal>
          <Reveal delay={80} className="grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-slate-200 bg-slate-200 sm:grid-cols-4">
            {demoCampaign.stats.map(([value, label], index) => <div key={label} className="hover-lift bg-white px-4 py-4 text-center"><div className="text-2xl font-black text-brand-800"><AnimatedCounter value={value} /></div><div className="mt-1 text-[11px] font-semibold text-slate-500">{label}</div>{index === 3 ? <Handshake className="mx-auto mt-2 text-brand-700" size={18} /> : null}</div>)}
          </Reveal>
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
