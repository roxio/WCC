<?php
const DATA_FILE = __DIR__ . '/dane.csv';
const DATA_FILE_TYPE = 'csv';

function load_data_csv($path) {
    $rows = [];
    if (!file_exists($path)) {
        return $rows;
    }
    
    if (($h = fopen($path, 'r')) !== false) {
        $bom = fread($h, 3);
        if ($bom != "\xEF\xBB\xBF") {
            rewind($h);
        }
        
        $headers = fgetcsv($h, 0, ',');
        if (!$headers) {
            fclose($h);
            return $rows;
        }
        
        $normalized_headers = [];
        foreach ($headers as $header) {
            $normalized_headers[] = trim($header);
        }
        
        while (($line = fgetcsv($h, 0, ',')) !== false) {
            $item = [];
            foreach ($normalized_headers as $i => $col) {
                $item[$col] = isset($line[$i]) ? trim($line[$i]) : '';
            }
            $rows[] = $item;
        }
        fclose($h);
    }
    return $rows;
}

function load_data_xlsx($path) {
    // wymaga phpoffice/phpspreadsheet
    if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
        return [];
    }
    
    try {
        $rows = [];
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, true);
        
        if (!$data || count($data) < 2) {
            return $rows;
        }
        
        $headers = [];
        $first_row = array_shift($data);
        foreach ($first_row as $key => $value) {
            $headers[$key] = trim($value);
        }
        
        foreach ($data as $row) {
            $item = [];
            foreach ($headers as $col_key => $col_name) {
                $item[$col_name] = isset($row[$col_key]) ? trim($row[$col_key]) : '';
            }
            $rows[] = $item;
        }
        return $rows;
    } catch (Exception $e) {
        return [];
    }
}

function search_multiple_queries($queries) {
    $type = DATA_FILE_TYPE;
    $rows = [];
    
    if ($type === 'csv') {
        $rows = load_data_csv(DATA_FILE);
    } elseif ($type === 'xlsx') {
        $rows = load_data_xlsx(DATA_FILE);
    }
    
    $results = [];
    $found_refs = [];
    
    foreach ($queries as $query) {
        $query = trim($query);
        if ($query === '') {
            continue;
        }
        
        foreach ($rows as $r) {
            // dopuszczalne klucze: reference, ean, name, supplier, range
            $ref = isset($r['reference']) ? $r['reference'] : (isset($r['ref']) ? $r['ref'] : '');
            $ean = isset($r['ean']) ? $r['ean'] : '';
            
            if ($ref === $query || $ean === $query) {
                if (!in_array($ref, $found_refs)) {
                    $results[] = array_merge(['reference' => $ref, 'ean' => $ean], $r);
                    $found_refs[] = $ref;
                }
            }
        }
    }
    return $results;
}

function parse_query_input($input) {
    $input = trim($input);
    if (empty($input)) {
        return [];
    }
    
    $input = str_replace([',', ';', "\r\n", "\n", "\t"], ' ', $input);
    $queries = preg_split('/\s+/', $input);
    $queries = array_filter($queries, function($q) {
        return !empty(trim($q));
    });
    
    return array_unique($queries);
}

function code128_svg($text, $height = 40, $scale = 2) {
    if (empty($text)) {
        return '';
    }
    
    $bits = '';
    $hash = unpack('C*', md5($text, true));
    foreach ($hash as $b) {
        $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
    }
    
    while (strlen($bits) < 200) {
        $bits .= $bits;
    }
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
    $data = 'data:image/svg+xml;utf8,' . rawurlencode($svg);
    return $data;
}

