/* ============================================================
   Hi Teach — UI Kit (Matérias + Painéis de equipe)
   1. Seções que abrem/fecham com animação  (details.ui-details)
   2. Contador de caracteres e limite de linhas (.ui-field)
   3. Janela de confirmação estilizada       ([data-confirm])
   4. Busca e filtros instantâneos           ([data-ui-filter])
   5. Proteção contra duplo envio de formulários
   Tudo é "progressive enhancement": sem JS as páginas continuam
   utilizáveis (details abre/fecha nativamente, confirm cai no nativo).
   ============================================================ */
(() => {
	"use strict";

	const root = document.documentElement;
	const EASE = "cubic-bezier(0.22, 1, 0.36, 1)";
	const reduced = () =>
		matchMedia("(prefers-reduced-motion: reduce)").matches ||
		root.dataset.motion === "reduced";

	const normalize = (text) =>
		String(text || "")
			.normalize("NFD")
			.replace(/[\u0300-\u036f]/g, "")
			.toLowerCase()
			.trim();

	/* ---------- 1. Seções animadas ---------- */
	const controllers = new WeakMap();

	const initDetails = (details) => {
		const summary = details.querySelector(":scope > summary");
		const body = details.querySelector(":scope > .ui-details-body");
		if (!summary || !body || controllers.has(details)) return;

		details.classList.toggle("is-open", details.open);
		let busy = false;

		const settle = (open) => {
			details.open = open;
			details.classList.toggle("is-open", open);
			body.style.overflow = "";
			busy = false;
		};

		const setOpen = (open) => {
			if (busy || details.classList.contains("is-open") === open) return;
			if (reduced() || typeof body.animate !== "function") {
				settle(open);
				return;
			}
			busy = true;
			details.classList.toggle("is-open", open);
			body.style.overflow = "hidden";

			let frames;
			if (open) {
				details.open = true;
				frames = [
					{ height: "0px", opacity: 0 },
					{ height: `${body.scrollHeight}px`, opacity: 1 },
				];
			} else {
				frames = [
					{ height: `${body.offsetHeight}px`, opacity: 1 },
					{ height: "0px", opacity: 0 },
				];
			}

			const animation = body.animate(frames, {
				duration: open ? 340 : 240,
				easing: EASE,
			});
			animation.onfinish = () => settle(open);
			animation.oncancel = () => settle(open);
		};

		controllers.set(details, setOpen);
		summary.addEventListener("click", (event) => {
			event.preventDefault();
			setOpen(!details.classList.contains("is-open"));
		});
	};

	const openDetails = (details) => {
		const setOpen = controllers.get(details);
		if (setOpen) setOpen(true);
		else details.open = true;
	};

	// Abre a seção quando o link/âncora aponta para ela (ex.: #course-create)
	const openFromHash = () => {
		if (!location.hash || location.hash.length < 2) return;
		let target = null;
		try {
			target = document.getElementById(decodeURIComponent(location.hash.slice(1)));
		} catch (error) {
			return;
		}
		const details = target?.closest?.("details.ui-details");
		if (details) openDetails(details);
	};

	document.addEventListener("click", (event) => {
		const link = event.target.closest?.('a[href^="#"]');
		if (!link || link.getAttribute("href").length < 2) return;
		const target = document.getElementById(link.getAttribute("href").slice(1));
		const details = target?.closest?.("details.ui-details");
		if (details) openDetails(details);
	});
	window.addEventListener("hashchange", openFromHash);

	/* ---------- 2. Campos com contador e limite de linhas ---------- */
	const initField = (field) => {
		const control = field.querySelector("input[maxlength], textarea[maxlength]");
		const top = field.querySelector(".ui-field-top");
		const max = Number(control?.getAttribute("maxlength"));
		if (!control || !top || !max || top.querySelector(".ui-counter")) return;

		const maxLines = Number(control.dataset.maxLines) || 0;
		const counter = document.createElement("small");
		counter.className = "ui-counter";
		top.append(counter);

		const update = () => {
			if (maxLines) {
				const lines = control.value.split("\n");
				if (lines.length > maxLines) {
					control.value = lines.slice(0, maxLines).join("\n");
					control.selectionStart = control.selectionEnd = control.value.length;
				}
			}
			const length = control.value.length;
			counter.textContent = `${length}/${max}`;
			counter.classList.toggle("is-near", length >= max * 0.85);
			counter.classList.toggle("is-full", length >= max);
		};

		control.addEventListener("input", update);
		control.form?.addEventListener("reset", () => setTimeout(update));
		update();
	};

	/* ---------- Utilitário: bloqueio de duplo envio ---------- */
	const markBusy = (form) => {
		if (form.method.toLowerCase() !== "post") return;
		// Desabilita depois do envio ser montado, para não perder name/value do botão clicado.
		setTimeout(() => {
			form.querySelectorAll('button[type="submit"], button:not([type])').forEach((button) => {
				button.disabled = true;
				button.dataset.uiBusy = "1";
			});
		}, 0);
	};

	/* ---------- 3. Janela de confirmação ---------- */
	const dialogSupported =
		typeof HTMLDialogElement === "function" &&
		typeof HTMLDialogElement.prototype.showModal === "function";
	let dialog = null;
	let parts = null;

	const buildDialog = () => {
		dialog = document.createElement("dialog");
		dialog.className = "ui-dialog";
		dialog.setAttribute("aria-labelledby", "ui-dialog-title");
		dialog.setAttribute("aria-describedby", "ui-dialog-text");
		dialog.innerHTML =
			'<div class="ui-dialog-body">' +
			'<h2 class="ui-dialog-title" id="ui-dialog-title"></h2>' +
			'<p class="ui-dialog-text" id="ui-dialog-text"></p>' +
			"</div>" +
			'<div class="ui-dialog-actions">' +
			'<button type="button" class="button button-small" data-ui-no>Cancelar</button>' +
			'<button type="button" class="button button-small" data-ui-yes></button>' +
			"</div>";
		document.body.append(dialog);
		parts = {
			title: dialog.querySelector(".ui-dialog-title"),
			text: dialog.querySelector(".ui-dialog-text"),
			yes: dialog.querySelector("[data-ui-yes]"),
			no: dialog.querySelector("[data-ui-no]"),
		};
	};

	const ask = ({ title, text, yes, tone }) =>
		new Promise((resolve) => {
			if (!dialog) buildDialog();
			parts.title.textContent = title || "Confirmar ação";
			parts.text.textContent = text || "";
			parts.yes.textContent = yes || "Confirmar";
			parts.yes.className =
				"button button-small " + (tone === "danger" ? "button-delete" : "button-primary");

			let done = false;
			const close = (value) => {
				if (done) return;
				done = true;
				const finish = () => {
					dialog.classList.remove("is-closing");
					if (dialog.open) dialog.close();
					resolve(value);
				};
				if (reduced()) {
					finish();
					return;
				}
				dialog.classList.add("is-closing");
				dialog.addEventListener("animationend", finish, { once: true });
				setTimeout(finish, 260);
			};

			parts.yes.onclick = () => close(true);
			parts.no.onclick = () => close(false);
			dialog.oncancel = (event) => {
				event.preventDefault();
				close(false);
			};
			dialog.onclick = (event) => {
				if (event.target === dialog) close(false);
			};

			dialog.showModal();
			parts.no.focus();
		});

	document.addEventListener(
		"submit",
		(event) => {
			const form = event.target;
			if (!(form instanceof HTMLFormElement)) return;

			// Reenvio após confirmação: passa direto e impede o confirm() nativo do main.js.
			if (form.dataset.confirmed === "1") {
				delete form.dataset.confirmed;
				event.stopImmediatePropagation();
				markBusy(form);
				return;
			}

			const submitter = event.submitter;
			const holder = submitter?.dataset.confirm ? submitter : form;
			const text = holder.dataset.confirm;
			if (!text || !dialogSupported) return;

			event.preventDefault();
			event.stopImmediatePropagation();
			ask({
				title: holder.dataset.confirmTitle,
				text,
				yes: holder.dataset.confirmYes,
				tone: holder.dataset.confirmTone,
			}).then((accepted) => {
				if (!accepted) return;
				form.dataset.confirmed = "1";
				try {
					form.requestSubmit(submitter || undefined);
				} catch (error) {
					form.submit();
				}
			});
		},
		true,
	);

	/* ---------- 4. Busca e filtros ---------- */
	const initFilter = (box) => {
		const scope = box.dataset.uiFilter ? document.querySelector(box.dataset.uiFilter) : box;
		if (!scope) return;

		const items = [...scope.querySelectorAll("[data-filter-item]")];
		const empty = scope.querySelector("[data-filter-empty]");
		const input = box.querySelector("[data-filter-input]");
		const pills = [...box.querySelectorAll("[data-filter-tag]")];
		const counter = box.querySelector("[data-filter-count]");
		const texts = new Map(items.map((item) => [item, normalize(item.dataset.filterText || item.textContent)]));

		let query = "";
		let tag = "all";

		const apply = () => {
			let shown = 0;
			items.forEach((item) => {
				const matchText = !query || texts.get(item).includes(query);
				const tags = (item.dataset.filterTags || "").split(" ");
				const matchTag = tag === "all" || tags.includes(tag);
				const visible = matchText && matchTag;
				item.hidden = !visible;
				if (visible) shown += 1;
			});
			if (counter) counter.textContent = String(shown);
			if (empty) empty.hidden = shown > 0;
		};

		input?.addEventListener("input", () => {
			query = normalize(input.value);
			apply();
		});
		pills.forEach((pill) =>
			pill.addEventListener("click", () => {
				tag = pill.dataset.filterTag;
				pills.forEach((other) => {
					const active = other === pill;
					other.setAttribute("aria-pressed", String(active));
					other.classList.toggle("is-active", active);
				});
				apply();
			}),
		);
		apply();
	};

	/* ---------- 5. Proteção contra duplo envio ---------- */
	document.addEventListener("submit", (event) => {
		const form = event.target;
		if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
		markBusy(form);
	});
	window.addEventListener("pageshow", (event) => {
		if (!event.persisted) return;
		document.querySelectorAll("[data-ui-busy]").forEach((button) => {
			button.disabled = false;
			delete button.dataset.uiBusy;
		});
	});

	/* ---------- Inicialização ---------- */
	const init = () => {
		document.querySelectorAll("details.ui-details").forEach(initDetails);
		document.querySelectorAll(".ui-field").forEach(initField);
		document.querySelectorAll("[data-ui-filter]").forEach(initFilter);
		openFromHash();
	};

	window.UIKit = { openDetails, ask };

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", init);
	} else {
		init();
	}
})();
