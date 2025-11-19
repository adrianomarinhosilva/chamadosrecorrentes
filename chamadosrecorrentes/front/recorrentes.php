<?php

include ("../../../inc/includes.php");

Session::checkLoginUser();

$current_user_id = Session::getLoginUserID();
$current_entity = $_SESSION['glpiactive_entity'];

// Processar formulário quando enviado
if (isset($_POST['criar_ticket_recorrente'])) {
    try {
        global $DB;
        
        // Validações básicas
        if (empty($_POST['titulo']) || empty($_POST['descricao']) || empty($_POST['users_id_recipient'])) {
            Session::addMessageAfterRedirect(__('Campos obrigatórios não preenchidos'), false, ERROR);
            Html::redirect($_SERVER['PHP_SELF']);
        }
        
        if (empty($_POST['itilcategories_id'])) {
            Session::addMessageAfterRedirect(__('Categoria é obrigatória'), false, ERROR);
            Html::redirect($_SERVER['PHP_SELF']);
        }
        
        if (empty($_POST['recorrencia_tipo'])) {
            Session::addMessageAfterRedirect(__('Tipo de recorrência é obrigatório'), false, ERROR);
            Html::redirect($_SERVER['PHP_SELF']);
        }
        
        // Dados básicos
        $data_inicio = !empty($_POST['data_inicio']) ? $_POST['data_inicio'] : date('Y-m-d H:i:s');
        $entities_id = !empty($_POST['entities_id']) ? (int)$_POST['entities_id'] : $current_entity;
        $users_id_recipient = (int)$_POST['users_id_recipient'];
        $itilcategories_id = (int)$_POST['itilcategories_id'];
        $titulo = $_POST['titulo'];
        $descricao = $_POST['descricao'];
        $recorrencia_tipo = $_POST['recorrencia_tipo'];
        $intervalo = (int)$_POST['intervalo'];
        $ativo = isset($_POST['ativo']) ? 1 : 0;
        
        // Novos campos condicionais
        $adicionar_comentario = isset($_POST['adicionar_comentario']) ? true : false;
        $solucionar_ticket = isset($_POST['solucionar_ticket']) ? true : false;
        $followup_content = ($adicionar_comentario && !empty($_POST['followup_content'])) ? $_POST['followup_content'] : '';
        $solucao_content = ($solucionar_ticket && !empty($_POST['solucao_content'])) ? $_POST['solucao_content'] : '';
        
        // Converter formato de data se necessário
        if (!empty($data_inicio) && strpos($data_inicio, 'T') !== false) {
            $data_inicio = str_replace('T', ' ', $data_inicio) . ':00';
        }
        
        // Criar registro de recorrência
$recorrencia_data = [
    'entities_id' => $entities_id,
    'name' => $titulo,
    'content' => $descricao,
    'users_id_recipient' => $users_id_recipient,
    'itilcategories_id' => $itilcategories_id,
    'followup_content' => $followup_content,
    'solution_content' => $solucao_content,
    'recorrencia_tipo' => $recorrencia_tipo,
    'intervalo' => $intervalo,
    'data_inicio' => $data_inicio,
    'proximo_agendamento' => $data_inicio,
    'created_by' => $current_user_id,
    'ativo' => $ativo,
    'date_creation' => date('Y-m-d H:i:s'),
];
        
        $result = $DB->insert('glpi_plugin_chamadosrecorrentes_recorrencias', $recorrencia_data);
        
        if ($result) {
            Session::addMessageAfterRedirect(__('Ticket recorrente criado com sucesso! ID: ') . $result, false, INFO);
        } else {
            Session::addMessageAfterRedirect(__('Erro ao criar ticket recorrente'), false, ERROR);
        }
        
    } catch (Exception $e) {
        error_log("PLUGIN CHAMADOS RECORRENTES: Exception: " . $e->getMessage());
        Session::addMessageAfterRedirect(__('Erro: ') . $e->getMessage(), false, ERROR);
    }
    
    Html::redirect($_SERVER['PHP_SELF']);
}

