<?php
require_once __DIR__ . '/config/database.php';

/**
 * Mengamankan teks agar valid ketika dimasukkan ke XML/XHTML.
 */
function epubEscape($value): string
{
    $value = (string) $value;

    $value = preg_replace(
        '/[\x00-\x08\x0B\x0C\x0E-\x1F]/',
        '',
        $value
    ) ?? '';

    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_XML1,
        'UTF-8'
    );
}

/**
 * Membuat nama file yang aman.
 */
function epubSafeFilename($value): string
{
    $name = preg_replace(
        '/[^\p{L}\p{N}\-_ ]+/u',
        '',
        (string) $value
    );

    $name = trim((string) $name);

    return $name !== '' ? $name : 'Novel';
}

/**
 * Membersihkan konten HTML dari editor rich-text,
 * lalu membuat paragraf XHTML dengan format standar novel.
 */
function epubParagraphs($content): string
{
    $content = (string) $content;

    for ($i = 0; $i < 3; $i++) {
        $decoded = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $content) {
            break;
        }
        $content = $decoded;
    }

    $content = preg_replace(
        '/<\/(p|div|h[1-6]|li|blockquote|section|article)\s*>/iu',
        "\n\n",
        $content
    ) ?? $content;

    $content = preg_replace('/<br\s*\/?>/iu', "\n", $content) ?? $content;
    $content = strip_tags($content);
    $content = preg_replace('/<[^>]*>/', '', $content) ?? $content;
    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $content = str_replace(["\xC2\xA0", '&nbsp;', "\t"], ' ', $content);
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $content = preg_replace('/[ \t]+/u', ' ', $content) ?? $content;
    $content = preg_replace("/\n{3,}/", "\n\n", $content) ?? $content;
    $content = trim($content);

    if ($content === '') {
        return '<p></p>';
    }

    $paragraphs = preg_split('/\n[ \t]*\n+/', $content);
    $html = [];
    $isFirst = true;

    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') {
            continue;
        }

        $lines = explode("\n", $paragraph);
        $escapedLines = [];
        $plainLines = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $plainLines[] = $line;
                $escapedLines[] = epubEscape($line);
            }
        }

        if ($escapedLines === []) {
            continue;
        }

        $plain = implode(' ', $plainLines);

        if (preg_match('/^([*•·\-–—=_]{3,}|[*•·](?:\s[*•·]){2,})$/u', $plain)) {
            $html[] = '<p class="scene-break">' . epubEscape($plain) . '</p>';
            $isFirst = false;
            continue;
        }

        if (preg_match('/^(BERSAMBUNG|Tamat|TAMAT|To be continued|Sambung|Continued)\b/iu', $plain)) {
            $html[] = '<p class="note">' . implode("<br />\n", $escapedLines) . '</p>';
            $isFirst = false;
            continue;
        }

        $class = $isFirst ? ' class="first"' : '';
        $html[] = '<p' . $class . '>' . implode("<br />\n", $escapedLines) . '</p>';
        $isFirst = false;
    }

    return $html === [] ? '<p></p>' : implode("\n", $html);
}

/**
 * Menghasilkan dokumen XHTML dasar dengan struktur Header dan Footer.
 */
function epubXhtmlDocument(string $pageTitle, string $headerTitle, string $body): string
{
    $headerHtml = '';
    if ($headerTitle !== '') {
        $headerHtml = '<header class="epub-header"><span>' . epubEscape($headerTitle) . '</span></header>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>' .
        '<!DOCTYPE html>' .
        '<html xmlns="http://www.w3.org/1999/xhtml" ' .
        'lang="id" xml:lang="id">' .
        '<head>' .
        '<title>' . epubEscape($pageTitle) . '</title>' .
        '<meta charset="UTF-8"/>' .
        '<meta name="viewport" content="width=device-width, initial-scale=1"/>' .
        '<link rel="stylesheet" type="text/css" href="style.css"/>' .
        '</head>' .
        '<body>' .
        $headerHtml .
        '<main>' . $body . '</main>' .
        '</body>' .
        '</html>';
}

$novelId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$novelId || $novelId < 1) {
    http_response_code(400);
    exit('ID novel tidak valid.');
}

