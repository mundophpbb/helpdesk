<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v360 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['mundophpbb_helpdesk_require_reason_assignment']);
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v350'];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_helpdesk_require_reason_status', 0]],
            ['config.add', ['mundophpbb_helpdesk_require_reason_priority', 0]],
            ['config.add', ['mundophpbb_helpdesk_require_reason_assignment', 0]],
        ];
    }

    public function revert_data()
    {
        return [
            ['config.remove', ['mundophpbb_helpdesk_require_reason_status']],
            ['config.remove', ['mundophpbb_helpdesk_require_reason_priority']],
            ['config.remove', ['mundophpbb_helpdesk_require_reason_assignment']],
        ];
    }
}
