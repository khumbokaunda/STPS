/*
 * signer.js  --  wires server-rendered forms to browser-held signing keys
 * (build spec section 9). The private key never leaves the browser.
 *
 * Form conventions:
 *   <form data-sign="generic" data-domain="PROCUREMENT-APPROVAL-V1" ...>
 *     inputs carrying data-canon="fieldName" contribute to the signed canonical
 *     payload; data-decimal="2" formats that field as a fixed-scale decimal.
 *   On submit we build { schema: domain, ...fields }, canonicalize it exactly as
 *   the PHP Canonicalizer does, sign to_sign, and inject _signature + _key_id
 *   hidden fields, then submit the form normally. The SERVER rebuilds the same
 *   canonical from the posted fields and re-verifies, trusting only the signature.
 *
 *   data-sign="commit" and data-sign="reveal" use the sealed-bid helpers.
 *   data-sign="enroll" generates and enrolls a device key.
 */
(function () {
  'use strict';

  window.STPS = window.STPS || {};

  function keyId() {
    var meta = document.querySelector('meta[name="stps-key-id"]');
    return (meta && meta.content) || window.STPS.keyId || null;
  }

  function setHidden(form, name, value) {
    var el = form.querySelector('input[name="' + name + '"]');
    if (!el) {
      el = document.createElement('input');
      el.type = 'hidden';
      el.name = name;
      form.appendChild(el);
    }
    el.value = value;
  }

  function buildCanonicalObject(form) {
    var obj = { schema: form.dataset.domain };
    form.querySelectorAll('[data-canon]').forEach(function (el) {
      var name = el.getAttribute('data-canon');
      var val = el.value;
      var dec = el.getAttribute('data-decimal');
      if (dec !== null) val = window.Canonicalizer.decimalString(val, parseInt(dec, 10));
      obj[name] = val;
    });
    return obj;
  }

  function requireKey() {
    var k = keyId();
    if (!k) {
      throw new Error('No signing key on this device. Enroll a key first (Dashboard).');
    }
    return k;
  }

  async function handleGeneric(form) {
    var k = requireKey();
    // Stamp a client timestamp into any [data-timestamp] field (created_at,
    // decided_at, ...) so the canonical payload is stable and reproducible.
    form.querySelectorAll('[data-timestamp]').forEach(function (ts) {
      if (!ts.value) ts.value = window.Canonicalizer.toIso8601Utc(new Date());
    });
    var obj = buildCanonicalObject(form);
    var signed = await window.WebCryptoClient.signPayload(form.dataset.domain, obj);
    setHidden(form, '_signature', signed.signature);
    setHidden(form, '_key_id', k);
  }

  async function handleCommit(form) {
    var k = requireKey();
    var rfq = form.querySelector('[name="rfq_id"]').value.trim();
    var bidder = form.querySelector('[name="bidder_id"]').value.trim();
    var amountEl = form.querySelector('[name="amount"]');
    var amount = window.Canonicalizer.decimalString(amountEl.value, 2);
    var bid = { schema: 'PROCUREMENT-BID-COMMITMENT-V1', amount: amount, currency: 'MWK', rfq_id: rfq };
    var c = await window.WebCryptoClient.commitBid(rfq, bidder, bid);
    setHidden(form, 'C', c.C);
    setHidden(form, 'sigC', c.sigC);
    setHidden(form, '_key_id', k);
  }

  async function handleReveal(form) {
    var k = requireKey();
    var rfq = form.querySelector('[name="rfq_id"]').value.trim();
    var r = await window.WebCryptoClient.revealBid(rfq);
    setHidden(form, 'canonical_bid', r.canonical_bid);
    setHidden(form, 'nonce', r.nonce);
    setHidden(form, 'sigR', r.sigR);
    setHidden(form, '_key_id', k);
  }

  function attach(form) {
    var mode = form.dataset.sign;
    form.addEventListener('submit', function (ev) {
      if (form.__signed) return; // second pass: allow native submit
      ev.preventDefault();
      var fn = mode === 'commit' ? handleCommit : (mode === 'reveal' ? handleReveal : handleGeneric);
      fn(form).then(function () {
        form.__signed = true;
        form.submit();
      }).catch(function (e) {
        alert(e.message);
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-sign="generic"], form[data-sign="commit"], form[data-sign="reveal"]').forEach(attach);

    // Enrollment button: generate a device key, then POST the public key.
    var enrollBtn = document.getElementById('btn-enroll');
    if (enrollBtn) {
      enrollBtn.addEventListener('click', async function () {
        var status = document.getElementById('enroll-status');
        try {
          var spki = await window.WebCryptoClient.enroll();
          var csrf = document.querySelector('meta[name="stps-csrf"]').content;
          var res = await fetch('/api/keys/enroll', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            credentials: 'same-origin',
            body: JSON.stringify({ spki_base64: spki })
          });
          var data = await res.json();
          if (!res.ok) throw new Error(data.error || 'enroll failed');
          if (status) { status.textContent = 'Key enrolled (key_id ' + data.key_id + '). Reloading...'; status.className = 'status ok'; }
          setTimeout(function () { location.reload(); }, 800);
        } catch (e) {
          if (status) { status.textContent = 'Enrollment failed: ' + e.message; status.className = 'status err'; }
        }
      });
    }
  });
})();
