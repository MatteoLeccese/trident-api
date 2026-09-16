# Reglas del Trident — lo que sólo el autor puede responder

> Estado: **sin responder** a 2026-09-15. Bloquea únicamente la fase 5.
> Ordenadas por **riesgo estructural**, no por orden narrativo: sólo las tres primeras pueden
> romper la forma del interfaz `RuleSet`. Los tragos no.

Contexto: las reglas del juego no están escritas en ningún sitio. El corpus completo que existe son
tres frases, dos de ellas ya borradas del código:

1. *"Trident is a domino-based game to play with your friends while drinking"*
2. *"Select carefully your domino so you are not the trident!"* — borrada en el commit `814867b`
3. *"Compete to be the last player sobber"*

Más dos comentarios: *"these dominoes are for the trident selection"* y que la segunda fase es
*"the real trident game"*.

De ahí se deduce, y se da por bueno: **el trident es un rol de jugador, hay exactamente uno, es
indeseable, y se elige eligiendo una ficha en una primera fase; después se rebaraja el mazo y
empieza el juego real.** Todo lo demás está abierto.

---

## Estructurales — pueden cambiar la forma del interfaz

**1. ¿Hay información oculta a los demás jugadores (una mano privada)?**
> Respuesta:

Decide si basta una proyección de estado o hacen falta dos.
*Mitigado:* `ControllerGameSnapshot` y `game_seats.private_state` existen sin usar desde la fase 2,
así que un sí cuesta un campo de proyección, no una migración.

**2. ¿Alguien actúa alguna vez simultáneamente, o es estrictamente un asiento cada vez?**
> Respuesta:

Decide si `current_seat` es un cursor único o un conjunto. Un sí es un día de trabajo confinado al
agregado, porque nada por encima lee el cursor salvo la proyección.

**3. ¿Un turno exige alguna vez input de otro jugador (votar, señalar una víctima)?**
> Respuesta:

Decide si hace falta `Outcome.pendingChoice` + `status = awaiting_choice`.
*Mitigado:* el `HostileRuleSet` de la fase 3 prueba que el interfaz lo aguanta antes de que llegue
esta respuesta.

---

## De reglas — no cambian ninguna estructura

**4. ¿Qué propiedad de la ficha elegida te convierte en trident?**
> Respuesta:

¿Una cara concreta (¿algo con un 3, dado el nombre de tres puntas?), la suma de pips más alta o más
baja, el doble, la última que queda? Bloquea sólo la rama de elección.

**5. En la fase de elección, ¿todos cogen exactamente una ficha, o se sigue cogiendo hasta que
aparece el trident?**
> Respuesta:

Con 3-15 jugadores y 49 fichas, la mayor parte del mazo no se toca nunca. ¿Es intencionado, o el
mazo debería dimensionarse al número de jugadores?

**6. ¿Qué hace el trident realmente en la segunda fase?**
> Respuesta:

Una vez eres el trident, ¿qué cambia para ti y para los demás durante el resto de la partida?
Ésta *es* el juego. No bloquea nada estructural — es `Effect[]` — pero sin ella no hay juego.

**7. ¿Quién bebe, cuánto, y con qué disparador? ¿Alguien queda fuera alguna vez?**
> Respuesta:

*"Compete to be the last player sobber"* implica desgaste, pero no existe ningún contador de tragos,
marcador ni flag de eliminación.

**8. ¿El mazo son 49 fichas (todos los pares ordenados, donde 01 y 10 son dos tiradas distintas) o
28 (el dominó doble-seis real)?**
> Respuesta:

*Por defecto se envía 49* para que nada se pare, pero **no está decidido**. Ver
[`../conventions/tile-deck.md`](../conventions/tile-deck.md). Cambiarlo es una variable de entorno y
sólo afecta a partidas nuevas.

**9. ¿El trident abre la segunda fase, o la abre otro?**
> Respuesta:

El código viejo reseteaba el cursor al primer asiento y descartaba el índice del trident. ¿Regla u
olvido?

**10. ¿El mazo de la fase principal se agota o se recicla?**
> Respuesta:

**11. ¿Se elimina a jugadores, o todos juegan hasta el final?**
> Respuesta:

Deliberadamente **no construido** por adelantado: `Outcome.overrideNextSeat` ya expresa el salto, y
hornear una eliminación adivinada en el agregado es peor que esperar.

**12. ¿Algo depende de la longitud del nickname?**
> Respuesta:

Los hemos cambiado de 4-40 a **2-24** a propósito (ver `waived-golden-rules.md`). Sólo necesito
confirmación.

---

## Y cómo vamos a conseguir estas respuestas de verdad

Este cuestionario **no es el mecanismo principal**, y planificar como si lo fuera es repetir el
fallo. Un diseñador de juegos suministra reglas viendo a gente jugar mal a su juego y corrigiéndolas
en voz alta.

> **El mecanismo principal es la partida de prueba de la fase 3, con el autor en la habitación,
> jugando `sandbox.v1`, y el acta de lo que grite como entregable.** Cada *"no, no — cuando pasa eso
> bebes"* se convierte en una línea numerada de `trident-rules.md`.

Una propuesta jugada que pueda corregir tiene mucha más probabilidad de producir respuestas que un
documento que tenga que redactar.
