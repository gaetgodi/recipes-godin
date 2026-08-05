# Allergen Checker v2 — Recipe Products Section & Scan-Time Checking

Status: Draft for review — not yet approved for implementation.
Depends on: `docs/allergen-checker-spec.md` (v1), specifically the product
library built in Phase 3 and the global-visibility model from Phase 5. Not
to be started before Phase 3 and Phase 5 are complete and tested.

## 1. Purpose

Two extensions to the v1 allergen checker, addressing a real gap found in
live testing: compound/packaged ingredients (e.g. "chocolate chips," "1 box
cake mix") that have their own hidden ingredient lists the recipe text
never states. v1 Phase 2 ships a stopgap (Option A: a curated dictionary
flagging these lines as "Cannot determine — verify package label"). This
document specifies the more accurate follow-up: let a recipe carry a set
of real, scanned candidate products alongside its ingredient list, and run
the allergen check automatically at recipe-capture time rather than only
on demand.

## 2. A Products section on the recipe

Rather than linking a specific product to a specific ingredient *line*
(the original approach considered for this document, now superseded by
this simpler design), each recipe gets a new **Products** section,
positioned directly under Ingredients in the recipe editor and on the
recipe view.

- The Products section holds a list of **candidate products the author
  has attached to the recipe** — e.g. a chocolate-chip recipe might carry
  "Chocolate Chips, Dairy," "Chocolate Chips, Non-Dairy," and a third
  brand, all attached at once, as a template.
- Adding a product is a **dropdown with a search field**, pulling from the
  full global product library (any product any user has scanned, per
  Phase 5's global-visibility model) — not limited to the author's own
  scanned products.
- This is saved as part of the recipe itself, visible to anyone who opens
  it, the same way the ingredient list is — not a picker a preparer fills
  in fresh each time they check.
- A recipe can have zero, one, or several attached products. Zero is the
  common case for most recipes (no change from today); several is the
  deliberate "template" case this document exists for.
- No ingredient line is rewritten, replaced, or linked to anything — the
  Products section is fully additive and separate from the existing
  free-text ingredient list. This also means Option A's compound-ingredient
  dictionary flag on ordinary ingredient lines is untouched by this
  feature; the two mechanisms coexist independently.

## 3. Matching engine — no changes required

Attached products are not a new source type inside
`get_checkable_text()`. They're checked exactly the way a product is
checked today when selected directly on the Allergen Checker page — each
attached product gets its own full, independent report, with its own
tier per allergen and its own cited matching lines. Nothing about how a
product is scanned, stored, or matched changes; the only new thing is
*where a product can be selected from* (attached to a recipe, in addition
to the existing standalone product picker).

## 4. Selecting which attached products to check

- **Checking a single recipe on its own**: if it has attached products,
  the Checker shows them and lets the person running the check choose
  which to include (e.g. "Non-Dairy" only) — this is the mechanism that
  makes the "copy the recipe, prune what doesn't fit" workflow in
  Section 6 work in the first place.
- **Checking multiple recipes together in one batch**: every attached
  product from every selected recipe is automatically included — no
  second, per-recipe layer of picking-within-a-picking on top of the
  batch selection. Confirmed as the deliberate rule to avoid a
  combinatorial "select which products, for which recipe, in a
  multi-recipe run" UI that would be more confusing than useful.

## 5. Scan-time / capture-time checking

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

## 6. Building a profile-friendly variant of a recipe

When one or more of a recipe's attached products don't clear the active
profile, the intended workflow is **not** for the tool to compare or
recommend between attached products automatically. That would edge the
tool toward making a safety judgment call on the preparer's behalf, which
cuts against the informational-only philosophy this feature has held to
throughout (v1 Section 1, v1 Section 9). Section 4 already establishes
that a single-recipe check lets the person choose which attached products
to include — that selection is the human decision point, not an automatic
recommendation.

For a lasting variant (not just a one-time check), the existing recipe
**copy** mechanism (already built for collection sharing) is the intended
path: the preparer copies the recipe, removes the attached products that
don't fit from the Products section of the copy, and labels the copy
accordingly (e.g. "Rowan Friendly"). The original recipe stays untouched
as the master, still carrying all its candidate products for anyone
else's use. No new comparison or recommendation UI is needed — this
reuses a pattern the codebase and its users already know.

Nothing in this document requires new matching-engine work to support
this — it's a usage pattern built entirely on top of Section 2's Products
section and the recipe library's existing copy function.

## 7. Explicitly out of scope for this document

- Rewriting, replacing, or auto-linking any existing free-text ingredient
  line to a product — the Products section is purely additive; attaching
  a product is opt-in and author-initiated.
- Any automatic comparison, ranking, or recommendation among a recipe's
  attached products — the person running the check chooses, the tool
  never picks for them.
- Blocking or gating recipe save/publish on allergen check results, at
  scan time or otherwise.
- Running a check when no active profile exists on the collection.
