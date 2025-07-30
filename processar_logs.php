<?php
require_once 'config.php';

// Simula a criação de um novo disparo para agrupar as mensagens
$mysqli->query("INSERT INTO disparos (nome_disparo) VALUES ('Campanha Semana do Evento')");
$disparo_id = $mysqli->insert_id;

$logFile = __DIR__ . '/logs.txt';
$logData = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($logData as $line) {
    $data = json_decode($line, true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['tipo'])) {
        continue; // Pula linha mal formatada
    }

    switch ($data['tipo']) {
        case 'envio':
            $stmt = $mysqli->prepare("INSERT INTO mensagens (disparo_id, message_id, numero, tipo, mensagem, timestamp, status_envio) VALUES (?, ?, ?, 'enviada', ?, ?, 1)");
            $stmt->bind_param("isssi", $disparo_id, $data['id'], $data['para'], $data['mensagem'], $data['timestamp']);
            $stmt->execute();
            break;

        case 'status':
            $stmt = $mysqli->prepare("UPDATE mensagens SET status_envio = ? WHERE message_id LIKE ?");
            $message_id_pattern = '%' . $data['messageId'] . '%';
            $stmt->bind_param("is", $data['status'], $message_id_pattern);
            $stmt->execute();
            break;

        case 'recebido':
            // Insere a mensagem recebida
            $stmt = $mysqli->prepare("INSERT INTO mensagens (message_id, numero, tipo, mensagem, timestamp) VALUES (?, ?, 'recebida', ?, ?)");
            $stmt->bind_param("sssi", $data['id'], $data['de'], $data['mensagem'], $data['timestamp']);
            $stmt->execute();
            $resposta_id = $mysqli->insert_id;

            // Tenta vincular a resposta à última mensagem enviada para aquele número
            $stmt_orig = $mysqli->prepare("SELECT id FROM mensagens WHERE numero = ? AND tipo = 'enviada' ORDER BY timestamp DESC LIMIT 1");
            $stmt_orig->bind_param("s", $data['de']);
            $stmt_orig->execute();
            $result = $stmt_orig->get_result();
            if ($result->num_rows > 0) {
                $original = $result->fetch_assoc();
                $original_id = $original['id'];
                
                $stmt_resp = $mysqli->prepare("INSERT INTO respostas (mensagem_original_id, mensagem_resposta_id) VALUES (?, ?)");
                $stmt_resp->bind_param("ii", $original_id, $resposta_id);
                $stmt_resp->execute();
            }
            break;
    }
}

echo "Processamento de logs concluído!";
$mysqli->close();
?>
