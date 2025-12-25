<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=utf-8');

$HOTEL_ADDRESS   = "La Morada Tandil — La Pampa 931, Tandil";
$HOTEL_MAP_LINK  = "https://maps.app.goo.gl/TdDNFWW5nGRLRVq47";
$MP_ACCESS_TOKEN = "APP_USR-4518252275853191-112421-370721fbc465852fcb25cc7cba42e681-59176727";

if (class_exists('MercadoPago\\SDK')) {
    MercadoPago\SDK::setAccessToken($MP_ACCESS_TOKEN);
}

$SUPER_VIP = [20,21];
$VIP_LIST  = [3,4,11,12,13,28,29,30,37,38];

function db(){
    static $conn = null;
    if($conn){ return $conn; }
    $servername = "127.0.0.1";
    $username   = "u460517132_F5bOi";
    $password   = "mDjVQbpI5A";
    $dbname     = "u460517132_GxbHQ";
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) { http_response_code(500); die("DB error"); }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function nowArgDT(){ return new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')); }
function nowUTCStrFromArg(){ $dt=nowArgDT(); $dt->setTimezone(new DateTimeZone('UTC')); return $dt->format('Y-m-d H:i:s'); }
function toArgTs($utc){
    if(!$utc) return null;
    $dtUtc = new DateTime($utc, new DateTimeZone('UTC'));
    $dtUtc->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
    return $dtUtc->getTimestamp();
}
function argDateToday(){ return nowArgDT()->format('Y-m-d'); }
function turnoEndArgTs($turno,$startArgTs,$bloques=1){
    $bloques = max(1, (int)$bloques);
    if($turno==='turno-2h'){ return $startArgTs + (2 * 3600 * $bloques); }
    if($turno==='turno-3h'){ return $startArgTs + (3 * 3600 * $bloques); }
    if(in_array($turno, ['noche','noche-finde','noche-find'], true)){
      $dt = new DateTime('@'.$startArgTs); $dt->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
      $hour=(int)$dt->format('G');
      if($hour>=21){ $dt->modify('+1 day'); }
      $dt->setTime(10,0,0);
      return $dt->getTimestamp();
    }
    return $startArgTs;
}
function endArgTsConAjuste($turno,$startArgTs,$bloques=1,$ajusteSegundos=0){
  return turnoEndArgTs($turno,$startArgTs,$bloques) + (int)$ajusteSegundos;
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
function isNightAllowedNow(){ list($dow,$hour)=argNowInfo(); return ($hour>=21 || $hour<10); }
function turnoBlockHoursForToday(){ list($dow,$hour)=argNowInfo(); return isTwoHourWindowNow($dow,$hour) ? 2 : 3; }

function tipoDeHabitacion($id, $SUPER_VIP, $VIP_LIST){
  if(in_array($id,$SUPER_VIP)) return 'Super VIP';
  if(in_array($id,$VIP_LIST))  return 'VIP';
  return 'Común';
}
function categoriaLabel($slug){
  if($slug==='vip') return 'VIP';
  if($slug==='supervip') return 'Super VIP';
  return 'Común';
}
function idsPorCategoria($slug, $SUPER_VIP, $VIP_LIST){
  if($slug==='vip') return $VIP_LIST;
  if($slug==='supervip') return $SUPER_VIP;
  $all = range(1,40);
  return array_values(array_diff($all, $SUPER_VIP, $VIP_LIST));
}
function baseUrl(){
  $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
  $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $path  = strtok($_SERVER['REQUEST_URI'] ?? '/pagos.php', '?');
  return $proto . $host . $path;
}
function precioVigenteInt($conn,$tipo,$turno){
  $p=0.0;
  $st=$conn->prepare("SELECT precio FROM precios_habitaciones WHERE tipo=? AND turno=? LIMIT 1");
  $st->bind_param('ss',$tipo,$turno); $st->execute(); $res=$st->get_result(); if($row=$res->fetch_assoc()) $p=floatval($row['precio']);
  $st->close();
  return (int)round($p,0);
}
function habitacionLibrePorCategoria($conn,$slug,$SUPER_VIP,$VIP_LIST,$prefer=null){
  $ids = idsPorCategoria($slug,$SUPER_VIP,$VIP_LIST);
  if($prefer && in_array($prefer,$ids, true)){
    $chk = $conn->prepare("SELECT estado FROM habitaciones WHERE id=? LIMIT 1");
    $chk->bind_param('i',$prefer); $chk->execute(); $r=$chk->get_result()->fetch_assoc(); $chk->close();
    if(($r['estado'] ?? '') === 'libre'){ return $prefer; }
  }
  if(empty($ids)) return null;
  $place = implode(',', array_map('intval',$ids));
  $res = $conn->query("SELECT id FROM habitaciones WHERE estado='libre' AND id IN ($place) ORDER BY id ASC LIMIT 1");
  $row = $res ? $res->fetch_assoc() : null;
  return $row ? (int)$row['id'] : null;
}
function habitacionOcupada($conn,$id){
  $st = $conn->prepare("SELECT estado FROM habitaciones WHERE id=? LIMIT 1");
  $st->bind_param('i',$id); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
  return ($r['estado'] ?? '') === 'ocupada';
}
function habitacionEstado($conn,$id){
  $st = $conn->prepare("SELECT estado, codigo_reserva FROM habitaciones WHERE id=? LIMIT 1");
  $st->bind_param('i',$id); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
  return $r;
}
function turnoActualClave($conn){
  $res = $conn->query("SELECT turno FROM turno_actual WHERE id=1 LIMIT 1");
  $row = $res ? $res->fetch_assoc() : null;
  return $row['turno'] ?? 'manana';
}
function generarCodigoReserva($conn){
  do {
    $code = str_pad((string)rand(0,9999), 4, '0', STR_PAD_LEFT);
    $chk = $conn->prepare("SELECT COUNT(*) c FROM habitaciones WHERE codigo_reserva=?");
    $chk->bind_param('s',$code); $chk->execute(); $cnt=$chk->get_result()->fetch_assoc()['c'] ?? 0; $chk->close();
    if($cnt==0){
      $chk = $conn->prepare("SELECT COUNT(*) c FROM historial_habitaciones WHERE codigo=? AND hora_fin IS NULL");
      $chk->bind_param('s',$code); $chk->execute(); $cnt2=$chk->get_result()->fetch_assoc()['c'] ?? 0; $chk->close();
      if($cnt2==0) break;
    }
  } while(true);
  return $code;
}
function registrarMensajeInterno($conn,$titulo,$texto){
  $ahora = nowUTCStrFromArg();
  $st = $conn->prepare("INSERT INTO mensajes_internos (nombre,mensaje,estado,created_at) VALUES (?,?, 'pendiente', ?)");
  $st->bind_param('sss',$titulo,$texto,$ahora);
  $st->execute(); $st->close();
}
function registrarIngresoDigital($conn,$tipo,$habitacion,$categoria,$monto,$descripcion,$codigo,$bloques=1,$referencia=null){
  $turno = turnoActualClave($conn);
  $now = nowUTCStrFromArg();
  $st = $conn->prepare("INSERT INTO digital_ingresos (tipo,habitacion,categoria,monto,descripcion,codigo,turno,bloques,referencia,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)");
  $st->bind_param('sisdsssiss',$tipo,$habitacion,$categoria,$monto,$descripcion,$codigo,$turno,$bloques,$referencia,$now);
  $st->execute(); $st->close();
}
function crearPreferenciaMP($title,$amount,$token,$description){
  global $MP_ACCESS_TOKEN;
  $endpoint = "https://api.mercadopago.com/checkout/preferences";
  $payload = [
    'items' => [[
      'title' => $title,
      'description' => $description,
      'quantity' => 1,
      'currency_id' => 'ARS',
      'unit_price' => (float)$amount
    ]],
    'back_urls' => [
      'success' => baseUrl() . '?status=success&token='.$token,
      'failure' => baseUrl() . '?status=failure&token='.$token,
      'pending' => baseUrl() . '?status=pending&token='.$token,
    ],
    'auto_return' => 'approved'
  ];
  $ch = curl_init($endpoint);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer '.$MP_ACCESS_TOKEN
  ]);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if($resp === false || $code >= 400){
    curl_close($ch);
    return [null, null];
  }
  curl_close($ch);
  $j = json_decode($resp, true);
  return [$j['init_point'] ?? null, $j['id'] ?? null];
}
function normalizarEstadoPago($status,$collectionStatus=null){
  $candidatos = array_filter(array_map(function($v){
    return strtolower(trim((string)$v));
  }, [$status, $collectionStatus]));

  foreach($candidatos as $st){
    if(in_array($st, ['success','approved','accredited','authorized'], true)){
      return 'success';
    }
  }
  return $candidatos[0] ?? null;
}
function verificarPagoMP($paymentId,$prefEsperado=null){
  global $MP_ACCESS_TOKEN;
  $paymentId = preg_replace('/[^a-zA-Z0-9_-]/','', $paymentId ?? '');
  if(!$paymentId) return ['approved'=>false];

  $endpoint = "https://api.mercadopago.com/v1/payments/".urlencode($paymentId);
  $ch = curl_init($endpoint);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer '.$MP_ACCESS_TOKEN
    ]
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if($resp === false || $code >= 400){ return ['approved'=>false]; }
  $j = json_decode($resp, true);
  $estado = strtolower($j['status'] ?? '');
  $prefId = $j['preference_id'] ?? ($j['order']['id'] ?? null);
  $coincide = $prefEsperado ? ($prefId === $prefEsperado) : true;
  return [
    'approved' => ($estado === 'approved'),
    'status' => $estado,
    'preference_id' => $prefId,
    'matches_pref' => $coincide
  ];
}
function guardarPagoPendiente($conn,$tipo,$payload,$prefId,$token,$monto,$habitacion,$categoria){
  $ahora = nowUTCStrFromArg();
  $st = $conn->prepare("INSERT INTO pagos_online (tipo,payload,pref_id,token,estado,monto,habitacion,categoria,created_at) VALUES (?,?,?,?, 'pendiente', ?,?,?,?)");
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
  $st->bind_param('ssssdiss',$tipo,$json,$prefId,$token,$monto,$habitacion,$categoria,$ahora);
  $st->execute(); $st->close();
}
function extenderTurnoDesdePago($conn,$habitacion,$bloques,$turnoTag,$monto,$ref){
  $blockHours = ($turnoTag==='turno-2h') ? 2 : 3;
  $nowUTC = nowUTCStrFromArg();
  $tipoHab = tipoDeHabitacion($habitacion, $GLOBALS['SUPER_VIP'], $GLOBALS['VIP_LIST']);
  $open = $conn->prepare("SELECT id, turno, hora_inicio, bloques, ajuste_segundos FROM historial_habitaciones WHERE habitacion=? AND hora_fin IS NULL ORDER BY id DESC LIMIT 1");
  $open->bind_param('i',$habitacion); $open->execute();
  $curOpen = $open->get_result()->fetch_assoc();
  $open->close();

  if(!$curOpen){
    registrarIngresoDigital($conn,'extra',$habitacion,$tipoHab,$monto,"Extra online sin turno abierto",$ref,$bloques,$ref);
    registrarMensajeInterno($conn,'Extra online','No había turno abierto en la hab. '.$habitacion.'. Revisar manualmente.');
    return null;
  }

  $startArgTs = toArgTs($curOpen['hora_inicio'] ?? null);
  $endArgTs = $startArgTs ? endArgTsConAjuste($curOpen['turno'] ?? '', $startArgTs, $curOpen['bloques'] ?? 1, $curOpen['ajuste_segundos'] ?? 0) : null;
  $endUTC = $endArgTs ? gmdate('Y-m-d H:i:s', $endArgTs) : $nowUTC;
  $mins = ($startArgTs!==null && $endArgTs!==null)
    ? max(0, intval(round(($endArgTs - $startArgTs) / 60)))
    : 0;

  $close = $conn->prepare("UPDATE historial_habitaciones SET hora_fin=?, duracion_minutos=? WHERE id=?");
  $close->bind_param('sii',$endUTC,$mins,$curOpen['id']);
  $close->execute(); $close->close();

  $fecha = $endArgTs ? date('Y-m-d', $endArgTs) : argDateToday();
  $precio = precioVigenteInt($conn,$tipoHab,$turnoTag) * $bloques;
  $estado='ocupada';
  $ins=$conn->prepare("INSERT INTO historial_habitaciones (habitacion,tipo,estado,turno,hora_inicio,fecha_registro,precio_aplicado,es_extra,bloques,codigo)
                       VALUES (?,?,?,?,?,?,?,1,?,(SELECT codigo_reserva FROM habitaciones WHERE id=?))");
  $ins->bind_param('isssssiii',$habitacion,$tipoHab,$estado,$turnoTag,$endUTC,$fecha,$precio,$bloques,$habitacion);
  $ins->execute(); $ins->close();

  $st=$conn->prepare("UPDATE habitaciones SET estado='ocupada', tipo_turno=?, hora_inicio=? WHERE id=?");
  $st->bind_param('ssi',$turnoTag,$endUTC,$habitacion); $st->execute(); $st->close();

  registrarIngresoDigital($conn,'extra',$habitacion,$tipoHab,$monto,"Turno extra online (+".($blockHours*$bloques)."h)",null,$bloques,$ref);
  registrarMensajeInterno($conn,'Turno extra online',"Hab. $habitacion — +".($blockHours*$bloques)."h agregadas desde pagos online.");

  return $endArgTs ? ($endArgTs + ($blockHours*3600*$bloques)) : null;
}
function procesarServicioDigital($conn,$habitacion,$items,$monto,$ref){
  if(empty($items)){ return; }
  $ids = array_map('intval', array_column($items,'id'));
  $place = implode(',', $ids);
  $res = $conn->query("SELECT id,nombre,precio,cantidad FROM inventario_productos WHERE id IN ($place) AND activo=1");
  $map=[]; while($r=$res->fetch_assoc()){ $map[(int)$r['id']]=$r; }
  foreach($items as $it){
    $pid = (int)$it['id']; $qty = max(1,(int)$it['cantidad']);
    if(!isset($map[$pid])) continue;
    $nuevo = max(0, ((int)$map[$pid]['cantidad']) - $qty);
    $st=$conn->prepare("UPDATE inventario_productos SET cantidad=? WHERE id=?");
    $st->bind_param('ii',$nuevo,$pid); $st->execute(); $st->close();
  }
  $descParts=[];
  foreach($items as $it){
    $descParts[] = ($it['cantidad'] ?? 1)."x ".($it['nombre'] ?? 'Item');
  }
  $descripcion = "Servicio al cuarto: ".implode(', ',$descParts);
  $tipoHab = tipoDeHabitacion($habitacion, $GLOBALS['SUPER_VIP'], $GLOBALS['VIP_LIST']);
  registrarIngresoDigital($conn,'servicio',$habitacion,$tipoHab,$monto,$descripcion,null,1,$ref);
  registrarMensajeInterno($conn,'Servicio al cuarto',"Hab. $habitacion — ".htmlspecialchars_decode($descripcion,ENT_QUOTES));
}

function finalizarReserva($conn,$payload,$row){
  $categoria = $row['categoria'] ?: ($payload['categoria'] ?? 'comun');
  $turnoTag  = $payload['turno'] ?? 'turno-3h';
  $bloques   = max(1, (int)($payload['bloques'] ?? 1));
  $habitacionElegida = (int)($row['habitacion'] ?? 0);
  $codigo = $payload['codigo'] ?? generarCodigoReserva($conn);

  $habitacion = habitacionLibrePorCategoria($conn,$categoria,$GLOBALS['SUPER_VIP'],$GLOBALS['VIP_LIST'],$habitacionElegida);
  if(!$habitacion){
    registrarMensajeInterno($conn,'Reserva online sin asignar',"No se encontró habitación libre en $categoria para la reserva ".$codigo);
    return ['ok'=>false,'error'=>'No hay habitaciones disponibles ahora mismo.'];
  }

  $horaInicioUTC = nowUTCStrFromArg();
  $fecha = argDateToday();
  $tipoHab = categoriaLabel($categoria);
  $precio = (int)round($row['monto'] ?? 0);
  $estado = 'reservada';

  $ins=$conn->prepare("INSERT INTO historial_habitaciones (habitacion,tipo,estado,turno,hora_inicio,fecha_registro,precio_aplicado,es_extra,bloques,codigo)
                       VALUES (?,?,?,?,?,?,?,0,?,?)");
  $ins->bind_param('isssssiis',$habitacion,$tipoHab,$estado,$turnoTag,$horaInicioUTC,$fecha,$precio,$bloques,$codigo);
  $ins->execute(); $ins->close();

  $st=$conn->prepare("UPDATE habitaciones SET estado='reservada', tipo_turno=?, hora_inicio=?, codigo_reserva=? WHERE id=?");
  $st->bind_param('sssi',$turnoTag,$horaInicioUTC,$codigo,$habitacion); $st->execute(); $st->close();

  $hrs = ($turnoTag==='turno-2h') ? 2*$bloques : (($turnoTag==='turno-3h') ? 3*$bloques : 0);
  $desc = "Reserva online ".($turnoTag==='noche' || $turnoTag==='noche-finde' ? 'Noche' : "+$hrs h");
  registrarIngresoDigital($conn,'reserva',$habitacion,$tipoHab,$precio,$desc,$codigo,$bloques,$row['token']);
  registrarMensajeInterno($conn,'Reserva online',"Hab. $habitacion ($tipoHab) reservada. Código $codigo. Turno: $turnoTag, bloques: $bloques.");

  $inicioTs = toArgTs($horaInicioUTC);
  $finTs = $inicioTs ? turnoEndArgTs($turnoTag,$inicioTs,$bloques) : null;

  return [
    'ok'=>true,
    'habitacion'=>$habitacion,
    'codigo'=>$codigo,
    'turno'=>$turnoTag,
    'bloques'=>$bloques,
    'monto'=>$precio,
    'fin_ts'=>$finTs
  ];
}

function procesarRetornoPago($status,$token,$ctx=[]){
  $conn = db();
  $token = preg_replace('/[^a-zA-Z0-9_-]/','',$token ?? '');
  $row = null;
  $st = $conn->prepare("SELECT * FROM pagos_online WHERE token=? LIMIT 1");
  $st->bind_param('s',$token);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  if(!$row){ return ['ok'=>false,'error'=>'No se encontró la operación.']; }

  $payload = json_decode($row['payload'] ?? '{}', true);
  $statusNorm = normalizarEstadoPago($status, $ctx['collection_status'] ?? null);

  if($statusNorm !== 'success' && !empty($ctx['payment_id'])){
    $verif = verificarPagoMP($ctx['payment_id'], $ctx['preference_id'] ?? ($row['pref_id'] ?? null));
    if(($verif['approved'] ?? false) && ($verif['matches_pref'] ?? true)){
      $statusNorm = 'success';
    }
  }

  if($row['estado'] !== 'aprobado' && $statusNorm==='success'){
    $conn->query("UPDATE pagos_online SET estado='aprobado', updated_at='".nowUTCStrFromArg()."' WHERE id=".(int)$row['id']);

    if($row['tipo']==='reserva'){
      $res = finalizarReserva($conn,$payload,$row);
      return array_merge(['tipo'=>'reserva'], $res);
    }
    if($row['tipo']==='envio'){
      $habitacion = (int)($payload['habitacion'] ?? 0);
      $tipoHab = $habitacion ? tipoDeHabitacion($habitacion,$GLOBALS['SUPER_VIP'],$GLOBALS['VIP_LIST']) : '—';
      registrarIngresoDigital($conn,'envio',$habitacion,$tipoHab,(float)$row['monto'],"Envío de dinero online",$payload['codigo'] ?? null,1,$row['token']);
      registrarMensajeInterno($conn,'Pago online',"Hab. $habitacion envió $".$row['monto']." a caja digital.");
      return ['ok'=>true,'tipo'=>'envio','habitacion'=>$habitacion,'monto'=>$row['monto']];
    }
    if($row['tipo']==='extra'){
      $habitacion = (int)($payload['habitacion'] ?? 0);
      $bloques = max(1,(int)($payload['bloques'] ?? 1));
      $turnoTag = $payload['turno'] ?? 'turno-3h';
      $finTs = extenderTurnoDesdePago($conn,$habitacion,$bloques,$turnoTag,(float)$row['monto'],$row['token']);
      return ['ok'=>true,'tipo'=>'extra','habitacion'=>$habitacion,'fin_ts'=>$finTs,'bloques'=>$bloques,'turno'=>$turnoTag];
    }
    if($row['tipo']==='servicio'){
      $habitacion = (int)($payload['habitacion'] ?? 0);
      procesarServicioDigital($conn,$habitacion,$payload['items'] ?? [],(float)$row['monto'],$row['token']);
      return ['ok'=>true,'tipo'=>'servicio','habitacion'=>$habitacion,'monto'=>$row['monto']];
    }
  }

  return [
    'ok'=>($row['estado']==='aprobado'),
    'tipo'=>$row['tipo'],
    'habitacion'=>(int)($row['habitacion'] ?? 0),
    'monto'=>$row['monto'] ?? 0,
    'error'=> $statusNorm!=='success' ? 'El pago no fue aprobado' : null
  ];
}

function preciosMap($conn){
  $res = $conn->query("SELECT tipo, turno, precio FROM precios_habitaciones");
  $out = [];
  while($r=$res->fetch_assoc()){
    $out[$r['tipo']][$r['turno']] = (float)$r['precio'];
  }
  return $out;
}

/* ========= AJAX simples ========= */
if(isset($_GET['ajax']) && $_GET['ajax']==='disponibilidad'){
  $conn=db();
  $resp = [];
  foreach(['comun','vip','supervip'] as $slug){
    $id = habitacionLibrePorCategoria($conn,$slug,$SUPER_VIP,$VIP_LIST,null);
    $resp[$slug] = $id ? true : false;
  }
  echo json_encode(['ok'=>1,'data'=>$resp]);
  exit;
}

/* ========= Manejo de acciones POST ========= */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['accion'])){
  $conn = db();
  $accion = $_POST['accion'];
  header('Content-Type: application/json; charset=utf-8');

  if($accion==='crear_reserva'){
    $categoria = $_POST['categoria'] ?? 'comun';
    $modalidad = $_POST['modalidad'] ?? 'turno';
    $bloques = max(1, intval($_POST['bloques'] ?? 1));
    $duracion = turnoBlockHoursForToday()===2 ? 'turno-2h' : 'turno-3h';
    $acepta   = !empty($_POST['acepta']);

    if(!$acepta){ echo json_encode(['ok'=>0,'error'=>'Debés confirmar que entendés el inicio del reloj.']); exit; }
    if(!in_array($categoria,['comun','vip','supervip'], true)){ echo json_encode(['ok'=>0,'error'=>'Categoría inválida']); exit; }

    if($modalidad==='noche'){
      if(!isNightAllowedNow()){ echo json_encode(['ok'=>0,'error'=>'El turno noche solo puede reservarse entre las 21:00 y las 10:00.']); exit; }
      list($dow,$hour) = argNowInfo();
      $duracion = isNocheFindeNow($dow,$hour) ? 'noche-finde' : 'noche';
      $bloques = 1;
    }else{
      $duracion = turnoBlockHoursForToday()===2 ? 'turno-2h' : 'turno-3h';
    }

    $habitacion = habitacionLibrePorCategoria($conn,$categoria,$SUPER_VIP,$VIP_LIST,null);
    if(!$habitacion){ echo json_encode(['ok'=>0,'error'=>'No hay habitaciones libres en esa categoría.']); exit; }

    $tipoHab = categoriaLabel($categoria);
    $precioUnit = precioVigenteInt($conn,$tipoHab,$duracion);
    $total = $precioUnit * $bloques;
    if($total <= 0){ echo json_encode(['ok'=>0,'error'=>'Configurá los valores primero.']); exit; }

    $token = bin2hex(random_bytes(8));
    $codigo = generarCodigoReserva($conn);
    list($init,$prefId) = crearPreferenciaMP("Reserva habitación $tipoHab",$total,$token,"Reserva $duracion");
    if(!$init){ echo json_encode(['ok'=>0,'error'=>'No se pudo iniciar el pago.']); exit; }

    $payload = [
      'categoria'=>$categoria,
      'habitacion'=>$habitacion,
      'turno'=>$duracion,
      'bloques'=>$bloques,
      'codigo'=>$codigo
    ];
    guardarPagoPendiente($conn,'reserva',$payload,$prefId,$token,$total,$habitacion,$categoria);

    echo json_encode(['ok'=>1,'init_point'=>$init,'token'=>$token,'codigo'=>$codigo,'habitacion'=>$habitacion]);
    exit;
  }

  if($accion==='enviar_dinero'){
    $habitacion = intval($_POST['habitacion'] ?? 0);
    $monto = floatval($_POST['monto'] ?? 0);
    if($habitacion<=0 || $habitacion>40){ echo json_encode(['ok'=>0,'error'=>'Habitación inválida']); exit; }
    if($monto<=0){ echo json_encode(['ok'=>0,'error'=>'Ingresá un monto']); exit; }
    if(!habitacionOcupada($conn,$habitacion)){ echo json_encode(['ok'=>0,'error'=>'Solo se puede pagar desde una habitación ocupada.']); exit; }

    $categoria = in_array($habitacion,$SUPER_VIP) ? 'supervip' : (in_array($habitacion,$VIP_LIST) ? 'vip' : 'comun');
    $token = bin2hex(random_bytes(8));
    list($init,$prefId) = crearPreferenciaMP("Pago digital hab $habitacion",$monto,$token,"Envío de dinero");
    if(!$init){ echo json_encode(['ok'=>0,'error'=>'No se pudo iniciar el pago.']); exit; }
    $payload = ['habitacion'=>$habitacion];
    guardarPagoPendiente($conn,'envio',$payload,$prefId,$token,$monto,$habitacion,$categoria);
    echo json_encode(['ok'=>1,'init_point'=>$init,'token'=>$token]);
    exit;
  }

  if($accion==='extra'){
    $habitacion = intval($_POST['habitacion'] ?? 0);
    $bloques = max(1, intval($_POST['bloques'] ?? 1));
    if($habitacion<=0 || $habitacion>40){ echo json_encode(['ok'=>0,'error'=>'Habitación inválida']); exit; }
    if(!habitacionOcupada($conn,$habitacion)){ echo json_encode(['ok'=>0,'error'=>'Solo disponible para habitaciones ocupadas.']); exit; }
    $turnoTag = turnoBlockHoursForToday()===2 ? 'turno-2h' : 'turno-3h';
    $categoria = in_array($habitacion,$SUPER_VIP) ? 'supervip' : (in_array($habitacion,$VIP_LIST) ? 'vip' : 'comun');
    $tipoHab = categoriaLabel($categoria);
    $precioUnit = precioVigenteInt($conn,$tipoHab,$turnoTag);
    $total = $precioUnit * $bloques;
    if($total<=0){ echo json_encode(['ok'=>0,'error'=>'Configurá los valores primero.']); exit; }
    $token = bin2hex(random_bytes(8));
    list($init,$prefId) = crearPreferenciaMP("Turno extra hab $habitacion",$total,$token,"Turno extra online");
    if(!$init){ echo json_encode(['ok'=>0,'error'=>'No se pudo iniciar el pago.']); exit; }
    $payload = ['habitacion'=>$habitacion,'bloques'=>$bloques,'turno'=>$turnoTag];
    guardarPagoPendiente($conn,'extra',$payload,$prefId,$token,$total,$habitacion,$categoria);
    echo json_encode(['ok'=>1,'init_point'=>$init,'token'=>$token]);
    exit;
  }

  if($accion==='servicio'){
    $habitacion = intval($_POST['habitacion'] ?? 0);
    $items = json_decode($_POST['items'] ?? '[]', true);
    if($habitacion<=0 || $habitacion>40){ echo json_encode(['ok'=>0,'error'=>'Habitación inválida']); exit; }
    if(!habitacionOcupada($conn,$habitacion)){ echo json_encode(['ok'=>0,'error'=>'Solo disponible para habitaciones ocupadas.']); exit; }
    if(empty($items)){ echo json_encode(['ok'=>0,'error'=>'Seleccioná al menos un producto']); exit; }

    $ids = array_map('intval', array_column($items,'id'));
    $place = implode(',', $ids);
    $res = $conn->query("SELECT id,nombre,precio,cantidad FROM inventario_productos WHERE id IN ($place) AND activo=1");
    $map=[]; while($r=$res->fetch_assoc()){ $map[(int)$r['id']]=$r; }
    $total=0; $detalle=[];
    foreach($items as &$it){
      $pid=(int)$it['id']; $qty=max(1,(int)$it['cantidad']);
      if(!isset($map[$pid])) continue;
      $it['nombre']=$map[$pid]['nombre'];
      if($map[$pid]['cantidad'] < $qty){ echo json_encode(['ok'=>0,'error'=>'Sin stock para '.$map[$pid]['nombre']]); exit; }
      $total += ((float)$map[$pid]['precio'])*$qty;
      $detalle[]=$qty.'x '.$map[$pid]['nombre'];
    }
    if($total<=0){ echo json_encode(['ok'=>0,'error'=>'Sin importe válido']); exit; }

    $categoria = in_array($habitacion,$SUPER_VIP) ? 'supervip' : (in_array($habitacion,$VIP_LIST) ? 'vip' : 'comun');
    $token = bin2hex(random_bytes(8));
    list($init,$prefId) = crearPreferenciaMP("Servicio al cuarto hab $habitacion",$total,$token,"Servicio al cuarto");
    if(!$init){ echo json_encode(['ok'=>0,'error'=>'No se pudo iniciar el pago.']); exit; }
    $payload = ['habitacion'=>$habitacion,'items'=>$items,'detalle'=>implode(', ',$detalle)];
    guardarPagoPendiente($conn,'servicio',$payload,$prefId,$token,$total,$habitacion,$categoria);
    echo json_encode(['ok'=>1,'init_point'=>$init,'token'=>$token]);
    exit;
  }

  echo json_encode(['ok'=>0,'error'=>'Acción no reconocida']);
  exit;
}

