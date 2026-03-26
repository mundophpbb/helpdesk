<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v260 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['mundophpbb_helpdesk_email_notify_enable'])
            && isset($this->config['mundophpbb_helpdesk_email_notify_author'])
            && isset($this->config['mundophpbb_helpdesk_email_notify_assignee'])
            && isset($this->config['mundophpbb_helpdesk_email_notify_user_reply'])
            && isset($this->config['mundophpbb_helpdesk_email_subject_prefix']);
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v250'];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_helpdesk_email_notify_enable', 0]],
            ['config.add', ['mundophpbb_helpdesk_email_notify_author', 1]],
            ['config.add', ['mundophpbb_helpdesk_email_notify_assignee', 1]],
            ['config.add', ['mundophpbb_helpdesk_email_notify_user_reply', 1]],
            ['config.add', ['mundophpbb_helpdesk_email_subject_prefix', '[Help Desk]']],
        ];
    }
}
