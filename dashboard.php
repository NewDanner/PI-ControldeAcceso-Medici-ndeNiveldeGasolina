<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = getConnection();

// ------------------------------------------------------------
// Cambio de modo (normal / crisis) - antes de imprimir HTML
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nuevo_modo'])) {
    $nuevo_modo = $_POST['nuevo_modo'] === 'crisis' ? 'crisis' : 'normal';
    $stmt = $pdo->prepare('UPDATE configuracion SET modo_actual = :modo WHERE id = 1');
    $stmt->execute(['modo' => $nuevo_modo]);
    header('Location: dashboard.php');
    exit;
}

// ------------------------------------------------------------
// Datos: configuración, tanque predeterminado, última lectura
// ------------------------------------------------------------
$config = $pdo->query('SELECT * FROM configuracion WHERE id = 1')->fetch();
$tanquePrincipal = $pdo->query('SELECT * FROM tanques WHERE predeterminado = 1 LIMIT 1')->fetch();

$ultima_lectura = null;
if ($tanquePrincipal) {
    $stmt = $pdo->prepare(
        'SELECT nivel_pct, volumen_litros, fecha_hora
         FROM lecturas_tanque
         WHERE tanque_id = :tanque_id
         ORDER BY fecha_hora DESC
         LIMIT 1'
    );
    $stmt->execute(['tanque_id' => $tanquePrincipal['id']]);
    $ultima_lectura = $stmt->fetch();
}

$nivel_pct      = $ultima_lectura['nivel_pct'] ?? 0;
$volumen_litros = $ultima_lectura['volumen_litros'] ?? 0;
$ultima_fecha   = $ultima_lectura['fecha_hora'] ?? null;
$nivel_critico_activo = $tanquePrincipal['nivel_critico_pct'] ?? $config['nivel_critico_pct'];
$es_critico     = $nivel_pct <= $nivel_critico_activo;

// ------------------------------------------------------------
// Consumo de los últimos 7 días, desglosado por combustible
// ------------------------------------------------------------
$consumo_stmt = $pdo->query(
    "SELECT DATE(d.fecha_hora) AS dia, t.tipo_combustible, SUM(d.litros) AS total
     FROM despachos d
     JOIN tanques t ON t.id = d.tanque_id
     WHERE d.estado = 'completado'
       AND d.fecha_hora >= (CURDATE() - INTERVAL 6 DAY)
     GROUP BY DATE(d.fecha_hora), t.tipo_combustible"
);
$consumo_gasolina = [];
$consumo_diesel   = [];
foreach ($consumo_stmt as $fila) {
    if ($fila['tipo_combustible'] === 'diesel') {
        $consumo_diesel[$fila['dia']] = (float) $fila['total'];
    } else {
        $consumo_gasolina[$fila['dia']] = (float) $fila['total'];
    }
}

$etiquetas_7d = [];
$valores_gasolina = [];
$valores_diesel   = [];
for ($i = 6; $i >= 0; $i--) {
    $dia = date('Y-m-d', strtotime("-$i day"));
    $etiquetas_7d[]      = date('d/m', strtotime($dia));
    $valores_gasolina[]  = $consumo_gasolina[$dia] ?? 0;
    $valores_diesel[]    = $consumo_diesel[$dia] ?? 0;
}

// ------------------------------------------------------------
// Ingresos por modo (últimos 7 días)
// ------------------------------------------------------------
$ingresos_stmt = $pdo->query(
    "SELECT modo, SUM(monto) AS total
     FROM despachos
     WHERE estado = 'completado'
       AND fecha_hora >= (CURDATE() - INTERVAL 6 DAY)
     GROUP BY modo"
);
$ingresos_normal = 0.0;
$ingresos_crisis = 0.0;
foreach ($ingresos_stmt as $fila) {
    if ($fila['modo'] === 'crisis') {
        $ingresos_crisis = (float) $fila['total'];
    } else {
        $ingresos_normal = (float) $fila['total'];
    }
}

// ------------------------------------------------------------
// Tanques registrados
// ------------------------------------------------------------
$tanques = $pdo->query('SELECT * FROM tanques ORDER BY predeterminado DESC, fecha_registro ASC')->fetchAll();

