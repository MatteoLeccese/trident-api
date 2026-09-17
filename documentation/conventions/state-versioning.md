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

La igualdad afirmada hoy es la del evento con el snapshot:
`tests/Feature/Realtime/GameStatePublisherTest.php:79` compara `$snapshot->toArray()` con
`new GameStateChanged($snapshot)->broadcastWith()`. Eso prueba que el evento no reinterpreta el
snapshot, y nada más: no emite ninguna petición HTTP, así que no toca `GameController::show` ni el
sobre `ApiResponse`, que es justo por donde una proyección se parte en dos.

> El hueco lo cierra `tests/Feature/Game/SnapshotDeliveryParityTest.php`, **ya escrito**: emite
> `GET /api/v1/games/{gameId}` de verdad, contra el repositorio real, y afirma que el `data` del sobre
> y el `broadcastWith()` que salió de esa misma escritura son **idénticos byte a byte**. Cubre la
> creación, un renombrado por HTTP y una partida terminada.

### `join_code` es `null` en una partida terminada

La partida terminada es el caso que parte la proyección en dos, y por eso el test de paridad lo
incluye. El código se libera al terminar —`games.join_code` pasa a NULL para que otra mesa pueda
teclearlo—, así que la fila ya no lo tiene, mientras que el agregado vivo que se acaba de emitir
todavía lo recuerda. Si la proyección emitiera "el código que tenga a mano", el televisor recibiría
`K7QP3M` por el socket y `ZZZZZZ` —el relleno con el que `EloquentGameRepository` reconstituye una
partida sin código— por el REST un segundo después: mismos `game_id` y `version`, bytes distintos.

`GameSnapshot` lo resuelve en la proyección y no en el almacenamiento: con un estado terminal emite
`"join_code": null`. Null explícito y nunca una clave ausente, la misma regla que sigue `tile`. Una
partida terminada no se puede teclear desde ningún sitio, así que el único valor correcto por las dos
rutas es "no hay código".

Esto no es ceremonia: el `GameController::show` viejo descartaba en silencio los `dominoes` y el
`game_phase` que su propio servicio devolvía. Ésa es exactamente esta divergencia, sin el test que la
habría cazado.

El snapshot lleva además **un campo de despliegue**, `tv_idle_notice_minutes`, fuera de
`room_config`: minutos de silencio tras los cuales la pantalla de *watch* avisa de que la partida
parece abandonada. Viaja aquí y no en una segunda petición por el mismo motivo que la configuración de
sala —un televisor entra por `JoinCode` y nunca vio la respuesta de creación—, y va fuera de
`room_config` porque no lo elige la mesa: lo pone `config/trident.php`. Es público por construcción y
no es una credencial. El cliente **no** guarda un valor propio, así que móvil y televisor no pueden
discrepar. Su restricción está en [`room-config.md`](room-config.md): menor que `idle_timeout_minutes`.

`seats` es un **array de objetos con un `seat` explícito** — nunca un mapa posicional, nunca filas de
BD crudas. `pool` sigue la misma regla con un `position` explícito, y una posición sin coger emite
`tile: null`: **null explícito, nunca una clave ausente**, porque el tipo del frontend distingue las
dos y una clave que a veces está es un contrato que nadie puede tipar.

**Una sola proyección para todo el mundo.** Lo que se oculta se oculta por stage —lo decide
`RuleSet::visibility()`, con vocabulario cerrado (`faces_hidden_until_taken` | `faces_open`), ver
`rule-set-seam.md`— y jamás por espectador: el móvil se pasa de mano, así que quien lo sostiene
recibe exactamente los mismos bytes que el televisor y no puede leer el tablero en las devtools. Por
eso el test de paridad es además la prueba de todo el modelo de visibilidad, y por eso no hay una
segunda clase de snapshot para el controlador.

## Techo declarado: no hay event sourcing

Un agregado, un repositorio. **Sin read store, sin upcasting, sin tabla de snapshots, sin bus de
eventos de dominio.**

El beneficio que se propuso — re-jugar una partida entera contra otro ruleset y diferenciar el flujo
de efectos — **no se cobrará nunca**, porque el autor valida sus reglas gritando en una mesa, no
leyendo un diff. `ReplayMatchesStateTest` ya da la única garantía que este proyecto necesita de un
log: reproducir los movimientos reproduce la fila.

## El tiempo se inyecta

`Src\Shared\Domain\Service\Clock` con `SystemClock` y `FrozenClock`, inyectado en el agregado y en
cada handler. La expiración es una transición de estado gobernada por una regla —*"una partida viva no
debe expirar a mitad de juego"*— y sin reloj inyectable eso se testea con `sleep()` o no se testea.

**Todo defecto de TTL del sistema viejo era un defecto de tiempo.**
