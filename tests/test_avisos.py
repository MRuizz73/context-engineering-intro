"""Tests de renovación de documentos y recordatorios por email."""

from datetime import date, timedelta

import pytest
from fastapi.testclient import TestClient

from gestion_flota import correo
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


def _doc_vencido(client: TestClient, chofer_id: int) -> dict:
    """
    Helper: crea un documento vencido para un chofer.

    Args:
        client (TestClient): cliente autenticado.
        chofer_id (int): id del chofer titular.

    Returns:
        dict: documento creado.
    """
    return client.post(
        "/api/documentos",
        json={
            "nombre": "Curso cargas peligrosas",
            "fecha_vencimiento": _fecha(-3),
            "dias_aviso": 30,
            "chofer_id": chofer_id,
        },
    ).json()


# ---------- renovación ----------


def test_alerta_persiste_hasta_renovar(client: TestClient):
    """Caso esperado: la alerta sigue activa hasta confirmar la renovación."""
    chofer = crear_chofer(client)
    doc = _doc_vencido(client, chofer["id"])

    # La alerta está y sigue estando mientras nadie confirme la renovación.
    assert len(client.get("/api/vencimientos").json()) == 1
    assert len(client.get("/api/vencimientos").json()) == 1

    resp = client.post(
        f"/api/documentos/{doc['id']}/renovar",
        json={"fecha_vencimiento": _fecha(365)},
    )
    assert resp.status_code == 200
    assert resp.json()["estado"] == "vigente"
    assert resp.json()["fecha_emision"] == date.today().isoformat()
    assert client.get("/api/vencimientos").json() == []


def test_renovar_con_fecha_pasada_rechazado(client: TestClient):
    """Caso de fallo: renovar con una fecha que no es futura devuelve 422."""
    chofer = crear_chofer(client)
    doc = _doc_vencido(client, chofer["id"])
    resp = client.post(
        f"/api/documentos/{doc['id']}/renovar",
        json={"fecha_vencimiento": _fecha(0)},
    )
    assert resp.status_code == 422


def test_renovar_documento_inexistente(client: TestClient):
    """Caso de fallo: renovar un documento que no existe devuelve 404."""
    resp = client.post(
        "/api/documentos/999/renovar", json={"fecha_vencimiento": _fecha(30)}
    )
    assert resp.status_code == 404


# ---------- emails ----------


@pytest.fixture(name="smtp_falso")
def smtp_falso_fixture(monkeypatch):
    """
    Configura un SMTP simulado que registra los emails "enviados".

    Yields:
        list: emails capturados como tuplas (destinatario, asunto, cuerpo).
    """
    monkeypatch.setenv("SMTP_HOST", "smtp.test")
    monkeypatch.setenv("SMTP_USER", "empresa@test.com")
    monkeypatch.setenv("EMAIL_ADMIN", "oficina@test.com")
    enviados = []

    def _capturar(destinatario, asunto, cuerpo):
        enviados.append((destinatario, asunto, cuerpo))

    monkeypatch.setattr(correo, "_enviar_smtp", _capturar)
    yield enviados


def test_email_al_chofer_y_admin(client: TestClient, smtp_falso: list):
    """Caso esperado: el aviso del chofer va a su email y el del camión al admin."""
    chofer = crear_chofer(client)
    client.put(
        f"/api/choferes/{chofer['id']}",
        json={
            "nombre": "Juan",
            "apellido": "Pérez",
            "dni": "30111222",
            "email": "juan@test.com",
        },
    )
    _doc_vencido(client, chofer["id"])
    camion = crear_camion(client)
    client.post(
        "/api/documentos",
        json={
            "nombre": "Revisión técnica",
            "fecha_vencimiento": _fecha(5),
            "dias_aviso": 30,
            "camion_id": camion["id"],
        },
    )

    resumen = client.post("/api/avisos/enviar").json()
    assert resumen["emails_enviados"] == 2
    assert resumen["documentos_avisados"] == 2

    destinos = {destino for destino, _, _ in smtp_falso}
    assert destinos == {"juan@test.com", "oficina@test.com"}
    cuerpo_chofer = next(c for d, _, c in smtp_falso if d == "juan@test.com")
    assert "Curso cargas peligrosas" in cuerpo_chofer
    assert "VENCIDO" in cuerpo_chofer


def test_email_no_se_repite_hasta_frecuencia(client: TestClient, smtp_falso: list):
    """Caso borde: un segundo envío el mismo día no repite el aviso."""
    chofer = crear_chofer(client)
    _doc_vencido(client, chofer["id"])

    primero = client.post("/api/avisos/enviar").json()
    segundo = client.post("/api/avisos/enviar").json()
    assert primero["documentos_avisados"] == 1
    assert segundo["documentos_avisados"] == 0
    assert len(smtp_falso) == 1


def test_renovar_reactiva_avisos(client: TestClient, smtp_falso: list):
    """Caso borde: renovar resetea el registro de aviso enviado."""
    chofer = crear_chofer(client)
    doc = _doc_vencido(client, chofer["id"])
    client.post("/api/avisos/enviar")

    # Renovado por poco tiempo: vuelve a estar por vencer y debe avisarse de nuevo.
    client.post(f"/api/documentos/{doc['id']}/renovar", json={"fecha_vencimiento": _fecha(10)})
    resumen = client.post("/api/avisos/enviar").json()
    assert resumen["documentos_avisados"] == 1
    assert len(smtp_falso) == 2


def test_envio_sin_smtp_configurado(client: TestClient, monkeypatch):
    """Caso de fallo: sin SMTP configurado, el endpoint devuelve 503."""
    monkeypatch.delenv("SMTP_HOST", raising=False)
    monkeypatch.delenv("SMTP_USER", raising=False)
    resp = client.post("/api/avisos/enviar")
    assert resp.status_code == 503
