<?php
header('Content-Type: application/json');

// Capturar todos os dados recebidos
$post_data = $_POST;
$raw_data = file_get_contents('php://input');
$server = $_SERVER;

// Salvar os dados em um arquivo de log para análise
$log_file = __DIR__ . '/debug-post.log';
file_put_contents($log_file, date('Y-m-d H:i:s') . "\n", FILE_APPEND);
file_put_contents($log_file, "POST DATA: " . print_r($post_data, true) . "\n", FILE_APPEND);
file_put_contents($log_file, "RAW DATA: " . $raw_data . "\n", FILE_APPEND);
file_put_contents($log_file, "CONTENT TYPE: " . ($server['CONTENT_TYPE'] ?? 'not set') . "\n", FILE_APPEND);
file_put_contents($log_file, "----------------------------------\n", FILE_APPEND);

// Retornar os dados para o cliente para debug
echo json_encode([
    'post' => $post_data,
    'raw' => $raw_data,
    'content_type' => $server['CONTENT_TYPE'] ?? 'not set'
]);
?> 