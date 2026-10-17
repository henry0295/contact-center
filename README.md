# Centro de Contacto Multicanal

Sistema integral para gestión de comunicaciones a través de múltiples canales (email, SMS, audio) con capacidades de campañas y reportes.

## 🚀 Características

- Sistema de autenticación y autorización
- Módulo de Email (AWS SES)
- Módulo de SMS (AWS SNS)
- Módulo de Audio/Voz (Asterisk)
- Gestión de campañas multicanal
- Reportes y analíticas
- Plantillas personalizables
- API RESTful

## 💻 Requisitos del Sistema

### Software
- PHP >= 8.2
- Node.js >= 18.x
- MySQL >= 8.0
- Composer
- NPM o Yarn
- Git

### Hardware Recomendado
- CPU: 4 cores
- RAM: 8GB mínimo
- Almacenamiento: 20GB disponibles

### Servicios Cloud
- Cuenta AWS activa
- Credenciales AWS con permisos para:
  - SES (Email)
  - SNS (SMS)
  - Lambda
  - S3

## 🛠 Tecnologías

### Frontend
- React 18 + TypeScript
- Tailwind CSS
- React Router v6
- React Query
- React Hook Form
- Lucide React (iconos)
- React Hot Toast

### Backend
- PHP 8.2
- Slim Framework 4
- AWS SDK PHP
- JWT para autenticación
- MySQL

## ⚙️ Instalación

1. **Clonar repositorio**
```bash
git clone https://github.com/usuario/contact-center.git
cd contact-center
```

2. **Instalar dependencias PHP**
```bash
composer install
```

3. **Instalar dependencias Node**
```bash
npm install
```

4. **Configurar entorno**
```bash
cp .env.example .env
```

5. **Configurar base de datos**
```sql
CREATE DATABASE contact_center;
```

6. **Ejecutar migraciones**
```bash
php artisan migrate
```

## 🚀 Ejecución

### Desarrollo

1. **Backend**
```bash
php -S localhost:8000 -t public
```

2. **Frontend**
```bash
npm run dev
```

3. **Acceder**
```
http://localhost:3000
```

### Producción

1. **Construir frontend**
```bash
npm run build
```

2. **Configurar servidor web**
- Apache o Nginx
- Apuntar a directorio `/public`
- Configurar SSL

## 📁 Estructura del Proyecto

```
├── config/               # Configuraciones
├── public/              # Archivos públicos
├── src/
│   ├── components/      # Componentes React
│   ├── context/         # Contextos React
│   ├── pages/           # Páginas
│   ├── hooks/           # Custom hooks
│   ├── services/        # Servicios API
│   └── utils/           # Utilidades
├── api/
│   ├── Controllers/     # Controladores PHP
│   ├── Models/          # Modelos
│   └── Services/        # Servicios
└── tests/               # Pruebas
```

## 🔐 Seguridad

- Autenticación JWT
- Validación de entradas
- Sanitización de datos
- CORS configurado
- Rate limiting
- Protección XSS
- Encriptación de datos sensibles

## 📝 Variables de Entorno

```env
DB_HOST=localhost
DB_NAME=contact_center
DB_USER=root
DB_PASS=

AWS_ACCESS_KEY=
AWS_SECRET_KEY=
AWS_REGION=

JWT_SECRET=
```

## 🧪 Pruebas

```bash
# Pruebas unitarias
npm run test

# Pruebas e2e
npm run test:e2e
```

## 📚 Documentación API

La documentación de la API está disponible en:
```
http://localhost:8000/docs
```

## 🤝 Contribución

1. Fork el proyecto
2. Crea tu rama (`git checkout -b feature/AmazingFeature`)
3. Commit cambios (`git commit -m 'Add AmazingFeature'`)
4. Push a la rama (`git push origin feature/AmazingFeature`)
5. Abre un Pull Request

## 📄 Licencia

Distribuido bajo la licencia MIT. Ver `LICENSE` para más información.

## 📧 Soporte

Para soporte contactar a: support@contact-center.com