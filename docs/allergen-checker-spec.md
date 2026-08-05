# Allergen Checker — Feature Spec

Status: Draft for review — not yet approved for implementation.

## 1. Purpose

Allow a logged-in recipes.godin.com user to check a packaged product or a
recipe against an allergen profile, and get a clear, verifiable risk report.
Built for a real medical use case (a child with diagnosed food allergies who
carries an epi pen) — accuracy, transparency, and honest uncertainty matter
more than polish anywhere in this feature.

**Persistent disclaimer, shown on every report, non-negotiable:**
> This tool assists in reading ingredient labels and recipes. It does not
> replace reading the label yourself. Always verify against the physical
> product before consuming.

## 2. Allergen reference list

- Seed list: Health Canada's priority allergens — milk, egg, peanut, tree
  nuts, soy, wheat/gluten, sesame, fish, crustaceans/molluscs, mustard,
  sulphites — each with common label aliases (e.g. casein/whey/lactose →
  milk; albumin → egg).
- This seed list is a starting point, not a guarantee of completeness.
  Ingredient labeling conventions vary by manufacturer and no alias list can
  be exhaustive.
- Any user can add a **custom allergen** with its own aliases, independent of
  the seed list, to cover anything specific not already captured.

## 3. Allergen profiles

- A profile = a named person + a set of allergens/aliases to flag +
  identification metadata (name, age, date created/updated).
- **Ownership**: created by one user (the "creator").
- **Sharing**: creator shares a profile with specific named users (same
  mechanism as existing recipe/collection sharing — not global/public).
- **Edit rights**: only the creator can edit a profile's allergen list.
  Users it's shared with can view it and apply it as their active profile,
  but cannot modify it. (Deliberate: a profile shared among several family
  members must not be silently changed by someone other than the person who
  built it.)
- **Active profile**: each user selects which profile (their own or one
  shared with them) is "active" for their session. This selection persists
  across logins. Only the active profile is used when generating reports —
  never a background check against all profiles.
- A user may have multiple profiles available to them (their own +
  others shared with them) but only one active at a time.
- Every report identifies the active profile in full: name, age, date.

## 4. Products

- A **product** is a saved item with a name and an ingredient list, sourced
  the same way existing recipe photos are processed: photo → OCR → text via
  the existing Anthropic vision pipeline.
- Products are saved to a library (scan once, reuse many times), not
  one-off lookups.
- Products support the same copy/share mechanism recipes already have.

## 5. Recipes as checkable items

- A recipe's existing structured fields — ingredient list, preparation
  steps, and notes — are all scanned as possible allergen sources (an
  allergen can appear in a prep note, e.g. "brush with egg wash," even if
  it's not itemized as an ingredient).
- No separate OCR step needed for recipes already in the system; this reads
  existing structured/text fields.

## 6. Risk report

Three tiers per flagged allergen, not a binary safe/unsafe:

| Tier | Meaning |
|---|---|
| **Contains** | The allergen or a known alias appears directly in the ingredient text. |
| **May contain / shared facility** | The source text includes its own precautionary statement (e.g. "may contain traces of..."). |
| **Not detected** | No match found by this tool. Explicitly *not* the same claim as "confirmed safe." |

- Every flagged result shows the **exact ingredient line or text** that
  triggered it, so it's checkable against the source by a human, not a
  black-box verdict.
- Report header always shows: active profile's name, age, and profile date,
  plus the standard disclaimer from Section 1.

## 7. Batch / multi-item scanning

- Multiple products and/or recipes can be checked in a single pass.
- Each item gets its own **full** report — nothing averaged or diluted
  across items.
- A summary line appears above the individual reports, e.g. "2 of 5 items
  flagged."

## 8. Open items for implementation planning (not yet decided)

These aren't blocking the spec, but Claude Code should surface a plan for
each before writing code:

- Exact DB schema for profiles, allergens/aliases, products, and the
  share-permission tables (likely mirrors the existing collection-sharing
  tables).
- Where the seed allergen/alias list lives (static PHP array vs. DB table) —
  DB table is probably right, since it needs to support custom
  user-added entries alongside the seed list.
- UI location: new page(s) under the existing recipe-manager area, or a
  standalone "Allergen Checker" page linked from it.
- Whether OCR calls for products reuse the existing recipe-scan endpoint or
  need a dedicated one.

## 9. Explicitly out of scope for v1

- Editing a shared profile by anyone other than its creator.
- Any automatic/background checking not tied to an explicit user action.
- Treating "not detected" as a safety guarantee in any UI copy.
