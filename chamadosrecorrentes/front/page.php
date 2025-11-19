<?php

include ("../../../inc/includes.php");

Session::checkLoginUser();

$current_user_id = Session::getLoginUserID();
$current_entity = $_SESSION['glpiactive_entity'];

// ========================================
// PROCESSAMENTO DO FORMULÁRIO
// ========================================
if (isset($_POST['criar_ticket_retroativo'])) {
    try {
        global $DB;
        
        // Validações básicas
        if (empty($_POST['titulo']) || empty($_POST['descricao']) || empty($_POST['users_id_recipient'])) {
            Session::addMessageAfterRedirect(__('Campos obrigatórios não preenchidos'), false, ERROR);
            Html::redirect($_SERVER['PHP_SELF']);
        }
        
        // Validar categoria se selecionada
        if (empty($_POST['itilcategories_id'])) {
            Session::addMessageAfterRedirect(__('Categoria é obrigatória antes que o chamado seja solucionado/fechado'), false, ERROR);
            Html::redirect($_SERVER['PHP_SELF']);
        }
        
        // Dados básicos
        $data_abertura = !empty($_POST['data_abertura']) ? $_POST['data_abertura'] : date('Y-m-d H:i:s');
        $data_solucao = !empty($_POST['data_solucao']) ? $_POST['data_solucao'] : null;
        $entities_id = !empty($_POST['entities_id']) ? (int)$_POST['entities_id'] : $current_entity;
        $users_id_recipient = (int)$_POST['users_id_recipient'];
        $itilcategories_id = (int)$_POST['itilcategories_id'];
        $titulo = $_POST['titulo'];
        $descricao = $_POST['descricao'];
        
        // Novos campos condicionais
        $adicionar_comentario = isset($_POST['adicionar_comentario']) ? true : false;
        $solucionar_ticket = isset($_POST['solucionar_ticket']) ? true : false;
        $followup_content = ($adicionar_comentario && !empty($_POST['followup_content'])) ? $_POST['followup_content'] : '';
        $solucao_content = ($solucionar_ticket && !empty($_POST['solucao_content'])) ? $_POST['solucao_content'] : '';
        
        // Converter formato de data se necessário
        if (!empty($data_abertura) && strpos($data_abertura, 'T') !== false) {
            $data_abertura = str_replace('T', ' ', $data_abertura) . ':00';
        }
        if (!empty($data_solucao) && strpos($data_solucao, 'T') !== false) {
            $data_solucao = str_replace('T', ' ', $data_solucao) . ':00';
        }
        
        // Verificar se é ticket futuro
        $data_abertura_timestamp = strtotime($data_abertura);
        $agora_timestamp = time();
        
        if ($data_abertura_timestamp > $agora_timestamp) {
            // ========================================
            // TICKET FUTURO - AGENDAR VIA MYSQL EVENT
            // ========================================
            $scheduled_data = [
                'scheduled_date' => $data_abertura,
                'solvedate' => ($solucionar_ticket && $data_solucao) ? $data_solucao : null,
                'entities_id' => $entities_id,
                'name' => $titulo,
                'content' => $descricao,
                'users_id_recipient' => $users_id_recipient,
                'itilcategories_id' => $itilcategories_id,
                'followup_content' => $followup_content,
                'solution_content' => $solucao_content,
                'created_by' => $current_user_id,
                'status' => 0,
                'date_creation' => date('Y-m-d H:i:s')
            ];

            // Verificar se as colunas existem antes de inserir
            try {
                $columns_check = $DB->query("SHOW COLUMNS FROM `glpi_plugin_chamadosrecorrentes_scheduled` LIKE 'adicionar_comentario'");
                if ($columns_check && $columns_check->num_rows > 0) {
                    $scheduled_data['adicionar_comentario'] = $adicionar_comentario ? 1 : 0;
                    $scheduled_data['solucionar_ticket'] = $solucionar_ticket ? 1 : 0;
                }
            } catch (Exception $e) {
                // Colunas não existem, continuar sem elas
            }
            
            // Log dos dados para debug
            error_log("PLUGIN CHAMADOS RECORRENTES: Tentando agendar ticket com dados: " . print_r($scheduled_data, true));
            
            try {
                // Verificar se a tabela existe
                $table_check = $DB->query("SHOW TABLES LIKE 'glpi_plugin_chamadosrecorrentes_scheduled'");
                if ($table_check->num_rows == 0) {
                    error_log("PLUGIN CHAMADOS RECORRENTES: Tabela glpi_plugin_chamadosrecorrentes_scheduled não existe");
                    Session::addMessageAfterRedirect(__('Erro: Tabela de agendamento não encontrada. Execute a instalação do plugin.'), false, ERROR);
                    Html::redirect($_SERVER['PHP_SELF']);
                }
                
                $insert_result = $DB->insert('glpi_plugin_chamadosrecorrentes_scheduled', $scheduled_data);
                
                if ($insert_result) {
                    $tempo_restante = $data_abertura_timestamp - $agora_timestamp;
                    $horas = floor($tempo_restante / 3600);
                    $minutos = floor(($tempo_restante % 3600) / 60);
                    
                    error_log("PLUGIN CHAMADOS RECORRENTES: Ticket agendado com sucesso. ID: " . $insert_result);
                    
                    Session::addMessageAfterRedirect(
                        __('Ticket agendado com sucesso! Será executado em ') . $horas . 'h' . $minutos . 'min', 
                        false, 
                        INFO
                    );
                } else {
                    $db_error = $DB->error();
                    error_log("PLUGIN CHAMADOS RECORRENTES: Falha ao inserir. Erro do DB: " . $db_error);
                    Session::addMessageAfterRedirect(__('Erro ao agendar ticket: ') . $db_error, false, ERROR);
                }
                
            } catch (Exception $e) {
                error_log("PLUGIN CHAMADOS RECORRENTES: Exception ao agendar: " . $e->getMessage());
                Session::addMessageAfterRedirect(__('Erro ao agendar ticket: ') . $e->getMessage(), false, ERROR);
            }
            
        } else {
            // ========================================
            // TICKET PASSADO/ATUAL - CRIAR IMEDIATAMENTE
            // SEGUINDO O FLUXO NATIVO DO GLPI
            // ========================================
            
            // PASSO 1: Criar ticket com status "Novo" (sem atribuição)
            $ticket = new Ticket();
            $ticket_data = [
                'entities_id' => $entities_id,
                'name' => $titulo,
                'date' => $data_abertura,
                'date_creation' => $data_abertura,
                'date_mod' => $data_abertura,
                'users_id_recipient' => $users_id_recipient,
                'users_id_lastupdater' => $current_user_id,
                'status' => 1, // Novo
                'content' => $descricao,
                'urgency' => 3,
                'impact' => 3,
                'priority' => 3,
                'type' => 1,
                'requesttypes_id' => 1,
                'itilcategories_id' => $itilcategories_id
            ];
            
            $ticket_id = $ticket->add($ticket_data);
            
            if ($ticket_id) {
                // PASSO 2: Simular botão "Associar a mim mesmo" - adicionar usuário atribuído
                $ticket_user = new Ticket_User();
                $ticket_user->add([
                    'tickets_id' => $ticket_id,
                    'users_id' => $current_user_id,
                    'type' => 2, // Atribuído
                    'use_notification' => 1
                ]);
                
                // PASSO 3: Atualizar ticket para simular "take into account" - comportamento do botão nativo
                $ticket->update([
                    'id' => $ticket_id,
                    'status' => 2, // Em atendimento
                    'takeintoaccountdate' => $data_abertura,
                    'date_mod' => $data_abertura,
                    'users_id_lastupdater' => $current_user_id
                ]);
                
                // PASSO 4: Adicionar followup se solicitado (ANTES da solução)
                if ($adicionar_comentario && !empty($followup_content)) {
                    $followup = new ITILFollowup();
                    $followup->add([
                        'itemtype' => 'Ticket',
                        'items_id' => $ticket_id,
                        'date' => $data_abertura,
                        'date_creation' => $data_abertura,
                        'date_mod' => $data_abertura,
                        'users_id' => $current_user_id,
                        'users_id_editor' => $current_user_id,
                        'content' => $followup_content,
                        'is_private' => 0,
                        'requesttypes_id' => 1,
                        'timeline_position' => 1
                    ]);
                }
                
                // PASSO 5: Adicionar solução se solicitado (ÚLTIMA AÇÃO)
                if ($solucionar_ticket && !empty($solucao_content)) {
                    // Primeiro: criar a solução
                    $solution = new ITILSolution();
                    $solution_data = [
                        'itemtype' => 'Ticket',
                        'items_id' => $ticket_id,
                        'solutiontypes_id' => 1,
                        'content' => $solucao_content,
                        'users_id' => $current_user_id,
                        'users_id_editor' => $current_user_id,
                        'date_creation' => !empty($data_solucao) ? $data_solucao : date('Y-m-d H:i:s'),
                        'date_mod' => !empty($data_solucao) ? $data_solucao : date('Y-m-d H:i:s'),
                        'status' => 3 // Status aprovado automaticamente
                    ];
                    
                    // Se tem data específica de solução, adicionar aprovação automática
                    if (!empty($data_solucao)) {
                        $solution_data['date_approval'] = $data_solucao;
                        $solution_data['users_id_approval'] = $current_user_id;
                    }
                    
                    $solution_id = $solution->add($solution_data);
                    
                    // Segundo: se a solução foi criada E tem data específica, resolver o ticket
                    if ($solution_id && !empty($data_solucao)) {
                        // Usar método nativo do GLPI para resolver ticket
                        $input_solve = [
                            'id' => $ticket_id,
                            'status' => 5, // Status solucionado
                            'solvedate' => $data_solucao,
                            'date_mod' => $data_solucao,
                            'users_id_lastupdater' => $current_user_id
                        ];
                        
                        // Definir closedate também se for para fechar automaticamente
                        $input_solve['closedate'] = $data_solucao;
                        $input_solve['actiontime'] = 19800;
                        
                        // Atualizar ticket usando método nativo
                        $ticket->update($input_solve);
                        
                        // Log para debug
                        error_log("PLUGIN: Ticket " . $ticket_id . " resolvido com solução ID: " . $solution_id);
                    }
                }
                
                // PASSO 6: Salvar log do ticket retroativo criado
                $user_requerente = new User();
                $user_requerente->getFromDB($users_id_recipient);
                $user_criador = new User();
                $user_criador->getFromDB($current_user_id);
                $entity = new Entity();
                $entity->getFromDB($entities_id);
                
                $log_data = [
                    'tickets_id' => $ticket_id,
                    'entities_id' => $entities_id,
                    'data_abertura' => $data_abertura,
                    'data_solucao' => ($solucionar_ticket && $data_solucao) ? $data_solucao : null,
                    'titulo' => $titulo,
                    'usuario_requerente_id' => $users_id_recipient,
                    'usuario_requerente_nome' => trim($user_requerente->fields['firstname'] . ' ' . $user_requerente->fields['realname']) ?: $user_requerente->fields['name'],
                    'usuario_atribuido_id' => $current_user_id,
                    'usuario_atribuido_nome' => trim($user_criador->fields['firstname'] . ' ' . $user_criador->fields['realname']) ?: $user_criador->fields['name'],
                    'solucao_conteudo' => $solucionar_ticket ? $solucao_content : null,
                    'entidade_nome' => $entity->fields['name'],
                    'data_criacao_log' => date('Y-m-d H:i:s'),
                    'usuario_criador_id' => $current_user_id,
                    'usuario_criador_nome' => trim($user_criador->fields['firstname'] . ' ' . $user_criador->fields['realname']) ?: $user_criador->fields['name']
                ];
                
                $DB->insert('glpi_plugin_chamadosrecorrentes_logs', $log_data);
                
                Session::addMessageAfterRedirect(__('Ticket retroativo criado com sucesso! ID: ') . $ticket_id, false, INFO);
            } else {
                Session::addMessageAfterRedirect(__('Erro ao criar ticket'), false, ERROR);
            }
        }
        
    } catch (Exception $e) {
        error_log("PLUGIN CHAMADOS RECORRENTES: Exception geral: " . $e->getMessage());
        Session::addMessageAfterRedirect(__('Erro: ') . $e->getMessage(), false, ERROR);
    }
    
    Html::redirect($_SERVER['PHP_SELF']);
}

