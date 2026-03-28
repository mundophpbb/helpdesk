<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v220 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['mundophpbb_helpdesk_reason_enable']);
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v210'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'helpdesk_logs' => [
                    'reason_text' => ['TEXT_UNI', ''],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_columns' => [
                $this->table_prefix . 'helpdesk_logs' => [
                    'reason_text',
                ],
            ],
        ];
    }


    public function revert_data()
    {
        return [
            ['config.remove', ['mundophpbb_helpdesk_reason_enable']],
            ['config.remove', ['mundophpbb_helpdesk_activity_limit']],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_helpdesk_reason_enable', 1]],
            ['config.add', ['mundophpbb_helpdesk_activity_limit', 10]],
        ];
    }
}
