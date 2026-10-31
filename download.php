<?php

require_once __DIR__ . '/config/database.php';

$novelId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$novelId || $novelId < 1) {
    http_response_code(400);
    exit('ID novel tidak valid.');
}

$stmt = $pdo->prepare('SELECT * FROM novels WHERE id = ?');
$stmt->execute([$novelId]);
$novel = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$novel) {
    http_response_code(404);
    exit('Novel tidak ditemukan.');
}

$title = trim((string) ($novel['title'] ?? 'Tanpa Judul'));
$author = trim((string) ($novel['author'] ?? 'SUYATNO ABU BAKAR'));
$genre = trim((string) ($novel['genre'] ?? 'Novel'));

$synopsis = '';
if (isset($novel['synopsis'])) {
    $synopsis = trim((string) $novel['synopsis']);
}

$synopsisOneLine = preg_replace('/\s+/u', ' ', $synopsis);
$synopsisOneLine = trim((string) $synopsisOneLine);

// Ambil seluruh bab mentah dari database
$stmt = $pdo->prepare(
    'SELECT chapter_number, title, content, id
     FROM chapters
     WHERE novel_id = ?'
);
$stmt->execute([$novelId]);
$chapters = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$chapters) {
    http_response_code(404);
    exit('Novel ini belum memiliki episode untuk diunduh.');
}

// Logika Pengurutan Seragam seperti di read.php & export_epub.php
usort($chapters, static function($a, $b) {
    $numA = (int) $a['chapter_number'];
    $numB = (int) $b['chapter_number'];

    if ($numA > 0 && $numB > 0) {
        return $numA <=> $numB;
    }

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
        return 2;
    };

    $rA = ($numA > 0) ? 2 : $rank($tA);
    $rB = ($numB > 0) ? 2 : $rank($tB);

    if ($rA !== $rB) {
        return $rA <=> $rB;
    }

    return (int)$a['id'] <=> (int)$b['id'];
});

$output = [];
$output[] = 'Judul: ' . mb_strtoupper($title, 'UTF-8');
$output[] = 'Penulis: ' . $author;
$output[] = 'Genre: ' . $genre;
$output[] = 'Sinopsis: ' . ($synopsisOneLine !== '' ? $synopsisOneLine : 'Tidak tersedia.');
$output[] = '';

foreach ($chapters as $chapter) {
    $number = (int) ($chapter['chapter_number'] ?? 0);
    $chapterTitle = trim((string) ($chapter['title'] ?? ''));
    $content = (string) ($chapter['content'] ?? '');

    // Membersihkan tag HTML dari konten rich-text agar menjadi teks biasa
    $content = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $content)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $content = str_replace(["\r\n", "\r"], "\n", $content);

    $chapterTitle = preg_replace(
        '/^\s*={0,3}\s*EPISODE\s+\d+\s*:\s*/iu',
        '',
        $chapterTitle
    );
    $chapterTitle = preg_replace(
        '/^\s*Episode\s+\d+\s*:\s*/iu',
        '',
        $chapterTitle
    );
    $chapterTitle = preg_replace(
        '/^\s*#\s*\d+\s*[.:\-]?\s*/u',
        '',
        $chapterTitle
    );
    $chapterTitle = trim((string) $chapterTitle);

    if ($chapterTitle === '') {
        $chapterTitle = 'Bagian ' . $number;
    }

    $contentLines = explode("\n", $content);
    $cleanLines = [];
    foreach ($contentLines as $line) {
        $cleanLines[] = trim($line);
    }

    // Buang baris kosong berlebih
    $cleanLines = array_filter($cleanLines, static fn($l) => $l !== '');
    $content = implode("\n\n", $cleanLines);

    if ($number > 0) {
        $output[] = '=== EPISODE ' . $number . ': ' . $chapterTitle . ' ===';
        $output[] = sprintf('#%02d. %s', $number, $chapterTitle);
    } else {
        $output[] = '=== ' . mb_strtoupper($chapterTitle, 'UTF-8') . ' ===';
        $output[] = $chapterTitle;
    }
    
    $output[] = '';

    if ($content !== '') {
        $output[] = $content;
    }

    $output[] = '';
    $output[] = '';
}

while (!empty($output) && end($output) === '') {
    array_pop($output);
}

$text = implode("\n", $output) . "\n";
$textWithBom = "\xEF\xBB\xBF" . $text;

$filename = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $title);
$filename = trim((string) $filename, '_-');
if ($filename === '') {
    $filename = 'novel';
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: text/plain; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '.txt"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($textWithBom));

echo $textWithBom;
exit;