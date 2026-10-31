<?php
session_start();
require_once __DIR__ . '/config/database.php';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['add_chapter_token'])) {
    $_SESSION['add_chapter_token'] = bin2hex(random_bytes(32));
}

$error = '';
$novelId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$judulEpisode = '';
$isiCerita = '';
$nomorBaru = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $novelId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $judulEpisode = trim($_POST['chapter_title'] ?? '');
    $isiCerita = (string) ($_POST['content'] ?? '');
}

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

// SOLUSI UTAMA: Mengambil MAX(chapter_number) HANYA dari judul yang berawalan "Episode "
// Contoh: Mengabaikan "Ucapan Terima Kasih", "Tentang Penulis", dll.
$stmt = $pdo->prepare(
    "SELECT COALESCE(MAX(chapter_number), 0) + 1
     FROM chapters WHERE novel_id = ? AND title LIKE 'Episode %'"
);
$stmt->execute([$novelId]);
$nomorBaru = (int) $stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['add_chapter_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['add_chapter_token'], $token)
    ) {
        $error = 'Formulir tidak valid. Muat ulang halaman dan coba lagi.';
    } elseif ($judulEpisode === '') {
        $error = 'Judul episode wajib diisi.';
    } elseif (mb_strlen($judulEpisode, 'UTF-8') > 255) {
        $error = 'Judul episode maksimal 255 karakter.';
    } elseif (trim(strip_tags($isiCerita)) === '') {
        $error = 'Isi cerita tidak boleh kosong.';
    } elseif (!preg_match('//u', $isiCerita)) {
        $error = 'Isi cerita harus berupa teks UTF-8 yang valid.';
    } else {
        $plainText = trim(strip_tags($isiCerita));
        preg_match_all('/[\p{L}\p{N}]+(?:[\'\x{2019}-][\p{L}\p{N}]+)*/u', $plainText, $matches);
        $wordCount = count($matches[0]);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT id FROM novels WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$novelId]);

            if (!$stmt->fetch()) {
                throw new RuntimeException('Novel tidak ditemukan.');
            }

            // Validasi ulang nomor baru di dalam transaction
            $stmt = $pdo->prepare(
                "SELECT COALESCE(MAX(chapter_number), 0) + 1
                 FROM chapters WHERE novel_id = ? AND title LIKE 'Episode %'"
            );
            $stmt->execute([$novelId]);
            $nomorBaru = (int) $stmt->fetchColumn();

            $judulLengkap = 'Episode ' . $nomorBaru . ': ' . $judulEpisode;

            $stmt = $pdo->prepare(
                'INSERT INTO chapters
                 (novel_id, chapter_number, title, content, word_count)
                 VALUES (?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $novelId,
                $nomorBaru,
                $judulLengkap,
                $isiCerita,
                $wordCount
            ]);

            $pdo->commit();

            header(
                'Location: read.php?id=' . $novelId .
                '&chapter=' . $nomorBaru . '&added=1'
            );
            exit;

        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = 'Gagal menyimpan: ' . $exception->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tambah Episode - <?= e($novel['title']) ?></title>

<link rel="stylesheet" href="assets/theme-system.css">
<script src="assets/theme.js"></script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,600;0,700;1,400&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Serif:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@400;500;600;700&family=Literata:ital,opsz,wght@0,7..72,400;0,7..72,600;0,7..72,700;1,7..72,400&family=Lora:ital,wght@0,400;0,600;0,700;1,400&family=Merriweather:ital,wght@0,400;0,700;1,400&family=Playfair+Display:wght@600;700&family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;0,8..60,700;1,8..60,400&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">

<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

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
    max-width: 820px;
    margin: 36px auto;
    padding: 0 20px 60px;
}

.page-title {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 6px;
}

.page-sub {
    color: var(--muted);
    font-size: 14.5px;
    margin-bottom: 24px;
}

.panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 26px;
    box-shadow: var(--shadow);
}

.episode-preview {
    margin-bottom: 22px;
    padding: 14px 16px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface-2);
    color: var(--text-soft);
    font-size: 14px;
    line-height: 1.6;
}

.episode-preview strong {
    color: var(--accent);
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
    min-height: 380px;
    line-height: 1.8;
    resize: vertical;
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 16.5px;
}

.actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    flex-wrap: wrap;
    margin-top: 8px;
}

