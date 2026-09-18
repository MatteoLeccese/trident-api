# Las reglas del Trident — enunciado canónico

> Estado: **decidido** por el autor, 2026-09-17. Éste es el único sitio del proyecto donde se
> enuncian las reglas del juego. Cualquier otro documento las **cita por su número** y no las repite;
> si un documento y este fichero discrepan, este fichero manda.

## Cómo se lee y cómo se testea

Cada regla es **una línea numerada con una sola afirmación comprobable**. La numeración es estable:
un número no se recicla nunca, y una regla que deja de existir se borra dejando su número muerto.

**Números muertos: TR-40, TR-47 y TR-49.** Existieron y se borraron; no se reutilizan. Lo que
queda de ellos está en TR-23 y TR-48.

La forma no es decorativa. **La estrategia de test es un test unitario por afirmación numerada**, en
`tests/Unit/Game/Rules/TridentRuleSetTest.php`, nombrado con su número —
`test_tr_24_the_election_ends_on_the_double_three()` — de modo que la cobertura de la especificación
se audita a ojo: se listan los tests, se listan los números, y las dos listas coinciden.

Las reglas las implementa **`trident.v1`**, la única implementación de producción de `RuleSet`.
`tests/Unit/Game/Doubles/HostileRuleSet.php` no es una regla del juego: es el doble que estresa la
costura.

## 1. El mazo

**TR-01.** El mazo de cualquier stage es `TileDeck::standard()`: 49 fichas, todos los pares
ordenados de dos caras independientes de 0 a 6.

**TR-02.** `2|1` y `1|2` son fichas distintas. El valor persistido y de cable de una ficha es la
cadena de dos caracteres que devuelve `Tile::value()` — `"21"` —; `2|1` es sólo notación de prosa y
de interfaz.

**TR-03.** Hay exactamente un `3|3` en el mazo, y cada uno de los siete dobles aparece una sola vez.

**TR-04.** Cada stage materializa su propio `TilePool` barajado desde un mazo nuevo, así que la
identidad de una cogida es `(stage, posición)` y nunca la ficha: el `3|3` puede salir en los dos
stages de la misma partida.

**TR-05.** El mazo no depende del número de jugadores: son 49 fichas con 3 asientos y con 15.

## 2. No hay información oculta

**TR-06.** Ninguna información está oculta a un participante y visible a otro. No existe mano
privada, ni proyección por espectador, ni un segundo snapshot.

**TR-07.** El ocultamiento es una propiedad **del pool**: una posición sin coger no lleva cara, para
todo el mundo a la vez. `RuleSet::visibility()` devuelve `faces_hidden_until_taken` en los dos
stages.

**TR-08.** Una posición cogida se marca y su cara queda visible para todos los clientes a la vez; no
se quita del pool, porque quitarla renumeraría las siguientes.

**TR-09.** Lo único que el servidor retiene es la cara de una posición sin coger y
`games.shuffle_seed`; la semilla no sale jamás del servidor, en ningún cuerpo de respuesta ni de
error.

## 3. Un asiento a la vez

**TR-10.** Exactamente un asiento actúa en cada momento: el `current_seat` que el agregado conoce.

**TR-11.** Una cogida no lleva número de asiento: se atribuye al `current_seat`, y el cliente nunca
envía un asiento al coger.

**TR-12.** Ningún turno exige la intervención de otro jugador: `trident.v1` no devuelve nunca una
elección pendiente, y `status` nunca sale de `running` por esperar a un humano distinto del que
tiene el mando.

**TR-13.** Ningún efecto que emita `trident.v1` pide a un jugador que señale o nombre a otro. Un
reto escrito por la mesa puede decirlo en su texto; eso es contenido de la sala, no mecánica de la
aplicación.

**TR-14.** La mesa tiene entre 3 y 15 asientos y todos juegan las dos etapas
(`config/trident.php: min_players`, `max_players`).

