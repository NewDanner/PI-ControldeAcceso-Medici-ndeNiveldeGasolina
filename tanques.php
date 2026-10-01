<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['administrador']);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = getConnection();
$error = '';
$exito = '';

// ------------------------------------------------------------
// Registrar nuevo tanque
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear') {
    $nombre       = trim($_POST['nombre'] ?? '');
    $tipo         = ($_POST['tipo_combustible'] ?? '') === 'diesel' ? 'diesel' : 'gasolina';
    $altura       = (float) ($_POST['altura_cm'] ?? 0);
    $diametro     = (float) ($_POST['diametro_cm'] ?? 0);
    $radio        = (float) ($_POST['radio_cm'] ?? 0);
    $offsetSensor = (float) ($_POST['offset_sensor_cm'] ?? 0);
    $precio       = (float) ($_POST['precio_litro'] ?? 0);
    $nivelCritico = (float) ($_POST['nivel_critico_pct'] ?? 10);

    if ($nombre === '' || $altura <= 0 || $radio <= 0 || $precio <= 0) {
        $error = 'Completa nombre, altura, radio y precio por litro (mayores a 0).';
    } else {
        // Volumen de un cilindro vertical: V = π · r² · altura (cm³ → litros)
        $capacidadLitros = (M_PI * $radio ** 2 * $altura) / 1000;

        $stmt = $pdo->prepare(
            'INSERT INTO tanques
                (nombre, tipo_combustible, altura_cm, diametro_cm, radio_cm, offset_sensor_cm,
                 capacidad_litros, precio_litro, nivel_critico_pct)
             VALUES
                (:nombre, :tipo, :altura, :diametro, :radio, :offset_sensor,
                 :capacidad, :precio, :nivel_critico)'
        );
        $stmt->execute([
            'nombre'       => $nombre,
            'tipo'         => $tipo,
            'altura'       => $altura,
            'diametro'     => $diametro,
            'radio'        => $radio,
            'offset_sensor'=> $offsetSensor,
            'capacidad'    => $capacidadLitros,
            'precio'       => $precio,
            'nivel_critico'=> $nivelCritico,
        ]);

        $nuevoId = (int) $pdo->lastInsertId();

        // Si es el primer tanque registrado, se marca automáticamente
        // como predeterminado (el que usa el sensor/dispensador).
        $totalTanques = (int) $pdo->query('SELECT COUNT(*) AS t FROM tanques')->fetch()['t'];
        if ($totalTanques === 1) {
            $pdo->prepare('UPDATE tanques SET predeterminado = 1 WHERE id = :id')
                ->execute(['id' => $nuevoId]);
        }

        $exito = 'Tanque registrado — capacidad calculada: ' . number_format($capacidadLitros, 0) . ' L.';
    }
}

// ------------------------------------------------------------
// Marcar como predeterminado (el tanque activo del sensor/dispensador)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'predeterminar') {
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->exec('UPDATE tanques SET predeterminado = 0');
    $pdo->prepare('UPDATE tanques SET predeterminado = 1 WHERE id = :id')->execute(['id' => $id]);
    header('Location: tanques.php');
    exit;
}

// ------------------------------------------------------------
// Activar / desactivar tanque
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'toggle') {
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->prepare('UPDATE tanques SET activo = NOT activo WHERE id = :id')->execute(['id' => $id]);
    header('Location: tanques.php');
    exit;
}

$tanques = $pdo->query(
    'SELECT * FROM tanques ORDER BY predeterminado DESC, fecha_registro ASC'
)->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1><?= icono('cilindro') ?> Tanques de almacenamiento</h1>
        <p class="page-subtitulo"><?= count($tanques) ?> tanques registrados</p>
    </div>
</div>

