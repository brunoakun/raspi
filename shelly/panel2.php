<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Configuración de dispositivos Shelly en la instalación.
 * Relé uno (192.168.1.79): Dispositivo de 4 canales controlado por este panel simplificado.
 * Relé dos (192.168.1.80): Segundo dispositivo de 4 canales del sistema.
 */
$configuracion = [
    'rele_uno' => [
        'id' => 'rele_01',
        'nombre' => 'Relé Uno',
        'ip' => '192.168.1.79',
        'canales' => [0, 1, 2, 3]
    ],
    'rele_dos' => [
        'id' => 'rele_02',
        'nombre' => 'Relé Dos',
        'ip' => '192.168.1.80',
        'canales' => [0, 1, 2, 3]
    ]
];

// Relé objetivo de este panel simplificado
$releActivo = $configuracion['rele_uno'];

/**
 * Realiza una llamada HTTP RPC a un dispositivo Shelly Gen2+.
 */
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
    $decodificado = json_decode($respuesta, true);
    if (!is_array($decodificado)) {
        return ['error' => true, 'mensaje' => 'Respuesta no válida del dispositivo'];
    }
    return $decodificado;
}

/**
 * Obtiene el estado actual de los canales de un relé.
 * Utiliza Shelly.GetStatus para consultar todos los canales en una única petición rápida,
 * con fallback a Switch.GetStatus individual si fuese necesario.
 */
function obtenerEstadoRele($ip, array $canales)
{
    $resGlobal = llamadaShellyRpc($ip, 'Shelly.GetStatus');

    $canalesEstado = [];
    $algunoEncendido = false;
    $todosEncendidos = true;
    $potenciaTotal = 0.0;
    $voltajePromedio = 0.0;
    $voltajesValidos = 0;

    if (!isset($resGlobal['error'])) {
        foreach ($canales as $c) {
            $key = "switch:{$c}";
            $salida = isset($resGlobal[$key]['output']) ? (bool) $resGlobal[$key]['output'] : false;
            $potencia = isset($resGlobal[$key]['apower']) ? (float) $resGlobal[$key]['apower'] : 0.0;
            $voltaje = isset($resGlobal[$key]['voltage']) ? (float) $resGlobal[$key]['voltage'] : null;

            $potenciaTotal += $potencia;
            if ($voltaje !== null && $voltaje > 0) {
                $voltajePromedio += $voltaje;
                $voltajesValidos++;
            }

            $canalesEstado[] = [
                'canal' => $c,
                'nombre' => "Canal $c",
                'output' => $salida,
                'apower' => round($potencia, 1),
                'voltage' => $voltaje !== null ? round($voltaje, 1) : null
            ];

            if ($salida) {
                $algunoEncendido = true;
            } else {
                $todosEncendidos = false;
            }
        }
    } else {
        // Fallback por canal individual si Shelly.GetStatus no respondió
        $errores = 0;
        foreach ($canales as $c) {
            $res = llamadaShellyRpc($ip, 'Switch.GetStatus', ['id' => $c]);
            if (isset($res['error'])) {
                $errores++;
                $salida = false;
                $potencia = 0.0;
                $voltaje = null;
            } else {
                $salida = isset($res['output']) ? (bool) $res['output'] : false;
                $potencia = isset($res['apower']) ? (float) $res['apower'] : 0.0;
                $voltaje = isset($res['voltage']) ? (float) $res['voltage'] : null;
                $potenciaTotal += $potencia;
                if ($voltaje !== null && $voltaje > 0) {
                    $voltajePromedio += $voltaje;
                    $voltajesValidos++;
                }
            }

            $canalesEstado[] = [
                'canal' => $c,
                'nombre' => "Canal $c",
                'output' => $salida,
                'apower' => round($potencia, 1),
                'voltage' => $voltaje !== null ? round($voltaje, 1) : null
            ];

            if ($salida) {
                $algunoEncendido = true;
            } else {
                $todosEncendidos = false;
            }
        }

        if ($errores === count($canales)) {
            return [
                'error' => true,
                'mensaje' => $resGlobal['mensaje'] ?? 'Dispositivo desconectado'
            ];
        }
    }

    $vFinal = $voltajesValidos > 0 ? round($voltajePromedio / $voltajesValidos, 1) : null;

    return [
        'error' => false,
        'ip' => $ip,
        'canales' => $canalesEstado,
        'algunoEncendido' => $algunoEncendido,
        'todosEncendidos' => $todosEncendidos,
        'estadoGlobal' => $algunoEncendido,
        'potenciaTotal' => round($potenciaTotal, 1),
        'voltaje' => $vFinal
    ];
}

