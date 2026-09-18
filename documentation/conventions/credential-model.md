# Modelo de credenciales — por qué son dos y no una

> Estado: **decidido** 2026-09-10. Decisión de un solo sentido. No re-litigar.

## La frase que gobierna todo este documento

> **La credencial de espectador se muestra en un televisor y la fotografía cada invitado, por tanto
> nunca puede ser una credencial de escritura.**

Si alguna tarde futura te apetece "simplificar esto a un único token": esa frase es la razón de que
no. Un salón con ocho personas y un móvil que cambia de mano es un entorno donde la pantalla grande
es pública por definición.

## Las dos credenciales

| | Credencial | Dónde vive | ¿Escribe? |
|---|---|---|---|
| **Televisor / espectador** | el `GameId` (UUID) en el enlace, más un `JoinCode` de 6 caracteres para teclear | la URL, un QR en el móvil | **nunca** |
| **Móvil / controlador** | `controller_token`, 32 bytes aleatorios | una cookie **httpOnly** puesta por el BFF de Next | sí |

- `games.controller_token_hash = hash('sha256', $token)`, comparado con `hash_equals`.
- El token en claro sale por **dos rutas, y sólo dos**: `POST /api/v1/games`, que abre la partida, y
  `POST /api/v1/games/{gameId}/play-again`, que abre la siguiente con la misma mesa. En las dos, **el
  BFF de Next lo consume y lo quita antes de que la respuesta llegue al JS del navegador**,
  escribiéndolo en la cookie `trident_controller` (`httpOnly`, `secure` en producción,
  `sameSite=lax`). `play-again` **rota** la cookie: el token de la partida anterior deja de escribir
  en cuanto existe la nueva. Por eso la API **cierra** esa partida en la misma unidad de trabajo que
  abre la siguiente: su token ya no existe en ningún sitio salvo como hash, ninguna ruta lo vuelve a
  emitir, y dejarla viva sería dejar una partida que nadie puede escribir ocupando su `JoinCode` hasta
  que expire.
- Por eso ninguna de esas dos puede pasar por el proxy genérico `/api/proxy/[...path]`, que devuelve
  el cuerpo del backend tal cual y entregaría el token al navegador. Cada una tiene su propia ruta de
  BFF —`/api/games` y `/api/games/[gameId]/play-again`— con el mismo trabajo: guardar el token en la
  cookie y responder sin él. **Una ruta que emita el token en claro sin ruta de BFF propia es un
  defecto.**
- El token **nunca** entra en una URL, un QR, el historial del navegador, una pantalla compartida, un
  payload de broadcast ni un frame de Reverb.
- La cookie es además el asidero de reanudación: `GET /api/session` devuelve `{game_id, role}`. Eso
  arregla gratis el *"un refresco pierde la partida para siempre"* del sistema viejo.

## Nadie escribe nunca por el socket

Toda mutación es un `POST` HTTPS autenticado por el token de controlador. **El WebSocket es un canal
de lectura de un solo sentido.** Por tanto la autorización de canal sólo responde a *"¿puedes mirar
esta partida?"*, y lo peor que pasa si te equivocas es que un desconocido vea un dominó.

Esa premisa se **enforza, no se afirma**. Cómo, exactamente — cada punto verificado contra el código
de `laravel/reverb` 1.11.1, que es la fuente que vale para estas tres claves:

- **`enable_client_messages` no existe.** No aparece en ninguna parte de Reverb. La puerta real es
  `apps.*.accept_client_events_from`, y el peligro está en su default: **si la clave falta, vale
  `'all'`** (`ConfigApplicationProvider.php:74`). Hay que fijarla explícitamente a `none`, vía
  `REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM=none`.
- **`REVERB_ALLOWED_ORIGINS` no existe.** `config/reverb.php:85` es el literal `'allowed_origins' => ['*']`,
  sin `env()`. Hay que editar el fichero publicado a mano para hacerlo configurable.
