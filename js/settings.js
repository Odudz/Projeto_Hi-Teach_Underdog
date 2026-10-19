(() => {
	const root = document.documentElement;
	const themeInputs = document.querySelectorAll("[data-theme-value]");
	const motionInput = document.getElementById("switch-reduce-motion");

	const save = (key, value) => {
		try {
			if (value === null) localStorage.removeItem(key);
			else localStorage.setItem(key, value);
		} catch (error) {}
	};

	const setTheme = (theme) => {
		if (root.getAttribute("data-motion") !== "reduced") {
			root.classList.add("theme-changing");
			setTimeout(() => root.classList.remove("theme-changing"), 700);
		}
		if (theme) root.setAttribute("data-theme", theme);
		else root.removeAttribute("data-theme");
		save("hiteach-theme", theme);
		themeInputs.forEach((input) => {
			input.checked = input.dataset.themeValue === theme;
		});
	};

	const currentTheme = root.getAttribute("data-theme");
	themeInputs.forEach((input) => {
		input.checked = input.dataset.themeValue === currentTheme;
		input.addEventListener("change", () =>
			setTheme(input.checked ? input.dataset.themeValue : null),
		);
	});

	if (motionInput) {
		const mediaQueryMotion = window.matchMedia(
			"(prefers-reduced-motion: reduce)",
		);

		const motionContainer = motionInput.closest(".theme-switch");
		const motionHint = motionContainer
			? motionContainer.querySelector(".switch-hint")
			: null;
		const defaultHintText = motionHint ? motionHint.textContent : "";

		const syncMotion = () => {
			const isSystemReduced = mediaQueryMotion.matches;

			if (isSystemReduced) {
				motionInput.checked = true;
				motionInput.disabled = true;
				root.setAttribute("data-motion", "reduced");

				if (motionHint) {
					motionHint.textContent =
						"Desativado automaticamente pelas configurações de desempenho ou acessibilidade do seu sistema.";
				}
			} else {
				motionInput.disabled = false;

				if (motionHint) {
					motionHint.textContent = defaultHintText;
				}

				let savedMotion = null;
				try {
					savedMotion = localStorage.getItem("hiteach-motion");
				} catch (e) {}

				const isReduced =
					savedMotion === "reduced" ||
					root.getAttribute("data-motion") === "reduced";
				motionInput.checked = isReduced;

				if (isReduced) root.setAttribute("data-motion", "reduced");
				else root.removeAttribute("data-motion");
			}
		};

		syncMotion();

		mediaQueryMotion.addEventListener("change", syncMotion);

		motionInput.addEventListener("change", () => {
			if (motionInput.checked) root.setAttribute("data-motion", "reduced");
			else root.removeAttribute("data-motion");
			save("hiteach-motion", motionInput.checked ? "reduced" : null);
		});
	}
})();
