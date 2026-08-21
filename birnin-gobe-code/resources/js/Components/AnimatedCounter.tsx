import { useEffect, useMemo, useState } from 'react';
import { useReveal, usePrefersReducedMotion } from '@/hooks/useReveal';

function parseNumeric(raw: string) {
  const match = raw.match(/^(\D*)([\d\s.,]*\d)(\D*)$/);
  if (!match) return null;
  const [, prefix, numStr, suffix] = match;
  const target = parseInt(numStr.replace(/[\s.,]/g, ''), 10);
  return Number.isNaN(target) ? null : { prefix, target, suffix };
}

/** Counts up from 0 to the numeric value in `value` once it scrolls into view. Non-numeric strings render as-is. */
export function AnimatedCounter({ value, durationMs = 1200, className = '' }: { value: string; durationMs?: number; className?: string }) {
  const { ref, visible } = useReveal<HTMLSpanElement>();
  const reducedMotion = usePrefersReducedMotion();
  // Memoized so an unrelated parent re-render can't hand the effect a
  // fresh object identity and restart the count mid-animation.
  const parsed = useMemo(() => parseNumeric(value), [value]);
  const [display, setDisplay] = useState(reducedMotion || !parsed ? value : `${parsed.prefix}0${parsed.suffix}`);

  useEffect(() => {
    if (!parsed) return;
    if (!visible || reducedMotion) {
      setDisplay(value);
      return;
    }
    let raf = 0;
    const start = performance.now();
    const { prefix, target, suffix } = parsed;
    const step = (now: number) => {
      const t = Math.min(1, Math.max(0, (now - start) / durationMs));
      const eased = 1 - (1 - t) ** 3;
      setDisplay(`${prefix}${Math.round(target * eased).toLocaleString('fr-FR')}${suffix}`);
      if (t < 1) raf = requestAnimationFrame(step);
    };
    raf = requestAnimationFrame(step);
    return () => cancelAnimationFrame(raf);
  }, [visible, reducedMotion, value, durationMs, parsed]);

  return (
    <span ref={ref} className={className}>
      {display}
    </span>
  );
}
