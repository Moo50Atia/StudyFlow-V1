import './v3-review-preview';

const root = document.querySelector('[data-v3-review-page]');
if (root) {
    const { caseId, reviewId, reviewVersion, candidateRef, candidateVersion } = root.dataset;
    const message = document.querySelector('#v3-review-message');
    const approve = document.querySelector('#v3-review-approve');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const decisionUrl = `/v3/teacher/cases/${caseId}/reviews/${reviewId}/decisions`;
    let firstFrameShown = false;
    const preview = new window.V3ReviewPreview({
        caseId, reviewId, expectedReviewVersion: Number(reviewVersion), csrfToken: csrf,
        containerEl: document.querySelector('#v3-review-preview'),
        onFirstFrame: () => { firstFrameShown = true; approve.disabled = false; },
    });

    async function submitDecision(body) {
        message.textContent = 'Submitting decision…';
        const response = await fetch(decisionUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({
                schema_version: 1,
                idempotency_key: crypto.randomUUID(),
                expected_review_version: Number(reviewVersion),
                candidate_ref: candidateRef,
                candidate_version: Number(candidateVersion),
                ...body,
            }),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload?.error?.message || `Request failed (${response.status})`);
        message.textContent = 'Decision saved. The review state will refresh shortly.';
        window.location.reload();
    }

    approve.addEventListener('click', async () => {
        if (!firstFrameShown || approve.disabled) return;
        approve.disabled = true;
        try { await submitDecision({ decision: 'approve' }); }
        catch (error) { message.textContent = error.message; approve.disabled = !firstFrameShown; }
    });

    document.querySelector('#v3-review-changes')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const submit = form.querySelector('[type="submit"]');
        if (submit.disabled) return;
        submit.disabled = true;
        try {
            await submitDecision({
                decision: 'request_changes',
                feedback_kind: form.elements.feedback_kind.value,
                notes: form.elements.notes.value,
            });
        } catch (error) { message.textContent = error.message; submit.disabled = false; }
    });

}

document.querySelector('#v3-postapproval-correction')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('[type="submit"]');
    const status = form.querySelector('[role="status"]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    if (button.disabled) return;
    button.disabled = true;
    status.textContent = 'Submitting correction…';
    try {
        const response = await fetch(form.dataset.url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({
                schema_version: 1,
                idempotency_key: crypto.randomUUID(),
                expected_section_version: Number(form.dataset.sectionVersion),
                feedback_kind: form.elements.feedback_kind.value,
                notes: form.elements.notes.value,
            }),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload?.error?.message || `Request failed (${response.status})`);
        status.textContent = 'Correction accepted and queued.';
        window.location.reload();
    } catch (error) {
        status.textContent = error.message;
        button.disabled = false;
    }
});