**TR-15.** Un nickname mide entre 2 y 24 caracteres (`Nickname::MIN_LENGTH`, `Nickname::MAX_LENGTH`).

## 4. Las dos etapas

**TR-16.** `RuleSet::stages()` devuelve exactamente dos `StageId`, en este orden: `election` y
`main`.

**TR-17.** `onGameStarted` deja la partida en `election`.

**TR-18.** La única transición de stage dirigida por reglas es `election` → `main`, expresada con
`Outcome.nextStage`. No se vuelve a `election`.

**TR-19.** `trident.v1` no guarda nada en `games.rule_state` más allá de `_v`: cada decisión se toma
con `GameContext` y `DrawContext`, y `stateVersion()` devuelve `1`.

## 5. La elección

**TR-20.** La elección arranca con un pool nuevo de 49 posiciones barajadas.

**TR-21.** El primer asiento en coger en la elección es el asiento 1 (`SeatNumber::first()`) — ver
inferencia I2.

**TR-22.** Dentro de la elección el turno avanza en orden de anillo, `SeatRing::next`, sin salto ni
repetición. La única cogida de la elección que devuelve `overrideNextSeat` es la del `3|3`, y lo hace
para fijar el asiento que abre `main` (TR-32), no para alterar el orden de la propia elección.

**TR-23.** En la elección no se dispara **ningún** reto. La etapa voltea fichas hasta que aparece el
`3|3` y ahí termina; los retos son del juego principal.

**TR-24.** La elección termina **exactamente** en la cogida del `3|3`, y en ninguna otra: esa cogida
devuelve `nextStage = main` y ninguna otra devuelve `nextStage`.

**TR-25.** El resto del pool de la elección no se juega nunca: `main` recibe un pool nuevo.

**TR-26.** La elección no puede agotarse: el `3|3` está en el pool, así que la etapa dura entre 1 y
49 cogidas y ambos extremos son alcanzables.

## 6. El trident

**TR-27.** Quien coge el `3|3` en la elección se convierte en el trident, mediante
`Effect::assignRole($seat, 'trident')`.

**TR-28.** Hay exactamente un trident por partida: ningún otro efecto asigna ese rol y ninguno lo
retira.

**TR-29.** El rol vive en `game_seats.roles` y se lee del `SeatRing`. No hay columna `trident_seat`,
ni campo `tridentSeat` en ningún DTO de la costura.

**TR-30.** Ser el trident no cambia el turno de nadie: no otorga prioridad, ni cogidas extra, ni
inmunidad.

## 7. El juego principal

**TR-31.** `main` arranca con un pool nuevo de 49 posiciones, barajado independientemente del de la
elección.

**TR-32.** Abre `main` el asiento 1 (`SeatNumber::first()`), sea quien sea el trident, fijado con
`Outcome.overrideNextSeat` en la cogida que cambia de stage.

**TR-33.** Dentro de `main` el turno avanza en orden de anillo, `SeatRing::next`, sin salto ni
repetición.

**TR-34.** `main` termina cuando su pool se agota: la cogida número 49 devuelve `finished = true`
con `FinishReason::POOL_EXHAUSTED`.

**TR-35.** Ninguna otra condición termina la partida por reglas. La expiración por inactividad
aterriza en `abandoned`, que es terminal del framework y no consulta al ruleset.

**TR-36.** `main` dura exactamente 49 cogidas, independientemente del número de asientos.

**TR-37.** Nadie queda eliminado: los mismos asientos que abren `main` lo cierran.

## 8. Los retos

**TR-38.** En `main`, cada ficha volteada dispara **exactamente dos retos**, uno por cara. En la
elección no dispara ninguno (TR-23), así que toda esta sección habla de un solo stage.

**TR-39.** El orden es cara izquierda y luego cara derecha: la ficha `2|1` dispara
`challenge.face.2` y después `challenge.face.1`.

**TR-41.** En `main`, un doble dispara dos veces el reto de su única cara: `3|3` dispara
`challenge.face.3` dos veces.

