# SilverShop Product Feed — Google

[![CI](https://github.com/silvershop/silvershop-product-feed-google/actions/workflows/ci.yml/badge.svg)](https://github.com/silvershop/silvershop-product-feed-google/actions/workflows/ci.yml)

The **Google Merchant channel connector** for
[`silvershop/product-feed`](https://github.com/silvershop/silvershop-product-feed) on Silverstripe CMS 6. It plugs a
Google Shopping channel into the feed core's registry seam: an **RSS 2.0 + `g:` namespace** serializer, the
`CatalogProductData` → `g:*` field map, and a bundled **Google product taxonomy** with per-category mapping. No core
change — the connector registers itself.

```
product-feed core ──(registry)──▶ GoogleChannelFeed.mapItem() ──▶ RssGFormat ──▶ public/feeds/google.xml
                                   (g: attribute map)              (RSS 2.0 + g:)   served at /feed/google
```

## Requirements

- PHP 8.3+, `silverstripe/framework` ^6, `silverstripe/cms` ^6, `silvershop/core` ^6.2
- `silvershop/product-feed` (the feed core; pulls in `silvershop/catalog-data` transitively)

> **Release ordering.** `silvershop/product-feed` and `silvershop/catalog-data` are not on Packagist yet, so CI / a
> plain `composer require` is **release-blocked until they are published** — a known ordering dependency (core →
> connector), like `silvershop/newsletter` → its ESP connectors. For development and the clean-room, install
> product-feed + catalog-data (and its guarded soft-deps) as **path repositories**.

## Installation

```bash
composer require silvershop/product-feed-google
```

Then `dev/build?flush=all`. This:

- adds the per-category **Google product category** mapping to `ProductCategory`;
- **seeds the bundled Google taxonomy** (~5,600 categories) when the table is empty (toggle with
  `GoogleShoppingCategory.seed_on_build`; re-seed with `sake tasks:ProductFeedGoogle-SeedTaxonomy`, `--fresh` to
  rebuild);
- **registers + enables** the `google` channel in the feed core (installing the connector means you want the feed).

## Mapping categories

Open a product category in the CMS → **Google Shopping** → pick a Google product category from the tree. A
sub-category inherits its nearest mapped ancestor, so mapping a top-level category is usually enough. A product's
`g:google_product_category` is resolved from its category's mapping (walking up the tree).

## Generating & serving

The feed core owns generation, scheduling and serving (see its README). With this connector installed:

- generate: `sake tasks:ProductFeed-Generate --channel=google` (or let the recurring `GenerateProductFeedJob` do
  it), written to `public/feeds/google.xml`;
- serve: `https://your-shop/feed/google` (the core's serving route) — point Google Merchant Center at that URL, or
  at the static file directly.

## The `g:` field map

| Google attribute | Source (`CatalogProductData` unless noted) |
|---|---|
| `g:id` | `feedId()` (= conversion-tracking `content_ids`) |
| `title` / `description` / `link` | `title()` / `description()` / `link()` |
| `g:image_link` / `g:additional_image_link` | `imageLink()` / `additionalImageLinks()` |
| `g:availability` | `availability()` → `in_stock` / `out_of_stock` / `preorder` / `backorder` |
| `g:price` / `g:sale_price` | `formattedPrice()` / `formattedSalePrice()` (`"12.95 EUR"`) |
| `g:brand` / `g:gtin` / `g:mpn` | `brand()` / `gtin()` / `mpn()` (gtin/mpn via `silvershop/product-identity`) |
| `g:identifier_exists` | `no` only when no gtin + mpn + brand, else omitted |
| `g:condition` | `condition()` → `new` / `refurbished` / `used` |
| `g:google_product_category` | the mapped `GoogleShoppingCategory` (id, or path) via the category parent-walk |
| `g:product_type` | `productType()` with the site root trimmed (`product_type_trim_segments`, default 1) |
| `g:item_group_id` | `itemGroupId()` (parent product) — variants are separate `<item>`s sharing it |
| `g:color` / `g:size` | matched from `attributes()` (`color_attributes` / `size_attributes`) |
| `g:shipping` | **opt-in** (`shipping_enabled`, default off) from configured flat rates |

All text is XML-escaped (XSS-safe) and the document is well-formed RSS 2.0.

### Config

```yaml
SilverShop\ProductFeed\Google\GoogleChannelFeed:
  google_product_category_as: 'id'   # or 'path'
  product_type_trim_segments: 1      # drop the catalogue root from g:product_type
  shipping_enabled: false
  shipping:
    - { country: 'NL', service: 'Standard', price: '4.95 EUR' }

SilverShop\ProductFeed\Google\Model\GoogleShoppingCategory:
  seed_on_build: true
```

## Merchant Center setup

1. Verify + claim your domain in Google Merchant Center.
2. Products → Feeds → add a **scheduled fetch** feed pointing at `https://your-shop/feed/google`.
3. Set the fetch frequency to match `GenerateProductFeedJob.regenerate_interval_mins` (default nightly).
4. Map your categories (above) so `g:google_product_category` is populated, and fix any attribute warnings in the
   Merchant Center diagnostics.

## Licence

BSD-3-Clause.
