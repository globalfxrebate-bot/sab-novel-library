<?php
session_start();
require_once __DIR__ . '/config/database.php';

$error = '';
$preview = $_SESSION['novel_import'] ?? null;

/**
 * Mengambil jumlah kata dengan dukungan teks UTF-8.
 */
function hitungKata(string $teks): int
{
    $teks = trim(strip_tags($teks));

    if ($teks === '') {
        return 0;
    }

    preg_match_all(
        '/[\p{L}\p{N}]+(?:[\'\x{2019}-][\p{L}\p{N}]+)*/u',
        $teks,
        $hasil
    );

    return count($hasil[0]);
}

/**
 * Pola heading standar novel.
 */
function polaHeadingStandar(): string
{
    return '/^(?:===|#{1,6}\s|'
        . 'BAB\s+\d+|Bab\s+\d+|CHAPTER\s+\d+|Chapter\s+\d+|'
        . 'EPISODE\s+\d+|Episode\s+\d+|'
        . 'PROLOG|Prolog|EPILOG|Epilog|'
        . 'Pendahuluan|PENDAHULUAN|Kata Pengantar|KATA PENGANTAR|'
        . 'Dedikasi|DEDIKASI|Hak Cipta|HAK CIPTA|Copyright|'
        . 'Sinopsis|SINOPSIS|Tentang Buku|TENTANG BUKU|'
        . 'Daftar Isi|DAFTAR ISI|TOC|'
        . 'Ucapan Terima Kasih|UCAPAN TERIMA KASIH|Acknowledgments?|'
        . 'Tentang Penulis|TENTANG PENULIS|About the Author|'
        . 'Halaman Promosi|HALAMAN PROMOSI|Promosi|'
        . 'Kesimpulan|KESIMPULAN|'
        . '\d+\.\s+\p{L}'
        . ')/u';
}

/**
 * Merapikan naskah agar layak cetak / ebook.
 */
function rapikanNaskah(string $teks): string
{
    if (trim($teks) === '') {
        return '';
    }

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
        return (bool) preg_match(polaHeadingStandar(), $line);
    };

    $isDialogue = static function (string $line): bool {
        return (bool) preg_match('/^(?:—|–|"|“|„|‘|\'|«)/u', $line);
    };

    $flush = static function () use (&$buffer, &$paragraphs): void {
        $value = trim($buffer);

        if ($value !== '') {
            $paragraphs[] = $value;
        }

        $buffer = '';
    };

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '') {
            $flush();
            continue;
        }

        $line = preg_replace('/\s+([,.;:!?…»”’\)\]])/u', '$1', $line) ?? $line;

        if ($isHeading($line)) {
            $flush();
            $paragraphs[] = $line;
            continue;
        }

        if ($isDialogue($line)) {
            $flush();
            $buffer = $line;
            $flush();
            continue;
        }

        if ($buffer === '') {
            $buffer = $line;
            continue;
        }

        $buffer .= ' ' . $line;
    }

    $flush();

    if (!$paragraphs) {
        return '';
    }

    $html = [];

    foreach ($paragraphs as $para) {
        $para = trim(preg_replace('/ {2,}/u', ' ', $para) ?? $para);

        if ($para === '') {
            continue;
        }

        $safe = htmlspecialchars($para, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $heading = (bool) preg_match(polaHeadingStandar(), $para);

        if ($heading) {
            $html[] = '<p style="text-indent:0 !important;margin:1.1em 0 0.45em;font-weight:600">' . $safe . '</p>';
        } else {
            $html[] = '<p style="text-indent:0 !important;margin:0 0 8px;line-height:1.65;text-align:justify">' . $safe . '</p>';
        }
    }

    return implode("\n", $html);
}

/**
 * Teks murni dari HTML untuk hitung kata.
 */
function teksMurni(string $html): string
{
    $teks = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
    $teks = str_replace(['\\"', "\\'"], ['"', "'"], $teks);

    return trim(preg_replace('/\s+/u', ' ', $teks) ?? $teks);
}

/**
 * Membatasi teks agar aman ditampilkan di HTML.
 */
function e($teks): string
{
    return htmlspecialchars((string) $teks, ENT_QUOTES, 'UTF-8');
}

/**
 * Membuat konten HTML paragraf standar.
 */
function buatParagraf(string $teks, bool $heading = false, string $align = 'justify'): string
{
    $safe = htmlspecialchars($teks, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    if ($heading) {
        return '<p style="text-indent:0 !important;margin:1.1em 0 0.45em;font-weight:600;text-align:center">' . $safe . '</p>';
    }

    $style = 'text-indent:0 !important;margin:0 0 8px;line-height:1.65;text-align:' . $align;

    return '<p style="' . $style . '">' . $safe . '</p>';
}

/**
 * Mengambil pembukaan cerita untuk draf sinopsis otomatis.
 */
function ambilPembukaanCerita(string $isi, int $maksimalKata = 145): string
{
    $isi = preg_replace('/^\xEF\xBB\xBF/u', '', $isi) ?? $isi;
    $isi = str_replace(["\r\n", "\r"], "\n", $isi);

    $lines = explode("\n", $isi);
    $hasil = [];
    $mulaiCerita = false;
    $adaPenandaCerita = false;

    $polaAwalCerita = '/^(?:BAB|CHAPTER|EPISODE)\s+\d+|^PROLOG\b|^===\s*EPISODE\s+\d+/iu';
    $polaBagianDepan = '/^(?:KATA PENGANTAR|PENDAHULUAN|DEDIKASI|HAK CIPTA|COPYRIGHT|SINOPSIS|TENTANG BUKU|DAFTAR ISI|TOC)\b/iu';
    $polaMetadata = '/^(?:Judul|Penulis|Sinopsis)\s*:/iu';

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        if (preg_match($polaMetadata, $line)) {
            continue;
        }

        if (preg_match($polaBagianDepan, $line)) {
            $mulaiCerita = false;
            continue;
        }

        if (preg_match($polaAwalCerita, $line)) {
            $mulaiCerita = true;
            $adaPenandaCerita = true;
            continue;
        }

        if (preg_match('/^===.*===$/u', $line)) {
            continue;
        }

        if (!$mulaiCerita && $adaPenandaCerita) {
            continue;
        }

        if (!$mulaiCerita && !$adaPenandaCerita) {
            if (preg_match('/^(?:Kata Pengantar|Sinopsis|Tentang Buku|Hak Cipta|Daftar Isi)\b/iu', $line)) {
                continue;
            }

            $mulaiCerita = true;
        }

        $line = strip_tags($line);
        $line = html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $line = trim($line);

        if ($line !== '') {
            $hasil[] = $line;
        }

        $gabungan = implode(' ', $hasil);

        if (hitungKata($gabungan) >= $maksimalKata) {
            break;
        }
    }

    $teks = implode(' ', $hasil);
    $teks = preg_replace('/\s+/u', ' ', $teks) ?? $teks;

    $kata = preg_split('/\s+/u', trim($teks), -1, PREG_SPLIT_NO_EMPTY);

    if (!$kata) {
        return '';
    }

    if (count($kata) > $maksimalKata) {
        $kata = array_slice($kata, 0, $maksimalKata);
        $teks = implode(' ', $kata);
        $teks = rtrim($teks, ',;:') . '...';
    }

    return trim($teks);
}

