<?php
// panel-telo.php — Panel + Registros + Precios + mejoras de layout y alertas
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=utf-8');
// Mantener la sesión abierta para las zonas protegidas (editar valores/reportes)
// durante unos minutos aunque se cambie de pestaña o se guarde el formulario.
session_set_cookie_params([
  'lifetime' => 600, // 10 minutos
  'path'     => '/',
  'httponly' => true,
]);
session_start();
/*========================= DB =========================*/
$servername = "127.0.0.1";
$username   = "u460517132_F5bOi";
$password   = "mDjVQbpI5A";
$dbname     = "u460517132_GxbHQ";

$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset('utf8mb4');
if ($conn->connect_error) { http_response_code(500); die("DB connection error: " . $conn->connect_error); }
/* ===== Clave admin para borrar movimientos ===== */
$CLAVE_ADMIN = "Mora2025";

/* ================= AJAX Inventario ================= */
if ($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['ajax_inv'])){
 $res=$conn->query("SELECT id,nombre,precio,cantidad,total_turno
                    FROM inventario_productos
                    WHERE activo = 1 AND cantidad > 0
                    ORDER BY nombre ASC");
$items=[];
while($r=$res->fetch_assoc()){
  // Si el total_turno está vacío, usar el stock actual como base
  $total = ($r['total_turno'] > 0) ? $r['total_turno'] : $r['cantidad'];
  $items[]=[
    'id'=>$r['id'],
    'nombre'=>$r['nombre'],
    'precio'=>$r['precio'],
    'cantidad'=>$r['cantidad'],
    'total'=>$total
  ];
}
  echo json_encode(['ok'=>1,'items'=>$items]);
  exit;
}

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['accion']) && $_POST['accion']==='vender_producto'){
  $id=intval($_POST['id']);

  // Descontar stock actual (solo si hay stock)
  $conn->query("UPDATE inventario_productos
                SET
                  cantidad = GREATEST(cantidad-1,0),
                  total_turno = GREATEST(total_turno-1,0)
                WHERE id = $id AND cantidad > 0");

  if ($conn->affected_rows === 0) {
    echo json_encode(['ok' => 0, 'msg' => 'Sin stock disponible']);
    exit;
  }


  // Guardar venta
  $p=$conn->query("SELECT nombre,precio FROM inventario_productos WHERE id=$id")->fetch_assoc();
  $cur=$conn->query("SELECT turno FROM turno_actual WHERE id=1")->fetch_assoc();
  $turno=$cur['turno']??'manana';
  $st=$conn->prepare("INSERT INTO ventas_turno(producto_id,nombre,precio,hora,turno) VALUES(?,?,?,?,?)");
  $ahora=nowUTCStrFromArg();
  $st->bind_param('issss',$id,$p['nombre'],$p['precio'],$ahora,$turno);
  $st->execute();$st->close();

  echo json_encode(['ok'=>1]);
  exit;
}

/* ================= AJAX alertas de minibar ================= */
if ($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['ajax_minibar_alerts'])) {
  $res = $conn->query("SELECT id, habitacion, items, total, paid_at FROM minibar_pedidos WHERE estado='pagado' AND avisado=0 ORDER BY COALESCE(paid_at, created_at) ASC");
  $pedidos = [];
  while($r = $res->fetch_assoc()){
    $items = json_decode($r['items'] ?? '[]', true);
    $pedidos[] = [
      'id' => (int)$r['id'],
      'habitacion' => (int)$r['habitacion'],
      'total' => (int)$r['total'],
      'items' => is_array($items) ? $items : []
    ];
  }
  echo json_encode(['ok'=>1,'pedidos'=>$pedidos]);
  exit;
}
/* ================= AJAX alertas de pagos online ================= */
if ($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['ajax_pagos_online'])) {
  $res = $conn->query("SELECT id, habitacion, turno, bloques, monto, created_at FROM pagos_online_habitaciones WHERE avisado=0 ORDER BY created_at ASC");
  $rows = [];
  while($r = $res->fetch_assoc()){
    $rows[] = [
      'id' => (int)$r['id'],
      'habitacion' => (int)$r['habitacion'],
      'turno' => $r['turno'] ?? '',
      'bloques' => (int)($r['bloques'] ?? 1),
      'monto' => (int)($r['monto'] ?? 0)
    ];
  }
  echo json_encode(['ok'=>1,'pagos'=>$rows]);
  exit;
}