// ------------------------------------------------------------
// Datos para el panel de despacho en vivo
// ------------------------------------------------------------
$tarjetas_activas = $pdo->query(
    'SELECT id, uid_rfid, titular FROM tarjetas_rfid WHERE activo = 1 ORDER BY titular'
)->fetchAll();
$tanques_activos = $pdo->query(
    'SELECT id, nombre, tipo_combustible, precio_litro FROM tanques WHERE activo = 1 ORDER BY nombre'
)->fetchAll();

$espera_crisis_restante = 0;
if ($config['modo_actual'] === 'crisis') {
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
            $espera_crisis_restante = $espera - $segundosDesde;
        }
    }
}

// ------------------------------------------------------------
// Despachos recientes (últimos 10)
// ------------------------------------------------------------
$despachos = $pdo->query(
    "SELECT d.fecha_hora, t.uid_rfid, t.titular, d.litros, d.estado, d.motivo
     FROM despachos d
     JOIN tarjetas_rfid t ON t.id = d.tarjeta_id
     ORDER BY d.fecha_hora DESC
     LIMIT 10"
)->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1><?= icono('dashboard') ?> Dashboard</h1>
        <p class="page-subtitulo">
            Estado del tanque y actividad reciente
            <?php if ($tanquePrincipal): ?>
                · Tanque activo: <strong><?= h($tanquePrincipal['nombre']) ?></strong>
                (<?= $tanquePrincipal['tipo_combustible'] === 'diesel' ? 'Diésel' : 'Gasolina' ?>)
            <?php else: ?>
                · <span class="valor-critico">Sin tanque predeterminado configurado</span>
            <?php endif; ?>
        </p>
    </div>
</div>

<!-- ---------- Fila de tarjetas resumen ---------- -->
<div class="cards-row">

    <div class="card modo-card">
        <div class="card-top">
            <span class="card-label">Modo de distribución</span>
            <span class="badge badge-<?= $config['modo_actual'] ?>">
                <?= $config['modo_actual'] === 'crisis' ? 'CRISIS' : 'NORMAL' ?>
            </span>
        </div>
        <form method="POST" class="modo-form">
            <input type="hidden" name="nuevo_modo"
                   value="<?= $config['modo_actual'] === 'crisis' ? 'normal' : 'crisis' ?>">
            <button type="submit" class="btn-toggle">
                Cambiar a <?= $config['modo_actual'] === 'crisis' ? 'Normal' : 'Crisis' ?>
            </button>
        </form>
        <?php if ($config['modo_actual'] === 'crisis'): ?>
            <small class="card-nota">Límite: <?= number_format($config['limite_crisis_litros'], 2) ?> L / tarjeta</small>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-top">
            <span class="card-label">Nivel del tanque</span>
            <span class="card-icono"><?= icono('tanque') ?></span>
        </div>
        <span class="card-value <?= $es_critico ? 'valor-critico' : '' ?>">
            <?= number_format($nivel_pct, 1) ?>%
        </span>
    </div>

    <div class="card">
        <div class="card-top">
            <span class="card-label">Volumen estimado</span>
            <span class="card-icono"><?= icono('grafico') ?></span>
        </div>
        <span class="card-value"><?= number_format($volumen_litros, 0) ?> L</span>
    </div>

    <div class="card">
        <div class="card-top">
            <span class="card-label">Última actualización</span>
            <span class="card-icono"><?= icono('reloj') ?></span>
        </div>
        <span class="card-value small">
            <?= $ultima_fecha ? date('d/m/Y H:i', strtotime($ultima_fecha)) : 'Sin datos' ?>
        </span>
    </div>
</div>

<!-- ---------- Ingresos por modo (7 días) ---------- -->
<div class="cards-row cards-row-resumen cards-row-dinero">
    <div class="card card-dinero">
        <div class="card-top">
            <span class="card-label">Ingresos modo normal (7 días)</span>
            <span class="card-icono"><?= icono('dinero') ?></span>
        </div>
        <span class="card-value"><?= fmtDinero($ingresos_normal) ?></span>
    </div>
    <div class="card card-dinero">
        <div class="card-top">
            <span class="card-label">Ingresos modo crisis (7 días)</span>
            <span class="card-icono"><?= icono('dinero') ?></span>
        </div>
        <span class="card-value"><?= fmtDinero($ingresos_crisis) ?></span>
    </div>
