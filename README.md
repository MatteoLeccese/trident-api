# Trident — API

REST API for Trident, a domino party game played with one phone passed around a
table and a television watching live.

Laravel 13 · PHP 8.4 · DDD + CQRS · PostgreSQL · Redis · Reverb (WebSockets)

The architecture, the decisions and their reasons live in
[`documentation/conventions/`](documentation/conventions/). Read
[`credential-model.md`](documentation/conventions/credential-model.md) first — it
explains why there are two credentials and why the spectator one can never write.

---

## Running it

This repository owns the shared Docker network and the data services. Start it
before the web app.

```bash
cp .env.example.local .env       # first time only
php artisan key:generate         # first time only

docker compose up -d --build
```

That brings up six things: PostgreSQL 17, two Redis instances (durable and
cache), the API on FrankenPHP/Octane, and Reverb. Migrations run automatically on
start.

```bash
curl -s localhost:8000/api/v1/health
# {"status":200,"message":"OK","error":null,"data":{"status":"ok"}}
```

Then start the web app — see [`../trident-web/README.md`](../trident-web/README.md).

### Without Docker

Possible, but you need `php8.4-pgsql` and `php8.4-sqlite3` installed, plus a
PostgreSQL and a Redis of your own. The container exists so you do not have to.

---

## Tests

```bash
php artisan test                                  # on your machine
docker build --target test -t trident-api:test .  # or inside an image
docker run --rm trident-api:test                  # identical to production
```

The suite runs on in-memory SQLite and declares everything it needs in
`phpunit.xml`, so it does not depend on your local `.env` and behaves the same on
a laptop, in CI and in the container.

Run a subset with `docker run --rm trident-api:test test --filter=ArchitectureTest`.

---

## One image, many roles

The API, the WebSocket server, the queue worker and the scheduler are all the
same build. The role arrives as the container's command, which makes "it works in
one container but not the other" impossible.

```bash
docker compose run --rm api cli php artisan tinker
docker compose exec api php artisan trident:smoke <GAME_ID>
```

`trident:smoke` broadcasts a game's current state on demand. It exists because a
television has no developer console: if the screen does not move after running
it, the problem is the socket, not the state.

---

## Layout

```
app/          A thin Laravel shim: providers and the base controller. Nothing else.
src/          All business logic, in bounded contexts.
  Game/       The aggregate, the rules seam, persistence, HTTP.
  Realtime/   Broadcasting, channel participants, the state publisher.
  Shared/     Buses, ApiResponse, exceptions, value objects, Clock.
```

Dependencies point inward: Infrastructure → Application → Domain. The domain is
plain PHP with no framework at all, and `tests/Unit/ArchitectureTest.php` fails
the build if that stops being true.

---

## Conventions worth knowing before you change anything

- **The response envelope is one shape**: `{status, message, error, data}`, with
  `meta` only when there is pagination. Clients branch on the snake_case `error`
  code, never on the message text.
- **An exception message never crosses the wire.** A 500 returns a reference; the
  detail is in the log under that reference.
- **PostgreSQL is the only source of truth.** Redis holds locks, throttle counters
  and the broadcast broker — never game state.
- **Nobody writes over the socket.** Every mutation is an HTTPS POST authenticated
  by the controller token. The WebSocket is a one-way read feed.
