import React, { createContext, useState, useContext, useEffect, useCallback, useMemo } from 'react';
import * as api from '../services/api';

interface User {
  id: number;
  name: string;
  email: string;
  role: string;
}

interface UserContextType {
  user: User | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  changePassword: (current: string, next: string) => Promise<string>;
  isAuthenticated: boolean;
  /** true si el usuario tiene rol de administrador. */
  isAdmin: boolean;
}

const UserContext = createContext<UserContextType | undefined>(undefined);

const USER_KEY = 'user';

export const UserProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  const clearSession = useCallback(() => {
    api.clearToken();
    api.clearRefreshToken();
    localStorage.removeItem(USER_KEY);
    setUser(null);
  }, []);

  useEffect(() => {
    // Restaura la sesión si hay token y usuario guardados.
    const storedUser = localStorage.getItem(USER_KEY);
    const token = api.getToken();

    if (!storedUser || !token) {
      setLoading(false);
      return;
    }

    try {
      setUser(JSON.parse(storedUser));
    } catch {
      localStorage.removeItem(USER_KEY);
      setLoading(false);
      return;
    }

    // Se valida contra el servidor: el token pudo caducar o ser revocado
    // mientras la pestaña estaba cerrada. api.getMe() renueva por sí solo con
    // el refresh token si hace falta.
    api.getMe()
      .then((res) => {
        setUser(res.user);
        localStorage.setItem(USER_KEY, JSON.stringify(res.user));
      })
      .catch(() => clearSession())
      .finally(() => setLoading(false));
  }, [clearSession]);

  useEffect(() => {
    // Cierra sesión cuando la API avisa de que ya no hay autorización posible
    // (token caducado y refresh token inservible).
    const handler = () => clearSession();
    window.addEventListener(api.AUTH_ERROR_EVENT, handler);

    return () => window.removeEventListener(api.AUTH_ERROR_EVENT, handler);
  }, [clearSession]);

  const login = useCallback(async (email: string, password: string) => {
    const res = await api.login(email, password);
    api.setToken(res.token);
    api.setRefreshToken(res.refreshToken);
    localStorage.setItem(USER_KEY, JSON.stringify(res.user));
    setUser(res.user);
  }, []);

  const logout = useCallback(async () => {
    // Se avisa al servidor para revocar la sesión de verdad; si la llamada
    // falla, la sesión local se limpia igualmente.
    try {
      await api.logout();
    } catch {
      // Sin acción: el cierre local es lo prioritario.
    }
    clearSession();
  }, [clearSession]);

  const changePassword = useCallback(async (current: string, next: string) => {
    const res = await api.changePassword(current, next);
    // El backend cierra las demás sesiones y emite una nueva: hay que guardarla.
    api.setToken(res.token);
    api.setRefreshToken(res.refreshToken);
    localStorage.setItem(USER_KEY, JSON.stringify(res.user));
    setUser(res.user);

    return res.message ?? 'Contraseña actualizada.';
  }, []);

  const value = useMemo<UserContextType>(() => ({
    user,
    loading,
    login,
    logout,
    changePassword,
    isAuthenticated: !!user,
    isAdmin: user?.role === 'admin',
  }), [user, loading, login, logout, changePassword]);

  return <UserContext.Provider value={value}>{children}</UserContext.Provider>;
};

export const useUser = (): UserContextType => {
  const context = useContext(UserContext);
  if (context === undefined) {
    throw new Error('useUser must be used within a UserProvider');
  }
  return context;
};
