import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowRight, CalendarDays, FileText, FilePlus2, Mail, UploadCloud } from 'lucide-react';
import { CandidateLayout } from '@/Layouts/CandidateLayout';
import { useAuthUser } from '@/hooks/useAuth';
import { Button, Card, Pill, SectionTitle } from '@/Components/Ui';
import { ProgressSteps, type Step } from '@/Components/ProgressSteps';
import { AnimatedCounter } from '@/Components/AnimatedCounter';
import { Reveal } from '@/Components/Reveal';
import { useReveal } from '@/hooks/useReveal';

/**
 * Messages et documents restent des donnees de demonstration : les modules
 * Notification et Storage ne sont pas developpes. Ils sont isoles ici, hors des
 * informations de candidature, qui viennent desormais toutes de PostgreSQL.
 */
const messages = [
  ['Équipe BIRNIN GOBE', 'Webinaire d’information : préparez votre candidature comme un pro !', '24 mai 2026'],
  ['Équipe BIRNIN GOBE', 'Rappel : date limite de soumission', '20 mai 2026'],
  ['Mahamadou A.', 'Re : question sur le budget du projet', '18 mai 2026'],
];
const docs = [
  ['Présentation du projet', 'PDF • 1,2 Mo', 'Ajouté'],
  ['Budget prévisionnel', 'XLSX • 240 Ko', 'Ajouté'],
  ['Plan d’action détaillé', 'XLSX • 300 Ko', 'Ajouté'],
  ['Lettre de motivation', 'PDF • 450 Ko', 'À ajouter'],
];

type Props = {
  campaign: { name: string; code: string; closesAt: string | null; daysLeft: number | null } | null;
  application: {
    id: number;
    status: string;
    statusLabel: string;
    completionPercent: number;
    currentStep: { key: string; label: string; position: number } | null;
    updatedAt: string | null;
    submissionNumber: string | null;
    submittedAt: string | null;
    /** Null des que le dossier est depose : il ne se reprend plus. */
    continueUrl: string | null;
    reviewUrl: string;
    submittedUrl: string | null;
  } | null;
  steps: { key: string; label: string; position: number; state: Step['state']; implemented: boolean; onOpenPath: boolean }[];
  startUrl: string;
};

const dateLongue = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
const dateCourte = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });

