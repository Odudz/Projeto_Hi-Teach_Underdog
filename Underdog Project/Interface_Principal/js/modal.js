(() => {
	const root = document.documentElement;
	const FOCUSABLE =
		'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
	let activeModal = null;
	let activeTrigger = null;

	const openModal = (id, trigger) => {
		const modal = document.getElementById(id);
		if (!modal || activeModal) return;
		activeModal = modal;
		activeTrigger = trigger || null;
		modal.classList.add("is-open");
		root.classList.add("is-locked");
		if (activeTrigger) activeTrigger.setAttribute("aria-expanded", "true");
		const first = modal.querySelector(FOCUSABLE);
		if (first) first.focus();
	};

	const closeModal = () => {
		if (!activeModal) return;
		activeModal.classList.remove("is-open");
		root.classList.remove("is-locked");
		if (activeTrigger) {
			activeTrigger.setAttribute("aria-expanded", "false");
			activeTrigger.focus();
		}
		activeModal = null;
		activeTrigger = null;
	};

	document.addEventListener("click", (event) => {
		const opener = event.target.closest("[data-modal-open]");
		if (opener) {
			openModal(opener.dataset.modalOpen, opener);
			return;
		}
		if (
			activeModal &&
			(event.target === activeModal ||
				event.target.closest("[data-modal-close]"))
		)
			closeModal();
	});

	document.addEventListener("keydown", (event) => {
		if (!activeModal) return;
		if (event.key === "Escape") {
			closeModal();
			return;
		}
		if (event.key !== "Tab") return;
		const items = [...activeModal.querySelectorAll(FOCUSABLE)].filter(
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
