import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Mail, MessageSquare, PhoneCall, MessageCircle, CheckCircle2, Clock, Wallet, MessageSquareReply,
  UserMinus, CalendarClock, AlertTriangle, XCircle, Activity, Plus, Loader2,
} from 'lucide-react';
import toast from 'react-hot-toast';
import * as api from '../services/api';
import { useUser } from '../context/UserContext';

const PERIODS: { key: api.DashboardPeriod; label: string }[] = [
  { key: 'today', label: 'Hoy' },
  { key: '7d', label: '7 días' },
  { key: '30d', label: '30 días' },
  { key: 'month', label: 'Este mes' },
];

const CHANNEL_META: Record<api.DashboardChannel, {
  label: string; icon: React.ElementType; bg: string; color: string; badge: string; to: string; action: string;
}> = {
  email: { label: 'Email', icon: Mail, bg: 'bg-blue-100', color: 'text-blue-600', badge: 'bg-blue-100 text-blue-800', to: '/email', action: 'Nuevo email' },
  sms: { label: 'SMS', icon: MessageSquare, bg: 'bg-green-100', color: 'text-green-600', badge: 'bg-green-100 text-green-800', to: '/sms', action: 'Nuevo SMS' },
  call: { label: 'Llamadas', icon: PhoneCall, bg: 'bg-purple-100', color: 'text-purple-600', badge: 'bg-purple-100 text-purple-800', to: '/calls', action: 'Nueva llamada' },
  whatsapp: { label: 'WhatsApp', icon: MessageCircle, bg: 'bg-emerald-100', color: 'text-emerald-600', badge: 'bg-emerald-100 text-emerald-800', to: '/whatsapp', action: 'Nuevo WhatsApp' },
};

const channelMeta = (ch: string) => CHANNEL_META[ch as api.DashboardChannel] ?? CHANNEL_META.sms;

const statusBadge = (status: string) => {
  const map: Record<string, string> = {
    Draft: 'bg-yellow-100 text-yellow-800',
    Scheduled: 'bg-blue-100 text-blue-800',
    Sending: 'bg-indigo-100 text-indigo-800',
    Completed: 'bg-green-100 text-green-800',
    Failed: 'bg-red-100 text-red-800',
  };
  return map[status] || 'bg-gray-100 text-gray-800';
};

const STATUS_LABELS: Record<string, string> = {
  Draft: 'Borrador', Scheduled: 'Programada', Sending: 'Enviando', Completed: 'Completada', Failed: 'Fallida', Expired: 'Caducada',
};

const money = (v: number) => '$' + Math.round(v).toLocaleString('es-CO');
const fmtDate = (s: string) => new Date(s.replace(' ', 'T')).toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' });

const HEALTH_STYLES = {
  ok: { dot: 'bg-green-500', text: 'Operativo', icon: CheckCircle2, color: 'text-green-600' },
  warning: { dot: 'bg-amber-500', text: 'Revisar', icon: AlertTriangle, color: 'text-amber-600' },
  off: { dot: 'bg-gray-400', text: 'Sin configurar', icon: XCircle, color: 'text-gray-500' },
} as const;

