import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Send, Loader2, Users, Tag, Clock, CalendarX, FileText, TestTube2, Smartphone,
  Sparkles, Download, MessageSquareReply, RefreshCw, Radio, Copy, ExternalLink,
  CheckCircle2, AlertTriangle, Search, Building2, Upload, Globe, Mail, MapPin,
} from 'lucide-react';
import toast from 'react-hot-toast';
import * as api from '../services/api';
import { useUser } from '../context/UserContext';
import { refreshBalance } from '../components/BalanceGauge';
import WaTemplates from '../components/WaTemplates';
import {
  type RecipientEntry, parseRecipients, parseRecipientsCsv, downloadCsv, PHONE_HEADERS,
} from '../utils/csvRecipients';

type Tab = 'compose' | 'templates' | 'replies' | 'line' | 'profile';
type RecipientSource = 'paste' | 'csv' | 'list';

const MEDIA_TYPES = ['IMAGE', 'VIDEO', 'DOCUMENT'];
const CATEGORY_LABELS: Record<string, string> = {
  MARKETING: 'Marketing',
  UTILITY: 'Utilidad',
  AUTHENTICATION: 'Autenticación',
};

const inputClass =
  'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-green-600 focus:ring-2 focus:ring-green-600 focus:outline-none';
const labelClass = 'block text-sm font-medium text-gray-700 mb-1';

const parseWaCsv = (text: string) => parseRecipientsCsv(text, PHONE_HEADERS, 'phone');

function autoName(): string {
  const d = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');
  return `WHATSAPP-${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}-${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

/** Sustituye {{1}}..{{n}} por los valores escritos (o deja el marcador). */
function fillBody(body: string, params: string[]): string {
  return body.replace(/\{\{\s*(\d+)\s*\}\}/g, (m, n) => {
    const v = params[Number(n) - 1];
    return v && v.trim() !== '' ? v : m;
  });
}

function lineLabel(s: api.WaStatus): string {
  if (!s.configured) return 'Línea sin activar';
  if (s.healthy) return 'Línea activa';
  return s.pendingSignup ? 'Pendiente de registro' : 'Línea con problemas';
}

/** Previsualización estilo WhatsApp. */
const WaPreview: React.FC<{ template: api.WaTemplate | null; params: string[]; mediaUrl: string }> = ({ template, params, mediaUrl }) => (
  <div className="mx-auto w-full max-w-[280px] rounded-[2rem] border-[6px] border-gray-900 bg-gray-900 shadow-xl">
    <div className="overflow-hidden rounded-[1.4rem]">
      <div className="flex items-center gap-2 bg-[#075E54] px-3 py-2.5 text-white">
        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/20 text-xs font-bold">WA</div>
        <span className="truncate text-sm font-medium">Tu empresa</span>
      </div>
      <div className="min-h-[280px] bg-[#ECE5DD] p-3">
        {template ? (
          <div className="max-w-[92%] space-y-1">
            <div className="rounded-lg rounded-tl-none bg-white px-3 py-2 text-sm text-gray-800 shadow-sm">
              {MEDIA_TYPES.includes(template.template_type) && (
                <div className="mb-2 flex h-24 items-center justify-center overflow-hidden rounded bg-gray-200 text-xs text-gray-500">
                  {template.template_type === 'IMAGE' && /^https:\/\//.test(mediaUrl)
                    ? <img src={mediaUrl} alt="Cabecera de la plantilla" className="h-full w-full object-cover" />
                    : template.template_type.toLowerCase()}
                </div>
              )}
              {template.header && <p className="mb-1 font-semibold">{template.header}</p>}
              <p className="whitespace-pre-wrap break-words">{fillBody(template.body, params)}</p>
              {template.footer && <p className="mt-1 text-xs text-gray-500">{template.footer}</p>}
            </div>
            {template.buttons.map((b, i) => (
              <div key={i} className="rounded-lg bg-white px-3 py-1.5 text-center text-sm font-medium text-[#00A5F4] shadow-sm">
                {b.type === 'URL' && <ExternalLink className="mr-1 inline h-3.5 w-3.5" aria-hidden="true" />}
                {b.text}
              </div>
            ))}
          </div>
        ) : (
          <p className="pt-16 text-center text-sm text-gray-500">Elige una plantilla aprobada para verla aquí.</p>
        )}
      </div>
    </div>
  </div>
);

const WhatsAppModule: React.FC = () => {
  const { isAdmin } = useUser();
  const [tab, setTab] = useState<Tab>('compose');
  const [status, setStatus] = useState<api.WaStatus | null>(null);
  const [statusLoading, setStatusLoading] = useState(false);
  const [templates, setTemplates] = useState<api.WaTemplate[]>([]);
  const [fields, setFields] = useState<api.PersonalizationField[]>([]);
  const [lists, setLists] = useState<api.ContactList[]>([]);

  const loadStatus = useCallback((refresh = false) => {
    setStatusLoading(true);
    api.getWhatsAppStatus(refresh)
      .then(setStatus)
      .catch((err) => toast.error(err instanceof Error ? err.message : 'No se pudo consultar la línea'))
      .finally(() => setStatusLoading(false));
  }, []);

  const loadTemplates = useCallback(() => {
    api.listWaTemplates().then((r) => setTemplates(r.templates)).catch(() => {});
  }, []);

  useEffect(() => {
    loadStatus();
    loadTemplates();
    api.waFields().then((r) => setFields(r.fields)).catch(() => {});
    api.listLists().then((r) => setLists(r.lists)).catch(() => {});
  }, [loadStatus, loadTemplates]);

  const tabButton = (key: Tab, label: string, Icon: React.ElementType) => (
    <button
      type="button"
      onClick={() => setTab(key)}
      aria-current={tab === key ? 'page' : undefined}
      className={`relative flex flex-1 items-center justify-center gap-2 px-4 py-3.5 text-sm font-medium transition-colors ${
        tab === key ? 'text-green-700' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-700'
      }`}
    >
      <Icon className="h-4 w-4" aria-hidden="true" />
      {label}
      {tab === key && <span className="absolute inset-x-0 bottom-0 h-0.5 bg-green-600" />}
    </button>
  );

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Módulo de WhatsApp</h1>
          <p className="mt-1 text-sm text-gray-500">
            Envía plantillas aprobadas por Meta. Cada envío queda registrado como campaña en Reportes.
          </p>
        </div>
        {status && (
          <span
            className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium ${
              !status.configured ? 'bg-gray-100 text-gray-700'
                : status.healthy ? 'bg-green-100 text-green-800'
                : status.pendingSignup ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800'
            }`}
          >
            <Radio className="h-3.5 w-3.5" aria-hidden="true" />
            {lineLabel(status)}
          </span>
        )}
      </div>

      <div className="overflow-hidden rounded-lg bg-white shadow">
        <nav className="flex border-b border-gray-200" aria-label="Secciones del módulo de WhatsApp">
          {tabButton('compose', 'Enviar', Send)}
          {tabButton('templates', 'Plantillas', FileText)}
          {tabButton('replies', 'Respuestas', MessageSquareReply)}
          {tabButton('line', 'Línea', Radio)}
          {isAdmin && status?.configured && tabButton('profile', 'Perfil', Building2)}
        </nav>

        {tab === 'compose' && (
          <Composer
            configured={status?.configured ?? false}
            templates={templates}
            fields={fields}
            lists={lists}
            goToLine={() => setTab('line')}
            goToTemplates={() => setTab('templates')}
          />
        )}
        {tab === 'templates' && (
          <WaTemplates templates={templates} isAdmin={isAdmin} configured={status?.configured ?? false} onChange={setTemplates} reload={loadTemplates} />
        )}
        {tab === 'replies' && <Replies />}
        {tab === 'line' && <Line status={status} loading={statusLoading} isAdmin={isAdmin} refresh={() => loadStatus(true)} />}
        {tab === 'profile' && isAdmin && <Profile pendingSignup={status?.pendingSignup ?? false} />}
      </div>
    </div>
  );
};

