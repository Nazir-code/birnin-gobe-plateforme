import type { ButtonHTMLAttributes, ComponentPropsWithoutRef, PropsWithChildren, ReactNode } from 'react';
import { useEffect } from 'react';
import { X } from 'lucide-react';

export function Card({ children, className = '', ...props }: PropsWithChildren<ComponentPropsWithoutRef<'section'>>) {
  return <section className={`surface-card ${className}`} {...props}>{children}</section>;
}

export function Button({ children, variant = 'primary', className = '', ...props }: PropsWithChildren<ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'secondary' | 'ghost' | 'danger' }>) {
  const variants = {
    primary: 'bg-brand-900 text-white hover:bg-brand-950 shadow-sm',
    secondary: 'bg-gold-500 text-ink-950 hover:bg-gold-600',
    ghost: 'border border-brand-900/35 bg-white text-brand-900 hover:bg-brand-50',
    danger: 'border border-red-300 bg-white text-red-600 hover:bg-red-50',
  };
  return (
    <button className={`focus-ring press-feedback inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-5 text-sm font-bold transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${variants[variant]} ${className}`} {...props}>
      {children}
    </button>
  );
}

export function Pill({ children, tone = 'green' }: PropsWithChildren<{ tone?: 'green' | 'gold' | 'neutral' | 'red' }>) {
  const toneClass = {
    green: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    gold: 'bg-amber-50 text-amber-800 ring-amber-200',
    neutral: 'bg-slate-100 text-slate-700 ring-slate-200',
    red: 'bg-red-50 text-red-700 ring-red-200',
  }[tone];
  return <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold ring-1 ring-inset ${toneClass}`}>{children}</span>;
}

export function MobileNavDrawer({ open, onClose, panelClassName = 'bg-white', testId, children }: PropsWithChildren<{ open: boolean; onClose: () => void; panelClassName?: string; testId?: string }>) {
  useEffect(() => {
    if (!open) return;
    const onKeyDown = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [open, onClose]);

  return (
    // Le tiroir reste monte, ouvert ou ferme : c'est `aria-hidden` qui dit son
    // etat, aux lecteurs d'ecran comme aux scenarios de bout en bout.
    <div className={`fixed inset-0 z-50 lg:hidden ${open ? '' : 'pointer-events-none'}`} aria-hidden={!open} data-testid={testId} data-ouvert={open ? 'oui' : 'non'}>
      <div className={`absolute inset-0 bg-black/40 transition-opacity ${open ? 'opacity-100' : 'opacity-0'}`} onClick={onClose} />
      <div className={`absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col overflow-y-auto transition-transform duration-200 ${panelClassName} ${open ? 'translate-x-0' : '-translate-x-full'}`}>
        <button className="focus-ring m-3 ml-auto flex h-10 w-10 shrink-0 items-center justify-center rounded-full hover:bg-black/5" onClick={onClose} aria-label="Fermer le menu">
          <X size={20} />
        </button>
        {children}
      </div>
    </div>
  );
}

export function SectionTitle({ eyebrow, title, aside }: { eyebrow?: string; title: string; aside?: ReactNode }) {
  return (
    <div className="mb-5 flex items-end justify-between gap-4">
      <div>
        {eyebrow ? <div className="mb-2 text-[11px] font-extrabold uppercase tracking-[.16em] text-brand-800">{eyebrow}</div> : null}
        <h2 className="text-xl font-extrabold tracking-tight text-ink-950 md:text-2xl">{title}</h2>
      </div>
      {aside}
    </div>
  );
}
