# La costura `RuleSet` — el único sitio donde puede vivir una regla

> Estado: **decidido** 2026-09-10. Criterio de éxito declarado al final de este documento. Las reglas
> que esta costura ejecuta están enunciadas en [`trident-rules.md`](trident-rules.md); aquí se citan
> por número y no se repiten.

## El problema que resuelve

Las reglas del Trident están escritas, y aun así no viven en el esquema ni en el agregado. La costura
sostiene tres propiedades concretas:

- **El esquema no codifica ninguna semántica de regla.** No hay columna `trident_seat`, ni
  `challenge_pip_3`, ni tabla de retos: TR-29 y TR-43 se cumplen sin una sola migración, y el mismo
  esquema sirve a cualquier variante que la casa quiera jugar mañana.
- **La regla de direccionamiento vive en exactamente una clase.** TR-44 —el reto de la cara 3 apunta
  al trident en `main`— es una condición dentro de `trident.v1`. Ni el agregado, ni la proyección, ni
  el cliente saben qué es un trident.
- **La configuración de la sala enchufa en una superficie declarada.** `roomConfigSpec()` publica las
  siete claves de TR-43 y el lobby se pinta desde ahí, sin un solo campo escrito a mano y sin que el
  framework sepa qué es un reto.

## La regla

**Una regla del juego sólo puede vivir detrás del interfaz `RuleSet`. El esquema de base de datos no
codifica ninguna semántica de regla.**

```php
interface RuleSet {
    public function id(): string;                     // "trident.v1"
    public function stateVersion(): int;              // versión de esquema de $ruleState
    public function stages(): StageSequence;          // StageId[] ordenados + etiquetas humanas
    public function deck(GameContext $c): TileDeck;
    public function visibility(GameContext $c): Visibility;
    public function roomConfigSpec(): RoomConfigSpec;
    public function onGameStarted(GameContext $c): Outcome;
    public function onTileDrawn(DrawContext $c): Outcome;
}
```

Ocho métodos y **dos** entradas de ejecución. **No hay `isStageComplete`, `onStageComplete` ni
`isFinished`**, y no deben añadirse: `Outcome.nextStage` y `Outcome.finished` ya expresan cada
transición dirigida por reglas —TR-18 y TR-34 son exactamente esos dos campos— y dos caminos para un
mismo cambio de estado son dos caminos que pueden discrepar. Ninguna transición la dispara un reloj
—ninguna pantalla lleva temporizador y todo avanza con un toque humano— y la única transición temporal
que existe, la expiración por inactividad, aterriza en `abandoned`, que es terminal del framework y
nunca consulta reglas (TR-35).

El rebarajado y el asiento de apertura del stage siguiente no son métodos: el framework reacciona a
`nextStage` pidiendo `deck()` otra vez (TR-31), y el asiento lo fija `Outcome.overrideNextSeat`
(TR-32).

`deck()` y `visibility()` reciben `GameContext` y no un `StageId` pelado. Un ruleset puede querer
dimensionar el mazo a la mesa, o abrir el resto del tablero cuando el `RuleState` dice que ya ha
pasado algo; estrechar la entrada obligaría a inventar un stage falso para expresar esas reglas, que
es el framework dictando la forma de una regla. `trident.v1` no ejerce ninguna de las dos (TR-05,
TR-07), y la firma vale igual: la costura la fija el peor ruleset que tenga que aceptar, no el único
que hay.

El agregado valida sólo lo que **él** posee — la partida está en `running`, hay un `current_seat`, la
posición existe y no está cogida, la partida no ha terminado — y entonces llama al objeto de reglas y
aplica los efectos devueltos. **Ése es todo el acoplamiento con las reglas.**

**"¿Es tu turno?" no está en esa lista, y no puede estarlo.** Hay un solo teléfono y un solo
`ControllerToken`: el servidor no puede saber qué humano lo sostiene. `Game::drawTile()` **no recibe
asiento**; la cogida se atribuye al `current_seat` que el agregado ya conoce (TR-11), y el cliente no
envía nunca un número de asiento en una cogida. `not_your_turn` no existe en el vocabulario de
errores.

## Las entradas son DTOs a medida — ni el snapshot ni el agregado

Ésta es la decisión más importante del documento.

