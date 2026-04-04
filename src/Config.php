<?php

declare(strict_types=1);

/**
 * Lee variables de configuración desde el archivo .env del proyecto.
 */
class Config
{
    private static array $cache = [];
    private static bool $loaded = false;

    /**
     * Devuelve el valor de una variable de entorno.
     * Primero busca en el .env del proyecto, luego en las variables del sistema.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        if (!self::$loaded) {
            self::load();
        }

        return self::$cache[$key] ?? $default;
    }

    private static function load(): void
    {
        self::$loaded = true;

        $envFile = dirname(__DIR__) . '/.env';
        if (!file_exists($envFile)) {
            return;
        }

        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2)) + [1 => ''];
            if ($key !== '') {
                self::$cache[$key] = $value;
            }
        }
    }
}
