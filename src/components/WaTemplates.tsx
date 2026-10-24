import React, { useEffect, useMemo, useState } from 'react';
import {
  RefreshCw, Plus, Trash2, Loader2, BookOpen, Pencil, Copy, Search, X, ExternalLink, Phone, ShieldCheck,
  AlertTriangle, Smartphone, Lightbulb,
} from 'lucide-react';
import toast from 'react-hot-toast';
import * as api from '../services/api';
import {
  WA_TEMPLATE_LIBRARY, LIB_SECTORS, LIB_USES, type LibTemplate,
} from '../data/waTemplateLibrary';

const inputClass =
  'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-green-600 focus:ring-2 focus:ring-green-600 focus:outline-none disabled:bg-gray-100 disabled:text-gray-500';
const labelClass = 'block text-sm font-medium text-gray-700 mb-1';

const CATEGORY_LABELS: Record<string, string> = { MARKETING: 'Marketing', UTILITY: 'Utilidad', AUTHENTICATION: 'Autenticación' };
const CATEGORY_STYLES: Record<string, string> = {
  MARKETING: 'bg-purple-100 text-purple-800', UTILITY: 'bg-blue-100 text-blue-800', AUTHENTICATION: 'bg-gray-800 text-white',
};
const STATUS: Record<string, { label: string; cls: string }> = {
  APPROVED: { label: 'Aprobada', cls: 'bg-green-100 text-green-800' },
  PENDING: { label: 'En revisión', cls: 'bg-amber-100 text-amber-800' },
  REJECTED: { label: 'Rechazada', cls: 'bg-red-100 text-red-800' },
  PAUSED: { label: 'Pausada', cls: 'bg-orange-100 text-orange-800' },
  DISABLED: { label: 'Desactivada', cls: 'bg-gray-200 text-gray-700' },
};

const CATEGORY_TIPS: Record<string, string[]> = {
  UTILITY: [
    'Solo para avisos sobre algo que el cliente hizo o tiene: pedido, cita, pago, cuenta, caso.',
    'Sin lenguaje promocional ("oferta", "descuento", "aprovecha"): Meta la pasaría a Marketing, que cuesta más.',
    'Incluye datos concretos con variables (número de pedido, fecha, valor).',
  ],
  MARKETING: [
    'Para promociones, novedades, reactivación y encuestas.',
    'Incluye en el pie cómo darse de baja, por ejemplo "Responde SALIR para no recibir más mensajes".',
    'Solo se puede enviar a contactos que aceptaron recibir mensajes de tu empresa.',
  ],
  AUTHENTICATION: [
    'Meta fija el texto: "<código> es tu código de verificación." Solo eliges las opciones.',
    'Úsala únicamente para códigos de un solo uso (OTP). No admite enlaces, emojis ni multimedia.',
    'Al enviarla, la única variable es el código.',
  ],
};

interface TplForm {
  name: string;
  category: api.WaCategory;
  language: string;
  header: string;
  body: string;
  footer: string;
  examples: string[];
  buttons: api.WaButton[];
  auth: api.WaAuthOptions;
}

const emptyForm: TplForm = {
  name: '', category: 'UTILITY', language: 'es', header: '', body: '', footer: '', examples: [], buttons: [],
  auth: { securityRecommendation: true, expirationMinutes: 10, buttonText: 'Copiar código' },
};

function countVars(text: string): number {
  const nums = new Set<number>();
  for (const m of text.matchAll(/\{\{\s*(\d+)\s*\}\}/g)) nums.add(Number(m[1]));
  return nums.size;
}

function fillBody(body: string, params: string[]): string {
  return body.replace(/\{\{\s*(\d+)\s*\}\}/g, (m, n) => {
    const v = params[Number(n) - 1];
    return v && v.trim() !== '' ? v : m;
  });
}

