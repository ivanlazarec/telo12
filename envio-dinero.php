<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Enviar dinero a la habitación</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --primary:#0B5FFF;
  --bg:#0f172a;
  --card:#ffffff;
  --text:#0f172a;
  --muted:#475569;
  --border:#e2e8f0;
}
*{box-sizing:border-box;font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;}
body{margin:0;background:#f8fafc;color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
.page{max-width:920px;width:100%;display:grid;grid-template-columns:1.05fr 0.95fr;gap:22px;align-items:start;}
.hero{background:linear-gradient(135deg,#fff,rgba(11,95,255,0.08));border:1px solid var(--border);border-radius:18px;padding:28px;box-shadow:0 22px 60px rgba(15,23,42,0.12);}
.hero h1{margin:8px 0;font-size:30px;color:var(--primary);}
.hero p{margin:0;color:var(--muted);line-height:1.5;}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:22px;box-shadow:0 22px 60px rgba(15,23,42,0.12);}
.card h2{margin-top:0;color:var(--primary);}
.field{display:flex;flex-direction:column;gap:6px;margin-bottom:14px;text-align:left;}
.field label{font-weight:700;color:#0f172a;}
input[type=number]{width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--border);background:#fff;font-size:15px;color:#0f172a;box-shadow:inset 0 1px 2px rgba(15,23,42,0.05);}
input[type=number]:focus{outline:2px solid rgba(11,95,255,0.18);border-color:var(--primary);}
.helper{color:var(--muted);font-size:13px;}
.notice{margin-top:10px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;padding:14px;border-radius:12px;font-weight:700;line-height:1.4;}
button[type=submit]{width:100%;padding:14px;border:none;border-radius:12px;background:var(--primary);color:#fff;font-weight:800;font-size:16px;cursor:pointer;box-shadow:0 14px 30px rgba(11,95,255,0.25);transition:transform .1s ease,box-shadow .2s ease;}
button[type=submit]:hover{transform:translateY(-1px);box-shadow:0 18px 36px rgba(11,95,255,0.28);}
.resultado{margin-top:10px;font-weight:700;}
.error{color:#b91c1c;}
.success{color:#166534;}
.actions{margin-top:12px;display:flex;flex-wrap:wrap;gap:10px;}
.link-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border-radius:10px;border:1px solid var(--border);background:#fff;color:var(--primary);text-decoration:none;font-weight:700;}
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
    <div class="link-btn" aria-hidden="true">Transferencia parcial</div>
    <h1>Enviá dinero a tu habitación</h1>
    <p>Elegí cuánta plata querés transferir en digital para completar tu pago. El equipo verá el aviso.</p>
    
  </div>

  <div class="card">
    <h2>Datos del pago</h2>
    <form id="envioForm">
      <div class="field">
        <label for="habitacion">Número de habitación</label>
        <input type="number" id="habitacion" name="habitacion" min="1" max="40" required>
        <div class="helper">Ingresá la habitación que tenés asignada.</div>
      </div>
      <div class="field">
        <label for="monto">Monto a transferir (ARS)</label>
        <input type="number" id="monto" name="monto" min="1" step="1" required>
        
      </div>
      <button type="submit">Pagar con Mercado Pago</button>
    </form>
    <div id="resultado" class="resultado"></div>
  </div>
</div>

<script>
const form = document.getElementById('envioForm');
const resultado = document.getElementById('resultado');

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  resultado.textContent = '';

  const hab = parseInt(document.getElementById('habitacion').value || '0', 10);
  const monto = parseInt(document.getElementById('monto').value || '0', 10);

  if (!hab || hab < 1) {
    resultado.textContent = 'Ingresá un número de habitación válido.';
    resultado.className = 'resultado error';
    return;
  }

  if (!monto || monto < 1) {
    resultado.textContent = 'El monto debe ser mayor a 0.';
    resultado.className = 'resultado error';
    return;
  }

  const btn = form.querySelector('button[type=submit]');
  btn.disabled = true;
  btn.textContent = 'Creando pago...';

  try {
    const r = await fetch('crear-preferencia-envio-dinero.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ habitacion: hab, monto })
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
    resultado.textContent = msg;
  } catch (err) {
    resultado.className = 'resultado error';
    resultado.textContent = 'Error de conexión con el servidor.';
  } finally {
    btn.disabled = false;
    btn.textContent = 'Pagar con Mercado Pago';
  }
});
</script>
</body>
</html>