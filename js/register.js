(() => {
	const form = document.getElementById("register-form");
	if (!form) return;

	const LIMITS = { tags: 10, tagMin: 3, files: 5, fileMb: 5, phraseWords: 3, phraseWordMin: 2 };
	const FILE_EXTENSIONS = ["pdf", "jpg", "jpeg", "png"];
	const MAX_BYTES = LIMITS.fileMb * 1024 * 1024;
	const LEAVE_MS = 180;
	const REDIRECT_MS = 650;
	// campo do servidor -> id do elemento que recebe o foco/erro
	const FIELD_INPUTS = {
		name: "name",
		birth_date: "birth-date",
		cpf: "cpf",
		email: "email",
		phone: "phone",
		bio: "bio",
		password: "password",
		password_confirm: "password-confirm",
		recovery_phrase: "recovery-phrase",
		specializations: "specialization-input",
		validation_proof: "proof-drop",
	};
	const FILE_ICON =
		'<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';

	const $ = (id) => document.getElementById(id);
	const reduced = () =>
		matchMedia("(prefers-reduced-motion: reduce)").matches ||
		document.documentElement.dataset.motion === "reduced";

	const roleSelect = $("role");
	const submitButton = $("register-form-submit");
	const submitLabel = submitButton.textContent;
	const passwordInput = $("password");
	const confirmInput = $("password-confirm");

	/* ---------- Erros por campo ---------- */
	const setError = (field, message) => {
		const input = $(FIELD_INPUTS[field]);
		const box = $(`error-${field}`);
		if (input) input.setAttribute("aria-invalid", "true");
		if (box) {
			box.textContent = message;
			box.hidden = false;
		}
	};
	const clearError = (field) => {
		const input = $(FIELD_INPUTS[field]);
		const box = $(`error-${field}`);
		if (input) input.removeAttribute("aria-invalid");
		if (box) {
			box.hidden = true;
			box.textContent = "";
		}
	};
	const clearAllErrors = () => Object.keys(FIELD_INPUTS).forEach(clearError);

	// Ao mexer em um campo, só o erro dele some
	form.addEventListener("input", (event) => {
		const group = event.target.closest("[data-field]");
		if (group) clearError(group.dataset.field);
	});

	/* ---------- Biografia (limite fixo de 250) ---------- */
	const bio = $("bio");
	const bioCounter = $("bio-counter");
	const bioMax = Number(bio.getAttribute("maxlength")) || 250;
	const updateBioCounter = () => {
		const length = bio.value.length;
		bioCounter.textContent = `${length}/${bioMax}`;
		bioCounter.classList.toggle("is-limit", length >= bioMax);
	};
	bio.addEventListener("input", updateBioCounter);
	updateBioCounter();

	/* ---------- Frase recuperadora (contador de palavras) ---------- */
	const phrase = $("recovery-phrase");
	const phraseCounter = $("recovery-counter");
	const countPhraseWords = (text) =>
		text
			.toLowerCase()
			.split(/\s+/)
			.filter((word) => word.length >= LIMITS.phraseWordMin && /[\p{L}\p{N}]/u.test(word)).length;
	const updatePhraseCounter = () => {
		const words = countPhraseWords(phrase.value);
		phraseCounter.textContent = `${Math.min(words, LIMITS.phraseWords)}/${LIMITS.phraseWords} palavras`;
		phraseCounter.classList.toggle("is-limit", words >= LIMITS.phraseWords);
	};
	phrase.addEventListener("input", updatePhraseCounter);
	updatePhraseCounter();

	/* ---------- Máscara do CPF ---------- */
	const cpf = $("cpf");
	cpf.addEventListener("input", () => {
		const digits = cpf.value.replace(/\D/g, "").slice(0, 11);
		cpf.value = digits
			.replace(/(\d{3})(\d)/, "$1.$2")
			.replace(/(\d{3})(\d)/, "$1.$2")
			.replace(/(\d{3})(\d{1,2})$/, "$1-$2");
	});

	/* ---------- Especializações (badges) ---------- */
	const tagList = $("specialization-list");
	const tagInput = $("specialization-input");
	const tagAdd = $("specialization-add");
	const tagCounter = $("specialization-counter");
	const tags = [];

	const updateTagCounter = () => {
		tagCounter.textContent = `${tags.length}/${LIMITS.tags}`;
		tagCounter.classList.toggle("is-limit", tags.length >= LIMITS.tags);
	};

	const removeTag = (item, value) => {
		if (item.classList.contains("is-leaving")) return;
		const finish = () => {
			const index = tags.indexOf(value);
			if (index >= 0) tags.splice(index, 1);
			item.remove();
			updateTagCounter();
			clearError("specializations");
			tagInput.focus();
		};
		if (reduced()) return finish();
		item.classList.add("is-leaving");
		setTimeout(finish, LEAVE_MS);
	};

	const appendTag = (value, animate) => {
		const item = document.createElement("li");
		item.className = animate ? "tag is-new" : "tag";
		const text = document.createElement("span");
		text.className = "tag-text";
		text.textContent = value;
		const remove = document.createElement("button");
		remove.type = "button";
		remove.className = "tag-remove";
		remove.setAttribute("aria-label", `Remover especialização: ${value}`);
		remove.textContent = "×";
		remove.addEventListener("click", () => removeTag(item, value));
		item.append(text, remove);
		tagList.append(item);
		tags.push(value);
		updateTagCounter();
	};

	// Retorna true se adicionou (ou se não havia nada para adicionar)
	const addTag = (raw) => {
		const value = raw.replace(/\s+/g, " ").trim();
		if (!value) return true;
		if (value.length < LIMITS.tagMin) {
			setError("specializations", `A especialização precisa ter pelo menos ${LIMITS.tagMin} caracteres.`);
			return false;
		}
		if (tags.length >= LIMITS.tags) {
			setError("specializations", `Você pode adicionar no máximo ${LIMITS.tags} especializações.`);
			return false;
		}
		if (tags.some((tag) => tag.toLowerCase() === value.toLowerCase())) {
			setError("specializations", "Você já adicionou essa especialização.");
			return false;
		}
		clearError("specializations");
		appendTag(value, true);
		tagInput.value = "";
		return true;
	};

	tagInput.addEventListener("keydown", (event) => {
		if (event.key !== "Enter") return;
		event.preventDefault();
		addTag(tagInput.value);
	});
	tagAdd.addEventListener("click", () => {
		addTag(tagInput.value);
		tagInput.focus();
	});

	// Especializações que voltaram do servidor (envio sem JavaScript)
	const seed = $("specialization-seed");
	seed.querySelectorAll("input").forEach((input) => appendTag(input.value, false));
	seed.replaceChildren();

	/* ---------- Comprovantes (vários arquivos) ---------- */
	const proofInput = $("validation-proof");
	const proofDrop = $("proof-drop");
	const proofChoose = $("proof-choose");
	const proofList = $("proof-list");
	const proofCounter = $("proof-counter");
	const proofs = []; // { file, item }

	const formatSize = (bytes) =>
		bytes < 1024 * 1024
			? `${Math.max(1, Math.round(bytes / 1024))} KB`
			: `${(bytes / 1024 / 1024).toFixed(1).replace(".", ",")} MB`;

	const updateProofCounter = () => {
		proofCounter.textContent = `${proofs.length}/${LIMITS.files}`;
		proofCounter.classList.toggle("is-limit", proofs.length >= LIMITS.files);
	};

	const dropProof = (entry, { silent = false } = {}) => {
		if (entry.item.classList.contains("is-leaving")) return;
		const finish = () => {
			const index = proofs.indexOf(entry);
			if (index >= 0) proofs.splice(index, 1);
			entry.item.remove();
			updateProofCounter();
			if (!silent) clearError("validation_proof");
		};
		if (reduced()) return finish();
		entry.item.classList.add("is-leaving");
		setTimeout(finish, LEAVE_MS);
	};

	const appendProof = (file) => {
		const item = document.createElement("li");
		item.className = "file-item is-new";
		const icon = document.createElement("span");
		icon.className = "file-icon";
		icon.innerHTML = FILE_ICON;
		const name = document.createElement("span");
		name.className = "file-name";
		name.textContent = file.name;
		name.title = file.name;
		const size = document.createElement("span");
		size.className = "file-size";
		size.textContent = formatSize(file.size);
		const remove = document.createElement("button");
		remove.type = "button";
		remove.className = "file-remove";
		remove.setAttribute("aria-label", `Remover arquivo: ${file.name}`);
		remove.textContent = "×";
		item.append(icon, name, size, remove);
		proofList.append(item);
		const entry = { file, item };
		remove.addEventListener("click", () => dropProof(entry));
		proofs.push(entry);
		updateProofCounter();
	};

	const checkProof = (file) => {
		const label = `“${file.name}”`;
		const extension = file.name.split(".").pop().toLowerCase();
		if (!FILE_EXTENSIONS.includes(extension)) return `${label} não é PDF, JPG ou PNG.`;
		if (file.size === 0) return `${label} está vazio.`;
		if (file.size > MAX_BYTES)
			return `${label} tem ${(file.size / 1024 / 1024).toFixed(1).replace(".", ",")} MB; o limite é ${LIMITS.fileMb} MB por arquivo.`;
		if (proofs.some((p) => p.file.name === file.name && p.file.size === file.size))
			return `${label} já foi adicionado.`;
		if (proofs.length >= LIMITS.files)
			return `Limite de ${LIMITS.files} arquivos atingido; ${label} não foi adicionado.`;
		return null;
	};

	const receiveFiles = (fileList) => {
		const problems = [];
		[...fileList].forEach((file) => {
			const problem = checkProof(file);
			if (problem) problems.push(problem);
			else appendProof(file);
		});
		if (problems.length) setError("validation_proof", problems.join(" "));
		else clearError("validation_proof");
	};

	proofInput.addEventListener("change", () => {
		receiveFiles(proofInput.files);
		proofInput.value = "";
	});

	// Área de arrastar e soltar (mesmo comportamento do editor de imagem do perfil)
	proofChoose.addEventListener("click", (event) => {
		event.stopPropagation();
		proofInput.click();
	});
	proofDrop.addEventListener("click", () => proofInput.click());
	["dragenter", "dragover"].forEach((name) =>
		proofDrop.addEventListener(name, (event) => {
			event.preventDefault();
			proofDrop.classList.add("is-over");
		}),
	);
	["dragleave", "dragend", "drop"].forEach((name) =>
		proofDrop.addEventListener(name, () => proofDrop.classList.remove("is-over")),
	);
	proofDrop.addEventListener("drop", (event) => {
		event.preventDefault();
		if (event.dataTransfer?.files?.length) receiveFiles(event.dataTransfer.files);
	});
	// Soltar um arquivo fora da área não deve abrir o arquivo no navegador
	["dragover", "drop"].forEach((name) =>
		window.addEventListener(name, (event) => {
			if (event.dataTransfer?.types?.includes("Files") && !proofDrop.contains(event.target)) event.preventDefault();
		}),
	);

	/* ---------- Envio sem recarregar a página ---------- */
	const focusField = (field) => {
		const input = $(FIELD_INPUTS[field]);
		if (!input) return;
		input.scrollIntoView({ behavior: reduced() ? "auto" : "smooth", block: "center" });
		input.focus({ preventScroll: true });
	};

	const notifyErrors = (errors, message) => {
		if (!window.HiTeach?.notify) return;
		const fields = Object.keys(FIELD_INPUTS).filter((field) => errors[field]);
		if (!fields.length) {
			window.HiTeach.notify("error", message || "Não foi possível concluir o cadastro. Tente novamente.");
			return;
		}
		window.HiTeach.notify("error", {
			title: fields.length === 1 ? "Corrija este campo:" : "Corrija os campos abaixo:",
			items: fields.map((field) => ({
				text: errors[field],
				onSelect: () => focusField(field),
			})),
		});
	};

	const applyServerErrors = (result) => {
		const errors = result.errors || {};
		Object.entries(errors).forEach(([field, message]) => setError(field, message));

		// Limpa só o que estava errado. Senha inválida: as duas senhas (a confirmação dependia dela).
		// Só a confirmação diferente: apenas a confirmação. Arquivos: só os recusados.
		if (errors.password) {
			passwordInput.value = "";
			confirmInput.value = "";
		} else if (errors.password_confirm) {
			confirmInput.value = "";
		}
		if (Array.isArray(result.files) && result.files.length) {
			const rejected = new Set(result.files.map((issue) => issue.index));
			proofs
				.filter((_, index) => rejected.has(index))
				.forEach((entry) => dropProof(entry, { silent: true }));
		}
		notifyErrors(errors, result.message);
	};

	let busy = false;
	form.addEventListener("submit", async (event) => {
		event.preventDefault();
		if (busy) return;
		const isTeacher = roleSelect.value === "teacher";

		clearAllErrors();
		if (isTeacher && tagInput.value.trim() && !addTag(tagInput.value)) {
			focusField("specializations");
			return;
		}

		const data = new FormData(form);
		["validation_proof[]", "specializations[]", "specialization_input"].forEach((key) => data.delete(key));
		if (isTeacher) {
			tags.forEach((tag) => data.append("specializations[]", tag));
			proofs.forEach(({ file }) => data.append("validation_proof[]", file, file.name));
		}

		busy = true;
		submitButton.disabled = true;
		submitButton.textContent = "Cadastrando…";
		let redirecting = false;
		try {
			const response = await fetch(form.action, {
				method: "POST",
				headers: { "X-Requested-With": "fetch", Accept: "application/json" },
				body: data,
			});
			let result;
			try {
				result = await response.json();
			} catch {
				throw new Error("O servidor respondeu de forma inesperada. Tente novamente.");
			}
			if (result.ok) {
				redirecting = true;
				document.body.classList.add("is-leaving");
				setTimeout(() => {
					location.href = result.redirect;
				}, reduced() ? 0 : REDIRECT_MS);
				return;
			}
			applyServerErrors(result);
		} catch (error) {
			window.HiTeach?.notify(
				"error",
				error instanceof TypeError
					? "Sem conexão com o servidor. Seus dados foram mantidos; tente novamente."
					: error.message,
			);
		} finally {
			if (!redirecting) {
				busy = false;
				submitButton.disabled = false;
				submitButton.textContent = submitLabel;
			}
		}
	});
})();
