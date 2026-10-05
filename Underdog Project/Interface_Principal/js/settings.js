(() => {
	const root = document.documentElement;
	const themeInputs = document.querySelectorAll("[data-theme-value]");
	const motionInput = document.getElementById("switch-reduce-motion");

	const save = (key, value) => {
		try {
			if (value === null) localStorage.removeItem(key);
			else localStorage.setItem(key, value);
		} catch (error) {
		}
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
		motionInput.checked = root.getAttribute("data-motion") === "reduced";
		motionInput.addEventListener("change", () => {
			if (motionInput.checked) root.setAttribute("data-motion", "reduced");
			else root.removeAttribute("data-motion");
			save("hiteach-motion", motionInput.checked ? "reduced" : null);
		});
	}
})();