/**
 * Membuat draf sinopsis dari pembukaan cerita.
 */
function buatSinopsisOtomatis(string $isi, string $judul, string $penulis): string
{
    $pembukaan = ambilPembukaanCerita($isi, 145);

    if ($pembukaan === '') {
        return 'Novel ' . $judul . ' karya ' . $penulis . ' memerlukan sinopsis yang ditulis secara manual. Silakan tuliskan gambaran utama cerita, tokoh sentral, latar peristiwa, tujuan atau persoalan yang dihadapi tokoh, serta konflik yang menjadi penggerak cerita. Jelaskan daya tarik kisah tanpa membocorkan penyelesaian atau akhir cerita. Akhiri sinopsis dengan pertanyaan atau petunjuk yang membuat calon pembaca ingin mengetahui kelanjutan kisahnya.';
    }

    return 'Novel ' . $judul . ' karya ' . $penulis . ' mengawali kisahnya dengan sebuah rangkaian peristiwa berikut. '
        . $pembukaan
        . ' Peristiwa tersebut menjadi pintu masuk untuk mengenal tokoh-tokoh, keadaan yang mereka hadapi, serta persoalan yang akan berkembang sepanjang cerita. Bagaimana perjalanan para tokoh menghadapi keadaan tersebut? Konflik apa yang akan muncul, dan pilihan apa yang harus mereka ambil ketika keadaan semakin rumit? '
        . 'Lengkapi draf ini dengan tujuan tokoh utama, latar cerita, konflik pokok, dan daya tarik utama novel agar sinopsis benar-benar mewakili keseluruhan kisah tanpa mengungkap akhir cerita.';
}

/**
 * Mengambil metadata dari file TXT.
 */
function ambilMetadata(string $isi, string $filename = ''): array
{
    $judul = '';
    $penulis = '';
    $sinopsis = '';

    if (preg_match('/^[ \t]*Judul[ \t]*:[ \t]*(.+)$/imu', $isi, $m)) {
        $judul = trim($m[1]);
    }

    if (preg_match('/^[ \t]*Penulis[ \t]*:[ \t]*(.+)$/imu', $isi, $m)) {
        $penulis = trim($m[1]);
    }

    if (preg_match('/^[ \t]*Sinopsis[ \t]*:[ \t]*(.+)$/imu', $isi, $m)) {
        $sinopsis = trim($m[1]);

        if (strcasecmp($sinopsis, 'tidak tersedia.') === 0) {
            $sinopsis = '';
        }
    }

    if ($judul === '') {
        $lines = explode("\n", trim($isi));
        $firstLine = trim($lines[0] ?? '');

        if (
            $firstLine !== ''
            && mb_strlen($firstLine) < 80
            && !preg_match('/^(?:BAB|CHAPTER|EPISODE|===|PROLOG|EPILOG)/iu', $firstLine)
        ) {
            $judul = $firstLine;
        } else {
            $baseName = pathinfo($filename, PATHINFO_FILENAME);
            $judul = ucwords(str_replace(['_', '-'], ' ', $baseName));

            if ($judul === '') {
                $judul = 'Novel Tanpa Judul';
            }
        }
    }

    if ($penulis === '') {
        $penulis = 'SUYATNO ABU BAKAR';
    }

    if ($sinopsis === '') {
        $sinopsis = buatSinopsisOtomatis($isi, $judul, $penulis);
    }

    return [
        'title' => $judul,
        'author' => $penulis,
        'synopsis' => $sinopsis,
    ];
}

/**
 * Memecah naskah otomatis menjadi daftar episode/bab.
 */
