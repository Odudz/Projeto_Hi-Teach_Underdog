(() => {
	const form = document.getElementById("recover-form");
	if (!form) return;

	const $ = (id) => document.getElementById(id);
	const loginEmail = $("email");
	const emailInput = $("recover-email");
	const phraseInput = $("recover-phrase");
	const submitButton = $("recover-submit");
	const submitLabel = submitButton.textContent;
	const errorBox = $("recover-error");
	const formView = $("recover-form-view");
	const successView = $("recover-success");
	const supportLink = $("recover-support-link");

	const FIELDS = {
		email: { input: emailInput, box: $("recover-email-error") },
		recovery_phrase: { input: phraseInput, box: $("recover-phrase-error") },
	};

	const setFieldError = (field, message) => {
		const { input, box } = FIELDS[field];
		input.setAttribute("aria-invalid", "true");
		box.textContent = message;
		box.hidden = false;
	};
	const clearFieldError = (field) => {
		const { input, box } = FIELDS[field];
		input.removeAttribute("aria-invalid");
		box.textContent = "";
		box.hidden = true;
	};
	const showError = (message) => {
		errorBox.textContent = message;
		errorBox.hidden = false;
	};
	const clearErrors = () => {
		Object.keys(FIELDS).forEach(clearFieldError);
		errorBox.hidden = true;
		errorBox.textContent = "";
	};

	// Só o erro do campo mexido some
	Object.entries(FIELDS).forEach(([field, { input }]) =>
		input.addEventListener("input", () => clearFieldError(field)),
	);

	// O link do suporte já leva o e-mail digitado
	const updateSupportLink = () => {
		const base = supportLink.dataset.mailBase;
		const email = emailInput.value.trim() || "(escreva aqui)";
		const body =
			"Olá! Minha conta no Hi Teach está desativada e eu não lembro a minha frase recuperadora.\n\n" +
			`E-mail da conta: ${email}\n` +
			"Nome completo:\nData de nascimento:\n";
		supportLink.href = `${base}&body=${encodeURIComponent(body)}`;
	};
	emailInput.addEventListener("input", updateSupportLink);

	// Ao abrir, aproveita o e-mail que a pessoa já digitou no login
	document.querySelectorAll('[data-modal-open="recover-modal"]').forEach((opener) =>
		opener.addEventListener("click", () => {
			if (!emailInput.value.trim() && loginEmail?.value.trim()) {
				emailInput.value = loginEmail.value.trim();
			}
			updateSupportLink();
		}),
	);
	updateSupportLink();

	// Quando a página já abre o modal sozinha (conta desativada), com e-mail preenchido, o foco vai para a frase
	if (emailInput.value.trim()) setTimeout(() => phraseInput.focus(), 50);

	let busy = false;
	form.addEventListener("submit", async (event) => {
		event.preventDefault();
		if (busy) return;
		clearErrors();

		const email = emailInput.value.trim();
		const phrase = phraseInput.value;
		let invalid = false;
		if (!email) {
			setFieldError("email", "Informe o e-mail da conta que você quer reativar.");
			invalid = true;
		} else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
			setFieldError("email", "Esse e-mail não parece válido. Use o formato nome@exemplo.com.");
			invalid = true;
		}
		if (!phrase.trim()) {
			setFieldError("recovery_phrase", "Escreva a frase recuperadora que você criou no cadastro.");
			invalid = true;
		}
		if (invalid) {
			(email ? phraseInput : emailInput).focus();
			return;
		}

		busy = true;
		submitButton.disabled = true;
		submitButton.textContent = "Verificando…";
		try {
			const response = await fetch(form.action, {
				method: "POST",
				headers: { "X-Requested-With": "fetch", Accept: "application/json" },
				body: new FormData(form),
			});
			let result;
			try {
				result = await response.json();
			} catch {
				throw new Error("O servidor respondeu de forma inesperada. Tente novamente.");
			}

			if (result.ok) {
				if (loginEmail) loginEmail.value = email;
				formView.hidden = true;
				successView.hidden = false;
				$("recover-done").focus();
				return;
			}

			if (result.errors) {
				Object.entries(result.errors).forEach(([field, message]) => FIELDS[field] && setFieldError(field, message));
			} else {
				showError(result.message || "Não foi possível reativar a conta agora.");
			}
			// Só a frase errada é esvaziada; o e-mail continua para a pessoa tentar de novo
			if (!result.errors) phraseInput.value = "";
			(phraseInput.value ? emailInput : phraseInput).focus();
		} catch (error) {
			showError(
				error instanceof TypeError
					? "Sem conexão com o servidor. Seus dados foram mantidos; tente novamente."
					: error.message,
			);
		} finally {
			busy = false;
			submitButton.disabled = false;
			submitButton.textContent = submitLabel;
		}
	});

	// Voltar do sucesso para o login: foca na senha
	$("recover-done").addEventListener("click", () => {
		setTimeout(() => $("password")?.focus(), 80);
	});

	// Reabrir o modal depois de uma recuperação volta ao formulário
	document.addEventListener("click", (event) => {
		const opener = event.target.closest('[data-modal-open="recover-modal"]');
		if (!opener || successView.hidden) return;
		successView.hidden = true;
		formView.hidden = false;
		phraseInput.value = "";
	});
})();
