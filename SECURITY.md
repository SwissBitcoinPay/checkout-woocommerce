# Security

This repository is the WooCommerce plugin with which a merchant's shop takes Bitcoin payments for
a sattle.me merchant. A flaw here could mark an order paid that is not, show an invoice that pays
someone else, or leak the integration key: please report it **privately**, never in a public
issue, pull request or discussion.

## How to report

- On GitHub: **Security** tab of this repository → **Report a vulnerability** (private
  vulnerability reporting).
- Or by email: security@sattle.me, subject starting with `[security] checkout-woocommerce`.

Please include what you found, the file or the page concerned, the versions of the plugin, of
WordPress, of WooCommerce and of PHP, and, when you can, the steps that show it. Do not test
against real merchants' funds or keys.

## What is in scope

- This plugin: anything that completes an order the SDK did not report paid, completes one order
  with another order's payment, makes a second payable invoice for an order, shows a payment page
  or its status to someone without the order's key, leaks the integration key, or lets a customer
  make the server do more than the checks of their own payment.
- A flaw of the SDK goes to [checkout-php](https://github.com/sattleme/checkout-php/security); a
  rule of the protocol itself to
  [checkout-spec](https://github.com/sattleme/checkout-spec/security); a flaw of WooCommerce or
  WordPress to their own security programs.

We will acknowledge your report, keep you informed, and credit you when the fix is published,
unless you prefer otherwise.
