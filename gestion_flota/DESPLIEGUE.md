# 🚀 Guía de despliegue — Gestión de Flota

Cómo poner la app en marcha para que los choferes y responsables de
transporte accedan desde cualquier lugar (móvil u ordenador).

## En el servidor propio de la empresa (PHP + MySQL)

La app convive sin problema con el PHP existente y **usa el MySQL de la
empresa**: basta configurar
`DATABASE_URL=mysql+pymysql://usuario:clave@localhost/gestion_flota` en el
`.env`. En el primer arranque crea las tablas e importa los datos solos.

### A) Servidor con SSH (VPS/dedicado con LAMP)

```bash
# 1. Base de datos (una sola vez)
mysql -u root -p -e "
  CREATE DATABASE gestion_flota CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'flota'@'localhost' IDENTIFIED BY 'UNA-CLAVE-SEGURA';
  GRANT ALL PRIVILEGES ON gestion_flota.* TO 'flota'@'localhost';"

# 2. Código y dependencias (Python 3.10+)
cd /opt && git clone https://github.com/MRuizz73/context-engineering-intro.git flota
cd flota && python3 -m venv venv_linux && ./venv_linux/bin/pip install -r requirements.txt

# 3. Configuración
cp .env.example .env && nano .env
#   DATABASE_URL=mysql+pymysql://flota:UNA-CLAVE-SEGURA@localhost/gestion_flota
#   SMTP_* (correo de la empresa) y CODIGO_REGISTRO

# 4. Servicio (arranca solo al reiniciar el servidor)
sudo tee /etc/systemd/system/flota.service > /dev/null <<'UNIT'
[Unit]
Description=Gestion de Flota
After=network.target mysql.service
[Service]
WorkingDirectory=/opt/flota
ExecStart=/opt/flota/venv_linux/bin/uvicorn gestion_flota.main:app --host 127.0.0.1 --port 8000
Restart=always
[Install]
WantedBy=multi-user.target
UNIT
sudo systemctl enable --now flota
```

Y en el Apache que ya sirve el PHP, un subdominio con proxy
(`a2enmod proxy proxy_http` una vez):

```apache
<VirtualHost *:80>
    ServerName flota.tuempresa.com
    ProxyPreserveHost On
    ProxyPass / http://127.0.0.1:8000/
    ProxyPassReverse / http://127.0.0.1:8000/
</VirtualHost>
```

HTTPS con `certbot --apache -d flota.tuempresa.com`. El PHP existente sigue
funcionando igual: solo se añade un subdominio.

### B) Hosting compartido cPanel/Plesk (sin SSH root)

Requiere que el panel tenga **"Setup Python App"** (Passenger); la mayoría
de los cPanel modernos lo traen:

1. **MySQL Databases** en cPanel: crear la base `gestion_flota`, un usuario
   y darle todos los permisos.
2. **Setup Python App**: crear una aplicación Python 3.10+, apuntando al
   directorio del proyecto y con *startup file* `passenger_wsgi.py`
   (incluido en el repo).
3. Subir el código (Git Version Control o zip) y en la terminal del panel:
   `pip install -r requirements.txt`.
4. Crear el `.env` con `DATABASE_URL=mysql+pymysql://...` (datos del paso 1),
   SMTP y `CODIGO_REGISTRO`.
5. Reiniciar la app desde el panel: al primer arranque se crean tablas,
   datos y cuentas.
6. **Cron Jobs** (los emails automáticos en modo Passenger van por cron):
   una tarea diaria con
   `/ruta/al/venv/bin/python -m gestion_flota.enviar_avisos`
   ejecutada desde el directorio del proyecto.

Si el hosting NO tiene soporte Python, la app no puede correr ahí: las
opciones son un VPS aparte (puede seguir usando el MySQL de la empresa si
está accesible) o el resto de alternativas de abajo.

## Opción recomendada: un VPS con Docker (~5 €/mes)

