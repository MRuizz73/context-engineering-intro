# 📘 Guía de instalación — Gestión de Flota en vuestro hosting

Aplicación: **versión PHP** (carpeta `gestion_flota_php/` del repositorio).
Requisitos del hosting (los cumple cualquier hosting normal):

| Requisito | Detalle |
|---|---|
| PHP | 8.0 o superior, con `pdo_mysql` y `openssl` (vienen de serie) |
| Base de datos | MariaDB 10.11 (la vuestra) o MySQL |
| Apache | con `.htaccess` habilitado (lo normal en cPanel/Plesk) |
| HTTPS | recomendado; en cPanel se activa gratis con *Let's Encrypt / AutoSSL* |

Tiempo estimado: **20–30 minutos**.

---

## Paso 1 — Crear la base de datos (5 min)

En cPanel, entra en **"Bases de datos MySQL®"** (así se llama el menú
aunque el motor sea MariaDB — es el sitio correcto):

1. **Crear base de datos**: nombre `gestion_flota`. cPanel le añadirá tu
   prefijo, quedará algo como `miusuario_gestion_flota`. **Apúntalo.**
2. **Crear usuario**: por ejemplo `flota`, con una contraseña fuerte
   (quedará `miusuario_flota`). **Apunta usuario y contraseña.**
3. Abajo, en **"Añadir usuario a la base de datos"**: añade ese usuario a
   esa base y marca **TODOS LOS PRIVILEGIOS** → Guardar.

> Si en lugar de cPanel tenéis Plesk, es igual: *Bases de datos → Añadir
> base de datos* y crear el usuario en la misma pantalla.

## Paso 2 — Subir los archivos (5 min)

1. Descarga la carpeta `gestion_flota_php/` del repositorio
   (en GitHub: Code → Download ZIP, y dentro del ZIP está la carpeta).
2. En cPanel → **Administrador de archivos** → `public_html/` → crea la
   carpeta `flota` y sube ahí **todo el contenido** de `gestion_flota_php/`
   (también vale por FTP con FileZilla).
3. Importante: activa **"Mostrar archivos ocultos"** (Configuración del
   Administrador de archivos) y comprueba que el archivo **`.htaccess`**
   está subido. Sin él, la API no funciona y los CSV quedarían legibles.

Estructura que debe quedar:

```
public_html/flota/
├── .htaccess
├── index.php
├── api.php
├── cron.php
├── config.example.php
├── lib/            (5 archivos .php)
├── static/         (index.html, app.js, auth.js, perfil.js, styles.css)
├── datos_iniciales.csv
└── cuentas_iniciales.csv
```

## Paso 3 — Configurar (5 min)

En el Administrador de archivos, **copia** `config.example.php` y renómbrala
a **`config.php`**. Edítala (clic derecho → Edit) y completa:

```php
'db' => [
    // NO cambies el prefijo "mysql:" — es el nombre del driver de PHP
    // y es el correcto también para MariaDB 10.11.
    'dsn'      => 'mysql:host=localhost;dbname=miusuario_gestion_flota;charset=utf8mb4',
    'usuario'  => 'miusuario_flota',
    'password' => 'la-clave-del-paso-1',
],
```

**Correo de la empresa** (para los recordatorios) — con **IONOS**:

```php
'smtp' => [
    'host'     => 'smtp.ionos.es',   // España; también smtp.ionos.com / .de
    'puerto'   => 587,               // 587 con TLS (también vale 465 SSL)
    'usuario'  => 'avisos@tudominio.com',
    'password' => 'clave-del-buzon',
    'tls'      => true,
],
```

Los 3 errores típicos con IONOS:
1. `usuario` debe ser el **buzón completo** (`algo@tudominio.com`), no un
   alias ni el nombre a secas.
2. `password` es la **contraseña del buzón de correo**, no la de la cuenta
   de cliente de IONOS.
3. El buzón debe existir en IONOS (Correo → crear buzón) — no vale una
   simple redirección.

Tras configurar, entra como admin y pulsa **🧪 Probar correo** en el panel
de Vencimientos: llega un email de prueba a la oficina, y si algo falla el
mensaje de error muestra la respuesta exacta del servidor de IONOS.