const ChannelBadge: React.FC<{ channel: string }> = ({ channel }) => {
  const m = channelMeta(channel);
  return <span className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ${m.badge}`}>{m.label}</span>;
};

const Section: React.FC<{ title: string; icon: React.ElementType; children: React.ReactNode; action?: React.ReactNode }> = ({ title, icon: Icon, children, action }) => (
  <section className="overflow-hidden rounded-lg bg-white shadow">
    <div className="flex items-center justify-between border-b border-gray-200 px-4 py-4 sm:px-6">
      <h2 className="flex items-center gap-2 text-base font-medium text-gray-900">
        <Icon className="h-4 w-4 text-gray-400" aria-hidden="true" /> {title}
      </h2>
      {action}
    </div>
    {children}
  </section>
);

const Dashboard: React.FC = () => {
  const { isAdmin } = useUser();
  const [period, setPeriod] = useState<api.DashboardPeriod>('30d');
  const [stats, setStats] = useState<api.DashboardStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [cancelling, setCancelling] = useState<number | null>(null);

  const load = useCallback((p: api.DashboardPeriod) => {
    setLoading(true);
    api.getDashboardStats(p)
      .then(setStats)
      .catch((e) => toast.error(e instanceof Error ? e.message : 'Error al cargar el panel'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(period); }, [period, load]);

  const cancel = async (id: number, name: string) => {
    if (!window.confirm(`¿Cancelar la programación de "${name}"? Quedará como borrador.`)) return;
    setCancelling(id);
    try {
      const r = await api.cancelCampaignSchedule(id);
      toast.success(r.message);
      load(period);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'No se pudo cancelar');
    } finally {
      setCancelling(null);
    }
  };

  const byChannel = stats?.byChannel ?? [];
  const spend = stats?.spend;
  const att = stats?.attention;
  const periodLabel = PERIODS.find((p) => p.key === period)?.label.toLowerCase() ?? '';

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold text-gray-900">Panel</h1>
        <div className="inline-flex rounded-md border border-gray-300 bg-white p-0.5" role="group" aria-label="Periodo">
          {PERIODS.map((p) => (
            <button
              key={p.key}
              type="button"
              aria-pressed={period === p.key}
              onClick={() => setPeriod(p.key)}
              className={`rounded px-3 py-1.5 text-xs font-medium transition-colors ${
                period === p.key ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'
              }`}
            >
              {p.label}
            </button>
          ))}
        </div>
      </div>

      {/* Accesos rápidos */}
      <div className="flex flex-wrap gap-2">
        {(Object.keys(CHANNEL_META) as api.DashboardChannel[]).map((ch) => {
          const m = CHANNEL_META[ch];
          return (
            <Link
              key={ch}
              to={m.to}
              className="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
            >
              <Plus className="h-4 w-4 text-gray-400" aria-hidden="true" /> {m.action}
            </Link>
          );
        })}
      </div>

      {/* Envíos por canal */}
      <dl className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
        {(Object.keys(CHANNEL_META) as api.DashboardChannel[]).map((ch) => {
          const m = CHANNEL_META[ch];
          const s = byChannel.find((x) => x.channel === ch);
          const Icon = m.icon;
          return (
            <div key={ch} className="overflow-hidden rounded-lg bg-white p-5 shadow">
              <div className="flex items-center">
                <div className={`flex-shrink-0 rounded-md p-3 ${m.bg}`}>
                  <Icon className={`h-6 w-6 ${m.color}`} aria-hidden="true" />
                </div>
                <div className="ml-4 min-w-0 flex-1">
                  <dt className="truncate text-sm font-medium text-gray-500">
                    {ch === 'call' ? 'Llamadas contestadas' : `${m.label} enviados`}
                  </dt>
                  <dd className="text-lg font-medium text-gray-900">{loading ? '…' : (s?.sent ?? 0).toLocaleString('es-CO')}</dd>
                </div>
              </div>
              <dd className="mt-3 flex justify-between border-t border-gray-100 pt-2 text-xs text-gray-500">
                <span>
                  {s && s.sent + s.failed > 0
                    ? `${s.deliveryRate.toLocaleString('es-CO')} % ${ch === 'call' ? 'contestadas' : 'entregado'} · ${s.failed} fallido(s)`
                    : 'Sin envíos en el periodo'}
                </span>
                {spend && spend.byChannel[ch] > 0 && <span className="font-medium text-gray-700">{money(spend.byChannel[ch])}</span>}
              </dd>
            </div>
          );
        })}
      </dl>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {/* Saldo y consumo */}
        <Section title="Saldo y consumo" icon={Wallet}>
          {spend ? (
            <dl className="space-y-3 p-4 text-sm sm:px-6">
              <div className="flex justify-between">
                <dt className="text-gray-500">Saldo disponible</dt>
                <dd className={`font-semibold ${spend.low ? 'text-red-600' : 'text-gray-900'}`}>{money(spend.balance)}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-gray-500">Gasto ({periodLabel})</dt>
                <dd className="font-medium text-gray-900">{money(spend.total)}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-gray-500">Consumo medio diario (7 días)</dt>
                <dd className="font-medium text-gray-900">{money(spend.dailyAvg)}</dd>
              </div>
              <div className={`rounded-md p-3 text-xs ${spend.low || (spend.daysLeft !== null && spend.daysLeft < 7) ? 'bg-red-50 text-red-700' : 'bg-gray-50 text-gray-600'}`}>
                {spend.daysLeft === null
                  ? 'Sin consumo en los últimos 7 días.'
                  : `Al ritmo actual, el saldo alcanza para unos ${spend.daysLeft.toLocaleString('es-CO')} día(s).`}
                {spend.low && ' Queda menos del 20 % de la última recarga: solicita una recarga.'}
              </div>
            </dl>
          ) : (
            <p className="p-6 text-sm text-gray-500">{loading ? 'Cargando...' : 'Sin datos.'}</p>
          )}
        </Section>

        {/* Pide atención */}
        <Section title="Pide atención" icon={Activity}>
          {att ? (
            <ul className="divide-y divide-gray-100 text-sm">
              <li className="flex items-center justify-between px-4 py-3 sm:px-6">
                <span className="flex items-center gap-2 text-gray-700">
                  <MessageSquareReply className="h-4 w-4 text-gray-400" aria-hidden="true" /> Respuestas recibidas
                </span>
                <span className="font-semibold text-gray-900">{att.replies}</span>
              </li>
              <li className="flex items-center justify-between px-4 py-3 sm:px-6">
                <span className="flex items-center gap-2 text-gray-700">
                  <UserMinus className="h-4 w-4 text-gray-400" aria-hidden="true" /> Bajas de contactos
                </span>
                <span className="font-semibold text-gray-900">{att.optOuts}</span>
              </li>
              <li className="flex items-center justify-between px-4 py-3 sm:px-6">
                <span className="flex items-center gap-2 text-gray-700">
                  <Mail className="h-4 w-4 text-gray-400" aria-hidden="true" /> Emails bloqueados (rebote/queja)
                </span>
                <Link to="/reports" className="font-semibold text-gray-900 hover:underline">{att.emailSuppressed}</Link>
              </li>
              <li className="flex items-center justify-between px-4 py-3 sm:px-6">
                <span className="flex items-center gap-2 text-gray-700">
                  <AlertTriangle className="h-4 w-4 text-gray-400" aria-hidden="true" /> Campañas con fallos
                </span>
                <span className={`font-semibold ${att.failedCampaigns.length ? 'text-red-600' : 'text-gray-900'}`}>{att.failedCampaigns.length}</span>
              </li>
              {att.recentReplies.length > 0 && (
                <li className="space-y-2 px-4 py-3 sm:px-6">
                  <p className="text-xs font-medium uppercase text-gray-500">Últimas respuestas</p>
                  {att.recentReplies.map((r, i) => (
                    <Link
                      key={i}
                      to={r.channel === 'whatsapp' ? '/whatsapp' : '/sms'}
                      className="block rounded-md bg-gray-50 px-3 py-2 hover:bg-gray-100"
                    >
                      <span className="flex items-center justify-between text-xs text-gray-500">
                        <span><ChannelBadge channel={r.channel} /> <span className="ml-1 font-mono">+{r.phone.replace(/^\+/, '')}</span></span>
                        <span>{fmtDate(r.receivedAt)}</span>
                      </span>
                      <span className="mt-1 block truncate text-gray-800">{r.message}</span>
                    </Link>
                  ))}
                </li>
              )}
            </ul>
          ) : (
            <p className="p-6 text-sm text-gray-500">{loading ? 'Cargando...' : 'Sin datos.'}</p>
          )}
        </Section>

        {/* Estado de los canales (admin) o próximos envíos */}
        {isAdmin && stats?.channelsHealth ? (
          <Section title="Estado de los canales" icon={CheckCircle2}>
            <ul className="divide-y divide-gray-100 text-sm">
              {stats.channelsHealth.map((h) => {
                const st = HEALTH_STYLES[h.status];
                const m = channelMeta(h.channel);
                return (
                  <li key={h.channel} className="flex items-start gap-3 px-4 py-3 sm:px-6">
                    <span className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${st.dot}`} aria-hidden="true" />
                    <div className="min-w-0 flex-1">
                      <p className="flex items-center justify-between">
                        <Link to={h.channel === 'email' ? '/verification' : m.to} className="font-medium text-gray-900 hover:underline">{h.label}</Link>
                        <span className={`text-xs font-medium ${st.color}`}>{st.text}</span>
                      </p>
                      <p className="mt-0.5 text-xs text-gray-500">{h.message}</p>
                    </div>
                  </li>
                );
              })}
            </ul>
          </Section>
        ) : (
          <UpcomingSection att={att} loading={loading} cancelling={cancelling} onCancel={cancel} />
        )}
      </div>

      {isAdmin && stats?.channelsHealth && (
        <UpcomingSection att={att} loading={loading} cancelling={cancelling} onCancel={cancel} />
      )}

      {att && att.failedCampaigns.length > 0 && (
        <Section title="Campañas con fallos" icon={AlertTriangle} action={<Link to="/reports" className="text-sm font-medium text-blue-600 hover:underline">Ver en Reportes</Link>}>
          <ul className="divide-y divide-gray-100 text-sm">
            {att.failedCampaigns.map((c) => (
              <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 sm:px-6">
                <div className="min-w-0">
                  <p className="flex items-center gap-2 font-medium text-gray-900">
                    <ChannelBadge channel={c.channel} /> <span className="truncate">{c.name}</span>
                  </p>
                  {c.topError && <p className="mt-1 text-xs text-red-600">Motivo más común: {c.topError}</p>}
                </div>
                <span className="text-xs text-gray-500">{c.sent} ok · {c.failed} fallidos / {c.total}</span>
              </li>
            ))}
          </ul>
        </Section>
      )}

      <Section title="Campañas recientes" icon={Clock}>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Campaña</th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Canal</th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Estado</th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Resultados</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {loading && !stats ? (
                <tr><td colSpan={4} className="px-6 py-10 text-center text-sm text-gray-500">Cargando...</td></tr>
              ) : !stats || stats.recentCampaigns.length === 0 ? (
                <tr><td colSpan={4} className="px-6 py-10 text-center text-sm text-gray-500">Aún no hay campañas.</td></tr>
              ) : (
                stats.recentCampaigns.map((c) => (
                  <tr key={c.id} className="hover:bg-gray-50">
                    <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">{c.name}</td>
                    <td className="whitespace-nowrap px-6 py-4"><ChannelBadge channel={c.channel} /></td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <span className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold ${statusBadge(c.status)}`}>
                        {c.status === 'Completed' && <CheckCircle2 className="mr-1 h-3 w-3" aria-hidden="true" />}
                        {c.status === 'Scheduled' && <Clock className="mr-1 h-3 w-3" aria-hidden="true" />}
                        {STATUS_LABELS[c.status] ?? c.status}
                      </span>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                      {c.sent_count} ok · {c.failed_count} fallidos / {c.total_recipients}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </Section>
    </div>
  );
};

const UpcomingSection: React.FC<{
  att: api.DashboardStats['attention'] | undefined;
  loading: boolean;
  cancelling: number | null;
  onCancel: (id: number, name: string) => void;
}> = ({ att, loading, cancelling, onCancel }) => (
  <Section title="Próximos envíos programados" icon={CalendarClock}>
    {!att ? (
      <p className="p-6 text-sm text-gray-500">{loading ? 'Cargando...' : 'Sin datos.'}</p>
    ) : att.upcoming.length === 0 ? (
      <p className="p-6 text-sm text-gray-500">No hay envíos programados.</p>
    ) : (
      <ul className="divide-y divide-gray-100 text-sm">
        {att.upcoming.map((c) => (
          <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 sm:px-6">
            <div className="min-w-0">
              <p className="flex items-center gap-2 font-medium text-gray-900">
                <ChannelBadge channel={c.channel} /> <span className="truncate">{c.name}</span>
              </p>
              <p className="mt-0.5 text-xs text-gray-500">{fmtDate(c.scheduledAt)} · {c.recipients} destinatario(s)</p>
            </div>
            <button
              type="button"
              onClick={() => onCancel(c.id, c.name)}
              disabled={cancelling === c.id}
              className="inline-flex items-center rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
            >
              {cancelling === c.id && <Loader2 className="mr-1 h-3 w-3 animate-spin" aria-hidden="true" />}
              Cancelar
            </button>
          </li>
        ))}
      </ul>
    )}
  </Section>
);

export default Dashboard;
