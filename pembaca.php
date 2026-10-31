<?php
session_start();
require_once __DIR__ . '/config/database.php';

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function createDefaultCover($title)
{
    $lines = explode("\n", wordwrap((string) $title, 20, "\n", true));
    $lines = array_slice($lines, 0, 4);
    $titleSvg = '';
    $startY = 360;
    foreach ($lines as $index => $line) {
        $safeLine = htmlspecialchars($line, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $y = $startY + ($index * 42);
        $titleSvg .= '<text x="320" y="' . $y . '"
            text-anchor="middle"
            font-family="Georgia, serif"
            font-size="32"
            font-weight="bold"
            fill="#fff4d6">' . $safeLine . '</text>';
    }
    $svg = '
    <svg xmlns="http://www.w3.org/2000/svg"
         width="640" height="800" viewBox="0 0 640 800">
        <defs>
            <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0%" stop-color="#172554"/>
                <stop offset="55%" stop-color="#37304f"/>
                <stop offset="100%" stop-color="#8b653c"/>
            </linearGradient>
            <linearGradient id="circle" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0%" stop-color="#f7d88b" stop-opacity=".9"/>
                <stop offset="100%" stop-color="#d4a65a" stop-opacity=".15"/>
            </linearGradient>
        </defs>
        <rect width="640" height="800" fill="url(#bg)"/>
        <circle cx="320" cy="230" r="145" fill="url(#circle)"/>
        <circle cx="320" cy="230" r="112"
                fill="none" stroke="#fff0c2"
                stroke-opacity=".65" stroke-width="2"/>
        <path d="M75 650 L240 420 L320 520 L410 390 L565 650 Z"
              fill="#111827" fill-opacity=".55"/>
        <path d="M90 680 L245 500 L320 575 L430 455 L560 680 Z"
              fill="#0f172a" fill-opacity=".7"/>
        <g transform="translate(320 230)"
           fill="none" stroke="#fff4d6"
           stroke-width="8" stroke-linejoin="round">
            <path d="M-76 -43 Q-38 -60 0 -35
                     Q38 -60 76 -43
                     L76 50 Q38 32 0 55
                     Q-38 32 -76 50 Z"/>
            <path d="M0 -35 L0 55"/>
            <path d="M-58 -25 L-22 -17 M-58 -3 L-22 5
                     M22 -17 L58 -25 M22 5 L58 -3"
                  stroke-width="4"/>
        </g>
        <text x="320" y="83"
              text-anchor="middle"
              font-family="Arial, sans-serif"
              font-size="17"
              letter-spacing="4"
              fill="#f7d88b">SAB Novel Library</text>
        <path d="M125 285 H515"
              stroke="#fff4d6" stroke-opacity=".65"
              stroke-width="2"/>
        ' . $titleSvg . '
        <text x="320" y="610"
              text-anchor="middle"
              font-family="Arial, sans-serif"
              font-size="17"
              letter-spacing="3"
              fill="#f7d88b">NOVEL DIGITAL</text>
        <path d="M190 650 H450"
              stroke="#f7d88b" stroke-opacity=".7"
              stroke-width="2"/>
        <text x="320" y="710"
              text-anchor="middle"
              font-family="Georgia, serif"
              font-size="17"
              fill="#fff4d6">Sebuah kisah menanti</text>
    </svg>';
    return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($svg);
}

function getNovelCover($novel)
{
    $path = trim((string) ($novel['cover_path'] ?? ''));
    if (
        $path !== ''
        && strpos($path, 'uploads/covers/') === 0
        && strpos($path, '..') === false
        && is_file(__DIR__ . '/' . $path)
    ) {
        return $path;
    }
    return createDefaultCover($novel['title'] ?? 'Novel Tanpa Judul');
}

try {
    $novelCount = (int) $pdo->query('SELECT COUNT(*) FROM novels')->fetchColumn();
    $stmt = $pdo->query(
        'SELECT
            n.id,
            n.title,
            n.author,
            n.original_filename,
            n.cover_path,
            n.imported_at,
            COUNT(c.id) AS chapter_count,
            COALESCE(SUM(c.word_count), 0) AS total_words
         FROM novels n
         LEFT JOIN chapters c ON c.novel_id = n.id
         GROUP BY
            n.id, n.title, n.author, n.original_filename, n.cover_path, n.imported_at
         ORDER BY n.imported_at DESC, n.id DESC'
    );
    $novels = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    http_response_code(500);
    exit('Gagal membaca data perpustakaan.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SAB Novel Library</title>
    <!-- Theme System -->
    <link rel="stylesheet" href="assets/theme-system.css">
    <script src="assets/theme.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,600;0,700;1,400&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Serif:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@400;500;600;700&family=Literata:ital,opsz,wght@0,7..72,400;0,7..72,600;0,7..72,700;1,7..72,400&family=Lora:ital,wght@0,400;0,600;0,700;1,400&family=Merriweather:ital,wght@0,400;0,700;1,400&family=Playfair+Display:wght@600;700&family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;0,8..60,700;1,8..60,400&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
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
            -khtml-user-drag: none;
            -moz-user-drag: none;
            -o-user-drag: none;
            user-drag: none;
            pointer-events: none;
        }

        body {
            font-family: var(--font-ui, 'Inter', system-ui, -apple-system, sans-serif);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            background: var(--bg);
            color: var(--text);
        }

        @media print {
            html, body {
                display: none !important;
            }
        }

        .header {
            background: var(--header-bg);
            color: var(--text);
            padding: 28px 24px 36px;
            position: relative;
            overflow: visible;
            border-bottom: 1px solid var(--border);
            z-index: 100;
        }
        .header-inner {
            max-width: 1180px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 24px;
            flex-wrap: wrap;
            position: relative;
            z-index: 101;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 16px;
            text-decoration: none;
            color: inherit;
        }
        .logo-wrap {
            position: relative;
            width: 72px;
            height: 72px;
            flex-shrink: 0;
        }
        .logo-wrap img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
            display: block;
            filter: drop-shadow(0 0 10px rgba(0, 180, 255, 0.35));
            transition: transform 0.3s ease, filter 0.3s ease;
        }
        .brand:hover .logo-wrap img {
            transform: scale(1.05);
            filter: drop-shadow(0 0 14px rgba(0, 200, 255, 0.5));
        }
        .brand-text h1 {
            font-family: var(--font-heading, 'Playfair Display', Georgia, serif);
            font-size: 26px;
            font-weight: 700;
            letter-spacing: -0.4px;
            line-height: 1.15;
            margin-bottom: 3px;
            color: var(--text);
        }
        .brand-text .tagline {
            color: var(--muted);
            font-size: 13px;
            font-weight: 400;
        }
        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            position: relative;
            z-index: 102;
        }
        #theme-switcher-mount {
            position: relative;
            z-index: 200;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 11px 18px;
            border-radius: 9px;
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
            font-family: inherit;
        }
        .button:hover { transform: translateY(-1px); }
        .button-read {
            width: 100%;
            background: var(--surface-2);
            color: var(--text);
            border: 1px solid var(--border);
            pointer-events: auto;
        }
        .button-read:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        .container {
            max-width: 1180px;
            margin: 24px auto 0;
            padding: 0 24px 56px;
            position: relative;
            z-index: 1;
        }

        .section-heading {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            margin-bottom: 20px;
        }
        .section-heading h2 {
            font-family: var(--font-heading, 'Playfair Display', Georgia, serif);
            font-size: 22px;
            font-weight: 600;
            letter-spacing: -0.3px;
            color: var(--text);
        }
        .section-heading span {
            color: var(--muted);
            font-size: 13px;
            font-weight: 500;
        }

        .novel-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 22px;
        }
        .novel-card {
            min-width: 0;
            background: var(--surface);
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            display: flex;
            flex-direction: column;
        }
        .novel-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 16px 40px rgba(0,0,0,.35);
        }
        .cover-link {
            display: block;
            position: relative;
            background: var(--surface-2);
            overflow: hidden;
            pointer-events: auto;
        }
        .cover-image {
            display: block;
            width: 100%;
            aspect-ratio: 4 / 5;
            object-fit: cover;
            transition: transform 0.4s ease;
        }
        .novel-card:hover .cover-image {
            transform: scale(1.04);
        }
        .novel-info {
            padding: 14px 16px 16px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        .novel-stats {
            display: flex;
            gap: 6px 14px;
            flex-wrap: wrap;
            color: var(--muted);
            font-size: 11.5px;
            font-weight: 500;
        }
        .novel-stats span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .novel-actions {
            display: flex;
            gap: 8px;
            margin-top: auto;
            padding-top: 14px;
        }

        .empty {
            background: var(--surface);
            padding: 64px 28px;
            border-radius: 14px;
            text-align: center;
            color: var(--muted);
            line-height: 1.7;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }
        .empty-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            background: var(--surface-2);
            font-size: 32px;
        }
        .empty h3 {
            color: var(--text);
            font-family: var(--font-heading, 'Playfair Display', Georgia, serif);
            font-size: 20px;
            margin-bottom: 8px;
        }

        .footer {
            text-align: center;
            color: var(--muted);
            font-size: 12.5px;
            padding: 28px 16px 36px;
            border-top: 1px solid var(--border);
        }

        @media (max-width: 1024px) {
            .novel-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 18px;
            }
        }
        @media (max-width: 720px) {
            .header { padding: 22px 16px 28px; }
            .logo-wrap { width: 58px; height: 58px; }
            .brand-text h1 { font-size: 21px; }
            .brand-text .tagline { font-size: 12px; }
            .header-inner {
                flex-direction: column;
                align-items: flex-start;
                gap: 18px;
            }
            .header-actions { width: 100%; }
            .container {
                padding: 0 16px 48px;
                margin-top: 16px;
            }
            .novel-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 14px;
            }
            .novel-info { padding: 12px 12px 14px; }
            .novel-actions .button {
                width: 100%;
                padding: 10px 8px;
                font-size: 12px;
            }
        }
        @media (max-width: 400px) {
            .novel-grid { gap: 10px; }
        }
    </style>
