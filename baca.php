<?php
session_start();
require_once __DIR__ . '/config/database.php';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Pecah isi bab menjadi beberapa halaman berdasarkan paragraf.
 * Target ~900–1200 karakter per halaman agar nyaman dibaca.
 */
function splitContentIntoPages(string $content, int $targetChars = 1100): array
{
    $content = trim(str_replace(["\r\n", "\r"], "\n", $content));

    if ($content === '') {
        return [''];
    }

    // Pisah per paragraf (baris kosong)
    $paragraphs = preg_split('/\n\s*\n/', $content) ?: [$content];
    $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), fn($p) => $p !== ''));

    if (count($paragraphs) === 0) {
        return [''];
    }

    $pages = [];
    $current = '';

    foreach ($paragraphs as $p) {
        $candidate = $current === '' ? $p : $current . "\n\n" . $p;

        // Jika paragraf tunggal sangat panjang, potong kasar per kalimat
        if (mb_strlen($p) > $targetChars * 1.5) {
            if ($current !== '') {
                $pages[] = $current;
                $current = '';
            }
            $chunks = preg_split('/(?<=[.!?…])\s+/u', $p) ?: [$p];
            $buf = '';
            foreach ($chunks as $chunk) {
                $try = $buf === '' ? $chunk : $buf . ' ' . $chunk;
                if (mb_strlen($try) > $targetChars && $buf !== '') {
                    $pages[] = $buf;
                    $buf = $chunk;
                } else {
                    $buf = $try;
                }
            }
            if ($buf !== '') {
                $current = $buf;
            }
            continue;
        }

        if (mb_strlen($candidate) > $targetChars && $current !== '') {
            $pages[] = $current;
            $current = $p;
        } else {
            $current = $candidate;
        }
    }

    if ($current !== '') {
        $pages[] = $current;
    }

    return $pages ?: [''];
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

$stmt = $pdo->prepare(
    'SELECT id, chapter_number, title, content, word_count
     FROM chapters
     WHERE novel_id = ?
     ORDER BY chapter_number ASC'
);
$stmt->execute([$novelId]);
$daftarBab = $stmt->fetchAll();

if (!$daftarBab) {
    exit('Novel ini belum memiliki episode.');
}

$nomorBab = filter_input(INPUT_GET, 'chapter', FILTER_VALIDATE_INT);
if (!$nomorBab || $nomorBab < 1) {
    $nomorBab = (int) $daftarBab[0]['chapter_number'];
}

$pageNum = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
if (!$pageNum || $pageNum < 1) {
    $pageNum = 1;
}

// Cari bab aktif + prev/next episode
$totalBab = count($daftarBab);
$posisiBab = 1;
$babSebelumnya = null;
$babBerikutnya = null;
$bab = null;

foreach ($daftarBab as $index => $item) {
    if ((int) $item['chapter_number'] === (int) $nomorBab) {
        $posisiBab = $index + 1;
        $bab = $item;
        if ($index > 0) {
            $babSebelumnya = $daftarBab[$index - 1];
        }
        if ($index < $totalBab - 1) {
            $babBerikutnya = $daftarBab[$index + 1];
        }
        break;
    }
}

if (!$bab) {
    $bab = $daftarBab[0];
    $nomorBab = (int) $bab['chapter_number'];
    $posisiBab = 1;
    $babBerikutnya = $totalBab > 1 ? $daftarBab[1] : null;
}

// Pecah konten jadi halaman
$pages = splitContentIntoPages((string) ($bab['content'] ?? ''), 1100);
$totalPages = count($pages);

if ($pageNum > $totalPages) {
    $pageNum = $totalPages;
}

$currentContent = $pages[$pageNum - 1] ?? '';

// URL helper
function readerUrl(int $novelId, int $chapter, int $page = 1): string
{
    return 'baca.php?id=' . $novelId . '&chapter=' . $chapter . '&page=' . $page;
}

// Navigasi halaman
$prevPageUrl = null;
$nextPageUrl = null;

if ($pageNum > 1) {
    $prevPageUrl = readerUrl($novelId, $nomorBab, $pageNum - 1);
} elseif ($babSebelumnya) {
    // Ke halaman terakhir episode sebelumnya — hitung dulu
    $prevPages = splitContentIntoPages((string) ($babSebelumnya['content'] ?? ''), 1100);
    $prevPageUrl = readerUrl(
        $novelId,
        (int) $babSebelumnya['chapter_number'],
        max(1, count($prevPages))
    );
}

