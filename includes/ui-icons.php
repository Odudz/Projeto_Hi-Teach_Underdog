<?php
/**
 * Ícones SVG inline (traço) e pequenos componentes visuais usados nas páginas de
 * Matérias e nos painéis de administração/moderação.
 * Uso: <?= uiIcon('book') ?>
 */
function uiIcon(string $name, int $size = 20): string
{
    static $paths = [
        'overview' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'teacher'  => '<path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12v5c3 2 9 2 12 0v-5"/>',
        'book'     => '<path d="M12 6c-3-3-7-3-10-2v15c3-1 7-1 10 2 3-3 7-3 10-2V4c-3-1-7-1-10 2Z"/><path d="M12 6v15"/>',
        'course'   => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m10 9 5 3-5 3Z"/>',
        'forum'    => '<path d="M21 12a8 8 0 0 1-11.6 7.1L3 21l1.9-6.4A8 8 0 1 1 21 12Z"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.5 3.3-5.5 6.5-5.5s5.9 2 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8"/><path d="M18 14.7c2 .7 3.2 2.5 3.5 5.3"/>',
        'audit'    => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 12h6M9 16h4"/>',
        'shield'   => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6l-8-3Z"/>',
        'live'     => '<circle cx="12" cy="12" r="2"/><path d="M16.2 7.8a6 6 0 0 1 0 8.4M7.8 16.2a6 6 0 0 1 0-8.4M19 5a10 10 0 0 1 0 14M5 19A10 10 0 0 1 5 5"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'alert'    => '<path d="M12 3 2 20h20L12 3Z"/><path d="M12 10v4M12 17.5v.01"/>',
        'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'edit'     => '<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16v4Z"/>',
        'trash'    => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
        'check'    => '<path d="m5 12 5 5 9-10"/>',
        'eye'      => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'lock'     => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'home'     => '<path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'list'     => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'dot'      => '<circle cx="12" cy="12" r="3"/>',
    ];

    $inner = $paths[$name] ?? $paths['dot'];
    return '<svg class="ui-icon" viewBox="0 0 24 24" width="' . $size . '" height="' . $size
        . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true" focusable="false">' . $inner . '</svg>';
}


/** Cartão de estatística do resumo (link quando $href é informado). */
function apStat(string $icon, int|string $num, string $label, string $hint = '', ?string $href = null, bool $alert = false): string
{
    $tag = $href !== null ? 'a' : 'div';
    $attr = $href !== null ? ' href="' . e($href) . '"' : '';
    return '<' . $tag . ' class="ap-stat' . ($alert ? ' is-alert' : '') . '"' . $attr . '>'
        . '<span class="ap-stat-icon">' . uiIcon($icon, 22) . '</span>'
        . '<span class="ap-stat-num">' . e((string) $num) . '</span>'
        . '<span class="ap-stat-label">' . e($label) . '</span>'
        . ($hint !== '' ? '<span class="ap-stat-hint">' . e($hint) . '</span>' : '')
        . '</' . $tag . '>';
}

/** Botão da navegação lateral que troca de painel (tratado por js/staff.js). */
function apNavButton(string $target, string $icon, string $label, int $badge = 0, bool $alert = false): string
{
    return '<button type="button" class="ap-nav-item" data-ap-target="' . e($target) . '">'
        . uiIcon($icon, 20) . '<span>' . e($label) . '</span>'
        . ($badge > 0 ? '<span class="ap-nav-badge' . ($alert ? ' is-alert' : '') . '">' . $badge . '</span>' : '')
        . '</button>';
}

/** Link da navegação lateral que leva para outra página. */
function apNavLink(string $href, string $icon, string $label): string
{
    return '<a class="ap-nav-item" href="' . e($href) . '">' . uiIcon($icon, 20) . '<span>' . e($label) . '</span></a>';
}
