# Diferido a propósito (no es un olvido)

> Estado: vivo. Cada entrada se borra cuando la pieza se construye.

El estándar del equipo pide algunas piezas que este proyecto **todavía** no
necesita. Construirlas sin un consumidor sería código especulativo, que la skill
de TDD prohíbe explícitamente. Se anotan aquí para que una auditoría las lea como
decisión y no como descuido.

| Pieza | Por qué no está | Cuándo entra |
|---|---|---|
| **`ApiResponse::paginated()`** | No hay una sola lista paginada en el producto. Una partida devuelve un snapshot completo; los asientos son 3-15 y viajan dentro de él. Escribir una factoría de paginación sin nada que paginar es inventarse un contrato. | Si alguna vez aparece un listado (un historial de partidas, por ejemplo). El sobre ya reserva `meta` para ello. |
| **`ApiFormRequest`** | Es la clase base de los FormRequest, y todavía no hay ningún FormRequest: no hay endpoints que acepten cuerpo. La base saldrá del primero de verdad, no de adivinar qué necesitará. | **Fase 2**, con `CreateGameRequest`. Ahí nacen `$stopOnFirstFailure`, `messages()` y `toCommand()`. |
| **Buses con handlers registrados** | `DomainServiceProvider::registerCommands()` y `registerQueries()` están vacíos porque no hay ningún caso de uso aún. El cableado sí está probado (`ContainerWiringTest`). | **Fase 2**, con `CreateGame` y `GetGameState`. |
| **Dockerfile y docker-compose** | Golden rule 4 no está waivada y esto **se construirá**. Pero el kernel no toca base de datos, Docker no está disponible en la máquina de desarrollo ahora mismo, y enviar infraestructura que no se puede levantar ni verificar es exactamente lo que la auditoría del proyecto viejo encontró. | **Arranque de la fase 2**, que es cuando aparece la tabla `games` y Postgres se vuelve real, y por tanto cuando el compose se puede probar de verdad. |

## Lo que NO está aquí, porque sí se hizo

`ApiResponseMiddleware`, el limitador `throttle:api`, `config/cors.php`, las
plantillas `.env.example.<env>` y la configuración de PostgreSQL estaban en esta
lista y salieron de ella: eran hallazgos de la revisión del kernel, no diferimientos.
