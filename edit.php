<?php
session_start();
require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['edit_token'])) {
    $_SESSION['edit_token'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Rapikan teks episode (paragraf + indent) — sama standar import.
 */
function rapikanNaskahEpisode(string $teks): string
{
    if (trim($teks) === '') return '';

    // Ubah HTML editor/Word menjadi teks dengan batas paragraf yang jelas.
    $teks = preg_replace('/^\xEF\xBB\xBF/u', '', $teks) ?? $teks;
    $teks = str_replace(["\r\n", "\r"], "\n", $teks);
    $teks = preg_replace('/<\s*br\s*\/?\s*>/iu', "\n", $teks) ?? $teks;
    $teks = preg_replace('/<\s*\/\s*(?:p|div|li|h[1-6])\s*>/iu', "\n\n", $teks) ?? $teks;
    $teks = preg_replace('/<\s*(?:p|div|li|h[1-6])\b[^>]*>/iu', '', $teks) ?? $teks;
    $teks = html_entity_decode(strip_tags($teks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $teks = str_replace(["\xC2\xAD", "\xC2\xA0"], ['', ' '], $teks);
    $teks = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $teks) ?? $teks;
    $teks = str_replace(['\\"', "\\'"], ['"', "'"], $teks);
    $teks = preg_replace('/[ \t]+/u', ' ', $teks) ?? $teks;

    $lines = explode("\n", $teks);
    $paragraphs = [];
    $buffer = '';
    $isHeading = static function (string $line): bool {
        return (bool) preg_match('/^(?:===|#{1,6}\s|BAB\s+\d+|Bab\s+\d+|CHAPTER\s+\d+|Chapter\s+\d+|EPISODE\s+\d+|Episode\s+\d+|\d+\.\s+\p{L}|Pendahuluan|PENDAHULUAN|Kesimpulan|KESIMPULAN)/u', $line);
    };
    $isDialogue = static function (string $line): bool {
        return (bool) preg_match('/^(?:—|–|"|“|„|‘|\'|«)/u', $line);
    };
    $flush = static function () use (&$buffer, &$paragraphs): void {
        $value = trim($buffer);
        if ($value !== '') $paragraphs[] = $value;
        $buffer = '';
    };

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') { $flush(); continue; }
        $line = preg_replace('/\s+([,.;:!?…»”’\)\]])/u', '$1', $line) ?? $line;
        if ($isHeading($line)) { $flush(); $paragraphs[] = $line; continue; }
        // Baris dialog yang diawali tanda petik/pisah menjadi paragraf penutur tersendiri.
        if ($isDialogue($line)) { $flush(); $buffer = $line; $flush(); continue; }
        if ($buffer === '') { $buffer = $line; continue; }
        // Baris berikutnya yang tampak sebagai dialog tetap dipisahkan; baris prosa yang terbungkus digabung.
        $buffer .= ' ' . $line;
    }
    $flush();

    if (!$paragraphs) return '';
    $html = [];
    foreach ($paragraphs as $para) {
        $para = trim(preg_replace('/ {2,}/u', ' ', $para) ?? $para);
        if ($para === '') continue;
        $safe = htmlspecialchars($para, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $heading = (bool) preg_match('/^(?:===|#{1,6}\s|BAB\s+\d+|Bab\s+\d+|CHAPTER\s+\d+|Chapter\s+\d+|EPISODE\s+\d+|Episode\s+\d+|\d+\.\s+\p{L}|Pendahuluan|PENDAHULUAN|Kesimpulan|KESIMPULAN)/u', $para);
        if ($heading) {
            $html[] = '<p style="text-indent:0 !important;margin:1.1em 0 0.45em;font-weight:600">' . $safe . '</p>';
        } else {
            $html[] = '<p style="text-indent:0 !important;margin:0 0 8px;line-height:1.65;text-align:justify">' . $safe . '</p>';
        }
    }
    return implode("\n", $html);
}

/**
 * Pemeriksaan standar teknis naskah.
 * Pemeriksaan ini tidak mengklaim menilai seluruh tata bahasa/KBBI secara semantik;
 * yang dicek adalah struktur paragraf, indentasi, spasi, dan tanda baca dasar.
 */
function cekNaskahSesuaiStandar(string $html): array
{
    $alasan = [];
    $teks = trim($html);
    if ($teks === '') {
        return ['sesuai' => false, 'alasan' => ['Naskah masih kosong.']];
    }

    preg_match_all('/<p\b([^>]*)>(.*?)<\/p>/isu', $teks, $matches, PREG_SET_ORDER);
    if (!$matches) {
        return ['sesuai' => false, 'alasan' => ['Naskah belum tersusun dalam paragraf HTML.']];
    }

    $plain = html_entity_decode(strip_tags($teks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('/[ \t]+[,.;:!?…]/u', $plain)) {
        $alasan[] = 'Ada spasi sebelum tanda baca.';
    }
    if (preg_match('/[,;:!?](?=\p{L})/u', $plain)) {
        $alasan[] = 'Periksa spasi setelah tanda baca.';
    }
    if (preg_match('/(?:\r?\n){3,}/u', $teks)) {
        $alasan[] = 'Ada jarak/baris kosong berlebihan.';
    }

    foreach ($matches as $m) {
        $attrs = $m[1] ?? '';
        $inner = trim(strip_tags($m[2] ?? ''));
        if ($inner === '') {
            $alasan[] = 'Ada paragraf kosong.';
            continue;
        }
        $isHeading = (bool) preg_match('/^(?:===|#{1,6}\s|BAB\s+\d+|Bab\s+\d+|CHAPTER\s+\d+|Chapter\s+\d+|EPISODE\s+\d+|Episode\s+\d+|\d+\.\s+\p{L}|Pendahuluan|PENDAHULUAN|Kesimpulan|KESIMPULAN)/iu', $inner);
        if ($isHeading) continue;

        preg_match('/text-indent\s*:\s*([^;"\']+)/i', $attrs, $im);
        $indent = trim(preg_replace('/\s*!important\s*$/i', '', $im[1] ?? ''));
        if ($indent !== '' && !preg_match('/^(?:0(?:px|em|rem|%)?)$/i', $indent)) {
            $alasan[] = 'Semua paragraf harus rata kiri tanpa indentasi.';
        }
    }

    $alasan = array_values(array_unique($alasan));
    return ['sesuai' => count($alasan) === 0, 'alasan' => $alasan];
}

function hitungKataEpisode(string $html): int
{
    $teks = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    if ($teks === '') {
        return 0;
    }
    preg_match_all('/[\p{L}\p{N}]+(?:[\'\x{2019}-][\p{L}\p{N}]+)*/u', $teks, $matches);
    return count($matches[0]);
}



function deleteCoverFile(?string $path): void
{
    if (!$path) {
        return;
    }

    $normalized = str_replace('\\', '/', $path);
    if (strpos($normalized, 'uploads/covers/') !== 0) {
        return;
    }

    $fullPath = __DIR__ . '/' . $normalized;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function uploadCover(string $field): ?string
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$field];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload sampul gagal. Silakan coba lagi.');
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Ukuran sampul maksimal 5 MB.');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('File unggahan tidak valid.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowedTypes[$mime])) {
        throw new RuntimeException('Format sampul harus JPG, PNG, atau WebP.');
    }

    $uploadDir = __DIR__ . '/uploads/covers/';

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Folder sampul tidak dapat dibuat.');
        }
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mime];
    $destination = $uploadDir . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Sampul gagal disimpan ke server.');
    }

    return 'uploads/covers/' . $fileName;
}

