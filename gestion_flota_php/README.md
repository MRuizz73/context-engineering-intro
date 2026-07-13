# ⛽ Gestión de Flota — versión PHP + MySQL

La misma aplicación de cursos y permisos de chóferes y camiones, reescrita
en **PHP** (sin frameworks ni dependencias) para funcionar en cualquier
hosting PHP con **MySQL**: cPanel, Plesk o un servidor LAMP propio.
Requiere PHP 8.0+ con las extensiones `pdo_mysql` y `openssl` (estándar en
todos los hostings).

Idéntica a la versión Python: login con roles (responsable ve todo, cada
chófer solo lo suyo), cuentas iniciales del Excel, generador de cuentas,
recordatorios por email repetidos hasta renovar, botón "✔ Renovado",
perfil con cuestionario, notificaciones de escritorio e importación
automática del calendario de la empresa.

## Instalación en el hosting (paso a paso)

1. **Crear la base de datos** (cPanel → *Bases de datos MySQL*):
   crea la base `gestion_flota`, un usuario y dale **todos los permisos**.
   Apunta el nombre completo (suele quedar `usuario_gestion_flota`).

2. **Subir esta carpeta** al hosting (Administrador de archivos o FTP):
   todo el contenido de `gestion_flota_php/` dentro de `public_html/flota/`
   (o el subdominio que prefieras). Comprueba que `.htaccess` se subió
   (activa "mostrar archivos ocultos").

3. **Configurar**: copia `config.example.php` como `config.php` y completa:
   - `db`: dsn con el nombre real de la base, usuario y contraseña MySQL.
   - `smtp`: el correo de la empresa (o deja `host` vacío y pon
     `email_remitente` para usar el correo del propio hosting).
   - `codigo_registro`: el código secreto para crear cuentas nuevas.

4. **Abrir la app**: entra en `https://tudominio.com/flota/`. En la primera
   visita se crean las tablas en MySQL, se importa el calendario de cursos
   (15 chóferes, 51 camiones, 234 documentos) y se crean las cuentas del
   Excel. Entra como `admin` con su contraseña.

5. **Borrar** `datos_iniciales.csv` y `cuentas_iniciales.csv` del servidor
   una vez repartidas las contraseñas (el `.htaccess` ya bloquea su lectura
   por web, pero mejor fuera).

6. **Cron opcional** (cPanel → *Trabajos de cron*, una vez al día):
   `/usr/bin/php /home/USUARIO/public_html/flota/cron.php`
   Los emails automáticos funcionan igualmente sin cron (la app revisa los
   vencimientos con las visitas, como máximo cada 12 h), pero el cron los
   garantiza aunque nadie abra la web.

## Estructura

```
gestion_flota_php/
├── index.php            # Sirve la interfaz
├── api.php              # API JSON (misma interfaz que la versión Python)
├── cron.php             # Envío de recordatorios para el cron del hosting
├── config.example.php   # Plantilla de configuración → copiar a config.php
├── .htaccess            # Rutas /api/* y bloqueo de archivos internos
├── lib/
│   ├── db.php           # Conexión PDO, esquema y siembra inicial
│   ├── auth.php         # Sesiones, roles, registro, cuentas generadas
│   ├── datos.php        # Chóferes, camiones y documentos
│   ├── correo.php       # SMTP propio o mail() + recordatorios repetidos
│   └── importar.php     # Importador del CSV del calendario
├── static/              # Interfaz web (la misma de la versión Python)
├── datos_iniciales.csv  # Calendario de la empresa (borrar tras instalar)
└── cuentas_iniciales.csv# Cuentas del Excel (borrar tras instalar)
```

## Desarrollo local

```bash
php -S 127.0.0.1:8080 router.php   # desde gestion_flota_php/
```
Con variables `GF_DSN`, `GF_DB_USUARIO`, `GF_DB_PASSWORD` se puede apuntar
a cualquier base (incluso `sqlite:` para pruebas).
