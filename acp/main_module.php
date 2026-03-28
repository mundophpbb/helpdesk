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
        $parsed_priorities = $this->parse_priority_definitions($priority_definitions);
        $department_definitions = $this->configured_list($config, 'mundophpbb_helpdesk_departments', [
            'mundophpbb_helpdesk_departments_pt_br',
            'mundophpbb_helpdesk_departments_en',
        ], $this->default_departments());
        $parsed_departments = $this->parse_keyed_list_definitions($department_definitions);
        $priority_high_status = $this->configured_optional_status($config, 'mundophpbb_helpdesk_priority_high_status', $parsed_statuses);
        $priority_critical_status = $this->configured_optional_status($config, 'mundophpbb_helpdesk_priority_critical_status', $parsed_statuses);
        $category_definitions = $this->configured_list($config, 'mundophpbb_helpdesk_categories', [
            'mundophpbb_helpdesk_categories_pt_br',
            'mundophpbb_helpdesk_categories_en',
        ], $this->default_categories());
        $parsed_categories = $this->parse_keyed_list_definitions($category_definitions);

        if ($request->is_set_post('restore_defaults'))
        {
            if (!check_form_key('mundophpbb_helpdesk'))
            {
                trigger_error('FORM_INVALID');
            }

            $restore_messages = $this->restore_defaults_for_section($config, $section);
            $success_message = $user->lang('ACP_HELPDESK_RESTORE_DEFAULTS_DONE');
            if (!empty($restore_messages))
            {
                $success_message .= '<br /><br />' . implode('<br />', $restore_messages);
            }

            trigger_error($success_message . adm_back_link($this->u_action . '&section=' . $section));
        }

        if ($request->is_set_post('submit'))
        {
            if (!check_form_key('mundophpbb_helpdesk'))
            {
                trigger_error('FORM_INVALID');
            }

            $errors = [];
            $messages = [];

            if ($section === 'general')
            {
                $forums_raw = trim($request->variable('mundophpbb_helpdesk_forums', '', true));
                $prefix = trim($request->variable('mundophpbb_helpdesk_prefix', '', true));
                $forums = $this->sanitize_forum_ids($forums_raw);

                if ($forums_raw !== '' && !preg_match('/^[0-9,\s]+$/', $forums_raw))
                {
                    $errors[] = $user->lang('ACP_HELPDESK_VALIDATION_FORUMS_INVALID');
                }

                $prefix_length = function_exists('utf8_strlen') ? utf8_strlen($prefix) : strlen($prefix);
                if ($prefix_length > 80)
                {
                    $errors[] = $user->lang('ACP_HELPDESK_VALIDATION_PREFIX_TOO_LONG');
                }

                if (!empty($errors))
                {
                    trigger_error(implode('<br />', $errors) . adm_back_link($this->u_action . '&section=' . $section), E_USER_WARNING);
                }

                $config->set('mundophpbb_helpdesk_enable', $request->variable('mundophpbb_helpdesk_enable', 0));
                $config->set('mundophpbb_helpdesk_forums', $forums);
                $config->set('mundophpbb_helpdesk_prefix', $prefix);
            }
            else if ($section === 'workflow')
            {
                $default_status = trim($request->variable('mundophpbb_helpdesk_default_status', 'open', true));
                if (!array_key_exists($default_status, $parsed_statuses))
                {
                    $default_status = $this->first_status_key($parsed_statuses, 'open');
                }

                $team_panel_enable = $request->variable('mundophpbb_helpdesk_team_panel_enable', 1);
                $alerts_enable = $request->variable('mundophpbb_helpdesk_alerts_enable', 1);
                $alert_hours = max(1, (int) $request->variable('mundophpbb_helpdesk_alert_hours', 24));
                $alert_limit = max(1, (int) $request->variable('mundophpbb_helpdesk_alert_limit', 15));
                $sla_hours = max(1, (int) $request->variable('mundophpbb_helpdesk_sla_hours', 24));
                $stale_hours = max(1, (int) $request->variable('mundophpbb_helpdesk_stale_hours', 72));
                $old_hours = max(1, (int) $request->variable('mundophpbb_helpdesk_old_hours', 168));

                if ($old_hours < $sla_hours)
                {
                    $errors[] = $user->lang('ACP_HELPDESK_VALIDATION_OLD_HOURS_TOO_SMALL');
                }

                if ((int) $alerts_enable === 1 && (int) $team_panel_enable !== 1)
                {
                    $errors[] = $user->lang('ACP_HELPDESK_VALIDATION_ALERTS_REQUIRE_TEAM_PANEL');
                }

                if (!empty($errors))
                {
                    trigger_error(implode('<br />', $errors) . adm_back_link($this->u_action . '&section=' . $section), E_USER_WARNING);
                }

                $config->set('mundophpbb_helpdesk_default_status', $default_status);
                $config->set('mundophpbb_helpdesk_status_enable', $request->variable('mundophpbb_helpdesk_status_enable', 1));
                $config->set('mundophpbb_helpdesk_priority_enable', $request->variable('mundophpbb_helpdesk_priority_enable', 1));
                $config->set('mundophpbb_helpdesk_category_enable', $request->variable('mundophpbb_helpdesk_category_enable', 1));
                $config->set('mundophpbb_helpdesk_department_enable', $request->variable('mundophpbb_helpdesk_department_enable', 1));
                $config->set('mundophpbb_helpdesk_assignment_enable', $request->variable('mundophpbb_helpdesk_assignment_enable', 1));
                $config->set('mundophpbb_helpdesk_team_panel_enable', $team_panel_enable);
                $config->set('mundophpbb_helpdesk_alerts_enable', $alerts_enable);
                $config->set('mundophpbb_helpdesk_alert_hours', $alert_hours);
                $config->set('mundophpbb_helpdesk_alert_limit', $alert_limit);
                $config->set('mundophpbb_helpdesk_sla_enable', $request->variable('mundophpbb_helpdesk_sla_enable', 1));
                $config->set('mundophpbb_helpdesk_sla_hours', $sla_hours);
                $config->set('mundophpbb_helpdesk_stale_hours', $stale_hours);
                $config->set('mundophpbb_helpdesk_old_hours', $old_hours);
            }
            else if ($section === 'automation')
            {
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

                $removed = 0;
                $department_rule_definitions = $this->sanitize_department_rule_definitions(trim($request->variable('mundophpbb_helpdesk_department_rule_definitions', '', true)), $parsed_departments, $parsed_statuses, $removed);
                if ($removed > 0)
                {
                    $messages[] = $user->lang('ACP_HELPDESK_VALIDATION_LINES_IGNORED', $user->lang('ACP_HELPDESK_DEPARTMENT_RULES'), $removed);
                }

                $removed = 0;
                $department_priority_rule_definitions = $this->sanitize_department_priority_rule_definitions(trim($request->variable('mundophpbb_helpdesk_department_priority_rule_definitions', '', true)), $parsed_departments, $parsed_priorities, $parsed_statuses, $removed);
                if ($removed > 0)
                {
                    $messages[] = $user->lang('ACP_HELPDESK_VALIDATION_LINES_IGNORED', $user->lang('ACP_HELPDESK_DEPARTMENT_PRIORITY_RULES'), $removed);
                }

                $removed = 0;
                $department_sla_definitions = $this->sanitize_department_sla_definitions(trim($request->variable('mundophpbb_helpdesk_department_sla_definitions', '', true)), $parsed_departments, $removed);
                if ($removed > 0)
                {
                    $messages[] = $user->lang('ACP_HELPDESK_VALIDATION_LINES_IGNORED', $user->lang('ACP_HELPDESK_DEPARTMENT_SLA_RULES'), $removed);
                }

                $removed = 0;
                $priority_sla_definitions = $this->sanitize_priority_sla_definitions(trim($request->variable('mundophpbb_helpdesk_priority_sla_definitions', '', true)), $parsed_priorities, $removed);
                if ($removed > 0)
                {
                    $messages[] = $user->lang('ACP_HELPDESK_VALIDATION_LINES_IGNORED', $user->lang('ACP_HELPDESK_PRIORITY_SLA_RULES'), $removed);
                }

                $removed = 0;
                $department_priority_sla_definitions = $this->sanitize_department_priority_sla_definitions(trim($request->variable('mundophpbb_helpdesk_department_priority_sla_definitions', '', true)), $parsed_departments, $parsed_priorities, $removed);
                if ($removed > 0)
                {
                    $messages[] = $user->lang('ACP_HELPDESK_VALIDATION_LINES_IGNORED', $user->lang('ACP_HELPDESK_DEPARTMENT_PRIORITY_SLA_RULES'), $removed);
                }

                $removed = 0;
                $department_priority_queue_definitions = $this->sanitize_department_priority_queue_definitions(trim($request->variable('mundophpbb_helpdesk_department_priority_queue_definitions', '', true)), $parsed_departments, $parsed_priorities, $removed);
                if ($removed > 0)
                {
                    $messages[] = $user->lang('ACP_HELPDESK_VALIDATION_LINES_IGNORED', $user->lang('ACP_HELPDESK_DEPARTMENT_PRIORITY_QUEUE_RULES'), $removed);
                }

                $removed = 0;
                $assignee_queue_definitions = $this->sanitize_assignee_queue_definitions(trim($request->variable('mundophpbb_helpdesk_assignee_queue_definitions', '', true)), $removed);
                if ($removed > 0)
                {
                    $messages[] = $user->lang('ACP_HELPDESK_VALIDATION_LINES_IGNORED', $user->lang('ACP_HELPDESK_ASSIGNEE_QUEUE_RULES'), $removed);
                }

                $config->set('mundophpbb_helpdesk_team_reply_status', $team_reply_status);
                $config->set('mundophpbb_helpdesk_user_reply_status', $user_reply_status);
                $config->set('mundophpbb_helpdesk_assign_status', $assign_status);
                $config->set('mundophpbb_helpdesk_unassign_status', $unassign_status);
                $config->set('mundophpbb_helpdesk_department_status', $department_status);
                $config->set('mundophpbb_helpdesk_priority_high_status', $priority_high_status);
                $config->set('mundophpbb_helpdesk_priority_critical_status', $priority_critical_status);
                $config->set('mundophpbb_helpdesk_department_rule_definitions', $department_rule_definitions);
                $config->set('mundophpbb_helpdesk_department_priority_rule_definitions', $department_priority_rule_definitions);
                $config->set('mundophpbb_helpdesk_department_sla_definitions', $department_sla_definitions);
                $config->set('mundophpbb_helpdesk_priority_sla_definitions', $priority_sla_definitions);
                $config->set('mundophpbb_helpdesk_department_priority_sla_definitions', $department_priority_sla_definitions);
                $config->set('mundophpbb_helpdesk_department_priority_queue_definitions', $department_priority_queue_definitions);
                $config->set('mundophpbb_helpdesk_assignee_queue_definitions', $assignee_queue_definitions);
            }
            else if ($section === 'notifications')
            {
                $email_subject_prefix = trim($request->variable('mundophpbb_helpdesk_email_subject_prefix', '[Help Desk]', true));
                if ($email_subject_prefix === '')
                {
                    $email_subject_prefix = '[Help Desk]';
                    $messages[] = $user->lang('ACP_HELPDESK_VALIDATION_EMAIL_PREFIX_DEFAULTED');
                }

                $config->set('mundophpbb_helpdesk_email_notify_enable', $request->variable('mundophpbb_helpdesk_email_notify_enable', 0));
                $config->set('mundophpbb_helpdesk_email_notify_author', $request->variable('mundophpbb_helpdesk_email_notify_author', 1));
                $config->set('mundophpbb_helpdesk_email_notify_assignee', $request->variable('mundophpbb_helpdesk_email_notify_assignee', 1));
                $config->set('mundophpbb_helpdesk_email_notify_user_reply', $request->variable('mundophpbb_helpdesk_email_notify_user_reply', 1));
                $config->set('mundophpbb_helpdesk_email_subject_prefix', $email_subject_prefix);
            }
            else if ($section === 'lists')
            {
                list($status_definitions, $parsed_statuses, $status_errors) = $this->validate_status_definition_text(trim($request->variable('mundophpbb_helpdesk_status_definitions', '', true)), $user->lang('ACP_HELPDESK_STATUS_DEFINITIONS'));
                list($priority_definitions, $parsed_priorities, $priority_errors) = $this->validate_priority_definition_text(trim($request->variable('mundophpbb_helpdesk_priority_definitions', '', true)), $user->lang('ACP_HELPDESK_PRIORITY_DEFINITIONS'));
                list($categories, $parsed_categories, $category_errors) = $this->validate_keyed_list_text(trim($request->variable('mundophpbb_helpdesk_categories', '', true)), false, $user->lang('ACP_HELPDESK_CATEGORIES'));
                list($department_definitions, $parsed_departments, $department_errors) = $this->validate_keyed_list_text(trim($request->variable('mundophpbb_helpdesk_departments', '', true)), true, $user->lang('ACP_HELPDESK_DEPARTMENTS'));

                $errors = array_merge($errors, $status_errors, $priority_errors, $category_errors, $department_errors);
                if (!empty($errors))
                {
                    trigger_error(implode('<br />', $errors) . adm_back_link($this->u_action . '&section=' . $section), E_USER_WARNING);
                }

                $config->set('mundophpbb_helpdesk_status_definitions', $status_definitions);
                $config->set('mundophpbb_helpdesk_priority_definitions', $priority_definitions);
                $config->set('mundophpbb_helpdesk_categories', $categories);
                $config->set('mundophpbb_helpdesk_departments', $department_definitions);

                $current_default_status = isset($config['mundophpbb_helpdesk_default_status']) ? (string) $config['mundophpbb_helpdesk_default_status'] : 'open';
                if (!array_key_exists($current_default_status, $parsed_statuses))
                {
                    $current_default_status = $this->first_status_key($parsed_statuses, 'open');
                }
                $config->set('mundophpbb_helpdesk_default_status', $current_default_status);
                $config->set('mundophpbb_helpdesk_team_reply_status', $this->configured_reply_status($config, 'mundophpbb_helpdesk_team_reply_status', $parsed_statuses, 'waiting_reply'));
                $config->set('mundophpbb_helpdesk_user_reply_status', $this->configured_reply_status($config, 'mundophpbb_helpdesk_user_reply_status', $parsed_statuses, 'in_progress'));
                $config->set('mundophpbb_helpdesk_assign_status', $this->configured_optional_status($config, 'mundophpbb_helpdesk_assign_status', $parsed_statuses));
                $config->set('mundophpbb_helpdesk_unassign_status', $this->configured_optional_status($config, 'mundophpbb_helpdesk_unassign_status', $parsed_statuses));
                $config->set('mundophpbb_helpdesk_department_status', $this->configured_optional_status($config, 'mundophpbb_helpdesk_department_status', $parsed_statuses));
                $config->set('mundophpbb_helpdesk_priority_high_status', $this->configured_optional_status($config, 'mundophpbb_helpdesk_priority_high_status', $parsed_statuses));
                $config->set('mundophpbb_helpdesk_priority_critical_status', $this->configured_optional_status($config, 'mundophpbb_helpdesk_priority_critical_status', $parsed_statuses));

                $removed = 0;
                $cleaned = $this->sanitize_department_rule_definitions(isset($config['mundophpbb_helpdesk_department_rule_definitions']) ? (string) $config['mundophpbb_helpdesk_department_rule_definitions'] : '', $parsed_departments, $parsed_statuses, $removed);
                $config->set('mundophpbb_helpdesk_department_rule_definitions', $cleaned);
                if ($removed > 0)
                {
                    $messages[] = $user->lang('ACP_HELPDESK_VALIDATION_DEPENDENCIES_CLEANED', $user->lang('ACP_HELPDESK_DEPARTMENT_RULES'), $removed);
                }

                $removed = 0;
                $cleaned = $this->sanitize_department_priority_rule_definitions(isset($config['mundophpbb_helpdesk_department_priority_rule_definitions']) ? (string) $config['mundophpbb_helpdesk_department_priority_rule_definitions'] : '', $parsed_departments, $parsed_priorities, $parsed_statuses, $removed);
                $config->set('mundophpbb_helpdesk_department_priority_rule_definitions', $cleaned);
                if ($removed > 0)
                {
                    $messages[] = $user->lang('ACP_HELPDESK_VALIDATION_DEPENDENCIES_CLEANED', $user->lang('ACP_HELPDESK_DEPARTMENT_PRIORITY_RULES'), $removed);
                }

                $removed = 0;
                $cleaned = $this->sanitize_department_sla_definitions(isset($config['mundophpbb_helpdesk_department_sla_definitions']) ? (string) $config['mundophpbb_helpdesk_department_sla_definitions'] : '', $parsed_departments, $removed);
                $config->set('mundophpbb_helpdesk_department_sla_definitions', $cleaned);
                if ($removed > 0)
                {
                    $messages[] = $user->lang('ACP_HELPDESK_VALIDATION_DEPENDENCIES_CLEANED', $user->lang('ACP_HELPDESK_DEPARTMENT_SLA_RULES'), $removed);
                }

                $removed = 0;
                $cleaned = $this->sanitize_priority_sla_definitions(isset($config['mundophpbb_helpdesk_priority_sla_definitions']) ? (string) $config['mundophpbb_helpdesk_priority_sla_definitions'] : '', $parsed_priorities, $removed);
                $config->set('mundophpbb_helpdesk_priority_sla_definitions', $cleaned);
                if ($removed > 0)
                {
                    $messages[] = $user->lang('ACP_HELPDESK_VALIDATION_DEPENDENCIES_CLEANED', $user->lang('ACP_HELPDESK_PRIORITY_SLA_RULES'), $removed);
                }

                $removed = 0;
                $cleaned = $this->sanitize_department_priority_sla_definitions(isset($config['mundophpbb_helpdesk_department_priority_sla_definitions']) ? (string) $config['mundophpbb_helpdesk_department_priority_sla_definitions'] : '', $parsed_departments, $parsed_priorities, $removed);
                $config->set('mundophpbb_helpdesk_department_priority_sla_definitions', $cleaned);
                if ($removed > 0)
                {
                    $messages[] = $user->lang('ACP_HELPDESK_VALIDATION_DEPENDENCIES_CLEANED', $user->lang('ACP_HELPDESK_DEPARTMENT_PRIORITY_SLA_RULES'), $removed);
                }

                $removed = 0;
                $cleaned = $this->sanitize_department_priority_queue_definitions(isset($config['mundophpbb_helpdesk_department_priority_queue_definitions']) ? (string) $config['mundophpbb_helpdesk_department_priority_queue_definitions'] : '', $parsed_departments, $parsed_priorities, $removed);
                $config->set('mundophpbb_helpdesk_department_priority_queue_definitions', $cleaned);
                if ($removed > 0)
                {
                    $messages[] = $user->lang('ACP_HELPDESK_VALIDATION_DEPENDENCIES_CLEANED', $user->lang('ACP_HELPDESK_DEPARTMENT_PRIORITY_QUEUE_RULES'), $removed);
                }
            }

            $success_message = $user->lang('CONFIG_UPDATED');
            if (!empty($messages))
            {
                $success_message .= '<br /><br />' . implode('<br />', $messages);
            }

            trigger_error($success_message . adm_back_link($this->u_action . '&section=' . $section));
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

        $forum_ids = $this->parse_forum_ids(isset($config['mundophpbb_helpdesk_forums']) ? (string) $config['mundophpbb_helpdesk_forums'] : '');
        $automation_rule_count =
            $this->count_non_empty_lines($department_rule_definitions)
            + $this->count_non_empty_lines($department_priority_rule_definitions)
            + $this->count_non_empty_lines($department_sla_definitions)
            + $this->count_non_empty_lines($priority_sla_definitions)
            + $this->count_non_empty_lines($department_priority_sla_definitions)
            + $this->count_non_empty_lines($department_priority_queue_definitions)
            + $this->count_non_empty_lines($assignee_queue_definitions);

        $diagnostic_warnings = [];
        if (!empty($config['mundophpbb_helpdesk_enable']) && empty($forum_ids))
        {
            $diagnostic_warnings[] = $user->lang('ACP_HELPDESK_QUICK_CHECK_WARNING_NO_FORUMS');
        }
        if (!empty($config['mundophpbb_helpdesk_status_enable']) && count($parsed_statuses) === 0)
        {
            $diagnostic_warnings[] = $user->lang('ACP_HELPDESK_QUICK_CHECK_WARNING_NO_STATUSES');
        }
        if (!empty($config['mundophpbb_helpdesk_priority_enable']) && count($parsed_priorities) === 0)
        {
            $diagnostic_warnings[] = $user->lang('ACP_HELPDESK_QUICK_CHECK_WARNING_NO_PRIORITIES');
        }
        if (!empty($config['mundophpbb_helpdesk_category_enable']) && count($parsed_categories) === 0)
        {
            $diagnostic_warnings[] = $user->lang('ACP_HELPDESK_QUICK_CHECK_WARNING_NO_CATEGORIES');
        }
        if (!empty($config['mundophpbb_helpdesk_department_enable']) && count($parsed_departments) === 0)
        {
            $diagnostic_warnings[] = $user->lang('ACP_HELPDESK_QUICK_CHECK_WARNING_NO_DEPARTMENTS');
        }
        if (!empty($config['mundophpbb_helpdesk_alerts_enable']) && empty($config['mundophpbb_helpdesk_team_panel_enable']))
        {
            $diagnostic_warnings[] = $user->lang('ACP_HELPDESK_QUICK_CHECK_WARNING_ALERTS_WITHOUT_PANEL');
        }
        if (!empty($config['mundophpbb_helpdesk_email_notify_enable']) && trim($email_subject_prefix) === '')
        {
            $diagnostic_warnings[] = $user->lang('ACP_HELPDESK_QUICK_CHECK_WARNING_EMAIL_PREFIX_EMPTY');
        }
        if (!isset($parsed_statuses[$default_status]))
        {
            $diagnostic_warnings[] = $user->lang('ACP_HELPDESK_QUICK_CHECK_WARNING_DEFAULT_STATUS_INVALID');
        }

        foreach ($diagnostic_warnings as $warning)
        {
            $template->assign_block_vars('helpdesk_acp_quickcheck_warning', [
                'MESSAGE' => $warning,
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
            'HELPDESK_CATEGORIES' => $category_definitions,
            'HELPDESK_DEPARTMENTS' => $department_definitions,
            'HELPDESK_ACP_QUICKCHECK_STATUS_CLASS' => !empty($diagnostic_warnings) ? 'warning' : 'ok',
            'HELPDESK_ACP_QUICKCHECK_STATUS_LABEL' => !empty($diagnostic_warnings)
                ? $user->lang('ACP_HELPDESK_QUICK_CHECK_STATUS_WARNING')
                : $user->lang('ACP_HELPDESK_QUICK_CHECK_STATUS_OK'),
            'HELPDESK_ACP_QUICKCHECK_WARNING_COUNT' => count($diagnostic_warnings),
            'HELPDESK_ACP_QUICKCHECK_FORUM_COUNT' => count($forum_ids),
            'HELPDESK_ACP_QUICKCHECK_STATUS_COUNT' => count($parsed_statuses),
            'HELPDESK_ACP_QUICKCHECK_PRIORITY_COUNT' => count($parsed_priorities),
            'HELPDESK_ACP_QUICKCHECK_CATEGORY_COUNT' => count($parsed_categories),
            'HELPDESK_ACP_QUICKCHECK_DEPARTMENT_COUNT' => count($parsed_departments),
            'HELPDESK_ACP_QUICKCHECK_RULE_COUNT' => $automation_rule_count,
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

    protected function parse_forum_ids($raw)
    {
        $parts = preg_split('/\s*,\s*/', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY);
        $forum_ids = [];
        $seen = [];

        foreach ($parts as $part)
        {
            $forum_id = (int) $part;
            if ($forum_id <= 0 || isset($seen[$forum_id]))
            {
                continue;
            }

            $seen[$forum_id] = true;
            $forum_ids[] = $forum_id;
        }

        return $forum_ids;
    }

    protected function count_non_empty_lines($raw)
    {
        $lines = preg_split('/
|
|
/', (string) $raw);
        $count = 0;

        foreach ($lines as $line)
        {
            if (trim((string) $line) !== '')
            {
                $count++;
            }
        }

        return $count;
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


    protected function normalize_priority_tone($value)
    {
        $value = $this->slugify($value);
        $map = [
            'low' => 'low',
            'normal' => 'normal',
            'high' => 'high',
            'critical' => 'critical',
        ];

        return isset($map[$value]) ? $map[$value] : '';
    }

    protected function parse_priority_definitions($raw)
    {
        $lines = preg_split('/
|
|
/', (string) $raw);
        $definitions = [];

        foreach ($lines as $line)
        {
            $line = trim((string) $line);
            if ($line === '')
            {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            $key = $this->slugify($parts[0] ?? '');
            if ($key === '')
            {
                continue;
            }

            $definitions[$key] = [
                'key' => $key,
                'label_pt_br' => (string) ($parts[1] ?? ($parts[0] ?? $key)),
                'label_en' => (string) ($parts[2] ?? ($parts[1] ?? ($parts[0] ?? $key))),
                'tone' => $this->normalize_priority_tone($parts[3] ?? 'normal'),
            ];
        }

        return $definitions;
    }

    protected function parse_keyed_list_definitions($raw)
    {
        $lines = preg_split('/
|
|
/', (string) $raw);
        $definitions = [];

        foreach ($lines as $line)
        {
            $line = trim((string) $line);
            if ($line === '')
            {
                continue;
            }

            $parts = explode('|', $line, 2);
            if (count($parts) === 2)
            {
                $key = $this->slugify($parts[0]);
                $label = trim((string) $parts[1]);
            }
            else
            {
                $label = trim((string) $parts[0]);
                $key = $this->slugify($label);
            }

            if ($key === '' || $label === '')
            {
                continue;
            }

            $definitions[$key] = $label;
        }

        return $definitions;
    }

    protected function sanitize_forum_ids($raw)
    {
        $parts = preg_split('/\s*,\s*/', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY);
        $seen = [];
        $clean = [];

        foreach ($parts as $part)
        {
            $forum_id = (int) $part;
            if ($forum_id <= 0 || isset($seen[$forum_id]))
            {
                continue;
            }

            $seen[$forum_id] = true;
            $clean[] = (string) $forum_id;
        }

        return implode(', ', $clean);
    }

    protected function validate_status_definition_text($raw, $label)
    {
        global $user;

        $lines = preg_split('/
|
|
/', (string) $raw);
        $definitions = [];
        $normalized = [];
        $errors = [];

        foreach ($lines as $index => $line)
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
                $errors[] = $user->lang('ACP_HELPDESK_VALIDATION_LINE_MISSING_VALUE', $label, $index + 1);
                continue;
            }

            if (isset($definitions[$key]))
            {
                $errors[] = $user->lang('ACP_HELPDESK_VALIDATION_LINE_DUPLICATE_KEY', $label, $index + 1, $key);
                continue;
            }

            $label_pt_br = '';
            $label_en = '';
            if (count($parts) >= 4)
            {
                $label_pt_br = (string) $parts[1];
                $label_en = (string) $parts[2];
            }
            else if (count($parts) === 3)
            {
                $label_pt_br = (string) $parts[1];
                $label_en = (string) $parts[2];
            }
            else if (count($parts) === 2)
            {
                $label_pt_br = (string) $parts[1];
                $label_en = (string) $parts[1];
            }
            else
            {
                $label_pt_br = (string) $parts[0];
                $label_en = (string) $parts[0];
            }

            if ($label_pt_br === '')
            {
                $label_pt_br = $label_en !== '' ? $label_en : $key;
            }
            if ($label_en === '')
            {
                $label_en = $label_pt_br;
            }

            $provided_tone = isset($parts[3]) ? trim((string) $parts[3]) : '';
            $tone = $provided_tone !== '' ? $this->normalize_status_tone($provided_tone) : $this->normalize_status_tone($key);
            if ($tone === '')
            {
                $tone = 'open';
            }
            if ($provided_tone !== '' && $this->normalize_status_tone($provided_tone) === '')
            {
                $errors[] = $user->lang('ACP_HELPDESK_VALIDATION_LINE_INVALID_TONE', $label, $index + 1, $provided_tone);
                continue;
            }

            $definitions[$key] = [
                'label_pt_br' => $label_pt_br,
                'label_en' => $label_en,
                'tone' => $tone,
            ];
            $normalized[] = $key . '|' . $label_pt_br . '|' . $label_en . '|' . $tone;
        }

        if (empty($definitions))
        {
            $errors[] = $user->lang('ACP_HELPDESK_VALIDATION_LIST_REQUIRED', $label);
        }

        return [implode("
", $normalized), $definitions, $errors];
    }

    protected function validate_priority_definition_text($raw, $label)
    {
        global $user;

        $lines = preg_split('/
|
|
/', (string) $raw);
        $definitions = [];
        $normalized = [];
        $errors = [];

        foreach ($lines as $index => $line)
        {
            $line = trim((string) $line);
            if ($line === '')
            {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            $key = $this->slugify($parts[0] ?? '');
            if ($key === '')
            {
                $errors[] = $user->lang('ACP_HELPDESK_VALIDATION_LINE_MISSING_VALUE', $label, $index + 1);
                continue;
            }
            if (isset($definitions[$key]))
            {
                $errors[] = $user->lang('ACP_HELPDESK_VALIDATION_LINE_DUPLICATE_KEY', $label, $index + 1, $key);
                continue;
            }

            $label_pt_br = (string) ($parts[1] ?? ($parts[0] ?? $key));
            $label_en = (string) ($parts[2] ?? ($parts[1] ?? ($parts[0] ?? $key)));
            $provided_tone = isset($parts[3]) ? trim((string) $parts[3]) : 'normal';
            $tone = $this->normalize_priority_tone($provided_tone);
            if ($tone === '')
            {
                $errors[] = $user->lang('ACP_HELPDESK_VALIDATION_LINE_INVALID_TONE', $label, $index + 1, $provided_tone);
                continue;
            }

            $definitions[$key] = [
                'key' => $key,
                'label_pt_br' => $label_pt_br,
                'label_en' => $label_en,
                'tone' => $tone,
            ];
            $normalized[] = $key . '|' . $label_pt_br . '|' . $label_en . '|' . $tone;
        }

        if (empty($definitions))
        {
            $errors[] = $user->lang('ACP_HELPDESK_VALIDATION_LIST_REQUIRED', $label);
        }

        return [implode("
", $normalized), $definitions, $errors];
    }

    protected function validate_keyed_list_text($raw, $required, $label)
    {
        global $user;

        $lines = preg_split('/
|
|
/', (string) $raw);
        $definitions = [];
        $normalized = [];
        $errors = [];

        foreach ($lines as $index => $line)
        {
            $line = trim((string) $line);
            if ($line === '')
            {
                continue;
            }

            $parts = explode('|', $line, 2);
            if (count($parts) === 2)
            {
                $key = $this->slugify($parts[0]);
                $value = trim((string) $parts[1]);
            }
            else
            {
                $value = trim((string) $parts[0]);
                $key = $this->slugify($value);
            }

            if ($key === '' || $value === '')
            {
                $errors[] = $user->lang('ACP_HELPDESK_VALIDATION_LINE_MISSING_VALUE', $label, $index + 1);
                continue;
            }
            if (isset($definitions[$key]))
            {
                $errors[] = $user->lang('ACP_HELPDESK_VALIDATION_LINE_DUPLICATE_KEY', $label, $index + 1, $key);
                continue;
            }

            $definitions[$key] = $value;
            $normalized[] = $key . '|' . $value;
        }

        if ($required && empty($definitions))
        {
            $errors[] = $user->lang('ACP_HELPDESK_VALIDATION_LIST_REQUIRED', $label);
        }

        return [implode("
", $normalized), $definitions, $errors];
    }

    protected function sanitize_department_rule_definitions($raw, array $departments, array $statuses, &$removed)
    {
        $removed = 0;
        $normalized = [];
        $lines = preg_split('/
|
|
/', (string) $raw);

        foreach ($lines as $line)
        {
            $line = trim((string) $line);
            if ($line === '')
            {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            if (count($parts) !== 6)
            {
                $removed++;
                continue;
            }

            $department = $this->slugify($parts[0]);
            if ($department === '' || !isset($departments[$department]))
            {
                $removed++;
                continue;
            }

            $status_values = [];
            $invalid = false;
            for ($i = 1; $i <= 5; $i++)
            {
                $status = $this->slugify($parts[$i]);
                if ($status !== '' && !isset($statuses[$status]))
                {
                    $invalid = true;
                    break;
                }
                $status_values[] = $status;
            }

            if ($invalid)
            {
                $removed++;
                continue;
            }

            $normalized[] = implode('|', array_merge([$department], $status_values));
        }

        return implode("
", $normalized);
    }

    protected function sanitize_department_priority_rule_definitions($raw, array $departments, array $priorities, array $statuses, &$removed)
    {
        $removed = 0;
        $normalized = [];
        $lines = preg_split('/
|
|
/', (string) $raw);

        foreach ($lines as $line)
        {
            $line = trim((string) $line);
            if ($line === '')
            {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            if (count($parts) !== 9)
            {
                $removed++;
                continue;
            }

            $department = $this->slugify($parts[0]);
            $priority = $this->slugify($parts[1]);
            if ($department === '' || $priority === '' || !isset($departments[$department]) || !isset($priorities[$priority]))
            {
                $removed++;
                continue;
            }

            $status_values = [];
            $invalid = false;
            for ($i = 2; $i <= 8; $i++)
            {
                $status = $this->slugify($parts[$i]);
                if ($status !== '' && !isset($statuses[$status]))
                {
                    $invalid = true;
                    break;
                }
                $status_values[] = $status;
            }

            if ($invalid)
            {
                $removed++;
                continue;
            }

            $normalized[] = implode('|', array_merge([$department, $priority], $status_values));
        }

        return implode("
", $normalized);
    }

    protected function sanitize_department_sla_definitions($raw, array $departments, &$removed)
    {
        return $this->sanitize_numeric_rule_definitions($raw, [$departments], 4, true, $removed);
    }

    protected function sanitize_priority_sla_definitions($raw, array $priorities, &$removed)
    {
        return $this->sanitize_numeric_rule_definitions($raw, [$priorities], 4, true, $removed);
    }

    protected function sanitize_department_priority_sla_definitions($raw, array $departments, array $priorities, &$removed)
    {
        return $this->sanitize_numeric_rule_definitions($raw, [$departments, $priorities], 5, true, $removed);
    }

    protected function sanitize_department_priority_queue_definitions($raw, array $departments, array $priorities, &$removed)
    {
        return $this->sanitize_numeric_rule_definitions($raw, [$departments, $priorities], 4, false, $removed);
    }

    protected function sanitize_assignee_queue_definitions($raw, &$removed)
    {
        $removed = 0;
        $normalized = [];
        $lines = preg_split('/
|
|
/', (string) $raw);

        foreach ($lines as $line)
        {
            $line = trim((string) $line);
            if ($line === '')
            {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            if (count($parts) !== 3)
            {
                $removed++;
                continue;
            }

            $assignee = $this->slugify($parts[0]);
            $queue_boost = (int) $parts[1];
            $alert_hours = (int) $parts[2];
            if ($assignee === '' || (string) $queue_boost !== trim((string) $parts[1]) || (string) $alert_hours !== trim((string) $parts[2]) || $queue_boost < 0 || $alert_hours < 1)
            {
                $removed++;
                continue;
            }

            $normalized[] = $assignee . '|' . $queue_boost . '|' . $alert_hours;
        }

        return implode("
", $normalized);
    }

    protected function sanitize_numeric_rule_definitions($raw, array $reference_sets, $expected_parts, $require_positive_numbers, &$removed)
    {
        $removed = 0;
        $normalized = [];
        $reference_count = count($reference_sets);
        $lines = preg_split('/
|
|
/', (string) $raw);

        foreach ($lines as $line)
        {
            $line = trim((string) $line);
            if ($line === '')
            {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            if (count($parts) !== $expected_parts)
            {
                $removed++;
                continue;
            }

            $normalized_parts = [];
            $invalid = false;
            for ($i = 0; $i < $reference_count; $i++)
            {
                $key = $this->slugify($parts[$i]);
                if ($key === '' || !isset($reference_sets[$i][$key]))
                {
                    $invalid = true;
                    break;
                }
                $normalized_parts[] = $key;
            }

            if ($invalid)
            {
                $removed++;
                continue;
            }

            for ($i = $reference_count; $i < $expected_parts; $i++)
            {
                $value = trim((string) $parts[$i]);
                if ($value === '' || !preg_match('/^-?[0-9]+$/', $value))
                {
                    $invalid = true;
                    break;
                }

                $number = (int) $value;
                if (($require_positive_numbers && $number < 1) || (!$require_positive_numbers && $i === $reference_count && $number < 0) || (!$require_positive_numbers && $i > $reference_count && $number < 1))
                {
                    $invalid = true;
                    break;
                }
                $normalized_parts[] = (string) $number;
            }

            if ($invalid)
            {
                $removed++;
                continue;
            }

            $normalized[] = implode('|', $normalized_parts);
        }

        return implode("
", $normalized);
    }


    protected function restore_defaults_for_section($config, $section)
    {
        $messages = [];

        if ($section === 'general')
        {
            $config->set('mundophpbb_helpdesk_enable', 1);
            $config->set('mundophpbb_helpdesk_forums', '');
            $config->set('mundophpbb_helpdesk_prefix', '[Ticket]');
            return $messages;
        }

        if ($section === 'workflow')
        {
            $parsed_statuses = $this->parse_status_definitions($this->configured_status_definitions($config));
            $config->set('mundophpbb_helpdesk_default_status', $this->first_status_key($parsed_statuses, 'open'));
            $config->set('mundophpbb_helpdesk_status_enable', 1);
            $config->set('mundophpbb_helpdesk_priority_enable', 1);
            $config->set('mundophpbb_helpdesk_category_enable', 1);
            $config->set('mundophpbb_helpdesk_department_enable', 1);
            $config->set('mundophpbb_helpdesk_assignment_enable', 1);
            $config->set('mundophpbb_helpdesk_team_panel_enable', 1);
            $config->set('mundophpbb_helpdesk_alerts_enable', 1);
            $config->set('mundophpbb_helpdesk_alert_hours', 24);
            $config->set('mundophpbb_helpdesk_alert_limit', 15);
            $config->set('mundophpbb_helpdesk_sla_enable', 1);
            $config->set('mundophpbb_helpdesk_sla_hours', 24);
            $config->set('mundophpbb_helpdesk_stale_hours', 72);
            $config->set('mundophpbb_helpdesk_old_hours', 168);
            return $messages;
        }

        if ($section === 'automation')
        {
            $parsed_statuses = $this->parse_status_definitions($this->configured_status_definitions($config));
            $config->set('mundophpbb_helpdesk_automation_enable', 1);
            $config->set('mundophpbb_helpdesk_auto_lock_closed', 1);
            $config->set('mundophpbb_helpdesk_auto_unlock_reopened', 1);
            $config->set('mundophpbb_helpdesk_auto_assign_team_reply', 0);
            $config->set('mundophpbb_helpdesk_team_reply_status', array_key_exists('waiting_reply', $parsed_statuses) ? 'waiting_reply' : $this->first_status_key($parsed_statuses, 'open'));
            $config->set('mundophpbb_helpdesk_user_reply_status', array_key_exists('in_progress', $parsed_statuses) ? 'in_progress' : $this->first_status_key($parsed_statuses, 'open'));
            $config->set('mundophpbb_helpdesk_assign_status', '');
            $config->set('mundophpbb_helpdesk_unassign_status', '');
            $config->set('mundophpbb_helpdesk_department_status', '');
            $config->set('mundophpbb_helpdesk_priority_high_status', '');
            $config->set('mundophpbb_helpdesk_priority_critical_status', '');
            $config->set('mundophpbb_helpdesk_department_rule_definitions', '');
            $config->set('mundophpbb_helpdesk_department_priority_rule_definitions', '');
            $config->set('mundophpbb_helpdesk_department_sla_definitions', '');
            $config->set('mundophpbb_helpdesk_priority_sla_definitions', '');
            $config->set('mundophpbb_helpdesk_department_priority_sla_definitions', '');
            $config->set('mundophpbb_helpdesk_department_priority_queue_definitions', '');
            $config->set('mundophpbb_helpdesk_assignee_queue_definitions', '');
            return $messages;
        }

        if ($section === 'notifications')
        {
            $config->set('mundophpbb_helpdesk_email_notify_enable', 0);
            $config->set('mundophpbb_helpdesk_email_notify_author', 1);
            $config->set('mundophpbb_helpdesk_email_notify_assignee', 1);
            $config->set('mundophpbb_helpdesk_email_notify_user_reply', 1);
            $config->set('mundophpbb_helpdesk_email_subject_prefix', '[Help Desk]');
            return $messages;
        }

        if ($section === 'lists')
        {
            $status_definitions = $this->default_status_definitions();
            $priority_definitions = $this->default_priority_definitions();
            $category_definitions = $this->default_categories();
            $department_definitions = $this->default_departments();
            $parsed_statuses = $this->parse_status_definitions($status_definitions);
            $parsed_priorities = $this->parse_priority_definitions($priority_definitions);
            $parsed_departments = $this->parse_keyed_list_definitions($department_definitions);

            $config->set('mundophpbb_helpdesk_status_definitions', $status_definitions);
            $config->set('mundophpbb_helpdesk_priority_definitions', $priority_definitions);
            $config->set('mundophpbb_helpdesk_categories', $category_definitions);
            $config->set('mundophpbb_helpdesk_departments', $department_definitions);

            $current_default_status = isset($config['mundophpbb_helpdesk_default_status']) ? (string) $config['mundophpbb_helpdesk_default_status'] : 'open';
            if (!array_key_exists($current_default_status, $parsed_statuses))
            {
                $current_default_status = $this->first_status_key($parsed_statuses, 'open');
            }
            $config->set('mundophpbb_helpdesk_default_status', $current_default_status);
            $config->set('mundophpbb_helpdesk_team_reply_status', $this->configured_reply_status($config, 'mundophpbb_helpdesk_team_reply_status', $parsed_statuses, 'waiting_reply'));
            $config->set('mundophpbb_helpdesk_user_reply_status', $this->configured_reply_status($config, 'mundophpbb_helpdesk_user_reply_status', $parsed_statuses, 'in_progress'));
            $config->set('mundophpbb_helpdesk_assign_status', $this->configured_optional_status($config, 'mundophpbb_helpdesk_assign_status', $parsed_statuses));
            $config->set('mundophpbb_helpdesk_unassign_status', $this->configured_optional_status($config, 'mundophpbb_helpdesk_unassign_status', $parsed_statuses));
            $config->set('mundophpbb_helpdesk_department_status', $this->configured_optional_status($config, 'mundophpbb_helpdesk_department_status', $parsed_statuses));
            $config->set('mundophpbb_helpdesk_priority_high_status', $this->configured_optional_status($config, 'mundophpbb_helpdesk_priority_high_status', $parsed_statuses));
            $config->set('mundophpbb_helpdesk_priority_critical_status', $this->configured_optional_status($config, 'mundophpbb_helpdesk_priority_critical_status', $parsed_statuses));
            $removed = 0;
            $config->set('mundophpbb_helpdesk_department_rule_definitions', $this->sanitize_department_rule_definitions(isset($config['mundophpbb_helpdesk_department_rule_definitions']) ? (string) $config['mundophpbb_helpdesk_department_rule_definitions'] : '', $parsed_departments, $parsed_statuses, $removed));
            $removed = 0;
            $config->set('mundophpbb_helpdesk_department_priority_rule_definitions', $this->sanitize_department_priority_rule_definitions(isset($config['mundophpbb_helpdesk_department_priority_rule_definitions']) ? (string) $config['mundophpbb_helpdesk_department_priority_rule_definitions'] : '', $parsed_departments, $parsed_priorities, $parsed_statuses, $removed));
            $removed = 0;
            $config->set('mundophpbb_helpdesk_department_sla_definitions', $this->sanitize_department_sla_definitions(isset($config['mundophpbb_helpdesk_department_sla_definitions']) ? (string) $config['mundophpbb_helpdesk_department_sla_definitions'] : '', $parsed_departments, $removed));
            $removed = 0;
            $config->set('mundophpbb_helpdesk_priority_sla_definitions', $this->sanitize_priority_sla_definitions(isset($config['mundophpbb_helpdesk_priority_sla_definitions']) ? (string) $config['mundophpbb_helpdesk_priority_sla_definitions'] : '', $parsed_priorities, $removed));
            $removed = 0;
            $config->set('mundophpbb_helpdesk_department_priority_sla_definitions', $this->sanitize_department_priority_sla_definitions(isset($config['mundophpbb_helpdesk_department_priority_sla_definitions']) ? (string) $config['mundophpbb_helpdesk_department_priority_sla_definitions'] : '', $parsed_departments, $parsed_priorities, $removed));
            $removed = 0;
            $config->set('mundophpbb_helpdesk_department_priority_queue_definitions', $this->sanitize_department_priority_queue_definitions(isset($config['mundophpbb_helpdesk_department_priority_queue_definitions']) ? (string) $config['mundophpbb_helpdesk_department_priority_queue_definitions'] : '', $parsed_departments, $parsed_priorities, $removed));
            return $messages;
        }

        return $messages;
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
