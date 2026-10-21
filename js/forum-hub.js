(() => {
	const form = document.getElementById("forum-new-form");
	if (!form) return;

	const subject = form.elements.subject_id;
	const title = form.elements.title;
	const body = form.elements.body;
	const preview = document.querySelector("[data-preview]");
	if (!preview) return;

	const titleNode = preview.querySelector("[data-preview-title]");
	const descNode = preview.querySelector("[data-preview-desc]");
	const subjectNode = preview.querySelector("[data-preview-subject]");
	const counters = {
		title: form.querySelector('[data-count="title"]'),
		body: form.querySelector('[data-count="body"]'),
	};

	const fill = (node, value) => {
		const empty = !value;
		node.textContent = empty ? node.dataset.empty : value;
		node.classList.toggle("is-placeholder", empty);
	};

	const count = (field, counter) => {
		if (!counter) return;
		const total = Array.from(field.value).length;
		counter.textContent = `${total}/${counter.dataset.max}`;
		counter.classList.toggle("is-limit", total >= Number(counter.dataset.max));
	};

	const sync = () => {
		fill(titleNode, title.value.replace(/\s+/g, " ").trim());
		fill(descNode, body.value.trim());
		const option = subject.selectedOptions[0];
		fill(subjectNode, option && option.value ? option.textContent.trim() : "");
		count(title, counters.title);
		count(body, counters.body);
	};

	[subject, title, body].forEach((field) => {
		field.addEventListener("input", sync);
		field.addEventListener("change", sync);
	});
	sync();
})();
