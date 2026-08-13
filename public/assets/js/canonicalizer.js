/*
 * canonicalizer.js  --  RFC 8785 (JSON Canonicalization Scheme) for the browser.
 *
 * Byte-for-byte parity with src/crypto/Canonicalizer.php. The two are covered by
 * the shared test vectors in tests/vectors/canonical.json. If you change one you
 * must change the other and re-run the parity test.
 *
 * Profile (build spec section 7): UTF-8 output; object keys sorted by UTF-16 code
 * unit; no insignificant whitespace; decimals passed as strings (never JS
 * numbers); UUIDs as lowercase 32-char hex; timestamps ISO-8601 UTC ms "Z";
 * every payload carries a "schema" domain separator field.
 *
 * Exposes window.Canonicalizer with encode(value) -> Uint8Array (UTF-8 bytes)
 * and encodeString(value) -> string.
 */
(function () {
  'use strict';

  function serialize(value) {
    if (value === null) return 'null';
    if (value === true) return 'true';
    if (value === false) return 'false';
    const t = typeof value;
    if (t === 'number') {
      if (!Number.isInteger(value)) {
        throw new Error('Canonicalizer: non-integer numbers are forbidden; pass decimals as strings.');
      }
      return String(value);
    }
    if (t === 'bigint') return value.toString();
    if (t === 'string') return serializeString(value);
    if (Array.isArray(value)) return serializeArray(value);
    if (t === 'object') return serializeObject(value);
    throw new Error('Canonicalizer: unsupported type ' + t);
  }

  function serializeArray(list) {
    return '[' + list.map(serialize).join(',') + ']';
  }

  function serializeObject(obj) {
    const keys = Object.keys(obj);
    keys.sort(compareCodeUnits);
    const parts = keys.map(function (k) {
      return serializeString(k) + ':' + serialize(obj[k]);
    });
    return '{' + parts.join(',') + '}';
  }

  // Compare by UTF-16 code unit. JS strings are already UTF-16, so a plain
  // lexicographic comparison of the code-unit sequence is what RFC 8785 wants.
  function compareCodeUnits(a, b) {
    const n = Math.min(a.length, b.length);
    for (let i = 0; i < n; i++) {
      const ca = a.charCodeAt(i);
      const cb = b.charCodeAt(i);
      if (ca !== cb) return ca - cb;
    }
    return a.length - b.length;
  }

  function serializeString(s) {
    let out = '"';
    for (let i = 0; i < s.length; i++) {
      const c = s.charCodeAt(i);
      switch (c) {
        case 0x08: out += '\\b'; break;
        case 0x09: out += '\\t'; break;
        case 0x0a: out += '\\n'; break;
        case 0x0c: out += '\\f'; break;
        case 0x0d: out += '\\r'; break;
        case 0x22: out += '\\"'; break;
        case 0x5c: out += '\\\\'; break;
        default:
          if (c < 0x20) {
            out += '\\u' + c.toString(16).padStart(4, '0');
          } else {
            out += s[i];
          }
      }
    }
    return out + '"';
  }

  function encodeString(value) {
    return serialize(value);
  }

  function encode(value) {
    return new TextEncoder().encode(serialize(value));
  }

  // ---- canonical value helpers -----------------------------------------

  function bytesToHex(bytes) {
    const arr = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
    let hex = '';
    for (let i = 0; i < arr.length; i++) {
      hex += arr[i].toString(16).padStart(2, '0');
    }
    return hex;
  }

  function hexToBytes(hex) {
    hex = hex.toLowerCase();
    if (hex.length % 2 !== 0 || /[^0-9a-f]/.test(hex)) {
      throw new Error('hexToBytes: invalid hex');
    }
    const out = new Uint8Array(hex.length / 2);
    for (let i = 0; i < out.length; i++) {
      out[i] = parseInt(hex.substr(i * 2, 2), 16);
    }
    return out;
  }

  function decimalString(value, scale) {
    value = String(value);
    if (!/^-?\d+(\.\d+)?$/.test(value)) {
      throw new Error('decimalString: not a plain decimal: ' + value);
    }
    const neg = value.startsWith('-');
    value = value.replace(/^-/, '');
    let [intPart, fracPart = ''] = value.split('.');
    intPart = intPart.replace(/^0+/, '') || '0';
    if (fracPart.length > scale) {
      fracPart = fracPart.slice(0, scale);
    } else {
      fracPart = fracPart.padEnd(scale, '0');
    }
    let out = scale > 0 ? intPart + '.' + fracPart : intPart;
    if (neg && !/^0(\.0*)?$/.test(out)) out = '-' + out;
    return out;
  }

  // Date -> ISO-8601 UTC millisecond form with Z suffix.
  function toIso8601Utc(date) {
    const d = date instanceof Date ? date : new Date(date);
    return d.toISOString().replace(/(\.\d{3})\d*Z$/, '$1Z');
  }

  window.Canonicalizer = {
    encode: encode,
    encodeString: encodeString,
    bytesToHex: bytesToHex,
    hexToBytes: hexToBytes,
    decimalString: decimalString,
    toIso8601Utc: toIso8601Utc
  };
})();
