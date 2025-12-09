<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Reserva tu habitación</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>

:root{
  --primary:#CC7D14;
  --bg:#0f172a;
  --card:#ffffff;
  --text:#0f172a;
  --muted:#475569;
  --border:#e2e8f0;
}
*{box-sizing:border-box;font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;}
body{margin:0;background:radial-gradient(circle at 20% 20%,rgba(204,125,20,0.18),transparent 28%),radial-gradient(circle at 80% 0%,rgba(204,125,20,0.12),transparent 26%),#f8fafc;color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
.page{max-width:1080px;width:100%;display:grid;grid-template-columns:1.1fr 0.9fr;gap:22px;align-items:start;}
.hero{background:linear-gradient(135deg,#fff,rgba(255,255,255,0.4));border:1px solid var(--border);border-radius:18px;padding:28px;box-shadow:0 22px 60px rgba(15,23,42,0.12);}
.hero h1{margin:8px 0;font-size:30px;color:var(--primary);} 
.hero p{margin:0;color:var(--muted);line-height:1.5;}
.status-card{margin-top:18px;border:1px solid var(--border);border-radius:16px;padding:16px;display:flex;align-items:center;gap:14px;background:#fff;box-shadow:0 16px 40px rgba(12,23,52,0.08);} 
.status-dot{width:16px;height:16px;border-radius:50%;background:var(--muted);box-shadow:0 0 0 8px rgba(204,125,20,0.08);transition:all .25s ease;}
.status-text{display:flex;flex-direction:column;gap:4px;font-weight:700;color:var(--muted);} 
.status-text span{font-size:14px;font-weight:600;color:var(--muted);} 
.status-ok .status-dot{background:#0ea5e9;box-shadow:0 0 0 10px rgba(14,165,233,0.15);} 
.status-ok .status-text{color:#0f172a;}
.status-bad .status-dot{background:#ef4444;box-shadow:0 0 0 10px rgba(239,68,68,0.18);} 
.status-bad .status-text{color:#b91c1c;}

.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:22px;box-shadow:0 22px 60px rgba(15,23,42,0.12);} 
.card h2{margin-top:0;color:var(--primary);}
.field{display:flex;flex-direction:column;gap:6px;margin-bottom:14px;text-align:left;}
.field label{font-weight:700;color:#0f172a;}
select,input[type=number]{width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--border);background:#fff;font-size:15px;color:#0f172a;box-shadow:inset 0 1px 2px rgba(15,23,42,0.05);}
select:focus,input[type=number]:focus{outline:2px solid rgba(204,125,20,0.2);border-color:var(--primary);} 
.helper{color:var(--muted);font-size:13px;}
.precio-box{margin:10px 0 4px;font-weight:800;font-size:18px;color:var(--primary);display:flex;align-items:center;gap:8px;}
.precio-pill{background:rgba(204,125,20,0.12);color:var(--primary);padding:10px 12px;border-radius:14px;font-size:14px;font-weight:700;}
button[type=submit]{width:100%;padding:14px;border:none;border-radius:12px;background:var(--primary);color:#fff;font-weight:800;font-size:16px;cursor:pointer;box-shadow:0 14px 30px rgba(204,125,20,0.25);transition:transform .1s ease,box-shadow .2s ease;}
button[type=submit]:hover{transform:translateY(-1px);box-shadow:0 18px 36px rgba(204,125,20,0.28);} 
.resultado{margin-top:10px;font-weight:700;}
.notice{margin-top:16px;background:#fff3e6;border:1px solid #fed7aa;color:#a16207;padding:14px;border-radius:12px;font-weight:700;line-height:1.4;}

.chips{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;}
.chip{padding:10px 12px;border-radius:999px;border:1px solid var(--border);background:#fff;color:var(--muted);font-weight:700;font-size:13px;}
.chip strong{color:var(--primary);} 

@media (max-width: 880px){
  body{padding:16px;}
  .page{grid-template-columns:1fr;}
  .hero h1{font-size:26px;}
}
</style>
</head>
<body>
<div class="page">
  <div class="hero">
    <div class="chip">Reservá online al instante</div>
    <h1>Elegí tu turno</h1>
    <p>Completá los datos, confirmá el pago y tu turno empieza a correr en el acto. Podés elegir más de un turno seguido para quedarte más tiempo.</p>
    <div class="status-card" id="status-card">
      <div class="status-dot" aria-hidden="true"></div>
      <div class="status-text">
        <div id="status-indicator">Verificando disponibilidad...</div>
        <span>Actualizamos cada 5 segundos</span>
      </div>
    </div>
    <div class="chips">
      <div class="chip">Pago seguro por Mercado Pago</div>
      <div class="chip">Turnos cortos o noche completa</div>
    </div>
  </div>

 <div class="card">
    <h2>Datos de la reserva</h2>
    <form id="reservaForm">
      <div class="field">
        <label for="tipo">Tipo de habitación</label>
        <select name="tipo" id="tipo">
          <option value="Común">Común</option>
          <option value="VIP">VIP</option>
          <option value="Super VIP">Super VIP</option>
        </select>
      </div>

      <div class="field">
        <label for="turno">Turno</label>
        <select name="turno" id="turno">
          <option value="2h">Turno 2 horas</option>
          <option value="3h">Turno 3 horas</option>
          <option value="noche">Noche (21:00–10:00)</option>
        </select>
        <div class="helper">Según el día, el sistema ajusta automáticamente si los turnos son de 2h o 3h.</div>
      </div>

      <div class="field">
        <label for="cantidad">Cantidad de turnos seguidos</label>
        <input type="number" id="cantidad" name="cantidad" min="1" max="3" value="1">
        <div class="helper">Si elegís un turno corto, podés sumar hasta 3 turnos consecutivos.</div>
      </div>

      <div class="precio-box">
        💰
        <div class="precio-pill">Precio total: <span id="precio-valor">$0</span></div>
      </div>

  <button type="submit">Reservar y pagar</button>
    </form>

    <div id="resultado" class="resultado"></div>

    <div class="notice">
      ⚠️ Una vez pagado no hay devolución, aunque no uses todo el turno. El reloj empieza a correr en cuanto se acredita el pago.
    </div>
  </div>
</div>

<script>
function nowArg(){
  const now = new Date();
  const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
  return new Date(utc - 3 * 3600 * 1000); // hora Argentina (UTC-3)
}

function turnoDisponible(){
  const d = nowArg();
  const dow = d.getDay(); // 0=Dom, 5=Vie, 6=Sab
  const h = d.getHours();
  const turnoSelect = document.getElementById('turno');
  const cantidadInput = document.getElementById('cantidad');
  turnoSelect.innerHTML = ''; // limpiar opciones

  // Definir horas de turno corto o largo según el día
  const hrs = ((dow===5 && h>=8) || dow===6 || dow===0) ? 2 : 3;
  // Mostrar siempre turno normal
  turnoSelect.innerHTML += `<option value="${hrs}h">Turno ${hrs} horas</option>`;

  // Agregar también la noche si es >=21h o <10h
  if (h >= 21 || h < 10) {
    turnoSelect.innerHTML += '<option value="noche">Noche (21:00–10:00)</option>';
  }
  
  // Si la opción visible es noche, fijamos cantidad en 1
  if (turnoSelect.value === 'noche') {
    cantidadInput.value = 1;
    cantidadInput.disabled = true;
  } else {
    cantidadInput.disabled = false;
  }
}

turnoDisponible();
setInterval(turnoDisponible, 60000);

// ==== Verificar disponibilidad cada 5s ====
async function checkDisponibilidad(){
  const ind = document.getElementById('status-indicator');
  const card = document.getElementById('status-card');
  try{
    const r = await fetch('estado_hotel.php?'+Date.now());
    const data = await r.json();
    const libres = data.filter(x=>x.estado==='libre').length;
    if(libres>0){
      ind.textContent=`✅  Habitación(es) disponibles`;
      card.classList.add('status-ok');
      card.classList.remove('status-bad');
    } else {
      ind.textContent='❌ No tenemos habitaciones libres en este momento';
      card.classList.add('status-bad');
      card.classList.remove('status-ok');
    }
  }catch(e){
    ind.textContent='Error verificando';
   card.classList.add('status-bad');
    card.classList.remove('status-ok');
  }
}
checkDisponibilidad();
setInterval(checkDisponibilidad,5000);


// ==== Enviar a Mercado Pago ====
document.getElementById('reservaForm').addEventListener('submit', async e => {
  e.preventDefault();

  const tipo  = document.getElementById('tipo').value;
  const turno = document.getElementById('turno').value;
  const cantidad = Math.max(1, Math.min(3, parseInt(document.getElementById('cantidad').value || '1', 10)));

  const resultadoDiv = document.getElementById('resultado');
  resultadoDiv.innerHTML = '';

  try {
    const r = await fetch('crear-preferencia.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ tipo, turno, cantidad })
    });

    const j = await r.json();

    if (j.init_point) {
      // Redirigir al checkout de Mercado Pago
      window.location.href = j.init_point;
    } else {
      resultadoDiv.innerHTML = `<p style="color:red">Error: ${j.error || 'No se pudo crear el pago.'}</p>`;
    }
  } catch (err) {
    resultadoDiv.innerHTML = `<p style="color:red">Error de comunicación con el servidor.</p>`;
  }
});

// ==== Actualizar precio dinámico ====
document.addEventListener('DOMContentLoaded', ()=>{
  const tipo = document.getElementById('tipo');
  const turno = document.getElementById('turno');
   const cantidad = document.getElementById('cantidad');
  const precioSpan = document.getElementById('precio-valor');

  async function actualizarPrecio(){
    const t = tipo.value, u = turno.value;
    const cant = Math.max(1, Math.min(3, parseInt(cantidad.value || '1', 10)));
    if(!t || !u) { precioSpan.textContent = '$0'; return; }
    try{
      const res = await fetch('api-reservar.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({get_precio:1, tipo:t, turno:u, cantidad:cant})
      });
      const data = await res.json();
      const precio = parseFloat(data.precio||0);
      precioSpan.textContent = precio>0 ? `$${precio.toLocaleString('es-AR')}` : '$0';
    }catch(e){
      precioSpan.textContent = '$0';
    }
  }

  tipo.addEventListener('change', actualizarPrecio);
  turno.addEventListener('change', ()=>{
    if(turno.value==='noche'){
      cantidad.value = 1;
      cantidad.disabled = true;
    } else {
      cantidad.disabled = false;
    }
    actualizarPrecio();
  });
  cantidad.addEventListener('input', actualizarPrecio);
  actualizarPrecio(); // ejecuta al cargar
});

</script>



</body>
</html>
