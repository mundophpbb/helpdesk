<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v290 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['mundophpbb_helpdesk_priority_definitions']);
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v280'];
    }


    public function revert_data()
    {
        return [
            ['config.remove', ['mundophpbb_helpdesk_priority_definitions']],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_helpdesk_priority_definitions', "low|Baixa|Low|low\nnormal|Normal|Normal|normal\nhigh|Alta|High|high\ncritical|Crítica|Critical|critical"]],
        ];
    }
}
