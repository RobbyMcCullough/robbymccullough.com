# Night Market — Art Direction

## Color Rationale

The palette is built on a near-black layered ground. `--ds-color-ground` (#0d0e10) is the base canvas — used in the hero, photo strip, how-it-works, and booking CTA sections. `--ds-color-asphalt` (#151719) appears one step lighter and anchors alternating sections (tonight's route, tours, testimonials), creating a quiet rhythm of dark-on-dark contrast rather than any light-to-dark swing. `--ds-color-steel` and `--ds-color-steel-mid` surface the card and stall-item components, giving them just enough lift from the background to read as objects without breaking the low-key atmosphere.

Three accent colors carry all the chromatic weight. `--ds-color-neon-pink` (#ff2d7a) is the primary signal color — used for the hero's featured title line, `.bb-btn--primary`, featured stall numbers, booking CTA headings, and the neon glow text class `.bb-neon-text--pink`. It reads hot and urgent, pointing attention toward the most actionable elements. `--ds-color-mango` (#ffb800) functions as the commercial register: it appears on pricing (`stall-item__price`, `tour-card__price`, `trust-item__number`), secondary actions (`.bb-btn--secondary`, the Raohe tour card), and zine callouts — the color of money and warmth, reinforcing the market's economy. `--ds-color-jade` (#1db87a) has a single precise role: the live status dot and kicker line in the hero, signaling "tonight, now, open." It does not appear elsewhere on the landing page.

Opacity is handled by embedding alpha directly into `rgba()` values on border-color and badge backgrounds — `rgba(255, 45, 122, 0.15)` for `.bb-badge--pink`, `rgba(255, 45, 122, 0.2)` for tour card borders — rather than using the token's hex value at reduced opacity via CSS `opacity`. This keeps the surface colors independent of their containers.

Text uses `--ds-color-bone` for primary headings, `--ds-color-ink` for secondary body copy and testimonial quotes, and `--ds-color-muted` for all metadata, labels, descriptions, and eyebrows. The muted tier is used aggressively — the majority of body text on the page sits at `--ds-color-muted`, making the rare use of `--ds-color-bone` read as deliberate signal.

## Typography Approach

Three fonts divide responsibilities cleanly. Oswald (`--ds-font-heading`) handles every display heading, section title, button, badge, stall name, and tour card name — always uppercase, always tight letter-spacing (`--ds-letter-tight`, -0.02em), always bold weight. It creates the compressed, architectural quality of market signage. Work Sans (`--ds-font-body`) handles running body copy and testimonial quotes — set at normal or relaxed line-height, never uppercase, functioning as the readable contrast to Oswald's intensity. Space Mono (`--ds-font-mono`) handles everything that needs to feel logged or labeled: stall numbers, prices, kicker lines, eyebrows, duration metadata, fine print. Its monospace rhythm adds a receipt-tape, inventory-ledger quality to informational elements.

The `.bb-eyebrow` class applies `--ds-font-mono`, `--ds-text-xs`, `--ds-letter-widest` (0.18em), and uppercase — it appears before every section heading and functions as a rubric, not a headline. The `.bb-display-heading` class locks in Oswald bold with `--ds-line-tight` and uppercase as a reusable heading foundation, though the landing page sections each define their own heading styles using the same tokens directly.

Hero heading size uses `clamp(3.5rem, 9vw, 7.5rem)` — the largest text on the page, exceeding the `--ds-text-6xl` token. Section headings use `clamp(2rem, 4.5vw, 3.5rem)`, the booking CTA heading scales to `clamp(3rem, 7vw, 6rem)`, and the background "Queue." ghost text escalates to `clamp(8rem, 22vw, 20rem)`. The design uses `clamp()` throughout rather than breakpoint-based font sizes, keeping typographic scaling fluid.

Italic is used exactly once as a stylistic emphasis device — in `how-it-works__heading` and `testimonials__heading`, where an `<em>` tag with `font-style: normal` receives `--ds-color-mango` or `--ds-color-neon-pink` coloring with neon shadow. The italic is immediately overridden; the only function of `<em>` is semantic, with the visual emphasis handled by color.

## Decorative Vocabulary

**Diagonal caution stripes.** The `.bb-divider-stripe` class produces a 6px-tall horizontal band of repeating diagonal dashes in neon pink or mango, using a `repeating-linear-gradient` at -45 degrees. On the landing page, these stripes appear as `::before` pseudo-elements on `.tonights-route`, `.tours`, and `.booking-cta` sections — always at the top edge, always at 0.4–0.5 opacity. They function as section entry markers, borrowed from construction tape and industrial hazard graphics.

**Vertical edge stripe.** `.hero__stripe` is a 6px-wide vertical strip on the right edge of the hero, using a vertical repeating gradient of neon pink segments at 0.4 opacity. It reads as a film strip or ticker edge — marking the hero's boundary and reinforcing the grid-and-precision aesthetic.

**Neon glow text.** `.bb-neon-text--pink` and `.bb-neon-text--mango` apply color plus a two-layer `text-shadow` using the neon shadow tokens (`--ds-shadow-neon-pink`, `--ds-shadow-neon-mango`). In the hero, selected lines of the `h1` receive this treatment directly via the `.neon-line` and `.mango-line` classes. In section headings, the glow is applied to `<em>` or `<span>` inline elements for selective illumination.

**Ghost watermark typography.** In `.booking-cta`, the word "Queue." is rendered at `clamp(8rem, 22vw, 20rem)` in `--ds-color-steel`, positioned absolutely in the bottom-right corner. It functions as a watermark — too large to fully fit on screen, adding depth behind the functional content without competing with it.

**Ticket-stub perforated tear.** `.tour-card::after` draws a dashed horizontal rule 72px from the card bottom using a `repeating-linear-gradient` at 90 degrees in `--ds-color-border`. This mimics the perforation line on a physical ticket stub, reinforcing the card's identity as a bookable ticket rather than a generic product card.

**Stall-number labels.** `.bb-stall-number` uses Space Mono, `--ds-text-xs`, `--ds-letter-wide`, and a bottom border in `--ds-color-border`. On featured stalls, the number switches to `--ds-color-neon-pink` with a subtle glow. This borrows the typographic convention of numbered vendor placards or kitchen expediting boards.

**Pulsing scroll indicator.** The `.hero__scroll-line` is a 1px-wide, 40px-tall gradient line from `--ds-color-muted` to transparent, animated with a keyframe loop that oscillates opacity and Y-scale. It reads as a heartbeat or signal ping rather than a generic down-arrow.

**Live status dot.** `.hero__tonight-dot` is an 8px circle in `--ds-color-jade` with `--ds-shadow-neon-jade`. Paired with the Space Mono kicker, it signals that the tour shown is active and happening tonight — a status indicator borrowed from real-time UI patterns.

## Atmosphere & Texture

The page produces its atmospheric quality through surface layering rather than any photographic texture overlay or CSS noise filter. The stack from deep to shallow is: `--ds-color-ground` → `--ds-color-asphalt` → `--ds-color-steel` → `--ds-color-steel-mid` → `--ds-color-steel-light`. Each step is a few degrees lighter in HSL, enough to create object hierarchy without any surface leaving the dark register.

Sections alternate between ground and asphalt backgrounds — the rhythm is: hero (ground) → tonight's route (asphalt) → photo strip (ground) → how it works (ground) → tours (asphalt) → zine (ground) → testimonials (asphalt) → booking CTA (ground). The alternation is subtle because both values are very dark, but it prevents the page from reading as a single undifferentiated dark block.

The photo strip section runs full-width with no `.bb-container` wrapper — four images in a flush-edge flex row with 3px gaps, creating an unframed contact-sheet effect. Images use `filter: saturate(1.3) brightness(0.8)` at rest, lifting to `saturate(1.6) brightness(1)` on hover. This means photography is always slightly compressed by default, and hover reveals the full color as a reward.

The hero uses a two-layer photo treatment: `filter: brightness(0.45) saturate(1.4)` on the image itself, plus a `linear-gradient` overlay on `::after` that fades from transparent at the top to `var(--ds-color-ground)` at the bottom. The overlay ensures the hero title text floats over a dark ground without any white text-shadow hack.

## Anti-Patterns

1. **Do not use white or light backgrounds.** The entire palette operates in a dark register. A section with `background: white` or any light neutral would break the night-market atmosphere irrecoverably. If you need contrast, use the `--ds-color-asphalt` / `--ds-color-steel` elevation steps.

2. **Do not use rounded corners for primary containers.** `.bb-surface` uses `--ds-radius-md` (4px) and `.tour-card` uses `--ds-radius-sm` (2px). The design vocabulary is rectilinear and industrial — large border radii or pill containers read as the wrong kind of modern (consumer-tech, not market-stall).

3. **Do not use neon glow on body text or descriptions.** Neon shadows appear only on display headings, select hero title lines, prices, and live-status elements. Applying `--ds-shadow-neon-pink` or `--ds-shadow-neon-mango` to paragraph copy or supporting labels would collapse the signal hierarchy the design depends on.

4. **Do not neutralize images with desaturation.** Testimonial avatars use `filter: saturate(0.8)` — a deliberate de-emphasis for secondary portrait images. Do not extend this treatment to hero imagery or tour photography, which use equal or boosted saturation. Muted photos in primary positions make the design feel grey and uncommitted.

5. **Do not center-align hero text.** The hero title, tagline, and actions are all left-aligned, bottom-anchored. Centering the hero content would import a generic tourism-marketing register that conflicts with the brand's point of view — specific, direct, not performing hospitality.

## Image Treatment

Photography across the landing page observes a consistent filter approach: saturation is increased above native (1.2–1.4x at rest, 1.5–1.6x on hover), and brightness is pulled down (0.7–0.85x). The combination produces images that read as vivid but contained — market fire and neon signs appear intense without blowing out. No images use black-and-white or heavy duotone.

The hero background image (`object-position: center 40%`) is cropped to emphasize the market-level view — crowd and stalls, not sky. The additional gradient overlay on `::after` means the bottom third of the image blends directly into the page background color, creating a seamless continuation rather than a hard section edge.

Tour card images use `aspect-ratio: 16 / 9` at a contained width. Photo strip images use `aspect-ratio: 4 / 5` — a tall portrait ratio that creates height in the strip. These two aspect ratios are the only ones used across the page.

The zine visual panel uses `filter: saturate(1.3) brightness(0.7)` with a horizontal gradient overlay that fades to `--ds-color-ground` on the right edge, where it meets the content panel. This creates an image-to-text dissolve — no hard line, no box-shadow.

## Hero & Introduction

The hero establishes the page's entire design logic in one section. It is full-viewport-height, image-backed, bottom-anchored — text sits at the bottom of the frame, not the center, giving the market photography maximum breathing room above it.

The kicker row leads with the live-status dot (jade, glowing) and a Space Mono label reading "Tonight's Tour — Shilin Night Market." This positions the first readable text as a real-time event indicator, not a brand name. The heading breaks into three lines: a neutral bone-colored line, a neon pink line (`.neon-line`), and a mango line (`.mango-line`). The three-color title creates a visual chord — the design's full accent palette stated simultaneously in display type.

The hero's meta row pairs a prose tagline at `--ds-text-lg` in `--ds-color-ink` with a sidebar block divided by a `1px solid --ds-color-steel-light` left border, showing the next departure time and available spots. This sidebar block uses the layered mono-label + Oswald heading pattern that recurs throughout the page in stall items and tour cards.

Button hierarchy in the hero: `.bb-btn--primary` (neon pink fill) leads; `.bb-btn--ghost` (transparent with steel-light border) follows. This pairing — filled primary and ghost secondary — is repeated in the booking CTA.

## Voice & Tone

The copy throughout the page is written in a second person that is terse and assumes competence. "We find the uncle with the cleaver. You show up hungry." Parenthetical asides are used for product names: "(Or-ah-jian. Egg, oysters, sweet potato starch, chili sauce. Queue before 8 PM.)" The naming convention is to give the local transliteration first, then the description — never translating it into a Western-friendly food-writing frame.

Testimonials are chosen for specificity and mild absurdity ("I went back the next night without the group and ordered a double portion. I don't know what happened to me."). Social proof is not generic ("great experience!") but detailed enough to function as a secondary food recommendation.

Section headings use imperative fragments: "Show up hungry. We handle the rest." "Nobody leaves hungry. Nobody leaves quiet." These follow a two-clause structure with the second clause functioning as a reversal or punchline. The eyebrow labels are factual and lowercase-inflected even when uppercase in rendering: "Tonight's Route — Stall by Stall", "People who queued."

The overall register is that of an expert who has no interest in performing enthusiasm — specificity substitutes for promotion.

---

## Business Context

Night Market is a guided food tour operator and supper club brand rooted in East Asian night markets, with a current focus on Taipei (Shilin and Raohe night markets) and Kuala Lumpur (Jalan Alor). The business runs multi-stop guided tours in groups of eight, with guides who have established relationships with specific vendors. Tours run Thursday through Sunday year-round, with three distinct products: the Shilin Classic (entry-level, flagship), the Raohe Deep Cut (for repeat visitors or those wanting a less commercial experience), and Jalan Alor After Dark (KL market, four-hour format). The business also produces a quarterly printed recipe zine — risograph-printed, saddle-stitched, sold separately — containing stall recipes, vendor interviews, and process notes. No digital version exists.

The target audience is curious, travel-motivated food enthusiasts who distrust curated fine-dining experiences and want direct access to street-level food culture. They have likely already tried solo exploration and found it limiting. The brand identity is built on expertise delivered without condescension — the guides know which stalls to trust, which queues are worth it, and why, and the copy demonstrates that knowledge through specificity rather than credential-listing. The brand is not a luxury product; pricing is in NTD and MYR at accessible street-food-adjacent price points, and the emphasis is on access and knowledge rather than comfort or exclusivity.