function coverUrl(?string $path): ?string
{
    if (!$path) {
        return null;
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    return $path;
}

$novelId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$chapterNumber = filter_input(INPUT_GET, 'chapter', FILTER_VALIDATE_INT);

// Simpan judul episode sebagai judul murni; nomor episode diambil dari chapter_number.
function normalizeEpisodeTitle(string $title): string
{
    $title = trim($title);
    // Hilangkan awalan seperti: Episode 11 - ..., Episode 11: ..., atau Episode 11. ...
    $title = preg_replace('/^episode\s+\d+\s*(?:[.\-:–—|]+\s*)?/iu', '', $title) ?? $title;
    return trim($title);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedNovelId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if ($postedNovelId !== false && $postedNovelId !== null) {
        $novelId = $postedNovelId;
    }

    $postedChapter = filter_input(INPUT_POST, 'chapter', FILTER_VALIDATE_INT);
    if ($postedChapter !== false && $postedChapter !== null) {
        $chapterNumber = $postedChapter;
    }
}

$isEpisodeMode = ($chapterNumber !== false && $chapterNumber !== null && $chapterNumber >= 1);

if (!$novelId || $novelId < 1) {
    http_response_code(400);
    exit('ID novel tidak valid.');
}

$error = '';
$newlyUploadedFiles = [];

try {
    $stmt = $pdo->prepare('SELECT * FROM novels WHERE id = ? LIMIT 1');
    $stmt->execute([$novelId]);
    $novel = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$novel) {
        http_response_code(404);
        exit('Novel tidak ditemukan.');
    }

    $chapter = null;
    if ($isEpisodeMode) {
        $stmt = $pdo->prepare(
            'SELECT * FROM chapters
             WHERE novel_id = ? AND chapter_number = ?
             LIMIT 1'
        );
        $stmt->execute([$novelId, $chapterNumber]);
        $chapter = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$chapter) {
            http_response_code(404);
            exit('Episode tidak ditemukan atau tidak termasuk novel ini.');
        }
    }
} catch (PDOException $exception) {
    http_response_code(500);
    exit('Terjadi kesalahan saat mengambil data.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['edit_token'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['edit_token'], $token)) {
        $error = 'Sesi formulir tidak valid. Muat ulang halaman, lalu coba lagi.';
    } else {
        try {
            // --- Cek standar naskah tanpa mengubah isi ---
            if (!$isEpisodeMode && ($_POST['action'] ?? '') === 'cek_standar') {
                $stmt = $pdo->prepare(
                    'SELECT chapter_number, content FROM chapters WHERE novel_id = ? ORDER BY chapter_number'
                );
                $stmt->execute([$novelId]);
                $chapters = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $sesuai = 0;
                $perlu = 0;
                $daftarPerlu = [];
                foreach ($chapters as $ch) {
                    $cek = cekNaskahSesuaiStandar((string)($ch['content'] ?? ''));
                    if ($cek['sesuai']) {
                        $sesuai++;
                    } else {
                        $perlu++;
                        $daftarPerlu[] = (int)$ch['chapter_number'];
                    }
                }
                $_SESSION['cek_standar_hasil'] = [
                    'total' => count($chapters),
                    'sesuai' => $sesuai,
                    'perlu' => $perlu,
                    'daftar' => $daftarPerlu,
                ];
                header('Location: edit.php?id=' . $novelId . '&cek_standar=1');
                exit;
            }

            // --- Rapikan episode yang belum sesuai saja; yang sudah sesuai tidak disentuh ---
            if (!$isEpisodeMode && ($_POST['action'] ?? '') === 'rapikan_semua') {
                $stmt = $pdo->prepare(
                    'SELECT chapter_number, content FROM chapters WHERE novel_id = ? ORDER BY chapter_number'
                );
                $stmt->execute([$novelId]);
                $chapters = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$chapters) {
                    throw new RuntimeException('Tidak ada episode untuk dirapikan.');
                }

                $pdo->beginTransaction();
                $upd = $pdo->prepare(
                    'UPDATE chapters SET content = ?, word_count = ? WHERE novel_id = ? AND chapter_number = ?'
                );
                $jumlah = 0;
                $dilewati = 0;
                foreach ($chapters as $ch) {
                    $lama = (string)($ch['content'] ?? '');
                    $cek = cekNaskahSesuaiStandar($lama);
                    if ($cek['sesuai']) {
                        $dilewati++;
                        continue; // Naskah yang sudah sesuai tidak diubah.
                    }
                    $baru = rapikanNaskahEpisode($lama);
                    if ($baru === '' || $baru === $lama) {
                        $dilewati++;
                        continue;
                    }
                    $wc = hitungKataEpisode($baru);
                    $upd->execute([$baru, $wc, $novelId, $ch['chapter_number']]);
                    $jumlah++;
                }
                $pdo->commit();
                $_SESSION['edit_token'] = bin2hex(random_bytes(32));
                header(
                    'Location: edit.php?id=' . $novelId . '&rapikan=1&n=' . $jumlah . '&skip=' . $dilewati
                );
                exit;
            }

            if ($isEpisodeMode) {
                $newChapterTitle = normalizeEpisodeTitle((string) ($_POST['chapter_title'] ?? ''));
                $newContent = $_POST['content'] ?? '';
                if (!is_string($newContent)) {
                    $newContent = '';
                }

                if ($newChapterTitle === '') {
                    throw new RuntimeException('Judul episode wajib diisi.');
                }
                if (mb_strlen($newChapterTitle, 'UTF-8') > 255) {
                    throw new RuntimeException('Judul episode maksimal 255 karakter.');
                }
                if (trim($newContent) === '') {
                    throw new RuntimeException('Isi cerita tidak boleh kosong.');
                }

                $oldChapterCover = $chapter['cover_path'] ?? null;
                $newChapterCover = $oldChapterCover;
                $removeChapterCover = isset($_POST['remove_chapter_cover']);

                $uploadedChapterCover = uploadCover('chapter_cover');
                if ($uploadedChapterCover !== null) {
                    $newlyUploadedFiles[] = $uploadedChapterCover;
                    $newChapterCover = $uploadedChapterCover;
                } elseif ($removeChapterCover) {
                    $newChapterCover = null;
                }

                $plainText = trim(strip_tags($newContent));
                preg_match_all('/[\p{L}\p{N}]+(?:[\'’-][\p{L}\p{N}]+)*/u', $plainText, $matches);
                $wordCount = count($matches[0]);

                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    'UPDATE chapters
                     SET title = ?, content = ?, word_count = ?, cover_path = ?
                     WHERE novel_id = ? AND chapter_number = ?'
                );
                $stmt->execute([
                    $newChapterTitle,
                    $newContent,
                    $wordCount,
                    $newChapterCover,
                    $novelId,
                    $chapterNumber,
                ]);

                $pdo->commit();

                if ($oldChapterCover && $oldChapterCover !== $newChapterCover) {
                    deleteCoverFile($oldChapterCover);
                }

                $_SESSION['edit_token'] = bin2hex(random_bytes(32));

                header(
                    'Location: read.php?id=' . $novelId .
                    '&chapter=' . $chapterNumber . '&edited=1'
                );
                exit;
            } else {
                $newNovelTitle = trim($_POST['novel_title'] ?? '');
                $newAuthor = trim($_POST['author'] ?? '');

                if ($newNovelTitle === '') {
                    throw new RuntimeException('Judul novel wajib diisi.');
                }
                if (mb_strlen($newNovelTitle, 'UTF-8') > 255) {
                    throw new RuntimeException('Judul novel maksimal 255 karakter.');
                }

                $oldNovelCover = $novel['cover_path'] ?? null;
                $newNovelCover = $oldNovelCover;
                $removeNovelCover = isset($_POST['remove_novel_cover']);

                $uploadedNovelCover = uploadCover('novel_cover');
                if ($uploadedNovelCover !== null) {
                    $newlyUploadedFiles[] = $uploadedNovelCover;
                    $newNovelCover = $uploadedNovelCover;
                } elseif ($removeNovelCover) {
                    $newNovelCover = null;
                }

                $pdo->beginTransaction();

                $hasAuthor = false;
                try {
                    $check = $pdo->query("SHOW COLUMNS FROM novels LIKE 'author'");
                    $hasAuthor = (bool) $check->fetch();
                } catch (Throwable $e) {
                    $hasAuthor = false;
                }

                if ($hasAuthor) {
                    $stmt = $pdo->prepare(
                        'UPDATE novels SET title = ?, author = ?, cover_path = ? WHERE id = ?'
                    );
                    $stmt->execute([
                        $newNovelTitle,
                        $newAuthor !== '' ? $newAuthor : null,
                        $newNovelCover,
                        $novelId,
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE novels SET title = ?, cover_path = ? WHERE id = ?'
                    );
                    $stmt->execute([$newNovelTitle, $newNovelCover, $novelId]);
                }

                $pdo->commit();

                if ($oldNovelCover && $oldNovelCover !== $newNovelCover) {
                    deleteCoverFile($oldNovelCover);
                }

                $_SESSION['edit_token'] = bin2hex(random_bytes(32));

                header('Location: read.php?id=' . $novelId . '&edited=1');
                exit;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            foreach ($newlyUploadedFiles as $uploadedPath) {
                deleteCoverFile($uploadedPath);
            }

            $error = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Perubahan gagal disimpan. Silakan coba lagi.';

            if ($isEpisodeMode) {
                $chapter['title'] = $newChapterTitle ?? $chapter['title'];
                $chapter['content'] = $newContent ?? $chapter['content'];
            } else {
                $novel['title'] = $newNovelTitle ?? $novel['title'];
                if (isset($newAuthor)) {
                    $novel['author'] = $newAuthor;
                }
            }
        }
    }
}

