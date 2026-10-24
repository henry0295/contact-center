import React from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { useSidebar } from '../context/SidebarContext';
import { useUser } from '../context/UserContext';
import { 
  LayoutDashboard, 
  Mail, 
  MessageSquare, 
  BarChart3, 
  Users,
  ShieldCheck,
  Contact,
  Settings,
  PhoneCall,
  MessageCircle
} from 'lucide-react';

interface NavItemProps {
  to: string;
  icon: React.ReactNode;
  label: string;
}

const NavItem: React.FC<NavItemProps> = ({ to, icon, label }) => {
  const location = useLocation();
  const isActive = location.pathname === to;

  return (
    <NavLink 
      to={to}
      className={({ isActive }) => `
        flex items-center gap-3 px-4 py-3 rounded-md transition-colors
        ${isActive 
          ? 'bg-blue-100 text-blue-700' 
          : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
        }
      `}
    >
      {React.cloneElement(icon as React.ReactElement, { 
        size: 20,
        className: isActive ? 'text-blue-700' : 'text-gray-500'
      })}
      <span className="font-medium">{label}</span>
    </NavLink>
  );
};

const Sidebar: React.FC = () => {
  const { isSidebarOpen } = useSidebar();
  const { isAdmin } = useUser();

  return (
    <aside className={`
      fixed inset-y-0 left-0 z-20 w-64 bg-white border-r border-gray-200 
      transform transition-transform duration-300 ease-in-out
      ${isSidebarOpen ? 'translate-x-0' : '-translate-x-full'}
      md:translate-x-0
    `}>
      <div className="flex flex-col h-full">
        <div className="flex items-center h-16 px-6 border-b border-gray-200">
          <h2 className="text-xl font-bold text-blue-700">Contact Center</h2>
        </div>

        <nav className="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
          <NavItem to="/" icon={<LayoutDashboard />} label="Panel" />
          <NavItem to="/email" icon={<Mail />} label="Email" />
          <NavItem to="/sms" icon={<MessageSquare />} label="SMS" />
          <NavItem to="/calls" icon={<PhoneCall />} label="Llamadas" />
          <NavItem to="/whatsapp" icon={<MessageCircle />} label="WhatsApp" />
          {isAdmin && <NavItem to="/verification" icon={<ShieldCheck />} label="Verificación" />}
          <NavItem to="/contacts" icon={<Contact />} label="Contactos" />
          {/* La gestión de campañas vive en Reportes (pestaña Campañas): cada
              envío de SMS/Email ya crea su propia campaña automáticamente. */}
          <NavItem to="/reports" icon={<BarChart3 />} label="Reportes" />
          {/* La gestión de usuarios solo se ofrece a administradores; el backend
              la restringe igualmente con su propio control de rol. */}
          {isAdmin && <NavItem to="/users" icon={<Users />} label="Usuarios" />}
          <NavItem to="/settings" icon={<Settings />} label="Mi cuenta" />
        </nav>

        <div className="p-4 border-t border-gray-200">
          <div className="bg-blue-50 p-3 rounded-lg">
            <h3 className="text-sm font-medium text-blue-700">¿Necesitas ayuda?</h3>
            <p className="mt-1 text-xs text-blue-600">Consulta la documentación del proyecto.</p>
          </div>
        </div>
      </div>
    </aside>
  );
};

export default Sidebar;