<?php
/**
 * Markdown Renderer
 *
 * Rendert .md-Dateien als schön formatiertes HTML.
 * Wird von Nginx aufgerufen, wenn eine .md-Datei angefragt wird.
 *
 * Benötigt:
 *   lib/Parsedown.php
 *   lib/ParsedownExtra.php  (optional, nur wenn parser=parsedown-extra)
 */

// ── Konfiguration laden ───────────────────────────────────────────────
$configFile = __DIR__ . '/config.php';
$config = file_exists($configFile) ? require $configFile : [];

// Defaults
$defaults = [
    'doc_root'             => __DIR__,
    'parser'               => 'parsedown-extra',
    'breaks'               => true,
    'markup'               => true,
    'safe_mode'            => false,
    'urls_linked'          => true,
    'theme'                => 'auto',
    'max_width'            => '860px',
    'font_family'          => 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
    'font_size'            => '17px',
    'line_height'          => '1.7',
    'custom_css'           => '',
    'highlight_code'       => true,
    'highlight_theme_light'=> 'github',
    'highlight_theme_dark' => 'github-dark',
    'toc'                  => false,
    'toc_min_headings'     => 3,
    'show_title'           => true,
    'show_meta'            => true,
    'show_source_link'     => false,
    'allowed_dirs'         => [],
    'blocked_files'        => [],
];

$cfg = array_merge($defaults, $config);

// Query-Parameter können Einstellungen überschreiben
$queryOverrides = ['theme', 'toc', 'highlight_code', 'highlight_theme_light', 'highlight_theme_dark', 'max_width', 'custom_css'];
foreach ($queryOverrides as $key) {
    if (isset($_GET[$key])) {
        $val = $_GET[$key];
        if (in_array($key, ['toc', 'highlight_code'])) {
            $cfg[$key] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
        } else {
            $cfg[$key] = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
        }
    }
}

// Theme-Name als CSS-Datei auflösen (alles ausser light/dark/auto)
$builtinThemes = ['light', 'dark', 'auto'];
if (!in_array($cfg['theme'], $builtinThemes, true)) {
    $themeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $cfg['theme']);
    $themePath = __DIR__ . '/themes/' . $themeName . '.css';
    if (file_exists($themePath)) {
        $cfg['custom_css'] = '/markdown/themes/' . $themeName . '.css';
    }
    $cfg['theme'] = 'auto';
}

// ── Markdown-Datei bestimmen ──────────────────────────────────────────
// Nginx übergibt den Dateipfad via fastcgi_param MARKDOWN_FILE
$mdFile = $_SERVER['MARKDOWN_FILE'] ?? '';

// Fallback: Query-Parameter ?file= (Pfad relativ zum Script-Verzeichnis)
if (empty($mdFile) && isset($_GET['file'])) {
    $requestedFile = $_GET['file'];
    // Sicherstellen, dass kein Directory Traversal möglich ist
    $requestedFile = str_replace(['..', "\0"], '', $requestedFile);
    $mdFile = $cfg['doc_root'] . '/' . ltrim($requestedFile, '/');
}

if (empty($mdFile)) {
    http_response_code(400);
    die('No markdown file specified.');
}

// Realpath auflösen und prüfen
$mdFileReal = realpath($mdFile);

if ($mdFileReal === false || !is_file($mdFileReal) || !is_readable($mdFileReal)) {
    http_response_code(404);
    die('Markdown file not found.');
}

// Sicherheitscheck: Nur .md-Dateien
if (strtolower(pathinfo($mdFileReal, PATHINFO_EXTENSION)) !== 'md') {
    http_response_code(403);
    die('Only .md files can be rendered.');
}

// Sicherheitscheck: Erlaubte Verzeichnisse
if (!empty($cfg['allowed_dirs'])) {
    $allowed = false;
    foreach ($cfg['allowed_dirs'] as $dir) {
        $dirReal = realpath($dir);
        if ($dirReal && str_starts_with($mdFileReal, $dirReal . '/')) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        http_response_code(403);
        die('Access to this file is not allowed.');
    }
}

