<?php
header('Content-Type: application/json; charset=utf-8');
$servername = "127.0.0.1";
$username   = "u460517132_F5bOi";
$password   = "mDjVQbpI5A";
$dbname     = "u460517132_GxbHQ";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { die(json_encode(['error'=>'DB error'])); }

$res = $conn->query("SELECT id, estado FROM habitaciones ORDER BY id ASC");
$out = [];
while($r = $res->fetch_assoc()){ $out[] = $r; }
echo json_encode($out);
