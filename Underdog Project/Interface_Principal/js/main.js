(() => {
	const LEAVE_MS = 650;
	const REVEAL_START_MS = 450;
	const REVEAL_SELECTOR =
		".hero > *, .section-title, .card, .form, .table, .video-player, .live-player, .profile-data, .comment-item, .message-item, .live-item, .material-item, .stat-card, .badge-item, .profile-header, .gate";
	const root = document.documentElement;
	const body = document.body;
	const isReduced = () =>
		matchMedia("(prefers-reduced-motion: reduce)").matches ||
		root.dataset.motion === "reduced";

	document.addEventListener("click", (event) => {
		const link = event.target.closest("a[href]");
		if (!link || isReduced()) return;
		const url = new URL(link.href, location.href);
		const isModified =
			event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0;
		const isWebLink = url.protocol === "http:" || url.protocol === "https:";
		const isExternal =
			url.origin !== location.origin ||
			(link.target && link.target !== "_self") ||
			link.hasAttribute("download");
		const isSamePage =
			url.pathname === location.pathname && url.search === location.search;
		if (isModified || !isWebLink || isExternal || isSamePage) return;
		event.preventDefault();
		body.classList.add("is-leaving");
		setTimeout(() => {
			location.href = url.href;
		}, LEAVE_MS);
	});

	window.addEventListener("pageshow", (event) => {
		if (event.persisted) body.classList.remove("is-leaving");
	});

	const scope = document.querySelector(".main-content");
	if (!scope || isReduced()) return;

	const observer = new IntersectionObserver(
		(entries) => {
			entries.forEach((entry) => {
				if (!entry.isIntersecting) return;
				entry.target.classList.add("is-visible");
				observer.unobserve(entry.target);
			});
		},
		{ threshold: 0.1 },
	);

	scope.querySelectorAll(REVEAL_SELECTOR).forEach((element, index) => {
		element.classList.add("reveal");
		element.style.setProperty("--i", index % 6);
		setTimeout(() => observer.observe(element), REVEAL_START_MS);
	});
})();
