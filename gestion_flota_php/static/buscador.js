// Buscador con sugerencias para chóferes y camiones.
// Al escribir se despliegan sugerencias; la búsqueda se aplica al tocar
// una sugerencia o al pulsar Enter (o la tecla "buscar" del móvil).

// Filtro aplicado por sección: null | {tipo:'texto'|'id', valor, etiqueta}
let filtroChoferes = null;
let filtroCamiones = null;

// Quita acentos, comas y mayúsculas para comparar sin sorpresas.
function normalizarTexto(texto) {
  return (texto || "")
    .toString()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[.,]/g, " ")
    .toLowerCase();
}

// Coincide si TODAS las palabras de la consulta aparecen en los campos.
function coincideBusqueda(campos, consulta) {
  const q = normalizarTexto(consulta).trim();
  if (!q) return true;
  const pajar = normalizarTexto(campos.filter(Boolean).join(" "));
  return q.split(/\s+/).every((palabra) => pajar.includes(palabra));
}

function camposChofer(c) {
  return [c.nombre, c.apellido, c.dni, c.email, c.telefono, ...c.documentos.map((d) => d.nombre)];
}

function camposCamion(c) {
  return [c.patente, c.marca, c.modelo, c.anio, ...c.documentos.map((d) => d.nombre)];
}

function pasaFiltroChofer(c) {
  if (!filtroChoferes) return true;
  if (filtroChoferes.tipo === "id") return c.id === filtroChoferes.valor;
  return coincideBusqueda(camposChofer(c), filtroChoferes.valor);
}

function pasaFiltroCamion(c) {
  if (!filtroCamiones) return true;
  if (filtroCamiones.tipo === "id") return c.id === filtroCamiones.valor;
  return coincideBusqueda(camposCamion(c), filtroCamiones.valor);
}

function sinResultados(filtro) {
  const texto = filtro ? filtro.etiqueta || filtro.valor : "";
  return `<div class="vacio">🔍 Sin resultados para «${esc(texto)}». Borra el buscador para ver todo.</div>`;
}

// ---------- desplegable de sugerencias ----------

function _elementos(seccion) {
  return {
    input: document.getElementById(`buscar-${seccion}`),
    cont: document.getElementById(`sug-${seccion}`),
  };
}

function cerrarSugerencias(seccion) {
  const { cont } = _elementos(seccion);
  cont.classList.add("oculta");
  cont.innerHTML = "";
}

function sugerir(seccion) {
  const { input, cont } = _elementos(seccion);
  const q = input.value;

  // Al vaciar el campo se quita el filtro y vuelve el listado completo.
  if (!q.trim()) {
    cerrarSugerencias(seccion);
    if (seccion === "choferes") { filtroChoferes = null; pintarChoferes(); }
    else { filtroCamiones = null; pintarCamiones(); }
    return;
  }

  let items;
  if (seccion === "choferes") {
    items = datosChoferes
      .filter((c) => coincideBusqueda(camposChofer(c), q))
      .slice(0, 8)
      .map((c) => ({ id: c.id, icono: "👷", etiqueta: `${c.apellido}, ${c.nombre}`, detalle: c.dni }));
  } else {
    items = datosCamiones
      .filter((c) => coincideBusqueda(camposCamion(c), q))
      .slice(0, 8)
      .map((c) => ({
        id: c.id, icono: "🚛", etiqueta: c.patente,
        detalle: [c.marca, c.modelo].filter(Boolean).join(" "),
      }));
  }

  const filas = [
    `<div class="sug-item" data-idx="0" onmousedown="aplicarTexto('${seccion}')">
       <span>🔍 Buscar «<strong>${esc(q)}</strong>»</span><small>Enter</small>
     </div>`,
    ...items.map(
      (it, i) => `
      <div class="sug-item" data-idx="${i + 1}" onmousedown="aplicarSugerencia('${seccion}', ${it.id}, '${esc(it.etiqueta).replace(/'/g, "&#39;")}')">
        <span>${it.icono} ${esc(it.etiqueta)}</span><small>${esc(it.detalle || "")}</small>
      </div>`
    ),
  ];
  cont.innerHTML = filas.join("");
  cont.classList.remove("oculta");
}

function aplicarTexto(seccion) {
  const { input } = _elementos(seccion);
  const q = input.value.trim();
  cerrarSugerencias(seccion);
  const filtro = q ? { tipo: "texto", valor: q, etiqueta: q } : null;
  if (seccion === "choferes") { filtroChoferes = filtro; pintarChoferes(); }
  else { filtroCamiones = filtro; pintarCamiones(); }
  input.blur();
}

function aplicarSugerencia(seccion, id, etiqueta) {
  const { input } = _elementos(seccion);
  input.value = etiqueta;
  cerrarSugerencias(seccion);
  const filtro = { tipo: "id", valor: id, etiqueta };
  if (seccion === "choferes") { filtroChoferes = filtro; pintarChoferes(); }
  else { filtroCamiones = filtro; pintarCamiones(); }
  input.blur();
}

// Flechas para moverse por las sugerencias, Enter para elegir, Esc cierra.
function teclasBuscador(evento, seccion) {
  const { cont } = _elementos(seccion);
  const filas = Array.from(cont.querySelectorAll(".sug-item"));
  const activa = filas.findIndex((f) => f.classList.contains("activa"));

  if (evento.key === "ArrowDown" || evento.key === "ArrowUp") {
    evento.preventDefault();
    if (filas.length === 0) return;
    const paso = evento.key === "ArrowDown" ? 1 : -1;
    const nueva = Math.min(Math.max(activa + paso, 0), filas.length - 1);
    filas.forEach((f) => f.classList.remove("activa"));
    filas[nueva].classList.add("activa");
    filas[nueva].scrollIntoView({ block: "nearest" });
  } else if (evento.key === "Enter") {
    evento.preventDefault();
    if (activa > 0) {
      filas[activa].dispatchEvent(new Event("mousedown"));
    } else {
      aplicarTexto(seccion);
    }
  } else if (evento.key === "Escape") {
    cerrarSugerencias(seccion);
  }
}

// Tocar fuera del buscador cierra el desplegable.
document.addEventListener("click", (evento) => {
  if (!evento.target.closest(".buscador")) {
    cerrarSugerencias("choferes");
    cerrarSugerencias("camiones");
  }
});