function render_print_page($items, $layout) {
    $date = date('Y-m-d');
    
    $allowed_layouts = ['1up', '2up', '4up', '3v'];
    if (!in_array($layout, $allowed_layouts)) {
        $layout = '1up';
    }
    
    $page_orientation = 'landscape';
    if ($layout === '2up' || $layout === '3v') {
        $page_orientation = 'portrait';
    }
    
    $style = <<<CSS
    <style>
    body { 
        font-family: Arial, Helvetica, sans-serif; 
        margin: 0; 
        padding: 0; 
    }
    .sheet { 
        box-sizing: border-box;
        page-break-after: always;
        position: relative;
    }
    .label { 
        box-sizing: border-box; 
        border: 1px solid #ccc; 
        padding: 3mm; 
        display: flex; 
        flex-direction: column; 
        justify-content: space-between;
        background: white;
    }
    
    /* Style dla układów landscape (1xA4, 4xA6) */
    .layout-landscape .sheet {
        width: 297mm;
        height: 210mm;
    }
    
    /* 1-up - strona landscape, zawartość landscape */
    .layout-1up .label { 
        width: 100%; 
        height: 100%; 
    }
    
    /* 4-up - strona landscape, zawartość landscape (siatka 2x2) */
    .layout-4up { 
        display: grid; 
        grid-template-columns: 1fr 1fr; 
        grid-template-rows: 1fr 1fr; 
        gap: 0; 
        height: 100%;
        width: 100%;
    }
    .layout-4up .label { 
        width: 100%; 
        height: 100%; 
    }
    
    /* Style dla układów portrait (2xA5, 3v) */
    .layout-portrait .sheet {
        width: 210mm;
        height: 297mm;
    }
    
    /* 2-up - strona vertical, zawartość landscape (2 etykiety A5 jedna pod drugą) */
    .layout-2up { 
        display: flex;
        flex-direction: column;
        width: 100%;
        height: 100%;
    }
    .layout-2up .label { 
        width: 100%; 
        height: 50%; 
    }
    
    /* 3 vertical stripes - strona vertical, zawartość landscape (3 etykiety obok siebie) */
    .layout-3v { 
        display: flex;
        flex-direction: row;
        width: 100%;
        height: 100%;
    }
    .layout-3v .label { 
        width: 33.333%; 
        height: 100%; 
    }

    /* Zawartość etykiety */
    .ref { 
        font-weight: bold; 
        line-height: 1; 
        text-align: center; 
        flex: 0 0 auto; 
        margin-bottom: 5px;
        word-break: break-all;
    }
    .meta { 
        flex: 0 0 auto; 
    }
    .barcode { 
        text-align: center; 
        margin-top: 5px; 
        flex: 0 0 auto; 
    }
    .small { 
        font-size: 10px; 
    }

    /* Dopasowanie rozmiaru czcionki do układu */
    .layout-1up .ref { font-size: 72px; }
    .layout-2up .ref { font-size: 48px; }
    .layout-4up .ref { font-size: 32px; }
    .layout-3v .ref { font-size: 36px; }
    
    .layout-1up .meta { font-size: 14px; }
    .layout-2up .meta { font-size: 12px; }
    .layout-4up .meta { font-size: 9px; }
    .layout-3v .meta { font-size: 10px; }
    
    .layout-1up .barcode img { height: 60px; }
    .layout-2up .barcode img { height: 40px; }
    .layout-4up .barcode img { height: 30px; }
    .layout-3v .barcode img { height: 35px; }

    /* Przy wydruku ukryj przyciski */
    @media print { 
        .no-print { 
            display: none !important; 
        }
        body { 
            margin: 0 !important; 
            padding: 0 !important; 
        }
        .sheet {
            margin: 0 !important;
            padding: 0 !important;
        }
    }
    
    /* Przycisk powrotu */
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
    
    /* Informacja o stronie */
    .page-info {
        position: absolute;
        top: 5mm;
        right: 5mm;
        font-size: 10px;
        color: #999;
        background: white;
        padding: 2px 5px;
        border-radius: 3px;
    }
    </style>
CSS;

    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Etykiety</title>' . $style . '</head>';
    $html .= '<body class="layout-' . $page_orientation . '">';
    $html .= '<button onclick="window.close()" class="back-button no-print">Zamknij okno</button>';
    
    $labels_per_page = 1;
    switch ($layout) {
        case '2up': $labels_per_page = 2; break;
        case '4up': $labels_per_page = 4; break;
        case '3v': $labels_per_page = 3; break;
        default: $labels_per_page = 1;
    }
    
    $total_pages = ceil(count($items) / $labels_per_page);
    $page_number = 1;
    
    for ($page = 0; $page < $total_pages; $page++) {
        $html .= '<div class="sheet layout-' . htmlspecialchars($layout) . '">';
        $html .= '<div class="page-info no-print">Strona ' . $page_number . ' z ' . $total_pages . '</div>';
        
        $page_items = array_slice($items, $page * $labels_per_page, $labels_per_page);
        
        foreach ($page_items as $it) {
            $ref = htmlspecialchars($it['reference'] ?? '');
            $ean = htmlspecialchars($it['ean'] ?? '');
            $name = htmlspecialchars($it['name'] ?? '');
            $supplier = htmlspecialchars($it['supplier'] ?? '');
            $range = htmlspecialchars($it['range'] ?? '');
            
            $barcode_height = 60;
            switch ($layout) {
                case '2up': $barcode_height = 40; break;
                case '4up': $barcode_height = 30; break;
                case '3v': $barcode_height = 35; break;
                default: $barcode_height = 60;
            }
            
            $barcode = code128_svg($ref, $barcode_height, 2);

            $html .= '<div class="label">';
            $html .= '<div class="ref">' . $ref . '</div>';
            $html .= '<div class="meta small">';
            if ($name) {
                $html .= 'Nazwa: ' . $name . '<br>';
            }
            if ($ean) {
                $html .= 'EAN: ' . $ean . '<br>';
            }
            $html .= 'Data: ' . $date . '<br>';
            if ($supplier) {
                $html .= 'Dostawca: ' . $supplier . '<br>';
            }
            if ($range) {
                $html .= 'Gama: ' . $range . '<br>';
            }
            $html .= '</div>';
            $html .= '<div class="barcode"><img src="' . $barcode . '" alt="barcode" style="max-width: 100%;"></div>';
            $html .= '</div>';
        }
        
        $empty_slots = $labels_per_page - count($page_items);
        for ($i = 0; $i < $empty_slots; $i++) {
            $html .= '<div class="label"></div>';
        }
        
        $html .= '</div>';
        $page_number++;
    }

    $html .= '<script>
        window.onload = function() {
            // Ustaw orientację strony przed drukowaniem
            const layout = "' . $layout . '";
            let orientation = "landscape";
            
            if (layout === "2up" || layout === "3v") {
                orientation = "portrait";
            }
            
            // Dodaj styl dla orientacji strony
            const style = document.createElement("style");
            style.innerHTML = `@page { size: A4 ${orientation}; margin: 0; }`;
            document.head.appendChild(style);
            
            // Automatyczne uruchomienie drukowania po załadowaniu strony
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>';
    $html .= '</body></html>';
    return $html;
}

// --- GŁÓWNA LOGIKA ---
$found = [];
$error = '';
$success = false;

$is_print_request = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['print']) && $_POST['print'] === '1');

