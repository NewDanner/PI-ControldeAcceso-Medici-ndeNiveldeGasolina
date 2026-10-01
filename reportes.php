<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['administrador']);
require_once __DIR__ . '/config/database.php';

$pdo = getConnection();

$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-d', strtotime('-7 day'));
$fecha_fin    = $_GET['fecha_fin']    ?? date('Y-m-d');
$formato      = $_GET['formato']      ?? '';

if (!strtotime($fecha_inicio)) { $fecha_inicio = date('Y-m-d', strtotime('-7 day')); }
if (!strtotime($fecha_fin))    { $fecha_fin    = date('Y-m-d'); }

// ------------------------------------------------------------
// Tanques disponibles y selección del administrador
// ------------------------------------------------------------
$todosLosTanques = $pdo->query('SELECT id, nombre, tipo_combustible FROM tanques ORDER BY nombre')->fetchAll();

if (isset($_GET['enviado'])) {
    // El formulario ya se envió: respeta exactamente lo que se marcó
    // (incluso si el administrador dejó todo sin marcar).
    $tanquesSeleccionados = array_map('intval', $_GET['tanques'] ?? []);
} else {
    // Primera visita a la página: por defecto, todos los tanques.
    $tanquesSeleccionados = array_map(fn($t) => (int) $t['id'], $todosLosTanques);
}

// Si no hay ningún tanque seleccionado (o no hay tanques), evita una
// consulta con "IN ()" inválida.
$hayTanquesSeleccionados = count($tanquesSeleccionados) > 0;
$placeholders = $hayTanquesSeleccionados ? implode(',', array_fill(0, count($tanquesSeleccionados), '?')) : "0";

// ------------------------------------------------------------
// Resumen por tanque: litros e ingresos en el periodo filtrado
// ------------------------------------------------------------
$resumenPorTanque = [];
if ($hayTanquesSeleccionados) {
    $sqlResumen = "
        SELECT t.id, t.nombre, t.tipo_combustible,
               COALESCE(SUM(CASE WHEN d.estado = 'completado' THEN d.litros ELSE 0 END), 0) AS litros_totales,
               COALESCE(SUM(CASE WHEN d.estado = 'completado' THEN d.monto  ELSE 0 END), 0) AS monto_total,
               SUM(CASE WHEN d.estado = 'completado' THEN 1 ELSE 0 END) AS despachos_completados,
               SUM(CASE WHEN d.estado = 'rechazado'  THEN 1 ELSE 0 END) AS despachos_rechazados
        FROM tanques t
        LEFT JOIN despachos d
               ON d.tanque_id = t.id
              AND DATE(d.fecha_hora) BETWEEN ? AND ?
        WHERE t.id IN ($placeholders)
        GROUP BY t.id, t.nombre, t.tipo_combustible
        ORDER BY t.nombre
    ";
    $stmt = $pdo->prepare($sqlResumen);
    $stmt->execute(array_merge([$fecha_inicio, $fecha_fin], $tanquesSeleccionados));
    $resumenPorTanque = $stmt->fetchAll();
}

$montoTotalGeneral  = 0.0;
$litrosTotalGeneral = 0.0;
foreach ($resumenPorTanque as $r) {
    $montoTotalGeneral  += (float) $r['monto_total'];
    $litrosTotalGeneral += (float) $r['litros_totales'];
}

// ------------------------------------------------------------
// Detalle de despachos (para la tabla y las exportaciones)
// ------------------------------------------------------------
$resultados = [];
if ($hayTanquesSeleccionados) {
    $sqlDetalle = "
        SELECT d.fecha_hora, tr.uid_rfid, tr.titular, d.litros, d.monto, d.modo, d.estado, d.motivo,
               tk.nombre AS tanque_nombre, tk.tipo_combustible
        FROM despachos d
        JOIN tarjetas_rfid tr ON tr.id = d.tarjeta_id
        JOIN tanques tk       ON tk.id = d.tanque_id
        WHERE DATE(d.fecha_hora) BETWEEN ? AND ?
          AND d.tanque_id IN ($placeholders)
        ORDER BY d.fecha_hora ASC
    ";
    $stmt = $pdo->prepare($sqlDetalle);
    $stmt->execute(array_merge([$fecha_inicio, $fecha_fin], $tanquesSeleccionados));
    $resultados = $stmt->fetchAll();
}

