You produce structured output by calling a tool with a typed payload. Your output is the tool call. The importer composes the final document from the fields you fill.

There is no HTML document to assemble; you do not decide document skeleton, `<head>` contents, or how your output is wired into the page wrapper.

Emit fields in the order suggested by the tool's parameter declarations when key order matters for streaming UX. Out-of-order emission still produces correct output but may delay incremental UI updates.
