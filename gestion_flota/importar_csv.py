"""Importador de datos iniciales desde el CSV de cursos y vencimientos.

Formato esperado (con cabecera): Curso,Fecha,Hora,Detalle
    - Cursos de vehículo (ITV, ADR (vehiculo), Tacografo, Extintores, Seguro):
      el Detalle es una matrícula, o varias separadas por "/".
    - Cursos de chofer (Certificado Medico, CAP Conductor, etc.): el Detalle
      es el nombre del chofer, o varios separados por "/", con una nota
      opcional entre paréntesis que se conserva en las notas del documento.

Si hay varias filas del mismo curso para el mismo titular, se crea UN solo
documento con la próxima fecha (la más cercana en el futuro, o la última
pasada si ya venció) y las demás fechas quedan en las notas.

Uso por línea de comandos:
    python -m gestion_flota.importar_csv archivo.csv
"""

import csv
import sys
from datetime import date, datetime
from pathlib import Path
from typing import Dict, List, Optional, Tuple

from sqlmodel import Session, select

from .models import Camion, Chofer, Documento, TipoDocumento

CURSOS_VEHICULO: Dict[str, TipoDocumento] = {
    "ITV": TipoDocumento.REVISION_TECNICA,
    "ADR (vehiculo)": TipoDocumento.PERMISO,
    "Tacografo": TipoDocumento.OTRO,
    "Extintores": TipoDocumento.OTRO,
    "Seguro": TipoDocumento.SEGURO,
}

CURSOS_CHOFER: Dict[str, TipoDocumento] = {
    "Certificado Medico": TipoDocumento.OTRO,
    "CAP Conductor": TipoDocumento.CURSO,
    "Permiso de Conducir": TipoDocumento.LICENCIA,
    "Online Curso": TipoDocumento.CURSO,
    "Curso Terminales": TipoDocumento.CURSO,
    "DNI": TipoDocumento.OTRO,
    "ADR Conductor": TipoDocumento.LICENCIA,
}

# Reason: el CSV trae el mismo chofer escrito de formas distintas; se unifica
# al nombre más frecuente para no crear perfiles duplicados.
NOMBRES_UNIFICADOS = {
    "Jonathan Quintero": "Jonathan Quinteros",
    "Raul Ivan Rodriguez": "Raul Rodriguez",
    "Francisco Javier Castro": "Francisco Castro",
}

DIAS_AVISO_DEFAULT = 30


def _separar_detalle(detalle: str) -> Tuple[List[str], Optional[str]]:
    """
    Separa el Detalle en titulares y nota entre paréntesis.

    Args:
        detalle (str): campo Detalle del CSV.

    Returns:
        Tuple[List[str], Optional[str]]: titulares (separados por "/") y nota.
    """
    nota = None
    if "(" in detalle and detalle.rstrip().endswith(")"):
        detalle, cruda = detalle.rsplit("(", 1)
        nota = cruda.rstrip()[:-1].strip()
    titulares = [t.strip() for t in detalle.split("/") if t.strip()]
    return titulares, nota


def _obtener_chofer(session: Session, nombre_completo: str, contador: List[int]) -> Chofer:
    """
    Busca un chofer por nombre o lo crea con DNI provisorio.

    Args:
        session (Session): sesión de base de datos.
        nombre_completo (str): nombre y apellido como figura en el CSV.
        contador (List[int]): contador mutable para los DNI provisorios.

    Returns:
        Chofer: el chofer existente o recién creado.
    """
    nombre_completo = NOMBRES_UNIFICADOS.get(nombre_completo, nombre_completo)
    partes = nombre_completo.split()
    nombre, apellido = partes[0], " ".join(partes[1:]) or "-"
    existente = session.exec(
        select(Chofer).where(Chofer.nombre == nombre, Chofer.apellido == apellido)
    ).first()
    if existente is not None:
        return existente
    contador[0] += 1
    # Reason: el DNI es obligatorio y único; se usa un provisorio visible
    # ("PTE-…" = pendiente) hasta que se complete el perfil real.
    chofer = Chofer(nombre=nombre, apellido=apellido, dni=f"PTE-{contador[0]:03d}")
    session.add(chofer)
    session.flush()
    return chofer


def _obtener_camion(session: Session, patente: str) -> Camion:
    """
    Busca un camión por patente o lo crea.

    Args:
        session (Session): sesión de base de datos.
        patente (str): matrícula del vehículo.

    Returns:
        Camion: el camión existente o recién creado.
    """
    patente = patente.upper()
    existente = session.exec(select(Camion).where(Camion.patente == patente)).first()
    if existente is not None:
        return existente
    camion = Camion(patente=patente)
    session.add(camion)
    session.flush()
    return camion


