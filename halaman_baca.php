<?php
session_start();
require_once __DIR__ . '/config/database.php';
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function getCoverPath($path): ?string
{
    $path = trim((string) $path);
    if (
        $path !== ''
        && (
            strpos($path, 'uploads/covers/') === 0
            || strpos($path, 'uploads/chapters/') === 0
            || strpos($path, 'uploads/images/') === 0
        )
        && strpos($path, '..') === false
        && is_file(__DIR__ . '/' . $path)
    ) {
        return $path;
    }
    return null;
}

function renderStoryContent($content): string
{
    $content = (string) $content;

    if (strpos($content, '<') === false || !preg_match('/<\/?(?:p|div|br|span|strong|b|em|i|u|s|strike|mark|font)\b/i', $content)) {
        return nl2br(e($content), false);
    }

    if (!class_exists('DOMDocument')) {
        return nl2br(e(strip_tags($content)), false);
    }

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->loadHTML('<?xml encoding="UTF-8"><div id="story-safe-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $dom->getElementById('story-safe-root');
    if (!$root) {
        return nl2br(e(strip_tags($content)), false);
    }

    $allowedTags = ['div', 'p', 'br', 'span', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'mark', 'font'];
    $dangerousTags = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'button', 'textarea', 'select', 'video', 'audio'];

    $cleanNode = function ($node) use (&$cleanNode, $allowedTags, $dangerousTags, $dom) {
        if ($node->nodeType === XML_COMMENT_NODE) {
            $node->parentNode?->removeChild($node);
            return;
        }

        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);

            if (in_array($tag, $dangerousTags, true)) {
                $node->parentNode?->removeChild($node);
                return;
            }

            foreach (iterator_to_array($node->childNodes) as $child) {
                $cleanNode($child);
            }

            if (!in_array($tag, $allowedTags, true)) {
                $parent = $node->parentNode;
                if ($parent) {
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                }
                return;
            }

            $safeStyle = [];
            if ($tag === 'font' && $node->hasAttribute('color')) {
                $fontColor = trim($node->getAttribute('color'));
                if (preg_match('/^(#[0-9a-f]{3,8}|[a-z]{1,20}|rgba?\(\s*[\d.%\s,]+\))$/i', $fontColor)) {
                    $safeStyle['color'] = $fontColor;
                }
            }
            if ($node->hasAttribute('style')) {
                $style = $node->getAttribute('style');
                foreach (explode(';', $style) as $declaration) {
                    $parts = explode(':', $declaration, 2);
                    if (count($parts) !== 2) continue;
                    $property = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);

                    if (in_array($property, ['color', 'background-color'], true)
                        && preg_match('/^(#[0-9a-f]{3,8}|[a-z]{1,20}|rgba?\(\s*[\d.%\s,]+\))$/i', $value)) {
                        $safeStyle[$property] = $value;
                        continue;
                    }
                    if ($property === 'text-indent' && preg_match('/^(?:0|2em)$/i', $value)) {
                        $safeStyle[$property] = strtolower($value);
                        continue;
                    }
                    if ($property === 'margin' && preg_match('/^(?:0\s+0\s+(?:\.9em|1em)|1\.25em\s+0\s+\.6em)$/i', $value)) {
                        $safeStyle[$property] = $value;
                        continue;
                    }
                    if ($property === 'text-align' && in_array(strtolower($value), ['left', 'right', 'center', 'justify'], true)) {
                        $safeStyle[$property] = strtolower($value);
                        continue;
                    }
                    if ($property === 'font-weight' && in_array(strtolower($value), ['400', '500', '600', '700', 'bold', 'normal'], true)) {
                        $safeStyle[$property] = strtolower($value);
                        continue;
                    }
                }
            }

            while ($node->attributes->length > 0) {
                $node->removeAttributeNode($node->attributes->item(0));
            }

            if ($safeStyle) {
                $styleText = [];
                foreach ($safeStyle as $property => $value) {
                    $styleText[] = $property . ': ' . $value;
                }
                $node->setAttribute('style', implode('; ', $styleText));
            }
            return;
        }

        if ($node->nodeType !== XML_TEXT_NODE) {
            $node->parentNode?->removeChild($node);
        }
    };

    foreach (iterator_to_array($root->childNodes) as $child) {
        $cleanNode($child);
    }

    foreach ($root->getElementsByTagName('p') as $paragraph) {
        foreach ($paragraph->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $child->nodeValue = preg_replace('/^(?:\x{2003})+/u', '', $child->nodeValue) ?? $child->nodeValue;
                break;
            }
            if ($child instanceof DOMElement) {
                break;
            }
        }
    }

    $html = '';
    foreach ($root->childNodes as $child) {
        $html .= $dom->saveHTML($child);
    }
    return $html;
}

