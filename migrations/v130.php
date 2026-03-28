<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v130 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['mundophpbb_helpdesk_assignment_enable']);
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v120'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'helpdesk_topics' => [
                    'assigned_to' => ['VCHAR:255', ''],
                    'assigned_time' => ['TIMESTAMP', 0],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_columns' => [
                $this->table_prefix . 'helpdesk_topics' => [
                    'assigned_to',
                    'assigned_time',
                ],
            ],
        ];
    }


    public function revert_data()
    {
        return [
            ['config.remove', ['mundophpbb_helpdesk_assignment_enable']],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_helpdesk_assignment_enable', 1]],
        ];
    }
}
