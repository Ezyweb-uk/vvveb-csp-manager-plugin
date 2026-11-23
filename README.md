# CSP Manager for Vvveb

A [Vvveb CMS](https://www.vvveb.com) plugin that sends a route-aware **Content Security Policy** (CSP): a strict
policy on the checkout page and a configurable, relaxed policy on the rest of the site. It starts in
report-only mode, collects violation reports, can email you about them, and merges with any CSP header a
payment plugin has already queued instead of overwriting it.

## Features

- **Two profiles:** *Checkout* (strict by default: scripts only from your own site) for the routes listed in
  "Checkout routes", and *Default* (relaxed) for every other storefront page. Each CSP directive is editable
  per profile in the settings.
- **Report-only or enforce:** start in report-only mode to see what the policy would block, then switch to
  enforce. The mode applies to the merged policy, including anything a payment plugin added.
- **Merging:** reads the CSP header other plugins have already queued (for example PayPal Smart Buttons' PayPal
  hosts and per-request nonce) and adds the profile's sources to it.
- **Violation reports:** built-in `/csp-manager-report` endpoint (written to the PHP error log), with optional
  email notification and a throttle so a busy page doesn't flood your inbox.
- **Checkout clean-up:** "Remove from checkout" takes theme widgets and third-party scripts out of the
  checkout page only, so their hosts don't need to be allowed in the strict policy (a payment page should
  load as little third-party code as possible). One entry per line: `#some-id` (the element with that id),
  `.some-class` (every element with that class, with its contents), or part of a URL such as a host name
  (script, img, iframe, link, source, embed and object tags whose src or href contains it). A separate switch
  removes Ezyweb's Cookie Notice banner and scripts from the checkout.
- **Speculation Rules on the checkout:** Landing puts its link-prefetch rules in an inline
  `<script type="speculationrules">`, which a strict checkout policy blocks. CSP Manager sends them as a
  `Speculation-Rules` header instead, pointing to its own route `/csp-manager-speculation-rules`. The route
  serves the active theme's `js/checkout-rules.json` if the theme has one, otherwise the same rules bundled with
  the plugin (`checkout-rules.json`), with the `application/speculationrules+json` type browsers require
  (a plain `.json` file is served as `application/json` and silently ignored), so no web server configuration
  is needed.
- **Theme compatibility script:** a strict policy also blocks the theme's own inline code. On the stock
  Landing theme that stops the checkout's Region list reloading when the country changes (so the shipping
  methods and totals aren't recalculated for the new address), "ship to a different address", the login,
  register and password-eye toggles, and the scroll animations. On every page where the enforced policy
  blocks inline code, CSP Manager adds `inline-compat.js`, which does the same work from an allowed file. It is
  decided from the final policy header, as the browser reads it (no `'unsafe-inline'`, or a nonce or hash), so
  it is added on the checkout in enforce mode, on other pages only if you remove `'unsafe-inline'` from the
  Default profile, and never in report-only mode, where nothing is blocked.
- **Strip known theme onclick handlers** (off by default): removes Landing's `onclick` attributes for the
  password eye and the login/register/shipping-address toggles from every page. Only turn it on if your theme
  has its own click listeners for those controls in an external script file; on the stock Landing theme it
  stops them working, as the compatibility script needs the attributes.

## Current scope: checkout support with PayPal Smart Buttons only

A strict checkout policy only works if it allows exactly the scripts, frames and connections the chosen
payment gateway needs. CSP Manager doesn't know those itself: each payment plugin has to declare them, by
queuing its own CSP header (with its hosts and a nonce for any inline script) before CSP Manager merges it.

