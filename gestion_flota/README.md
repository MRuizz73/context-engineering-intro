# ⛽ Gestión de Flota — Cursos y Permisos

Aplicación web para empresas de transporte de combustibles: administra los
**choferes**, los **camiones** y todos sus **cursos, permisos y habilitaciones**,
con **recordatorios configurables** para las renovaciones.

La interfaz usa la identidad visual de la empresa (Manual de Uso iR): verde
`#85F64B`, celeste `#36A9E1`, degradado de verde a celeste y tipografía Lato.

## Funcionalidades

- **Login con roles**: las cuentas de **responsable (admin)** ven y editan
  todo; las cuentas de **chofer** ven solo sus propios cursos, alertas y
  perfil (y pueden añadir cursos y confirmar renovaciones de lo suyo).
  Sesión de 30 días; contraseña cambiable con el botón 🔑.
- **Cuentas de choferes**: el admin puede generar la cuenta de cada chofer
  con el botón *🔐 Cuenta* (o al crear el chofer): usuario y contraseña
  seguros que se entregan al chofer una sola vez. En el primer arranque se
  crean las cuentas de `gestion_flota/cuentas_iniciales.csv` (borrar ese
  archivo tras repartir las claves). Alternativa: los choferes se registran
  solos con el código de la empresa + su email de perfil.
- **Notificaciones de escritorio**: con el botón *🔔 Activar avisos* el
  navegador pide permiso y luego avisa en el ordenador cuando hay cursos o
  permisos vencidos o por vencer (revisa cada hora y avisa una vez por día
  por documento).
- **Choferes**: alta, edición y baja, con DNI único, teléfono y email.
- **Perfil del chofer**: haciendo clic en un chofer (o en *👤 Ver perfil*) se
  abre su perfil con sus datos para completar (email de recordatorios) y un
  **cuestionario para añadir cursos/permisos** nuevos a ese perfil.
- **Datos iniciales**: al arrancar por primera vez con la base vacía, la app
  importa automáticamente `gestion_flota/datos_iniciales.csv` (calendario de
  cursos y vencimientos de la empresa): crea los choferes, los camiones por
  matrícula y todos los documentos. También se puede importar a mano:
  `python -m gestion_flota.importar_csv archivo.csv` (desactivable con
  `AUTO_IMPORTAR=0`). Los choferes importados quedan con DNI provisorio
  (`PTE-…`) y sin email: completalos desde su perfil.
- **Camiones**: alta, edición y baja, con patente única, marca, modelo y año.
- **Documentos** (cursos, permisos, licencias, seguros, revisión técnica):
  se asignan a un chofer o a un camión, con fecha de vencimiento y una
  ventana de aviso configurable (`dias_aviso`).
- **Recordatorios**: la pestaña *Vencimientos* muestra automáticamente todo
  documento vencido o dentro de su ventana de aviso, ordenado por urgencia.
  **La alerta no se quita sola**: permanece hasta confirmar la renovación.
- **Botón "✔ Curso/Permiso renovado"**: en cada alerta (y en las tablas), al
  pulsarlo se carga la nueva fecha de vencimiento y la alerta desaparece.
- **Emails a los choferes**: cada chofer tiene su email en el perfil (campo
  obligatorio en el formulario) y recibe en su correo los recordatorios de
  SUS cursos. Los avisos de camiones (y de choferes sin email) van al email
  administrativo. Se envían automáticamente (revisión cada 12 h, un aviso
  cada `AVISO_EMAIL_CADA_DIAS` días por documento hasta que se renueve) o
  manualmente con el botón *📧 Enviar recordatorios por email*.

## Cómo ejecutarla

```bash
# 1. Crear el entorno virtual e instalar dependencias (desde la raíz del repo)
python3 -m venv venv_linux
./venv_linux/bin/pip install -r requirements.txt

# 2. Levantar el servidor
./venv_linux/bin/python -m uvicorn gestion_flota.main:app --host 0.0.0.0 --port 8000

# 3. Abrir en el navegador
# http://localhost:8000        → interfaz web
# http://localhost:8000/docs   → documentación interactiva de la API
```

Los datos se guardan en `gestion_flota.db` (SQLite) en el directorio desde el
que se ejecuta el servidor. Se puede cambiar con la variable de entorno
`DATABASE_URL`.

### Conectar el correo de la empresa

Copiá `.env.example` a `.env` y completá los datos SMTP de la cuenta de la
empresa. Para Gmail: `SMTP_HOST=smtp.gmail.com`, `SMTP_PORT=587` y una
**contraseña de aplicación** (se genera en la cuenta de Google, en
Seguridad → Verificación en dos pasos → Contraseñas de aplicaciones).
Sin esta configuración la app funciona igual, pero el envío de emails
devuelve un error explicativo.

## Ponerla en internet (choferes y responsables)

Ver la guía completa en [`DESPLIEGUE.md`](DESPLIEGUE.md): VPS con Docker y
HTTPS (recomendado), red de oficina, Tailscale o PaaS. Antes de exponerla,
definir `CODIGO_REGISTRO` en `.env`: crear cuenta pasa a exigir el código
interno de la empresa.

## Tests

```bash
./venv_linux/bin/python -m pytest tests/ -v
```

## Estructura

```
gestion_flota/
├── main.py            # Aplicación FastAPI y montaje de la interfaz
├── database.py        # Conexión SQLite (SQLModel)
├── models.py          # Chofer, Camion, Documento + lógica de estado
├── schemas.py         # Validación de entrada/salida (Pydantic)
├── serializers.py     # Conversión modelo → respuesta con estado calculado
├── routers/
│   ├── choferes.py    # CRUD /api/choferes
│   ├── camiones.py    # CRUD /api/camiones
│   └── documentos.py  # CRUD /api/documentos + /api/vencimientos
└── static/            # Interfaz web (HTML/CSS/JS, en español)
```

## API

| Método | Ruta | Descripción |
|---|---|---|
| GET/POST | `/api/choferes` | Listar / crear choferes |
| GET/PUT/DELETE | `/api/choferes/{id}` | Ver / editar / borrar un chofer |
| GET/POST | `/api/camiones` | Listar / crear camiones |
| GET/PUT/DELETE | `/api/camiones/{id}` | Ver / editar / borrar un camión |
| GET/POST | `/api/documentos` | Listar / crear documentos (filtro `?estado=`) |
| PUT/DELETE | `/api/documentos/{id}` | Editar / borrar un documento |
| POST | `/api/documentos/{id}/renovar` | Confirmar renovación (quita la alerta) |
| POST | `/api/avisos/enviar` | Enviar ya los recordatorios por email |
| GET | `/api/vencimientos` | Recordatorios activos (vencidos y por vencer) |
| POST | `/api/auth/registro` | Crear cuenta (queda logueado) |
| POST | `/api/auth/login` | Iniciar sesión |
| POST | `/api/auth/logout` | Cerrar sesión |
| GET | `/api/auth/yo` | Usuario actual (401 si no hay sesión) |
