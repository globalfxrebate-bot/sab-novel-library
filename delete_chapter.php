
<?php
session_start();

require_once __DIR__ . '/config/database.php';

// Pastikan request berasal dari pengguna yang membuka aplikasi
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// Ambil parameter novel dan nomor bab
$novelId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$chapterNumber = filter_input(INPUT_POST, 'chapter', FILTER_VALIDATE_INT);

if (!$novelId || !$chapterNumber || $novelId < 1 || $chapterNumber < 1) {
    http_response_code(400);
    exit('ID novel atau nomor bab tidak valid.');
}

// Validasi token CSRF.
// Gunakan nama session yang sama dengan form hapus di read.php.
$sessionToken = $_SESSION['delete_chapter_token'] ?? '';
$postToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($sessionToken) ||
    !is_string($postToken) ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $postToken)
) {
    http_response_code(403);
    exit('Token keamanan tidak valid. Muat ulang halaman, lalu coba lagi.');
}

try {
    // Cari bab berdasarkan ID novel dan NOMOR bab.
    $stmt = $pdo->prepare(
        'SELECT id, novel_id, chapter_number
         FROM chapters
         WHERE novel_id = ? AND chapter_number = ?
         LIMIT 1'
    );
    $stmt->execute([$novelId, $chapterNumber]);

    $chapter = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$chapter) {
        http_response_code(404);
        exit('Bab tidak ditemukan atau tidak termasuk novel ini.');
    }

    // Hapus menggunakan kombinasi novel_id dan chapter_number.
    $delete = $pdo->prepare(
        'DELETE FROM chapters
         WHERE novel_id = ? AND chapter_number = ?
         LIMIT 1'
    );
    $delete->execute([$novelId, $chapterNumber]);

    if ($delete->rowCount() !== 1) {
        http_response_code(500);
        exit('Bab gagal dihapus. Silakan coba lagi.');
    }

    // Regenerasi token setelah operasi berhasil.
    $_SESSION['delete_chapter_token'] = bin2hex(random_bytes(32));

    // Kembali ke novel yang sama.
    header('Location: read.php?id=' . $novelId);
    exit;

} catch (PDOException $e) {
    error_log('Delete chapter error: ' . $e->getMessage());

    http_response_code(500);
    exit('Terjadi kesalahan database saat menghapus bab.');
}
