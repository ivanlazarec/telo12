<?php

$servername = "127.0.0.1";
$username   = "u460517132_F5bOi";
$password   = "mDjVQbpI5A";
$dbname     = "u460517132_GxbHQ";

$conn = new mysqli($servername, $username, $password, $dbname);

function renderPage($heading, $subheading, $details, $status = 'info', $code = null)
{
    $accentColors = [
        'success' => '#2BB673',
        'warning' => '#FF9F1C',
        'info'    => '#4D9DE0',
    ];

    $accent   = $accentColors[$status] ?? $accentColors['info'];
    $codeHTML = '';

    if ($code !== null) {
        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $codeHTML = "<div class=\"code-card\"><span class=\"label\">Código</span><span class=\"code\">$safeCode</span></div>";
    }

    echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$heading</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent: $accent;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: radial-gradient(circle at 20% 20%, rgba(77,157,224,0.2), transparent 35%),
                        radial-gradient(circle at 80% 0%, rgba(43,182,115,0.18), transparent 30%),
                        #0b1222;
            color: #e8edf5;
            padding: 20px;
        }

        .card {
            width: min(640px, 100%);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.35);
            border-radius: 18px;
            padding: 32px;
            backdrop-filter: blur(14px);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: var(--accent);
            font-weight: 600;
            font-size: 14px;
            letter-spacing: 0.02em;
        }

        .pulse {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.6);
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(77, 157, 224, 0.35); }
            70% { box-shadow: 0 0 0 14px rgba(77, 157, 224, 0); }
            100% { box-shadow: 0 0 0 0 rgba(77, 157, 224, 0); }
        }

        h1 {
            margin: 16px 0 8px;
            font-size: clamp(28px, 4vw, 36px);
            letter-spacing: -0.02em;
            color: #f4f7fb;
        }

        h2 {
            margin: 12px 0 6px;
            font-size: clamp(24px, 4vw, 32px);
            color: #f4f7fb;
        }

        p {
            margin: 0 0 14px;
            color: #c7d2e4;
            line-height: 1.6;
        }

        .code-card {
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 18px;
            margin: 18px 0;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 14px;
            letter-spacing: 0.05em;
        }

        .label {
            font-size: 12px;
            color: #9fb3d9;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .code {
            font-weight: 700;
            font-size: clamp(26px, 5vw, 30px);
            color: var(--accent);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 8px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.09), rgba(255, 255, 255, 0.04));
            color: #f4f7fb;
            text-decoration: none;
            font-weight: 600;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
            border-color: rgba(255, 255, 255, 0.32);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.25);
        }

        .footnote {
            margin-top: 22px;
            font-size: 14px;
            color: #9fb3d9;
        }

        @media (max-width: 480px) {
            .card { padding: 24px; }
            .actions { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="badge"><span class="pulse"></span><span>$subheading</span></div>
        <h1>$heading</h1>
        <p>$details</p>
        $codeHTML
        <div class="actions">
            <a class="btn" href="https://maps.app.goo.gl/25ov8RLoWqhzAZEn9" target="_blank" rel="noopener">Ver ubicación</a>
            <a class="btn" href="javascript:location.reload()">Actualizar estado</a>
        </div>
        <p class="footnote">Guardá este comprobante. Te servirá para ingresar y agilizar tu check-in.</p>
    </main>
</body>
</html>
HTML;
    exit;
}

if (!isset($_GET['payment_id'])) {
    renderPage(
        'Pago procesado',
        'Sin datos de reserva',
        'No pudimos identificar tu reserva. Si creés que es un error, volvé a la página anterior o contactanos.',
        'warning'
    );
}
$payment_id = $conn->real_escape_string($_GET['payment_id']);
$q          = $conn->query("SELECT codigo FROM pagos_mp WHERE payment_id='$payment_id' LIMIT 1");

if ($q->num_rows == 0) {
    renderPage(
        'Procesando pago',
        'Estamos registrando tu reserva',
        'Tu pago fue aprobado pero la reserva todavía se está vinculando al sistema. Actualizá esta página en unos segundos para ver tu código.',
        'info'
    );
}

$codigo = $q->fetch_assoc()['codigo'];

renderPage(
    '¡Reserva confirmada!',
    'Todo listo para tu estadía',
    'Guardalo: lo vas a usar para ingresar y comunicar en el portero. El reloj del turno ya está corriendo.',
    'success',
    $codigo
);
