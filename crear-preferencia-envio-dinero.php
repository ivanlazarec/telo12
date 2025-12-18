<?php
require __DIR__ . '/vendor/autoload.php';

MercadoPago\SDK::setAccessToken("APP_USR-4518252275853191-112421-370721fbc465852fcb25cc7cba42e681-59176727");

header('Content-Type: application/json');

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

$habitacion = intval($_POST['habitacion'] ?? 0);
$monto      = max(1, intval($_POST['monto'] ?? 0));

if ($habitacion <= 0 || $habitacion > 40) {
    echo json_encode(['error' => 'Habitación inválida']);
    exit;
}

if ($monto <= 0) {
    echo json_encode(['error' => 'El monto debe ser mayor a 0']);
    exit;
}

$preference = new MercadoPago\Preference();
$item = new MercadoPago\Item();
$item->title = "Envío digital para Hab. #$habitacion";
$item->quantity = 1;
$item->unit_price = $monto;
$preference->items = [$item];
$preference->metadata = [
    'kind' => 'envio_dinero',
    'habitacion' => $habitacion,
    'monto' => $monto
];
$preference->back_urls = [
    'success' => 'https://lamoradatandil.com/gracias-envio-dinero.php',
    'pending' => 'https://lamoradatandil.com/gracias-envio-dinero.php',
    'failure' => 'https://lamoradatandil.com/gracias-envio-dinero.php'
];
$preference->auto_return = 'approved';
$preference->notification_url = 'https://lamoradatandil.com/mp-webhook.php';

$preference->save();

$conn->close();

echo json_encode([
    'init_point' => $preference->init_point,
    'mensaje' => 'Redirigiendo al pago seguro...'
]);