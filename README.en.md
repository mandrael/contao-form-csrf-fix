[🇩🇪 Deutsch](README.md) | **🇬🇧 English**

# Contao Form CSRF Fix

Solves the **"Invalid request token" (HTTP 400)** problem that first-time
visitors can run into when submitting a Contao form — not a Contao bug, but
intended core behaviour in favour of the cache, and a real problem for
everyone who values reliable forms over cache optimisation. Server-side
only, zero configuration. Contao 4.13 – 5.x.

Related core issue: [contao/contao#2820](https://github.com/contao/contao/issues/2820)

## The essentials

**The problem:** When someone opens a form page as their very first contact
with your website (ad link, newsletter, directly shared event link) and fills
in the form right away, the submission can fail with "Invalid request token".
The trigger is **JavaScript that sets cookies on the side** — Matomo, Google
Analytics, Meta Pixel, consent banners, chat widgets, heatmap tools … The
more such scripts a page embeds, the more likely visitors are hit. The
submission is lost; not everyone retries.

**Why isn't this fixed in core long ago?** It is not a bug in the strict
sense but a deliberate **design decision in favour of the shared cache**:
Contao wants to serve even pages containing forms from the page cache — one
stored copy for everyone. For that, first-time visitors deliberately receive
no CSRF cookie yet. Under this constraint the problem is fundamentally
unsolvable (one cached copy for everyone and individual tokens for each
visitor are mutually exclusive); core only mitigates it with a list of known
tracking cookies, which can never be complete.

**What this bundle does:** It deliberately flips the priority — **reliable
forms over maximum cache**. Pages containing a form are excluded from the
shared cache and always served fresh; in return, every visitor receives the
complete token pair (token in the form + matching cookie) on their very
first page view. After that, tracking JavaScript can set whatever it wants:
**the first submission attempt always works.** All pages without forms stay
fully cached, unchanged. CSRF protection itself remains fully active.

### When should you use this bundle — and when not?

✅ **Use it** when form pages are **opened directly** and must never fail:
- Event/course registrations whose links are distributed directly (ads, newsletters, social media, QR codes)
- Promoted landing pages with forms
- Sign-up/booking/order flows where every lost submission costs money
- In general: working forms have priority, speed optimisation is secondary

➖ **Optional** when your forms are practically only reached via internal
navigation (e.g. a classic contact form nobody links to directly): those
visitors already carry cookies from the pages they visited before — the
token pair is already in place and the problem normally does not occur at
all. If maximum cache hit rate matters there, you don't need the bundle. (It does no harm either — it only costs the page cache of form
pages. And on Contao < 5.3.31 with an active page cache, certain cookie
constellations could hit navigating visitors too — when in doubt: install.)

### What exactly does it do?

Contao's CSRF protection works like a doorman with a ticket system: the form
contains a ticket (token), the browser a matching stamp (cookie) — on
submission both must match. First-time visitors without cookies deliberately
get **no** pair ("whoever has nothing is harmless and gets waved through") —
that keeps the page cacheable. But if any script sets any cookie between
page load and submission, the doorman suddenly says "inspection!" — and the
visitor never received a ticket. Rejected, error 400.

The bundle makes sure that on form pages **everyone receives ticket and
stamp immediately on the first page view**. It invents nothing new — it
simply triggers, one request earlier, exactly the mechanism Contao already
uses for every visitor who carries cookies. The price: these pages can no
longer be served as one-copy-for-everyone from the cache — they are rendered
per visitor, just as is taken for granted for shopping carts, for example.

## Installation

Via the **Contao Manager**: search for `mandrael/contao-form-csrf-fix`,
install, done. Or via **Composer**:

```bash
composer require mandrael/contao-form-csrf-fix
```

Then clear the application cache as usual (the Contao Manager does this
automatically; console: `vendor/bin/contao-console cache:clear`).
No configuration needed.

> **Note:** If you already run this fix as app code (your own listener in
> `src/` following the same pattern), remove the app version when switching —
> otherwise two identical listeners will run.

**Without the Contao Manager** (plain Symfony application with Contao as a
bundle): additionally register the bundle in `config/bundles.php`:

```php
Mandrael\ContaoFormCsrfFix\ContaoFormCsrfFixBundle::class => ['all' => true],
```

## Verifying it works

