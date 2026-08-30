import type { ElementType, PropsWithChildren } from 'react';
import { useReveal } from '@/hooks/useReveal';

/** Wraps content that should fade/slide in once, the first time it scrolls into view. */
/**
 * `testId` est une prop nommée, et non un `data-testid` passé en vol.
 * TypeScript laisse passer les attributs à tiret sur n'importe quel composant
 * sans les vérifier — ils ne peuvent pas être des identifiants — et `Reveal` ne
 * répandant pas ses props, un `data-testid` écrit ici disparaissait sans que
 * rien ne le signale. Une prop déclarée ne peut pas se perdre en silence.
 */
export function Reveal({ as: Tag = 'div', id, delay = 0, className = '', testId, children }: PropsWithChildren<{ as?: ElementType; id?: string; delay?: number; className?: string; testId?: string }>) {
  const { ref, visible } = useReveal<HTMLElement>();
  return (
    <Tag ref={ref} id={id} data-testid={testId} className={`reveal ${visible ? 'is-visible' : ''} ${className}`} style={delay ? { transitionDelay: `${delay}ms` } : undefined}>
      {children}
    </Tag>
  );
}
