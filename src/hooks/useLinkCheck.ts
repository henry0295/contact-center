import { useEffect, useState } from 'react';
import * as api from '../services/api';

/** Revisa los links del HTML del email (con un pequeño retraso) mientras se escribe. */
export function useLinkCheck(html: string, enabled = true): { result: api.LinkCheckResult | null; loading: boolean } {
  const [result, setResult] = useState<api.LinkCheckResult | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    const empty = !html || html === '<p></p>';
    if (!enabled || empty || !/https?:|www\.|href/i.test(html)) {
      setResult(null);
      setLoading(false);
      return;
    }
    let cancelled = false;
    setLoading(true);
    const id = window.setTimeout(() => {
      api.checkEmailLinks(html)
        .then((r) => { if (!cancelled) setResult(r); })
        .catch(() => { if (!cancelled) setResult(null); })
        .finally(() => { if (!cancelled) setLoading(false); });
    }, 600);
    return () => { cancelled = true; window.clearTimeout(id); };
  }, [html, enabled]);

  return { result, loading };
}
