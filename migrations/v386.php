<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v386 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['mundophpbb_helpdesk_feedback_enable'])
            || $this->table_exists($this->table_prefix . 'helpdesk_feedback');
    }

    static public function depends_on()
    {
        return ['\\mundophpbb\\helpdesk\\migrations\\v385'];
    }

    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'helpdesk_feedback' => [
                    'COLUMNS' => [
                        'feedback_id' => ['UINT', null, 'auto_increment'],
                        'topic_id' => ['UINT', 0],
                        'forum_id' => ['UINT', 0],
                        'user_id' => ['UINT', 0],
                        'rating' => ['TINT:1', 0],
                        'comment_text' => ['TEXT_UNI', ''],
                        'submitted_time' => ['TIMESTAMP', 0],
                        'updated_time' => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => 'feedback_id',
                    'KEYS' => [
                        'topic_id' => ['UNIQUE', 'topic_id'],
                        'forum_id' => ['INDEX', 'forum_id'],
                        'user_id' => ['INDEX', 'user_id'],
                        'submitted_time' => ['INDEX', 'submitted_time'],
                    ],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_helpdesk_feedback_enable', 1]],
        ];
    }

    public function revert_data()
    {
        return [
            ['custom', [[$this, 'safe_drop_helpdesk_feedback_table']]],
            ['config.remove', ['mundophpbb_helpdesk_feedback_enable']],
        ];
    }

    public function safe_drop_helpdesk_feedback_table()
    {
        $table = $this->table_prefix . 'helpdesk_feedback';

        if ($this->table_exists($table))
        {
            $this->db_tools->sql_table_drop($table);
        }
    }

    protected function table_exists($table_name)
    {
        return $this->db_tools->sql_table_exists($table_name);
    }
}
