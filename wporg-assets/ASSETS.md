# WordPress.org assets (`wporg-assets/`)

Publish the PNG files in this folder to the WordPress.org SVN top-level
`/assets` directory, alongside `/trunk` and `/tags`. They do not belong in the
plugin ZIP. This guide and generation notes are for maintainers, not SVN assets.
No asset here is needed for `make test`.

## Checklist

| File | Size | Status |
| ---- | ---- | ------ |
| `icon-128x128.png` | 128×128 | Ready — downsampled from the official 512px app icon |
| `icon-256x256.png` | 256×256 | Ready — downsampled from the official 512px app icon |
| `banner-772x250.png` | 772×250 | Ready — SuggestAPI / Discovery layer / WordPress + WooCommerce |
| `banner-1544x500.png` | 1544×500 | Ready — matching high-DPI banner |
| `screenshot-1.png` | 1200px+ wide | Captured — General settings, masked keys, live index picker, test button |
| `screenshot-2.png` | 1200px+ wide | Captured — actual Advanced controls |
| `screenshot-3.png` | 1200px+ wide | Captured for review — current queue, stalled reindex, and last sync |
| `screenshot-4.png` | 1200px+ wide | Captured — shop search with real demo-index results above the grid |

An SVG icon is optional: the two PNG icons are the supported directory assets.
The brand site's current SVG downloads embed raster images; do not describe
those wrappers as true vector exports or trace/redraw the official mark just
to create `icon.svg`.

## Brand and artwork

Brand reference: [SuggestAPI brand guidelines](https://suggestapi.com/brand).

Official source assets:

- App icon: `https://www.suggestapi.com/web-app-manifest-512x512.png` (512×512).
- White wordmark: `https://www.suggestapi.com/suggestapi-logo-white.png`.
- Brand-page source: `suggestapi_website/src/pages/brand.astro` in the sibling website repository.

Use the supplied logos, preserve their proportions and clear space, and do not
upscale `../assets/menu-icon.png` (64px). The icons here use the full-resolution
app icon. The banner uses the official white wordmark as its image reference.

Palette: SuggestAPI Mint `#00F0FF`, Signal Teal `#00B4C8`, Void `#000000`,
Surface `#1A1F2E`, Cloud `#FFFFFF`, and Misty `#E2E8F0`. Typography follows the
brand's Inter direction. The approved banner headline is **Discovery layer**.
The supporting line is **WordPress + WooCommerce**.

The banner was generated with the built-in ChatGPT Images tool, then exported
to the exact directory dimensions. The 772×250 version is downsampled from the
1544×500 version. The final prompt and provenance are in `GENERATION.md`.
The screenshots must be real captures; do not generate simulated plugin UI.

## Screenshot capture notes

Captured on 2026-09-29 from the real local Docker stack, WordPress 7.1.2,
WooCommerce 11.1.2, and Twenty Twenty-Five. Each screenshot is 1265×712 pixels.
The captures use the native browser viewport, not AI-generated or simulated UI.
The public key in screenshot 1 was masked in an unsaved form; no settings were
saved. The private key is never printed by the plugin. A read-only connectivity
test returned HTTP 200 and a real product hit before capture.

1. `screenshot-1.png`: General settings, live index picker, masked keys, and
   connectivity-test button.
2. `screenshot-2.png`: Actual Advanced settings. Agentic Domain is unresolved
   for localhost; the screenshot preserves that real state.
3. `screenshot-3.png`: Actual catalog sync panel. **Review before publication:**
   the local reindex still shows a 2026-09-24 start and pending actions. No
   successful sync was fabricated and no reindex/write request was triggered
   for these captures. Refresh this image after resolving the local queue.
   No error-log panel is displayed in this state, so the readme caption names
   the visible queue, reindex, and last-sync fields instead.
4. `screenshot-4.png`: Real shop search for “shoes” returns two demo-index
   results above the seeded WooCommerce grid. The grid uses its actual product
   placeholder images; demo results currently have no product URLs. Consider
   recapturing against the intended merchant index before publication.

The `readme.txt` Screenshots section follows the same 1–4 order. Screenshots
ship separately to SVN `/assets`, not inside the plugin ZIP.

## Directory validation

WordPress.org requires image dimensions to match their filenames. Keep banners
below 4MB, icons below 1MB, and screenshots below 10MB. See the
[WordPress.org asset specification](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/).

Before publication, verify the PNG dimensions, legibility at the smaller size,
brand spelling, screenshot captions, and missing screenshot files. Publishing
these assets to SVN is a separate release step.
