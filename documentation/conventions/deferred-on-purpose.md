# Diferido a propósito (no es un olvido)

> Estado: **vivo.** Una entrada de la primera tabla se borra cuando la pieza se construye; una de la
> segunda, sólo si el autor pide lo contrario.

El estándar del equipo pide algunas piezas que este proyecto **todavía** no necesita. Construirlas sin
un consumidor sería código especulativo, que la skill de TDD prohíbe explícitamente. Se anotan aquí
para que una auditoría las lea como decisión y no como descuido.

| Pieza | Por qué no está | Cuándo entra |
|---|---|---|
| **`ApiResponse::paginated()`** | No hay una sola lista paginada en el producto. Una partida devuelve un snapshot completo; los asientos son 3-15 y viajan dentro de él. Escribir una factoría de paginación sin nada que paginar es inventarse un contrato. | Si alguna vez aparece un listado (un historial de partidas, por ejemplo). El sobre ya reserva `meta` para ello. |
| **Terminar la partida a mano** | El juego principal termina cuando se agota su pool, y ésa es toda la condición de fin dirigida por reglas (TR-34, TR-35). Un botón de "terminar ya" es producto nuevo: necesita su ventana de confirmación, su `FinishReason` y su ruta mutante con la fila correspondiente en el barredor de mutaciones. | Cuando el autor lo pida; él lo sitúa *"para el final"*. `GameOverScreen` nace sin él. |

## Descartado, no diferido

Estas piezas **no entran por sí solas**. No esperan un consumidor: esperan que el autor pida lo
contrario, y mientras no lo pida, construirlas es inventar producto.

| Pieza | Por qué no existe |
|---|---|
| **Relevo de controlador** (`/claim-controller`, `TakeoverCode`, `takeover_code_length`) | No hay relevo: el móvil que abre la partida es el único que escribe en ella, y el televisor **sólo observa, nunca juega**. El caso que el relevo defendía —el móvil se muere a mitad de partida— está resuelto por el autor y la resolución es *no construir nada*: la partida se queda mirable en el televisor, expira por inactividad y el grupo crea otra. `config/trident.php` **no declara `takeover_code_length`**: ninguna clase la leería, y una clave de configuración que nadie honra es peor que ninguna. |
| **Quitar un asiento** | Se pueden **añadir** asientos en el lobby; no se construye ninguna eliminación, ni en lobby ni en juego. Nadie queda eliminado tampoco por reglas (TR-37). Quitar un asiento renumera el anillo, y `game_moves.actor_seat` es un `smallint` pelado, no una referencia a una persona: un número de asiento histórico dejaría de nombrar al mismo humano y corrompería el historial de qué cogió cada uno, que es justo lo que el autor sí pidió conservar. Por la misma razón, reordenar asientos está **limitado al lobby**, donde no hay historial que corromper. |
| **Deshacer una cogida** | Una cogida es un hecho físico: la ficha está volteada sobre la mesa y todo el salón la ha visto (TR-06). Deshacerla en la pantalla no la desvoltea en la habitación. Además exigiría reescribir un log de **sólo-anexar** que sirve a la vez de contador de versión, libro de idempotencia y rastro de auditoría, y bajar la versión rompería el guard con el que cada televisor decide si un frame es nuevo. El arreglo de una cogida equivocada es social, no técnico. El doble toque —que sí es un fallo real— lo cubre `X-Request-Id` con su índice único, que ya existe en el esquema. |
| **Memoria entre partidas** | No hay marcador, ni totales, ni nada que sobreviva a la partida (TR-54). El historial de la mesa muere con la fila (TR-55) y ninguna regla mira una partida anterior (TR-56). |
| **Segunda proyección por espectador** (`ControllerGameSnapshot`) | **No existe información oculta a un participante y visible a otro** (TR-06): lo que se oculta se oculta por stage (TR-07), así que móvil y televisor reciben los mismos bytes. Dos proyecciones sobre la misma ruta son exactamente lo que caza el test de paridad entre el `data` del REST y `broadcastWith()` (ver `state-versioning.md`). `game_seats.private_state` sigue aprovisionado y **ninguna regla lo lee**; TR-06 descarta la clase de regla que lo justificaría. La única obligación que impone mientras siga en el esquema: el agregado lo transporta en los dos sentidos —fuera de `Seat::toArray()`, así que no llega al snapshot— para que escribir los asientos como conjunto no lo ponga a cero. |

## Lo que NO está aquí, porque sí se hizo

Estas piezas están construidas, así que no son diferimientos y no tienen fila arriba:

- **`ApiResponseMiddleware`**, el limitador `throttle:api`, `config/cors.php`, las plantillas
  `.env.example.<env>` y la configuración de PostgreSQL.
- **`ApiFormRequest`** existe en `src/Shared/Infrastructure/Http/ApiFormRequest.php` con
  `$stopOnFirstFailure`; sus hijos `CreateGameRequest` y `RenameSeatRequest` ponen `messages()` y
  `toCommand()`.
- **Los buses tienen handlers registrados** en `DomainServiceProvider`: `CreateGameHandler`,
  `RenameSeatHandler`, `GetGameStateHandler` y `ResolveJoinCodeHandler`.
- **`Dockerfile` y `docker-compose.yml`** están en la raíz de `trident-api`, con `docker/entrypoint.sh`
  y la configuración de Postgres, y se levantan de verdad porque ya hay tablas que levantar.
