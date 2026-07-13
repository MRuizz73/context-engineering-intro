"""Punto de entrada para hostings cPanel/Plesk con Passenger (WSGI).

Passenger no ejecuta el ciclo de vida ASGI, así que la inicialización de la
base y la siembra de datos se hacen acá. Los emails automáticos en este tipo
de hosting se programan con un cron: `python -m gestion_flota.enviar_avisos`.
"""

from a2wsgi import ASGIMiddleware

from gestion_flota.database import init_db
from gestion_flota.main import _sembrar_datos_iniciales, app

init_db()
_sembrar_datos_iniciales()

application = ASGIMiddleware(app)