if (!class_exists('ZipArchive')) {
    exit('Fitur ZIP PHP belum aktif. Aktifkan extension=zip di php.ini.');
}

$stmt = $pdo->prepare('SELECT * FROM novels WHERE id = ?');
$stmt->execute([$novelId]);
$novel = $stmt->fetch();

if (!$novel) {
    http_response_code(404);
    exit('Novel tidak ditemukan.');
}

// Ambil seluruh bab mentah dari database
$stmt = $pdo->prepare(
    'SELECT chapter_number, title, content, id
     FROM chapters
     WHERE novel_id = ?'
);
$stmt->execute([$novelId]);
$chapters = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$chapters) {
    exit('Novel belum memiliki episode.');
}

// Logika Pengurutan Seragam seperti di read.php
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

$title = trim((string) ($novel['title'] ?? 'Novel'));
if ($title === '') {
    $title = 'Novel';
}

$author = trim((string) ($novel['author'] ?? ''));
$synopsis = trim((string) ($novel['synopsis'] ?? ''));

if ($author === '') {
    $author = 'Penulis tidak dicantumkan';
}
if ($synopsis === '') {
    $synopsis = 'Belum ada sinopsis untuk novel ini.';
}

$uid = 'urn:satria-novel:' . $novelId;

$zipPath = tempnam(sys_get_temp_dir(), 'epub_');
if ($zipPath === false) {
    exit('Tidak dapat membuat file sementara.');
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
    @unlink($zipPath);
    exit('Gagal membuat file EPUB.');
}

if (!$zip->addFromString('mimetype', 'application/epub+zip')) {
    $zip->close();
    @unlink($zipPath);
    exit('Gagal menambahkan file mimetype.');
}
$zip->setCompressionName('mimetype', ZipArchive::CM_STORE);

$containerXml =
    '<?xml version="1.0" encoding="UTF-8"?>' .
    '<container version="1.0" ' .
    'xmlns="urn:oasis:names:tc:opendocument:xmlns:container">' .
    '<rootfiles>' .
    '<rootfile full-path="EPUB/content.opf" ' .
    'media-type="application/oebps-package+xml"/>' .
    '</rootfiles>' .
    '</container>';

$zip->addFromString('META-INF/container.xml', $containerXml);

$coverPath = '';
$coverType = 'image/svg+xml';
$coverExtension = 'svg';

if (!empty($novel['cover_path'])) {
    $relativeCover = ltrim(
        str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $novel['cover_path']),
        DIRECTORY_SEPARATOR
    );
    $candidate = __DIR__ . DIRECTORY_SEPARATOR . $relativeCover;
    $realCandidate = realpath($candidate);
    $realRoot = realpath(__DIR__);

    if (
        $realCandidate !== false &&
        $realRoot !== false &&
        is_file($realCandidate) &&
        strpos($realCandidate, $realRoot . DIRECTORY_SEPARATOR) === 0
    ) {
        $imageInfo = @getimagesize($realCandidate);
        if ($imageInfo !== false) {
            $mime = $imageInfo['mime'] ?? '';
            $extensions = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif'
            ];
            if (isset($extensions[$mime])) {
                $coverPath = $realCandidate;
                $coverType = $mime;
                $coverExtension = $extensions[$mime];
            }
        }
    }
}

if ($coverPath !== '') {
    $zip->addFile($coverPath, 'EPUB/cover.' . $coverExtension);
} else {
    $svg =
        '<?xml version="1.0" encoding="UTF-8"?>' .
        '<svg xmlns="http://www.w3.org/2000/svg" ' .
        'width="1200" height="1800" viewBox="0 0 1200 1800">' .
        '<rect width="1200" height="1800" fill="#18243b"/>' .
        '<rect x="55" y="55" width="1090" height="1690" ' .
        'fill="none" stroke="#c5a66b" stroke-width="5"/>' .
        '<text x="600" y="700" fill="#ffffff" ' .
        'font-family="serif" font-size="64" ' .
        'text-anchor="middle">' .
        epubEscape($title) .
        '</text>' .
        '<text x="600" y="820" fill="#c5a66b" ' .
        'font-family="serif" font-size="34" ' .
        'text-anchor="middle">Satria Novel Library</text>' .
        '</svg>';

    $zip->addFromString('EPUB/cover.svg', $svg);
}

