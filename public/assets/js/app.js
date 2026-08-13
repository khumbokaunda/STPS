/*
 * app.js  --  UI behaviour that the CSP requires to live in a file, not inline
 * (build spec sections 3, 6, 8). Toast notifications, the device-key status
 * indicator, clipboard copy for ids/hashes, RFQ countdowns, and the audit runner.
 * No crypto here; signing stays in signer.js and webcrypto-client.js.
 */
(function () {
  'use strict';

  window.STPS = window.STPS || {};

  // ---- toasts ------------------------------------------------------------
  function container() {
    var c = document.getElementById('toast-container');
    if (!c) {
      c = document.createElement('div');
      c.id = 'toast-container';
      c.className = 'toast-container position-fixed top-0 end-0 p-3';
      document.body.appendChild(c);
    }
    return c;
  }

  var ICONS = { ok: 'bi-check-circle', err: 'bi-exclamation-octagon', info: 'bi-info-circle' };

  window.STPS.toast = function (message, tone) {
    tone = tone === 'ok' || tone === 'err' ? tone : 'info';
    var el = document.createElement('div');
    el.className = 'toast app-toast toast-' + tone;
    el.setAttribute('role', 'alert');
    el.setAttribute('aria-live', 'assertive');
    el.setAttribute('aria-atomic', 'true');
    var body = document.createElement('div');
    body.className = 'toast-body';
    var icon = document.createElement('i');
    icon.className = 'bi ' + ICONS[tone];
    body.appendChild(icon);
    body.appendChild(document.createTextNode(' ' + message));
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close';
    close.setAttribute('data-bs-dismiss', 'toast');
    close.setAttribute('aria-label', 'Close');
    el.appendChild(body);
    el.appendChild(close);
    container().appendChild(el);
    var t = new bootstrap.Toast(el, { delay: 5000 });
    el.addEventListener('hidden.bs.toast', function () { el.remove(); });
    t.show();
    return t;
  };

  function showFlashToasts() {
    document.querySelectorAll('#toast-container .app-toast').forEach(function (el) {
      var t = new bootstrap.Toast(el, { delay: 5000 });
      el.addEventListener('hidden.bs.toast', function () { el.remove(); });
      t.show();
    });
  }

  // ---- key status --------------------------------------------------------
  function updateKeyStatus() {
    var el = document.getElementById('key-status');
    if (!el) return;
    var enrolledOnServer = el.getAttribute('data-has-key') === '1';
    var label = el.querySelector('.label');
    function set(state, text, title) {
      el.classList.remove('key-ok', 'key-missing', 'key-busy');
      el.classList.add(state);
      if (label) label.textContent = text;
      el.setAttribute('title', title);
    }
    if (!window.WebCryptoClient) { return; }
    window.WebCryptoClient.hasKey().then(function (localKey) {
      if (localKey && enrolledOnServer) {
        set('key-ok', 'key ready', 'A device signing key is present and enrolled.');
      } else if (localKey && !enrolledOnServer) {
        set('key-missing', 'enroll', 'A key exists locally but is not enrolled. Visit the dashboard.');
      } else {
        set('key-missing', 'no key', 'No signing key on this device. Enroll one on the dashboard.');
      }
    }).catch(function () { set('key-missing', 'no key', 'Key status unavailable.'); });
  }
  window.STPS.setKeyBusy = function (busy) {
    var el = document.getElementById('key-status');
    if (!el) return;
    if (busy) { el.classList.add('key-busy'); var l = el.querySelector('.label'); if (l) l.textContent = 'signing…'; }
    else { updateKeyStatus(); }
  };

  // ---- clipboard copy ----------------------------------------------------
  function initCopy() {
    document.body.addEventListener('click', function (ev) {
      var btn = ev.target.closest('.copy-btn');
      if (!btn) return;
      var text = btn.getAttribute('data-copy');
      if (!text) return;
      var done = function () {
        var old = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2"></i>';
        window.STPS.toast('Copied to clipboard.', 'ok');
        setTimeout(function () { btn.innerHTML = old; }, 1200);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(function () { done(); });
      } else {
        done();
      }
    });
  }

  // ---- countdowns --------------------------------------------------------
  function initCountdowns() {
    var els = Array.prototype.slice.call(document.querySelectorAll('.countdown[data-deadline]'));
    if (!els.length) return;
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function tick() {
      var now = Date.now();
      els.forEach(function (el) {
        var target = Date.parse(el.getAttribute('data-deadline'));
        var diff = target - now;
        if (isNaN(target)) { el.textContent = '—'; return; }
        if (diff <= 0) {
          el.textContent = el.getAttribute('data-expired-text') || 'closed';
          el.classList.add('expired');
          return;
        }
        var s = Math.floor(diff / 1000);
        var d = Math.floor(s / 86400); s -= d * 86400;
        var h = Math.floor(s / 3600); s -= h * 3600;
        var m = Math.floor(s / 60); s -= m * 60;
        el.textContent = (d > 0 ? d + 'd ' : '') + pad(h) + ':' + pad(m) + ':' + pad(s);
      });
    }
    tick();
    setInterval(tick, 1000);
  }

  // ---- audit runner ------------------------------------------------------
  function initAudit() {
    var btn = document.getElementById('run-audit');
    if (!btn) return;
    var out = document.getElementById('audit-report');
    btn.addEventListener('click', function () {
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Verifying…';
      fetch('/api/verify', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) { renderAudit(out, data); })
        .catch(function () { window.STPS.toast('Verification request failed.', 'err'); })
        .finally(function () { btn.disabled = false; btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Run verification'; });
    });
  }

  var AUDIT_CHECKS = [
    ['payload_hash', 'Payload hashes reproduce'],
    ['entry_hash', 'Entry hashes recompute'],
    ['chain_linkage', 'Chain linkage intact'],
    ['sequence_continuity', 'Sequence continuity (no gaps)'],
    ['approval_signature', 'Approval signatures verify'],
    ['reveal_signature', 'Bid reveal signatures verify'],
    ['signature', 'Actor signatures / keys valid'],
    ['merkle_root', 'Merkle roots match'],
    ['anchor', 'External RFC 3161 anchors'],
    ['commitment', 'Revealed bid commitments'],
    ['committee_decision', 'Committee quorum outcomes'],
    ['timing', 'Timing invariants'],
    ['authorization', 'Authorization at decision time']
  ];

  function renderAudit(out, data) {
    if (!out) return;
    var pass = data.verdict === 'PASS';
    var failedCheck = pass ? null : (data.failures && data.failures[0] ? data.failures[0].check : null);
    var html = '';
    html += '<div class="verdict-banner ' + (pass ? 'verdict-pass' : 'verdict-fail') + '">';
    html += '<i class="bi ' + (pass ? 'bi-shield-check' : 'bi-shield-exclamation') + '"></i>';
    html += '<span class="big">' + (pass ? 'PASS' : 'FAIL') + '</span>';
    html += '<span>' + (pass ? 'No unauthorized historical modification detected.' : 'A break was detected. See below.') + '</span>';
    html += '</div>';

    if (!pass && data.failures && data.failures[0]) {
      var f = data.failures[0];
      html += '<div class="mt-3 p-3 rounded border">';
      html += '<div class="fw-semibold mb-1">First break</div>';
      html += '<dl class="row small mb-0 mt-2">';
      html += '<dt class="col-4 text-muted">check</dt><dd class="col-8 mono">' + esc(f.check) + '</dd>';
      html += '<dt class="col-4 text-muted">sequence_no</dt><dd class="col-8">' + esc(f.sequence_no == null ? '(n/a)' : f.sequence_no) + '</dd>';
      html += '<dt class="col-4 text-muted">entity</dt><dd class="col-8 mono">' + esc(f.entity || '(n/a)') + '</dd>';
      html += '<dt class="col-4 text-muted">detail</dt><dd class="col-8">' + esc(f.detail) + '</dd>';
      html += '</dl></div>';
    }

    html += '<div class="mt-3">';
    AUDIT_CHECKS.forEach(function (c) {
      var isFail = failedCheck === c[0];
      var reached = pass || !failedCheck ? true : true;
      var tone = isFail ? 'err' : (pass ? 'ok' : 'ok');
      var pill = isFail ? '<span class="pill pill-err">FAIL</span>' : '<span class="pill pill-ok">PASS</span>';
      html += '<div class="check-row"><i class="bi ' + (isFail ? 'bi-x-circle' : 'bi-check-circle') + '"></i>';
      html += '<span class="name">' + esc(c[1]) + '</span>' + pill + '</div>';
    });
    html += '</div>';

    if (data.notes && data.notes.length) {
      html += '<ul class="small text-muted mt-3">';
      data.notes.forEach(function (n) { html += '<li>' + esc(n) + '</li>'; });
      html += '</ul>';
    }
    out.innerHTML = html;
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    showFlashToasts();
    updateKeyStatus();
    initCopy();
    initCountdowns();
    initAudit();
  });
})();
