import React, { useEffect, useState } from 'react';
import { BookOpen, Download, FileText, Mail, MessageCircle, MessageSquare, PhoneCall, PlayCircle, X } from 'lucide-react';

/** Manual de un módulo (static/manuales/catalogo.json, lo genera la herramienta de videos). */
interface Manual {
  id: string;
  titulo: string;
  descripcion: string;
  icono: string;
  segundos: number;
  video: string;
  pdf: string;
  /** Imagen de la tarjeta (un fotograma del video). */
  portada?: string;
  pasos: string[];
  actualizado: string;
}

const ICONS: Record<string, React.ElementType> = { sms: MessageSquare, email: Mail, call: PhoneCall, whatsapp: MessageCircle };

const duration = (s: number) => `${Math.floor(s / 60)} min ${String(s % 60).padStart(2, '0')} s`;
const fileName = (m: Manual, ext: string) => `Manual ${m.titulo.replace(/^Módulo de /, '')}.${ext}`;

/**
 * Centro de ayuda: un video y un manual en PDF por módulo, para ver en la
 * plataforma o descargar. Los archivos viven en /manuales/ (estáticos).
 */
const Help: React.FC = () => {
  const [manuales, setManuales] = useState<Manual[] | null>(null);
  const [error, setError] = useState(false);
  const [playing, setPlaying] = useState<Manual | null>(null);

  useEffect(() => {
    fetch('/manuales/catalogo.json', { cache: 'no-cache' })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d: { manuales?: Manual[] }) => setManuales(d.manuales ?? []))
      .catch(() => setError(true));
  }, []);

  useEffect(() => {
    if (!playing) return undefined;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setPlaying(null); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [playing]);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Ayuda</h1>
        <p className="mt-1 text-sm text-gray-500">
          Videos y manuales paso a paso de cada módulo. Puedes verlos aquí o descargarlos para compartirlos con tu equipo.
        </p>
      </div>

      {error && (
        <div className="rounded-md border-l-4 border-amber-400 bg-amber-50 p-4 text-sm text-amber-800">
          No se pudieron cargar los manuales. Intenta de nuevo más tarde o contacta al administrador.
        </div>
      )}
      {!error && manuales === null && <p className="text-sm text-gray-500">Cargando manuales...</p>}
      {manuales !== null && manuales.length === 0 && <p className="text-sm text-gray-500">Todavía no hay manuales disponibles.</p>}

      <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
        {manuales?.map((m) => {
          const Icon = ICONS[m.icono] ?? BookOpen;
          return (
            <article key={m.id} className="flex flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
              <button
                type="button"
                onClick={() => setPlaying(m)}
                className="group relative flex aspect-video items-center justify-center bg-gray-900"
                aria-label={`Ver el video de ${m.titulo}`}
              >
                {m.portada && <img src={m.portada} alt="" loading="lazy" className="absolute inset-0 h-full w-full object-cover opacity-80" />}
                <PlayCircle className="relative h-16 w-16 text-white drop-shadow transition-transform group-hover:scale-110" aria-hidden="true" />
                <span className="absolute bottom-2 right-2 rounded bg-black/70 px-1.5 py-0.5 text-xs text-white">{duration(m.segundos)}</span>
              </button>
              <div className="flex flex-1 flex-col p-5">
                <h2 className="flex items-center gap-2 text-base font-semibold text-gray-900">
                  <Icon className="h-5 w-5 text-blue-600" aria-hidden="true" /> {m.titulo}
                </h2>
                <p className="mt-1 text-sm text-gray-600">{m.descripcion}</p>
                <details className="mt-3 text-sm">
                  <summary className="cursor-pointer font-medium text-gray-700">Qué incluye ({m.pasos.length} pasos)</summary>
                  <ol className="mt-2 list-inside list-decimal space-y-0.5 text-gray-600">
                    {m.pasos.map((p) => <li key={p}>{p.replace(/^Paso \d+ · /, '')}</li>)}
                  </ol>
                </details>
                <div className="mt-auto flex flex-wrap gap-2 pt-4">
                  <button
                    type="button"
                    onClick={() => setPlaying(m)}
                    className="inline-flex items-center rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700"
                  >
                    <PlayCircle className="mr-1.5 h-4 w-4" aria-hidden="true" /> Ver video
                  </button>
                  <a
                    href={m.video}
                    download={fileName(m, 'mp4')}
                    className="inline-flex items-center rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                  >
                    <Download className="mr-1.5 h-4 w-4" aria-hidden="true" /> Descargar video
                  </a>
                  <a
                    href={m.pdf}
                    download={fileName(m, 'pdf')}
                    className="inline-flex items-center rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                  >
                    <FileText className="mr-1.5 h-4 w-4 text-red-600" aria-hidden="true" /> Manual PDF
                  </a>
                </div>
                <p className="mt-2 text-xs text-gray-400">Actualizado el {new Date(m.actualizado + 'T12:00:00').toLocaleDateString('es-CO')} · datos de ejemplo</p>
              </div>
            </article>
          );
        })}
      </div>

      {playing && (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/70 p-4" onClick={() => setPlaying(null)}>
          <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="helpVideoTitle"
            className="w-full max-w-5xl overflow-hidden rounded-lg bg-gray-900 shadow-2xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between px-4 py-2">
              <h2 id="helpVideoTitle" className="text-sm font-medium text-white">{playing.titulo}</h2>
              <button type="button" onClick={() => setPlaying(null)} className="text-gray-300 hover:text-white" aria-label="Cerrar video">
                <X className="h-5 w-5" />
              </button>
            </div>
            <video src={playing.video} poster={playing.portada} controls autoPlay preload="auto" className="aspect-video w-full bg-black" />
          </div>
        </div>
      )}
    </div>
  );
};

export default Help;