// Sicherheitscheck: Blockierte Dateinamen
$basename = basename($mdFileReal);
if (in_array($basename, $cfg['blocked_files'], true)) {
    http_response_code(403);
    die('This file is blocked.');
}

// ── Markdown lesen und parsen ─────────────────────────────────────────
$markdown = file_get_contents($mdFileReal);
if ($markdown === false) {
    http_response_code(500);
    die('Could not read markdown file.');
}

// Parsedown laden
require_once __DIR__ . '/lib/Parsedown.php';

if ($cfg['parser'] === 'parsedown-extra') {
    $extraFile = __DIR__ . '/lib/ParsedownExtra.php';
    if (file_exists($extraFile)) {
        require_once $extraFile;
        $parser = new ParsedownExtra();
    } else {
        $parser = new Parsedown();
    }
} else {
    $parser = new Parsedown();
}

$parser->setBreaksEnabled($cfg['breaks']);
$parser->setMarkupEscaped($cfg['safe_mode']);
$parser->setUrlsLinked($cfg['urls_linked']);

if (!$cfg['safe_mode']) {
    $parser->setSafeMode(false);
} else {
    $parser->setSafeMode(true);
}

$htmlContent = $parser->text($markdown);

// ── Relative .md-Links umschreiben ────────────────────────────────────
$nginxMode = !empty($_SERVER['MARKDOWN_FILE']);

// Verzeichnis der aktuellen Datei relativ zum doc_root (für ?file= Modus)
$docRoot = rtrim($cfg['doc_root'], '/');
$currentDir = dirname($mdFileReal);
$relativeDir = '';
if (str_starts_with($currentDir, $docRoot)) {
    $relativeDir = ltrim(substr($currentDir, strlen($docRoot)), '/');
}

// Query-Parameter beibehalten (ohne 'file')
$preserveParams = $_GET;
unset($preserveParams['file']);
$extraQuery = http_build_query($preserveParams);

// Script-URL bestimmen (nur für ?file= Modus)
$scriptUrl = strtok($_SERVER['SCRIPT_NAME'] ?? '/markdown/render.php', '?');

$htmlContent = preg_replace_callback(
    '/<a\s([^>]*?)href="([^"]*?\.md(?:#[^"]*)?)"([^>]*?)>/i',
    function ($match) use ($nginxMode, $relativeDir, $extraQuery, $scriptUrl) {
        $before = $match[1];
        $href   = $match[2];
        $after  = $match[3];

        // Absolute URLs nicht umschreiben
        if (preg_match('#^https?://#', $href) || str_starts_with($href, '/')) {
            return $match[0];
        }

        // Anchor vom Pfad trennen
        $anchor = '';
        if (($pos = strpos($href, '#')) !== false) {
            $anchor = substr($href, $pos);
            $href = substr($href, 0, $pos);
        }

        if ($nginxMode) {
            // Nginx-Modus: Link bleibt relativ, Browser löst den Pfad auf
            // Nur Query-Parameter anhängen
            $newHref = $href . ($extraQuery ? '?' . $extraQuery : '') . $anchor;
        } else {
            // ?file= Modus: Pfad relativ zum doc_root zusammenbauen
            $filePath = $relativeDir ? $relativeDir . '/' . $href : $href;
            $params = ['file' => $filePath];
            $queryString = http_build_query($params) . ($extraQuery ? '&' . $extraQuery : '');
            $newHref = $scriptUrl . '?' . $queryString . $anchor;
        }

        return '<a ' . $before . 'href="' . htmlspecialchars($newHref) . '"' . $after . '>';
    },
    $htmlContent
);

// ── Titel bestimmen ───────────────────────────────────────────────────
$title = pathinfo($mdFileReal, PATHINFO_FILENAME);
// Erste H1 aus dem Markdown als Titel verwenden
if (preg_match('/^#\s+(.+)$/m', $markdown, $m)) {
    $title = strip_tags($m[1]);
}

