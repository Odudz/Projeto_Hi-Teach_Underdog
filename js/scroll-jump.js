(() => {
	const wrap = document.getElementById("scroll-jump");
	const up = document.getElementById("scroll-up");
	const down = document.getElementById("scroll-down");
	if (!wrap || !up || !down) return;

	// Caixa de resposta (o formulário em si, para medir só o retângulo visível do input)
	const composer = document.querySelector("#topic-reply .write-sheet") || document.getElementById("topic-reply");
	const GAP = 16;
	const EDGE = 12;

	const reduced = () =>
		window.matchMedia("(prefers-reduced-motion: reduce)").matches ||
		document.documentElement.dataset.motion === "reduced";

	// Limiares: a seta de subir aparece depois de rolar um pouco; a de descer só
	// quando ainda existem mensagens abaixo da tela.
	const UP_THRESHOLD = 240;
	const DOWN_THRESHOLD = 120;
	let frame = 0;

	// Posiciona os botões do lado direito da caixa. Se não houver espaço lateral
	// (telas estreitas), ficam acima dela, alinhados à borda direita. Como a
	// posição é recalculada a partir do retângulo atual da caixa, eles acompanham
	// a expansão e não se sobrepõem a ela.
	const place = () => {
		if (!composer) return;
		const rect = composer.getBoundingClientRect();
		const viewportWidth = document.documentElement.clientWidth;
		const buttonWidth = wrap.offsetWidth || 48;
		const sideRoom = viewportWidth - rect.right;

		wrap.style.left = "";
		wrap.style.right = "";
		wrap.style.top = "";
		wrap.style.bottom = "";

		if (sideRoom >= buttonWidth + GAP + EDGE) {
			wrap.dataset.mode = "side";
			wrap.style.left = `${rect.right + GAP}px`;
			wrap.style.bottom = `${window.innerHeight - rect.bottom}px`;
		} else {
			wrap.dataset.mode = "above";
			wrap.style.right = `${Math.max(EDGE, viewportWidth - rect.right)}px`;
			wrap.style.bottom = `${window.innerHeight - rect.top + EDGE}px`;
		}
	};

	const setVisible = (button, visible) => {
		button.classList.toggle("is-visible", visible);
		button.toggleAttribute("inert", !visible);
	};

	const update = () => {
		frame = 0;
		const y = window.scrollY;
		const remaining = document.documentElement.scrollHeight - window.innerHeight - y;
		setVisible(up, y > UP_THRESHOLD);
		setVisible(down, remaining > DOWN_THRESHOLD);
		place();
	};

	const schedule = () => {
		if (!frame) frame = window.requestAnimationFrame(update);
	};

	const go = (top) => window.scrollTo({ top, behavior: reduced() ? "auto" : "smooth" });

	up.addEventListener("click", () => go(0));
	down.addEventListener("click", () => go(document.documentElement.scrollHeight));
	window.addEventListener("scroll", schedule, { passive: true });
	window.addEventListener("resize", schedule);
	if (window.ResizeObserver && composer) {
		new ResizeObserver(schedule).observe(composer);
	}

	update();
})();
