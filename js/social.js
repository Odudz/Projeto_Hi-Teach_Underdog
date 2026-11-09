(() => {
	const header = document.getElementById("header-actions");
	const endpoint = header?.dataset.socialEndpoint;
	const csrf = header?.dataset.socialCsrf || "";
	if (!endpoint) return;

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
		// Sem arquivos: application/x-www-form-urlencoded evita multipart desnecessário.
		const body = new URLSearchParams();
		body.set("csrf_token", csrf);
		Object.entries(data).forEach(([key, value]) => body.set(key, String(value)));
		const url = new URL(endpoint, location.href);
		url.searchParams.set("ajax", "1");
		const response = await fetch(url, {
			method: "POST",
			headers: { Accept: "application/json" },
			body,
		});
		const responseText = await response.text();
		let payload = null;
		try { payload = JSON.parse(responseText); } catch { /* A camada web pode responder HTML. */ }
		if (!response.ok || !payload?.ok) {
			const fallback = response.status === 403
				? "A hospedagem recusou a atualização (HTTP 403)."
				: `Não foi possível atualizar agora (HTTP ${response.status}).`;
			throw new Error(payload?.error || fallback);
		}
		return payload;
	};

	// Uma atualização ao abrir cada página, sem heartbeat recorrente.
	// A hospedagem gratuita não aceita aplicações de chat com consultas automáticas contínuas.
	post({}).then((payload) => {
		setBadge(payload.badge);
		applyFriends(payload.friends);
		applyOwnStatus(payload.status);
	}).catch(() => {});

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

	/* A atualização automática da conversa foi desativada nesta hospedagem.
	 * Recarregue a página para ver mensagens recebidas; isso evita polling contínuo.
	 */

})();
