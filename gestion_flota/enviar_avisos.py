"""Envío de recordatorios por línea de comandos (para cron en hostings).

Uso:
    python -m gestion_flota.enviar_avisos

Pensado para servidores donde la app corre como WSGI (cPanel/Passenger) y no
existe el bucle automático de avisos: se programa un cron diario que ejecuta
este módulo. Respeta la misma regla de frecuencia (AVISO_EMAIL_CADA_DIAS).
"""

from sqlmodel import Session

from . import correo
from .database import engine, init_db


def main() -> None:
    """
    Envía los recordatorios pendientes e imprime el resumen.

    Returns:
        None
    """
    init_db()
    if not correo.smtp_configurado():
        print("SMTP no configurado: definí SMTP_HOST y SMTP_USER en .env")
        raise SystemExit(1)
    with Session(engine) as session:
        print(correo.enviar_recordatorios(session))


if __name__ == "__main__":
    main()
