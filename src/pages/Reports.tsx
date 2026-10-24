import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  BarChart3, Mail, MessageSquare, CheckCircle2, AlertCircle, Loader2,
  RefreshCw, Ban, TrendingUp, Megaphone, Search, ChevronLeft, ChevronRight,
  Eye, X, CalendarX, Send, Trash2, Clock, FileText, Phone, MessageCircle,
} from 'lucide-react';
import toast from 'react-hot-toast';
import * as api from '../services/api';
import { useUser } from '../context/UserContext';

type Tab = 'resumen' | 'historial' | 'campanas' | 'supresiones';

const campaignStatusBadge = (status: string) => {
  const map: Record<string, string> = {
    Draft: 'bg-yellow-100 text-yellow-800',
    Scheduled: 'bg-blue-100 text-blue-800',
    Sending: 'bg-indigo-100 text-indigo-800',
    /** Llamada originada, esperando a que Asterisk/el destinatario resuelvan. */
    Dialing: 'bg-indigo-100 text-indigo-800',
    Pending: 'bg-gray-100 text-gray-700',
    Completed: 'bg-green-100 text-green-800',
    Failed: 'bg-red-100 text-red-800',
    Expired: 'bg-gray-200 text-gray-700',
  };
  return map[status] || 'bg-gray-100 text-gray-800';
};

const originLabel = (origin: string): string => {
  if (origin === 'test') return 'Prueba';
  if (origin === 'campaign') return 'Campaña';
  return 'Directo';
};

const channelBadgeClass = (channel: string): string => {
  if (channel === 'email') return 'bg-blue-100 text-blue-800';
  if (channel === 'call') return 'bg-purple-100 text-purple-800';
  if (channel === 'whatsapp') return 'bg-emerald-100 text-emerald-800';
  return 'bg-green-100 text-green-800';
};

const ChannelIcon: React.FC<{ channel: string; className?: string }> = ({ channel, className }) => {
  if (channel === 'email') return <Mail className={className} />;
  if (channel === 'call') return <Phone className={className} />;
  if (channel === 'whatsapp') return <MessageCircle className={className} />;
  return <MessageSquare className={className} />;
};

const inputClass =
  'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none';

function statusBadge(status: string) {
  const map: Record<string, string> = {
    Sent: 'bg-green-100 text-green-800',
    Failed: 'bg-red-100 text-red-800',
  };
  return map[status] ?? 'bg-gray-100 text-gray-800';
}

