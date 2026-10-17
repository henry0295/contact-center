import React, { useEffect, useState } from 'react';
import { Mail, MessageSquare, Megaphone, Calendar, CheckCircle2, Clock } from 'lucide-react';
import toast from 'react-hot-toast';
import * as api from '../services/api';

const statusBadge = (status: string) => {
  const map: Record<string, string> = {
    Draft: 'bg-yellow-100 text-yellow-800',
    Scheduled: 'bg-blue-100 text-blue-800',
    Sending: 'bg-indigo-100 text-indigo-800',
    Completed: 'bg-green-100 text-green-800',
    Failed: 'bg-red-100 text-red-800',
  };
  return map[status] || 'bg-gray-100 text-gray-800';
};

const Dashboard: React.FC = () => {
  const [stats, setStats] = useState<api.DashboardStats | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.getDashboardStats()
      .then(setStats)
      .catch((e) => toast.error(e instanceof Error ? e.message : 'Error al cargar el panel'))
      .finally(() => setLoading(false));
  }, []);

  const cards = [
    { name: 'Emails enviados', value: stats?.emailsSent ?? 0, icon: <Mail />, bg: 'bg-blue-100', color: 'text-blue-600' },
    { name: 'SMS enviados', value: stats?.smsSent ?? 0, icon: <MessageSquare />, bg: 'bg-green-100', color: 'text-green-600' },
    { name: 'Campañas activas', value: stats?.activeCampaigns ?? 0, icon: <Megaphone />, bg: 'bg-amber-100', color: 'text-amber-600' },
    { name: 'Campañas totales', value: stats?.totalCampaigns ?? 0, icon: <Calendar />, bg: 'bg-purple-100', color: 'text-purple-600' },
  ];

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold text-gray-900">Panel</h1>

      <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
        {cards.map((c) => (
          <div key={c.name} className="bg-white overflow-hidden shadow rounded-lg">
            <div className="p-5 flex items-center">
              <div className={`flex-shrink-0 ${c.bg} rounded-md p-3`}>
                <div className={c.color}>{React.cloneElement(c.icon as React.ReactElement, { size: 24 })}</div>
              </div>
              <div className="ml-5 w-0 flex-1">
                <dt className="text-sm font-medium text-gray-500 truncate">{c.name}</dt>
                <dd className="text-lg font-medium text-gray-900">{loading ? '…' : c.value.toLocaleString('es')}</dd>
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="bg-white overflow-hidden shadow rounded-lg">
        <div className="px-4 py-5 sm:px-6 border-b border-gray-200">
          <h3 className="text-lg leading-6 font-medium text-gray-900">Campañas recientes</h3>
        </div>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Campaña</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Canal</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Resultados</th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {loading ? (
                <tr><td colSpan={4} className="px-6 py-10 text-center text-sm text-gray-500">Cargando...</td></tr>
              ) : !stats || stats.recentCampaigns.length === 0 ? (
                <tr><td colSpan={4} className="px-6 py-10 text-center text-sm text-gray-500">Aún no hay campañas.</td></tr>
              ) : (
                stats.recentCampaigns.map((c) => (
                  <tr key={c.id} className="hover:bg-gray-50">
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{c.name}</td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className={`px-2 py-1 inline-flex text-xs font-semibold rounded-full ${c.channel === 'email' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'}`}>
                        {c.channel.toUpperCase()}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className={`px-2 py-1 inline-flex items-center text-xs font-semibold rounded-full ${statusBadge(c.status)}`}>
                        {c.status === 'Completed' && <CheckCircle2 className="h-3 w-3 mr-1" />}
                        {c.status === 'Scheduled' && <Clock className="h-3 w-3 mr-1" />}
                        {c.status}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {c.sent_count} ok · {c.failed_count} fallidos / {c.total_recipients}
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

export default Dashboard;
