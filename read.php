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

/**
 * Menampilkan isi episode yang disimpan oleh editor rich-text dengan aman.
 */
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
    header('Location: index.php');
    exit;
}
$stmt = $pdo->prepare('SELECT * FROM novels WHERE id = ?');
$stmt->execute([$novelId]);
$novel = $stmt->fetch();
if (!$novel) {
    http_response_code(404);
    exit('Novel tidak ditemukan.');
}

// Ambil seluruh daftar bab dari database
$stmt = $pdo->prepare(
    'SELECT id, chapter_number, title, word_count
     FROM chapters
     WHERE novel_id = ?'
);
$stmt->execute([$novelId]);
$daftarBabMentah = $stmt->fetchAll();
if (!$daftarBabMentah) {
    exit('Novel ini belum memiliki episode.');
}

/**
 * PENGURUTAN CERDAS EPUB:
 * 1. Front Matter (chapter_number = 0, judul mengandung pengantar/sinopsis/hak cipta) di atas.
 * 2. Main Text (chapter_number > 0) diurutkan berdasarkan chapter_number ASC.
 * 3. Back Matter (chapter_number = 0, judul mengandung ucapan terima kasih/penulis/promosi) di bawah.
 */
usort($daftarBabMentah, static function($a, $b) {
    $numA = (int) $a['chapter_number'];
    $numB = (int) $b['chapter_number'];

    // Jika keduanya episode utama (> 0), urutkan berdasarkan nomor bab
    if ($numA > 0 && $numB > 0) {
        return $numA <=> $numB;
    }

    // Jika A utama, B bukan -> A di bawah Front Matter, tapi di atas Back Matter
    // Kita kategorikan berdasarkan tipe judul
    $tA = mb_strtolower($a['title'], 'UTF-8');
    $tB = mb_strtolower($b['title'], 'UTF-8');

    $isFront = static function($title) {
        return str_contains($title, 'kata pengantar') || str_contains($title, 'pendahuluan') || str_contains($title, 'sinopsis') || str_contains($title, 'hak cipta') || str_contains($title, 'copyright') || str_contains($title, 'dedikasi') || str_contains($title, 'tentang buku');
    };

    $isBack = static function($title) {
        return str_contains($title, 'ucapan terima kasih') || str_contains($title, 'tentang penulis') || str_contains($title, 'promosi') || str_contains($title, 'epilog') || str_contains($title, 'kesimpulan') || str_contains($title, 'acknowledg');
    };

    $rank = static function($title) use ($isFront, $isBack) {
        if ($isFront($title)) return 1;
        if ($isBack($title)) return 3;
        return 2; // Main / Lainnya
    };

    $rA = ($numA > 0) ? 2 : $rank($tA);
    $rB = ($numB > 0) ? 2 : $rank($tB);

    if ($rA !== $rB) {
        return $rA <=> $rB;
    }

    // Jika rank sama, urutkan berdasarkan ID asli
    return (int)$a['id'] <=> (int)$b['id'];
});

$daftarBab = $daftarBabMentah;

$chapterId = filter_input(INPUT_GET, 'chapter_id', FILTER_VALIDATE_INT);
$nomorBab = filter_input(INPUT_GET, 'chapter', FILTER_VALIDATE_INT);

$bab = null;
if ($chapterId) {
    $stmt = $pdo->prepare('SELECT * FROM chapters WHERE novel_id = ? AND id = ?');
    $stmt->execute([$novelId, $chapterId]);
    $bab = $stmt->fetch();
}

if (!$bab && $nomorBab) {
    $stmt = $pdo->prepare('SELECT * FROM chapters WHERE novel_id = ? AND chapter_number = ?');
    $stmt->execute([$novelId, $nomorBab]);
    $bab = $stmt->fetch();
}

