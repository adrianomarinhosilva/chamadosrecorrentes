<?php

class PluginChamadosrecorrentesScheduled extends CommonDBTM {
    
    static $rightname = 'plugin_chamadosrecorrentes';
    
    static function getTable($classname = null) {
        return 'glpi_plugin_chamadosrecorrentes_scheduled';
    }
    
    static function getTypeName($nb = 0) {
        return 'Tickets Agendados';
    }
    
    function canCreate() {
        return Session::haveRight(self::$rightname, CREATE);
    }
    
    function canView() {
        return Session::haveRight(self::$rightname, READ);
    }
    
    function canUpdate() {
        return Session::haveRight(self::$rightname, UPDATE);
    }
    
    function canDelete() {
        return Session::haveRight(self::$rightname, DELETE);
    }
    
    function canPurge() {
        return Session::haveRight(self::$rightname, PURGE);
    }
    
    /**
     * Verificar se o Event Scheduler está ativo
     */
    static function isEventSchedulerActive() {
        global $DB;
        
        try {
            $result = $DB->request("SHOW VARIABLES LIKE 'event_scheduler'");
            foreach ($result as $row) {
                return ($row['Value'] == 'ON');
            }
        } catch (Exception $e) {
            return false;
        }
        
        return false;
    }
    
    /**
     * Verificar se o evento MySQL existe e está ativo
     */
    static function isEventActive() {
        global $DB;
        
        try {
            $result = $DB->request("SHOW EVENTS WHERE Name = 'process_scheduled_tickets'");
            foreach ($result as $event) {
                return ($event['Status'] == 'ENABLED');
            }
        } catch (Exception $e) {
            return false;
        }
        
        return false;
    }
    
    /**
     * Obter próxima execução do evento
     */
    static function getNextExecution() {
        // Como o evento roda a cada minuto, a próxima execução é no próximo minuto
        return date('H:i:s', time() + 60 - (time() % 60));
    }
    
    /**
     * Obter tickets agendados pendentes
     */
    static function getPendingTickets($limit = 10) {
        global $DB;
        
        $tickets = [];
        
        try {
            $iterator = $DB->request([
                'FROM' => 'glpi_plugin_chamadosrecorrentes_scheduled',
                'WHERE' => ['status' => 0],
                'ORDER' => ['scheduled_date ASC'],
                'LIMIT' => $limit
            ]);
            
            foreach ($iterator as $ticket) {
                $tempo_restante = strtotime($ticket['scheduled_date']) - time();
                
                $ticket['tempo_restante'] = $tempo_restante;
                $ticket['tempo_formatado'] = self::formatTimeRemaining($tempo_restante);
                
                $tickets[] = $ticket;
            }
        } catch (Exception $e) {
            error_log("PLUGIN CHAMADOS RECORRENTES: Erro ao buscar tickets agendados: " . $e->getMessage());
        }
        
        return $tickets;
    }
    
    /**
     * Formatar tempo restante em formato legível
     */
    static function formatTimeRemaining($seconds) {
        if ($seconds <= 0) {
            return "Executando...";
        }
        
        $dias = floor($seconds / 86400);
        $horas = floor(($seconds % 86400) / 3600);
        $minutos = floor(($seconds % 3600) / 60);
        
        $tempo_texto = "";
        if ($dias > 0) {
            $tempo_texto .= $dias . "d ";
        }
        if ($horas > 0) {
            $tempo_texto .= $horas . "h ";
        }
        $tempo_texto .= $minutos . "min";
        
        return $tempo_texto;
    }
    