$displayChapterTitle = $isEpisodeMode ? normalizeEpisodeTitle((string) ($chapter['title'] ?? '')) : '';

$novelCover = coverUrl($novel['cover_path'] ?? null);
$chapterCover = $isEpisodeMode ? coverUrl($chapter['cover_path'] ?? null) : null;
$backUrl = $isEpisodeMode
    ? 'read.php?id=' . (int) $novelId . '&chapter=' . (int) $chapterNumber
    : 'read.php?id=' . (int) $novelId;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isEpisodeMode ? 'Edit Episode' : 'Edit Novel' ?> - <?= e($novel['title']) ?></title>

<link rel="stylesheet" href="assets/theme-system.css">
<script src="assets/theme.js"></script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,600;0,700;1,400&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Serif:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@400;500;600;700&family=Literata:ital,opsz,wght@0,7..72,400;0,7..72,600;0,7..72,700;1,7..72,400&family=Lora:ital,wght@0,400;0,600;0,700;1,400&family=Merriweather:ital,wght@0,400;0,700;1,400&family=Playfair+Display:wght@600;700&family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;0,8..60,700;1,8..60,400&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">

<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

html {
    height: 100%;
    overflow: hidden;
}

body {
    height: 100vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    font-family: 'Inter', system-ui, sans-serif;
    line-height: 1.5;
    background: var(--bg);
    color: var(--text);
    -webkit-font-smoothing: antialiased;
}

