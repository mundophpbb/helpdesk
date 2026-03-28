<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
    /** @var \phpbb\config\config */
    protected $config;

    /** @var \phpbb\template\template */
    protected $template;

    /** @var \phpbb\request\request_interface */
    protected $request;

    /** @var \phpbb\user */
    protected $user;

    /** @var \phpbb\auth\auth */
    protected $auth;

    /** @var \phpbb\db\driver\driver_interface */
    protected $db;

    /** @var \phpbb\controller\helper */
    protected $helper;

    /** @var string */
    protected $table_prefix;

    /** @var array */
    protected $topic_cache = [];

    /** @var array|null */
    protected $status_cache = null;

    /** @var array|null */
    protected $priority_cache = null;

    /** @var array */
    protected $reopen_count_cache = [];

    /** @var bool */
    protected $bulk_manage_processed = false;

    /** @var bool */
    protected $bulk_manage_template_assigned = false;

    public function __construct(
        \phpbb\config\config $config,
        \phpbb\template\template $template,
        \phpbb\request\request_interface $request,
        \phpbb\user $user,
        \phpbb\auth\auth $auth,
        \phpbb\db\driver\driver_interface $db,
        \phpbb\controller\helper $helper,
        $table_prefix
    ) {
        $this->config = $config;
        $this->template = $template;
        $this->request = $request;
        $this->user = $user;
        $this->auth = $auth;
        $this->db = $db;
        $this->helper = $helper;
        $this->table_prefix = (string) $table_prefix;
    }

    public static function getSubscribedEvents()
    {
        return [
            'core.user_setup' => 'load_language_on_setup',
            'core.page_header' => 'page_header',
            'core.posting_modify_template_vars' => 'posting_modify_template_vars',
            'core.submit_post_end' => 'submit_post_end',
            'core.viewforum_modify_topicrow' => 'viewforum_modify_topicrow',
            'core.mcp_view_forum_modify_topicrow' => 'mcp_view_forum_modify_topicrow',
            'core.viewtopic_assign_template_vars_before' => 'viewtopic_assign_template_vars_before',
        ];
    }

    public function load_language_on_setup($event)
    {
        $lang_set_ext = $event['lang_set_ext'];
        $lang_set_ext[] = [
            'ext_name' => 'mundophpbb/helpdesk',
            'lang_set' => 'common',
        ];
        $lang_set_ext[] = [
            'ext_name' => 'mundophpbb/helpdesk',
            'lang_set' => 'permissions_helpdesk',
        ];
        $event['lang_set_ext'] = $lang_set_ext;
    }


    public function page_header($event)
    {
        $can_view_queue = $this->team_panel_enabled() && $this->can_view_team_queue();
        $can_view_my_tickets = $this->can_view_my_tickets();

        $this->template->assign_vars([
            'S_HELPDESK_CAN_VIEW_TEAM_QUEUE' => $can_view_queue,
            'U_HELPDESK_TEAM_QUEUE' => $can_view_queue ? $this->team_queue_url() : '',
            'S_HELPDESK_CAN_VIEW_MY_TICKETS' => $can_view_my_tickets,
            'U_HELPDESK_MY_TICKETS' => $can_view_my_tickets ? $this->my_tickets_url() : '',
            'S_HELPDESK_ALERTS_ENABLED' => $this->alerts_enabled(),
        ]);
    }

    public function posting_modify_template_vars($event)
    {
        $forum_id = isset($event['forum_id']) ? (int) $event['forum_id'] : 0;
        $mode = isset($event['mode']) ? (string) $event['mode'] : '';
        $post_data = isset($event['post_data']) && is_array($event['post_data']) ? $event['post_data'] : [];

        if (!$this->extension_enabled() || !$this->forum_is_enabled($forum_id) || !$this->allow_posting_panel($mode, $post_data))
        {
            return;
        }

        $topic_id = !empty($post_data['topic_id']) ? (int) $post_data['topic_id'] : 0;
        $meta = $topic_id > 0 ? $this->get_topic_meta($topic_id) : null;

        $status = $this->request->variable(
            'helpdesk_status',
            $meta ? (string) $meta['status_key'] : $this->default_status(),
            true
        );
        $priority = $this->request->variable(
            'helpdesk_priority',
            $meta ? (string) $meta['priority_key'] : 'normal',
            true
        );
        $category = $this->request->variable(
            'helpdesk_category',
            $meta ? $this->extract_category_key($meta) : '',
            true
        );
        $department = $this->request->variable(
            'helpdesk_department',
            $meta ? $this->extract_department_key($meta) : '',
            true
        );

        $page_data = isset($event['page_data']) && is_array($event['page_data']) ? $event['page_data'] : [];
        $page_data['S_HELPDESK_PANEL'] = true;
        $page_data['HELPDESK_SELECTED_STATUS'] = $status;
        $page_data['HELPDESK_SELECTED_PRIORITY'] = $priority;
        $page_data['HELPDESK_SELECTED_CATEGORY'] = $category;
        $page_data['HELPDESK_SELECTED_DEPARTMENT'] = $department;
        $subject_prefix = $this->subject_prefix();
        $page_data['S_HELPDESK_STATUS_ENABLED'] = $this->status_enabled();
        $page_data['S_HELPDESK_PRIORITY_ENABLED'] = $this->priority_enabled();
        $page_data['S_HELPDESK_CATEGORY_ENABLED'] = $this->category_enabled();
        $page_data['S_HELPDESK_DEPARTMENT_ENABLED'] = $this->department_enabled();
        $page_data['S_HELPDESK_SUBJECT_PREFIX'] = $mode === 'post' && $subject_prefix !== '';
        $page_data['HELPDESK_SUBJECT_PREFIX'] = $subject_prefix;
        $event['page_data'] = $page_data;

        foreach ($this->status_definitions() as $key => $definition)
        {
            $this->template->assign_block_vars('helpdesk_status_options', [
                'VALUE' => $key,
                'LABEL' => $this->status_label_from_definition($definition),
                'S_SELECTED' => $status === $key,
            ]);
        }

        foreach ($this->priority_definitions() as $key => $definition)
        {
            $this->template->assign_block_vars('helpdesk_priority_options', [
                'VALUE' => $key,
                'LABEL' => $this->priority_label_from_definition($definition),
                'S_SELECTED' => $priority === $key,
            ]);
        }

        foreach ($this->category_options() as $key => $label)
        {
            $this->template->assign_block_vars('helpdesk_category_options', [
                'VALUE' => $key,
                'LABEL' => $label,
                'S_SELECTED' => $category === $key,
            ]);
        }

        foreach ($this->department_options() as $key => $label)
        {
            $this->template->assign_block_vars('helpdesk_department_options', [
                'VALUE' => $key,
                'LABEL' => $label,
                'S_SELECTED' => $department === $key,
            ]);
        }
    }

    public function submit_post_end($event)
    {
        if (!$this->extension_enabled())
        {
            return;
        }

        $data = isset($event['data']) && is_array($event['data']) ? $event['data'] : [];
        $mode = isset($event['mode']) ? (string) $event['mode'] : '';
        $forum_id = !empty($data['forum_id']) ? (int) $data['forum_id'] : (isset($event['forum_id']) ? (int) $event['forum_id'] : 0);
        $topic_id = !empty($data['topic_id']) ? (int) $data['topic_id'] : 0;

        if ($topic_id <= 0 || $forum_id <= 0 || !$this->forum_is_enabled($forum_id))
        {
            return;
        }

        $existing = $this->get_topic_meta($topic_id);
        $has_post_fields = $this->request->variable('helpdesk_status', '', true) !== ''
            || $this->request->variable('helpdesk_priority', '', true) !== ''
            || $this->request->variable('helpdesk_category', '', true) !== ''
            || $this->request->variable('helpdesk_department', '', true) !== '';

        if ($mode === 'reply' && !$has_post_fields)
        {
            if ($existing)
            {
                $this->handle_reply_automation($topic_id, $forum_id, $existing, $data);
            }
            return;
        }

        $status = $this->normalize_status(
            $this->request->variable('helpdesk_status', $this->default_status(), true)
        );
        $priority = $this->normalize_priority(
            $this->request->variable('helpdesk_priority', 'normal', true)
        );
        $category_key = $this->sanitize_option_key(
            $this->request->variable('helpdesk_category', '', true),
            $this->category_options(),
            $this->category_enabled()
        );
        $department_key = $this->sanitize_option_key(
            $this->request->variable('helpdesk_department', '', true),
            $this->department_options(),
            $this->department_enabled()
        );
        $created_time = $existing ? (int) $existing['created_time'] : time();
        $old_status = $existing && isset($existing['status_key']) ? (string) $existing['status_key'] : '';

        $sql_ary = [
            'topic_id' => $topic_id,
            'forum_id' => $forum_id,
            'status_key' => $status,
            'priority_key' => $priority,
            'category_key' => $category_key,
            'department_key' => $department_key,
            'category_label' => $this->resolve_option_label($category_key, $this->category_options(), ''),
            'created_time' => $created_time,
            'updated_time' => time(),
        ];

        if ($existing)
        {
            $this->db->sql_query('UPDATE ' . $this->topics_table() . '
                SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
                WHERE topic_id = ' . (int) $topic_id);
        }
        else
        {
            $this->db->sql_query('INSERT INTO ' . $this->topics_table() . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
        }

        $this->apply_closed_topic_lock($topic_id, $status, $old_status);
        $this->topic_cache[$topic_id] = $sql_ary;
    }

    public function viewforum_modify_topicrow($event)
    {
        $this->inject_topicrow_meta($event);
    }

    public function mcp_view_forum_modify_topicrow($event)
    {
        $this->inject_topicrow_meta($event);
    }

    public function viewtopic_assign_template_vars_before($event)
    {
        $topic_data = isset($event['topic_data']) ? $event['topic_data'] : [];
        $topic_id = !empty($topic_data['topic_id']) ? (int) $topic_data['topic_id'] : 0;
        $forum_id = !empty($topic_data['forum_id']) ? (int) $topic_data['forum_id'] : 0;

        if (!$this->extension_enabled() || !$this->forum_is_enabled($forum_id) || $topic_id <= 0)
        {
            return;
        }

        $meta = $this->get_topic_meta($topic_id);
        if (!$meta)
        {
            return;
        }

        $can_manage_status = $this->status_enabled() && $this->can_manage_status($forum_id);
        $can_manage_priority = $this->priority_enabled() && $this->can_manage_priority($forum_id);
        $can_manage_department = $this->department_enabled() && $this->can_manage_department($forum_id);
        $can_manage_assignment = $this->assignment_enabled() && $this->can_manage_assignment($forum_id);
        $can_manage_panel = $can_manage_status || $can_manage_priority || $can_manage_department || $can_manage_assignment;

        if ($can_manage_panel)
        {
            $this->handle_topic_manage_update($topic_id, $forum_id, $meta);
            $meta = $this->get_topic_meta($topic_id);
        }

        $status_meta = $this->status_meta($meta['status_key']);
        $priority_meta = $this->priority_meta($meta['priority_key']);
        $category_label = $this->resolve_option_label($this->extract_category_key($meta), $this->category_options(), $this->extract_legacy_category_label($meta));
        $department_label = $this->resolve_option_label($this->extract_department_key($meta), $this->department_options(), '');
        $assigned_to = $this->extract_assigned_to($meta);
        $reply_count = $this->topic_reply_count($topic_data);
        $staff_reply_pending = $this->is_waiting_staff_response($topic_data, $status_meta['tone']);
        $reopen_count = $this->get_reopen_count($topic_id);
        $criticality = $this->operational_criticality(
            $status_meta['tone'],
            $priority_meta['tone'],
            $this->sla_enabled() && $this->is_ticket_overdue($meta),
            $this->sla_enabled() && $this->is_ticket_stale($topic_data, $meta),
            $reply_count <= 0,
            $this->is_ticket_very_old($meta),
            $assigned_to === '',
            $staff_reply_pending,
            $reopen_count > 0
        );

        $this->template->assign_vars([
            'S_HELPDESK_TOPIC_PANEL' => true,
            'HELPDESK_STATUS_LABEL' => $status_meta['label'],
            'HELPDESK_STATUS_CLASS' => $status_meta['class'],
            'S_HELPDESK_PRIORITY' => $this->priority_enabled() && $meta['priority_key'] !== '',
            'HELPDESK_PRIORITY_LABEL' => $priority_meta['label'],
            'HELPDESK_PRIORITY_CLASS' => $priority_meta['class'],
            'S_HELPDESK_CATEGORY' => $this->category_enabled() && $category_label !== '',
            'HELPDESK_CATEGORY_LABEL' => $category_label,
            'S_HELPDESK_DEPARTMENT' => $this->department_enabled() && $department_label !== '',
            'HELPDESK_DEPARTMENT_LABEL' => $department_label,
            'S_HELPDESK_ASSIGNED' => $this->assignment_enabled() && $assigned_to !== '',
            'HELPDESK_ASSIGNED_LABEL' => $assigned_to,
            'S_HELPDESK_STAFF_PENDING' => $staff_reply_pending,
            'S_HELPDESK_REOPENED' => $reopen_count > 0,
            'S_HELPDESK_CRITICALITY' => $criticality['key'] === 'critical' || $criticality['key'] === 'attention',
            'HELPDESK_CRITICALITY_LABEL' => $criticality['label'],
            'HELPDESK_CRITICALITY_CLASS' => $criticality['class'],
            'HELPDESK_UPDATED_AT' => !empty($meta['updated_time']) ? $this->user->format_date((int) $meta['updated_time']) : '',
            'S_HELPDESK_CAN_MANAGE_STATUS' => $can_manage_status,
            'S_HELPDESK_CAN_MANAGE_PRIORITY' => $can_manage_priority,
            'S_HELPDESK_CAN_MANAGE_DEPARTMENT' => $can_manage_department,
            'S_HELPDESK_CAN_MANAGE_ASSIGNMENT' => $can_manage_assignment,
            'S_HELPDESK_CAN_MANAGE_PANEL' => $can_manage_panel,
            'HELPDESK_MANAGE_PRIORITY_VALUE' => isset($meta['priority_key']) ? (string) $meta['priority_key'] : 'normal',
            'HELPDESK_ASSIGNED_TO_VALUE' => $assigned_to,
            'HELPDESK_CHANGE_REASON_VALUE' => '',
            'HELPDESK_MANAGE_FORM_TOKEN' => $this->build_form_token_fields('mundophpbb_helpdesk_topic_manage'),
            'S_HELPDESK_CAN_VIEW_TEAM_QUEUE' => $this->team_panel_enabled() && $this->can_view_team_queue(),
            'U_HELPDESK_TEAM_QUEUE' => $this->team_queue_url(),
        ]);

        if ($can_manage_status)
        {
            foreach ($this->status_definitions() as $key => $definition)
            {
                $this->template->assign_block_vars('helpdesk_manage_status_options', [
                    'VALUE' => $key,
                    'LABEL' => $this->status_label_from_definition($definition),
                    'S_SELECTED' => $meta['status_key'] === $key,
                ]);
            }
        }

        if ($can_manage_priority)
        {
            foreach ($this->priority_definitions() as $key => $definition)
            {
                $this->template->assign_block_vars('helpdesk_manage_priority_options', [
                    'VALUE' => $key,
                    'LABEL' => $this->priority_label_from_definition($definition),
                    'S_SELECTED' => (isset($meta['priority_key']) ? (string) $meta['priority_key'] : 'normal') === $key,
                ]);
            }
        }

        if ($can_manage_department)
        {
            $this->template->assign_block_vars('helpdesk_manage_department_options', [
                'VALUE' => '',
                'LABEL' => $this->user->lang('HELPDESK_EMPTY_OPTION'),
                'S_SELECTED' => $this->extract_department_key($meta) === '',
            ]);

            foreach ($this->department_options() as $key => $label)
            {
                $this->template->assign_block_vars('helpdesk_manage_department_options', [
                    'VALUE' => $key,
                    'LABEL' => $label,
                    'S_SELECTED' => $this->extract_department_key($meta) === $key,
                ]);
            }
        }

        foreach ($this->get_activity_history($topic_id, $this->activity_limit()) as $entry)
        {
            $this->template->assign_block_vars('helpdesk_activity_history', $entry);
        }
    }


    protected function handle_topic_manage_update($topic_id, $forum_id, array $meta)
    {
        if (!$this->request->is_set_post('helpdesk_save_management'))
        {
            return;
        }

        if (!check_form_key('mundophpbb_helpdesk_topic_manage'))
        {
            trigger_error('FORM_INVALID');
        }

        $old_status = isset($meta['status_key']) ? (string) $meta['status_key'] : $this->default_status();
        $new_status = ($this->status_enabled() && $this->can_manage_status($forum_id))
            ? $this->normalize_status($this->request->variable('helpdesk_manage_status', $old_status, true))
            : $old_status;
        $explicit_status_change = $new_status !== $old_status;

        $old_priority = isset($meta['priority_key']) ? (string) $meta['priority_key'] : 'normal';
        $new_priority = ($this->priority_enabled() && $this->can_manage_priority($forum_id))
            ? $this->normalize_priority($this->request->variable('helpdesk_manage_priority', $old_priority, true))
            : $old_priority;

        $old_department = $this->extract_department_key($meta);
        $new_department = ($this->department_enabled() && $this->can_manage_department($forum_id))
            ? $this->sanitize_option_key($this->request->variable('helpdesk_manage_department', $old_department, true), $this->department_options(), true)
            : $old_department;

        $old_assigned_to = $this->extract_assigned_to($meta);
        $new_assigned_to = ($this->assignment_enabled() && $this->can_manage_assignment($forum_id))
            ? $this->sanitize_assignee($this->request->variable('helpdesk_assigned_to', $old_assigned_to, true))
            : $old_assigned_to;
        $change_reason = $this->sanitize_change_reason($this->request->variable('helpdesk_change_reason', '', true));
        $status_reason = $change_reason;

        if (!$explicit_status_change)
        {
            $auto_status = '';
            if ($new_department !== $old_department)
            {
                $auto_status = $this->resolve_department_priority_rule_status($new_department, $new_priority, 'department_change', $this->operation_department_status());
                if ($auto_status !== '')
                {
                    $status_reason = $change_reason !== '' ? $change_reason : $this->user->lang('HELPDESK_AUTO_REASON_DEPARTMENT');
                }
            }

            if ($auto_status === '' && $new_assigned_to !== $old_assigned_to)
            {
                $auto_status = $new_assigned_to !== ''
                    ? $this->resolve_department_priority_rule_status($new_department, $new_priority, 'assign', $this->operation_assign_status())
                    : $this->resolve_department_priority_rule_status($new_department, $new_priority, 'unassign', $this->operation_unassign_status());

                if ($auto_status !== '')
                {
                    $status_reason = $change_reason !== '' ? $change_reason : $this->user->lang($new_assigned_to !== '' ? 'HELPDESK_AUTO_REASON_ASSIGN' : 'HELPDESK_AUTO_REASON_UNASSIGN');
                }
            }

            if ($auto_status === '' && $new_priority !== $old_priority)
            {
                $auto_status = $this->priority_change_status($new_priority, $new_department);
                if ($auto_status !== '')
                {
                    $status_reason = $change_reason !== '' ? $change_reason : $this->priority_change_reason_key($new_priority);
                }
            }

            if ($auto_status !== '' && $auto_status !== $old_status)
            {
                $new_status = $auto_status;
            }
        }

        $sql_ary = [
            'updated_time' => time(),
        ];
        $has_changes = false;

        if ($new_status !== $old_status)
        {
            $sql_ary['status_key'] = $new_status;
            $has_changes = true;
        }

        if ($new_priority !== $old_priority)
        {
            $sql_ary['priority_key'] = $new_priority;
            $has_changes = true;
        }

        if ($new_department !== $old_department)
        {
            $sql_ary['department_key'] = $new_department;
            $has_changes = true;
        }

        if ($new_assigned_to !== $old_assigned_to)
        {
            $sql_ary['assigned_to'] = $new_assigned_to;
            $sql_ary['assigned_time'] = $new_assigned_to !== '' ? time() : 0;
            $has_changes = true;
        }

        if (!$has_changes)
        {
            redirect($this->topic_url($forum_id, $topic_id));
        }

        $this->db->sql_query('UPDATE ' . $this->topics_table() . '
            SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
            WHERE topic_id = ' . (int) $topic_id);

        if ($new_status !== $old_status)
        {
            $this->insert_status_log($topic_id, $forum_id, $old_status, $new_status, $status_reason);
            $this->apply_closed_topic_lock($topic_id, $new_status, $old_status);
        }

        if ($new_priority !== $old_priority)
        {
            $this->insert_priority_log($topic_id, $forum_id, $old_priority, $new_priority, $change_reason);
        }

        if ($new_department !== $old_department)
        {
            $this->insert_department_log($topic_id, $forum_id, $old_department, $new_department, $change_reason);
        }

        if ($new_assigned_to !== $old_assigned_to)
        {
            $this->insert_assignment_log($topic_id, $forum_id, $old_assigned_to, $new_assigned_to, $change_reason);
        }

        $new_meta = $meta;
        $new_meta['status_key'] = $new_status;
        $new_meta['priority_key'] = $new_priority;
        $new_meta['department_key'] = $new_department;
        $new_meta['assigned_to'] = $new_assigned_to;
        $new_meta['updated_time'] = $sql_ary['updated_time'];
        $changes = $this->build_notification_changes($old_status, $new_status, $old_priority, $new_priority, $old_department, $new_department, $old_assigned_to, $new_assigned_to, $status_reason, $change_reason);
        $this->send_ticket_email_notifications($topic_id, $forum_id, $meta, $new_meta, $changes, $this->email_notify_author_enabled(), $this->email_notify_assignee_enabled());

        unset($this->topic_cache[$topic_id]);
        redirect($this->topic_url($forum_id, $topic_id));
    }

    protected function topic_url($forum_id, $topic_id)
    {
        global $phpbb_root_path, $phpEx;
        return append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'f=' . (int) $forum_id . '&t=' . (int) $topic_id . '#helpdesk-topic-panel');
    }

    protected function insert_status_log($topic_id, $forum_id, $old_status, $new_status, $reason = '')
    {
        $sql_ary = [
            'log_id' => $this->next_log_id(),
            'topic_id' => (int) $topic_id,
            'forum_id' => (int) $forum_id,
            'user_id' => (int) $this->user->data['user_id'],
            'action_key' => 'status_change',
            'old_value' => (string) $old_status,
            'new_value' => (string) $new_status,
            'log_time' => time(),
        ];

        if ($this->logs_support_reason())
        {
            $sql_ary['reason_text'] = (string) $reason;
        }

        $this->db->sql_query('INSERT INTO ' . $this->logs_table() . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
    }

    protected function insert_assignment_log($topic_id, $forum_id, $old_assigned_to, $new_assigned_to, $reason = '')
    {
        $sql_ary = [
            'log_id' => $this->next_log_id(),
            'topic_id' => (int) $topic_id,
            'forum_id' => (int) $forum_id,
            'user_id' => (int) $this->user->data['user_id'],
            'action_key' => 'assignment_change',
            'old_value' => (string) $old_assigned_to,
            'new_value' => (string) $new_assigned_to,
            'log_time' => time(),
        ];

        if ($this->logs_support_reason())
        {
            $sql_ary['reason_text'] = (string) $reason;
        }

        $this->db->sql_query('INSERT INTO ' . $this->logs_table() . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
    }

    protected function insert_department_log($topic_id, $forum_id, $old_department, $new_department, $reason = '')
    {
        $sql_ary = [
            'log_id' => $this->next_log_id(),
            'topic_id' => (int) $topic_id,
            'forum_id' => (int) $forum_id,
            'user_id' => (int) $this->user->data['user_id'],
            'action_key' => 'department_change',
            'old_value' => (string) $old_department,
            'new_value' => (string) $new_department,
            'log_time' => time(),
        ];

        if ($this->logs_support_reason())
        {
            $sql_ary['reason_text'] = (string) $reason;
        }

        $this->db->sql_query('INSERT INTO ' . $this->logs_table() . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
    }

    protected function insert_priority_log($topic_id, $forum_id, $old_priority, $new_priority, $reason = '')
    {
        $sql_ary = [
            'log_id' => $this->next_log_id(),
            'topic_id' => (int) $topic_id,
            'forum_id' => (int) $forum_id,
            'user_id' => (int) $this->user->data['user_id'],
            'action_key' => 'priority_change',
            'old_value' => (string) $old_priority,
            'new_value' => (string) $new_priority,
            'log_time' => time(),
        ];

        if ($this->logs_support_reason())
        {
            $sql_ary['reason_text'] = (string) $reason;
        }

        $this->db->sql_query('INSERT INTO ' . $this->logs_table() . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
    }

    protected function next_log_id()
    {
        $sql = 'SELECT MAX(log_id) AS max_log_id
            FROM ' . $this->logs_table();
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return (int) (!empty($row['max_log_id']) ? $row['max_log_id'] + 1 : 1);
    }

    protected function get_activity_history($topic_id, $limit = null)
    {
        $topic_id = (int) $topic_id;
        if ($topic_id <= 0)
        {
            return [];
        }

        if ($limit === null)
        {
            $limit = $this->activity_limit();
        }

        $sql = 'SELECT l.*, u.username, u.user_colour
            FROM ' . $this->logs_table() . ' l
            LEFT JOIN ' . $this->table_prefix . 'users u
                ON u.user_id = l.user_id
            WHERE l.topic_id = ' . $topic_id . "
                AND l.action_key IN ('status_change', 'priority_change', 'assignment_change', 'department_change')
            ORDER BY l.log_time DESC";
        $result = $this->db->sql_query_limit($sql, (int) $limit);

        $history = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $username = !empty($row['username']) ? (string) $row['username'] : $this->user->lang('GUEST');
            $action_key = isset($row['action_key']) ? (string) $row['action_key'] : '';
            $old_value = isset($row['old_value']) ? (string) $row['old_value'] : '';
            $new_value = isset($row['new_value']) ? (string) $row['new_value'] : '';
            $reason = !empty($row['reason_text']) ? $this->sanitize_change_reason($row['reason_text']) : '';
            $type_meta = $this->history_type_meta($action_key);

            if ($action_key === 'assignment_change')
            {
                if ($old_value === '' && $new_value !== '')
                {
                    $log_text = sprintf($this->user->lang('HELPDESK_LOG_ASSIGNED_TO'), $new_value);
                }
                else if ($old_value !== '' && $new_value === '')
                {
                    $log_text = sprintf($this->user->lang('HELPDESK_LOG_UNASSIGNED_FROM'), $old_value);
                }
                else
                {
                    $log_text = sprintf($this->user->lang('HELPDESK_LOG_REASSIGNED'), $old_value, $new_value);
                }
            }
            else if ($action_key === 'priority_change')
            {
                $old_meta = $this->priority_meta($old_value);
                $new_meta = $this->priority_meta($new_value);
                $log_text = sprintf($this->user->lang('HELPDESK_LOG_PRIORITY_CHANGED'), $old_meta['label'], $new_meta['label']);
            }
            else if ($action_key === 'department_change')
            {
                $old_label = $this->resolve_option_label($old_value, $this->department_options(), $old_value);
                $new_label = $this->resolve_option_label($new_value, $this->department_options(), $new_value);

                if ($old_value === '' && $new_value !== '')
                {
                    $log_text = sprintf($this->user->lang('HELPDESK_LOG_DEPARTMENT_SET'), $new_label);
                }
                else if ($old_value !== '' && $new_value === '')
                {
                    $log_text = sprintf($this->user->lang('HELPDESK_LOG_DEPARTMENT_CLEARED'), $old_label);
                }
                else
                {
                    $log_text = sprintf($this->user->lang('HELPDESK_LOG_DEPARTMENT_CHANGED'), $old_label, $new_label);
                }
            }
            else
            {
                $old_meta = $this->status_meta($old_value);
                $new_meta = $this->status_meta($new_value);
                $log_text = sprintf($this->user->lang('HELPDESK_LOG_STATUS_CHANGED'), $old_meta['label'], $new_meta['label']);
            }

            $history[] = [
                'ACTION_LABEL' => $type_meta['label'],
                'ACTION_CLASS' => $type_meta['class'],
                'LOG_TEXT' => $log_text,
                'LOG_REASON' => $reason,
                'LOG_USERNAME' => $username,
                'LOG_TIME' => $this->user->format_date((int) $row['log_time']),
            ];
        }
        $this->db->sql_freeresult($result);

        return $history;
    }

    protected function inject_topicrow_meta($event)
    {
        $row = isset($event['row']) ? $event['row'] : [];
        $topic_row = isset($event['topic_row']) ? $event['topic_row'] : [];
        $topic_id = !empty($row['topic_id']) ? (int) $row['topic_id'] : 0;
        $forum_id = !empty($row['forum_id']) ? (int) $row['forum_id'] : 0;

        $this->handle_viewforum_bulk_update($forum_id);
        $this->assign_viewforum_manage_vars($forum_id);

        $topic_row['S_HELPDESK_META'] = false;
        $topic_row['S_HELPDESK_PRIORITY'] = false;
        $topic_row['S_HELPDESK_CATEGORY'] = false;
        $topic_row['S_HELPDESK_DEPARTMENT'] = false;
        $topic_row['S_HELPDESK_ASSIGNED'] = false;

        if (!$this->extension_enabled() || !$this->forum_is_enabled($forum_id) || $topic_id <= 0)
        {
            $event['topic_row'] = $topic_row;
            return;
        }

        $meta = $this->get_topic_meta($topic_id);
        if (!$meta)
        {
            $event['topic_row'] = $topic_row;
            return;
        }

        $status_meta = $this->status_meta($meta['status_key']);
        $priority_meta = $this->priority_meta($meta['priority_key']);
        $category_label = $this->resolve_option_label($this->extract_category_key($meta), $this->category_options(), $this->extract_legacy_category_label($meta));
        $department_label = $this->resolve_option_label($this->extract_department_key($meta), $this->department_options(), '');
        $assigned_to = $this->extract_assigned_to($meta);
        $reply_count = $this->topic_reply_count($row);
        $staff_reply_pending = $this->is_waiting_staff_response($row, $status_meta['tone']);
        $reopen_count = $this->get_reopen_count($topic_id);
        $criticality = $this->operational_criticality(
            $status_meta['tone'],
            isset($meta['priority_key']) ? (string) $meta['priority_key'] : 'normal',
            $this->sla_enabled() && $this->is_ticket_overdue($meta),
            $this->sla_enabled() && $this->is_ticket_stale($row, $meta),
            $reply_count <= 0,
            $this->is_ticket_very_old($meta),
            $assigned_to === '',
            $staff_reply_pending,
            $reopen_count > 0
        );

        $topic_row['S_HELPDESK_META'] = true;
        $topic_row['HELPDESK_TOPIC_ID'] = $topic_id;
        $topic_row['HELPDESK_STATUS_KEY'] = (string) $meta['status_key'];
        $topic_row['S_HELPDESK_BULK_MANAGE'] = $this->can_bulk_manage($forum_id);
        $topic_row['HELPDESK_STATUS_LABEL'] = $status_meta['label'];
        $topic_row['HELPDESK_STATUS_CLASS'] = $status_meta['class'];
        $topic_row['HELPDESK_STATUS_TONE'] = isset($status_meta['tone']) ? (string) $status_meta['tone'] : 'open';
        $topic_row['HELPDESK_PRIORITY_KEY'] = isset($meta['priority_key']) ? (string) $meta['priority_key'] : 'normal';
        $topic_row['HELPDESK_TOPIC_POSTER_ID'] = $this->topic_poster_id($row);
        $topic_row['HELPDESK_LAST_POSTER_ID'] = $this->topic_last_poster_id($row);
        $topic_row['HELPDESK_CREATED_TS'] = !empty($meta['created_time']) ? (int) $meta['created_time'] : 0;
        $topic_row['HELPDESK_UPDATED_TS'] = !empty($meta['updated_time']) ? (int) $meta['updated_time'] : 0;
        $topic_row['HELPDESK_LAST_ACTIVITY_TS'] = $this->topic_last_activity_time($row, $meta);
        $topic_row['HELPDESK_REPLY_COUNT'] = $reply_count;
        $topic_row['HELPDESK_REOPEN_COUNT'] = $reopen_count;
        $topic_row['HELPDESK_CRITICALITY_KEY'] = $criticality['key'];
        $topic_row['HELPDESK_CRITICALITY_LABEL'] = $criticality['label'];
        $topic_row['HELPDESK_CRITICALITY_CLASS'] = $criticality['class'];
        $topic_row['S_HELPDESK_SLA_ACTIVE'] = $this->sla_enabled() && $this->is_active_status_tone($topic_row['HELPDESK_STATUS_TONE']);
        $topic_row['S_HELPDESK_SLA_OVERDUE'] = $this->sla_enabled() && $this->is_active_status_tone($topic_row['HELPDESK_STATUS_TONE']) && $this->is_ticket_overdue($meta);
        $topic_row['S_HELPDESK_STALE'] = $this->sla_enabled() && $this->is_active_status_tone($topic_row['HELPDESK_STATUS_TONE']) && $this->is_ticket_stale($row, $meta);
        $topic_row['S_HELPDESK_FIRST_REPLY_PENDING'] = $this->is_active_status_tone($topic_row['HELPDESK_STATUS_TONE']) && $topic_row['HELPDESK_REPLY_COUNT'] <= 0;
        $topic_row['S_HELPDESK_VERY_OLD'] = $this->is_active_status_tone($topic_row['HELPDESK_STATUS_TONE']) && $this->is_ticket_very_old($meta);
        $topic_row['S_HELPDESK_STAFF_PENDING'] = $staff_reply_pending;
        $topic_row['S_HELPDESK_REOPENED'] = $reopen_count > 0;
        $topic_row['S_HELPDESK_CRITICALITY'] = $criticality['key'] === 'critical' || $criticality['key'] === 'attention';
        $topic_row['S_HELPDESK_PRIORITY'] = $this->priority_enabled() && $meta['priority_key'] !== '';
        $topic_row['HELPDESK_PRIORITY_LABEL'] = $priority_meta['label'];
        $topic_row['HELPDESK_PRIORITY_CLASS'] = $priority_meta['class'];
        $topic_row['S_HELPDESK_CATEGORY'] = $this->category_enabled() && $category_label !== '';
        $topic_row['HELPDESK_CATEGORY_LABEL'] = $category_label;
        $topic_row['S_HELPDESK_DEPARTMENT'] = $this->department_enabled() && $department_label !== '';
        $topic_row['HELPDESK_DEPARTMENT_LABEL'] = $department_label;
        $topic_row['S_HELPDESK_ASSIGNED'] = $this->assignment_enabled() && $assigned_to !== '';
        $topic_row['HELPDESK_ASSIGNED_LABEL'] = $assigned_to;
        $event['topic_row'] = $topic_row;
    }


    protected function handle_viewforum_bulk_update($forum_id)
    {
        if ($this->bulk_manage_processed)
        {
            return;
        }

        $this->bulk_manage_processed = true;

        if (!$this->request->is_set_post('helpdesk_bulk_apply'))
        {
            return;
        }

        $forum_id = (int) ($forum_id ?: $this->request->variable('f', 0));
        if (!$this->extension_enabled() || !$this->forum_is_enabled($forum_id) || !$this->can_bulk_manage($forum_id))
        {
            return;
        }

        if (!check_form_key('mundophpbb_helpdesk_bulk_manage'))
        {
            trigger_error('FORM_INVALID');
        }

        $selected_ids = $this->request->variable('helpdesk_bulk_topic_ids', [0]);
        $selected_ids = array_values(array_unique(array_filter(array_map('intval', (array) $selected_ids))));
        if (empty($selected_ids))
        {
            redirect($this->forum_url($forum_id));
        }

        $raw_status = $this->request->variable('helpdesk_bulk_status', '', true);
        $new_status = $raw_status !== '' ? $this->normalize_status($raw_status) : '';
        $explicit_status_change = $new_status !== '';

        $raw_priority = $this->request->variable('helpdesk_bulk_priority', '__NO_CHANGE__', true);
        $priority_has_change = $this->priority_enabled() && $raw_priority !== '__NO_CHANGE__';
        $new_priority = '';
        if ($priority_has_change)
        {
            $new_priority = $this->normalize_priority($raw_priority);
        }

        $raw_department = $this->request->variable('helpdesk_bulk_department', '__NO_CHANGE__', true);
        $department_has_change = $this->department_enabled() && $raw_department !== '__NO_CHANGE__';
        $new_department = '';
        if ($department_has_change)
        {
            $new_department = $raw_department === '__CLEAR__'
                ? ''
                : $this->sanitize_option_key($raw_department, $this->department_options(), true);
        }

        $assignment_action = $this->assignment_enabled()
            ? $this->request->variable('helpdesk_bulk_assignment_action', 'keep', true)
            : 'keep';
        $assignee_input = $this->assignment_enabled()
            ? $this->sanitize_assignee($this->request->variable('helpdesk_bulk_assigned_to', '', true))
            : '';

        $assignment_has_change = false;
        $new_assigned_to = '';
        if ($assignment_action === 'clear')
        {
            $assignment_has_change = true;
            $new_assigned_to = '';
        }
        else if ($assignment_action === 'set' && $assignee_input !== '')
        {
            $assignment_has_change = true;
            $new_assigned_to = $assignee_input;
        }

        if ($new_status === '' && !$priority_has_change && !$department_has_change && !$assignment_has_change)
        {
            redirect($this->forum_url($forum_id));
        }

        $sql = 'SELECT *
            FROM ' . $this->topics_table() . '
            WHERE forum_id = ' . (int) $forum_id . '
                AND ' . $this->db->sql_in_set('topic_id', $selected_ids);
        $result = $this->db->sql_query($sql);

        while ($meta = $this->db->sql_fetchrow($result))
        {
            $topic_id = (int) $meta['topic_id'];
            $update_sql = [
                'updated_time' => time(),
            ];
            $has_changes = false;

            $old_status = isset($meta['status_key']) ? (string) $meta['status_key'] : $this->default_status();
            $applied_status = $new_status;
            $status_reason = '';

            $old_priority = isset($meta['priority_key']) ? (string) $meta['priority_key'] : 'normal';
            if ($priority_has_change && $old_priority !== $new_priority)
            {
                $update_sql['priority_key'] = $new_priority;
                $has_changes = true;
            }

            $old_department = $this->extract_department_key($meta);
            if ($department_has_change && $old_department !== $new_department)
            {
                $update_sql['department_key'] = $new_department;
                $has_changes = true;
            }

            $old_assigned_to = $this->extract_assigned_to($meta);
            if ($assignment_has_change && $old_assigned_to !== $new_assigned_to)
            {
                $update_sql['assigned_to'] = $new_assigned_to;
                $update_sql['assigned_time'] = $new_assigned_to !== '' ? time() : 0;
                $has_changes = true;
            }

            if (!$explicit_status_change)
            {
                if ($department_has_change && $old_department !== $new_department)
                {
                    $applied_status = $this->resolve_department_priority_rule_status($new_department, $priority_has_change ? $new_priority : $old_priority, 'department_change', $this->operation_department_status());
                    if ($applied_status !== '')
                    {
                        $status_reason = $this->user->lang('HELPDESK_AUTO_REASON_DEPARTMENT');
                    }
                }

                if ($applied_status === '' && $assignment_has_change && $old_assigned_to !== $new_assigned_to)
                {
                    $effective_department = $department_has_change ? $new_department : $old_department;
                    $effective_priority = $priority_has_change ? $new_priority : $old_priority;
                    $applied_status = $new_assigned_to !== ''
                        ? $this->resolve_department_priority_rule_status($effective_department, $effective_priority, 'assign', $this->operation_assign_status())
                        : $this->resolve_department_priority_rule_status($effective_department, $effective_priority, 'unassign', $this->operation_unassign_status());
                    if ($applied_status !== '')
                    {
                        $status_reason = $this->user->lang($new_assigned_to !== '' ? 'HELPDESK_AUTO_REASON_ASSIGN' : 'HELPDESK_AUTO_REASON_UNASSIGN');
                    }
                }

                if ($applied_status === '' && $priority_has_change && $old_priority !== $new_priority)
                {
                    $applied_status = $this->priority_change_status($new_priority, $department_has_change ? $new_department : $old_department);
                    if ($applied_status !== '')
                    {
                        $status_reason = $this->user->lang($this->priority_change_reason_key($new_priority));
                    }
                }
            }

            if ($applied_status !== '' && $old_status !== $applied_status)
            {
                $update_sql['status_key'] = $applied_status;
                $has_changes = true;
            }

            if (!$has_changes)
            {
                continue;
            }

            $this->db->sql_query('UPDATE ' . $this->topics_table() . '
                SET ' . $this->db->sql_build_array('UPDATE', $update_sql) . '
                WHERE topic_id = ' . $topic_id);

            if ($applied_status !== '' && $old_status !== $applied_status)
            {
                $this->insert_status_log($topic_id, $forum_id, $old_status, $applied_status, $status_reason);
                $this->apply_closed_topic_lock($topic_id, $applied_status, $old_status);
            }

            if ($priority_has_change && $old_priority !== $new_priority)
            {
                $this->insert_priority_log($topic_id, $forum_id, $old_priority, $new_priority, '');
            }

            if ($department_has_change && $old_department !== $new_department)
            {
                $this->insert_department_log($topic_id, $forum_id, $old_department, $new_department, '');
            }

            if ($assignment_has_change && $old_assigned_to !== $new_assigned_to)
            {
                $this->insert_assignment_log($topic_id, $forum_id, $old_assigned_to, $new_assigned_to, '');
            }

            $new_meta = $meta;
            $new_meta['status_key'] = $applied_status !== '' ? $applied_status : $old_status;
            $new_meta['priority_key'] = $priority_has_change ? $new_priority : $old_priority;
            $new_meta['department_key'] = $department_has_change ? $new_department : $old_department;
            $new_meta['assigned_to'] = $assignment_has_change ? $new_assigned_to : $old_assigned_to;
            $new_meta['updated_time'] = $update_sql['updated_time'];
            $changes = $this->build_notification_changes($old_status, $new_meta['status_key'], $old_priority, $new_meta['priority_key'], $old_department, $new_meta['department_key'], $old_assigned_to, $new_meta['assigned_to'], $status_reason, '');
            $this->send_ticket_email_notifications($topic_id, $forum_id, $meta, $new_meta, $changes, $this->email_notify_author_enabled(), $this->email_notify_assignee_enabled());

            unset($this->topic_cache[$topic_id]);
        }
        $this->db->sql_freeresult($result);

        redirect($this->forum_url($forum_id));
    }

    protected function assign_viewforum_manage_vars($forum_id)
    {
        if ($this->bulk_manage_template_assigned)
        {
            return;
        }

        $forum_id = (int) ($forum_id ?: $this->request->variable('f', 0));
        $can_bulk_manage = $this->extension_enabled() && $this->forum_is_enabled($forum_id) && $this->can_bulk_manage($forum_id);

        $this->template->assign_vars([
            'S_HELPDESK_CAN_BULK_MANAGE' => $can_bulk_manage,
            'S_HELPDESK_BULK_PRIORITY_ENABLED' => $can_bulk_manage && $this->priority_enabled(),
            'S_HELPDESK_BULK_DEPARTMENT_ENABLED' => $can_bulk_manage && $this->department_enabled(),
            'S_HELPDESK_BULK_ASSIGNMENT_ENABLED' => $can_bulk_manage && $this->assignment_enabled(),
            'S_HELPDESK_SLA_ENABLED' => $this->sla_enabled(),
            'HELPDESK_SLA_HOURS' => $this->sla_hours(),
            'HELPDESK_STALE_HOURS' => $this->stale_hours(),
            'HELPDESK_OLD_HOURS' => $this->old_hours(),
            'HELPDESK_BULK_FORM_TOKEN' => $can_bulk_manage ? $this->build_form_token_fields('mundophpbb_helpdesk_bulk_manage') : '',
            'S_HELPDESK_CAN_VIEW_TEAM_QUEUE' => $this->team_panel_enabled() && $this->can_view_team_queue(),
            'U_HELPDESK_TEAM_QUEUE' => $this->team_queue_url(),
        ]);

        if ($can_bulk_manage)
        {
            foreach ($this->status_definitions() as $key => $definition)
            {
                $this->template->assign_block_vars('helpdesk_bulk_status_options', [
                    'VALUE' => $key,
                    'LABEL' => $this->status_label_from_definition($definition),
                ]);
            }

            foreach ($this->priority_definitions() as $key => $definition)
            {
                $this->template->assign_block_vars('helpdesk_bulk_priority_options', [
                    'VALUE' => $key,
                    'LABEL' => $this->priority_label_from_definition($definition),
                ]);
            }

            foreach ($this->department_options() as $key => $label)
            {
                $this->template->assign_block_vars('helpdesk_bulk_department_options', [
                    'VALUE' => $key,
                    'LABEL' => $label,
                ]);
            }
        }

        $this->bulk_manage_template_assigned = true;
    }

    protected function forum_url($forum_id)
    {
        global $phpbb_root_path, $phpEx;
        return append_sid("{$phpbb_root_path}viewforum.$phpEx", 'f=' . (int) $forum_id . '#helpdesk-bulk-panel');
    }

    protected function apply_closed_topic_lock($topic_id, $status_key, $previous_status_key = '')
    {
        if (!$this->automation_enabled())
        {
            return;
        }

        $should_lock = $this->status_requires_lock($status_key);
        $previous_was_locked = $previous_status_key !== '' && $this->status_requires_lock($previous_status_key);
        $item_locked = defined('ITEM_LOCKED') ? (int) ITEM_LOCKED : 1;
        $item_unlocked = defined('ITEM_UNLOCKED') ? (int) ITEM_UNLOCKED : 0;

        if ($should_lock)
        {
            if (!$this->auto_lock_closed_enabled())
            {
                return;
            }

            $topic_status = $item_locked;
        }
        else
        {
            if (!$previous_was_locked || !$this->auto_unlock_reopened_enabled())
            {
                return;
            }

            $topic_status = $item_unlocked;
        }

        $this->db->sql_query('UPDATE ' . $this->table_prefix . 'topics
            SET topic_status = ' . (int) $topic_status . '
            WHERE topic_id = ' . (int) $topic_id);
    }

    protected function status_requires_lock($status_key)
    {
        $definitions = $this->status_definitions();
        $status_key = (string) $status_key;

        if (isset($definitions[$status_key]) && !empty($definitions[$status_key]['tone']))
        {
            return $definitions[$status_key]['tone'] === 'closed';
        }

        return $this->normalize_status_tone($status_key) === 'closed';
    }

    protected function handle_reply_automation($topic_id, $forum_id, array $meta, array $data)
    {
        $topic_id = (int) $topic_id;
        $forum_id = (int) $forum_id;
        if (!$this->automation_enabled() || $topic_id <= 0 || $forum_id <= 0)
        {
            return;
        }

        $old_status = isset($meta['status_key']) ? (string) $meta['status_key'] : $this->default_status();
        $new_status = $old_status;
        $old_assigned_to = $this->extract_assigned_to($meta);
        $new_assigned_to = $old_assigned_to;
        $is_team_actor = $this->can_manage_topic($forum_id) || $this->can_manage_assignment($forum_id) || $this->can_bulk_manage($forum_id);
        $reason = '';

        if ($is_team_actor)
        {
            $configured_status = $this->resolve_department_priority_rule_status($this->extract_department_key($meta), isset($meta['priority_key']) ? (string) $meta['priority_key'] : 'normal', 'team_reply', $this->automation_team_reply_status());
            if ($configured_status !== '')
            {
                $new_status = $configured_status;
            }

            if ($this->auto_assign_team_reply_enabled() && $new_assigned_to === '')
            {
                $new_assigned_to = $this->sanitize_assignee(isset($this->user->data['username']) ? $this->user->data['username'] : '');
            }

            $reason = $this->user->lang('HELPDESK_AUTO_REASON_TEAM_REPLY');
        }
        else
        {
            $topic_poster_id = $this->topic_poster_id($data);
            if ($topic_poster_id <= 0)
            {
                $topic_poster_id = $this->get_topic_owner_id($topic_id);
            }

            $current_user_id = !empty($this->user->data['user_id']) ? (int) $this->user->data['user_id'] : 0;
            if ($topic_poster_id > 0 && $current_user_id > 0 && $topic_poster_id === $current_user_id)
            {
                $configured_status = $this->resolve_department_priority_rule_status($this->extract_department_key($meta), isset($meta['priority_key']) ? (string) $meta['priority_key'] : 'normal', 'user_reply', $this->automation_user_reply_status());
                if ($configured_status !== '')
                {
                    $new_status = $configured_status;
                }
                $reason = $this->user->lang('HELPDESK_AUTO_REASON_USER_REPLY');
            }
        }

        $update_sql = [
            'updated_time' => time(),
        ];

        if ($new_status !== $old_status)
        {
            $update_sql['status_key'] = $new_status;
        }

        if ($new_assigned_to !== $old_assigned_to)
        {
            $update_sql['assigned_to'] = $new_assigned_to;
            $update_sql['assigned_time'] = $new_assigned_to !== '' ? time() : 0;
        }

        $this->db->sql_query('UPDATE ' . $this->topics_table() . '
            SET ' . $this->db->sql_build_array('UPDATE', $update_sql) . '
            WHERE topic_id = ' . $topic_id);

        if ($new_status !== $old_status)
        {
            $this->insert_status_log($topic_id, $forum_id, $old_status, $new_status, $reason);
            $this->apply_closed_topic_lock($topic_id, $new_status, $old_status);
            $meta['status_key'] = $new_status;
        }

        if ($new_assigned_to !== $old_assigned_to)
        {
            $this->insert_assignment_log($topic_id, $forum_id, $old_assigned_to, $new_assigned_to, $reason);
            $meta['assigned_to'] = $new_assigned_to;
            $meta['assigned_time'] = isset($update_sql['assigned_time']) ? $update_sql['assigned_time'] : 0;
        }

        $meta['updated_time'] = $update_sql['updated_time'];
        $reply_changes = [];
        $reply_changes[] = ['type' => $is_team_actor ? 'team_reply' : 'user_reply'];
        $reply_changes = array_merge($reply_changes, $this->build_notification_changes($old_status, $new_status, isset($meta['priority_key']) ? (string) $meta['priority_key'] : 'normal', isset($meta['priority_key']) ? (string) $meta['priority_key'] : 'normal', '', '', $old_assigned_to, $new_assigned_to, $reason, ''));
        $this->send_ticket_email_notifications(
            $topic_id,
            $forum_id,
            ['status_key' => $old_status, 'assigned_to' => $old_assigned_to],
            $meta,
            $reply_changes,
            $is_team_actor ? $this->email_notify_author_enabled() : false,
            $is_team_actor ? $this->email_notify_assignee_enabled() : $this->email_notify_user_reply_enabled()
        );
        $this->topic_cache[$topic_id] = $meta;
    }

    protected function automation_enabled()
    {
        return !empty($this->config['mundophpbb_helpdesk_automation_enable']);
    }

    protected function auto_lock_closed_enabled()
    {
        return !empty($this->config['mundophpbb_helpdesk_auto_lock_closed']);
    }

    protected function auto_unlock_reopened_enabled()
    {
        return !empty($this->config['mundophpbb_helpdesk_auto_unlock_reopened']);
    }

    protected function auto_assign_team_reply_enabled()
    {
        return !empty($this->config['mundophpbb_helpdesk_auto_assign_team_reply']);
    }

    protected function automation_team_reply_status()
    {
        return $this->configured_automation_status(isset($this->config['mundophpbb_helpdesk_team_reply_status']) ? (string) $this->config['mundophpbb_helpdesk_team_reply_status'] : '', 'waiting_reply');
    }

    protected function automation_user_reply_status()
    {
        return $this->configured_automation_status(isset($this->config['mundophpbb_helpdesk_user_reply_status']) ? (string) $this->config['mundophpbb_helpdesk_user_reply_status'] : '', 'in_progress');
    }

    protected function operation_assign_status()
    {
        return $this->configured_optional_status(isset($this->config['mundophpbb_helpdesk_assign_status']) ? (string) $this->config['mundophpbb_helpdesk_assign_status'] : '');
    }

    protected function operation_unassign_status()
    {
        return $this->configured_optional_status(isset($this->config['mundophpbb_helpdesk_unassign_status']) ? (string) $this->config['mundophpbb_helpdesk_unassign_status'] : '');
    }

    protected function operation_department_status()
    {
        return $this->configured_optional_status(isset($this->config['mundophpbb_helpdesk_department_status']) ? (string) $this->config['mundophpbb_helpdesk_department_status'] : '');
    }

    protected function operation_priority_high_status()
    {
        return $this->configured_optional_status(isset($this->config['mundophpbb_helpdesk_priority_high_status']) ? (string) $this->config['mundophpbb_helpdesk_priority_high_status'] : '');
    }

    protected function operation_priority_critical_status()
    {
        return $this->configured_optional_status(isset($this->config['mundophpbb_helpdesk_priority_critical_status']) ? (string) $this->config['mundophpbb_helpdesk_priority_critical_status'] : '');
    }

    protected function priority_change_status($priority_key, $department_key = '')
    {
        $tone = $this->priority_meta($priority_key)['tone'];
        if ($tone === 'critical')
        {
            return $this->resolve_department_priority_rule_status($department_key, $priority_key, 'priority_critical', $this->operation_priority_critical_status());
        }
        if ($tone === 'high')
        {
            return $this->resolve_department_priority_rule_status($department_key, $priority_key, 'priority_high', $this->operation_priority_high_status());
        }

        return '';
    }

    protected function priority_change_reason_key($priority_key)
    {
        $tone = $this->priority_meta($priority_key)['tone'];
        if ($tone === 'critical')
        {
            return 'HELPDESK_AUTO_REASON_PRIORITY_CRITICAL';
        }
        if ($tone === 'high')
        {
            return 'HELPDESK_AUTO_REASON_PRIORITY_HIGH';
        }

        return '';
    }

    protected function department_priority_rule_definitions()
    {
        static $definitions = null;

        if ($definitions !== null)
        {
            return $definitions;
        }

        $definitions = [];
        $raw = isset($this->config['mundophpbb_helpdesk_department_priority_rule_definitions']) ? (string) $this->config['mundophpbb_helpdesk_department_priority_rule_definitions'] : '';
        $lines = preg_split('/\r\n|\r|\n/', $raw);

        foreach ($lines as $line)
        {
            $line = trim((string) $line);
            if ($line === '')
            {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            $department_key = $this->normalize_option_key(isset($parts[0]) ? $parts[0] : '');
            $priority_key = $this->normalize_priority(isset($parts[1]) ? $parts[1] : '');
            if ($department_key === '' || $priority_key === '')
            {
                continue;
            }

            if (!isset($definitions[$department_key]))
            {
                $definitions[$department_key] = [];
            }

            $definitions[$department_key][$priority_key] = [
                'team_reply' => $this->configured_optional_status(isset($parts[2]) ? $parts[2] : ''),
                'user_reply' => $this->configured_optional_status(isset($parts[3]) ? $parts[3] : ''),
                'assign' => $this->configured_optional_status(isset($parts[4]) ? $parts[4] : ''),
                'unassign' => $this->configured_optional_status(isset($parts[5]) ? $parts[5] : ''),
                'department_change' => $this->configured_optional_status(isset($parts[6]) ? $parts[6] : ''),
                'priority_high' => $this->configured_optional_status(isset($parts[7]) ? $parts[7] : ''),
                'priority_critical' => $this->configured_optional_status(isset($parts[8]) ? $parts[8] : ''),
            ];
        }

        return $definitions;
    }

    protected function resolve_department_priority_rule_status($department_key, $priority_key, $rule_key, $fallback = '')
    {
        $department_key = $this->normalize_option_key($department_key);
        $priority_key = $this->normalize_priority($priority_key);
        $rule_key = trim((string) $rule_key);

        if ($department_key !== '' && $priority_key !== '' && $rule_key !== '')
        {
            $definitions = $this->department_priority_rule_definitions();
            if (isset($definitions[$department_key][$priority_key][$rule_key]) && $definitions[$department_key][$priority_key][$rule_key] !== '')
            {
                return $definitions[$department_key][$priority_key][$rule_key];
            }
        }

        return $this->resolve_department_rule_status($department_key, $rule_key, $fallback);
    }

    protected function department_rule_definitions()
    {
        static $definitions = null;

        if ($definitions !== null)
        {
            return $definitions;
        }

        $definitions = [];
        $raw = isset($this->config['mundophpbb_helpdesk_department_rule_definitions']) ? (string) $this->config['mundophpbb_helpdesk_department_rule_definitions'] : '';
        $lines = preg_split('/\r\n|\r|\n/', $raw);

        foreach ($lines as $line)
        {
            $line = trim((string) $line);
            if ($line === '')
            {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            $department_key = $this->normalize_option_key(isset($parts[0]) ? $parts[0] : '');
            if ($department_key === '')
            {
                continue;
            }

            $definitions[$department_key] = [
                'team_reply' => $this->configured_optional_status(isset($parts[1]) ? $parts[1] : ''),
                'user_reply' => $this->configured_optional_status(isset($parts[2]) ? $parts[2] : ''),
                'assign' => $this->configured_optional_status(isset($parts[3]) ? $parts[3] : ''),
                'unassign' => $this->configured_optional_status(isset($parts[4]) ? $parts[4] : ''),
                'department_change' => $this->configured_optional_status(isset($parts[5]) ? $parts[5] : ''),
            ];
        }

        return $definitions;
    }

    protected function resolve_department_rule_status($department_key, $rule_key, $fallback = '')
    {
        $department_key = $this->normalize_option_key($department_key);
        $rule_key = trim((string) $rule_key);

        if ($department_key !== '' && $rule_key !== '')
        {
            $definitions = $this->department_rule_definitions();
            if (isset($definitions[$department_key][$rule_key]) && $definitions[$department_key][$rule_key] !== '')
            {
                return $definitions[$department_key][$rule_key];
            }
        }

        return $fallback !== '' ? $this->configured_optional_status($fallback) : '';
    }

    protected function configured_optional_status($configured)
    {
        $configured = trim((string) $configured);
        if ($configured === '')
        {
            return '';
        }

        $definitions = $this->status_definitions();
        return array_key_exists($configured, $definitions) ? $configured : '';
    }

    protected function configured_automation_status($configured, $fallback)
    {
        $configured = trim((string) $configured);
        $definitions = $this->status_definitions();
        if ($configured === '')
        {
            return '';
        }

        if (array_key_exists($configured, $definitions))
        {
            return $configured;
        }

        if (array_key_exists($fallback, $definitions))
        {
            return $fallback;
        }

        if ($fallback !== 'open' && array_key_exists('open', $definitions))
        {
            return 'open';
        }

        return '';
    }

    protected function get_topic_owner_id($topic_id)
    {
        $sql = 'SELECT topic_poster
            FROM ' . $this->table_prefix . 'topics
            WHERE topic_id = ' . (int) $topic_id;
        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return !empty($row['topic_poster']) ? (int) $row['topic_poster'] : 0;
    }

    protected function build_notification_changes($old_status, $new_status, $old_priority, $new_priority, $old_department, $new_department, $old_assigned_to, $new_assigned_to, $status_reason = '', $change_reason = '')
    {
        $changes = [];

        if ((string) $new_status !== (string) $old_status)
        {
            $changes[] = [
                'type' => 'status',
                'old' => (string) $old_status,
                'new' => (string) $new_status,
                'reason' => (string) $status_reason,
            ];
        }

        if ((string) $new_priority !== (string) $old_priority)
        {
            $changes[] = [
                'type' => 'priority',
                'old' => (string) $old_priority,
                'new' => (string) $new_priority,
                'reason' => (string) $change_reason,
            ];
        }

        if ((string) $new_department !== (string) $old_department)
        {
            $changes[] = [
                'type' => 'department',
                'old' => (string) $old_department,
                'new' => (string) $new_department,
                'reason' => (string) $change_reason,
            ];
        }

        if ((string) $new_assigned_to !== (string) $old_assigned_to)
        {
            $changes[] = [
                'type' => 'assignment',
                'old' => (string) $old_assigned_to,
                'new' => (string) $new_assigned_to,
                'reason' => (string) $change_reason,
            ];
        }

        return $changes;
    }

    protected function send_ticket_email_notifications($topic_id, $forum_id, array $old_meta, array $new_meta, array $changes, $send_author, $send_assignee)
    {
        if (empty($changes) || !$this->email_notifications_enabled())
        {
            return;
        }

        $topic = $this->get_topic_context_for_email($topic_id, $forum_id);
        if (empty($topic))
        {
            return;
        }

        $actor_user_id = !empty($this->user->data['user_id']) ? (int) $this->user->data['user_id'] : 0;
        $sent_user_ids = [];

        if ($send_author)
        {
            $author = $this->get_user_row_by_id((int) $topic['topic_poster']);
            if (!empty($author) && (int) $author['user_id'] !== $actor_user_id)
            {
                $this->deliver_ticket_change_email($author, 'author', $topic, $changes, $sent_user_ids);
            }
        }

        if ($send_assignee)
        {
            $assignee_name = $this->extract_assigned_to($new_meta);
            if ($assignee_name === '')
            {
                $assignee_name = $this->extract_assigned_to($old_meta);
            }

            if ($assignee_name !== '')
            {
                $assignee = $this->get_user_row_by_username($assignee_name);
                if (!empty($assignee) && (int) $assignee['user_id'] !== $actor_user_id)
                {
                    $this->deliver_ticket_change_email($assignee, 'assignee', $topic, $changes, $sent_user_ids);
                }
            }
        }
    }

    protected function deliver_ticket_change_email(array $recipient, $role, array $topic, array $changes, array &$sent_user_ids)
    {
        $recipient_id = !empty($recipient['user_id']) ? (int) $recipient['user_id'] : 0;
        if ($recipient_id <= ANONYMOUS || in_array($recipient_id, $sent_user_ids, true))
        {
            return;
        }

        $email = !empty($recipient['user_email']) ? trim((string) $recipient['user_email']) : '';
        if ($email === '')
        {
            return;
        }

        $lang = $this->notification_lang(isset($recipient['user_lang']) ? $recipient['user_lang'] : 'en');
        $lines = $this->build_notification_lines($lang, $changes);
        if (empty($lines))
        {
            return;
        }

        $subject = $this->build_notification_subject($lang, $topic, $changes);
        $body = $this->build_notification_body($lang, $recipient, $role, $topic, $changes, $lines);
        if ($body === '')
        {
            return;
        }

        if ($this->send_plain_email($email, $subject, $body))
        {
            $sent_user_ids[] = $recipient_id;
        }
    }

    protected function get_topic_context_for_email($topic_id, $forum_id)
    {
        $sql = 'SELECT topic_id, topic_title, topic_poster
            FROM ' . $this->table_prefix . 'topics
            WHERE topic_id = ' . (int) $topic_id;
        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row)
        {
            return [];
        }

        $row['forum_id'] = (int) $forum_id;
        $row['ticket_url'] = $this->plain_topic_url($forum_id, $topic_id);
        return $row;
    }

    protected function get_user_row_by_id($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= ANONYMOUS)
        {
            return [];
        }

        $sql = 'SELECT user_id, username, user_email, user_lang
            FROM ' . $this->table_prefix . 'users
            WHERE user_id = ' . $user_id;
        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ?: [];
    }

    protected function get_user_row_by_username($username)
    {
        $username = trim((string) $username);
        if ($username === '')
        {
            return [];
        }

        $username_clean = function_exists('utf8_clean_string') ? utf8_clean_string($username) : strtolower($username);
        $sql = 'SELECT user_id, username, user_email, user_lang
            FROM ' . $this->table_prefix . "users
            WHERE username_clean = '" . $this->db->sql_escape($username_clean) . "'";
        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row)
        {
            $sql = 'SELECT user_id, username, user_email, user_lang
                FROM ' . $this->table_prefix . "users
                WHERE username = '" . $this->db->sql_escape($username) . "'";
            $result = $this->db->sql_query_limit($sql, 1);
            $row = $this->db->sql_fetchrow($result);
            $this->db->sql_freeresult($result);
        }

        return $row ?: [];
    }

    protected function plain_topic_url($forum_id, $topic_id)
    {
        global $phpEx;
        return generate_board_url() . '/viewtopic.' . $phpEx . '?f=' . (int) $forum_id . '&t=' . (int) $topic_id;
    }

    protected function build_notification_subject($lang, array $topic, array $changes)
    {
        $prefix = $this->email_subject_prefix();
        $title = !empty($topic['topic_title']) ? (string) $topic['topic_title'] : '#' . (int) $topic['topic_id'];
        $subject_key = 'subject_update';

        foreach ($changes as $change)
        {
            if (!empty($change['type']) && $change['type'] === 'user_reply')
            {
                $subject_key = 'subject_user_reply';
                break;
            }
            if (!empty($change['type']) && $change['type'] === 'team_reply')
            {
                $subject_key = 'subject_team_reply';
                break;
            }
        }

        return $this->notification_text($lang, $subject_key, [$prefix, $title]);
    }

    protected function build_notification_body($lang, array $recipient, $role, array $topic, array $changes, array $lines)
    {
        $name = !empty($recipient['username']) ? (string) $recipient['username'] : 'User';
        $body = [];
        $body[] = $this->notification_text($lang, 'greeting', [$name]);
        $body[] = '';

        $intro_key = $role === 'assignee' ? 'intro_assignee_update' : 'intro_author_update';
        foreach ($changes as $change)
        {
            if (!empty($change['type']) && $change['type'] === 'user_reply')
            {
                $intro_key = 'intro_user_reply';
                break;
            }
            if (!empty($change['type']) && $change['type'] === 'team_reply')
            {
                $intro_key = $role === 'assignee' ? 'intro_assignee_update' : 'intro_team_reply';
                break;
            }
        }

        $body[] = $this->notification_text($lang, $intro_key, [!empty($topic['topic_title']) ? (string) $topic['topic_title'] : '#' . (int) $topic['topic_id']]);
        $body[] = '';
        foreach ($lines as $line)
        {
            $body[] = '- ' . $line;
        }
        $body[] = '';
        $body[] = $this->notification_text($lang, 'ticket_link', [$topic['ticket_url']]);
        $body[] = '';
        $body[] = $this->notification_text($lang, 'footer');

        return implode("\n", $body);
    }

    protected function build_notification_lines($lang, array $changes)
    {
        $lines = [];
        foreach ($changes as $change)
        {
            $type = !empty($change['type']) ? (string) $change['type'] : '';
            switch ($type)
            {
                case 'status':
                    $lines[] = $this->notification_text($lang, 'line_status', [
                        $this->status_label_for_lang(isset($change['old']) ? $change['old'] : '', $lang),
                        $this->status_label_for_lang(isset($change['new']) ? $change['new'] : '', $lang),
                    ]);
                break;

                case 'priority':
                    $lines[] = $this->notification_text($lang, 'line_priority', [
                        $this->priority_label_for_lang(isset($change['old']) ? $change['old'] : '', $lang),
                        $this->priority_label_for_lang(isset($change['new']) ? $change['new'] : '', $lang),
                    ]);
                break;

                case 'department':
                    $lines[] = $this->notification_text($lang, 'line_department', [
                        $this->value_or_unset_for_lang(isset($change['old']) ? $change['old'] : '', $lang),
                        $this->value_or_unset_for_lang(isset($change['new']) ? $change['new'] : '', $lang),
                    ]);
                break;

                case 'assignment':
                    $lines[] = $this->notification_text($lang, 'line_assignment', [
                        $this->value_or_unset_for_lang(isset($change['old']) ? $change['old'] : '', $lang),
                        $this->value_or_unset_for_lang(isset($change['new']) ? $change['new'] : '', $lang),
                    ]);
                break;

                case 'user_reply':
                    $lines[] = $this->notification_text($lang, 'line_user_reply');
                break;

                case 'team_reply':
                    $lines[] = $this->notification_text($lang, 'line_team_reply');
                break;
            }

            if (!empty($change['reason']))
            {
                $lines[] = $this->notification_text($lang, 'line_reason', [(string) $change['reason']]);
            }
        }

        return $lines;
    }

    protected function priority_label_for_lang($priority_key, $lang)
    {
        $definitions = $this->priority_definitions();
        $resolved_key = $this->normalize_priority($priority_key);
        if (!isset($definitions[$resolved_key]))
        {
            return $resolved_key !== '' ? $resolved_key : $this->notification_text($lang, 'unset_label');
        }

        $definition = $definitions[$resolved_key];
        if (strtolower((string) $lang) === 'pt_br')
        {
            return !empty($definition['label_pt_br']) ? (string) $definition['label_pt_br'] : $resolved_key;
        }

        return !empty($definition['label_en']) ? (string) $definition['label_en'] : (!empty($definition['label_pt_br']) ? (string) $definition['label_pt_br'] : $resolved_key);
    }

    protected function value_or_unset_for_lang($value, $lang)
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : $this->notification_text($lang, 'unset_value');
    }

    protected function status_label_for_lang($status_key, $lang)
    {
        $definitions = $this->status_definitions();
        $status_key = (string) $status_key;
        if (!isset($definitions[$status_key]))
        {
            return $this->value_or_unset_for_lang($status_key, $lang);
        }

        $definition = $definitions[$status_key];
        if ($lang === 'pt_br' && !empty($definition['label_pt_br']))
        {
            return (string) $definition['label_pt_br'];
        }

        if (!empty($definition['label_en']))
        {
            return (string) $definition['label_en'];
        }

        if (!empty($definition['label_pt_br']))
        {
            return (string) $definition['label_pt_br'];
        }

        return $status_key;
    }

    protected function notification_lang($lang)
    {
        return strtolower(trim((string) $lang)) === 'pt_br' ? 'pt_br' : 'en';
    }

    protected function notification_text($lang, $key, array $args = [])
    {
        static $strings = [
            'pt_br' => [
                'subject_update' => '%1$s Ticket atualizado: %2$s',
                'subject_user_reply' => '%1$s Novo retorno do usuário: %2$s',
                'subject_team_reply' => '%1$s Resposta da equipe: %2$s',
                'greeting' => 'Olá %s,',
                'intro_author_update' => 'O seu ticket "%s" recebeu atualizações.',
                'intro_assignee_update' => 'O ticket atribuído a você "%s" recebeu atualizações.',
                'intro_user_reply' => 'O usuário respondeu ao ticket "%s".',
                'intro_team_reply' => 'A equipe respondeu ao ticket "%s".',
                'line_status' => 'Status: %1$s → %2$s',
                'line_priority' => 'Prioridade: %1$s → %2$s',
                'line_department' => 'Departamento: %1$s → %2$s',
                'line_assignment' => 'Responsável: %1$s → %2$s',
                'line_user_reply' => 'Nova resposta do usuário registrada no ticket.',
                'line_team_reply' => 'Nova resposta da equipe registrada no ticket.',
                'line_reason' => 'Motivo: %s',
                'ticket_link' => 'Link do ticket: %s',
                'unset_value' => 'não definido',
                'footer' => 'Mensagem automática do Help Desk do fórum.',
            ],
            'en' => [
                'subject_update' => '%1$s Ticket updated: %2$s',
                'subject_user_reply' => '%1$s New user reply: %2$s',
                'subject_team_reply' => '%1$s Team reply: %2$s',
                'greeting' => 'Hello %s,',
                'intro_author_update' => 'Your ticket "%s" has new updates.',
                'intro_assignee_update' => 'The ticket assigned to you "%s" has new updates.',
                'intro_user_reply' => 'The user replied to ticket "%s".',
                'intro_team_reply' => 'The team replied to ticket "%s".',
                'line_status' => 'Status: %1$s → %2$s',
                'line_priority' => 'Priority: %1$s → %2$s',
                'line_department' => 'Department: %1$s → %2$s',
                'line_assignment' => 'Assignee: %1$s → %2$s',
                'line_user_reply' => 'A new user reply was posted on the ticket.',
                'line_team_reply' => 'A new team reply was posted on the ticket.',
                'line_reason' => 'Reason: %s',
                'ticket_link' => 'Ticket link: %s',
                'unset_value' => 'not set',
                'footer' => 'Automatic message from the forum Help Desk.',
            ],
        ];

        $lang = isset($strings[$lang]) ? $lang : 'en';
        $pattern = isset($strings[$lang][$key]) ? $strings[$lang][$key] : $key;
        return !empty($args) ? vsprintf($pattern, $args) : $pattern;
    }

    protected function send_plain_email($to, $subject, $body)
    {
        $to = trim((string) $to);
        if ($to === '')
        {
            return false;
        }

        $site_name = isset($this->config['sitename']) ? trim((string) $this->config['sitename']) : 'phpBB';
        $from_email = isset($this->config['board_contact']) ? trim((string) $this->config['board_contact']) : '';
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        if ($from_email !== '')
        {
            $headers[] = 'From: ' . $site_name . ' <' . $from_email . '>';
            $headers[] = 'Reply-To: ' . $from_email;
        }

        $encoded_subject = '=?UTF-8?B?' . base64_encode((string) $subject) . '?=';
        return @mail($to, $encoded_subject, (string) $body, implode("\r\n", $headers));
    }

    protected function logs_support_reason()
    {
        return isset($this->config['mundophpbb_helpdesk_reason_enable']);
    }

    protected function activity_limit()
    {
        return max(1, (int) (isset($this->config['mundophpbb_helpdesk_activity_limit']) ? $this->config['mundophpbb_helpdesk_activity_limit'] : 10));
    }

    protected function sanitize_change_reason($value)
    {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return substr($value, 0, 1000);
    }

    protected function history_type_meta($action_key)
    {
        switch ((string) $action_key)
        {
            case 'assignment_change':
                return [
                    'label' => $this->user->lang('HELPDESK_ACTIVITY_ASSIGNMENT'),
                    'class' => 'helpdesk-history-type-assignment',
                ];

            case 'department_change':
                return [
                    'label' => $this->user->lang('HELPDESK_ACTIVITY_DEPARTMENT'),
                    'class' => 'helpdesk-history-type-department',
                ];

            case 'priority_change':
                return [
                    'label' => $this->user->lang('HELPDESK_ACTIVITY_PRIORITY'),
                    'class' => 'helpdesk-history-type-priority',
                ];

            case 'status_change':
            default:
                return [
                    'label' => $this->user->lang('HELPDESK_ACTIVITY_STATUS'),
                    'class' => 'helpdesk-history-type-status',
                ];
        }
    }


    protected function department_sla_definitions()
    {
        $raw = isset($this->config['mundophpbb_helpdesk_department_sla_definitions']) ? (string) $this->config['mundophpbb_helpdesk_department_sla_definitions'] : '';
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $definitions = [];

        foreach ($lines as $line)
        {
            $line = trim((string) $line);
            if ($line === '')
            {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            if (empty($parts))
            {
                continue;
            }

            $department_key = $this->normalize_option_key(isset($parts[0]) ? $parts[0] : '');
            if ($department_key === '')
            {
                continue;
            }

            $definitions[$department_key] = [
                'sla_hours' => $this->parse_positive_int(isset($parts[1]) ? $parts[1] : ''),
                'stale_hours' => $this->parse_positive_int(isset($parts[2]) ? $parts[2] : ''),
                'old_hours' => $this->parse_positive_int(isset($parts[3]) ? $parts[3] : ''),
            ];
        }

        return $definitions;
    }



    protected function department_priority_sla_definitions()
    {
        $raw = isset($this->config['mundophpbb_helpdesk_department_priority_sla_definitions']) ? (string) $this->config['mundophpbb_helpdesk_department_priority_sla_definitions'] : '';
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $definitions = [];

        foreach ($lines as $line)
        {
            $line = trim((string) $line);
            if ($line === '')
            {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            if (empty($parts))
            {
                continue;
            }

            $department_key = $this->normalize_option_key(isset($parts[0]) ? $parts[0] : '');
            $priority_key = $this->normalize_priority(isset($parts[1]) ? $parts[1] : '');

            if ($department_key === '' || $priority_key === '')
            {
                continue;
            }

            $definitions[$department_key . '|' . $priority_key] = [
                'sla_hours' => $this->parse_positive_int(isset($parts[2]) ? $parts[2] : ''),
                'stale_hours' => $this->parse_positive_int(isset($parts[3]) ? $parts[3] : ''),
                'old_hours' => $this->parse_positive_int(isset($parts[4]) ? $parts[4] : ''),
            ];
        }

        return $definitions;
    }

    protected function priority_sla_definitions()
    {
        $raw = isset($this->config['mundophpbb_helpdesk_priority_sla_definitions']) ? (string) $this->config['mundophpbb_helpdesk_priority_sla_definitions'] : '';
        $lines = preg_split('/
|
|
/', $raw);
        $definitions = [];

        foreach ($lines as $line)
        {
            $line = trim((string) $line);
            if ($line === '')
            {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            if (empty($parts))
            {
                continue;
            }

            $priority_key = $this->normalize_priority(isset($parts[0]) ? $parts[0] : '');
            if ($priority_key === '')
            {
                continue;
            }

            $definitions[$priority_key] = [
                'sla_hours' => $this->parse_positive_int(isset($parts[1]) ? $parts[1] : ''),
                'stale_hours' => $this->parse_positive_int(isset($parts[2]) ? $parts[2] : ''),
                'old_hours' => $this->parse_positive_int(isset($parts[3]) ? $parts[3] : ''),
            ];
        }

        return $definitions;
    }

    protected function parse_positive_int($value)
    {
        $value = trim((string) $value);
        if ($value === '')
        {
            return 0;
        }

        return max(0, (int) $value);
    }

    
protected function effective_sla_hours($department_key = '', $priority_key = '')
    {
        $hours = $this->sla_hours();
        $department_key = $this->normalize_option_key($department_key);
        $priority_key = $this->normalize_priority($priority_key);

        $combined_definitions = $this->department_priority_sla_definitions();
        $combined_key = $department_key . '|' . $priority_key;
        if ($department_key !== '' && $priority_key !== '' && !empty($combined_definitions[$combined_key]['sla_hours']))
        {
            return max(1, (int) $combined_definitions[$combined_key]['sla_hours']);
        }

        $definitions = $this->department_sla_definitions();
        if ($department_key !== '' && !empty($definitions[$department_key]['sla_hours']))
        {
            return max(1, (int) $definitions[$department_key]['sla_hours']);
        }

        $priority_definitions = $this->priority_sla_definitions();
        if ($priority_key !== '' && !empty($priority_definitions[$priority_key]['sla_hours']))
        {
            return max(1, (int) $priority_definitions[$priority_key]['sla_hours']);
        }

        return max(1, (int) $hours);
    }

    
protected function effective_stale_hours($department_key = '', $priority_key = '')
    {
        $hours = $this->stale_hours();
        $department_key = $this->normalize_option_key($department_key);
        $priority_key = $this->normalize_priority($priority_key);

        $combined_definitions = $this->department_priority_sla_definitions();
        $combined_key = $department_key . '|' . $priority_key;
        if ($department_key !== '' && $priority_key !== '' && !empty($combined_definitions[$combined_key]['stale_hours']))
        {
            return max(1, (int) $combined_definitions[$combined_key]['stale_hours']);
        }

        $definitions = $this->department_sla_definitions();
        if ($department_key !== '' && !empty($definitions[$department_key]['stale_hours']))
        {
            return max(1, (int) $definitions[$department_key]['stale_hours']);
        }

        $priority_definitions = $this->priority_sla_definitions();
        if ($priority_key !== '' && !empty($priority_definitions[$priority_key]['stale_hours']))
        {
            return max(1, (int) $priority_definitions[$priority_key]['stale_hours']);
        }

        return max(1, (int) $hours);
    }

    
protected function effective_old_hours($department_key = '', $priority_key = '')
    {
        $hours = $this->old_hours();
        $department_key = $this->normalize_option_key($department_key);
        $priority_key = $this->normalize_priority($priority_key);

        $combined_definitions = $this->department_priority_sla_definitions();
        $combined_key = $department_key . '|' . $priority_key;
        if ($department_key !== '' && $priority_key !== '' && !empty($combined_definitions[$combined_key]['old_hours']))
        {
            return max(1, (int) $combined_definitions[$combined_key]['old_hours']);
        }

        $definitions = $this->department_sla_definitions();
        if ($department_key !== '' && !empty($definitions[$department_key]['old_hours']))
        {
            return max(1, (int) $definitions[$department_key]['old_hours']);
        }

        $priority_definitions = $this->priority_sla_definitions();
        if ($priority_key !== '' && !empty($priority_definitions[$priority_key]['old_hours']))
        {
            return max(1, (int) $priority_definitions[$priority_key]['old_hours']);
        }

        return max(1, (int) $hours);
    }

    protected function sla_enabled()
    {
        return !empty($this->config['mundophpbb_helpdesk_sla_enable']);
    }

    protected function sla_hours()
    {
        return max(1, (int) (isset($this->config['mundophpbb_helpdesk_sla_hours']) ? $this->config['mundophpbb_helpdesk_sla_hours'] : 24));
    }

    protected function stale_hours()
    {
        return max(1, (int) (isset($this->config['mundophpbb_helpdesk_stale_hours']) ? $this->config['mundophpbb_helpdesk_stale_hours'] : 72));
    }

    protected function old_hours()
    {
        return max(1, (int) (isset($this->config['mundophpbb_helpdesk_old_hours']) ? $this->config['mundophpbb_helpdesk_old_hours'] : 168));
    }

    protected function is_active_status_tone($tone)
    {
        return in_array((string) $tone, ['open', 'progress', 'waiting'], true);
    }

    protected function topic_last_activity_time(array $row, array $meta)
    {
        $last_post_time = !empty($row['topic_last_post_time']) ? (int) $row['topic_last_post_time'] : 0;
        $updated_time = !empty($meta['updated_time']) ? (int) $meta['updated_time'] : 0;
        $created_time = !empty($meta['created_time']) ? (int) $meta['created_time'] : 0;

        return max($last_post_time, $updated_time, $created_time);
    }

    protected function topic_reply_count(array $row)
    {
        if (isset($row['topic_replies_real']))
        {
            return max(0, (int) $row['topic_replies_real']);
        }

        if (isset($row['topic_replies']))
        {
            return max(0, (int) $row['topic_replies']);
        }

        return 0;
    }

    protected function topic_poster_id(array $row)
    {
        if (isset($row['topic_poster']))
        {
            return (int) $row['topic_poster'];
        }

        if (isset($row['topic_poster_id']))
        {
            return (int) $row['topic_poster_id'];
        }

        return 0;
    }

    protected function topic_last_poster_id(array $row)
    {
        if (isset($row['topic_last_poster_id']))
        {
            return (int) $row['topic_last_poster_id'];
        }

        return 0;
    }

    protected function is_waiting_staff_response(array $row, $status_tone)
    {
        if (!$this->is_active_status_tone($status_tone))
        {
            return false;
        }

        if ($this->topic_reply_count($row) <= 0)
        {
            return false;
        }

        $topic_poster_id = $this->topic_poster_id($row);
        $last_poster_id = $this->topic_last_poster_id($row);

        return $topic_poster_id > 0 && $last_poster_id > 0 && $topic_poster_id === $last_poster_id;
    }

    protected function get_reopen_count($topic_id)
    {
        $topic_id = (int) $topic_id;
        if ($topic_id <= 0)
        {
            return 0;
        }

        if (array_key_exists($topic_id, $this->reopen_count_cache))
        {
            return (int) $this->reopen_count_cache[$topic_id];
        }

        $sql = 'SELECT old_value, new_value
            FROM ' . $this->logs_table() . '
            WHERE topic_id = ' . $topic_id . "
                AND action_key = 'status_change'";
        $result = $this->db->sql_query($sql);

        $count = 0;
        while ($row = $this->db->sql_fetchrow($result))
        {
            $old_tone = $this->status_meta(isset($row['old_value']) ? (string) $row['old_value'] : '')['tone'];
            $new_tone = $this->status_meta(isset($row['new_value']) ? (string) $row['new_value'] : '')['tone'];

            if (($old_tone === 'closed' || $old_tone === 'resolved') && $this->is_active_status_tone($new_tone))
            {
                $count++;
            }
        }
        $this->db->sql_freeresult($result);

        $this->reopen_count_cache[$topic_id] = $count;
        return $count;
    }

    protected function operational_criticality($status_tone, $priority_tone, $is_overdue, $is_stale, $needs_first_reply, $is_very_old, $is_unassigned, $needs_staff_reply, $is_reopened)
    {
        if (!$this->is_active_status_tone($status_tone))
        {
            return ['key' => '', 'label' => '', 'class' => ''];
        }

        $score = 0;
        switch ($this->normalize_priority_tone($priority_tone))
        {
            case 'critical':
                $score += 4;
            break;

            case 'high':
                $score += 2;
            break;
        }

        if ($is_overdue)
        {
            $score += 2;
        }

        if ($is_stale)
        {
            $score += 1;
        }

        if ($needs_first_reply)
        {
            $score += 2;
        }

        if ($needs_staff_reply)
        {
            $score += 2;
        }

        if ($is_very_old)
        {
            $score += 3;
        }

        if ($is_unassigned)
        {
            $score += 1;
        }

        if ($is_reopened)
        {
            $score += 1;
        }

        if ($score >= 6)
        {
            return [
                'key' => 'critical',
                'label' => $this->user->lang('HELPDESK_CRITICALITY_CRITICAL'),
                'class' => 'helpdesk-tag-criticality-critical',
            ];
        }

        if ($score >= 3)
        {
            return [
                'key' => 'attention',
                'label' => $this->user->lang('HELPDESK_CRITICALITY_ATTENTION'),
                'class' => 'helpdesk-tag-criticality-attention',
            ];
        }

        return [
            'key' => 'normal',
            'label' => $this->user->lang('HELPDESK_CRITICALITY_NORMAL'),
            'class' => 'helpdesk-tag-criticality-normal',
        ];
    }

    protected function is_ticket_overdue(array $meta)
    {
        $created_time = !empty($meta['created_time']) ? (int) $meta['created_time'] : 0;
        if ($created_time <= 0)
        {
            return false;
        }

        $department_key = $this->extract_department_key($meta);
        $priority_key = isset($meta['priority_key']) ? (string) $meta['priority_key'] : 'normal';

        return (time() - $created_time) > ($this->effective_sla_hours($department_key, $priority_key) * 3600);
    }

    protected function is_ticket_stale(array $row, array $meta)
    {
        $last_activity = $this->topic_last_activity_time($row, $meta);
        if ($last_activity <= 0)
        {
            return false;
        }

        $department_key = $this->extract_department_key($meta);
        $priority_key = isset($meta['priority_key']) ? (string) $meta['priority_key'] : 'normal';

        return (time() - $last_activity) > ($this->effective_stale_hours($department_key, $priority_key) * 3600);
    }

    protected function is_ticket_very_old(array $meta)
    {
        $created_time = !empty($meta['created_time']) ? (int) $meta['created_time'] : 0;
        if ($created_time <= 0)
        {
            return false;
        }

        $department_key = $this->extract_department_key($meta);
        $priority_key = isset($meta['priority_key']) ? (string) $meta['priority_key'] : 'normal';

        return (time() - $created_time) > ($this->effective_old_hours($department_key, $priority_key) * 3600);
    }

    protected function team_panel_enabled()
    {
        return !empty($this->config['mundophpbb_helpdesk_team_panel_enable']);
    }

    protected function alerts_enabled()
    {
        return !empty($this->config['mundophpbb_helpdesk_alerts_enable']);
    }

    protected function alert_hours()
    {
        return max(1, (int) (isset($this->config['mundophpbb_helpdesk_alert_hours']) ? $this->config['mundophpbb_helpdesk_alert_hours'] : 24));
    }

    protected function alert_limit()
    {
        return max(1, (int) (isset($this->config['mundophpbb_helpdesk_alert_limit']) ? $this->config['mundophpbb_helpdesk_alert_limit'] : 15));
    }

    protected function email_notifications_enabled()
    {
        return !empty($this->config['mundophpbb_helpdesk_email_notify_enable'])
            && !empty($this->config['email_enable'])
            && function_exists('mail');
    }

    protected function email_notify_author_enabled()
    {
        return $this->email_notifications_enabled() && !empty($this->config['mundophpbb_helpdesk_email_notify_author']);
    }

    protected function email_notify_assignee_enabled()
    {
        return $this->email_notifications_enabled() && !empty($this->config['mundophpbb_helpdesk_email_notify_assignee']);
    }

    protected function email_notify_user_reply_enabled()
    {
        return $this->email_notifications_enabled() && !empty($this->config['mundophpbb_helpdesk_email_notify_user_reply']);
    }

    protected function email_subject_prefix()
    {
        $prefix = isset($this->config['mundophpbb_helpdesk_email_subject_prefix'])
            ? trim((string) $this->config['mundophpbb_helpdesk_email_subject_prefix'])
            : '[Help Desk]';

        return $prefix !== '' ? $prefix : '[Help Desk]';
    }


    protected function subject_prefix()
    {
        $prefix = isset($this->config['mundophpbb_helpdesk_prefix'])
            ? trim((string) $this->config['mundophpbb_helpdesk_prefix'])
            : '[Ticket]';

        return $prefix !== '' ? $prefix : '[Ticket]';
    }

    protected function extension_enabled()
    {
        return !empty($this->config['mundophpbb_helpdesk_enable']);
    }

    protected function status_enabled()
    {
        return !empty($this->config['mundophpbb_helpdesk_status_enable']);
    }

    protected function priority_enabled()
    {
        return !empty($this->config['mundophpbb_helpdesk_priority_enable']);
    }

    protected function category_enabled()
    {
        return !empty($this->config['mundophpbb_helpdesk_category_enable']);
    }

    protected function department_enabled()
    {
        return !empty($this->config['mundophpbb_helpdesk_department_enable']);
    }

    protected function assignment_enabled()
    {
        return !empty($this->config['mundophpbb_helpdesk_assignment_enable']);
    }

    protected function can_manage_topic($forum_id)
    {
        $forum_id = (int) $forum_id;
        return $this->auth->acl_get('a_') || $this->auth->acl_get('m_', $forum_id) || $this->auth->acl_get('m_helpdesk_manage', $forum_id);
    }

    protected function can_manage_status($forum_id)
    {
        return $this->can_manage_topic($forum_id);
    }

    protected function can_manage_priority($forum_id)
    {
        return $this->can_manage_topic($forum_id);
    }

    protected function can_manage_department($forum_id)
    {
        return $this->can_manage_topic($forum_id);
    }

    protected function can_manage_assignment($forum_id)
    {
        $forum_id = (int) $forum_id;
        return $this->can_manage_topic($forum_id) || $this->auth->acl_get('m_helpdesk_assign', $forum_id);
    }

    protected function can_bulk_manage($forum_id)
    {
        $forum_id = (int) $forum_id;
        return $this->can_manage_topic($forum_id) || $this->auth->acl_get('m_helpdesk_bulk', $forum_id);
    }

    protected function can_view_team_queue($forum_id = 0)
    {
        if ($this->auth->acl_get('a_'))
        {
            return true;
        }

        $forum_id = (int) $forum_id;
        if ($forum_id > 0)
        {
            return $this->auth->acl_get('m_', $forum_id) || $this->auth->acl_get('m_helpdesk_queue', $forum_id) || $this->can_manage_topic($forum_id) || $this->can_bulk_manage($forum_id) || $this->can_manage_assignment($forum_id);
        }

        foreach ($this->enabled_forum_ids() as $enabled_forum_id)
        {
            if ($this->auth->acl_get('m_', $enabled_forum_id) || $this->auth->acl_get('m_helpdesk_queue', $enabled_forum_id) || $this->can_manage_topic($enabled_forum_id) || $this->can_bulk_manage($enabled_forum_id) || $this->can_manage_assignment($enabled_forum_id))
            {
                return true;
            }
        }

        return false;
    }

    protected function enabled_forum_ids()
    {
        $raw = isset($this->config['mundophpbb_helpdesk_forums']) ? (string) $this->config['mundophpbb_helpdesk_forums'] : '';
        if ($raw === '')
        {
            return [];
        }

        $ids = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        return $ids;
    }

    protected function team_queue_url()
    {
        if (!$this->team_panel_enabled())
        {
            return '';
        }

        return $this->helper->route('mundophpbb_helpdesk_queue_controller');
    }


    protected function can_view_my_tickets()
    {
        if (!$this->extension_enabled())
        {
            return false;
        }

        $user_id = !empty($this->user->data['user_id']) ? (int) $this->user->data['user_id'] : ANONYMOUS;
        if ($user_id === ANONYMOUS)
        {
            return false;
        }

        foreach ($this->enabled_forum_ids() as $forum_id)
        {
            if ($this->auth->acl_get('f_list', $forum_id) && $this->auth->acl_get('f_read', $forum_id))
            {
                return true;
            }
        }

        return false;
    }

    protected function my_tickets_url()
    {
        if (!$this->can_view_my_tickets())
        {
            return '';
        }

        return $this->helper->route('mundophpbb_helpdesk_my_tickets_controller');
    }


    protected function default_status()
    {
        $configured = isset($this->config['mundophpbb_helpdesk_default_status']) ? (string) $this->config['mundophpbb_helpdesk_default_status'] : 'open';
        $definitions = $this->status_definitions();

        if (array_key_exists($configured, $definitions))
        {
            return $configured;
        }

        if (array_key_exists('open', $definitions))
        {
            return 'open';
        }

        $keys = array_keys($definitions);
        return !empty($keys) ? (string) $keys[0] : 'open';
    }

    protected function forum_is_enabled($forum_id)
    {
        $forum_id = (int) $forum_id;
        if ($forum_id <= 0)
        {
            return false;
        }

        $raw = isset($this->config['mundophpbb_helpdesk_forums']) ? (string) $this->config['mundophpbb_helpdesk_forums'] : '';
        if ($raw === '')
        {
            return false;
        }

        $ids = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $ids = array_map('intval', $ids);
        return in_array($forum_id, $ids, true);
    }

    protected function allow_posting_panel($mode, array $post_data)
    {
        if ($mode === 'post')
        {
            return true;
        }

        if ($mode === 'edit')
        {
            $post_id = !empty($post_data['post_id']) ? (int) $post_data['post_id'] : 0;
            $first_post_id = !empty($post_data['topic_first_post_id']) ? (int) $post_data['topic_first_post_id'] : 0;
            return $post_id > 0 && $first_post_id > 0 && $post_id === $first_post_id;
        }

        return false;
    }

    protected function get_topic_meta($topic_id)
    {
        $topic_id = (int) $topic_id;
        if ($topic_id <= 0)
        {
            return null;
        }

        if (array_key_exists($topic_id, $this->topic_cache))
        {
            return $this->topic_cache[$topic_id];
        }

        $sql = 'SELECT *
            FROM ' . $this->topics_table() . '
            WHERE topic_id = ' . $topic_id;
        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        $this->topic_cache[$topic_id] = $row ?: null;
        return $this->topic_cache[$topic_id];
    }

    protected function topics_table()
    {
        return $this->table_prefix . 'helpdesk_topics';
    }

    protected function logs_table()
    {
        return $this->table_prefix . 'helpdesk_logs';
    }

    protected function status_definitions()
    {
        if ($this->status_cache !== null)
        {
            return $this->status_cache;
        }

        $raw = isset($this->config['mundophpbb_helpdesk_status_definitions']) ? (string) $this->config['mundophpbb_helpdesk_status_definitions'] : '';
        $definitions = $this->parse_status_definitions($raw);

        if (empty($definitions))
        {
            $definitions = $this->parse_status_definitions($this->default_status_definitions());
        }

        $this->status_cache = $definitions;
        return $this->status_cache;
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
                'key' => $key,
                'label_pt_br' => $label_pt_br,
                'label_en' => $label_en,
                'tone' => $tone !== '' ? $tone : 'open',
            ];
        }

        return $definitions;
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

    protected function status_class_from_tone($tone)
    {
        switch ($tone)
        {
            case 'progress':
                return 'helpdesk-status-progress';

            case 'waiting':
                return 'helpdesk-status-waiting';

            case 'resolved':
                return 'helpdesk-status-resolved';

            case 'closed':
                return 'helpdesk-status-closed';

            case 'open':
            default:
                return 'helpdesk-status-open';
        }
    }

    protected function status_label_from_definition(array $definition)
    {
        $language = isset($this->user->lang_name) ? strtolower((string) $this->user->lang_name) : 'en';
        if ($language === 'pt_br' && !empty($definition['label_pt_br']))
        {
            return (string) $definition['label_pt_br'];
        }

        if (!empty($definition['label_en']))
        {
            return (string) $definition['label_en'];
        }

        if (!empty($definition['label_pt_br']))
        {
            return (string) $definition['label_pt_br'];
        }

        return !empty($definition['key']) ? (string) $definition['key'] : $this->default_status();
    }

    protected function priority_definitions()
    {
        if ($this->priority_cache !== null)
        {
            return $this->priority_cache;
        }

        $raw = isset($this->config['mundophpbb_helpdesk_priority_definitions'])
            ? (string) $this->config['mundophpbb_helpdesk_priority_definitions']
            : '';

        $definitions = $this->parse_priority_definitions($raw);
        if (empty($definitions))
        {
            $definitions = $this->default_priority_definitions();
        }

        $this->priority_cache = $definitions;
        return $definitions;
    }

    protected function parse_priority_definitions($raw)
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

    protected function default_priority_definitions()
    {
        return [
            'low' => [
                'key' => 'low',
                'label_pt_br' => 'Baixa',
                'label_en' => 'Low',
                'tone' => 'low',
            ],
            'normal' => [
                'key' => 'normal',
                'label_pt_br' => 'Normal',
                'label_en' => 'Normal',
                'tone' => 'normal',
            ],
            'high' => [
                'key' => 'high',
                'label_pt_br' => 'Alta',
                'label_en' => 'High',
                'tone' => 'high',
            ],
            'critical' => [
                'key' => 'critical',
                'label_pt_br' => 'Crítica',
                'label_en' => 'Critical',
                'tone' => 'critical',
            ],
        ];
    }

    protected function priority_label_from_definition(array $definition)
    {
        if (strtolower((string) $this->user->lang_name) === 'pt_br' && !empty($definition['label_pt_br']))
        {
            return (string) $definition['label_pt_br'];
        }

        if (!empty($definition['label_en']))
        {
            return (string) $definition['label_en'];
        }

        if (!empty($definition['label_pt_br']))
        {
            return (string) $definition['label_pt_br'];
        }

        return !empty($definition['key']) ? (string) $definition['key'] : 'normal';
    }

    protected function category_options()
    {
        if (!$this->category_enabled())
        {
            return [];
        }

        $definitions = $this->parse_keyed_config('mundophpbb_helpdesk_categories');

        if (empty($definitions))
        {
            $definitions = $this->parse_keyed_config('mundophpbb_helpdesk_categories_pt_br');
        }

        if (empty($definitions))
        {
            $definitions = $this->parse_keyed_config('mundophpbb_helpdesk_categories_en');
        }

        if (empty($definitions))
        {
            $definitions = $this->parse_legacy_categories();
        }

        return $definitions;
    }

    protected function department_options()
    {
        if (!$this->department_enabled())
        {
            return [];
        }

        $definitions = $this->parse_keyed_config('mundophpbb_helpdesk_departments');

        if (empty($definitions))
        {
            $definitions = $this->parse_keyed_config('mundophpbb_helpdesk_departments_pt_br');
        }

        if (empty($definitions))
        {
            $definitions = $this->parse_keyed_config('mundophpbb_helpdesk_departments_en');
        }

        return $definitions;
    }

    protected function parse_keyed_config($config_key)
    {
        $raw = isset($this->config[$config_key]) ? (string) $this->config[$config_key] : '';
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $options = [];

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
                $label = trim($parts[1]);
            }
            else
            {
                $label = trim($parts[0]);
                $key = $this->slugify($label);
            }

            if ($key === '' || $label === '')
            {
                continue;
            }

            $options[$key] = $label;
        }

        return $options;
    }

    protected function parse_legacy_categories()
    {
        $raw = isset($this->config['mundophpbb_helpdesk_categories']) ? (string) $this->config['mundophpbb_helpdesk_categories'] : '';
        $parts = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $options = [];

        foreach ($parts as $part)
        {
            $label = trim($part);
            $key = $this->slugify($label);
            if ($key !== '' && $label !== '')
            {
                $options[$key] = $label;
            }
        }

        return $options;
    }


    protected function build_form_token_fields($form_name)
    {
        $now = time();
        $token_sid = ((int) $this->user->data['user_id'] == ANONYMOUS && !empty($this->config['form_token_sid_guests']))
            ? $this->user->session_id
            : '';
        $token = sha1($now . $this->user->data['user_form_salt'] . $form_name . $token_sid);

        return build_hidden_fields([
            'creation_time' => $now,
            'form_token' => $token,
        ]);
    }

    protected function sanitize_assignee($value)
    {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/', ' ', $value);
        if (function_exists('utf8_normalize_nfc'))
        {
            $value = utf8_normalize_nfc($value);
        }

        return substr($value, 0, 255);
    }

    protected function extract_assigned_to(array $meta)
    {
        return !empty($meta['assigned_to']) ? (string) $meta['assigned_to'] : '';
    }

    protected function slugify($value)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        return trim((string) $value, '_');
    }


    protected function normalize_option_key($value)
    {
        $value = trim((string) $value);
        if ($value === '')
        {
            return '';
        }

        return $this->slugify($value);
    }

    protected function normalize_status($value)
    {
        $value = (string) $value;
        $definitions = $this->status_definitions();
        return array_key_exists($value, $definitions) ? $value : $this->default_status();
    }

    protected function normalize_priority($value)
    {
        $value = (string) $value;
        $definitions = $this->priority_definitions();
        if (array_key_exists($value, $definitions))
        {
            return $value;
        }

        return array_key_exists('normal', $definitions) ? 'normal' : (string) key($definitions);
    }

    protected function normalize_priority_tone($value)
    {
        $value = trim((string) $value);
        return in_array($value, ['low', 'normal', 'high', 'critical'], true) ? $value : 'normal';
    }

    protected function sanitize_option_key($value, array $options, $enabled)
    {
        $value = trim((string) $value);
        if ($value === '' || !$enabled)
        {
            return '';
        }

        return array_key_exists($value, $options) ? $value : '';
    }

    protected function status_meta($key)
    {
        $definitions = $this->status_definitions();
        $resolved_key = array_key_exists((string) $key, $definitions) ? (string) $key : $this->default_status();
        $definition = isset($definitions[$resolved_key]) ? $definitions[$resolved_key] : [
            'key' => $this->default_status(),
            'label_pt_br' => $this->user->lang('HELPDESK_STATUS_OPEN'),
            'label_en' => $this->user->lang('HELPDESK_STATUS_OPEN'),
            'tone' => 'open',
        ];

        return [
            'label' => $this->status_label_from_definition($definition),
            'class' => $this->status_class_from_tone(isset($definition['tone']) ? $definition['tone'] : 'open'),
            'tone' => isset($definition['tone']) ? (string) $definition['tone'] : 'open',
        ];
    }

    protected function priority_meta($key)
    {
        $definitions = $this->priority_definitions();
        $resolved_key = $this->normalize_priority($key);
        $definition = isset($definitions[$resolved_key]) ? $definitions[$resolved_key] : [
            'key' => 'normal',
            'label_pt_br' => $this->user->lang('HELPDESK_PRIORITY_NORMAL'),
            'label_en' => $this->user->lang('HELPDESK_PRIORITY_NORMAL'),
            'tone' => 'normal',
        ];

        return [
            'label' => $this->priority_label_from_definition($definition),
            'class' => 'helpdesk-priority-' . $this->normalize_priority_tone($definition['tone'] ?? 'normal'),
            'tone' => $this->normalize_priority_tone($definition['tone'] ?? 'normal'),
        ];
    }

    protected function resolve_option_label($key, array $options, $fallback_label)
    {
        $key = trim((string) $key);
        if ($key !== '' && array_key_exists($key, $options))
        {
            return $options[$key];
        }

        return trim((string) $fallback_label);
    }

    protected function extract_category_key(array $meta)
    {
        if (!empty($meta['category_key']))
        {
            return (string) $meta['category_key'];
        }

        if (!empty($meta['category_label']))
        {
            return $this->slugify($meta['category_label']);
        }

        return '';
    }

    protected function extract_department_key(array $meta)
    {
        return !empty($meta['department_key']) ? (string) $meta['department_key'] : '';
    }

    protected function extract_legacy_category_label(array $meta)
    {
        return !empty($meta['category_label']) ? (string) $meta['category_label'] : '';
    }
}
