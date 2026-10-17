import React from 'react';
import { Menu, X, User, LogOut } from 'lucide-react';
import { useSidebar } from '../context/SidebarContext';
import { useUser } from '../context/UserContext';

const Navbar: React.FC = () => {
  const { isSidebarOpen, toggleSidebar } = useSidebar();
  const { user, logout } = useUser();
  const [isDropdownOpen, setIsDropdownOpen] = React.useState(false);

  const toggleDropdown = () => setIsDropdownOpen(!isDropdownOpen);

  return (
    <header className="bg-white border-b border-gray-200 shadow-sm z-10">
      <div className="flex items-center justify-between h-16 px-4 md:px-6">
        <div className="flex items-center">
          <button
            onClick={toggleSidebar}
            className="text-gray-600 focus:outline-none lg:hidden"
            aria-label="Toggle sidebar"
          >
            {isSidebarOpen ? (
              <X className="h-6 w-6" />
            ) : (
              <Menu className="h-6 w-6" />
            )}
          </button>

          <h1 className="ml-4 text-lg font-semibold text-gray-800">
            Contact Center
          </h1>
        </div>

        <div className="flex items-center gap-4">
          <div className="relative">
            <button
              onClick={toggleDropdown}
              className="flex items-center gap-2 p-1 pl-2 pr-2 rounded-full border border-gray-200 hover:bg-gray-50 transition-colors focus:outline-none"
            >
              <div className="w-8 h-8 rounded-full bg-blue-500 flex items-center justify-center text-white">
                <User size={16} />
              </div>
              <span className="hidden md:block text-sm font-medium text-gray-700 truncate max-w-[100px]">
                {user?.name || 'User'}
              </span>
            </button>

            {isDropdownOpen && (
              <div className="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg border border-gray-200 z-20">
                <div className="py-1">
                  <div className="px-4 py-2 border-b border-gray-200">
                    <p className="text-sm font-medium text-gray-700">{user?.name}</p>
                    <p className="text-xs text-gray-500">{user?.email}</p>
                  </div>
                  <button
                    onClick={() => {
                      setIsDropdownOpen(false);
                      void logout();
                    }}
                    className="flex items-center w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900"
                  >
                    <LogOut size={16} className="mr-2" />
                    Cerrar sesión
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </header>
  );
};

export default Navbar;