import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  BarChart3, Mail, MessageSquare, CheckCircle2, AlertCircle, Loader2,
  RefreshCw, Ban, TrendingUp, TrendingDown, Megaphone, Search, ChevronLeft, ChevronRight,
  Eye, X, CalendarX, Send, Trash2, Clock, FileText, Phone, MessageCircle, Download,
  Wallet, MessageSquareReply, PhoneCall, ListX, Minus,
} from 'lucide-react';
import toast from 'react-hot-toast';
import * as api from '../services/api';
import { useUser } from '../context/UserContext';

type Tab = 'resumen' | 'historial' | 'campanas' | 'supresiones';
type Period = '7' | '30' | '90' | '365' | 'custom';

// ------------------------------------------------------------ etiquetas --

const CAMPAIGN_STATUS: Record<string, { label: string; cls: string }> = {
  Draft: { label: 'Borrador', cls: 'bg-yellow-100 text-yellow-800' },
  Scheduled: { label: 'Programada', cls: 'bg-blue-100 text-blue-800' },
  Sending: { label: 'Enviando', cls: 'bg-indigo-100 text-indigo-800' },
  Completed: { label: 'Completada', cls: 'bg-green-100 text-green-800' },
  Failed: { label: 'Fallida', cls: 'bg-red-100 text-red-800' },
  Expired: { label: 'Caducada', cls: 'bg-gray-200 text-gray-700' },
};

const RECIPIENT_STATUS: Record<string, { label: string; cls: string }> = {
  Pending: { label: 'Pendiente', cls: 'bg-gray-100 text-gray-700' },
  Sent: { label: 'Enviado', cls: 'bg-green-100 text-green-800' },
  Failed: { label: 'Fallido', cls: 'bg-red-100 text-red-800' },
  /** Llamada originada, esperando a que Asterisk/el destinatario resuelvan. */
  Dialing: { label: 'Marcando', cls: 'bg-indigo-100 text-indigo-800' },
};

const DELIVERY_LABELS: Record<string, string> = {
  accepted: 'Aceptado', sent: 'Enviado', delivered: 'Entregado', read: 'Leído', failed: 'Fallido',
};

const CHANNEL_LABELS: Record<string, string> = { email: 'Email', sms: 'SMS', call: 'Llamada', whatsapp: 'WhatsApp' };

const StatusPill: React.FC<{ status: string; map: Record<string, { label: string; cls: string }> }> = ({ status, map }) => {
  const s = map[status];
  return (
    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ${s?.cls ?? 'bg-gray-100 text-gray-800'}`}>
      {status === 'Completed' && <CheckCircle2 className="mr-1 h-3 w-3" aria-hidden="true" />}
      {status === 'Scheduled' && <Clock className="mr-1 h-3 w-3" aria-hidden="true" />}
      {status === 'Draft' && <FileText className="mr-1 h-3 w-3" aria-hidden="true" />}
      {s?.label ?? status}
    </span>
  );
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
  if (channel === 'email') return <Mail className={className} aria-hidden="true" />;
  if (channel === 'call') return <Phone className={className} aria-hidden="true" />;
  if (channel === 'whatsapp') return <MessageCircle className={className} aria-hidden="true" />;
  return <MessageSquare className={className} aria-hidden="true" />;
};

const ChannelBadge: React.FC<{ channel: string }> = ({ channel }) => (
  <span className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold ${channelBadgeClass(channel)}`}>
    <ChannelIcon channel={channel} className="mr-1 h-3 w-3" />
    {CHANNEL_LABELS[channel] ?? channel}
  </span>
);

const inputClass =
  'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none';

const money = (v: number) => '$' + Math.round(v).toLocaleString('es-CO');
const num = (v: number) => v.toLocaleString('es-CO');

