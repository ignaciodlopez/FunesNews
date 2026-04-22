<?php
declare(strict_types=1);

/**
 * ImageScraper - Extracto especializado de imágenes con mejores prácticas
 * 
 * Problemas encontrados en versión anterior:
 * 1. Búsquedas duplicadas de contenido principal para Estacionline
 * 2. No maneja lazy loading ni imágenes modernas (srcset, data-src)
 * 3. Falta manejo de estructuras específicas por sitio
 * 4. No valida tamaño de imágenes antes de descargar
 * 5. Scores débiles para distinguir entre placeholders y fotos reales
 * 6. No detecta imágenes en estructuras JSON-LD
 * 7. Penalización insuficiente para miniaturas relacionadas
 */
class ImageScraper
{
    private const USER_AGENT = 
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private const SCAN_WINDOW = [
        'estacionline.com'  => 150000,
        'default'           => 70000,
    ];

    private const CONTENT_SELECTORS = [
        'estacionline.com' => [
            // Estructura específica: div.single-content or div.nota
            ['div', 'single-content'],
            ['div', 'nota'],
            ['article', 'post'],
            ['div', 'entry-content'],
        ],
        'default' => [
            ['article', null],
            ['main', null],
            ['div', 'content'],
            ['div', 'article-content'],
        ],
    ];

    private const RELATED_ARTICLE_PATTERNS = [
        'crp_thumb', 'crp_featured', 'related-post', 'related-item',
        'similar-post', 'newsletter', 'sidebar', 'widget', 'ad-', 'advertisement',
    ];

    private string $link;
    private string $host;
    private string $domain;
    private string $html;
    private array $keywords = [];
    private array $candidates = [];

    public function __construct(string $link) {
        $this->link = $link;
        $parsed = parse_url($link);
        $this->host = $parsed['host'] ?? '';
        $this->domain = $this->extractDomain($this->host);
    }

    /**
     * Extrae dominio base de un host (ej: estacionline.com de cdn.estacionline.com)
     */
    private function extractDomain(string $host): string {
        $parts = explode('.', strtolower($host));
        if (count($parts) >= 2) {
            return implode('.', array_slice($parts, -2));
        }
        return $host;
    }

