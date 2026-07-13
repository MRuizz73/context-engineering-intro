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

## Discovered During Work

- [ ] Recordatorios por WhatsApp además de email y panel web.
