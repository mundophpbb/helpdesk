<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v320 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['mundophpbb_helpdesk_department_priority_rule_definitions']);
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v310'];
    }


    public function revert_data()
    {
        return [
            ['config.remove', ['mundophpbb_helpdesk_department_priority_rule_definitions']],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_helpdesk_department_priority_rule_definitions', '']],
        ];
    }
}