At the moment only the **[PayPal Smart Buttons plugin](https://github.com/Ezyweb-uk/vvveb-paypal-smart-buttons-plugin)**
(0.5) does this. Its "Checkout security policy" setting, on *Automatic*, sends PayPal's
sources whenever CSP Manager is active.

Shipping plugins need nothing allowed, but they depend on the checkout's Region list and shipping refresh,
which the compatibility script keeps working (for example Ezyweb's
[Order Value Shipping](https://github.com/Ezyweb-uk/vvveb-order-value-shipping-plugin)).

With any other payment method (Vvveb's bundled payment plugin, card gateways and so on), keep CSP Manager in
**report-only** mode on the checkout, or add the gateway's hosts to the Checkout profile fields yourself, or
the policy will block its scripts. CSP Manager will be adapted to work with other payment plugins in the
future, through a common way for each gateway to register the sources it needs.

## Why the checkout needs a Content Security Policy (PCI DSS)

The Payment Card Industry Data Security Standard (**PCI DSS**) applies to every business that accepts card
payments. Version **4.0.1** is currently the only active version; the PCI Security Standards Council started
collecting comments for the next version in 2026 but hasn't released it.

### E-skimming

The main threat to an online checkout is **e-skimming** (also called Magecart or formjacking): an attacker
gets a malicious script onto the payment page, through a hacked plugin, a compromised third-party script or a
stored cross-site scripting flaw. The script then copies card details as they are typed, swaps the real
payment form for a fake one, or sends the shopper to a look-alike payment page. Because it runs in the
shopper's browser, server-side security alone doesn't see it.

A Content Security Policy tells the browser which scripts, frames and destinations the page may use. A
strict checkout policy (`script-src 'self'` plus the payment provider's hosts and a nonce) means an injected
inline script, or one loaded from an unknown host, is refused by the browser. CSP reports tell you when
something tries.

### PCI DSS requirements 6.4.3 and 11.6.1

PCI DSS v4.0 added two requirements for payment pages, mandatory since 31 March 2025:

- **6.4.3:** every script loaded on the payment page must be authorised, its integrity assured, and listed
  with a justification.
- **11.6.1:** a change- and tamper-detection mechanism must alert on unauthorised changes to the
  security-relevant HTTP headers and script contents of payment pages.

A CSP is the browser's built-in way to authorise scripts (6.4.3). Subresource Integrity hashes pin a script
to a known version (the PayPal Smart Buttons plugin uses one for its checkout script). CSP violation reports,
with CSP Manager's email notification, give you a detection and alerting feed (11.6.1). The CSP header is
itself one of the security-relevant headers 11.6.1 refers to.

### SAQ A and its script eligibility criterion

Merchants that hand all card data entry to a PCI DSS compliant payment provider can validate compliance with
the shortest self-assessment questionnaire, **SAQ A**, instead of the much longer SAQ A-EP or SAQ D. When
the provider's payment form is embedded in the merchant's page (for example in an iframe), all fields that
capture card data have to be inside the provider's iframe (PCI SSC FAQ 1438).

In January 2025 the PCI Security Standards Council revised SAQ A (SAQ A r1, in force from 31 March 2025).
It removed requirements 6.4.3 and 11.6.1 from SAQ A but added an **eligibility criterion**: the merchant must
confirm that their site is not susceptible to attacks from scripts that could affect the merchant's
e-commerce system(s).

PCI SSC FAQ 1588 (February 2025) explains how to confirm it, for merchants whose page embeds the provider's
payment form (it doesn't apply to a plain redirect to the provider's own payment page):

1. apply protective techniques, such as those in requirements 6.4.3 and 11.6.1, on the page; or
2. get confirmation from the PCI DSS compliant payment provider that its embedded solution, implemented as
   instructed, includes techniques that protect the payment page from script attacks.

A strict, enforced checkout CSP with violation reporting is a practical way to apply option 1, so it helps a
merchant meet the criterion and stay within the reduced scope of SAQ A, where they are otherwise eligible.

### Important

- CSP Manager is a tool, not a compliance certificate. Which questionnaire applies depends on your whole
  payment setup: confirm it with your acquiring bank or a Qualified Security Assessor (QSA), and check what
  your payment provider says about its integration. For example, PayPal's documentation describes some card
  field integrations under SAQ A-EP.
- **Report-only mode doesn't block anything.** It is for testing; the checkout is only protected once you
  switch to enforce mode, after the reports show nothing legitimate is being blocked.
- The SAQ A criterion refers to the merchant's *site*, not just the payment page, and some assessors read it
  that way. The *Default* profile allows inline scripts out of the box; tighten it as your theme allows.
- PCI DSS has many other requirements (secure passwords, patching, TLS and so on). A CSP covers only the
  browser side of the checkout.

## Requirements

- Vvveb 1.0.9.0 or later.
- Front end: tested with the Landing theme (Oct 2026).
- For an enforced checkout policy with PayPal: the PayPal Smart Buttons plugin 0.5 (see "Current scope").

## Installation

1. Download `csp-manager.zip` from the [latest release](../../releases/latest).
2. In the Vvveb admin go to **Plugins › Add new**, upload the zip and activate **CSP Manager**.
3. Open its **Settings**. Leave **Mode** on *Report-only*, browse the site and complete test orders, and
   check the reports (PHP error log, or the notification email).
4. When the checkout shows no unexpected reports, switch **Mode** to *Enforce*.

Manual install: copy the files to `plugins/csp-manager/`, then activate the plugin. The folder must be named
`csp-manager` (the plugin's slug).

## Version history

- **0.5** (2026-11-23): current release, tested with Vvveb 1.0.9.0 and the Oct 2026 Landing theme. Earlier
  versions were development releases.


## Sources

- [PCI SSC: Important updates announced for merchants validating to SAQ A (January 2025)](https://blog.pcisecuritystandards.org/important-updates-announced-for-merchants-validating-to-self-assessment-questionnaire-a)
- [PCI SSC: FAQ clarifies new SAQ A eligibility criteria for e-commerce merchants (FAQ 1588, February 2025)](https://blog.pcisecuritystandards.org/faq-clarifies-new-saq-a-eligibility-criteria-for-e-commerce-merchants)
- [PCI SSC FAQ 1588](https://www.pcisecuritystandards.org/faqs/1588/)
- [PCI SSC FAQ 1438: How is the payment page determined for SAQ A merchants using iframe?](https://www.pcisecuritystandards.org/faqs/1438/)
- [PCI SSC: Request for comments on PCI DSS v4.0.1 (2026)](https://blog.pcisecuritystandards.org/request-for-comments-pci-data-security-standard-pci-dss-v4.0.1)

## Licence

Copyright (C) 2026 Ezyweb.uk. Released under the GNU Affero General Public License v3 or later
(see LICENSE). Built for Vvveb CMS by Ziadin Givan, which is also AGPLv3. The settings page uses the admin page
layout of Vvveb's bundled payment plugin, Copyright (C) 2022 Ziadin Givan.