$precios = preciosMap(db());
$status = $_GET['status'] ?? ($_GET['collection_status'] ?? null);
$collectionStatus = $_GET['collection_status'] ?? null;
$tokenRet = $_GET['token'] ?? null;
$paymentId = $_GET['payment_id'] ?? ($_GET['collection_id'] ?? null);
$prefBack = $_GET['preference_id'] ?? null;
$feedback = null;
if($tokenRet){
  $feedback = procesarRetornoPago($status,$tokenRet,[
    'collection_status'=>$collectionStatus,
    'payment_id'=>$paymentId,
    'preference_id'=>$prefBack
  ]);
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pagos online — Hotel</title>
  <style>
    :root{
      --bg:#0f172a;
      --card:#0b1220;
      --soft:#111827;
      --accent:#7c3aed;
      --accent2:#22c55e;
      --text:#f8fafc;
      --muted:#94a3b8;
      --border:#1f2937;
    }
    *{box-sizing:border-box;font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;}
    body{margin:0;background:linear-gradient(140deg,#0f172a,#0b1220);color:var(--text);}
    header{padding:18px 16px;border-bottom:1px solid var(--border);position:sticky;top:0;background:rgba(12,20,36,0.95);backdrop-filter:blur(8px);z-index:5;}
    .brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:18px;}
    .tabs{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;}
    .tabs button{background:var(--soft);color:var(--text);border:1px solid var(--border);border-radius:10px;padding:10px 14px;cursor:pointer;font-weight:700;}
    .tabs button.active{background:var(--accent);border-color:var(--accent);box-shadow:0 8px 24px rgba(124,58,237,0.3);}
    main{padding:16px;max-width:1100px;margin:0 auto;}
    .card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px;box-shadow:0 10px 30px rgba(0,0,0,0.35);margin-bottom:16px;}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;}
    .label{color:var(--muted);font-size:13px;margin-bottom:4px;display:block;}
    input, select, textarea{width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:#0f172a;color:var(--text);}
    textarea{min-height:90px;resize:vertical;}
    .btn{background:var(--accent);border:none;color:var(--text);padding:12px 16px;border-radius:12px;cursor:pointer;font-weight:800;width:100%;}
    .btn.secondary{background:#1f2937;}
    .alert{padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,0.08);background:rgba(255,255,255,0.04);color:var(--muted);font-size:14px;}
    .avail{display:flex;align-items:center;gap:10px;padding:12px;border-radius:12px;background:var(--soft);border:1px solid var(--border);}
    .badge{padding:4px 8px;border-radius:999px;font-weight:800;font-size:12px;}
    .ok{background:rgba(34,197,94,0.18);color:#4ade80;}
    .ko{background:rgba(239,68,68,0.18);color:#f87171;}
    .success{background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.4);padding:12px;border-radius:12px;}
    .danger{color:#fda4af;}
    .pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,0.06);border:1px solid var(--border);}
    .list{margin:8px 0 0 0;padding-left:18px;color:var(--muted);}
    .price-line{display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border);}
    .muted{color:var(--muted);}
    .tag{padding:4px 8px;border-radius:8px;background:rgba(255,255,255,0.05);font-size:12px;}
    .divider{height:1px;background:var(--border);margin:12px 0;}
    .checkbox-row{display:inline-flex;gap:8px;align-items:flex-start;margin:12px 0;justify-content:flex-start;width:auto;}
    .checkbox-row input[type=checkbox]{margin:2px 0 0;}
    .checkbox-row span{line-height:1.4;}
    .confirm-section{margin-top:12px;padding:12px;border-radius:12px;border:1px solid var(--border);background:var(--soft);display:flex;flex-direction:column;gap:8px;align-items:flex-start;}
    .confirm-total{display:flex;justify-content:flex-start;}
    .map-link{color:var(--accent2);text-decoration:none;font-weight:700;}
    .map-link:hover{text-decoration:underline;}
    .map-link-cta{display:inline-flex;gap:6px;align-items:center;margin-top:10px;}
    @media(max-width:600px){
      header{position:static;}
      .tabs button{flex:1 1 45%;justify-content:center;}
      .checkbox-row{flex-direction:column;align-items:flex-start;}
      .confirm-total{width:100%;}
      .confirm-total .pill{width:100%;justify-content:center;}
    }
  </style>
