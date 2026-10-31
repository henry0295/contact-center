import React, { useEffect, useMemo, useState } from 'react';
import {
  Phone, Loader2, Users, Tag, Clock, CalendarX, Download,
  History, TestTube2, ChevronDown, Sparkles, Mic, Volume2,
} from 'lucide-react';
import toast from 'react-hot-toast';
import * as api from '../services/api';
import { refreshBalance } from '../components/BalanceGauge';
import {
  type RecipientEntry, parseRecipients, parseRecipientsCsv, downloadCsv, PHONE_HEADERS,
} from '../utils/csvRecipients';

type RecipientSource = 'paste' | 'csv' | 'list';

const parseCallCsv = (text: string) => parseRecipientsCsv(text, PHONE_HEADERS, 'phone');

function autoName(): string {
  const d = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');
  return `LLAMADA-${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}-${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

const inputClass =
  'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none';
const labelClass = 'block text-sm font-medium text-gray-700 mb-1';
const MAX_CHARS = 1000;

const CallModule: React.FC = () => {
  const [caps, setCaps] = useState<api.CallCapabilities | null>(null);

  // --- Compositor ---
  const [name, setName] = useState(autoName());
  const [voiceGender, setVoiceGender] = useState<'F' | 'M'>('F');
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

  const [testOpen, setTestOpen] = useState(false);
  const [testPhone, setTestPhone] = useState('');
  const [testSending, setTestSending] = useState(false);

  const [csvVariableNames, setCsvVariableNames] = useState<string[]>([]);

  useEffect(() => {
    api.getCallCapabilities().then(setCaps).catch(() => {});
    api.listLists().then((r) => setLists(r.lists)).catch(() => {});
    api.callFields().then((r) => { setFields(r.fields); setPrevious(r.previous); }).catch(() => {});
  }, []);

  const pastedRecipients: RecipientEntry[] = useMemo(() => {
    if (source === 'paste') return parseRecipients(pasteText);
    if (source === 'csv') return parseCallCsv(csvText).rows;
    return [];
  }, [source, pasteText, csvText]);

  const recipientCount = source === 'list'
    ? (lists.find((l) => String(l.id) === listId)?.member_count ?? 0)
    : pastedRecipients.length;

  const insertToken = (token: string) => setMessage((m) => m + token);

  const onCsvFile = (file?: File) => {
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      const text = String(reader.result || '');
      setCsvText(text);
      setCsvVariableNames(parseCallCsv(text).variableNames);
    };
    reader.readAsText(file);
  };

  const downloadSimpleTemplate = () => {
    downloadCsv('plantilla-llamadas-simple.csv', [
      ['telefono'],
      ['3001112233'],
      ['3004445566'],
    ]);
  };

  const downloadVariablesTemplate = () => {
    downloadCsv('plantilla-llamadas-con-variables.csv', [
      ['telefono', 'nombre', 'valor'],
      ['3001112233', 'Ana Pérez', '150000'],
      ['3004445566', 'Carlos Gómez', '98500'],
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
  };

  const validate = (): (api.DispatchPayload & { voiceGender: 'F' | 'M' }) | null => {
    if (!message.trim()) { toast.error('Escribe el mensaje que escuchará el destinatario.'); return null; }
    if (message.length > MAX_CHARS) {
      toast.error(`El mensaje supera los ${MAX_CHARS} caracteres que admite la locución.`);
      return null;
    }

    const payload: api.DispatchPayload & { voiceGender: 'F' | 'M' } = {
      name: name.trim() || undefined,
      body: message,
      tags: tags.trim() || undefined,
      scheduleType,
      voiceGender,
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
      const res = await api.sendCall(payload);
      toast.success(res.message);
      resetAfterSend();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al originar las llamadas');
    } finally {
      setSending(false);
      refreshBalance();
    }
  };

  const sendTest = async () => {
    if (!testPhone.trim()) { toast.error('Indica un número de prueba.'); return; }
    if (!message.trim()) { toast.error('Escribe el mensaje antes de probarlo.'); return; }

    setTestSending(true);
    try {
      const res = await api.sendCallTest({ to: testPhone.trim(), message, voiceGender });
      if (res.success) {
        toast.success(res.message);
        setTestOpen(false);
        setTestPhone('');
      } else {
        toast.error(res.hint ?? res.message);
      }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al originar la llamada de prueba');
    } finally {
      setTestSending(false);
      refreshBalance();
    }
  };

  const charsLeft = MAX_CHARS - message.length;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Módulo de Llamadas</h1>
        <p className="mt-1 text-sm text-gray-500">
          Convierte texto en voz. Cada envío
          queda registrado como campaña en Reportes.
        </p>
      </div>

      {caps && !caps.configured && (
        <div className="flex items-start gap-3 rounded-md border-l-4 border-amber-400 bg-amber-50 p-4">
          <p className="text-sm text-amber-800">{caps.notice}</p>
        </div>
      )}

      <div className="overflow-hidden rounded-lg bg-white shadow">
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
                <label htmlFor="voiceGender" className={labelClass}>
                  <Mic className="mr-1 inline h-3.5 w-3.5 text-gray-400" /> Voz
                </label>
                <select
                  id="voiceGender"
                  value={voiceGender}
                  onChange={(e) => setVoiceGender(e.target.value as 'F' | 'M')}
                  className={inputClass}
                >
                  <option value="F">Femenina</option>
                  <option value="M">Masculina</option>
                </select>
              </div>
            </div>

            <div>
              <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
                <label htmlFor="message" className={labelClass + ' mb-0'}>Mensaje de la locución *</label>
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
                    etc., escribe <code className="font-mono">{'{{'}valor{'}}'}</code> en el mensaje y la
                    locución dirá el dato de cada fila.
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
                rows={5}
                value={message}
                onChange={(e) => setMessage(e.target.value)}
                className={inputClass}
                placeholder="Hola {{nombre}}, le informamos que..."
              />
              <div className={`mt-1 text-right text-xs ${charsLeft < 0 ? 'font-medium text-red-600' : 'text-gray-500'}`}>
                {message.length.toLocaleString('es')}/{MAX_CHARS.toLocaleString('es')} caracteres
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
                    placeholder="Un número por línea, o separados por comas (3001234567 o +573001234567; el +57 se agrega solo)"
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
                  placeholder="cobranza, octubre"
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
                <TestTube2 className="mr-2 h-4 w-4" /> Llamada de prueba
              </button>
              <button
                type="submit"
                disabled={sending || !caps?.configured}
                className="inline-flex items-center rounded-md bg-blue-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
              >
                {sending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Phone className="mr-2 h-4 w-4" />}
                {sending ? 'Originando...' : scheduleType === 'scheduled' ? 'Programar llamadas' : 'Originar llamadas'}
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
                    placeholder="3001234567"
                  />
                </div>
                <button
                  type="button"
                  onClick={sendTest}
                  disabled={testSending || !caps?.configured}
                  className="inline-flex items-center rounded-md bg-gray-800 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-900 disabled:opacity-50"
                >
                  {testSending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <TestTube2 className="mr-2 h-4 w-4" />}
                  Llamar
                </button>
              </div>
            )}
          </div>

          {/* ---------------- Columna lateral ---------------- */}
          <div className="space-y-4 lg:col-span-1">
            <div className="rounded-lg border border-gray-200 p-4">
              <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-700">
                <Volume2 className="h-4 w-4 text-gray-400" /> Cómo funciona
              </h3>
              <ol className="list-inside list-decimal space-y-2 text-xs text-gray-600">
                <li>Se genera la locución a partir del mensaje (voz {voiceGender === 'F' ? 'femenina' : 'masculina'}).</li>
                <li>El servidor Asterisk origina la llamada hacia cada número.</li>
                <li>
                  El resultado (contestada, ocupado o sin respuesta) no se conoce al instante: se
                  consulta unos segundos después y se actualiza en Reportes automáticamente.
                </li>
              </ol>
              <p className="mt-3 rounded-md bg-amber-50 p-2 text-xs text-amber-700">
                Son llamadas reales a números de teléfono reales. Verifica el destinatario antes de
                enviar, sobre todo al probar con datos de ejemplo.
              </p>
            </div>

            <div className="rounded-lg border border-gray-200 p-4">
              <h3 className="mb-2 text-sm font-semibold text-gray-700">Destinatarios</h3>
              <p className="text-2xl font-semibold text-gray-900">{recipientCount}</p>
              <p className="text-xs text-gray-500">
                {recipientCount > 15
                  ? 'Más de 15: se originarán en segundo plano para no agotar el tiempo de la petición.'
                  : 'Se originarán al enviar el formulario.'}
              </p>
            </div>
          </div>
        </form>
      </div>
    </div>
  );
};

export default CallModule;