function pecahEpisode(string $isi): array
{
    $bagian = [];

    // 1. Pola === EPISODE n: Judul ===
    $pola = '/^===[ \t]*EPISODE[ \t]+(\d+)[ \t]*:[ \t]*(.*?)'
          . '[ \t]*===[ \t]*$/imu';

    $jumlah = preg_match_all($pola, $isi, $matches, PREG_OFFSET_CAPTURE);

    if ($jumlah !== false && $jumlah > 0) {
        for ($i = 0; $i < $jumlah; $i++) {
            $nomorAsli = (int) $matches[1][$i][0];
            $judul = trim($matches[2][$i][0]);

            $judul = preg_replace('/^Episode[ \t]*\d+[ \t]*:[ \t]*/iu', '', $judul);
            $judul = preg_replace('/^#[ \t]*\d+[ \t]*[.:\-][ \t]*/u', '', $judul);

            if ($judul === '') {
                $judul = 'Tanpa Judul';
            }

            $awalIsi = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            $akhirIsi = ($i + 1 < $jumlah)
                ? $matches[0][$i + 1][1]
                : strlen($isi);

            $isiEpisode = substr($isi, $awalIsi, $akhirIsi - $awalIsi);
            $isiEpisode = preg_replace('/^\xEF\xBB\xBF/', '', $isiEpisode);
            $isiEpisode = rapikanNaskah((string) $isiEpisode);

            $judulLengkap = 'Episode ' . $nomorAsli . ': ' . $judul;

            $bagian[] = [
                'title' => mb_substr($judulLengkap, 0, 255, 'UTF-8'),
                'content' => $isiEpisode,
                'word_count' => hitungKata(teksMurni($isiEpisode)),
                'type' => 'main',
            ];
        }

        return $bagian;
    }

    // 2. Pola Bab / Chapter / Prolog / Epilog / bagian khusus
    $polaBab = '/^(?:'
             . '(?:BAB|Bab|CHAPTER|Chapter)\s+(\d+)\s*[:\-]?\s*(.*)|'
             . '(PROLOG|Prolog|EPILOG|Epilog|Pendahuluan|PENDAHULUAN|'
             . 'Kata Pengantar|KATA PENGANTAR|Dedikasi|DEDIKASI|'
             . 'Hak Cipta|HAK CIPTA|Copyright|Sinopsis|SINOPSIS|'
             . 'Tentang Buku|TENTANG BUKU|Ucapan Terima Kasih|UCAPAN TERIMA KASIH|'
             . 'Tentang Penulis|TENTANG PENULIS|Halaman Promosi|HALAMAN PROMOSI|'
             . 'Kesimpulan|KESIMPULAN)'
             . '(?:\s*[:\-]?\s*(.*))?'
             . ')$/imu';

    $jumlahBab = preg_match_all($polaBab, $isi, $matchesBab, PREG_OFFSET_CAPTURE);

    if ($jumlahBab !== false && $jumlahBab > 0) {
        for ($i = 0; $i < $jumlahBab; $i++) {
            $fullMatch = $matchesBab[0][$i][0];
            $offset = $matchesBab[0][$i][1];

            if (!empty($matchesBab[1][$i][0])) {
                $nomorAsli = (int) $matchesBab[1][$i][0];
                $judulBab = trim($matchesBab[2][$i][0] ?? '');

                if ($judulBab === '') {
                    $judulBab = 'Bab ' . $nomorAsli;
                } else {
                    $judulBab = 'Bab ' . $nomorAsli . ': ' . $judulBab;
                }

                $type = 'main';
            } else {
                $namaBagian = trim($matchesBab[3][$i][0] ?? '');
                $subJudul = trim($matchesBab[4][$i][0] ?? '');

                $judulBab = $namaBagian;

                if ($subJudul !== '') {
                    $judulBab .= ': ' . $subJudul;
                }

                $type = tentukanTipeBagian($namaBagian);
            }

            $awalIsi = $offset + strlen($fullMatch);
            $akhirIsi = ($i + 1 < $jumlahBab)
                ? $matchesBab[0][$i + 1][1]
                : strlen($isi);

            $isiBab = substr($isi, $awalIsi, $akhirIsi - $awalIsi);
            $isiBab = rapikanNaskah((string) $isiBab);

            $bagian[] = [
                'title' => mb_substr($judulBab, 0, 255, 'UTF-8'),
                'content' => $isiBab,
                'word_count' => hitungKata(teksMurni($isiBab)),
                'type' => $type,
            ];
        }

        return $bagian;
    }

    // 3. Tidak ada penanda → pecah otomatis tiap ~35 paragraf
    $teksRapi = rapikanNaskah($isi);
    $paragrafList = array_filter(
        explode("\n", $teksRapi),
        static fn($p) => trim($p) !== ''
    );

    $paragrafList = array_values($paragrafList);

    if (count($paragrafList) === 0) {
        return [];
    }

    $chunks = array_chunk($paragrafList, 35);

    foreach ($chunks as $index => $chunk) {
        $nomorBab = $index + 1;
        $kontenBab = implode("\n", $chunk);

        $bagian[] = [
            'title' => 'Bab ' . $nomorBab,
            'content' => $kontenBab,
            'word_count' => hitungKata(teksMurni($kontenBab)),
            'type' => 'main',
        ];
    }

    return $bagian;
}

/**
 * Menentukan tipe bagian berdasarkan judul.
 */
function tentukanTipeBagian(string $judul): string
{
    $j = mb_strtolower($judul, 'UTF-8');

    $front = [
        'kata pengantar',
        'pendahuluan',
        'dedikasi',
        'hak cipta',
        'copyright',
        'sinopsis',
        'tentang buku',
        'daftar isi'
    ];

    $back = [
        'epilog',
        'ucapan terima kasih',
        'tentang penulis',
        'halaman promosi',
        'promosi',
        'kesimpulan',
        'acknowledg'
    ];

    foreach ($front as $f) {
        if (str_contains($j, $f)) {
            return 'front';
        }
    }

    foreach ($back as $b) {
        if (str_contains($j, $b)) {
            return 'back';
        }
    }

    return 'main';
}

/**
 * Menerapkan struktur EPUB standar.
 */