// ── Table of Contents generieren ──────────────────────────────────────
$tocHtml = '';
if ($cfg['toc']) {
    $tocItems = [];
    // Headings aus dem gerenderten HTML extrahieren
    if (preg_match_all('/<h([2-4])[^>]*>(.*?)<\/h\1>/si', $htmlContent, $matches, PREG_SET_ORDER)) {
        if (count($matches) >= $cfg['toc_min_headings']) {
            foreach ($matches as $i => $match) {
                $level = (int)$match[1];
                $text = strip_tags($match[2]);
                $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($text));
                $slug = trim($slug, '-');
                $id = $slug ?: 'heading-' . $i;

                // ID zum Heading im Content hinzufügen
                $htmlContent = str_replace(
                    $match[0],
                    sprintf('<h%d id="%s">%s</h%d>', $level, htmlspecialchars($id), $match[2], $level),
                    $htmlContent
                );

                $indent = ($level - 2) * 1;
                $tocItems[] = sprintf(
                    '<li style="margin-left:%dem"><a href="#%s">%s</a></li>',
                    $indent,
                    htmlspecialchars($id),
                    htmlspecialchars($text)
                );
            }
            $tocHtml = '<nav class="toc"><details open><summary>Inhaltsverzeichnis</summary><ul>'
                     . implode("\n", $tocItems)
                     . '</ul></details></nav>';
        }
    }
}

// ── Meta-Informationen ────────────────────────────────────────────────
$metaHtml = '';
if ($cfg['show_meta']) {
    $mtime = filemtime($mdFileReal);
    $dateStr = date('d.m.Y H:i', $mtime);
    $relativePath = str_replace(($_SERVER['DOCUMENT_ROOT'] ?? ''), '', $mdFileReal);
    $metaHtml = sprintf(
        '<div class="meta">Zuletzt geändert: %s &middot; %s</div>',
        htmlspecialchars($dateStr),
        htmlspecialchars($relativePath)
    );
}

// ── Source Link ───────────────────────────────────────────────────────
$sourceLinkHtml = '';
if ($cfg['show_source_link']) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $rawUrl = strtok($requestUri, '?');
    $sourceLinkHtml = sprintf(
        '<a class="source-link" href="%s?raw=1">Quelldatei anzeigen</a>',
        htmlspecialchars($rawUrl)
    );
}

// Raw-Modus: Markdown als Plaintext ausliefern
if (isset($_GET['raw'])) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo $markdown;
    exit;
}

// ── CSS ───────────────────────────────────────────────────────────────
$fontFamily = htmlspecialchars($cfg['font_family'], ENT_QUOTES, 'UTF-8');
$fontSize = htmlspecialchars($cfg['font_size'], ENT_QUOTES, 'UTF-8');
$lineHeight = htmlspecialchars($cfg['line_height'], ENT_QUOTES, 'UTF-8');
$maxWidth = htmlspecialchars($cfg['max_width'], ENT_QUOTES, 'UTF-8');

// highlight.js Theme URLs
$hlVersion = '11.9.0';
$hlThemeLight = htmlspecialchars($cfg['highlight_theme_light'], ENT_QUOTES, 'UTF-8');
$hlThemeDark  = htmlspecialchars($cfg['highlight_theme_dark'], ENT_QUOTES, 'UTF-8');

// ── HTML-Output ───────────────────────────────────────────────────────
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?></title>

<?php if ($cfg['highlight_code']): ?>
<?php if ($cfg['theme'] === 'light' || $cfg['theme'] === 'auto'): ?>
<link rel="stylesheet" id="hl-light" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/<?= $hlVersion ?>/styles/<?= $hlThemeLight ?>.min.css"
  <?php if ($cfg['theme'] === 'auto'): ?>media="(prefers-color-scheme: light)"<?php endif ?>>
<?php endif ?>
<?php if ($cfg['theme'] === 'dark' || $cfg['theme'] === 'auto'): ?>
<link rel="stylesheet" id="hl-dark" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/<?= $hlVersion ?>/styles/<?= $hlThemeDark ?>.min.css"
  <?php if ($cfg['theme'] === 'auto'): ?>media="(prefers-color-scheme: dark)"<?php endif ?>>
