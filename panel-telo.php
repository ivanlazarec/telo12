<?php
// panel-nuevo.php — Panel limpio + precios + reportes + inventario + mensajes
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
/* ================= Mensajes internos (fetch) ================= */
if ($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['ajax_mensajes'])) {
  $res = $conn->query("
    SELECT id, nombre, mensaje, created_at
    FROM mensajes_internos
    WHERE estado='pendiente'
      AND (snooze_until IS NULL OR snooze_until <= UTC_TIMESTAMP())
    ORDER BY created_at ASC");
  $items = [];
  while($r = $res->fetch_assoc()){
    $items[] = [
      'id' => (int)$r['id'],
      'nombre' => $r['nombre'] ?? '',
      'mensaje' => $r['mensaje'] ?? '',
      'hora' => fmtHoraArg($r['created_at'] ?? ''),
      'created_at' => $r['created_at'] ?? ''
    ];
  }
  echo json_encode(['ok'=>1,'mensajes'=>$items]);
  exit;
}


/*===================== Tablas mínimas ==================*/
$conn->query("CREATE TABLE IF NOT EXISTS habitaciones (
  id INT PRIMARY KEY,
  estado VARCHAR(20) DEFAULT 'libre',   -- libre | ocupada | limpieza | reservada
  tipo_turno VARCHAR(20) NULL,
  hora_inicio DATETIME NULL,
  codigo_reserva VARCHAR(10) NULL
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
@$conn->query("ALTER TABLE habitaciones ADD COLUMN IF NOT EXISTS codigo_reserva VARCHAR(10) NULL");
@$conn->query("ALTER TABLE mensajes_internos ADD COLUMN IF NOT EXISTS snooze_until DATETIME NULL");

/* ====== ingresos digitales / pagos online ====== */
$conn->query("
CREATE TABLE IF NOT EXISTS digital_ingresos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo VARCHAR(20) NOT NULL,
  habitacion INT NULL,
  categoria VARCHAR(20) NULL,
  monto DECIMAL(10,2) NOT NULL,
  descripcion VARCHAR(255) NULL,
  codigo VARCHAR(10) NULL,
  turno VARCHAR(20) NULL,
  bloques INT NOT NULL DEFAULT 1,
  referencia VARCHAR(80) NULL,
  created_at DATETIME NOT NULL,
  INDEX(habitacion), INDEX(tipo), INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$conn->query("
CREATE TABLE IF NOT EXISTS pagos_online (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo VARCHAR(20) NOT NULL,
  payload TEXT NULL,
  pref_id VARCHAR(80) NOT NULL,
  token VARCHAR(80) NOT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
  monto DECIMAL(10,2) NOT NULL DEFAULT 0,
  habitacion INT NULL,
  categoria VARCHAR(20) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY token_unique (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
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

/* ====== Marcadores de cobro de movimientos ====== */
$conn->query("
CREATE TABLE IF NOT EXISTS cobros_movimientos (
  id INT NOT NULL,
  tipo VARCHAR(10) NOT NULL, -- hab | venta
  cobrado TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ====== Pedidos de minibar ====== */



/* ====== Mensajes internos ====== */
$conn->query("
CREATE TABLE IF NOT EXISTS mensajes_internos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  mensaje TEXT NOT NULL,
  estado ENUM('pendiente','leido') NOT NULL DEFAULT 'pendiente',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  snooze_until DATETIME NULL,
  INDEX (estado),
  INDEX (created_at)
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
function toArgTs($utc){
  if(!$utc) return null;
  $dtUtc = new DateTime($utc, new DateTimeZone('UTC'));
  $dtUtc->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
  return $dtUtc->getTimestamp();
}
function argDateFromTs($ts){
  $dt = new DateTime('@'.$ts);
  $dt->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
  return $dt->format('Y-m-d');
}
function turnoEndArgTs($turno,$startArgTs,$bloques=1){
  $bloques = max(1, (int)$bloques);
  if($turno==='turno-2h'){ return $startArgTs + (2 * 3600 * $bloques); }
  if($turno==='turno-3h'){ return $startArgTs + (3 * 3600 * $bloques); }
  if(in_array($turno, ['noche','noche-finde','noche-find'], true)){
    return nightEndTsFromStartArg($startArgTs);
  }
  return $startArgTs;
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

function turnoLabelCorto($turno){
  if($turno==='turno-2h') return 'Turno 2h';
  if($turno==='turno-3h') return 'Turno 3h';
  if($turno==='noche') return 'Noche';
  if($turno==='noche-finde' || $turno==='noche-find') return 'Noche finde';
  return ucfirst($turno);
}
function proximaExtra($conn,$habitacion,$horaInicio){
  $st = $conn->prepare("SELECT turno, hora_inicio FROM historial_habitaciones WHERE habitacion=? AND hora_inicio > ? AND es_extra=1 ORDER BY hora_inicio ASC LIMIT 1");
  $st->bind_param('is',$habitacion,$horaInicio);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  if(!$row){ return [null,null]; }
  return [toArgTs($row['hora_inicio']), $row['turno'] ?? null];
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
    $st = $conn->prepare("SELECT estado, codigo_reserva FROM habitaciones WHERE id=? LIMIT 1");
    $st->bind_param('i', $id);
    $st->execute();
    $res = $st->get_result();
    $row = $res->fetch_assoc();
    $st->close();

    $estado = $row['estado'] ?? 'libre';
    $codigo = trim($row['codigo_reserva'] ?? '');
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
        . ' data-tipo="' . htmlspecialchars($tipo) . '"'
        . ' data-codigo="' . htmlspecialchars($codigo) . '">';
    echo '    <div class="state-line">' . htmlspecialchars($estadoTxt) . '</div>';
    echo '    <div class="timer" id="timer-' . $id . '">' . htmlspecialchars($timer) . '</div>';
    echo '    <div class="room-num">' . $id . '</div>';
    if($codigo && ($estado === 'reservada' || $estado === 'ocupada')){
      echo '    <div class="room-code">#' . htmlspecialchars($codigo) . '</div>';
    }
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

  $totalHab = (int)($resHab['total'] ?? 0);
  $cantHab  = (int)($resHab['cant'] ?? 0);
  $totalVen = (int)($resVen['total'] ?? 0);

  $totalCaja = $totalHab + $totalVen;

  return [ $totalCaja, $cantHab ];
}

function cajaDetalleDesdeHasta($conn,$inicioUTC,$finUTC=null,$limit=150){
    $col = 'utf8mb4_unicode_ci';
  $sql = "
    (SELECT
        h.id,
        h.habitacion,
        CONVERT(h.turno USING utf8mb4) COLLATE $col AS turno,
        h.hora_inicio,
        h.precio_aplicado AS monto,
        'hab' COLLATE $col AS tipo,
        NULL AS nombre,
        COALESCE(cm.cobrado, 0) AS cobrado
     FROM historial_habitaciones h
     LEFT JOIN cobros_movimientos cm ON cm.id = h.id AND cm.tipo = 'hab'
     WHERE h.hora_inicio >= ? AND h.codigo IS NULL".($finUTC?" AND h.hora_inicio < ?":"").")
    UNION ALL
    (SELECT
        v.id,
        NULL AS habitacion,
        CONVERT(v.turno USING utf8mb4) COLLATE $col AS turno,
        v.hora AS hora_inicio,
        v.precio AS monto,
        'venta' COLLATE $col AS tipo,
         CONVERT(v.nombre USING utf8mb4) COLLATE $col AS nombre,
        COALESCE(cm.cobrado, 0) AS cobrado
     FROM ventas_turno v
     LEFT JOIN cobros_movimientos cm ON cm.id = v.id AND cm.tipo = 'venta'
     WHERE v.hora >= ?".($finUTC?" AND v.hora < ?":"").")
    ORDER BY hora_inicio ASC
    LIMIT $limit
  ";

  $st = $conn->prepare($sql);
  if($finUTC){
    $st->bind_param('ssss',$inicioUTC,$finUTC,$inicioUTC,$finUTC);
  } else {
    $st->bind_param('ss',$inicioUTC,$inicioUTC);
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
      'cobrado' => (int)($r['cobrado'] ?? 0),
    ];
  }
  $st->close();
  return $rows;
}
function digitalIngresosDesdeHasta($conn,$inicioUTC,$finUTC=null,$limit=300){
  $sql = "SELECT id, tipo, habitacion, categoria, monto, descripcion, codigo, created_at
          FROM digital_ingresos
          WHERE created_at >= ?".($finUTC ? " AND created_at < ?" : "")."
          ORDER BY created_at ASC
          LIMIT ?";
  $st = $conn->prepare($sql);
  if($finUTC){
    $st->bind_param('ssi',$inicioUTC,$finUTC,$limit);
  } else {
    $st->bind_param('si',$inicioUTC,$limit);
  }
  $st->execute();
  $res = $st->get_result();
  $rows = [];
  while($r = $res->fetch_assoc()){
    $rows[] = [
      'id' => (int)$r['id'],
      'tipo' => $r['tipo'] ?? '',
      'habitacion' => $r['habitacion'] ? (int)$r['habitacion'] : null,
      'categoria' => $r['categoria'] ?? '',
      'monto' => (float)($r['monto'] ?? 0),
      'descripcion' => $r['descripcion'] ?? '',
      'codigo' => $r['codigo'] ?? '',
      'created_at' => $r['created_at'] ?? ''
    ];
  }
  $st->close();
  return $rows;
}

function digitalTotalDesdeHasta($conn,$inicioUTC,$finUTC=null){
  $sql = "SELECT SUM(monto) total FROM digital_ingresos WHERE created_at >= ?".($finUTC ? " AND created_at < ?" : "");
  $st = $conn->prepare($sql);
  if($finUTC){
    $st->bind_param('ss',$inicioUTC,$finUTC);
  } else {
    $st->bind_param('s',$inicioUTC);
  }
  $st->execute();
  $res = $st->get_result()->fetch_assoc();
  $st->close();
  return (float)($res['total'] ?? 0);
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
  
  /* ===== Mensajes internos ===== */
  if ($accion === 'crear_mensaje') {
    $nombre = trim($_POST['nombre'] ?? '');
    $mensaje = trim($_POST['mensaje'] ?? '');

    if ($mensaje === '') {
      echo json_encode(['ok'=>0, 'error'=>'Ingresá un mensaje']);
      exit;
    }

    if ($nombre === '') { $nombre = 'Anónimo'; }
    $ahora = nowUTCStrFromArg();

    $st = $conn->prepare("INSERT INTO mensajes_internos (nombre, mensaje, estado, created_at) VALUES (?,?, 'pendiente', ?)");
    $st->bind_param('sss', $nombre, $mensaje, $ahora);
    $st->execute();
    $newId = $st->insert_id;
    $st->close();

    echo json_encode([
      'ok'=>1,
      'mensaje'=>[
        'id'=>(int)$newId,
        'nombre'=>$nombre,
        'mensaje'=>$mensaje,
        'hora'=>fmtHoraArg($ahora)
      ]
    ]);
    exit;
  }

  if ($accion === 'ack_mensaje') {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
      echo json_encode(['ok'=>0, 'error'=>'ID inválido']);
      exit;
    }

    $ahora = nowUTCStrFromArg();
    $st = $conn->prepare("UPDATE mensajes_internos SET estado='leido', updated_at=?, snooze_until=NULL WHERE id=?");
    $st->bind_param('si', $ahora, $id);
    $st->execute();
    $ok = $st->affected_rows > 0;
    $st->close();

    echo json_encode(['ok'=>$ok ? 1 : 0]);
    exit;
  }
  if ($accion === 'snooze_mensaje') {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
      echo json_encode(['ok'=>0, 'error'=>'ID inválido']);
      exit;
    }
    $ahora = nowUTCStrFromArg();
    $future = (new DateTime($ahora, new DateTimeZone('UTC')))->modify('+1 minute')->format('Y-m-d H:i:s');
    $st = $conn->prepare("UPDATE mensajes_internos SET snooze_until=?, updated_at=? WHERE id=?");
    $st->bind_param('ssi', $future, $ahora, $id);
    $st->execute();
    $ok = $st->affected_rows > 0;
    $st->close();
    echo json_encode(['ok'=>$ok ? 1 : 0, 'next'=>$future]);
    exit;
  }
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
  /* ===== MARCAR/Desmarcar cobrado ===== */
  if ($accion === 'toggle_cobrado') {
    $id   = intval($_POST['id'] ?? 0);
    $tipo = $_POST['tipo'] ?? '';

    if ($id <= 0 || !in_array($tipo, ['hab','venta'], true)) {
      echo json_encode(['ok'=>0,'error'=>'Datos inválidos']);
      exit;
    }

    $tabla = $tipo === 'hab' ? 'historial_habitaciones' : 'ventas_turno';

    $chk = $conn->prepare("SELECT COUNT(*) c FROM $tabla WHERE id=?");
    $chk->bind_param('i', $id);
    $chk->execute();
    $exists = (int)($chk->get_result()->fetch_assoc()['c'] ?? 0);
    $chk->close();

    if ($exists === 0) {
      echo json_encode(['ok'=>0,'error'=>'Movimiento inexistente']);
      exit;
    }

    $sel = $conn->prepare("SELECT cobrado FROM cobros_movimientos WHERE id=? AND tipo=? LIMIT 1");
    $sel->bind_param('is', $id, $tipo);
    $sel->execute();
    $cur = $sel->get_result()->fetch_assoc();
    $sel->close();

    $nuevo = ($cur['cobrado'] ?? 0) ? 0 : 1;

    $up = $conn->prepare("REPLACE INTO cobros_movimientos (id,tipo,cobrado) VALUES (?,?,?)");
    $up->bind_param('isi', $id, $tipo, $nuevo);
    $up->execute();
    $up->close();

    echo json_encode(['ok'=>1,'cobrado'=>$nuevo]);
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
        $st=$conn->prepare("UPDATE habitaciones SET estado=?, tipo_turno=NULL, hora_inicio=NULL, codigo_reserva=NULL WHERE id=?");
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

    $q = $conn->prepare("SELECT estado, tipo_turno, hora_inicio, codigo_reserva FROM habitaciones WHERE id=?");
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
    $codigo = $orig['codigo_reserva'] ?? null;

    $upDest = $conn->prepare("UPDATE habitaciones SET estado='reservada', tipo_turno=?, hora_inicio=?, codigo_reserva=? WHERE id=?");
    $upDest->bind_param('sssi', $turno, $horaInicio, $codigo, $hasta);
    $upDest->execute();
    $upDest->close();

    $upOrig = $conn->prepare("UPDATE habitaciones SET estado='libre', tipo_turno=NULL, hora_inicio=NULL, codigo_reserva=NULL WHERE id=?");
    $upOrig->bind_param('i', $desde);
    $upOrig->execute();
    $upOrig->close();

    $updHist = $conn->prepare("UPDATE historial_habitaciones SET habitacion=?, tipo=?, codigo=? WHERE habitacion=? AND hora_fin IS NULL AND estado='reservada'");
    $updHist->bind_param('issi', $hasta, $tipoDesde, $codigo, $desde);
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
            $startArgTs = toArgTs($curOpen['hora_inicio'] ?? null);
            $endArgTs = $startArgTs ? turnoEndArgTs($curOpen['turno'] ?? '', $startArgTs, $curOpen['bloques'] ?? 1) : null;
            $endUTC = $endArgTs ? gmdate('Y-m-d H:i:s', $endArgTs) : $startUTC;
            $mins = ($startArgTs!==null && $endArgTs!==null)
              ? max(0, intval(round(($endArgTs - $startArgTs) / 60)))
              : 0;

            $close = $conn->prepare("UPDATE historial_habitaciones SET hora_fin=?, duracion_minutos=? WHERE id=?");
            $close->bind_param('sii',$endUTC,$mins,$curOpen['id']);
            $close->execute();
            $close->close();

            $turnoHabitacion = $turnoTag;
            $inicioHabitacion = $endUTC;
            $precio = precioVigenteInt($conn, $tipoHab, $turnoTag);
            $fecha = $endArgTs ? argDateFromTs($endArgTs) : $fecha;

            $ins=$conn->prepare("INSERT INTO historial_habitaciones (habitacion,tipo,estado,turno,hora_inicio,fecha_registro,precio_aplicado,es_extra,bloques)
                             VALUES (?,?,?,?,?,?,?,1,1)");
            $ins->bind_param('isssssi',$id,$tipoHab,$estado,$turnoTag,$inicioHabitacion,$fecha,$precio);
            $ins->execute();
            $ins->close();
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
        $startArgTs = toArgTs($curOpen['hora_inicio'] ?? null);
        $endArgTs = $startArgTs ? turnoEndArgTs($curOpen['turno'] ?? '', $startArgTs, $curOpen['bloques'] ?? 1) : null;
        $endUTC = $endArgTs ? gmdate('Y-m-d H:i:s', $endArgTs) : $startUTC;
        $mins = ($startArgTs!==null && $endArgTs!==null)
          ? max(0, intval(round(($endArgTs - $startArgTs) / 60)))
          : 0;

        $close = $conn->prepare("UPDATE historial_habitaciones SET hora_fin=?, duracion_minutos=? WHERE id=?");
        $close->bind_param('sii',$endUTC,$mins,$curOpen['id']);
        $close->execute();
        $close->close();
    }

    $turnoHabitacion = $turnoTag;
    $inicioHabitacion = $endUTC ?? $startUTC;
    if(isset($endArgTs) && $endArgTs){
      $fecha = argDateFromTs($endArgTs);
    }
    $estado='ocupada';
    $ins=$conn->prepare("INSERT INTO historial_habitaciones (habitacion,tipo,estado,turno,hora_inicio,fecha_registro,precio_aplicado,es_extra,bloques)
                     VALUES (?,?,?,?,?,?,?,1,1)");
    $ins->bind_param('isssssi',$id,$tipoHab,$estado,$turnoTag,$inicioHabitacion,$fecha,$precio);
    $ins->execute();
    $ins->close();

    // Asegurar que la habitación quede marcada como ocupada y con inicio correcto
    $st=$conn->prepare("UPDATE habitaciones SET estado='ocupada', tipo_turno=?, hora_inicio=? WHERE id=?");
    $st->bind_param('ssi',$turnoHabitacion,$inicioHabitacion,$id); $st->execute(); $st->close();

    echo json_encode(['success'=>true,'hours'=>$blockHours]);
    exit;
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
$rs = $conn->query("SELECT id, estado, tipo_turno, hora_inicio, codigo_reserva FROM habitaciones ORDER BY id ASC");
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
<title>Panel Nuevo</title>
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
.room-code{
  font-size:11px;
  font-weight:800 !important;
  padding:2px 6px;
  border-radius:999px;
  background:rgba(255,255,255,0.8);
  color:#4c1d95 !important;
  letter-spacing:.04em;
}
.room-card.reservada .room-code,
.room-card.ocupada .room-code{
  background:#f5f3ff;
  color:#4c1d95 !important;
  box-shadow:0 0 0 1px rgba(76,29,149,0.2);
}

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

/* Tablas */
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
/* ===== Mensajes internos ===== */
.mensajes-wrapper{
  position:fixed;
  left:50%;
  bottom:18px;
  transform:translateX(-50%);
  z-index:30;
  width:min(500px, calc(100% - 28px));
  max-width:100%;
}
.mensajes-toggle{
  width:100%;
  border:none;
  background:#0b5fff;
  color:#fff;
  border-radius:14px;
  padding:10px 14px;
  font-weight:800;
  box-shadow:0 10px 20px rgba(11,95,255,0.25);
  display:flex;
  align-items:center;
  justify-content:center;
  gap:10px;
  cursor:pointer;
}
.mensajes-toggle.alerta{
  background:#dc2626;
  box-shadow:0 12px 26px rgba(220,38,38,0.35);
}
.mensajes-toggle span{
  background:rgba(255,255,255,0.15);
  padding:4px 8px;
  border-radius:999px;
  font-size:12px;
}
.mensajes-panel{
  margin-top:8px;
  background:#fff;
  border:1px solid var(--border);
  border-radius:16px;
  box-shadow:0 12px 28px rgba(15,23,42,0.25);
  overflow:hidden;
  display:flex;
  flex-direction:column;
}
.mensajes-panel.collapsed .mensajes-body,
.mensajes-panel.collapsed .mensajes-form{
  display:none;
}
.mensajes-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:8px;
  padding:12px 14px;
  background:#0b5fff;
  color:#fff;
}
.mensajes-header h3{margin:0;font-size:15px;}
.mensajes-actions{display:flex;gap:8px;align-items:center;}
.mensajes-actions button{
  border:none;
  background:rgba(255,255,255,0.15);
  color:#fff;
  padding:6px 10px;
  border-radius:10px;
  cursor:pointer;
}
.mensajes-body{
  max-height:260px;
  overflow-y:auto;
  padding:10px 12px;
  display:flex;
  flex-direction:column;
  gap:10px;
  background:#f8fafc;
}
.mensaje-item{
  background:#fff;
  border:1px solid #e2e8f0;
  border-radius:12px;
  padding:10px 12px;
  box-shadow:0 2px 6px rgba(0,0,0,0.05);
}
.mensaje-top{display:flex;justify-content:space-between;align-items:center;gap:6px; font-weight:700; font-size:13px;}
.mensaje-text{margin:6px 0 8px 0; font-size:14px; line-height:1.4;}
.mensaje-acciones{display:flex;justify-content:flex-end;}
.mensaje-acciones button{
  background:#16a34a;
  color:#fff;
  border:none;
  border-radius:10px;
  padding:6px 10px;
  cursor:pointer;
  font-weight:700;
}
.mensajes-form{
  border-top:1px solid #e2e8f0;
  padding:12px;
  display:grid;
  grid-template-columns:1fr auto;
  gap:8px;
  align-items:center;
}
.mensajes-form input,
.mensajes-form textarea{
  width:100%;
  border:1px solid #e2e8f0;
  border-radius:10px;
  padding:8px 10px;
  font-size:14px;
}
.mensajes-form textarea{grid-column:1/3; min-height:70px; resize:vertical;}
.mensajes-form button{
  background:#0b5fff;
  color:#fff;
  border:none;
  border-radius:10px;
  padding:10px 14px;
  font-weight:800;
  cursor:pointer;
}
@media(max-width:900px){
  .mensajes-wrapper{
    left:12px;
    right:auto;
    bottom:80px;
    transform:none;
    width:auto;
    max-width:calc(100% - 24px);
  }
  .mensajes-wrapper.expanded{
    background:#fff;
    border:1px solid var(--border);
    border-radius:16px;
    box-shadow:0 12px 28px rgba(15,23,42,0.25);
    padding:8px;
  }
  .mensajes-wrapper.expanded .mensajes-toggle{
    display:none;
  }
  .mensajes-wrapper.expanded .mensajes-panel{
    margin-top:0;
    border:0;
    box-shadow:none;
  }
  .mensajes-toggle{
    width:48px;
    height:48px;
    padding:0;
    border-radius:999px;
    font-size:0;
    position:relative;
    justify-content:center;
  }
  .mensajes-toggle::before{
    content:"💬";
    font-size:22px;
  }
  .mensajes-toggle span{
    position:absolute;
    top:-6px;
    right:-6px;
    font-size:12px;
  }
  .mensajes-panel{
    width:min(320px, calc(100vw - 24px));
  }
  .mensajes-panel.collapsed{
    display:none;
  }
  .mensajes-body{ max-height:220px; }
  .mensajes-form{ grid-template-columns:1fr; }
  .mensajes-form textarea{ grid-column:1/2; }
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
.minutos-alerta{
  color:#b91c1c;
  font-weight:700;
}
.fila-alerta{
  background:#fef2f2;
}
.nota-extra{
  color:#0b5fff;
  font-weight:600;
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
        <button class="<?php echo ($view==='reportes-empleadas'?'active':''); ?>" onclick="location.href='?view=reportes-empleadas'">Reporte empleadas</button>
        <button onclick="window.open('https://lamoradatandil.com/inventario.php', '_blank')">Inventario</button>
        <button class="<?php echo ($view==='valores'?'active':''); ?>" onclick="location.href='?view=valores'">Editar valores</button>
      <div class="clock" id="arg-clock">--:--:--</div>
      <span id="turno-label" style="font-weight:700;margin-left:8px;color:#0B5FFF">Turnos de -- h</span>
    </div>
  </div>
</header>
<!-- Menú móvil -->
<div id="mobile-menu">
  <button onclick="location.href='?view=panel'">Panel</button>
  <button onclick="location.href='?view=valores'">Editar valores</button>
  <button onclick="location.href='?view=reportes-empleadas'">Reporte empleadas</button>
  <button onclick="location.href='?view=reportes'">Reportes</button>
  <button onclick="window.open('https://lamoradatandil.com/inventario.php','_blank')">Inventario</button>
</div>

<script>
document.getElementById('menu-toggle').addEventListener('click', function(){
  const menu = document.getElementById('mobile-menu');
  menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
});
</script>
<?php if($view==='reportes-empleadas'): ?>
<?php
$fechaFiltro = preg_replace('/[^0-9\\-]/','', $_GET['fecha'] ?? argDateToday());
if($fechaFiltro===''){ $fechaFiltro = argDateToday(); }

$st = $conn->prepare("SELECT id, habitacion, turno, hora_inicio, hora_fin, duracion_minutos, es_extra, bloques FROM historial_habitaciones WHERE fecha_registro=? ORDER BY habitacion ASC, hora_inicio ASC");
$st->bind_param('s',$fechaFiltro);
$st->execute();
$res = $st->get_result();
$reporteEmpleadas = [];
$nowTs = nowArgDT()->getTimestamp();
while($r=$res->fetch_assoc()){
  $inicioTs = toArgTs($r['hora_inicio']);
  $esperadoFinTs = $inicioTs!==null ? turnoEndArgTs($r['turno'], $inicioTs, $r['bloques'] ?? 1) : null;
  $finTs = $r['hora_fin'] ? toArgTs($r['hora_fin']) : $nowTs;
  $minutos = ($r['duracion_minutos'] !== null) ? (int)$r['duracion_minutos'] : (($inicioTs!==null && $finTs!==null) ? max(0, (int)round(($finTs - $inicioTs) / 60)) : 0);

  list($proximaExtraTs,$proximaExtraTurno) = proximaExtra($conn,(int)$r['habitacion'],$r['hora_inicio']);
  $tieneExtra = $proximaExtraTs !== null && ($esperadoFinTs === null || $proximaExtraTs <= $esperadoFinTs + 10800);

  $nota = '';
  if((int)$r['es_extra'] === 1){
    $nota = 'Turno extra / reactivado';
  } elseif($tieneExtra){
    $nota = 'Extendido con turno extra'.($proximaExtraTurno ? ' ('.turnoLabelCorto($proximaExtraTurno).')' : '');
  }

  $alerta = false;
  if($esperadoFinTs!==null){
    if(in_array($r['turno'], ['turno-2h','turno-3h'], true)){
      $alerta = !$tieneExtra && ($finTs > $esperadoFinTs + (15 * 60));
    } elseif(in_array($r['turno'], ['noche','noche-finde','noche-find'], true)){
      $abierta = empty($r['hora_fin']);
      $alerta = !$tieneExtra && (($abierta && $nowTs > $esperadoFinTs) || (!$abierta && $finTs > $esperadoFinTs));
    }
  }

  $reporteEmpleadas[] = [
    'habitacion'=>(int)$r['habitacion'],
    'turno'=>turnoLabelCorto($r['turno']),
    'inicio'=>fmtHoraArg($r['hora_inicio']),
    'fin'=>$r['hora_fin'] ? fmtHoraArg($r['hora_fin']) : 'En curso',
    'minutos'=>$minutos,
    'nota'=>$nota,
    'alerta'=>$alerta
  ];
}
$st->close();
?>
<main class="container">
  <div class="card" style="margin-top:20px;">
    <h2 style="margin-top:0">📝 Reporte diario para empleadas</h2>
    <p style="color:#4b5563;">Turnos registrados en la fecha seleccionada. Los minutos se remarcan en rojo solo si superan el límite sin un turno extra o reactivación posterior.</p>
    <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:12px;">
      <input type="hidden" name="view" value="reportes-empleadas">
      <label>Fecha
        <input type="date" name="fecha" value="<?= safe($fechaFiltro) ?>">
      </label>
      <button type="submit">Ver reporte</button>
    </form>
    <style>
      .table-sortable th {
        cursor: pointer;
        user-select: none;
        position: relative;
      }
      .table-sortable th[data-sort-direction="asc"]::after {
        content: "▲";
        position: absolute;
        right: 8px;
        font-size: 11px;
        color: #6b7280;
      }
      .table-sortable th[data-sort-direction="desc"]::after {
        content: "▼";
        position: absolute;
        right: 8px;
        font-size: 11px;
        color: #6b7280;
      }
    </style>
    <div class="table-container">
      <table class="table table-sortable" id="tabla-reporte-empleadas">
        <thead>
          <tr>
            <th data-sort-key="habitacion">Habitación</th>
            <th data-sort-key="turno">Turno</th>
            <th data-sort-key="inicio">Inicio</th>
            <th data-sort-key="fin">Fin</th>
            <th data-sort-key="minutos">Minutos</th>
            <th data-sort-key="nota">Nota</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($reporteEmpleadas)): ?>
            <tr><td colspan="6" style="text-align:center;padding:10px;color:#6b7280;">Sin movimientos para esta fecha.</td></tr>
          <?php else: foreach($reporteEmpleadas as $row): ?>
            <tr class="<?= $row['alerta'] ? 'fila-alerta' : '' ?>">
              <td>Hab. <?= $row['habitacion'] ?></td>
              <td><?= safe($row['turno']) ?></td>
              <td><?= safe($row['inicio']) ?></td>
              <td><?= safe($row['fin']) ?></td>
              <td><span class="<?= $row['alerta'] ? 'minutos-alerta' : '' ?>"><?= (int)$row['minutos'] ?> min</span></td>
              <td><?= $row['nota'] ? '<span class="nota-extra">'.safe($row['nota']).'</span>' : '-' ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <script>
    (function() {
      const table = document.getElementById('tabla-reporte-empleadas');
      if (!table) return;

      const tbody = table.querySelector('tbody');
      const headers = table.querySelectorAll('th[data-sort-key]');
      const parseTimeToMinutes = (text) => {
        const match = text.match(/(\d{1,2}):(\d{2})/);
        if (!match) return null;
        const hours = parseInt(match[1], 10);
        const minutes = parseInt(match[2], 10);
        return (hours * 60) + minutes;
      };

      const normalizeValue = (row, key, index) => {
        const text = (row.cells[index]?.textContent || '').trim();
        switch (key) {
          case 'habitacion':
            return parseInt(text.replace(/\D+/g, ''), 10) || 0;
          case 'turno':
            return text.toLowerCase();
          case 'inicio':
          case 'fin': {
            const t = parseTimeToMinutes(text);
            return (t !== null) ? t : Number.POSITIVE_INFINITY;
          }
          case 'minutos':
            return parseInt(text.replace(/\D+/g, ''), 10) || 0;
          case 'nota':
            return text.toLowerCase();
          default:
            return text.toLowerCase();
        }
      };

      let sortState = { key: null, direction: null };

      const updateIndicators = () => {
        headers.forEach((th) => {
          if (th.dataset.sortKey === sortState.key) {
            th.setAttribute('data-sort-direction', sortState.direction);
          } else {
            th.removeAttribute('data-sort-direction');
          }
        });
      };

      const sortRows = (key, index) => {
        const nextDirection = (sortState.key === key && sortState.direction === 'asc') ? 'desc' : 'asc';
        sortState = { key, direction: nextDirection };

        const rows = Array.from(tbody.querySelectorAll('tr'));
        rows.sort((a, b) => {
          const aVal = normalizeValue(a, key, index);
          const bVal = normalizeValue(b, key, index);

          let compare;
          if (typeof aVal === 'number' && typeof bVal === 'number') {
            compare = aVal - bVal;
          } else {
            compare = String(aVal).localeCompare(String(bVal), 'es', { sensitivity: 'base' });
          }

          return nextDirection === 'asc' ? compare : -compare;
        });

        tbody.innerHTML = '';
        rows.forEach((row) => tbody.appendChild(row));
        updateIndicators();
      };

      headers.forEach((th, index) => {
        th.addEventListener('click', () => sortRows(th.dataset.sortKey, index));
        th.title = 'Ordenar';
      });
    })();
  </script>

  <a href="?view=panel" style="display:block;margin-top:10px;color:#0B5FFF;text-decoration:none;">⬅ Volver al panel</a>
</main>
<?php endif; ?>

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

// Preparar reportes con movimientos calculados (una sola vez)
$reportes = [];
foreach ($rows as $r) {
    $inicioTurno = $r['inicio'];
    $finTurno = $r['fin'] ?? $r['inicio'];
    $movsBase = cajaDetalleDesdeHasta($conn, $inicioTurno, $finTurno, 500);
    $movsDigital = digitalIngresosDesdeHasta($conn, $inicioTurno, $finTurno, 500);
    $movs = $movsBase;

    $digitalTotal = 0;
    foreach($movsDigital as $d){
        $digitalTotal += (float)$d['monto'];
        $movs[] = [
          'id'    => $d['id'],
          'tipo'  => 'digital',
          'hab'   => $d['habitacion'] ? ('Hab. '.$d['habitacion']) : ($d['tipo'] ?: 'Ingreso digital'),
          'turno' => $d['tipo'] ?? '',
          'inicio'=> fmtHoraArg($d['created_at']),
          'monto' => (int)round($d['monto']),
          'descripcion' => $d['descripcion'] ?? '',
          'codigo' => $d['codigo'] ?? ''
        ];
    }

    usort($movs, function($a,$b){
      return strcmp($a['inicio'] ?? '', $b['inicio'] ?? '');
    });
    $reportes[] = [
        'data' => array_merge($r, [
          'total_con_digital' => (int)$r['total'] + (int)round($digitalTotal),
          'digital_total' => $digitalTotal
        ]),
        'movs' => $movs
    ];
}
// Calcular total de caja del filtro seleccionado
// Calcular totales del filtro
$total_caja = 0;
$total_ocupaciones = 0;
$cantidad_turnos = count($rows);

foreach ($reportes as $rep) {
    $r = $rep['data'];
    $total_caja += (int)($r['total_con_digital'] ?? $r['total']);
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

.reportes-table { width:100%; }
.reportes-table table { width:100%; }

.report-cards { display:none; gap:12px; }
.report-card {
  background:#fff;
  border:1px solid #e5e7eb;
  border-radius:10px;
  padding:12px;
  box-shadow:0 1px 3px rgba(0,0,0,0.08);
}
.report-card-header {
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:8px;
  font-size:14px;
}
.report-card-header .label { color:#6b7280; }
.report-card-header .value { font-weight:600; text-align:right; }
.report-card-header .full { grid-column:1/-1; }
.report-card details summary {
  cursor:pointer;
  margin-top:10px;
  font-weight:600;
  color:#0B5FFF;
}
.report-card details[open] summary { margin-bottom:6px; }
.report-card .mov-table {
  width:100%;
  border-collapse:collapse;
  font-size:13px;
}
.report-card .mov-table th,
.report-card .mov-table td { padding:4px 6px; }
.report-card .mov-table th { text-align:left; color:#374151; }

@media (max-width: 820px) {
  .controls { flex-direction:column; align-items:flex-start; }
  .reportes-table { display:none; }
  .report-cards { display:flex; flex-direction:column; }
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

<div class="reportes-table">
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
    <?php if(empty($reportes)): ?>
      <tr><td colspan="6" style="padding:10px;text-align:center;color:#777;">Sin turnos cerrados todavía.</td></tr>
   <?php else: foreach($reportes as $rep): $r=$rep['data']; $movs=$rep['movs']; ?>
  <tr>
    <td><?= fmtFechaArg($r['inicio']) ?></td>
    <td><?= turnoNombre($r['turno']) ?></td>
    <td><?= fmtHoraArg($r['inicio']) ?></td>
    <td><?= fmtHoraArg($r['fin']) ?></td>
    <td><?= (int)$r['ocupaciones'] ?></td>
    <td>$ <?= number_format((int)($r['total_con_digital'] ?? $r['total']),0,',','.') ?></td>
  </tr>
  <tr>
    <td colspan="6" style="background:#f8fafc;">
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
              <?php foreach($movs as $m): $isDigital = ($m['tipo'] ?? '') === 'digital'; ?>
                <tr style="<?= $isDigital ? 'background:#ecfdf3;' : '' ?>">
                  <td style="padding:4px 6px;">
                    <?php
                      if(($m['tipo'] ?? '')==='venta')      echo safe($m['hab']);
                      elseif(($m['tipo'] ?? '')==='ajuste') echo '💳 '.safe($m['hab']);
                      elseif(($m['tipo'] ?? '')==='digital') echo '💳 '.safe($m['hab']);
                      else                                   echo 'Hab. '.safe($m['hab']);
                    ?>
                    <?php if(!empty($m['descripcion'])): ?>
                      <div style="color:#047857;font-size:12px;"><?= safe($m['descripcion']) ?></div>
                    <?php endif; ?>
                    <?php if(!empty($m['codigo'])): ?>
                      <div style="color:#047857;font-size:11px;">Ref: <?= safe($m['codigo']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td style="padding:4px 6px;"><?= safe($m['inicio']) ?></td>
                  <td style="padding:4px 6px; color:<?= ($m['monto']<0?'#b91c1c':($isDigital ? '#047857':'#111827')) ?>; text-align:right; font-weight:<?= $isDigital ? '700' : '400' ?>;">
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

</div>

<div class="report-cards">
  <?php if(empty($reportes)): ?>
    <div class="report-card" style="color:#6b7280;">Sin turnos cerrados todavía.</div>
  <?php else: foreach($reportes as $rep): $r=$rep['data']; $movs=$rep['movs']; ?>
    <div class="report-card">
      <div class="report-card-header">
        <div>
          <div class="label">Fecha</div>
          <div class="value"><?= fmtFechaArg($r['inicio']) ?></div>
        </div>
        <div>
          <div class="label">Turno</div>
          <div class="value"><?= turnoNombre($r['turno']) ?></div>
        </div>
        <div>
          <div class="label">Inicio</div>
          <div class="value"><?= fmtHoraArg($r['inicio']) ?></div>
        </div>
        <div>
          <div class="label">Fin</div>
          <div class="value"><?= fmtHoraArg($r['fin']) ?></div>
        </div>
        <div class="full">
          <div class="label">Total</div>
          <div class="value">$ <?= number_format((int)($r['total_con_digital'] ?? $r['total']),0,',','.') ?></div>
          <?php if(!empty($r['digital_total'])): ?>
            <div class="label" style="color:#047857;">Incluye ingresos digitales: $ <?= number_format((int)round($r['digital_total']),0,',','.') ?></div>
          <?php endif; ?>
        </div>
        <div class="full" style="color:#6b7280; font-size:13px;">Ocupaciones: <?= (int)$r['ocupaciones'] ?></div>
      </div>

      <details>
        <summary>Ver movimientos del turno</summary>
        <div style="margin-top:6px;">
          <?php if(empty($movs)): ?>
            <div style="color:#6b7280;">Sin movimientos registrados.</div>
          <?php else: ?>
          <table class="mov-table">
            <thead>
              <tr>
                <th>Origen</th>
                <th>Hora</th>
                <th style="text-align:right;">Monto</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($movs as $m): $isDigital = ($m['tipo'] ?? '')==='digital'; ?>
              <tr style="<?= $isDigital ? 'background:#ecfdf3;' : '' ?>">
                <td>
                  <?php
                    if(($m['tipo'] ?? '')==='venta')      echo safe($m['hab']);
                    elseif(($m['tipo'] ?? '')==='ajuste') echo '💳 '.safe($m['hab']);
                    elseif(($m['tipo'] ?? '')==='digital') echo '💳 '.safe($m['hab']);
                    else                                   echo 'Hab. '.safe($m['hab']);
                  ?>
                  <?php if(!empty($m['descripcion'])): ?>
                    <div style="color:#047857;font-size:12px;"><?= safe($m['descripcion']) ?></div>
                  <?php endif; ?>
                  <?php if(!empty($m['codigo'])): ?>
                    <div style="color:#047857;font-size:11px;">Ref: <?= safe($m['codigo']) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= safe($m['inicio']) ?></td>
                <td style="text-align:right; color:<?= ($m['monto']<0?'#b91c1c':($isDigital?'#047857':'#111827')) ?>;">$ <?= number_format($m['monto'],0,',','.') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </details>
    </div>
  <?php endforeach; endif; ?>
</div>

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
        $codigo=trim($s['codigo_reserva'] ?? '');
        $rest = sumRemainingForRoom($conn,$id);
        $estadoTxt = ($estado==='libre'?'Disponible':($estado==='limpieza'?'Limpieza':($estado==='reservada'?'Reservada':'Ocupada')));
        $timer = formatTimerLabel($rest, $estado);
        $isSuper = in_array($id,$SUPER_VIP);
      ?>
      <div class="room-wrap">
        <div class="room-card <?= $estado ?> <?= $isSuper?'super':'' ?>" id="hab-<?= $id ?>" data-id="<?= $id ?>" data-restante="<?= $rest ?>" data-tipo="<?= $tipo ?>" data-codigo="<?= safe($codigo) ?>">
          <div class="state-line"><?= safe($estadoTxt) ?></div>
          <div class="timer" id="timer-<?= $id ?>"><?= safe($timer) ?></div>
          <div class="room-num"><?= $id ?></div>
          <?php if($codigo && ($estado==='reservada' || $estado==='ocupada')): ?>
            <div class="room-code">#<?= safe($codigo) ?></div>
          <?php endif; ?>
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
        $codigo=trim($s['codigo_reserva'] ?? '');
        $rest = sumRemainingForRoom($conn,$id);
        $estadoTxt = ($estado==='libre'?'Disponible':($estado==='limpieza'?'Limpieza':($estado==='reservada'?'Reservada':'Ocupada')));
        $timer = formatTimerLabel($rest, $estado);
        $isSuper = in_array($id,$SUPER_VIP);
      ?>
      <div class="room-wrap">
        <div class="room-card <?= $estado ?> <?= $isSuper?'super':'' ?>" id="hab-<?= $id ?>" data-id="<?= $id ?>" data-restante="<?= $rest ?>" data-tipo="<?= $tipo ?>" data-codigo="<?= safe($codigo) ?>">
          <div class="state-line"><?= safe($estadoTxt) ?></div>
          <div class="timer" id="timer-<?= $id ?>"><?= safe($timer) ?></div>
          <div class="room-num"><?= $id ?></div>
          <?php if($codigo && ($estado==='reservada' || $estado==='ocupada')): ?>
            <div class="room-code">#<?= safe($codigo) ?></div>
          <?php endif; ?>
        </div>
        <div class="room-kind"><?= $tipo ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Grid móvil (2 columnas, orden especial) -->
  <div id="mobile-grid" class="mobile-grid"></div>
  <!-- Mensajes internos -->
  <div class="mensajes-wrapper" id="mensajes-wrapper">
    <button class="mensajes-toggle" id="mensajes-toggle">💬 Mensajes <span id="mensajes-count">0</span></button>
    <div class="mensajes-panel collapsed" id="mensajes-panel">
      <div class="mensajes-header">
        <h3>Mensajes internos</h3>
        <div class="mensajes-actions">
          <button type="button" id="mensajes-refresh">Actualizar</button>
          <button type="button" id="mensajes-colapsar">Minimizar</button>
        </div>
      </div>
      <div class="mensajes-body" id="mensajes-body">
        <div style="text-align:center;color:#64748B;font-weight:600;">Cargando mensajes...</div>
      </div>
      <form class="mensajes-form" id="mensajes-form">
        <input type="text" id="msg-nombre" name="nombre" placeholder="Tu nombre o sector" autocomplete="name">
        <button type="submit" aria-label="Enviar mensaje">Enviar mensaje</button>
        <textarea id="msg-texto" name="mensaje" placeholder="Escribí el mensaje" required></textarea>
      </form>
    </div>
  </div>
  
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
const escapeHTML = (str='') => str
  .replace(/&/g,'&amp;')
  .replace(/</g,'&lt;')
  .replace(/>/g,'&gt;')
  .replace(/"/g,'&quot;')
  .replace(/'/g,'&#039;');

const msgPanel = document.getElementById('mensajes-panel');
const msgBody = document.getElementById('mensajes-body');
const msgToggle = document.getElementById('mensajes-toggle');
const msgWrapper = document.getElementById('mensajes-wrapper');
const msgRefresh = document.getElementById('mensajes-refresh');
const msgCollapse = document.getElementById('mensajes-colapsar');
const msgCount = document.getElementById('mensajes-count');
const msgForm = document.getElementById('mensajes-form');
const msgNombre = document.getElementById('msg-nombre');
const msgTexto = document.getElementById('msg-texto');
let mensajesTimer = null;

function setMensajesCollapsed(collapsed){
  msgPanel.classList.toggle('collapsed', collapsed);
  if (msgWrapper) {
    msgWrapper.classList.toggle('expanded', !collapsed);
  }
}

function renderMensajes(list){
  const hay = Array.isArray(list) && list.length > 0;
  msgCount.textContent = list.length;
  msgToggle.classList.toggle('alerta', hay);

  if(!hay){
    msgBody.innerHTML = '<div style="text-align:center;color:#64748B;font-weight:600;">No hay mensajes pendientes</div>';
    return;
  }

  msgBody.innerHTML = '';
  list.forEach(m => {
    const item = document.createElement('div');
    item.className = 'mensaje-item';
    item.innerHTML = `
      <div class="mensaje-top">
        <span>${escapeHTML(m.nombre || 'Anon')}</span>
        <span style="color:#0b5fff;font-size:12px;">${escapeHTML(m.hora || '')}</span>
      </div>
      <div class="mensaje-text">${escapeHTML(m.mensaje || '')}</div>
      <div class="mensaje-acciones">
        <button type="button" data-id="${m.id}" data-action="ack">Marcar leído</button>
        <button type="button" data-id="${m.id}" data-action="snooze">Recordar más tarde</button>
      </div>
    `;
    msgBody.appendChild(item);
  });
}

async function actualizarMensajes(){
  try{
    const r = await fetch('?ajax_mensajes=1', {cache:'no-store'});
    const j = await r.json();
    if(j.ok){ renderMensajes(j.mensajes || []); }
  }catch(err){ console.error(err); }
}

async function marcarLeido(id){
  if(!id) return;
  try{
    await fetch('',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({accion:'ack_mensaje',id})
    });
    actualizarMensajes();
  }catch(err){ console.error(err); }
}
async function snoozearMensaje(id){
  if(!id) return;
  try{
    await fetch('',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({accion:'snooze_mensaje',id})
    });
    actualizarMensajes();
  }catch(err){ console.error(err); }
}
msgToggle.addEventListener('click', ()=>{
  const col = msgPanel.classList.contains('collapsed');
  setMensajesCollapsed(!col);
});
msgCollapse.addEventListener('click', ()=> setMensajesCollapsed(true));
msgRefresh.addEventListener('click', actualizarMensajes);

msgBody.addEventListener('click', (ev)=>{
  const btn = ev.target.closest('button[data-id]');
  if(!btn) return;
  const id = btn.dataset.id;
  const action = btn.dataset.action || 'ack';
  btn.disabled = true;
  if(action === 'snooze'){
    snoozearMensaje(id).finally(()=>{ btn.disabled=false; });
  } else {
    marcarLeido(id).finally(()=>{ btn.disabled=false; });
  }
});

msgForm.addEventListener('submit', async (ev)=>{
  ev.preventDefault();
  const nombre = msgNombre.value.trim();
  const mensaje = msgTexto.value.trim();
  if(!mensaje){
    Swal.fire({icon:'warning',title:'Escribí un mensaje'});
    return;
  }

  try{
    const r = await fetch('',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({accion:'crear_mensaje', nombre, mensaje})
    });
    const j = await r.json();
    if(j.ok){
      msgTexto.value='';
      setMensajesCollapsed(false);
      actualizarMensajes();
    } else {
      Swal.fire({icon:'error',title:'No se pudo guardar', text:j.error||'Reintentá'});
    }
  }catch(err){ console.error(err); }
});

mensajesTimer = setInterval(actualizarMensajes, 5000);
actualizarMensajes();
</script>
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
     body.innerHTML=j.detalle.map(m=>{
        const toggleable = m.tipo !== 'ajuste';
        const bg = toggleable ? (m.cobrado ? '#dcfce7' : '#fee2e2') : '#f3f4f6';
        const cursor = toggleable ? 'pointer' : 'default';
        const cobradoTxt = toggleable ? (m.cobrado ? 'Cobrado' : 'Pendiente') : '';
        return `
          <tr
            data-id="${m.id}"
            data-tipo="${m.tipo}"
            data-toggle="${toggleable?1:0}"
            data-cobrado="${m.cobrado||0}"
            style="background:${bg}; cursor:${cursor};">
            <td style="padding:4px 6px;">${m.hab}</td>
            <td style="padding:4px 6px;">${m.inicio}</td>
            <td style="padding:4px 6px; text-align:right; ${m.monto<0?'color:#b91c1c;':'color:#111827;'}">${m.monto}</td>
            <td style="padding:4px 6px; text-align:center;">
              ${toggleable ? `<span style="font-size:10px;color:#374151;display:block;">${cobradoTxt}</span>` : ''}
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
        `;
      }).join('');

      body.querySelectorAll('tr[data-toggle="1"]').forEach(row=>{
        row.addEventListener('click', async (ev)=>{
          if(ev.target.closest('.btn-del-mov')) return;
          const id=row.dataset.id;
          const tipo=row.dataset.tipo;
          try{
            const res=await fetch('',{
              method:'POST',
              headers:{'Content-Type':'application/x-www-form-urlencoded'},
              body:new URLSearchParams({accion:'toggle_cobrado',id,tipo})
            });
            const j=await res.json();
            if(j.ok){
              row.dataset.cobrado = j.cobrado ? '1':'0';
              row.style.background = j.cobrado ? '#dcfce7' : '#fee2e2';
              const badge=row.querySelector('span');
              if(badge) badge.textContent = j.cobrado ? 'Cobrado' : 'Pendiente';
            }
          }catch(err){ console.error(err); }
        });
      });
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
      const codigo = newCard.getAttribute('data-codigo') || '';
      cur.setAttribute('data-codigo', codigo);

      /* ==============================
         🔥 FIX 3 — actualizar textos
         ============================== */
      cur.querySelector('.state-line').textContent =
          newCard.querySelector('.state-line').textContent;

      cur.querySelector('.timer').textContent =
          newCard.querySelector('.timer').textContent;
          const codeTxt = (codigo && (cur.classList.contains('ocupada') || cur.classList.contains('reservada'))) ? ('#'+codigo) : '';
      let codeEl = cur.querySelector('.room-code');
      if(codeTxt){
        if(!codeEl){
          codeEl = document.createElement('div');
          codeEl.className = 'room-code';
          cur.appendChild(codeEl);
        }
        codeEl.textContent = codeTxt;
      } else if(codeEl){
        codeEl.remove();
      }

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
</body>
</html>