<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v250 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['mundophpbb_helpdesk_assign_status'])
            && isset($this->config['mundophpbb_helpdesk_unassign_status'])
            && isset($this->config['mundophpbb_helpdesk_department_status']);
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v240'];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_helpdesk_assign_status', '']],
            ['config.add', ['mundophpbb_helpdesk_unassign_status', '']],
            ['config.add', ['mundophpbb_helpdesk_department_status', '']],
        ];
    }
}
