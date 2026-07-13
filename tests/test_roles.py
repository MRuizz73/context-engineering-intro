"""Tests de roles: admin ve todo, cada chofer solo lo suyo."""

from datetime import date, timedelta
from pathlib import Path

import pytest
from fastapi.testclient import TestClient
from sqlmodel import Session

from gestion_flota.cuentas import crear_cuentas_desde_csv
from gestion_flota.main import app
from .conftest import crear_camion, crear_chofer


def _fecha(dias: int) -> str:
    """
    Devuelve la fecha de hoy desplazada `dias` días, en formato ISO.

    Args:
        dias (int): días a sumar (negativo para el pasado).

    Returns:
        str: fecha ISO (YYYY-MM-DD).
    """
    return (date.today() + timedelta(days=dias)).isoformat()


@pytest.fixture(name="entorno")
def entorno_fixture(client: TestClient):
    """
    Arma un escenario con dos choferes, un camión y sus documentos,
    más un cliente logueado como el chofer Juan.

    Args:
        client (TestClient): cliente admin ya autenticado.

    Yields:
        dict: {"admin", "chofer_cliente", "juan", "otro", "doc_juan", "doc_otro"}
    """
    juan = crear_chofer(client, dni="30111222")
    client.put(
        f"/api/choferes/{juan['id']}",
        json={"nombre": "Juan", "apellido": "Pérez", "dni": "30111222",
              "email": "juan@test.com"},
    )
    otro = client.post(
        "/api/choferes",
        json={"nombre": "Luis", "apellido": "Gómez", "dni": "31222333"},
    ).json()
    camion = crear_camion(client)
    doc_juan = client.post(
        "/api/documentos",
        json={"nombre": "Curso ADR", "fecha_vencimiento": _fecha(-2),
              "dias_aviso": 30, "chofer_id": juan["id"]},
    ).json()
    doc_otro = client.post(
        "/api/documentos",
        json={"nombre": "Certificado médico", "fecha_vencimiento": _fecha(5),
              "dias_aviso": 30, "chofer_id": otro["id"]},
    ).json()
    client.post(
        "/api/documentos",
        json={"nombre": "ITV", "fecha_vencimiento": _fecha(3),
              "dias_aviso": 30, "camion_id": camion["id"]},
    )

    chofer_cliente = TestClient(app)
    resp = chofer_cliente.post(
        "/api/auth/registro",
        json={"username": "juanp", "password": "clave123",
              "email_chofer": "juan@test.com"},
    )
    assert resp.status_code == 201
    assert resp.json()["rol"] == "chofer"
    assert resp.json()["chofer_id"] == juan["id"]

    yield {
        "admin": client,
        "chofer_cliente": chofer_cliente,
        "juan": juan,
        "otro": otro,
        "doc_juan": doc_juan,
        "doc_otro": doc_otro,
    }


def test_chofer_ve_solo_lo_suyo(entorno):
    """Caso esperado: el chofer no ve listados globales ni perfiles ajenos."""
    chofer = entorno["chofer_cliente"]

    assert chofer.get("/api/choferes").status_code == 403
    assert chofer.get("/api/camiones").status_code == 403
    assert chofer.get(f"/api/choferes/{entorno['otro']['id']}").status_code == 403
    assert chofer.get(f"/api/choferes/{entorno['juan']['id']}").status_code == 200

    documentos = chofer.get("/api/documentos").json()
    assert [d["nombre"] for d in documentos] == ["Curso ADR"]
    alertas = chofer.get("/api/vencimientos").json()
    assert [d["nombre"] for d in alertas] == ["Curso ADR"]


def test_admin_ve_todo(entorno):
    """Caso esperado: el admin sigue viendo choferes, camiones y todas las alertas."""
    admin = entorno["admin"]
    assert len(admin.get("/api/choferes").json()) == 2
    assert len(admin.get("/api/camiones").json()) == 1
    assert len(admin.get("/api/vencimientos").json()) == 3


