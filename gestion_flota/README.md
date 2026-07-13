# ⛽ Gestión de Flota — Cursos y Permisos

Aplicación web para empresas de transporte de combustibles: administra los
**choferes**, los **camiones** y todos sus **cursos, permisos y habilitaciones**,
con **recordatorios configurables** para las renovaciones.

La interfaz usa la identidad visual de la empresa (Manual de Uso iR): verde
`#85F64B`, celeste `#36A9E1`, degradado de verde a celeste y tipografía Lato.

## Funcionalidades

- **Login y registro**: cuentas con usuario y contraseña (sin verificación
  por email). Los datos de la flota solo se ven con sesión iniciada; la
  sesión dura 30 días.
- **Notificaciones de escritorio**: con el botón *🔔 Activar avisos* el
  navegador pide permiso y luego avisa en el ordenador cuando hay cursos o
  permisos vencidos o por vencer (revisa cada hora y avisa una vez por día
  por documento).
- **Choferes**: alta, edición y baja, con DNI único, teléfono y email.
- **Camiones**: alta, edición y baja, con patente única, marca, modelo y año.
- **Documentos** (cursos, permisos, licencias, seguros, revisión técnica):
  se asignan a un chofer o a un camión, con fecha de vencimiento y una
  ventana de aviso configurable (`dias_aviso`).
- **Recordatorios**: la pestaña *Vencimientos* muestra automáticamente todo
  documento vencido o dentro de su ventana de aviso, ordenado por urgencia.
- **Renovaciones**: al renovar un curso/permiso, se edita el documento con la
  nueva fecha y el estado vuelve a *Vigente*.

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
| PUT/DELETE | `/api/documentos/{id}` | Renovar / borrar un documento |
| GET | `/api/vencimientos` | Recordatorios activos (vencidos y por vencer) |
| POST | `/api/auth/registro` | Crear cuenta (queda logueado) |
| POST | `/api/auth/login` | Iniciar sesión |
| POST | `/api/auth/logout` | Cerrar sesión |
| GET | `/api/auth/yo` | Usuario actual (401 si no hay sesión) |