// ------------------------------------------------------------
// Exportación CSV
// ------------------------------------------------------------
if ($formato === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_' . $fecha_inicio . '_' . $fecha_fin . '.csv"');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    fputcsv($out, ['Resumen por tanque - Periodo', "$fecha_inicio a $fecha_fin"]);
    fputcsv($out, ['Tanque', 'Combustible', 'Litros', 'Ingreso (Bs)', 'Despachos OK', 'Despachos rechazados']);
    foreach ($resumenPorTanque as $r) {
        fputcsv($out, [
            $r['nombre'],
            $r['tipo_combustible'],
            number_format((float) $r['litros_totales'], 2, '.', ''),
            number_format((float) $r['monto_total'], 2, '.', ''),
            $r['despachos_completados'],
            $r['despachos_rechazados'],
        ]);
    }
    fputcsv($out, ['TOTAL GENERAL', '', number_format($litrosTotalGeneral, 2, '.', ''), number_format($montoTotalGeneral, 2, '.', ''), '', '']);
    fputcsv($out, []);

    fputcsv($out, ['Detalle de despachos']);
    fputcsv($out, ['Fecha/Hora', 'Tanque', 'Combustible', 'UID RFID', 'Titular', 'Litros', 'Monto (Bs)', 'Modo', 'Estado', 'Motivo']);
    foreach ($resultados as $r) {
        fputcsv($out, [
            date('d/m/Y H:i', strtotime($r['fecha_hora'])),
            $r['tanque_nombre'],
            $r['tipo_combustible'],
            $r['uid_rfid'],
            $r['titular'],
            number_format((float) $r['litros'], 2, '.', ''),
            number_format((float) ($r['monto'] ?? 0), 2, '.', ''),
            $r['modo'],
            $r['estado'],
            $r['motivo'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ------------------------------------------------------------
// Exportación PDF
// ------------------------------------------------------------
if ($formato === 'pdf') {
    require_once __DIR__ . '/includes/simple_pdf.php';

    $pdf = new SimplePDF();
    $pdf->addPage();
    $margen = 40;
    $y = 50;

    $pdf->text($margen, $y, 'ControlFuel - Reporte por tanques', 16, true);
    $y += 22;
    $pdf->text($margen, $y, "Periodo: $fecha_inicio  a  $fecha_fin", 10);
    $y += 30;

    // ----- Resumen por tanque -----
    $pdf->text($margen, $y, 'Resumen por tanque', 12, true);
    $y += 20;

    $colsResumen = [
        'Tanque'      => $margen,
        'Combustible' => $margen + 140,
        'Litros'      => $margen + 260,
        'Ingreso (Bs)'=> $margen + 340,
        'Despachos'   => $margen + 440,
    ];
    foreach ($colsResumen as $etq => $x) { $pdf->text($x, $y, $etq, 9, true); }
    $y += 4;
    $pdf->line($margen, $y, $pdf->getWidth() - $margen, $y);
    $y += 14;

    foreach ($resumenPorTanque as $r) {
        $pdf->text($colsResumen['Tanque'], $y, substr($r['nombre'], 0, 22), 9);
        $pdf->text($colsResumen['Combustible'], $y, $r['tipo_combustible'] === 'diesel' ? 'Diesel' : 'Gasolina', 9);
        $pdf->text($colsResumen['Litros'], $y, number_format($r['litros_totales'], 2), 9);
        $pdf->text($colsResumen['Ingreso (Bs)'], $y, number_format($r['monto_total'], 2), 9);
        $pdf->text($colsResumen['Despachos'], $y, $r['despachos_completados'] . ' ok / ' . $r['despachos_rechazados'] . ' rech.', 9);
        $y += 14;
    }
    $y += 6;
    $pdf->line($margen, $y, $pdf->getWidth() - $margen, $y);
    $y += 16;
    $pdf->text($margen, $y, 'TOTAL GENERAL:  ' . number_format($litrosTotalGeneral, 2) . ' L   —   Bs ' . number_format($montoTotalGeneral, 2), 11, true);
    $y += 30;

    // ----- Detalle de despachos -----
    $pdf->text($margen, $y, 'Detalle de despachos', 12, true);
    $y += 20;

    $cols = [
        'Fecha/Hora' => $margen,
        'Tanque'     => $margen + 90,
        'UID'        => $margen + 175,
        'Titular'    => $margen + 250,
        'Litros'     => $margen + 350,
        'Monto'      => $margen + 400,
        'Estado'     => $margen + 450,
    ];
    foreach ($cols as $etiqueta => $x) { $pdf->text($x, $y, $etiqueta, 8, true); }
    $y += 4;
    $pdf->line($margen, $y, $pdf->getWidth() - $margen, $y);
    $y += 14;

    $altoUtil = $pdf->getHeight() - 60;
    foreach ($resultados as $r) {
        if ($y > $altoUtil) { $pdf->addPage(); $y = 50; }
        $pdf->text($cols['Fecha/Hora'], $y, date('d/m/y H:i', strtotime($r['fecha_hora'])), 8);
        $pdf->text($cols['Tanque'], $y, substr($r['tanque_nombre'], 0, 14), 8);
        $pdf->text($cols['UID'], $y, substr($r['uid_rfid'], 0, 12), 8);
        $pdf->text($cols['Titular'], $y, substr($r['titular'], 0, 16), 8);
        $pdf->text($cols['Litros'], $y, number_format($r['litros'], 2), 8);
        $pdf->text($cols['Monto'], $y, number_format($r['monto'] ?? 0, 2), 8);
        $pdf->text($cols['Estado'], $y, $r['estado'], 8);
        $y += 14;
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="reporte_' . $fecha_inicio . '_' . $fecha_fin . '.pdf"');
    echo $pdf->output();
    exit;
}

// ------------------------------------------------------------
// Vista normal
// ------------------------------------------------------------
require_once __DIR__ . '/includes/header.php';

$queryParamsBase = ['fecha_inicio' => $fecha_inicio, 'fecha_fin' => $fecha_fin, 'enviado' => 1];
$queryParamsConTanques = $queryParamsBase;
foreach ($tanquesSeleccionados as $tid) { $queryParamsConTanques['tanques'][] = $tid; }
$queryParams = http_build_query($queryParamsConTanques);

$etiquetasChart = [];
$valoresChart = [];
$coloresChart = [];
foreach ($resumenPorTanque as $r) {
    $etiquetasChart[] = $r['nombre'];
    $valoresChart[]   = (float) $r['litros_totales'];
    $coloresChart[]   = $r['tipo_combustible'] === 'diesel' ? '#f59e0b' : '#16a34a';
}
?>

<div class="page-header">
    <div>
        <h1><?= icono('reportes') ?> Reportes</h1>
        <p class="page-subtitulo">Reporte completo por tanque: ingresos, consumo y detalle de despachos</p>
    </div>
</div>

<div class="panel panel-form">
    <form method="GET" class="form-inline form-reportes">
        <input type="hidden" name="enviado" value="1">
        <div>
            <label for="fecha_inicio">Desde</label>
            <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?= h($fecha_inicio) ?>">
        </div>
        <div>
            <label for="fecha_fin">Hasta</label>
            <input type="date" id="fecha_fin" name="fecha_fin" value="<?= h($fecha_fin) ?>">
        </div>
        <div class="filtro-tanques">
            <label>Tanques a incluir</label>
            <div class="checks-tanques">
                <?php if (empty($todosLosTanques)): ?>
                    <span class="page-subtitulo">Sin tanques registrados</span>
                <?php endif; ?>
                <?php foreach ($todosLosTanques as $t): ?>
                    <label class="check-tanque">
                        <input type="checkbox" name="tanques[]" value="<?= $t['id'] ?>"
                            <?= in_array((int) $t['id'], $tanquesSeleccionados, true) ? 'checked' : '' ?>>
                        <?= h($t['nombre']) ?>
                        <small>(<?= $t['tipo_combustible'] === 'diesel' ? 'Diésel' : 'Gasolina' ?>)</small>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <button type="submit" class="btn-primary"><?= icono('lista') ?> Filtrar</button>
    </form>
    <div class="botones-exportar">
        <a class="btn-toggle" href="reportes.php?<?= $queryParams ?>&formato=csv"><?= icono('descarga') ?> Exportar CSV</a>
        <a class="btn-toggle" href="reportes.php?<?= $queryParams ?>&formato=pdf"><?= icono('descarga') ?> Exportar PDF</a>
    </div>
</div>

<?php if (!$hayTanquesSeleccionados): ?>
    <div class="alerta-error"><?= icono('alerta') ?> Selecciona al menos un tanque para ver el reporte.</div>
<?php else: ?>

<!-- ---------- Total general ---------- -->
<div class="cards-row cards-row-resumen">
    <div class="card">
        <div class="card-top"><span class="card-label">Litros totales (seleccionados)</span><span class="card-icono"><?= icono('tanque') ?></span></div>
        <span class="card-value"><?= number_format($litrosTotalGeneral, 0) ?> L</span>
    </div>
    <div class="card card-dinero">
        <div class="card-top"><span class="card-label">Ingreso total (todos los tanques)</span><span class="card-icono"><?= icono('dinero') ?></span></div>
        <span class="card-value"><?= fmtDinero($montoTotalGeneral) ?></span>
    </div>
</div>

<!-- ---------- Resumen por tanque ---------- -->
<div class="panel panel-tabla">
    <h3><?= icono('cilindro') ?> Ingresos por tanque</h3>
    <table class="tabla-despachos">
        <thead>
            <tr>
                <th>Tanque</th><th>Combustible</th><th>Litros</th>
                <th>Ingreso</th><th>Despachos OK</th><th>Rechazados</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($resumenPorTanque)): ?>
                <tr><td colspan="6" class="sin-datos">Sin tanques seleccionados</td></tr>
            <?php endif; ?>
            <?php foreach ($resumenPorTanque as $r): ?>
                <tr>
                    <td><strong><?= h($r['nombre']) ?></strong></td>
                    <td><?= $r['tipo_combustible'] === 'diesel' ? 'Diésel' : 'Gasolina' ?></td>
                    <td><?= fmtLitros((float) $r['litros_totales']) ?></td>
                    <td><?= fmtDinero((float) $r['monto_total']) ?></td>
                    <td><?= (int) $r['despachos_completados'] ?></td>
                    <td><?= (int) $r['despachos_rechazados'] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <?php if (!empty($resumenPorTanque)): ?>
        <tfoot>
            <tr class="fila-total">
                <td colspan="2">TOTAL GENERAL</td>
                <td><?= fmtLitros($litrosTotalGeneral) ?></td>
                <td><?= fmtDinero($montoTotalGeneral) ?></td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<!-- ---------- Gráfica de consumo por tanque ---------- -->
<div class="panel panel-chart">
    <h3><?= icono('grafico') ?> Consumo por tanque (litros en el periodo)</h3>
    <canvas id="chartTanques"></canvas>
</div>

<!-- ---------- Detalle de despachos ---------- -->
<div class="panel panel-tabla">
    <h3><?= icono('lista') ?> Detalle de despachos</h3>
    <table class="tabla-despachos">
        <thead>
            <tr>
                <th>Fecha/Hora</th><th>Tanque</th><th>RFID</th><th>Titular</th>
                <th>Litros</th><th>Monto</th><th>Modo</th><th>Estado</th><th>Motivo</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($resultados)): ?>
                <tr><td colspan="9" class="sin-datos">Sin despachos en este periodo</td></tr>
            <?php endif; ?>
            <?php foreach ($resultados as $r): ?>
                <tr>
                    <td><?= fmtFechaHora($r['fecha_hora']) ?></td>
                    <td><?= h($r['tanque_nombre']) ?></td>
                    <td><code><?= h($r['uid_rfid']) ?></code></td>
                    <td><?= h($r['titular']) ?></td>
                    <td><?= fmtLitros((float) $r['litros']) ?></td>
                    <td><?= fmtDinero((float) ($r['monto'] ?? 0)) ?></td>
                    <td><?= $r['modo'] === 'crisis' ? 'Crisis' : 'Normal' ?></td>
                    <td><?= badgeEstado($r['estado'] === 'completado', 'Completado', 'Rechazado') ?></td>
                    <td><?= $r['motivo'] ? h($r['motivo']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('chartTanques'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($etiquetasChart) ?>,
        datasets: [{
            label: 'Litros consumidos',
            data: <?= json_encode($valoresChart) ?>,
            backgroundColor: <?= json_encode($coloresChart) ?>,
            borderRadius: 6,
            maxBarThickness: 46
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: '#eef2f7' } },
            x: { grid: { display: false } }
        }
    }
});
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
