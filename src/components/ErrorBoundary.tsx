import React from 'react';
import { AlertTriangle, RefreshCw, Home } from 'lucide-react';

interface Props {
  children: React.ReactNode;
}

interface State {
  error: Error | null;
}

/**
 * Captura los errores de renderizado de React.
 *
 * Sin esto, cualquier excepción en un componente dejaba la pantalla en blanco
 * sin explicación. Ahora se muestra un mensaje con opción de reintentar y, en
 * desarrollo, el detalle técnico para depurar.
 *
 * Nota: un ErrorBoundary NO captura errores de código asíncrono (promesas) ni
 * de manejadores de eventos; esos se tratan con try/catch donde ocurren.
 */
class ErrorBoundary extends React.Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = { error: null };
  }

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: React.ErrorInfo): void {
    // En producción convendría enviarlo a un servicio de seguimiento; de momento
    // queda en la consola del navegador, que es donde se mira primero.
    console.error('Error no controlado en la interfaz:', error, info.componentStack);
  }

  private reset = (): void => {
    this.setState({ error: null });
  };

  render(): React.ReactNode {
    const { error } = this.state;

    if (!error) {
      return this.props.children;
    }

    const isDev = import.meta.env.DEV;

    return (
      <div className="flex min-h-screen items-center justify-center bg-gray-50 p-4">
        <div className="w-full max-w-lg rounded-lg bg-white p-8 shadow">
          <div className="flex items-start gap-4">
            <div className="shrink-0 rounded-full bg-red-100 p-3">
              <AlertTriangle className="h-6 w-6 text-red-600" />
            </div>
            <div className="min-w-0">
              <h1 className="text-lg font-semibold text-gray-900">Algo ha fallado</h1>
              <p className="mt-1 text-sm text-gray-600">
                Se produjo un error inesperado en la aplicación. Puedes reintentar; si vuelve a
                ocurrir, recarga la página.
              </p>

              {isDev && (
                <pre className="mt-4 max-h-48 overflow-auto rounded border border-gray-200 bg-gray-50 p-3 text-xs text-red-700">
                  {error.message}
                  {error.stack ? '\n\n' + error.stack : ''}
                </pre>
              )}

              <div className="mt-6 flex flex-wrap gap-2">
                <button
                  type="button"
                  onClick={this.reset}
                  className="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                >
                  <RefreshCw className="mr-2 h-4 w-4" />
                  Reintentar
                </button>
                <button
                  type="button"
                  onClick={() => { window.location.href = '/'; }}
                  className="inline-flex items-center rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                  <Home className="mr-2 h-4 w-4" />
                  Ir al inicio
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    );
  }
}

export default ErrorBoundary;