```php
GameContext {
    StageId $stage, SeatRing $seats, ?SeatNumber $currentSeat, DrawLog $priorDraws,
    int $turnNumber, RuleState $state, RoomConfig $config
}

DrawContext {
    SeatNumber $seat, PoolPosition $position, Tile $tile, SeatRing $seats,
    DrawLog $priorDraws, TilePool $poolBefore, int $turnNumber, StageId $stage,
    RuleState $state, RoomConfig $config
}
```

- **Pasar el `GameSnapshot`** (la proyección de cable) haría que cualquier regla que necesite
  información no proyectada o histórica forzase un cambio en la clase cuyo único trabajo es **ser
  segura de emitir a un televisor**. Con el pool boca abajo eso deja de ser un argumento de estilo: el
  snapshot **no lleva** las caras sin coger (TR-07), así que una regla que las necesite no podría
  leerlas ahí.
- **Pasar el agregado entero** convertiría la superficie real de la costura en el modelo completo, que
  es lo mismo que no tener costura.
- `GameContext.currentSeat` es **nulable**: `onGameStarted` corre antes de que exista cursor (TR-17).
  Un tipo no nulable obligaría a inventar un asiento cero para el único momento en que aún no hay
  ninguno.
- `GameContext.priorDraws` va siempre, aunque el contexto no sea el de una cogida. `deck()` y
  `visibility()` son los dos sitios donde una regla puede depender de lo ya salido, y sin ese campo
  tendrían que inventarse un stage para acordarse.
- `DrawContext` lleva **posición y ficha**: la posición es lo que el móvil toca, la ficha es lo que el
  servidor descubre al voltearla. `trident.v1` decide con la ficha —las dos caras de TR-38 y el `3|3`
  de TR-24—, y otra regla puede mirar la posición: *"la última casilla que queda"* es tan regla como
  *"el doble tres"*. El campo `seat` lo rellena el agregado desde `current_seat`; no viene de la
  petición.
- **No hay `?SeatNumber $tridentSeat`.** "Trident" es un **rol**: vive en `game_seats.roles` jsonb y
  se lee del `SeatRing` (TR-29). Un campo dedicado en el DTO —o una columna `trident_seat`— sería una
  regla horneada en la forma de la costura.
- `RoomConfig` entra porque un ruleset puede ramificar sobre un ajuste que él mismo declaró. Nunca
  entra `config()`: el dominio no puede llamarlo y `ArchitectureTest` lo enforza.

## La visibilidad es una regla, y el pool es posicional

El mazo de un stage se materializa en un `TilePool`: una lista **ordenada, 1-based, de posiciones**,
cada una con su ficha y con quién la cogió. Una posición cogida se **marca, nunca se quita** (TR-08):
quitarla renumeraría todas las siguientes, y un móvil rancio que toca la posición 7 acabaría cogiendo
otra ficha. Eso es un fallo de corrección, no un detalle cosmético.

Se coge **por posición, nunca por cara**: `POST /api/v1/games/{gameId}/pool/{position}/draw`. El
cliente no puede nombrar una cara que no ha visto, y la misma ficha existe legítimamente en los dos
stages (TR-04), así que la cara no identifica nada. Los dos modos de fallo de esa ruta son **caminos
distintos**: `->whereNumber('position')` restringe el segmento a `[0-9]+`, así que un segmento no
numérico es un **404 de la restricción de ruta**, mientras que una posición numérica fuera de
`1..count` llega al handler y sale como **`422 pool_position_not_in_pool`**.

`RuleSet::visibility(GameContext $c): Visibility` responde si las caras sin coger están ocultas.
`Visibility` es una **`final class` de constantes del framework** (R4), con `ALL` e `isValid()` como
`MoveKind`: `faces_hidden_until_taken` y `faces_open`. **No es un booleano** y no se llama
`concealUndrawnTiles`: un booleano no admite un tercer caso sin cambiar la firma, y un ruleset que
abra el tablero a media partida es exactamente ese tercer caso. De ahí salen tres reglas que no se
negocian:

- **El ocultamiento es una propiedad de la proyección, no del almacenamiento.** La fila `games` guarda
  todas las caras; `TilePool::project(Visibility $v)` emite `tile: null` en cada posición sin coger
  cuando toca ocultar. Si esa decisión se cableara dentro del `TilePool`, cambiarla sería editar un
  objeto de valor del framework en vez de un ruleset — exactamente el acoplamiento que este documento
  existe para impedir.
