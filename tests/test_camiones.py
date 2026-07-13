"""Tests de los endpoints de camiones."""

from fastapi.testclient import TestClient

from .conftest import crear_camion


def test_crear_y_listar_camion(client: TestClient):
    """Caso esperado: crear un camión y verlo en el listado."""
    camion = crear_camion(client)
    assert camion["patente"] == "AB123CD"
    assert camion["marca"] == "Scania"

    resp = client.get("/api/camiones")
    assert resp.status_code == 200
    assert len(resp.json()) == 1


def test_patente_duplicada_rechazada(client: TestClient):
    """Caso borde: dos camiones con la misma patente no están permitidos."""
    crear_camion(client, patente="AB123CD")
    resp = client.post("/api/camiones", json={"patente": "AB123CD"})
    assert resp.status_code == 409


def test_eliminar_camion_inexistente(client: TestClient):
    """Caso de fallo: eliminar un camión que no existe devuelve 404."""
    resp = client.delete("/api/camiones/999")
    assert resp.status_code == 404


def test_actualizar_camion(client: TestClient):
    """Caso esperado: actualizar los datos de un camión."""
    camion = crear_camion(client)
    resp = client.put(
        f"/api/camiones/{camion['id']}",
        json={"patente": "AB123CD", "marca": "Volvo", "modelo": "FH", "anio": 2022, "activo": True},
    )
    assert resp.status_code == 200
    assert resp.json()["marca"] == "Volvo"
    assert resp.json()["anio"] == 2022