/** Mismas reglas que el servidor, para avisar mientras se escribe. */
function localIssues(f: TplForm): string[] {
  if (f.category === 'AUTHENTICATION') return [];
  const out: string[] = [];
  const vars = countVars(f.body);
  const nums = [...f.body.matchAll(/\{\{\s*(\d+)\s*\}\}/g)].map((m) => Number(m[1]));
  const uniq = [...new Set(nums)].sort((a, b) => a - b);
  if (vars > 0 && uniq.some((n, i) => n !== i + 1)) out.push('Las variables deben ser consecutivas: {{1}}, {{2}}, ...');
  if (/^\s*\{\{/.test(f.body) || /\{\{\s*\d+\s*\}\}[\s.!?¡¿,;:]*$/u.test(f.body)) out.push('El cuerpo no puede empezar ni terminar con una variable.');
  if (/\}\}\s*\{\{/.test(f.body)) out.push('No pongas dos variables seguidas sin texto entre ellas.');
  if (/\{\{/.test(f.header + f.footer)) out.push('Las variables solo se permiten en el cuerpo.');
  if (f.category === 'UTILITY' && /\b(descuento|oferta|promo|aprovecha|gratis|rebaja)/i.test(f.body)) {
    out.push('Tiene palabras promocionales: Meta podría clasificarla como Marketing.');
  }
  return out;
}

const fromLibrary = (t: LibTemplate): TplForm => ({
  name: t.name,
  category: t.category,
  language: 'es',
  header: t.header ?? '',
  body: t.body,
  footer: t.footer ?? '',
  examples: [...t.examples],
  buttons: (t.buttons ?? []).map((b) => ({ ...b })),
  auth: t.auth
    ? { securityRecommendation: t.auth.securityRecommendation, expirationMinutes: t.auth.expirationMinutes ?? null, buttonText: t.auth.buttonText }
    : emptyForm.auth,
});

const fromExisting = (t: api.WaTemplate, asCopy: boolean): TplForm => {
  const otp = t.buttons.find((b) => b.type === 'OTP');
  const minutes = /(\d+)\s*minutos/.exec(t.footer ?? '')?.[1];
  return {
    name: asCopy ? `${t.element_name}_v2`.slice(0, 180) : t.element_name,
    category: (t.category as api.WaCategory) ?? 'UTILITY',
    language: t.language,
    header: t.header ?? '',
    body: t.body,
    footer: t.category === 'AUTHENTICATION' ? '' : t.footer ?? '',
    examples: [],
    buttons: t.buttons.filter((b) => b.type !== 'OTP').map((b) => ({ type: b.type, text: b.text, url: b.url, phone_number: b.phone_number })),
    auth: {
      securityRecommendation: /seguridad/i.test(t.body),
      expirationMinutes: minutes ? Number(minutes) : null,
      buttonText: otp?.text ?? 'Copiar código',
    },
  };
};

const Badge: React.FC<{ cls: string; children: React.ReactNode }> = ({ cls, children }) => (
  <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${cls}`}>{children}</span>
);

/** Vista previa con el aspecto de WhatsApp. */
const Preview: React.FC<{ f: TplForm }> = ({ f }) => {
  const isAuth = f.category === 'AUTHENTICATION';
  const body = isAuth
    ? `123456 es tu código de verificación.${f.auth.securityRecommendation ? ' Por tu seguridad, no lo compartas.' : ''}`
    : fillBody(f.body, f.examples);
  const footer = isAuth ? (f.auth.expirationMinutes ? `Este código vence en ${f.auth.expirationMinutes} minutos.` : '') : f.footer;
  const buttons = isAuth ? [{ type: 'OTP', text: f.auth.buttonText || 'Copiar código' }] : f.buttons;
  return (
    <div className="mx-auto w-full max-w-[280px] rounded-[2rem] border-[6px] border-gray-900 bg-gray-900 shadow-xl">
      <div className="overflow-hidden rounded-[1.4rem]">
        <div className="flex items-center gap-2 bg-[#075E54] px-3 py-2.5 text-white">
          <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/20 text-xs font-bold">WA</div>
          <span className="truncate text-sm font-medium">Tu empresa</span>
        </div>
        <div className="min-h-[260px] bg-[#ECE5DD] p-3">
          {body.trim() ? (
            <div className="max-w-[92%] space-y-1">
              <div className="rounded-lg rounded-tl-none bg-white px-3 py-2 text-sm text-gray-800 shadow-sm">
                {!isAuth && f.header && <p className="mb-1 font-semibold">{f.header}</p>}
                <p className="whitespace-pre-wrap break-words">{body}</p>
                {footer && <p className="mt-1 text-xs text-gray-500">{footer}</p>}
              </div>
              {buttons.filter((b) => b.text).map((b, i) => (
                <div key={i} className="rounded-lg bg-white px-3 py-1.5 text-center text-sm font-medium text-[#00A5F4] shadow-sm">
                  {b.type === 'URL' && <ExternalLink className="mr-1 inline h-3.5 w-3.5" aria-hidden="true" />}
                  {b.type === 'PHONE_NUMBER' && <Phone className="mr-1 inline h-3.5 w-3.5" aria-hidden="true" />}
                  {b.type === 'OTP' && <Copy className="mr-1 inline h-3.5 w-3.5" aria-hidden="true" />}
                  {b.text}
                </div>
              ))}
            </div>
          ) : (
            <p className="pt-16 text-center text-sm text-gray-500">Escribe el cuerpo para ver la vista previa.</p>
          )}
        </div>
      </div>
    </div>
  );
};

// ------------------------------------------------------------ biblioteca --

const Library: React.FC<{ onUse: (t: LibTemplate) => void; onClose: () => void }> = ({ onUse, onClose }) => {
  const [sector, setSector] = useState('');
  const [use, setUse] = useState('');
  const [category, setCategory] = useState('');
  const [q, setQ] = useState('');

  const items = useMemo(() => {
    const s = q.trim().toLowerCase();
    return WA_TEMPLATE_LIBRARY.filter((t) =>
      (!sector || t.sector === sector || t.sector === 'general') &&
      (!use || t.use === use) &&
      (!category || t.category === category) &&
      (!s || `${t.title} ${t.body} ${t.name}`.toLowerCase().includes(s)));
  }, [sector, use, category, q]);

  return (
    <section className="space-y-4 rounded-md border border-green-200 bg-green-50/40 p-4" aria-labelledby="libTitle">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h3 id="libTitle" className="flex items-center gap-2 text-sm font-semibold text-gray-900">
            <BookOpen className="h-4 w-4 text-green-700" aria-hidden="true" /> Biblioteca de plantillas de ejemplo
          </h3>
          <p className="mt-0.5 text-xs text-gray-600">
            {WA_TEMPLATE_LIBRARY.length} ejemplos redactados según las reglas de Meta. Elige uno, ajústalo a tu negocio y envíalo a aprobación.
          </p>
        </div>
        <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-600" aria-label="Cerrar biblioteca"><X className="h-5 w-5" /></button>
      </div>
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-4">
        <div className="relative">
          <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" aria-hidden="true" />
          <input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Buscar" aria-label="Buscar en la biblioteca" className={inputClass + ' pl-8'} />
        </div>
        <select value={sector} onChange={(e) => setSector(e.target.value)} className={inputClass} aria-label="Sector">
          <option value="">Todos los sectores</option>
          {Object.entries(LIB_SECTORS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
        <select value={use} onChange={(e) => setUse(e.target.value)} className={inputClass} aria-label="Uso">
          <option value="">Todos los usos</option>
          {Object.entries(LIB_USES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
        <select value={category} onChange={(e) => setCategory(e.target.value)} className={inputClass} aria-label="Categoría de Meta">
          <option value="">Todas las categorías</option>
          {Object.entries(CATEGORY_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
      </div>
      {items.length === 0 ? (
        <p className="text-sm text-gray-500">No hay ejemplos con esos filtros.</p>
      ) : (
        <ul className="grid max-h-[480px] grid-cols-1 gap-3 overflow-y-auto pr-1 md:grid-cols-2 xl:grid-cols-3">
          {items.map((t) => (
            <li key={t.id} className="flex flex-col rounded-md border border-gray-200 bg-white p-3">
              <div className="flex flex-wrap items-center gap-1.5">
                <Badge cls={CATEGORY_STYLES[t.category]}>{CATEGORY_LABELS[t.category]}</Badge>
                <span className="text-xs text-gray-500">{LIB_SECTORS[t.sector]} · {LIB_USES[t.use]}</span>
              </div>
              <p className="mt-2 text-sm font-medium text-gray-900">{t.title}</p>
              <p className="mt-1 line-clamp-4 flex-1 whitespace-pre-wrap text-xs text-gray-600">
                {t.category === 'AUTHENTICATION' ? '123456 es tu código de verificación. Por tu seguridad, no lo compartas.' : fillBody(t.body, t.examples)}
              </p>
              <button
                type="button"
                onClick={() => onUse(t)}
                className="mt-3 inline-flex items-center justify-center rounded-md bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700"
              >
                <Pencil className="mr-1.5 h-4 w-4" aria-hidden="true" /> Usar y editar
              </button>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
};

// ------------------------------------------------------------ formulario --

const TemplateForm: React.FC<{
  initial: TplForm;
  editing: api.WaTemplate | null;
  onCancel: () => void;
  onSaved: () => void;
}> = ({ initial, editing, onCancel, onSaved }) => {
  const [form, setForm] = useState<TplForm>(initial);
  const [saving, setSaving] = useState(false);
  const isAuth = form.category === 'AUTHENTICATION';
  const vars = countVars(form.body);
  const issues = localIssues(form);
  const lockCategory = editing?.status === 'APPROVED';

  useEffect(() => { setForm(initial); }, [initial]);
  useEffect(() => {
    setForm((f) => (f.examples.length === vars ? f : { ...f, examples: Array.from({ length: vars }, (_, i) => f.examples[i] ?? '') }));
  }, [vars]);

  const set = <K extends keyof TplForm>(k: K, v: TplForm[K]) => setForm((f) => ({ ...f, [k]: v }));
  const setButton = (i: number, patch: Partial<api.WaButton>) =>
    setForm((f) => ({ ...f, buttons: f.buttons.map((b, j) => (j === i ? { ...b, ...patch } : b)) }));

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (issues.some((i) => !i.startsWith('Tiene palabras'))) { toast.error(issues[0]); return; }
    const payload = {
      category: form.category,
      header: isAuth ? undefined : form.header.trim() || undefined,
      body: isAuth ? '{{1}}' : form.body.trim(),
      footer: isAuth ? undefined : form.footer.trim() || undefined,
      examples: isAuth ? ['123456'] : form.examples,
      buttons: isAuth ? undefined : form.buttons.length ? form.buttons : undefined,
      auth: isAuth ? form.auth : undefined,
    };
    setSaving(true);
    try {
      const r = editing
        ? await api.editWaTemplate(editing.id, payload)
        : await api.createWaTemplate({ ...payload, name: form.name.trim(), language: form.language.trim() });
      toast.success(r.message);
      onSaved();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'No se pudo guardar la plantilla');
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={save} className="grid grid-cols-1 gap-6 rounded-md border border-gray-200 p-4 lg:grid-cols-3">
      <div className="space-y-4 lg:col-span-2">
        <h3 className="text-sm font-semibold text-gray-900">
          {editing ? `Editar "${editing.element_name}" y reenviar a revisión` : 'Nueva plantilla'}
        </h3>
        {editing?.status === 'APPROVED' && (
          <p className="flex items-start gap-2 rounded-md bg-amber-50 p-2 text-xs text-amber-900">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            Al guardar, la plantilla vuelve a revisión y no se podrá usar hasta que Meta la apruebe de nuevo. Meta permite editar una plantilla aprobada una vez cada 24 h y hasta 10 veces en 30 días, sin cambiar la categoría.
          </p>
        )}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <label htmlFor="tplName" className={labelClass}>Nombre *</label>
            <input
              id="tplName"
              type="text"
              value={form.name}
              disabled={!!editing}
              onChange={(e) => set('name', e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_'))}
              className={inputClass}
              placeholder="recordatorio_pago"
              required
            />
          </div>
          <div>
            <label htmlFor="tplCategory" className={labelClass}>Categoría *</label>
            <select id="tplCategory" value={form.category} disabled={lockCategory} onChange={(e) => set('category', e.target.value as api.WaCategory)} className={inputClass}>
              <option value="UTILITY">Utilidad (avisos, pagos, citas)</option>
              <option value="MARKETING">Marketing (promociones)</option>
              <option value="AUTHENTICATION">Autenticación (códigos)</option>
            </select>
          </div>
          <div>
            <label htmlFor="tplLang" className={labelClass}>Idioma *</label>
            <input id="tplLang" type="text" value={form.language} disabled={!!editing} onChange={(e) => set('language', e.target.value)} className={inputClass} placeholder="es" />
          </div>
        </div>

        {isAuth ? (
          <fieldset className="space-y-3 rounded-md border border-gray-200 p-3">
            <legend className="flex items-center gap-1.5 px-1 text-sm font-medium text-gray-700">
              <ShieldCheck className="h-4 w-4 text-gray-400" aria-hidden="true" /> Opciones del código
            </legend>
            <label className="flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" checked={form.auth.securityRecommendation} onChange={(e) => set('auth', { ...form.auth, securityRecommendation: e.target.checked })} className="h-4 w-4 rounded border-gray-300 text-green-600" />
              Añadir "Por tu seguridad, no lo compartas"
            </label>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label htmlFor="authMin" className={labelClass}>Vence en (minutos, opcional)</label>
                <input
                  id="authMin"
                  type="number"
                  min={1}
                  max={90}
                  value={form.auth.expirationMinutes ?? ''}
                  onChange={(e) => set('auth', { ...form.auth, expirationMinutes: e.target.value ? Number(e.target.value) : null })}
                  className={inputClass}
                />
              </div>
              <div>
                <label htmlFor="authBtn" className={labelClass}>Texto del botón</label>
                <input id="authBtn" type="text" maxLength={25} value={form.auth.buttonText} onChange={(e) => set('auth', { ...form.auth, buttonText: e.target.value })} className={inputClass} />
              </div>
            </div>
          </fieldset>
        ) : (
          <>
            <div>
              <label htmlFor="tplHeader" className={labelClass}>Encabezado (opcional)</label>
              <input id="tplHeader" type="text" maxLength={60} value={form.header} onChange={(e) => set('header', e.target.value)} className={inputClass} />
            </div>
            <div>
              <label htmlFor="tplBody" className={labelClass}>Cuerpo *</label>
              <textarea
                id="tplBody"
                rows={5}
                maxLength={1024}
                value={form.body}
                onChange={(e) => set('body', e.target.value)}
                className={inputClass}
                placeholder="Hola {{1}}, tu factura por {{2}} vence mañana. Gracias."
                required
              />
              <p className="mt-1 text-xs text-gray-500">
                Usa {'{{1}}'}, {'{{2}}'}... para las variables. {form.body.length}/1024
              </p>
            </div>
            {form.examples.length > 0 && (
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                {form.examples.map((ex, i) => (
                  <div key={i}>
                    <label htmlFor={`tplEx${i}`} className="mb-1 block text-xs font-medium text-gray-600">{`Ejemplo de {{${i + 1}}} *`}</label>
                    <input
                      id={`tplEx${i}`}
                      type="text"
                      value={ex}
                      onChange={(e) => set('examples', form.examples.map((x, j) => (j === i ? e.target.value : x)))}
                      className={inputClass}
                      required
                    />
                  </div>
                ))}
              </div>
            )}
            <div>
              <label htmlFor="tplFooter" className={labelClass}>Pie (opcional)</label>
              <input id="tplFooter" type="text" maxLength={60} value={form.footer} onChange={(e) => set('footer', e.target.value)} className={inputClass} placeholder="Responde SALIR para no recibir más mensajes" />
            </div>
            <div className="space-y-2">
              <span className="block text-sm font-medium text-gray-700">Botones (máx. 3)</span>
              {form.buttons.map((b, i) => (
                <div key={i} className="flex flex-wrap items-center gap-2">
                  <select value={b.type} onChange={(e) => setButton(i, { type: e.target.value })} className={inputClass + ' w-44'} aria-label={`Tipo del botón ${i + 1}`}>
                    <option value="QUICK_REPLY">Respuesta rápida</option>
                    <option value="URL">Enlace</option>
                    <option value="PHONE_NUMBER">Llamar</option>
                  </select>
                  <input type="text" maxLength={25} value={b.text} onChange={(e) => setButton(i, { text: e.target.value })} className={inputClass + ' flex-1'} placeholder="Texto del botón" aria-label={`Texto del botón ${i + 1}`} />
                  {b.type === 'URL' && (
                    <input type="url" value={b.url ?? ''} onChange={(e) => setButton(i, { url: e.target.value })} className={inputClass + ' flex-1'} placeholder="https://..." aria-label={`URL del botón ${i + 1}`} />
                  )}
                  {b.type === 'PHONE_NUMBER' && (
                    <input type="tel" value={b.phone_number ?? ''} onChange={(e) => setButton(i, { phone_number: e.target.value })} className={inputClass + ' flex-1'} placeholder="+573001234567" aria-label={`Teléfono del botón ${i + 1}`} />
                  )}
                  <button type="button" onClick={() => set('buttons', form.buttons.filter((_, j) => j !== i))} className="rounded p-2 text-gray-400 hover:bg-red-50 hover:text-red-600" aria-label={`Quitar botón ${i + 1}`}>
                    <Trash2 className="h-4 w-4" aria-hidden="true" />
                  </button>
                </div>
              ))}
              {form.buttons.length < 3 && (
                <button type="button" onClick={() => set('buttons', [...form.buttons, { type: 'QUICK_REPLY', text: '' }])} className="inline-flex items-center text-xs font-medium text-green-700 hover:underline">
                  <Plus className="mr-1 h-3.5 w-3.5" aria-hidden="true" /> Añadir botón
                </button>
              )}
            </div>
          </>
        )}

        {issues.length > 0 && (
          <ul className="space-y-1 rounded-md bg-amber-50 p-2 text-xs text-amber-900" aria-live="polite">
            {issues.map((i) => <li key={i} className="flex items-start gap-1.5"><AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />{i}</li>)}
          </ul>
        )}

        <div className="flex justify-end gap-3 border-t border-gray-200 pt-4">
          <button type="button" onClick={onCancel} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</button>
          <button type="submit" disabled={saving} className="inline-flex items-center rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50">
            {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden="true" />}
            {editing ? 'Guardar y reenviar a revisión' : 'Enviar a aprobación'}
          </button>
        </div>
      </div>

      <div className="space-y-4 lg:col-span-1">
        <div>
          <h4 className="mb-2 flex items-center gap-2 text-sm font-semibold text-gray-700">
            <Smartphone className="h-4 w-4 text-gray-400" aria-hidden="true" /> Vista previa (con los ejemplos)
          </h4>
          <Preview f={form} />
        </div>
        <div className="rounded-md border border-gray-200 bg-gray-50 p-3 text-xs text-gray-700">
          <p className="mb-1 flex items-center gap-1.5 font-medium text-gray-900">
            <Lightbulb className="h-4 w-4 text-amber-500" aria-hidden="true" /> Para que Meta la apruebe ({CATEGORY_LABELS[form.category]})
          </p>
          <ul className="list-disc space-y-1 pl-4">
            {CATEGORY_TIPS[form.category].map((t) => <li key={t}>{t}</li>)}
          </ul>
        </div>
      </div>
    </form>
  );
};

// ------------------------------------------------------------- borrado --

const DeleteDialog: React.FC<{ t: api.WaTemplate; onClose: () => void; onDone: () => void }> = ({ t, onClose, onDone }) => {
  const [busy, setBusy] = useState<'local' | 'remote' | null>(null);
  const [confirmText, setConfirmText] = useState('');

  const run = async (remote: boolean) => {
    setBusy(remote ? 'remote' : 'local');
    try {
      const r = await api.deleteWaTemplate(t.id, remote);
      toast.success(r.message ?? 'Plantilla eliminada.');
      onDone();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'No se pudo eliminar');
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <div role="alertdialog" aria-modal="true" aria-labelledby="delTplTitle" className="w-full max-w-lg overflow-hidden rounded-lg bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-start justify-between border-b border-gray-200 px-5 py-4">
          <h2 id="delTplTitle" className="text-base font-semibold text-gray-900">Eliminar "{t.element_name}"</h2>
          <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-600" aria-label="Cerrar"><X className="h-5 w-5" /></button>
        </div>
        <div className="space-y-4 px-5 py-4 text-sm text-gray-700">
          <div className="rounded-md border border-gray-200 p-3">
            <p className="font-medium text-gray-900">Quitar de la lista</p>
            <p className="mt-1 text-xs text-gray-600">Solo la oculta en esta aplicación. Sigue existiendo en Meta y reaparece si sincronizas.</p>
            <button type="button" disabled={busy !== null} onClick={() => run(false)} className="mt-2 inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">
              {busy === 'local' && <Loader2 className="mr-1.5 h-4 w-4 animate-spin" aria-hidden="true" />} Quitar de la lista
            </button>
          </div>
          <div className="rounded-md border border-red-200 bg-red-50 p-3">
            <p className="font-medium text-red-800">Borrar en Meta (definitivo)</p>
            <ul className="mt-1 list-disc space-y-0.5 pl-4 text-xs text-red-800">
              <li>No se puede deshacer y borra todos sus idiomas.</li>
              <li>Meta no deja volver a usar el nombre "{t.element_name}" durante 30 días.</li>
              <li>Los mensajes ya enviados no se afectan. Solo funciona con la línea en vivo.</li>
            </ul>
            <label htmlFor="delConfirm" className="mt-2 block text-xs text-red-800">Escribe el nombre de la plantilla para confirmar:</label>
            <input id="delConfirm" type="text" value={confirmText} onChange={(e) => setConfirmText(e.target.value)} className={inputClass + ' mt-1'} autoComplete="off" />
            <button
              type="button"
              disabled={busy !== null || confirmText.trim() !== t.element_name}
              onClick={() => run(true)}
              className="mt-2 inline-flex items-center rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
            >
              {busy === 'remote' && <Loader2 className="mr-1.5 h-4 w-4 animate-spin" aria-hidden="true" />} Borrar en Meta
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

// -------------------------------------------------------------- pestaña --

const WaTemplates: React.FC<{
  templates: api.WaTemplate[];
  isAdmin: boolean;
  configured: boolean;
  onChange: (t: api.WaTemplate[]) => void;
  reload: () => void;
}> = ({ templates, isAdmin, configured, onChange, reload }) => {
  const [syncing, setSyncing] = useState(false);
  const [showLibrary, setShowLibrary] = useState(false);
  const [formState, setFormState] = useState<{ initial: TplForm; editing: api.WaTemplate | null } | null>(null);
  const [toDelete, setToDelete] = useState<api.WaTemplate | null>(null);
  const [filter, setFilter] = useState('');

  const sync = async () => {
    setSyncing(true);
    try {
      const r = await api.syncWaTemplates();
      onChange(r.templates);
      toast.success(`${r.synced} plantilla(s) sincronizada(s) con Meta.`);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'No se pudo sincronizar');
    } finally {
      setSyncing(false);
    }
  };

  const openForm = (initial: TplForm, editing: api.WaTemplate | null = null) => {
    setFormState({ initial, editing });
    setShowLibrary(false);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const used = new Set(templates.map((t) => t.element_name));
  const useLibrary = (t: LibTemplate) => {
    const f = fromLibrary(t);
    // Evita chocar con una plantilla que ya existe con ese nombre.
    let name = f.name;
    for (let i = 2; used.has(name); i++) name = `${f.name}_${i}`;
    openForm({ ...f, name });
  };

  const shown = filter ? templates.filter((t) => t.status === filter) : templates;
  const canEdit = (t: api.WaTemplate) => isAdmin && !!t.external_id && t.template_type === 'TEXT' && t.status !== 'PENDING';

  return (
    <div className="space-y-6 p-6">
      {isAdmin && (
        <div className="flex flex-wrap items-center gap-3">
          <button type="button" onClick={sync} disabled={syncing || !configured} className="inline-flex items-center rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">
            <RefreshCw className={`mr-2 h-4 w-4 ${syncing ? 'animate-spin' : ''}`} aria-hidden="true" /> Sincronizar con Meta
          </button>
          <button type="button" onClick={() => setShowLibrary((v) => !v)} disabled={!configured} aria-expanded={showLibrary} className="inline-flex items-center rounded-md border border-green-600 px-4 py-2 text-sm font-medium text-green-700 hover:bg-green-50 disabled:opacity-50">
            <BookOpen className="mr-2 h-4 w-4" aria-hidden="true" /> Biblioteca de ejemplos
          </button>
          <button type="button" onClick={() => openForm({ ...emptyForm })} disabled={!configured} className="inline-flex items-center rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50">
            <Plus className="mr-2 h-4 w-4" aria-hidden="true" /> Nueva plantilla
          </button>
          {!configured && <span className="text-xs text-gray-500">Activa la línea para gestionar plantillas.</span>}
        </div>
      )}

      {isAdmin && showLibrary && <Library onUse={useLibrary} onClose={() => setShowLibrary(false)} />}

      {isAdmin && formState && (
        <TemplateForm
          initial={formState.initial}
          editing={formState.editing}
          onCancel={() => setFormState(null)}
          onSaved={() => { setFormState(null); reload(); }}
        />
      )}

      <div className="flex flex-wrap items-center justify-between gap-3">
        <h3 className="text-sm font-semibold text-gray-900">Tus plantillas ({templates.length})</h3>
        <select value={filter} onChange={(e) => setFilter(e.target.value)} className="rounded-md border border-gray-300 px-3 py-1.5 text-sm" aria-label="Filtrar por estado">
          <option value="">Todos los estados</option>
          {Object.entries(STATUS).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
        </select>
      </div>

      {shown.length === 0 ? (
        <p className="text-sm text-gray-500">
          {templates.length === 0
            ? isAdmin ? 'No hay plantillas. Parte de un ejemplo de la biblioteca, crea una nueva o sincroniza las que ya existan en Meta.' : 'No hay plantillas. Pide a un administrador que las cree o sincronice.'
            : 'No hay plantillas con ese estado.'}
        </p>
      ) : (
        <ul className="divide-y divide-gray-200 rounded-md border border-gray-200">
          {shown.map((t) => (
            <li key={t.id} className="flex flex-wrap items-start justify-between gap-3 p-4">
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-mono text-sm font-medium text-gray-900">{t.element_name}</span>
                  <Badge cls={STATUS[t.status]?.cls ?? 'bg-gray-100 text-gray-700'}>{STATUS[t.status]?.label ?? t.status}</Badge>
                  <Badge cls={CATEGORY_STYLES[t.category] ?? 'bg-gray-100 text-gray-700'}>{CATEGORY_LABELS[t.category] ?? t.category}</Badge>
                  <span className="text-xs text-gray-500">{t.language} · {t.template_type}</span>
                </div>
                {t.header && <p className="mt-1 text-sm font-semibold text-gray-700">{t.header}</p>}
                <p className="mt-1 whitespace-pre-wrap text-sm text-gray-600">{t.body}</p>
                {t.footer && <p className="mt-1 text-xs text-gray-400">{t.footer}</p>}
                {t.buttons.length > 0 && (
                  <p className="mt-1 flex flex-wrap gap-1">
                    {t.buttons.map((b, i) => <span key={i} className="rounded border border-gray-200 px-1.5 py-0.5 text-xs text-gray-600">{b.text}</span>)}
                  </p>
                )}
                {t.reason && t.status !== 'APPROVED' && <p className="mt-1 text-xs text-red-600">Motivo de Meta: {t.reason}</p>}
              </div>
              {isAdmin && (
                <div className="flex shrink-0 items-center gap-1">
                  {canEdit(t) && (
                    <button type="button" onClick={() => openForm(fromExisting(t, false), t)} className="rounded p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-800" title="Editar y reenviar a revisión" aria-label={`Editar ${t.element_name}`}>
                      <Pencil className="h-4 w-4" aria-hidden="true" />
                    </button>
                  )}
                  {t.template_type === 'TEXT' && (
                    <button type="button" onClick={() => openForm(fromExisting(t, true))} className="rounded p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-800" title="Duplicar como nueva plantilla" aria-label={`Duplicar ${t.element_name}`}>
                      <Copy className="h-4 w-4" aria-hidden="true" />
                    </button>
                  )}
                  <button type="button" onClick={() => setToDelete(t)} className="rounded p-2 text-gray-400 hover:bg-red-50 hover:text-red-600" title="Eliminar" aria-label={`Eliminar ${t.element_name}`}>
                    <Trash2 className="h-4 w-4" aria-hidden="true" />
                  </button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}

      {toDelete && <DeleteDialog t={toDelete} onClose={() => setToDelete(null)} onDone={() => { setToDelete(null); reload(); }} />}
    </div>
  );
};

export default WaTemplates;