// Processar ativação/desativação de recorrências
if (isset($_GET['action'])) {
    if ($_GET['action'] == 'toggle_recorrencia' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        try {
            // Buscar status atual
            $current = $DB->request([
                'FROM' => 'glpi_plugin_chamadosrecorrentes_recorrencias',
                'WHERE' => ['id' => $id],
                'LIMIT' => 1
            ]);
            
            foreach ($current as $rec) {
                $novo_status = $rec['ativo'] ? 0 : 1;
                $result = $DB->update(
                    'glpi_plugin_chamadosrecorrentes_recorrencias',
                    ['ativo' => $novo_status, 'date_mod' => date('Y-m-d H:i:s')],
                    ['id' => $id]
                );
                
                if ($result) {
                    $status_texto = $novo_status ? 'ativada' : 'desativada';
                    Session::addMessageAfterRedirect(__('Recorrência ') . $status_texto . __(' com sucesso'), false, INFO);
                } else {
                    Session::addMessageAfterRedirect(__('Erro ao alterar status da recorrência'), false, ERROR);
                }
                break;
            }
        } catch (Exception $e) {
            Session::addMessageAfterRedirect(__('Erro: ') . $e->getMessage(), false, ERROR);
        }
        Html::redirect($_SERVER['PHP_SELF']);
    }
    
    if ($_GET['action'] == 'delete_recorrencia' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        try {
            $result = $DB->delete('glpi_plugin_chamadosrecorrentes_recorrencias', ['id' => $id]);
            if ($result) {
                Session::addMessageAfterRedirect(__('Recorrência deletada com sucesso'), false, INFO);
            } else {
                Session::addMessageAfterRedirect(__('Erro ao deletar recorrência'), false, ERROR);
            }
        } catch (Exception $e) {
            Session::addMessageAfterRedirect(__('Erro: ') . $e->getMessage(), false, ERROR);
        }
        Html::redirect($_SERVER['PHP_SELF']);
    }
}

// Cabeçalho da página
Html::header("Chamados Recorrentes", $_SERVER['PHP_SELF'], "tools", "pluginchamadosrecorrentesmenu");

// Criar abas
$tabs = [
    1 => [
        'title' => 'Abertura de Tickets (Passado e Futuro)',
        'url' => $CFG_GLPI['root_doc'] . '/plugins/chamadosrecorrentes/front/page.php',
        'active' => false
    ],
    2 => [
        'title' => 'Agendamento Continuo de Tickets',
        'url' => $CFG_GLPI['root_doc'] . '/plugins/chamadosrecorrentes/front/recorrentes.php',
        'active' => true
    ]
];

// Exibir abas
echo "<div class='chamadosrecorrentes-tabs' style='margin-bottom: 20px; border-bottom: 2px solid #ddd;'>";
echo "<style>
.chamadosrecorrentes-tabs { overflow: hidden; }
.chamadosrecorrentes-tab { 
    float: left; 
    display: block; 
    color: #666; 
    text-decoration: none; 
    padding: 12px 20px; 
    border-bottom: 3px solid transparent; 
    margin-right: 5px;
    transition: all 0.3s ease;
}
.chamadosrecorrentes-tab:hover { 
    color: #666; 
    background-color: rgba(128, 128, 128, 0.1); 
    text-decoration: none;
}
.chamadosrecorrentes-tab.active { 
    color: #666; 
    border-bottom-color: rgba(128, 128, 128, 0.3); 
    font-weight: bold; 
    background-color: rgba(128, 128, 128, 0.1);
}
.chamadosrecorrentes-tabs::after { content: ''; display: table; clear: both; }

/* CSS Responsivo */
@media (max-width: 1200px) {
    .chamadosrecorrentes-form-section { width: 100%; float: none; margin-right: 0; margin-bottom: 20px; }
    .chamadosrecorrentes-status-section { width: 100%; float: none; }
}

@media (max-width: 768px) {
    .chamadosrecorrentes-tab { 
        float: none; 
        display: block; 
        text-align: center; 
        margin-right: 0; 
        border-bottom: 1px solid #ddd; 
    }
    .chamadosrecorrentes-tabs { border-bottom: none; }
    .chamadosrecorrentes-container { padding: 10px; }
    .logs-table th, .logs-table td { font-size: 10px; padding: 4px; }
    .recorrencia-item { font-size: 11px; }
}

@media (max-width: 480px) {
    .chamadosrecorrentes-form-section table tr td { display: block; width: 100% !important; }
    .chamadosrecorrentes-form-section table tr td:first-child { font-weight: bold; margin-bottom: 5px; }
}
</style>";

