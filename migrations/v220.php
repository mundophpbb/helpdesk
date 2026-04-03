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
        return isset($this->config['mundophpbb_helpdesk_reason_enable'])
            || $this->column_exists($this->table_prefix . 'helpdesk_logs', 'reason_text');
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
        return [];
    }

    public function revert_data()
    {
        return [
            ['custom', [[$this, 'safe_remove_reason_text_column']]],
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

    public function safe_remove_reason_text_column()
    {
        $table = $this->table_prefix . 'helpdesk_logs';

        if ($this->column_exists($table, 'reason_text'))
        {
            $this->db_tools->sql_column_remove($table, 'reason_text');
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
