import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import * as api from '../services/api';
import { useUser } from './UserContext';

interface SesDataContextType {
  domains: api.DomainRow[];
  senders: api.SenderRow[];
  /** Solo los dominios ya verificados en SES. */
  verifiedDomains: api.DomainRow[];
  /** Remitente marcado por defecto (el MAIL_FROM efectivo). */
  defaultSender: api.SenderRow | null;
  /** Remitente de respuesta por defecto (Reply-To efectivo), si hay uno configurado. */
  defaultReplyTo: api.SenderRow | null;
  loading: boolean;
  syncing: boolean;
  /** Recarga rápida desde la base de datos. */
  refresh: () => Promise<void>;
  /** Sincroniza contra Amazon SES (lento) y recarga. */
  sync: () => Promise<void>;
}

const SesDataContext = createContext<SesDataContextType | undefined>(undefined);

/** Cada cuánto se refresca en segundo plano mientras la sesión está abierta. */
const BACKGROUND_REFRESH_MS = 60_000;

/**
 * Carga dominios y remitentes UNA vez al iniciar sesión y los comparte con toda
 * la aplicación. Así el módulo de Email ya conoce el remitente y los dominios
 * verificados sin que el usuario tenga que pasar por Verificación y esperar.
 *
 * La carga normal lee de la base de datos (rápido). La sincronización contra
 * Amazon SES solo ocurre cuando se pide explícitamente.
 */
export const SesDataProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { isAuthenticated } = useUser();
  const [domains, setDomains] = useState<api.DomainRow[]>([]);
  const [senders, setSenders] = useState<api.SenderRow[]>([]);
  const [loading, setLoading] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const loadedOnce = useRef(false);

  const load = useCallback(async (opts: { silent?: boolean; sync?: boolean } = {}) => {
    if (!opts.silent) setLoading(true);
    if (opts.sync) setSyncing(true);
    try {
      // Al sincronizar, los dominios van primero: la reconciliación de
      // remitentes depende del estado actualizado de su dominio.
      const d = opts.sync ? await api.syncDomains() : await api.listDomains();
      const s = opts.sync ? await api.syncSenders() : await api.listSenders();
      setDomains(d.domains);
      setSenders(s.senders);
      loadedOnce.current = true;
    } catch {
      // Silencioso: los módulos muestran sus propios avisos si hace falta.
    } finally {
      if (!opts.silent) setLoading(false);
      if (opts.sync) setSyncing(false);
    }
  }, []);

  // Carga inicial al autenticarse; limpieza al cerrar sesión.
  useEffect(() => {
    if (!isAuthenticated) {
      setDomains([]);
      setSenders([]);
      loadedOnce.current = false;
      return;
    }
    load();
  }, [isAuthenticated, load]);

  // Refresco en segundo plano para que los estados se actualicen solos
  // (p.ej. un dominio que termina de verificarse) sin molestar al usuario.
  useEffect(() => {
    if (!isAuthenticated) return;
    const id = window.setInterval(() => {
      if (document.visibilityState === 'visible') {
        load({ silent: true });
      }
    }, BACKGROUND_REFRESH_MS);
    return () => window.clearInterval(id);
  }, [isAuthenticated, load]);

  const verifiedDomains = useMemo(
    () => domains.filter((d) => d.verification_status === 'Success'),
    [domains]
  );

  const defaultSender = useMemo(
    () => senders.find((s) => s.is_default && s.type !== 'reply') ?? null,
    [senders]
  );

  const defaultReplyTo = useMemo(
    () => senders.find((s) => s.is_default && s.type === 'reply') ?? null,
    [senders]
  );

  const value = useMemo<SesDataContextType>(() => ({
    domains,
    senders,
    verifiedDomains,
    defaultSender,
    defaultReplyTo,
    loading,
    syncing,
    refresh: () => load(),
    sync: () => load({ sync: true }),
  }), [domains, senders, verifiedDomains, defaultSender, defaultReplyTo, loading, syncing, load]);

  return <SesDataContext.Provider value={value}>{children}</SesDataContext.Provider>;
};

export const useSesData = (): SesDataContextType => {
  const ctx = useContext(SesDataContext);
  if (ctx === undefined) {
    throw new Error('useSesData debe usarse dentro de SesDataProvider');
  }
  return ctx;
};
