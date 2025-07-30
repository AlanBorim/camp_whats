<?php
require_once 'config.php';

// --- BUSCA DE DADOS PARA OS GRÁFICOS E STATS ---

// 1. Estatísticas Gerais
$total_enviadas = $mysqli->query("SELECT COUNT(id) as total FROM mensagens WHERE tipo = 'enviada'")->fetch_assoc()['total'];
$total_respostas = $mysqli->query("SELECT COUNT(id) as total FROM respostas")->fetch_assoc()['total'];

// 2. Dados para o Gráfico de Status
$status_counts = $mysqli->query("
    SELECT 
        SUM(CASE WHEN status_envio >= 1 THEN 1 ELSE 0 END) as enviado,
        SUM(CASE WHEN status_envio >= 2 THEN 1 ELSE 0 END) as recebido,
        SUM(CASE WHEN status_envio = 3 THEN 1 ELSE 0 END) as lido
    FROM mensagens WHERE tipo = 'enviada'
")->fetch_assoc();

// 3. Cálculo da Taxa de Recepção em 5 minutos
$recebidas_5min_query = $mysqli->query("
    SELECT COUNT(id) as total FROM mensagens 
    WHERE tipo = 'enviada' 
    AND data_status_recebido IS NOT NULL
    AND TIMESTAMPDIFF(SECOND, data_criacao, data_status_recebido) <= 300
");
$recebidas_em_5_min = $recebidas_5min_query->fetch_assoc()['total'];
$taxa_recepcao_5min = ($total_enviadas > 0) ? round(($recebidas_em_5_min / $total_enviadas) * 100, 2) : 0;

// 4. Preparação dos dados para os gráficos em JSON
$status_data_json = json_encode([
    'lido' => (int)($status_counts['lido'] ?? 0),
    'recebido' => (int)($status_counts['recebido'] ?? 0),
    'enviado' => (int)($status_counts['enviado'] ?? 0),
]);

$taxa_resposta = ($total_enviadas > 0) ? round(($total_respostas / $total_enviadas) * 100, 2) : 0;
$engagement_data_json = json_encode([
    'taxa_resposta' => $taxa_resposta,
    'taxa_nao_resposta' => 100 - $taxa_resposta,
    'taxa_recepcao_5min' => $taxa_recepcao_5min,
]);

// 5. Obter dados para o histórico de conversas
$conversas = [];
// AJUSTE: Ordenando primeiro pelo número do contato e depois pelo ID da mensagem para garantir a ordem de inserção.
$result = $mysqli->query("SELECT * FROM mensagens ORDER BY numero ASC, id ASC");
while ($row = $result->fetch_assoc()) {
    $conversas[$row['numero']][] = $row;
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Dashboard de Relatórios - WhatsApp</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* (CSS inalterado da versão anterior - omitido por brevidade, mas deve ser incluído aqui ) */
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; margin: 0; background-color: #f8f9fa; color: #333; }
        .container { max-width: 1400px; margin: 20px auto; padding: 0 20px; }
        h1, h2 { color: #212529; }
        h1 { font-size: 2.5rem; margin-bottom: 20px; text-align: center; }
        h2 { border-bottom: 2px solid #dee2e6; padding-bottom: 10px; margin-top: 40px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 25px; }
        .charts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 25px; }
        .chart-container { position: relative; height: 350px; width: 100%; text-align: center; }
        .accordion-item { border: 1px solid #dee2e6; border-radius: 8px; margin-bottom: 10px; overflow: hidden; }
        .accordion-header { background-color: #f8f9fa; padding: 15px 20px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; font-weight: 600; }
        .accordion-header:hover { background-color: #e9ecef; }
        .accordion-header .arrow { transition: transform 0.3s ease; }
        .accordion-header.active .arrow { transform: rotate(180deg); }
        .accordion-content { padding: 20px; display: none; background-color: #fff; border-top: 1px solid #dee2e6; }
        .message { padding: 12px; border-radius: 8px; margin-bottom: 12px; max-width: 80%; position: relative; }
        .message p { margin: 0; }
        .message .timestamp { font-size: 0.75rem; color: #6c757d; margin-top: 8px; text-align: right; }
        .msg-enviada { background-color: #cfe2ff; border: 1px solid #b6d4fe; margin-left: auto; }
        .msg-recebida { background-color: #d1e7dd; border: 1px solid #badbcc; margin-right: auto; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Dashboard de Performance</h1>

        <div class="card">
            <div class="charts-grid">
                <div>
                    <h2>Funil de Entrega</h2>
                    <div class="chart-container">
                        <canvas id="deliveryFunnelChart"></canvas>
                    </div>
                </div>
                <div>
                    <h2>Performance e Engajamento (%)</h2>
                    <div class="chart-container">
                        <canvas id="engagementRateChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Histórico de Conversas</h2>
            <div id="conversation-accordion">
                <?php foreach ($conversas as $numero => $mensagens): ?>
                    <div class="accordion-item">
                        <div class="accordion-header">
                            <span>Conversa com: <?php echo explode('@c.us',htmlspecialchars($numero))[0]; ?></span>
                            <span class="arrow">▼</span>
                        </div>
                        <div class="accordion-content">
                            <?php foreach ($mensagens as $msg): ?>
                                <div class="message msg-<?php echo $msg['tipo']; ?>">
                                    <p><?php echo $msg['id'] . '-' . nl2br(htmlspecialchars($msg['mensagem'])); ?></p>
                                    <div class="timestamp">
                                        <!-- Agora usamos a data de criação correta -->
                                        <?php echo date('d/m/Y H:i:s', $msg['timestamp']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        // Lógica do Acordeão (inalterada)
        document.querySelectorAll('.accordion-header').forEach(header => {
            header.addEventListener('click', () => {
                const content = header.nextElementSibling;
                header.classList.toggle('active');
                content.style.display = content.style.display === 'block' ? 'none' : 'block';
            });
        });

        // --- GRÁFICOS ATUALIZADOS ---

        // 1. Funil de Entrega (Barras)
        const statusData = <?php echo $status_data_json; ?>;
        const ctxFunnel = document.getElementById('deliveryFunnelChart').getContext('2d');
        new Chart(ctxFunnel, {
            type: 'bar',
            data: {
                labels: ['Enviadas', 'Recebidas', 'Lidas'],
                datasets: [{
                    label: 'Quantidade',
                    data: [statusData.enviado, statusData.recebido, statusData.lido],
                    backgroundColor: ['#0d6efd', '#0dcaf0', '#198754'],
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, indexAxis: 'y',
                plugins: { legend: { display: false } }
            }
        });

        // 2. Taxas de Performance (Pizza/Doughnut)
        const engagementData = <?php echo $engagement_data_json; ?>;
        const ctxEngagement = document.getElementById('engagementRateChart').getContext('2d');
        new Chart(ctxEngagement, {
            type: 'doughnut',
            data: {
                labels: [`Taxa de Recepção (5min): ${engagementData.taxa_recepcao_5min}%`, `Taxa de Resposta: ${engagementData.taxa_resposta}%`],
                datasets: [{
                    label: 'Performance (%)',
                    data: [engagementData.taxa_recepcao_5min, engagementData.taxa_resposta],
                    backgroundColor: ['#6f42c1', '#d63384'],
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } }
            }
        });
    });
    </script>
</body>
</html>
