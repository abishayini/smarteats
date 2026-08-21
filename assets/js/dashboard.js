/* =====================================================================
   Smart Eats - live order board

   Polls a lightweight endpoint and tells staff when the board has
   changed. It deliberately does not re-render the board itself: the
   server already produces that markup, and duplicating it here would
   mean two versions to keep in step.
   ===================================================================== */

(function () {
  'use strict';

  var board = window.SMARTEATS_BOARD;
  if (!board) return;

  var POLL_MS = 10000;
  var AUTO_REFRESH_MS = 6000;

  var alertBox = document.getElementById('new-order-alert');
  var detail = document.getElementById('new-order-detail');
  var refreshNow = document.getElementById('refresh-now');
  var dismiss = document.getElementById('dismiss-alert');

  var knownStamp = board.stamp;
  var dismissed = false;
  var refreshTimer = null;

  /* ---------------------------------------------------------------- */
  /* Audible alert                                                     */
  /* ---------------------------------------------------------------- */
  /* A short tone generated in the browser, so there is no audio file
     to ship and nothing to load. Browsers block audio until the user
     has interacted with the page, so this is best-effort: the visible
     banner is what staff actually rely on. */
  function chime() {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;

      var ctx = new Ctx();
      var now = ctx.currentTime;

      [880, 1175].forEach(function (frequency, index) {
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        var start = now + index * 0.18;

        osc.type = 'sine';
        osc.frequency.value = frequency;
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(0.25, start + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.16);

        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(start);
        osc.stop(start + 0.18);
      });

      setTimeout(function () { ctx.close(); }, 1200);
    } catch (error) {
      /* Audio is an enhancement; a failure here must not stop the poll. */
    }
  }

  /* ---------------------------------------------------------------- */
  /* Alert banner                                                      */
  /* ---------------------------------------------------------------- */
  function announce(newest) {
    if (!alertBox || dismissed) return;

    detail.textContent = newest
      ? newest.order_number + ' from ' + newest.customer +
        ' (' + newest.type + ', ' + newest.total + ')'
      : 'The board has changed.';

    alertBox.hidden = false;
    document.title = '(!) ' + document.title.replace(/^\(!\)\s*/, '');

    chime();

    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(function () {
      window.location.reload();
    }, AUTO_REFRESH_MS);
  }

  if (refreshNow) {
    refreshNow.addEventListener('click', function () {
      window.location.reload();
    });
  }

  if (dismiss) {
    dismiss.addEventListener('click', function () {
      clearTimeout(refreshTimer);
      alertBox.hidden = true;
      dismissed = true;
      document.title = document.title.replace(/^\(!\)\s*/, '');
    });
  }

  /* ---------------------------------------------------------------- */
  /* Polling                                                           */
  /* ---------------------------------------------------------------- */
  function poll() {
    fetch(board.endpoint, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    })
      .then(function (response) {
        // A signed-out session returns 403; reloading sends the user to
        // the sign-in page rather than polling forever in the background.
        if (response.status === 403) {
          window.location.reload();
          throw new Error('Session ended');
        }
        return response.json();
      })
      .then(function (data) {
        if (!data.ok) return;

        updateCounts(data.counts);

        if (data.stamp !== knownStamp) {
          knownStamp = data.stamp;
          announce(data.newest);
        }
      })
      .catch(function () {
        /* A dropped request is not worth interrupting service for. The
           next poll will pick things up again. */
      });
  }

  function updateCounts(counts) {
    var values = document.querySelectorAll('.stats .stat__value');
    if (values.length < 3) return;

    values[0].textContent = counts.pending;
    values[1].textContent = counts.preparing;
    values[2].textContent = counts.ready + counts.delivering;
  }

  setInterval(poll, POLL_MS);

  // Check straight away when a tab is brought back to the front.
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) poll();
  });
})();
