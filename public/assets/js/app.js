/*
 * app.js  --  thin UI glue (build spec section 20 step 8). Enrollment, sign-in,
 * sealed-bid commit and reveal. All cryptography happens in webcrypto-client.js;
 * this file only wires the DOM to those calls and the JSON API. The private key
 * never leaves the browser.
 */
(function () {
  'use strict';

  var state = { csrf: null, keyId: null };

  function setStatus(id, msg, ok) {
    var el = document.getElementById(id);
    el.textContent = msg;
    el.className = 'status ' + (ok ? 'ok' : 'err');
  }

  async function api(method, path, body) {
    var headers = { 'Content-Type': 'application/json' };
    if (state.csrf) headers['X-CSRF-Token'] = state.csrf;
    var res = await fetch(path, {
      method: method,
      headers: headers,
      body: body ? JSON.stringify(body) : undefined,
      credentials: 'same-origin'
    });
    var data = await res.json().catch(function () { return {}; });
    if (!res.ok) throw new Error(data.error || ('HTTP ' + res.status));
    return data;
  }

  async function ensureCsrf() {
    if (!state.csrf) {
      var d = await api('GET', '/api/csrf');
      state.csrf = d.token;
    }
  }

  document.getElementById('btn-enroll').addEventListener('click', async function () {
    try {
      var spki = await window.WebCryptoClient.enroll();
      setStatus('enroll-status', 'Key generated locally. Sign in, then it will enroll.', true);
      state.pendingSpki = spki;
    } catch (e) {
      setStatus('enroll-status', 'Enrollment failed: ' + e.message, false);
    }
  });

  document.getElementById('btn-login').addEventListener('click', async function () {
    try {
      await ensureCsrf();
      var d = await api('POST', '/api/login', {
        username: document.getElementById('login-username').value,
        password: document.getElementById('login-password').value
      });
      state.csrf = d.csrf || state.csrf;
      setStatus('login-status', 'Signed in.', true);
      // If a key was generated but not yet enrolled, enroll it now.
      if (state.pendingSpki) {
        var e = await api('POST', '/api/keys/enroll', { spki_base64: state.pendingSpki });
        state.keyId = e.key_id;
        state.pendingSpki = null;
        setStatus('enroll-status', 'Public key enrolled. key_id=' + e.key_id, true);
      }
    } catch (e) {
      setStatus('login-status', 'Sign in failed: ' + e.message, false);
    }
  });

  document.getElementById('btn-commit').addEventListener('click', async function () {
    try {
      if (!state.keyId) throw new Error('Enroll a key and sign in first.');
      var rfq = document.getElementById('commit-rfq').value.trim();
      var bidder = document.getElementById('commit-bidder').value.trim();
      var amount = window.Canonicalizer.decimalString(document.getElementById('commit-amount').value, 2);
      var bid = {
        schema: 'PROCUREMENT-BID-COMMITMENT-V1',
        amount: amount,
        currency: 'MWK',
        rfq_id: rfq
      };
      var c = await window.WebCryptoClient.commitBid(rfq, bidder, bid);
      var d = await api('POST', '/api/bids/commit', {
        rfq_id: rfq, bidder_id: bidder, C: c.C, sigC: c.sigC, key_id: state.keyId
      });
      setStatus('commit-status', 'Committed. bid_id=' + d.bid_id + ' (nonce kept locally)', true);
      document.getElementById('reveal-bid').value = d.bid_id;
      document.getElementById('reveal-rfq').value = rfq;
    } catch (e) {
      setStatus('commit-status', 'Commit failed: ' + e.message, false);
    }
  });

  document.getElementById('btn-reveal').addEventListener('click', async function () {
    try {
      if (!state.keyId) throw new Error('Enroll a key and sign in first.');
      var rfq = document.getElementById('reveal-rfq').value.trim();
      var bidId = document.getElementById('reveal-bid').value.trim();
      var r = await window.WebCryptoClient.revealBid(rfq);
      var d = await api('POST', '/api/bids/reveal', {
        bid_id: bidId,
        canonical_bid: r.canonical_bid,
        nonce: r.nonce,
        sigR: r.sigR,
        key_id: state.keyId
      });
      setStatus('reveal-status', 'Reveal result: ' + d.status, d.status === 'revealed');
    } catch (e) {
      setStatus('reveal-status', 'Reveal failed: ' + e.message, false);
    }
  });
})();
