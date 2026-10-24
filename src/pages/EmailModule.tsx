import React, { useEffect, useMemo, useState } from 'react';
import {
  Mail, Users, FileText, Trash2, Send, Loader2, LayoutTemplate, Eye, Pencil,
  Paperclip, Tag, Clock, CalendarX, TestTube2, Sparkles, History, ChevronDown, Download, Reply,
} from 'lucide-react';
import toast from 'react-hot-toast';
import * as api from '../services/api';
import RichTextEditor from '../components/RichTextEditor';
import AttachmentPicker from '../components/AttachmentPicker';
import { useSesData } from '../context/SesDataContext';
import { refreshBalance } from '../components/BalanceGauge';
import SafeHtmlFrame from '../components/SafeHtmlFrame';
import { LinkCheckPanel, LinkCheckDialog } from '../components/LinkCheckPanel';
import { useLinkCheck } from '../hooks/useLinkCheck';
import {
  type RecipientEntry, parseRecipients, parseRecipientsCsv, downloadCsv, EMAIL_HEADERS,
} from '../utils/csvRecipients';

// GrapesJS pesa ~1.5 MB: se carga solo cuando se abre el editor de plantillas,
// para no penalizar la carga inicial de la aplicación.
const BlockEditor = React.lazy(() => import('../components/BlockEditor'));

type Tab = 'compose' | 'templates';
type RecipientSource = 'paste' | 'csv' | 'list';

const parseEmailCsv = (text: string) => parseRecipientsCsv(text, EMAIL_HEADERS, 'email');

