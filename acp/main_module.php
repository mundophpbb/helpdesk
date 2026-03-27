<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\acp;

class main_module
{
    public $u_action;
    public $tpl_name;
    public $page_title;

    public function main($id, $mode)
    {
        global $config, $request, $template, $user;

        $user->add_lang_ext('mundophpbb/helpdesk', 'common');

        $this->tpl_name = 'acp_helpdesk_body';
        $this->page_title = $user->lang('ACP_HELPDESK_SETTINGS');

        add_form_key('mundophpbb_helpdesk');

        $valid_sections = ['general', 'workflow', 'automation', 'notifications', 'lists'];
        $section = (string) $request->variable('section', 'general', true);
        if (!in_array($section, $valid_sections, true))
        {
            $section = 'general';
        }

        $status_definitions = $this->configured_status_definitions($config);
        $parsed_statuses = $this->parse_status_definitions($status_definitions);
        $status_keys = array_keys($parsed_statuses);
        $default_status = isset($config['mundophpbb_helpdesk_default_status']) ? (string) $config['mundophpbb_helpdesk_default_status'] : 'open';
        $team_reply_status = $this->configured_reply_status($config, 'mundophpbb_helpdesk_team_reply_status', $parsed_statuses, 'waiting_reply');
        $user_reply_status = $this->configured_reply_status($config, 'mundophpbb_helpdesk_user_reply_status', $parsed_statuses, 'in_progress');
        $assign_status = $this->configured_optional_status($config, 'mundophpbb_helpdesk_assign_status', $parsed_statuses);
        $unassign_status = $this->configured_optional_status($config, 'mundophpbb_helpdesk_unassign_status', $parsed_statuses);
        $department_status = $this->configured_optional_status($config, 'mundophpbb_helpdesk_department_status', $parsed_statuses);
        $email_subject_prefix = isset($config['mundophpbb_helpdesk_email_subject_prefix']) ? (string) $config['mundophpbb_helpdesk_email_subject_prefix'] : '[Help Desk]';
        $department_rule_definitions = isset($config['mundophpbb_helpdesk_department_rule_definitions']) ? (string) $config['mundophpbb_helpdesk_department_rule_definitions'] : '';
        $department_priority_rule_definitions = isset($config['mundophpbb_helpdesk_department_priority_rule_definitions']) ? (string) $config['mundophpbb_helpdesk_department_priority_rule_definitions'] : '';
        $department_sla_definitions = isset($config['mundophpbb_helpdesk_department_sla_definitions']) ? (string) $config['mundophpbb_helpdesk_department_sla_definitions'] : '';
        $priority_sla_definitions = isset($config['mundophpbb_helpdesk_priority_sla_definitions']) ? (string) $config['mundophpbb_helpdesk_priority_sla_definitions'] : '';
        $department_priority_sla_definitions = isset($config['mundophpbb_helpdesk_department_priority_sla_definitions']) ? (string) $config['mundophpbb_helpdesk_department_priority_sla_definitions'] : '';
        $department_priority_queue_definitions = isset($config['mundophpbb_helpdesk_department_priority_queue_definitions']) ? (string) $config['mundophpbb_helpdesk_department_priority_queue_definitions'] : '';
        $assignee_queue_definitions = isset($config['mundophpbb_helpdesk_assignee_queue_definitions']) ? (string) $config['mundophpbb_helpdesk_assignee_queue_definitions'] : '';
        $priority_definitions = $this->configured_priority_definitions($config);
        $priority_high_status = $this->configured_optional_status($config, 'mundophpbb_helpdesk_priority_high_status', $parsed_statuses);
        $priority_critical_status = $this->configured_optional_status($config, 'mundophpbb_helpdesk_priority_critical_status', $parsed_statuses);

        if ($request->is_set_post('submit'))
        {
            if (!check_form_key('mundophpbb_helpdesk'))
            {
                trigger_error('FORM_INVALID');
            }

            $forums = preg_replace('/[^0-9,\s]/', '', $request->variable('mundophpbb_helpdesk_forums', '', true));
            $prefix = trim($request->variable('mundophpbb_helpdesk_prefix', '', true));
            $status_definitions = trim($request->variable('mundophpbb_helpdesk_status_definitions', '', true));
            $parsed_statuses = $this->parse_status_definitions($status_definitions);

            if (empty($parsed_statuses))
            {
                $status_definitions = $this->default_status_definitions();
                $parsed_statuses = $this->parse_status_definitions($status_definitions);
            }

            $default_status = trim($request->variable('mundophpbb_helpdesk_default_status', 'open', true));
            if (!array_key_exists($default_status, $parsed_statuses))
            {
                $default_status = $this->first_status_key($parsed_statuses, 'open');
            }

            $config->set('mundophpbb_helpdesk_enable', $request->variable('mundophpbb_helpdesk_enable', 0));
            $config->set('mundophpbb_helpdesk_forums', trim($forums));
            $config->set('mundophpbb_helpdesk_prefix', $prefix);
            $config->set('mundophpbb_helpdesk_status_definitions', $status_definitions);
            $config->set('mundophpbb_helpdesk_default_status', $default_status);
            $config->set('mundophpbb_helpdesk_status_enable', $request->variable('mundophpbb_helpdesk_status_enable', 1));
            $config->set('mundophpbb_helpdesk_priority_enable', $request->variable('mundophpbb_helpdesk_priority_enable', 1));
            $config->set('mundophpbb_helpdesk_category_enable', $request->variable('mundophpbb_helpdesk_category_enable', 1));
            $config->set('mundophpbb_helpdesk_department_enable', $request->variable('mundophpbb_helpdesk_department_enable', 1));
            $config->set('mundophpbb_helpdesk_assignment_enable', $request->variable('mundophpbb_helpdesk_assignment_enable', 1));
            $config->set('mundophpbb_helpdesk_team_panel_enable', $request->variable('mundophpbb_helpdesk_team_panel_enable', 1));
            $config->set('mundophpbb_helpdesk_alerts_enable', $request->variable('mundophpbb_helpdesk_alerts_enable', 1));
            $config->set('mundophpbb_helpdesk_alert_hours', max(1, (int) $request->variable('mundophpbb_helpdesk_alert_hours', 24)));
            $config->set('mundophpbb_helpdesk_alert_limit', max(1, (int) $request->variable('mundophpbb_helpdesk_alert_limit', 15)));
            $config->set('mundophpbb_helpdesk_sla_enable', $request->variable('mundophpbb_helpdesk_sla_enable', 1));
            $config->set('mundophpbb_helpdesk_sla_hours', max(1, (int) $request->variable('mundophpbb_helpdesk_sla_hours', 24)));
            $config->set('mundophpbb_helpdesk_stale_hours', max(1, (int) $request->variable('mundophpbb_helpdesk_stale_hours', 72)));
            $config->set('mundophpbb_helpdesk_old_hours', max(1, (int) $request->variable('mundophpbb_helpdesk_old_hours', 168)));
            $config->set('mundophpbb_helpdesk_automation_enable', $request->variable('mundophpbb_helpdesk_automation_enable', 1));
            $config->set('mundophpbb_helpdesk_auto_lock_closed', $request->variable('mundophpbb_helpdesk_auto_lock_closed', 1));
            $config->set('mundophpbb_helpdesk_auto_unlock_reopened', $request->variable('mundophpbb_helpdesk_auto_unlock_reopened', 1));
            $config->set('mundophpbb_helpdesk_auto_assign_team_reply', $request->variable('mundophpbb_helpdesk_auto_assign_team_reply', 0));
            $team_reply_status = trim($request->variable('mundophpbb_helpdesk_team_reply_status', '', true));
            if ($team_reply_status !== '' && !array_key_exists($team_reply_status, $parsed_statuses))
            {
                $team_reply_status = '';
            }
            $user_reply_status = trim($request->variable('mundophpbb_helpdesk_user_reply_status', '', true));
            if ($user_reply_status !== '' && !array_key_exists($user_reply_status, $parsed_statuses))
            {
                $user_reply_status = '';
            }
            $assign_status = trim($request->variable('mundophpbb_helpdesk_assign_status', '', true));
            if ($assign_status !== '' && !array_key_exists($assign_status, $parsed_statuses))
            {
                $assign_status = '';
            }
            $unassign_status = trim($request->variable('mundophpbb_helpdesk_unassign_status', '', true));
            if ($unassign_status !== '' && !array_key_exists($unassign_status, $parsed_statuses))
            {
                $unassign_status = '';
            }
            $department_status = trim($request->variable('mundophpbb_helpdesk_department_status', '', true));
            if ($department_status !== '' && !array_key_exists($department_status, $parsed_statuses))
            {
                $department_status = '';
            }
            $priority_high_status = trim($request->variable('mundophpbb_helpdesk_priority_high_status', '', true));
            if ($priority_high_status !== '' && !array_key_exists($priority_high_status, $parsed_statuses))
            {
                $priority_high_status = '';
            }
            $priority_critical_status = trim($request->variable('mundophpbb_helpdesk_priority_critical_status', '', true));
            if ($priority_critical_status !== '' && !array_key_exists($priority_critical_status, $parsed_statuses))
            {
                $priority_critical_status = '';
            }
            $config->set('mundophpbb_helpdesk_team_reply_status', $team_reply_status);
            $config->set('mundophpbb_helpdesk_user_reply_status', $user_reply_status);
            $config->set('mundophpbb_helpdesk_assign_status', $assign_status);
            $config->set('mundophpbb_helpdesk_unassign_status', $unassign_status);
            $config->set('mundophpbb_helpdesk_department_status', $department_status);
            $config->set('mundophpbb_helpdesk_priority_high_status', $priority_high_status);
            $config->set('mundophpbb_helpdesk_priority_critical_status', $priority_critical_status);
            $config->set('mundophpbb_helpdesk_email_notify_enable', $request->variable('mundophpbb_helpdesk_email_notify_enable', 0));
            $config->set('mundophpbb_helpdesk_email_notify_author', $request->variable('mundophpbb_helpdesk_email_notify_author', 1));
            $config->set('mundophpbb_helpdesk_email_notify_assignee', $request->variable('mundophpbb_helpdesk_email_notify_assignee', 1));
            $config->set('mundophpbb_helpdesk_email_notify_user_reply', $request->variable('mundophpbb_helpdesk_email_notify_user_reply', 1));
            $config->set('mundophpbb_helpdesk_email_subject_prefix', trim($request->variable('mundophpbb_helpdesk_email_subject_prefix', '[Help Desk]', true)));
            $config->set('mundophpbb_helpdesk_department_rule_definitions', trim($request->variable('mundophpbb_helpdesk_department_rule_definitions', '', true)));
            $config->set('mundophpbb_helpdesk_department_priority_rule_definitions', trim($request->variable('mundophpbb_helpdesk_department_priority_rule_definitions', '', true)));
            $config->set('mundophpbb_helpdesk_department_sla_definitions', trim($request->variable('mundophpbb_helpdesk_department_sla_definitions', '', true)));
            $config->set('mundophpbb_helpdesk_priority_sla_definitions', trim($request->variable('mundophpbb_helpdesk_priority_sla_definitions', '', true)));
            $config->set('mundophpbb_helpdesk_department_priority_sla_definitions', trim($request->variable('mundophpbb_helpdesk_department_priority_sla_definitions', '', true)));
            $config->set('mundophpbb_helpdesk_department_priority_queue_definitions', trim($request->variable('mundophpbb_helpdesk_department_priority_queue_definitions', '', true)));
            $config->set('mundophpbb_helpdesk_assignee_queue_definitions', trim($request->variable('mundophpbb_helpdesk_assignee_queue_definitions', '', true)));
            $config->set('mundophpbb_helpdesk_priority_definitions', trim($request->variable('mundophpbb_helpdesk_priority_definitions', '', true)));
            $config->set('mundophpbb_helpdesk_categories', trim($request->variable('mundophpbb_helpdesk_categories', '', true)));
            $config->set('mundophpbb_helpdesk_departments', trim($request->variable('mundophpbb_helpdesk_departments', '', true)));

            trigger_error($user->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
        }

        foreach ($this->parse_status_definitions($status_definitions) as $key => $definition)
        {
            $label = strtolower($user->lang_name) === 'pt_br'
                ? $definition['label_pt_br']
                : $definition['label_en'];

            $template->assign_block_vars('helpdesk_default_status_options', [
                'VALUE' => $key,
                'LABEL' => $label,
                'S_SELECTED' => $default_status === $key,
            ]);
            $template->assign_block_vars('helpdesk_team_reply_status_options', [
                'VALUE' => $key,
                'LABEL' => $label,
                'S_SELECTED' => $team_reply_status === $key,
            ]);
            $template->assign_block_vars('helpdesk_user_reply_status_options', [
                'VALUE' => $key,
                'LABEL' => $label,
                'S_SELECTED' => $user_reply_status === $key,
            ]);
            $template->assign_block_vars('helpdesk_assign_status_options', [
                'VALUE' => $key,
                'LABEL' => $label,
                'S_SELECTED' => $assign_status === $key,
            ]);
            $template->assign_block_vars('helpdesk_unassign_status_options', [
                'VALUE' => $key,
                'LABEL' => $label,
                'S_SELECTED' => $unassign_status === $key,
            ]);
            $template->assign_block_vars('helpdesk_department_status_options', [
                'VALUE' => $key,
                'LABEL' => $label,
                'S_SELECTED' => $department_status === $key,
            ]);
            $template->assign_block_vars('helpdesk_priority_high_status_options', [
                'VALUE' => $key,
                'LABEL' => $label,
                'S_SELECTED' => $priority_high_status === $key,
            ]);
            $template->assign_block_vars('helpdesk_priority_critical_status_options', [
                'VALUE' => $key,
                'LABEL' => $label,
                'S_SELECTED' => $priority_critical_status === $key,
            ]);
        }

        $template->assign_vars([
            'U_ACTION' => $this->u_action,
            'HELPDESK_ENABLE' => !empty($config['mundophpbb_helpdesk_enable']),
            'HELPDESK_FORUMS' => isset($config['mundophpbb_helpdesk_forums']) ? (string) $config['mundophpbb_helpdesk_forums'] : '',
            'HELPDESK_PREFIX' => isset($config['mundophpbb_helpdesk_prefix']) ? (string) $config['mundophpbb_helpdesk_prefix'] : '[Ticket]',
            'HELPDESK_DEFAULT_STATUS' => $default_status,
            'HELPDESK_STATUS_ENABLE' => !empty($config['mundophpbb_helpdesk_status_enable']),
            'HELPDESK_PRIORITY_ENABLE' => !empty($config['mundophpbb_helpdesk_priority_enable']),
            'HELPDESK_CATEGORY_ENABLE' => !empty($config['mundophpbb_helpdesk_category_enable']),
            'HELPDESK_DEPARTMENT_ENABLE' => !empty($config['mundophpbb_helpdesk_department_enable']),
            'HELPDESK_ASSIGNMENT_ENABLE' => !empty($config['mundophpbb_helpdesk_assignment_enable']),
            'HELPDESK_TEAM_PANEL_ENABLE' => !empty($config['mundophpbb_helpdesk_team_panel_enable']),
            'HELPDESK_ALERTS_ENABLE' => !empty($config['mundophpbb_helpdesk_alerts_enable']),
            'HELPDESK_ALERT_HOURS' => isset($config['mundophpbb_helpdesk_alert_hours']) ? (int) $config['mundophpbb_helpdesk_alert_hours'] : 24,
            'HELPDESK_ALERT_LIMIT' => isset($config['mundophpbb_helpdesk_alert_limit']) ? (int) $config['mundophpbb_helpdesk_alert_limit'] : 15,
            'HELPDESK_SLA_ENABLE' => !empty($config['mundophpbb_helpdesk_sla_enable']),
            'HELPDESK_SLA_HOURS' => isset($config['mundophpbb_helpdesk_sla_hours']) ? (int) $config['mundophpbb_helpdesk_sla_hours'] : 24,
            'HELPDESK_STALE_HOURS' => isset($config['mundophpbb_helpdesk_stale_hours']) ? (int) $config['mundophpbb_helpdesk_stale_hours'] : 72,
            'HELPDESK_OLD_HOURS' => isset($config['mundophpbb_helpdesk_old_hours']) ? (int) $config['mundophpbb_helpdesk_old_hours'] : 168,
            'HELPDESK_AUTOMATION_ENABLE' => !empty($config['mundophpbb_helpdesk_automation_enable']),
            'HELPDESK_AUTO_LOCK_CLOSED' => !empty($config['mundophpbb_helpdesk_auto_lock_closed']),
            'HELPDESK_AUTO_UNLOCK_REOPENED' => !empty($config['mundophpbb_helpdesk_auto_unlock_reopened']),
            'HELPDESK_AUTO_ASSIGN_TEAM_REPLY' => !empty($config['mundophpbb_helpdesk_auto_assign_team_reply']),
            'HELPDESK_TEAM_REPLY_STATUS' => $team_reply_status,
            'HELPDESK_USER_REPLY_STATUS' => $user_reply_status,
            'HELPDESK_ASSIGN_STATUS' => $assign_status,
            'HELPDESK_UNASSIGN_STATUS' => $unassign_status,
            'HELPDESK_DEPARTMENT_STATUS' => $department_status,
            'HELPDESK_PRIORITY_HIGH_STATUS' => $priority_high_status,
            'HELPDESK_PRIORITY_CRITICAL_STATUS' => $priority_critical_status,
            'HELPDESK_EMAIL_NOTIFY_ENABLE' => !empty($config['mundophpbb_helpdesk_email_notify_enable']),
            'HELPDESK_EMAIL_NOTIFY_AUTHOR' => !empty($config['mundophpbb_helpdesk_email_notify_author']),
            'HELPDESK_EMAIL_NOTIFY_ASSIGNEE' => !empty($config['mundophpbb_helpdesk_email_notify_assignee']),
            'HELPDESK_EMAIL_NOTIFY_USER_REPLY' => !empty($config['mundophpbb_helpdesk_email_notify_user_reply']),
            'HELPDESK_EMAIL_SUBJECT_PREFIX' => $email_subject_prefix,
            'HELPDESK_DEPARTMENT_RULE_DEFINITIONS' => $department_rule_definitions,
            'HELPDESK_DEPARTMENT_PRIORITY_RULE_DEFINITIONS' => $department_priority_rule_definitions,
            'HELPDESK_DEPARTMENT_SLA_DEFINITIONS' => $department_sla_definitions,
            'HELPDESK_PRIORITY_SLA_DEFINITIONS' => $priority_sla_definitions,
            'HELPDESK_DEPARTMENT_PRIORITY_SLA_DEFINITIONS' => $department_priority_sla_definitions,
            'HELPDESK_DEPARTMENT_PRIORITY_QUEUE_DEFINITIONS' => $department_priority_queue_definitions,
            'HELPDESK_ASSIGNEE_QUEUE_DEFINITIONS' => $assignee_queue_definitions,
            'HELPDESK_STATUS_DEFINITIONS' => $status_definitions,
            'HELPDESK_PRIORITY_DEFINITIONS' => $priority_definitions,
            'HELPDESK_CATEGORIES' => $this->configured_list($config, 'mundophpbb_helpdesk_categories', [
                'mundophpbb_helpdesk_categories_pt_br',
                'mundophpbb_helpdesk_categories_en',
            ], $this->default_categories()),
            'HELPDESK_DEPARTMENTS' => $this->configured_list($config, 'mundophpbb_helpdesk_departments', [
                'mundophpbb_helpdesk_departments_pt_br',
                'mundophpbb_helpdesk_departments_en',
            ], $this->default_departments()),
            'HELPDESK_ACP_SECTION' => $section,
            'S_HELPDESK_ACP_GENERAL' => ($section === 'general'),
            'S_HELPDESK_ACP_WORKFLOW' => ($section === 'workflow'),
            'S_HELPDESK_ACP_AUTOMATION' => ($section === 'automation'),
            'S_HELPDESK_ACP_NOTIFICATIONS' => ($section === 'notifications'),
            'S_HELPDESK_ACP_LISTS' => ($section === 'lists'),
            'U_HELPDESK_ACP_GENERAL' => $this->u_action . '&amp;section=general',
            'U_HELPDESK_ACP_WORKFLOW' => $this->u_action . '&amp;section=workflow',
            'U_HELPDESK_ACP_AUTOMATION' => $this->u_action . '&amp;section=automation',
            'U_HELPDESK_ACP_NOTIFICATIONS' => $this->u_action . '&amp;section=notifications',
            'U_HELPDESK_ACP_LISTS' => $this->u_action . '&amp;section=lists',
        ]);
    }

    protected function configured_priority_definitions($config)
    {
        if (isset($config['mundophpbb_helpdesk_priority_definitions']) && trim((string) $config['mundophpbb_helpdesk_priority_definitions']) !== '')
        {
            return (string) $config['mundophpbb_helpdesk_priority_definitions'];
        }

        return $this->default_priority_definitions();
    }

    protected function configured_list($config, $primary_key, array $fallback_keys, $default_value)
    {
        if (isset($config[$primary_key]) && trim((string) $config[$primary_key]) !== '')
        {
            return (string) $config[$primary_key];
        }

        foreach ($fallback_keys as $fallback_key)
        {
            if (isset($config[$fallback_key]) && trim((string) $config[$fallback_key]) !== '')
            {
                return (string) $config[$fallback_key];
            }
        }

        return $default_value;
    }

    protected function configured_status_definitions($config)
    {
        if (isset($config['mundophpbb_helpdesk_status_definitions']) && trim((string) $config['mundophpbb_helpdesk_status_definitions']) !== '')
        {
            return (string) $config['mundophpbb_helpdesk_status_definitions'];
        }

        return $this->default_status_definitions();
    }

    protected function parse_status_definitions($raw)
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
        $definitions = [];

        foreach ($lines as $line)
        {
            $line = trim((string) $line);
            if ($line === '')
            {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            $key = isset($parts[0]) ? $this->slugify($parts[0]) : '';
            if ($key === '')
            {
                continue;
            }

            $label_pt_br = '';
            $label_en = '';
            $tone = $this->normalize_status_tone(isset($parts[3]) ? $parts[3] : '');

            if (count($parts) >= 4)
            {
                $label_pt_br = $parts[1];
                $label_en = $parts[2];
            }
            else if (count($parts) === 3)
            {
                $label_pt_br = $parts[1];
                $label_en = $parts[2];
            }
            else if (count($parts) === 2)
            {
                $label_pt_br = $parts[1];
                $label_en = $parts[1];
            }
            else
            {
                $label_pt_br = $parts[0];
                $label_en = $parts[0];
            }

            if ($label_pt_br === '')
            {
                $label_pt_br = $label_en !== '' ? $label_en : $key;
            }

            if ($label_en === '')
            {
                $label_en = $label_pt_br;
            }

            if ($tone === '')
            {
                $tone = $this->normalize_status_tone($key);
            }

            $definitions[$key] = [
                'label_pt_br' => $label_pt_br,
                'label_en' => $label_en,
                'tone' => $tone !== '' ? $tone : 'open',
            ];
        }

        return $definitions;
    }

    protected function first_status_key(array $definitions, $fallback)
    {
        if (array_key_exists($fallback, $definitions))
        {
            return $fallback;
        }

        $keys = array_keys($definitions);
        return !empty($keys) ? (string) $keys[0] : $fallback;
    }

    protected function configured_reply_status($config, $config_key, array $definitions, $preferred)
    {
        if (isset($config[$config_key]))
        {
            $configured = trim((string) $config[$config_key]);
            if ($configured === '' || array_key_exists($configured, $definitions))
            {
                return $configured;
            }
        }

        if (array_key_exists($preferred, $definitions))
        {
            return $preferred;
        }

        if ($preferred !== 'open' && array_key_exists('open', $definitions))
        {
            return 'open';
        }

        return '';
    }

    protected function configured_optional_status($config, $config_key, array $definitions)
    {
        if (!isset($config[$config_key]))
        {
            return '';
        }

        $configured = trim((string) $config[$config_key]);
        if ($configured === '' || array_key_exists($configured, $definitions))
        {
            return $configured;
        }

        return '';
    }

    protected function normalize_status_tone($value)
    {
        $value = $this->slugify($value);
        $map = [
            'in_progress' => 'progress',
            'progress' => 'progress',
            'waiting_reply' => 'waiting',
            'waiting' => 'waiting',
            'resolved' => 'resolved',
            'closed' => 'closed',
            'open' => 'open',
        ];

        return isset($map[$value]) ? $map[$value] : '';
    }

    protected function slugify($value)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        return trim((string) $value, '_');
    }

    protected function default_status_definitions()
    {
        return implode("\n", [
            'open|Aberto|Open|open',
            'in_progress|Em andamento|In progress|progress',
            'waiting_reply|Aguardando retorno|Waiting for reply|waiting',
            'resolved|Resolvido|Resolved|resolved',
            'closed|Fechado|Closed|closed',
        ]);
    }

    protected function default_priority_definitions()
    {
        return implode("\n", [
            'low|Baixa|Low|low',
            'normal|Normal|Normal|normal',
            'high|Alta|High|high',
            'critical|Crítica|Critical|critical',
        ]);
    }

    protected function default_categories()
    {
        return implode("\n", [
            'technical_support|Technical Support',
            'sales|Sales',
            'billing|Billing',
            'general_question|General Question',
        ]);
    }

    protected function default_departments()
    {
        return implode("\n", [
            'support_team|Support Team',
            'commercial_team|Commercial Team',
            'financial_team|Financial Team',
        ]);
    }
}
