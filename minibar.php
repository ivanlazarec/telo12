<?php
// Página pública para pedir productos del minibar con pago por Mercado Pago
error_reporting(E_ALL);
ini_set('display_errors', 1);

$servername = "127.0.0.1";
$username   = "u460517132_F5bOi";
$password   = "mDjVQbpI5A";
$dbname     = "u460517132_GxbHQ";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    http_response_code(500);
    echo "Error de conexión";
    exit;
}

$res = $conn->query("SELECT id, nombre, precio, cantidad FROM inventario_productos WHERE activo = 1 AND cantidad > 0 ORDER BY nombre ASC");
$items = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$conn->close();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Pedir a la habitación</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root {
  --primary: #0B5FFF;
  --bg: #0f172a;
  --card: #ffffff;
  --text: #0f172a;
  --muted: #475569;
  --border: #e2e8f0;
}
*{box-sizing:border-box;font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;}
body{margin:0;background:linear-gradient(135deg,#f8fafc 0%,#eef2ff 100%);color:var(--text);min-height:100vh;display:flex;justify-content:center;padding:20px;}
.page{width:100%;max-width:980px;display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;}
.hero{background:#0B5FFF;color:#fff;border-radius:18px;padding:22px;box-shadow:0 18px 50px rgba(11,95,255,0.25);}
.hero h1{margin:0 0 10px;font-size:28px;}
.hero p{margin:0;color:rgba(255,255,255,0.9);line-height:1.5;}
.hero .chips{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0;}
.hero .chip{background:rgba(255,255,255,0.15);padding:10px 12px;border-radius:999px;font-weight:700;font-size:13px;}
.hero .status{margin-top:16px;display:flex;align-items:center;gap:10px;background:rgba(255,255,255,0.12);padding:12px;border-radius:12px;}
.hero .status .dot{width:12px;height:12px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 8px rgba(34,197,94,0.15);}

.card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:18px;box-shadow:0 16px 38px rgba(15,23,42,0.12);}
.card h2{margin-top:0;color:var(--primary);}
.field{display:flex;flex-direction:column;gap:6px;margin-bottom:12px;}
.field label{font-weight:700;color:#0f172a;}
input[type=number]{width:100%;padding:12px;border-radius:12px;border:1px solid var(--border);background:#fff;font-size:15px;color:#0f172a;}
input[type=number]:focus{outline:2px solid rgba(11,95,255,0.25);border-color:var(--primary);}
.products{display:grid;grid-template-columns:1fr;gap:10px;margin:10px 0;}
.product{border:1px solid var(--border);border-radius:12px;padding:12px;display:flex;justify-content:space-between;align-items:center;gap:12px;}
.product strong{display:block;color:#0f172a;}
.product .meta{color:var(--muted);font-size:13px;}
.qty{display:flex;align-items:center;gap:8px;}
.qty input{width:90px;}
.total-box{margin:12px 0 6px;font-weight:800;font-size:18px;color:var(--primary);display:flex;align-items:center;gap:8px;}
button[type=submit]{width:100%;padding:14px;border:none;border-radius:12px;background:var(--primary);color:#fff;font-weight:800;font-size:16px;cursor:pointer;box-shadow:0 14px 30px rgba(11,95,255,0.25);transition:transform .1s ease,box-shadow .2s ease;}
button[type=submit]:hover{transform:translateY(-1px);box-shadow:0 18px 36px rgba(11,95,255,0.28);}
.notice{margin-top:14px;background:#fff3e6;border:1px solid #fed7aa;color:#a16207;padding:12px;border-radius:12px;font-weight:700;line-height:1.4;}
#resultado{margin-top:10px;font-weight:700;}
@media(max-width:860px){.page{grid-template-columns:1fr;}body{padding:14px;}}
</style>
</head>
<body>
<div class="page">
  <div class="hero">
    <div class="chip">Pedido a la habitación</div>
    <h1>Elegí lo que querés del minibar</h1>
    <p>Pagás por Mercado Pago y te llevamos el pedido a la habitación.</p>
    <div class="chips">
      <div class="chip">MP - Pago seguro</div>
      <div class="chip">Stock en tiempo real</div>
      
    </div>
    <div class="status"><div class="dot"></div><div>Disponible 24/7</div></div>
  </div>

  <div class="card">
    <h2>Armar pedido</h2>
    <form id="pedidoForm">
      <div class="field">
        <label for="habitacion">Habitación</label>
        <input type="number" id="habitacion" name="habitacion" min="1" max="40" placeholder="N.º de habitación" required>
        <div class="meta" style="color:var(--muted);font-size:13px;">Elegí la habitación donde querés recibir el pedido.</div>
      </div>

      <div class="products">
        <?php if(empty($items)): ?>
          <p>No hay productos disponibles en este momento.</p>
        <?php else: ?>
          <?php foreach($items as $p): ?>
            <div class="product" data-id="<?php echo (int)$p['id']; ?>" data-precio="<?php echo (int)$p['precio']; ?>" data-stock="<?php echo (int)$p['cantidad']; ?>">
              <div>
                <strong><?php echo htmlspecialchars($p['nombre'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <div class="meta">$<?php echo number_format((int)$p['precio'], 0, ',', '.');  ?></div>
              </div>
              <div class="qty">
                <label for="qty-<?php echo (int)$p['id']; ?>">Cant.</label>
                <input type="number" id="qty-<?php echo (int)$p['id']; ?>" min="0" max="<?php echo (int)$p['cantidad']; ?>" value="0">
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="total-box">Total estimado: <span id="total">$0</span></div>
      <button type="submit" <?php echo empty($items)?'disabled':''; ?>>Pagar y pedir</button>
      <div id="resultado"></div>
      <div class="notice">El pago se procesa con Mercado Pago. Una vez aprobado te mostramos la confirmación.</div>
    </form>
  </div>
</div>
<script>
const productos = Array.from(document.querySelectorAll('.product'));
const totalEl = document.getElementById('total');
const resultado = document.getElementById('resultado');

function calcularTotal(){
  let total = 0;
  productos.forEach(p => {
    const precio = parseInt(p.dataset.precio || '0', 10);
    const input = p.querySelector('input[type="number"]');
    const cant = Math.max(0, parseInt(input.value || '0', 10));
    total += precio * cant;
  });
  totalEl.textContent = '$' + total.toLocaleString('es-AR');
  return total;
}

productos.forEach(p => {
  const input = p.querySelector('input[type="number"]');
  input.addEventListener('input', () => {
    const max = parseInt(p.dataset.stock || '0', 10);
    if (input.value === '') return;
    input.value = Math.min(Math.max(0, parseInt(input.value, 10) || 0), max);
    calcularTotal();
  });
});

calcularTotal();

document.getElementById('pedidoForm').addEventListener('submit', async e => {
  e.preventDefault();
  resultado.textContent = '';

  const habitacion = parseInt(document.getElementById('habitacion').value || '0', 10);
  if(!habitacion || habitacion < 1 || habitacion > 40){
    resultado.textContent = 'Ingresá una habitación válida (1-40).';
    resultado.style.color = 'red';
    return;
  }

  const seleccion = productos.map(p => {
    const input = p.querySelector('input[type="number"]');
    const qty = Math.max(0, parseInt(input.value || '0', 10));
    return { id: parseInt(p.dataset.id, 10), cantidad: qty };
  }).filter(x => x.cantidad > 0);

  if(!seleccion.length){
    resultado.textContent = 'Elegí al menos un producto.';
    resultado.style.color = 'red';
    return;
  }

  const total = calcularTotal();
  if(total <= 0){
    resultado.textContent = 'Total inválido.';
    resultado.style.color = 'red';
    return;
  }

  try {
    const r = await fetch('crear-preferencia-minibar.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        habitacion,
        items: JSON.stringify(seleccion)
      })
    });
    const j = await r.json();
    if(j.init_point){
      window.location.href = j.init_point;
    } else {
      resultado.textContent = j.error || 'No se pudo generar el pago.';
      resultado.style.color = 'red';
    }
  } catch(err){
    resultado.textContent = 'Error de comunicación con el servidor.';
    resultado.style.color = 'red';
  }
});
</script>
</body>
</html>