// ------------------------------------------------------------- Enviar -----

const Composer: React.FC<{
  configured: boolean;
  templates: api.WaTemplate[];
  fields: api.PersonalizationField[];
  lists: api.ContactList[];
  goToLine: () => void;
  goToTemplates: () => void;
}> = ({ configured, templates, fields, lists, goToLine, goToTemplates }) => {
  const approved = useMemo(() => templates.filter((t) => t.status === 'APPROVED'), [templates]);
  const [name, setName] = useState(autoName());
  const [templateId, setTemplateId] = useState('');
  const [params, setParams] = useState<string[]>([]);
  const [mediaUrl, setMediaUrl] = useState('');
  const [tags, setTags] = useState('');
  const [source, setSource] = useState<RecipientSource>('paste');
  const [pasteText, setPasteText] = useState('');
  const [csvText, setCsvText] = useState('');
  const [csvVariableNames, setCsvVariableNames] = useState<string[]>([]);
  const [listId, setListId] = useState('');
  const [scheduleType, setScheduleType] = useState<'now' | 'scheduled'>('now');
  const [scheduledAt, setScheduledAt] = useState('');
  const [expiresAt, setExpiresAt] = useState('');
  const [sending, setSending] = useState(false);
  const [testOpen, setTestOpen] = useState(false);
  const [testPhone, setTestPhone] = useState('');
  const [testSending, setTestSending] = useState(false);
  const [activeParam, setActiveParam] = useState(0);

  const template = approved.find((t) => String(t.id) === templateId) ?? null;
  const needsMedia = template !== null && MEDIA_TYPES.includes(template.template_type);

  useEffect(() => {
    setParams(Array.from({ length: template?.var_count ?? 0 }, () => ''));
    setActiveParam(0);
    setMediaUrl('');
  }, [template?.id, template?.var_count]);

  const recipients: RecipientEntry[] = useMemo(() => {
    if (source === 'paste') return parseRecipients(pasteText);
    if (source === 'csv') return parseWaCsv(csvText).rows;
    return [];
  }, [source, pasteText, csvText]);

  const recipientCount = source === 'list'
    ? (lists.find((l) => String(l.id) === listId)?.member_count ?? 0)
    : recipients.length;

  const setParam = (i: number, v: string) => setParams((p) => p.map((x, j) => (j === i ? v : x)));
  const insertToken = (token: string) => {
    if (params.length === 0) return;
    setParams((p) => p.map((x, j) => (j === activeParam ? x + token : x)));
  };

  const onCsvFile = (file?: File) => {
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      const text = String(reader.result || '');
      setCsvText(text);
      setCsvVariableNames(parseWaCsv(text).variableNames);
    };
    reader.readAsText(file);
  };

  const checkContent = (): boolean => {
    if (!template) { toast.error('Selecciona una plantilla aprobada.'); return false; }
    if (params.some((p) => p.trim() === '')) { toast.error('Completa todas las variables de la plantilla.'); return false; }
    if (needsMedia && !/^https:\/\/\S+$/.test(mediaUrl.trim())) {
      toast.error('Esta plantilla lleva un archivo de cabecera: indica su URL pública https.');
      return false;
    }
    return true;
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!checkContent() || !template) return;
    const payload: api.WaSendPayload = {
      name: name.trim() || undefined,
      templateId: template.id,
      params,
      headerMediaUrl: needsMedia ? mediaUrl.trim() : undefined,
      tags: tags.trim() || undefined,
      scheduleType,
    };
    if (scheduleType === 'scheduled') {
      if (!scheduledAt) { toast.error('Indica la fecha y hora de envío.'); return; }
      payload.scheduledAt = scheduledAt;
    }
    if (expiresAt) payload.expiresAt = expiresAt;
    if (source === 'list') {
      if (!listId) { toast.error('Selecciona una lista.'); return; }
      payload.listId = Number(listId);
    } else {
      if (recipients.length === 0) { toast.error('Indica al menos un destinatario.'); return; }
      payload.recipients = recipients;
    }

    setSending(true);
    try {
      const res = await api.sendWhatsApp(payload);
      if (res.mode === 'sent' && (res.result?.sent ?? 0) === 0) toast.error(res.hint ?? res.message);
      else toast.success(res.message);
      setName(autoName());
      setPasteText('');
      setCsvText('');
      setCsvVariableNames([]);
      setListId('');
      setTags('');
      setScheduleType('now');
      setScheduledAt('');
      setExpiresAt('');
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al enviar');
    } finally {
      setSending(false);
      refreshBalance();
    }
  };

  const sendTest = async () => {
    if (!testPhone.trim()) { toast.error('Indica un número de prueba.'); return; }
    if (!checkContent() || !template) return;
    setTestSending(true);
    try {
      const res = await api.sendWhatsAppTest({
        to: testPhone.trim(),
        templateId: template.id,
        params,
        headerMediaUrl: needsMedia ? mediaUrl.trim() : undefined,
      });
      if (res.success) {
        toast.success(res.message);
        setTestOpen(false);
      } else {
        toast.error(res.hint ?? res.message);
      }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al enviar la prueba');
    } finally {
      setTestSending(false);
      refreshBalance();
    }
  };

  if (!configured) {
    return (
      <div className="p-6">
        <div className="flex items-start gap-3 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
          <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
          <div>
            <p className="font-medium">La línea de WhatsApp todavía no está activada.</p>
            <p className="mt-1">
              Contacta a tu proveedor para activarla. El estado se ve en la pestaña{' '}
              <button type="button" onClick={goToLine} className="font-medium underline">Línea</button>.
            </p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <form onSubmit={submit} className="grid grid-cols-1 gap-6 p-6 lg:grid-cols-3">
      <div className="space-y-5 lg:col-span-2">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label htmlFor="waName" className={labelClass}>Nombre de la campaña</label>
            <input id="waName" type="text" value={name} onChange={(e) => setName(e.target.value)} className={inputClass} />
          </div>
          <div>
            <label htmlFor="waTemplate" className={labelClass}>Plantilla *</label>
            <select id="waTemplate" value={templateId} onChange={(e) => setTemplateId(e.target.value)} className={inputClass}>
              <option value="">{approved.length ? 'Selecciona una plantilla' : 'No hay plantillas aprobadas'}</option>
              {approved.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.element_name} ({t.language} · {CATEGORY_LABELS[t.category] ?? t.category})
                </option>
              ))}
            </select>
            {approved.length === 0 && (
              <p className="mt-1 text-xs text-gray-500">
                Crea o sincroniza plantillas en{' '}
                <button type="button" onClick={goToTemplates} className="font-medium text-green-700 underline">Plantillas</button>.
              </p>
            )}
          </div>
        </div>

        {template && template.var_count > 0 && (
          <fieldset className="space-y-3 rounded-md border border-gray-200 p-4">
            <legend className="px-1 text-sm font-medium text-gray-700">Variables de la plantilla</legend>
            <div className="flex flex-wrap items-center gap-1.5">
              <Sparkles className="h-3.5 w-3.5 text-gray-400" aria-hidden="true" />
              <span className="text-xs text-gray-500">Insertar en la variable {activeParam + 1}:</span>
              {fields.map((f) => (
                <button
                  key={f.token}
                  type="button"
                  onClick={() => insertToken(f.token)}
                  title={f.label}
                  className="rounded border border-gray-300 bg-white px-2 py-0.5 font-mono text-xs text-green-700 hover:bg-green-50"
                >
                  {f.token}
                </button>
              ))}
              {csvVariableNames.filter((v) => v !== 'nombre').map((v) => (
                <button
                  key={v}
                  type="button"
                  onClick={() => insertToken(`{{${v}}}`)}
                  title={`Columna "${v}" del CSV`}
                  className="rounded border border-green-300 bg-green-50 px-2 py-0.5 font-mono text-xs text-green-700 hover:bg-green-100"
                >
                  {`{{${v}}}`}
                </button>
              ))}
            </div>
            {params.map((p, i) => (
              <div key={i}>
                <label htmlFor={`waParam${i}`} className="mb-1 block text-xs font-medium text-gray-600">{`{{${i + 1}}}`}</label>
                <input
                  id={`waParam${i}`}
                  type="text"
                  value={p}
                  onFocus={() => setActiveParam(i)}
                  onChange={(e) => setParam(i, e.target.value)}
                  className={inputClass}
                  placeholder="Texto fijo o un campo, p.ej. {{nombre}}"
                />
              </div>
            ))}
          </fieldset>
        )}

        {needsMedia && (
          <div>
            <label htmlFor="waMedia" className={labelClass}>URL del archivo de cabecera ({template?.template_type.toLowerCase()}) *</label>
            <input
              id="waMedia"
              type="url"
              value={mediaUrl}
              onChange={(e) => setMediaUrl(e.target.value)}
              className={inputClass}
              placeholder="https://..."
            />
            <p className="mt-1 text-xs text-gray-500">Debe ser una URL pública https accesible por WhatsApp.</p>
          </div>
        )}

        <div>
          <span className={labelClass}>
            <Users className="mr-1 inline h-3.5 w-3.5 text-gray-400" aria-hidden="true" /> DESTINATARIOS
          </span>
          <div className="mb-3 inline-flex rounded-md border border-gray-300 p-0.5" role="group" aria-label="Origen de los destinatarios">
            {([['paste', 'Pegar'], ['csv', 'CSV'], ['list', 'Lista guardada']] as const).map(([key, label]) => (
              <button
                key={key}
                type="button"
                aria-pressed={source === key}
                onClick={() => setSource(key)}
                className={`rounded px-3 py-1.5 text-xs font-medium transition-colors ${
                  source === key ? 'bg-green-600 text-white' : 'text-gray-600 hover:bg-gray-100'
                }`}
              >
                {label}
              </button>
            ))}
          </div>

          {source === 'paste' && (
            <>
              <textarea
                rows={3}
                value={pasteText}
                onChange={(e) => setPasteText(e.target.value)}
                className={inputClass}
                aria-label="Números de teléfono"
                placeholder="Un número por línea o separados por comas (+573001234567 o 3001234567)"
              />
              <p className="mt-1 text-xs text-gray-500">{recipients.length} destinatario(s) detectado(s)</p>
            </>
          )}
          {source === 'csv' && (
            <>
              <div className="mb-2 flex flex-wrap gap-2 text-xs">
                <button
                  type="button"
                  onClick={() => downloadCsv('plantilla-whatsapp.csv', [
                    ['telefono', 'nombre', 'valor'],
                    ['+573001112233', 'Ana Pérez', '150000'],
                    ['+573004445566', 'Carlos Gómez', '98500'],
                  ])}
                  className="inline-flex items-center gap-1 rounded-md border border-gray-300 px-2.5 py-1.5 font-medium text-gray-600 hover:bg-gray-50"
                >
                  <Download className="h-3.5 w-3.5" aria-hidden="true" /> Plantilla CSV con variables
                </button>
              </div>
              <input type="file" accept=".csv,text/csv" aria-label="Archivo CSV" onChange={(e) => onCsvFile(e.target.files?.[0])} className="text-sm" />
              {csvText && (
                <p className="mt-1 text-xs text-gray-500">
                  {recipients.length} destinatario(s)
                  {csvVariableNames.length > 0 && ` · columnas: ${csvVariableNames.map((v) => `{{${v}}}`).join(', ')}`}
                </p>
              )}
            </>
          )}
          {source === 'list' && (
            <select value={listId} onChange={(e) => setListId(e.target.value)} className={inputClass} aria-label="Lista de contactos">
              <option value="">Selecciona una lista</option>
              {lists.map((l) => <option key={l.id} value={l.id}>{l.name} ({l.member_count})</option>)}
            </select>
          )}
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label htmlFor="waTags" className={labelClass}>
              <Tag className="mr-1 inline h-3.5 w-3.5 text-gray-400" aria-hidden="true" /> Etiquetas
            </label>
            <input id="waTags" type="text" value={tags} onChange={(e) => setTags(e.target.value)} className={inputClass} placeholder="promo, octubre" />
          </div>
          <div>
            <label htmlFor="waSchedule" className={labelClass}>
              <Clock className="mr-1 inline h-3.5 w-3.5 text-gray-400" aria-hidden="true" /> Envío
            </label>
            <select id="waSchedule" value={scheduleType} onChange={(e) => setScheduleType(e.target.value as 'now' | 'scheduled')} className={inputClass}>
              <option value="now">Enviar ahora</option>
              <option value="scheduled">Programar</option>
            </select>
          </div>
          {scheduleType === 'scheduled' && (
            <div>
              <label htmlFor="waScheduledAt" className={labelClass}>Fecha y hora de envío *</label>
              <input id="waScheduledAt" type="datetime-local" value={scheduledAt} onChange={(e) => setScheduledAt(e.target.value)} className={inputClass} />
            </div>
          )}
          <div>
            <label htmlFor="waExpires" className={labelClass}>
              <CalendarX className="mr-1 inline h-3.5 w-3.5 text-gray-400" aria-hidden="true" /> Caduca el (opcional)
            </label>
            <input id="waExpires" type="datetime-local" value={expiresAt} onChange={(e) => setExpiresAt(e.target.value)} className={inputClass} />
          </div>
        </div>

        <div className="flex flex-wrap items-center justify-end gap-3 border-t border-gray-200 pt-5">
          <button
            type="button"
            onClick={() => setTestOpen((v) => !v)}
            className="inline-flex items-center rounded-md border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            <TestTube2 className="mr-2 h-4 w-4" aria-hidden="true" /> Enviar prueba
          </button>
          <button
            type="submit"
            disabled={sending}
            className="inline-flex items-center rounded-md bg-green-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-green-700 disabled:opacity-50"
          >
            {sending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden="true" /> : <Send className="mr-2 h-4 w-4" aria-hidden="true" />}
            {sending ? 'Enviando...' : scheduleType === 'scheduled' ? 'Programar envío' : 'Enviar WhatsApp'}
          </button>
        </div>

        {testOpen && (
          <div className="flex flex-wrap items-end gap-3 rounded-md border border-gray-200 bg-gray-50 p-4">
            <div className="flex-1">
              <label htmlFor="waTestPhone" className={labelClass}>Número para la prueba</label>
              <input id="waTestPhone" type="tel" value={testPhone} onChange={(e) => setTestPhone(e.target.value)} className={inputClass} placeholder="+573001234567" />
            </div>
            <button
              type="button"
              onClick={sendTest}
              disabled={testSending}
              className="inline-flex items-center rounded-md bg-gray-800 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-900 disabled:opacity-50"
            >
              {testSending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden="true" /> : <TestTube2 className="mr-2 h-4 w-4" aria-hidden="true" />}
              Enviar prueba
            </button>
          </div>
        )}
      </div>

      <div className="space-y-4 lg:col-span-1">
        <div className="rounded-lg border border-gray-200 p-4">
          <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-700">
            <Smartphone className="h-4 w-4 text-gray-400" aria-hidden="true" /> Previsualización
          </h3>
          <WaPreview template={template} params={params} mediaUrl={mediaUrl} />
        </div>
        <div className="rounded-lg border border-gray-200 p-4 text-sm">
          <h3 className="mb-2 flex items-center gap-2 font-semibold text-gray-700">
            <Users className="h-4 w-4 text-gray-400" aria-hidden="true" /> Resumen
          </h3>
          <dl className="space-y-1.5">
            <div className="flex justify-between">
              <dt className="text-gray-500">Destinatarios</dt>
              <dd className="font-medium text-gray-900">{recipientCount}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-gray-500">Categoría</dt>
              <dd className="font-medium text-gray-900">{template ? CATEGORY_LABELS[template.category] ?? template.category : '-'}</dd>
            </div>
          </dl>
          <p className="pt-2 text-xs text-gray-400">
            Cada mensaje se descuenta del saldo según la categoría de la plantilla. Si WhatsApp no lo entrega, se devuelve.
          </p>
        </div>
      </div>
    </form>
  );
};