<?php endif ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/<?= $hlVersion ?>/highlight.min.js"></script>
<?php endif ?>

<style>
/* ── Reset & Base ──────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }

:root {
    --bg: #ffffff;
    --fg: #1f2328;
    --fg-muted: #656d76;
    --border: #d0d7de;
    --border-light: #e8ecf0;
    --bg-code: #f6f8fa;
    --bg-blockquote: #f6f8fa;
    --accent: #0969da;
    --accent-hover: #0550ae;
    --bg-card: #ffffff;
    --shadow: 0 1px 3px rgba(0,0,0,0.08);
}

@media (prefers-color-scheme: dark) {
    :root {
        --bg: #0d1117;
        --fg: #e6edf3;
        --fg-muted: #8b949e;
        --border: #30363d;
        --border-light: #21262d;
        --bg-code: #161b22;
        --bg-blockquote: #161b22;
        --accent: #58a6ff;
        --accent-hover: #79c0ff;
        --bg-card: #0d1117;
        --shadow: 0 1px 3px rgba(0,0,0,0.3);
    }
}

<?php if ($cfg['theme'] === 'light'): ?>
/* Force light */
:root {
    --bg: #ffffff;
    --fg: #1f2328;
    --fg-muted: #656d76;
    --border: #d0d7de;
    --border-light: #e8ecf0;
    --bg-code: #f6f8fa;
    --bg-blockquote: #f6f8fa;
    --accent: #0969da;
    --accent-hover: #0550ae;
    --bg-card: #ffffff;
    --shadow: 0 1px 3px rgba(0,0,0,0.08);
}
<?php elseif ($cfg['theme'] === 'dark'): ?>
/* Force dark */
:root {
    --bg: #0d1117;
    --fg: #e6edf3;
    --fg-muted: #8b949e;
    --border: #30363d;
    --border-light: #21262d;
    --bg-code: #161b22;
    --bg-blockquote: #161b22;
    --accent: #58a6ff;
    --accent-hover: #79c0ff;
    --bg-card: #0d1117;
    --shadow: 0 1px 3px rgba(0,0,0,0.3);
}
<?php endif ?>

html { font-size: <?= $fontSize ?>; }

body {
    font-family: <?= $fontFamily ?>;
    line-height: <?= $lineHeight ?>;
    color: var(--fg);
    background: var(--bg);
    margin: 0;
    padding: 2rem 1rem;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

.container {
    max-width: <?= $maxWidth ?>;
    margin: 0 auto;
}

/* ── Meta ──────────────────────────────────────── */
.meta {
    color: var(--fg-muted);
    font-size: 0.85rem;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border-light);
}

.source-link {
    float: right;
    color: var(--accent);
    font-size: 0.85rem;
    text-decoration: none;
}
.source-link:hover { text-decoration: underline; }

/* ── TOC ───────────────────────────────────────── */
.toc {
    background: var(--bg-code);
    border: 1px solid var(--border-light);
    border-radius: 8px;
    padding: 1rem 1.5rem;
    margin-bottom: 2rem;
}
.toc summary {
    font-weight: 600;
    cursor: pointer;
    color: var(--fg);
}
.toc ul {
    list-style: none;
    padding-left: 0;
    margin: 0.5rem 0 0 0;
}
.toc li {
    padding: 0.2rem 0;
}
.toc a {
    color: var(--accent);
    text-decoration: none;
}
.toc a:hover {
    text-decoration: underline;
}

/* ── Typography ────────────────────────────────── */
.content h1, .content h2, .content h3, .content h4, .content h5, .content h6 {
    margin-top: 1.8em;
    margin-bottom: 0.6em;
    font-weight: 600;
    line-height: 1.3;
    color: var(--fg);
}

