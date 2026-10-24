import React, { Suspense, lazy } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import { Toaster } from 'react-hot-toast';
import { Loader2 } from 'lucide-react';
import { UserProvider } from './context/UserContext';
import { SidebarProvider } from './context/SidebarContext';
import { SesDataProvider } from './context/SesDataContext';
import ErrorBoundary from './components/ErrorBoundary';
import ProtectedRoute from './components/ProtectedRoute';
import AdminRoute from './components/AdminRoute';
import Layout from './components/Layout';
import Login from './pages/Login';
import './App.css';

// Carga diferida por ruta: cada módulo viaja en su propio paquete y solo se
// descarga al visitarlo. Así la carga inicial no arrastra toda la aplicación.
const Dashboard = lazy(() => import('./pages/Dashboard'));
const EmailModule = lazy(() => import('./pages/EmailModule'));
const SMSModule = lazy(() => import('./pages/SMSModule'));
const CallModule = lazy(() => import('./pages/CallModule'));
const WhatsAppModule = lazy(() => import('./pages/WhatsAppModule'));
const Reports = lazy(() => import('./pages/Reports'));
const Verification = lazy(() => import('./pages/Verification'));
const Contacts = lazy(() => import('./pages/Contacts'));
const Users = lazy(() => import('./pages/Users'));
const Settings = lazy(() => import('./pages/Settings'));
const NotFound = lazy(() => import('./pages/NotFound'));

/** Indicador mientras se descarga el paquete de la ruta. */
const RouteFallback: React.FC = () => (
  <div className="flex items-center justify-center py-20" role="status" aria-live="polite">
    <span className="inline-flex items-center text-sm text-gray-500">
      <Loader2 className="mr-2 h-5 w-5 animate-spin" />
      Cargando módulo...
    </span>
  </div>
);

function App() {
  return (
    <ErrorBoundary>
      <Router>
        <UserProvider>
          <SesDataProvider>
            <SidebarProvider>
              <Toaster position="top-right" />
              <Suspense fallback={<RouteFallback />}>
                <Routes>
                  <Route path="/login" element={<Login />} />
                  <Route path="/" element={<ProtectedRoute><Layout /></ProtectedRoute>}>
                    <Route index element={<Dashboard />} />
                    <Route path="email" element={<EmailModule />} />
                    <Route path="sms" element={<SMSModule />} />
                    <Route path="calls" element={<CallModule />} />
                    <Route path="whatsapp" element={<WhatsAppModule />} />
                    <Route path="reports" element={<Reports />} />
                    {/* El módulo "Campañas" se retiró del menú: cada envío de SMS/Email
                        crea su propia campaña, y se gestionan desde Reportes > Campañas.
                        Se redirige por si quedó algún enlace o marcador antiguo. */}
                    <Route path="campaigns" element={<Navigate to="/reports" replace />} />
                    {/* El módulo "Audio" (biblioteca con Amazon Polly) se retiró: la voz
                        para llamadas ahora la genera y origina directamente el módulo
                        Llamadas, vía la API de Asterisk. Se redirige por compatibilidad. */}
                    <Route path="audio" element={<Navigate to="/calls" replace />} />
                    <Route path="verification" element={<AdminRoute><Verification /></AdminRoute>} />
                    <Route path="contacts" element={<Contacts />} />
                    <Route path="settings" element={<Settings />} />
                    {/* Gestión de usuarios: solo administradores. */}
                    <Route path="users" element={<AdminRoute><Users /></AdminRoute>} />
                  </Route>
                  <Route path="*" element={<NotFound />} />
                </Routes>
              </Suspense>
            </SidebarProvider>
          </SesDataProvider>
        </UserProvider>
      </Router>
    </ErrorBoundary>
  );
}

export default App;
