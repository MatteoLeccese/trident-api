# La costura `RuleSet` — el único sitio donde puede vivir una regla

> Estado: **decidido** 2026-09-10. Criterio de éxito declarado al final de este documento.

## El problema que resuelve

Las reglas del Trident no están escritas en ningún sitio (ver `../rules/QUESTIONS.md`). El plan tiene
que producir trabajo real durante semanas **sin** esas respuestas, y absorberlas después **sin
rehacer nada**. Un plan que se para a esperar es malo; un plan que adivina las reglas y las hornea en
el esquema es peor.

## La regla

**Una regla del juego sólo puede vivir detrás del interfaz `RuleSet`. El esquema de base de datos no
codifica ninguna semántica de regla.**

```php
interface RuleSet {
    public function id(): string;                 // "sandbox.v1" | "trident.v1"
    public function stateVersion(): int;          // versión de esquema de $ruleState
    public function stages(): StageSequence;      // StageId[] ordenados + etiquetas humanas
    public function deck(StageId $stage): TileDeck;
    public function onGameStarted(GameContext $c): Outcome;
    public function onTileDrawn(DrawContext $c): Outcome;
    public function isStageComplete(GameContext $c): bool;
    public function onStageComplete(GameContext $c): Outcome;
    public function isFinished(GameContext $c): bool;
}
```

El agregado valida sólo lo que **él** posee — status, "es tu turno", la ficha está en el mazo, la
partida no ha terminado — y entonces llama al objeto de reglas y aplica los efectos devueltos.
**Ése es todo el acoplamiento con lo desconocido.**

## Las entradas son DTOs a medida — ni el snapshot ni el agregado

Ésta es la decisión más importante del documento.

```php
DrawContext {
    SeatNumber $seat, Tile $tile, ?SeatNumber $tridentSeat, SeatRing $seats,
    DrawLog $priorDraws, TilePool $poolBefore, int $turnNumber, StageId $stage, RuleState $state
}
```

- **Pasar el `GameSnapshot`** (la proyección de cable) haría que cualquier regla que necesite
  información oculta o histórica forzase un cambio en la clase cuyo único trabajo es **ser segura de
  emitir a un televisor**.
- **Pasar el agregado entero** convertiría la superficie real de la costura en el modelo completo,
  que es lo mismo que no tener costura.

## La salida es un vocabulario cerrado

`Outcome`: `Effect[] $effects`, `?SeatNumber $overrideNextSeat`, `?StageId $nextStage`,
`array $ruleStatePatch`, `bool $finished`, `?FinishReason $reason`.

`Effect` es **declarativo y cerrado**, y la UI lo pinta sin conocer ninguna regla. Se persiste como
jsonb en `game_moves.payload`:

```
Effect::announce(string $messageKey, array $params)
Effect::assignRole(SeatNumber $seat, string $role)      // "trident" es sólo un string de rol
Effect::drink(SeatNumber $target, int $sips, string $reason)
Effect::skipTurn(SeatNumber $seat)
```

**Por eso las reglas son enchufables con cero migraciones:** una regla sorprendente llega como una
constante nueva de `EffectKind` más una rama en `EffectBanner.tsx`. Nunca un `ALTER TABLE`.

## El estado opaco va versionado

`games.rule_state` jsonb lleva siempre `_v = RuleSet::stateVersion()`. Un ruleset que lee un blob
escrito por una versión anterior de sí mismo y no puede migrarlo **termina la partida** con
`FinishReason::RULESET_UPGRADED` y emite un snapshot final, en vez de malinterpretarlo en silencio.

Evolucionar clases sobre jsonb opaco es un camino clásico de corrupción silenciosa; esto es la
guarda de dos líneas contra él.

## Un ruleset por partida, fijado al nacer

`RuleSetResolver` lee **`games.rule_set_id`**, no `config()`. Una partida en vuelo queda fijada al
ruleset con el que nació. La fase 5 cambia el *default* para partidas nuevas; **no debe cambiar el
significado de una partida que ya está sobre una mesa.**

## El test de doblado: un ruleset hostil que tiene que ejecutarse

`tests/Unit/Game/Doubles/HostileRuleSet.php` — un ruleset sólo-de-test que **exige una elección del
jugador a mitad de turno**, y que debe correr por los mismos handlers sin cambios.

Variantes de reglas ficticias esbozadas en papel por la misma persona que diseñó el interfaz siempre
encajan. Una implementación hostil que tiene que **ejecutarse de verdad**, no. Si no puede correr, el
interfaz se arregla **en la fase 3**, mientras hay una sola implementación que migrar.

La forma esperada de ese arreglo: `Outcome` gana un `PendingChoice` y `status` gana `awaiting_choice`.
Presupuestada una tarde.

## Criterio de éxito, declarado por adelantado

> Al terminar la fase 5, `git diff --stat database/migrations/` de esa fase está **vacío**.

Cualquier otra cosa es un **fallo de diseño de esta costura**, y se registra aquí con su causa. No se
tapa.
