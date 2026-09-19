# Golden rules waived on purpose

> Decided 2026-09-10. **Swept 2026-09-18**, against the code rather than against
> memory: three of these had stopped being true and are corrected below. Revisit
> only if this stops being a party game with no login.

The team's guidelines were distilled from a multi-tenant administrative panel
with money and user accounts in it. Trident has none of that. These rules are
waived **knowingly**, not by oversight, and the reason is written down here so a
later audit reads a decision rather than a gap.

A waiver that has quietly stopped applying is worse than one that was never
written: it says somebody looked. That is what the sweep is for, and the three it
caught are marked.

| # | Rule waived | Why, and what replaces it |
|---|---|---|
| 1 | **Full multi-tenancy** | There are no tenants; a game is the only unit of isolation. Its four-layer defence is **transposed** onto the controller token: middleware resolves it → the typed Command carries the `gameId` explicitly → `controller_token_hash NOT NULL` → the mutation sweeper (`GameEndpointsTest::test_every_mutating_game_route_demands_the_controller_token`). **No static context class replaces it** — the role travels in the Command, which is what rule 3 of the guideline itself prescribes, and it avoids recreating a cross-request contamination risk under Octane. |
| 2 | **Sanctum, user accounts, `/api/v1/auth/*`** | The premise is a phone with no login passed around a table; a login screen would be a product defect. Replaced by two capability credentials per game (see `credential-model.md`). `app/Models/User.php` and `config/sanctum.php` are **deleted**, not left dead. |
| 3 | **Golden rule 9 — money as integer cents** | There is no money and no currency: **nothing counts anything** — no drinks, no points, no scoreboard — and no `Effect` carries a quantity (TR-54). The rule's real intent, that such a value is never a float, is honoured by there being no such value. The `Effect` vocabulary must not acquire an accumulable integer that would put it back in play. |
| 4 | **`PASSWORD_SALT` and client-side pre-hashing** | There are no passwords. The contract is defined in no `.env`. |
| 5 | **The BFF's `/api/auth/{login,logout,me}`** | Replaced, not removed. The cornerstone of the BFF is honoured whole: the write credential lives only in an httpOnly cookie and is stripped from every response to the client, including the WebSocket auth route. The routes are `/api/session` and `/api/games`. |
| 6 | **`audit_logs` + `AuditLogger`** | Superseded by `game_moves`, which is at once the audit trail, the idempotency ledger, the version counter and the television's history feed. |
| 7 | **Soft deletes** *(corrected 2026-09-18)* | Games are ephemeral: `trident:expire-games` ends the ones a table walked away from, and the row then says so — `abandoned`, with `idle_timeout` as its reason. **Nothing is pruned.** The waiver used to claim games were "expired and pruned"; the expiry is now real and the pruning never existed. Soft deletes are still not wanted, and for a better reason than before: a game that ended is a record of an evening, and `game_moves` is append-only precisely so that it stays one. |
| 8 | **Repository posture** *(corrected 2026-09-18 — resolved, not waived)* | Real repositories, and **two ports rather than one**. `GameRepository` loads and saves the one aggregate. `GameTallyReader` answers the single question asked of every game at once — how many began against how many finished — and is deliberately not a method on the first: a count over every row that ever existed is not an aggregate operation, and a repository that answers reports stops being the place one game is read from. `backend.md` warns against half-mixing; with one aggregate and a pure domain, full adoption is one file. |
| 9 | **Domain events / `AggregateRoot`** | Not adopted. The guidelines call them optional and prefer Observers plus jobs; this project needs neither. |
| 10 | **Pest** | Not adopted. A skill recommends it; the guidelines specify PHPUnit and, by their own README, **the guidelines win**. |
| 11 | **A staging environment and a Postman collection** | Neither exists. This runs locally on the host's machine, so there is no second audience to stage for. |
| 12 | **A Server Component for the first paint of `/tv/[gameId]`** | ~~Deliberate divergence: a television's first paint should be the game, not a spinner.~~ **This never happened, and the sweep is what found it.** The page is `"use client"` from its first line and paints "Loading…" until its first snapshot arrives. It is left that way on purpose rather than quietly fixed: the whole screen is driven by a socket and a poll that only exist in a browser, and a server-rendered first paint would show a state that is stale by the time it arrives — while splitting the page in two to gain one frame. **The waiver is therefore withdrawn and the guideline is simply followed.** What the original concern was actually about — a television sitting on a spinner — is answered by the reconcile poll and by the socket, not by where the first frame is rendered. |
| 13 | **"The unguessable link is the credential"**, weakened | A 6-character `JoinCode` gives 32⁶ ≈ 10⁹, and its brute-force surface, `GET /api/v1/games/by-code/{code}`, is limited to 20/min; the code is released when the game ends. Accepted: it grants **read-only** sight of a drinking game's deck, and typing a UUID with a television remote is not a product. |

## Product deviations (not golden rules, recorded anyway)

- **Nickname of 4–40 → 2–24 characters.** It is the most specified rule that
  survived the old system, and it changes anyway: *"Bo"* and *"Al"* are real
  names that a minimum of 4 rejects, and fifteen nicknames of forty characters is
  a television layout nobody has solved. The 2–24 range is the author's (TR-15),
  and it lives in `Nickname::MIN_LENGTH` / `MAX_LENGTH` and in
  `config/trident.php`.

- **No CI.** Not a guideline waiver but a standing decision of the owner
  (2026-09-18): there is no pipeline now and there will not be one at the end.
  The checks exist and they bind — Pint, PHPUnit on both engines, ESLint, `tsc`,
  Vitest, Playwright and the structural guards — and they run locally through
  `bin/check` before a session closes. There is no `.github/` in either
  repository.

## Explicitly NOT waived

Golden rules **1, 2, 3, 4, 5, 6, 7, 8, 10, 11, 12**; the `/api/v1` prefix; the
`ApiResponse` envelope with snake_case machine codes; centralised exception
handling in `bootstrap/app.php`; `config/trident.php` as the single home for
constants; the "enums as a `final class` of constants" convention; the two PSR-4
roots with a thin `App\`; the CQRS buses wired in `DomainServiceProvider`; Redis
split durable/cache; Pint + ESLint; PHPUnit on SQLite in memory.
