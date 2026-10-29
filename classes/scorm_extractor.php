<?php
/**
 * SCORM Extractor — texto de un paquete SCORM ya descomprimido por Moodle
 *
 * Moodle guarda el paquete en mod_scorm/content (itemid 0), contexto del módulo.
 * Esta clase recibe esos ficheros (stored_file o cualquier objeto con la misma
 * interfaz de lectura) y devuelve el texto en el ORDEN del manifiesto. Solo
 * corre desde las tareas de cron (vía content_extractor), nunca en una petición
 * de chat.
 *
 * Tres vías, por este orden (la primera que da texto aprovechable gana):
 *   rise       Articulate Rise: JSON en base64 dentro del HTML (deserialize("…")).
 *   storyline  Articulate Storyline: globalProvideData('slide', '{…}') en html5/data/js.
 *   html       el texto de los HTML del manifiesto.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

class scorm_extractor {

    /** Máx. ficheros de texto (html/js) que se leen de un paquete. */
    const MAX_FILES = 60;

    /** Máx. bytes que se leen de UN fichero (un index.html de Rise lleva el curso entero en base64). */
    const MAX_FILE_BYTES = 8 * 1024 * 1024;

    /** Máx. bytes que se leen en TOTAL de un paquete. */
    const MAX_PACKAGE_BYTES = 24 * 1024 * 1024;

    /** Máx. caracteres de texto que se devuelven (Épica recorta a ~100.000 igualmente). */
    const MAX_CHARS = 80000;

    /** Presupuesto de tiempo por paquete, en segundos. */
    const TIME_BUDGET_S = 30;

    /** Máx. scripts (js/json) que se leen, además de las páginas, para buscar el host de un paquete remoto. */
    const REMOTE_SCAN_FILES = 20;

    /** Máx. bytes que se leen de cada uno de esos scripts. */
    const REMOTE_SCAN_BYTES = 256 * 1024;

    /** Hosts que NO indican contenido remoto: espacios de nombres del estándar, CDN de librerías, fuentes y vídeo. */
    const REMOTE_IGNORE_HOSTS = [
        'w3.org', 'imsglobal.org', 'adlnet.org', 'adlnet.gov', 'ieee.org', 'purl.org', 'apache.org',
        'schemas.microsoft.com', 'microsoft.com', 'googleapis.com', 'gstatic.com', 'google.com',
        'googletagmanager.com', 'google-analytics.com', 'cloudflare.com', 'jsdelivr.net', 'unpkg.com',
        'jquery.com', 'bootstrapcdn.com', 'fontawesome.com', 'youtube.com', 'youtube-nocookie.com',
        'youtu.be', 'ytimg.com', 'vimeo.com', 'example.com',
    ];

    /** Claves de JSON que nunca son texto para una persona (Rise). */
    const JSON_DENY_KEYS = [
        'id', 'type', 'url', 'src', 'href', 'key', 'family', 'variant', 'style', 'color', 'backgroundcolor',
        'align', 'alignment', 'size', 'width', 'height', 'theme', 'mimetype', 'media', 'thumbnail', 'icon',
        'class', 'classname', 'layout', 'font', 'fonts', 'path', 'filename', 'extension', 'lang', 'locale',
        'version', 'uuid', 'guid', 'scormid', 'objectid', 'lessonid', 'courseid', 'format', 'ratio', 'position',
    ];

    /** @var content_extractor */
    private content_extractor $ce;

    /** @var float */
    private float $deadline = 0.0;

    /** @var int */
    private int $bytesread = 0;

    /** @var string[] anotaciones (truncados, límites, motivos) para el log */
    private array $notes = [];

    public function __construct(content_extractor $ce) {
        $this->ce = $ce;
    }

    /**
     * @param array $files stored_file[] (cualquier orden); se ignoran directorios y binarios.
     * @return array ['text'=>string, 'method'=>'rise'|'storyline'|'html'|'', 'notes'=>string[], 'reason'=>string, 'host'=>string]
     *               reason: '' si hay texto; 'no_files' (paquete vacío/externo), 'remote' (el contenido
     *               se carga desde otro servidor; 'host' = ese host validado, '' si no se identifica) o 'no_text'.
     */
    public function extract(array $files): array {
        $this->deadline = microtime(true) + self::TIME_BUDGET_S;
        $this->bytesread = 0;
        $this->notes = [];

        // Solo los ficheros que pueden llevar texto; vídeos, imágenes y fuentes ni se indexan.
        $byPath = [];
        $lower = [];
        foreach ($files as $file) {
            $name = $file->get_filename();
            if ($name === '.' || $name === '') {
                continue;
            }
            if (!preg_match('/\.(html?|xhtml|xml|js|json)$/i', $name)) {
                continue;
            }
            $rel = ltrim($file->get_filepath(), '/') . $name;
            $byPath[$rel] = $file;
            $lower[mb_strtolower($rel)] = $rel;
        }
        if (empty($byPath)) {
            return $this->result('', '', 'no_files');        }

        $manifestpath = null;
        foreach ($byPath as $rel => $file) {
            if (mb_strtolower(basename($rel)) === 'imsmanifest.xml'
                    && ($manifestpath === null || strlen($rel) < strlen($manifestpath))) {
                $manifestpath = $rel;
            }
        }

        $ordered = [];
        $xml = '';
        if ($manifestpath !== null) {
            $xml = $this->read($byPath[$manifestpath], 2 * 1024 * 1024);
            $ordered = $this->launch_order($xml, $byPath, $lower);
        }
        if (empty($ordered)) {
            $this->notes[] = 'sin manifiesto utilizable: todos los HTML por orden alfabético';
            foreach (array_keys($byPath) as $rel) {
                if (preg_match('/\.(html?|xhtml)$/i', $rel)) {
                    $ordered[] = $rel;
                }
            }
            sort($ordered, SORT_STRING);
        }
        if (count($ordered) > self::MAX_FILES) {
            $this->notes[] = 'truncado: ' . count($ordered) . ' páginas, se leen ' . self::MAX_FILES;
            $ordered = array_slice($ordered, 0, self::MAX_FILES);
        }

        // Lectura de las páginas (una vez): sirve a Rise y a la vía HTML.
        $pages = [];
        foreach ($ordered as $rel) {
            if ($this->out_of_budget()) {
                break;
            }
            $pages[$rel] = $this->read($byPath[$rel]);
        }

        // 1. Rise
        $rise = [];
        foreach ($pages as $html) {
            foreach ($this->rise_texts($html) as $line) {
                $rise[] = $line;
            }
        }
        $text = $this->join_capped($rise);
        if ($this->ce->is_extracted_text_useful($text)) {
            return $this->result($text, 'rise');
        }

        // 2. Storyline
        $text = $this->join_capped($this->storyline_texts($byPath, $lower));
        if ($this->ce->is_extracted_text_useful($text)) {
            return $this->result($text, 'storyline');
        }

        // 3. HTML
        $parts = [];
        foreach ($pages as $html) {
            $page = $this->ce->html_to_text($this->strip_noise($this->to_utf8($html)));
            if ($this->ce->is_extracted_text_useful($page)) {
                $parts[] = $page;
            }
        }
        $text = $this->join_capped($parts);
        if ($this->ce->is_extracted_text_useful($text)) {
            return $this->result($text, 'html');
        }

        // Sin texto: ¿el contenido vive fuera de Moodle (paquete «remoto», p. ej. scormnext)?
        $host = $this->remote_host($xml, $pages, $byPath);
        if ($host !== '') {
            $this->notes[] = 'paquete remoto: contenido alojado en ' . $host;
            return $this->result('', '', 'remote', $host);
        }
        if ($this->is_wrapper_only($ordered)) {
            $this->notes[] = 'paquete remoto: solo wrapper/redirect, sin host identificable';
            return $this->result('', '', 'remote');
        }

        return $this->result('', '', 'no_text');
    }

    private function result(string $text, string $method, string $reason = '', string $host = ''): array {
        return ['text' => $text, 'method' => $method, 'notes' => $this->notes, 'reason' => $reason, 'host' => $host];
    }

    // ----------------------------------------------------------------
    // Paquetes remotos (el contenido se carga desde otro servidor)
    // ----------------------------------------------------------------

    /**
     * Host externo al que apuntan el manifiesto, las páginas de lanzamiento y los scripts de un
     * paquete SIN texto: el que más veces aparece, ya validado (nombre de host en minúsculas, con
     * punto y TLD de letras; sin IP). '' si ninguno. Solo se mira el host: nunca la URL entera.
     */
    private function remote_host(string $manifest, array $pages, array $byPath): string {
        $sources = [$manifest];
        foreach ($pages as $html) {
            $sources[] = $html;
        }
        $extra = 0;
        foreach ($byPath as $rel => $file) {
            if (isset($pages[$rel]) || !preg_match('/\.(js|json)$/i', $rel)) {
                continue;
            }
            if ($extra >= self::REMOTE_SCAN_FILES || $this->out_of_budget()) {
                break;
            }
            $sources[] = $this->read($file, self::REMOTE_SCAN_BYTES);
            $extra++;
        }

        $counts = [];
        foreach ($sources as $src) {
            if ($src === '') {
                continue;
            }
            // «https://host», «https:\/\/host» (JSON/JS escapado) y http.
            if (!preg_match_all('#https?:(?:\\\\?/){2}([A-Za-z0-9](?:[A-Za-z0-9.-]{0,250}[A-Za-z0-9])?)#', $src, $m)) {
                continue;
            }
            foreach ($m[1] as $host) {
                $host = strtolower($host);
                if ($this->is_remote_host_candidate($host)) {
                    $counts[$host] = ($counts[$host] ?? 0) + 1;
                }
            }
        }
        if (empty($counts)) {
            return '';
        }
        arsort($counts);
        return (string)array_key_first($counts);
    }

    /** Nombre de host plausible y que no sea un espacio de nombres, un CDN de librerías ni un vídeo. */
    private function is_remote_host_candidate(string $host): bool {
        if (strlen($host) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $host)) {
            return false;
        }
        foreach (self::REMOTE_IGNORE_HOSTS as $ignored) {
            if ($host === $ignored || substr($host, -strlen($ignored) - 1) === '.' . $ignored) {
                return false;
            }
        }
        return true;
    }

    /** ¿Todas las páginas de lanzamiento son un wrapper/redirect/proxy (sin contenido propio)? */
    private function is_wrapper_only(array $ordered): bool {
        if (empty($ordered)) {
            return false;
        }
        foreach ($ordered as $rel) {
            if (!preg_match('/(wrapper|redirect|proxy)/i', basename($rel))) {
                return false;
            }
        }
        return true;
    }

    private function out_of_budget(): bool {
        if (microtime(true) > $this->deadline) {
            if (!in_array('truncado: tiempo agotado', $this->notes, true)) {
                $this->notes[] = 'truncado: tiempo agotado';
            }
            return true;
        }
        if ($this->bytesread >= self::MAX_PACKAGE_BYTES) {
            if (!in_array('truncado: bytes del paquete', $this->notes, true)) {
                $this->notes[] = 'truncado: bytes del paquete';
            }
            return true;
        }
        return false;
    }

    /** Lee como mucho $cap bytes de un fichero (nunca get_content() a pelo: puede pesar decenas de MB). */
    private function read($file, int $cap = self::MAX_FILE_BYTES): string {
        $cap = min($cap, max(0, self::MAX_PACKAGE_BYTES - $this->bytesread));
        if ($cap <= 0) {
            return '';
        }
        $fh = $file->get_content_file_handle();
        if (!$fh) {
            return '';
        }
        $data = (string)stream_get_contents($fh, $cap + 1);
        fclose($fh);
        if (strlen($data) > $cap) {
            $data = substr($data, 0, $cap);
            $this->notes[] = 'truncado: ' . $file->get_filename() . ' supera ' . $cap . ' bytes';
        }
        $this->bytesread += strlen($data);
        return $data;
    }

    /** Une líneas respetando MAX_CHARS (con mb_*), cortando en salto de línea. */
    private function join_capped(array $lines): string {
        $out = '';
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            $add = ($out === '' ? '' : "\n\n") . $line;
            if (mb_strlen($out) + mb_strlen($add) > self::MAX_CHARS) {
                $room = self::MAX_CHARS - mb_strlen($out);
                if ($room > 200) {
                    $out .= mb_substr($add, 0, $room);
                }
                $this->notes[] = 'truncado: texto a ' . self::MAX_CHARS . ' caracteres';
                break;
            }
            $out .= $add;
        }
        return trim($out);
    }

    // ----------------------------------------------------------------
    // Manifiesto
    // ----------------------------------------------------------------

    /**
     * Rutas de lanzamiento en el orden de la organización por defecto
     * (items → identifierref → resource href), con xml:base, sin query/fragmento.
     *
     * @return string[] rutas relativas presentes en $byPath
     */
    private function launch_order(string $xml, array $byPath, array $lower): array {
        if (trim($xml) === '') {
            return [];
        }
        $prev = libxml_use_internal_errors(true);
        // Sin LIBXML_NOENT ni carga de red: un manifiesto de un curso no puede leer ficheros del servidor.
        $manifest = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($manifest === false) {
            return [];
        }

        $rootbase = $this->xml_base($manifest);
        $resbase = $rootbase;
        $hrefs = [];
        if (isset($manifest->resources)) {
            $resbase = $this->join_base($rootbase, $this->xml_base($manifest->resources));
            foreach ($manifest->resources->resource as $resource) {
                $attrs = $resource->attributes();
                $identifier = (string)($attrs['identifier'] ?? '');
                $href = (string)($attrs['href'] ?? '');
                if ($identifier === '' || $href === '') {
                    continue;
                }
                $base = $this->join_base($resbase, $this->xml_base($resource));
                $path = $this->resolve_path($base, $href);
                if (isset($byPath[$path])) {
                    $hrefs[$identifier] = $path;
                } else if (isset($lower[mb_strtolower($path)])) {
                    $hrefs[$identifier] = $lower[mb_strtolower($path)];
                }
            }
        }
        if (empty($hrefs) || !isset($manifest->organizations)) {
            return [];
        }

        $orgs = [];
        $default = (string)($manifest->organizations->attributes()['default'] ?? '');
        foreach ($manifest->organizations->organization as $org) {
            if ($default !== '' && (string)($org->attributes()['identifier'] ?? '') === $default) {
                $orgs = [$org];
                break;
            }
            $orgs[] = $org;
        }
        // Con 'default' válido solo esa; si no casa ninguna, todas (el seen[] evita repetidos).
        $ordered = [];
        $seen = [];
        foreach ($orgs as $org) {
            $this->collect_items($org, $hrefs, $ordered, $seen);
        }
        return $ordered;
    }

    private function collect_items(\SimpleXMLElement $node, array $hrefs, array &$ordered, array &$seen): void {
        foreach ($node->item as $item) {
            $ref = (string)($item->attributes()['identifierref'] ?? '');
            if ($ref !== '' && isset($hrefs[$ref]) && !isset($seen[$hrefs[$ref]])) {
                $seen[$hrefs[$ref]] = true;
                $ordered[] = $hrefs[$ref];
            }
            $this->collect_items($item, $hrefs, $ordered, $seen);
        }
    }

    private function xml_base(\SimpleXMLElement $node): string {
        $a = $node->attributes('http://www.w3.org/XML/1998/namespace');
        return $a ? (string)($a['base'] ?? '') : '';
    }

    private function join_base(string $base, string $more): string {
        if ($more === '') {
            return $base;
        }
        if ($base === '' || preg_match('#^[a-z][a-z0-9+.-]*:|^/#i', $more)) {
            return $more;
        }
        return rtrim($base, '/') . '/' . $more;
    }

    /** base + href → ruta relativa normalizada (sin query/fragmento, sin ./ ni ../, urldecode). */
    private function resolve_path(string $base, string $href): string {
        $href = preg_replace('/[?#].*$/s', '', trim($href));
        $full = $this->join_base($base, str_replace('\\', '/', rawurldecode($href)));
        $out = [];
        foreach (explode('/', str_replace('\\', '/', $full)) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        return implode('/', $out);
    }

    // ----------------------------------------------------------------
    // HTML
    // ----------------------------------------------------------------

    /** Quita lo que strip_tags dejaría como texto: head/title, scripts, estilos, nav, svg, comentarios… */
    private function strip_noise(string $html): string {
        $html = preg_replace('/<!--.*?-->/s', ' ', $html);
        foreach (['head', 'script', 'style', 'noscript', 'nav', 'svg', 'template', 'select'] as $tag) {
            $html = preg_replace('/<' . $tag . '\b[^>]*>.*?<\/' . $tag . '\s*>/is', ' ', (string)$html);
        }
        return (string)$html;
    }

    /** HTML antiguo en Latin-1/windows-1252: a UTF-8 antes de nada (si ya es UTF-8, intacto). */
    private function to_utf8(string $html): string {
        if ($html === '' || mb_check_encoding($html, 'UTF-8')) {
            return $html;
        }
        $charset = 'Windows-1252';
        if (preg_match('/charset\s*=\s*["\']?\s*([A-Za-z0-9_-]+)/i', substr($html, 0, 2048), $m)) {
            $declared = $m[1];
            if (in_array(strtolower($declared), array_map('strtolower', mb_list_encodings()), true)) {
                $charset = $declared;
            }
        }
        $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
        return is_string($converted) ? $converted : $html;
    }

    // ----------------------------------------------------------------
    // Articulate Rise
    // ----------------------------------------------------------------

    /**
     * Textos de los bloques de Rise de UNA página: busca deserialize("…")/atob("…") con un
     * base64 largo, lo decodifica a JSON y recorre sus cadenas en orden de documento.
     *
     * @return string[]
     */
    private function rise_texts(string $html): array {
        $lines = [];
        foreach (['deserialize(', 'atob('] as $marker) {
            $offset = 0;
            while (($p = strpos($html, $marker, $offset)) !== false && !$this->out_of_budget()) {
                $offset = $p + strlen($marker);
                $i = $offset;
                while ($i < strlen($html) && ($html[$i] === ' ' || $html[$i] === "\n" || $html[$i] === "\r")) {
                    $i++;
                }
                if ($i >= strlen($html) || ($html[$i] !== '"' && $html[$i] !== "'")) {
                    continue;
                }
                $i++;
                // strspn: lineal, sin los límites de backtracking de PCRE sobre blobs de MB.
                $len = strspn($html, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=_-', $i);
                if ($len < 200) {
                    continue;
                }
                $json = $this->decode_rise_payload(substr($html, $i, $len));
                if ($json === null) {
                    continue;
                }
                $this->walk_json($json, '', $lines);
                $offset = $i + $len;
            }
        }
        return $lines;
    }

    private function decode_rise_payload(string $b64): ?array {
        $raw = base64_decode(strtr($b64, '-_', '+/'), true);
        if ($raw === false || $raw === '') {
            return null;
        }
        foreach ([$raw, rawurldecode($raw)] as $candidate) {
            $trim = ltrim($candidate);
            if ($trim === '' || ($trim[0] !== '{' && $trim[0] !== '[')) {
                continue;
            }
            $data = json_decode($candidate, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_array($data)) {
                return $data;
            }
        }
        return null;
    }

    /** Recorre un JSON en orden y acumula las cadenas que parecen texto para una persona. */
    private function walk_json($node, string $key, array &$lines): void {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $this->walk_json($v, is_string($k) ? $k : $key, $lines);
            }
            return;
        }
        if (!is_string($node) || in_array(mb_strtolower($key), self::JSON_DENY_KEYS, true)) {
            return;
        }
        $text = $this->ce->html_to_text($node);
        if (!$this->looks_like_text($text)) {
            return;
        }
        if (!empty($lines) && end($lines) === $text) {
            return;
        }
        $lines[] = $text;
    }

    /** Descarta ids, URLs, colores, CSS, base64 y valores sueltos de una palabra en minúscula. */
    private function looks_like_text(string $s): bool {
        $len = mb_strlen($s);
        if ($len < 2 || !preg_match('/\p{L}/u', $s)) {
            return false;
        }
        if (preg_match('~://|^[#.]?[A-Fa-f0-9-]{8,}$|[{};]\s*$|^data:|\bfunction\b|\.(png|jpe?g|gif|svg|mp4|mp3|woff2?|css|js)\b~i', $s)) {
            return false;
        }
        if (preg_match('/\p{L}+[\s,.;:]+\p{L}+/u', $s)) {
            return true; // al menos dos palabras
        }
        if (preg_match('/^\p{Lu}\p{Ll}{3,}[.:!?]?$/u', $s)) {
            return true; // una palabra con mayúscula inicial: «Introducción»
        }
        return false;
    }

    // ----------------------------------------------------------------
    // Articulate Storyline
    // ----------------------------------------------------------------

    /**
     * Textos de las diapositivas: ficheros de html5/data/js (o slides/) con
     * globalProvideData('slide', '{…}'). Orden: el de aparición de sus ids en data.js
     * (cualquier cadena de data.js igual al nombre de una diapositiva), el resto por nombre.
     *
     * @return string[]
     */
    private function storyline_texts(array $byPath, array $lower): array {
        $slides = [];
        $datajs = null;
        foreach ($byPath as $rel => $file) {
            if (!preg_match('#(^|/)(html5/data/js|slides)/([^/]+)\.js$#i', $rel, $m)) {
                continue;
            }
            $base = $m[3];
            if (in_array(strtolower($base), ['paths', 'frame', 'user', 'data'], true)) {
                if (strtolower($base) === 'data') {
                    $datajs = $rel;
                }
                continue;
            }
            $slides[$base] = $rel;
        }
        if (empty($slides)) {
            return [];
        }

        $order = [];
        if ($datajs !== null && !$this->out_of_budget()) {
            $data = $this->provided_json($this->read($byPath[$datajs], 2 * 1024 * 1024), 'data');
            if ($data !== null) {
                $this->collect_ids($data, $slides, $order);
            }
        }
        $rest = array_diff(array_keys($slides), $order);
        natsort($rest);
        $order = array_merge($order, array_values($rest));
        if (count($order) > self::MAX_FILES * 4) {
            $this->notes[] = 'truncado: ' . count($order) . ' diapositivas, se leen ' . (self::MAX_FILES * 4);
            $order = array_slice($order, 0, self::MAX_FILES * 4);
        }

        $lines = [];
        foreach ($order as $base) {
            if ($this->out_of_budget()) {
                break;
            }
            $slide = $this->provided_json($this->read($byPath[$slides[$base]], 2 * 1024 * 1024), 'slide');
            if ($slide === null) {
                continue;
            }
            if (!empty($slide['title']) && is_string($slide['title']) && $this->looks_like_text(trim($slide['title']))) {
                $lines[] = trim($slide['title']);
            }
            $this->collect_blocks($slide, $lines);
        }
        return $lines;
    }

    private function collect_ids($node, array $slides, array &$order): void {
        if (is_array($node)) {
            foreach ($node as $v) {
                $this->collect_ids($v, $slides, $order);
            }
        } else if (is_string($node) && isset($slides[$node]) && !in_array($node, $order, true)) {
            $order[] = $node;
        }
    }

    /** Cada "blocks" de Storyline es una lista de párrafos con "spans" de texto. */
    private function collect_blocks($node, array &$lines): void {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $k => $v) {
            if ($k === 'blocks' && is_array($v)) {
                foreach ($v as $block) {
                    $para = '';
                    foreach (($block['spans'] ?? []) as $span) {
                        if (isset($span['text']) && is_string($span['text'])) {
                            $para .= $span['text'];
                        }
                    }
                    $para = trim(preg_replace('/\s+/u', ' ', $para));
                    if ($this->looks_like_text_or_short($para) && (empty($lines) || end($lines) !== $para)) {
                        $lines[] = $para;
                    }
                }
                continue;
            }
            $this->collect_blocks($v, $lines);
        }
    }

    /** En una diapositiva el texto es de la persona: se admiten también frases cortas y una palabra. */
    private function looks_like_text_or_short(string $s): bool {
        return mb_strlen($s) >= 2 && preg_match('/\p{L}/u', $s) && !preg_match('#://#', $s);
    }

    /**
     * JSON de window.globalProvideData('<tipo>', '<literal JS con JSON>'): lee el literal a mano
     * (sin regex sobre contenido de tamaño arbitrario) y lo decodifica.
     */
    private function provided_json(string $js, string $kind): ?array {
        $p = strpos($js, 'globalProvideData(');
        if ($p === false) {
            return null;
        }
        $i = $p + strlen('globalProvideData(');
        $comma = strpos($js, ',', $i);
        if ($comma === false || trim(str_replace(['"', "'"], '', substr($js, $i, $comma - $i))) !== $kind) {
            return null;
        }
        $i = $comma + 1;
        while ($i < strlen($js) && ctype_space($js[$i])) {
            $i++;
        }
        $q = $js[$i] ?? '';
        if ($q !== '"' && $q !== "'") {
            return null;
        }
        $i++;
        $buf = '';
        $n = strlen($js);
        while ($i < $n) {
            $c = $js[$i];
            if ($c === '\\' && $i + 1 < $n) {
                $e = $js[$i + 1];
                // Se deshacen solo las comillas y la barra del literal JS; \n, \t y \uXXXX
                // los resuelve json_decode (dentro de una cadena JSON siguen siendo válidos).
                $buf .= in_array($e, ['\\', '"', "'", '/'], true) ? $e : ('\\' . $e);
                $i += 2;
                continue;
            }
            if ($c === $q) {
                break;
            }
            $buf .= $c;
            $i++;
        }
        $data = json_decode($buf, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        return is_array($data) ? $data : null;
    }
}
