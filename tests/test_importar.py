"""Tests del importador de CSV de cursos y vencimientos."""

from datetime import date
from pathlib import Path

from sqlmodel import Session, select

from gestion_flota.importar_csv import importar
from gestion_flota.models import Camion, Chofer, Documento

CSV_REAL = Path(__file__).parent.parent / "gestion_flota" / "datos_iniciales.csv"


def _escribir_csv(tmp_path: Path, filas: str) -> Path:
    """
    Crea un CSV temporal con cabecera estándar.

    Args:
        tmp_path (Path): directorio temporal de pytest.
        filas (str): filas del CSV (sin cabecera).

    Returns:
        Path: ruta del archivo creado.
    """
    ruta = tmp_path / "datos.csv"
    ruta.write_text("Curso,Fecha,Hora,Detalle\n" + filas, encoding="utf-8")
    return ruta


def test_importa_chofer_y_camion(session: Session, tmp_path: Path):
    """Caso esperado: crea el chofer, el camión y sus documentos."""
    ruta = _escribir_csv(
        tmp_path,
        "ITV,30/01/2027,11:00-12:00,R8197BCY\n"
        "Certificado Medico,01/04/2027,09:30-10:30,Samuel Alonso (caduca en 3 dias)\n",
    )
    resumen = importar(session, ruta, hoy=date(2026, 7, 13))
    assert resumen == {
        "choferes_creados": 1,
        "camiones_creados": 1,
        "documentos_creados": 2,
        "filas_omitidas": [],
    }
    chofer = session.exec(select(Chofer)).one()
    assert (chofer.nombre, chofer.apellido) == ("Samuel", "Alonso")
    assert chofer.dni.startswith("PTE-")
    camion = session.exec(select(Camion)).one()
    assert camion.patente == "R8197BCY"
    doc = session.exec(select(Documento).where(Documento.chofer_id == chofer.id)).one()
    assert "caduca en 3 dias" in doc.notas


def test_titulares_multiples_y_dedupe(session: Session, tmp_path: Path):
    """Caso borde: matrículas separadas por "/" y citas repetidas del mismo curso."""
    ruta = _escribir_csv(
        tmp_path,
        "Seguro,25/01/2027,11:00-12:00,E0047MTP/E0048MTP\n"
        "ITV,05/08/2026,10:00-11:00,E0047MTP\n"
        "ITV,07/08/2026,10:00-11:00,E0047MTP\n",
    )
    resumen = importar(session, ruta, hoy=date(2026, 7, 13))
    assert resumen["camiones_creados"] == 2
    # 2 seguros (uno por camión) + 1 sola ITV con la fecha más próxima.
    assert resumen["documentos_creados"] == 3
    itv = session.exec(select(Documento).where(Documento.nombre == "ITV")).one()
    assert itv.fecha_vencimiento == date(2026, 8, 5)
    assert "07/08/2026" in itv.notas


def test_nombres_unificados(session: Session, tmp_path: Path):
    """Caso borde: variantes del mismo nombre no crean choferes duplicados."""
    ruta = _escribir_csv(
        tmp_path,
        "CAP Conductor,01/10/2026,13:30-14:30,Raul Ivan Rodriguez (desde hoy 6 meses)\n"
        "Certificado Medico,16/04/2027,09:30-10:30,Raul Rodriguez (caduca en 7 dias)\n",
    )
    resumen = importar(session, ruta, hoy=date(2026, 7, 13))
    assert resumen["choferes_creados"] == 1


def test_filas_no_reconocidas_se_reportan(session: Session, tmp_path: Path):
    """Caso de fallo: cursos desconocidos o fechas inválidas no cortan la importación."""
    ruta = _escribir_csv(
        tmp_path,
        "Tarjetas de Transporte,30/06/2027,11:00-12:00,Todas las tarjetas de transporte\n"
        "ITV,fecha-rota,11:00-12:00,R8197BCY\n"
        "ITV,30/01/2027,11:00-12:00,R8197BCY\n",
    )
    resumen = importar(session, ruta, hoy=date(2026, 7, 13))
    assert resumen["documentos_creados"] == 1
    assert len(resumen["filas_omitidas"]) == 2


def test_csv_real_completo(session: Session):
    """Caso esperado: el CSV real de la empresa importa sin errores graves."""
    resumen = importar(session, CSV_REAL, hoy=date(2026, 7, 13))
    assert resumen["choferes_creados"] == 15
    assert resumen["camiones_creados"] > 40
    assert resumen["documentos_creados"] > 100
    # Solo debería omitirse la fila general de tarjetas de transporte.
    assert len(resumen["filas_omitidas"]) == 1
    assert "Tarjetas de Transporte" in resumen["filas_omitidas"][0]