    /**
     * Descarga el HTML de la página con timeouts y límites inteligentes
     */
    private function downloadHtml(bool $useRange = true): ?string {
        $scanWindow = self::SCAN_WINDOW[$this->domain] ?? self::SCAN_WINDOW['default'];
        
        $ctx = stream_context_create([
            'http' => [
                'timeout'         => 10,
                'user_agent'      => self::USER_AGENT,
                'header'          => $useRange ? "Range: bytes=0-$scanWindow\r\n" : "",
                'follow_location' => 1,
                'max_redirects'   => 5,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $html = @file_get_contents($this->link, false, $ctx);
        return ($html !== false) ? (string)$html : null;
    }

    /**
     * Extrae palabras clave del título para scoring semántico
     */
    private function extractKeywords(string $html): void {
        if (!preg_match('/<title>([^<]+)/i', $html, $m)) {
            return;
        }

        $title = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = strtolower($title);
        
        // Remover acentos y caracteres especiales
        $title = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'n'],
            $title
        );
        $title = preg_replace('/[^a-z0-9 ]/', ' ', $title);
        
        // Filtrar palabras cortas
        $this->keywords = array_filter(
            explode(' ', $title),
            fn($w) => strlen($w) > 3
        );
    }

    /**
     * Busca og:image en meta tags (imagen oficial del artículo)
     */
    private function findOpenGraphImage(string $html): void {
        $patterns = [
            '/<meta[^>]+property=["\']og:image(?:secure_url)?["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image(?:secure_url)?["\']/i',
            '/<meta[^>]+property=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $url) {
                    $url = trim($url);
                    if ($url !== '') {
                        // og:image tiene máxima prioridad (es imagen oficial del autor)
                        $this->candidates[$url] = max(
                            ($this->candidates[$url] ?? 0),
                            800  // Puntuación alta
                        );
                    }
                }
            }
        }
    }

    /**
     * Busca imágenes en esquema JSON-LD (muy confiable)
     */
    private function findJsonLdImage(string $html): void {
        if (!preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            return;
        }

        foreach ($matches[1] as $jsonBlock) {
            try {
                $data = json_decode($jsonBlock, true);
                if (!is_array($data)) continue;

                $imageUrl = $this->extractFromJsonLd($data);
                if ($imageUrl !== null) {
                    // JSON-LD es muy confiable (editor lo configuró)
                    $this->candidates[$imageUrl] = max(
                        ($this->candidates[$imageUrl] ?? 0),
                        750
                    );
                }
            } catch (\Throwable $e) {
                // JSON inválido, ignorar
            }
        }
    }

    /**
     * Extrae recursivamente imagen de estructura JSON-LD
     */
    private function extractFromJsonLd(array $data): ?string {
        // Buscar propiedades comunes
        if (!empty($data['image'])) {
            if (is_string($data['image'])) {
                return $data['image'];
            }
            if (is_array($data['image'])) {
                if (!empty($data['image']['url'])) {
                    return $data['image']['url'];
                }
                if (!empty($data['image'][0])) {
                    return is_string($data['image'][0]) ? $data['image'][0] : ($data['image'][0]['url'] ?? null);
                }
            }
        }

        // Buscar en propiedades de estructura anidada
        foreach ($data as $value) {
            if (is_array($value)) {
                $result = $this->extractFromJsonLd($value);
                if ($result !== null) return $result;
            }
        }

        return null;
    }

    /**
     * Busca imágenes en contenido principal (artículo)
     * Maneja lazy loading, srcset, data-src, etc.
     */
    private function findContentImages(string $html): void {
        $selectors = self::CONTENT_SELECTORS[$this->domain] 
                  ?? self::CONTENT_SELECTORS['default'];

        foreach ($selectors as [$tag, $class]) {
            if ($this->findImagesInElement($html, $tag, $class)) {
                break; // Encontrado contenido principal, no seguir buscando
            }
        }
    }

    /**
     * Busca imágenes dentro de un elemento específico
     */
    private function findImagesInElement(string $html, string $tag, ?string $class = null): bool {
        // Construir patrón de búsqueda
        if ($class !== null) {
            $pattern = '/<' . preg_quote($tag, '/') . '[^>]+class=["\'][^"\']*' 
                     . preg_quote($class, '/') 
                     . '[^"\']*["\'][^>]*>(.*?)<\/' . preg_quote($tag, '/') . '>/is';
        } else {
            $pattern = '/<' . preg_quote($tag, '/') . '[^>]*>(.*?)<\/' . preg_quote($tag, '/') . '>/is';
        }

        if (!preg_match($pattern, $html, $matches)) {
            return false;
        }

        $content = $matches[1];
        $found = false;

        // Buscar imágenes en este contenedor
        if (preg_match_all('/<img\b[^>]*>/i', $content, $imgMatches)) {
            foreach ($imgMatches[0] as $imgTag) {
                // Excluir si es thumbnail relacionado
                if ($this->isRelatedArticleImage($imgTag)) {
                    continue;
                }

                $url = $this->extractImageUrl($imgTag);
                if ($url !== null) {
                    // Imágenes en contenido principal tienen prioridad alta
                    $this->candidates[$url] = max(
                        ($this->candidates[$url] ?? 0),
                        400
                    );
                    $found = true;
                }
            }
        }

        return $found;
    }

    /**
     * Detecta si una imagen es de artículos relacionados (thumbnail)
     */
    private function isRelatedArticleImage(string $imgTag): bool {
        foreach (self::RELATED_ARTICLE_PATTERNS as $pattern) {
            if (stripos($imgTag, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extrae URL de imagen desde un tag <img> con soporte para lazy loading
     */
    private function extractImageUrl(string $imgTag): ?string {
        $attributes = [
            'data-lazy-srcset', 'data-srcset', 'srcset',
            'data-lazy-src', 'data-src', 'data-original', 'src'
        ];

        foreach ($attributes as $attr) {
            if (!preg_match('/' . preg_quote($attr, '/') . '=["\']([^"\']+)["\']/i', $imgTag, $m)) {
                continue;
            }

            $raw = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($raw === '' || str_starts_with($raw, 'data:image/')) {
                continue;
            }

            // Si es srcset, extraer la mejor URL
            if (str_contains($attr, 'srcset')) {
                $raw = $this->pickBestSrcsetUrl($raw);
                if ($raw === null) continue;
            }

            return $raw;
        }

        return null;
    }

    /**
     * Elige la mejor URL de un srcset (preferir resolucion más alta)
     */
    private function pickBestSrcsetUrl(string $srcset): ?string {
        $bestUrl = null;
        $bestWidth = -1;

        foreach (explode(',', $srcset) as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate));
            $url = trim($parts[0] ?? '');
            if (!$url) continue;

            $width = 0;
            if (isset($parts[1]) && preg_match('/(\d+)w/', $parts[1], $m)) {
                $width = (int)$m[1];
            }

            if ($width > $bestWidth) {
                $bestUrl = $url;
                $bestWidth = $width;
            }
        }

        return $bestUrl;
    }

    /**
     * Normaliza y resuelve URLs relativas
     */
    private function resolveUrl(string $url): ?string {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $scheme = 'https'; // Asumir HTTPS
        
        if (str_starts_with($url, '//')) {
            return $scheme . ':' . $url;
        }
        
        if (str_starts_with($url, '/')) {
            return $scheme . '://' . $this->host . $url;
        }

        // URL relativa
        return $scheme . '://' . $this->host . '/' . ltrim($url, '/');
    }

    /**
     * Verifica si una imagen es usable (no placeholder, no stock, etc)
     */
    private function isUsableImage(string $url): bool {
        $url = strtolower($url);
        
        // Excluir imágenes de stock
        if (preg_match('/(picsum\.photos|images\.unsplash\.com|placeholder)/i', $url)) {
            return false;
        }

        // Excluir GIFs animados
        if (str_ends_with($url, '.gif')) {
            return false;
        }

        return true;
    }

    /**
     * Calcula puntuación final basada en nombre y palabras clave
     */
    private function scoreImage(string $url): int {
        $score = 0;
        $filename = strtolower(basename(parse_url($url, PHP_URL_PATH) ?? ''));

        // Normalizar acentos
        $filename = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'n'],
            $filename
        );

        // Contar coincidencias con palabras clave del título
        $matchCount = 0;
        foreach ($this->keywords as $word) {
            if (str_contains($filename, $word)) {
                $matchCount++;
            }
        }

        if ($matchCount >= 2) {
            $score += 150;
        } elseif ($matchCount >= 1) {
            $score += 80;
        }

        // Penalizar thumbnails
        if (preg_match('/-(?:75|100|150|200|300)x\d+\.(jpg|png|webp)/i', $filename)) {
            $score -= 100;
        }

        // Premiar imágenes grandes
        if (preg_match('/-(?:720|1080|1200|1600)x\d+\.(jpg|png|webp)/i', $filename)) {
            $score += 100;
        }

        // Penalizar slugs genéricos
        if (preg_match('/^(?:image|photo|pic|image\d+)\./i', $filename)) {
            $score -= 150;
        }

        return $score;
    }

    /**
     * Ejecuta el scraping completo y retorna la mejor imagen encontrada
     */
    public function scrape(): ?string {
        // Descargar HTML
        $this->html = $this->downloadHtml(true);
        if ($this->html === null) {
            return null;
        }

        // Extraer información del artículo
        $this->extractKeywords($this->html);

        // Buscar imágenes en orden de confiabilidad
        $this->findOpenGraphImage($this->html);           // Máxima confianza
        $this->findJsonLdImage($this->html);              // Alta confianza
        $this->findContentImages($this->html);            // Media confianza

        // Si no hay candidatos, descargar HTML completo
        if (empty($this->candidates)) {
            $fullHtml = $this->downloadHtml(false);
            if ($fullHtml && $fullHtml !== $this->html) {
                $this->html = $fullHtml;
                $this->findContentImages($this->html);
            }
        }

        if (empty($this->candidates)) {
            return null;
        }

        // Procesar y puntar candidatos
        $scored = [];
        foreach ($this->candidates as $rawUrl => $baseScore) {
            $url = $this->resolveUrl($rawUrl);
            if ($url === null || !$this->isUsableImage($url)) {
                continue;
            }

            $finalScore = $baseScore + $this->scoreImage($url);
            $scored[$url] = $finalScore;
        }

        if (empty($scored)) {
            return null;
        }

        // Retornar imagen con mayor puntuación
        arsort($scored);
        return key($scored);
    }
}
