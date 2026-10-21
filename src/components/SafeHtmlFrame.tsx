import React from 'react';

/**
 * Muestra HTML de terceros (plantillas, cuerpos de correo) de forma aislada.
 *
 * El iframe lleva `sandbox` SIN `allow-scripts` ni `allow-same-origin`: no se
 * ejecuta ningún script y el contenido corre en un origen opaco, así que no
 * puede leer localStorage (donde están los tokens) ni llamar a la API. Antes se
 * usaba dangerouslySetInnerHTML, que permitía XSS almacenado vía plantilla.
 */
const SafeHtmlFrame: React.FC<{
  html: string;
  title: string;
  className?: string;
  style?: React.CSSProperties;
}> = ({ html, title, className, style }) => {
  // CSP propia del documento: sin scripts y con imágenes remotas permitidas.
  const doc =
    '<!doctype html><html><head><meta charset="utf-8">' +
    '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data: https: http:; style-src \'unsafe-inline\' https: http:; font-src data: https: http:">' +
    '<base target="_blank">' +
    '<style>body{margin:0;font-family:system-ui,sans-serif}</style></head><body>' +
    html +
    '</body></html>';

  return (
    <iframe
      title={title}
      sandbox=""
      referrerPolicy="no-referrer"
      srcDoc={doc}
      className={className}
      style={{ border: 0, ...style }}
    />
  );
};

export default SafeHtmlFrame;
