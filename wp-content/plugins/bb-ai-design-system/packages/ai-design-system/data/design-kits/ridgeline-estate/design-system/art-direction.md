# Ridgeline Estate — Art Direction

## Color Rationale

The palette is built on deep navy as a single ground. `--ds-color-navy` (#0b0e1a) is the base canvas — used in the hero, our-story, wine-collection, and footer. `--ds-color-navy-mid` (#111627) anchors the story panel beside the photo, offering the smallest possible step up in value without breaking the dark register. `--ds-color-navy-light` (#1a2035) reads as a tertiary elevation for cards or inset surfaces when needed. The three-step navy ladder creates hierarchy purely by surface, never by hue swing.

Gold is the single accent color, and it does a lot of work. `--ds-color-gold` (#c9a84c) is the signal tone — used on `.bb-btn-primary` fills, hairline rules before eyebrow labels, section eyebrow text, active link underlines, and year badges. `--ds-color-gold-light` (#e2c97e) is reserved for italicized emphasis inside headings (the `<em>` color in `h1`, `h2`), giving italic pull-words a warmer, softer tone so they read as stylistic flourishes rather than second headings. `--ds-color-gold-pale` (#f5edd8) is available but used sparingly — mainly for warm highlights. `--ds-color-cream` (#faf6ef) is reserved for occasional warm-toned text moments, not a default.

White with alpha is the primary text strategy. `--ds-color-white` for headings; `--ds-color-white-80` for body copy and prose; `--ds-color-white-50` for quieter metadata, muted descriptions, and stat labels; `--ds-color-white-20` and `--ds-color-white-10` for hairlines, borders, and dividers. This tiered opacity approach keeps the color palette narrow — no additional muted-gray tokens are needed because alpha handles the full range of quiet-to-loud text.

## Typography Approach

Two fonts, sharply divided. Cormorant Garamond (`--ds-font-heading`) handles every display heading, section title, stat number, pull quote, and logo wordmark — almost always at light weight (300 or 400), rarely bold. The design leans on Cormorant's italic faces for emphasis: `<em>` tags inside headings drop into italic with `--ds-color-gold-light` (e.g., "Where the *Vineyard* Becomes Your Table," "*Honest Wine*," "*Yours to Celebrate*"). The italic-in-heading pattern is the signature typographic move of the design — do not abandon it when writing new headings.

DM Sans (`--ds-font-body`) handles running body copy, eyebrow labels, navigation, buttons, and all-caps metadata. Eyebrow labels sit at `--ds-text-xs` with heavy tracking (0.25em–0.3em letter-spacing) and uppercase — they always read as rubrics, never as headlines. Nav links track slightly tighter (0.08em–0.12em) and sit at `--ds-text-sm` weight 400.

Heading sizes escalate from `--ds-text-4xl` for story-panel H2 up through `--ds-text-5xl` for tasting-room H2 and hero H1, which uses `clamp(3.5rem, 6vw, var(--ds-text-6xl))` for fluid scaling. The hero-ghost-year watermark escalates to `clamp(8rem, 18vw, 18rem)` at 7% opacity — oversized decorative typography that lives behind content rather than competing with it.

Italic-as-color is the only emphasis device. The design never uses bold heading weights, and never colors whole headings gold — only the italicized pull-word inside gets the gold-light treatment.

## Decorative Vocabulary

**Hairline gold rule.** The `.page-rule` pattern and `.hero-pre-line` produce a 48px-wide, 1px-tall gold hairline preceding an eyebrow label. This pattern repeats before section eyebrows and hero pre-titles. It functions as a bookmark — a quiet signal that a labeled section is about to begin.

**Oversized faded year watermark.** `.hero-ghost-year` renders "1978" at `clamp(8rem, 18vw, 18rem)` in gold at 7% opacity, positioned absolutely in the top-right of the hero. It's too large to fully frame — slight bleed past the right edge is intentional. This is the decorative move that marks the winery's historical anchor without prose.

**Roman numeral section markers.** `.story-roman` renders "I" at 9rem in gold at 18% opacity on the story photo; `.tasting-section-num` renders "II." inline in gold at 30% opacity above the tasting-room H2. These numerals sequence major sections as chapters without needing visible navigation.

**Est. year badge.** The gold-on-navy badge overlapping the story photo (`.story-year-badge`) displays the founding year in Cormorant and "Est." in DM Sans all-caps. This is the one place the design brings warm-on-dark contrast directly onto an image. Use for anchoring other year/date moments (first vintage, first release, first planting).

**Oversized italic quote mark.** `.vq-mark` renders `"` at 9rem in gold-light at 45% opacity above pull quotes. The line-height is crushed (0.55) so the quote mark tucks tightly above its text. Reserve for single-sentence pull quotes from people in the business — not for testimonials or reviews.

**Hairline reveal on link hover.** `.footer-links a::before` renders a 12px gold hairline that expands to 20px on hover, alongside the text shifting from 50%-white to full gold. The entire interaction is measured in 8px — small, unhurried. Apply to any vertical link list.

**Reveal on scroll.** `.bb-reveal` fades in + rises 32px on viewport entry via `IntersectionObserver`. Delays `.bb-reveal-delay-1` through `-4` stagger nested elements. This is the only motion the design uses for content — no parallax, no bounce, no slide-from-side. Use it sparingly and in sequence, not on every element.

## Atmosphere & Texture

The page produces its atmospheric quality through photography layered under dark gradients, not through CSS texture or noise. Every full-bleed image sits under a navy gradient overlay that fades from transparent at the top to `rgba(11,14,26,0.92)` at the bottom, allowing text to float over the image without text-shadow hacks. The Tasting Room, Events, and Vineyard Quote sections use darker whole-image overlays (`rgba(11,14,26,0.72)` to `0.82`) to dim the image into a mood rather than show it literally.

Images never appear as isolated cards on the navy ground — they're always either full-bleed, bounded inside a tall content split (Our Story), or gridded in dense 3-column panels (Tasting Room). Card-on-ground layouts are not part of this design's vocabulary for imagery.

The one exception to the dark-over-image rule is the Our Story photo, which gets a horizontal right-side gradient (`linear-gradient(to right, transparent 65%, var(--ds-color-navy))`) instead of a bottom fade — dissolving the photo directly into the adjacent story panel. No hard edge, no box.

## Anti-Patterns

1. **Do not use light or cream backgrounds.** The entire palette operates in the navy register. `--ds-color-cream` and `--ds-color-gold-pale` are accent tones for type and hairlines, not surface fills. A section with a cream or white background would break the estate's evening atmosphere.

2. **Do not move page content on hover.** Hover interactions that translate content (`transform: translateX`, `translateY`, or padding shifts on a flex/grid child) cause surrounding content to jitter. The established hover vocabulary is color change, border brightness, opacity lift, and image scale — not position shift.

3. **Do not hide content behind hover reveal.** Every piece of copy on the page should be visible at rest. Descriptions, buttons, and prices are not rewards for hovering. Hover can enhance (color lift, image scale), but must not conceal.

4. **Do not color whole headings gold.** Gold is for eyebrows, hairlines, accents, and italicized pull-words inside headings. A fully gold H1 or H2 is out of register — it reads as a warning callout, not an estate heading.

5. **Do not use neon, saturated, or bright photography.** Image treatment is warm, golden-hour, slightly dusty. Bright, high-saturation, or cool-toned imagery will fight the palette. If the photograph is modern and colorful, it's probably not right for this kit.

6. **Do not center-align body copy or primary headings.** The design's layout is asymmetric — split grids (hero, story, tasting header, events) with left-aligned type. Pull quotes are the only centered content (`.vineyard-quote`). Centering section headings collapses the asymmetric rhythm into generic marketing symmetry.

7. **Do not use italic outside of Cormorant heading emphasis and pull quotes.** Italic body copy looks amateur in this palette. The two italic faces that appear (heading `<em>` and `.vq-text`) are both Cormorant, both light weight. DM Sans italic is not used.

## Image Treatment

Photography across the landing page uses warm, golden-hour light as the single atmospheric baseline. Estate vineyards, stone buildings, barrel caves, and evening celebrations all share the same time-of-day. No harsh noon light, no blue morning light, no black-and-white. Saturation is native or slightly boosted; brightness is either native or pulled down via gradient overlay rather than filter.

The hero image uses `object-position: center 35%` — cropping to keep the vineyard horizon in the upper third and the ground in the lower two-thirds, giving the hero content room to breathe on top of dark foreground. Images in the tasting panel grid are `object-fit: cover` portrait crops (700x1000) with a hard bottom-to-top gradient veil rather than a filter — the image stays vivid where it peeks out at top.

The story photo is an exception: it's portrait-oriented and dissolves horizontally into the adjacent text panel. When promoting this pattern to other pages, always pair it with a 5fr:6fr split (or similar) and the same transparent-to-navy right-edge gradient.

## Hero & Introduction

The hero establishes the page's full design logic in one section. It is ~90svh, image-backed, bottom-anchored — content sits near the bottom of the frame with the vineyard photograph occupying the upper two-thirds. The hero is asymmetric: a left column carries the eyebrow rule + H1, and a right column carries the italic tagline + a two-button CTA row.

The eyebrow row leads with the 48px gold hairline rule, then a Space-wide DM Sans label ("Est. 1978 · Napa Valley, California"). The H1 runs in Cormorant light with an italicized gold-light pull-word ("*Vineyard*"), and a `<br>` line break inside — the heading is composed, not flowed. The tagline uses italic Cormorant at `--ds-text-2xl` with a 2px gold left border and padded inset, giving the prose the feel of a quotation without the quote marks.

The CTA row is a repeater with variation: item 1 is `bb-btn bb-btn-primary` (solid gold), item 2 is `bb-btn bb-btn-outline` (transparent with white border). This solid + outline pair is the design's primary button rhythm — use it everywhere a two-button row appears.

A vertical scroll indicator runs down the right edge, with the label "Scroll" set in DM Sans extra-wide tracking at writing-mode vertical-lr, terminating in a 60px gradient line with a soft pulsing opacity keyframe. This is the only animated decorative element in the hero — no parallax, no floating shapes.

## Voice & Tone

The copy throughout is first-person plural and unhurried. "We make Cabernet the way we always have: picked by hand, aged in French oak, bottled when it's ready." Specificity over adjectives: acreage, years, French oak, hillside, picked by hand. The brand name "Ridgeline" is used sparingly — the copy trusts the reader to remember where they are rather than repeating the wordmark.

Section headings follow a two-clause pattern with the second clause italicized: "Every Pour / *Tells a Story*" — "Where the *Vineyard* / Becomes Your Table" — "Three Generations / of *Honest Wine*." The italicized word is almost always the emotional pull word (verb, noun, or adjective that carries the sentence's weight). When writing new headings, preserve this structure.

Eyebrow labels are short factual fragments — "Host Your Moment," "The Cellar," "Where to Find Us" — never a full sentence, never performative. They read as section navigation, not as introductions.

Testimonials and quotes are short, attributed to named people by role ("— James Callahan, Third-Generation Winemaker"). No stars, no ratings, no review-aggregator language. The quote speaks for itself.

The register is that of a family business that knows its work and doesn't need to announce it. Avoid any copy that sounds like marketing — "experience," "journey," "unforgettable," "luxury" — these words belong elsewhere.

---

## Business Context

Ridgeline Estate is a family-owned Napa Valley winery, established 1978 on the eastern ridge of the valley. 40 acres under vine across eight varietals, with Cabernet Sauvignon as the flagship. Three generations have worked the estate — founders Harold and June Callahan planted the first vines; their grandchildren, including third-generation winemaker James Callahan, work the property today. The wine is estate-grown, picked by hand, aged in French oak, and bottled when ready. No wholesale distribution emphasis — tastings and direct sales drive the brand.

The brand offers three primary visitor experiences: a $45 Estate Flight in the barrel cave, a $70 Terrace & Bites pairing with charcuterie on the sun-drenched terrace, and a $120 Vineyard Walk & Cave Tasting with the winemaker. The estate also hosts weddings (up to 150 guests), live music evenings in summer/fall, and private dinners.

The target audience is return-visitor wine tourism — people who have done Napa once and want to get past the polished tasting-bar circuit into a family-run estate with a working vineyard. They value provenance, the winemaker's voice, and unhurried hospitality over status and Instagram backdrops. The brand identity is built on continuity and trust — "we make it the way we always have" — without performing rusticity or nostalgia. The copy demonstrates that through specifics (row counts, oak programs, named family members) rather than adjectives.