foreach ($tabs as $tab) {
    $class = $tab['active'] ? 'chamadosrecorrentes-tab active' : 'chamadosrecorrentes-tab';
    echo "<a href='" . $tab['url'] . "' class='" . $class . "'>" . $tab['title'] . "</a>";
}

echo "</div>";

echo "<div class='chamadosrecorrentes-container' style='padding: 20px; margin-left: 0;'>";
echo "<style>
.chamadosrecorrentes-container * { box-sizing: border-box; }
.chamadosrecorrentes-form-section { width: 48%; float: left; margin-right: 2%; margin-bottom: 30px; }
.chamadosrecorrentes-status-section { width: 48%; float: right; margin-bottom: 30px; padding: 15px; border: 1px solid #ddd; background-color: #f9f9f9; }
.chamadosrecorrentes-logs-section { width: 100%; clear: both; }
.logs-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
.logs-table th, .logs-table td { padding: 8px; border: 1px solid #ddd; text-align: left; font-size: 12px; }
.logs-table th { background-color: #f5f5f5; font-weight: bold; }
.logs-table tr:nth-child(even) { background-color: #f9f9f9; }
.logs-table tr:hover { background-color: #f0f0f0; }
.logs-scroll { max-height: 400px; overflow-y: auto; border: 1px solid #ddd; }
.status-active { color: #28a745; font-weight: bold; }
.status-inactive { color: #dc3545; font-weight: bold; }
.recorrencia-item { 
    margin: 8px 0; 
    background-color: #e7f3ff; 
    border-left: 4px solid #007bff; 
    font-size: 12px; 
    border-radius: 4px; 
    padding: 12px;
}
.recorrencia-ativa { background-color: #d4edda; border-left-color: #28a745; }
.recorrencia-inativa { background-color: #f8d7da; border-left-color: #dc3545; }
.conditional-field {
    display: none;
    margin-top: 10px;
}
.checkbox-container {
    margin: 10px 0;
    padding: 10px;
    background-color: #f8f9fa;
    border-radius: 4px;
    border: 1px solid #dee2e6;
}
</style>";

// Seção do formulário (alinhada à esquerda)
echo "<div class='chamadosrecorrentes-form-section'>";

// Formulário
echo "<form method='post' action='" . $_SERVER['PHP_SELF'] . "'>";

// Token CSRF
Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

// Tabela principal do formulário
echo "<table class='tab_cadre_fixe' style='width: 100%;'>";

// PRIMEIRA LINHA - Data de início e Tipo de recorrência
echo "<tr class='tab_bg_1'>";
echo "<td width='25%'><label for='data_inicio'>Data/Hora de Início:</label></td>";
echo "<td width='25%'>";
Html::showDateTimeField('data_inicio', ['value' => '', 'maybeempty' => true]);
echo "</td>";
echo "<td width='25%'><label for='recorrencia_tipo'>Tipo de Recorrência: <span class='red'>*</span></label></td>";
echo "<td width='25%'>";
echo "<select name='recorrencia_tipo' id='recorrencia_tipo' required class='form-control'>";
echo "<option value=''>Selecione...</option>";
echo "<option value='hourly'>A cada hora</option>";
echo "<option value='minutes'>A cada X minutos</option>";
echo "<option value='daily'>Diário</option>";
echo "<option value='weekly'>Semanal</option>";
echo "<option value='monthly'>Mensal</option>";
echo "<option value='yearly'>Anual</option>";
echo "<option value='custom'>Personalizado (dias)</option>";
echo "</select>";
echo "</td>";
echo "</tr>";

// SEGUNDA LINHA - Entidade, Categoria e Intervalo
echo "<tr class='tab_bg_1'>";
echo "<td><label for='entities_id'>Entidade:</label></td>";
echo "<td>";
Entity::dropdown(['value' => $current_entity, 'name' => 'entities_id']);
echo "</td>";
echo "<td><label for='intervalo'>Intervalo:</label></td>";
echo "<td>";
echo "<input type='number' name='intervalo' id='intervalo' value='1' min='1' max='1440' class='form-control' style='width: 100%;'>";
echo "<small style='color: #666;'>Ex: a cada 2 dias, 30 minutos, 3 horas, etc.</small>";
echo "</td>";
echo "</tr>";

// TERCEIRA LINHA - Categoria
echo "<tr class='tab_bg_1'>";
echo "<td><label for='itilcategories_id'>Categoria: <span class='red'>*</span></label></td>";
echo "<td colspan='3'>";
ITILCategory::dropdown([
    'name' => 'itilcategories_id',
    'value' => 0,
    'entity' => $current_entity,
    'condition' => ['is_helpdeskvisible' => 1]
]);
echo "</td>";
echo "</tr>";

// Título do chamado (linha separada para aproveitar largura completa)
echo "<tr class='tab_bg_1'>";
echo "<td><label for='titulo'>Título do Chamado: <span class='red'>*</span></label></td>";
echo "<td colspan='3'>";
echo "<input type='text' name='titulo' id='titulo' required size='100' class='form-control' style='width: 100%;'>";
echo "</td>";
echo "</tr>";

// Descrição (largura completa)
echo "<tr class='tab_bg_1'>";
echo "<td><label for='descricao'>Descrição: <span class='red'>*</span></label></td>";
echo "<td colspan='3'>";
echo "<textarea name='descricao' id='descricao' required rows='4' class='form-control' style='width: 100%;'></textarea>";
echo "</td>";
echo "</tr>";

// Usuário requerente
echo "<tr class='tab_bg_1'>";
echo "<td><label for='users_id_recipient'>Usuário Requerente: <span class='red'>*</span></label></td>";
echo "<td colspan='3'>";
echo "<select name='users_id_recipient' id='users_id_recipient' required class='form-control'>";
echo "<option value=''>Selecione um usuário</option>";

// Buscar usuários
$iterator = $DB->request([
    'SELECT' => ['id', 'firstname', 'realname', 'name'],
    'FROM' => 'glpi_users',
    'WHERE' => [
        'is_deleted' => 0,
        'is_active' => 1
    ],
    'ORDER' => ['realname', 'firstname']
]);

foreach ($iterator as $user) {
    $username = trim($user['firstname'] . ' ' . $user['realname']);
    if (empty($username)) {
        $username = $user['name'] ?: "Usuário ID: " . $user['id'];
    }
    echo "<option value='" . $user['id'] . "'>" . htmlspecialchars($username) . "</option>";
}
echo "</select>";
echo "</td>";
echo "</tr>";

// CHECKBOXES CONDICIONAIS
echo "<tr class='tab_bg_1'>";
echo "<td colspan='4'>";

echo "<div class='checkbox-container'>";
echo "<div style='margin-bottom: 10px;'>";
echo "<label><input type='checkbox' id='adicionar_comentario_rec' name='adicionar_comentario' onchange='toggleComentarioFieldRec()'> Adicionar comentário ao chamado?</label>";
echo "</div>";

echo "<div style='margin-bottom: 10px;'>";
echo "<label><input type='checkbox' id='solucionar_ticket_rec' name='solucionar_ticket' onchange='toggleSolucaoFieldRec()'> Deseja solucionar o ticket?</label>";
echo "</div>";
echo "</div>";

echo "</td>";
echo "</tr>";

// Conteúdo do acompanhamento (condicional)
echo "<tr class='tab_bg_1' id='followup_row_rec' style='display: none;'>";
echo "<td><label for='followup_content_rec'>Conteúdo do Acompanhamento:</label></td>";
echo "<td colspan='3'>";
echo "<textarea name='followup_content' id='followup_content_rec' rows='3' class='form-control' style='width: 100%;'></textarea>";
echo "</td>";
echo "</tr>";

// Conteúdo da solução (condicional)
echo "<tr class='tab_bg_1' id='solucao_row_rec' style='display: none;'>";
echo "<td><label for='solucao_content_rec'>Conteúdo da Solução:</label></td>";
echo "<td colspan='3'>";
echo "<textarea name='solucao_content' id='solucao_content_rec' rows='4' class='form-control' style='width: 100%;'></textarea>";
echo "</td>";
echo "</tr>";

// Status ativo
echo "<tr class='tab_bg_1'>";
echo "<td><label for='ativo'>Ativar Recorrência:</label></td>";
echo "<td colspan='3'>";
echo "<input type='checkbox' name='ativo' id='ativo' checked> Sim, ativar imediatamente";
echo "</td>";
echo "</tr>";

echo "</table>";

// Botão de submissão
echo "<div class='center' style='margin-top: 20px;'>";
echo "<input type='submit' name='criar_ticket_recorrente' value='Criar Recorrência' class='btn btn-primary'>";
echo "</div>";

Html::closeForm();
echo "</div>"; // Fim da seção do formulário

// Seção de status das recorrências (lado direito)
echo "<div class='chamadosrecorrentes-status-section'>";
echo "<h4 style='color: #333; margin-bottom: 10px;'>Recorrências Ativas</h4>";

try {
    $recorrencias = $DB->request([
        'FROM' => 'glpi_plugin_chamadosrecorrentes_recorrencias',
        'ORDER' => ['date_creation DESC'],
        'LIMIT' => 10
    ]);

    if (count($recorrencias) > 0) {
        echo "<div style='max-height: 400px; overflow-y: auto;'>";
        foreach ($recorrencias as $rec) {
            $class_status = $rec['ativo'] ? 'recorrencia-ativa' : 'recorrencia-inativa';
            $status_texto = $rec['ativo'] ? '✅ Ativa' : '❌ Inativa';
            
            echo "<div class='recorrencia-item " . $class_status . "'>";
            echo "<strong>" . htmlspecialchars($rec['name']) . "</strong><br>";
            echo "<small>Tipo: " . ucfirst($rec['recorrencia_tipo']) . " (a cada " . $rec['intervalo'] . ")</small><br>";
            echo "<small>Próximo: " . Html::convDateTime($rec['proximo_agendamento']) . "</small><br>";
            echo "<small>Status: " . $status_texto . "</small><br>";
            
            // Mostrar se tem comentário e/ou solução configurados
            $recursos = [];
            if (isset($rec['adicionar_comentario']) && $rec['adicionar_comentario']) {
                $recursos[] = "📝 Comentário";
            }
            if (isset($rec['solucionar_ticket']) && $rec['solucionar_ticket']) {
                $recursos[] = "✅ Solução";
            }
            if (!empty($recursos)) {
                echo "<small>Recursos: " . implode(", ", $recursos) . "</small><br>";
            }
            
            echo "<div style='margin-top: 8px;'>";
            $toggle_url = $_SERVER['PHP_SELF'] . '?action=toggle_recorrencia&id=' . $rec['id'];
            $delete_url = $_SERVER['PHP_SELF'] . '?action=delete_recorrencia&id=' . $rec['id'];
            
            $toggle_text = $rec['ativo'] ? 'Desativar' : 'Ativar';
            echo "<a href='" . $toggle_url . "' style='background-color: #ffc107; color: white; padding: 3px 6px; border-radius: 3px; font-size: 10px; text-decoration: none; margin-right: 5px;'>" . $toggle_text . "</a>";
            echo "<a href='" . $delete_url . "' onclick='return confirm(\"Tem certeza?\");' style='background-color: #dc3545; color: white; padding: 3px 6px; border-radius: 3px; font-size: 10px; text-decoration: none;'>Deletar</a>";
            echo "</div>";
            
            echo "</div>";
        }
        echo "</div>";
    } else {
        echo "<p style='color: #666; font-style: italic;'>Nenhuma recorrência criada ainda.</p>";
    }
        
} catch (Exception $e) {
    echo "<p style='color: #dc3545;'>Erro ao carregar recorrências: " . $e->getMessage() . "</p>";
}

echo "</div>"; // Fim da seção de status

echo "</div>"; // Fim do container principal

echo "<script>
function toggleComentarioFieldRec() {
    var checkbox = document.getElementById('adicionar_comentario_rec');
    var row = document.getElementById('followup_row_rec');
    var textarea = document.getElementById('followup_content_rec');
    
    if (checkbox.checked) {
        row.style.display = 'table-row';
        textarea.setAttribute('required', 'required');
    } else {
        row.style.display = 'none';
        textarea.removeAttribute('required');
        textarea.value = '';
    }
}

function toggleSolucaoFieldRec() {
    var checkbox = document.getElementById('solucionar_ticket_rec');
    var row = document.getElementById('solucao_row_rec');
    var textarea = document.getElementById('solucao_content_rec');
    
    if (checkbox.checked) {
        row.style.display = 'table-row';
        textarea.setAttribute('required', 'required');
    } else {
        row.style.display = 'none';
        textarea.removeAttribute('required');
        textarea.value = '';
    }
}
</script>";

Html::footer();
?>