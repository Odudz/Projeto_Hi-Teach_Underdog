(() => {
  const frame = document.getElementById('youtube-player');
  if (!frame) return;
  const url = new URL(frame.src);
  url.searchParams.set('origin', location.origin);
  frame.src = url.href;
  window.onYouTubeIframeAPIReady = () => {
    new YT.Player('youtube-player', {
      events: {
        onError: (event) => {
          const message = document.getElementById('youtube-error');
          message.firstChild.textContent = [100, 101, 150].includes(event.data)
            ? 'Este vídeo está indisponível, privado ou não permite reprodução incorporada. '
            : 'Não foi possível reproduzir o vídeo. Verifique a conexão ou abra no YouTube. ';
          message.setAttribute('role', 'alert');
        }
      }
    });
  };
  const script = document.createElement('script');
  script.src = 'https://www.youtube.com/iframe_api';
  document.head.append(script);
})();
