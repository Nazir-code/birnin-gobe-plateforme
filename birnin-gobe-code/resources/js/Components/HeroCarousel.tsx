import { useEffect, useState } from 'react';
import { Pause, Play } from 'lucide-react';

export type HeroImage = {
  webp?: string;
  jpg?: string;
  src: string;
  alt: string;
  /**
   * Cadrage du sujet. Les visuels sont en 16/9 alors que la zone image devient
   * quasi carrée sous 1024 px : sans décalage, `object-cover` rogne la largeur
   * et sort les visages du cadre.
   */
  objectPosition?: string;
};

/** Durée d'affichage d'une image, en ms. */
const AUTOPLAY_MS = 5000;

/**
 * Carrousel crossfade de la zone image du hero. Le texte du hero vit en dehors
 * de ce composant : seules les photos changent.
 *
 * - une seule minuterie à la fois (setTimeout relancé à chaque changement
 *   d'index, donc un clic sur un indicateur réinitialise proprement le cycle) ;
 * - le fondu et le zoom lent sont pilotés en CSS (`.crossfade-layer`), pas en
 *   JS, et retombent sur le garde-fou prefers-reduced-motion global ;
 * - bouton pause explicite (WCAG 2.2.2 — un contenu qui défile tout seul doit
 *   pouvoir être arrêté, le survol ne couvre ni le clavier ni le tactile) ;
 * - autoplay suspendu quand l'onglet est masqué, pour ne pas revenir sur une
 *   pile de transitions en attente.
 *
 * Les photos sont empilées en `absolute inset-0` : le conteneur doit donc être
 * positionné par l'appelant via `className` (`relative` par défaut,
 * `absolute inset-0` pour remplir une zone déjà dimensionnée). Ne pas remettre
 * `relative` en dur ici : Tailwind sérialise `.relative` après `.absolute`, la
 * classe de l'appelant serait ignorée et le carrousel s'effondrerait à une
 * hauteur de 0.
 */
export function HeroCarousel({ images, className = 'relative' }: { images: readonly HeroImage[]; className?: string }) {
  const [index, setIndex] = useState(0);
  const [userPaused, setUserPaused] = useState(false);
  const [tabHidden, setTabHidden] = useState(false);
  const count = images.length;

  useEffect(() => {
    const onVisibility = () => setTabHidden(document.hidden);
    onVisibility();
    document.addEventListener('visibilitychange', onVisibility);
    return () => document.removeEventListener('visibilitychange', onVisibility);
  }, []);

  const playing = count > 1 && !userPaused && !tabHidden;

  useEffect(() => {
    if (!playing) return;
    const id = window.setTimeout(() => setIndex((i) => (i + 1) % count), AUTOPLAY_MS);
    return () => window.clearTimeout(id);
  }, [index, playing, count]);

  if (count === 0) return null;

  return (
    <div
      className={`hero-carousel overflow-hidden ${className}`}
      role="group"
      aria-roledescription="carrousel"
      aria-label="Photos de la campagne BIRNIN GOBE"
    >
      {images.map((image, i) => (
        <picture
          key={image.src}
          className={`crossfade-layer absolute inset-0 block ${i === index ? 'is-active' : ''}`}
          aria-hidden={i !== index}
        >
          {image.webp ? <source srcSet={image.webp} type="image/webp" /> : null}
          {image.jpg ? <source srcSet={image.jpg} type="image/jpeg" /> : null}
          <img
            src={image.src}
            alt={image.alt}
            className="h-full w-full object-cover"
            style={image.objectPosition ? { objectPosition: image.objectPosition } : undefined}
            /* Toutes les photos sont chargées d'emblée (elles sont dans le
               viewport) : une image non décodée au moment du fondu produirait
               exactement le flash que le crossfade cherche à éviter. */
            loading="eager"
            decoding="async"
            fetchPriority={i === 0 ? 'high' : 'low'}
            draggable={false}
          />
        </picture>
      ))}

      {count > 1 ? (
        <div className="absolute inset-x-0 bottom-4 z-10 flex items-center justify-center gap-3">
          <div className="flex items-center gap-2" role="tablist" aria-label="Choisir une photo">
            {images.map((image, i) => (
              <button
                key={image.src}
                type="button"
                role="tab"
                aria-selected={i === index}
                aria-label={`Photo ${i + 1} sur ${count}`}
                className={`focus-ring h-1.5 rounded-full transition-all ${i === index ? 'w-7 bg-white' : 'w-2.5 bg-white/55 hover:bg-white/85'}`}
                onClick={() => setIndex(i)}
              />
            ))}
          </div>
          <button
            type="button"
            className="focus-ring press-feedback grid h-7 w-7 place-items-center rounded-full bg-black/30 text-white hover:bg-black/50"
            aria-label={userPaused ? 'Reprendre le défilement des photos' : 'Mettre en pause le défilement des photos'}
            aria-pressed={userPaused}
            onClick={() => setUserPaused((v) => !v)}
          >
            {userPaused ? <Play size={13} /> : <Pause size={13} />}
          </button>
        </div>
      ) : null}

      {/* Annonce du changement pour les lecteurs d'écran, sans dupliquer le texte du hero. */}
      <span className="sr-only" aria-live="polite">{`Photo ${index + 1} sur ${count} : ${images[index].alt}`}</span>
    </div>
  );
}
