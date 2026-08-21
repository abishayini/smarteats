/* =====================================================================
   Smart Eats - card payment

   Mounts Stripe's Payment Element and confirms the payment. The card
   fields live inside a Stripe-hosted iframe, so this file never sees or
   handles a card number.
   ===================================================================== */

(function () {
  'use strict';

  var config = window.SMARTEATS_PAY;
  if (!config || !config.clientSecret || typeof Stripe === 'undefined') {
    return;
  }

  var stripe = Stripe(config.publishableKey);
  var elements = stripe.elements({
    clientSecret: config.clientSecret,
    appearance: {
      theme: 'stripe',
      variables: {
        colorPrimary: '#245c4a',
        colorText: '#1b211e',
        fontFamily: 'Inter, system-ui, sans-serif',
        borderRadius: '10px'
      }
    }
  });

  var paymentElement = elements.create('payment');
  paymentElement.mount('#payment-element');

  var button = document.getElementById('pay-button');
  var messageBox = document.getElementById('payment-message');

  function showMessage(text) {
    messageBox.textContent = text;
    messageBox.hidden = false;
  }

  function setBusy(busy) {
    button.disabled = busy;
    button.textContent = busy ? 'Processing...' : button.dataset.label;
  }

  button.dataset.label = button.textContent;

  button.addEventListener('click', function () {
    messageBox.hidden = true;
    setBusy(true);

    stripe.confirmPayment({
      elements: elements,
      confirmParams: { return_url: config.returnUrl }
    }).then(function (result) {
      // A successful payment redirects to return_url, so reaching this
      // point with a result means something needs the customer's
      // attention. Card and validation errors are safe to display;
      // anything else gets a generic message.
      if (result.error) {
        var type = result.error.type;
        showMessage(
          (type === 'card_error' || type === 'validation_error')
            ? result.error.message
            : 'The payment could not be completed. Your card has not been charged.'
        );
      }
      setBusy(false);
    }).catch(function () {
      showMessage('The payment could not be completed. Your card has not been charged.');
      setBusy(false);
    });
  });
})();
