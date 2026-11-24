=== sattle.me for WooCommerce ===
Contributors: sattleme
Tags: bitcoin, lightning, payments, payment gateway, woocommerce
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/license/mit

Bitcoin payments, in Lightning or on-chain, paid straight to your sattle.me wallet.

== Description ==

Your customers pay their orders in bitcoin, in Lightning or on-chain, and the bitcoin goes
straight to your [sattle.me](https://sattle.me) wallet. No sattle.me server holds your funds:
the plugin makes each invoice on your shop's server, with the sattle.me checkout SDK, and the
integration key it holds can make invoices that pay you, never spend your bitcoin.

* **At checkout**, classic or the Checkout block, the customer chooses Bitcoin.
* **On the payment page**, in your shop's theme and the customer's language: one QR code, a
  button that opens their wallet, the Lightning invoice and the address to copy, the amount in
  sats and in your currency, the time left to pay. It follows the payment on its own, and works
  without JavaScript too.
* **Once paid** (a Lightning payment at once, an on-chain one at 3 confirmations, about 30
  minutes), the order's payment is complete, as with any payment method: processing, emails,
  stock.
* An invoice never outlives the order's stock hold, and an order whose invoice may be paid, or
  whose payment is being confirmed or came, is never cancelled, by WooCommerce nor by the
  customer.
* The order's screen shows each invoice: its reference, amount and exchange rate, address,
  transactions, proof of payment and last check.

Translated into French, German (informal and formal, Germany and Switzerland) and Italian.
Compatible with WooCommerce's order tables (HPOS) and with its Cart and Checkout blocks.

There is no test mode: payments are real bitcoin payments, to your wallet.

== Installation ==

1. In the sattle.me app, create an integration for your shop and copy its secret key
   (`sattleme_ck_…`).
2. In WordPress, **Plugins → Add Plugin**, search for **sattle.me**, then **Install Now**
   and **Activate**. WooCommerce 11.1 or later must be active.
3. In **WooCommerce → Settings → Payments**, open **sattle.me (Bitcoin)**, enable it, enter the
   key and save: the key is checked with sattle.me, then never shown again.
4. Optionally: the time to pay (1 to 60 minutes, 15 by default), the title and description at
   checkout, the minimum and maximum order totals.

The server needs PHP 8.2 or later (64-bit) with the bcmath, iconv, json and openssl extensions,
OpenSSL with the secp256k1 curve and the ChaCha20 cipher, and outbound HTTPS and WebSocket
connections (see External services). The plugin tells you in the admin when something is
missing.

Payments are checked in the background by WooCommerce's Action Scheduler: keep WP-Cron running,
or run Action Scheduler's queue from the server's cron
(`wp action-scheduler run`).

== Frequently Asked Questions ==

= Which currencies? =

Those the sattle.me SDK converts (about 150 ISO 4217 currencies, each with its own decimals): the
price is converted at checkout at the rate of the first rate source that answers. A shop in
another currency does not offer Bitcoin, and its admin says so.

= What if a customer pays less than the amount? =

On-chain, the payment page asks for the rest, with its own invoice, until the invoice ends. If
the rest never comes, the order is put on hold and its notes say what reached your wallet:
settle it with the customer. As for any order on hold, WooCommerce takes its products from the
stock and sends the customer its "on hold" email, to which the plugin adds what came and that you
will contact them; the customer's pages of the order say the same. A payment short by no more
than your tolerance, or one you accept as it is in the sattle.me app, completes the order, its
notes saying what came.

= How do I refund? =

By hand: a Lightning payment can only be refunded to an invoice of the customer's, an on-chain
one to an address of theirs. Send the refund from your wallet, then record it in WooCommerce as a
manual refund.

= Can the customer pay with another method? =

Not until the order's invoice ended unpaid, as a check made after its end finds it (a Lightning
invoice cannot be withdrawn, and a payment of its last seconds is found by that check): then the
order can be paid again, with any method.

= What if an order is cancelled before its payment arrives? =

The customer cannot cancel it while its invoice may be paid; you can. A Lightning invoice
already in the customer's wallet can still be paid. The bitcoin then reaches your wallet, but the
order is never completed: it goes on hold, with a note giving the sats received. Reinstate it,
or refund the customer from your wallet and cancel it again. Meanwhile the customer reads that
the payment was received and that you will contact them.

