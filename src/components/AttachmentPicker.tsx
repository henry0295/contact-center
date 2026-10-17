import React, { useRef, useState } from 'react';
import { Paperclip, X, FileText, Image as ImageIcon, FileArchive, File as FileIcon, UploadCloud } from 'lucide-react';
import toast from 'react-hot-toast';
import { ATTACHMENT_LIMITS } from '../services/api';

interface Props {
  files: File[];
  onChange: (files: File[]) => void;
}

export function humanSize(bytes: number): string {
  if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  if (bytes >= 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${bytes} B`;
}

function extensionOf(name: string): string {
  const parts = name.split('.');
  return parts.length > 1 ? parts.pop()!.toLowerCase() : '';
}

function iconFor(file: File) {
  const ext = extensionOf(file.name);
  if (file.type.startsWith('image/')) return <ImageIcon className="h-4 w-4 text-purple-600" />;
  if (['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt'].includes(ext)) return <FileText className="h-4 w-4 text-blue-600" />;
  if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) return <FileArchive className="h-4 w-4 text-amber-600" />;
  return <FileIcon className="h-4 w-4 text-gray-500" />;
}

const AttachmentPicker: React.FC<Props> = ({ files, onChange }) => {
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragging, setDragging] = useState(false);

  const totalBytes = files.reduce((sum, f) => sum + f.size, 0);

  /** Valida contra los mismos límites que aplica el backend. */
  const addFiles = (incoming: FileList | File[] | null) => {
    if (!incoming) return;
    const candidates = Array.from(incoming);
    const accepted: File[] = [];
    let running = totalBytes;

    for (const file of candidates) {
      const ext = extensionOf(file.name);

      if (ext && ATTACHMENT_LIMITS.blockedExtensions.includes(ext)) {
        toast.error(`"${file.name}": el tipo .${ext} no está permitido.`);
        continue;
      }
      if (file.size > ATTACHMENT_LIMITS.maxFileBytes) {
        toast.error(`"${file.name}" supera ${humanSize(ATTACHMENT_LIMITS.maxFileBytes)}.`);
        continue;
      }
      if (files.length + accepted.length >= ATTACHMENT_LIMITS.maxFiles) {
        toast.error(`Máximo ${ATTACHMENT_LIMITS.maxFiles} archivos.`);
        break;
      }
      if (files.some((f) => f.name === file.name && f.size === file.size)) {
        continue; // ya adjuntado
      }
      if (running + file.size > ATTACHMENT_LIMITS.maxTotalBytes) {
        toast.error(`Se superaría el total de ${humanSize(ATTACHMENT_LIMITS.maxTotalBytes)}.`);
        break;
      }

      running += file.size;
      accepted.push(file);
    }

    if (accepted.length > 0) onChange([...files, ...accepted]);
    if (inputRef.current) inputRef.current.value = '';
  };

  const remove = (index: number) => {
    onChange(files.filter((_, i) => i !== index));
  };

  const pct = Math.min(100, Math.round((totalBytes / ATTACHMENT_LIMITS.maxTotalBytes) * 100));

  return (
    <div className="space-y-3">
      <div
        onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
        onDragLeave={() => setDragging(false)}
        onDrop={(e) => { e.preventDefault(); setDragging(false); addFiles(e.dataTransfer.files); }}
        onClick={() => inputRef.current?.click()}
        role="button"
        tabIndex={0}
        aria-label="Agregar archivos adjuntos"
        onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); inputRef.current?.click(); } }}
        className={`cursor-pointer rounded-lg border-2 border-dashed px-4 py-5 text-center transition-colors ${
          dragging ? 'border-blue-500 bg-blue-50' : 'border-gray-300 bg-gray-50 hover:border-blue-400 hover:bg-blue-50/40'
        }`}
      >
        <UploadCloud className={`mx-auto h-6 w-6 ${dragging ? 'text-blue-600' : 'text-gray-400'}`} />
        <p className="mt-1 text-sm text-gray-600">
          Arrastra archivos aquí o <span className="font-medium text-blue-600">búscalos</span>
        </p>
        <p className="mt-0.5 text-xs text-gray-500">
          Hasta {ATTACHMENT_LIMITS.maxFiles} archivos · {humanSize(ATTACHMENT_LIMITS.maxFileBytes)} por archivo ·{' '}
          {humanSize(ATTACHMENT_LIMITS.maxTotalBytes)} en total
        </p>
      </div>

      {files.length > 0 && (
        <div className="space-y-2">
          <div className="flex items-center justify-between text-xs text-gray-600">
            <span className="inline-flex items-center gap-1 font-medium">
              <Paperclip className="h-3.5 w-3.5" />
              {files.length} {files.length === 1 ? 'archivo' : 'archivos'}
            </span>
            <span className={pct > 85 ? 'text-amber-600 font-medium' : ''}>
              {humanSize(totalBytes)} / {humanSize(ATTACHMENT_LIMITS.maxTotalBytes)}
            </span>
          </div>

          <div className="h-1 w-full rounded-full bg-gray-200 overflow-hidden">
            <div
              className={`h-full rounded-full transition-all ${pct > 85 ? 'bg-amber-500' : 'bg-blue-600'}`}
              style={{ width: `${pct}%` }}
            />
          </div>

          <ul className="divide-y divide-gray-200 rounded-md border border-gray-200">
            {files.map((file, i) => (
              <li key={`${file.name}-${file.size}-${i}`} className="flex items-center justify-between gap-3 px-3 py-2">
                <div className="flex min-w-0 items-center gap-2">
                  {iconFor(file)}
                  <span className="truncate text-sm text-gray-800">{file.name}</span>
                  <span className="shrink-0 text-xs text-gray-500">{humanSize(file.size)}</span>
                </div>
                <button
                  type="button"
                  onClick={() => remove(i)}
                  title={`Quitar ${file.name}`}
                  aria-label={`Quitar ${file.name}`}
                  className="shrink-0 rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600"
                >
                  <X className="h-4 w-4" />
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}

      <input
        ref={inputRef}
        type="file"
        multiple
        className="hidden"
        onChange={(e) => addFiles(e.target.files)}
      />
    </div>
  );
};

export default AttachmentPicker;
