<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v383 extends \phpbb\db\migration\migration
{
    protected $roles = [
        'Help Desk Administrator' => [
            'permissions' => ['a_helpdesk_manage'],
        ],
        'Help Desk Supervisor' => [
            'permissions' => ['m_helpdesk_queue', 'm_helpdesk_manage', 'm_helpdesk_assign', 'm_helpdesk_bulk'],
        ],
        'Help Desk Agent' => [
            'permissions' => ['m_helpdesk_queue', 'm_helpdesk_manage', 'm_helpdesk_assign'],
        ],
        'Help Desk Auditor' => [
            'permissions' => ['m_helpdesk_queue'],
        ],
        'Help Desk Customer' => [
            'permissions' => ['f_helpdesk_view', 'f_helpdesk_ticket'],
        ],
        'Help Desk Read Only' => [
            'permissions' => ['f_helpdesk_view'],
        ],
    ];

    public function effectively_installed()
    {
        foreach ($this->roles as $role_name => $role)
        {
            if (!$this->role_has_permissions($role_name, $role['permissions']))
            {
                return false;
            }
        }

        return true;
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v382'];
    }

    public function update_data()
    {
        return [
            ['custom', [[$this, 'sync_helpdesk_roles']]],
        ];
    }

    public function revert_data()
    {
        return [];
    }

    public function sync_helpdesk_roles()
    {
        foreach ($this->roles as $role_name => $role)
        {
            $role_id = $this->get_role_id($role_name);
            if (!$role_id)
            {
                continue;
            }

            $auth_option_ids = $this->get_auth_option_ids($role['permissions']);
            if (empty($auth_option_ids))
            {
                continue;
            }

            $sql = 'DELETE FROM ' . ACL_ROLES_DATA_TABLE . '
                WHERE role_id = ' . (int) $role_id;
            $this->db->sql_query($sql);

            $insert_rows = [];
            foreach ($auth_option_ids as $auth_option_id)
            {
                $insert_rows[] = [
                    'role_id' => (int) $role_id,
                    'auth_option_id' => (int) $auth_option_id,
                    'auth_setting' => 1,
                ];
            }

            if (!empty($insert_rows))
            {
                $this->db->sql_multi_insert(ACL_ROLES_DATA_TABLE, $insert_rows);
            }
        }
    }

    protected function role_has_permissions($role_name, array $permissions)
    {
        $role_id = $this->get_role_id($role_name);
        if (!$role_id)
        {
            return false;
        }

        $expected_ids = $this->get_auth_option_ids($permissions);
        if (count($expected_ids) !== count($permissions))
        {
            return false;
        }

        $sql = 'SELECT auth_option_id, auth_setting
            FROM ' . ACL_ROLES_DATA_TABLE . '
            WHERE role_id = ' . (int) $role_id;
        $result = $this->db->sql_query($sql);

        $current = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $current[(int) $row['auth_option_id']] = (int) $row['auth_setting'];
        }
        $this->db->sql_freeresult($result);

        foreach ($expected_ids as $auth_option_id)
        {
            if (!isset($current[$auth_option_id]) || (int) $current[$auth_option_id] !== 1)
            {
                return false;
            }
        }

        return true;
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