$novelId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$novelId || $novelId < 1) {
    header('Location: pembaca.php');
    exit;
}
$stmt = $pdo->prepare('SELECT * FROM novels WHERE id = ?');
$stmt->execute([$novelId]);
$novel = $stmt->fetch();
if (!$novel) {
    http_response_code(404);
    exit('Novel tidak ditemukan.');
}

$stmt = $pdo->prepare(
    'SELECT id, chapter_number, title, content, cover_path, word_count
     FROM chapters
     WHERE novel_id = ?
     ORDER BY chapter_number ASC'
);
$stmt->execute([$novelId]);
$semuaBab = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$semuaBab) {
    exit('Novel ini belum memiliki episode.');
}

$daftarBab = $semuaBab;
$totalBab = count($semuaBab);

$nomorBab = filter_input(INPUT_GET, 'chapter', FILTER_VALIDATE_INT);
if (!$nomorBab || $nomorBab < 1) {
    $nomorBab = (int) $semuaBab[0]['chapter_number'];
}

$bab = null;
$posisiBab = 1;
$babSebelumnya = null;
$babBerikutnya = null;
foreach ($semuaBab as $index => $item) {
    if ((int) $item['chapter_number'] === (int) $nomorBab) {
        $bab = $item;
        $posisiBab = $index + 1;
        if ($index > 0) {
            $babSebelumnya = $semuaBab[$index - 1];
        }
        if ($index < $totalBab - 1) {
            $babBerikutnya = $semuaBab[$index + 1];
        }
        break;
    }
}
if (!$bab) {
    $bab = $semuaBab[0];
    $nomorBab = (int) $bab['chapter_number'];
    $posisiBab = 1;
    $babBerikutnya = $totalBab > 1 ? $semuaBab[1] : null;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($novel['title']) ?></title>
<link rel="stylesheet" href="assets/theme-system.css">
<script src="assets/theme.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Crimson+Pro:ital,wght@0,400;0,600;1,400&family=IBM+Plex+Sans:wght@400;600&family=IBM+Plex+Serif:wght@400;600&family=Inter:wght@400;500;600;700&family=Literata:ital,wght@0,400;0,600;1,400&family=Lora:ital,wght@0,400;0,600;1,400&family=Merriweather:wght@400;700&family=Playfair+Display:wght@600;700&family=Source+Serif+4:ital,wght@0,400;0,600;1,400&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
<style>
/* Proteksi CSS: Blokir seleksi teks & interaksi drag */
* { 
    box-sizing: border-box; 
    margin: 0; 
    padding: 0; 
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
    -webkit-touch-callout: none;
}
img {
    -webkit-user-drag: none;
    user-drag: none;
    pointer-events: none;
}
@media print {
    html, body { display: none !important; }
}

html, body { height: 100%; }
html { scroll-behavior: smooth; }
body {
    font-family: 'Inter', system-ui, sans-serif;
    line-height: 1.5;
    background: var(--bg);
    color: var(--text);
    -webkit-font-smoothing: antialiased;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    overflow: hidden;
}
.header {
    position: relative; z-index: 100; flex-shrink: 0;
    background: var(--header-bg); color: var(--text);
    border-bottom: 1px solid var(--border); box-shadow: var(--shadow);
}
.header-inner {
    max-width: 1100px; margin: 0 auto; padding: 14px 20px;
    display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
}
.brand { display: flex; align-items: center; gap: 12px; text-decoration: none; color: inherit; flex-shrink: 0; }
.logo-wrap { width: 44px; height: 44px; }
.logo-wrap img { width: 100%; height: 100%; object-fit: contain; border-radius: 50%; filter: drop-shadow(0 0 6px rgba(0,180,255,.35)); }
.book-heading { flex: 1; min-width: 0; }
.book-title {
    font-family: 'Playfair Display', Georgia, serif; font-size: 18px; font-weight: 700;
    line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text);
}
.episode-label { margin-top: 3px; color: var(--muted); font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.header-controls { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.episode-select {
    min-width: 160px; max-width: 220px; padding: 9px 12px;
    border: 1px solid var(--border); border-radius: 8px;
    background: var(--surface-2); color: var(--text); font-size: 13px; font-family: inherit;
}

.container {
    flex: 1 1 auto;
    min-height: 0;
    max-width: 1100px;
    width: 100%;
    margin: 0 auto;
    padding: 0;
    display: flex;
    flex-direction: column;
    position: relative;
}

.book-spread {
    position: relative;
    display: flex;
    flex: 1 1 auto;
    min-height: 0;
    background: var(--reader-bg);
    border-left: 1px solid var(--border);
    border-right: 1px solid var(--border);
    border-top: none;
    border-bottom: none;
    border-radius: 0;
    box-shadow: none;
    overflow: hidden;
}
.book-spread::before {
    content: ''; position: absolute; top: 0; bottom: 0; left: 50%; width: 22px;
    transform: translateX(-50%);
    background: linear-gradient(90deg, rgba(0,0,0,.07) 0%, rgba(0,0,0,.02) 35%, transparent 48%, transparent 52%, rgba(0,0,0,.02) 65%, rgba(0,0,0,.07) 100%);
    pointer-events: none; z-index: 4;
}
.book-page {
    flex: 1 1 50%; min-width: 0; min-height: 0;
    display: flex; flex-direction: column;
    padding: 16px 22px 18px; background: var(--reader-bg);
}
.book-page.left { border-right: 1px solid var(--border); box-shadow: inset -6px 0 14px -10px rgba(0,0,0,.12); }
.book-page.right { box-shadow: inset 6px 0 14px -10px rgba(0,0,0,.12); }
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid var(--border);
    font-size: 11px; color: var(--muted); letter-spacing: .04em; text-transform: uppercase; flex-shrink: 0;
}
.page-header .page-title { font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 72%; }
.page-header .page-num { font-variant-numeric: tabular-nums; flex-shrink: 0; }
.page-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow: hidden;
    max-width: 100%;
}
.page-body .ep-title {
    font-family: var(--reader-heading-font, 'Playfair Display', Georgia, serif);
    font-size: calc(var(--reader-font-size, 16px) * 1.25);
    font-weight: 700;
    line-height: 1.3; margin: 0 0 12px; letter-spacing: -.3px; color: var(--text); text-align: center;
}
.page-body .ep-cover { margin: 0 0 12px; text-align: center; }
.page-body .ep-cover img {
    display: block; width: 100%; max-width: 140px; margin: 0 auto;
    aspect-ratio: 4/5; object-fit: cover; border-radius: 4px;
    box-shadow: 0 4px 16px rgba(0,0,0,.2); border: 1px solid var(--border);
}
.page-body p, .page-body .story {
    font-family: var(--reader-font, Georgia, 'Times New Roman', serif);
    font-size: var(--reader-font-size, 16px);
    line-height: var(--reader-line-height, 1.55);
    color: var(--reader-text);
    overflow-wrap: anywhere;
    max-width: 70ch;
    margin-left: auto;
    margin-right: auto;
}
.page-body p {
    text-indent: 2em;
    margin: 0 auto .65em;
    text-align: justify;
    max-width: 70ch;
}
.page-body h1, .page-body h2, .page-body h3, .page-body h4 {
    text-indent: 0; margin: .9em auto .4em; line-height: 1.35;
    font-family: var(--reader-heading-font, Georgia, serif);
    color: var(--reader-text); max-width: 70ch;
    font-size: calc(var(--reader-font-size, 16px) * 1.1);
}

