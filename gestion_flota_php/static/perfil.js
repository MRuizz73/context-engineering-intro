// Perfil del chofer: datos personales, cuestionario para añadir
// cursos/permisos y listado de sus documentos.

let perfilChoferId = null;

async function abrirPerfil(choferId) {
  perfilChoferId = choferId;
  const chofer = await api(`api/choferes/${choferId}`);

  document.getElementById("perfil-titulo").textContent =
    `Perfil de ${chofer.apellido}, ${chofer.nombre}`;
  document.getElementById("perfil-nombre").value = chofer.nombre;
  document.getElementById("perfil-apellido").value = chofer.apellido;
  document.getElementById("perfil-dni").value = chofer.dni;
  document.getElementById("perfil-telefono").value = chofer.telefono || "";
  document.getElementById("perfil-email").value = chofer.email || "";
  document.getElementById("perfil-activo").checked = chofer.activo;
  document.getElementById("perfil-documentos").innerHTML = tablaDocumentos(
    chofer.documentos,
    "chofer",
    chofer.id
  );
  document.getElementById("form-cuestionario").reset();
  document.getElementById("cuest-aviso").value = 30;

  // Un chofer no tiene listado de choferes al que volver.
  document.getElementById("btn-volver-choferes")
    .classList.toggle("oculta", window.esAdmin === false);

  document.querySelectorAll(".vista").forEach((v) => v.classList.add("oculta"));
  document.getElementById("vista-perfil").classList.remove("oculta");
  window.scrollTo(0, 0);
}

function volverAChoferes() {
  perfilChoferId = null;
  document.querySelectorAll(".vista").forEach((v) => v.classList.add("oculta"));
  document.getElementById("vista-choferes").classList.remove("oculta");
  cargarTodo();
}

async function guardarPerfil(evento) {
  evento.preventDefault();
  await api(`api/choferes/${perfilChoferId}`, {
    method: "PUT",
    body: JSON.stringify({
      nombre: document.getElementById("perfil-nombre").value.trim(),
      apellido: document.getElementById("perfil-apellido").value.trim(),
      dni: document.getElementById("perfil-dni").value.trim(),
      telefono: document.getElementById("perfil-telefono").value.trim() || null,
      email: document.getElementById("perfil-email").value.trim() || null,
      activo: document.getElementById("perfil-activo").checked,
    }),
  });
  await abrirPerfil(perfilChoferId);
  return false;
}

async function enviarCuestionario(evento) {
  evento.preventDefault();
  await api("api/documentos", {
    method: "POST",
    body: JSON.stringify({
      nombre: document.getElementById("cuest-nombre").value.trim(),
      tipo: document.getElementById("cuest-tipo").value,
      fecha_emision: document.getElementById("cuest-emision").value || null,
      fecha_vencimiento: document.getElementById("cuest-vencimiento").value,
      dias_aviso: parseInt(document.getElementById("cuest-aviso").value, 10),
      notas: document.getElementById("cuest-notas").value.trim() || null,
      chofer_id: perfilChoferId,
      camion_id: null,
    }),
  });
  await abrirPerfil(perfilChoferId);
  return false;
}

// Refresca el perfil abierto cuando otra acción (renovar, borrar) recarga datos.
function refrescarPerfilSiAbierto() {
  const visible = !document.getElementById("vista-perfil").classList.contains("oculta");
  if (perfilChoferId !== null && visible) {
    abrirPerfil(perfilChoferId);
  }
}
