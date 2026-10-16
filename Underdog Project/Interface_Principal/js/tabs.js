(() => {
	const init = () => {
		const scope = document.querySelector(".main-content");
		if (!scope) return;
		const panels = [...scope.querySelectorAll(":scope > section[data-tab]")];
		if (panels.length < 2) return;

		const list = document.createElement("div");
		list.className = "tabs";
		list.setAttribute("role", "tablist");
		list.setAttribute("aria-label", "Seções do painel");

		const tabs = panels.map((panel, index) => {
			const button = document.createElement("button");
			button.type = "button";
			button.className = "tab";
			button.id = `tab-${panel.id}`;
			button.setAttribute("role", "tab");
			button.setAttribute("aria-controls", panel.id);

			const text = document.createElement("span");
			text.textContent = panel.dataset.tab;
			button.append(text);

			const count = Number(panel.dataset.tabBadge);
			if (count > 0) {
				const badge = document.createElement("span");
				badge.className = "tab-badge";
				badge.textContent = count;
				badge.setAttribute("aria-hidden", "true");
				button.append(badge);
				button.setAttribute("aria-label", `${panel.dataset.tab} (${count})`);
			}

			panel.setAttribute("role", "tabpanel");
			panel.setAttribute("aria-labelledby", button.id);
			button.addEventListener("click", () => select(index));
			return button;
		});

		const select = (index, { focus = false, updateHash = true } = {}) => {
			tabs.forEach((tab, i) => {
				const active = i === index;
				tab.setAttribute("aria-selected", String(active));
				tab.tabIndex = active ? 0 : -1;
				panels[i].hidden = !active;
			});
			if (focus) tabs[index].focus();
			if (updateHash) history.replaceState(null, "", `#${panels[index].id}`);
		};

		list.addEventListener("keydown", (event) => {
			const current = tabs.indexOf(document.activeElement);
			if (current < 0) return;
			const last = tabs.length - 1;
			const targets = {
				ArrowRight: current === last ? 0 : current + 1,
				ArrowLeft: current === 0 ? last : current - 1,
				Home: 0,
				End: last,
			};
			if (!(event.key in targets)) return;
			event.preventDefault();
			select(targets[event.key], { focus: true });
		});

		list.append(...tabs);
		panels[0].before(list);

		const indexFromHash = () =>
			panels.findIndex((panel) => `#${panel.id}` === location.hash);

		window.addEventListener("hashchange", () => {
			const index = indexFromHash();
			if (index >= 0) select(index, { updateHash: false });
		});

		const initial = indexFromHash();
		select(Math.max(initial, 0), { updateHash: false });
		if (initial >= 0) list.scrollIntoView({ block: "start" });
	};

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", init);
	} else {
		init();
	}
})();
