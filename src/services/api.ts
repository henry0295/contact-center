// Mismo origen por defecto (Nginx enruta /api/ a public/index.php). En desarrollo
// (vite :5173 + php :8000) se define VITE_API_URL=http://localhost:8000/api en .env.
const BASE = import.meta.env.VITE_API_URL || '/api';

const TOKEN_KEY = 'token';

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token);
}

export function clearToken(): void {
  localStorage.removeItem(TOKEN_KEY);
}

/** Se dispara cuando el backend responde 401: el UserContext lo escucha para cerrar sesión. */
export const AUTH_ERROR_EVENT = 'auth:unauthorized';

const REFRESH_KEY = 'refreshToken';

export function getRefreshToken(): string | null {
  return localStorage.getItem(REFRESH_KEY);
}

export function setRefreshToken(token: string): void {
  localStorage.setItem(REFRESH_KEY, token);
}

export function clearRefreshToken(): void {
  localStorage.removeItem(REFRESH_KEY);
}

// ---------------------------------------------------------------------------
// Tipos
// ---------------------------------------------------------------------------

export interface DomainRow {
  id: number;
  domain: string;
  verification_token: string | null;
  verification_status: string;
  dkim_enabled: boolean;
  dkim_status: string | null;
  dkim_tokens: string[];
  created_at: string;
  updated_at: string;
}

export interface SenderRow {
  id: number;
  email: string;
  verification_status: string;
  /** Remitente por defecto de su tipo: MAIL_FROM si es 'sender', Reply-To si es 'reply'. */
  is_default: boolean;
  /** 'sender' (aparece en "De") o 'reply' (recibe las respuestas vía Reply-To). */
  type: 'sender' | 'reply';
  created_at: string;
  updated_at: string;
}

export interface VerifyDomainResponse {
  success: boolean;
  message: string;
  verificationToken: string;
  dkimTokens: string[];
}

export interface DomainStatusResponse {
  domain: string;
  verificationStatus: string;
  dkimStatus: { enabled: boolean; status: string; tokens: string[] };
}

export interface VerifySenderResponse {
  success: boolean;
  message: string;
  email: string;
  type: 'sender' | 'reply';
  /** true si el remitente heredó la verificación de su dominio. */
  domainVerified: boolean;
  /** true si quedó marcado como por defecto de su tipo (MAIL_FROM o Reply-To). */
  isDefault: boolean;
}

/** Crear remitente: parte local + dominio verificado, o un correo completo. */
export interface CreateSenderPayload {
  localPart?: string;
  domain?: string;
  email?: string;
  setAsDefault?: boolean;
  /** 'sender' (por defecto) o 'reply'. */
  type?: 'sender' | 'reply';
}

export interface SenderStatusResponse {
  email: string;
  verificationStatus: string;
}

export interface ListDomainsResponse {
  domains: DomainRow[];
  /** true si los estados se reconciliaron contra SES en esta respuesta. */
  synced?: boolean;
}

export interface ListSendersResponse {
  senders: SenderRow[];
  /** true si los estados se sincronizaron contra SES en esta respuesta. */
  synced?: boolean;
}

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  role: string;
}

export interface LoginResponse {
  token: string;
  expiresIn: number;
  refreshToken: string;
  refreshExpiresIn: number;
  user: AuthUser;
  /** Presente en la respuesta del cambio de contraseña. */
  message?: string;
}

/** Metadatos de paginación que devuelven los listados del backend. */
export interface Pagination {
  page: number;
  perPage: number;
  total: number;
  totalPages: number;
  hasMore: boolean;
}

export interface PageQuery {
  page?: number;
  perPage?: number;
}

/**
 * Construye la cadena de consulta omitiendo valores vacíos.
 * Recibe "object" (no Record<string, ...>) a propósito: las interfaces como
 * PageQuery no declaran una firma de índice, y TypeScript no las deja
 * asignar a Record<string, T> aunque sean estructuralmente compatibles.
 */
function qs(params: object): string {
  const entries = Object.entries(params as Record<string, string | number | undefined | null>).filter(
    ([, v]) => v !== undefined && v !== null && v !== ''
  );
  if (entries.length === 0) return '';
  return '?' + entries.map(([k, v]) => `${k}=${encodeURIComponent(String(v))}`).join('&');
}

export interface Contact {
  id: number;
  name: string | null;
  email: string | null;
  phone: string | null;
  created_at: string;
}

export interface ContactList {
  id: number;
  name: string;
  description: string | null;
  member_count: number;
  created_at: string;
}

export interface Template {
  id: number;
  name: string;
  channel: 'email' | 'sms';
  subject: string | null;
  body: string;
  /** Estado del editor de bloques, para poder reabrir y seguir editando. */
  design_json: string | null;
  created_at: string;
}

