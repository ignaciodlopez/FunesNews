<?php
/**
 * Endpoint REST que devuelve noticias locales en formato JSON.
 * Lanza el aggregator en background para no bloquear la respuesta al usuario.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../src/Database.php';

try {
    $db = new Database();

    $lastUpdate    = $db->getLastUpdate();
    $minutesPassed = (time() - $lastUpdate) / 60;

    // Lanzar actualización en background cada 2 minutos.
    // Se marca el timestamp ANTES de lanzar para que requests simultáneos
    // no disparen múltiples procesos.
    if ($minutesPassed >= 2) {
        $db->setLastUpdate(time());
        $script = realpath(__DIR__ . '/../scripts/run_aggregator.php');
        if ($script) {
            // Windows: start /b lanza el proceso sin bloquear
            $php = PHP_BINARY;
            pclose(popen("cmd /c start /b \"\" \"{$php}\" \"{$script}\" > NUL 2>&1", 'r'));
        }
    }

    // Parámetros de filtrado por fuente y paginación
    $source = isset($_GET['source']) ? $_GET['source'] : null;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = 12;
    $offset = ($page - 1) * $limit;

    $news = $db->getNews($limit, $source, $offset);
    $sources = $db->getSources();

    // Construye la lista de fuentes asegurando que "Todas" sea la primera opción
    $allSources = ['Todas'];
    foreach ($sources as $s) {
        if ($s !== 'Todas') $allSources[] = $s;
    }

    echo json_encode([
        'status' => 'success',
        'last_update' => date('Y-m-d H:i:s', $db->getLastUpdate()),
        'sources' => $allSources,
        'page' => $page,
        'has_more' => count($news) === $limit,
        'data' => $news
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
