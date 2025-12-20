<?php
file_put_contents(__DIR__."/webhook-log.txt", date("Y-m-d H:i:s")." - Webhook hit: ".file_get_contents("php://input")."\n", FILE_APPEND);

require __DIR__ . '/vendor/autoload.php';

MercadoPago\SDK::setAccessToken("APP_USR-4518252275853191-112421-370721fbc465852fcb25cc7cba42e681-59176727");

// ==== Configuración DB centralizada ====
$DB_CONFIG = [
    'host' => '127.0.0.1',
    'user' => 'u460517132_F5bOi',
    'pass' => 'mDjVQbpI5A',
    'name' => 'u460517132_GxbHQ',
];

function db(): mysqli {
    global $DB_CONFIG;
    $conn = new mysqli($DB_CONFIG['host'], $DB_CONFIG['user'], $DB_CONFIG['pass'], $DB_CONFIG['name']);
    if ($conn->connect_error) {
        http_response_code(500);
        exit;
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function ensurePagosOnlineHabitaciones(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS pagos_online_habitaciones (
      id INT AUTO_INCREMENT PRIMARY KEY,
      habitacion INT NOT NULL,
      turno VARCHAR(30) NOT NULL,
      bloques INT NOT NULL DEFAULT 1,
      monto INT NOT NULL DEFAULT 0,
      payment_id VARCHAR(80) NULL,
      created_at DATETIME NOT NULL,
      avisado TINYINT(1) NOT NULL DEFAULT 0,
      INDEX(habitacion), INDEX(avisado),
      UNIQUE KEY unique_payment_id (payment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    @$conn->query("ALTER TABLE pagos_online_habitaciones ADD COLUMN payment_id VARCHAR(80) NULL");
    @$conn->query("ALTER TABLE pagos_online_habitaciones ADD UNIQUE KEY unique_payment_id (payment_id)");
}

function ensurePagosMp(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS pagos_mp (
      id INT AUTO_INCREMENT PRIMARY KEY,
      payment_id VARCHAR(80) NOT NULL,
      codigo VARCHAR(10) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY(payment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

function safePaymentById($payment_id){
    try {
        return MercadoPago\Payment::find_by_id($payment_id);
    } catch (Throwable $e) {
        return null;
    }
}

// ==== Recibir notificación ====
$body = file_get_contents("php://input");
$data = json_decode($body, true);

if(!isset($data['data']['id'])){
    http_response_code(200);
    exit;
}

$payment_id = $data['data']['id'];
$payment = safePaymentById($payment_id);
if(!$payment){
    http_response_code(200);
    exit;
}

// Solo procesamos pagos aprobados
if($payment->status !== "approved"){
    http_response_code(200);
    exit;
}

$metadata = $payment->metadata ?? new stdClass();
$kind     = $metadata->kind ?? '';

// ======================
// 🔥 Pedidos de MINIBAR
// ======================
if ($kind === 'minibar') {
    $pedidoId   = intval($metadata->pedido_id ?? 0);
    $habitacion = intval($metadata->habitacion ?? 0);

    if($pedidoId <= 0){ http_response_code(200); exit; }

    $conn = db();

    $conn->query("CREATE TABLE IF NOT EXISTS minibar_pedidos (
      id INT AUTO_INCREMENT PRIMARY KEY,
      habitacion INT NOT NULL,
      items JSON NOT NULL,
      total INT NOT NULL DEFAULT 0,
      estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
      preference_id VARCHAR(80) NULL,
      payment_id VARCHAR(80) NULL,
      avisado TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL,
      paid_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $stmt = $conn->prepare("SELECT items, estado FROM minibar_pedidos WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $pedidoId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$res){ http_response_code(200); exit; }

    if(($res['estado'] ?? '') !== 'pagado'){
        // Marcar pago aprobado
        $upd = $conn->prepare("UPDATE minibar_pedidos SET estado='pagado', payment_id=?, paid_at=NOW() WHERE id=?");
        $pid = strval($payment_id);
        $upd->bind_param('si', $pid, $pedidoId);
        $upd->execute();
        $upd->close();

        // Descontar stock por cada item
        $items = json_decode($res['items'] ?? '[]', true);
        if(is_array($items)){
            foreach($items as $it){
                $prodId = intval($it['id'] ?? 0);
                $cant   = intval($it['cantidad'] ?? 0);
                if($prodId>0 && $cant>0){
                    $q = $conn->prepare("UPDATE inventario_productos SET cantidad = GREATEST(cantidad-?,0), total_turno = GREATEST(total_turno-?,0) WHERE id=?");
                    $q->bind_param('iii', $cant, $cant, $prodId);
                    $q->execute();
                    $q->close();
                }
            }
        }
    }
    http_response_code(200);
    exit;
}
// ======================
// 🔥 Turnos extra
// ======================
if ($kind === 'extra_turno') {
    $habitacion = intval($metadata->habitacion ?? 0);

    if ($habitacion <= 0) {
        http_response_code(200);
        exit;
    }

    $conn = db();

    $roomStmt = $conn->prepare("SELECT estado FROM habitaciones WHERE id=? LIMIT 1");
    $roomStmt->bind_param('i', $habitacion);
    $roomStmt->execute();
    $room = $roomStmt->get_result()->fetch_assoc();
    $roomStmt->close();

    if (($room['estado'] ?? '') !== 'ocupada') {
        http_response_code(200);
        exit;
    }

    $hist = $conn->prepare("SELECT id, bloques, precio_aplicado FROM historial_habitaciones WHERE habitacion=? AND hora_fin IS NULL ORDER BY id DESC LIMIT 1");
    $hist->bind_param('i', $habitacion);
    $hist->execute();
    $cur = $hist->get_result()->fetch_assoc();
    $hist->close();

    if (!$cur) { http_response_code(200); exit; }

    $extra = (int) round($payment->transaction_amount ?? 0);
    $nuevoPrecio = max(0, (int) ($cur['precio_aplicado'] ?? 0)) + $extra;
    $nuevosBloques = max(1, (int) ($cur['bloques'] ?? 1)) + 1;

    $upd = $conn->prepare("UPDATE historial_habitaciones SET bloques=?, precio_aplicado=? WHERE id=?");
    $upd->bind_param('iii', $nuevosBloques, $nuevoPrecio, $cur['id']);
    $upd->execute();
    $upd->close();

    http_response_code(200);
    exit;
}
// 🔥 Envío de dinero (ajuste digital de caja)
// ======================
if ($kind === 'envio_dinero') {
    $habitacion = intval($metadata->habitacion ?? 0);
    $monto      = (int) round($payment->transaction_amount ?? 0);

    if ($habitacion <= 0 || $monto < 0) { http_response_code(200); exit; }

    $conn = db();
    ensurePagosOnlineHabitaciones($conn);
    
    $roomStmt = $conn->prepare("SELECT estado FROM habitaciones WHERE id=? LIMIT 1");
    $roomStmt->bind_param('i', $habitacion);
    $roomStmt->execute();
    $room = $roomStmt->get_result()->fetch_assoc();
    $roomStmt->close();

    if (($room['estado'] ?? '') !== 'ocupada') {
        http_response_code(200);
        exit;
    }

    $turnoTxt = 'envio-dinero';
    $bloques  = 0;
    $pidStr = strval($payment_id);
    $ins = $conn->prepare("INSERT IGNORE INTO pagos_online_habitaciones (habitacion, turno, bloques, monto, payment_id, created_at) VALUES (?,?,?,?,?,NOW())");
    $ins->bind_param('isiis', $habitacion, $turnoTxt, $bloques, $monto, $pidStr);
    $ins->execute();
    $ins->close();

    http_response_code(200);
    exit;
}
// ======================
// ======================
// 🔥 Pago online de turnos ya asignados
// ======================
if ($kind === 'turno_online') {
    $habitacion = intval($metadata->habitacion ?? 0);
    $turno      = strval($metadata->turno ?? '');
    $cantidad   = max(1, min(3, intval($metadata->cantidad ?? 1)));

    $conn = db();
    ensurePagosOnlineHabitaciones($conn);

    $conn->query("CREATE TABLE IF NOT EXISTS pagos_online_habitaciones (
      id INT AUTO_INCREMENT PRIMARY KEY,
      habitacion INT NOT NULL,
      turno VARCHAR(30) NOT NULL,
      bloques INT NOT NULL DEFAULT 1,
      monto INT NOT NULL DEFAULT 0,
      payment_id VARCHAR(80) NULL,
      created_at DATETIME NOT NULL,
      avisado TINYINT(1) NOT NULL DEFAULT 0,
      INDEX(habitacion), INDEX(avisado),
      UNIQUE KEY unique_payment_id (payment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $hist = $conn->prepare("SELECT id, bloques, precio_aplicado FROM historial_habitaciones WHERE habitacion=? AND hora_fin IS NULL ORDER BY id DESC LIMIT 1");
    $hist->bind_param('i', $habitacion);
    $hist->execute();
    $cur = $hist->get_result()->fetch_assoc();
    $hist->close();

    $pagados = ($turno === 'noche' || $turno === 'noche-finde') ? 1 : $cantidad;
    $monto   = (int) round($payment->transaction_amount ?? 0);

    if ($cur) {
        $nuevoBloque = max((int)($cur['bloques'] ?? 1), $pagados);
        if ($monto <= 0) { $monto = (int) ($cur['precio_aplicado'] ?? 0); }

        $upd = $conn->prepare("UPDATE historial_habitaciones SET bloques=?, precio_aplicado=? WHERE id=?");
        $upd->bind_param('iii', $nuevoBloque, $monto, $cur['id']);
        $upd->execute();
        $upd->close();
    }

    $pidStr = strval($payment_id);
    $ins = $conn->prepare("INSERT IGNORE INTO pagos_online_habitaciones (habitacion, turno, bloques, monto, payment_id, created_at) VALUES (?,?,?,?,?,NOW())");
    $ins->bind_param('isiis', $habitacion, $turno, $pagados, $monto, $pidStr);
    $ins->execute();
    $ins->close();

    http_response_code(200);
    exit;
}


// Ignorar otros pagos que tengan metadata de otro tipo
if ($kind && $kind !== 'reserva') {
    http_response_code(200);
    exit;
}

// ======================
// 🔥 Reservas normales
// ======================
// ==== Sacar tipo y turno desde metadata ====

$tipoSeleccionado  = $metadata->tipo ?? '';
$turnoSeleccionado = $metadata->turno ?? '';
$cantidadTurnos    = max(1, min(3, intval($metadata->cantidad ?? 1)));

if(!$tipoSeleccionado || !$turnoSeleccionado){
    http_response_code(200);
    exit;
}

$conn = db();
ensurePagosMp($conn);


// ==== Config y funciones copiadas de tu API ====
$SUPER_VIP = [20,21];
$VIP_LIST  = [3,4,11,12,13,28,29,30,37,38];

function nowArgDT(){ return new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')); }
function nowUTCStrFromArg(){ $dt=nowArgDT(); $dt->setTimezone(new DateTimeZone('UTC')); return $dt->format('Y-m-d H:i:s'); }
function argDateToday(){ return nowArgDT()->format('Y-m-d'); }
function argNowInfo(){ $dt=nowArgDT(); return [(int)$dt->format('w'), (int)$dt->format('G')]; }
function isTwoHourWindowNow(int $dow, int $hour){
  return ($dow===5 && $hour>=8) || $dow===6 || $dow===0; // Vie 8am → Dom 23:59
}
function isNocheFindeNow(int $dow, int $hour){
  if($dow===5 && $hour>=21) return true;
  if($dow===6 && ($hour<10 || $hour>=21)) return true;
  if($dow===0 && $hour<10) return true;
  return false;
}

function tipoDeHabitacion($id,$SUPER_VIP,$VIP_LIST){
  if(in_array($id,$SUPER_VIP)) return 'Super VIP';
  if(in_array($id,$VIP_LIST))  return 'VIP';
  return 'Común';
}

// ==== Determinar turnoTag igual que tu API ====
list($dow,$hour)=argNowInfo();

if ($turnoSeleccionado === 'noche') {
    // noche de finde
    $turnoTag = isNocheFindeNow($dow,$hour) ? 'noche-finde' : 'noche';
} else {
    // 2h o 3h según día
    $turnoTag = isTwoHourWindowNow($dow,$hour) ? 'turno-2h' : 'turno-3h';
}
$precioAplicado = (int)round($payment->transaction_amount ?? 0);

$startUTC = nowUTCStrFromArg();
$fecha    = argDateToday();

// ==== Buscar habitación libre igual que tu API ====
if($tipoSeleccionado==='Común'){
  $ids = range(1,40);
  $ids = array_diff($ids, $VIP_LIST, $SUPER_VIP);
} elseif($tipoSeleccionado==='VIP'){
  $ids = $VIP_LIST;
} else {
  $ids = $SUPER_VIP;
}

// Priorizar nuevas (de 11 a 20 y 30 a 21) pero solo dentro del tipo elegido
$prioridad = array_merge(range(11,20), range(30,21));
$ordenadas = array_values(array_unique(array_merge(array_intersect($prioridad, $ids), $ids)));

$habitacionId = null;
foreach($ordenadas as $id){
  $q = $conn->prepare("SELECT estado FROM habitaciones WHERE id=?");
  $q->bind_param('i',$id);
  $q->execute();
  $r = $q->get_result()->fetch_assoc();
  if($r && $r['estado']==='libre'){
    $habitacionId = $id;
    break;
  }
}

if(!$habitacionId){
    // si no hay habitación, simplemente no hacemos reserva
    http_response_code(200);
    exit;
}

// ==== Marcar como reservada ====
$st = $conn->prepare("UPDATE habitaciones SET estado='reservada', tipo_turno=?, hora_inicio=? WHERE id=?");
$st->bind_param('ssi', $turnoTag, $startUTC, $habitacionId);
$st->execute();
$st->close();

// ==== Generar código EXACTO igual que tu API ====
$codigo = strtoupper(substr(md5(uniqid('', true)), 0, 4));
$tipo   = tipoDeHabitacion($habitacionId, $SUPER_VIP, $VIP_LIST);
$estado = 'reservada';

// ==== Insertar en historial_habitaciones igual que tu API ====
$ins = $conn->prepare("INSERT INTO historial_habitaciones (habitacion,codigo,tipo,estado,turno,hora_inicio,fecha_registro,precio_aplicado,bloques) VALUES (?,?,?,?,?,?,?,?,?)");
$ins->bind_param('issssssii', $habitacionId, $codigo, $tipo, $estado, $turnoTag, $startUTC, $fecha, $precioAplicado, $cantidadTurnos);
$ins->execute();
$ins->close();

// ==== Vincular payment_id con código para gracias.php ====
$insPago = $conn->prepare("INSERT INTO pagos_mp (payment_id, codigo) VALUES (?, ?) ON DUPLICATE KEY UPDATE codigo=VALUES(codigo), created_at=CURRENT_TIMESTAMP");
$pidStr = strval($payment_id);
$insPago->bind_param('ss', $pidStr, $codigo);
$insPago->execute();
$insPago->close();

http_response_code(200);
