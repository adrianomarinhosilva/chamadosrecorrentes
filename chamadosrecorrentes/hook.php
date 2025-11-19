<?php

function plugin_chamadosrecorrentes_install() {
    global $DB;
    
    // Criar tabela de logs dos tickets retroativos
    $query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_chamadosrecorrentes_logs` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `tickets_id` int unsigned NOT NULL DEFAULT '0',
        `entities_id` int unsigned NOT NULL DEFAULT '0',
        `data_abertura` datetime DEFAULT NULL,
        `data_solucao` datetime DEFAULT NULL,
        `titulo` varchar(255) DEFAULT NULL,
        `usuario_requerente_id` int unsigned NOT NULL DEFAULT '0',
        `usuario_requerente_nome` varchar(255) DEFAULT NULL,
        `usuario_atribuido_id` int unsigned NOT NULL DEFAULT '0',
        `usuario_atribuido_nome` varchar(255) DEFAULT NULL,
        `solucao_conteudo` text,
        `entidade_nome` varchar(255) DEFAULT NULL,
        `data_criacao_log` datetime NOT NULL,
        `usuario_criador_id` int unsigned NOT NULL DEFAULT '0',
        `usuario_criador_nome` varchar(255) DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `tickets_id` (`tickets_id`),
        KEY `entities_id` (`entities_id`),
        KEY `usuario_requerente_id` (`usuario_requerente_id`),
        KEY `usuario_atribuido_id` (`usuario_atribuido_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    $DB->query($query) or die("Erro ao criar tabela de logs: " . $DB->error());
    
    // Criar tabela de tickets agendados
    $query_scheduled = "CREATE TABLE IF NOT EXISTS `glpi_plugin_chamadosrecorrentes_scheduled` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `scheduled_date` datetime NOT NULL,
        `solvedate` datetime DEFAULT NULL,
        `entities_id` int unsigned NOT NULL DEFAULT '0',
        `name` varchar(255) NOT NULL,
        `content` text NOT NULL,
        `users_id_recipient` int unsigned NOT NULL DEFAULT '0',
        `itilcategories_id` int unsigned NOT NULL DEFAULT '0',
        `followup_content` text,
        `solution_content` text,
        `created_by` int unsigned NOT NULL DEFAULT '0',
        `status` tinyint NOT NULL DEFAULT '0' COMMENT '0=pendente, 1=executado, 2=erro',
        `date_creation` datetime NOT NULL,
        `date_mod` datetime DEFAULT NULL,
        `error_message` text,
        `adicionar_comentario` tinyint NOT NULL DEFAULT '0',
        `solucionar_ticket` tinyint NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        KEY `scheduled_date` (`scheduled_date`),
        KEY `status` (`status`),
        KEY `entities_id` (`entities_id`),
        KEY `created_by` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    $DB->query($query_scheduled) or die("Erro ao criar tabela de agendamentos: " . $DB->error());

    // Criar tabela de recorrências
    $query_recorrencias = "CREATE TABLE IF NOT EXISTS `glpi_plugin_chamadosrecorrentes_recorrencias` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `entities_id` int unsigned NOT NULL DEFAULT '0',
        `name` varchar(255) NOT NULL,
        `content` text NOT NULL,
        `users_id_recipient` int unsigned NOT NULL DEFAULT '0',
        `itilcategories_id` int unsigned NOT NULL DEFAULT '0',
        `followup_content` text,
        `solution_content` text,
        `recorrencia_tipo` enum('minutes','hourly','daily','weekly','monthly','yearly','custom') NOT NULL,
        `intervalo` int unsigned NOT NULL DEFAULT '1',
        `data_inicio` datetime NOT NULL,
        `proximo_agendamento` datetime NOT NULL,
        `ultimo_agendamento` datetime DEFAULT NULL,
        `created_by` int unsigned NOT NULL DEFAULT '0',
        `ativo` tinyint NOT NULL DEFAULT '1',
        `date_creation` datetime NOT NULL,
        `date_mod` datetime DEFAULT NULL,
        `adicionar_comentario` tinyint NOT NULL DEFAULT '0',
        `solucionar_ticket` tinyint NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        KEY `entities_id` (`entities_id`),
        KEY `ativo` (`ativo`),
        KEY `proximo_agendamento` (`proximo_agendamento`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    $DB->query($query_recorrencias) or die("Erro ao criar tabela de recorrências: " . $DB->error());
    
    // Adicionar campos nas tabelas existentes se elas já existem (para atualizações)
    try {
        $check_scheduled = $DB->query("SHOW COLUMNS FROM `glpi_plugin_chamadosrecorrentes_scheduled` LIKE 'adicionar_comentario'");
        if ($check_scheduled && $check_scheduled->num_rows == 0) {
            $DB->query("ALTER TABLE `glpi_plugin_chamadosrecorrentes_scheduled` 
                        ADD COLUMN `adicionar_comentario` tinyint NOT NULL DEFAULT '0',
                        ADD COLUMN `solucionar_ticket` tinyint NOT NULL DEFAULT '0'");
        }
    } catch (Exception $e) {
        // Tabela não existe ainda ou campos já existem
    }

    try {
        $check_recorrencias = $DB->query("SHOW COLUMNS FROM `glpi_plugin_chamadosrecorrentes_recorrencias` LIKE 'adicionar_comentario'");
        if ($check_recorrencias && $check_recorrencias->num_rows == 0) {
            $DB->query("ALTER TABLE `glpi_plugin_chamadosrecorrentes_recorrencias` 
                        ADD COLUMN `adicionar_comentario` tinyint NOT NULL DEFAULT '0',
                        ADD COLUMN `solucionar_ticket` tinyint NOT NULL DEFAULT '0'");
        }
    } catch (Exception $e) {
        // Tabela não existe ainda ou campos já existem
    }
    
    // Verificar se o Event Scheduler está ativo
    $event_scheduler_result = $DB->request("SHOW VARIABLES LIKE 'event_scheduler'");
    $event_scheduler_status = false;
    
    foreach ($event_scheduler_result as $row) {
        if ($row['Value'] == 'ON') {
            $event_scheduler_status = true;
            break;
        }
    }
    
    // Ativar Event Scheduler se não estiver ativo
    if (!$event_scheduler_status) {
        try {
            $DB->query("SET GLOBAL event_scheduler = ON;");
        } catch (Exception $e) {
            error_log("PLUGIN CHAMADOS RECORRENTES: Não foi possível ativar o Event Scheduler: " . $e->getMessage());
        }
    }
    
    // Criar o evento MySQL para processar tickets agendados
    $DB->query("DROP EVENT IF EXISTS process_scheduled_tickets;");
    
    $event_query = "CREATE EVENT process_scheduled_tickets
    ON SCHEDULE EVERY 1 MINUTE
    STARTS NOW()
    DO
    BEGIN
        DECLARE v_id INT;
        DECLARE v_scheduled_date, v_solvedate DATETIME;
        DECLARE v_entities_id, v_users_id_recipient, v_itilcategories_id, v_created_by INT;
        DECLARE v_name, v_content, v_followup_content, v_solution_content TEXT;
        DECLARE v_adicionar_comentario, v_solucionar_ticket TINYINT DEFAULT 0;
        DECLARE v_ticket_id INT;
        DECLARE done INT DEFAULT FALSE;
        
        DECLARE cur CURSOR FOR 
            SELECT id, scheduled_date, solvedate, entities_id, name, content, 
                   users_id_recipient, itilcategories_id, followup_content, 
                   solution_content, created_by,
                   COALESCE(adicionar_comentario, 0) as adicionar_comentario,
                   COALESCE(solucionar_ticket, 0) as solucionar_ticket
            FROM glpi_plugin_chamadosrecorrentes_scheduled 
            WHERE status = 0 AND scheduled_date <= NOW();
        
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
        BEGIN
            ROLLBACK;
            UPDATE glpi_plugin_chamadosrecorrentes_scheduled 
            SET status = 2, error_message = 'Erro na execução do evento', date_mod = NOW() 
            WHERE id = v_id;
        END;
        
        OPEN cur;
        
        read_loop: LOOP
            FETCH cur INTO v_id, v_scheduled_date, v_solvedate, v_entities_id, v_name, v_content,
                          v_users_id_recipient, v_itilcategories_id, v_followup_content, 
                          v_solution_content, v_created_by, v_adicionar_comentario, v_solucionar_ticket;
            
            IF done THEN
                LEAVE read_loop;
            END IF;
            
            START TRANSACTION;
            
            -- Criar o ticket
            INSERT INTO glpi_tickets (
                entities_id, name, date, date_creation, date_mod, users_id_recipient,
                users_id_lastupdater, status, content, urgency, impact, priority,
                type, requesttypes_id, itilcategories_id
            ) VALUES (
                v_entities_id, v_name, v_scheduled_date, v_scheduled_date, v_scheduled_date,
                v_users_id_recipient, v_created_by, 2, v_content, 3, 3, 3, 1, 1, v_itilcategories_id
            );
            
            SET v_ticket_id = LAST_INSERT_ID();
            
            -- Adicionar requerente
            INSERT INTO glpi_tickets_users (tickets_id, users_id, type, use_notification)
            VALUES (v_ticket_id, v_users_id_recipient, 1, 1);
            
            -- Adicionar atribuído (usuário criador se atribui automaticamente)
            INSERT INTO glpi_tickets_users (tickets_id, users_id, type, use_notification)
            VALUES (v_ticket_id, v_created_by, 2, 1);
            
            -- Adicionar followup se solicitado (ANTES da solução)
            IF v_adicionar_comentario = 1 AND v_followup_content IS NOT NULL AND v_followup_content != '' THEN
                INSERT INTO glpi_itilfollowups (
                    itemtype, items_id, date, date_creation, date_mod, users_id, users_id_editor,
                    content, is_private, requesttypes_id, timeline_position
                ) VALUES (
                    'Ticket', v_ticket_id, v_scheduled_date, v_scheduled_date, v_scheduled_date,
                    v_created_by, v_created_by, v_followup_content, 0, 1, 1
                );
            END IF;
            
            -- Adicionar solução se solicitado (DEPOIS do followup)
            IF v_solucionar_ticket = 1 AND v_solution_content IS NOT NULL AND v_solution_content != '' THEN
                INSERT INTO glpi_itilsolutions (
                    itemtype, items_id, solutiontypes_id, content, users_id, users_id_editor,
                    date_creation, date_mod, status, date_approval, users_id_approval
                ) VALUES (
                    'Ticket', v_ticket_id, 1, v_solution_content, v_created_by, v_created_by,
                    COALESCE(v_solvedate, v_scheduled_date), COALESCE(v_solvedate, v_scheduled_date), 3, 
                    COALESCE(v_solvedate, v_scheduled_date), v_created_by
                );
                
                -- Resolver o ticket
                UPDATE glpi_tickets 
                SET status = 5, solvedate = COALESCE(v_solvedate, v_scheduled_date), 
                    closedate = COALESCE(v_solvedate, v_scheduled_date), 
                    date_mod = COALESCE(v_solvedate, v_scheduled_date), 
                    users_id_lastupdater = v_created_by, actiontime = 19800
                WHERE id = v_ticket_id;
            END IF;
            
            -- Salvar log completo do ticket criado pelo evento
            INSERT INTO glpi_plugin_chamadosrecorrentes_logs (
                tickets_id, entities_id, data_abertura, data_solucao, titulo,
                usuario_requerente_id, usuario_requerente_nome, usuario_atribuido_id, 
                usuario_atribuido_nome, solucao_conteudo, entidade_nome,
                data_criacao_log, usuario_criador_id, usuario_criador_nome
            )
            SELECT 
                v_ticket_id, 
                v_entities_id, 
                v_scheduled_date, 
                CASE WHEN v_solucionar_ticket = 1 THEN COALESCE(v_solvedate, v_scheduled_date) ELSE NULL END, 
                v_name,
                v_users_id_recipient,
                TRIM(CONCAT(COALESCE(u1.firstname, ''), ' ', COALESCE(u1.realname, ''))),
                v_created_by,
                TRIM(CONCAT(COALESCE(u2.firstname, ''), ' ', COALESCE(u2.realname, ''))),
                CASE WHEN v_solucionar_ticket = 1 THEN v_solution_content ELSE NULL END,
                e.name,
                NOW(),
                v_created_by,
                TRIM(CONCAT(COALESCE(u2.firstname, ''), ' ', COALESCE(u2.realname, '')))
            FROM glpi_users u1, glpi_users u2, glpi_entities e
            WHERE u1.id = v_users_id_recipient 
              AND u2.id = v_created_by 
              AND e.id = v_entities_id;
            
            -- Marcar como executado
            UPDATE glpi_plugin_chamadosrecorrentes_scheduled 
            SET status = 1, date_mod = NOW() 
            WHERE id = v_id;
            
            COMMIT;
            
        END LOOP;
        
        CLOSE cur;
    END";
    
    try {
        $DB->query($event_query);
    } catch (Exception $e) {
        error_log("PLUGIN CHAMADOS RECORRENTES: Erro ao criar evento MySQL: " . $e->getMessage());
    }

    // Criar o evento MySQL para processar recorrências
    $DB->query("DROP EVENT IF EXISTS process_recurrent_tickets;");
    
    $recurrent_event_query = "CREATE EVENT process_recurrent_tickets
    ON SCHEDULE EVERY 1 MINUTE
    STARTS NOW()
    DO
    BEGIN
        DECLARE v_id INT;
        DECLARE v_entities_id, v_users_id_recipient, v_itilcategories_id, v_created_by INT;
        DECLARE v_name, v_content, v_followup_content, v_solution_content TEXT;
        DECLARE v_recorrencia_tipo VARCHAR(20);
        DECLARE v_intervalo INT;
        DECLARE v_proximo_agendamento DATETIME;
        DECLARE v_novo_agendamento DATETIME;
        DECLARE v_adicionar_comentario, v_solucionar_ticket TINYINT DEFAULT 0;
        DECLARE v_ticket_id INT;
        DECLARE done INT DEFAULT FALSE;
        
        DECLARE cur CURSOR FOR 
            SELECT id, entities_id, name, content, users_id_recipient, itilcategories_id, 
                   followup_content, solution_content, recorrencia_tipo, intervalo, 
                   proximo_agendamento, created_by,
                   COALESCE(adicionar_comentario, 0) as adicionar_comentario,
                   COALESCE(solucionar_ticket, 0) as solucionar_ticket
            FROM glpi_plugin_chamadosrecorrentes_recorrencias 
            WHERE ativo = 1 AND proximo_agendamento <= NOW();
        
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
        BEGIN
            ROLLBACK;
        END;
        
        OPEN cur;
        
        read_loop: LOOP
            FETCH cur INTO v_id, v_entities_id, v_name, v_content, v_users_id_recipient, 
                          v_itilcategories_id, v_followup_content, v_solution_content,
                          v_recorrencia_tipo, v_intervalo, v_proximo_agendamento, v_created_by,
                          v_adicionar_comentario, v_solucionar_ticket;
            
            IF done THEN
                LEAVE read_loop;
            END IF;
            
            START TRANSACTION;
            
            -- Criar o ticket
            INSERT INTO glpi_tickets (
                entities_id, name, date, date_creation, date_mod, users_id_recipient,
                users_id_lastupdater, status, content, urgency, impact, priority,
                type, requesttypes_id, itilcategories_id
            ) VALUES (
                v_entities_id, v_name, NOW(), NOW(), NOW(),
                v_users_id_recipient, v_created_by, 2, v_content, 3, 3, 3, 1, 1, v_itilcategories_id
            );
            
            SET v_ticket_id = LAST_INSERT_ID();
            
            -- Adicionar requerente
            INSERT INTO glpi_tickets_users (tickets_id, users_id, type, use_notification)
            VALUES (v_ticket_id, v_users_id_recipient, 1, 1);
            
            -- Adicionar atribuído (usuário criador se atribui automaticamente)
            INSERT INTO glpi_tickets_users (tickets_id, users_id, type, use_notification)
            VALUES (v_ticket_id, v_created_by, 2, 1);
            
            -- Adicionar followup se solicitado
            IF v_adicionar_comentario = 1 AND v_followup_content IS NOT NULL AND v_followup_content != '' THEN
                INSERT INTO glpi_itilfollowups (
                    itemtype, items_id, date, date_creation, date_mod, users_id, users_id_editor,
                    content, is_private, requesttypes_id, timeline_position
                ) VALUES (
                    'Ticket', v_ticket_id, NOW(), NOW(), NOW(),
                    v_created_by, v_created_by, v_followup_content, 0, 1, 1
                );
            END IF;
            
            -- Adicionar solução se solicitado
            IF v_solucionar_ticket = 1 AND v_solution_content IS NOT NULL AND v_solution_content != '' THEN
                INSERT INTO glpi_itilsolutions (
                    itemtype, items_id, solutiontypes_id, content, users_id, users_id_editor,
                    date_creation, date_mod, status, date_approval, users_id_approval
                ) VALUES (
                    'Ticket', v_ticket_id, 1, v_solution_content, v_created_by, v_created_by,
                    NOW(), NOW(), 3, NOW(), v_created_by
                );
                
                -- Resolver o ticket
                UPDATE glpi_tickets 
                SET status = 5, solvedate = NOW(), closedate = NOW(), 
                    date_mod = NOW(), users_id_lastupdater = v_created_by, actiontime = 19800
                WHERE id = v_ticket_id;
            END IF;
            
            -- Calcular próximo agendamento baseado no tipo de recorrência
            CASE v_recorrencia_tipo
                WHEN 'minutes' THEN
                    SET v_novo_agendamento = DATE_ADD(v_proximo_agendamento, INTERVAL v_intervalo MINUTE);
                WHEN 'hourly' THEN
                    SET v_novo_agendamento = DATE_ADD(v_proximo_agendamento, INTERVAL v_intervalo HOUR);
                WHEN 'daily' THEN
                    SET v_novo_agendamento = DATE_ADD(v_proximo_agendamento, INTERVAL v_intervalo DAY);
                WHEN 'weekly' THEN
                    SET v_novo_agendamento = DATE_ADD(v_proximo_agendamento, INTERVAL (v_intervalo * 7) DAY);
                WHEN 'monthly' THEN
                    SET v_novo_agendamento = DATE_ADD(v_proximo_agendamento, INTERVAL v_intervalo MONTH);
                WHEN 'yearly' THEN
                    SET v_novo_agendamento = DATE_ADD(v_proximo_agendamento, INTERVAL v_intervalo YEAR);
                WHEN 'custom' THEN
                    SET v_novo_agendamento = DATE_ADD(v_proximo_agendamento, INTERVAL v_intervalo DAY);
                ELSE
                    SET v_novo_agendamento = DATE_ADD(v_proximo_agendamento, INTERVAL 1 DAY);
            END CASE;
            
            -- Atualizar próximo agendamento
            UPDATE glpi_plugin_chamadosrecorrentes_recorrencias 
            SET proximo_agendamento = v_novo_agendamento,
                ultimo_agendamento = NOW(),
                date_mod = NOW()
            WHERE id = v_id;
            
            -- Salvar log do ticket criado pela recorrência
            INSERT INTO glpi_plugin_chamadosrecorrentes_logs (
                tickets_id, entities_id, data_abertura, data_solucao, titulo,
                usuario_requerente_id, usuario_requerente_nome, usuario_atribuido_id, 
                usuario_atribuido_nome, solucao_conteudo, entidade_nome,
                data_criacao_log, usuario_criador_id, usuario_criador_nome
            )
            SELECT 
                v_ticket_id, 
                v_entities_id, 
                NOW(), 
                CASE WHEN v_solucionar_ticket = 1 AND v_solution_content IS NOT NULL AND v_solution_content != '' THEN NOW() ELSE NULL END, 
                v_name,
                v_users_id_recipient,
                TRIM(CONCAT(COALESCE(u1.firstname, ''), ' ', COALESCE(u1.realname, ''))),
                v_created_by,
                TRIM(CONCAT(COALESCE(u2.firstname, ''), ' ', COALESCE(u2.realname, ''))),
                CASE WHEN v_solucionar_ticket = 1 THEN v_solution_content ELSE NULL END,
                e.name,
                NOW(),
                v_created_by,
                TRIM(CONCAT(COALESCE(u2.firstname, ''), ' ', COALESCE(u2.realname, '')))
            FROM glpi_users u1, glpi_users u2, glpi_entities e
            WHERE u1.id = v_users_id_recipient 
              AND u2.id = v_created_by 
              AND e.id = v_entities_id;
            
            COMMIT;
            
        END LOOP;
        
        CLOSE cur;
    END";
    
    try {
        $DB->query($recurrent_event_query);
    } catch (Exception $e) {
        error_log("PLUGIN CHAMADOS RECORRENTES: Erro ao criar evento de recorrências: " . $e->getMessage());
    }
    
    // Instalar permissões do perfil
    if (class_exists('PluginChamadosrecorrentesProfile')) {
        PluginChamadosrecorrentesProfile::installProfile();
    }
    
    return true;
}

function plugin_chamadosrecorrentes_uninstall() {
    global $DB;
    
    // Remover permissões do perfil
    if (class_exists('PluginChamadosrecorrentesProfile')) {
        PluginChamadosrecorrentesProfile::uninstallProfile();
    }
    
    // Remover eventos MySQL
    try {
        $DB->query("DROP EVENT IF EXISTS process_scheduled_tickets;");
        $DB->query("DROP EVENT IF EXISTS process_recurrent_tickets;");
    } catch (Exception $e) {
        error_log("PLUGIN CHAMADOS RECORRENTES: Erro ao remover eventos MySQL: " . $e->getMessage());
    }
    
    // Remover tabelas
    $query1 = "DROP TABLE IF EXISTS `glpi_plugin_chamadosrecorrentes_logs`";
    $DB->query($query1) or die("Erro ao remover tabela de logs: " . $DB->error());
    
    $query2 = "DROP TABLE IF EXISTS `glpi_plugin_chamadosrecorrentes_scheduled`";
    $DB->query($query2) or die("Erro ao remover tabela de agendamentos: " . $DB->error());
    
    $query3 = "DROP TABLE IF EXISTS `glpi_plugin_chamadosrecorrentes_recorrencias`";
    $DB->query($query3) or die("Erro ao remover tabela de recorrências: " . $DB->error());
    
    return true;
}
?>