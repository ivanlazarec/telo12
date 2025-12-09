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

function nowArgDT(){ return new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')); }
function argNowInfo(){ $dt=nowArgDT(); return [(int)$dt->format('w'), (int)$dt->format('G')]; }
function isTwoHourWindowNow(int $dow, int $hour){
  return ($dow===5 && $hour>=8) || $dow===6 || $dow===0; // Vie 8am → Dom 23:59
}
function isNightWindow(int $hour){
  return $hour >= 21 || $hour < 10;
}
function isNocheFindeNow(int $dow, int $hour){
  if($dow===5 && $hour>=21) return true;
  if($dow===6 && ($hour<10 || $hour>=21)) return true;
  if($dow===0 && $hour<10) return true;
  return false;
}
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
$modo       = $_POST['modo'] ?? 'turno';
$cantidad   = max(1, min(3, intval($_POST['cantidad'] ?? 1)));

if ($habitacion <= 0) {
    echo json_encode(['error' => 'Habitación inválida']);
    exit;
}

if ($modo === 'noche') {
    $cantidad = 1; // la noche siempre es una sola
}

$room = $conn->prepare("SELECT estado, tipo_turno FROM habitaciones WHERE id=? LIMIT 1");
$room->bind_param('i', $habitacion);
$room->execute();
$roomRes = $room->get_result()->fetch_assoc();
$room->close();

if (($roomRes['estado'] ?? '') !== 'ocupada') {
    echo json_encode([
        'error' => 'La habitación indicada no está ocupada en este momento.'
    ]);
    exit;
}

list($dow,$hour) = argNowInfo();
$turnoTag = 'turno-3h';
if ($modo === 'noche') {
    if(!isNightWindow($hour)){
        echo json_encode(['error' => 'El turno noche solo está disponible de 21:00 a 10:00.']);
        exit;
    }
    $turnoTag = isNocheFindeNow($dow,$hour) ? 'noche-finde' : 'noche';
} else {
    $turnoTag = isTwoHourWindowNow($dow,$hour) ? 'turno-2h' : 'turno-3h';
}

$tipoHab = tipoDeHabitacion($habitacion, $SUPER_VIP, $VIP_LIST);
$precioUnit = precioVigenteInt($conn, $tipoHab, $turnoTag);

if ($precioUnit <= 0) {
    echo json_encode(['error' => 'No hay un precio configurado para esta combinación.']);
    exit;
}

$montoTotal = $precioUnit * $cantidad;

$preference = new MercadoPago\Preference();
$item = new MercadoPago\Item();
$item->title = "Habitación #$habitacion - $turnoTag";
$item->quantity = $cantidad;
$item->unit_price = $precioUnit;
$preference->items = [$item];
$preference->metadata = [
    'kind' => 'turno_online',
    'habitacion' => $habitacion,
    'turno' => $turnoTag,
    'cantidad' => $cantidad
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
    'mensaje' => 'Redirigiendo al pago seguro...'
]);