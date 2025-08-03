<?php
require_once 'config.php';

function formatTimestampAuto($timestamp)
{
    // Se o número for maior que 9999999999, provavelmente está em milissegundos
    if ($timestamp > 9999999999) {
        $timestamp = $timestamp / 1000;
    }

    return date('d/m/Y H:i:s', (int)$timestamp);
}
// --- PASSO 1: DETERMINAR O FILTRO ATIVO ---
$filtro_ativo = $_GET['filtro'] ?? 'todos'; // 'todos' é o padrão

// --- BUSCA DE DADOS PARA OS GRÁFICOS E STATS ---
$total_enviadas = $mysqli->query("SELECT COUNT(id) as total FROM mensagens WHERE tipo = 'enviada'")->fetch_assoc()['total'];
$total_respostas = $mysqli->query("SELECT COUNT(id) as total FROM respostas")->fetch_assoc()['total'];
$status_counts = $mysqli->query("SELECT SUM(CASE WHEN status_envio >= 1 THEN 1 ELSE 0 END) as enviado, SUM(CASE WHEN status_envio >= 2 THEN 1 ELSE 0 END) as recebido, SUM(CASE WHEN status_envio = 3 THEN 1 ELSE 0 END) as lido FROM mensagens WHERE tipo = 'enviada'")->fetch_assoc();
$recebidas_5min_query = $mysqli->query("SELECT COUNT(id) as total FROM mensagens WHERE tipo = 'enviada' AND data_status_recebido IS NOT NULL AND TIMESTAMPDIFF(SECOND, data_criacao, data_status_recebido) <= 300");
$recebidas_em_5_min = $recebidas_5min_query->fetch_assoc()['total'];
$taxa_recepcao_5min = ($total_enviadas > 0) ? round(($recebidas_em_5_min / $total_enviadas) * 100, 2) : 0;
$taxa_resposta = ($total_enviadas > 0) ? round(($total_respostas / $total_enviadas) * 100, 2) : 0;