export interface Campaign {
  id: number;
  name: string;
  channel: 'email' | 'sms' | 'call' | 'whatsapp';
  template_id: number | null;
  subject: string | null;
  body: string | null;
  status: string;
  scheduled_at: string | null;
  total_recipients: number;
  sent_count: number;
  failed_count: number;
  created_at: string;
  /** Remitente alfanumérico visible del SMS (si se indicó). */
  sender_id: string | null;
  /** Caducidad: pasada esa fecha, el envío ya no se entrega. */
  expires_at: string | null;
  /** Etiquetas libres, separadas por coma. */
  tags: string | null;
  /** Segmentos por mensaje (SMS). */
  segments: number;
  /** Coste estimado al crear la campaña (informativo). */
  cost_estimate: string | number | null;
  /** 'direct' (compositor), 'campaign' (API) o 'test' (envío de prueba). */
  origin: 'direct' | 'campaign' | 'test' | string;
  /** Voz de la locución (solo channel 'call'): F femenina, M masculina. */
  voice_gender: 'F' | 'M' | null;
  /** Email del usuario que la creó (null en campañas antiguas o de consola). */
  created_by?: string | null;
  /** Solo en el listado: gasto neto, respuestas y bajas. */
  spent?: number;
  replies?: number;
  opt_outs?: number;
}

export interface CampaignRecipient {
  id: number;
  campaign_id: number;
  email: string | null;
  phone: string | null;
  status: string;
  error: string | null;
  sent_at: string | null;
  /** WhatsApp: accepted | sent | delivered | read | failed. */
  delivery_status?: string | null;
}

export interface BulkResult {
  success: boolean;
  total: number;
  sent: number;
  failed: number;
  results: { recipient: string; status: string; error?: string }[];
}

// ---- Compositor unificado (SMS y email crean campaña siempre) ----

/** Análisis de un texto de SMS: codificación, longitud y segmentos. */
export interface SmsAnalysis {
  encoding: 'GSM-7' | 'UCS-2';
  length: number;
  segments: number;
  perSegment: number;
  remaining: number;
}

export interface SendCost {
  segmentsPerMessage: number;
  recipients: number;
  totalSegments: number;
  pricePerSegment: number;
  currency: string;
  total: number;
  /** false si no se configuró SMS_PRICE_PER_SEGMENT: el importe no es real. */
  configured: boolean;
}

export interface SendResult {
  sent: number;
  failed: number;
  skipped: number;
  status: string;
}

/** Respuesta común de /sms/send, /sms/test, /email/send y /email/test. */
export interface DispatchResponse {
  success: boolean;
  campaign: Campaign;
  mode: 'sent' | 'scheduled' | 'queued';
  result?: SendResult;
  cost: SendCost;
  message: string;
  /** Sugerencia accionable cuando hubo fallos (p.ej. remitente no verificado). */
  hint?: string | null;
  jobId?: number;
}

/**
 * Una fila con variables (del CSV de personalización): el backend crea o
 * reutiliza el contacto (por name/email/phone) para que {{nombre}} y los
 * demás campos del contacto se resuelvan. Cualquier otra clave (p.ej. "valor",
 * "fecha_pago") es una variable propia de ESTE envío, no del contacto: se
 * guarda junto al destinatario de la campaña, no en la ficha del contacto.
 */
export interface RecipientRow {
  name?: string;
  email?: string;
  phone?: string;
  [variable: string]: string | undefined;
}

export interface DispatchPayload {
  name?: string;
  /** Solo email. */
  subject?: string;
  body: string;
  /** string[] para destinatarios simples; RecipientRow[] para un CSV con variables. */
  recipients?: (string | RecipientRow)[];
  listId?: number;
  /** Solo SMS: remitente alfanumérico (hasta 15 caracteres). */
  senderId?: string;
  tags?: string;
  scheduleType?: 'now' | 'scheduled';
  scheduledAt?: string;
  expiresAt?: string;
  templateId?: number;
  /** Solo SMS (IT Cloud): permitir que el destinatario responda (doble vía). */
  twoWay?: boolean;
  /**
   * Solo email. Omitido = email de respuesta por defecto; 'none' = sin
   * Reply-To; o una de las direcciones registradas como email de respuesta.
   */
  replyTo?: string;
  /** Solo email: el usuario confirmó los avisos de la revisión de links. */
  acceptLinkWarnings?: boolean;
}

export interface PersonalizationField {
  token: string;
  label: string;
}

export interface PreviousMessage {
  id: number;
  name: string;
  subject: string | null;
  body: string;
  created_at: string;
}

/** Envía (o programa/encola) un SMS. Siempre crea una campaña. */
export function sendSms(payload: DispatchPayload): Promise<DispatchResponse> {
  return request('/sms/send', { method: 'POST', body: jsonBody(payload) });
}

export function sendSmsTest(payload: { to: string; message: string; senderId?: string; twoWay?: boolean }): Promise<DispatchResponse> {
  return request('/sms/test', { method: 'POST', body: jsonBody(payload) });
}

export function smsEstimate(payload: { message: string; recipients: number }): Promise<{ analysis: SmsAnalysis; cost: SendCost }> {
  return request('/sms/estimate', { method: 'POST', body: jsonBody(payload) });
}

export function smsFields(): Promise<{
  fields: PersonalizationField[];
  previous: PreviousMessage[];
  /** false con IT Cloud: el remitente lo fija la cuenta del proveedor. */
  senderIdSupported?: boolean;
  /** true si el proveedor admite SMS de doble vía (respuestas). */
  twoWaySupported?: boolean;
}> {
  return request('/sms/fields');
}

