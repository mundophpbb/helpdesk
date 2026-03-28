<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v110 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['mundophpbb_helpdesk_categories_en'])
            && isset($this->config['mundophpbb_helpdesk_departments_en'])
            && isset($this->config['mundophpbb_helpdesk_department_enable']);
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v100'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'helpdesk_topics' => [
                    'category_key' => ['VCHAR:100', ''],
                    'department_key' => ['VCHAR:100', ''],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_columns' => [
                $this->table_prefix . 'helpdesk_topics' => [
                    'category_key',
                    'department_key',
                ],
            ],
        ];
    }


    public function revert_data()
    {
        return [
            ['config.remove', ['mundophpbb_helpdesk_department_enable']],
            ['config.remove', ['mundophpbb_helpdesk_categories_en']],
            ['config.remove', ['mundophpbb_helpdesk_categories_pt_br']],
            ['config.remove', ['mundophpbb_helpdesk_departments_en']],
            ['config.remove', ['mundophpbb_helpdesk_departments_pt_br']],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_helpdesk_department_enable', 1]],
            ['config.add', ['mundophpbb_helpdesk_categories_en', "technical_support|Technical Support\nsales|Sales\nbilling|Billing\ngeneral_question|General Question"]],
            ['config.add', ['mundophpbb_helpdesk_categories_pt_br', "technical_support|Suporte técnico\nsales|Vendas\nbilling|Financeiro\ngeneral_question|Dúvida geral"]],
            ['config.add', ['mundophpbb_helpdesk_departments_en', "support_team|Support Team\ncommercial_team|Commercial Team\nfinancial_team|Financial Team"]],
            ['config.add', ['mundophpbb_helpdesk_departments_pt_br', "support_team|Equipe de suporte\ncommercial_team|Equipe comercial\nfinancial_team|Equipe financeira"]],
            ['custom', [[$this, 'migrate_legacy_category_keys']]],
        ];
    }

    public function migrate_legacy_category_keys()
    {
        $sql = 'SELECT topic_id, category_label
            FROM ' . $this->table_prefix . 'helpdesk_topics';
        $result = $this->db->sql_query($sql);

        while ($row = $this->db->sql_fetchrow($result))
        {
            $label = isset($row['category_label']) ? (string) $row['category_label'] : '';
            $key = $this->slugify($label);
            if ($key === '')
            {
                continue;
            }

            $sql_ary = [
                'category_key' => $key,
            ];

            $this->db->sql_query('UPDATE ' . $this->table_prefix . 'helpdesk_topics
                SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
                WHERE topic_id = ' . (int) $row['topic_id']);
        }

        $this->db->sql_freeresult($result);
    }

    protected function slugify($value)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        return trim((string) $value, '_');
    }
}
