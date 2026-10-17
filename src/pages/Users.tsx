import React, { useEffect, useState } from 'react';
import { UserPlus, Trash2, Users as UsersIcon, Shield, KeyRound } from 'lucide-react';
import toast from 'react-hot-toast';
import * as api from '../services/api';
import { useUser } from '../context/UserContext';

const Users: React.FC = () => {
  const { user: current } = useUser();
  const [users, setUsers] = useState<api.AuthUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState({ name: '', email: '', password: '', role: 'admin' });

  const load = async () => {
    setLoading(true);
    try {
      const res = await api.listUsers();
      setUsers(res.users);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error al cargar usuarios');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, []);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form.name || !form.email || !form.password) {
      toast.error('Nombre, email y contraseña son obligatorios');
      return;
    }
    setSaving(true);
    try {
      await api.createUser(form);
      toast.success('Usuario creado');
      setForm({ name: '', email: '', password: '', role: 'admin' });
      load();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error al crear usuario');
    } finally {
      setSaving(false);
    }
  };

  /** El admin asigna una contraseña nueva; se cierran las sesiones del usuario. */
  const handleReset = async (u: api.AuthUser) => {
    const nueva = window.prompt(
      `Nueva contraseña para ${u.email}\n\nMínimo 10 caracteres, combinando letras y números.`
    );
    if (!nueva) return;

    try {
      const res = await api.resetUserPassword(u.id, nueva);
      toast.success(`${res.message} (${res.sessionsRevoked} sesión/es cerradas)`);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error al restablecer la contraseña');
    }
  };

  const handleDelete = async (id: number, email: string) => {
    if (!window.confirm(`¿Eliminar al usuario ${email}?`)) return;
    try {
      await api.deleteUser(id);
      toast.success('Usuario eliminado');
      load();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Error al eliminar usuario');
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Usuarios</h1>
        <p className="mt-1 text-sm text-gray-500">
          Crea y elimina los usuarios con acceso a la plataforma.
        </p>
      </div>

      <div className="bg-white shadow rounded-lg p-6">
        <h3 className="text-lg font-medium text-gray-900 mb-4 flex items-center">
          <UserPlus className="mr-2 h-5 w-5 text-blue-600" />
          Nuevo usuario
        </h3>
        <form onSubmit={handleCreate} className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <input
            type="text"
            placeholder="Nombre"
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
            className="shadow-sm focus:ring-blue-500 focus:border-blue-500 block w-full sm:text-sm border-gray-300 rounded-md border px-3 py-2"
          />
          <input
            type="email"
            placeholder="Email"
            value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
            className="shadow-sm focus:ring-blue-500 focus:border-blue-500 block w-full sm:text-sm border-gray-300 rounded-md border px-3 py-2"
          />
          <input
            type="password"
            placeholder="Contraseña (mín. 10, letras y números)"
            value={form.password}
            onChange={(e) => setForm({ ...form, password: e.target.value })}
            className="shadow-sm focus:ring-blue-500 focus:border-blue-500 block w-full sm:text-sm border-gray-300 rounded-md border px-3 py-2"
          />
          <button
            type="submit"
            disabled={saving}
            className="inline-flex justify-center items-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
          >
            {saving ? 'Creando...' : 'Crear usuario'}
          </button>
        </form>
      </div>

      <div className="bg-white shadow rounded-lg overflow-hidden">
        <div className="px-4 py-5 sm:px-6 border-b border-gray-200">
          <h3 className="text-lg font-medium text-gray-900 flex items-center">
            <UsersIcon className="mr-2 h-5 w-5 text-gray-500" />
            Usuarios ({users.length})
          </h3>
        </div>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nombre</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rol</th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {loading ? (
                <tr><td colSpan={4} className="px-6 py-10 text-center text-sm text-gray-500">Cargando...</td></tr>
              ) : users.length === 0 ? (
                <tr><td colSpan={4} className="px-6 py-10 text-center text-sm text-gray-500">No hay usuarios.</td></tr>
              ) : (
                users.map((u) => (
                  <tr key={u.id} className="hover:bg-gray-50">
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{u.name}</td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{u.email}</td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className="px-2 inline-flex items-center text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                        <Shield className="mr-1 h-3 w-3" />
                        {u.role}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                      <div className="inline-flex items-center gap-3">
                        <button
                          onClick={() => handleReset(u)}
                          className="inline-flex items-center text-blue-600 hover:text-blue-900"
                          title="Asignar una contraseña nueva"
                        >
                          <KeyRound className="mr-1 h-4 w-4" />
                          Contraseña
                        </button>
                        {current?.id === u.id ? (
                          <span className="text-xs text-gray-400">Tú</span>
                        ) : (
                          <button
                            onClick={() => handleDelete(u.id, u.email)}
                            className="text-red-600 hover:text-red-900 inline-flex items-center"
                          >
                            <Trash2 className="h-4 w-4 mr-1" />
                            Eliminar
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
};

export default Users;