</div>

<!-- ---------- Despacho en vivo ---------- -->
<div class="panel panel-despacho">
    <h3><?= icono('gota') ?> Despacho de combustible</h3>

    <!-- Estado inactivo: esperando que se lea una tarjeta física -->
    <div id="despacho-inactivo" class="despacho-inactivo">
        <span class="pulso-espera"></span>
        <span>Esperando lectura de tarjeta en el lector RFID...</span>
    </div>

    <div id="despacho-mensaje" class="despacho-mensaje"></div>
    <div id="despacho-espera" class="despacho-espera" style="display:none;">
        <?= icono('reloj') ?> Espera de modo crisis: <strong><span id="espera-segundos">0</span>s</strong> para el próximo despacho
    </div>

    <!-- Modo de prueba manual (colapsado por defecto) -->
    <details class="despacho-manual">
        <summary>¿Sin hardware conectado? Iniciar un despacho de prueba manualmente</summary>
        <div class="form-inline" style="margin-top:.75rem;">
            <div>
                <label for="sel-tarjeta-despacho">Tarjeta</label>
                <select id="sel-tarjeta-despacho">
                    <?php if (empty($tarjetas_activas)): ?>
                        <option value="">No hay tarjetas activas</option>
                    <?php endif; ?>
                    <?php foreach ($tarjetas_activas as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= h($t['titular']) ?> — <?= h($t['uid_rfid']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="sel-tanque-despacho">Tanque</label>
                <select id="sel-tanque-despacho">
                    <?php if (empty($tanques_activos)): ?>
                        <option value="">No hay tanques activos</option>
                    <?php endif; ?>
                    <?php foreach ($tanques_activos as $t): ?>
                        <option value="<?= $t['id'] ?>">
                            <?= h($t['nombre']) ?> (<?= $t['tipo_combustible'] === 'diesel' ? 'Diésel' : 'Gasolina' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" id="btn-iniciar-despacho" class="btn-primary">
                <?= icono('gota') ?> Iniciar despacho de prueba
            </button>
        </div>
    </details>

    <!-- Sesión activa: el surtidor en vivo -->
    <div id="despacho-en-curso" class="despacho-en-curso" style="display:none;">
        <div class="surtidor">
            <div class="surtidor-titular" id="surtidor-titular">—</div>
            <div class="surtidor-lectura">
                <div class="surtidor-bloque">
                    <span class="surtidor-numero" id="surtidor-litros">0.00</span>
                    <span class="surtidor-unidad">Litros</span>
                </div>
                <div class="surtidor-bloque">
                    <span class="surtidor-numero" id="surtidor-monto">0.00</span>
                    <span class="surtidor-unidad">Bs</span>
                </div>
            </div>
            <div id="surtidor-limite" class="surtidor-limite" style="display:none;">
                Límite modo crisis: <strong><span id="surtidor-limite-valor">5.00</span> L</strong>
            </div>
            <button type="button" id="btn-detener-despacho" class="btn-detener">
                <?= icono('alerta') ?> Detener despacho
            </button>
        </div>
    </div>
</div>

<!-- ---------- Tanque visual + gráfico de consumo ---------- -->
<div class="panels-row">

    <div class="panel panel-tanque">
        <h3><?= icono('tanque') ?> Tanque subterráneo</h3>
        <div class="tanque">
            <div class="tanque-liquido <?= $es_critico ? 'nivel-critico' : '' ?>"
                 style="height: <?= max(0, min(100, $nivel_pct)) ?>%;"></div>
            <span class="tanque-porcentaje"><?= number_format($nivel_pct, 1) ?>%</span>
        </div>
        <?php if ($es_critico): ?>
            <p class="aviso-critico"><?= icono('alerta') ?> Nivel crítico</p>
        <?php endif; ?>
    </div>

    <div class="panel panel-chart">
        <h3><?= icono('grafico') ?> Consumo últimos 7 días (por combustible)</h3>
        <canvas id="chartConsumo"></canvas>
    </div>
</div>

<!-- ---------- Tanques registrados ---------- -->
<div class="panel panel-tabla">
    <h3><?= icono('cilindro') ?> Tanques registrados</h3>
    <table class="tabla-despachos">
        <thead>
            <tr>
                <th></th><th>Nombre</th><th>Combustible</th>
                <th>Altura</th><th>Diámetro</th><th>Radio</th>
                <th>Capacidad</th><th>Precio/L</th><th>Estado</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tanques)): ?>
                <tr><td colspan="9" class="sin-datos">
                    Sin tanques registrados — ve a <a href="tanques.php">Tanques</a> para registrar el primero.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($tanques as $t): ?>
                <tr>
                    <td><?= $t['predeterminado'] ? icono('estrella') : '' ?></td>
                    <td><strong><?= h($t['nombre']) ?></strong></td>
                    <td><?= $t['tipo_combustible'] === 'diesel' ? 'Diésel' : 'Gasolina' ?></td>
                    <td><?= number_format($t['altura_cm'], 1) ?> cm</td>
                    <td><?= number_format($t['diametro_cm'], 1) ?> cm</td>
                    <td><?= number_format($t['radio_cm'], 1) ?> cm</td>
                    <td><?= number_format($t['capacidad_litros'], 0) ?> L</td>
                    <td><?= fmtDinero((float) $t['precio_litro']) ?></td>
                    <td><?= badgeEstado((bool) $t['activo'], 'Activo', 'Inactivo') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p class="page-subtitulo" style="margin-top:.75rem;">
        <a href="tanques.php"><?= icono('mas') ?> Gestionar tanques</a>
    </p>
</div>

<!-- ---------- Despachos recientes ---------- -->
<div class="panel panel-tabla">
    <h3><?= icono('lista') ?> Despachos recientes</h3>
    <table class="tabla-despachos">
        <thead>
            <tr>
                <th>Fecha/Hora</th>
                <th>RFID</th>
                <th>Titular</th>
                <th>Litros</th>
                <th>Estado</th>
                <th>Motivo</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($despachos)): ?>
                <tr><td colspan="6" class="sin-datos">Sin despachos registrados</td></tr>
            <?php endif; ?>
            <?php foreach ($despachos as $d): ?>
                <tr>
                    <td><?= fmtFechaHora($d['fecha_hora']) ?></td>
                    <td><code><?= h($d['uid_rfid']) ?></code></td>
                    <td><?= h($d['titular']) ?></td>
                    <td><?= fmtLitros((float) $d['litros']) ?></td>
                    <td><?= badgeEstado($d['estado'] === 'completado', 'Completado', 'Rechazado') ?></td>
                    <td><?= $d['motivo'] ? h($d['motivo']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
// ===================== Despacho en vivo (sondeo real) =====================
const INTERVALO_SONDEO_MS = 1000;

const inactivoDiv   = document.getElementById('despacho-inactivo');
const manualDetails  = document.querySelector('.despacho-manual');
const enCursoPanel  = document.getElementById('despacho-en-curso');
const mensajeDiv    = document.getElementById('despacho-mensaje');
const esperaDiv      = document.getElementById('despacho-espera');
const esperaSegSpan  = document.getElementById('espera-segundos');

const surtidorTitular = document.getElementById('surtidor-titular');
const surtidorLitros  = document.getElementById('surtidor-litros');
const surtidorMonto   = document.getElementById('surtidor-monto');
const surtidorLimiteBox = document.getElementById('surtidor-limite');
const surtidorLimiteValor = document.getElementById('surtidor-limite-valor');

let habiaSesionActiva = false;
let cuentaRegresivaCrisis = null;

function mostrarMensaje(texto, tipo) {
    mensajeDiv.innerHTML = texto ? `<div class="alerta-${tipo}">${texto}</div>` : '';
}

function iniciarCooldownCrisis(segundos) {
    clearInterval(cuentaRegresivaCrisis);
    let restante = Math.ceil(segundos);
    esperaDiv.style.display = 'flex';
    esperaSegSpan.textContent = restante;

    cuentaRegresivaCrisis = setInterval(() => {
        restante--;
        esperaSegSpan.textContent = Math.max(0, restante);
        if (restante <= 0) {
            clearInterval(cuentaRegresivaCrisis);
            esperaDiv.style.display = 'none';
        }
    }, 1000);
}

function mostrarEstadoInactivo() {
    inactivoDiv.style.display = 'flex';
    manualDetails.style.display = 'block';
    enCursoPanel.style.display = 'none';
}

function mostrarEstadoEnCurso(data) {
    inactivoDiv.style.display = 'none';
    manualDetails.style.display = 'none';
    enCursoPanel.style.display = 'block';

    surtidorTitular.textContent = data.titular;
    surtidorLitros.textContent = data.litros_actual.toFixed(2);
    surtidorMonto.textContent = data.monto_actual.toFixed(2);

    if (data.limite_litros) {
        surtidorLimiteBox.style.display = 'block';
        surtidorLimiteValor.textContent = data.limite_litros.toFixed(2);
    } else {
        surtidorLimiteBox.style.display = 'none';
    }
}

async function sondearSesion() {
    try {
        const resp = await fetch('api/estado_sesion.php');
        const data = await resp.json();

        if (data.activa) {
            habiaSesionActiva = true;
            mostrarEstadoEnCurso(data);
        } else {
            if (habiaSesionActiva) {
                // Se acaba de cerrar (manual o por límite de crisis)
                mostrarEstadoInactivo();
                if (data.recien_finalizada) {
                    mostrarMensaje(
                        `${data.motivo} — Despacho registrado: ${data.litros.toFixed(2)} L — Bs ${data.monto.toFixed(2)}`,
                        'exito'
                    );
                    <?php if ($config['modo_actual'] === 'crisis'): ?>
                    iniciarCooldownCrisis(<?= (int) $config['crisis_espera_segundos'] ?>);
                    <?php endif; ?>
                }
            }
            habiaSesionActiva = false;
        }
    } catch (e) {
        // Sin conexión momentánea: se reintenta en el próximo sondeo
    }
}

setInterval(sondearSesion, INTERVALO_SONDEO_MS);
sondearSesion();

<?php if ($espera_crisis_restante > 0): ?>
iniciarCooldownCrisis(<?= (int) $espera_crisis_restante ?>);
<?php endif; ?>

document.getElementById('btn-iniciar-despacho').addEventListener('click', async () => {
    const tarjetaId = document.getElementById('sel-tarjeta-despacho').value;
    const tanqueId  = document.getElementById('sel-tanque-despacho').value;

    if (!tarjetaId || !tanqueId) {
        mostrarMensaje('Selecciona tarjeta y tanque.', 'error');
        return;
    }

    mostrarMensaje('', '');
    const resp = await fetch('ajax_iniciar_despacho.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `tarjeta_id=${encodeURIComponent(tarjetaId)}&tanque_id=${encodeURIComponent(tanqueId)}`,
    });
    const data = await resp.json();

    if (!data.ok) {
        mostrarMensaje(data.motivo, 'error');
        if (data.espera_segundos) iniciarCooldownCrisis(data.espera_segundos);
        return;
    }

    sondearSesion(); // refleja la nueva sesión de inmediato
});

