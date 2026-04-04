<?php
/**
 * Script de actualización de noticias que se ejecuta en background.
 * Se invoca desde api/news.php de forma asíncrona para no bloquear al usuario.
 *
 * Usa un archivo de lock para evitar ejecuciones simultáneas.
 */
$lockFile = __DIR__ . '/../data/aggregator.lock';

// Si ya hay una actualización en curso, salir
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 60) {
    exit;
}

// Crear lock
file_put_contents($lockFile, (string)time());

try {
    require_once __DIR__ . '/../src/Database.php';
    require_once __DIR__ . '/../src/Aggregator.php';

    $db  = new Database();
    $agg = new Aggregator($db);
    $agg->fetchAll();
} finally {
    // Siempre liberar el lock
    @unlink($lockFile);
}