export interface SmsReply {
  id: number;
  phone: string;
  message: string;
  campaign_id: number | null;
  campaign_name: string | null;
  contact_name: string | null;
  /** true si respondió SALIR/STOP/BAJA: el contacto quedó dado de baja. */
  is_optout: boolean;
  received_at: string;
}

export function listSmsReplies(params: { page?: number; perPage?: number; campaignId?: number; q?: string } = {}): Promise<{
  replies: SmsReply[]; total: number; page: number; perPage: number;
}> {
  return request('/sms/replies' + qs(params));
}

export function emailFields(): Promise<{ fields: PersonalizationField[]; previous: PreviousMessage[] }> {
  return request('/email/fields');
}

/** Envía (o programa/encola) un email. Siempre crea una campaña. */
export function sendEmailCampaign(
  payload: DispatchPayload & { attachments?: File[] }
): Promise<DispatchResponse> {
  if (payload.attachments && payload.attachments.length > 0) {
    const form = new FormData();
    if (payload.name) form.append('name', payload.name);
    if (payload.subject) form.append('subject', payload.subject);
    form.append('body', payload.body);
    // Si son filas con variables (CSV de personalización) no caben como campos
    // repetidos de FormData: viajan serializadas y el backend las decodifica.
    const recipients = payload.recipients ?? [];
    if (recipients.some((r) => typeof r === 'object')) {
      form.append('recipients', JSON.stringify(recipients));
    } else {
      (recipients as string[]).forEach((r) => form.append('recipients[]', r));
    }
    if (payload.listId) form.append('listId', String(payload.listId));
    if (payload.tags) form.append('tags', payload.tags);
    if (payload.scheduleType) form.append('scheduleType', payload.scheduleType);
    if (payload.scheduledAt) form.append('scheduledAt', payload.scheduledAt);
    if (payload.expiresAt) form.append('expiresAt', payload.expiresAt);
    if (payload.templateId) form.append('templateId', String(payload.templateId));
    if (payload.replyTo !== undefined) form.append('replyTo', payload.replyTo);
    if (payload.acceptLinkWarnings) form.append('acceptLinkWarnings', 'true');
    payload.attachments.forEach((f) => form.append('attachments[]', f));
    return request('/email/send', { method: 'POST', body: form });
  }
  const { attachments, ...rest } = payload;
  void attachments;
  return request('/email/send', { method: 'POST', body: jsonBody(rest) });
}

export function sendEmailTest(payload: { to: string; subject: string; body: string; replyTo?: string; acceptLinkWarnings?: boolean }): Promise<DispatchResponse> {
  return request('/email/test', { method: 'POST', body: jsonBody(payload) });
}

// ---- Revisión de links del email ----
export interface LinkIssue {
  url: string;
  text: string | null;
  /** dangerous_scheme, ip_address, userinfo, deceptive_text, insecure, shortener, foreign_domain... */
  rule: string;
  message: string;
  /** Solo en errores por fila del CSV. */
  recipient?: string | null;
}

export interface LinkCheckResult {
  links: { url: string; text: string | null; status: 'ok' | 'warning' | 'error' }[];
  errors: LinkIssue[];
  warnings: LinkIssue[];
  /** Hay links con variables ({{link_pago}}): se revisan por fila al enviar. */
  hasVariableLinks?: boolean;
}

export function checkEmailLinks(body: string): Promise<LinkCheckResult> {
  return request('/email/check-links', { method: 'POST', body: jsonBody({ body }) });
}

/** Datos de un 422 de la revisión de links (error.payload de request()). */
export interface LinkCheckErrorPayload {
  error: string;
  code: 'link_blocked' | 'link_warnings';
  needsConfirmation: boolean;
  linkCheck: { errors: LinkIssue[]; warnings: LinkIssue[] };
}

export function linkCheckError(err: unknown): LinkCheckErrorPayload | null {
  const p = (err as { payload?: unknown } | null)?.payload as Partial<LinkCheckErrorPayload> | undefined;
  return p && (p.code === 'link_blocked' || p.code === 'link_warnings') && p.linkCheck ? (p as LinkCheckErrorPayload) : null;
}

// ---------------------------------------------------------------------------
// Llamadas de voz (TTS + Asterisk, API externa)
// ---------------------------------------------------------------------------

export interface CallCapabilities {
  configured: boolean;
  maxChars: number;
  /** Solo presente cuando el servicio no está disponible. */
  notice: string | null;
}

export function getCallCapabilities(): Promise<CallCapabilities> {
  return request('/calls/capabilities');
}

/** Envía (o programa/encola) una campaña de llamadas. Siempre crea una campaña. */
export function sendCall(payload: DispatchPayload & { voiceGender?: 'F' | 'M' }): Promise<DispatchResponse> {
  return request('/calls/send', { method: 'POST', body: jsonBody(payload) });
}

export function sendCallTest(payload: { to: string; message: string; voiceGender?: 'F' | 'M' }): Promise<DispatchResponse> {
  return request('/calls/test', { method: 'POST', body: jsonBody(payload) });
}

export function callFields(): Promise<{ fields: PersonalizationField[]; previous: PreviousMessage[] }> {
  return request('/calls/fields');
}

export type DashboardPeriod = 'today' | '7d' | '30d' | 'month';
export type DashboardChannel = 'email' | 'sms' | 'call' | 'whatsapp';

