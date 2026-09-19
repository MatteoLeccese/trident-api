#!/bin/sh
# Takes a dump of the game database on an interval, and keeps the last few.
#
# A loop and not cron: the schedule is visible in `docker compose logs pg-backup`
# without anybody having to remember where a crontab lives, and a container whose
# only job is one command should not need a second process to start it.
#
# The dump is a custom-format archive (`-Fc`), not plain SQL. It restores with
# `pg_restore`, it can restore one table out of the whole file, and it is
# compressed on the way out.
set -eu

EVERY="${TRIDENT_BACKUP_EVERY_SECONDS:-21600}"
KEEP="${TRIDENT_BACKUP_KEEP:-8}"
DIRECTORY="/backups"

mkdir -p "$DIRECTORY"

echo "→ backing up ${PGDATABASE} every ${EVERY}s, keeping ${KEEP}"

while true; do
  STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
  TARGET="${DIRECTORY}/trident-${STAMP}.dump"

  # Written to a partial name and renamed, so a backup interrupted halfway
  # through never sits in the directory looking like a good one.
  if pg_dump -Fc -f "${TARGET}.partial"; then
    mv "${TARGET}.partial" "$TARGET"
    echo "✓ ${TARGET} ($(du -h "$TARGET" | cut -f1))"
  else
    echo "✗ dump failed at ${STAMP}" >&2
    rm -f "${TARGET}.partial"
  fi

  # Prune only whole backups, and only after a new one has landed: a directory
  # that is emptied before the replacement exists has a window with nothing in it.
  ls -1t "${DIRECTORY}"/trident-*.dump 2>/dev/null | tail -n "+$((KEEP + 1))" | while read -r old; do
    echo "· pruning $(basename "$old")"
    rm -f "$old"
  done

  sleep "$EVERY"
done
