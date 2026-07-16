// Turismos (coches de empresa): solicitud con contrato firmado con el dedo
// y ubicación GPS, y confirmación de devolución (en la app o por el enlace
// del email). Módulo aditivo: envuelve mostrarApp/mostrarLogin sin tocarlos.

const ESTADOS_VEHICULO = {
  disponible: "Disponible",
  mantenimiento: "Mantenimiento",
  baja: "Fuera de flota",
};

let datosVehiculos = [];
let datosRegistro = [];      // registro completo (solo admin)
let misSolicitudes = [];     // mis coches sin devolver
let miPerfilTurismos = null; // datos de mi cuenta (nombre, dni, telefono, email)
let vehiculoElegido = null;
let solicitudDatos = null;   // {nombre, telefono, motivo} del paso 1
let contratoPlantilla = null;
const tokenDevolucion = new URLSearchParams(location.search).get("devolucion");
// Debe coincidir con TURISMO_PENDIENTE_DEVOLUCION del servidor.
const PENDIENTE_DEVOLUCION = "pendiente de devolución (se completa al devolverlo en la app)";

// ---------- integración con la app existente (sin modificar auth.js) ----------

const _mostrarAppOriginal = mostrarApp;
mostrarApp = function (usuario) {
  if (tokenDevolucion) {
    iniciarDevolucionPorToken();
    return;
  }
  if (usuario.rol === "vehiculos") {
    mostrarAppVehiculos(usuario);
    return;
  }
  _mostrarAppOriginal(usuario);
  // El responsable ve además la pestaña de turismos.
  document.getElementById("tab-turismos").classList.toggle("oculta", !window.esAdmin);
};

const _mostrarLoginOriginal = mostrarLogin;
mostrarLogin = function () {
  if (tokenDevolucion) {
    iniciarDevolucionPorToken();
    return;
  }
  _mostrarLoginOriginal();
};

/** Vista para el rol 'vehiculos': SOLO la sección de turismos. */
function mostrarAppVehiculos(usuario) {
  usuarioActual = usuario;
  window.esAdmin = false;
  document.getElementById("pantalla-login").classList.add("oculta");
  document.getElementById("cabecera").classList.remove("oculta");
  document.getElementById("contenido").classList.remove("oculta");
  document.getElementById("usuario-actual").textContent = `👤 ${usuario.username}`;
  document.getElementById("btn-avisos").classList.add("oculta");
  document.querySelectorAll("nav .tab").forEach((t) => {
    t.classList.toggle("oculta", t.id !== "tab-turismos");
    t.classList.toggle("activa", t.id === "tab-turismos");
  });
  document.querySelectorAll(".vista").forEach((v) => v.classList.add("oculta"));
  document.getElementById("vista-turismos").classList.remove("oculta");
  cargarTurismos();
}

// Al tocar la pestaña se recargan los datos de turismos (además de la
// navegación genérica que ya hace app.js con este mismo botón).
document.getElementById("tab-turismos").addEventListener("click", () => cargarTurismos());

// ---------- utilidades ----------

function fechaHoraLegible(s) {
  if (!s) return "—";
  const [fecha, hora] = s.split(" ");
  const [a, m, d] = fecha.split("-");
  return `${d}/${m}/${a}${hora ? " " + hora.slice(0, 5) : ""}`;
}

