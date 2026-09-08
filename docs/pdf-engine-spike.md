# PDF Engine Spike (P05)

**Question:** dompdf (pure PHP, no server dependencies) or Browsershot/Chrome (needs Node +
Chrome on the server) for invoices, receipts and reports?

**Method:** the same invoice template (`resources/views/pdf/sample-invoice.blade.php` —
English body plus three Bangla strings containing pre-base vowel signs and conjuncts:
রেডিয়েন্ট, পোল্ট্রি, বিক্রিত, পূর্বানুমতি) rendered two ways with the same embedded font
(Noto Sans Bengali), rasterised with poppler and inspected.

## Result

| | dompdf 3 (`barryvdh/laravel-dompdf`) | Chrome (`page.pdf`, what Browsershot uses) |
| --- | --- | --- |
| Latin text, numbers, tables, layout | ✅ identical to the browser | ✅ |
| Bangla text | ❌ **mis-shaped**: vowel sign ে/ি drawn *after* its consonant, conjuncts (ন্ট, ল্ট্র, ক্রি) not formed — readable-looking but wrong | ✅ correct shaping |
| Fonts | must ship a `.ttf` in the repo (Windows' Nirmala UI is a `.ttc`, which dompdf cannot load) | system or bundled font, `.ttc` fine |
| Server needs | none | Node + Chromium (Puppeteer) on the server, ~300 MB, must be kept patched |
| Speed | ~1 s/page | ~2–3 s/page (browser launch amortised with a pool) |

dompdf has no OpenType shaping engine (no GSUB/GPOS), so **any Indic script is unusable
with it**, regardless of font.

## Decision (rebuild-plan §0 #10)

* **Default: dompdf**, because the legacy invoice is English-only (DM Sans, "Radian
  Agrovet") and parity does not require Bangla on paper. Zero server dependencies.
* **If Bangla must appear on any printed document** (customer names in Bangla, a Bangla
  footer, Bangla reports) → switch that document to **Browsershot** (`spatie/laravel-pdf`).
  Confirm with the product owner in Phase 0 using the real invoice sample.
* Screen printing (`PrintLayout` + `window.print()`) already renders Bangla correctly because
  it *is* Chrome — most "print" needs are covered without any PDF library.

## Outcome in P16

The invoice PDF ships on dompdf as decided, from `resources/views/pdf/invoice.blade.php`, and
the screen print view (`pages/print/invoice.tsx`) is the Chrome-rendered twin. Both are fed by
`App\Support\Invoices\InvoiceDocument`, so switching this one document to Browsershot later
means changing the renderer, not the content.

## Artefacts

* Template kept at `resources/views/pdf/sample-invoice.blade.php` as the starting point for the
  P16 invoice PDF.
* dompdf fonts go in `storage/fonts` (cache) and the source `.ttf` under a path inside the
  project (dompdf's `chroot`); do not point it at `C:\Windows\Fonts`.
