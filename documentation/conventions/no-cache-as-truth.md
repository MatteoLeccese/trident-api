# La caché no es la fuente de verdad. Nunca.

> Estado: **decidido** 2026-09-10. Es el arreglo del hallazgo de cabecera de la auditoría.

## La regla

**PostgreSQL es la única fuente de verdad.**
**Redis guarda locks, contadores de throttle y el broker de broadcast. Nada más, nunca.**

## Qué pasó antes, para que no vuelva a pasar

En el sistema viejo el estado completo de la partida vivía en una única clave de caché
`game:{uuid}` con un **TTL absoluto de una hora que nunca se refrescaba al leer**. Las filas de la
base de datos eran de **sólo escritura**: `games.game_phase` se quedaba en `0` para siempre, las
fichas no tenían representación en BD, y ningún camino de lectura consultaba jamás ninguna de las
dos tablas.

Consecuencias reales, todas confirmadas en la auditoría:

- Una partida que durase 61 minutos se destruía sin recuperación posible.
- Un `cache:clear` o un deploy hacían lo mismo.
- La BD y la caché divergían desde el primer instante y nada lo detectaba.
- El mensaje que el jugador veía era *"o la partida expiró, haz otra"*.

## Cómo se enforza ahora

- **La fila `games` es autoritativa.** `game_moves` es un log de sólo-anexar que sirve el contador de
  versión, el historial del televisor, el libro de idempotencia y el rastro de auditoría — pero
  **no es autoritativo**. Dos almacenes autoritativos sobre los mismos hechos reconciliados por
  convención es exactamente el hallazgo viejo con mejor ropa.
- `ReplayMatchesStateTest` asegura que **reproducir los movimientos de una partida reproduce su fila
  persistida**, así que la divergencia no puede pasar desapercibida como pasó con BD-contra-caché.
- **La expiración es una columna, no un TTL de clave:** `last_activity_at` **deslizante**, refrescado
  en cada escritura. No hay columna `expires_at` y no hace falta: el corte se calcula al barrer, así
  que cambiar `idle_timeout_minutes` no deja atrás un montón de filas con un vencimiento viejo
  grabado. `trident:expire-games` la gobierna, corre cada cinco minutos en el contenedor `scheduler`,
  cierra cada partida bajo su propio lock con `FinishReason::IDLE_TIMEOUT` y **emite un snapshot
  final** — sin él, un televisor con el socket vivo se queda anunciando el turno de alguien toda la
  noche. Jamás un reloj absoluto.
- `GameStateChanged` recibe un `GameSnapshot` **como argumento de constructor** y es
  **estructuralmente incapaz de leer almacenamiento**. El evento viejo leía la caché en su propio
  constructor, que es como acabó emitiendo `[]`.
- `Cache::lock("game:{$id}", 5)` está permitido — es un lock, no un estado.

## El control de concurrencia no es un lock

Es el índice **`unique(game_id, seq)`** de `game_moves`. Dos escrituras concurrentes con la misma
versión esperada — el doble toque sobre un móvil que se pasa de mano — no pueden confirmar ambas: el
perdedor recibe `game_version_conflict`.

Una restricción de base de datos **aguanta cuando Redis no está disponible**. Un lock más una
convención, no. `SELECT … FOR UPDATE` dentro de `DB::transaction` y el `Cache::lock` corto van
encima como tirantes y cinturón, no como el mecanismo.

## Si alguna vez te tienta

Son las once de la noche, hay una consulta lenta y meter el estado en Redis parece obvio. No. Vuelve
a leer la lista de consecuencias de arriba: todas salieron de exactamente esa decisión.