= How does it differ from other Bitcoin payment plugins? =

No payment processor holds your funds: the bitcoin goes straight to your sattle.me wallet, and
you run no payment server of your own. The plugin makes each invoice on your shop's server, with
the sattle.me checkout SDK, and checks its payment itself: with your Lightning address's
provider, and with a block explorer for an on-chain payment (mempool.space, or your own Bitcoin
node when your sattle.me app names one).

== External services ==

To make and check each invoice, the shop's server (never the customer's browser) connects to the
services below, and to no other. Each of them sees the shop's server's IP address and what it is
asked about, never the customer's name, email or IP address.

* **sattle.me** (https://sattle.me), the merchant's wallet, through **the merchant's Nostr
  relays**, those chosen in the sattle.me app: each invoice, encrypted for the merchant, with its
  price and a note (the shop's name and the order's number), when the customer pays; the
  merchant's integration, read when its key is saved.
* **The merchant's Lightning address** (on breez.tips for a sattle.me account): asked for each
  invoice and its payment, with its amount and note.
* **Rate sources**, one at a time until one answers, asked the price of bitcoin in the shop's
  currency for each invoice:
  * CoinGecko (https://www.coingecko.com): API terms https://www.coingecko.com/en/api_terms,
    privacy policy https://www.coingecko.com/en/privacy.
  * mempool.space (https://mempool.space): terms https://mempool.space/terms-of-service,
    privacy policy https://mempool.space/privacy-policy.
  * Coinbase (https://www.coinbase.com): terms https://www.coinbase.com/legal/user_agreement,
    privacy policy https://www.coinbase.com/legal/privacy.
  * Kraken (https://www.kraken.com): terms https://www.kraken.com/legal, privacy notice
    https://www.kraken.com/legal/privacy.
  * OpenNode (https://opennode.com): terms https://opennode.com/terms-and-conditions/, privacy
    policy https://opennode.com/privacy-policy/.
* **Block explorers**, for an invoice payable on-chain, told its deposit address and its
  transactions while its checks run:
  * mempool.space (https://mempool.space): terms and privacy policy as above.
  * The merchant's own Bitcoin node, when the merchant's sattle.me app names one: read instead of
    mempool.space, which is then read only when the node does not answer, unless the merchant
    chose no fallback. It is the merchant's server, under the merchant's own terms.
  * BlockCypher (https://www.blockcypher.com), told one transaction, only for a payment no check
    saw in time: terms https://www.blockcypher.com/terms-of-service.html, privacy policy
    https://www.blockcypher.com/privacy-policy.html.

== Privacy ==

For each Bitcoin payment the plugin stores, in its own table, the invoice as the SDK made it (its
reference, amounts, exchange rate, Lightning invoice and, for an on-chain payment, a deposit
address of the merchant's wallet) and what its checks found (the transactions, the proof of
payment). WordPress's personal data exporter exports them with the customer's orders; its eraser,
and WooCommerce's removal of an order's personal data, remove the address and the transactions
once the payment is final; an order deleted for good takes its payments with it. Settings →
Privacy suggests a text for your privacy policy.

== Source code ==

The plugin's source, with its tests and the tools that build its ZIP:
https://github.com/sattleme/checkout-woocommerce. Its scripts and styles are plain files, without
a build step; its translations' .mo and .l10n.php files are compiled from the .po files beside
them.

The `vendor-prefixed/` folder holds its dependencies, at the versions the plugin's
`composer.json` and `composer.lock` pin: the sattle.me checkout SDK for PHP
(https://github.com/sattleme/checkout-php), and BaconQrCode (https://github.com/Bacon/BaconQrCode)
with DASPRiD/Enum (https://github.com/DASPRiD/Enum) for the QR codes. `tools/build.sh` installs
them with Composer from the lockfile, without the development tools, then Strauss 0.31.1
(https://github.com/BrianHenryIE/strauss) prefixes their namespaces, so that another plugin's
copy of them never clashes with these: their code is otherwise as published.

== Changelog ==

= 1.0.0 =
* First version: Bitcoin payments, in Lightning or on-chain, with sattle.me, on the sattle.me
  checkout SDK for PHP 1.0.0.