</head>
<body>
  <header>
    <div class="brand">
      <span>💳</span> <span>Pagos online & reservas</span>
    </div>
    <div class="tabs">
      <button class="tab-btn active" data-tab="reserva">Reserva</button>
      <button class="tab-btn" data-tab="envio">Pagar Habitación en la que estoy</button>
      <button class="tab-btn" data-tab="extra">Turno extra</button>
      <button class="tab-btn" data-tab="servicio">Servicio al cuarto</button>
    </div>
    <a class="map-link map-link-cta" href="<?= htmlspecialchars($HOTEL_MAP_LINK) ?>" target="_blank" rel="noopener">📍 Ver mapa</a>
  </header>
  <main>
    <?php if($feedback): ?>
      <div class="card" id="feedback">
        <?php if($feedback['ok']): ?>
          <div class="success">
            <h2 style="margin-top:0;">✅ Pago confirmado</h2>
            <p>Código: <strong><?= htmlspecialchars($feedback['codigo'] ?? ($feedback['token'] ?? '')) ?></strong></p>
            <?php if(!empty($feedback['habitacion'])): ?>
              <p>Habitación asignada: <strong><?= (int)$feedback['habitacion'] ?></strong></p>
            <?php endif; ?>
            <?php if(!empty($feedback['turno'])): ?>
              <p>Turno: <strong><?= htmlspecialchars($feedback['turno']) ?></strong> — bloques: <?= (int)($feedback['bloques'] ?? 1) ?></p>
            <?php endif; ?>
            <?php if(!empty($feedback['monto'])): ?>
              <p>Total abonado: $ <?= number_format((float)$feedback['monto'],0,',','.') ?></p>
            <?php endif; ?>
            <p>Dirección del hotel: <?= htmlspecialchars($HOTEL_ADDRESS) ?> — <a class="map-link" href="<?= htmlspecialchars($HOTEL_MAP_LINK) ?>" target="_blank" rel="noopener">Ver mapa</a></p>
            <div id="timer-line" style="margin-top:10px; font-weight:700;"></div>
          </div>
        <?php else: ?>
          <div class="danger">
            <h2 style="margin-top:0;">⚠️ Pago pendiente</h2>
            <p><?= htmlspecialchars($feedback['error'] ?? 'Revisá el estado de tu operación.') ?></p>
          </div>
        <?php endif; ?>
      </div>
      <div class="divider"></div>
    <?php endif; ?>

    <div class="card">
      <h3 style="margin-top:0;">Disponibilidad en vivo</h3>
      <div class="grid" id="availability">
        <div class="avail"><span>Común</span><span class="badge" id="avail-comun">...</span></div>
        <div class="avail"><span>VIP</span><span class="badge" id="avail-vip">...</span></div>
        <div class="avail"><span>Super VIP</span><span class="badge" id="avail-supervip">...</span></div>
      </div>
    </div>

    <section id="tab-reserva" class="tab-section card">
      <h2 style="margin-top:0;">1) Reservar una habitación</h2>
      <p class="muted">El reloj empieza a correr en cuanto se confirma el pago. Debés aceptar la confirmación antes de pagar.</p>
      <div class="grid">
        <div>
          <label class="label">Tipo de habitación</label>
          <select id="res-categoria">
            <option value="comun">Común</option>
            <option value="vip">VIP</option>
            <option value="supervip">Super VIP</option>
          </select>
        </div>
        <div>
          <label class="label">Modalidad</label>
          <select id="res-modalidad">
            <option value="turno">Turno</option>
            <option value="noche">Noche (21 a 10)</option>
          </select>
        </div>
        <div id="turno-fields">
          <label class="label">Duración de turno (automática)</label>
          <div class="pill" id="res-duracion-auto">Cargando...</div>
        </div>
        <div id="bloques-wrap">
          <label class="label">Cantidad de turnos</label>
          <input type="number" id="res-bloques" value="1" min="1" step="1">
        </div>
      </div>
      <div class="divider"></div>
      <div class="alert">Precios vigentes se calculan al momento del pago. El turno noche solo está disponible de 21:00 a 10:00.</div>
      <div class="confirm-section">
        <div class="confirm-total">
          <div id="res-total" class="pill">Total estimado: $ --</div>
        </div>
        <label class="checkbox-row"> <input type="checkbox" id="res-acepta"> <span>Entiendo que el reloj comienza a correr una vez que pago.</span>
        </label>
      </div>
      <div style="margin-top:12px;">
        <button class="btn" id="res-btn">Pagar y reservar</button>
      </div>
    </section>

    <section id="tab-envio" class="tab-section card" style="display:none;">
      <h2 style="margin-top:0;">2) Enviar dinero a caja</h2>
      <div class="grid">
        <div>
          <label class="label">Ingresá el numero de tu habitación</label>
          <input type="number" id="env-hab" min="1" max="40" placeholder="Ej: 7">
        </div>
        <div>
          <label class="label">Monto</label>
          <input type="number" id="env-monto" min="1" step="1" placeholder="Ej: 5000">
        </div>
      </div>
      <div class="alert">Solo se permite pagar desde habitaciones ocupadas. Aparecerá en el panel como ingreso digital.</div>
      <button class="btn" id="env-btn">Enviar</button>
    </section>

    <section id="tab-extra" class="tab-section card" style="display:none;">
      <h2 style="margin-top:0;">3) Comprar turno extra</h2>
      <div class="grid">
        <div>
          <label class="label">Habitación ocupada</label>
          <input type="number" id="extra-hab" min="1" max="40" placeholder="Ej: 15">
        </div>
        <div>
          <label class="label">Cantidad de turnos extra</label>
          <input type="number" id="extra-bloques" value="1" min="1" step="1">
        </div>
      </div>
      <div class="alert">No genera turno nocturno nuevo, pero sí suma horas sobre un turno noche existente. El largo del turno sigue la lógica de 2h/3h del día.</div>
      <div id="extra-total" class="pill">Total estimado: $ --</div>
      <button class="btn" id="extra-btn">Agregar turno</button>
    </section>

    <section id="tab-servicio" class="tab-section card" style="display:none;">
      <h2 style="margin-top:0;">4) Comprar servicio al cuarto</h2>
      <div class="grid">
        <div>
          <label class="label">Habitación ocupada</label>
          <input type="number" id="serv-hab" min="1" max="40" placeholder="Ej: 22">
        </div>
      </div>
      <div class="divider"></div>
      <div id="serv-items" class="grid"></div>
      <div class="pill" id="serv-total">Total estimado: $ --</div>
      <button class="btn" id="serv-btn" style="margin-top:12px;">Pagar servicio</button>
    </section>
  </main>