export interface DashboardChannelStats {
  channel: DashboardChannel;
  sent: number;
  failed: number;
  /** Pendientes o llamadas aún marcando. */
  pending: number;
  total: number;
  /** % de enviados sobre enviados + fallidos. */
  deliveryRate: number;
}

export interface DashboardStats {
  period: DashboardPeriod;
  since: string;
  emailsSent: number;
  smsSent: number;
  totalCampaigns: number;
  activeCampaigns: number;
  recentCampaigns: Campaign[];
  byChannel: DashboardChannelStats[];
  spend: {
    /** Gasto neto del periodo (débitos − reembolsos), en pesos. */
    total: number;
    byChannel: Record<DashboardChannel, number>;
    balance: number;
    /** Consumo medio diario de los últimos 7 días. */
    dailyAvg: number;
    /** Días que alcanza el saldo a ese ritmo (null si no hubo consumo). */
    daysLeft: number | null;
    low: boolean;
  };
  attention: {
    replies: number;
    recentReplies: { channel: 'sms' | 'whatsapp'; phone: string; message: string; receivedAt: string }[];
    optOuts: number;
    emailSuppressed: number;
    upcoming: { id: number; name: string; channel: string; scheduledAt: string; recipients: number }[];
    failedCampaigns: {
      id: number; name: string; channel: string; status: string;
      sent: number; failed: number; total: number; topError: string | null;
    }[];
  };
  /** Solo administradores. */
  channelsHealth?: { channel: DashboardChannel; label: string; status: 'ok' | 'warning' | 'off'; message: string }[];
}

// ---------------------------------------------------------------------------
// Cliente HTTP
// ---------------------------------------------------------------------------

/** Una sola renovación en vuelo: varias peticiones simultáneas la comparten. */
let refreshInFlight: Promise<boolean> | null = null;

/**
 * Canjea el refresh token por un token de acceso nuevo.
 * Devuelve false si ya no es posible (hay que volver a iniciar sesión).
 */
async function tryRefresh(): Promise<boolean> {
  const refresh = getRefreshToken();
  if (!refresh) return false;

  if (refreshInFlight) return refreshInFlight;

  refreshInFlight = (async () => {
    try {
      const res = await fetch(`${BASE}/auth/refresh`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refreshToken: refresh }),
      });
      if (!res.ok) return false;

      const data = (await res.json()) as LoginResponse;
      setToken(data.token);
      // El backend rota el refresh token en cada uso: hay que guardar el nuevo.
      setRefreshToken(data.refreshToken);
      return true;
    } catch {
      return false;
    } finally {
      refreshInFlight = null;
    }
  })();

  return refreshInFlight;
}

async function rawRequest(path: string, options: RequestInit): Promise<Response> {
  const headers: Record<string, string> = {
    ...(options.headers as Record<string, string> | undefined),
  };

  // Content-Type JSON solo cuando el cuerpo es string (JSON). Para FormData
  // (subida de CSV) el navegador pone el boundary multipart automáticamente.
  if (options.body && typeof options.body === 'string') {
    headers['Content-Type'] = 'application/json';
  }

  const token = getToken();
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  return fetch(`${BASE}${path}`, { ...options, headers });
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  let res = await rawRequest(path, options);

  // Si el token caducó, se renueva de forma transparente y se reintenta una vez.
  // El cuerpo FormData no se puede reenviar si ya se consumió, pero fetch solo
  // lo lee al enviar, así que reutilizarlo aquí es seguro.
  if (res.status === 401 && !path.startsWith('/auth/')) {
    const renewed = await tryRefresh();
    if (renewed) {
      res = await rawRequest(path, options);
    }
  }

  if (res.status === 401) {
    clearToken();
    clearRefreshToken();
    window.dispatchEvent(new CustomEvent(AUTH_ERROR_EVENT));
  }

  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const error = new Error(data.message || data.error || `Error ${res.status}`);
    // Se expone el código para que la interfaz distinga casos (429, 403...).
    (error as Error & { status?: number }).status = res.status;
    (error as Error & { payload?: unknown }).payload = data;
    throw error;
  }
  return data as T;
}

/**
 * Descarga un archivo autenticado (CSV de reportes). Usa el nombre que envía
 * el servidor en Content-Disposition; si no hay, `fallbackName`.
 */
async function downloadFile(path: string, fallbackName: string): Promise<void> {
  let res = await rawRequest(path, { method: 'GET' });
  if (res.status === 401 && (await tryRefresh())) {
    res = await rawRequest(path, { method: 'GET' });
  }
  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    throw new Error(data.message || data.error || `Error ${res.status}`);
  }
  const blob = await res.blob();
  const match = /filename="?([^";]+)"?/i.exec(res.headers.get('Content-Disposition') ?? '');
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = match?.[1] ?? fallbackName;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function jsonBody(obj: unknown): string {
  return JSON.stringify(obj);
}

// ---- Auth ----
export function login(email: string, password: string): Promise<LoginResponse> {
  return request<LoginResponse>('/auth/login', { method: 'POST', body: jsonBody({ email, password }) });
}

