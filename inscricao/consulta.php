<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Obter CPF da requisição
$cpf = isset($_GET['cpf']) ? $_GET['cpf'] : '';

// Limpar o CPF, mantendo apenas números
$cpf = preg_replace('/[^0-9]/', '', $cpf);

// Validar CPF
if (strlen($cpf) != 11) {
    echo json_encode(['error' => 'CPF inválido']);
    exit;
}

try {
    // URL da API com o CPF inserido
    $apiUrl = "https://searchapi.dnnl.live/consulta?token_api=p2&cpf={$cpf}";
    
    // Configurar a requisição cURL
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // Executar a requisição
    $response = curl_exec($ch);
    
    // Verificar por erros
    if (curl_errno($ch)) {
        throw new Exception('Erro na consulta: ' . curl_error($ch));
    }
    
    // Fechar a conexão
    curl_close($ch);
    
    // Decodificar a resposta
    $data = json_decode($response, true);
    
    // Verificar se a resposta é válida
    if (!$data || !isset($data['status'])) {
        throw new Exception('Resposta inválida da API');
    }
    
    // Verificar se encontrou dados do CPF
    if ($data['status'] != 200 || empty($data['dados'])) {
        echo json_encode([
            'success' => false,
            'message' => 'CPF não encontrado ou inválido'
        ]);
        exit;
    }
    
    // Formatar os dados para enviar ao frontend
    $resultado = [
        'success' => true,
        'dados' => $data['dados'][0]
    ];
    
    // Enviar resposta formatada
    echo json_encode($resultado);
    
} catch (Exception $e) {
    // Responder com erro
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 