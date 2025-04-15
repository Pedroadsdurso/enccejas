<?php
header('Content-Type: application/json');

// Habilita o log de erros
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Recebe o payload do webhook
$payload = file_get_contents('php://input');
$event = json_decode($payload, true);

// Log do payload recebido
error_log("[Webhook-Obrigado] 🔄 Iniciando processamento do webhook");
error_log("[Webhook-Obrigado] 📦 Payload recebido: " . $payload);

// Verifica se o payload é válido
if (!$event || !isset($event['paymentId']) || !isset($event['status'])) {
    error_log("[Webhook-Obrigado] ❌ Payload inválido recebido. Campos necessários não encontrados");
    error_log("[Webhook-Obrigado] 🔍 Campos disponíveis: " . print_r(array_keys($event ?? []), true));
    http_response_code(400);
    echo json_encode(['error' => 'Payload inválido']);
    exit;
}

try {
    error_log("[Webhook-Obrigado] ℹ️ Processando pagamento ID: " . $event['paymentId'] . " com status: " . $event['status']);
    
    // Conecta ao SQLite
    $dbPath = __DIR__ . '/database.sqlite';
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    error_log("[Webhook-Obrigado] ✅ Conexão com banco de dados estabelecida");

    // Atualiza o status do pagamento no banco de dados
    $stmt = $db->prepare("UPDATE pedidos SET status = :status, updated_at = :updated_at WHERE transaction_id = :transaction_id");
    
    $novoStatus = $event['status'] === 'APPROVED' ? 'paid' : $event['status'];
    error_log("[Webhook-Obrigado] 🔄 Atualizando status para: " . $novoStatus);
    
    $result = $stmt->execute([
        'status' => $novoStatus,
        'updated_at' => date('c'),
        'transaction_id' => $event['paymentId']
    ]);

    if ($stmt->rowCount() === 0) {
        error_log("[Webhook-Obrigado] ⚠️ Nenhum pedido encontrado com o ID: " . $event['paymentId']);
        error_log("[Webhook-Obrigado] 🔍 Verificando se o pedido existe no banco...");
        
        // Verifica se o pedido existe
        $checkStmt = $db->prepare("SELECT * FROM pedidos WHERE transaction_id = :transaction_id");
        $checkStmt->execute(['transaction_id' => $event['paymentId']]);
        $pedidoExiste = $checkStmt->fetch();
        
        if ($pedidoExiste) {
            error_log("[Webhook-Obrigado] ℹ️ Pedido encontrado mas status não foi alterado. Status atual: " . $pedidoExiste['status']);
        } else {
            error_log("[Webhook-Obrigado] ❌ Pedido não existe no banco de dados");
        }
        
        http_response_code(404);
        echo json_encode(['error' => 'Pedido não encontrado']);
        exit;
    }

    error_log("[Webhook-Obrigado] ✅ Status atualizado com sucesso no banco de dados");

    // Responde imediatamente ao webhook
    http_response_code(200);
    echo json_encode(['success' => true]);
    
    // Fecha a conexão com o cliente
    if (function_exists('fastcgi_finish_request')) {
        error_log("[Webhook-Obrigado] 📤 Fechando conexão com o cliente via fastcgi_finish_request");
        fastcgi_finish_request();
    } else {
        error_log("[Webhook-Obrigado] ⚠️ fastcgi_finish_request não disponível");
    }
    
    // Continua o processamento em background
    if ($event['status'] === 'APPROVED') {
        error_log("[Webhook-Obrigado] ✅ Pagamento aprovado, iniciando processamento em background");

        // Busca os dados do pedido
        $stmt = $db->prepare("SELECT * FROM pedidos WHERE transaction_id = :transaction_id");
        $stmt->execute(['transaction_id' => $event['paymentId']]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pedido) {
            error_log("[Webhook-Obrigado] ✅ Dados do pedido recuperados do banco");
            error_log("[Webhook-Obrigado] 📊 Dados do pedido: " . print_r($pedido, true));

            // Decodifica os parâmetros UTM do banco
            $utmParams = json_decode($pedido['utm_params'], true);
            error_log("[Webhook-Obrigado] 📊 UTM Params brutos do banco: " . print_r($utmParams, true));
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("[Webhook-Obrigado] ⚠️ Erro ao decodificar UTM params: " . json_last_error_msg());
            }

            // Extrai os parâmetros UTM, garantindo que todos os campos necessários existam
            $trackingParameters = [
                'src' => $utmParams['utm_source'] ?? null,
                'sck' => $utmParams['sck'] ?? null,
                'utm_source' => $utmParams['utm_source'] ?? null,
                'utm_campaign' => $utmParams['utm_campaign'] ?? null,
                'utm_medium' => $utmParams['utm_medium'] ?? null,
                'utm_content' => $utmParams['utm_content'] ?? null,
                'utm_term' => $utmParams['utm_term'] ?? null,
                'fbclid' => $utmParams['fbclid'] ?? null,
                'gclid' => $utmParams['gclid'] ?? null,
                'ttclid' => $utmParams['ttclid'] ?? null,
                'xcod' => $utmParams['xcod'] ?? null
            ];

            // Remove valores null para manter apenas os parâmetros que existem
            $trackingParameters = array_filter($trackingParameters, function($value) {
                return $value !== null;
            });

            error_log("[Webhook-Obrigado] 📊 Tracking Parameters processados: " . print_r($trackingParameters, true));

            $utmifyData = [
                'orderId' => $event['paymentId'],
                'platform' => 'Ghostspays',
                'paymentMethod' => 'pix',
                'status' => 'paid',
                'createdAt' => $pedido['created_at'],
                'approvedDate' => date('Y-m-d H:i:s'),
                'paidAt' => date('Y-m-d H:i:s'),
                'refundedAt' => null,
                'customer' => [
                    'name' => $pedido['nome'],
                    'email' => $pedido['email'],
                    'phone' => null,
                    'document' => [
                        'number' => $pedido['cpf'],
                        'type' => 'CPF'
                    ],
                    'country' => 'BR',
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null
                ],
                'items' => [
                    [
                        'id' => uniqid('PROD_'),
                        'title' => 'Tarifa Regional de Aplicação da Prova',
                        'quantity' => 1,
                        'unitPrice' => $pedido['valor']
                    ]
                ],
                'amount' => $pedido['valor'],
                'fee' => [
                    'fixedAmount' => 0,
                    'netAmount' => $pedido['valor']
                ],
                'trackingParameters' => $trackingParameters,
                'isTest' => false
            ];

            error_log("[Webhook-Obrigado] 📦 Payload completo para utmify: " . json_encode($utmifyData));

            // Verifica se o arquivo utmify.php existe na pasta pagamento
            $utmifyPath = dirname(__DIR__) . "/encceja/pagamento/utmify.php";
            if (file_exists($utmifyPath)) {
                // Envia para utmify.php na pasta pagamento
                $serverUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
                $utmifyUrl = $serverUrl . "/encceja/pagamento/utmify.php";
                error_log("[Webhook-Obrigado] 🌐 Enviando dados para URL: " . $utmifyUrl);

                $ch = curl_init($utmifyUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($utmifyData),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_TIMEOUT => 30
                ]);

                $utmifyResponse = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                
                error_log("[Webhook-Obrigado] 📤 Resposta do utmify (HTTP $httpCode): " . $utmifyResponse);
                if ($curlError) {
                    error_log("[Webhook-Obrigado] ❌ Erro ao enviar para utmify: " . $curlError);
                } else {
                    error_log("[Webhook-Obrigado] 📊 Resposta decodificada: " . print_r(json_decode($utmifyResponse, true), true));
                }
                
                curl_close($ch);
            } else {
                error_log("[Webhook-Obrigado] ⚠️ Arquivo utmify.php não encontrado. Pulando esta etapa.");
            }
            
            error_log("[Webhook-Obrigado] ✅ Processamento em background concluído");
        } else {
            error_log("[Webhook-Obrigado] ❌ Não foi possível recuperar os dados do pedido do banco");
        }
    } else {
        error_log("[Webhook-Obrigado] ℹ️ Status não é APPROVED, pulando processamento em background");
    }

} catch (Exception $e) {
    error_log("[Webhook-Obrigado] ❌ Erro: " . $e->getMessage());
    error_log("[Webhook-Obrigado] 🔍 Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
} 