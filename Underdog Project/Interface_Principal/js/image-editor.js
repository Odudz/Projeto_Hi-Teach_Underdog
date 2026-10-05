(() => {
	const ACCEPTED_TYPES = ["image/jpeg", "image/png", "image/webp"];
	const MAX_SOURCE_MB = 20;
	const FRAME_PADDING = 16;
	const MIN_ZOOM = 1;
	const MAX_ZOOM = 5;
	const CLOSE_DELAY_MS = 550;
	const DIM_COLOR = "rgba(18, 14, 44, 0.6)";

	const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
	const defaultState = () => ({
		zoom: 1,
		panX: 0,
		panY: 0,
		rotation: 0,
		flip: false,
		brightness: 100,
		contrast: 100,
		saturation: 100,
	});

	const showFlash = (type, message) => {
		const area = document.getElementById("flash-area");
		if (!area) return;
		const alert = document.createElement("div");
		alert.className = `alert alert-${type}`;
		alert.setAttribute("role", "alert");
		alert.textContent = message;
		area.replaceChildren(alert);
		setTimeout(() => alert.remove(), 4000);
	};

	const syncHeaderAvatar = (url) => {
		const link = document.getElementById("header-avatar");
		if (!link) return;
		let image = link.querySelector(".header-avatar-image");
		if (!image) {
			image = new Image();
			image.className = "header-avatar-image";
			image.alt = "";
			link.querySelector(".header-avatar-initials")?.remove();
			link.prepend(image);
		}
		image.src = url;
	};

	const applyResult = (kind, url) => {
		if (kind === "avatar") {
			syncHeaderAvatar(url);
			const holder = document.getElementById("profile-avatar");
			let image = document.getElementById("profile-avatar-image");
			if (!image) {
				image = new Image();
				image.id = "profile-avatar-image";
				image.className = "profile-avatar-image";
				const name = document.getElementById("profile-name");
				image.alt = `Foto de perfil de ${name ? name.textContent : ""}`;
				document.getElementById("profile-avatar-initials")?.remove();
				holder.prepend(image);
			}
			image.src = url;
			return;
		}
		const holder = document.getElementById("profile-banner");
		let image = document.getElementById("profile-banner-image");
		if (!image) {
			image = new Image();
			image.id = "profile-banner-image";
			image.className = "profile-banner-image";
			image.alt = "";
			holder.prepend(image);
		}
		image.src = url;
	};

	const initEditor = (overlay) => {
		const kind = overlay.dataset.imageEditor;
		const isCircle = overlay.dataset.shape === "circle";
		const outWidth = Number(overlay.dataset.outWidth);
		const outHeight = Number(overlay.dataset.outHeight);
		const find = (selector) => overlay.querySelector(selector);

		const errorBox = find("[data-editor-error]");
		const picker = find("[data-editor-picker]");
		const dropZone = find("[data-editor-drop]");
		const fileInput = find("[data-editor-file]");
		const workspace = find("[data-editor-workspace]");
		const stage = find("[data-editor-stage]");
		const canvas = find("[data-editor-canvas]");
		const zoomRange = find("[data-editor-zoom]");
		const saveButton = find("[data-editor-save]");
		const ctx = canvas.getContext("2d");
		const hasCtxFilter = typeof ctx.filter === "string";
		const adjustInputs = [...overlay.querySelectorAll("[data-editor-adjust]")];

		let img = null;
		let objectUrl = null;
		let saving = false;
		let state = defaultState();
		let view = { w: 0, h: 0 };
		let frame = { x: 0, y: 0, w: 0, h: 0 };
		let baseScale = 1;
		let dpr = 1;
		const pointers = new Map();
		let pinch = null;

		const showError = (message) => {
			errorBox.textContent = message;
			errorBox.hidden = false;
		};
		const hideError = () => {
			errorBox.hidden = true;
		};

		const turnedSize = () =>
			state.rotation % 180 === 0
				? [img.naturalWidth, img.naturalHeight]
				: [img.naturalHeight, img.naturalWidth];

		const updateBaseScale = () => {
			const [width, height] = turnedSize();
			baseScale = Math.max(frame.w / width, frame.h / height);
		};

		const clampPan = () => {
			const [width, height] = turnedSize();
			const scale = baseScale * state.zoom;
			const maxX = Math.max(0, (width * scale - frame.w) / 2);
			const maxY = Math.max(0, (height * scale - frame.h) / 2);
			state.panX = clamp(state.panX, -maxX, maxX);
			state.panY = clamp(state.panY, -maxY, maxY);
		};

		const layoutStage = () => {
			dpr = window.devicePixelRatio || 1;
			view = { w: stage.clientWidth, h: stage.clientHeight };
			canvas.width = Math.round(view.w * dpr);
			canvas.height = Math.round(view.h * dpr);

			const ratio = outWidth / outHeight;
			let width = view.w - FRAME_PADDING * 2;
			let height = width / ratio;
			if (height > view.h - FRAME_PADDING * 2) {
				height = view.h - FRAME_PADDING * 2;
				width = height * ratio;
			}
			frame = {
				x: (view.w - width) / 2,
				y: (view.h - height) / 2,
				w: width,
				h: height,
			};
			updateBaseScale();
			clampPan();
			draw();
		};

		const filterString = () =>
			`brightness(${state.brightness}%) contrast(${state.contrast}%) saturate(${state.saturation}%)`;
		const hasAdjustments = () =>
			state.brightness !== 100 ||
			state.contrast !== 100 ||
			state.saturation !== 100;

		const adjustPixels = (target, width, height) => {
			if (!hasAdjustments()) return;
			const data = target.getImageData(0, 0, width, height);
			const pixels = data.data;
			const brightness = state.brightness / 100;
			const contrast = state.contrast / 100;
			const saturation = state.saturation / 100;
			for (let i = 0; i < pixels.length; i += 4) {
				const channels = [pixels[i], pixels[i + 1], pixels[i + 2]].map(
					(value) => value * brightness,
				);
				const gray =
					0.299 * channels[0] + 0.587 * channels[1] + 0.114 * channels[2];
				for (let c = 0; c < 3; c++) {
					let value = gray + (channels[c] - gray) * saturation;
					value = (value - 127.5) * contrast + 127.5;
					pixels[i + c] = clamp(value, 0, 255);
				}
			}
			target.putImageData(data, 0, 0);
		};

		const paintImage = (target, centerX, centerY, k) => {
			const direction = state.flip ? -1 : 1;
			const scale = baseScale * state.zoom * k;
			target.save();
			target.imageSmoothingQuality = "high";
			if (hasCtxFilter) target.filter = filterString();
			target.translate(centerX + state.panX * k, centerY + state.panY * k);
			target.scale(direction, 1);
			target.rotate((direction * state.rotation * Math.PI) / 180);
			target.scale(scale, scale);
			target.drawImage(img, -img.naturalWidth / 2, -img.naturalHeight / 2);
			target.restore();
		};

		const traceFrame = () => {
			if (isCircle) {
				ctx.moveTo(frame.x + frame.w, frame.y + frame.h / 2);
				ctx.ellipse(
					frame.x + frame.w / 2,
					frame.y + frame.h / 2,
					frame.w / 2,
					frame.h / 2,
					0,
					0,
					Math.PI * 2,
				);
			} else {
				ctx.rect(frame.x, frame.y, frame.w, frame.h);
			}
		};

		function draw() {
			ctx.setTransform(1, 0, 0, 1, 0, 0);
			ctx.clearRect(0, 0, canvas.width, canvas.height);
			if (!img) return;

			ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
			paintImage(ctx, frame.x + frame.w / 2, frame.y + frame.h / 2, 1);
			if (!hasCtxFilter) {
				ctx.setTransform(1, 0, 0, 1, 0, 0);
				adjustPixels(ctx, canvas.width, canvas.height);
				ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
			}

			ctx.save();
			ctx.fillStyle = DIM_COLOR;
			ctx.beginPath();
			ctx.rect(0, 0, view.w, view.h);
			traceFrame();
			ctx.fill("evenodd");
			ctx.restore();

			ctx.save();
			ctx.strokeStyle = "#fff";
			ctx.lineWidth = 2;
			ctx.beginPath();
			traceFrame();
			ctx.stroke();
			if (!isCircle) {
				ctx.strokeStyle = "rgba(255, 255, 255, 0.35)";
				ctx.lineWidth = 1;
				ctx.beginPath();
				for (let i = 1; i < 3; i++) {
					const x = frame.x + (frame.w * i) / 3;
					const y = frame.y + (frame.h * i) / 3;
					ctx.moveTo(x, frame.y);
					ctx.lineTo(x, frame.y + frame.h);
					ctx.moveTo(frame.x, y);
					ctx.lineTo(frame.x + frame.w, y);
				}
				ctx.stroke();
			}
			ctx.restore();
		}

		const syncControls = () => {
			zoomRange.value = state.zoom;
			adjustInputs.forEach((input) => {
				const key = input.dataset.editorAdjust;
				input.value = state[key];
				find(`[data-editor-adjust-value="${key}"]`).textContent =
					`${state[key]}%`;
			});
		};

		const setZoom = (value) => {
			if (!img) return;
			const next = clamp(value, MIN_ZOOM, MAX_ZOOM);
			const ratio = next / state.zoom;
			state.zoom = next;
			state.panX *= ratio;
			state.panY *= ratio;
			clampPan();
			zoomRange.value = next;
			draw();
		};

		const pan = (dx, dy) => {
			state.panX += dx;
			state.panY += dy;
			clampPan();
			draw();
		};

		const rotate = (direction) => {
			state.rotation = (state.rotation + direction * 90 + 360) % 360;
			state.panX = 0;
			state.panY = 0;
			updateBaseScale();
			clampPan();
			draw();
		};

		const reset = () => {
			state = defaultState();
			syncControls();
			if (!img) return;
			updateBaseScale();
			clampPan();
			draw();
		};

		zoomRange.addEventListener("input", () => setZoom(Number(zoomRange.value)));
		find("[data-editor-zoom-in]").addEventListener("click", () =>
			setZoom(state.zoom + 0.25),
		);
		find("[data-editor-zoom-out]").addEventListener("click", () =>
			setZoom(state.zoom - 0.25),
		);
		overlay.querySelectorAll("[data-editor-rotate]").forEach((button) => {
			button.addEventListener("click", () =>
				rotate(Number(button.dataset.editorRotate)),
			);
		});
		find("[data-editor-flip]").addEventListener("click", () => {
			state.flip = !state.flip;
			draw();
		});
		find("[data-editor-reset]").addEventListener("click", reset);
		adjustInputs.forEach((input) => {
			input.addEventListener("input", () => {
				const key = input.dataset.editorAdjust;
				state[key] = Number(input.value);
				find(`[data-editor-adjust-value="${key}"]`).textContent =
					`${state[key]}%`;
				draw();
			});
		});

		const distance = () => {
			const [a, b] = [...pointers.values()];
			return Math.hypot(a.x - b.x, a.y - b.y);
		};

		canvas.addEventListener("pointerdown", (event) => {
			if (!img) return;
			canvas.setPointerCapture(event.pointerId);
			pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
			canvas.classList.add("is-dragging");
			pinch =
				pointers.size === 2 ? { start: distance(), zoom: state.zoom } : null;
		});
		canvas.addEventListener("pointermove", (event) => {
			const previous = pointers.get(event.pointerId);
			if (!previous) return;
			const current = { x: event.clientX, y: event.clientY };
			pointers.set(event.pointerId, current);
			if (pointers.size === 1) {
				pan(current.x - previous.x, current.y - previous.y);
			} else if (pointers.size === 2 && pinch) {
				setZoom((pinch.zoom * distance()) / pinch.start);
			}
		});
		const endPointer = (event) => {
			pointers.delete(event.pointerId);
			pinch = null;
			if (!pointers.size) canvas.classList.remove("is-dragging");
		};
		canvas.addEventListener("pointerup", endPointer);
		canvas.addEventListener("pointercancel", endPointer);

		canvas.addEventListener(
			"wheel",
			(event) => {
				if (!img) return;
				event.preventDefault();
				setZoom(state.zoom * Math.exp(-event.deltaY * 0.0015));
			},
			{ passive: false },
		);

		canvas.addEventListener("keydown", (event) => {
			if (!img) return;
			const step = event.shiftKey ? 30 : 10;
			const moves = {
				ArrowLeft: [-step, 0],
				ArrowRight: [step, 0],
				ArrowUp: [0, -step],
				ArrowDown: [0, step],
			};
			if (moves[event.key]) {
				event.preventDefault();
				pan(...moves[event.key]);
			} else if (event.key === "+" || event.key === "=") {
				event.preventDefault();
				setZoom(state.zoom + 0.25);
			} else if (event.key === "-") {
				event.preventDefault();
				setZoom(state.zoom - 0.25);
			}
		});

		new ResizeObserver(() => {
			if (img && !workspace.hidden) layoutStage();
		}).observe(stage);

		const releaseImage = () => {
			if (objectUrl) URL.revokeObjectURL(objectUrl);
			objectUrl = null;
			img = null;
		};

		const showPicker = () => {
			releaseImage();
			state = defaultState();
			syncControls();
			workspace.hidden = true;
			picker.hidden = false;
			fileInput.value = "";
			pointers.clear();
			canvas.classList.remove("is-dragging");
		};

		const loadFile = (file) => {
			if (!file) return;
			if (!ACCEPTED_TYPES.includes(file.type)) {
				showError("Use uma imagem JPG, PNG ou WebP.");
				return;
			}
			if (file.size > MAX_SOURCE_MB * 1024 * 1024) {
				showError(`A imagem deve ter no máximo ${MAX_SOURCE_MB} MB.`);
				return;
			}
			hideError();

			const url = URL.createObjectURL(file);
			const image = new Image();
			image.onload = () => {
				releaseImage();
				objectUrl = url;
				img = image;
				state = defaultState();
				syncControls();
				picker.hidden = true;
				workspace.hidden = false;
				layoutStage();
				canvas.focus({ preventScroll: true });
			};
			image.onerror = () => {
				URL.revokeObjectURL(url);
				showError("Não foi possível abrir essa imagem.");
			};
			image.src = url;
		};

		find("[data-editor-choose]").addEventListener("click", () =>
			fileInput.click(),
		);
		fileInput.addEventListener("change", () => loadFile(fileInput.files[0]));
		find("[data-editor-change]").addEventListener("click", () => {
			hideError();
			showPicker();
			find("[data-editor-choose]").focus();
		});

		["dragenter", "dragover"].forEach((name) => {
			dropZone.addEventListener(name, (event) => {
				event.preventDefault();
				dropZone.classList.add("is-over");
			});
		});
		["dragleave", "drop"].forEach((name) => {
			dropZone.addEventListener(name, () =>
				dropZone.classList.remove("is-over"),
			);
		});
		dropZone.addEventListener("drop", (event) => {
			event.preventDefault();
			loadFile(event.dataTransfer.files[0]);
		});

		const dataUrlToBlob = (dataUrl) => {
			const [header, data] = dataUrl.split(",");
			const mime = header.match(/:(.*?);/)?.[1] || "image/jpeg";
			const bytes = atob(data);
			const buffer = new Uint8Array(bytes.length);
			for (let i = 0; i < bytes.length; i++) buffer[i] = bytes.charCodeAt(i);
			return new Blob([buffer], { type: mime });
		};

		const exportBlob = () =>
			new Promise((resolve, reject) => {
				const output = document.createElement("canvas");
				output.width = outWidth;
				output.height = outHeight;
				const outputCtx = output.getContext("2d");
				if (!outputCtx) {
					reject(new Error("Não foi possível preparar o editor de imagem."));
					return;
				}
				outputCtx.fillStyle = "#fff";
				outputCtx.fillRect(0, 0, outWidth, outHeight);
				paintImage(outputCtx, outWidth / 2, outHeight / 2, outWidth / frame.w);
				if (!hasCtxFilter) adjustPixels(outputCtx, outWidth, outHeight);
				if (output.toBlob) {
					output.toBlob((blob) => {
						if (blob) {
							resolve(blob);
							return;
						}
						try {
							resolve(dataUrlToBlob(output.toDataURL("image/jpeg", 0.9)));
						} catch {
							reject(new Error("Não foi possível preparar a imagem."));
						}
					}, "image/jpeg", 0.9);
					return;
				}
				try {
					resolve(dataUrlToBlob(output.toDataURL("image/jpeg", 0.9)));
				} catch {
					reject(new Error("Não foi possível preparar a imagem."));
				}
			});

		const setSaving = (value) => {
			saving = value;
			saveButton.disabled = value;
			saveButton.textContent = value ? "Salvando…" : "Salvar";
		};

		saveButton.addEventListener("click", async () => {
			if (!img || saving) return;
			setSaving(true);
			hideError();
			try {
				const blob = await exportBlob();
				if (!blob) throw new Error("Não foi possível preparar a imagem.");

				const body = new FormData();
				body.append("action", kind);
				body.append("image", blob, `${kind}.jpg`);
				const endpoint = new URL(overlay.dataset.endpoint, window.location.href);
				const response = await fetch(endpoint.toString(), {
					method: "POST",
					headers: { "X-Requested-With": "fetch" },
					body,
				});
				const text = await response.text();
				let data = null;
				try {
					data = text ? JSON.parse(text) : null;
				} catch {
					throw new Error("O servidor não conseguiu concluir o salvamento da imagem.");
				}
				if (!response.ok || !data?.ok) {
					throw new Error(
						data?.error || "Não foi possível salvar. Entre na sua conta e tente de novo.",
					);
				}

				applyResult(kind, data.url);
				showFlash("success", data.message);
				find("[data-modal-close]").click();
			} catch (error) {
				showError(
					error instanceof TypeError
						? "Sem conexão com o servidor. Tente novamente."
						: error.message,
				);
			} finally {
				setSaving(false);
			}
		});

		let pressStartedInside = false;
		overlay.addEventListener(
			"pointerdown",
			(event) => {
				pressStartedInside = event.target !== overlay;
			},
			true,
		);
		overlay.addEventListener(
			"click",
			(event) => {
				if (event.target === overlay && pressStartedInside) {
					event.stopPropagation();
				}
				pressStartedInside = false;
			},
			true,
		);

		new MutationObserver(() => {
			if (overlay.classList.contains("is-open")) return;
			setTimeout(() => {
				if (overlay.classList.contains("is-open")) return;
				hideError();
				showPicker();
			}, CLOSE_DELAY_MS);
		}).observe(overlay, { attributes: true, attributeFilter: ["class"] });
	};

	document.querySelectorAll("[data-image-editor]").forEach(initEditor);
})();
