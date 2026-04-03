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
        return isset($this->config['mundophpbb_helpdesk_assignment_enable'])
            || (
                $this->column_exists($this->table_prefix . 'helpdesk_topics', 'assigned_to')
                && $this->column_exists($this->table_prefix . 'helpdesk_topics', 'assigned_time')
            );
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
        return [];
    }

    public function revert_data()
    {
        return [
            ['custom', [[$this, 'safe_remove_assignment_columns']]],
            ['config.remove', ['mundophpbb_helpdesk_assignment_enable']],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_helpdesk_assignment_enable', 1]],
        ];
    }

    public function safe_remove_assignment_columns()
    {
        $table = $this->table_prefix . 'helpdesk_topics';

        if (!$this->table_exists($table))
        {
            return;
        }

        if ($this->db_tools->sql_column_exists($table, 'assigned_to'))
        {
            $this->db_tools->sql_column_remove($table, 'assigned_to');
        }

        if ($this->db_tools->sql_column_exists($table, 'assigned_time'))
        {
            $this->db_tools->sql_column_remove($table, 'assigned_time');
        }
    }

    protected function table_exists($table_name)
    {
        return $this->db_tools->sql_table_exists($table_name);
    }

    protected function column_exists($table_name, $column_name)
    {
        return $this->table_exists($table_name) && $this->db_tools->sql_column_exists($table_name, $column_name);
    }
}