/** Usuario autenticado según el servidor. Valida además que la sesión siga viva. */
export function getMe(): Promise<{ user: AuthUser }> {
  return request('/auth/me');
}
export function logout(): Promise<{ success: boolean }> {
  return request('/auth/logout', { method: 'POST', body: jsonBody({}) });
}
export function logoutAll(): Promise<{ success: boolean; revoked: number }> {
  return request('/auth/logout-all', { method: 'POST', body: jsonBody({}) });
}
export function changePassword(currentPassword: string, newPassword: string): Promise<LoginResponse> {
  return request('/auth/change-password', {
    method: 'POST',
    body: jsonBody({ currentPassword, newPassword }),
  });
}

// ---- Usuarios ----
export function listUsers(): Promise<{ users: AuthUser[] }> {
  return request('/users');
}
export function resetUserPassword(id: number, newPassword: string): Promise<{ success: boolean; message: string; sessionsRevoked: number }> {
  return request(`/users/${id}/reset-password`, { method: 'POST', body: jsonBody({ newPassword }) });
}
export function createUser(payload: { name: string; email: string; password: string; role?: string }): Promise<{ success: boolean; user: AuthUser }> {
  return request('/users', { method: 'POST', body: jsonBody(payload) });
}
export function deleteUser(id: number): Promise<{ success: boolean }> {
  return request(`/users/${id}`, { method: 'DELETE' });
}

// ---- Verificación (SES) ----
export function verifyDomain(domain: string): Promise<VerifyDomainResponse> {
  return request('/domains/verify', { method: 'POST', body: jsonBody({ domain }) });
}
export function getDomainStatus(domain: string): Promise<DomainStatusResponse> {
  return request(`/domains/${encodeURIComponent(domain)}/status`);
}
/** Listado rápido: lee de la base de datos, sin consultar Amazon SES. */
export function listDomains(): Promise<ListDomainsResponse> {
  return request('/domains');
}

/** Reconcilia los estados contra Amazon SES antes de responder (más lento). */
export function syncDomains(): Promise<ListDomainsResponse> {
  return request('/domains?sync=1');
}
export function deleteDomain(domain: string): Promise<{ success: boolean; message: string; affectedSenders: number; sesWarning: string | null }> {
  return request(`/domains/${encodeURIComponent(domain)}`, { method: 'DELETE' });
}
export function reverifyDomain(domain: string): Promise<VerifyDomainResponse> {
  return request(`/domains/${encodeURIComponent(domain)}/reverify`, { method: 'POST', body: jsonBody({}) });
}
export function verifySender(payload: CreateSenderPayload): Promise<VerifySenderResponse> {
  return request('/senders/verify', { method: 'POST', body: jsonBody(payload) });
}

/** Marca un remitente existente como el remitente por defecto (MAIL_FROM). */
export function setDefaultSender(email: string): Promise<{ success: boolean; message: string; email: string; verified: boolean }> {
  return request('/senders/default', { method: 'POST', body: jsonBody({ email }) });
}
export function getSenderStatus(email: string): Promise<SenderStatusResponse> {
  return request(`/senders/${encodeURIComponent(email)}/status`);
}
/** Listado rápido: lee de la base de datos, sin consultar Amazon SES. */
export function listSenders(type?: 'sender' | 'reply'): Promise<ListSendersResponse> {
  return request('/senders' + qs({ type }));
}

/** Sincroniza los estados contra Amazon SES antes de responder (más lento). */
export function syncSenders(type?: 'sender' | 'reply'): Promise<ListSendersResponse> {
  return request('/senders' + qs({ sync: 1, type }));
}
export function deleteSender(email: string): Promise<{ success: boolean; message: string; wasDefault: boolean; newDefault: string | null }> {
  return request(`/senders/${encodeURIComponent(email)}`, { method: 'DELETE' });
}
export function resendSenderVerification(email: string): Promise<{ success: boolean; message: string; email: string }> {
  return request(`/senders/${encodeURIComponent(email)}/resend`, { method: 'POST', body: jsonBody({}) });
}

// ---- Envíos ----

/** Límites alineados con App\Support\Attachments del backend. */
export const ATTACHMENT_LIMITS = {
  maxFiles: 10,
  maxFileBytes: 5 * 1024 * 1024,
  maxTotalBytes: 7 * 1024 * 1024,
  blockedExtensions: [
    'exe', 'bat', 'cmd', 'com', 'cpl', 'dll', 'scr', 'msi', 'msp', 'jar',
    'vbs', 'vbe', 'js', 'jse', 'ws', 'wsf', 'wsc', 'wsh', 'ps1', 'ps1xml',
    'psc1', 'msh', 'msh1', 'scf', 'lnk', 'inf', 'reg', 'app', 'hta', 'pif',
  ],
};

/** Sube una imagen y devuelve su URL absoluta (para usarla en los editores). */
export function uploadImage(file: File): Promise<{ success: boolean; url: string; name: string }> {
  const form = new FormData();
  form.append('file', file);
  return request('/uploads/image', { method: 'POST', body: form });
}