def _elegir_fecha(fechas: List[date], hoy: date) -> date:
    """
    Elige la fecha de vencimiento principal entre varias citas.

    Args:
        fechas (List[date]): todas las fechas del grupo.
        hoy (date): fecha de referencia.

    Returns:
        date: la próxima futura, o la última pasada si no hay futuras.
    """
    futuras = sorted(f for f in fechas if f >= hoy)
    return futuras[0] if futuras else max(fechas)


def importar(session: Session, ruta_csv: Path, hoy: Optional[date] = None) -> Dict:
    """
    Importa el CSV creando choferes, camiones y documentos.

    Args:
        session (Session): sesión de base de datos.
        ruta_csv (Path): ruta del archivo CSV.
        hoy (date | None): fecha de referencia; por defecto, hoy.

    Returns:
        Dict: resumen con contadores y filas no importadas.
    """
    hoy = hoy or date.today()
    filas_omitidas: List[str] = []
    # clave: (curso, "chofer"/"camion", titular) → lista de (fecha, hora, nota)
    grupos: Dict[Tuple[str, str, str], List[Tuple[date, str, Optional[str]]]] = {}

    with open(ruta_csv, newline="", encoding="utf-8-sig") as archivo:
        for fila in csv.DictReader(archivo):
            curso = (fila.get("Curso") or "").strip()
            try:
                fecha = datetime.strptime((fila.get("Fecha") or "").strip(), "%d/%m/%Y").date()
            except ValueError:
                filas_omitidas.append(f"Fecha inválida: {fila}")
                continue
            hora = (fila.get("Hora") or "").strip()
            titulares, nota = _separar_detalle((fila.get("Detalle") or "").strip())
            if curso in CURSOS_VEHICULO:
                tipo_titular = "camion"
            elif curso in CURSOS_CHOFER:
                tipo_titular = "chofer"
            else:
                filas_omitidas.append(f"{curso} ({fila.get('Fecha')}): {fila.get('Detalle')}")
                continue
            if not titulares:
                filas_omitidas.append(f"{curso} ({fila.get('Fecha')}): sin titular")
                continue
            for titular in titulares:
                clave = (curso, tipo_titular, NOMBRES_UNIFICADOS.get(titular, titular))
                grupos.setdefault(clave, []).append((fecha, hora, nota))

    contador_dni = [0]
    choferes_previos = len(session.exec(select(Chofer)).all())
    camiones_previos = len(session.exec(select(Camion)).all())
    documentos = 0

    for (curso, tipo_titular, titular), citas in sorted(grupos.items()):
        fechas = [c[0] for c in citas]
        elegida = _elegir_fecha(fechas, hoy)
        hora, nota = next((c[1], c[2]) for c in citas if c[0] == elegida)

        notas: List[str] = []
        if nota:
            notas.append(f"Nota del calendario: {nota}")
        if hora and hora.lower() != "todo el dia":
            notas.append(f"Cita: {hora}")
        otras = sorted(f for f in fechas if f != elegida)
        if otras:
            notas.append(
                "Otras fechas del calendario: "
                + ", ".join(f.strftime("%d/%m/%Y") for f in otras)
            )

        doc = Documento(
            nombre=curso,
            tipo=(CURSOS_VEHICULO.get(curso) or CURSOS_CHOFER[curso]),
            fecha_vencimiento=elegida,
            dias_aviso=DIAS_AVISO_DEFAULT,
            notas="; ".join(notas) or None,
        )
        if tipo_titular == "chofer":
            doc.chofer_id = _obtener_chofer(session, titular, contador_dni).id
        else:
            doc.camion_id = _obtener_camion(session, titular).id
        session.add(doc)
        documentos += 1

    session.commit()
    return {
        "choferes_creados": len(session.exec(select(Chofer)).all()) - choferes_previos,
        "camiones_creados": len(session.exec(select(Camion)).all()) - camiones_previos,
        "documentos_creados": documentos,
        "filas_omitidas": filas_omitidas,
    }


def main() -> None:
    """
    Punto de entrada de línea de comandos.

    Returns:
        None
    """
    if len(sys.argv) != 2:
        print("Uso: python -m gestion_flota.importar_csv archivo.csv")
        raise SystemExit(1)
    from .database import engine, init_db

    init_db()
    with Session(engine) as session:
        resumen = importar(session, Path(sys.argv[1]))
    print(resumen)


if __name__ == "__main__":
    main()