$css = '
@page {
    margin-top: 10%;
    margin-bottom: 10%;
    margin-left: 8%;
    margin-right: 8%;

    @top-center {
        content: env(pub-title, "' . epubEscape($title) . '");
        font-family: serif;
        font-size: 0.8em;
        color: #777777;
        border-bottom: 1px solid #ddd;
        padding-bottom: 4px;
    }

    @bottom-center {
        content: counter(page);
        font-family: serif;
        font-size: 0.85em;
        color: #555555;
    }
}

@page :first {
    @top-center { content: ""; border: none; }
    @bottom-center { content: ""; }
}

body {
    font-family: "Georgia", "Times New Roman", "Liberation Serif", serif;
    font-size: 1em;
    line-height: 1.75;
    margin: 0;
    padding: 0;
    widows: 2;
    orphans: 2;
    hyphens: auto;
    -webkit-hyphens: auto;
}

.epub-header {
    text-align: center;
    font-size: 0.8em;
    color: #888;
    border-bottom: 1px solid #eee;
    padding-bottom: 5px;
    margin-bottom: 2em;
    text-transform: uppercase;
    letter-spacing: 0.1em;
}

h1 {
    font-size: 1.5em;
    font-weight: bold;
    line-height: 1.35;
    text-align: center;
    margin-top: 2em;
    margin-bottom: 1.8em;
    page-break-after: avoid;
    break-after: avoid;
    text-indent: 0;
}

h2 {
    font-size: 1.2em;
    line-height: 1.4;
    text-align: center;
    text-indent: 0;
}

p {
    margin-top: 0;
    margin-bottom: 0;
    text-indent: 1.5em;
    text-align: justify;
    overflow-wrap: break-word;
}

p.first {
    text-indent: 0;
}

p.scene-break {
    text-indent: 0;
    text-align: center;
    margin-top: 1.2em;
    margin-bottom: 1.2em;
    letter-spacing: 0.35em;
}

p.note {
    text-indent: 0;
    text-align: center;
    font-style: italic;
    margin-top: 1.5em;
    margin-bottom: 0;
}

.cover-page {
    margin: 0;
    padding: 0;
    text-align: center;
}

.cover-image {
    display: block;
    width: 100%;
    height: auto;
    margin: 0 auto;
}

.center-text {
    text-align: center;
    text-indent: 0;
}

.copyright-box {
    margin-top: 20vh;
    font-size: 0.9em;
    text-align: center;
    text-indent: 0;
    line-height: 1.6;
}

.synopsis-box {
    margin-top: 2em;
    text-align: justify;
    text-indent: 1.5em;
    line-height: 1.8;
}

.toc-list {
    list-style-type: none;
    padding-left: 0;
    margin-top: 1.5em;
}

.toc-list li {
    margin-bottom: 0.8em;
    border-bottom: 1px dotted #ccc;
    padding-bottom: 0.3em;
}

.toc-list a {
    text-decoration: none;
    color: #222;
    font-weight: 500;
}
';

$zip->addFromString('EPUB/style.css', $css);

$coverBody =
    '<div class="cover-page">' .
    '<img class="cover-image" ' .
    'src="cover.' . $coverExtension . '" ' .
    'alt="Sampul ' . epubEscape($title) . '"/>' .
    '</div>';
$coverXhtml = epubXhtmlDocument('Sampul - ' . $title, '', $coverBody);
$zip->addFromString('EPUB/cover.xhtml', $coverXhtml);