function formatDate(value: string): string {
  const d = new Date(value.replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? value : d.toLocaleString('es-CO');
}

/** Variación respecto al periodo anterior. */
const Delta: React.FC<{ current: number; previous: number; points?: boolean; inverse?: boolean }> = ({ current, previous, points, inverse }) => {
  if (!points && previous === 0) {
    return current > 0 ? <span className="text-xs text-gray-400">nuevo en el periodo</span> : null;
  }
  const diff = points ? current - previous : ((current - previous) / previous) * 100;
  const rounded = Math.round(diff * 10) / 10;
  if (rounded === 0) {
    return <span className="inline-flex items-center text-xs text-gray-400"><Minus className="mr-0.5 h-3 w-3" aria-hidden="true" /> sin cambio</span>;
  }
  const good = inverse ? rounded < 0 : rounded > 0;
  const Icon = rounded > 0 ? TrendingUp : TrendingDown;
  return (
    <span className={`inline-flex items-center text-xs font-medium ${good ? 'text-green-600' : 'text-red-600'}`}>
      <Icon className="mr-0.5 h-3 w-3" aria-hidden="true" />
      {rounded > 0 ? '+' : ''}{rounded.toLocaleString('es-CO')}{points ? ' pts' : ' %'}
      <span className="ml-1 font-normal text-gray-400">vs. periodo anterior</span>
    </span>
  );
};

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
    return <div className="flex h-56 items-center justify-center text-sm text-gray-500">Todavía no hay envíos en este periodo.</div>;
  }

  const max = Math.max(...byDay.map((d) => d.sent + d.failed), 1);
  const barWidth = 100 / byDay.length;

  return (
    <div>
      <svg viewBox="0 0 100 60" preserveAspectRatio="none" className="h-56 w-full" role="img" aria-label={`Evolución de envíos: ${byDay.length} días`}>
        {byDay.map((d, i) => {
          const totalH = ((d.sent + d.failed) / max) * 55;
          const sentH = (d.sent / max) * 55;
          const x = i * barWidth + barWidth * 0.15;
          const w = barWidth * 0.7;
          return (
            <g key={d.dia}>
              <title>{`${d.dia}: ${d.sent} enviados, ${d.failed} fallidos`}</title>
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

/** Barra horizontal con etiqueta y valor. */
const Bar: React.FC<{ label: string; value: number; total: number; color: string; suffix?: string }> = ({ label, value, total, color, suffix }) => {
  const pct = total > 0 ? Math.round((value / total) * 1000) / 10 : 0;
  return (
    <div>
      <div className="flex justify-between text-xs text-gray-600">
        <span>{label}</span>
        <span className="font-medium text-gray-900">{num(value)} <span className="font-normal text-gray-500">({pct.toLocaleString('es-CO')} %{suffix ?? ''})</span></span>
      </div>
      <div className="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-100">
        <div className={`h-full rounded-full ${color}`} style={{ width: `${Math.min(100, pct)}%` }} />
      </div>
    </div>
  );
};

const FunnelView: React.FC<{ f: api.WaFunnel }> = ({ f }) => (
  <div className="space-y-2">
    <Bar label="Enviados" value={f.sent} total={f.sent} color="bg-emerald-600" />
    <Bar label="Entregados" value={f.delivered} total={f.sent} color="bg-emerald-500" />
    <Bar label="Leídos" value={f.read} total={f.sent} color="bg-emerald-400" />
    {f.failed > 0 && <p className="text-xs text-red-600">{num(f.failed)} no entregado(s) (reembolsados)</p>}
  </div>
);

const CallsView: React.FC<{ c: api.CallOutcomes }> = ({ c }) => {
  const total = c.answered + c.busy + c.noAnswer + c.otherFailed;
  return (
    <div className="space-y-2">
      <Bar label="Contestadas" value={c.answered} total={total} color="bg-purple-600" />
      <Bar label="No contestó" value={c.noAnswer} total={total} color="bg-purple-300" />
      <Bar label="Línea ocupada" value={c.busy} total={total} color="bg-amber-400" />
      {c.otherFailed > 0 && <Bar label="Otros fallos" value={c.otherFailed} total={total} color="bg-red-400" />}
      {c.dialing > 0 && <p className="text-xs text-gray-500">{num(c.dialing)} todavía marcando</p>}
    </div>
  );
};

const ReasonsList: React.FC<{ reasons: api.FailureReason[] }> = ({ reasons }) => {
  const max = Math.max(...reasons.map((r) => r.count), 1);
  return (
    <ul className="space-y-2">
      {reasons.map((r, i) => (
        <li key={i}>
          <div className="flex items-start justify-between gap-3 text-sm">
            <span className="min-w-0 break-words text-gray-800">{r.reason}</span>
            <span className="shrink-0 text-xs text-gray-500">
              {r.channel && <span className="mr-2">{CHANNEL_LABELS[r.channel] ?? r.channel}</span>}
              <span className="font-semibold text-gray-900">{num(r.count)}</span>
            </span>
          </div>
          <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
            <div className="h-full rounded-full bg-red-400" style={{ width: `${(r.count / max) * 100}%` }} />
          </div>
        </li>
      ))}
    </ul>
  );
};

const Panel: React.FC<{ title: string; icon: React.ElementType; children: React.ReactNode; action?: React.ReactNode }> = ({ title, icon: Icon, children, action }) => (
  <section className="rounded-lg border border-gray-200 p-4">
    <div className="mb-3 flex items-center justify-between gap-2">
      <h3 className="flex items-center gap-2 text-sm font-semibold text-gray-700">
        <Icon className="h-4 w-4 text-gray-400" aria-hidden="true" /> {title}
      </h3>
      {action}
    </div>
    {children}
  </section>
);

const ExportButton: React.FC<{ onClick: () => Promise<void>; label?: string }> = ({ onClick, label = 'Exportar CSV' }) => {
  const [busy, setBusy] = useState(false);
  return (
    <button
      type="button"
      disabled={busy}
      onClick={async () => {
        setBusy(true);
        try { await onClick(); } catch (e) { toast.error(e instanceof Error ? e.message : 'No se pudo exportar'); } finally { setBusy(false); }
      }}
      className="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
    >
      {busy ? <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden="true" /> : <Download className="mr-2 h-4 w-4" aria-hidden="true" />}
      {label}
    </button>
  );
};

// ------------------------------------------------------------ página --

const today = () => new Date().toISOString().slice(0, 10);
const daysAgo = (n: number) => new Date(Date.now() - n * 86400000).toISOString().slice(0, 10);

const Reports: React.FC = () => {
  // Borrar campañas y liberar supresiones es solo para administradores (el
  // backend lo impone con RoleMiddleware; aquí solo se ocultan los botones).
  const { isAdmin } = useUser();
  const [tab, setTab] = useState<Tab>('resumen');

  const [summary, setSummary] = useState<api.ReportSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [period, setPeriod] = useState<Period>('30');
  const [customFrom, setCustomFrom] = useState(daysAgo(29));
  const [customTo, setCustomTo] = useState(today());
  const [perfSearch, setPerfSearch] = useState('');
  const [perfQuery, setPerfQuery] = useState('');

  const range: api.ReportRange = useMemo(
    () => (period === 'custom' ? { from: customFrom, to: customTo } : { days: Number(period) }),
    [period, customFrom, customTo]
  );

  const [messages, setMessages] = useState<api.MessageRow[]>([]);
  const [msgPage, setMsgPage] = useState<api.Pagination | null>(null);
  const [loadingMsgs, setLoadingMsgs] = useState(false);
  const [filters, setFilters] = useState({ channel: '', status: '', search: '', from: '', to: '' });
  const [page, setPage] = useState(1);

  const [suppressions, setSuppressions] = useState<api.SuppressionRow[]>([]);
  const [loadingSup, setLoadingSup] = useState(false);

  const [campaigns, setCampaigns] = useState<api.Campaign[]>([]);
  const [creators, setCreators] = useState<string[]>([]);
  const [campPage, setCampPage] = useState<api.Pagination | null>(null);
  const [loadingCamp, setLoadingCamp] = useState(false);
  const [campFilters, setCampFilters] = useState({ status: '', channel: '', origin: '', search: '', from: '', to: '', createdBy: '' });
  const [campPageNum, setCampPageNum] = useState(1);
  const [campDetail, setCampDetail] = useState<Awaited<ReturnType<typeof api.getCampaign>> | null>(null);

  const loadSummary = useCallback(async () => {
    setLoading(true);
    try {
      setSummary(await api.getReportSummary({ ...range, search: perfQuery || undefined }));
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error al cargar los reportes');
    } finally {
      setLoading(false);
    }
  }, [range, perfQuery]);

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
      const f = campFilters;
      const res = await api.listCampaigns({
        page: campPageNum,
        perPage: 20,
        status: f.status || undefined,
        channel: f.channel || undefined,
        origin: f.origin || undefined,
        search: f.search.trim() || undefined,
        from: f.from || undefined,
        to: f.to || undefined,
        createdBy: f.createdBy || undefined,
      });
      setCampaigns(res.campaigns);
      setCampPage(res.pagination);
      setCreators(res.creators ?? []);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error al cargar las campañas');
    } finally {
      setLoadingCamp(false);
    }
  }, [campPageNum, campFilters]);

  // La búsqueda de texto espera a que se deje de escribir.
  useEffect(() => {
    const id = window.setTimeout(() => setPerfQuery(perfSearch.trim()), 400);
    return () => window.clearTimeout(id);
  }, [perfSearch]);

  useEffect(() => { loadSummary(); }, [loadSummary]);
  useEffect(() => {
    if (tab !== 'historial') return;
    const id = window.setTimeout(loadMessages, 250);
    return () => window.clearTimeout(id);
  }, [tab, loadMessages]);
  useEffect(() => { if (tab === 'supresiones') loadSuppressions(); }, [tab, loadSuppressions]);
  useEffect(() => {
    if (tab !== 'campanas') return;
    const id = window.setTimeout(loadCampaigns, 250);
    return () => window.clearTimeout(id);
  }, [tab, loadCampaigns]);

  const cancelCampaignSchedule = async (c: api.Campaign) => {
    const cuando = c.scheduled_at ? formatDate(c.scheduled_at) : 'la fecha prevista';
    if (!window.confirm(`¿Cancelar el envío programado de "${c.name}" (${cuando})?\n\nLa campaña volverá a borrador y no se enviará automáticamente.`)) return;
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

  const totalSuppressed = summary ? Object.values(summary.suppressions).reduce((a, b) => a + b, 0) : 0;

  const tabButton = (key: Tab, label: string, Icon: React.ElementType, badge?: number) => (
    <button
      type="button"
      onClick={() => setTab(key)}
      aria-current={tab === key ? 'page' : undefined}
      className={`relative flex flex-1 items-center justify-center gap-2 px-4 py-3.5 text-sm font-medium transition-colors ${
        tab === key ? 'text-blue-600' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-700'
      }`}
    >
      <Icon className="h-4 w-4" aria-hidden="true" />
      {label}
      {!!badge && <span className="rounded-full bg-amber-100 px-1.5 text-xs text-amber-800">{badge}</span>}
      {tab === key && <span className="absolute inset-x-0 bottom-0 h-0.5 bg-blue-600" />}
    </button>
  );

  const t = summary?.totals;
  const prev = summary?.previous;
  const cards = [
    { name: 'Enviados', value: num(t?.sent ?? 0), icon: CheckCircle2, bg: 'bg-green-100', color: 'text-green-600', delta: t && prev ? <Delta current={t.sent} previous={prev.sent} /> : null },
    { name: 'Fallidos', value: num(t?.failed ?? 0), icon: AlertCircle, bg: 'bg-red-100', color: 'text-red-600', delta: t && prev ? <Delta current={t.failed} previous={prev.failed} inverse /> : null },
    { name: 'Tasa de entrega', value: `${(t?.deliveryRate ?? 0).toLocaleString('es-CO')} %`, icon: TrendingUp, bg: 'bg-blue-100', color: 'text-blue-600', delta: t && prev && prev.total > 0 ? <Delta current={t.deliveryRate} previous={prev.deliveryRate} points /> : null },
    { name: 'Gasto', value: money(t?.spend ?? 0), icon: Wallet, bg: 'bg-amber-100', color: 'text-amber-600', delta: t && prev ? <Delta current={t.spend} previous={prev.spend} inverse /> : null, sub: t && t.refunded > 0 ? `${money(t.refunded)} reembolsado` : undefined },
    { name: 'Respuestas', value: num(summary?.replies.replies ?? 0), icon: MessageSquareReply, bg: 'bg-emerald-100', color: 'text-emerald-600', sub: summary ? `${num(summary.replies.optOuts)} baja(s)` : undefined },
  ];

  const funnel = summary?.whatsappFunnel;
  const calls = summary?.callOutcomes;
  const hasCalls = calls && calls.answered + calls.busy + calls.noAnswer + calls.otherFailed + calls.dialing > 0;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Reportes</h1>
          <p className="mt-1 text-sm text-gray-500">Métricas calculadas sobre los envíos reales registrados en la base de datos.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <select value={period} onChange={(e) => setPeriod(e.target.value as Period)} className="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Periodo del informe">
            <option value="7">Últimos 7 días</option>
            <option value="30">Últimos 30 días</option>
            <option value="90">Últimos 90 días</option>
            <option value="365">Último año</option>
            <option value="custom">Rango personalizado</option>
          </select>
          {period === 'custom' && (
            <>
              <input type="date" value={customFrom} max={customTo} onChange={(e) => setCustomFrom(e.target.value)} className="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Desde" />
              <span className="text-sm text-gray-500">a</span>
              <input type="date" value={customTo} min={customFrom} max={today()} onChange={(e) => setCustomTo(e.target.value)} className="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Hasta" />
            </>
          )}
          <button
            type="button"
            onClick={loadSummary}
            disabled={loading}
            className="inline-flex items-center rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
          >
            <RefreshCw className={`mr-2 h-4 w-4 ${loading ? 'animate-spin' : ''}`} aria-hidden="true" /> Actualizar
          </button>
        </div>
      </div>

      <dl className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-5">
        {cards.map((c) => (
          <div key={c.name} className="overflow-hidden rounded-lg bg-white p-5 shadow">
            <div className="flex items-center">
              <div className={`shrink-0 rounded-md ${c.bg} p-3`}>
                <c.icon className={`h-6 w-6 ${c.color}`} aria-hidden="true" />
              </div>
              <div className="ml-4 w-0 flex-1">
                <dt className="truncate text-sm font-medium text-gray-500">{c.name}</dt>
                <dd className="text-lg font-medium text-gray-900">{loading && !summary ? '…' : c.value}</dd>
              </div>
            </div>
            {(c.delta || c.sub) && (
              <dd className="mt-2 space-y-0.5 border-t border-gray-100 pt-2">
                {c.delta}
                {c.sub && <p className="text-xs text-gray-500">{c.sub}</p>}
              </dd>
            )}
          </div>
        ))}
      </dl>

      <div className="overflow-hidden rounded-lg bg-white shadow">
        <nav className="flex border-b border-gray-200" aria-label="Secciones de reportes">
          {tabButton('resumen', 'Resumen', BarChart3)}
          {tabButton('historial', 'Historial', Mail)}
          {tabButton('campanas', 'Campañas', Megaphone)}
          {tabButton('supresiones', 'Supresiones', Ban, totalSuppressed)}
        </nav>

        <div className="p-6">
          {tab === 'resumen' && (
            <div className="space-y-6">
              <Panel title="Evolución de envíos" icon={BarChart3}>
                {loading && !summary ? (
                  <div className="flex h-56 items-center justify-center"><Loader2 className="h-5 w-5 animate-spin text-gray-400" aria-hidden="true" /></div>
                ) : (
                  <DailyChart points={summary?.dailySeries ?? []} />
                )}
              </Panel>

              <Panel title="Por canal" icon={Megaphone}>
                {(summary?.byChannel.length ?? 0) === 0 ? (
                  <p className="text-sm text-gray-500">Sin datos en este periodo.</p>
                ) : (
                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {summary!.byChannel.map((ch) => (
                      <div key={ch.channel} className="rounded-lg border border-gray-200 p-4">
                        <div className="flex items-center justify-between">
                          <span className="flex items-center gap-2 text-sm font-medium text-gray-900">
                            <ChannelIcon channel={ch.channel} className="h-4 w-4 text-gray-600" />
                            {CHANNEL_LABELS[ch.channel] ?? ch.channel}
                          </span>
                          <span className="text-sm font-semibold text-gray-900">{ch.deliveryRate.toLocaleString('es-CO')} %</span>
                        </div>
                        <div className="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-200">
                          <div className="h-full rounded-full bg-blue-600" style={{ width: `${ch.deliveryRate}%` }} />
                        </div>
                        <p className="mt-2 text-xs text-gray-500">
                          {num(ch.sent)} {ch.channel === 'call' ? 'contestadas' : 'enviados'} · {num(ch.failed)} fallidos
                        </p>
                        {(ch.spend ?? 0) > 0 && <p className="mt-1 text-xs font-medium text-gray-700">Gasto: {money(ch.spend ?? 0)}</p>}
                      </div>
                    ))}
                  </div>
                )}
              </Panel>

              <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Panel title="WhatsApp: entrega y lectura" icon={MessageCircle}>
                  {funnel && funnel.sent > 0 ? (
                    <>
                      <FunnelView f={funnel} />
                      <p className="mt-3 text-xs text-gray-400">Los estados llegan por el webhook de WhatsApp; sin él solo se ven los enviados.</p>
                    </>
                  ) : <p className="text-sm text-gray-500">Sin envíos de WhatsApp en el periodo.</p>}
                </Panel>
                <Panel title="Resultado de las llamadas" icon={PhoneCall}>
                  {hasCalls ? <CallsView c={calls!} /> : <p className="text-sm text-gray-500">Sin llamadas en el periodo.</p>}
                </Panel>
                <Panel title="Motivos de fallo" icon={ListX}>
                  {(summary?.failureReasons.length ?? 0) > 0
                    ? <ReasonsList reasons={summary!.failureReasons} />
                    : <p className="text-sm text-gray-500">Sin fallos en el periodo.</p>}
                </Panel>
              </div>

              <Panel
                title="Rendimiento de campañas"
                icon={Megaphone}
                action={
                  <div className="flex flex-wrap items-center gap-2">
                    <div className="relative">
                      <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" aria-hidden="true" />
                      <input
                        type="search"
                        value={perfSearch}
                        onChange={(e) => setPerfSearch(e.target.value)}
                        placeholder="Nombre o etiqueta"
                        aria-label="Buscar campañas"
                        className="w-48 rounded-md border border-gray-300 py-2 pl-8 pr-3 text-sm"
                      />
                    </div>
                    <ExportButton onClick={() => api.exportCampaigns(range)} />
                  </div>
                }
              >
                {(summary?.campaignPerformance.length ?? 0) === 0 ? (
                  <p className="text-sm text-gray-500">Ninguna campaña con envíos en este periodo.</p>
                ) : (
                  <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                      <thead className="bg-gray-50">
                        <tr>
                          <th scope="col" className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Campaña</th>
                          <th scope="col" className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Canal</th>
                          <th scope="col" className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Estado</th>
                          <th scope="col" className="px-4 py-2 text-right text-xs font-medium uppercase text-gray-500">Enviados</th>
                          <th scope="col" className="px-4 py-2 text-right text-xs font-medium uppercase text-gray-500">Entrega</th>
                          <th scope="col" className="px-4 py-2 text-right text-xs font-medium uppercase text-gray-500">Resultado</th>
                          <th scope="col" className="px-4 py-2 text-right text-xs font-medium uppercase text-gray-500">Respuestas</th>
                          <th scope="col" className="px-4 py-2 text-right text-xs font-medium uppercase text-gray-500">Gasto</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-gray-200">
                        {summary!.campaignPerformance.map((c) => (
                          <tr key={c.id} className="cursor-pointer hover:bg-gray-50" onClick={() => viewCampaignDetail(c.id)}>
                            <td className="px-4 py-2">
                              <div className="font-medium text-gray-900">{c.name}</div>
                              <div className="text-xs text-gray-500">{formatDate(c.createdAt)}{c.createdBy ? ` · ${c.createdBy}` : ''}</div>
                            </td>
                            <td className="px-4 py-2"><ChannelBadge channel={c.channel} /></td>
                            <td className="px-4 py-2"><StatusPill status={c.status} map={CAMPAIGN_STATUS} /></td>
                            <td className="px-4 py-2 text-right text-gray-700">{num(c.sent)}/{num(c.total)}</td>
                            <td className="px-4 py-2 text-right font-medium text-gray-900">{c.deliveryRate.toLocaleString('es-CO')} %</td>
                            <td className="px-4 py-2 text-right text-xs text-gray-600">
                              {c.funnel ? `${c.funnel.readRate.toLocaleString('es-CO')} % leídos` : c.calls ? `${c.calls.answerRate.toLocaleString('es-CO')} % contestadas` : '—'}
                            </td>
                            <td className="px-4 py-2 text-right text-gray-700">
                              {c.replies > 0 ? `${num(c.replies)} (${c.responseRate.toLocaleString('es-CO')} %)` : '—'}
                              {c.optOuts > 0 && <div className="text-xs text-red-600">{c.optOuts} baja(s)</div>}
                            </td>
                            <td className="px-4 py-2 text-right font-medium text-gray-900">{money(c.cost)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </Panel>

              {(summary?.topFailures.length ?? 0) > 0 && (
                <Panel title="Destinatarios con más fallos" icon={AlertCircle}>
                  <ul className="divide-y divide-gray-200 rounded-md border border-gray-200">
                    {summary!.topFailures.map((f) => (
                      <li key={`${f.recipient}-${f.channel}`} className="flex items-center justify-between px-4 py-2 text-sm">
                        <span className="truncate text-gray-800">{f.recipient}</span>
                        <span className="shrink-0 text-xs text-gray-500">
                          {CHANNEL_LABELS[f.channel] ?? f.channel} · {f.fallos} fallo(s) · {formatDate(f.ultimo)}
                        </span>
                      </li>
                    ))}
                  </ul>
                </Panel>
              )}
            </div>
          )}

          {tab === 'historial' && (
            <div className="space-y-4">
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-6">
                <div className="sm:col-span-2">
                  <label htmlFor="buscar" className="sr-only">Buscar</label>
                  <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" aria-hidden="true" />
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
                <input type="date" value={filters.from} max={filters.to || undefined} onChange={(e) => { setFilters({ ...filters, from: e.target.value }); setPage(1); }} className={inputClass} aria-label="Desde" />
                <input type="date" value={filters.to} min={filters.from || undefined} onChange={(e) => { setFilters({ ...filters, to: e.target.value }); setPage(1); }} className={inputClass} aria-label="Hasta" />
              </div>
              <div className="flex justify-end">
                <ExportButton onClick={() => api.exportMessages(filters)} label="Exportar historial (CSV)" />
              </div>

              <div className="overflow-x-auto rounded-md border border-gray-200">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                  <thead className="bg-gray-50">
                    <tr>
                      <th scope="col" className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Fecha</th>
                      <th scope="col" className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Canal</th>
                      <th scope="col" className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Destinatario</th>
                      <th scope="col" className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Asunto / plantilla</th>
                      <th scope="col" className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Estado</th>
                      <th scope="col" className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Motivo</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200 bg-white">
                    {loadingMsgs ? (
                      <tr><td colSpan={6} className="px-4 py-10 text-center text-gray-500">Cargando...</td></tr>
                    ) : messages.length === 0 ? (
                      <tr><td colSpan={6} className="px-4 py-10 text-center text-gray-500">Sin resultados.</td></tr>
                    ) : (
                      messages.map((m) => (
                        <tr key={m.id} className="hover:bg-gray-50">
                          <td className="whitespace-nowrap px-4 py-2 text-gray-500">{formatDate(m.created_at)}</td>
                          <td className="px-4 py-2 text-gray-500">{CHANNEL_LABELS[m.channel] ?? m.channel}</td>
                          <td className="max-w-xs truncate px-4 py-2 text-gray-900">{m.recipient}</td>
                          <td className="max-w-xs truncate px-4 py-2 text-gray-500">{m.subject ?? '—'}</td>
                          <td className="px-4 py-2"><StatusPill status={m.status} map={RECIPIENT_STATUS} /></td>
                          <td className="max-w-xs truncate px-4 py-2 text-xs text-red-600" title={m.error ?? undefined}>{m.error ?? ''}</td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>

              {msgPage && msgPage.total > 0 && (
                <div className="flex items-center justify-between text-sm">
                  <p className="text-gray-600">Página {msgPage.page} de {msgPage.totalPages} · {num(msgPage.total)} mensajes</p>
                  <div className="flex gap-2">
                    <button type="button" onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={msgPage.page <= 1}
                      className="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 hover:bg-gray-50 disabled:opacity-40">
                      <ChevronLeft className="h-4 w-4" aria-hidden="true" /> Anterior
                    </button>
                    <button type="button" onClick={() => setPage((p) => p + 1)} disabled={!msgPage.hasMore}
                      className="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 hover:bg-gray-50 disabled:opacity-40">
                      Siguiente <ChevronRight className="h-4 w-4" aria-hidden="true" />
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
                  Cada envío de email, SMS, llamada o WhatsApp (individual, masivo o de prueba) crea aquí su propia campaña.
                  Revisa el detalle por destinatario, exporta los resultados, cancela una programada o elimínala.
                </p>
              </div>

              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div className="relative sm:col-span-2">
                  <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" aria-hidden="true" />
                  <input
                    type="search"
                    value={campFilters.search}
                    onChange={(e) => { setCampFilters({ ...campFilters, search: e.target.value }); setCampPageNum(1); }}
                    placeholder="Buscar por nombre o etiqueta"
                    aria-label="Buscar campañas"
                    className={inputClass + ' pl-9'}
                  />
                </div>
                <input type="date" value={campFilters.from} max={campFilters.to || undefined} onChange={(e) => { setCampFilters({ ...campFilters, from: e.target.value }); setCampPageNum(1); }} className={inputClass} aria-label="Creadas desde" />
                <input type="date" value={campFilters.to} min={campFilters.from || undefined} onChange={(e) => { setCampFilters({ ...campFilters, to: e.target.value }); setCampPageNum(1); }} className={inputClass} aria-label="Creadas hasta" />
                <select value={campFilters.channel} onChange={(e) => { setCampFilters({ ...campFilters, channel: e.target.value }); setCampPageNum(1); }} className={inputClass} aria-label="Canal">
                  <option value="">Todos los canales</option>
                  <option value="email">Email</option>
                  <option value="sms">SMS</option>
                  <option value="call">Llamada</option>
                  <option value="whatsapp">WhatsApp</option>
                </select>
                <select value={campFilters.status} onChange={(e) => { setCampFilters({ ...campFilters, status: e.target.value }); setCampPageNum(1); }} className={inputClass} aria-label="Estado">
                  <option value="">Todos los estados</option>
                  {Object.entries(CAMPAIGN_STATUS).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
                </select>
                <select value={campFilters.origin} onChange={(e) => { setCampFilters({ ...campFilters, origin: e.target.value }); setCampPageNum(1); }} className={inputClass} aria-label="Origen">
                  <option value="">Todos los orígenes</option>
                  <option value="direct">Directo (compositor)</option>
                  <option value="campaign">Campaña (API)</option>
                  <option value="test">Prueba</option>
                </select>
                {creators.length > 0 ? (
                  <select value={campFilters.createdBy} onChange={(e) => { setCampFilters({ ...campFilters, createdBy: e.target.value }); setCampPageNum(1); }} className={inputClass} aria-label="Creada por">
                    <option value="">Todos los usuarios</option>
                    {creators.map((u) => <option key={u} value={u}>{u}</option>)}
                  </select>
                ) : (
                  <button
                    type="button"
                    onClick={loadCampaigns}
                    disabled={loadingCamp}
                    className="inline-flex items-center justify-center rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                  >
                    <RefreshCw className={`mr-2 h-4 w-4 ${loadingCamp ? 'animate-spin' : ''}`} aria-hidden="true" /> Actualizar
                  </button>
                )}
              </div>

              <div className="overflow-hidden rounded-md border border-gray-200">
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <thead className="bg-gray-50">
                      <tr>
                        <th scope="col" className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Campaña</th>
                        <th scope="col" className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Canal</th>
                        <th scope="col" className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Origen</th>
                        <th scope="col" className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Estado</th>
                        <th scope="col" className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Destinatarios</th>
                        <th scope="col" className="px-4 py-2 text-right text-xs font-medium uppercase text-gray-500">Gasto</th>
                        <th scope="col" className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Programada</th>
                        <th scope="col" className="px-4 py-2 text-right text-xs font-medium uppercase text-gray-500">Acciones</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-200 bg-white">
                      {loadingCamp ? (
                        <tr><td colSpan={8} className="px-4 py-10 text-center text-gray-500">Cargando...</td></tr>
                      ) : campaigns.length === 0 ? (
                        <tr><td colSpan={8} className="px-4 py-10 text-center text-gray-500">No hay campañas con estos filtros.</td></tr>
                      ) : (
                        campaigns.map((c) => (
                          <tr key={c.id} className="hover:bg-gray-50">
                            <td className="px-4 py-2">
                              <div className="font-medium text-gray-900">{c.name}</div>
                              <div className="text-xs text-gray-500">
                                {formatDate(c.created_at)}
                                {c.created_by && ` · ${c.created_by}`}
                                {c.tags && ` · ${c.tags}`}
                              </div>
                            </td>
                            <td className="px-4 py-2"><ChannelBadge channel={c.channel} /></td>
                            <td className="px-4 py-2 text-gray-500">{originLabel(c.origin)}</td>
                            <td className="px-4 py-2"><StatusPill status={c.status} map={CAMPAIGN_STATUS} /></td>
                            <td className="px-4 py-2 text-gray-500">
                              <div>{num(c.total_recipients)} total</div>
                              {(c.sent_count > 0 || c.failed_count > 0) && (
                                <div className="text-xs text-gray-500">{c.sent_count} ok · {c.failed_count} fallidos</div>
                              )}
                              {(c.replies ?? 0) > 0 && <div className="text-xs text-emerald-700">{c.replies} respuesta(s)</div>}
                            </td>
                            <td className="px-4 py-2 text-right text-gray-900">{(c.spent ?? 0) > 0 ? money(c.spent ?? 0) : '—'}</td>
                            <td className="px-4 py-2 text-gray-500">{c.scheduled_at ? formatDate(c.scheduled_at) : '—'}</td>
                            <td className="px-4 py-2 text-right font-medium">
                              <div className="flex items-center justify-end gap-3">
                                <button type="button" onClick={() => viewCampaignDetail(c.id)} title="Ver detalle" aria-label={`Ver detalle de ${c.name}`} className="text-gray-600 hover:text-gray-900"><Eye className="h-4 w-4" /></button>
                                <button type="button" onClick={() => api.exportCampaign(c.id).catch((e) => toast.error(e instanceof Error ? e.message : 'No se pudo exportar'))} title="Exportar destinatarios (CSV)" aria-label={`Exportar ${c.name}`} className="text-gray-600 hover:text-gray-900"><Download className="h-4 w-4" /></button>
                                {c.status === 'Scheduled' && (
                                  <button type="button" onClick={() => cancelCampaignSchedule(c)} title="Cancelar la programación" aria-label={`Cancelar la programación de ${c.name}`} className="text-amber-600 hover:text-amber-800"><CalendarX className="h-4 w-4" /></button>
                                )}
                                {c.status !== 'Completed' && c.status !== 'Sending' && (
                                  <button type="button" onClick={() => sendCampaignNow(c.id)} title="Enviar ahora" aria-label={`Enviar ${c.name} ahora`} className="text-blue-600 hover:text-blue-900"><Send className="h-4 w-4" /></button>
                                )}
                                {isAdmin && (
                                  <button type="button" onClick={() => removeCampaign(c.id)} title="Eliminar" aria-label={`Eliminar ${c.name}`} className="text-red-600 hover:text-red-900"><Trash2 className="h-4 w-4" /></button>
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
                    <span className="text-gray-600">Página {campPage.page} de {campPage.totalPages} · {num(campPage.total)} campañas</span>
                    <div className="flex gap-2">
                      <button type="button" onClick={() => setCampPageNum((p) => Math.max(1, p - 1))} disabled={campPage.page <= 1}
                        className="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 hover:bg-gray-50 disabled:opacity-40">
                        <ChevronLeft className="h-4 w-4" aria-hidden="true" /> Anterior
                      </button>
                      <button type="button" onClick={() => setCampPageNum((p) => p + 1)} disabled={!campPage.hasMore}
                        className="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 hover:bg-gray-50 disabled:opacity-40">
                        Siguiente <ChevronRight className="h-4 w-4" aria-hidden="true" />
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
                  Estas direcciones pidieron la baja o quedaron bloqueadas para envíos de email.
                  La aplicación no les envía para proteger la reputación de la cuenta de envío.
                  Libéralas solo si la persona volvió a autorizar los correos.
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
                          {s.reason === 'bounce' || s.reason === 'Bounce' ? 'Rebote permanente' : 'Queja de spam'}
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

      {campDetail && <CampaignDetail detail={campDetail} onClose={() => setCampDetail(null)} />}
    </div>
  );
};

// ------------------------------------------------------ detalle --

const CampaignDetail: React.FC<{ detail: Awaited<ReturnType<typeof api.getCampaign>>; onClose: () => void }> = ({ detail, onClose }) => {
  const c = detail.campaign;
  const s = detail.stats;
  const isWa = c.channel === 'whatsapp';

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  return (
    <div className="fixed inset-0 z-30 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="campDetailTitle"
        className="flex max-h-[85vh] w-full max-w-3xl flex-col overflow-hidden rounded-lg bg-white shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between gap-3 border-b border-gray-200 px-6 py-4">
          <div className="min-w-0">
            <h3 id="campDetailTitle" className="truncate text-lg font-medium text-gray-900">{c.name}</h3>
            <p className="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-gray-500">
              <ChannelBadge channel={c.channel} />
              <StatusPill status={c.status} map={CAMPAIGN_STATUS} />
              <span>{formatDate(c.created_at)}</span>
              {c.created_by && <span>· {c.created_by}</span>}
            </p>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <ExportButton onClick={() => api.exportCampaign(c.id)} label="CSV" />
            <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-600" aria-label="Cerrar"><X className="h-5 w-5" /></button>
          </div>
        </div>
        <div className="space-y-5 overflow-y-auto p-6">
          {s && (
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              {[
                ['Enviados', `${num(s.sent)}/${num(s.total)}`],
                ['Entrega', `${s.deliveryRate.toLocaleString('es-CO')} %`],
                ['Gasto', money(s.cost)],
                ['Respuestas', s.replies > 0 ? `${num(s.replies)} (${s.responseRate.toLocaleString('es-CO')} %)` : '0'],
              ].map(([k, v]) => (
                <div key={k} className="rounded-md border border-gray-200 p-3">
                  <dt className="text-xs text-gray-500">{k}</dt>
                  <dd className="mt-0.5 text-base font-semibold text-gray-900">{v}</dd>
                </div>
              ))}
            </dl>
          )}
          {s && (s.refunded > 0 || s.optOuts > 0) && (
            <p className="text-xs text-gray-500">
              {s.refunded > 0 && <>Reembolsado por envíos fallidos: <span className="font-medium">{money(s.refunded)}</span>. </>}
              {s.optOuts > 0 && <>Bajas: <span className="font-medium text-red-600">{s.optOuts}</span>.</>}
            </p>
          )}

          <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-600">
            {c.sender_id && <span>Remitente: <span className="font-medium">{c.sender_id}</span></span>}
            {c.tags && <span>Etiquetas: {c.tags}</span>}
            {c.scheduled_at && <span>Programada: {formatDate(c.scheduled_at)}</span>}
          </div>

          {(s?.funnel || s?.calls || (detail.failureReasons?.length ?? 0) > 0) && (
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              {s?.funnel && s.funnel.sent > 0 && <Panel title="Entrega y lectura" icon={MessageCircle}><FunnelView f={s.funnel} /></Panel>}
              {s?.calls && <Panel title="Resultado de las llamadas" icon={PhoneCall}><CallsView c={s.calls} /></Panel>}
              {(detail.failureReasons?.length ?? 0) > 0 && <Panel title="Motivos de fallo" icon={ListX}><ReasonsList reasons={detail.failureReasons!} /></Panel>}
            </div>
          )}

          {detail.attachments.length > 0 && (
            <div className="flex flex-wrap gap-2 text-xs text-gray-600">
              {detail.attachments.map((a) => (
                <span key={a.name} className="inline-flex items-center gap-1 rounded border border-gray-200 bg-gray-50 px-2 py-1">
                  <FileText className="h-3 w-3" aria-hidden="true" /> {a.name}
                </span>
              ))}
            </div>
          )}

          <table className="min-w-full divide-y divide-gray-200 text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th scope="col" className="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500">Destinatario</th>
                <th scope="col" className="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500">Estado</th>
                {isWa && <th scope="col" className="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500">Entrega</th>}
                <th scope="col" className="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500">Motivo</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {detail.recipients.map((r) => (
                <tr key={r.id}>
                  <td className="px-3 py-2 text-gray-900">{r.email || r.phone}</td>
                  <td className="px-3 py-2"><StatusPill status={r.status} map={RECIPIENT_STATUS} /></td>
                  {isWa && <td className="px-3 py-2 text-xs text-gray-600">{r.delivery_status ? DELIVERY_LABELS[r.delivery_status] ?? r.delivery_status : '—'}</td>}
                  <td className="max-w-xs break-words px-3 py-2 text-xs text-red-600">{r.error || ''}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
};

export default Reports;
