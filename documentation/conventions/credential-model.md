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
- El token en claro lo devuelve **exactamente una vez** `POST /api/v1/games`, y **el BFF lo quita
  antes de que la respuesta llegue al JS del navegador**, escribiéndolo en la cookie
  `trident_controller` (`httpOnly`, `secure` en producción, `sameSite=lax`).
- El token **nunca** entra en una URL, un QR, el historial del navegador, una pantalla compartida, un
  payload de broadcast ni un frame de Reverb.
- La cookie es además el asidero de reanudación: `GET /api/session` devuelve `{game_id, role}`. Eso
  arregla gratis el *"un refresco pierde la partida para siempre"* del sistema viejo.

## Nadie escribe nunca por el socket

Toda mutación es un `POST` HTTPS autenticado por el token de controlador. **El WebSocket es un canal
de lectura de un solo sentido.** Por tanto la autorización de canal sólo responde a *"¿puedes mirar
esta partida?"*, y lo peor que pasa si te equivocas es que un desconocido vea un dominó.

Esa premisa se **enforza, no se afirma**. Cómo, exactamente — verificado contra el código de
`laravel/reverb` 1.11.1, porque las dos primeras versiones de este párrafo eran falsas:

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

Seis caracteres de **Crockford base32** (alfabeto sin ambigüedades: sin I, L, O ni U). `throttle:10,1`.
Se libera cuando la partida termina o expira.

No es un base62 de 22 caracteres ni un UUID crudo: **eso no se puede teclear con el mando de un
televisor**, y teclearlo es uno de los tres caminos soportados para que la pantalla grande entre.

## Recuperación del controlador — el fallo que acaba una fiesta

El móvil muere, se limpia el navegador, otro amigo tiene que tomar el relevo.
`POST /api/v1/games/{gameId}/claim-controller` canjea un **código de relevo de 4 dígitos** y rota
`controller_token_hash`, invalidando al instante el móvil muerto.

*Quien puede leer el televisor está de pie en la habitación* es el límite de confianza correcto aquí.

Dos detalles que **no** son negociables:

1. El código se revela con un botón deliberado de **"¿se murió el móvil?"** en el televisor. **No** se
   pinta permanentemente junto al QR: una sola foto de esa pantalla sería control total permanente.
2. El endpoint va duramente limitado: `throttle:5,1` por partida.

## Lo que se descartó, y por qué

El protocolo `controller_session_id` / `X-Controller-Session` / `409 controller_claimed_elsewhere`.
El token ya vive en una cookie httpOnly que el JS no puede leer; el escenario de dos controladores
silenciosos que defiende exige un atacante que **ya tiene la cookie**, y compra una ruta de error de
escritura contendida en el endpoint más caliente de la mesa para defender una carrera que no puede
ocurrir.

## Cómo se vigila

- `AllMutationsRequireControllerTest` — itera `Route::getRoutes()` y asegura que **toda** ruta no-GET
  de `/api/v1/games/*` devuelve `401 controller_token_required` sin token y
  `403 controller_token_invalid` con uno erróneo. **Convención: una ruta mutante nueva y su fila en
  este test entran en el mismo commit.**
- `GameSnapshotTest` — el snapshot **nunca** contiene `controller_token`, `takeover_code`,
  `is_controller` ni un id interno de fila.
- Grep de CI, permanente: `! grep -rE 'NEXT_PUBLIC_[A-Z_]*SECRET' trident-web/src`.
  El repo viejo enviaba `NEXT_PUBLIC_APP_SECRET` al bundle del navegador.
