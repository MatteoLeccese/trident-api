# El mazo: 49 fichas por defecto — y por qué eso NO está decidido

> Estado: **por defecto, pendiente de respuesta del autor.** Pregunta 8 de `../rules/QUESTIONS.md`.

## El hecho

`DominoService::generateShuffledTiles()` del sistema viejo generaba **49 fichas**: todos los pares
ordenados de dígitos 0-6 (7 × 7). Verificado contando el array literal.

**Un dominó doble-seis real tiene 28 fichas.** La diferencia no es cosmética:

- En un mazo de 49, `"01"` y `"10"` son **dos tiradas distintas**.
- Por tanto **cada ficha no-doble es el doble de probable que un doble**.

Si alguna regla del Trident depende de dobles, de sumas de pips o de la rareza de una cara, el mazo
de 49 cambia el juego. Si el nombre "trident" tiene algo que ver con el 3, también.

## La decisión provisional

**Se envía `ordered_pairs_49` por defecto**, generado por `TileDeck::for(config("trident.deck.mode"))`,
y guardado por partida como un array jsonb.

Enviar un default para que nada se pare es correcto. **Escribir "se decidió" en un documento de
convenciones sería incorrecto**, y es justo cómo el autor nunca vuelve a ser preguntado.

## Coste de cambiarlo

Mínimo, y así se ha diseñado a propósito:

- `TileDeck` es un value object; los dos modos (`ordered_pairs_49`, `double_six_28`) son dos ramas de
  una factoría.
- El mazo se **elige por `RuleSet::deck(StageId $stage)`**, no por el esquema.
- Cambiarlo es **una variable de entorno**, y sólo afecta a **partidas nuevas** (el mazo vive en la
  fila de la partida).
- `TileDeckTest` ya cubre ambos: 49 únicas con `"01"` ≠ `"10"`; 28 con ellas idénticas.

## Lo que sí está decidido

La **codificación** de una ficha: un string de **2 caracteres** de dígitos 0-6, donde el índice 0 y el
índice 1 son las dos caras (`"36"` = un 3 y un 6). Rescatada literal del sistema viejo porque es
compacta, JSON-safe, legible en un dump, y **ya estaba acordada en ambos lados del cable**.

Se conserva tal cual **incluso si el tamaño del mazo cambia de 49 a 28**, para que el componente
`Domino.tsx` rescatado la consuma sin ninguna modificación.