<script>
const PRECIOS = <?= json_encode($precios, JSON_UNESCAPED_UNICODE) ?>;
const BLOCK_HOURS = <?= turnoBlockHoursForToday() ?>;
const SUCCESS_FIN_TS = <?= isset($feedback['fin_ts']) && $feedback['fin_ts'] ? (int)$feedback['fin_ts'] : 'null' ?>;
const AUTO_TURNO_TAG = BLOCK_HOURS===2 ? 'turno-2h' : 'turno-3h';
const AUTO_TURNO_LABEL = BLOCK_HOURS===2 ? 'Turno 2 h' : 'Turno 3 h';

document.getElementById('res-duracion-auto').textContent = `Hoy se asigna ${AUTO_TURNO_LABEL} automáticamente`;

function formatoPrecio(tipo, turno){
  const val = (PRECIOS?.[tipo]?.[turno]) ?? 0;
  return parseInt(val,10) || 0;
}
function esNocheFindeAhora(){
  const now = new Date();
  const dow = now.getDay(); // 0=Dom..6=Sab
  const hour = now.getHours();
  if(dow===5 && hour>=21) return true;
  if(dow===6 && (hour<10 || hour>=21)) return true;
  if(dow===0 && hour<10) return true;
  return false;
}
function actualizarTotales(){
  const cat = document.getElementById('res-categoria').value;
  const mod = document.getElementById('res-modalidad').value;
  const bloques = Math.max(1, parseInt(document.getElementById('res-bloques').value || '1',10));
  const tipoLabel = cat==='comun'?'Común':(cat==='vip'?'VIP':'Super VIP');

  if(mod==='noche'){
    const turnoNoche = esNocheFindeAhora() ? 'noche-finde' : 'noche';
    const precioNoche = formatoPrecio(tipoLabel, turnoNoche);
    document.getElementById('res-total').textContent = `Total estimado: $ ${precioNoche.toLocaleString('es-AR')}`;
  }else{
    const precio = formatoPrecio(tipoLabel, AUTO_TURNO_TAG);
    const total = precio * bloques;
    document.getElementById('res-total').textContent = `Total estimado: $ ${total.toLocaleString('es-AR')}`;
  }

  const extraBloques = parseInt(document.getElementById('extra-bloques').value || '1',10);
  const extraHab = document.getElementById('extra-hab').value.trim();
  let extraTipo = 'Común';
  if(['20','21'].includes(extraHab)) extraTipo='Super VIP';
  else if(['3','4','11','12','13','28','29','30','37','38'].includes(extraHab)) extraTipo='VIP';
  const extraPrecio = formatoPrecio(extraTipo, BLOCK_HOURS===2?'turno-2h':'turno-3h');
  document.getElementById('extra-total').textContent = `Total estimado: $ ${(extraPrecio*extraBloques).toLocaleString('es-AR')}`;
}
['res-categoria','res-bloques','res-modalidad','extra-bloques','extra-hab'].forEach(id=>{
  const el=document.getElementById(id);
  if(el) el.addEventListener('input', actualizarTotales);
});
actualizarTotales();