if ($pageNum < $totalPages) {
    $nextPageUrl = readerUrl($novelId, $nomorBab, $pageNum + 1);
} elseif ($babBerikutnya) {
    $nextPageUrl = readerUrl($novelId, (int) $babBerikutnya['chapter_number'], 1);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($novel['title']) ?> — <?= e($bab['title']) ?></title>

<link rel="stylesheet" href="assets/theme-system.css">
<script src="assets/theme.js"></script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,600;0,700;1,400&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Serif:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@400;500;600;700&family=Literata:ital,opsz,wght@0,7..72,400;0,7..72,600;0,7..72,700;1,7..72,400&family=Lora:ital,wght@0,400;0,600;0,700;1,400&family=Merriweather:ital,wght@0,400;0,700;1,400&family=Playfair+Display:wght@600;700&family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;0,8..60,700;1,8..60,400&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">

<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
    height: 100%;
}

body {
    font-family: var(--font-ui, 'Inter', system-ui, sans-serif);
    background: var(--bg);
    color: var(--text);
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
}

/* ===== TOP BAR ===== */
.topbar {
    flex-shrink: 0;
    background: var(--header-bg);
    border-bottom: 1px solid var(--border);
    padding: 12px 18px;
    position: sticky;
    top: 0;
    z-index: 50;
}

.topbar-inner {
    max-width: 780px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    gap: 12px;
}

.btn-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: rgba(255,255,255,.08);
    border: 1px solid var(--border);
    color: var(--text);
    text-decoration: none;
    font-size: 18px;
    flex-shrink: 0;
    transition: background .15s;
}

.btn-icon:hover {
    background: rgba(255,255,255,.14);
}

.top-meta {
    flex: 1;
    min-width: 0;
}

.top-title {
    font-family: var(--font-heading, 'Playfair Display', Georgia, serif);
    font-size: 15px;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: var(--text);
}

.top-sub {
    font-size: 12px;
    color: var(--muted);
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

#theme-switcher-mount {
    position: relative;
    z-index: 200;
    flex-shrink: 0;
}

.theme-panel {
    max-height: min(60vh, 420px) !important;
    overflow-y: auto !important;
    z-index: 9999 !important;
}

/* ===== READER AREA ===== */
.reader-wrap {
    flex: 1;
    display: flex;
    flex-direction: column;
    max-width: 780px;
    width: 100%;
    margin: 0 auto;
    padding: 24px 20px 0;
}

.chapter-label {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: var(--accent);
    margin-bottom: 8px;
}

.chapter-title {
    font-family: var(--font-heading, 'Playfair Display', Georgia, serif);
    font-size: clamp(22px, 4vw, 28px);
    font-weight: 700;
    line-height: 1.3;
    margin-bottom: 20px;
    color: var(--text);
    letter-spacing: -0.3px;
}

.page-body {
    flex: 1;
    background: var(--reader-bg);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 28px 28px 32px;
    box-shadow: var(--shadow);
    margin-bottom: 20px;
}

.page-content {
    font-family: var(--font-reader, Georgia, 'Times New Roman', serif);
    font-size: var(--reader-size, 18.5px);
    line-height: var(--reader-line, 1.95);
    color: var(--reader-text);
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

/* ===== BOTTOM NAV ===== */
.bottom-nav {
    flex-shrink: 0;
    position: sticky;
    bottom: 0;
    background: var(--surface);
    border-top: 1px solid var(--border);
    padding: 14px 18px calc(14px + env(safe-area-inset-bottom));
    box-shadow: 0 -4px 20px rgba(0,0,0,.15);
    z-index: 40;
}

.bottom-inner {
    max-width: 780px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.nav-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-width: 120px;
    padding: 12px 18px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    border: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--text);
    transition: all .15s;
    font-family: inherit;
    cursor: pointer;
}

.nav-btn:hover:not(.disabled) {
    border-color: var(--accent);
    background: var(--surface);
}

.nav-btn.primary {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
}

.nav-btn.primary:hover:not(.disabled) {
    background: var(--accent-hover);
}

