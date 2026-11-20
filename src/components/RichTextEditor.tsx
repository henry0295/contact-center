import React, { useCallback, useEffect, useRef, useState } from 'react';
import { useEditor, EditorContent, Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';
import TextAlign from '@tiptap/extension-text-align';
import { TextStyle } from '@tiptap/extension-text-style';
import { Color } from '@tiptap/extension-color';
import {
  Bold, Italic, Strikethrough, Heading1, Heading2, List, ListOrdered,
  Link as LinkIcon, Image as ImageIcon, AlignLeft, AlignCenter, AlignRight,
  Undo, Redo, Quote, Minus, Code, Loader2, X, Link2Off,
} from 'lucide-react';
import toast from 'react-hot-toast';
import { uploadImage } from '../services/api';

interface Props {
  value: string;
  onChange: (html: string) => void;
  placeholder?: string;
  minHeight?: number;
}

/** Botón de la barra de herramientas. */
const ToolButton: React.FC<{
  onClick: () => void;
  active?: boolean;
  disabled?: boolean;
  title: string;
  children: React.ReactNode;
}> = ({ onClick, active, disabled, title, children }) => (
  <button
    type="button"
    onMouseDown={(e) => e.preventDefault()}
    onClick={onClick}
    disabled={disabled}
    title={title}
    aria-label={title}
    aria-pressed={!!active}
    className={`p-2 rounded-md transition-colors disabled:opacity-40 disabled:cursor-not-allowed ${
      active ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
    }`}
  >
    {children}
  </button>
);

const Divider = () => <span className="w-px h-6 bg-gray-200 mx-1" aria-hidden="true" />;

const COLORS = ['#111827', '#2563eb', '#059669', '#d97706', '#dc2626', '#7c3aed'];

/**
 * Completa la dirección como la escribe la gente: "unicuces.edu.co" -> https://,
 * un correo -> mailto:. Se admite cualquier dominio y las variables {{...}} del
 * CSV se dejan tal cual.
 */
const normalizeUrl = (raw: string): string => {
  const url = raw.trim().replace(/^<|>$/g, '');
  if (url === '') return '';
  if (url.includes('{{')) return url;
  if (/^[a-z][a-z0-9+.-]*:/i.test(url)) return url; // ya trae https:, mailto:, tel:...
  if (/^\/\//.test(url)) return `https:${url}`;
  if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(url)) return `mailto:${url}`;

  return `https://${url}`;
};

/** Diálogo para insertar o editar un enlace. */
const LinkDialog: React.FC<{
  initialUrl: string;
  initialText: string;
  canRemove: boolean;
  onCancel: () => void;
  onRemove: () => void;
  onSubmit: (url: string, text: string) => void;
}> = ({ initialUrl, initialText, canRemove, onCancel, onRemove, onSubmit }) => {
  const [url, setUrl] = useState(initialUrl);
  const [text, setText] = useState(initialText);
  const urlRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    urlRef.current?.focus();
    urlRef.current?.select();
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onCancel(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onCancel]);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (url.trim() === '') return;
    onSubmit(url, text);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onMouseDown={onCancel}>
      <form
        onSubmit={submit}
        onMouseDown={(e) => e.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-labelledby="rteLinkTitle"
        className="w-full max-w-md overflow-hidden rounded-lg bg-white shadow-xl"
      >
        <div className="flex items-start justify-between gap-3 border-b border-gray-200 px-5 py-3">
          <h2 id="rteLinkTitle" className="flex items-center gap-2 text-base font-semibold text-gray-900">
            <LinkIcon className="h-4 w-4 text-gray-400" aria-hidden="true" /> {canRemove ? 'Editar enlace' : 'Insertar enlace'}
          </h2>
          <button type="button" onClick={onCancel} className="text-gray-400 hover:text-gray-600" aria-label="Cerrar">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="space-y-3 px-5 py-4">
          <div>
            <label htmlFor="rteLinkUrl" className="mb-1 block text-sm font-medium text-gray-700">Dirección *</label>
            <input
              id="rteLinkUrl"
              ref={urlRef}
              type="text"
              value={url}
              onChange={(e) => setUrl(e.target.value)}
              placeholder="https://ejemplo.com/pagina"
              className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <p className="mt-1 text-xs text-gray-500">
              Cualquier dominio. También vale un correo (se convierte en mailto:) o una variable del CSV.
            </p>
          </div>
          <div>
            <label htmlFor="rteLinkText" className="mb-1 block text-sm font-medium text-gray-700">Texto que se muestra</label>
            <input
              id="rteLinkText"
              type="text"
              value={text}
              onChange={(e) => setText(e.target.value)}
              placeholder="Ver más"
              className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <p className="mt-1 text-xs text-gray-500">Si lo dejas vacío se muestra la dirección.</p>
          </div>
        </div>

        <div className="flex items-center justify-end gap-3 border-t border-gray-200 px-5 py-3">
          {canRemove && (
            <button
              type="button"
              onClick={onRemove}
              className="mr-auto flex items-center gap-1.5 rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
              <Link2Off className="h-4 w-4" aria-hidden="true" /> Quitar enlace
            </button>
          )}
          <button type="button" onClick={onCancel} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
            Cancelar
          </button>
          <button
            type="submit"
            disabled={url.trim() === ''}
            className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          >
            {canRemove ? 'Guardar' : 'Insertar'}
          </button>
        </div>
      </form>
    </div>
  );
};

