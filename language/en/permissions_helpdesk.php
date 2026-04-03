<?php
if (!defined('IN_PHPBB'))
{
    exit;
}

if (empty($lang) || !is_array($lang))
{
    $lang = [];
}

$lang = array_merge($lang, [
    'ACL_A_HELPDESK_MANAGE' => 'Can manage Help Desk settings and permissions',
    'ACL_M_HELPDESK_MANAGE' => 'Can manage Help Desk status and department',
    'ACL_M_HELPDESK_ASSIGN' => 'Can assign Help Desk tickets',
    'ACL_M_HELPDESK_BULK' => 'Can use Help Desk bulk actions',
    'ACL_M_HELPDESK_QUEUE' => 'Can view the Help Desk team queue',

    'ACL_F_HELPDESK_VIEW' => 'Can view Help Desk context and personal ticket listings in enabled forums',
    'ACL_F_HELPDESK_TICKET' => 'Can open and edit Help Desk tickets in enabled forums',
]);
