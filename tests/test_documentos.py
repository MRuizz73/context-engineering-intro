"""Tests de documentos (cursos/permisos) y recordatorios de vencimiento."""

from datetime import date, timedelta

from fastapi.testclient import TestClient

from gestion_flota.models import Documento, EstadoDocumento, TipoDocumento
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


def test_crear_documento_para_chofer(client: TestClient):
    """Caso esperado: asignar un curso a un chofer con recordatorio de 30 días."""
    chofer = crear_chofer(client)
    resp = client.post(
        "/api/documentos",
        json={
            "nombre": "Curso cargas peligrosas",
            "tipo": "curso",
            "fecha_vencimiento": _fecha(90),
            "dias_aviso": 30,
            "chofer_id": chofer["id"],
        },
    )
    assert resp.status_code == 201
    doc = resp.json()
    assert doc["estado"] == "vigente"
    assert doc["dias_restantes"] == 90
    assert "Pérez" in doc["titular"]


def test_crear_documento_para_camion(client: TestClient):
    """Caso esperado: asignar una revisión técnica a un camión."""
    camion = crear_camion(client)
    resp = client.post(
        "/api/documentos",
        json={
            "nombre": "Revisión técnica obligatoria",
            "tipo": "revision_tecnica",
            "fecha_vencimiento": _fecha(10),
            "dias_aviso": 30,
            "camion_id": camion["id"],
        },
    )
    assert resp.status_code == 201
    doc = resp.json()
    assert doc["estado"] == "por_vencer"
    assert "AB123CD" in doc["titular"]


def test_documento_sin_titular_rechazado(client: TestClient):
    """Caso de fallo: un documento sin chofer ni camión devuelve 422."""
    resp = client.post(
        "/api/documentos",
        json={"nombre": "Curso suelto", "fecha_vencimiento": _fecha(30)},
    )
    assert resp.status_code == 422


def test_documento_con_ambos_titulares_rechazado(client: TestClient):
    """Caso de fallo: un documento con chofer Y camión a la vez devuelve 422."""
    chofer = crear_chofer(client)
    camion = crear_camion(client)
    resp = client.post(
        "/api/documentos",
        json={
            "nombre": "Doc inválido",
            "fecha_vencimiento": _fecha(30),
            "chofer_id": chofer["id"],
            "camion_id": camion["id"],
        },
    )
    assert resp.status_code == 422


def test_documento_titular_inexistente(client: TestClient):
    """Caso de fallo: documento para un chofer que no existe devuelve 404."""
    resp = client.post(
        "/api/documentos",
        json={"nombre": "Curso", "fecha_vencimiento": _fecha(30), "chofer_id": 999},
    )
    assert resp.status_code == 404


def test_vencimientos_lista_solo_alertas(client: TestClient):
    """Caso esperado: /api/vencimientos incluye vencidos y por vencer, no vigentes."""
    chofer = crear_chofer(client)
    base = {"chofer_id": chofer["id"], "dias_aviso": 30}
    client.post("/api/documentos", json={"nombre": "Vigente", "fecha_vencimiento": _fecha(200), **base})
    client.post("/api/documentos", json={"nombre": "Por vencer", "fecha_vencimiento": _fecha(15), **base})
    client.post("/api/documentos", json={"nombre": "Vencido", "fecha_vencimiento": _fecha(-5), **base})

    resp = client.get("/api/vencimientos")
    assert resp.status_code == 200
    nombres = [d["nombre"] for d in resp.json()]
    assert nombres == ["Vencido", "Por vencer"]

    estados = {d["nombre"]: d["estado"] for d in resp.json()}
    assert estados["Vencido"] == "vencido"
    assert estados["Por vencer"] == "por_vencer"


def test_estado_vence_hoy_es_por_vencer():
    """Caso borde: un documento que vence hoy cuenta como 'por vencer', no vencido."""
    doc = Documento(
        nombre="Licencia",
        tipo=TipoDocumento.LICENCIA,
        fecha_vencimiento=date.today(),
        dias_aviso=0,
    )
    assert doc.dias_restantes() == 0
    assert doc.estado() == EstadoDocumento.POR_VENCER


def test_renovar_documento_actualiza_estado(client: TestClient):
    """Caso esperado: renovar (PUT) un documento vencido lo vuelve vigente."""
    chofer = crear_chofer(client)
    doc = client.post(
        "/api/documentos",
        json={
            "nombre": "Curso manejo defensivo",
            "fecha_vencimiento": _fecha(-10),
            "dias_aviso": 30,
            "chofer_id": chofer["id"],
        },
    ).json()
    assert doc["estado"] == "vencido"

    resp = client.put(
        f"/api/documentos/{doc['id']}",
        json={
            "nombre": "Curso manejo defensivo",
            "fecha_vencimiento": _fecha(365),
            "dias_aviso": 30,
            "chofer_id": chofer["id"],
        },
    )
    assert resp.status_code == 200
    assert resp.json()["estado"] == "vigente"


def test_eliminar_chofer_elimina_sus_documentos(client: TestClient):
    """Caso borde: al borrar un chofer, sus documentos desaparecen de los listados."""
    chofer = crear_chofer(client)
    client.post(
        "/api/documentos",
        json={"nombre": "Curso", "fecha_vencimiento": _fecha(5), "chofer_id": chofer["id"]},
    )
    assert len(client.get("/api/documentos").json()) == 1

    client.delete(f"/api/choferes/{chofer['id']}")
    assert client.get("/api/documentos").json() == []
