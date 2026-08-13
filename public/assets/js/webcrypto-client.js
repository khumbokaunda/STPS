/*
 * webcrypto-client.js  --  browser-side cryptography (build spec section 9).
 *
 * The private key is generated in the browser as a NON-EXTRACTABLE ECDSA P-256
 * CryptoKey stored in IndexedDB. It never leaves the browser and is never
 * transmitted. Only the SPKI public key is sent to the server at enrollment.
 *
 * Signing model: to_sign = SHA-256(domain_separator || 0x1F || canonical_json).
 * We compute to_sign in the browser and then sign it with WebCrypto ECDSA/SHA-256
 * (WebCrypto hashes the message again). The server verifies openssl_verify over
 * the same to_sign bytes with SHA-256, so the two agree. WebCrypto emits IEEE
 * P1363 (raw r||s); the PHP Signer converts P1363 to DER before verifying.
 *
 * Depends on window.Canonicalizer (canonicalizer.js).
 */
(function () {
  'use strict';

  var DB_NAME = 'stps_keys';
  var STORE = 'keys';
  var US = 0x1f;

  function openDb() {
    return new Promise(function (resolve, reject) {
      var req = indexedDB.open(DB_NAME, 1);
      req.onupgradeneeded = function () {
        req.result.createObjectStore(STORE);
      };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { reject(req.error); };
    });
  }

  function idbPut(key, value) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(STORE, 'readwrite');
        tx.objectStore(STORE).put(value, key);
        tx.oncomplete = function () { resolve(); };
        tx.onerror = function () { reject(tx.error); };
      });
    });
  }

  function idbGet(key) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(STORE, 'readonly');
        var r = tx.objectStore(STORE).get(key);
        r.onsuccess = function () { resolve(r.result); };
        r.onerror = function () { reject(r.error); };
      });
    });
  }

  function bufToBase64(buf) {
    var bytes = new Uint8Array(buf);
    var bin = '';
    for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin);
  }

  function concatBytes(list) {
    var total = 0, i;
    for (i = 0; i < list.length; i++) total += list[i].length;
    var out = new Uint8Array(total);
    var off = 0;
    for (i = 0; i < list.length; i++) { out.set(list[i], off); off += list[i].length; }
    return out;
  }

  function strBytes(s) { return new TextEncoder().encode(s); }

  async function sha256(bytes) {
    var digest = await crypto.subtle.digest('SHA-256', bytes);
    return new Uint8Array(digest);
  }

  /* Generate a non-extractable P-256 keypair, persist the private key in
   * IndexedDB, and return the base64 SPKI public key for enrollment. */
  async function enroll() {
    var pair = await crypto.subtle.generateKey(
      { name: 'ECDSA', namedCurve: 'P-256' },
      false, // non-extractable private key
      ['sign']
    );
    // Public key must be extractable to export SPKI; regenerate as a pair where
    // only the public half is exportable.
    var spki = await crypto.subtle.exportKey('spki', pair.publicKey);
    await idbPut('signing_private', pair.privateKey);
    await idbPut('signing_public_spki', bufToBase64(spki));
    return bufToBase64(spki);
  }

  async function hasKey() {
    var k = await idbGet('signing_private');
    return !!k;
  }

  /* Compute to_sign = SHA-256(domain || 0x1F || canonical_json). */
  async function toSign(domainSeparator, canonicalBytes) {
    var msg = concatBytes([strBytes(domainSeparator), new Uint8Array([US]), canonicalBytes]);
    return sha256(msg);
  }

  /* Sign a to_sign digest, returning base64 P1363 signature. */
  async function signDigest(digest) {
    var priv = await idbGet('signing_private');
    if (!priv) throw new Error('No signing key enrolled on this device.');
    var sig = await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, priv, digest);
    return bufToBase64(sig);
  }

  /* Sign a canonical payload for a domain separator; returns base64 P1363. */
  async function signPayload(domainSeparator, payloadObject) {
    var canonical = window.Canonicalizer.encode(payloadObject);
    var digest = await toSign(domainSeparator, canonical);
    var sig = await signDigest(digest);
    return { canonical: new TextDecoder().decode(canonical), signature: sig };
  }

  /* ---- sealed-bid commit (build spec section 12) ----
   * C = SHA-256(SEP 0x1F rfq_id(16) 0x1F bidder_id(16) 0x1F canonical_bid 0x1F nonce)
   * sigC over SHA-256(SEP 0x1F C). Returns { C, sigC, nonce } base64.
   * The nonce and canonical bid are kept LOCALLY for the later reveal; only C and
   * sigC are sent at commit time (never the bid or the nonce).
   */
  async function commitBid(rfqIdHex, bidderIdHex, bidObject) {
    var SEP = 'PROCUREMENT-BID-COMMITMENT-V1';
    var canonical = window.Canonicalizer.encode(bidObject);
    var nonce = crypto.getRandomValues(new Uint8Array(32));
    var rfqId = window.Canonicalizer.hexToBytes(rfqIdHex);
    var bidderId = window.Canonicalizer.hexToBytes(bidderIdHex);
    var pre = concatBytes([
      strBytes(SEP), new Uint8Array([US]),
      rfqId, new Uint8Array([US]),
      bidderId, new Uint8Array([US]),
      canonical, new Uint8Array([US]),
      nonce
    ]);
    var C = await sha256(pre);
    var sigDigest = await sha256(concatBytes([strBytes(SEP), new Uint8Array([US]), C]));
    var sigC = await signDigest(sigDigest);
    // Persist reveal material locally (never sent at commit).
    await idbPut('reveal_' + rfqIdHex, {
      canonical: new TextDecoder().decode(canonical),
      nonce: bufToBase64(nonce)
    });
    return { C: bufToBase64(C), sigC: sigC, nonce: bufToBase64(nonce) };
  }

  /* Reveal: retrieve the locally kept bid + nonce, sign the reveal, return the
   * payload the server needs { canonical_bid, nonce, sigR }. */
  async function revealBid(rfqIdHex) {
    var material = await idbGet('reveal_' + rfqIdHex);
    if (!material) throw new Error('No local reveal material for this RFQ.');
    var SEP = 'PROCUREMENT-BID-REVEAL-V1';
    var canonicalBytes = strBytes(material.canonical);
    var digest = await toSign(SEP, canonicalBytes);
    var sigR = await signDigest(digest);
    return { canonical_bid: material.canonical, nonce: material.nonce, sigR: sigR };
  }

  window.WebCryptoClient = {
    enroll: enroll,
    hasKey: hasKey,
    signPayload: signPayload,
    commitBid: commitBid,
    revealBid: revealBid
  };
})();
