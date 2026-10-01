<?php
/**
 * MidnightPDF - Single File PHP & JavaScript Document & Media Viewer
 * 
 * Features:
 * - Collapsible directory tree view for any file/folder in MidnightPDF-data
 * - Native PDF viewer powered by Mozilla PDF.js
 * - Media preview for Images (.jpg, .png, .webp, .gif, .bmp, .svg),
 *   Videos (.mp4, .3gp, .webm), Audio (.mp3, .m4a, .aac, .wav, .opus)
 * - Code & text viewer with syntax highlighting (Highlight.js) & line numbers
 * - Graceful unsupported file fallback with download button
 * - Mobile responsive drawer & desktop collapsible sidepane
 * - Full HTTP Range requests support for smooth video/audio seeking and PDF byte ranges
 * - Deep linking via URL hash & instant search filter
 */

declare(strict_types=1);

// Configuration
$DATA_DIR_NAME = 'MidnightPDF-data';
$DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . $DATA_DIR_NAME;

// Ensure data folder exists
if (!is_dir($DATA_DIR)) {
    @mkdir($DATA_DIR, 0755, true);
}

$REAL_BASE_DIR = realpath($DATA_DIR);

/**
 * Validate and safely resolve relative file path to prevent directory traversal
 */
function resolve_safe_path(?string $relativePath, string $realBaseDir): ?string {
    if ($relativePath === null || $relativePath === '') {
        return null;
    }

    // Disallow null bytes
    if (strpos($relativePath, "\0") !== false) {
        return null;
    }

    // Normalize slashes
    $cleanPath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relativePath);
    $cleanPath = ltrim($cleanPath, DIRECTORY_SEPARATOR);

    $fullPath = $realBaseDir . DIRECTORY_SEPARATOR . $cleanPath;
    $realFullPath = realpath($fullPath);

    if ($realFullPath === false) {
        return null;
    }

    // Ensure the resolved realpath starts with the real base directory
    if ($realFullPath !== $realBaseDir && strpos($realFullPath, $realBaseDir . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }

    return $realFullPath;
}

/**
 * Format bytes into human-readable size
 */
function format_bytes(int $bytes): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = floor(log($bytes, 1024));
    $pow = min((int)$pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), 1) . ' ' . $units[$pow];
}

/**
 * Detect file category
 */
function get_file_category(string $ext): string {
    $ext = strtolower($ext);
    if ($ext === 'pdf') {
        return 'pdf';
    }
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'avif'])) {
        return 'image';
    }
    if (in_array($ext, ['mp4', '3gp', 'webm', 'mkv', 'mov', 'avi'])) {
        return 'video';
    }
    if (in_array($ext, ['mp3', 'm4a', 'aac', 'wav', 'opus', 'ogg', 'flac', 'wma'])) {
        return 'audio';
    }
    if (in_array($ext, [
        'md', 'txt', 'html', 'htm', 'php', 'css', 'js', 'mjs', 'ts', 'tsx', 'jsx',
        'cpp', 'c', 'h', 'hpp', 'py', 'java', 'json', 'yaml', 'yml', 'xml', 'sql',
        'sh', 'bash', 'rs', 'go', 'env', 'ini', 'log', 'toml', 'lua', 'dart', 'rb'
    ])) {
        return 'code';
    }
    return 'unsupported';
}

/**
 * Detect MIME type
 */
function get_mime_type(string $filePath): string {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimes = [
        'pdf'   => 'application/pdf',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'png'   => 'image/png',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'bmp'   => 'image/bmp',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'avif'  => 'image/avif',
        'mp4'   => 'video/mp4',
        '3gp'   => 'video/3gpp',
        'webm'  => 'video/webm',
        'mkv'   => 'video/x-matroska',
        'mov'   => 'video/quicktime',
        'avi'   => 'video/x-msvideo',
        'mp3'   => 'audio/mpeg',
        'm4a'   => 'audio/mp4',
        'aac'   => 'audio/aac',
        'wav'   => 'audio/wav',
        'opus'  => 'audio/opus',
        'ogg'   => 'audio/ogg',
        'flac'  => 'audio/flac',
        'txt'   => 'text/plain; charset=utf-8',
        'md'    => 'text/markdown; charset=utf-8',
        'html'  => 'text/html; charset=utf-8',
        'htm'   => 'text/html; charset=utf-8',
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'json'  => 'application/json; charset=utf-8',
        'xml'   => 'application/xml; charset=utf-8',
        'yaml'  => 'text/yaml; charset=utf-8',
        'yml'   => 'text/yaml; charset=utf-8',
        'php'   => 'text/plain; charset=utf-8',
        'py'    => 'text/plain; charset=utf-8',
        'c'     => 'text/plain; charset=utf-8',
        'cpp'   => 'text/plain; charset=utf-8',
        'h'     => 'text/plain; charset=utf-8',
        'hpp'   => 'text/plain; charset=utf-8',
        'java'  => 'text/plain; charset=utf-8',
        'rs'    => 'text/plain; charset=utf-8',
        'go'    => 'text/plain; charset=utf-8',
        'sh'    => 'text/plain; charset=utf-8',
        'sql'   => 'text/plain; charset=utf-8',
    ];
    return $mimes[$ext] ?? 'application/octet-stream';
}

/**
 * Scan directory recursively into nested tree structure
 */
function scan_directory_tree(string $dir, string $baseDir): array {
    $items = [];
    if (!is_dir($dir) || !is_readable($dir)) {
        return $items;
    }

    $entries = scandir($dir);
    if ($entries === false) {
        return $items;
    }

    $folders = [];
    $files = [];

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
            continue; // Skip hidden files/directories
        }

        $fullPath = $dir . DIRECTORY_SEPARATOR . $entry;
        // Relative path normalized with forward slashes for URLs
        $relPath = str_replace('\\', '/', substr($fullPath, strlen($baseDir) + 1));

        if (is_dir($fullPath)) {
            $children = scan_directory_tree($fullPath, $baseDir);
            $folders[] = [
                'type' => 'folder',
                'name' => $entry,
                'path' => $relPath,
                'children' => $children,
                'itemCount' => count($children),
            ];
        } else {
            $ext = pathinfo($entry, PATHINFO_EXTENSION);
            $size = filesize($fullPath) ?: 0;
            $mtime = filemtime($fullPath) ?: time();
            $files[] = [
                'type' => 'file',
                'name' => $entry,
                'path' => $relPath,
                'ext' => strtolower($ext),
                'category' => get_file_category($ext),
                'size' => $size,
                'sizeFormatted' => format_bytes($size),
                'mtime' => $mtime,
                'mtimeFormatted' => date('d M Y, H:i', $mtime),
            ];
        }
    }

    // Sort folders alphabetically (case-insensitive)
    usort($folders, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    // Sort files alphabetically (case-insensitive)
    usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    return array_merge($folders, $files);
}

/**
 * Compute aggregate statistics for the tree
 */
function compute_stats(array $tree): array {
    $stats = [
        'totalFiles' => 0,
        'totalFolders' => 0,
        'pdfCount' => 0,
        'imageCount' => 0,
        'audioCount' => 0,
        'videoCount' => 0,
        'codeCount' => 0,
        'otherCount' => 0,
        'totalSize' => 0,
    ];

    $traverse = function(array $items) use (&$traverse, &$stats) {
        foreach ($items as $item) {
            if ($item['type'] === 'folder') {
                $stats['totalFolders']++;
                if (!empty($item['children'])) {
                    $traverse($item['children']);
                }
            } elseif ($item['type'] === 'file') {
                $stats['totalFiles']++;
                $stats['totalSize'] += $item['size'];
                $cat = $item['category'];
                if ($cat === 'pdf') $stats['pdfCount']++;
                elseif ($cat === 'image') $stats['imageCount']++;
                elseif ($cat === 'audio') $stats['audioCount']++;
                elseif ($cat === 'video') $stats['videoCount']++;
                elseif ($cat === 'code') $stats['codeCount']++;
                else $stats['otherCount']++;
            }
        }
    };

    $traverse($tree);
    $stats['totalSizeFormatted'] = format_bytes($stats['totalSize']);
    return $stats;
}

/**
 * Stream file with HTTP Range support (crucial for video/audio seeking and PDF byte ranges)
 */
function stream_file_with_range(string $filePath, bool $isDownload = false): void {
    if (!file_exists($filePath) || is_dir($filePath)) {
        http_response_code(404);
        die('File not found');
    }

    $size = filesize($filePath) ?: 0;
    $mime = get_mime_type($filePath);
    $filename = basename($filePath);

    // Clean output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Accept-Ranges: bytes');
    header('Content-Type: ' . $mime);

    // Content-Disposition
    $disposition = $isDownload ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($filename) . '"');

    $start = 0;
    $end = $size - 1;

    // Check for Range header
    if (isset($_SERVER['HTTP_RANGE'])) {
        $range = $_SERVER['HTTP_RANGE'];
        if (preg_match('/bytes=\h*(\d+)-(\d*)/i', $range, $matches)) {
            $start = intval($matches[1]);
            if (!empty($matches[2])) {
                $end = intval($matches[2]);
            }
            if ($start > $end || $start >= $size || $end >= $size) {
                http_response_code(416); // Range Not Satisfiable
                header("Content-Range: bytes */$size");
                exit;
            }

            http_response_code(206); // Partial Content
            header("Content-Range: bytes $start-$end/$size");
            header('Content-Length: ' . ($end - $start + 1));
        } else {
            http_response_code(416);
            exit;
        }
    } else {
        http_response_code(200);
        header('Content-Length: ' . $size);
    }

    $fp = fopen($filePath, 'rb');
    if ($fp === false) {
        http_response_code(500);
        exit;
    }

    fseek($fp, $start);
    $bytesToRead = $end - $start + 1;
    $chunkSize = 65536; // 64KB chunks

    while (!feof($fp) && $bytesToRead > 0 && connection_status() === 0) {
        $readLen = min($chunkSize, $bytesToRead);
        $buffer = fread($fp, $readLen);
        if ($buffer === false) break;
        echo $buffer;
        flush();
        $bytesToRead -= strlen($buffer);
    }
    fclose($fp);
    exit;
}