function toggleModalidad(){
  const mod = document.getElementById('res-modalidad').value;
  document.getElementById('turno-fields').style.display = mod==='turno' ? 'block' : 'none';
  document.getElementById('bloques-wrap').style.display = mod==='turno' ? 'block' : 'none';
  if(mod==='noche'){
    document.getElementById('res-bloques').value = 1;
  }
  actualizarTotales();
}
document.getElementById('res-modalidad').addEventListener('change', toggleModalidad);
toggleModalidad();

async function disponibilidad(){
  try{
    const r = await fetch('?ajax=disponibilidad',{cache:'no-store'});
    const j = await r.json();
    if(!j.ok) return;
    ['comun','vip','supervip'].forEach(slug=>{
      const el = document.getElementById('avail-'+slug);
      const ok = j.data?.[slug];
      el.textContent = ok ? 'Disponible' : 'Sin cupo';
      el.className = 'badge ' + (ok ? 'ok':'ko');
    });
  }catch(e){}
}
setInterval(disponibilidad, 5000); disponibilidad();

function activarTab(tab){
  document.querySelectorAll('.tab-btn').forEach(btn=>{
    btn.classList.toggle('active', btn.dataset.tab===tab);
  });
  document.querySelectorAll('.tab-section').forEach(sec=>{
    sec.style.display = sec.id === 'tab-'+tab ? 'block' : 'none';
  });
}
document.querySelectorAll('.tab-btn').forEach(btn=>{
  btn.addEventListener('click', ()=>activarTab(btn.dataset.tab));
});

