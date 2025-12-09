<?php
date_default_timezone_set('America/Argentina/Buenos_Aires');
echo "<h3>Hora PHP local Argentina:</h3>";
echo date('Y-m-d H:i:s');
echo "<hr>";
echo "<h3>Hora del servidor (sin forzar zona horaria):</h3>";
echo (new DateTime())->format('Y-m-d H:i:s');
?>
