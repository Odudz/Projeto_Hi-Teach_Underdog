(() => {
	const EMOJIS = [
		"😀", "😃", "😄", "😁", "😆", "😅", "😂", "🤣", "😊", "🙂", "😉", "😍", "🥰", "😘", "😋", "😎",
		"🤩", "🥳", "🤔", "🤨", "😐", "😴", "😢", "😭", "😡", "😱", "🤯", "🥺", "😇", "🤗", "🙄", "😬",
		"👍", "👎", "👏", "🙌", "🙏", "💪", "👀", "🤝", "✌️", "👋", "🤞", "👌", "❤️", "💙", "💚", "💛",
		"🔥", "⭐", "✨", "🎉", "💡", "✅", "❌", "❓", "❗", "📚", "📝", "✏️", "💻", "🎓", "🧠", "🚀",
	];
	const CONFIRM_ID = "chat-confirm-modal";

	const INVISIBLE = /[\u200B-\u200F\u2028-\u202E\u2060-\u2064\u2800\u3164\u115F\u1160\uFEFF\u00AD]/g;
	const LINK_STRONG = /(https?:|ftp:|www\.|:\/\/|mailto:|wa\.me|bit\.ly|t\.me|discord\.gg|tinyurl)/;
	const LINK_EMAIL = /[a-z0-9._%+\-]+@[a-z0-9\-]+\.[a-z]{2,}/;
	const LINK_DOMAIN = /[a-z0-9\-]+\.(?:com|net|org|info|biz|xyz|site|online|shop|store|click|vip|icu|br|ru|cn)(?![a-z0-9])/;
	const EASE = "cubic-bezier(0.22, 1, 0.36, 1)";

	const size = (text) => Array.from(text).length;
	const clip = (text, max) => Array.from(text).slice(0, max).join("");
	const reduced = () => window.matchMedia("(prefers-reduced-motion: reduce)").matches;
	const notify = (type, message) => {
		if (window.HiTeach?.notify) window.HiTeach.notify(type, message);
	};

	const tidy = (text) =>
		text
			.replace(/\r\n?/g, "\n")
			.replace(INVISIBLE, "")
			.replace(/[^\S\n]+/g, " ")
			.replace(/ ?\n ?/g, "\n")
			.replace(/\n{2,}/g, "\n")
			.replace(/^\s+/, "");

	const cap = (text, max, lines) => clip(text.split("\n").slice(0, lines).join("\n"), max);

	const hasLink = (text) => {
		const flat = text.toLowerCase().replace(/[\s\u200B-\u200F]+/g, "");
		return LINK_STRONG.test(flat) || LINK_EMAIL.test(flat) || LINK_DOMAIN.test(flat);
	};

	const checkMessage = (value, max, lines) => {
		if (!value) return "A mensagem não pode ficar vazia.";
		if (size(value) > max) return `A mensagem pode ter no máximo ${max} caracteres.`;
		if (value.split("\n").length > lines) return `A mensagem pode ter no máximo ${lines} linhas.`;
		if (hasLink(value)) return "Links e endereços de sites não são permitidos nas mensagens.";
		return "";
	};

	const nudge = (element) => {
		if (reduced()) return;
		element.classList.remove("is-nudge");
		void element.offsetWidth;
		element.classList.add("is-nudge");
	};

	const bindLimit = (textarea, counter, limit, lines) => {
		const sync = () => {
			const raw = textarea.value;
			const caret = textarea.selectionStart ?? raw.length;
			const cleaned = tidy(raw);
			const next = cap(cleaned, limit, lines);
			if (next !== cleaned) nudge(textarea);
			if (next !== raw) {
				const head = cap(tidy(raw.slice(0, caret)), limit, lines);
				textarea.value = next;
				const position = Math.min(head.length, next.length);
				textarea.setSelectionRange(position, position);
			}
			const total = size(textarea.value);
			if (counter) {
				counter.textContent = `${total}/${limit}`;
				counter.classList.toggle("is-limit", total >= limit);
			}
		};
		textarea.addEventListener("input", sync);
		textarea.addEventListener("animationend", () => textarea.classList.remove("is-nudge"));
		sync();
		return sync;
	};

	const morph = (item, mutate, duration = 340) => {
		if (reduced()) {
			mutate();
			return Promise.resolve();
		}
		const bubble = item.querySelector(".chat-bubble");
		item.getAnimations().forEach((animation) => animation.cancel());
		bubble.getAnimations().forEach((animation) => animation.cancel());
		const fromHeight = bubble.getBoundingClientRect().height;
		const fromWidth = item.getBoundingClientRect().width;
		mutate();
		const toHeight = bubble.getBoundingClientRect().height;
		const toWidth = item.getBoundingClientRect().width;
		bubble.style.overflow = "hidden";
		const options = { duration, easing: EASE };
		const heightAnimation = bubble.animate([{ height: `${fromHeight}px` }, { height: `${toHeight}px` }], options);
		const widthAnimation = item.animate([{ width: `${fromWidth}px` }, { width: `${toWidth}px` }], options);
		return Promise.allSettled([heightAnimation.finished, widthAnimation.finished]).then(() => {
			bubble.style.overflow = "";
		});
	};

	const panels = new Set();
	const closePanels = (except) => {
		panels.forEach((entry) => {
			if (entry.panel === except) return;
			entry.panel.hidden = true;
			entry.toggle.setAttribute("aria-expanded", "false");
		});
	};
	document.addEventListener("click", (event) => {
		if (event.target.closest(".emoji-wrap")) return;
		closePanels();
	});
	document.addEventListener("keydown", (event) => {
		if (event.key === "Escape") closePanels();
	});

	const attachEmoji = (toggle, textarea, limit) => {
		const panel = document.createElement("div");
		panel.className = "emoji-panel";
		panel.hidden = true;
		panel.setAttribute("role", "group");
		panel.setAttribute("aria-label", "Escolher emoji");
		EMOJIS.forEach((emoji) => {
			const item = document.createElement("button");
			item.type = "button";
			item.className = "emoji-item";
			item.textContent = emoji;
			item.setAttribute("aria-label", `Emoji ${emoji}`);
			item.addEventListener("click", () => {
				const start = textarea.selectionStart ?? textarea.value.length;
				const end = textarea.selectionEnd ?? start;
				const room = limit - size(textarea.value) + size(textarea.value.slice(start, end));
				if (size(emoji) > room) {
					textarea.focus();
					return;
				}
				textarea.setRangeText(emoji, start, end, "end");
				textarea.dispatchEvent(new Event("input", { bubbles: true }));
				textarea.focus();
			});
			panel.append(item);
		});
		toggle.after(panel);
		const entry = { panel, toggle };
		panels.add(entry);
		toggle.addEventListener("click", () => {
			const open = panel.hidden;
			closePanels(panel);
			panel.hidden = !open;
			toggle.setAttribute("aria-expanded", String(open));
		});
	};

	let confirmOverlay = null;
	let confirmOpener = null;
	let confirmResolve = null;

	const buildConfirm = () => {
		confirmOverlay = document.createElement("div");
		confirmOverlay.id = CONFIRM_ID;
		confirmOverlay.className = "modal-overlay";
		confirmOverlay.innerHTML =
			'<section class="modal modal-confirm" role="alertdialog" aria-modal="true" aria-labelledby="chat-confirm-title" aria-describedby="chat-confirm-text">' +
			'<header class="modal-header"><h2 id="chat-confirm-title" class="modal-title">Confirmar exclusão</h2>' +
			'<button type="button" class="modal-close" data-modal-close aria-label="Fechar">×</button></header>' +
			'<div class="confirm-body"><p id="chat-confirm-text" class="confirm-text"></p>' +
			'<div class="confirm-actions"><button type="button" class="button" data-modal-close>Cancelar</button>' +
			'<button type="button" class="button button-delete" data-chat-confirm-yes>Sim, excluir</button></div></div></section>';
		document.body.append(confirmOverlay);

		confirmOpener = document.createElement("button");
		confirmOpener.type = "button";
		confirmOpener.hidden = true;
		confirmOpener.dataset.modalOpen = CONFIRM_ID;
		document.body.append(confirmOpener);

		confirmOverlay.querySelector("[data-chat-confirm-yes]").addEventListener("click", () => {
			const resolve = confirmResolve;
			confirmResolve = null;
			if (resolve) resolve(true);
			confirmOverlay.querySelector("[data-modal-close]").click();
		});
		new MutationObserver(() => {
			if (confirmOverlay.classList.contains("is-open") || !confirmResolve) return;
			const resolve = confirmResolve;
			confirmResolve = null;
			resolve(false);
		}).observe(confirmOverlay, { attributes: true, attributeFilter: ["class"] });
	};

	const askConfirm = (message) =>
		new Promise((resolve) => {
			if (!confirmOverlay) buildConfirm();
			confirmOverlay.querySelector("#chat-confirm-text").textContent = message;
			confirmResolve = resolve;
			confirmOpener.click();
		});

	document.addEventListener("submit", async (event) => {
		const form = event.target;
		if (!(form instanceof HTMLFormElement) || form.dataset.confirmed === "1") return;
		const submitter = event.submitter;
		const message = submitter?.dataset.confirm || form.dataset.confirm;
		if (!message) return;
		event.preventDefault();
		if (await askConfirm(message)) {
			form.dataset.confirmed = "1";
			form.requestSubmit(submitter || undefined);
		}
	});

	const list = document.getElementById("message-list");
	const limit = Number(list?.dataset.limit) || 280;
	const maxLines = Number(list?.dataset.maxLines) || 5;
	const endpoint = list?.dataset.action || "";
	const csrf = list?.dataset.csrf || "";
	const messages = [];

	const parseResponse = async (response) => {
		let data = null;
		try {
			data = await response.json();
		} catch {
			throw new Error("O servidor respondeu de forma inesperada. Tente novamente.");
		}
		if (!response.ok || !data?.ok) throw new Error(data?.error || "Não foi possível concluir a ação.");
		return data;
	};

	const send = async (url, body) => {
		try {
			const response = await fetch(url, {
				method: "POST",
				headers: { "X-Requested-With": "fetch", Accept: "application/json" },
				body,
			});
			return await parseResponse(response);
		} catch (failure) {
			if (failure instanceof TypeError) throw new Error("Sem conexão com o servidor. Tente de novo.");
			throw failure;
		}
	};

	const bumpReplies = (delta) => {
		const stat = document.querySelector("[data-stat-replies]");
		if (!stat) return;
		stat.textContent = String(Math.max(0, (Number(stat.textContent) || 0) + delta));
		stat.classList.remove("is-bumped");
		void stat.offsetWidth;
		stat.classList.add("is-bumped");
	};

	const tidyActions = (item) => {
		const actions = item.querySelector(".chat-actions");
		if (actions && !actions.children.length) actions.remove();
	};

	const register = (item) => {
		const now = Date.now();
		const editLeft = Number(item.dataset.editLeft) || 0;
		const deleteLeft = Number(item.dataset.deleteLeft) || 0;
		messages.push({
			item,
			editUntil: editLeft > 0 ? now + editLeft * 1000 : 0,
			deleteUntil: deleteLeft > 0 ? now + deleteLeft * 1000 : 0,
		});
	};

	const tick = () => {
		const now = Date.now();
		messages.forEach((entry) => {
			if (entry.editUntil && now >= entry.editUntil) {
				entry.editUntil = 0;
				if (!entry.item.classList.contains("is-editing")) {
					entry.item.querySelector("[data-edit]")?.remove();
					tidyActions(entry.item);
				}
			}
			if (entry.deleteUntil && now >= entry.deleteUntil) {
				entry.deleteUntil = 0;
				entry.item.querySelector(".chat-delete-form")?.remove();
				tidyActions(entry.item);
			}
		});
	};

	const reveal = (element) => {
		element.scrollIntoView({ block: "nearest", behavior: reduced() ? "auto" : "smooth" });
	};

	const replyForm = document.getElementById("reply-form");
	if (replyForm && list) {
		const textarea = replyForm.querySelector("textarea");
		const counter = document.getElementById("reply-counter");
		const errorBox = document.getElementById("reply-error");
		const submitButton = document.getElementById("reply-form-submit");
		const files = replyForm.querySelector('input[type="file"]');
		const sync = bindLimit(textarea, counter, limit, maxLines);
		const toggle = replyForm.querySelector(".emoji-toggle");
		if (toggle) attachEmoji(toggle, textarea, limit);

		const showError = (message) => {
			errorBox.textContent = message;
			errorBox.hidden = false;
			nudge(errorBox);
		};

		let sending = false;
		const sendReply = async () => {
			if (sending) return;
			errorBox.hidden = true;
			const value = tidy(textarea.value).trim();
			const problem = checkMessage(value, limit, maxLines);
			if (problem) {
				showError(problem);
				notify("error", problem);
				textarea.focus();
				return;
			}
			sending = true;
			submitButton.disabled = true;
			submitButton.classList.add("is-sending");
			submitButton.textContent = "Enviando…";
			try {
				const body = new FormData(replyForm);
				body.set("body", value);
				const data = await send(replyForm.getAttribute("action") || endpoint, body);
				const holder = document.createElement("template");
				holder.innerHTML = data.html.trim();
				const item = holder.content.firstElementChild;
				if (!item) throw new Error("Não foi possível exibir a mensagem enviada.");
				item.classList.add("is-new");
				item.addEventListener("animationend", (event) => {
					if (event.target === item) item.classList.remove("is-new");
				});
				list.append(item);
				register(item);
				bumpReplies(1);
				textarea.value = "";
				if (files) files.value = "";
				sync();
				reveal(item);
				textarea.focus({ preventScroll: true });
			} catch (failure) {
				showError(failure.message);
				notify("error", failure.message);
			} finally {
				sending = false;
				submitButton.disabled = false;
				submitButton.classList.remove("is-sending");
				submitButton.textContent = "Enviar";
			}
		};

		replyForm.addEventListener("submit", (event) => {
			event.preventDefault();
			event.stopImmediatePropagation();
			sendReply();
		});
		textarea.addEventListener("keydown", (event) => {
			if ((event.ctrlKey || event.metaKey) && event.key === "Enter") {
				event.preventDefault();
				sendReply();
			}
		});
	}

	if (!list) return;

	list.querySelectorAll(".chat-message[data-post-id]").forEach(register);
	setInterval(tick, 1000);

	const removeMessage = (item) => {
		const index = messages.findIndex((entry) => entry.item === item);
		if (index >= 0) messages.splice(index, 1);
		const finish = () => item.remove();
		if (reduced()) {
			finish();
			return;
		}
		const height = item.getBoundingClientRect().height;
		const gap = parseFloat(getComputedStyle(list).rowGap) || 0;
		item.style.overflow = "hidden";
		const animation = item.animate(
			[
				{ opacity: 1, height: `${height}px`, marginBottom: "0px", transform: "scale(1)" },
				{ opacity: 0, height: "0px", marginBottom: `${-gap}px`, transform: "scale(0.92)" },
			],
			{ duration: 320, easing: EASE, fill: "forwards" },
		);
		animation.finished.then(finish, finish);
	};

	window.addEventListener(
		"submit",
		async (event) => {
			const form = event.target;
			if (!(form instanceof HTMLFormElement) || !form.classList.contains("chat-delete-form")) return;
			event.preventDefault();
			event.stopImmediatePropagation();
			if (form.dataset.busy === "1") return;
			if (!(await askConfirm(form.dataset.confirm || "Excluir esta mensagem?"))) return;
			form.dataset.busy = "1";
			const item = form.closest(".chat-message");
			const button = form.querySelector("button");
			if (button) button.disabled = true;
			try {
				await send(form.getAttribute("action") || endpoint, new FormData(form));
				bumpReplies(-1);
				removeMessage(item);
			} catch (failure) {
				notify("error", failure.message);
				if (button) button.disabled = false;
			} finally {
				delete form.dataset.busy;
			}
		},
		true,
	);

	const openEditor = (entry) => {
		const { item } = entry;
		if (item.classList.contains("is-editing")) return;
		const text = item.querySelector("[data-text]");
		const actions = item.querySelector(".chat-actions");
		const original = text.textContent;

		const box = document.createElement("div");
		box.className = "chat-edit";
		const area = document.createElement("textarea");
		area.className = "form-input chat-input";
		area.value = original;
		area.setAttribute("aria-label", "Editar mensagem");
		const error = document.createElement("p");
		error.className = "chat-edit-error";
		error.setAttribute("role", "alert");
		error.hidden = true;

		const tools = document.createElement("div");
		tools.className = "chat-tools";
		const wrap = document.createElement("div");
		wrap.className = "emoji-wrap";
		const toggle = document.createElement("button");
		toggle.type = "button";
		toggle.className = "emoji-toggle";
		toggle.textContent = "😊";
		toggle.setAttribute("aria-label", "Inserir emoji");
		toggle.setAttribute("aria-haspopup", "true");
		toggle.setAttribute("aria-expanded", "false");
		wrap.append(toggle);
		const counter = document.createElement("small");
		counter.className = "field-counter";
		counter.setAttribute("aria-live", "polite");
		tools.append(wrap, counter);

		const buttons = document.createElement("div");
		buttons.className = "chat-edit-buttons";
		const cancel = document.createElement("button");
		cancel.type = "button";
		cancel.className = "button button-small";
		cancel.textContent = "Cancelar";
		const save = document.createElement("button");
		save.type = "button";
		save.className = "button button-primary button-small";
		save.textContent = "Salvar";
		buttons.append(cancel, save);

		box.append(area, tools, error, buttons);
		bindLimit(area, counter, limit, maxLines);
		attachEmoji(toggle, area, limit);

		morph(item, () => {
			text.hidden = true;
			if (actions) actions.hidden = true;
			text.after(box);
			item.classList.add("is-editing");
		}).then(() => {
			area.focus({ preventScroll: true });
			area.setSelectionRange(area.value.length, area.value.length);
		});
		reveal(item);

		const close = () => {
			panels.forEach((panelEntry) => {
				if (wrap.contains(panelEntry.toggle)) panels.delete(panelEntry);
			});
			box.classList.add("is-leaving");
			morph(item, () => {
				box.remove();
				text.hidden = false;
				if (actions) actions.hidden = false;
				item.classList.remove("is-editing");
			}).then(tick);
		};

		let busy = false;
		const submit = async () => {
			if (busy) return;
			error.hidden = true;
			const value = tidy(area.value).trim();
			const problem = checkMessage(value, limit, maxLines);
			if (problem) {
				error.textContent = problem;
				error.hidden = false;
				nudge(error);
				return;
			}
			busy = true;
			save.disabled = true;
			save.textContent = "Salvando…";
			try {
				const body = new FormData();
				body.append("action", "edit_post");
				body.append("post_id", item.dataset.postId);
				body.append("body", value);
				body.append("csrf_token", csrf);
				const data = await send(endpoint, body);
				text.textContent = data.body;
				const mark = item.querySelector("[data-edited]");
				if (mark && data.edited) {
					mark.hidden = false;
					mark.title = "Editada agora";
				}
				close();
			} catch (failure) {
				error.textContent = failure.message;
				error.hidden = false;
				nudge(error);
			} finally {
				busy = false;
				save.disabled = false;
				save.textContent = "Salvar";
			}
		};

		save.addEventListener("click", submit);
		cancel.addEventListener("click", close);
		area.addEventListener("keydown", (event) => {
			if (event.key === "Escape" && ![...panels].some((candidate) => !candidate.panel.hidden)) close();
			if ((event.ctrlKey || event.metaKey) && event.key === "Enter") {
				event.preventDefault();
				submit();
			}
		});
	};

	list.addEventListener("click", (event) => {
		const button = event.target.closest("[data-edit]");
		if (!button) return;
		const entry = messages.find((candidate) => candidate.item.contains(button));
		if (entry) openEditor(entry);
	});
})();