.header {
    background: var(--header-bg);
    color: var(--text);
    padding: 18px 24px;
    border-bottom: 1px solid var(--border);
}

.header-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-shrink: 0;
}

.episode-header-sub {
    display: block;
    font-size: 12px;
    color: var(--muted);
    margin-top: 2px;
    line-height: 1.35;
}

.episode-header-sub strong { color: var(--text-soft); }

.header-inner {
    max-width: 1100px;
    margin: 0 auto;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
}

.brand {
    display: flex;
    align-items: center;
    gap: 14px;
    text-decoration: none;
    color: inherit;
}

.logo-wrap {
    width: 52px;
    height: 52px;
    flex-shrink: 0;
}

.logo-wrap img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    border-radius: 50%;
    filter: drop-shadow(0 0 8px rgba(0,180,255,.35));
}

.brand-text h1 {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 20px;
    font-weight: 700;
}

.brand-text span {
    display: block;
    font-size: 12px;
    color: var(--muted);
    margin-top: 1px;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px 16px;
    border-radius: 9px;
    text-decoration: none;
    font-size: 13.5px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all .2s;
    font-family: inherit;
}

.btn-ghost {
    background: rgba(255,255,255,.08);
    color: var(--text);
    border: 1px solid var(--border);
}

.btn-ghost:hover { background: rgba(255,255,255,.14); }

.btn-primary {
    background: var(--accent);
    color: #fff;
}

.btn-primary:hover { background: var(--accent-hover); }

.btn-cancel {
    background: var(--surface-2);
    color: var(--text);
    border: 1px solid var(--border);
}

.container {
    width: 100%;
    max-width: 1100px;
    flex: 1;
    min-height: 0;
    overflow: hidden;
    margin: 18px auto;
    padding: 0 20px 12px;
    display: flex;
    flex-direction: column;
}

.page-title {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 26px;
    font-weight: 700;
    margin-bottom: 4px;
}

.page-sub {
    color: var(--muted);
    font-size: 14px;
    margin-bottom: 22px;
}

.alert-error {
    padding: 14px 16px;
    border-radius: 10px;
    margin-bottom: 18px;
    font-size: 14px;
    background: rgba(248,113,113,.12);
    border: 1px solid rgba(248,113,113,.25);
    color: #f87171;
}

/* Layout 2 kolom: halaman tetap, hanya kolom isi cerita yang bisa di-scroll */
main.container > form {
    flex: 1;
    min-height: 0;
    overflow: hidden;
}

.layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 260px;
    gap: 20px;
    align-items: stretch;
    height: 100%;
    min-height: 0;
}

.panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 22px;
    box-shadow: var(--shadow);
    min-height: 0;
    overflow: hidden;
}

.layout > .panel:first-child {
    display: flex;
    flex-direction: column;
}

.layout > .panel:first-child textarea,
.layout > .panel:first-child .rich-editor {
    flex: 1;
    min-height: 0;
    max-height: none;
}

.panel-side {
    position: sticky;
    top: 20px;
}

.episode-preview {
    margin-bottom: 16px;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface-2);
    color: var(--text-soft);
    font-size: 13.5px;
    line-height: 1.55;
}

.episode-preview strong {
    color: var(--accent);
}

label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--text-soft);
}

input[type="text"],
textarea {
    width: 100%;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 11px 13px;
    color: var(--text);
    font: inherit;
    margin-bottom: 16px;
    transition: border-color .2s, box-shadow .2s;
}

input[type="text"]:focus,
textarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(124,140,255,.15);
}

textarea {
    min-height: 0;
    max-height: none;
    line-height: 1.8;
    resize: none;
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 16px;
    overflow-y: auto;
}

.rich-editor {
    width: 100%;
    flex: 1;
    min-height: 0;
    max-height: none;
    overflow-y: auto;
    resize: none;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 11px 13px;
    color: var(--text);
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 16px;
    line-height: 1.8;
    margin-bottom: 12px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    outline: none;
}
.rich-editor:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(124,140,255,.15);
}
.rich-editor:empty::before {
    content: attr(data-placeholder);
    color: var(--muted);
    pointer-events: none;
}
.rich-editor p {
    margin: 0 0 8px;
    text-indent: 0 !important;
    line-height: 1.65;
    text-align: justify;
}
.rich-editor > p:first-child { text-indent: 0 !important; }
.toolbar-separator { height: 25px; border-left: 1px solid var(--border); margin: 0 2px; }
.color-tool {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 32px;
    border: 1px solid var(--border);
    border-radius: 7px;
    background: var(--surface-2);
    color: var(--text);
    margin: 0;
    cursor: pointer;
    overflow: hidden;
    font-size: 16px;
}
.color-tool input[type="color"] {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
    padding: 0;
    border: 0;
}
.color-tool:hover { border-color: var(--accent); }
@media (max-width: 700px) {
    .format-tools { gap: 5px; }
    .btn-format, .color-tool { width: 31px; height: 30px; }
}

.editor-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding-top: 2px;
}

.word-count {
    font-size: 12.5px;
    color: var(--muted);
    margin: 0;
    white-space: nowrap;
}

.format-tools {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
}

.format-label {
    margin: 0;
    font-size: 12px;
    white-space: nowrap;
}

.format-tools {
    gap: 5px;
}

.btn-format {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--text);
    border-radius: 7px;
    font: inherit;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
}
.btn-format:hover, .btn-format:focus-visible {
    border-color: var(--accent);
    outline: 2px solid transparent;
}

.actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    flex-wrap: wrap;
}

.top-actions {
    margin-bottom: 20px;
}

