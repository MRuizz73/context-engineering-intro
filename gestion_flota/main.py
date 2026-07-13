"""Aplicación FastAPI: gestión de cursos y permisos de choferes y camiones."""

import asyncio
import logging
import os
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import Depends, FastAPI
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles
from sqlmodel import Session, select

from . import correo, cuentas, importar_csv
from .database import engine, init_db
from .models import Camion, Chofer, Documento, Usuario
from .routers import auth, camiones, choferes, documentos
from .seguridad import requiere_admin, usuario_actual

STATIC_DIR = Path(__file__).parent / "static"
DATOS_INICIALES = Path(__file__).parent / "datos_iniciales.csv"
CUENTAS_INICIALES = Path(__file__).parent / "cuentas_iniciales.csv"
INTERVALO_AVISOS_SEGUNDOS = 12 * 60 * 60
logger = logging.getLogger("gestion_flota")


async def _bucle_avisos_email() -> None:
    """
    Revisa los vencimientos cada 12 horas y envía los emails pendientes.

    `enviar_recordatorios` ya limita la frecuencia por documento, así que
    correr el bucle dos veces por día no duplica avisos.

    Returns:
        None
    """
    while True:
        try:
            if correo.smtp_configurado():
                with Session(engine) as session:
                    resumen = correo.enviar_recordatorios(session)
                    if resumen["emails_enviados"]:
                        logger.info("Recordatorios enviados: %s", resumen)
        except Exception:  # Reason: un fallo de SMTP no debe tumbar la app.
            logger.exception("Error enviando recordatorios por email")
        await asyncio.sleep(INTERVALO_AVISOS_SEGUNDOS)


def _sembrar_datos_iniciales() -> None:
    """
    Importa el CSV de datos iniciales la primera vez (base vacía).

    Se puede desactivar con AUTO_IMPORTAR=0.

    Returns:
        None
    """
    if os.getenv("AUTO_IMPORTAR", "1") != "1" or not DATOS_INICIALES.exists():
        return
    with Session(engine) as session:
        vacia = (
            session.exec(select(Chofer)).first() is None
            and session.exec(select(Camion)).first() is None
            and session.exec(select(Documento)).first() is None
        )
        if vacia:
            resumen = importar_csv.importar(session, DATOS_INICIALES)
            logger.info("Datos iniciales importados: %s", resumen)
        sin_usuarios = session.exec(select(Usuario)).first() is None
        if sin_usuarios and CUENTAS_INICIALES.exists():
            resumen_cuentas = cuentas.crear_cuentas_desde_csv(session, CUENTAS_INICIALES)
            logger.info("Cuentas iniciales creadas: %s", resumen_cuentas)


@asynccontextmanager
async def lifespan(app: FastAPI):
    """
    Inicializa la base, siembra datos iniciales y arranca los avisos.

    Args:
        app (FastAPI): instancia de la aplicación.

    Yields:
        None
    """
    init_db()
    _sembrar_datos_iniciales()
    tarea_avisos = asyncio.create_task(_bucle_avisos_email())
    yield
    tarea_avisos.cancel()


app = FastAPI(
    title="Gestión de Flota",
    description="Cursos y permisos de choferes y camiones con recordatorios de renovación.",
    lifespan=lifespan,
)

app.include_router(auth.router)

# Reason: los datos de la flota solo son visibles con sesión iniciada;
# los camiones son exclusivos del rol admin (los choferes ven solo lo suyo).
protegido = [Depends(usuario_actual)]
app.include_router(choferes.router, dependencies=protegido)
app.include_router(camiones.router, dependencies=[Depends(requiere_admin)])
app.include_router(documentos.router, dependencies=protegido)

app.mount("/static", StaticFiles(directory=STATIC_DIR), name="static")


@app.get("/", include_in_schema=False)
def index() -> FileResponse:
    """
    Sirve la interfaz web.

    Returns:
        FileResponse: la página principal.
    """
    return FileResponse(STATIC_DIR / "index.html")
