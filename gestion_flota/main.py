"""Aplicación FastAPI: gestión de cursos y permisos de choferes y camiones."""

import asyncio
import logging
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import Depends, FastAPI
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles
from sqlmodel import Session

from . import correo
from .database import engine, init_db
from .routers import auth, camiones, choferes, documentos
from .seguridad import usuario_actual

STATIC_DIR = Path(__file__).parent / "static"
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


@asynccontextmanager
async def lifespan(app: FastAPI):
    """
    Inicializa la base y arranca el bucle de avisos por email.

    Args:
        app (FastAPI): instancia de la aplicación.

    Yields:
        None
    """
    init_db()
    tarea_avisos = asyncio.create_task(_bucle_avisos_email())
    yield
    tarea_avisos.cancel()


app = FastAPI(
    title="Gestión de Flota",
    description="Cursos y permisos de choferes y camiones con recordatorios de renovación.",
    lifespan=lifespan,
)

app.include_router(auth.router)

# Reason: los datos de la flota solo son visibles con sesión iniciada.
protegido = [Depends(usuario_actual)]
app.include_router(choferes.router, dependencies=protegido)
app.include_router(camiones.router, dependencies=protegido)
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
