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

$SUPER_VIP = [20,21];
$VIP_LIST  = [3,4,11,12,13,28,29,30,37,38];

function tipoDeHabitacion($id, $SUPER_VIP, $VIP_LIST) {
    if (in_array($id, $SUPER_VIP)) return 'Super VIP';
    if (in_array($id, $VIP_LIST))  return 'VIP';
    return 'Común';
}

function precioVigenteInt($conn, $tipo, $turno) {
    $p = 0.0;
    $st = $conn->prepare("SELECT precio FROM precios_habitaciones WHERE tipo=? AND turno=? LIMIT 1");
    $st->bind_param('ss', $tipo, $turno);
    $st->execute();
    $res = $st->get_result();
    if ($row = $res->fetch_assoc()) {
        $p = floatval($row['precio']);
    }
    $st->close();
    return (int) round($p, 0);
}

$habitacion = intval($_POST['habitacion'] ?? 0);

if ($habitacion <= 0) {
    echo json_encode(['error' => 'Habitación inválida']);
    exit;
}

$room = $conn->prepare("SELECT estado, tipo_turno FROM habitaciones WHERE id=? LIMIT 1");
$room->bind_param('i', $habitacion);
$room->execute();
$roomRes = $room->get_result()->fetch_assoc();
$room->close();

if (($roomRes['estado'] ?? '') !== 'ocupada') {
    echo json_encode([
        'error' => 'La pieza que intentás agregar turno está libre. Si deseás sacar un turno nuevo podés hacerlo online.',
        'reserva_link' => 'https://lamoradatandil.com/reserva-publica.php'
    ]);
    exit;
}

$hist = $conn->prepare("SELECT id, tipo, turno, bloques, precio_aplicado FROM historial_habitaciones WHERE habitacion=? AND hora_fin IS NULL ORDER BY id DESC LIMIT 1");
$hist->bind_param('i', $habitacion);
$hist->execute();
$cur = $hist->get_result()->fetch_assoc();
$hist->close();

if (!$cur) {
    echo json_encode([
        'error' => 'No encontramos una estadía activa en esa habitación.',
        'reserva_link' => 'https://lamoradatandil.com/reserva-publica.php'
    ]);
    exit;
}

$tipoActual = $cur['tipo'] ?: tipoDeHabitacion($habitacion, $SUPER_VIP, $VIP_LIST);
$turnoActual = $cur['turno'] ?: ($roomRes['tipo_turno'] ?? '');

if (!$turnoActual || !in_array($turnoActual, ['turno-2h', 'turno-3h'])) {
    echo json_encode(['error' => 'Solo podés agregar turnos extra a estadías de turno corto.']);
    exit;
}

$precioExtra = precioVigenteInt($conn, $tipoActual, $turnoActual);

if ($precioExtra <= 0) {
    echo json_encode(['error' => 'No hay un precio configurado para este tipo de habitación.']);
    exit;
}

$preference = new MercadoPago\Preference();

$item = new MercadoPago\Item();
$item->title = "Turno extra habitación #$habitacion";
$item->quantity = 1;
$item->unit_price = $precioExtra;

$preference->items = [$item];
$preference->metadata = [
    'kind' => 'extra_turno',
    'habitacion' => $habitacion
];
$preference->back_urls = [
    'success' => 'https://lamoradatandil.com/gracias.php',
    'pending' => 'https://lamoradatandil.com/gracias.php',
    'failure' => 'https://lamoradatandil.com/gracias.php'
];
$preference->auto_return = 'approved';
$preference->notification_url = 'https://lamoradatandil.com/mp-webhook.php';

$preference->save();

$conn->close();

echo json_encode([
    'init_point' => $preference->init_point,
    'mensaje' => 'Redirigiendo al pago seguro de Mercado Pago...'
]);