<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\migrations;

class v111 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['mundophpbb_helpdesk_categories'])
            && isset($this->config['mundophpbb_helpdesk_departments']);
    }

    static public function depends_on()
    {
        return ['\mundophpbb\helpdesk\migrations\v110'];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_helpdesk_categories', '']],
            ['config.add', ['mundophpbb_helpdesk_departments', '']],
            ['custom', [[$this, 'migrate_shared_lists']]],
        ];
    }

    public function migrate_shared_lists()
    {
        $categories = isset($this->config['mundophpbb_helpdesk_categories']) ? trim((string) $this->config['mundophpbb_helpdesk_categories']) : '';
        if ($categories === '')
        {
            $categories = $this->first_non_empty([
                'mundophpbb_helpdesk_categories_pt_br',
                'mundophpbb_helpdesk_categories_en',
                'mundophpbb_helpdesk_categories',
            ]);

            if ($categories !== '')
            {
                $this->config->set('mundophpbb_helpdesk_categories', $categories);
            }
        }

        $departments = isset($this->config['mundophpbb_helpdesk_departments']) ? trim((string) $this->config['mundophpbb_helpdesk_departments']) : '';
        if ($departments === '')
        {
            $departments = $this->first_non_empty([
                'mundophpbb_helpdesk_departments_pt_br',
                'mundophpbb_helpdesk_departments_en',
            ]);

            if ($departments !== '')
            {
                $this->config->set('mundophpbb_helpdesk_departments', $departments);
            }
        }
    }

    protected function first_non_empty(array $keys)
    {
        foreach ($keys as $key)
        {
            if (isset($this->config[$key]))
            {
                $value = trim((string) $this->config[$key]);
                if ($value !== '')
                {
                    return $value;
                }
            }
        }

        return '';
    }
}
