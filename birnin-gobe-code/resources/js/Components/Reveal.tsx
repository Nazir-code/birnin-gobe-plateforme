import type { ElementType, PropsWithChildren } from 'react';
import { useReveal } from '@/hooks/useReveal';

/** Wraps content that should fade/slide in once, the first time it scrolls into view. */
export function Reveal({ as: Tag = 'div', id, delay = 0, className = '', children }: PropsWithChildren<{ as?: ElementType; id?: string; delay?: number; className?: string }>) {
  const { ref, visible } = useReveal<HTMLElement>();
  return (
    <Tag ref={ref} id={id} className={`reveal ${visible ? 'is-visible' : ''} ${className}`} style={delay ? { transitionDelay: `${delay}ms` } : undefined}>
      {children}
    </Tag>
  );
}
