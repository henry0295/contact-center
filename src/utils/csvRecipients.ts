import type { RecipientRow } from '../services/api';

/** Fila de destinatario con variables, o un valor suelto (teléfono/email). */
export type RecipientEntry = string | RecipientRow;

export function parseRecipients(text: string): string[] {
  return text.split(/[\n,;]+/).map((t) => t.trim()).filter((t) => t.length > 0);
}

/** Cabeceras reconocidas para la columna de teléfono. */
export const PHONE_HEADERS = ['phone', 'telefono', 'teléfono', 'numero', 'número', 'number', 'celular', 'movil', 'móvil'];
/** Cabeceras reconocidas para la columna de email. */
export const EMAIL_HEADERS = ['email', 'correo', 'mail', 'e-mail'];
/** La columna de nombre se trata distinto (alimenta {{nombre}} del contacto). */
export const NAME_HEADERS = ['name', 'nombre'];

/** Quita las comillas que Excel añade al exportar celdas con comas dentro. */
function unquoteCell(cell: string): string {
  const trimmed = cell.trim();
  const match = /^"(.*)"$/.exec(trimmed);
  return (match ? match[1].replace(/""/g, '"') : trimmed).trim();
}

/** Normaliza una cabecera a un token válido para {{variable}}: minúsculas, sin espacios ni acentos. */
export function normalizeVarName(header: string): string {
  return header
    .trim()
    .toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // quita acentos (á -> a)
    .replace(/[^a-z0-9_]+/g, '_')
    .replace(/^_+|_+$/g, '');
}

export interface ParsedCsv {
  rows: RecipientEntry[];
  hasVariables: boolean;
  /** Nombres de las columnas extra detectadas (para avisar en la UI). */
  variableNames: string[];
}

/**
 * Analiza un CSV de destinatarios. Admite dos formatos:
 *  - Simple: una columna de valores (teléfono o email), con o sin cabecera.
 *  - Con variables: cabecera con la columna identificadora ("telefono" o
 *    "email") más "nombre" y/o CUALQUIER otra columna (valor, fecha_pago,
 *    placa...), en cualquier orden. "telefono"/"email" y "nombre" alimentan
 *    el contacto reutilizable; el resto son variables propias de ESE envío
 *    (p.ej. {{valor}} en un recordatorio de pago con un importe distinto por
 *    persona) y no se guardan en la ficha del contacto.
 *
 * @param identifierHeaders cabeceras reconocidas para la columna obligatoria
 *   (PHONE_HEADERS para SMS, EMAIL_HEADERS para email)
 * @param identifierField   'phone' o 'email': cómo se llama ese campo en RecipientRow
 */
export function parseRecipientsCsv(
  text: string,
  identifierHeaders: string[],
  identifierField: 'phone' | 'email'
): ParsedCsv {
  const lines = text.split(/\r?\n/).map((l) => l.trim()).filter((l) => l.length > 0);
  if (lines.length === 0) return { rows: [], hasVariables: false, variableNames: [] };

  const delimiter = (lines[0].match(/;/g)?.length ?? 0) > (lines[0].match(/,/g)?.length ?? 0) ? ';' : ',';
  const splitLine = (line: string) => line.split(delimiter).map(unquoteCell);

  const headerCells = splitLine(lines[0]);
  const firstCellsLower = headerCells.map((c) => c.toLowerCase());
  const idIdx = firstCellsLower.findIndex((c) => identifierHeaders.includes(c));
  const nameIdx = firstCellsLower.findIndex((c) => NAME_HEADERS.includes(c));
  const isHeader = idIdx !== -1;

  if (!isHeader) {
    // Sin cabecera reconocida: se trata como lista simple (una celda = un valor).
    return { rows: parseRecipients(text), hasVariables: false, variableNames: [] };
  }

  // Cualquier columna que no sea el identificador ni el nombre es una variable libre.
  const extraCols = headerCells
    .map((raw, idx) => ({ idx, token: normalizeVarName(raw) }))
    .filter(({ idx, token }) => idx !== idIdx && idx !== nameIdx && token !== '');

  const dataLines = lines.slice(1);
  const rows: RecipientEntry[] = [];
  for (const line of dataLines) {
    const cells = splitLine(line);
    const idValue = (cells[idIdx] ?? '').trim();
    if (!idValue) continue;
    const name = nameIdx !== -1 ? (cells[nameIdx] ?? '').trim() : '';

    const row: RecipientRow = { [identifierField]: idValue };
    if (name) row.name = name;
    for (const { idx, token } of extraCols) {
      const value = (cells[idx] ?? '').trim();
      if (value !== '') row[token] = value;
    }

    rows.push(Object.keys(row).length > 1 ? row : idValue);
  }

  return {
    rows,
    hasVariables: nameIdx !== -1 || extraCols.length > 0,
    variableNames: [...(nameIdx !== -1 ? ['nombre'] : []), ...extraCols.map((c) => c.token)],
  };
}

/** Dispara la descarga de un CSV generado en el navegador (sin pedir nada al backend). */
export function downloadCsv(filename: string, lines: string[][]): void {
  const escape = (v: string) => (/[",;\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v);
  // BOM UTF-8: para que Excel abra con tildes correctas en vez de caracteres sueltos.
  const csv = '\uFEFF' + lines.map((row) => row.map(escape).join(',')).join('\r\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}
