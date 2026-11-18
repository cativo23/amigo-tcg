# Roadmap

Everything still to do, grouped into phases. A phase holds related work; its
parts are done in order, and one part is one focused block of work (usually
one PR). Phases run in the order below: the first ones fix or protect data,
the later ones add features. What shipped lives in
[`CHANGELOG.md`](CHANGELOG.md), not here.

- **Effort:** `S` under half a day with tests · `M` one to two days · `L`
  more · `XL` weeks of calendar.
- **Value:** `high` breaks something, loses data or misprices cards · `med` a
  real improvement · `low` polish.
- 🚀 marks a release point (see "Releases" below). ✋ marks a step that writes
  to production or goes outside the repo: it needs the owner's yes, with the
  exact command, every time.
- Each phase names its spec. Specs are local and git-ignored, in
  `.superpowers/roadmap/specs/`, and keep the numbers they were written
  under (`phase-07-…` is Phase D's spec). Re-check a spec against the code
  before acting on it.

## Releases

A release is the only way code reaches production: publishing it deploys it
(`deploy/README.md`, "Cutting a release"). So "when to deploy" and "when to
release" are the same question.

**When.** At each 🚀 in this file and nowhere else. By default that is the
end of a phase. A phase gets a 🚀 mid-way only when something forces it: a
migration that must be live before the next part, or a wait measured in
nightly runs. Parts merge to `master` as they finish and wait there, listed
under `[Unreleased]`.

**Exceptions.**
- A production data-integrity bug ships alone, right away, as a patch.
- Never two releases that change the host (compose, `.env`, Postgres,
  Redis) on the same day, and never one of those together with a migration.
- No release inside the nightly windows (00:00–01:00, 03:00–03:15,
  04:00–04:30, 20:30–21:00, 21:45 UTC).

**Which number** (`MAJOR.MINOR.PATCH`):
- **Patch** (`1.9.0 → 1.9.1`): only fixes, performance, operations or
  internal code. A visitor or member sees nothing new.
- **Minor** (`1.9.0 → 1.10.0`): anything a visitor or member can see or use
  that is new or changed: a page, a filter, a command, a CSV column, a copy
  change, a changed figure.
- **Major** (`2.0.0`): once, when registration opens to the public (end of
  Phase I). Nothing else bumps the major.

Each 🚀 below names the bump it expects; the CHANGELOG at release time
decides. Every release needs the owner's yes. A release with a migration
fails its deploy on purpose until the migration is run by hand
(`deploy/README.md`, "A release with a migration").

---

## Phase B — Backups off the host, and host tuning

`M–L · high` · spec: `phase-11-host-redis-postgres-backups.md`. One part per
release, never two on the same day; each starts with a dated copy of the
compose file on the host.

1. **Backups off the host.** `M · high` — losing polaris2 loses the backups
   too. A second backup service ships the dumps and photos to Cloudflare R2
   (EU bucket, restic, client-side encryption), with a restore test and a
   freshness alert; the privacy policy changes in the same release. ✋ The
   owner enables Cloudflare billing and creates the bucket and token first;
   until then go on with the parts below. 🚀 **Minor** (privacy policy).
2. **`work_mem` from real numbers.** `S` — v1.9.2 turned on
   `pg_stat_statements` and temp-file logging on 2026-11-18. From
   2026-11-25, read the top statements and the temp-file lines
   (`deploy/README.md`, "Slow queries") and decide a per-query
   `SET LOCAL work_mem` or a global raise. 🚀 **Patch** if it changes the
   compose.
3. **Redis.** `S` — `maxmemory 96mb` with `volatile-lru` on the shared
   instance (a dedicated cache instance stays the fallback), plus a memory
   and eviction alert. 🚀 **Patch**.
4. **Sessions only where they are needed.** `S` — about 1,600 guest
   sessions a day; start a session only on login, forms and Livewire
   routes. 🚀 **Patch**.
5. **Compose tidy-up.** `S` — recreate `backup` on deploy instead of
   restarting it; postgres/redis health checks every 30 s with a
   `start_interval`; re-measure CPU throttling on scheduler, horizon and
   backup before changing any limit; pin `postgres` and `redis` by digest
   so they change only in a planned release (the v1.8.0 deploy's
   `compose pull` took new `postgres:17-alpine` and `redis:7-alpine` builds
   and recreated both inside the 04:00 window, with a few seconds of failed
   redis lookups). 🚀 **Patch** (can ride with part 4).

## Phase C — TCGplayer links and price-link integrity

`M · med-high` · specs: `phase-03-tcgplayer-links-and-csv-source.md`,
`phase-06-price-link-integrity.md`; plan
`.superpowers/roadmap/refs/price-link-redesign/FINAL.md`

1. **`catalog:link-tcgplayer`.** `S–M` — link a print to a TCGplayer product
   from the command line instead of tinker (dry run by default,
   `--apply`), plus a product listing by printed number. Covers the
   deck-exclusive prints with no TCGplayer id and every stamped print a
   collector adds. ✋ After release: confirm the 10 `gaps_unverified` prints
   (holo and reverse of `sv03-062`, `-072`, `-085` and `swsh12-131`, plus the
   stamped `me02.5-155` and `me03-086`) one link at a time; link the 12 `mep`
   cards with no link (`mep-055`..`mep-063`, `mep-091`, `mep-120`,
   `mep-Museum`), then `catalog:backfill-images` for their images.
2. **The CSV says where each price came from.** `S · low` — a
   `price_source` column at the end of the export. The 10 hand-entered rows
   stay. 🚀 **Minor** (parts 1–2).
3. **The identity matcher writes links.** `M` — it has agreed with every
   existing link in log mode since v1.6.12. Cross-card guard, trust level,
   `tcgcsv-identity` method with its evidence (additive migration). 🚀
   **Minor**. Then two full nightly runs with the switch still on `log`, and
   ✋ switch `TCGCSV_IDENTITY_LINKS=on` (`.env` + recreate).
4. **Integrity constraints.** `S–M` — CHECKs on link `method` and snapshot
   `origin`, `UNIQUE(product_id, sub_type)`, `group_id NOT NULL`, and
   `UNIQUE(set_id, local_id)` on `cards` (a number imported twice would
   count twice in the strip and the missing total; count duplicates in
   production first, read-only). ✋ First: a backup and
   the `30th-c-007` cleanup (irreversible). Look at the other links on a
   variant the card's `variants` marks false (`mep-002`, `mep-004`,
   `mep-092`, `mep-093`, all `normal`) in the same pass. 🚀 **Patch**
   (migration). A rollback goes to part 3's tag, never older.

