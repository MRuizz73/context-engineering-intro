"""Configuración de la base de datos (SQLite via SQLModel)."""

import os

from dotenv import load_dotenv
from sqlmodel import Session, SQLModel, create_engine

load_dotenv()

# Reason: se permite sobreescribir la URL por variable de entorno; soporta
# SQLite (default) y MySQL/MariaDB (mysql+pymysql://usuario:clave@host/base).
DATABASE_URL = os.getenv("DATABASE_URL", "sqlite:///gestion_flota.db")

if DATABASE_URL.startswith("sqlite"):
    engine = create_engine(DATABASE_URL, connect_args={"check_same_thread": False})
else:
    # Reason: pool_pre_ping y pool_recycle evitan el clásico
    # "MySQL server has gone away" cuando la conexión queda ociosa.
    engine = create_engine(DATABASE_URL, pool_pre_ping=True, pool_recycle=1800)


def init_db() -> None:
    """
    Crea todas las tablas si no existen y aplica migraciones simples.

    Returns:
        None
    """
    SQLModel.metadata.create_all(engine)
    _migrar_columnas()


# Columnas agregadas después de la primera versión: tabla → (columna, tipo).
_MIGRACIONES = {
    "documento": [("ultimo_aviso_email", "DATE")],
    "usuario": [("rol", "VARCHAR DEFAULT 'admin'"), ("chofer_id", "INTEGER")],
}


def _migrar_columnas() -> None:
    """
    Agrega columnas nuevas a tablas existentes (SQLite no las crea solo).

    Solo aplica a SQLite: en MySQL las tablas se crean completas con
    `create_all` (instalación nueva).

    Returns:
        None
    """
    from sqlalchemy import text

    if engine.dialect.name != "sqlite":
        return

    with engine.connect() as conn:
        for tabla, columnas_nuevas in _MIGRACIONES.items():
            existentes = [
                fila[1]
                for fila in conn.execute(text(f"PRAGMA table_info({tabla})")).fetchall()
            ]
            for columna, tipo in columnas_nuevas:
                if existentes and columna not in existentes:
                    conn.execute(
                        text(f"ALTER TABLE {tabla} ADD COLUMN {columna} {tipo}")
                    )
        conn.commit()


def get_session():
    """
    Provee una sesión de base de datos como dependencia de FastAPI.

    Yields:
        Session: sesión activa de SQLModel.
    """
    with Session(engine) as session:
        yield session
