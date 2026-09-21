/* vm-service-status.js
 * Shows customers and agents what Incident Command has in force: a service
 * pause, paused services or institutions, a frozen account, and notices the
 * Incident Commander approved. Checks every 60 seconds. The server refuses
 * frozen transactions regardless; this only tells people why, and turns a
 * refused request into a clear message instead of a generic error.
 */
(function () {
  'use strict';
  var URL = '/api/v1/system/status.php', last = null;
  var LABEL = { DEPOSIT: 'Deposits', CASHOUT: 'Cash-outs', IDENTITY_HOLD: 'Send to ID / phone', IDENTITY_CLAIM: 'Claims',
                CARD_LOAD: 'Card top-ups', PAYMENT_REQUEST: 'Payment requests', ENTERPRISE_BATCH: 'Business payments' };
  var COLOURS = { INFO: ['#eaf2fb', '#1F3A5F'], WARNING: ['#fff4d6', '#7a5200'], CRITICAL: ['#fde8e6', '#8a1c10'] };

  function el(tag, css, text) { var e = document.createElement(tag); if (css) e.style.cssText = css; if (text != null) e.textContent = text; return e; }

  function render(s) {
    var host = document.getElementById('vmServiceStatus');
    if (!host) {
      host = el('div', 'position:sticky;top:0;z-index:99999;font:14px/1.45 system-ui,-apple-system,Segoe UI,sans-serif');
      host.id = 'vmServiceStatus'; host.setAttribute('role', 'status'); host.setAttribute('aria-live', 'polite');
      document.body.insertBefore(host, document.body.firstChild);
    }
    host.innerHTML = '';
    var items = [];
    if (s.service_paused) items.push(['CRITICAL', 'Service paused', s.message]);
    if (s.account_frozen) items.push(['CRITICAL', 'Your account is on hold', 'You cannot make transactions right now. Please contact VouchMorph support.']);
    if (s.agent_frozen) items.push(['CRITICAL', 'Agent authorisation suspended', 'You cannot process claims or cash-outs until this is lifted. Contact VouchMorph operations.']);
    if (!s.service_paused && s.paused_flows && s.paused_flows.length) {
      items.push(['WARNING', 'Some services are paused', s.paused_flows.map(function (f) { return LABEL[f] || f; }).join(', ') + ' are temporarily unavailable. Everything else works normally.']);
    }
    if (!s.service_paused && s.paused_institutions && s.paused_institutions.length) {
      items.push(['WARNING', 'Some institutions are unavailable', 'Transfers to and from ' + s.paused_institutions.join(', ') + ' are temporarily paused. Your money is safe with them.']);
    }
    (s.notices || []).forEach(function (n) { items.push([n.level || 'INFO', n.title, n.body]); });
    items.forEach(function (it) {
      var c = COLOURS[it[0]] || COLOURS.INFO;
      var row = el('div', 'background:' + c[0] + ';color:' + c[1] + ';border-bottom:1px solid ' + c[1] + '33;padding:10px 16px;text-align:center');
      row.appendChild(el('strong', 'margin-right:8px', it[1]));
      row.appendChild(el('span', '', it[2]));
      host.appendChild(row);
    });
  }

  function poll() {
    fetch(URL, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (s) { last = s; render(s); })
      .catch(function () {});
  }

  // A refused transaction (423 frozen / 403 over the limit) shows its real reason.
  var origFetch = window.fetch;
  window.fetch = function (input, init) {
    return origFetch.apply(this, arguments).then(function (resp) {
      if ((resp.status === 423 || resp.status === 403) && /\/api\/v1\/(swap|payments|agent)\//.test(String(input && input.url || input))) {
        resp.clone().json().then(function (b) {
          if (b && (b.code || '').match(/FROZEN|LIMIT_EXCEEDED/)) { poll(); window.alert(b.error || b.message); }
        }).catch(function () {});
      }
      return resp;
    });
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', poll); else poll();
  setInterval(poll, 60000);
})();