/**
 * Modifica el estado de los canales en bloque con delay en el servidor si se requiere.
 */
function cambiarEstadoRele($ip, array $canales, bool $nuevoEstado, int $delayMs = 0)
{
    $onStr = $nuevoEstado ? 'true' : 'false';
    $errores = [];

    foreach ($canales as $idx => $c) {
        if ($idx > 0 && $delayMs > 0) {
            usleep($delayMs * 1000);
        }
        $res = llamadaShellyRpc($ip, 'Switch.Set', ['id' => $c, 'on' => $onStr]);
        if (isset($res['error'])) {
            $errores[] = "Canal $c: " . ($res['mensaje'] ?? 'Error desconocido');
        }
    }

    $estadoActualizado = obtenerEstadoRele($ip, $canales);
    $estadoActualizado['erroresComando'] = $errores;

    return $estadoActualizado;
}

// ============================================================================
// MANEJADOR DE PETICIONES AJAX
// ============================================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $accion = $_GET['accion'] ?? 'status';

    // Acción para cambiar un único canal individualmente (rápido, ultra responsivo)
    if ($accion === 'set_channel') {
        $canal = isset($_GET['canal']) ? (int) $_GET['canal'] : 0;
        $onParam = $_GET['on'] ?? 'true';
        $onVal = ($onParam === 'true' || $onParam === '1' || $onParam === 1);

        $res = llamadaShellyRpc($releActivo['ip'], 'Switch.Set', [
            'id' => $canal,
            'on' => $onVal ? 'true' : 'false'
        ]);

        if (isset($res['error'])) {
            echo json_encode([
                'error' => true,
                'mensaje' => "Error al conmutar Canal $canal: " . ($res['mensaje'] ?? 'Error desconocido')
            ]);
            exit;
        }

        // Respuesta ultra rápida directa sin esperar por toda la telemetría
        echo json_encode([
            'error' => false,
            'canal' => $canal,
            'output' => $onVal
        ]);
        exit;
    }

    // Toggle directo con delay opcional
    if ($accion === 'toggle') {
        $delayMs = isset($_GET['delay']) ? (int) $_GET['delay'] : 0;
        $estadoPrevio = obtenerEstadoRele($releActivo['ip'], $releActivo['canales']);
        if (!empty($estadoPrevio['error'])) {
            echo json_encode([
                'error' => true,
                'mensaje' => 'No se pudo comunicar con el relé: ' . ($estadoPrevio['mensaje'] ?? 'Error de conexión')
            ]);
            exit;
        }

        $objetivo = !($estadoPrevio['algunoEncendido']);
        $resultado = cambiarEstadoRele($releActivo['ip'], $releActivo['canales'], $objetivo, $delayMs);
        echo json_encode($resultado);
        exit;
    }

    if ($accion === 'on') {
        $delayMs = isset($_GET['delay']) ? (int) $_GET['delay'] : 0;
        $resultado = cambiarEstadoRele($releActivo['ip'], $releActivo['canales'], true, $delayMs);
        echo json_encode($resultado);
        exit;
    }

    if ($accion === 'off') {
        $delayMs = isset($_GET['delay']) ? (int) $_GET['delay'] : 0;
        $resultado = cambiarEstadoRele($releActivo['ip'], $releActivo['canales'], false, $delayMs);
        echo json_encode($resultado);
        exit;
    }

    // Por defecto: accion = status
    $resultado = obtenerEstadoRele($releActivo['ip'], $releActivo['canales']);
    echo json_encode($resultado);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <meta name="description"
          content="Panel simplificado para encender y apagar los 4 canales de Relé Uno en secuencia con retardo progresivo">
    <title>Panel Simplificado - Encendido/Apagado Secuencial Relé Uno</title>
    <link rel="preconnect"
          href="https://fonts.googleapis.com">
    <link rel="preconnect"
          href="https://fonts.gstatic.com"
          crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap"
          rel="stylesheet">
    <style>
        :root {
            --bg-page: #090d16;
            --card-bg: rgba(22, 30, 49, 0.78);
            --card-border: rgba(255, 255, 255, 0.08);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --text-dim: #64748b;

            /* Estado ON - Verde Esmeralda Vibrante */
            --on-emerald: #10b981;
            --on-emerald-dark: #047857;
            --on-glow: rgba(16, 185, 129, 0.38);
            --on-badge-bg: rgba(16, 185, 129, 0.15);
            --on-badge-border: rgba(16, 185, 129, 0.35);

            /* Estado OFF - Slate / Indigo */
            --off-slate: #1e293b;
            --off-glow: rgba(99, 102, 241, 0.18);
            --off-badge-bg: rgba(71, 85, 105, 0.2);
            --off-badge-border: rgba(148, 163, 184, 0.25);

            /* Error / Offline */
            --err-red: #ef4444;
            --err-badge-bg: rgba(239, 68, 68, 0.15);
            --err-badge-border: rgba(239, 68, 68, 0.35);
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

        /* Barra superior de navegación y controles */
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
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
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

        /* Encabezado del dispositivo */
        .device-header {
            margin-bottom: 20px;
        }

        /* .device-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(56, 189, 248, 0.1);
            border: 1px solid rgba(56, 189, 248, 0.25);
            color: #38bdf8;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 4px 10px;
            border-radius: 9999px;
            margin-bottom: 8px;
        } */

        .device-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.02em;
            margin-bottom: 6px;
        }

        .device-meta {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.82rem;
            color: var(--text-dim);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        /* Insignia de estado general */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            margin-bottom: 22px;
            transition: all 0.3s ease;
        }

        .status-badge .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: currentColor;
        }

        .status-badge.badge-on {
            background: var(--on-badge-bg);
            border: 1px solid var(--on-badge-border);
            color: var(--on-emerald);
        }

        .status-badge.badge-on .dot {
            box-shadow: 0 0 10px var(--on-emerald);
            animation: pulse-dot 1.8s ease-in-out infinite;
        }

        .status-badge.badge-off {
            background: var(--off-badge-bg);
            border: 1px solid var(--off-badge-border);
            color: var(--text-muted);
        }

        .status-badge.badge-err {
            background: var(--err-badge-bg);
            border: 1px solid var(--err-badge-border);
            color: var(--err-red);
        }

        @keyframes pulse-dot {

            0%,
            100% {
                transform: scale(1);
                opacity: 1;
            }

            50% {
                transform: scale(1.35);
                opacity: 0.6;
            }
        }

        /* ====================================================================
           BOTÓN ÚNICO DE ENCENDIDO / APAGADO
           ==================================================================== */
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
            padding: 22px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            position: relative;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }

        .btn-master:active:not(:disabled) {
            transform: scale(0.97);
        }

        .btn-master:disabled {
            cursor: not-allowed;
            opacity: 0.85;
        }

        /* Estilo Botón cuando está APAGADO (listo para encender en cascada) */
        .btn-master.state-off {
            background: linear-gradient(145deg, #1e293b, #0f172a);
            border: 2px solid rgba(148, 163, 184, 0.25);
            color: var(--text-main);
            box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.05);
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

        /* Estilo Botón cuando está ENCENDIDO (listo para apagar en cascada) */
        .btn-master.state-on {
            background: linear-gradient(145deg, #059669, #047857);
            border: 2px solid #34d399;
            color: #ffffff;
            box-shadow: 0 16px 32px -8px var(--on-glow), 0 0 25px rgba(16, 185, 129, 0.35);
        }

        .btn-master.state-on:hover:not(:disabled) {
            background: linear-gradient(145deg, #10b981, #059669);
            box-shadow: 0 20px 40px -6px var(--on-glow), 0 0 35px rgba(16, 185, 129, 0.5);
            transform: translateY(-2px);
        }

        .btn-master.state-on .power-icon-circle {
            background: rgba(255, 255, 255, 0.22);
            border: 2px solid rgba(255, 255, 255, 0.7);
            color: #ffffff;
            box-shadow: 0 0 15px rgba(255, 255, 255, 0.4);
        }

        /* Estilo Botón durante la secuencia en ejecución */
        .btn-master.is-loading {
            background: linear-gradient(145deg, #0f172a, #1e293b);
            border: 2px solid #38bdf8;
            box-shadow: 0 0 20px rgba(56, 189, 248, 0.35);
            color: #ffffff;
        }

        .btn-master.is-loading .power-icon-circle {
            background: rgba(56, 189, 248, 0.15);
            border: 2px solid #38bdf8;
            color: #38bdf8;
        }

        /* Círculo del icono de energía */
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
            transition: transform 0.2s ease;
        }

        .btn-master:hover:not(:disabled) .power-icon-circle svg {
            transform: scale(1.08);
        }

        /* Textos dentro del botón */
        .btn-label-title {
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .btn-label-sub {
            font-size: 0.78rem;
            font-weight: 500;
            opacity: 0.85;
            letter-spacing: 0.01em;
        }

        /* Spinner dentro del botón mientras carga */
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

        /* ====================================================================
           MONITOR EN VIVO DE LOS 4 CANALES (0, 1, 2, 3)
           ==================================================================== */
        .channels-strip {
            width: 100%;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .channels-strip-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-dim);
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
            letter-spacing: 0.03em;
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

        /* Resaltado cuando un canal se está conmutando activamente en la secuencia */
        .channel-pill.ch-switching {
            border-color: #38bdf8 !important;
            background: rgba(56, 189, 248, 0.2) !important;
            box-shadow: 0 0 16px rgba(56, 189, 248, 0.55);
            transform: scale(1.06);
            animation: pulse-switching 0.5s ease-in-out infinite alternate;
        }

        @keyframes pulse-switching {
            from {
                border-color: rgba(56, 189, 248, 0.4);
            }

            to {
                border-color: #38bdf8;
            }
        }

        /* Telemetría rápida */
        .telemetry-row {
            display: flex;
            justify-content: space-around;
            width: 100%;
            margin-top: 6px;
            padding-top: 10px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 0.78rem;
            color: var(--text-dim);
        }

        .telemetry-item strong {
            color: var(--text-muted);
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.82rem;
        }

        /* Notificación de feedback / avisos */
        .toast-feedback {
            font-size: 0.8rem;
            margin-top: 10px;
            min-height: 18px;
            transition: opacity 0.3s ease;
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
        <!-- Barra de navegación y controles de retardo -->
        <nav class="top-nav"
             aria-label="Navegación del panel">
            <a href="index.php"
               class="nav-link"
               id="link-panel-completo"
               title="Volver al panel multi-relé">
                <svg width="16"
                     height="16"
                     viewBox="0 0 24 24"
                     fill="none"
                     stroke="currentColor"
                     stroke-width="2"
                     stroke-linecap="round"
                     stroke-linejoin="round">
                    <line x1="19"
                          y1="12"
                          x2="5"
                          y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Volver a index.php
            </a>

            <div class="nav-actions">
                <!-- Selector de retardo entre la activación de cada canal -->
                <div class="delay-control"
                     title="Retardo entre canal y canal (0 -> 1 -> 2 -> 3)">
                    <svg width="13"
                         height="13"
                         viewBox="0 0 24 24"
                         fill="none"
                         stroke="currentColor"
                         stroke-width="2.5"
                         stroke-linecap="round"
                         stroke-linejoin="round">
                        <circle cx="12"
                                cy="12"
                                r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <label for="select-delay">Retardo:</label>
                    <select id="select-delay"
                            class="delay-select">
                        <option value="150">150 ms</option>
                        <option value="300">300 ms</option>
                        <option value="600"
                                selected>600 ms</option>
                        <option value="800">800 ms</option>
                        <option value="1000">1.0 s</option>
                        <option value="1500">1.5 s</option>
                    </select>
                </div>

                <button class="refresh-btn"
                        id="btn-manual-refresh"
                        onclick="consultarEstado(true)"
                        title="Refrescar estado ahora">
                    <svg width="14"
                         height="14"
                         viewBox="0 0 24 24"
                         fill="none"
                         stroke="currentColor"
                         stroke-width="2"
                         stroke-linecap="round"
                         stroke-linejoin="round">
                        <polyline points="23 4 23 10 17 10"></polyline>
                        <polyline points="1 20 1 14 7 14"></polyline>
                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                    </svg>
                    <span id="txt-refresh-btn">Actualizar</span>
                </button>
            </div>
        </nav>

        <!-- Tarjeta Principal del Relé Uno -->
        <section class="master-card"
                 id="card-master-rele">
            <!-- Encabezado -->
            <header class="device-header">
                <!-- <div class="device-pill">Interfaz Simplificada • Secuencia 0 → 1 → 2 → 3</div> -->
                <h1 class="device-title"
                    id="titulo-dispositivo"><?= htmlspecialchars($releActivo['nombre']) ?></h1>
                <div class="device-meta">
                    <span id="disp-ip">IP: <?= htmlspecialchars($releActivo['ip']) ?></span>
                    <!-- <span>•</span>
                    <span id="disp-canales">4 Canales Secuenciales</span> -->
                </div>
            </header>

            <!-- Badge de Estado Global -->
            <!-- <div class="status-badge badge-off"
                 id="badge-estado-global"
                 role="status"> -->
            <div>
                <span class="dot"></span>
                <span id="txt-estado-global">Consultando estado...</span>
            </div>

            <!-- UN SOLO BOTÓN PRINCIPAL DE ENCENDIDO / APAGADO -->
            <div class="master-button-wrapper">
                <button type="button"
                        class="btn-master state-off"
                        id="btn-master-toggle"
                        aria-pressed="false"
                        onclick="conmutarSecuencia()"
                        disabled>
                    <div class="power-icon-circle"
                         id="icon-container-power">
                        <svg id="icon-master-power"
                             viewBox="0 0 24 24"
                             fill="none"
                             stroke="currentColor"
                             stroke-width="2.5"
                             stroke-linecap="round"
                             stroke-linejoin="round">
                            <path d="M18.36 6.64a9 9 0 1 1-12.73 0"></path>
                            <line x1="12"
                                  y1="2"
                                  x2="12"
                                  y2="12"></line>
                        </svg>
                        <div class="btn-spinner"
                             id="spinner-master"></div>
                    </div>
                    <span class="btn-label-title"
                          id="txt-master-action">ENCENDIDO / APAGADO</span>
                    <span class="btn-label-sub"
                          id="txt-master-desc"></span>
                </button>
            </div>

            <!-- Monitor individual de los 4 Canales del Relé Uno -->
            <div class="channels-strip"
                 id="strip-canales">
                <div class="channels-strip-header">
                    <!-- <span>Estado Canales (0, 1, 2, 3)</span> -->
                    <span id="txt-resumen-canales">Sincronizando...</span>
                </div>
                <div class="channels-grid">
                    <?php foreach ($releActivo['canales'] as $ch): ?>
                        <div class="channel-pill ch-off"
                             id="canal-badge-<?= $ch ?>">
                            <span class="ch-name">CH <?= $ch ?></span>
                            <span class="ch-status"
                                  id="canal-txt-<?= $ch ?>">---</span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Telemetría rápida (Potencia total y Tensión promedio) -->
                <div class="telemetry-row">
                    <div class="telemetry-item">
                        Potencia: <strong id="val-potencia-total">-- W</strong>
                    </div>
                    <div class="telemetry-item">
                        Tensión: <strong id="val-voltaje">-- V</strong>
                    </div>
                </div>
            </div>

            <!-- Mensaje de error o aviso -->
            <div class="toast-feedback"
                 id="toast-feedback"
                 aria-live="polite"></div>
        </section>

        <!-- Pie de página -->
        <footer class="footer-info">
            <span id="txt-last-sync">Última sinc: Iniciando...</span>
            <span>•</span>
            <span>Auto-refresco: 5s</span>
        </footer>
    </main>

    <script>
        // Referencias a los elementos del DOM
        const btnMaster = document.getElementById('btn-master-toggle');
        const txtMasterAction = document.getElementById('txt-master-action');
        const txtMasterDesc = document.getElementById('txt-master-desc');
        //const badgeGlobal = document.getElementById('badge-estado-global');
        const txtEstadoGlobal = document.getElementById('txt-estado-global');
        const cardMaster = document.getElementById('card-master-rele');
        const txtResumenCanales = document.getElementById('txt-resumen-canales');
        const valPotencia = document.getElementById('val-potencia-total');
        const valVoltaje = document.getElementById('val-voltaje');
        const txtLastSync = document.getElementById('txt-last-sync');
        const toastFeedback = document.getElementById('toast-feedback');
        const btnRefresh = document.getElementById('btn-manual-refresh');
        const selectDelay = document.getElementById('select-delay');

        // Estado local en memoria
        let estadoActual = {
            cargando: true,
            algunoEncendido: false,
            todosEncendidos: false,
            canales: []
        };

        let enSecuencia = false;
        let intervaloAutoRefresco = null;

        /**
         * Renderiza la interfaz en base a los datos recibidos del Shelly
         */
        function renderizarEstado(data) {
            if (!enSecuencia) {
                btnMaster.disabled = false;
                btnMaster.classList.remove('is-loading');
            }

            if (data.error) {
                // badgeGlobal.className = 'status-badge badge-err';
                txtEstadoGlobal.textContent = 'DESCONECTADO';
                cardMaster.classList.remove('is-on');

                if (!enSecuencia) {
                    btnMaster.className = 'btn-master state-off';
                    btnMaster.setAttribute('aria-pressed', 'false');
                    txtMasterAction.textContent = 'REINTENTAR CONEXIÓN';
                    txtMasterDesc.textContent = 'Sin respuesta en ' + (data.ip || '192.168.1.79');
                }

                txtResumenCanales.textContent = 'Sin respuesta';
                toastFeedback.style.color = 'var(--err-red)';
                toastFeedback.textContent = data.mensaje || 'Error de conexión con el dispositivo';
                return;
            }

            estadoActual = data;
            const algunoOn = Boolean(data.algunoEncendido);
            const todosOn = Boolean(data.todosEncendidos);

            // 1. Actualizar el Botón de Encendido / Apagado
            if (!enSecuencia) {
                if (algunoOn) {
                    btnMaster.className = 'btn-master state-on';
                    btnMaster.setAttribute('aria-pressed', 'true');
                    txtMasterAction.textContent = 'APAGAR RELÉ UNO';
                    // txtMasterDesc.textContent = 'Pulsar para apagar en secuencia: 0 → 1 → 2 → 3';
                    txtMasterDesc.textContent = '';
                    cardMaster.classList.add('is-on');

                    // badgeGlobal.className = 'status-badge badge-on';
                    // txtEstadoGlobal.textContent = todosOn ? 'ENCENDIDO (4/4)' : 'PARCIALMENTE ENCENDIDO';
                    txtEstadoGlobal.textContent = '';
                } else {
                    btnMaster.className = 'btn-master state-off';
                    btnMaster.setAttribute('aria-pressed', 'false');
                    txtMasterAction.textContent = 'ENCENDER RELÉ UNO';
                    //   txtMasterDesc.textContent = 'Pulsar para encender en secuencia: 0 → 1 → 2 → 3';
                    txtMasterDesc.textContent = '';
                    cardMaster.classList.remove('is-on');

                    // badgeGlobal.className = 'status-badge badge-off';
                    // txtEstadoGlobal.textContent = 'APAGADO (0/4)';
                    txtEstadoGlobal.textContent = '';
                }
            }

            // 2. Actualizar las insignias individuales de los 4 canales
            let canalesEncendidosCount = 0;
            if (Array.isArray(data.canales)) {
                data.canales.forEach(ch => {
                    const pill = document.getElementById('canal-badge-' + ch.canal);
                    const txt = document.getElementById('canal-txt-' + ch.canal);
                    if (pill && txt) {
                        if (ch.output) {
                            canalesEncendidosCount++;
                            pill.className = 'channel-pill ch-on';
                            txt.textContent = 'ON';
                        } else {
                            pill.className = 'channel-pill ch-off';
                            txt.textContent = 'OFF';
                        }
                    }
                });
            }
            //  txtResumenCanales.textContent = `${canalesEncendidosCount} de 4 activos`;
            txtResumenCanales.textContent = '';

            // 3. Telemetría (Potencia y Tensión)
            if (data.potenciaTotal !== undefined) {
                valPotencia.textContent = data.potenciaTotal + ' W';
            }
            if (data.voltaje !== undefined && data.voltaje !== null) {
                valVoltaje.textContent = data.voltaje + ' V';
            }

            // 4. Marca de tiempo
            const ahora = new Date();
            txtLastSync.textContent = 'Última sinc: ' + ahora.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }

        /**
         * Consulta el estado del relé vía AJAX
         */
        async function consultarEstado(mostrarGiro = false) {
            if (enSecuencia) return;

            if (mostrarGiro && btnRefresh) {
                btnRefresh.classList.add('spinning');
            }

            try {
                const r = await fetch('panel2.php?ajax=1&accion=status', { cache: 'no-store' });
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                const data = await r.json();
                renderizarEstado(data);
            } catch (err) {
                console.error('Error al consultar estado:', err);
                renderizarEstado({
                    error: true,
                    mensaje: 'Fallo de comunicación de red: ' + err.message
                });
            } finally {
                if (btnRefresh) {
                    btnRefresh.classList.remove('spinning');
                }
            }
        }

        /**
         * Función auxiliar para generar pausas asíncronas precisas
         */
        const esperar = (ms) => new Promise(resolve => setTimeout(resolve, ms));

        /**
         * Ejecuta la secuencia progresiva al activar el botón de Encendido/Apagado:
         * 1. Canal 0 se activa/desactiva
         * 2. Tras un pequeño delay, Canal 1 se activa/desactiva
         * 3. Tras un pequeño delay, Canal 2 se activa/desactiva
         * 4. Tras un pequeño delay, Canal 3 se activa/desactiva
         * Mostrando el cambio de estado en vivo de cada canal a medida que sucede.
         */
        async function conmutarSecuencia() {
            if (enSecuencia || btnMaster.disabled) return;

            enSecuencia = true;
            btnMaster.disabled = true;
            btnMaster.classList.add('is-loading');
            toastFeedback.textContent = '';

            // Si al menos un canal está encendido, el objetivo es apagarlos todos (false).
            // Si todos están apagados, el objetivo es encenderlos todos (true).
            const targetOn = !(estadoActual && estadoActual.algunoEncendido);
            const accionVerbo = targetOn ? 'ENCENDIENDO' : 'APAGANDO';
            const canales = [0, 1, 2, 3];
            const delayMs = parseInt(selectDelay.value, 10) || 600;

            try {
                for (let i = 0; i < canales.length; i++) {
                    const ch = canales[i];
                    const pill = document.getElementById('canal-badge-' + ch);
                    const txt = document.getElementById('canal-txt-' + ch);

                    // 1. Mostrar estado del paso en el botón y resaltar el canal en conmutación
                    // txtMasterAction.textContent = `${accionVerbo} CANAL ${ch}...`;
                    txtMasterAction.textContent = `${accionVerbo}...`;
                    // txtMasterDesc.textContent = `Paso ${i + 1} de 4 (${delayMs} ms de retardo)`;
                    txtMasterDesc.textContent = '';
                    if (pill) {
                        pill.classList.add('ch-switching');
                    }

                    // 2. Enviar orden RPC rápida al Shelly para el canal actual
                    const url = `panel2.php?ajax=1&accion=set_channel&canal=${ch}&on=${targetOn ? 'true' : 'false'}`;
                    const resp = await fetch(url, { cache: 'no-store' });
                    if (!resp.ok) {
                        throw new Error(`HTTP ${resp.status} al conmutar canal ${ch}`);
                    }
                    const data = await resp.json();
                    if (data.error) {
                        throw new Error(data.mensaje || `Error en canal ${ch}`);
                    }

                    // 3. ACTUALIZAR DE INMEDIATO EN PANTALLA EL ESTADO DEL CANAL TRAS CAMBIAR
                    if (pill) {
                        pill.classList.remove('ch-switching');
                        pill.className = targetOn ? 'channel-pill ch-on' : 'channel-pill ch-off';
                    }
                    if (txt) {
                        txt.textContent = targetOn ? 'ON' : 'OFF';
                    }

                    // Actualizar el contador de canales activos en pantalla en tiempo real
                    const activos = document.querySelectorAll('.channel-pill.ch-on').length;
                    //  txtResumenCanales.textContent = `${activos} de 4 activos`;
                    txtResumenCanales.textContent = '';
                    // badgeGlobal.className = activos > 0 ? 'status-badge badge-on' : 'status-badge badge-off';
                    // txtEstadoGlobal.textContent = activos === 4 ? 'ENCENDIDO (4/4)' : (activos > 0 ? `CAMBIANDO (${activos}/4)` : 'APAGADO (0/4)');
                    txtEstadoGlobal.textContent = '';

                    if (targetOn) {
                        cardMaster.classList.add('is-on');
                    } else if (activos === 0) {
                        cardMaster.classList.remove('is-on');
                    }

                    // 4. ESPERAR EL DELAY ANTES DE ACTIVAR/DESACTIVAR EL SIGUIENTE CANAL
                    if (i < canales.length - 1) {
                        await esperar(delayMs);
                    }
                }

                // Notificación visual de éxito tras completar los 4 canales
                // toastFeedback.style.color = '#34d399';
                //  toastFeedback.textContent = `✓ Los 4 canales se han ${targetOn ? 'activado' : 'desactivado'} en secuencia (0 → 1 → 2 → 3).`;
                // toastFeedback.textContent = '';
                // setTimeout(() => {
                //     if (toastFeedback.style.color === 'rgb(52, 211, 153)') {
                //         toastFeedback.textContent = '';
                //     }
                // }, 4000);

            } catch (err) {
                console.error('Error durante la secuencia de conmutación:', err);
                toastFeedback.style.color = 'var(--err-red)';
                toastFeedback.textContent = 'Error: ' + err.message;
            } finally {
                // Limpiar posibles estilos residuales y sincronizar telemetría completa
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

            // Refresco periódico cada 5 segundos si no hay secuencia activa ni pestaña oculta
            intervaloAutoRefresco = setInterval(() => {
                if (!document.hidden && !enSecuencia) {
                    consultarEstado(false);
                }
            }, 5000);
        });
    </script>
</body>

</html>