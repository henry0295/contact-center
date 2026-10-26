/**
 * Exportación de los registros DNS de un dominio de SES, para enviárselos a
 * quien administra el DNS: Excel (CSV para Excel en español) y PDF (vista de
 * impresión del navegador -> "Guardar como PDF"). Todo en el navegador.
 */

export interface DnsRecord {
  type: 'TXT' | 'CNAME' | 'MX';
  name: string;
  value: string;
  purpose: string;
  required: boolean;
}

export interface DnsDomain {
  domain: string;
  verification_status: string | null;
  verification_token: string | null;
  dkim_tokens: string[];
  mail_from_domain?: string | null;
  mail_from_mx?: string;
}

/** Registros del dominio MAIL FROM (rebotes): MX hacia SES + SPF. */
export function mailFromRecords(d: DnsDomain): DnsRecord[] {
  if (!d.mail_from_domain) return [];
  const mx = d.mail_from_mx || 'feedback-smtp.us-east-1.amazonses.com';
  return [
    { type: 'MX', name: d.mail_from_domain, value: `10 ${mx}`, purpose: 'MAIL FROM: recibe los rebotes y avisos de queja (prioridad 10)', required: true },
    { type: 'TXT', name: d.mail_from_domain, value: 'v=spf1 include:amazonses.com ~all', purpose: 'MAIL FROM: SPF del subdominio de rebotes (alinea SPF para DMARC)', required: true },
  ];
}

export function dnsRecords(d: DnsDomain): DnsRecord[] {
  const out: DnsRecord[] = [];
  if (d.verification_token) {
    out.push({ type: 'TXT', name: `_amazonses.${d.domain}`, value: d.verification_token, purpose: 'Verificación del dominio en Amazon SES', required: true });
  }
  for (const t of d.dkim_tokens) {
    out.push({ type: 'CNAME', name: `${t}._domainkey.${d.domain}`, value: `${t}.dkim.amazonses.com`, purpose: 'Firma DKIM de los correos', required: true });
  }
  out.push(...mailFromRecords(d));
  // Recomendados: mejoran la entrega y la protección contra suplantación.
  out.push({ type: 'TXT', name: d.domain, value: 'v=spf1 include:amazonses.com ~all', purpose: 'SPF (si ya existe un SPF, solo añada include:amazonses.com)', required: false });
  out.push({ type: 'TXT', name: `_dmarc.${d.domain}`, value: 'v=DMARC1; p=none;', purpose: 'DMARC (política de autenticación)', required: false });
  return out;
}

const STATUS: Record<string, string> = { Success: 'Verificado', Failed: 'Fallido', TemporaryFailure: 'Fallido' };
const statusLabel = (s: string | null) => STATUS[s ?? ''] ?? 'Pendiente';
const stamp = () => new Date().toISOString().slice(0, 10);
const safeName = (domain: string) => domain.replace(/[^a-z0-9.-]+/gi, '-');

/** Excel: CSV con BOM y ";" (así lo abre Excel en español sin asistente). */
export function downloadDnsExcel(d: DnsDomain): void {
  const cell = (v: string) => {
    // Evita que Excel interprete el contenido como fórmula.
    const s = /^[=+\-@\t\r]/.test(v) ? `'${v}` : v;
    return `"${s.replace(/"/g, '""')}"`;
  };
  const rows = [
    ['Dominio', d.domain],
    ['Estado', statusLabel(d.verification_status)],
    ['Generado', new Date().toLocaleString('es-CO')],
    [],
    ['Tipo', 'Nombre (host)', 'Valor', 'Obligatorio', 'Para qué sirve'],
    ...dnsRecords(d).map((r) => [r.type, r.name, r.value, r.required ? 'Sí' : 'Recomendado', r.purpose]),
  ];
  const csv = '\uFEFF' + rows.map((r) => r.map(cell).join(';')).join('\r\n');
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
  const a = document.createElement('a');
  a.href = url;
  a.download = `registros-dns-${safeName(d.domain)}-${stamp()}.csv`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

const esc = (s: string) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] as string));

/** PDF: abre una vista de impresión; el usuario elige "Guardar como PDF". */
export function downloadDnsPdf(d: DnsDomain): boolean {
  const w = window.open('', '_blank', 'width=900,height=700');
  if (!w) return false;
  const rows = dnsRecords(d).map((r) => `
      <tr>
        <td>${r.type}</td>
        <td class="mono">${esc(r.name)}</td>
        <td class="mono">${esc(r.value)}</td>
        <td>${r.required ? '<b>Obligatorio</b>' : 'Recomendado'}<br><span class="muted">${esc(r.purpose)}</span></td>
      </tr>`).join('');
  w.document.open();
  w.document.write(`<!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Registros DNS - ${esc(d.domain)}</title>
<style>
  @page { size: A4 landscape; margin: 14mm; }
  body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #111827; font-size: 12px; }
  h1 { font-size: 18px; margin: 0 0 4px; }
  .muted { color: #6b7280; font-size: 11px; }
  table { width: 100%; border-collapse: collapse; margin-top: 14px; table-layout: fixed; }
  th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; vertical-align: top; word-break: break-all; }
  th { background: #f3f4f6; font-size: 11px; text-transform: uppercase; }
  th:nth-child(1) { width: 60px; } th:nth-child(4) { width: 190px; }
  .mono { font-family: ui-monospace, Consolas, monospace; font-size: 11px; }
  ol { padding-left: 18px; } li { margin: 3px 0; }
</style></head><body>
  <h1>Registros DNS para el envío de correo</h1>
  <div>Dominio: <b>${esc(d.domain)}</b> · Estado: <b>${statusLabel(d.verification_status)}</b></div>
  <div class="muted">Generado el ${esc(new Date().toLocaleString('es-CO'))}</div>
  <table>
    <thead><tr><th>Tipo</th><th>Nombre (host)</th><th>Valor</th><th>Uso</th></tr></thead>
    <tbody>${rows}</tbody>
  </table>
  <h2 style="font-size:14px;margin-top:18px">Instrucciones</h2>
  <ol>
    <li>Cree cada registro en el panel DNS del dominio con el tipo, nombre y valor exactos.</li>
    <li>Si el panel agrega el dominio automáticamente, escriba en "Nombre" solo la parte anterior a <span class="mono">${esc(d.domain)}</span>.</li>
    <li>Los registros CNAME de DKIM no deben pasar por proxy (en Cloudflare: "Solo DNS").</li>
    <li>SPF: si el dominio ya tiene un registro <span class="mono">v=spf1</span>, no cree otro; añada <span class="mono">include:amazonses.com</span> al existente.</li>
    <li>MX del MAIL FROM: en "Prioridad" ponga 10 y en "Destino" solo el servidor (sin el 10). Debe ser el único MX de ese subdominio y no afecta al correo del dominio principal.</li>
    <li>La verificación suele tardar menos de una hora (hasta 72 h).</li>
  </ol>
</body></html>`);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 300);
  return true;
}
