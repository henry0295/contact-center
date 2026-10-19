import React, { useCallback, useEffect, useState } from 'react';
import { RefreshCw, Search, Loader2, UserX } from 'lucide-react';
import toast from 'react-hot-toast';
import * as api from '../services/api';

const PER_PAGE = 25;

const formatDate = (value: string) => {
  const d = new Date(value.replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? value : d.toLocaleString('es-CO');
};

/** Respuestas recibidas de los SMS de doble vía. */
const SmsReplies: React.FC = () => {
  const [rows, setRows] = useState<api.SmsReply[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [query, setQuery] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    api
      .listSmsReplies({ page, perPage: PER_PAGE, q: search || undefined })
      .then((r) => { setRows(r.replies); setTotal(r.total); })
      .catch((err) => toast.error(err instanceof Error ? err.message : 'No se pudieron cargar las respuestas'))
      .finally(() => setLoading(false));
  }, [page, search]);

  useEffect(() => { load(); }, [load]);

  // Las respuestas llegan en cualquier momento: se refresca cada 30 s.
  useEffect(() => {
    const id = window.setInterval(load, 30_000);
    return () => window.clearInterval(id);
  }, [load]);

  const pages = Math.max(1, Math.ceil(total / PER_PAGE));

  return (
    <div className="space-y-4 p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-gray-500">
          Respuestas de los destinatarios a los SMS enviados con "Permitir respuestas". Quien responde
          SALIR, STOP o BAJA queda dado de baja automáticamente.
        </p>
        <div className="flex items-center gap-2">
          <form
            onSubmit={(e) => { e.preventDefault(); setPage(1); setSearch(query.trim()); }}
            className="relative"
            role="search"
          >
            <Search className="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-gray-400" aria-hidden="true" />
            <input
              type="search"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Buscar número o texto"
              aria-label="Buscar respuestas"
              className="w-56 rounded-md border border-gray-300 py-2 pl-8 pr-3 text-sm focus:border-blue-500 focus:ring-blue-500"
            />
          </form>
          <button
            type="button"
            onClick={load}
            disabled={loading}
            className="inline-flex items-center rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
          >
            <RefreshCw className={`mr-2 h-4 w-4 ${loading ? 'animate-spin' : ''}`} aria-hidden="true" />
            Actualizar
          </button>
        </div>
      </div>

      <div className="overflow-x-auto rounded-md border border-gray-200">
        <table className="min-w-full divide-y divide-gray-200 text-sm">
          <thead className="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
            <tr>
              <th scope="col" className="px-4 py-3">Fecha</th>
              <th scope="col" className="px-4 py-3">Número</th>
              <th scope="col" className="px-4 py-3">Respuesta</th>
              <th scope="col" className="px-4 py-3">Campaña</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 bg-white">
            {loading && rows.length === 0 ? (
              <tr><td colSpan={4} className="px-4 py-8 text-center text-gray-400"><Loader2 className="mx-auto h-5 w-5 animate-spin" aria-label="Cargando" /></td></tr>
            ) : rows.length === 0 ? (
              <tr><td colSpan={4} className="px-4 py-8 text-center text-gray-500">Aún no hay respuestas.</td></tr>
            ) : (
              rows.map((r) => (
                <tr key={r.id} className="align-top">
                  <td className="whitespace-nowrap px-4 py-3 text-gray-500">{formatDate(r.received_at)}</td>
                  <td className="whitespace-nowrap px-4 py-3">
                    <div className="font-medium text-gray-900">{r.phone}</div>
                    {r.contact_name && <div className="text-xs text-gray-500">{r.contact_name}</div>}
                  </td>
                  <td className="px-4 py-3 text-gray-800">
                    <p className="whitespace-pre-wrap break-words">{r.message}</p>
                    {r.is_optout && (
                      <span className="mt-1 inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                        <UserX className="h-3 w-3" aria-hidden="true" /> Dado de baja
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-gray-600">{r.campaign_name ?? <span className="text-gray-400">Sin campaña</span>}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {pages > 1 && (
        <div className="flex items-center justify-between text-sm text-gray-600">
          <span>{total} respuesta(s)</span>
          <div className="flex items-center gap-2">
            <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} className="rounded-md border border-gray-300 px-3 py-1.5 disabled:opacity-40">Anterior</button>
            <span>Página {page} de {pages}</span>
            <button type="button" disabled={page >= pages} onClick={() => setPage((p) => p + 1)} className="rounded-md border border-gray-300 px-3 py-1.5 disabled:opacity-40">Siguiente</button>
          </div>
        </div>
      )}
    </div>
  );
};

export default SmsReplies;