/*===================== Tablas mínimas ==================*/
$conn->query("CREATE TABLE IF NOT EXISTS habitaciones (
  id INT PRIMARY KEY,
  estado VARCHAR(20) DEFAULT 'libre',   -- libre | ocupada | limpieza | reservada
  tipo_turno VARCHAR(20) NULL,
  hora_inicio DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

for($i=1;$i<=40;$i++){
  $conn->query("INSERT IGNORE INTO habitaciones (id,estado) VALUES ($i,'libre')");
}

$conn->query("CREATE TABLE IF NOT EXISTS historial_habitaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  habitacion INT NOT NULL,
  codigo VARCHAR(10) NULL,
  tipo VARCHAR(20),            -- Super VIP / VIP / Común
  estado VARCHAR(20),          -- 'ocupada' durante la ocupación; se cierra con hora_fin
  turno VARCHAR(20),           -- turno-2h | turno-3h | noche | noche-finde
  hora_inicio DATETIME,        -- guardado en UTC
  hora_fin DATETIME NULL,      -- guardado en UTC
  duracion_minutos INT NULL,   -- calculado al cerrar
  fecha_registro DATE,         -- día ARG del inicio
  precio_aplicado INT NULL,    -- ENTERO redondeado, snapshot del precio al crear
  bloques INT NOT NULL DEFAULT 1, -- cantidad de turnos acumulados
  es_extra TINYINT(1) NOT NULL DEFAULT 0, -- 0=normal, 1=extra
  INDEX(habitacion), INDEX(fecha_registro), INDEX(turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

/* Migraciones suaves (por si faltan columnas) */
@$conn->query("ALTER TABLE historial_habitaciones ADD COLUMN IF NOT EXISTS precio_aplicado INT NULL");
@$conn->query("ALTER TABLE historial_habitaciones ADD COLUMN IF NOT EXISTS es_extra TINYINT(1) NOT NULL DEFAULT 0");
@$conn->query("ALTER TABLE historial_habitaciones ADD COLUMN IF NOT EXISTS bloques INT NOT NULL DEFAULT 1");
/* ====== precios_habitaciones (incluye noche-finde) ====== */
$conn->query("
CREATE TABLE IF NOT EXISTS precios_habitaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo VARCHAR(20) NOT NULL,        -- Común | VIP | Super VIP
  turno VARCHAR(20) NOT NULL,       -- turno-2h | turno-3h | noche | noche-finde
  precio DECIMAL(10,2) NOT NULL DEFAULT 0,
  UNIQUE KEY unique_tipo_turno (tipo, turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$conn->query("
INSERT IGNORE INTO precios_habitaciones (tipo,turno,precio) VALUES
('Común','turno-2h',0),('Común','turno-3h',0),('Común','noche',0),('Común','noche-finde',0),
('VIP','turno-2h',0),('VIP','turno-3h',0),('VIP','noche',0),('VIP','noche-finde',0),
('Super VIP','turno-2h',0),('Super VIP','turno-3h',0),('Super VIP','noche',0),('Super VIP','noche-finde',0);
");

/* ====== Turnos de caja (persistente, compartido entre dispositivos) ====== */
$conn->query("
CREATE TABLE IF NOT EXISTS turno_actual (
  id TINYINT PRIMARY KEY,
  turno ENUM('manana','tarde','noche') NOT NULL,
  inicio DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$conn->query("
CREATE TABLE IF NOT EXISTS historial_turnos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  turno ENUM('manana','tarde','noche') NOT NULL,
  inicio DATETIME NOT NULL,
  fin DATETIME DEFAULT NULL,
  total INT NOT NULL DEFAULT 0,
  ocupaciones INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
/* ====== Pedidos de minibar ====== */
$conn->query("
CREATE TABLE IF NOT EXISTS minibar_pedidos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  habitacion INT NOT NULL,
  items JSON NOT NULL,
  total INT NOT NULL DEFAULT 0,
  estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
  preference_id VARCHAR(80) NULL,
  payment_id VARCHAR(80) NULL,
  avisado TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  paid_at DATETIME NULL,
  INDEX (estado),
  INDEX (habitacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ====== Pagos online de habitaciones ====== */
$conn->query("
CREATE TABLE IF NOT EXISTS pagos_online_habitaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  habitacion INT NOT NULL,
  turno VARCHAR(30) NOT NULL,
  bloques INT NOT NULL DEFAULT 1,
  monto INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  avisado TINYINT(1) NOT NULL DEFAULT 0,
  INDEX (habitacion),
  INDEX (avisado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Semilla: si no hay turno_actual, arrancamos con 'manana' ahora */
$__exists = $conn->query("SELECT COUNT(*) c FROM turno_actual")->fetch_assoc()['c'] ?? 0;
if (!$__exists) {
  $nowUTC = nowUTCStrFromArg();
  $conn->query("INSERT INTO turno_actual (id,turno,inicio) VALUES (1,'manana','$nowUTC')");
}

/* Limpieza de históricos viejos (opcional) */
$conn->query("DELETE FROM historial_habitaciones WHERE fecha_registro < (CURDATE() - INTERVAL 30 DAY)");

/*==================== Config y utilidades ===============*/
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
function toArgDT($utc){ if(!$utc) return null; $dt=new DateTime($utc,new DateTimeZone('UTC')); $dt->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires')); return $dt; }
function argDateRangeToUtcBounds(string $date){
  $tzArg = new DateTimeZone('America/Argentina/Buenos_Aires');
  $tzUtc = new DateTimeZone('UTC');

  $start = new DateTime($date.' 00:00:00', $tzArg);
  $start->setTimezone($tzUtc);

  $end = new DateTime($date.' 23:59:59', $tzArg);
  $end->setTimezone($tzUtc);

  return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}
function toArgTs($utc){
  if(!$utc) return null;
  $dtUtc = new DateTime($utc, new DateTimeZone('UTC'));
  $dtUtc->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
  return $dtUtc->getTimestamp();
}
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


// Formatos hora/día AR (sin segundos)
function fmtHoraArg($utc){ $dt=toArgDT($utc); return $dt? $dt->format('H:i') : ''; }
function partDia($utc){ $dt=toArgDT($utc); return $dt? $dt->format('d') : ''; }
function partMes($utc){ $dt=toArgDT($utc); return $dt? $dt->format('m') : ''; }
function partAnio($utc){ $dt=toArgDT($utc); return $dt? $dt->format('Y') : ''; }
function fmtFechaArg($utc){
  $dt = toArgDT($utc);
  return $dt ? $dt->format('d/m/Y') : '';
}


// Turno Noche: 21:00 → 10:00 todos los días
function nightEndTsFromStartArg($startTs){
  $dt = new DateTime('@'.$startTs); $dt->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
  $hour=(int)$dt->format('G');
  if($hour>=21){ $dt->modify('+1 day'); }
  $dt->setTime(10,0,0);
  return $dt->getTimestamp();
}
function isNightAllowedNow(){ list($dow,$hour)=argNowInfo(); return ($hour>=21 || $hour<10); }

// Bloques de turno: Vie 8:00 → Dom 23:59 = 2h, resto = 3h
function turnoBlockHoursForToday(){ list($dow,$hour)=argNowInfo(); return isTwoHourWindowNow($dow,$hour) ? 2 : 3; }

// Precio vigente (redondeado, entero)
function precioVigenteInt($conn,$tipo,$turno){
  $p=0.0;
  $st=$conn->prepare("SELECT precio FROM precios_habitaciones WHERE tipo=? AND turno=? LIMIT 1");
  $st->bind_param('ss',$tipo,$turno); $st->execute(); $res=$st->get_result(); if($row=$res->fetch_assoc()) $p=floatval($row['precio']);
  $st->close();
  return (int)round($p,0);
}
/*==================== AJAX de tarjetas =================*/
if(isset($_GET['ajax']) && $_GET['ajax']=='1'){

  // No usamos $states global, todo viene fresco de la BD
  renderFilaAjax(range(40,21), $conn, $SUPER_VIP, $VIP_LIST); // fila superior
  echo '<div class="rows-spacer"></div>';
  renderFilaAjax(range(1,20), $conn, $SUPER_VIP, $VIP_LIST);  // fila inferior

  exit;
}

function sumRemainingForRoom($conn,$roomId){
  $now = nowArgDT()->getTimestamp();
  $sum = 0;

  $sql="SELECT turno, hora_inicio, estado, bloques
      FROM historial_habitaciones
      WHERE habitacion=?
      AND hora_fin IS NULL
      AND (estado='ocupada' OR estado='reservada')
      ORDER BY id DESC
      LIMIT 1";

  $st=$conn->prepare($sql);
  $st->bind_param('i',$roomId);
  $st->execute();
  $res=$st->get_result();

  while($r=$res->fetch_assoc()){

    // VOLVER A UTC → ARG (CORRECTO PARA TODOS LOS TURNOS)
    $dtUtc = new DateTime($r['hora_inicio'], new DateTimeZone('UTC'));
    $dtUtc->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
    $startTs = $dtUtc->getTimestamp();
    $bloques = max(1, (int)($r['bloques'] ?? 1));

    if ($r['turno'] === 'turno-2h') {
      $end = $startTs + (2 * 3600 * $bloques);

    } elseif ($r['turno'] === 'turno-3h') {
      $end = $startTs + (3 * 3600 * $bloques);

    } elseif (
      $r['turno'] === 'noche' ||
      $r['turno'] === 'noche-finde' ||
      $r['turno'] === 'noche-find'
    ) {
      $end = nightEndTsFromStartArg($startTs);

    } else {
      continue;
    }

    $rem = $end - $now;
    $sum += $rem;
  }

  $st->close();
  return $sum;
}
function formatTimerLabel($rest,$estado){
  if($estado!=='ocupada' && $estado!=='reservada') return '';
  $sign = $rest < 0 ? '-' : '';
  $abs  = abs($rest);
  $h    = floor($abs/3600);
  $m    = floor(($abs%3600)/60);
  return $sign . $h . ':' . str_pad($m, 2, '0', STR_PAD_LEFT);
}

// Render de una fila de habitaciones PARA EL AJAX (no usa $states inicial)
function renderFilaAjax($ids, $conn, $SUPER_VIP, $VIP_LIST){
  echo '<div class="rooms-row">';
  foreach($ids as $id){
    // Estado en caliente desde la tabla habitaciones
    $st = $conn->prepare("SELECT estado FROM habitaciones WHERE id=? LIMIT 1");
    $st->bind_param('i', $id);
    $st->execute();
    $res = $st->get_result();
    $row = $res->fetch_assoc();
    $st->close();

    $estado = $row['estado'] ?? 'libre';
    $tipo   = tipoDeHabitacion($id, $SUPER_VIP, $VIP_LIST);
    $rest   = sumRemainingForRoom($conn, $id);
    $isSuper = in_array($id, $SUPER_VIP);

    // Texto de estado
    if ($estado === 'libre')       $estadoTxt = 'Disponible';
    elseif ($estado === 'limpieza') $estadoTxt = 'Limpieza';
    elseif ($estado === 'reservada') $estadoTxt = 'Reservada';
    else                            $estadoTxt = 'Ocupada';

     // Texto del timer (incluye atrasos en negativo)
    $timer = formatTimerLabel($rest, $estado);

    echo '<div class="room-wrap">';
    echo '  <div class="room-card ' . htmlspecialchars($estado) . ' ' . ($isSuper ? 'super' : '') . '"'
        . ' id="hab-' . $id . '"'
        . ' data-id="' . $id . '"'
        . ' data-restante="' . $rest . '"'
        . ' data-tipo="' . htmlspecialchars($tipo) . '">';
    echo '    <div class="state-line">' . htmlspecialchars($estadoTxt) . '</div>';
    echo '    <div class="timer" id="timer-' . $id . '">' . htmlspecialchars($timer) . '</div>';
    echo '    <div class="room-num">' . $id . '</div>';
    echo '  </div>';
    echo '  <div class="room-kind">' . htmlspecialchars($tipo) . '</div>';
    echo '</div>';
  }
  echo '</div>';
}

/* ===== Helpers de Turno de Caja ===== */
function turnoNombre($k){ return $k==='manana'?'Mañana':($k==='tarde'?'Tarde':'Noche'); }

function turnoActual($conn){
  $r = $conn->query("SELECT turno, inicio FROM turno_actual WHERE id=1 LIMIT 1")->fetch_assoc();
  if(!$r){
    $now = nowUTCStrFromArg();
    $conn->query("INSERT INTO turno_actual (id, turno, inicio) VALUES (1,'manana','".$conn->real_escape_string($now)."') ON DUPLICATE KEY UPDATE turno=VALUES(turno), inicio=VALUES(inicio)");
    return ['turno'=>'manana','inicio'=>$now];
  }
  return $r;
}

/* Suma de caja de un rango [inicioUTC, finUTC) contando $ al INICIO de la ocupación */
function cajaTotalDesdeHasta($conn,$inicioUTC,$finUTC=null){
  // Ingresos por habitaciones
  $sqlHab = "SELECT SUM(precio_aplicado) total, COUNT(*) cant
             FROM historial_habitaciones
             WHERE hora_inicio >= ? AND codigo IS NULL".($finUTC?" AND hora_inicio < ?":"");
  $st = $conn->prepare($sqlHab);
  if($finUTC){ $st->bind_param('ss',$inicioUTC,$finUTC); } else { $st->bind_param('s',$inicioUTC); }
  $st->execute(); $resHab = $st->get_result()->fetch_assoc(); $st->close();

  // Ventas de minibar (efectivo)
  $sqlVen = "SELECT SUM(precio) total FROM ventas_turno WHERE hora >= ?".($finUTC?" AND hora < ?":"");
  $st = $conn->prepare($sqlVen);
  if($finUTC){ $st->bind_param('ss',$inicioUTC,$finUTC); } else { $st->bind_param('s',$inicioUTC); }
    $st->execute(); $resVen = $st->get_result()->fetch_assoc(); $st->close();

  // Ajustes por pagos online (se descuentan de la caja en efectivo)
  $sqlPg = "SELECT SUM(monto) total FROM pagos_online_habitaciones WHERE created_at >= ?".($finUTC?" AND created_at < ?":"");
  $st = $conn->prepare($sqlPg);
  if($finUTC){ $st->bind_param('ss',$inicioUTC,$finUTC); } else { $st->bind_param('s',$inicioUTC); }
  $st->execute(); $resPg = $st->get_result()->fetch_assoc(); $st->close();

  $totalHab = (int)($resHab['total'] ?? 0);
  $cantHab  = (int)($resHab['cant'] ?? 0);
  $totalVen = (int)($resVen['total'] ?? 0);
  $totalPg  = (int)($resPg['total'] ?? 0);

  $totalCaja = $totalHab + $totalVen - $totalPg; // online resta efectivo

  return [ $totalCaja, $cantHab ];
}

function cajaDetalleDesdeHasta($conn,$inicioUTC,$finUTC=null,$limit=150){
    $col = 'utf8mb4_unicode_ci';
  $sql = "
    (SELECT
        id,
        habitacion,
        CONVERT(turno USING utf8mb4) COLLATE $col AS turno,
        hora_inicio,
        precio_aplicado AS monto,
        'hab' COLLATE $col AS tipo,
        NULL AS nombre
     FROM historial_habitaciones
     WHERE hora_inicio >= ? AND codigo IS NULL".($finUTC?" AND hora_inicio < ?":"").")
    UNION ALL
    (SELECT
        id,
        NULL AS habitacion,
        CONVERT(turno USING utf8mb4) COLLATE $col AS turno,
        hora AS hora_inicio,
        precio AS monto,
        'venta' COLLATE $col AS tipo,
        CONVERT(nombre USING utf8mb4) COLLATE $col AS nombre
     FROM ventas_turno
     WHERE hora >= ?".($finUTC?" AND hora < ?":"").")
     UNION ALL
    (SELECT
        id,
        habitacion,
        CONVERT(turno USING utf8mb4) COLLATE $col AS turno,
        created_at AS hora_inicio,
        (monto * -1) AS monto,
        'ajuste' COLLATE $col AS tipo,
        'Pago online' COLLATE $col AS nombre
     FROM pagos_online_habitaciones
     WHERE created_at >= ?".($finUTC?" AND created_at < ?":"").")
    ORDER BY hora_inicio ASC
    LIMIT $limit
  ";

  $st = $conn->prepare($sql);
  if($finUTC){
    $st->bind_param('ssssss',$inicioUTC,$finUTC,$inicioUTC,$finUTC,$inicioUTC,$finUTC);
  } else {
    $st->bind_param('sss',$inicioUTC,$inicioUTC,$inicioUTC);
  }
  $st->execute();
  $res = $st->get_result();
  $rows=[];
  while($r=$res->fetch_assoc()){
       $label = '';
    if($r['tipo']==='hab')      $label = (int)$r['habitacion'];
    elseif($r['tipo']==='venta') $label = '🧃 '.$r['nombre'];
    else                         $label = '💳 Hab. '.($r['habitacion'] ?? '-');
    
    $rows[] = [
      'id'    => (int)$r['id'],               // ID real del movimiento
      'tipo'  => $r['tipo'],                  // 'hab', 'venta' o 'ajuste'
      'hab'   => $label,
      'turno' => $r['turno'],
      'inicio'=> fmtHoraArg($r['hora_inicio']),
      'monto' => (int)($r['monto']??0),
    ];
  }
  $st->close();
  return $rows;
}

/* =================== AJAX de Turno de Caja =================== */

if ($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['ajax_turno']) && $_GET['ajax_turno']=='1'){
  $cur = turnoActual($conn);

  $inicioArg = fmtHoraArg($cur['inicio']);


list($total,) = cajaTotalDesdeHasta($conn, $cur['inicio']);
  $detalle = cajaDetalleDesdeHasta($conn, $cur['inicio'], null, 300);

  echo json_encode([
    'ok' => 1,
    'turno' => $cur['turno'],
    'turno_txt' => turnoNombre($cur['turno']),
    'inicio_arg' => $inicioArg,
    'total' => $total,
    'detalle' => $detalle
  ]);
  exit;
}


/*======================= AJAX acciones ===================*/
if($_SERVER['REQUEST_METHOD']==='POST'){ ini_set('display_errors', 0); } // evita ensuciar JSON

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['accion'])){
  $accion = $_POST['accion'];
  
  /* ===== BORRAR MOVIMIENTO (habitaciones o ventas) ===== */
  if ($accion === 'borrar_mov') {
    $clave = $_POST['clave'] ?? '';
    $id    = intval($_POST['id'] ?? 0);
    $tipo  = $_POST['tipo'] ?? '';

    if ($clave !== $CLAVE_ADMIN) {
      echo json_encode(['ok'=>0, 'error'=>'Clave incorrecta']);
      exit;
    }

    if ($id <= 0) {
      echo json_encode(['ok'=>0, 'error'=>'ID inválido']);
      exit;
    }

    if ($tipo === 'hab') {
      $stmt = $conn->prepare("DELETE FROM historial_habitaciones WHERE id=?");
    } elseif ($tipo === 'venta') {
      $stmt = $conn->prepare("DELETE FROM ventas_turno WHERE id=?");
    } else {
      echo json_encode(['ok'=>0, 'error'=>'Tipo inválido']);
      exit;
    }

    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['ok'=>1]);
    exit;
  }
if ($accion === 'ack_minibar') {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
      echo json_encode(['ok'=>0,'error'=>'ID inválido']);
      exit;
    }
    $stmt = $conn->prepare("UPDATE minibar_pedidos SET avisado=1, estado='entregado' WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['ok'=>1]);
    exit;
  }
  if ($accion === 'ack_pago_online') {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
      echo json_encode(['ok'=>0,'error'=>'ID inválido']);
      exit;
    }
    $conn->begin_transaction();

    // 1) Marcar alerta como atendida y obtener la habitación
    $sel = $conn->prepare("SELECT habitacion FROM pagos_online_habitaciones WHERE id=? LIMIT 1 FOR UPDATE");
    $sel->bind_param('i', $id);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    $sel->close();

    $updAviso = $conn->prepare("UPDATE pagos_online_habitaciones SET avisado=1 WHERE id=?");
    $updAviso->bind_param('i', $id);
    $updAviso->execute();
    $updAviso->close();

    
    $habitacion = intval($row['habitacion'] ?? 0);
    $pagoTurno = strval($row['turno'] ?? '');
    $pagoBloques = max(1, intval($row['bloques'] ?? 1));
    $pagoFecha = $row['created_at'] ?? null;

    // 2) Ajustar el registro manual según la hora del pago (ventana 20 minutos)
    if ($habitacion > 0 && $pagoTurno !== 'envio-dinero' && $pagoFecha) {
      $pagoTime = new DateTime($pagoFecha, new DateTimeZone('UTC'));
      $window = clone $pagoTime;
      $window->modify('-20 minutes');
      $limiteStr = $window->format('Y-m-d H:i:s');

      $selHist = $conn->prepare("SELECT id, bloques, precio_aplicado FROM historial_habitaciones WHERE habitacion=? AND codigo IS NULL AND hora_fin IS NULL AND hora_inicio >= ? ORDER BY id DESC LIMIT 1");
      $selHist->bind_param('is', $habitacion, $limiteStr);
      $selHist->execute();
      $histRow = $selHist->get_result()->fetch_assoc();
      $selHist->close();

      if ($histRow && ($histRow['id'] ?? null)) {
        $hid = intval($histRow['id']);
        $bloquesActuales = max(1, intval($histRow['bloques'] ?? 1));
        $precioActual = max(0, intval($histRow['precio_aplicado'] ?? 0));
        $bloquesADescontar = min($bloquesActuales, $pagoBloques);
        $precioPorBloque = $bloquesActuales > 0 ? (int) round($precioActual / $bloquesActuales) : 0;
        $nuevoPrecio = max(0, $precioActual - ($precioPorBloque * $bloquesADescontar));
        $bloquesRestantes = max(0, $bloquesActuales - $bloquesADescontar);

        $updHist = $conn->prepare("UPDATE historial_habitaciones SET codigo='pago_online', precio_aplicado=?, bloques=? WHERE id=?");
        $updHist->bind_param('iii', $nuevoPrecio, $bloquesRestantes, $hid);
        $updHist->execute();
        $updHist->close();
      }
    }

    $conn->commit();
    echo json_encode(['ok'=>1]);
    exit;
  }
  
  if ($accion === 'set_estado') {
    $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
    $estado = $_POST['estado'] ?? 'libre';

    foreach ($ids as $id) {
      // cerrar todos los bloques abiertos SOLO si NO pasa a ocupada
      if ($estado !== 'ocupada') {
        $endUTC = nowUTCStrFromArg();
        $q = $conn->prepare("SELECT id, hora_inicio FROM historial_habitaciones WHERE habitacion=? AND hora_fin IS NULL");
        $q->bind_param('i', $id); $q->execute(); $rs = $q->get_result();
        while ($row = $rs->fetch_assoc()) {
          $mins = max(0, intval(round((toArgTs($endUTC) - toArgTs($row['hora_inicio'])) / 60)));
          $u = $conn->prepare("UPDATE historial_habitaciones SET hora_fin=?, duracion_minutos=? WHERE id=?");
          $u->bind_param('sii', $endUTC, $mins, $row['id']); $u->execute(); $u->close();
        }
        $q->close();
      }

      if ($estado === 'ocupada') {
        $st=$conn->prepare("UPDATE habitaciones SET estado=? WHERE id=?");
        $st->bind_param('si',$estado,$id); $st->execute(); $st->close();

        $u=$conn->prepare("UPDATE historial_habitaciones SET estado='ocupada' WHERE habitacion=? AND hora_fin IS NULL");
        $u->bind_param('i',$id); $u->execute(); $u->close();
      } else {
        $st=$conn->prepare("UPDATE habitaciones SET estado=?, tipo_turno=NULL, hora_inicio=NULL WHERE id=?");
        $st->bind_param('si',$estado,$id); $st->execute(); $st->close();
      }
    }
    echo json_encode(['success'=>true]); exit;
  }
if ($accion === 'mover_reserva') {
    $desde = intval($_POST['desde'] ?? 0);
    $hasta = intval($_POST['hasta'] ?? 0);

    if ($desde <= 0 || $hasta <= 0 || $desde === $hasta) {
      echo json_encode(['success' => false, 'error' => 'Habitación inválida']);
      exit;
    }

    $tipoDesde = tipoDeHabitacion($desde, $SUPER_VIP, $VIP_LIST);
    $tipoHasta = tipoDeHabitacion($hasta, $SUPER_VIP, $VIP_LIST);
    if ($tipoDesde !== $tipoHasta) {
      echo json_encode(['success' => false, 'error' => 'Solo se puede mover dentro de la misma categoría']);
      exit;
    }

    $q = $conn->prepare("SELECT estado, tipo_turno, hora_inicio FROM habitaciones WHERE id=?");
    $q->bind_param('i', $desde);
    $q->execute();
    $orig = $q->get_result()->fetch_assoc();
    $q->close();

    if (($orig['estado'] ?? '') !== 'reservada') {
      echo json_encode(['success' => false, 'error' => 'La habitación original no está reservada']);
      exit;
    }

    $q = $conn->prepare("SELECT estado FROM habitaciones WHERE id=?");
    $q->bind_param('i', $hasta);
    $q->execute();
    $dest = $q->get_result()->fetch_assoc();
    $q->close();

    if (($dest['estado'] ?? '') !== 'libre') {
      echo json_encode(['success' => false, 'error' => 'La habitación destino no está disponible']);
      exit;
    }

    $turno = $orig['tipo_turno'] ?? null;
    $horaInicio = $orig['hora_inicio'] ?? null;

    $upDest = $conn->prepare("UPDATE habitaciones SET estado='reservada', tipo_turno=?, hora_inicio=? WHERE id=?");
    $upDest->bind_param('ssi', $turno, $horaInicio, $hasta);
    $upDest->execute();
    $upDest->close();

    $upOrig = $conn->prepare("UPDATE habitaciones SET estado='libre', tipo_turno=NULL, hora_inicio=NULL WHERE id=?");
    $upOrig->bind_param('i', $desde);
    $upOrig->execute();
    $upOrig->close();

    $updHist = $conn->prepare("UPDATE historial_habitaciones SET habitacion=?, tipo=? WHERE habitacion=? AND hora_fin IS NULL AND estado='reservada'");
    $updHist->bind_param('isi', $hasta, $tipoDesde, $desde);
    $updHist->execute();
    $updHist->close();

    echo json_encode(['success' => true]);
    exit;
  }
 if($accion==='ocupar_turno'){ // agregar bloque (acumulable)
    $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
    $blockHours = turnoBlockHoursForToday();
    $turnoTag = $blockHours===2 ? 'turno-2h' : 'turno-3h';
    $startUTC = nowUTCStrFromArg();
    $fecha    = argDateToday();

    foreach($ids as $id){
        // si tiene noche abierta, no sumar
        $chk = $conn->prepare("SELECT COUNT(*) c FROM historial_habitaciones WHERE habitacion=? AND turno IN ('noche','noche-finde') AND hora_fin IS NULL");
        $chk->bind_param('i',$id); $chk->execute();
        $res=$chk->get_result()->fetch_assoc();
        $chk->close();
        if(($res['c']??0)>0){ continue; }

        $tipoHab = tipoDeHabitacion($id,$SUPER_VIP,$VIP_LIST);
        $estado='ocupada';
        $turnoHabitacion = $turnoTag;
        $inicioHabitacion = $startUTC;

        // ¿Hay una ocupación abierta? entonces sumamos bloques en el mismo registro
        $open = $conn->prepare("SELECT id, turno, hora_inicio, bloques FROM historial_habitaciones WHERE habitacion=? AND hora_fin IS NULL ORDER BY id DESC LIMIT 1");
        $open->bind_param('i',$id);
        $open->execute();
        $curOpen = $open->get_result()->fetch_assoc();
        $open->close();

        if($curOpen){
            $turnoHabitacion = $curOpen['turno'] ?? $turnoTag;
            $inicioHabitacion = $curOpen['hora_inicio'] ?? $startUTC;
            $bloquesActuales = max(1,(int)($curOpen['bloques'] ?? 1)) + 1;
            $precioExtra = precioVigenteInt($conn, $tipoHab, $turnoHabitacion);

            $upd = $conn->prepare("UPDATE historial_habitaciones SET bloques=?, precio_aplicado = COALESCE(precio_aplicado,0)+? WHERE id=?");
            $upd->bind_param('iii',$bloquesActuales,$precioExtra,$curOpen['id']);
            $upd->execute();
            $upd->close();
        } else {
            // precio vigente congelado
            $precio = precioVigenteInt($conn, $tipoHab, $turnoTag);

            $ins=$conn->prepare("INSERT INTO historial_habitaciones (habitacion,tipo,estado,turno,hora_inicio,fecha_registro,precio_aplicado,es_extra,bloques)
                             VALUES (?,?,?,?,?,?,?,0,1)");
            $ins->bind_param('isssssi',$id,$tipoHab,$estado,$turnoTag,$startUTC,$fecha,$precio);
            $ins->execute();
            $ins->close();
        }

        // poner ocupada con turno real, conservando inicio original si ya existía
        $st=$conn->prepare("UPDATE habitaciones SET estado='ocupada', tipo_turno=?, hora_inicio=? WHERE id=?");
        $st->bind_param('ssi',$turnoHabitacion,$inicioHabitacion,$id);
        $st->execute();
        $st->close();

        
    }

    echo json_encode(['success'=>true,'hours'=>$blockHours]);
    exit;
}



  if($accion==='ocupar_noche'){ // única por ocupación (no se acumula con noche)
    if(!isNightAllowedNow()){ echo json_encode(['success'=>false,'error'=>'El turno noche solo puede reservarse entre las 21:00 y las 10:00.']); exit; }
    $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
    $startUTC = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $fecha    = argDateToday();
    $errors = [];
    foreach($ids as $id){
      // si ya hay noche abierta, error
      $chk = $conn->prepare("SELECT COUNT(*) c FROM historial_habitaciones WHERE habitacion=? AND turno IN ('noche','noche-finde') AND hora_fin IS NULL");
      $chk->bind_param('i',$id); $chk->execute(); $res=$chk->get_result()->fetch_assoc(); $chk->close();
      if(($res['c']??0)>0){ $errors[]=$id; continue; }

      // tipo y turno (noche finde: franjas de viernes y sábado)
      list($dow,$hour)=argNowInfo();
      $turno = isNocheFindeNow($dow,$hour) ? 'noche-finde' : 'noche';

      // precio vigente congelado
      $tipoHab = tipoDeHabitacion($id,$SUPER_VIP,$VIP_LIST);
      $precio  = precioVigenteInt($conn,$tipoHab,$turno);

      // marcar ocupada + HORA INICIO (FIX)
     $horaInicioArg = nowArgDT();
$horaInicioArg->setTimezone(new DateTimeZone('UTC'));
$horaInicioUTC = $horaInicioArg->format('Y-m-d H:i:s');

$st=$conn->prepare("UPDATE habitaciones SET estado='ocupada', tipo_turno=?, hora_inicio=? WHERE id=?");
$st->bind_param('ssi',$turno,$horaInicioUTC,$id);
$st->execute();
$st->close();


      // crear registro (normal, no extra)  **FIX de tipos en bind_param: 'isssssi'**
      $estado='ocupada';
        $ins=$conn->prepare("INSERT INTO historial_habitaciones (habitacion,tipo,estado,turno,hora_inicio,fecha_registro,precio_aplicado,es_extra,bloques)
                          VALUES (?,?,?,?,?,?,?,0,1)");
      $ins->bind_param('isssssi',$id,$tipoHab,$estado,$turno,$startUTC,$fecha,$precio);
      $ins->execute(); $ins->close();
    }
    if(!empty($errors)){
      echo json_encode(['success'=>false,'error'=>'Estas habitaciones ya tienen Noche activa: '.implode(', ',$errors)]); exit;
    }
    echo json_encode(['success'=>true]); exit;
  }

if($accion==='reactivar_extra'){
    $id = intval($_POST['id'] ?? 0);
    if($id<=0){ echo json_encode(['success'=>false,'error'=>'Habitación inválida']); exit; }

    

    $blockHours = turnoBlockHoursForToday();
    $turnoTag = $blockHours===2 ? 'turno-2h' : 'turno-3h';
    $startUTC = nowUTCStrFromArg();
    $fecha    = argDateToday();
    $tipoHab  = tipoDeHabitacion($id,$SUPER_VIP,$VIP_LIST);
    $precio   = precioVigenteInt($conn,$tipoHab,$turnoTag);

    // ¿Hay una ocupación abierta? si sí, sumamos bloques como extra
    $open = $conn->prepare("SELECT id, turno, hora_inicio, bloques FROM historial_habitaciones WHERE habitacion=? AND hora_fin IS NULL ORDER BY id DESC LIMIT 1");
    $open->bind_param('i',$id);
    $open->execute();
    $curOpen = $open->get_result()->fetch_assoc();
    $open->close();

    $turnoHabitacion = $turnoTag;
    $inicioHabitacion = $startUTC;

    if($curOpen){
        $turnoHabitacion = $curOpen['turno'] ?? $turnoTag;
        $inicioHabitacion = $curOpen['hora_inicio'] ?? $startUTC;
        $bloquesActuales = max(1,(int)($curOpen['bloques'] ?? 1)) + 1;
        $precioExtra = precioVigenteInt($conn, $tipoHab, $turnoHabitacion);

        $upd = $conn->prepare("UPDATE historial_habitaciones SET bloques=?, precio_aplicado = COALESCE(precio_aplicado,0)+?, es_extra=1 WHERE id=?");
        $upd->bind_param('iii',$bloquesActuales,$precioExtra,$curOpen['id']);
        $upd->execute();
        $upd->close();
    } else {
        $estado='ocupada';
        $ins=$conn->prepare("INSERT INTO historial_habitaciones (habitacion,tipo,estado,turno,hora_inicio,fecha_registro,precio_aplicado,es_extra,bloques)
                         VALUES (?,?,?,?,?,?,?,1,1)");
    $ins->bind_param('isssssi',$id,$tipoHab,$estado,$turnoTag,$startUTC,$fecha,$precio);
        $ins->execute();
        $ins->close();
    }

    // Asegurar que la habitación quede marcada como ocupada y con inicio correcto
    $st=$conn->prepare("UPDATE habitaciones SET estado='ocupada', tipo_turno=?, hora_inicio=? WHERE id=?");
    $st->bind_param('ssi',$turnoHabitacion,$inicioHabitacion,$id); $st->execute(); $st->close();

    echo json_encode(['success'=>true,'hours'=>$blockHours]);
    exit;
  }

  if($accion==='export_csv'){
    $desde = preg_replace('/[^0-9\-]/','', $_POST['desde'] ?? argDateToday());
    $hasta = preg_replace('/[^0-9\-]/','', $_POST['hasta'] ?? argDateToday());
    $tipo  = preg_replace('/[^a-zA-Z\s]/','', $_POST['tipo']  ?? 'todos');

    $sql = "SELECT habitacion, tipo, estado, turno, hora_inicio, hora_fin, duracion_minutos, fecha_registro, precio_aplicado, es_extra
            FROM historial_habitaciones
            WHERE fecha_registro BETWEEN ? AND ?";
    if($tipo!=='todos') $sql.=" AND tipo=?";
    $sql.=" ORDER BY COALESCE(hora_fin,hora_inicio) DESC";
    $stmt = $conn->prepare($sql);
    if($tipo!=='todos'){ $stmt->bind_param('sss',$desde,$hasta,$tipo); } else { $stmt->bind_param('ss',$desde,$hasta); }
    $stmt->execute(); $result = $stmt->get_result();

    $fname = "reporte-".$desde.($desde!==$hasta?"_a_".$hasta:"").($tipo!=='todos'?"-".str_replace(' ','_',$tipo):"").".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$fname.'"');
    $out = fopen('php://output','w');
    fputcsv($out, ['Habitación','Tipo','Turno (normal/extra)','Hora Inicio (ARG)','Día','Mes','Año','Hora Fin (ARG)','Duración (min)','Monto']);
    while($row=$result->fetch_assoc()){
      fputcsv($out, [
        $row['habitacion']??'',
        $row['tipo']??'',
        (($row['es_extra']??0)?'extra':'normal'),
        fmtHoraArg($row['hora_inicio']??''),
        partDia($row['hora_inicio']??''),
        partMes($row['hora_inicio']??''),
        partAnio($row['hora_inicio']??''),
        fmtHoraArg($row['hora_fin']??''),
        $row['duracion_minutos']??'',
        (int)($row['precio_aplicado']??0)
      ]);
    }
    fclose($out); exit;
  }

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['accion']) && $_POST['accion']==='iniciar_turno'){
  $nuevo = $_POST['nuevo'] ?? '';
  if(!in_array($nuevo,['manana','tarde','noche'],true)){
    echo json_encode(['ok'=>0,'error'=>'Turno inválido']); exit;
  }

  // Cerrar el turno actual (solo caja, NO toca habitaciones)
  $cur = turnoActual($conn);
  $ahoraUTC = nowUTCStrFromArg();

  // Resumen del turno que se cierra
  list($total,$cant) = cajaTotalDesdeHasta($conn,$cur['inicio'],$ahoraUTC);
  $detalle = cajaDetalleDesdeHasta($conn,$cur['inicio'],$ahoraUTC,150);

  // Guardar cierre en historial_turnos
  $st = $conn->prepare("INSERT INTO historial_turnos (turno,inicio,fin,total,ocupaciones) VALUES (?,?,?,?,?)");
  $st->bind_param('sssii',$cur['turno'],$cur['inicio'],$ahoraUTC,$total,$cant);
  $st->execute(); $st->close();

  // Abrir el nuevo turno
  $conn->query("UPDATE turno_actual SET turno='".$conn->real_escape_string($nuevo)."', inicio='".$ahoraUTC."' WHERE id=1");

  echo json_encode([
    'ok'=>1,
    'cerrado'=>[
      'turno'=>$cur['turno'],
      'turno_txt'=>turnoNombre($cur['turno']),
      'inicio_arg'=>fmtHoraArg($cur['inicio']),
      'fin_arg'=>fmtHoraArg($ahoraUTC),
      'total'=>$total,
      'cant'=>$cant,
      'detalle'=>$detalle
    ],
    'nuevo'=>[
      'turno'=>$nuevo,
      'turno_txt'=>turnoNombre($nuevo),
      'inicio_arg'=>fmtHoraArg($ahoraUTC)
    ]
  ]);
  $conn->query("UPDATE inventario_productos SET total_turno = cantidad");

  exit;
}

  echo json_encode(['success'=>false,'error'=>'Acción no reconocida']); exit;
}

/*======================== Vista ========================*/
$view = $_GET['view'] ?? 'panel';
if ($view !== 'reportes') {
    unset($_SESSION['reportes_ok']);
}
/*=================== Estados iniciales =================*/
$states = [];
$rs = $conn->query("SELECT id, estado, tipo_turno, hora_inicio FROM habitaciones ORDER BY id ASC");
while($r=$rs->fetch_assoc()){
  $id=(int)$r['id'];
  $states[$id]=$r;
}

/*========================= HTML ========================*/
function safe($v){ return htmlspecialchars($v ?? ''); }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panel Telo</title>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
/* FORZAR TEXTO NEGRO Y BOLD DENTRO DE TODAS LAS TARJETAS */
.room-card .state-line,
.room-card.ocupada .timer {
    color: #fff !important;
    font-weight: 700;
}
.room-card .room-num {
    color: #000 !important;
    font-weight: 900 !important;
}

/* Si querés ser más agresivo y asegurar TODO en negro */
.room-card * {
    color: #000 !important;
    font-weight: 900 !important;
}

:root{
  --bg:#F5F7FB; --text:#0B1220; --border:#E5E7EB; --shadow:0 10px 28px rgba(16,24,40,.06);
  --green:#00C851; --yellow:#FFBB33; --red:#FF0000; --blue:#2563EB; --chip:#EEF2FF;
}
*{box-sizing:border-box;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
body{margin:0;background:var(--bg);color:var(--text)}

/* Header */
header{position:sticky;top:0;z-index:5;background:#fff;border-bottom:1px solid var(--border);box-shadow:0 1px 4px rgba(0,0,0,.08)}
.header-inner{max-width:1440px;margin:0 auto;padding:8px 12px;display:flex;align-items:center;gap:8px}
.brand{display:flex;align-items:center;gap:10px;font-weight:800}
.nav{margin-left:auto;display:flex;align-items:center;gap:6px}
.nav button{background:#fff;border:1px solid var(--border);border-radius:6px;padding:4px 8px;font-size:12px;cursor:pointer}
.nav button.active{background:#0B5FFF;color:#fff;border-color:#0B5FFF}
.clock{font-weight:700;color:#0B5FFF;font-size:12px;min-width:80px;text-align:right}
/* 🔥 BOTÓN HAMBURGUESA */
#menu-toggle{
  display:none;
  margin-left:auto;
  background:none;
  border:none;
  font-size:24px;
  cursor:pointer;
  color:#0B1220;
}

/* 🔥 MENÚ MOBILE */
#mobile-menu{
  display:none;
  position:fixed;
  top:58px;
  left:0;
  right:0;
  background:#111827;
  padding:12px;
  z-index:10;
  border-bottom:1px solid #374151;
}

#mobile-menu button{
  width:100%;
  background:#1F2937;
  border:1px solid #374151;
  border-radius:8px;
  padding:12px;
  margin-bottom:8px;
  color:#fff;
  font-size:15px;
  cursor:pointer;
}

/* 🔥 MOBILE: esconder menú horizontal, mostrar hamburguesa */
@media(max-width:768px){
  .nav{
    display:none;
  }
  #menu-toggle{
    display:block;
  }
}

/* DESKTOP: ocultar menú móvil */
@media(min-width:769px){
  #mobile-menu{
    display:none !important;
  }
}

/* Contenedor global */
.container{
  width: 100%;
  max-width: 1700px;
  margin: 120px auto 8px auto;
  padding: 0 12px;
  display:flex;
  flex-direction:column;
  align-items:center;
}

/* ==== GRID ESCRITORIO: SIEMPRE 20 x FILA ==== */
.rooms-row{
  display:grid;
  grid-template-columns:repeat(20, minmax(40px, 1fr));
  gap:8px;
  width:100%;
}

/* Separador entre filas */
.rows-spacer{height:80px}
@media(max-width:900px){ .rows-spacer{display:none} }

.room-wrap{display:flex;flex-direction:column;align-items:stretch}

/* ====== TARJETAS ====== */
.room-card{
  position:relative;
  border:1px solid var(--border);
  border-radius:10px;
  padding:8px 6px;
  min-height:70px;
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;
  box-shadow:0 2px 6px rgba(16,24,40,.08);
  user-select:none; cursor:pointer; transition:transform .05s ease,border-color .2s ease;
  overflow:hidden;
}
.room-card.super{ min-height:90px; }

/* Línea de estado */
.state-line{
  font-weight:800;
  font-size:11px;
  text-transform:uppercase;
  letter-spacing:.03em;
  line-height:1;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  max-width:100%;
}

/* Timer */
.timer{font-weight:800;font-size:12px;line-height:1}

/* Número de habitación */
.room-num{font-weight:800;font-size:12px}

/* Tipo de habitación (afuera, solo desktop) */
.room-kind{display:none}
@media (min-width:901px){
  .room-kind{
    display:block;
    text-align:center;
    font-size:14px;
    font-weight:500;
    color:#111;
    margin-top:7px
  }
}

/* Colores estados */
.room-card.libre{background-color:var(--green); color:#fff; box-shadow:0 0 6px #00C851AA;}
.room-card.ocupada{background-color:var(--red); color:#000; box-shadow:0 0 6px #FF0000AA;}
.room-card.limpieza{background-color:var(--yellow); color:#000; box-shadow:0 0 6px #FFBB33AA;}
.room-card.reservada{background:#A855F7; color:#fff; box-shadow:0 0 6px #A855F7AA;}

/* Alerta <15 min */
@keyframes flashRedBlue {
  0% { background-color: #ff0000; box-shadow: 0 0 12px #ff0000; }
  50% { background-color: #2563eb; box-shadow: 0 0 12px #2563eb; }
  100% { background-color: #ff0000; box-shadow: 0 0 12px #ff0000; }
}
.room-card.alerta-tiempo { animation: flashRedBlue .5s linear infinite alternate; }

/* Vencida: azul fija + campana */
.room-card.vencida{ background: var(--blue) !important; color:#fff !important; }
.room-card.vencida::after{
  content: "🔔";
  position: absolute; top: 6px; right: 6px; font-size: 28px; line-height: 1;
}

/* Móvil (2 columnas orden especial) */
#mobile-grid{display:none}
@media(max-width:900px){
  .room-card::after{
    content: attr(data-tipo);
    position:absolute; bottom:4px; left:50%; transform:translateX(-50%);
    font-size:11px; font-weight:700; text-transform:uppercase; color:#fff; text-shadow:0 1px 2px rgba(0,0,0,0.5);
  }
  .brand div{display:none}
  #row-top,#row-bot{display:none}
  #mobile-grid{ display:flex; gap:12px; margin-top:2px; min-height:60vh; padding:0 4px; }
  #mobile-grid>div{ display:flex; flex-direction:column; gap:10px; width:52%; }
  .room-card{ min-height:115px; padding:10px 30px; font-size:15px; }
  .room-card::after{ bottom:6px; font-size:8px; font-weight:200; }
}

/* Registros */
.card{background:#fff;border:1px solid var(--border);border-radius:16px;padding:16px;box-shadow:var(--shadow)}
.controls{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:12px}
.controls input[type=date], .controls select{background:#fff;border:1px solid var(--border);border-radius:10px;padding:8px 10px}
.controls button{background:#0B5FFF;color:#fff;border:none;border-radius:10px;padding:8px 12px;cursor:pointer}
.controls .ghost{background:#fff;color:var(--text);border:1px solid var(--border)}
.summary{display:flex;flex-wrap:wrap;gap:10px}
.summary .chip{background:#EEF2FF;border:1px solid var(--border);color:#1E293B;padding:8px 10px;border-radius:999px;font-weight:700}
.table{width:100%;border-collapse:separate;border-spacing:0 6px}
.table th{font-size:12px;color:#64748B;text-transform:uppercase;letter-spacing:.06em;text-align:left;padding:8px}
.table td{background:#fff;border:1px solid var(--border);padding:10px}
.table tr td:first-child{border-top-left-radius:10px;border-bottom-left-radius:10px}
.table tr td:last-child{border-top-right-radius:10px;border-bottom-right-radius:10px}
.count-note{color:#6B7280;text-align:right;margin-top:8px;font-size:12px}

/* Línea central y etiquetas */
.panel-grid{ position:relative; width:100%; }
.central-divider{ position:absolute; top:0; bottom:0; left:50%; width:2px; background:#000; transform:translateX(-50%); z-index:1; }
.section-label{ position:absolute; top:-28px; width:50%; text-align:center; font-weight:900; font-size:18px; color:#000; z-index:2; }
.section-left{ left:0; } .section-right{ right:0; }
@media (max-width: 900px){ .central-divider,.section-label{ display:none; } }

/* Ajustes puntuales (respetados) */
@media (min-width: 901px) { #hab-21 { position: relative; top: -20px; z-index: 3; } }
@media (min-width: 901px) { #hab-21 ~ .room-kind { position: relative; top: -20px; } }
@media (min-width: 901px) { .room-kind { font-weight: 900 !important; } }

html, body { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; text-rendering: optimizeLegibility; }
* { font-smooth: always; }
.swal2-container { z-index: 99999 !important; }
/* ========================
   CAMPANA flotante (solo escritorio)
   ======================== */
@media (min-width: 901px) {
  .room-card.vencida::after {
    content: "🔔";
    position: absolute;
    top: -34px; /* sube la campana por encima de la tarjeta */
    left: 50%;
    transform: translateX(-50%);
    font-size: 28px;
    line-height: 1;
  }

  /* subimos un poco las etiquetas HOTEL VIEJO / HOTEL NUEVO */
  .section-label {
    top: -60px !important; /* antes estaba -28px */
  }
}
/* ===== Caja / Turnos (abajo a la derecha) ===== */
.turno-box {
  position: fixed;
  right: 16px;
  bottom: 16px;
  z-index: 6;
  width: 320px;
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 14px;
  box-shadow: var(--shadow);
  padding: 12px;
  font-size: 13px;
}
.turno-box h3{
  margin: 0 0 8px 0;
  font-size: 14px;
  font-weight: 800;
}
.turno-row{ display:flex; align-items:center; justify-content:space-between; gap:8px; }
.turno-radios{ display:flex; gap:8px; flex-wrap:wrap; }
.turno-radios label{
  display:flex; align-items:center; gap:6px; padding:6px 8px; border:1px solid var(--border);
  border-radius: 999px; cursor:pointer; user-select:none;
}
.turno-stats{ display:flex; gap:8px; margin-top:8px; }
.turno-chip{ background: var(--chip); border:1px solid var(--border); padding:6px 8px; border-radius:999px; font-weight:800; }
.turno-cta{ display:flex; gap:8px; align-items:center; margin-top:10px; }
.turno-cta button{
  background:#0B5FFF; color:#fff; border:none; border-radius:10px; padding:8px 12px; cursor:pointer;
}
@media(max-width:900px){
  .turno-box{ position: static; width:100%; margin-top:12px; }
}
.table-container {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  text-align: left;
  scroll-behavior: smooth;
  display: block;
}

.table {
  border-collapse: collapse;
  width: 100%;
  min-width: 950px;
  margin: 0;
}

.table th, .table td {
  padding: 8px;
  border: 1px solid #ddd;
  white-space: nowrap;
  text-align: center;
}

.table thead th:first-child,
.table tbody td:first-child {
  padding-left: 12px;
  text-align: left;
}

/* ======== MODO TARJETAS EN CELULAR ======== */
@media (max-width: 768px) {
  /* Oculta la tabla y genera tarjetas desde el DOM */
  .table-container { overflow-x: visible; }
  .table { display: none; }

/* ===== FIX DEFINITIVO: tabla de movimientos correcta en mobile ===== */
#turno-movimientos table {
    display: table !important;
    width: 100% !important;
    border-collapse: collapse !important;
}

#turno-movimientos thead {
    display: table-header-group !important;
}

#turno-movimientos tbody {
    display: table-row-group !important;
}

#turno-movimientos tr {
    display: table-row !important;
}

#turno-movimientos td,
#turno-movimientos th {
    display: table-cell !important;
    padding: 6px !important;
    white-space: nowrap !important;
    text-align: left !important;
}

  .mobile-cards {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .mobile-card {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    overflow: hidden;
    transition: all 0.25s ease;
  }

  .mobile-summary {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 14px;
    font-weight: bold;
    background: #f8f9fa;
    cursor: pointer;
  }

  .mobile-summary span { font-size: 14px; }

  .mobile-details {
    display: none;
    padding: 10px;
    border-top: 1px solid #eee;
    font-size: 14px;
  }

  .mobile-details.active { display: block; }

  .duracion-larga {
    color: #ff0000;
    font-weight: bold;
    text-shadow: 0 0 3px rgba(255,0,0,0.4);
  }
}

</style>
</head>
<body>
<header>
  <div class="header-inner">
    <div class="brand">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="#0B5FFF"><path d="M12 3l6 4v6c0 3.866-3.582 7-8 7s-8-3.134-8-7V7l6-4h4z"/></svg>
      <div>Panel — Telo operativo</div>
    </div>
    <button id="menu-toggle" aria-label="Abrir menú">☰</button>
    <div class="nav">
        <button class="<?php echo ($view==='panel'?'active':''); ?>" onclick="location.href='?view=panel'">Panel</button>
        <button class="<?php echo ($view==='reportes'?'active':''); ?>" onclick="location.href='?view=reportes'">Reportes</button>
        <button
  class=""
  onclick="window.open('https://lamoradatandil.com/inventario.php', '_blank')"
>
  Inventario
</button>
<button onclick="window.open('https://lamoradatandil.com/minibar.php','_blank')">Link menú</button>
        
        <button onclick="window.open('https://lamoradatandil.com/envio-dinero.php','_blank')">Enviar dinero</button>
      <button class="<?php echo ($view==='minibar'?'active':''); ?>" onclick="location.href='?view=minibar'">Minibar</button>
      <button class="<?php echo ($view==='registros'?'active':''); ?>" onclick="location.href='?view=registros'">Habitaciones</button>
      <button class="<?php echo ($view==='valores'?'active':''); ?>" onclick="location.href='?view=valores'">Editar valores</button>
      <div class="clock" id="arg-clock">--:--:--</div>
      <span id="turno-label" style="font-weight:700;margin-left:8px;color:#0B5FFF">Turnos de -- h</span>
    </div>
  </div>
</header>
<!-- Menú móvil -->
<div id="mobile-menu">
  <button onclick="location.href='?view=panel'">Panel</button>
  <button onclick="location.href='?view=minibar'">Minibar</button>
  <button onclick="location.href='?view=registros'">Habitaciones</button>
  <button onclick="location.href='?view=valores'">Editar valores</button>
  <button onclick="location.href='?view=reportes'">Reportes</button>
  <button onclick="window.open('https://lamoradatandil.com/inventario.php','_blank')">Inventario</button>
  <button onclick="window.open('https://lamoradatandil.com/minibar.php','_blank')">Link menú</button>
  <button onclick="window.open('https://lamoradatandil.com/envio-dinero.php','_blank')">Enviar dinero</button>
</div>

<script>
document.getElementById('menu-toggle').addEventListener('click', function(){
  const menu = document.getElementById('mobile-menu');
  menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
});
</script>

<?php if($view==='valores'): ?>

<?php
$PASS = "Mora2025";

$autorizado = false;

// Revisión de intento de acceso
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['clave'])) {
    if ($_POST['clave'] === $PASS) {
        $autorizado = true;
        $_SESSION['valores_ok'] = true;
    } else {
        echo '<div style="background:#FEE2E2;color:#B91C1C;padding:10px;margin:10px;border-radius:6px;">
                ❌ Clave incorrecta
              </div>';
    }
}

// Sesión ya autorizada
if (isset($_SESSION['valores_ok']) && $_SESSION['valores_ok'] === true) {
    $autorizado = true;
}
?>

<main class="container">

<?php if(!$autorizado): ?>

  <div class="card" style="max-width:400px;margin-top:40px;">
    <h2 style="margin-top:0">🔐 Zona restringida</h2>
    <p>Ingresá la clave para editar los valores del hotel.</p>
    <form method="post">
      <input type="password" name="clave" placeholder="Clave" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:8px;">
      <button type="submit" style="margin-top:10px;background:#0B5FFF;color:#fff;border:none;padding:10px 16px;border-radius:8px;cursor:pointer;width:100%;">
        Acceder
      </button>
    </form>
    <a href="?view=panel" style="display:block;margin-top:10px;color:#0B5FFF;text-decoration:none;">⬅ Volver al panel</a>
  </div>

<?php else: ?>

<?php
// Guardado original
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['precios'])){
    foreach($_POST['precios'] as $tipo=>$turnos){
      foreach($turnos as $turno=>$precio){
        $p = floatval($precio);
        $u = $conn->prepare("UPDATE precios_habitaciones SET precio=? WHERE tipo=? AND turno=?");
        $u->bind_param('dss',$p,$tipo,$turno); $u->execute(); $u->close();
      }
    }
    echo '<div style="background:#D1FAE5;color:#065F46;padding:10px;margin:10px;border-radius:6px;">💾 Valores actualizados correctamente.</div>';
}
$precios = [];
$res = $conn->query("SELECT * FROM precios_habitaciones ORDER BY FIELD(tipo,'Común','VIP','Super VIP'), turno");
while($r=$res->fetch_assoc()){ $precios[$r['tipo']][$r['turno']] = $r['precio']; }
?>

<div class="card" style="max-width:600px">
  <h2 style="margin-top:0">🛏 Editar valores</h2>
  <form method="post">
    <?php foreach(['Común','VIP','Super VIP'] as $tipo): ?>
      <h3 style="margin-top:20px"><?= $tipo ?></h3>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <label>Turno 2h<br><input type="number" step="0.01" name="precios[<?= $tipo ?>][turno-2h]" value="<?= $precios[$tipo]['turno-2h'] ?? 0 ?>"></label>
        <label>Turno 3h<br><input type="number" step="0.01" name="precios[<?= $tipo ?>][turno-3h]" value="<?= $precios[$tipo]['turno-3h'] ?? 0 ?>"></label>
        <label>Noche<br><input type="number" step="0.01" name="precios[<?= $tipo ?>][noche]" value="<?= $precios[$tipo]['noche'] ?? 0 ?>"></label>
        <label>Noche finde<br><input type="number" step="0.01" name="precios[<?= $tipo ?>][noche-finde]" value="<?= $precios[$tipo]['noche-finde'] ?? 0 ?>"></label>
      </div>
    <?php endforeach; ?>
    <br>
    <button type="submit" style="background:#0B5FFF;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;">Guardar cambios</button>
  </form>
  <a href="?view=panel" style="display:block;margin-top:10px;color:#0B5FFF;text-decoration:none;">⬅ Volver al panel</a>
</div>

<?php endif; ?>
</main>

<?php endif; ?>

<?php 
if($view === 'reportes'): ?>

<?php
$PASS = "Mora2025";

$autorizado = false;

// Si se envió contraseña
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['clave'])) {
    if ($_POST['clave'] === $PASS) {
        $autorizado = true;
        $_SESSION['reportes_ok'] = time(); // marca de tiempo
    } else {
        echo '<div style="background:#FEE2E2;color:#B91C1C;padding:10px;margin:10px;border-radius:6px;">
                ❌ Clave incorrecta
              </div>';
    }
}

// Si ya entró antes en esta sesión
if (!empty($_SESSION['reportes_ok'])) {
    $autorizado = true;
}
?>

<main class="container">
<?php if(!$autorizado): ?>

  <div class="card" style="max-width:400px;margin-top:40px;">
    <h2 style="margin-top:0">🔐 Acceso restringido</h2>
    <p>Ingresá la clave para ver los reportes del dueño.</p>
    <form method="post">
      <input type="password" name="clave" placeholder="Clave" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:8px;">
      <button type="submit" style="margin-top:10px;background:#0B5FFF;color:#fff;border:none;padding:10px 16px;border-radius:8px;cursor:pointer;width:100%;">
        Acceder
      </button>
    </form>
    <a href="?view=panel" style="display:block;margin-top:10px;color:#0B5FFF;text-decoration:none;">⬅ Volver al panel</a>
  </div>

<?php else: ?>

<?php
// Traer historial de turnos ya cerrados
$desde = preg_replace('/[^0-9\-]/','', $_GET['desde'] ?? '');
$hasta = preg_replace('/[^0-9\-]/','', $_GET['hasta'] ?? '');

$sql = "SELECT turno, inicio, fin, total, ocupaciones
        FROM historial_turnos
        WHERE 1";

if ($desde !== '') {
    $sql .= " AND DATE(inicio) >= '".$conn->real_escape_string($desde)."'";
}

if ($hasta !== '') {
    $sql .= " AND DATE(inicio) <= '".$conn->real_escape_string($hasta)."'";
}

$sql .= " ORDER BY inicio DESC";

$res = $conn->query($sql);
$rows = [];
while($r = $res->fetch_assoc()) { $rows[] = $r; }
// Calcular total de caja del filtro seleccionado
// Calcular totales del filtro
$total_caja = 0;
$total_ocupaciones = 0;
$cantidad_turnos = count($rows);

foreach ($rows as $r) {
    $total_caja += (int)$r['total'];
    $total_ocupaciones += (int)$r['ocupaciones'];
}

// Evitar división por cero
$promedio_turno = ($cantidad_turnos > 0)
    ? round($total_caja / $cantidad_turnos)
    : 0;

?>

<div class="card" style="margin-top:20px;">
  <h2 style="margin:0 0 10px 0">📊 Reportes de Turnos</h2>
<div style="display:flex; gap:10px; margin-bottom:10px;">
  <a href="?view=reportes&desde=<?=date('Y-m-d')?>&hasta=<?=date('Y-m-d')?>" class="btn-small">Hoy</a>
  <a href="?view=reportes&desde=<?=date('Y-m-d', strtotime('-1 day'))?>&hasta=<?=date('Y-m-d', strtotime('-1 day'))?>" class="btn-small">Ayer</a>
  <a href="?view=reportes&desde=<?=date('Y-m-d', strtotime('-7 day'))?>&hasta=<?=date('Y-m-d')?>" class="btn-small">Últimos 7 días</a>
  <a href="?view=reportes&desde=<?=date('Y-m-01')?>&hasta=<?=date('Y-m-d')?>" class="btn-small">Este mes</a>
</div>

<style>
.btn-small {
  background:#0B5FFF;
  padding:6px 10px;
  color:white;
  border-radius:6px;
  font-size:13px;
  text-decoration:none;
}
.btn-small:hover {
  background:#0044cc;
}
</style>

<form method="get" class="controls" style="margin-bottom:15px; display:flex;flex-wrap:wrap; gap:10px;">
  <input type="hidden" name="view" value="reportes">

  <label>Desde
    <input type="date" name="desde" value="<?= safe($_GET['desde'] ?? '') ?>">
  </label>

  <label>Hasta
    <input type="date" name="hasta" value="<?= safe($_GET['hasta'] ?? '') ?>">
  </label>

  <button type="submit">Filtrar</button>
<button type="button" class="ghost" onclick="location.href='?view=reportes'">Borrar filtros</button>

<div style="margin-left:20px;">
  <div style="font-weight:bold; font-size:15px;">
    💰 Total caja: $ <?= number_format($total_caja, 0, ',', '.') ?>
  </div>
  <div style="font-size:14px; margin-top:2px;">
    🛏 Ocupaciones: <?= $total_ocupaciones ?>
  </div>
  <div style="font-size:14px;">
    📊 Promedio por turno: $ <?= number_format($promedio_turno, 0, ',', '.') ?>
  </div>
</div>


</form>

<table class="table" style="width:100%;border-collapse:collapse;">
  <thead>
    <tr>
      <th>Fecha</th>
      <th>Turno</th>
      <th>Inicio</th>
      <th>Fin</th>
      <th>Ocupaciones</th>
      <th>Total $</th>
    </tr>
  </thead>
    <tbody>
    <?php if(empty($rows)): ?>
      <tr><td colspan="6" style="padding:10px;text-align:center;color:#777;">Sin turnos cerrados todavía.</td></tr>
   <?php else: foreach($rows as $r): ?>
  <tr>
    <td><?= fmtFechaArg($r['inicio']) ?></td>
    <td><?= turnoNombre($r['turno']) ?></td>
    <td><?= fmtHoraArg($r['inicio']) ?></td>
    <td><?= fmtHoraArg($r['fin']) ?></td>
    <td><?= (int)$r['ocupaciones'] ?></td>
    <td>$ <?= number_format((int)$r['total'],0,',','.') ?></td>
  </tr>
  <tr>
    <td colspan="6" style="background:#f8fafc;">
      <?php $movs = cajaDetalleDesdeHasta($conn, $r['inicio'], $r['fin'] ?? $r['inicio'], 500); ?>
      <details>
        <summary style="cursor:pointer;font-weight:600;color:#0B5FFF;">Ver movimientos del turno</summary>
        <div style="margin-top:8px;">
          <?php if(empty($movs)): ?>
            <div style="color:#6b7280;">Sin movimientos registrados.</div>
          <?php else: ?>
          <table style="width:100%; border-collapse:collapse; font-size:13px;">
            <thead>
              <tr style="text-align:left;">
                <th style="padding:4px 6px;">Origen</th>
                <th style="padding:4px 6px;">Hora</th>
                <th style="padding:4px 6px;">Monto</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($movs as $m): ?>
                <tr>
                  <td style="padding:4px 6px;">
                    <?php
                      if($m['tipo']==='venta')      echo safe($m['hab']);
                      elseif($m['tipo']==='ajuste') echo '💳 '.safe($m['hab']);
                      else                          echo 'Hab. '.safe($m['hab']);
                    ?>
                  </td>
                  <td style="padding:4px 6px;"><?= safe($m['inicio']) ?></td>
                  <td style="padding:4px 6px; color:<?= ($m['monto']<0?'#b91c1c':'#111827') ?>; text-align:right;">
                    $ <?= number_format($m['monto'],0,',','.') ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </details>
    </td>
  </tr>
<?php endforeach; endif; ?>

    </tbody>
  </table>

  <a href="?view=panel" style="display:block;margin-top:10px;color:#0B5FFF;text-decoration:none;">⬅ Volver al panel</a>
</div>

<?php endif; ?>
</main>

<?php endif; ?>

<?php if($view==='panel'): ?>
<main class="container" id="panel">
  <div class="panel-grid">
    <div class="section-label section-left">HOTEL VIEJO</div>
    <div class="section-label section-right">HOTEL NUEVO</div>
    <div class="central-divider"></div>

    <!-- Fila superior: 40 → 21 -->
    <div class="rooms-row" id="row-top">
      <?php foreach(range(40,21) as $id):
        $s=$states[$id] ?? ['estado'=>'libre'];
        $estado=$s['estado']; $tipo=tipoDeHabitacion($id,$SUPER_VIP,$VIP_LIST);
        $rest = sumRemainingForRoom($conn,$id);
        $estadoTxt = ($estado==='libre'?'Disponible':($estado==='limpieza'?'Limpieza':($estado==='reservada'?'Reservada':'Ocupada')));
        $timer = formatTimerLabel($rest, $estado);
        $isSuper = in_array($id,$SUPER_VIP);
      ?>
      <div class="room-wrap">
        <div class="room-card <?= $estado ?> <?= $isSuper?'super':'' ?>" id="hab-<?= $id ?>" data-id="<?= $id ?>" data-restante="<?= $rest ?>" data-tipo="<?= $tipo ?>">
          <div class="state-line"><?= safe($estadoTxt) ?></div>
          <div class="timer" id="timer-<?= $id ?>"><?= safe($timer) ?></div>
          <div class="room-num"><?= $id ?></div>
        </div>
        <div class="room-kind"><?= $tipo ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="rows-spacer"></div>

    <!-- Fila inferior: 1 → 20 -->
    <div class="rooms-row" id="row-bot">
      <?php foreach(range(1,20) as $id):
        $s=$states[$id] ?? ['estado'=>'libre'];
        $estado=$s['estado']; $tipo=tipoDeHabitacion($id,$SUPER_VIP,$VIP_LIST);
        $rest = sumRemainingForRoom($conn,$id);
        $estadoTxt = ($estado==='libre'?'Disponible':($estado==='limpieza'?'Limpieza':($estado==='reservada'?'Reservada':'Ocupada')));
        $timer = formatTimerLabel($rest, $estado);
        $isSuper = in_array($id,$SUPER_VIP);
      ?>
      <div class="room-wrap">
        <div class="room-card <?= $estado ?> <?= $isSuper?'super':'' ?>" id="hab-<?= $id ?>" data-id="<?= $id ?>" data-restante="<?= $rest ?>" data-tipo="<?= $tipo ?>">
          <div class="state-line"><?= safe($estadoTxt) ?></div>
          <div class="timer" id="timer-<?= $id ?>"><?= safe($timer) ?></div>
          <div class="room-num"><?= $id ?></div>
        </div>
        <div class="room-kind"><?= $tipo ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Grid móvil (2 columnas, orden especial) -->
  <div id="mobile-grid" class="mobile-grid"></div>
  <!-- Caja de turnos revisada (discreta y funcional) -->
<div id="turno-mini-btn" 
     style="position:fixed; right:16px; bottom:16px; z-index:6; background:#e5e7eb; color:#111; border:1px solid #ccc; border-radius:50%; width:42px; height:42px; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:18px; font-weight:bold;">
📋
</div>

<div id="turno-box" style="position:fixed; right:16px; bottom:16px; z-index:5; width:320px; background:#fff; border:1px solid #d1d5db; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.1); padding:10px; font-size:13px; display:none;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
    <strong id="turno-actual-label">Turno actual: —</strong>
    <button id="cerrar-turno-box" style="background:none; border:none; font-size:16px; cursor:pointer; color:#555;">×</button>
  </div>

  <div style="display:flex; gap:6px; margin-bottom:10px;">
    <button class="btn-turno" data-turno="manana" style="flex:1; border:1px solid #ccc; background:#fff; border-radius:8px; padding:6px 0; cursor:pointer;">Mañana</button>
    <button class="btn-turno" data-turno="tarde" style="flex:1; border:1px solid #ccc; background:#fff; border-radius:8px; padding:6px 0; cursor:pointer;">Tarde</button>
    <button class="btn-turno" data-turno="noche" style="flex:1; border:1px solid #ccc; background:#fff; border-radius:8px; padding:6px 0; cursor:pointer;">Noche</button>
  </div>

  <div style="margin-bottom:6px; font-weight:bold; font-size:14px;">
    Inicio: <span id="turno-inicio">--:--</span> — Total: <span id="turno-total">0</span>
  </div>

  <div id="turno-movimientos" style="max-height:200px; overflow-y:auto; border:1px solid #e5e7eb; border-radius:8px; background:#fff;">
    <table style="width:100%; border-collapse:collapse; font-size:12px;">
     <thead style="position:sticky; top:0; background:#f3f4f6;">
  <tr>
    <th style="text-align:left; padding:6px;">Hab</th>
    <th style="text-align:left; padding:6px;">Inicio</th>
    <th style="text-align:right; padding:6px;">Monto</th>
    <th style="text-align:center; padding:6px;">Borrar</th>
  </tr>
</thead>
      <tbody id="tb-mov-body">
        <tr><td colspan="3" style="text-align:center; color:#9ca3af; padding:8px;">Sin movimientos</td></tr>
      </tbody>
    </table>
  </div>
</div>

<script>
// Mostrar / ocultar caja
document.getElementById('turno-mini-btn').addEventListener('click', ()=>{
  const box=document.getElementById('turno-box');
  box.style.display = (box.style.display==='none'||!box.style.display)?'block':'none';
});
document.getElementById('cerrar-turno-box').addEventListener('click', ()=>{
  document.getElementById('turno-box').style.display='none';
});

// Iniciar turno manual
document.querySelectorAll('.btn-turno').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    const turno=btn.dataset.turno;
    const nombres={manana:'Mañana',tarde:'Tarde',noche:'Noche'};
    const conf=await Swal.fire({
      title:`¿Iniciar turno ${nombres[turno]}?`,
      icon:'question',
      showCancelButton:true,
      confirmButtonText:'Sí, iniciar',
      cancelButtonText:'Cancelar'
    });
    if(!conf.isConfirmed) return;

    const res = await fetch(location.href,{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({accion:'iniciar_turno',nuevo:turno})
    });
    const j=await res.json();
    if(j.ok){
      Swal.fire({icon:'success',title:`Turno ${nombres[turno]} iniciado`,timer:1500,showConfirmButton:false});
      actualizarTurnoBox();
    } else {
      Swal.fire({icon:'error',title:'Error',text:j.error||'No se pudo iniciar'});
    }
  });
});

// Refrescar cada 3s y mostrar movimientos reales desde historial_habitaciones
async function actualizarTurnoBox(){
  try{
    const r=await fetch('?ajax_turno=1',{cache:'no-store'});
    const j=await r.json();
    if(!j.ok) return;

    document.getElementById('turno-actual-label').textContent = 'Turno actual: '+ (j.turno_txt || '—' );
    document.getElementById('turno-inicio').textContent = j.inicio_arg||'--:--';
    document.getElementById('turno-total').textContent = (j.total||0).toLocaleString('es-AR');

      const body=document.getElementById('tb-mov-body');
    if(j.detalle && j.detalle.length){
      body.innerHTML=j.detalle.map(m=>`
        <tr>
          <td style="padding:4px 6px;">${m.hab}</td>
          <td style="padding:4px 6px;">${m.inicio}</td>
          <td style="padding:4px 6px; text-align:right; ${m.monto<0?'color:#b91c1c;':'color:#111827;'}">${m.monto}</td>
          <td style="padding:4px 6px; text-align:center;">
            ${m.tipo==='ajuste' ? '' : `
              <button
                class="btn-del-mov"
                data-id="${m.id}"
                data-tipo="${m.tipo}"
                style="border:none;background:none;font-size:16px;cursor:pointer;">
                🗑
              </button>
            `}
          </td>
        </tr>
      `).join('');
    }else{
      body.innerHTML='<tr><td colspan="4" style="text-align:center; color:#9ca3af; padding:8px;">Sin movimientos</td></tr>';
    }

  }catch(e){}
}
setInterval(actualizarTurnoBox,3000);
actualizarTurnoBox();

/* ===== ELIMINAR MOVIMIENTO (habitaciones o ventas, con clave admin) ===== */
document.addEventListener("click", async e => {
  if (!e.target.classList.contains("btn-del-mov")) return;

  const id   = e.target.dataset.id;
  const tipo = e.target.dataset.tipo; // 'hab' o 'venta'

  const clave = await Swal.fire({
    title: "Clave de administrador",
    input: "password",
    inputPlaceholder: "Ingresá la clave",
    showCancelButton: true,
    confirmButtonText: "Eliminar",
    cancelButtonText: "Cancelar"
  });

  if (!clave.value) return;

  const r = await fetch("", {
    method: "POST",
    headers: {"Content-Type": "application/x-www-form-urlencoded"},
    body: new URLSearchParams({
      accion: "borrar_mov",
      id: id,
      tipo: tipo,
      clave: clave.value
    })
  });

  const j = await r.json();

  if (!j.ok) {
    Swal.fire("Error", j.error || "No se pudo borrar", "error");
    return;
  }

  Swal.fire("Eliminado", "Movimiento borrado correctamente", "success");
  actualizarTurnoBox();
});

</script>

</main>
<?php endif; ?>

<?php if($view==='registros'):
  $desde = preg_replace('/[^0-9\-]/','', $_GET['desde'] ?? argDateToday());
  $hasta = preg_replace('/[^0-9\-]/','', $_GET['hasta'] ?? argDateToday());
  $tipoF = $_GET['tipo'] ?? 'todos';

  $summary = ['total'=>0,'super'=>0,'vip'=>0,'comun'=>0,'t2'=>0,'t3'=>0,'tnoche'=>0,'monto'=>0];
  $rows = [];
  $sql = "SELECT habitacion, tipo, estado, turno, hora_inicio, hora_fin, duracion_minutos, fecha_registro, precio_aplicado, es_extra
          FROM historial_habitaciones
          WHERE fecha_registro BETWEEN ? AND ?";
  if($tipoF!=='todos') $sql.=" AND tipo=?";
 $sql.=" ORDER BY hora_inicio DESC";
  $stmt = $conn->prepare($sql);
  if($tipoF!=='todos'){ $stmt->bind_param('sss',$desde,$hasta,$tipoF); } else { $stmt->bind_param('ss',$desde,$hasta); }
  $stmt->execute(); $res = $stmt->get_result();
  while($r = $res->fetch_assoc()){
    $rows[] = $r;
    $summary['total']++;
    if($r['tipo']==='Super VIP') $summary['super']++; elseif($r['tipo']==='VIP') $summary['vip']++; else $summary['comun']++;
    if($r['turno']==='turno-2h') $summary['t2']++; elseif($r['turno']==='turno-3h') $summary['t3']++; elseif($r['turno']==='noche' || $r['turno']==='noche-finde') $summary['tnoche']++;

    // Total del día: SOLO registros cerrados (hora_fin no nula), normal + extra
    if(!empty($r['hora_fin'])){
      $summary['monto'] += (int)($r['precio_aplicado'] ?? 0);
    }
  }
  $stmt->close();

  // Formateo de total con separador de miles (AR)
  $fmtTotal = number_format((int)$summary['monto'], 0, ',', '.');
?>
<main class="container">
  <div class="card">
    <h2 style="margin:0 0 10px 0">📅 Registros</h2>
    <form method="get" class="controls">
      <input type="hidden" name="view" value="registros">
      <label>Desde <input type="date" name="desde" value="<?php echo safe($desde); ?>"></label>
      <label>Hasta <input type="date" name="hasta" value="<?php echo safe($hasta); ?>"></label>
      <label>Tipo
        <select name="tipo">
          <?php
            $opts = ['todos'=>'Todos','Super VIP'=>'Super VIP','VIP'=>'VIP','Común'=>'Común'];
            foreach($opts as $val=>$txt){
              $sel = ($tipoF===$val)?'selected':'';
              echo "<option value=\"".safe($val)."\" $sel>".safe($txt)."</option>";
            }
          ?>
        </select>
      </label>
      <button type="submit">Buscar</button>
      <button type="button" class="ghost" onclick="location.href='?view=registros'">Borrar filtros</button>
      <button type="button" onclick="exportCSV()">Exportar CSV</button>
      <a href="?view=panel" style="margin-left:auto;text-decoration:none;color:#0B5FFF">← Volver al panel</a>
    </form>

    <div class="summary">
      <div class="chip">Total ocupaciones: <b><?php echo $summary['total']; ?></b></div>
      <div class="chip">Super VIP: <b><?php echo $summary['super']; ?></b></div>
      <div class="chip">VIP: <b><?php echo $summary['vip']; ?></b></div>
      <div class="chip">Comunes: <b><?php echo $summary['comun']; ?></b></div>
      <div class="chip">Turno 2h: <b><?php echo $summary['t2']; ?></b></div>
      <div class="chip">Turno 3h: <b><?php echo $summary['t3']; ?></b></div>
      <div class="chip">Noche: <b><?php echo $summary['tnoche']; ?></b></div>

    </div>

    <div class="table-container">
  <table class="table">
    <thead>
      <tr>
        <th>Habitación</th>
        <th>Tipo</th>
        <th>Turno</th> <!-- real -->
        <th>Hora Inicio</th> <!-- ARG sin segundos -->
        <th>Día</th>
        <th>Mes</th>
        <th>Año</th>
        <th>Hora Final</th> <!-- ARG sin segundos -->
        <th>Duración (min)</th>
        <th>Monto</th>
      </tr>
    </thead>
    <tbody>
  <?php if(empty($rows)): ?>
    <tr><td colspan="10" style="color:#64748B;padding:18px;background:transparent;border:none">Sin registros en el rango seleccionado.</td></tr>
  <?php else: foreach($rows as $r): ?>
    <tr>
      <td data-label="Habitación"><?= safe($r['habitacion']) ?></td>
      <td data-label="Tipo"><?= safe($r['tipo']) ?></td>
      <td data-label="Turno"><?= safe($r['turno']) ?></td>
      <td data-label="Hora Inicio"><?= safe(fmtHoraArg($r['hora_inicio'])) ?></td>
      <td data-label="Día"><?= safe(partDia($r['hora_inicio'])) ?></td>
      <td data-label="Mes"><?= safe(partMes($r['hora_inicio'])) ?></td>
      <td data-label="Año"><?= safe(partAnio($r['hora_inicio'])) ?></td>
      <td data-label="Hora Final"><?= safe(fmtHoraArg($r['hora_fin'])) ?></td>
      <td data-label="Duración (min)"><?= safe($r['duracion_minutos']) ?></td>
      <td data-label="Monto">$ <?= number_format((int)($r['precio_aplicado']??0),0,',','.') ?></td>
    </tr>
  <?php endforeach; endif; ?>
</tbody>
  </table>
</div>
    <script>
document.addEventListener('DOMContentLoaded', ()=>{
  document.querySelectorAll('.table tbody tr').forEach(row=>{
    const turno = row.cells[2]?.textContent.trim() || '';
    const duracion = parseInt(row.cells[8]?.textContent.trim() || '0',10);
    const horaFinTxt = row.cells[7]?.textContent.trim();
    let marcar = false;

    // Turno 2h → más de 135 minutos
    if (turno.includes('turno-2h') && duracion > 135) marcar = true;

    // Turno 3h → más de 195 minutos
    if (turno.includes('turno-3h') && duracion > 195) marcar = true;

    // Turnos noche → más de 15 minutos después de las 10:00
    if (turno === 'noche' || turno === 'noche-finde') {
      if (horaFinTxt) {
        const [h, m] = horaFinTxt.split(':').map(x => parseInt(x, 10));
        if (h > 10 || (h === 10 && m > 15)) marcar = true;
      }
    }

    if (marcar) {
      row.cells[8].style.color = '#ff0000';
      row.cells[8].style.fontWeight = 'bold';
      row.cells[8].style.textShadow = '0 0 4px rgba(255,0,0,0.4)';
    }
  });
});

    </script>

    <div class="count-note">Mostrando <?php echo count($rows); ?> registros</div>
  </div>
</main>
<?php endif; ?>
<?php if($view==='minibar'):
  $desde = preg_replace('/[^0-9\-]/','', $_GET['desde'] ?? argDateToday());
  $hasta = preg_replace('/[^0-9\-]/','', $_GET['hasta'] ?? argDateToday());

  $rows = [];
  $total = 0;

  list($desdeUtc,) = argDateRangeToUtcBounds($desde);
  list(,$hastaUtc) = argDateRangeToUtcBounds($hasta);

  $sql = "
    SELECT id, producto_id, nombre, precio, hora,
           hora AS hora_local
    FROM ventas_turno
    WHERE hora BETWEEN ? AND ?
    ORDER BY hora DESC
  ";
  $stmt = $conn->prepare($sql);
 $stmt->bind_param('ss', $desdeUtc, $hastaUtc);
  $stmt->execute();
  $res = $stmt->get_result();
  while($r = $res->fetch_assoc()){
    $rows[] = $r;
    $total += (int)$r['precio'];
  }
  $stmt->close();
  $fmtTotal = number_format($total,0,',','.');
?>
<main class="container">
  <div class="card">
    <h2 style="margin:0 0 10px 0">🧃 Registros de Minibar</h2>
    <form method="get" class="controls">
      <input type="hidden" name="view" value="minibar">
      <label>Desde <input type="date" name="desde" value="<?=safe($desde)?>"></label>
      <label>Hasta <input type="date" name="hasta" value="<?=safe($hasta)?>"></label>
      <button type="submit">Buscar</button>
      <button type="button" class="ghost" onclick="location.href='?view=minibar'">Borrar filtros</button>
      <a href="?view=panel" style="margin-left:auto;text-decoration:none;color:#0B5FFF">← Volver al panel</a>
    </form>

    <div class="summary">
      <div class="chip">Total ventas: <b><?=count($rows)?></b></div>
    </div>

    <table class="table">
      <thead>
        <tr>
          <th>Cant.</th>
          <th>Producto</th>
          <th>Hora de venta</th>
          <th>Monto</th>
        </tr>
      </thead>
      <tbody>
        <?php if(empty($rows)): ?>
          <tr><td colspan="4" style="text-align:center;color:#64748B;padding:18px;">Sin ventas registradas</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr style="background:#f0fff4;">
            <td data-label="Cant.">1</td>
            <td data-label="Producto">🧃 <?=safe($r['nombre'])?></td>
            <td data-label="Hora de venta"><?=safe(fmtHoraArg($r['hora_local']))?></td>
            <td data-label="Monto" style="text-align:right;">$ <?=number_format((int)$r['precio'],0,',','.')?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

    <div class="count-note">Mostrando <?=count($rows)?> registros</div>
  </div>
</main>
<?php endif; ?>
<script>
  document.addEventListener('DOMContentLoaded', ()=>{
    const cont = document.querySelector('.table-container');
    if(cont) cont.scrollLeft = 0; // fuerza scroll al inicio
  });
</script>

<script>
// ===== Reloj ARG + etiqueta de turnos
(function(){
  const el = document.getElementById('arg-clock');
  function tick(){
    try{
      el.textContent = new Intl.DateTimeFormat('es-AR',{timeZone:'America/Argentina/Buenos_Aires',hour12:false,hour:'2-digit',minute:'2-digit',second:'2-digit'}).format(new Date());
    }catch(e){
      const now = new Date(Date.now() - (new Date().getTimezoneOffset()*60000) - (3*3600*1000));
      el.textContent = `${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}:${String(now.getSeconds()).padStart(2,'0')}`;
    }
  }
  tick(); setInterval(tick,1000);
})();

function nowArgParts(){
  const parts = new Intl.DateTimeFormat('en-CA',{timeZone:'America/Argentina/Buenos_Aires',weekday:'short',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).formatToParts(new Date());
  const map={}; parts.forEach(p=>map[p.type]=p.value);
  const dowMap={Sun:0,Mon:1,Tue:2,Wed:3,Thu:4,Fri:5,Sat:6};
  return { dow:dowMap[map.weekday], hour:parseInt(map.hour,10) };
}
function turnoBlockHoursJS(){
  const n=nowArgParts();
  return ((n.dow===5 && n.hour>=8) || n.dow===6 || n.dow===0) ? 2 : 3;
} // Vie 8:00 → Dom 23:59 = 2h
function isNightAllowedNowJS(){ const n=nowArgParts(); return (n.hour>=21 || n.hour<10); }

function updateTurnoLabel(){
  const el = document.getElementById('turno-label');
  if(!el) return;
  el.textContent = `Turnos de ${turnoBlockHoursJS()} h`;
}
setInterval(updateTurnoLabel, 60000); updateTurnoLabel();

// ===== Click en tarjetas (flujo por estado)
document.addEventListener('click', async (e)=>{
  const card = e.target.closest('.room-card');
  if(!card) return;
  const id   = parseInt(card.dataset.id,10);
  const estado =
    card.classList.contains('reservada') ? 'reservada' :
    card.classList.contains('libre') ? 'libre' :
    card.classList.contains('limpieza') ? 'limpieza' :
    card.classList.contains('vencida') ? 'vencida' :
    'ocupada';

  if(estado==='libre'){
    const { value: mode } = await Swal.fire({
      title: `Hab. ${id} — Disponible`,
      text: '¿Qué querés hacer?',
      input: 'select',
      inputOptions: { 'turno': `Turno (+${turnoBlockHoursJS()} h)`, 'noche': 'Noche (21–10)' },
      inputPlaceholder: 'Seleccioná opción',
      showCancelButton: true,
      confirmButtonText: 'Aceptar',
      cancelButtonText: 'Cancelar'
    });
    if(!mode) return;

    if(mode==='noche'){
      if(!isNightAllowedNowJS()){
        Swal.fire({icon:'error',title:'Fuera de horario',text:'El turno noche solo puede reservarse entre las 21:00 y las 10:00.'});
        return;
      }
      await ocuparNoche([id]);
    }else{
      await ocuparTurno([id]);
    }
    return;
  }

  if(estado==='reservada'){
    try {
      const res = await fetch(`obtener-codigo.php?id=${id}`);
      let j = {}; try { j = await res.json(); } catch(e) {}
      const codigo = j.codigo || '—';
const tipo = card.dataset.tipo || '';
      const { value: action } = await Swal.fire({
        title: `Hab. ${id} — Reservada`,
        html: `<p style="font-weight:700;margin-bottom:6px">Código de reserva: <b>${codigo}</b></p>
               <p>¿Qué deseás hacer?</p>`,
        input: 'radio',
        inputOptions: {
          'aceptar':'Aceptar reserva (ocupar ahora)',
          'cancelar':'Cancelar reserva (liberar)',
          'mover':'Mover de habitación (misma categoría)'
        },
        inputValidator: v => !v && 'Elegí una opción',
        confirmButtonText:'Aceptar',
        cancelButtonText:'Cancelar',
        showCancelButton:true
      });
      if(!action) return;
if(action==='mover'){
        const libresMismoTipo = Array.from(document.querySelectorAll('.room-card.libre'))
          .filter(r => (r.dataset.tipo || '') === tipo)
          .sort((a,b)=>parseInt(a.dataset.id,10)-parseInt(b.dataset.id,10));

        if(!libresMismoTipo.length){
          Swal.fire({icon:'info',title:'Sin habitaciones disponibles',text:'No hay habitaciones libres de la misma categoría.'});
          return;
        }

        const opciones = {};
        libresMismoTipo.forEach(r => { opciones[r.dataset.id] = `Hab. ${r.dataset.id}`; });

        const { value: nuevaHab } = await Swal.fire({
          title:`Mover reserva (Hab. ${id})`,
          input:'select',
          inputOptions: opciones,
          inputPlaceholder:'Elegí habitación libre',
          showCancelButton:true,
          confirmButtonText:'Mover'
        });

        if(!nuevaHab) return;

        const mov = await moverReserva(id, parseInt(nuevaHab,10));
        if(mov.ok){
          Swal.fire({icon:'success',title:'Reserva movida',text:`Asignada a la habitación ${nuevaHab}.`});
        } else {
          Swal.fire({icon:'error',title:'No se pudo mover',text: mov.error || 'Intentalo de nuevo.'});
        }
        return;
      }

      if(action==='aceptar'){
        await setEstado([id],'ocupada');
        Swal.fire({icon:'success',title:'Reserva aceptada',text:'Habitación ocupada.'});
      } else {
        await setEstado([id],'libre');
        Swal.fire({icon:'info',title:'Reserva cancelada',text:'Habitación liberada.'});
      }
    } catch(e) { console.error(e); }
    return;
  }
  
if(estado==='vencida'){
    const { value: action } = await Swal.fire({
      title:`Hab. ${id} — Tiempo vencido`,
      input:'radio',
      inputOptions:{
        limpieza:'Mandar a Limpieza',
        reactivar:'Reactivar con turno extra'
      },
      inputValidator: v => !v && 'Elegí una opción',
      showCancelButton:true,
      confirmButtonText:'Aceptar'
    });

    if(action==='limpieza'){
      await setEstado([id],'limpieza');
      Swal.fire({icon:'success',title:'En limpieza'});
    } else if(action==='reactivar'){
      const ok = await reactivarExtra(id);
      if(ok){
        Swal.fire({icon:'success',title:'Reactivada',text:`+${turnoBlockHoursJS()} h agregadas`});
      } else {
        Swal.fire({icon:'error',title:'No se pudo reactivar'});
      }
    }
    return;
  }

  if(estado==='ocupada'){
    const { value: action } = await Swal.fire({
      title: `Hab. ${id} — Ocupada`,
      input: 'radio',
      inputOptions: { 'agregar':'Agregar otro turno', 'liberar':'Liberar (pasa a Limpieza)' },
      inputValidator: v => !v && 'Elegí una opción',
      confirmButtonText:'Aceptar',
      cancelButtonText:'Cancelar',
      showCancelButton:true
    });
    if(!action) return;

    if(action==='agregar'){
      const ok = await ocuparTurno([id], true);
      if(ok){ Swal.fire({icon:'success',title:'Turno agregado',text:`+${turnoBlockHoursJS()} h`}); }
      else { Swal.fire({icon:'warning',title:'No se pudo agregar',text:'Verificá si tiene Noche activa.'}); }
    }else{
      await setEstado([id],'limpieza');
      Swal.fire({icon:'success',title:'Liberado',text:'Quedó en Limpieza.'});
    }
    return;
  }

  if(estado==='limpieza'){
    const { value: action } = await Swal.fire({
      title:`Hab. ${id} — Limpieza`,
      input:'radio',
      inputOptions:{
        disponible:'Marcar como Disponible'
      },
      inputValidator: v => !v && 'Elegí una opción',
      showCancelButton:true,
      confirmButtonText:'Aceptar'
    });
    if(action==='disponible'){
      await setEstado([id],'libre');
      Swal.fire({icon:'success',title:'Disponible'});
    
    }
    return;
  }
});

// ===== AJAX helpers
async function setEstado(ids, estado){
  try{
    const res = await fetch(location.href, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({accion:'set_estado',ids:ids.join(','),estado})
    });
    await res.text();
    await pollUpdates(true);
    return true;
  }catch(e){ await pollUpdates(true); return true; }
}
async function moverReserva(desde, hasta){
  try{
    const res = await fetch(location.href, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({accion:'mover_reserva',desde,hasta})
    });
    const txt = await res.text(); let j={}; try{ j=JSON.parse(txt);}catch(_){}
    if(j && j.success===true){ await pollUpdates(true); return {ok:true}; }
    await pollUpdates(true);
    return {ok:false, error:(j && j.error)||'No se pudo mover la reserva'};
  }catch(e){ await pollUpdates(true); return {ok:false, error:'No se pudo mover la reserva'}; }
}
async function reactivarExtra(id){
  try{
    const res = await fetch(location.href, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({accion:'reactivar_extra',id})
    });
    const txt = await res.text(); let j={}; try{ j=JSON.parse(txt);}catch(_){}
    if(j && j.success===true){ await pollUpdates(true); return true; }
    await pollUpdates(true); return false;
  }catch(e){ await pollUpdates(true); return false; }
}
async function ocuparTurno(ids, silentWarn=false){
  try{
    const res = await fetch(location.href, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({accion:'ocupar_turno',ids:ids.join(',')})
    });
    const txt = await res.text(); let j={}; try{ j=JSON.parse(txt);}catch(_){}
    if(j && j.success===true){ await pollUpdates(true); return true; }
    if(!silentWarn) Swal.fire({icon:'warning',title:'No se pudo ocupar',text:(j && j.error)||'Verificá la habitación.'});
    await pollUpdates(true); return false;
  }catch(e){ await pollUpdates(true); return true; }
}
async function ocuparNoche(ids){
  try{
    const res = await fetch(location.href, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({accion:'ocupar_noche',ids:ids.join(',')})
    });
    const txt = await res.text(); let j={}; try{ j=JSON.parse(txt);}catch(_){}
    if(j && j.success===true){ await pollUpdates(true); return true; }
    Swal.fire({icon:'warning',title:'No se pudo iniciar Noche',text:(j && j.error)||'Verificá la habitación.'});
    await pollUpdates(true); return false;
  }catch(e){ await pollUpdates(true); return true; }
}

// ===== Timers con alerta <15m y vencida azul
const timers={};
function startTimer(id, seconds){
  const el=document.getElementById('timer-'+id);
  const card=document.getElementById('hab-'+id);
  if(!el||!card) return;
  clearTimer(id);

  let t=parseInt(seconds,10);
   if(Number.isNaN(t)) t=0;

  const tick=()=>{
    const isNegative = t < 0;

    if(card.classList.contains('ocupada') || card.classList.contains('reservada')){
      if(!isNegative && t<=900){ card.classList.add('alerta-tiempo'); }
      else { card.classList.remove('alerta-tiempo'); }
      if(t<=0){ card.classList.add('vencida'); }
      else { card.classList.remove('vencida'); }
    }else{
       card.classList.remove('alerta-tiempo','vencida');
    }

    renderTime(el,t);
  t--;
  };

  tick();
  timers[id]=setInterval(tick,1000);
}
function clearTimer(id){ if(timers[id]){ clearInterval(timers[id]); delete timers[id]; } }
function renderTime(el,t){
    const sign = t < 0 ? '-' : '';
  const abs = Math.abs(t);
  const h=Math.floor(abs/3600), m=Math.floor((abs%3600)/60);
  el.textContent = sign + h+':'+String(m).padStart(2,'0');
}

// ===== Polling 2s
let MOBILE_BUILT = false;

// ===== Alertas de pedidos de minibar
let minibarInterval = null;
let minibarBusy = false;
let minibarQueue = [];
let minibarActiveId = null;

async function fetchMinibarAlerts(){
  if(minibarBusy) return;
  minibarBusy = true;
  try{
    const r = await fetch('?ajax_minibar_alerts=1',{cache:'no-store'});
    const j = await r.json();
     const pedidos = Array.isArray(j.pedidos) ? j.pedidos : [];
    const pendingIds = new Set(pedidos.map(p=>p.id));

      // Cerrar modales abiertos si otro dispositivo ya los atendió
    if(minibarActiveId && !pendingIds.has(minibarActiveId) && Swal.isVisible()){
      Swal.close();
      minibarActiveId = null;
    }

    // Mantener la cola solo con pendientes
    minibarQueue = minibarQueue.filter(p=>pendingIds.has(p.id));

    // Agregar nuevos pendientes a la cola, evitando duplicados
    for(const p of pedidos){
      if(p.id === minibarActiveId) continue;
      if(!minibarQueue.some(q=>q.id === p.id)) minibarQueue.push(p);
    }
  }catch(e){
    console.error(e);
  }
  minibarBusy = false;
  processMinibarQueue();
}

async function processMinibarQueue(){
  if(minibarActiveId || !minibarQueue.length) return;

  const p = minibarQueue.shift();
  minibarActiveId = p.id;

  const items = Array.isArray(p.items) ? p.items : [];
  const html = items.length
    ? items.map(it=>`${it.cantidad} × ${it.nombre}`).join('<br>')
    : 'Pedido sin detalle';
  const totalTxt = (p.total||0).toLocaleString('es-AR');

  try{
    const res = await Swal.fire({
      title:`Hab. ${p.habitacion} — Pedido pago`,
      html:`${html}<br><br><b>Total abonado:</b> $${totalTxt}`,
      icon:'info',
      confirmButtonText:'Aceptar y llevar',
      cancelButtonText:'Más tarde',
      showCancelButton:true
    });

    if(res.isConfirmed){
      await fetch('',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({accion:'ack_minibar', id:p.id})
      });
    }
  }catch(e){
    console.error(e);
  }finally{
    minibarActiveId = null;
    processMinibarQueue();
  }
}

function startMinibarAlerts(){
  fetchMinibarAlerts();
  if(minibarInterval) clearInterval(minibarInterval);
  minibarInterval = setInterval(fetchMinibarAlerts, 5000);
}
// ===== Alertas de pagos online de turnos
let pagosInterval = null;
let pagosBusy = false;
let pagosQueue = [];
let pagoActiveId = null;

async function fetchPagosOnline(){
  if(pagosBusy) return;
  pagosBusy = true;
  try{
    const r = await fetch('?ajax_pagos_online=1',{cache:'no-store'});
    const j = await r.json();
    const pagos = Array.isArray(j.pagos) ? j.pagos : [];
    const pendingIds = new Set(pagos.map(p=>p.id));

     if(pagoActiveId && !pendingIds.has(pagoActiveId) && Swal.isVisible()){
      Swal.close();
      pagoActiveId = null;
    }

    pagosQueue = pagosQueue.filter(p=>pendingIds.has(p.id));

    for(const p of pagos){
      if(p.id === pagoActiveId) continue;
      if(!pagosQueue.some(q=>q.id === p.id)) pagosQueue.push(p);
    }
  }catch(e){ console.error(e); }
  pagosBusy = false;
   processPagosQueue();
}

async function processPagosQueue(){
  if(pagoActiveId || !pagosQueue.length) return;

  const p = pagosQueue.shift();
  pagoActiveId = p.id;

  const bloquesTxt = (p.bloques||1) > 1 ? `${p.bloques} turnos` : '1 turno';
  const totalTxt = (p.monto||0).toLocaleString('es-AR');
  const esEnvio = (p.turno||'') === 'envio-dinero';

  try{
    const res = await Swal.fire({
       title: esEnvio ? `Hab. ${p.habitacion} envió dinero digital` : `Hab. ${p.habitacion} pagó online`,
      html: esEnvio
        ? `Transferencia registrada<br><b>Total:</b> $${totalTxt}`
        : `${p.turno || 'turno'}<br>${bloquesTxt}<br><b>Total:</b> $${totalTxt}`,
      icon:'info',
      confirmButtonText:'Aceptar (borrar registro previo)',
      cancelButtonText:'Más tarde',
      showCancelButton:true
    });

    if(res.isConfirmed){
      await fetch('',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({accion:'ack_pago_online', id:p.id})
      });
    }
  }catch(e){
    console.error(e);
  }finally{
    pagoActiveId = null;
    processPagosQueue();
  }
}

function startPagoOnlineAlerts(){
  fetchPagosOnline();
  if(pagosInterval) clearInterval(pagosInterval);
  pagosInterval = setInterval(fetchPagosOnline, 5000);
}
async function pollUpdates(force=false){
  try{
    const r = await fetch('?ajax=1', {cache:'no-store'});
    if(!r.ok) return;

    const html = await r.text();
    const doc  = new DOMParser().parseFromString(html, 'text/html');
    const newCards = doc.querySelectorAll('.room-card');

    newCards.forEach(newCard=>{
      const id  = newCard.id;
      const cur = document.getElementById(id);
      if(!cur) return;

      /* ==============================
         🔥 FIX 1 — NO reventar className
         ============================== */
      const allowed = ['libre','ocupada','reservada','limpieza','super'];
      cur.classList.remove('libre','ocupada','reservada','limpieza','vencida','alerta-tiempo','super');

      newCard.classList.forEach(c=>{
        if (allowed.includes(c)) cur.classList.add(c);
      });

      /* ==============================
         🔥 FIX 2 — atributos actuales
         ============================== */
      cur.setAttribute('data-restante', newCard.getAttribute('data-restante') || '0');
      cur.setAttribute('data-tipo', newCard.getAttribute('data-tipo') || '');

      /* ==============================
         🔥 FIX 3 — actualizar textos
         ============================== */
      cur.querySelector('.state-line').textContent =
          newCard.querySelector('.state-line').textContent;

      cur.querySelector('.timer').textContent =
          newCard.querySelector('.timer').textContent;

      /* ==============================
         🔥 FIX 4 — ID numérico REAL
         ============================== */
      const numericId = parseInt(cur.getAttribute('data-id'), 10);

      const rest = parseInt(cur.getAttribute('data-restante')||'0',10);

      clearTimer(numericId);

      if (cur.classList.contains('ocupada') || cur.classList.contains('reservada')) {
          startTimer(numericId, rest);
      }
      
      else {
          cur.classList.remove('vencida','alerta-tiempo');
      }
    });

    buildMobileGrid();
  }catch(e){
    // silencio
  }
}


// ===== Orden móvil: izquierda 21→40, derecha 20→1
function buildMobileGrid(){
  const wrap = document.getElementById('mobile-grid');
  if(!wrap) return;

  const isMobile = window.matchMedia('(max-width: 900px)').matches;
  if(!isMobile){ MOBILE_BUILT = false; return; }
  if(MOBILE_BUILT) return;

  const left = [];  for(let i=21;i<=40;i++) left.push(i);
  const right = []; for(let i=20;i>=1;i--) right.push(i);

  wrap.innerHTML='';
  const colL = document.createElement('div');
  const colR = document.createElement('div');
  colL.style.display=colR.style.display='flex';
  colL.style.flexDirection=colR.style.flexDirection='column';
  colL.style.gap=colR.style.gap='6px';

  left.forEach(id=>{ const el=document.getElementById('hab-'+id); if(el) colL.appendChild(el.parentElement); });
  right.forEach(id=>{ const el=document.getElementById('hab-'+id); if(el) colR.appendChild(el.parentElement); });

  wrap.appendChild(colL);
  wrap.appendChild(colR);

  MOBILE_BUILT = true;
}

// ===== Registros: export CSV (POST)
function exportCSV(){
  const url=new URL(location.href);
  const desde=url.searchParams.get('desde')||'';
  const hasta=url.searchParams.get('hasta')||'';
  const tipo =url.searchParams.get('tipo') ||'todos';
  const f=document.createElement('form'); f.method='POST'; f.action=location.href;
  f.innerHTML=`<input type="hidden" name="accion" value="export_csv">
               <input type="hidden" name="desde" value="${desde}">
               <input type="hidden" name="hasta" value="${hasta}">
               <input type="hidden" name="tipo"  value="${tipo}">`;
  document.body.appendChild(f); f.submit();
}

// ===== Init
let GLOBAL_INTERVAL = null;

document.addEventListener('DOMContentLoaded', ()=>{

  updateTurnoLabel();

  // Limpia cualquier intervalo previo (si cambiaste de vista)
  if (GLOBAL_INTERVAL) {
    clearInterval(GLOBAL_INTERVAL);
    GLOBAL_INTERVAL = null;
  }

  /* =======================
     🔥 MODO PANEL
     =======================*/
  const isPanel = document.getElementById('panel');
  if (isPanel) {

    // iniciar timers iniciales
    document.querySelectorAll('.room-card').forEach(card=>{
      const id = card.dataset.id;
      const rest = parseInt(card.getAttribute('data-restante')||'0',10);

      if (card.classList.contains('ocupada') || card.classList.contains('reservada')) {
        startTimer(id, rest);
      }
      
    });

    buildMobileGrid();

    // solo un polling
    GLOBAL_INTERVAL = setInterval(() => {
      pollUpdates();
    }, 2000);
    
startMinibarAlerts();
startPagoOnlineAlerts();

    return;
  }


  /* =======================
     🔥 MODO MINIBAR
     =======================*/
  const isMinibar = location.search.includes('view=minibar');
  if (isMinibar) {

    // solo cargar inventario
    GLOBAL_INTERVAL = setInterval(() => {
      cargarInventario();
    }, 5000);

    cargarInventario();

    return;
  }


  /* =======================
     🔥 MODO REGISTROS
     =======================*/
  const isReg = location.search.includes('view=registros');
  if (isReg) {
    // Nada de timers ni polling
    return;
  }

});

</script>

<!-- PANEL DE INVENTARIO -->
<div class="inventario-wrapper">
  <div id="box-inventario" class="inventario-panel collapsed">
    <div id="inv-header" class="inventario-header">
      <div class="inv-title">🧃 Inventario</div>
      <button id="inv-toggle" class="inv-toggle" aria-label="Minimizar o expandir inventario">▾</button>
    </div>
    <div id="inv-body" class="inventario-body">
      <table id="inv-table" class="inventario-table">
        <tbody></tbody>
      </table>
    </div>
  </div>
  
<!-- BOTÓN FLOTANTE MOBILE -->
  <button id="btn-inventario" class="inventario-btn" title="Inventario" aria-label="Abrir inventario">
    <span class="icon">🧃</span>
    <span class="label">Inventario</span>
  </button>
</div>

<style>
.inventario-wrapper {
  position: fixed;
  bottom: 14px;
  left: 14px;
  z-index: 1000;
  font-family: 'Inter', system-ui, -apple-system, sans-serif;
}

/* Botón flotante oculto en escritorio */
.inventario-btn {
  display: none;
  align-items: center;
  gap: 8px;
  border: none;
  background: linear-gradient(135deg, #2563eb, #0ea5e9);
  color: #fff;
  padding: 12px 16px;
  border-radius: 999px;
  box-shadow: 0 10px 25px rgba(37, 99, 235, 0.4);
  font-weight: 600;
  cursor: pointer;
  transition: transform 0.18s ease, box-shadow 0.18s ease;
}
.inventario-btn .icon {
  font-size: 20px;
}

.inventario-btn:hover { transform: translateY(-1px); }
.inventario-btn:active { transform: translateY(0); box-shadow: 0 8px 18px rgba(37,99,235,0.35); }

/* Panel base (escritorio) */
.inventario-panel {
  width: 320px;
  background: linear-gradient(180deg, #0f172a 0%, #111827 60%, #0f172a 100%);
  color: #e5e7eb;
  border-radius: 14px;
  box-shadow: 0 15px 35px rgba(0,0,0,0.35);
  border: 1px solid rgba(148, 163, 184, 0.35);
  overflow: hidden;
  backdrop-filter: blur(6px);
}

.inventario-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 14px;
  cursor: pointer;
  user-select: none;
  background: linear-gradient(90deg, rgba(59,130,246,0.25), rgba(14,165,233,0.15));
}

.inv-title {
  font-weight: 700;
  letter-spacing: 0.2px;
}

.inv-toggle {
  border: none;
  background: rgba(255,255,255,0.07);
  color: #e5e7eb;
  width: 28px;
  height: 28px;
  border-radius: 8px;
  cursor: pointer;
  transition: transform 0.18s ease, background 0.18s ease;
}

.inv-toggle:hover { background: rgba(255,255,255,0.12); }
.inventario-panel.collapsed .inv-toggle { transform: rotate(-90deg); }

.inventario-body {
  max-height: 360px;
  overflow-y: auto;
  background: rgba(255,255,255,0.02);
  display: none;
}

.inventario-panel:not(.collapsed) .inventario-body { display: block; }

.inventario-table {
  width: 100%;
  border-collapse: collapse;
}

.inventario-table tr {
  border-bottom: 1px solid rgba(255,255,255,0.06);
}

.inventario-table td {
  padding: 12px 14px;
  vertical-align: middle;
}

.inv-name { font-weight: 600; color: #fff; }
.inv-meta { color: #cbd5e1; font-size: 12px; margin-top: 4px; display: flex; gap: 8px; align-items: center; }
.inv-pill { background: rgba(59,130,246,0.18); color: #bfdbfe; padding: 2px 8px; border-radius: 999px; font-weight: 700; font-size: 12px; }
.inv-price { font-weight: 700; color: #22c55e; text-align: right; }
.inv-actions { text-align: right; }
.inv-sell {
  margin-top: 6px;
  background: linear-gradient(135deg, #22c55e, #16a34a);
  border: none;
  color: #fff;
  padding: 8px 10px;
  border-radius: 10px;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 8px 18px rgba(34,197,94,0.35);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.inv-sell:hover { transform: translateY(-1px); box-shadow: 0 10px 22px rgba(34,197,94,0.45); }
.inv-sell:active { transform: translateY(0); box-shadow: 0 6px 14px rgba(34,197,94,0.35); }

/* Mobile: una sola burbuja y panel moderno */
@media (max-width: 768px) {
  .inventario-wrapper {
    left: 12px;
    bottom: 12px;
  }

  
  .inventario-btn {
    display: inline-flex;
  }

  .inventario-panel {
    display: none;
    width: calc(100vw - 32px);
    max-width: 440px;
    position: fixed;
    bottom: 92px;
    left: 16px;
    right: 16px;
  }
.inventario-panel.visible { display: block; }
  .inventario-panel.collapsed .inventario-body { display: none; }

  .inventario-header { padding: 14px 16px; }
  .inventario-table td { padding: 12px 16px; }
}

</style>

<script>
const invPanel   = document.getElementById('box-inventario');
const invHeader  = document.getElementById('inv-header');
const invBody    = document.getElementById('inv-body');
const invToggle  = document.getElementById('inv-toggle');
const btnInv     = document.getElementById('btn-inventario');

function toggleCollapse(){
  invPanel.classList.toggle('collapsed');
}

function toggleMobilePanel(){
  const isVisible = invPanel.classList.contains('visible');
  invPanel.classList.toggle('visible', !isVisible);
  invPanel.classList.remove('collapsed');
}

invHeader.addEventListener('click', toggleCollapse);
invToggle.addEventListener('click', (e)=>{ e.stopPropagation(); toggleCollapse(); });
btnInv.addEventListener('click', toggleMobilePanel);

async function cargarInventario(){
  try {
    const r = await fetch('?ajax_inv=1', {cache:'no-store'});
    const j = await r.json();
    const tb = document.querySelector('#inv-table tbody');

    if(j.ok && Array.isArray(j.items)){
      tb.innerHTML = j.items.map(p=>`
        <tr>
           <td>
            <div class="inv-name">${p.nombre}</div>
            <div class="inv-meta">
              <span>Stock</span>
              <span class="inv-pill">${p.cantidad}</span>
            </div>
          </td>
          <td class="inv-actions">
            <div class="inv-price">$${p.precio}</div>
            <button class="inv-sell" onclick="venderProd(${p.id})">Vender</button>
          </td>
        </tr>
      `).join('');
    } else {
      tb.innerHTML = '<tr><td colspan="2" style="padding:14px;text-align:center;color:#cbd5e1;">Sin productos disponibles</td></tr>';
    }

  } catch(e) {
    console.error(e);
  }
}

async function venderProd(id){

  
  const conf = await Swal.fire({
    title: "¿Confirmar venta?",
    text: "Se descontará 1 unidad del stock.",
    icon: "question",
    showCancelButton: true,
    confirmButtonText: "Sí, vender",
    cancelButtonText: "Cancelar"
  });

  if (!conf.isConfirmed) return; // ❌ si cancela, no vende

 
  const r = await fetch('', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'accion=vender_producto&id='+id
  });

  const j = await r.json();
  if(!j.ok){
    Swal.fire({
      icon: 'warning',
      title: 'Sin stock',
      text: j.msg || 'No hay stock disponible para vender'
    });
    return;
  }
  
  cargarInventario();
  actualizarTurnoBox();
}

setInterval(cargarInventario, 5000);
cargarInventario();
</script>
<script>
document.addEventListener('DOMContentLoaded', ()=>{
  if(window.innerWidth > 768) return; // solo móvil

  const table = document.querySelector('table');
  if(!table) return;

  const rows = table.querySelectorAll('tbody tr');
  if(!rows.length) return;

  const isMinibar = location.search.includes('view=minibar');
  const cardsContainer = document.createElement('div');
  cardsContainer.className = 'mobile-cards';

  rows.forEach(row=>{
    const cells = row.querySelectorAll('td');
    if(!cells.length) return;

    let summaryHTML = '';
    let durClass = '';

    if (isMinibar) {
      // 🧃 Formato MINIBAR
      const prod = cells[1]?.innerText.trim() || '-';
      const hora = cells[2]?.innerText.trim() || '-';
      const monto = cells[3]?.innerText.trim() || '$0';
      summaryHTML = `<span>Prod: ${prod}</span><span>Hora: ${hora}</span><span>Mto: ${monto}</span>`;
    } else {
      // 🛏️ Formato HABITACIONES
      const hab = cells[0]?.innerText.trim() || '-';
      const tipoTurno = cells[2]?.innerText.trim() || '';
      const dur = parseInt(cells[8]?.innerText.trim() || '0',10);
      const monto = cells[9]?.innerText.trim() || '$0';
      const horaFinTxt = cells[7]?.innerText.trim() || '';

      if (tipoTurno.includes('turno-2h') && dur > 135) durClass = 'duracion-larga';
      else if (tipoTurno.includes('turno-3h') && dur > 195) durClass = 'duracion-larga';
      else if ((tipoTurno.includes('noche') || tipoTurno.includes('noche-finde')) && horaFinTxt) {
        const [h,m] = horaFinTxt.split(':').map(x=>parseInt(x,10));
        if (h > 10 || (h === 10 && m > 15)) durClass = 'duracion-larga';
      }

      summaryHTML = `<span>Hab: ${hab}</span><span class="${durClass}">Dur: ${dur}</span><span>Mto: ${monto}</span>`;
    }

    // Crear tarjeta
    const card = document.createElement('div');
    card.className = 'mobile-card';
    card.innerHTML = `
      <div class="mobile-summary">${summaryHTML}</div>
      <div class="mobile-details">
        ${Array.from(cells).map((c,i)=>{
          const th = table.querySelector('thead th:nth-child('+(i+1)+')');
          const label = th ? th.innerText : '';
          return `<div><b>${label}:</b> ${c.innerText}</div>`;
        }).join('')}
      </div>
    `;

    card.querySelector('.mobile-summary').addEventListener('click',()=>{
      const d = card.querySelector('.mobile-details');
      d.classList.toggle('active');
    });

    cardsContainer.appendChild(card);
  });

  table.style.display = 'none';
  table.parentNode.insertBefore(cardsContainer, table.nextSibling);
});

</script>
</body>
</html>