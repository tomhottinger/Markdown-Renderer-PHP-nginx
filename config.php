<?php
/**
 * Markdown Renderer - Konfiguration
 *
 * Alle Optionen können hier zentral angepasst werden.
 * Einzelne Optionen können auch per Query-Parameter überschrieben werden:
 *   ?theme=dark&toc=1&highlight_theme=monokai
 */

return [

    // ── Pfade ─────────────────────────────────────────────────────────
    // Basis-Verzeichnis für ?file= Parameter (absoluter Pfad im Container)
    // Leer = Script-Verzeichnis (__DIR__)
    'doc_root' => __DIR__,

    // ── Parser ────────────────────────────────────────────────────────
    // 'parsedown'       = Standard Markdown + GFM (Tabellen, Fenced Code, Strikethrough)
    // 'parsedown-extra' = Zusätzlich: Fussnoten, Definitionslisten, Abkürzungen, Attribute
    'parser' => 'parsedown-extra',

    // GFM-Zeilenumbrüche: Einfacher Zeilenumbruch = <br>
    'breaks' => true,

    // Inline-HTML im Markdown erlauben
    'markup' => true,

    // Safe Mode: Escaped allen HTML (überschreibt markup). Für untrusted Content.
    'safe_mode' => false,

    // URLs automatisch verlinken
    'urls_linked' => true,

    // ── Theme / Erscheinungsbild ──────────────────────────────────────
    // 'light', 'dark', 'auto' (folgt System-Einstellung via prefers-color-scheme)
    'theme' => 'auto',

    // Maximale Breite des Content-Bereichs
    'max_width' => '860px',

    // Schriftart
    'font_family' => 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',

    // Basis-Schriftgrösse
    'font_size' => '17px',

    // Zeilenhöhe
    'line_height' => '1.7',

    // Zusätzliche CSS-Datei (Pfad relativ zum Webroot oder absolute URL)
    'custom_css' => '',

    // ── Code Syntax-Highlighting ──────────────────────────────────────
    // Aktiviert highlight.js (wird von CDN geladen)
    'highlight_code' => true,

    // highlight.js Theme-Name (z.B. 'github', 'github-dark', 'monokai', 'dracula',
    // 'nord', 'atom-one-dark', 'vs2015', 'solarized-dark', 'tokyo-night-dark')
    // Bei theme=auto wird automatisch ein passendes Light/Dark-Paar verwendet.
    'highlight_theme_light' => 'github',
    'highlight_theme_dark'  => 'github-dark',

    // ── Features ──────────────────────────────────────────────────────
    // Automatisches Inhaltsverzeichnis generieren
    'toc' => false,

    // TOC nur anzeigen wenn mindestens N Headings vorhanden
    'toc_min_headings' => 3,

    // Dateiname / erste H1 als Seitentitel anzeigen
    'show_title' => true,

    // Metadaten anzeigen (letztes Änderungsdatum)
    'show_meta' => true,

    // Link zur rohen .md-Datei anzeigen
    'show_source_link' => false,

    // ── Sicherheit ────────────────────────────────────────────────────
    // Nur Dateien aus diesen Verzeichnissen erlauben (leer = alles unter Document Root)
    // Beispiel: ['/app/t71.ch/public/docs', '/app/t71.ch/public/wiki']
    'allowed_dirs' => [],

    // Dateien mit diesen Namen blockieren
    'blocked_files' => [],
];