// ========================================
// CABEÇALHO E INTERFACE DA PÁGINA
// ========================================

// Cabeçalho da página
Html::header("Chamados Recorrentes", $_SERVER['PHP_SELF'], "tools", "pluginchamadosrecorrentesmenu");

// Criar abas
$tabs = [
    1 => [
        'title' => 'Abertura de Tickets (Passado e Futuro)',
        'url' => $CFG_GLPI['root_doc'] . '/plugins/chamadosrecorrentes/front/page.php',
        'active' => true
    ],
    2 => [
        'title' => 'Agendamento Continuo de Tickets',
        'url' => $CFG_GLPI['root_doc'] . '/plugins/chamadosrecorrentes/front/recorrentes.php',
        'active' => false
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
    .scheduled-item { font-size: 11px; }
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
.scheduled-item { 
    position: relative; 
    margin: 8px 0; 
    background-color: #fff3cd; 
    border-left: 4px solid #ffc107; 
    font-size: 12px; 
    border-radius: 4px; 
    overflow: visible; 
    z-index: 1; 
}
.countdown { font-weight: bold; color: #007bff; }
.accordion-header { 
    position: relative; 
    z-index: 2; 
}
.accordion-content { 
    position: relative; 
    z-index: 1; 
}
.scheduled-tickets-container {
    max-height: 300px;
    overflow-y: auto;
    padding-right: 5px;
}
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

// ========================================
// SEÇÃO DO FORMULÁRIO
// ========================================
echo "<div class='chamadosrecorrentes-form-section'>";

// Formulário
echo "<form method='post' action='" . $_SERVER['PHP_SELF'] . "'>";

// Token CSRF
Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

// Tabela principal do formulário
echo "<table class='tab_cadre_fixe' style='width: 100%;'>";

// PRIMEIRA LINHA - Data/hora de abertura e Data/hora da solução
echo "<tr class='tab_bg_1'>";
echo "<td width='15%'><label for='data_abertura'>Data/Hora de Abertura:</label></td>";
echo "<td width='35%'>";
Html::showDateTimeField('data_abertura', ['value' => '', 'maybeempty' => true]);
echo "</td>";
echo "<td width='15%'><label for='data_solucao'>Data/Hora da Solução:</label></td>";
echo "<td width='35%'>";
Html::showDateTimeField('data_solucao', ['value' => '', 'maybeempty' => true]);
echo "</td>";
echo "</tr>";

// SEGUNDA LINHA - Entidade, Categoria e Título
echo "<tr class='tab_bg_1'>";
echo "<td><label for='entities_id'>Entidade:</label></td>";
echo "<td>";
Entity::dropdown(['value' => $current_entity, 'name' => 'entities_id']);
echo "</td>";
echo "<td><label for='itilcategories_id'>Categoria: <span class='red'>*</span></label></td>";
echo "<td>";
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

// TERCEIRA LINHA - Descrição (largura completa)
echo "<tr class='tab_bg_1'>";
echo "<td><label for='descricao'>Descrição: <span class='red'>*</span></label></td>";
echo "<td colspan='3'>";
echo "<textarea name='descricao' id='descricao' required rows='4' class='form-control' style='width: 100%;'></textarea>";
echo "</td>";
echo "</tr>";

// QUARTA LINHA - Usuário requerente e Usuário atribuído
echo "<tr class='tab_bg_1'>";
echo "<td><label for='users_id_recipient'>Usuário Requerente: <span class='red'>*</span></label></td>";
echo "<td>";
echo "<select name='users_id_recipient' id='users_id_recipient' required class='form-control'>";
echo "<option value=''>Selecione um usuário</option>";

// Buscar usuários sem limitação
global $DB;
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

// Usuário atribuído (somente leitura)
echo "<td><label for='usuario_atribuido'>Usuário Atribuído:</label></td>";
echo "<td>";
$current_user = new User();
$current_user->getFromDB($current_user_id);
$current_user_name = trim($current_user->fields['firstname'] . ' ' . $current_user->fields['realname']) ?: $current_user->fields['name'];
echo "<input type='text' value='" . htmlspecialchars($current_user_name) . "' readonly class='form-control' style='background-color: #f5f5f5; color: #666;'>";
echo "</td>";
echo "</tr>";

// CHECKBOXES CONDICIONAIS
echo "<tr class='tab_bg_1'>";
echo "<td colspan='4'>";

echo "<div class='checkbox-container'>";
echo "<div style='margin-bottom: 10px;'>";
echo "<label><input type='checkbox' id='adicionar_comentario' name='adicionar_comentario' onchange='toggleComentarioField()'> Adicionar comentário ao chamado?</label>";
echo "</div>";

echo "<div style='margin-bottom: 10px;'>";
echo "<label><input type='checkbox' id='solucionar_ticket' name='solucionar_ticket' onchange='toggleSolucaoField()'> Deseja solucionar o ticket?</label>";
echo "</div>";
echo "</div>";

echo "</td>";
echo "</tr>";

// QUINTA LINHA - Conteúdo do acompanhamento (condicional)
echo "<tr class='tab_bg_1' id='followup_row' style='display: none;'>";
echo "<td><label for='followup_content'>Conteúdo do Acompanhamento:</label></td>";
echo "<td colspan='3'>";
echo "<textarea name='followup_content' id='followup_content' rows='3' class='form-control' style='width: 100%;'></textarea>";
echo "</td>";
echo "</tr>";

// SEXTA LINHA - Conteúdo da solução (condicional)
echo "<tr class='tab_bg_1' id='solucao_row' style='display: none;'>";
echo "<td><label for='solucao_content'>Conteúdo da Solução:</label></td>";
echo "<td colspan='3'>";
echo "<textarea name='solucao_content' id='solucao_content' rows='4' class='form-control' style='width: 100%;'></textarea>";
echo "</td>";
echo "</tr>";

echo "</table>";

// Botão de submissão
echo "<div class='center' style='margin-top: 20px;'>";
echo "<input type='submit' name='criar_ticket_retroativo' value='Abrir Chamado' class='btn btn-primary'>";
echo "</div>";

// Fechar formulário CORRETAMENTE - deve ser APÓS todos os inputs
Html::closeForm();

echo "</div>"; // Fim da seção do formulário

// ========================================
// SEÇÃO DE STATUS DO SISTEMA
// ========================================
echo "<div class='chamadosrecorrentes-status-section'>";

// Processar ativação/desativação do Event Scheduler
if (isset($_GET['action']) && $_GET['action'] == 'activate_scheduler') {
    try {
        $result = $DB->query("SET GLOBAL event_scheduler = ON");
        if ($result) {
            Session::addMessageAfterRedirect('Event Scheduler MySQL ativado com sucesso', false, INFO);
        } else {
            Session::addMessageAfterRedirect('Erro ao ativar Event Scheduler MySQL', false, ERROR);
        }
    } catch (Exception $e) {
        Session::addMessageAfterRedirect('Erro ao ativar Event Scheduler: ' . $e->getMessage(), false, ERROR);
    }
    Html::redirect($_SERVER['PHP_SELF']);
}

if (isset($_GET['action']) && $_GET['action'] == 'deactivate_scheduler') {
    try {
        $result = $DB->query("SET GLOBAL event_scheduler = OFF");
        if ($result) {
            Session::addMessageAfterRedirect('Event Scheduler MySQL desativado com sucesso', false, WARNING);
        } else {
            Session::addMessageAfterRedirect('Erro ao desativar Event Scheduler MySQL', false, ERROR);
        }
    } catch (Exception $e) {
        Session::addMessageAfterRedirect('Erro ao desativar Event Scheduler: ' . $e->getMessage(), false, ERROR);
    }
    Html::redirect($_SERVER['PHP_SELF']);
}

try {
    $event_scheduler_result = $DB->request("SHOW VARIABLES LIKE 'event_scheduler'");
    $event_scheduler_active = false;

    foreach ($event_scheduler_result as $row) {
        if ($row['Value'] == 'ON') {
            $event_scheduler_active = true;
            break;
        }
    }

    echo "<div style='margin-bottom: 15px; display: flex; align-items: center; gap: 10px;'>";
    echo "<strong>Event Scheduler MySQL</strong> ";
    
    if ($event_scheduler_active) {
        echo "<span class='status-active'>✅ Ativo</span>";
        
        // Verificar próxima execução
        try {
            $next_execution = $DB->request("SHOW EVENTS WHERE Name = 'process_scheduled_tickets'");
            $next_exec_info = "";
            
            foreach ($next_execution as $event) {
                if ($event['Status'] == 'ENABLED') {
                    $next_exec_info = " - Próxima execução: " . date('H:i:s', time() + 60 - (time() % 60));
                    break;
                }
            }
        } catch (Exception $e) {
            // Erro silencioso
        }
        
        // Botão para desativar quando está ativo
        echo "<a href='" . $_SERVER['PHP_SELF'] . "?action=deactivate_scheduler' onclick='return confirm(\"Deseja desativar o Event Scheduler MySQL? Isso impedirá o processamento automático de tickets futuros.\");' class='btn btn-primary' style='padding: 4px 8px; font-size: 11px; text-decoration: none;'>⏸ Desativar</a>";
        
    } else {
        echo "<span class='status-inactive'>❌ Inativo</span>";
        
        // Botão para ativar quando está inativo
        echo "<a href='" . $_SERVER['PHP_SELF'] . "?action=activate_scheduler' onclick='return confirm(\"Deseja ativar o Event Scheduler MySQL?\");' class='btn btn-primary' style='padding: 4px 8px; font-size: 11px; text-decoration: none;'>⚡ Ativar</a>";
    }
    
    echo "</div>";
    
    // Mostrar aviso apenas quando inativo
    if (!$event_scheduler_active) {
        echo "<div style='margin-bottom: 15px;'>";
        echo "<small style='color: #dc3545;'>⚠️ O Event Scheduler precisa estar ativo para processar tickets futuros</small>";
        echo "</div>";
    }

    // Usar abordagem direta com SQL que funciona no GLPI
if (isset($_GET['action']) && $_GET['action'] == 'delete_scheduled' && isset($_GET['id'])) {
    
    $ticket_id = (int)$_GET['id'];
    try {
        $result = $DB->delete('glpi_plugin_chamadosrecorrentes_scheduled', [
            'id' => $ticket_id,
            'status' => 0
        ]);
        
        if ($result) {
            Session::addMessageAfterRedirect('Ticket agendado deletado com sucesso', false, INFO);
        } else {
            Session::addMessageAfterRedirect('Ticket agendado não encontrado ou já executado', false, WARNING);
        }
    } catch (Exception $e) {
        Session::addMessageAfterRedirect('Erro ao deletar: ' . $e->getMessage(), false, ERROR);
    }
    Html::redirect($_SERVER['PHP_SELF']);
}

if (isset($_GET['action']) && $_GET['action'] == 'delete_all_scheduled') {
    
    try {
        $result = $DB->delete('glpi_plugin_chamadosrecorrentes_scheduled', [
            'status' => 0
        ]);
        
        if ($result) {
            $affected = $DB->affectedRows();
            Session::addMessageAfterRedirect('Deletados ' . $affected . ' tickets agendados', false, INFO);
        } else {
            Session::addMessageAfterRedirect('Nenhum ticket agendado para deletar', false, WARNING);
        }
    } catch (Exception $e) {
        Session::addMessageAfterRedirect('Erro ao deletar: ' . $e->getMessage(), false, ERROR);
    }
    Html::redirect($_SERVER['PHP_SELF']);
}

if (isset($_POST['deletar_todos_agendados'])) {
    Html::checkCSRFToken($_POST['_token']);
    
    try {
        $result = $DB->query("DELETE FROM glpi_plugin_chamadosrecorrentes_scheduled WHERE status = 0");
        if ($result) {
            Session::addMessageAfterRedirect(__('Todos os tickets agendados foram deletados'), false, INFO);
        } else {
            Session::addMessageAfterRedirect(__('Erro ao deletar tickets agendados'), false, ERROR);
        }
    } catch (Exception $e) {
        Session::addMessageAfterRedirect(__('Erro: ') . $e->getMessage(), false, ERROR);
    }
    Html::redirect($_SERVER['PHP_SELF']);
}

// Lista de tickets programados
echo "<h4 style='color: #333; margin-bottom: 10px;'>Abertura de Chamados Programada</h4>";

try {
    $scheduled_tickets = $DB->request([
        'FROM' => 'glpi_plugin_chamadosrecorrentes_scheduled',
        'WHERE' => ['status' => 0],
        'ORDER' => ['scheduled_date ASC'],
        'LIMIT' => 10
    ]);

    if (count($scheduled_tickets) > 0) {
        // Botão para deletar todos
        echo "<div style='margin-bottom: 10px; text-align: right;'>";
        echo "<a href='" . $_SERVER['PHP_SELF'] . "?action=delete_all_scheduled' onclick='return confirm(\"Tem certeza que deseja deletar TODOS os tickets agendados? Esta ação não pode ser desfeita.\");' style='background-color: #dc3545; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 11px; text-decoration: none; display: inline-block;'>Deletar Todos</a>";
        echo "</div>";
        
        echo "<div class='scheduled-tickets-container'>";
        foreach ($scheduled_tickets as $scheduled) {
            $tempo_restante = strtotime($scheduled['scheduled_date']) - time();
            
            if ($tempo_restante > 0) {
                $dias = floor($tempo_restante / 86400);
                $horas = floor(($tempo_restante % 86400) / 3600);
                $minutos = floor(($tempo_restante % 3600) / 60);
                
                $tempo_texto = "";
                if ($dias > 0) $tempo_texto .= $dias . "d ";
                if ($horas > 0) $tempo_texto .= $horas . "h ";
                $tempo_texto .= $minutos . "min";
            } else {
                $tempo_texto = "Executando...";
            }
            
            // Buscar dados relacionados
            $entity = new Entity();
            $entity->getFromDB($scheduled['entities_id']);
            
            $user_requerente = new User();
            $user_requerente->getFromDB($scheduled['users_id_recipient']);
            $nome_requerente = trim($user_requerente->fields['firstname'] . ' ' . $user_requerente->fields['realname']) ?: $user_requerente->fields['name'];
            
            $user_atribuido = new User();
            $user_atribuido->getFromDB($scheduled['created_by']);
            $nome_atribuido = trim($user_atribuido->fields['firstname'] . ' ' . $user_atribuido->fields['realname']) ?: $user_atribuido->fields['name'];
            
            $categoria = new ITILCategory();
            $categoria->getFromDB($scheduled['itilcategories_id']);
            
            $accordion_id = "accordion_" . $scheduled['id'];
            
            echo "<div class='scheduled-item' style='margin: 10px 0; background-color: #fff3cd; border-left: 4px solid #ffc107; font-size: 12px; border-radius: 4px; overflow: hidden;'>";
            
            // Cabeçalho do acordeão (clicável)
            echo "<div class='accordion-header' onclick='toggleAccordion(\"" . $accordion_id . "\")' style='padding: 12px; cursor: pointer; user-select: none; display: flex; justify-content: space-between; align-items: center; background-color: #fff3cd; border-bottom: 1px solid #ffeaa7;'>";
            
            echo "<div style='flex: 1;'>";
            echo "<strong style='color: #856404; font-size: 13px;'>📋 " . htmlspecialchars($scheduled['name']) . "</strong><br>";
            echo "<small style='color: #666;'>Abertura: " . Html::convDateTime($scheduled['scheduled_date']) . "</small>";
            echo "</div>";
            
            echo "<div style='display: flex; align-items: center; gap: 10px;'>";
            echo "<span class='countdown' style='font-weight: bold; color: #007bff; font-size: 11px;'>⏱️ " . $tempo_texto . "</span>";
            
            // Botão deletar individual
           echo "<a href='" . $_SERVER['PHP_SELF'] . "?action=delete_scheduled&id=" . $scheduled['id'] . "' onclick='return confirm(\"Tem certeza que deseja deletar este ticket agendado?\");' title='Deletar ticket agendado' style='background-color: #dc3545; color: white; border: none; padding: 4px 6px; border-radius: 3px; cursor: pointer; font-size: 10px; text-decoration: none; display: inline-block;'>Deletar</a>";
            
            echo "<span class='accordion-arrow' id='arrow_" . $accordion_id . "' style='color: #856404; font-size: 14px; transition: transform 0.3s;'>▶</span>";
            echo "</div>";
            
            echo "</div>";
            
            // Conteúdo do acordeão (inicialmente oculto)
            echo "<div class='accordion-content' id='" . $accordion_id . "' style='display: none; padding: 12px; background-color: #fffbf0;'>";
            
            // Grid de informações detalhadas
            echo "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px;'>";
            
            echo "<div><strong>Título:</strong><br>" . htmlspecialchars($scheduled['name']) . "</div>";
            echo "<div><strong>Data/Hora Solução:</strong><br>" . ($scheduled['solvedate'] ? Html::convDateTime($scheduled['solvedate']) : 'Não definida') . "</div>";
            
            echo "<div><strong>Entidade:</strong><br>" . htmlspecialchars($entity->fields['name']) . "</div>";
            echo "<div><strong>Categoria:</strong><br>" . htmlspecialchars($categoria->fields['name']) . "</div>";
            
            echo "<div><strong>Usuário Requerente:</strong><br>" . htmlspecialchars($nome_requerente) . "</div>";
            echo "<div><strong>Usuário Atribuído:</strong><br>" . htmlspecialchars($nome_atribuido) . "</div>";
            
            echo "</div>";
            
            // Descrição do ticket
            echo "<div style='margin-bottom: 8px;'>";
            echo "<strong>Descrição:</strong><br>";
            echo "<div style='background-color: #fff; padding: 8px; border-radius: 4px; border: 1px solid #e6ccb3; color: #333; max-height: 100px; overflow-y: auto;'>";
            echo htmlspecialchars($scheduled['content']);
            echo "</div>";
            echo "</div>";
            
            // Conteúdo do acompanhamento (se houver)
            if (!empty($scheduled['followup_content'])) {
                echo "<div style='margin-bottom: 8px;'>";
                echo "<strong>Conteúdo do Acompanhamento:</strong><br>";
                echo "<div style='background-color: #fff; padding: 8px; border-radius: 4px; border: 1px solid #e6ccb3; color: #666; max-height: 80px; overflow-y: auto;'>";
                echo htmlspecialchars($scheduled['followup_content']);
                echo "</div>";
                echo "</div>";
            }
            
            // Conteúdo da solução (se houver)
            if (!empty($scheduled['solution_content'])) {
                echo "<div style='margin-bottom: 8px;'>";
                echo "<strong>Conteúdo da Solução:</strong><br>";
                echo "<div style='background-color: #fff; padding: 8px; border-radius: 4px; border: 1px solid #e6ccb3; color: #333; max-height: 80px; overflow-y: auto;'>";
                echo htmlspecialchars($scheduled['solution_content']);
                echo "</div>";
                echo "</div>";
            }
            
            echo "</div>"; // Fim do conteúdo do acordeão
            echo "</div>"; // Fim do item agendado
        }
        echo "</div>"; // Fim do container dos tickets programados
    } else {
        echo "<p style='color: #666; font-style: italic;'>Nenhum ticket programado no momento.</p>";
    }
        
    } catch (Exception $e) {
        echo "<p style='color: #dc3545;'>Erro ao carregar tickets programados: " . $e->getMessage() . "</p>";
        error_log("PLUGIN CHAMADOS RECORRENTES: Erro ao buscar tickets agendados: " . $e->getMessage());
    }

} catch (Exception $e) {
    echo "<p style='color: #dc3545;'>Erro ao verificar status do sistema: " . $e->getMessage() . "</p>";
    error_log("PLUGIN CHAMADOS RECORRENTES: Erro ao verificar Event Scheduler: " . $e->getMessage());
}

echo "</div>"; // Fim da seção de status

// ========================================
// SEÇÃO DOS LOGS
// ========================================
echo "<div class='chamadosrecorrentes-logs-section'>";
echo "<h2>Chamados Abertos via Plugin</h2>";

try {
    // Buscar logs dos tickets retroativos
    $logs_iterator = $DB->request([
        'FROM' => 'glpi_plugin_chamadosrecorrentes_logs',
        'ORDER' => ['data_criacao_log DESC'],
        'LIMIT' => 50
    ]);

    if (count($logs_iterator) > 0) {
        echo "<div class='logs-scroll'>";
        echo "<table class='logs-table'>";
        echo "<thead>";
        echo "<tr>";
        echo "<th>ID Ticket</th>";
        echo "<th>Entidade</th>";
        echo "<th>Data/Hora Abertura</th>";
        echo "<th>Data/Hora Solução</th>";
        echo "<th>Título</th>";
        echo "<th>Usuário Requerente</th>";
        echo "<th>Usuário Atribuído</th>";
        echo "<th>Solução</th>";
        echo "<th>Criado em</th>";
        echo "</tr>";
        echo "</thead>";
        echo "<tbody>";
        
        foreach ($logs_iterator as $log) {
            echo "<tr>";
            echo "<td><a href='" . $CFG_GLPI['root_doc'] . "/front/ticket.form.php?id=" . $log['tickets_id'] . "' target='_blank'>" . $log['tickets_id'] . "</a></td>";
            echo "<td>" . htmlspecialchars($log['entidade_nome']) . "</td>";
            echo "<td>" . Html::convDateTime($log['data_abertura']) . "</td>";
            echo "<td>" . ($log['data_solucao'] ? Html::convDateTime($log['data_solucao']) : '-') . "</td>";
            echo "<td title='" . htmlspecialchars($log['titulo']) . "'>" . htmlspecialchars(mb_substr($log['titulo'], 0, 30)) . (mb_strlen($log['titulo']) > 30 ? '...' : '') . "</td>";
            echo "<td>" . htmlspecialchars($log['usuario_requerente_nome']) . "</td>";
            echo "<td>" . htmlspecialchars($log['usuario_atribuido_nome']) . "</td>";
            echo "<td title='" . htmlspecialchars($log['solucao_conteudo']) . "'>" . htmlspecialchars(mb_substr($log['solucao_conteudo'], 0, 20)) . (mb_strlen($log['solucao_conteudo']) > 20 ? '...' : '') . "</td>";
            echo "<td>" . Html::convDateTime($log['data_criacao_log']) . "</td>";
            echo "</tr>";
        }
        
        echo "</tbody>";
        echo "</table>";
        echo "</div>";
    } else {
        echo "<p>Nenhum ticket retroativo foi criado ainda.</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: #dc3545;'>Erro ao carregar histórico: " . $e->getMessage() . "</p>";
    error_log("PLUGIN CHAMADOS RECORRENTES: Erro ao buscar logs: " . $e->getMessage());
}

echo "</div>"; // Fim da seção dos logs

echo "</div>"; // Fim do container principal

// ========================================
// JAVASCRIPT PARA FUNCIONALIDADES
// ========================================
echo "<script>
function toggleAccordion(elementId) {
    var content = document.getElementById(elementId);
    var arrow = document.getElementById('arrow_' + elementId);
    
    if (content.style.display === 'none' || content.style.display === '') {
        content.style.display = 'block';
        arrow.style.transform = 'rotate(90deg)';
    } else {
        content.style.display = 'none';
        arrow.style.transform = 'rotate(0deg)';
    }
}

function toggleComentarioField() {
    var checkbox = document.getElementById('adicionar_comentario');
    var row = document.getElementById('followup_row');
    var textarea = document.getElementById('followup_content');
    
    if (checkbox.checked) {
        row.style.display = 'table-row';
        textarea.setAttribute('required', 'required');
    } else {
        row.style.display = 'none';
        textarea.removeAttribute('required');
        textarea.value = '';
    }
}

function toggleSolucaoField() {
    var checkbox = document.getElementById('solucionar_ticket');
    var row = document.getElementById('solucao_row');
    var textarea = document.getElementById('solucao_content');
    
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