// ---- Contactos y listas ----
export function listContacts(
  params: PageQuery & { search?: string } = {}
): Promise<{ contacts: Contact[]; pagination: Pagination }> {
  return request('/contacts' + qs(params));
}
export function createContact(payload: { name?: string; email?: string; phone?: string }): Promise<{ success: boolean; contact: Contact }> {
  return request('/contacts', { method: 'POST', body: jsonBody(payload) });
}
export function deleteContact(id: number): Promise<{ success: boolean }> {
  return request(`/contacts/${id}`, { method: 'DELETE' });
}
export function listLists(): Promise<{ lists: ContactList[] }> {
  return request('/lists');
}
export function createList(payload: { name: string; description?: string; contactIds?: number[] }): Promise<{ success: boolean; list: ContactList }> {
  return request('/lists', { method: 'POST', body: jsonBody(payload) });
}
export function deleteList(id: number): Promise<{ success: boolean }> {
  return request(`/lists/${id}`, { method: 'DELETE' });
}
export function listMembers(listId: number): Promise<{ contacts: Contact[] }> {
  return request(`/lists/${listId}/members`);
}
export function addListMembers(listId: number, contactIds: number[]): Promise<{ success: boolean; added: number; contacts: Contact[] }> {
  return request(`/lists/${listId}/members`, { method: 'POST', body: jsonBody({ contactIds }) });
}
export function removeListMember(listId: number, contactId: number): Promise<{ success: boolean }> {
  return request(`/lists/${listId}/members/${contactId}`, { method: 'DELETE' });
}
export function importContacts(file: File, listId?: number): Promise<{ success: boolean; imported: number; skipped: number }> {
  const form = new FormData();
  form.append('file', file);
  if (listId) form.append('listId', String(listId));
  return request('/contacts/import', { method: 'POST', body: form });
}

// ---- Plantillas ----
export function listTemplates(channel?: 'email' | 'sms'): Promise<{ templates: Template[] }> {
  return request(`/templates${channel ? `?channel=${channel}` : ''}`);
}
export function createTemplate(payload: { name: string; channel: 'email' | 'sms'; subject?: string; body: string; designJson?: string }): Promise<{ success: boolean; template: Template; linkCheck?: { errors: LinkIssue[]; warnings: LinkIssue[] } | null }> {
  return request('/templates', { method: 'POST', body: jsonBody(payload) });
}
export function updateTemplate(id: number, payload: { name: string; subject?: string; body: string; designJson?: string }): Promise<{ success: boolean; template: Template; linkCheck?: { errors: LinkIssue[]; warnings: LinkIssue[] } | null }> {
  return request(`/templates/${id}`, { method: 'PUT', body: jsonBody(payload) });
}
export function deleteTemplate(id: number): Promise<{ success: boolean }> {
  return request(`/templates/${id}`, { method: 'DELETE' });
}

// ---- Campañas ----
// Ya no hay un formulario de "crear campaña" aparte: toda campaña nace de los
// compositores de SMS/Email (sendSms / sendEmailCampaign). Este bloque cubre
// solo listar, ver el detalle y las acciones sobre una campaña ya creada,
// que es lo que necesita la pestaña Campañas de Reportes.
export interface CampaignFilters {
  status?: string;
  channel?: string;
  origin?: string;
  /** Nombre o etiquetas. */
  search?: string;
  /** Y-m-d */
  from?: string;
  to?: string;
  createdBy?: string;
}

export function listCampaigns(
  params: PageQuery & CampaignFilters = {}
): Promise<{ campaigns: Campaign[]; pagination: Pagination; creators?: string[] }> {
  return request('/campaigns' + qs(params));
}

/** Descarga de la campaña: destinatarios con estado y motivo (CSV). */
export function exportCampaign(id: number): Promise<void> {
  return downloadFile(`/campaigns/${id}/export`, `campana-${id}.csv`);
}
/** Cancela la programación: la campaña vuelve a borrador. */
export function cancelCampaignSchedule(id: number): Promise<{ success: boolean; message: string; campaign: Campaign }> {
  return request(`/campaigns/${id}/cancel`, { method: 'POST', body: jsonBody({}) });
}
export function getCampaign(id: number): Promise<{
  campaign: Campaign;
  stats?: CampaignPerformance;
  failureReasons?: FailureReason[];
  recipients: CampaignRecipient[];
  attachments: { name: string }[];
}> {
  return request(`/campaigns/${id}`);
}
export interface CampaignRunResult {
  sent: number;
  failed: number;
  skipped?: number;
  /** 'Completed', 'Failed' (si no se entregó ninguno) o 'Expired'. */
  status: string;
}
export function sendCampaignNow(id: number): Promise<{ success: boolean; campaign: Campaign; result: CampaignRunResult }> {
  return request(`/campaigns/${id}/send`, { method: 'POST', body: jsonBody({}) });
}
export function deleteCampaign(id: number): Promise<{ success: boolean }> {
  return request(`/campaigns/${id}`, { method: 'DELETE' });
}

// ---- Saldo (solo lectura; la recarga es por consola en el servidor) ----
export interface Balance {
  /** Saldo disponible en pesos. */
  balance: number;
  currency: string;
  /** % del saldo respecto al que quedó tras la última recarga. */
  percent: number;
  /** true si queda menos del 20 %. */
  low: boolean;
}

export function getBalance(): Promise<Balance> {
  return request('/balance');
}

// ---- WhatsApp (Gupshup) ----
export type WaCategory = 'MARKETING' | 'UTILITY' | 'AUTHENTICATION';

