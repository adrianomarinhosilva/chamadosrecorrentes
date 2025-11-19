<?php

class PluginChamadosrecorrentesMenu extends CommonGLPI {
    
    static function getMenuName() {
        return 'Chamados Recorrentes';
    }
    
    static function getMenuContent() {
    $menu = [];
    $menu['title'] = self::getMenuName();
    $menu['page'] = '/plugins/chamadosrecorrentes/front/page.php';
    $menu['icon'] = 'ti ti-refresh';
    
    // Definir abas do menu
    $menu['links']['search'] = '/plugins/chamadosrecorrentes/front/page.php';
    $menu['links']['config'] = '/plugins/chamadosrecorrentes/front/config.php';
    
    // Opções das abas
    $menu['options']['page']['title'] = 'Gerenciar Chamados';
    $menu['options']['page']['icon'] = 'ti ti-ticket';
    
    $menu['options']['config']['title'] = 'Configurações';
    $menu['options']['config']['icon'] = 'ti ti-settings';
    
    return $menu;
}
}
?>