"""Tests de registro, login y protección de rutas."""

from fastapi.testclient import TestClient


def test_registro_y_login(client_anonimo: TestClient):
    """Caso esperado: registrarse, salir y volver a entrar con la misma clave."""
    resp = client_anonimo.post(
        "/api/auth/registro", json={"username": "Miguel", "password": "clave123"}
    )
    assert resp.status_code == 201
    assert resp.json()["username"] == "miguel"

    assert client_anonimo.get("/api/auth/yo").status_code == 200

    client_anonimo.post("/api/auth/logout")
    assert client_anonimo.get("/api/auth/yo").status_code == 401

    resp = client_anonimo.post(
        "/api/auth/login", json={"username": "miguel", "password": "clave123"}
    )
    assert resp.status_code == 200
    assert client_anonimo.get("/api/auth/yo").status_code == 200


def test_login_password_incorrecta(client_anonimo: TestClient):
    """Caso de fallo: contraseña equivocada devuelve 401."""
    client_anonimo.post(
        "/api/auth/registro", json={"username": "miguel", "password": "clave123"}
    )
    client_anonimo.post("/api/auth/logout")
    resp = client_anonimo.post(
        "/api/auth/login", json={"username": "miguel", "password": "otra-clave"}
    )
    assert resp.status_code == 401


def test_username_duplicado(client_anonimo: TestClient):
    """Caso borde: el mismo username (con otras mayúsculas) devuelve 409."""
    client_anonimo.post(
        "/api/auth/registro", json={"username": "miguel", "password": "clave123"}
    )
    resp = client_anonimo.post(
        "/api/auth/registro", json={"username": "MIGUEL", "password": "clave456"}
    )
    assert resp.status_code == 409


def test_password_corta_rechazada(client_anonimo: TestClient):
    """Caso de fallo: contraseña de menos de 6 caracteres devuelve 422."""
    resp = client_anonimo.post(
        "/api/auth/registro", json={"username": "miguel", "password": "123"}
    )
    assert resp.status_code == 422


def test_rutas_protegidas_sin_login(client_anonimo: TestClient):
    """Caso esperado: los datos de la flota exigen sesión iniciada."""
    for ruta in ["/api/choferes", "/api/camiones", "/api/documentos", "/api/vencimientos"]:
        assert client_anonimo.get(ruta).status_code == 401, ruta
    resp = client_anonimo.post(
        "/api/choferes", json={"nombre": "X", "apellido": "Y", "dni": "1"}
    )
    assert resp.status_code == 401
