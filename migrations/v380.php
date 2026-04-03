<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v380 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return $this->permission_exists('a_helpdesk_manage')
            && $this->module_exists('permissions');
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v370'];
    }

    public function update_data()
    {
        return [
            ['permission.add', ['a_helpdesk_manage']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'a_helpdesk_manage']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'a_helpdesk_manage']],
            ['module.add', ['acp', 'ACP_HELPDESK_TITLE', [
                'module_basename' => '\\mundophpbb\\helpdesk\\acp\\main_module',
                'modes' => ['permissions'],
            ]]],
        ];
    }

    public function revert_data()
    {
        return [
            ['module.remove', ['acp', 'ACP_HELPDESK_TITLE', [
                'module_basename' => '\\mundophpbb\\helpdesk\\acp\\main_module',
                'modes' => ['permissions'],
            ]]],
            ['permission.permission_unset', ['ROLE_ADMIN_STANDARD', ['a_helpdesk_manage']]],
            ['permission.permission_unset', ['ROLE_ADMIN_FULL', ['a_helpdesk_manage']]],
            ['permission.remove', ['a_helpdesk_manage']],
        ];
    }

    protected function permission_exists($auth_option)
    {
        $sql = 'SELECT auth_option_id
            FROM ' . ACL_OPTIONS_TABLE . "
            WHERE auth_option = '" . $this->db->sql_escape((string) $auth_option) . "'";
        $result = $this->db->sql_query($sql);
        $auth_option_id = (int) $this->db->sql_fetchfield('auth_option_id');
        $this->db->sql_freeresult($result);

        return $auth_option_id > 0;
    }

    protected function module_exists($mode)
    {
        $sql = 'SELECT module_id
            FROM ' . MODULES_TABLE . "
            WHERE module_class = 'acp'
                AND module_basename = '" . $this->db->sql_escape('\\mundophpbb\\helpdesk\\acp\\main_module') . "'
                AND module_mode = '" . $this->db->sql_escape((string) $mode) . "'";
        $result = $this->db->sql_query($sql);
        $module_id = (int) $this->db->sql_fetchfield('module_id');
        $this->db->sql_freeresult($result);

        return $module_id > 0;
    }
}
