## Forms

Form elements are structural. Don't annotate every label, placeholder, or option — settings panels become unreadable and copy gets coupled to backend field names and values.

### Annotate

- Form heading, subheading, eyebrow
- Step titles and descriptions (multi-step forms)
- Submit button text
- Consent and legal copy
- Success and error messages

### Skip

- Input labels, placeholders, and hints
- Select options and radio/checkbox option text
- Progress step labels
- Back, continue, and other navigation buttons
- Required markers and field-level validation text

### Unsupported field types

- **File uploads** (`<input type="file">`) are not included in submissions. Don't add file inputs — the value will be silently dropped when the form is sent.

### Submission behavior

Every `<form>` element must have an `id` attribute. The id keys the form's submission settings, so the user can choose how submissions are handled (email, webhook, custom hook, or none). Forms without an id won't have their settings surfaced in the editor.

New forms default to "None" — no submission handling. They render as plain HTML with native browser submit until the user configures an action in the settings panel.

When an action is configured, form submission is handled by a runtime that ships with the design system. Don't write your own submit handlers and don't add hidden spam-protection fields — the runtime handles submission, double-submit prevention, and spam protection automatically.

During submission the submit button text becomes "Sending…" and the button is disabled. On success the runtime clears the form fields and the submit button becomes "Sent" (disabled). That's the default — nothing else appears on the page unless you author it.

### Success UX

Success is open-ended. If you want to show a thank-you panel, open a modal, redirect the user, reveal an offer, play an animation, or fire analytics, listen for the `fl:form:success` event on the form element.

The runtime dispatches three events on the form, all bubble:

- `fl:form:submitting` — the request has started
- `fl:form:success` — detail: `{ response }`. The server accepted the submission.
- `fl:form:error` — detail: `{ errors }`. The submission failed.

```js
document.getElementById('signup-form').addEventListener('fl:form:success', () => {
  // reveal thank-you panel, redirect, fire analytics, etc.
});
```

Annotate any copy the user should be able to edit (headings, body text, offer text) with `data-field`. The annotation is about editability, not runtime behavior.

### Error UX

If the form (or a nearby ancestor of it) contains an element annotated `data-field="error_message"`, its text content is replaced with the server error and it's revealed. Otherwise the runtime injects a minimal error notice before the submit button.

On error the submit button is re-enabled with its original label so the user can correct and retry.