/** Render mínimo del contrato markdown (títulos, negritas y párrafos). */
function markdownSimple(md) {
  const html = esc(md)
    .replace(/^### (.*)$/gm, "<h5>$1</h5>")
    .replace(/^## (.*)$/gm, "<h4>$1</h4>")
    .replace(/^# (.*)$/gm, "<h3>$1</h3>")
    .replace(/^---$/gm, "<hr>")
    .replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>")
    .replace(/\*([^*\n]+)\*/g, "<em>$1</em>")
    .split(/\n{2,}/)
    .map((p) => `<p>${p.replace(/\n/g, "<br>")}</p>`)
    .join("");
  return html;
}

function cerrarModalTurismo(id) {
  document.getElementById(id).classList.add("oculta");
}

/** Pide la ubicación al navegador; si se deniega, se registra sin ella. */
function obtenerUbicacion() {
  return new Promise((resolver) => {
    if (!navigator.geolocation) {
      resolver(null);
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (p) => resolver({ lat: p.coords.latitude, lng: p.coords.longitude }),
      () => {
        alert("⚠️ No pudimos obtener tu ubicación (permiso denegado o sin señal). Se registrará sin ubicación.");
        resolver(null);
      },
      { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 }
    );
  });
}

// ---------- carga y pintado ----------

async function cargarTurismos() {
  const [vehiculos, solicitudes, perfil] = await Promise.all([
    api("api/turismos/vehiculos"),
    api("api/turismos/solicitudes"),
    api("api/turismos/yo"),
  ]);
  miPerfilTurismos = perfil && perfil.nombre ? perfil : null;
  datosVehiculos = vehiculos;
  if (window.esAdmin) {
    datosRegistro = solicitudes;
    misSolicitudes = solicitudes.filter(
      (s) => !s.devuelta && usuarioActual && s.usuario_id === usuarioActual.id
    );
    document.getElementById("turismos-acciones-admin").classList.remove("oculta");
    document.getElementById("turismos-registro").classList.remove("oculta");
    pintarRegistroTurismos();
  } else {
    misSolicitudes = solicitudes;
  }
  pintarSolicitudActiva();
  pintarVehiculos();
}

function pintarSolicitudActiva() {
  const cont = document.getElementById("turismo-solicitud-activa");
  cont.innerHTML = misSolicitudes
    .map(
      (s) => `
      <div class="tarjeta turismo-activa">
        <strong>🔑 Tienes el coche ${esc(s.matricula)}${s.modelo ? " · " + esc(s.modelo) : ""}</strong>
        <p class="item-datos">
          Solicitado el ${fechaHoraLegible(s.fecha_solicitud)}
          (${s.dias_en_uso} día${s.dias_en_uso === 1 ? "" : "s"}).
          Cuando lo devuelvas, confírmalo aquí mismo:
        </p>
        <button class="btn-marca" onclick="devolverSolicitud(${s.id}, this)">📍 Ya devolví este coche</button>
      </div>`
    )
    .join("");
}

function pintarVehiculos() {
  const cont = document.getElementById("lista-turismos");
  if (datosVehiculos.length === 0) {
    cont.innerHTML = '<div class="vacio">No hay vehículos cargados todavía.</div>';
    return;
  }
  cont.innerHTML = datosVehiculos
    .map((v) => {
      const libre = v.estado === "disponible" && !v.en_uso;
      const clase = v.en_uso ? "ocupado" : libre ? "libre" : "parado";
      const estado = v.en_uso ? "En uso" : ESTADOS_VEHICULO[v.estado] || v.estado;
      const extra =
        window.esAdmin && v.usado_por
          ? `<small>👤 ${esc(v.usado_por)} · desde ${fechaHoraLegible(v.usado_desde)}</small>`
          : "";
      const editar = window.esAdmin
        ? `<button class="btn-chico btn-editar-veh" onclick='event.stopPropagation(); editarVehiculo(${JSON.stringify(
            JSON.stringify(v)
          )})'>✏️</button>`
        : "";
      return `
      <div class="turismo-card ${clase}" ${libre ? `onclick="abrirSolicitudTurismo(${v.id})"` : ""}>
        ${editar}
        <strong>🚗 ${esc(v.matricula)}</strong>
        <span class="turismo-modelo">${esc(v.modelo || "")}</span>
        <span class="badge ${clase}">${estado}</span>
        ${extra}
      </div>`;
    })
    .join("");
}

// ---------- solicitar un coche ----------

function abrirSolicitudTurismo(id) {
  vehiculoElegido = datosVehiculos.find((v) => v.id === id);
  if (!vehiculoElegido) return;
  document.getElementById("solicitud-veh").textContent =
    vehiculoElegido.matricula + (vehiculoElegido.modelo ? " · " + vehiculoElegido.modelo : "");

  // Si la cuenta ya tiene los datos de la persona, no se le vuelven a pedir:
  // se muestran como resumen y solo se pregunta el motivo.
  const conPerfil = miPerfilTurismos && miPerfilTurismos.telefono;
  document.getElementById("sol-datos-manuales").classList.toggle("oculta", !!conPerfil);
  document.getElementById("sol-datos-perfil").classList.toggle("oculta", !conPerfil);
  if (conPerfil) {
    document.getElementById("sol-nombre").value = miPerfilTurismos.nombre;
    document.getElementById("sol-telefono").value = miPerfilTurismos.telefono;
    document.getElementById("sol-datos-perfil").textContent =
      `Solicitas como ${miPerfilTurismos.nombre}` +
      (miPerfilTurismos.dni ? ` · DNI ${miPerfilTurismos.dni}` : "") +
      ` · ☎️ ${miPerfilTurismos.telefono} (datos de tu cuenta)`;
  } else {
    // Reason: sin datos en la cuenta (p. ej. el responsable) se recuerdan
    // nombre y teléfono en el móvil para que la próxima vez sea más rápido.
    document.getElementById("sol-nombre").value = localStorage.getItem("turismo-nombre") || "";
    document.getElementById("sol-telefono").value = localStorage.getItem("turismo-telefono") || "";
  }
  document.getElementById("sol-motivo").value = "";
  document.getElementById("sol-finalidad").value = "Exclusivamente profesional";
  ["sol-km", "sol-nivel", "sol-accesorios", "sol-danos"].forEach(
    (id) => (document.getElementById(id).value = "")
  );
  document.getElementById("modal-solicitud-turismo").classList.remove("oculta");
}

async function continuarAlContrato(evento) {
  evento.preventDefault();
  solicitudDatos = {
    nombre: document.getElementById("sol-nombre").value.trim(),
    telefono: document.getElementById("sol-telefono").value.trim(),
    motivo: document.getElementById("sol-motivo").value.trim(),
    finalidad: document.getElementById("sol-finalidad").value,
    km: document.getElementById("sol-km").value.trim(),
    nivel: document.getElementById("sol-nivel").value.trim(),
    accesorios: document.getElementById("sol-accesorios").value.trim() || "Ninguno",
    danos: document.getElementById("sol-danos").value.trim() || "Sin daños anotados",
  };
  if (!miPerfilTurismos) {
    localStorage.setItem("turismo-nombre", solicitudDatos.nombre);
    localStorage.setItem("turismo-telefono", solicitudDatos.telefono);
  }

  if (contratoPlantilla === null) {
    contratoPlantilla = (await api("api/turismos/contrato")).texto;
  }
  const ahora = new Date();
  const dosDigitos = (n) => String(n).padStart(2, "0");
  const fechaHora = `${dosDigitos(ahora.getDate())}/${dosDigitos(ahora.getMonth() + 1)}/${ahora.getFullYear()} ${dosDigitos(ahora.getHours())}:${dosDigitos(ahora.getMinutes())}`;
  const p = miPerfilTurismos || {};
  const relacion = p.empleado === null || p.empleado === undefined
    ? "________"
    : p.empleado ? "☑ Empleado" : "☑ Colaborador/tercero (no empleado)";
  const texto = contratoPlantilla
    .replaceAll("{{NOMBRE_APELLIDOS}}", solicitudDatos.nombre)
    .replaceAll("{{TELEFONO}}", solicitudDatos.telefono)
    .replaceAll("{{DNI}}", p.dni || "________")
    .replaceAll("{{EMAIL}}", p.email || "________")
    .replaceAll("{{DOMICILIO}}", p.domicilio || "________")
    .replaceAll("{{PERMISO}}", p.permiso || "________")
    .replaceAll("{{CLASE}}", p.clase_permiso || "________")
    .replaceAll("{{CADUCIDAD}}", p.permiso_caduca ? fechaLegible(p.permiso_caduca) : "________")
    .replaceAll("{{RELACION}}", relacion)
    .replaceAll("{{KM}}", solicitudDatos.km)
    .replaceAll("{{NIVEL}}", solicitudDatos.nivel)
    .replaceAll("{{ACCESORIOS}}", solicitudDatos.accesorios)
    .replaceAll("{{DANOS_PREVIOS}}", solicitudDatos.danos)
    .replaceAll("{{FINALIDAD}}", solicitudDatos.finalidad)
    .replaceAll("{{MATRICULA}}", vehiculoElegido.matricula)
    .replaceAll("{{MARCA_MODELO}}", vehiculoElegido.modelo || "—")
    .replaceAll("{{FECHA_HORA_ENTREGA}}", fechaHora)
    .replaceAll("{{FECHA_HORA_DEVOLUCION}}", PENDIENTE_DEVOLUCION)
    .replaceAll("{{FECHA}}", fechaHora.slice(0, 10))
    .replace(/\{\{[A-Z_]+\}\}/g, "________");
  document.getElementById("contrato-texto").innerHTML = markdownSimple(texto);
  document.getElementById("contrato-texto").scrollTop = 0;
  ["chk-contrato", "chk-estado", "chk-datos"].forEach(
    (id) => (document.getElementById(id).checked = false)
  );
  cerrarModalTurismo("modal-solicitud-turismo");
  document.getElementById("modal-contrato").classList.remove("oculta");
  iniciarFirma();
  return false;
}

async function firmarYSolicitar(boton) {
  const faltan = ["chk-contrato", "chk-estado", "chk-datos"].some(
    (id) => !document.getElementById(id).checked
  );
  if (faltan) {
    alert("Marca las tres casillas de la declaración para poder firmar.");
    return;
  }
  if (!hayFirma) {
    alert("✍️ Firma con el dedo dentro del recuadro.");
    return;
  }
  boton.disabled = true;
  boton.textContent = "📍 Obteniendo tu ubicación…";
  try {
    const pos = await obtenerUbicacion();
    boton.textContent = "Enviando solicitud…";
    await api("api/turismos/solicitudes", {
      method: "POST",
      body: JSON.stringify({
        vehiculo_id: vehiculoElegido.id,
        nombre: solicitudDatos.nombre,
        telefono: solicitudDatos.telefono,
        motivo: solicitudDatos.motivo,
        finalidad: solicitudDatos.finalidad,
        km: solicitudDatos.km,
        nivel: solicitudDatos.nivel,
        accesorios: solicitudDatos.accesorios,
        danos: solicitudDatos.danos,
        acepta_contrato: true,
        firma: document.getElementById("firma-canvas").toDataURL("image/png"),
        lat: pos ? pos.lat : null,
        lng: pos ? pos.lng : null,
      }),
    });
    cerrarModalTurismo("modal-contrato");
    alert(`✅ Coche ${vehiculoElegido.matricula} solicitado. ¡Buen viaje!\n\nAcuérdate de confirmar la devolución en la app cuando lo devuelvas.`);
    cargarTurismos();
  } finally {
    boton.disabled = false;
    boton.textContent = "✍️ Firmar y solicitar el coche";
  }
}

// ---------- devolver un coche (desde la app) ----------

async function devolverSolicitud(id, boton) {
  if (!confirm("¿Confirmas que ya devolviste este coche?")) return;
  boton.disabled = true;
  boton.textContent = "📍 Obteniendo tu ubicación…";
  try {
    const pos = await obtenerUbicacion();
    boton.textContent = "Confirmando…";
    await api(`api/turismos/solicitudes/${id}/devolver`, {
      method: "POST",
      body: JSON.stringify({ lat: pos ? pos.lat : null, lng: pos ? pos.lng : null }),
    });
    alert("✅ Devolución registrada. ¡Gracias!");
    cargarTurismos();
  } finally {
    boton.disabled = false;
    boton.textContent = "📍 Ya devolví este coche";
  }
}

// ---------- firma con el dedo ----------

let hayFirma = false;
let firmaCtx = null;

function iniciarFirma() {
  const canvas = document.getElementById("firma-canvas");
  const escala = window.devicePixelRatio || 1;
  const ancho = canvas.clientWidth || 300;
  const alto = canvas.clientHeight || 160;
  canvas.width = Math.round(ancho * escala);
  canvas.height = Math.round(alto * escala);
  firmaCtx = canvas.getContext("2d");
  firmaCtx.scale(escala, escala);
  firmaCtx.fillStyle = "#ffffff";
  firmaCtx.fillRect(0, 0, ancho, alto);
  firmaCtx.strokeStyle = "#1c2b39";
  firmaCtx.lineWidth = 2.4;
  firmaCtx.lineCap = "round";
  firmaCtx.lineJoin = "round";
  hayFirma = false;

  if (!canvas.dataset.listo) {
    canvas.dataset.listo = "1";
    let dibujando = false;
    const punto = (e) => {
      const r = canvas.getBoundingClientRect();
      return { x: e.clientX - r.left, y: e.clientY - r.top };
    };
    canvas.addEventListener("pointerdown", (e) => {
      e.preventDefault();
      canvas.setPointerCapture(e.pointerId);
      dibujando = true;
      const p = punto(e);
      firmaCtx.beginPath();
      firmaCtx.moveTo(p.x, p.y);
      firmaCtx.lineTo(p.x + 0.1, p.y + 0.1);
      firmaCtx.stroke();
      hayFirma = true;
    });
    canvas.addEventListener("pointermove", (e) => {
      if (!dibujando) return;
      e.preventDefault();
      const p = punto(e);
      firmaCtx.lineTo(p.x, p.y);
      firmaCtx.stroke();
    });
    ["pointerup", "pointercancel"].forEach((ev) =>
      canvas.addEventListener(ev, () => (dibujando = false))
    );
  }
}

function limpiarFirma() {
  iniciarFirma();
}

// ---------- devolución por el enlace del email (sin iniciar sesión) ----------

async function iniciarDevolucionPorToken() {
  document.getElementById("pantalla-login").classList.add("oculta");
  document.getElementById("cabecera").classList.add("oculta");
  document.getElementById("contenido").classList.add("oculta");
  document.getElementById("pantalla-devolucion").classList.remove("oculta");
  const info = document.getElementById("devolucion-info");
  const boton = document.getElementById("btn-devolucion-token");
  try {
    const resp = await fetch(`api/turismos/devolucion/${encodeURIComponent(tokenDevolucion)}`);
    const s = await resp.json();
    if (!resp.ok) throw new Error(s.detail || "Enlace no válido");
    if (s.devuelta) {
      info.innerHTML = `✅ El coche <strong>${esc(s.matricula)}</strong> ya consta como devuelto. ¡Gracias!`;
      boton.classList.add("oculta");
    } else {
      info.innerHTML =
        `Hola <strong>${esc(s.nombre)}</strong>: vas a confirmar la devolución del coche ` +
        `<strong>${esc(s.matricula)}${s.modelo ? " · " + esc(s.modelo) : ""}</strong> ` +
        `que solicitaste el ${fechaHoraLegible(s.fecha_solicitud)}.`;
    }
  } catch (e) {
    info.textContent = "❌ " + (e.message || "Enlace de devolución no válido");
    boton.classList.add("oculta");
  }
}

async function confirmarDevolucionToken(boton) {
  boton.disabled = true;
  boton.textContent = "📍 Obteniendo tu ubicación…";
  const info = document.getElementById("devolucion-info");
  try {
    const pos = await obtenerUbicacion();
    boton.textContent = "Confirmando…";
    const resp = await fetch(`api/turismos/devolucion/${encodeURIComponent(tokenDevolucion)}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ lat: pos ? pos.lat : null, lng: pos ? pos.lng : null }),
    });
    const s = await resp.json();
    if (!resp.ok) throw new Error(s.detail || "No se pudo registrar la devolución");
    info.innerHTML = `✅ Devolución del coche <strong>${esc(s.matricula)}</strong> registrada. ¡Gracias!`;
    boton.classList.add("oculta");
  } catch (e) {
    document.getElementById("error-devolucion").textContent = e.message;
    boton.disabled = false;
    boton.textContent = "📍 Confirmar devolución";
  }
}