function terapkanStrukturEPUB(array $episodes, array $metadata): array
{
    $judul = $metadata['title'];
    $penulis = $metadata['author'];
    $sinopsis = $metadata['synopsis'];

    $ada = static function (array $eps, array $keywords): bool {
        foreach ($eps as $ep) {
            $t = mb_strtolower($ep['title'], 'UTF-8');

            foreach ($keywords as $kw) {
                if (str_contains($t, $kw)) {
                    return true;
                }
            }
        }

        return false;
    };

    $front = [];
    $main = [];
    $back = [];

    foreach ($episodes as $ep) {
        $type = $ep['type'] ?? tentukanTipeBagian($ep['title']);
        $t = mb_strtolower($ep['title'], 'UTF-8');

        if (
            $type === 'front'
            || str_contains($t, 'kata pengantar')
            || str_contains($t, 'pendahuluan')
            || str_contains($t, 'dedikasi')
            || str_contains($t, 'sinopsis')
            || str_contains($t, 'tentang buku')
            || str_contains($t, 'hak cipta')
            || str_contains($t, 'copyright')
        ) {
            $ep['type'] = 'front';
            $front[] = $ep;
        } elseif (
            $type === 'back'
            || str_contains($t, 'epilog')
            || str_contains($t, 'ucapan terima kasih')
            || str_contains($t, 'tentang penulis')
            || str_contains($t, 'promosi')
            || str_contains($t, 'kesimpulan')
        ) {
            $ep['type'] = 'back';
            $back[] = $ep;
        } else {
            $ep['type'] = 'main';
            $main[] = $ep;
        }
    }

    // FRONT MATTER

    // 1. Kata Pengantar
    if (!$ada($front, ['kata pengantar', 'pendahuluan'])) {
        $konten = buatParagraf(
            'Puji syukur saya panjatkan ke hadirat Tuhan Yang Maha Esa atas segala rahmat dan karunia-Nya sehingga novel berjudul ' . $judul . ', karya ' . $penulis . ', dapat diselesaikan dan hadir di hadapan para pembaca.'
        )
        . buatParagraf(
            'Novel ini disajikan sebagai sebuah karya cerita yang menghadirkan perjalanan penuh warna, sarat makna, serta dibalut dengan imajinasi dan gaya bahasa yang menarik. Melalui kisah ini, saya berharap pembaca tidak hanya memperoleh hiburan, tetapi juga dapat menemukan pesan, nilai kehidupan, dan pengalaman membaca yang berkesan.'
        )
        . buatParagraf(
            'Saya menyadari bahwa setiap karya memiliki kekurangan. Oleh karena itu, segala kritik dan saran yang membangun akan menjadi bahan berharga untuk penyempurnaan karya-karya selanjutnya.'
        )
        . buatParagraf(
            'Akhir kata, saya mengucapkan selamat membaca. Semoga kisah dalam ' . $judul . ' dapat memberikan kesan tersendiri dan membawa pembaca menikmati perjalanan cerita hingga akhir.'
        )
        . '<p style="text-indent:0 !important;margin:18px 0 8px;line-height:1.65;text-align:right;font-weight:600">' . e($penulis) . '</p>';

        array_unshift($front, [
            'title' => 'Kata Pengantar',
            'content' => $konten,
            'word_count' => hitungKata(teksMurni($konten)),
            'type' => 'front',
        ]);
    }

    // 2. Sinopsis
    if (!$ada($front, ['sinopsis', 'tentang buku'])) {
        $konten = buatParagraf($sinopsis);

        $front[] = [
            'title' => 'Sinopsis',
            'content' => $konten,
            'word_count' => hitungKata(teksMurni($konten)),
            'type' => 'front',
        ];
    }

    // 3. Hak Cipta
    if (!$ada($front, ['hak cipta', 'copyright'])) {
        $tahun = date('Y');

        $konten = buatParagraf('© ' . $tahun . ' ' . $penulis . '. Semua hak dilindungi.', false, 'center')
            . buatParagraf('Judul: ' . $judul, false, 'center')
            . buatParagraf('Penulis: ' . $penulis, false, 'center')
            . buatParagraf('Dilarang mengutip, memperbanyak, atau menyebarluaskan sebagian atau seluruh isi buku ini tanpa izin tertulis dari penulis, kecuali untuk keperluan review atau penelitian non-komersial.', false, 'center')
            . buatParagraf('Edisi digital ini diterbitkan melalui SAB Novel Library.', false, 'center');

        $front[] = [
            'title' => 'Hak Cipta',
            'content' => $konten,
            'word_count' => hitungKata(teksMurni($konten)),
            'type' => 'front',
        ];
    }

    // BACK MATTER

    // 1. Ucapan Terima Kasih
    if (!$ada($back, ['ucapan terima kasih', 'acknowledg'])) {
        $konten = buatParagraf(
            'Saya mengucapkan terima kasih yang sebesar-besarnya kepada Tuhan Yang Maha Esa atas segala anugerah-Nya.'
        )
        . buatParagraf(
            'Terima kasih juga kepada keluarga, sahabat, dan seluruh pihak yang telah memberikan dukungan, semangat, serta masukan selama proses penulisan novel ' . $judul . '.'
        )
        . buatParagraf(
            'Terima kasih kepada para pembaca setia yang telah meluangkan waktu untuk menikmati kisah ini. Kehadiran Anda adalah motivasi terbesar bagi saya untuk terus berkarya.'
        )
        . buatParagraf(
            'Semoga novel ini dapat memberikan manfaat dan kesan yang indah.'
        )
        . '<p style="text-indent:0 !important;margin:18px 0 8px;line-height:1.65;text-align:right;font-weight:600">' . e($penulis) . '</p>';

        $back[] = [
            'title' => 'Ucapan Terima Kasih',
            'content' => $konten,
            'word_count' => hitungKata(teksMurni($konten)),
            'type' => 'back',
        ];
    }

    // 2. Tentang Penulis
    if (!$ada($back, ['tentang penulis', 'about the author'])) {
        $konten = buatParagraf(
            $penulis . ' adalah seorang penulis yang mencintai dunia sastra dan storytelling. Melalui karya-karyanya, beliau berusaha menghadirkan kisah yang tidak hanya menghibur, tetapi juga menyentuh hati serta memberikan refleksi kehidupan.'
        )
        . buatParagraf(
            'Novel ' . $judul . ' merupakan salah satu karya yang lahir dari imajinasi dan pengalaman hidup penulis. Semoga pembaca dapat menemukan kehangatan, pelajaran, dan inspirasi di dalamnya.'
        )
        . buatParagraf(
            'Untuk informasi lebih lanjut atau menghubungi penulis, silakan kunjungi platform digital SAB Novel Library atau media sosial resmi penulis.'
        );

        $back[] = [
            'title' => 'Tentang Penulis',
            'content' => $konten,
            'word_count' => hitungKata(teksMurni($konten)),
            'type' => 'back',
        ];
    }

    // 3. Halaman Promosi
    if (!$ada($back, ['promosi', 'halaman promosi'])) {
        $konten = buatParagraf('Terima kasih telah membaca ' . $judul . '!', false, 'center')
            . buatParagraf(
                'Jika Anda menyukai novel ini, mohon berikan rating dan ulasan di Google Play Books atau platform tempat Anda membaca. Ulasan Anda sangat berarti bagi penulis dan membantu pembaca lain menemukan karya ini.'
            )
            . buatParagraf(
                'Jangan lupa untuk mengikuti perkembangan karya-karya terbaru dari ' . $penulis . ' di SAB Novel Library. Masih banyak kisah menarik yang menunggu untuk dibaca!'
            )
            . buatParagraf('Selamat membaca novel-novel lainnya.', false, 'center')
            . '<p style="text-indent:0 !important;margin:18px 0 8px;line-height:1.65;text-align:center;font-weight:600">— SAB Novel Library —</p>';

        $back[] = [
            'title' => 'Halaman Promosi',
            'content' => $konten,
            'word_count' => hitungKata(teksMurni($konten)),
            'type' => 'back',
        ];
    }

    // Urutan Front Matter
    usort($front, static function ($a, $b) {
        $order = [
            'kata pengantar' => 1,
            'pendahuluan' => 1,
            'dedikasi' => 2,
            'sinopsis' => 3,
            'tentang buku' => 3,
            'hak cipta' => 4,
            'copyright' => 4,
        ];

        $ra = 50;
        $rb = 50;

        $ta = mb_strtolower($a['title'], 'UTF-8');
        $tb = mb_strtolower($b['title'], 'UTF-8');

        foreach ($order as $k => $v) {
            if (str_contains($ta, $k)) {
                $ra = $v;
            }

            if (str_contains($tb, $k)) {
                $rb = $v;
            }
        }

        return $ra <=> $rb;
    });

    // Urutan Back Matter
    usort($back, static function ($a, $b) {
        $order = [
            'epilog' => 1,
            'kesimpulan' => 2,
            'ucapan terima kasih' => 3,
            'acknowledg' => 3,
            'tentang penulis' => 4,
            'about the author' => 4,
            'promosi' => 5,
            'halaman promosi' => 5,
        ];

        $ra = 50;
        $rb = 50;

        $ta = mb_strtolower($a['title'], 'UTF-8');
        $tb = mb_strtolower($b['title'], 'UTF-8');

        foreach ($order as $k => $v) {
            if (str_contains($ta, $k)) {
                $ra = $v;
            }

            if (str_contains($tb, $k)) {
                $rb = $v;
            }
        }

        return $ra <=> $rb;
    });

    return array_merge($front, $main, $back);
}

