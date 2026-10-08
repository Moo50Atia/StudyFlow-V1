/**
 * V3 Teacher Review Interactive Scene Preview Client (Spec 013).
 *
 * Enforces remote container frame-based interaction:
 * - Displays 1280x720 PNG frames streamed from the authenticated owner endpoints.
 * - Captures clicks and translates them to bounded 1280x720 coordinates.
 * - Exposes accessible semantic controls and text input (no canvas-only interaction).
 * - Enforces escaped rendering of dom_text and controls (no candidate JS/HTML execution).
 */

class V3ReviewPreview {
    constructor(options) {
        this.caseId = options.caseId;
        this.reviewId = options.reviewId;
        this.expectedReviewVersion = options.expectedReviewVersion || 1;
        this.csrfToken = options.csrfToken;
        this.containerEl = options.containerEl;
        this.onFirstFrame = options.onFirstFrame || (() => {});

        this.viewerRef = null;
        this.currentFrameNo = 0;
        this.isLoading = false;
        this.controls = [];
        this.domText = '';

        this.initDOM();
    }

    initDOM() {
        this.containerEl.innerHTML = `
            <div class="v3-preview-wrapper bg-gray-900 border border-gray-700 rounded-lg overflow-hidden shadow-xl">
                <!-- Toolbar -->
                <div class="v3-preview-toolbar flex items-center justify-between px-4 py-2 bg-gray-800 border-b border-gray-700 text-sm text-gray-300">
                    <div class="flex items-center space-x-3">
                        <span class="font-mono text-xs px-2 py-0.5 rounded bg-gray-700 text-gray-200" id="v3-frame-indicator">Frame: 0</span>
                        <span class="text-xs text-gray-400" id="v3-status-indicator">Ready</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <button type="button" id="v3-start-btn" class="px-3 py-1 bg-indigo-600 hover:bg-indigo-500 text-white rounded text-xs font-semibold">Start Preview</button>
                        <button type="button" id="v3-close-btn" class="px-3 py-1 bg-red-700 hover:bg-red-600 text-white rounded text-xs font-semibold hidden">Close Session</button>
                    </div>
                </div>

                <!-- Scene Display Area -->
                <div class="relative bg-black flex items-center justify-center min-h-[400px]" style="aspect-ratio: 16/9;">
                    <div id="v3-loading-spinner" class="absolute inset-0 bg-black/60 flex items-center justify-center hidden z-10">
                        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-white"></div>
                    </div>
                    <img id="v3-preview-img" src="" alt="Interactive scene preview" class="w-full h-full object-contain cursor-crosshair hidden" />
                    <div id="v3-placeholder" class="text-gray-500 text-sm text-center p-8">
                        Click "Start Preview" to launch the isolated interactive scene session.
                    </div>
                </div>

                <!-- Text & Keyboard Controls Area -->
                <div class="p-4 bg-gray-800/80 border-t border-gray-700 space-y-3">
                    <div class="text-xs text-gray-400">
                        <span class="font-bold text-gray-300">Accessibility Text: </span>
                        <span id="v3-dom-text" class="italic text-gray-200"></span>
                    </div>

                    <!-- Semantic Controls -->
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-gray-300">Controls:</span>
                        <div id="v3-controls-container" class="flex flex-wrap gap-2"></div>
                    </div>

                    <!-- Keyboard Type Input -->
                    <div class="flex items-center space-x-2 pt-2 border-t border-gray-700">
                        <input type="text" id="v3-type-input" placeholder="Type text to send to scene..." maxlength="256" class="flex-1 bg-gray-900 border border-gray-600 rounded px-3 py-1 text-xs text-white focus:outline-none focus:border-indigo-500" />
                        <button type="button" id="v3-send-type-btn" class="px-3 py-1 bg-gray-700 hover:bg-gray-600 text-white rounded text-xs">Send Text</button>
                    </div>
                </div>
            </div>
        `;

        this.startBtn = this.containerEl.querySelector('#v3-start-btn');
        this.closeBtn = this.containerEl.querySelector('#v3-close-btn');
        this.previewImg = this.containerEl.querySelector('#v3-preview-img');
        this.placeholder = this.containerEl.querySelector('#v3-placeholder');
        this.spinner = this.containerEl.querySelector('#v3-loading-spinner');
        this.frameIndicator = this.containerEl.querySelector('#v3-frame-indicator');
        this.statusIndicator = this.containerEl.querySelector('#v3-status-indicator');
        this.domTextEl = this.containerEl.querySelector('#v3-dom-text');
        this.controlsContainer = this.containerEl.querySelector('#v3-controls-container');
        this.typeInput = this.containerEl.querySelector('#v3-type-input');
        this.sendTypeBtn = this.containerEl.querySelector('#v3-send-type-btn');

        this.bindEvents();
    }

