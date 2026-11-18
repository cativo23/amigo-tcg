# Roadmap

The pending work, grouped into phases. Each phase is a self-contained block of
work with one theme, sized to be finished in one go, and the phases are
ordered by **value for the effort**: quick, valuable work first, big projects
after, polish last. At equal effort, anything that risks losing or corrupting
data goes before anything a visitor merely sees.

Each phase carries its effort and value:

- **Effort** — `S` under half a day with tests · `M` one to two days · `L` more.
- **Value** — `high` breaks something or misprices cards · `med` a real
  improvement · `low` polish.

A finished phase leaves this file in the same PR that closes it; what shipped
is in [`CHANGELOG.md`](CHANGELOG.md). Anything marked *(prod)* needs a look at
production before acting, and any write to production needs a yes.

## Phase 0 — Write the specs

`M · high` — turn every phase below into a spec that stands alone: scope, the
files and callers it touches (checked against the code), the tests written
first, the production steps that need a yes, the risks, and what "done" means.
Phases that already have a plan (6, 7, 8) get that plan re-checked against the
current code instead of rewritten. Phases that touch production data get a
red-team pass before anyone signs off. The feature phases (9, 13–16) also need
product decisions, so their specs list the open questions rather than guess.

Specs are saved in `.superpowers/sdd/`, one file per phase.

## Phase 1 — The collection page's price read, and release v1.7.1

`M · high`

1. **Bound the collection page's price read.** `S–M · high` — `/admin`
   (`CollectionItems`) eager-loads 30 days of every snapshot of every owned
   card, `raw` included, and sorts them in PHP; at 128M it showed the 500
   page, and 192M only delays it. Load only the deciding rows, as the public
   pages already do (`DecidingPriceRows::loadOnto`); `SetGrid` has the same
   pattern. Its cache key is per card-id set, so a search keystroke writes a
   new Redis entry while Redis has no `maxmemory` (Phase 11 closes that side).
2. **Fix the flaky `CollectionValueSeriesTest`.** `S · med` — "warming sums the
   totals over card chunks…" fails now and then on CI, and a red `test` check
   blocks a release. Suspect: `PublicCollection::cardsQuery()` has no
   `ORDER BY`, so the first-seen currency can differ between the warm run and
   the page. Try `orderBy('cards.id')`; unconfirmed.
3. **Release v1.7.1.** `S · med` — CHANGELOG entries for #171, #172 and #174
   (unreleased on `master`, including the two compose changes: PHP
   `memory_limit` 192M, `app` limit 384M, 4 FPM workers) and for items 1–2, so
   the page fixes reach production and the repo's compose file matches the
   host it ships to. Needs a yes.

## Phase 2 — Production check pass

`S · med` *(prod)* — read-only, after a nightly run.

- tcgdex v2.49.0: the links and prices of `mep`, `30th` and `30th-c` did not
  change, and the images and prices tcgcsv fills agree with tcgdex's.
- `sv10.5w` (mapped by hand) has its links and prices.
- The held-back list (`gaps_implausible` in the last run summary): the
  2026-10-22 list predates v1.6.16, which removed the Cardmarket check that
  held most of them.
- The `WarmValueSeriesJob` row in `failed_jobs` (attempt limit, cause never
  looked at; possibly a worker killed by a deploy).

## Phase 2c — Sell your own copy on the landing

`S–M · med` — a landing act for what is built and never advertised: the
collector's own photo of their copy, whole and under the loupe, behind a link
anyone opens; and the official art at real size, approximate until
calibrated. Copy round, then a mockup, then a demo photo seeded by
`demo:seed-gallery` (a production write, with a yes). Touches the euro line
Phase 8 owns. Spec: `SPEC-A.md`.

## Phase 3 — Linking prints to TCGplayer, and the CSV's price source

`M · med-high`

