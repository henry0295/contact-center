import React, { useEffect } from 'react';
import { AlertTriangle, CheckCircle2, Link2, Loader2, ShieldAlert, X } from 'lucide-react';
import * as api from '../services/api';

const IssueList: React.FC<{ issues: api.LinkIssue[]; tone: 'error' | 'warning' }> = ({ issues, tone }) => (
  <ul className="space-y-1.5">
    {issues.map((i, n) => (
      <li key={n} className={`rounded px-2 py-1.5 text-xs ${tone === 'error' ? 'bg-red-50 text-red-800' : 'bg-amber-50 text-amber-900'}`}>
        <p>{i.message}</p>
        {i.url && <p className="mt-0.5 break-all font-mono text-[11px] opacity-80">{i.url}</p>}
        {i.recipient && <p className="mt-0.5 opacity-80">Fila del CSV: {i.recipient}</p>}
      </li>
    ))}
  </ul>
);

/** Panel "Revisión de links" para el compositor de email. */
export const LinkCheckPanel: React.FC<{ result: api.LinkCheckResult | null; loading: boolean }> = ({ result, loading }) => {
  if (!result && !loading) return null;
  const errors = result?.errors ?? [];
  const warnings = result?.warnings ?? [];
  const links = result?.links ?? [];

  return (
    <section className="rounded-md border border-gray-200 p-3" aria-live="polite">
      <h3 className="mb-2 flex items-center gap-2 text-sm font-medium text-gray-800">
        <Link2 className="h-4 w-4 text-gray-400" aria-hidden="true" /> Revisión de links
        {loading && <Loader2 className="h-3.5 w-3.5 animate-spin text-gray-400" aria-label="Revisando" />}
      </h3>
      {result && (
        <div className="space-y-2">
          {errors.length === 0 && warnings.length === 0 ? (
            <p className="flex items-center gap-1.5 text-xs text-green-700">
              <CheckCircle2 className="h-4 w-4" aria-hidden="true" />
              {links.length > 0 ? `${links.length} link(s) revisado(s), sin problemas.` : 'Sin links que revisar.'}
            </p>
          ) : (
            <>
              {errors.length > 0 && (
                <div>
                  <p className="mb-1 flex items-center gap-1.5 text-xs font-medium text-red-700">
                    <ShieldAlert className="h-4 w-4" aria-hidden="true" /> {errors.length} bloqueado(s): el email no se enviará hasta corregirlos
                  </p>
                  <IssueList issues={errors} tone="error" />
                </div>
              )}
              {warnings.length > 0 && (
                <div>
                  <p className="mb-1 flex items-center gap-1.5 text-xs font-medium text-amber-700">
                    <AlertTriangle className="h-4 w-4" aria-hidden="true" /> {warnings.length} aviso(s): podrás enviar confirmándolos
                  </p>
                  <IssueList issues={warnings} tone="warning" />
                </div>
              )}
            </>
          )}
          {result.hasVariableLinks && (
            <p className="text-xs text-gray-500">Los links con variables del CSV se revisan con el dato de cada fila al enviar.</p>
          )}
        </div>
      )}
    </section>
  );
};

/** Diálogo al enviar: muestra los bloqueos o pide confirmar los avisos. */
export const LinkCheckDialog: React.FC<{
  data: api.LinkCheckErrorPayload;
  onCancel: () => void;
  onConfirm: () => void;
}> = ({ data, onCancel, onConfirm }) => {
  const blocked = !data.needsConfirmation;

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onCancel(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onCancel]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onCancel}>
      <div
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="linkDlgTitle"
        className="flex max-h-[80vh] w-full max-w-lg flex-col overflow-hidden rounded-lg bg-white shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3 border-b border-gray-200 px-5 py-4">
          <h2 id="linkDlgTitle" className={`flex items-center gap-2 text-base font-semibold ${blocked ? 'text-red-700' : 'text-amber-700'}`}>
            {blocked ? <ShieldAlert className="h-5 w-5" aria-hidden="true" /> : <AlertTriangle className="h-5 w-5" aria-hidden="true" />}
            {blocked ? 'Envío bloqueado por los links' : 'Revisa los avisos de los links'}
          </h2>
          <button type="button" onClick={onCancel} className="text-gray-400 hover:text-gray-600" aria-label="Cerrar"><X className="h-5 w-5" /></button>
        </div>
        <div className="space-y-3 overflow-y-auto px-5 py-4 text-sm">
          <p className="text-gray-700">
            {blocked
              ? 'Estos links parecen phishing o son peligrosos. Corrígelos para poder enviar: protegen tu reputación de envío y la de la cuenta.'
              : 'El email se puede enviar, pero estos links pueden hacer que llegue a spam. ¿Quieres enviarlo de todos modos?'}
          </p>
          {data.linkCheck.errors.length > 0 && <IssueList issues={data.linkCheck.errors} tone="error" />}
          {data.linkCheck.warnings.length > 0 && <IssueList issues={data.linkCheck.warnings} tone="warning" />}
        </div>
        <div className="flex justify-end gap-3 border-t border-gray-200 px-5 py-3">
          <button type="button" onClick={onCancel} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
            {blocked ? 'Entendido, corregir' : 'Volver y corregir'}
          </button>
          {!blocked && (
            <button type="button" onClick={onConfirm} className="rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
              Enviar de todos modos
            </button>
          )}
        </div>
      </div>
    </div>
  );
};
