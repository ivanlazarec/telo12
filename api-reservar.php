<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

/*==================== Conexión ====================*/
$servername = "127.0.0.1";
$username   = "u460517132_F5bOi";
$password   = "mDjVQbpI5A";
$dbname     = "u460517132_GxbHQ";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { 
  http_response_code(500); 
  echo json_encode(['error' => 'Error de conexión a la base de datos']);
  exit;
}

/* ====== CONSULTA DE PRECIO SEGÚN TIPO Y TURNO ====== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_precio'])) {
    $tipo = $_POST['tipo'] ?? '';
    $turno = $_POST['turno'] ?? '';
    $cantidadTurnos = max(1, min(3, intval($_POST['cantidad'] ?? 1)));
list($dow,$hour) = argNowInfo();

// Ajustar a las reglas vigentes según día/horario
if ($turno === 'noche' && isNocheFindeNow($dow,$hour)) {
    $turno = 'noche-finde';
} elseif ($turno !== 'noche') {
    $turno = isTwoHourWindowNow($dow,$hour) ? '2h' : '3h';
}


    $turnoMap = [
  '2h' => 'turno-2h',
  '3h' => 'turno-3h',
  'noche' => 'noche'
];
$turnoBD = $turnoMap[$turno] ?? $turno;

$stmt = $conn->prepare("SELECT precio FROM precios_habitaciones WHERE tipo=? AND turno=?");
$stmt->bind_param("ss", $tipo, $turnoBD);

    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();

     $precioBase = floatval($res['precio'] ?? 0);
    echo json_encode(['precio' => $precioBase * $cantidadTurnos]);
    $stmt->close();
    $conn->close();
    exit; // 🚨 Detiene la ejecución aquí, no sigue con la lógica de reserva
}

/*=================== Config ===================*/
$SUPER_VIP = [20,21];
$VIP_LIST  = [3,4,11,12,13,28,29,30,37,38];

function tipoDeHabitacion($id,$SUPER_VIP,$VIP_LIST){
  if(in_array($id,$SUPER_VIP)) return 'Super VIP';
  if(in_array($id,$VIP_LIST))  return 'VIP';
  return 'Común';
}

function nowArgDT(){ return new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')); }
function nowUTCStrFromArg(){ $dt=nowArgDT(); $dt->setTimezone(new DateTimeZone('UTC')); return $dt->format('Y-m-d H:i:s'); }
function argDateToday(){ return nowArgDT()->format('Y-m-d'); }
function argNowInfo(){ $dt=nowArgDT(); return [(int)$dt->format('w'), (int)$dt->format('G')]; } // 0=Dom..6=Sab
function isTwoHourWindowNow(int $dow, int $hour){
  return ($dow===5 && $hour>=8) || $dow===6 || $dow===0; // Vie 8am → Dom 23:59
}
function isNocheFindeNow(int $dow, int $hour){
  if($dow===5 && $hour>=21) return true;              // Vie 21 → 23:59
  if($dow===6 && ($hour<10 || $hour>=21)) return true; // Sáb 00–09:59 y 21–23:59
  if($dow===0 && $hour<10) return true;                // Dom 00–09:59
  return false;
}
function nightEndTsFromStartArg($startTs){ $dt=new DateTime('@'.$startTs); $dt->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires')); if((int)$dt->format('G')>=21){$dt->modify('+1 day');} $dt->setTime(10,0,0); return $dt->getTimestamp(); }

/*=================== Lógica ===================*/
$tipoSeleccionado = $_POST['tipo'] ?? '';
$turnoSeleccionado = $_POST['turno'] ?? '';
$cantidadTurnos = max(1, min(3, intval($_POST['cantidad'] ?? 1)));

if(!$tipoSeleccionado || !$turnoSeleccionado){
  echo json_encode(['success'=>false,'error'=>'Datos incompletos']);
  exit;
}

// Turno y duración
list($dow,$hour)=argNowInfo();
// Detectar noche de fin de semana
if ($turnoSeleccionado === 'noche') {
    // si hoy es viernes (5) o sábado (6), usamos noche-finde
     $turnoTag = isNocheFindeNow($dow,$hour) ? 'noche-finde' : 'noche';
} else {
    $turnoTag = isTwoHourWindowNow($dow,$hour) ? 'turno-2h' : 'turno-3h';
}
$blockHoursBase = ($turnoTag==='turno-2h') ? 2 : (($turnoTag==='turno-3h') ? 3 : 11);
$blockHours = $blockHoursBase * $cantidadTurnos;
$startUTC = nowUTCStrFromArg();
$fecha = argDateToday();

/*=================== Buscar habitación disponible ===================*/
$whereTipo = '';
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
  echo json_encode(['success'=>false,'error'=>'No hay habitaciones disponibles']);
  exit;
}

/*=================== Marcar como reservada ===================*/
$st = $conn->prepare("UPDATE habitaciones SET estado='reservada', tipo_turno=?, hora_inicio=? WHERE id=?");
$st->bind_param('ssi', $turnoTag, $startUTC, $habitacionId);
$st->execute();
$st->close();

/*=================== Insertar registro ===================*/
$codigo = strtoupper(substr(md5(uniqid('', true)), 0, 4));
$tipo = tipoDeHabitacion($habitacionId, $SUPER_VIP, $VIP_LIST);
$estado = 'reservada';

$ins = $conn->prepare("INSERT INTO historial_habitaciones (habitacion,codigo,tipo,estado,turno,hora_inicio,fecha_registro,bloques) VALUES (?,?,?,?,?,?,?,?)");
$ins->bind_param('issssssi', $habitacionId, $codigo, $tipo, $estado, $turnoTag, $startUTC, $fecha, $cantidadTurnos);
$ins->execute();
$ins->close();

/*=================== Respuesta ===================*/
echo json_encode([
  'success' => true,
  'habitacion' => $habitacionId,
  'codigo' => $codigo,
  'duracion_horas' => $blockHours
]);
