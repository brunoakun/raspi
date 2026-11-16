<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Configuración de los 4 relés Shelly (cada uno con 4 canales)
 */
$delay = "500"; // Pausa entre canales/relé en milisegundos
$configuracion = [
    'rele_uno' => [
        'id' => 'rele_01',
        'nombre' => 'Relé Uno',
        'ip' => '192.168.1.79',
        'canales' => [0, 1, 2, 3],
        'canal_nombre' => ['luz01', 'Luz Salón', 'luz03', 'luz04']
    ],
    'rele_dos' => [
        'id' => 'rele_02',
        'nombre' => 'Relé Dos',
        'ip' => '192.168.1.80',
        'canales' => [0, 1, 2, 3],
        'canal_nombre' => ['luz05', 'luz06', 'luz07', 'luz08']
    ],
    'rele_tres' => [
        'id' => 'rele_03',
        'nombre' => 'Relé Tres',
        'ip' => '192.168.1.81',
        'canales' => [0, 1, 2, 3],
        'canal_nombre' => ['luz09', 'luz10', 'luz11', 'luz12']
    ],
    'rele_cuatro' => [
        'id' => 'rele_04',
        'nombre' => 'Relé Cuatro',
        'ip' => '192.168.1.82',
        'canales' => [0, 1, 2, 3],
        'canal_nombre' => ['luz13', 'luz14', 'luz15', 'luz16']
    ]
];

/**
 * Realiza una llamada RPC a un Shelly
 */
function llamadaShellyRpc(string $ip, string $metodo, array $params = []): array
{
    $url = "http://{$ip}/rpc/{$metodo}";
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2
    ]);
    $respuesta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode !== 200) {
        return ['error' => true, 'mensaje' => $error ?: "HTTP $httpCode"];
    }
    $data = json_decode($respuesta, true);
    return is_array($data) ? $data : ['error' => true, 'mensaje' => 'Respuesta no válida'];
}

/**
 * Obtiene el estado de los canales de un relé
 */
function obtenerEstadoRele(string $ip, array $canales): array
{
    $res = llamadaShellyRpc($ip, 'Shelly.GetStatus');
    if (!empty($res['error'])) {
        return ['error' => true, 'mensaje' => $res['mensaje'] ?? 'Dispositivo desconectado'];
    }
    $estado = [];
    foreach ($canales as $c) {
        $sw = $res["switch:{$c}"] ?? [];
        $estado[] = [
            'canal' => $c,
            'output' => !empty($sw['output'])
        ];
    }
    return ['error' => false, 'canales' => $estado];
}