The 86 `30th` cards linked `normal` on a Holofoil-only product are the
known limit in `deploy/README.md` ("Stamped prints"), not a wrong price:
tcgdex's row of a day equals tcgcsv's row of the evening before on all 86.
They are 86 of the 87 nightly `link_conflicts` (the other is `mep-028`).
Nothing to fix unless a part here changes how a link's variant is keyed.

While part 3 waits on the nightly runs, take Phase D's first part.

## Phase D — USD only

`L · high` · specs: `phase-07-usd-only-stop-cardmarket.md`,
`phase-08-usd-only-purge.md`, `SPEC-B.md` (the B2 page); plan
`.superpowers/roadmap/refs/remove-cardmarket/`. Cardmarket's figure is EUR
and often another print's. Needs Phase C done.

1. **Read only TCGplayer and hand prices.** `M` — re-count the prints that
   will go blank first. One market-sources filter at every price read site,
   the parity oracle, staleness by the print's market date (anchored on the
   newest TCGplayer day). These two never ship apart.
2. **Stop storing Cardmarket.** `M` — the provider emits none, the sync
   skips non-USD entries; delete the dead card-wide code; stop reading
   `trend_minor`.
3. **The public copy, as a correction.** `S` — the landing's euro line,
   `terms.blade.php` (and its "Last updated"), the README. The owner
   approves every line. Parts 1–3 are one branch and one PR, never released
   in pieces. 🚀 **Minor**. About 331 prints (14 cards, none owned) go blank.
4. **How prices work.** `S–M · med` — a public page on the binder system
   explaining the method (TCGplayer market in USD, each copy at its own
   print, "No price", daily refresh, hand prices, frozen sources), linked
   from the "Approx." notes. Copy, then a mockup, then the build. 🚀
   **Minor**.
5. **The purge command.** `M` — `prices:purge-source` (dry run by default)
   and the test fixtures rewritten. May merge during the wait. 🚀 **Patch**.
   Then, at least 7 nights after part 3's release: ✋ a restore test, a dump
   of the table, ✋ the purge (about 445,000 rows, irreversible except from
   the dump), `VACUUM (ANALYZE)`.
