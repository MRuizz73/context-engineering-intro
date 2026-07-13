// Lógica de la interfaz de Gestión de Flota (choferes, camiones y documentos).

const TIPOS = {
  curso: "Curso",
  permiso: "Permiso",
  licencia: "Licencia",
  seguro: "Seguro",
  revision_tecnica: "Revisión técnica",
  otro: "Otro",
};

const ESTADOS = {
  vigente: "Vigente",
  por_vencer: "Por vencer",
  vencido: "Vencido",
};

// ---------- utilidades ----------

async function api(ruta, opciones = {}) {
  const resp = await fetch(ruta, {
    headers: { "Content-Type": "application/json" },
    ...opciones,
  });
  if (resp.status === 401) {
    mostrarLogin();
    throw new Error("Sesión expirada");
  }
  if (!resp.ok) {
    let detalle = "Error en la operación";
    try {
      const cuerpo = await resp.json();
      detalle = typeof cuerpo.detail === "string" ? cuerpo.detail : JSON.stringify(cuerpo.detail);
    } catch (e) { /* respuesta sin cuerpo */ }
    alert(detalle);
    throw new Error(detalle);
  }
  return resp.status === 204 ? null : resp.json();
}

function esc(texto) {
  const div = document.createElement("div");
  div.textContent = texto == null ? "" : String(texto);
  return div.innerHTML;
}

function fechaLegible(iso) {
  if (!iso) return "—";
  const [a, m, d] = iso.split("-");
  return `${d}/${m}/${a}`;
}

function textoDias(doc) {
  if (doc.dias_restantes < 0) {
    const dias = Math.abs(doc.dias_restantes);
    return `Venció hace ${dias} día${dias === 1 ? "" : "s"}`;
  }
  if (doc.dias_restantes === 0) return "¡Vence hoy!";
  return `Vence en ${doc.dias_restantes} día${doc.dias_restantes === 1 ? "" : "s"}`;
}

// ---------- navegación ----------

document.querySelectorAll(".tab").forEach((tab) => {
  tab.addEventListener("click", () => {
    document.querySelectorAll(".tab").forEach((t) => t.classList.remove("activa"));
    tab.classList.add("activa");
    document.querySelectorAll(".vista").forEach((v) => v.classList.add("oculta"));
    document.getElementById(`vista-${tab.dataset.vista}`).classList.remove("oculta");
    cargarTodo();
  });
});

function cerrarForms() {
  document.getElementById("form-chofer").classList.add("oculta");
  document.getElementById("form-camion").classList.add("oculta");
}

// ---------- vencimientos ----------

async function cargarVencimientos() {
  const alertas = await api("/api/vencimientos");
  const cont = document.getElementById("lista-vencimientos");
  if (alertas.length === 0) {
    cont.innerHTML = '<div class="vacio">✅ No hay vencimientos pendientes. Todo al día.</div>';
    return;
  }
  cont.innerHTML = alertas
    .map(
      (d) => `
      <div class="tarjeta alerta ${d.estado}">
        <div class="item-cabecera">
          <div>
            <strong>${esc(d.nombre)}</strong>
            <span class="badge ${d.estado}">${ESTADOS[d.estado]}</span>
          </div>
          <div class="acciones" style="margin:0">
            <strong style="margin-right:0.6rem">${textoDias(d)}</strong>
            <button class="btn-chico btn-marca" onclick='abrirRenovar(${JSON.stringify(JSON.stringify(d))})'>
              ✔ ${d.tipo === "curso" ? "Curso renovado" : "Permiso renovado"}
            </button>
          </div>
        </div>
        <p class="item-datos">
          ${esc(TIPOS[d.tipo] || d.tipo)} · ${esc(d.titular)} ·
          Vencimiento: ${fechaLegible(d.fecha_vencimiento)} ·
          Aviso: ${d.dias_aviso} días antes ·
          📧 ${destinoEmail(d)}
        </p>
      </div>`
    )
    .join("");
}

function destinoEmail(d) {
  if (d.email_destino) return esc(d.email_destino);
  if (d.camion_id) return "email de la empresa";
  return "⚠️ chofer sin email cargado";
}

// ---------- envío manual de recordatorios ----------