#measure-box {
    position: absolute; left: -99999px; top: 0; visibility: hidden; pointer-events: none;
}
#measure-box .ep-title {
    font-family: var(--reader-heading-font, 'Playfair Display', Georgia, serif);
    font-size: calc(var(--reader-font-size, 16px) * 1.25);
    font-weight: 700; line-height: 1.3; margin: 0 0 12px; text-align: center;
}
#measure-box .ep-cover { margin: 0 0 12px; text-align: center; }
#measure-box .ep-cover img { max-width: 140px; width: 100%; aspect-ratio: 4/5; display: block; margin: 0 auto; }
#measure-box p, #measure-box .story {
    font-family: var(--reader-font, Georgia, 'Times New Roman', serif);
    font-size: var(--reader-font-size, 16px);
    line-height: var(--reader-line-height, 1.55);
    overflow-wrap: anywhere;
    max-width: 70ch;
}
#measure-box p { text-indent: 2em; margin: 0 0 .65em; text-align: justify; max-width: 70ch; }

.font-controls {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.font-controls .font-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 8px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface-2);
    color: var(--text);
    font-weight: 700;
    cursor: pointer;
    font-family: inherit;
    line-height: 1;
}
.font-controls .font-btn:hover {
    background: var(--surface);
    border-color: var(--accent);
}
.font-controls .font-btn:disabled {
    opacity: .4;
    cursor: not-allowed;
}
.font-controls .font-label {
    font-size: 12px;
    color: var(--muted);
    min-width: 34px;
    text-align: center;
    font-variant-numeric: tabular-nums;
}

