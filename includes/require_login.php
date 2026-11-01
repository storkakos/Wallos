<?php

// For endpoints that fetch from third-party services on the caller's behalf:
// signed-in users only, so they cannot be used as an open proxy.
require_once __DIR__ . '/connect_endpoint.php';

if (empty($userId)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// These requests can take a while; do not hold the session or the database.
session_write_close();
$db->close();
