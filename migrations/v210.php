<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v210 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['mundophpbb_helpdesk_team_panel_enable']);
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v190'];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_helpdesk_team_panel_enable', 1]],
            ['config.add', ['mundophpbb_helpdesk_alerts_enable', 1]],
            ['config.add', ['mundophpbb_helpdesk_alert_hours', 24]],
            ['config.add', ['mundophpbb_helpdesk_alert_limit', 15]],

            ['permission.add', ['m_helpdesk_manage']],
            ['permission.add', ['m_helpdesk_assign']],
            ['permission.add', ['m_helpdesk_bulk']],
            ['permission.add', ['m_helpdesk_queue']],

            ['permission.permission_set', ['ROLE_MOD_FULL', ['m_helpdesk_manage', 'm_helpdesk_assign', 'm_helpdesk_bulk', 'm_helpdesk_queue']]],
        ];
    }
}
