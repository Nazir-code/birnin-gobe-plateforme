import { useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Building2, CalendarDays, CheckCircle2, FileText, Lock, Map, MapPinned, PlayCircle, UserRound } from 'lucide-react';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { Button, Card, SectionTitle } from '@/Components/Ui';
import { AnimatedCounter } from '@/Components/AnimatedCounter';
import { Reveal } from '@/Components/Reveal';
import { HeroCarousel, type HeroImage } from '@/Components/HeroCarousel';

const countdownLabels: Record<string, string> = { days: 'jours', hours: 'heures', minutes: 'minutes', seconds: 'secondes' };

/**
 * Icones des thematiques, dans l'ordre servi par le serveur.
 *
 * Elles restent ici parce qu'elles sont de la mise en forme, pas du contenu :
 * le CMS servira des titres et des textes, pas des composants React. La cle du
 * serveur choisit l'icone, donc l'ordre peut changer sans casser l'association.
 */
const iconesThematiques: Record<string, typeof Building2> = {
  'gestion-urbaine': Building2,
  foncier: Map,
  'etat-civil': FileText,
  cartographie: MapPinned,
};

type Thematique = { key: string; title: string; problems: string; results: string };
type Critere = { key: string; title: string; question: string };

/** Les cinq etapes du parcours, de la candidature aux resultats. */
const processus = ['Candidature', 'Admissibilité', 'Présélection', 'Jury final', 'Publication'] as const;


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
  opensAt: string | null;
  closesAt: string | null;
  timezone: string;
};