async function enviarRecordatorios(boton) {
  boton.disabled = true;
  boton.textContent = "Enviando…";
  try {
    const resumen = await api("/api/avisos/enviar", { method: "POST" });
    const detalle =
      resumen.documentos_avisados === 0
        ? "No había avisos pendientes de enviar (ya se avisaron o no hay vencimientos)."
        : `Se enviaron ${resumen.emails_enviados} email(s) cubriendo ${resumen.documentos_avisados} documento(s).`;
    alert(`📧 ${detalle}`);
  } finally {
    boton.disabled = false;
    boton.textContent = "📧 Enviar recordatorios por email";
  }
}

// ---------- renovación ----------

function abrirRenovar(docJson) {
  const d = JSON.parse(docJson);
  document.getElementById("renovar-id").value = d.id;
  document.getElementById("titulo-renovar").textContent =
    d.tipo === "curso" ? "Confirmar curso renovado" : "Confirmar permiso renovado";
  document.getElementById("renovar-info").textContent =
    `${d.nombre} — ${d.titular}. Al confirmar, la alerta se quita y arranca la nueva vigencia.`;
  const hoy = new Date().toISOString().slice(0, 10);
  document.getElementById("renovar-emision").value = hoy;
  document.getElementById("renovar-vencimiento").value = "";
  document.getElementById("modal-renovar").classList.remove("oculta");
}

function cerrarRenovar() {
  document.getElementById("modal-renovar").classList.add("oculta");
}

async function confirmarRenovacion(evento) {
  evento.preventDefault();
  const id = document.getElementById("renovar-id").value;
  await api(`/api/documentos/${id}/renovar`, {
    method: "POST",
    body: JSON.stringify({
      fecha_vencimiento: document.getElementById("renovar-vencimiento").value,
      fecha_emision: document.getElementById("renovar-emision").value || null,
    }),
  });
  cerrarRenovar();
  cargarTodo();
  return false;
}

// ---------- documentos (tabla compartida) ----------

function tablaDocumentos(docs, tipoTitular, titularId) {
  if (docs.length === 0) {
    return '<p class="item-datos">Sin documentos cargados.</p>';
  }
  const filas = docs
    .map(
      (d) => `
      <tr>
        <td>${esc(d.nombre)}</td>
        <td>${esc(TIPOS[d.tipo] || d.tipo)}</td>
        <td>${fechaLegible(d.fecha_vencimiento)}</td>
        <td>${d.dias_aviso} días</td>
        <td><span class="badge ${d.estado}">${ESTADOS[d.estado]}</span> <small>${esc(textoDias(d))}</small></td>
        <td>
          ${d.estado !== "vigente" ? `<button class="btn-chico btn-marca" onclick='abrirRenovar(${JSON.stringify(JSON.stringify(d))})'>✔ Renovado</button>` : ""}
          <button class="btn-chico" onclick='abrirFormDoc(${JSON.stringify(JSON.stringify(d))}, "${tipoTitular}", ${titularId})'>Editar</button>
          <button class="btn-chico btn-peligro" onclick="borrarDocumento(${d.id})">Borrar</button>
        </td>
      </tr>`
    )
    .join("");
  return `
    <table>
      <thead><tr><th>Documento</th><th>Tipo</th><th>Vence</th><th>Aviso</th><th>Estado</th><th></th></tr></thead>
      <tbody>${filas}</tbody>
    </table>`;
}

// ---------- choferes ----------

async function cargarChoferes() {
  const choferes = await api("/api/choferes");
  const cont = document.getElementById("lista-choferes");
  if (choferes.length === 0) {
    cont.innerHTML = '<div class="vacio">Todavía no hay choferes. Creá el primero con "+ Nuevo chofer".</div>';
    return;
  }
  cont.innerHTML = choferes
    .map(
      (c) => `
      <div class="tarjeta">
        <div class="item-cabecera">
          <div>
            <strong>${esc(c.apellido)}, ${esc(c.nombre)}</strong>
            ${c.activo ? "" : '<span class="badge inactivo">Inactivo</span>'}
          </div>
          <div class="acciones" style="margin:0">
            <button class="btn-chico btn-primario" onclick="abrirFormDoc(null, 'chofer', ${c.id}, '${esc(c.apellido)}, ${esc(c.nombre)}')">+ Curso/Permiso</button>
            <button class="btn-chico" onclick='editarChofer(${JSON.stringify(JSON.stringify(c))})'>Editar</button>
            <button class="btn-chico btn-peligro" onclick="borrarChofer(${c.id})">Borrar</button>
          </div>
        </div>
        <p class="item-datos">
          DNI: ${esc(c.dni)}${c.telefono ? " · Tel: " + esc(c.telefono) : ""}
          ${c.email ? " · 📧 " + esc(c.email) : ' · <span class="badge vencido">⚠️ Sin email para recordatorios</span>'}
        </p>
        ${tablaDocumentos(c.documentos, "chofer", c.id)}
      </div>`
    )
    .join("");
}

