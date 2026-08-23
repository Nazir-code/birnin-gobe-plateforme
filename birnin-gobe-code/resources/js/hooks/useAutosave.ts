import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * `dirty` n'est pas cosmetique : sans lui, l'ecran continuerait d'afficher
 * « Enregistre » pendant que le candidat tape, c'est-a-dire pendant le seul
 * moment ou c'est faux.
 */
export type SaveState = 'idle' | 'dirty' | 'saving' | 'saved' | 'error';

export type AutosaveResult<T, R> = {
  /** Etat a afficher au candidat. Jamais simule : il suit la requete reelle. */
  state: SaveState;
  /** Horodatage ISO renvoye par le serveur a la derniere sauvegarde reussie. */
  savedAt: string | null;
  /** Erreurs de validation renvoyees par Laravel, par champ. */
  errors: Partial<Record<keyof T & string, string>>;
  /**
   * Corps de la derniere reponse acceptee.
   *
   * Certaines sections renvoient plus qu'un horodatage : l'eligibilite y joint
   * le verdict recalcule par le serveur. Le lire ici evite d'avoir a le
   * recalculer cote navigateur — ce qui reviendrait a laisser React decider.
   */
  response: R | null;
  /** Force une sauvegarde immediate (bouton « Enregistrer », sortie de champ). */
  flush: () => void;
};

type Options = {
  /** Delai d'inactivite avant envoi. Assez long pour ne pas ecrire a chaque touche. */
  delayMs?: number;
};

/** Jeton CSRF depose par Laravel. Meme mecanisme que celui d'axios. */
function jetonCsrf(): string {
  const cookie = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
  return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

/**
 * Sauvegarde automatique d'un formulaire vers une route Laravel.
 *
 * Trois problemes reels, trois reponses :
 *
 * 1. Requetes concurrentes — une seule requete est en vol a la fois. Si le
 *    candidat continue de taper pendant l'envoi, la modification est marquee et
 *    repartira des la reponse recue. On ne lance jamais deux ecritures en
 *    parallele sur la meme ligne.
 *
 * 2. Reponse ancienne ecrasant la nouvelle — chaque envoi porte un numero
 *    croissant, et une reponse dont le numero n'est plus le dernier est ignoree.
 *    L'affichage ne peut donc pas revenir en arriere.
 *
 * 3. Sauvegarde apres demontage — la requete en vol n'est pas annulee (annuler,
 *    ce serait perdre la saisie du candidat), mais plus aucun etat React n'est
 *    ecrit une fois le composant demonte.
 *
 * La source de verite reste PostgreSQL : ce hook n'ecrit rien en local, et un
 * rechargement repart des props Inertia.
 */
export function useAutosave<T extends Record<string, string>, R = unknown>(
  url: string,
  values: T,
  { delayMs = 900 }: Options = {},
): AutosaveResult<T, R> {
  const [state, setState] = useState<SaveState>('idle');
  const [savedAt, setSavedAt] = useState<string | null>(null);
  const [response, setResponse] = useState<R | null>(null);
  const [errors, setErrors] = useState<Partial<Record<keyof T & string, string>>>({});

  const valuesRef = useRef(values);
  const monteRef = useRef(true);
  const enVolRef = useRef(false);
  const enAttenteRef = useRef(false);
  const seqRef = useRef(0);
  const dernierAppliqueRef = useRef(0);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  // Compare a l'etat serveur connu : recharger la page ne doit pas declencher
  // une sauvegarde alors que rien n'a change.
  const referenceRef = useRef(JSON.stringify(values));

  valuesRef.current = values;

  const envoyer = useCallback(async () => {
    if (enVolRef.current) {
      enAttenteRef.current = true;
      return;
    }

    const charge = valuesRef.current;
    const empreinte = JSON.stringify(charge);
    const seq = ++seqRef.current;

    enVolRef.current = true;
    if (monteRef.current) setState('saving');

    try {
      const reponse = await fetch(url, {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-XSRF-TOKEN': jetonCsrf(),
        },
        body: JSON.stringify(charge),
      });

      // Une reponse plus ancienne que la derniere appliquee n'a plus rien a
      // dire de l'etat courant.
      const obsolete = seq < dernierAppliqueRef.current;

      if (reponse.status === 422) {
        const corps = (await reponse.json()) as { errors?: Record<string, string[]> };
        if (!obsolete && monteRef.current) {
          dernierAppliqueRef.current = seq;
          setErrors(
            Object.fromEntries(
              Object.entries(corps.errors ?? {}).map(([champ, messages]) => [champ, messages[0]]),
            ) as Partial<Record<keyof T & string, string>>,
          );
          setState('error');
        }
        return;
      }

      if (!reponse.ok) throw new Error(`HTTP ${reponse.status}`);

      const corps = (await reponse.json()) as R & { savedAt?: string | null };

      if (!obsolete && monteRef.current) {
        dernierAppliqueRef.current = seq;
        referenceRef.current = empreinte;
        setErrors({});
        setSavedAt(corps.savedAt ?? null);
        setResponse(corps);
        setState('saved');
      }
    } catch {
      if (monteRef.current && seq >= dernierAppliqueRef.current) {
        dernierAppliqueRef.current = seq;
        setState('error');
      }
    } finally {
      enVolRef.current = false;
      if (enAttenteRef.current) {
        enAttenteRef.current = false;
        void envoyer();
      }
    }
  }, [url]);

  const flush = useCallback(() => {
    if (timerRef.current) clearTimeout(timerRef.current);
    timerRef.current = null;
    if (JSON.stringify(valuesRef.current) === referenceRef.current && !enAttenteRef.current) return;
    void envoyer();
  }, [envoyer]);

  useEffect(() => {
    if (JSON.stringify(values) === referenceRef.current) return;

    setState((precedent) => (precedent === 'saving' ? precedent : 'dirty'));

    if (timerRef.current) clearTimeout(timerRef.current);
    timerRef.current = setTimeout(() => {
      timerRef.current = null;
      void envoyer();
    }, delayMs);

    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
    };
  }, [values, delayMs, envoyer]);

  useEffect(() => {
    monteRef.current = true;
    return () => {
      monteRef.current = false;
    };
  }, []);

  return { state, savedAt, errors, response, flush };
}
