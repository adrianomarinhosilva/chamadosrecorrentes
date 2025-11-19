<?php

class PluginChamadosrecorrentesProfile extends CommonGLPI {
    
    static $rightname = 'profile';
    
    static function getTypeName($nb = 0) {
        return 'Chamados Recorrentes';
    }
    
    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item->getType() == 'Profile') {
            return 'Chamados Recorrentes';
        }
        return '';
    }
    
    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item->getType() == 'Profile') {
            $profile = new self();
            $profile->showForm($item->getID());
        }
        return true;
    }
    
    function showForm($profiles_id, $options = []) {
        $profile = new Profile();
        $profile->getFromDB($profiles_id);
        
        if (!$profile->can($profiles_id, READ)) {
            return false;
        }
        
        $canedit = $profile->can($profiles_id, UPDATE);
        $rights = $this->getAllRights();
        $profile->displayRightsChoiceMatrix($rights, [
            'canedit' => $canedit,
            'default_class' => 'tab_bg_2'
        ]);
        
        return true;
    }
    
    static function getAllRights() {
        return [
            [
                'itemtype' => 'PluginChamadosrecorrentesScheduled',
                'label' => 'Chamados Recorrentes',
                'field' => 'plugin_chamadosrecorrentes',
                'rights' => [
                    READ => 'Ler',
                    CREATE => 'Criar',
                    UPDATE => 'Atualizar',
                    DELETE => 'Excluir'
                ]
            ]
        ];
    }
    
    static function installProfile() {
        global $DB;
        
        // Adicionar o direito na tabela
        ProfileRight::addProfileRights(['plugin_chamadosrecorrentes']);
        
        // Dar permissão total para Super-Admin
        $profiles = $DB->request([
            'FROM' => 'glpi_profiles',
            'WHERE' => ['name' => 'Super-Admin']
        ]);
        
        foreach ($profiles as $profile_data) {
            $profileRight = new ProfileRight();
            $profileRight->updateProfileRights(
                $profile_data['id'],
                ['plugin_chamadosrecorrentes' => ALLSTANDARDRIGHT]
            );
        }
        
        return true;
    }
    
    static function uninstallProfile() {
        global $DB;
        
        $DB->delete('glpi_profilerights', [
            'name' => 'plugin_chamadosrecorrentes'
        ]);
        
        return true;
    }
}
?>