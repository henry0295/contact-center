import React, { useCallback, useEffect, useRef } from 'react';
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
  Undo, Redo, Quote, Minus, Code, Loader2,
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

const RichTextEditor: React.FC<Props> = ({ value, onChange, placeholder, minHeight = 260 }) => {
  const fileRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = React.useState(false);

  const editor = useEditor({
    extensions: [
      StarterKit.configure({ heading: { levels: [1, 2, 3] } }),
      Link.configure({ openOnClick: false, autolink: true }),
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

  const addLink = useCallback(
    (ed: Editor) => {
      const previous = ed.getAttributes('link').href as string | undefined;
      const url = window.prompt('URL del enlace:', previous ?? 'https://');
      if (url === null) return;
      if (url === '') {
        ed.chain().focus().extendMarkRange('link').unsetLink().run();
        return;
      }
      ed.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
    },
    []
  );

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

        <ToolButton title="Enlace" active={editor.isActive('link')} onClick={() => addLink(editor)}>
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
    </div>
  );
};

export default RichTextEditor;
