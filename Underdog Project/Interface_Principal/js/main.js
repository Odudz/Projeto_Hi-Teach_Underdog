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

/* Navegação: trilha (breadcrumb) estilo explorador de arquivos */
(() => {
	// Arquivos que ficam na raiz do site. Todos os outros ficam em /pages/.
	const ROOT_FILES = ["index.php", "activity.php"];
	// Hierarquia do site: cada página aponta para a página "pai".
	const SITE_MAP = {
		"index.php": { label: "Início" },
		"subjects.php": { label: "Matérias", parent: "index.php" },
		"course.php": { label: "Curso", parent: "subjects.php" },
		"video.php": { label: "Vídeo", parent: "course.php" },
		"classroom.php": { label: "Sala virtual", parent: "course.php" },
		"activity.php": { label: "Atividade", parent: "classroom.php" },
		"forum.php": { label: "Fóruns", parent: "index.php" },
		"forum-topic.php": {
			label: "Tópico",
			parent: "forum.php",
			dynamicLabel: () =>
				document
					.querySelector("#topic-header .section-title")
					?.textContent.replace("🔒", "")
					.trim(),
		},
		"live-class.php": { label: "Aulas ao vivo", parent: "index.php" },
		"live-watch.php": { label: "Assistindo", parent: "live-class.php" },
		"live-teacher.php": { label: "Transmitindo", parent: "live-class.php" },
		"messages.php": { label: "Mensagens", parent: "index.php" },
		"profile.php": { label: "Perfil", parent: "index.php" },
		"profile-view.php": {
			label: "Perfil",
			parent: "forum.php",
			dynamicLabel: () =>
				document.getElementById("profile-name")?.textContent.trim(),
		},
		"teacher-dashboard.php": { label: "Painel do professor", parent: "profile.php" },
		"moderator-dashboard.php": { label: "Administração", parent: "profile.php" },
	};

	const currentFile = location.pathname.split("/").pop() || "index.php";
	const currentPage = SITE_MAP[currentFile];

	const siteRoot = () => {
		const dir = location.pathname.slice(0, location.pathname.lastIndexOf("/") + 1);
		return dir.replace(/(pages|auth)\/$/, "");
	};
	const hrefFor = (root, key) =>
		root + (ROOT_FILES.includes(key) ? "" : "pages/") + key;

	const buildTrail = (key) => {
		const trail = [];
		while (key && SITE_MAP[key] && !trail.includes(key)) {
			trail.unshift(key);
			key = SITE_MAP[key].parent;
		}
		return trail;
	};
	const labelOf = (key) => {
		const page = SITE_MAP[key];
		return (key === currentFile && page.dynamicLabel?.()) || page.label;
	};

	const renderCrumbs = (header, entries) => {
		const bar = document.createElement("nav");
		bar.className = "crumbs-bar";
		bar.setAttribute("aria-label", "Trilha de navegação");
		const list = document.createElement("ol");
		list.className = "crumbs";
		entries.forEach((entry, index) => {
			const item = document.createElement("li");
			item.className = "crumb";
			item.style.setProperty("--i", index);
			if (entry.href) {
				const link = document.createElement("a");
				link.className = "crumb-link";
				link.href = entry.href;
				link.textContent = entry.label;
				item.append(link);
			} else {
				const current = document.createElement("span");
				current.className = "crumb-current";
				current.setAttribute("aria-current", "page");
				current.textContent = entry.label;
				current.title = entry.label;
				item.append(current);
			}
			list.append(item);
		});
		bar.append(list);
		header.after(bar);
		list.scrollLeft = list.scrollWidth;
	};

	const init = () => {
		const header = document.querySelector(".site-header");
		if (!header || !currentPage) return;
		const root = siteRoot();
		const entries = buildTrail(currentFile).map((key) => ({
			label: labelOf(key),
			href: key === currentFile ? null : hrefFor(root, key),
		}));
		if (entries.length > 1) renderCrumbs(header, entries);
	};

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", init);
	} else {
		init();
	}
})();