function autoName(): string {
  const d = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');
  return `EMAIL-${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}-${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

const inputClass =
  'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none';
const labelClass = 'block text-sm font-medium text-gray-700 mb-1';

const EmailModule: React.FC = () => {
  const [tab, setTab] = useState<Tab>('compose');

  // Remitente activo: viene del contexto global, ya cargado al iniciar sesión.
  const { defaultSender: sender } = useSesData();

  // --- Compositor ---
  const [name, setName] = useState(autoName());
  const [recipient, setRecipient] = useState(''); // reutilizado para "a:" opcional dentro de paste
  const [subject, setSubject] = useState('');
  const [message, setMessage] = useState('');
  const [files, setFiles] = useState<File[]>([]);
  const [tags, setTags] = useState('');
  // Email de respuesta (Reply-To): una de las direcciones creadas en
  // Verificación > Email de respuesta, o 'none' para que respondan al remitente.
  const [replyOptions, setReplyOptions] = useState<api.SenderRow[]>([]);
  const [replyTo, setReplyTo] = useState('none');
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
  const [testEmail, setTestEmail] = useState('');
  const [testSending, setTestSending] = useState(false);
  // Revisión de links: en vivo mientras se escribe y diálogo al enviar.
  const [linkDialog, setLinkDialog] = useState<{ data: api.LinkCheckErrorPayload; action: 'send' | 'test' } | null>(null);

  // --- Plantillas ---
  const [templates, setTemplates] = useState<api.Template[]>([]);
  const [editorOpen, setEditorOpen] = useState(false);
  const [editing, setEditing] = useState<api.Template | null>(null);
  const [newTplName, setNewTplName] = useState('');
  const [newTplSubject, setNewTplSubject] = useState('');
  const [savingTpl, setSavingTpl] = useState(false);
  const [preview, setPreview] = useState<api.Template | null>(null);

  useEffect(() => {
    api.listLists().then((r) => setLists(r.lists)).catch(() => {});
    api.emailFields().then((r) => { setFields(r.fields); setPrevious(r.previous); }).catch(() => {});
    api.listSenders('reply')
      .then((r) => {
        setReplyOptions(r.senders);
        const def = r.senders.find((s) => s.is_default);
        setReplyTo(def ? def.email : 'none');
      })
      .catch(() => {});
    refreshTemplates();
  }, []);

  const refreshTemplates = () => {
    api.listTemplates('email').then((r) => setTemplates(r.templates)).catch(() => {});
  };

  const [csvVariableNames, setCsvVariableNames] = useState<string[]>([]);

  const pastedRecipients: RecipientEntry[] = useMemo(() => {
    if (source === 'paste') return parseRecipients(pasteText || recipient);
    if (source === 'csv') return parseEmailCsv(csvText).rows;
    return [];
  }, [source, pasteText, csvText, recipient]);

  const insertToken = (token: string) => setMessage((m) => m + token);
  const { result: linkResult, loading: linkLoading } = useLinkCheck(message, tab === 'compose');

  const onCsvFile = (file?: File) => {
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      const text = String(reader.result || '');
      setCsvText(text);
      setCsvVariableNames(parseEmailCsv(text).variableNames);
    };
    reader.readAsText(file);
  };

  const downloadSimpleTemplate = () => {
    downloadCsv('plantilla-email-simple.csv', [
      ['email'],
      ['ana@example.com'],
      ['carlos@example.com'],
    ]);
  };

  // Incluye una columna extra ("valor") a modo de ejemplo: deja claro que se
  // pueden añadir tantas columnas libres como se necesiten (fecha_pago,
  // placa, factura...), no solo nombre.
  const downloadVariablesTemplate = () => {
    downloadCsv('plantilla-email-con-variables.csv', [
      ['email', 'nombre', 'valor'],
      ['ana@example.com', 'Ana Pérez', '150000'],
      ['carlos@example.com', 'Carlos Gómez', '98500'],
    ]);
  };

  const resetAfterSend = () => {
    setName(autoName());
    setSubject('');
    setMessage('');
    setFiles([]);
    setRecipient('');
    setPasteText('');
    setCsvText('');
    setCsvVariableNames([]);
    setListId('');
    setTags('');
    setScheduleType('now');
    setScheduledAt('');
    setExpiresAt('');
  };

  const validate = (): api.DispatchPayload | null => {
    if (!subject.trim()) { toast.error('El asunto es obligatorio.'); return null; }
    if (!message.trim() || message === '<p></p>') { toast.error('Escribe el mensaje.'); return null; }

    const payload: api.DispatchPayload = {
      name: name.trim() || undefined,
      subject,
      body: message,
      tags: tags.trim() || undefined,
      scheduleType,
      replyTo,
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

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    void doSend(false);
  };

  const doSend = async (acceptLinkWarnings: boolean) => {
    const payload = validate();
    if (!payload) return;

    setSending(true);
    try {
      const res = await api.sendEmailCampaign({ ...payload, attachments: files, acceptLinkWarnings: acceptLinkWarnings || undefined });
      if (res.mode === 'sent') {
        if ((res.result?.sent ?? 0) > 0) toast.success(res.message);
        else toast.error(res.hint ?? res.message);
      } else {
        toast.success(res.message);
      }
      resetAfterSend();
    } catch (err) {
      const lc = api.linkCheckError(err);
      if (lc) setLinkDialog({ data: lc, action: 'send' });
      else toast.error(err instanceof Error ? err.message : 'Error al enviar');
    } finally {
      setSending(false);
      refreshBalance();
    }
  };

  const sendTest = async (acceptLinkWarnings = false) => {
    if (!testEmail.trim()) { toast.error('Indica un correo de prueba.'); return; }
    if (!subject.trim() || !message.trim()) { toast.error('Completa asunto y mensaje antes de probar.'); return; }

    setTestSending(true);
    try {
      const res = await api.sendEmailTest({ to: testEmail.trim(), subject, body: message, replyTo, acceptLinkWarnings: acceptLinkWarnings || undefined });
      if (res.success) {
        toast.success(res.message);
        setTestOpen(false);
        setTestEmail('');
      } else {
        toast.error(res.hint ?? res.message);
      }
    } catch (err) {
      const lc = api.linkCheckError(err);
      if (lc) setLinkDialog({ data: lc, action: 'test' });
      else toast.error(err instanceof Error ? err.message : 'Error al enviar la prueba');
    } finally {
      setTestSending(false);
      refreshBalance();
    }
  };

  // ---------------- Plantillas ----------------

  const openNewTemplate = () => {
    const tplName = window.prompt('Nombre de la plantilla:');
    if (!tplName) return;
    const subj = window.prompt('Asunto por defecto:') ?? '';
    setEditing(null);
    setNewTplName(tplName);
    setNewTplSubject(subj);
    setEditorOpen(true);
  };

  const openEditTemplate = (tpl: api.Template) => {
    setEditing(tpl);
    setNewTplName(tpl.name);
    setNewTplSubject(tpl.subject ?? '');
    setEditorOpen(true);
  };

  const saveTemplate = async (html: string, designJson: string) => {
    setSavingTpl(true);
    try {
      const res = editing
        ? await api.updateTemplate(editing.id, { name: newTplName, subject: newTplSubject, body: html, designJson })
        : await api.createTemplate({ name: newTplName, channel: 'email', subject: newTplSubject, body: html, designJson });
      toast.success(editing ? 'Plantilla actualizada.' : 'Plantilla creada.');
      // La plantilla se guarda igual, pero se avisa si sus links impedirán enviarla.
      const lc = res.linkCheck;
      if (lc && lc.errors.length > 0) {
        toast.error(`La plantilla tiene ${lc.errors.length} link(s) que bloquearán el envío: ${lc.errors[0].message}`, { duration: 8000 });
      } else if (lc && lc.warnings.length > 0) {
        toast(`Aviso: ${lc.warnings.length} link(s) pueden enviar el correo a spam. ${lc.warnings[0].message}`, { icon: '⚠️', duration: 7000 });
      }
      setEditorOpen(false);
      setEditing(null);
      refreshTemplates();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al guardar');
    } finally {
      setSavingTpl(false);
    }
  };

  const removeTemplate = async (id: number) => {
    if (!window.confirm('¿Eliminar esta plantilla?')) return;
    try {
      await api.deleteTemplate(id);
      toast.success('Plantilla eliminada.');
      refreshTemplates();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error');
    }
  };

  const applyTemplate = (tpl: api.Template) => {
    setSubject(tpl.subject ?? '');
    setMessage(tpl.body);
    setTab('compose');
    toast.success(`Plantilla "${tpl.name}" cargada.`);
  };

  // ---------------- UI ----------------

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

  const senderBanner = (
    <div className="flex items-center gap-2 rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600">
      <Mail className="h-3.5 w-3.5 shrink-0 text-gray-400" />
      {sender ? (
        <span>
          Se enviará desde <span className="font-medium text-gray-900">{sender.email}</span>
        </span>
      ) : (
        <span className="text-amber-700">
          No hay remitente configurado. Crea uno en el módulo de Verificación.
        </span>
      )}
    </div>
  );

  return (
    <div className="space-y-6">
      <div className="sm:flex sm:items-start sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Módulo de Email</h1>
          <p className="mt-1 text-sm text-gray-500">
            Redacta y envía correos, con adjuntos y plantillas. Cada envío queda registrado
            como campaña en Reportes.
          </p>
        </div>
      </div>

      <div className="overflow-hidden rounded-lg bg-white shadow">
        <nav className="flex border-b border-gray-200" aria-label="Secciones del módulo de email">
          {tabButton('compose', 'Compositor', Send)}
          {tabButton('templates', 'Plantillas', FileText)}
        </nav>

        {tab === 'compose' && (
          <form onSubmit={submit} className="space-y-5 p-6">
            {senderBanner}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label htmlFor="campName" className={labelClass}>Nombre de la campaña</label>
                <input id="campName" type="text" value={name} onChange={(e) => setName(e.target.value)} className={inputClass} />
              </div>
              <div>
                <label htmlFor="subject" className={labelClass}>Asunto *</label>
                <input
                  id="subject"
                  type="text"
                  value={subject}
                  onChange={(e) => setSubject(e.target.value)}
                  className={inputClass}
                  placeholder="Asunto del correo"
                />
              </div>
            </div>

            <div>
              <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
                <label className={labelClass + ' mb-0'}>Mensaje *</label>
                <div className="flex items-center gap-2 text-xs">
                  {templates.length > 0 && (
                    <select
                      onChange={(e) => {
                        const tpl = templates.find((t) => t.id === Number(e.target.value));
                        if (tpl) applyTemplate(tpl);
                        e.target.value = '';
                      }}
                      defaultValue=""
                      className="rounded-md border border-gray-300 px-2 py-1 text-xs text-gray-600"
                    >
                      <option value="" disabled>Usar una plantilla...</option>
                      {templates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </select>
                  )}
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
                      onClick={() => { setSubject(p.subject ?? ''); setMessage(p.body); setShowPrevious(false); }}
                      className="block w-full truncate border-b border-gray-100 px-3 py-2 text-left text-xs text-gray-700 last:border-0 hover:bg-gray-50"
                    >
                      {p.subject ?? '(sin asunto)'}
                    </button>
                  ))}
                </div>
              )}

              <RichTextEditor
                value={message}
                onChange={setMessage}
                placeholder="Escribe tu mensaje. Puedes dar formato, insertar enlaces e imágenes."
              />
              <div className="mt-2">
                <LinkCheckPanel result={linkResult} loading={linkLoading} />
              </div>
            </div>

            <div>
              <label className={labelClass}>Archivos adjuntos</label>
              <AttachmentPicker files={files} onChange={setFiles} />
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
                    placeholder="Un correo por línea, o separados por comas"
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

            <div>
              <label htmlFor="replyTo" className={labelClass}>
                <Reply className="mr-1 inline h-3.5 w-3.5 text-gray-400" /> Email de respuesta
              </label>
              <select id="replyTo" value={replyTo} onChange={(e) => setReplyTo(e.target.value)} className={inputClass}>
                <option value="none">Sin email de respuesta (responden al remitente)</option>
                {replyOptions.map((s) => (
                  <option key={s.id} value={s.email}>
                    {s.email}{s.is_default ? ' (por defecto)' : ''}
                  </option>
                ))}
              </select>
              <p className="mt-1 text-xs text-gray-500">
                {replyOptions.length === 0
                  ? 'Agrega emails de respuesta en Verificación > Email de respuesta.'
                  : 'Cuando el destinatario pulse "Responder", el correo llegará a esta dirección.'}
              </p>
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label htmlFor="tags" className={labelClass}>
                  <Tag className="mr-1 inline h-3.5 w-3.5 text-gray-400" /> Etiquetas
                </label>
                <input id="tags" type="text" value={tags} onChange={(e) => setTags(e.target.value)} className={inputClass} placeholder="promo, octubre" />
              </div>
              <div>
                <label htmlFor="scheduleType" className={labelClass}>
                  <Clock className="mr-1 inline h-3.5 w-3.5 text-gray-400" /> Envío
                </label>
                <select id="scheduleType" value={scheduleType} onChange={(e) => setScheduleType(e.target.value as 'now' | 'scheduled')} className={inputClass}>
                  <option value="now">Enviar ahora</option>
                  <option value="scheduled">Programar</option>
                </select>
              </div>
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              {scheduleType === 'scheduled' && (
                <div>
                  <label htmlFor="scheduledAt" className={labelClass}>Fecha y hora de envío *</label>
                  <input id="scheduledAt" type="datetime-local" value={scheduledAt} onChange={(e) => setScheduledAt(e.target.value)} className={inputClass} />
                </div>
              )}
              <div>
                <label htmlFor="expiresAt" className={labelClass}>
                  <CalendarX className="mr-1 inline h-3.5 w-3.5 text-gray-400" /> Caduca el (opcional)
                </label>
                <input id="expiresAt" type="datetime-local" value={expiresAt} onChange={(e) => setExpiresAt(e.target.value)} className={inputClass} />
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
                {sending ? 'Enviando...' : scheduleType === 'scheduled' ? 'Programar envío' : 'Enviar email'}
              </button>
            </div>

            {testOpen && (
              <div className="flex flex-wrap items-end gap-3 rounded-md border border-gray-200 bg-gray-50 p-4">
                <div className="flex-1">
                  <label htmlFor="testEmail" className={labelClass}>Correo para la prueba</label>
                  <input
                    id="testEmail"
                    type="email"
                    value={testEmail}
                    onChange={(e) => setTestEmail(e.target.value)}
                    className={inputClass}
                    placeholder="destinatario@ejemplo.com"
                  />
                </div>
                <button
                  type="button"
                  onClick={() => sendTest(false)}
                  disabled={testSending}
                  className="inline-flex items-center rounded-md bg-gray-800 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-900 disabled:opacity-50"
                >
                  {testSending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <TestTube2 className="mr-2 h-4 w-4" />}
                  Enviar prueba
                </button>
              </div>
            )}
          </form>
        )}

        {tab === 'templates' && (
          <div className="space-y-5 p-6">
            <div className="flex items-center justify-between gap-3">
              <p className="text-sm text-gray-500">
                Diseña plantillas con bloques arrastrables. El HTML generado usa tablas, compatible con
                Outlook y Gmail.
              </p>
              <button
                type="button"
                onClick={openNewTemplate}
                className="shrink-0 inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700"
              >
                <LayoutTemplate className="mr-2 h-4 w-4" />
                Nueva plantilla
              </button>
            </div>

            {templates.length === 0 ? (
              <div className="rounded-lg border-2 border-dashed border-gray-300 px-6 py-12 text-center">
                <LayoutTemplate className="mx-auto h-8 w-8 text-gray-400" />
                <h3 className="mt-2 text-sm font-medium text-gray-900">Todavía no hay plantillas</h3>
                <p className="mt-1 text-sm text-gray-500">
                  Crea la primera para reutilizarla en tus envíos.
                </p>
              </div>
            ) : (
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {templates.map((t) => (
                  <div key={t.id} className="flex flex-col overflow-hidden rounded-lg border border-gray-200 transition-shadow hover:shadow-md">
                    <div className="h-32 overflow-hidden border-b border-gray-200 bg-gray-50 p-2">
                      <SafeHtmlFrame
                        html={t.body}
                        title={`Miniatura de ${t.name}`}
                        className="pointer-events-none origin-top-left scale-[0.45] bg-white"
                        style={{ width: '222%', height: '222%' }}
                      />
                    </div>
                    <div className="flex flex-1 flex-col p-3">
                      <h4 className="truncate text-sm font-medium text-gray-900">{t.name}</h4>
                      <p className="mt-0.5 truncate text-xs text-gray-500">{t.subject || 'Sin asunto'}</p>
                      <div className="mt-3 flex items-center gap-2 border-t border-gray-100 pt-2 text-xs">
                        <button type="button" onClick={() => applyTemplate(t)} className="font-medium text-blue-600 hover:text-blue-800">
                          Usar
                        </button>
                        <button type="button" onClick={() => setPreview(t)} title="Previsualizar" className="ml-auto p-1 text-gray-400 hover:text-gray-700">
                          <Eye className="h-4 w-4" />
                        </button>
                        <button type="button" onClick={() => openEditTemplate(t)} title="Editar" className="p-1 text-gray-400 hover:text-blue-600">
                          <Pencil className="h-4 w-4" />
                        </button>
                        <button type="button" onClick={() => removeTemplate(t.id)} title="Eliminar" className="p-1 text-gray-400 hover:text-red-600">
                          <Trash2 className="h-4 w-4" />
                        </button>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}
      </div>

      {/* Nota sobre adjuntos */}
      <p className="flex items-start gap-2 text-xs text-gray-500">
        <Paperclip className="mt-0.5 h-3.5 w-3.5 shrink-0" />
        No se permiten ejecutables, y el total por correo
        está limitado para no exceder el máximo.
      </p>

      {/* Editor de bloques a pantalla completa */}
      {editorOpen && (
        <React.Suspense
          fallback={
            <div className="fixed inset-0 z-40 flex items-center justify-center bg-white">
              <span className="inline-flex items-center text-sm text-gray-500">
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                Cargando editor de bloques...
              </span>
            </div>
          }
        >
          <BlockEditor
            title={editing ? `Editar plantilla: ${newTplName}` : `Nueva plantilla: ${newTplName}`}
            initialHtml={editing?.body}
            initialDesign={editing?.design_json ?? null}
            saving={savingTpl}
            onSave={saveTemplate}
            onClose={() => { setEditorOpen(false); setEditing(null); }}
          />
        </React.Suspense>
      )}

      {linkDialog && (
        <LinkCheckDialog
          data={linkDialog.data}
          onCancel={() => setLinkDialog(null)}
          onConfirm={() => {
            const action = linkDialog.action;
            setLinkDialog(null);
            if (action === 'test') void sendTest(true);
            else void doSend(true);
          }}
        />
      )}

      {/* Previsualización */}
      {preview && (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" onClick={() => setPreview(null)}>
          <div className="flex max-h-[85vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between border-b border-gray-200 px-5 py-3">
              <div className="min-w-0">
                <h3 className="truncate text-sm font-semibold text-gray-900">{preview.name}</h3>
                <p className="truncate text-xs text-gray-500">{preview.subject || 'Sin asunto'}</p>
              </div>
              <button type="button" onClick={() => setPreview(null)} className="text-sm text-gray-500 hover:text-gray-800">
                Cerrar
              </button>
            </div>
            <div className="overflow-y-auto bg-gray-100 p-4">
              <SafeHtmlFrame
                html={preview.body}
                title={`Vista previa de ${preview.name}`}
                className="mx-auto block w-full max-w-xl rounded bg-white shadow-sm"
                style={{ height: '70vh' }}
              />
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default EmailModule;
