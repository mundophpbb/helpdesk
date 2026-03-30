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
    'ACL_M_HELPDESK_MANAGE' => 'Can manage Help Desk status and department',
    'ACL_M_HELPDESK_ASSIGN' => 'Can assign Help Desk tickets',
    'ACL_M_HELPDESK_BULK' => 'Can use Help Desk bulk actions',
    'ACL_M_HELPDESK_QUEUE' => 'Can view the Help Desk team queue',
]);