def test_chofer_gestiona_solo_sus_cursos(entorno):
    """Caso borde: el chofer añade y renueva lo suyo; lo ajeno da 403."""
    chofer = entorno["chofer_cliente"]
    juan_id = entorno["juan"]["id"]

    propio = chofer.post(
        "/api/documentos",
        json={"nombre": "Curso terminales", "fecha_vencimiento": _fecha(60),
              "dias_aviso": 30, "chofer_id": juan_id},
    )
    assert propio.status_code == 201

    ajeno = chofer.post(
        "/api/documentos",
        json={"nombre": "Curso intruso", "fecha_vencimiento": _fecha(60),
              "dias_aviso": 30, "chofer_id": entorno["otro"]["id"]},
    )
    assert ajeno.status_code == 403

    renovar_propio = chofer.post(
        f"/api/documentos/{entorno['doc_juan']['id']}/renovar",
        json={"fecha_vencimiento": _fecha(365)},
    )
    assert renovar_propio.status_code == 200
    renovar_ajeno = chofer.post(
        f"/api/documentos/{entorno['doc_otro']['id']}/renovar",
        json={"fecha_vencimiento": _fecha(365)},
    )
    assert renovar_ajeno.status_code == 403

    assert chofer.delete(f"/api/documentos/{entorno['doc_juan']['id']}").status_code == 403
    assert chofer.post("/api/avisos/enviar").status_code == 403


def test_registro_chofer_fallas(entorno):
    """Caso de fallo: email inexistente da 404 y chofer ya vinculado da 409."""
    nuevo = TestClient(app)
    sin_perfil = nuevo.post(
        "/api/auth/registro",
        json={"username": "nuevo1", "password": "clave123", "email_chofer": "nadie@test.com"},
    )
    assert sin_perfil.status_code == 404

    repetido = nuevo.post(
        "/api/auth/registro",
        json={"username": "nuevo2", "password": "clave123", "email_chofer": "juan@test.com"},
    )
    assert repetido.status_code == 409


def test_cambiar_password(client_anonimo: TestClient):
    """Caso esperado: cambiar la contraseña y volver a entrar con la nueva."""
    client_anonimo.post(
        "/api/auth/registro", json={"username": "miguel", "password": "vieja123"}
    )
    mal = client_anonimo.post(
        "/api/auth/cambiar-password",
        json={"password_actual": "equivocada", "password_nueva": "nueva123"},
    )
    assert mal.status_code == 401

    bien = client_anonimo.post(
        "/api/auth/cambiar-password",
        json={"password_actual": "vieja123", "password_nueva": "nueva123"},
    )
    assert bien.status_code == 204

    client_anonimo.post("/api/auth/logout")
    assert client_anonimo.post(
        "/api/auth/login", json={"username": "miguel", "password": "vieja123"}
    ).status_code == 401
    assert client_anonimo.post(
        "/api/auth/login", json={"username": "miguel", "password": "nueva123"}
    ).status_code == 200


def test_generador_de_cuenta_para_chofer(entorno):
    """Caso esperado: el admin genera la cuenta de un chofer y este puede entrar."""
    admin = entorno["admin"]
    otro_id = entorno["otro"]["id"]

    resp = admin.post(f"/api/choferes/{otro_id}/crear-cuenta")
    assert resp.status_code == 201
    cred = resp.json()
    assert cred["username"] == "luis.gomez"
    assert len(cred["password"]) >= 9

    # El chofer entra con esas credenciales y solo ve lo suyo.
    nuevo = TestClient(app)
    login = nuevo.post("/api/auth/login", json={"username": cred["username"], "password": cred["password"]})
    assert login.status_code == 200
    assert login.json()["rol"] == "chofer"
    assert login.json()["chofer_id"] == otro_id
    assert nuevo.get("/api/choferes").status_code == 403

    # Repetir la generación para el mismo chofer da 409.
    assert admin.post(f"/api/choferes/{otro_id}/crear-cuenta").status_code == 409

    # Un chofer no puede generar cuentas.
    assert entorno["chofer_cliente"].post(
        f"/api/choferes/{otro_id}/crear-cuenta"
    ).status_code == 403


def test_cuentas_iniciales_desde_csv(session: Session, tmp_path: Path):
    """Caso esperado: crea admin y chofer vinculado; reporta filas inválidas."""
    from gestion_flota.models import Chofer

    session.add(Chofer(nombre="Samuel", apellido="Alonso", dni="PTE-001"))
    session.commit()

    ruta = tmp_path / "cuentas.csv"
    ruta.write_text(
        "usuario,password,rol,chofer\n"
        "admin,Clave-Segura1,admin,\n"
        "samuel.alonso,Clave-Segura2,chofer,Samuel Alonso\n"
        "fantasma,Clave-Segura3,chofer,No Existe\n",
        encoding="utf-8",
    )
    resumen = crear_cuentas_desde_csv(session, ruta)
    assert resumen["creadas"] == ["admin", "samuel.alonso"]
    assert len(resumen["omitidas"]) == 1
