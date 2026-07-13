"""Tests de los endpoints de choferes."""

from fastapi.testclient import TestClient

from .conftest import crear_chofer


def test_crear_y_listar_chofer(client: TestClient):
    """Caso esperado: crear un chofer y verlo en el listado."""
    chofer = crear_chofer(client)
    assert chofer["nombre"] == "Juan"
    assert chofer["apellido"] == "Pérez"
    assert chofer["activo"] is True

    resp = client.get("/api/choferes")
    assert resp.status_code == 200
    listado = resp.json()
    assert len(listado) == 1
    assert listado[0]["dni"] == "30111222"


def test_dni_duplicado_rechazado(client: TestClient):
    """Caso borde: dos choferes con el mismo DNI no están permitidos."""
    crear_chofer(client, dni="30111222")
    resp = client.post(
        "/api/choferes",
        json={"nombre": "Otro", "apellido": "Gómez", "dni": "30111222"},
    )
    assert resp.status_code == 409


def test_obtener_chofer_inexistente(client: TestClient):
    """Caso de fallo: pedir un chofer que no existe devuelve 404."""
    resp = client.get("/api/choferes/999")
    assert resp.status_code == 404


def test_actualizar_y_eliminar_chofer(client: TestClient):
    """Caso esperado: actualizar datos y luego eliminar."""
    chofer = crear_chofer(client)
    resp = client.put(
        f"/api/choferes/{chofer['id']}",
        json={
            "nombre": "Juan Carlos",
            "apellido": "Pérez",
            "dni": "30111222",
            "telefono": "1155667788",
            "activo": False,
        },
    )
    assert resp.status_code == 200
    assert resp.json()["nombre"] == "Juan Carlos"
    assert resp.json()["activo"] is False

    resp = client.delete(f"/api/choferes/{chofer['id']}")
    assert resp.status_code == 204
    assert client.get("/api/choferes").json() == []
