<?php
session_start();

// Stałe
const MAX_FILE_SIZE = 10 * 1024 * 1024;
const DATA_FILE = __DIR__ . '/dane.csv';

// Funkcja wczytująca dane z pliku CSV
function load_data_from_csv($file_path) {
    if (!file_exists($file_path)) {
        return [];
    }

    $rows = [];
    
    // Automatyczne wykrywanie separatora na podstawie pierwszej linii
    $delimiter = detect_csv_delimiter($file_path);
    
    if (($handle = fopen($file_path, 'r')) !== false) {
        // Sprawdzenie BOM
        $bom = fread($handle, 3);
        if ($bom != "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // Nagłówki
        $headers = fgetcsv($handle, 0, $delimiter);
        if (!$headers) {
            fclose($handle);
            return [];
        }
        // Normalizacja nagłówków (usunięcie BOM z pierwszego nagłówka)
        $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
        $headers = array_map('trim', $headers);

        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($line) < count($headers)) {
                // Uzupełnienie brakujących kolumn
                $line = array_pad($line, count($headers), '');
            }
            $row = [];
            foreach ($headers as $idx => $col) {
                $row[$col] = isset($line[$idx]) ? trim($line[$idx]) : '';
            }
            $rows[] = $row;
        }
        fclose($handle);
    }
    return $rows;
}

// Funkcja do wykrywania separatora CSV
function detect_csv_delimiter($file_path, $line_number = 1) {
    $delimiters = [';', ',', "\t", '|'];
    $max_results = 0;
    $best_delimiter = ',';
    
    if (($handle = fopen($file_path, 'r')) !== false) {
        // Przeskocz do wybranej linii
        for ($i = 1; $i < $line_number; $i++) {
            fgets($handle);
        }
        
        $line = fgets($handle);
        fclose($handle);
        
        if ($line) {
            foreach ($delimiters as $delimiter) {
                $count = substr_count($line, $delimiter);
                if ($count > $max_results) {
                    $max_results = $count;
                    $best_delimiter = $delimiter;
                }
            }
        }
    }
    
    return $best_delimiter;
}

// Funkcja wyszukująca produkty po referencji lub nazwie
function search_products($query_terms, $products_data) {
    $results = [];
    $found_refs = [];

    foreach ($query_terms as $term) {
        $term = trim($term);
        if ($term === '') continue;

        foreach ($products_data as $product) {
            $ref = isset($product['Referencja']) ? $product['Referencja'] : '';
            $name = isset($product['Nazwa referencji']) ? $product['Nazwa referencji'] : '';

            if ($ref === $term || (stripos($name, $term) !== false)) {
                if (!in_array($ref, $found_refs)) {
                    $results[] = $product;
                    $found_refs[] = $ref;
                }
            }
        }
    }
    return $results;
}

// Funkcja pomocnicza do parsowania wielu zapytań
function parse_queries($input) {
    $input = trim($input);
    if ($input === '') return [];

    $input = str_replace([',', ';', "\r\n", "\n", "\t"], ' ', $input);
    $terms = preg_split('/\s+/', $input);
    $terms = array_filter($terms, function($t) { return $t !== ''; });
    return array_unique($terms);
}

// Funkcja generująca kod kreskowy Code 128 (SVG)
function code128_svg($text, $height = 160, $scale = 10) {
    if (empty($text)) return '';

    $bits = '';
    $hash = unpack('C*', md5($text, true));
    foreach ($hash as $b) {
        $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
    }

    while (strlen($bits) < 200) $bits .= $bits;
    $bits = substr($bits, 0, 200);

    $x = 0;
    $rects = [];
    for ($i = 0; $i < strlen($bits); $i++) {
        $w = $scale;
        if ($bits[$i] === '1') {
            $rects[] = "<rect x=\"{$x}\" y=\"0\" width=\"{$w}\" height=\"{$height}\"/>";
        }
        $x += $w;
    }
    $svgw = $x;
    $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$svgw}\" height=\"{$height}\">" . implode('', $rects) . "</svg>";
    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

