/* =====================================================================
   Smart Eats - site-wide JavaScript
   Loaded by includes/footer.php and includes/panel_footer.php.

   The site works without JavaScript; everything here is an enhancement.
   ===================================================================== */

(function () {
  'use strict';

  var App = window.SMARTEATS || {};

  /* ---------------------------------------------------------------- */
  /* Mobile navigation                                                 */
  /* ---------------------------------------------------------------- */
  var toggle = document.querySelector('.nav-toggle');
  var nav = document.getElementById('primary-nav');

  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  /* ---------------------------------------------------------------- */
  /* Helpers shared by later phases                                    */
  /* ---------------------------------------------------------------- */

  /** POST JSON to an endpoint under /api, with the CSRF token attached. */
  App.post = function (endpoint, data) {
    return fetch(App.baseUrl + '/api/' + endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': App.csrf,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(data || {})
    }).then(function (res) {
      return res.json().catch(function () {
        throw new Error('The server returned an unexpected response.');
      });
    });
  };

  /** Update every basket badge on the page. */
  App.setCartCount = function (count) {
    document.querySelectorAll('[data-cart-count]').forEach(function (el) {
      el.textContent = count;
    });
  };

  /** Show a short-lived message at the top of the main content. */
  App.notify = function (message, type) {
    var host = document.querySelector('#main, .panel__content');
    if (!host) return;

    var box = document.createElement('div');
    box.className = 'alert alert--' + (type || 'success');
    box.setAttribute('role', 'status');
    box.textContent = message;
    host.prepend(box);

    setTimeout(function () { box.remove(); }, 4000);
  };

  /* ---------------------------------------------------------------- */
  /* Form guards                                                       */
  /* ---------------------------------------------------------------- */

  // Prevent accidental double submission on slow pages.
  document.querySelectorAll('form[data-once]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var button = form.querySelector('[type="submit"]');
      if (button) {
        button.disabled = true;
        button.dataset.originalText = button.textContent;
        button.textContent = 'Working...';
      }
    });
  });

  // Confirm destructive actions.
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (event) {
      if (!window.confirm(el.dataset.confirm)) {
        event.preventDefault();
      }
    });
  });

  window.SMARTEATS = App;
})();