document.getElementById('btn-detener-despacho').addEventListener('click', async () => {
    const resp = await fetch('detener_sesion.php', { method: 'POST' });
    const data = await resp.json();

    if (data.ok) {
        mostrarEstadoInactivo();
        habiaSesionActiva = false;
        mostrarMensaje(`Despacho registrado: ${data.litros.toFixed(2)} L — Bs ${data.monto.toFixed(2)}`, 'exito');
        <?php if ($config['modo_actual'] === 'crisis'): ?>
        iniciarCooldownCrisis(<?= (int) $config['crisis_espera_segundos'] ?>);
        <?php endif; ?>
    } else {
        mostrarMensaje(data.motivo, 'error');
    }
});
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('chartConsumo'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($etiquetas_7d) ?>,
        datasets: [
            {
                label: 'Gasolina (L)',
                data: <?= json_encode($valores_gasolina) ?>,
                backgroundColor: '#16a34a',
                borderRadius: 6,
                maxBarThickness: 28
            },
            {
                label: 'Diésel (L)',
                data: <?= json_encode($valores_diesel) ?>,
                backgroundColor: '#f59e0b',
                borderRadius: 6,
                maxBarThickness: 28
            }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: true, position: 'bottom' } },
        scales: {
            y: { beginAtZero: true, grid: { color: '#eef2f7' } },
            x: { grid: { display: false } }
        }
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