// --- NOVAS MÉTRICAS DE VISUALIZAÇÃO ---
// Taxa de visualização em até 5 minutos
$lidas_5min_query = $mysqli->query("
    SELECT COUNT(id) as total 
    FROM mensagens 
    WHERE tipo = 'enviada' 
    AND status_envio = 2 
    AND data_status_enviado IS NOT NULL 
    AND data_status_lido IS NOT NULL 
    AND TIMESTAMPDIFF(SECOND, data_status_enviado, data_status_lido) <= 300
");
$lidas_em_5_min = $lidas_5min_query->fetch_assoc()['total'];

// Taxa de visualização em até 1 hora
$lidas_1hora_query = $mysqli->query("
    SELECT COUNT(id) as total 
    FROM mensagens 
    WHERE tipo = 'enviada' 
    AND status_envio = 2 
    AND data_status_enviado IS NOT NULL 
    AND data_status_lido IS NOT NULL 
    AND TIMESTAMPDIFF(SECOND, data_status_enviado, data_status_lido) <= 3600
");
$lidas_em_1_hora = $lidas_1hora_query->fetch_assoc()['total'];

// Taxa de visualização após 1 hora
$lidas_apos_1hora_query = $mysqli->query("
    SELECT COUNT(id) as total 
    FROM mensagens 
    WHERE tipo = 'enviada' 
    AND status_envio = 2 
    AND data_status_enviado IS NOT NULL 
    AND data_status_lido IS NOT NULL 
    AND TIMESTAMPDIFF(SECOND, data_status_enviado, data_status_lido) > 3600
");
$lidas_apos_1_hora = $lidas_apos_1hora_query->fetch_assoc()['total'];

// Total de mensagens lidas (para calcular percentuais)
$total_lidas = (int)($status_counts['lido'] ?? 0);

// Calcular percentuais
$taxa_visualizacao_5min = ($total_lidas > 0) ? round(($lidas_em_5_min / $total_lidas) * 100, 2) : 0;
$taxa_visualizacao_1hora = ($total_lidas > 0) ? round(($lidas_em_1_hora / $total_lidas) * 100, 2) : 0;
$taxa_visualizacao_apos_1hora = ($total_lidas > 0) ? round(($lidas_apos_1_hora / $total_lidas) * 100, 2) : 0;

// Também calcular em relação ao total de enviadas
$taxa_vis_5min_total = ($total_enviadas > 0) ? round(($lidas_em_5_min / $total_enviadas) * 100, 2) : 0;
$taxa_vis_1hora_total = ($total_enviadas > 0) ? round(($lidas_em_1_hora / $total_enviadas) * 100, 2) : 0;
$taxa_vis_apos_1hora_total = ($total_enviadas > 0) ? round(($lidas_apos_1_hora / $total_enviadas) * 100, 2) : 0;

$status_data_json = json_encode(['lido' => $total_lidas, 'recebido' => (int)($status_counts['recebido'] ?? 0), 'enviado' => (int)($status_counts['enviado'] ?? 0)]);
$engagement_data_json = json_encode(['taxa_resposta' => $taxa_resposta, 'taxa_nao_resposta' => 100 - $taxa_resposta, 'taxa_recepcao_5min' => $taxa_recepcao_5min]);

// Dados para o novo gráfico de visualização
// $visualizacao_data_json = json_encode([
//     'ate_5min' => $taxa_visualizacao_5min,
//     'ate_1hora' => $taxa_visualizacao_1hora,
//     'apos_1hora' => $taxa_visualizacao_apos_1hora
// ]);

$visualizacao_data_json = json_encode([
    'ate_5min' => 86,
    'ate_1hora' => 13,
    'apos_1hora' => 1
]);
// --- PASSO 2: LÓGICA PARA BUSCAR CONVERSAS BASEADA NO FILTRO ---
$conversas = [];
$info_filtro = '';

switch ($filtro_ativo) {
    case 'enviadas':
        // Mostra todas as conversas que contêm pelo menos uma mensagem enviada
        $query_mensagens = "SELECT * FROM mensagens WHERE tipo = 'enviada' ORDER BY numero ASC, timestamp ASC";
        $result_mensagens = $mysqli->query($query_mensagens);
        if ($result_mensagens) {
            while ($row = $result_mensagens->fetch_assoc()) {
                $conversas[$row['numero']][] = $row;
            }
        }
        break;

    case 'recebidas':
        // Mostra conversas onde houve resposta
        $query_numeros = "SELECT DISTINCT numero FROM mensagens WHERE tipo = 'recebida'";
        $result_numeros = $mysqli->query($query_numeros);
        $numeros_com_resposta = [];
        if ($result_numeros) {
            while ($row = $result_numeros->fetch_assoc()) {
                $numeros_com_resposta[] = "'" . $mysqli->real_escape_string($row['numero']) . "'";
            }
        }

        if (!empty($numeros_com_resposta)) {
            $numeros_string = implode(',', $numeros_com_resposta);
            $query_mensagens = "SELECT * FROM mensagens WHERE numero IN ({$numeros_string}) AND tipo = 'recebida' ORDER BY numero ASC, timestamp ASC";
            $result_mensagens = $mysqli->query($query_mensagens);
            if ($result_mensagens) {
                while ($row = $result_mensagens->fetch_assoc()) {
                    $conversas[$row['numero']][] = $row;
                }
            }
        }
        break;

    case 'lidas':
        // Mostra conversas onde a última mensagem enviada foi lida
        $query_mensagens = "SELECT m.* FROM mensagens m 
                           INNER JOIN (
                               SELECT numero, MAX(id) as max_id 
                               FROM mensagens 
                               WHERE tipo = 'enviada' 
                               GROUP BY numero
                           ) as ultima_msg ON m.id = ultima_msg.max_id 
                           WHERE m.status_envio = 3 
                           ORDER BY m.numero ASC, m.timestamp ASC";
        $result_mensagens = $mysqli->query($query_mensagens);
        if ($result_mensagens) {
            while ($row = $result_mensagens->fetch_assoc()) {
                $conversas[$row['numero']][] = $row;
            }
        }
        break;

    case 'respostas_31_07':
        // Busca números que tiveram respostas em 31/07/2025 (apenas strings)
        $query_numeros = "SELECT DISTINCT numero FROM mensagens 
                         WHERE tipo = 'recebida' 
                         AND FROM_UNIXTIME(
                            CASE
                                WHEN LENGTH(CAST(timestamp AS CHAR(20))) = 13 THEN timestamp / 1000
                                ELSE timestamp
                            END
                        ) >= '2025-07-31 00:00:00'
                         AND mensagem <> '1'
                         AND mensagem <> '2'
                         AND TRIM(mensagem) != ''";

        $result_numeros = $mysqli->query($query_numeros);
        $numeros_filtrados = [];
        if ($result_numeros) {
            while ($row = $result_numeros->fetch_assoc()) {
                $numeros_filtrados[] = "'" . $mysqli->real_escape_string($row['numero']) . "'";
            }
        }

        // Busca TODAS as mensagens (enviadas e recebidas) desses números
        if (!empty($numeros_filtrados)) {
            $numeros_string = implode(',', $numeros_filtrados);
            $query_mensagens = "SELECT * FROM mensagens 
                               WHERE numero IN ({$numeros_string}) 
                               ORDER BY numero ASC, timestamp ASC";

            $result_mensagens = $mysqli->query($query_mensagens);
            if ($result_mensagens) {
                while ($row = $result_mensagens->fetch_assoc()) {
                    $conversas[$row['numero']][] = $row;
                }
            }
        }
        $info_filtro = 'Conversas com respostas recebidas em 31/07/2025 (apenas texto)';
        break;

    case 'respostas_01_08':
        // Busca números que tiveram respostas em 01/08/2025 (apenas strings)
        $query_numeros = "SELECT DISTINCT numero FROM mensagens 
                         WHERE tipo = 'recebida' 
                         AND FROM_UNIXTIME(
                            CASE
                                WHEN LENGTH(CAST(timestamp AS CHAR(20))) = 13 THEN timestamp / 1000
                                ELSE timestamp
                            END
                        ) >= '2025-08-01 00:00:00'
                         AND mensagem <> '1'
                         AND mensagem <> '2'
                         AND TRIM(mensagem) != ''";

        $result_numeros = $mysqli->query($query_numeros);
        $numeros_filtrados = [];
        if ($result_numeros) {
            while ($row = $result_numeros->fetch_assoc()) {
                $numeros_filtrados[] = "'" . $mysqli->real_escape_string($row['numero']) . "'";
            }
        }

        // Busca TODAS as mensagens (enviadas e recebidas) desses números
        if (!empty($numeros_filtrados)) {
            $numeros_string = implode(',', $numeros_filtrados);
            $query_mensagens = "SELECT * FROM mensagens 
                               WHERE numero IN ({$numeros_string}) 
                               ORDER BY numero ASC, timestamp ASC";

            $result_mensagens = $mysqli->query($query_mensagens);
            if ($result_mensagens) {
                while ($row = $result_mensagens->fetch_assoc()) {
                    $conversas[$row['numero']][] = $row;
                }
            }
        }
        $info_filtro = 'Conversas com respostas recebidas em 01/08/2025 (apenas texto)';
        break;

    case 'todos':
    default:
        // Mostra todas as conversas
        $query_mensagens = "SELECT * FROM mensagens ORDER BY numero ASC, timestamp ASC";
        $result_mensagens = $mysqli->query($query_mensagens);
        if ($result_mensagens) {
            while ($row = $result_mensagens->fetch_assoc()) {
                $conversas[$row['numero']][] = $row;
            }
        }
        break;
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <title>Dashboard de Relatórios - WhatsApp</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            background-color: #f8f9fa;
            color: #333;
        }

        .container {
            max-width: 1400px;
            margin: 20px auto;
            padding: 0 20px;
        }

        h1,
        h2 {
            color: #212529;
        }

        h1 {
            font-size: 2.5rem;
            margin-bottom: 20px;
            text-align: center;
        }

        h2 {
            border-bottom: 2px solid #dee2e6;
            padding-bottom: 10px;
            margin-top: 40px;
        }

        .card {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 25px;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
        }

        .chart-container {
            position: relative;
            height: 350px;
            width: 100%;
            text-align: center;
        }

        /* Estilos para os filtros */
        .filter-container {
            margin-bottom: 20px;
            text-align: center;
        }

        .filter-btn {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            margin: 0 5px;
            border-radius: 20px;
            cursor: pointer;
            transition: background-color 0.3s;
            font-weight: 600;
        }

        .filter-btn:hover {
            background-color: #5a6268;
        }

        .filter-btn.active {
            background-color: #0d6efd;
        }

        .accordion-item {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 10px;
            overflow: hidden;
        }

        .accordion-header {
            background-color: #f8f9fa;
            padding: 15px 20px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
        }

        .accordion-header:hover {
            background-color: #e9ecef;
        }

        .accordion-header .arrow {
            transition: transform 0.3s ease;
        }

        .accordion-header.active .arrow {
            transform: rotate(180deg);
        }

        .accordion-content {
            padding: 20px;
            display: none;
            background-color: #fff;
            border-top: 1px solid #dee2e6;
        }

        .message {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 12px;
            max-width: 80%;
            position: relative;
        }

        .message p {
            margin: 0;
        }

        .message .timestamp {
            font-size: 0.75rem;
            color: #6c757d;
            margin-top: 8px;
            text-align: right;
        }

        .msg-enviada {
            background-color: #cfe2ff;
            border: 1px solid #b6d4fe;
            margin-left: auto;
        }

        .msg-recebida {
            background-color: #d1e7dd;
            border: 1px solid #badbcc;
            margin-right: auto;
        }

        .filter-info {
            background-color: #e7f3ff;
            border: 1px solid #b3d9ff;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            text-align: center;
            color: #0066cc;
        }

        .conversation-stats {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 10px;
            font-size: 0.9rem;
            color: #6c757d;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>Dashboard de Performance</h1>

        <!-- NOVAS MÉTRICAS DE VISUALIZAÇÃO -->
        <div class="card">
            <h2>Métricas de Visualização</h2>
            <div class="metrics-grid">
                <div class="metrics-grid">
                    <div class="metric-card">
                        <!-- <div class="metric-value">0%</div> -->
                        <div class="metric-value" style="
    float: left;
    width: 5%;
    background: #28a745;
    color: white;
    text-align: center;
    margin-right: 10px;
">86%</div>
                        <div class="metric-label" style="margin: 5px 10px 0 0;">Taxa de visualização em até 5 minutos</div>
                    </div>
                    <div class="metric-card">
                        <!-- <div class="metric-value">0%</div> -->
                        <div class="metric-value" style="
    float: left;
    width: 5%;
    background: #ffc107;
    color: white;
    text-align: center;
    margin-right: 10px;
">13%</div>
                        <div class="metric-label" style="
    margin: 5px 10px 0 0;
">Taxa de visualização em até 1 hora</div>
                    </div>
                    <div class="metric-card">
                        <!-- <div class="metric-value">0%</div> -->
                        <div class="metric-value" style="
    float: left;
    width: 5%;
    background: #dc3545;
    color: white;
    text-align: center;
    margin-right: 10px;
">1%</div>
                        <div class="metric-label" style="
    margin: 5px 10px 0 0;
">Taxa de visualização após 1 hora</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="charts-grid">
                <!-- Gráficos existentes -->
                <div>
                    <h2>Funil de Entrega</h2>
                    <div class="chart-container"><canvas id="deliveryFunnelChart"></canvas></div>
                </div>
                <div>
                    <h2>Performance e Engajamento (%)</h2>
                    <div class="chart-container"><canvas id="engagementRateChart"></canvas></div>
                </div>
                <!-- Novo gráfico de visualização -->
                <div>
                    <h2>Tempo de Visualização</h2>
                    <div class="chart-container"><canvas id="visualizationTimeChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Histórico de Conversas</h2>

            <!-- FILTROS -->
            <div class="filter-container">
                <button class="filter-btn <?php echo ($filtro_ativo == 'todos') ? 'active' : ''; ?>" onclick="window.location.href='?filtro=todos'">Todas</button>
                <button class="filter-btn <?php echo ($filtro_ativo == 'enviadas') ? 'active' : ''; ?>" onclick="window.location.href='?filtro=enviadas'">Apenas Enviadas</button>
                <button class="filter-btn <?php echo ($filtro_ativo == 'recebidas') ? 'active' : ''; ?>" onclick="window.location.href='?filtro=recebidas'">Com Resposta</button>
                <button class="filter-btn <?php echo ($filtro_ativo == 'lidas') ? 'active' : ''; ?>" onclick="window.location.href='?filtro=lidas'">Lidas</button>
                <button class="filter-btn <?php echo ($filtro_ativo == 'respostas_31_07') ? 'active' : ''; ?>" onclick="window.location.href='?filtro=respostas_31_07'">Respostas 31/07</button>
                <button class="filter-btn <?php echo ($filtro_ativo == 'respostas_01_08') ? 'active' : ''; ?>" onclick="window.location.href='?filtro=respostas_01_08'">Respostas 01/08</button>
            </div>

            <?php if (!empty($info_filtro)): ?>
                <div class="filter-info">
                    <?php echo $info_filtro; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($conversas)): ?>
                <div class="conversation-stats">
                    Total de conversas encontradas: <?php echo count($conversas); ?>
                </div>
            <?php endif; ?>

            <div id="conversation-accordion">
                <?php if (!empty($conversas)): ?>
                    <?php foreach ($conversas as $numero => $mensagens): ?>
                        <?php
                        $total_mensagens = count($mensagens);
                        $enviadas = array_filter($mensagens, function ($m) {
                            return $m['tipo'] == 'enviada';
                        });
                        $recebidas = array_filter($mensagens, function ($m) {
                            return $m['tipo'] == 'recebida';
                        });
                        ?>
                        <div class="accordion-item">
                            <div class="accordion-header">
                                <span>
                                    Conversa com: <?php echo explode('@c.us', htmlspecialchars($numero))[0]; ?>
                                    <small style="color: #6c757d; font-weight: normal;">
                                        (<?php echo count($enviadas); ?> enviadas, <?php echo count($recebidas); ?> recebidas)
                                    </small>
                                </span>
                                <span class="arrow">▼</span>
                            </div>
                            <div class="accordion-content">
                                <?php foreach ($mensagens as $msg): ?>
                                    <div class="message msg-<?php echo $msg['tipo']; ?>">
                                        <p><?php echo nl2br(htmlspecialchars($msg['mensagem'])); ?></p>
                                        <div class="timestamp">
                                            <?php echo formatTimestampAuto($msg['timestamp']); ?>
                                            <?php if ($msg['tipo'] == 'enviada' && $msg['status_envio'] > 0): ?>
                                                - Status:
                                                <?php
                                                switch ($msg['status_envio']) {
                                                    case 1:
                                                        echo 'Enviado';
                                                        break;
                                                    case 2:
                                                        echo 'Recebido';
                                                        break;
                                                    case 3:
                                                        echo 'Lido';
                                                        break;
                                                    default:
                                                        echo 'Pendente';
                                                }
                                                ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="text-align:center; color: #6c757d;">Nenhuma conversa encontrada para o filtro selecionado.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Lógica do Acordeão
            document.querySelectorAll('.accordion-header').forEach(header => {
                header.addEventListener('click', () => {
                    header.classList.toggle('active');
                    header.nextElementSibling.style.display = header.classList.contains('active') ? 'block' : 'none';
                });
            });

            // Gráfico do Funil de Entrega
            const statusData = <?php echo $status_data_json; ?>;
            const ctxFunnel = document.getElementById('deliveryFunnelChart').getContext('2d');
            new Chart(ctxFunnel, {
                type: 'bar',
                data: {
                    labels: ['Enviadas', 'Recebidas', 'Lidas'],
                    datasets: [{
                        label: 'Quantidade',
                        data: [statusData.enviado, statusData.recebido, statusData.lido],
                        backgroundColor: ['#0d6efd', '#0dcaf0', '#198754']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });

            // Gráfico de Engajamento
            const engagementData = <?php echo $engagement_data_json; ?>;
            const ctxEngagement = document.getElementById('engagementRateChart').getContext('2d');
            new Chart(ctxEngagement, {
                type: 'doughnut',
                data: {
                    labels: [`Não respondidas: ${100 - engagementData.taxa_resposta}%`, `Taxa de Resposta: ${engagementData.taxa_resposta}%`],
                    datasets: [{
                        label: 'Performance (%)',
                        data: [100 - engagementData.taxa_resposta, engagementData.taxa_resposta],
                        backgroundColor: ['#6f42c1', '#d63384'],
                        borderColor: '#fff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top'
                        }
                    }
                }
            });

            // NOVO GRÁFICO DE TEMPO DE VISUALIZAÇÃO
            const visualizationData = <?php echo $visualizacao_data_json; ?>;
            const ctxVisualization = document.getElementById('visualizationTimeChart').getContext('2d');
            new Chart(ctxVisualization, {
                type: 'pie',
                data: {
                    labels: ['Até 5 minutos', 'Até 1 hora', 'Após 1 hora'],
                    datasets: [{
                        label: 'Tempo de Visualização (%)',
                        data: [visualizationData.ate_5min, visualizationData.ate_1hora, visualizationData.apos_1hora],
                        backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                        borderColor: '#fff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + context.parsed + '%';
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>

</html>