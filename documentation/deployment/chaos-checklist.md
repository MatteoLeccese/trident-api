# Chaos checklist — what happens when something breaks mid-party

> This is a **procedure, not a report**. It is run by hand against the local
> stack, and every expected result below is what the shipped code does, traced to
> the file that does it. If a run disagrees with this document, the document is
> the thing that is wrong until somebody proves otherwise.

The product has exactly one phone that writes and any number of televisions that
read. Every scenario here is one of those two paths failing while the other keeps
working, because that is the shape of every real failure a living room produces:
nothing goes down cleanly, something gets slow or lies.

## Before you start

```bash
cd trident-api  && docker compose up -d
cd trident-web  && docker compose up -d
```

Open the phone at `http://<LAN-IP>:3000` and a television at
`http://<LAN-IP>:3000/tv` **in a separate cookie jar** — another browser, another
profile or a private window. Sharing a jar gives the television the controller
cookie, and then it is not a television.

Start a game with three names and draw a few tiles, so there is something on
screen to watch surviving.

Useful while you work:

```bash
docker compose logs -f api reverb scheduler
docker compose exec api php artisan trident:smoke <GAME_ID>
docker compose exec api php artisan trident:tally
```

---

## 1. Reverb killed for sixty seconds

```bash
docker compose stop reverb
# wait 60s, watching both screens
docker compose start reverb
```

**What should happen**

| Screen | Within | What you should see |
|---|---|---|
| Television | ~30 s | The board keeps up. It has no socket, and its unconditional 30 s poll is carrying it (`useReconcile`, `tv/[gameId]/page.tsx`). |
| Television | at once | The badge goes to `Offline`. Trouble is never delayed. |
| Phone | ~3 s | Draws still work, and the board still updates: its poll switches **on** when the socket goes down. |
| Phone | at once | The badge goes to `Offline`; writes keep landing, so it never says `Not saving`. |
| Both | ≤10 s after restart | `Live` comes back — **not instantly**. A reconnection has to hold for ten seconds before either screen believes it (`useSettledStatus`). |

**Why the delay is correct and not a bug.** A socket on a bad network does not
fail, it flaps. A badge that believed every momentary `connected` would alternate
between `Live` and `Offline` across the room, and — worse — each of those seconds
would switch off the phone's rescue poll, which is its only recovery exactly when
the network is at its worst.

**What must NOT happen:** a write hanging. `GameStatePublisher` swallows its own
errors, and the Reverb client carries a connect timeout of 1 s and a total
timeout of 2 s (`config/broadcasting.php`). Without those, a Reverb that drops
packets rather than refusing connections would hold an Octane worker inside the
write and one sick socket server would stop the game instead of stopping the big
screen.

---

## 2. The phone in flight mode for sixty seconds

Turn the phone's wifi off with a confirmation sheet open, wait, turn it back on.

**What should happen**

- The tap that was in flight retries twice — 200 ms then 600 ms — **with the same
  `X-Request-Id`** (`writeProtocol.ts`). A retry that landed the first time is
  answered from `game_moves.request_id` with the first attempt's bytes, so the
  turn advances once and never twice.
- Past the retries the badge reads **`Not saving`** in red, which is the one
  state the old badge could not express: the socket may well be reporting itself
  connected, and the game has stopped.
- The board does not move on its own and no tile is lost.
- On reconnection the poll and the `online` listener both fire; the phone
  converges on whatever the table did meanwhile.

**The case to try deliberately:** turn wifi off, tap a tile, wait for the retries
to give up, turn wifi on, tap **the same tile** again. The second tap is a new
intention with a new id, so it is a real draw — the position is already taken, and
the server answers `422 pool_position_taken` rather than turning a second tile
over.

---

## 3. The television unplugged for five minutes

Pull the television's power (or close the tab) for five minutes, then bring it
back on the same link.

**What should happen**

- It loads the current state through the same `GET` a first load uses, so it
  lands on the board as it is now, mid-game, with no replay of what it missed.
- It does **not** show the idle notice. `last_activity_at` slides on every write,
  and the table has been playing.
- Its socket re-subscribes and the next draw arrives on it.

**Then try the other half:** stop drawing for longer than
`TRIDENT_TV_IDLE_NOTICE_MINUTES` (30 by default) and the television says the game
may have been left behind. That number is enforced to be lower than
`TRIDENT_IDLE_TIMEOUT_MINUTES` by `tests/Unit/Shared/TridentConfigTest.php`, so
the notice can never tell a room to go home for a game the server still keeps.

---

## 4. A double tap on a slow connection

Throttle the phone to 3G in devtools, then tap a tile and confirm twice as fast as
you can.

**What should happen**

- One tile turns over. Three guards stack: the confirm sheet is disabled while a
  write is in flight, `useWrite` refuses a second call through a ref rather than
  through its pending flag — two activations in one React batch both read the same
  `false` — and, if anything did get through, the server's unique index on
  `game_moves.request_id` answers the repeat with the first attempt's bytes.
- The turn advances exactly once, and the television sees one version.

**Also worth trying:** tap "Save" on a seat rename with the Enter key held down.
The key is gated on the pending state, like the button beside it.

---

## 5. A deploy in the middle of a game

```bash
cd trident-api && docker compose up -d --build api reverb
```

**What should happen**

- Postgres keeps its volume, so the game is exactly where it was.
- Every socket drops and re-subscribes; both screens recover through the poll
  first and the socket second.
- No write is lost: anything in flight either committed before the container went
  away or is retried against the new one with the same id.

**What you need to know before doing this on purpose.** Every `NEXT_PUBLIC_*`
value is inlined into the frontend bundle at **build** time, not read at run time.
Changing one means rebuilding the web image — setting it under `environment:`
changes nothing and reads as though it did.

---

## 6. The scheduler, and the games nobody came back to

Leave a game and let the sweep find it:

```bash
docker compose exec api php artisan trident:expire-games --minutes=1
```

**What should happen**

- The game becomes `abandoned` with `FinishReason::IDLE_TIMEOUT`.
- **A television with a live socket is told**, in the same frame, and stops
  showing somebody's turn. This is the whole reason the sweep publishes.
- `trident:tally` counts it under "left behind" and not under "closed for a
  rematch" — the two are told apart on purpose, or a room that played all night
  would read exactly like a room that walked out after one game.
- Both screens then stop polling and close their sockets: a terminal snapshot is
  the last one there will ever be.

To watch the scheduled path instead of the manual one, set
`TRIDENT_IDLE_TIMEOUT_MINUTES=1`, restart the stack and wait five minutes with
`docker compose logs -f scheduler` open.

---

## What this checklist deliberately does not cover

- **Reverb failing over to a second instance.** There is one, on a LAN.
- **A database failover.** One Postgres, one volume, one house.
- **Load.** Fifteen people is the product's maximum, and the rate limit is keyed
  per game precisely so that one table cannot silence another.
- **A restore drill.** It belongs with the backup sidecar, which is not built.