.content h1 { font-size: 2rem; padding-bottom: 0.3em; border-bottom: 1px solid var(--border-light); }
.content h2 { font-size: 1.5rem; padding-bottom: 0.3em; border-bottom: 1px solid var(--border-light); }
.content h3 { font-size: 1.25rem; }
.content h4 { font-size: 1rem; }

.content p { margin: 0 0 1em; }

.content a {
    color: var(--accent);
    text-decoration: none;
}
.content a:hover {
    text-decoration: underline;
    color: var(--accent-hover);
}

/* ── Lists ─────────────────────────────────────── */
.content ul, .content ol {
    padding-left: 2em;
    margin: 0 0 1em;
}
.content li { margin: 0.25em 0; }
.content li > ul, .content li > ol { margin-bottom: 0; }

/* Task lists */
.content li input[type="checkbox"] {
    margin-right: 0.5em;
}

/* ── Code ──────────────────────────────────────── */
.content code {
    font-family: "JetBrains Mono", "Fira Code", "SF Mono", Consolas, "Liberation Mono", Menlo, monospace;
    font-size: 0.875em;
    background: var(--bg-code);
    padding: 0.2em 0.4em;
    border-radius: 4px;
}

.content pre {
    background: var(--bg-code);
    border: 1px solid var(--border-light);
    border-radius: 8px;
    padding: 1rem;
    overflow-x: auto;
    margin: 0 0 1em;
    line-height: 1.5;
}

.content pre code {
    background: none;
    padding: 0;
    border-radius: 0;
    font-size: 0.85rem;
}

/* ── Blockquotes ───────────────────────────────── */
.content blockquote {
    margin: 0 0 1em;
    padding: 0.5rem 1rem;
    border-left: 4px solid var(--accent);
    background: var(--bg-blockquote);
    color: var(--fg-muted);
    border-radius: 0 8px 8px 0;
}
.content blockquote p:last-child { margin-bottom: 0; }

/* ── Tables ────────────────────────────────────── */
.content table {
    border-collapse: collapse;
    width: 100%;
    margin: 0 0 1em;
    font-size: 0.95em;
}
.content th, .content td {
    border: 1px solid var(--border);
    padding: 0.5rem 0.75rem;
    text-align: left;
}
.content th {
    background: var(--bg-code);
    font-weight: 600;
}
.content tr:nth-child(even) {
    background: var(--bg-code);
}

/* ── Images ────────────────────────────────────── */
.content img {
    max-width: 100%;
    height: auto;
    border-radius: 8px;
    border: 1px solid var(--border-light);
}

/* ── Horizontal Rules ──────────────────────────── */
.content hr {
    border: none;
    border-top: 1px solid var(--border);
    margin: 2em 0;
}

/* ── Definition Lists (ParsedownExtra) ─────────── */
.content dl { margin: 0 0 1em; }
.content dt { font-weight: 600; margin-top: 0.5em; }
.content dd { margin-left: 2em; color: var(--fg-muted); }

/* ── Footnotes (ParsedownExtra) ────────────────── */
.content .footnotes {
    margin-top: 2em;
    padding-top: 1em;
    border-top: 1px solid var(--border-light);
    font-size: 0.9em;
    color: var(--fg-muted);
}

/* ── Print ─────────────────────────────────────── */
@media print {
    body { background: white; color: black; padding: 0; }
    .meta, .source-link, .toc summary { color: #666; }
    .content a { color: #0366d6; }
    .content pre { border: 1px solid #ddd; }
}
</style>

<?php if (!empty($cfg['custom_css'])): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($cfg['custom_css'], ENT_QUOTES, 'UTF-8') ?>">
<?php endif ?>

</head>
<body>
<div class="container">

<?php if ($cfg['show_source_link']): ?>
<?= $sourceLinkHtml ?>
<?php endif ?>

<?php if ($cfg['show_meta']): ?>
<?= $metaHtml ?>
<?php endif ?>

<?= $tocHtml ?>

<div class="content">
<?= $htmlContent ?>
</div>

</div>

<?php if ($cfg['highlight_code']): ?>
<script>hljs.highlightAll();</script>
<?php endif ?>

</body>
</html>
