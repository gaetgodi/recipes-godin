# Allergen Checker v2 — Linked-Product Ingredients & Scan-Time Checking

Status: Draft for review — not yet approved for implementation.
Depends on: `docs/allergen-checker-spec.md` (v1), specifically the product
library built in Phase 3. Not to be started before Phase 3 is complete and
tested.

## 1. Purpose

Two extensions to the v1 allergen checker, addressing a real gap found in
live testing: compound/packaged ingredients (e.g. "chocolate chips," "1 box
cake mix") that have their own hidden ingredient lists the recipe text
never states. v1 Phase 2 ships a stopgap (Option A: a curated dictionary
flagging these lines as "Cannot determine — verify package label"). This
document specifies the more accurate follow-up: let an ingredient line
point directly at a real, scanned product from the product library, and
run the allergen check automatically at recipe-capture time rather than
only on demand.

## 2. Linked-product ingredients

- A new ingredient type in the recipe editor: **linked to a product**,
  alongside the existing free-text ingredient line.
- As the author types an ingredient, offer an **autocomplete/dropdown
  against their own product library** (and any products shared with them,
  same visibility rules as the product library itself) — e.g. typing
  "chocolate chips" suggests saved products like "Chocolate Chips, Dairy"
  and "Chocolate Chips, Non-Dairy."
- **If no matching product exists**, the ingredient stays as ordinary free
  text — falls back to v1 Phase 2's Option A dictionary flag if it matches
  a known compound-ingredient term, otherwise behaves exactly as today.
  Linking is optional, never required to save a recipe.
- A linked ingredient stores a reference (`product_id`) alongside its
  display text, not a copy of the product's ingredient text — so if the
  author later rescans/updates that product (e.g. the manufacturer changes
  a recipe), every recipe linking to it reflects the update automatically.

## 3. Matching engine changes

`get_checkable_text()` gains a new source branch: when an ingredient line
is linked to a product, the engine pulls that product's `ingredient_text`
and scans it *in place of* the recipe's own free text for that line —
tagged with a source that names both the recipe field and the linked
product, e.g. `source: "Ingredients → linked product: Chocolate Chips,
Dairy"`, so the report always shows a human exactly where a hit came from,
consistent with v1's existing per-line source citation.

## 4. Scan-time / capture-time checking

- When a recipe is created via the OCR/scan flow (photo or handwritten,
  same pipeline either way — no separate logic branch), and the creating
  user has an **active allergen profile**, run the allergen check
  automatically against the freshly extracted recipe, before or
  immediately after save.
- **If the collection has no active allergen profile set, the entire
  allergen layer is bypassed at scan time** — no check runs, no prompts,
  nothing — same as the Checker page's existing behavior when no profile
  is active. This is a deliberate, confirmed default: the feature stays
  invisible to anyone not using it.
- **On a flag at scan time: never block saving.** The recipe saves
  normally regardless of what the check finds. This matches v1's advisory
  philosophy — the tool informs, it never gatekeeps. A visible warning
  attaches to the recipe itself going forward (not just shown once to the
  person scanning), so anyone who later opens that recipe — including
  someone other than whoever scanned it — sees the same flag.
- **Whose profile runs at scan time** is the scanning user's own active
  profile, same as the on-demand Checker page today. If someone scans a
  recipe on behalf of another person's profile, they need that profile
  active first, same mechanism as any other check.
- **OCR accuracy risk, named explicitly**: this checker is only as
  reliable as the text it's given. Handwritten recipes carry higher
  transcription-error risk than printed/typed sources, and neither this
  feature nor v1 claims to compensate for OCR misreads. The persistent
  disclaimer from v1 Section 1 continues to apply in full at scan time —
  this doesn't change or strengthen that language, it's a reminder the
  same caveat covers scanned input too.

## 5. Explicitly out of scope for this document

- Any automatic mutation of a recipe's ingredient list to convert existing
  free-text lines into linked products retroactively — linking is opt-in,
  author-initiated, going forward only.
- Blocking or gating recipe save/publish on allergen check results, at
  scan time or otherwise.
- Running a check when no active profile exists on the collection.
