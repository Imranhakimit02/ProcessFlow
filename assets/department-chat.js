/**
 * File: assets/department-chat.js
 * Purpose: Send and refresh progress messages without reloading the room.
 * Author: Imran IT Intern
 *
 * These handlers send chat messages and refresh the conversation while the
 * project remains open.
 */

// ------------------------------------------
// Prepare the conversation controls
// ------------------------------------------
const thread = document.querySelector('#department-chat-thread');
const composer = document.querySelector('#chat-composer');
const closedNotice = document.querySelector('#chat-closed');
const errorNotice = document.querySelector('#chat-error');
let lastThread = thread.innerHTML.trim();
let refreshing = false;
let refreshTimer;
thread.scrollTop = thread.scrollHeight;

// -------------------------------
// Close the message form
// -------------------------------
function closeComposer() {
    if (composer) composer.hidden = true;
    closedNotice.hidden = false;
    clearInterval(refreshTimer);
}

// --------------------------------------------
// Refresh the department conversation
// --------------------------------------------
async function refreshDepartmentChat(forceScroll = false) {
    if (refreshing || !closedNotice.hidden) return;
    refreshing = true;
    try {
        const response = await fetch(thread.dataset.threadUrl, { cache: 'no-store' });
        if (response.status === 403) {
            thread.textContent = 'This department chat is no longer available in your workspace.';
            composer.hidden = true;
            clearInterval(refreshTimer);
            return;
        }
        if (!response.ok) return;
        const messages = (await response.text()).trim();
        if (messages !== lastThread) {
            const atBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 32;
            thread.innerHTML = messages;
            lastThread = messages;
            if (atBottom || forceScroll) thread.scrollTop = thread.scrollHeight;
        }
        if (response.headers.get('X-Chat-Closed') === '1') closeComposer();
    } catch {
        // The next refresh retries temporary connection failures.
    } finally {
        refreshing = false;
    }
}

// ------------------------------
// Submit staff messages
// ------------------------------
if (composer) {
    refreshTimer = setInterval(refreshDepartmentChat, 2000);
    composer.addEventListener('submit', async (event) => {
        event.preventDefault();
        const input = composer.elements.message;
        const send = composer.querySelector('button[type="submit"]');
        if (send.disabled || composer.hidden) return;

        send.disabled = true;
        input.readOnly = true;
        errorNotice.textContent = '';
        try {
            const response = await fetch(thread.dataset.threadUrl, {
                method: 'POST',
                body: new FormData(composer),
            });
            if (response.status === 204) {
                composer.reset();
                await refreshDepartmentChat(true);
            } else if (response.status === 409) {
                await refreshDepartmentChat();
                closeComposer();
            } else if (response.status === 422) {
                errorNotice.textContent = await response.text();
            } else {
                errorNotice.textContent = 'Could not send the message. Check your session and try again.';
            }
        } catch {
            errorNotice.textContent = 'Could not confirm delivery. Check the conversation before retrying.';
        } finally {
            input.readOnly = false;
            send.disabled = false;
            if (!composer.hidden) input.focus();
        }
    });
}