// ======================================================
// PROSES 1: UPLOAD FILE TXT DAN MENYIAPKAN PRATINJAU
// ======================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'upload'
) {
    unset($_SESSION['novel_import']);

    if (
        !isset($_FILES['novel_file'])
        || $_FILES['novel_file']['error'] !== UPLOAD_ERR_OK
    ) {
        $error = 'File gagal diunggah. Silakan pilih file TXT kembali.';
    } else {
        $file = $_FILES['novel_file'];

        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'txt') {
            $error = 'Format file harus .txt.';
        } elseif ($file['size'] > 20 * 1024 * 1024) {
            $error = 'Ukuran file maksimal 20 MB.';
        } else {
            $isi = file_get_contents($file['tmp_name']);

            if ($isi === false) {
                $error = 'Isi file tidak dapat dibaca.';
            } else {
                $isi = preg_replace('/^\xEF\xBB\xBF/', '', $isi);

                if (!preg_match('//u', $isi)) {
                    $error = 'File harus menggunakan encoding UTF-8.';
                } else {
                    $metadata = ambilMetadata($isi, $file['name']);
                    $episodes = pecahEpisode($isi);

                    if (count($episodes) === 0) {
                        $error = 'File tidak berisi teks yang dapat diimpor.';
                    } else {
                        $episodes = terapkanStrukturEPUB($episodes, $metadata);

                        $_SESSION['novel_import'] = [
                            'filename' => basename($file['name']),
                            'title' => $metadata['title'],
                            'author' => $metadata['author'],
                            'synopsis' => $metadata['synopsis'],
                            'episodes' => $episodes,
                        ];

                        $preview = $_SESSION['novel_import'];
                    }
                }
            }
        }
    }
}

