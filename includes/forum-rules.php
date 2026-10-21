<?php
const FORUM_TITLE_MIN = 5;
const FORUM_TITLE_MAX = 100;
const FORUM_BODY_MIN = 10;
const FORUM_BODY_MAX = 500;
const FORUM_STUDENT_LIMIT = 3;

function forumCleanTitle(string $text): string
{
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

function forumCleanBody(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/ ?\n ?/', "\n", $text) ?? $text;
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    return trim($text);
}

function forumValidate(string $title, string $body): ?string
{
    $titleLength = mb_strlen($title);
    $bodyLength = mb_strlen($body);
    if ($titleLength < FORUM_TITLE_MIN || $titleLength > FORUM_TITLE_MAX) {
        return 'O título deve ter de ' . FORUM_TITLE_MIN . ' a ' . FORUM_TITLE_MAX . ' caracteres.';
    }
    if ($bodyLength < FORUM_BODY_MIN || $bodyLength > FORUM_BODY_MAX) {
        return 'A descrição deve ter de ' . FORUM_BODY_MIN . ' a ' . FORUM_BODY_MAX . ' caracteres.';
    }
    return null;
}

function forumAgo(int $seconds): string
{
    if ($seconds < 60) {
        return 'agora';
    }
    if ($seconds < 3600) {
        return 'há ' . intdiv($seconds, 60) . ' min';
    }
    if ($seconds < 86400) {
        return 'há ' . intdiv($seconds, 3600) . ' h';
    }
    if ($seconds < 86400 * 30) {
        return 'há ' . intdiv($seconds, 86400) . ' d';
    }
    return 'há ' . intdiv($seconds, 86400 * 30) . ' mês' . (intdiv($seconds, 86400 * 30) > 1 ? 'es' : '');
}

function forumTileAvatar(array $person): string
{
    $id = (int) $person['author_id'];
    if (!empty($person['author_avatar_mime'])) {
        return '<img class="tile-avatar" src="../endpoints/image.php?user=' . $id . '&amp;type=avatar" alt="" loading="lazy">';
    }
    return '<span class="tile-avatar tile-avatar-initials" aria-hidden="true">' . e(mb_strtoupper(mb_substr(trim((string) $person['author']), 0, 1))) . '</span>';
}

function forumRenderTile(array $topic, int $index = 0, bool $preview = false): string
{
    $closed = !empty($topic['is_closed']);
    ob_start();
    ?>
    <li class="forum-tile<?= $closed ? ' is-closed' : '' ?><?= !empty($topic['is_owner']) ? ' is-mine' : '' ?>" style="--i: <?= min($index, 12) ?>"<?= $preview ? ' data-preview' : '' ?>>
      <div class="forum-tile-top">
        <span class="forum-chip forum-chip-subject"<?= $preview ? ' data-preview-subject data-empty="Matéria"' : '' ?>><?= e($topic['subject']) ?></span>
        <?php if ($closed): ?>
          <span class="forum-chip forum-chip-closed">Fechado</span>
        <?php endif; ?>
      </div>
      <h3 class="forum-tile-title">
        <?php if ($preview): ?>
          <span class="forum-tile-link" data-preview-title data-empty="Título do fórum"><?= e($topic['title']) ?></span>
        <?php else: ?>
          <a class="forum-tile-link" href="forum-topic.php?id=<?= (int) $topic['id'] ?>"><?= e($topic['title']) ?></a>
        <?php endif; ?>
      </h3>
      <p class="forum-tile-desc"<?= $preview ? ' data-preview-desc data-empty="A descrição aparece aqui."' : '' ?>><?= e($topic['body']) ?></p>
      <div class="forum-tile-foot">
        <span class="forum-tile-author"><?= forumTileAvatar($topic) ?><span><?= e($topic['author']) ?></span></span>
        <span class="forum-tile-meta">
          <span class="forum-tile-count" title="Respostas"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><?= (int) $topic['replies'] ?></span>
          <span class="forum-tile-time"><?= e(forumAgo((int) $topic['idle'])) ?></span>
        </span>
      </div>
    </li>
    <?php
    return (string) ob_get_clean();
}
