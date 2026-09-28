/**
 * Invoice form conveniences: add/remove lines, prefill a line from the chosen
 * product, and show running totals.
 *
 * Totals shown here are display-only. They use whole cents (BigInt) so the
 * browser never shows floating-point rounding errors, but the server always
 * recalculates every amount when the invoice is saved.
 */

const PLAIN_DECIMAL = /^\d+(\.\d{1,2})?$/;

/** Parse "12.5" into 1250n cents, or null if it is not a plain amount. */
function toCents(value) {
    const text = String(value ?? '').trim();

    if (!PLAIN_DECIMAL.test(text)) {
        return null;
    }

    const [whole, fraction = ''] = text.split('.');

    return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
}

/** Format 150050n cents as "RM 1,500.50". */
function formatCents(cents, symbol) {
    const whole = (cents / 100n).toLocaleString('en-US');
    const fraction = String(cents % 100n).padStart(2, '0');

    return `${symbol} ${whole}.${fraction}`;
}

function initInvoiceForm(form) {
    const lines = form.querySelector('[data-invoice-lines]');
    const template = form.querySelector('#invoice-line-template');
    const addButton = form.querySelector('[data-add-line]');
    const symbol = form.dataset.currency || 'RM';
    let nextIndex = Number(lines.dataset.nextIndex) || lines.children.length;

    const output = (selector) => form.querySelector(selector);

    function recalculate() {
        let subtotal = 0n;
        let valid = true;

        lines.querySelectorAll('[data-invoice-line]').forEach((line) => {
            const quantity = toCents(line.querySelector('[data-line-quantity]').value);
            const price = toCents(line.querySelector('[data-line-price]').value);
            const totalCell = line.querySelector('[data-line-total]');

            if (quantity === null && price === null) {
                totalCell.textContent = '';
                return;
            }

            if (quantity === null || price === null) {
                totalCell.textContent = '—';
                valid = false;
                return;
            }

            // (quantity × price) is in 1/10000ths; round half up to cents.
            const lineCents = (quantity * price + 50n) / 100n;
            totalCell.textContent = formatCents(lineCents, symbol);
            subtotal += lineCents;
        });

        const discount = toCents(output('[data-discount]').value || '0');
        const rate = toCents(output('[data-tax-rate]').value || '0');

        if (!valid || discount === null || rate === null) {
            ['[data-subtotal]', '[data-tax-amount]', '[data-total]'].forEach((selector) => {
                output(selector).textContent = '—';
            });
            return;
        }

        // rate is in hundredths of a percent, so divide by 100 × 100; round half up to cents.
        const taxable = subtotal - discount;
        const tax = taxable > 0n ? (taxable * rate + 5000n) / 10000n : 0n;

        output('[data-subtotal]').textContent = formatCents(subtotal, symbol);
        output('[data-tax-amount]').textContent = formatCents(tax, symbol);
        output('[data-total]').textContent = taxable >= 0n ? formatCents(taxable + tax, symbol) : '—';
    }

    function addLine() {
        const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
        lines.insertAdjacentHTML('beforeend', html);
        lines.lastElementChild.querySelector('[data-line-product]').focus();
    }

    function prefillFromProduct(select) {
        const option = select.selectedOptions[0];
        const line = select.closest('[data-invoice-line]');

        if (!option || option.value === '') {
            return;
        }

        line.querySelector('[data-line-name]').value = option.dataset.name ?? '';
        line.querySelector('[data-line-description]').value = option.dataset.description ?? '';
        line.querySelector('[data-line-unit]').value = option.dataset.unit ?? '';
        line.querySelector('[data-line-price]').value = option.dataset.price ?? '';

        const quantity = line.querySelector('[data-line-quantity]');
        if (quantity.value.trim() === '') {
            quantity.value = '1';
        }
    }

    addButton.classList.remove('d-none');
    addButton.addEventListener('click', () => {
        addLine();
        recalculate();
    });

    form.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-line]');
        if (!remove) {
            return;
        }

        remove.closest('[data-invoice-line]').remove();
        if (lines.querySelectorAll('[data-invoice-line]').length === 0) {
            addLine();
        }
        recalculate();
    });

    form.addEventListener('change', (event) => {
        if (event.target.matches('[data-line-product]')) {
            prefillFromProduct(event.target);
        }
        recalculate();
    });

    form.addEventListener('input', recalculate);

    recalculate();
}

document.querySelectorAll('[data-invoice-form]').forEach(initInvoiceForm);

document.querySelectorAll('[data-print]').forEach((button) => {
    button.addEventListener('click', () => window.print());
});