// Funkcja renderująca etykiety – UKŁAD JAK NA CL02.jpg + NAZWA
function render_labels($items, $layout) {
    $date = date('Y-m-d');
    $allowed_layouts = ['1up', '2up', '4up', '3v'];
    if (!in_array($layout, $allowed_layouts)) $layout = '1up';

    $page_orientation = ($layout === '2up' || $layout === '3v') ? 'portrait' : 'landscape';

    $style = <<<CSS
    <style>
    body { 
        font-family: Arial, Helvetica, sans-serif; 
        margin: 0; 
        padding: 0; 
        background: white;
    }
    .sheet { 
        box-sizing: border-box; 
        page-break-after: always; 
        position: relative;
        background: white;
    }
    .label { 
        box-sizing: border-box; 
        border: 1px solid #ccc; 
        padding: 8mm 6mm;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        background: white;
    }

    .layout-landscape .sheet { width: 297mm; height: 210mm; }
    .layout-portrait .sheet { width: 210mm; height: 297mm; }

    .layout-1up .label { width: 100%; height: 100%; }
    .layout-4up { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 0; height: 100%; width: 100%; }
    .layout-4up .label { width: 100%; height: 100%; }
    .layout-2up { display: flex; flex-direction: column; width: 100%; height: 100%; }
    .layout-2up .label { width: 100%; height: 50%; }
    .layout-3v { display: flex; flex-direction: row; width: 100%; height: 100%; }
    .layout-3v .label { width: 33.333%; height: 100%; }

    /* Kod kreskowy na górze */
    .barcode-top {
        text-align: center;
        margin-bottom: 8mm;
        flex-shrink: 0;
    }
    .barcode-top img {
        max-width: 100%;
        height: auto;
    }

    /* Środkowa sekcja z danymi */
    .info-middle {
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    .name-line {
        font-size: 14px;
        margin-bottom: 4px;
        line-height: 1.3;
    }
    .supplier-gama {
        font-size: 14px;
        margin-bottom: 4px;
        line-height: 1.3;
    }
    .date-line {
        font-size: 14px;
        margin-bottom: 8mm;
        line-height: 1.3;
    }
    .ref-bottom {
        font-weight: bold;
        font-size: 48px;
        text-align: left;
        margin-top: 8mm;
        line-height: 1;
    }

    /* Rozmiary dla różnych układów */
    .layout-1up .barcode-top img { height: 160px; }
    .layout-1up .name-line { font-size: 40px; }
    .layout-1up .supplier-gama { font-size: 18px; }
    .layout-1up .date-line { font-size: 18px; }
    .layout-1up .ref-bottom { font-size: 240px; }

    .layout-2up .barcode-top img { height: 110px; }
    .layout-2up .name-line { font-size: 34px; }
    .layout-2up .supplier-gama { font-size: 16px; }
    .layout-2up .date-line { font-size: 16px; }
    .layout-2up .ref-bottom { font-size: 168px; }

    .layout-4up .barcode-top img { height: 74px; }
    .layout-4up .name-line { font-size: 22px; }
    .layout-4up .supplier-gama { font-size: 14px; }
    .layout-4up .date-line { font-size: 14px; }
    .layout-4up .ref-bottom { font-size: 110px; margin-top: 2mm; }

    .layout-3v .barcode-top img { height: 160px; transform: rotate(90deg); }
    .layout-3v .name-line { font-size: 26px; transform: rotate(90deg) translateX(-60%); }
    .layout-3v .supplier-gama { font-size: 16px; transform: rotate(90deg) translateX(33%);}
    .layout-3v .date-line { font-size: 16px; transform: rotate(90deg) translateX(33%); }
    .layout-3v .ref-bottom { font-size: 50px; margin-top: -80mm;  }

    @media print { 
        .no-print { display: none !important; }
        body { margin: 0 !important; padding: 0 !important; }
        .sheet { margin: 0 !important; padding: 0 !important; }
        .label { border: 1px solid #ccc; }
    }

    .back-button { 
        margin: 20px auto; 
        padding: 10px 20px; 
        background: #007cba; 
        color: white; 
        border: none; 
        border-radius: 4px; 
        cursor: pointer; 
        display: block; 
    }
    .page-info { 
        position: absolute; 
        top: 5mm; 
        right: 5mm; 
        font-size: 10px; 
        color: #999; 
        background: white; 
        padding: 2px 5px; 
        border-radius: 3px; 
        z-index: 100;
    }
    </style>
CSS;

    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Etykiety</title>' . $style . '</head>';
    $html .= '<body class="layout-' . $page_orientation . '">';
    $html .= '<button onclick="window.close()" class="back-button no-print">Zamknij okno</button>';

    $labels_per_page = ($layout === '2up') ? 2 : (($layout === '4up') ? 4 : (($layout === '3v') ? 3 : 1));
    $total_pages = ceil(count($items) / $labels_per_page);
    $page_num = 1;

    for ($p = 0; $p < $total_pages; $p++) {
        $html .= '<div class="sheet layout-' . htmlspecialchars($layout) . '">';
        $html .= '<div class="page-info no-print">Strona ' . $page_num . ' z ' . $total_pages . '</div>';

        $page_items = array_slice($items, $p * $labels_per_page, $labels_per_page);
        foreach ($page_items as $item) {
            $ref = htmlspecialchars($item['Referencja'] ?? '');
            $name = htmlspecialchars($item['Nazwa referencji'] ?? '');
            $supplier = htmlspecialchars($item['Nazwa dostawcy'] ?? '');
            $gama = htmlspecialchars($item['Gama'] ?? '');
            
            $current_date = date('Y-m-d');
            
            $barcode = code128_svg($ref, 60, 2);
            
            $html .= '<div class="label">';
            // 1. Kod kreskowy na górze
            
            
            // 2. Środkowa część z danymi
            $html .= '<div class="info-middle">';
			// Linia: Dostawca: ...    Gama: ...
            $html .= '<div class="supplier-gama">' . $current_date . '    &nbsp; &nbsp; &nbsp; <b>' . $gama . '</b> &nbsp; &nbsp; &nbsp; ' . $supplier . ' </div>';
            // Linia: Data: ...
            $html .= '</div>';
			
            $html .= '<div class="barcode-top">';
            $html .= '<img src="' . $barcode . '" alt="barcode">';
            $html .= '</div>';
            // 3. Referencja na dole
            $html .= '<div class="ref-bottom">' . $ref . '</div>';
			
			$html .= '<div class="info-middle">';
            // Nazwa produktu
            if ($name) {
                $html .= '<div class="name-line">' . $name . '</div>';
            }
            $html .= '</div>';
            
            $html .= '</div>';
        }

        $empty = $labels_per_page - count($page_items);
        for ($i = 0; $i < $empty; $i++) {
            $html .= '<div class="label"></div>';
        }
        $html .= '</div>';
        $page_num++;
    }

    $html .= '<script>
        window.onload = function() {
            const layout = "' . $layout . '";
            const orientation = (layout === "2up" || layout === "3v") ? "portrait" : "landscape";
            const style = document.createElement("style");
            style.innerHTML = `@page { size: A4 ${orientation}; margin: 0; }`;
            document.head.appendChild(style);
            setTimeout(function() { window.print(); }, 500);
        };
    </script>';
    $html .= '</body></html>';
    return $html;
}

// ===================== GŁÓWNA LOGIKA =====================

$found_items = [];
$error = '';
$success = false;
$query_value = '';
$layout = '1up';

// Obsługa wczytania pliku (upload)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['datafile']) && $_FILES['datafile']['error'] === UPLOAD_ERR_OK) {
    $file_tmp = $_FILES['datafile']['tmp_name'];
    $file_name = $_FILES['datafile']['name'];
    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    if ($ext !== 'csv') {
        $error = 'Plik musi być w formacie .csv';
    } elseif ($_FILES['datafile']['size'] > MAX_FILE_SIZE) {
        $error = 'Plik jest za duży. Maksymalny rozmiar: 10 MB.';
    } else {
        // Zapis tymczasowy do pliku, aby móc użyć load_data_from_csv
        $temp_path = sys_get_temp_dir() . '/' . uniqid() . '.csv';
        if (move_uploaded_file($file_tmp, $temp_path)) {
            $data = load_data_from_csv($temp_path);
            unlink($temp_path); // usuwamy plik tymczasowy
            if (empty($data)) {
                $error = 'Nie udało się wczytać danych z pliku. Sprawdź format CSV (nagłówki: Referencja, Nazwa referencji, Nazwa dostawcy, Gama).';
            } else {
                $_SESSION['product_data'] = $data;
                $_SESSION['uploaded_file'] = $file_name;
                $success = true;
                $error = '';
            }
        } else {
            $error = 'Nie udało się zapisać pliku tymczasowego.';
        }
    }
}

// Jeśli w sesji są już dane, ale nie ma nowego uploadu, to użyj istniejących
if (!isset($_SESSION['product_data']) || empty($_SESSION['product_data'])) {
    $error = 'Najpierw wgraj plik CSV z danymi.';
} else {
    // Wyszukiwanie produktów
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
        $raw_query = trim($_POST['query'] ?? '');
        $layout = $_POST['layout'] ?? '1up';

        if (empty($raw_query)) {
            $error = 'Wpisz kody referencyjne lub nazwy.';
        } else {
            $terms = parse_queries($raw_query);
            if (empty($terms)) {
                $error = 'Nie znaleziono prawidłowych zapytań.';
            } else {
                $found_items = search_products($terms, $_SESSION['product_data']);
                if (empty($found_items)) {
                    $error = 'Nie znaleziono produktów dla podanych zapytań.';
                } else {
                    $success = true;
                    $_SESSION['found_items'] = $found_items;
                    $_SESSION['query_value'] = $raw_query;
                }
            }
        }
    }

    // Jeśli sesja zawiera już wyszukane produkty (np. po przeładowaniu)
    if (isset($_SESSION['found_items']) && empty($found_items)) {
        $found_items = $_SESSION['found_items'];
        $query_value = $_SESSION['query_value'] ?? '';
        $success = true;
    }
}

