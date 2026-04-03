<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\acp;

class main_info
{
    public function module()
    {
        return [
            'filename' => '\\mundophpbb\\helpdesk\\acp\\main_module',
            'title' => 'ACP_HELPDESK_TITLE',
            'modes' => [
                'settings' => [
                    'title' => 'ACP_HELPDESK_SETTINGS',
                    'auth' => 'ext_mundophpbb/helpdesk && acl_a_helpdesk_manage',
                    'cat' => ['ACP_HELPDESK_TITLE'],
                ],
                'permissions' => [
                    'title' => 'ACP_HELPDESK_PERMISSIONS',
                    'auth' => 'ext_mundophpbb/helpdesk && acl_a_helpdesk_manage',
                    'cat' => ['ACP_HELPDESK_TITLE'],
                ],
            ],
        ];
    }
}
