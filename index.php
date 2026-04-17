<?php

define('SEARCH_PAGE_URL', 'https://paksim.info/search-free-sim-database-online-2022.php');
define('FALLBACK_ACTION', 'https://paksim.info/sim-database-online-2022-result.php');
define('FALLBACK_PARAM',  'cnnum');
define('USER_AGENT', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$cnnum = trim($_REQUEST['num'] ?? '');
if ($cnnum === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameter: num']);
    exit;
}
if (!preg_match('/^\d{10,13}$/', $cnnum)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid phone number format (must be 10-13 digits)']);
    exit;
}

$detected = detectFormEndpoint(SEARCH_PAGE_URL);
if (!$detected) {
    $detected = [
        'action' => FALLBACK_ACTION,
        'param'  => FALLBACK_PARAM
    ];
}

$result = fetchSimData($detected['action'], $detected['param'], $cnnum);

if (isset($result['error'])) {
    http_response_code(404);
    echo json_encode($result);
    exit;
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;

function detectFormEndpoint($searchUrl) {
    $ch = curl_init($searchUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
        CURLOPT_TIMEOUT        => 10
    ]);

    $html = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || !$html) return false;

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $forms = $xpath->query("//form[translate(@method,'POST','post')='post']");

    foreach ($forms as $form) {
        $action = $form->getAttribute('action');
        if (!preg_match('/^https?:\/\//i', $action)) {
            $action = 'https://paksim.info/' . ltrim($action, '/');
        }

        $inputs = $xpath->query(".//input[@type='text']", $form);
        if ($inputs->length > 0) {
            $paramName = $inputs->item(0)->getAttribute('name');
            if ($action !== '' && $paramName !== '') {
                return ['action' => $action, 'param' => $paramName];
            }
        }
    }
    return false;
}

function fetchSimData($endpoint, $paramName, $cnnum) {
    $postData = http_build_query([$paramName => $cnnum]);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: ' . USER_AGENT,
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://paksim.info',
            'Referer: ' . SEARCH_PAGE_URL
        ],
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['error' => 'cURL error: ' . $error];
    }
    if ($httpCode !== 200) {
        return ['error' => "HTTP error $httpCode from target"];
    }
    if (stripos($response, 'No record') !== false || stripos($response, 'not found') !== false) {
        return ['error' => 'No records found for this number'];
    }

    return parseHtmlResponse($response);
}

function parseHtmlResponse($html) {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    $recordsFound = 0;
    $headerNodes = $xpath->query("//h1[contains(., 'Records Found')]");
    if ($headerNodes->length > 0) {
        $headerText = $headerNodes->item(0)->textContent;
        if (preg_match('/Records Found:\s*(\d+)/i', $headerText, $matches)) {
            $recordsFound = (int)$matches[1];
        }
    }

    $tables = $xpath->query("//table[contains(@class, 'tg')]");
    if ($tables->length === 0) {
        return ['error' => 'Could not locate data table in response'];
    }

    $allRecords = [];

    foreach ($tables as $table) {
        $record = [];

        $rows = $xpath->query(".//tr", $table);
        foreach ($rows as $row) {
            $cells = $xpath->query(".//td", $row);
            if ($cells->length < 2) continue;

            $label = trim($cells->item(0)->textContent);
            $value = trim($cells->item(1)->textContent);

            $label = preg_replace('/[^\p{L}\p{N}]/u', '', $label);
            $label = strtolower($label);

            switch ($label) {
                case 'mobileno':
                    $record['mobile'] = $value;
                    break;
                case 'name':
                    $record['name'] = $value;
                    break;
                case 'cnic':
                    if (preg_match('/(\d{13,15})/', $value, $matches)) {
                        $record['cnic'] = $matches[1];
                    }
                    break;
                case 'address':
                    $record['address'] = $value;
                    break;
                case 'operator':
                    $record['operator'] = $value;
                    break;
            }
        }

        if (!empty($record)) {
            $allRecords[] = $record;
        }
    }

    if (empty($allRecords)) {
        return ['error' => 'No subscriber data extracted'];
    }

    $finalCount = ($recordsFound > 0) ? $recordsFound : count($allRecords);

    return [
        'count'   => $finalCount,
        'records' => $allRecords
    ];
}
