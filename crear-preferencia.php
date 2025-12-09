<?php
require __DIR__ . '/vendor/autoload.php';

MercadoPago\SDK::setAccessToken("APP_USR-4518252275853191-112421-370721fbc465852fcb25cc7cba42e681-59176727");



// ==== Conexión a la base (misma que api-reservar.php) ====
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

// ==== Configuración de tipos de habitación ====
$SUPER_VIP = [20,21];
$VIP_LIST  = [3,4,11,12,13,28,29,30,37,38];

function habitacionesPorTipo($tipo, $SUPER_VIP, $VIP_LIST){
    if($tipo==='Común'){
        $ids = range(1,40);
        return array_values(array_diff($ids, $VIP_LIST, $SUPER_VIP));
    }
    if($tipo==='VIP') return $VIP_LIST;
    return $SUPER_VIP;
}

// ==== Funciones de fecha/hora iguales a tu API ====
function nowArgDT(){ return new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')); }
function argNowInfo(){ $dt=nowArgDT(); return [(int)$dt->format('w'), (int)$dt->format('G')]; } // 0=Dom..6=Sab
function isTwoHourWindowNow(int $dow, int $hour){
  return ($dow===5 && $hour>=8) || $dow===6 || $dow===0; // Vie 8am → Dom 23:59
}
function isNocheFindeNow(int $dow, int $hour){
  if($dow===5 && $hour>=21) return true;
  if($dow===6 && ($hour<10 || $hour>=21)) return true;
  if($dow===0 && $hour<10) return true;
  return false;
}

header('Content-Type: application/json');

// ==== Datos que vienen del formulario ====
$tipoSeleccionado  = $_POST['tipo']  ?? '';
$turnoSeleccionado = $_POST['turno'] ?? '';
$cantidadTurnos    = max(1, min(3, intval($_POST['cantidad'] ?? 1)));

if(!$tipoSeleccionado || !$turnoSeleccionado){
    echo json_encode(['error' => 'Datos incompletos']);
    exit;
}

// ==== Verificar disponibilidad del tipo elegido ====
$ids = habitacionesPorTipo($tipoSeleccionado, $SUPER_VIP, $VIP_LIST);
$ids = array_map('intval', $ids);

if(empty($ids)){
    echo json_encode(['error' => 'No hay habitaciones configuradas para ese tipo']);
    $conn->close();
    exit;
}

$idList = implode(',', $ids);
$dispSql = "SELECT COUNT(*) AS libres FROM habitaciones WHERE id IN ($idList) AND estado='libre'";
$dispRes = $conn->query($dispSql);
$libres = $dispRes ? intval($dispRes->fetch_assoc()['libres'] ?? 0) : 0;

if($libres <= 0){
    echo json_encode(['error' => 'No hay habitaciones disponibles para ese tipo ahora mismo']);
    $conn->close();
    exit;
}

// ==== Calcular turno de precio igual que en api-reservar.php ====
// (2h / 3h / noche / noche-finde)
list($dow,$hour) = argNowInfo();

// Ajustar turno según reglas vigentes
if ($turnoSeleccionado === 'noche' && isNocheFindeNow($dow,$hour)) {
    $turnoBD = 'noche-finde';
} elseif ($turnoSeleccionado === 'noche') {
    $turnoBD = 'noche';
} else {
   $turnoBD = isTwoHourWindowNow($dow,$hour) ? 'turno-2h' : 'turno-3h';
}

// ==== Buscar precio en precios_habitaciones ====
$stmt = $conn->prepare("SELECT precio FROM precios_habitaciones WHERE tipo=? AND turno=?");
$stmt->bind_param("ss", $tipoSeleccionado, $turnoBD);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

$precio = floatval($res['precio'] ?? 0) * $cantidadTurnos;

if($precio <= 0){
    echo json_encode(['error' => 'No se encontró precio para esa combinación']);
    exit;
}

// ==== Crear preferencia de MP ====
$preference = new MercadoPago\Preference();

$item = new MercadoPago\Item();
$item->title = "Reserva: $tipoSeleccionado ($turnoSeleccionado)";
$item->quantity = $cantidadTurnos;
$item->unit_price = $precio / $cantidadTurnos;

$preference->items = [$item];

// Guardamos los datos del turno en metadata
$preference->metadata = [
     "tipo"   => $tipoSeleccionado,
    "turno" => $turnoSeleccionado,
    "cantidad" => $cantidadTurnos
];

// Donde vuelve el usuario luego del pago
$preference->back_urls = [
    "success" => "https://lamoradatandil.com/gracias.php",
    "pending" => "https://lamoradatandil.com/gracias.php",
    "failure" => "https://lamoradatandil.com/gracias.php"
];
$preference->auto_return = "approved";

// Webhook para confirmar pagos
$preference->notification_url = "https://lamoradatandil.com/mp-webhook.php";

$preference->save();

echo json_encode([
    'init_point' => $preference->init_point
]);