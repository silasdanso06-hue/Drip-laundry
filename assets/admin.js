'use strict';

const copyPrices = document.querySelector('#copy-prices');
if (copyPrices) {
    copyPrices.hidden = false;
    copyPrices.addEventListener('click', async () => {
        const message = document.querySelector('#customer-message');
        const status = document.querySelector('#share-status');
        try {
            await navigator.clipboard.writeText(message.value);
            status.textContent = 'Price list copied. Paste it into your customer conversation and review before sending.';
        } catch {
            message.focus();
            message.select();
            status.textContent = 'Message selected. Use your device’s Copy command, then paste it into your customer conversation.';
        }
    });
}