**TR-42.** Un reto viaja como `Effect::challenge(?SeatNumber $target, string $configKey)` y lleva
**la clave, nunca el texto**; en el cable es `{ "kind": "challenge", "seat": 1, "config_key":
"challenge.face.3" }`. El `seat` de un efecto es su **destinatario**, no su autor: quien cogió la
ficha es `actor_seat` en el movimiento, y en `main` el `seat` de un reto de cara 3 es el del trident
(TR-44).

**TR-43.** Existen **exactamente siete** claves de reto: `challenge.face.0` … `challenge.face.6`,
una por cara. Ninguna clave de reto está indexada por ficha.

**TR-44.** En `main`, el reto de la cara 3 apunta **al asiento del trident**, no al asiento que cogió
la ficha. Es la única regla del juego cuyo destinatario no es quien coge, y es la razón de que
`Effect::challenge` lleve `target`.

**TR-45.** En `main`, los retos de las caras 0, 1, 2, 4, 5 y 6 apuntan al asiento que cogió la ficha.

**TR-46.** El `3|3` en `main` no tiene tratamiento específico: es una ficha con dos treses, y emite
dos `challenge.face.3` dirigidos al trident. No hay rama por ficha en ninguna parte del código.

**TR-48.** La cogida del `3|3` en la elección emite `assignRole` y la transición de stage, y ningún
reto: es el único efecto que produce la etapa entera.

**TR-48b.** Los retos de una ficha se anuncian **después** de que termine la animación de revelación,
separados de ella por una pausa breve. El orden que ve la mesa es: se toca la posición, la ficha se
voltea, y entonces saltan los dos retos. La pausa es de cliente y no llega al servidor: el snapshot
emite la cogida y sus efectos en el mismo instante, y es la pantalla la que los escalona.

**TR-50.** `trident.v1` emite exactamente dos clases de efecto, `assignRole` y `challenge`. No emite
`announce`: cada momento que merece pintarse ya es un efecto que la UI resuelve.

**TR-51.** `roomConfigSpec()` declara las siete claves de reto como `RoomConfigField::text` con tope
de 80 caracteres y un default no vacío en inglés.

**TR-52.** `roomConfigSpec()` declara `drawn_tiles.election` con default `keep` y `drawn_tiles.main`
con default `remove`. Es presentación gobernada por un ajuste: no cambia ninguna cogida.

**TR-53.** El disparo de un reto es una regla y su texto es un ajuste. Reescribir las siete cajas no
cambia una sola cogida.

Los siete textos por defecto son un juego de beber y **los entrega el autor**. Hasta que los entrega,
las constantes de `trident.v1` llevan marcadores en inglés de la forma y la longitud correctas. Beber
es el contenido por defecto, nunca el mecanismo: no existe `EffectKind::DRINK`.

## 9. No se cuenta nada

**TR-54.** No hay marcador de ninguna clase: ni tragos, ni puntos, ni turnos jugados. Ningún `Effect`
lleva cantidad y ningún snapshot lleva contador.

**TR-55.** El historial de cogidas vive en `game_moves`, es un registro y no una puntuación, y muere
con la partida: no cruza a otra partida ni sobrevive a la expiración.

**TR-56.** Ninguna regla mira una partida anterior: `trident.v1` decide con el `GameContext` y el
`DrawContext` de la partida en curso y nada más.

## 10. La aritmética, dicha honestamente

El `3|3` es único en un pool de 49 barajado uniformemente, así que **su posición es uniforme en
1..49**: la elección dura **25 cogidas de media y 49 en el peor caso**. Con cinco jugadores eso son
cinco rondas de media y diez en el peor caso.

La consecuencia de producto se escribe aquí para que ninguna pantalla la olvide: **la elección es
mecánica y va deprisa.** No se lee nada en voz alta (TR-23), así que sus veinticinco cogidas de media
son veinticinco toques y no veinticinco pausas, y la pantalla no debe meter ni una interrupción que no
sea voltear la ficha.