export interface WaButton {
  type: 'QUICK_REPLY' | 'URL' | 'PHONE_NUMBER' | 'OTP' | string;
  text: string;
  url?: string;
  phone_number?: string;
}

export interface WaAuthOptions {
  /** Añade "Por tu seguridad, no compartas este código". */
  securityRecommendation: boolean;
  /** 1-90; añade "Este código vence en N minutos". */
  expirationMinutes?: number | null;
  buttonText: string;
}

export interface WaTemplate {
  id: number;
  external_id: string | null;
  element_name: string;
  language: string;
  category: WaCategory | string;
  /** TEXT, IMAGE, VIDEO, DOCUMENT... */
  template_type: string;
  header: string | null;
  body: string;
  footer: string | null;
  buttons: WaButton[];
  example: string | null;
  /** Número de variables {{1}}..{{n}} del cuerpo. */
  var_count: number;
  /** APPROVED, PENDING, REJECTED, PAUSED, DISABLED... */
  status: string;
  reason: string | null;
  created_at: string;
  updated_at: string;
}

export interface WaStatus {
  configured: boolean;
  appId: string | null;
  healthy: boolean;
  /** true mientras el número no se haya registrado con Meta. */
  pendingSignup?: boolean;
  /** Mensaje legible para el cliente. */
  healthMessage: string | null;
  /** Detalle técnico (solo administradores). */
  healthDetail?: string | null;
  /** Calidad del número según Meta (GREEN, YELLOW, RED). */
  quality: string | null;
  /** Límite de conversaciones iniciadas por día (TIER_1K...). */
  messagingLimit: string | null;
  checkedAt: string | null;
  /** Solo para administradores: enlace de registro de la línea. */
  onboardingLink: string | null;
  webhookConfigured: boolean;
}

export interface WaReply {
  id: number;
  phone: string;
  profile_name: string | null;
  message_type: string;
  message: string;
  campaign_id: number | null;
  campaign_name: string | null;
  contact_name: string | null;
  is_optout: boolean;
  received_at: string;
}

export interface WaSendPayload {
  name?: string;
  templateId: number;
  /** Valores de {{1}}..{{n}}; admiten campos como {{nombre}}. */
  params: string[];
  /** URL pública de la imagen/video/documento de cabecera (plantillas multimedia). */
  headerMediaUrl?: string;
  recipients?: (string | RecipientRow)[];
  listId?: number;
  tags?: string;
  scheduleType?: 'now' | 'scheduled';
  scheduledAt?: string;
  expiresAt?: string;
}

export interface WaTemplateInput {
  name: string;
  category: WaCategory;
  language: string;
  header?: string;
  body: string;
  footer?: string;
  /** Un ejemplo por variable, en orden. */
  examples: string[];
  buttons?: WaButton[];
  /** Solo AUTHENTICATION (el texto lo fija Meta). */
  auth?: WaAuthOptions;
}

export function getWhatsAppStatus(refresh = false): Promise<WaStatus> {
  return request('/whatsapp/status' + (refresh ? '?refresh=1' : ''));
}

export function listWaTemplates(status?: string): Promise<{ templates: WaTemplate[] }> {
  return request('/whatsapp/templates' + qs({ status }));
}

export function syncWaTemplates(): Promise<{ success: boolean; synced: number; templates: WaTemplate[] }> {
  return request('/whatsapp/templates/sync', { method: 'POST' });
}

export function createWaTemplate(payload: WaTemplateInput): Promise<{ success: boolean; message: string; template: WaTemplate }> {
  return request('/whatsapp/templates', { method: 'POST', body: jsonBody(payload) });
}

/**
 * remote=false: solo la quita de la lista (reaparece al sincronizar).
 * remote=true: la borra en Meta (irreversible, todos sus idiomas; el nombre
 * no se puede reutilizar durante 30 días).
 */
export function deleteWaTemplate(id: number, remote = false): Promise<{ success: boolean; message?: string; deleted?: number }> {
  return request(`/whatsapp/templates/${id}${remote ? '?remote=1' : ''}`, { method: 'DELETE' });
}

/** Edita y reenvía a revisión de Meta (rechazadas, pausadas o aprobadas). */
export function editWaTemplate(id: number, payload: Omit<WaTemplateInput, 'name' | 'language'>): Promise<{ success: boolean; message: string; template: WaTemplate }> {
  return request(`/whatsapp/templates/${id}`, { method: 'PUT', body: jsonBody(payload) });
}

export function sendWhatsApp(payload: WaSendPayload): Promise<DispatchResponse> {
  return request('/whatsapp/send', { method: 'POST', body: jsonBody(payload) });
}

export function sendWhatsAppTest(payload: { to: string; templateId: number; params: string[]; headerMediaUrl?: string }): Promise<DispatchResponse> {
  return request('/whatsapp/test', { method: 'POST', body: jsonBody(payload) });
}

export interface WaProfile {
  address: string | null;
  description: string | null;
  email: string | null;
  website1: string | null;
  website2: string | null;
  vertical: string | null;
  /** Texto "Info" bajo el nombre (máx. 139). */
  about: string | null;
  photoUrl: string | null;
}

export type WaProfileInput = Partial<Record<Exclude<keyof WaProfile, 'photoUrl'>, string>>;

