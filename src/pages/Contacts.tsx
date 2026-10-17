import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  UserPlus, Trash2, Upload, List, Contact as ContactIcon,
  Search, ChevronLeft, ChevronRight,
} from 'lucide-react';
import toast from 'react-hot-toast';
import * as api from '../services/api';

const Contacts: React.FC = () => {
  const [contacts, setContacts] = useState<api.Contact[]>([]);
  const [lists, setLists] = useState<api.ContactList[]>([]);
  const [loading, setLoading] = useState(true);
  const [pagination, setPagination] = useState<api.Pagination | null>(null);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [contactForm, setContactForm] = useState({ name: '', email: '', phone: '' });
  const [listForm, setListForm] = useState({ name: '', description: '' });
  const [importListId, setImportListId] = useState<string>('');
  const [importing, setImporting] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [c, l] = await Promise.all([
        api.listContacts({ page, perPage: 25, search: search.trim() || undefined }),
        api.listLists(),
      ]);
      setContacts(c.contacts);
      setPagination(c.pagination);
      setLists(l.lists);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error al cargar');
    } finally {
      setLoading(false);
    }
  }, [page, search]);

  // Se espera a que el usuario deje de teclear para no consultar en cada letra.
  useEffect(() => {
    const id = window.setTimeout(() => { load(); }, 300);

    return () => window.clearTimeout(id);
  }, [load]);

  const addContact = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!contactForm.email && !contactForm.phone) {
      toast.error('Indica al menos email o teléfono');
      return;
    }
    try {
      await api.createContact(contactForm);
      toast.success('Contacto agregado');
      setContactForm({ name: '', email: '', phone: '' });
      load();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error al crear contacto');
    }
  };

  const removeContact = async (id: number) => {
    if (!window.confirm('¿Eliminar este contacto?')) return;
    try {
      await api.deleteContact(id);
      load();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error al eliminar');
    }
  };

  const addList = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!listForm.name) {
      toast.error('El nombre de la lista es obligatorio');
      return;
    }
    try {
      await api.createList(listForm);
      toast.success('Lista creada');
      setListForm({ name: '', description: '' });
      load();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error al crear lista');
    }
  };

  const removeList = async (id: number) => {
    if (!window.confirm('¿Eliminar esta lista? (los contactos no se borran)')) return;
    try {
      await api.deleteList(id);
      load();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error al eliminar lista');
    }
  };

  const handleImport = async () => {
    const file = fileRef.current?.files?.[0];
    if (!file) {
      toast.error('Selecciona un archivo CSV');
      return;
    }
    setImporting(true);
    try {
      const res = await api.importContacts(file, importListId ? Number(importListId) : undefined);
      toast.success(`Importados: ${res.imported}, omitidos: ${res.skipped}`);
      if (fileRef.current) fileRef.current.value = '';
      load();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error al importar');
    } finally {
      setImporting(false);
    }
  };

  const inputClass = 'shadow-sm focus:ring-blue-500 focus:border-blue-500 block w-full sm:text-sm border-gray-300 rounded-md border px-3 py-2';

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Contactos</h1>
        <p className="mt-1 text-sm text-gray-500">
          Gestiona contactos y listas reutilizables para tus envíos masivos y campañas.
        </p>
      </div>

      {/* Importación CSV */}
      <div className="bg-white shadow rounded-lg p-6">
        <h3 className="text-lg font-medium text-gray-900 mb-1 flex items-center">
          <Upload className="mr-2 h-5 w-5 text-blue-600" />
          Importar CSV
        </h3>
        <p className="text-sm text-gray-500 mb-4">
          El CSV debe tener una cabecera con las columnas <code>name,email,phone</code>.
        </p>
        <div className="flex flex-col sm:flex-row gap-3 sm:items-center">
          <input ref={fileRef} type="file" accept=".csv,text/csv" className="text-sm" />
          <select value={importListId} onChange={(e) => setImportListId(e.target.value)} className={inputClass + ' sm:w-64'}>
            <option value="">Sin asignar a lista</option>
            {lists.map((l) => (
              <option key={l.id} value={l.id}>{l.name}</option>
            ))}
          </select>
          <button
            onClick={handleImport}
            disabled={importing}
            className="inline-flex justify-center items-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50"
          >
            {importing ? 'Importando...' : 'Importar'}
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Listas */}
        <div className="bg-white shadow rounded-lg overflow-hidden">
          <div className="px-4 py-5 sm:px-6 border-b border-gray-200">
            <h3 className="text-lg font-medium text-gray-900 flex items-center">
              <List className="mr-2 h-5 w-5 text-gray-500" />
              Listas ({lists.length})
            </h3>
          </div>
          <div className="p-4">
            <form onSubmit={addList} className="flex gap-2 mb-4">
              <input type="text" placeholder="Nombre de la lista" value={listForm.name} onChange={(e) => setListForm({ ...listForm, name: e.target.value })} className={inputClass} />
              <button type="submit" className="shrink-0 inline-flex items-center px-3 py-2 rounded-md text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                <UserPlus className="h-4 w-4" />
              </button>
            </form>
            <ul className="divide-y divide-gray-200">
              {lists.map((l) => (
                <li key={l.id} className="py-3 flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium text-gray-900">{l.name}</p>
                    <p className="text-xs text-gray-500">{l.member_count} contactos</p>
                  </div>
                  <button onClick={() => removeList(l.id)} className="text-red-600 hover:text-red-900">
                    <Trash2 className="h-4 w-4" />
                  </button>
                </li>
              ))}
              {lists.length === 0 && <li className="py-6 text-center text-sm text-gray-500">No hay listas.</li>}
            </ul>
          </div>
        </div>

        {/* Contactos */}
        <div className="bg-white shadow rounded-lg overflow-hidden">
          <div className="px-4 py-5 sm:px-6 border-b border-gray-200">
            <h3 className="text-lg font-medium text-gray-900 flex items-center">
              <ContactIcon className="mr-2 h-5 w-5 text-gray-500" />
              Contactos ({pagination?.total ?? contacts.length})
            </h3>
          </div>
          <div className="p-4">
            <div className="relative mb-4">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
              <input
                type="search"
                value={search}
                onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                placeholder="Buscar por nombre, email o teléfono"
                aria-label="Buscar contactos"
                className={inputClass + ' pl-9'}
              />
            </div>
            <form onSubmit={addContact} className="grid grid-cols-1 sm:grid-cols-3 gap-2 mb-4">
              <input type="text" placeholder="Nombre" value={contactForm.name} onChange={(e) => setContactForm({ ...contactForm, name: e.target.value })} className={inputClass} />
              <input type="email" placeholder="Email" value={contactForm.email} onChange={(e) => setContactForm({ ...contactForm, email: e.target.value })} className={inputClass} />
              <div className="flex gap-2">
                <input type="tel" placeholder="Teléfono" value={contactForm.phone} onChange={(e) => setContactForm({ ...contactForm, phone: e.target.value })} className={inputClass} />
                <button type="submit" className="shrink-0 inline-flex items-center px-3 py-2 rounded-md text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                  <UserPlus className="h-4 w-4" />
                </button>
              </div>
            </form>
            <div className="max-h-80 overflow-y-auto">
              <ul className="divide-y divide-gray-200">
                {loading ? (
                  <li className="py-6 text-center text-sm text-gray-500">Cargando...</li>
                ) : contacts.length === 0 ? (
                  <li className="py-6 text-center text-sm text-gray-500">No hay contactos.</li>
                ) : (
                  contacts.map((c) => (
                    <li key={c.id} className="py-3 flex items-center justify-between">
                      <div>
                        <p className="text-sm font-medium text-gray-900">{c.name || '(sin nombre)'}</p>
                        <p className="text-xs text-gray-500">{c.email || ''}{c.email && c.phone ? ' · ' : ''}{c.phone || ''}</p>
                      </div>
                      <button onClick={() => removeContact(c.id)} className="text-red-600 hover:text-red-900">
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </li>
                  ))
                )}
              </ul>
            </div>

            {pagination && pagination.totalPages > 1 && (
              <div className="mt-3 flex items-center justify-between border-t border-gray-200 pt-3 text-sm">
                <span className="text-gray-600">
                  Página {pagination.page} de {pagination.totalPages}
                </span>
                <div className="flex gap-2">
                  <button type="button" onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={pagination.page <= 1}
                    className="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 hover:bg-gray-50 disabled:opacity-40">
                    <ChevronLeft className="h-4 w-4" /> Anterior
                  </button>
                  <button type="button" onClick={() => setPage((p) => p + 1)} disabled={!pagination.hasMore}
                    className="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 hover:bg-gray-50 disabled:opacity-40">
                    Siguiente <ChevronRight className="h-4 w-4" />
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

export default Contacts;