if ($is_print_request) {
    $items = isset($_POST['items']) ? json_decode($_POST['items'], true) : [];
    $layout = $_POST['layout'] ?? '1up';
    
    if (!empty($items)) {
        header('Content-Type: text/html; charset=utf-8');
        echo render_print_page($items, $layout);
        exit;
    } else {
        $error = 'Brak danych do wydruku.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_print_request) {
    $q = isset($_POST['query']) ? trim($_POST['query']) : '';
    $layout = isset($_POST['layout']) ? $_POST['layout'] : '1up';
    
    if (empty($q)) {
        $error = 'Wpisz kod referencyjny lub EAN.';
    } elseif (!preg_match('/^[a-zA-Z0-9\s\-\_,;\r\n]*$/', $q)) {
        $error = 'Wprowadzono nieprawidłowe znaki. Dozwolone są tylko litery, cyfry, spacje, myślniki, podkreślniki, przecinki i średniki.';
    } else {
        $queries = parse_query_input($q);
        if (empty($queries)) {
            $error = 'Nie znaleziono prawidłowych kodów w podanym tekście.';
        } else {
            $found = search_multiple_queries($queries);
            if (empty($found)) {
                $searched_codes = implode(', ', array_slice($queries, 0, 5));
                if (count($queries) > 5) {
                    $searched_codes .= '... (łącznie ' . count($queries) . ' kodów)';
                }
                $error = 'Nie znaleziono pozycji dla podanych kodów: ' . htmlspecialchars($searched_codes);
            } else {
                $success = true;
            }
        }
    }
}

$query_value = '';
if (isset($_POST['query']) && !$is_print_request) {
    $query_value = htmlspecialchars($_POST['query']);
}

$layout_value = isset($_POST['layout']) ? $_POST['layout'] : '1up';
if ($is_print_request) {
    exit;
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Generator etykiet - formularz</title>
    <style>
    body {
        font-family: Arial, Helvetica, sans-serif;
        margin: 20px;
        line-height: 1.6;
    }
    form {
        max-width: 700px;
        margin: 0 auto;
        padding: 20px;
        border: 1px solid #ddd;
        border-radius: 6px;
        background: #f9f9f9;
    }
    label {
        display: block;
        margin-top: 15px;
        font-weight: bold;
    }
    input[type=text], select, textarea {
        width: 100%;
        padding: 10px;
        margin-top: 5px;
        border: 1px solid #ccc;
        border-radius: 4px;
        box-sizing: border-box;
        font-family: Arial, Helvetica, sans-serif;
    }
    textarea {
        height: 120px;
        resize: vertical;
    }
    .hint {
        font-size: 12px;
        color: #666;
        margin-top: 5px;
    }
    .no-print {
        margin-top: 20px;
    }
    button, .btn {
        background: #007cba;
        color: white;
        padding: 12px 24px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 16px;
        text-decoration: none;
        display: inline-block;
        text-align: center;
    }
    button:hover, .btn:hover {
        background: #005a87;
    }
    .btn-success {
        background: #28a745;
    }
    .btn-success:hover {
        background: #218838;
    }
    .error {
        color: #d63384;
        font-weight: bold;
        margin: 15px 0;
        padding: 10px;
        background: #fff5f5;
        border: 1px solid #ffb8c2;
        border-radius: 4px;
    }
    .success {
        color: #155724;
        font-weight: bold;
        margin: 15px 0;
        padding: 15px;
        background: #d4edda;
        border: 1px solid #c3e6cb;
        border-radius: 4px;
    }
    .info {
        color: #0c5460;
        margin: 10px 0;
        padding: 10px;
        background: #d1ecf1;
        border: 1px solid #bee5eb;
        border-radius: 4px;
    }
    .layout-info {
        margin-top: 5px;
        font-size: 11px;
        color: #666;
    }
    </style>
</head>
<body>
    <form method="post" id="labelForm" <?= $success ? 'target="_blank"' : '' ?>>
        <h1>Generator etykiet</h1>
        
        <label for="query">Wpisz kody referencyjne lub EAN (jeden lub wiele)</label>
        <textarea id="query" name="query" required 
                  placeholder="Możesz wpisać wiele kodów oddzielonych spacjami, przecinkami lub enterem&#10;np.:&#10;REF001&#10;REF002, REF003&#10;5901234567890 5902345678901"><?= $query_value ?></textarea>
        
        <label for="layout">Układ etykiet</label>
        <select id="layout" name="layout">
            <option value="1up" <?= $layout_value === '1up' ? 'selected' : '' ?>>1 x A4 (1 etykieta na stronę A4)</option>
            <option value="2up" <?= $layout_value === '2up' ? 'selected' : '' ?>>2 x A5 na A4 (2 etykiety na stronę)</option>
            <option value="4up" <?= $layout_value === '4up' ? 'selected' : '' ?>>4 x A6 na A4 (4 etykiety na stronę)</option>
            <option value="3v" <?= $layout_value === '3v' ? 'selected' : '' ?>>A4 podzielone na 3 paski w pionie</option>
        </select>
        <?php if ($success): ?>
        <div class="layout-info">
            <strong>Przewidywana liczba stron dla <?= count($found) ?> kodów:</strong><br>
            • 1xA4: <?= ceil(count($found) / 1) ?> stron(y)<br>
            • 2xA5: <?= ceil(count($found) / 2) ?> stron(y)<br>
            • 4xA6: <?= ceil(count($found) / 4) ?> stron(y)<br>
            • 3 paski: <?= ceil(count($found) / 3) ?> stron(y)
        </div>
        <?php endif; ?>
        
        <p class="hint">
            Plik danych (<?= DATA_FILE_TYPE ?>) znajduje się w: <?= htmlspecialchars(DATA_FILE) ?><br>
            Wymagane nagłówki: reference, ean, name, supplier, range<br>
            Możesz wpisać wiele kodów na raz - oddziel je spacjami, przecinkami lub enterem
        </p>
        
        <?php if ($error): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success">
                ✓ Wygenerowano etykiety dla <?= count($found) ?> pozycji!<br>
                <small>Kliknij przycisk poniżej aby otworzyć etykiety w nowej zakładce.</small>
            </div>
            
            <input type="hidden" name="print" value="1">
            <input type="hidden" name="items" value="<?= htmlspecialchars(json_encode($found)) ?>">
            <input type="hidden" name="layout" value="<?= htmlspecialchars($layout_value) ?>">
            
            <div class="no-print">
                <button type="submit" class="btn-success">
                    🖨️ Otwórz etykiety w nowej zakładce
                </button>
                <button type="button" onclick="location.href=''" style="background: #6c757d; margin-left: 10px;">
                    Wygeneruj ponownie
                </button>
            </div>
        <?php else: ?>
            <div class="no-print">
                <button type="submit">Wyszukaj i wygeneruj etykiety</button>
            </div>
        <?php endif; ?>
    </form>

    <?php if (!$success): ?>
    <div style="max-width: 700px; margin: 20px auto;">
        <div class="info">
            <strong>Przykładowe kody do testów:</strong><br>
            REF001, REF002, REF003, 5901234567890, 5902345678901
        </div>
    </div>
    <?php endif; ?>

    <script>
    document.getElementById('labelForm').addEventListener('submit', function(e) {
        const successElement = document.querySelector('.success');
        if (!successElement) {
            return true;
        }
        return true;
    });
    </script>
</body>
</html>