- Las entradas de `allowed_origins` son **hosts pelados** (`192.168.1.50`), nunca URLs con esquema ni
  puerto: el servidor compara contra `parse_url($origin, PHP_URL_HOST)` (`Protocols/Pusher/Server.php:190-207`).
  Admite patrones glob, así que `192.168.1.*` es válido para una LAN.

## El `JoinCode`

Seis caracteres de **Crockford base32** (alfabeto sin ambigüedades: sin I, L, O ni U).
`GET /api/v1/games/by-code/{code}` va con `throttle:20,1`. Se libera cuando la partida termina o
expira.

No es un base62 de 22 caracteres ni un UUID crudo: **eso no se puede teclear con el mando de un
televisor**, y teclearlo es uno de los tres caminos soportados para que la pantalla grande entre.

## No hay relevo de controlador

El móvil que abre la partida es el único que puede escribir en ella, y **no existe ningún camino para
transferir esa capacidad**: ni un código de relevo, ni un segundo móvil, ni un botón en el televisor.
El televisor **sólo observa**; ninguna credencial suya escribe jamás, que es la frase que gobierna
este documento.

Si el móvil se muere a mitad de partida, esa partida se queda mirable en el televisor, expira por
inactividad y el grupo crea otra. Eso es la decisión completa del autor, no un hueco pendiente: **no
se construye nada** para ese caso, ver [`deferred-on-purpose.md`](deferred-on-purpose.md).

`GameSnapshotTest` afirma que el snapshot no contiene la subcadena `takeover`, junto a `token`,
`secret` y `hash`. **La lista de subcadenas prohibidas es una regla sobre el snapshot, no sobre las
funciones que las acuñaron**: caza cualquier credencial que alguien proyecte con uno de esos nombres,
así que una entrada se queda aunque la función que la sugirió no se construya.

## Lo que se descartó, y por qué

El protocolo `controller_session_id` / `X-Controller-Session` / `409 controller_claimed_elsewhere`.
El token ya vive en una cookie httpOnly que el JS no puede leer; el escenario de dos controladores
silenciosos que defiende exige un atacante que **ya tiene la cookie**, y compra una ruta de error de
escritura contendida en el endpoint más caliente de la mesa para defender una carrera que no puede
ocurrir.

## Cómo se vigila

- El **barredor de mutaciones**, hoy
  `GameEndpointsTest::test_every_mutating_game_route_demands_the_controller_token`: itera
  `Route::getRoutes()` y exige que **toda** ruta no-GET de `/api/v1/games/*` lleve
  `VerifyControllerToken`. La única excepción registrada es `POST /api/v1/games`, que es la ruta que
  emite el token; `POST /api/v1/games/{gameId}/play-again` **no** es excepción, porque exige el token
  de la partida en curso para emitir el de la siguiente. Sus hermanos del mismo fichero afirman los
  códigos: `401 controller_token_required` sin token, `403 controller_token_invalid` con uno erróneo.
  **Convención: una ruta mutante nueva y su fila en este test entran en el mismo commit.**
- **Convención en el web:** una ruta de BFF que consuma el token en claro entra con su test de que la
  respuesta que llega al JS no lo contiene. Hoy falta el de `src/app/api/games/route.ts`, que existe
  sin test: **la convención está rota** y la salda `src/app/api/games/route.test.ts`. El de
  `play-again` nace con su ruta.
- `GameSnapshotTest::test_it_never_carries_a_credential` — el snapshot, en minúsculas, **nunca**
  contiene las subcadenas `token`, `secret`, `hash` ni `takeover`, y tampoco el token en claro ni su
  hash por valor.
- Comprobación local, a mano, antes de cerrar cualquier cambio del web:
  `! grep -rE 'NEXT_PUBLIC_[A-Z_]*SECRET' trident-web/src`. **No hay CI que la ejecute**, igual que no
  la hay para Pint, PHPUnit, ESLint, `tsc --noEmit` ni Vitest: son comandos que se corren en la
  máquina. Un `NEXT_PUBLIC_*` con un secreto dentro viaja al bundle del navegador, y el bundle lo lee
  cualquiera que esté en la mesa.