function abrirFormChofer() {
  cerrarForms();
  document.getElementById("titulo-form-chofer").textContent = "Nuevo chofer";
  document.getElementById("chofer-id").value = "";
  ["nombre", "apellido", "dni", "telefono", "email"].forEach(
    (campo) => (document.getElementById(`chofer-${campo}`).value = "")
  );
  document.getElementById("chofer-activo").checked = true;
  document.getElementById("form-chofer").classList.remove("oculta");
}

function editarChofer(json) {
  const c = JSON.parse(json);
  abrirFormChofer();
  document.getElementById("titulo-form-chofer").textContent = "Editar chofer";
  document.getElementById("chofer-id").value = c.id;
  document.getElementById("chofer-nombre").value = c.nombre;
  document.getElementById("chofer-apellido").value = c.apellido;
  document.getElementById("chofer-dni").value = c.dni;
  document.getElementById("chofer-telefono").value = c.telefono || "";
  document.getElementById("chofer-email").value = c.email || "";
  document.getElementById("chofer-activo").checked = c.activo;
}

async function guardarChofer(evento) {
  evento.preventDefault();
  const id = document.getElementById("chofer-id").value;
  const datos = {
    nombre: document.getElementById("chofer-nombre").value.trim(),
    apellido: document.getElementById("chofer-apellido").value.trim(),
    dni: document.getElementById("chofer-dni").value.trim(),
    telefono: document.getElementById("chofer-telefono").value.trim() || null,
    email: document.getElementById("chofer-email").value.trim() || null,
    activo: document.getElementById("chofer-activo").checked,
  };
  if (id) {
    await api(`/api/choferes/${id}`, { method: "PUT", body: JSON.stringify(datos) });
  } else {
    await api("/api/choferes", { method: "POST", body: JSON.stringify(datos) });
  }
  cerrarForms();
  cargarTodo();
  return false;
}

async function borrarChofer(id) {
  if (!confirm("¿Eliminar este chofer y todos sus documentos?")) return;
  await api(`/api/choferes/${id}`, { method: "DELETE" });
  cargarTodo();
}

// ---------- camiones ----------

async function cargarCamiones() {
  const camiones = await api("/api/camiones");
  const cont = document.getElementById("lista-camiones");
  if (camiones.length === 0) {
    cont.innerHTML = '<div class="vacio">Todavía no hay camiones. Creá el primero con "+ Nuevo camión".</div>';
    return;
  }
  cont.innerHTML = camiones
    .map(
      (c) => `
      <div class="tarjeta">
        <div class="item-cabecera">
          <div>
            <strong>🚛 ${esc(c.patente)}</strong>
            ${c.activo ? "" : '<span class="badge inactivo">Inactivo</span>'}
          </div>
          <div class="acciones" style="margin:0">
            <button class="btn-chico btn-primario" onclick="abrirFormDoc(null, 'camion', ${c.id}, 'Camión ${esc(c.patente)}')">+ Permiso</button>
            <button class="btn-chico" onclick='editarCamion(${JSON.stringify(JSON.stringify(c))})'>Editar</button>
            <button class="btn-chico btn-peligro" onclick="borrarCamion(${c.id})">Borrar</button>
          </div>
        </div>
        <p class="item-datos">
          ${esc(c.marca || "")} ${esc(c.modelo || "")}${c.anio ? " · Año " + c.anio : ""}
        </p>
        ${tablaDocumentos(c.documentos, "camion", c.id)}
      </div>`
    )
    .join("");
}

function abrirFormCamion() {
  cerrarForms();
  document.getElementById("titulo-form-camion").textContent = "Nuevo camión";
  document.getElementById("camion-id").value = "";
  ["patente", "marca", "modelo", "anio"].forEach(
    (campo) => (document.getElementById(`camion-${campo}`).value = "")
  );
  document.getElementById("camion-activo").checked = true;
  document.getElementById("form-camion").classList.remove("oculta");
}

