# TASK.md

## Tareas

- [x] **2026-07-13** — App web de gestión de flota (`gestion_flota/`): CRUD de
  choferes y camiones, asignación de cursos/permisos con fecha de vencimiento
  y días de aviso configurables, y panel de recordatorios de renovación.
  Incluye interfaz web en español, API FastAPI + SQLModel (SQLite) y tests
  (17 pasando).

- [x] **2026-07-13** — Rebranding con la identidad de la empresa (Manual de
  Uso iR: verde #85F64B, celeste #36A9E1, degradado verde→celeste, Lato),
  notificaciones de escritorio para caducidades (Notification API, aviso
  una vez por día por documento) y login/registro simple sin verificación
  por email (PBKDF2 + cookie de sesión de 30 días). 22 tests pasando.

- [x] **2026-07-13** — Recordatorios por email al correo de cada chofer
  (SMTP de la empresa vía `.env`, envío automático cada 12 h y manual con
  botón; frecuencia configurable por documento), email obligatorio en el
  perfil del chofer, y botón "✔ Curso/Permiso renovado" que es la única
  forma de quitar la alerta (pide la nueva fecha de vencimiento).
  29 tests pasando; verificado con servidor SMTP local real.

- [x] **2026-07-13** — Importador del CSV real de la empresa
  (`gestion_flota/importar_csv.py` + `datos_iniciales.csv`): crea 15
  choferes, 51 camiones (por matrícula) y 234 documentos, unificando
  variantes de nombres y deduplicando citas repetidas (la próxima fecha va
  al documento, el resto a notas). Siembra automática al primer arranque
  (AUTO_IMPORTAR=0 la desactiva). Perfil del chofer con cuestionario para
  añadir cursos/permisos. 34 tests pasando.

- [x] **2026-07-13** — Roles y despliegue: rol admin (ve todo) vs rol chofer
  (ve/gestiona solo lo suyo); cuentas iniciales generadas (admin + 15
  choferes, Excel entregado); generador de cuentas de chofer para el admin;
  cambio de contraseña propio (botón 🔑); código de registro de empresa
  (CODIGO_REGISTRO/CODIGO_CHOFER); Dockerfile + docker-compose + guía
  DESPLIEGUE.md. 42 tests pasando.

- [x] **2026-07-13** — Soporte MySQL/MariaDB para el servidor de la empresa
  (DATABASE_URL mysql+pymysql, pool_pre_ping, migraciones solo-SQLite),
  verificado contra MariaDB real (seed completo + login + renovación).
  passenger_wsgi.py para hostings cPanel/Passenger y comando de cron
  `python -m gestion_flota.enviar_avisos`. Guía de despliegue ampliada
  con la sección "Servidor propio PHP + MySQL".

- [x] **2026-07-13** — Versión PHP + MySQL completa (`gestion_flota_php/`)
  para el hosting PHP de la empresa (sin Python): misma API JSON y misma
  interfaz, PDO MySQL/SQLite, sesiones PHP con bcrypt, cliente SMTP propio
  o mail() del hosting, recordatorios automáticos disparados por visitas
  (máx. cada 12 h) + cron.php opcional, importación y cuentas iniciales
  automáticas al primer arranque, .htaccess con bloqueo de archivos
  internos. Verificada en navegador contra MariaDB real (misma paridad:
  emails, roles, renovar, generador de cuentas, cuestionario).
- [x] **2026-07-13** — Todo el lenguaje de la interfaz, emails y mensajes
  pasado a español de España/canario (sin voseo; "chófer", "matrícula").
  Excel de cuentas regenerado con instrucciones es-ES.

- [x] **2026-07-13** — Confirmada compatibilidad con MariaDB 10.11 (el motor
  del servidor de la empresa): la verificación completa se hizo contra
  MariaDB 10.11.14 real; documentación y config.example.php actualizados
  para hablar de MariaDB (el prefijo PDO "mysql:" es el driver correcto
  también para MariaDB).

- [x] **2026-07-13** — Tutorial de uso en PDF (17 páginas, con capturas
  reales de cada pantalla, es-ES): acceso y registro, panel de
  vencimientos, renovaciones, chóferes y perfil con cuestionario, alta con
  generador de cuenta, camiones, avisos automáticos, vista del chófer,
  cambio de contraseña y FAQ. `gestion_flota_php/Tutorial_Gestion_Flota.pdf`.

- [x] **2026-07-14** — Mejoras pedidas tras el estreno: SMTP compatible con
  IONOS (465 SSL directo + guía con los 3 errores típicos) y botón "🧪
  Probar correo"; botón "📧 Avisar" por documento (alerta y tablas);
  perfil con cursos activos separados de pendientes; camiones visibles en
  solo lectura para los chóferes; registro web desactivado (solo login con
  cuentas creadas por el admin). Tutorial PDF regenerado.

- [x] **2026-07-14** — Envío de emails migrado a PHPMailer 6.8.1 oficial
  (incluido en lib/phpmailer/, licencia LGPL): ENCRYPTION_STARTTLS en 587
  y ENCRYPTION_SMTPS en 465, mensajes de error en español, idioma es.
  Verificado envío real (prueba + recordatorio individual) vía SMTP local.

- [x] **2026-07-14** — Interfaz optimizada para móvil (donde la usan los
  chóferes): pestañas deslizables, tarjetas y botones de tamaño táctil,
  formularios a una columna con fuente 16px (sin zoom de iOS), tablas con
  desplazamiento lateral dentro de la tarjeta, modales como hoja inferior,
  icono de app (apple-touch-icon) y theme-color. Verificado en viewport
  390×844 sin desbordamiento horizontal (0 px).

- [x] **2026-07-14** — Buscador con lupa en Chóferes y Camiones: filtra al
  instante mientras se escribe, sin distinguir tildes ni mayúsculas, por
  nombre/DNI/email/teléfono y matrícula/marca/modelo, e incluso por nombre
  de curso o permiso; mensaje de "sin resultados" con la consulta.
  Verificado en escritorio y móvil (16→1 por nombre, 51→1 por matrícula,
  búsqueda por curso "cap conductor" → 8 chóferes).

- [x] **2026-07-14** — Arreglo del corte de tablas en móvil (solo CSS, sin
  tocar código): en pantallas ≤700px cada fila de las tablas de documentos
  se muestra como mini-tarjeta apilada con etiquetas (Tipo/Vence/Aviso),
  estado y botones a ancho completo. Verificado en 390×844 (iPhone 12 Pro)
  y 412×914: 0 px de desbordamiento y 0 tablas cortadas.

- [x] **2026-07-14** — Buscador con sugerencias táctiles (buscador.js):
  al escribir se despliegan sugerencias (👷 chóferes con DNI, 🚛 matrículas
  con marca/modelo, máx. 8) más la fila "🔍 Buscar «texto»"; la búsqueda
  solo se aplica al tocar una sugerencia o pulsar Enter/tecla buscar del
  móvil. Flechas+Enter en escritorio, Esc y toque fuera cierran, vaciar
  restaura el listado. 34 comprobaciones pasadas en escritorio, 390×844 y
  412×914.

- [x] **2026-07-15** — Módulo de TURISMOS (coches de empresa) para auditorías,
  100% aditivo (nuevos `lib/turismos.php`, `static/turismos.js`,
  `turismos_admin.js`, `turismos.css`; sin tocar el código existente):
  rol nuevo 'vehiculos' que SOLO ve la sección de turismos; solicitud de
  coche tocando la tarjeta (nombre, teléfono y motivo obligatorios) con
  ubicación GPS del navegador, contrato de cesión con los datos rellenos y
  firma con el dedo (canvas, archivada como PNG con IP y user-agent);
  devolución con GPS desde la app o desde el enlace directo del email;
  aviso automático por email a los 7 días sin devolver ("¿Devolviste el
  coche y no lo pusiste en la app? Ponlo aquí:" + enlace con token);
  botón "👥 Crear usuario de vehículos" (nombre + email) para el admin;
  registro de auditoría filtrable (estado/mes) y exportable a CSV con
  ubicaciones en el mapa y contrato firmado visible; alta/edición de
  vehículos y siembra de los 51 turismos reales del listado (estados
  mantenimiento/fuera de flota detectados). Interfaz enfocada a móvil
  (tarjetas táctiles a 2 columnas, hoja inferior, canvas de firma).
  Verificado contra MariaDB 10.11 real + Playwright: 26 comprobaciones
  (escritorio, 390×844 y 412×914, 0 px de desbordamiento) y email de los
  7 días recibido en SMTP local con enlace funcional.

- [x] **2026-07-15** — Turismos: los datos personales viajan con la cuenta.
  "Crear usuario de vehículos" ahora guarda nombre, DNI, teléfono y email;
  al solicitar un coche ya no se le vuelven a pedir (solo el motivo, con un
  resumen "Solicitas como…"), el servidor toma los datos de la cuenta (no
  se pueden falsear desde el navegador) y el contrato se archiva con nombre,
  DNI y email rellenos. Migración suave de columnas (ALTER idempotente).
  Verificado por API y Playwright en móvil 390×844.

## Discovered During Work

- [ ] Recordatorios por WhatsApp además de email y panel web.