// -------------------------------------------------------------
// ROUTE HANDLING (AJAX API & File Serving)
// -------------------------------------------------------------
$action = $_GET['action'] ?? null;

if ($action === 'tree') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$REAL_BASE_DIR) {
        echo json_encode(['success' => false, 'error' => 'Data directory not found']);
        exit;
    }
    $tree = scan_directory_tree($REAL_BASE_DIR, $REAL_BASE_DIR);
    $stats = compute_stats($tree);
    echo json_encode(['success' => true, 'tree' => $tree, 'stats' => $stats]);
    exit;
}

if ($action === 'raw' || $action === 'download') {
    $reqFile = $_GET['file'] ?? '';
    $safePath = resolve_safe_path($reqFile, $REAL_BASE_DIR);
    if (!$safePath) {
        http_response_code(403);
        die('Akses ditolak atau file tidak ditemukan.');
    }
    stream_file_with_range($safePath, $action === 'download');
    exit;
}

if ($action === 'text') {
    $reqFile = $_GET['file'] ?? '';
    $safePath = resolve_safe_path($reqFile, $REAL_BASE_DIR);
    if (!$safePath || !is_file($safePath)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'File tidak ditemukan']);
        exit;
    }

    // Max 5MB for text preview
    if (filesize($safePath) > 5 * 1024 * 1024) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'File teks terlalu besar untuk dipratinjau (>5MB)']);
        exit;
    }

    $content = file_get_contents($safePath);
    // Ensure UTF-8 safely (using mbstring or iconv if available)
    if (function_exists('mb_check_encoding')) {
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }
    } elseif (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $content);
        if ($converted !== false) {
            $content = $converted;
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'content' => $content,
        'size' => filesize($safePath),
        'lines' => substr_count($content, "\n") + 1,
    ]);
    exit;
}