    bindEvents() {
        this.startBtn.addEventListener('click', () => this.startSession());
        this.closeBtn.addEventListener('click', () => this.closeSession());

        // Mouse click on preview image
        this.previewImg.addEventListener('click', (e) => this.handleImageClick(e));

        // Keyboard press forwarding
        this.previewImg.setAttribute('tabindex', '0');
        this.previewImg.addEventListener('keydown', (e) => this.handleKeyDown(e));

        // Send text
        this.sendTypeBtn.addEventListener('click', () => this.handleSendType());
        this.typeInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                this.handleSendType();
            }
        });
    }

    async startSession() {
        if (this.isLoading) return;
        this.setLoading(true, 'Starting isolated container...');

        try {
            const resp = await fetch(`/v3/teacher/cases/${this.caseId}/reviews/${this.reviewId}/viewer-sessions`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify({
                    expected_review_version: this.expectedReviewVersion,
                }),
            });

            if (!resp.ok) {
                const err = await resp.json().catch(() => ({}));
                alert(`Failed to start preview: ${err?.error?.message || resp.statusText}`);
                this.setLoading(false);
                return;
            }

            const res = await resp.json();
            this.viewerRef = res.data.viewer_ref;
            this.currentFrameNo = 0;

            this.startBtn.classList.add('hidden');
            this.closeBtn.classList.remove('hidden');

            await this.loadFrame(0);
            await this.fetchMetadata();
        } catch (e) {
            console.error(e);
            alert('Error starting preview session.');
        } finally {
            this.setLoading(false);
        }
    }

    async loadFrame(frameNo) {
        if (!this.viewerRef) return;
        this.setLoading(true, `Loading frame ${frameNo}...`);

        try {
            const frameUrl = `/v3/teacher/cases/${this.caseId}/reviews/${this.reviewId}/viewer-sessions/${this.viewerRef}/frames/${frameNo}`;
            const resp = await fetch(frameUrl);
            if (!resp.ok) {
                throw new Error(`Failed to fetch frame ${frameNo}`);
            }

            const blob = await resp.blob();
            const objectUrl = URL.createObjectURL(blob);
            this.previewImg.src = objectUrl;
            this.previewImg.classList.remove('hidden');
            this.placeholder.classList.add('hidden');

            this.currentFrameNo = frameNo;
            this.frameIndicator.textContent = `Frame: ${frameNo}`;
            if (frameNo === 0) this.onFirstFrame();
        } catch (e) {
            console.error(e);
        } finally {
            this.setLoading(false);
        }
    }

    async fetchMetadata() {
        if (!this.viewerRef) return;
        try {
            const showUrl = `/v3/teacher/cases/${this.caseId}/reviews/${this.reviewId}/viewer-sessions/${this.viewerRef}`;
            const resp = await fetch(showUrl);
            if (!resp.ok) return;

            const res = await resp.json();
            this.renderMetadata(res.data);
        } catch (e) {
            console.error(e);
        }
    }

    renderMetadata(data) {
        this.domText = data.dom_text || '';
        this.domTextEl.textContent = this.domText;

        this.controls = data.controls || [];
        this.controlsContainer.innerHTML = '';

        this.controls.forEach((ctrl) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'px-2 py-0.5 bg-gray-700 hover:bg-gray-600 text-gray-200 rounded text-xs border border-gray-600 focus:ring-1 focus:ring-indigo-500';
            btn.textContent = `${ctrl.role}: ${ctrl.name}`;
            btn.addEventListener('click', () => {
                this.sendAction({
                    schema_version: 1,
                    expected_frame_no: this.currentFrameNo,
                    kind: 'press',
                    payload: { key: 'Enter' },
                });
            });
            this.controlsContainer.appendChild(btn);
        });
    }

    async handleImageClick(e) {
        if (!this.viewerRef || this.isLoading) return;

        const rect = this.previewImg.getBoundingClientRect();
        const clientX = e.clientX - rect.left;
        const clientY = e.clientY - rect.top;

        // Map to 1280x720
        const scaleX = 1280 / rect.width;
        const scaleY = 720 / rect.height;

        const x = Math.min(1279, Math.max(0, Math.round(clientX * scaleX)));
        const y = Math.min(719, Math.max(0, Math.round(clientY * scaleY)));

        await this.sendAction({
            schema_version: 1,
            expected_frame_no: this.currentFrameNo,
            kind: 'click',
            payload: { x, y },
        });
    }

    async handleKeyDown(e) {
        if (!this.viewerRef || this.isLoading) return;
        const allowedKeys = ['Tab', 'Shift+Tab', 'Enter', 'Space', 'Escape', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Backspace', 'Delete'];

        let key = e.key;
        if (e.key === ' ') key = 'Space';
        if (e.shiftKey && e.key === 'Tab') key = 'Shift+Tab';

        if (allowedKeys.includes(key)) {
            e.preventDefault();
            await this.sendAction({
                schema_version: 1,
                expected_frame_no: this.currentFrameNo,
                kind: 'press',
                payload: { key },
            });
        }
    }

    async handleSendType() {
        const text = this.typeInput.value.trim();
        if (!text || !this.viewerRef || this.isLoading) return;

        await this.sendAction({
            schema_version: 1,
            expected_frame_no: this.currentFrameNo,
            kind: 'type',
            payload: { text },
        });

        this.typeInput.value = '';
    }

    async sendAction(actionData) {
        this.setLoading(true, 'Applying interaction...');
        try {
            const actionUrl = `/v3/teacher/cases/${this.caseId}/reviews/${this.reviewId}/viewer-sessions/${this.viewerRef}/actions`;
            const resp = await fetch(actionUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify(actionData),
            });

            if (!resp.ok) {
                const err = await resp.json().catch(() => ({}));
                alert(`Action rejected: ${err?.error?.message || resp.statusText}`);
                return;
            }

            const res = await resp.json();
            const nextFrameNo = res.data.frame_no;
            this.renderMetadata(res.data);
            await this.loadFrame(nextFrameNo);
        } catch (e) {
            console.error(e);
        } finally {
            this.setLoading(false);
        }
    }

    async closeSession() {
        if (!this.viewerRef) return;
        this.setLoading(true, 'Closing session...');

        try {
            await fetch(`/v3/teacher/cases/${this.caseId}/reviews/${this.reviewId}/viewer-sessions/${this.viewerRef}`, {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
            });
        } catch (e) {
            console.error(e);
        } finally {
            this.viewerRef = null;
            this.currentFrameNo = 0;
            this.previewImg.classList.add('hidden');
            this.placeholder.classList.remove('hidden');
            this.startBtn.classList.remove('hidden');
            this.closeBtn.classList.add('hidden');
            this.controlsContainer.innerHTML = '';
            this.domTextEl.textContent = '';
            this.setLoading(false, 'Ready');
        }
    }

    setLoading(loading, statusText = '') {
        this.isLoading = loading;
        if (loading) {
            this.spinner.classList.remove('hidden');
            this.statusIndicator.textContent = statusText;
        } else {
            this.spinner.classList.add('hidden');
            this.statusIndicator.textContent = statusText || 'Ready';
        }
    }
}

window.V3ReviewPreview = V3ReviewPreview;