6. **Constrain the data.** `S–M` — 7 days after the purge: `CHECK` on
   `source` and `currency`, drop `trend_minor`. Reversible with
   `migrate:rollback`. 🚀 **Patch** (migration). After it, no rollback to a
   tag older than part 3's.
7. **Remove the two-currency code.** `S` — `Valuation::order`, the secondary
   totals and notes, the mixed-currency paths in `CollectionItems`, the
   legacy resolver. Dead once part 6 is live. Rides the next release.

## Phase E — Code cleanup and tooling

`M · low` · specs: `phase-04-cleanup-and-chart-leftovers.md`,
`phase-12-slimmer-ci-cd.md`, `phase-17-larastan-level-9.md`

1. **Small hygiene.** `S` — `composer.json` to `php ^8.4`; delete the
   orphaned Breeze views (the `dashboard` route stays); dead CSS and tokens
   from `design.md`; the card-page strip with fewer than 3 tiles; check the
   card page at 390 px on a phone (the plate may overflow, "320 HP" outside
   the frame).
2. **One chart class.** `M` — `PriceChart::plot()` gets `maxStep` and
   `ValueChart` goes; shared slider and copy-link modules; arrow keys step
   from the value the slider announced.
3. **Chart and page limits.** `S–M` — a manual price's line held out to
   today (amend `design.md`); one shared 600-card cap in a stable order,
   with a visible "covers the first 600 of N" note; `srcset` images.
4. **Slimmer CI.** `S` — skip `test` and `image` on same-repo `release/*`
   PRs (a job-level skip, not a trigger filter), retry the image build,
   cancel stale runs only on PRs, a guard test for the workflow contract.
5. **Larastan level 9.** `M` — a typed parsing layer for tcgdex's JSON, then
   the other errors file by file. After Phase D (it removes a third of them).

🚀 **Minor** at the end (the cap note and the chart line are visible).

## Phase F — Collection features

`L · med-high` · specs: `phase-05-collection-card-type-filter.md`,
`phase-09-wishlist.md`; research for master set:
`.superpowers/roadmap/specs/RESEARCH-b.md`. Each UI part: copy first, then
a local mockup, demo data only.

1. **Filter by card type.** `M` — Pokémon (Basic, Stage 1, Stage 2), Trainer
   (Supporter, Item, Tool, Stadium), Energy (Basic, Special), on the public
   page and in `/admin`; kinds not owned are hidden. 🚀 **Minor**.
2. **Wishlist, the core.** `M` — a private list of wanted cards (card plus
   an optional print), added from the set page's missing pockets and by
   search, owner only; its own page with current prices. Open: the name
   ("Wishlist" or "Want list"), the tile link for a want's set. 🚀
   **Minor** (migration).
3. **Wishlist targets and sharing.** `M` — a target price per want (with a
   non-colour "below target" marker) and an optional public wishlist page.
   Open: where the public flag lives (decide with Phase H part 1). 🚀
   **Minor** (migration).
4. **Master set mode, the spec.** `S` — no spec yet. Settle: per set or per
   collection, an "any reverse counts" tier, owner default or visitor
   switch, how Poké Ball and Master Ball reverses count.
5. **Master set mode, the build.** `L` — every print the catalogue lists
   counts (stamped prints and promos stay out), and the pockets, strip, %
   and missing total follow it. 🚀 **Minor**.

## Phase G — Owner and staff pages on the design system

`XL · med` · spec: `phase-10-admin-staff-redesign.md`; inventory
`.superpowers/roadmap/refs/admin-revamp/INVENTORY.md`. `/admin`, `/staff`,
auth, legal and error pages still use `app.css`. Each part: copy round,
local mockup, then the build; parts merge on green as they finish.

1. A guard test, then the legal pages (the last users of `layouts.public`).
2. Login, the guest shell, the `x-ui.*` primitives and tokens (proves the
   token bridge; if it fails, parts 7–10 become one PR).
3. Sign-up and invite.
4. Password recovery.
5. Error pages. 🚀 **Minor** (parts 1–5).
6. The owner shell and the profile page.
7. Staff pages.
8. My collection and the card editor (one drawer).
9. The set grid.
10. My sets, the set picker and Add a card.
11. Import.
12. Cleanup: delete `--chrome-*`, `app.css`, Tailwind and the old layouts.
    🚀 **Minor** (parts 6–12).

