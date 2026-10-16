(() => {
	const root = document.documentElement;
	const FOCUSABLE =
		'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
	const BASE_Z_INDEX = 2000;
	const stack = [];

	const topEntry = () => stack[stack.length - 1];

	const openModal = (id, trigger) => {
		const modal = document.getElementById(id);
		if (!modal || stack.some((entry) => entry.modal === modal)) return;
		stack.push({ modal, trigger: trigger || null });
		modal.style.zIndex = String(BASE_Z_INDEX + stack.length);
		modal.classList.add("is-open");
		root.classList.add("is-locked");
		if (trigger) trigger.setAttribute("aria-expanded", "true");
		const first = modal.querySelector(FOCUSABLE);
		if (first) first.focus();
	};

	const closeModal = () => {
		const entry = stack.pop();
		if (!entry) return;
		entry.modal.classList.remove("is-open");
		if (!stack.length) root.classList.remove("is-locked");
		if (entry.trigger) {
			entry.trigger.setAttribute("aria-expanded", "false");
			entry.trigger.focus();
		}
	};

	document.addEventListener("click", (event) => {
		const opener = event.target.closest("[data-modal-open]");
		if (opener) {
			openModal(opener.dataset.modalOpen, opener);
			return;
		}
		const current = topEntry();
		if (!current) return;
		const closer = event.target.closest("[data-modal-close]");
		if (
			event.target === current.modal ||
			(closer && current.modal.contains(closer))
		)
			closeModal();
	});

	document.addEventListener("keydown", (event) => {
		const current = topEntry();
		if (!current) return;
		if (event.key === "Escape") {
			closeModal();
			return;
		}
		if (event.key !== "Tab") return;
		const items = [...current.modal.querySelectorAll(FOCUSABLE)].filter(
			(item) => item.offsetParent !== null,
		);
		if (!items.length) return;
		const first = items[0];
		const last = items[items.length - 1];
		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	});

	const autoOpen = document.body.dataset.openModal;
	if (autoOpen) openModal(autoOpen, null);
})();
