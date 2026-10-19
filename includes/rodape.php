<footer id="site-footer" class="site-footer">
  <p class="footer-text">Hi Teach — ensino a distância para todos.</p>
</footer>

<?= $modals ?? '' ?>

<div id="settings-modal" class="modal-overlay">
  <section id="settings-dialog" class="modal" role="dialog" aria-modal="true" aria-labelledby="settings-title">
    <header class="modal-header">
      <h2 id="settings-title" class="modal-title">Opções</h2>
      <button type="button" class="modal-close" data-modal-close aria-label="Fechar opções">×</button>
    </header>

    <div id="settings-theme" class="settings-group">
      <h3 class="settings-group-title">Tema</h3>

      <label class="theme-switch theme-switch-day" for="switch-light">
        <span class="switch-text">
          <span class="switch-label">Tema claro</span>
          <span class="switch-hint">Cores base mais leves</span>
        </span>
        <input type="checkbox" id="switch-light" class="switch-input" data-theme-value="light">
        <span class="switch-track"><span class="switch-stars"></span><span class="switch-clouds"></span><span class="switch-knob"></span></span>
      </label>

      <label class="theme-switch theme-switch-night" for="switch-dark">
        <span class="switch-text">
          <span class="switch-label">Tema escuro</span>
          <span class="switch-hint">Fundo escurecido, mesma estética</span>
        </span>
        <input type="checkbox" id="switch-dark" class="switch-input" data-theme-value="dark">
        <span class="switch-track"><span class="switch-stars"></span><span class="switch-clouds"></span><span class="switch-knob"></span></span>
      </label>

      <label class="theme-switch theme-switch-amoled" for="switch-amoled">
        <span class="switch-text">
          <span class="switch-label">AMOLED</span>
          <span class="switch-hint">Preto total, economiza bateria</span>
        </span>
        <input type="checkbox" id="switch-amoled" class="switch-input" data-theme-value="amoled">
        <span class="switch-track"><span class="switch-stars"></span><span class="switch-clouds"></span><span class="switch-knob"></span></span>
      </label>
    </div>

    <div id="settings-motion" class="settings-group">
      <h3 class="settings-group-title">Movimento</h3>

      <label class="theme-switch theme-switch-plain" for="switch-reduce-motion">
        <span class="switch-text">
          <span class="switch-label">Reduzir animações</span>
          <span class="switch-hint">Desliga transições e efeitos</span>
        </span>
        <input type="checkbox" id="switch-reduce-motion" class="switch-input">
        <span class="switch-track"><span class="switch-stars"></span><span class="switch-clouds"></span><span class="switch-knob"></span></span>
      </label>
    </div>
  </section>
</div>

<?php $baseUrl = $baseUrl ?? ''; ?>
<script src="<?= e($baseUrl) ?>js/modal.js"></script>
<script src="<?= e($baseUrl) ?>js/settings.js"></script>
<?php foreach ($extraJs ?? [] as $script): ?>
<script src="<?= e($baseUrl) ?>js/<?= e($script) ?>.js"></script>
<?php endforeach; ?>
<script src="<?= e($baseUrl) ?>js/main.js"></script>
</body>
</html>