// Jika tidak ditentukan di URL, ambil bab pertama dari daftar terurut
if (!$bab && !empty($daftarBab)) {
    $firstId = $daftarBab[0]['id'];
    $stmt = $pdo->prepare('SELECT * FROM chapters WHERE novel_id = ? AND id = ?');
    $stmt->execute([$novelId, $firstId]);
    $bab = $stmt->fetch();
}

if (!$bab) {
    exit('Bab atau bagian novel tidak ditemukan.');
}

$totalBab = count($daftarBab);
$posisiBab = 1;
$babSebelumnya = null;
$babBerikutnya = null;

foreach ($daftarBab as $index => $item) {
    if ((int) $item['id'] === (int) $bab['id']) {
        $posisiBab = $index + 1;
        if ($index > 0) {
            $babSebelumnya = $daftarBab[$index - 1];
        }
        if ($index < $totalBab - 1) {
            $babBerikutnya = $daftarBab[$index + 1];
        }
        break;
    }
}

if (empty($_SESSION['delete_chapter_token'])) {
    $_SESSION['delete_chapter_token'] = bin2hex(random_bytes(32));
}

$episodeCover = getCoverPath($bab['cover_path'] ?? $bab['image_path'] ?? '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($novel['title']) ?> - <?= e($bab['title']) ?></title>
<link rel="stylesheet" href="assets/theme-system.css">
<script src="assets/theme.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,600;0,700;1,400&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Serif:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@400;500;600;700&family=Literata:ital,opsz,wght@0,7..72,400;0,7..72,600;0,7..72,700;1,7..72,400&family=Lora:ital,wght@0,400;0,600;0,700;1,400&family=Merriweather:ital,wght@0,400;0,700;1,400&family=Playfair+Display:wght@600;700&family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;0,8..60,700;1,8..60,400&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
    font-family: 'Inter', system-ui, sans-serif;
    line-height: 1.5;
    background: var(--bg);
    color: var(--text);
    -webkit-font-smoothing: antialiased;
}
.header {
    position: sticky;
    top: 0;
    z-index: 100;
    background: var(--header-bg);
    color: var(--text);
    border-bottom: 1px solid var(--border);
    box-shadow: var(--shadow);
}
.header-inner {
    max-width: 1100px;
    margin: 0 auto;
    padding: 14px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
    color: inherit;
    flex-shrink: 0;
}
.logo-wrap {
    width: 44px;
    height: 44px;
}
.logo-wrap img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    border-radius: 50%;
    filter: drop-shadow(0 0 6px rgba(0,180,255,.35));
}
.book-heading {
    flex: 1;
    min-width: 0;
}
.book-title {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 18px;
    font-weight: 700;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: var(--text);
}
.episode-label {
    margin-top: 3px;
    color: var(--muted);
    font-size: 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.header-controls {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.episode-select {
    min-width: 160px;
    max-width: 220px;
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface-2);
    color: var(--text);
    font-size: 13px;
    font-family: inherit;
}
.header-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 38px;
    height: 38px;
    padding: 0 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface-2);
    color: var(--text);
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s;
    font-family: inherit;
}
.header-btn:hover {
    background: var(--surface);
    border-color: var(--accent);
}
.header-btn.add {
    background: #166534;
    border-color: #15803d;
    color: #fff;
}
.header-btn.add:hover { background: #15803d; }
.download-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 8px;
    background: var(--accent);
    color: #fff;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
}
.download-btn:hover {
    background: var(--accent-hover);
    color: #fff;
}
.container {
    max-width: 820px;
    margin: 28px auto;
    padding: 0 18px 50px;
}
.reader {
    background: var(--reader-bg);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 36px 40px;
    box-shadow: var(--shadow);
}
.chapter-title {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 28px;
    font-weight: 700;
    line-height: 1.35;
    margin-bottom: 24px;
    padding-bottom: 18px;
    border-bottom: 1px solid var(--border);
    letter-spacing: -.4px;
    color: var(--text);
}
.chapter-cover {
    margin: 0 0 28px;
    text-align: center;
}
.chapter-cover img {
    display: block;
    width: 100%;
    max-width: 320px;
    margin: 0 auto;
    aspect-ratio: 4 / 5;
    object-fit: cover;
    border-radius: 12px;
    box-shadow: 0 8px 28px rgba(0,0,0,.28);
    border: 1px solid var(--border);
}
.chapter-content {
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 18.5px;
    line-height: 1.65;
    color: var(--reader-text);
    white-space: normal;
    overflow-wrap: anywhere;
}
.chapter-content p {
    text-indent: 2em;
    margin: 0 0 .9em;
    line-height: 1.8;
    text-align: justify;
}
.chapter-content h1,
.chapter-content h2,
.chapter-content h3,
.chapter-content h4 {
    text-indent: 0;
    margin: 1.25em 0 .6em;
    line-height: 1.4;
}
.navigation {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin: 22px 0;
    padding: 14px 16px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow);
    flex-wrap: wrap;
}
.nav-left,
.nav-center,
.nav-right {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.nav-center {
    flex: 1;
    justify-content: center;
}
.nav-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px 14px;
    border-radius: 8px;
    background: var(--surface-2);
    color: var(--text);
    text-decoration: none;
    font-size: 13.5px;
    font-weight: 600;
    transition: background .15s;
    border: 1px solid var(--border);
    cursor: pointer;
    font-family: inherit;
    white-space: nowrap;
}
.nav-btn:hover {
    background: var(--surface);
    border-color: var(--accent);
}
.nav-btn.disabled {
    background: var(--surface-2);
    color: var(--muted);
    pointer-events: none;
    opacity: .6;
}
.nav-btn.copy {
    background: #1d4ed8;
    border-color: #1d4ed8;
    color: #fff;
}
.nav-btn.copy:hover {
    background: #1e40af;
    border-color: #1e40af;
}
.nav-btn.edit-episode {
    background: rgba(234, 179, 8, .12);
    border-color: rgba(234, 179, 8, .3);
    color: #eab308;
}
.nav-btn.edit-episode:hover {
    background: rgba(234, 179, 8, .2);
}
.nav-btn.delete {
    background: rgba(248,113,113,.12);
    border-color: rgba(248,113,113,.25);
    color: #f87171;
}
.nav-btn.delete:hover {
    background: rgba(248,113,113,.2);
}
.progress-text {
    color: var(--muted);
    font-size: 13.5px;
    font-weight: 500;
    white-space: nowrap;
}
#copy-status {
    font-size: 12.5px;
    color: #4ade80;
    font-weight: 500;
    width: 100%;
    text-align: center;
    margin-top: 4px;
}
.chapter-list {
    margin-bottom: 30px;
    padding: 18px 22px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow);
}
.chapter-list summary {
    cursor: pointer;
    font-weight: 600;
    font-size: 14.5px;
    color: var(--text);
}
.chapter-list ol {
    padding-left: 22px;
    margin-top: 12px;
}
.chapter-list li { padding: 5px 0; }
.chapter-list a {
    color: var(--muted);
    text-decoration: none;
    font-size: 14px;
}
.chapter-list a:hover { color: var(--accent); }
.chapter-list a.active {
    color: var(--text);
    font-weight: 600;
}
.footer {
    text-align: center;
    color: var(--muted);
    font-size: 12.5px;
    padding: 24px 16px 36px;
}
@media (max-width: 700px) {
    .header-inner {
        padding: 12px 14px;
        gap: 10px;
    }
    .book-title { font-size: 16px; }
    .episode-label { font-size: 11px; }
    .header-controls { width: 100%; }
    .episode-select {
        flex: 1;
        max-width: none;
        min-width: 0;
    }
    .container {
        margin-top: 18px;
        padding: 0 12px 40px;
    }
    .reader { padding: 24px 18px; }
    .chapter-cover img { max-width: 220px; }
    .chapter-title { font-size: 22px; }
    .chapter-content { font-size: 16.5px; line-height: 1.8; }
    .navigation {
        padding: 12px;
        justify-content: center;
    }
    .nav-left,
    .nav-center,
    .nav-right {
        width: 100%;
        justify-content: center;
    }
    .nav-btn { padding: 9px 12px; font-size: 12.5px; }
}
</style>
</head>
<body>
<header class="header">
    <div class="header-inner">
        <a href="index.php" class="brand" title="Kembali ke perpustakaan">
            <div class="logo-wrap">
                <img src="logo.png" alt="SAB Logo">
            </div>
        </a>
        <div class="book-heading">
            <h1 class="book-title"><?= e($novel['title']) ?></h1>
            <div class="episode-label">
                Bagian <?= $posisiBab ?> dari <?= $totalBab ?> · <?= e($bab['title']) ?>
            </div>
        </div>
        <div class="header-controls">
            <div id="theme-switcher-mount"></div>
            <select
                class="episode-select"
                aria-label="Pilih episode"
                onchange="if (this.value) window.location.href=this.value;"
            >
                <?php foreach ($daftarBab as $item): ?>
                    <option
                        value="read.php?id=<?= $novelId ?>&chapter_id=<?= (int) $item['id'] ?>"
                        <?= (int) $item['id'] === (int) $bab['id'] ? 'selected' : '' ?>
                    ><?= e($item['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <a class="header-btn add"
               title="Tambah episode baru"
               href="add_chapter.php?id=<?= $novelId ?>">＋</a>
            <a class="header-btn"
               title="Edit novel"
               href="edit.php?id=<?= $novelId ?>">⚙</a>
            <a class="header-btn"
               title="Export ke EPUB"
               href="export_epub.php?id=<?= (int) $novel['id'] ?>">📚</a>
            <a href="download.php?id=<?= (int) $novel['id'] ?>"
               class="download-btn"
               title="Download seluruh novel (TXT)">
                ⬇ TXT
            </a>
            <?php if ($babSebelumnya): ?>
                <a class="header-btn"
                   id="btn-prev-header"
                   title="Bagian sebelumnya (Tombol Panah Kiri)"
                   href="read.php?id=<?= $novelId ?>&chapter_id=<?= (int) $babSebelumnya['id'] ?>">←</a>
            <?php endif; ?>
            <?php if ($babBerikutnya): ?>
                <a class="header-btn"
                   id="btn-next-header"
                   title="Bagian berikutnya (Tombol Panah Kanan)"
                   href="read.php?id=<?= $novelId ?>&chapter_id=<?= (int) $babBerikutnya['id'] ?>">→</a>
            <?php endif; ?>
        </div>
    </div>
</header>
<main class="container">
    <article class="reader">
        <h2 class="chapter-title" id="story-title"><?= e($bab['title']) ?></h2>

        <?php if ($episodeCover): ?>
            <div class="chapter-cover">
                <img
                    src="<?= e($episodeCover) ?>"
                    alt="Gambar bagian <?= e($bab['title']) ?>"
                    loading="lazy"
                >
            </div>
        <?php endif; ?>

        <div class="chapter-content" id="story-content"><?= renderStoryContent($bab['content'] ?? '') ?></div>
    </article>

    <nav class="navigation">
        <div class="nav-left">
            <?php if ($babSebelumnya): ?>
                <a class="nav-btn"
                   id="btn-prev-nav"
                   href="read.php?id=<?= $novelId ?>&chapter_id=<?= (int) $babSebelumnya['id'] ?>">← Sebelumnya</a>
            <?php else: ?>
                <span class="nav-btn disabled">← Sebelumnya</span>
            <?php endif; ?>
        </div>

        <div class="nav-center">
            <button type="button" class="nav-btn copy" id="copy-story">
                📋 Copy Cerita
            </button>
            <a class="nav-btn edit-episode"
               href="edit.php?id=<?= $novelId ?>&chapter_id=<?= (int) $bab['id'] ?>"
               title="Edit bagian ini">
                ✏️ Edit Episode
            </a>
            <form
                method="post"
                action="delete_chapter.php"
                onsubmit="return confirm('Yakin ingin menghapus bagian ini? Data yang dihapus tidak dapat dikembalikan.');"
                style="display:inline;margin:0;"
            >
                <input type="hidden" name="id" value="<?= $novelId ?>">
                <input type="hidden" name="chapter_id" value="<?= (int) $bab['id'] ?>">
                <input type="hidden"
                       name="csrf_token"
                       value="<?= e($_SESSION['delete_chapter_token']) ?>">
                <button type="submit" class="nav-btn delete">
                    🗑 Hapus
                </button>
            </form>
            <span class="progress-text"><?= $posisiBab ?> / <?= $totalBab ?></span>
        </div>

        <div class="nav-right">
            <?php if ($babBerikutnya): ?>
                <a class="nav-btn"
                   id="btn-next-nav"
                   href="read.php?id=<?= $novelId ?>&chapter_id=<?= (int) $babBerikutnya['id'] ?>">Berikutnya →</a>
            <?php else: ?>
                <span class="nav-btn disabled">Bagian terakhir</span>
            <?php endif; ?>
        </div>

        <span id="copy-status" role="status" aria-live="polite"></span>
    </nav>

    <section class="chapter-list">
        <details>
            <summary>Daftar Semua Bagian / Bab</summary>
            <ol>
                <?php foreach ($daftarBab as $item): ?>
                    <li>
                        <a
                            class="<?= (int) $item['id'] === (int) $bab['id'] ? 'active' : '' ?>"
                            href="read.php?id=<?= $novelId ?>&chapter_id=<?= (int) $item['id'] ?>"
                        ><?= e($item['title']) ?></a>
                    </li>
                <?php endforeach; ?>
            </ol>
        </details>
    </section>
</main>
<footer class="footer">
    SAB Novel Library &copy; <?= date('Y') ?>
</footer>
<script>
(function () {
    const copyButton = document.getElementById('copy-story');
    const copyStatus = document.getElementById('copy-status');
    const title = document.getElementById('story-title').innerText.trim();
    const content = document.getElementById('story-content').innerText;
    const textToCopy = title + '\n\n' + content;
    copyButton.addEventListener('click', async function () {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(textToCopy);
            } else {
                const textarea = document.createElement('textarea');
                textarea.value = textToCopy;
                textarea.setAttribute('readonly', '',);
                textarea.style.position = 'fixed';
                textarea.style.left = '-9999px';
                document.body.appendChild(textarea);
                textarea.select();
                const success = document.execCommand('copy');
                textarea.remove();
                if (!success) {
                    throw new Error('Penyalinan gagal');
                }
            }
            copyStatus.textContent = 'Berhasil disalin! Silakan paste.';
            copyButton.textContent = '✓ Tersalin';
        } catch (error) {
            copyStatus.textContent = 'Gagal menyalin. Coba gunakan browser lain.';
        }
    });

    document.addEventListener('keydown', function(event) {
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
            return;
        }

        if (event.key === 'ArrowLeft') {
            const prevBtn = document.getElementById('btn-prev-nav') || document.getElementById('btn-prev-header');
            if (prevBtn) {
                window.location.href = prevBtn.href;
            }
        } else if (event.key === 'ArrowRight') {
            const nextBtn = document.getElementById('btn-next-nav') || document.getElementById('btn-next-header');
            if (nextBtn) {
                window.location.href = nextBtn.href;
            }
        }
    });

    const novelId = <?= json_encode((string) $novelId) ?>;
    const chapterId = <?= (int) $bab['id'] ?>;
    try {
        localStorage.setItem('satria_last_chapter_id_' + novelId, String(chapterId));
    } catch (error) {}
})();
</script>
</body>
</html>