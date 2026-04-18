<?php
/**
 * Router para el servidor de desarrollo built-in de PHP.
 * Úsalo con: php -S 127.0.0.1:9000 router.php
 *
 * Replica las reglas del .htaccess para que las URLs amigables
 * funcionen igual que en producción con Apache.
 */

$uri = $_SERVER['REQUEST_URI'];

// Quitar query string para evaluar el path
$path = parse_url($uri, PHP_URL_PATH);

// /articulo/ID-slug → article.php?id=ID
if (preg_match('#^/articulo/(\d+)#', $path, $m)) {
    $_GET['id'] = (int)$m[1];
    require __DIR__ . '/article.php';
    return;
}

// Servir archivos estáticos que existen (CSS, JS, imágenes, fuentes, etc.)
$file = __DIR__ . $path;
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // El servidor built-in sirve el archivo directamente
}

// Cualquier otra ruta → index.php
require __DIR__ . '/index.php';