    /**
     * Agendar um novo ticket
     */
    static function scheduleTicket($data) {
        global $DB;
        
        $required_fields = [
            'scheduled_date', 'entities_id', 'name', 'content',
            'users_id_recipient', 'itilcategories_id', 'created_by'
        ];
        
        // Validar campos obrigatórios
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                return false;
            }
        }
        
        // Preparar dados para inserção
        $insert_data = [
            'scheduled_date' => $data['scheduled_date'],
            'solvedate' => $data['solvedate'] ?? null,
            'entities_id' => (int)$data['entities_id'],
            'name' => $data['name'],
            'content' => $data['content'],
            'users_id_recipient' => (int)$data['users_id_recipient'],
            'itilcategories_id' => (int)$data['itilcategories_id'],
            'followup_content' => $data['followup_content'] ?? '',
            'solution_content' => $data['solution_content'] ?? '',
            'created_by' => (int)$data['created_by'],
            'status' => 0,
            'date_created' => date('Y-m-d H:i:s'),
            'date_modified' => null,
            'error_message' => null
        ];
        
        try {
            return $DB->insert('glpi_plugin_chamadosrecorrentes_scheduled', $insert_data);
        } catch (Exception $e) {
            error_log("PLUGIN CHAMADOS RECORRENTES: Erro ao agendar ticket: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Cancelar ticket agendado
     */
    static function cancelScheduledTicket($id) {
        global $DB;
        
        try {
            return $DB->update(
                'glpi_plugin_chamadosrecorrentes_scheduled',
                ['status' => 2, 'error_message' => 'Cancelado pelo usuário', 'date_modified' => date('Y-m-d H:i:s')],
                ['id' => (int)$id, 'status' => 0]
            );
        } catch (Exception $e) {
            error_log("PLUGIN CHAMADOS RECORRENTES: Erro ao cancelar ticket agendado: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obter estatísticas dos tickets agendados
     */
    static function getStatistics() {
        global $DB;
        
        $stats = [
            'pendentes' => 0,
            'executados' => 0,
            'erros' => 0,
            'cancelados' => 0
        ];
        
        try {
            // Contar pendentes
            $result = $DB->request([
                'COUNT' => 'total',
                'FROM' => 'glpi_plugin_chamadosrecorrentes_scheduled',
                'WHERE' => ['status' => 0]
            ]);
            foreach ($result as $row) {
                $stats['pendentes'] = $row['total'];
            }
            
            // Contar executados
            $result = $DB->request([
                'COUNT' => 'total',
                'FROM' => 'glpi_plugin_chamadosrecorrentes_scheduled',
                'WHERE' => ['status' => 1]
            ]);
            foreach ($result as $row) {
                $stats['executados'] = $row['total'];
            }
            
            // Contar erros/cancelados
            $result = $DB->request([
                'COUNT' => 'total',
                'FROM' => 'glpi_plugin_chamadosrecorrentes_scheduled',
                'WHERE' => ['status' => 2]
            ]);
            foreach ($result as $row) {
                $stats['erros'] = $row['total'];
            }
            
        } catch (Exception $e) {
            error_log("PLUGIN CHAMADOS RECORRENTES: Erro ao obter estatísticas: " . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Limpar tickets executados antigos (mais de 30 dias)
     */
    static function cleanOldExecutedTickets() {
        global $DB;
        
        try {
            $cutoff_date = date('Y-m-d H:i:s', strtotime('-30 days'));
            
            return $DB->delete(
                'glpi_plugin_chamadosrecorrentes_scheduled',
                [
                    'status' => 1,
                    'date_modified' => ['<', $cutoff_date]
                ]
            );
        } catch (Exception $e) {
            error_log("PLUGIN CHAMADOS RECORRENTES: Erro ao limpar tickets antigos: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Forçar execução de um ticket agendado específico
     */
    static function forceExecuteTicket($id) {
        global $DB;
        
        try {
            // Atualizar a data de agendamento para agora
            return $DB->update(
                'glpi_plugin_chamadosrecorrentes_scheduled',
                ['scheduled_date' => date('Y-m-d H:i:s'), 'date_modified' => date('Y-m-d H:i:s')],
                ['id' => (int)$id, 'status' => 0]
            );
        } catch (Exception $e) {
            error_log("PLUGIN CHAMADOS RECORRENTES: Erro ao forçar execução: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Validar se uma data é futura
     */
    static function isFutureDate($date) {
        return strtotime($date) > time();
    }
    
    /**
     * Obter histórico de execuções com erro
     */
    static function getErrorHistory($limit = 20) {
        global $DB;
        
        $errors = [];
        
        try {
            $iterator = $DB->request([
                'FROM' => 'glpi_plugin_chamadosrecorrentes_scheduled',
                'WHERE' => ['status' => 2],
                'ORDER' => ['date_modified DESC'],
                'LIMIT' => $limit
            ]);
            
            foreach ($iterator as $error) {
                $errors[] = $error;
            }
        } catch (Exception $e) {
            error_log("PLUGIN CHAMADOS RECORRENTES: Erro ao buscar histórico de erros: " . $e->getMessage());
        }
        
        return $errors;
    }
}
?>