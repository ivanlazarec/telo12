<?php
header('Content-Type: application/json');

$servername = "127.0.0.1";
$username   = "u460517132_F5bOi";
$password   = "mDjVQbpI5A";
$dbname     = "u460517132_GxbHQ";
$conn = new mysqli($servername, $username, $password, $dbname);

$id = intval($_GET['id'] ?? 0);

$q = $conn->prepare("SELECT codigo FROM historial_habitaciones WHERE habitacion=? AND hora_fin IS NULL ORDER BY id DESC LIMIT 1");
$q->bind_param('i', $id);
$q->execute();
$r = $q->get_result()->fetch_assoc();

echo json_encode(['codigo' => $r['codigo'] ?? null]);
