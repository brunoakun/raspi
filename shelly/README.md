================================================================================
EXPLICACIÓN DETALLADA DEL CÓDIGO (shelly/index.php)
================================================================================

Este archivo implementa un panel de control web ligero y autónomo para gestionar
dispositivos relé Shelly (generaciones Plus / Pro / Gen 2 y posteriores) a través 
de la red local mediante su API RPC HTTP, sin depender de servicios en la nube.

El archivo contiene en un solo lugar:
1. Configuración y catálogo de dispositivos (PHP).
2. Cliente de comunicación RPC con los Shelly mediante cURL (PHP).
3. Backend / API interna que atiende peticiones AJAX (PHP).
4. Interfaz gráfica de usuario con diseño CSS (HTML/CSS).
5. Lógica del navegador para consultar y cambiar estados en tiempo real (JavaScript).

A continuación se detalla cada sección del archivo:

--------------------------------------------------------------------------------
1. CONFIGURACIÓN Y REPORTE DE ERRORES (Líneas 1 - 4)
--------------------------------------------------------------------------------
- ini_set('display_errors', 1);
- ini_set('display_startup_errors', 1);
- error_reporting(E_ALL);

Activan la visualización de cualquier error, advertencia o aviso de PHP directamente 
en pantalla. Es útil durante el desarrollo y depuración local.

--------------------------------------------------------------------------------
2. LISTA DE DISPOSITIVOS CONFIGURADOS (Líneas 6 - 19)
--------------------------------------------------------------------------------
La variable `$dispositivos` es un array donde se declaran los dispositivos Shelly 
que estarán disponibles en el panel. Cada elemento tiene:
- 'id': Identificador único interno para la aplicación (ej. 'luz_01').
- 'nombre': Nombre descriptivo que se muestra al usuario (ej. 'Luz uno').
- 'ip': Dirección IP local asignada al Shelly en la red WiFi/Ethernet (ej. '192.168.1.50').
- 'canal': Número del canal o relé a controlar (habitualmente 0 para dispositivos 
  de un solo canal o el primer canal de un Shelly doble/múltiple).

Para añadir más relés al sistema, basta con agregar nuevos elementos a este array.

--------------------------------------------------------------------------------
3. FUNCIÓN DE COMUNICACIÓN RPC: llamadaShellyRpc() (Líneas 21 - 39)
--------------------------------------------------------------------------------
Función: llamadaShellyRpc($ip, $metodo, $params = [])

Los dispositivos Shelly modernos (Gen 2+) utilizan una API RPC mediante peticiones HTTP.
Esta función se encarga de realizar la petición HTTP GET al dispositivo:

- URL destino: http://{IP}/rpc/{METODO}?param1=val1...
- cURL: Se usa la librería cURL de PHP para realizar la solicitud.
- Timeouts: 
  * CURLOPT_CONNECTTIMEOUT = 2 segundos (tiempo máximo para establecer conexión).
  * CURLOPT_TIMEOUT = 3 segundos (tiempo total máximo de espera de respuesta).
  Esto evita que la página se quede congelada si un dispositivo está apagado o fuera de red.
- Gestión de errores: Si la conexión falla o el código HTTP es distinto de 200, 
  devuelve un array con `['error' => true, 'mensaje' => ...]`.
- Si tiene éxito: Decodifica la respuesta JSON devuelta por el Shelly y la entrega 
  como un array asociativo de PHP.

--------------------------------------------------------------------------------
4. ENDPOINT BACKEND / MANEJADOR AJAX (Líneas 41 - 67)
--------------------------------------------------------------------------------
Se ejecuta únicamente cuando la URL incluye el parámetro `?ajax=...` 
(por ejemplo: index.php?ajax=1&id=luz_01&accion=toggle).

Funciona como un proxy intermedio entre el navegador y el dispositivo Shelly:
- Establece la cabecera `Content-Type: application/json`.
- Busca el dispositivo solicitado por su `id` dentro del array `$dispositivos`.
- Si el ID no existe, responde con un mensaje de error en JSON y finaliza con `exit`.
- Según la acción solicitada (`accion`), invoca el método correspondiente del Shelly:
  * 'toggle': Llama a 'Switch.Toggle' con el canal del relé (invierte el estado actual).
  * 'on': Llama a 'Switch.Set' con ['on' => 'true'] (enciende el relé).
  * 'off': Llama a 'Switch.Set' con ['on' => 'false'] (apaga el relé).
  * Por defecto ('status'): Llama a 'Switch.GetStatus' para consultar si está encendido 
    o apagado, consumo, etc.
