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
        $user->add_lang_ext('mundophpbb/helpdesk', 'permissions_helpdesk');

        $this->tpl_name = 'acp_helpdesk_body';
        $is_permissions_mode = ($mode === 'permissions');
        $this->page_title = $user->lang($is_permissions_mode ? 'ACP_HELPDESK_PERMISSIONS' : 'ACP_HELPDESK_SETTINGS');

        add_form_key('mundophpbb_helpdesk');

        $valid_sections = ['general', 'workflow', 'automation', 'notifications', 'lists'];
        $section = $is_permissions_mode ? 'permissions' : (string) $request->variable('section', 'general', true);
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
        $department_reply_templates = isset($config['mundophpbb_helpdesk_department_reply_templates']) ? (string) $config['mundophpbb_helpdesk_department_reply_templates'] : '';
        $department_reply_template_rows = $this->parse_department_reply_template_rows($department_reply_templates, $parsed_departments);
        $department_auto_profile_definitions = isset($config['mundophpbb_helpdesk_department_auto_profile_definitions']) ? (string) $config['mundophpbb_helpdesk_department_auto_profile_definitions'] : '';
        $priority_high_status = $this->configured_optional_status($config, 'mundophpbb_helpdesk_priority_high_status', $parsed_statuses);
        $priority_critical_status = $this->configured_optional_status($config, 'mundophpbb_helpdesk_priority_critical_status', $parsed_statuses);
        $category_definitions = $this->configured_list($config, 'mundophpbb_helpdesk_categories', [
            'mundophpbb_helpdesk_categories_pt_br',
            'mundophpbb_helpdesk_categories_en',
        ], $this->default_categories());
        $parsed_categories = $this->parse_keyed_list_definitions($category_definitions);
        $department_auto_profiles = $this->parse_department_auto_profile_definitions($department_auto_profile_definitions, $parsed_departments, $parsed_statuses, $parsed_priorities);

        if (!$is_permissions_mode && $request->is_set_post('restore_defaults'))
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

        if ($is_permissions_mode && $request->is_set_post('apply_setup_assistant'))
        {
            if (!check_form_key('mundophpbb_helpdesk'))
            {
                trigger_error('FORM_INVALID');
            }

            $available_forums = $this->fetch_setup_forums();
            $available_groups = $this->fetch_available_groups($user);
            $valid_forum_ids = array_column($available_forums, 'forum_id');
            $valid_group_ids = array_column($available_groups, 'group_id');

            $setup_forums = array_values(array_unique(array_filter(array_map('intval', (array) $request->variable('helpdesk_setup_forums', [0])))));
            $setup_forums = array_values(array_intersect($setup_forums, $valid_forum_ids));

            $setup = [
                'admin_group_id' => (int) $request->variable('helpdesk_setup_admin_group', 0),
                'supervisor_group_id' => (int) $request->variable('helpdesk_setup_supervisor_group', 0),
                'agent_group_id' => (int) $request->variable('helpdesk_setup_agent_group', 0),
                'auditor_group_id' => (int) $request->variable('helpdesk_setup_auditor_group', 0),
                'customer_group_id' => (int) $request->variable('helpdesk_setup_customer_group', 0),
                'readonly_group_id' => (int) $request->variable('helpdesk_setup_readonly_group', 0),
            ];

            $errors = [];
            if (empty($setup_forums))
            {
                $errors[] = $user->lang('ACP_HELPDESK_SETUP_WARNING_NO_FORUMS_SELECTED');
            }

            foreach ($setup as $key => $group_id)
            {
                if ($group_id > 0 && !in_array($group_id, $valid_group_ids, true))
                {
                    $errors[] = $user->lang('ACP_HELPDESK_SETUP_WARNING_INVALID_GROUP');
                    break;
                }
            }

            $forum_scoped_groups = array_filter([
                (int) $setup['supervisor_group_id'],
                (int) $setup['agent_group_id'],
                (int) $setup['auditor_group_id'],
                (int) $setup['customer_group_id'],
                (int) $setup['readonly_group_id'],
            ]);
            $duplicates = [];
            foreach (array_count_values($forum_scoped_groups) as $group_id => $count)
            {
                if ($count > 1)
                {
                    $duplicates[] = $this->group_name_by_id((int) $group_id, $available_groups);
                }
            }
            if (!empty($duplicates))
            {
                $errors[] = $user->lang('ACP_HELPDESK_SETUP_WARNING_DUPLICATE_GROUPS', implode(', ', $duplicates));
            }

            if (!empty($errors))
            {
                trigger_error(implode('<br />', $errors) . adm_back_link($this->u_action), E_USER_WARNING);
            }

            $messages = $this->apply_setup_assistant($setup_forums, $setup, $available_groups, $config, $user);
            $success_message = $user->lang('ACP_HELPDESK_SETUP_SUCCESS');
            if (!empty($messages))
            {
                $success_message .= '<br /><br />' . implode('<br />', $messages);
            }

            trigger_error($success_message . adm_back_link($this->u_action));
        }

        if ($is_permissions_mode && $request->is_set_post('run_permission_probe'))
        {
            if (!check_form_key('mundophpbb_helpdesk'))
            {
                trigger_error('FORM_INVALID');
            }
        }

        if (!$is_permissions_mode && $request->is_set_post('submit'))
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
                $config->set('mundophpbb_helpdesk_require_reason_status', $request->variable('mundophpbb_helpdesk_require_reason_status', 0));
                $config->set('mundophpbb_helpdesk_require_reason_priority', $request->variable('mundophpbb_helpdesk_require_reason_priority', 0));
                $config->set('mundophpbb_helpdesk_require_reason_assignment', $request->variable('mundophpbb_helpdesk_require_reason_assignment', 0));
                $config->set('mundophpbb_helpdesk_team_panel_enable', $team_panel_enable);
                $config->set('mundophpbb_helpdesk_alerts_enable', $alerts_enable);
                $config->set('mundophpbb_helpdesk_alert_hours', $alert_hours);
                $config->set('mundophpbb_helpdesk_alert_limit', $alert_limit);
                $config->set('mundophpbb_helpdesk_sla_enable', $request->variable('mundophpbb_helpdesk_sla_enable', 1));
                $config->set('mundophpbb_helpdesk_sla_hours', $sla_hours);
                $config->set('mundophpbb_helpdesk_stale_hours', $stale_hours);
                $removed = 0;
                $reply_template_departments = $request->variable('helpdesk_reply_template_department', ['' => ''], true);
                $reply_template_titles = $request->variable('helpdesk_reply_template_title', ['' => ''], true);
                $reply_template_bodies = $request->variable('helpdesk_reply_template_body', ['' => ''], true);

                if (is_array($reply_template_departments) || is_array($reply_template_titles) || is_array($reply_template_bodies))
                {
                    $department_reply_templates = $this->build_department_reply_templates_from_arrays(
                        is_array($reply_template_departments) ? $reply_template_departments : [],
                        is_array($reply_template_titles) ? $reply_template_titles : [],
                        is_array($reply_template_bodies) ? $reply_template_bodies : [],
                        $parsed_departments,
                        $removed
                    );
                }
                else
                {
                    $department_reply_templates = $this->sanitize_department_reply_templates(trim($request->variable('mundophpbb_helpdesk_department_reply_templates', '', true)), $parsed_departments, $removed);
                }
                if ($removed > 0)
                {
                    $messages[] = $user->lang('ACP_HELPDESK_VALIDATION_LINES_IGNORED', $user->lang('ACP_HELPDESK_DEPARTMENT_REPLY_TEMPLATES'), $removed);
                }
                $department_reply_template_rows = $this->parse_department_reply_template_rows($department_reply_templates, $parsed_departments);

                $config->set('mundophpbb_helpdesk_old_hours', $old_hours);
                $config->set('mundophpbb_helpdesk_department_reply_templates', $department_reply_templates);
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

                $department_auto_profile_definitions = $this->build_department_auto_profile_definitions(
                    $request->variable('helpdesk_department_profile_status', ['' => ''], true),
                    $request->variable('helpdesk_department_profile_priority', ['' => ''], true),
                    $request->variable('helpdesk_department_profile_assignee', ['' => ''], true),
                    $request->variable('helpdesk_department_profile_reply_template', ['' => ''], true),
                    $parsed_departments,
                    $parsed_statuses,
                    $parsed_priorities,
                    $removed
                );
                if ($removed > 0)
                {
                    $messages[] = $user->lang('ACP_HELPDESK_VALIDATION_LINES_IGNORED', $user->lang('ACP_HELPDESK_DEPARTMENT_AUTO_PROFILES'), $removed);
                }
                $department_auto_profiles = $this->parse_department_auto_profile_definitions($department_auto_profile_definitions, $parsed_departments, $parsed_statuses, $parsed_priorities);

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
                $config->set('mundophpbb_helpdesk_department_auto_profile_definitions', $department_auto_profile_definitions);
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
                $cleaned = $this->sanitize_department_auto_profile_definitions(isset($config['mundophpbb_helpdesk_department_auto_profile_definitions']) ? (string) $config['mundophpbb_helpdesk_department_auto_profile_definitions'] : '', $parsed_departments, $parsed_statuses, $parsed_priorities, $removed);
                $config->set('mundophpbb_helpdesk_department_auto_profile_definitions', $cleaned);
                if ($removed > 0)
                {
                    $messages[] = $user->lang('ACP_HELPDESK_VALIDATION_DEPENDENCIES_CLEANED', $user->lang('ACP_HELPDESK_DEPARTMENT_AUTO_PROFILES'), $removed);
                }

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

        foreach ($parsed_departments as $department_key => $department_definition)
        {
            $department_label = $this->department_label_for_user($department_definition, $department_key, $user);
            $profile = isset($department_auto_profiles[$department_key]) ? $department_auto_profiles[$department_key] : ['status' => '', 'priority' => '', 'assignee' => '', 'reply_template' => ''];

            $template->assign_block_vars('helpdesk_department_auto_profile_row', [
                'DEPARTMENT_KEY' => $department_key,
                'DEPARTMENT_LABEL' => $department_label,
                'STATUS_OPTIONS_HTML' => $this->build_status_select_options_html($parsed_statuses, isset($profile['status']) ? $profile['status'] : '', $user, true),
                'PRIORITY_OPTIONS_HTML' => $this->build_priority_select_options_html($parsed_priorities, isset($profile['priority']) ? $profile['priority'] : '', $user, true),
                'ASSIGNEE' => isset($profile['assignee']) ? $profile['assignee'] : '',
                'REPLY_TEMPLATE' => isset($profile['reply_template']) ? $profile['reply_template'] : '',
            ]);
        }

        foreach ($this->build_department_reply_template_form_rows($department_reply_template_rows, $parsed_departments, $user) as $reply_template_row)
        {
            $template->assign_block_vars('helpdesk_department_reply_template_row', $reply_template_row);
        }

        $forum_ids = $this->parse_forum_ids(isset($config['mundophpbb_helpdesk_forums']) ? (string) $config['mundophpbb_helpdesk_forums'] : '');
        $automation_rule_count =
            $this->count_non_empty_lines($department_auto_profile_definitions)
            + $this->count_non_empty_lines($department_rule_definitions)
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

        $team_reply_status_label = ($team_reply_status !== '' && isset($parsed_statuses[$team_reply_status])) ? $this->status_label_for_user($parsed_statuses[$team_reply_status], $user) : $user->lang('ACP_HELPDESK_AUTOMATION_NO_CHANGE');
        $user_reply_status_label = ($user_reply_status !== '' && isset($parsed_statuses[$user_reply_status])) ? $this->status_label_for_user($parsed_statuses[$user_reply_status], $user) : $user->lang('ACP_HELPDESK_AUTOMATION_NO_CHANGE');
        $sla_rule_count = $this->count_non_empty_lines($department_sla_definitions) + $this->count_non_empty_lines($priority_sla_definitions) + $this->count_non_empty_lines($department_priority_sla_definitions);
        $queue_rule_count = $this->count_non_empty_lines($department_priority_queue_definitions) + $this->count_non_empty_lines($assignee_queue_definitions);
        $automation_summary = [
            'status_class' => !empty($config['mundophpbb_helpdesk_automation_enable']) ? 'ok' : 'warning',
            'status_label' => !empty($config['mundophpbb_helpdesk_automation_enable']) ? $user->lang('ACP_HELPDESK_AUTOMATION_STATUS_ENABLED') : $user->lang('ACP_HELPDESK_AUTOMATION_STATUS_DISABLED'),
            'reply_rule_count' => (!empty($team_reply_status) ? 1 : 0) + (!empty($user_reply_status) ? 1 : 0),
            'assignment_rule_count' => (!empty($assign_status) ? 1 : 0) + (!empty($unassign_status) ? 1 : 0) + (!empty($department_status) ? 1 : 0) + (!empty($priority_high_status) ? 1 : 0) + (!empty($priority_critical_status) ? 1 : 0) + (!empty($config['mundophpbb_helpdesk_auto_assign_team_reply']) ? 1 : 0),
            'sla_rule_count' => $sla_rule_count,
            'queue_rule_count' => $queue_rule_count,
            'locking_label' => (!empty($config['mundophpbb_helpdesk_auto_lock_closed']) ? $user->lang('YES') : $user->lang('NO')) . ' / ' . (!empty($config['mundophpbb_helpdesk_auto_unlock_reopened']) ? $user->lang('YES') : $user->lang('NO')),
            'reply_flow_label' => $team_reply_status_label . ' · ' . $user_reply_status_label,
            'escalation_label' => $this->automation_escalation_coverage_label($sla_rule_count, $queue_rule_count, $user),
            'behavior_lines' => [
                $user->lang('ACP_HELPDESK_AUTOMATION_BEHAVIOR_TEAM_REPLY', $team_reply_status_label),
                $user->lang('ACP_HELPDESK_AUTOMATION_BEHAVIOR_USER_REPLY', $user_reply_status_label),
                $user->lang('ACP_HELPDESK_AUTOMATION_BEHAVIOR_ASSIGNMENT', $this->automation_status_label($assign_status, $parsed_statuses, $user), $this->automation_status_label($unassign_status, $parsed_statuses, $user)),
                $user->lang('ACP_HELPDESK_AUTOMATION_BEHAVIOR_ESCALATION', $this->automation_escalation_coverage_label($sla_rule_count, $queue_rule_count, $user)),
            ],
        ];
        $notification_summary = [
            'status_class' => !empty($config['mundophpbb_helpdesk_email_notify_enable']) ? 'ok' : 'warning',
            'status_label' => !empty($config['mundophpbb_helpdesk_email_notify_enable']) ? $user->lang('ACP_HELPDESK_NOTIFICATIONS_STATUS_ENABLED') : $user->lang('ACP_HELPDESK_NOTIFICATIONS_STATUS_DISABLED'),
            'recipient_count' => (int) (!empty($config['mundophpbb_helpdesk_email_notify_author'])) + (int) (!empty($config['mundophpbb_helpdesk_email_notify_assignee'])) + (int) (!empty($config['mundophpbb_helpdesk_email_notify_user_reply'])),
            'subject_preview' => trim($email_subject_prefix) !== '' ? trim($email_subject_prefix) : $user->lang('ACP_HELPDESK_NOTIFICATIONS_SUBJECT_EMPTY'),
            'alerts_label' => (!empty($config['mundophpbb_helpdesk_team_panel_enable']) && !empty($config['mundophpbb_helpdesk_alerts_enable'])) ? $user->lang('ACP_HELPDESK_NOTIFICATIONS_ALERTS_PANEL_READY') : $user->lang('ACP_HELPDESK_NOTIFICATIONS_ALERTS_PANEL_MISSING'),
            'behavior_lines' => [
                $user->lang('ACP_HELPDESK_NOTIFICATIONS_BEHAVIOR_TEAM_REPLY', $this->notification_recipient_route_label(!empty($config['mundophpbb_helpdesk_email_notify_author']), !empty($config['mundophpbb_helpdesk_email_notify_assignee']), $user)),
                $user->lang('ACP_HELPDESK_NOTIFICATIONS_BEHAVIOR_USER_REPLY', $this->notification_recipient_route_label(false, !empty($config['mundophpbb_helpdesk_email_notify_user_reply']), $user)),
                $user->lang('ACP_HELPDESK_NOTIFICATIONS_BEHAVIOR_META_UPDATE', $this->notification_recipient_route_label(!empty($config['mundophpbb_helpdesk_email_notify_author']), !empty($config['mundophpbb_helpdesk_email_notify_assignee']), $user)),
            ],
        ];

        $permission_diagnostics = $is_permissions_mode ? $this->build_permission_diagnostics($forum_ids, $user) : [];
        $setup_summary = $is_permissions_mode ? $this->build_setup_summary($forum_ids, $user) : [];
        $setup_assistant = $is_permissions_mode ? $this->build_setup_assistant_state($config, $user, $setup_summary) : [];
        $permission_probe = $is_permissions_mode ? $this->build_permission_probe_state($forum_ids, $user, $request) : [];
        $acp_overview = !$is_permissions_mode ? $this->build_acp_overview($forum_ids, $parsed_statuses, $parsed_departments, $parsed_priorities, $user) : [];
        $feedback_overview = !$is_permissions_mode ? $this->build_acp_feedback_overview($forum_ids, $parsed_departments, $user) : [];
        if ($is_permissions_mode)
        {
            foreach ($setup_summary['forums'] as $forum_row)
            {
                $template->assign_block_vars('helpdesk_setup_summary_forum', $forum_row);
            }

            foreach ($setup_summary['roles'] as $role_row)
            {
                $template->assign_block_vars('helpdesk_setup_summary_role', $role_row);
            }

            foreach ($permission_diagnostics['warnings'] as $warning)
            {
                $template->assign_block_vars('helpdesk_permission_warning', [
                    'MESSAGE' => $warning,
                ]);
            }

            foreach ($permission_diagnostics['forums'] as $forum_row)
            {
                $template->assign_block_vars('helpdesk_permission_forum', $forum_row);
            }

            foreach ($permission_diagnostics['customer_checks'] as $customer_check_row)
            {
                $template->assign_block_vars('helpdesk_permission_customer_check', $customer_check_row);
            }

            foreach ($permission_diagnostics['roles'] as $role_row)
            {
                $template->assign_block_vars('helpdesk_permission_role', $role_row);
            }

            foreach ($setup_assistant['forums'] as $forum_row)
            {
                $template->assign_block_vars('helpdesk_setup_forum', $forum_row);
            }

            foreach ($permission_probe['forum_options'] as $forum_option)
            {
                $template->assign_block_vars('helpdesk_permission_probe_forum', $forum_option);
            }

            foreach ($permission_probe['summary_rows'] as $summary_row)
            {
                $template->assign_block_vars('helpdesk_permission_probe_summary', $summary_row);
            }

            foreach ($permission_probe['permission_rows'] as $permission_row)
            {
                $template->assign_block_vars('helpdesk_permission_probe_row', $permission_row);
            }

            foreach ($permission_probe['warnings'] as $warning)
            {
                $template->assign_block_vars('helpdesk_permission_probe_warning', [
                    'MESSAGE' => $warning,
                ]);
            }

            $this->assign_setup_group_options($template, 'helpdesk_setup_admin_group', $setup_assistant['groups'], $setup_assistant['selected']['admin_group_id']);
            $this->assign_setup_group_options($template, 'helpdesk_setup_supervisor_group', $setup_assistant['groups'], $setup_assistant['selected']['supervisor_group_id']);
            $this->assign_setup_group_options($template, 'helpdesk_setup_agent_group', $setup_assistant['groups'], $setup_assistant['selected']['agent_group_id']);
            $this->assign_setup_group_options($template, 'helpdesk_setup_auditor_group', $setup_assistant['groups'], $setup_assistant['selected']['auditor_group_id']);
            $this->assign_setup_group_options($template, 'helpdesk_setup_customer_group', $setup_assistant['groups'], $setup_assistant['selected']['customer_group_id']);
            $this->assign_setup_group_options($template, 'helpdesk_setup_readonly_group', $setup_assistant['groups'], $setup_assistant['selected']['readonly_group_id']);
        }

        if (!$is_permissions_mode)
        {
            foreach ($acp_overview['status_rows'] as $status_row)
            {
                $template->assign_block_vars('helpdesk_acp_status_row', $status_row);
            }

            foreach ($acp_overview['recent_activity'] as $activity_row)
            {
                $template->assign_block_vars('helpdesk_acp_recent_activity', $activity_row);
            }

            foreach ($acp_overview['tracked_forums'] as $forum_row)
            {
                $template->assign_block_vars('helpdesk_acp_tracked_forum', $forum_row);
            }

            foreach ($acp_overview['alert_rows'] as $alert_row)
            {
                $template->assign_block_vars('helpdesk_acp_alert_row', $alert_row);
            }

            foreach ($feedback_overview['distribution_rows'] as $distribution_row)
            {
                $template->assign_block_vars('helpdesk_acp_feedback_distribution_row', $distribution_row);
            }

            foreach ($feedback_overview['department_rows'] as $department_row)
            {
                $template->assign_block_vars('helpdesk_acp_feedback_department_row', $department_row);
            }

            foreach ($feedback_overview['recent_rows'] as $recent_row)
            {
                $template->assign_block_vars('helpdesk_acp_feedback_recent_row', $recent_row);
            }

            foreach ($automation_summary['behavior_lines'] as $behavior_line)
            {
                $template->assign_block_vars('helpdesk_acp_automation_behavior_line', [
                    'TEXT' => $behavior_line,
                ]);
            }

            foreach ($notification_summary['behavior_lines'] as $behavior_line)
            {
                $template->assign_block_vars('helpdesk_acp_notification_behavior_line', [
                    'TEXT' => $behavior_line,
                ]);
            }
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
            'HELPDESK_REQUIRE_REASON_STATUS' => !empty($config['mundophpbb_helpdesk_require_reason_status']),
            'HELPDESK_REQUIRE_REASON_PRIORITY' => !empty($config['mundophpbb_helpdesk_require_reason_priority']),
            'HELPDESK_REQUIRE_REASON_ASSIGNMENT' => !empty($config['mundophpbb_helpdesk_require_reason_assignment']),
            'HELPDESK_TEAM_PANEL_ENABLE' => !empty($config['mundophpbb_helpdesk_team_panel_enable']),
            'HELPDESK_ALERTS_ENABLE' => !empty($config['mundophpbb_helpdesk_alerts_enable']),
            'HELPDESK_ALERT_HOURS' => isset($config['mundophpbb_helpdesk_alert_hours']) ? (int) $config['mundophpbb_helpdesk_alert_hours'] : 24,
            'HELPDESK_ALERT_LIMIT' => isset($config['mundophpbb_helpdesk_alert_limit']) ? (int) $config['mundophpbb_helpdesk_alert_limit'] : 15,
            'HELPDESK_SLA_ENABLE' => !empty($config['mundophpbb_helpdesk_sla_enable']),
            'HELPDESK_SLA_HOURS' => isset($config['mundophpbb_helpdesk_sla_hours']) ? (int) $config['mundophpbb_helpdesk_sla_hours'] : 24,
            'HELPDESK_STALE_HOURS' => isset($config['mundophpbb_helpdesk_stale_hours']) ? (int) $config['mundophpbb_helpdesk_stale_hours'] : 72,
            'HELPDESK_OLD_HOURS' => isset($config['mundophpbb_helpdesk_old_hours']) ? (int) $config['mundophpbb_helpdesk_old_hours'] : 168,
            'HELPDESK_DEPARTMENT_REPLY_TEMPLATES' => $department_reply_templates,
            'HELPDESK_DEPARTMENT_REPLY_TEMPLATE_ROW_COUNT' => count($department_reply_template_rows),
            'HELPDESK_DEPARTMENT_AUTO_PROFILE_DEFINITIONS' => $department_auto_profile_definitions,
            'HELPDESK_DEPARTMENT_AUTO_PROFILE_COUNT' => count($parsed_departments),
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
            'HELPDESK_ACP_AUTOMATION_STATUS_CLASS' => $automation_summary['status_class'],
            'HELPDESK_ACP_AUTOMATION_STATUS_LABEL' => $automation_summary['status_label'],
            'HELPDESK_ACP_AUTOMATION_REPLY_RULE_COUNT' => $automation_summary['reply_rule_count'],
            'HELPDESK_ACP_AUTOMATION_ASSIGNMENT_RULE_COUNT' => $automation_summary['assignment_rule_count'],
            'HELPDESK_ACP_AUTOMATION_SLA_RULE_COUNT' => $automation_summary['sla_rule_count'],
            'HELPDESK_ACP_AUTOMATION_QUEUE_RULE_COUNT' => $automation_summary['queue_rule_count'],
            'HELPDESK_ACP_AUTOMATION_LOCKING_LABEL' => $automation_summary['locking_label'],
            'HELPDESK_ACP_AUTOMATION_REPLY_FLOW_LABEL' => $automation_summary['reply_flow_label'],
            'HELPDESK_ACP_AUTOMATION_ESCALATION_LABEL' => $automation_summary['escalation_label'],
            'HELPDESK_ACP_NOTIFICATIONS_STATUS_CLASS' => $notification_summary['status_class'],
            'HELPDESK_ACP_NOTIFICATIONS_STATUS_LABEL' => $notification_summary['status_label'],
            'HELPDESK_ACP_NOTIFICATIONS_RECIPIENT_COUNT' => $notification_summary['recipient_count'],
            'HELPDESK_ACP_NOTIFICATIONS_SUBJECT_PREVIEW' => $notification_summary['subject_preview'],
            'HELPDESK_ACP_NOTIFICATIONS_ALERTS_LABEL' => $notification_summary['alerts_label'],
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
            'HELPDESK_ACP_OVERVIEW_TOTAL_TICKETS' => !$is_permissions_mode ? (int) $acp_overview['total_tickets'] : 0,
            'HELPDESK_ACP_OVERVIEW_ACTIVE_TICKETS' => !$is_permissions_mode ? (int) $acp_overview['active_tickets'] : 0,
            'HELPDESK_ACP_OVERVIEW_CLOSED_TICKETS' => !$is_permissions_mode ? (int) $acp_overview['closed_tickets'] : 0,
            'HELPDESK_ACP_OVERVIEW_ASSIGNED_TICKETS' => !$is_permissions_mode ? (int) $acp_overview['assigned_tickets'] : 0,
            'HELPDESK_ACP_OVERVIEW_RECENT_ACTIVITY' => !$is_permissions_mode ? (int) $acp_overview['recent_activity_count'] : 0,
            'HELPDESK_ACP_OVERVIEW_TRACKED_FORUM_NAMES' => !$is_permissions_mode ? (string) $acp_overview['tracked_forum_names'] : '',
            'HELPDESK_ACP_OVERVIEW_HAS_ACTIVITY' => !$is_permissions_mode && !empty($acp_overview['recent_activity']),
            'HELPDESK_ACP_ALERT_TOTAL' => !$is_permissions_mode ? (int) $acp_overview['alert_total'] : 0,
            'HELPDESK_ACP_ALERT_OVERDUE' => !$is_permissions_mode ? (int) $acp_overview['alert_overdue_count'] : 0,
            'HELPDESK_ACP_ALERT_STALE' => !$is_permissions_mode ? (int) $acp_overview['alert_stale_count'] : 0,
            'HELPDESK_ACP_ALERT_FIRST_REPLY' => !$is_permissions_mode ? (int) $acp_overview['alert_first_reply_count'] : 0,
            'HELPDESK_ACP_ALERT_UNASSIGNED' => !$is_permissions_mode ? (int) $acp_overview['alert_unassigned_count'] : 0,
            'HELPDESK_ACP_ALERT_REOPENED' => !$is_permissions_mode ? (int) $acp_overview['alert_reopened_count'] : 0,
            'HELPDESK_ACP_ALERT_WAITING_STAFF' => !$is_permissions_mode ? (int) $acp_overview['alert_waiting_staff_count'] : 0,
            'HELPDESK_ACP_ALERT_LIMIT' => !$is_permissions_mode ? (int) $acp_overview['alert_limit'] : 0,
            'HELPDESK_ACP_ALERT_HAS_ROWS' => !$is_permissions_mode && !empty($acp_overview['alert_rows']),
            'HELPDESK_ACP_FEEDBACK_ENABLED' => !$is_permissions_mode ? !empty($feedback_overview['enabled']) : false,
            'HELPDESK_ACP_FEEDBACK_HAS_DATA' => !$is_permissions_mode ? !empty($feedback_overview['has_feedback']) : false,
            'HELPDESK_ACP_FEEDBACK_TOTAL' => !$is_permissions_mode ? (int) $feedback_overview['total_feedback'] : 0,
            'HELPDESK_ACP_FEEDBACK_AVERAGE' => !$is_permissions_mode ? (string) $feedback_overview['average_rating_value'] : '0.0',
            'HELPDESK_ACP_FEEDBACK_AVERAGE_LABEL' => !$is_permissions_mode ? (string) $feedback_overview['average_rating_label'] : '',
            'HELPDESK_ACP_FEEDBACK_RECENT_30D' => !$is_permissions_mode ? (int) $feedback_overview['recent_feedback_count'] : 0,
            'HELPDESK_ACP_FEEDBACK_COMMENT_COUNT' => !$is_permissions_mode ? (int) $feedback_overview['comment_count'] : 0,
            'HELPDESK_ACP_FEEDBACK_LOW_RATING_COUNT' => !$is_permissions_mode ? (int) $feedback_overview['low_rating_count'] : 0,
            'HELPDESK_ACP_PERMISSION_STATUS_CLASS' => ($is_permissions_mode && !empty($permission_diagnostics['warnings'])) ? 'warning' : 'ok',
            'HELPDESK_ACP_PERMISSION_STATUS_LABEL' => ($is_permissions_mode && !empty($permission_diagnostics['warnings']))
                ? $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_STATUS_WARNING')
                : $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_STATUS_OK'),
            'HELPDESK_ACP_PERMISSION_WARNING_COUNT' => $is_permissions_mode ? (int) $permission_diagnostics['warning_count'] : 0,
            'HELPDESK_ACP_PERMISSION_TRACKED_FORUM_COUNT' => $is_permissions_mode ? (int) $permission_diagnostics['tracked_forum_count'] : 0,
            'HELPDESK_ACP_PERMISSION_ADMIN_ASSIGNMENTS' => $is_permissions_mode ? (int) $permission_diagnostics['admin_assignments'] : 0,
            'HELPDESK_ACP_PERMISSION_STAFF_ASSIGNMENTS' => $is_permissions_mode ? (int) $permission_diagnostics['staff_assignments'] : 0,
            'HELPDESK_ACP_PERMISSION_CUSTOMER_ASSIGNMENTS' => $is_permissions_mode ? (int) $permission_diagnostics['customer_assignments'] : 0,
            'HELPDESK_ACP_PERMISSION_CUSTOMER_READY_COUNT' => $is_permissions_mode ? (int) $permission_diagnostics['customer_ready_count'] : 0,
            'HELPDESK_ACP_PERMISSION_CUSTOMER_CHECK_COUNT' => $is_permissions_mode ? (int) $permission_diagnostics['customer_check_count'] : 0,
            'HELPDESK_ACP_PERMISSION_VALID_ROLE_COUNT' => $is_permissions_mode ? (int) $permission_diagnostics['valid_role_count'] : 0,
            'HELPDESK_ACP_PERMISSION_ROLE_COUNT' => $is_permissions_mode ? (int) $permission_diagnostics['role_count'] : 0,
            'HELPDESK_ACP_SETUP_FORUM_COUNT' => $is_permissions_mode ? count($setup_assistant['forums']) : 0,
            'HELPDESK_ACP_SETUP_GROUP_COUNT' => $is_permissions_mode ? count($setup_assistant['groups']) : 0,
            'HELPDESK_ACP_SETUP_SUMMARY_FORUM_COUNT' => $is_permissions_mode ? (int) $setup_summary['forum_count'] : 0,
            'HELPDESK_ACP_SETUP_SUMMARY_ASSIGNED_ROLE_COUNT' => $is_permissions_mode ? (int) $setup_summary['assigned_role_count'] : 0,
            'HELPDESK_ACP_SETUP_SUMMARY_ROW_COUNT' => $is_permissions_mode ? (int) $setup_summary['row_count'] : 0,
            'HELPDESK_ACP_PERMISSION_PROBE_HAS_RESULT' => $is_permissions_mode ? !empty($permission_probe['has_result']) : false,
            'HELPDESK_ACP_PERMISSION_PROBE_HAS_ERROR' => $is_permissions_mode ? !empty($permission_probe['error']) : false,
            'HELPDESK_ACP_PERMISSION_PROBE_ERROR' => $is_permissions_mode ? (string) $permission_probe['error'] : '',
            'HELPDESK_ACP_PERMISSION_PROBE_STATUS_CLASS' => $is_permissions_mode ? (string) $permission_probe['status_class'] : 'info',
            'HELPDESK_ACP_PERMISSION_PROBE_STATUS_LABEL' => $is_permissions_mode ? (string) $permission_probe['status_label'] : '',
            'HELPDESK_ACP_PERMISSION_PROBE_USERNAME' => $is_permissions_mode ? (string) $permission_probe['username_input'] : '',
            'HELPDESK_ACP_PERMISSION_PROBE_SELECTED_FORUM' => $is_permissions_mode ? (int) $permission_probe['selected_forum_id'] : 0,
            'HELPDESK_ACP_PERMISSION_PROBE_USER_LABEL' => $is_permissions_mode ? (string) $permission_probe['user_label'] : '',
            'HELPDESK_ACP_PERMISSION_PROBE_FORUM_LABEL' => $is_permissions_mode ? (string) $permission_probe['forum_label'] : '',
            'HELPDESK_ACP_PERMISSION_PROBE_GROUPS_LABEL' => $is_permissions_mode ? (string) $permission_probe['groups_label'] : '',
            'HELPDESK_ACP_PERMISSION_PROBE_NOTE' => $is_permissions_mode ? (string) $permission_probe['note'] : '',
            'HELPDESK_ACP_PAGE_TITLE' => $user->lang($is_permissions_mode ? 'ACP_HELPDESK_PERMISSIONS' : 'ACP_HELPDESK_SETTINGS'),
            'HELPDESK_ACP_PAGE_EXPLAIN' => $user->lang($is_permissions_mode ? 'ACP_HELPDESK_PERMISSIONS_EXPLAIN' : 'ACP_HELPDESK_SETTINGS_EXPLAIN'),
            'HELPDESK_ACP_SECTION' => $section,
            'S_HELPDESK_ACP_GENERAL' => ($section === 'general'),
            'S_HELPDESK_ACP_WORKFLOW' => ($section === 'workflow'),
            'S_HELPDESK_ACP_AUTOMATION' => ($section === 'automation'),
            'S_HELPDESK_ACP_NOTIFICATIONS' => ($section === 'notifications'),
            'S_HELPDESK_ACP_LISTS' => ($section === 'lists'),
            'S_HELPDESK_ACP_PERMISSIONS' => $is_permissions_mode,
            'U_HELPDESK_ACP_GENERAL' => str_replace('mode=permissions', 'mode=settings', $this->u_action) . '&amp;section=general',
            'U_HELPDESK_ACP_WORKFLOW' => str_replace('mode=permissions', 'mode=settings', $this->u_action) . '&amp;section=workflow',
            'U_HELPDESK_ACP_AUTOMATION' => str_replace('mode=permissions', 'mode=settings', $this->u_action) . '&amp;section=automation',
            'U_HELPDESK_ACP_NOTIFICATIONS' => str_replace('mode=permissions', 'mode=settings', $this->u_action) . '&amp;section=notifications',
            'U_HELPDESK_ACP_LISTS' => str_replace('mode=permissions', 'mode=settings', $this->u_action) . '&amp;section=lists',
            'U_HELPDESK_ACP_PERMISSIONS' => str_replace('mode=settings', 'mode=permissions', $this->u_action),
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


    protected function automation_status_label($status_key, array $parsed_statuses, $user)
    {
        $status_key = (string) $status_key;
        if ($status_key !== '' && isset($parsed_statuses[$status_key]))
        {
            return $this->status_label_for_user($parsed_statuses[$status_key], $user);
        }

        return $user->lang('ACP_HELPDESK_AUTOMATION_NO_CHANGE');
    }

    protected function automation_escalation_coverage_label($sla_rule_count, $queue_rule_count, $user)
    {
        $sla_rule_count = (int) $sla_rule_count;
        $queue_rule_count = (int) $queue_rule_count;

        if ($sla_rule_count > 0 && $queue_rule_count > 0)
        {
            return $user->lang('ACP_HELPDESK_AUTOMATION_ESCALATION_COVERAGE_FULL');
        }

        if ($sla_rule_count > 0 || $queue_rule_count > 0)
        {
            return $user->lang('ACP_HELPDESK_AUTOMATION_ESCALATION_COVERAGE_PARTIAL');
        }

        return $user->lang('ACP_HELPDESK_AUTOMATION_ESCALATION_COVERAGE_GLOBAL');
    }

    protected function notification_recipient_route_label($author_enabled, $assignee_enabled, $user)
    {
        if ($author_enabled && $assignee_enabled)
        {
            return $user->lang('ACP_HELPDESK_NOTIFICATIONS_ROUTE_AUTHOR_ASSIGNEE');
        }

        if ($author_enabled)
        {
            return $user->lang('ACP_HELPDESK_NOTIFICATIONS_ROUTE_AUTHOR');
        }

        if ($assignee_enabled)
        {
            return $user->lang('ACP_HELPDESK_NOTIFICATIONS_ROUTE_ASSIGNEE');
        }

        return $user->lang('ACP_HELPDESK_NOTIFICATIONS_ROUTE_NONE');
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


    protected function parse_department_auto_profile_definitions($raw, array $departments, array $statuses, array $priorities)
    {
        $definitions = [];
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw);

        foreach ($lines as $line)
        {
            $line = trim((string) $line);
            if ($line === '' || strpos($line, '#') === 0)
            {
                continue;
            }

            $parts = $this->split_escaped_pipe_parts($line, 5);
            if (count($parts) !== 5)
            {
                continue;
            }

            $department_key = $this->slugify(trim(str_replace('\\|', '|', $parts[0])));
            if ($department_key === '' || !isset($departments[$department_key]))
            {
                continue;
            }

            $status_key = $this->slugify(trim(str_replace('\\|', '|', $parts[1])));
            $priority_key = $this->slugify(trim(str_replace('\\|', '|', $parts[2])));
            $assignee = $this->sanitize_profile_assignee(trim(str_replace('\\|', '|', $parts[3])));
            $reply_template = $this->sanitize_profile_reply_template(trim(str_replace('\\|', '|', $parts[4])));

            if ($status_key !== '' && !isset($statuses[$status_key]))
            {
                $status_key = '';
            }

            if ($priority_key !== '' && !isset($priorities[$priority_key]))
            {
                $priority_key = '';
            }

            if ($status_key === '' && $priority_key === '' && $assignee === '' && $reply_template === '')
            {
                continue;
            }

            $definitions[$department_key] = [
                'status' => $status_key,
                'priority' => $priority_key,
                'assignee' => $assignee,
                'reply_template' => $reply_template,
            ];
        }

        return $definitions;
    }

    protected function build_department_auto_profile_definitions(array $status_values, array $priority_values, array $assignee_values, array $reply_template_values, array $departments, array $statuses, array $priorities, &$removed)
    {
        $removed = 0;
        $normalized = [];

        foreach ($departments as $department_key => $definition)
        {
            $status_key = $this->slugify(isset($status_values[$department_key]) ? $status_values[$department_key] : '');
            $priority_key = $this->slugify(isset($priority_values[$department_key]) ? $priority_values[$department_key] : '');
            $assignee = $this->sanitize_profile_assignee(isset($assignee_values[$department_key]) ? $assignee_values[$department_key] : '');
            $reply_template = $this->sanitize_profile_reply_template(isset($reply_template_values[$department_key]) ? $reply_template_values[$department_key] : '');

            if ($status_key !== '' && !isset($statuses[$status_key]))
            {
                $removed++;
                $status_key = '';
            }

            if ($priority_key !== '' && !isset($priorities[$priority_key]))
            {
                $removed++;
                $priority_key = '';
            }

            if ($status_key === '' && $priority_key === '' && $assignee === '' && $reply_template === '')
            {
                continue;
            }

            $normalized[] = implode('|', [$department_key, $status_key, $priority_key, $assignee, $reply_template]);
        }

        return implode("\n", $normalized);
    }

    protected function sanitize_department_auto_profile_definitions($raw, array $departments, array $statuses, array $priorities, &$removed)
    {
        $definitions = $this->parse_department_auto_profile_definitions($raw, $departments, $statuses, $priorities);
        $removed = max(0, $this->count_non_empty_lines($raw) - count($definitions));
        $normalized = [];

        foreach ($definitions as $department_key => $profile)
        {
            $normalized[] = implode('|', [
                $department_key,
                isset($profile['status']) ? $profile['status'] : '',
                isset($profile['priority']) ? $profile['priority'] : '',
                isset($profile['assignee']) ? $profile['assignee'] : '',
                isset($profile['reply_template']) ? $profile['reply_template'] : '',
            ]);
        }

        return implode("\n", $normalized);
    }

    protected function sanitize_profile_assignee($value)
    {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return substr($value, 0, 255);
    }

    protected function sanitize_profile_reply_template($value)
    {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return substr($value, 0, 255);
    }

    protected function build_status_select_options_html(array $definitions, $selected, $user, $allow_empty = false)
    {
        $selected = trim((string) $selected);
        $html = $allow_empty ? '<option value="">' . \utf8_htmlspecialchars((string) $user->lang('ACP_HELPDESK_AUTOMATION_NO_CHANGE')) . '</option>' : '';

        foreach ($definitions as $key => $definition)
        {
            $label = strtolower((string) $user->lang_name) === 'pt_br'
                ? $definition['label_pt_br']
                : $definition['label_en'];
            $html .= '<option value="' . \utf8_htmlspecialchars((string) $key) . '"' . ($selected === (string) $key ? ' selected="selected"' : '') . '>' . \utf8_htmlspecialchars((string) $label) . '</option>';
        }

        return $html;
    }

    protected function build_priority_select_options_html(array $definitions, $selected, $user, $allow_empty = false)
    {
        $selected = trim((string) $selected);
        $html = $allow_empty ? '<option value="">' . \utf8_htmlspecialchars((string) $user->lang('ACP_HELPDESK_AUTOMATION_NO_CHANGE')) . '</option>' : '';

        foreach ($definitions as $key => $definition)
        {
            $label = strtolower((string) $user->lang_name) === 'pt_br'
                ? $definition['label_pt_br']
                : $definition['label_en'];
            $html .= '<option value="' . \utf8_htmlspecialchars((string) $key) . '"' . ($selected === (string) $key ? ' selected="selected"' : '') . '>' . \utf8_htmlspecialchars((string) $label) . '</option>';
        }

        return $html;
    }


    protected function parse_department_reply_template_rows($raw, array $departments)
    {
        $rows = [];
        $lines = preg_split('/
|
|
/', (string) $raw);

        foreach ($lines as $line)
        {
            $line = trim((string) $line);
            if ($line === '' || strpos($line, '#') === 0)
            {
                continue;
            }

            $parts = $this->split_escaped_pipe_parts($line, 3);
            if (count($parts) !== 3)
            {
                continue;
            }

            $department_key = trim(str_replace('\|', '|', $parts[0]));
            $template_label = trim(str_replace('\|', '|', $parts[1]));
            $template_body = trim(str_replace('\|', '|', $parts[2]));

            if ($department_key === '' || $template_label === '' || $template_body === '')
            {
                continue;
            }

            if ($department_key !== '*' && !isset($departments[$department_key]))
            {
                continue;
            }

            $rows[] = [
                'department' => $department_key,
                'label' => $template_label,
                'body' => str_replace(['\r\n', '\n', '\r'], ["\n", "\n", "\n"], $template_body),
            ];
        }

        return $rows;
    }

    protected function build_department_reply_templates_from_arrays(array $departments_in, array $titles_in, array $bodies_in, array $departments, &$removed)
    {
        $keys = array_unique(array_merge(array_keys($departments_in), array_keys($titles_in), array_keys($bodies_in)));
        sort($keys);

        $cleaned = [];
        $removed = 0;

        foreach ($keys as $row_id)
        {
            $department_key = trim((string) (isset($departments_in[$row_id]) ? $departments_in[$row_id] : ''));
            $template_label = trim((string) (isset($titles_in[$row_id]) ? $titles_in[$row_id] : ''));
            $template_body = trim((string) (isset($bodies_in[$row_id]) ? $bodies_in[$row_id] : ''));

            if ($department_key === '' && $template_label === '' && $template_body === '')
            {
                continue;
            }

            if ($department_key === '' || $template_label === '' || $template_body === '')
            {
                $removed++;
                continue;
            }

            if ($department_key !== '*' && !isset($departments[$department_key]))
            {
                $removed++;
                continue;
            }

            $department_key = str_replace('|', '\|', $department_key);
            $template_label = str_replace('|', '\|', $template_label);
            $template_body = str_replace(["\r\n", "\r", "\n"], '\n', $template_body);
            $template_body = str_replace('|', '\|', $template_body);

            $cleaned[] = $department_key . '|' . $template_label . '|' . $template_body;
        }

        return implode("\n", $cleaned);
    }

    protected function build_department_reply_template_form_rows(array $rows, array $departments, $user, $blank_rows = 4)
    {
        $result = [];
        $row_index = 0;

        foreach ($rows as $row)
        {
            $result[] = [
                'ROW_ID' => $row_index,
                'DEPARTMENT_OPTIONS_HTML' => $this->build_department_reply_template_department_options(isset($row['department']) ? $row['department'] : '', $departments, $user),
                'TITLE' => isset($row['label']) ? (string) $row['label'] : '',
                'BODY' => isset($row['body']) ? (string) $row['body'] : '',
            ];
            $row_index++;
        }

        $department_count = count($departments);
        $minimum_total_rows = max((int) $blank_rows, $department_count + 1);
        $total_blank_rows = max(1, $minimum_total_rows - count($result));

        for ($i = 0; $i < $total_blank_rows; $i++)
        {
            $result[] = [
                'ROW_ID' => $row_index,
                'DEPARTMENT_OPTIONS_HTML' => $this->build_department_reply_template_department_options('', $departments, $user),
                'TITLE' => '',
                'BODY' => '',
            ];
            $row_index++;
        }

        return $result;
    }

    protected function build_department_reply_template_department_options($selected, array $departments, $user)
    {
        $selected = trim((string) $selected);
        $html = '<option value="">' . \utf8_htmlspecialchars((string) $user->lang('ACP_HELPDESK_REPLY_TEMPLATE_SELECT_DEPARTMENT')) . '</option>';
        $html .= '<option value="*"' . ($selected === '*' ? ' selected="selected"' : '') . '>' . \utf8_htmlspecialchars((string) $user->lang('ACP_HELPDESK_REPLY_TEMPLATE_GLOBAL')) . '</option>';

        foreach ($departments as $department_key => $definition)
        {
            $label = $this->department_label_for_user($definition, $department_key, $user);
            $html .= '<option value="' . \utf8_htmlspecialchars((string) $department_key) . '"' . ($selected === (string) $department_key ? ' selected="selected"' : '') . '>' . \utf8_htmlspecialchars((string) $label) . '</option>';
        }

        return $html;
    }

    protected function sanitize_department_reply_templates($raw, array $departments, &$removed)
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
        $cleaned = [];
        $removed = 0;

        foreach ($lines as $line)
        {
            $line = trim((string) $line);
            if ($line === '' || strpos($line, '#') === 0)
            {
                continue;
            }
            $parts = $this->split_escaped_pipe_parts($line, 3);
            if (count($parts) !== 3)
            {
                $removed++;
                continue;
            }

            $department_key = trim(str_replace('\\|', '|', $parts[0]));
            $template_label = trim(str_replace('\\|', '|', $parts[1]));
            $template_body = trim(str_replace('\\|', '|', $parts[2]));

            if ($department_key === '' || $template_label === '' || $template_body === '')
            {
                $removed++;
                continue;
            }

            if ($department_key !== '*' && !isset($departments[$department_key]))
            {
                $removed++;
                continue;
            }

            $cleaned[] = $department_key . '|' . $template_label . '|' . $template_body;
        }

        return implode("\n", $cleaned);
    }


    protected function split_escaped_pipe_parts($line, $limit)
    {
        $parts = [];
        $buffer = '';
        $length = function_exists('utf8_strlen') ? utf8_strlen((string) $line) : strlen((string) $line);
        $escape = false;

        for ($i = 0; $i < $length; $i++)
        {
            $char = function_exists('utf8_substr') ? utf8_substr($line, $i, 1) : substr($line, $i, 1);

            if ($escape)
            {
                $buffer .= $char;
                $escape = false;
                continue;
            }

            if ($char === '\\')
            {
                $escape = true;
                $buffer .= $char;
                continue;
            }

            if ($char === '|' && count($parts) < ($limit - 1))
            {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $parts[] = $buffer;

        return $parts;
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
            $config->set('mundophpbb_helpdesk_require_reason_status', 0);
            $config->set('mundophpbb_helpdesk_require_reason_priority', 0);
            $config->set('mundophpbb_helpdesk_require_reason_assignment', 0);
            $config->set('mundophpbb_helpdesk_team_panel_enable', 1);
            $config->set('mundophpbb_helpdesk_alerts_enable', 1);
            $config->set('mundophpbb_helpdesk_alert_hours', 24);
            $config->set('mundophpbb_helpdesk_alert_limit', 15);
            $config->set('mundophpbb_helpdesk_sla_enable', 1);
            $config->set('mundophpbb_helpdesk_sla_hours', 24);
            $config->set('mundophpbb_helpdesk_stale_hours', 72);
            $config->set('mundophpbb_helpdesk_old_hours', 168);
            $config->set('mundophpbb_helpdesk_department_reply_templates', '');
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
            $config->set('mundophpbb_helpdesk_department_auto_profile_definitions', '');
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


    protected function build_setup_assistant_state($config, $user, array $setup_summary = [])
    {
        $selected_forums = $this->parse_forum_ids(isset($config['mundophpbb_helpdesk_forums']) ? (string) $config['mundophpbb_helpdesk_forums'] : '');
        $forums = $this->fetch_setup_forums($selected_forums);
        $groups = $this->fetch_available_groups($user);
        $selected_groups = !empty($setup_summary['selected_groups']) ? $setup_summary['selected_groups'] : [];

        return [
            'forums' => $forums,
            'groups' => $groups,
            'selected' => [
                'admin_group_id' => isset($selected_groups['admin_group_id']) ? (int) $selected_groups['admin_group_id'] : $this->find_group_id_by_name($groups, 'ADMINISTRATORS'),
                'supervisor_group_id' => isset($selected_groups['supervisor_group_id']) ? (int) $selected_groups['supervisor_group_id'] : 0,
                'agent_group_id' => isset($selected_groups['agent_group_id']) ? (int) $selected_groups['agent_group_id'] : 0,
                'auditor_group_id' => isset($selected_groups['auditor_group_id']) ? (int) $selected_groups['auditor_group_id'] : 0,
                'customer_group_id' => isset($selected_groups['customer_group_id']) ? (int) $selected_groups['customer_group_id'] : $this->find_group_id_by_name($groups, 'REGISTERED'),
                'readonly_group_id' => isset($selected_groups['readonly_group_id']) ? (int) $selected_groups['readonly_group_id'] : 0,
            ],
        ];
    }


    protected function build_setup_summary(array $forum_ids, $user)
    {
        $groups = $this->fetch_available_groups($user);
        $groups_by_id = [];
        foreach ($groups as $group)
        {
            $groups_by_id[(int) $group['group_id']] = (string) $group['display_name'];
        }

        $forum_names = $this->fetch_forum_names($forum_ids);
        $forum_rows = [];
        foreach ($forum_ids as $forum_id)
        {
            $forum_rows[] = [
                'FORUM_ID' => (int) $forum_id,
                'FORUM_NAME' => isset($forum_names[$forum_id]) ? $forum_names[$forum_id] : $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_FORUM_UNKNOWN', $forum_id),
            ];
        }

        $role_order = [
            'Help Desk Administrator' => ['type' => 'a_', 'field' => 'admin_group_id'],
            'Help Desk Supervisor' => ['type' => 'm_', 'field' => 'supervisor_group_id'],
            'Help Desk Agent' => ['type' => 'm_', 'field' => 'agent_group_id'],
            'Help Desk Auditor' => ['type' => 'm_', 'field' => 'auditor_group_id'],
            'Help Desk Customer' => ['type' => 'f_', 'field' => 'customer_group_id'],
            'Help Desk Read Only' => ['type' => 'f_', 'field' => 'readonly_group_id'],
        ];

        $role_ids = $this->fetch_role_ids(array_keys($role_order));
        $selected_groups = [];
        $role_rows = [];
        $assigned_role_count = 0;

        foreach ($role_order as $role_name => $meta)
        {
            $role_id = isset($role_ids[$role_name]) ? (int) $role_ids[$role_name] : 0;
            $scope_forum_ids = ($meta['type'] === 'a_') ? [0] : $forum_ids;
            $assignments = $this->collect_group_role_scope_rows($role_id, $scope_forum_ids, $forum_names, $groups_by_id, $user, $meta['type'] === 'a_');

            if (count($assignments) === 1)
            {
                $selected_groups[$meta['field']] = (int) $assignments[0]['GROUP_ID'];
            }

            if (!empty($assignments))
            {
                $assigned_role_count++;
                foreach ($assignments as $assignment)
                {
                    $role_rows[] = array_merge([
                        'ROLE_NAME' => $this->localized_helpdesk_role_name($role_name, $user),
                    ], $assignment);
                }
            }
        }

        return [
            'forums' => $forum_rows,
            'roles' => $role_rows,
            'forum_count' => count($forum_rows),
            'assigned_role_count' => $assigned_role_count,
            'row_count' => count($role_rows),
            'selected_groups' => $selected_groups,
        ];
    }

    protected function collect_group_role_scope_rows($role_id, array $forum_ids, array $forum_names, array $groups_by_id, $user, $is_global = false)
    {
        global $db;

        if ($role_id <= 0 || empty($forum_ids))
        {
            return [];
        }

        $sql = 'SELECT group_id, forum_id
            FROM ' . ACL_GROUPS_TABLE . '
            WHERE auth_role_id = ' . (int) $role_id . '
                AND ' . $db->sql_in_set('forum_id', array_values(array_unique(array_map('intval', $forum_ids)))) . '
            ORDER BY group_id ASC, forum_id ASC';
        $result = $db->sql_query($sql);

        $by_group = [];
        while ($row = $db->sql_fetchrow($result))
        {
            $group_id = (int) $row['group_id'];
            $forum_id = (int) $row['forum_id'];
            if (!isset($by_group[$group_id]))
            {
                $by_group[$group_id] = [];
            }
            $by_group[$group_id][$forum_id] = true;
        }
        $db->sql_freeresult($result);

        $rows = [];
        foreach ($by_group as $group_id => $forums)
        {
            $forum_id_list = array_keys($forums);
            sort($forum_id_list);

            if ($is_global)
            {
                $scope_label = $user->lang('ACP_HELPDESK_SETUP_SUMMARY_SCOPE_GLOBAL');
                $forum_count = 1;
            }
            else
            {
                $forum_labels = [];
                foreach ($forum_id_list as $forum_id)
                {
                    $forum_labels[] = isset($forum_names[$forum_id]) ? $forum_names[$forum_id] : $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_FORUM_UNKNOWN', $forum_id);
                }
                $forum_count = count($forum_labels);
                $scope_label = implode(', ', $forum_labels);
            }

            $rows[] = [
                'GROUP_ID' => $group_id,
                'GROUP_NAME' => isset($groups_by_id[$group_id]) ? $groups_by_id[$group_id] : ('#' . $group_id),
                'FORUM_COUNT' => $forum_count,
                'SCOPE' => $scope_label,
            ];
        }

        return $rows;
    }

    protected function fetch_setup_forums(array $selected_forums = [])
    {
        global $db;

        $rows = [];
        $forum_post_type = defined('FORUM_POST') ? (int) FORUM_POST : 1;
        $sql = 'SELECT forum_id, forum_name, forum_type, left_id, right_id
            FROM ' . FORUMS_TABLE . '
            ORDER BY left_id ASC';
        $result = $db->sql_query($sql);

        $stack = [];
        while ($row = $db->sql_fetchrow($result))
        {
            while (!empty($stack) && end($stack) < (int) $row['left_id'])
            {
                array_pop($stack);
            }

            $level = count($stack);
            if ((int) $row['forum_type'] === $forum_post_type)
            {
                $rows[] = [
                    'forum_id' => (int) $row['forum_id'],
                    'FORUM_ID' => (int) $row['forum_id'],
                    'FORUM_NAME' => str_repeat('— ', max(0, $level)) . (string) $row['forum_name'],
                    'S_SELECTED' => in_array((int) $row['forum_id'], $selected_forums, true),
                ];
            }

            $stack[] = (int) $row['right_id'];
        }
        $db->sql_freeresult($result);

        return $rows;
    }

    protected function fetch_available_groups($user)
    {
        global $db;

        $sql = 'SELECT group_id, group_name
            FROM ' . GROUPS_TABLE . '
            ORDER BY group_type DESC, group_name ASC';
        $result = $db->sql_query($sql);

        $groups = [];
        while ($row = $db->sql_fetchrow($result))
        {
            $groups[] = [
                'group_id' => (int) $row['group_id'],
                'group_name' => (string) $row['group_name'],
                'display_name' => $this->display_group_name((string) $row['group_name'], $user),
            ];
        }
        $db->sql_freeresult($result);

        return $groups;
    }

    protected function acl_group_display_name(array $group_row)
    {
        global $user;

        return $this->display_group_name(
            isset($group_row['group_name']) ? (string) $group_row['group_name'] : '',
            $user
        );
    }

    protected function display_group_name($group_name, $user)
    {
        $lang_key = 'G_' . strtoupper((string) $group_name);
        $translated = $user->lang($lang_key);
        if ($translated !== $lang_key)
        {
            return $translated;
        }

        $raw = (string) $group_name;
        if (preg_match('/^[A-Z0-9_]+$/', $raw))
        {
            return ucwords(strtolower(str_replace('_', ' ', $raw)));
        }

        return $raw;
    }

    protected function find_group_id_by_name(array $groups, $group_name)
    {
        foreach ($groups as $group)
        {
            if (isset($group['group_name']) && strtoupper((string) $group['group_name']) === strtoupper((string) $group_name))
            {
                return (int) $group['group_id'];
            }
        }

        return 0;
    }

    protected function group_name_by_id($group_id, array $groups)
    {
        foreach ($groups as $group)
        {
            if ((int) $group['group_id'] === (int) $group_id)
            {
                return (string) $group['display_name'];
            }
        }

        return '#' . (int) $group_id;
    }

    protected function assign_setup_group_options($template, $block_name, array $groups, $selected_group_id)
    {
        foreach ($groups as $group)
        {
            $template->assign_block_vars($block_name, [
                'GROUP_ID' => (int) $group['group_id'],
                'GROUP_NAME' => (string) $group['display_name'],
                'S_SELECTED' => (int) $group['group_id'] === (int) $selected_group_id,
            ]);
        }
    }

    protected function apply_setup_assistant(array $forum_ids, array $setup, array $groups, $config, $user)
    {
        global $db, $auth;

        $role_matrix = $this->helpdesk_role_matrix();
        $role_ids = $this->fetch_role_ids(array_keys($role_matrix));

        $all_permissions = [];
        foreach ($role_matrix as $definition)
        {
            foreach ($definition['permissions'] as $permission)
            {
                $all_permissions[$permission] = $permission;
            }
        }
        $auth_option_ids = $this->fetch_auth_option_ids(array_values($all_permissions));

        sort($forum_ids);
        $config->set('mundophpbb_helpdesk_enable', 1);
        $config->set('mundophpbb_helpdesk_forums', implode(',', $forum_ids));

        $messages = [];

        if (!empty($setup['admin_group_id']) && isset($role_ids['Help Desk Administrator']))
        {
            $this->clear_helpdesk_group_permissions((int) $setup['admin_group_id'], 0, 'a_', $role_ids, $role_matrix, $auth_option_ids);
            $this->assign_helpdesk_role_to_group((int) $setup['admin_group_id'], 0, (int) $role_ids['Help Desk Administrator']);
            $messages[] = $user->lang('ACP_HELPDESK_SETUP_RESULT_ADMIN', $this->group_name_by_id((int) $setup['admin_group_id'], $groups));
        }

        $forum_role_map = [
            'supervisor_group_id' => 'Help Desk Supervisor',
            'agent_group_id' => 'Help Desk Agent',
            'auditor_group_id' => 'Help Desk Auditor',
            'customer_group_id' => 'Help Desk Customer',
            'readonly_group_id' => 'Help Desk Read Only',
        ];

        foreach ($forum_role_map as $field => $role_name)
        {
            $group_id = isset($setup[$field]) ? (int) $setup[$field] : 0;
            if ($group_id <= 0 || !isset($role_ids[$role_name]) || empty($forum_ids))
            {
                continue;
            }

            $type = $role_matrix[$role_name]['type'];
            foreach ($forum_ids as $forum_id)
            {
                $this->clear_helpdesk_group_permissions($group_id, (int) $forum_id, $type, $role_ids, $role_matrix, $auth_option_ids);
                $this->assign_helpdesk_role_to_group($group_id, (int) $forum_id, (int) $role_ids[$role_name]);
            }

            $messages[] = $user->lang('ACP_HELPDESK_SETUP_RESULT_FORUM_ROLE', $this->localized_helpdesk_role_name($role_name, $user), $this->group_name_by_id($group_id, $groups), count($forum_ids));
        }

        if (is_object($auth) && method_exists($auth, 'acl_clear_prefetch'))
        {
            $auth->acl_clear_prefetch();
        }

        return $messages;
    }

    protected function clear_helpdesk_group_permissions($group_id, $forum_id, $type, array $role_ids_by_name, array $role_matrix, array $auth_option_ids)
    {
        global $db;

        $type_role_ids = [];
        $type_permissions = [];
        foreach ($role_matrix as $role_name => $definition)
        {
            if ($definition['type'] !== $type)
            {
                continue;
            }

            if (isset($role_ids_by_name[$role_name]))
            {
                $type_role_ids[] = (int) $role_ids_by_name[$role_name];
            }
            foreach ($definition['permissions'] as $permission)
            {
                $type_permissions[$permission] = $permission;
            }
        }

        $type_option_ids = $this->filter_option_ids($auth_option_ids, array_values($type_permissions));
        $conditions = [];
        if (!empty($type_role_ids))
        {
            $conditions[] = $db->sql_in_set('auth_role_id', $type_role_ids);
        }
        if (!empty($type_option_ids))
        {
            $conditions[] = '(auth_role_id = 0 AND ' . $db->sql_in_set('auth_option_id', $type_option_ids) . ')';
        }

        if (empty($conditions))
        {
            return;
        }

        $sql = 'DELETE FROM ' . ACL_GROUPS_TABLE . '
            WHERE group_id = ' . (int) $group_id . '
                AND forum_id = ' . (int) $forum_id . '
                AND (' . implode(' OR ', $conditions) . ')';
        $db->sql_query($sql);
    }

    protected function assign_helpdesk_role_to_group($group_id, $forum_id, $role_id)
    {
        global $db;

        $sql = 'INSERT INTO ' . ACL_GROUPS_TABLE . ' ' . $db->sql_build_array('INSERT', [
            'group_id' => (int) $group_id,
            'forum_id' => (int) $forum_id,
            'auth_option_id' => 0,
            'auth_role_id' => (int) $role_id,
            'auth_setting' => 0,
        ]);
        $db->sql_query($sql);
    }

    protected function build_permission_diagnostics(array $forum_ids, $user)
    {
        $role_matrix = $this->helpdesk_role_matrix();
        $forum_names = $this->fetch_forum_names($forum_ids);
        $missing_forum_ids = array_values(array_diff($forum_ids, array_keys($forum_names)));

        $all_permissions = [];
        foreach ($role_matrix as $role)
        {
            foreach ($role['permissions'] as $permission)
            {
                $all_permissions[$permission] = $permission;
            }
        }

        foreach (['f_list', 'f_read', 'f_post'] as $permission)
        {
            $all_permissions[$permission] = $permission;
        }

        $auth_option_ids = $this->fetch_auth_option_ids(array_values($all_permissions));
        $role_ids = $this->fetch_role_ids(array_keys($role_matrix));

        $admin_permissions = ['a_helpdesk_manage'];
        $staff_permissions = ['m_helpdesk_queue', 'm_helpdesk_manage', 'm_helpdesk_assign', 'm_helpdesk_bulk'];
        $forum_permissions = ['f_helpdesk_view', 'f_helpdesk_ticket'];

        $admin_role_ids = $this->filter_role_ids_by_type($role_matrix, $role_ids, 'a_');
        $staff_role_ids = $this->filter_role_ids_by_type($role_matrix, $role_ids, 'm_');
        $forum_role_ids = $this->filter_role_ids_by_type($role_matrix, $role_ids, 'f_');

        $admin_assignment = $this->collect_acl_assignment_summary([0], $admin_role_ids, $this->filter_option_ids($auth_option_ids, $admin_permissions));
        $staff_assignment = $this->collect_acl_assignment_summary($forum_ids, $staff_role_ids, $this->filter_option_ids($auth_option_ids, $staff_permissions));
        $customer_assignment = $this->collect_acl_assignment_summary($forum_ids, $forum_role_ids, $this->filter_option_ids($auth_option_ids, $forum_permissions));
        $role_assignment_counts = $this->collect_role_assignment_counts($role_ids, array_merge([0], $forum_ids));

        $groups = $this->fetch_available_groups($user);
        $groups_by_id = [];
        foreach ($groups as $group)
        {
            $groups_by_id[(int) $group['group_id']] = (string) $group['display_name'];
        }

        $role_rows = [];
        $valid_role_count = 0;
        $missing_roles = [];
        $invalid_roles = [];

        foreach ($role_matrix as $role_name => $definition)
        {
            $role_id = isset($role_ids[$role_name]) ? (int) $role_ids[$role_name] : 0;
            $is_valid = $role_id > 0 && $this->role_has_expected_permissions($role_id, $definition['permissions'], $auth_option_ids);
            if ($is_valid)
            {
                $valid_role_count++;
            }
            else if ($role_id <= 0)
            {
                $missing_roles[] = $role_name;
            }
            else
            {
                $invalid_roles[] = $role_name;
            }

            $role_rows[] = [
                'NAME' => $this->localized_helpdesk_role_name($role_name, $user),
                'TYPE' => $this->permission_type_label($definition['type'], $user),
                'EXPECTED' => implode(', ', $definition['permissions']),
                'ASSIGNMENTS' => isset($role_assignment_counts[$role_id]) ? (int) $role_assignment_counts[$role_id] : 0,
                'STATUS_CLASS' => $is_valid ? 'ok' : 'warning',
                'STATUS_LABEL' => $user->lang($is_valid ? 'ACP_HELPDESK_PERMISSION_DIAGNOSTIC_ROLE_OK' : 'ACP_HELPDESK_PERMISSION_DIAGNOSTIC_ROLE_WARNING'),
            ];
        }

        $forum_rows = [];
        $staff_empty_forums = [];
        $customer_empty_forums = [];

        foreach ($forum_ids as $forum_id)
        {
            $forum_name = isset($forum_names[$forum_id]) ? $forum_names[$forum_id] : $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_FORUM_UNKNOWN', $forum_id);
            $staff_total = isset($staff_assignment['per_forum'][$forum_id]) ? (int) $staff_assignment['per_forum'][$forum_id]['total'] : 0;
            $customer_total = isset($customer_assignment['per_forum'][$forum_id]) ? (int) $customer_assignment['per_forum'][$forum_id]['total'] : 0;
            $is_ready = ($staff_total > 0 && $customer_total > 0);

            if ($staff_total === 0)
            {
                $staff_empty_forums[] = $forum_name;
            }
            if ($customer_total === 0)
            {
                $customer_empty_forums[] = $forum_name;
            }

            $forum_rows[] = [
                'FORUM_ID' => $forum_id,
                'FORUM_NAME' => $forum_name,
                'STAFF_ASSIGNMENTS' => $staff_total,
                'CUSTOMER_ASSIGNMENTS' => $customer_total,
                'STATUS_CLASS' => $is_ready ? 'ok' : 'warning',
                'STATUS_LABEL' => $user->lang($is_ready ? 'ACP_HELPDESK_PERMISSION_DIAGNOSTIC_FORUM_READY' : 'ACP_HELPDESK_PERMISSION_DIAGNOSTIC_FORUM_ATTENTION'),
            ];
        }

        $customer_checks = [];
        $customer_incomplete_rows = [];
        $customer_ready_count = 0;
        $customer_assignments_by_forum = $this->collect_group_role_assignments_by_forum($forum_ids, isset($role_ids['Help Desk Customer']) ? (int) $role_ids['Help Desk Customer'] : 0);

        foreach ($customer_assignments_by_forum as $forum_id => $group_ids)
        {
            $forum_name = isset($forum_names[$forum_id]) ? $forum_names[$forum_id] : $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_FORUM_UNKNOWN', $forum_id);
            foreach ($group_ids as $group_id)
            {
                $has_helpdesk_view = $this->group_has_forum_permission($group_id, $forum_id, 'f_helpdesk_view', $auth_option_ids);
                $has_helpdesk_ticket = $this->group_has_forum_permission($group_id, $forum_id, 'f_helpdesk_ticket', $auth_option_ids);
                $has_forum_list = $this->group_has_forum_permission($group_id, $forum_id, 'f_list', $auth_option_ids);
                $has_forum_read = $this->group_has_forum_permission($group_id, $forum_id, 'f_read', $auth_option_ids);
                $has_forum_post = $this->group_has_forum_permission($group_id, $forum_id, 'f_post', $auth_option_ids);

                $missing_permissions = [];
                if (!$has_helpdesk_view)
                {
                    $missing_permissions[] = $this->customer_permission_label('f_helpdesk_view', $user);
                }
                if (!$has_helpdesk_ticket)
                {
                    $missing_permissions[] = $this->customer_permission_label('f_helpdesk_ticket', $user);
                }
                if (!$has_forum_list)
                {
                    $missing_permissions[] = $this->customer_permission_label('f_list', $user);
                }
                if (!$has_forum_read)
                {
                    $missing_permissions[] = $this->customer_permission_label('f_read', $user);
                }
                if (!$has_forum_post)
                {
                    $missing_permissions[] = $this->customer_permission_label('f_post', $user);
                }

                $is_ready = empty($missing_permissions);
                if ($is_ready)
                {
                    $customer_ready_count++;
                }
                else
                {
                    $customer_incomplete_rows[] = $forum_name . ' / ' . (isset($groups_by_id[$group_id]) ? $groups_by_id[$group_id] : ('#' . $group_id));
                }

                $customer_checks[] = [
                    'FORUM_ID' => (int) $forum_id,
                    'FORUM_NAME' => $forum_name,
                    'GROUP_ID' => (int) $group_id,
                    'GROUP_NAME' => isset($groups_by_id[$group_id]) ? $groups_by_id[$group_id] : ('#' . $group_id),
                    'HAS_HELPDESK_VIEW' => $this->yes_no_label($has_helpdesk_view, $user),
                    'HAS_HELPDESK_TICKET' => $this->yes_no_label($has_helpdesk_ticket, $user),
                    'HAS_FORUM_LIST' => $this->yes_no_label($has_forum_list, $user),
                    'HAS_FORUM_READ' => $this->yes_no_label($has_forum_read, $user),
                    'HAS_FORUM_POST' => $this->yes_no_label($has_forum_post, $user),
                    'DETAILS' => $is_ready ? $user->lang('ACP_HELPDESK_PERMISSION_CUSTOMER_CHECK_READY_NOTE') : $user->lang('ACP_HELPDESK_PERMISSION_CUSTOMER_CHECK_MISSING_NOTE', implode(', ', $missing_permissions)),
                    'STATUS_CLASS' => $is_ready ? 'ok' : 'warning',
                    'STATUS_LABEL' => $user->lang($is_ready ? 'ACP_HELPDESK_PERMISSION_CUSTOMER_CHECK_READY' : 'ACP_HELPDESK_PERMISSION_CUSTOMER_CHECK_INCOMPLETE'),
                ];
            }
        }

        $warnings = [];
        if (empty($forum_ids))
        {
            $warnings[] = $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_WARNING_NO_TRACKED_FORUMS');
        }
        if (!empty($missing_forum_ids))
        {
            $warnings[] = $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_WARNING_MISSING_FORUMS', implode(', ', $missing_forum_ids));
        }
        if ((int) $admin_assignment['total'] === 0)
        {
            $warnings[] = $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_WARNING_NO_ADMIN');
        }
        if (!empty($staff_empty_forums))
        {
            $warnings[] = $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_WARNING_NO_STAFF', implode(', ', $staff_empty_forums));
        }
        if (!empty($customer_empty_forums))
        {
            $warnings[] = $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_WARNING_NO_CUSTOMERS', implode(', ', $customer_empty_forums));
        }
        if (!empty($customer_incomplete_rows))
        {
            $warnings[] = $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_WARNING_CUSTOMER_BASE_PERMS', implode(', ', $customer_incomplete_rows));
        }
        if (!empty($missing_roles))
        {
            $warnings[] = $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_WARNING_MISSING_ROLES', $this->localized_helpdesk_role_names($missing_roles, $user));
        }
        if (!empty($invalid_roles))
        {
            $warnings[] = $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_WARNING_INVALID_ROLES', $this->localized_helpdesk_role_names($invalid_roles, $user));
        }

        return [
            'warnings' => $warnings,
            'warning_count' => count($warnings),
            'tracked_forum_count' => count($forum_ids),
            'admin_assignments' => (int) $admin_assignment['total'],
            'staff_assignments' => (int) $staff_assignment['total'],
            'customer_assignments' => (int) $customer_assignment['total'],
            'customer_ready_count' => $customer_ready_count,
            'customer_check_count' => count($customer_checks),
            'valid_role_count' => $valid_role_count,
            'role_count' => count($role_matrix),
            'forums' => $forum_rows,
            'customer_checks' => $customer_checks,
            'roles' => $role_rows,
        ];
    }

    protected function collect_group_role_assignments_by_forum(array $forum_ids, $role_id)
    {
        global $db;

        if ($role_id <= 0 || empty($forum_ids))
        {
            return [];
        }

        $sql = 'SELECT forum_id, group_id
            FROM ' . ACL_GROUPS_TABLE . '
            WHERE auth_role_id = ' . (int) $role_id . '
                AND ' . $db->sql_in_set('forum_id', array_values(array_unique(array_map('intval', $forum_ids)))) . '
            ORDER BY forum_id ASC, group_id ASC';
        $result = $db->sql_query($sql);

        $assignments = [];
        while ($row = $db->sql_fetchrow($result))
        {
            $forum_id = (int) $row['forum_id'];
            $group_id = (int) $row['group_id'];
            if (!isset($assignments[$forum_id]))
            {
                $assignments[$forum_id] = [];
            }
            $assignments[$forum_id][$group_id] = $group_id;
        }
        $db->sql_freeresult($result);

        foreach ($assignments as $forum_id => $group_ids)
        {
            $assignments[$forum_id] = array_values($group_ids);
        }

        return $assignments;
    }

    protected function group_has_forum_permission($group_id, $forum_id, $auth_option, array $auth_option_ids)
    {
        global $db;

        $group_id = (int) $group_id;
        $forum_id = (int) $forum_id;
        $auth_option_id = isset($auth_option_ids[$auth_option]) ? (int) $auth_option_ids[$auth_option] : 0;

        if ($group_id <= 0 || $forum_id <= 0 || $auth_option_id <= 0)
        {
            return false;
        }

        $sql = 'SELECT 1 AS granted
            FROM ' . ACL_GROUPS_TABLE . ' ag
            LEFT JOIN ' . ACL_ROLES_DATA_TABLE . ' ard
                ON ard.role_id = ag.auth_role_id
                AND ard.auth_option_id = ' . (int) $auth_option_id . '
            WHERE ag.group_id = ' . (int) $group_id . '
                AND ag.forum_id = ' . (int) $forum_id . '
                AND (
                    (ag.auth_option_id = ' . (int) $auth_option_id . ' AND ag.auth_setting = 1)
                    OR (ag.auth_role_id > 0 AND ard.auth_setting = 1)
                )';
        $result = $db->sql_query_limit($sql, 1);
        $row = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);

        return !empty($row);
    }

    protected function customer_permission_label($auth_option, $user)
    {
        $map = [
            'f_helpdesk_view' => 'ACL_F_HELPDESK_VIEW',
            'f_helpdesk_ticket' => 'ACL_F_HELPDESK_TICKET',
            'f_list' => 'ACL_F_LIST',
            'f_read' => 'ACL_F_READ',
            'f_post' => 'ACL_F_POST',
        ];

        $lang_key = isset($map[$auth_option]) ? $map[$auth_option] : strtoupper((string) $auth_option);
        $label = $user->lang($lang_key);

        return ($label !== $lang_key) ? $label : (string) $auth_option;
    }

    protected function yes_no_label($value, $user)
    {
        return $user->lang($value ? 'YES' : 'NO');
    }

    protected function helpdesk_role_matrix()
    {
        return [
            'Help Desk Administrator' => [
                'type' => 'a_',
                'permissions' => ['a_helpdesk_manage'],
            ],
            'Help Desk Supervisor' => [
                'type' => 'm_',
                'permissions' => ['m_helpdesk_queue', 'm_helpdesk_manage', 'm_helpdesk_assign', 'm_helpdesk_bulk'],
            ],
            'Help Desk Agent' => [
                'type' => 'm_',
                'permissions' => ['m_helpdesk_queue', 'm_helpdesk_manage', 'm_helpdesk_assign'],
            ],
            'Help Desk Auditor' => [
                'type' => 'm_',
                'permissions' => ['m_helpdesk_queue'],
            ],
            'Help Desk Customer' => [
                'type' => 'f_',
                'permissions' => ['f_helpdesk_view', 'f_helpdesk_ticket'],
            ],
            'Help Desk Read Only' => [
                'type' => 'f_',
                'permissions' => ['f_helpdesk_view'],
            ],
        ];
    }

    protected function fetch_forum_names(array $forum_ids)
    {
        global $db;

        if (empty($forum_ids))
        {
            return [];
        }

        $sql = 'SELECT forum_id, forum_name
            FROM ' . FORUMS_TABLE . '
            WHERE ' . $db->sql_in_set('forum_id', array_values($forum_ids));
        $result = $db->sql_query($sql);

        $forums = [];
        while ($row = $db->sql_fetchrow($result))
        {
            $forums[(int) $row['forum_id']] = (string) $row['forum_name'];
        }
        $db->sql_freeresult($result);

        return $forums;
    }

    protected function fetch_auth_option_ids(array $auth_options)
    {
        global $db;

        if (empty($auth_options))
        {
            return [];
        }

        $sql = 'SELECT auth_option, auth_option_id
            FROM ' . ACL_OPTIONS_TABLE . '
            WHERE ' . $db->sql_in_set('auth_option', array_values($auth_options));
        $result = $db->sql_query($sql);

        $map = [];
        while ($row = $db->sql_fetchrow($result))
        {
            $map[(string) $row['auth_option']] = (int) $row['auth_option_id'];
        }
        $db->sql_freeresult($result);

        return $map;
    }


    protected function localized_helpdesk_role_name($role_name, $lang_user = null)
    {
        if ($lang_user === null)
        {
            global $user;
            $lang_user = $user;
        }

        $map = [
            'Help Desk Administrator' => 'ACP_HELPDESK_ROLE_ADMIN',
            'Help Desk Supervisor' => 'ACP_HELPDESK_ROLE_SUPERVISOR',
            'Help Desk Agent' => 'ACP_HELPDESK_ROLE_AGENT',
            'Help Desk Auditor' => 'ACP_HELPDESK_ROLE_AUDITOR',
            'Help Desk Customer' => 'ACP_HELPDESK_ROLE_CUSTOMER',
            'Help Desk Read Only' => 'ACP_HELPDESK_ROLE_READ_ONLY',
        ];

        if (isset($map[$role_name]) && is_object($lang_user) && method_exists($lang_user, 'lang'))
        {
            return $lang_user->lang($map[$role_name]);
        }

        return (string) $role_name;
    }

    protected function localized_helpdesk_role_names(array $role_names, $lang_user = null)
    {
        $labels = [];
        foreach ($role_names as $role_name)
        {
            $labels[] = $this->localized_helpdesk_role_name($role_name, $lang_user);
        }

        return implode(', ', $labels);
    }

    protected function fetch_role_ids(array $role_names)
    {
        global $db;

        if (empty($role_names))
        {
            return [];
        }

        $sql = 'SELECT role_name, role_id
            FROM ' . ACL_ROLES_TABLE . '
            WHERE ' . $db->sql_in_set('role_name', array_values($role_names));
        $result = $db->sql_query($sql);

        $map = [];
        while ($row = $db->sql_fetchrow($result))
        {
            $map[(string) $row['role_name']] = (int) $row['role_id'];
        }
        $db->sql_freeresult($result);

        return $map;
    }

    protected function filter_role_ids_by_type(array $role_matrix, array $role_ids, $type)
    {
        $filtered = [];

        foreach ($role_matrix as $role_name => $definition)
        {
            if ($definition['type'] !== $type || !isset($role_ids[$role_name]))
            {
                continue;
            }

            $filtered[] = (int) $role_ids[$role_name];
        }

        return $filtered;
    }

    protected function filter_option_ids(array $auth_option_ids, array $permissions)
    {
        $filtered = [];

        foreach ($permissions as $permission)
        {
            if (isset($auth_option_ids[$permission]))
            {
                $filtered[] = (int) $auth_option_ids[$permission];
            }
        }

        return $filtered;
    }

    protected function collect_acl_assignment_summary(array $forum_ids, array $role_ids, array $auth_option_ids)
    {
        global $db;

        $summary = [
            'total' => 0,
            'per_forum' => [],
        ];

        if (empty($forum_ids) || (empty($role_ids) && empty($auth_option_ids)))
        {
            return $summary;
        }

        foreach ($forum_ids as $forum_id)
        {
            $summary['per_forum'][(int) $forum_id] = [
                'groups' => 0,
                'users' => 0,
                'total' => 0,
            ];
        }

        $summary['per_forum'] = $this->merge_acl_assignment_rows($summary['per_forum'], $this->fetch_acl_assignment_rows(ACL_GROUPS_TABLE, 'group_id', $forum_ids, $role_ids, $auth_option_ids));
        $summary['per_forum'] = $this->merge_acl_assignment_rows($summary['per_forum'], $this->fetch_acl_assignment_rows(ACL_USERS_TABLE, 'user_id', $forum_ids, $role_ids, $auth_option_ids));

        foreach ($summary['per_forum'] as $forum_id => $row)
        {
            $summary['per_forum'][$forum_id]['total'] = (int) $row['groups'] + (int) $row['users'];
            $summary['total'] += $summary['per_forum'][$forum_id]['total'];
        }

        return $summary;
    }

    protected function fetch_acl_assignment_rows($table, $entity_column, array $forum_ids, array $role_ids, array $auth_option_ids)
    {
        global $db;

        if (empty($forum_ids) || (empty($role_ids) && empty($auth_option_ids)))
        {
            return [];
        }

        $conditions = [];
        if (!empty($role_ids))
        {
            $conditions[] = $db->sql_in_set('auth_role_id', array_values($role_ids));
        }
        if (!empty($auth_option_ids))
        {
            $conditions[] = '(' . $db->sql_in_set('auth_option_id', array_values($auth_option_ids)) . ' AND auth_setting = 1)';
        }

        if (empty($conditions))
        {
            return [];
        }

        $sql = 'SELECT forum_id, COUNT(DISTINCT ' . $entity_column . ') AS entity_count
            FROM ' . $table . '
            WHERE ' . $db->sql_in_set('forum_id', array_values($forum_ids)) . '
                AND (' . implode(' OR ', $conditions) . ')
            GROUP BY forum_id';
        $result = $db->sql_query($sql);

        $rows = [];
        while ($row = $db->sql_fetchrow($result))
        {
            $rows[(int) $row['forum_id']] = (int) $row['entity_count'];
        }
        $db->sql_freeresult($result);

        return [
            'table' => $table,
            'rows' => $rows,
        ];
    }

    protected function merge_acl_assignment_rows(array $summary, array $payload)
    {
        if (empty($payload))
        {
            return $summary;
        }

        $column = ($payload['table'] === ACL_GROUPS_TABLE) ? 'groups' : 'users';
        foreach ($payload['rows'] as $forum_id => $count)
        {
            if (!isset($summary[$forum_id]))
            {
                $summary[$forum_id] = ['groups' => 0, 'users' => 0, 'total' => 0];
            }
            $summary[$forum_id][$column] = (int) $count;
        }

        return $summary;
    }

    protected function collect_role_assignment_counts(array $role_ids_by_name, array $forum_ids)
    {
        global $db;

        $counts = [];
        $role_ids = array_values(array_unique(array_map('intval', array_values($role_ids_by_name))));
        if (empty($role_ids) || empty($forum_ids))
        {
            return $counts;
        }

        foreach ([ACL_GROUPS_TABLE => 'group_id', ACL_USERS_TABLE => 'user_id'] as $table => $entity_column)
        {
            $sql = 'SELECT auth_role_id, forum_id, COUNT(DISTINCT ' . $entity_column . ') AS entity_count
                FROM ' . $table . '
                WHERE ' . $db->sql_in_set('auth_role_id', $role_ids) . '
                    AND ' . $db->sql_in_set('forum_id', array_values($forum_ids)) . '
                GROUP BY auth_role_id, forum_id';
            $result = $db->sql_query($sql);

            while ($row = $db->sql_fetchrow($result))
            {
                $role_id = (int) $row['auth_role_id'];
                if (!isset($counts[$role_id]))
                {
                    $counts[$role_id] = 0;
                }
                $counts[$role_id] += (int) $row['entity_count'];
            }
            $db->sql_freeresult($result);
        }

        return $counts;
    }

    protected function role_has_expected_permissions($role_id, array $permissions, array $auth_option_ids)
    {
        global $db;

        if ($role_id <= 0)
        {
            return false;
        }

        $expected_ids = [];
        foreach ($permissions as $permission)
        {
            if (!isset($auth_option_ids[$permission]))
            {
                return false;
            }
            $expected_ids[(int) $auth_option_ids[$permission]] = true;
        }

        $sql = 'SELECT auth_option_id, auth_setting
            FROM ' . ACL_ROLES_DATA_TABLE . '
            WHERE role_id = ' . (int) $role_id;
        $result = $db->sql_query($sql);

        $current = [];
        while ($row = $db->sql_fetchrow($result))
        {
            $current[(int) $row['auth_option_id']] = (int) $row['auth_setting'];
        }
        $db->sql_freeresult($result);

        foreach (array_keys($expected_ids) as $auth_option_id)
        {
            if (!isset($current[$auth_option_id]) || (int) $current[$auth_option_id] !== 1)
            {
                return false;
            }
        }

        return true;
    }

    protected function permission_type_label($type, $user)
    {
        switch ((string) $type)
        {
            case 'a_':
                return $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_TYPE_ADMIN');

            case 'm_':
                return $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_TYPE_MOD');

            case 'f_':
                return $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_TYPE_FORUM');
        }

        return (string) $type;
    }


    protected function build_permission_probe_state(array $forum_ids, $user, $request)
    {
        $forum_names = $this->fetch_forum_names($forum_ids);
        $selected_forum_id = (int) $request->variable('helpdesk_permission_probe_forum', !empty($forum_ids) ? (int) reset($forum_ids) : 0);
        if ($selected_forum_id > 0 && !in_array($selected_forum_id, $forum_ids, true))
        {
            $selected_forum_id = !empty($forum_ids) ? (int) reset($forum_ids) : 0;
        }

        $forum_options = [];
        foreach ($forum_ids as $forum_id)
        {
            $forum_options[] = [
                'FORUM_ID' => (int) $forum_id,
                'FORUM_NAME' => isset($forum_names[$forum_id]) ? $forum_names[$forum_id] : $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_FORUM_UNKNOWN', (int) $forum_id),
                'S_SELECTED' => (int) $forum_id === (int) $selected_forum_id,
            ];
        }

        $state = [
            'forum_options' => $forum_options,
            'selected_forum_id' => $selected_forum_id,
            'username_input' => trim((string) $request->variable('helpdesk_permission_probe_username', '', true)),
            'has_result' => false,
            'error' => '',
            'status_class' => 'info',
            'status_label' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_IDLE'),
            'user_label' => '',
            'forum_label' => isset($forum_names[$selected_forum_id]) ? $forum_names[$selected_forum_id] : '',
            'groups_label' => '',
            'note' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_NOTE_IDLE'),
            'summary_rows' => [],
            'permission_rows' => [],
            'warnings' => [],
        ];

        if (!$request->is_set_post('run_permission_probe'))
        {
            return $state;
        }

        if ($state['username_input'] === '')
        {
            $state['error'] = $user->lang('ACP_HELPDESK_PERMISSION_PROBE_ERROR_NO_USERNAME');
            $state['status_class'] = 'warning';
            $state['status_label'] = $user->lang('ACP_HELPDESK_PERMISSION_PROBE_STATUS_REVIEW');
            return $state;
        }

        if ($selected_forum_id <= 0)
        {
            $state['error'] = $user->lang('ACP_HELPDESK_PERMISSION_PROBE_ERROR_NO_FORUM');
            $state['status_class'] = 'warning';
            $state['status_label'] = $user->lang('ACP_HELPDESK_PERMISSION_PROBE_STATUS_REVIEW');
            return $state;
        }

        $user_row = $this->fetch_user_row_by_name($state['username_input']);
        if (empty($user_row))
        {
            $state['error'] = $user->lang('ACP_HELPDESK_PERMISSION_PROBE_ERROR_USER_NOT_FOUND', $state['username_input']);
            $state['status_class'] = 'warning';
            $state['status_label'] = $user->lang('ACP_HELPDESK_PERMISSION_PROBE_STATUS_REVIEW');
            return $state;
        }

        $group_rows = $this->fetch_user_groups((int) $user_row['user_id']);
        $group_ids = array_column($group_rows, 'group_id');
        $group_names = array_column($group_rows, 'display_name');
        $auth_option_ids = $this->fetch_auth_option_ids(['a_helpdesk_manage', 'm_helpdesk_queue', 'm_helpdesk_manage', 'm_helpdesk_assign', 'm_helpdesk_bulk', 'f_helpdesk_view', 'f_helpdesk_ticket', 'f_list', 'f_read', 'f_post']);

        $probe_permissions = [
            'a_helpdesk_manage' => [
                'label' => $this->permission_label('a_helpdesk_manage', $user),
                'scope' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_SCOPE_GLOBAL'),
                'note' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_NOTE_ACP'),
            ],
            'm_helpdesk_queue' => [
                'label' => $this->permission_label('m_helpdesk_queue', $user),
                'scope' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_SCOPE_FORUM'),
                'note' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_NOTE_TEAM_QUEUE'),
            ],
            'm_helpdesk_manage' => [
                'label' => $this->permission_label('m_helpdesk_manage', $user),
                'scope' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_SCOPE_FORUM'),
                'note' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_NOTE_TEAM_MANAGE'),
            ],
            'm_helpdesk_assign' => [
                'label' => $this->permission_label('m_helpdesk_assign', $user),
                'scope' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_SCOPE_FORUM'),
                'note' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_NOTE_ASSIGN'),
            ],
            'm_helpdesk_bulk' => [
                'label' => $this->permission_label('m_helpdesk_bulk', $user),
                'scope' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_SCOPE_FORUM'),
                'note' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_NOTE_BULK'),
            ],
            'f_helpdesk_view' => [
                'label' => $this->permission_label('f_helpdesk_view', $user),
                'scope' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_SCOPE_FORUM'),
                'note' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_NOTE_CUSTOMER_VIEW'),
            ],
            'f_helpdesk_ticket' => [
                'label' => $this->permission_label('f_helpdesk_ticket', $user),
                'scope' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_SCOPE_FORUM'),
                'note' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_NOTE_CUSTOMER_TICKET'),
            ],
            'f_list' => [
                'label' => $this->permission_label('f_list', $user),
                'scope' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_SCOPE_FORUM'),
                'note' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_NOTE_PHPBB_LIST'),
            ],
            'f_read' => [
                'label' => $this->permission_label('f_read', $user),
                'scope' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_SCOPE_FORUM'),
                'note' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_NOTE_PHPBB_READ'),
            ],
            'f_post' => [
                'label' => $this->permission_label('f_post', $user),
                'scope' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_SCOPE_FORUM'),
                'note' => $user->lang('ACP_HELPDESK_PERMISSION_PROBE_NOTE_PHPBB_POST'),
            ],
        ];

        $results = [];
        foreach ($probe_permissions as $auth_option => $definition)
        {
            $probe = $this->evaluate_user_permission_probe((int) $user_row['user_id'], $group_rows, $selected_forum_id, $auth_option, $auth_option_ids);
            $results[$auth_option] = $probe;
            $state['permission_rows'][] = [
                'PERMISSION_LABEL' => $definition['label'],
                'SCOPE_LABEL' => $definition['scope'],
                'STATUS_CLASS' => $probe['status_class'],
                'STATUS_LABEL' => $probe['status_label'],
                'ALLOW_SOURCES' => $probe['allow_label'],
                'DENY_SOURCES' => $probe['deny_label'],
                'NOTE' => $definition['note'],
            ];
        }

        $customer_visible = $this->permission_probe_allows($results, ['f_helpdesk_view', 'f_list', 'f_read']);
        $customer_ticket = $this->permission_probe_allows($results, ['f_helpdesk_view', 'f_helpdesk_ticket', 'f_list', 'f_read', 'f_post']);
        $team_queue = $this->permission_probe_allows($results, ['m_helpdesk_queue']);
        $team_manage = $this->permission_probe_allows($results, ['m_helpdesk_manage']);
        $team_assign = $this->permission_probe_allows($results, ['m_helpdesk_assign']);
        $team_bulk = $this->permission_probe_allows($results, ['m_helpdesk_bulk']);
        $admin_manage = $this->permission_probe_allows_any($results, ['a_helpdesk_manage']);
        $internal_notes = ($team_queue || $team_manage);

        $state['summary_rows'] = [
            $this->permission_probe_summary_row($user->lang('ACP_HELPDESK_PERMISSION_PROBE_SUMMARY_CUSTOMER_VIEW'), $customer_visible, $user),
            $this->permission_probe_summary_row($user->lang('ACP_HELPDESK_PERMISSION_PROBE_SUMMARY_CUSTOMER_TICKET'), $customer_ticket, $user),
            $this->permission_probe_summary_row($user->lang('ACP_HELPDESK_PERMISSION_PROBE_SUMMARY_TEAM_QUEUE'), $team_queue, $user),
            $this->permission_probe_summary_row($user->lang('ACP_HELPDESK_PERMISSION_PROBE_SUMMARY_TEAM_MANAGE'), $team_manage, $user),
            $this->permission_probe_summary_row($user->lang('ACP_HELPDESK_PERMISSION_PROBE_SUMMARY_INTERNAL_NOTES'), $internal_notes, $user),
            $this->permission_probe_summary_row($user->lang('ACP_HELPDESK_PERMISSION_PROBE_SUMMARY_ASSIGN'), $team_assign, $user),
            $this->permission_probe_summary_row($user->lang('ACP_HELPDESK_PERMISSION_PROBE_SUMMARY_BULK'), $team_bulk, $user),
            $this->permission_probe_summary_row($user->lang('ACP_HELPDESK_PERMISSION_PROBE_SUMMARY_ADMIN'), $admin_manage, $user),
        ];

        if (!$customer_visible && !$team_queue && !$admin_manage)
        {
            $state['warnings'][] = $user->lang('ACP_HELPDESK_PERMISSION_PROBE_WARNING_NO_VISIBLE_ENTRY');
        }
        if (!$customer_ticket && $customer_visible)
        {
            $state['warnings'][] = $user->lang('ACP_HELPDESK_PERMISSION_PROBE_WARNING_NO_CUSTOMER_TICKET');
        }
        if ($results['f_helpdesk_ticket']['is_allowed'] && !$results['f_helpdesk_view']['is_allowed'])
        {
            $state['warnings'][] = $user->lang('ACP_HELPDESK_PERMISSION_PROBE_WARNING_TICKET_WITHOUT_VIEW');
        }
        if (($team_manage || $team_assign || $team_bulk) && !$team_queue)
        {
            $state['warnings'][] = $user->lang('ACP_HELPDESK_PERMISSION_PROBE_WARNING_OPERATE_WITHOUT_QUEUE');
        }

        $status_class = (!empty($state['warnings']) || $results['f_helpdesk_view']['is_conflict'] || $results['f_helpdesk_ticket']['is_conflict']) ? 'warning' : 'ok';
        $status_label = $status_class === 'ok'
            ? $user->lang('ACP_HELPDESK_PERMISSION_PROBE_STATUS_READY')
            : $user->lang('ACP_HELPDESK_PERMISSION_PROBE_STATUS_REVIEW');

        $state['has_result'] = true;
        $state['status_class'] = $status_class;
        $state['status_label'] = $status_label;
        $state['user_label'] = get_username_string('full', (int) $user_row['user_id'], (string) $user_row['username'], (string) $user_row['user_colour']);
        $state['forum_label'] = isset($forum_names[$selected_forum_id]) ? $forum_names[$selected_forum_id] : ('#' . (int) $selected_forum_id);
        $state['groups_label'] = !empty($group_names) ? implode(', ', $group_names) : $user->lang('ACP_HELPDESK_PERMISSION_PROBE_NO_GROUPS');
        $state['note'] = $this->permission_probe_note($customer_visible, $customer_ticket, $team_queue, $team_manage, $admin_manage, $user);

        return $state;
    }

    protected function fetch_user_row_by_name($username)
    {
        global $db;

        $username = trim((string) $username);
        if ($username === '')
        {
            return [];
        }

        $sql = 'SELECT user_id, username, user_colour
            FROM ' . USERS_TABLE . "
            WHERE username_clean = '" . $db->sql_escape(utf8_clean_string($username)) . "'";
        $result = $db->sql_query_limit($sql, 1);
        $row = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);

        return $row ?: [];
    }

    protected function fetch_user_groups($user_id)
    {
        global $db;

        $user_id = (int) $user_id;
        if ($user_id <= 0)
        {
            return [];
        }

        $sql = 'SELECT g.group_id, g.group_name, g.group_type
            FROM ' . USER_GROUP_TABLE . ' ug
            INNER JOIN ' . GROUPS_TABLE . ' g
                ON g.group_id = ug.group_id
            WHERE ug.user_id = ' . (int) $user_id . '
                AND ug.user_pending = 0
            ORDER BY g.group_name ASC';
        $result = $db->sql_query($sql);

        $rows = [];
        while ($row = $db->sql_fetchrow($result))
        {
            $rows[] = [
                'group_id' => (int) $row['group_id'],
                'group_name' => (string) $row['group_name'],
                'display_name' => $this->acl_group_display_name($row),
            ];
        }
        $db->sql_freeresult($result);

        return $rows;
    }

    protected function evaluate_user_permission_probe($user_id, array $group_rows, $forum_id, $auth_option, array $auth_option_ids)
    {
        $group_names = [];
        foreach ($group_rows as $group_row)
        {
            $group_names[(int) $group_row['group_id']] = (string) $group_row['display_name'];
        }

        $user_label = $this->fetch_probe_username_label((int) $user_id);
        $user_sources = $this->fetch_acl_probe_sources(ACL_USERS_TABLE, 'user_id', [(int) $user_id => $user_label], $forum_id, $auth_option, $auth_option_ids);
        $group_source_map = [];
        foreach ($group_rows as $group_row)
        {
            $group_source_map[(int) $group_row['group_id']] = (string) $group_row['display_name'];
        }
        $group_sources = $this->fetch_acl_probe_sources(ACL_GROUPS_TABLE, 'group_id', $group_source_map, $forum_id, $auth_option, $auth_option_ids);
        $all_sources = array_merge($user_sources, $group_sources);

        $allow_sources = [];
        $deny_sources = [];
        foreach ($all_sources as $source)
        {
            if ((int) $source['SETTING'] === 1)
            {
                $allow_sources[] = $source['LABEL'];
            }
            else if ((int) $source['SETTING'] === -1)
            {
                $deny_sources[] = $source['LABEL'];
            }
        }

        $allow_sources = array_values(array_unique($allow_sources));
        $deny_sources = array_values(array_unique($deny_sources));
        $is_conflict = !empty($allow_sources) && !empty($deny_sources);
        $is_allowed = !empty($allow_sources) && empty($deny_sources);
        $is_denied = empty($allow_sources) && !empty($deny_sources);

        if ($is_conflict)
        {
            $status_class = 'warning';
            $status_label = $GLOBALS['user']->lang('ACP_HELPDESK_PERMISSION_PROBE_RESULT_CONFLICT');
        }
        else if ($is_allowed)
        {
            $status_class = 'ok';
            $status_label = $GLOBALS['user']->lang('ACP_HELPDESK_PERMISSION_PROBE_RESULT_ALLOWED');
        }
        else if ($is_denied)
        {
            $status_class = 'warning';
            $status_label = $GLOBALS['user']->lang('ACP_HELPDESK_PERMISSION_PROBE_RESULT_DENIED');
        }
        else
        {
            $status_class = 'neutral';
            $status_label = $GLOBALS['user']->lang('ACP_HELPDESK_PERMISSION_PROBE_RESULT_NOT_FOUND');
        }

        return [
            'is_allowed' => $is_allowed,
            'is_denied' => $is_denied,
            'is_conflict' => $is_conflict,
            'allow_label' => !empty($allow_sources) ? implode(', ', $allow_sources) : $GLOBALS['user']->lang('ACP_HELPDESK_PERMISSION_PROBE_SOURCE_NONE'),
            'deny_label' => !empty($deny_sources) ? implode(', ', $deny_sources) : $GLOBALS['user']->lang('ACP_HELPDESK_PERMISSION_PROBE_SOURCE_NONE'),
            'status_class' => $status_class,
            'status_label' => $status_label,
        ];
    }

    protected function fetch_acl_probe_sources($table, $entity_column, array $entities, $forum_id, $auth_option, array $auth_option_ids)
    {
        global $db;

        $auth_option_id = isset($auth_option_ids[$auth_option]) ? (int) $auth_option_ids[$auth_option] : 0;
        if ($auth_option_id <= 0 || empty($entities))
        {
            return [];
        }

        $forum_scope_ids = $this->permission_probe_scope_for_option($auth_option, $forum_id);
        if (empty($forum_scope_ids))
        {
            return [];
        }

        $entity_ids = array_values(array_map('intval', array_keys($entities)));
        $sql = 'SELECT a.' . $entity_column . ' AS entity_id, a.forum_id, a.auth_role_id, a.auth_option_id, a.auth_setting AS direct_setting,
                    ard.auth_setting AS role_setting, r.role_name
            FROM ' . $table . ' a
            LEFT JOIN ' . ACL_ROLES_DATA_TABLE . ' ard
                ON ard.role_id = a.auth_role_id
                AND ard.auth_option_id = ' . $auth_option_id . '
            LEFT JOIN ' . ACL_ROLES_TABLE . ' r
                ON r.role_id = a.auth_role_id
            WHERE ' . $db->sql_in_set('a.' . $entity_column, $entity_ids) . '
                AND ' . $db->sql_in_set('a.forum_id', $forum_scope_ids) . '
                AND (
                    a.auth_option_id = ' . $auth_option_id . '
                    OR (a.auth_role_id > 0 AND ard.auth_option_id = ' . $auth_option_id . ')
                )
            ORDER BY a.forum_id ASC, a.auth_role_id DESC, a.auth_option_id ASC';
        $result = $db->sql_query($sql);

        $sources = [];
        while ($row = $db->sql_fetchrow($result))
        {
            $setting = ((int) $row['auth_role_id'] > 0) ? (int) $row['role_setting'] : (int) $row['direct_setting'];
            if (!in_array($setting, [-1, 1], true))
            {
                continue;
            }

            $entity_id = (int) $row['entity_id'];
            $forum_label = ((int) $row['forum_id'] === 0)
                ? $GLOBALS['user']->lang('ACP_HELPDESK_PERMISSION_PROBE_SCOPE_GLOBAL')
                : $GLOBALS['user']->lang('ACP_HELPDESK_PERMISSION_PROBE_SCOPE_THIS_FORUM');
            $entity_label = isset($entities[$entity_id]) ? $entities[$entity_id] : ('#' . $entity_id);
            if ((int) $row['auth_role_id'] > 0)
            {
                $label = $entity_label . ' · ' . $forum_label . ' · ' . $this->localized_helpdesk_role_name((string) $row['role_name'], $GLOBALS['user']);
            }
            else
            {
                $label = $entity_label . ' · ' . $forum_label . ' · ' . $auth_option;
            }

            $sources[] = [
                'SETTING' => $setting,
                'LABEL' => $label,
            ];
        }
        $db->sql_freeresult($result);

        return $sources;
    }

    protected function permission_probe_scope_for_option($auth_option, $forum_id)
    {
        $forum_id = (int) $forum_id;
        if (strpos((string) $auth_option, 'a_') === 0)
        {
            return [0];
        }
        if (strpos((string) $auth_option, 'm_') === 0)
        {
            return array_values(array_unique(array_filter([0, $forum_id])));
        }

        return $forum_id > 0 ? [$forum_id] : [];
    }

    protected function permission_probe_allows(array $results, array $keys)
    {
        foreach ($keys as $key)
        {
            if (empty($results[$key]['is_allowed']))
            {
                return false;
            }
        }

        return true;
    }

    protected function permission_probe_allows_any(array $results, array $keys)
    {
        foreach ($keys as $key)
        {
            if (!empty($results[$key]['is_allowed']))
            {
                return true;
            }
        }

        return false;
    }

    protected function fetch_probe_username_label($user_id)
    {
        global $db;

        $sql = 'SELECT user_id, username, user_colour
            FROM ' . USERS_TABLE . '
            WHERE user_id = ' . (int) $user_id;
        $result = $db->sql_query_limit($sql, 1);
        $row = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);

        if (!empty($row))
        {
            return get_username_string('full', (int) $row['user_id'], (string) $row['username'], (string) $row['user_colour']);
        }

        return '#' . (int) $user_id;
    }

    protected function permission_probe_summary_row($label, $is_ready, $user)
    {
        return [
            'LABEL' => $label,
            'STATUS_CLASS' => $is_ready ? 'ok' : 'warning',
            'STATUS_LABEL' => $user->lang($is_ready ? 'ACP_HELPDESK_PERMISSION_PROBE_SUMMARY_READY' : 'ACP_HELPDESK_PERMISSION_PROBE_SUMMARY_MISSING'),
        ];
    }

    protected function permission_probe_note($customer_visible, $customer_ticket, $team_queue, $team_manage, $admin_manage, $user)
    {
        if ($admin_manage)
        {
            return $user->lang('ACP_HELPDESK_PERMISSION_PROBE_NOTE_RESULT_ADMIN');
        }

        if ($team_queue || $team_manage)
        {
            return $user->lang('ACP_HELPDESK_PERMISSION_PROBE_NOTE_RESULT_TEAM');
        }

        if ($customer_ticket)
        {
            return $user->lang('ACP_HELPDESK_PERMISSION_PROBE_NOTE_RESULT_CUSTOMER_TICKET');
        }

        if ($customer_visible)
        {
            return $user->lang('ACP_HELPDESK_PERMISSION_PROBE_NOTE_RESULT_CUSTOMER_VIEW');
        }

        return $user->lang('ACP_HELPDESK_PERMISSION_PROBE_NOTE_RESULT_HIDDEN');
    }

    protected function permission_label($auth_option, $user)
    {
        $lang_key = 'ACL_' . strtoupper((string) $auth_option);
        $label = $user->lang($lang_key);

        return ($label !== $lang_key) ? $label : (string) $auth_option;
    }

    protected function build_acp_overview(array $forum_ids, array $parsed_statuses, array $parsed_departments, array $parsed_priorities, $user)
    {
        $forum_names = $this->fetch_forum_names($forum_ids);
        $tracked_forums = [];
        foreach ($forum_ids as $forum_id)
        {
            $tracked_forums[] = [
                'FORUM_ID' => (int) $forum_id,
                'FORUM_NAME' => isset($forum_names[$forum_id]) ? $forum_names[$forum_id] : $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_FORUM_UNKNOWN', (int) $forum_id),
            ];
        }

        $overview = [
            'total_tickets' => 0,
            'active_tickets' => 0,
            'closed_tickets' => 0,
            'assigned_tickets' => 0,
            'recent_activity_count' => 0,
            'tracked_forum_names' => implode(', ', array_column($tracked_forums, 'FORUM_NAME')),
            'tracked_forums' => $tracked_forums,
            'status_rows' => [],
            'recent_activity' => [],
            'alert_total' => 0,
            'alert_overdue_count' => 0,
            'alert_stale_count' => 0,
            'alert_first_reply_count' => 0,
            'alert_unassigned_count' => 0,
            'alert_reopened_count' => 0,
            'alert_waiting_staff_count' => 0,
            'alert_limit' => 0,
            'alert_rows' => [],
        ];

        if (empty($forum_ids))
        {
            return $overview;
        }

        global $db, $table_prefix, $config;

        $status_counts = [];
        $status_tones = [];
        foreach ($parsed_statuses as $status_key => $definition)
        {
            $status_tones[$status_key] = isset($definition['tone']) ? (string) $definition['tone'] : $this->normalize_status_tone($status_key);
        }

        $helpdesk_topics_table = $table_prefix . 'helpdesk_topics';
        $sql = 'SELECT status_key, COUNT(*) AS ticket_count, SUM(CASE WHEN assigned_to IS NOT NULL AND CHAR_LENGTH(TRIM(assigned_to)) > 0 THEN 1 ELSE 0 END) AS assigned_count
            FROM ' . $helpdesk_topics_table . '
            WHERE ' . $db->sql_in_set('forum_id', array_map('intval', $forum_ids)) . '
            GROUP BY status_key';
        $result = $db->sql_query($sql);
        while ($row = $db->sql_fetchrow($result))
        {
            $status_key = (string) $row['status_key'];
            $ticket_count = (int) $row['ticket_count'];
            $status_counts[$status_key] = $ticket_count;
            $overview['total_tickets'] += $ticket_count;
            $overview['assigned_tickets'] += (int) $row['assigned_count'];

            $tone = isset($status_tones[$status_key]) ? $status_tones[$status_key] : $this->normalize_status_tone($status_key);
            if (in_array($tone, ['resolved', 'closed'], true))
            {
                $overview['closed_tickets'] += $ticket_count;
            }
            else
            {
                $overview['active_tickets'] += $ticket_count;
            }
        }
        $db->sql_freeresult($result);

        foreach ($parsed_statuses as $status_key => $definition)
        {
            $count = isset($status_counts[$status_key]) ? (int) $status_counts[$status_key] : 0;
            $share = $overview['total_tickets'] > 0 ? round(($count / $overview['total_tickets']) * 100, 1) : 0;
            $tone = isset($definition['tone']) ? (string) $definition['tone'] : 'open';
            $overview['status_rows'][] = [
                'STATUS_LABEL' => $this->status_label_for_user($definition, $user),
                'STATUS_TONE' => $this->status_tone_label($tone, $user),
                'STATUS_TONE_CLASS' => $this->status_tone_css_class($tone),
                'TICKET_COUNT' => $count,
                'SHARE_LABEL' => $share > 0 ? sprintf('%.1f%%', $share) : '0%',
            ];
        }

        $recent_threshold = time() - 86400;
        $helpdesk_logs_table = $table_prefix . 'helpdesk_logs';
        $sql = 'SELECT COUNT(*) AS activity_count
            FROM ' . $helpdesk_logs_table . '
            WHERE ' . $db->sql_in_set('forum_id', array_map('intval', $forum_ids)) . '
                AND log_time >= ' . (int) $recent_threshold;
        $result = $db->sql_query($sql);
        $overview['recent_activity_count'] = (int) $db->sql_fetchfield('activity_count');
        $db->sql_freeresult($result);

        $sql = 'SELECT l.topic_id, l.user_id, l.action_key, l.log_time, t.topic_title, u.username, u.user_colour
            FROM ' . $helpdesk_logs_table . ' l
            LEFT JOIN ' . TOPICS_TABLE . ' t ON t.topic_id = l.topic_id
            LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = l.user_id
            WHERE ' . $db->sql_in_set('l.forum_id', array_map('intval', $forum_ids)) . '
            ORDER BY l.log_time DESC';
        $result = $db->sql_query_limit($sql, 8);
        while ($row = $db->sql_fetchrow($result))
        {
            $username = isset($row['username']) && $row['username'] !== '' ? get_username_string('full', (int) $row['user_id'], (string) $row['username'], (string) $row['user_colour']) : $user->lang('ACP_HELPDESK_OVERVIEW_SYSTEM');
            $overview['recent_activity'][] = [
                'LOG_TIME' => $user->format_date((int) $row['log_time']),
                'TOPIC_LABEL' => isset($row['topic_title']) && (string) $row['topic_title'] !== '' ? (string) $row['topic_title'] : $user->lang('ACP_HELPDESK_OVERVIEW_TOPIC_FALLBACK', (int) $row['topic_id']),
                'ACTION_LABEL' => $this->acp_activity_action_label(isset($row['action_key']) ? (string) $row['action_key'] : '', $user),
                'ACTION_CLASS' => $this->acp_activity_action_class(isset($row['action_key']) ? (string) $row['action_key'] : ''),
                'USERNAME' => $username,
            ];
        }
        $db->sql_freeresult($result);

        $reopen_counts = [];
        $sql = 'SELECT topic_id, COUNT(*) AS reopen_count
            FROM ' . $helpdesk_logs_table . '
            WHERE ' . $db->sql_in_set('forum_id', array_map('intval', $forum_ids)) . "
                AND action_key = 'status_change'
                AND old_value IN ('resolved', 'closed')
                AND new_value NOT IN ('resolved', 'closed')
            GROUP BY topic_id";
        $result = $db->sql_query($sql);
        while ($row = $db->sql_fetchrow($result))
        {
            $reopen_counts[(int) $row['topic_id']] = (int) $row['reopen_count'];
        }
        $db->sql_freeresult($result);

        $alert_limit = max(5, min(25, (int) (isset($config['mundophpbb_helpdesk_alert_limit']) ? $config['mundophpbb_helpdesk_alert_limit'] : 15)));
        $overview['alert_limit'] = $alert_limit;
        $sla_seconds = max(1, (int) (isset($config['mundophpbb_helpdesk_sla_hours']) ? $config['mundophpbb_helpdesk_sla_hours'] : 24)) * 3600;
        $stale_seconds = max(1, (int) (isset($config['mundophpbb_helpdesk_stale_hours']) ? $config['mundophpbb_helpdesk_stale_hours'] : 72)) * 3600;
        $now = time();
        $alert_rows = [];

        $sql = 'SELECT h.topic_id, h.forum_id, h.status_key, h.priority_key, h.department_key, h.assigned_to, h.created_time, h.updated_time,
                t.topic_title, t.topic_poster, t.topic_last_poster_id, t.topic_first_post_id, t.topic_last_post_id,
                f.forum_name
            FROM ' . $helpdesk_topics_table . ' h
            LEFT JOIN ' . TOPICS_TABLE . ' t ON t.topic_id = h.topic_id
            LEFT JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = h.forum_id
            WHERE ' . $db->sql_in_set('h.forum_id', array_map('intval', $forum_ids));
        $result = $db->sql_query($sql);
        while ($row = $db->sql_fetchrow($result))
        {
            $topic_id = (int) $row['topic_id'];
            $status_key = (string) ($row['status_key'] ?? '');
            $status_tone = isset($status_tones[$status_key]) ? $status_tones[$status_key] : $this->normalize_status_tone($status_key);
            $is_active = !in_array($status_tone, ['resolved', 'closed'], true);
            if (!$is_active)
            {
                continue;
            }

            $created_time = (int) ($row['created_time'] ?? 0);
            $updated_time = (int) ($row['updated_time'] ?? 0);
            $last_activity = $updated_time > 0 ? $updated_time : $created_time;
            $topic_poster = (int) ($row['topic_poster'] ?? 0);
            $topic_last_poster_id = (int) ($row['topic_last_poster_id'] ?? 0);
            $topic_first_post_id = (int) ($row['topic_first_post_id'] ?? 0);
            $topic_last_post_id = (int) ($row['topic_last_post_id'] ?? 0);
            $has_reply = $topic_first_post_id > 0 && $topic_last_post_id > 0 && $topic_last_post_id !== $topic_first_post_id;
            $assigned_to = trim((string) ($row['assigned_to'] ?? ''));
            $department_key = (string) ($row['department_key'] ?? '');
            $priority_key = (string) ($row['priority_key'] ?? 'normal');
            $reopen_count = isset($reopen_counts[$topic_id]) ? (int) $reopen_counts[$topic_id] : 0;

            $is_overdue = $created_time > 0 && ($now - $created_time) > $sla_seconds;
            $is_stale = $last_activity > 0 && ($now - $last_activity) > $stale_seconds;
            $needs_first_reply = !$has_reply;
            $is_unassigned = $assigned_to === '';
            $is_reopened = $reopen_count > 0;
            $is_waiting_staff = $has_reply && $topic_poster > 0 && $topic_last_poster_id > 0 && $topic_poster === $topic_last_poster_id;

            if ($is_overdue) { $overview['alert_overdue_count']++; }
            if ($is_stale) { $overview['alert_stale_count']++; }
            if ($needs_first_reply) { $overview['alert_first_reply_count']++; }
            if ($is_unassigned) { $overview['alert_unassigned_count']++; }
            if ($is_reopened) { $overview['alert_reopened_count']++; }
            if ($is_waiting_staff) { $overview['alert_waiting_staff_count']++; }

            if (!($is_overdue || $is_stale || $needs_first_reply || $is_unassigned || $is_reopened || $is_waiting_staff))
            {
                continue;
            }

            $alerts = [];
            $score = 0;
            if ($is_overdue)
            {
                $alerts[] = $user->lang('ACP_HELPDESK_ALERT_FLAG_OVERDUE');
                $score += 50;
            }
            if ($needs_first_reply)
            {
                $alerts[] = $user->lang('ACP_HELPDESK_ALERT_FLAG_FIRST_REPLY');
                $score += 40;
            }
            if ($is_stale)
            {
                $alerts[] = $user->lang('ACP_HELPDESK_ALERT_FLAG_STALE');
                $score += 30;
            }
            if ($is_waiting_staff)
            {
                $alerts[] = $user->lang('ACP_HELPDESK_ALERT_FLAG_WAITING_STAFF');
                $score += 20;
            }
            if ($is_unassigned)
            {
                $alerts[] = $user->lang('ACP_HELPDESK_ALERT_FLAG_UNASSIGNED');
                $score += 10;
            }
            if ($is_reopened)
            {
                $alerts[] = $user->lang('ACP_HELPDESK_ALERT_FLAG_REOPENED');
                $score += 5;
            }
            if ($priority_key === 'critical')
            {
                $score += 3;
            }
            else if ($priority_key === 'high')
            {
                $score += 1;
            }

            $severity = 'monitor';
            if ($is_overdue || $needs_first_reply || ($is_stale && $is_waiting_staff))
            {
                $severity = 'critical';
            }
            else if ($is_stale || $is_waiting_staff || $is_unassigned || $is_reopened)
            {
                $severity = 'attention';
            }

            $status_definition = isset($parsed_statuses[$status_key]) ? $parsed_statuses[$status_key] : [
                'label_pt_br' => $status_key,
                'label_en' => $status_key,
                'tone' => $status_tone,
            ];
            $priority_definition = isset($parsed_priorities[$priority_key]) ? $parsed_priorities[$priority_key] : [
                'label_pt_br' => $priority_key,
                'label_en' => $priority_key,
                'tone' => $priority_key,
            ];

            $alert_rows[] = [
                'SCORE' => $score,
                'LAST_ACTIVITY_TS' => $last_activity,
                'TOPIC_LABEL' => isset($row['topic_title']) && (string) $row['topic_title'] !== '' ? (string) $row['topic_title'] : $user->lang('ACP_HELPDESK_OVERVIEW_TOPIC_FALLBACK', $topic_id),
                'FORUM_NAME' => isset($row['forum_name']) ? (string) $row['forum_name'] : $user->lang('ACP_HELPDESK_PERMISSION_DIAGNOSTIC_FORUM_UNKNOWN', (int) $row['forum_id']),
                'STATUS_LABEL' => $this->status_label_for_user($status_definition, $user),
                'PRIORITY_LABEL' => $this->priority_label_for_user($priority_definition, $priority_key, $user),
                'DEPARTMENT_LABEL' => $department_key !== ''
                    ? $this->department_label_for_user(isset($parsed_departments[$department_key]) ? $parsed_departments[$department_key] : $department_key, $department_key, $user)
                    : $user->lang('ACP_HELPDESK_FEEDBACK_DEPARTMENT_UNASSIGNED'),
                'ALERTS_LABEL' => implode(' · ', $alerts),
                'SEVERITY_LABEL' => $this->acp_alert_severity_label($severity, $user),
                'SEVERITY_CLASS' => $this->acp_alert_severity_class($severity),
                'LAST_ACTIVITY_LABEL' => $last_activity > 0 ? $user->format_date($last_activity) : $user->lang('ACP_HELPDESK_ALERT_NO_ACTIVITY'),
            ];
        }
        $db->sql_freeresult($result);

        $overview['alert_total'] = count($alert_rows);
        usort($alert_rows, static function ($a, $b) {
            if ((int) $a['SCORE'] === (int) $b['SCORE'])
            {
                return (int) $b['LAST_ACTIVITY_TS'] <=> (int) $a['LAST_ACTIVITY_TS'];
            }
            return (int) $b['SCORE'] <=> (int) $a['SCORE'];
        });
        $alert_rows = array_slice($alert_rows, 0, $alert_limit);
        foreach ($alert_rows as &$alert_row)
        {
            unset($alert_row['SCORE'], $alert_row['LAST_ACTIVITY_TS']);
        }
        unset($alert_row);
        $overview['alert_rows'] = $alert_rows;

        return $overview;
    }


    protected function build_acp_feedback_overview(array $forum_ids, array $parsed_departments, $user)
    {
        $overview = [
            'enabled' => false,
            'has_feedback' => false,
            'total_feedback' => 0,
            'average_rating_value' => '0.0',
            'average_rating_label' => '',
            'recent_feedback_count' => 0,
            'comment_count' => 0,
            'low_rating_count' => 0,
            'distribution_rows' => [],
            'department_rows' => [],
            'recent_rows' => [],
        ];

        if (empty($forum_ids))
        {
            return $overview;
        }

        global $db, $table_prefix;

        if (empty($GLOBALS['config']['mundophpbb_helpdesk_feedback_enable']))
        {
            return $overview;
        }

        $overview['enabled'] = true;
        $feedback_table = $table_prefix . 'helpdesk_feedback';
        $helpdesk_topics_table = $table_prefix . 'helpdesk_topics';
        $recent_threshold = time() - (30 * 86400);

        $sql = 'SELECT COUNT(*) AS total_feedback, AVG(rating) AS average_rating, '
            . 'SUM(CASE WHEN comment_text <> \'\' THEN 1 ELSE 0 END) AS comment_count, '
            . 'SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) AS low_rating_count, '
            . 'SUM(CASE WHEN submitted_time >= ' . (int) $recent_threshold . ' THEN 1 ELSE 0 END) AS recent_feedback_count '
            . 'FROM ' . $feedback_table . ' '
            . 'WHERE ' . $db->sql_in_set('forum_id', array_map('intval', $forum_ids));
        $result = $db->sql_query($sql);
        $row = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);

        $overview['total_feedback'] = (int) ($row['total_feedback'] ?? 0);
        $overview['comment_count'] = (int) ($row['comment_count'] ?? 0);
        $overview['low_rating_count'] = (int) ($row['low_rating_count'] ?? 0);
        $overview['recent_feedback_count'] = (int) ($row['recent_feedback_count'] ?? 0);
        $average_rating = isset($row['average_rating']) ? (float) $row['average_rating'] : 0.0;
        $overview['average_rating_value'] = $overview['total_feedback'] > 0 ? number_format($average_rating, 1, '.', '') : '0.0';
        $overview['average_rating_label'] = $overview['total_feedback'] > 0
            ? $this->feedback_stars_for_acp($average_rating, $user)
            : $user->lang('ACP_HELPDESK_FEEDBACK_NO_DATA');
        $overview['has_feedback'] = $overview['total_feedback'] > 0;

        for ($rating = 5; $rating >= 1; $rating--)
        {
            $overview['distribution_rows'][] = [
                'RATING_VALUE' => $rating,
                'RATING_LABEL' => $this->feedback_stars_for_acp((float) $rating, $user),
                'TICKET_COUNT' => 0,
                'SHARE_LABEL' => '0%',
            ];
        }

        if (!$overview['has_feedback'])
        {
            return $overview;
        }

        $distribution_counts = [];
        $sql = 'SELECT rating, COUNT(*) AS total_count '
            . 'FROM ' . $feedback_table . ' '
            . 'WHERE ' . $db->sql_in_set('forum_id', array_map('intval', $forum_ids)) . ' '
            . 'GROUP BY rating';
        $result = $db->sql_query($sql);
        while ($row = $db->sql_fetchrow($result))
        {
            $distribution_counts[(int) $row['rating']] = (int) $row['total_count'];
        }
        $db->sql_freeresult($result);

        $overview['distribution_rows'] = [];
        for ($rating = 5; $rating >= 1; $rating--)
        {
            $count = isset($distribution_counts[$rating]) ? (int) $distribution_counts[$rating] : 0;
            $share = $overview['total_feedback'] > 0 ? round(($count / $overview['total_feedback']) * 100, 1) : 0;
            $overview['distribution_rows'][] = [
                'RATING_VALUE' => $rating,
                'RATING_LABEL' => $this->feedback_stars_for_acp((float) $rating, $user),
                'TICKET_COUNT' => $count,
                'SHARE_LABEL' => $share > 0 ? sprintf('%.1f%%', $share) : '0%',
            ];
        }

        $sql = 'SELECT h.department_key, COUNT(f.feedback_id) AS total_feedback, AVG(f.rating) AS average_rating, '
            . 'SUM(CASE WHEN f.comment_text <> \'\' THEN 1 ELSE 0 END) AS comment_count '
            . 'FROM ' . $feedback_table . ' f '
            . 'LEFT JOIN ' . $helpdesk_topics_table . ' h ON h.topic_id = f.topic_id '
            . 'WHERE ' . $db->sql_in_set('f.forum_id', array_map('intval', $forum_ids)) . ' '
            . 'GROUP BY h.department_key '
            . 'ORDER BY total_feedback DESC, average_rating DESC';
        $result = $db->sql_query_limit($sql, 8);
        while ($row = $db->sql_fetchrow($result))
        {
            $department_key = isset($row['department_key']) ? (string) $row['department_key'] : '';
            $label = $department_key !== ''
                ? $this->department_label_for_user(isset($parsed_departments[$department_key]) ? $parsed_departments[$department_key] : $department_key, $department_key, $user)
                : $user->lang('ACP_HELPDESK_FEEDBACK_DEPARTMENT_UNASSIGNED');
            $average = isset($row['average_rating']) ? (float) $row['average_rating'] : 0.0;
            $overview['department_rows'][] = [
                'DEPARTMENT_LABEL' => $label,
                'AVERAGE_LABEL' => number_format($average, 1, '.', ''),
                'AVERAGE_STARS' => $this->feedback_stars_for_acp($average, $user),
                'TOTAL_FEEDBACK' => (int) ($row['total_feedback'] ?? 0),
                'COMMENT_COUNT' => (int) ($row['comment_count'] ?? 0),
            ];
        }
        $db->sql_freeresult($result);

        $sql = 'SELECT f.topic_id, f.rating, f.comment_text, f.submitted_time, t.topic_title, h.department_key, u.username, u.user_colour, u.user_id '
            . 'FROM ' . $feedback_table . ' f '
            . 'LEFT JOIN ' . TOPICS_TABLE . ' t ON t.topic_id = f.topic_id '
            . 'LEFT JOIN ' . $helpdesk_topics_table . ' h ON h.topic_id = f.topic_id '
            . 'LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = f.user_id '
            . 'WHERE ' . $db->sql_in_set('f.forum_id', array_map('intval', $forum_ids)) . ' '
            . 'ORDER BY f.submitted_time DESC';
        $result = $db->sql_query_limit($sql, 8);
        while ($row = $db->sql_fetchrow($result))
        {
            $department_key = isset($row['department_key']) ? (string) $row['department_key'] : '';
            $department_label = $department_key !== ''
                ? $this->department_label_for_user(isset($parsed_departments[$department_key]) ? $parsed_departments[$department_key] : $department_key, $department_key, $user)
                : $user->lang('ACP_HELPDESK_FEEDBACK_DEPARTMENT_UNASSIGNED');
            $comment = trim((string) ($row['comment_text'] ?? ''));
            if ($comment !== '')
            {
                if (function_exists('utf8_substr'))
                {
                    $comment = utf8_substr($comment, 0, 120);
                }
                else
                {
                    $comment = substr($comment, 0, 120);
                }
            }
            $overview['recent_rows'][] = [
                'SUBMITTED_AT' => $user->format_date((int) ($row['submitted_time'] ?? 0)),
                'TOPIC_LABEL' => isset($row['topic_title']) && (string) $row['topic_title'] !== '' ? (string) $row['topic_title'] : $user->lang('ACP_HELPDESK_OVERVIEW_TOPIC_FALLBACK', (int) ($row['topic_id'] ?? 0)),
                'RATING_LABEL' => $this->feedback_stars_for_acp((float) ($row['rating'] ?? 0), $user),
                'RATING_VALUE' => (int) ($row['rating'] ?? 0),
                'DEPARTMENT_LABEL' => $department_label,
                'USERNAME' => isset($row['username']) && $row['username'] !== '' ? get_username_string('full', (int) $row['user_id'], (string) $row['username'], (string) $row['user_colour']) : $user->lang('ACP_HELPDESK_OVERVIEW_SYSTEM'),
                'COMMENT_PREVIEW' => $comment,
                'HAS_COMMENT' => $comment !== '',
            ];
        }
        $db->sql_freeresult($result);

        return $overview;
    }

    protected function feedback_stars_for_acp($rating, $user)
    {
        $value = max(0.0, min(5.0, (float) $rating));
        if ($value <= 0)
        {
            return $user->lang('ACP_HELPDESK_FEEDBACK_NO_DATA');
        }

        $rounded = (int) round($value);
        return str_repeat('★', $rounded) . str_repeat('☆', max(0, 5 - $rounded));
    }

    protected function status_label_for_user(array $definition, $user)
    {
        $lang_name = method_exists($user, 'lang_name') ? (string) $user->lang_name : '';
        if ($lang_name === 'pt_br')
        {
            return isset($definition['label_pt_br']) && $definition['label_pt_br'] !== '' ? (string) $definition['label_pt_br'] : (string) $definition['label_en'];
        }

        return isset($definition['label_en']) && $definition['label_en'] !== '' ? (string) $definition['label_en'] : (string) $definition['label_pt_br'];
    }

    protected function department_label_for_user($definition, $fallback_key, $user)
    {
        if (is_array($definition))
        {
            $lang_name = method_exists($user, 'lang_name') ? (string) $user->lang_name : '';
            if ($lang_name === 'pt_br')
            {
                if (isset($definition['label_pt_br']) && $definition['label_pt_br'] !== '')
                {
                    return (string) $definition['label_pt_br'];
                }

                if (isset($definition['label_en']) && $definition['label_en'] !== '')
                {
                    return (string) $definition['label_en'];
                }
            }
            else
            {
                if (isset($definition['label_en']) && $definition['label_en'] !== '')
                {
                    return (string) $definition['label_en'];
                }

                if (isset($definition['label_pt_br']) && $definition['label_pt_br'] !== '')
                {
                    return (string) $definition['label_pt_br'];
                }
            }
        }

        $label = trim((string) $definition);
        return $label !== '' ? $label : (string) $fallback_key;
    }

    protected function priority_label_for_user($definition, $fallback_key, $user)
    {
        if (is_array($definition))
        {
            $lang_name = method_exists($user, 'lang_name') ? (string) $user->lang_name : '';
            if ($lang_name === 'pt_br')
            {
                if (isset($definition['label_pt_br']) && $definition['label_pt_br'] !== '')
                {
                    return (string) $definition['label_pt_br'];
                }

                if (isset($definition['label_en']) && $definition['label_en'] !== '')
                {
                    return (string) $definition['label_en'];
                }
            }
            else
            {
                if (isset($definition['label_en']) && $definition['label_en'] !== '')
                {
                    return (string) $definition['label_en'];
                }

                if (isset($definition['label_pt_br']) && $definition['label_pt_br'] !== '')
                {
                    return (string) $definition['label_pt_br'];
                }
            }
        }

        $label = trim((string) $definition);
        return $label !== '' ? $label : (string) $fallback_key;
    }

    protected function acp_alert_severity_label($severity, $user)
    {
        switch ((string) $severity)
        {
            case 'critical':
                return $user->lang('ACP_HELPDESK_ALERT_SEVERITY_CRITICAL');
            case 'attention':
                return $user->lang('ACP_HELPDESK_ALERT_SEVERITY_ATTENTION');
            case 'monitor':
            default:
                return $user->lang('ACP_HELPDESK_ALERT_SEVERITY_MONITOR');
        }
    }

    protected function acp_alert_severity_class($severity)
    {
        switch ((string) $severity)
        {
            case 'critical':
                return 'warning';
            case 'attention':
                return 'active';
            case 'monitor':
            default:
                return 'info';
        }
    }

    protected function status_tone_label($tone, $user)
    {
        switch ((string) $tone)
        {
            case 'progress':
                return $user->lang('ACP_HELPDESK_OVERVIEW_TONE_PROGRESS');
            case 'waiting':
                return $user->lang('ACP_HELPDESK_OVERVIEW_TONE_WAITING');
            case 'resolved':
                return $user->lang('ACP_HELPDESK_OVERVIEW_TONE_RESOLVED');
            case 'closed':
                return $user->lang('ACP_HELPDESK_OVERVIEW_TONE_CLOSED');
            case 'open':
            default:
                return $user->lang('ACP_HELPDESK_OVERVIEW_TONE_OPEN');
        }
    }

    protected function status_tone_css_class($tone)
    {
        switch ((string) $tone)
        {
            case 'progress':
                return 'info';
            case 'waiting':
                return 'warning';
            case 'resolved':
                return 'ok';
            case 'closed':
                return 'neutral';
            case 'open':
            default:
                return 'active';
        }
    }

    protected function acp_activity_action_label($action_key, $user)
    {
        switch ((string) $action_key)
        {
            case 'assignment_change':
                return $user->lang('ACP_HELPDESK_OVERVIEW_ACTION_ASSIGNMENT');
            case 'department_change':
                return $user->lang('ACP_HELPDESK_OVERVIEW_ACTION_DEPARTMENT');
            case 'priority_change':
                return $user->lang('ACP_HELPDESK_OVERVIEW_ACTION_PRIORITY');
            case 'internal_note':
                return $user->lang('ACP_HELPDESK_OVERVIEW_ACTION_NOTE');
            case 'customer_feedback':
                return $user->lang('ACP_HELPDESK_OVERVIEW_ACTION_FEEDBACK');
            case 'status_change':
            default:
                return $user->lang('ACP_HELPDESK_OVERVIEW_ACTION_STATUS');
        }
    }

    protected function acp_activity_action_class($action_key)
    {
        switch ((string) $action_key)
        {
            case 'assignment_change':
                return 'info';
            case 'department_change':
                return 'active';
            case 'priority_change':
                return 'warning';
            case 'internal_note':
                return 'neutral';
            case 'customer_feedback':
                return 'warning';
            case 'status_change':
            default:
                return 'ok';
        }
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
