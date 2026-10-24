/**
 * Biblioteca de plantillas de WhatsApp de ejemplo, por sector y uso, para que
 * el cliente parta de un texto que Meta suele aprobar, lo edite y lo envíe a
 * aprobación desde su línea.
 *
 * Reglas de Meta que cumplen todas:
 *  - Variables {{1}}, {{2}}... consecutivas, nunca al inicio ni al final del
 *    cuerpo, ni dos seguidas sin texto entre ellas.
 *  - UTILITY solo para avisos ligados a algo que el cliente hizo o tiene
 *    (pedido, cita, pago, cuenta); sin lenguaje promocional.
 *  - MARKETING para promociones, novedades y reactivación; incluyen la opción
 *    de darse de baja.
 *  - AUTHENTICATION usa el texto fijo de Meta (solo se eligen las opciones).
 */

export type LibCategory = 'MARKETING' | 'UTILITY' | 'AUTHENTICATION';

export interface LibButton {
  type: 'QUICK_REPLY' | 'URL' | 'PHONE_NUMBER';
  text: string;
  url?: string;
  phone_number?: string;
}

export interface LibTemplate {
  id: string;
  sector: string;
  use: string;
  category: LibCategory;
  /** Nombre sugerido (minúsculas y guion bajo). */
  name: string;
  title: string;
  header?: string;
  body: string;
  footer?: string;
  /** Un ejemplo por variable, en orden. */
  examples: string[];
  buttons?: LibButton[];
  /** Solo AUTHENTICATION. */
  auth?: { securityRecommendation: boolean; expirationMinutes?: number; buttonText: string };
}

export const LIB_SECTORS: Record<string, string> = {
  general: 'General / cualquier negocio',
  comercio: 'Comercio y tiendas',
  salud: 'Salud y clínicas',
  educacion: 'Educación',
  finanzas: 'Banca y finanzas',
  cobranza: 'Cobranza',
  servicios: 'Servicios públicos y telecom',
  inmobiliaria: 'Inmobiliaria',
  logistica: 'Transporte y envíos',
  restaurantes: 'Restaurantes y domicilios',
  turismo: 'Turismo y hoteles',
  seguros: 'Seguros',
  automotriz: 'Automotriz y talleres',
  belleza: 'Belleza y bienestar',
};

export const LIB_USES: Record<string, string> = {
  promocion: 'Promociones y ofertas',
  novedad: 'Novedades y lanzamientos',
  reactivacion: 'Reactivación de clientes',
  bienvenida: 'Bienvenida',
  confirmacion: 'Confirmaciones',
  recordatorio: 'Recordatorios',
  notificacion: 'Notificaciones y avisos',
  pago: 'Pagos y facturación',
  cobranza: 'Cobranza',
  seguimiento: 'Seguimiento de pedidos y casos',
  encuesta: 'Encuestas y opinión',
  codigo: 'Códigos de verificación',
};

const BAJA = 'Responde SALIR para no recibir más mensajes';

