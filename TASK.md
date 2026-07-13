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

## Discovered During Work

- [ ] Envío de recordatorios por email/WhatsApp además del panel web
  (requiere integrar un proveedor de correo o mensajería).