.alert-error {
    padding: 14px 16px;
    border-radius: 10px;
    margin-bottom: 20px;
    font-size: 14px;
    background: rgba(248,113,113,.12);
    border: 1px solid rgba(248,113,113,.25);
    color: #f87171;
    word-break: break-word;
}

.word-count {
    font-size: 12.5px;
    color: var(--muted);
    margin-top: -10px;
    margin-bottom: 8px;
}

@media (max-width: 600px) {
    .panel { padding: 18px; }
    .page-title { font-size: 24px; }
    .logo-wrap { width: 44px; height: 44px; }
    textarea { min-height: 300px; }
}

textarea#content { display: none !important; height: 0 !important; min-height: 0 !important; margin: 0 !important; padding: 0 !important; border: 0 !important; }
.rich-editor {
    width: 100%;
    min-height: 320px;
    overflow-y: auto;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 14px;
    color: var(--text);
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 16.5px;
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
                <span>Tambah Episode</span>
            </div>
        </a>
        <div style="display:flex;gap:10px;align-items:center;">
            <div id="theme-switcher-mount"></div>
            <a href="read.php?id=<?= (int) $novelId ?>" class="btn btn-ghost">
                ← Kembali ke Pembaca
            </a>
        </div>
    </div>
</header>

<main class="container">
    <h1 class="page-title">＋ Tambah Episode Baru</h1>
    <p class="page-sub">
        Novel: <strong><?= e($novel['title']) ?></strong>
    </p>

    <?php if ($error !== ''): ?>
        <div class="alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="panel">
        <div class="episode-preview">
            Nomor episode berikutnya dibuat otomatis:
            <strong id="episodePreview">Episode <?= $nomorBaru ?>:</strong>
            <span id="titlePreview">Judul episode kamu</span>
        </div>

        <form method="post" id="add-chapter-form">
            <input type="hidden" name="id" value="<?= (int) $novelId ?>">
            <input type="hidden" name="add_chapter_token" value="<?= e($_SESSION['add_chapter_token']) ?>">

            <label for="chapter_title">Judul Episode</label>
            <input type="text" id="chapter_title" name="chapter_title" maxlength="255" required
                   value="<?= e($judulEpisode) ?>"
                   placeholder="Masukkan judul episode saja (tanpa nomor)">

            <label for="content">Isi Cerita</label>
            <textarea id="content" name="content" style="display:none !important" aria-hidden="true"><?= e($isiCerita) ?></textarea>
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
                    <button type="button" class="btn-format" data-format="indent" title="Rapikan paragraf + indentasi (masuk ke dalam)" aria-label="Paragraf indent">¶↳</button>
                    <button type="button" class="btn-format" data-format="sentence" title="Kapital awal kalimat" aria-label="Kapital awal kalimat">Aa</button>
                    <button type="button" class="btn-format" data-format="upper" title="Ubah menjadi HURUF BESAR" aria-label="Ubah menjadi huruf besar">A↑</button>
                    <button type="button" class="btn-format" data-format="lower" title="Ubah menjadi huruf kecil" aria-label="Ubah menjadi huruf kecil">a↓</button>
                    <button type="button" class="btn-format" data-format="title" title="Kapital Tiap Kata" aria-label="Kapital tiap kata">Tt</button>
                    <button type="button" class="btn-format" data-format="spaces" title="Hapus spasi berlebih" aria-label="Hapus spasi berlebih">↔</button>
                    <button type="button" class="btn-format" data-format="breaks" title="Rapikan baris menjadi paragraf" aria-label="Rapikan baris menjadi paragraf">↵</button>
                </div>
            </div>


            <div class="actions">
                <a class="btn btn-cancel" href="read.php?id=<?= (int) $novelId ?>">Batal</a>
                <button class="btn btn-primary" type="submit">💾 Simpan Episode</button>
            </div>
        </form>
    </section>
</main>

<script>
const titleInput = document.getElementById('chapter_title');
const titlePreview = document.getElementById('titlePreview');
function updateTitlePreview() {
    titlePreview.textContent = titleInput.value.trim() || 'Judul episode kamu';
}
titleInput.addEventListener('input', updateTitlePreview);
updateTitlePreview();

(() => {
    const content = document.getElementById('content');
    const editor = document.getElementById('contentEditor');
    const wordsEl = document.getElementById('words');
    const form = document.getElementById('add-chapter-form');

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
</html>