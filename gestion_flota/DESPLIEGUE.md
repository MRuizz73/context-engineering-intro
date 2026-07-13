# 🚀 Guía de despliegue — Gestión de Flota

Cómo poner la app en marcha para que los choferes y responsables de
transporte accedan desde cualquier lugar (móvil u ordenador).

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
