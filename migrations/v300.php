<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v300 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['mundophpbb_helpdesk_priority_high_status'])
            && isset($this->config['mundophpbb_helpdesk_priority_critical_status']);
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v290'];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_helpdesk_priority_high_status', '']],
            ['config.add', ['mundophpbb_helpdesk_priority_critical_status', '']],
        ];
    }
}
