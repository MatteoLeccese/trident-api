# Configuración de sala y retos — ajustes, no reglas

> Estado: **decidido** 2026-09-17. Complementa [`rule-set-seam.md`](rule-set-seam.md), que fija dónde
> para la costura. Las reglas que esta configuración no puede tocar están enunciadas en
> [`trident-rules.md`](trident-rules.md); aquí se citan por número.

## Qué es un ajuste

Un **ajuste de sala** es un valor que la mesa elige antes de empezar y que **no puede cambiar el
resultado de la partida**. Ésa es toda la prueba. Si cambiarlo cambia quién es el trident, cuándo
acaba un stage, qué ficha puedes coger o cuándo se dispara un reto, no es un ajuste: es una regla, y
una regla sólo puede vivir detrás de `RuleSet`.

La frontera pasa por dentro de los retos, y conviene decirla de una vez: **cuándo se dispara un reto
es una regla; qué dice el reto es un ajuste.** Cuántos retos dispara una ficha, en qué orden y a quién
apuntan lo decide el ruleset (TR-38, TR-39, TR-44, TR-47). Las frases las escribe la sala, y
reescribirlas todas no cambia una sola cogida (TR-53).

Debajo de las dos hay una tercera capa: la **presentación**, que vive entera en el cliente y no llega
a la API. Cómo se dibuja una posición cogida, la animación de volteo y la transición de traspaso del
móvil son presentación.

## El espacio de claves es plano y punteado

**La configuración de sala es un mapa plano de claves punteadas a valores escalares. Nunca objetos
anidados.** La misma cadena literal es la clave del spec, la clave del blob guardado, la clave del
`room_config` proyectado en el snapshot y el `config_key` que viaja dentro de un efecto:

```json
"room_config": {
  "challenge.face.0": "…", "challenge.face.1": "…", "challenge.face.2": "…",
  "challenge.face.3": "…", "challenge.face.4": "…", "challenge.face.5": "…",
  "challenge.face.6": "…",
  "drawn_tiles.election": "keep", "drawn_tiles.main": "remove"
}
```

Son **siete** claves de reto, una por cara, y ninguna clave de reto está indexada por ficha (TR-43).

Una clave plana es lo único que hace **genérico** al validador del framework: con objetos anidados, el
framework tendría que entender una jerarquía que el ruleset se inventó, y entender la jerarquía de un
ruleset es conocer una regla. También es lo que permite que `ChallengeCard` resuelva un efecto con un
acceso directo, sin recorrer un árbol cuya forma no conoce.

`Effect::challenge(?SeatNumber $target, string $configKey)` lleva **esa misma cadena**, y se serializa
como `"config_key": "challenge.face.3"` (TR-42). El nombre es `config_key` y no `challenge_key` porque
la clave direcciona toda la configuración de sala, no sólo los retos: `drawn_tiles.election` no es un
reto.

## Las claves de hoy

| Clave | Tipo | Default | Qué gobierna |
|---|---|---|---|
| `challenge.face.0` … `challenge.face.6` | `text`, tope 80 | **lo entrega el autor** | lo que la aplicación anuncia por cada cara que sale |
| `drawn_tiles.<stageId>` | `choice`, `keep` \| `remove` | `keep` en `election`, `remove` en `main` | si una ficha cogida se queda volteada en el tablero o se retira de la vista |

**Los siete textos por defecto los entrega el autor** (TR-53). Hasta que los entrega, las constantes de
`trident.v1` llevan marcadores en inglés de la forma y la longitud correctas: son marcadores nuestros,
no contenido inventado que alguien pueda confundir con la configuración final. El default declarado
nunca es vacío (TR-51).

Uno de los siete ya está entregado: el de la cara 1 es *"Bebe x persona"*, dado por el autor al
explicar el disparador. Queda como marcador hasta que lleguen los siete juntos, porque un default en
castellano entre seis en inglés se lee como un descuido y no como una entrega.

Ninguna de las dos familias es una regla:

- `drawn_tiles.*` es **presentación gobernada por un ajuste** (TR-52). Una posición cogida no se puede
  volver a coger con ninguno de los dos valores, y el snapshot emite **los mismos bytes** con ambos:
  lo único que cambia es si el tablero la sigue dibujando volteada o deja un hueco inerte que conserva
  la geometría. Ningún PHP de dominio lo lee.
- `challenge.*` es **contenido**. El ruleset decide qué reto se dispara; el texto es de la mesa. Beber
  es el contenido por defecto, no el mecanismo: un grupo que quiera usar la idea para otra cosa
  reescribe las siete cajas. No hay "packs" ni selector de packs.

El **default** de `drawn_tiles` lo pone el ruleset, por stage, no el framework: depende del stage, y
un `StageId` es un string opaco que sólo el ruleset entiende. La sala **sobrescribe** ese default; no
lo inventa, y ningún fichero del cliente contiene el nombre de un stage.

## Quién declara qué ajustes existen

El ruleset. `RuleSet::roomConfigSpec(): RoomConfigSpec` devuelve una lista ordenada y **declarativa**
de `RoomConfigField`:

```php
RoomConfigField::text(string $key, string $label, string $default, int $maxLength)
RoomConfigField::toggle(string $key, string $label, bool $default)
RoomConfigField::choice(string $key, string $label, array $options, string $default)
```

De ahí salen las dos propiedades que son el punto entero del diseño:

- **El framework valida sin entender.** Un validador genérico comprueba tipo, dominio y longitud
  contra la spec. Ningún ruleset escribe su propio validador, y ninguna clase del framework sabe qué
  es un "reto".
- **El lobby se pinta desde la spec.** El formulario no lleva campos escritos a mano: renderiza lo que
  la spec declara, con su etiqueta. La spec viaja dentro del snapshot mientras `status === lobby` y
  desaparece después — oculto **por estado, nunca por espectador**, igual que las caras del pool
  (TR-06, TR-07).

Un ruleset futuro que quiera un segundo modo de tablero, o un ajuste que hoy no existe, lo declara
como **una entrada más en esa lista**. No es una columna, ni un endpoint, ni una rama en React.

## Quién valida, y qué pasa con una clave rara

Dos direcciones, dos políticas opuestas, y la asimetría es deliberada:

| Situación | Qué pasa |
|---|---|
| **Envío** con una clave que la spec no declara | **`422`** nombrando la clave. Nunca se descarta en silencio: alguien escribió algo, tocó guardar, y tiene derecho a saber que no se guardó. |
| **Envío** con un valor malformado (tipo, opción fuera del conjunto, texto demasiado largo) | **`422`** nombrando la clave. Nunca se coacciona. |
| **Envío** sin una clave declarada | Se rellena con el default de la spec. La ausencia siempre es legal. |
| **Lectura** de un blob guardado al que le falta una clave declarada | Se rellena con el default al proyectar, así que el cliente puede dar por hecho que **toda clave declarada está presente**. |
| **Lectura** de un blob guardado con una clave que la spec ya no declara | Se ignora al leer y **se conserva al escribir**. |

La regla corta: **formulario estricto, lector tolerante, escritor conservador.** Una persona viva se
puede corregir; una fila guardada es historia y no se reescribe.

`CreateGameRequest` valida **sólo la forma exterior** —que `room_config` sea un objeto plano de
escalares y no supere un tamaño máximo—; toda interpretación vive en el objeto de valor. Si un
`FormRequest` nombrase `challenge.face.0` o `drawn_tiles.main`, el esquema de un ruleset estaría
viviendo en la capa HTTP, que es el mismo fallo que una columna con nombre de regla.

## Una partida nacida antes de que la clave existiera

No se migra, no se rompe y no se toca. La partida quedó fijada a su ruleset en `games.rule_set_id`, y
ese ruleset es el que declara su spec: una clave que su spec no declara no se le pide. Un ruleset
nuevo declara sus claves nuevas, y ninguna partida vieja lo usa. Y si la clave nueva llega dentro del
mismo ruleset, el blob guardado la tiene **ausente**, que es exactamente el caso "se rellena con el
default al proyectar".

Por eso `games.room_config` **no lleva `_v` y no termina nunca una partida**, mientras
`games.rule_state` sí lo lleva y un desajuste la termina. La asimetría es deliberada: `rule_state` lo
escribe una máquina, está acoplado estructuralmente, y leerlo mal corrompe una partida en curso;
`room_config` lo escriben personas, es plano, y **cada clave tiene un default independiente**, así que
no hay nada que migrar y un guardián de versión sólo fabricaría un modo de fallo — matar la partida de
un salón porque una frase cambió de sitio.

Si el significado de un ajuste cambiase de forma incompatible, se declara **una clave nueva**, y la
vieja conserva su significado para las partidas viejas.

## Dónde viven

**Una sola columna jsonb, `games.room_config`, `NOT NULL DEFAULT '{}'`.** No una tabla
`game_challenges`, no columnas tipadas, no `config()`.

- Una tabla `(game_id, challenge_key, text)` codifica *"un reto es una cadena indexada por una
  cadena"*, que es semántica de regla en el esquema. Un ruleset que quisiera dos textos o una duración
  necesitaría un `ALTER TABLE`.
- Columnas tipadas (`challenge_pip_3`, `remove_taken_tiles`) son lo mismo, peor: llevan el nombre de
  la regla escrito en el esquema.
- `config()` es por despliegue, no por partida, y el dominio no puede llamarlo —`ArchitectureTest`
  prohíbe el literal `config(` bajo `src/*/Domain`—. Los defaults son constantes del propio ruleset.
  Además, resolver un "pack" por nombre en tiempo de lectura dejaría que editar un fichero de
  configuración cambiase el significado de una partida que ya está sobre una mesa, que es justo lo que
  `RuleSetResolver` evita leyendo `games.rule_set_id`.

**Por eso se sostiene el criterio de cero migraciones:** un ajuste nuevo es una entrada de
`roomConfigSpec()` y una clave más dentro de un blob que la base de datos nunca lee ni indexa. El
`git diff --stat database/migrations/` de la fase que lo introduce sigue vacío.

## Cuándo se puede tocar

