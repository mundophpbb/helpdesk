<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v381 extends \phpbb\db\migration\migration
{
    protected $target_auth = 'ext_mundophpbb/helpdesk && acl_a_helpdesk_manage';

    public function effectively_installed()
    {
        return $this->modules_have_expected_auth();
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v380'];
    }

    public function update_data()
    {
        return [
            ['custom', [[$this, 'sync_helpdesk_acp_module_auth']]],
        ];
    }

    public function revert_data()
    {
        return [
            ['custom', [[$this, 'revert_helpdesk_acp_module_auth']]],
        ];
    }

    public function sync_helpdesk_acp_module_auth()
    {
        $sql = 'UPDATE ' . MODULES_TABLE . "
            SET module_auth = '" . $this->db->sql_escape($this->target_auth) . "'
            WHERE module_class = 'acp'
                AND module_basename = '" . $this->db->sql_escape('\\mundophpbb\\helpdesk\\acp\\main_module') . "'
                AND " . $this->db->sql_in_set('module_mode', ['settings', 'permissions']);
        $this->db->sql_query($sql);
    }

    public function revert_helpdesk_acp_module_auth()
    {
        $sql = 'UPDATE ' . MODULES_TABLE . "
            SET module_auth = '" . $this->db->sql_escape('ext_mundophpbb/helpdesk && acl_a_board') . "'
            WHERE module_class = 'acp'
                AND module_basename = '" . $this->db->sql_escape('\\mundophpbb\\helpdesk\\acp\\main_module') . "'
                AND " . $this->db->sql_in_set('module_mode', ['settings', 'permissions']);
        $this->db->sql_query($sql);
    }

    protected function modules_have_expected_auth()
    {
        $sql = 'SELECT module_mode, module_auth
            FROM ' . MODULES_TABLE . "
            WHERE module_class = 'acp'
                AND module_basename = '" . $this->db->sql_escape('\\mundophpbb\\helpdesk\\acp\\main_module') . "'
                AND " . $this->db->sql_in_set('module_mode', ['settings', 'permissions']);
        $result = $this->db->sql_query($sql);

        $modes = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $modes[(string) $row['module_mode']] = (string) $row['module_auth'];
        }
        $this->db->sql_freeresult($result);

        return isset($modes['settings'], $modes['permissions'])
            && $modes['settings'] === $this->target_auth
            && $modes['permissions'] === $this->target_auth;
    }
}