async function crearReserva(){
  const payload = {
    accion:'crear_reserva',
    categoria: document.getElementById('res-categoria').value,
    modalidad: document.getElementById('res-modalidad').value,
    duracion: AUTO_TURNO_TAG,
    bloques: document.getElementById('res-bloques').value,
    acepta: document.getElementById('res-acepta').checked ? 1 : 0
  };
  const r = await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(payload)});
  const j = await r.json();
  if(!j.ok){ alert(j.error||'No se pudo crear la reserva'); return; }
  alert('Recordá: el reloj comienza a correr una vez que se confirma el pago.');
  window.location.href = j.init_point;
}
async function enviarDinero(){
  const payload = {
    accion:'enviar_dinero',
    habitacion: document.getElementById('env-hab').value,
    monto: document.getElementById('env-monto').value
  };
  const r = await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(payload)});
  const j = await r.json();
  if(!j.ok){ alert(j.error||'No se pudo iniciar el pago'); return; }
  window.location.href = j.init_point;
}
async function comprarExtra(){
  const payload = {
    accion:'extra',
    habitacion: document.getElementById('extra-hab').value,
    bloques: document.getElementById('extra-bloques').value
  };
  const r = await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(payload)});
  const j = await r.json();
  if(!j.ok){ alert(j.error||'No se pudo iniciar el pago'); return; }
  window.location.href = j.init_point;
}
function buildServicios(){
  const cont = document.getElementById('serv-items');
  cont.innerHTML = '';
  const items = <?= json_encode((function(){
    $conn=db();
    $res=$conn->query("SELECT id,nombre,precio,cantidad FROM inventario_productos WHERE activo=1 AND cantidad>0 ORDER BY nombre ASC");
    $rows=[]; while($r=$res->fetch_assoc()){ $rows[]=$r; }
    return $rows;
  })(), JSON_UNESCAPED_UNICODE); ?>;
  if(!items.length){
    cont.innerHTML = '<p class="muted">No hay productos cargados.</p>';
    return;
  }
  items.forEach(it=>{
    const wrap = document.createElement('div');
    wrap.innerHTML = `
      <div class="tag" style="margin-bottom:6px;">${it.nombre} — $${it.precio}</div>
      <input type="number" min="0" max="${it.cantidad}" value="0" data-id="${it.id}" data-precio="${it.precio}" class="serv-qty">
    `;
    cont.appendChild(wrap);
  });
  cont.addEventListener('input', actualizarTotalServicio);
}
function actualizarTotalServicio(){
  let total = 0;
  document.querySelectorAll('.serv-qty').forEach(input=>{
    const qty = parseInt(input.value||'0',10);
    const precio = parseFloat(input.dataset.precio||'0');
    total += qty*precio;
  });
  document.getElementById('serv-total').textContent = `Total estimado: $ ${total.toLocaleString('es-AR')}`;
}
buildServicios();
actualizarTotalServicio();

