# BATCH 5 SUMMARY - HTML and CSS cleanup

Scope: conservative cleanup. NO redesign, no HTML/PHP/JS/database changes.

## Changes (`css/style.css` only)
- Removed 134 rules/selectors that target classes used nowhere in any HTML, PHP or JS file (old about/hero/collage/quick-card/CTA band/FAQ/social/deco hooks and similar leftovers). 1509 -> ~1380 lines.
- When a comma list mixed used and unused selectors, only the unused ones were dropped.
- Classes built dynamically by JS (`badge-<status>`) and state classes (`is-*`, `qr-level`, `tag`) were deliberately KEPT.
- Removed trailing whitespace and extra blank lines; fixed the table of contents (added Responsive and Refinement layer entries) and removed a stale comment.
- HTML/PHP files: reviewed for unused markup and trailing whitespace; left unchanged on purpose (IDs, classes, names and data-attributes are used by JS/PHP).

## Not done (kept for safety)
- The CSS still has later "refinement/final polish" override sections and some repeated selectors (e.g. `.hero`, `.site-nav`). Merging them risks changing the cascade and thus the look, so they were NOT merged. Best done with a browser side-by-side check.

## Testing (what was actually done)
- Brace balance checked; every rule that did not involve an unused class is still present (script comparison of old vs new).
- All CSS class selectors removed were confirmed absent from `*.html`, `*.php`, `js/*.js`, `includes/*.php`, `actions/*.php`.
- NOT tested: visual rendering, responsive layouts, or PHP-generated pages in a browser/XAMPP (no browser or PHP available in this sandbox). Please compare each page at desktop and phone width.
