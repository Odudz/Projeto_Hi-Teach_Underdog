(() => {
	try {
		const root = document.documentElement;
		const theme = localStorage.getItem("hiteach-theme");
		if (["light", "dark", "amoled"].includes(theme))
			root.setAttribute("data-theme", theme);
		if (localStorage.getItem("hiteach-motion") === "reduced")
			root.setAttribute("data-motion", "reduced");

		// Escalas de tamanho (aplicadas antes da primeira pintura para não piscar)
		const scales = [
			["hiteach-text-scale", "--text-scale", 90, 130],
			["hiteach-ui-scale", "--ui-scale", 90, 120],
		];
		scales.forEach(([key, cssVar, min, max]) => {
			const percent = parseInt(localStorage.getItem(key), 10);
			if (Number.isInteger(percent) && percent >= min && percent <= max)
				root.style.setProperty(cssVar, String(percent / 100));
		});
	} catch (error) {
	}
})();
