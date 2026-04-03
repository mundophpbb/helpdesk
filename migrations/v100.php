<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v100 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['mundophpbb_helpdesk_enable'])
            || $this->table_exists($this->table_prefix . 'helpdesk_topics');
    }

    static public function depends_on()
    {
        return ['\\phpbb\\db\\migration\\data\\v33x\\v3314'];
    }

    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'helpdesk_topics' => [
                    'COLUMNS' => [
                        'topic_id'       => ['UINT', 0],
                        'forum_id'       => ['UINT', 0],
                        'status_key'     => ['VCHAR:30', 'open'],
                        'priority_key'   => ['VCHAR:20', 'normal'],
                        'category_label' => ['VCHAR:100', ''],
                        'created_time'   => ['TIMESTAMP', 0],
                        'updated_time'   => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => 'topic_id',
                    'KEYS' => [
                        'forum_id' => ['INDEX', 'forum_id'],
                        'status_key' => ['INDEX', 'status_key'],
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
            ['module.remove', ['acp', 'ACP_HELPDESK_TITLE', [
                'module_basename' => '\\mundophpbb\\helpdesk\\acp\\main_module',
                'modes' => ['settings'],
            ]]],
            ['module.remove', ['acp', 'ACP_CAT_DOT_MODS', 'ACP_HELPDESK_TITLE']],
            ['custom', [[$this, 'safe_drop_helpdesk_topics_table']]],
            ['config.remove', ['mundophpbb_helpdesk_enable']],
            ['config.remove', ['mundophpbb_helpdesk_forums']],
            ['config.remove', ['mundophpbb_helpdesk_prefix']],
            ['config.remove', ['mundophpbb_helpdesk_default_status']],
            ['config.remove', ['mundophpbb_helpdesk_status_enable']],
            ['config.remove', ['mundophpbb_helpdesk_priority_enable']],
            ['config.remove', ['mundophpbb_helpdesk_category_enable']],
            ['config.remove', ['mundophpbb_helpdesk_categories']],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_helpdesk_enable', 1]],
            ['config.add', ['mundophpbb_helpdesk_forums', '']],
            ['config.add', ['mundophpbb_helpdesk_prefix', '[Ticket]']],
            ['config.add', ['mundophpbb_helpdesk_default_status', 'open']],
            ['config.add', ['mundophpbb_helpdesk_status_enable', 1]],
            ['config.add', ['mundophpbb_helpdesk_priority_enable', 1]],
            ['config.add', ['mundophpbb_helpdesk_category_enable', 1]],
            ['config.add', ['mundophpbb_helpdesk_categories', 'Technical Support, Sales, Billing, General Question']],

            ['module.add', ['acp', 'ACP_CAT_DOT_MODS', 'ACP_HELPDESK_TITLE']],
            ['module.add', ['acp', 'ACP_HELPDESK_TITLE', [
                'module_basename' => '\\mundophpbb\\helpdesk\\acp\\main_module',
                'modes' => ['settings'],
            ]]],
        ];
    }

    public function safe_drop_helpdesk_topics_table()
    {
        $table = $this->table_prefix . 'helpdesk_topics';

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
