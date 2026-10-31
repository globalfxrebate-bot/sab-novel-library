<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['create_token'])) {
    $_SESSION['create_token'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$author = 'SUYATNO ABU BAKAR';
$title = '';
$chapterTitle = '';
$content = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['create_token'] ?? '';

    if (
        !is_string($token) ||
        !isset($_SESSION['create_token']) ||
        !hash_equals($_SESSION['create_token'], $token)
    ) {
        $error = 'Sesi formulir tidak valid. Muat ulang halaman.';
    } else {
        $author = trim((string) ($_POST['author'] ?? ''));
        if ($author === '') { $author = 'SUYATNO ABU BAKAR'; }
        $title = trim((string) ($_POST['title'] ?? ''));
        $chapterTitle = trim((string) ($_POST['chapter_title'] ?? ''));
        $content = (string) ($_POST['content'] ?? '');

        if ($author === '') {
            $author = 'SUYATNO ABU BAKAR';
        }
        if (mb_strlen($author) > 255) {
            $error = 'Nama penulis maksimal 255 karakter.';
        } elseif ($title === '') {
            $error = 'Judul novel wajib diisi.';
        } elseif (mb_strlen($title) > 255) {
            $error = 'Judul novel maksimal 255 karakter.';
        } elseif ($chapterTitle === '') {
            $error = 'Judul bab pertama wajib diisi.';
        } elseif (mb_strlen($chapterTitle) > 255) {
            $error = 'Judul bab maksimal 255 karakter.';
        } else {
            $coverPath = null;
            $uploadedPath = null;

            try {
                /*
                 * Upload sampul novel.
                 */
                if (
                    isset($_FILES['cover']) &&
                    $_FILES['cover']['error'] !== UPLOAD_ERR_NO_FILE
                ) {
                    if ($_FILES['cover']['error'] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException(
                            'Upload sampul gagal.'
                        );
                    }

                    if ($_FILES['cover']['size'] > 5 * 1024 * 1024) {
                        throw new RuntimeException(
                            'Ukuran sampul maksimal 5 MB.'
                        );
                    }

                    if (
                        !is_uploaded_file(
                            $_FILES['cover']['tmp_name']
                        )
                    ) {
                        throw new RuntimeException(
                            'File sampul tidak valid.'
                        );
                    }

                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file(
                        $_FILES['cover']['tmp_name']
                    );

                    $types = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/webp' => 'webp',
                    ];

                    if (!isset($types[$mime])) {
                        throw new RuntimeException(
                            'Sampul harus JPG, PNG, atau WebP.'
                        );
                    }

                    $directory = __DIR__ . '/uploads/covers/';

                    if (
                        !is_dir($directory) &&
                        !mkdir($directory, 0755, true) &&
                        !is_dir($directory)
                    ) {
                        throw new RuntimeException(
                            'Folder sampul tidak dapat dibuat.'
                        );
                    }

                    $filename = bin2hex(random_bytes(16))
                        . '.'
                        . $types[$mime];

                    $destination = $directory . $filename;

                    if (
                        !move_uploaded_file(
                            $_FILES['cover']['tmp_name'],
                            $destination
                        )
                    ) {
                        throw new RuntimeException(
                            'Sampul gagal disimpan.'
                        );
                    }

                    $coverPath = 'uploads/covers/' . $filename;
                    $uploadedPath = $destination;
                }

                /*
                 * Hitung jumlah kata.
                 */
                preg_match_all(
                    '/[\p{L}\p{N}]+(?:[\'’-][\p{L}\p{N}]+)*/u',
                    trim(strip_tags($content)),
                    $matches
                );

                $wordCount = count($matches[0]);

                /*
                 * Judul bab otomatis:
                 * Episode 1 — Judul yang ditulis penulis
                 *
                 * Penulis tidak perlu mengetik "Episode 1".
                 */
                $finalChapterTitle = 'Episode 1 — ' . $chapterTitle;

                $pdo->beginTransaction();

                /*
                 * Simpan novel beserta nama penulis.
                 */
                $stmt = $pdo->prepare(
                    'INSERT INTO novels
                        (title, author, cover_path)
                     VALUES (?, ?, ?)'
                );

                $stmt->execute([
                    $title,
                    $author,
                    $coverPath
                ]);

                $novelId = (int) $pdo->lastInsertId();

                /*
                 * Simpan bab pertama.
                 */
                $stmt = $pdo->prepare(
                    'INSERT INTO chapters
                        (
                            novel_id,
                            chapter_number,
                            title,
                            content,
                            word_count,
                            cover_path
                        )
                     VALUES (?, ?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $novelId,
                    1,
                    $finalChapterTitle,
                    $content,
                    $wordCount,
                    null
                ]);

                $pdo->commit();

                /*
                 * Perbarui token setelah berhasil.
                 */
                $_SESSION['create_token'] = bin2hex(
                    random_bytes(32)
                );

                header(
                    'Location: read.php?id='
                    . $novelId
                    . '&chapter=1'
                );

                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if (
                    $uploadedPath !== null &&
                    is_file($uploadedPath)
                ) {
                    @unlink($uploadedPath);
                }

                if ($exception instanceof RuntimeException) {
                    $error = $exception->getMessage();
                } else {
                    error_log(
                        'Gagal membuat novel: '
                        . $exception->getMessage()
                    );

                    $error = 'Novel gagal dibuat. '
                        . 'Periksa struktur tabel database '
                        . 'dan log kesalahan server.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Buat Novel Baru - SAB Novel Library</title>

<link rel="stylesheet" href="assets/theme-system.css">
<script src="assets/theme.js"></script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">

<style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
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

.header-inner {
    max-width: 900px;
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
    letter-spacing: -.3px;
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

.btn-ghost:hover {
    background: rgba(255,255,255,.14);
}

.btn-primary {
    background: var(--accent);
    color: #fff;
}

.btn-primary:hover {
    background: var(--accent-hover);
}

.btn-cancel {
    background: var(--surface-2);
    color: var(--text);
    border: 1px solid var(--border);
}

.btn-cancel:hover {
    background: var(--surface);
}

.container {
    max-width: 820px;
    margin: 24px auto;
    padding: 0 20px 60px;
}

.page-title {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 6px;
    letter-spacing: -.4px;
}

.page-sub {
    color: var(--muted);
    font-size: 14.5px;
    margin-bottom: 28px;
}

.panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 26px;
    margin-bottom: 20px;
    box-shadow: var(--shadow);
}

.panel-title {
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 8px;
}

label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 7px;
    color: var(--text-soft);
}

input[type="text"],
textarea {
    display: block;
    width: 100%;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 14px;
    color: var(--text);
    font: inherit;
    margin-bottom: 18px;
    transition: border-color .2s, box-shadow .2s;
}

input[type="text"]:focus,
textarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(124,140,255,.15);
}

textarea {
    min-height: 280px;
    line-height: 1.75;
    resize: vertical;
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 16px;
}

input[type="file"] {
    margin: 4px 0 8px;
    font-size: 13px;
    color: var(--muted);
    max-width: 100%;
}

.hint {
    font-size: 12.5px;
    color: var(--muted);
    margin-top: -10px;
    margin-bottom: 16px;
}

.episode-preview {
    background: var(--surface-2);
    border: 1px dashed var(--border);
    border-radius: 10px;
    padding: 13px 14px;
    margin-top: -6px;
    margin-bottom: 18px;
}

.episode-preview small {
    display: block;
    font-size: 11px;
    color: var(--muted);
    margin-bottom: 4px;
}

.episode-preview strong {
    display: block;
    font-size: 14px;
    overflow-wrap: anywhere;
}

.actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    flex-wrap: wrap;
    margin-top: 8px;
}

.alert {
    padding: 14px 16px;
    border-radius: 10px;
    margin-bottom: 20px;
    font-size: 14px;
}

.alert-error {
    background: rgba(248,113,113,.12);
    border: 1px solid rgba(248,113,113,.25);
    color: #f87171;
}

@media (max-width: 600px) {
    .header {
        padding: 14px;
    }

    .header-inner {
        align-items: flex-start;
    }

    .header-inner > div:last-child {
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .panel {
        padding: 18px;
    }

    .page-title {
        font-size: 24px;
    }

    .logo-wrap {
        width: 44px;
        height: 44px;
    }

    .brand-text h1 {
        font-size: 17px;
    }

    .container {
        margin-top: 26px;
        padding-right: 14px;
        padding-left: 14px;
    }
}

textarea#content { display: none !important; height: 0 !important; min-height: 0 !important; margin: 0 !important; padding: 0 !important; border: 0 !important; }
.rich-editor {
    width: 100%;
    min-height: 280px;
    overflow-y: auto;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 14px;
    color: var(--text);
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 16px;
    line-height: 1.75;
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
.rich-editor > p:first-child {
    text-indent: 0;
}
.rich-editor p:has(+ p) {
    /* Jarak antarparagraf dikendalikan oleh margin di atas. */
}
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
    position: absolute; inset: 0; width: 100%; height: 100%;
    opacity: 0; cursor: pointer; padding: 0; border: 0;
}
.color-tool:hover { border-color: var(--accent); }
.editor-footer {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; flex-wrap: wrap; padding-top: 2px; margin-bottom: 8px;
}
.format-tools { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; }
.btn-format {
    display: inline-flex; align-items: center; justify-content: center;
    width: 34px; height: 32px; padding: 0;
    border: 1px solid var(--border); background: var(--surface-2);
    color: var(--text); border-radius: 7px; font: inherit;
    font-size: 15px; font-weight: 600; cursor: pointer; white-space: nowrap;
}
.btn-format:hover, .btn-format:focus-visible {
    border-color: var(--accent); outline: 2px solid transparent;
}
@media (max-width: 700px) {
    .format-tools { gap: 5px; width: 100%; }
    .btn-format, .color-tool { width: 31px; height: 30px; }
    .editor-footer { align-items: flex-start; }
}






/* Panel utama penuh; sampul di luar kanan container */
.container {
    position: relative;
}
.page-layout {
    display: block; /* tidak grid — panel tidak menyusut */
}
.page-main {
    width: 100%;
    max-width: none;
}
.page-side {
    position: absolute;
    left: calc(100% + 20px);
    top: 0;
    width: 200px;
}
.page-side .panel {
    margin-bottom: 0;
    position: sticky;
    top: 20px;
}
.cover-label {
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 10px;
    color: var(--text-soft);
}
.cover-preview-box {
    display: none;
    width: 100%;
    aspect-ratio: 4 / 5;
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid var(--border);
    margin-bottom: 12px;
    background: var(--surface-2);
}
.cover-preview-box.is-visible { display: block; }
.page-side input[type="file"] {
    display: block;
    width: 100%;
    margin: 4px 0;
    font-size: 12px;
    color: var(--muted);
}
.page-side .hint {
    margin: 0;
    font-size: 12px;
    color: var(--muted);
    line-height: 1.45;
}
@media (max-width: 1100px) {
    /* layar sempit: sampul di atas, panel tetap penuh */
    .page-side {
        position: static;
        width: auto;
        max-width: 200px;
        margin: 0 auto 16px;
    }
    .page-layout {
        display: flex;
        flex-direction: column;
    }
    .page-side { order: -1; }
}


.header-title-form {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    min-width: 0;
    margin: 0 16px;
}
.header-title-form label {
    display: none;
}
.header-title-form input[type="text"] {
    width: 100%;
    max-width: 420px;
    margin: 0;
    padding: 10px 14px;
    font-size: 14px;
    font-weight: 500;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 9px;
    color: var(--text);
}
.header-title-form input[type="text"]:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(124,140,255,.15);
}
@media (max-width: 700px) {
    .header-inner {
        flex-wrap: wrap;
    }
    .header-title-form {
        order: 3;
        flex: 1 1 100%;
        margin: 8px 0 0;
    }
    .header-title-form input[type="text"] {
        max-width: none;
    }
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
                <h1>＋ Buat Novel Baru</h1>
            </div>
        </a>

        <div class="header-title-form">
            <label for="title">Judul Novel *</label>
            <input
                type="text"
                id="title"
                name="title"
                form="create-form"
                maxlength="255"
                required
                value="<?= e($title) ?>"
                placeholder="Judul novel..."
                autofocus
            >
        </div>

        <div style="display:flex;gap:10px;align-items:center;flex-shrink:0;">
            <div id="theme-switcher-mount"></div>
            <a href="index.php" class="btn btn-ghost">
                ← Kembali
            </a>
        </div>

    </div>
</header>

<main class="container">
<?php if ($error !== ''): ?>
        <div class="alert alert-error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="create-form">

        <input
            type="hidden"
            name="create_token"
            value="<?= e($_SESSION['create_token']) ?>"
        >
        <input type="hidden" name="author" value="SUYATNO ABU BAKAR">

        <div class="page-layout">
            <div class="page-main">


        <section class="panel">
            <div class="panel-title">✍️ Episode Pertama</div>

            <label for="chapter_title">Judul Episode *</label>
            <input
                type="text"
                id="chapter_title"
                name="chapter_title"
                maxlength="240"
                required
                value="<?= e($chapterTitle) ?>"
                placeholder="Contoh: Awal Perjalanan"
            >
            <p class="hint">
                Tuliskan judulnya saja. Tulisan Episode 1 akan ditambahkan otomatis.
            </p>

            <label for="content">Isi Episode Pertama</label>
            <textarea id="content" name="content" style="display:none !important" aria-hidden="true"><?= e($content) ?></textarea>
            <div id="contentEditor" class="rich-editor" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Mulai tulis cerita novelmu di sini..."></div>

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
                    <button type="button" class="btn-format" data-format="indent" title="Rapikan paragraf + indentasi (masuk ke dalam)" aria-label="Paragraf indent">¶↳</button>

                    <button type="button" class="btn-format" data-format="sentence" title="Kapital awal kalimat" aria-label="Kapital awal kalimat">Aa</button>
                    <button type="button" class="btn-format" data-format="upper" title="Ubah menjadi HURUF BESAR" aria-label="Ubah menjadi huruf besar">A↑</button>
                    <button type="button" class="btn-format" data-format="lower" title="Ubah menjadi huruf kecil" aria-label="Ubah menjadi huruf kecil">a↓</button>
                    <button type="button" class="btn-format" data-format="title" title="Kapital Tiap Kata" aria-label="Kapital tiap kata">Tt</button>
                    <button type="button" class="btn-format" data-format="spaces" title="Hapus spasi berlebih" aria-label="Hapus spasi berlebih">↔</button>
                    <button type="button" class="btn-format" data-format="breaks" title="Rapikan baris menjadi paragraf" aria-label="Rapikan baris menjadi paragraf">↵</button>
                </div>
            </div>

            <p class="hint">Isi episode boleh dikosongkan dan dilanjutkan nanti.</p>
        </section>

        <div class="actions">
            <a href="index.php" class="btn btn-cancel">Batal</a>
            <button class="btn btn-primary" type="submit">＋ Buat Novel</button>
        </div>

            </div><!-- .page-main -->

            <aside class="page-side">
                <section class="panel">
                    <div class="cover-label">Sampul Novel</div>
                    <img class="cover-preview-box" id="coverPreview" alt="Pratinjau sampul">
                    <input
                        type="file"
                        id="cover"
                        name="cover"
                        accept="image/jpeg,image/png,image/webp"
                    >
                    <p class="hint">JPG, PNG, WebP · Maks. 5 MB · Rasio 4:5</p>
                </section>
            </aside>
        </div><!-- .page-layout -->

</form>

</main>

<script>

(() => {
    const input = document.getElementById('cover');
    const preview = document.getElementById('coverPreview');
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
        if (preview.dataset.objectUrl) URL.revokeObjectURL(preview.dataset.objectUrl);
        preview.dataset.objectUrl = URL.createObjectURL(file);
        preview.src = preview.dataset.objectUrl;
        preview.classList.add('is-visible');
    });
})();

/* pratinjau judul disembunyikan */

(() => {
    const content = document.getElementById('content');
    const editor = document.getElementById('contentEditor');
    const wordsEl = document.getElementById('words');
    const form = document.getElementById('create-form');

    if (!content || !editor) return;

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
                return '<p style="text-indent:0 !important;margin:0 0 8px;line-height:1.65;text-align:justify">' + escapeHtml(p) + '</p>';
            }).join('');
        })(initial);
    }

    const undoStack = [editor.innerHTML];
    const redoStack = [];
    let lastValue = editor.innerHTML;
    let restoringHistory = false;
    let savedRange = null;
    const maxHistory = 150;

    function syncContent() { content.value = editor.innerHTML; }
    function countWords() {
        const text = (editor.innerText || editor.textContent || '').trim();
        const count = text ? (text.match(/[\p{L}\p{N}]+(?:['\u2019-][\p{L}\p{N}]+)*/gu) || []).length : 0;
        if (wordsEl) wordsEl.textContent = count.toLocaleString('id-ID');
    }
    function rememberCurrentValue() {
        syncContent(); countWords();
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
        syncContent(); countWords();
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
            if (isHeading) {
                return '<p style="text-indent:0 !important;margin:1.1em 0 0.45em;font-weight:600">' + safe + '</p>';
            }
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
            // hanya pakai teks apa adanya, lalu bungkus paragraf indent
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
})();

</script>
</body>