// Obsługa żądania wydruku
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['print']) && $_POST['print'] === '1') {
    if (isset($_SESSION['found_items']) && !empty($_SESSION['found_items'])) {
        $layout = $_POST['layout'] ?? '1up';
        echo render_labels($_SESSION['found_items'], $layout);
        exit;
    } else {
        $error = 'Brak danych do wydruku.';
    }
}

// Czyszczenie sesji (nowe wyszukiwanie)
if (isset($_GET['clear'])) {
    unset($_SESSION['found_items']);
    unset($_SESSION['query_value']);
    // Nie usuwamy danych produktów, bo to plik źródłowy – zostaje w sesji
    header('Location: ' . str_replace('?clear=1', '', $_SERVER['REQUEST_URI']));
    exit;
}

// Obsługa zmiany pliku
if (isset($_GET['clear_file'])) {
    unset($_SESSION['product_data']);
    unset($_SESSION['uploaded_file']);
    unset($_SESSION['found_items']);
    unset($_SESSION['query_value']);
    header('Location: ' . str_replace('?clear_file=1', '', $_SERVER['REQUEST_URI']));
    exit;
}

// Przekazanie wartości do formularza
$query_value = htmlspecialchars($_SESSION['query_value'] ?? $query_value);
$layout = $_POST['layout'] ?? ($_SESSION['last_layout'] ?? '1up');
$_SESSION['last_layout'] = $layout;

