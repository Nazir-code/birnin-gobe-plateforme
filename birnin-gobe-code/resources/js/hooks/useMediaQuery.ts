import { useEffect, useState } from 'react';

/**
 * Suit un media query côté client (accordéons mobiles du footer, par exemple).
 * Valeur initiale mobile-first lorsque `window` n'est pas disponible.
 */
export function useMediaQuery(query: string): boolean {
  const [matches, setMatches] = useState(
    () => typeof window !== 'undefined' && window.matchMedia(query).matches,
  );

  useEffect(() => {
    const mql = window.matchMedia(query);
    const onChange = () => setMatches(mql.matches);
    onChange();
    mql.addEventListener('change', onChange);
    return () => mql.removeEventListener('change', onChange);
  }, [query]);

  return matches;
}
