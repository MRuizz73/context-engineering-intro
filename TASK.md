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

## Discovered During Work

- [ ] Recordatorios por WhatsApp además de email y panel web.
