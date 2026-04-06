<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v387 extends \phpbb\db\migration\migration
{
    protected $permissions = ['m_helpdesk_manage', 'm_helpdesk_assign', 'm_helpdesk_bulk', 'm_helpdesk_queue'];

    public function effectively_installed()
    {
        return !$this->role_has_any_permissions('ROLE_MOD_FULL', $this->permissions);
    }

    static public function depends_on()
    {
        return ['\\mundophpbb\\helpdesk\\migrations\\v386'];
    }

    public function update_data()
    {
        return [
            ['custom', [[$this, 'remove_helpdesk_permissions_from_full_moderator_role']]],
        ];
    }

    public function revert_data()
    {
        return [];
    }

    public function remove_helpdesk_permissions_from_full_moderator_role()
    {
        $role_id = $this->get_role_id('ROLE_MOD_FULL');
        if (!$role_id)
        {
            return;
        }

        $auth_option_ids = $this->get_auth_option_ids($this->permissions);
        if (empty($auth_option_ids))
        {
            return;
        }

        $sql = 'DELETE FROM ' . ACL_ROLES_DATA_TABLE . '
            WHERE role_id = ' . (int) $role_id . '
                AND ' . $this->db->sql_in_set('auth_option_id', $auth_option_ids);
        $this->db->sql_query($sql);
    }

    protected function role_has_any_permissions($role_name, array $permissions)
    {
        $role_id = $this->get_role_id($role_name);
        if (!$role_id)
        {
            return false;
        }

        $auth_option_ids = $this->get_auth_option_ids($permissions);
        if (empty($auth_option_ids))
        {
            return false;
        }

        $sql = 'SELECT auth_option_id
            FROM ' . ACL_ROLES_DATA_TABLE . '
            WHERE role_id = ' . (int) $role_id . '
                AND ' . $this->db->sql_in_set('auth_option_id', $auth_option_ids) . '
                AND auth_setting <> 0';
        $result = $this->db->sql_query_limit($sql, 1);
        $has_permission = (bool) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $has_permission;
    }

    protected function get_role_id($role_name)
    {
        $sql = 'SELECT role_id
            FROM ' . ACL_ROLES_TABLE . "
            WHERE role_name = '" . $this->db->sql_escape((string) $role_name) . "'";
        $result = $this->db->sql_query($sql);
        $role_id = (int) $this->db->sql_fetchfield('role_id');
        $this->db->sql_freeresult($result);

        return $role_id;
    }

    protected function get_auth_option_ids(array $permissions)
    {
        if (empty($permissions))
        {
            return [];
        }

        $sql = 'SELECT auth_option, auth_option_id
            FROM ' . ACL_OPTIONS_TABLE . '
            WHERE ' . $this->db->sql_in_set('auth_option', array_values($permissions));
        $result = $this->db->sql_query($sql);

        $map = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $map[(string) $row['auth_option']] = (int) $row['auth_option_id'];
        }
        $this->db->sql_freeresult($result);

        $ids = [];
        foreach ($permissions as $permission)
        {
            if (isset($map[$permission]))
            {
                $ids[] = $map[$permission];
            }
        }

        return $ids;
    }
}