export const WA_TEMPLATE_LIBRARY: LibTemplate[] = [
  // ------------------------------------------------------- AUTENTICACIÓN --
  {
    id: 'auth_codigo', sector: 'general', use: 'codigo', category: 'AUTHENTICATION',
    name: 'codigo_verificacion', title: 'Código de verificación (con vencimiento)',
    body: '{{1}} es tu código de verificación.', examples: ['482913'],
    auth: { securityRecommendation: true, expirationMinutes: 10, buttonText: 'Copiar código' },
  },
  {
    id: 'auth_codigo_simple', sector: 'finanzas', use: 'codigo', category: 'AUTHENTICATION',
    name: 'codigo_acceso', title: 'Código de acceso / transacción',
    body: '{{1}} es tu código de verificación.', examples: ['705218'],
    auth: { securityRecommendation: true, expirationMinutes: 5, buttonText: 'Copiar código' },
  },

  // ------------------------------------------------------------- GENERAL --
  {
    id: 'gen_bienvenida', sector: 'general', use: 'bienvenida', category: 'MARKETING',
    name: 'bienvenida_cliente', title: 'Bienvenida a nuevos clientes',
    header: '¡Bienvenido!',
    body: 'Hola {{1}}, gracias por elegir {{2}}. Por este canal te contaremos novedades, beneficios y ofertas exclusivas para ti. Si tienes alguna pregunta, escríbenos aquí mismo.',
    footer: BAJA, examples: ['Ana', 'Tienda Central'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Quiero saber más' }],
  },
  {
    id: 'gen_confirmacion_registro', sector: 'general', use: 'confirmacion', category: 'UTILITY',
    name: 'confirmacion_registro', title: 'Confirmación de registro',
    body: 'Hola {{1}}, tu registro en {{2}} quedó confirmado. Tu número de cliente es {{3}}. Guarda este mensaje para futuras consultas.',
    examples: ['Carlos', 'Club Vozip', 'CL-20931'],
  },
  {
    id: 'gen_caso', sector: 'general', use: 'seguimiento', category: 'UTILITY',
    name: 'actualizacion_caso', title: 'Actualización de un caso o solicitud',
    body: 'Hola {{1}}, tu solicitud número {{2}} cambió de estado a: {{3}}. Si necesitas más información, responde a este mensaje y un asesor te atenderá.',
    examples: ['María', '45021', 'En revisión'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Hablar con un asesor' }],
  },
  {
    id: 'gen_encuesta', sector: 'general', use: 'encuesta', category: 'MARKETING',
    name: 'encuesta_satisfaccion', title: 'Encuesta de satisfacción',
    body: 'Hola {{1}}, queremos mejorar para ti. ¿Cómo calificas la atención que recibiste de {{2}}? Elige una opción, solo te tomará un segundo.',
    footer: BAJA, examples: ['Laura', 'nuestro equipo'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Excelente' }, { type: 'QUICK_REPLY', text: 'Buena' }, { type: 'QUICK_REPLY', text: 'Por mejorar' }],
  },
  {
    id: 'gen_reactivacion', sector: 'general', use: 'reactivacion', category: 'MARKETING',
    name: 'te_extranamos', title: 'Reactivación: te extrañamos',
    body: 'Hola {{1}}, hace tiempo no sabemos de ti y te extrañamos. Por volver, tienes un {{2}} de descuento en tu próxima compra usando el código {{3}} hasta fin de mes.',
    footer: BAJA, examples: ['Pedro', '15 %', 'VUELVE15'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Ver beneficios' }],
  },
  {
    id: 'gen_horario', sector: 'general', use: 'notificacion', category: 'UTILITY',
    name: 'cambio_horario_atencion', title: 'Cambio de horario de atención (clientes con cuenta)',
    body: 'Hola {{1}}, te informamos que a partir del {{2}} nuestro horario de atención será {{3}}. Tus servicios activos no tienen ningún cambio.',
    examples: ['Andrés', '1 de noviembre', 'de lunes a viernes de 8 a. m. a 6 p. m.'],
  },

  // ------------------------------------------------------------ COMERCIO --
  {
    id: 'com_promo', sector: 'comercio', use: 'promocion', category: 'MARKETING',
    name: 'promocion_temporada', title: 'Promoción de temporada',
    header: 'Ofertas de temporada',
    body: 'Hola {{1}}, llegó nuestra temporada de descuentos: hasta {{2}} en productos seleccionados. La promoción es válida hasta el {{3}} o hasta agotar existencias.',
    footer: BAJA, examples: ['Valentina', '40 %', '30 de noviembre'],
    buttons: [{ type: 'URL', text: 'Ver ofertas', url: 'https://www.tutienda.com/ofertas' }],
  },
  {
    id: 'com_lanzamiento', sector: 'comercio', use: 'novedad', category: 'MARKETING',
    name: 'nuevo_producto', title: 'Lanzamiento de producto',
    body: 'Hola {{1}}, ya está disponible {{2}}. Como cliente frecuente, puedes reservarlo antes que nadie con envío gratis hasta el {{3}}. Responde a este mensaje para separarlo.',
    footer: BAJA, examples: ['Sofía', 'la nueva colección de invierno', '15 de diciembre'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Quiero reservar' }],
  },
  {
    id: 'com_carrito', sector: 'comercio', use: 'reactivacion', category: 'MARKETING',
    name: 'carrito_abandonado', title: 'Carrito abandonado',
    body: 'Hola {{1}}, dejaste productos en tu carrito. Los guardamos para ti por 48 horas y, si completas tu compra hoy, te regalamos el envío con el código {{2}} en el pago.',
    footer: BAJA, examples: ['Daniel', 'ENVIOGRATIS'],
    buttons: [{ type: 'URL', text: 'Terminar compra', url: 'https://www.tutienda.com/carrito' }],
  },
  {
    id: 'com_pedido', sector: 'comercio', use: 'confirmacion', category: 'UTILITY',
    name: 'confirmacion_pedido', title: 'Confirmación de pedido',
    body: 'Hola {{1}}, recibimos tu pedido número {{2}} por un valor de {{3}}. Te avisaremos por este medio cuando sea despachado.',
    examples: ['Camila', 'P-10293', '$185.000'],
  },
  {
    id: 'com_despacho', sector: 'comercio', use: 'seguimiento', category: 'UTILITY',
    name: 'pedido_despachado', title: 'Pedido despachado',
    body: 'Hola {{1}}, tu pedido {{2}} ya va en camino con la transportadora {{3}}. El número de guía es {{4}} y la entrega estimada es en 2 a 3 días hábiles.',
    examples: ['Julián', 'P-10293', 'Servientrega', '9081726354'],
  },

  // --------------------------------------------------------------- SALUD --
  {
    id: 'sal_cita', sector: 'salud', use: 'recordatorio', category: 'UTILITY',
    name: 'recordatorio_cita_medica', title: 'Recordatorio de cita médica',
    body: 'Hola {{1}}, te recordamos tu cita de {{2}} el {{3}} a las {{4}} en nuestra sede. Por favor llega 15 minutos antes con tu documento de identidad.',
    examples: ['Rosa', 'medicina general', '12 de noviembre', '9:30 a. m.'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Confirmo asistencia' }, { type: 'QUICK_REPLY', text: 'Necesito reprogramar' }],
  },
  {
    id: 'sal_resultados', sector: 'salud', use: 'notificacion', category: 'UTILITY',
    name: 'resultados_disponibles', title: 'Resultados de laboratorio disponibles',
    body: 'Hola {{1}}, los resultados de tu examen de {{2}} ya están disponibles. Puedes consultarlos en nuestro portal con tu número de documento.',
    examples: ['Jorge', 'laboratorio clínico'],
    buttons: [{ type: 'URL', text: 'Ver resultados', url: 'https://www.tuclinica.com/resultados' }],
  },
  {
    id: 'sal_vacunacion', sector: 'salud', use: 'promocion', category: 'MARKETING',
    name: 'jornada_salud', title: 'Jornada de salud / campaña de prevención',
    body: 'Hola {{1}}, este {{2}} tendremos jornada de {{3}} con descuento especial para nuestros pacientes. Agenda tu espacio respondiendo a este mensaje.',
    footer: BAJA, examples: ['Marta', 'sábado 18', 'chequeo preventivo'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Quiero agendar' }],
  },

  // ----------------------------------------------------------- EDUCACIÓN --
  {
    id: 'edu_matriculas', sector: 'educacion', use: 'promocion', category: 'MARKETING',
    name: 'inscripciones_abiertas', title: 'Inscripciones abiertas',
    header: 'Inscripciones abiertas',
    body: 'Hola {{1}}, ya están abiertas las inscripciones para {{2}}. Si te inscribes antes del {{3}} obtienes un descuento especial en la matrícula.',
    footer: BAJA, examples: ['Felipe', 'el semestre 2027-1', '20 de diciembre'],
    buttons: [{ type: 'URL', text: 'Inscribirme', url: 'https://www.tuinstitucion.edu.co/inscripciones' }],
  },
  {
    id: 'edu_clase', sector: 'educacion', use: 'recordatorio', category: 'UTILITY',
    name: 'recordatorio_clase', title: 'Recordatorio de clase o evento académico',
    body: 'Hola {{1}}, te recordamos que tu clase de {{2}} es el {{3}} a las {{4}}. Si no puedes asistir, avísanos respondiendo a este mensaje.',
    examples: ['Isabella', 'inglés intermedio', 'martes 14', '6:00 p. m.'],
  },
  {
    id: 'edu_pension', sector: 'educacion', use: 'pago', category: 'UTILITY',
    name: 'pension_por_vencer', title: 'Pago de pensión por vencer',
    body: 'Hola {{1}}, la pensión de {{2}} correspondiente a {{3}} vence el {{4}}. Puedes pagarla en línea o en nuestras oficinas.',
    examples: ['señora Gómez', 'Santiago Gómez', 'noviembre', '5 de noviembre'],
    buttons: [{ type: 'URL', text: 'Pagar en línea', url: 'https://www.tuinstitucion.edu.co/pagos' }],
  },

  // ------------------------------------------------------------ FINANZAS --
  {
    id: 'fin_movimiento', sector: 'finanzas', use: 'notificacion', category: 'UTILITY',
    name: 'alerta_movimiento', title: 'Alerta de movimiento en la cuenta',
    body: 'Hola {{1}}, se registró una compra por {{2}} con tu tarjeta terminada en {{3}} el {{4}}. Si no reconoces esta transacción, comunícate con nosotros de inmediato.',
    examples: ['Ricardo', '$250.000', '4821', '10/11/2026 a las 3:15 p. m.'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Sí la reconozco' }, { type: 'QUICK_REPLY', text: 'No la reconozco' }],
  },
  {
    id: 'fin_credito_aprobado', sector: 'finanzas', use: 'notificacion', category: 'UTILITY',
    name: 'solicitud_credito_aprobada', title: 'Solicitud de crédito aprobada',
    body: 'Hola {{1}}, tu solicitud de crédito número {{2}} fue aprobada por {{3}}. Un asesor se comunicará contigo para el desembolso.',
    examples: ['Paula', 'SC-88201', '$5.000.000'],
  },
  {
    id: 'fin_oferta', sector: 'finanzas', use: 'promocion', category: 'MARKETING',
    name: 'oferta_credito', title: 'Oferta de crédito preaprobado',
    body: 'Hola {{1}}, tienes un crédito preaprobado de hasta {{2}} con tasa preferencial. La oferta está vigente hasta el {{3}}, sin trámites adicionales.',
    footer: BAJA, examples: ['Esteban', '$10.000.000', '30 de noviembre'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Me interesa' }],
  },

  // ------------------------------------------------------------ COBRANZA --
  {
    id: 'cob_preventivo', sector: 'cobranza', use: 'cobranza', category: 'UTILITY',
    name: 'recordatorio_pago_preventivo', title: 'Recordatorio preventivo (antes del vencimiento)',
    body: 'Hola {{1}}, te recordamos que tu cuota de {{2}} por valor de {{3}} vence el {{4}}. Pagar a tiempo evita intereses de mora.',
    examples: ['Luis', 'tu crédito 30291', '$320.000', '5 de noviembre'],
    buttons: [{ type: 'URL', text: 'Pagar ahora', url: 'https://www.tuempresa.com/pagos' }],
  },
  {
    id: 'cob_vencido', sector: 'cobranza', use: 'cobranza', category: 'UTILITY',
    name: 'pago_vencido', title: 'Pago vencido',
    body: 'Hola {{1}}, tu obligación {{2}} presenta un saldo vencido de {{3}} desde el {{4}}. Ponte al día para evitar reportes y cargos adicionales.',
    examples: ['Andrea', 'número 30291', '$320.000', '5 de noviembre'],
    buttons: [{ type: 'URL', text: 'Pagar ahora', url: 'https://www.tuempresa.com/pagos' }, { type: 'QUICK_REPLY', text: 'Ya pagué' }, { type: 'QUICK_REPLY', text: 'Necesito un acuerdo' }],
  },
  {
    id: 'cob_acuerdo', sector: 'cobranza', use: 'cobranza', category: 'UTILITY',
    name: 'confirmacion_acuerdo_pago', title: 'Confirmación de acuerdo de pago',
    body: 'Hola {{1}}, confirmamos tu acuerdo de pago para la obligación {{2}}: {{3}} cuotas de {{4}}, la primera el {{5}}. Gracias por ponerte al día.',
    examples: ['Mauricio', '30291', '3', '$110.000', '15 de noviembre'],
  },
  {
    id: 'cob_pago_recibido', sector: 'cobranza', use: 'pago', category: 'UTILITY',
    name: 'pago_recibido', title: 'Pago recibido',
    body: 'Hola {{1}}, recibimos tu pago por {{2}} el {{3}}. Tu saldo pendiente es {{4}}. Gracias por tu cumplimiento.',
    examples: ['Natalia', '$320.000', '4 de noviembre', '$0'],
  },

  // --------------------------------------------- SERVICIOS Y TELECOM --
  {
    id: 'ser_factura', sector: 'servicios', use: 'pago', category: 'UTILITY',
    name: 'factura_disponible', title: 'Factura disponible',
    body: 'Hola {{1}}, tu factura del servicio de {{2}} del periodo {{3}} ya está disponible por un valor de {{4}}. La fecha límite de pago es el {{5}}. Paga a tiempo y evita la suspensión del servicio.',
    examples: ['Gustavo', 'internet hogar', 'octubre', '$89.900', '15 de noviembre'],
    buttons: [{ type: 'URL', text: 'Ver y pagar', url: 'https://www.tuempresa.com/factura' }],
  },
  {
    id: 'ser_mantenimiento', sector: 'servicios', use: 'notificacion', category: 'UTILITY',
    name: 'mantenimiento_programado', title: 'Mantenimiento o corte programado',
    body: 'Hola {{1}}, el {{2}} entre las {{3}} haremos un mantenimiento programado que puede afectar tu servicio de {{4}} en tu zona. Gracias por tu comprensión.',
    examples: ['Beatriz', 'jueves 13', '1:00 y 4:00 a. m.', 'internet'],
  },
  {
    id: 'ser_upgrade', sector: 'servicios', use: 'promocion', category: 'MARKETING',
    name: 'mejora_plan', title: 'Mejora de plan',
    body: 'Hola {{1}}, por ser cliente fiel puedes pasar a {{2}} por solo {{3}} más al mes, durante los primeros 6 meses. Activa el beneficio respondiendo a este mensaje.',
    footer: BAJA, examples: ['Hernán', '500 megas', '$10.000'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Quiero mejorar mi plan' }],
  },

  // -------------------------------------------------------- INMOBILIARIA --
  {
    id: 'inm_visita', sector: 'inmobiliaria', use: 'recordatorio', category: 'UTILITY',
    name: 'recordatorio_visita_inmueble', title: 'Recordatorio de visita a inmueble',
    body: 'Hola {{1}}, te esperamos el {{2}} a las {{3}} para la visita al inmueble ubicado en {{4}}. Nuestro asesor estará en el lugar.',
    examples: ['Ximena', 'viernes 15', '11:00 a. m.', 'Calle 100 # 15-20'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Confirmo' }, { type: 'QUICK_REPLY', text: 'Reprogramar' }],
  },
  {
    id: 'inm_proyecto', sector: 'inmobiliaria', use: 'novedad', category: 'MARKETING',
    name: 'lanzamiento_proyecto', title: 'Lanzamiento de proyecto',
    header: 'Nuevo proyecto',
    body: 'Hola {{1}}, lanzamos {{2}} con apartamentos desde {{3}} y precios especiales de preventa. Agenda una visita a la sala de ventas.',
    footer: BAJA, examples: ['Óscar', 'Torres del Parque', '$280 millones'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Agendar visita' }],
  },
  {
    id: 'inm_canon', sector: 'inmobiliaria', use: 'pago', category: 'UTILITY',
    name: 'recordatorio_canon', title: 'Recordatorio de canon de arriendo',
    body: 'Hola {{1}}, te recordamos que el canon de arriendo del inmueble {{2}} por {{3}} vence el {{4}}. Puedes pagar por transferencia o en nuestras oficinas.',
    examples: ['Diana', 'Apto 502', '$1.800.000', '5 de noviembre'],
  },

  // ----------------------------------------------------------- LOGÍSTICA --
  {
    id: 'log_entrega_hoy', sector: 'logistica', use: 'seguimiento', category: 'UTILITY',
    name: 'entrega_hoy', title: 'Entrega programada para hoy',
    body: 'Hola {{1}}, tu envío con guía {{2}} será entregado hoy entre las {{3}}. Asegúrate de que alguien pueda recibirlo en la dirección registrada.',
    examples: ['Sebastián', '9081726354', '2:00 y 6:00 p. m.'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Estaré disponible' }, { type: 'QUICK_REPLY', text: 'Cambiar dirección' }],
  },
  {
    id: 'log_novedad', sector: 'logistica', use: 'notificacion', category: 'UTILITY',
    name: 'novedad_envio', title: 'Novedad en el envío',
    body: 'Hola {{1}}, no pudimos entregar tu envío {{2}} por el siguiente motivo: {{3}}. Responde a este mensaje para coordinar una nueva entrega.',
    examples: ['Lucía', '9081726354', 'no había nadie en la dirección'],
  },

  // -------------------------------------------------------- RESTAURANTES --
  {
    id: 'res_reserva', sector: 'restaurantes', use: 'confirmacion', category: 'UTILITY',
    name: 'confirmacion_reserva_mesa', title: 'Confirmación de reserva',
    body: 'Hola {{1}}, confirmamos tu reserva para {{2}} personas el {{3}} a las {{4}}. Si cambias de planes, avísanos respondiendo a este mensaje.',
    examples: ['Tomás', '4', 'sábado 16', '8:00 p. m.'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Confirmo' }, { type: 'QUICK_REPLY', text: 'Cancelar reserva' }],
  },
  {
    id: 'res_pedido_camino', sector: 'restaurantes', use: 'seguimiento', category: 'UTILITY',
    name: 'domicilio_en_camino', title: 'Domicilio en camino',
    body: 'Hola {{1}}, tu pedido {{2}} salió de nuestra cocina y llegará en aproximadamente {{3}} minutos. ¡Buen provecho!',
    examples: ['Mariana', '#4521', '25'],
  },
  {
    id: 'res_promo', sector: 'restaurantes', use: 'promocion', category: 'MARKETING',
    name: 'promo_fin_de_semana', title: 'Promoción de fin de semana',
    body: 'Hola {{1}}, este fin de semana tenemos {{2}} en {{3}}. Pide por este medio o visítanos, te esperamos.',
    footer: BAJA, examples: ['Nicolás', '2x1', 'hamburguesas clásicas'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Quiero pedir' }],
  },

  // ------------------------------------------------------------- TURISMO --
  {
    id: 'tur_reserva', sector: 'turismo', use: 'confirmacion', category: 'UTILITY',
    name: 'confirmacion_reserva_hotel', title: 'Confirmación de reserva de hotel',
    body: 'Hola {{1}}, tu reserva {{2}} está confirmada del {{3}} al {{4}}. El check-in es desde las 3:00 p. m. ¡Te esperamos!',
    examples: ['Alejandra', 'R-77120', '20 de diciembre', '23 de diciembre'],
  },
  {
    id: 'tur_checkin', sector: 'turismo', use: 'recordatorio', category: 'UTILITY',
    name: 'recordatorio_viaje', title: 'Recordatorio de viaje',
    body: 'Hola {{1}}, faltan {{2}} días para tu viaje a {{3}}. Revisa que tengas tus documentos al día y escríbenos si necesitas traslado.',
    examples: ['Gabriel', '3', 'Cartagena'],
  },
  {
    id: 'tur_promo', sector: 'turismo', use: 'promocion', category: 'MARKETING',
    name: 'oferta_vacaciones', title: 'Oferta de vacaciones',
    header: 'Escápate con nosotros',
    body: 'Hola {{1}}, tenemos planes a {{2}} desde {{3}} por persona con hotel y desayuno incluidos. Cupos limitados hasta el {{4}}, ¡reserva el tuyo!',
    footer: BAJA, examples: ['Juliana', 'San Andrés', '$1.290.000', '30 de noviembre'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Quiero cotizar' }],
  },

  // ------------------------------------------------------------- SEGUROS --
  {
    id: 'seg_renovacion', sector: 'seguros', use: 'recordatorio', category: 'UTILITY',
    name: 'renovacion_poliza', title: 'Renovación de póliza',
    body: 'Hola {{1}}, tu póliza de {{2}} número {{3}} vence el {{4}}. Renuévala a tiempo para mantener tu protección sin interrupciones.',
    examples: ['Fernando', 'seguro de auto', 'POL-55120', '30 de noviembre'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Quiero renovar' }],
  },
  {
    id: 'seg_siniestro', sector: 'seguros', use: 'seguimiento', category: 'UTILITY',
    name: 'estado_reclamacion', title: 'Estado de la reclamación',
    body: 'Hola {{1}}, tu reclamación número {{2}} está en estado: {{3}}. Te informaremos cualquier novedad por este medio.',
    examples: ['Carolina', 'RC-1203', 'aprobada para pago'],
  },

  // ---------------------------------------------------------- AUTOMOTRIZ --
  {
    id: 'aut_mantenimiento', sector: 'automotriz', use: 'recordatorio', category: 'UTILITY',
    name: 'recordatorio_mantenimiento', title: 'Recordatorio de mantenimiento',
    body: 'Hola {{1}}, tu vehículo de placa {{2}} tiene programado el mantenimiento de {{3}} el {{4}}. Responde para confirmar o cambiar la cita.',
    examples: ['Ernesto', 'ABC123', '10.000 km', 'lunes 18'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Confirmo' }, { type: 'QUICK_REPLY', text: 'Cambiar cita' }],
  },
  {
    id: 'aut_listo', sector: 'automotriz', use: 'notificacion', category: 'UTILITY',
    name: 'vehiculo_listo', title: 'Vehículo listo para recoger',
    body: 'Hola {{1}}, tu vehículo de placa {{2}} ya está listo. El valor del servicio es {{3}} y puedes recogerlo hasta las 6:00 p. m.',
    examples: ['Ernesto', 'ABC123', '$450.000'],
  },

  // ------------------------------------------------------------- BELLEZA --
  {
    id: 'bel_cita', sector: 'belleza', use: 'recordatorio', category: 'UTILITY',
    name: 'recordatorio_cita_salon', title: 'Recordatorio de cita',
    body: 'Hola {{1}}, te esperamos el {{2}} a las {{3}} para tu servicio de {{4}}. Si no puedes asistir, avísanos con anticipación.',
    examples: ['Paola', 'jueves 14', '4:00 p. m.', 'manicure'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Confirmo' }, { type: 'QUICK_REPLY', text: 'Reprogramar' }],
  },
  {
    id: 'bel_cumple', sector: 'belleza', use: 'promocion', category: 'MARKETING',
    name: 'regalo_cumpleanos', title: 'Regalo de cumpleaños',
    header: '¡Feliz cumpleaños!',
    body: 'Hola {{1}}, en tu mes de cumpleaños te regalamos {{2}} en cualquiera de nuestros servicios. Agenda tu cita antes del {{3}} y disfruta tu regalo.',
    footer: BAJA, examples: ['Daniela', '20 % de descuento', '30 de noviembre'],
    buttons: [{ type: 'QUICK_REPLY', text: 'Agendar' }],
  },
];