- **Se oculta POR STAGE, jamás por espectador** (TR-06). El móvil y el televisor reciben **los mismos
  bytes**: el móvil se pasa de mano, y quien lo sostiene no puede tener el tablero abierto en las
  devtools. Por eso sigue habiendo **una sola proyección** (ver
  [`state-versioning.md`](state-versioning.md)), y por eso el test de paridad es también la prueba de
  todo el modelo de visibilidad. La igualdad que hoy se afirma, en
  `tests/Feature/Realtime/GameStatePublisherTest.php:79`, compara `$snapshot->toArray()` con
  `broadcastWith()` —el objeto contra sí mismo, sin emitir ninguna petición HTTP—. Falta
  `tests/Feature/Game/SnapshotDeliveryParityTest.php`, que emite `GET /api/v1/games/{gameId}` de
  verdad y compara el `data` del sobre con `broadcastWith()`.
- **La semilla no sale nunca** (TR-09). `games.shuffle_seed` es secreto de servidor: no va en el
  snapshot, ni en un payload de movimiento, ni en un cuerpo de error, ni cuando la partida termina, y
  no se acepta como campo de petición. El barajado es Fisher-Yates sobre un flujo de bytes de
  `hash('sha256', seed|stage|contador)` — **nunca `mt_srand()` + `shuffle()`**, porque el estado
  interno de `mt_rand` se recupera de un puñado de salidas y el prefijo revelado del tablero es
  exactamente ese puñado. La guarda prohíbe las llamadas `mt_rand(`, `mt_srand(`, `shuffle(`, `rand(`
  y `array_rand(`, no las palabras sueltas.

## La salida es un vocabulario cerrado

`Outcome`: `Effect[] $effects`, `?SeatNumber $overrideNextSeat`, `?StageId $nextStage`,
`array $ruleStatePatch`, `bool $finished`, `?FinishReason $reason`.

`Effect` es **declarativo y cerrado**, y la UI lo pinta sin conocer ninguna regla. Se persiste como
jsonb en `game_moves.payload`:

```
Effect::announce(string $messageKey, array $params)          // copia que trae la app
Effect::assignRole(SeatNumber $seat, string $role)           // "trident" es sólo un string de rol
Effect::challenge(?SeatNumber $target, string $configKey)    // copia que escribió la sala
```

`trident.v1` emite dos de los tres: `assignRole` en la cogida del `3|3` de la elección (TR-27) y
`challenge` dos veces por ficha volteada (TR-38). `announce` se queda en el vocabulario sin emisor de
producción: cada momento que merece pintarse ya es uno de los otros dos efectos.

**`Effect::challenge` lleva `target` por una razón concreta y en producción:** TR-44. En `main`, el
reto de la cara 3 apunta al asiento del trident y no a quien cogió la ficha; los demás retos apuntan a
quien cogió (TR-45), y durante la elección todos apuntan a quien cogió (TR-47). Sin `target`, esa
única excepción obligaría a que el cliente supiera qué es un trident. `$target === null` significa
"toda la mesa", que es un campo nullable en lugar de quince efectos repetidos.

Tres reglas sobre este vocabulario:

- **No existe un efecto de beber, y no debe crearse.** Beber es el contenido por defecto de los retos,
  no el mecanismo: un grupo que quiera usar el juego para otra cosa tiene que poder, y un
  `EffectKind::DRINK` hornearía un tema en el único sitio cuyo propósito declarado es no conocer
  ninguno. Tampoco lleva cantidad: **no hay contador de nada, en ningún sitio** (TR-54), así que un
  `int $sips` sería el último rastro de un marcador que no existe.
- **`announce` y `challenge` no se fusionan.** `announce` indexa copia que envía la aplicación
  —confiable, traducible, con parámetros—; `challenge` indexa copia que **escribieron los jugadores**
  —no confiable, acotada, texto plano—. Un solo efecto de texto pasaría las dos por el mismo
  renderizador. El efecto lleva **la clave, nunca el texto** (TR-42): es una clave plana y punteada
  del espacio de configuración de sala (`challenge.face.3`), viaja en el cable como `config_key` y en
  PHP como `string $configKey`, y direcciona **toda** la configuración, no sólo los retos. La
  configuración congelada viaja en el mismo snapshot, así que la clave siempre es resoluble con los
  mismos bytes y no hay ventana en la que una clave no se pueda resolver; y el historial de
  `game_moves` no se llena de copias de la misma frase.