export default function CandidateDashboard({ campaign, application, steps, startUrl }: Props) {
  const user = useAuthUser();
  const { ref: statsRef, visible: statsVisible } = useReveal<HTMLElement>();
  const [demarrage, setDemarrage] = useState(false);

  /**
   * « Commencer ma candidature » ecrit reellement en base.
   *
   * Le bouton est verrouille pendant la requete : le serveur est idempotent
   * — il renverrait le meme brouillon — mais rien ne justifie d'envoyer deux
   * fois la meme ecriture depuis un reseau souvent lent.
   */
  const commencer = () => {
    if (demarrage) return;
    setDemarrage(true);
    router.post(startUrl, {}, { onFinish: () => setDemarrage(false) });
  };

  const progression = application?.completionPercent ?? 0;

  /**
   * Etapes remplies qui ne comptent pas encore dans la progression : elles ont
   * ete developpees avant une etape qui les precede dans l'ordre du concours.
   * Sans cette explication, le candidat verrait son pourcentage rester immobile
   * apres avoir rempli une etape entiere.
   */
  const horsParcours = steps.filter((etape) => etape.state === 'done' && !etape.onOpenPath);

  return (
    <CandidateLayout active="Tableau de bord">
      <Head title="Tableau de bord candidat — BIRNIN GOBE" />
      <div className="mx-auto max-w-[1500px] p-5 sm:p-8">
        <div className="mb-6"><h1 className="text-3xl font-black tracking-tight text-slate-950">Bonjour, {user?.name.split(' ')[0] ?? ''} 👋</h1><p className="mt-1 text-sm text-slate-500">Bienvenue dans votre espace candidat BIRNIN GOBE.</p></div>

        {application === null ? (
          <Reveal><Card className="p-6 sm:p-8" data-testid="aucune-candidature">
            <div className="flex flex-col gap-5 md:flex-row md:items-center">
              <div className="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-800"><FilePlus2 /></div>
              <div className="min-w-0 flex-1">
                <h2 className="text-xl font-extrabold tracking-tight text-ink-950">Vous n’avez pas encore de candidature</h2>
                <p className="mt-1 text-sm leading-6 text-slate-600">
                  {campaign
                    ? `Ouvrez votre dossier pour ${campaign.name}. Il est enregistré au fur et à mesure : vous pouvez le reprendre quand vous voulez.`
                    : 'Les candidatures ne sont pas ouvertes pour le moment. Revenez à l’ouverture de la prochaine campagne.'}
                </p>
              </div>
              {campaign ? (
                <Button onClick={commencer} disabled={demarrage} className="min-w-56">
                  {demarrage ? 'Création…' : 'Commencer ma candidature'} <ArrowRight size={16} />
                </Button>
              ) : null}
            </div>
          </Card></Reveal>
        ) : (
          <section ref={statsRef} className={`surface-card reveal ${statsVisible ? 'is-visible' : ''} grid overflow-hidden md:grid-cols-3`} data-testid="candidature-existante">
            <div className="p-6 md:border-r md:border-slate-200">
              <div className="text-sm font-extrabold">Statut de ma candidature</div>
              <div className="mt-4 flex items-center gap-4">
                <div className="grid h-14 w-14 place-items-center rounded-full bg-amber-100 text-amber-600"><FileText /></div>
                <div>
                  <div className="text-xs text-slate-500">Statut actuel</div>
                  <div className="text-2xl font-black" data-testid="statut-candidature">{application.statusLabel}</div>
                  {/* Un dossier depose montre sa reference, pas sa derniere
                      modification : c'est le numero que le candidat cherche. */}
                  {application.submittedAt ? (
                    <div className="text-xs text-slate-500" data-testid="depot-resume">
                      <span className="font-mono font-bold text-brand-800">{application.submissionNumber}</span>
                      {' — déposée le '}{dateCourte.format(new Date(application.submittedAt))}
                    </div>
                  ) : (
                    <div className="text-xs text-slate-500">
                      {application.updatedAt ? `Dernière modification le ${dateCourte.format(new Date(application.updatedAt))}` : 'Aucune modification enregistrée'}
                    </div>
                  )}
                </div>
              </div>
            </div>
            <div className="border-t border-slate-200 p-6 md:border-r md:border-t-0">
              <div className="text-sm font-extrabold">Complétude du dossier</div>
              <div className="mt-4 text-4xl font-black text-brand-800" data-testid="progression">{statsVisible ? <AnimatedCounter value={`${progression}%`} /> : '0%'}</div>
              <div className="mt-3 h-2.5 overflow-hidden rounded-full bg-slate-100"><div className="animate-width h-full rounded-full bg-brand-800" style={{ width: statsVisible ? `${progression}%` : '0%' }} /></div>
              <p className="mt-3 text-xs leading-5 text-slate-500">
                {application.currentStep ? `Étape en cours : ${application.currentStep.label} (${application.currentStep.position} sur ${steps.length}).` : 'Commencez par la première étape.'}
              </p>
            </div>
            <div className="border-t border-slate-200 p-6 md:border-t-0">
              <div className="text-sm font-extrabold">Date limite de soumission</div>
              {campaign?.closesAt ? (
                <div className="mt-4 flex items-center gap-4">
                  <div className="grid h-14 w-14 place-items-center rounded-full bg-emerald-50 text-brand-800"><CalendarDays /></div>
                  <div>
                    <div className="text-2xl font-black text-brand-800">{dateLongue.format(new Date(campaign.closesAt))}</div>
                    <div className="mt-1 text-sm text-slate-600">{campaign.daysLeft === 0 ? 'Dernier jour' : <>Il vous reste <strong className="text-gold-600">{campaign.daysLeft} jours</strong></>}</div>
                  </div>
                </div>
              ) : (
                <p className="mt-4 text-sm text-slate-500">Aucune date de clôture n’est publiée pour le moment.</p>
              )}
            </div>
          </section>
        )}

        {horsParcours.length > 0 ? (
          <Reveal delay={60}>
            <Card className="mt-5 border-amber-200 bg-[#fffdf5] p-5" data-testid="etapes-hors-parcours">
              <p className="text-sm leading-6 text-slate-700">
                <strong className="font-extrabold text-brand-950">
                  {horsParcours.map((etape) => `« ${etape.label} »`).join(', ')}
                </strong>{' '}
                {horsParcours.length > 1 ? 'sont remplies et conservées' : 'est remplie et conservée'}, mais
                {horsParcours.length > 1 ? ' ne comptent' : ' ne compte'} pas encore dans votre progression :
                une étape précédente n’est pas encore ouverte. Rien n’est perdu — vos réponses reprendront leur place dès son ouverture.
              </p>
            </Card>
          </Reveal>
        ) : null}

        <Reveal delay={80}><Card className="mt-5 p-6">
          <SectionTitle
            title={`Progression de ma candidature (${steps.length} étapes)`}
            aside={
              application?.submittedUrl ? (
                <Link className="text-xs font-bold text-brand-800" href={application.submittedUrl} data-testid="voir-accuse">
                  Voir l’accusé de dépôt <ArrowRight className="inline" size={14} />
                </Link>
              ) : application?.continueUrl ? (
                <span className="flex flex-wrap items-center gap-3">
                  <Link className="text-xs font-bold text-brand-800" href={application.continueUrl}>Continuer ma candidature <ArrowRight className="inline" size={14}/></Link>
                  <Link className="text-xs font-bold text-slate-500" href={application.reviewUrl} data-testid="aller-relecture">Relire et envoyer</Link>
                </span>
              ) : undefined
            }
          />
          <ProgressSteps steps={steps.map(({ label, state }): Step => ({ label, state }))} />
        </Card></Reveal>

        <div className="mt-5 grid gap-5 xl:grid-cols-2">
          <Reveal delay={140}><Card className="p-6">
            <SectionTitle title="Messages récents" aside={<a href="#" className="text-xs font-bold text-brand-800">Voir tout</a>} />
            <div className="divide-y divide-slate-100">{messages.map(([from, subject, date], i) => <div key={subject} className="flex gap-4 py-4 first:pt-0"><div className={`grid h-11 w-11 shrink-0 place-items-center rounded-full ${i === 2 ? 'bg-amber-100 text-amber-700' : 'border border-brand-700 bg-white text-brand-800'}`}>{i === 2 ? 'MA' : <Mail size={18}/>}</div><div className="min-w-0 flex-1"><div className="flex items-start justify-between gap-3"><div className="font-bold text-slate-800">{from}</div><div className="whitespace-nowrap text-[11px] text-slate-400">{date}</div></div><div className="mt-0.5 truncate text-sm font-semibold text-slate-700">{subject}</div><div className="mt-1 truncate text-xs text-slate-400">Consultez le détail du message depuis votre centre de notifications…</div></div></div>)}</div>
          </Card></Reveal>
          <Reveal delay={200}><Card className="p-6">
            <SectionTitle title="Mes documents" aside={<a href="#" className="text-xs font-bold text-brand-800">Voir tout</a>} />
            <div className="space-y-3">{docs.map(([name, meta, status]) => <div key={name} className="flex items-center gap-3"><div className="grid h-10 w-10 place-items-center rounded-lg bg-slate-50 text-brand-800"><FileText size={18}/></div><div className="min-w-0 flex-1"><div className="truncate text-sm font-semibold text-slate-800">{name}</div><div className="text-[11px] text-slate-400">{meta}</div></div><Pill tone={status === 'Ajouté' ? 'green' : 'gold'}>{status}</Pill></div>)}</div>
            <button className="focus-ring press-feedback mt-5 flex min-h-14 w-full items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 bg-slate-50/40 text-sm font-bold text-slate-700 hover:bg-brand-50"><UploadCloud size={18}/> Ajouter un document</button>
          </Card></Reveal>
        </div>
      </div>
    </CandidateLayout>
  );
}