$copyrightBody =
    '<div class="copyright-box">' .
    '<h2 style="margin-bottom: 0.5em;">' . epubEscape($title) . '</h2>' .
    '<p class="center-text" style="margin-bottom: 2em;">Penulis: ' . epubEscape($author) . '</p>' .
    '<hr style="width: 30%; border: 0; border-top: 1px solid #ccc; margin: 0 auto 2em auto;" />' .
    '<p class="center-text" style="font-size: 0.85em;">Hak Cipta &copy; ' . date('Y') . ' ' . epubEscape($author) . '</p>' .
    '<p class="center-text" style="font-size: 0.85em;">Diterbitkan oleh Satria Novel Library</p>' .
    '<p class="center-text" style="font-size: 0.8em; font-style: italic; margin-top: 1em;">Seluruh hak cipta dilindungi undang-undang.</p>' .
    '</div>';
$copyrightXhtml = epubXhtmlDocument('Hak Cipta - ' . $title, $title, $copyrightBody);
$zip->addFromString('EPUB/copyright.xhtml', $copyrightXhtml);

$synopsisBody =
    '<h1>Sinopsis</h1>' .
    '<div class="synopsis-box">' .
    epubParagraphs($synopsis) .
    '</div>';
$synopsisXhtml = epubXhtmlDocument('Sinopsis - ' . $title, $title, $synopsisBody);
$zip->addFromString('EPUB/synopsis.xhtml', $synopsisXhtml);

$manifest = [];
$spine = [];
$navItems = [];
$ncxNavItems = [];

$manifest[] = '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>';
$manifest[] = '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>';
$manifest[] = '<item id="cover-page" href="cover.xhtml" media-type="application/xhtml+xml"/>';
$manifest[] = '<item id="copyright-page" href="copyright.xhtml" media-type="application/xhtml+xml"/>';
$manifest[] = '<item id="synopsis-page" href="synopsis.xhtml" media-type="application/xhtml+xml"/>';
$manifest[] = '<item id="style" href="style.css" media-type="text/css"/>';
$manifest[] =
    '<item id="cover-image" href="cover.' . $coverExtension .
    '" media-type="' . epubEscape($coverType) .
    '" properties="cover-image"/>';

$spine[] = '<itemref idref="cover-page"/>';
$spine[] = '<itemref idref="copyright-page"/>';
$spine[] = '<itemref idref="synopsis-page"/>';

$ncxNavItems[] = '<navPoint id="navPoint-1" playOrder="1"><navLabel><text>Hak Cipta</text></navLabel><content src="copyright.xhtml"/></navPoint>';
$ncxNavItems[] = '<navPoint id="navPoint-2" playOrder="2"><navLabel><text>Sinopsis</text></navLabel><content src="synopsis.xhtml"/></navPoint>';

$playOrder = 3;

foreach ($chapters as $index => $chapter) {
    $number = (int) $chapter['chapter_number'];
    $chapterTitle = trim((string) ($chapter['title'] ?? ''));
    if ($chapterTitle === '') {
        $chapterTitle = 'Bab ' . $number;
    }

    $fileName = 'chapter-' . ($index + 1) . '.xhtml';
    $itemId = 'chapter-' . ($index + 1);

    $contentHtml = epubParagraphs($chapter['content'] ?? '');
    $chapterBody = '<h1>' . epubEscape($chapterTitle) . '</h1>' . $contentHtml;

    $xhtml = epubXhtmlDocument($chapterTitle, $title, $chapterBody);

    if (!$zip->addFromString('EPUB/' . $fileName, $xhtml)) {
        $zip->close();
        @unlink($zipPath);
        exit('Gagal menambahkan bagian ke EPUB.');
    }

    $manifest[] =
        '<item id="' . $itemId .
        '" href="' . $fileName .
        '" media-type="application/xhtml+xml"/>';

    $spine[] = '<itemref idref="' . $itemId . '"/>';

    $navItems[] =
        '<li><a href="' . $fileName . '">' .
        epubEscape($chapterTitle) .
        '</a></li>';

    $ncxNavItems[] =
        '<navPoint id="navPoint-' . $playOrder . '" playOrder="' . $playOrder . '">' .
        '<navLabel><text>' . epubEscape($chapterTitle) . '</text></navLabel>' .
        '<content src="' . $fileName . '"/>' .
        '</navPoint>';

    $playOrder++;
}

