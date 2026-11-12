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
				const action = new URL(edit.getAttribute("action"), location.href);
				action.searchParams.set("ajax", "1");
				const response = await fetch(action, {
					method: "POST",
					headers: { Accept: "application/json" },
					body: new URLSearchParams(new FormData(edit)),
				});
				const responseText = await response.text();
				let data = null;
				try { data = JSON.parse(responseText); } catch { /* A hospedagem pode devolver HTML. */ }
				if (!response.ok || !data?.ok) {
					const fallback = response.status === 403
						? "A hospedagem recusou a edição (HTTP 403/OpenResty)."
						: `Não foi possível salvar agora (HTTP ${response.status}).`;
					throw new Error(data?.error || fallback);
				}
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

	// Atualização automática desativada: nesta hospedagem, novas respostas aparecem
	// ao recarregar a página. Isso evita consultas periódicas em segundo plano.

})();
