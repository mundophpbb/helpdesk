<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v384 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return $this->table_exists($this->table_prefix . 'helpdesk_notes');
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v383'];
    }

    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'helpdesk_notes' => [
                    'COLUMNS' => [
                        'note_id' => ['UINT', null, 'auto_increment'],
                        'topic_id' => ['UINT', 0],
                        'forum_id' => ['UINT', 0],
                        'user_id' => ['UINT', 0],
                        'note_text' => ['TEXT_UNI', ''],
                        'note_time' => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => 'note_id',
                    'KEYS' => [
                        'topic_id' => ['INDEX', 'topic_id'],
                        'forum_id' => ['INDEX', 'forum_id'],
                        'note_time' => ['INDEX', 'note_time'],
                    ],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [];
    }

    public function revert_data()
    {
        return [
            ['custom', [[$this, 'safe_drop_helpdesk_notes_table']]],
            ['config.remove', ['mundophpbb_helpdesk_internal_notes_enabled']],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_helpdesk_internal_notes_enabled', 1]],
        ];
    }

    public function safe_drop_helpdesk_notes_table()
    {
        $table = $this->table_prefix . 'helpdesk_notes';

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
