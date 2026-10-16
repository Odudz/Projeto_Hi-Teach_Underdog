<?php
/**
 * Componente do player JaaS: imprime o container da videochamada e inicia a API.
 * Usado tanto pela página do professor quanto pela do aluno.
 * Estilos em css/live.css (carregue com $extraCss = ['live'] antes do cabecalho.php).
 *
 * Opções ($opts):
 *   room       (string, obrigatório)  chave da sala (sem o App ID)
 *   jwt        (string, obrigatório)  token gerado por gerarJwtJaas()
 *   config     (array)                configOverwrite do Jitsi (microfone, câmera, botões...)
 *   exitUrl    (string)               para onde ir quando a pessoa sair da chamada
 *   onCloseJs  (string JS)            substitui o comportamento padrão ao sair da chamada
 *   extraJs    (string JS)            código extra; a variável `api` já existe aqui dentro
 */
require_once __DIR__ . '/jaas_config.php';

function renderJaasPlayer(array $opts): void
{
    $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    $options = [
        'roomName' => JAAS_APP_ID . '/' . $opts['room'],
        'jwt' => $opts['jwt'],
        'width' => '100%',
        'height' => '100%',
        'configOverwrite' => array_merge(['prejoinPageEnabled' => false], $opts['config'] ?? []),
    ];

    $onClose = $opts['onCloseJs']
        ?? 'window.location.href = ' . json_encode($opts['exitUrl'] ?? '/', $flags) . ';';
    $extraJs = $opts['extraJs'] ?? '';
    $appId = JAAS_APP_ID;
    ?>
<div id="jaas-warning" class="alert" hidden></div>
<div id="jaas-container" class="live-player jaas-player"></div>

<script src="https://8x8.vc/<?= $appId ?>/external_api.js"></script>
<script>
(function () {
  var warning = document.getElementById('jaas-warning');

  function warn(text) {
    warning.textContent = text;
    warning.classList.add('alert-error');
    warning.hidden = false;
  }

  // Câmera e microfone só funcionam em HTTPS ou em localhost
  if (!window.isSecureContext) {
    warn('Atenção: acesse por https:// ou http://localhost, senão o navegador bloqueia câmera e microfone.');
  }

  if (typeof JitsiMeetExternalAPI === 'undefined') {
    warn('Não foi possível carregar o JaaS. Verifique sua conexão com a internet.');
    return;
  }

  var options = <?= json_encode($options, $flags) ?>;
  options.parentNode = document.getElementById('jaas-container');
  var api = new JitsiMeetExternalAPI('8x8.vc', options);

  api.addEventListener('readyToClose', function () {
    <?= $onClose ?>
  });

  <?= $extraJs ?>
})();
</script>
<?php
}
