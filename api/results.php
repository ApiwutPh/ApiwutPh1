<?php
require dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!results_visible()) {
    http_response_code(403);
    echo json_encode(['error' => 'ผลคะแนนยังไม่เปิดเผย'], JSON_UNESCAPED_UNICODE);
    exit;
}

$r = get_results();
echo json_encode($r, JSON_UNESCAPED_UNICODE);
