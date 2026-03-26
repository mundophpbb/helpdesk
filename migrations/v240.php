<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v240 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['mundophpbb_helpdesk_automation_enable']);
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v220'];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_helpdesk_automation_enable', 1]],
            ['config.add', ['mundophpbb_helpdesk_auto_lock_closed', 1]],
            ['config.add', ['mundophpbb_helpdesk_auto_unlock_reopened', 1]],
            ['config.add', ['mundophpbb_helpdesk_auto_assign_team_reply', 0]],
            ['config.add', ['mundophpbb_helpdesk_team_reply_status', 'waiting_reply']],
            ['config.add', ['mundophpbb_helpdesk_user_reply_status', 'in_progress']],
        ];
    }
}
