// Turismos — parte del responsable (admin): registro auditable y exportable,
// alta/edición de vehículos y creación de usuarios de vehículos.

// ---------- registro de uso (auditoría) ----------

function pintarRegistroTurismos() {
  const filtro = document.getElementById("registro-filtro").value;
  const mes = document.getElementById("registro-mes").value; // "2026-07"
  let lista = datosRegistro;
  if (filtro === "en_uso") lista = lista.filter((s) => !s.devuelta);
  if (filtro === "devueltas") lista = lista.filter((s) => s.devuelta);
  if (mes) lista = lista.filter((s) => s.fecha_solicitud.startsWith(mes));

  const cont = document.getElementById("lista-registro-turismos");
  if (lista.length === 0) {
    cont.innerHTML = '<div class="vacio">No hay solicitudes con ese filtro.</div>';
    return;
  }
  cont.innerHTML = lista
    .map((s) => {
      const estado = s.devuelta
        ? `<span class="badge libre">Devuelto ${fechaHoraLegible(s.fecha_devolucion)}${s.devuelto_via === "email" ? " (por email)" : ""}</span>`
        : `<span class="badge ocupado">En uso · ${s.dias_en_uso} día${s.dias_en_uso === 1 ? "" : "s"}</span>`;
      return `
      <div class="tarjeta registro-item">
        <div class="item-cabecera">
          <div>
            <strong>🚗 ${esc(s.matricula)}</strong> ${esc(s.modelo || "")} ${estado}
          </div>
          <div class="acciones" style="margin:0">
            <button class="btn-chico" onclick="verContratoSolicitud(${s.id})">📄 Contrato</button>
            ${s.devuelta ? "" : `<button class="btn-chico btn-marca" onclick="devolverSolicitud(${s.id}, this)">📍 Marcar devuelto</button>`}
          </div>
        </div>
        <p class="item-datos">
          👤 ${esc(s.nombre)} · ☎️ ${esc(s.telefono)} · Motivo: ${esc(s.motivo)}<br>
          Recogida: ${fechaHoraLegible(s.fecha_solicitud)} ${enlaceMapa(s.lat_solicitud, s.lng_solicitud)}
          ${s.devuelta ? `· Devolución: ${fechaHoraLegible(s.fecha_devolucion)} ${enlaceMapa(s.lat_devolucion, s.lng_devolucion)}` : ""}
        </p>
      </div>`;
    })
    .join("");
}

function enlaceMapa(lat, lng) {
  if (lat == null || lng == null) return '<small>(sin ubicación)</small>';
  return `<a href="https://maps.google.com/?q=${lat},${lng}" target="_blank" rel="noopener">📍 ver en el mapa</a>`;
}

/** Exporta el registro filtrado a CSV (compatible con Excel es-ES). */
function exportarRegistroTurismos() {
  const filtro = document.getElementById("registro-filtro").value;
  const mes = document.getElementById("registro-mes").value;
  let lista = datosRegistro;
  if (filtro === "en_uso") lista = lista.filter((s) => !s.devuelta);
  if (filtro === "devueltas") lista = lista.filter((s) => s.devuelta);
  if (mes) lista = lista.filter((s) => s.fecha_solicitud.startsWith(mes));

  const campo = (v) => `"${String(v == null ? "" : v).replace(/"/g, '""')}"`;
  const coord = (lat, lng) => (lat == null || lng == null ? "" : `${lat},${lng}`);
  const filas = [
    ["Matrícula", "Modelo", "Nombre", "DNI", "Teléfono", "Motivo", "Finalidad",
     "Km entrega", "Carga %", "Accesorios", "Daños previos", "Fecha solicitud",
     "Ubicación solicitud", "Fecha devolución", "Ubicación devolución",
     "Devuelto vía", "Días de uso", "Estado"],
    ...lista.map((s) => [
      s.matricula, s.modelo || "", s.nombre, s.dni || "", s.telefono, s.motivo,
      s.finalidad || "", s.km || "", s.nivel || "", s.accesorios || "", s.danos || "",
      s.fecha_solicitud, coord(s.lat_solicitud, s.lng_solicitud),
      s.fecha_devolucion || "", coord(s.lat_devolucion, s.lng_devolucion),
      s.devuelto_via || "", s.dias_en_uso, s.devuelta ? "Devuelto" : "En uso",
    ]),
  ];
  // Reason: BOM + punto y coma para que Excel en español lo abra en columnas.
  const csv = "﻿" + filas.map((f) => f.map(campo).join(";")).join("\r\n");
  const enlace = document.createElement("a");
  enlace.href = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
  enlace.download = `registro_turismos_${new Date().toISOString().slice(0, 10)}.csv`;
  enlace.click();
  URL.revokeObjectURL(enlace.href);
}

