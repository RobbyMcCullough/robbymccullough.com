# Codec Authoring Guide

How to add a new compound field type to the design system without re-deriving the discipline from prior PRs.

## Who this is for

Anyone adding a field type with `valueType: 'object'` whose value round-trips through the parser and reconstructor. If your type bypasses the codec entirely (see [What stays out of the codec](#what-stays-out-of-the-codec)), you can skip this document.

## What you're committing to

The codec contract: for every covered field type, both runtimes must satisfy the same round-trip on a shared set of cases.

- `parse(annotated_html)` produces the expected `template` and `settings` (PHP and JS agree).
- `reconstruct(template, settings)` produces `annotated_html` (or `reconstructed_html` when the case is parse-asymmetric; see [Fixture file shape](#fixture-file-shape)).

Equality is checked through the shared HTML normalizer in [`tests/Unit/Services/Parser/CodecFixturesTest.php`](../../../tests/Unit/Services/Parser/CodecFixturesTest.php) and [`frontend/src/core/services/__tests__/codec-fixtures.test.js`](../../../frontend/src/core/services/__tests__/codec-fixtures.test.js), both of which load every file in [`data/spec/codec-fixtures/`](../codec-fixtures/).

A separate CI gate ([`field-types/__tests__/codec-contract-coverage.test.js`](../../../frontend/src/core/field-types/__tests__/codec-contract-coverage.test.js)) fails the build if a covered field type is missing a fixture file, so this contract cannot regress silently.

## Step-by-step

1. **Define the field type.** Add a definition under [`field-types/definitions/<type>.js`](../../../frontend/src/core/field-types/definitions/) and register it in [`definitions/index.js`](../../../frontend/src/core/field-types/definitions/index.js). Set `valueType: 'object'`. Do not set `codec: false` unless your type genuinely bypasses the parser/reconstructor.
2. **Implement the codec on both sides.** Update both [`AnnotationParser.php`](../../../backend/Services/Parser/AnnotationParser.php) / [`annotation-parser.js`](../../../frontend/src/core/services/annotation-parser.js) and [`AnnotationReconstructor.php`](../../../backend/Services/Parser/AnnotationReconstructor.php) / [`annotation-reconstructor.js`](../../../frontend/src/core/services/annotation-reconstructor.js). Mirror logic across runtimes; do not let one side carry behavior the other lacks.
3. **Write a fixture file.** Create [`data/spec/codec-fixtures/<type>.json`](../codec-fixtures/) with at least one case. The existing files cover the worked examples:

   - [`text.json`](../codec-fixtures/text.json) — heading and editor text fields, standalone SVG, the legacy two-key link form (`data-field` + `data-field-href`), empty-string and entity values.
   - [`link.json`](../codec-fixtures/link.json) — `<a>` link fields at top level and inside repeaters, with auxiliary attributes and a nested-repeater case.
   - [`image.json`](../codec-fixtures/image.json) — `<img data-field>` round-trip including alt (non-empty and empty) and inside single- and multi-item repeaters.
   - [`rating.json`](../codec-fixtures/rating.json) — active-only and two-state rating patterns, custom `data-field-max`, cross-item inactive-template detection in a repeater, and an SVG-marker variant.
   - [`repeater.json`](../codec-fixtures/repeater.json) — flat and single-item repeaters, multi-field items (text + image + bare link), class-variation via `{{{_variation}}}`, and a nested non-link repeater.
   - [`integration.json`](../codec-fixtures/integration.json) — cross-type cases combining several field types in one block (hero, feature grid, testimonial).
4. **Run both suites.** Run `npm test` and `composer test`. Both must pass. The coverage gate confirms the fixture exists; the fixtures suite confirms the round-trip holds.
5. **Reconcile drift.** When a case fails on one side but not the other, follow the [drift-fix protocol](#drift-fix-protocol).

## Fixture file shape

A fixture file is a JSON array. Each case is an object with these keys:

| Key | Required | Purpose |
|---|---|---|
| `name` | yes | Short label for the case; appears in test output. |
| `annotated_html` | yes | Input to `parse`; expected output of `reconstruct` unless `reconstructed_html` is set. |
| `template` | yes | Mustache template that `parse` should produce, and `reconstruct` consumes. |
| `settings` | yes | Settings object that `parse` should produce, and `reconstruct` consumes. |
| `reconstructed_html` | no | Expected `reconstruct` output when the codec legitimately normalizes its input on parse (see below). |

### When to use `reconstructed_html`

Use it only when a case is intentionally parse-asymmetric: the parser normalizes the input, so the reconstructor cannot equal the original `annotated_html`. The orphan-rescue case in [`repeater.json`](../codec-fixtures/repeater.json) (`"orphan rescue: parent without data-repeater is promoted via class-name inference"`) is the canonical example. The parser promotes a `data-repeater-item`'s parent to `data-repeater` based on a class-name match; reconstruct then emits the promoted form.

If `reconstructed_html` is omitted, the suite checks reconstruct equality against `annotated_html` (the symmetric default).

## Drift-fix protocol

When a fixture case fails on one runtime, the other has diverged from a shared expectation. Reconcile, do not work around.

### Direction is case-by-case, not directional

Earlier PRs framed JS as canonical with PHP usually patched. That framing did not survive contact with reality. PR 3 reconciled four deferred cases and every one of them needed both-side patches because the gap was in shared logic. Trace both sides; pick the side that needs to mirror, case by case.

### Per-case cap: ~30 to 50 lines

The fix budget for a single case is roughly 30 to 50 lines of source patch summed across PHP and JS. The cap exists to enforce focus. If you find yourself blowing past it, the fix is not the bug you think it is, or the case is too broad.

### Escalation ladder

1. **Narrow the fixture.** If a case is sprawling, split it into smaller cases that each fit the cap. Most "this won't fit" turns out to be "this case is doing too much."
2. **Defer once, close on second pass.** A case that resists a focused fix can be deferred for one PR cycle to gather context. It must close on the next pass.
3. **Escalate to out-of-codec.** If a case still does not fit on a focused second attempt, document it under [Out-of-codec escalations](#out-of-codec-escalations) below, with a one-line reason. Then remove the failing case from the fixture file. Do not defer indefinitely.

## What stays out of the codec

A small number of field types and behaviors live outside the codec by design.

### Field types that bypass the parser/reconstructor

If your field type is rendered by its own component and never round-trips through the parser/reconstructor, mark it `codec: false` in its definition. Today this applies to `form-submission`. The coverage gate honors `codec: false` and does not require a fixture.

#### Why `codec` is separate from `tokens.mode`

The two are orthogonal. `tokens.mode` describes how the field's sub-values are exposed for connection in the UI: `compound` types (link, image) expose individual sub-tokens like `text` and `href`; `custom` types (rating, form-submission) do not. `codec` describes whether the field round-trips through the parser/reconstructor at all.

Rating uses `tokens.mode: 'custom'` (its `value` and `max` are not independently connectable) but is fully in the codec (the parser handles `data-field-type="rating"` and the reconstructor expands rating sections to active/inactive markup). Form-submission also uses `tokens.mode: 'custom'`, but additionally bypasses the codec entirely. Conflating the two signals would silently drop rating from coverage.

When in doubt: if your type's HTML is produced or consumed by the parser/reconstructor, it is in the codec. Do not set `codec: false`.

### Behaviors that cannot be expressed even with `reconstructed_html`

If a behavior is parse-asymmetric in a way that `reconstructed_html` cannot capture (different settings shape on each runtime, runtime-specific side effects, etc.), it is out of codec. Document it under [Out-of-codec escalations](#out-of-codec-escalations) and keep it out of fixtures.

### CSS background-image content-field codec

The CSS background-image promotion (parser scans CSS for `background-image: url(...)` and creates an editable image field) is a separate codec. It is not currently fixture-covered. If it eventually needs governance, it gets its own fixture directory and its own gate; do not retrofit it into `data/spec/codec-fixtures/`.

## The HTML normalizer is the only concession

The shared HTML normalizer absorbs trivial serialization differences between PHP's DOMDocument and JS's parse5: attribute order, void-element form, between-tag whitespace. That is the entire concession. Parser and reconstructor output strings should otherwise match byte for byte.

If you find yourself wanting to extend the normalizer to absorb a new class of difference, ask first. The normalizer is a contract surface; expanding it expands what the codec is allowed to disagree on, and that decision belongs to the team, not to the case in front of you.

## Out-of-codec escalations

Cases that cannot be reconciled within the per-case fix budget (see the [escalation ladder](#escalation-ladder)) are escalated here as one-line entries with a brief reason, then removed from the fixture files. New entries land here only after the escalation ladder has been followed.

_None currently._