Editable mientras `status === lobby`; congelada al empezar. El autor la sitúa *"en la sala antes de
entrar"*, y una mesa que escribió mal un reto debe poder arreglarlo sin recrear la partida y reteclear
seis nombres. La mutación es `POST /api/v1/games/{gameId}/room-config`, dentro del grupo de
`VerifyControllerToken`, `422` en cuanto la partida sale del lobby, y registra un movimiento como
cualquier otra escritura —con su fila en el barredor de mutaciones en el mismo commit, según la
convención de [`credential-model.md`](credential-model.md)—. Una edición que no cambia nada **no sube
la versión y no emite nada**: la versión sube de una en una y sólo cuando algo cambia de verdad.

## El texto lo escriben los jugadores, y eso tiene consecuencias

El reto es contenido no confiable escrito por un invitado y pintado **a 96px en un televisor** que
fotografía todo el salón. El límite de longitud y el escapado son parte de la regla, no un remate:

- **Se acota a 80 caracteres**, contados con `mb_strlen`, y el tope viaja en la spec
  (`RoomConfigField::text(..., maxLength: 80)`) para que el lobby lo pinte y el validador lo aplique
  desde la misma fuente. A 96px, el área segura de un televisor de 1080p entra a unos 36 caracteres
  por línea: 80 caracteres son tres líneas, que es lo que cabe sin empujar nada fuera de pantalla.
- **Vacío es legal** y significa "esta cara no hace nada". Un reto vacío no pinta tarjeta. El default
  declarado, en cambio, nunca es vacío (TR-51): vaciar una caja es una decisión de la mesa.
- **Se rechazan los caracteres de control** (`\p{C}`), con la misma guarda ya probada en `Nickname`.
  **No** se rechazan las marcas combinantes: hebreo con niqqud, árabe vocalizado y conjuntos índicos
  legítimos llegan a tres seguidas. Esto corre en una LAN doméstica entre amigos; el adversario que
  justificaría esa guarda no existe, y la guarda sí rechazaría nombres y frases reales.
- **Se pinta como texto, nunca como HTML.** `dangerouslySetInnerHTML` no aparece en ninguno de los dos
  repositorios y no debe aparecer. El riesgo residual es de maquetación, no de inyección: se resuelve
  con `overflow-wrap: anywhere` y tamaño en `clamp()`, **nunca truncando** — truncar el reto esconde
  exactamente lo que la aplicación existe para anunciar.
- **El texto de la sala no entra en la lista de subcadenas prohibidas del snapshot.** Esa lista vigila
  credenciales; un reto que contenga la palabra *hash* haría fallar un test de credenciales por una
  razón que no tiene nada que ver. Los secretos se afirman **por valor**, no por subcadena.

Un reto que apunta al trident (TR-44) se pinta con el nickname de su asiento, que el snapshot ya
lleva. El texto no nombra a nadie: el destinatario viaja en el efecto, no dentro de la frase.

## La configuración viaja en el snapshot

Congelada, plana y ya resuelta con sus defaults. No en la respuesta de creación: un televisor entra
por `JoinCode` y nunca vio esa respuesta, así que necesitaría una segunda petición y un segundo modo
de fallo, rompiendo la proyección única y el *"incorporación tardía, reconexión y televisor dormido
son el mismo caso"* de [`state-versioning.md`](state-versioning.md).

**El cliente no aplica defaults propios.** Si lo hiciera, un móvil y un televisor con bundles
distintos podrían discrepar sobre lo que dice la mesa.

La configuración es **pública por construcción**: se pinta en un televisor que fotografía todo el
salón. Nunca puede llevar nada que se parezca a una credencial.

`tv_idle_notice_minutes` viaja en el mismo snapshot **por el mismo argumento**, y **fuera de
`room_config`**: es un valor por despliegue y no lo elige la mesa. Su sitio exacto en la proyección lo
fija [`state-versioning.md`](state-versioning.md).

## Lo que NO es un ajuste de sala

- **El aviso de inactividad del televisor.** Es un valor **por despliegue**, no por partida: vive en
  `config/trident.php` como `tv_idle_notice_minutes`, el backend lo entrega al cliente dentro del
  snapshot y se pinta en la pantalla de *watch*. No lo declara `roomConfigSpec()`, no está en
  `room_config` y la mesa no lo edita. Su única restricción: debe ser **menor que
  `idle_timeout_minutes`** —lo afirma `tests/Unit/Shared/TridentConfigTest.php`—, o el televisor manda
  a la gente a casa por una partida que el servidor aún
  no ha expirado.
- **La transición de traspaso del móvil.** Es **una sola transición, idéntica todas las veces**, no
  configurable y ausente de la API: la pinta el cliente. No hay ninguna clave, ni de sala ni de
  despliegue, que la elija.
- **Cualquier contador.** No hay marcador de nada (TR-54). El historial de qué cogió cada uno es un
  registro, no una puntuación, y muere con la partida (TR-55).
- **El mazo.** Cuántas fichas hay y cuáles es una regla del ruleset, no un ajuste:
  [`tile-deck.md`](tile-deck.md).
