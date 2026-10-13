<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Configuración de dispositivos Shelly
 */
$configuracion = [
    'rele_uno' => [
        'id'      => 'rele_01',
        'nombre'  => 'Relé Uno',
        'ip'      => '192.168.1.79',
        'canales' => [0, 1, 2, 3]
    ],
    'rele_dos' => [
        'id'      => 'rele_02',
        'nombre'  => 'Relé Dos',
        'ip'      => '192.168.1.80',
        'canales' => [0, 1, 2, 3]
    ]
];

// Relé gestionado por este panel simplificado
$releActivo = $configuracion['rele_uno'];

/**
 * Realiza una llamada HTTP RPC a un dispositivo Shelly Gen2+
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
        CURLOPT_TIMEOUT        => 3,
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
 * Consulta el estado y telemetría de los 4 canales de Relé Uno
 */
function obtenerEstadoRele(string $ip, array $canales): array
{
    $res = llamadaShellyRpc($ip, 'Shelly.GetStatus');
    if (!empty($res['error'])) {
        return ['error' => true, 'mensaje' => $res['mensaje'] ?? 'Dispositivo desconectado'];
    }

    $canalesEstado = [];
    $algunoEncendido = false;
    $potenciaTotal = 0.0;
    $voltajes = [];

    foreach ($canales as $c) {
        $sw = $res["switch:{$c}"] ?? [];
        $salida = !empty($sw['output']);
        $potencia = (float)($sw['apower'] ?? 0.0);
        $voltaje = isset($sw['voltage']) ? (float)$sw['voltage'] : null;

        $potenciaTotal += $potencia;
        if ($voltaje !== null && $voltaje > 0) {
            $voltajes[] = $voltaje;
        }

        $canalesEstado[] = [
            'canal'   => $c,
            'output'  => $salida,
            'apower'  => round($potencia, 1),
            'voltage' => $voltaje !== null ? round($voltaje, 1) : null
        ];

        if ($salida) {
            $algunoEncendido = true;
        }
    }

    $vPromedio = count($voltajes) > 0 ? round(array_sum($voltajes) / count($voltajes), 1) : null;

    return [
        'error'           => false,
        'ip'              => $ip,
        'canales'         => $canalesEstado,
        'algunoEncendido' => $algunoEncendido,
        'potenciaTotal'   => round($potenciaTotal, 1),
        'voltaje'         => $vPromedio
    ];
}

