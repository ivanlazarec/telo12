<?php
// ================== DEBUG BÁSICO (temporal) ==================
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ================== SESIÓN / LOGIN ==================
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

session_name('inv_sess');
session_set_cookie_params([
    'lifetime' => 3600,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

$pass = "Mora2025";

// ---- LOGOUT ----
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: inventario.php');
    exit;
}

// ---- LOGIN ----
if (!isset($_SESSION['logged_in'])) {
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clave'])) {
        if ($_POST['clave'] === $pass) {
            $_SESSION['logged_in'] = true;
            header("Location: inventario.php");
            exit;
        } else {
            $error = "Contraseña incorrecta";
        }
    }
    ?>
    <!doctype html>
    <html lang="es">
    <head>
        <meta charset="utf-8">
        <title>Acceso Inventario</title>
    </head>
    <body style="font-family:sans-serif;">
        <form method="POST" style="margin:100px auto; width:300px; text-align:center;">
            <h2>Acceso Inventario</h2>
            <input type="password" name="clave" placeholder="Contraseña"
                   style="padding:8px;width:100%;margin:10px 0;">
            <button type="submit" style="padding:8px 20px;">Entrar</button>
            <?php if($error): ?>
                <p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>
        </form>
    </body>
    </html>
    <?php
    exit;
}

// ================== CONEXIÓN DB ==================
$servername = "127.0.0.1";
$username   = "u460517132_F5bOi";
$password   = "mDjVQbpI5A";
$dbname     = "u460517132_GxbHQ";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Error de conexión DB: " . $conn->connect_error);
}

// ================== CRUD ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $accion = $_POST['accion'];

    // ---- GUARDAR (alta / edición) ----
    if ($accion === 'guardar') {
        $id       = intval($_POST['id'] ?? 0);
        $nombre   = trim($_POST['nombre'] ?? '');
        $precio   = intval($_POST['precio'] ?? 0);
        $cantidad = intval($_POST['cantidad'] ?? 0);

        if ($nombre === '') {
            die("Nombre de producto vacío.");
        }

        if ($id > 0) {
            // UPDATE
            $stmt = $conn->prepare("UPDATE inventario_productos SET nombre=?, precio=?, cantidad=? WHERE id=?");
            if (!$stmt) {
                die("Error prepare UPDATE: " . $conn->error);
            }
            $stmt->bind_param('siii', $nombre, $precio, $cantidad, $id);
            if (!$stmt->execute()) {
                die("Error execute UPDATE: " . $stmt->error);
            }
            $stmt->close();
            header("Location: inventario.php?saved=1");
            exit;
        } else {
            // INSERT
            $stmt = $conn->prepare("INSERT INTO inventario_productos (nombre, precio, cantidad) VALUES (?,?,?)");
            if (!$stmt) {
                die("Error prepare INSERT: " . $conn->error);
            }
            $stmt->bind_param('sii', $nombre, $precio, $cantidad);
            if (!$stmt->execute()) {
                die("Error execute INSERT: " . $stmt->error);
            }
            $stmt->close();
            header("Location: inventario.php?saved=1");
            exit;
        }
    }

// ---- DESACTIVAR PRODUCTO (no borrar realmente) ----
if ($accion === 'borrar') {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        die("ID inválido para desactivar.");
    }

    $stmt = $conn->prepare("UPDATE inventario_productos SET activo = 0 WHERE id = ?");
    if (!$stmt) {
        die("Error prepare UPDATE activo: " . $conn->error);
    }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        die("Error execute UPDATE activo: " . $stmt->error);
    }
    $stmt->close();

    header("Location: inventario.php?deleted=1");
    exit;
}

}

// ================== CONSULTA LISTADO ==================
$res=$conn->query("SELECT * FROM inventario_productos WHERE activo = 1 ORDER BY nombre ASC");
if (!$res) {
    die("Error SELECT inventario_productos: " . $conn->error);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Inventario</title>
<style>
body{font-family:Arial;margin:40px;background:#f7f7f7;color:#222;}
table{border-collapse:collapse;width:100%;margin-top:20px;}
th,td{border:1px solid #ccc;padding:8px;text-align:center;}
input[type=text],input[type=number]{width:100%;padding:4px;}
form.inline{display:inline;}
.btn{padding:4px 8px;cursor:pointer;border:none;border-radius:4px;}
.btn-save{background:#4caf50;color:#fff;}
.btn-del{background:#e53935;color:#fff;}
h1{text-align:center;}
.notice{margin:10px auto;max-width:400px;padding:8px 12px;border-radius:6px;font-size:14px;}
.notice.ok{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;}
.notice.del{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
</style>
</head>
<body>

<div style="text-align:right;">
  <a href="?logout=1" style="text-decoration:none; color:#d00; font-weight:bold;">🔒 Cerrar sesión</a>
</div>

<h1>Inventario</h1>

<?php if(isset($_GET['saved'])): ?>
  <div class="notice ok">Producto guardado correctamente.</div>
<?php endif; ?>
<?php if(isset($_GET['deleted'])): ?>
  <div class="notice del">Producto desactivado correctamente.</div>
<?php endif; ?>

<!-- ALTA NUEVA FILA -->
<form method="POST">
  <input type="hidden" name="id" value="">
  <input type="hidden" name="accion" value="guardar">
  <table>
    <tr><th>Producto</th><th>Precio Unidad ($)</th><th>Cantidad</th><th>Acciones</th></tr>
    <tr>
      <td><input type="text" name="nombre" required></td>
      <td><input type="number" name="precio" min="0" step="1" required></td>
      <td><input type="number" name="cantidad" min="0" step="1" required></td>
      <td><button class="btn btn-save" type="submit">Agregar</button></td>
    </tr>
  </table>
</form>

<!-- LISTADO + EDICIÓN / BORRADO -->
<table>
  <tr><th>Producto</th><th>Precio Unidad</th><th>Cantidad</th><th>Acciones</th></tr>
  <?php while($p = $res->fetch_assoc()): ?>
    <tr>
      <td>
        <form method="POST" class="inline">
          <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
          <input type="hidden" name="accion" value="guardar">
          <input type="text" name="nombre" value="<?php echo htmlspecialchars($p['nombre'], ENT_QUOTES, 'UTF-8'); ?>">
      </td>
      <td>
          <input type="number" name="precio" value="<?php echo (int)$p['precio']; ?>" min="0" step="1">
      </td>
      <td>
          <input type="number" name="cantidad" value="<?php echo (int)$p['cantidad']; ?>" min="0" step="1">
      </td>
      <td>
          <button class="btn btn-save" type="submit">💾</button>
        </form>

        <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar producto?');">
          <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
          <input type="hidden" name="accion" value="borrar">
          <button class="btn btn-del" type="submit">🗑️</button>
        </form>
      </td>
    </tr>
  <?php endwhile; ?>
</table>

</body>
</html>
