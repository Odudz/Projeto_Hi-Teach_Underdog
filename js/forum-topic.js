(() => {
	const reduced = () =>
		window.matchMedia("(prefers-reduced-motion: reduce)").matches ||
		document.documentElement.dataset.motion === "reduced";
	const notify = (type, message) => window.HiTeach?.notify?.(type, message);

	const header = document.getElementById("topic-header");
	const list = document.getElementById("message-list");

	const desc = document.querySelector("[data-topic-desc]");
	const more = document.querySelector("[data-topic-more]");
	if (desc && more) {
		more.addEventListener("click", () => {
			const open = desc.classList.toggle("is-clamped") === false;
			more.textContent = open ? "Ver menos" : "Ver tudo";
			more.setAttribute("aria-expanded", String(open));
		});
	}

	const view = document.getElementById("topic-view");
	const edit = document.getElementById("topic-edit");
	const tools = document.getElementById("topic-moderation");
	if (view && edit) {
		const titleField = edit.elements.title;
		const bodyField = edit.elements.body;
		const error = edit.querySelector("[data-edit-error]");
		const save = edit.querySelector("[data-edit-save]");
		const counters = {
			title: edit.querySelector('[data-count="title"]'),
			body: edit.querySelector('[data-count="body"]'),
		};
		const initial = { title: titleField.value, body: bodyField.value };

		const count = (field, counter) => {
			const total = Array.from(field.value).length;
			counter.textContent = `${total}/${counter.dataset.max}`;
			counter.classList.toggle("is-limit", total >= Number(counter.dataset.max));
		};
		const sync = () => {
			count(titleField, counters.title);
			count(bodyField, counters.body);
		};
		titleField.addEventListener("input", sync);
		bodyField.addEventListener("input", sync);
		sync();

		const toggle = (editing) => {
			view.hidden = editing;
			edit.hidden = !editing;
			if (tools) tools.hidden = editing;
			if (editing) {
				error.hidden = true;
				titleField.focus();
				titleField.setSelectionRange(titleField.value.length, titleField.value.length);
			}
		};

		document.querySelector("[data-edit-open]")?.addEventListener("click", () => toggle(true));
		edit.querySelector("[data-edit-cancel]").addEventListener("click", () => {
			titleField.value = initial.title;
			bodyField.value = initial.body;
			sync();
			toggle(false);
		});

		edit.addEventListener("submit", async (event) => {
			event.preventDefault();
			if (save.disabled) return;
			error.hidden = true;
			save.disabled = true;
			save.textContent = "Salvando…";
			try {
				const response = await fetch(edit.getAttribute("action"), {
					method: "POST",
					headers: { "X-Requested-With": "fetch", Accept: "application/json" },
					body: new FormData(edit),
				});
				const data = await response.json().catch(() => null);
				if (!response.ok || !data?.ok) throw new Error(data?.error || "Não foi possível salvar agora.");
				document.querySelector("[data-topic-title]").textContent = data.title;
				if (desc) desc.textContent = data.body;
				document.title = `${data.title} | Hi Teach`;
				titleField.value = data.title;
				bodyField.value = data.body;
				initial.title = data.title;
				initial.body = data.body;
				sync();
				toggle(false);
				notify("success", "Fórum atualizado.");
			} catch (failure) {
				error.textContent = failure instanceof TypeError ? "Sem conexão com o servidor." : failure.message;
				error.hidden = false;
			} finally {
				save.disabled = false;
				save.textContent = "Salvar";
			}
		});
	}

	if (!list || !list.dataset.topicPoll) return;

	const lastId = () =>
		[...list.querySelectorAll(".chat-message[data-post-id]")].reduce(
			(max, item) => Math.max(max, Number(item.dataset.postId) || 0),
			0,
		);
	if (!location.hash.startsWith("#post-")) list.scrollTop = list.scrollHeight;
	const repliesNode = document.querySelector("[data-stat-replies]");
	const peopleNode = document.querySelector("[data-stat-people]");
	const closedNow = header?.dataset.closed === "1";
	let polling = false;

	const poll = async () => {
		if (polling || document.hidden) return;
		polling = true;
		try {
			const response = await fetch(`${list.dataset.topicPoll}&after=${lastId()}`, {
				headers: { "X-Requested-With": "fetch", Accept: "application/json" },
			});
			const data = await response.json().catch(() => null);
			if (!response.ok || !data?.ok) return;

			if (data.html) {
				const nearBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 140;
				const holder = document.createElement("template");
				holder.innerHTML = data.html.trim();
				let added = 0;
				holder.content.querySelectorAll(".chat-message").forEach((item) => {
					if (list.querySelector(`#${item.id}`)) return;
					item.classList.add("is-new");
					item.addEventListener("animationend", (event) => {
						if (event.target === item) item.classList.remove("is-new");
					});
					list.append(item);
					added += 1;
				});
				if (added) {
					list.querySelector("[data-topic-empty]")?.remove();
					if (nearBottom) list.scrollTo({ top: list.scrollHeight, behavior: reduced() ? "auto" : "smooth" });
				}
			}
			if (repliesNode && Number(repliesNode.textContent) !== data.replies) repliesNode.textContent = String(data.replies);
			if (peopleNode && Number(peopleNode.textContent) !== data.people) peopleNode.textContent = String(data.people);

			if (data.closed !== closedNow) {
				const draft = document.getElementById("reply-text");
				if (!draft || draft.value.trim() === "") location.reload();
			}
		} catch {
			polling = false;
		} finally {
			polling = false;
		}
	};

	setInterval(poll, 6000);
	document.addEventListener("visibilitychange", () => {
		if (!document.hidden) poll();
	});
})();