$navBody =
    '<nav xmlns:epub="http://www.idpf.org/2007/ops" epub:type="toc" id="toc">' .
    '<h1>Daftar Isi</h1>' .
    '<ol class="toc-list">' .
    '<li><a href="copyright.xhtml">Hak Cipta</a></li>' .
    '<li><a href="synopsis.xhtml">Sinopsis</a></li>' .
    implode('', $navItems) .
    '</ol>' .
    '</nav>' .
    '<nav epub:type="landmarks" hidden="hidden">' .
    '<h2>Panduan Navigasi</h2>' .
    '<ol>' .
    '<li><a epub:type="cover" href="cover.xhtml">Sampul</a></li>' .
    '<li><a epub:type="frontmatter" href="copyright.xhtml">Hak Cipta</a></li>' .
    '<li><a epub:type="summary" href="synopsis.xhtml">Sinopsis</a></li>' .
    '<li><a epub:type="toc" href="nav.xhtml">Daftar Isi</a></li>' .
    '<li><a epub:type="bodymatter" href="chapter-1.xhtml">Mulai Membaca</a></li>' .
    '</ol>' .
    '</nav>';

$navXhtml = epubXhtmlDocument('Daftar Isi', $title, $navBody);
$zip->addFromString('EPUB/nav.xhtml', $navXhtml);

$ncxXml =
    '<?xml version="1.0" encoding="UTF-8"?>' .
    '<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">' .
    '<head>' .
    '<meta name="dtb:uid" content="' . epubEscape($uid) . '"/>' .
    '<meta name="dtb:depth" content="1"/>' .
    '<meta name="dtb:totalPageCount" content="0"/>' .
    '<meta name="dtb:maxPageNumber" content="0"/>' .
    '</head>' .
    '<docTitle><text>' . epubEscape($title) . '</text></docTitle>' .
    '<navMap>' . implode('', $ncxNavItems) . '</navMap>' .
    '</ncx>';

$zip->addFromString('EPUB/toc.ncx', $ncxXml);

$modified = gmdate('Y-m-d\TH:i:s\Z');

$metadata =
    '<metadata ' .
    'xmlns:dc="http://purl.org/dc/elements/1.1/" ' .
    'xmlns="http://www.idpf.org/2007/opf">' .
    '<dc:identifier id="pub-id">' . epubEscape($uid) . '</dc:identifier>' .
    '<dc:title>' . epubEscape($title) . '</dc:title>' .
    '<dc:language>id</dc:language>' .
    '<dc:creator>' . epubEscape($author) . '</dc:creator>' .
    '<dc:description>' . epubEscape($synopsis) . '</dc:description>' .
    '<meta property="dcterms:modified">' . $modified . '</meta>' .
    '<meta property="rendition:layout">reflowable</meta>' .
    '</metadata>';

$package =
    '<?xml version="1.0" encoding="UTF-8"?>' .
    '<package xmlns="http://www.idpf.org/2007/opf" ' .
    'version="3.0" unique-identifier="pub-id" xml:lang="id">' .
    $metadata .
    '<manifest>' . implode('', $manifest) . '</manifest>' .
    '<spine toc="ncx">' . implode('', $spine) . '</spine>' .
    '</package>';

if (!$zip->addFromString('EPUB/content.opf', $package)) {
    $zip->close();
    @unlink($zipPath);
    exit('Gagal membuat metadata EPUB.');
}

if (!$zip->close()) {
    @unlink($zipPath);
    exit('Gagal menyelesaikan file EPUB.');
}

if (!is_file($zipPath)) {
    exit('File EPUB tidak berhasil dibuat.');
}

$filename = epubSafeFilename($title) . '.epub';
$asciiFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
if ($asciiFilename === '' || $asciiFilename === '.epub') {
    $asciiFilename = 'Novel.epub';
}

header('Content-Type: application/epub+zip');
header(
    'Content-Disposition: attachment; filename="' .
    $asciiFilename .
    '"; filename*=UTF-8\'\'' .
    rawurlencode($filename)
);
header('Content-Length: ' . filesize($zipPath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');

readfile($zipPath);
@unlink($zipPath);
exit;