?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Generator etykiet produktów z pliku CSV</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        form { max-width: 800px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 6px; background: #f9f9f9; }
        label { display: block; margin-top: 15px; font-weight: bold; }
        input[type="file"], select, textarea { width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        textarea { height: 120px; resize: vertical; }
        .hint { font-size: 12px; color: #666; margin-top: 5px; }
        .no-print { margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap; }
        button, .btn { background: #007cba; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; text-decoration: none; display: inline-block; text-align: center; flex: 1; min-width: 200px; }
        button:hover, .btn:hover { background: #005a87; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-warning:hover { background: #e0a800; }
        .error { color: #d63384; font-weight: bold; margin: 15px 0; padding: 10px; background: #fff5f5; border: 1px solid #ffb8c2; border-radius: 4px; }
        .success { color: #155724; margin: 15px 0; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; }
        .info { color: #0c5460; margin: 10px 0; padding: 10px; background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 4px; }
        .layout-info { margin-top: 5px; font-size: 11px; color: #666; }
        .file-info { margin: 10px 0; padding: 8px; background: #e9ecef; border-radius: 4px; font-size: 14px; }
    </style>
</head>
<body>
    <form method="post" enctype="multipart/form-data">
        <h1>Generator etykiet produktów</h1>

        <?php if (!isset($_SESSION['product_data'])): ?>
            <label for="datafile">1. Wybierz plik CSV z danymi:</label>
            <input type="file" name="datafile" id="datafile" accept=".csv" required>
            <p class="hint">Plik CSV powinien być w formacie UTF-8 lub ANSI. Wymagane kolumny (nagłówki): <strong>Referencja, Nazwa referencji, Nazwa dostawcy, Gama</strong>.<br>Separator: przecinek, średnik. Jeśli plik zawiera inne kolumny, będą ignorowane.</p>
            <button type="submit" name="upload" value="1">Wczytaj dane</button>
        <?php else: ?>
            <div class="file-info">
                ✅ Wczytano plik: <?= htmlspecialchars($_SESSION['uploaded_file'] ?? '') ?><br>
                Liczba produktów: <?= count($_SESSION['product_data']) ?>
                <a href="?clear_file=1" style="margin-left: 10px; color: #dc3545;">[wczytaj inny plik]</a>
            </div>

            <label for="query">2. Wpisz kody referencyjne lub nazwy (można wiele):</label>
            <textarea id="query" name="query" placeholder="Wpisz jeden lub wiele kodów/nazw oddzielonych spacją, przecinkiem lub enterem"><?= $query_value ?></textarea>

            <label for="layout">3. Wybierz układ etykiet:</label>
            <select id="layout" name="layout">
                <option value="1up" <?= $layout === '1up' ? 'selected' : '' ?>>1 etykieta na A4</option>
                <option value="2up" <?= $layout === '2up' ? 'selected' : '' ?>>2 etykiety na A4 (poziomo)</option>
                <option value="4up" <?= $layout === '4up' ? 'selected' : '' ?>>4 etykiety na A4</option>
                <option value="3v" <?= $layout === '3v' ? 'selected' : '' ?>>3 etykiety w pionie</option>
            </select>

            <?php if ($success && isset($_SESSION['found_items'])): ?>
                <div class="layout-info">
                    <strong>Znaleziono <?= count($_SESSION['found_items']) ?> produktów.</strong><br>
                    Przewidywana liczba stron:<br>
                    • 1xA4: <?= ceil(count($_SESSION['found_items']) / 1) ?><br>
                    • 2xA5: <?= ceil(count($_SESSION['found_items']) / 2) ?><br>
                    • 4xA6: <?= ceil(count($_SESSION['found_items']) / 4) ?><br>
                    • 3 paski: <?= ceil(count($_SESSION['found_items']) / 3) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="no-print">
                <?php if ($success && isset($_SESSION['found_items']) && !empty($_SESSION['found_items'])): ?>
                    <button type="submit" name="print" value="1" class="btn-success" formtarget="_blank">
                        🖨️ Otwórz etykiety w nowej zakładce
                    </button>
                    <button type="submit" name="search" value="1" class="btn-warning">
                        🔄 Wyszukaj ponownie (z nowym układem)
                    </button>
                    <button type="button" onclick="location.href='?clear=1'" style="background: #6c757d;">
                        🆕 Nowe wyszukiwanie
                    </button>
                <?php else: ?>
                    <button type="submit" name="search" value="1">Wyszukaj i wygeneruj etykiety</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </form>

    <?php if (!isset($_SESSION['product_data']) && !$error && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <div style="max-width: 800px; margin: 20px auto;">
            <div class="info">
                <strong>Instrukcja:</strong><br>
                1. Przygotuj plik CSV z nagłówkami: <strong>Referencja, Nazwa referencji, Nazwa dostawcy, Gama, Cena standardowa</strong> (separator przecinek).<br>
                2. Wybierz plik i wczytaj dane.<br>
                3. Wpisz referencje lub fragmenty nazw (oddzielone spacją, przecinkiem lub enterem).<br>
                4. Wybierz układ etykiet i wydrukuj.
            </div>
        </div>
    <?php endif; ?>
</body>
</html>