/* Sidebar sampul */
.cover-label {
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 10px;
    color: var(--text-soft);
}

.cover-preview {
    display: block;
    width: 100%;
    aspect-ratio: 4 / 5;
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid var(--border);
    margin-bottom: 12px;
    background: var(--surface-2);
}

.cover-empty {
    display: none;
}

input[type="file"] {
    display: block;
    width: 100%;
    color: var(--muted);
    font-size: 12px;
    margin: 4px 0;
}

.muted {
    color: var(--muted);
    font-size: 12px;
    margin-bottom: 8px;
    line-height: 1.45;
}

.checkline {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    color: #f87171;
    font-size: 12.5px;
    margin-top: 10px;
    line-height: 1.4;
}

.checkline input {
    accent-color: #f87171;
    margin-top: 2px;
    flex-shrink: 0;
}

@media (max-width: 800px) {
    .header {
        padding: 10px 14px;
        flex-shrink: 0;
    }

    .container {
        margin: 10px auto;
        padding: 0 12px 8px;
    }

    .page-title {
        font-size: 21px;
    }

    .page-sub {
        margin-bottom: 10px;
    }

    .layout {
        grid-template-columns: minmax(0, 1fr) 190px;
        gap: 12px;
    }

    .panel {
        padding: 14px;
    }

    .panel-side {
        position: static;
    }

    .cover-preview,
    .cover-empty {
        max-width: 150px;
        margin-left: auto;
        margin-right: auto;
    }

    textarea {
        min-height: 0;
        max-height: none;
    }
}

@media (max-width: 600px) {
    .header-inner {
        gap: 8px;
        align-items: flex-start;
    }

    .header-actions {
        gap: 5px;
    }

    .header-actions .btn {
        padding: 7px 8px;
        font-size: 11px;
    }

    .episode-header-sub {
        max-width: 42vw;
        font-size: 10px;
    }

    .brand {
        gap: 8px;
    }

    .brand-text h1 {
        font-size: 16px;
    }

    .logo-wrap {
        width: 36px;
        height: 36px;
    }

    .layout {
        grid-template-columns: minmax(0, 1fr);
    }

    .layout > .panel-side {
        display: none;
    }

    .panel {
        padding: 12px;
    }

    .top-actions {
        margin-bottom: 10px;
    }

    .page-title {
        font-size: 20px;
    }
}

@media (max-width: 700px) {
    .editor-footer { align-items: flex-start; }
    .format-tools { width: 100%; }
    .format-tools { gap: 4px; }
}

</style>
</head>
<body>

