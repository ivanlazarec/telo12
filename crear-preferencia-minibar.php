<?php
require __DIR__ . '/vendor/autoload.php';

MercadoPago\SDK::setAccessToken("APP_USR-4518252275853191-112421-370721fbc465852fcb25cc7cba42e681-59176727");

$servername = "127.0.0.1";
$username   = "u460517132_F5bOi";
$password   = "mDjVQbpI5A";
$dbname     = "u460517132_GxbHQ";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de conexión']);
    exit;
}

header('Content-Type: application/json');

$habitacion = intval($_POST['habitacion'] ?? 0);
$itemsRaw   = $_POST['items'] ?? '';

if ($habitacion < 1 || $habitacion > 40) {
    echo json_encode(['error' => 'Habitación inválida']);
    exit;
}

$items = json_decode($itemsRaw, true);
if (!is_array($items) || empty($items)) {
    echo json_encode(['error' => 'Sin productos seleccionados']);
    exit;
}

$productIds = array_column($items, 'id');
$placeholders = implode(',', array_fill(0, count($productIds), '?'));
$types = str_repeat('i', count($productIds));

$stmt = $conn->prepare("SELECT id, nombre, precio, cantidad FROM inventario_productos WHERE activo = 1 AND id IN ($placeholders)");
$stmt->bind_param($types, ...$productIds);
$stmt->execute();
$res = $stmt->get_result();
$productos = [];
while ($r = $res->fetch_assoc()) {
    $productos[$r['id']] = $r;
}
$stmt->close();

$detalle = [];
$total = 0;
foreach ($items as $it) {
    $pid = intval($it['id'] ?? 0);
    $cant = max(0, intval($it['cantidad'] ?? 0));
    if ($pid <= 0 || $cant <= 0) continue;
    if (!isset($productos[$pid])) {
        echo json_encode(['error' => 'Producto inválido']);
        exit;
    }
    if ($cant > intval($productos[$pid]['cantidad'])) {
        echo json_encode(['error' => 'Sin stock para '.($productos[$pid]['nombre'] ?? 'producto')]);
        exit;
    }
    $precio = intval($productos[$pid]['precio']);
    $detalle[] = [
        'id' => $pid,
        'nombre' => $productos[$pid]['nombre'],
        'precio' => $precio,
        'cantidad' => $cant
    ];
    $total += $precio * $cant;
}

if ($total <= 0) {
    echo json_encode(['error' => 'Total inválido']);
    exit;
}

// Guardar pedido en DB
$stmt = $conn->prepare("INSERT INTO minibar_pedidos (habitacion, items, total, estado, created_at) VALUES (?,?,?,?,NOW())");
$jsonItems = json_encode($detalle, JSON_UNESCAPED_UNICODE);
$estado = 'pendiente';
$stmt->bind_param('isis', $habitacion, $jsonItems, $total, $estado);
$stmt->execute();
$pedidoId = $stmt->insert_id;
$stmt->close();

$preference = new MercadoPago\Preference();

$preference->items = array_map(function($p){
    $item = new MercadoPago\Item();
    $item->title = $p['nombre'];
    $item->quantity = $p['cantidad'];
    $item->unit_price = $p['precio'];
    return $item;
}, $detalle);

$preference->back_urls = [
    "success" => "https://lamoradatandil.com/gracias-minibar.php",
    "pending" => "https://lamoradatandil.com/gracias-minibar.php",
    "failure" => "https://lamoradatandil.com/gracias-minibar.php"
];
$preference->auto_return = "approved";
$preference->notification_url = "https://lamoradatandil.com/mp-webhook.php";
$preference->metadata = [
    'kind' => 'minibar',
    'pedido_id' => $pedidoId,
    'habitacion' => $habitacion
];

$preference->save();

// Guardar referencia
$prefId = $preference->id;
$upd = $conn->prepare("UPDATE minibar_pedidos SET preference_id=? WHERE id=?");
$upd->bind_param('si', $prefId, $pedidoId);
$upd->execute();
$upd->close();
$conn->close();

echo json_encode(['init_point' => $preference->init_point]);