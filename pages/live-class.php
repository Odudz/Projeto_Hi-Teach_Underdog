<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/live.php';

$currentUser = liveCurrentUser();
$isTeacher = liveIsTeacher($currentUser);
$old = ['title' => '', 'subject' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isTeacher) {
        setFlash('error', 'Somente professores podem criar lives.');
        redirect('live-class.php');
    }
    if (!liveCsrfCheck()) {
        setFlash('error', 'Sessão expirada. Tente novamente.');
        redirect('live-class.php');
    }

    $old = [
        'title' => trim($_POST['title'] ?? ''),
        'subject' => trim($_POST['subject'] ?? ''),
    ];
    $errors = liveValidateInput($old['title'], $old['subject']);

    if (!$errors) {
        $active = liveTeacherActive($pdo, (int) $currentUser['id']);
        if ($active) {
            setFlash('error', 'Você já tem uma live no ar. Volte para ela ou encerre-a antes de criar outra.');
            redirect('live-teacher.php?id=' . (int) $active['id']);
        }

        $newId = liveCreate($pdo, (int) $currentUser['id'], $old['title'], $old['subject']);
        redirect('live-teacher.php?id=' . $newId);
    }

    setFlash('error', implode(' ', $errors));
}

$lives = $currentUser ? liveActiveList($pdo) : [];
$myActive = $isTeacher ? liveTeacherActive($pdo, (int) $currentUser['id']) : null;

$pageId = 'live-class';
$pageTitle = 'Aulas ao vivo';
$baseUrl = '../';
$extraCss = ['forum', 'courses', 'live'];
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content courses-content">

    <section class="section forum-hero">
        <span class="forum-hero-deco forum-hero-deco-a" aria-hidden="true"></span>
        <span class="forum-hero-deco forum-hero-deco-b" aria-hidden="true"></span>
        <span class="forum-hero-deco forum-hero-deco-c" aria-hidden="true"></span>
        <h1 class="forum-hero-title">Aulas ao vivo</h1>
        <p class="forum-hero-lead">Participe das transmissões em tempo real, interaja com os professores e tire suas
            dúvidas na hora.</p>
    </section>

    <?php if ($isTeacher): ?>
        <section id="live-create-section" class="section course-form-panel">
            <div class="courses-section-heading">
                <h2 class="section-title">Iniciar Transmissão</h2>
            </div>

            <?php if ($myActive): ?>
                <div class="video-notice"
                    style="background: color-mix(in srgb, var(--color-primary) 15%, var(--color-surface));">
                    <div class="video-notice-icon" style="background: var(--color-primary); color: var(--color-on-primary);">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4"
                            stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="23 7 16 12 23 17 23 7" />
                            <rect x="1" y="5" width="15" height="14" rx="2" ry="2" />
                        </svg>
                    </div>
                    <div class="video-notice-text">
                        <strong style="color: var(--color-primary);">Você já tem uma live no ar</strong>
                        <span>A transmissão "<?= e($myActive['title']) ?>" está em andamento no momento.</span>
                    </div>
                    <a id="link-my-live" class="button button-small button-primary"
                        href="live-teacher.php?id=<?= (int) $myActive['id'] ?>">Voltar para a minha live</a>
                </div>
            <?php else: ?>
                <form id="live-create-form" class="form" action="live-class.php" method="post">
                    <?= liveCsrfField() ?>

                    <label>Nome da live
                        <input type="text" id="live-title" name="title" class="form-input" maxlength="<?= LIVE_TITLE_MAX ?>"
                            value="<?= e($old['title']) ?>" placeholder="Ex.: Revisão para a Prova" required>
                    </label>

                    <label>Matéria
                        <input type="text" id="live-subject" name="subject" class="form-input"
                            maxlength="<?= LIVE_SUBJECT_MAX ?>" value="<?= e($old['subject']) ?>" placeholder="Ex.: Matemática"
                            required>
                    </label>

                    <button type="submit" id="live-create-form-submit" class="button button-primary"
                        style="margin-top: var(--space-2);">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4"
                            stroke-linecap="round" stroke-linejoin="round"
                            style="margin-right: 8px; vertical-align: text-bottom;">
                            <polygon points="23 7 16 12 23 17 23 7" />
                            <rect x="1" y="5" width="15" height="14" rx="2" ry="2" />
                        </svg>
                        Abrir sala ao vivo
                    </button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section id="live-player-section" class="section forum-shelf">
        <div class="courses-section-heading">
            <h2 class="section-title">No ar agora <span class="dm-count"><?= $currentUser ? count($lives) : 0 ?></span>
            </h2>
        </div>

        <?php if (!$currentUser): ?>
            <div class="video-placeholder">
                <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
                    <polyline points="10 17 15 12 10 7" />
                    <line x1="15" y1="12" x2="3" y2="12" />
                </svg>
                <h3 style="margin: 0; font-size: var(--font-size-xl); font-weight: 900;">Entre para assistir</h3>
                <p style="margin: 0;">Faça login na sua conta para acessar as transmissões ao vivo da plataforma.</p>
                <div style="display: flex; gap: var(--space-3); margin-top: var(--space-2);">
                    <a id="link-live-login" class="button button-primary"
                        href="../<?= LIVE_LOGIN_PATH ?>?next=pages/live-class.php">Entrar na conta</a>
                    <a id="link-live-register" class="button button-secondary"
                        href="../<?= LIVE_REGISTER_PATH ?>">Cadastre-se</a>
                </div>
            </div>

        <?php elseif (!$lives): ?>
            <div class="video-placeholder">
                <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18" />
                    <line x1="7" y1="2" x2="7" y2="22" />
                    <line x1="17" y1="2" x2="17" y2="22" />
                    <line x1="2" y1="12" x2="22" y2="12" />
                    <line x1="2" y1="7" x2="7" y2="7" />
                    <line x1="2" y1="17" x2="7" y2="17" />
                    <line x1="17" y1="17" x2="22" y2="17" />
                    <line x1="17" y1="7" x2="22" y2="7" />
                </svg>
                <h3 style="margin: 0; font-size: var(--font-size-xl); font-weight: 900;">Nenhuma live no momento</h3>
                <p style="margin: 0;">Os professores costumam avisar sobre aulas ao vivo com antecedência.<br>Fique de olho
                    nos fóruns das suas matérias!</p>
            </div>

        <?php else: ?>
            <div class="forum-grid">
                <?php foreach ($lives as $live): ?>
                    <article class="forum-tile course-card">
                        <div class="forum-tile-top">
                            <span class="forum-chip chip-danger" style="display: inline-flex; align-items: center; gap: 6px;">
                                <span class="live-pulse-dot"></span> Ao vivo
                            </span>
                            <span class="forum-chip forum-chip-subject"><?= e($live['subject']) ?></span>
                        </div>
                        <h3 class="forum-tile-title" style="margin-top: var(--space-2); margin-bottom: var(--space-2);">
                            <?= e($live['title']) ?></h3>
                        <div class="forum-tile-foot" style="margin-top: auto;">
                            <span class="forum-tile-author">
                                <span class="tile-avatar tile-avatar-initials"
                                    aria-hidden="true"><?= e(mb_strtoupper(mb_substr($live['teacher_name'], 0, 1))) ?></span>
                                <span><?= e($live['teacher_name']) ?></span>
                            </span>
                            <a class="button button-primary button-small"
                                href="live-watch.php?id=<?= (int) $live['id'] ?>">Assistir Agora</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

</main>

<?php if ($currentUser && !$isTeacher): ?>
    <script>
        setTimeout(function() {
            window.location.reload();
        }, 30000);
    </script>
<?php endif; ?>

<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