.nav-btn.disabled {
    opacity: 0.4;
    pointer-events: none;
}

.page-indicator {
    text-align: center;
    flex: 1;
}

.page-indicator .nums {
    font-size: 15px;
    font-weight: 700;
    color: var(--text);
}

.page-indicator .hint {
    font-size: 11.5px;
    color: var(--muted);
    margin-top: 2px;
}

/* Progress bar tipis */
.progress-bar {
    height: 3px;
    background: var(--border);
    border-radius: 2px;
    overflow: hidden;
    margin-bottom: 12px;
}

.progress-fill {
    height: 100%;
    background: var(--accent);
    border-radius: 2px;
    transition: width 0.25s ease;
}

@media (max-width: 560px) {
    .reader-wrap { padding: 16px 14px 0; }
    .page-body { padding: 20px 16px 24px; }
    .nav-btn {
        min-width: 0;
        flex: 1;
        padding: 12px 10px;
        font-size: 13px;
    }
    .page-indicator .hint { display: none; }
}
</style>
</head>
<body>

<header class="topbar">
    <div class="topbar-inner">
        <a href="index.php" class="btn-icon" title="Kembali ke perpustakaan">←</a>

        <div class="top-meta">
            <div class="top-title"><?= e($novel['title']) ?></div>
            <div class="top-sub">
                Episode <?= $posisiBab ?> / <?= $totalBab ?>
                · Halaman <?= $pageNum ?> / <?= $totalPages ?>
            </div>
        </div>

        <div id="theme-switcher-mount"></div>
    </div>
</header>

<main class="reader-wrap">
    <div class="chapter-label">Episode <?= $posisiBab ?></div>
    <h1 class="chapter-title"><?= e($bab['title']) ?></h1>

    <article class="page-body">
        <div class="page-content"><?= e($currentContent) ?></div>
    </article>
</main>

<nav class="bottom-nav">
    <div class="progress-bar">
        <div class="progress-fill" style="width: <?= $totalPages > 0 ? round(($pageNum / $totalPages) * 100) : 0 ?>%"></div>
    </div>

    <div class="bottom-inner">
        <?php if ($prevPageUrl): ?>
            <a class="nav-btn" href="<?= e($prevPageUrl) ?>">← Sebelumnya</a>
        <?php else: ?>
            <span class="nav-btn disabled">← Sebelumnya</span>
        <?php endif; ?>

        <div class="page-indicator">
            <div class="nums"><?= $pageNum ?> / <?= $totalPages ?></div>
            <div class="hint">
                <?php if ($pageNum >= $totalPages && $babBerikutnya): ?>
                    Berikutnya: episode baru
                <?php elseif ($pageNum <= 1 && $babSebelumnya): ?>
                    Sebelumnya: episode lalu
                <?php else: ?>
                    Halaman episode ini
                <?php endif; ?>
            </div>
        </div>

        <?php if ($nextPageUrl): ?>
            <a class="nav-btn primary" href="<?= e($nextPageUrl) ?>">
                <?= ($pageNum >= $totalPages && $babBerikutnya) ? 'Episode berikutnya →' : 'Berikutnya →' ?>
            </a>
        <?php else: ?>
            <span class="nav-btn disabled">Selesai</span>
        <?php endif; ?>
    </div>
</nav>

<script>
// Simpan posisi baca terakhir
(function () {
    try {
        localStorage.setItem(
            'sab_reader_pos_<?= (int)$novelId ?>',
            JSON.stringify({
                chapter: <?= (int)$nomorBab ?>,
                page: <?= (int)$pageNum ?>
            })
        );
    } catch (e) {}
})();

// Navigasi keyboard
document.addEventListener('keydown', function (e) {
    if (e.target.matches('input, textarea')) return;
    if (e.key === 'ArrowRight' || e.key === ' ') {
        const next = document.querySelector('.nav-btn.primary');
        if (next && next.href) {
            e.preventDefault();
            window.location.href = next.href;
        }
    }
    if (e.key === 'ArrowLeft') {
        const prev = document.querySelector('.bottom-inner .nav-btn:not(.disabled):not(.primary)');
        if (prev && prev.href) {
            e.preventDefault();
            window.location.href = prev.href;
        }
    }
});
</script>
</body>
</html>