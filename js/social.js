(() => {
	const header = document.getElementById("header-actions");
	const endpoint = header?.dataset.socialEndpoint;
	const csrf = header?.dataset.socialCsrf || "";
	if (!endpoint) return;

	const interval = (Number(header.dataset.socialInterval) || 60) * 1000;
	const reduced = () => window.matchMedia("(prefers-reduced-motion: reduce)").matches;
	const notify = (type, message) => window.HiTeach?.notify?.(type, message);
	const badge = document.querySelector("[data-social-badge]");
	const chatLink = document.getElementById("header-chat");

	/* ------------------------------------------------ balão do cabeçalho */
	const setBadge = (counts) => {
		if (!badge || !counts) return;
		const total = Number(counts.total) || 0;
		const previous = Number(badge.dataset.total || badge.textContent.replace("+", "")) || 0;
		badge.dataset.total = String(total);
		badge.textContent = total > 99 ? "99+" : String(total);
		badge.hidden = total === 0;
		if (chatLink) {
			chatLink.setAttribute(
				"aria-label",
				total > 0 ? `Contatos, conversas e pedidos (${total} novos)` : "Contatos, conversas e pedidos",
			);
		}
		if (total > previous && !reduced()) {
			badge.classList.remove("is-bumped");
			void badge.offsetWidth;
			badge.classList.add("is-bumped");
		}
		document.dispatchEvent(new CustomEvent("hiteach:social", { detail: { badge: counts } }));
	};

	/* ------------------------------------------------ presença dos amigos */
	const applyFriends = (friends) => {
		(friends || []).forEach((friend) => {
			document.querySelectorAll(`[data-presence-user="${friend.id}"]`).forEach((node) => {
				node.className = `presence presence-${friend.key}`;
				const label = node.querySelector(".presence-label");
				if (label) label.textContent = friend.label;
				const seen = node.querySelector(".presence-seen");
				if (seen) seen.textContent = friend.seen || "";
			});
		});
	};

	const applyOwnStatus = (status) => {
		document.querySelectorAll("[data-status-picker] [data-status]").forEach((button) => {
			button.setAttribute("aria-pressed", String(button.dataset.status === status));
		});
	};

	const post = async (data) => {
		const body = new FormData();
		body.append("csrf_token", csrf);
		Object.entries(data).forEach(([key, value]) => body.append(key, value));
		const response = await fetch(endpoint, {
			method: "POST",
			headers: { "X-Requested-With": "fetch", Accept: "application/json" },
			body,
		});
		const payload = await response.json().catch(() => null);
		if (!response.ok || !payload?.ok) throw new Error(payload?.error || "Não foi possível atualizar agora.");
		return payload;
	};

	let lastBeat = 0;
	let beating = false;
	const beat = async () => {
		if (beating) return;
		beating = true;
		try {
			const payload = await post({});
			lastBeat = Date.now();
			setBadge(payload.badge);
			applyFriends(payload.friends);
			applyOwnStatus(payload.status);
		} catch {
			/* sem rede ou sessão expirada: tenta de novo no próximo ciclo */
		} finally {
			beating = false;
		}
	};

	setInterval(() => {
		if (!document.hidden) beat();
	}, interval);
	document.addEventListener("visibilitychange", () => {
		if (!document.hidden && Date.now() - lastBeat > interval / 2) beat();
	});
	window.addEventListener("online", beat);

	/* --------------------------------------------------- troca de status */
	document.querySelectorAll("[data-status-picker]").forEach((form) => {
		form.addEventListener("submit", async (event) => {
			const button = event.submitter;
			if (!button?.dataset.status) return;
			event.preventDefault();
			form.querySelectorAll("button").forEach((item) => (item.disabled = true));
			try {
				const payload = await post({ status: button.dataset.status });
				applyOwnStatus(payload.status);
				setBadge(payload.badge);
			} catch (failure) {
				notify("error", failure.message);
			} finally {
				form.querySelectorAll("button").forEach((item) => (item.disabled = false));
			}
		});
	});

	/* ----------------------------------- conversa: rolar e receber mensagens */
	const list = document.getElementById("message-list");
	if (!list || !list.dataset.dmWith) return;

	const scrollDown = (smooth) => {
		list.scrollTo({ top: list.scrollHeight, behavior: smooth && !reduced() ? "smooth" : "auto" });
	};
	scrollDown(false);

	let lastId = Number(list.dataset.dmLast) || 0;
	let polling = false;
	const poll = async () => {
		if (polling || document.hidden) return;
		polling = true;
		try {
			const response = await fetch(`${list.dataset.dmPoll}&after=${lastId}`, {
				headers: { "X-Requested-With": "fetch", Accept: "application/json" },
			});
			if (response.status === 403) {
				location.href = "contacts.php";
				return;
			}
			const payload = await response.json().catch(() => null);
			if (!response.ok || !payload?.ok) return;
			lastId = Math.max(lastId, Number(payload.last_id) || 0);
			if (payload.state && list.dataset.dmState && payload.state !== list.dataset.dmState) {
				const draft = document.getElementById("reply-text");
				if (window.HiDM?.applyMode(payload.mode)) {
					list.dataset.dmState = payload.state;
				} else if (draft && draft.value.trim() !== "") {
					if (!list.dataset.dmStateWarned) {
						list.dataset.dmStateWarned = "1";
						notify("success", "Esta conversa mudou. Envie ou copie o que escreveu e recarregue a página.");
					}
				} else {
					location.reload();
					return;
				}
			}
			if (!payload.html) return;
			const nearBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 140;
			const holder = document.createElement("template");
			holder.innerHTML = payload.html.trim();
			holder.content.querySelectorAll(".chat-message").forEach((item) => {
				if (list.querySelector(`#${item.id}`)) return;
				item.classList.add("is-new");
				item.addEventListener("animationend", (event) => {
					if (event.target === item) item.classList.remove("is-new");
				});
				list.append(item);
			});
			if (nearBottom) scrollDown(true);
		} catch {
			/* tenta de novo no próximo ciclo */
		} finally {
			polling = false;
		}
	};
	const pollDelay = () => (["awaiting", "incoming"].includes(list.dataset.dmMode) ? 3000 : 8000);
	const pollLoop = async () => {
		await poll();
		setTimeout(pollLoop, pollDelay());
	};
	setTimeout(pollLoop, pollDelay());
	document.addEventListener("visibilitychange", () => {
		if (!document.hidden) poll();
	});

	// O marcador só avança com mensagens do amigo. As minhas (enviadas por aqui) não entram:
	// se uma mensagem dele chegou antes de eu enviar a minha, ela ainda precisa ser buscada.
})();
