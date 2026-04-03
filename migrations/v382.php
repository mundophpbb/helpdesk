<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v382 extends \phpbb\db\migration\migration
{
    protected $roles = [
        'Help Desk Administrator' => [
            'type' => 'a_',
            'description' => 'Administrative role for Help Desk configuration access',
            'permissions' => ['a_helpdesk_manage'],
        ],
        'Help Desk Supervisor' => [
            'type' => 'm_',
            'description' => 'Operational supervisor role for the Help Desk queue',
            'permissions' => ['m_helpdesk_queue', 'm_helpdesk_manage', 'm_helpdesk_assign', 'm_helpdesk_bulk'],
        ],
        'Help Desk Agent' => [
            'type' => 'm_',
            'description' => 'Agent role for daily Help Desk ticket handling',
            'permissions' => ['m_helpdesk_queue', 'm_helpdesk_manage', 'm_helpdesk_assign'],
        ],
        'Help Desk Auditor' => [
            'type' => 'm_',
            'description' => 'Read-only operational role for the Help Desk queue',
            'permissions' => ['m_helpdesk_queue'],
        ],
        'Help Desk Customer' => [
            'type' => 'f_',
            'description' => 'Forum role for members who may view Help Desk context and open tickets',
            'permissions' => ['f_helpdesk_view', 'f_helpdesk_ticket'],
        ],
        'Help Desk Read Only' => [
            'type' => 'f_',
            'description' => 'Forum role for members who may only view Help Desk context',
            'permissions' => ['f_helpdesk_view'],
        ],
    ];

    public function effectively_installed()
    {
        return $this->permission_exists('f_helpdesk_view')
            && $this->permission_exists('f_helpdesk_ticket')
            && $this->role_exists('Help Desk Administrator')
            && $this->role_exists('Help Desk Supervisor')
            && $this->role_exists('Help Desk Agent')
            && $this->role_exists('Help Desk Auditor')
            && $this->role_exists('Help Desk Customer')
            && $this->role_exists('Help Desk Read Only');
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v381'];
    }

    public function update_data()
    {
        $data = [
            ['permission.add', ['f_helpdesk_view']],
            ['permission.add', ['f_helpdesk_ticket']],
        ];

        foreach ($this->roles as $role_name => $role)
        {
            $data[] = ['permission.role_add', [$role_name, $role['type'], $role['description']]];
            foreach ($role['permissions'] as $permission)
            {
                $data[] = ['permission.permission_set', [$role_name, $permission]];
            }
        }

        return $data;
    }

    public function revert_data()
    {
        return [
            ['custom', [[$this, 'remove_helpdesk_roles_safely']]],
            ['custom', [[$this, 'remove_helpdesk_forum_permissions_safely']]],
        ];
    }


    public function remove_helpdesk_roles_safely()
    {
        foreach (array_keys($this->roles) as $role_name)
        {
            $role_id = $this->get_role_id($role_name);
            if (!$role_id)
            {
                continue;
            }

            $sql = 'DELETE FROM ' . ACL_USERS_TABLE . '
                WHERE auth_role_id = ' . (int) $role_id;
            $this->db->sql_query($sql);

            $sql = 'DELETE FROM ' . ACL_GROUPS_TABLE . '
                WHERE auth_role_id = ' . (int) $role_id;
            $this->db->sql_query($sql);

            $sql = 'DELETE FROM ' . ACL_ROLES_DATA_TABLE . '
                WHERE role_id = ' . (int) $role_id;
            $this->db->sql_query($sql);

            $sql = 'DELETE FROM ' . ACL_ROLES_TABLE . '
                WHERE role_id = ' . (int) $role_id;
            $this->db->sql_query($sql);
        }
    }

    public function remove_helpdesk_forum_permissions_safely()
    {
        $this->remove_auth_option_safely('f_helpdesk_ticket');
        $this->remove_auth_option_safely('f_helpdesk_view');
    }

    protected function remove_auth_option_safely($auth_option)
    {
        $auth_option_id = $this->get_auth_option_id($auth_option);
        if (!$auth_option_id)
        {
            return;
        }

        $sql = 'DELETE FROM ' . ACL_USERS_TABLE . '
            WHERE auth_option_id = ' . (int) $auth_option_id;
        $this->db->sql_query($sql);

        $sql = 'DELETE FROM ' . ACL_GROUPS_TABLE . '
            WHERE auth_option_id = ' . (int) $auth_option_id;
        $this->db->sql_query($sql);

        $sql = 'DELETE FROM ' . ACL_ROLES_DATA_TABLE . '
            WHERE auth_option_id = ' . (int) $auth_option_id;
        $this->db->sql_query($sql);

        $sql = 'DELETE FROM ' . ACL_OPTIONS_TABLE . '
            WHERE auth_option_id = ' . (int) $auth_option_id;
        $this->db->sql_query($sql);
    }

    protected function get_auth_option_id($auth_option)
    {
        $sql = 'SELECT auth_option_id
            FROM ' . ACL_OPTIONS_TABLE . "
            WHERE auth_option = '" . $this->db->sql_escape((string) $auth_option) . "'";
        $result = $this->db->sql_query($sql);
        $auth_option_id = (int) $this->db->sql_fetchfield('auth_option_id');
        $this->db->sql_freeresult($result);

        return $auth_option_id;
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

    protected function role_exists($role_name)
    {
        $sql = 'SELECT role_id
            FROM ' . ACL_ROLES_TABLE . "
            WHERE role_name = '" . $this->db->sql_escape((string) $role_name) . "'";
        $result = $this->db->sql_query($sql);
        $role_id = (int) $this->db->sql_fetchfield('role_id');
        $this->db->sql_freeresult($result);

        return $role_id > 0;
    }
}