/** Abre el contrato firmado de una solicitud (prueba para auditorías). */
async function verContratoSolicitud(id) {
  const s = await api(`api/turismos/solicitudes/${id}`);
  document.getElementById("detalle-solicitud-info").textContent =
    `${s.nombre} · ${s.matricula} · solicitado el ${fechaHoraLegible(s.fecha_solicitud)}` +
    (s.ip ? ` · IP ${s.ip}` : "");
  document.getElementById("detalle-contrato").innerHTML = markdownSimple(s.contrato || "(sin contrato archivado)");
  const img = document.getElementById("detalle-firma");
  if (s.firma) {
    img.src = s.firma;
    img.classList.remove("oculta");
  } else {
    img.classList.add("oculta");
  }
  document.getElementById("modal-detalle-solicitud").classList.remove("oculta");
}

// ---------- vehículos (alta y edición) ----------

function abrirFormVehiculo() {
  document.getElementById("titulo-form-vehiculo").textContent = "Nuevo vehículo";
  document.getElementById("veh-id").value = "";
  document.getElementById("veh-matricula").value = "";
  document.getElementById("veh-modelo").value = "";
  document.getElementById("veh-estado").value = "disponible";
  document.getElementById("modal-vehiculo").classList.remove("oculta");
}

function editarVehiculo(json) {
  const v = JSON.parse(json);
  abrirFormVehiculo();
  document.getElementById("titulo-form-vehiculo").textContent = "Editar vehículo";
  document.getElementById("veh-id").value = v.id;
  document.getElementById("veh-matricula").value = v.matricula;
  document.getElementById("veh-modelo").value = v.modelo || "";
  document.getElementById("veh-estado").value = v.estado;
}

async function guardarVehiculo(evento) {
  evento.preventDefault();
  const id = document.getElementById("veh-id").value;
  const datos = {
    matricula: document.getElementById("veh-matricula").value.trim().toUpperCase(),
    modelo: document.getElementById("veh-modelo").value.trim() || null,
    estado: document.getElementById("veh-estado").value,
  };
  if (id) {
    await api(`api/turismos/vehiculos/${id}`, { method: "PUT", body: JSON.stringify(datos) });
  } else {
    await api("api/turismos/vehiculos", { method: "POST", body: JSON.stringify(datos) });
  }
  cerrarModalTurismo("modal-vehiculo");
  cargarTurismos();
  return false;
}

// ---------- usuarios de vehículos ----------

let datosUsuariosVehiculos = [];

async function cargarUsuariosVehiculos() {
  datosUsuariosVehiculos = await api("api/turismos/usuarios");
  pintarUsuariosVehiculos();
}

function pintarUsuariosVehiculos() {
  const cont = document.getElementById("lista-usuarios-vehiculos");
  if (datosUsuariosVehiculos.length === 0) {
    cont.innerHTML = '<div class="vacio">Todavía no hay usuarios de vehículos. Crea el primero con "➕ Crear usuario de vehículos".</div>';
    return;
  }
  const hoy = new Date().toISOString().slice(0, 10);
  cont.innerHTML = datosUsuariosVehiculos
    .map((u) => {
      const permisoCaducado = u.permiso_caduca && u.permiso_caduca < hoy;
      return `
      <div class="tarjeta">
        <div class="item-cabecera">
          <div>
            <strong>👤 ${esc(u.nombre)}</strong>
            <span class="badge ${u.empleado ? "libre" : "parado"}">${u.empleado ? "Empleado" : "Colaborador/tercero"}</span>
            ${u.coches_en_uso > 0 ? '<span class="badge ocupado">🚗 Tiene un coche</span>' : ""}
            ${permisoCaducado ? '<span class="badge vencido">⚠️ Permiso caducado</span>' : ""}
          </div>
          <div class="acciones" style="margin:0">
            <button class="btn-chico" onclick='editarUsuarioVehiculos(${JSON.stringify(JSON.stringify(u))})'>✏️ Editar</button>
            <button class="btn-chico btn-peligro" onclick="borrarUsuarioVehiculos(${u.id}, '${esc(u.nombre).replace(/'/g, "&#39;")}')">🗑 Borrar</button>
          </div>
        </div>
        <p class="item-datos">
          Usuario: <strong>${esc(u.username)}</strong> · DNI ${esc(u.dni || "—")} · ☎️ ${esc(u.telefono || "—")} · 📧 ${esc(u.email)}<br>
          Domicilio: ${esc(u.domicilio || "—")} ·
          Permiso ${esc(u.permiso || "—")} (clase ${esc(u.clase_permiso || "—")}, válido hasta ${fechaLegible(u.permiso_caduca)})
        </p>
      </div>`;
    })
    .join("");
}