<header class="header">
    <div class="header-inner">
        <a href="index.php" class="brand">
            <div class="logo-wrap">
                <img src="logo.png" alt="SAB Logo">
            </div>
            <div class="brand-text">
                <h1>SAB Novel Library</h1>
                <?php if ($isEpisodeMode): ?>
                    <span class="episode-header-sub">✏️ Edit Episode · Novel: <strong><?= e($novel['title']) ?></strong> · Episode <?= (int) $chapterNumber ?></span>
                <?php else: ?>
                    <span>Edit Novel</span>
                <?php endif; ?>
            </div>
        </a>
        <div class="header-actions">
            <div id="theme-switcher-mount"></div>
            <?php if ($isEpisodeMode): ?>
                <a href="<?= e($backUrl) ?>" class="btn btn-cancel">Batal</a>
                <button class="btn btn-primary" type="submit" form="episode-edit-form">💾 Simpan</button>
            <?php else: ?>
                <a href="<?= e($backUrl) ?>" class="btn btn-ghost">← Kembali</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<main class="container">
    <?php if (!$isEpisodeMode): ?>
        <h1 class="page-title">⚙ Edit Novel</h1>
        <p class="page-sub">Perbarui judul, penulis, dan sampul novel.</p>
        <?php if (isset($_GET['rapikan'])): ?>
            <div style="padding:14px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.3);color:#34d399;">
                ✅ Selesai. <?= (int) ($_GET['n'] ?? 0) ?> episode dirapikan; <?= (int) ($_GET['skip'] ?? 0) ?> episode dilewati karena sudah sesuai atau tidak memerlukan perubahan.
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['cek_standar'])): $hasilCek = $_SESSION['cek_standar_hasil'] ?? null; unset($_SESSION['cek_standar_hasil']); ?>
            <div style="padding:14px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;background:rgba(96,165,250,.10);border:1px solid rgba(96,165,250,.3);color:var(--text,#dbeafe);">
                <strong>🔎 Hasil cek standar naskah</strong><br>
                Total episode: <?= (int)($hasilCek['total'] ?? 0) ?> ·
                Terindikasi sesuai: <?= (int)($hasilCek['sesuai'] ?? 0) ?> ·
                Perlu diperiksa/dirapikan: <?= (int)($hasilCek['perlu'] ?? 0) ?>.
                <?php if (!empty($hasilCek['daftar'])): ?><br><span>Nomor episode yang perlu diperiksa: <?= e(implode(', ', $hasilCek['daftar'])) ?>.</span><?php endif; ?>
                <br><small>Pemeriksaan otomatis ini menilai struktur paragraf, indentasi, spasi, dan tanda baca dasar; bukan pemeriksaan semantik penuh KBBI/PUEBI.</small>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($isEpisodeMode): ?>
        <form id="episode-edit-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?= (int) $novelId ?>">
            <input type="hidden" name="chapter" value="<?= (int) $chapterNumber ?>">
            <input type="hidden" name="edit_token" value="<?= e($_SESSION['edit_token']) ?>">

            <div class="layout">
                <div class="panel">
                    <label for="chapter_title">Judul</label>
                    <input
                        type="text"
                        id="chapter_title"
                        name="chapter_title"
                        maxlength="255"
                        required
                        value="<?= e($displayChapterTitle) ?>"
                        placeholder="Tulis judul saja, tanpa nomor episode"
                    >

                    <label for="content">Isi Cerita</label>
                    <textarea id="content" name="content" hidden required><?= e($chapter['content'] ?? '') ?></textarea>
                    <div id="contentEditor" class="rich-editor" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Tuliskan isi cerita di sini..."></div>
                    <div class="editor-footer">
                        <p class="word-count"><span id="words">0</span> kata</p>
                        <div class="format-tools" aria-label="Alat perapian dan format teks">
                            <button type="button" class="btn-format" data-action="undo" title="Urungkan perubahan (Undo)" aria-label="Undo">↶</button>
                            <button type="button" class="btn-format" data-action="redo" title="Ulangi perubahan (Redo)" aria-label="Redo">↷</button>
                            <button type="button" class="btn-format" data-action="copy" title="Salin seluruh isi cerita" aria-label="Salin isi cerita">▢</button>
                            <button type="button" class="btn-format" data-action="selectAll" title="Pilih seluruh isi cerita" aria-label="Pilih semua">▤</button>
                            <span class="toolbar-separator"></span>
                            <button type="button" class="btn-format" data-command="bold" title="Tebal (Ctrl+B)" aria-label="Tebal"><b>B</b></button>
                            <button type="button" class="btn-format" data-command="italic" title="Miring (Ctrl+I)" aria-label="Miring"><i>I</i></button>
                            <button type="button" class="btn-format" data-command="underline" title="Garis bawah (Ctrl+U)" aria-label="Garis bawah"><u>U</u></button>
                            <button type="button" class="btn-format highlight-button" data-command="highlight" title="Stabilo kuning pada teks yang dipilih" aria-label="Stabilo kuning">🖍</button>
                            <label class="color-tool" title="Pilih warna stabilo">
                                <span aria-hidden="true">▰</span><input type="color" id="highlightColor" value="#fff176" aria-label="Warna stabilo">
                            </label>
                            <label class="color-tool" title="Pilih warna huruf">
                                <span aria-hidden="true">A</span><input type="color" id="textColor" value="#d32f2f" aria-label="Warna huruf">
                            </label>
                            <button type="button" class="btn-format" data-command="clearFormat" title="Hapus format dari teks terpilih" aria-label="Hapus format">Tx</button>
                            <span class="toolbar-separator"></span>
                            <button type="button" class="btn-format" data-format="paragraphs" title="Rapikan paragraf" aria-label="Rapikan paragraf">¶</button>
                            <button type="button" class="btn-format" data-format="indent" title="Rapikan paragraf blok (tanpa indentasi)" aria-label="Paragraf blok">¶↳</button>
                            <button type="button" class="btn-format" data-format="sentence" title="Kapital awal kalimat" aria-label="Kapital awal kalimat">Aa</button>
                            <button type="button" class="btn-format" data-format="upper" title="Ubah menjadi HURUF BESAR" aria-label="Ubah menjadi huruf besar">A↑</button>
                            <button type="button" class="btn-format" data-format="lower" title="Ubah menjadi huruf kecil" aria-label="Ubah menjadi huruf kecil">a↓</button>
                            <button type="button" class="btn-format" data-format="title" title="Kapital Tiap Kata" aria-label="Kapital tiap kata">Tt</button>
                            <button type="button" class="btn-format" data-format="spaces" title="Hapus spasi berlebih" aria-label="Hapus spasi berlebih">↔</button>
                            <button type="button" class="btn-format" data-format="breaks" title="Rapikan baris menjadi paragraf" aria-label="Rapikan baris menjadi paragraf">↵</button>
                        </div>
                    </div>

                </div>

                <aside class="panel panel-side">
                    <div class="cover-label">Sampul Episode</div>
                    <?php if ($chapterCover): ?>
                        <img
                            class="cover-preview"
                            id="chapterCoverPreview"
                            src="<?= e($chapterCover) ?>"
                            alt="Sampul episode"
                        >
                    <?php else: ?>
                        <img class="cover-preview" id="chapterCoverPreview" alt="Pratinjau sampul episode" hidden>
                    <?php endif; ?>

                    <input
                        type="file"
                        name="chapter_cover"
                        id="chapter_cover"
                        accept="image/jpeg,image/png,image/webp"
                    >
                    <p class="muted">JPG, PNG, WebP · Maks. 5 MB · Rasio 4:5</p>

                    <?php if ($chapterCover): ?>
                        <label class="checkline">
                            <input type="checkbox" name="remove_chapter_cover" value="1">
                            Hapus sampul saat disimpan
                        </label>
                    <?php endif; ?>
                </aside>
            </div>
        </form>

    <?php else: ?>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?= (int) $novelId ?>">
            <input type="hidden" name="edit_token" value="<?= e($_SESSION['edit_token']) ?>">

            <div class="layout">
                <div class="panel">
                    <label for="novel_title">Judul Novel</label>
                    <input
                        type="text"
                        id="novel_title"
                        name="novel_title"
                        maxlength="255"
                        required
                        value="<?= e($novel['title'] ?? '') ?>"
                        placeholder="Judul novel"
                    >

                    <label for="author">Penulis</label>
                    <input
                        type="text"
                        id="author"
                        name="author"
                        maxlength="255"
                        value="<?= e($novel['author'] ?? '') ?>"
                        placeholder="Nama penulis (opsional)"
                    >

                    <div class="actions" style="margin-top:8px;justify-content:space-between;width:100%;">
                        <button
                            class="btn btn-ghost"
                            type="submit"
                            name="action"
                            value="cek_standar"
                            formaction=""
                            title="Periksa standar penulisan tanpa mengubah isi episode"
                        >🔎 Cek Standar Novel</button>
                        <button
                            class="btn btn-ghost"
                            type="submit"
                            name="action"
                            value="rapikan_semua"
                            formaction=""
                            onclick="return confirm('Rapikan SEMUA episode novel ini?\n\n• Paragraf dirapikan\n• Spasi & tanda baca dibersihkan\n• Indentasi paragraf dibatasi maksimal 1em\n• Episode yang sudah sesuai akan dilewati\n\nHanya episode yang terindikasi perlu dirapikan yang akan diubah.');"
                            title="Rapikan tulisan seluruh episode"
                        >✨ Rapikan Semua Episode</button>
                        <div style="display:flex;gap:10px;">
                            <a class="btn btn-cancel" href="<?= e($backUrl) ?>">Batal</a>
                            <button class="btn btn-primary" type="submit">💾 Simpan Novel</button>
                        </div>
                    </div>
                </div>

                <aside class="panel panel-side">
                    <div class="cover-label">Sampul Novel</div>
                    <?php if ($novelCover): ?>
                        <img
                            class="cover-preview"
                            id="novelCoverPreview"
                            src="<?= e($novelCover) ?>"
                            alt="Sampul novel"
                        >
                    <?php else: ?>
                        <img class="cover-preview" id="novelCoverPreview" alt="Pratinjau sampul novel" hidden>
                    <?php endif; ?>

                    <input
                        type="file"
                        name="novel_cover"
                        id="novel_cover"
                        accept="image/jpeg,image/png,image/webp"
                    >
                    <p class="muted">JPG, PNG, WebP · Maks. 5 MB · Rasio 4:5</p>

                    <?php if ($novelCover): ?>
                        <label class="checkline">
                            <input type="checkbox" name="remove_novel_cover" value="1">
                            Hapus sampul saat disimpan
                        </label>
                    <?php endif; ?>
                </aside>
            </div>
        </form>
    <?php endif; ?>