function formatDate(value: string): string {
  const d = new Date(value.replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? value : d.toLocaleString('es');
}

/** Gráfico de barras en SVG: evita añadir una librería solo para esto. */
const DailyChart: React.FC<{ points: api.DailyPoint[] }> = ({ points }) => {
  const byDay = useMemo(() => {
    const map = new Map<string, { sent: number; failed: number }>();
    for (const p of points) {
      const entry = map.get(p.dia) ?? { sent: 0, failed: 0 };
      if (p.status === 'Sent') entry.sent += p.total;
      else if (p.status === 'Failed') entry.failed += p.total;
      map.set(p.dia, entry);
    }
    return Array.from(map.entries())
      .sort((a, b) => a[0].localeCompare(b[0]))
      .map(([dia, v]) => ({ dia, ...v }));
  }, [points]);

  if (byDay.length === 0) {
    return (
      <div className="flex h-56 items-center justify-center text-sm text-gray-500">
        Todavía no hay envíos en este periodo.
      </div>
    );
  }

  const max = Math.max(...byDay.map((d) => d.sent + d.failed), 1);
  const barWidth = 100 / byDay.length;

  return (
    <div>
      <svg viewBox="0 0 100 60" preserveAspectRatio="none" className="h-56 w-full" role="img"
        aria-label={`Evolución de envíos: ${byDay.length} días`}>
        {byDay.map((d, i) => {
          const totalH = ((d.sent + d.failed) / max) * 55;
          const sentH = ((d.sent) / max) * 55;
          const x = i * barWidth + barWidth * 0.15;
          const w = barWidth * 0.7;
          return (
            <g key={d.dia}>
              <rect x={x} y={58 - totalH} width={w} height={totalH - sentH} fill="#ef4444" />
              <rect x={x} y={58 - sentH} width={w} height={sentH} fill="#2563eb" />
            </g>
          );
        })}
        <line x1="0" y1="58" x2="100" y2="58" stroke="#e5e7eb" strokeWidth="0.3" />
      </svg>
      <div className="mt-2 flex items-center justify-between text-xs text-gray-500">
        <span>{byDay[0]?.dia}</span>
        <span className="flex items-center gap-3">
          <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-sm bg-blue-600" />Enviados</span>
          <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-sm bg-red-500" />Fallidos</span>
        </span>
        <span>{byDay[byDay.length - 1]?.dia}</span>
      </div>
    </div>
  );
};

const Reports: React.FC = () => {
  // Borrar campañas y liberar supresiones es solo para administradores (el
  // backend lo impone con RoleMiddleware; aquí solo se ocultan los botones).
  const { isAdmin } = useUser();
  const [tab, setTab] = useState<Tab>('resumen');

  const [summary, setSummary] = useState<api.ReportSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [days, setDays] = useState(30);

  const [messages, setMessages] = useState<api.MessageRow[]>([]);
  const [msgPage, setMsgPage] = useState<api.Pagination | null>(null);
  const [loadingMsgs, setLoadingMsgs] = useState(false);
  const [filters, setFilters] = useState({ channel: '', status: '', search: '', from: '', to: '' });
  const [page, setPage] = useState(1);

  const [suppressions, setSuppressions] = useState<api.SuppressionRow[]>([]);
  const [loadingSup, setLoadingSup] = useState(false);

  const [campaigns, setCampaigns] = useState<api.Campaign[]>([]);
  const [campPage, setCampPage] = useState<api.Pagination | null>(null);
  const [loadingCamp, setLoadingCamp] = useState(false);
  const [campFilters, setCampFilters] = useState({ status: '', channel: '', origin: '' });
  const [campPageNum, setCampPageNum] = useState(1);
  const [campDetail, setCampDetail] = useState<{
    campaign: api.Campaign;
    recipients: api.CampaignRecipient[];
    attachments: { name: string }[];
  } | null>(null);

  const loadSummary = useCallback(async () => {
    setLoading(true);
    try {
      setSummary(await api.getReportSummary({ days }));
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error al cargar los reportes');
    } finally {
      setLoading(false);
    }
  }, [days]);

  const loadMessages = useCallback(async () => {
    setLoadingMsgs(true);
    try {
      const res = await api.listMessages({ page, perPage: 25, ...filters });
      setMessages(res.messages);
      setMsgPage(res.pagination);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error al cargar el historial');
    } finally {
      setLoadingMsgs(false);
    }
  }, [page, filters]);

  const loadSuppressions = useCallback(async () => {
    setLoadingSup(true);
    try {
      setSuppressions((await api.listSuppressions({ perPage: 100 })).suppressions);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error');
    } finally {
      setLoadingSup(false);
    }
  }, []);

  const loadCampaigns = useCallback(async () => {
    setLoadingCamp(true);
    try {
      const res = await api.listCampaigns({
        page: campPageNum,
        perPage: 20,
        status: campFilters.status || undefined,
        channel: campFilters.channel || undefined,
        origin: campFilters.origin || undefined,
      });
      setCampaigns(res.campaigns);
      setCampPage(res.pagination);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error al cargar las campañas');
    } finally {
      setLoadingCamp(false);
    }
  }, [campPageNum, campFilters]);

  useEffect(() => { loadSummary(); }, [loadSummary]);
  useEffect(() => { if (tab === 'historial') loadMessages(); }, [tab, loadMessages]);
  useEffect(() => { if (tab === 'supresiones') loadSuppressions(); }, [tab, loadSuppressions]);
  useEffect(() => { if (tab === 'campanas') loadCampaigns(); }, [tab, loadCampaigns]);

  const cancelCampaignSchedule = async (c: api.Campaign) => {
    const cuando = c.scheduled_at
      ? new Date(c.scheduled_at.replace(' ', 'T')).toLocaleString('es')
      : 'la fecha prevista';
    if (!window.confirm(`¿Cancelar el envío programado de "${c.name}" (${cuando})?\n\nLa campaña volverá a borrador y no se enviará automáticamente.`)) {
      return;
    }
    try {
      const res = await api.cancelCampaignSchedule(c.id);
      toast.success(res.message);
      loadCampaigns();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error al cancelar');
    }
  };

  const sendCampaignNow = async (id: number) => {
    if (!window.confirm('¿Enviar esta campaña ahora?')) return;
    try {
      const res = await api.sendCampaignNow(id);
      toast.success(`Enviada · ${res.result.sent} ok, ${res.result.failed} fallidos`);
      loadCampaigns();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error al enviar');
    }
  };

  const removeCampaign = async (id: number) => {
    if (!window.confirm('¿Eliminar esta campaña? Esta acción no se puede deshacer.')) return;
    try {
      await api.deleteCampaign(id);
      toast.success('Campaña eliminada.');
      loadCampaigns();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error al eliminar');
    }
  };

  const viewCampaignDetail = async (id: number) => {
    try {
      setCampDetail(await api.getCampaign(id));
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error');
    }
  };

  const unsuppress = async (email: string) => {
    if (!window.confirm(`¿Quitar ${email} de la lista de supresión? Volverá a recibir envíos.`)) return;
    try {
      await api.removeSuppression(email);
      toast.success('Dirección liberada.');
      loadSuppressions();
      loadSummary();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error');
    }
  };

  const tabButton = (key: Tab, label: string, Icon: React.ElementType) => (
    <button
      type="button"
      onClick={() => setTab(key)}
      className={`relative flex flex-1 items-center justify-center gap-2 px-4 py-3.5 text-sm font-medium transition-colors ${
        tab === key ? 'text-blue-600' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-700'
      }`}
    >
      <Icon className="h-4 w-4" />
      {label}
      {tab === key && <span className="absolute inset-x-0 bottom-0 h-0.5 bg-blue-600" />}
    </button>
  );

  const totalSuppressed = summary
    ? Object.values(summary.suppressions).reduce((a, b) => a + b, 0)
    : 0;

  const cards = [
    { name: 'Enviados', value: summary?.totals.sent ?? 0, icon: CheckCircle2, bg: 'bg-green-100', color: 'text-green-600' },
    { name: 'Fallidos', value: summary?.totals.failed ?? 0, icon: AlertCircle, bg: 'bg-red-100', color: 'text-red-600' },
    { name: 'Tasa de entrega', value: `${summary?.totals.deliveryRate ?? 0}%`, icon: TrendingUp, bg: 'bg-blue-100', color: 'text-blue-600' },
    { name: 'Suprimidos', value: totalSuppressed, icon: Ban, bg: 'bg-amber-100', color: 'text-amber-600' },
  ];

  return (
    <div className="space-y-6">
      <div className="sm:flex sm:items-start sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Reportes</h1>
          <p className="mt-1 text-sm text-gray-500">
            Métricas calculadas sobre los envíos reales registrados en la base de datos.
          </p>
        </div>
        <div className="mt-4 flex items-center gap-2 sm:mt-0">
          <select
            value={days}
            onChange={(e) => setDays(Number(e.target.value))}
            className="rounded-md border border-gray-300 px-3 py-2 text-sm"
            aria-label="Periodo del informe"
          >
            <option value={7}>Últimos 7 días</option>
            <option value={30}>Últimos 30 días</option>
            <option value={90}>Últimos 90 días</option>
            <option value={365}>Último año</option>
          </select>
          <button
            type="button"
            onClick={loadSummary}
            disabled={loading}
            className="inline-flex items-center rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
          >
            <RefreshCw className={`mr-2 h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
            Actualizar
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
        {cards.map((c) => (
          <div key={c.name} className="overflow-hidden rounded-lg bg-white shadow">
            <div className="flex items-center p-5">
              <div className={`shrink-0 rounded-md ${c.bg} p-3`}>
                <c.icon className={`h-6 w-6 ${c.color}`} />
              </div>
              <div className="ml-5 w-0 flex-1">
                <dt className="truncate text-sm font-medium text-gray-500">{c.name}</dt>
                <dd className="text-lg font-medium text-gray-900">
                  {loading ? '…' : typeof c.value === 'number' ? c.value.toLocaleString('es') : c.value}
                </dd>
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="overflow-hidden rounded-lg bg-white shadow">
        <nav className="flex border-b border-gray-200" aria-label="Secciones de reportes">
          {tabButton('resumen', 'Resumen', BarChart3)}
          {tabButton('historial', 'Historial', Mail)}
          {tabButton('campanas', 'Campañas', Megaphone)}
          {tabButton('supresiones', 'Supresiones', Ban)}
        </nav>

        <div className="p-6">
          {tab === 'resumen' && (
            <div className="space-y-8">
              <section>
                <h3 className="mb-3 text-sm font-semibold text-gray-700">Evolución de envíos</h3>
                {loading ? (
                  <div className="flex h-56 items-center justify-center"><Loader2 className="h-5 w-5 animate-spin text-gray-400" /></div>
                ) : (
                  <DailyChart points={summary?.dailySeries ?? []} />
                )}
              </section>

              <section>
                <h3 className="mb-3 text-sm font-semibold text-gray-700">Por canal</h3>
                {(summary?.byChannel.length ?? 0) === 0 ? (
                  <p className="text-sm text-gray-500">Sin datos todavía.</p>
                ) : (
                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {summary!.byChannel.map((ch) => (
                      <div key={ch.channel} className="rounded-lg border border-gray-200 p-4">
                        <div className="flex items-center justify-between">
                          <span className="flex items-center gap-2 text-sm font-medium text-gray-900">
                            <ChannelIcon channel={ch.channel} className="h-4 w-4 text-gray-600" />
                            {ch.channel.toUpperCase()}
                          </span>
                          <span className="text-sm font-semibold text-gray-900">{ch.deliveryRate}%</span>
                        </div>
                        <div className="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-200">
                          <div className="h-full rounded-full bg-blue-600" style={{ width: `${ch.deliveryRate}%` }} />
                        </div>
                        <p className="mt-2 text-xs text-gray-500">
                          {ch.sent.toLocaleString('es')} enviados · {ch.failed.toLocaleString('es')} fallidos
                        </p>
                      </div>
                    ))}
                  </div>
                )}
              </section>

              <section>
                <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-700">
                  <Megaphone className="h-4 w-4 text-gray-400" />
                  Rendimiento de campañas
                </h3>
                {(summary?.campaignPerformance.length ?? 0) === 0 ? (
                  <p className="text-sm text-gray-500">Ninguna campaña con envíos todavía.</p>
                ) : (
                  <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                      <thead className="bg-gray-50">
                        <tr>
                          <th className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Campaña</th>
                          <th className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Canal</th>
                          <th className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Estado</th>
                          <th className="px-4 py-2 text-right text-xs font-medium uppercase text-gray-500">Enviados</th>
                          <th className="px-4 py-2 text-right text-xs font-medium uppercase text-gray-500">Entrega</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-gray-200">
                        {summary!.campaignPerformance.map((c) => (
                          <tr key={c.id}>
                            <td className="px-4 py-2 font-medium text-gray-900">{c.name}</td>
                            <td className="px-4 py-2 text-gray-500">{c.channel.toUpperCase()}</td>
                            <td className="px-4 py-2 text-gray-500">{c.status}</td>
                            <td className="px-4 py-2 text-right text-gray-700">{c.sent}/{c.total}</td>
                            <td className="px-4 py-2 text-right font-medium text-gray-900">{c.deliveryRate}%</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </section>

              {(summary?.topFailures.length ?? 0) > 0 && (
                <section>
                  <h3 className="mb-3 text-sm font-semibold text-gray-700">Destinatarios con más fallos</h3>
                  <ul className="divide-y divide-gray-200 rounded-md border border-gray-200">
                    {summary!.topFailures.map((f) => (
                      <li key={`${f.recipient}-${f.channel}`} className="flex items-center justify-between px-4 py-2 text-sm">
                        <span className="truncate text-gray-800">{f.recipient}</span>
                        <span className="shrink-0 text-xs text-gray-500">
                          {f.channel} · {f.fallos} fallo(s) · {formatDate(f.ultimo)}
                        </span>
                      </li>
                    ))}
                  </ul>
                </section>
              )}
            </div>
          )}

          {tab === 'historial' && (
            <div className="space-y-4">
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-5">
                <div className="sm:col-span-2">
                  <label htmlFor="buscar" className="sr-only">Buscar</label>
                  <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                    <input
                      id="buscar"
                      type="search"
                      value={filters.search}
                      onChange={(e) => { setFilters({ ...filters, search: e.target.value }); setPage(1); }}
                      placeholder="Destinatario o asunto"
                      className={inputClass + ' pl-9'}
                    />
                  </div>
                </div>
                <select value={filters.channel} onChange={(e) => { setFilters({ ...filters, channel: e.target.value }); setPage(1); }} className={inputClass} aria-label="Canal">
                  <option value="">Todos los canales</option>
                  <option value="email">Email</option>
                  <option value="sms">SMS</option>
                  <option value="call">Llamada</option>
                  <option value="whatsapp">WhatsApp</option>
                </select>
                <select value={filters.status} onChange={(e) => { setFilters({ ...filters, status: e.target.value }); setPage(1); }} className={inputClass} aria-label="Estado">
                  <option value="">Todos los estados</option>
                  <option value="Sent">Enviados</option>
                  <option value="Failed">Fallidos</option>
                </select>
                <input type="date" value={filters.from} onChange={(e) => { setFilters({ ...filters, from: e.target.value }); setPage(1); }} className={inputClass} aria-label="Desde" />
              </div>

              <div className="overflow-x-auto rounded-md border border-gray-200">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Fecha</th>
                      <th className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Canal</th>
                      <th className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Destinatario</th>
                      <th className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Asunto</th>
                      <th className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Estado</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200 bg-white">
                    {loadingMsgs ? (
                      <tr><td colSpan={5} className="px-4 py-10 text-center text-gray-500">Cargando...</td></tr>
                    ) : messages.length === 0 ? (
                      <tr><td colSpan={5} className="px-4 py-10 text-center text-gray-500">Sin resultados.</td></tr>
                    ) : (
                      messages.map((m) => (
                        <tr key={m.id} className="hover:bg-gray-50">
                          <td className="whitespace-nowrap px-4 py-2 text-gray-500">{formatDate(m.created_at)}</td>
                          <td className="px-4 py-2 text-gray-500">{m.channel.toUpperCase()}</td>
                          <td className="max-w-xs truncate px-4 py-2 text-gray-900">{m.recipient}</td>
                          <td className="max-w-xs truncate px-4 py-2 text-gray-500">{m.subject ?? '—'}</td>
                          <td className="px-4 py-2">
                            <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${statusBadge(m.status)}`} title={m.error ?? undefined}>
                              {m.status === 'Sent' ? 'Enviado' : 'Fallido'}
                            </span>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>

              {msgPage && msgPage.total > 0 && (
                <div className="flex items-center justify-between text-sm">
                  <p className="text-gray-600">
                    Página {msgPage.page} de {msgPage.totalPages} · {msgPage.total.toLocaleString('es')} mensajes
                  </p>
                  <div className="flex gap-2">
                    <button type="button" onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={msgPage.page <= 1}
                      className="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 hover:bg-gray-50 disabled:opacity-40">
                      <ChevronLeft className="h-4 w-4" /> Anterior
                    </button>
                    <button type="button" onClick={() => setPage((p) => p + 1)} disabled={!msgPage.hasMore}
                      className="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 hover:bg-gray-50 disabled:opacity-40">
                      Siguiente <ChevronRight className="h-4 w-4" />
                    </button>
                  </div>
                </div>
              )}
            </div>
          )}

          {tab === 'campanas' && (
            <div className="space-y-4">
              <div className="rounded-md border-l-4 border-blue-400 bg-blue-50 p-4">
                <p className="text-sm text-blue-800">
                  Cada envío de SMS o email (individual, masivo o de prueba) crea aquí su propia campaña.
                  Gestiónalas: revisa el detalle por destinatario, cancela una programada o elimínala.
                </p>
              </div>

              <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                <select
                  value={campFilters.channel}
                  onChange={(e) => { setCampFilters({ ...campFilters, channel: e.target.value }); setCampPageNum(1); }}
                  className={inputClass}
                  aria-label="Canal"
                >
                  <option value="">Todos los canales</option>
                  <option value="email">Email</option>
                  <option value="sms">SMS</option>
                  <option value="call">Llamada</option>
                  <option value="whatsapp">WhatsApp</option>
                </select>
                <select
                  value={campFilters.status}
                  onChange={(e) => { setCampFilters({ ...campFilters, status: e.target.value }); setCampPageNum(1); }}
                  className={inputClass}
                  aria-label="Estado"
                >
                  <option value="">Todos los estados</option>
                  <option value="Draft">Borrador</option>
                  <option value="Scheduled">Programada</option>
                  <option value="Sending">Enviando</option>
                  <option value="Completed">Completada</option>
                  <option value="Failed">Fallida</option>
                  <option value="Expired">Caducada</option>
                </select>
                {/* Nota: 'Dialing' es el estado de un destinatario individual
                    (campaign_recipients), no de la campaña completa; mientras
                    hay destinatarios en Dialing la campaña aparece 'Sending'. */}
                <select
                  value={campFilters.origin}
                  onChange={(e) => { setCampFilters({ ...campFilters, origin: e.target.value }); setCampPageNum(1); }}
                  className={inputClass}
                  aria-label="Origen"
                >
                  <option value="">Todos los orígenes</option>
                  <option value="direct">Directo (compositor)</option>
                  <option value="campaign">Campaña (API)</option>
                  <option value="test">Prueba</option>
                </select>
                <button
                  type="button"
                  onClick={loadCampaigns}
                  disabled={loadingCamp}
                  className="inline-flex items-center justify-center rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                >
                  <RefreshCw className={`mr-2 h-4 w-4 ${loadingCamp ? 'animate-spin' : ''}`} />
                  Actualizar
                </button>
              </div>

              <div className="overflow-hidden rounded-md border border-gray-200">
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <thead className="bg-gray-50">
                      <tr>
                        <th className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Campaña</th>
                        <th className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Canal</th>
                        <th className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Origen</th>
                        <th className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Estado</th>
                        <th className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Destinatarios</th>
                        <th className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Programada</th>
                        <th className="px-4 py-2 text-right text-xs font-medium uppercase text-gray-500">Acciones</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-200 bg-white">
                      {loadingCamp ? (
                        <tr><td colSpan={7} className="px-4 py-10 text-center text-gray-500">Cargando...</td></tr>
                      ) : campaigns.length === 0 ? (
                        <tr><td colSpan={7} className="px-4 py-10 text-center text-gray-500">No hay campañas todavía.</td></tr>
                      ) : (
                        campaigns.map((c) => (
                          <tr key={c.id} className="hover:bg-gray-50">
                            <td className="px-4 py-2">
                              <div className="font-medium text-gray-900">{c.name}</div>
                              {c.tags && <div className="text-xs text-gray-500">{c.tags}</div>}
                            </td>
                            <td className="px-4 py-2">
                              <span className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold ${channelBadgeClass(c.channel)}`}>
                                <ChannelIcon channel={c.channel} className="mr-1 h-3 w-3" />
                                {c.channel.toUpperCase()}
                              </span>
                            </td>
                            <td className="px-4 py-2 text-gray-500">{originLabel(c.origin)}</td>
                            <td className="px-4 py-2">
                              <span className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold ${campaignStatusBadge(c.status)}`}>
                                {c.status === 'Completed' && <CheckCircle2 className="mr-1 h-3 w-3" />}
                                {c.status === 'Scheduled' && <Clock className="mr-1 h-3 w-3" />}
                                {c.status === 'Draft' && <FileText className="mr-1 h-3 w-3" />}
                                {c.status}
                              </span>
                            </td>
                            <td className="px-4 py-2 text-gray-500">
                              <div>{c.total_recipients} total</div>
                              {(c.sent_count > 0 || c.failed_count > 0) && (
                                <div className="text-xs text-gray-500">{c.sent_count} ok · {c.failed_count} fallidos</div>
                              )}
                            </td>
                            <td className="px-4 py-2 text-gray-500">
                              {c.scheduled_at ? new Date(c.scheduled_at.replace(' ', 'T')).toLocaleString('es') : '—'}
                            </td>
                            <td className="px-4 py-2 text-right font-medium">
                              <div className="flex items-center justify-end gap-3">
                                <button onClick={() => viewCampaignDetail(c.id)} title="Ver detalle" className="text-gray-600 hover:text-gray-900"><Eye className="h-4 w-4" /></button>
                                {c.status === 'Scheduled' && (
                                  <button onClick={() => cancelCampaignSchedule(c)} title="Cancelar la programación" className="text-amber-600 hover:text-amber-800"><CalendarX className="h-4 w-4" /></button>
                                )}
                                {c.status !== 'Completed' && c.status !== 'Sending' && (
                                  <button onClick={() => sendCampaignNow(c.id)} title="Enviar ahora" className="text-blue-600 hover:text-blue-900"><Send className="h-4 w-4" /></button>
                                )}
                                {isAdmin && (
                                  <button onClick={() => removeCampaign(c.id)} title="Eliminar" className="text-red-600 hover:text-red-900"><Trash2 className="h-4 w-4" /></button>
                                )}
                              </div>
                            </td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>

                {campPage && campPage.totalPages > 1 && (
                  <div className="flex items-center justify-between border-t border-gray-200 px-4 py-3 text-sm">
                    <span className="text-gray-600">
                      Página {campPage.page} de {campPage.totalPages} · {campPage.total.toLocaleString('es')} campañas
                    </span>
                    <div className="flex gap-2">
                      <button type="button" onClick={() => setCampPageNum((p) => Math.max(1, p - 1))} disabled={campPage.page <= 1}
                        className="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 hover:bg-gray-50 disabled:opacity-40">
                        <ChevronLeft className="h-4 w-4" /> Anterior
                      </button>
                      <button type="button" onClick={() => setCampPageNum((p) => p + 1)} disabled={!campPage.hasMore}
                        className="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 hover:bg-gray-50 disabled:opacity-40">
                        Siguiente <ChevronRight className="h-4 w-4" />
                      </button>
                    </div>
                  </div>
                )}
              </div>
            </div>
          )}

          {tab === 'supresiones' && (
            <div className="space-y-4">
              <div className="rounded-md border-l-4 border-amber-400 bg-amber-50 p-4">
                <p className="text-sm text-amber-700">
                  Estas direcciones rebotaron de forma permanente o marcaron el correo como spam.
                  La aplicación bloquea los envíos a ellas para proteger la reputación de la cuenta
                  en AWS. Libéralas solo si sabes que el problema está resuelto.
                </p>
              </div>

              {loadingSup ? (
                <p className="text-sm text-gray-500">Cargando...</p>
              ) : suppressions.length === 0 ? (
                <p className="text-sm text-gray-500">No hay direcciones suprimidas.</p>
              ) : (
                <ul className="divide-y divide-gray-200 rounded-md border border-gray-200">
                  {suppressions.map((s) => (
                    <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium text-gray-900">{s.email}</p>
                        <p className="truncate text-xs text-gray-500">
                          {s.reason === 'bounce' ? 'Rebote permanente' : 'Queja de spam'}
                          {s.detail ? ` · ${s.detail}` : ''} · {formatDate(s.created_at)}
                        </p>
                      </div>
                      {isAdmin && (
                        <button type="button" onClick={() => unsuppress(s.email)}
                          className="shrink-0 rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                          Liberar
                        </button>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </div>
      </div>

      {campDetail && (
        <div className="fixed inset-0 z-30 flex items-center justify-center bg-black/40 p-4" onClick={() => setCampDetail(null)}>
          <div className="flex max-h-[80vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between border-b border-gray-200 px-6 py-4">
              <h3 className="text-lg font-medium text-gray-900">{campDetail.campaign.name}</h3>
              <button onClick={() => setCampDetail(null)} className="text-gray-400 hover:text-gray-600"><X className="h-5 w-5" /></button>
            </div>
            <div className="overflow-y-auto p-6">
              <div className="mb-4 flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-600">
                <span>Estado: <span className="font-medium">{campDetail.campaign.status}</span></span>
                <span>{campDetail.campaign.sent_count} enviados · {campDetail.campaign.failed_count} fallidos</span>
                {campDetail.campaign.sender_id && <span>Remitente: <span className="font-medium">{campDetail.campaign.sender_id}</span></span>}
                {campDetail.campaign.tags && <span>Etiquetas: {campDetail.campaign.tags}</span>}
              </div>
              {campDetail.attachments.length > 0 && (
                <div className="mb-4 flex flex-wrap gap-2 text-xs text-gray-600">
                  {campDetail.attachments.map((a) => (
                    <span key={a.name} className="inline-flex items-center gap-1 rounded border border-gray-200 bg-gray-50 px-2 py-1">
                      <FileText className="h-3 w-3" /> {a.name}
                    </span>
                  ))}
                </div>
              )}
              <table className="min-w-full divide-y divide-gray-200 text-sm">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500">Destinatario</th>
                    <th className="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500">Estado</th>
                    <th className="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500">Error</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200">
                  {campDetail.recipients.map((r) => (
                    <tr key={r.id}>
                      <td className="px-3 py-2 text-gray-900">{r.email || r.phone}</td>
                      <td className="px-3 py-2"><span className={`rounded-full px-2 py-0.5 text-xs ${campaignStatusBadge(r.status)}`}>{r.status}</span></td>
                      <td className="max-w-xs truncate px-3 py-2 text-xs text-red-600">{r.error || ''}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default Reports;
