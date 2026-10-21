(() => {
	const reduced = () =>
		window.matchMedia("(prefers-reduced-motion: reduce)").matches ||
		document.documentElement.dataset.motion === "reduced";
	const notify = (type, message) => window.HiTeach?.notify?.(type, message);

	const copyText = async (text) => {
		try {
			await navigator.clipboard.writeText(text);
			return true;
		} catch {
			const field = document.createElement("textarea");
			field.value = text;
			field.setAttribute("readonly", "");
			field.style.position = "fixed";
			field.style.opacity = "0";
			document.body.append(field);
			field.select();
			let done = false;
			try {
				done = document.execCommand("copy");
			} catch {
				done = false;
			}
			field.remove();
			return done;
		}
	};

	document.addEventListener("click", async (event) => {
		const button = event.target.closest("[data-copy]");
		if (!button) return;
		const done = await copyText(button.dataset.copy);
		if (!done) {
			notify("error", "Não foi possível copiar. Selecione o código e copie manualmente.");
			return;
		}
		const label = button.querySelector("[data-copy-label]");
		const original = label?.dataset.original || label?.textContent;
		if (label) {
			label.dataset.original = original;
			label.textContent = "Copiado!";
		}
		button.classList.remove("is-copied");
		void button.offsetWidth;
		button.classList.add("is-copied");
		clearTimeout(button.copyTimer);
		button.copyTimer = setTimeout(() => {
			button.classList.remove("is-copied");
			if (label) label.textContent = original;
		}, 1600);
	});

	const normalize = (text) =>
		text
			.normalize("NFD")
			.replace(/[\u0300-\u036f]/g, "")
			.toLowerCase();

	document.querySelectorAll("[data-filter]").forEach((input) => {
		const list = document.querySelector(input.dataset.filter);
		const empty = input.dataset.filterEmpty ? document.querySelector(input.dataset.filterEmpty) : null;
		if (!list) return;
		input.addEventListener("input", () => {
			const term = normalize(input.value.trim());
			let shown = 0;
			[...list.children].forEach((item) => {
				const match = !term || normalize(item.dataset.name || "").includes(term);
				item.hidden = !match;
				if (match) shown += 1;
			});
			if (empty) empty.hidden = shown > 0;
		});
	});

	const openMenus = () => document.querySelectorAll("details.dm-menu[open]");
	document.addEventListener("click", (event) => {
		openMenus().forEach((menu) => {
			if (!menu.contains(event.target)) menu.removeAttribute("open");
		});
	});
	document.addEventListener("keydown", (event) => {
		if (event.key !== "Escape") return;
		openMenus().forEach((menu) => {
			menu.removeAttribute("open");
			menu.querySelector("summary")?.focus();
		});
	});

	const tagForm = document.querySelector("[data-tag-form]");
	if (tagForm) {
		const input = tagForm.querySelector("input[name='tag']");
		const error = document.getElementById("tag-error");
		const TAG = /^@?\s*\S.*#\s*\d{1,10}\s*$/;
		const nudge = (element) => {
			if (reduced()) return;
			element.classList.remove("is-nudge");
			void element.offsetWidth;
			element.classList.add("is-nudge");
		};
		input.addEventListener("input", () => {
			input.removeAttribute("aria-invalid");
			error.hidden = true;
		});
		tagForm.addEventListener("submit", (event) => {
			if (TAG.test(input.value.trim())) return;
			event.preventDefault();
			input.setAttribute("aria-invalid", "true");
			error.textContent = "Use o formato nome#número.";
			error.hidden = false;
			nudge(input);
			input.focus();
		});
	}

	const refresh = document.getElementById("contacts-refresh");
	const main = document.getElementById("main-content");
	if (refresh && main) {
		const baseline = Number(main.dataset.badgeTotal) || 0;
		document.addEventListener("hiteach:social", (event) => {
			const total = Number(event.detail?.badge?.total) || 0;
			if (total !== baseline) refresh.hidden = false;
		});
		refresh.addEventListener("click", () => location.reload());
	}

	const list = document.getElementById("message-list");
	const form = document.getElementById("reply-form");
	const locked = document.getElementById("dm-locked");
	const area = document.getElementById("reply-text");
	const sendButton = document.getElementById("reply-form-submit");

	const setLocked = (on) => {
		if (form) form.hidden = on;
		if (locked) {
			locked.hidden = !on;
			const text = locked.querySelector("p");
			if (on && text && locked.dataset.textAwaiting) text.textContent = locked.dataset.textAwaiting;
		}
	};

	window.HiDM = {
		applyMode(mode) {
			if (!list || !form || !locked) return false;
			if (mode === "open" || mode === "first") {
				setLocked(false);
			} else if (mode === "awaiting") {
				setLocked(true);
			} else {
				return false;
			}
			list.dataset.dmMode = mode;
			return true;
		},
	};

	if (list && form && sendButton && area) {
		let wasSending = false;
		let ownBefore = 0;
		const own = () => list.querySelectorAll(".chat-message.is-own").length;
		new MutationObserver(() => {
			const sending = sendButton.classList.contains("is-sending");
			if (sending && !wasSending) {
				wasSending = true;
				ownBefore = own();
				if (list.dataset.dmMode === "first") area.readOnly = true;
			} else if (!sending && wasSending) {
				wasSending = false;
				if (list.dataset.dmMode !== "first") return;
				if (own() > ownBefore) {
					window.HiDM.applyMode("awaiting");
				} else {
					area.readOnly = false;
				}
			}
		}).observe(sendButton, { attributes: true, attributeFilter: ["class"] });
	}

	const showBanner = (image) => {
		const source = image.dataset.banner;
		if (!source) return;
		delete image.dataset.banner;
		image.addEventListener("load", () => image.classList.add("is-loaded"), { once: true });
		image.src = source;
	};
	const banners = [...document.querySelectorAll("img[data-banner]")];
	if (banners.length) {
		if (!("IntersectionObserver" in window)) {
			banners.forEach(showBanner);
		} else {
			const observers = new Map();
			banners.forEach((image) => {
				const root = image.closest("[data-banner-root]");
				if (!observers.has(root)) {
					observers.set(
						root,
						new IntersectionObserver(
							(entries, observer) => {
								entries.forEach((entry) => {
									if (!entry.isIntersecting) return;
									showBanner(entry.target);
									observer.unobserve(entry.target);
								});
							},
							{ root, rootMargin: "60px 0px" },
						),
					);
				}
				observers.get(root).observe(image);
			});
		}
	}
})();
