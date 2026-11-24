# Changelog

Versions of sattle.me for WooCommerce ([semantic versioning](https://semver.org)).

## 1.0.0 - 2026-11-24

First version, on the sattle.me checkout SDK for PHP 1.0.0 (`sattleme/checkout`, embedded and
prefixed; it follows checkout-spec 1.0.0), for WordPress 7.0 and later, WooCommerce 11.1 and
later, PHP 8.2 to 8.5.

- The payment method **sattle.me (Bitcoin)**, in the Checkout block and the classic checkout,
  compatible with WooCommerce's order tables (HPOS): its integration key, checked when saved and
  never shown again, kept apart from its other settings; the time to pay; the title and the
  description at checkout; the minimum and maximum totals. Offered for the currencies the SDK
  converts and the totals it takes as a price.
- An invoice per attempt to pay an order, for a random reference stored before the SDK is asked;
  the order locked in the database while it is made, so that two requests at once get one
  invoice; an invoice the SDK hands out again shown once found payable, and only at the order's
  price. One payable invoice per order: no other method, no other invoice, until a check after
  its end found it ended unpaid.
- The payment page, WooCommerce's order-pay page in the shop's theme: the QR code (made on the
  server), the wallet's button, the invoice and the address to copy, the amount, the time left,
  the rest of an on-chain underpayment, the confirmations; updated on its own, usable without
  JavaScript, never cached nor indexed, authorized by the order's key.
- Payments checked for the payment page and in the background with Action Scheduler (a chain of
  checks per invoice, its waits counted from the start of each action, with a check just before
  and just after the end of its grace period, and a sweep that restarts a lost chain); the order
  completed through WooCommerce on a `paid` status only, once, the reference as its transaction
  id; on hold when paid after the order was cancelled, or at a price the order no longer has;
  failed when the invoice expired unpaid; on hold, for the merchant to settle, when it ended
  underpaid. A completion that a failure stopped half-way is finished by a later check.
- The order's notes say what came, how and against what the invoice asked, each written once.
- Invoices that end before WooCommerce frees the order's stock or cancels it, its stock held
  again when short; an order kept from being cancelled, by WooCommerce and by the customer, while
  its invoice may be paid or its payment confirmed, and once bitcoin came; the customer's "Pay"
  button only while the invoice can be paid.
- The order's box in the admin: each attempt, its amount and exchange rate, address,
  transactions, proofs of payment with the payment hashes they prove, checks and errors in plain
  words.
- WordPress's personal data exporter and eraser, and WooCommerce's anonymization of orders, for
  the deposit addresses and the transactions; a privacy policy text.
- Translations in French, German (formal and informal, of Germany and of Switzerland) and
  Italian.
