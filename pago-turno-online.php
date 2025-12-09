<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Pago online de turno</title>
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
.page{max-width:960px;width:100%;display:grid;grid-template-columns:1fr 1fr;gap:22px;align-items:start;}
.hero{background:linear-gradient(135deg,#fff,rgba(255,255,255,0.4));border:1px solid var(--border);border-radius:18px;padding:28px;box-shadow:0 22px 60px rgba(15,23,42,0.12);}
.hero h1{margin:8px 0;font-size:30px;color:var(--primary);}
.hero p{margin:0;color:var(--muted);line-height:1.5;}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:22px;box-shadow:0 22px 60px rgba(15,23,42,0.12);}
.card h2{margin-top:0;color:var(--primary);}
.field{display:flex;flex-direction:column;gap:6px;margin-bottom:14px;text-align:left;}
.field label{font-weight:700;color:#0f172a;}
input[type=number], select{width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--border);background:#fff;font-size:15px;color:#0f172a;box-shadow:inset 0 1px 2px rgba(15,23,42,0.05);}
input[type=number]:focus, select:focus{outline:2px solid rgba(204,125,20,0.2);border-color:var(--primary);}
.helper{color:var(--muted);font-size:13px;}
.notice{margin-top:10px;background:#fff3e6;border:1px solid #fed7aa;color:#a16207;padding:14px;border-radius:12px;font-weight:700;line-height:1.4;}
button[type=submit]{width:100%;padding:14px;border:none;border-radius:12px;background:var(--primary);color:#fff;font-weight:800;font-size:16px;cursor:pointer;box-shadow:0 14px 30px rgba(204,125,20,0.25);transition:transform .1s ease,box-shadow .2s ease;}
button[type=submit]:hover{transform:translateY(-1px);box-shadow:0 18px 36px rgba(204,125,20,0.28);}
.resultado{margin-top:10px;font-weight:700;}
.error{color:#b91c1c;}
.success{color:#166534;}
.radio-group{display:flex;gap:10px;flex-wrap:wrap;}
.radio{display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:#fff;cursor:pointer;}
.radio input{margin:0;}
@media (max-width: 860px){
  body{padding:16px;}
  .page{grid-template-columns:1fr;}
  .hero h1{font-size:26px;}
}
</style>
</head>
<body>
<div class="page">
  <div class="hero">
    <div class="link-btn" aria-hidden="true">Pago online</div>
    <h1>Pagá tu turno desde la habitación</h1>
    <p>Ingresá el número de tu habitación, elegí si es turno corto o noche y pagá de forma online sin pasar por la recepción.</p>
    <div class="notice">Solo ves tu propio número de habitación. El reloj y el tiempo cargado se mantienen igual.</div>
  </div>

  <div class="card">
    <h2>Datos del pago</h2>
    <form id="pagoForm">
      <div class="field">
        <label for="habitacion">Número de habitación</label>
        <input type="number" id="habitacion" name="habitacion" min="1" max="40" required>
        <div class="helper">Ingresá únicamente el número en el que estás alojado.</div>
      </div>

      <div class="field">
        <label>Tipo de turno</label>
        <div class="radio-group">
          <label class="radio" id="turnoRadio"><input type="radio" name="modo" value="turno" checked>Turno corto (2h / 3h)</label>
          <label class="radio" id="nocheRadio"><input type="radio" name="modo" value="noche">Noche completa</label>
        </div>
        <div class="helper" id="modoHelper">El sistema elige automáticamente si corresponde 2hs, 3hs o noche de finde según día y hora. El turno noche está disponible de 21:00 a 10:00.</div>
      </div>

      <div class="field" id="cantidadField">
        <label for="cantidad">Cantidad de turnos</label>
        <select id="cantidad" name="cantidad">
          <option value="1">1 turno</option>
          <option value="2">2 turnos</option>
          <option value="3">3 turnos</option>
        </select>
        <div class="helper">Usá esto si querés pagar más de un turno seguido.</div>
      </div>

      <button type="submit">Pagar online</button>
    </form>
    <div id="resultado" class="resultado"></div>
  </div>
</div>

<script>
const form = document.getElementById('pagoForm');
const resultado = document.getElementById('resultado');
const cantidadField = document.getElementById('cantidadField');
const nocheRadio = document.getElementById('nocheRadio');
const modoHelper = document.getElementById('modoHelper');

function refreshNightAvailability(){
  const hour = new Date().getHours();
  const nightAvailable = hour >= 21 || hour < 10;

  if(!nightAvailable){
    nocheRadio.style.display = 'none';
    const nightInput = nocheRadio.querySelector('input');
    if(nightInput.checked){
      form.querySelector('input[name="modo"][value="turno"]').checked = true;
      cantidadField.style.display='block';
    }
    modoHelper.textContent = 'El turno noche está disponible únicamente de 21:00 a 10:00.';
  } else {
    nocheRadio.style.display = 'flex';
    modoHelper.textContent = 'El sistema elige automáticamente si corresponde 2hs, 3hs o noche de finde según día y hora. El turno noche está disponible de 21:00 a 10:00.';
  }
}

refreshNightAvailability();
setInterval(refreshNightAvailability, 5 * 60 * 1000);

form.addEventListener('change', (e)=>{
  if(e.target.name==='modo'){
    if(e.target.value==='noche'){
      cantidadField.style.display='none';
      document.getElementById('cantidad').value='1';
    } else {
      cantidadField.style.display='block';
    }
  }
});

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  resultado.textContent = '';
  const hab = parseInt(document.getElementById('habitacion').value || '0', 10);
  const modo = form.querySelector('input[name="modo"]:checked')?.value || 'turno';
  const cantidad = parseInt(document.getElementById('cantidad').value || '1', 10);

  if (!hab || hab < 1) {
    resultado.textContent = 'Ingresá un número de habitación válido.';
    resultado.className = 'resultado error';
    return;
  }

  const btn = form.querySelector('button[type=submit]');
  btn.disabled = true;
  btn.textContent = 'Creando pago...';

  try {
    const r = await fetch('crear-preferencia-turno-online.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        habitacion: hab,
        modo,
        cantidad
      })
    });
    const data = await r.json();

    if (data.init_point) {
      resultado.className = 'resultado success';
      resultado.textContent = data.mensaje || 'Redirigiendo al pago seguro...';
      window.location.href = data.init_point;
      return;
    }

    let msg = data.error || 'No se pudo crear el pago.';
    resultado.className = 'resultado error';
    resultado.innerHTML = msg;
  } catch (err) {
    resultado.className = 'resultado error';
    resultado.textContent = 'Error de conexión con el servidor.';
  } finally {
    btn.disabled = false;
    btn.textContent = 'Pagar online';
  }
});
</script>
</body>
</html>