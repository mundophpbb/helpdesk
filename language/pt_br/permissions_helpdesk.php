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
    'ACL_A_HELPDESK_MANAGE' => 'Pode administrar as configurações e permissões do Help Desk',
    'ACL_M_HELPDESK_MANAGE' => 'Pode gerenciar status e departamento do Help Desk',
    'ACL_M_HELPDESK_ASSIGN' => 'Pode atribuir tickets do Help Desk',
    'ACL_M_HELPDESK_BULK' => 'Pode usar ações em massa do Help Desk',
    'ACL_M_HELPDESK_QUEUE' => 'Pode ver a fila da equipe do Help Desk',

    'ACL_F_HELPDESK_VIEW' => 'Pode ver o contexto do Help Desk e a lista de tickets próprios nos fóruns habilitados',
    'ACL_F_HELPDESK_TICKET' => 'Pode abrir e editar tickets do Help Desk nos fóruns habilitados',
]);
