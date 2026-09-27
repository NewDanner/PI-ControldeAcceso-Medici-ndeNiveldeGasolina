<?php
// ============================================================
// Conexión a la base de datos (PDO)
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'controlfuel');
define('DB_USER', 'root');
define('DB_PASS', ''); // XAMPP por defecto: sin contraseña

function getConnection(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die('Error de conexión a la base de datos: ' . $e->getMessage());
        }
    }

    return $pdo;
}