async function pagarServicio(){
  const items=[];
  document.querySelectorAll('.serv-qty').forEach(input=>{
    const qty = parseInt(input.value||'0',10);
    if(qty>0){
      items.push({id:input.dataset.id,cantidad:qty});
    }
  });
  const payload = {
    accion:'servicio',
    habitacion: document.getElementById('serv-hab').value,
    items: JSON.stringify(items)
  };
  const r = await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(payload)});
  const j = await r.json();
  if(!j.ok){ alert(j.error||'No se pudo iniciar el pago'); return; }
  window.location.href = j.init_point;
}

document.getElementById('res-btn').addEventListener('click', crearReserva);
document.getElementById('env-btn').addEventListener('click', enviarDinero);
document.getElementById('extra-btn').addEventListener('click', comprarExtra);
document.getElementById('serv-btn').addEventListener('click', pagarServicio);

if(SUCCESS_FIN_TS){
  const line = document.getElementById('timer-line');
  const tick = ()=>{
    const now = Math.floor(Date.now()/1000);
    const diff = SUCCESS_FIN_TS - now;
    const sign = diff < 0 ? '-' : '';
    const abs = Math.abs(diff);
    const h = Math.floor(abs/3600);
    const m = Math.floor((abs%3600)/60);
    line.textContent = `Tiempo restante estimado: ${sign}${h.toString().padStart(2,'0')}:${m.toString().padStart(2,'0')}`;
  };
  tick();
  setInterval(tick, 1000);
}
</script>
</body>
</html>