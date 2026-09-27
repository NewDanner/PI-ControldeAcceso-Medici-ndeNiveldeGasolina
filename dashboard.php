<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/config/database.php';

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
// Datos: configuración, última lectura del tanque
// ------------------------------------------------------------
$config = $pdo->query('SELECT * FROM configuracion WHERE id = 1')->fetch();

$ultima_lectura = $pdo->query(
    'SELECT nivel_pct, volumen_litros, fecha_hora
     FROM lecturas_tanque
     ORDER BY fecha_hora DESC
     LIMIT 1'
)->fetch();

$nivel_pct      = $ultima_lectura['nivel_pct'] ?? 0;
$volumen_litros = $ultima_lectura['volumen_litros'] ?? 0;
$ultima_fecha   = $ultima_lectura['fecha_hora'] ?? null;
$es_critico     = $nivel_pct <= $config['nivel_critico_pct'];

// ------------------------------------------------------------
// Consumo de los últimos 7 días (litros despachados por día)
// ------------------------------------------------------------
$consumo_stmt = $pdo->query(
    "SELECT DATE(fecha_hora) AS dia, SUM(litros) AS total
     FROM despachos
     WHERE estado = 'completado'
       AND fecha_hora >= (CURDATE() - INTERVAL 6 DAY)
     GROUP BY DATE(fecha_hora)"
);
$consumo_por_dia = [];
foreach ($consumo_stmt as $fila) {
    $consumo_por_dia[$fila['dia']] = (float) $fila['total'];
}

$etiquetas_7d = [];
$valores_7d   = [];
for ($i = 6; $i >= 0; $i--) {
    $dia = date('Y-m-d', strtotime("-$i day"));
    $etiquetas_7d[] = date('d/m', strtotime($dia));
    $valores_7d[]   = $consumo_por_dia[$dia] ?? 0;
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

<h1>Dashboard</h1>

<!-- ---------- Fila de tarjetas resumen ---------- -->
<div class="cards-row">

    <div class="card modo-card">
        <span class="card-label">Modo de distribución</span>
        <span class="badge badge-<?= $config['modo_actual'] ?>">
            <?= $config['modo_actual'] === 'crisis' ? 'CRISIS' : 'NORMAL' ?>
        </span>
        <form method="POST" class="modo-form">
            <input type="hidden" name="nuevo_modo"
                   value="<?= $config['modo_actual'] === 'crisis' ? 'normal' : 'crisis' ?>">
            <button type="submit" class="btn-toggle">
                Cambiar a <?= $config['modo_actual'] === 'crisis' ? 'Normal' : 'Crisis' ?>
            </button>
        </form>
        <?php if ($config['modo_actual'] === 'crisis'): ?>
            <small>Límite: <?= number_format($config['limite_crisis_litros'], 2) ?> L / tarjeta</small>
        <?php endif; ?>
    </div>

    <div class="card">
        <span class="card-label">Nivel del tanque</span>
        <span class="card-value <?= $es_critico ? 'valor-critico' : '' ?>">
            <?= number_format($nivel_pct, 1) ?>%
        </span>
    </div>

    <div class="card">
        <span class="card-label">Volumen estimado</span>
        <span class="card-value"><?= number_format($volumen_litros, 0) ?> L</span>
    </div>

    <div class="card">
        <span class="card-label">Última actualización</span>
        <span class="card-value small">
            <?= $ultima_fecha ? date('d/m/Y H:i', strtotime($ultima_fecha)) : 'Sin datos' ?>
        </span>
    </div>
</div>

<!-- ---------- Tanque visual + gráfico de consumo ---------- -->
<div class="panels-row">

    <div class="panel panel-tanque">
        <h3>Tanque subterráneo</h3>
        <div class="tanque">
            <div class="tanque-liquido <?= $es_critico ? 'nivel-critico' : '' ?>"
                 style="height: <?= max(0, min(100, $nivel_pct)) ?>%;"></div>
            <span class="tanque-porcentaje"><?= number_format($nivel_pct, 1) ?>%</span>
        </div>
        <?php if ($es_critico): ?>
            <p class="aviso-critico">⚠ Nivel crítico</p>
        <?php endif; ?>
    </div>

    <div class="panel panel-chart">
        <h3>Consumo últimos 7 días</h3>
        <canvas id="chartConsumo"></canvas>
    </div>
</div>

<!-- ---------- Despachos recientes ---------- -->
<div class="panel panel-tabla">
    <h3>Despachos recientes</h3>
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
                    <td><?= date('d/m/Y H:i', strtotime($d['fecha_hora'])) ?></td>
                    <td><?= htmlspecialchars($d['uid_rfid']) ?></td>
                    <td><?= htmlspecialchars($d['titular']) ?></td>
                    <td><?= number_format($d['litros'], 2) ?></td>
                    <td>
                        <span class="estado estado-<?= $d['estado'] ?>">
                            <?= $d['estado'] === 'completado' ? 'Completado' : 'Rechazado' ?>
                        </span>
                    </td>
                    <td><?= $d['motivo'] ? htmlspecialchars($d['motivo']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('chartConsumo'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($etiquetas_7d) ?>,
        datasets: [{
            label: 'Litros despachados',
            data: <?= json_encode($valores_7d) ?>,
            backgroundColor: '#16a34a'
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
