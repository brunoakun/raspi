<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$dispositivos = [
    [
        'id' => 'luz_01',
        'nombre' => 'Luz uno',
        'ip' => '192.168.1.50',
        'canal' => 0
    ],
    [
        'id' => 'luz_02',
        'nombre' => 'Luz dos',
        'ip' => '192.168.1.51',
        'canal' => 0
    ]
];

function llamadaShellyRpc($ip, $metodo, $params = [])
{
    $url = "http://{$ip}/rpc/{$metodo}";
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    $respuesta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error || $httpCode !== 200) {
        return ['error' => true, 'mensaje' => $error ?: "HTTP $httpCode"];
    }
    return json_decode($respuesta, true);
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $id = $_GET['id'] ?? '';
    $accion = $_GET['accion'] ?? 'status';
    $dev = null;
    foreach ($dispositivos as $d) {
        if ($d['id'] === $id) {
            $dev = $d;
            break;
        }
    }
    if (!$dev) {
        echo json_encode(['error' => true, 'mensaje' => 'Dispositivo no encontrado']);
        exit;
    }
    if ($accion === 'toggle') {
        $res = llamadaShellyRpc($dev['ip'], 'Switch.Toggle', ['id' => $dev['canal']]);
    } elseif ($accion === 'on') {
        $res = llamadaShellyRpc($dev['ip'], 'Switch.Set', ['id' => $dev['canal'], 'on' => 'true']);
    } elseif ($accion === 'off') {
        $res = llamadaShellyRpc($dev['ip'], 'Switch.Set', ['id' => $dev['canal'], 'on' => 'false']);
    } else {
        $res = llamadaShellyRpc($dev['ip'], 'Switch.GetStatus', ['id' => $dev['canal']]);
    }
    echo json_encode($res);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <title>Panel de Control Shelly</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f1f5f9;
            margin: 0;
            padding: 24px;
            display: flex;
            justify-content: center;
        }

        .container {
            width: 100%;
            max-width: 520px;
        }

        h1 {
            font-size: 1.4rem;
            color: #1e293b;
            margin-bottom: 20px;
            text-align: center;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 14px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .nombre {
            font-weight: 600;
            color: #0f172a;
            font-size: 1.05rem;
        }

        .ip {
            font-size: 0.8rem;
            color: #64748b;
        }

        .estado {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: bold;
            padding: 3px 8px;
            border-radius: 9999px;
            width: fit-content;
            margin-top: 4px;
        }

        .estado-on {
            background: #dcfce7;
            color: #15803d;
        }

        .estado-off {
            background: #f1f5f9;
            color: #64748b;
        }

        .estado-err {
            background: #fee2e2;
            color: #b91c1c;
        }

        .btn-toggle {
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 20px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-toggle:disabled {
            opacity: 0.5;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>Relés Shelly Locales</h1>
        <?php foreach ($dispositivos as $dev): ?>
            <div class="card"
                 id="card-<?= htmlspecialchars($dev['id']) ?>">
                <div class="info">
                    <span class="nombre"><?= htmlspecialchars($dev['nombre']) ?></span>
                    <span class="ip"><?= htmlspecialchars($dev['ip']) ?></span>
                    <span class="estado estado-off"
                          id="status-<?= htmlspecialchars($dev['id']) ?>">Consultando...</span>
                </div>
                <div>
                    <button class="btn-toggle"
                            onclick="ejecutar('<?= htmlspecialchars($dev['id']) ?>', 'toggle')">Conmutar</button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <script>
        function actualizarEstado(id) {
            const badge = document.getElementById('status-' + id);
            fetch(`?ajax=1&id=${id}&accion=status`)
                .then(r => r.json())
                .then(data => {
                    if (data && data.output !== undefined) {
                        badge.textContent = data.output ? 'ENCENDIDO' : 'APAGADO';
                        badge.className = data.output ? 'estado estado-on' : 'estado estado-off';
                    } else {
                        badge.textContent = 'DESCONECTADO';
                        badge.className = 'estado estado-err';
                    }
                })
                .catch(() => {
                    badge.textContent = 'ERROR RED';
                    badge.className = 'estado estado-err';
                });
        }
        function ejecutar(id, accion) {
            const card = document.getElementById('card-' + id);
            const btn = card.querySelector('button');
            btn.disabled = true;
            fetch(`?ajax=1&id=${id}&accion=${accion}`)
                .then(r => r.json())
                .then(() => actualizarEstado(id))
                .catch(err => { console.error(err); alert('Error al enviar orden'); })
                .finally(() => btn.disabled = false);
        }
        document.addEventListener('DOMContentLoaded', () => {
            <?php foreach ($dispositivos as $dev): ?>
                actualizarEstado('<?= htmlspecialchars($dev['id']) ?>');
            <?php endforeach; ?>
        });
    </script>
</body>

</html>