Optionally enable a diagnostic header (in your installation's `config/config.yaml`):

```yaml
parameters:
    contao_form_csrf_fix.diagnostic_header: true
```

Then request a form page without cookies:

```bash
curl -sD - -o /dev/null https://example.com/your-form-page | grep -iE 'x-contao-csrf-fix|set-cookie|cache-control'
```

Expected: `X-Contao-Csrf-Fix: 1`, `Set-Cookie: csrf_…`, `Cache-Control: … private`
— and a non-empty `REQUEST_TOKEN` value in the HTML. A page *without* a form
must show none of these and stay cacheable.

## Compatibility

| Contao version | Token stripped from HTML | First-visit 400 bug | This bundle helps |
|---|---|---|---|
| 4.13.x | yes | yes (worst case) | ✅ |
| 5.0 – 5.3.30, 5.4, 5.5.0 – 5.5.6 | yes | yes | ✅ |
| 5.3.31+, 5.5.7+, 5.6.x, 5.7.x | no ([#8162](https://github.com/contao/contao/pull/8162)) | yes (cookie race remains) | ✅ |

PHP ≥ 8.1 (CI-tested on 8.1–8.4, preview testing on 8.5). End-of-life Contao versions (4.13, 5.0–5.2, 5.4–5.6) are
supported on a best-effort basis — the proper fix there is upgrading to a
supported LTS.

## Uninstall / rollback

```bash
composer remove mandrael/contao-form-csrf-fix
vendor/bin/contao-console cache:clear
```

The bundle leaves no data behind — afterwards you get Contao's stock
behaviour back (including the bug).

## FAQ

**Does this conflict with consent management / GDPR?**
No. The `csrf_*` cookie is strictly necessary for the requested service (form
submission) and does not require consent. Consider whitelisting it in your
consent tool and listing it in your privacy policy.

**Why not just disable caching for form pages?**
That alone does not fix the bug: the missing `csrf_*` cookie is triggered by
the *absence of cookies in the request*, not by the cache.

**Why not refresh the token via JavaScript?**
It would race against the very scripts causing the problem, and it would not
work for visitors without JavaScript.

**What about visitors who block all cookies?**
Their POST arrives cookie-less and falls under Contao's skip rule as before.
Works.

---

## Technical details (for developers)

**The core mechanism.** Contao's CSRF protection is a double-submit cookie
scheme: the form contains the token value, the `csrf_*` cookie carries the
same value; on POST they are compared (the `MemoryTokenStorage` is
initialised from the `csrf_*` cookies on every request). Two properties make
the system cache-friendly — and fragile:

1. **Lazy cookie:** `CsrfTokenCookieSubscriber::onKernelResponse()` only sets
   the `csrf_*` cookie when `requiresCsrf()` is true — i.e. when the request
   already carries a non-CSRF cookie (or the response sets cookies).
   Cookie-less first-time visitors get **no** cookie; in Contao < 5.3.31 all
   rendered token values are additionally removed from the HTML via
   `str_replace` so the page stays shared-cacheable.
2. **Skip rule:** `ContaoCsrfTokenManager::canSkipTokenValidation()` skips
   POST validation only for **zero cookies** (or exactly the `csrf_*` cookie
   alone) and an empty session. A single foreign cookie — any cookie —
   enforces validation.

**The race:** GET without cookies → no `csrf_*` cookie (token possibly
stripped) → tracking/consent JS sets cookies client-side → the POST carries
cookies → validation enforced → token/cookie pair incomplete →
`InvalidRequestTokenException` → 400. The core workaround (deny list in the
`StripCookiesSubscriber`, [#2876](https://github.com/contao/contao/pull/2876))
only filters *known* tracking cookies before the cache lookup / before
forwarding to the app — unknown cookies (custom consent tools, chat widgets,
heatmaps, new trackers) reopen the gap immediately. On Contao < 5.3.31 with
an active page cache, a visitor carrying *only* deny-listed cookies can even
be served the token-stripped cached variant — then the error hits navigating
visitors as well.

**What the listener changes.** A single `kernel.response` listener that runs
immediately **before** the core `CsrfTokenCookieSubscriber`. Its priority is
not hard-coded but read from the core at container compile time (core
priority + 2), because it differs between Contao versions (−1006 in 4.13 and
5.3.31+, −832 in 5.0 – 5.3.30). For successful frontend HTML responses (main
request, no `_token_check=false`, content type `text/html`, body present) it
checks whether the body contains an **actually rendered** token — matched
against `ContaoCsrfTokenManager::getUsedTokenValues()`, i.e. exactly the
data source the core itself uses for stripping (covers `REQUEST_TOKEN`
inputs as well as `{{request_token}}` in inline JS). Only then:

1. **`$response->setPrivate()` — always.** Token pages must never enter the
   shared cache. This also closes a pre-existing cache-poisoning window in
   core: when a request carries an unchanged `csrf_*` cookie plus another
   cookie, `setCookies()` sends no `Set-Cookie`, the
   `MakeResponsePrivateListener` does not kick in, and a response containing
   a user-bound token could be stored as `public`.
2. **Marker cookie into the request bag** (only when the request carries
   exclusively `csrf_*` cookies or none at all): `$request->cookies->set(…)`
   with a name guaranteed not to start with the configured
   `%contao.csrf_cookie_prefix%` (for exotic prefixes the name is padded
   automatically). The core subscriber reads the request cookies after us,
   therefore considers the request cookie-carrying and takes its normal
   `setCookies()` path: the token stays in the HTML, the `csrf_*` cookie is
   set. The marker exists only in the server-side request representation —
   it is **never** sent to the browser (the HTTP cache kernel forwards a
   clone of the request anyway).

**Why this does not weaken CSRF protection:** No validation is disabled and
no condition is relaxed. The listener exclusively triggers the code path the
core takes for every cookie-carrying visitor anyway — just one request
earlier. Cookie-less POSTs (skip path), Ajax POSTs, backend requests and
routes with `_token_check=false` remain untouched.

**Cost:** One `strpos` per used token value per frontend HTML response
(microseconds); form pages always hit PHP instead of the shared cache. Pages
without a rendered token are completely unaffected.

**APIs used** (identical from 4.13 through 5.7, all public):
`ContaoCsrfTokenManager::getUsedTokenValues()`,
`ScopeMatcher::isFrontendMainRequest()`, the `contao.csrf_cookie_prefix`
parameter, service ids `contao.csrf.token_manager` /
`contao.routing.scope_matcher`.

## Support & contributing

Please report bugs or questions as
[GitHub issues](https://github.com/mandrael/contao-form-csrf-fix/issues).
Pull requests welcome. This bundle is a community project and is not
affiliated with Contao GmbH or the Contao core team.

## License

MIT
