/* =====================================================================
   Smart Eats - basket interactions

   Adds dishes to the session basket without a page reload and keeps the
   header badge in step. Loaded after main.js, which provides
   SMARTEATS.post, .setCartCount and .notify.

   Phase 11 adds the restaurant switch. The endpoint answers a
   cross-restaurant add with needs_switch rather than acting, and this
   file asks the customer before sending the same request again with the
   confirmation attached. Nothing is emptied without a clear yes.
   ===================================================================== */

(function () {
  'use strict';

  var App = window.SMARTEATS;
  if (!App) return;

  /* ---------------------------------------------------------------- */
  /* Quantity stepper on the dish detail page                          */
  /* ---------------------------------------------------------------- */
  document.querySelectorAll('[data-qty-step]').forEach(function (button) {
    button.addEventListener('click', function () {
      var field = button.parentElement.querySelector('.qty__input');
      if (!field) return;

      var min = parseInt(field.min, 10) || 1;
      var max = parseInt(field.max, 10) || 20;
      var next = (parseInt(field.value, 10) || min) + parseInt(button.dataset.qtyStep, 10);

      field.value = Math.min(max, Math.max(min, next));
    });
  });

  /* ---------------------------------------------------------------- */
  /* Add to basket                                                     */
  /* ---------------------------------------------------------------- */
  document.querySelectorAll('[data-add-to-cart]').forEach(function (button) {
    button.addEventListener('click', function () {
      var quantity = 1;

      if (button.dataset.qtySource) {
        var field = document.querySelector(button.dataset.qtySource);
        quantity = parseInt(field && field.value, 10) || 1;
      }

      var originalLabel = button.textContent;
      var itemId = parseInt(button.dataset.itemId, 10);

      button.disabled = true;
      button.textContent = 'Adding...';

      function restore() {
        button.textContent = originalLabel;
        button.disabled = false;
      }

      function confirmed() {
        button.textContent = 'Added';
        setTimeout(restore, 1200);
      }

      /* One attempt. When allowSwitch is true the request carries the
         customer's answer to the "start a new basket?" question. */
      function send(allowSwitch) {
        return App.post('cart_action.php', {
          action: 'add',
          item_id: itemId,
          quantity: quantity,
          switch: allowSwitch === true
        }).then(function (data) {

          if (data.needs_switch) {
            // Nothing has gone wrong; a decision is needed. The basket is
            // only emptied if the customer says so.
            if (window.confirm(data.message)) {
              return send(true);
            }
            App.notify('Your basket from ' + data.from + ' has been kept.', 'info');
            restore();
            return null;
          }

          if (!data.ok) {
            throw new Error(data.error || 'The dish could not be added.');
          }

          App.setCartCount(data.cart.count);
          App.notify(data.message, 'success');
          updateBasketBar(data.cart);
          confirmed();
          return data;
        });
      }

      send(false).catch(function (error) {
        App.notify(error.message, 'error');
        restore();
      });
    });
  });

  /** Keep the "your basket is with X" bar in step after a switch. */
  function updateBasketBar(summary) {
    var bar = document.querySelector('.basket-bar');

    // The bar is rendered server-side. When the restaurant changes there
    // is no sensible partial update, so the next navigation redraws it;
    // only the obvious case of an emptied basket is handled here.
    if (bar && summary.count === 0) {
      bar.remove();
    }
  }

  /* ---------------------------------------------------------------- */
  /* Basket page                                                       */
  /* ---------------------------------------------------------------- */
  var cartForm = document.querySelector('[data-cart-form]');
  if (!cartForm) return;

  var currency = document.querySelector('[data-total="total"]');

  /** Apply a summary from the endpoint to the totals panel. */
  function applySummary(summary) {
    Object.keys(summary.formatted).forEach(function (key) {
      var cell = document.querySelector('[data-total="' + key + '"]');
      if (cell) cell.textContent = summary.formatted[key];
    });

    App.setCartCount(summary.count);

    var feeRow = document.querySelector('[data-fee-row]');
    if (feeRow) feeRow.hidden = summary.order_type === 'pickup';

    // A basket emptied to nothing needs the empty state, and a basket
    // that drops below the minimum needs the checkout button disabling,
    // both of which are rendered server-side.
    if (summary.count === 0 || summary.below_minimum) {
      window.location.reload();
    }
  }

  /** Send one line change and refresh the affected row. */
  function changeLine(line, quantity) {
    var itemId = parseInt(line.dataset.itemId, 10);

    return App.post('cart_action.php', {
      action: quantity < 1 ? 'remove' : 'update',
      item_id: itemId,
      quantity: quantity
    }).then(function (data) {
      if (!data.ok) throw new Error(data.error || 'The basket could not be updated.');

      if (quantity < 1) {
        line.remove();
      } else {
        var unit = parseFloat(line.dataset.unitPrice) || 0;
        var cell = line.querySelector('[data-line-total]');
        var symbol = currency ? currency.textContent.replace(/[\d.,\s]/g, '') : '';
        if (cell) cell.textContent = symbol + (unit * quantity).toFixed(2);
      }

      applySummary(data.cart);
    }).catch(function (error) {
      App.notify(error.message, 'error');
      window.location.reload();
    });
  }

  // Quantity steppers and direct edits save immediately.
  cartForm.querySelectorAll('[data-line]').forEach(function (line) {
    var field = line.querySelector('.qty__input');
    if (!field) return;

    var timer = null;
    field.addEventListener('change', function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        changeLine(line, parseInt(field.value, 10) || 0);
      }, 250);
    });

    line.querySelectorAll('[data-qty-step]').forEach(function (button) {
      button.addEventListener('click', function () {
        field.dispatchEvent(new Event('change'));
      });
    });
  });

  // Remove buttons.
  cartForm.querySelectorAll('[data-remove]').forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.preventDefault();
      var line = button.closest('[data-line]');
      if (line) changeLine(line, 0);
    });
  });

  // The explicit update button is redundant once scripting is active.
  var updateButton = cartForm.querySelector('[data-update-basket]');
  if (updateButton) updateButton.hidden = true;

  // Switching delivery and collection recalculates without a reload.
  var typeForm = document.querySelector('[data-order-type-form]');
  if (typeForm) {
    typeForm.querySelectorAll('input[name="order_type"]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        typeForm.querySelectorAll('.switch__option').forEach(function (option) {
          option.classList.toggle('is-active', option.contains(radio) && radio.checked);
        });

        App.post('cart_action.php', {
          action: 'order_type',
          order_type: radio.value
        }).then(function (data) {
          if (!data.ok) throw new Error(data.error);
          applySummary(data.cart);
        }).catch(function (error) {
          App.notify(error.message, 'error');
        });
      });
    });
  }
})();