## Phase H — More collector features

`L each · low-med` · specs: `phase-13-…`, `phase-14-…`, `phase-15-…`,
`phase-16-…`. Their decisions are provisional defaults; confirm them before
each part starts.

1. **Multiple collections, the model.** `M` — one service for the user's
   collections, a default flag (migration), create/rename/move/delete with a
   cap of 10. No UI. 🚀 **Patch** (migration).
2. **Multiple collections, the pages.** `L` — a switcher in `/admin`, a
   destination in Add and Import, public collection pages, a `collection`
   CSV column. After Phase G. 🚀 **Minor**.
3. **Deck lists, the rules.** `M` — the deck validator, deck tables
   (migration), owned vs needed per line.
4. **Deck lists, the pages.** `M–L` — the owner's deck pages, PTCGL text
   import and export (needs a real export sample first). 🚀 **Minor**.
5. **Sales and trades log.** `L` — "Sold or traded" next to Remove, an
   append-only log, its page and CSV; privacy copy. 🚀 **Minor**.
6. **Owner stats.** `S–M` — signups, cards added per day, public
   collections, from the existing tables. 🚀 **Minor**.
7. **Page-view counters.** `L` — first-party, cookieless; only near Phase I.
   Counters need a store that never evicts (Phase B's Redis evicts keys
   with a TTL). 🚀 **Minor**.

## Phase I — Public beta

`L · low until registration opens` · spec:
`phase-18-public-beta-prerequisites.md`. Schedule it when registration is
about to open; the terms review has the longest wait, so it starts first.

1. **Terms with real teeth.** `M` — a draft with recorded acceptance
   (`terms_accepted_at`, `terms_version`). Open: who reviews it (a lawyer,
   an adapted template, or the draft as is). ✋ Members get the new terms by
   email 30 days before they apply.
2. **Mandatory email verification.** `M` — the `verified` gate covers
   Livewire requests too; an email change unverifies today.
3. **Signup abuse controls.** `S–M` — per-IP limits, a honeypot, limits on
   forgot-password and resend.
4. **Photo moderation.** `M` — a report button, a staff queue, one-click
   removal (migration).
5. **Open registration.** ✋ The pre-flight checklist in the spec, then the
   switch in `/staff/settings`. 🚀 **Major: v2.0.0**.

---

## Parked

Each waits on a trigger; none is a part until it fires.

- **The Activity chart in the nightly window** — build the precomputed
  per-print table (design in `.superpowers/roadmap/refs/perf-audit/`) only
  if one fires: more than 3 page-view builds a week for two weeks, one over
  10 s for a non-owner, ten or more public users, a warm run over 200 s, or
  a second use for per-print daily values.
- **Cache the missing-cards total** next to the deciding rows — if set pages
  on ~300-card sets show its ~20 ms in production timings.
- **A per-group `LATERAL … LIMIT 1` for the deciding-rows query** — if the
  cold `/admin` render after the nightly sync (about 2 s) bothers.
- **The nightly sync refreshes `sets.card_count`** — if more sets keep
  growing after release (a stale count hid 26 cards once).
- **Rename the product-id signals** (the remove-cardmarket plan's S3) —
  after Phase D; nothing depends on it.
- **Wishlist alerts by email** — its own spec, after Phase F part 3.
- **Automatic links for stamped prints** (Prize Pack group 22880 by name
  and number) — postponed by the owner; `catalog:link-tcgplayer` covers it
  by hand.
- **An admin link editor in the UI** — only if `catalog:link-tcgplayer`
  proves too slow.
- **A purchase price per copy** — parked by the owner.
- **The real stamp as an image on a stamped print's tile** — needs a
  hand-cut asset per stamp, covers a few of 120+, uses official logos;
  revisit if collectors ask.
- **Why tcgdex sends no TCGplayer price for about half its cards each
  night** — unanswered upstream.
- **A collector's photo at real size** (`L`) — crop and straighten on
  upload so Actual size applies to it; revisit if collectors ask.
- **A photo of a real copy on the demo account** — waits for a photo given
  with permission (a web photo needs its licence checked); seeding it is a
  production write (`demo:seed-gallery --photo`, stripping EXIF).