// ============================================================================
// MANEJADOR DE PETICIONES AJAX
// ============================================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $accion = $_GET['accion'] ?? 'status';

    // Conmutar un canal individual (rápido para la secuencia con retardo)
    if ($accion === 'set_channel') {
        $canal = (int)($_GET['canal'] ?? 0);
        $onVal = in_array($_GET['on'] ?? '', ['true', '1', 1], true);

        $res = llamadaShellyRpc($releActivo['ip'], 'Switch.Set', [
            'id' => $canal,
            'on' => $onVal ? 'true' : 'false'
        ]);

        if (!empty($res['error'])) {
            echo json_encode(['error' => true, 'mensaje' => "Error canal $canal: " . ($res['mensaje'] ?? '')]);
            exit;
        }

        echo json_encode(['error' => false, 'canal' => $canal, 'output' => $onVal]);
        exit;
    }

    // Consulta general de estado
    echo json_encode(obtenerEstadoRele($releActivo['ip'], $releActivo['canales']));
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Panel de control simplificado y secuencial para Relé Uno">
    <title>Panel Simplificado - <?= htmlspecialchars($releActivo['nombre']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-page: #090d16;
            --card-bg: rgba(22, 30, 49, 0.78);
            --card-border: rgba(255, 255, 255, 0.08);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --text-dim: #64748b;

            /* Estado ON - Verde Esmeralda */
            --on-emerald: #10b981;
            --on-emerald-dark: #047857;
            --on-glow: rgba(16, 185, 129, 0.38);

            /* Error */
            --err-red: #ef4444;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-page);
            background-image:
                radial-gradient(at 0% 0%, rgba(30, 58, 138, 0.25) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(16, 185, 129, 0.12) 0px, transparent 50%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            color: var(--text-main);
            -webkit-font-smoothing: antialiased;
        }

        .container {
            width: 100%;
            max-width: 490px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        /* Barra de navegación superior */
        .top-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 4px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .nav-link {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: color 0.2s ease;
        }

        .nav-link:hover {
            color: #38bdf8;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Selector de retardo */
        .delay-control {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--card-border);
            padding: 5px 9px;
            border-radius: 8px;
            font-size: 0.78rem;
            color: var(--text-muted);
        }

        .delay-control svg {
            color: #38bdf8;
        }

        .delay-select {
            background: transparent;
            border: none;
            color: var(--text-main);
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            outline: none;
        }

        .delay-select option {
            background: #1e293b;
            color: #f8fafc;
        }

        .refresh-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--card-border);
            color: var(--text-muted);
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }

        .refresh-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-main);
        }

        .refresh-btn.spinning svg {
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Tarjeta Principal */
        .master-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 24px;
            padding: 30px 24px;
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(16px);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            position: relative;
            overflow: hidden;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .master-card.is-on {
            border-color: rgba(16, 185, 129, 0.3);
            box-shadow: 0 25px 50px -12px rgba(16, 185, 129, 0.15), 0 0 0 1px rgba(16, 185, 129, 0.25);
        }

        /* Encabezado */
        .device-header {
            margin-bottom: 24px;
        }

        .device-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.02em;
            margin-bottom: 4px;
        }

        .device-meta {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.82rem;
            color: var(--text-dim);
        }

        /* Botón de Encendido / Apagado */
        .master-button-wrapper {
            width: 100%;
            margin-bottom: 24px;
            display: flex;
            justify-content: center;
        }

        .btn-master {
            width: 100%;
            max-width: 380px;
            border: none;
            outline: none;
            cursor: pointer;
            border-radius: 20px;
            padding: 24px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            user-select: none;
        }

        .btn-master:active:not(:disabled) {
            transform: scale(0.97);
        }

        .btn-master:disabled {
            cursor: not-allowed;
            opacity: 0.85;
        }

        .btn-master.state-off {
            background: linear-gradient(145deg, #1e293b, #0f172a);
            border: 2px solid rgba(148, 163, 184, 0.25);
            color: var(--text-main);
            box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.5);
        }

        .btn-master.state-off:hover:not(:disabled) {
            border-color: rgba(56, 189, 248, 0.5);
            box-shadow: 0 14px 28px -6px rgba(56, 189, 248, 0.25);
            transform: translateY(-2px);
        }

        .btn-master.state-off .power-icon-circle {
            background: rgba(255, 255, 255, 0.06);
            border: 2px solid rgba(255, 255, 255, 0.15);
            color: #94a3b8;
        }

        .btn-master.state-on {
            background: linear-gradient(145deg, #059669, #047857);
            border: 2px solid #34d399;
            color: #ffffff;
            box-shadow: 0 16px 32px -8px var(--on-glow), 0 0 25px rgba(16, 185, 129, 0.35);
        }

        .btn-master.state-on:hover:not(:disabled) {
            background: linear-gradient(145deg, #10b981, #059669);
            box-shadow: 0 20px 40px -6px var(--on-glow);
            transform: translateY(-2px);
        }

        .btn-master.state-on .power-icon-circle {
            background: rgba(255, 255, 255, 0.22);
            border: 2px solid rgba(255, 255, 255, 0.7);
            color: #ffffff;
        }

        .btn-master.is-loading {
            background: linear-gradient(145deg, #0f172a, #1e293b);
            border: 2px solid #38bdf8;
            box-shadow: 0 0 20px rgba(56, 189, 248, 0.35);
            color: #ffffff;
        }

        .btn-master.is-loading .power-icon-circle {
            background: rgba(56, 189, 248, 0.15);
            border: 2px solid #38bdf8;
        }

        .power-icon-circle {
            width: 66px;
            height: 66px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .power-icon-circle svg {
            width: 32px;
            height: 32px;
        }

        .btn-label-title {
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .btn-spinner {
            display: none;
            width: 26px;
            height: 26px;
            border: 3px solid rgba(56, 189, 248, 0.25);
            border-top-color: #38bdf8;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }

        .btn-master.is-loading .power-icon-circle svg {
            display: none;
        }

        .btn-master.is-loading .btn-spinner {
            display: block;
        }

        /* Monitor de Canales (0, 1, 2, 3) */
        .channels-strip {
            width: 100%;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .channels-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
        }

        .channel-pill {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 10px;
            padding: 8px 6px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            transition: all 0.25s ease;
        }

        .channel-pill .ch-name {
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--text-dim);
        }

        .channel-pill .ch-status {
            font-size: 0.68rem;
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 6px;
            transition: all 0.25s ease;
        }

        .channel-pill.ch-on {
            border-color: rgba(16, 185, 129, 0.4);
            background: rgba(16, 185, 129, 0.1);
        }

        .channel-pill.ch-on .ch-name {
            color: #6ee7b7;
        }

        .channel-pill.ch-on .ch-status {
            background: #10b981;
            color: #ffffff;
            box-shadow: 0 0 8px rgba(16, 185, 129, 0.4);
        }

        .channel-pill.ch-off {
            border-color: rgba(148, 163, 184, 0.1);
        }

        .channel-pill.ch-off .ch-status {
            background: rgba(71, 85, 105, 0.4);
            color: #94a3b8;
        }

        .channel-pill.ch-switching {
            border-color: #38bdf8 !important;
            background: rgba(56, 189, 248, 0.2) !important;
            box-shadow: 0 0 16px rgba(56, 189, 248, 0.55);
            transform: scale(1.05);
            animation: pulse-switching 0.5s ease-in-out infinite alternate;
        }

        @keyframes pulse-switching {
            from { border-color: rgba(56, 189, 248, 0.4); }
            to { border-color: #38bdf8; }
        }

        /* Telemetría */
        .telemetry-row {
            display: flex;
            justify-content: space-around;
            width: 100%;
            padding-top: 8px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 0.78rem;
            color: var(--text-dim);
        }

        .telemetry-item strong {
            color: var(--text-muted);
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.82rem;
        }

        .toast-feedback {
            font-size: 0.8rem;
            color: var(--err-red);
            margin-top: 8px;
            min-height: 18px;
        }

        /* Pie de página */
        .footer-info {
            text-align: center;
            font-size: 0.75rem;
            color: var(--text-dim);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }
    </style>
</head>

<body>
    <main class="container">
        <!-- Navegación y selector de retardo -->
        <nav class="top-nav" aria-label="Navegación del panel">
            <a href="index.php" class="nav-link" id="link-panel-completo" title="Volver al panel multi-relé">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Volver a index.php
            </a>

            <div class="nav-actions">
                <div class="delay-control" title="Retardo entre canal y canal (0 -> 1 -> 2 -> 3)">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <label for="select-delay">Retardo:</label>
                    <select id="select-delay" class="delay-select">
                        <option value="150">150 ms</option>
                        <option value="300">300 ms</option>
                        <option value="600" selected>600 ms</option>
                        <option value="800">800 ms</option>
                        <option value="1000">1.0 s</option>
                        <option value="1500">1.5 s</option>
                    </select>
                </div>

                <button class="refresh-btn" id="btn-manual-refresh" onclick="consultarEstado(true)" title="Refrescar estado ahora">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="23 4 23 10 17 10"></polyline>
                        <polyline points="1 20 1 14 7 14"></polyline>
                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                    </svg>
                    <span>Actualizar</span>
                </button>
            </div>
        </nav>

        <!-- Tarjeta Principal del Relé Uno -->
        <section class="master-card" id="card-master-rele">
            <header class="device-header">
                <h1 class="device-title" id="titulo-dispositivo"><?= htmlspecialchars($releActivo['nombre']) ?></h1>
                <div class="device-meta">
                    <span>IP: <?= htmlspecialchars($releActivo['ip']) ?></span>
                </div>
            </header>

            <!-- Botón Maestro de Encendido / Apagado -->
            <div class="master-button-wrapper">
                <button type="button" class="btn-master state-off" id="btn-master-toggle" onclick="conmutarSecuencia()" disabled>
                    <div class="power-icon-circle">
                        <svg id="icon-master-power" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18.36 6.64a9 9 0 1 1-12.73 0"></path>
                            <line x1="12" y1="2" x2="12" y2="12"></line>
                        </svg>
                        <div class="btn-spinner" id="spinner-master"></div>
                    </div>
                    <span class="btn-label-title" id="txt-master-action">ENCENDIDO / APAGADO</span>
                </button>
            </div>

            <!-- Monitor de los 4 Canales -->
            <div class="channels-strip">
                <div class="channels-grid">
                    <?php foreach ($releActivo['canales'] as $ch): ?>
                        <div class="channel-pill ch-off" id="canal-badge-<?= $ch ?>">
                            <span class="ch-name">CH <?= $ch ?></span>
                            <span class="ch-status" id="canal-txt-<?= $ch ?>">---</span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="telemetry-row">
                    <div class="telemetry-item">
                        Potencia: <strong id="val-potencia-total">-- W</strong>
                    </div>
                    <div class="telemetry-item">
                        Tensión: <strong id="val-voltaje">-- V</strong>
                    </div>
                </div>
            </div>

            <div class="toast-feedback" id="toast-feedback" aria-live="polite"></div>
        </section>

        <!-- Pie de página -->
        <footer class="footer-info">
            <span id="txt-last-sync">Última sinc: Iniciando...</span>
            <span>•</span>
            <span>Auto-refresco: 5s</span>
        </footer>
    </main>

    <script>
        // Elementos DOM
        const btnMaster = document.getElementById('btn-master-toggle');
        const txtMasterAction = document.getElementById('txt-master-action');
        const cardMaster = document.getElementById('card-master-rele');
        const valPotencia = document.getElementById('val-potencia-total');
        const valVoltaje = document.getElementById('val-voltaje');
        const txtLastSync = document.getElementById('txt-last-sync');
        const toastFeedback = document.getElementById('toast-feedback');
        const btnRefresh = document.getElementById('btn-manual-refresh');
        const selectDelay = document.getElementById('select-delay');

        let estadoActual = { algunoEncendido: false, canales: [] };
        let enSecuencia = false;
        let intervaloAutoRefresco = null;

        const esperar = (ms) => new Promise(resolve => setTimeout(resolve, ms));

        /**
         * Actualiza el interfaz con los datos del Shelly
         */
        function renderizarEstado(data) {
            if (!enSecuencia) {
                btnMaster.disabled = false;
                btnMaster.classList.remove('is-loading');
            }

            if (data.error) {
                cardMaster.classList.remove('is-on');
                if (!enSecuencia) {
                    btnMaster.className = 'btn-master state-off';
                    txtMasterAction.textContent = 'REINTENTAR CONEXIÓN';
                }
                toastFeedback.textContent = data.mensaje || 'Error de conexión';
                return;
            }

            toastFeedback.textContent = '';
            estadoActual = data;
            const algunoOn = Boolean(data.algunoEncendido);

            // Actualizar botón según el estado
            if (!enSecuencia) {
                if (algunoOn) {
                    btnMaster.className = 'btn-master state-on';
                    txtMasterAction.textContent = 'APAGAR RELÉ UNO';
                    cardMaster.classList.add('is-on');
                } else {
                    btnMaster.className = 'btn-master state-off';
                    txtMasterAction.textContent = 'ENCENDER RELÉ UNO';
                    cardMaster.classList.remove('is-on');
                }
            }

            // Actualizar píldoras de canales
            if (Array.isArray(data.canales)) {
                data.canales.forEach(ch => {
                    const pill = document.getElementById('canal-badge-' + ch.canal);
                    const txt = document.getElementById('canal-txt-' + ch.canal);
                    if (pill && txt) {
                        pill.className = ch.output ? 'channel-pill ch-on' : 'channel-pill ch-off';
                        txt.textContent = ch.output ? 'ON' : 'OFF';
                    }
                });
            }

            // Telemetría
            if (data.potenciaTotal !== undefined) valPotencia.textContent = data.potenciaTotal + ' W';
            if (data.voltaje !== undefined && data.voltaje !== null) valVoltaje.textContent = data.voltaje + ' V';

            const ahora = new Date();
            txtLastSync.textContent = 'Última sinc: ' + ahora.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }

        /**
         * Consulta el estado del relé vía AJAX
         */
        async function consultarEstado(mostrarGiro = false) {
            if (enSecuencia) return;
            if (mostrarGiro && btnRefresh) btnRefresh.classList.add('spinning');

            try {
                const r = await fetch('panel2.php?ajax=1&accion=status', { cache: 'no-store' });
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                const data = await r.json();
                renderizarEstado(data);
            } catch (err) {
                console.error('Error al consultar estado:', err);
                renderizarEstado({ error: true, mensaje: 'Error de red: ' + err.message });
            } finally {
                if (btnRefresh) btnRefresh.classList.remove('spinning');
            }
        }

        /**
         * Ejecuta la conmutación secuencial: Canal 0 -> delay -> Canal 1 -> delay -> Canal 2 -> delay -> Canal 3
         */
        async function conmutarSecuencia() {
            if (enSecuencia || btnMaster.disabled) return;

            enSecuencia = true;
            btnMaster.disabled = true;
            btnMaster.classList.add('is-loading');
            toastFeedback.textContent = '';

            const targetOn = !estadoActual.algunoEncendido;
            const accionVerbo = targetOn ? 'ENCENDIENDO' : 'APAGANDO';
            const canales = [0, 1, 2, 3];
            const delayMs = parseInt(selectDelay.value, 10) || 600;

            try {
                for (let i = 0; i < canales.length; i++) {
                    const ch = canales[i];
                    const pill = document.getElementById('canal-badge-' + ch);
                    const txt = document.getElementById('canal-txt-' + ch);

                    txtMasterAction.textContent = `${accionVerbo}...`;
                    if (pill) pill.classList.add('ch-switching');

                    // Llamada directa al canal
                    const url = `panel2.php?ajax=1&accion=set_channel&canal=${ch}&on=${targetOn ? 'true' : 'false'}`;
                    const resp = await fetch(url, { cache: 'no-store' });
                    if (!resp.ok) throw new Error(`HTTP ${resp.status} en canal ${ch}`);
                    const data = await resp.json();
                    if (data.error) throw new Error(data.mensaje || `Error en canal ${ch}`);

                    // Actualización visual inmediata
                    if (pill) {
                        pill.classList.remove('ch-switching');
                        pill.className = targetOn ? 'channel-pill ch-on' : 'channel-pill ch-off';
                    }
                    if (txt) txt.textContent = targetOn ? 'ON' : 'OFF';

                    if (targetOn) {
                        cardMaster.classList.add('is-on');
                    } else if (i === canales.length - 1) {
                        cardMaster.classList.remove('is-on');
                    }

                    // Esperar el retardo antes del siguiente canal
                    if (i < canales.length - 1) {
                        await esperar(delayMs);
                    }
                }
            } catch (err) {
                console.error('Error en la secuencia:', err);
                toastFeedback.textContent = 'Error: ' + err.message;
            } finally {
                canales.forEach(ch => {
                    const p = document.getElementById('canal-badge-' + ch);
                    if (p) p.classList.remove('ch-switching');
                });
                enSecuencia = false;
                await consultarEstado();
            }
        }

        // Inicialización y auto-refresco
        document.addEventListener('DOMContentLoaded', () => {
            consultarEstado();
            intervaloAutoRefresco = setInterval(() => {
                if (!document.hidden && !enSecuencia) {
                    consultarEstado(false);
                }
            }, 5000);
        });
    </script>
</body>

</html>