// Autenticación (solo login: las cuentas las crea el responsable),
// roles y notificaciones de escritorio.

let usuarioActual = null;

// ---------- login / registro ----------

function mostrarLogin() {
  document.getElementById("pantalla-login").classList.remove("oculta");
  document.getElementById("cabecera").classList.add("oculta");
  document.getElementById("contenido").classList.add("oculta");
}

function mostrarApp(usuario) {
  usuarioActual = usuario;
  window.esAdmin = usuario.rol !== "chofer";
  document.getElementById("pantalla-login").classList.add("oculta");
  document.getElementById("cabecera").classList.remove("oculta");
  document.getElementById("contenido").classList.remove("oculta");
  document.getElementById("usuario-actual").textContent =
    `👤 ${usuario.username}${window.esAdmin ? " (responsable)" : ""}`;

  // Un chófer ve sus vencimientos, su perfil y los camiones (solo lectura).
  document.querySelectorAll('[data-vista="choferes"]')
    .forEach((t) => t.classList.toggle("oculta", !window.esAdmin));
  document.getElementById("tab-perfil").classList.toggle("oculta", window.esAdmin);
  ["btn-enviar-avisos", "btn-probar-correo", "btn-nuevo-camion"].forEach((id) =>
    document.getElementById(id).classList.toggle("oculta", !window.esAdmin)
  );

  actualizarBotonAvisos();
  cargarTodo();
  iniciarAvisos();
}

function abrirMiPerfil(tab) {
  document.querySelectorAll(".tab").forEach((t) => t.classList.remove("activa"));
  tab.classList.add("activa");
  abrirPerfil(usuarioActual.chofer_id);
}

async function enviarLogin(evento) {
  evento.preventDefault();
  const datos = {
    username: document.getElementById("login-usuario").value.trim(),
    password: document.getElementById("login-password").value,
  };
  const ruta = "api/auth/login";
  const resp = await fetch(ruta, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(datos),
  });
  if (!resp.ok) {
    let detalle = "Error al ingresar";
    try {
      const cuerpo = await resp.json();
      detalle = typeof cuerpo.detail === "string" ? cuerpo.detail : "Datos inválidos (mínimo 6 caracteres de contraseña)";
    } catch (e) { /* sin cuerpo */ }
    document.getElementById("error-login").textContent = detalle;
    return false;
  }
  const usuario = await resp.json();
  document.getElementById("login-password").value = "";
  mostrarApp(usuario);
  return false;
}

async function cerrarSesion() {
  await fetch("api/auth/logout", { method: "POST" });
  mostrarLogin();
}

async function inicializarAuth() {
  const resp = await fetch("api/auth/yo");
  if (resp.ok) {
    mostrarApp(await resp.json());
  } else {
    mostrarLogin();
  }
}

// ---------- cambio de contraseña ----------

function abrirCambioPassword() {
  document.getElementById("pass-actual").value = "";
  document.getElementById("pass-nueva").value = "";
  document.getElementById("pass-repetir").value = "";
  document.getElementById("modal-password").classList.remove("oculta");
}

function cerrarCambioPassword() {
  document.getElementById("modal-password").classList.add("oculta");
}

async function cambiarPassword(evento) {
  evento.preventDefault();
  const nueva = document.getElementById("pass-nueva").value;
  if (nueva !== document.getElementById("pass-repetir").value) {
    alert("Las contraseñas nuevas no coinciden");
    return false;
  }
  const resp = await fetch("api/auth/cambiar-password", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      password_actual: document.getElementById("pass-actual").value,
      password_nueva: nueva,
    }),
  });
  if (!resp.ok) {
    const cuerpo = await resp.json().catch(() => ({}));
    alert(cuerpo.detail || "No se pudo cambiar la contraseña");
    return false;
  }
  cerrarCambioPassword();
  alert("✅ Contraseña actualizada");
  return false;
}

// ---------- notificaciones de escritorio ----------

const INTERVALO_AVISOS_MS = 60 * 60 * 1000; // Revisa vencimientos cada hora.
let timerAvisos = null;

function actualizarBotonAvisos() {
  const btn = document.getElementById("btn-avisos");
  if (!("Notification" in window)) {
    btn.textContent = "🔕 Sin soporte de avisos";
    btn.disabled = true;
  } else if (Notification.permission === "granted") {
    btn.textContent = "🔔 Avisos activados";
    btn.disabled = true;
  } else {
    btn.textContent = "🔔 Activar avisos";
    btn.disabled = false;
  }
}

async function activarAvisos() {
  if (!("Notification" in window)) return;
  const permiso = await Notification.requestPermission();
  actualizarBotonAvisos();
  if (permiso === "granted") {
    new Notification("⛽ Gestión de Flota", {
      body: "Avisos activados: te notificaremos las caducidades de cursos y permisos.",
    });
    revisarVencimientos();
  }
}

function iniciarAvisos() {
  revisarVencimientos();
  if (timerAvisos === null) {
    timerAvisos = setInterval(revisarVencimientos, INTERVALO_AVISOS_MS);
  }
}

async function revisarVencimientos() {
  if (!("Notification" in window) || Notification.permission !== "granted") return;
  const resp = await fetch("api/vencimientos");
  if (!resp.ok) return;
  const alertas = await resp.json();
  if (alertas.length === 0) return;

  // Reason: se notifica cada documento una sola vez por día para no
  // bombardear al usuario cada vez que abre la app o corre el timer.
  const hoy = new Date().toISOString().slice(0, 10);
  const avisados = JSON.parse(localStorage.getItem("avisos-vistos") || "{}");
  const pendientes = alertas.filter((d) => avisados[d.id] !== hoy);
  if (pendientes.length === 0) return;

  const cuerpo = pendientes
    .slice(0, 5)
    .map((d) => `• ${d.nombre} (${d.titular}): ${textoDias(d)}`)
    .join("\n");
  const extra = pendientes.length > 5 ? `\n…y ${pendientes.length - 5} más.` : "";
  new Notification(`⛽ ${pendientes.length} vencimiento${pendientes.length === 1 ? "" : "s"} por atender`, {
    body: cuerpo + extra,
    tag: "gestion-flota-vencimientos",
  });

  pendientes.forEach((d) => (avisados[d.id] = hoy));
  localStorage.setItem("avisos-vistos", JSON.stringify(avisados));
}
