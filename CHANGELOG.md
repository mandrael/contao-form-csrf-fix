# Changelog

## 1.0.0 (2026-06-10)

Initial release.

- `kernel.response` listener (priority `-1004`) that, for successful frontend
  HTML responses containing a rendered CSRF token:
  - always makes the response private (form pages never enter the shared
    HTTP cache; also closes a pre-existing cache-poisoning window),
  - injects a server-side marker cookie for cookie-less requests so Contao's
    core `CsrfTokenCookieSubscriber` keeps the token in the HTML and sets the
    `csrf_*` cookie on the very first page view.
- Solves the "Invalid request token" (400) problem for first-time visitors whose
  tracking/consent scripts set cookies between page load and form submission
  (contao/contao#2820).
- Supports Contao 4.13 – 5.x, PHP 8.1+ (CI: 8.1–8.4, preview 8.5).
- Optional diagnostic response header via the
  `contao_form_csrf_fix.diagnostic_header` parameter.
- Battle-tested on a production multi-domain Contao 4.13 installation before
  release.
