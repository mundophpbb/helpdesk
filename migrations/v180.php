<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v180 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['mundophpbb_helpdesk_sla_enable']);
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v130'];
    }


    public function revert_data()
    {
        return [
            ['config.remove', ['mundophpbb_helpdesk_sla_enable']],
            ['config.remove', ['mundophpbb_helpdesk_sla_hours']],
            ['config.remove', ['mundophpbb_helpdesk_stale_hours']],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_helpdesk_sla_enable', 1]],
            ['config.add', ['mundophpbb_helpdesk_sla_hours', 24]],
            ['config.add', ['mundophpbb_helpdesk_stale_hours', 72]],
        ];
    }
}
