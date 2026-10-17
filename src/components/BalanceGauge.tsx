import React, { useCallback, useEffect, useState } from 'react';
import { Wallet } from 'lucide-react';
import * as api from '../services/api';

/** Evento que disparan los compositores tras enviar, para refrescar el saldo al instante. */
export const BALANCE_REFRESH_EVENT = 'balance:refresh';

export function refreshBalance(): void {
  window.dispatchEvent(new Event(BALANCE_REFRESH_EVENT));
}

const formatter = new Intl.NumberFormat('es-CO', {
  style: 'currency',
  currency: 'COP',
  maximumFractionDigits: 0,
});

/**
 * Medidor de saldo de la barra superior. Solo muestra el saldo: los precios
 * por envío son internos y no llegan al navegador.
 */
const BalanceGauge: React.FC = () => {
  const [data, setData] = useState<api.Balance | null>(null);
  const [error, setError] = useState(false);

  const load = useCallback(() => {
    api
      .getBalance()
      .then((b) => {
        setData(b);
        setError(false);
      })
      .catch(() => setError(true));
  }, []);

  useEffect(() => {
    load();
    const id = window.setInterval(load, 60_000);
    window.addEventListener(BALANCE_REFRESH_EVENT, load);
    window.addEventListener('focus', load);
    return () => {
      window.clearInterval(id);
      window.removeEventListener(BALANCE_REFRESH_EVENT, load);
      window.removeEventListener('focus', load);
    };
  }, [load]);

  if (error && !data) return null;

  const percent = data ? Math.max(0, Math.min(100, data.percent)) : 0;
  const empty = data !== null && data.balance <= 0;
  const tone = empty || data?.low
    ? { bar: 'bg-red-500', text: 'text-red-700', ring: 'border-red-200 bg-red-50', icon: 'text-red-500' }
    : percent < 50
      ? { bar: 'bg-amber-500', text: 'text-amber-700', ring: 'border-amber-200 bg-amber-50', icon: 'text-amber-500' }
      : { bar: 'bg-emerald-500', text: 'text-gray-900', ring: 'border-gray-200 bg-white', icon: 'text-emerald-600' };

  const label = data ? formatter.format(data.balance) : '…';
  const title = empty
    ? 'Sin saldo: los envíos están detenidos. Contacta al administrador para recargar.'
    : data?.low
      ? 'Saldo bajo. Contacta al administrador para recargar.'
      : 'Saldo disponible para envíos';

  return (
    <div
      className={`flex items-center gap-3 rounded-lg border px-3 py-1.5 ${tone.ring}`}
      title={title}
      role="status"
      aria-live="polite"
      aria-label={`Saldo disponible: ${label}`}
    >
      <Wallet className={`h-5 w-5 shrink-0 ${tone.icon}`} aria-hidden="true" />
      <div className="min-w-[8.5rem]">
        <div className="flex items-baseline justify-between gap-2">
          <span className="text-[11px] font-medium uppercase tracking-wide text-gray-500">Saldo</span>
          <span className={`text-sm font-semibold tabular-nums ${tone.text}`}>{label}</span>
        </div>
        <div
          className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-200"
          role="meter"
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={percent}
          aria-label="Saldo restante respecto a la última recarga"
        >
          <div className={`h-full rounded-full transition-all duration-500 ${tone.bar}`} style={{ width: `${percent}%` }} />
        </div>
      </div>
    </div>
  );
};

export default BalanceGauge;
