/**
 * Small page behaviours that used to be inline handlers (onsubmit="...", <script>...</script>).
 * Keeping them in the bundle means the pages need no inline script, which is what lets the
 * Content-Security-Policy refuse inline script at all.
 *
 *   <form data-disable-on-submit>     disable the submit buttons once the form is submitted, so a
 *                                     double click cannot post it twice
 *   <form data-confirm="Are you sure?"> ask before submitting; cancelling stops the submit
 *   <div data-auto-reload="3000">     reload the page after that many milliseconds
 */

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const question = form.dataset.confirm;

    if (question !== undefined && !window.confirm(question)) {
        event.preventDefault();

        return;
    }

    if (form.hasAttribute('data-disable-on-submit')) {
        form.querySelectorAll('[type=submit]').forEach((button) => {
            button.disabled = true;
        });
    }
});

document.querySelectorAll('[data-auto-reload]').forEach((element) => {
    const delay = Number.parseInt(element.dataset.autoReload, 10);

    if (Number.isFinite(delay) && delay > 0) {
        window.setTimeout(() => window.location.reload(), delay);
    }
});
