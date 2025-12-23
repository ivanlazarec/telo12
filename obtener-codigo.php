<?php
// Devuelve el código de reserva asignado a una habitación (si existe)
header('Content-Type: application/json; charset=utf-8');

$servername = "127.0.0.1";
$username   = "u460517132_F5bOi";
$password   = "mDjVQbpI5A";
$dbname     = "u460517132_GxbHQ";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  http_response_code(500);
  echo json_encode(['ok'=>0,'error'=>'DB error']);
  exit;
}

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
  echo json_encode(['ok'=>0,'error'=>'ID inválido']);
  exit;
}

$conn->set_charset('utf8mb4');

$stmt = $conn->prepare("SELECT codigo_reserva FROM habitaciones WHERE id=? LIMIT 1");
$stmt->bind_param('i',$id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

$codigo = $res['codigo_reserva'] ?? null;

if(!$codigo){
  $stmt = $conn->prepare("SELECT codigo FROM historial_habitaciones WHERE habitacion=? AND hora_fin IS NULL ORDER BY id DESC LIMIT 1");
  $stmt->bind_param('i',$id);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  $codigo = $res['codigo'] ?? null;
}

echo json_encode(['ok'=>1,'codigo'=>$codigo ?: null]);
