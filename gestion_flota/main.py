"""Aplicación FastAPI: gestión de cursos y permisos de choferes y camiones."""

from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles

from .database import init_db
from .routers import camiones, choferes, documentos

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

app.include_router(choferes.router)
app.include_router(camiones.router)
app.include_router(documentos.router)

app.mount("/static", StaticFiles(directory=STATIC_DIR), name="static")


@app.get("/", include_in_schema=False)
def index() -> FileResponse:
    """
    Sirve la interfaz web.

    Returns:
        FileResponse: la página principal.
    """
    return FileResponse(STATIC_DIR / "index.html")
