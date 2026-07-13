"""Configuración de la base de datos (SQLite via SQLModel)."""

import os

from dotenv import load_dotenv
from sqlmodel import Session, SQLModel, create_engine

load_dotenv()

# Reason: se permite sobreescribir la URL por variable de entorno para que los
# tests puedan usar una base en memoria sin tocar la base real.
DATABASE_URL = os.getenv("DATABASE_URL", "sqlite:///gestion_flota.db")

connect_args = {"check_same_thread": False} if DATABASE_URL.startswith("sqlite") else {}
engine = create_engine(DATABASE_URL, connect_args=connect_args)


def init_db() -> None:
    """
    Crea todas las tablas si no existen y aplica migraciones simples.

    Returns:
        None
    """
    SQLModel.metadata.create_all(engine)
    _migrar_columnas()


def _migrar_columnas() -> None:
    """
    Agrega columnas nuevas a tablas existentes (SQLite no las crea solo).

    Returns:
        None
    """
    from sqlalchemy import text

    with engine.connect() as conn:
        columnas = [
            fila[1]
            for fila in conn.execute(text("PRAGMA table_info(documento)")).fetchall()
        ]
        if columnas and "ultimo_aviso_email" not in columnas:
            conn.execute(text("ALTER TABLE documento ADD COLUMN ultimo_aviso_email DATE"))
            conn.commit()


def get_session():
    """
    Provee una sesión de base de datos como dependencia de FastAPI.

    Yields:
        Session: sesión activa de SQLModel.
    """
    with Session(engine) as session:
        yield session
