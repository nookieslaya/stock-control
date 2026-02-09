# Stock Control

Plugin adds one REST endpoint for WooCommerce stock sync:

- `POST /wp-json/stock-control/v1/stock`

It updates stock by `sku` or `product_id` in `set` mode only.

## Requirements

- WordPress 6.x
- WooCommerce
- PHP 7.4+

## Run Locally

1. Install WordPress and WooCommerce.
2. Copy this plugin to `wp-content/plugins/stock-control`.
3. Activate **Stock Control** in wp-admin.
4. Make sure your local domain works (example: `https://example.local`).

## Create Sample Data

In WooCommerce, enable stock management first:

`WooCommerce -> Settings -> Products -> Inventory -> Enable stock management`

Then create products:

1. `Simple Product 1`, SKU `SIMPLE-001`, stock `5`
2. `Simple Product 2`, SKU `SIMPLE-002`, stock `10`
3. Variable product `Variable Product`, parent SKU `VAR-001`
4. Variations:
   - `VAR-001-256-black`, stock `13`
   - `VAR-001-256-gold`, stock `3`
   - `VAR-001-512-black`, stock `3`
   - `VAR-001-512-gold`, stock `22`

For each item/variation, make sure **Manage stock?** is enabled.

## Authentication (Application Passwords) - with Example

Use WordPress Application Passwords with Basic Auth.

Example setup:

1. Create or choose user `stock-manager` (role: `Shop Manager` or `Administrator`).
2. Go to `Users -> Profile`
3. In **Application Passwords**, set app name to `Postman Stock Sync`.
4. Click **Add New Application Password**.
5. WordPress will show a generated password once, for example:
   `abcd efgh ijkl mnop qrst uvwx`
6. In Postman Basic Auth use:
   - Username: `stock-manager`
   - Password: `abcd efgh ijkl mnop qrst uvwx`

Important:

- Do not use the normal wp-admin account password.
- User must have `manage_woocommerce` or `manage_options`.
- If auth fails, first test:
  `GET /wp-json/wp/v2/users/me?context=edit` with the same Basic Auth credentials.

## Endpoint Behavior

Supported:

- update by `sku` or `product_id`
- `qty` must be integer `>= 0`
- mode `set` only

Business rules:

- parent variable SKU (example `VAR-001`) returns error
- parent variable `product_id` returns error
- ambiguous SKU returns `ambiguous_sku`
- partial failures are returned per item; endpoint should not return HTTP 500

## Request Format

```json
{
  "items": [
    { "product_id": 123, "qty": 10 },
    { "sku": "SIMPLE-001", "qty": 7 }
  ],
  "mode": "set"
}
```

## cURL Examples

### Successful Request

```bash
curl -X POST "https://example.local/wp-json/stock-control/v1/stock" \
  -u "stock-manager:abcd efgh ijkl mnop qrst uvwx" \
  -H "Content-Type: application/json" \
  -d '{
    "items": [
      { "sku": "SIMPLE-001", "qty": 9 },
      { "sku": "VAR-001-256-black", "qty": 11 }
    ]
  }'
```

## Postman Example

- Method: `POST`
- URL: `https://example.local/wp-json/stock-control/v1/stock`
- Authorization: `Basic Auth`
- Username: `stock-manager`
- Password: `abcd efgh ijkl mnop qrst uvwx`
- Header: `Content-Type: application/json`
- Body (raw JSON):

```json
{
  "items": [{ "sku": "VAR-001-512-black", "qty": 4 }]
}
```
