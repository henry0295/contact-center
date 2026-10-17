import React from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import Sidebar from './Sidebar';
import Navbar from './Navbar';
import { useSidebar } from '../context/SidebarContext';

const Layout: React.FC = () => {
  const { isSidebarOpen, closeSidebar } = useSidebar();
  const location = useLocation();

  // En móvil la barra lateral se superpone: al navegar se cierra sola para no
  // quedarse tapando el contenido recién cargado.
  React.useEffect(() => {
    if (window.innerWidth < 768) {
      closeSidebar();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.pathname]);

  return (
    <div className="flex h-screen bg-gray-50">
      <Sidebar />

      {/* Fondo oscuro en móvil: permite cerrar la barra tocando fuera. */}
      {isSidebarOpen && (
        <div
          onClick={closeSidebar}
          className="fixed inset-0 z-10 bg-black/40 md:hidden"
          aria-hidden="true"
        />
      )}

      {/*
        La barra lateral está siempre visible desde el breakpoint md (usa
        md:translate-x-0), así que el margen izquierdo debe aplicarse SIEMPRE en
        md+. Antes dependía de isSidebarOpen, que arranca en false entre 768px y
        1023px, y el contenido quedaba oculto detrás de la barra.
      */}
      <div className="flex flex-1 flex-col transition-all duration-300 ease-in-out md:ml-64">
        <Navbar />

        <main className="flex-1 overflow-y-auto px-4 py-8 md:px-6 lg:px-8">
          <div className="mx-auto max-w-7xl fade-in">
            <Outlet />
          </div>
        </main>

        <footer className="border-t border-gray-200 bg-white px-6 py-4 text-center text-sm text-gray-600">
          <div className="mx-auto max-w-7xl">
            <p>© {new Date().getFullYear()} Contact Center. Todos los derechos reservados.</p>
          </div>
        </footer>
      </div>
    </div>
  );
};

export default Layout;