- **El orden de turno se expresa sólo con `Outcome.overrideNextSeat`.** No hay `Effect::skipTurn`: un
  efecto que además moviera el cursor sería una segunda fuente de verdad sobre quién juega. Si el
  salto hay que anunciarlo, se anuncia con `announce`.

**Por eso las reglas son enchufables con cero migraciones:** una regla sorprendente llega como una
constante nueva de `EffectKind` más una rama en `EffectBanner.tsx`. Nunca un `ALTER TABLE`.

`EffectKind` es una `final class` de constantes con `ALL` e `isValid()`, como `MoveKind`. Nunca un
enum nativo de PHP.

## Dónde para la costura: regla, ajuste de sala, presentación

Tres capas, y para asignar algo a una de ellas hay una sola pregunta: **¿puede cambiar el resultado de
la partida?**

| Capa | Quién la posee | Ejemplo |
|---|---|---|
| **Regla** | `RuleSet` | qué ficha te hace trident; cuándo acaba un stage; si las caras sin coger están ocultas; a quién apunta el reto de la cara 3 |
| **Ajuste de sala** | `RoomConfig`, declarado por `RuleSet::roomConfigSpec()` y escrito por la mesa | el texto de cada reto; si las fichas cogidas se retiran del tablero |
| **Presentación** | el cliente | cómo se dibuja una posición cogida; la animación de volteo; la transición de traspaso del móvil |

Un ajuste **no es una regla**: el ruleset declara qué ajustes existen y puede leerlos, el framework
nunca los interpreta, y la base de datos los guarda como un blob opaco. Quién valida, qué pasa con una
clave desconocida o ausente, y por qué esa forma sostiene el criterio de cero migraciones está en
[`room-config.md`](room-config.md).

La frontera entre las dos primeras capas pasa por dentro de los retos: **cuándo se dispara un reto es
una regla y qué dice es un ajuste.** Que cada ficha volteada dispare los retos de sus dos caras en los
dos stages, en qué orden y contra quién, es regla del ruleset (TR-38, TR-39, TR-44); las siete frases
que se anuncian las escribe la sala (TR-43, TR-53).

El error de capa más fácil de cometer: *"las fichas cogidas se quitan del tablero"* **no es
visibilidad**. Una posición cogida no se puede volver a coger en ninguno de los dos modos y el
snapshot emite los mismos bytes con ambos, así que no cambia ni el resultado ni los datos: es
presentación, gobernada por un ajuste. El **valor por defecto por stage** sí lo pone el ruleset
(TR-52) porque depende del stage, y un `StageId` es un string opaco que sólo el ruleset entiende. La
sala **sobrescribe** ese default; no lo inventa, y ningún fichero del cliente contiene el nombre de un
stage.

## El estado opaco va versionado; la configuración de sala no

`games.rule_state` jsonb lleva siempre `_v = RuleSet::stateVersion()`. Un ruleset que lee un blob
escrito por una versión anterior de sí mismo y no puede migrarlo **termina la partida** con
`FinishReason::RULESET_UPGRADED` y emite un snapshot final, en vez de malinterpretarlo en silencio.

`trident.v1` devuelve `stateVersion() = 1` y no guarda nada más allá de `_v` (TR-19), así que hoy ese
camino no lo ejercita ninguna partida real: lo ejercita el doble hostil, que es justamente el motivo
de que exista.

Evolucionar clases sobre jsonb opaco es un camino clásico de corrupción silenciosa; esto es la
guarda de dos líneas contra él.

**`games.room_config` no lleva `_v` y no termina nada nunca.** La asimetría es deliberada:
`rule_state` lo escribe una máquina, está acoplado estructuralmente, y leerlo mal corrompe una partida
en curso; `room_config` lo escriben personas, es un mapa plano, y cada clave tiene un default
independiente, así que una clave que falta se rellena y una desconocida se ignora. Matar la partida de
un salón porque una frase cambió de sitio es peor que usar la frase por defecto. El argumento completo
está en [`room-config.md`](room-config.md).

## Un ruleset por partida, fijado al nacer

`RuleSetResolver` lee **`games.rule_set_id`**, no `config()`. Una partida en vuelo queda fijada al
ruleset con el que nació. `config/trident.php: default_rule_set` vale `trident.v1` y sólo decide con
qué nace una partida nueva; un ruleset futuro cambia ese default y **no debe cambiar el significado de
una partida que ya está sobre una mesa.**

