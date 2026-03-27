<?php
/**
 * @copyright (c) 2026 MundoPHPBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\helpdesk\controller;

class queue_controller
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

    /** @var array|null */
    protected $status_cache;

    /** @var array|null */
    protected $priority_cache;

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
        $this->status_cache = null;
        $this->priority_cache = null;
    }

    public function display()
    {
        $this->user->add_lang_ext('mundophpbb/helpdesk', 'common');

        if (!$this->extension_enabled() || !$this->team_panel_enabled())
        {
            trigger_error('NOT_AUTHORISED');
        }

        $forum_ids = $this->accessible_forum_ids();
        if (empty($forum_ids))
        {
            trigger_error('NOT_AUTHORISED');
        }

        \add_form_key('mundophpbb_helpdesk_queue_actions');
        $this->handle_queue_post_actions($forum_ids);

        $queue_views = ['queue', 'reports', 'alerts'];
        $queue_view = (string) $this->request->variable('qview', 'queue', true);
        if (!in_array($queue_view, $queue_views, true))
        {
            $queue_view = 'queue';
        }

        $filters = [
            'scope' => $this->request->variable('scope', 'all', true),
            'forum_id' => $this->request->variable('forum_id', 0),
            'status_key' => $this->request->variable('status_key', '', true),
            'department_key' => $this->request->variable('department_key', '', true),
            'priority_key' => $this->request->variable('priority_key', '', true),
            'mine' => $this->request->variable('mine', 0),
            'assigned_to' => $this->sanitize_assignee($this->request->variable('assigned_to', '', true)),
        ];

        if (!in_array($filters['scope'], ['all', 'unassigned', 'overdue', 'stale', 'reopened', 'critical', 'attention', 'staff_reply', 'my', 'my_overdue', 'my_staff_reply', 'my_critical', 'my_prioritized', 'my_alerts', 'priority_high', 'priority_critical', 'prioritized', 'overloaded', 'redistribute'], true))
        {
            $filters['scope'] = 'all';
        }

        if ($filters['mine'])
        {
            $filters['scope'] = 'my';
        }

        $preview_mode = (string) $this->request->variable('preview', '', true);
        if (!in_array($preview_mode, ['', 'balanced', 'overload', 'critical', 'priority_high', 'priority_filtered', 'department_priority', 'cleanup', 'assignee', 'assignee_priority'], true))
        {
            $preview_mode = '';
        }

        $rows = $this->load_ticket_rows($forum_ids);
        $counts = $this->build_counts($rows);
        $filtered_rows = $this->filter_rows($rows, $filters);
        $recent_alerts = $this->alerts_enabled() ? $this->load_recent_alerts($forum_ids) : [];
        $report = $this->build_report($filtered_rows);
        $assignee_load = $this->build_assignee_load($rows);
        $my_workload = $this->current_user_workload($assignee_load);
        $redistribution = $this->build_redistribution_suggestions($rows, $assignee_load);
        $balanced_redistribution = $this->build_balanced_redistribution_plan($redistribution, $assignee_load);
        $balanced_index = [];
        foreach ($balanced_redistribution as $balanced_row)
        {
            $balanced_index[isset($balanced_row['CANDIDATE_KEY']) ? (string) $balanced_row['CANDIDATE_KEY'] : ''] = true;
        }
        foreach ($redistribution as &$redistribution_row)
        {
            $candidate_key = isset($redistribution_row['CANDIDATE_KEY']) ? (string) $redistribution_row['CANDIDATE_KEY'] : '';
            $redistribution_row['S_BALANCED_PICK'] = ($candidate_key !== '' && isset($balanced_index[$candidate_key]));
            $redistribution_row['BALANCED_REASON_TEXT'] = !empty($redistribution_row['S_BALANCED_PICK']) ? $this->balanced_redistribution_reason($redistribution_row) : '';
        }
        unset($redistribution_row);
        $smart_redistribution_count = $this->count_smart_redistribution($redistribution);
        $balanced_redistribution_count = $this->count_balanced_redistribution($redistribution);
        $preview_plan = [];
        if ($preview_mode === 'balanced')
        {
            $preview_plan = $balanced_redistribution;
        }
        else if ($preview_mode === 'overload')
        {
            $preview_plan = $this->build_overload_preview_plan($balanced_redistribution, $assignee_load);
        }
        else if ($preview_mode === 'critical')
        {
            $preview_plan = $this->build_critical_preview_plan($balanced_redistribution);
        }
        else if ($preview_mode === 'priority_high')
        {
            $preview_plan = $this->build_priority_high_preview_plan($balanced_redistribution);
        }
        else if ($preview_mode === 'priority_filtered')
        {
            $preview_plan = $this->build_filtered_priority_preview_plan($balanced_redistribution, $filters['priority_key']);
        }
        else if ($preview_mode === 'department_priority')
        {
            $preview_plan = $this->build_department_priority_preview_plan($balanced_redistribution, $filters['department_key'], $filters['priority_key']);
        }
        else if ($preview_mode === 'cleanup')
        {
            $preview_plan = $this->build_cleanup_preview_plan($balanced_redistribution);
        }
        else if ($preview_mode === 'assignee')
        {
            $preview_plan = $this->build_assignee_preview_plan($balanced_redistribution, $filters['assigned_to']);
        }
        else if ($preview_mode === 'assignee_priority')
        {
            $preview_plan = $this->build_assignee_priority_preview_plan($balanced_redistribution, $filters['assigned_to'], $filters['priority_key']);
        }
        $preview_impact = ($preview_mode !== '') ? $this->build_balanced_preview_impact($preview_plan, $assignee_load) : [];
        $preview_summary = ($preview_mode !== '') ? $this->build_preview_comparison_summary($preview_impact) : [];
        $preview_distribution = ($preview_mode !== '') ? $this->build_preview_distribution_rows($preview_impact) : [];
        $preview_top_impacts = ($preview_mode !== '') ? $this->build_preview_top_impact_rows($preview_impact) : [];
        $preview_groups = ($preview_mode !== '') ? $this->build_preview_group_rows($preview_impact) : [];
        $preview_departments = ($preview_mode !== '') ? $this->build_preview_department_rows($preview_plan) : [];

        foreach ($this->forum_info($forum_ids) as $forum)
        {
            $this->template->assign_block_vars('helpdesk_queue_forum_options', [
                'VALUE' => $forum['forum_id'],
                'LABEL' => $forum['forum_name'],
                'S_SELECTED' => (int) $filters['forum_id'] === (int) $forum['forum_id'],
            ]);
        }

        $this->template->assign_block_vars('helpdesk_queue_status_options', [
            'VALUE' => '',
            'LABEL' => $this->user->lang('HELPDESK_FILTER_ALL'),
            'S_SELECTED' => $filters['status_key'] === '',
        ]);

        foreach ($this->status_definitions() as $key => $definition)
        {
            $this->template->assign_block_vars('helpdesk_queue_status_options', [
                'VALUE' => $key,
                'LABEL' => $this->status_label_from_definition($definition),
                'S_SELECTED' => $filters['status_key'] === $key,
            ]);
        }

        $this->template->assign_block_vars('helpdesk_queue_department_options', [
            'VALUE' => '',
            'LABEL' => $this->user->lang('HELPDESK_FILTER_ALL'),
            'S_SELECTED' => $filters['department_key'] === '',
        ]);
        $this->template->assign_block_vars('helpdesk_queue_priority_options', [
            'VALUE' => '',
            'LABEL' => $this->user->lang('HELPDESK_FILTER_ALL'),
            'S_SELECTED' => $filters['priority_key'] === '',
        ]);
        foreach ($this->priority_definitions() as $key => $definition)
        {
            $this->template->assign_block_vars('helpdesk_queue_priority_options', [
                'VALUE' => $key,
                'LABEL' => $this->priority_label_from_definition($definition),
                'S_SELECTED' => $filters['priority_key'] === $key,
            ]);
        }
        foreach ($this->department_options() as $key => $label)
        {
            $this->template->assign_block_vars('helpdesk_queue_department_options', [
                'VALUE' => $key,
                'LABEL' => $label,
                'S_SELECTED' => $filters['department_key'] === $key,
            ]);
        }

        foreach ($filtered_rows as $row)
        {
            $this->template->assign_block_vars('helpdesk_queue_rows', $row);
        }

        foreach ($recent_alerts as $alert)
        {
            $this->template->assign_block_vars('helpdesk_recent_alerts', $alert);
        }

        foreach ($report['status_rows'] as $status_row)
        {
            $this->template->assign_block_vars('helpdesk_report_status_rows', $status_row);
        }

        foreach ($report['department_rows'] as $department_row)
        {
            $this->template->assign_block_vars('helpdesk_report_department_rows', $department_row);
        }
        foreach ($report['priority_rows'] as $priority_row)
        {
            $this->template->assign_block_vars('helpdesk_report_priority_rows', $priority_row);
        }
        foreach ($report['assignee_rows'] as $assignee_row)
        {
            $this->template->assign_block_vars('helpdesk_report_assignee_rows', $assignee_row);
        }
        $assignee_load_index = 0;
        foreach ($assignee_load as $load_row)
        {
            if ($assignee_load_index >= 8)
            {
                break;
            }

            $this->template->assign_block_vars('helpdesk_assignee_load_rows', $load_row);
            $assignee_load_index++;
        }

        foreach ($redistribution as $redistribution_row)
        {
            $this->template->assign_block_vars('helpdesk_redistribution_rows', $redistribution_row);
        }
        foreach ($preview_plan as $preview_row)
        {
            $this->template->assign_block_vars('helpdesk_redistribution_preview_rows', $preview_row);
        }
        foreach ($preview_impact as $impact_row)
        {
            $this->template->assign_block_vars('helpdesk_preview_impact_rows', $impact_row);
        }
        foreach ($preview_summary as $summary_row)
        {
            $this->template->assign_block_vars('helpdesk_preview_summary_rows', $summary_row);
        }
        foreach ($preview_distribution as $distribution_row)
        {
            $this->template->assign_block_vars('helpdesk_preview_distribution_rows', $distribution_row);
        }
        foreach ($preview_top_impacts as $top_impact_row)
        {
            $this->template->assign_block_vars('helpdesk_preview_top_impact_rows', $top_impact_row);
        }
        foreach ($preview_groups as $preview_group_row)
        {
            $this->template->assign_block_vars('helpdesk_preview_group_rows', $preview_group_row);
        }
        foreach ($preview_departments as $preview_department_row)
        {
            $this->template->assign_block_vars('helpdesk_preview_department_rows', $preview_department_row);
        }

        $base_queue_url = $this->helper->route('mundophpbb_helpdesk_queue_controller');

        $this->template->assign_vars([
            'S_HELPDESK_TEAM_QUEUE' => true,
            'HELPDESK_QUEUE_VIEW' => $queue_view,
            'S_HELPDESK_QUEUE_VIEW_QUEUE' => ($queue_view === 'queue'),
            'S_HELPDESK_QUEUE_VIEW_REPORTS' => ($queue_view === 'reports'),
            'S_HELPDESK_QUEUE_VIEW_ALERTS' => ($queue_view === 'alerts'),
            'HELPDESK_QUEUE_TOTAL' => $counts['total'],
            'HELPDESK_QUEUE_OPEN_COUNT' => $counts['open'],
            'HELPDESK_QUEUE_UNASSIGNED_COUNT' => $counts['unassigned'],
            'HELPDESK_QUEUE_OVERDUE_COUNT' => $counts['overdue'],
            'HELPDESK_QUEUE_STALE_COUNT' => $counts['stale'],
            'HELPDESK_QUEUE_REOPENED_COUNT' => $counts['reopened'],
            'HELPDESK_QUEUE_STAFF_REPLY_COUNT' => $counts['staff_reply'],
            'HELPDESK_QUEUE_CRITICAL_COUNT' => $counts['critical'],
            'HELPDESK_QUEUE_ATTENTION_COUNT' => $counts['attention'],
            'HELPDESK_QUEUE_PRIORITY_HIGH_COUNT' => $counts['priority_high'],
            'HELPDESK_QUEUE_PRIORITY_CRITICAL_COUNT' => $counts['priority_critical'],
            'HELPDESK_QUEUE_PRIORITIZED_COUNT' => $counts['prioritized'],
            'HELPDESK_QUEUE_OVERLOADED_COUNT' => $counts['overloaded'],
            'HELPDESK_QUEUE_REDISTRIBUTION_COUNT' => $counts['redistribute'],
            'HELPDESK_QUEUE_REDISTRIBUTION_SMART_COUNT' => $smart_redistribution_count,
            'HELPDESK_QUEUE_REDISTRIBUTION_BALANCED_COUNT' => $balanced_redistribution_count,
            'HELPDESK_QUEUE_PREVIEW_COUNT' => count($preview_plan),
            'HELPDESK_QUEUE_PREVIEW_SOURCE_COUNT' => $this->count_preview_sources($preview_impact),
            'HELPDESK_QUEUE_PREVIEW_TARGET_COUNT' => $this->count_preview_targets($preview_impact),
            'HELPDESK_QUEUE_MY_COUNT' => $counts['my'],
            'HELPDESK_QUEUE_MY_OVERDUE_COUNT' => $counts['my_overdue'],
            'HELPDESK_QUEUE_MY_STAFF_REPLY_COUNT' => $counts['my_staff_reply'],
            'HELPDESK_QUEUE_MY_CRITICAL_COUNT' => $counts['my_critical'],
            'HELPDESK_QUEUE_MY_PRIORITIZED_COUNT' => $counts['my_prioritized'],
            'HELPDESK_QUEUE_MY_ALERTS_COUNT' => $counts['my_alerts'],
            'HELPDESK_QUEUE_MY_LOAD_LABEL' => $my_workload['label'],
            'HELPDESK_QUEUE_MY_LOAD_CLASS' => $my_workload['class'],
            'HELPDESK_QUEUE_MY_LOAD_SCORE' => $my_workload['score'],
            'HELPDESK_QUEUE_MY_LOAD_COUNT' => $my_workload['active_count'],
            'HELPDESK_QUEUE_USER' => isset($this->user->data['username']) ? (string) $this->user->data['username'] : '',
            'HELPDESK_ALERT_HOURS' => $this->alert_hours(),
            'HELPDESK_ALERT_LIMIT' => $this->alert_limit(),
            'HELPDESK_FILTER_SCOPE' => $filters['scope'],
            'HELPDESK_FILTER_FORUM_ID' => (int) $filters['forum_id'],
            'HELPDESK_FILTER_STATUS' => $filters['status_key'],
            'HELPDESK_FILTER_DEPARTMENT' => $filters['department_key'],
            'HELPDESK_FILTER_PRIORITY' => $filters['priority_key'],
            'HELPDESK_FILTER_ASSIGNED_TO' => $filters['assigned_to'],
            'U_HELPDESK_TEAM_QUEUE' => $base_queue_url,
            'U_HELPDESK_QUEUE_PREVIEW_BALANCED' => $this->queue_preview_url('balanced'),
            'U_HELPDESK_QUEUE_PREVIEW_OVERLOAD' => $this->queue_preview_url('overload'),
            'U_HELPDESK_QUEUE_PREVIEW_CRITICAL' => $this->queue_preview_url('critical'),
            'U_HELPDESK_QUEUE_PREVIEW_PRIORITY_HIGH' => $this->queue_preview_url('priority_high'),
            'U_HELPDESK_QUEUE_PREVIEW_PRIORITY_FILTERED' => $this->queue_preview_url('priority_filtered'),
            'U_HELPDESK_QUEUE_PREVIEW_DEPARTMENT_PRIORITY' => $this->queue_preview_url('department_priority'),
            'U_HELPDESK_QUEUE_PREVIEW_CLEANUP' => $this->queue_preview_url('cleanup'),
            'U_HELPDESK_QUEUE_PREVIEW_ASSIGNEE' => $this->queue_preview_url('assignee'),
            'U_HELPDESK_QUEUE_PREVIEW_ASSIGNEE_PRIORITY' => $this->queue_preview_url('assignee_priority'),
            'U_HELPDESK_QUEUE_PREVIEW_CLEAR' => $this->queue_preview_url(''),
            'HELPDESK_QUEUE_PREVIEW_TITLE' => $this->preview_title($preview_mode),
            'HELPDESK_QUEUE_PREVIEW_EXPLAIN' => $this->preview_explain($preview_mode),
            'HELPDESK_QUEUE_PREVIEW_MODE' => $preview_mode,
            'U_HELPDESK_TEAM_QUEUE_RESET' => $base_queue_url,
            'U_HELPDESK_TEAM_QUEUE_QUEUE' => $base_queue_url . '?qview=queue',
            'U_HELPDESK_TEAM_QUEUE_REPORTS' => $base_queue_url . '?qview=reports',
            'U_HELPDESK_TEAM_QUEUE_ALERTS' => $base_queue_url . '?qview=alerts',
            'S_HELPDESK_ALERTS_ENABLED' => $this->alerts_enabled(),
            'S_HELPDESK_QUEUE_HAS_RESULTS' => !empty($filtered_rows),
            'S_HELPDESK_ASSIGNEE_LOAD_HAS_DATA' => !empty($assignee_load),
            'S_HELPDESK_REDIS_HAS_DATA' => !empty($redistribution),
            'S_HELPDESK_QUEUE_PREVIEW' => ($preview_mode !== '' && !empty($preview_plan)),
            'S_HELPDESK_QUEUE_PREVIEW_EMPTY' => ($preview_mode !== '' && empty($preview_plan)),
            'S_HELPDESK_QUEUE_PREVIEW_COMPARE' => ($preview_mode !== '' && !empty($preview_impact)),
            'S_HELPDESK_QUEUE_PREVIEW_OVERLOAD_MODE' => ($preview_mode === 'overload'),
            'S_HELPDESK_QUEUE_PREVIEW_BALANCED_MODE' => ($preview_mode === 'balanced'),
            'S_HELPDESK_QUEUE_PREVIEW_CRITICAL_MODE' => ($preview_mode === 'critical'),
            'S_HELPDESK_QUEUE_PREVIEW_PRIORITY_HIGH_MODE' => ($preview_mode === 'priority_high'),
            'S_HELPDESK_QUEUE_PREVIEW_PRIORITY_FILTERED_MODE' => ($preview_mode === 'priority_filtered'),
            'S_HELPDESK_QUEUE_PREVIEW_DEPARTMENT_PRIORITY_MODE' => ($preview_mode === 'department_priority'),
            'S_HELPDESK_QUEUE_PREVIEW_CLEANUP_MODE' => ($preview_mode === 'cleanup'),
            'S_HELPDESK_QUEUE_PREVIEW_ASSIGNEE_MODE' => ($preview_mode === 'assignee'),
            'S_HELPDESK_QUEUE_PREVIEW_ASSIGNEE_PRIORITY_MODE' => ($preview_mode === 'assignee_priority'),
            'S_HELPDESK_QUEUE_HAS_DEPARTMENT_FILTER' => ($filters['department_key'] !== ''),
            'S_HELPDESK_QUEUE_HAS_PRIORITY_FILTER' => ($filters['priority_key'] !== ''),
            'S_HELPDESK_QUEUE_HAS_DEPARTMENT_AND_PRIORITY_FILTER' => ($filters['department_key'] !== '' && $filters['priority_key'] !== ''),
            'S_HELPDESK_QUEUE_HAS_ASSIGNEE_FILTER' => ($filters['assigned_to'] !== ''),
            'S_HELPDESK_QUEUE_HAS_ASSIGNEE_AND_PRIORITY_FILTER' => ($filters['assigned_to'] !== '' && $filters['priority_key'] !== ''),
            'HELPDESK_TEAM_ALERTS_EXPLAIN_TEXT' => sprintf($this->user->lang('HELPDESK_TEAM_ALERTS_EXPLAIN'), $this->alert_hours(), $this->alert_limit()),
            'S_HELPDESK_REPORT_HAS_DATA' => !empty($report['total']),
            'S_HELPDESK_CAN_QUEUE_ASSIGN' => $this->can_assign_any_queue($forum_ids),
            'S_HELPDESK_CAN_QUEUE_BULK_ASSIGN' => $this->can_assign_any_queue($forum_ids),
            'S_HELPDESK_QUEUE_NOTICE' => $this->queue_notice_text() !== '',
            'HELPDESK_QUEUE_NOTICE_TEXT' => $this->queue_notice_text(),
            'HELPDESK_REPORT_TOTAL' => $report['total'],
            'HELPDESK_REPORT_ACTIVE' => $report['active'],
            'HELPDESK_REPORT_RESOLVED' => $report['resolved'],
            'HELPDESK_REPORT_CLOSED' => $report['closed'],
            'HELPDESK_REPORT_UNASSIGNED' => $report['unassigned'],
            'HELPDESK_REPORT_FIRST_REPLY' => $report['first_reply'],
            'HELPDESK_REPORT_UPDATED_24H' => $report['updated_24h'],
            'HELPDESK_REPORT_CREATED_24H' => $report['created_24h'],
            'HELPDESK_REPORT_OVERDUE' => $report['overdue'],
            'HELPDESK_REPORT_STAFF_REPLY' => $report['staff_reply'],
            'HELPDESK_REPORT_AVG_AGE' => $report['avg_age_label'],
            'HELPDESK_REPORT_AVG_IDLE' => $report['avg_idle_label'],
        ]);

        return $this->helper->render('helpdesk_queue_body.html', $this->user->lang('HELPDESK_TEAM_QUEUE_TITLE'));
    }

    protected function load_ticket_rows(array $forum_ids)
    {
        if (empty($forum_ids))
        {
            return [];
        }

        $sql = 'SELECT h.*, t.topic_title, t.topic_last_post_time,
                t.topic_poster, t.topic_last_poster_id, t.topic_status, t.topic_visibility,
                f.forum_name
            FROM ' . $this->topics_table() . ' h
            INNER JOIN ' . $this->table_prefix . 'topics t
                ON t.topic_id = h.topic_id
            INNER JOIN ' . $this->table_prefix . 'forums f
                ON f.forum_id = h.forum_id
            WHERE ' . $this->db->sql_in_set('h.forum_id', array_map('intval', $forum_ids)) . '
            ORDER BY h.updated_time DESC, h.topic_id DESC';
        $result = $this->db->sql_query_limit($sql, 250);

        $source_rows = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $source_rows[] = $row;
        }
        $this->db->sql_freeresult($result);

        $reply_counts = $this->load_reply_counts(array_column($source_rows, 'topic_id'));

        $rows = [];
        foreach ($source_rows as $row)
        {
            $row['helpdesk_reply_count'] = $reply_counts[(int) $row['topic_id']] ?? 0;
            $status_meta = $this->status_meta(isset($row['status_key']) ? (string) $row['status_key'] : 'open');
            $priority_meta = $this->priority_meta(isset($row['priority_key']) ? (string) $row['priority_key'] : 'normal');
            $reply_count = $this->topic_reply_count($row);
            $reopen_count = $this->get_reopen_count((int) $row['topic_id']);
            $staff_reply_pending = $this->is_waiting_staff_response($row, $status_meta['tone']);
            $is_overdue = $this->sla_enabled() && $this->is_ticket_overdue($row);
            $is_stale = $this->sla_enabled() && $this->is_ticket_stale($row);
            $is_very_old = $this->is_ticket_very_old($row);
            $criticality = $this->operational_criticality(
                $status_meta['tone'],
                $priority_meta['tone'],
                $is_overdue,
                $is_stale,
                $reply_count <= 0,
                $is_very_old,
                $this->extract_assigned_to($row) === '',
                $staff_reply_pending,
                $reopen_count > 0
            );

            $priority_route = $this->department_priority_queue_rule($this->extract_department_key($row), isset($row['priority_key']) ? (string) $row['priority_key'] : 'normal');
            $assignee_route = $this->assignee_queue_rule($this->extract_assigned_to($row));
            $combined_route = [
                'enabled' => !empty($priority_route['enabled']) || !empty($assignee_route['enabled']),
                'queue_boost' => (int) ($priority_route['queue_boost'] ?? 0) + (int) ($assignee_route['queue_boost'] ?? 0),
                'alert_hours' => 0,
            ];
            $is_prioritized_queue = $this->is_active_status_tone($status_meta['tone']) && !empty($combined_route['enabled']);
            $is_priority_alert = false;
            if ($this->is_active_status_tone($status_meta['tone']) && !empty($priority_route['enabled']) && !empty($priority_route['alert_hours']))
            {
                $is_priority_alert = (time() - $this->topic_last_activity_time($row)) >= ((int) $priority_route['alert_hours'] * 3600);
            }
            $is_assignee_alert = false;
            if ($this->is_active_status_tone($status_meta['tone']) && !empty($assignee_route['enabled']) && !empty($assignee_route['alert_hours']))
            {
                $is_assignee_alert = (time() - $this->topic_last_activity_time($row)) >= ((int) $assignee_route['alert_hours'] * 3600);
            }
            $queue_score = $this->queue_operational_score($combined_route, $criticality, $is_overdue, $is_stale, $staff_reply_pending, $reply_count <= 0, $reopen_count > 0, $this->extract_assigned_to($row) === '', $this->topic_last_activity_time($row));

            $alerts = [];
            if ($is_overdue)
            {
                $alerts[] = $this->user->lang('HELPDESK_QUEUE_OVERDUE');
            }
            if ($is_stale)
            {
                $alerts[] = $this->user->lang('HELPDESK_QUEUE_STALE');
            }
            if ($reply_count <= 0 && $this->is_active_status_tone($status_meta['tone']))
            {
                $alerts[] = $this->user->lang('HELPDESK_QUEUE_FIRST_REPLY');
            }
            if ($staff_reply_pending)
            {
                $alerts[] = $this->user->lang('HELPDESK_QUEUE_STAFF_REPLY');
            }
            if ($reopen_count > 0)
            {
                $alerts[] = $this->user->lang('HELPDESK_QUEUE_REOPENED');
            }
            if ($criticality['key'] === 'critical' || $criticality['key'] === 'attention')
            {
                $alerts[] = $criticality['label'];
            }
            if ($is_priority_alert)
            {
                $alerts[] = sprintf($this->user->lang('HELPDESK_QUEUE_PRIORITY_ALERT'), $this->resolve_option_label($this->extract_department_key($row), $this->department_options(), $this->extract_department_key($row)), $priority_meta['label']);
            }
            if ($is_assignee_alert)
            {
                $alerts[] = sprintf($this->user->lang('HELPDESK_QUEUE_ASSIGNEE_ALERT'), $this->extract_assigned_to($row));
            }

            $queue_rule_labels = [];
            if ($this->is_active_status_tone($status_meta['tone']) && !empty($priority_route['enabled']))
            {
                $queue_rule_labels[] = sprintf($this->user->lang('HELPDESK_QUEUE_PRIORITY_MATCH'), $this->resolve_option_label($this->extract_department_key($row), $this->department_options(), $this->extract_department_key($row)), $priority_meta['label']);
            }
            if ($this->is_active_status_tone($status_meta['tone']) && !empty($assignee_route['enabled']))
            {
                $queue_rule_labels[] = sprintf($this->user->lang('HELPDESK_QUEUE_ASSIGNEE_MATCH'), $this->extract_assigned_to($row));
            }

            $rows[] = [
                'TOPIC_ID' => (int) $row['topic_id'],
                'FORUM_ID' => (int) $row['forum_id'],
                'FORUM_NAME' => (string) $row['forum_name'],
                'TOPIC_TITLE' => (string) $row['topic_title'],
                'U_TOPIC' => $this->topic_url((int) $row['forum_id'], (int) $row['topic_id']),
                'U_FORUM' => $this->forum_url((int) $row['forum_id']),
                'STATUS_KEY' => (string) $row['status_key'],
                'STATUS_LABEL' => $status_meta['label'],
                'STATUS_CLASS' => $status_meta['class'],
                'STATUS_TONE' => $status_meta['tone'],
                'PRIORITY_KEY' => isset($row['priority_key']) ? (string) $row['priority_key'] : 'normal',
                'PRIORITY_LABEL' => $priority_meta['label'],
                'PRIORITY_CLASS' => $priority_meta['class'],
                'PRIORITY_TONE' => $priority_meta['tone'],
                'DEPARTMENT_KEY' => $this->extract_department_key($row),
                'DEPARTMENT_LABEL' => $this->resolve_option_label($this->extract_department_key($row), $this->department_options(), ''),
                'ASSIGNED_TO' => $this->extract_assigned_to($row),
                'CREATED_TS' => !empty($row['created_time']) ? (int) $row['created_time'] : 0,
                'UPDATED_TS' => !empty($row['updated_time']) ? (int) $row['updated_time'] : 0,
                'REPLY_COUNT' => $reply_count,
                'UPDATED_AT' => !empty($row['updated_time']) ? $this->user->format_date((int) $row['updated_time']) : '',
                'LAST_ACTIVITY_AT' => $this->user->format_date($this->topic_last_activity_time($row)),
                'IS_UNASSIGNED' => $this->extract_assigned_to($row) === '',
                'IS_OVERDUE' => $is_overdue,
                'IS_STALE' => $is_stale,
                'IS_REOPENED' => $reopen_count > 0,
                'IS_STAFF_REPLY' => $staff_reply_pending,
                'IS_OPEN' => $this->is_active_status_tone($status_meta['tone']),
                'IS_CRITICAL' => $criticality['key'] === 'critical',
                'IS_ATTENTION' => $criticality['key'] === 'attention',
                'IS_PRIORITIZED' => $is_prioritized_queue,
                'IS_PRIORITY_ALERT' => $is_priority_alert,
                'IS_ASSIGNEE_ALERT' => $is_assignee_alert,
                'IS_ASSIGNEE_PRIORITIZED' => !empty($assignee_route['enabled']) && $this->is_active_status_tone($status_meta['tone']),
                'QUEUE_SCORE' => $queue_score,
                'QUEUE_RULE_LABEL' => !empty($queue_rule_labels) ? implode(' · ', $queue_rule_labels) : '',
                'ALERT_TEXT' => implode(' · ', $alerts),
            ];
        }

        $assignee_load = $this->build_assignee_load($rows);
        foreach ($rows as $index => $queue_row)
        {
            $assigned_to = isset($queue_row['ASSIGNED_TO']) ? strtolower((string) $queue_row['ASSIGNED_TO']) : '';
            if ($assigned_to !== '' && isset($assignee_load[$assigned_to]))
            {
                $load_row = $assignee_load[$assigned_to];
                $rows[$index]['WORKLOAD_KEY'] = $load_row['WORKLOAD_KEY'];
                $rows[$index]['WORKLOAD_LABEL'] = $load_row['WORKLOAD_LABEL'];
                $rows[$index]['WORKLOAD_CLASS'] = $load_row['WORKLOAD_CLASS'];
                $rows[$index]['WORKLOAD_SCORE'] = $load_row['SCORE'];
                $rows[$index]['WORKLOAD_ACTIVE_COUNT'] = $load_row['ACTIVE_COUNT'];
                $rows[$index]['WORKLOAD_OVERDUE_COUNT'] = $load_row['OVERDUE_COUNT'];
                $rows[$index]['WORKLOAD_CRITICAL_COUNT'] = $load_row['CRITICAL_COUNT'];
                $rows[$index]['IS_WORKLOAD_OVERLOADED'] = in_array($load_row['WORKLOAD_KEY'], ['high', 'overload'], true);
                $rows[$index]['QUEUE_SCORE'] = (int) $rows[$index]['QUEUE_SCORE'] + (int) $load_row['QUEUE_WEIGHT'];
            }
            else
            {
                $rows[$index]['WORKLOAD_KEY'] = 'idle';
                $rows[$index]['WORKLOAD_LABEL'] = $this->user->lang('HELPDESK_WORKLOAD_IDLE');
                $rows[$index]['WORKLOAD_CLASS'] = 'helpdesk-workload-idle';
                $rows[$index]['WORKLOAD_SCORE'] = 0;
                $rows[$index]['WORKLOAD_ACTIVE_COUNT'] = 0;
                $rows[$index]['WORKLOAD_OVERDUE_COUNT'] = 0;
                $rows[$index]['WORKLOAD_CRITICAL_COUNT'] = 0;
                $rows[$index]['IS_WORKLOAD_OVERLOADED'] = false;
            }
        }

        foreach ($rows as $index => $queue_row)
        {
            $rows[$index]['IS_REDISTRIBUTION_CANDIDATE'] = $this->is_redistribution_candidate_row($queue_row);
        }

        usort($rows, function ($a, $b) {
            $scoreA = isset($a['QUEUE_SCORE']) ? (int) $a['QUEUE_SCORE'] : 0;
            $scoreB = isset($b['QUEUE_SCORE']) ? (int) $b['QUEUE_SCORE'] : 0;
            if ($scoreA !== $scoreB)
            {
                return ($scoreA > $scoreB) ? -1 : 1;
            }

            $activityA = !empty($a['UPDATED_TS']) ? (int) $a['UPDATED_TS'] : (!empty($a['CREATED_TS']) ? (int) $a['CREATED_TS'] : 0);
            $activityB = !empty($b['UPDATED_TS']) ? (int) $b['UPDATED_TS'] : (!empty($b['CREATED_TS']) ? (int) $b['CREATED_TS'] : 0);
            if ($activityA !== $activityB)
            {
                return ($activityA < $activityB) ? -1 : 1;
            }

            return ((int) $a['TOPIC_ID'] < (int) $b['TOPIC_ID']) ? -1 : 1;
        });

        return $rows;
    }


    protected function handle_queue_post_actions(array $forum_ids)
    {
        $action = $this->request->variable('helpdesk_queue_action', '', true);
        if (!in_array($action, ['apply_redistribution', 'apply_redistribution_bulk', 'apply_redistribution_balanced', 'apply_redistribution_overload', 'apply_redistribution_department', 'apply_redistribution_critical', 'apply_redistribution_priority_high', 'apply_redistribution_priority_filtered', 'apply_redistribution_department_priority', 'apply_redistribution_cleanup', 'apply_redistribution_assignee', 'apply_redistribution_assignee_priority'], true))
        {
            return;
        }

        if (!\check_form_key('mundophpbb_helpdesk_queue_actions'))
        {
            \redirect($this->queue_redirect_url('invalid'));
        }

        if ($action === 'apply_redistribution')
        {
            $topic_id = $this->request->variable('topic_id', 0);
            $forum_id = $this->request->variable('forum_id', 0);
            $target_assignee = $this->sanitize_assignee($this->request->variable('target_assignee', '', true));

            if ($topic_id <= 0 || $forum_id <= 0 || $target_assignee === '' || !in_array((int) $forum_id, array_map('intval', $forum_ids), true) || !$this->can_assign_forum($forum_id))
            {
                \redirect($this->queue_redirect_url('invalid'));
            }

            $updated = $this->apply_queue_redistribution((int) $topic_id, (int) $forum_id, (string) $target_assignee);
            \redirect($this->queue_redirect_url($updated ? 'redistributed' : 'noop'));
        }

        if ($action === 'apply_redistribution_balanced')
        {
            $filters = [
                'scope' => $this->request->variable('scope', 'redistribute', true),
                'forum_id' => $this->request->variable('forum_id', 0),
                'status_key' => $this->request->variable('status_key', '', true),
                'department_key' => $this->request->variable('department_key', '', true),
                'priority_key' => $this->request->variable('priority_key', '', true),
                'mine' => $this->request->variable('mine', 0),
                'assigned_to' => $this->sanitize_assignee($this->request->variable('assigned_to', '', true)),
            ];

            if (!in_array($filters['scope'], ['all', 'unassigned', 'overdue', 'stale', 'reopened', 'critical', 'attention', 'staff_reply', 'my', 'my_overdue', 'my_staff_reply', 'my_critical', 'my_prioritized', 'my_alerts', 'priority_high', 'priority_critical', 'prioritized', 'overloaded', 'redistribute'], true))
            {
                $filters['scope'] = 'redistribute';
            }

            if ($filters['mine'])
            {
                $filters['scope'] = 'my';
            }

            $all_rows = $this->load_ticket_rows($forum_ids);
            $scope_rows = $this->filter_rows($all_rows, $filters);
            $assignee_load = $this->build_assignee_load($all_rows);
            $redistribution = $this->build_redistribution_suggestions($scope_rows, $assignee_load);
            $plan = $this->build_balanced_redistribution_plan($redistribution, $assignee_load);

            $updated_count = 0;
            foreach ($plan as $plan_row)
            {
                if ($this->apply_queue_redistribution(
                    isset($plan_row['TOPIC_ID']) ? (int) $plan_row['TOPIC_ID'] : 0,
                    isset($plan_row['FORUM_ID']) ? (int) $plan_row['FORUM_ID'] : 0,
                    isset($plan_row['TARGET_KEY']) ? (string) $plan_row['TARGET_KEY'] : '',
                    (string) $this->user->lang('HELPDESK_AUTO_REASON_REDISTRIBUTION_BALANCED')
                ))
                {
                    $updated_count++;
                }
            }

            \redirect($this->queue_redirect_url($updated_count > 0 ? 'redistributed_balanced' : 'noop'));
        }


        if ($action === 'apply_redistribution_department')
        {
            $filters = [
                'scope' => $this->request->variable('scope', 'redistribute', true),
                'forum_id' => $this->request->variable('forum_id', 0),
                'status_key' => $this->request->variable('status_key', '', true),
                'department_key' => $this->request->variable('department_key', '', true),
                'priority_key' => $this->request->variable('priority_key', '', true),
                'mine' => $this->request->variable('mine', 0),
                'assigned_to' => $this->sanitize_assignee($this->request->variable('assigned_to', '', true)),
            ];

            if (!in_array($filters['scope'], ['all', 'unassigned', 'overdue', 'stale', 'reopened', 'critical', 'attention', 'staff_reply', 'my', 'my_overdue', 'my_staff_reply', 'my_critical', 'my_prioritized', 'my_alerts', 'priority_high', 'priority_critical', 'prioritized', 'overloaded', 'redistribute'], true))
            {
                $filters['scope'] = 'redistribute';
            }

            if ($filters['mine'])
            {
                $filters['scope'] = 'my';
            }

            if ((string) $filters['department_key'] === '')
            {
                
edirect($this->queue_redirect_url('invalid'));
            }

            $all_rows = $this->load_ticket_rows($forum_ids);
            $scope_rows = $this->filter_rows($all_rows, $filters);
            $assignee_load = $this->build_assignee_load($all_rows);
            $redistribution = $this->build_redistribution_suggestions($scope_rows, $assignee_load);
            $plan = $this->build_balanced_redistribution_plan($redistribution, $assignee_load);

            $updated_count = 0;
            foreach ($plan as $plan_row)
            {
                if ($this->apply_queue_redistribution(
                    isset($plan_row['TOPIC_ID']) ? (int) $plan_row['TOPIC_ID'] : 0,
                    isset($plan_row['FORUM_ID']) ? (int) $plan_row['FORUM_ID'] : 0,
                    isset($plan_row['TARGET_KEY']) ? (string) $plan_row['TARGET_KEY'] : '',
                    (string) $this->user->lang('HELPDESK_AUTO_REASON_REDISTRIBUTION_DEPARTMENT')
                ))
                {
                    $updated_count++;
                }
            }

            
edirect($this->queue_redirect_url($updated_count > 0 ? 'redistributed_department' : 'noop'));
        }

        if ($action === 'apply_redistribution_overload')
        {
            $filters = [
                'scope' => $this->request->variable('scope', 'redistribute', true),
                'forum_id' => $this->request->variable('forum_id', 0),
                'status_key' => $this->request->variable('status_key', '', true),
                'department_key' => $this->request->variable('department_key', '', true),
                'priority_key' => $this->request->variable('priority_key', '', true),
                'mine' => $this->request->variable('mine', 0),
                'assigned_to' => $this->sanitize_assignee($this->request->variable('assigned_to', '', true)),
            ];

            if (!in_array($filters['scope'], ['all', 'unassigned', 'overdue', 'stale', 'reopened', 'critical', 'attention', 'staff_reply', 'my', 'my_overdue', 'my_staff_reply', 'my_critical', 'my_prioritized', 'my_alerts', 'priority_high', 'priority_critical', 'prioritized', 'overloaded', 'redistribute'], true))
            {
                $filters['scope'] = 'redistribute';
            }

            if ($filters['mine'])
            {
                $filters['scope'] = 'my';
            }

            $all_rows = $this->load_ticket_rows($forum_ids);
            $scope_rows = $this->filter_rows($all_rows, $filters);
            $assignee_load = $this->build_assignee_load($all_rows);
            $redistribution = $this->build_redistribution_suggestions($scope_rows, $assignee_load);
            $balanced_plan = $this->build_balanced_redistribution_plan($redistribution, $assignee_load);
            $plan = $this->build_overload_preview_plan($balanced_plan, $assignee_load);

            $updated_count = 0;
            foreach ($plan as $plan_row)
            {
                if ($this->apply_queue_redistribution(
                    isset($plan_row['TOPIC_ID']) ? (int) $plan_row['TOPIC_ID'] : 0,
                    isset($plan_row['FORUM_ID']) ? (int) $plan_row['FORUM_ID'] : 0,
                    isset($plan_row['TARGET_KEY']) ? (string) $plan_row['TARGET_KEY'] : '',
                    (string) $this->user->lang('HELPDESK_AUTO_REASON_REDISTRIBUTION_BALANCED')
                ))
                {
                    $updated_count++;
                }
            }

            \redirect($this->queue_redirect_url($updated_count > 0 ? 'redistributed_overload' : 'noop'));
        }


        if ($action === 'apply_redistribution_critical')
        {
            $filters = [
                'scope' => $this->request->variable('scope', 'redistribute', true),
                'forum_id' => $this->request->variable('forum_id', 0),
                'status_key' => $this->request->variable('status_key', '', true),
                'department_key' => $this->request->variable('department_key', '', true),
                'priority_key' => $this->request->variable('priority_key', '', true),
                'mine' => $this->request->variable('mine', 0),
                'assigned_to' => $this->sanitize_assignee($this->request->variable('assigned_to', '', true)),
            ];

            if (!in_array($filters['scope'], ['all', 'unassigned', 'overdue', 'stale', 'reopened', 'critical', 'attention', 'staff_reply', 'my', 'my_overdue', 'my_staff_reply', 'my_critical', 'my_prioritized', 'my_alerts', 'priority_high', 'priority_critical', 'prioritized', 'overloaded', 'redistribute'], true))
            {
                $filters['scope'] = 'redistribute';
            }

            if ($filters['mine'])
            {
                $filters['scope'] = 'my';
            }

            $all_rows = $this->load_ticket_rows($forum_ids);
            $scope_rows = $this->filter_rows($all_rows, $filters);
            $assignee_load = $this->build_assignee_load($all_rows);
            $redistribution = $this->build_redistribution_suggestions($scope_rows, $assignee_load);
            $plan = $this->build_critical_preview_plan($this->build_balanced_redistribution_plan($redistribution, $assignee_load));

            $updated_count = 0;
            foreach ($plan as $plan_row)
            {
                if ($this->apply_queue_redistribution(
                    isset($plan_row['TOPIC_ID']) ? (int) $plan_row['TOPIC_ID'] : 0,
                    isset($plan_row['FORUM_ID']) ? (int) $plan_row['FORUM_ID'] : 0,
                    isset($plan_row['TARGET_KEY']) ? (string) $plan_row['TARGET_KEY'] : '',
                    (string) $this->user->lang('HELPDESK_AUTO_REASON_REDISTRIBUTION_CRITICAL')
                ))
                {
                    $updated_count++;
                }
            }

            \redirect($this->queue_redirect_url($updated_count > 0 ? 'redistributed_critical' : 'noop'));
        }

        if ($action === 'apply_redistribution_priority_high')
        {
            $filters = [
                'scope' => $this->request->variable('scope', 'redistribute', true),
                'forum_id' => $this->request->variable('forum_id', 0),
                'status_key' => $this->request->variable('status_key', '', true),
                'department_key' => $this->request->variable('department_key', '', true),
                'priority_key' => $this->request->variable('priority_key', '', true),
                'mine' => $this->request->variable('mine', 0),
                'assigned_to' => $this->sanitize_assignee($this->request->variable('assigned_to', '', true)),
            ];

            if (!in_array($filters['scope'], ['all', 'unassigned', 'overdue', 'stale', 'reopened', 'critical', 'attention', 'staff_reply', 'my', 'my_overdue', 'my_staff_reply', 'my_critical', 'my_prioritized', 'my_alerts', 'priority_high', 'priority_critical', 'prioritized', 'overloaded', 'redistribute'], true))
            {
                $filters['scope'] = 'redistribute';
            }

            if ($filters['mine'])
            {
                $filters['scope'] = 'my';
            }

            $all_rows = $this->load_ticket_rows($forum_ids);
            $scope_rows = $this->filter_rows($all_rows, $filters);
            $assignee_load = $this->build_assignee_load($all_rows);
            $redistribution = $this->build_redistribution_suggestions($scope_rows, $assignee_load);
            $plan = $this->build_priority_high_preview_plan($this->build_balanced_redistribution_plan($redistribution, $assignee_load));

            $updated_count = 0;
            foreach ($plan as $plan_row)
            {
                if ($this->apply_queue_redistribution(
                    isset($plan_row['TOPIC_ID']) ? (int) $plan_row['TOPIC_ID'] : 0,
                    isset($plan_row['FORUM_ID']) ? (int) $plan_row['FORUM_ID'] : 0,
                    isset($plan_row['TARGET_KEY']) ? (string) $plan_row['TARGET_KEY'] : '',
                    (string) $this->user->lang('HELPDESK_AUTO_REASON_REDISTRIBUTION_HIGH')
                ))
                {
                    $updated_count++;
                }
            }

            \redirect($this->queue_redirect_url($updated_count > 0 ? 'redistributed_priority_high' : 'noop'));
        }

        if ($action === 'apply_redistribution_priority_filtered')
        {
            $filters = [
                'scope' => $this->request->variable('scope', 'redistribute', true),
                'forum_id' => $this->request->variable('forum_id', 0),
                'status_key' => $this->request->variable('status_key', '', true),
                'department_key' => $this->request->variable('department_key', '', true),
                'priority_key' => $this->request->variable('priority_key', '', true),
                'mine' => $this->request->variable('mine', 0),
                'assigned_to' => $this->sanitize_assignee($this->request->variable('assigned_to', '', true)),
            ];

            if (!in_array($filters['scope'], ['all', 'unassigned', 'overdue', 'stale', 'reopened', 'critical', 'attention', 'staff_reply', 'my', 'my_overdue', 'my_staff_reply', 'my_critical', 'my_prioritized', 'my_alerts', 'priority_high', 'priority_critical', 'prioritized', 'overloaded', 'redistribute'], true))
            {
                $filters['scope'] = 'redistribute';
            }

            if ($filters['mine'])
            {
                $filters['scope'] = 'my';
            }

            if ((string) $filters['priority_key'] === '')
            {
                \redirect($this->queue_redirect_url('invalid'));
            }

            $all_rows = $this->load_ticket_rows($forum_ids);
            $scope_rows = $this->filter_rows($all_rows, $filters);
            $assignee_load = $this->build_assignee_load($all_rows);
            $redistribution = $this->build_redistribution_suggestions($scope_rows, $assignee_load);
            $plan = $this->build_filtered_priority_preview_plan($this->build_balanced_redistribution_plan($redistribution, $assignee_load), $filters['priority_key']);

            $updated_count = 0;
            foreach ($plan as $plan_row)
            {
                if ($this->apply_queue_redistribution(
                    isset($plan_row['TOPIC_ID']) ? (int) $plan_row['TOPIC_ID'] : 0,
                    isset($plan_row['FORUM_ID']) ? (int) $plan_row['FORUM_ID'] : 0,
                    isset($plan_row['TARGET_KEY']) ? (string) $plan_row['TARGET_KEY'] : '',
                    (string) $this->user->lang('HELPDESK_AUTO_REASON_REDISTRIBUTION_PRIORITY_FILTERED')
                ))
                {
                    $updated_count++;
                }
            }

            \redirect($this->queue_redirect_url($updated_count > 0 ? 'redistributed_priority_filtered' : 'noop'));
        }


        if ($action === 'apply_redistribution_department_priority')
        {
            $filters = [
                'scope' => $this->request->variable('scope', 'redistribute', true),
                'forum_id' => $this->request->variable('forum_id', 0),
                'status_key' => $this->request->variable('status_key', '', true),
                'department_key' => $this->request->variable('department_key', '', true),
                'priority_key' => $this->request->variable('priority_key', '', true),
                'mine' => $this->request->variable('mine', 0),
                'assigned_to' => $this->sanitize_assignee($this->request->variable('assigned_to', '', true)),
            ];

            if (!in_array($filters['scope'], ['all', 'unassigned', 'overdue', 'stale', 'reopened', 'critical', 'attention', 'staff_reply', 'my', 'my_overdue', 'my_staff_reply', 'my_critical', 'my_prioritized', 'my_alerts', 'priority_high', 'priority_critical', 'prioritized', 'overloaded', 'redistribute'], true))
            {
                $filters['scope'] = 'redistribute';
            }

            if ($filters['mine'])
            {
                $filters['scope'] = 'my';
            }

            if ((string) $filters['department_key'] === '' || (string) $filters['priority_key'] === '')
            {
                \redirect($this->queue_redirect_url('invalid'));
            }

            $all_rows = $this->load_ticket_rows($forum_ids);
            $scope_rows = $this->filter_rows($all_rows, $filters);
            $assignee_load = $this->build_assignee_load($all_rows);
            $redistribution = $this->build_redistribution_suggestions($scope_rows, $assignee_load);
            $plan = $this->build_department_priority_preview_plan($this->build_balanced_redistribution_plan($redistribution, $assignee_load), $filters['department_key'], $filters['priority_key']);

            $updated_count = 0;
            foreach ($plan as $plan_row)
            {
                if ($this->apply_queue_redistribution(
                    isset($plan_row['TOPIC_ID']) ? (int) $plan_row['TOPIC_ID'] : 0,
                    isset($plan_row['FORUM_ID']) ? (int) $plan_row['FORUM_ID'] : 0,
                    isset($plan_row['TARGET_KEY']) ? (string) $plan_row['TARGET_KEY'] : '',
                    (string) $this->user->lang('HELPDESK_AUTO_REASON_REDISTRIBUTION_DEPARTMENT_PRIORITY')
                ))
                {
                    $updated_count++;
                }
            }

            \redirect($this->queue_redirect_url($updated_count > 0 ? 'redistributed_department_priority' : 'noop'));
        }

        if ($action === 'apply_redistribution_cleanup')
        {
            $filters = [
                'scope' => $this->request->variable('scope', 'redistribute', true),
                'forum_id' => $this->request->variable('forum_id', 0),
                'status_key' => $this->request->variable('status_key', '', true),
                'department_key' => $this->request->variable('department_key', '', true),
                'priority_key' => $this->request->variable('priority_key', '', true),
                'mine' => $this->request->variable('mine', 0),
                'assigned_to' => $this->sanitize_assignee($this->request->variable('assigned_to', '', true)),
            ];

            if (!in_array($filters['scope'], ['all', 'unassigned', 'overdue', 'stale', 'reopened', 'critical', 'attention', 'staff_reply', 'my', 'my_overdue', 'my_staff_reply', 'my_critical', 'my_prioritized', 'my_alerts', 'priority_high', 'priority_critical', 'prioritized', 'overloaded', 'redistribute'], true))
            {
                $filters['scope'] = 'redistribute';
            }

            if ($filters['mine'])
            {
                $filters['scope'] = 'my';
            }

            $all_rows = $this->load_ticket_rows($forum_ids);
            $scope_rows = $this->filter_rows($all_rows, $filters);
            $assignee_load = $this->build_assignee_load($all_rows);
            $redistribution = $this->build_redistribution_suggestions($scope_rows, $assignee_load);
            $plan = $this->build_cleanup_preview_plan($this->build_balanced_redistribution_plan($redistribution, $assignee_load));

            $updated_count = 0;
            foreach ($plan as $plan_row)
            {
                if ($this->apply_queue_redistribution(
                    isset($plan_row['TOPIC_ID']) ? (int) $plan_row['TOPIC_ID'] : 0,
                    isset($plan_row['FORUM_ID']) ? (int) $plan_row['FORUM_ID'] : 0,
                    isset($plan_row['TARGET_KEY']) ? (string) $plan_row['TARGET_KEY'] : '',
                    (string) $this->user->lang('HELPDESK_AUTO_REASON_REDISTRIBUTION_CLEANUP')
                ))
                {
                    $updated_count++;
                }
            }

            \redirect($this->queue_redirect_url($updated_count > 0 ? 'redistributed_cleanup' : 'noop'));
        }

        if ($action === 'apply_redistribution_assignee')
        {
            $filters = [
                'scope' => $this->request->variable('scope', 'redistribute', true),
                'forum_id' => $this->request->variable('forum_id', 0),
                'status_key' => $this->request->variable('status_key', '', true),
                'department_key' => $this->request->variable('department_key', '', true),
                'priority_key' => $this->request->variable('priority_key', '', true),
                'mine' => $this->request->variable('mine', 0),
                'assigned_to' => $this->sanitize_assignee($this->request->variable('assigned_to', '', true)),
            ];

            if (!in_array($filters['scope'], ['all', 'unassigned', 'overdue', 'stale', 'reopened', 'critical', 'attention', 'staff_reply', 'my', 'my_overdue', 'my_staff_reply', 'my_critical', 'my_prioritized', 'my_alerts', 'priority_high', 'priority_critical', 'prioritized', 'overloaded', 'redistribute'], true))
            {
                $filters['scope'] = 'redistribute';
            }

            if ($filters['mine'])
            {
                $filters['scope'] = 'my';
            }

            if ((string) $filters['assigned_to'] === '')
            {
                \redirect($this->queue_redirect_url('invalid'));
            }

            $all_rows = $this->load_ticket_rows($forum_ids);
            $scope_rows = $this->filter_rows($all_rows, $filters);
            $assignee_load = $this->build_assignee_load($all_rows);
            $redistribution = $this->build_redistribution_suggestions($scope_rows, $assignee_load);
            $plan = $this->build_assignee_preview_plan($this->build_balanced_redistribution_plan($redistribution, $assignee_load), $filters['assigned_to']);

            $updated_count = 0;
            foreach ($plan as $plan_row)
            {
                if ($this->apply_queue_redistribution(
                    isset($plan_row['TOPIC_ID']) ? (int) $plan_row['TOPIC_ID'] : 0,
                    isset($plan_row['FORUM_ID']) ? (int) $plan_row['FORUM_ID'] : 0,
                    isset($plan_row['TARGET_KEY']) ? (string) $plan_row['TARGET_KEY'] : '',
                    (string) $this->user->lang('HELPDESK_AUTO_REASON_REDISTRIBUTION_ASSIGNEE')
                ))
                {
                    $updated_count++;
                }
            }

            \redirect($this->queue_redirect_url($updated_count > 0 ? 'redistributed_assignee' : 'noop'));
        }

        if ($action === 'apply_redistribution_assignee_priority')
        {
            $filters = [
                'scope' => $this->request->variable('scope', 'redistribute', true),
                'forum_id' => $this->request->variable('forum_id', 0),
                'status_key' => $this->request->variable('status_key', '', true),
                'department_key' => $this->request->variable('department_key', '', true),
                'priority_key' => $this->request->variable('priority_key', '', true),
                'mine' => $this->request->variable('mine', 0),
                'assigned_to' => $this->sanitize_assignee($this->request->variable('assigned_to', '', true)),
            ];

            if (!in_array($filters['scope'], ['all', 'unassigned', 'overdue', 'stale', 'reopened', 'critical', 'attention', 'staff_reply', 'my', 'my_overdue', 'my_staff_reply', 'my_critical', 'my_prioritized', 'my_alerts', 'priority_high', 'priority_critical', 'prioritized', 'overloaded', 'redistribute'], true))
            {
                $filters['scope'] = 'redistribute';
            }

            if ($filters['mine'])
            {
                $filters['scope'] = 'my';
            }

            if ((string) $filters['assigned_to'] === '' || (string) $filters['priority_key'] === '')
            {
                \redirect($this->queue_redirect_url('invalid'));
            }

            $all_rows = $this->load_ticket_rows($forum_ids);
            $scope_rows = $this->filter_rows($all_rows, $filters);
            $assignee_load = $this->build_assignee_load($all_rows);
            $redistribution = $this->build_redistribution_suggestions($scope_rows, $assignee_load);
            $balanced_plan = $this->build_balanced_redistribution_plan($redistribution, $assignee_load);
            $plan = $this->build_assignee_priority_preview_plan($balanced_plan, $filters['assigned_to'], $filters['priority_key']);

            $updated_count = 0;
            foreach ($plan as $plan_row)
            {
                if ($this->apply_queue_redistribution(
                    isset($plan_row['TOPIC_ID']) ? (int) $plan_row['TOPIC_ID'] : 0,
                    isset($plan_row['FORUM_ID']) ? (int) $plan_row['FORUM_ID'] : 0,
                    isset($plan_row['TARGET_KEY']) ? (string) $plan_row['TARGET_KEY'] : '',
                    (string) $this->user->lang('HELPDESK_AUTO_REASON_REDISTRIBUTION_ASSIGNEE_PRIORITY')
                ))
                {
                    $updated_count++;
                }
            }

            \redirect($this->queue_redirect_url($updated_count > 0 ? 'redistributed_assignee_priority' : 'noop'));
        }


        $items = $this->request->variable('redistribution_items', [0 => ''], true);
        if (!is_array($items))
        {
            $items = [];
        }

        $updated_count = 0;
        foreach ($items as $raw_item)
        {
            $parts = explode('|', (string) $raw_item, 3);
            if (count($parts) !== 3)
            {
                continue;
            }

            $topic_id = (int) $parts[0];
            $forum_id = (int) $parts[1];
            $target_assignee = $this->sanitize_assignee(rawurldecode((string) $parts[2]));

            if ($topic_id <= 0 || $forum_id <= 0 || $target_assignee === '')
            {
                continue;
            }

            if (!in_array((int) $forum_id, array_map('intval', $forum_ids), true) || !$this->can_assign_forum($forum_id))
            {
                continue;
            }

            if ($this->apply_queue_redistribution($topic_id, $forum_id, $target_assignee))
            {
                $updated_count++;
            }
        }

        \redirect($this->queue_redirect_url($updated_count > 0 ? 'redistributed_bulk' : 'noop'));
    }

    protected function apply_queue_redistribution($topic_id, $forum_id, $target_assignee, $reason_text = '')
    {
        $topic_id = (int) $topic_id;
        $forum_id = (int) $forum_id;
        $target_assignee = $this->sanitize_assignee($target_assignee);
        $reason_text = trim((string) $reason_text);

        if ($topic_id <= 0 || $forum_id <= 0 || $target_assignee === '')
        {
            return false;
        }

        $sql = 'SELECT *
            FROM ' . $this->topics_table() . '
            WHERE topic_id = ' . $topic_id . '
                AND forum_id = ' . $forum_id;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (empty($row))
        {
            return false;
        }

        $old_assignee = $this->extract_assigned_to($row);
        if ($old_assignee === $target_assignee)
        {
            return false;
        }

        $sql_ary = [
            'assigned_to' => (string) $target_assignee,
            'assigned_time' => time(),
            'updated_time' => time(),
        ];

        $this->db->sql_query('UPDATE ' . $this->topics_table() . '
            SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
            WHERE topic_id = ' . $topic_id . '
                AND forum_id = ' . $forum_id);

        $log_sql = [
            'log_id' => $this->next_log_id(),
            'topic_id' => $topic_id,
            'forum_id' => $forum_id,
            'user_id' => (int) (isset($this->user->data['user_id']) ? $this->user->data['user_id'] : 0),
            'action_key' => 'assignment_change',
            'old_value' => (string) $old_assignee,
            'new_value' => (string) $target_assignee,
            'log_time' => time(),
        ];
        if ($this->logs_support_reason())
        {
            $log_sql['reason_text'] = ($reason_text !== '') ? $reason_text : (string) $this->user->lang('HELPDESK_AUTO_REASON_REDISTRIBUTION');
        }
        $this->db->sql_query('INSERT INTO ' . $this->logs_table() . ' ' . $this->db->sql_build_array('INSERT', $log_sql));

        return true;
    }

    protected function queue_redirect_url($notice = '')
    {
        $params = [
            'qview' => (string) $this->request->variable('qview', 'queue', true),
            'scope' => (string) $this->request->variable('scope', 'redistribute', true),
            'forum_id' => (int) $this->request->variable('forum_id', 0),
            'status_key' => (string) $this->request->variable('status_key', '', true),
            'department_key' => (string) $this->request->variable('department_key', '', true),
            'priority_key' => (string) $this->request->variable('priority_key', '', true),
            'assigned_to' => (string) $this->sanitize_assignee($this->request->variable('assigned_to', '', true)),
        ];

        if ($notice !== '')
        {
            $params['queue_notice'] = (string) $notice;
        }

        $pairs = [];
        foreach ($params as $key => $value)
        {
            if ($key === 'forum_id' && (int) $value <= 0)
            {
                continue;
            }
            if ($key !== 'forum_id' && (string) $value === '' && !in_array($key, ['qview', 'scope'], true))
            {
                continue;
            }
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return $this->helper->route('mundophpbb_helpdesk_queue_controller') . (!empty($pairs) ? '?' . implode('&', $pairs) : '');
    }


protected function queue_preview_url($preview = '')
{
    $params = [
        'qview' => (string) $this->request->variable('qview', 'queue', true),
        'scope' => (string) $this->request->variable('scope', 'redistribute', true),
        'forum_id' => (int) $this->request->variable('forum_id', 0),
        'status_key' => (string) $this->request->variable('status_key', '', true),
        'department_key' => (string) $this->request->variable('department_key', '', true),
        'priority_key' => (string) $this->request->variable('priority_key', '', true),
        'assigned_to' => (string) $this->sanitize_assignee($this->request->variable('assigned_to', '', true)),
    ];

    if ((string) $preview !== '')
    {
        $params['preview'] = (string) $preview;
    }

    $pairs = [];
    foreach ($params as $key => $value)
    {
        if ($key === 'forum_id' && (int) $value <= 0)
        {
            continue;
        }
        if ($key !== 'forum_id' && (string) $value === '' && !in_array($key, ['qview', 'scope'], true))
        {
            continue;
        }
        $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
    }

    return $this->helper->route('mundophpbb_helpdesk_queue_controller') . (!empty($pairs) ? '?' . implode('&', $pairs) : '');
}

    protected function queue_notice_text()
    {
        $notice = (string) $this->request->variable('queue_notice', '', true);
        switch ($notice)
        {
            case 'redistributed':
                return $this->user->lang('HELPDESK_QUEUE_NOTICE_REDISTRIBUTED');
            case 'redistributed_bulk':
                return $this->user->lang('HELPDESK_QUEUE_NOTICE_REDISTRIBUTED_BULK');
            case 'redistributed_balanced':
                return $this->user->lang('HELPDESK_QUEUE_NOTICE_REDISTRIBUTED_BALANCED');
            case 'redistributed_overload':
                return $this->user->lang('HELPDESK_QUEUE_NOTICE_REDISTRIBUTED_OVERLOAD');
            case 'redistributed_department':
                return $this->user->lang('HELPDESK_QUEUE_NOTICE_REDISTRIBUTED_DEPARTMENT');
            case 'redistributed_critical':
                return $this->user->lang('HELPDESK_QUEUE_NOTICE_REDISTRIBUTED_CRITICAL');
            case 'redistributed_priority_high':
                return $this->user->lang('HELPDESK_QUEUE_NOTICE_REDISTRIBUTED_HIGH');
            case 'redistributed_priority_filtered':
                return $this->user->lang('HELPDESK_QUEUE_NOTICE_REDISTRIBUTED_PRIORITY_FILTERED');
            case 'redistributed_department_priority':
                return $this->user->lang('HELPDESK_QUEUE_NOTICE_REDISTRIBUTED_DEPARTMENT_PRIORITY');
            case 'redistributed_cleanup':
                return $this->user->lang('HELPDESK_QUEUE_NOTICE_REDISTRIBUTED_CLEANUP');
            case 'redistributed_assignee':
                return $this->user->lang('HELPDESK_QUEUE_NOTICE_REDISTRIBUTED_ASSIGNEE');
            case 'redistributed_assignee_priority':
                return $this->user->lang('HELPDESK_QUEUE_NOTICE_REDISTRIBUTED_ASSIGNEE_PRIORITY');
            case 'noop':
                return $this->user->lang('HELPDESK_QUEUE_NOTICE_NOOP');
            case 'missing':
                return $this->user->lang('HELPDESK_QUEUE_NOTICE_MISSING');
            case 'invalid':
                return $this->user->lang('FORM_INVALID');
            default:
                return '';
        }
    }

    protected function can_assign_any_queue(array $forum_ids)
    {
        foreach ($forum_ids as $forum_id)
        {
            if ($this->can_assign_forum($forum_id))
            {
                return true;
            }
        }

        return false;
    }

    protected function can_assign_forum($forum_id)
    {
        $forum_id = (int) $forum_id;
        return $this->auth->acl_get('a_')
            || $this->auth->acl_get('m_', $forum_id)
            || $this->auth->acl_get('m_helpdesk_manage', $forum_id)
            || $this->auth->acl_get('m_helpdesk_assign', $forum_id)
            || $this->auth->acl_get('m_helpdesk_bulk', $forum_id);
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

    protected function build_counts(array $rows)
    {
        $me = isset($this->user->data['username']) ? strtolower((string) $this->user->data['username']) : '';
        $counts = [
            'total' => count($rows),
            'open' => 0,
            'unassigned' => 0,
            'overdue' => 0,
            'stale' => 0,
            'reopened' => 0,
            'staff_reply' => 0,
            'critical' => 0,
            'attention' => 0,
            'priority_high' => 0,
            'priority_critical' => 0,
            'prioritized' => 0,
            'overloaded' => 0,
            'redistribute' => 0,
            'my' => 0,
            'my_overdue' => 0,
            'my_staff_reply' => 0,
            'my_critical' => 0,
            'my_prioritized' => 0,
            'my_alerts' => 0,
        ];

        foreach ($rows as $row)
        {
            if (!empty($row['IS_OPEN']))
            {
                $counts['open']++;
            }
            if (!empty($row['IS_UNASSIGNED']) && !empty($row['IS_OPEN']))
            {
                $counts['unassigned']++;
            }
            if (!empty($row['IS_OVERDUE']))
            {
                $counts['overdue']++;
            }
            if (!empty($row['IS_STALE']))
            {
                $counts['stale']++;
            }
            if (!empty($row['IS_REOPENED']))
            {
                $counts['reopened']++;
            }
            if (!empty($row['IS_STAFF_REPLY']))
            {
                $counts['staff_reply']++;
            }
            if (!empty($row['IS_CRITICAL']))
            {
                $counts['critical']++;
            }
            if (!empty($row['IS_ATTENTION']))
            {
                $counts['attention']++;
            }
            if (!empty($row['IS_WORKLOAD_OVERLOADED']) && !empty($row['IS_OPEN']))
            {
                $counts['overloaded']++;
            }
            if (!empty($row['IS_REDISTRIBUTION_CANDIDATE']))
            {
                $counts['redistribute']++;
            }
            if (isset($row['PRIORITY_TONE']) && in_array((string) $row['PRIORITY_TONE'], ['high', 'critical'], true))
            {
                $counts['priority_high']++;
            }
            if (isset($row['PRIORITY_TONE']) && (string) $row['PRIORITY_TONE'] === 'critical')
            {
                $counts['priority_critical']++;
            }
            if (!empty($row['IS_PRIORITIZED']))
            {
                $counts['prioritized']++;
            }
            if ($me !== '' && strtolower((string) $row['ASSIGNED_TO']) === $me)
            {
                $counts['my']++;
                if (!empty($row['IS_OVERDUE']))
                {
                    $counts['my_overdue']++;
                }
                if (!empty($row['IS_STAFF_REPLY']))
                {
                    $counts['my_staff_reply']++;
                }
                if (!empty($row['IS_CRITICAL']))
                {
                    $counts['my_critical']++;
                }
                if (!empty($row['IS_ASSIGNEE_PRIORITIZED']))
                {
                    $counts['my_prioritized']++;
                }
                if (!empty($row['IS_ASSIGNEE_ALERT']))
                {
                    $counts['my_alerts']++;
                }
            }
        }


        return $counts;
    }

    protected function build_redistribution_suggestions(array $rows, array $assignee_load)
    {
        if (empty($rows) || empty($assignee_load))
        {
            return [];
        }

        $target_pool = [];
        foreach ($assignee_load as $assignee_key => $load_row)
        {
            if (in_array((string) $load_row['WORKLOAD_KEY'], ['idle', 'low', 'medium'], true))
            {
                $target_pool[] = [
                    'KEY' => $assignee_key,
                    'LABEL' => (string) $load_row['LABEL'],
                    'WORKLOAD_KEY' => (string) $load_row['WORKLOAD_KEY'],
                    'WORKLOAD_LABEL' => (string) $load_row['WORKLOAD_LABEL'],
                    'WORKLOAD_CLASS' => (string) $load_row['WORKLOAD_CLASS'],
                    'SCORE' => (int) $load_row['SCORE'],
                ];
            }
        }

        usort($target_pool, function ($a, $b) {
            if ((int) $a['SCORE'] === (int) $b['SCORE'])
            {
                return strcasecmp((string) $a['LABEL'], (string) $b['LABEL']);
            }

            return ((int) $a['SCORE'] < (int) $b['SCORE']) ? -1 : 1;
        });

        if (empty($target_pool))
        {
            return [];
        }

        $rows_by_assignee = [];
        foreach ($rows as $row)
        {
            $assignee_key = isset($row['ASSIGNED_TO']) ? strtolower((string) $row['ASSIGNED_TO']) : '';
            if ($assignee_key === '' || empty($row['IS_REDISTRIBUTION_CANDIDATE']))
            {
                continue;
            }

            $rows_by_assignee[$assignee_key][] = $row;
        }

        $suggestions = [];
        $target_index = 0;
        foreach ($assignee_load as $source_key => $load_row)
        {
            if (!in_array((string) $load_row['WORKLOAD_KEY'], ['high', 'overload'], true))
            {
                continue;
            }

            if (empty($rows_by_assignee[$source_key]))
            {
                continue;
            }

            usort($rows_by_assignee[$source_key], function ($a, $b) {
                $rankA = $this->redistribution_rank($a);
                $rankB = $this->redistribution_rank($b);
                if ($rankA !== $rankB)
                {
                    return ($rankA < $rankB) ? -1 : 1;
                }

                $updatedA = !empty($a['UPDATED_TS']) ? (int) $a['UPDATED_TS'] : 0;
                $updatedB = !empty($b['UPDATED_TS']) ? (int) $b['UPDATED_TS'] : 0;
                if ($updatedA !== $updatedB)
                {
                    return ($updatedA < $updatedB) ? -1 : 1;
                }

                return ((int) $a['TOPIC_ID'] < (int) $b['TOPIC_ID']) ? -1 : 1;
            });

            $limit = ((string) $load_row['WORKLOAD_KEY'] === 'overload') ? 2 : 1;
            $added = 0;

            foreach ($rows_by_assignee[$source_key] as $ticket_row)
            {
                $target = null;
                $pool_count = count($target_pool);
                for ($attempt = 0; $attempt < $pool_count; $attempt++)
                {
                    $candidate = $target_pool[$target_index % $pool_count];
                    $target_index++;
                    if ((string) $candidate['KEY'] !== (string) $source_key)
                    {
                        $target = $candidate;
                        break;
                    }
                }

                if (empty($target))
                {
                    break;
                }

                $redistribution_rank = $this->redistribution_rank($ticket_row);
                $is_smart_pick = $this->is_smart_redistribution_pick($ticket_row, $target);
$suggestions[] = [
    'TOPIC_ID' => (int) $ticket_row['TOPIC_ID'],
    'FORUM_ID' => isset($ticket_row['FORUM_ID']) ? (int) $ticket_row['FORUM_ID'] : 0,
    'TOPIC_TITLE' => (string) $ticket_row['TOPIC_TITLE'],
    'U_TOPIC' => (string) $ticket_row['U_TOPIC'],
    'SOURCE_KEY' => (string) $source_key,
    'SOURCE_LABEL' => (string) $load_row['LABEL'],
    'SOURCE_WORKLOAD_LABEL' => (string) $load_row['WORKLOAD_LABEL'],
    'SOURCE_WORKLOAD_CLASS' => (string) $load_row['WORKLOAD_CLASS'],
    'SOURCE_SCORE' => (int) $load_row['SCORE'],
    'TARGET_KEY' => (string) $target['KEY'],
    'TARGET_LABEL' => (string) $target['LABEL'],
    'TARGET_WORKLOAD_LABEL' => (string) $target['WORKLOAD_LABEL'],
    'U_TARGET_QUEUE' => $this->helper->route('mundophpbb_helpdesk_queue_controller') . '?qview=queue&assigned_to=' . rawurlencode((string) $target['KEY']),
    'TARGET_WORKLOAD_CLASS' => (string) $target['WORKLOAD_CLASS'],
    'TARGET_SCORE' => (int) $target['SCORE'],
    'PRIORITY_LABEL' => isset($ticket_row['PRIORITY_LABEL']) ? (string) $ticket_row['PRIORITY_LABEL'] : '',
    'PRIORITY_CLASS' => isset($ticket_row['PRIORITY_CLASS']) ? (string) $ticket_row['PRIORITY_CLASS'] : '',
    'PRIORITY_TONE' => isset($ticket_row['PRIORITY_TONE']) ? (string) $ticket_row['PRIORITY_TONE'] : 'normal',
    'DEPARTMENT_LABEL' => isset($ticket_row['DEPARTMENT_LABEL']) ? (string) $ticket_row['DEPARTMENT_LABEL'] : '',
    'UPDATED_AT' => isset($ticket_row['UPDATED_AT']) ? (string) $ticket_row['UPDATED_AT'] : '',
    'SUGGESTION_TEXT' => sprintf($this->user->lang('HELPDESK_REDISTRIBUTION_REASON_TEXT'), isset($ticket_row['PRIORITY_LABEL']) ? (string) $ticket_row['PRIORITY_LABEL'] : '', isset($ticket_row['UPDATED_AT']) ? (string) $ticket_row['UPDATED_AT'] : ''),
    'SELECTION_VALUE' => (int) $ticket_row['TOPIC_ID'] . '|' . (isset($ticket_row['FORUM_ID']) ? (int) $ticket_row['FORUM_ID'] : 0) . '|' . rawurlencode((string) $target['KEY']),
    'REDISTRIBUTION_RANK' => $redistribution_rank,
    'CANDIDATE_KEY' => (int) $ticket_row['TOPIC_ID'] . '|' . (isset($ticket_row['FORUM_ID']) ? (int) $ticket_row['FORUM_ID'] : 0) . '|' . (string) $target['KEY'],
    'S_SMART_PICK' => $is_smart_pick,
    'SMART_REASON_TEXT' => $is_smart_pick ? $this->smart_redistribution_reason($ticket_row, $target) : '',
    'S_BALANCED_PICK' => false,
    'BALANCED_REASON_TEXT' => '',
];


                $added++;
                if ($added >= $limit)
                {
                    break;
                }
            }
        }

        return $suggestions;
    }


protected function count_smart_redistribution(array $suggestions)
{
    $count = 0;
    foreach ($suggestions as $suggestion)
    {
        if (!empty($suggestion['S_SMART_PICK']))
        {
            $count++;
        }
    }

    return $count;
}


protected function count_balanced_redistribution(array $suggestions)
{
    $count = 0;
    foreach ($suggestions as $suggestion)
    {
        if (!empty($suggestion['S_BALANCED_PICK']))
        {
            $count++;
        }
    }

    return $count;
}

protected function build_balanced_redistribution_plan(array $suggestions, array $assignee_load)
{
    if (empty($suggestions) || empty($assignee_load))
    {
        return [];
    }

    $source_limits = [];
    $target_capacity = [];
    foreach ($assignee_load as $assignee_key => $load_row)
    {
        $workload_key = isset($load_row['WORKLOAD_KEY']) ? (string) $load_row['WORKLOAD_KEY'] : '';
        $score = isset($load_row['SCORE']) ? (int) $load_row['SCORE'] : 0;

        if ($workload_key === 'overload')
        {
            $source_limits[$assignee_key] = max(2, min(4, (int) ceil(max(0, $score - 12) / 4) + 1));
        }
        else if ($workload_key === 'high')
        {
            $source_limits[$assignee_key] = 1;
        }

        $capacity = 0;
        if ($workload_key === 'idle')
        {
            $capacity = 2;
        }
        else if ($workload_key === 'low')
        {
            $capacity = 1;
        }
        else if ($workload_key === 'medium' && $score <= 6)
        {
            $capacity = 1;
        }

        if ($capacity > 0)
        {
            $target_capacity[$assignee_key] = $capacity;
        }
    }

    if (empty($source_limits) || empty($target_capacity))
    {
        return [];
    }

    usort($suggestions, function ($a, $b) {
        $smartA = !empty($a['S_SMART_PICK']) ? 1 : 0;
        $smartB = !empty($b['S_SMART_PICK']) ? 1 : 0;
        if ($smartA !== $smartB)
        {
            return ($smartA > $smartB) ? -1 : 1;
        }

        $sourceScoreA = isset($a['SOURCE_SCORE']) ? (int) $a['SOURCE_SCORE'] : 0;
        $sourceScoreB = isset($b['SOURCE_SCORE']) ? (int) $b['SOURCE_SCORE'] : 0;
        if ($sourceScoreA !== $sourceScoreB)
        {
            return ($sourceScoreA > $sourceScoreB) ? -1 : 1;
        }

        $targetScoreA = isset($a['TARGET_SCORE']) ? (int) $a['TARGET_SCORE'] : 0;
        $targetScoreB = isset($b['TARGET_SCORE']) ? (int) $b['TARGET_SCORE'] : 0;
        if ($targetScoreA !== $targetScoreB)
        {
            return ($targetScoreA < $targetScoreB) ? -1 : 1;
        }

        $rankA = isset($a['REDISTRIBUTION_RANK']) ? (int) $a['REDISTRIBUTION_RANK'] : 99;
        $rankB = isset($b['REDISTRIBUTION_RANK']) ? (int) $b['REDISTRIBUTION_RANK'] : 99;
        if ($rankA !== $rankB)
        {
            return ($rankA < $rankB) ? -1 : 1;
        }

        $updatedA = isset($a['UPDATED_AT']) ? strtotime((string) $a['UPDATED_AT']) : 0;
        $updatedB = isset($b['UPDATED_AT']) ? strtotime((string) $b['UPDATED_AT']) : 0;
        if ($updatedA !== $updatedB)
        {
            return ($updatedA < $updatedB) ? -1 : 1;
        }

        return ((int) $a['TOPIC_ID'] < (int) $b['TOPIC_ID']) ? -1 : 1;
    });

    $plan = [];
    $seen_topics = [];

    foreach ([true, false] as $smart_only)
    {
        foreach ($suggestions as $suggestion)
        {
            $source_key = isset($suggestion['SOURCE_KEY']) ? (string) $suggestion['SOURCE_KEY'] : '';
            $target_key = isset($suggestion['TARGET_KEY']) ? (string) $suggestion['TARGET_KEY'] : '';
            $topic_id = isset($suggestion['TOPIC_ID']) ? (int) $suggestion['TOPIC_ID'] : 0;
            $rank = isset($suggestion['REDISTRIBUTION_RANK']) ? (int) $suggestion['REDISTRIBUTION_RANK'] : 99;
            $is_smart = !empty($suggestion['S_SMART_PICK']);

            if ($source_key === '' || $target_key === '' || $topic_id <= 0)
            {
                continue;
            }

            if ($smart_only && !$is_smart)
            {
                continue;
            }

            if (!$smart_only)
            {
                if ($is_smart || $rank > 1)
                {
                    continue;
                }
            }

            if (empty($source_limits[$source_key]) || empty($target_capacity[$target_key]) || isset($seen_topics[$topic_id]))
            {
                continue;
            }

            $plan[] = $suggestion;
            $seen_topics[$topic_id] = true;
            $source_limits[$source_key]--;
            $target_capacity[$target_key]--;

            if ($source_limits[$source_key] <= 0)
            {
                unset($source_limits[$source_key]);
            }
            if ($target_capacity[$target_key] <= 0)
            {
                unset($target_capacity[$target_key]);
            }

            if (empty($source_limits) || empty($target_capacity))
            {
                break 2;
            }
        }
    }

    return $plan;
}


protected function build_overload_preview_plan(array $plan, array $assignee_load)
{
    if (empty($plan))
    {
        return [];
    }

    $filtered = [];
    $per_source = [];

    foreach ($plan as $row)
    {
        $source_key = isset($row['SOURCE_KEY']) ? strtolower((string) $row['SOURCE_KEY']) : '';
        if ($source_key === '' || !isset($assignee_load[$source_key]))
        {
            continue;
        }

        $source_row = $assignee_load[$source_key];
        $source_class = isset($source_row['WORKLOAD_CLASS']) ? (string) $source_row['WORKLOAD_CLASS'] : 'helpdesk-workload-idle';
        if (!in_array($source_class, ['helpdesk-workload-high', 'helpdesk-workload-overload'], true))
        {
            continue;
        }

        if (!isset($per_source[$source_key]))
        {
            $per_source[$source_key] = 0;
        }

        if ($per_source[$source_key] >= 3)
        {
            continue;
        }

        $filtered[] = $row;
        $per_source[$source_key]++;

        if (count($filtered) >= 30)
        {
            break;
        }
    }

    if (!empty($filtered))
    {
        return array_values($filtered);
    }

    return array_slice(array_values($plan), 0, min(12, count($plan)));
}

protected function build_critical_preview_plan(array $plan)
{
    if (empty($plan))
    {
        return [];
    }

    $filtered = [];
    foreach ($plan as $row)
    {
        $priority_tone = isset($row['PRIORITY_TONE']) ? (string) $row['PRIORITY_TONE'] : 'normal';
        if ($priority_tone !== 'critical')
        {
            continue;
        }

        $filtered[] = $row;
    }

    return array_values($filtered);
}

protected function build_priority_high_preview_plan(array $plan)
{
    if (empty($plan))
    {
        return [];
    }

    $filtered = [];
    foreach ($plan as $row)
    {
        $priority_tone = isset($row['PRIORITY_TONE']) ? (string) $row['PRIORITY_TONE'] : 'normal';
        if (!in_array($priority_tone, ['high', 'critical'], true))
        {
            continue;
        }

        $filtered[] = $row;
    }

    return array_values($filtered);
}

protected function build_filtered_priority_preview_plan(array $plan, $priority_key)
{
    $priority_key = trim((string) $priority_key);
    if (empty($plan) || $priority_key === '')
    {
        return [];
    }

    $priority_key = $this->normalize_priority($priority_key);
    $filtered = [];
    foreach ($plan as $row)
    {
        $row_priority = isset($row['PRIORITY_KEY']) ? $this->normalize_priority((string) $row['PRIORITY_KEY']) : '';
        if ($row_priority !== $priority_key)
        {
            continue;
        }

        $row['S_PRIORITY_FILTERED_PICK'] = true;
        $filtered[] = $row;
    }

    return array_values($filtered);
}


protected function build_department_priority_preview_plan(array $plan, $department_key, $priority_key)
{
    $department_key = $this->normalize_option_key($department_key);
    $priority_key = $this->normalize_priority($priority_key);

    if (empty($plan) || $department_key === '' || $priority_key === '')
    {
        return [];
    }

    $filtered = [];
    foreach ($plan as $row)
    {
        $row_department = isset($row['DEPARTMENT_KEY']) ? $this->normalize_option_key((string) $row['DEPARTMENT_KEY']) : '';
        $row_priority = isset($row['PRIORITY_KEY']) ? $this->normalize_priority((string) $row['PRIORITY_KEY']) : '';

        if ($row_department !== $department_key || $row_priority !== $priority_key)
        {
            continue;
        }

        $row['S_DEPARTMENT_PRIORITY_PICK'] = true;
        $filtered[] = $row;
    }

    return array_values($filtered);
}

protected function build_cleanup_preview_plan(array $plan)
{
    if (empty($plan))
    {
        return [];
    }

    $filtered = [];
    foreach ($plan as $row)
    {
        $priority_tone = isset($row['PRIORITY_TONE']) ? (string) $row['PRIORITY_TONE'] : 'normal';
        if (!in_array($priority_tone, ['low', 'normal'], true))
        {
            continue;
        }

        $row['S_CLEANUP_PICK'] = true;
        $filtered[] = $row;
    }

    return array_values($filtered);
}

protected function build_assignee_preview_plan(array $plan, $assignee_key)
{
    $assignee_key = $this->sanitize_assignee($assignee_key);
    if (empty($plan) || $assignee_key === '')
    {
        return [];
    }

    $filtered = [];
    foreach ($plan as $row)
    {
        $source_key = isset($row['SOURCE_KEY']) ? $this->sanitize_assignee((string) $row['SOURCE_KEY']) : '';
        $target_key = isset($row['TARGET_KEY']) ? $this->sanitize_assignee((string) $row['TARGET_KEY']) : '';

        if ($source_key !== $assignee_key && $target_key !== $assignee_key)
        {
            continue;
        }

        $row['S_ASSIGNEE_SOURCE'] = ($source_key === $assignee_key);
        $row['S_ASSIGNEE_TARGET'] = ($target_key === $assignee_key);
        $filtered[] = $row;
    }

    return array_values($filtered);
}

protected function build_preview_department_rows(array $plan)
{
    if (empty($plan))
    {
        return [];
    }

    $groups = [];
    foreach ($plan as $row)
    {
        $label = !empty($row['DEPARTMENT_LABEL']) ? (string) $row['DEPARTMENT_LABEL'] : $this->user->lang('HELPDESK_REPORT_UNSET_LABEL');
        $group_key = strtolower(trim($label));
        if ($group_key === '')
        {
            $group_key = '__unset__';
        }

        if (!isset($groups[$group_key]))
        {
            $groups[$group_key] = [
                'LABEL' => $label,
                'MOVE_COUNT' => 0,
                'SMART_COUNT' => 0,
                'HIGH_COUNT' => 0,
                'CRITICAL_COUNT' => 0,
            ];
        }

        $groups[$group_key]['MOVE_COUNT']++;
        if (!empty($row['S_SMART_PICK']))
        {
            $groups[$group_key]['SMART_COUNT']++;
        }

        $priority_tone = isset($row['PRIORITY_TONE']) ? (string) $row['PRIORITY_TONE'] : 'normal';
        if (in_array($priority_tone, ['high', 'critical'], true))
        {
            $groups[$group_key]['HIGH_COUNT']++;
        }
        if ($priority_tone === 'critical')
        {
            $groups[$group_key]['CRITICAL_COUNT']++;
        }
    }

    $groups = array_values($groups);
    usort($groups, function ($a, $b) {
        if ((int) $a['MOVE_COUNT'] === (int) $b['MOVE_COUNT'])
        {
            if ((int) $a['CRITICAL_COUNT'] === (int) $b['CRITICAL_COUNT'])
            {
                return strcasecmp((string) $a['LABEL'], (string) $b['LABEL']);
            }

            return ((int) $a['CRITICAL_COUNT'] > (int) $b['CRITICAL_COUNT']) ? -1 : 1;
        }

        return ((int) $a['MOVE_COUNT'] > (int) $b['MOVE_COUNT']) ? -1 : 1;
    });

    $rows = [];
    foreach ($groups as $group)
    {
        $meta = [];
        if ((int) $group['SMART_COUNT'] > 0)
        {
            $meta[] = $group['SMART_COUNT'] . ' ' . $this->user->lang('HELPDESK_REDISTRIBUTION_SMART_BADGE');
        }
        if ((int) $group['HIGH_COUNT'] > 0)
        {
            $meta[] = $group['HIGH_COUNT'] . ' ' . $this->user->lang('HELPDESK_PRIORITY_HIGH');
        }
        if ((int) $group['CRITICAL_COUNT'] > 0)
        {
            $meta[] = $group['CRITICAL_COUNT'] . ' ' . $this->user->lang('HELPDESK_PRIORITY_CRITICAL');
        }

        $rows[] = [
            'LABEL' => (string) $group['LABEL'],
            'MOVE_COUNT' => (int) $group['MOVE_COUNT'],
            'SMART_COUNT' => (int) $group['SMART_COUNT'],
            'HIGH_COUNT' => (int) $group['HIGH_COUNT'],
            'CRITICAL_COUNT' => (int) $group['CRITICAL_COUNT'],
            'META_TEXT' => !empty($meta) ? implode(' • ', $meta) : $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_DEPARTMENT_META_EMPTY'),
            'BADGE_LABEL' => sprintf($this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_MOVES_TEXT'), (int) $group['MOVE_COUNT']),
            'BADGE_CLASS' => ((int) $group['CRITICAL_COUNT'] > 0) ? 'helpdesk-preview-delta-up' : (((int) $group['HIGH_COUNT'] > 0) ? 'helpdesk-preview-delta-stable' : 'helpdesk-preview-delta-down'),
        ];
    }

    return $rows;
}

protected function preview_title($preview_mode)
{
    if ((string) $preview_mode === 'overload')
    {
        return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_OVERLOAD_TITLE');
    }

    if ((string) $preview_mode === 'critical')
    {
        return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_CRITICAL_TITLE');
    }

    if ((string) $preview_mode === 'priority_high')
    {
        return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_HIGH_TITLE');
    }

    if ((string) $preview_mode === 'priority_filtered')
    {
        $priority_key = trim((string) $this->request->variable('priority_key', '', true));
        if ($priority_key !== '')
        {
            $priority_meta = $this->priority_meta($priority_key);
            return sprintf($this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_PRIORITY_FILTERED_TITLE'), $priority_meta['label']);
        }

        return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_TITLE');
    }

    if ((string) $preview_mode === 'department_priority')
    {
        $department_key = $this->normalize_option_key($this->request->variable('department_key', '', true));
        $priority_key = trim((string) $this->request->variable('priority_key', '', true));
        if ($department_key !== '' && $priority_key !== '')
        {
            $department_label = $this->resolve_option_label($department_key, $this->department_options(), $department_key);
            $priority_meta = $this->priority_meta($priority_key);
            return sprintf($this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_DEPARTMENT_PRIORITY_TITLE'), $department_label, $priority_meta['label']);
        }

        return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_TITLE');
    }

    if ((string) $preview_mode === 'cleanup')
    {
        return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_CLEANUP_TITLE');
    }

    if ((string) $preview_mode === 'assignee')
    {
        $assignee = $this->sanitize_assignee($this->request->variable('assigned_to', '', true));
        if ($assignee !== '')
        {
            return sprintf($this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_ASSIGNEE_TITLE'), $assignee);
        }

        return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_TITLE');
    }

    if ((string) $preview_mode === 'assignee_priority')
    {
        $assignee = $this->sanitize_assignee($this->request->variable('assigned_to', '', true));
        $priority_key = trim((string) $this->request->variable('priority_key', '', true));
        if ($assignee !== '' && $priority_key !== '')
        {
            $priority_meta = $this->priority_meta($priority_key);
            return sprintf($this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_ASSIGNEE_PRIORITY_TITLE'), $assignee, $priority_meta['label']);
        }

        return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_TITLE');
    }

    return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_TITLE');
}

protected function preview_explain($preview_mode)
{
    if ((string) $preview_mode === 'overload')
    {
        return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_OVERLOAD_EXPLAIN');
    }

    if ((string) $preview_mode === 'critical')
    {
        return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_CRITICAL_EXPLAIN');
    }

    if ((string) $preview_mode === 'priority_high')
    {
        return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_HIGH_EXPLAIN');
    }

    if ((string) $preview_mode === 'priority_filtered')
    {
        $priority_key = trim((string) $this->request->variable('priority_key', '', true));
        if ($priority_key !== '')
        {
            $priority_meta = $this->priority_meta($priority_key);
            return sprintf($this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_PRIORITY_FILTERED_EXPLAIN'), $priority_meta['label']);
        }

        return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_EXPLAIN');
    }

    if ((string) $preview_mode === 'department_priority')
    {
        $department_key = $this->normalize_option_key($this->request->variable('department_key', '', true));
        $priority_key = trim((string) $this->request->variable('priority_key', '', true));
        if ($department_key !== '' && $priority_key !== '')
        {
            $department_label = $this->resolve_option_label($department_key, $this->department_options(), $department_key);
            $priority_meta = $this->priority_meta($priority_key);
            return sprintf($this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_DEPARTMENT_PRIORITY_EXPLAIN'), $department_label, $priority_meta['label']);
        }

        return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_EXPLAIN');
    }

    if ((string) $preview_mode === 'cleanup')
    {
        return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_CLEANUP_EXPLAIN');
    }

    if ((string) $preview_mode === 'assignee')
    {
        $assignee = $this->sanitize_assignee($this->request->variable('assigned_to', '', true));
        if ($assignee !== '')
        {
            return sprintf($this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_ASSIGNEE_EXPLAIN'), $assignee);
        }

        return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_EXPLAIN');
    }

    if ((string) $preview_mode === 'assignee_priority')
    {
        $assignee = $this->sanitize_assignee($this->request->variable('assigned_to', '', true));
        $priority_key = trim((string) $this->request->variable('priority_key', '', true));
        if ($assignee !== '' && $priority_key !== '')
        {
            $priority_meta = $this->priority_meta($priority_key);
            return sprintf($this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_ASSIGNEE_PRIORITY_EXPLAIN'), $assignee, $priority_meta['label']);
        }

        return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_EXPLAIN');
    }

    return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_EXPLAIN');
}

protected function build_preview_group_rows(array $impact_rows)
{
    if (empty($impact_rows))
    {
        return [];
    }

    $group_labels = [
        'helpdesk-workload-overload' => $this->user->lang('HELPDESK_WORKLOAD_OVERLOAD'),
        'helpdesk-workload-high' => $this->user->lang('HELPDESK_WORKLOAD_HIGH'),
        'helpdesk-workload-medium' => $this->user->lang('HELPDESK_WORKLOAD_MEDIUM'),
        'helpdesk-workload-low' => $this->user->lang('HELPDESK_WORKLOAD_LOW'),
        'helpdesk-workload-idle' => $this->user->lang('HELPDESK_WORKLOAD_IDLE'),
    ];

    $groups = [];
    foreach ($impact_rows as $row)
    {
        $group_key = isset($row['WORKLOAD_BEFORE_CLASS']) ? (string) $row['WORKLOAD_BEFORE_CLASS'] : 'helpdesk-workload-idle';
        if (!isset($group_labels[$group_key]))
        {
            $group_key = 'helpdesk-workload-idle';
        }

        if (!isset($groups[$group_key]))
        {
            $groups[$group_key] = [
                'GROUP_KEY' => $group_key,
                'LABEL' => $group_labels[$group_key],
                'ASSIGNEE_COUNT' => 0,
                'SCORE_BEFORE' => 0,
                'SCORE_AFTER' => 0,
                'MOVE_OUT_COUNT' => 0,
                'MOVE_IN_COUNT' => 0,
            ];
        }

        $groups[$group_key]['ASSIGNEE_COUNT']++;
        $groups[$group_key]['SCORE_BEFORE'] += isset($row['SCORE_BEFORE']) ? (int) $row['SCORE_BEFORE'] : 0;
        $groups[$group_key]['SCORE_AFTER'] += isset($row['SCORE_AFTER']) ? (int) $row['SCORE_AFTER'] : 0;
        $groups[$group_key]['MOVE_OUT_COUNT'] += isset($row['MOVE_OUT_COUNT']) ? (int) $row['MOVE_OUT_COUNT'] : 0;
        $groups[$group_key]['MOVE_IN_COUNT'] += isset($row['MOVE_IN_COUNT']) ? (int) $row['MOVE_IN_COUNT'] : 0;
    }

    $rows = [];
    foreach ($groups as $group_key => $group)
    {
        $delta = (int) $group['SCORE_AFTER'] - (int) $group['SCORE_BEFORE'];
        $rows[] = [
            'LABEL' => (string) $group['LABEL'],
            'WORKLOAD_CLASS' => (string) $group_key,
            'ASSIGNEE_COUNT' => (int) $group['ASSIGNEE_COUNT'],
            'SCORE_BEFORE' => (int) $group['SCORE_BEFORE'],
            'SCORE_AFTER' => (int) $group['SCORE_AFTER'],
            'MOVE_OUT_COUNT' => (int) $group['MOVE_OUT_COUNT'],
            'MOVE_IN_COUNT' => (int) $group['MOVE_IN_COUNT'],
            'DELTA_TEXT' => $this->signed_number_text($delta),
            'DELTA_CLASS' => $this->preview_delta_class($delta),
        ];
    }

    usort($rows, function ($a, $b) {
        $deltaA = abs((int) $a['SCORE_AFTER'] - (int) $a['SCORE_BEFORE']);
        $deltaB = abs((int) $b['SCORE_AFTER'] - (int) $b['SCORE_BEFORE']);
        if ($deltaA !== $deltaB)
        {
            return ($deltaA > $deltaB) ? -1 : 1;
        }

        $movesA = (int) $a['MOVE_OUT_COUNT'] + (int) $a['MOVE_IN_COUNT'];
        $movesB = (int) $b['MOVE_OUT_COUNT'] + (int) $b['MOVE_IN_COUNT'];
        if ($movesA !== $movesB)
        {
            return ($movesA > $movesB) ? -1 : 1;
        }

        return strcasecmp((string) $a['LABEL'], (string) $b['LABEL']);
    });

    return $rows;
}


protected function build_balanced_preview_impact(array $plan, array $assignee_load)
{
    if (empty($plan))
    {
        return [];
    }

    $impact = [];
    foreach ($plan as $row)
    {
        $source_key = isset($row['SOURCE_KEY']) ? (string) $row['SOURCE_KEY'] : '';
        $target_key = isset($row['TARGET_KEY']) ? (string) $row['TARGET_KEY'] : '';

        if ($source_key !== '')
        {
            if (!isset($impact[$source_key]))
            {
                $impact[$source_key] = $this->preview_impact_base_row($source_key, $assignee_load, 'source');
            }
            else if ($impact[$source_key]['ROLE'] === 'target')
            {
                $impact[$source_key]['ROLE'] = 'both';
            }
            $impact[$source_key]['MOVE_OUT_COUNT']++;
        }

        if ($target_key !== '')
        {
            if (!isset($impact[$target_key]))
            {
                $impact[$target_key] = $this->preview_impact_base_row($target_key, $assignee_load, 'target');
            }
            else if ($impact[$target_key]['ROLE'] === 'source')
            {
                $impact[$target_key]['ROLE'] = 'both';
            }
            $impact[$target_key]['MOVE_IN_COUNT']++;
        }
    }

    $impact_order = 1;
    foreach ($impact as $key => $row)
    {
        $before_count = isset($row['ACTIVE_BEFORE']) ? (int) $row['ACTIVE_BEFORE'] : 0;
        $after_count = max(0, $before_count - (int) $row['MOVE_OUT_COUNT'] + (int) $row['MOVE_IN_COUNT']);
        $score_before = isset($row['SCORE_BEFORE']) ? (int) $row['SCORE_BEFORE'] : 0;
        $score_after = max(0, $score_before - (int) $row['MOVE_OUT_COUNT'] + (int) $row['MOVE_IN_COUNT']);
        $meta_after = $this->workload_meta($score_after);
        $direction = 'neutral';
        if ((int) $row['MOVE_OUT_COUNT'] > (int) $row['MOVE_IN_COUNT'])
        {
            $direction = 'down';
        }
        else if ((int) $row['MOVE_IN_COUNT'] > (int) $row['MOVE_OUT_COUNT'])
        {
            $direction = 'up';
        }

        $impact[$key]['ACTIVE_AFTER'] = $after_count;
        $impact[$key]['SCORE_AFTER'] = $score_after;
        $impact[$key]['WORKLOAD_AFTER_LABEL'] = isset($meta_after['label']) ? (string) $meta_after['label'] : '';
        $impact[$key]['WORKLOAD_AFTER_CLASS'] = isset($meta_after['class']) ? (string) $meta_after['class'] : '';
        $impact[$key]['S_SOURCE'] = ($row['ROLE'] === 'source');
        $impact[$key]['S_TARGET'] = ($row['ROLE'] === 'target');
        $impact[$key]['S_BOTH'] = ($row['ROLE'] === 'both');
        $impact[$key]['IMPACT_DIRECTION'] = $direction;
        $impact[$key]['IMPACT_LABEL'] = $this->preview_impact_label($direction);
    }

    uasort($impact, function ($a, $b) {
        $scoreDeltaA = abs(((int) $a['MOVE_IN_COUNT']) - ((int) $a['MOVE_OUT_COUNT']));
        $scoreDeltaB = abs(((int) $b['MOVE_IN_COUNT']) - ((int) $b['MOVE_OUT_COUNT']));
        if ($scoreDeltaA !== $scoreDeltaB)
        {
            return ($scoreDeltaA > $scoreDeltaB) ? -1 : 1;
        }

        $movementA = ((int) $a['MOVE_OUT_COUNT'] + (int) $a['MOVE_IN_COUNT']);
        $movementB = ((int) $b['MOVE_OUT_COUNT'] + (int) $b['MOVE_IN_COUNT']);
        if ($movementA !== $movementB)
        {
            return ($movementA > $movementB) ? -1 : 1;
        }

        $scoreA = isset($a['SCORE_BEFORE']) ? (int) $a['SCORE_BEFORE'] : 0;
        $scoreB = isset($b['SCORE_BEFORE']) ? (int) $b['SCORE_BEFORE'] : 0;
        if ($scoreA !== $scoreB)
        {
            return ($scoreA > $scoreB) ? -1 : 1;
        }

        return strcasecmp((string) $a['LABEL'], (string) $b['LABEL']);
    });

    $max_score = 1;
    foreach ($impact as $row)
    {
        $max_score = max($max_score, (int) $row['SCORE_BEFORE'], (int) $row['SCORE_AFTER']);
    }

    $impact_order = 1;
    foreach ($impact as $key => $row)
    {
        $score_before = isset($row['SCORE_BEFORE']) ? (int) $row['SCORE_BEFORE'] : 0;
        $score_after = isset($row['SCORE_AFTER']) ? (int) $row['SCORE_AFTER'] : 0;
        $active_before = isset($row['ACTIVE_BEFORE']) ? (int) $row['ACTIVE_BEFORE'] : 0;
        $active_after = isset($row['ACTIVE_AFTER']) ? (int) $row['ACTIVE_AFTER'] : 0;
        $score_delta = $score_after - $score_before;
        $active_delta = $active_after - $active_before;

        $impact[$key]['SCORE_DELTA'] = $score_delta;
        $impact[$key]['SCORE_DELTA_TEXT'] = $this->signed_number_text($score_delta);
        $impact[$key]['ACTIVE_DELTA'] = $active_delta;
        $impact[$key]['ACTIVE_DELTA_TEXT'] = $this->signed_number_text($active_delta);
        $impact[$key]['SCORE_BEFORE_WIDTH'] = max(8, (int) round(($score_before / $max_score) * 100));
        $impact[$key]['SCORE_AFTER_WIDTH'] = max(8, (int) round(($score_after / $max_score) * 100));
        $impact[$key]['DELTA_CLASS'] = $this->preview_delta_class($score_delta);
        $impact[$key]['DELTA_LABEL'] = $this->preview_delta_label($score_delta);
        $impact[$key]['IMPACT_ORDER'] = $impact_order;
        $impact_order++;
    }

    return array_values($impact);
}

protected function build_preview_top_impact_rows(array $impact_rows)
{
    if (empty($impact_rows))
    {
        return [];
    }

    $best_relief = null;
    $highest_load = null;
    $most_out = null;
    $most_in = null;

    foreach ($impact_rows as $row)
    {
        $score_delta = isset($row['SCORE_DELTA']) ? (int) $row['SCORE_DELTA'] : 0;
        $move_out = isset($row['MOVE_OUT_COUNT']) ? (int) $row['MOVE_OUT_COUNT'] : 0;
        $move_in = isset($row['MOVE_IN_COUNT']) ? (int) $row['MOVE_IN_COUNT'] : 0;

        if ($score_delta < 0 && ($best_relief === null || $score_delta < (int) $best_relief['SCORE_DELTA']))
        {
            $best_relief = $row;
        }
        if ($score_delta > 0 && ($highest_load === null || $score_delta > (int) $highest_load['SCORE_DELTA']))
        {
            $highest_load = $row;
        }
        if ($move_out > 0 && ($most_out === null || $move_out > (int) $most_out['MOVE_OUT_COUNT']))
        {
            $most_out = $row;
        }
        if ($move_in > 0 && ($most_in === null || $move_in > (int) $most_in['MOVE_IN_COUNT']))
        {
            $most_in = $row;
        }
    }

    $rows = [];
    if ($best_relief !== null)
    {
        $rows[] = [
            'LABEL' => $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_TOP_RELIEF'),
            'VALUE_TEXT' => (string) $best_relief['LABEL'],
            'META_TEXT' => $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_DELTA') . ': ' . (string) $best_relief['SCORE_DELTA_TEXT'],
            'BADGE_LABEL' => (string) $best_relief['WORKLOAD_AFTER_LABEL'],
            'BADGE_CLASS' => 'helpdesk-preview-delta-down',
        ];
    }
    if ($highest_load !== null)
    {
        $rows[] = [
            'LABEL' => $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_TOP_RECEIVING'),
            'VALUE_TEXT' => (string) $highest_load['LABEL'],
            'META_TEXT' => $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_DELTA') . ': ' . (string) $highest_load['SCORE_DELTA_TEXT'],
            'BADGE_LABEL' => (string) $highest_load['WORKLOAD_AFTER_LABEL'],
            'BADGE_CLASS' => 'helpdesk-preview-delta-up',
        ];
    }
    if ($most_out !== null)
    {
        $rows[] = [
            'LABEL' => $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_TOP_MOVE_OUT'),
            'VALUE_TEXT' => (string) $most_out['LABEL'],
            'META_TEXT' => $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_OUT') . ': ' . (int) $most_out['MOVE_OUT_COUNT'],
            'BADGE_LABEL' => (string) $most_out['WORKLOAD_BEFORE_LABEL'],
            'BADGE_CLASS' => 'helpdesk-preview-delta-stable',
        ];
    }
    if ($most_in !== null)
    {
        $rows[] = [
            'LABEL' => $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_TOP_MOVE_IN'),
            'VALUE_TEXT' => (string) $most_in['LABEL'],
            'META_TEXT' => $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_IN') . ': ' . (int) $most_in['MOVE_IN_COUNT'],
            'BADGE_LABEL' => (string) $most_in['WORKLOAD_AFTER_LABEL'],
            'BADGE_CLASS' => 'helpdesk-preview-delta-stable',
        ];
    }

    return $rows;
}

protected function preview_impact_base_row($assignee_key, array $assignee_load, $role = 'neutral')
{
    $assignee_key = strtolower((string) $assignee_key);
    $load_row = isset($assignee_load[$assignee_key]) ? $assignee_load[$assignee_key] : [];

    return [
        'KEY' => $assignee_key,
        'LABEL' => !empty($load_row['LABEL']) ? (string) $load_row['LABEL'] : (string) $assignee_key,
        'ROLE' => (string) $role,
        'ACTIVE_BEFORE' => isset($load_row['ACTIVE_COUNT']) ? (int) $load_row['ACTIVE_COUNT'] : 0,
        'SCORE_BEFORE' => isset($load_row['SCORE']) ? (int) $load_row['SCORE'] : 0,
        'WORKLOAD_BEFORE_LABEL' => isset($load_row['WORKLOAD_LABEL']) ? (string) $load_row['WORKLOAD_LABEL'] : $this->user->lang('HELPDESK_WORKLOAD_IDLE'),
        'WORKLOAD_BEFORE_CLASS' => isset($load_row['WORKLOAD_CLASS']) ? (string) $load_row['WORKLOAD_CLASS'] : 'helpdesk-workload-idle',
        'MOVE_OUT_COUNT' => 0,
        'MOVE_IN_COUNT' => 0,
    ];
}

protected function preview_impact_label($direction)
{
    switch ((string) $direction)
    {
        case 'down':
            return $this->user->lang('HELPDESK_QUEUE_PREVIEW_IMPACT_DOWN');
        case 'up':
            return $this->user->lang('HELPDESK_QUEUE_PREVIEW_IMPACT_UP');
        default:
            return $this->user->lang('HELPDESK_QUEUE_PREVIEW_IMPACT_STABLE');
    }
}


protected function preview_delta_class($delta)
{
    if ((int) $delta < 0)
    {
        return 'helpdesk-preview-delta-down';
    }
    else if ((int) $delta > 0)
    {
        return 'helpdesk-preview-delta-up';
    }

    return 'helpdesk-preview-delta-stable';
}

protected function preview_delta_label($delta)
{
    if ((int) $delta < 0)
    {
        return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_DELTA_DOWN');
    }
    else if ((int) $delta > 0)
    {
        return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_DELTA_UP');
    }

    return $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_DELTA_STABLE');
}

protected function signed_number_text($value)
{
    $value = (int) $value;
    if ($value > 0)
    {
        return '+' . $value;
    }

    return (string) $value;
}

protected function build_preview_comparison_summary(array $impact_rows)
{
    if (empty($impact_rows))
    {
        return [];
    }

    $improved = 0;
    $heavier = 0;
    $overload_before = 0;
    $overload_after = 0;
    $high_before = 0;
    $high_after = 0;

    foreach ($impact_rows as $row)
    {
        $score_before = isset($row['SCORE_BEFORE']) ? (int) $row['SCORE_BEFORE'] : 0;
        $score_after = isset($row['SCORE_AFTER']) ? (int) $row['SCORE_AFTER'] : 0;
        $before_meta = $this->workload_meta($score_before);
        $after_meta = $this->workload_meta($score_after);
        $before_class = isset($before_meta['class']) ? (string) $before_meta['class'] : 'helpdesk-workload-idle';
        $after_class = isset($after_meta['class']) ? (string) $after_meta['class'] : 'helpdesk-workload-idle';

        if ($score_after < $score_before)
        {
            $improved++;
        }
        else if ($score_after > $score_before)
        {
            $heavier++;
        }

        if ($before_class === 'helpdesk-workload-overload')
        {
            $overload_before++;
        }
        if ($after_class === 'helpdesk-workload-overload')
        {
            $overload_after++;
        }
        if (in_array($before_class, ['helpdesk-workload-high', 'helpdesk-workload-overload'], true))
        {
            $high_before++;
        }
        if (in_array($after_class, ['helpdesk-workload-high', 'helpdesk-workload-overload'], true))
        {
            $high_after++;
        }
    }

    return [
        [
            'LABEL' => $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_IMPROVED'),
            'VALUE_TEXT' => $improved,
            'META_TEXT' => $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_AFFECTED_PEOPLE'),
            'BADGE_LABEL' => $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_BETTER'),
            'BADGE_CLASS' => 'helpdesk-preview-delta-down',
        ],
        [
            'LABEL' => $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_HEAVIER'),
            'VALUE_TEXT' => $heavier,
            'META_TEXT' => $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_RECEIVING_PEOPLE'),
            'BADGE_LABEL' => $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_MORE_LOAD'),
            'BADGE_CLASS' => 'helpdesk-preview-delta-up',
        ],
        [
            'LABEL' => $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_OVERLOAD'),
            'VALUE_TEXT' => $overload_before . ' → ' . $overload_after,
            'META_TEXT' => $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_BEFORE_AFTER'),
            'BADGE_LABEL' => ($overload_after <= $overload_before) ? $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_BETTER') : $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_WORSE'),
            'BADGE_CLASS' => ($overload_after <= $overload_before) ? 'helpdesk-preview-delta-down' : 'helpdesk-preview-delta-up',
        ],
        [
            'LABEL' => $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_HIGH_LOAD'),
            'VALUE_TEXT' => $high_before . ' → ' . $high_after,
            'META_TEXT' => $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_BEFORE_AFTER'),
            'BADGE_LABEL' => ($high_after <= $high_before) ? $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_BETTER') : $this->user->lang('HELPDESK_REDISTRIBUTION_PREVIEW_WORSE'),
            'BADGE_CLASS' => ($high_after <= $high_before) ? 'helpdesk-preview-delta-down' : 'helpdesk-preview-delta-up',
        ],
    ];
}

protected function build_preview_distribution_rows(array $impact_rows)
{
    if (empty($impact_rows))
    {
        return [];
    }

    $levels = [
        'helpdesk-workload-idle' => $this->user->lang('HELPDESK_WORKLOAD_IDLE'),
        'helpdesk-workload-low' => $this->user->lang('HELPDESK_WORKLOAD_LOW'),
        'helpdesk-workload-medium' => $this->user->lang('HELPDESK_WORKLOAD_MEDIUM'),
        'helpdesk-workload-high' => $this->user->lang('HELPDESK_WORKLOAD_HIGH'),
        'helpdesk-workload-overload' => $this->user->lang('HELPDESK_WORKLOAD_OVERLOAD'),
    ];

    $before = array_fill_keys(array_keys($levels), 0);
    $after = array_fill_keys(array_keys($levels), 0);

    foreach ($impact_rows as $row)
    {
        $before_class = isset($row['WORKLOAD_BEFORE_CLASS']) ? (string) $row['WORKLOAD_BEFORE_CLASS'] : 'helpdesk-workload-idle';
        $after_class = isset($row['WORKLOAD_AFTER_CLASS']) ? (string) $row['WORKLOAD_AFTER_CLASS'] : 'helpdesk-workload-idle';

        if (isset($before[$before_class]))
        {
            $before[$before_class]++;
        }
        if (isset($after[$after_class]))
        {
            $after[$after_class]++;
        }
    }

    $max_count = max(1, max($before), max($after));
    $rows = [];
    foreach ($levels as $class => $label)
    {
        $before_count = isset($before[$class]) ? (int) $before[$class] : 0;
        $after_count = isset($after[$class]) ? (int) $after[$class] : 0;
        $delta = $after_count - $before_count;
        $rows[] = [
            'LABEL' => $label,
            'WORKLOAD_CLASS' => $class,
            'BEFORE_COUNT' => $before_count,
            'AFTER_COUNT' => $after_count,
            'BEFORE_WIDTH' => max(8, (int) round(($before_count / $max_count) * 100)),
            'AFTER_WIDTH' => max(8, (int) round(($after_count / $max_count) * 100)),
            'DELTA_TEXT' => $this->signed_number_text($delta),
            'DELTA_CLASS' => $this->preview_delta_class($delta),
        ];
    }

    return $rows;
}

protected function count_preview_sources(array $impact_rows)
{
    $count = 0;
    foreach ($impact_rows as $row)
    {
        if (!empty($row['MOVE_OUT_COUNT']))
        {
            $count++;
        }
    }

    return $count;
}

protected function count_preview_targets(array $impact_rows)
{
    $count = 0;
    foreach ($impact_rows as $row)
    {
        if (!empty($row['MOVE_IN_COUNT']))
        {
            $count++;
        }
    }

    return $count;
}

protected function balanced_redistribution_reason(array $suggestion)
{
    $source_label = isset($suggestion['SOURCE_LABEL']) ? (string) $suggestion['SOURCE_LABEL'] : '';
    $target_label = isset($suggestion['TARGET_LABEL']) ? (string) $suggestion['TARGET_LABEL'] : '';
    $source_workload = isset($suggestion['SOURCE_WORKLOAD_LABEL']) ? (string) $suggestion['SOURCE_WORKLOAD_LABEL'] : '';
    $target_workload = isset($suggestion['TARGET_WORKLOAD_LABEL']) ? (string) $suggestion['TARGET_WORKLOAD_LABEL'] : '';

    return sprintf(
        $this->user->lang('HELPDESK_REDISTRIBUTION_BALANCED_REASON_TEXT'),
        $source_label,
        $source_workload,
        $target_label,
        $target_workload
    );
}


protected function is_smart_redistribution_pick(array $ticket_row, array $target)
{
    if ($this->redistribution_rank($ticket_row) > 1)
    {
        return false;
    }

    if (!empty($ticket_row['IS_OVERDUE']) || !empty($ticket_row['IS_REOPENED']) || !empty($ticket_row['IS_PRIORITIZED']))
    {
        return false;
    }

    $priority_tone = isset($ticket_row['PRIORITY_TONE']) ? (string) $ticket_row['PRIORITY_TONE'] : 'normal';
    if (!in_array($priority_tone, ['low', 'normal'], true))
    {
        return false;
    }

    $target_workload_key = isset($target['WORKLOAD_KEY']) ? (string) $target['WORKLOAD_KEY'] : '';
    return in_array($target_workload_key, ['idle', 'low'], true);
}

protected function smart_redistribution_reason(array $ticket_row, array $target)
{
    $priority_label = isset($ticket_row['PRIORITY_LABEL']) ? (string) $ticket_row['PRIORITY_LABEL'] : $this->user->lang('HELPDESK_FILTER_ALL');
    $target_label = isset($target['LABEL']) ? (string) $target['LABEL'] : '';
    $target_workload_label = isset($target['WORKLOAD_LABEL']) ? (string) $target['WORKLOAD_LABEL'] : '';

    return sprintf(
        $this->user->lang('HELPDESK_REDISTRIBUTION_SMART_REASON_TEXT'),
        $priority_label,
        $target_label,
        $target_workload_label
    );
}

    protected function is_redistribution_candidate_row(array $row)
    {
        if (empty($row['IS_OPEN']) || empty($row['ASSIGNED_TO']))
        {
            return false;
        }

        if (empty($row['IS_WORKLOAD_OVERLOADED']))
        {
            return false;
        }

        if (!empty($row['IS_STAFF_REPLY']) || !empty($row['IS_CRITICAL']))
        {
            return false;
        }

        $priority_tone = isset($row['PRIORITY_TONE']) ? (string) $row['PRIORITY_TONE'] : 'normal';
        if ($priority_tone === 'critical')
        {
            return false;
        }

        return true;
    }

    protected function redistribution_rank(array $row)
    {
        $priority_tone = isset($row['PRIORITY_TONE']) ? (string) $row['PRIORITY_TONE'] : 'normal';
        $priority_rank = [
            'low' => 0,
            'normal' => 1,
            'high' => 2,
            'critical' => 3,
        ];

        $rank = isset($priority_rank[$priority_tone]) ? (int) $priority_rank[$priority_tone] : 1;

        if (!empty($row['IS_OVERDUE']))
        {
            $rank += 3;
        }
        if (!empty($row['IS_REOPENED']))
        {
            $rank += 2;
        }
        if (!empty($row['IS_PRIORITIZED']))
        {
            $rank += 2;
        }

        return $rank;
    }

    protected function build_report(array $rows)
    {
        $now = time();
        $report = [
            'total' => count($rows),
            'active' => 0,
            'resolved' => 0,
            'closed' => 0,
            'unassigned' => 0,
            'first_reply' => 0,
            'updated_24h' => 0,
            'created_24h' => 0,
            'overdue' => 0,
            'staff_reply' => 0,
            'avg_age_label' => $this->user->lang('HELPDESK_REPORT_EMPTY_VALUE'),
            'avg_idle_label' => $this->user->lang('HELPDESK_REPORT_EMPTY_VALUE'),
            'status_rows' => [],
            'department_rows' => [],
            'assignee_rows' => [],
            'priority_rows' => [],
        ];

        if (empty($rows))
        {
            return $report;
        }

        $status_map = [];
        $department_map = [];
        $assignee_map = [];
        $priority_map = [];
        $active_age_total = 0;
        $active_idle_total = 0;
        $active_count_for_average = 0;
        $assignee_load_map = $this->build_assignee_load($rows);

        foreach ($rows as $row)
        {
            $tone = isset($row['STATUS_TONE']) ? (string) $row['STATUS_TONE'] : 'open';
            $is_active = $this->is_active_status_tone($tone);
            $created_ts = !empty($row['CREATED_TS']) ? (int) $row['CREATED_TS'] : 0;
            $updated_ts = !empty($row['UPDATED_TS']) ? (int) $row['UPDATED_TS'] : 0;
            $reply_count = isset($row['REPLY_COUNT']) ? (int) $row['REPLY_COUNT'] : 0;

            if ($is_active)
            {
                $report['active']++;
                if (!empty($row['IS_UNASSIGNED']))
                {
                    $report['unassigned']++;
                }
                if ($reply_count <= 0)
                {
                    $report['first_reply']++;
                }
                if (!empty($row['IS_OVERDUE']))
                {
                    $report['overdue']++;
                }
                if (!empty($row['IS_STAFF_REPLY']))
                {
                    $report['staff_reply']++;
                }
                if ($created_ts > 0)
                {
                    $active_age_total += max(0, $now - $created_ts);
                    $active_count_for_average++;
                }
                if ($updated_ts > 0)
                {
                    $active_idle_total += max(0, $now - $updated_ts);
                }
            }
            else if ($tone === 'resolved')
            {
                $report['resolved']++;
            }
            else if ($tone === 'closed')
            {
                $report['closed']++;
            }

            if ($updated_ts > 0 && $updated_ts >= ($now - 86400))
            {
                $report['updated_24h']++;
            }
            if ($created_ts > 0 && $created_ts >= ($now - 86400))
            {
                $report['created_24h']++;
            }

            $status_label = !empty($row['STATUS_LABEL']) ? (string) $row['STATUS_LABEL'] : $this->user->lang('HELPDESK_REPORT_EMPTY_VALUE');
            if (!isset($status_map[$status_label]))
            {
                $status_map[$status_label] = [
                    'LABEL' => $status_label,
                    'CLASS' => !empty($row['STATUS_CLASS']) ? (string) $row['STATUS_CLASS'] : '',
                    'COUNT' => 0,
                ];
            }
            $status_map[$status_label]['COUNT']++;

            $department_label = !empty($row['DEPARTMENT_LABEL']) ? (string) $row['DEPARTMENT_LABEL'] : $this->user->lang('HELPDESK_REPORT_UNSET_LABEL');
            if (!isset($department_map[$department_label]))
            {
                $department_map[$department_label] = [
                    'LABEL' => $department_label,
                    'COUNT' => 0,
                ];
            }
            $department_map[$department_label]['COUNT']++;

            $priority_label = !empty($row['PRIORITY_LABEL']) ? (string) $row['PRIORITY_LABEL'] : $this->user->lang('HELPDESK_REPORT_EMPTY_VALUE');
            if (!isset($priority_map[$priority_label]))
            {
                $priority_map[$priority_label] = [
                    'LABEL' => $priority_label,
                    'CLASS' => !empty($row['PRIORITY_CLASS']) ? (string) $row['PRIORITY_CLASS'] : '',
                    'COUNT' => 0,
                ];
            }
            $priority_map[$priority_label]['COUNT']++;
            $assignee_label = !empty($row['ASSIGNED_TO']) ? (string) $row['ASSIGNED_TO'] : $this->user->lang('HELPDESK_REPORT_UNASSIGNED_LABEL');
            if (!isset($assignee_map[$assignee_label]))
            {
                $assignee_map[$assignee_label] = [
                    'LABEL' => $assignee_label,
                    'COUNT' => 0,
                    'OVERDUE_COUNT' => 0,
                    'CRITICAL_COUNT' => 0,
                ];
            }
            $assignee_map[$assignee_label]['COUNT']++;
            if (!empty($row['IS_OVERDUE']))
            {
                $assignee_map[$assignee_label]['OVERDUE_COUNT']++;
            }
            if (!empty($row['IS_CRITICAL']))
            {
                $assignee_map[$assignee_label]['CRITICAL_COUNT']++;
            }
        }

        if ($active_count_for_average > 0)
        {
            $report['avg_age_label'] = $this->format_report_duration((int) round($active_age_total / max(1, $active_count_for_average)));
            $report['avg_idle_label'] = $this->format_report_duration((int) round($active_idle_total / max(1, $active_count_for_average)));
        }

        uasort($status_map, function ($a, $b) {
            if ((int) $a['COUNT'] === (int) $b['COUNT'])
            {
                return strcasecmp((string) $a['LABEL'], (string) $b['LABEL']);
            }
            return ((int) $a['COUNT'] > (int) $b['COUNT']) ? -1 : 1;
        });

        uasort($department_map, function ($a, $b) {
            if ((int) $a['COUNT'] === (int) $b['COUNT'])
            {
                return strcasecmp((string) $a['LABEL'], (string) $b['LABEL']);
            }
            return ((int) $a['COUNT'] > (int) $b['COUNT']) ? -1 : 1;
        });

        uasort($priority_map, function ($a, $b) {
            if ((int) $a['COUNT'] === (int) $b['COUNT'])
            {
                return strcasecmp((string) $a['LABEL'], (string) $b['LABEL']);
            }
            return ((int) $a['COUNT'] > (int) $b['COUNT']) ? -1 : 1;
        });
        uasort($assignee_map, function ($a, $b) {
            if ((int) $a['COUNT'] === (int) $b['COUNT'])
            {
                return strcasecmp((string) $a['LABEL'], (string) $b['LABEL']);
            }
            return ((int) $a['COUNT'] > (int) $b['COUNT']) ? -1 : 1;
        });

        foreach ($status_map as $row)
        {
            $report['status_rows'][] = [
                'LABEL' => $row['LABEL'],
                'CLASS' => $row['CLASS'],
                'COUNT' => $row['COUNT'],
                'PERCENT' => $this->report_percent((int) $row['COUNT'], (int) $report['total']),
            ];
        }

        foreach ($this->limit_report_groups($department_map, 8, $this->user->lang('HELPDESK_REPORT_OTHERS_LABEL')) as $row)
        {
            $report['department_rows'][] = [
                'LABEL' => $row['LABEL'],
                'COUNT' => $row['COUNT'],
                'PERCENT' => $this->report_percent((int) $row['COUNT'], (int) $report['total']),
            ];
        }

        foreach ($priority_map as $row)
        {
            $report['priority_rows'][] = [
                'LABEL' => $row['LABEL'],
                'CLASS' => $row['CLASS'],
                'COUNT' => $row['COUNT'],
                'PERCENT' => $this->report_percent((int) $row['COUNT'], (int) $report['total']),
            ];
        }
        foreach ($this->limit_report_groups($assignee_map, 8, $this->user->lang('HELPDESK_REPORT_OTHERS_LABEL')) as $row)
        {
            $lookup = strtolower((string) $row['LABEL']);
            $load_meta = isset($assignee_load_map[$lookup]) ? $assignee_load_map[$lookup] : [
                'WORKLOAD_LABEL' => $this->user->lang('HELPDESK_WORKLOAD_IDLE'),
                'WORKLOAD_CLASS' => 'helpdesk-workload-idle',
                'SCORE' => 0,
            ];

            $report['assignee_rows'][] = [
                'LABEL' => $row['LABEL'],
                'COUNT' => $row['COUNT'],
                'OVERDUE_COUNT' => $row['OVERDUE_COUNT'],
                'CRITICAL_COUNT' => $row['CRITICAL_COUNT'],
                'WORKLOAD_LABEL' => $load_meta['WORKLOAD_LABEL'],
                'WORKLOAD_CLASS' => $load_meta['WORKLOAD_CLASS'],
                'WORKLOAD_SCORE' => $load_meta['SCORE'],
            ];
        }

        return $report;
    }

    protected function limit_report_groups(array $rows, $limit, $other_label)
    {
        $output = [];
        $index = 0;
        $other = null;

        foreach ($rows as $row)
        {
            if ($index < (int) $limit)
            {
                $output[] = $row;
            }
            else
            {
                if ($other === null)
                {
                    $other = [
                        'LABEL' => (string) $other_label,
                        'COUNT' => 0,
                        'OVERDUE_COUNT' => 0,
                        'CRITICAL_COUNT' => 0,
                    ];
                }

                $other['COUNT'] += isset($row['COUNT']) ? (int) $row['COUNT'] : 0;
                $other['OVERDUE_COUNT'] += isset($row['OVERDUE_COUNT']) ? (int) $row['OVERDUE_COUNT'] : 0;
                $other['CRITICAL_COUNT'] += isset($row['CRITICAL_COUNT']) ? (int) $row['CRITICAL_COUNT'] : 0;
            }

            $index++;
        }

        if ($other !== null && $other['COUNT'] > 0)
        {
            $output[] = $other;
        }

        return $output;
    }

    protected function report_percent($count, $total)
    {
        if ((int) $total <= 0)
        {
            return '0%';
        }

        return (string) round(((int) $count / (int) $total) * 100) . '%';
    }

    protected function format_report_duration($seconds)
    {
        $seconds = max(0, (int) $seconds);

        if ($seconds >= 86400)
        {
            return sprintf($this->user->lang('HELPDESK_REPORT_DAYS_VALUE'), round($seconds / 86400, 1));
        }

        if ($seconds >= 3600)
        {
            return sprintf($this->user->lang('HELPDESK_REPORT_HOURS_VALUE'), round($seconds / 3600, 1));
        }

        return sprintf($this->user->lang('HELPDESK_REPORT_MINUTES_VALUE'), max(1, (int) round($seconds / 60)));
    }

    protected function filter_rows(array $rows, array $filters)
    {
        $me = isset($this->user->data['username']) ? strtolower((string) $this->user->data['username']) : '';

        return array_values(array_filter($rows, function ($row) use ($filters, $me) {
            if (!empty($filters['forum_id']) && (int) $row['FORUM_ID'] !== (int) $filters['forum_id'])
            {
                return false;
            }

            if ($filters['status_key'] !== '' && (string) $row['STATUS_KEY'] !== (string) $filters['status_key'])
            {
                return false;
            }

            if ($filters['department_key'] !== '' && (string) $row['DEPARTMENT_KEY'] !== (string) $filters['department_key'])
            {
                return false;
            }
            if ($filters['priority_key'] !== '' && (string) $row['PRIORITY_KEY'] !== (string) $filters['priority_key'])
            {
                return false;
            }

            if ($filters['assigned_to'] !== '' && stripos((string) $row['ASSIGNED_TO'], (string) $filters['assigned_to']) === false)
            {
                return false;
            }

            switch ($filters['scope'])
            {
                case 'unassigned':
                    return !empty($row['IS_UNASSIGNED']) && !empty($row['IS_OPEN']);
                case 'overdue':
                    return !empty($row['IS_OVERDUE']);
                case 'stale':
                    return !empty($row['IS_STALE']);
                case 'reopened':
                    return !empty($row['IS_REOPENED']);
                case 'critical':
                    return !empty($row['IS_CRITICAL']);
                case 'attention':
                    return !empty($row['IS_ATTENTION']);
                case 'staff_reply':
                    return !empty($row['IS_STAFF_REPLY']);
                case 'my':
                    return $me !== '' && strtolower((string) $row['ASSIGNED_TO']) === $me;
                case 'my_overdue':
                    return $me !== '' && strtolower((string) $row['ASSIGNED_TO']) === $me && !empty($row['IS_OVERDUE']);
                case 'my_staff_reply':
                    return $me !== '' && strtolower((string) $row['ASSIGNED_TO']) === $me && !empty($row['IS_STAFF_REPLY']);
                case 'my_critical':
                    return $me !== '' && strtolower((string) $row['ASSIGNED_TO']) === $me && !empty($row['IS_CRITICAL']);
                case 'my_prioritized':
                    return $me !== '' && strtolower((string) $row['ASSIGNED_TO']) === $me && !empty($row['IS_ASSIGNEE_PRIORITIZED']);
                case 'my_alerts':
                    return $me !== '' && strtolower((string) $row['ASSIGNED_TO']) === $me && !empty($row['IS_ASSIGNEE_ALERT']);
                case 'priority_high':
                    return isset($row['PRIORITY_TONE']) && in_array((string) $row['PRIORITY_TONE'], ['high', 'critical'], true);
                case 'priority_critical':
                    return isset($row['PRIORITY_TONE']) && (string) $row['PRIORITY_TONE'] === 'critical';
                case 'prioritized':
                    return !empty($row['IS_PRIORITIZED']);
                case 'overloaded':
                    return !empty($row['IS_WORKLOAD_OVERLOADED']) && !empty($row['IS_OPEN']);
                case 'redistribute':
                    return !empty($row['IS_REDISTRIBUTION_CANDIDATE']);
                case 'all':
                default:
                    return true;
            }
        }));
    }


    protected function build_assignee_load(array $rows)
    {
        $assignees = [];

        foreach ($rows as $row)
        {
            if (empty($row['IS_OPEN']))
            {
                continue;
            }

            $assignee = !empty($row['ASSIGNED_TO']) ? strtolower((string) $row['ASSIGNED_TO']) : '';
            if ($assignee === '')
            {
                continue;
            }

            if (!isset($assignees[$assignee]))
            {
                $assignees[$assignee] = [
                    'LABEL' => (string) $row['ASSIGNED_TO'],
                    'ACTIVE_COUNT' => 0,
                    'OVERDUE_COUNT' => 0,
                    'CRITICAL_COUNT' => 0,
                    'STAFF_REPLY_COUNT' => 0,
                    'PRIORITIZED_COUNT' => 0,
                    'SCORE' => 0,
                    'QUEUE_WEIGHT' => 0,
                    'WORKLOAD_KEY' => 'idle',
                    'WORKLOAD_LABEL' => $this->user->lang('HELPDESK_WORKLOAD_IDLE'),
                    'WORKLOAD_CLASS' => 'helpdesk-workload-idle',
                ];
            }

            $assignees[$assignee]['ACTIVE_COUNT']++;
            if (!empty($row['IS_OVERDUE']))
            {
                $assignees[$assignee]['OVERDUE_COUNT']++;
            }
            if (!empty($row['IS_CRITICAL']))
            {
                $assignees[$assignee]['CRITICAL_COUNT']++;
            }
            if (!empty($row['IS_STAFF_REPLY']))
            {
                $assignees[$assignee]['STAFF_REPLY_COUNT']++;
            }
            if (!empty($row['IS_PRIORITIZED']) || !empty($row['IS_ASSIGNEE_PRIORITIZED']))
            {
                $assignees[$assignee]['PRIORITIZED_COUNT']++;
            }
        }

        foreach ($assignees as $key => $assignee)
        {
            $score = (int) $assignee['ACTIVE_COUNT']
                + ((int) $assignee['OVERDUE_COUNT'] * 3)
                + ((int) $assignee['CRITICAL_COUNT'] * 4)
                + ((int) $assignee['STAFF_REPLY_COUNT'] * 2)
                + ((int) $assignee['PRIORITIZED_COUNT'] * 2);

            $meta = $this->workload_meta($score);
            $assignees[$key]['SCORE'] = $score;
            $assignees[$key]['QUEUE_WEIGHT'] = (int) $meta['queue_weight'];
            $assignees[$key]['WORKLOAD_KEY'] = $meta['key'];
            $assignees[$key]['WORKLOAD_LABEL'] = $meta['label'];
            $assignees[$key]['WORKLOAD_CLASS'] = $meta['class'];
        }

        uasort($assignees, function ($a, $b) {
            if ((int) $a['SCORE'] === (int) $b['SCORE'])
            {
                return strcasecmp((string) $a['LABEL'], (string) $b['LABEL']);
            }

            return ((int) $a['SCORE'] > (int) $b['SCORE']) ? -1 : 1;
        });

        return $assignees;
    }

    protected function current_user_workload(array $assignee_load)
    {
        $username = isset($this->user->data['username']) ? strtolower((string) $this->user->data['username']) : '';
        if ($username !== '' && isset($assignee_load[$username]))
        {
            return [
                'label' => $assignee_load[$username]['WORKLOAD_LABEL'],
                'class' => $assignee_load[$username]['WORKLOAD_CLASS'],
                'score' => $assignee_load[$username]['SCORE'],
                'active_count' => $assignee_load[$username]['ACTIVE_COUNT'],
            ];
        }

        return [
            'label' => $this->user->lang('HELPDESK_WORKLOAD_IDLE'),
            'class' => 'helpdesk-workload-idle',
            'score' => 0,
            'active_count' => 0,
        ];
    }

    protected function workload_meta($score)
    {
        $score = max(0, (int) $score);

        if ($score >= 16)
        {
            return [
                'key' => 'overload',
                'label' => $this->user->lang('HELPDESK_WORKLOAD_OVERLOAD'),
                'class' => 'helpdesk-workload-overload',
                'queue_weight' => 20,
            ];
        }

        if ($score >= 10)
        {
            return [
                'key' => 'high',
                'label' => $this->user->lang('HELPDESK_WORKLOAD_HIGH'),
                'class' => 'helpdesk-workload-high',
                'queue_weight' => 12,
            ];
        }

        if ($score >= 5)
        {
            return [
                'key' => 'medium',
                'label' => $this->user->lang('HELPDESK_WORKLOAD_MEDIUM'),
                'class' => 'helpdesk-workload-medium',
                'queue_weight' => 6,
            ];
        }

        if ($score >= 1)
        {
            return [
                'key' => 'low',
                'label' => $this->user->lang('HELPDESK_WORKLOAD_LOW'),
                'class' => 'helpdesk-workload-low',
                'queue_weight' => 2,
            ];
        }

        return [
            'key' => 'idle',
            'label' => $this->user->lang('HELPDESK_WORKLOAD_IDLE'),
            'class' => 'helpdesk-workload-idle',
            'queue_weight' => 0,
        ];
    }

    protected function load_recent_alerts(array $forum_ids)
    {
        if (empty($forum_ids))
        {
            return [];
        }

        $threshold = time() - ($this->alert_hours() * 3600);
        $sql = 'SELECT l.*, t.topic_title, u.username, f.forum_name
            FROM ' . $this->logs_table() . ' l
            INNER JOIN ' . $this->topics_table() . ' h
                ON h.topic_id = l.topic_id
            INNER JOIN ' . $this->table_prefix . 'topics t
                ON t.topic_id = l.topic_id
            INNER JOIN ' . $this->table_prefix . 'forums f
                ON f.forum_id = l.forum_id
            LEFT JOIN ' . $this->table_prefix . 'users u
                ON u.user_id = l.user_id
            WHERE ' . $this->db->sql_in_set('l.forum_id', array_map('intval', $forum_ids)) . '
                AND l.log_time >= ' . (int) $threshold . "
                AND l.action_key IN ('status_change', 'priority_change', 'assignment_change', 'department_change')
            ORDER BY l.log_time DESC";
        $result = $this->db->sql_query_limit($sql, $this->alert_limit());

        $alerts = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $alert_text = $this->build_alert_text($row);
            $type_meta = $this->history_type_meta(isset($row['action_key']) ? (string) $row['action_key'] : '');
            $alerts[] = [
                'TOPIC_TITLE' => (string) $row['topic_title'],
                'FORUM_NAME' => (string) $row['forum_name'],
                'U_TOPIC' => $this->topic_url((int) $row['forum_id'], (int) $row['topic_id']),
                'ALERT_TEXT' => $alert_text,
                'ALERT_REASON' => !empty($row['reason_text']) ? $this->sanitize_change_reason($row['reason_text']) : '',
                'ALERT_USERNAME' => !empty($row['username']) ? (string) $row['username'] : $this->user->lang('GUEST'),
                'ALERT_TIME' => $this->user->format_date((int) $row['log_time']),
                'ACTION_LABEL' => $type_meta['label'],
                'ACTION_CLASS' => $type_meta['class'],
            ];
        }
        $this->db->sql_freeresult($result);

        return $alerts;
    }

    protected function build_alert_text(array $row)
    {
        $action_key = isset($row['action_key']) ? (string) $row['action_key'] : '';
        $old_value = isset($row['old_value']) ? (string) $row['old_value'] : '';
        $new_value = isset($row['new_value']) ? (string) $row['new_value'] : '';

        if ($action_key === 'assignment_change')
        {
            if ($old_value === '' && $new_value !== '')
            {
                return sprintf($this->user->lang('HELPDESK_LOG_ASSIGNED_TO'), $new_value);
            }
            if ($old_value !== '' && $new_value === '')
            {
                return sprintf($this->user->lang('HELPDESK_LOG_UNASSIGNED_FROM'), $old_value);
            }
            return sprintf($this->user->lang('HELPDESK_LOG_REASSIGNED'), $old_value, $new_value);
        }

        if ($action_key === 'priority_change')
        {
            $old_meta = $this->priority_meta($old_value);
            $new_meta = $this->priority_meta($new_value);
            return sprintf($this->user->lang('HELPDESK_LOG_PRIORITY_CHANGED'), $old_meta['label'], $new_meta['label']);
        }

        if ($action_key === 'department_change')
        {
            $old_label = $this->resolve_option_label($old_value, $this->department_options(), $old_value);
            $new_label = $this->resolve_option_label($new_value, $this->department_options(), $new_value);
            if ($old_value === '' && $new_value !== '')
            {
                return sprintf($this->user->lang('HELPDESK_LOG_DEPARTMENT_SET'), $new_label);
            }
            if ($old_value !== '' && $new_value === '')
            {
                return sprintf($this->user->lang('HELPDESK_LOG_DEPARTMENT_CLEARED'), $old_label);
            }
            return sprintf($this->user->lang('HELPDESK_LOG_DEPARTMENT_CHANGED'), $old_label, $new_label);
        }

        $old_meta = $this->status_meta($old_value);
        $new_meta = $this->status_meta($new_value);
        return sprintf($this->user->lang('HELPDESK_LOG_STATUS_CHANGED'), $old_meta['label'], $new_meta['label']);
    }

    protected function forum_info(array $forum_ids)
    {
        if (empty($forum_ids))
        {
            return [];
        }

        $sql = 'SELECT forum_id, forum_name
            FROM ' . $this->table_prefix . 'forums
            WHERE ' . $this->db->sql_in_set('forum_id', array_map('intval', $forum_ids)) . '
            ORDER BY left_id ASC';
        $result = $this->db->sql_query($sql);

        $forums = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $forums[] = [
                'forum_id' => (int) $row['forum_id'],
                'forum_name' => (string) $row['forum_name'],
            ];
        }
        $this->db->sql_freeresult($result);

        return $forums;
    }

    protected function accessible_forum_ids()
    {
        $enabled = $this->enabled_forum_ids();
        $allowed = [];

        foreach ($enabled as $forum_id)
        {
            if ($this->auth->acl_get('a_')
                || $this->auth->acl_get('m_', $forum_id)
                || $this->auth->acl_get('m_helpdesk_queue', $forum_id)
                || $this->auth->acl_get('m_helpdesk_manage', $forum_id)
                || $this->auth->acl_get('m_helpdesk_bulk', $forum_id)
                || $this->auth->acl_get('m_helpdesk_assign', $forum_id))
            {
                $allowed[] = (int) $forum_id;
            }
        }

        return array_values(array_unique($allowed));
    }

    protected function enabled_forum_ids()
    {
        $raw = isset($this->config['mundophpbb_helpdesk_forums']) ? (string) $this->config['mundophpbb_helpdesk_forums'] : '';
        if ($raw === '')
        {
            return [];
        }

        $ids = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    protected function logs_support_reason()
    {
        return isset($this->config['mundophpbb_helpdesk_reason_enable']);
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

    protected function extension_enabled()
    {
        return !empty($this->config['mundophpbb_helpdesk_enable']);
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

            $department_key = $this->slugify(isset($parts[0]) ? $parts[0] : '');
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




    protected function department_priority_queue_definitions()
    {
        $raw = isset($this->config['mundophpbb_helpdesk_department_priority_queue_definitions']) ? (string) $this->config['mundophpbb_helpdesk_department_priority_queue_definitions'] : '';
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

            $department_key = $this->normalize_option_key(isset($parts[0]) ? $parts[0] : '');
            $priority_key = $this->normalize_priority(isset($parts[1]) ? $parts[1] : '');
            if ($department_key === '' || $priority_key === '')
            {
                continue;
            }

            $definitions[$department_key . '|' . $priority_key] = [
                'enabled' => true,
                'queue_boost' => (int) (isset($parts[2]) ? $parts[2] : 0),
                'alert_hours' => $this->parse_positive_int(isset($parts[3]) ? $parts[3] : ''),
            ];
        }

        return $definitions;
    }

    protected function department_priority_queue_rule($department_key, $priority_key)
    {
        $department_key = $this->normalize_option_key($department_key);
        $priority_key = $this->normalize_priority($priority_key);
        if ($department_key === '' || $priority_key === '')
        {
            return ['enabled' => false, 'queue_boost' => 0, 'alert_hours' => 0];
        }

        $definitions = $this->department_priority_queue_definitions();
        $key = $department_key . '|' . $priority_key;
        if (!isset($definitions[$key]))
        {
            return ['enabled' => false, 'queue_boost' => 0, 'alert_hours' => 0];
        }

        return $definitions[$key];
    }


    protected function assignee_queue_definitions()
    {
        $raw = isset($this->config['mundophpbb_helpdesk_assignee_queue_definitions']) ? (string) $this->config['mundophpbb_helpdesk_assignee_queue_definitions'] : '';
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

            $assignee_key = $this->normalize_option_key(isset($parts[0]) ? $parts[0] : '');
            if ($assignee_key === '')
            {
                continue;
            }

            $definitions[$assignee_key] = [
                'enabled' => true,
                'queue_boost' => (int) (isset($parts[1]) ? $parts[1] : 0),
                'alert_hours' => $this->parse_positive_int(isset($parts[2]) ? $parts[2] : ''),
            ];
        }

        return $definitions;
    }

    protected function assignee_queue_rule($assigned_to)
    {
        $assignee_key = $this->normalize_option_key($assigned_to);
        if ($assignee_key === '')
        {
            return ['enabled' => false, 'queue_boost' => 0, 'alert_hours' => 0];
        }

        $definitions = $this->assignee_queue_definitions();
        if (!isset($definitions[$assignee_key]))
        {
            return ['enabled' => false, 'queue_boost' => 0, 'alert_hours' => 0];
        }

        return $definitions[$assignee_key];
    }

    protected function queue_operational_score(array $priority_route, array $criticality, $is_overdue, $is_stale, $staff_reply_pending, $missing_first_reply, $is_reopened, $is_unassigned, $activity_time)
    {
        $score = 0;

        if (!empty($priority_route['enabled']))
        {
            $score += max(0, (int) $priority_route['queue_boost']);
        }
        if (!empty($is_overdue))
        {
            $score += 80;
        }
        if (!empty($staff_reply_pending))
        {
            $score += 55;
        }
        if (!empty($missing_first_reply))
        {
            $score += 40;
        }
        if (!empty($is_unassigned))
        {
            $score += 28;
        }
        if (!empty($is_stale))
        {
            $score += 22;
        }
        if (!empty($is_reopened))
        {
            $score += 18;
        }
        if (isset($criticality['key']) && (string) $criticality['key'] === 'critical')
        {
            $score += 90;
        }
        else if (isset($criticality['key']) && (string) $criticality['key'] === 'attention')
        {
            $score += 35;
        }

        $idle_hours = 0;
        if ((int) $activity_time > 0)
        {
            $idle_hours = (int) floor(max(0, time() - (int) $activity_time) / 3600);
        }
        $score += min(24, $idle_hours);

        return $score;
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

            $department_key = $this->slugify(isset($parts[0]) ? $parts[0] : '');
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
        $department_key = $this->slugify($department_key);
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
        $department_key = $this->slugify($department_key);
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
        $department_key = $this->slugify($department_key);
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
        return $definitions;
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

            $definitions[$key] = [
                'key' => $key,
                'label_pt_br' => $parts[1] ?? $parts[0],
                'label_en' => $parts[2] ?? ($parts[1] ?? $parts[0]),
                'tone' => $this->normalize_status_tone($parts[3] ?? $key) ?: 'open',
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
        return $map[$value] ?? '';
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
        return !empty($definition['label_pt_br']) ? (string) $definition['label_pt_br'] : (string) $definition['key'];
    }

    protected function status_class_from_tone($tone)
    {
        switch ((string) $tone)
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

    protected function status_meta($key)
    {
        $definitions = $this->status_definitions();
        $resolved_key = array_key_exists((string) $key, $definitions) ? (string) $key : 'open';
        $definition = $definitions[$resolved_key] ?? [
            'key' => 'open',
            'label_pt_br' => $this->user->lang('HELPDESK_STATUS_OPEN'),
            'label_en' => $this->user->lang('HELPDESK_STATUS_OPEN'),
            'tone' => 'open',
        ];

        return [
            'label' => $this->status_label_from_definition($definition),
            'class' => $this->status_class_from_tone($definition['tone'] ?? 'open'),
            'tone' => (string) ($definition['tone'] ?? 'open'),
        ];
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
            'low' => ['key' => 'low', 'label_pt_br' => 'Baixa', 'label_en' => 'Low', 'tone' => 'low'],
            'normal' => ['key' => 'normal', 'label_pt_br' => 'Normal', 'label_en' => 'Normal', 'tone' => 'normal'],
            'high' => ['key' => 'high', 'label_pt_br' => 'Alta', 'label_en' => 'High', 'tone' => 'high'],
            'critical' => ['key' => 'critical', 'label_pt_br' => 'Crítica', 'label_en' => 'Critical', 'tone' => 'critical'],
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

    protected function normalize_priority($value)
    {
        $value = trim((string) $value);
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

    protected function department_options()
    {
        $raw = isset($this->config['mundophpbb_helpdesk_departments']) ? (string) $this->config['mundophpbb_helpdesk_departments'] : '';
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
            $key = $this->slugify($parts[0]);
            $label = trim($parts[1] ?? $parts[0]);
            if ($key !== '' && $label !== '')
            {
                $options[$key] = $label;
            }
        }
        return $options;
    }

    protected function resolve_option_label($key, array $options, $fallback_label)
    {
        $key = trim((string) $key);
        if ($key !== '' && isset($options[$key]))
        {
            return (string) $options[$key];
        }
        return trim((string) $fallback_label);
    }

    protected function topic_reply_count(array $row)
    {
        if (isset($row['helpdesk_reply_count']))
        {
            return max(0, (int) $row['helpdesk_reply_count']);
        }
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

    protected function load_reply_counts(array $topic_ids)
    {
        $topic_ids = array_values(array_unique(array_filter(array_map('intval', $topic_ids))));
        if (empty($topic_ids))
        {
            return [];
        }

        $sql = 'SELECT topic_id, COUNT(post_id) AS post_count
            FROM ' . $this->table_prefix . 'posts
            WHERE ' . $this->db->sql_in_set('topic_id', $topic_ids) . '
            GROUP BY topic_id';
        $result = $this->db->sql_query($sql);

        $counts = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $post_count = isset($row['post_count']) ? (int) $row['post_count'] : 0;
            $counts[(int) $row['topic_id']] = max(0, $post_count - 1);
        }
        $this->db->sql_freeresult($result);

        foreach ($topic_ids as $topic_id)
        {
            if (!isset($counts[$topic_id]))
            {
                $counts[$topic_id] = 0;
            }
        }

        return $counts;
    }

    protected function topic_poster_id(array $row)
    {
        return isset($row['topic_poster']) ? (int) $row['topic_poster'] : 0;
    }

    protected function topic_last_poster_id(array $row)
    {
        return isset($row['topic_last_poster_id']) ? (int) $row['topic_last_poster_id'] : 0;
    }

    protected function topic_last_activity_time(array $row)
    {
        return max(
            !empty($row['topic_last_post_time']) ? (int) $row['topic_last_post_time'] : 0,
            !empty($row['updated_time']) ? (int) $row['updated_time'] : 0,
            !empty($row['created_time']) ? (int) $row['created_time'] : 0
        );
    }

    protected function is_active_status_tone($tone)
    {
        return in_array((string) $tone, ['open', 'progress', 'waiting'], true);
    }

    protected function is_waiting_staff_response(array $row, $status_tone)
    {
        if (!$this->is_active_status_tone($status_tone) || $this->topic_reply_count($row) <= 0)
        {
            return false;
        }
        $topic_poster_id = $this->topic_poster_id($row);
        $last_poster_id = $this->topic_last_poster_id($row);
        return $topic_poster_id > 0 && $last_poster_id > 0 && $topic_poster_id === $last_poster_id;
    }

    protected function is_ticket_overdue(array $row)
    {
        $department_key = $this->extract_department_key($row);
        $priority_key = isset($row['priority_key']) ? (string) $row['priority_key'] : 'normal';
        return !empty($row['created_time']) && (time() - (int) $row['created_time']) > ($this->effective_sla_hours($department_key, $priority_key) * 3600);
    }

    protected function is_ticket_stale(array $row)
    {
        $last_activity = $this->topic_last_activity_time($row);
        $department_key = $this->extract_department_key($row);
        $priority_key = isset($row['priority_key']) ? (string) $row['priority_key'] : 'normal';
        return $last_activity > 0 && (time() - $last_activity) > ($this->effective_stale_hours($department_key, $priority_key) * 3600);
    }

    protected function is_ticket_very_old(array $row)
    {
        $department_key = $this->extract_department_key($row);
        $priority_key = isset($row['priority_key']) ? (string) $row['priority_key'] : 'normal';
        return !empty($row['created_time']) && (time() - (int) $row['created_time']) > ($this->effective_old_hours($department_key, $priority_key) * 3600);
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
        if ($is_overdue) { $score += 2; }
        if ($is_stale) { $score += 1; }
        if ($needs_first_reply) { $score += 2; }
        if ($needs_staff_reply) { $score += 2; }
        if ($is_very_old) { $score += 3; }
        if ($is_unassigned) { $score += 1; }
        if ($is_reopened) { $score += 1; }

        if ($score >= 6)
        {
            return ['key' => 'critical', 'label' => $this->user->lang('HELPDESK_CRITICALITY_CRITICAL'), 'class' => 'helpdesk-tag-criticality-critical'];
        }
        if ($score >= 3)
        {
            return ['key' => 'attention', 'label' => $this->user->lang('HELPDESK_CRITICALITY_ATTENTION'), 'class' => 'helpdesk-tag-criticality-attention'];
        }
        return ['key' => 'normal', 'label' => $this->user->lang('HELPDESK_CRITICALITY_NORMAL'), 'class' => 'helpdesk-tag-criticality-normal'];
    }

    protected function get_reopen_count($topic_id)
    {
        $sql = 'SELECT old_value, new_value
            FROM ' . $this->logs_table() . '
            WHERE topic_id = ' . (int) $topic_id . "
                AND action_key = 'status_change'";
        $result = $this->db->sql_query($sql);
        $count = 0;
        while ($row = $this->db->sql_fetchrow($result))
        {
            $old_tone = $this->status_meta($row['old_value'] ?? '')['tone'];
            $new_tone = $this->status_meta($row['new_value'] ?? '')['tone'];
            if (($old_tone === 'closed' || $old_tone === 'resolved') && $this->is_active_status_tone($new_tone))
            {
                $count++;
            }
        }
        $this->db->sql_freeresult($result);
        return $count;
    }

    protected function extract_assigned_to(array $row)
    {
        return !empty($row['assigned_to']) ? (string) $row['assigned_to'] : '';
    }

    protected function extract_department_key(array $row)
    {
        return !empty($row['department_key']) ? (string) $row['department_key'] : '';
    }

    protected function sanitize_assignee($value)
    {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return substr($value, 0, 255);
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

    protected function slugify($value)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        return trim((string) $value, '_');
    }

    protected function topics_table()
    {
        return $this->table_prefix . 'helpdesk_topics';
    }

    protected function logs_table()
    {
        return $this->table_prefix . 'helpdesk_logs';
    }

    protected function topic_url($forum_id, $topic_id)
    {
        global $phpbb_root_path, $phpEx;
        return append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'f=' . (int) $forum_id . '&t=' . (int) $topic_id . '#helpdesk-topic-panel');
    }

    protected function forum_url($forum_id)
    {
        global $phpbb_root_path, $phpEx;
        return append_sid("{$phpbb_root_path}viewforum.$phpEx", 'f=' . (int) $forum_id . '#helpdesk-queue-panel');
    }
}
