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

        if (!in_array($filters['scope'], ['all', 'unassigned', 'overdue', 'stale', 'reopened', 'critical', 'attention', 'staff_reply', 'my', 'my_overdue', 'my_staff_reply', 'my_critical', 'priority_high', 'priority_critical'], true))
        {
            $filters['scope'] = 'all';
        }

        if ($filters['mine'])
        {
            $filters['scope'] = 'my';
        }

        $rows = $this->load_ticket_rows($forum_ids);
        $counts = $this->build_counts($rows);
        $filtered_rows = $this->filter_rows($rows, $filters);
        $recent_alerts = $this->alerts_enabled() ? $this->load_recent_alerts($forum_ids) : [];
        $report = $this->build_report($filtered_rows);

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
            'HELPDESK_QUEUE_MY_COUNT' => $counts['my'],
            'HELPDESK_QUEUE_MY_OVERDUE_COUNT' => $counts['my_overdue'],
            'HELPDESK_QUEUE_MY_STAFF_REPLY_COUNT' => $counts['my_staff_reply'],
            'HELPDESK_QUEUE_MY_CRITICAL_COUNT' => $counts['my_critical'],
            'HELPDESK_QUEUE_USER' => isset($this->user->data['username']) ? (string) $this->user->data['username'] : '',
            'HELPDESK_ALERT_HOURS' => $this->alert_hours(),
            'HELPDESK_ALERT_LIMIT' => $this->alert_limit(),
            'HELPDESK_FILTER_SCOPE' => $filters['scope'],
            'HELPDESK_FILTER_PRIORITY' => $filters['priority_key'],
            'HELPDESK_FILTER_ASSIGNED_TO' => $filters['assigned_to'],
            'U_HELPDESK_TEAM_QUEUE' => $base_queue_url,
            'U_HELPDESK_TEAM_QUEUE_RESET' => $base_queue_url,
            'U_HELPDESK_TEAM_QUEUE_QUEUE' => $base_queue_url . '?qview=queue',
            'U_HELPDESK_TEAM_QUEUE_REPORTS' => $base_queue_url . '?qview=reports',
            'U_HELPDESK_TEAM_QUEUE_ALERTS' => $base_queue_url . '?qview=alerts',
            'S_HELPDESK_ALERTS_ENABLED' => $this->alerts_enabled(),
            'S_HELPDESK_QUEUE_HAS_RESULTS' => !empty($filtered_rows),
            'HELPDESK_TEAM_ALERTS_EXPLAIN_TEXT' => sprintf($this->user->lang('HELPDESK_TEAM_ALERTS_EXPLAIN'), $this->alert_hours(), $this->alert_limit()),
            'S_HELPDESK_REPORT_HAS_DATA' => !empty($report['total']),
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
                'ALERT_TEXT' => implode(' · ', $alerts),
            ];
        }

        return $rows;
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
            'my' => 0,
            'my_overdue' => 0,
            'my_staff_reply' => 0,
            'my_critical' => 0,
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
            if (isset($row['PRIORITY_TONE']) && in_array((string) $row['PRIORITY_TONE'], ['high', 'critical'], true))
            {
                $counts['priority_high']++;
            }
            if (isset($row['PRIORITY_TONE']) && (string) $row['PRIORITY_TONE'] === 'critical')
            {
                $counts['priority_critical']++;
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
            }
        }


        return $counts;
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
            $report['assignee_rows'][] = [
                'LABEL' => $row['LABEL'],
                'COUNT' => $row['COUNT'],
                'OVERDUE_COUNT' => $row['OVERDUE_COUNT'],
                'CRITICAL_COUNT' => $row['CRITICAL_COUNT'],
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
                case 'priority_high':
                    return isset($row['PRIORITY_TONE']) && in_array((string) $row['PRIORITY_TONE'], ['high', 'critical'], true);
                case 'priority_critical':
                    return isset($row['PRIORITY_TONE']) && (string) $row['PRIORITY_TONE'] === 'critical';
                case 'all':
                default:
                    return true;
            }
        }));
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
                AND l.action_key IN ('status_change', 'assignment_change', 'department_change')
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

    protected function parse_positive_int($value)
    {
        $value = trim((string) $value);
        if ($value === '')
        {
            return 0;
        }

        return max(0, (int) $value);
    }

    protected function effective_sla_hours($department_key = '')
    {
        $hours = $this->sla_hours();
        $department_key = $this->slugify($department_key);
        $definitions = $this->department_sla_definitions();

        if ($department_key !== '' && !empty($definitions[$department_key]['sla_hours']))
        {
            $hours = (int) $definitions[$department_key]['sla_hours'];
        }

        return max(1, (int) $hours);
    }

    protected function effective_stale_hours($department_key = '')
    {
        $hours = $this->stale_hours();
        $department_key = $this->slugify($department_key);
        $definitions = $this->department_sla_definitions();

        if ($department_key !== '' && !empty($definitions[$department_key]['stale_hours']))
        {
            $hours = (int) $definitions[$department_key]['stale_hours'];
        }

        return max(1, (int) $hours);
    }

    protected function effective_old_hours($department_key = '')
    {
        $hours = $this->old_hours();
        $department_key = $this->slugify($department_key);
        $definitions = $this->department_sla_definitions();

        if ($department_key !== '' && !empty($definitions[$department_key]['old_hours']))
        {
            $hours = (int) $definitions[$department_key]['old_hours'];
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
        return !empty($row['created_time']) && (time() - (int) $row['created_time']) > ($this->effective_sla_hours($department_key) * 3600);
    }

    protected function is_ticket_stale(array $row)
    {
        $last_activity = $this->topic_last_activity_time($row);
        $department_key = $this->extract_department_key($row);
        return $last_activity > 0 && (time() - $last_activity) > ($this->effective_stale_hours($department_key) * 3600);
    }

    protected function is_ticket_very_old(array $row)
    {
        $department_key = $this->extract_department_key($row);
        return !empty($row['created_time']) && (time() - (int) $row['created_time']) > ($this->effective_old_hours($department_key) * 3600);
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
