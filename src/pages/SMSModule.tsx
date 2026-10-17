import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  Send, Loader2, Users, Tag, Clock, CalendarX, FileText, Trash2,
  History, DollarSign, TestTube2, ChevronDown, Smartphone, Sparkles, Download,
} from 'lucide-react';
import toast from 'react-hot-toast';
import * as api from '../services/api';
import {
  type RecipientEntry, parseRecipients, parseRecipientsCsv, downloadCsv, PHONE_HEADERS,
} from '../utils/csvRecipients';

type Tab = 'compose' | 'templates';
type RecipientSource = 'paste' | 'csv' | 'list';

const parseSmsCsv = (text: string) => parseRecipientsCsv(text, PHONE_HEADERS, 'phone');

/** Igual que el nombre que el backend asignaría si se deja en blanco. */
function autoName(): string {
  const d = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');
  return `SMS-${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}-${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

const inputClass =
  'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none';
const labelClass = 'block text-sm font-medium text-gray-700 mb-1';

/** Mockup de teléfono para previsualizar cómo se verá el SMS. */
const PhonePreview: React.FC<{ sender: string; message: string }> = ({ sender, message }) => (
  <div className="mx-auto w-full max-w-[260px] rounded-[2rem] border-[6px] border-gray-900 bg-gray-900 shadow-xl">
    <div className="overflow-hidden rounded-[1.4rem] bg-white">
      <div className="flex items-center gap-2 border-b border-gray-100 bg-gray-50 px-3 py-2.5">
        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-white">
          {(sender || 'SMS').slice(0, 2).toUpperCase()}
        </div>
        <span className="truncate text-sm font-medium text-gray-900">{sender || 'Remitente'}</span>
      </div>
      <div className="min-h-[260px] space-y-2 bg-gray-100 p-3">
        <div className="max-w-[88%] whitespace-pre-wrap break-words rounded-2xl rounded-tl-sm bg-white px-3 py-2 text-sm text-gray-800 shadow-sm">
          {message || <span className="text-gray-400">Escribe tu mensaje para verlo aquí...</span>}
        </div>
      </div>
    </div>
  </div>
);

const SMSModule: React.FC = () => {
  const [tab, setTab] = useState<Tab>('compose');

  // --- Compositor ---
  const [name, setName] = useState(autoName());
  const [senderId, setSenderId] = useState('');
  const [message, setMessage] = useState('');
  const [tags, setTags] = useState('');
  const [source, setSource] = useState<RecipientSource>('paste');
  const [pasteText, setPasteText] = useState('');
  const [csvText, setCsvText] = useState('');
  const [listId, setListId] = useState('');
  const [lists, setLists] = useState<api.ContactList[]>([]);
  const [scheduleType, setScheduleType] = useState<'now' | 'scheduled'>('now');
  const [scheduledAt, setScheduledAt] = useState('');
  const [expiresAt, setExpiresAt] = useState('');
  const [sending, setSending] = useState(false);
  const [showPersonalization, setShowPersonalization] = useState(false);
  const [showPrevious, setShowPrevious] = useState(false);

  const [fields, setFields] = useState<api.PersonalizationField[]>([]);
  const [previous, setPrevious] = useState<api.PreviousMessage[]>([]);

  const [analysis, setAnalysis] = useState<api.SmsAnalysis | null>(null);
  const [cost, setCost] = useState<api.SendCost | null>(null);

  const [testOpen, setTestOpen] = useState(false);
  const [testPhone, setTestPhone] = useState('');
  const [testSending, setTestSending] = useState(false);

  // --- Plantillas ---
  const [templates, setTemplates] = useState<api.Template[]>([]);
  const [tplForm, setTplForm] = useState({ name: '', body: '' });

  const textareaRef = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    api.listLists().then((r) => setLists(r.lists)).catch(() => {});
    api.smsFields().then((r) => { setFields(r.fields); setPrevious(r.previous); }).catch(() => {});
    refreshTemplates();
  }, []);

  const refreshTemplates = () => {
    api.listTemplates('sms').then((r) => setTemplates(r.templates)).catch(() => {});
  };

  const [csvVariableNames, setCsvVariableNames] = useState<string[]>([]);

  const pastedRecipients: RecipientEntry[] = useMemo(() => {
    if (source === 'paste') return parseRecipients(pasteText);
    if (source === 'csv') return parseSmsCsv(csvText).rows;
    return [];
  }, [source, pasteText, csvText]);

  const recipientCount = source === 'list'
    ? (lists.find((l) => String(l.id) === listId)?.member_count ?? 0)
    : pastedRecipients.length;

  // Segmentos y coste estimado, recalculados mientras se escribe (con un
  // pequeño retraso para no llamar al servidor en cada tecla).
  useEffect(() => {
    if (!message.trim()) { setAnalysis(null); setCost(null); return; }
    const id = window.setTimeout(() => {
      api.smsEstimate({ message, recipients: recipientCount })
        .then((r) => { setAnalysis(r.analysis); setCost(r.cost); })
        .catch(() => {});
    }, 350);
    return () => window.clearTimeout(id);
  }, [message, recipientCount]);

  const insertToken = (token: string) => {
    const el = textareaRef.current;
    if (!el) { setMessage((m) => m + token); return; }
    const start = el.selectionStart ?? message.length;
    const end = el.selectionEnd ?? message.length;
    const next = message.slice(0, start) + token + message.slice(end);
    setMessage(next);
    requestAnimationFrame(() => {
      el.focus();
      el.selectionStart = el.selectionEnd = start + token.length;
    });
  };

  const onCsvFile = (file?: File) => {
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      const text = String(reader.result || '');
      setCsvText(text);
      setCsvVariableNames(parseSmsCsv(text).variableNames);
    };
    reader.readAsText(file);
  };

  const downloadSimpleTemplate = () => {
    downloadCsv('plantilla-sms-simple.csv', [
      ['telefono'],
      ['+573001112233'],
      ['+573004445566'],
    ]);
  };

  // Incluye una columna extra ("valor") a modo de ejemplo: deja claro que se
  // pueden añadir tantas columnas libres como se necesiten (fecha_pago,
  // placa, factura...), no solo nombre.
  const downloadVariablesTemplate = () => {
    downloadCsv('plantilla-sms-con-variables.csv', [
      ['telefono', 'nombre', 'valor'],
      ['+573001112233', 'Ana Pérez', '150000'],
      ['+573004445566', 'Carlos Gómez', '98500'],
    ]);
  };

  const resetAfterSend = () => {
    setName(autoName());
    setMessage('');
    setPasteText('');
    setCsvText('');
    setCsvVariableNames([]);
    setListId('');
    setTags('');
    setScheduleType('now');
    setScheduledAt('');
    setExpiresAt('');
    setAnalysis(null);
    setCost(null);
  };

  const validate = (): api.DispatchPayload | null => {
    if (!message.trim()) { toast.error('Escribe el mensaje.'); return null; }
    if (senderId.trim().length > 15) { toast.error('El remitente no puede superar 15 caracteres.'); return null; }

    const payload: api.DispatchPayload = {
      name: name.trim() || undefined,
      body: message,
      senderId: senderId.trim() || undefined,
      tags: tags.trim() || undefined,
      scheduleType,
    };

    if (scheduleType === 'scheduled') {
      if (!scheduledAt) { toast.error('Indica la fecha y hora de envío.'); return null; }
      payload.scheduledAt = scheduledAt;
    }
    if (expiresAt) payload.expiresAt = expiresAt;

    if (source === 'list') {
      if (!listId) { toast.error('Selecciona una lista.'); return null; }
      payload.listId = Number(listId);
    } else {
      if (pastedRecipients.length === 0) { toast.error('Indica al menos un destinatario.'); return null; }
      payload.recipients = pastedRecipients;
    }

    return payload;
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const payload = validate();
    if (!payload) return;

    setSending(true);
    try {
      const res = await api.sendSms(payload);
      if (res.mode === 'sent') {
        if ((res.result?.sent ?? 0) > 0) toast.success(res.message);
        else toast.error(res.hint ?? res.message);
      } else {
        toast.success(res.message);
      }
      resetAfterSend();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al enviar');
    } finally {
      setSending(false);
    }
  };

  const sendTest = async () => {
    if (!testPhone.trim()) { toast.error('Indica un número de prueba.'); return; }
    if (!message.trim()) { toast.error('Escribe el mensaje antes de probarlo.'); return; }

    setTestSending(true);
    try {
      const res = await api.sendSmsTest({
        to: testPhone.trim(),
        message,
        senderId: senderId.trim() || undefined,
      });
      if (res.success) {
        toast.success(res.message);
        setTestOpen(false);
        setTestPhone('');
      } else {
        toast.error(res.hint ?? res.message);
      }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al enviar la prueba');
    } finally {
      setTestSending(false);
    }
  };

  // --- Plantillas ---

  const createTemplate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!tplForm.name || !tplForm.body) {
      toast.error('Nombre y contenido son obligatorios');
      return;
    }
    try {
      await api.createTemplate({ name: tplForm.name, channel: 'sms', body: tplForm.body });
      toast.success('Plantilla creada');
      setTplForm({ name: '', body: '' });
      refreshTemplates();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error');
    }
  };

  const applyTemplate = (t: api.Template) => {
    setMessage(t.body);
    setTab('compose');
    toast.success(`Plantilla "${t.name}" cargada.`);
  };

  const removeTemplate = async (id: number) => {
    if (!window.confirm('¿Eliminar esta plantilla?')) return;
    try {
      await api.deleteTemplate(id);
      refreshTemplates();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error');
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

  const charCounterLabel = analysis
    ? `${analysis.length} caracteres · ${analysis.segments} SMS · ${analysis.encoding}`
    : `0 caracteres · 1 SMS · GSM-7`;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Módulo de SMS</h1>
        <p className="mt-1 text-sm text-gray-500">
          Crea tu mensaje y envíalo. Cada envío queda registrado como campaña en Reportes.
        </p>
      </div>

      <div className="overflow-hidden rounded-lg bg-white shadow">
        <nav className="flex border-b border-gray-200" aria-label="Secciones del módulo de SMS">
          {tabButton('compose', 'Compositor', Send)}
          {tabButton('templates', 'Plantillas', FileText)}
        </nav>

        {tab === 'compose' && (
          <form onSubmit={submit} className="grid grid-cols-1 gap-6 p-6 lg:grid-cols-3">
            {/* ---------------- Columna principal ---------------- */}
            <div className="space-y-5 lg:col-span-2">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <label htmlFor="campName" className={labelClass}>Nombre de la campaña</label>
                  <input
                    id="campName"
                    type="text"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    className={inputClass}
                  />
                </div>
                <div>
                  <label htmlFor="senderId" className={labelClass}>Remitente</label>
                  <input
                    id="senderId"
                    type="text"
                    maxLength={15}
                    value={senderId}
                    onChange={(e) => setSenderId(e.target.value)}
                    className={inputClass}
                    placeholder="Ej. ContactCC"
                  />
                  <p className="mt-1 text-right text-xs text-gray-500">{senderId.length}/15</p>
                </div>
              </div>

              <div>
                <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
                  <label htmlFor="message" className={labelClass + ' mb-0'}>Mensaje *</label>
                  <div className="flex items-center gap-2 text-xs">
                    <button
                      type="button"
                      onClick={() => setShowPersonalization((v) => !v)}
                      className="inline-flex items-center gap-1 rounded-md border border-gray-300 px-2 py-1 font-medium text-gray-600 hover:bg-gray-50"
                    >
                      <Sparkles className="h-3.5 w-3.5" /> Campos <ChevronDown className="h-3 w-3" />
                    </button>
                    {previous.length > 0 && (
                      <button
                        type="button"
                        onClick={() => setShowPrevious((v) => !v)}
                        className="inline-flex items-center gap-1 rounded-md border border-gray-300 px-2 py-1 font-medium text-gray-600 hover:bg-gray-50"
                      >
                        <History className="h-3.5 w-3.5" /> Anteriores <ChevronDown className="h-3 w-3" />
                      </button>
                    )}
                  </div>
                </div>

                {showPersonalization && (
                  <div className="mb-2 rounded-md border border-gray-200 bg-gray-50 p-2">
                    <div className="flex flex-wrap gap-1.5">
                      {fields.map((f) => (
                        <button
                          key={f.token}
                          type="button"
                          onClick={() => insertToken(f.token)}
                          title={f.label}
                          className="rounded border border-gray-300 bg-white px-2 py-1 text-xs font-mono text-blue-700 hover:bg-blue-50"
                        >
                          {f.token}
                        </button>
                      ))}
                      {csvVariableNames.filter((v) => v !== 'nombre').map((v) => (
                        <button
                          key={v}
                          type="button"
                          onClick={() => insertToken(`{{${v}}}`)}
                          title={`Columna "${v}" del CSV cargado`}
                          className="rounded border border-blue-300 bg-blue-50 px-2 py-1 text-xs font-mono text-blue-700 hover:bg-blue-100"
                        >
                          {`{{${v}}}`}
                        </button>
                      ))}
                    </div>
                    <p className="mt-1.5 text-xs text-gray-500">
                      También puedes usar cualquier columna de tu CSV como variable: si tu archivo
                      trae <code className="font-mono">valor</code>, <code className="font-mono">fecha_pago</code>,
                      etc., escribe <code className="font-mono">{'{{'}valor{'}}'}</code> en el mensaje y se
                      reemplazará por el dato de cada fila.
                    </p>
                  </div>
                )}

                {showPrevious && (
                  <div className="mb-2 max-h-40 overflow-y-auto rounded-md border border-gray-200">
                    {previous.map((p) => (
                      <button
                        key={p.id}
                        type="button"
                        onClick={() => { setMessage(p.body); setShowPrevious(false); }}
                        className="block w-full truncate border-b border-gray-100 px-3 py-2 text-left text-xs text-gray-700 last:border-0 hover:bg-gray-50"
                      >
                        {p.body}
                      </button>
                    ))}
                  </div>
                )}

                <textarea
                  id="message"
                  ref={textareaRef}
                  rows={5}
                  value={message}
                  onChange={(e) => setMessage(e.target.value)}
                  className={inputClass}
                  placeholder="Hola {{nombre}}, ... Responde STOP para no recibir más mensajes."
                />
                <div className="mt-1 flex items-center justify-between text-xs text-gray-500">
                  <span>{charCounterLabel}</span>
                  {analysis && analysis.encoding === 'UCS-2' && (
                    <span className="text-amber-600">Hay caracteres especiales: cuestan más segmentos.</span>
                  )}
                </div>
              </div>

              <div>
                <label className={labelClass}>
                  <Users className="mr-1 inline h-3.5 w-3.5 text-gray-400" /> DESTINATARIOS
                </label>
                <div className="mb-3 inline-flex rounded-md border border-gray-300 p-0.5">
                  {([['paste', 'Pegar'], ['csv', 'CSV'], ['list', 'Lista guardada']] as const).map(([key, label]) => (
                    <button
                      key={key}
                      type="button"
                      onClick={() => setSource(key)}
                      className={`rounded px-3 py-1.5 text-xs font-medium transition-colors ${
                        source === key ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'
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
                      placeholder="Un número por línea, o separados por comas (formato E.164: +573001234567)"
                    />
                    <p className="mt-1 text-xs text-gray-500">{pastedRecipients.length} destinatario(s) detectado(s)</p>
                  </>
                )}
                {source === 'csv' && (
                  <>
                    <div className="mb-2 flex flex-wrap gap-2 text-xs">
                      <button
                        type="button"
                        onClick={downloadSimpleTemplate}
                        className="inline-flex items-center gap-1 rounded-md border border-gray-300 px-2.5 py-1.5 font-medium text-gray-600 hover:bg-gray-50"
                      >
                        <Download className="h-3.5 w-3.5" /> Plantilla simple (sin variables)
                      </button>
                      <button
                        type="button"
                        onClick={downloadVariablesTemplate}
                        className="inline-flex items-center gap-1 rounded-md border border-gray-300 px-2.5 py-1.5 font-medium text-gray-600 hover:bg-gray-50"
                      >
                        <Download className="h-3.5 w-3.5" /> Plantilla con variables ({'{{'}nombre{'}}'})
                      </button>
                    </div>
                    <input type="file" accept=".csv,text/csv" onChange={(e) => onCsvFile(e.target.files?.[0])} className="text-sm" />
                    {csvText && (
                      <p className="mt-1 text-xs text-gray-500">
                        {pastedRecipients.length} destinatario(s) detectado(s)
                        {csvVariableNames.length > 0 && (
                          <span className="ml-1 inline-flex flex-wrap items-center gap-1 text-blue-600">
                            <Sparkles className="h-3 w-3" /> variables detectadas:{' '}
                            {csvVariableNames.map((v) => (
                              <code key={v} className="rounded bg-blue-50 px-1 py-0.5 font-mono">{'{{'}{v}{'}}'}</code>
                            ))}
                            — se personalizan por fila
                          </span>
                        )}
                      </p>
                    )}
                  </>
                )}
                {source === 'list' && (
                  <select value={listId} onChange={(e) => setListId(e.target.value)} className={inputClass}>
                    <option value="">Selecciona una lista</option>
                    {lists.map((l) => <option key={l.id} value={l.id}>{l.name} ({l.member_count})</option>)}
                  </select>
                )}
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <label htmlFor="tags" className={labelClass}>
                    <Tag className="mr-1 inline h-3.5 w-3.5 text-gray-400" /> Etiquetas
                  </label>
                  <input
                    id="tags"
                    type="text"
                    value={tags}
                    onChange={(e) => setTags(e.target.value)}
                    className={inputClass}
                    placeholder="promo, octubre"
                  />
                </div>
                <div>
                  <label htmlFor="scheduleType" className={labelClass}>
                    <Clock className="mr-1 inline h-3.5 w-3.5 text-gray-400" /> Envío
                  </label>
                  <select
                    id="scheduleType"
                    value={scheduleType}
                    onChange={(e) => setScheduleType(e.target.value as 'now' | 'scheduled')}
                    className={inputClass}
                  >
                    <option value="now">Enviar ahora</option>
                    <option value="scheduled">Programar</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {scheduleType === 'scheduled' && (
                  <div>
                    <label htmlFor="scheduledAt" className={labelClass}>Fecha y hora de envío *</label>
                    <input
                      id="scheduledAt"
                      type="datetime-local"
                      value={scheduledAt}
                      onChange={(e) => setScheduledAt(e.target.value)}
                      className={inputClass}
                    />
                  </div>
                )}
                <div>
                  <label htmlFor="expiresAt" className={labelClass}>
                    <CalendarX className="mr-1 inline h-3.5 w-3.5 text-gray-400" /> Caduca el (opcional)
                  </label>
                  <input
                    id="expiresAt"
                    type="datetime-local"
                    value={expiresAt}
                    onChange={(e) => setExpiresAt(e.target.value)}
                    className={inputClass}
                  />
                </div>
              </div>

              <div className="flex flex-wrap items-center justify-end gap-3 border-t border-gray-200 pt-5">
                <button
                  type="button"
                  onClick={() => setTestOpen((v) => !v)}
                  className="inline-flex items-center rounded-md border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                  <TestTube2 className="mr-2 h-4 w-4" /> Enviar prueba
                </button>
                <button
                  type="submit"
                  disabled={sending}
                  className="inline-flex items-center rounded-md bg-blue-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
                >
                  {sending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Send className="mr-2 h-4 w-4" />}
                  {sending ? 'Enviando...' : scheduleType === 'scheduled' ? 'Programar envío' : 'Enviar SMS'}
                </button>
              </div>

              {testOpen && (
                <div className="flex flex-wrap items-end gap-3 rounded-md border border-gray-200 bg-gray-50 p-4">
                  <div className="flex-1">
                    <label htmlFor="testPhone" className={labelClass}>Número para la prueba</label>
                    <input
                      id="testPhone"
                      type="tel"
                      value={testPhone}
                      onChange={(e) => setTestPhone(e.target.value)}
                      className={inputClass}
                      placeholder="+573001234567"
                    />
                  </div>
                  <button
                    type="button"
                    onClick={sendTest}
                    disabled={testSending}
                    className="inline-flex items-center rounded-md bg-gray-800 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-900 disabled:opacity-50"
                  >
                    {testSending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <TestTube2 className="mr-2 h-4 w-4" />}
                    Enviar prueba
                  </button>
                </div>
              )}
            </div>

            {/* ---------------- Columna de previsualización ---------------- */}
            <div className="space-y-4 lg:col-span-1">
              <div className="rounded-lg border border-gray-200 p-4">
                <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-700">
                  <Smartphone className="h-4 w-4 text-gray-400" /> Previsualización
                </h3>
                <PhonePreview sender={senderId} message={message} />
              </div>

              <div className="rounded-lg border border-gray-200 p-4">
                <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-700">
                  <DollarSign className="h-4 w-4 text-gray-400" /> Coste estimado
                </h3>
                {cost ? (
                  <dl className="space-y-1.5 text-sm">
                    <div className="flex justify-between">
                      <dt className="text-gray-500">Destinatarios</dt>
                      <dd className="font-medium text-gray-900">{cost.recipients}</dd>
                    </div>
                    <div className="flex justify-between">
                      <dt className="text-gray-500">Segmentos totales</dt>
                      <dd className="font-medium text-gray-900">{cost.totalSegments}</dd>
                    </div>
                    <div className="flex justify-between border-t border-gray-100 pt-1.5">
                      <dt className="text-gray-500">Total estimado</dt>
                      <dd className="font-semibold text-gray-900">
                        {cost.configured ? `${cost.total.toFixed(4)} ${cost.currency}` : 'Sin configurar'}
                      </dd>
                    </div>
                    {!cost.configured && (
                      <p className="pt-1 text-xs text-gray-400">
                        Define SMS_PRICE_PER_SEGMENT en el .env para ver un importe real.
                      </p>
                    )}
                  </dl>
                ) : (
                  <p className="text-sm text-gray-400">Escribe el mensaje para estimar el coste.</p>
                )}
              </div>
            </div>
          </form>
        )}

        {tab === 'templates' && (
          <div className="space-y-6 p-6">
            <form onSubmit={createTemplate} className="space-y-3 border-b border-gray-200 pb-6">
              <h3 className="text-sm font-medium text-gray-900">Nueva plantilla</h3>
              <input type="text" placeholder="Nombre" value={tplForm.name} onChange={(e) => setTplForm({ ...tplForm, name: e.target.value })} className={inputClass} />
              <textarea rows={3} placeholder="Contenido" value={tplForm.body} onChange={(e) => setTplForm({ ...tplForm, body: e.target.value })} className={inputClass} />
              <button type="submit" className="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Guardar plantilla</button>
            </form>
            <div className="divide-y divide-gray-200 rounded-md border border-gray-200">
              {templates.length === 0 ? (
                <div className="p-6 text-center text-sm text-gray-500">No hay plantillas de SMS.</div>
              ) : (
                templates.map((t) => (
                  <div key={t.id} className="flex items-center justify-between p-4">
                    <div>
                      <h4 className="text-sm font-medium text-gray-900">{t.name}</h4>
                      <p className="max-w-md truncate text-xs text-gray-500">{t.body}</p>
                    </div>
                    <div className="flex items-center gap-3 text-sm">
                      <button type="button" onClick={() => applyTemplate(t)} className="text-blue-600 hover:text-blue-800">Usar</button>
                      <button type="button" onClick={() => removeTemplate(t.id)} className="text-red-600 hover:text-red-800"><Trash2 className="h-4 w-4" /></button>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default SMSModule;