// --------------------------------------------------------- Respuestas -----

const Replies: React.FC = () => {
  const [rows, setRows] = useState<api.WaReply[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(false);
  const perPage = 25;

  useEffect(() => {
    setLoading(true);
    api.listWaReplies({ page, perPage, q: search || undefined })
      .then((r) => { setRows(r.replies); setTotal(r.total); })
      .catch((err) => toast.error(err instanceof Error ? err.message : 'Error'))
      .finally(() => setLoading(false));
  }, [page, search]);

  const pages = Math.max(1, Math.ceil(total / perPage));

  return (
    <div className="space-y-4 p-6">
      <form
        onSubmit={(e) => { e.preventDefault(); setPage(1); setSearch(q.trim()); }}
        className="flex max-w-md gap-2"
        role="search"
      >
        <input type="search" value={q} onChange={(e) => setQ(e.target.value)} className={inputClass} placeholder="Buscar por número, nombre o texto" aria-label="Buscar respuestas" />
        <button type="submit" className="rounded-md border border-gray-300 px-3 text-gray-600 hover:bg-gray-50" aria-label="Buscar">
          <Search className="h-4 w-4" aria-hidden="true" />
        </button>
      </form>

      {loading ? (
        <p className="flex items-center text-sm text-gray-500"><Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden="true" /> Cargando...</p>
      ) : rows.length === 0 ? (
        <p className="text-sm text-gray-500">Aún no hay respuestas.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th scope="col" className="px-3 py-2 text-left font-medium text-gray-600">Fecha</th>
                <th scope="col" className="px-3 py-2 text-left font-medium text-gray-600">Número</th>
                <th scope="col" className="px-3 py-2 text-left font-medium text-gray-600">Contacto</th>
                <th scope="col" className="px-3 py-2 text-left font-medium text-gray-600">Mensaje</th>
                <th scope="col" className="px-3 py-2 text-left font-medium text-gray-600">Campaña</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {rows.map((r) => (
                <tr key={r.id}>
                  <td className="whitespace-nowrap px-3 py-2 text-gray-500">{new Date(r.received_at.replace(' ', 'T')).toLocaleString('es-CO')}</td>
                  <td className="whitespace-nowrap px-3 py-2 font-mono text-gray-800">+{r.phone}</td>
                  <td className="px-3 py-2 text-gray-700">{r.contact_name ?? r.profile_name ?? '-'}</td>
                  <td className="px-3 py-2 text-gray-800">
                    <span className="whitespace-pre-wrap break-words">{r.message}</span>
                    {r.is_optout && <span className="ml-2 rounded bg-red-100 px-1.5 py-0.5 text-xs font-medium text-red-700">Baja</span>}
                  </td>
                  <td className="px-3 py-2 text-gray-500">{r.campaign_name ?? '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {pages > 1 && (
        <div className="flex items-center justify-end gap-2 text-sm">
          <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} className="rounded border border-gray-300 px-3 py-1 disabled:opacity-50">Anterior</button>
          <span className="text-gray-500">Página {page} de {pages}</span>
          <button type="button" disabled={page >= pages} onClick={() => setPage((p) => p + 1)} className="rounded border border-gray-300 px-3 py-1 disabled:opacity-50">Siguiente</button>
        </div>
      )}
      <p className="text-xs text-gray-400">Quien responda SALIR, STOP o BAJA queda dado de baja automáticamente.</p>
    </div>
  );
};

// -------------------------------------------------------------- Línea -----

const QUALITY_LABELS: Record<string, string> = { GREEN: 'Alta', YELLOW: 'Media', RED: 'Baja' };
const QUALITY_COLORS: Record<string, string> = { GREEN: 'text-green-700', YELLOW: 'text-amber-600', RED: 'text-red-600' };

/** Conversaciones nuevas que Meta permite iniciar cada 24 h. */
const TIER_LABELS: Record<string, string> = {
  TIER_50: '50 al día',
  TIER_250: '250 al día',
  TIER_1K: '1.000 al día',
  TIER_2K: '2.000 al día',
  TIER_10K: '10.000 al día',
  TIER_100K: '100.000 al día',
  TIER_UNLIMITED: 'Sin límite',
};

const Line: React.FC<{ status: api.WaStatus | null; loading: boolean; isAdmin: boolean; refresh: () => void }> = ({ status, loading, isAdmin, refresh }) => {
  if (!status) {
    return <p className="flex items-center p-6 text-sm text-gray-500"><Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden="true" /> Consultando la línea...</p>;
  }

  const copyLink = () => {
    if (!status.onboardingLink) return;
    navigator.clipboard.writeText(status.onboardingLink).then(() => toast.success('Enlace copiado'), () => toast.error('No se pudo copiar'));
  };

  const tierLabel = status.messagingLimit ? TIER_LABELS[status.messagingLimit] ?? null : null;

  return (
    <div className="space-y-6 p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          {status.configured && status.healthy
            ? <CheckCircle2 className="h-5 w-5 text-green-600" aria-hidden="true" />
            : <AlertTriangle className="h-5 w-5 text-amber-500" aria-hidden="true" />}
          <span className="font-medium text-gray-900">
            {lineLabel(status)}
          </span>
        </div>
        {status.configured && (
          <button
            type="button"
            onClick={refresh}
            disabled={loading}
            className="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
          >
            <RefreshCw className={`mr-2 h-4 w-4 ${loading ? 'animate-spin' : ''}`} aria-hidden="true" /> Actualizar
          </button>
        )}
      </div>

      {status.configured && (status.displayName || status.phone) && (
        <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="rounded-md border border-gray-200 p-4">
            <dt className="text-xs text-gray-500">Nombre visible</dt>
            <dd className="mt-1 text-lg font-semibold text-gray-900">{status.displayName ?? 'Pendiente de aprobación'}</dd>
            <dd className="mt-1 text-xs text-gray-500">Es el nombre que ven tus clientes en WhatsApp.</dd>
          </div>
          <div className="rounded-md border border-gray-200 p-4">
            <dt className="text-xs text-gray-500">Número de la línea</dt>
            <dd className="mt-1 text-lg font-semibold text-gray-900">{status.phone ? `+${status.phone}` : '-'}</dd>
          </div>
        </dl>
      )}

      {status.configured && (
        <dl className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div className="rounded-md border border-gray-200 p-4">
            <dt className="text-xs text-gray-500">Calidad del número</dt>
            <dd className={`mt-1 text-lg font-semibold ${status.quality ? QUALITY_COLORS[status.quality] ?? 'text-gray-900' : 'text-gray-900'}`}>
              {status.quality ? QUALITY_LABELS[status.quality] ?? status.quality : 'Sin calificar'}
            </dd>
            <dd className="mt-1 text-xs text-gray-500">
              {status.quality
                ? 'La calcula Meta con los reportes y bloqueos de tus clientes.'
                : 'Meta la asigna cuando el número empieza a recibir mensajes.'}
            </dd>
          </div>
          <div className="rounded-md border border-gray-200 p-4">
            <dt className="text-xs text-gray-500">Límite diario de mensajes</dt>
            <dd className="mt-1 text-lg font-semibold text-gray-900">{tierLabel ?? 'Sin asignar'}</dd>
            <dd className="mt-1 text-xs text-gray-500">
              {tierLabel
                ? 'Conversaciones nuevas que puedes iniciar cada 24 horas. Sube solo con el uso.'
                : 'Meta lo asigna al aprobar el nombre visible y empezar a enviar.'}
            </dd>
          </div>
          <div className="rounded-md border border-gray-200 p-4">
            <dt className="text-xs text-gray-500">Webhook (estados y respuestas)</dt>
            <dd className="mt-1 text-lg font-semibold text-gray-900">{status.webhookConfigured ? 'Registrado' : 'Pendiente'}</dd>
            {!status.webhookConfigured && (
              <dd className="mt-1 text-xs text-gray-500">Sin esto no llegan estados de entrega ni respuestas.</dd>
            )}
          </div>
        </dl>
      )}

      {status.canSendMessage === 'BLOCKED' && (
        <p className="text-sm text-red-600">Meta bloqueó el envío desde esta línea. Contacta a tu proveedor.</p>
      )}
      {status.canSendMessage === 'LIMITED' && (
        <p className="text-sm text-amber-700">Meta limitó temporalmente el envío desde esta línea.</p>
      )}
      {status.healthMessage && (
        <p className={`text-sm ${status.pendingSignup ? 'text-amber-700' : 'text-red-600'}`}>
          {status.healthMessage}
          {status.healthDetail && <span className="ml-2 text-xs text-gray-400">({status.healthDetail})</span>}
        </p>
      )}
      {status.checkedAt && <p className="text-xs text-gray-400">Última comprobación: {new Date(status.checkedAt).toLocaleString('es-CO')}</p>}

      {isAdmin && (status.lineNotes?.length ?? 0) > 0 && (
        <div className="rounded-md border border-gray-200 bg-gray-50 p-4 text-xs text-gray-600">
          <p className="font-medium text-gray-800">Avisos de Meta (soporte)</p>
          <ul className="mt-1 list-disc space-y-1 pl-4">
            {status.lineNotes?.map((n) => <li key={n}>{n}</li>)}
          </ul>
        </div>
      )}

      {/* El enlace de registro solo sirve antes de activar la línea. */}
      {isAdmin && !status.healthy && status.onboardingLink && (
        <div className="rounded-md border border-green-200 bg-green-50 p-4 text-sm text-green-900">
          <p className="font-medium">Registro del número en WhatsApp Business</p>
          <p className="mt-1">
            Abre este enlace con la cuenta de Facebook que administra el negocio, elige o crea el portafolio comercial y
            verifica el número. El enlace vence a los 5 días.
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            <a
              href={status.onboardingLink}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center rounded-md bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700"
            >
              <ExternalLink className="mr-1.5 h-4 w-4" aria-hidden="true" /> Abrir registro
            </a>
            <button type="button" onClick={copyLink} className="inline-flex items-center rounded-md border border-green-300 bg-white px-3 py-1.5 text-sm font-medium text-green-800 hover:bg-green-100">
              <Copy className="mr-1.5 h-4 w-4" aria-hidden="true" /> Copiar enlace
            </button>
          </div>
        </div>
      )}

      {!status.configured && (
        <p className="text-sm text-gray-600">Para activar la línea de WhatsApp, contacta a tu proveedor.</p>
      )}
    </div>
  );
};

// ------------------------------------------------------------- Perfil -----

const VERTICAL_LABELS: Record<string, string> = {
  OTHER: 'Otro', AUTO: 'Automotriz', BEAUTY: 'Belleza, spa y salón', APPAREL: 'Ropa y accesorios',
  EDU: 'Educación', ENTERTAIN: 'Entretenimiento', EVENT_PLAN: 'Eventos', FINANCE: 'Finanzas y banca',
  GROCERY: 'Supermercado', GOVT: 'Servicios públicos y gobierno', HOTEL: 'Hotel y alojamiento',
  HEALTH: 'Medicina y salud', NONPROFIT: 'Sin ánimo de lucro', PROF_SERVICES: 'Servicios profesionales',
  RETAIL: 'Comercio', TRAVEL: 'Viajes y transporte', RESTAURANT: 'Restaurante',
};

type ProfileForm = Record<'about' | 'description' | 'address' | 'email' | 'website1' | 'website2' | 'vertical', string>;

const toForm = (p: api.WaProfile): ProfileForm => ({
  about: p.about ?? '', description: p.description ?? '', address: p.address ?? '', email: p.email ?? '',
  website1: p.website1 ?? '', website2: p.website2 ?? '', vertical: p.vertical ?? '',
});

const Profile: React.FC<{ pendingSignup: boolean }> = ({ pendingSignup }) => {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [original, setOriginal] = useState<ProfileForm | null>(null);
  const [form, setForm] = useState<ProfileForm | null>(null);
  const [verticals, setVerticals] = useState<string[]>([]);
  const [photoUrl, setPhotoUrl] = useState<string | null>(null);
  const [photoFile, setPhotoFile] = useState<File | null>(null);
  const [photoPreview, setPhotoPreview] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    api.getWaProfile()
      .then((r) => {
        const f = toForm(r.profile);
        setOriginal(f);
        setForm(f);
        setVerticals(r.verticals);
        setPhotoUrl(r.profile.photoUrl);
      })
      .catch((e) => setError(e instanceof Error ? e.message : 'No se pudo cargar el perfil'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    if (!photoFile) { setPhotoPreview(null); return; }
    const url = URL.createObjectURL(photoFile);
    setPhotoPreview(url);
    return () => URL.revokeObjectURL(url);
  }, [photoFile]);

  const onPhoto = (file?: File) => {
    if (!file) return;
    if (!['image/jpeg', 'image/png'].includes(file.type)) { toast.error('Usa una imagen JPG o PNG.'); return; }
    if (file.size > 5 * 1024 * 1024) { toast.error('La imagen supera 5 MB.'); return; }
    setPhotoFile(file);
  };

  const uploadPhoto = async () => {
    if (!photoFile) return;
    setUploading(true);
    try {
      const r = await api.uploadWaProfilePhoto(photoFile);
      toast.success(r.message);
      setPhotoUrl(photoPreview);
      setPhotoFile(null);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'No se pudo subir la foto');
    } finally {
      setUploading(false);
    }
  };

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form || !original) return;
    if (form.about.trim() === '') { toast.error('La información ("Info") es obligatoria.'); return; }
    // Solo se envían los campos que cambiaron.
    const changes: api.WaProfileInput = {};
    (Object.keys(form) as (keyof ProfileForm)[]).forEach((k) => {
      if (form[k].trim() !== original[k].trim()) changes[k] = form[k].trim();
    });
    if (Object.keys(changes).length === 0) { toast('No hay cambios.'); return; }
    setSaving(true);
    try {
      const r = await api.updateWaProfile(changes);
      toast.success(r.message);
      const f = r.profile ? toForm(r.profile) : { ...form };
      setOriginal(f);
      setForm(f);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'No se pudo guardar');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return <p className="flex items-center p-6 text-sm text-gray-500"><Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden="true" /> Cargando el perfil...</p>;
  }
  if (error || !form) {
    return (
      <div className="p-6">
        <div className="flex items-start gap-3 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
          <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
          <div>
            <p className="font-medium">{pendingSignup ? 'El perfil se puede editar cuando el número esté registrado en WhatsApp.' : error}</p>
            <button type="button" onClick={load} className="mt-2 text-sm font-medium underline">Reintentar</button>
          </div>
        </div>
      </div>
    );
  }

  const set = (k: keyof ProfileForm) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
    setForm({ ...form, [k]: e.target.value });
  const shownPhoto = photoPreview ?? photoUrl;

  return (
    <div className="grid grid-cols-1 gap-6 p-6 lg:grid-cols-3">
      <form onSubmit={save} className="space-y-5 lg:col-span-2">
        <div className="flex flex-wrap items-center gap-4 rounded-md border border-gray-200 p-4">
          <div className="h-20 w-20 shrink-0 overflow-hidden rounded-full bg-gray-100">
            {shownPhoto
              ? <img src={shownPhoto} alt="Foto de perfil de WhatsApp" className="h-full w-full object-cover" />
              : <div className="flex h-full w-full items-center justify-center text-gray-400"><Building2 className="h-8 w-8" aria-hidden="true" /></div>}
          </div>
          <div className="min-w-0 flex-1 space-y-2">
            <p className="text-sm font-medium text-gray-900">Foto de perfil</p>
            <p className="text-xs text-gray-500">JPG o PNG cuadrada, mínimo 192×192 px (recomendado 640×640), máximo 5 MB. WhatsApp la recorta en círculo.</p>
            <div className="flex flex-wrap items-center gap-2">
              <label className="inline-flex cursor-pointer items-center rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 focus-within:ring-2 focus-within:ring-green-600">
                <Upload className="mr-1.5 h-4 w-4" aria-hidden="true" /> Elegir imagen
                <input type="file" accept="image/jpeg,image/png" className="sr-only" onChange={(e) => { onPhoto(e.target.files?.[0]); e.target.value = ''; }} />
              </label>
              {photoFile && (
                <>
                  <button
                    type="button"
                    onClick={uploadPhoto}
                    disabled={uploading}
                    className="inline-flex items-center rounded-md bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                  >
                    {uploading && <Loader2 className="mr-1.5 h-4 w-4 animate-spin" aria-hidden="true" />} Subir foto
                  </button>
                  <button type="button" onClick={() => setPhotoFile(null)} className="text-sm text-gray-500 hover:underline">Descartar</button>
                </>
              )}
            </div>
          </div>
        </div>

        <div>
          <label htmlFor="waAbout" className={labelClass}>Info *</label>
          <input id="waAbout" type="text" maxLength={139} value={form.about} onChange={set('about')} className={inputClass} placeholder="Atención de lunes a viernes, 8 a. m. a 6 p. m." />
          <p className="mt-1 text-right text-xs text-gray-500">{form.about.length}/139 · se ve bajo el nombre del negocio</p>
        </div>
        <div>
          <label htmlFor="waDesc" className={labelClass}>Descripción</label>
          <textarea id="waDesc" rows={4} maxLength={512} value={form.description} onChange={set('description')} className={inputClass} placeholder="A qué se dedica la empresa." />
          <p className="mt-1 text-right text-xs text-gray-500">{form.description.length}/512</p>
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label htmlFor="waVertical" className={labelClass}>Categoría</label>
            <select id="waVertical" value={form.vertical} onChange={set('vertical')} className={inputClass}>
              <option value="">Sin categoría</option>
              {verticals.map((v) => <option key={v} value={v}>{VERTICAL_LABELS[v] ?? v}</option>)}
            </select>
          </div>
          <div>
            <label htmlFor="waEmail" className={labelClass}>Email</label>
            <input id="waEmail" type="email" maxLength={128} value={form.email} onChange={set('email')} className={inputClass} placeholder="contacto@empresa.com" />
          </div>
        </div>
        <div>
          <label htmlFor="waAddress" className={labelClass}>Dirección</label>
          <input id="waAddress" type="text" maxLength={256} value={form.address} onChange={set('address')} className={inputClass} placeholder="Calle 00 # 00-00, Bogotá" />
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label htmlFor="waWeb1" className={labelClass}>Sitio web</label>
            <input id="waWeb1" type="url" maxLength={256} value={form.website1} onChange={set('website1')} className={inputClass} placeholder="https://empresa.com" />
          </div>
          <div>
            <label htmlFor="waWeb2" className={labelClass}>Segundo sitio web</label>
            <input id="waWeb2" type="url" maxLength={256} value={form.website2} onChange={set('website2')} className={inputClass} placeholder="https://..." />
          </div>
        </div>

        <div className="rounded-md border border-gray-200 bg-gray-50 p-3 text-xs text-gray-600">
          <p className="font-medium text-gray-800">Nombre visible</p>
          <p className="mt-1">
            El nombre que ven los clientes lo revisa Meta. Se cambia en{' '}
            <a href="https://business.facebook.com/wa/manage/phone-numbers/" target="_blank" rel="noopener noreferrer" className="font-medium text-green-700 underline">
              WhatsApp Manager
            </a>{' '}
            → Números de teléfono → Editar nombre visible (la revisión tarda de 1 a 3 días).
          </p>
        </div>

        <div className="flex justify-end gap-3 border-t border-gray-200 pt-4">
          <button type="button" onClick={() => original && setForm(original)} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
            Deshacer cambios
          </button>
          <button type="submit" disabled={saving} className="inline-flex items-center rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50">
            {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden="true" />} Guardar perfil
          </button>
        </div>
      </form>

      {/* Vista previa del perfil como lo ve el cliente */}
      <div className="lg:col-span-1">
        <div className="rounded-lg border border-gray-200 p-4">
          <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-700">
            <Smartphone className="h-4 w-4 text-gray-400" aria-hidden="true" /> Así se verá
          </h3>
          <div className="mx-auto max-w-[280px] overflow-hidden rounded-2xl border border-gray-200 bg-white text-sm shadow">
            <div className="flex flex-col items-center bg-gray-50 px-4 py-5">
              <div className="h-20 w-20 overflow-hidden rounded-full bg-gray-200">
                {shownPhoto
                  ? <img src={shownPhoto} alt="" className="h-full w-full object-cover" />
                  : <div className="flex h-full w-full items-center justify-center text-gray-400"><Building2 className="h-8 w-8" aria-hidden="true" /></div>}
              </div>
              <p className="mt-2 font-semibold text-gray-900">Tu empresa</p>
              <p className="text-xs text-gray-500">Cuenta de empresa</p>
            </div>
            <div className="space-y-2 px-4 py-3 text-gray-700">
              {form.about && <p className="text-gray-800">{form.about}</p>}
              {form.description && <p className="whitespace-pre-wrap text-xs text-gray-600">{form.description}</p>}
              {form.vertical && <p className="text-xs text-gray-500">{VERTICAL_LABELS[form.vertical] ?? form.vertical}</p>}
              {form.address && <p className="flex items-start gap-1.5 text-xs"><MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" aria-hidden="true" />{form.address}</p>}
              {form.email && <p className="flex items-center gap-1.5 text-xs"><Mail className="h-3.5 w-3.5 text-gray-400" aria-hidden="true" />{form.email}</p>}
              {[form.website1, form.website2].filter(Boolean).map((w) => (
                <p key={w} className="flex items-center gap-1.5 truncate text-xs text-[#00A5F4]"><Globe className="h-3.5 w-3.5 shrink-0 text-gray-400" aria-hidden="true" />{w}</p>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default WhatsAppModule;