function abrirUsuarioVehiculos() {
  document.getElementById("uv-id").value = "";
  document.getElementById("titulo-usuario-vehiculos").textContent = "👥 Crear usuario de vehículos";
  document.getElementById("btn-uv-guardar").textContent = "Crear cuenta";
  ["uv-nombre", "uv-dni", "uv-telefono", "uv-email", "uv-domicilio", "uv-permiso", "uv-clase", "uv-caduca"].forEach(
    (id) => (document.getElementById(id).value = "")
  );
  document.getElementById("uv-empleado").value = "1";
  document.getElementById("modal-usuario-vehiculos").classList.remove("oculta");
}

function editarUsuarioVehiculos(json) {
  const u = JSON.parse(json);
  abrirUsuarioVehiculos();
  document.getElementById("uv-id").value = u.id;
  document.getElementById("titulo-usuario-vehiculos").textContent = `✏️ Editar a ${u.nombre}`;
  document.getElementById("btn-uv-guardar").textContent = "Guardar cambios";
  document.getElementById("uv-nombre").value = u.nombre;
  document.getElementById("uv-dni").value = u.dni || "";
  document.getElementById("uv-telefono").value = u.telefono || "";
  document.getElementById("uv-email").value = u.email;
  document.getElementById("uv-domicilio").value = u.domicilio || "";
  document.getElementById("uv-permiso").value = u.permiso || "";
  document.getElementById("uv-clase").value = u.clase_permiso || "";
  document.getElementById("uv-caduca").value = u.permiso_caduca || "";
  document.getElementById("uv-empleado").value = u.empleado ? "1" : "0";
}

async function crearUsuarioVehiculos(evento) {
  evento.preventDefault();
  const id = document.getElementById("uv-id").value;
  const datos = {
    nombre: document.getElementById("uv-nombre").value.trim(),
    dni: document.getElementById("uv-dni").value.trim(),
    telefono: document.getElementById("uv-telefono").value.trim(),
    email: document.getElementById("uv-email").value.trim(),
    domicilio: document.getElementById("uv-domicilio").value.trim(),
    permiso: document.getElementById("uv-permiso").value.trim(),
    clase_permiso: document.getElementById("uv-clase").value.trim(),
    permiso_caduca: document.getElementById("uv-caduca").value,
    empleado: document.getElementById("uv-empleado").value === "1",
  };
  if (id) {
    await api(`api/turismos/usuarios/${id}`, { method: "PUT", body: JSON.stringify(datos) });
    cerrarModalTurismo("modal-usuario-vehiculos");
    alert("✅ Datos actualizados. Sus próximos contratos saldrán con los datos nuevos.");
  } else {
    const cred = await api("api/turismos/usuarios", { method: "POST", body: JSON.stringify(datos) });
    cerrarModalTurismo("modal-usuario-vehiculos");
    // Se reutiliza el modal de credenciales de las cuentas de chófer.
    document.getElementById("cred-usuario").value = cred.username;
    document.getElementById("cred-password").value = cred.password;
    document.getElementById("modal-credenciales").classList.remove("oculta");
  }
  cargarUsuariosVehiculos();
  return false;
}

async function borrarUsuarioVehiculos(id, nombre) {
  if (!confirm(`¿Borrar la cuenta de vehículos de ${nombre}?\n\nSu historial de solicitudes firmadas se conserva para las auditorías.`)) return;
  await api(`api/turismos/usuarios/${id}`, { method: "DELETE" });
  cargarUsuariosVehiculos();
}
