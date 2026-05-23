# Settings Merge Rules

These rules govern how `update_block_settings` payloads are merged onto the current settings object. The host parses your output with `JSON.parse` and validates it against a fixed schema; invalid output is rejected.

## Output schema

Emit a single field: `settings`. The value is an object containing **only the keys that change**. The host recursively merges your output onto the current settings; never resend unchanged keys.

```json
{ "settings": { "heading": "Welcome", "buttonLink": "/about" } }
```

## Merge semantics

`update_block_settings` is **merge, not replace**. The host applies one uniform recursive rule at every depth:

- **Objects** merge key-by-key. Keys in the current settings that you don't mention are preserved.
- **Arrays (repeaters)** merge position-by-position. Use `{}` as a placeholder for any row you aren't changing. Trailing rows past the end of your patch array are preserved, so you only need to send the array up through the last row you change.
- **Empty patch** (`{}`) is identity. An empty object as a patch leaves that subtree untouched.
- **Scalars and type mismatches** replace. A scalar in your patch overwrites whatever's there; an array in your patch where the existing value is an object (or vice versa) replaces the whole subtree.

Repeater rows support add, remove, and reorder via the merge protocol (see Repeater fields below). Template-deep changes (changing row HTML, adding new field columns) still go through `html` via `write_block`. If the requested change is template-deep, return an empty `{ "settings": {} }` so the host can re-route.

Practical rules:

- Include only the keys you want to change, at any depth.
- Re-sending an unchanged value is harmless but noisy; omit it.
- To clear a string field, set it to `""`. Do not omit it.
- Never send the entire settings object back; that would no-op for unchanged keys but masks intent on review.

## Schema fidelity

Use the **exact key names** from the schema in the user message:

- **DS custom blocks:** keys from the block's `form` config (each entry's `name`).
- **Native BB modules:** keys from the editable-text-fields map. This is a restricted, allow-listed view computed from BB's inline-editor filter — only the keys returned in that map are writable. Non-text settings (CSS code, JS code, layout config, etc.) are not exposed here.
- **WP core blocks:** attribute names from the block type registry. Use `style.*` paths (e.g. `style.color.background`) for nested style attributes; the host deep-merges them.

## Value-type coercion

Match the schema's expected type:

- **String** fields: emit a string. Don't wrap in arrays or objects.
- **Number** fields: emit a number, not a numeric string. `42`, not `"42"`.
- **Boolean** fields: emit `true`/`false`, not `"true"`/`"false"` or `1`/`0`.
- **Object / nested** fields: the merge is recursive. Send only the keys you want to change at any depth; unmentioned keys at every level are preserved.

## Repeater fields

Repeater fields are arrays of objects, each object containing the row's keys. The merge is positional: index 0 in your patch maps to index 0 of the existing array, index 1 to index 1, and so on (count from zero).

- **Edit one row**: send the array up through the row you change. Use `{}` for every preceding row you aren't changing. Omit any trailing rows; they're preserved automatically.
  - Example: to change row 2 in a 5-row repeater, send `[ {}, {}, { label: "New" } ]`. Rows 0, 1, 3, and 4 are preserved.
- **Edit multiple rows**: same shape, multiple non-`{}` entries.
  - Example: `[ { label: "A2" }, {}, { label: "C2" } ]` updates rows 0 and 2 in a longer array.
- **Edit one field of one row**: object merge applies inside the row, so `[ {}, { cta: { text: "Tap" } } ]` changes only the `text` of row 1's `cta`, leaving `cta.href`, `cta.target`, etc. intact.
- **Add a row at the end**: send a patch that extends past the existing length. Example: against a 3-row array, `[ {}, {}, {}, { label: "New" } ]` appends a 4th row.
- **Remove rows**: pass `null` at the array index. Example: `[ {}, {}, {}, {}, null ]` against a 5-row array removes the last row. Multiple removals fine: `[ {}, null, {}, null ]` removes rows 1 and 3.
- **Reorder rows**: write the kept rows into their new positions. Example: to swap rows 0 and 1, send `[ { ...row1 contents }, { ...row0 contents } ]`. Trailing rows past the patch end stay where they are.

If you don't have the current repeater value (you need it to know how many rows exist or which index to target), request it via `read_node` with `include: ["settings"]` first.

Worked nested example. To change one feature label inside the second tab of a tabs module:

```json
{ "tabs": [ {}, { "features": [ {}, { "label": "New" } ] } ] }
```

Tab 0, tab 1's other fields, tab 1's feature 0, and any tabs past index 1 are all preserved.