Alternativa sin SMTP: deja `'host' => ''` y pon
`'email_remitente' => 'avisos@tudominio.com'` para usar la función
`mail()` del hosting (menos fiable, puede caer en spam).

Y el correo de la oficina:

```php
'email_admin' => 'oficina@tudominio.com', // recibe los avisos de camiones
```

El registro desde la web viene **desactivado**: las cuentas las creas tú
desde la app (botón 🔐 al crear cada chófer) y las entregas en mano, así
que no hace falta tocar `registro_abierto` ni los códigos.

## Paso 4 — Primer arranque (1 min)

Abre en el navegador: **`https://tudominio.com/flota/`**

La primera visita lo hace todo sola (tarda unos segundos):
- Crea las tablas en vuestra MariaDB.
- Importa el calendario de la empresa: **15 chóferes, 51 camiones y 234
  cursos/permisos** con sus vencimientos.
- Crea las **16 cuentas** (admin + chóferes) del Excel que os pasé.

Entra con el usuario **`admin`** y su contraseña del Excel y comprueba que
en "Vencimientos" aparecen las alertas.

## Paso 5 — Poner en marcha al equipo (10 min)

1. Reparte a cada chófer su fila del **Excel de cuentas** (usuario +
   contraseña) junto con la dirección `https://tudominio.com/flota/`.
2. Diles que al entrar por primera vez pulsen **🔑 Contraseña** y pongan
   una suya.
3. Como admin, entra al **perfil de cada chófer** y completa su **email**
   (y el DNI real, que está provisional como `PTE-…`): sin email, sus
   recordatorios van al correo de la oficina.
4. En el móvil: abrir la web y "**Añadir a pantalla de inicio**" — queda
   como una app.
5. Cuando todos tengan su cuenta, **borra del servidor**
   `datos_iniciales.csv` y `cuentas_iniciales.csv` (ya cumplieron su
   función; el `.htaccess` impide leerlos por web, pero mejor fuera).

## Paso 6 (opcional) — Cron para los emails

Los recordatorios se envían solos igualmente (la app revisa vencimientos
con las visitas, como máximo cada 12 h, y repite el aviso de cada documento
cada 7 días hasta que se pulsa "✔ Renovado"). Pero si quieres garantía
total aunque nadie abra la web, añade en cPanel → **Trabajos de cron** una
tarea diaria:

```
/usr/local/bin/php /home/MIUSUARIO/public_html/flota/cron.php
```

(la ruta exacta de PHP te la muestra el propio cPanel en esa pantalla).

---

## Si algo falla (los 4 problemas típicos)

| Síntoma | Causa y solución |
|---|---|
| **Error 500 al abrir** | Versión de PHP antigua. cPanel → *Select PHP Version* → elegir **8.1 o superior**. |
| **"Error interno del servidor" al usar la app** | Datos de la base mal en `config.php` (nombre completo con prefijo, usuario, clave) o el usuario sin privilegios (Paso 1.3). |
| **La portada carga pero todo da "Ruta no encontrada"** | No se subió el `.htaccess` (archivos ocultos) o el hosting tiene `mod_rewrite` desactivado (raro; se pide a soporte). |
| **No llegan los emails** | Pulsa "🧪 Probar correo": el mensaje dice exactamente qué respondió el servidor. Con IONOS revisa los 3 errores típicos del Paso 3; con Gmail, recuerda que es contraseña *de aplicación*. |

Cualquier mensaje de error que veas, pásamelo tal cual y te digo qué tocar.

## Checklist final

- [ ] Base y usuario MariaDB creados con todos los privilegios
- [ ] Archivos subidos con `.htaccess` incluido
- [ ] `config.php` con base de datos + correo de IONOS + email_admin
- [ ] Primer arranque OK (login `admin`, alertas visibles)
- [ ] HTTPS activo (candado en el navegador)
- [ ] Emails probados con el botón 📧
- [ ] Contraseñas repartidas y CSV borrados del servidor
- [ ] Emails y DNI reales completados en los perfiles de los chóferes