<div class="panel panel-form">
    <h3><?= icono('mas') ?> Registrar nuevo tanque</h3>

    <?php if ($error): ?><div class="alerta-error"><?= icono('alerta') ?> <?= h($error) ?></div><?php endif; ?>
    <?php if ($exito): ?><div class="alerta-exito">✓ <?= h($exito) ?></div><?php endif; ?>

    <form method="POST" class="form-inline">
        <input type="hidden" name="accion" value="crear">
        <div>
            <label for="nombre">Nombre / identificador</label>
            <input type="text" id="nombre" name="nombre" placeholder="Ej: Tanque 1" required>
        </div>
        <div>
            <label for="tipo_combustible">Combustible</label>
            <select id="tipo_combustible" name="tipo_combustible">
                <option value="gasolina">Gasolina</option>
                <option value="diesel">Diésel</option>
            </select>
        </div>
        <div>
            <label for="altura_cm">Altura (cm)</label>
            <input type="number" step="0.1" id="altura_cm" name="altura_cm" placeholder="250" required>
        </div>
        <div>
            <label for="diametro_cm">Diámetro (cm)</label>
            <input type="number" step="0.1" id="diametro_cm" name="diametro_cm" placeholder="180" required>
        </div>
        <div>
            <label for="radio_cm">Radio (cm)</label>
            <input type="number" step="0.1" id="radio_cm" name="radio_cm" placeholder="90" required>
        </div>
        <div>
            <label for="offset_sensor_cm">Offset del sensor (cm)</label>
            <input type="number" step="0.1" id="offset_sensor_cm" name="offset_sensor_cm" placeholder="10" value="0">
        </div>
        <div>
            <label for="precio_litro">Precio por litro (Bs)</label>
            <input type="number" step="0.001" id="precio_litro" name="precio_litro" placeholder="3.72" required>
        </div>
        <div>
            <label for="nivel_critico_pct">Nivel crítico (%)</label>
            <input type="number" step="0.1" id="nivel_critico_pct" name="nivel_critico_pct" value="10" required>
        </div>
        <button type="submit" class="btn-primary"><?= icono('mas') ?> Registrar</button>
    </form>
    <p class="nota-formula"><?= icono('grafico') ?> La capacidad se calcula como cilindro vertical: <code>π · radio² · altura</code>. El "offset del sensor" es la distancia entre el sensor ultrasónico y la línea de tanque lleno — mide esa distancia física al instalarlo.</p>
</div>

<div class="panel panel-tabla">
    <h3><?= icono('lista') ?> Tanques registrados</h3>
    <table class="tabla-despachos">
        <thead>
            <tr>
                <th></th>
                <th>Nombre</th>
                <th>Combustible</th>
                <th>Altura</th>
                <th>Diámetro</th>
                <th>Radio</th>
                <th>Capacidad</th>
                <th>Precio/L</th>
                <th>Nivel crítico</th>
                <th>Estado</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tanques)): ?>
                <tr><td colspan="11" class="sin-datos">Sin tanques registrados todavía</td></tr>
            <?php endif; ?>
            <?php foreach ($tanques as $t): ?>
                <tr>
                    <td>
                        <?php if ($t['predeterminado']): ?>
                            <span class="marca-predeterminado" title="Tanque activo del sensor/dispensador"><?= icono('estrella') ?></span>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= h($t['nombre']) ?></strong></td>
                    <td><?= $t['tipo_combustible'] === 'diesel' ? 'Diésel' : 'Gasolina' ?></td>
                    <td><?= number_format($t['altura_cm'], 1) ?> cm</td>
                    <td><?= number_format($t['diametro_cm'], 1) ?> cm</td>
                    <td><?= number_format($t['radio_cm'], 1) ?> cm</td>
                    <td><?= number_format($t['capacidad_litros'], 0) ?> L</td>
                    <td><?= fmtDinero((float) $t['precio_litro']) ?></td>
                    <td><?= number_format($t['nivel_critico_pct'], 1) ?>%</td>
                    <td><?= badgeEstado((bool) $t['activo'], 'Activo', 'Inactivo') ?></td>
                    <td>
                        <?php if (!$t['predeterminado']): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="accion" value="predeterminar">
                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn-link">Usar este</button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="accion" value="toggle">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button type="submit" class="btn-link"><?= $t['activo'] ? 'Desactivar' : 'Activar' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p class="page-subtitulo" style="margin-top:.75rem;">
        <?= icono('estrella') ?> = tanque predeterminado: al que están conectados el sensor ultrasónico y el dispensador en este momento.
    </p>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
