<?php
/**
 * Componentes de Interface para Painéis Administrativos
 */
include_once __DIR__ . '/rbac.php';

function uiInitials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) {
        return '?';
    }
    $initials = mb_strtoupper(mb_substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $initials .= mb_strtoupper(mb_substr(end($parts), 0, 1));
    }
    return $initials;
}

function uiAvatar(int $userId, string $name, bool $hasImage, string $size = '', string $base = '../'): string
{
    $class = 'avatar' . ($size !== '' ? ' avatar-' . $size : '');
    if ($hasImage) {
        return '<span class="' . $class . '"><img src="' . e($base) . 'endpoints/image.php?user=' . $userId . '&amp;type=avatar" alt="" loading="lazy"></span>';
    }
    return '<span class="' . $class . '" aria-hidden="true">' . e(uiInitials($name)) . '</span>';
}

function uiAgo(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return 'agora';
    }
    if ($seconds < 3600) {
        return 'há ' . intdiv($seconds, 60) . ' min';
    }
    if ($seconds < 86400) {
        return 'há ' . intdiv($seconds, 3600) . ' h';
    }
    return 'há ' . intdiv($seconds, 86400) . ' d';
}

function uiDuration(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return $seconds . ' s';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . ' min';
    }
    return intdiv($seconds, 3600) . ' h ' . str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT) . ' min';
}

function uiRemaining(?int $seconds): string
{
    if ($seconds === null) {
        return 'indefinida';
    }
    if ($seconds <= 0) {
        return 'expirando';
    }
    if ($seconds < 3600) {
        return 'restam ' . max(1, intdiv($seconds, 60)) . ' min';
    }
    if ($seconds < 86400) {
        return 'restam ' . intdiv($seconds, 3600) . ' h';
    }
    return 'restam ' . intdiv($seconds, 86400) . ' d';
}

function uiRoleBadge(string $role): string
{
    $variant = ['admin' => 'chip-danger', 'moderator' => 'chip-accent', 'teacher' => 'chip-primary'][$role] ?? 'chip-muted';
    return '<span class="chip ' . $variant . '">' . e(rbacRoleLabel($role)) . '</span>';
}

function uiStat(string $label, int|string $value, ?string $href = null, string $hint = '', bool $alert = false): string
{
    $inner = '<span class="stat-value">' . e((string) $value) . '</span>'
        . '<span class="stat-label">' . e($label) . '</span>'
        . ($hint !== '' ? '<span class="stat-hint">' . e($hint) . '</span>' : '');
    $class = 'stat-card' . ($alert ? ' is-alert' : '');
    return $href !== null
        ? '<a class="' . $class . '" href="' . e($href) . '">' . $inner . '</a>'
        : '<div class="' . $class . '">' . $inner . '</div>';
}

function uiPreviewType(string $nameOrMime): ?string
{
    $value = strtolower($nameOrMime);
    if (str_starts_with($value, 'image/') || preg_match('/\.(jpe?g|png|gif|webp)$/', $value)) {
        return 'image';
    }
    if ($value === 'application/pdf' || str_ends_with($value, '.pdf')) {
        return 'pdf';
    }
    return null;
}

function uiAttachment(string $src, string $name, string $mime): string
{
    $type = uiPreviewType($mime . ' ' . $name) ?? uiPreviewType($name);
    if ($type === 'image') {
        return '<a class="thumb" href="' . e($src) . '"><img src="' . e($src) . '" alt="' . e($name) . '" loading="lazy"></a>';
    }
    return '<a class="button button-small button-secondary" href="' . e($src) . '">Documento: ' . e($name) . '</a>';
}

function uiAuditLabel(string $action): string
{
    return [
        'ROLE_CHANGED'      => 'Cargo alterado',
        'POST_DELETED'      => 'Mensagem excluída',
        'TOPIC_DELETED'     => 'Fórum excluído',
        'USER_MUTED'        => 'Usuário silenciado',
        'USER_SUSPENDED'    => 'Usuário suspenso',
        'SANCTION_REVOKED'  => 'Sanção revogada',
        'REPORT_HANDLED'    => 'Denúncia atendida',
        'LIVE_ENDED'        => 'Live encerrada',
        'TEACHER_APPROVED'  => 'Professor aprovado',
        'TEACHER_REJECTED'  => 'Professor recusado',
        'SUBJECT_CREATED'   => 'Matéria criada',
        'SUBJECT_UPDATED'   => 'Matéria atualizada',
        'SUBJECT_DELETED'   => 'Matéria excluída',
        'USER_ACTIVATED'    => 'Conta ativada',
        'USER_DEACTIVATED'  => 'Conta desativada',
    ][$action] ?? $action;
}