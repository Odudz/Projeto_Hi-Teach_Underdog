<?php
/**
 * Avaliações por estrelas (1 a 5) de professores, aulas e atividades.
 * Somente estrelas: não há comentários.
 * Pressupõe que courses.php já foi incluído (conexão, cursos e schema).
 */
require_once __DIR__ . '/courses.php';

const RATING_TYPES = ['teacher', 'lesson', 'activity'];

/**
 * Médias e totais de um alvo. $studentId informado devolve também a nota do próprio aluno.
 *
 * @return array{avg: ?float, count: int, mine: ?int}
 */
function ratingStats(PDO $pdo, string $type, int $targetId, int $studentId = 0): array
{
    $stats = ['avg' => null, 'count' => 0, 'mine' => null];
    if (!in_array($type, RATING_TYPES, true) || $targetId <= 0) {
        return $stats;
    }
    try {
        $stmt = $pdo->prepare('SELECT AVG(stars) AS avg_stars, COUNT(*) AS total FROM course_ratings WHERE target_type = ? AND target_id = ?');
        $stmt->execute([$type, $targetId]);
        $row = $stmt->fetch() ?: [];
        $stats['avg'] = isset($row['avg_stars']) ? round((float) $row['avg_stars'], 1) : null;
        $stats['count'] = (int) ($row['total'] ?? 0);

        if ($studentId > 0) {
            $stmt = $pdo->prepare('SELECT stars FROM course_ratings WHERE target_type = ? AND target_id = ? AND student_id = ?');
            $stmt->execute([$type, $targetId, $studentId]);
            $mine = $stmt->fetchColumn();
            $stats['mine'] = $mine === false ? null : (int) $mine;
        }
    } catch (Throwable $error) {
        error_log('ratings.php: ' . $error->getMessage());
    }
    return $stats;
}

function ratingSave(PDO $pdo, int $studentId, string $type, int $targetId, int $stars): void
{
    $pdo->prepare('INSERT INTO course_ratings (target_type, target_id, student_id, stars) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE stars = VALUES(stars)')
        ->execute([$type, $targetId, $studentId, $stars]);
}

function ratingStarSvg(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><polygon points="12 2.5 15.1 8.8 22 9.8 17 14.7 18.2 21.5 12 18.2 5.8 21.5 7 14.7 2 9.8 8.9 8.8 12 2.5"/></svg>';
}

function ratingFormatAvg(float $avg): string
{
    return number_format($avg, 1, ',', '.');
}

/**
 * Bloco de avaliação. Alunos com $canRate veem as estrelas clicáveis (formulário POST,
 * sem JavaScript). Os demais veem apenas a média.
 */
function ratingRender(PDO $pdo, string $type, int $targetId, int $courseId, int $userId, bool $canRate, string $title, string $anchor): string
{
    $stats = ratingStats($pdo, $type, $targetId, $canRate ? $userId : 0);
    $hasVotes = $stats['count'] > 0 && $stats['avg'] !== null;

    if ($hasVotes) {
        $summary = 'Média ' . ratingFormatAvg($stats['avg']) . ' de 5 · '
            . $stats['count'] . ($stats['count'] === 1 ? ' avaliação' : ' avaliações');
    } else {
        $summary = 'Ainda sem avaliações';
    }

    $html = '<div id="' . e($anchor) . '" class="rating-box">';
    $html .= '<p class="rating-title">' . e($title) . '</p>';

    if ($canRate) {
        $mine = $stats['mine'];
        $html .= '<form class="rating-form" method="post" action="' . e(ratingBaseUrl()) . 'endpoints/rating.php">';
        $html .= csrfInput();
        $html .= '<input type="hidden" name="type" value="' . e($type) . '">';
        $html .= '<input type="hidden" name="course_id" value="' . $courseId . '">';
        if ($type !== 'teacher') {
            $html .= '<input type="hidden" name="target_id" value="' . $targetId . '">';
        }
        $html .= '<fieldset class="rating-stars-group"><legend class="visually-hidden">' . e($title) . '</legend>';
        for ($star = 1; $star <= 5; $star++) {
            $on = $mine !== null && $star <= $mine;
            $label = $star === 1 ? '1 estrela' : $star . ' estrelas';
            $html .= '<button type="submit" name="stars" value="' . $star . '" class="rating-star' . ($on ? ' is-on' : '') . '"'
                . ' aria-label="' . e($label) . '" aria-pressed="' . ($mine === $star ? 'true' : 'false') . '" title="' . e($label) . '">'
                . ratingStarSvg() . '</button>';
        }
        $html .= '</fieldset>';
        $html .= '<p class="rating-meta">' . ($mine !== null
            ? 'Sua avaliação: ' . $mine . ' de 5. Toque em outra estrela para mudar.'
            : 'Toque em uma estrela para avaliar.') . ' <span class="rating-summary">' . e($summary) . '</span></p>';
        $html .= '</form>';
    } else {
        $rounded = $hasVotes ? (int) round($stats['avg']) : 0;
        $html .= '<div class="rating-stars is-static" role="img" aria-label="' . e($hasVotes ? 'Média ' . ratingFormatAvg($stats['avg']) . ' de 5 estrelas' : 'Sem avaliações') . '">';
        for ($star = 1; $star <= 5; $star++) {
            $html .= '<span class="rating-star' . ($star <= $rounded ? ' is-on' : '') . '">' . ratingStarSvg() . '</span>';
        }
        $html .= '</div>';
        $html .= '<p class="rating-meta"><span class="rating-summary">' . e($summary) . '</span></p>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Caminho até a raiz do site a partir da página atual (as páginas usam '' ou '../').
 */
function ratingBaseUrl(): string
{
    return $GLOBALS['baseUrl'] ?? '';
}
