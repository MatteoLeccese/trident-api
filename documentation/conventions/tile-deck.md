# El mazo en código: `TileDeck`, `TilePool` y la ficha como string

> Estado: **decidido** por el autor, 2026-09-17. El mazo como **regla** está enunciado en
> [`trident-rules.md`](trident-rules.md), TR-01 a TR-05. Este documento no lo repite: fija cómo se
> construye, dónde vive y qué trampas de implementación tiene.

## La ficha es un string de dos caracteres

`Tile` guarda su identidad como la cadena de dos caracteres de sus caras, izquierda y luego derecha:
`Tile::of(2, 1)->value() === '21'`. Ése es el valor que se persiste y el que viaja por el cable
(TR-02). La notación `2|1` es de prosa y de interfaz, y no aparece en ningún payload ni en ninguna
columna.

Consecuencias que conviene tener delante al escribir código:

- **`'01'` y `'10'` son fichas distintas** (TR-02). Una regla que sólo mire `total()` o si una cara
  concreta está presente las trata igual; sólo se distinguen si alguna regla lee las dos caras de
  forma asimétrica. `trident.v1` las lee en orden (TR-39), así que el orden importa desde el primer
  reto.
- **Un dominó doble-seis físico tiene 28 fichas, no 49.** Éste no es un dominó físico: no se encadenan
  extremos, se cogen fichas. Cualquier constante `28`, cualquier bucle `for ($r = $l; ...)` y
  cualquier arte de 28 piezas es un defecto.
- **Entre los 49 pares ordenados hay exactamente un `'33'`** (TR-03), y de ahí sale toda la aritmética
  de la elección: está en [`trident-rules.md`](trident-rules.md) §10 y no se recalcula en otro sitio.

`TileDeck::standard()` es el **único** sitio que construye el conjunto, en orden fijo. La
aleatoriedad es del barajado, para que una semilla dada produzca siempre la misma partida.

## El mazo no es el pool

`TileDeck` es el **conjunto** de fichas de un stage. `TilePool` es su **materialización barajada**:
una lista ordenada de posiciones 1-based, cada una con una ficha, marcadas cuando alguien las coge.
La costura pide el mazo con `RuleSet::deck(GameContext $c)` y el framework lo baraja con la semilla de
la partida.

Tres consecuencias de esa separación:

- **La identidad de una cogida es `(stage, posición)`, nunca la ficha** (TR-04). Cada stage construye
  un pool nuevo desde un mazo nuevo, así que el `'33'` puede salir en la elección y otra vez en el
  juego principal. Cualquier código que suponga que una ficha es única dentro de una **partida** es un
  defecto; dentro de **un** stage sí lo es, porque el mazo no tiene duplicados.
- **Las caras son del servidor mientras el ruleset las oculte.** El mazo completo vive en la fila
  `games` y la proyección emite `tile: null` en las posiciones sin coger mientras la visibilidad del
  stage es `faces_hidden_until_taken` (TR-07). Ver [`rule-set-seam.md`](rule-set-seam.md).
- **El tamaño del pool es una decisión del ruleset**, expresada por el mazo que devuelve `deck()`.
  Nunca un parámetro de recorte en el framework: un tamaño de mazo es una regla.

## Si alguna vez cambia

Cambiar el conjunto es reemplazar la construcción de `TileDeck::standard()`, o devolver otro mazo
desde el `deck()` de otro ruleset; no hay migración de por medio, porque el **pool** se guarda por
partida como jsonb y las partidas en vuelo conservan el suyo.

**El mazo no sale de `config()`.** Ninguna clave de despliegue decide qué fichas hay, y
`config/trident.php` **no declara ninguna clave de mazo**: una partida en vuelo queda fijada a su
ruleset por `games.rule_set_id`, y su mazo sale de ahí igual que cualquier otra regla. Una clave de
configuración que cambiase el mazo cambiaría el significado de una partida que ya está sobre una mesa.
