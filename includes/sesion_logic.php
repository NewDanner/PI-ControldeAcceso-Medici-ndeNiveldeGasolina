<?php
// ============================================================
// Sesiones de despacho en vivo — compartida por:
//   - api/iniciar_sesion.php   (el ESP32, vía el puente USB)
//   - ajax_iniciar_despacho.php (modo de prueba manual, sin hardware)
//   - api/estado_sesion.php    (consulta en vivo + auto-corte en crisis)
//   - detener_sesion.php       (botón "Detener despacho" en el dashboard)
//
// Solo puede existir UNA sesión "en_curso" a la vez (un solo surtidor).
//
// IMPORTANTE: todo el cálculo de "cuánto tiempo pasó" se hace con
// TIMESTAMPDIFF() dentro de MySQL, nunca comparando time() de PHP
// contra una fecha guardada por MySQL — si el servidor web y la
// base de datos tienen zonas horarias distintas, esa comparación
// da resultados absurdos (sesiones que "ya nacen viejas").
// ============================================================

const FLUJO_LITROS_POR_SEGUNDO = 0.2; // 12 L/min — debe coincidir con el firmware
const MAX_DURACION_SESION_SEG  = 1200; // 20 min: si nadie la detiene, se da por abandonada

/**
 * Devuelve la sesión "en_curso" vigente (con 'segundos_transcurridos'
 * ya calculado por MySQL), o null si no hay ninguna. Si la que
 * encuentra lleva corriendo más de MAX_DURACION_SESION_SEG, la cierra
 * sola como abandonada — sin registrarla como una venta real.
 */
function obtenerSesionActiva(PDO $pdo): ?array {
    $sesion = $pdo->query(
        "SELECT *, TIMESTAMPDIFF(SECOND, inicio, NOW()) AS segundos_transcurridos
         FROM sesiones_despacho
         WHERE estado = 'en_curso'
         ORDER BY id DESC LIMIT 1"
    )->fetch();

    if (!$sesion) {
        return null;
    }

    if ((int) $sesion['segundos_transcurridos'] > MAX_DURACION_SESION_SEG) {
        cerrarSesionAbandonada($pdo, $sesion);
        return null;
    }

    return $sesion;
}

/** Cierra una sesión que quedó colgada, registrando el despacho como
 *  rechazado (no se cobra) para que quede trazabilidad del incidente. */
function cerrarSesionAbandonada(PDO $pdo, array $sesion): void {
    $pdo->prepare(
        'INSERT INTO despachos (tarjeta_id, tanque_id, litros, monto, modo, estado, motivo)
         VALUES (:tarjeta_id, :tanque_id, 0, 0, :modo, "rechazado", "Sesión abandonada (nadie detuvo el despacho)")'
    )->execute([
        'tarjeta_id' => $sesion['tarjeta_id'],
        'tanque_id'  => $sesion['tanque_id'],
        'modo'       => $sesion['modo'],
    ]);

    $pdo->prepare(
        "UPDATE sesiones_despacho SET estado = 'finalizado', fin = NOW() WHERE id = :id"
    )->execute(['id' => $sesion['id']]);
}

/**
 * Valida tarjeta/tanque/nivel/cooldown de crisis y crea la sesión si
 * todo está en orden. $tanqueIdForzado = null usa el tanque predeterminado
 * (flujo del hardware); si se pasa un id, se usa ese (flujo manual).
 */
