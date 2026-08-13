# STPS Frontend Build Specification

Status: frontend build and redesign spec for the existing STPS repository. Read
alongside the code, not instead of it. The backend, routing, and client crypto
are already built and correct. This document is about visual quality, information
architecture, component consistency, and UX completeness. Do not change backend
behaviour or the security wiring. Where this document and the security wiring
disagree, the wiring wins.

## 0. The quality bar
A fluid, production-grade internal application: coherent spacing, a real type
scale, consistent components, clear states for empty/loading/error/permission,
smooth restrained motion, full responsiveness to phone. Lift every page to that
bar without breaking a single security hook.

## 1. What must not change (load-bearing)
1. Server-rendered PHP views under `/public/views` via `View::render` into
   `layout.php`. No SPA, no client router, no build step.
2. The CSP in `src/http/View.php`. Everything ships locally; no inline scripts,
   inline handlers, static inline `style`, or CDN.
3. The signer contract in `public/assets/js/signer.js`: `data-sign` modes
   (`generic`/`commit`/`reveal`), `data-domain`, `data-canon`, `data-decimal`,
   `data-timestamp`, `#btn-enroll`/`#enroll-status`, the `stps-csrf` and
   `stps-key-id` meta tags, and injected `_signature`/`_key_id` plus `_csrf`.
   Restyle freely but keep every attribute, name, id, and meta tag.
4. `View::e()` output escaping on every dynamic value.
5. Tamper-evident framing. Never "tamper-proof".
6. The private key, bid nonce, and plaintext bid are never rendered or posted at
   commit time.

## 2. Stack (fixed)
HTML/CSS/JS. Bootstrap 5.3+ vendored locally, dark mode via
`data-bs-theme="dark"`. Server-rendered PHP. No SPA, no bundler.

## 3. Vendoring and CSP
Bootstrap CSS/JS under `public/assets/vendor/bootstrap/`; Bootstrap Icons under
`public/assets/vendor/bootstrap-icons/`. Local paths only. No inline styles,
scripts, or handlers. `data-bs-*` attributes are allowed. `app.css` loads after
Bootstrap. `font-src 'self'` added to the CSP for the local icon font.

Head load order: bootstrap.min.css, bootstrap-icons.css, app.css.
Body script order: bootstrap.bundle.min.js, canonicalizer.js,
webcrypto-client.js, signer.js, app.js.

## 4. Design system
Palette (dark): `--bg #0d141c`, `--surface #151f2a`, `--surface-2 #1b2733`,
`--ink #e7eef6`, `--muted #9db0c3`, `--line #26333f`, `--accent #3aa0e6`,
`--accent-ink #fff`, `--ok #37c978`, `--warn #e8b23a`, `--err #ef5b5b`,
`--info #6ea8fe`. Map onto Bootstrap theme variables. Radius 10/8/999px; type
scale 0.8/0.9/1/1.15/1.4/1.8rem, headings 600; mono for hashes/ids; soft
elevation; 120-180ms motion respecting `prefers-reduced-motion`; visible 2px
accent focus everywhere.

## 5. Shell and IA
Left sidebar (~240px) with role-aware nav grouped under section labels
(Procurement, Bidding, Evaluation, Contracts, Oversight), active-route highlight.
Top bar: page title, key-status indicator, user+roles dropdown, sign-out. Mobile:
sidebar becomes an offcanvas via a hamburger; tables scroll or stack. Flash
messages render as top-right toasts, never `alert()`.

## 6. Component library
Buttons (primary/secondary/subtle/danger, async spinner), cards, data tables
(zebra, hover, numeric right, mono ids, empty+loading states, mobile scroll/stack,
truncated ids with copy), status pills (single helper mapping status->color),
forms (labels, help text, validation, input-group addons keeping `data-decimal`,
a "signed with your device key" note), modals for consequential actions, a
lifecycle stepper and sealed-bid phase stepper, hash/id display with copy+tooltip,
a key-status indicator, and an RFQ countdown.

Pill mapping: pending/committed/submitted/draft/pending_approval -> warn;
approved/revealed/signed/active/verified/PASS -> ok;
rejected/invalid/expired/cancelled/failed/FAIL -> err;
published/reveal_open/evaluation/notified/issued -> info.

## 7. Pages
Login, Dashboard (device-key panel, stat tiles, recent ledger), Requisitions,
Approvals (work queue), Committee (quorum progress), Bidding documents (version
hashes), RFQs (prepare/publish/close with confirm modals, countdown), Bids
(two-phase commit/reveal stepper, same-device reveal guidance, specific errors),
Evaluation (scores with revision history), Awards/Contracts (confirm modals),
Execution, Finance, Admin, Audit (verifier result as a readable PASS/FAIL report
with per-check rows and a run button). Keep every route, field name, and signing
hook.

## 8. Interaction
Signing feedback (disable+spinner+inline error, no `alert()`); confirm modals for
irreversible actions naming the ledger consequence; empty/pending/failure states
everywhere; copy-to-clipboard confirmation; keyboard: focus trap in modals, esc
closes, enter submits, visible focus.

## 9. Accessibility
WCAG AA contrast; keyboard operable; status never by color alone; respect
`prefers-reduced-motion`; layout works from 360px.

## 10. Files
vendor/bootstrap/*, vendor/bootstrap-icons/*, app.css (rewritten), app.js (new),
layout.php (reworked), all views restyled.

## 11. Do-not
No CDN; no inline style/script/handlers; do not weaken CSP (except `font-src
'self'`); do not change field names/data-* attrs/meta tags/hidden fields; never
render the key/nonce/plaintext bid; keep `View::e()`; never "tamper-proof"; no
`alert()`; no SPA.

## 12. Acceptance
Bootstrap vendored + dark + no CDN; zero CSP violations; sidebar+topbar shell with
active highlight; toasts not alerts; full component library; every page restyled;
bids commit/reveal clear with same-device guidance; audit readable PASS/FAIL;
signing intact with all hooks/meta; responsive from 360px, keyboard accessible, AA
contrast; no inline styles/scripts, behaviour in app.js, crypto unchanged except
the error-display improvement.
