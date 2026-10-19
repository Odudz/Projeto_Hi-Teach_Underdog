(() => {
	try {
		const root = document.documentElement;
		const theme = localStorage.getItem("hiteach-theme");
		if (["light", "dark", "amoled"].includes(theme))
			root.setAttribute("data-theme", theme);
		if (localStorage.getItem("hiteach-motion") === "reduced")
			root.setAttribute("data-motion", "reduced");
	} catch (error) {
	}
})();
