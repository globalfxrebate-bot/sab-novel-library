
<?php
session_start();
require_once __DIR__ . '/config/database.php';

// Hanya izinkan penghapusan melalui metode POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// Buat token keamanan untuk formulir.
if (empty($_SESSION['delete_token'])) {
    $_SESSION['delete_token'] = bin2hex(random_bytes(32));
}

$token = $_POST['token'] ?? '';

if (
    !is_string($token) ||
    !hash_equals($_SESSION['delete_token'], $token)
) {
    http_response_code(403);
    exit('Permintaan tidak valid. Silakan kembali dan coba lagi.');
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header('Location: index.php');
    exit;
}

try {
    $stmt = $pdo->prepare('DELETE FROM novels WHERE id = :id');
    $stmt->execute(['id' => $id]);

    // Perbarui token agar formulir lama tidak dapat digunakan ulang.
    $_SESSION['delete_token'] = bin2hex(random_bytes(32));

    header('Location: index.php?deleted=' . ($stmt->rowCount() ? '1' : '0'));
    exit;
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('Novel gagal dihapus. Silakan periksa database.');
}