- Imprime la respuesta obtenida en formato JSON y termina la ejecución (`exit`) para no 
  renderizar el HTML que viene después.

Ventaja de este proxy:
Evita problemas de CORS (Cross-Origin Resource Sharing) en el navegador del cliente 
y centraliza la autenticación o configuración en el servidor web.

--------------------------------------------------------------------------------
5. INTERFAZ GRÁFICA Y ESTILOS CSS (Líneas 69 - 187)
--------------------------------------------------------------------------------
Si la petición NO es AJAX, se renderiza la página web completa:
- <head> y <style>:
  * Diseño limpio y adaptable a dispositivos móviles (responsive).
  * Tarjetas blancas (.card) con sombra para cada relé.
  * Etiquetas de estado (.estado) con distintos colores:
    - Verde (.estado-on): Encendido.
    - Gris (.estado-off): Apagado.
    - Rojo (.estado-err): Desconectado o error de red.
  * Botón azul (.btn-toggle) para accionar el relé.

- <body> y bucle PHP:
  * Se recorre el array `$dispositivos` mediante un bucle `foreach`.
  * Para cada dispositivo se crea su tarjeta HTML con:
    - Nombre del dispositivo.
    - Dirección IP.
    - Badge de estado con texto inicial "Consultando...".
    - Botón "Conmutar" que al pulsar ejecuta la función JS: ejecutar('id', 'toggle').
  * Se usa `htmlspecialchars()` para evitar inyecciones de código o fallos de renderizado.

--------------------------------------------------------------------------------
6. LÓGICA JAVASCRIPT EN EL NAVEGADOR (Líneas 188 - 222)
--------------------------------------------------------------------------------
Gestiona la interactividad de la página de forma asíncrona sin recargar la pantalla:

- actualizarEstado(id):
  * Lanza una petición con `fetch` a: `?ajax=1&id=${id}&accion=status`.
  * El Shelly devuelve en su JSON una clave llamada `output` (true = encendido, false = apagado).
  * Si la respuesta contiene `output`:
    - Si es true: actualiza la etiqueta a "ENCENDIDO" (verde).
    - Si es false: actualiza la etiqueta a "APAGADO" (gris).
  * Si el dispositivo no responde o da error:
    - Muestra "DESCONECTADO" o "ERROR RED" (rojo).

- ejecutar(id, accion):
  * Deshabilita temporalmente el botón (`btn.disabled = true`) para evitar múltiples clics rápidos.
  * Lanza una petición `fetch` a: `?ajax=1&id=${id}&accion=${accion}`.
  * Tras recibir la respuesta, llama a `actualizarEstado(id)` para reflejar el nuevo estado en pantalla.
  * Captura posibles errores de conexión y avisa con una alerta en caso de fallo.
  * En el bloque `.finally()`, vuelve a habilitar el botón (`btn.disabled = false`).

- Evento DOMContentLoaded:
  * Al terminar de cargar el DOM de la página, PHP genera llamadas a `actualizarEstado()` 
    para cada dispositivo configurado, consultando automáticamente su estado inicial.

================================================================================
RESUMEN DEL FLUJO DE TRABAJO
================================================================================
1. El usuario abre la página en el navegador.
2. PHP genera la interfaz con la lista de relés.
3. JavaScript consulta automáticamente el estado de cada relé vía AJAX.
4. El backend en PHP recibe la petición AJAX, se conecta al Shelly por cURL (RPC) 
   y devuelve el JSON al navegador.
5. El navegador pinta el estado ("ENCENDIDO", "APAGADO" o "DESCONECTADO").
6. Cuando el usuario pulsa "Conmutar", JavaScript solicita el cambio a PHP, 
   PHP ordena al Shelly alternar su estado, y se actualiza el indicador visual.
================================================================================
