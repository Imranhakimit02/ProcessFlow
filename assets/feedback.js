/**
 * File: assets/feedback.js
 * Purpose: Dismiss transient feedback five seconds after it appears.
 * Author: Imran IT Intern
 *
 * This handler refreshes the dismissal timer whenever a feedback message
 * changes.
 */

// -----------------------------------
// Manage Feedback Visibility
// -----------------------------------
const feedbackSelector = '[data-feedback], [role="alert"], [role="status"]';
const timedFeedback = new WeakSet();

function watchFeedback(element) {
    if (timedFeedback.has(element)) {
        return;
    }
    timedFeedback.add(element);
    let timer;

    function refreshFeedback() {
        clearTimeout(timer);
        element.hidden = !element.textContent.trim();

        if (!element.hidden) {
            timer = setTimeout(() => {
                element.hidden = true;
            }, 5000);
        }
    }

    refreshFeedback();
    new MutationObserver(refreshFeedback).observe(element, {
        childList: true,
        characterData: true,
        subtree: true,
    });
}

document.querySelectorAll(feedbackSelector).forEach(watchFeedback);

// -------------------------------------
// Watch for new feedback messages
// -------------------------------------
new MutationObserver((changes) => {
    changes.forEach((change) => {
        change.addedNodes.forEach((node) => {
            if (!(node instanceof Element)) {
                return;
            }
            if (node.matches(feedbackSelector)) {
                watchFeedback(node);
            }
            node.querySelectorAll(feedbackSelector).forEach(watchFeedback);
        });
    });
}).observe(document.body, {
    childList: true,
    subtree: true,
});