`trident.v1` es la **única** implementación de producción. `HostileRuleSet` vive en
`tests/Unit/Game/Doubles/` y no es una regla del juego.

## El test de doblado: un ruleset hostil que tiene que ejecutarse

`tests/Unit/Game/Doubles/HostileRuleSet.php` — un ruleset sólo-de-test que ejerce la costura por sus
puntos débiles y que debe correr **por `GameController` y los handlers reales**, sin cambios. Correr
contra el agregado directamente no prueba nada de la costura.

Su trabajo es **estresar el interfaz**: `trident.v1` usa una parte estrecha de la superficie —dos
stages, un mazo fijo, una visibilidad, un `overrideNextSeat`, ningún `ruleStatePatch`— y un interfaz
que sólo ha ejecutado a su propio caso cómodo no está probado. El hostil recorre los bordes que el
juego real no toca, para que una variante de la casa no descubra que la costura sólo aguantaba una
implementación.

Ocho esfuerzos, cada uno con su test nombrado:

1. **Exige una elección del jugador a mitad de turno.** `trident.v1` no la exige nunca (TR-12), así
   que es el borde que ninguna partida real ejercita, y es una exigencia plausible: un reto
   configurable puede decir *"señala a alguien"*, porque *"señala a alguien"* es un texto que la sala
   puede escribir (TR-13).
2. **Un stage cuya `visibility()` devuelve `faces_open`**, que prueba que la proyección cambia de
   forma **por stage** con una sola clase de proyección y sin rama por espectador. `trident.v1`
   devuelve `faces_hidden_until_taken` en los dos (TR-07), así que la otra constante no la ejecuta
   nadie más.
3. **Un stage cuyo `deck()` devuelve cuatro fichas**, que prueba que nada —serialización del pool,
   camino de pool vacío, rejilla responsiva— da 49 por supuesto por el hecho de que TR-01 diga 49.
4. **Tres stages cuyo `nextStage` salta hacia atrás** a uno ya jugado: el test específico de que
   quitar `isStageComplete`/`onStageComplete` no coló una suposición de orden en el framework.
   `trident.v1` sólo avanza (TR-18).
5. **`onGameStarted` devolviendo `finished: true`** — una partida de cero cogidas, que prueba que el
   camino terminal no exige una cogida. El terminal real llega en la cogida 49 (TR-34).
6. **`overrideNextSeat` apuntando al mismo asiento**, de modo que un jugador coge dos veces seguidas.
   Rompe la pantalla de traspaso, que no tiene diseño para *"no lo pases, Ana repite"*. `trident.v1`
   lo usa una sola vez y para otra cosa (TR-32).
7. **Una regla que ramifica sobre un valor de `RoomConfig`**, con un blob guardado al que le falta una
   clave declarada, que prueba que la configuración es legible por las reglas y se resuelve en un solo
   sitio. `trident.v1` declara siete claves de texto y ninguna regla suya ramifica sobre ellas
   (TR-53).
8. **Una segunda clase hostil con el mismo `id()` y `stateVersion() + 1`** leyendo un blob v1, que
   prueba que el camino `FinishReason::RULESET_UPGRADED` se ejecuta de verdad.

Variantes de reglas esbozadas en papel por la misma persona que diseñó el interfaz siempre encajan.
Una implementación hostil que tiene que **ejecutarse de verdad**, no. Si no puede correr, el interfaz
se arregla **antes de que exista una segunda implementación**, mientras no hay ninguna partida jugada
que migrar.

La forma esperada de ese arreglo: `Outcome` gana un `PendingChoice` y `status` gana `awaiting_choice`.
Presupuestada una tarde. **No se construye por adelantado:** ninguna regla del juego pide la
intervención de otro jugador (TR-12, TR-13), así que construirlo antes de que el ruleset hostil lo
exija es el código especulativo que este proyecto prohíbe.

## Criterio de éxito, declarado por adelantado

> Cuando llegue un ruleset nuevo —una variante de la casa, un modo distinto—,
> `git diff --stat database/migrations/` de la fase que lo introduce está **vacío**.

Cualquier otra cosa es un **fallo de diseño de esta costura**, y se registra aquí con su causa. No se
tapa.