1. **A command to link a print to a TCGplayer product.** `S · med-high` —
   today every admin link is a tinker one-liner. A `catalog:link-tcgplayer`
   command (card, variant, product, subtype, group; validated against the
   group's products) makes the open link gaps routine:
   - ten MEP cards with no link and no image (`mep-055`–`mep-063`,
     `mep-Museum`);
   - about 26 deck-exclusive prints tcgdex gives no TCGplayer id;
   - every stamped print a collector adds (Prize Pack, Pokéween), which never
     links itself.
2. **Say where each price came from in the CSV export.** `S · med` — add a
   `price_source` column (`CollectionCsvExporter::HEADER` has none). Then
   delete the hand-entered rows tcgcsv now covers (10 rows over 7 prints) — a
   production write, with a yes.

## Phase 4 — Cleanup and chart leftovers

`M · low`

- **Small hygiene.** `composer.json` says `"php": "^8.3"` while the lock needs
  8.4 (Pest 5, PHPUnit 13) and every runtime is 8.4; delete the orphaned
  Breeze pages (verify-email, confirm-password, `dashboard`,
  `x-application-logo`) and the unused styles and tokens listed at the end of
  `design.md`.
- **Chart code.** Unify `PriceChart` and `ValueChart` (give
  `PriceChart::plot()` a `maxStep`, delete `ValueChart`) and fold the
  copy-link and chart-slider code duplicated in `card.js`, `collection.js` and
  `activity.js` into shared modules, aligning `stepReading` / `readingStep`
  argument order.
- **Chart behavior.** After a mouse sweep over a chart, the next arrow key
  steps from the day under the pointer while the slider still announces the
  last keyboard value. A manual price's step line stops at its first and last
  entry instead of reaching the range edges.
- **Page limits and sizing.** Activity reads the first 500 cards, the
  collection page 600. A card page strip with fewer than three tiles renders
  slightly smaller than the mockup.

## Phase 5 — Filter the collection by card type

`M · med` — selling a batch of Trainers means finding them first. Pokémon
(Basic, Stage 1, Stage 2), Trainer (Supporter, Item, Tool, Stadium), Energy
(Basic, Special), combinable with the set and price filters. The stored tcgdex
payload (`raw`) has `category` on every card, `trainerType` on modern trainers
(null on some old ones) and `energyType` on energy, so a first version can
read the payload without a migration. Needs a ruling on BREAK, V, VMAX and ex.
*(prod: count cards with no `category`.)*

## Phase 6 — Price link integrity

`M–L · med-high` *(prod)* — plan:
`.superpowers/sdd/2026-10-23-price-link-redesign/FINAL.md`.

1. **Write the identity matcher's links.** `M · med-high` — the matcher has run
   in log mode since v1.6.12 and agreed with every existing link (6,916
   agree, 0 different, 9 new on 2026-10-26). Turning it on needs code, not
   just config: `TCGCSV_IDENTITY_LINKS` knows only `log` and off, and
   `CardTcgplayerLink::TRUST` has no identity level. Roll out with a yes after
   two matching builds.
2. **Integrity constraints on prices and links.** `M · med` — CHECKs on
   source/origin/currency/method, `UNIQUE(product_id, sub_type)` on links,
   `group_id NOT NULL`; no variant-key CHECK (it would break the nightly
   sync). Needs the `30th-c-007` cleanup first, with a yes. The spec settles
   which CHECKs belong here and which in Phase 8, so the currency CHECK is
   written once.

## Phase 7 — USD only, part 1: stop using Cardmarket

`L · high` *(prod)* — Cardmarket's figure is EUR and often another print's.
Plan and red team: `.superpowers/sdd/2026-10-28-remove-cardmarket/`.

- Stop reading and writing it together (S2+S4, which also stops storing
  Cardmarket `raw`).
- Resolver staleness by `source_updated_at`.
- The remaining read paths (S2b, S2c).
- The rename (S3).

On 2026-10-28 about 344 prints and 17 cards would have been left with no
market price, so re-count first. Ends with a release; Phase 8 starts at least
7 days later.

## Phase 8 — USD only, part 2: purge and the last copy

`M · high` *(prod)* — the irreversible half of Phase 7, same plan.

- Purge the Cardmarket data (S5a) and add the CHECK (S5b), both irreversible,
  each with a yes.
- Last, the copy that still promises a euro price: `home.blade.php`,
  `terms.blade.php`, README.

## Phase 8a — How prices work

`S–M · med` — a public page on the binder system explaining the price
method, linked from the "Approx." notes: TCGplayer market in USD, each copy
at its own print, a print with no price says so, daily refresh, hand prices,
frozen sources. After Phase 8 so it is written once, for USD only. Copy
round first. Spec: `SPEC-B.md` (B2) in `.superpowers/sdd/2026-11-17-differentiator/`.

## Phase 9 — Wishlist / want-list

`L · med-high` — the feature collectors are most likely to ask for; nothing
exists yet.

## Phase 9a — Master set mode on the set page

`L · med` — a set counts one of each card today: owning any print completes
the number. A switch to master set counts every print the catalogue lists
(normal, holo, reverse, Poké Ball and Master Ball reverses; stamped prints
and promos stay out), and the pockets, the printed strip, the % and the
missing cards' total follow it. The catalogue already knows each card's prints, and
TCGplayer prices about 94–100% of base prints. Open for the spec: per set or
per collection, an "any reverse counts" tier, owner default or visitor
toggle. Research: `RESEARCH-b.md` in `.superpowers/sdd/2026-11-17-differentiator/`.

## Phase 10 — Redesign the owner and staff pages

`L · med` — `/admin`, `/staff`, auth, legal and error pages still use
`app.css` and the old primitives (inventory:
`.superpowers/sdd/2026-11-05-admin-revamp/INVENTORY.md`). Deleting the retired
`--chrome-*` tokens needs a plan for the legal, auth and error pages too, not
only `/admin`.

## Phase 11 — Host: Redis, Postgres and PHP-FPM tuning, backups off the host

`L · med-high` *(prod)* — one batch of production-host changes.

- **Redis.** Give the cache its own instance (a cache wants eviction, a queue
  must never evict), then set `maxmemory`; consider one shared Redis for the
  host's projects instead of a second one-off here.
- **Postgres and PHP-FPM tuning.** `pg_stat_statements`, `track_io_timing` and
  `work_mem` in one restart; an FPM `max_requests`; `srcset` images.
- **Copy the backups off the host.** `M · med-high` — losing polaris2 loses
  the backups too. Cloudflare R2's free tier needs billing enabled on the
  Cloudflare account first.

## Phase 12 — Slimmer CI/CD

`M · low` — after a short design talk. A feature plus its release runs the
full suite four times (PR and push to `master`, twice). Constraints: branch
protection requires `audit`, `test` and `image`, so a workflow-level
`paths-ignore` would leave them pending and block the merge, and
`auto-release.yml` waits up to 15 min for `test` on the merge commit. Safe
levers: job-level conditions (skip `image` when nothing it builds changed), no
PR run for `release/*`, a warm npm cache for new branches, and a retry on the
`image` job's `composer install` (it failed once on GitHub 504s).

## Phases 13–16 — Collector features

`L each · low-med` — in this order, which is the likely order of demand; none
exists yet. Each is its own phase.

- **Phase 13 — Multiple collections.**
- **Phase 14 — Deck lists.**
- **Phase 15 — Trade and sale tracking.**
- **Phase 16 — Usage analytics.**

## Phase 17 — Larastan level 9

`L · low` — most of what level 9 adds is `TcgdexCardCatalogProvider` reading
tcgdex's JSON as `mixed`. The fix is a parsing layer that validates each field
before building `CardDetailData` / `PriceEntryData`, with its own tests; then
raise `phpstan.neon`.

## Phase 18 — Public beta prerequisites

`L · low until registration opens` — mandatory email verification (`User` does
not implement `MustVerifyEmail`, and invite signup marks the address
verified), signup abuse controls, uploaded-photo moderation, terms with real
teeth.

## Parked

Each waits on a trigger; none is a phase until it fires.

- **The Activity chart in the nightly window** — measure it between 00:00 and
  01:00 UTC with real traffic, and watch the "activity chart built on a page
  view" warnings. Build the precomputed per-print table only if one trigger
  fires: more than 3 page-view builds a week for two weeks, one over 10 s for
  a non-owner, ten or more public users, a warm run over 200 s, or a second
  use for per-print daily values.
- **Automatic links for stamped prints** (Prize Pack group 22880 by name and
  number) — postponed by the owner; Phase 3's command covers it by hand.
- **An admin link editor in the UI** — only if Phase 3's command proves too
  slow.
- **A purchase price per copy** — parked by the owner.
- **The real stamp as an image on a stamped print's tile** — the band carries
  the stamp's name today. An image would need a hand-cut asset per stamp (no
  feed publishes them), would cover only the most common few of 120+, and
  uses official logos; revisit if collectors ask for it.
- **Why tcgdex sends no TCGplayer price for about half its cards each night**
  — unanswered upstream.
