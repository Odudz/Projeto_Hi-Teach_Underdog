/* ============================================================
   Hi Teach — Navegação dos painéis de equipe (admin / moderador)
   Liga os botões [data-ap-target] da lateral aos painéis
   [data-ap-panel]. Mantém o #id na URL, então os redirecionamentos
   do PHP (ex.: moderator-dashboard.php#mod-courses) continuam
   abrindo a seção certa. Sem JS, todos os painéis ficam visíveis.
   ============================================================ */
(() => {
	"use strict";

	const nav = document.querySelector("[data-ap-nav]");
	const panels = [...document.querySelectorAll("[data-ap-panel]")];
	if (!nav || !panels.length) return;

	const shell = nav.closest(".ap-shell") || nav;
	const buttons = [...nav.querySelectorAll("[data-ap-target]")];
	const byId = new Map(panels.map((panel) => [panel.id, panel]));
	const reduced = () =>
		matchMedia("(prefers-reduced-motion: reduce)").matches ||
		document.documentElement.dataset.motion === "reduced";

	nav.setAttribute("role", "tablist");
	buttons.forEach((button) => {
		const id = button.dataset.apTarget;
		button.setAttribute("role", "tab");
		button.setAttribute("aria-controls", id);
		button.id = `ap-tab-${id}`;
		const panel = byId.get(id);
		if (panel) {
			panel.setAttribute("role", "tabpanel");
			panel.setAttribute("aria-labelledby", button.id);
		}
	});

	let currentId = null;

	const scrollToPanel = () => {
		const outOfView = shell.getBoundingClientRect().top < 0;
		if (outOfView || window.innerWidth <= 960) {
			shell.scrollIntoView({ block: "start", behavior: reduced() ? "auto" : "smooth" });
		}
	};

	const show = (id, { scroll = false, updateHash = true } = {}) => {
		if (!byId.has(id)) return false;
		if (id !== currentId) {
			const animate = currentId !== null && !reduced();
			panels.forEach((panel) => {
				const active = panel.id === id;
				panel.hidden = !active;
				panel.classList.remove("is-entering");
				if (active && animate) {
					void panel.offsetWidth; // reinicia a animação
					panel.classList.add("is-entering");
				}
			});
			buttons.forEach((button) => {
				const active = button.dataset.apTarget === id;
				button.setAttribute("aria-selected", String(active));
				button.tabIndex = active ? 0 : -1;
			});
			currentId = id;
			if (updateHash) history.replaceState(null, "", `#${id}`);
		}
		if (scroll) scrollToPanel();
		return true;
	};

	// Resolve um #hash para o painel que o contém (inclui âncoras internas, como #subject-create)
	const panelFromHash = () => {
		if (location.hash.length < 2) return null;
		let target = null;
		try {
			target = document.getElementById(decodeURIComponent(location.hash.slice(1)));
		} catch (error) {
			return null;
		}
		return target?.closest("[data-ap-panel]")?.id || null;
	};

	buttons.forEach((button) =>
		button.addEventListener("click", () => show(button.dataset.apTarget, { scroll: true })),
	);

	nav.addEventListener("keydown", (event) => {
		const current = buttons.indexOf(document.activeElement);
		if (current < 0) return;
		const last = buttons.length - 1;
		const targets = {
			ArrowDown: current === last ? 0 : current + 1,
			ArrowRight: current === last ? 0 : current + 1,
			ArrowUp: current === 0 ? last : current - 1,
			ArrowLeft: current === 0 ? last : current - 1,
			Home: 0,
			End: last,
		};
		if (!(event.key in targets)) return;
		event.preventDefault();
		const next = buttons[targets[event.key]];
		next.focus();
		show(next.dataset.apTarget);
	});

	// Links internos (cartões do resumo, atalhos) que apontam para um painel
	document.addEventListener("click", (event) => {
		const link = event.target.closest?.('a[href^="#"]');
		if (!link || link.getAttribute("href").length < 2) return;
		const id = link.getAttribute("href").slice(1);
		if (byId.has(id)) show(id, { scroll: true });
	});

	window.addEventListener("hashchange", () => {
		const id = panelFromHash();
		if (id) show(id, { updateHash: false, scroll: true });
	});

	const initial = panelFromHash() || buttons[0]?.dataset.apTarget || panels[0].id;
	show(initial, { updateHash: false });
	if (panelFromHash()) shell.scrollIntoView({ block: "start" });
})();