function editarCamion(json) {
  const c = JSON.parse(json);
  abrirFormCamion();
  document.getElementById("titulo-form-camion").textContent = "Editar camión";
  document.getElementById("camion-id").value = c.id;
  document.getElementById("camion-patente").value = c.patente;
  document.getElementById("camion-marca").value = c.marca || "";
  document.getElementById("camion-modelo").value = c.modelo || "";
  document.getElementById("camion-anio").value = c.anio || "";
  document.getElementById("camion-activo").checked = c.activo;
}

async function guardarCamion(evento) {
  evento.preventDefault();
  const id = document.getElementById("camion-id").value;
  const anio = document.getElementById("camion-anio").value;
  const datos = {
    patente: document.getElementById("camion-patente").value.trim().toUpperCase(),
    marca: document.getElementById("camion-marca").value.trim() || null,
    modelo: document.getElementById("camion-modelo").value.trim() || null,
    anio: anio ? parseInt(anio, 10) : null,
    activo: document.getElementById("camion-activo").checked,
  };
  if (id) {
    await api(`/api/camiones/${id}`, { method: "PUT", body: JSON.stringify(datos) });
  } else {
    await api("/api/camiones", { method: "POST", body: JSON.stringify(datos) });
  }
  cerrarForms();
  cargarTodo();
  return false;
}

async function borrarCamion(id) {
  if (!confirm("¿Eliminar este camión y todos sus documentos?")) return;
  await api(`/api/camiones/${id}`, { method: "DELETE" });
  cargarTodo();
}

// ---------- documentos ----------

function abrirFormDoc(docJson, tipoTitular, titularId, nombreTitular) {
  const doc = docJson ? JSON.parse(docJson) : null;
  document.getElementById("titulo-form-doc").textContent = doc
    ? "Editar documento"
    : "Nuevo curso/permiso";
  document.getElementById("doc-titular").textContent = doc
    ? `Titular: ${doc.titular}`
    : `Titular: ${nombreTitular}`;
  document.getElementById("doc-id").value = doc ? doc.id : "";
  document.getElementById("doc-chofer-id").value =
    tipoTitular === "chofer" ? titularId : "";
  document.getElementById("doc-camion-id").value =
    tipoTitular === "camion" ? titularId : "";
  document.getElementById("doc-nombre").value = doc ? doc.nombre : "";
  document.getElementById("doc-tipo").value = doc ? doc.tipo : (tipoTitular === "camion" ? "permiso" : "curso");
  document.getElementById("doc-emision").value = doc && doc.fecha_emision ? doc.fecha_emision : "";
  document.getElementById("doc-vencimiento").value = doc ? doc.fecha_vencimiento : "";
  document.getElementById("doc-aviso").value = doc ? doc.dias_aviso : 30;
  document.getElementById("doc-notas").value = doc && doc.notas ? doc.notas : "";
  document.getElementById("modal-doc").classList.remove("oculta");
}

function cerrarModal() {
  document.getElementById("modal-doc").classList.add("oculta");
}

async function guardarDocumento(evento) {
  evento.preventDefault();
  const id = document.getElementById("doc-id").value;
  const choferId = document.getElementById("doc-chofer-id").value;
  const camionId = document.getElementById("doc-camion-id").value;
  const datos = {
    nombre: document.getElementById("doc-nombre").value.trim(),
    tipo: document.getElementById("doc-tipo").value,
    fecha_emision: document.getElementById("doc-emision").value || null,
    fecha_vencimiento: document.getElementById("doc-vencimiento").value,
    dias_aviso: parseInt(document.getElementById("doc-aviso").value, 10),
    notas: document.getElementById("doc-notas").value.trim() || null,
    chofer_id: choferId ? parseInt(choferId, 10) : null,
    camion_id: camionId ? parseInt(camionId, 10) : null,
  };
  if (id) {
    await api(`/api/documentos/${id}`, { method: "PUT", body: JSON.stringify(datos) });
  } else {
    await api("/api/documentos", { method: "POST", body: JSON.stringify(datos) });
  }
  cerrarModal();
  cargarTodo();
  return false;
}

async function borrarDocumento(id) {
  if (!confirm("¿Eliminar este documento?")) return;
  await api(`/api/documentos/${id}`, { method: "DELETE" });
  cargarTodo();
}

// ---------- inicio ----------

function cargarTodo() {
  cargarVencimientos();
  cargarChoferes();
  cargarCamiones();
}

inicializarAuth();
