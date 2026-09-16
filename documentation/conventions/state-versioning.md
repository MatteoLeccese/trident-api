# Versionado de estado, orden y auto-curación

> Estado: **decidido** 2026-09-10. Incluye un techo explícito: **no hay event sourcing**.

## El problema

Un móvil que escribe, uno o más televisores que miran, una red doméstica, y un socket que se cae sin
avisar. El estado tiene que converger **siempre**, y la recuperación no puede ser una rama de error
rara que sólo se ejecuta cuando algo va mal.

## Cada payload es un snapshot completo, no un delta

`games.version` se incrementa **dentro de la misma transacción** que cada mutación, y todo payload la
lleva. La regla del cliente vive en una **función pura**,
`src/domains/game/utils/applyGameState.ts`, testeable sin Echo, sin React y sin navegador:

| Condición | Acción |
|---|---|
| `incoming.version <= current.version` | **descartar** |
| `incoming.version === current.version + 1` | **aplicar** |
| `incoming.version > current.version + 1` | aplicar **y marcar hueco** → resincronizar |

Como el payload es completo y no incremental, **un evento perdido se auto-cura**, y la incorporación
tardía, la reconexión y el televisor dormido son todos el mismo caso trivial: un
`GET /api/v1/games/{id}` por el mismo guard de versión.

> Consecuencia buscada: **la ruta de recuperación se ejercita en cada apertura de página**, no en una
> rama que nadie prueba.

## Las escrituras llevan `expected_version`

Un desajuste devuelve `422 game_version_conflict` **con el estado actual en `data`**, así que un móvil
rancio se auto-cura en vez de aplicar su toque a un estado más nuevo en silencio.

## Idempotencia y reintento van juntos, o no sirven

- Toda mutación lleva `X-Request-Id`, con índice **único** en `game_moves.request_id`. Una repetición
  devuelve el snapshot existente en vez de avanzar el turno dos veces.
- **Y el cliente reintenta con el mismo id** — dos veces, con backoff, mostrando estado pendiente en
  el control que ya deshabilitó.

Sin el reintento automático con el mismo id, la idempotencia protege al servidor de un problema que
**la UI está generando activamente**: toca, no pasa nada visible, toca, toca.

## Disparadores de resincronización

En `useGameChannel.ts`: `channel.subscribed`; el `state_change` de Echo hacia `connected`;
`document.visibilitychange → visible` (el móvil estaba bloqueado y pasó a Ana; el televisor
despertó); y un hueco de versión.

## El poll de reconciliación — la decisión operativa más valiosa

- **Televisor: 30 s, incondicional, siempre.** Un Reverb muerto, un rebind de NAT, un socket que
  pusher-js sigue reportando como conectado mientras traga frames, o un deploy que reinicia el
  contenedor de WS — **todo degrada a treinta segundos de retraso en vez de a una pantalla
  congelada.** Todo esquema de resincronización-por-evento exige que el cliente *sepa* que pasó algo.
  Éste no.
- **Móvil: 3 s, sólo mientras el socket no está conectado.** Es el punto único de fallo del juego y su
  batería es la restricción; cada escritura ya devuelve un snapshot fresco y cada desbloqueo resincroniza.

## La salud del socket no es la salud de la escritura

Las mutaciones del móvil van por **HTTPS a Octane**; las actualizaciones del televisor por
**WebSocket a Reverb**. Fallan de forma independiente y constante.

- En el móvil, `<ConnectionBadge />` lo gobierna **la última escritura exitosa**. Un toque fallido se
  pinta como un toque fallido, nunca como silencio.
- En el televisor, es estado de socket más tiempo desde el último snapshot.

## Una proyección, dos rutas de entrega — y un test de que coinciden

`GameSnapshot` es la **única** forma de estado del sistema: lo que devuelve
`GET /api/v1/games/{gameId}`, lo que envía `broadcastWith()`, y lo que el frontend refleja como tipo.

> `SnapshotShapeTest` asegura que el `data` del endpoint REST y `broadcastWith()` son **idénticos byte
> a byte.**

Esto no es ceremonia: el `GameController::show` viejo descartaba en silencio los `dominoes` y el
`game_phase` que su propio servicio devolvía. Ésa es exactamente esta divergencia, sin el test que la
habría cazado.

`seats` es un **array de objetos con un `seat` explícito** — nunca un mapa posicional, nunca filas de
BD crudas.

## Techo declarado: no hay event sourcing

Un agregado, un repositorio. **Sin read store, sin upcasting, sin tabla de snapshots, sin bus de
eventos de dominio.**

El beneficio que se propuso — re-jugar una partida sandbox contra las reglas reales y diferenciar el
flujo de efectos — **no se cobrará nunca**, porque el autor confirmará sus reglas gritando en una
mesa, no leyendo un diff.

## El tiempo se inyecta

`Src\Shared\Domain\Service\Clock` con `SystemClock` y `FrozenClock`, inyectado en el agregado y en
cada handler. La expiración es una transición de estado gobernada por una regla —*"una partida viva no
debe expirar a mitad de juego"*— y sin reloj inyectable eso se testea con `sleep()` o no se testea.

**Todo defecto de TTL del sistema viejo era un defecto de tiempo.**
