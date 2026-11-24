# sattle.me for WooCommerce

A payment plugin for [WooCommerce](https://woocommerce.com): customers pay their orders in
bitcoin, in Lightning or on-chain, and the bitcoin goes straight to the merchant's
[sattle.me](https://sattle.me) wallet. It is built on the
[sattle.me checkout SDK for PHP](https://github.com/sattleme/checkout-php) (`sattleme/checkout`),
embedded in the plugin, which makes and checks the invoices: no sattle.me server, no API key of a
third party, and the integration key can make invoices that pay the merchant, never spend the
funds.

- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Payments and orders](#payments-and-orders)
- [Background checks](#background-checks)
- [Security](#security)
- [Privacy](#privacy)
- [Known limits](#known-limits)
- [Troubleshooting](#troubleshooting)
- [Development](#development)
- [Releases](#releases)
- [License](#license)

## How it works

1. At checkout (the Checkout block or the classic checkout), the customer chooses **Bitcoin** (or
   the name the merchant gives the method). As the order is placed, the plugin asks the SDK for
   its invoice: a Lightning invoice, a deposit address of the merchant's wallet, or both,
   depending on the amount (see [the SDK](https://github.com/sattleme/checkout-php#on-chain-payments)).
2. The customer lands on the order's payment page, WooCommerce's own "order-pay" page, in the
   shop's theme and the customer's language: one QR code of the invoice (BIP-21 with
   `lightning=`, or `lightning:`), a button that opens their wallet with it, the Lightning invoice
   and the address to copy, the amount (in bitcoin, in sats and in the price the invoice was made
   for), and the time left to pay. An on-chain payment short of the amount shows the rest to
   pay; one being confirmed says so.
3. The page follows the payment on its own. Once the SDK reports it **paid**, the plugin completes
   the order's payment through WooCommerce (`$order->payment_complete()`): the order is
   processing, emails are sent and stock is reduced as with any payment method. An order
   cancelled or changed meanwhile is never completed: it is put on hold for the merchant (see
   [Payments and orders](#payments-and-orders)).

The QR code is an inline SVG made on the server. The page's small script, a file of the plugin,
only counts down, copies, and asks the server for news of the payment; without JavaScript, the
page offers a button to check the payment.

## Requirements

- WordPress **7.0** or later and WooCommerce **11.1** or later: the current WordPress release and
  the one before it (WooCommerce 11.3 requires WordPress 7.0), and WooCommerce's three latest
  feature releases. The tests run against WordPress 7.0.9 and 7.1.5 and WooCommerce 11.1.2 and
  11.3.0 (the oldest and the latest supported), orders in WooCommerce's order tables (HPOS) and
  as posts.
- PHP **8.2** to **8.5**, **64-bit**, with the extensions `bcmath`, `hash`, `iconv`, `json`,
  `openssl` (with the secp256k1 curve and the ChaCha20 cipher) and `pcre`, as the SDK and the QR
  codes need them. The plugin checks them and says in the admin what is missing.
- A MySQL or MariaDB database, as WordPress needs: the order's lock is the database's named lock
  (`GET_LOCK`). The tests run on MariaDB 11.4.
- WooCommerce's Action Scheduler running (WP-Cron, or the server's cron): see
  [Background checks](#background-checks).
- A merchant account of sattle.me, and an **integration** created in its app.
- **Outbound connections** from the shop's server (never from the customer's browser), direct:
  the SDK does not use a proxy.
  - The merchant's **Nostr relays**, over WebSocket (TLS): those the integration key names, or
    the merchant's app.
  - The merchant's **Lightning address** (breez.tips for a sattle.me account) and the URLs its
    provider gives (its callback, its verify URLs), over HTTPS.
  - The **rate sources**, one after the other until one answers: CoinGecko, mempool.space,
    Coinbase, Kraken, OpenNode.
  - For an invoice with a deposit address, the explorer: **mempool.space**, or the merchant's own
    **Bitcoin node** when the merchant's app names one (over HTTPS, or HTTP to a node of a local
    network), mempool.space then its fallback unless the merchant chose none; and
    **api.blockcypher.com** for a transaction no check saw in time.

  Every request, and the handshake of each WebSocket, says `User-Agent: sattleme-Checkout/1`.

## Installation

From WordPress.org's plugin directory, where the plugin is distributed: in WordPress,
**Plugins → Add Plugin**, search for **sattle.me**, **Install Now**, then **Activate**. Or with
WP-CLI:

```shell
wp plugin install sattleme-for-woocommerce --activate
```

WordPress then offers each new version on its Updates screen, as for any plugin of the directory.
The plugin's ZIP (`sattleme-for-woocommerce-<version>.zip`, the one the directory serves, or the
one the release workflow keeps, see [Releases](#releases)) installs the same way with
**Plugins → Add Plugin → Upload Plugin**, or `wp plugin install <the ZIP> --activate`. The ZIP
holds everything the plugin needs, the SDK and the QR code library included (prefixed, so that
another plugin's copy of them never clashes with these).

Uninstalling the plugin (deleting it on the Plugins screen) removes its settings, its key and its
scheduled checks. Its table of Bitcoin payments (`{prefix}sattleme_invoices`, the merchant's
records) stays, as WooCommerce keeps its orders, unless `WC_REMOVE_ALL_DATA` is true in
`wp-config.php`, WooCommerce's own switch for removing all its data.

## Configuration

1. In the sattle.me app, create an integration for your shop and copy its secret key
   (`sattleme_ck_…`).
2. In WordPress, **WooCommerce → Settings → Payments**, open **sattle.me (Bitcoin)**, enable it
   and enter the key. It is checked when you save it: the SDK refuses what is not an integration
   key, then reads the integration on the merchant's relays, so that the key of an integration
   removed in the app, or of one the relays do not know, is refused. Once saved, it is never shown
   again; the settings show the business it connects to, the amounts its Lightning address takes
   and the number of its deposit addresses.
3. **Time to pay**: how long the customer has to pay an invoice, 1 to 60 minutes (15 by default),
   but never past the order's deadline (see [Payments and orders](#payments-and-orders)): with
   WooCommerce's "Hold stock" (**WooCommerce → Settings → Products → Inventory**) shorter than the
   time to pay and 2 minutes 20 seconds, invoices are shorter.
4. Optionally, the **title** and the **description** the customer reads at checkout (empty: "Bitcoin"
   and a description, in the customer's language; `wpml-config.xml` names them to WPML as texts
   to translate), and the **minimum** and **maximum** order totals the method is offered for.

Dates and times (the payment page's "until", the order's notes and box, the settings) are shown
in the site's time zone and formats, set in **Settings → General** (WordPress's `wp_date()`).

Change the key only while no Bitcoin payment is pending: payments are checked with the key set at
the time of the check, and an invoice made under another integration is not that one's.

The method is offered only when the store's currency is one the SDK converts (155 ISO 4217
currencies, each with its decimals: the SDK's table), for a total it takes as a price (no more
decimals than the currency has, and at most 2^53 − 1 of its minor units: 90,071,992,547,409.91
CHF), within the merchant's minimum and maximum totals. The admin says when the currency is not
one of them.

**There is no test mode.** sattle.me has no test network: payments are real bitcoin payments, to
the merchant's wallet.

## Payments and orders

| What the SDK reports                       | What the plugin does                                                                                     |
| ------------------------------------------ | -------------------------------------------------------------------------------------------------------- |
| An invoice was made                        | The order is **pending**, a note says so (with how it can be paid: in Lightning, on-chain, or both); the customer goes to the payment page. |
| An invoice made without a deposit address  | Below 1,000 sats, or when no address of the integration could be leased (none free in its lot, the explorer, i.e. the merchant's Bitcoin node or mempool.space, not answering then, or the merchant's relays confirming none of the leases tried, 3 at most: the SDK's log says why), the invoice is payable in Lightning only, as the SDK makes it (SPEC.md §31). The payment page then shows the Lightning invoice alone, and says nothing of an on-chain payment. |
| `open`                                     | Waits. The order can be paid with no other method until the invoice ended unpaid, and cannot be cancelled. |
| No final status yet, the invoice's end past | Still the order's invoice: a payment of its last seconds, or an on-chain one dated in time, is found by the next check. A new attempt to pay checks it first. |
| `open` with `received` (on-chain, short)   | The page shows what came and asks for the rest (the SDK's invoice of it); a note on the order.           |
| `unconfirmed`                              | Waits for 3 confirmations (about 30 minutes), noted once; the order is kept from being cancelled (by WooCommerce, by the customer), its stock held; its "Pay" button gone. |
| `paid` (Lightning, on-chain, or both)      | Completes the order's payment, the invoice's reference as its transaction id: processing, emails, stock. |
| `paid`, with `received` below or above `sats` | Completes it too, and notes what came: less (the merchant's tolerance, or the merchant's acceptance of what came, accepted it), or more (to refund if needed). |
| `paid`, the order changed since            | The order is put **on hold**, with a note giving the sats received and what the order owes now (its total less its refunds): the invoice was paid at a price the order no longer owes. The customer reads that the payment was received and that the shop will contact them. |
| `paid`, the order cancelled since          | The order is put **on hold**, never completed, the invoice's reference as its transaction id, with a note giving the sats received and the invoice's price, which reached the wallet: the merchant reinstates the order, or refunds the customer. WooCommerce takes its products from the stock again and emails the customer (below); the customer reads that the payment was received and that the shop will contact them. |
| `expired`                                  | Final: the invoice is checked no more ([Known limits](#known-limits)). The order **fails**: the customer can pay again, with any method, or cancel it. |
| `expired` with `received` above 0          | The order is put **on hold**, with a note giving what came of what the invoice asked, which reached the wallet, to settle with the customer by hand: it takes no other payment, nor another invoice, and neither WooCommerce nor the customer cancels it. As for any order put on hold, WooCommerce takes its products from the stock (a pending order only held them) and emails the customer its "on hold" email, which says the order waits for its payment to be confirmed: the plugin adds what came of what was asked, and that the shop will contact them to settle the difference, as the customer's pages of the order say (below). |

- **Only a `paid` status completes an order**, from the SDK's own check of the invoice the order
  holds, on the server: never because the customer's browser says so. An order is completed
  once, through WooCommerce's `payment_complete()`, whichever check sees the payment first; a
  status is never applied twice, and a paid order is never undone.
- **The order's notes** say what came (never the invoice's amount in its place: a Lightning
  payment's status has no amount received, so its note gives the invoice's) against what the
  invoice asked, and how only when the SDK's status says it: a paid status gives its method (in
  Lightning, on-chain, or both, a Lightning part never called on-chain); a payment being
  confirmed says what came and that its on-chain payment, which it always has, is being
  confirmed (it may still fail); an invoice that ended underpaid says what came and where it
  went, not how. A payment accepted below its amount is noted
  "by your tolerance or by your acceptance of what came": the status does not say which.
  Each is written once, before the order's status changes: a check that a failure stopped
  half-way (a plugin, or an email failing as WooCommerce completes the order, in a background
  check too) is done again by a later check, which finishes it without a second note, and finds
  the order completed by its transaction id, never paid twice.
- **One attempt, one reference.** Each attempt to pay an order gets a random reference
  (`wc-<order id>-<32 hex digits>`: 128 bits of the CSPRNG, made on the server), stored before
  the SDK is asked for its invoice, and never used for another attempt: no deleted test order,
  restored backup or copy of the shop can share it. An attempt that failed after the SDK may have
  made its invoice (the network, the time) asks again with the same reference: the SDK then hands
  out that invoice, shown only once a check found it payable, or reports it paid, and the order is
  completed.
- **The order locked.** The invoice is made with the order locked in the database (the same lock
  whether WooCommerce stores orders in its tables or as posts): two requests at once (a double
  click, two PHP workers) get one invoice.
- **One payable invoice per order.** A Lightning invoice cannot be revoked, and a payment of its
  last seconds, or an on-chain one the SDK dates in time, is known only from a check made after
  its end: until the invoice ended unpaid (its final status `expired`, nothing received), the
  order can be paid with no other method, nor get another Bitcoin invoice (the order-pay page says
  why), so that it is never paid twice. An attempt to pay an order whose invoice's end passed
  checks that invoice first. Once it ended unpaid, the order fails and can be paid again, with any
  method; one that ended underpaid is on hold, the merchant's to settle by hand.
- **No cancellation once bitcoin may come, or came.** While an order's invoice may be paid or
  its payment confirmed (its final status unknown, whatever the time), and once something came
  (paid, an underpayment that ended with something received, an invoice given up on after 90
  days), neither WooCommerce's cancellation of unpaid orders ("Hold stock") nor the customer
  cancels the order: the "Cancel" button of their account is not shown, and WooCommerce's
  handler of its link refuses it ("Your order can no longer be cancelled"). Once the invoice
  ended unpaid, the customer may cancel the order as WooCommerce allows. The merchant may always
  cancel it, from the order's screen.
- **"Pay" buttons.** WooCommerce's "Pay" button of the customer's account leads to the payment
  page only while the order's invoice can be paid (the rest of an underpayment included): never
  once a payment is being confirmed or came, nor after the invoice's time is over. The payment
  page offers to pay again only once the invoice ended unpaid; an order whose invoice ended
  underpaid is on hold, which WooCommerce offers no payment of.
- **Deadlines.** An invoice never outlives the moment WooCommerce would free the order's stock
  ("Hold stock", in WooCommerce's inventory settings) or cancel it unpaid: the invoice, its 120
  seconds of grace and the 20 seconds its making may take end before then. The stock is held
  again first when what is left of the hold is short, as checkout holds it. An invoice whose end
  the merchant's Lightning address set later than asked (its clock) is never shown. While an
  invoice may be paid or confirmed, or once something came, WooCommerce's cancellation of unpaid
  orders spares its order; while an on-chain payment is being confirmed, its stock is held again.
- **The order's price.** An invoice is made for what the order owes (its total less what was
  refunded of it), and pays the order at that price. One the SDK hands out again for an earlier
  price is never shown. When the invoice is found paid, its price is compared with what the order
  owes then: when the merchant changed the order meanwhile (its items, a coupon, a refund), the
  order is put on hold instead of completed, with a note giving the sats received and what the
  order owes now.
- **An order cancelled, then paid.** A Lightning invoice cannot be revoked: one already in the
  customer's wallet can be paid after its order was cancelled by the merchant (the customer
  cannot cancel it meanwhile). The bitcoin then reached the merchant's wallet, but the order is
  never completed: it goes from cancelled to **on hold**, the invoice's reference as its
  transaction id, with a note giving the sats received and the invoice's price; a later check of
  the invoice changes nothing. Taking the order out of "cancelled", WooCommerce, as for any
  order put on hold: takes its products from the stock again (`wc_maybe_reduce_stock_levels`,
  even below zero when they were sold since); sends the customer its "on hold" email and the shop
  its "new order" email when it never sent it; and records no date of payment (it dates one only
  as the order becomes processing or completed). The merchant then reinstates the order (sets it
  to processing: WooCommerce sends the customer its "processing" email) or refunds the customer
  from the wallet and cancels it again (WooCommerce puts its products back in stock).
- **The customer's pages of an order on hold** for its payment (cancelled or changed before the
  invoice was found paid): the payment page goes to WooCommerce's "order received" page, which,
  like the order's page in the customer's account, says that the Bitcoin payment was received and
  that the shop will contact them, never that the order is paid (its status endpoint answers
  `received`, not `paid`). An order on hold because its invoice ended underpaid goes there too,
  and those pages say what came of what was asked ("we received 20,000 of the 49,000 sats asked")
  and that the shop will contact them to settle the difference, as the payment page does.
  WooCommerce's "on hold" email to the customer, whose own words say that the order waits for its
  payment to be confirmed, says the same after them (the plugin adds it where payment methods add
  their instructions, `woocommerce_email_before_order_table`; WooCommerce's templates stay as they
  are).
- **Underpayments.** When less than the amount comes on-chain in time, the page shows the rest,
  as an invoice the SDK asks for. The merchant may accept an on-chain payment a little short of
  the amount (its tolerance, set in the sattle.me app): the SDK then reports it `paid`, and the
  order's note says what came. So it does when the merchant accepts, in the sattle.me app, what an
  underpaid order received (the merchant's acceptance): being confirmed, then `paid`, the order
  completed, never on hold for the difference alone. When the rest never comes, the invoice ends
  underpaid: the order is on hold, what came in the merchant's wallet, for the merchant to settle
  with the customer. Putting it on hold, WooCommerce takes its products from the stock
  (`wc_maybe_reduce_stock_levels`; an order cancelled later gets them back) and sends the customer
  its "on hold" email, with the plugin's words of what came; a failed order, which an invoice that
  ended with nothing received gives, takes no stock and sends the customer no email.
- **Refunds** are never automatic: a Lightning payment can only be refunded to an invoice of the
  customer's, an on-chain one to an address of theirs, which WooCommerce never asks. Refund the
  customer from the wallet, then record the refund in WooCommerce as a manual refund.
- **The merchant's view.** The order's screen has a box with each attempt to pay it with bitcoin:
  its reference, amount and exchange rate, the methods it took, its payment hash, its address
  (linked to mempool.space), the transactions (linked to mempool.space; for an underpayment whose
  status lists none, its address's page), each proof of payment (preimage) with the payment hash
  it proves (its SHA-256), its last check (or that its first check failed), its next one, and its
  error in plain words; an invoice that ended with less than its amount says "ended underpaid".
  The order's notes log the invoices made, the payments being confirmed, the underpayments and
  their outcome.

## Background checks

The SDK has no webhooks: payments are checked by the plugin, on the shop's server.

- While the customer's payment page is open, it asks the server every few seconds (every 3
  seconds while the invoice can be paid, every 30 seconds while a payment is being confirmed),
  and the server checks the invoice at most every 6 seconds.
- In the background, with WooCommerce's **Action Scheduler**, each invoice by a chain of actions
  of its own: every minute while it can be paid, once 10 seconds before the end of its grace
  period (an on-chain payment a check lists by then came in time) and once 10 seconds after (its
  final status), then every five minutes until its final status is known (an on-chain payment
  being confirmed). After failed checks, once the invoice has ended: one minute, then twice as
  long each time, up to every 30 minutes. Each action checks one invoice, within 25 seconds. The
  waits count from the start of the action, less 5 seconds: Action Scheduler runs the actions due
  when its queue runner starts, once a minute, so a check of every minute is made at each of its
  runs, not at every other one.
- Every five minutes, a sweep restarts the chain of an invoice whose next check is 10 minutes
  late (a lost action), up to 100 invoices per run.

Action Scheduler runs from WP-Cron, which WordPress triggers as visitors come: on a shop with few
visitors, or with `DISABLE_WP_CRON`, run it from the server's cron, every minute:

```shell
* * * * * cd /path/to/wordpress && wp action-scheduler run --quiet
```

An invoice whose final status is still unknown 90 days after it was made (an on-chain payment made
in time, still unconfirmed on a congested network) is no longer checked; its order gets a note,
and its payment stays pending: check it in the sattle.me app and settle the order by hand.

## Security

- **The integration key** is stored in WordPress's options, in the database, in an option of its
  own (`sattleme_for_woocommerce_integration_key`), never loaded on every request (not
  autoloaded), never in the payment method's settings (which WooCommerce's REST API returns to
  administrators). The option holds an array, which WordPress's "All options" page
  (`wp-admin/options.php`) shows as "SERIALIZED DATA" rather than writing it into the page.
  WordPress offers no encryption of its options: the key is stored as it is, as
  the keys of WooCommerce's own payment gateways; protect the database and its backups. The key's
  field is write-only: once saved, the key never goes back to the browser (the field stays empty,
  and a key refused is not sent back either). It never goes in a log, an exception, an order's
  note or a page; the SDK never puts it, or a key derived from it, in a message or a dump.
- **One key, one shop.** Create an integration for your WooCommerce shop and use its key nowhere
  else (another shop, a staging copy). If it leaks, remove the integration in the sattle.me app
  and create a new one: the key can make invoices that pay you, never spend your funds.
- **The payment page and its status** are authorized by the order's key (the secret of
  WooCommerce's own order links, compared in constant time), never by the order's number alone;
  another payment method's order is not the plugin's. They use no cookie, so no request can be
  forged on a customer's behalf, and change nothing a request chooses: a check finds what the SDK
  finds, at most every 6 seconds per invoice. The page is `noindex`, never cached
  (`Cache-Control: no-store`), and loads nothing from another host: its script and its style are
  files of the plugin, the QR code an inline SVG. Every value it shows is escaped, the QR code's
  SVG too (`wp_kses()`, with the elements and attributes it draws with alone). Nothing the
  browser sends decides an amount, a currency, a reference or a status.
- **Admin**: the settings are WooCommerce's (`manage_woocommerce`), saved with its nonce; the
  plugin's notices show only to the shop's managers.
- **Outbound requests.** The SDK connects to hosts the merchant chooses (its relays, its Lightning
  address, its Bitcoin node). It refuses IP addresses and `localhost`, follows no redirect and
  checks TLS, but for the merchant's Bitcoin node, which may also be an IPv4 address or a host of a
  local network asked over HTTP (an Umbrel at home, for a shop on the same network). A name may
  still lead to an address of your own network: if your shop's server sits in a network with
  services of its own, restrict what it can reach to public addresses (a firewall rule); a node
  the server cannot reach then falls back to mempool.space, unless the merchant chose no fallback.
- **The testing constant.** `SATTLEME_FOR_WOOCOMMERCE_TESTING` lets the plugin's tests replace
  the SDK with a fake: the plugin never defines it, and the admin warns on every page when it is
  defined. Never define it on a shop.
- **Dependencies**: the SDK and the QR code library (BaconQrCode, with its one dependency), each
  pinned to an exact version in `composer.json` and locked in `composer.lock`, embedded and
  prefixed in the ZIP, which holds both manifests too. Nothing is loaded from a CDN.
- Report a vulnerability privately: see [SECURITY.md](SECURITY.md).

## Privacy

- **What the plugin stores**, in its table `{prefix}sattleme_invoices`, one row per attempt to pay
  an order: the invoice as the SDK made it (its reference, its amount in sats and its price in the
  store's currency, the exchange rate, the Lightning invoice and, from 1,000 sats when one could
  be leased, a deposit address of the merchant's wallet) and what its checks found (its state,
  what came, the confirmations, the transactions, the proofs of payment), with their dates. No
  name, no email address, no IP address: the customer's are WooCommerce's.
- **What the shop's server sends**, and to whom:
  - the merchant's **Nostr relays**, encrypted for the merchant: each invoice, with its price and
    its note (the shop's name and the order's number), which the merchant's app shows;
  - the merchant's **Lightning address** (breez.tips for a sattle.me account): asked for each
    invoice, its amount and, when the address accepts comments, its note, then for its payment;
  - the **rate sources**, one at a time until one answers: asked for the price of bitcoin;
  - the explorer, for an invoice with an address: **mempool.space**, or the merchant's own
    **Bitcoin node** when the merchant's app names one (mempool.space then its fallback, unless
    the merchant chose none): the address and its transactions; **api.blockcypher.com**: a
    transaction, only for one no check saw in time.

  Each of them sees the shop's server's IP address, never the customer's: the customer's browser
  talks to the shop only.
- **Personal data tools.** WordPress's exporter (**Tools → Export Personal Data**) exports a
  customer's Bitcoin payments with their orders. Its eraser (**Tools → Erase Personal Data**, when
  WooCommerce's "Remove personal data from orders on request" is on) and WooCommerce's own
  removal of an order's personal data (its anonymization of old orders) remove what leads to the
  customer's coins on the blockchain: the deposit address and the transactions. The references,
  amounts, exchange rates, dates and states stay, for the merchant's accounting; so do the
  Lightning invoices and their proofs, which name the merchant's node, never the customer's. An
  invoice still being checked (a payment being confirmed) loses them once its status is final.
  What the merchant's relays received stays theirs: the merchant's app keeps its sales.
- **A deleted order** (deleted for good, not trashed) takes its rows of the plugin's table with
  it, and their checks stop: no tool would find them once the order is gone.
- **Settings → Privacy** suggests a text for the shop's privacy policy.

## Known limits

- **No proxy.** The SDK's connections do not use a proxy: the shop's server needs direct
  outbound connections.
- **Currencies.** The store's currency must be one the SDK converts and its rate sources price;
  an order whose total has more decimals than its currency has is not offered Bitcoin.
- **Confirmations.** An on-chain payment is paid at 3 confirmations, about 30 minutes: the order
  stays pending until then.
- **Time.** An invoice is made in the request that places the order, within 20 seconds; a check a
  customer without JavaScript asks for, within 15 seconds. With WooCommerce's "Hold stock" set to
  fewer than 4 minutes, no invoice fits before the order's deadline (the shortest invoice, a
  minute, its 2 minutes of grace and the time to make it): Bitcoin cannot pay the order.
- **Deposit addresses.** Each invoice of 1,000 sats or more may lease an address of the
  integration's lot for its time: many orders left unpaid at once can empty it, and the next ones
  are payable in Lightning only until the merchant opens the app (see the SDK). So are they while
  the merchant's Bitcoin node, named in its app without a fallback, does not answer.
- **The database's lock** serializes the requests of one database server: a cluster whose nodes
  all take writes (Galera, MySQL Group Replication in multi-primary mode) does not share named
  locks between them. With read replicas (HyperDB, LudicrousDB), the lock and the reads made
  while it is held go to the primary.
- **`expired` is final.** SPEC.md §3 names one exception: the merchant accepted in the sattle.me
  app what an underpaid order received, a relay that holds that acceptance fails at the check
  after the invoice's end, that check reports `expired`, and a later one would report it being
  confirmed, then paid. The plugin checks an expired invoice no more: in that rare case the shop
  shows the order's invoice as ended underpaid (the order on hold), or the order cancelled,
  although the merchant accepted the underpayment in the app; the merchant then settles the order
  by hand, as SPEC.md §3 says.
- **Background checks need Action Scheduler running**; without it, payments are confirmed only
  while the customer's payment page is open.
- **Not tested on WordPress multisite.**

## Troubleshooting

The order's box and its notes say what went wrong, in plain words; WooCommerce's log
(**WooCommerce → Status → Logs**, source `sattleme-for-woocommerce`) says it too, without the key.

- **The key is refused when saving the settings.**
  - "This is not the secret key of a sattle.me integration": copy the whole key from the app,
    `sattleme_ck_` included.
  - "This integration was removed in the sattle.me app": create a new integration, and enter its
    key.
  - "This integration could not be found": the merchant's relays do not hold it (yet): open the
    sattle.me app, check the key, and try again.
  - "This integration needs a newer version of sattle.me for WooCommerce": update the plugin.
  - "sattle.me could not be reached to check this key": the shop's server could not reach the
    merchant's relays or its Lightning address. Check its outbound connections
    ([Requirements](#requirements)): direct, without a proxy.
  - "The server's PHP lacks what sattle.me needs": the message lists what (an extension, the
    secp256k1 curve of OpenSSL).
- **The method is not offered at checkout**: it is disabled, no key is saved, the store's
  currency is not one the SDK converts, the order's total is outside the minimum and maximum
  totals, or the server lacks a requirement (the admin's notices say which).
- **"Bitcoin payments are not available right now"** at checkout: the order's box and the log say
  why: a key no longer valid or removed, the merchant's Lightning address answering wrongly, or
  a clock that is off (the shop's server's: keep it synchronized).
- **"We could not create your Bitcoin invoice right now"**: the relays, the Lightning address or
  the rate sources did not answer in time, or the amount can only be paid on-chain and no deposit
  address of the integration was free (the merchant opens the app to give it more). The customer
  tries again; if it goes on, check the server's outbound connections.
- **"This order cannot be paid with Bitcoin"**: the SDK refused the amount or the currency.
- **"The time to pay this order is almost over"**: too little time is left before WooCommerce
  frees the order's stock or cancels it (its "Hold stock" minutes), or the merchant's Lightning
  address made an invoice that ends later than asked (its clock).
- **A payment stays pending.** The order's box shows its last check and its next one. No check
  at all: Action Scheduler is not running (**Tools → Scheduled Actions**, group
  `sattleme-for-woocommerce`; see [Background checks](#background-checks)). An on-chain payment
  waits for 3 confirmations, about 30 minutes, longer with a low fee. When the merchant's app names
  a Bitcoin node without a fallback, every check of an on-chain invoice fails while the node does
  not answer the shop's server (the order's box says so), and goes on once it
  answers: check that the node is reachable from the shop's server.
- **"ended underpaid"** in the order's notes, the order on hold: less than the amount came, and
  reached the wallet: settle the difference with the customer (complete the order once paid in
  full, or refund what came and cancel it).
- **An order on hold** after a Bitcoin payment: it was paid at a price the order no longer has
  (the note says both): settle the difference with the customer, then update the order; or it
  was paid after the order was cancelled (the note says so): reinstate the order, or refund the
  customer from the wallet and cancel it again.

## Development

The plugin is PHP for WordPress (the WordPress coding standards), its dependencies managed by
Composer 2.11, on PHP 8.2 or later. From a clone:

```shell
composer install             # the lockfile, then the dependencies prefixed (tools/strauss.sh)
php tools/sdk.php check      # the pinned SDK is its commit's, file for file
vendor/bin/phpcs             # WordPress coding standards (WPCS 3.4), PHPCompatibilityWP (PHP 8.2+)
vendor/bin/phpstan analyse   # PHPStan 2.3, level max, strict rules: the plugin, its tests and tools
sh tools/i18n.sh check       # the translations' catalog is the sources'
bash tools/build.sh          # the ZIP: build/sattleme-for-woocommerce-<version>.zip, checked
```

- **The tests** never reach the network: the SDK is faked at the plugin's one seam
  (`Sdk\ClientFactory`, which makes the `Sdk\CheckoutClient` the plugin calls), with the SDK's own
  values and exceptions; one test makes real SDK clients, without the network. The unit tests
  need neither WordPress nor a database: `vendor/bin/phpunit -c phpunit-unit.xml.dist`. The
  integration tests run on WordPress's PHPUnit test library with WooCommerce and a MySQL or
  MariaDB database:

  ```shell
  sh tools/tests/wordpress.sh 7.1.5 11.3.0       # WordPress, its test library, WooCommerce (pinned)
  socket="$(sh tools/tests/mariadb.sh start)"   # MariaDB, without root: its socket
  export WP_TESTS_DB_HOST="localhost:$socket"
  vendor/bin/phpunit -c phpunit-integration.xml.dist                    # orders in HPOS tables
  SATTLEME_HPOS=no vendor/bin/phpunit -c phpunit-integration.xml.dist   # orders as posts
  ```

  `SATTLEME_TEST_ENV` picks another environment of `tools/tests/wordpress.sh` (7.0.9 11.1.2 for
  the oldest supported), `SATTLEME_PLUGIN_DIR` the plugin under test (the ZIP's files, extracted,
  as the CI tests them), `WP_TESTS_DB_*` another database.
- **The end-to-end test** installs the ZIP into a real WordPress and WooCommerce, saves a key in
  the settings, places orders through the Checkout block's API and the classic checkout, opens the
  payment page and sees the orders paid (the SDK replaced by a fake through a test-only must-use
  plugin and the testing constant), then runs WordPress.org's Plugin Check:
  `bash tools/e2e/run.sh 7.1.5 11.3.0` on PHP's built-in web server, without Docker, and
  `bash tools/e2e/docker.sh <WordPress image@digest> 11.3.0` on WordPress's official Docker image.
- **The pinned SDK.** The plugin embeds the SDK at one commit of
  [checkout-php](https://github.com/sattleme/checkout-php): `sdk/` holds the files of its package
  at that commit (its `composer.json`, `LICENSE` and `src/`), unchanged, and `sdk/PIN.json` the
  repository, the commit, the version and each file's SHA-256. With them, two files of its
  repository that its package does not hold: the specification's vectors of prices
  (`spec/vectors/fiat.json`), which the unit tests run through the plugin's check of totals so
  that it takes exactly the prices the SDK takes, and the pin of the specification they come
  from (`spec/PIN.json`). Composer installs it from there (a path repository, at the exact
  version) and Strauss prefixes it (`Sattleme\WooCommerce\Vendor\`) with the QR code library,
  leaving the vectors out: the ZIP never holds them. To take another version or commit, from a
  clone of checkout-php:

  ```shell
  git clone https://github.com/sattleme/checkout-php /path/to/checkout-php
  php tools/sdk.php import /path/to/checkout-php <commit>   # by default, the clone's HEAD
  ```

  It replaces `sdk/` with the files of the SDK's package and the vectors at that commit (as git
  holds them, never the clone's working tree), writes `PIN.json`, sets the version in
  `composer.json`, and has Composer lock it, install it again (a fix at the same version too) and
  prefix it. Then run the checks and the tests above, and commit `sdk/`, `composer.json` and
  `composer.lock`.
- **Strauss 0.31.1** prefixes the dependencies: the version checkout-php's CI prefixes the SDK with
  before running its tests on the prefixed copy.
- **The translations** (French, German formal and informal, of Germany and of Switzerland,
  Italian): `sh tools/i18n.sh` updates `languages/` from the sources (WP-CLI 2.12's phar, checked
  against its SHA-512): the POT, each catalog, and their compiled `.mo` and `.l10n.php` files.
- **Dependencies** are pinned to exact versions in `composer.json`, the versions `composer.lock`
  holds (a unit test checks it, and that the ZIP embeds only packages `composer.json` names);
  Dependabot proposes their updates and the actions', one pull request per ecosystem, a week
  after their release. By hand, each a week after its release: the WordPress and WooCommerce
  versions the tests run on, pinned at several versions on purpose (`tools/downloads.sh` with
  their SHA-256, `tools/tests/wordpress.sh`, the workflows' matrices, `tools/phpstan/bootstrap.php`
  and the tests' default environment), and the tools the CI downloads, each pinned with its
  checksum (PHP, Composer, Strauss, WP-CLI, Plugin Check, MariaDB, the Docker images).

## Releases

The plugin is distributed only through WordPress.org's plugin directory: no GitHub release.
Releases are the maintainers' only: a maintainer pushes a tag `vX.Y.Z` (the plugin's version, in
its main file and `readme.txt`, with its entry `## X.Y.Z - <date>` in `CHANGELOG.md`) on a commit
of `main`; [`release.yml`](.github/workflows/release.yml) runs the CI again, checks the tag,
builds the ZIP from the lockfile and checks it, then keeps it with its SHA-256 (`SHA256SUMS`) as
the workflow run's artifact, `sattleme-for-woocommerce-vX.Y.Z`, for 90 days. The directory takes a
plugin only through its SVN repository, with a committer's password, which no workflow holds:
the team downloads the artifact, checks its SHA-256, and submits that ZIP, the first version on
WordPress.org's [Add your plugin](https://wordpress.org/plugins/developers/add/) page, each next
one to the plugin's SVN (`trunk`, then `tags/X.Y.Z`).

## License

MIT, see [LICENSE](LICENSE). The sattle.me name and logo (`assets/images/sattleme-logo.svg`,
`assets/images/sattleme-icon.svg`) are not under that license. The ZIP also holds the SDK
(`sattleme/checkout`, MIT) and BaconQrCode with its dependency DASPRiD/Enum (BSD-2-Clause), each
with its license.
