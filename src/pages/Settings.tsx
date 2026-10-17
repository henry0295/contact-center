import React, { useState } from 'react';
import { KeyRound, Loader2, LogOut, ShieldCheck, User as UserIcon } from 'lucide-react';
import toast from 'react-hot-toast';
import * as api from '../services/api';
import { useUser } from '../context/UserContext';

const inputClass =
  'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none';

const Settings: React.FC = () => {
  const { user, changePassword } = useUser();
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [repeat, setRepeat] = useState('');
  const [saving, setSaving] = useState(false);
  const [closingAll, setClosingAll] = useState(false);

  /** Mismas reglas que aplica el backend, para avisar antes de enviar. */
  const strength = (() => {
    if (next === '') return null;
    if (next.length < 10) return 'Debe tener al menos 10 caracteres.';
    if (!/[A-Za-z]/.test(next) || !/\d/.test(next)) return 'Debe combinar letras y números.';
    return null;
  })();

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!current || !next) {
      toast.error('Completa la contraseña actual y la nueva.');
      return;
    }
    if (strength) {
      toast.error(strength);
      return;
    }
    if (next !== repeat) {
      toast.error('La nueva contraseña y su repetición no coinciden.');
      return;
    }

    setSaving(true);
    try {
      const message = await changePassword(current, next);
      toast.success(message);
      setCurrent('');
      setNext('');
      setRepeat('');
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'No se pudo cambiar la contraseña');
    } finally {
      setSaving(false);
    }
  };

  const closeAllSessions = async () => {
    if (!window.confirm('¿Cerrar la sesión en todos los dispositivos? Tendrás que volver a entrar.')) {
      return;
    }
    setClosingAll(true);
    try {
      const res = await api.logoutAll();
      toast.success(`Se cerraron ${res.revoked} sesión(es).`);
      // La sesión actual también queda revocada: la propia API lo detectará.
      window.location.href = '/login';
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error');
    } finally {
      setClosingAll(false);
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Mi cuenta</h1>
        <p className="mt-1 text-sm text-gray-500">Datos de tu usuario y seguridad de la sesión.</p>
      </div>

      <div className="rounded-lg bg-white p-6 shadow">
        <h2 className="mb-4 flex items-center text-lg font-medium text-gray-900">
          <UserIcon className="mr-2 h-5 w-5 text-gray-400" />
          Datos
        </h2>
        <dl className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
          <div>
            <dt className="text-gray-500">Nombre</dt>
            <dd className="font-medium text-gray-900">{user?.name}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Correo</dt>
            <dd className="font-medium text-gray-900">{user?.email}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Rol</dt>
            <dd>
              <span className="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800">
                <ShieldCheck className="mr-1 h-3 w-3" />
                {user?.role}
              </span>
            </dd>
          </div>
        </dl>
      </div>

      <div className="rounded-lg bg-white p-6 shadow">
        <h2 className="mb-1 flex items-center text-lg font-medium text-gray-900">
          <KeyRound className="mr-2 h-5 w-5 text-gray-400" />
          Cambiar contraseña
        </h2>
        <p className="mb-4 text-sm text-gray-500">
          Al cambiarla se cerrarán tus sesiones en los demás dispositivos.
        </p>

        <form onSubmit={submit} className="grid max-w-xl grid-cols-1 gap-4">
          <div>
            <label htmlFor="actual" className="mb-1 block text-sm font-medium text-gray-700">
              Contraseña actual
            </label>
            <input
              id="actual"
              type="password"
              autoComplete="current-password"
              value={current}
              onChange={(e) => setCurrent(e.target.value)}
              className={inputClass}
            />
          </div>
          <div>
            <label htmlFor="nueva" className="mb-1 block text-sm font-medium text-gray-700">
              Nueva contraseña
            </label>
            <input
              id="nueva"
              type="password"
              autoComplete="new-password"
              value={next}
              onChange={(e) => setNext(e.target.value)}
              className={inputClass}
              aria-describedby="ayuda-clave"
            />
            <p id="ayuda-clave" className={`mt-1 text-xs ${strength ? 'text-amber-600' : 'text-gray-500'}`}>
              {strength ?? 'Mínimo 10 caracteres, combinando letras y números.'}
            </p>
          </div>
          <div>
            <label htmlFor="repetir" className="mb-1 block text-sm font-medium text-gray-700">
              Repetir la nueva contraseña
            </label>
            <input
              id="repetir"
              type="password"
              autoComplete="new-password"
              value={repeat}
              onChange={(e) => setRepeat(e.target.value)}
              className={inputClass}
            />
            {repeat !== '' && repeat !== next && (
              <p className="mt-1 text-xs text-red-600">No coinciden.</p>
            )}
          </div>
          <div>
            <button
              type="submit"
              disabled={saving}
              className="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
            >
              {saving ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <KeyRound className="mr-2 h-4 w-4" />}
              {saving ? 'Guardando...' : 'Cambiar contraseña'}
            </button>
          </div>
        </form>
      </div>

      <div className="rounded-lg bg-white p-6 shadow">
        <h2 className="mb-1 text-lg font-medium text-gray-900">Sesiones</h2>
        <p className="mb-4 text-sm text-gray-500">
          Si sospechas que alguien tiene acceso a tu cuenta, cierra todas las sesiones: los tokens
          emitidos dejan de ser válidos al instante.
        </p>
        <button
          type="button"
          onClick={closeAllSessions}
          disabled={closingAll}
          className="inline-flex items-center rounded-md border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
        >
          {closingAll ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <LogOut className="mr-2 h-4 w-4" />}
          Cerrar sesión en todos los dispositivos
        </button>
      </div>
    </div>
  );
};

export default Settings;
