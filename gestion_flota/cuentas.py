"""Creación de las cuentas iniciales (admin y choferes) desde un CSV.

El archivo `cuentas_iniciales.csv` (columnas: usuario,password,rol,chofer)
se procesa en el primer arranque, después de importar los datos. Cada cuenta
de rol "chofer" se vincula a su perfil buscándolo por "Nombre Apellido".

IMPORTANTE: una vez repartidas las contraseñas, cada uno debería cambiarla
desde el botón "🔑 Contraseña" de la app.
"""

import csv
import secrets
import unicodedata
from pathlib import Path
from typing import Dict, List

from sqlmodel import Session, select

from .models import Chofer, RolUsuario, Usuario
from .seguridad import hashear_password

# Reason: sin caracteres ambiguos (0/O, 1/l/I) para poder dictar la clave
# por teléfono sin confusiones.
_ALFABETO = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789"


def generar_password() -> str:
    """
    Genera una contraseña segura y fácil de dictar (formato xxxx-xxxx).

    Returns:
        str: contraseña aleatoria.
    """
    return "-".join(
        "".join(secrets.choice(_ALFABETO) for _ in range(4)) for _ in range(2)
    )


def _normalizar(texto: str) -> str:
    """
    Pasa un nombre a minúsculas sin acentos ni espacios.

    Args:
        texto (str): texto a normalizar.

    Returns:
        str: texto apto para nombre de usuario.
    """
    sin_acentos = (
        unicodedata.normalize("NFKD", texto).encode("ascii", "ignore").decode()
    )
    return ".".join(sin_acentos.lower().split())


def generar_username(session: Session, chofer: Chofer) -> str:
    """
    Propone un nombre de usuario único a partir del nombre del chofer.

    Args:
        session (Session): sesión de base de datos.
        chofer (Chofer): chofer para el que se crea la cuenta.

    Returns:
        str: username libre (nombre.apellido, con sufijo numérico si hace falta).
    """
    base = _normalizar(f"{chofer.nombre} {chofer.apellido}")
    candidato = base
    sufijo = 1
    while session.exec(select(Usuario).where(Usuario.username == candidato)).first():
        sufijo += 1
        candidato = f"{base}{sufijo}"
    return candidato


def crear_cuenta_chofer(session: Session, chofer: Chofer) -> Dict[str, str]:
    """
    Crea la cuenta de acceso de un chofer con contraseña generada.

    Args:
        session (Session): sesión de base de datos.
        chofer (Chofer): chofer a vincular.

    Returns:
        Dict[str, str]: {"username", "password"} para entregar al chofer.

    Raises:
        ValueError: si el chofer ya tiene una cuenta vinculada.
    """
    existente = session.exec(
        select(Usuario).where(Usuario.chofer_id == chofer.id)
    ).first()
    if existente is not None:
        raise ValueError(f"Este chofer ya tiene la cuenta '{existente.username}'")
    username = generar_username(session, chofer)
    password = generar_password()
    session.add(
        Usuario(
            username=username,
            password_hash=hashear_password(password),
            rol=RolUsuario.CHOFER,
            chofer_id=chofer.id,
        )
    )
    session.commit()
    return {"username": username, "password": password}


def crear_cuentas_desde_csv(session: Session, ruta_csv: Path) -> Dict:
    """
    Crea las cuentas listadas en el CSV (si no existen todavía).

    Args:
        session (Session): sesión de base de datos.
        ruta_csv (Path): archivo con columnas usuario,password,rol,chofer.

    Returns:
        Dict: resumen {"creadas": [...], "omitidas": [...]}.
    """
    creadas: List[str] = []
    omitidas: List[str] = []

    with open(ruta_csv, newline="", encoding="utf-8-sig") as archivo:
        for fila in csv.DictReader(archivo):
            username = (fila.get("usuario") or "").strip().lower()
            password = (fila.get("password") or "").strip()
            rol = (fila.get("rol") or "admin").strip().lower()
            nombre_chofer = (fila.get("chofer") or "").strip()

            if not username or len(password) < 6:
                omitidas.append(f"{username or '(sin usuario)'}: datos incompletos")
                continue
            if session.exec(select(Usuario).where(Usuario.username == username)).first():
                omitidas.append(f"{username}: ya existe")
                continue

            chofer_id = None
            if rol == RolUsuario.CHOFER.value:
                partes = nombre_chofer.split()
                chofer = session.exec(
                    select(Chofer).where(
                        Chofer.nombre == partes[0],
                        Chofer.apellido == " ".join(partes[1:]),
                    )
                ).first() if partes else None
                if chofer is None:
                    omitidas.append(f"{username}: no existe el chofer '{nombre_chofer}'")
                    continue
                chofer_id = chofer.id

            session.add(
                Usuario(
                    username=username,
                    password_hash=hashear_password(password),
                    rol=RolUsuario(rol),
                    chofer_id=chofer_id,
                )
            )
            creadas.append(username)

    session.commit()
    return {"creadas": creadas, "omitidas": omitidas}
