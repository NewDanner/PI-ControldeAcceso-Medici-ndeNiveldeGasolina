<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['administrador']);
require_once __DIR__ . '/config/database.php';

$pdo = getConnection();

$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-d', strtotime('-7 day'));
$fecha_fin    = $_GET['fecha_fin']    ?? date('Y-m-d');
$formato      = $_GET['formato']      ?? '';

// Validación simple de fechas
if (!strtotime($fecha_inicio)) { $fecha_inicio = date('Y-m-d', strtotime('-7 day')); }
if (!strtotime($fecha_fin))    { $fecha_fin    = date('Y-m-d'); }

$stmt = $pdo->prepare(
    "SELECT d.fecha_hora, t.uid_rfid, t.titular, d.litros, d.modo, d.estado, d.motivo
     FROM despachos d
     JOIN tarjetas_rfid t ON t.id = d.tarjeta_id
     WHERE DATE(d.fecha_hora) BETWEEN :inicio AND :fin
     ORDER BY d.fecha_hora ASC"
);
$stmt->execute(['inicio' => $fecha_inicio, 'fin' => $fecha_fin]);
$resultados = $stmt->fetchAll();

// ------------------------------------------------------------
// Exportación CSV
// ------------------------------------------------------------
if ($formato === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_' . $fecha_inicio . '_' . $fecha_fin . '.csv"');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM para acentos en Excel
    fputcsv($out, ['Fecha/Hora', 'UID RFID', 'Titular', 'Litros', 'Modo', 'Estado', 'Motivo']);

    foreach ($resultados as $r) {
        fputcsv($out, [
            date('d/m/Y H:i', strtotime($r['fecha_hora'])),
            $r['uid_rfid'],
            $r['titular'],
            number_format($r['litros'], 2, '.', ''),
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

    $pdf->text($margen, $y, 'ControlFuel - Reporte de Despachos', 16, true);
    $y += 22;
    $pdf->text($margen, $y, "Periodo: $fecha_inicio  a  $fecha_fin", 10);
    $y += 25;

    // Encabezados de columna
    $cols = [
        'Fecha/Hora' => $margen,
        'UID'        => $margen + 100,
        'Titular'    => $margen + 175,
        'Litros'     => $margen + 300,
        'Modo'       => $margen + 350,
        'Estado'     => $margen + 410,
        'Motivo'     => $margen + 470,
    ];
    foreach ($cols as $etiqueta => $x) {
        $pdf->text($x, $y, $etiqueta, 9, true);
    }
    $y += 4;
    $pdf->line($margen, $y, $pdf->getWidth() - $margen, $y);
    $y += 14;

    $totalLitros = 0;
    $altoUtil = $pdf->getHeight() - 60;

    foreach ($resultados as $r) {
        if ($y > $altoUtil) {
            $pdf->addPage();
            $y = 50;
        }
        $pdf->text($cols['Fecha/Hora'], $y, date('d/m/y H:i', strtotime($r['fecha_hora'])), 8);
        $pdf->text($cols['UID'], $y, substr($r['uid_rfid'], 0, 12), 8);
        $pdf->text($cols['Titular'], $y, substr($r['titular'], 0, 20), 8);
        $pdf->text($cols['Litros'], $y, number_format($r['litros'], 2), 8);
        $pdf->text($cols['Modo'], $y, $r['modo'], 8);
        $pdf->text($cols['Estado'], $y, $r['estado'], 8);
        $pdf->text($cols['Motivo'], $y, substr($r['motivo'] ?? '-', 0, 18), 8);
        $y += 14;
        if ($r['estado'] === 'completado') {
            $totalLitros += (float) $r['litros'];
        }
    }

    $y += 10;
    $pdf->line($margen, $y, $pdf->getWidth() - $margen, $y);
    $y += 16;
    $pdf->text($margen, $y, 'Total litros despachados: ' . number_format($totalLitros, 2) . ' L', 10, true);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="reporte_' . $fecha_inicio . '_' . $fecha_fin . '.pdf"');
    echo $pdf->output();
    exit;
}

// ------------------------------------------------------------
// Vista normal (filtro + tabla previa)
// ------------------------------------------------------------
require_once __DIR__ . '/includes/header.php';
$queryParams = http_build_query(['fecha_inicio' => $fecha_inicio, 'fecha_fin' => $fecha_fin]);
?>

<h1>Reportes</h1>

<div class="panel panel-form">
    <form method="GET" class="form-inline">
        <div>
            <label for="fecha_inicio">Desde</label>
            <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>">
        </div>
        <div>
            <label for="fecha_fin">Hasta</label>
            <input type="date" id="fecha_fin" name="fecha_fin" value="<?= htmlspecialchars($fecha_fin) ?>">
        </div>
        <button type="submit" class="btn-primary">Filtrar</button>
    </form>
    <div class="botones-exportar">
        <a class="btn-toggle" href="reportes.php?<?= $queryParams ?>&formato=csv">Exportar CSV</a>
        <a class="btn-toggle" href="reportes.php?<?= $queryParams ?>&formato=pdf">Exportar PDF</a>
    </div>
</div>

<div class="panel panel-tabla">
    <h3>Despachos en el periodo (<?= count($resultados) ?>)</h3>
    <table class="tabla-despachos">
        <thead>
            <tr>
                <th>Fecha/Hora</th><th>RFID</th><th>Titular</th>
                <th>Litros</th><th>Modo</th><th>Estado</th><th>Motivo</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($resultados)): ?>
                <tr><td colspan="7" class="sin-datos">Sin despachos en este periodo</td></tr>
            <?php endif; ?>
            <?php foreach ($resultados as $r): ?>
                <tr>
                    <td><?= date('d/m/Y H:i', strtotime($r['fecha_hora'])) ?></td>
                    <td><code><?= htmlspecialchars($r['uid_rfid']) ?></code></td>
                    <td><?= htmlspecialchars($r['titular']) ?></td>
                    <td><?= number_format($r['litros'], 2) ?></td>
                    <td><?= $r['modo'] ?></td>
                    <td>
                        <span class="estado estado-<?= $r['estado'] ?>">
                            <?= $r['estado'] === 'completado' ? 'Completado' : 'Rechazado' ?>
                        </span>
                    </td>
                    <td><?= $r['motivo'] ? htmlspecialchars($r['motivo']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