El juego principal son **exactamente 49 cogidas**: diez turnos por cabeza con cinco jugadores.

Entre las 98 caras de un pool de 49, **cada valor de cara aparece exactamente 14 veces** (7 como cara
izquierda y 7 como cara derecha). De ahí dos números que fijan presupuestos de test y de UI:

**TR-57.** En un `main` completo cada una de las siete claves de reto se dispara exactamente 14
veces, y se disparan 98 retos en total.

**TR-58.** En un `main` completo el trident ejecuta exactamente 14 retos, los 14 de la cara 3.

## 11. Lo que se infiere, y de qué frase cuelga

Una inferencia **no es una regla que el autor haya dicho**. Se aísla aquí, con la frase suya en la que
se apoya, para que revocarla cueste una línea.

**I1 está revocada.** Decía que durante la elección el reto de la cara 3 apuntaba a quien cogía la
ficha. El autor precisó después que en la elección no se dispara ningún reto (TR-23), así que la
pregunta que la inferencia respondía ya no llega a hacerse. Revocarla costó una llamada dentro de
`trident.v1` y ningún esquema, que es exactamente lo que aislarla compraba.

**I2 — La elección la abre el asiento 1.**
Se apoya en la respuesta 9: *«Abre el primero de la lista de juego»*, dicha del juego principal. Antes
de la primera cogida ningún asiento se distingue de otro, y `SeatNumber::first()` es la única elección
que no inventa un criterio. Implementa TR-21.

**I3 — La razón de fin se llama `FinishReason::POOL_EXHAUSTED`.**
Se apoya en la respuesta 10: *«El juego finaliza cuando el mazo se termine»*. La condición es del
autor; el nombre de la constante es nuestro. Implementa TR-34.

## 12. Lo que no es una regla de este juego

- **La transición de traspaso del móvil.** Es **una sola transición, idéntica todas las veces**, para
  todos los asientos. No es configurable, no depende del stage y no llega a la API: la pinta el
  cliente.
- **El aviso de inactividad del televisor.** Es un valor por despliegue, no por partida: vive en
  `config/trident.php` como `tv_idle_notice_minutes`, el backend lo entrega al cliente y se pinta en
  la pantalla de *watch*. Su única restricción es que sea **menor que `idle_timeout_minutes`**, o el
  televisor manda a la gente a casa por una partida que el servidor aún no ha expirado.
- **Terminar la partida a mano.** No existe. El autor la sitúa *«para el final»*, con una ventana de
  confirmación; se anota en [`deferred-on-purpose.md`](deferred-on-purpose.md) y no se construye por
  adelantado.
- **El texto de los retos** y **`drawn_tiles.*`**: ajustes de sala, gobernados por
  [`room-config.md`](room-config.md).
- **El tamaño del mazo como clave de despliegue.** `config/trident.php` no declara ninguna clave de
  mazo: [`tile-deck.md`](tile-deck.md).

## 13. Por qué estas reglas viven detrás de `RuleSet`

`config/trident.php: default_rule_set` vale `trident.v1`, y una partida queda fijada al suyo en
`games.rule_set_id`. La costura sostiene tres propiedades que este documento necesita:

- **El esquema no codifica ninguna semántica de regla.** No hay columna `trident_seat`, ni
  `challenge_pip_3`, ni tabla de retos: TR-29 y TR-43 se cumplen sin una sola migración.
- **TR-44 vive en exactamente una clase.** El único direccionamiento excepcional del juego es una
  condición dentro de `trident.v1`; ni el agregado, ni la proyección, ni el cliente saben qué es un
  trident.
- **La configuración de la sala enchufa en una superficie declarada.** `roomConfigSpec()` publica las
  siete claves de TR-43 y el lobby se pinta desde ahí, sin campos escritos a mano.

Ver [`rule-set-seam.md`](rule-set-seam.md) para la forma completa del interfaz.
