<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v120 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['mundophpbb_helpdesk_status_definitions']);
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v111'];
    }

    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'helpdesk_logs' => [
                    'COLUMNS' => [
                        'log_id' => ['UINT', null, 'auto_increment'],
                        'topic_id' => ['UINT', 0],
                        'forum_id' => ['UINT', 0],
                        'user_id' => ['UINT', 0],
                        'action_key' => ['VCHAR:30', ''],
                        'old_value' => ['VCHAR:100', ''],
                        'new_value' => ['VCHAR:100', ''],
                        'log_time' => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => 'log_id',
                    'KEYS' => [
                        'topic_id' => ['INDEX', 'topic_id'],
                        'forum_id' => ['INDEX', 'forum_id'],
                        'log_time' => ['INDEX', 'log_time'],
                    ],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_tables' => [
                $this->table_prefix . 'helpdesk_logs',
            ],
        ];
    }


    public function revert_data()
    {
        return [
            ['config.remove', ['mundophpbb_helpdesk_status_definitions']],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_helpdesk_status_definitions', "open|Aberto|Open|open\nin_progress|Em andamento|In progress|progress\nwaiting_reply|Aguardando retorno|Waiting for reply|waiting\nresolved|Resolvido|Resolved|resolved\nclosed|Fechado|Closed|closed"]],
        ];
    }
}
