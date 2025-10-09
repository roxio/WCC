<?php
// KONFIGURACJA
const DATA_FILE = __DIR__ . '/dane.csv';
const DATA_FILE_TYPE = 'csv';

// --- pomocnicze funkcje ---
function load_data_csv($path) {
    $rows = [];
    if (!file_exists($path)) {
        return $rows;
    }
    
    if (($h = fopen($path, 'r')) !== false) {
        // Sprawdź BOM dla UTF-8
        $bom = fread($h, 3);
        if ($bom != "\xEF\xBB\xBF") {
            rewind($h);
        }
        
        $headers = fgetcsv($h, 0, ',');
        if (!$headers) {
            fclose($h);
            return $rows;
        }
        
        // Normalizuj nagłówki
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

function search_data($query) {
    $type = DATA_FILE_TYPE;
    $rows = [];
    
    if ($type === 'csv') {
        $rows = load_data_csv(DATA_FILE);
    } elseif ($type === 'xlsx') {
        $rows = load_data_xlsx(DATA_FILE);
    }
    
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    
    $results = [];
    foreach ($rows as $r) {
        // dopuszczalne klucze: reference, ean, name, supplier, range
        $ref = isset($r['reference']) ? $r['reference'] : (isset($r['ref']) ? $r['ref'] : '');
        $ean = isset($r['ean']) ? $r['ean'] : '';
        
        if ($ref === $query || $ean === $query || 
            stripos($ref, $query) !== false || 
            stripos($ean, $query) !== false) {
            $results[] = array_merge(['reference' => $ref, 'ean' => $ean], $r);
        }
    }
    return $results;
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
    
    $style = <<<CSS
    <style>
    @page { 
        size: A4; 
        margin: 5mm; 
    }
    body { 
        font-family: Arial, Helvetica, sans-serif; 
        margin: 0; 
        padding: 0; 
    }
    .sheet { 
        width: 210mm; 
        height: 297mm; 
        box-sizing: border-box; 
        page-break-after: always;
    }
    .label { 
        box-sizing: border-box; 
        border: 1px solid #ccc; 
        padding: 4mm; 
        display: flex; 
        flex-direction: column; 
        justify-content: space-between; 
    }
    /* 1-up */
    .layout-1up .label { 
        width: 100%; 
        height: 100%; 
    }
    /* 2-up (2x A5 stacked vertically) */
    .layout-2up { 
        display: flex; 
        flex-direction: column; 
        gap: 0; 
        height: 100%;
    }
    .layout-2up .label { 
        width: 100%; 
        height: calc(50% - 1mm); 
    }
    /* 4-up (4x A6) */
    .layout-4up { 
        display: grid; 
        grid-template-columns: 1fr 1fr; 
        grid-template-rows: 1fr 1fr; 
        gap: 0; 
        height: 100%;
    }
    .layout-4up .label { 
        width: 100%; 
        height: 100%; 
    }
    /* 3 vertical stripes */
    .layout-3v { 
        display: grid; 
        grid-template-columns: repeat(3, 1fr); 
        grid-template-rows: 1fr; 
        gap: 0; 
        height: 100%;
    }
    .layout-3v .label { 
        height: 100%; 
    }

    /* Zawartość etykiety */
    .ref { 
        font-weight: bold; 
        font-size: 40px; 
        line-height: 1; 
        text-align: center; 
        flex: 0 0 auto; 
    }
    .meta { 
        font-size: 12px; 
        margin-top: 6px; 
        flex: 0 0 auto; 
    }
    .barcode { 
        text-align: center; 
        margin-top: 6px; 
        flex: 0 0 auto; 
    }
    .small { 
        font-size: 10px; 
    }
    .ref.big { 
        font-size: 48px; 
    }

    /* Przy wydruku ukryj przyciski */
    @media print { 
        .no-print { 
            display: none !important; 
        }
        body { 
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
    </style>
CSS;

    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Etykiety</title>' . $style . '</head><body>';
    $html .= '<button onclick="window.history.back()" class="back-button no-print">Powrót do wyszukiwania</button>';
    $html .= '<div class="sheet layout-' . htmlspecialchars($layout) . '">';

    foreach ($items as $it) {
        $ref = htmlspecialchars($it['reference'] ?? '');
        $ean = htmlspecialchars($it['ean'] ?? '');
        $name = htmlspecialchars($it['name'] ?? '');
        $supplier = htmlspecialchars($it['supplier'] ?? '');
        $range = htmlspecialchars($it['range'] ?? '');
        $barcode = code128_svg($ref, 40, 2);

        $html .= '<div class="label">';
        $html .= '<div class="ref big">' . $ref . '</div>';
        $html .= '<div class="meta small">';
        if ($name) {
            $html .= 'Nazwa: ' . $name . '<br>';
        }
        if ($ean) {
            $html .= 'EAN: ' . $ean . '<br>';
        }
        $html .= 'Data wydruku: ' . $date . '<br>';
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

    $item_count = count($items);
    $needed = 0;
    
    switch ($layout) {
        case '4up':
            $needed = (4 - ($item_count % 4)) % 4;
            break;
        case '2up':
            $needed = (2 - ($item_count % 2)) % 2;
            break;
        case '3v':
            $needed = (3 - ($item_count % 3)) % 3;
            break;
        default:
            $needed = 0;
    }
    
    for ($i = 0; $i < $needed; $i++) {
        $html .= '<div class="label"></div>';
    }

    $html .= '</div>';
    $html .= '<script>
        window.onload = function() {
            // Automatyczne uruchomienie drukowania po załadowaniu strony
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>';
    $html .= '</body></html>';
    return $html;
}

// --- obsługa formularza i generowanie etykiet ---
$found = [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $q = isset($_POST['query']) ? trim($_POST['query']) : '';
    $layout = isset($_POST['layout']) ? $_POST['layout'] : '1up';
    
    if (empty($q)) {
        $error = 'Wpisz kod referencyjny lub EAN.';
    } elseif (!preg_match('/^[a-zA-Z0-9\s\-_]*$/', $q)) {
        $error = 'Wprowadzono nieprawidłowe znaki. Dozwolone są tylko litery, cyfry, spacje, myślniki i podkreślniki.';
    } else {
        $found = search_data($q);
        if (empty($found)) {
            $error = 'Nie znaleziono pozycji dla: ' . htmlspecialchars($q);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo render_print_page($found, $layout);
            exit;
        }
    }
}

$query_value = '';
if (isset($_POST['query'])) {
    $query_value = htmlspecialchars($_POST['query']);
}

$layout_value = isset($_POST['layout']) ? $_POST['layout'] : '1up';
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
    input[type=text], select {
        width: 100%;
        padding: 10px;
        margin-top: 5px;
        border: 1px solid #ccc;
        border-radius: 4px;
        box-sizing: border-box;
    }
    .hint {
        font-size: 12px;
        color: #666;
        margin-top: 5px;
    }
    .no-print {
        margin-top: 20px;
    }
    button {
        background: #007cba;
        color: white;
        padding: 12px 24px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 16px;
    }
    button:hover {
        background: #005a87;
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
        color: #0d6efd;
        margin: 15px 0;
        padding: 10px;
        background: #f0f9ff;
        border: 1px solid #b6d4fe;
        border-radius: 4px;
    }
    </style>
</head>
<body>
    
    
    <form method="post">
	<h1>Generator etykiet</h1>
        <label for="query">Wpisz kod referencyjny lub EAN</label>
        <input type="text" id="query" name="query" value="<?= $query_value ?>" required 
               placeholder="np. REF12345 lub 1234567890123">
        
        <label for="layout">Układ etykiet</label>
        <select id="layout" name="layout">
            <option value="1up" <?= $layout_value === '1up' ? 'selected' : '' ?>>1 x A4 (1 etykieta na stronę A4)</option>
            <option value="2up" <?= $layout_value === '2up' ? 'selected' : '' ?>>2 x A5 na A4 (2 etykiety na stronę)</option>
            <option value="4up" <?= $layout_value === '4up' ? 'selected' : '' ?>>4 x A6 na A4 (4 etykiety na stronę)</option>
            <option value="3v" <?= $layout_value === '3v' ? 'selected' : '' ?>>A4 podzielone na 3 paski w pionie</option>
        </select>
        
        <p class="hint">
            Plik danych (<?= DATA_FILE_TYPE ?>) znajduje się w: <?= htmlspecialchars(DATA_FILE) ?><br>
            Wymagane nagłówki: reference, ean, name, supplier, range
        </p>
        
        <?php if ($error): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>
        
        <?php if (!empty($found) && empty($error)): ?>
            <div class="success">Znaleziono <?= count($found) ?> pozycji</div>
        <?php endif; ?>
        
        <div class="no-print">
            <button type="submit">Wyszukaj i wygeneruj etykiety</button>
        </div>
    </form>
</body>
</html>