// ---------------------------------------------------------------
// Manejo de peticiones AJAX (status y set_channel)
// ---------------------------------------------------------------
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $accion = $_GET['accion'] ?? 'status';
    $releKey = $_GET['rele'] ?? 'rele_uno';
    if (!isset($configuracion[$releKey])) {
        $releKey = 'rele_uno';
    }
    $releActivo = $configuracion[$releKey];

    if ($accion === 'set_channel') {
        $canal = (int) ($_GET['canal'] ?? 0);
        $onVal = in_array($_GET['on'] ?? '', ['true', '1', 1], true);
        $res = llamadaShellyRpc($releActivo['ip'], 'Switch.Set', [
            'id' => $canal,
            'on' => $onVal ? 'true' : 'false'
        ]);
        if (!empty($res['error'])) {
            echo json_encode(['error' => true, 'mensaje' => 'Error canal ' . $canal . ': ' . ($res['mensaje'] ?? '')]);
            exit;
        }
        echo json_encode(['error' => false, 'canal' => $canal, 'output' => $onVal]);
        exit;
    }

    // status
    echo json_encode(obtenerEstadoRele($releActivo['ip'], $releActivo['canales']));
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <title>Panel 3 – Control Global</title>
    <style>
        :root {
            --bg-page: #090d16;
            --card-bg: rgba(22, 30, 49, 0.78);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --on-color: #ffeb3b;
            --off-color: #555;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: var(--bg-page);
            color: var(--text-main);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .container {
            max-width: 960px;
            width: 100%;
            padding: 20px;
        }

        .actions-bar {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .btn-action {
            color: #fff;
            padding: 12px 22px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: background 0.2s ease, transform 0.1s ease;
        }

        .btn-action:active {
            transform: scale(0.98);
        }

        .btn-action svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2.2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        button#toggleAll {
            background: #047857;
        }

        button#toggleAll:hover {
            background: #059669;
        }

        button#btnLimpieza {
            background: #0284c7;
        }

        button#btnLimpieza:hover {
            background: #0369a1;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 12px;
        }

        .bulb {
            text-align: center;
        }

        .bulb svg {
            width: 40px;
            height: 40px;
            fill: var(--off-color);
            transition: fill 0.2s;
        }

        .bulb.on svg {
            fill: var(--on-color);
        }

        .bulb.err svg {
            fill: #ef4444;
        }

        .bulb.err .state {
            color: #ef4444;
        }

        .name {
            font-size: 0.75rem;
            margin-top: 4px;
            color: var(--text-muted);
        }

        .state {
            font-size: 0.7rem;
            margin-top: 2px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="actions-bar">
            <button id="toggleAll" class="btn-action">
                <svg viewBox="0 0 24 24">
                    <path d="M18.36 6.64a9 9 0 1 1-12.73 0"></path>
                    <line x1="12" y1="2" x2="12" y2="12"></line>
                </svg>
                <span>Encender / Apagar Todo</span>
            </button>
            <button id="btnLimpieza" class="btn-action">
                <svg viewBox="0 0 24 24">
                    <path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"></path>
                </svg>
                <span>Limpieza</span>
            </button>
        </div>
        <div class="grid"
             id="bulbGrid">
            <?php
            foreach ($configuracion as $key => $rel) {
                // Recorremos los canales, manteniendo el índice ($idx) para poder acceder a canal_nombre
                foreach ($rel['canales'] as $idx => $c) {
                    // Nombre a mostrar: tomamos el que definiste en canal_nombre, con fallback al número
                    $nombreCanal = $rel['canal_nombre'][$idx] ?? $c;
                    echo "<div class='bulb' data-rele='{$key}' data-canal='{$c}'>";
                    echo '<svg viewBox="0 0 24 24"><path d="M12 2a7 7 0 0 0-7 7c0 2.5 1.5 4.7 3.8 5.7V17a1 1 0 0 0 1 1h4a1 1 0 0 0 1-1v-2.3c2.3-1 3.8-3.2 3.8-5.7a7 7 0 0 0-7-7z"/></svg>';
                    // <-- aquí usamos el nombre definido en canal_nombre
                    echo "<div class='name'>{$nombreCanal}</div>";
                    echo "<div class='state'>?</div>";
                    echo "</div>";
                }
            }
            ?>
        </div>
    </div>
    <script>
        const bulbs = document.querySelectorAll('.bulb');
        // Actualiza el estado de cada bulb al cargar
        async function refreshAll() {
            for (const b of bulbs) {
                const rele = b.dataset.rele;
                try {
                    const resp = await fetch(`?ajax=1&rele=${rele}&accion=status`);
                    const data = await resp.json();
                    if (data && !data.error) {
                        const canal = parseInt(b.dataset.canal);
                        const ch = data.canales?.find(c => c.canal === canal);
                        const on = ch?.output ?? false;
                        b.classList.toggle('on', on);
                        b.classList.toggle('off', !on);
                        b.classList.remove('err');
                        b.querySelector('.state').textContent = on ? 'Encendido' : 'Apagado';
                    } else {
                        b.classList.remove('on');
                        b.classList.add('off', 'err');
                        b.querySelector('.state').textContent = 'Error';
                    }
                } catch (e) {
                    b.classList.remove('on');
                    b.classList.add('off', 'err');
                    b.querySelector('.state').textContent = 'Error';
                }
            }
        }
        // Botón de toggle
        document.getElementById('toggleAll').addEventListener('click', async () => {
            // decidir el objetivo: si al menos uno está apagado → encender todo, sino apagar todo
            const anyOff = Array.from(bulbs).some(b => !b.classList.contains('on'));
            const target = anyOff ? 'true' : 'false';
            for (const b of bulbs) {
                const rele = b.dataset.rele;
                const canal = b.dataset.canal;
                try {
                    const resp = await fetch(`?ajax=1&rele=${rele}&accion=set_channel&canal=${canal}&on=${target}`);
                    const data = await resp.json();
                    if (data && !data.error) {
                        const on = target === 'true';
                        b.classList.toggle('on', on);
                        b.classList.toggle('off', !on);
                        b.classList.remove('err');
                        b.querySelector('.state').textContent = on ? 'Encendido' : 'Apagado';
                    } else {
                        // En caso de error devuelto por la API del Shelly
                        b.classList.remove('on');
                        b.classList.add('off', 'err');
                        b.querySelector('.state').textContent = 'Error';
                    }
                } catch (e) {
                    // En caso de fallo de red/conexión
                    b.classList.remove('on');
                    b.classList.add('off', 'err');
                    b.querySelector('.state').textContent = 'Error';
                }
                await new Promise(r => setTimeout(r, <?= (int)$delay ?>)); // ms delay entre canales
            }
        });
        // Botón Limpieza (Relé 1, Canal 0 sin delay)
        document.getElementById('btnLimpieza').addEventListener('click', async () => {
            const bulb = document.querySelector('.bulb[data-rele="rele_uno"][data-canal="0"]');
            const isCurrentlyOn = bulb ? bulb.classList.contains('on') : false;
            const target = isCurrentlyOn ? 'false' : 'true';

            try {
                // Petición directa sin retardo
                const resp = await fetch(`?ajax=1&rele=rele_uno&accion=set_channel&canal=0&on=${target}`);
                const data = await resp.json();

                if (bulb) {
                    if (data && !data.error) {
                        const on = target === 'true';
                        bulb.classList.toggle('on', on);
                        bulb.classList.toggle('off', !on);
                        bulb.classList.remove('err');
                        bulb.querySelector('.state').textContent = on ? 'Encendido' : 'Apagado';
                    } else {
                        bulb.classList.remove('on');
                        bulb.classList.add('off', 'err');
                        bulb.querySelector('.state').textContent = 'Error';
                    }
                }
            } catch (e) {
                if (bulb) {
                    bulb.classList.remove('on');
                    bulb.classList.add('off', 'err');
                    bulb.querySelector('.state').textContent = 'Error';
                }
            }
        });

        // inicializar
        refreshAll();
    </script>
</body>

</html>