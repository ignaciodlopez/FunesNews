<?php
/**
 * Script de reparación manual de imágenes.
 * Escanea los últimos 14 días en busca de artículos sin imagen e intenta
 * recuperarlas usando la lógica mejorada del Aggregator.
 */

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Aggregator.php';

// Aumentar límites para ejecución manual
set_time_limit(0);
ini_set('memory_limit', '512M');

$db = new Database();

// Clase anónima o extendida para acceder al método privado de reparación con un límite mayor
class ManualRepairer extends Aggregator {
    private Database $localDb;
    
    public function __construct(Database $db) {
        parent::__construct($db);
        $this->localDb = $db;
    }

    public function runMassRepair(int $limit = 500): void {
        echo "Iniciando reparación masiva de imágenes (límite: $limit)...\n";
        
        // Obtenemos una lista más grande de artículos sin imagen
        $articles = $this->localDb->getRecentArticlesWithoutImage($limit);
        
        if (empty($articles)) {
            echo "No se encontraron artículos recientes sin imagen.\n";
            return;
        }

        echo "Se encontraron " . count($articles) . " artículos para procesar.\n";
        
        $repaired = 0;
        foreach ($articles as $index => $article) {
            $current = $index + 1;
            echo "[$current/" . count($articles) . "] Procesando: " . $article['link'] . "... ";
            
            // Usamos reflection para llamar al método privado de extracción de imagen
            $method = new ReflectionMethod('Aggregator', 'fetchOgImage');
            $method->setAccessible(true);
            $image = $method->invoke($this, $article['link']);

            if ($image && !str_contains($image, '.gif')) {
                $this->localDb->updateImageUrl((int)$article['id'], $image);
                echo "¡CORREGIDO! -> $image\n";
                $repaired++;
            } else {
                echo "Sin éxito.\n";
            }
            
            // Pequeña pausa para no saturar a los servidores origen
            usleep(200000); 
        }

        echo "\n--------------------------------------------------\n";
        echo "Reparación finalizada. Total corregidos: $repaired\n";
    }
}

$repairer = new ManualRepairer($db);
$limit = isset($argv[1]) ? (int)$argv[1] : 200;
$repairer->runMassRepair($limit);
