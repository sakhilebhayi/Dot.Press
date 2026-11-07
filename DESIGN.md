---
version: alpha
name: The Spine
description: Dot.Press interface system. A deck is a line of dots, one claim per dot.
colors:
  paper: "#EEF0F1"
  sheet: "#FFFFFF"
  raised: "#F7F8F8"
  ink: "#14171A"
  ink-2: "#3A4046"
  muted: "#5B6269"
  line: "#D6DADD"
  amber: "#F2A100"
  amber-ink: "#6B4600"
  good: "#1D7A46"
  warn: "#9A5B00"
  bad: "#B3261E"
typography:
  display:
    fontFamily: Bricolage
    fontSize: 3.25rem
    fontWeight: 600
    lineHeight: 1
    letterSpacing: -0.03em
  title:
    fontFamily: Bricolage
    fontSize: 1.5rem
    fontWeight: 600
    lineHeight: 1.15
    letterSpacing: -0.02em
  ui:
    fontFamily: Instrument
    fontSize: 0.9375rem
    fontWeight: 400
    lineHeight: 1.45
  small:
    fontFamily: Instrument
    fontSize: 0.8125rem
    fontWeight: 500
    lineHeight: 1.35
  state:
    fontFamily: PlexMono
    fontSize: 0.75rem
    fontWeight: 500
    lineHeight: 1.2
    letterSpacing: 0.02em
rounded:
  sm: 4px
  md: 8px
  lg: 14px
spacing:
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 48px
components:
  button-primary:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.sheet}"
    rounded: "{rounded.md}"
    padding: 10px
  button-quiet:
    backgroundColor: "{colors.raised}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: 10px
  bead-current:
    backgroundColor: "{colors.amber}"
    textColor: "{colors.ink}"
    rounded: "{rounded.lg}"
    size: 12px
---

## Concept card

**Idea.** Every deck is a line of dots. One dot is one claim. The dot is hollow until the slide carries evidence for its claim, solid once it does, amber where you are. The workspace is built around that line.

**Metaphor.** A rosary or an abacus rail: things travel along a rail and settle on their place. It is also the Dot in Dot.Press, and the press's way of making a mark: one impression, one dot.

**Signature.** The Spine: a vertical rail on the left of the editor that holds the whole argument. Drag a bead and the line draws to its new place while the slide springs after it. The same line becomes the thin progress line while presenting, so what you built and what the audience sees are one object.

**Emotion.** Calm, exact, a little playful. A tidy workbench where the thinking is visible.

**Motion verb.** Slide along the rail and settle. Short travel, one small overshoot, then still. Nothing bounces twice.

**Screenshot.** The editor with the Spine on the left (hollow, solid and one amber bead, a section label between two runs of dots) and a slide on a pale stage, with one contextual bar floating at the selected element.

## Anchors (what the concept depends on)

1. A deck is an argument made of claims; research on presentation tools shows the commonest failure is slides with a topic and no claim, and decks whose consistency drifts. The hollow and solid bead states are derived from the real deck (does the slide hold a chart, table, image, quote or a number, and does its title state a claim).
2. The Dot in the name. The mark is a dot on a line.
3. Print: a press leaves one clean impression. The UI leaves marks only where something happened.

## What the concept decides

| Decision | Rule |
|---|---|
| Layout | A left rail (the Spine, 288 px) carries navigation and structure. The stage is the centre. Contextual tools appear beside the selection, never in a fixed ribbon. An inspector drawer on the right shows what the selection or the deck needs. |
| States | Hollow ring: claim without evidence. Solid dot: with evidence. Amber: current. A square dot marks a slide with an open review issue. Colour is never the only carrier: the shape and a mono state word carry it too. |
| Motion | Beads and slide cards move along the rail with a short spring. Lines draw rather than fade. Panels slide from their own edge. Under reduced motion everything is an instant state change. |
| Type | Bricolage (display, optical size, used large and rarely), Instrument (all interface text), IBM Plex Mono (state words and numbers only, never for decoration). |
| Colour | Ink is the identity. Amber is rare and always means "here". Paper is a cool grey, not cream. |
| Copy | Plain verbs. Slide, claim, evidence. No "magic", no "supercharge". Sentence case. |
| 404 and empty states | A spine with no beads: "Nothing on this line yet." |

## Colors

Paper `#EEF0F1` is the ground and the stage is slightly darker, `#E2E5E7`, so a white slide reads as a sheet. Ink `#14171A` carries text, solid beads and primary buttons. Muted `#5B6269` is secondary text and passes AA on paper. Amber `#F2A100` is only the current bead, the focus ring, the live slide and the selected thumbnail edge; text on amber is ink. Good, warn and bad are for review states and always come with an icon and a word. The dark theme keeps the same roles: paper `#101214`, sheet `#191C1F`, ink `#ECEEF0`, amber `#FFB52E`.

## Typography

Bricolage 600 for the deck title, empty states and the library's big numbers, set tight (letter spacing -0.02 to -0.03em). Instrument for everything else at 13 to 15 px. Plex Mono 12 px for bead states (`hollow`, `solid`, `here`), slide numbers, timers and sizes, with tabular figures. Slide content has its own font list and never uses interface fonts unless the user picks them.

## Layout

Editor grid: Spine 288 px, stage fills the rest, inspector 320 px when open. Top bar 52 px (title, save state as a small bead, share, present). Dock 56 px under the stage (insert tools, zoom, guides). Phones: the Spine becomes a drawer, the inspector a bottom sheet, the dock stays. The library is a wall of sheets on the same paper ground, with one large "continue" sheet.

## Elevation

No cards. Sheets (slides, thumbnails) have a 1 px ink edge at 8 percent and one soft shadow. Floating bars use the lift shadow. Everything else is flat and separated by hairlines at the line colour.

## Shapes

Radius 4 for chips and handles, 8 for buttons and fields, 14 for floating bars. Slides themselves are square cornered. Beads are circles; a review issue is a rotated square.

## Components

Button primary (ink fill, sheet text), quiet (raised fill, hairline), bead (12 px; hollow, solid, current, issue), spine row (bead, title, claim line, thumbnail on hover or when current), contextual bar (floats 12 px above the selection, flips below at the top edge), inspector section (title, controls, a single reset), dock, toast (bottom left, one line, a bead at its left that settles).

## Do and do not

Do show state with shape and word as well as colour. Do keep the stage quiet. Do let one amber thing be on screen at a time where possible. Do not add gradients, glass, coloured card washes, a tracked uppercase eyebrow over every heading, or arrows on every link. Do not use amber for decoration. Do not animate anything that is not a state change.