// Initial Tree & Stats for instant rendering
$initialTree = $REAL_BASE_DIR ? scan_directory_tree($REAL_BASE_DIR, $REAL_BASE_DIR) : [];
$initialStats = compute_stats($initialTree);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MidnightPDF - Document & Media Hub</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%236366F1'%3E%3Cpath d='M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z'/%3E%3Cpolyline points='14 2 14 8 20 8' fill='none' stroke='%23090D16' stroke-width='2'/%3E%3C/svg%3E">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    
    <!-- Highlight.js for Code & Text files -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    
    <!-- PDF.js from Mozilla CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>

    <style>
        :root {
            --bg-base: #090D16;
            --bg-surface: #0F172A;
            --bg-surface-elevated: #162036;
            --bg-surface-glass: rgba(15, 23, 42, 0.85);
            --bg-hover: rgba(255, 255, 255, 0.06);
            --bg-active: rgba(99, 102, 241, 0.15);
            
            --border-subtle: rgba(255, 255, 255, 0.08);
            --border-medium: rgba(255, 255, 255, 0.14);
            --border-glow: rgba(99, 102, 241, 0.4);

            --accent-primary: #6366F1;
            --accent-primary-hover: #4F46E5;
            --accent-glow: rgba(99, 102, 241, 0.35);
            --accent-cyan: #06B6D4;
            --accent-pdf: #EF4444;
            --accent-image: #EC4899;
            --accent-video: #38BDF8;
            --accent-audio: #F59E0B;
            --accent-code: #10B981;
            --accent-other: #94A3B8;

            --text-primary: #F8FAFC;
            --text-secondary: #94A3B8;
            --text-muted: #64748B;
            --text-light: #CBD5E1;

            --header-height: 64px;
            --sidepane-width: 320px;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
            --radius-full: 9999px;

            --font-sans: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
            
            --transition-fast: 150ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-normal: 250ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-bounce: 350ms cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-sans);
            background-color: var(--bg-base);
            color: var(--text-primary);
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Scrollbars */
        ::-webkit-scrollbar {
            width: 7px;
            height: 7px;
        }
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
            border-radius: var(--radius-full);
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.25);
        }

        /* -------------------------------------------------------------
           HEADER
           ------------------------------------------------------------- */
        .app-header {
            height: var(--header-height);
            background: var(--bg-surface-glass);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border-subtle);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            position: relative;
            z-index: 50;
            flex-shrink: 0;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 220px;
        }

        .btn-icon {
            background: transparent;
            border: 1px solid var(--border-subtle);
            color: var(--text-light);
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: var(--radius-md);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-fast);
            position: relative;
            user-select: none;
            flex-shrink: 0;
        }

        .btn-icon:hover:not(:disabled) {
            background: var(--bg-hover);
            color: var(--text-primary);
            border-color: var(--border-medium);
            transform: translateY(-1px);
        }

        .btn-icon:active:not(:disabled) {
            transform: translateY(0) scale(0.96);
        }

        .btn-icon:disabled {
            opacity: 0.35;
            cursor: not-allowed;
            pointer-events: none;
        }

        .btn-icon.active {
            background: var(--accent-primary);
            color: #fff;
            border-color: var(--accent-primary);
            box-shadow: 0 0 16px var(--accent-glow);
        }

        .brand-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            color: inherit;
            cursor: pointer;
            user-select: none;
        }

        .brand-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #6366F1 0%, #3B82F6 100%);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 14px var(--accent-glow);
        }

        .brand-icon svg {
            width: 18px;
            height: 18px;
            color: #fff;
        }

        .brand-title {
            font-size: 1.05rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            background: linear-gradient(90deg, #FFFFFF 0%, #CBD5E1 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .brand-title span {
            color: var(--accent-primary);
            -webkit-text-fill-color: var(--accent-primary);
        }

        /* Center Breadcrumbs / Path display */
        .header-center {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 20px;
            overflow: hidden;
        }

        .path-breadcrumb {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            background: var(--bg-surface-elevated);
            border: 1px solid var(--border-subtle);
            padding: 6px 14px;
            border-radius: var(--radius-full);
            font-size: 0.86rem;
            color: var(--text-light);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            transition: border-color var(--transition-fast);
        }

        .path-breadcrumb:hover {
            border-color: var(--border-medium);
        }

        .path-breadcrumb.empty {
            color: var(--text-muted);
            font-style: italic;
        }

        .path-icon {
            margin-right: 8px;
            display: inline-flex;
            align-items: center;
            color: var(--accent-primary);
        }

        .path-separator {
            margin: 0 6px;
            color: var(--text-muted);
            font-size: 0.75rem;
        }

        .path-segment {
            color: var(--text-secondary);
        }

        .path-segment.active-file {
            color: var(--text-primary);
            font-weight: 600;
        }

        /* Right actions */
        .header-right {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 140px;
            justify-content: flex-end;
        }

        .btn-download-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(99, 102, 241, 0.12);
            border: 1px solid rgba(99, 102, 241, 0.3);
            color: #818CF8;
            padding: 8px 14px;
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all var(--transition-fast);
            text-decoration: none;
        }

        .btn-download-action:hover:not(.disabled) {
            background: var(--accent-primary);
            color: #fff;
            box-shadow: 0 0 16px var(--accent-glow);
            transform: translateY(-1px);
        }

        .btn-download-action.disabled {
            opacity: 0.35;
            cursor: not-allowed;
            pointer-events: none;
        }

        .btn-close-file {
            width: 38px;
            height: 38px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-subtle);
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-fast);
        }

        .btn-close-file:hover:not(.disabled) {
            background: rgba(239, 68, 68, 0.15);
            border-color: rgba(239, 68, 68, 0.4);
            color: #F87171;
            transform: translateY(-1px);
        }

        .btn-close-file.disabled {
            opacity: 0.25;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* -------------------------------------------------------------
           MAIN LAYOUT: SIDEPANE + MAIN CONTENT
           ------------------------------------------------------------- */
        .app-body {
            display: flex;
            flex: 1;
            height: calc(100vh - var(--header-height));
            position: relative;
            overflow: hidden;
        }

        /* Backdrop overlay for mobile drawer */
        .drawer-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
            z-index: 40;
            opacity: 0;
            transition: opacity var(--transition-normal);
        }

        .drawer-backdrop.active {
            display: block;
            opacity: 1;
        }

        /* Sidepane */
        .app-sidepane {
            width: var(--sidepane-width);
            background: var(--bg-surface);
            border-right: 1px solid var(--border-subtle);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            transition: margin-left var(--transition-normal), transform var(--transition-normal);
            position: relative;
            z-index: 45;
            height: 100%;
            overflow: hidden;
        }

        .app-sidepane.collapsed {
            margin-left: calc(-1 * var(--sidepane-width));
        }

        /* Sidepane Header / Search & Tools */
        .sidepane-top {
            padding: 14px 14px 10px;
            border-bottom: 1px solid var(--border-subtle);
            display: flex;
            flex-direction: column;
            gap: 10px;
            background: rgba(15, 23, 42, 0.5);
        }

        .sidepane-meta-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .sidepane-title {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-muted);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .sidepane-actions-row {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .mini-btn {
            background: transparent;
            border: 1px solid transparent;
            color: var(--text-muted);
            cursor: pointer;
            width: 28px;
            height: 28px;
            border-radius: var(--radius-sm);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-fast);
        }

        .mini-btn:hover {
            color: var(--text-primary);
            background: var(--bg-hover);
            border-color: var(--border-subtle);
        }

        .search-box-wrap {
            position: relative;
            width: 100%;
        }

        .search-box-wrap svg.search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            width: 15px;
            height: 15px;
            pointer-events: none;
        }

        .search-input {
            width: 100%;
            background: var(--bg-surface-elevated);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            padding: 8px 30px 8px 32px;
            color: var(--text-primary);
            font-size: 0.84rem;
            font-family: inherit;
            outline: none;
            transition: all var(--transition-fast);
        }

        .search-input:focus {
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
            background: rgba(22, 32, 54, 0.95);
        }

        .search-input::placeholder {
            color: var(--text-muted);
        }

        .search-clear-btn {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            display: none;
            padding: 2px;
        }

        .search-clear-btn:hover {
            color: var(--text-primary);
        }

        /* Tree View Container */
        .tree-container {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 10px 8px 24px;
        }

        .tree-node-group {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .tree-item {
            display: flex;
            align-items: center;
            padding: 6px 8px;
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
            font-size: 0.85rem;
            cursor: pointer;
            user-select: none;
            position: relative;
            transition: all var(--transition-fast);
            gap: 6px;
            text-decoration: none;
        }

        .tree-item:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
        }

        .tree-item.active {
            background: var(--bg-active);
            color: #FFFFFF;
            font-weight: 500;
        }

        .tree-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 4px;
            bottom: 4px;
            width: 3px;
            background: var(--accent-primary);
            border-radius: 2px;
            box-shadow: 0 0 8px var(--accent-glow);
        }

        .tree-chevron {
            width: 16px;
            height: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            transition: transform var(--transition-fast);
            flex-shrink: 0;
        }

        .tree-chevron.expanded {
            transform: rotate(90deg);
        }

        .tree-chevron.empty-space {
            opacity: 0;
            pointer-events: none;
        }

        .tree-icon {
            width: 18px;
            height: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .tree-icon.icon-folder { color: #F59E0B; }
        .tree-icon.icon-pdf { color: var(--accent-pdf); }
        .tree-icon.icon-image { color: var(--accent-image); }
        .tree-icon.icon-video { color: var(--accent-video); }
        .tree-icon.icon-audio { color: var(--accent-audio); }
        .tree-icon.icon-code { color: var(--accent-code); }
        .tree-icon.icon-unsupported { color: var(--accent-other); }

        .tree-label {
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .tree-badge {
            font-size: 0.7rem;
            color: var(--text-muted);
            background: rgba(255, 255, 255, 0.05);
            padding: 1px 6px;
            border-radius: var(--radius-full);
            flex-shrink: 0;
        }

        .tree-children {
            margin-left: 14px;
            padding-left: 6px;
            border-left: 1px solid rgba(255, 255, 255, 0.06);
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .tree-children.hidden {
            display: none;
        }

        .tree-empty-notice {
            padding: 30px 16px;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.85rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }

        /* Sidepane Footer Stats */
        .sidepane-footer {
            padding: 10px 14px;
            border-top: 1px solid var(--border-subtle);
            font-size: 0.75rem;
            color: var(--text-muted);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(15, 23, 42, 0.7);
        }

        /* -------------------------------------------------------------
           MAIN CONTENT AREA
           ------------------------------------------------------------- */
        .app-main {
            flex: 1;
            height: 100%;
            background: var(--bg-base);
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
        }

        /* EMPTY STATE */
        .empty-state-view {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            padding: 40px 20px;
            text-align: center;
            position: relative;
            overflow-y: auto;
        }

        .empty-glow {
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.12) 0%, rgba(99, 102, 241, 0) 70%);
            border-radius: 50%;
            pointer-events: none;
            z-index: 0;
        }

        .empty-card {
            position: relative;
            z-index: 1;
            max-width: 540px;
            background: var(--bg-surface-glass);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            padding: 40px 32px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
        }

        .empty-illustration {
            width: 80px;
            height: 80px;
            background: rgba(99, 102, 241, 0.12);
            border: 1px solid rgba(99, 102, 241, 0.25);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent-primary);
            box-shadow: 0 0 24px rgba(99, 102, 241, 0.2);
            margin-bottom: 8px;
        }

        .empty-title {
            font-size: 1.45rem;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.02em;
        }

        .empty-desc {
            font-size: 0.95rem;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .empty-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            width: 100%;
            margin-top: 10px;
        }

        .stat-card {
            background: var(--bg-surface-elevated);
            border: 1px solid var(--border-subtle);
            padding: 12px 8px;
            border-radius: var(--radius-md);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }

        .stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .empty-tip {
            font-size: 0.82rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 6px;
        }

        /* -------------------------------------------------------------
           VIEWER CONTAINERS
           ------------------------------------------------------------- */
        .viewer-container {
            display: none;
            width: 100%;
            height: 100%;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }

        .viewer-container.active {
            display: flex;
        }

        /* Sub-Toolbar for Viewers */
        .viewer-toolbar {
            height: 48px;
            background: var(--bg-surface);
            border-bottom: 1px solid var(--border-subtle);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            flex-shrink: 0;
            gap: 8px;
            z-index: 10;
        }

        .toolbar-group {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .toolbar-btn {
            background: transparent;
            border: 1px solid var(--border-subtle);
            color: var(--text-light);
            cursor: pointer;
            height: 32px;
            padding: 0 10px;
            border-radius: var(--radius-sm);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-size: 0.82rem;
            transition: all var(--transition-fast);
        }

        .toolbar-btn:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
            border-color: var(--border-medium);
        }

        .toolbar-btn:active {
            transform: scale(0.97);
        }

        .toolbar-input {
            width: 48px;
            background: var(--bg-surface-elevated);
            border: 1px solid var(--border-subtle);
            color: var(--text-primary);
            text-align: center;
            padding: 4px;
            border-radius: var(--radius-sm);
            font-size: 0.82rem;
            outline: none;
        }

        .toolbar-input:focus {
            border-color: var(--accent-primary);
        }

        .toolbar-text {
            font-size: 0.82rem;
            color: var(--text-muted);
        }

        .toolbar-select {
            background: var(--bg-surface-elevated);
            border: 1px solid var(--border-subtle);
            color: var(--text-light);
            padding: 4px 8px;
            border-radius: var(--radius-sm);
            font-size: 0.82rem;
            outline: none;
            cursor: pointer;
        }

        /* -------------------------------------------------------------
           PDF VIEWER SPECIFIC
           ------------------------------------------------------------- */
        .pdf-viewport {
            flex: 1;
            overflow: auto;
            padding: 24px 16px;
            background: #0B0E14;
            position: relative;
            box-sizing: border-box;
            -webkit-overflow-scrolling: touch;
        }

        .pdf-canvas-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
            width: fit-content;
            min-width: min-content;
            margin: 0 auto;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.6);
            border-radius: 4px;
        }

        .pdf-page-canvas {
            display: block;
            background: #FFFFFF;
            border-radius: 2px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.5);
            flex-shrink: 0;
            /* Never set max-width: 100% on canvas as it squishes width while keeping height fixed! */
        }

        /* PDF Loading Overlay */
        .loading-overlay {
            position: absolute;
            inset: 0;
            background: rgba(9, 13, 22, 0.8);
            backdrop-filter: blur(4px);
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 14px;
            z-index: 20;
        }

        .loading-overlay.active {
            display: flex;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid rgba(99, 102, 241, 0.2);
            border-top-color: var(--accent-primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* -------------------------------------------------------------
           IMAGE VIEWER
           ------------------------------------------------------------- */
        .image-viewport {
            flex: 1;
            overflow: auto;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px;
            background: #080B12;
            background-image: radial-gradient(rgba(255, 255, 255, 0.05) 1px, transparent 0);
            background-size: 24px 24px;
        }

        .image-preview {
            max-width: 90%;
            max-height: 85vh;
            border-radius: var(--radius-md);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.7);
            transition: transform var(--transition-fast);
            cursor: grab;
            object-fit: contain;
        }

        /* -------------------------------------------------------------
           VIDEO VIEWER
           ------------------------------------------------------------- */
        .video-viewport {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 30px 20px;
            background: #080B12;
        }

        .video-wrapper {
            max-width: 960px;
            width: 100%;
            background: #000;
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.8);
            border: 1px solid var(--border-subtle);
        }

        video.video-player {
            width: 100%;
            max-height: 70vh;
            display: block;
            outline: none;
        }

        /* -------------------------------------------------------------
           AUDIO VIEWER
           ------------------------------------------------------------- */
        .audio-viewport {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 20px;
            background: #080B12;
        }

        .audio-card {
            max-width: 500px;
            width: 100%;
            background: var(--bg-surface-elevated);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            padding: 36px 28px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6);
            position: relative;
            overflow: hidden;
        }

        .audio-disc-wrap {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background: radial-gradient(circle, #1E293B 20%, #0F172A 70%, #020617 100%);
            border: 4px solid rgba(245, 158, 11, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 30px rgba(245, 158, 11, 0.2);
            position: relative;
        }

        .audio-disc-wrap.spinning {
            animation: spin 6s linear infinite;
        }

        .audio-disc-center {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }

        .audio-info {
            text-align: center;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .audio-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .audio-path {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        audio.audio-player {
            width: 100%;
            outline: none;
            filter: drop-shadow(0 2px 8px rgba(0, 0, 0, 0.4));
        }

        /* -------------------------------------------------------------
           CODE & TEXT VIEWER
           ------------------------------------------------------------- */
        .code-viewport {
            flex: 1;
            overflow: auto;
            background: #1e1e2e;
            display: flex;
            flex-direction: column;
        }

        .code-container {
            display: flex;
            min-height: 100%;
            font-family: var(--font-mono);
            font-size: 0.88rem;
            line-height: 1.6;
        }

        .code-line-numbers {
            padding: 16px 14px;
            text-align: right;
            user-select: none;
            -webkit-user-select: none;
            color: rgba(255, 255, 255, 0.25);
            background: rgba(0, 0, 0, 0.18);
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            font-family: var(--font-mono) !important;
            font-size: 0.88rem !important;
            line-height: 1.6 !important;
            white-space: pre !important;
            flex-shrink: 0;
            min-width: 44px;
            box-sizing: border-box;
        }

        .code-content-wrap {
            flex: 1;
            padding: 16px 20px;
            overflow-x: auto;
            box-sizing: border-box;
        }

        .code-content-wrap pre {
            margin: 0;
            padding: 0;
            background: transparent !important;
            font-family: var(--font-mono) !important;
            font-size: 0.88rem !important;
            line-height: 1.6 !important;
        }

        .code-content-wrap code {
            font-family: var(--font-mono) !important;
            font-size: 0.88rem !important;
            line-height: 1.6 !important;
            background: transparent !important;
            padding: 0 !important;
            display: block;
        }

        .code-content-wrap.wrap-lines pre {
            white-space: pre-wrap;
            word-break: break-all;
        }

        /* -------------------------------------------------------------
           UNSUPPORTED FILE VIEW
           ------------------------------------------------------------- */
        .unsupported-viewport {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 20px;
        }

        .unsupported-card {
            max-width: 520px;
            width: 100%;
            background: var(--bg-surface-elevated);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            padding: 36px 28px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 18px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
        }

        .unsupported-icon {
            width: 72px;
            height: 72px;
            border-radius: var(--radius-full);
            background: rgba(148, 163, 184, 0.1);
            border: 1px solid rgba(148, 163, 184, 0.2);
            color: var(--accent-other);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .unsupported-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .unsupported-msg {
            font-size: 0.95rem;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .unsupported-meta {
            width: 100%;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            padding: 12px;
            display: flex;
            justify-content: space-around;
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .unsupported-meta strong {
            color: var(--text-primary);
        }

        .btn-big-download {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #6366F1 0%, #4F46E5 100%);
            color: #FFFFFF;
            padding: 12px 24px;
            border-radius: var(--radius-md);
            font-size: 0.92rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            box-shadow: 0 4px 16px var(--accent-glow);
            transition: all var(--transition-fast);
        }

        .btn-big-download:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 22px rgba(99, 102, 241, 0.5);
        }

        /* -------------------------------------------------------------
           TOAST NOTIFICATION
           ------------------------------------------------------------- */
        .toast-notification {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: var(--bg-surface-elevated);
            border: 1px solid var(--border-medium);
            color: var(--text-primary);
            padding: 10px 18px;
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 100;
            transform: translateY(100px);
            opacity: 0;
            transition: all var(--transition-bounce);
            pointer-events: none;
        }

        .toast-notification.active {
            transform: translateY(0);
            opacity: 1;
        }

        /* -------------------------------------------------------------
           RESPONSIVE MOBILE STYLING
           ------------------------------------------------------------- */
        @media (max-width: 768px) {
            :root {
                --sidepane-width: 290px;
                --header-height: 58px;
            }

            .app-sidepane {
                position: fixed;
                top: var(--header-height);
                bottom: 0;
                left: 0;
                transform: translateX(-100%);
                margin-left: 0 !important;
                box-shadow: none;
                visibility: hidden;
                pointer-events: none;
                transition: transform var(--transition-normal), visibility var(--transition-normal);
            }

            .app-sidepane.mobile-open {
                transform: translateX(0);
                box-shadow: 10px 0 30px rgba(0, 0, 0, 0.7);
                visibility: visible;
                pointer-events: auto;
            }

            .header-center {
                padding: 0 8px;
            }

            .path-breadcrumb {
                font-size: 0.78rem;
                padding: 4px 10px;
            }

            .btn-download-action span {
                display: none;
            }

            .btn-download-action {
                padding: 8px;
            }

            .empty-card {
                padding: 24px 16px;
            }

            .empty-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .viewer-toolbar {
                overflow-x: auto;
                padding: 0 8px;
            }
        }
    </style>
</head>
<body>

    <!-- ==========================================
         TOP HEADER BAR
         ========================================== -->
    <header class="app-header">
        <div class="header-left">
            <!-- Burger Menu Button (Visible & active on both Desktop and Mobile) -->
            <button id="btnBurgerToggle" class="btn-icon" title="Sembunyikan/Tampilkan Sidepane (Ctrl+B)">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="3" y1="12" x2="21" y2="12"></line>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <line x1="3" y1="18" x2="21" y2="18"></line>
                </svg>
            </button>

            <!-- Brand Logo -->
            <a href="MidnightPDF.php" class="brand-badge" title="MidnightPDF Home">
                <div class="brand-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <polyline points="10 9 9 9 8 9"></polyline>
                    </svg>
                </div>
                <div class="brand-title">Midnight<span>PDF</span></div>
            </a>
        </div>

        <!-- Center: File Path Breadcrumbs -->
        <div class="header-center">
            <div id="headerBreadcrumb" class="path-breadcrumb empty">
                <span class="path-icon">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
                    </svg>
                </span>
                <span id="breadcrumbText">Pilih file di sidepane untuk memulai</span>
            </div>
        </div>

        <!-- Right: Action Buttons (Download & Close "X") -->
        <div class="header-right">
            <a id="btnHeaderDownload" class="btn-download-action disabled" href="#" title="Unduh File Ini">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                <span>Unduh</span>
            </a>

            <button id="btnHeaderClose" class="btn-close-file disabled" title="Tutup File dan Kembali">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
    </header>

    <!-- ==========================================
         BODY: SIDEPANE & MAIN CONTENT
         ========================================== -->
    <div class="app-body">
        <!-- Backdrop for mobile drawer -->
        <div id="drawerBackdrop" class="drawer-backdrop"></div>

        <!-- Left Sidepane (Tree View) -->
        <aside id="appSidepane" class="app-sidepane">
            <div class="sidepane-top">
                <div class="sidepane-meta-row">
                    <div class="sidepane-title">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"></path>
                        </svg>
                        <span>MidnightPDF-data</span>
                    </div>
                    <div class="sidepane-actions-row">
                        <button id="btnExpandAll" class="mini-btn" title="Buka Semua Folder">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="7 13 12 18 17 13"></polyline>
                                <polyline points="7 6 12 11 17 6"></polyline>
                            </svg>
                        </button>
                        <button id="btnCollapseAll" class="mini-btn" title="Tutup Semua Folder">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="17 11 12 6 7 11"></polyline>
                                <polyline points="17 18 12 13 7 18"></polyline>
                            </svg>
                        </button>
                        <button id="btnRefreshTree" class="mini-btn" title="Refresh Daftar File">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Instant Search Input -->
                <div class="search-box-wrap">
                    <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <input type="text" id="sidepaneSearch" class="search-input" placeholder="Cari file atau folder..." autocomplete="off">
                    <button id="searchClearBtn" class="search-clear-btn" title="Bersihkan pencarian">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Tree View Item List -->
            <div id="treeContainer" class="tree-container">
                <!-- Injected via JavaScript -->
            </div>

            <!-- Sidepane Footer -->
            <div class="sidepane-footer">
                <span id="footerFileStats">0 File • 0 Folder</span>
                <span id="footerSizeStats">0 B</span>
            </div>
        </aside>

        <!-- Right Main Content Area -->
        <main class="app-main">

            <!-- 1. EMPTY STATE VIEW -->
            <div id="emptyStateView" class="empty-state-view">
                <div class="empty-glow"></div>
                <div class="empty-card">
                    <div class="empty-illustration">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="12" y1="18" x2="12" y2="12"></line>
                            <line x1="9" y1="15" x2="15" y2="15"></line>
                        </svg>
                    </div>
                    <h2 class="empty-title">Belum ada file dibuka</h2>
                    <p class="empty-desc">Pilih file di sidepane untuk memulai melihat dokumen PDF, media, atau kode sumber.</p>

                    <div class="empty-stats-grid">
                        <div class="stat-card">
                            <div class="stat-value" id="cardPdfCount">0</div>
                            <div class="stat-label">Dokumen PDF</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value" id="cardMediaCount">0</div>
                            <div class="stat-label">Media & Gambar</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value" id="cardCodeCount">0</div>
                            <div class="stat-label">Kode & Teks</div>
                        </div>
                    </div>

                    <div class="empty-tip">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="16" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12.01" y2="8"></line>
                        </svg>
                        <span>Tips: Gunakan tombol burger menu di pojok kiri atas untuk membuka/menutup sidepane.</span>
                    </div>
                </div>
            </div>

            <!-- 2. PDF VIEWER -->
            <div id="pdfViewerContainer" class="viewer-container">
                <div class="viewer-toolbar">
                    <div class="toolbar-group">
                        <button id="pdfPrevPage" class="toolbar-btn" title="Halaman Sebelumnya ([)">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"></polyline></svg>
                        </button>
                        <span class="toolbar-text">Hal</span>
                        <input type="number" id="pdfPageInput" class="toolbar-input" min="1" value="1">
                        <span class="toolbar-text">dari <span id="pdfTotalPages">1</span></span>
                        <button id="pdfNextPage" class="toolbar-btn" title="Halaman Selanjutnya (])">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </button>
                    </div>

                    <div class="toolbar-group">
                        <button id="pdfZoomOut" class="toolbar-btn" title="Perkecil (-)">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        </button>
                        <select id="pdfZoomSelect" class="toolbar-select">
                            <option value="0.75">75%</option>
                            <option value="1.0" selected>100%</option>
                            <option value="1.25">125%</option>
                            <option value="1.5">150%</option>
                            <option value="2.0">200%</option>
                            <option value="fit-width">Sesuaikan Lebar</option>
                            <option value="fit-page">Sesuaikan Halaman</option>
                        </select>
                        <button id="pdfZoomIn" class="toolbar-btn" title="Perbesar (+)">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        </button>
                        <button id="pdfRotateCw" class="toolbar-btn" title="Putar 90 Derajat">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"></path>
                            </svg>
                        </button>
                    </div>

                    <div class="toolbar-group">
                        <button id="pdfFullscreen" class="toolbar-btn" title="Layar Penuh">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="15 3 21 3 21 9"></polyline>
                                <polyline points="9 21 3 21 3 15"></polyline>
                                <line x1="21" y1="3" x2="14" y2="10"></line>
                                <line x1="3" y1="21" x2="10" y2="14"></line>
                            </svg>
                        </button>
                    </div>
                </div>

                <div id="pdfViewport" class="pdf-viewport">
                    <div id="pdfLoadingOverlay" class="loading-overlay">
                        <div class="spinner"></div>
                        <div class="toolbar-text">Memuat dokumen PDF...</div>
                    </div>
                    <div id="pdfCanvasContainer" class="pdf-canvas-container">
                        <!-- Canvas rendered via PDF.js -->
                    </div>
                </div>
            </div>

            <!-- 3. IMAGE VIEWER -->
            <div id="imageViewerContainer" class="viewer-container">
                <div class="viewer-toolbar">
                    <div class="toolbar-group">
                        <span id="imageMetaDimensions" class="toolbar-text">Memuat dimensi...</span>
                    </div>
                    <div class="toolbar-group">
                        <button id="btnImgZoomIn" class="toolbar-btn" title="Perbesar">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        </button>
                        <button id="btnImgZoomReset" class="toolbar-btn" title="Reset Zoom">Reset</button>
                        <button id="btnImgZoomOut" class="toolbar-btn" title="Perkecil">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        </button>
                        <button id="btnImgFullscreen" class="toolbar-btn" title="Layar Penuh">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="15 3 21 3 21 9"></polyline>
                                <polyline points="9 21 3 21 3 15"></polyline>
                                <line x1="21" y1="3" x2="14" y2="10"></line>
                                <line x1="3" y1="21" x2="10" y2="14"></line>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="image-viewport" id="imageViewport">
                    <img id="imageElement" class="image-preview" src="" alt="Pratinjau Gambar">
                </div>
            </div>

            <!-- 4. VIDEO VIEWER -->
            <div id="videoViewerContainer" class="viewer-container">
                <div class="video-viewport">
                    <div class="video-wrapper">
                        <video id="videoElement" class="video-player" controls playsinline preload="metadata">
                            Browser Anda tidak mendukung pemutar video.
                        </video>
                    </div>
                </div>
            </div>

            <!-- 5. AUDIO VIEWER -->
            <div id="audioViewerContainer" class="viewer-container">
                <div class="audio-viewport">
                    <div class="audio-card">
                        <div id="audioDiscWrap" class="audio-disc-wrap">
                            <div class="audio-disc-center">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </div>
                        </div>

                        <div class="audio-info">
                            <div id="audioTrackTitle" class="audio-title">Audio Track</div>
                            <div id="audioTrackPath" class="audio-path">Path audio</div>
                        </div>

                        <audio id="audioElement" class="audio-player" controls preload="metadata">
                            Browser Anda tidak mendukung pemutar audio.
                        </audio>
                    </div>
                </div>
            </div>

            <!-- 6. CODE & TEXT VIEWER (Highlight.js) -->
            <div id="codeViewerContainer" class="viewer-container">
                <div class="viewer-toolbar">
                    <div class="toolbar-group">
                        <span id="codeLanguageBadge" class="toolbar-btn" style="background: rgba(16, 185, 129, 0.15); color: #10B981; border-color: rgba(16, 185, 129, 0.3);">TEXT</span>
                        <span id="codeLineCount" class="toolbar-text">0 baris</span>
                        <span class="toolbar-text">•</span>
                        <span id="codeFileSize" class="toolbar-text">0 B</span>
                    </div>

                    <div class="toolbar-group">
                        <button id="btnWrapLines" class="toolbar-btn" title="Toggle Wrap Lines">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="3" y1="6" x2="21" y2="6"></line>
                                <path d="M3 12h15a3 3 0 1 1 0 6h-4"></path>
                                <polyline points="16 16 14 18 16 20"></polyline>
                            </svg>
                            <span>Wrap</span>
                        </button>
                        <button id="btnCopyCode" class="toolbar-btn" title="Salin Isi Kode">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                            </svg>
                            <span>Salin</span>
                        </button>
                    </div>
                </div>
                <div class="code-viewport">
                    <div class="code-container">
                        <div id="codeLineNumbers" class="code-line-numbers">1</div>
                        <div id="codeContentWrap" class="code-content-wrap">
                            <pre><code id="codeBlock"></code></pre>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 7. UNSUPPORTED FILE VIEW -->
            <div id="unsupportedViewerContainer" class="viewer-container">
                <div class="unsupported-viewport">
                    <div class="unsupported-card">
                        <div class="unsupported-icon">
                            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                        </div>
                        <h3 class="unsupported-title">Format File Tidak Didukung</h3>
                        <p class="unsupported-msg">Preview tidak/belum didukung, klik tombol di bawah ini untuk mengunduh</p>
                        
                        <div class="unsupported-meta">
                            <div>Nama: <strong id="unsupName">-</strong></div>
                            <div>Ukuran: <strong id="unsupSize">-</strong></div>
                            <div>Ekstensi: <strong id="unsupExt">-</strong></div>
                        </div>

                        <a id="btnUnsupportedDownload" class="btn-big-download" href="#" download>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <polyline points="7 10 12 15 17 10"></polyline>
                                <line x1="12" y1="15" x2="12" y2="3"></line>
                            </svg>
                            <span>Unduh File Sekarang</span>
                        </a>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <!-- Toast Notification -->
    <div id="appToast" class="toast-notification">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2.5">
            <polyline points="20 6 9 17 4 12"></polyline>
        </svg>
        <span id="toastMessage">Pesan</span>
    </div>

    <!-- ==========================================
         JAVASCRIPT LOGIC
         ========================================== -->
    <script>
        // Configure PDF.js Worker
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        // Preloaded Initial Tree & Stats from PHP
        window.INITIAL_TREE = <?php echo json_encode($initialTree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        window.INITIAL_STATS = <?php echo json_encode($initialStats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

        // Application State
        const state = {
            tree: window.INITIAL_TREE || [],
            stats: window.INITIAL_STATS || {},
            activeFile: null,
            isSidepaneOpen: window.innerWidth > 768,
            isMobileDrawerOpen: false,
            searchQuery: '',
            expandedFolders: new Set(JSON.parse(localStorage.getItem('midnight_expanded_folders') || '[]')),
            
            // PDF State
            pdf: {
                doc: null,
                currentPage: 1,
                totalPages: 1,
                scale: 1.0,
                rotation: 0,
                renderTask: null
            },

            // Image State
            img: {
                scale: 1.0,
                rotation: 0
            }
        };

        // DOM Elements
        const el = {
            // Header
            btnBurgerToggle: document.getElementById('btnBurgerToggle'),
            headerBreadcrumb: document.getElementById('headerBreadcrumb'),
            breadcrumbText: document.getElementById('breadcrumbText'),
            btnHeaderDownload: document.getElementById('btnHeaderDownload'),
            btnHeaderClose: document.getElementById('btnHeaderClose'),

            // Sidepane
            appSidepane: document.getElementById('appSidepane'),
            drawerBackdrop: document.getElementById('drawerBackdrop'),
            treeContainer: document.getElementById('treeContainer'),
            sidepaneSearch: document.getElementById('sidepaneSearch'),
            searchClearBtn: document.getElementById('searchClearBtn'),
            btnExpandAll: document.getElementById('btnExpandAll'),
            btnCollapseAll: document.getElementById('btnCollapseAll'),
            btnRefreshTree: document.getElementById('btnRefreshTree'),
            footerFileStats: document.getElementById('footerFileStats'),
            footerSizeStats: document.getElementById('footerSizeStats'),

            // Empty State
            emptyStateView: document.getElementById('emptyStateView'),
            cardPdfCount: document.getElementById('cardPdfCount'),
            cardMediaCount: document.getElementById('cardMediaCount'),
            cardCodeCount: document.getElementById('cardCodeCount'),

            // Viewers
            pdfViewerContainer: document.getElementById('pdfViewerContainer'),
            pdfViewport: document.getElementById('pdfViewport'),
            pdfCanvasContainer: document.getElementById('pdfCanvasContainer'),
            pdfLoadingOverlay: document.getElementById('pdfLoadingOverlay'),
            pdfPageInput: document.getElementById('pdfPageInput'),
            pdfTotalPages: document.getElementById('pdfTotalPages'),
            pdfPrevPage: document.getElementById('pdfPrevPage'),
            pdfNextPage: document.getElementById('pdfNextPage'),
            pdfZoomSelect: document.getElementById('pdfZoomSelect'),
            pdfZoomIn: document.getElementById('pdfZoomIn'),
            pdfZoomOut: document.getElementById('pdfZoomOut'),
            pdfRotateCw: document.getElementById('pdfRotateCw'),
            pdfFullscreen: document.getElementById('pdfFullscreen'),

            imageViewerContainer: document.getElementById('imageViewerContainer'),
            imageElement: document.getElementById('imageElement'),
            imageMetaDimensions: document.getElementById('imageMetaDimensions'),
            btnImgZoomIn: document.getElementById('btnImgZoomIn'),
            btnImgZoomReset: document.getElementById('btnImgZoomReset'),
            btnImgZoomOut: document.getElementById('btnImgZoomOut'),
            btnImgFullscreen: document.getElementById('btnImgFullscreen'),

            videoViewerContainer: document.getElementById('videoViewerContainer'),
            videoElement: document.getElementById('videoElement'),

            audioViewerContainer: document.getElementById('audioViewerContainer'),
            audioElement: document.getElementById('audioElement'),
            audioDiscWrap: document.getElementById('audioDiscWrap'),
            audioTrackTitle: document.getElementById('audioTrackTitle'),
            audioTrackPath: document.getElementById('audioTrackPath'),

            codeViewerContainer: document.getElementById('codeViewerContainer'),
            codeBlock: document.getElementById('codeBlock'),
            codeLineNumbers: document.getElementById('codeLineNumbers'),
            codeContentWrap: document.getElementById('codeContentWrap'),
            codeLanguageBadge: document.getElementById('codeLanguageBadge'),
            codeLineCount: document.getElementById('codeLineCount'),
            codeFileSize: document.getElementById('codeFileSize'),
            btnWrapLines: document.getElementById('btnWrapLines'),
            btnCopyCode: document.getElementById('btnCopyCode'),

            unsupportedViewerContainer: document.getElementById('unsupportedViewerContainer'),
            unsupName: document.getElementById('unsupName'),
            unsupSize: document.getElementById('unsupSize'),
            unsupExt: document.getElementById('unsupExt'),
            btnUnsupportedDownload: document.getElementById('btnUnsupportedDownload'),

            appToast: document.getElementById('appToast'),
            toastMessage: document.getElementById('toastMessage')
        };

        // -------------------------------------------------------------
        // SVG ICONS GENERATOR
        // -------------------------------------------------------------
        function getCategoryIconSvg(category) {
            switch (category) {
                case 'pdf':
                    return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>`;
                case 'image':
                    return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>`;
                case 'video':
                    return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"></rect><line x1="7" y1="2" x2="7" y2="22"></line><line x1="17" y1="2" x2="17" y2="22"></line><line x1="2" y1="12" x2="22" y2="12"></line><line x1="2" y1="7" x2="7" y2="7"></line><line x1="2" y1="17" x2="7" y2="17"></line><line x1="17" y1="17" x2="22" y2="17"></line><line x1="17" y1="7" x2="22" y2="7"></line></svg>`;
                case 'audio':
                    return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"></path><circle cx="6" cy="18" r="3"></circle><circle cx="18" cy="16" r="3"></circle></svg>`;
                case 'code':
                    return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>`;
                default:
                    return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline></svg>`;
            }
        }

        // -------------------------------------------------------------
        // TREE VIEW RENDERING & FILTERING
        // -------------------------------------------------------------
        function saveExpandedFolders() {
            localStorage.setItem('midnight_expanded_folders', JSON.stringify(Array.from(state.expandedFolders)));
        }

        function filterTree(items, query) {
            if (!query) return items;
            const q = query.toLowerCase();

            function matches(item) {
                if (item.type === 'file') {
                    return item.name.toLowerCase().includes(q) || item.path.toLowerCase().includes(q);
                }
                if (item.type === 'folder') {
                    if (item.name.toLowerCase().includes(q) || item.path.toLowerCase().includes(q)) {
                        return true;
                    }
                    if (item.children && item.children.length > 0) {
                        return item.children.some(matches);
                    }
                }
                return false;
            }

            function cloneFilter(nodeList) {
                const res = [];
                for (const node of nodeList) {
                    if (node.type === 'file') {
                        if (matches(node)) res.push(node);
                    } else if (node.type === 'folder') {
                        if (node.name.toLowerCase().includes(q) || node.path.toLowerCase().includes(q)) {
                            res.push(node); // Keep entire folder if name matches
                        } else {
                            const filteredChildren = cloneFilter(node.children || []);
                            if (filteredChildren.length > 0) {
                                res.push({ ...node, children: filteredChildren });
                            }
                        }
                    }
                }
                return res;
            }

            return cloneFilter(items);
        }

        function renderTree() {
            const items = filterTree(state.tree, state.searchQuery);
            el.treeContainer.innerHTML = '';

            if (!items || items.length === 0) {
                el.treeContainer.innerHTML = `
                    <div class="tree-empty-notice">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <span>${state.searchQuery ? 'Tidak ada file yang cocok dengan pencarian' : 'Folder MidnightPDF-data kosong'}</span>
                    </div>
                `;
                return;
            }

            const fragment = document.createDocumentFragment();
            const group = document.createElement('div');
            group.className = 'tree-node-group';

            function buildNode(item) {
                const wrap = document.createElement('div');
                wrap.className = 'tree-node-wrap';

                if (item.type === 'folder') {
                    const isExpanded = state.searchQuery ? true : state.expandedFolders.has(item.path);
                    const folderRow = document.createElement('div');
                    folderRow.className = 'tree-item';
                    folderRow.setAttribute('data-path', item.path);

                    folderRow.innerHTML = `
                        <span class="tree-chevron ${isExpanded ? 'expanded' : ''}">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </span>
                        <span class="tree-icon icon-folder">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"></path></svg>
                        </span>
                        <span class="tree-label" title="${item.name}">${escapeHtml(item.name)}</span>
                        <span class="tree-badge">${item.children ? item.children.length : 0}</span>
                    `;

                    const childrenContainer = document.createElement('div');
                    childrenContainer.className = `tree-children ${isExpanded ? '' : 'hidden'}`;

                    if (item.children && item.children.length > 0) {
                        for (const child of item.children) {
                            childrenContainer.appendChild(buildNode(child));
                        }
                    }

                    folderRow.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const currentlyExpanded = !childrenContainer.classList.contains('hidden');
                        if (currentlyExpanded) {
                            childrenContainer.classList.add('hidden');
                            folderRow.querySelector('.tree-chevron').classList.remove('expanded');
                            state.expandedFolders.delete(item.path);
                        } else {
                            childrenContainer.classList.remove('hidden');
                            folderRow.querySelector('.tree-chevron').classList.add('expanded');
                            state.expandedFolders.add(item.path);
                        }
                        saveExpandedFolders();
                    });

                    wrap.appendChild(folderRow);
                    wrap.appendChild(childrenContainer);
                } else {
                    // File item
                    const fileRow = document.createElement('div');
                    const isActive = state.activeFile && state.activeFile.path === item.path;
                    fileRow.className = `tree-item ${isActive ? 'active' : ''}`;
                    fileRow.setAttribute('data-path', item.path);

                    fileRow.innerHTML = `
                        <span class="tree-chevron empty-space"></span>
                        <span class="tree-icon icon-${item.category}">
                            ${getCategoryIconSvg(item.category)}
                        </span>
                        <span class="tree-label" title="${item.name}">${escapeHtml(item.name)}</span>
                        <span class="tree-badge">${item.sizeFormatted}</span>
                    `;

                    fileRow.addEventListener('click', () => {
                        openFile(item);
                        // If mobile, close the drawer
                        if (window.innerWidth <= 768) {
                            closeMobileDrawer();
                        }
                    });

                    wrap.appendChild(fileRow);
                }

                return wrap;
            }

            for (const item of items) {
                group.appendChild(buildNode(item));
            }

            fragment.appendChild(group);
            el.treeContainer.appendChild(fragment);
        }

        function updateSidepaneStats() {
            const stats = state.stats;
            el.footerFileStats.textContent = `${stats.totalFiles || 0} File • ${stats.totalFolders || 0} Folder`;
            el.footerSizeStats.textContent = stats.totalSizeFormatted || '0 B';

            el.cardPdfCount.textContent = stats.pdfCount || 0;
            el.cardMediaCount.textContent = (stats.imageCount || 0) + (stats.audioCount || 0) + (stats.videoCount || 0);
            el.cardCodeCount.textContent = stats.codeCount || 0;
        }

        async function refreshTreeData() {
            try {
                const res = await fetch('MidnightPDF.php?action=tree');
                const data = await res.json();
                if (data.success) {
                    state.tree = data.tree;
                    state.stats = data.stats;
                    renderTree();
                    updateSidepaneStats();
                    showToast('Daftar file berhasil diperbarui');
                }
            } catch (err) {
                console.error('Error refreshing tree:', err);
                showToast('Gagal memuat daftar file');
            }
        }

        // -------------------------------------------------------------
        // FILE ROUTING & VIEWERS
        // -------------------------------------------------------------
        function findFileByPath(items, targetPath) {
            for (const item of items) {
                if (item.type === 'file' && item.path === targetPath) {
                    return item;
                }
                if (item.type === 'folder' && item.children) {
                    const found = findFileByPath(item.children, targetPath);
                    if (found) return found;
                }
            }
            return null;
        }

        function hideAllViewers() {
            el.emptyStateView.style.display = 'none';
            el.pdfViewerContainer.classList.remove('active');
            el.imageViewerContainer.classList.remove('active');
            el.videoViewerContainer.classList.remove('active');
            el.audioViewerContainer.classList.remove('active');
            el.codeViewerContainer.classList.remove('active');
            el.unsupportedViewerContainer.classList.remove('active');

            // Pause media if playing
            if (!el.videoElement.paused) el.videoElement.pause();
            if (!el.audioElement.paused) el.audioElement.pause();
            el.audioDiscWrap.classList.remove('spinning');
        }

        function closeActiveFile() {
            state.activeFile = null;
            hideAllViewers();
            el.emptyStateView.style.display = 'flex';

            // Reset Header
            el.headerBreadcrumb.classList.add('empty');
            el.breadcrumbText.textContent = 'Pilih file di sidepane untuk memulai';
            el.btnHeaderDownload.classList.add('disabled');
            el.btnHeaderDownload.removeAttribute('href');
            el.btnHeaderClose.classList.add('disabled');

            // Remove active highlight in sidepane
            const activeItems = el.treeContainer.querySelectorAll('.tree-item.active');
            activeItems.forEach(item => item.classList.remove('active'));

            // Clear URL hash without reload
            history.pushState("", document.title, window.location.pathname + window.location.search);
        }

        function openFile(file) {
            state.activeFile = file;

            // Update Breadcrumb & Header
            updateHeaderForFile(file);

            // Update Active class in tree
            const allItems = el.treeContainer.querySelectorAll('.tree-item');
            allItems.forEach(item => {
                if (item.getAttribute('data-path') === file.path) {
                    item.classList.add('active');
                } else {
                    item.classList.remove('active');
                }
            });

            // Update URL hash for deep linking
            window.location.hash = `file=${encodeURIComponent(file.path)}`;

            hideAllViewers();

            // Render according to category
            switch (file.category) {
                case 'pdf':
                    loadPdfViewer(file);
                    break;
                case 'image':
                    loadImageViewer(file);
                    break;
                case 'video':
                    loadVideoViewer(file);
                    break;
                case 'audio':
                    loadAudioViewer(file);
                    break;
                case 'code':
                    loadCodeViewer(file);
                    break;
                default:
                    loadUnsupportedViewer(file);
                    break;
            }
        }

        function updateHeaderForFile(file) {
            el.headerBreadcrumb.classList.remove('empty');
            
            // Format path segments
            const segments = file.path.split('/');
            let breadcrumbHtml = `<span class="path-icon">${getCategoryIconSvg(file.category)}</span>`;

            segments.forEach((seg, idx) => {
                if (idx > 0) {
                    breadcrumbHtml += `<span class="path-separator">/</span>`;
                }
                const isLast = idx === segments.length - 1;
                breadcrumbHtml += `<span class="path-segment ${isLast ? 'active-file' : ''}">${escapeHtml(seg)}</span>`;
            });

            el.headerBreadcrumb.innerHTML = breadcrumbHtml;

            // Enable Header buttons
            const rawUrl = `MidnightPDF.php?action=raw&file=${encodeURIComponent(file.path)}`;
            const downloadUrl = `MidnightPDF.php?action=download&file=${encodeURIComponent(file.path)}`;

            el.btnHeaderDownload.classList.remove('disabled');
            el.btnHeaderDownload.setAttribute('href', downloadUrl);
            el.btnHeaderDownload.setAttribute('download', file.name);

            el.btnHeaderClose.classList.remove('disabled');
        }

        // -------------------------------------------------------------
        // PDF VIEWER LOGIC (PDF.js)
        // -------------------------------------------------------------
        async function loadPdfViewer(file) {
            el.pdfViewerContainer.classList.add('active');
            el.pdfLoadingOverlay.classList.add('active');
            el.pdfCanvasContainer.innerHTML = '';

            const url = `MidnightPDF.php?action=raw&file=${encodeURIComponent(file.path)}`;

            try {
                if (state.pdf.doc) {
                    state.pdf.doc.destroy();
                }

                const loadingTask = pdfjsLib.getDocument(url);
                state.pdf.doc = await loadingTask.promise;
                state.pdf.totalPages = state.pdf.doc.numPages;
                state.pdf.currentPage = 1;
                state.pdf.rotation = 0;

                // Smart initial zoom: fit-width on mobile screens, 100% on desktop
                if (window.innerWidth <= 768) {
                    state.pdf.scaleMode = 'fit-width';
                    state.pdf.scale = 1.0;
                    el.pdfZoomSelect.value = 'fit-width';
                } else {
                    state.pdf.scaleMode = null;
                    state.pdf.scale = 1.0;
                    el.pdfZoomSelect.value = '1.0';
                }

                el.pdfTotalPages.textContent = state.pdf.totalPages;
                el.pdfPageInput.max = state.pdf.totalPages;
                el.pdfPageInput.value = 1;

                await renderPdfPage(1);
            } catch (err) {
                console.error('Error loading PDF:', err);
                el.pdfCanvasContainer.innerHTML = `
                    <div style="padding: 40px; text-align: center; color: var(--accent-pdf);">
                        <p><strong>Gagal memuat dokumen PDF.</strong></p>
                        <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 8px;">File mungkin rusak atau tidak dapat diakses.</p>
                        <a href="MidnightPDF.php?action=download&file=${encodeURIComponent(file.path)}" class="btn-download-action" style="margin-top: 14px; display: inline-flex;">Unduh File PDF</a>
                    </div>
                `;
            } finally {
                el.pdfLoadingOverlay.classList.remove('active');
            }
        }

        async function renderPdfPage(pageNum) {
            if (!state.pdf.doc) return;
            if (pageNum < 1 || pageNum > state.pdf.totalPages) return;

            state.pdf.currentPage = pageNum;
            el.pdfPageInput.value = pageNum;

            if (state.pdf.renderTask) {
                state.pdf.renderTask.cancel();
            }

            try {
                const page = await state.pdf.doc.getPage(pageNum);
                
                // Calculate scale accurately based on scroll viewport dimensions
                let computedScale = state.pdf.scale;
                const vpW = el.pdfViewport ? el.pdfViewport.clientWidth : el.pdfViewerContainer.clientWidth;
                const vpH = el.pdfViewport ? el.pdfViewport.clientHeight : el.pdfViewerContainer.clientHeight;
                const containerWidth = Math.max(160, vpW - 32);
                const containerHeight = Math.max(160, vpH - 48);

                if (state.pdf.scaleMode === 'fit-width') {
                    const unscaledViewport = page.getViewport({ scale: 1.0, rotation: state.pdf.rotation });
                    computedScale = Math.max(0.1, containerWidth / unscaledViewport.width);
                } else if (state.pdf.scaleMode === 'fit-page') {
                    const unscaledViewport = page.getViewport({ scale: 1.0, rotation: state.pdf.rotation });
                    const scaleW = containerWidth / unscaledViewport.width;
                    const scaleH = containerHeight / unscaledViewport.height;
                    computedScale = Math.max(0.1, Math.min(scaleW, scaleH));
                }

                const viewport = page.getViewport({ scale: computedScale, rotation: state.pdf.rotation });

                // Prepare canvas with high DPI support
                let canvas = el.pdfCanvasContainer.querySelector('canvas');
                if (!canvas) {
                    canvas = document.createElement('canvas');
                    canvas.className = 'pdf-page-canvas';
                    el.pdfCanvasContainer.appendChild(canvas);
                }

                const outputScale = window.devicePixelRatio || 1;
                canvas.width = Math.floor(viewport.width * outputScale);
                canvas.height = Math.floor(viewport.height * outputScale);
                canvas.style.width = Math.floor(viewport.width) + "px";
                canvas.style.height = Math.floor(viewport.height) + "px";

                const ctx = canvas.getContext('2d');
                const transform = outputScale !== 1 ? [outputScale, 0, 0, outputScale, 0, 0] : null;

                const renderContext = {
                    canvasContext: ctx,
                    transform: transform,
                    viewport: viewport
                };

                state.pdf.renderTask = page.render(renderContext);
                await state.pdf.renderTask.promise;
            } catch (err) {
                if (err.name !== 'RenderingCancelledException') {
                    console.error('Error rendering page:', err);
                }
            }
        }

        // PDF Controls Listeners
        el.pdfPrevPage.addEventListener('click', () => {
            if (state.pdf.currentPage > 1) {
                renderPdfPage(state.pdf.currentPage - 1);
            }
        });

        el.pdfNextPage.addEventListener('click', () => {
            if (state.pdf.currentPage < state.pdf.totalPages) {
                renderPdfPage(state.pdf.currentPage + 1);
            }
        });

        el.pdfPageInput.addEventListener('change', () => {
            let val = parseInt(el.pdfPageInput.value, 10);
            if (isNaN(val)) val = 1;
            val = Math.max(1, Math.min(state.pdf.totalPages, val));
            renderPdfPage(val);
        });

        el.pdfZoomIn.addEventListener('click', () => {
            state.pdf.scaleMode = null;
            state.pdf.scale = Math.min(3.0, state.pdf.scale + 0.25);
            el.pdfZoomSelect.value = state.pdf.scale.toFixed(1);
            renderPdfPage(state.pdf.currentPage);
        });

        el.pdfZoomOut.addEventListener('click', () => {
            state.pdf.scaleMode = null;
            state.pdf.scale = Math.max(0.5, state.pdf.scale - 0.25);
            el.pdfZoomSelect.value = state.pdf.scale.toFixed(1);
            renderPdfPage(state.pdf.currentPage);
        });

        el.pdfZoomSelect.addEventListener('change', (e) => {
            const val = e.target.value;
            if (val === 'fit-width' || val === 'fit-page') {
                state.pdf.scaleMode = val;
            } else {
                state.pdf.scaleMode = null;
                state.pdf.scale = parseFloat(val);
            }
            renderPdfPage(state.pdf.currentPage);
        });

        el.pdfRotateCw.addEventListener('click', () => {
            state.pdf.rotation = (state.pdf.rotation + 90) % 360;
            renderPdfPage(state.pdf.currentPage);
        });

        el.pdfFullscreen.addEventListener('click', () => {
            if (!document.fullscreenElement) {
                el.pdfViewerContainer.requestFullscreen().catch(err => console.log(err));
            } else {
                document.exitFullscreen();
            }
        });

        // -------------------------------------------------------------
        // IMAGE VIEWER LOGIC
        // -------------------------------------------------------------
        function loadImageViewer(file) {
            el.imageViewerContainer.classList.add('active');
            state.img.scale = 1.0;
            el.imageElement.style.transform = `scale(1.0)`;
            el.imageMetaDimensions.textContent = `Memuat ${file.name}...`;

            const url = `MidnightPDF.php?action=raw&file=${encodeURIComponent(file.path)}`;
            el.imageElement.src = url;

            el.imageElement.onload = () => {
                const w = el.imageElement.naturalWidth;
                const h = el.imageElement.naturalHeight;
                el.imageMetaDimensions.textContent = `${w} × ${h} px • ${file.sizeFormatted}`;
            };

            el.imageElement.onerror = () => {
                el.imageMetaDimensions.textContent = `Gagal memuat gambar`;
            };
        }

        el.btnImgZoomIn.addEventListener('click', () => {
            state.img.scale = Math.min(4.0, state.img.scale + 0.25);
            el.imageElement.style.transform = `scale(${state.img.scale})`;
        });

        el.btnImgZoomOut.addEventListener('click', () => {
            state.img.scale = Math.max(0.25, state.img.scale - 0.25);
            el.imageElement.style.transform = `scale(${state.img.scale})`;
        });

        el.btnImgZoomReset.addEventListener('click', () => {
            state.img.scale = 1.0;
            el.imageElement.style.transform = `scale(1.0)`;
        });

        el.btnImgFullscreen.addEventListener('click', () => {
            if (!document.fullscreenElement) {
                el.imageViewerContainer.requestFullscreen().catch(err => console.log(err));
            } else {
                document.exitFullscreen();
            }
        });

        // -------------------------------------------------------------
        // VIDEO VIEWER LOGIC
        // -------------------------------------------------------------
        function loadVideoViewer(file) {
            el.videoViewerContainer.classList.add('active');
            const url = `MidnightPDF.php?action=raw&file=${encodeURIComponent(file.path)}`;
            el.videoElement.src = url;
            el.videoElement.load();
            el.videoElement.play().catch(() => {});
        }

        // -------------------------------------------------------------
        // AUDIO VIEWER LOGIC
        // -------------------------------------------------------------
        function loadAudioViewer(file) {
            el.audioViewerContainer.classList.add('active');
            el.audioTrackTitle.textContent = file.name;
            el.audioTrackPath.textContent = file.path;

            const url = `MidnightPDF.php?action=raw&file=${encodeURIComponent(file.path)}`;
            el.audioElement.src = url;
            el.audioElement.load();
            el.audioElement.play().catch(() => {});
        }

        el.audioElement.addEventListener('play', () => {
            el.audioDiscWrap.classList.add('spinning');
        });

        el.audioElement.addEventListener('pause', () => {
            el.audioDiscWrap.classList.remove('spinning');
        });

        el.audioElement.addEventListener('ended', () => {
            el.audioDiscWrap.classList.remove('spinning');
        });

        // -------------------------------------------------------------
        // CODE & TEXT VIEWER LOGIC (Highlight.js)
        // -------------------------------------------------------------
        async function loadCodeViewer(file) {
            el.codeViewerContainer.classList.add('active');
            el.codeLanguageBadge.textContent = (file.ext || 'TEXT').toUpperCase();
            el.codeFileSize.textContent = file.sizeFormatted;
            el.codeBlock.textContent = 'Memuat isi file...';
            el.codeLineNumbers.innerHTML = '1';

            try {
                const res = await fetch(`MidnightPDF.php?action=text&file=${encodeURIComponent(file.path)}`);
                const data = await res.json();
                if (data.success) {
                    el.codeBlock.textContent = data.content;
                    // Format line numbers accurately based on content lines
                    const contentLines = data.content.split(/\r\n|\r|\n/);
                    const lineCount = contentLines.length;
                    el.codeLineCount.textContent = `${lineCount} baris`;
                    
                    let nums = '';
                    for (let i = 1; i <= lineCount; i++) {
                        nums += i + (i < lineCount ? '\n' : '');
                    }
                    el.codeLineNumbers.textContent = nums;

                    // Syntax highlighting
                    hljs.highlightElement(el.codeBlock);
                } else {
                    el.codeBlock.textContent = `Error: ${data.error || 'Gagal memuat teks'}`;
                }
            } catch (err) {
                console.error('Error loading text:', err);
                el.codeBlock.textContent = 'Gagal mengambil konten teks file.';
            }
        }

        el.btnWrapLines.addEventListener('click', () => {
            el.codeContentWrap.classList.toggle('wrap-lines');
            el.btnWrapLines.classList.toggle('active');
        });

        el.btnCopyCode.addEventListener('click', () => {
            const text = el.codeBlock.textContent;
            navigator.clipboard.writeText(text).then(() => {
                showToast('Kode berhasil disalin ke clipboard');
            }).catch(() => {
                showToast('Gagal menyalin kode');
            });
        });

        // -------------------------------------------------------------
        // UNSUPPORTED VIEWER LOGIC
        // -------------------------------------------------------------
        function loadUnsupportedViewer(file) {
            el.unsupportedViewerContainer.classList.add('active');
            el.unsupName.textContent = file.name;
            el.unsupSize.textContent = file.sizeFormatted;
            el.unsupExt.textContent = (file.ext || 'UNKNOWN').toUpperCase();

            const downloadUrl = `MidnightPDF.php?action=download&file=${encodeURIComponent(file.path)}`;
            el.btnUnsupportedDownload.setAttribute('href', downloadUrl);
            el.btnUnsupportedDownload.setAttribute('download', file.name);
        }

        // -------------------------------------------------------------
        // HEADER & NAVIGATION CONTROLS
        // -------------------------------------------------------------
        function toggleSidepane() {
            if (window.innerWidth <= 768) {
                // Mobile behavior: drawer
                state.isMobileDrawerOpen = !state.isMobileDrawerOpen;
                if (state.isMobileDrawerOpen) {
                    openMobileDrawer();
                } else {
                    closeMobileDrawer();
                }
            } else {
                // Desktop behavior: collapse/expand
                state.isSidepaneOpen = !state.isSidepaneOpen;
                if (state.isSidepaneOpen) {
                    el.appSidepane.classList.remove('collapsed');
                    el.btnBurgerToggle.classList.remove('active');
                } else {
                    el.appSidepane.classList.add('collapsed');
                    el.btnBurgerToggle.classList.add('active');
                }
            }
        }

        function openMobileDrawer() {
            el.appSidepane.classList.add('mobile-open');
            el.drawerBackdrop.classList.add('active');
            el.btnBurgerToggle.classList.add('active');
            state.isMobileDrawerOpen = true;
        }

        function closeMobileDrawer() {
            el.appSidepane.classList.remove('mobile-open');
            el.drawerBackdrop.classList.remove('active');
            el.btnBurgerToggle.classList.remove('active');
            state.isMobileDrawerOpen = false;
        }

        el.btnBurgerToggle.addEventListener('click', toggleSidepane);
        el.drawerBackdrop.addEventListener('click', closeMobileDrawer);
        el.btnHeaderClose.addEventListener('click', closeActiveFile);

        // Expand / Collapse all folders
        function collectAllFolderPaths(items, set) {
            for (const item of items) {
                if (item.type === 'folder') {
                    set.add(item.path);
                    if (item.children) {
                        collectAllFolderPaths(item.children, set);
                    }
                }
            }
        }

        el.btnExpandAll.addEventListener('click', () => {
            collectAllFolderPaths(state.tree, state.expandedFolders);
            saveExpandedFolders();
            renderTree();
            showToast('Semua folder dibuka');
        });

        el.btnCollapseAll.addEventListener('click', () => {
            state.expandedFolders.clear();
            saveExpandedFolders();
            renderTree();
            showToast('Semua folder ditutup');
        });

        el.btnRefreshTree.addEventListener('click', refreshTreeData);

        // Search Filter
        el.sidepaneSearch.addEventListener('input', (e) => {
            state.searchQuery = e.target.value.trim();
            el.searchClearBtn.style.display = state.searchQuery ? 'block' : 'none';
            renderTree();
        });

        el.searchClearBtn.addEventListener('click', () => {
            el.sidepaneSearch.value = '';
            state.searchQuery = '';
            el.searchClearBtn.style.display = 'none';
            renderTree();
        });

        // Toast feedback
        let toastTimeout = null;
        function showToast(msg) {
            el.toastMessage.textContent = msg;
            el.appToast.classList.add('active');
            if (toastTimeout) clearTimeout(toastTimeout);
            toastTimeout = setTimeout(() => {
                el.appToast.classList.remove('active');
            }, 3000);
        }

        // HTML Escape helper
        function escapeHtml(str) {
            if (!str) return '';
            return str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Keyboard Shortcuts
        window.addEventListener('keydown', (e) => {
            // Ctrl/Cmd + B: toggle sidepane
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') {
                e.preventDefault();
                toggleSidepane();
            }
            // Escape: Close active file or drawer
            if (e.key === 'Escape') {
                if (state.isMobileDrawerOpen) {
                    closeMobileDrawer();
                } else if (state.activeFile) {
                    closeActiveFile();
                }
            }
            // [ and ]: PDF page nav
            if (state.activeFile && state.activeFile.category === 'pdf') {
                if (e.key === '[') {
                    if (state.pdf.currentPage > 1) renderPdfPage(state.pdf.currentPage - 1);
                } else if (e.key === ']') {
                    if (state.pdf.currentPage < state.pdf.totalPages) renderPdfPage(state.pdf.currentPage + 1);
                }
            }
        });

        // Handle URL Hash navigation
        function checkUrlHash() {
            const hash = window.location.hash;
            if (hash.startsWith('#file=')) {
                const filePath = decodeURIComponent(hash.substring(6));
                const file = findFileByPath(state.tree, filePath);
                if (file) {
                    openFile(file);
                }
            }
        }

        window.addEventListener('hashchange', checkUrlHash);

        // Window resize adjustments
        let resizeTimer = null;
        window.addEventListener('resize', () => {
            if (window.innerWidth > 768 && state.isMobileDrawerOpen) {
                closeMobileDrawer();
            }
            if (state.activeFile && state.activeFile.category === 'pdf' && (state.pdf.scaleMode === 'fit-width' || state.pdf.scaleMode === 'fit-page')) {
                if (resizeTimer) clearTimeout(resizeTimer);
                resizeTimer = setTimeout(() => {
                    renderPdfPage(state.pdf.currentPage);
                }, 150);
            }
        });

        // Initialize App
        document.addEventListener('DOMContentLoaded', () => {
            renderTree();
            updateSidepaneStats();
            checkUrlHash();
        });
    </script>
</body>
</html>
