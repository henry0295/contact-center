import React, { useEffect, useMemo, useState } from 'react';
import { ShieldCheck, Globe, Mail, Reply, RefreshCw, Star, AlertCircle, Trash2, RotateCcw, Send } from 'lucide-react';
import toast from 'react-hot-toast';
import { useSesData } from '../context/SesDataContext';
import {
  verifyDomain,
  verifySender,
  setDefaultSender,
  getDomainStatus,
  getSenderStatus,
  deleteDomain,
  reverifyDomain,
  deleteSender,
  resendSenderVerification,
  listSenders,
  type SenderRow,
} from '../services/api';

function statusBadge(status: string | null) {
  const normalized = status ?? '';
  if (normalized === 'Success') {
    return <span className="inline-flex px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">Verificado</span>;
  }
  if (normalized === 'Failed' || normalized === 'TemporaryFailure') {
    return <span className="inline-flex px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-800">Fallido</span>;
  }
  return <span className="inline-flex px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-800">Pendiente</span>;
}

function errorMessage(e: unknown): string {
  return e instanceof Error ? e.message : 'Error inesperado';
}

const Verification: React.FC = () => {
  const [activeTab, setActiveTab] = useState<'dominios' | 'remitentes' | 'respuesta'>('dominios');

  // Los datos vienen del contexto global: ya están cargados al entrar, sin esperas.
  const { domains, senders: allSenders, verifiedDomains, loading, syncing, refresh, sync } = useSesData();

  // El contexto global mezcla ambos tipos; cada pestaña solo ve el suyo.
  const senders = useMemo(() => allSenders.filter((s) => s.type !== 'reply'), [allSenders]);

  // --- Email de respuesta (Reply-To): tiene su propia lista, cargada aparte
  // porque es un caso de uso secundario que no necesita estar en el contexto
  // global compartido por toda la aplicación. ---
  const [replySenders, setReplySenders] = useState<SenderRow[]>([]);
  const [loadingReply, setLoadingReply] = useState(false);
  const [replyEmail, setReplyEmail] = useState('');
  const [submittingReply, setSubmittingReply] = useState(false);
  const [busyReply, setBusyReply] = useState<string | null>(null);
  const [refreshingReply, setRefreshingReply] = useState<string | null>(null);
  const [settingDefaultReply, setSettingDefaultReply] = useState<string | null>(null);

  const loadReplySenders = async () => {
    setLoadingReply(true);
    try {
      const res = await listSenders('reply');
      setReplySenders(res.senders);
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setLoadingReply(false);
    }
  };

  useEffect(() => {
    if (activeTab === 'respuesta') loadReplySenders();
  }, [activeTab]);

  const [domainInput, setDomainInput] = useState('');
  const [localPart, setLocalPart] = useState('');
  const [selectedDomain, setSelectedDomain] = useState('');
  const [submittingDomain, setSubmittingDomain] = useState(false);
  const [submittingSender, setSubmittingSender] = useState(false);
  const [refreshingDomain, setRefreshingDomain] = useState<string | null>(null);
  const [refreshingSender, setRefreshingSender] = useState<string | null>(null);
  const [settingDefault, setSettingDefault] = useState<string | null>(null);
  const [busyDomain, setBusyDomain] = useState<string | null>(null);
  const [busySender, setBusySender] = useState<string | null>(null);
  const [freeEmail, setFreeEmail] = useState('');
  const [submittingFreeEmail, setSubmittingFreeEmail] = useState(false);

  const previewEmail = localPart.trim() && selectedDomain
    ? `${localPart.trim()}@${selectedDomain}`
    : '';

  // Alias para no tocar el resto de los manejadores.
  const loadDomains = refresh;
  const loadSenders = refresh;
  const loadingDomains = loading;
  const loadingSenders = loading;

  // Preselecciona el primer dominio verificado disponible.
  useEffect(() => {
    if (!selectedDomain && verifiedDomains.length > 0) {
      setSelectedDomain(verifiedDomains[0].domain);
    }
  }, [verifiedDomains, selectedDomain]);

  const handleVerifyDomain = async (e: React.FormEvent) => {
    e.preventDefault();
    const domain = domainInput.trim();
    if (!domain) {
      toast.error('Ingresa un dominio.');
      return;
    }
    setSubmittingDomain(true);
    try {
      await verifyDomain(domain);
      toast.success('Verificación de dominio iniciada.');
      setDomainInput('');
      await loadDomains();
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setSubmittingDomain(false);
    }
  };

  const handleRefreshDomain = async (domain: string) => {
    setRefreshingDomain(domain);
    try {
      await getDomainStatus(domain);
      await loadDomains();
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setRefreshingDomain(null);
    }
  };

  const handleVerifySender = async (e: React.FormEvent) => {
    e.preventDefault();
    const local = localPart.trim();
    if (!local) {
      toast.error('Ingresa el nombre del remitente (la parte antes de la @).');
      return;
    }
    if (!selectedDomain) {
      toast.error('Selecciona un dominio verificado.');
      return;
    }
    setSubmittingSender(true);
    try {
      const res = await verifySender({ localPart: local, domain: selectedDomain, setAsDefault: true });
      toast.success(res.message);
      setLocalPart('');
      await loadSenders();
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setSubmittingSender(false);
    }
  };

  const handleDeleteDomain = async (domain: string) => {
    const confirmed = window.confirm(
      `¿Eliminar el dominio ${domain}?\n\n` +
      'Se borrará también su identidad en Amazon SES. Los remitentes que dependían ' +
      'de la verificación de este dominio dejarán de poder enviar.'
    );
    if (!confirmed) return;

    setBusyDomain(domain);
    try {
      const res = await deleteDomain(domain);
      if (res.affectedSenders > 0) {
        toast.success(`Dominio eliminado. Revisa ${res.affectedSenders} remitente(s) de este dominio.`);
      } else {
        toast.success('Dominio eliminado.');
      }
      await Promise.all([loadDomains(), loadSenders()]);
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setBusyDomain(null);
    }
  };

  const handleReverifyDomain = async (domain: string) => {
    const confirmed = window.confirm(
      `¿Regenerar los registros DNS de ${domain}?\n\n` +
      'Se volverán a solicitar el registro TXT y los CNAME de DKIM. Tendrás que ' +
      'publicarlos en tu DNS para completar la verificación.'
    );
    if (!confirmed) return;

    setBusyDomain(domain);
    try {
      const res = await reverifyDomain(domain);
      toast.success(res.message);
      await loadDomains();
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setBusyDomain(null);
    }
  };

  const handleDeleteSender = async (email: string) => {
    if (!window.confirm(`¿Eliminar el remitente ${email}?`)) return;

    setBusySender(email);
    try {
      const res = await deleteSender(email);
      if (res.wasDefault && res.newDefault) {
        toast.success(`Remitente eliminado. Ahora el remitente por defecto es ${res.newDefault}.`);
      } else if (res.wasDefault) {
        toast.success('Remitente eliminado. Ya no hay remitente por defecto: configura otro.');
      } else {
        toast.success('Remitente eliminado.');
      }
      await loadSenders();
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setBusySender(null);
    }
  };

  const handleResendSender = async (email: string) => {
    setBusySender(email);
    try {
      const res = await resendSenderVerification(email);
      toast.success(res.message);
      await loadSenders();
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setBusySender(null);
    }
  };

  const handleVerifyEmailAddress = async (e: React.FormEvent) => {
    e.preventDefault();
    const email = freeEmail.trim();
    if (!email) {
      toast.error('Ingresa un correo.');
      return;
    }
    setSubmittingFreeEmail(true);
    try {
      // setAsDefault: false → verificar un correo externo no debe robarle el
      // rol de remitente por defecto al remitente real.
      const res = await verifySender({ email, setAsDefault: false });
      toast.success(res.message);
      setFreeEmail('');
      await loadSenders();
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setSubmittingFreeEmail(false);
    }
  };

  const handleSetDefault = async (email: string) => {
    setSettingDefault(email);
    try {
      await setDefaultSender(email);
      toast.success(`${email} es ahora el remitente por defecto.`);
      await loadSenders();
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setSettingDefault(null);
    }
  };

  const handleRefreshSender = async (email: string) => {
    setRefreshingSender(email);
    try {
      await getSenderStatus(email);
      await loadSenders();
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setRefreshingSender(null);
    }
  };

  // --- Email de respuesta (Reply-To) ---

  const handleVerifyReply = async (e: React.FormEvent) => {
    e.preventDefault();
    const email = replyEmail.trim();
    if (!email) {
      toast.error('Ingresa un correo.');
      return;
    }
    setSubmittingReply(true);
    try {
      // El primero queda como predeterminado automáticamente; los demás se
      // eligen por envío en el módulo de Email (o con "Usar por defecto").
      const res = await verifySender({ email, type: 'reply' });
      toast.success(res.message);
      setReplyEmail('');
      await loadReplySenders();
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setSubmittingReply(false);
    }
  };

  const handleSetDefaultReply = async (email: string) => {
    setSettingDefaultReply(email);
    try {
      await setDefaultSender(email);
      toast.success(`${email} es ahora el email de respuesta por defecto.`);
      await loadReplySenders();
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setSettingDefaultReply(null);
    }
  };

  const handleDeleteReply = async (email: string) => {
    if (!window.confirm(`¿Eliminar el email de respuesta ${email}?`)) return;

    setBusyReply(email);
    try {
      await deleteSender(email);
      toast.success('Email de respuesta eliminado.');
      await loadReplySenders();
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setBusyReply(null);
    }
  };

  const handleResendReply = async (email: string) => {
    setBusyReply(email);
    try {
      const res = await resendSenderVerification(email);
      toast.success(res.message);
      await loadReplySenders();
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setBusyReply(null);
    }
  };

  const handleRefreshReply = async (email: string) => {
    setRefreshingReply(email);
    try {
      await getSenderStatus(email);
      await loadReplySenders();
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setRefreshingReply(null);
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <div className="flex items-center gap-2">
          <ShieldCheck className="h-6 w-6 text-blue-600" />
          <h1 className="text-2xl font-bold text-gray-900">Verificación de Dominios y Remitentes</h1>
        </div>
        <div className="mt-1 flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm text-gray-500">
            Verifica dominios y remitentes en Amazon SES y publica los registros DNS requeridos.
          </p>
          <button
            type="button"
            onClick={() => sync()}
            disabled={syncing}
            title="Consultar el estado actual directamente en Amazon SES"
            className="inline-flex shrink-0 items-center rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
          >
            <RefreshCw className={`mr-2 h-4 w-4 ${syncing ? 'animate-spin' : ''}`} />
            {syncing ? 'Sincronizando...' : 'Sincronizar con SES'}
          </button>
        </div>
      </div>

      <div className="bg-white shadow rounded-lg overflow-hidden">
        <div className="border-b border-gray-200">
          <nav className="-mb-px flex">
            <button
              onClick={() => setActiveTab('dominios')}
              className={`w-1/3 py-4 px-1 text-center border-b-2 font-medium text-sm ${
                activeTab === 'dominios'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              <div className="flex justify-center items-center">
                <Globe className="mr-2 h-5 w-5" />
                Dominios
              </div>
            </button>
            <button
              onClick={() => setActiveTab('remitentes')}
              className={`w-1/3 py-4 px-1 text-center border-b-2 font-medium text-sm ${
                activeTab === 'remitentes'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              <div className="flex justify-center items-center">
                <Mail className="mr-2 h-5 w-5" />
                Remitentes
              </div>
            </button>
            <button
              onClick={() => setActiveTab('respuesta')}
              className={`w-1/3 py-4 px-1 text-center border-b-2 font-medium text-sm ${
                activeTab === 'respuesta'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              <div className="flex justify-center items-center">
                <Reply className="mr-2 h-5 w-5" />
                Email de respuesta
              </div>
            </button>
          </nav>
        </div>

        <div className="p-6">
          {activeTab === 'dominios' && (
            <div className="space-y-6">
              <form onSubmit={handleVerifyDomain} className="flex flex-col sm:flex-row gap-3 sm:items-end">
                <div className="flex-1">
                  <label htmlFor="domain" className="block text-sm font-medium text-gray-700">
                    Dominio
                  </label>
                  <div className="mt-1">
                    <input
                      type="text"
                      id="domain"
                      value={domainInput}
                      onChange={(e) => setDomainInput(e.target.value)}
                      className="shadow-sm focus:ring-blue-500 focus:border-blue-500 block w-full sm:text-sm border-gray-300 rounded-md"
                      placeholder="ejemplo.com"
                    />
                  </div>
                </div>
                <button
                  type="submit"
                  disabled={submittingDomain}
                  className="inline-flex justify-center items-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
                >
                  <Globe className="-ml-1 mr-2 h-4 w-4" />
                  {submittingDomain ? 'Verificando...' : 'Verificar dominio'}
                </button>
              </form>

              {loadingDomains ? (
                <p className="text-sm text-gray-500">Cargando dominios...</p>
              ) : domains.length === 0 ? (
                <p className="text-sm text-gray-500">No hay dominios registrados todavía.</p>
              ) : (
                <div className="space-y-4">
                  {domains.map((d) => (
                    <div key={d.id} className="border border-gray-200 rounded-md p-4">
                      <div className="flex items-center justify-between flex-wrap gap-2">
                        <div className="flex items-center gap-3">
                          <span className="text-sm font-medium text-gray-900">{d.domain}</span>
                          {statusBadge(d.verification_status)}
                        </div>
                        <div className="flex items-center gap-2 flex-wrap">
                          <button
                            type="button"
                            onClick={() => handleRefreshDomain(d.domain)}
                            disabled={refreshingDomain === d.domain}
                            className="inline-flex items-center py-1.5 px-3 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
                          >
                            <RefreshCw className={`mr-2 h-4 w-4 ${refreshingDomain === d.domain ? 'animate-spin' : ''}`} />
                            Actualizar estado
                          </button>
                          <button
                            type="button"
                            onClick={() => handleReverifyDomain(d.domain)}
                            disabled={busyDomain === d.domain}
                            className="inline-flex items-center py-1.5 px-3 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
                          >
                            <RotateCcw className="mr-2 h-4 w-4" />
                            Regenerar registros
                          </button>
                          <button
                            type="button"
                            onClick={() => handleDeleteDomain(d.domain)}
                            disabled={busyDomain === d.domain}
                            className="inline-flex items-center py-1.5 px-3 border border-red-300 rounded-md text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 disabled:opacity-50"
                          >
                            <Trash2 className="mr-2 h-4 w-4" />
                            Eliminar
                          </button>
                        </div>
                      </div>

                      <div className="mt-4">
                        <h4 className="text-xs font-semibold text-gray-700 uppercase tracking-wide">Registros DNS a publicar</h4>
                        <div className="mt-2 space-y-2">
                          {d.verification_token && (
                            <div className="bg-gray-50 border border-gray-200 rounded p-3 font-mono text-xs text-gray-800 overflow-x-auto">
                              <div><span className="text-gray-500">Tipo:</span> TXT</div>
                              <div><span className="text-gray-500">Nombre:</span> _amazonses.{d.domain}</div>
                              <div><span className="text-gray-500">Valor:</span> {d.verification_token}</div>
                            </div>
                          )}
                          {d.dkim_tokens.map((t) => (
                            <div key={t} className="bg-gray-50 border border-gray-200 rounded p-3 font-mono text-xs text-gray-800 overflow-x-auto">
                              <div><span className="text-gray-500">Tipo:</span> CNAME</div>
                              <div><span className="text-gray-500">Nombre:</span> {t}._domainkey.{d.domain}</div>
                              <div><span className="text-gray-500">Valor:</span> {t}.dkim.amazonses.com</div>
                            </div>
                          ))}
                          {!d.verification_token && d.dkim_tokens.length === 0 && (
                            <p className="text-sm text-gray-500">No hay registros DNS disponibles para este dominio.</p>
                          )}
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {activeTab === 'remitentes' && (
            <div className="space-y-6">
              {verifiedDomains.length === 0 ? (
                <div className="bg-yellow-50 border-l-4 border-yellow-400 p-4">
                  <div className="flex">
                    <AlertCircle className="h-5 w-5 text-yellow-400 flex-shrink-0" />
                    <div className="ml-3">
                      <p className="text-sm text-yellow-700">
                        Primero verifica un dominio en la pestaña <strong>Dominios</strong>. Los remitentes se
                        crean sobre un dominio verificado y heredan su verificación automáticamente.
                      </p>
                    </div>
                  </div>
                </div>
              ) : (
                <form onSubmit={handleVerifySender} className="space-y-3">
                  <label className="block text-sm font-medium text-gray-700">
                    Crear remitente sobre un dominio verificado
                  </label>
                  <div className="flex flex-col sm:flex-row gap-3 sm:items-center">
                    <input
                      type="text"
                      value={localPart}
                      onChange={(e) => setLocalPart(e.target.value)}
                      className="shadow-sm focus:ring-blue-500 focus:border-blue-500 block w-full sm:w-56 sm:text-sm border-gray-300 rounded-md border px-3 py-2"
                      placeholder="notificaciones"
                    />
                    <span className="hidden sm:block text-gray-500 font-medium">@</span>
                    <select
                      value={selectedDomain}
                      onChange={(e) => setSelectedDomain(e.target.value)}
                      className="shadow-sm focus:ring-blue-500 focus:border-blue-500 block w-full sm:flex-1 sm:text-sm border-gray-300 rounded-md border px-3 py-2"
                    >
                      {verifiedDomains.map((d) => (
                        <option key={d.id} value={d.domain}>{d.domain}</option>
                      ))}
                    </select>
                    <button
                      type="submit"
                      disabled={submittingSender}
                      className="shrink-0 inline-flex justify-center items-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
                    >
                      <Mail className="-ml-1 mr-2 h-4 w-4" />
                      {submittingSender ? 'Creando...' : 'Crear remitente'}
                    </button>
                  </div>
                  {previewEmail && (
                    <p className="text-xs text-gray-500">
                      Se creará <span className="font-mono text-gray-700">{previewEmail}</span> y quedará como
                      remitente por defecto (MAIL_FROM) de los envíos.
                    </p>
                  )}
                </form>
              )}

              <form onSubmit={handleVerifyEmailAddress} className="space-y-2 border-t border-gray-200 pt-6">
                <label htmlFor="freeEmail" className="block text-sm font-medium text-gray-700">
                  Verificar un correo suelto
                </label>
                <p className="text-xs text-gray-500">
                  Para cualquier dirección, aunque no sea de un dominio tuyo. Se le envía un correo de
                  confirmación que su dueño debe aceptar. Necesario si tu cuenta de SES está en{' '}
                  <strong>sandbox</strong>, porque ahí también los <strong>destinatarios</strong> deben
                  estar verificados.
                </p>
                <div className="flex flex-col sm:flex-row gap-3">
                  <input
                    type="email"
                    id="freeEmail"
                    value={freeEmail}
                    onChange={(e) => setFreeEmail(e.target.value)}
                    className="shadow-sm focus:ring-blue-500 focus:border-blue-500 block w-full sm:flex-1 sm:text-sm border-gray-300 rounded-md border px-3 py-2"
                    placeholder="destinatario@otrodominio.com"
                  />
                  <button
                    type="submit"
                    disabled={submittingFreeEmail}
                    className="shrink-0 inline-flex justify-center items-center py-2 px-4 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
                  >
                    <Send className="-ml-1 mr-2 h-4 w-4" />
                    {submittingFreeEmail ? 'Enviando...' : 'Enviar verificación'}
                  </button>
                </div>
              </form>

              {loadingSenders ? (
                <p className="text-sm text-gray-500">Cargando remitentes...</p>
              ) : senders.length === 0 ? (
                <p className="text-sm text-gray-500">No hay remitentes registrados todavía.</p>
              ) : (
                <div className="border border-gray-200 rounded-md divide-y divide-gray-200">
                  {senders.map((s) => (
                    <div key={s.id} className="p-4 flex items-center justify-between flex-wrap gap-2">
                      <div className="flex items-center gap-3 flex-wrap">
                        <span className="text-sm font-medium text-gray-900">{s.email}</span>
                        {statusBadge(s.verification_status)}
                        {s.is_default && (
                          <span className="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800">
                            <Star className="h-3 w-3 mr-1 fill-current" />
                            Remitente por defecto
                          </span>
                        )}
                      </div>
                      <div className="flex items-center gap-2">
                        {!s.is_default && (
                          <button
                            type="button"
                            onClick={() => handleSetDefault(s.email)}
                            disabled={settingDefault === s.email}
                            className="inline-flex items-center py-1.5 px-3 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
                          >
                            <Star className="mr-2 h-4 w-4" />
                            Usar por defecto
                          </button>
                        )}
                        {s.verification_status !== 'Success' && (
                          <button
                            type="button"
                            onClick={() => handleResendSender(s.email)}
                            disabled={busySender === s.email}
                            className="inline-flex items-center py-1.5 px-3 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
                          >
                            <Send className="mr-2 h-4 w-4" />
                            Reenviar verificación
                          </button>
                        )}
                        <button
                          type="button"
                          onClick={() => handleRefreshSender(s.email)}
                          disabled={refreshingSender === s.email}
                          className="inline-flex items-center py-1.5 px-3 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
                        >
                          <RefreshCw className={`mr-2 h-4 w-4 ${refreshingSender === s.email ? 'animate-spin' : ''}`} />
                          Actualizar estado
                        </button>
                        <button
                          type="button"
                          onClick={() => handleDeleteSender(s.email)}
                          disabled={busySender === s.email}
                          className="inline-flex items-center py-1.5 px-3 border border-red-300 rounded-md text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 disabled:opacity-50"
                        >
                          <Trash2 className="mr-2 h-4 w-4" />
                          Eliminar
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {activeTab === 'respuesta' && (
            <div className="space-y-6">
              <div className="rounded-md border-l-4 border-blue-400 bg-blue-50 p-4">
                <p className="text-sm text-blue-800">
                  Cuando el destinatario le dé clic a "Responder" en su cliente de correo, el mensaje
                  llegará a esta dirección en vez de al remitente. Es opcional: sin ninguna configurada,
                  las respuestas van al remitente normal, como en cualquier correo.
                </p>
              </div>

              <form onSubmit={handleVerifyReply} className="space-y-2">
                <label htmlFor="replyEmail" className="block text-sm font-medium text-gray-700">
                  Agregar un email de respuesta
                </label>
                <p className="text-xs text-gray-500">
                  Puedes agregar varios y elegir cuál usar en cada envío desde el módulo de Email.
                  Puede ser cualquier dirección (de tu dominio o no) y no necesita verificación: solo
                  se usa en la cabecera Reply-To.
                </p>
                <div className="flex flex-col sm:flex-row gap-3">
                  <input
                    type="email"
                    id="replyEmail"
                    value={replyEmail}
                    onChange={(e) => setReplyEmail(e.target.value)}
                    className="shadow-sm focus:ring-blue-500 focus:border-blue-500 block w-full sm:flex-1 sm:text-sm border-gray-300 rounded-md border px-3 py-2"
                    placeholder="respuestas@tudominio.com"
                  />
                  <button
                    type="submit"
                    disabled={submittingReply}
                    className="shrink-0 inline-flex justify-center items-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
                  >
                    <Reply className="-ml-1 mr-2 h-4 w-4" />
                    {submittingReply ? 'Guardando...' : 'Agregar email de respuesta'}
                  </button>
                </div>
              </form>

              {loadingReply ? (
                <p className="text-sm text-gray-500">Cargando...</p>
              ) : replySenders.length === 0 ? (
                <p className="text-sm text-gray-500">No hay emails de respuesta registrados todavía.</p>
              ) : (
                <div className="border border-gray-200 rounded-md divide-y divide-gray-200">
                  {replySenders.map((s) => (
                    <div key={s.id} className="p-4 flex items-center justify-between flex-wrap gap-2">
                      <div className="flex items-center gap-3 flex-wrap">
                        <span className="text-sm font-medium text-gray-900">{s.email}</span>
                        {statusBadge(s.verification_status)}
                        {s.is_default && (
                          <span className="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800">
                            <Star className="h-3 w-3 mr-1 fill-current" />
                            Respuesta por defecto
                          </span>
                        )}
                      </div>
                      <div className="flex items-center gap-2">
                        {!s.is_default && (
                          <button
                            type="button"
                            onClick={() => handleSetDefaultReply(s.email)}
                            disabled={settingDefaultReply === s.email}
                            className="inline-flex items-center py-1.5 px-3 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
                          >
                            <Star className="mr-2 h-4 w-4" />
                            Usar por defecto
                          </button>
                        )}
                        {s.verification_status !== 'Success' && (
                          <button
                            type="button"
                            onClick={() => handleResendReply(s.email)}
                            disabled={busyReply === s.email}
                            className="inline-flex items-center py-1.5 px-3 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
                          >
                            <Send className="mr-2 h-4 w-4" />
                            Reenviar verificación
                          </button>
                        )}
                        <button
                          type="button"
                          onClick={() => handleRefreshReply(s.email)}
                          disabled={refreshingReply === s.email}
                          className="inline-flex items-center py-1.5 px-3 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
                        >
                          <RefreshCw className={`mr-2 h-4 w-4 ${refreshingReply === s.email ? 'animate-spin' : ''}`} />
                          Actualizar estado
                        </button>
                        <button
                          type="button"
                          onClick={() => handleDeleteReply(s.email)}
                          disabled={busyReply === s.email}
                          className="inline-flex items-center py-1.5 px-3 border border-red-300 rounded-md text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 disabled:opacity-50"
                        >
                          <Trash2 className="mr-2 h-4 w-4" />
                          Eliminar
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default Verification;
