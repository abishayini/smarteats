/* =====================================================================
   Smart Eats - customer order tracking

   Watches an order for a status change and reloads the page when the
   kitchen moves it on, so a customer watching the tracker sees progress
   without pressing refresh.
   ===================================================================== */

(function () {
  'use strict';

  var tracker = document.querySelector('[data-track-order]');
  if (!tracker) return;

  var App = window.SMARTEATS;
  if (!App) return;

  var reference = tracker.dataset.trackOrder;
  var known = tracker.dataset.trackStatus;
  var POLL_MS = 15000;

  // Nothing further will happen to a finished order, so stop there
  // rather than polling an unchanging value indefinitely.
  if (known === 'completed' || known === 'cancelled') return;

  function poll() {
    fetch(App.baseUrl + '/api/order_status.php?order=' + encodeURIComponent(reference), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (data.ok && data.status !== known) {
          window.location.reload();
        }
      })
      .catch(function () {
        /* Ignore a dropped poll and try again on the next tick. */
      });
  }

  setInterval(poll, POLL_MS);

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) poll();
  });
})();