function validarYCrearSesion(PDO $pdo, string $uidRfid, ?int $tanqueIdForzado, string $origen): array {
    $uidRfid = strtoupper(trim($uidRfid));

    if (obtenerSesionActiva($pdo)) {
        return ['ok' => false, 'motivo' => 'Ya hay un despacho en curso'];
    }

    $stmt = $pdo->prepare('SELECT id, activo, titular FROM tarjetas_rfid WHERE uid_rfid = :uid');
    $stmt->execute(['uid' => $uidRfid]);
    $tarjeta = $stmt->fetch();

    if (!$tarjeta) {
        return ['ok' => false, 'motivo' => 'Tarjeta no registrada'];
    }
    if (!$tarjeta['activo']) {
        return ['ok' => false, 'motivo' => 'Tarjeta inactiva'];
    }

    if ($tanqueIdForzado !== null) {
        $stmt = $pdo->prepare('SELECT * FROM tanques WHERE id = :id');
        $stmt->execute(['id' => $tanqueIdForzado]);
        $tanque = $stmt->fetch();
    } else {
        $tanque = $pdo->query('SELECT * FROM tanques WHERE predeterminado = 1 LIMIT 1')->fetch();
    }

    if (!$tanque) {
        return ['ok' => false, 'motivo' => 'No hay tanque configurado'];
    }
    if (!$tanque['activo']) {
        return ['ok' => false, 'motivo' => 'Ese tanque está inactivo'];
    }

    $stmt = $pdo->prepare('SELECT nivel_pct FROM lecturas_tanque WHERE tanque_id = :id ORDER BY fecha_hora DESC LIMIT 1');
    $stmt->execute(['id' => $tanque['id']]);
    $nivelActual = (float) ($stmt->fetch()['nivel_pct'] ?? 0);

    if ($nivelActual <= (float) $tanque['nivel_critico_pct']) {
        return ['ok' => false, 'motivo' => 'Nivel de tanque crítico'];
    }

    $config = $pdo->query('SELECT * FROM configuracion WHERE id = 1')->fetch();
    $modo = $config['modo_actual'];

    if ($modo === 'crisis') {
        $ultimoCrisis = $pdo->query(
            "SELECT TIMESTAMPDIFF(SECOND, fecha_hora, NOW()) AS segundos_desde
             FROM despachos
             WHERE modo = 'crisis' AND estado = 'completado'
             ORDER BY fecha_hora DESC LIMIT 1"
        )->fetch();
        if ($ultimoCrisis) {
            $segundosDesde = (int) $ultimoCrisis['segundos_desde'];
            $espera = (int) $config['crisis_espera_segundos'];
            if ($segundosDesde < $espera) {
                return ['ok' => false, 'motivo' => 'En espera por modo crisis', 'espera_segundos' => $espera - $segundosDesde];
            }
        }
    }

    $limite = $modo === 'crisis' ? (float) $config['limite_crisis_litros'] : null;

    $stmt = $pdo->prepare(
        'INSERT INTO sesiones_despacho (tarjeta_id, tanque_id, modo, precio_litro, limite_litros, origen)
         VALUES (:tarjeta_id, :tanque_id, :modo, :precio, :limite, :origen)'
    );
    $stmt->execute([
        'tarjeta_id' => $tarjeta['id'],
        'tanque_id'  => $tanque['id'],
        'modo'       => $modo,
        'precio'     => $tanque['precio_litro'],
        'limite'     => $limite,
        'origen'     => $origen,
    ]);

    return [
        'ok'            => true,
        'sesion_id'     => (int) $pdo->lastInsertId(),
        'titular'       => $tarjeta['titular'],
        'modo'          => $modo,
        'precio_litro'  => (float) $tanque['precio_litro'],
        'limite_litros' => $limite,
    ];
}

/** $sesion debe venir de obtenerSesionActiva() (trae segundos_transcurridos). */
function calcularLitrosActuales(array $sesion): float {
    $segundos = max(0, (int) $sesion['segundos_transcurridos']);
    $litros = $segundos * FLUJO_LITROS_POR_SEGUNDO;

    if ($sesion['limite_litros'] !== null) {
        $litros = min($litros, (float) $sesion['limite_litros']);
    }
    return $litros;
}

/** Registra el despacho final en `despachos` y cierra la sesión. */
function finalizarSesion(PDO $pdo, array $sesion, float $litros): array {
    $litros = max(0.0, $litros);
    if ($sesion['limite_litros'] !== null) {
        $litros = min($litros, (float) $sesion['limite_litros']);
    }
    $monto = round($litros * (float) $sesion['precio_litro'], 2);

    $pdo->prepare(
        'INSERT INTO despachos (tarjeta_id, tanque_id, litros, monto, modo, estado, motivo)
         VALUES (:tarjeta_id, :tanque_id, :litros, :monto, :modo, "completado", NULL)'
    )->execute([
        'tarjeta_id' => $sesion['tarjeta_id'],
        'tanque_id'  => $sesion['tanque_id'],
        'litros'     => round($litros, 2),
        'monto'      => $monto,
        'modo'       => $sesion['modo'],
    ]);

    $pdo->prepare(
        "UPDATE sesiones_despacho
         SET estado = 'finalizado', litros_finales = :litros, monto_final = :monto, fin = NOW()
         WHERE id = :id"
    )->execute(['litros' => round($litros, 2), 'monto' => $monto, 'id' => $sesion['id']]);

    return ['litros' => round($litros, 2), 'monto' => $monto];
}

/** Acepta autenticación por API key (hardware) o por sesión web (dashboard). */
function autenticarApiOSesion(): bool {
    require_once __DIR__ . '/../config/api.php';
    $headers = getallheaders();
    $apiKey = $headers['X-Api-Key'] ?? $headers['X-API-Key'] ?? '';
    if ($apiKey === API_KEY) {
        return true;
    }
    require_once __DIR__ . '/auth.php';
    return isLoggedIn();
}
