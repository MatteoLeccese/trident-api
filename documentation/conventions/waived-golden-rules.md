# Golden rules waivadas a propósito

> Estado: **decidido** 2026-09-10. Revisar sólo si el producto deja de ser un juego de fiesta sin
> login.

Las guidelines del equipo se destilaron de un panel administrativo multi-tenant con dinero y cuentas
de usuario. Trident no tiene nada de eso. Estas reglas se waivan **conscientemente**, no por
descuido, y aquí queda por qué — para que una auditoría futura no las vuelva a levantar.

| # | Regla waivada | Por qué, y qué la sustituye |
|---|---|---|
| 1 | **Multi-tenancy completa** | No hay tenants; una partida es la única unidad de aislamiento. Su defensa de 4 capas se **transpone** al token de controlador: middleware lo resuelve → el Command tipado lleva el `gameId` explícito → `controller_token_hash NOT NULL` → el barredor de mutaciones (`GameEndpointsTest::test_every_mutating_game_route_demands_the_controller_token`). **Ninguna clase estática de contexto lo sustituye** — el rol viaja en el Command, que es lo que prescribe la propia regla 3 de la guideline, y evita recrear un riesgo de contaminación entre peticiones bajo Octane. |
| 2 | **Sanctum, cuentas de usuario, `/api/v1/auth/*`** | La premisa es un móvil sin login que se pasa por una mesa; una pantalla de login sería un defecto de producto. Sustituido por dos credenciales de capacidad por partida (ver `credential-model.md`). `app/Models/User.php` y `config/sanctum.php` se **borran**, no se dejan muertos. |
| 3 | **Golden rule 9 — dinero como enteros de céntimos** | No hay dinero, y tampoco hay divisa: **no existe contador de nada** —ni tragos, ni puntos, ni marcador— y ningún `Effect` lleva cantidad
(TR-54). La intención real de la regla, que un valor así nunca sea un float, se honra por vacío. El vocabulario de `Effect` no debe adquirir un entero acumulable que la vuelva a poner en juego. |
| 4 | **`PASSWORD_SALT` y pre-hasheo en cliente** | No hay contraseñas. El contrato no se define en ningún `.env`. |
| 5 | **`/api/auth/{login,logout,me}` del BFF** | Sustituido, no eliminado. La piedra angular del BFF se honra entera: la credencial de escritura vive sólo en una cookie httpOnly y se quita de toda respuesta al cliente, incluida la ruta de auth del WebSocket. Las rutas pasan a ser `/api/session` y `/api/games`. |
| 6 | **`audit_logs` + `AuditLogger`** | Superado por `game_moves`, que es a la vez rastro de auditoría, libro de idempotencia, contador de versión y feed de historial del televisor. |
| 7 | **Soft deletes** | Las partidas son efímeras: expiran y se podan. No se archiva nada. |
| 8 | **Postura de repositorios** *(resuelta, no waivada)* | Repositorios reales, exactamente uno (`GameRepository`). `backend.md` avisa contra medio-mezclar; con un agregado y un dominio puro, la adopción completa es un fichero. |
| 9 | **Eventos de dominio / `AggregateRoot`** | No adoptados. Las guidelines los llaman opcionales y prefieren Observers + jobs; este proyecto no necesita ninguno de los dos. |
| 10 | **Pest** | No adoptado. Una skill lo recomienda; las guidelines especifican PHPUnit y, por su propio README, **ganan las guidelines**. |
| 11 | **Entorno de staging y colección de Postman** | No existen. Esto corre en local sobre la máquina del anfitrión, así que no hay una segunda audiencia para la que hacer staging; si algún día se publica algo, se decide entonces. |
| 12 | **Server Component para el primer pintado de `/tv/[gameId]`** | Divergencia deliberada del hábito todo-`"use client"` del proyecto de referencia: el primer pintado de un televisor debe ser el juego, no un spinner, y ahí no hay cookie de auth que esperar. |
| 13 | **"El enlace inadivinable es la credencial"**, debilitado | El `JoinCode` de 6 caracteres da 32⁶ ≈ 10⁹, y su superficie de fuerza bruta, `GET /api/v1/games/by-code/{code}`, va limitada a 20/min; el código se libera al terminar la partida. Aceptado: concede visión de *sólo lectura* del mazo de un juego de beber, y teclear un UUID con el mando de un televisor no es un producto. |

## Desviaciones de producto (no son golden rules, pero se registran)

- **Nickname de 4-40 → 2-24 caracteres.** Es la regla más especificada que sobrevivió del sistema
  viejo, y aun así se cambia: *"Bo"* y *"Al"* son nombres reales que el mínimo de 4 rechaza, y quince
  nicknames de cuarenta caracteres es un layout de televisor que nadie ha resuelto. El rango 2-24 es
  el que el autor fija (TR-15), y vive en `Nickname::MIN_LENGTH` / `MAX_LENGTH` y en
  `config/trident.php`.

## Explícitamente NO waivadas

Golden rules **1, 2, 3, 4, 5, 6, 7, 8, 10, 11, 12**; el prefijo `/api/v1`; el sobre `ApiResponse` con
códigos máquina snake_case; el manejo centralizado de excepciones en `bootstrap/app.php`;
`config/trident.php` como única sede de constantes; la convención de "enums" como `final class` de
constantes; los dos roots PSR-4 con un `App\` delgado; los buses CQRS cableados en
`DomainServiceProvider`; Redis dividido durable/caché; Pint + ESLint; PHPUnit sobre SQLite en memoria.