const RichTextEditor: React.FC<Props> = ({ value, onChange, placeholder, minHeight = 260 }) => {
  const fileRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = React.useState(false);

  const editor = useEditor({
    extensions: [
      StarterKit.configure({ heading: { levels: [1, 2, 3] } }),
      // Se acepta cualquier dominio; https por defecto al escribir "ejemplo.com".
      Link.configure({ openOnClick: false, autolink: true, defaultProtocol: 'https', linkOnPaste: true }),
      Image.configure({ inline: false }),
      TextAlign.configure({ types: ['heading', 'paragraph'] }),
      TextStyle,
      Color,
    ],
    content: value,
    onUpdate: ({ editor: ed }) => onChange(ed.getHTML()),
    editorProps: {
      attributes: {
        class: 'prose-email focus:outline-none px-4 py-3',
        style: `min-height:${minHeight}px`,
      },
    },
  });

  // Sincroniza cuando el valor cambia desde fuera (p.ej. al cargar una plantilla).
  useEffect(() => {
    if (!editor) return;
    if (value !== editor.getHTML()) {
      editor.commands.setContent(value || '', { emitUpdate: false });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, editor]);

  // Diálogo de enlace: null = cerrado.
  const [linkDialog, setLinkDialog] = useState<{ url: string; text: string; editing: boolean } | null>(null);

  const openLinkDialog = useCallback((ed: Editor) => {
    // Si el cursor está dentro de un enlace, se trabaja sobre todo el enlace.
    ed.chain().focus().extendMarkRange('link').run();
    const { from, to, empty } = ed.state.selection;
    const selected = empty ? '' : ed.state.doc.textBetween(from, to, ' ');
    const href = (ed.getAttributes('link').href as string | undefined) ?? '';
    setLinkDialog({ url: href, text: selected, editing: href !== '' });
  }, []);

  const applyLink = useCallback(
    (rawUrl: string, rawText: string) => {
      if (!editor) return;
      const href = normalizeUrl(rawUrl);
      if (href === '') return;

      const { from, empty } = editor.state.selection;
      const label = rawText.trim() !== '' ? rawText.trim() : rawUrl.trim();
      const keepSelection = !empty && label === (linkDialog?.text ?? '').trim();

      if (keepSelection) {
        // Hay texto seleccionado y no se cambió: se le pone el enlace y se
        // conserva su formato (negritas, varios párrafos, etc.).
        editor.chain().focus().setLink({ href }).run();
      } else {
        // Sin selección (o con otro texto): se inserta el texto y se enlaza.
        // setLink solo marca texto existente, por eso antes no aparecía nada.
        editor.chain().focus().insertContent({ type: 'text', text: label }).run();
        editor
          .chain()
          .setTextSelection({ from, to: from + label.length })
          .setLink({ href })
          .setTextSelection(from + label.length)
          .unsetMark('link') // lo que se escriba después ya no va enlazado
          .run();
      }
      setLinkDialog(null);
    },
    [editor, linkDialog]
  );

  const removeLink = useCallback(() => {
    editor?.chain().focus().extendMarkRange('link').unsetLink().run();
    setLinkDialog(null);
  }, [editor]);

  const handleImageFile = async (file?: File) => {
    if (!file || !editor) return;
    setUploading(true);
    try {
      // Se sube al servidor y se inserta por URL absoluta: los clientes de
      // correo suelen bloquear las imágenes incrustadas en base64.
      const res = await uploadImage(file);
      editor.chain().focus().setImage({ src: res.url }).run();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'No se pudo subir la imagen');
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = '';
    }
  };

  if (!editor) {
    return (
      <div className="border border-gray-300 rounded-md bg-gray-50" style={{ minHeight }} />
    );
  }

  return (
    <div className="border border-gray-300 rounded-md overflow-hidden focus-within:ring-2 focus-within:ring-blue-500 focus-within:border-blue-500 bg-white">
      <div className="flex items-center flex-wrap gap-0.5 px-2 py-1.5 border-b border-gray-200 bg-gray-50">
        <ToolButton title="Negrita" active={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()}>
          <Bold className="h-4 w-4" />
        </ToolButton>
        <ToolButton title="Cursiva" active={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()}>
          <Italic className="h-4 w-4" />
        </ToolButton>
        <ToolButton title="Tachado" active={editor.isActive('strike')} onClick={() => editor.chain().focus().toggleStrike().run()}>
          <Strikethrough className="h-4 w-4" />
        </ToolButton>

        <Divider />

        <ToolButton title="Título 1" active={editor.isActive('heading', { level: 1 })} onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()}>
          <Heading1 className="h-4 w-4" />
        </ToolButton>
        <ToolButton title="Título 2" active={editor.isActive('heading', { level: 2 })} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}>
          <Heading2 className="h-4 w-4" />
        </ToolButton>
        <ToolButton title="Cita" active={editor.isActive('blockquote')} onClick={() => editor.chain().focus().toggleBlockquote().run()}>
          <Quote className="h-4 w-4" />
        </ToolButton>

        <Divider />

        <ToolButton title="Lista con viñetas" active={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()}>
          <List className="h-4 w-4" />
        </ToolButton>
        <ToolButton title="Lista numerada" active={editor.isActive('orderedList')} onClick={() => editor.chain().focus().toggleOrderedList().run()}>
          <ListOrdered className="h-4 w-4" />
        </ToolButton>

        <Divider />

        <ToolButton title="Alinear a la izquierda" active={editor.isActive({ textAlign: 'left' })} onClick={() => editor.chain().focus().setTextAlign('left').run()}>
          <AlignLeft className="h-4 w-4" />
        </ToolButton>
        <ToolButton title="Centrar" active={editor.isActive({ textAlign: 'center' })} onClick={() => editor.chain().focus().setTextAlign('center').run()}>
          <AlignCenter className="h-4 w-4" />
        </ToolButton>
        <ToolButton title="Alinear a la derecha" active={editor.isActive({ textAlign: 'right' })} onClick={() => editor.chain().focus().setTextAlign('right').run()}>
          <AlignRight className="h-4 w-4" />
        </ToolButton>

        <Divider />

        <ToolButton title="Enlace" active={editor.isActive('link')} onClick={() => openLinkDialog(editor)}>
          <LinkIcon className="h-4 w-4" />
        </ToolButton>
        <ToolButton title="Insertar imagen" disabled={uploading} onClick={() => fileRef.current?.click()}>
          {uploading ? <Loader2 className="h-4 w-4 animate-spin" /> : <ImageIcon className="h-4 w-4" />}
        </ToolButton>
        <ToolButton title="Línea separadora" onClick={() => editor.chain().focus().setHorizontalRule().run()}>
          <Minus className="h-4 w-4" />
        </ToolButton>
        <ToolButton title="Código" active={editor.isActive('codeBlock')} onClick={() => editor.chain().focus().toggleCodeBlock().run()}>
          <Code className="h-4 w-4" />
        </ToolButton>

        <Divider />

        <div className="flex items-center gap-1 px-1" role="group" aria-label="Color del texto">
          {COLORS.map((c) => (
            <button
              key={c}
              type="button"
              title={`Color ${c}`}
              aria-label={`Color ${c}`}
              onMouseDown={(e) => e.preventDefault()}
              onClick={() => editor.chain().focus().setColor(c).run()}
              className="w-5 h-5 rounded-full border border-gray-300 hover:scale-110 transition-transform"
              style={{ backgroundColor: c }}
            />
          ))}
        </div>

        <div className="ml-auto flex items-center gap-0.5">
          <ToolButton title="Deshacer" disabled={!editor.can().undo()} onClick={() => editor.chain().focus().undo().run()}>
            <Undo className="h-4 w-4" />
          </ToolButton>
          <ToolButton title="Rehacer" disabled={!editor.can().redo()} onClick={() => editor.chain().focus().redo().run()}>
            <Redo className="h-4 w-4" />
          </ToolButton>
        </div>
      </div>

      <div className="relative">
        {editor.isEmpty && placeholder && (
          <p className="absolute left-0 top-0 px-4 pt-3 pb-0 text-sm text-gray-400 pointer-events-none">
            {placeholder}
          </p>
        )}
        <EditorContent editor={editor} />
      </div>

      <input
        ref={fileRef}
        type="file"
        accept="image/png,image/jpeg,image/gif,image/webp"
        className="hidden"
        onChange={(e) => handleImageFile(e.target.files?.[0])}
      />

      {linkDialog && (
        <LinkDialog
          initialUrl={linkDialog.url}
          initialText={linkDialog.text}
          canRemove={linkDialog.editing}
          onCancel={() => setLinkDialog(null)}
          onRemove={removeLink}
          onSubmit={applyLink}
        />
      )}
    </div>
  );
};

export default RichTextEditor;
