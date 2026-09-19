# Backups, and the restore that has actually been rehearsed

> A backup nobody has ever restored is a file. The procedure below was run
> end to end against the local stack on 2026-09-18, and what it produced is
> recorded at the bottom.

## What takes them

The `pg-backup` service runs `pg_dump` on an interval and keeps the last few.
It is the same `postgres:17-alpine` image the database uses, so the dump and the
server can never be different versions of each other.

```
TRIDENT_BACKUP_EVERY_SECONDS=21600   # six hours
TRIDENT_BACKUP_KEEP=8                # two days of history
```

Three things about it are deliberate:

- **The dumps live in their own volume**, `postgres-backups`, and never inside
  `postgres-data`. A backup that lives inside the thing it is backing up is not a
  backup.
- **Custom format** (`-Fc`), not plain SQL: it is compressed, it restores with
  `pg_restore`, and one table can be pulled out of it without the rest.
- **Written to `.partial` and renamed.** A dump interrupted halfway through never
  sits in the directory looking like a good one, and the prune only ever removes
  whole files, only after a new one has landed.

Two days of history is on purpose and not an oversight: a game abandons itself
after three hours, so there is nothing here worth more than a couple of days.

## Looking at them

```bash
docker compose logs pg-backup
docker compose exec pg-backup ls -lh /backups
```

## Copying one off this machine

The volume lives on this machine, so a machine that dies takes both the database
and its backups. If an evening is worth keeping, copy it somewhere else:

```bash
docker compose cp pg-backup:/backups/trident-20260918T210159Z.dump ./
```

## Taking one right now

```bash
docker compose exec pg-backup sh -c 'pg_dump -Fc -f /backups/trident-manual.dump'
```

## Restoring

**Always restore into a new database first and compare it.** Restoring straight
over the live one turns a bad backup into a lost database, and you find out in
that order.

```bash
# 1. The newest dump.
DUMP=$(docker compose exec -T pg-backup sh -c 'ls -1t /backups/trident-*.dump | head -1' | tr -d '\r')

# 2. Restore it beside the real one, never over it.
docker compose exec -T pg-backup sh -c "createdb trident_restore_drill && pg_restore -d trident_restore_drill '$DUMP'"

# 3. Count both and compare. If these two lines differ, the dump is not good and
#    the live database has not been touched.
for DB in trident_restore_drill trident; do
  docker compose exec -T pg-backup psql -d "$DB" -t -A -F'|' -c "
    select count(*) filter (where rule_set_id is null),
           count(*) filter (where rule_set_id is not null),
           count(*) filter (where rule_set_id is not null and status='finished'),
           (select count(*) from game_seats),
           (select count(*) from game_moves)
    from games;"
done
```

Only once those agree, and only if you actually mean to go back:

```bash
docker compose stop api reverb scheduler          # nothing writes during a restore
docker compose exec -T pg-backup sh -c "dropdb trident && createdb trident && pg_restore -d trident '$DUMP'"
docker compose start api reverb scheduler
docker compose exec pg-backup dropdb trident_restore_drill
```

`api`, `reverb` and `scheduler` go down first because all three write: a sweep
landing in the middle of a restore would abandon games in a database that is half
replaced.

## The rehearsal

Run on 2026-09-18 against the local stack, on a database holding real history
from the development sessions — not on an empty one, which would have proved
nothing.

| | Live | Restored |
|---|---:|---:|
| Lobbies nobody started | 20 | 20 |
| Games that began | 125 | 125 |
| Games that finished | 14 | 14 |
| Games still on a table | 111 | 111 |
| Seat rows | 498 | 498 |
| Move rows | 1707 | 1707 |

The dump was 153 KB and the restore took under a second. The drill database was
dropped afterwards and the live one was never touched.

## What is not covered

- **Off-machine copies.** There is no second location and no schedule for one.
  The command to copy a dump off is above; running it is a decision.
- **Point-in-time recovery.** No WAL archiving. The most that can be lost is one
  interval, which is what "six hours" means.
- **Encryption at rest.** The dumps carry nicknames and nothing else: no
  password, no email address, and the controller token is stored only as a hash.
