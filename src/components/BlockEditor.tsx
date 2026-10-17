import React, { useEffect, useRef, useState } from 'react';
import grapesjs, { Editor } from 'grapesjs';
import newsletterPreset from 'grapesjs-preset-newsletter';
import 'grapesjs/dist/css/grapes.min.css';
import { X, Save, Loader2, Monitor, Smartphone, Send } from 'lucide-react';
import toast from 'react-hot-toast';
import { uploadImage, sendEmailTest } from '../services/api';

interface Props {
  /** HTML inicial (se usa si no hay diseño guardado). */
  initialHtml?: string;
  /** Estado del editor guardado previamente (JSON de GrapesJS). */
  initialDesign?: string | null;
  title: string;
  saving?: boolean;
  onSave: (html: string, designJson: string) => void;
  onClose: () => void;
}

/**
 * Editor de bloques arrastrar y soltar para plantillas de email, basado en
 * GrapesJS con el preset "newsletter": genera HTML con tablas y estilos en
 * línea, que es lo que entienden bien Outlook, Gmail y compañía.
 *
 * Es completamente autoalojado (MIT): no depende de ningún servicio externo.
 */
const BlockEditor: React.FC<Props> = ({
  initialHtml,
  initialDesign,
  title,
  saving = false,
  onSave,
  onClose,
}) => {
  const holderRef = useRef<HTMLDivElement>(null);
  const editorRef = useRef<Editor | null>(null);
  const [ready, setReady] = useState(false);
  const [currentDevice, setCurrentDevice] = useState<string>('Desktop');
  const [sendingTest, setSendingTest] = useState(false);
  const [lastTestEmail, setLastTestEmail] = useState('');

  useEffect(() => {
    if (!holderRef.current) return;

    const editor = grapesjs.init({
      container: holderRef.current,
      height: '100%',
      width: 'auto',
      storageManager: false,
      plugins: [newsletterPreset],
      assetManager: {
        // Las imágenes se suben al backend y se referencian por URL absoluta.
        uploadFile: async (e: DragEvent | Event) => {
          const target = e.target as HTMLInputElement | null;
          const dropped = (e as DragEvent).dataTransfer?.files;
          const files = dropped ?? target?.files;
          if (!files || files.length === 0) return;

          for (const file of Array.from(files)) {
            try {
              const res = await uploadImage(file);
              editor.AssetManager.add({ src: res.url, name: file.name });
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'No se pudo subir la imagen');
            }
          }
        },
      },
    });

    editorRef.current = editor;

    editor.on('load', () => {
      setReady(true);
      // Prioriza el diseño guardado; si no existe, carga el HTML.
      if (initialDesign) {
        try {
          editor.loadProjectData(JSON.parse(initialDesign));
          return;
        } catch {
          // Diseño corrupto: se cae al HTML.
        }
      }
      if (initialHtml) {
        editor.setComponents(initialHtml);
      }
    });

    return () => {
      editor.destroy();
      editorRef.current = null;
    };
    // Solo se inicializa una vez: los cambios posteriores los gestiona GrapesJS.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  /** Cambia el ancho del lienzo para previsualizar en móvil o escritorio. */
  const setDevice = (device: 'Desktop' | 'Mobile portrait') => {
    const editor = editorRef.current;
    if (!editor) return;
    editor.setDevice(device);
    setCurrentDevice(device);
  };

  /** Exporta el HTML autocontenido (estilos incluidos) listo para enviar. */
  const exportHtml = (): string => {
    const editor = editorRef.current;
    if (!editor) return '';
    const html = editor.getHtml();
    const css = editor.getCss();

    return css ? `<style>${css}</style>${html}` : html;
  };

  /** Envía el diseño actual a una dirección concreta para verlo en el buzón. */
  const sendTest = async () => {
    const html = exportHtml();
    if (html.trim() === '') {
      toast.error('El diseño está vacío.');
      return;
    }

    const to = window.prompt('Enviar una prueba a:', lastTestEmail);
    if (!to) return;

    setSendingTest(true);
    try {
      const res = await sendEmailTest({
        to,
        subject: `[Prueba] ${title}`,
        body: html,
      });
      setLastTestEmail(to);
      if (res.success) {
        toast.success(res.message);
      } else {
        toast.error(res.hint ?? res.message);
      }
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'No se pudo enviar la prueba');
    } finally {
      setSendingTest(false);
    }
  };

  const handleSave = () => {
    const editor = editorRef.current;
    if (!editor) return;

    // getHtml + getCss y se incrustan los estilos en un <style> del propio
    // documento para que el HTML sea autocontenido al enviarse por correo.
    const full = exportHtml();
    const design = JSON.stringify(editor.getProjectData());

    onSave(full, design);
  };

  return (
    <div className="fixed inset-0 z-40 flex flex-col bg-white">
      <header className="flex shrink-0 items-center justify-between border-b border-gray-200 px-4 py-3">
        <div className="min-w-0">
          <h2 className="truncate text-lg font-semibold text-gray-900">{title}</h2>
          <p className="text-xs text-gray-500">
            Arrastra bloques desde el panel derecho. El HTML se genera con tablas, compatible con clientes de correo.
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          {/* Previsualización: el mismo diseño a ancho de escritorio y de móvil */}
          <div className="mr-1 inline-flex rounded-md border border-gray-300 p-0.5" role="group" aria-label="Vista previa">
            <button
              type="button"
              onClick={() => setDevice('Desktop')}
              disabled={!ready}
              title="Vista de escritorio"
              aria-pressed={currentDevice === 'Desktop'}
              className={`rounded px-2.5 py-1.5 transition-colors ${
                currentDevice === 'Desktop' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'
              }`}
            >
              <Monitor className="h-4 w-4" />
            </button>
            <button
              type="button"
              onClick={() => setDevice('Mobile portrait')}
              disabled={!ready}
              title="Vista móvil"
              aria-pressed={currentDevice !== 'Desktop'}
              className={`rounded px-2.5 py-1.5 transition-colors ${
                currentDevice !== 'Desktop' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'
              }`}
            >
              <Smartphone className="h-4 w-4" />
            </button>
          </div>

          <button
            type="button"
            onClick={sendTest}
            disabled={!ready || sendingTest}
            title="Enviar una prueba a tu correo para verlo en un cliente real"
            className="inline-flex items-center rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
          >
            {sendingTest ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Send className="mr-2 h-4 w-4" />}
            {sendingTest ? 'Enviando...' : 'Enviar prueba'}
          </button>

          <button
            type="button"
            onClick={onClose}
            className="inline-flex items-center rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            <X className="mr-2 h-4 w-4" />
            Cancelar
          </button>
          <button
            type="button"
            onClick={handleSave}
            disabled={!ready || saving}
            className="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          >
            {saving ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
            {saving ? 'Guardando...' : 'Guardar plantilla'}
          </button>
        </div>
      </header>

      <div className="gjs-block-editor relative min-h-0 flex-1">
        {!ready && (
          <div className="absolute inset-0 z-10 flex items-center justify-center bg-white">
            <span className="inline-flex items-center text-sm text-gray-500">
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              Cargando editor...
            </span>
          </div>
        )}
        <div ref={holderRef} className="h-full" />
      </div>
    </div>
  );
};

export default BlockEditor;
