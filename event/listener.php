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

    /** @var array */
    protected $assignable_users_cache = [];

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

    protected function safe_array($value)
    {
        return is_array($value) ? $value : [];
    }

    protected function safe_count($value)
    {
        return is_countable($value) ? count($value) : 0;
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
        $forum_context = $this->current_helpdesk_forum_context();

        $this->template->assign_vars([
            'S_HELPDESK_CAN_VIEW_TEAM_QUEUE' => $can_view_queue,
            'U_HELPDESK_TEAM_QUEUE' => $can_view_queue ? $this->team_queue_url() : '',
            'S_HELPDESK_CAN_VIEW_MY_TICKETS' => $can_view_my_tickets,
            'U_HELPDESK_MY_TICKETS' => $can_view_my_tickets ? $this->my_tickets_url() : '',
            'S_HELPDESK_ALERTS_ENABLED' => $this->alerts_enabled(),
            'S_HELPDESK_FORUM_CONTEXT' => !empty($forum_context),
            'S_HELPDESK_FORUM_CONTEXT_CAN_OPEN' => !empty($forum_context['can_open_ticket']),
            'U_HELPDESK_FORUM_CONTEXT_NEW_TICKET' => !empty($forum_context['new_ticket_url']) ? (string) $forum_context['new_ticket_url'] : '',
            'HELPDESK_FORUM_CONTEXT_TITLE' => $this->user->lang('HELPDESK_FORUM_CONTEXT_TITLE'),
            'HELPDESK_FORUM_CONTEXT_TEXT' => $this->user->lang(!empty($forum_context['can_open_ticket']) ? 'HELPDESK_FORUM_CONTEXT_TEXT_OPEN' : 'HELPDESK_FORUM_CONTEXT_TEXT_VIEW'),
            'HELPDESK_FORUM_CONTEXT_OPEN_LABEL' => $this->user->lang('HELPDESK_FORUM_CONTEXT_OPEN_BUTTON'),
        ]);
    }

    public function posting_modify_template_vars($event)
    {
        $forum_id = isset($event['forum_id']) ? (int) $event['forum_id'] : 0;
        $mode = isset($event['mode']) ? (string) $event['mode'] : '';
        $post_data = isset($event['post_data']) && is_array($event['post_data']) ? $event['post_data'] : [];

        if (!$this->extension_enabled() || !$this->forum_is_enabled($forum_id) || !$this->allow_posting_panel($mode, $post_data) || !$this->can_use_helpdesk_ticket_editor($forum_id))
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

        if (($mode === 'post' || $mode === 'edit') && !$this->can_use_helpdesk_ticket_editor($forum_id))
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

        $status_is_generic = !$this->request->is_set_post('helpdesk_status') || $status === $this->default_status();
        $priority_is_generic = !$this->request->is_set_post('helpdesk_priority') || $priority === 'normal';
        $auto_assigned_to = '';

        if (!$existing && $department_key !== '')
        {
            $department_profile = $this->department_auto_profile($department_key);
            if (!empty($department_profile['status']) && $status_is_generic)
            {
                $status = (string) $department_profile['status'];
            }

            if (!empty($department_profile['priority']) && $priority_is_generic)
            {
                $priority = (string) $department_profile['priority'];
            }

            if ($this->assignment_enabled())
            {
                $auto_assigned_to = $this->department_profile_assignee($department_key, $forum_id);
            }
        }

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

        if (!$existing && $auto_assigned_to !== '')
        {
            $sql_ary['assigned_to'] = $auto_assigned_to;
            $sql_ary['assigned_time'] = time();
        }

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

        if (!$this->can_view_helpdesk_meta($forum_id))
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

        $can_view_internal_notes = $this->can_view_internal_notes($forum_id);
        $can_add_internal_notes = $this->can_add_internal_notes($forum_id);

        if ($can_add_internal_notes)
        {
            $this->handle_internal_note_submit($topic_id, $forum_id);
        }

        $topic_author_id = $this->topic_poster_id($topic_data);
        $status_meta = $this->status_meta($meta['status_key']);
        $feedback_row = $this->get_ticket_feedback($topic_id);
        $can_submit_feedback = $this->can_submit_feedback($forum_id, $topic_author_id, isset($status_meta['tone']) ? (string) $status_meta['tone'] : '');

        if ($can_submit_feedback)
        {
            $this->handle_feedback_submit($topic_id, $forum_id, $topic_author_id, isset($status_meta['tone']) ? (string) $status_meta['tone'] : '', $feedback_row);
            $feedback_row = $this->get_ticket_feedback($topic_id);
        }

        $priority_meta = $this->priority_meta($meta['priority_key']);
        $category_label = $this->resolve_option_label($this->extract_category_key($meta), $this->category_options(), $this->extract_legacy_category_label($meta));
        $current_department_key = $this->extract_department_key($meta);
        $department_label = $this->resolve_option_label($current_department_key, $this->department_options(), '');
        $assigned_to = $this->extract_assigned_to($meta);
        $reply_count = $this->topic_reply_count($topic_data);
        $staff_reply_pending = $this->is_waiting_staff_response($topic_data, $status_meta['tone']);
        $reopen_count = $this->get_reopen_count($topic_id);
        $is_overdue = $this->sla_enabled() && $this->is_ticket_overdue($meta);
        $is_stale = $this->sla_enabled() && $this->is_ticket_stale($topic_data, $meta);
        $is_due_today = $this->sla_enabled() && $this->is_ticket_due_today($meta, $status_meta['tone']);
        $criticality = $this->operational_criticality(
            $status_meta['tone'],
            $priority_meta['tone'],
            $is_overdue,
            $is_stale,
            $reply_count <= 0,
            $this->is_ticket_very_old($meta),
            $assigned_to === '',
            $staff_reply_pending,
            $reopen_count > 0
        );

        $topic_author = $this->resolve_topic_author_name($topic_data);
        $reply_templates = $this->safe_array($this->department_reply_templates_for_topic(
            $current_department_key,
            [
                'USERNAME' => $topic_author !== '' ? $topic_author : $this->user->lang('HELPDESK_REPLY_TEMPLATE_GENERIC_USER'),
                'TOPIC_TITLE' => !empty($topic_data['topic_title']) ? (string) $topic_data['topic_title'] : '',
                'TICKET_ID' => (string) $topic_id,
                'DEPARTMENT' => $department_label,
                'STATUS' => $status_meta['label'],
                'PRIORITY' => $priority_meta['label'],
                'ASSIGNED_TO' => $assigned_to !== '' ? $assigned_to : $this->user->lang('HELPDESK_QUEUE_UNASSIGNED'),
                'BOARD_NAME' => isset($this->config['sitename']) ? (string) $this->config['sitename'] : '',
            ]
        ));
        $can_use_reply_templates = $this->team_panel_enabled() && $this->can_view_team_queue();
        $can_view_feedback_summary = $this->can_view_feedback_summary($forum_id, $topic_author_id);
        $has_feedback = !empty($feedback_row);
        $feedback_rating = $has_feedback && isset($feedback_row['rating']) ? (int) $feedback_row['rating'] : 0;
        $feedback_comment = $has_feedback && !empty($feedback_row['comment_text']) ? (string) $feedback_row['comment_text'] : '';
        $feedback_state = $this->feedback_state_for_topic(isset($status_meta['tone']) ? (string) $status_meta['tone'] : '', $has_feedback, $can_submit_feedback);
        $feedback_quality = $this->feedback_quality_for_rating($feedback_rating);
        $preferred_reply_template_label = $this->department_profile_reply_template_label($current_department_key);
        $topic_automation_snapshot = $this->topic_automation_snapshot($meta, $status_meta, $reply_count, $assigned_to, $staff_reply_pending, $reopen_count, $is_overdue, $is_due_today, $is_stale, $criticality);
        $notification_snapshot = $this->topic_notification_snapshot();

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
            'S_HELPDESK_AUTOMATION_SNAPSHOT' => true,
            'HELPDESK_AUTOMATION_STATE_LABEL' => $topic_automation_snapshot['state_label'],
            'HELPDESK_AUTOMATION_STATE_CLASS' => $topic_automation_snapshot['state_class'],
            'HELPDESK_AUTOMATION_STATUS_NOTE' => $topic_automation_snapshot['status_note'],
            'HELPDESK_AUTOMATION_NEXT_ACTION_LABEL' => $topic_automation_snapshot['next_action'],
            'HELPDESK_AUTOMATION_REPLY_FLOW_LABEL' => $topic_automation_snapshot['reply_flow'],
            'HELPDESK_AUTOMATION_ESCALATION_LABEL' => $topic_automation_snapshot['escalation'],
            'HELPDESK_AUTOMATION_SLA_LABEL' => $topic_automation_snapshot['sla_label'],
            'HELPDESK_AUTOMATION_SLA_DEADLINE_AT' => $topic_automation_snapshot['deadline_at'],
            'S_HELPDESK_NOTIFICATIONS_SNAPSHOT' => true,
            'HELPDESK_NOTIFICATIONS_STATUS_LABEL' => $notification_snapshot['status_label'],
            'HELPDESK_NOTIFICATIONS_STATUS_CLASS' => $notification_snapshot['status_class'],
            'HELPDESK_NOTIFICATIONS_STATUS_NOTE' => $notification_snapshot['status_note'],
            'HELPDESK_NOTIFICATIONS_TEAM_REPLY_LABEL' => $notification_snapshot['team_reply'],
            'HELPDESK_NOTIFICATIONS_USER_REPLY_LABEL' => $notification_snapshot['user_reply'],
            'HELPDESK_NOTIFICATIONS_META_LABEL' => $notification_snapshot['meta_update'],
            'S_HELPDESK_CAN_MANAGE_STATUS' => $can_manage_status,
            'S_HELPDESK_CAN_MANAGE_PRIORITY' => $can_manage_priority,
            'S_HELPDESK_CAN_MANAGE_DEPARTMENT' => $can_manage_department,
            'S_HELPDESK_CAN_MANAGE_ASSIGNMENT' => $can_manage_assignment,
            'S_HELPDESK_CAN_MANAGE_PANEL' => $can_manage_panel,
            'HELPDESK_MANAGE_PRIORITY_VALUE' => isset($meta['priority_key']) ? (string) $meta['priority_key'] : 'normal',
            'HELPDESK_ASSIGNED_TO_VALUE' => $assigned_to,
            'HELPDESK_CHANGE_REASON_VALUE' => '',
            'S_HELPDESK_REASON_REQUIRED_STATUS' => $this->status_reason_required(),
            'S_HELPDESK_REASON_REQUIRED_PRIORITY' => $this->priority_reason_required(),
            'S_HELPDESK_REASON_REQUIRED_ASSIGNMENT' => $this->assignment_reason_required(),
            'S_HELPDESK_REASON_REQUIRED_ANY' => $this->any_change_reason_required(),
            'HELPDESK_CHANGE_REASON_RULES_TEXT' => $this->change_reason_requirements_text(),
            'HELPDESK_MANAGE_FORM_TOKEN' => $this->build_form_token_fields('mundophpbb_helpdesk_topic_manage', '_HELPDESK_MANAGE'),
            'S_HELPDESK_CAN_VIEW_TEAM_QUEUE' => $this->team_panel_enabled() && $this->can_view_team_queue(),
            'U_HELPDESK_TEAM_QUEUE' => $this->team_queue_url(),
            'S_HELPDESK_CAN_VIEW_INTERNAL_NOTES' => $can_view_internal_notes,
            'S_HELPDESK_CAN_ADD_INTERNAL_NOTES' => $can_add_internal_notes,
            'HELPDESK_INTERNAL_NOTES_FORM_TOKEN' => $can_add_internal_notes ? $this->build_form_token_fields('mundophpbb_helpdesk_internal_note', '_HELPDESK_NOTE') : '',
            'HELPDESK_INTERNAL_NOTE_VALUE' => '',
            'S_HELPDESK_CAN_USE_REPLY_TEMPLATES' => $can_use_reply_templates && !empty($reply_templates),
            'HELPDESK_REPLY_TEMPLATE_COUNT' => $this->safe_count($reply_templates),
            'HELPDESK_REPLY_TEMPLATE_PREVIEW' => !empty($reply_templates) ? $reply_templates[0]['body'] : '',
            'S_HELPDESK_CAN_SUBMIT_FEEDBACK' => $can_submit_feedback,
            'S_HELPDESK_CAN_VIEW_FEEDBACK' => $this->feedback_enabled() && ($can_submit_feedback || $can_view_feedback_summary || $has_feedback),
            'S_HELPDESK_CAN_VIEW_FEEDBACK_EMPTY' => $this->feedback_enabled() && $can_view_feedback_summary && !$has_feedback,
            'S_HELPDESK_HAS_FEEDBACK' => $has_feedback,
            'HELPDESK_FEEDBACK_FORM_TOKEN' => $can_submit_feedback ? $this->build_form_token_fields('mundophpbb_helpdesk_feedback', '_HELPDESK_FEEDBACK') : '',
            'HELPDESK_FEEDBACK_CURRENT_COMMENT' => $feedback_comment,
            'HELPDESK_FEEDBACK_CURRENT_RATING' => $feedback_rating,
            'HELPDESK_FEEDBACK_RATING_LABEL' => $feedback_rating > 0 ? $this->feedback_rating_label($feedback_rating) : '',
            'HELPDESK_FEEDBACK_STARS_TEXT' => $feedback_rating > 0 ? $this->feedback_stars_text($feedback_rating) : '',
            'HELPDESK_FEEDBACK_SUBMITTED_AT' => $has_feedback && !empty($feedback_row['submitted_time']) ? $this->user->format_date((int) $feedback_row['submitted_time']) : '',
            'HELPDESK_FEEDBACK_COMMENT' => $feedback_comment,
            'HELPDESK_FEEDBACK_SUBMIT_LABEL' => $has_feedback ? $this->user->lang('HELPDESK_FEEDBACK_UPDATE') : $this->user->lang('HELPDESK_FEEDBACK_SUBMIT'),
            'HELPDESK_FEEDBACK_STATE_LABEL' => $feedback_state['label'],
            'HELPDESK_FEEDBACK_STATE_CLASS' => $feedback_state['class'],
            'S_HELPDESK_FEEDBACK_PENDING' => $feedback_state['key'] === 'pending',
            'S_HELPDESK_FEEDBACK_SUBMITTED' => $feedback_state['key'] === 'submitted',
            'S_HELPDESK_FEEDBACK_HAS_QUALITY' => $feedback_quality['label'] !== '',
            'HELPDESK_FEEDBACK_QUALITY_LABEL' => $feedback_quality['label'],
            'HELPDESK_FEEDBACK_QUALITY_CLASS' => $feedback_quality['class'],
            'S_HELPDESK_REPLY_TEMPLATE_HAS_PREFERRED' => $preferred_reply_template_label !== '',
            'HELPDESK_REPLY_TEMPLATE_PREFERRED_LABEL' => $preferred_reply_template_label,
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

        if ($can_manage_assignment)
        {
            $assignable_users = $this->safe_array($this->get_assignable_users([$forum_id]));
            $selected_assignee_key = $this->normalize_assignee_key($assigned_to);

            $this->template->assign_block_vars('helpdesk_manage_assignee_options', [
                'VALUE' => '',
                'LABEL' => $this->user->lang('HELPDESK_QUEUE_UNASSIGNED'),
                'S_SELECTED' => $assigned_to === '',
            ]);

            foreach ($assignable_users as $assignee_row)
            {
                $this->template->assign_block_vars('helpdesk_manage_assignee_options', [
                    'VALUE' => $assignee_row['username'],
                    'LABEL' => $assignee_row['username'],
                    'S_SELECTED' => $selected_assignee_key === $assignee_row['username_key'],
                ]);
            }

            if ($assigned_to !== '' && !$this->assignee_exists_in_options($assigned_to, $assignable_users))
            {
                $this->template->assign_block_vars('helpdesk_manage_assignee_options', [
                    'VALUE' => $assigned_to,
                    'LABEL' => $assigned_to,
                    'S_SELECTED' => true,
                ]);
            }
        }

        foreach ($reply_templates as $index => $reply_template)
        {
            $this->template->assign_block_vars('helpdesk_reply_templates', [
                'KEY' => 't' . $index,
                'LABEL' => $reply_template['label'],
                'BODY_B64' => base64_encode($reply_template['body']),
            ]);
        }

        for ($rating_option = 5; $rating_option >= 1; $rating_option--)
        {
            $this->template->assign_block_vars('helpdesk_feedback_rating_options', [
                'VALUE' => $rating_option,
                'LABEL' => $this->feedback_rating_label($rating_option),
                'STARS' => $this->feedback_stars_text($rating_option),
                'S_SELECTED' => $feedback_rating === $rating_option,
            ]);
        }

        $internal_notes = $can_view_internal_notes ? $this->get_internal_notes($topic_id, $this->internal_notes_limit()) : [];
        $this->template->assign_var('HELPDESK_INTERNAL_NOTES_COUNT', $this->safe_count($internal_notes));

        foreach ($internal_notes as $note_row)
        {
            $this->template->assign_block_vars('helpdesk_internal_notes', $note_row);
        }

        $activity_history = $this->safe_array($this->get_activity_history($topic_id, $this->activity_limit()));
        $this->assign_activity_history_summary($activity_history);
        $this->assign_activity_history_filters($activity_history);

        foreach ($activity_history as $index => $entry)
        {
            $entry['SEQUENCE'] = $this->safe_count($activity_history) - (int) $index;
            $entry['FILTER_KEY'] = isset($entry['ACTION_KEY']) ? (string) $entry['ACTION_KEY'] : 'all';
            $this->template->assign_block_vars('helpdesk_activity_history', $entry);
        }
    }

    protected function handle_internal_note_submit($topic_id, $forum_id)
    {
        if (!$this->request->is_set_post('helpdesk_save_internal_note'))
        {
            return;
        }

        if (!check_form_key('mundophpbb_helpdesk_internal_note'))
        {
            trigger_error('FORM_INVALID');
        }

        $note_text = $this->sanitize_internal_note($this->request->variable('helpdesk_internal_note', '', true));
        if ($note_text === '')
        {
            trigger_error($this->user->lang('HELPDESK_INTERNAL_NOTE_EMPTY'), E_USER_WARNING);
        }

        $this->insert_internal_note($topic_id, $forum_id, $note_text);
        $this->insert_internal_note_log($topic_id, $forum_id, $note_text);

        redirect($this->topic_url($forum_id, $topic_id, 'helpdesk-internal-notes'));
    }

    protected function sanitize_internal_note($value)
    {
        $value = trim((string) $value);
        $value = preg_replace("/
?|
/u", "
", $value);
        $value = preg_replace("/
{3,}/u", "

", $value);

        if (function_exists('utf8_normalize_nfc'))
        {
            $value = utf8_normalize_nfc($value);
        }

        if (function_exists('utf8_substr'))
        {
            $value = utf8_substr($value, 0, $this->max_internal_note_length());
        }
        else
        {
            $value = substr($value, 0, $this->max_internal_note_length());
        }

        return trim($value);
    }

    protected function max_internal_note_length()
    {
        return 4000;
    }

    protected function internal_notes_limit()
    {
        return max(5, min(20, $this->activity_limit()));
    }

    protected function internal_notes_enabled()
    {
        return !empty($this->config['mundophpbb_helpdesk_internal_notes_enabled']);
    }

    protected function feedback_enabled()
    {
        return !empty($this->config['mundophpbb_helpdesk_feedback_enable']);
    }

    protected function feedback_table()
    {
        return $this->table_prefix . 'helpdesk_feedback';
    }

    protected function feedback_status_allows_submission($status_tone)
    {
        return in_array((string) $status_tone, ['resolved', 'closed'], true);
    }

    protected function can_submit_feedback($forum_id, $topic_author_id, $status_tone)
    {
        if (!$this->feedback_enabled())
        {
            return false;
        }

        if ((int) $topic_author_id <= ANONYMOUS || (int) $this->user->data['user_id'] <= ANONYMOUS)
        {
            return false;
        }

        return (int) $forum_id > 0
            && (int) $topic_author_id === (int) $this->user->data['user_id']
            && $this->can_view_helpdesk_meta($forum_id)
            && $this->feedback_status_allows_submission($status_tone);
    }

    protected function can_view_feedback_summary($forum_id, $topic_author_id)
    {
        if (!$this->feedback_enabled())
        {
            return false;
        }

        return ((int) $topic_author_id > ANONYMOUS && (int) $this->user->data['user_id'] === (int) $topic_author_id)
            || $this->can_view_team_queue($forum_id)
            || $this->can_manage_topic($forum_id);
    }

    protected function handle_feedback_submit($topic_id, $forum_id, $topic_author_id, $status_tone, array $existing_feedback = [])
    {
        if (!$this->request->is_set_post('helpdesk_save_feedback'))
        {
            return;
        }

        if (!$this->can_submit_feedback($forum_id, $topic_author_id, $status_tone))
        {
            return;
        }

        if (!check_form_key('mundophpbb_helpdesk_feedback'))
        {
            trigger_error('FORM_INVALID');
        }

        $rating = (int) $this->request->variable('helpdesk_feedback_rating', 0);
        if ($rating < 1 || $rating > 5)
        {
            trigger_error($this->user->lang('HELPDESK_FEEDBACK_RATING_REQUIRED'), E_USER_WARNING);
        }

        $comment = $this->sanitize_feedback_comment($this->request->variable('helpdesk_feedback_comment', '', true));
        $this->save_ticket_feedback($topic_id, $forum_id, $topic_author_id, $rating, $comment, $existing_feedback);
        $this->insert_feedback_log($topic_id, $forum_id, isset($existing_feedback['rating']) ? (int) $existing_feedback['rating'] : 0, $rating, $comment);

        redirect($this->topic_url($forum_id, $topic_id, 'helpdesk-feedback-box'));
    }

    protected function sanitize_feedback_comment($value)
    {
        $value = trim((string) $value);
        $value = preg_replace("/
?|
/u", "
", $value);
        $value = preg_replace("/
{3,}/u", "

", $value);

        if (function_exists('utf8_normalize_nfc'))
        {
            $value = utf8_normalize_nfc($value);
        }

        if (function_exists('utf8_substr'))
        {
            $value = utf8_substr($value, 0, $this->max_feedback_comment_length());
        }
        else
        {
            $value = substr($value, 0, $this->max_feedback_comment_length());
        }

        return trim($value);
    }

    protected function max_feedback_comment_length()
    {
        return 2000;
    }

    protected function get_ticket_feedback($topic_id)
    {
        $topic_id = (int) $topic_id;
        if (!$this->feedback_enabled() || $topic_id <= 0)
        {
            return [];
        }

        $sql = 'SELECT *
            FROM ' . $this->feedback_table() . '
            WHERE topic_id = ' . (int) $topic_id;
        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row)
        {
            return [];
        }

        return [
            'feedback_id' => (int) ($row['feedback_id'] ?? 0),
            'topic_id' => (int) ($row['topic_id'] ?? 0),
            'forum_id' => (int) ($row['forum_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'rating' => (int) ($row['rating'] ?? 0),
            'comment_text' => !empty($row['comment_text']) ? (string) $row['comment_text'] : '',
            'submitted_time' => (int) ($row['submitted_time'] ?? 0),
            'updated_time' => (int) ($row['updated_time'] ?? 0),
        ];
    }

    protected function save_ticket_feedback($topic_id, $forum_id, $topic_author_id, $rating, $comment, array $existing_feedback = [])
    {
        $time = time();
        $sql_ary = [
            'topic_id' => (int) $topic_id,
            'forum_id' => (int) $forum_id,
            'user_id' => (int) $topic_author_id,
            'rating' => (int) $rating,
            'comment_text' => (string) $comment,
            'updated_time' => $time,
        ];

        if (!empty($existing_feedback['feedback_id']))
        {
            $this->db->sql_query('UPDATE ' . $this->feedback_table() . '
                SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
                WHERE feedback_id = ' . (int) $existing_feedback['feedback_id']);
            return;
        }

        $sql_ary['feedback_id'] = $this->next_feedback_id();
        $sql_ary['submitted_time'] = $time;

        $this->db->sql_query('INSERT INTO ' . $this->feedback_table() . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
    }

    protected function next_feedback_id()
    {
        $sql = 'SELECT MAX(feedback_id) AS max_feedback_id
            FROM ' . $this->feedback_table();
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return (int) (!empty($row['max_feedback_id']) ? $row['max_feedback_id'] + 1 : 1);
    }

    protected function insert_feedback_log($topic_id, $forum_id, $old_rating, $new_rating, $comment = '')
    {
        $sql_ary = [
            'log_id' => $this->next_log_id(),
            'topic_id' => (int) $topic_id,
            'forum_id' => (int) $forum_id,
            'user_id' => (int) $this->user->data['user_id'],
            'action_key' => 'customer_feedback',
            'old_value' => $old_rating > 0 ? (string) (int) $old_rating : '',
            'new_value' => (string) (int) $new_rating,
            'log_time' => time(),
        ];

        if ($this->logs_support_reason())
        {
            $sql_ary['reason_text'] = $this->sanitize_change_reason($comment);
        }

        $this->db->sql_query('INSERT INTO ' . $this->logs_table() . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
    }

    protected function feedback_rating_label($rating)
    {
        $rating = max(1, min(5, (int) $rating));
        return $this->user->lang('HELPDESK_FEEDBACK_RATING_' . $rating);
    }

    protected function feedback_stars_text($rating)
    {
        $rating = max(1, min(5, (int) $rating));
        return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
    }


    protected function feedback_state_for_topic($status_tone, $has_feedback, $can_submit_feedback)
    {
        if ($has_feedback)
        {
            return [
                'key' => 'submitted',
                'label' => $this->user->lang('HELPDESK_FEEDBACK_STATE_SUBMITTED'),
                'class' => 'helpdesk-feedback-state-submitted',
            ];
        }

        if ($can_submit_feedback)
        {
            return [
                'key' => 'pending',
                'label' => $this->user->lang('HELPDESK_FEEDBACK_STATE_PENDING'),
                'class' => 'helpdesk-feedback-state-pending',
            ];
        }

        return [
            'key' => 'unavailable',
            'label' => $this->user->lang('HELPDESK_FEEDBACK_STATE_NOT_AVAILABLE'),
            'class' => 'helpdesk-feedback-state-muted',
        ];
    }

    protected function feedback_quality_for_rating($rating)
    {
        $rating = max(0, min(5, (int) $rating));
        if ($rating <= 0)
        {
            return ['label' => '', 'class' => ''];
        }

        if ($rating >= 5)
        {
            return ['label' => $this->user->lang('HELPDESK_FEEDBACK_QUALITY_EXCELLENT'), 'class' => 'helpdesk-feedback-quality-excellent'];
        }

        if ($rating >= 4)
        {
            return ['label' => $this->user->lang('HELPDESK_FEEDBACK_QUALITY_GOOD'), 'class' => 'helpdesk-feedback-quality-good'];
        }

        if ($rating >= 3)
        {
            return ['label' => $this->user->lang('HELPDESK_FEEDBACK_QUALITY_NEUTRAL'), 'class' => 'helpdesk-feedback-quality-neutral'];
        }

        return ['label' => $this->user->lang('HELPDESK_FEEDBACK_QUALITY_LOW'), 'class' => 'helpdesk-feedback-quality-low'];
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

        if ($change_reason === '' && (
            ($explicit_status_change && $this->status_reason_required())
            || ($new_priority !== $old_priority && $this->priority_reason_required())
            || ($new_assigned_to !== $old_assigned_to && $this->assignment_reason_required())
        ))
        {
            trigger_error($this->user->lang('HELPDESK_CHANGE_REASON_REQUIRED_NOTICE', $this->change_reason_requirements_text()), E_USER_WARNING);
        }

        $applied_department_profile = false;
        if ($new_department !== $old_department)
        {
            $department_profile = $this->department_auto_profile($new_department);
            if ($new_priority === $old_priority && $new_priority === 'normal' && !empty($department_profile['priority']))
            {
                $new_priority = (string) $department_profile['priority'];
                $applied_department_profile = true;
            }

            if ($new_assigned_to === $old_assigned_to && $new_assigned_to === '' && $this->assignment_enabled() && $this->can_manage_assignment($forum_id))
            {
                $profile_assignee = $this->department_profile_assignee($new_department, $forum_id);
                if ($profile_assignee !== '')
                {
                    $new_assigned_to = $profile_assignee;
                    $applied_department_profile = true;
                }
            }
        }

        if ($applied_department_profile && $change_reason === '')
        {
            $change_reason = $this->user->lang('HELPDESK_AUTO_REASON_DEPARTMENT_PROFILE');
            if ($status_reason === '')
            {
                $status_reason = $change_reason;
            }
        }

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

    protected function topic_url($forum_id, $topic_id, $anchor = 'helpdesk-topic-panel')
    {
        global $phpbb_root_path, $phpEx;

        $url = append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'f=' . (int) $forum_id . '&t=' . (int) $topic_id);
        $anchor = trim((string) $anchor);

        return $anchor !== '' ? ($url . '#' . $anchor) : $url;
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

    protected function insert_internal_note_log($topic_id, $forum_id, $note_text = '')
    {
        $preview = $this->sanitize_change_reason($note_text);

        $sql_ary = [
            'log_id' => $this->next_log_id(),
            'topic_id' => (int) $topic_id,
            'forum_id' => (int) $forum_id,
            'user_id' => (int) $this->user->data['user_id'],
            'action_key' => 'internal_note',
            'old_value' => '',
            'new_value' => '',
            'log_time' => time(),
        ];

        if ($this->logs_support_reason())
        {
            $sql_ary['reason_text'] = $preview;
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
            WHERE l.topic_id = ' . (int) $topic_id . "
                AND l.action_key IN ('status_change', 'priority_change', 'assignment_change', 'department_change', 'internal_note', 'customer_feedback')
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
            $old_display = '';
            $new_display = '';
            $change_mode = 'swap';
            $detail_label = $reason !== '' ? $this->user->lang('HELPDESK_CHANGE_REASON') : '';
            $detail_text = $reason;
            $detail_class = 'helpdesk-history-detail-reason';

            if ($action_key === 'assignment_change')
            {
                $old_display = $old_value;
                $new_display = $new_value;

                if ($old_value === '' && $new_value !== '')
                {
                    $change_mode = 'set';
                    $log_text = sprintf($this->user->lang('HELPDESK_LOG_ASSIGNED_TO'), $new_value);
                }
                else if ($old_value !== '' && $new_value === '')
                {
                    $change_mode = 'clear';
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
                $old_display = isset($old_meta['label']) ? (string) $old_meta['label'] : $old_value;
                $new_display = isset($new_meta['label']) ? (string) $new_meta['label'] : $new_value;
                $log_text = sprintf($this->user->lang('HELPDESK_LOG_PRIORITY_CHANGED'), $old_display, $new_display);
            }
            else if ($action_key === 'department_change')
            {
                $old_display = $this->resolve_option_label($old_value, $this->department_options(), $old_value);
                $new_display = $this->resolve_option_label($new_value, $this->department_options(), $new_value);

                if ($old_value === '' && $new_value !== '')
                {
                    $change_mode = 'set';
                    $log_text = sprintf($this->user->lang('HELPDESK_LOG_DEPARTMENT_SET'), $new_display);
                }
                else if ($old_value !== '' && $new_value === '')
                {
                    $change_mode = 'clear';
                    $log_text = sprintf($this->user->lang('HELPDESK_LOG_DEPARTMENT_CLEARED'), $old_display);
                }
                else
                {
                    $log_text = sprintf($this->user->lang('HELPDESK_LOG_DEPARTMENT_CHANGED'), $old_display, $new_display);
                }
            }
            else if ($action_key === 'internal_note')
            {
                $change_mode = 'none';
                $detail_label = $this->user->lang('HELPDESK_ACTIVITY_INTERNAL_NOTE');
                $detail_text = $reason;
                $detail_class = 'helpdesk-history-detail-note';
                $log_text = $this->user->lang('HELPDESK_LOG_INTERNAL_NOTE_ADDED');
            }
            else if ($action_key === 'customer_feedback')
            {
                $old_rating = (int) $old_value;
                $new_rating = (int) $new_value;
                $old_display = $old_rating > 0 ? $this->feedback_rating_label($old_rating) : '';
                $new_display = $new_rating > 0 ? $this->feedback_rating_label($new_rating) : '';
                $detail_label = $reason !== '' ? $this->user->lang('HELPDESK_FEEDBACK_COMMENT_LABEL') : '';
                $detail_text = $reason;
                $detail_class = 'helpdesk-history-detail-feedback';

                if ($old_rating > 0 && $new_rating > 0 && $old_rating !== $new_rating)
                {
                    $log_text = sprintf($this->user->lang('HELPDESK_LOG_FEEDBACK_UPDATED'), $old_display, $new_display);
                }
                else
                {
                    $change_mode = 'set';
                    $log_text = sprintf($this->user->lang('HELPDESK_LOG_FEEDBACK_SUBMITTED'), $new_display);
                }
            }
            else
            {
                $old_meta = $this->status_meta($old_value);
                $new_meta = $this->status_meta($new_value);
                $old_display = isset($old_meta['label']) ? (string) $old_meta['label'] : $old_value;
                $new_display = isset($new_meta['label']) ? (string) $new_meta['label'] : $new_value;
                $log_text = sprintf($this->user->lang('HELPDESK_LOG_STATUS_CHANGED'), $old_display, $new_display);
            }

            $search_text = trim(implode(' ', array_filter([
                $type_meta['label'],
                $username,
                $log_text,
                $detail_label,
                $detail_text,
                $reason,
                $old_display,
                $new_display,
            ])));

            $history[] = [
                'ACTION_KEY' => $action_key,
                'ACTION_LABEL' => $type_meta['label'],
                'ACTION_CLASS' => $type_meta['class'],
                'LOG_TEXT' => $log_text,
                'LOG_REASON' => $reason,
                'LOG_DETAIL' => $detail_text,
                'LOG_DETAIL_LABEL' => $detail_label,
                'DETAIL_CLASS' => $detail_class,
                'LOG_USERNAME' => $username,
                'LOG_TIME' => $this->user->format_date((int) $row['log_time']),
                'LOG_TIMESTAMP' => (int) $row['log_time'],
                'LOG_OLD_LABEL' => $old_display,
                'LOG_NEW_LABEL' => $new_display,
                'SEARCH_TEXT' => $search_text,
                'S_HAS_CHANGE_PAIR' => ($old_display !== '' && $new_display !== '' && $old_display !== $new_display),
                'S_HAS_CHANGE_TARGET' => ($new_display !== ''),
                'S_HAS_CHANGE_SOURCE' => ($old_display !== ''),
                'S_CHANGE_SET' => ($change_mode === 'set'),
                'S_CHANGE_CLEAR' => ($change_mode === 'clear'),
                'S_HAS_DETAIL' => ($detail_text !== ''),
                'S_INTERNAL_NOTE' => ($action_key === 'internal_note'),
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

        if (!$this->can_view_helpdesk_meta($forum_id))
        {
            $event['topic_row'] = $topic_row;
            return;
        }

        $status_meta = $this->status_meta($meta['status_key']);
        $priority_meta = $this->priority_meta($meta['priority_key']);
        $category_label = $this->resolve_option_label($this->extract_category_key($meta), $this->category_options(), $this->extract_legacy_category_label($meta));
        $current_department_key = $this->extract_department_key($meta);
        $department_label = $this->resolve_option_label($current_department_key, $this->department_options(), '');
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

        $bulk_change_reason = $this->sanitize_change_reason($this->request->variable('helpdesk_bulk_reason', '', true));

        if ($bulk_change_reason === '' && (
            ($new_status !== '' && $this->status_reason_required())
            || ($priority_has_change && $this->priority_reason_required())
            || ($assignment_has_change && $this->assignment_reason_required())
        ))
        {
            trigger_error($this->user->lang('HELPDESK_CHANGE_REASON_REQUIRED_NOTICE', $this->change_reason_requirements_text()), E_USER_WARNING);
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
            $status_reason = $bulk_change_reason;

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
                        $status_reason = $bulk_change_reason !== '' ? $bulk_change_reason : $this->user->lang('HELPDESK_AUTO_REASON_DEPARTMENT');
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
                        $status_reason = $bulk_change_reason !== '' ? $bulk_change_reason : $this->user->lang($new_assigned_to !== '' ? 'HELPDESK_AUTO_REASON_ASSIGN' : 'HELPDESK_AUTO_REASON_UNASSIGN');
                    }
                }

                if ($applied_status === '' && $priority_has_change && $old_priority !== $new_priority)
                {
                    $applied_status = $this->priority_change_status($new_priority, $department_has_change ? $new_department : $old_department);
                    if ($applied_status !== '')
                    {
                        $status_reason = $bulk_change_reason !== '' ? $bulk_change_reason : $this->user->lang($this->priority_change_reason_key($new_priority));
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
                WHERE topic_id = ' . (int) $topic_id);

            if ($applied_status !== '' && $old_status !== $applied_status)
            {
                $this->insert_status_log($topic_id, $forum_id, $old_status, $applied_status, $status_reason);
                $this->apply_closed_topic_lock($topic_id, $applied_status, $old_status);
            }

            if ($priority_has_change && $old_priority !== $new_priority)
            {
                $this->insert_priority_log($topic_id, $forum_id, $old_priority, $new_priority, $bulk_change_reason);
            }

            if ($department_has_change && $old_department !== $new_department)
            {
                $this->insert_department_log($topic_id, $forum_id, $old_department, $new_department, $bulk_change_reason);
            }

            if ($assignment_has_change && $old_assigned_to !== $new_assigned_to)
            {
                $this->insert_assignment_log($topic_id, $forum_id, $old_assigned_to, $new_assigned_to, $bulk_change_reason);
            }

            $new_meta = $meta;
            $new_meta['status_key'] = $applied_status !== '' ? $applied_status : $old_status;
            $new_meta['priority_key'] = $priority_has_change ? $new_priority : $old_priority;
            $new_meta['department_key'] = $department_has_change ? $new_department : $old_department;
            $new_meta['assigned_to'] = $assignment_has_change ? $new_assigned_to : $old_assigned_to;
            $new_meta['updated_time'] = $update_sql['updated_time'];
            $changes = $this->build_notification_changes($old_status, $new_meta['status_key'], $old_priority, $new_meta['priority_key'], $old_department, $new_meta['department_key'], $old_assigned_to, $new_meta['assigned_to'], $status_reason, $bulk_change_reason);
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
        $reply_templates = [];
        $can_use_reply_templates = false;

        $this->template->assign_vars([
            'S_HELPDESK_CAN_BULK_MANAGE' => $can_bulk_manage,
            'S_HELPDESK_BULK_PRIORITY_ENABLED' => $can_bulk_manage && $this->priority_enabled(),
            'S_HELPDESK_BULK_DEPARTMENT_ENABLED' => $can_bulk_manage && $this->department_enabled(),
            'S_HELPDESK_BULK_ASSIGNMENT_ENABLED' => $can_bulk_manage && $this->assignment_enabled(),
            'S_HELPDESK_REASON_REQUIRED_STATUS' => $this->status_reason_required(),
            'S_HELPDESK_REASON_REQUIRED_PRIORITY' => $this->priority_reason_required(),
            'S_HELPDESK_REASON_REQUIRED_ASSIGNMENT' => $this->assignment_reason_required(),
            'S_HELPDESK_REASON_REQUIRED_ANY' => $this->any_change_reason_required(),
            'HELPDESK_CHANGE_REASON_RULES_TEXT' => $this->change_reason_requirements_text(),
            'S_HELPDESK_SLA_ENABLED' => $this->sla_enabled(),
            'HELPDESK_SLA_HOURS' => $this->sla_hours(),
            'HELPDESK_STALE_HOURS' => $this->stale_hours(),
            'HELPDESK_OLD_HOURS' => $this->old_hours(),
            'HELPDESK_BULK_FORM_TOKEN' => $can_bulk_manage ? $this->build_form_token_fields('mundophpbb_helpdesk_bulk_manage', '_HELPDESK_BULK') : '',
            'S_HELPDESK_CAN_VIEW_TEAM_QUEUE' => $this->team_panel_enabled() && $this->can_view_team_queue(),
            'U_HELPDESK_TEAM_QUEUE' => $this->team_queue_url(),
            'S_HELPDESK_CAN_USE_REPLY_TEMPLATES' => $can_use_reply_templates && !empty($reply_templates),
            'HELPDESK_REPLY_TEMPLATE_COUNT' => $this->safe_count($reply_templates),
            'HELPDESK_REPLY_TEMPLATE_PREVIEW' => !empty($reply_templates) ? $reply_templates[0]['body'] : '',
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

            $assignable_users = $this->safe_array($this->get_assignable_users([$forum_id]));
            foreach ($assignable_users as $assignee_row)
            {
                $this->template->assign_block_vars('helpdesk_viewforum_assignee_options', [
                    'VALUE' => $assignee_row['username'],
                    'LABEL' => $assignee_row['username'],
                ]);
                $this->template->assign_block_vars('helpdesk_viewforum_bulk_assignee_options', [
                    'VALUE' => $assignee_row['username'],
                    'LABEL' => $assignee_row['username'],
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
            WHERE topic_id = ' . (int) $topic_id);

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

    protected function department_auto_profile_definitions()
    {
        static $definitions = null;

        if ($definitions !== null)
        {
            return $definitions;
        }

        $definitions = [];
        $raw = isset($this->config['mundophpbb_helpdesk_department_auto_profile_definitions']) ? (string) $this->config['mundophpbb_helpdesk_department_auto_profile_definitions'] : '';
        $lines = preg_split('/\r\n|\r|\n/', $raw);

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

            $department_key = $this->normalize_option_key(isset($parts[0]) ? $parts[0] : '');
            if ($department_key === '')
            {
                continue;
            }

            $definitions[$department_key] = [
                'status' => $this->configured_optional_status(isset($parts[1]) ? $parts[1] : ''),
                'priority' => $this->normalize_priority(isset($parts[2]) ? $parts[2] : ''),
                'assignee' => $this->sanitize_assignee(isset($parts[3]) ? str_replace('\\|', '|', $parts[3]) : ''),
                'reply_template' => trim((string) (isset($parts[4]) ? str_replace('\\|', '|', $parts[4]) : '')),
            ];
        }

        return $definitions;
    }

    protected function department_auto_profile($department_key)
    {
        $department_key = $this->normalize_option_key($department_key);
        if ($department_key === '')
        {
            return [];
        }

        $definitions = $this->department_auto_profile_definitions();
        return isset($definitions[$department_key]) ? $definitions[$department_key] : [];
    }

    protected function department_profile_assignee($department_key, $forum_id)
    {
        $profile = $this->department_auto_profile($department_key);
        if (empty($profile['assignee']) || (int) $forum_id <= 0)
        {
            return '';
        }

        $assignable_users = $this->safe_array($this->get_assignable_users([(int) $forum_id]));
        return $this->assignee_exists_in_options((string) $profile['assignee'], $assignable_users) ? (string) $profile['assignee'] : '';
    }

    protected function department_profile_reply_template_label($department_key)
    {
        $profile = $this->department_auto_profile($department_key);
        return !empty($profile['reply_template']) ? trim((string) $profile['reply_template']) : '';
    }

    protected function prioritize_reply_templates(array $templates, $preferred_label)
    {
        $preferred_key = $this->normalize_option_key($preferred_label);
        if ($preferred_key === '' || empty($templates))
        {
            return $templates;
        }

        $preferred = [];
        $others = [];
        foreach ($templates as $template_row)
        {
            $label_key = $this->normalize_option_key(isset($template_row['label']) ? $template_row['label'] : '');
            if ($label_key !== '' && $label_key === $preferred_key)
            {
                $preferred[] = $template_row;
                continue;
            }

            $others[] = $template_row;
        }

        return !empty($preferred) ? array_merge($preferred, $others) : $templates;
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
            WHERE user_id = ' . (int) $user_id;
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

    protected function assign_activity_history_summary(array $history)
    {
        $total = $this->safe_count($history);
        $latest_entry = $total ? $history[0] : [];
        $latest_status = [];
        $latest_note = [];

        foreach ($history as $entry)
        {
            $action_key = isset($entry['ACTION_KEY']) ? (string) $entry['ACTION_KEY'] : '';

            if (!$latest_status && $action_key === 'status_change')
            {
                $latest_status = $entry;
            }

            if (!$latest_note && $action_key === 'internal_note')
            {
                $latest_note = $entry;
            }

            if ($latest_status && $latest_note)
            {
                break;
            }
        }

        $this->template->assign_vars([
            'S_HELPDESK_ACTIVITY_SUMMARY' => $total > 0,
            'HELPDESK_HISTORY_TOTAL' => $total,
            'HELPDESK_HISTORY_LATEST_ACTION' => !empty($latest_entry['ACTION_LABEL']) ? (string) $latest_entry['ACTION_LABEL'] : $this->user->lang('HELPDESK_TIMELINE_NONE'),
            'HELPDESK_HISTORY_LATEST_ACTION_META' => !empty($latest_entry['LOG_TIME']) ? (string) $latest_entry['LOG_TIME'] : '',
            'HELPDESK_HISTORY_LATEST_STATUS' => !empty($latest_status['LOG_NEW_LABEL']) ? (string) $latest_status['LOG_NEW_LABEL'] : $this->user->lang('HELPDESK_TIMELINE_NONE'),
            'HELPDESK_HISTORY_LATEST_STATUS_META' => !empty($latest_status['LOG_TIME']) ? (string) $latest_status['LOG_TIME'] : '',
            'HELPDESK_HISTORY_LATEST_NOTE' => !empty($latest_note['LOG_USERNAME']) ? (string) $latest_note['LOG_USERNAME'] : $this->user->lang('HELPDESK_TIMELINE_NONE'),
            'HELPDESK_HISTORY_LATEST_NOTE_META' => !empty($latest_note['LOG_TIME']) ? (string) $latest_note['LOG_TIME'] : '',
        ]);
    }

    protected function assign_activity_history_filters(array $history)
    {
        $definitions = $this->activity_history_filter_definitions();
        $counts = ['all' => $this->safe_count($history)];

        foreach ($history as $entry)
        {
            $action_key = isset($entry['ACTION_KEY']) ? (string) $entry['ACTION_KEY'] : '';
            if ($action_key === '')
            {
                continue;
            }

            if (!isset($counts[$action_key]))
            {
                $counts[$action_key] = 0;
            }

            $counts[$action_key]++;
        }

        $selected = 'all';

        foreach ($definitions as $definition)
        {
            $key = isset($definition['key']) ? (string) $definition['key'] : 'all';
            $count = isset($counts[$key]) ? (int) $counts[$key] : 0;

            $this->template->assign_block_vars('helpdesk_activity_filters', [
                'KEY' => $key,
                'LABEL' => isset($definition['label']) ? (string) $definition['label'] : $key,
                'COUNT' => $count,
                'S_SELECTED' => ($key === $selected),
            ]);
        }

        $this->template->assign_vars([
            'S_HELPDESK_ACTIVITY_FILTERS' => !empty($definitions) && $this->safe_count($history) > 0,
            'HELPDESK_ACTIVITY_FILTER_SELECTED' => $selected,
            'HELPDESK_ACTIVITY_FILTER_EMPTY_TEXT' => $this->user->lang('HELPDESK_TIMELINE_FILTER_EMPTY'),
        ]);
    }

    protected function activity_history_filter_definitions()
    {
        return [
            [
                'key' => 'all',
                'label' => $this->user->lang('HELPDESK_TIMELINE_FILTER_ALL'),
            ],
            [
                'key' => 'status_change',
                'label' => $this->user->lang('HELPDESK_ACTIVITY_STATUS'),
            ],
            [
                'key' => 'assignment_change',
                'label' => $this->user->lang('HELPDESK_ACTIVITY_ASSIGNMENT'),
            ],
            [
                'key' => 'department_change',
                'label' => $this->user->lang('HELPDESK_ACTIVITY_DEPARTMENT'),
            ],
            [
                'key' => 'priority_change',
                'label' => $this->user->lang('HELPDESK_ACTIVITY_PRIORITY'),
            ],
            [
                'key' => 'internal_note',
                'label' => $this->user->lang('HELPDESK_ACTIVITY_INTERNAL_NOTE'),
            ],
            [
                'key' => 'customer_feedback',
                'label' => $this->user->lang('HELPDESK_ACTIVITY_FEEDBACK'),
            ],
        ];
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

            case 'internal_note':
                return [
                    'label' => $this->user->lang('HELPDESK_ACTIVITY_INTERNAL_NOTE'),
                    'class' => 'helpdesk-history-type-note',
                ];

            case 'customer_feedback':
                return [
                    'label' => $this->user->lang('HELPDESK_ACTIVITY_FEEDBACK'),
                    'class' => 'helpdesk-history-type-feedback',
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
            WHERE topic_id = ' . (int) $topic_id . "
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

    protected function status_reason_required()
    {
        return !empty($this->config['mundophpbb_helpdesk_require_reason_status']);
    }

    protected function priority_reason_required()
    {
        return !empty($this->config['mundophpbb_helpdesk_require_reason_priority']);
    }

    protected function assignment_reason_required()
    {
        return !empty($this->config['mundophpbb_helpdesk_require_reason_assignment']);
    }

    protected function any_change_reason_required()
    {
        return $this->status_reason_required() || $this->priority_reason_required() || $this->assignment_reason_required();
    }

    protected function change_reason_requirements_text()
    {
        $labels = [];
        if ($this->status_reason_required())
        {
            $labels[] = $this->user->lang('HELPDESK_STATUS');
        }
        if ($this->priority_reason_required())
        {
            $labels[] = $this->user->lang('HELPDESK_PRIORITY');
        }
        if ($this->assignment_reason_required())
        {
            $labels[] = $this->user->lang('HELPDESK_ASSIGNED_TO');
        }

        return !empty($labels)
            ? $this->user->lang('HELPDESK_CHANGE_REASON_REQUIRED_FOR', implode(', ', $labels))
            : '';
    }


    protected function count_non_empty_lines($raw)
    {
        $lines = preg_split("/\r\n|\r|\n/", (string) $raw);
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


    protected function topic_automation_snapshot(array $meta, array $status_meta, $reply_count, $assigned_to, $staff_reply_pending, $reopen_count, $is_overdue, $is_due_today, $is_stale, array $criticality)
    {
        $reply_count = (int) $reply_count;
        $is_active = $this->is_active_status_tone(isset($status_meta['tone']) ? (string) $status_meta['tone'] : '');
        $deadline = $this->sla_enabled() ? $this->ticket_sla_deadline_for_meta($meta) : 0;
        $state_label = $this->user->lang('HELPDESK_AUTOMATION_STATE_STABLE');
        $state_class = 'helpdesk-feedback-state helpdesk-feedback-state-neutral';
        $status_note = $this->user->lang('HELPDESK_AUTOMATION_STATUS_NOTE_STABLE');

        if (!$is_active)
        {
            $state_label = $this->user->lang('HELPDESK_AUTOMATION_STATE_COMPLETED');
            $state_class = 'helpdesk-feedback-state helpdesk-feedback-state-submitted';
            $status_note = $this->user->lang('HELPDESK_AUTOMATION_STATUS_NOTE_COMPLETED');
        }
        else if (!empty($is_overdue) || (!empty($criticality['key']) && (string) $criticality['key'] === 'critical'))
        {
            $state_label = $this->user->lang('HELPDESK_AUTOMATION_STATE_ESCALATED');
            $state_class = 'helpdesk-feedback-state helpdesk-feedback-state-pending';
            $status_note = $this->user->lang('HELPDESK_AUTOMATION_STATUS_NOTE_ESCALATED');
        }
        else if (!empty($is_due_today) || !empty($is_stale) || ($assigned_to === '') || $reply_count <= 0 || !empty($staff_reply_pending) || ($reopen_count > 0))
        {
            $state_label = $this->user->lang('HELPDESK_AUTOMATION_STATE_ATTENTION');
            $state_class = 'helpdesk-feedback-state helpdesk-feedback-state-neutral';
            $status_note = $this->user->lang('HELPDESK_AUTOMATION_STATUS_NOTE_ATTENTION');
        }

        $next_action = $this->user->lang('HELPDESK_AUTOMATION_NEXT_ACTION_MONITOR');
        if (!$is_active)
        {
            $next_action = $this->user->lang('HELPDESK_AUTOMATION_NEXT_ACTION_COMPLETED');
        }
        else if ($reply_count <= 0)
        {
            $next_action = $this->user->lang('HELPDESK_AUTOMATION_NEXT_ACTION_FIRST_REPLY');
        }
        else if (!empty($staff_reply_pending))
        {
            $next_action = $this->user->lang('HELPDESK_AUTOMATION_NEXT_ACTION_TEAM_REPLY');
        }
        else if ($assigned_to === '')
        {
            $next_action = $this->user->lang('HELPDESK_AUTOMATION_NEXT_ACTION_ASSIGN');
        }
        else if (!empty($is_stale))
        {
            $next_action = $this->user->lang('HELPDESK_AUTOMATION_NEXT_ACTION_RESUME');
        }
        else if (!empty($is_overdue))
        {
            $next_action = $this->user->lang('HELPDESK_AUTOMATION_NEXT_ACTION_PRIORITY');
        }

        $reply_flow = $this->user->lang('HELPDESK_AUTOMATION_REPLY_FLOW_INLINE', $this->automation_status_label(isset($this->config['mundophpbb_helpdesk_team_reply_status']) ? (string) $this->config['mundophpbb_helpdesk_team_reply_status'] : ''), $this->automation_status_label(isset($this->config['mundophpbb_helpdesk_user_reply_status']) ? (string) $this->config['mundophpbb_helpdesk_user_reply_status'] : ''));

        $escalation = $this->user->lang('HELPDESK_AUTOMATION_ESCALATION_GLOBAL');
        if ($this->count_non_empty_lines(isset($this->config['mundophpbb_helpdesk_department_priority_queue_definitions']) ? (string) $this->config['mundophpbb_helpdesk_department_priority_queue_definitions'] : '') + $this->count_non_empty_lines(isset($this->config['mundophpbb_helpdesk_assignee_queue_definitions']) ? (string) $this->config['mundophpbb_helpdesk_assignee_queue_definitions'] : '') > 0)
        {
            $escalation = $this->user->lang('HELPDESK_AUTOMATION_ESCALATION_ROUTED');
        }
        else if ($this->count_non_empty_lines(isset($this->config['mundophpbb_helpdesk_department_sla_definitions']) ? (string) $this->config['mundophpbb_helpdesk_department_sla_definitions'] : '') + $this->count_non_empty_lines(isset($this->config['mundophpbb_helpdesk_priority_sla_definitions']) ? (string) $this->config['mundophpbb_helpdesk_priority_sla_definitions'] : '') + $this->count_non_empty_lines(isset($this->config['mundophpbb_helpdesk_department_priority_sla_definitions']) ? (string) $this->config['mundophpbb_helpdesk_department_priority_sla_definitions'] : '') > 0)
        {
            $escalation = $this->user->lang('HELPDESK_AUTOMATION_ESCALATION_SLA');
        }

        $sla_label = $this->user->lang('HELPDESK_AUTOMATION_SLA_LABEL_DISABLED');
        if ($is_active && $this->sla_enabled())
        {
            if (!empty($is_overdue))
            {
                $sla_label = $this->user->lang('HELPDESK_QUEUE_OVERDUE');
            }
            else if (!empty($is_due_today))
            {
                $sla_label = $this->user->lang('HELPDESK_QUEUE_DUE_TODAY');
            }
            else
            {
                $sla_label = $this->user->lang('HELPDESK_QUEUE_WITHIN_SLA');
            }
        }

        return [
            'state_label' => $state_label,
            'state_class' => $state_class,
            'status_note' => $status_note,
            'next_action' => $next_action,
            'reply_flow' => $reply_flow,
            'escalation' => $escalation,
            'sla_label' => $sla_label,
            'deadline_at' => $deadline > 0 ? $this->user->format_date($deadline) : $this->user->lang('HELPDESK_AUTOMATION_SLA_NO_DEADLINE'),
        ];
    }

    protected function topic_notification_snapshot()
    {
        $enabled = $this->email_notifications_enabled();
        return [
            'status_label' => $enabled ? $this->user->lang('HELPDESK_NOTIFICATIONS_STATUS_ENABLED') : $this->user->lang('HELPDESK_NOTIFICATIONS_STATUS_DISABLED'),
            'status_class' => $enabled ? 'helpdesk-feedback-state helpdesk-feedback-state-submitted' : 'helpdesk-feedback-state helpdesk-feedback-state-neutral',
            'status_note' => $enabled ? $this->user->lang('HELPDESK_NOTIFICATIONS_STATUS_NOTE_ENABLED') : $this->user->lang('HELPDESK_NOTIFICATIONS_STATUS_NOTE_DISABLED'),
            'team_reply' => $this->user->lang('HELPDESK_NOTIFICATIONS_TEAM_REPLY', $this->notification_route_label($enabled && $this->email_notify_author_enabled(), $enabled && $this->email_notify_assignee_enabled())),
            'user_reply' => $this->user->lang('HELPDESK_NOTIFICATIONS_USER_REPLY', $this->notification_route_label(false, $enabled && $this->email_notify_user_reply_enabled())),
            'meta_update' => $this->user->lang('HELPDESK_NOTIFICATIONS_META_UPDATE', $this->notification_route_label($enabled && $this->email_notify_author_enabled(), $enabled && $this->email_notify_assignee_enabled())),
        ];
    }

    protected function automation_status_label($status_key)
    {
        $status_key = (string) $status_key;
        if ($status_key === '')
        {
            return $this->user->lang('HELPDESK_AUTOMATION_NO_CHANGE');
        }

        $definitions = $this->status_definitions();
        if (!isset($definitions[$status_key]))
        {
            return $status_key;
        }

        return $this->status_label_from_definition($definitions[$status_key]);
    }

    protected function notification_route_label($author_enabled, $assignee_enabled)
    {
        if ($author_enabled && $assignee_enabled)
        {
            return $this->user->lang('HELPDESK_NOTIFICATIONS_ROUTE_AUTHOR_ASSIGNEE');
        }

        if ($author_enabled)
        {
            return $this->user->lang('HELPDESK_NOTIFICATIONS_ROUTE_AUTHOR');
        }

        if ($assignee_enabled)
        {
            return $this->user->lang('HELPDESK_NOTIFICATIONS_ROUTE_ASSIGNEE');
        }

        return $this->user->lang('HELPDESK_NOTIFICATIONS_ROUTE_NONE');
    }

    protected function ticket_sla_deadline_for_meta(array $meta)
    {
        $created_time = !empty($meta['created_time']) ? (int) $meta['created_time'] : 0;
        if ($created_time <= 0)
        {
            return 0;
        }

        $department_key = $this->extract_department_key($meta);
        $priority_key = isset($meta['priority_key']) ? (string) $meta['priority_key'] : 'normal';

        return $created_time + ($this->effective_sla_hours($department_key, $priority_key) * 3600);
    }

    protected function is_ticket_due_today(array $meta, $status_tone = '')
    {
        if (!$this->is_active_status_tone($status_tone))
        {
            return false;
        }

        $deadline = $this->ticket_sla_deadline_for_meta($meta);
        if ($deadline <= 0)
        {
            return false;
        }

        $now = time();
        return $deadline > $now && $deadline <= ($now + 86400);
    }

    protected function can_manage_topic($forum_id)
    {
        $forum_id = (int) $forum_id;
        return $this->auth->acl_get('a_helpdesk_manage') || $this->auth->acl_get('m_helpdesk_manage', $forum_id);
    }

    protected function can_view_helpdesk_meta($forum_id)
    {
        $forum_id = (int) $forum_id;
        if ($forum_id <= 0 || !$this->forum_is_enabled($forum_id))
        {
            return false;
        }

        if ($this->can_manage_topic($forum_id) || $this->can_manage_assignment($forum_id) || $this->can_bulk_manage($forum_id) || $this->auth->acl_get('m_helpdesk_queue', $forum_id))
        {
            return true;
        }

        if (!$this->has_forum_helpdesk_visibility_base($forum_id))
        {
            return false;
        }

        return $this->auth->acl_get('f_helpdesk_view', $forum_id)
            || $this->auth->acl_get('f_helpdesk_ticket', $forum_id)
            || $this->auth->acl_get('f_post', $forum_id);
    }

    protected function can_use_helpdesk_ticket_editor($forum_id)
    {
        $forum_id = (int) $forum_id;
        if ($forum_id <= 0 || !$this->forum_is_enabled($forum_id))
        {
            return false;
        }

        if ($this->can_manage_topic($forum_id))
        {
            return true;
        }

        if (!$this->has_forum_helpdesk_visibility_base($forum_id))
        {
            return false;
        }

        return $this->auth->acl_get('f_helpdesk_ticket', $forum_id)
            || $this->auth->acl_get('f_post', $forum_id);
    }

    protected function has_forum_helpdesk_visibility_base($forum_id)
    {
        $forum_id = (int) $forum_id;

        return $forum_id > 0
            && $this->auth->acl_get('f_list', $forum_id)
            && $this->auth->acl_get('f_read', $forum_id);
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

    protected function can_view_internal_notes($forum_id)
    {
        $forum_id = (int) $forum_id;
        return $this->internal_notes_enabled() && $forum_id > 0 && $this->can_view_team_queue($forum_id);
    }

    protected function can_add_internal_notes($forum_id)
    {
        $forum_id = (int) $forum_id;
        return $this->internal_notes_enabled() && $forum_id > 0 && $this->can_view_team_queue($forum_id);
    }

    protected function can_view_team_queue($forum_id = 0)
    {
        if ($this->auth->acl_get('a_helpdesk_manage'))
        {
            return true;
        }

        $forum_id = (int) $forum_id;
        if ($forum_id > 0)
        {
            return $this->auth->acl_get('m_helpdesk_queue', $forum_id) || $this->can_manage_topic($forum_id) || $this->can_bulk_manage($forum_id) || $this->can_manage_assignment($forum_id);
        }

        foreach ($this->enabled_forum_ids() as $enabled_forum_id)
        {
            if ($this->auth->acl_get('m_helpdesk_queue', $enabled_forum_id) || $this->can_manage_topic($enabled_forum_id) || $this->can_bulk_manage($enabled_forum_id) || $this->can_manage_assignment($enabled_forum_id))
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

    protected function current_helpdesk_forum_context()
    {
        $script_name = basename((string) $this->request->server('SCRIPT_NAME'));
        $forum_id = (int) $this->request->variable('f', 0);

        if ($script_name !== 'viewforum.php' || $forum_id <= 0 || !$this->forum_is_enabled($forum_id) || !$this->can_view_helpdesk_meta($forum_id))
        {
            return [];
        }

        return [
            'forum_id' => $forum_id,
            'can_open_ticket' => $this->can_use_helpdesk_ticket_editor($forum_id),
            'new_ticket_url' => $this->posting_url($forum_id),
        ];
    }

    protected function posting_url($forum_id)
    {
        global $phpbb_root_path, $phpEx;

        return append_sid("{$phpbb_root_path}posting.$phpEx", 'mode=post&f=' . (int) $forum_id);
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
            if ($this->can_view_helpdesk_meta($forum_id))
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
            WHERE topic_id = ' . (int) $topic_id;
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

    protected function notes_table()
    {
        return $this->table_prefix . 'helpdesk_notes';
    }

    protected function insert_internal_note($topic_id, $forum_id, $note_text)
    {
        if (!$this->internal_notes_enabled())
        {
            return;
        }

        $sql_ary = [
            'note_id' => $this->next_internal_note_id(),
            'topic_id' => (int) $topic_id,
            'forum_id' => (int) $forum_id,
            'user_id' => (int) $this->user->data['user_id'],
            'note_text' => (string) $note_text,
            'note_time' => time(),
        ];

        $this->db->sql_query('INSERT INTO ' . $this->notes_table() . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
    }

    protected function next_internal_note_id()
    {
        if (!$this->internal_notes_enabled())
        {
            return 1;
        }

        $sql = 'SELECT MAX(note_id) AS max_note_id
            FROM ' . $this->notes_table();
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return (int) (!empty($row['max_note_id']) ? $row['max_note_id'] + 1 : 1);
    }

    protected function get_internal_notes($topic_id, $limit = 10)
    {
        $topic_id = (int) $topic_id;
        if (!$this->internal_notes_enabled() || $topic_id <= 0)
        {
            return [];
        }

        $sql = 'SELECT n.*, u.username, u.user_colour
            FROM ' . $this->notes_table() . ' n
            LEFT JOIN ' . USERS_TABLE . ' u
                ON u.user_id = n.user_id
            WHERE n.topic_id = ' . (int) $topic_id . '
            ORDER BY n.note_time DESC';
        $result = $this->db->sql_query_limit($sql, max(1, (int) $limit));

        $rows = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $rows[] = [
                'NOTE_ID' => (int) $row['note_id'],
                'NOTE_TEXT' => \utf8_htmlspecialchars(isset($row['note_text']) ? (string) $row['note_text'] : ''),
                'NOTE_TIME' => $this->user->format_date((int) $row['note_time']),
                'NOTE_USERNAME' => !empty($row['username']) ? (string) $row['username'] : $this->user->lang('GUEST'),
            ];
        }
        $this->db->sql_freeresult($result);

        return $rows;
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


    protected function department_reply_templates_for_topic($department_key, array $placeholders)
    {
        $raw = isset($this->config['mundophpbb_helpdesk_department_reply_templates']) ? (string) $this->config['mundophpbb_helpdesk_department_reply_templates'] : '';
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $templates = [];

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

            $entry_department = trim(str_replace('\\|', '|', $parts[0]));
            $label = trim(str_replace('\\|', '|', $parts[1]));
            $body = trim(str_replace('\\|', '|', $parts[2]));

            if ($entry_department === '' || $label === '' || $body === '')
            {
                continue;
            }

            if ($entry_department !== '*' && (string) $department_key === '')
            {
                continue;
            }

            if ($entry_department !== '*' && $entry_department !== (string) $department_key)
            {
                continue;
            }

            $templates[] = [
                'label' => $label,
                'body' => $this->expand_reply_template_body($body, $placeholders),
            ];
        }

        return $this->prioritize_reply_templates($templates, $this->department_profile_reply_template_label($department_key));
    }

    protected function expand_reply_template_body($body, array $placeholders)
    {
        $body = str_replace(['\\r\\n', '\\n', '\\r', '\\t'], ["\n", "\n", "\n", "\t"], (string) $body);

        foreach ($placeholders as $key => $value)
        {
            $body = str_replace('{' . strtoupper((string) $key) . '}', (string) $value, $body);
        }

        return trim($body);
    }

    protected function resolve_topic_author_name(array $topic_data)
    {
        $candidates = [
            'topic_first_poster_name',
            'topic_poster_name',
            'username',
        ];

        foreach ($candidates as $candidate)
        {
            if (!empty($topic_data[$candidate]) && !is_numeric($topic_data[$candidate]))
            {
                return (string) $topic_data[$candidate];
            }
        }

        return '';
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


    protected function get_assignable_users(array $forum_ids)
    {
        $forum_ids = array_map('intval', $forum_ids);
        $forum_ids = array_values(array_unique(array_filter($forum_ids)));
        if (!in_array(0, $forum_ids, true))
        {
            array_unshift($forum_ids, 0);
        }
        sort($forum_ids);

        $cache_key = implode(':', $forum_ids);
        if (isset($this->assignable_users_cache[$cache_key]))
        {
            return $this->assignable_users_cache[$cache_key];
        }

        $option_ids = $this->assignable_permission_option_ids();
        if (empty($option_ids))
        {
            $this->assignable_users_cache[$cache_key] = [];
            return $this->assignable_users_cache[$cache_key];
        }

        $role_settings = $this->assignable_permission_role_settings($option_ids);
        $assignable_users = [];

        $this->collect_assignable_users_from_acl_users($forum_ids, $option_ids, $role_settings, $assignable_users);
        $this->collect_assignable_users_from_acl_groups($forum_ids, $option_ids, $role_settings, $assignable_users);

        foreach ($assignable_users as $user_id => $row)
        {
            if (empty($row['has_grant']) || !empty($row['has_never']))
            {
                unset($assignable_users[$user_id]);
            }
        }

        uasort($assignable_users, function ($left, $right) {
            return strcmp($left['username_sort'], $right['username_sort']);
        });

        $this->assignable_users_cache[$cache_key] = array_values($assignable_users);
        return $this->assignable_users_cache[$cache_key];
    }

    protected function assignable_permission_option_ids()
    {
        $sql = 'SELECT auth_option_id, auth_option
            FROM ' . $this->table_prefix . "acl_options
            WHERE " . $this->db->sql_in_set('auth_option', ['m_helpdesk_manage', 'm_helpdesk_assign']);
        $result = $this->db->sql_query($sql);
        $option_ids = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $option_ids[] = (int) $row['auth_option_id'];
        }
        $this->db->sql_freeresult($result);

        return array_values(array_unique($option_ids));
    }

    protected function assignable_permission_role_settings(array $option_ids)
    {
        if (empty($option_ids))
        {
            return [];
        }

        $sql = 'SELECT role_id, auth_setting
            FROM ' . $this->table_prefix . 'acl_roles_data
            WHERE ' . $this->db->sql_in_set('auth_option_id', $option_ids) . '
                AND auth_setting <> 0';
        $result = $this->db->sql_query($sql);
        $role_settings = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $role_id = (int) $row['role_id'];
            if (!isset($role_settings[$role_id]))
            {
                $role_settings[$role_id] = [];
            }
            $role_settings[$role_id][] = (int) $row['auth_setting'];
        }
        $this->db->sql_freeresult($result);

        return $role_settings;
    }

    protected function collect_assignable_users_from_acl_users(array $forum_ids, array $option_ids, array $role_settings, array &$assignable_users)
    {
        if (empty($forum_ids) || empty($option_ids))
        {
            return;
        }

        $sql = 'SELECT u.user_id, u.username, u.username_clean, au.auth_option_id, au.auth_role_id, au.auth_setting
            FROM ' . $this->table_prefix . 'acl_users au
            INNER JOIN ' . $this->table_prefix . 'users u
                ON u.user_id = au.user_id
            WHERE ' . $this->db->sql_in_set('au.forum_id', $forum_ids) . '
                AND (au.auth_role_id <> 0 OR ' . $this->db->sql_in_set('au.auth_option_id', $option_ids) . ')
                AND u.user_id <> ' . (defined('ANONYMOUS') ? (int) ANONYMOUS : 1) . $this->assignable_users_type_sql();
        $result = $this->db->sql_query($sql);
        while ($row = $this->db->sql_fetchrow($result))
        {
            $user_id = (int) $row['user_id'];
            $this->initialize_assignable_user_entry($assignable_users, $user_id, (string) $row['username'], (string) $row['username_clean']);
            $this->apply_assignable_user_permission_settings($assignable_users[$user_id], (int) $row['auth_role_id'], (int) $row['auth_setting'], $role_settings);
        }
        $this->db->sql_freeresult($result);
    }

    protected function collect_assignable_users_from_acl_groups(array $forum_ids, array $option_ids, array $role_settings, array &$assignable_users)
    {
        if (empty($forum_ids) || empty($option_ids))
        {
            return;
        }

        $sql = 'SELECT u.user_id, u.username, u.username_clean, ag.auth_option_id, ag.auth_role_id, ag.auth_setting
            FROM ' . $this->table_prefix . 'acl_groups ag
            INNER JOIN ' . $this->table_prefix . 'user_group ug
                ON ug.group_id = ag.group_id
                AND ug.user_pending = 0
            INNER JOIN ' . $this->table_prefix . 'users u
                ON u.user_id = ug.user_id
            WHERE ' . $this->db->sql_in_set('ag.forum_id', $forum_ids) . '
                AND (ag.auth_role_id <> 0 OR ' . $this->db->sql_in_set('ag.auth_option_id', $option_ids) . ')
                AND u.user_id <> ' . (defined('ANONYMOUS') ? (int) ANONYMOUS : 1) . $this->assignable_users_type_sql();
        $result = $this->db->sql_query($sql);
        while ($row = $this->db->sql_fetchrow($result))
        {
            $user_id = (int) $row['user_id'];
            $this->initialize_assignable_user_entry($assignable_users, $user_id, (string) $row['username'], (string) $row['username_clean']);
            $this->apply_assignable_user_permission_settings($assignable_users[$user_id], (int) $row['auth_role_id'], (int) $row['auth_setting'], $role_settings);
        }
        $this->db->sql_freeresult($result);
    }

    protected function initialize_assignable_user_entry(array &$assignable_users, $user_id, $username, $username_clean)
    {
        if (isset($assignable_users[$user_id]))
        {
            return;
        }

        $assignable_users[$user_id] = [
            'user_id' => (int) $user_id,
            'username' => (string) $username,
            'username_key' => $this->normalize_assignee_key($username),
            'username_sort' => (string) $username_clean,
            'has_grant' => false,
            'has_never' => false,
        ];
    }

    protected function apply_assignable_user_permission_settings(array &$user_row, $role_id, $auth_setting, array $role_settings)
    {
        $role_id = (int) $role_id;
        if ($role_id > 0)
        {
            if (!empty($role_settings[$role_id]))
            {
                foreach ($role_settings[$role_id] as $setting)
                {
                    $this->mark_assignable_permission_setting($user_row, (int) $setting);
                }
            }
            return;
        }

        $this->mark_assignable_permission_setting($user_row, (int) $auth_setting);
    }

    protected function mark_assignable_permission_setting(array &$user_row, $setting)
    {
        $setting = (int) $setting;
        if ($setting < 0)
        {
            $user_row['has_never'] = true;
        }
        else if ($setting > 0)
        {
            $user_row['has_grant'] = true;
        }
    }

    protected function assignable_users_type_sql()
    {
        $allowed_user_types = [];
        if (defined('USER_NORMAL'))
        {
            $allowed_user_types[] = (int) USER_NORMAL;
        }
        if (defined('USER_FOUNDER'))
        {
            $allowed_user_types[] = (int) USER_FOUNDER;
        }

        if (!empty($allowed_user_types))
        {
            return ' AND ' . $this->db->sql_in_set('u.user_type', array_values(array_unique($allowed_user_types)));
        }

        return ' AND u.user_type <> ' . (defined('USER_IGNORE') ? (int) USER_IGNORE : 2);
    }

    protected function normalize_assignee_key($value)
    {
        $value = trim((string) $value);
        if ($value === '')
        {
            return '';
        }

        if (function_exists('utf8_strtolower'))
        {
            return utf8_strtolower($value);
        }

        return strtolower($value);
    }

    protected function assignee_exists_in_options($assignee, array $assignable_users)
    {
        $assignee_key = $this->normalize_assignee_key($assignee);
        if ($assignee_key === '')
        {
            return false;
        }

        foreach ($assignable_users as $assignee_row)
        {
            if ($assignee_key === (string) $assignee_row['username_key'])
            {
                return true;
            }
        }

        return false;
    }

    protected function build_form_token_fields($form_name, $template_variable_suffix = '')
    {
        if (!function_exists('add_form_key'))
        {
            return '';
        }

        add_form_key($form_name, $template_variable_suffix);

        $template_var = 'S_FORM_TOKEN' . $template_variable_suffix;

        if (method_exists($this->template, 'retrieve_var'))
        {
            $token_fields = (string) $this->template->retrieve_var($template_var);
            if ($token_fields !== '')
            {
                return $token_fields;
            }
        }

        if (isset($this->template->_tpldata['.'][0][$template_var]))
        {
            return (string) $this->template->_tpldata['.'][0][$template_var];
        }

        return '';
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