Un VPS es un pequeño servidor alquilado (Hetzner, DigitalOcean, OVH,
Clouding.io…). Pasos completos partiendo de un VPS con Ubuntu:

```bash
# 1. Instalar Docker (una sola vez)
curl -fsSL https://get.docker.com | sh

# 2. Clonar el repositorio
git clone https://github.com/MRuizz73/context-engineering-intro.git
cd context-engineering-intro

# 3. Configurar el correo de la empresa y el código de registro
cp .env.example .env
nano .env       # completar SMTP_* y añadir: CODIGO_REGISTRO=algo-secreto

# 4. Levantar la app
docker compose up -d --build
```

La app queda en `http://IP-DEL-SERVIDOR:8000`. En el primer arranque se
importan automáticamente los datos del CSV de la empresa.

### HTTPS con dominio propio (muy recomendado)

Comprá un dominio (p. ej. `flota.tuempresa.com`, apuntá un registro A a la
IP del VPS) e instalá Caddy, que gestiona el certificado HTTPS solo:

```bash
sudo apt install -y caddy
```

`/etc/caddy/Caddyfile`:

```
flota.tuempresa.com {
    reverse_proxy localhost:8000
}
```

```bash
sudo systemctl reload caddy
```

Listo: la app queda en `https://flota.tuempresa.com` con candado. HTTPS es
imprescindible para que las contraseñas y los datos viajen cifrados y para
que las notificaciones de escritorio funcionen fuera de localhost.

## Alternativas

- **Solo red de la oficina (gratis)**: ejecutar la app en un PC de la
  oficina (`uvicorn gestion_flota.main:app --host 0.0.0.0 --port 8000`) y
  entrar desde otros equipos con `http://IP-DEL-PC:8000`. Los choferes NO
  podrán entrar desde fuera.
- **Acceso remoto privado sin dominio (gratis)**: instalar
  [Tailscale](https://tailscale.com) en el servidor y en los móviles de los
  choferes; crea una red privada cifrada sin abrir puertos.
- **PaaS (Render, Railway, Fly.io)**: despliegue desde el repositorio con el
  Dockerfile incluido, sin administrar servidor. Configurar las variables de
  entorno del `.env` en su panel y un disco persistente montado en `/data`
  (si no, la base se borra en cada redeploy).

## Alta de usuarios (choferes y responsables)

**Cuentas ya creadas**: en el primer arranque se crean las cuentas de
`gestion_flota/cuentas_iniciales.csv` (admin + un usuario por chofer).
Repartí las credenciales del Excel y pedí a cada uno que cambie su
contraseña con el botón 🔑 al entrar. Después **borrá ese CSV del
servidor/repositorio**.

**Choferes nuevos**: el admin los crea desde la pestaña Choferes con la
casilla "🔐 Generar también su cuenta de acceso" (o el botón *🔐 Cuenta*
en un chofer existente) y entrega el usuario/contraseña generados.

**Registro manual (opcional)**: con `CODIGO_REGISTRO` definido, quien tenga
el código puede registrarse como responsable; un chofer puede registrarse
eligiendo "Chofer" e indicando el email cargado en su perfil (con
`CODIGO_CHOFER` podés darles un código distinto al de los responsables).

En el móvil: abrir la URL en el navegador y usar **"Añadir a pantalla de
inicio"** para tenerla como una app.

## Checklist de seguridad y mantenimiento

- [ ] `CODIGO_REGISTRO` definido antes de exponer la app a internet.
- [ ] HTTPS activo (Caddy o equivalente).
- [ ] SMTP configurado y probado con *📧 Enviar recordatorios por email*.
- [ ] Copia de seguridad de la base: `docker compose cp app:/data/gestion_flota.db ./backup-$(date +%F).db`
  (un cron semanal alcanza).
- [ ] Actualizaciones: `git pull && docker compose up -d --build`.