/** Solo administradores. Requiere el número registrado (app en vivo). */
export function getWaProfile(): Promise<{ profile: WaProfile; verticals: string[] }> {
  return request('/whatsapp/profile');
}

export function updateWaProfile(payload: WaProfileInput): Promise<{ success: boolean; message: string; profile: WaProfile | null }> {
  return request('/whatsapp/profile', { method: 'POST', body: jsonBody(payload) });
}

export function uploadWaProfilePhoto(file: File): Promise<{ success: boolean; message: string }> {
  const form = new FormData();
  form.append('photo', file);
  return request('/whatsapp/profile/photo', { method: 'POST', body: form });
}

export function waFields(): Promise<{ fields: PersonalizationField[] }> {
  return request('/whatsapp/fields');
}

export function listWaReplies(params: { page?: number; perPage?: number; q?: string } = {}): Promise<{
  replies: WaReply[]; total: number; page: number; perPage: number;
}> {
  return request('/whatsapp/replies' + qs(params));
}

// ---- Dashboard ----
export function getDashboardStats(period: DashboardPeriod = '30d'): Promise<DashboardStats> {
  return request('/dashboard/stats' + qs({ period }));
}

// ---------------------------------------------------------------------------
// Reportes e historial de mensajes
// ---------------------------------------------------------------------------

export interface MessageRow {
  id: number;
  channel: string;
  recipient: string;
  subject: string | null;
  status: string;
  error: string | null;
  campaign_id: number | null;
  created_at: string;
}

export interface ChannelTotals {
  channel: string;
  sent: number;
  failed: number;
  total: number;
  deliveryRate: number;
  /** Gasto neto del canal en el periodo (pesos). */
  spend?: number;
}

export interface WaFunnel {
  sent: number;
  delivered: number;
  read: number;
  failed: number;
  deliveredRate: number;
  readRate: number;
}

export interface CallOutcomes {
  answered: number;
  busy: number;
  noAnswer: number;
  otherFailed: number;
  dialing: number;
  answerRate: number;
}

export interface FailureReason {
  reason: string;
  count: number;
  channel: string | null;
}

export interface DailyPoint {
  dia: string;
  channel: string;
  status: string;
  total: number;
}

export interface CampaignPerformance {
  id: number;
  name: string;
  channel: string;
  status: string;
  total: number;
  sent: number;
  failed: number;
  deliveryRate: number;
  createdAt: string;
  createdBy?: string | null;
  /** Gasto neto (pesos). */
  cost: number;
  refunded: number;
  replies: number;
  optOuts: number;
  /** % de respuestas sobre enviados. */
  responseRate: number;
  funnel: WaFunnel | null;
  calls: CallOutcomes | null;
}

export interface FailureRow {
  recipient: string;
  channel: string;
  fallos: number;
  ultimo: string;
}

export interface ReportSummary {
  range: { from: string | null; to: string | null; days: number; since: string; until: string };
  totals: { sent: number; failed: number; total: number; deliveryRate: number; spend: number; refunded: number };
  /** Mismo número de días inmediatamente anterior. */
  previous: { sent: number; failed: number; total: number; deliveryRate: number; spend: number };
  byChannel: ChannelTotals[];
  dailySeries: DailyPoint[];
  whatsappFunnel: WaFunnel;
  callOutcomes: CallOutcomes;
  replies: { replies: number; optOuts: number };
  failureReasons: FailureReason[];
  campaigns: { total: number; byStatus: Record<string, number> };
  campaignPerformance: CampaignPerformance[];
  topFailures: FailureRow[];
  suppressions: Record<string, number>;
}

export interface SuppressionRow {
  id: number;
  email: string;
  reason: string;
  detail: string | null;
  created_at: string;
}

export function listMessages(
  params: PageQuery & { channel?: string; status?: string; from?: string; to?: string; search?: string } = {}
): Promise<{ messages: MessageRow[]; pagination: Pagination }> {
  return request('/messages' + qs(params));
}

export interface ReportRange { from?: string; to?: string; days?: number; search?: string }

export function getReportSummary(params: ReportRange = {}): Promise<ReportSummary> {
  return request('/reports/summary' + qs(params));
}

export function exportMessages(
  params: { channel?: string; status?: string; from?: string; to?: string; search?: string } = {}
): Promise<void> {
  return downloadFile('/reports/export/messages' + qs(params), 'historial.csv');
}

export function exportCampaigns(params: ReportRange = {}): Promise<void> {
  return downloadFile('/reports/export/campaigns' + qs(params), 'campanas.csv');
}

export function listSuppressions(
  params: PageQuery = {}
): Promise<{ suppressions: SuppressionRow[]; pagination: Pagination }> {
  return request('/suppressions' + qs(params));
}

export function removeSuppression(email: string): Promise<{ success: boolean; email: string }> {
  return request(`/suppressions/${encodeURIComponent(email)}`, { method: 'DELETE' });
}

// ---------------------------------------------------------------------------
// Nota: el módulo de Audio (biblioteca con Amazon Polly) se retiró. La voz
// para llamadas ahora la genera y origina directamente el módulo Llamadas,
// a través de la API de Asterisk (ver sendCall / sendCallTest más arriba).
// ---------------------------------------------------------------------------
