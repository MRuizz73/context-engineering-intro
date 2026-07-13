"""Aplicación FastAPI: gestión de cursos y permisos de choferes y camiones."""

from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import Depends, FastAPI
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles

from .database import init_db
from .routers import auth, camiones, choferes, documentos
from .seguridad import usuario_actual

STATIC_DIR = Path(__file__).parent / "static"


@asynccontextmanager
async def lifespan(app: FastAPI):
    """
    Inicializa la base de datos al arrancar la aplicación.

    Args:
        app (FastAPI): instancia de la aplicación.

    Yields:
        None
    """
    init_db()
    yield


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
