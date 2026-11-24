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
	/* ---------- Tamanho do texto e da interface ---------- */
	const scaleControls = [
		{
			input: "scale-text",
			output: "scale-text-value",
			cssVar: "--text-scale",
			key: "hiteach-text-scale",
			min: 90,
			max: 130,
		},
		{
			input: "scale-ui",
			output: "scale-ui-value",
			cssVar: "--ui-scale",
			key: "hiteach-ui-scale",
			min: 90,
			max: 120,
		},
	];
	const resetButton = document.getElementById("scale-reset");

	const readScale = (control) => {
		try {
			const percent = parseInt(localStorage.getItem(control.key), 10);
			if (Number.isInteger(percent) && percent >= control.min && percent <= control.max)
				return percent;
		} catch (error) {}
		return 100;
	};

	const applyScale = (control, percent) => {
		const slider = document.getElementById(control.input);
		const output = document.getElementById(control.output);
		if (slider) slider.value = String(percent);
		if (output) output.textContent = percent + "%";
		root.style.setProperty(control.cssVar, String(percent / 100));
		save(control.key, percent === 100 ? null : String(percent));
	};

	scaleControls.forEach((control) => {
		const slider = document.getElementById(control.input);
		if (!slider) return;
		applyScale(control, readScale(control));
		// "input" atualiza enquanto arrasta; a escala é aplicada na hora.
		slider.addEventListener("input", () => applyScale(control, Number(slider.value)));
	});

	if (resetButton) {
		resetButton.addEventListener("click", () => {
			scaleControls.forEach((control) => applyScale(control, 100));
		});
	}
})();
