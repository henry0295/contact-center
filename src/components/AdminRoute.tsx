import React from 'react';
import { ShieldAlert } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useUser } from '../context/UserContext';

/**
 * Restringe una ruta al rol de administrador.
 *
 * Es una capa de comodidad: la autorización REAL la impone el backend con
 * RoleMiddleware, porque cualquier control del navegador se puede saltar.
 */
const AdminRoute: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { isAdmin } = useUser();

  if (!isAdmin) {
    return (
      <div className="mx-auto max-w-lg rounded-lg bg-white p-8 text-center shadow">
        <div className="mx-auto w-fit rounded-full bg-amber-100 p-3">
          <ShieldAlert className="h-6 w-6 text-amber-600" />
        </div>
        <h1 className="mt-4 text-lg font-semibold text-gray-900">Acceso restringido</h1>
        <p className="mt-1 text-sm text-gray-600">
          Esta sección es solo para administradores. Si necesitas acceso, pídeselo a quien
          administre la plataforma.
        </p>
        <Link
          to="/"
          className="mt-6 inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
        >
          Volver al panel
        </Link>
      </div>
    );
  }

  return <>{children}</>;
};

export default AdminRoute;
