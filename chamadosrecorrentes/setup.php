<?php

function plugin_version_chamadosrecorrentes() {
    return [
        'name'         => 'Chamados Recorrentes',
        'version'      => '1.0.0',
        'author'       => 'Dev',
        'requirements' => [
            'glpi' => [
                'min' => '10.0.0'
            ]
        ]
    ];
}

function plugin_chamadosrecorrentes_check_prerequisites() {
    return true;
}

function plugin_chamadosrecorrentes_check_config() {
    return true;
}

function plugin_init_chamadosrecorrentes() {
    global $PLUGIN_HOOKS;
    
    // DECLARAR CSRF COMPLIANT É OBRIGATÓRIO
    $PLUGIN_HOOKS['csrf_compliant']['chamadosrecorrentes'] = true;
    $PLUGIN_HOOKS['menu_toadd']['chamadosrecorrentes'] = ['tools' => 'PluginChamadosrecorrentesMenu'];
    
    // Hook de instalação do perfil
    $PLUGIN_HOOKS['post_init']['chamadosrecorrentes'] = 'plugin_chamadosrecorrentes_postinit';
}

function plugin_chamadosrecorrentes_postinit() {
    global $PLUGIN_HOOKS;
    
    $PLUGIN_HOOKS['item_add']['chamadosrecorrentes'] = [
        'Profile' => 'plugin_chamadosrecorrentes_item_add_profile'
    ];
}

function plugin_chamadosrecorrentes_item_add_profile($item) {
    if (isset($item->fields['id'])) {
        PluginChamadosrecorrentesProfile::initProfile();
    }
}
?>