</head>
<body>
<header class="header">
    <div class="header-inner">
        <a href="index.php" class="brand">
            <div class="logo-wrap">
                <img src="logo.png" alt="SAB Logo - Suyatno Abu Bakar">
            </div>
            <div class="brand-text">
                <h1>SAB Novel Library</h1>
                <p class="tagline">Perpustakaan novel digital</p>
            </div>
        </a>
        <div class="header-actions">
            <div id="theme-switcher-mount"></div>
        </div>
    </div>
</header>
<main class="container">
    <section>
        <div class="section-heading">
            <h2>Daftar Novel</h2>
            <span><?= number_format($novelCount, 0, ',', '.') ?> novel</span>
        </div>
        <?php if (count($novels) > 0): ?>
            <div class="novel-grid">
                <?php foreach ($novels as $novel): ?>
                    <?php $cover = getNovelCover($novel); ?>
                    <article class="novel-card">
                        <!-- Tautan sampul diarahkan ke halaman_baca.php -->
                        <a
                            href="halaman_baca.php?id=<?= (int) $novel['id'] ?>"
                            class="cover-link"
                            aria-label="Baca <?= e($novel['title']) ?>"
                        >
                            <img
                                src="<?= e($cover) ?>"
                                alt="Sampul <?= e($novel['title']) ?>"
                                class="cover-image"
                                loading="lazy"
                            >
                        </a>
                        <div class="novel-info">
                            <div class="novel-stats">
                                <span>
                                    📖
                                    <?= number_format((int) $novel['chapter_count'], 0, ',', '.') ?>
                                    Episode
                                </span>
                                <span>
                                    ✍️
                                    <?= number_format((int) $novel['total_words'], 0, ',', '.') ?>
                                    Kata
                                </span>
                            </div>
                            <div class="novel-actions">
                                <!-- Tautan tombol Baca diarahkan ke halaman_baca.php -->
                                <a
                                    href="halaman_baca.php?id=<?= (int) $novel['id'] ?>"
                                    class="button button-read"
                                >
                                    Baca
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty">
                <div class="empty-icon">📚</div>
                <h3>Perpustakaan masih kosong</h3>
                <p>Belum ada novel yang tersedia saat ini.</p>
            </div>
        <?php endif; ?>
    </section>
</main>
<footer class="footer">
    SAB Novel Library &copy; <?= date('Y') ?>
</footer>

<script>
    document.addEventListener('contextmenu', function(e) {
        e.preventDefault();
    });

    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && (e.key === 'c' || e.key === 'C' || e.key === 'u' || e.key === 'U' || e.key === 's' || e.key === 'S' || e.key === 'p' || e.key === 'P' || e.key === 'a' || e.key === 'A')) {
            e.preventDefault();
        }
        if (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'i' || e.key === 'J' || e.key === 'j' || e.key === 'C' || e.key === 'c')) {
            e.preventDefault();
        }
        if (e.key === 'F12') {
            e.preventDefault();
        }
        if (e.key === 'PrintScreen' || e.keyCode === 44) {
            navigator.clipboard.writeText('');
            alert('Screenshot tidak diizinkan pada halaman ini.');
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