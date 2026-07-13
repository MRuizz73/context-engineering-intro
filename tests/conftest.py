"""Fixtures compartidas: app de prueba con base SQLite en memoria."""

import pytest
from fastapi.testclient import TestClient
from sqlmodel import Session, SQLModel, create_engine
from sqlmodel.pool import StaticPool

from gestion_flota.database import get_session
from gestion_flota.main import app


@pytest.fixture(name="session")
def session_fixture():
    """
    Crea una sesión sobre una base SQLite en memoria, aislada por test.

    Yields:
        Session: sesión de prueba.
    """
    engine = create_engine(
        "sqlite://",
        connect_args={"check_same_thread": False},
        poolclass=StaticPool,
    )
    SQLModel.metadata.create_all(engine)
    with Session(engine) as session:
        yield session


@pytest.fixture(name="client")
def client_fixture(session: Session):
    """
    Cliente HTTP de prueba con la dependencia de sesión sobreescrita.

    Args:
        session (Session): sesión de prueba en memoria.

    Yields:
        TestClient: cliente para llamar a la API.
    """

    def get_session_override():
        return session

    app.dependency_overrides[get_session] = get_session_override
    client = TestClient(app)
    yield client
    app.dependency_overrides.clear()


def crear_chofer(client: TestClient, dni: str = "30111222") -> dict:
    """
    Helper: crea un chofer de prueba vía API.

    Args:
        client (TestClient): cliente de prueba.
        dni (str): DNI único del chofer.

    Returns:
        dict: chofer creado.
    """
    resp = client.post(
        "/api/choferes",
        json={"nombre": "Juan", "apellido": "Pérez", "dni": dni},
    )
    assert resp.status_code == 201
    return resp.json()


def crear_camion(client: TestClient, patente: str = "AB123CD") -> dict:
    """
    Helper: crea un camión de prueba vía API.

    Args:
        client (TestClient): cliente de prueba.
        patente (str): patente única del camión.

    Returns:
        dict: camión creado.
    """
    resp = client.post(
        "/api/camiones",
        json={"patente": patente, "marca": "Scania", "modelo": "R450", "anio": 2020},
    )
    assert resp.status_code == 201
    return resp.json()