</main>

<script>
(() => {
    function setupPreview(inputId, previewId) {
        const input = document.getElementById(inputId);
        const preview = document.getElementById(previewId);
        if (!input || !preview) return;

        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            if (!file) return;

            if (!file.type.startsWith('image/')) {
                alert('Pilih file gambar yang valid.');
                input.value = '';
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                alert('Ukuran gambar maksimal 5 MB.');
                input.value = '';
                return;
            }

            if (preview.dataset.objectUrl) {
                URL.revokeObjectURL(preview.dataset.objectUrl);
            }
            preview.dataset.objectUrl = URL.createObjectURL(file);
            preview.src = preview.dataset.objectUrl;
            preview.hidden = false;
        });
    }

    setupPreview('novel_cover', 'novelCoverPreview');
    setupPreview('chapter_cover', 'chapterCoverPreview');

    const content = document.getElementById('content'); // hidden form field
    const editor = document.getElementById('contentEditor');
    const wordsEl = document.getElementById('words');
    const form = document.getElementById('episode-edit-form');

    if (content && editor) {
        // Load prior plain text safely, or preserve supported formatting HTML.
        function escapeHtml(value) {
            return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
        function sanitizeHtml(html) {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const allowed = new Set(['B','STRONG','I','EM','U','S','STRIKE','BR','P','DIV','SPAN','MARK']);
            doc.body.querySelectorAll('*').forEach(el => {
                if (!allowed.has(el.tagName)) {
                    el.replaceWith(...el.childNodes);
                    return;
                }
                [...el.attributes].forEach(attr => {
                    if (attr.name !== 'style') el.removeAttribute(attr.name);
                });
                if (el.hasAttribute('style')) {
                    const color = el.style.color;
                    const bg = el.style.backgroundColor;
                    el.removeAttribute('style');
                    if (color && /^(#[0-9a-f]{3,8}|rgb\(|rgba\(|[a-z]+$)/i.test(color)) el.style.color = color;
                    if (bg && /^(#[0-9a-f]{3,8}|rgb\(|rgba\(|[a-z]+$)/i.test(bg)) el.style.backgroundColor = bg;
                    if (!el.getAttribute('style')) el.removeAttribute('style');
                }
            });
            return doc.body.innerHTML;
        }
        const initial = content.value || '';
        if (/<(?:b|strong|i|em|u|s|strike|br|p|div|span|mark)\b/i.test(initial)) {
            editor.innerHTML = sanitizeHtml(initial);
        } else {
            editor.innerHTML = (function(text) {
            const blocks = text.replace(/\r\n?/g, '\n')
                .split(/\n\s*\n+/)
                .map(p => p.replace(/[ \t]+/g, ' ').replace(/\n/g, ' ').trim())
                .filter(Boolean);
            return blocks.map(p => {
                const heading = /^(?:BAB\s+\d+|Bab\s+\d+|CHAPTER\s+\d+|Chapter\s+\d+|EPISODE\s+\d+|Episode\s+\d+|#\s|\d+\.\s)/u.test(p);
                if (heading) return '<p style="text-indent:0 !important;margin:1.1em 0 0.45em;font-weight:600">' + escapeHtml(p) + '</p>';
                return '<p style="text-indent:' + indent + ' !important;margin:0 0 0.55em;text-align:justify">' + escapeHtml(p) + '</p>';
            }).join('');
        })(initial);
        }

        const undoStack = [editor.innerHTML];
        const redoStack = [];
        let lastValue = editor.innerHTML;
        let restoringHistory = false;
        let savedRange = null;
        const maxHistory = 150;

        function syncContent() {
            content.value = editor.innerHTML;
        }
        function countWords() {
            const text = (editor.innerText || editor.textContent || '').trim();
            const count = text ? (text.match(/[\p{L}\p{N}]+(?:['’-][\p{L}\p{N}]+)*/gu) || []).length : 0;
            if (wordsEl) wordsEl.textContent = count.toLocaleString('id-ID');
        }
        function rememberCurrentValue() {
            syncContent();
            countWords();
            if (restoringHistory || editor.innerHTML === lastValue) return;
            undoStack.push(editor.innerHTML);
            if (undoStack.length > maxHistory) undoStack.shift();
            lastValue = editor.innerHTML;
            redoStack.length = 0;
        }
        function restoreValue(value) {
            restoringHistory = true;
            editor.innerHTML = value;
            lastValue = value;
            syncContent();
            countWords();
            restoringHistory = false;
            editor.focus();
        }
        function saveSelection() {
            const sel = window.getSelection();
            if (sel && sel.rangeCount && editor.contains(sel.anchorNode) && editor.contains(sel.focusNode)) {
                savedRange = sel.getRangeAt(0).cloneRange();
            }
        }
        function restoreSelection() {
            if (!savedRange) return;
            editor.focus();
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(savedRange);
        }
        function applyCommand(command, value = null) {
            restoreSelection();
            if (command === 'highlight') {
                document.execCommand('hiliteColor', false, value || '#fff176');
                // Some browsers use backColor instead of hiliteColor.
                if (!window.getSelection()?.toString()) document.execCommand('backColor', false, value || '#fff176');
            } else if (command === 'clearFormat') {
                document.execCommand('removeFormat', false, null);
                document.execCommand('unlink', false, null);
            } else {
                document.execCommand(command, false, value);
            }
            saveSelection();
            rememberCurrentValue();
        }

        editor.addEventListener('input', rememberCurrentValue);
        editor.addEventListener('keyup', saveSelection);
        editor.addEventListener('mouseup', saveSelection);
        editor.addEventListener('touchend', saveSelection);
        document.addEventListener('selectionchange', saveSelection);
        editor.addEventListener('paste', event => {
            event.preventDefault();
            const text = (event.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, text);
        });
        if (form) form.addEventListener('submit', () => syncContent());

        document.querySelectorAll('[data-command]').forEach(button => {
            button.addEventListener('mousedown', event => event.preventDefault());
            button.addEventListener('click', () => applyCommand(button.dataset.command));
        });
        document.querySelectorAll('[data-action]').forEach(button => button.addEventListener('click', async () => {
            const action = button.dataset.action;
            if (action === 'undo' && undoStack.length > 1) {
                redoStack.push(undoStack.pop());
                restoreValue(undoStack[undoStack.length - 1]);
            } else if (action === 'redo' && redoStack.length) {
                const value = redoStack.pop();
                undoStack.push(value);
                restoreValue(value);
            } else if (action === 'selectAll') {
                editor.focus();
                const range = document.createRange();
                range.selectNodeContents(editor);
                const sel = window.getSelection();
                sel.removeAllRanges(); sel.addRange(range); saveSelection();
            } else if (action === 'copy') {
                try {
                    await navigator.clipboard.writeText(editor.innerText || editor.textContent || '');
                    button.title = 'Teks berhasil disalin';
                    setTimeout(() => { button.title = 'Salin seluruh isi cerita'; }, 1500);
                } catch (error) {
                    editor.focus();
                    document.execCommand('copy');
                }
            }
        }));

        const highlightColor = document.getElementById('highlightColor');
        if (highlightColor) {
            highlightColor.addEventListener('input', () => {
                if (savedRange && !savedRange.collapsed) applyCommand('highlight', highlightColor.value);
            });
        }
        const textColor = document.getElementById('textColor');
        if (textColor) {
            textColor.addEventListener('mousedown', saveSelection);
            textColor.addEventListener('input', () => {
                if (savedRange && !savedRange.collapsed) applyCommand('foreColor', textColor.value);
            });
        }

        function textToIndentedHtml(text) {
            const parts = text
                .replace(/\r\n?/g, '\n')
                .split(/\n\s*\n+/)
                .map(p => p.replace(/[ \t]+/g, ' ').replace(/\n/g, ' ').trim())
                .filter(Boolean);
            if (!parts.length) return '';
            return parts.map(p => {
                const isHeading = /^(?:BAB\s+\d+|Bab\s+\d+|CHAPTER\s+\d+|Chapter\s+\d+|EPISODE\s+\d+|Episode\s+\d+|#\s|\d+\.\s)/u.test(p);
                const safe = escapeHtml(p);
                if (isHeading) return '<p style="text-indent:0 !important;margin:1.1em 0 0.45em;font-weight:600">' + safe + '</p>';
                return '<p style="text-indent:0 !important;margin:0 0 8px;line-height:1.65;text-align:justify">' + safe + '</p>';
            }).join('');
        }

        document.querySelectorAll('[data-format]').forEach(button => button.addEventListener('click', () => {
            const action = button.dataset.format;
            const originalHtml = editor.innerHTML;
            let text = editor.innerText || editor.textContent || '';
            if (action === 'paragraphs') {
                text = text.replace(/\r\n?/g, '\n').split(/\n\s*\n+/).map(p => p.replace(/[ \t]+/g, ' ').trim()).filter(Boolean).join('\n\n');
            } else if (action === 'sentence') {
                text = text.toLocaleLowerCase('id-ID').replace(/(^|[.!?]\s+)([\p{L}])/gu, (m, lead, letter) => lead + letter.toLocaleUpperCase('id-ID'));
            } else if (action === 'upper') {
                text = text.toLocaleUpperCase('id-ID');
            } else if (action === 'lower') {
                text = text.toLocaleLowerCase('id-ID');
            } else if (action === 'title') {
                text = text.toLocaleLowerCase('id-ID').replace(/(^|[\s([{“"'—–-])([\p{L}])/gu, (m, lead, letter) => lead + letter.toLocaleUpperCase('id-ID'));
            } else if (action === 'spaces') {
                text = text.replace(/\r\n?/g, '\n').split('\n').map(line => line.replace(/[ \t]+/g, ' ').trim()).join('\n').replace(/\n{3,}/g, '\n\n').trim();
            } else if (action === 'breaks') {
                text = text.replace(/\r\n?/g, '\n').split('\n').map(line => line.trim()).filter(Boolean).join('\n\n');
            } else if (action === 'indent') {
                text = text.replace(/\r\n?/g, '\n').replace(/[ \t]+/g, ' ').trim();
            }
            const useParagraphHtml = (action === 'paragraphs' || action === 'breaks' || action === 'indent');
            const nextHtml = useParagraphHtml
                ? textToIndentedHtml(text)
                : escapeHtml(text).replace(/\r\n?/g, '\n').replace(/\n/g, '<br>');
            if (nextHtml && nextHtml !== originalHtml) {
                if (undoStack[undoStack.length - 1] !== originalHtml) undoStack.push(originalHtml);
                if (undoStack.length > maxHistory) undoStack.shift();
                redoStack.length = 0;
                editor.innerHTML = nextHtml;
                lastValue = editor.innerHTML;
                undoStack.push(editor.innerHTML);
                if (undoStack.length > maxHistory) undoStack.shift();
                syncContent(); countWords();
            }
            editor.focus();
        }));

        syncContent();
        countWords();
    }
})();
</script>
</body>
</html>