.navigation {
    position: relative;
    z-index: 100;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin: 0;
    padding: 10px 16px;
    background: var(--header-bg, var(--surface));
    border-top: 1px solid var(--border);
    box-shadow: none;
    flex-wrap: wrap;
}
.nav-inner {
    max-width: 1100px;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
}
.nav-left, .nav-center, .nav-right {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.nav-center { flex: 1; justify-content: center; }
.nav-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 10px 14px; border-radius: 8px; background: var(--surface-2); color: var(--text);
    text-decoration: none; font-size: 13.5px; font-weight: 600; border: 1px solid var(--border);
    cursor: pointer; font-family: inherit; white-space: nowrap;
}
.nav-btn:hover:not(:disabled) { background: var(--surface); border-color: var(--accent); }
.nav-btn:disabled, .nav-btn.disabled {
    color: var(--muted); pointer-events: none; opacity: .45; cursor: not-allowed;
}
.progress-text { color: var(--muted); font-size: 13.5px; font-weight: 500; }
.footer { display: none; }

@media (max-width: 900px) {
    .book-spread { flex-direction: column; min-height: 0; }
    .book-spread::before { display: none; }
    .book-page.left { display: none !important; }
    .book-page.right { box-shadow: none; flex: 1 1 100%; }
    .book-page { padding: 16px; }
    .page-body p, .page-body .story { max-width: 70ch; }
}
@media (max-width: 700px) {
    .header-inner { padding: 12px 14px; gap: 10px; }
    .book-title { font-size: 16px; }
    .episode-label { font-size: 11px; }
    .header-controls { width: 100%; }
    .episode-select { flex: 1; max-width: none; min-width: 0; }
    .container { margin: 0; padding: 0; }
    .navigation { padding: 10px 12px; }
    .nav-inner { justify-content: center; }
    .nav-left, .nav-center, .nav-right { justify-content: center; }
    .nav-btn { padding: 9px 11px; font-size: 12.5px; }
}
</style>
</head>
<body>
<header class="header">
    <div class="header-inner">
        <a href="pembaca.php" class="brand" title="Kembali ke perpustakaan">
            <div class="logo-wrap"><img src="logo.png" alt="SAB Logo"></div>
        </a>
        <div class="book-heading">
            <h1 class="book-title"><?= e($novel['title']) ?></h1>
            <div class="episode-label" id="header-episode-label">
                Episode <?= $posisiBab ?> dari <?= $totalBab ?> · <?= e($bab['title']) ?>
            </div>
        </div>
        <div class="header-controls">
            <div id="theme-switcher-mount"></div>
            <select class="episode-select" id="episode-select" aria-label="Lompat ke episode">
                <?php foreach ($daftarBab as $item): ?>
                    <option value="<?= (int) $item['chapter_number'] ?>"
                        <?= (int) $item['chapter_number'] === (int) $nomorBab ? 'selected' : '' ?>
                    ><?= e($item['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</header>

<main class="container">
    <div id="source-content" hidden>
        <?php foreach ($semuaBab as $ep): ?>
            <div class="episode-block" data-chapter="<?= (int) $ep['chapter_number'] ?>" data-title="<?= e($ep['title']) ?>">
                <?php
                $cover = getCoverPath($ep['cover_path'] ?? '');
                if ($cover):
                ?>
                    <div class="ep-cover"><img src="<?= e($cover) ?>" alt="Cover" loading="lazy"></div>
                <?php endif; ?>
                <div class="ep-title"><?= e($ep['title']) ?></div>
                <div class="story"><?= renderStoryContent($ep['content'] ?? '') ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div id="measure-box" aria-hidden="true"></div>

    <article class="book-spread" id="book-spread">
        <div class="book-page left">
            <div class="page-header">
                <span class="page-title"><?= e($novel['title']) ?></span>
                <span class="page-num" id="num-left">1</span>
            </div>
            <div class="page-body" id="body-left"></div>
        </div>
        <div class="book-page right">
            <div class="page-header">
                <span class="page-title" id="right-header-title"><?= e($novel['title']) ?></span>
                <span class="page-num" id="num-right">2</span>
            </div>
            <div class="page-body" id="body-right"></div>
        </div>
    </article>

    <nav class="navigation" aria-label="Kontrol baca">
        <div class="nav-inner">
            <div class="nav-left">
                <button type="button" class="nav-btn" id="btn-prev" disabled title="Halaman sebelumnya">← Sebelumnya</button>
            </div>
            <div class="nav-center">
                <div class="font-controls" title="Ukuran font">
                    <button type="button" class="font-btn" id="font-down" aria-label="Perkecil font">A−</button>
                    <span class="font-label" id="font-size-label">16</span>
                    <button type="button" class="font-btn" id="font-up" aria-label="Perbesar font">A+</button>
                </div>
                <span class="progress-text" id="page-info">Hal. 1–2</span>
            </div>
            <div class="nav-right">
                <button type="button" class="nav-btn" id="btn-next" title="Halaman berikutnya">Berikutnya →</button>
            </div>
        </div>
    </nav>
</main>
<footer class="footer">SAB Novel Library &copy; <?= date('Y') ?></footer>

<script>
(function () {
    const novelId = <?= json_encode((string) $novelId) ?>;
    const novelTitle = <?= json_encode($novel['title'], JSON_UNESCAPED_UNICODE) ?>;
    const startChapter = <?= (int) $nomorBab ?>;
    const totalEpisodes = <?= (int) $totalBab ?>;
    const source = document.getElementById('source-content');
    const measureBox = document.getElementById('measure-box');
    const bodyLeft = document.getElementById('body-left');
    const bodyRight = document.getElementById('body-right');
    const numLeft = document.getElementById('num-left');
    const numRight = document.getElementById('num-right');
    const pageInfo = document.getElementById('page-info');
    const btnPrev = document.getElementById('btn-prev');
    const btnNext = document.getElementById('btn-next');
    const bookSpread = document.getElementById('book-spread');
    const headerLabel = document.getElementById('header-episode-label');
    const episodeSelect = document.getElementById('episode-select');
    const fontDown = document.getElementById('font-down');
    const fontUp = document.getElementById('font-up');
    const fontLabel = document.getElementById('font-size-label');

    const FONT_MIN = 13;
    const FONT_MAX = 24;
    const FONT_STEP = 1;
    const FONT_KEY = 'sab_reader_font_size';

    function getFontSize() {
        const n = parseInt(localStorage.getItem(FONT_KEY) || '16', 10);
        return isNaN(n) ? 16 : Math.min(FONT_MAX, Math.max(FONT_MIN, n));
    }

    function applyFontSize(px, rebuild) {
        px = Math.min(FONT_MAX, Math.max(FONT_MIN, px));
        document.documentElement.style.setProperty('--reader-font-size', px + 'px');
        document.documentElement.style.setProperty('--reader-line-height', '1.55');
        if (measureBox) {
            measureBox.style.fontSize = px + 'px';
        }
        if (fontLabel) fontLabel.textContent = String(px);
        if (fontDown) fontDown.disabled = px <= FONT_MIN;
        if (fontUp) fontUp.disabled = px >= FONT_MAX;
        try { localStorage.setItem(FONT_KEY, String(px)); } catch (e) {}
        if (rebuild && typeof buildPages === 'function') {
            const ch = pageChapters[pageIndex];
            buildPages();
            if (ch && episodeStartsPage[ch] !== undefined) {
                pageIndex = episodeStartsPage[ch];
                render();
            }
        }
    }

    applyFontSize(getFontSize(), false);

    if (fontDown) {
        fontDown.addEventListener('click', function () {
            applyFontSize(getFontSize() - FONT_STEP, true);
        });
    }
    if (fontUp) {
        fontUp.addEventListener('click', function () {
            applyFontSize(getFontSize() + FONT_STEP, true);
        });
    }

    function isMobile() {
        return window.matchMedia('(max-width: 900px)').matches;
    }

    function collectBlocks() {
        const blocks = [];
        const episodeStarts = {};
        const episodes = source.querySelectorAll('.episode-block');
        episodes.forEach(function (ep) {
            const ch = parseInt(ep.getAttribute('data-chapter'), 10);
            const title = ep.getAttribute('data-title') || '';
            episodeStarts[ch] = blocks.length;

            const cover = ep.querySelector('.ep-cover');
            if (cover) {
                blocks.push({ html: cover.outerHTML, chapter: ch, title: title, kind: 'cover' });
            }
            const t = ep.querySelector('.ep-title');
            if (t) {
                blocks.push({ html: '<div class="ep-title">' + t.innerHTML + '</div>', chapter: ch, title: title, kind: 'title' });
            }
            const story = ep.querySelector('.story');
            if (story) {
                if (story.children.length) {
                    Array.from(story.children).forEach(function (el) {
                        blocks.push({ html: el.outerHTML, chapter: ch, title: title, kind: 'para' });
                    });
                } else if (story.innerHTML.trim()) {
                    blocks.push({ html: '<div class="story">' + story.innerHTML + '</div>', chapter: ch, title: title, kind: 'para' });
                }
            }
        });
        return { blocks: blocks, episodeStarts: episodeStarts };
    }

    function isHangingPage(html) {
        measureBox.innerHTML = html;
        const hasBody = !!measureBox.querySelector('p, .story, h1, h2, h3, h4');
        const hasLead = !!measureBox.querySelector('.ep-title, .ep-cover');
        return hasLead && !hasBody;
    }

    let pages = [];
    let pageChapters = [];
    let pageTitles = [];
    let pageIndex = 0;
    let episodeStartsPage = {};

    function buildPages() {
        const collected = collectBlocks();
        const blocks = collected.blocks;
        const mobile = isMobile();
        const sampleBody = mobile ? bodyRight : bodyLeft;
        const w = Math.max(sampleBody.clientWidth || 0, 240);
        const actualH = sampleBody.clientHeight || Math.round(getFontSize() * 1.55 * 28);
        const maxH = Math.max(120, actualH - 6);

        measureBox.style.width = w + 'px';
        pages = [];
        pageChapters = [];
        pageTitles = [];
        episodeStartsPage = {};

        let html = '';
        let curCh = null;
        let curTitle = '';
        let prevChapter = null;
        measureBox.innerHTML = '';

        function flush() {
            if (!html.trim()) return;
            pages.push(html);
            pageChapters.push(curCh);
            pageTitles.push(curTitle);
            html = '';
            measureBox.innerHTML = '';
        }

        let i = 0;
        while (i < blocks.length) {
            const b = blocks[i];

            if (b.kind === 'cover' || b.kind === 'title') {
                if (html.trim()) flush();

                let lead = '';
                const epCh = b.chapter;
                const epTitle = b.title;
                while (i < blocks.length && (blocks[i].kind === 'cover' || blocks[i].kind === 'title') && blocks[i].chapter === epCh) {
                    lead += blocks[i].html;
                    i++;
                }

                let body = '';
                while (i < blocks.length && blocks[i].chapter === epCh && blocks[i].kind === 'para') {
                    const trial = lead + body + blocks[i].html;
                    measureBox.innerHTML = trial;
                    if (body !== '' && measureBox.scrollHeight > maxH + 2) {
                        break;
                    }
                    if (body === '' && measureBox.scrollHeight > maxH + 2) {
                        body += blocks[i].html;
                        i++;
                        break;
                    }
                    body += blocks[i].html;
                    i++;
                    measureBox.innerHTML = lead + body;
                    if (measureBox.scrollHeight > maxH * 0.92) break;
                }

                html = lead + body;
                curCh = epCh;
                curTitle = epTitle;
                prevChapter = epCh;
                measureBox.innerHTML = html;
                flush();
                continue;
            }

            if (prevChapter !== null && b.chapter !== prevChapter) {
                if (html.trim()) flush();
            }

            const trial = html + b.html;
            measureBox.innerHTML = trial;
            if (html !== '' && measureBox.scrollHeight > maxH + 2) {
                flush();
                html = b.html;
                curCh = b.chapter;
                curTitle = b.title;
                prevChapter = b.chapter;
                measureBox.innerHTML = html;
                if (measureBox.scrollHeight > maxH + 2) {
                    flush();
                    html = '';
                }
            } else {
                html = trial;
                curCh = b.chapter;
                curTitle = b.title;
                prevChapter = b.chapter;
            }
            i++;
        }
        flush();

        const cleanP = [];
        const cleanC = [];
        const cleanT = [];
        for (let p = 0; p < pages.length; p++) {
            if (isHangingPage(pages[p]) && p + 1 < pages.length) {
                pages[p + 1] = pages[p] + pages[p + 1];
            } else {
                cleanP.push(pages[p]);
                cleanC.push(pageChapters[p]);
                cleanT.push(pageTitles[p]);
            }
        }
        pages = cleanP;
        pageChapters = cleanC;
        pageTitles = cleanT;

        if (!pages.length) {
            pages = ['<p></p>'];
            pageChapters = [startChapter];
            pageTitles = [''];
        }

        for (let p = 0; p < pageChapters.length; p++) {
            const ch = pageChapters[p];
            if (episodeStartsPage[ch] === undefined) {
                episodeStartsPage[ch] = p;
            }
        }

        if (episodeStartsPage[startChapter] !== undefined) {
            pageIndex = episodeStartsPage[startChapter];
        } else {
            pageIndex = 0;
        }

        if (!mobile && pageIndex % 2 === 1) pageIndex -= 1;

        const maxStart = mobile ? pages.length - 1 : Math.max(0, pages.length - 2 - ((pages.length - 2) % 2));
        if (pageIndex > maxStart) pageIndex = Math.max(0, maxStart - (maxStart % 2));
        render();
    }

    function updateEpisodeUI(ch, title) {
        if (!ch) return;
        headerLabel.textContent = title ? ('Episode · ' + title) : '';
        if (episodeSelect) {
            episodeSelect.value = String(ch);
        }
        try { localStorage.setItem('satria_last_chapter_' + novelId, String(ch)); } catch (e) {}
    }

    function render() {
        const mobile = isMobile();
        const total = pages.length;

        if (mobile) {
            bodyRight.innerHTML = pages[pageIndex] || '';
            numRight.textContent = (pageIndex + 1) + ' / ' + total;
            bodyLeft.innerHTML = '';
            numLeft.textContent = '';
            pageInfo.textContent = 'Hal. ' + (pageIndex + 1) + ' / ' + total;
            btnPrev.disabled = pageIndex <= 0;
            btnNext.disabled = pageIndex >= total - 1;
            updateEpisodeUI(pageChapters[pageIndex], pageTitles[pageIndex]);
        } else {
            if (pageIndex % 2 === 1) pageIndex -= 1;
            const left = pageIndex;
            const right = pageIndex + 1;
            bodyLeft.innerHTML = pages[left] || '';
            bodyRight.innerHTML = right < total ? (pages[right] || '') : '';
            numLeft.textContent = String(left + 1);
            numRight.textContent = right < total ? String(right + 1) : '';
            if (right < total) {
                pageInfo.textContent = 'Hal. ' + (left + 1) + '–' + (right + 1) + ' / ' + total;
            } else {
                pageInfo.textContent = 'Hal. ' + (left + 1) + ' / ' + total;
            }
            btnPrev.disabled = pageIndex <= 0;
            btnNext.disabled = left + 2 >= total;
            updateEpisodeUI(pageChapters[left], pageTitles[left]);
        }
    }

    function scrollToTop() {
        try {
            window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
            document.documentElement.scrollTop = 0;
            document.body.scrollTop = 0;
            if (bookSpread) {
                bookSpread.scrollTop = 0;
                const bodies = bookSpread.querySelectorAll('.page-body');
                bodies.forEach(function (el) { el.scrollTop = 0; });
            }
            const main = document.querySelector('main.container');
            if (main) main.scrollTop = 0;
        } catch (e) {}
    }

    function goPrev() {
        const mobile = isMobile();
        let moved = false;
        if (mobile) {
            if (pageIndex > 0) { pageIndex--; moved = true; }
        } else {
            if (pageIndex >= 2) { pageIndex -= 2; moved = true; }
        }
        if (moved) { render(); scrollToTop(); }
    }
    function goNext() {
        const mobile = isMobile();
        let moved = false;
        if (mobile) {
            if (pageIndex < pages.length - 1) { pageIndex++; moved = true; }
        } else {
            if (pageIndex + 2 < pages.length) { pageIndex += 2; moved = true; }
        }
        if (moved) { render(); scrollToTop(); }
    }

    btnPrev.addEventListener('click', goPrev);
    btnNext.addEventListener('click', goNext);
    document.addEventListener('keydown', function (e) {
        if (['INPUT', 'TEXTAREA', 'SELECT'].indexOf(e.target.tagName) >= 0) return;
        if (e.key === 'ArrowLeft') { e.preventDefault(); goPrev(); }
        if (e.key === 'ArrowRight') { e.preventDefault(); goNext(); }
    });

    let touchX = null;
    bookSpread.addEventListener('touchstart', function (e) {
        touchX = e.changedTouches[0].screenX;
    }, { passive: true });
    bookSpread.addEventListener('touchend', function (e) {
        if (touchX === null) return;
        const dx = e.changedTouches[0].screenX - touchX;
        if (Math.abs(dx) > 50) { if (dx < 0) goNext(); else goPrev(); }
        touchX = null;
    }, { passive: true });

    function jumpToChapter(ch) {
        ch = parseInt(ch, 10);
        if (episodeStartsPage[ch] !== undefined) {
            pageIndex = episodeStartsPage[ch];
            if (!isMobile() && pageIndex % 2 === 1) pageIndex -= 1;
            render();
            scrollToTop();
        }
    }
    if (episodeSelect) {
        episodeSelect.addEventListener('change', function () {
            jumpToChapter(this.value);
        });
    }

    let resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            const ch = pageChapters[pageIndex];
            buildPages();
            if (ch && episodeStartsPage[ch] !== undefined) {
                pageIndex = episodeStartsPage[ch];
                render();
            }
        }, 200);
    });

    function start() {
        requestAnimationFrame(function () {
            requestAnimationFrame(buildPages);
        });
    }
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(start);
    } else {
        setTimeout(start, 120);
    }
})();

// Proteksi Tambahan: Blokir Klik Kanan & Shortcut Keyboard (Copy, Inspect, Save, Print)
document.addEventListener('contextmenu', function(e) {
    e.preventDefault();
});

document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && ['c','C','u','U','s','S','p','P','a','A'].includes(e.key)) {
        e.preventDefault();
    }
    if (e.ctrlKey && e.shiftKey && ['I','i','J','j','C','c'].includes(e.key)) {
        e.preventDefault();
    }
    if (e.key === 'F12') {
        e.preventDefault();
    }
    if (e.key === 'PrintScreen' || e.keyCode === 44) {
        navigator.clipboard.writeText('');
    }
});

window.addEventListener('keyup', function(e) {
    if (e.key === 'PrintScreen' || e.keyCode === 44) {
        navigator.clipboard.writeText('');
    }
});
</script>
</body>
</html>