// ======================================================
// PROSES 2: MENYIMPAN NOVEL DAN SELURUH EPISODE
// ======================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'save'
) {
    $data = $_SESSION['novel_import'] ?? null;

    if (!$data || empty($data['episodes'])) {
        $error = 'Pratinjau tidak ditemukan. Silakan unggah file kembali.';
    } else {
        $judul = trim($_POST['title'] ?? '');
        $penulis = trim($_POST['author'] ?? '');

        if ($penulis === '') {
            $penulis = 'SUYATNO ABU BAKAR';
        }

        $sinopsis = trim($_POST['synopsis'] ?? '');

        if ($judul === '') {
            $error = 'Judul novel wajib diisi.';
        } elseif ($sinopsis === '') {
            $error = 'Sinopsis wajib diisi.';
        } elseif (hitungKata($sinopsis) < 120) {
            $jumlahKataSinopsis = hitungKata($sinopsis);

            $error = 'Sinopsis minimal 120 kata. Saat ini baru '
                . $jumlahKataSinopsis
                . ' kata. Silakan lengkapi sinopsis sebelum menyimpan novel.';
        } else {
            try {
                $episodesToSave = $data['episodes'];
                $sinopsisDitemukan = false;

                foreach ($episodesToSave as &$episode) {
                    $judulEpisode = mb_strtolower($episode['title'], 'UTF-8');

                    if (
                        str_contains($judulEpisode, 'sinopsis')
                        || str_contains($judulEpisode, 'tentang buku')
                    ) {
                        $episode['content'] = buatParagraf($sinopsis);
                        $episode['word_count'] = hitungKata($sinopsis);
                        $sinopsisDitemukan = true;
                        break;
                    }
                }
                unset($episode);

                if (!$sinopsisDitemukan) {
                    array_unshift($episodesToSave, [
                        'title' => 'Sinopsis',
                        'content' => buatParagraf($sinopsis),
                        'word_count' => hitungKata($sinopsis),
                        'type' => 'front',
                    ]);
                }

                $pdo->beginTransaction();

                $stmtNovel = $pdo->prepare(
                    'INSERT INTO novels
                    (title, author, synopsis, original_filename, status)
                    VALUES (?, ?, ?, ?, ?)'
                );

                $stmtNovel->execute([
                    mb_substr($judul, 0, 255, 'UTF-8'),
                    mb_substr($penulis, 0, 255, 'UTF-8'),
                    $sinopsis,
                    $data['filename'],
                    'published',
                ]);

                $novelId = (int) $pdo->lastInsertId();

                $stmtChapter = $pdo->prepare(
                    'INSERT INTO chapters
                    (novel_id, chapter_number, title, content, word_count)
                    VALUES (?, ?, ?, ?, ?)'
                );

                // PENOMORAN CERDAS: Menghitung nomor bab murni khusus cerita utama (Main)
                $chapterCounter = 1;

                foreach ($episodesToSave as $episode) {
                    $titleLower = mb_strtolower($episode['title'], 'UTF-8');
                    $type = $episode['type'] ?? 'main';

                    // Cek apakah ini bagian khusus (Front/Back) atau Episode Utama
                    $isFrontOrBack = (
                        $type === 'front' || $type === 'back' ||
                        str_contains($titleLower, 'kata pengantar') ||
                        str_contains($titleLower, 'sinopsis') ||
                        str_contains($titleLower, 'hak cipta') ||
                        str_contains($titleLower, 'copyright') ||
                        str_contains($titleLower, 'ucapan terima kasih') ||
                        str_contains($titleLower, 'tentang penulis') ||
                        str_contains($titleLower, 'halaman promosi') ||
                        str_contains($titleLower, 'dedikasi') ||
                        str_contains($titleLower, 'pendahuluan')
                    );

                    if ($isFrontOrBack) {
                        // Halaman khusus diberi nomor 0 agar tidak mengacaukan penomoran episode utama
                        $chapterNumber = 0;
                    } else {
                        // Episode cerita utama diberi nomor urut 1, 2, 3, dst.
                        $chapterNumber = $chapterCounter++;
                    }

                    $stmtChapter->execute([
                        $novelId,
                        $chapterNumber,
                        $episode['title'],
                        $episode['content'],
                        $episode['word_count'],
                    ]);
                }

                $pdo->commit();

                unset($_SESSION['novel_import']);

                header('Location: index.php?saved=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($e->getMessage());

                $error = 'Gagal menyimpan: ' . $e->getMessage();
                $preview = $_SESSION['novel_import'] ?? null;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Impor Novel - SAB Novel Library</title>

    <link rel="stylesheet" href="assets/theme-system.css">
    <script src="assets/theme.js"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,600;0,700;1,400&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Serif:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@400;500;600;700&family=Literata:ital,opsz,wght@0,7..72,400;0,7..72,600;0,7..72,700;1,7..72,400&family=Lora:ital,wght@0,400;0,600;0,700;1,400&family=Merriweather:ital,wght@0,400;0,700;1,400&family=Playfair+Display:wght@600;700&family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;0,8..60,700;1,7..72,400&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-ui, 'Inter', system-ui, sans-serif);
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .header {
            background: var(--header-bg);
            color: var(--text);
            padding: 18px 24px;
            border-bottom: 1px solid var(--border);
            position: relative;
            z-index: 100;
        }

        .header-inner {
            max-width: 900px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
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
            font-family: var(--font-heading, 'Playfair Display', Georgia, serif);
            font-size: 20px;
            font-weight: 700;
        }

        .brand-text span {
            display: block;
            font-size: 12px;
            color: var(--muted);
            margin-top: 1px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
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

        .btn-secondary {
            background: var(--surface-2);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .container {
            max-width: 900px;
            margin: 32px auto;
            padding: 0 20px 50px;
        }

        .page-title {
            font-family: var(--font-heading, 'Playfair Display', Georgia, serif);
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
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }

        .panel h2 {
            font-family: var(--font-heading, 'Playfair Display', Georgia, serif);
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .panel h3 {
            font-size: 15px;
            font-weight: 600;
            margin: 20px 0 12px;
            color: var(--text);
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin: 16px 0 7px;
            color: var(--text-soft);
        }

        input[type="text"],
        textarea,
        input[type="file"] {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface-2);
            color: var(--text);
            font: inherit;
        }

        input[type="text"]:focus,
        textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(124,140,255,.15);
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        #synopsis {
            min-height: 230px;
            line-height: 1.7;
        }

        input[type="file"] {
            color: var(--muted);
            font-size: 13px;
            padding: 10px;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .error {
            background: rgba(248,113,113,.12);
            color: #f87171;
            border: 1px solid rgba(248,113,113,.25);
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-size: 14px;
        }

        .info {
            background: var(--surface-2);
            border: 1px solid var(--border);
            padding: 14px 16px;
            border-radius: 10px;
            margin: 14px 0;
            font-size: 14px;
            line-height: 1.65;
            color: var(--text-soft);
        }

        .episode-list {
            max-height: 420px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 4px 16px;
            background: var(--surface-2);
            list-style-position: inside;
        }

        .episode-list li {
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
            color: var(--text);
            font-size: 14px;
        }

        .episode-list li .section-label {
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 4px;
            margin-right: 6px;
            vertical-align: middle;
        }

        .section-front {
            background: rgba(59,130,246,.15);
            color: #60a5fa;
        }

        .section-main {
            background: rgba(34,197,94,.15);
            color: #4ade80;
        }

        .section-back {
            background: rgba(249,115,22,.15);
            color: #fb923c;
        }

        .muted {
            color: var(--muted);
            font-size: 13px;
        }

        .hint {
            margin-top: 12px;
            color: var(--muted);
            font-size: 13px;
        }

        .structure-note {
            background: rgba(124,140,255,.08);
            border: 1px solid rgba(124,140,255,.2);
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 14px;
            line-height: 1.55;
        }

        .synopsis-guidance {
            background: var(--surface-2);
            border: 1px solid var(--border);
            padding: 12px 14px;
            border-radius: 9px;
            margin-top: 9px;
            font-size: 13px;
            color: var(--muted);
            line-height: 1.6;
        }

        .synopsis-counter {
            margin-top: 9px;
            font-size: 13px;
            font-weight: 600;
        }

        .synopsis-counter.invalid {
            color: #f87171;
        }

        .synopsis-counter.valid {
            color: #4ade80;
        }

        @media (max-width: 600px) {
            .header {
                padding: 14px 16px;
            }

            .container {
                margin: 22px auto;
                padding: 0 14px 35px;
            }

            .panel {
                padding: 18px;
            }

            .page-title {
                font-size: 24px;
            }

            .brand-text h1 {
                font-size: 17px;
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
                <h1>SAB Novel Library</h1>
                <span>Impor Novel</span>
            </div>
        </a>

        <div class="header-actions">
            <div id="theme-switcher-mount"></div>
            <a href="index.php" class="btn btn-ghost">← Kembali</a>
        </div>

    </div>
</header>

<main class="container">

    <h1 class="page-title">Impor Novel</h1>

    <p class="page-sub">
        Pilih file TXT. Sistem akan otomatis menerapkan struktur EPUB lengkap
        (Front Matter → Main Text → Back Matter) jika bagian tersebut belum ada di naskah.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="panel">

        <form method="post" enctype="multipart/form-data">

            <input type="hidden" name="action" value="upload">

            <label for="novel_file">File novel (.txt)</label>

            <input
                type="file"
                id="novel_file"
                name="novel_file"
                accept=".txt,text/plain"
                required
            >

            <div class="actions">
                <button type="submit" class="btn btn-primary">
                    Baca dan Pratinjau File
                </button>

                <a class="btn btn-secondary" href="index.php">
                    Batal
                </a>
            </div>

        </form>

    </div>

    <?php if ($preview): ?>

        <?php
        $jumlahEpisode = count($preview['episodes']);
        $jumlahKata = 0;

        foreach ($preview['episodes'] as $episode) {
            $jumlahKata += $episode['word_count'];
        }

        $cntFront = 0;
        $cntMain = 0;
        $cntBack = 0;

        foreach ($preview['episodes'] as $ep) {
            $t = mb_strtolower($ep['title'], 'UTF-8');
            $type = $ep['type'] ?? 'main';

            if (
                $type === 'front' ||
                str_contains($t, 'kata pengantar')
                || str_contains($t, 'pendahuluan')
                || str_contains($t, 'sinopsis')
                || str_contains($t, 'tentang buku')
                || str_contains($t, 'hak cipta')
                || str_contains($t, 'copyright')
                || str_contains($t, 'dedikasi')
            ) {
                $cntFront++;
            } elseif (
                $type === 'back' ||
                str_contains($t, 'epilog')
                || str_contains($t, 'ucapan terima kasih')
                || str_contains($t, 'tentang penulis')
                || str_contains($t, 'promosi')
                || str_contains($t, 'kesimpulan')
            ) {
                $cntBack++;
            } else {
                $cntMain++;
            }
        }
        ?>

        <div class="panel">

            <h2>Pratinjau Novel</h2>

            <div class="info">
                <strong>File:</strong>
                <?= e($preview['filename']) ?>
                <br>

                <strong>Jumlah Bagian:</strong>
                <?= $jumlahEpisode ?>

                (Front Matter: <?= $cntFront ?>,
                Isi Cerita: <?= $cntMain ?>,
                Back Matter: <?= $cntBack ?>)

                <br>

                <strong>Total Kata:</strong>
                <?= number_format($jumlahKata, 0, ',', '.') ?>
            </div>

            <div class="structure-note">
                <strong>Struktur EPUB diterapkan otomatis:</strong>
                <br>

                • <strong>Front Matter</strong> — Kata Pengantar, Sinopsis, Hak Cipta
                <br>

                • <strong>Main Text</strong> — Prolog (jika ada) + Bab / Episode cerita
                <br>

                • <strong>Back Matter</strong> — Epilog (jika ada), Ucapan Terima Kasih,
                Tentang Penulis, Halaman Promosi
            </div>

            <h3>Daftar Bab / Bagian Terdeteksi</h3>

            <ol class="episode-list">

                <?php foreach ($preview['episodes'] as $episode): ?>

                    <?php
                    $t = mb_strtolower($episode['title'], 'UTF-8');
                    $type = $episode['type'] ?? 'main';

                    $label = 'Main';
                    $cls = 'section-main';

                    if (
                        $type === 'front' ||
                        str_contains($t, 'kata pengantar')
                        || str_contains($t, 'pendahuluan')
                        || str_contains($t, 'sinopsis')
                        || str_contains($t, 'tentang buku')
                        || str_contains($t, 'hak cipta')
                        || str_contains($t, 'copyright')
                        || str_contains($t, 'dedikasi')
                    ) {
                        $label = 'Front';
                        $cls = 'section-front';
                    } elseif (
                        $type === 'back' ||
                        str_contains($t, 'epilog')
                        || str_contains($t, 'ucapan terima kasih')
                        || str_contains($t, 'tentang penulis')
                        || str_contains($t, 'promosi')
                        || str_contains($t, 'kesimpulan')
                    ) {
                        $label = 'Back';
                        $cls = 'section-back';
                    }
                    ?>

                    <li>
                        <span class="section-label <?= $cls ?>">
                            <?= $label ?>
                        </span>

                        <strong><?= e($episode['title']) ?></strong>
                        <br>

                        <span class="muted">
                            <?= number_format($episode['word_count'], 0, ',', '.') ?> kata
                        </span>
                    </li>

                <?php endforeach; ?>

            </ol>

            <form method="post" id="save-novel-form">

                <input type="hidden" name="action" value="save">

                <label for="title">Judul Novel</label>

                <input
                    type="text"
                    id="title"
                    name="title"
                    maxlength="255"
                    value="<?= e($preview['title']) ?>"
                    required
                >

                <label for="author">Nama Penulis</label>

                <input
                    type="text"
                    id="author"
                    name="author"
                    maxlength="255"
                    value="<?= e($preview['author']) ?>"
                >

                <label for="synopsis">
                    Sinopsis (minimal 120 kata)
                </label>

                <textarea
                    id="synopsis"
                    name="synopsis"
                    required
                ><?= e($preview['synopsis']) ?></textarea>

                <div class="synopsis-guidance">
                    <strong>Panduan penulisan sinopsis:</strong>
                    <br>
                    • Jelaskan tokoh utama dan latar cerita.
                    <br>
                    • Gambarkan tujuan atau persoalan yang dihadapi tokoh.
                    <br>
                    • Perkenalkan konflik utama tanpa membocorkan akhir cerita.
                    <br>
                    • Buat pembaca penasaran untuk mengetahui kelanjutannya.
                    <br>
                    • Minimal 120 kata. Idealnya 150–200 kata.
                    <br>
                    • Periksa kembali draf otomatis agar benar-benar sesuai dengan isi novel.
                </div>

                <div
                    id="synopsis-counter"
                    class="synopsis-counter"
                    aria-live="polite"
                >
                    Menghitung kata...
                </div>

                <p class="hint">
                    Periksa kembali data di atas sebelum menyimpannya ke database.
                    Sinopsis yang Anda edit akan digunakan untuk mengisi halaman Sinopsis
                    di dalam novel.
                </p>

                <div class="actions">

                    <button
                        type="submit"
                        class="btn btn-primary"
                        id="save-button"
                    >
                        💾 Simpan Novel dan Semua Episode
                    </button>

                </div>

            </form>

        </div>

    <?php endif; ?>

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const synopsis = document.getElementById('synopsis');
    const counter = document.getElementById('synopsis-counter');
    const saveButton = document.getElementById('save-button');
    const saveForm = document.getElementById('save-novel-form');

    if (!synopsis || !counter) {
        return;
    }

    function hitungJumlahKata(teks) {
        const hasil = teks.trim().match(
            /[\p{L}\p{N}]+(?:['’\-][\p{L}\p{N}]+)*/gu
        );

        return hasil ? hasil.length : 0;
    }

    function perbaruiPenghitung() {
        const jumlah = hitungJumlahKata(synopsis.value);

        counter.classList.remove('valid', 'invalid');

        if (jumlah < 120) {
            const kurang = 120 - jumlah;

            counter.textContent =
                'Jumlah kata: ' + jumlah +
                ' kata. Masih kurang ' + kurang +
                ' kata dari batas minimal 120 kata.';

            counter.classList.add('invalid');

            if (saveButton) {
                saveButton.disabled = true;
                saveButton.style.opacity = '0.55';
                saveButton.style.cursor = 'not-allowed';
            }
        } else if (jumlah < 150) {
            counter.textContent =
                'Jumlah kata: ' + jumlah +
                ' kata. Sinopsis sudah memenuhi batas minimal 120 kata.';

            counter.classList.add('valid');

            if (saveButton) {
                saveButton.disabled = false;
                saveButton.style.opacity = '1';
                saveButton.style.cursor = 'pointer';
            }
        } else if (jumlah <= 200) {
            counter.textContent =
                'Jumlah kata: ' + jumlah +
                ' kata. Panjang sinopsis ideal.';

            counter.classList.add('valid');

            if (saveButton) {
                saveButton.disabled = false;
                saveButton.style.opacity = '1';
                saveButton.style.cursor = 'pointer';
            }
        } else {
            counter.textContent =
                'Jumlah kata: ' + jumlah +
                ' kata. Sinopsis sudah memenuhi batas minimal, tetapi lebih panjang dari rekomendasi 150–200 kata.';

            counter.classList.add('valid');

            if (saveButton) {
                saveButton.disabled = false;
                saveButton.style.opacity = '1';
                saveButton.style.cursor = 'pointer';
            }
        }
    }

    synopsis.addEventListener('input', perbaruiPenghitung);

    if (saveForm) {
        saveForm.addEventListener('submit', function (event) {
            const jumlah = hitungJumlahKata(synopsis.value);

            if (jumlah < 120) {
                event.preventDefault();

                alert(
                    'Sinopsis minimal 120 kata. Saat ini baru ' +
                    jumlah +
                    ' kata. Silakan lengkapi terlebih dahulu.'
                );

                synopsis.focus();
            }
        });
    }

    perbaruiPenghitung();
});
</script>

</body>
</html>