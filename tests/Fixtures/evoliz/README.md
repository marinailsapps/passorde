# Evoliz fixtures

Answers of the Evoliz API in their real shape (keys, value types, key order, pagination, HTML entities in
designations, leading spaces, dotted phones and the other quirks the sync code must handle), with invented
values only: record ids, company and user ids, article references and designations (a fictional nursery
catalogue, designations such as `VARIETY FORM POMMIER / PG : <rootstock> / CAL : <calibre> / LOT : …`), clients, contacts,
addresses, postcodes, phones (06.00.00.00.0x), `*@example.test` e-mails, document numbers (`BL-2026900…`),
external document numbers (`C-2026-…`, the app's order numbers), dates, selling and purchase prices, margins
and page counts.

The delivery notes are made-up documents: invented dates, lines and prices, with their totals computed by the
rule Evoliz applies (VAT per rate on the summed lines, rounded half up). `tests/Feature/Orders/OrderTotalsTest.php`
and `resources/js/lib/orderTotals.test.ts` pin the app's rounding to that rule on invented figures, half-up
edge cases included.

- Reads: `clients-*`, `articles-*` (two pages each, to exercise pagination), `paytypes.json` (Evoliz's
  standard payment types), `contacts-clients.json`, `users.json`.
- Push answers: `delivery-created.json` (POST /deliveries → 201), `client-created.json` (POST /clients → 201),
  `clients-empty.json` (GET /clients?code= with no match), `client-error-400.json` (POST /clients → 400,
  missing vat_number), `deliveries-list.json` (GET /deliveries list page).
- Stock adjustment: `article-7000240.json` (GET /articles/7000240 → 200, `stock_management: true`,
  `stocked_quantity: 4`). The tests serve the PATCH answer as the same article with the new `stocked_quantity`
  (Evoliz answers a PATCH with the whole article).
- Delivery mirror: `deliveries-window.json` (a `GET /deliveries?period=custom` page cut to two BLs, one invoiced
  with free and gift lines, one open, and to one page).
