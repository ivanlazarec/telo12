<?php
$statusParam = strtolower(trim($_GET['status'] ?? $_GET['collection_status'] ?? ''));
$paymentId   = trim($_GET['payment_id'] ?? $_GET['collection_id'] ?? '');
$prefId      = trim($_GET['preference_id'] ?? '');

function viewForStatus(string $status): array {
    $views = [
        'approved' => [
            'badge' => 'Pago confirmado',
            'title' => '¡Gracias! El dinero se envió exitosamente',
            'message' => 'Registramos el pago y avisamos al equipo para vincularlo con tu habitación. Si necesitás hacer otro envío, podés volver al formulario.',
            'tone' => 'success'
        ],
        'pending' => [
            'badge' => 'Pago en proceso',
            'title' => 'Estamos procesando tu envío',
            'message' => 'Mercado Pago está revisando la operación. Si queda pendiente o demorada, podrás reintentar desde el formulario.',
            'tone' => 'info'
        ],
        'failure' => [
            'badge' => 'Pago no completado',
            'title' => 'Tu envío no pudo finalizarse',
            'message' => 'El pago se canceló o fue rechazado. Revisá los datos y probá nuevamente; no se realizó ningún cargo.',
            'tone' => 'warning'
        ]
    ];

    if (in_array($status, ['approved', 'success'], true)) {
        return $views['approved'];
    }

    if (in_array($status, ['pending', 'in_process', 'in_mediation'], true)) {
        return $views['pending'];
    }

    if (in_array($status, ['failure', 'rejected', 'cancelled'], true)) {
        return $views['failure'];
    }

    return [
        'badge' => 'Estado a confirmar',
        'title' => 'Revisá tu envío digital',
        'message' => 'No pudimos identificar el estado del pago. Si ya completaste la operación, actualizá la página en unos segundos o volvé al formulario para reintentar.',
        'tone' => 'info'
    ];
}

$view = viewForStatus($statusParam);

$paymentInfo = '';
if ($paymentId !== '') {
    $paymentInfo .= '<p class="meta">ID de pago: <strong>' . htmlspecialchars($paymentId, ENT_QUOTES, 'UTF-8') . '</strong></p>';
}
if ($prefId !== '') {
    $paymentInfo .= '<p class="meta">Preferencia: ' . htmlspecialchars($prefId, ENT_QUOTES, 'UTF-8') . '</p>';
}

$tones = [
    'success' => ['#DCFCE7', '#16A34A'],
    'info'    => ['#E0F2FE', '#0EA5E9'],
    'warning' => ['#FEF9C3', '#D97706']
];

[$bgTone, $textTone] = $tones[$view['tone']] ?? $tones['info'];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Resultado del envío</title>
  <style>
    :root{
      --bg:#0f172a;
      --card:#0b1222;
      --text:#e5e7eb;
      --muted:#9ca3af;
      --accent-bg: <?= $bgTone ?>;
      --accent-text: <?= $textTone ?>;
    }
    *{box-sizing:border-box;font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;}
    body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:22px;background:radial-gradient(circle at 20% 20%,rgba(14,165,233,.08),transparent 35%),radial-gradient(circle at 80% 0%,rgba(22,163,74,.08),transparent 28%),var(--bg);color:var(--text);}
    .card{width:min(720px,100%);background:var(--card);border:1px solid rgba(255,255,255,.05);border-radius:18px;padding:26px;box-shadow:0 22px 60px rgba(0,0,0,.35);}    
    .badge{display:inline-flex;align-items:center;gap:10px;padding:10px 14px;border-radius:999px;background:var(--accent-bg);color:var(--accent-text);font-weight:700;font-size:14px;letter-spacing:.01em;}
    .pulse{width:10px;height:10px;border-radius:50%;background:var(--accent-text);box-shadow:0 0 0 0 rgba(255,255,255,.6);animation:pulse 1.6s infinite;}
    @keyframes pulse{0%{box-shadow:0 0 0 0 rgba(255,255,255,.35);}70%{box-shadow:0 0 0 14px rgba(255,255,255,0);}100%{box-shadow:0 0 0 0 rgba(255,255,255,0);}}
    h1{margin:14px 0 6px;font-size:clamp(28px,4vw,34px);letter-spacing:-.02em;}
    p{margin:0 0 12px;color:var(--muted);line-height:1.6;}
    .meta{color:#cbd5e1;font-size:14px;margin:4px 0;}
    .actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:14px;}
    .btn{display:inline-flex;align-items:center;gap:8px;padding:12px 16px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:linear-gradient(135deg,rgba(255,255,255,.12),rgba(255,255,255,.04));color:var(--text);text-decoration:none;font-weight:700;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease;}
    .btn:hover{transform:translateY(-1px);border-color:rgba(255,255,255,.32);box-shadow:0 12px 30px rgba(0,0,0,.22);}    
    .note{margin-top:18px;font-size:13px;color:#94a3b8;}
    @media(max-width:520px){body{padding:16px;} .card{padding:22px;} .actions{flex-direction:column;} .btn{width:100%;justify-content:center;}}
  </style>
</head>
<body>
  <main class="card">
    <div class="badge"><span class="pulse"></span><span><?= htmlspecialchars($view['badge'], ENT_QUOTES, 'UTF-8') ?></span></div>
    <h1><?= htmlspecialchars($view['title'], ENT_QUOTES, 'UTF-8') ?></h1>
    <p><?= htmlspecialchars($view['message'], ENT_QUOTES, 'UTF-8') ?></p>
    <?= $paymentInfo ?>
    <div class="actions">
      <a class="btn" href="envio-dinero.php">Hacer otro envío</a>
      <a class="btn" href="javascript:location.reload()">Actualizar estado</a>
    </div>
    <p class="note">Si necesitás asistencia, compartí el identificador de pago para que podamos ayudarte más rápido.</p>
  </main>
</body>
</html>