/** Comptes reels, servis par HomeController. Aucun n'est calcule ici. */
type Chiffres = {
  candidates: number;
  draftApplications: number;
  submittedApplications: number;
  themes: number;
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

export default function Home({ campaign, stats, themes, criteria }: {
  campaign: Campagne | null;
  stats: Chiffres;
  themes: Thematique[];
  criteria: Critere[];
}) {
  const restant = useCompteARebours(campaign?.closesAt ?? null);
  const depotOuvert = campaign !== null && restant !== null;

  /**
   * Les chiffres cles, tels que la base les compte.
   *
   * Aucun n'est arrondi, aucun n'est embelli, et zero s'affiche zero. « 5 000+
   * jeunes impactes » et ses voisins ne mesuraient rien ; ceux-ci viennent de
   * trois `count()` en PostgreSQL.
   */
  const chiffres = [
    { valeur: stats.candidates, label: 'Candidats inscrits', aide: 'Comptes candidats créés' },
    { valeur: stats.draftApplications, label: 'Candidatures en cours', aide: 'Dossiers commencés, non déposés' },
    { valeur: stats.submittedApplications, label: 'Candidatures soumises', aide: 'Dossiers officiellement déposés' },
    { valeur: stats.themes, label: 'Thématiques', aide: 'Domaines ouverts au concours' },
  ];

  return (
    <PublicLayout>
      <Head title="BIRNIN GOBE — Compétition nationale d'innovation" />

      {/* ——— Hero ——— */}
      <section className="bg-pattern overflow-hidden border-b border-slate-100">
        <div className="mx-auto grid max-w-[1500px] lg:grid-cols-[0.88fr_1.12fr]">
          <div className="relative z-10 flex min-h-[470px] flex-col justify-center px-6 py-12 lg:px-12 xl:px-16">
            <div className="kicker">Innover aujourd'hui, transformer demain</div>
            <h1 className="mt-5 max-w-3xl text-[2.7rem] font-black leading-[1.02] tracking-[-.045em] text-brand-950 sm:text-6xl xl:text-[4.35rem]">
              La plateforme nationale qui propulse les jeunes <span className="text-gold-600">innovateurs du Niger.</span>
            </h1>
            <p className="mt-6 max-w-2xl text-base leading-7 text-slate-600 sm:text-lg">
              BIRNIN GOBE accompagne les jeunes talents et leurs idées innovantes pour bâtir un Niger créatif,
              inclusif et durable.
            </p>
            <div className="mt-8 flex flex-wrap items-center gap-3" id="candidater">
              {/* Le bouton ne promet une candidature que s'il y en a une à
                  déposer. Hors période, il est désactivé et la raison est dite —
                  un lien qui mène à un formulaire fermé est pire qu'un bouton
                  éteint. */}
              {depotOuvert ? (
                <Link
                  href="/register"
                  className="focus-ring press-feedback inline-flex min-h-11 min-w-56 items-center justify-center gap-2 rounded-xl bg-gold-500 px-5 text-sm font-bold text-ink-950 transition-colors hover:bg-gold-600"
                  data-testid="cta-candidater"
                >
                  Commencer ma candidature <ArrowRight size={17} />
                </Link>
              ) : (
                <Button className="min-w-56" disabled data-testid="cta-candidater-ferme">Candidatures fermées</Button>
              )}
              {/* Une ancre native, pas un bouton décoratif : elle défile vers la
                  section, se met en signet, s'ouvre dans un onglet et reste
                  atteignable au clavier. Le défilement doux et le dégagement de
                  l'en-tête collant sont dans app.css. */}
              <a
                href="#processus"
                className="focus-ring press-feedback inline-flex min-h-11 min-w-48 items-center justify-center gap-2 rounded-xl border border-brand-900/35 bg-white px-5 text-sm font-bold text-brand-900 transition-colors hover:bg-slate-50"
                data-testid="lien-processus"
              >
                <PlayCircle size={17} /> Découvrir le processus
              </a>
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

        {/* ——— Clôture des candidatures ——— */}
        <div className="mx-auto max-w-[1500px] px-6 pb-8 lg:px-12 xl:px-16">
          <Reveal className="hover-lift"><Card className="flex flex-wrap items-center justify-between gap-5 px-5 py-5">
            <div className="flex items-start gap-4">
              {/* Un candidat, puis un cadenas : à la date annoncée, le dépôt se
                  ferme. L'image dit d'un coup d'œil ce que la date seule laisse
                  déduire. Décorative — le texte à côté porte le sens. */}
              <div className="flex shrink-0 items-center gap-1.5" aria-hidden>
                <div className="grid h-12 w-12 place-items-center rounded-xl bg-brand-50 text-brand-900"><UserRound size={23} /></div>
                <span className="text-slate-300">+</span>
                <div className="grid h-12 w-12 place-items-center rounded-xl bg-amber-50 text-amber-700"><Lock size={21} /></div>
              </div>
              <div>
                <div className="text-base font-black text-brand-950">Clôture des candidatures</div>
                <div className="mt-1 text-sm text-slate-600" data-testid="cloture">
                  {campaign?.closesAt
                    ? `${clotureLisible(campaign.closesAt, campaign.timezone)} — ${campaign.name}`
                    : 'Les candidatures ne sont pas ouvertes actuellement.'}
                </div>
                {/* Le libellé suit ce que le serveur fait, pas l'inverse.
                    `SubmissionReadiness` ferme bien le dépôt passé `closes_at` —
                    mais `ApplicationPolicy::update()` n'ayant aucune borne de
                    calendrier, un brouillon reste modifiable après la clôture.
                    Annoncer un verrouillage des dossiers serait donc faux ;
                    annoncer la fermeture du dépôt est exact. Les deux moitiés du
                    contrat sont éprouvées par `ClotureEtVerrouillageTest`. */}
                <p className="mt-1 text-xs leading-5 text-slate-500">
                  Après cette date, aucune candidature ne pourra être soumise.
                </p>
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

      {/* ——— Chiffres clés ——— */}
      <section className="mx-auto max-w-[1500px] px-6 py-10 lg:px-12 xl:px-16" id="chiffres">
        <Reveal>
          <div className="grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-slate-200 bg-slate-200 sm:grid-cols-4" data-testid="chiffres-cles">
            {chiffres.map(({ valeur, label, aide }) => (
              <div key={label} className="hover-lift bg-white px-4 py-5 text-center">
                <div className="text-3xl font-black text-brand-800"><AnimatedCounter value={String(valeur)} /></div>
                <div className="mt-1 text-[12px] font-bold text-slate-700">{label}</div>
                <div className="mt-0.5 text-[11px] leading-4 text-slate-400">{aide}</div>
              </div>
            ))}
          </div>
        </Reveal>
      </section>

      {/* ——— À propos ——— */}
      <section className="border-y border-slate-100 bg-slate-50/60" id="à-propos">
        <div className="mx-auto max-w-[1500px] px-6 py-12 lg:px-12 xl:px-16">
          <div className="grid gap-8 lg:grid-cols-[1fr_1fr]">
            <div>
              <SectionTitle eyebrow="À propos" title="Un concours national pour des services publics plus efficaces" />
              <p className="text-base leading-7 text-slate-600">
                BIRNIN GOBE réunit les jeunes talents du Niger autour de défis concrets rencontrés par les
                collectivités et les administrations. Les candidatures sont déposées, examinées puis évaluées
                sur cette plateforme, selon un calendrier et des critères publiés à l’avance.
              </p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              {[
                ['Un dossier en 9 étapes', 'Éligibilité, profil, équipe, défi, solution, impact, plan, pièces, relecture.'],
                ['Un dépôt officiel', 'Numéro de candidature et date de dépôt, conservés et opposables.'],
                ['Une évaluation annoncée', 'Huit critères connus dès le dépôt, publiés plus bas sur cette page.'],
                ['Des données protégées', 'Accès selon le rôle, journalisation des décisions, hébergement au Niger.'],
              ].map(([titre, texte]) => (
                <Card key={titre} className="p-4">
                  <div className="text-sm font-extrabold text-brand-950">{titre}</div>
                  <p className="mt-1 text-xs leading-5 text-slate-500">{texte}</p>
                </Card>
              ))}
            </div>
          </div>
        </div>
      </section>

      {/* ——— Thématiques officielles ——— */}
      <section className="mx-auto max-w-[1500px] px-6 py-12 lg:px-12 xl:px-16" id="thématiques">
        <SectionTitle eyebrow="Nos thématiques" title="Quatre domaines, des défis concrets" />
        <div className="grid gap-4 md:grid-cols-2">
          {themes.map(({ key, title, problems, results }, index) => {
            const Icone = iconesThematiques[key] ?? Building2;

            return (
              <Reveal key={key} delay={index * 60}>
                <Card className="hover-lift h-full p-5 sm:p-6" data-testid={`thematique-${index + 1}`}>
                  <div className="flex items-start gap-3">
                    <div className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand-900 text-white"><Icone size={21} /></div>
                    <h3 className="mt-1 text-base font-extrabold leading-6 text-brand-950">{title}</h3>
                  </div>
                  <dl className="mt-4 space-y-3">
                    <div>
                      <dt className="text-[11px] font-bold uppercase tracking-wide text-amber-700">Problèmes prioritaires</dt>
                      <dd className="mt-1 text-sm leading-6 text-slate-600">{problems}</dd>
                    </div>
                    <div>
                      <dt className="text-[11px] font-bold uppercase tracking-wide text-brand-700">Résultats attendus</dt>
                      <dd className="mt-1 text-sm leading-6 text-slate-600">{results}</dd>
                    </div>
                  </dl>
                </Card>
              </Reveal>
            );
          })}
        </div>
      </section>

      {/* ——— Critères d'évaluation ——— */}
      <section className="border-y border-slate-100 bg-slate-50/60" id="critères">
        <div className="mx-auto max-w-[1500px] px-6 py-12 lg:px-12 xl:px-16">
          <SectionTitle eyebrow="Comment les dossiers sont jugés" title="Critères d’évaluation" />
          <p className="-mt-2 mb-5 max-w-3xl text-sm leading-6 text-slate-600">
            Ces huit critères servent à <strong>évaluer</strong> les dossiers recevables. Ils ne décident pas
            qui a le droit de candidater : cela relève de l’éligibilité, présentée juste en dessous.
          </p>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {criteria.map(({ key, title, question }, index) => (
              <Reveal key={key} delay={index * 40}>
                <Card className="hover-lift h-full p-4" data-testid={`critere-${index + 1}`}>
                  <div className="flex items-center gap-2.5">
                    <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full border border-brand-800 text-xs font-black text-brand-800">
                      {index + 1}
                    </span>
                    <h3 className="text-sm font-extrabold text-brand-950">{title}</h3>
                  </div>
                  <p className="mt-2 text-xs leading-5 text-slate-600">{question}</p>
                </Card>
              </Reveal>
            ))}
          </div>
        </div>
      </section>

      {/* ——— Éligibilité et calendrier ——— */}
      <section className="mx-auto max-w-[1500px] px-6 py-12 lg:px-12 xl:px-16">
        <div className="grid gap-5 lg:grid-cols-2">
          <Reveal id="éligibilité"><Card className="h-full p-6">
            <SectionTitle eyebrow="Qui peut candidater ?" title="L'éligibilité en un coup d'œil" />
            <ul className="space-y-3 text-sm leading-6 text-slate-700">
              {[
                'Jeunes répondant aux règles de la campagne',
                'Porteurs d’idées ou de projets innovants',
                'Candidature individuelle, équipe ou startup',
              ].map((item) => (
                <li key={item} className="flex items-start gap-3">
                  <CheckCircle2 className="mt-0.5 shrink-0 text-brand-700" size={18} />{item}
                </li>
              ))}
            </ul>
            <p className="mt-4 text-xs leading-5 text-slate-500">
              Les conditions exactes — âge, nationalité, zone d’intervention, effectif — sont fixées par
              l’édition en cours et vérifiées dès la première étape du dossier.
            </p>
          </Card></Reveal>

          <Reveal id="calendrier" delay={80}><Card className="h-full p-6">
            <SectionTitle eyebrow="Calendrier" title="Les dates de l'édition" />
            {campaign === null ? (
              <p className="text-sm leading-6 text-slate-600" data-testid="calendrier-vide">
                Aucune édition n’est ouverte actuellement. Les dates seront publiées ici dès l’ouverture.
              </p>
            ) : (
              <dl className="space-y-3">
                <div className="flex items-start gap-3">
                  <CalendarDays className="mt-0.5 shrink-0 text-brand-700" size={18} />
                  <div>
                    <dt className="text-xs font-bold uppercase tracking-wide text-slate-400">Ouverture des candidatures</dt>
                    <dd className="text-sm font-semibold text-slate-800" data-testid="ouverture">
                      {campaign.opensAt ? clotureLisible(campaign.opensAt, campaign.timezone) : 'Non communiquée'}
                    </dd>
                  </div>
                </div>
                <div className="flex items-start gap-3">
                  <Lock className="mt-0.5 shrink-0 text-amber-700" size={18} />
                  <div>
                    <dt className="text-xs font-bold uppercase tracking-wide text-slate-400">Clôture des candidatures</dt>
                    <dd className="text-sm font-semibold text-slate-800">
                      {campaign.closesAt ? clotureLisible(campaign.closesAt, campaign.timezone) : 'Non communiquée'}
                    </dd>
                  </div>
                </div>
                <p className="pt-1 text-xs leading-5 text-slate-500">
                  Édition {campaign.name} ({campaign.code}). Les dates suivantes — admissibilité, présélection,
                  jury — seront publiées ici lorsqu’elles seront arrêtées.
                </p>
              </dl>
            )}
          </Card></Reveal>
        </div>
      </section>

      {/* ——— Processus ——— */}
      <section className="border-y border-slate-100 bg-slate-50/60" id="processus">
        <div className="mx-auto max-w-[1500px] px-6 py-12 lg:px-12 xl:px-16">
          <SectionTitle eyebrow="Notre processus" title="Comment candidater" />
          <ol className="grid gap-3 sm:grid-cols-3 xl:grid-cols-5">
            {processus.map((etape, index) => (
              <li key={etape}>
                <Card className="hover-lift h-full p-4 text-center">
                  <div className="mx-auto grid h-10 w-10 place-items-center rounded-full border border-brand-800 text-sm font-black text-brand-800">
                    {index + 1}
                  </div>
                  <div className="mt-2 text-sm font-bold text-slate-700">{etape}</div>
                </Card>
              </li>
            ))}
          </ol>
          <p className="mt-5 max-w-3xl text-sm leading-6 text-slate-600">
            Vous remplissez votre dossier en neuf étapes, à votre rythme : tout est enregistré au fur et à
            mesure. À la dernière étape, vous relisez l’ensemble puis déposez. Le dépôt est définitif et vous
            recevez un numéro de candidature.
          </p>
        </div>
      </section>

      {/* ——— Appel à l'action final ——— */}
      <section className="mx-auto max-w-[1500px] px-6 py-14 lg:px-12 xl:px-16">
        <Card className="p-7 text-center sm:p-10">
          <h2 className="text-2xl font-black tracking-tight text-brand-950 sm:text-3xl">
            Prêt à présenter votre projet ?
          </h2>
          <p className="mx-auto mt-3 max-w-2xl text-sm leading-6 text-slate-600">
            Créez votre compte, puis remplissez votre dossier étape par étape. Vous pourrez le reprendre autant
            de fois que nécessaire avant de le déposer.
          </p>
          <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
            {depotOuvert ? (
              <Link
                href="/register"
                className="focus-ring press-feedback inline-flex min-h-11 min-w-56 items-center justify-center gap-2 rounded-xl bg-gold-500 px-5 text-sm font-bold text-ink-950 transition-colors hover:bg-gold-600"
                data-testid="cta-final"
              >
                Commencer ma candidature <ArrowRight size={17} />
              </Link>
            ) : (
              <Button className="min-w-56" disabled data-testid="cta-final-ferme">Candidatures fermées</Button>
            )}
            <Link
              href="/login"
              className="focus-ring press-feedback inline-flex min-h-11 min-w-44 items-center justify-center gap-2 rounded-xl border border-brand-900/35 bg-white px-5 text-sm font-bold text-brand-900 transition-colors hover:bg-slate-50"
            >
              <UserRound size={17} /> J’ai déjà un compte
            </Link>
          </div>
        </Card>
      </section>
    </PublicLayout>
  );
}
