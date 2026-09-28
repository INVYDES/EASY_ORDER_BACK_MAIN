# Conexiones — EASY ORDER BACK

Guía para conectar este back (Laravel 12 + PHP 8.2) con todo el sistema: MySQL, el front Vue,
WebSockets (Reverb), WhatsApp (evolution-api), correo por Gmail API, pagos y el deploy.

---

## 1. Mapa del sistema

```
  Front (Vue 3)  ──https──►  https://eorder.mx        (Cloudflare Worker "eorder", repo front)
       │
       │  API JSON  +  wss (protocolo Pusher)
       ▼
  ┌─────────────────────────────────────────────────────────────┐
  │ ESTE REPO — Laravel 12 (PHP 8.2)                            │
  │ local: http://localhost:8000                                │
  │ prod:  https://easy-order-back-201452705980.us-central1.run.app  (Cloud Run) │
  └──────┬───────────────────────┬───────────────────────┬──────┘
         ▼                       ▼                       ▼
   ┌───────────┐          ┌──────────────┐         ┌──────────────────┐
   │ MySQL     │          │ Reverb       │         │ evolution-api    │
   │ prod:     │          │ ws :8080     │         │ docker :8080     │
   │ Railway   │          │ (prod: wss   │         │ panel :3000      │
   │ (proxy)   │          │  en el host) │         │ (WhatsApp)       │
   └───────────┘          └──────────────┘         └──────────────────┘
```

Los `.env` del front (`VITE_API_URL`, `VITE_REVERB_*`) viven en el repo
`INVYDES/EASY_ORDER_FRONT_MAIN`, rama `conexiones`.

---

## 2. Archivos `.env`

| Archivo | Cuándo se usa | Apunta a |
|---|---|---|
| `.env` | Uso diario en local | API `http://localhost:8000` · MySQL **Railway** por el proxy (`turntable.proxy.rlwy.net:12823`, base `railway`) · Reverb `localhost:8080` `http` |
| `.env.localtest` | Pruebas contra una base local | MySQL `127.0.0.1:3306`, base `easy_order_test` · incluye Google OAuth (`GOOGLE_REFRESH_TOKEN`) y `GEMINI_API_KEY` |
| `.env.cloudrun` | **Producción** (Cloud Run) | `APP_URL` de Cloud Run · CORS `https://eorder.mx,https://www.eorder.mx` · Reverb wss en 443 · S3/R2 para imágenes, PayPal y MercadoPago de producción |

`docker-entrypoint.sh` genera `APP_KEY` si falta y ejecuta `config:cache` + `route:cache`
(al cambiar variables en un contenedor hay que recrearlo o limpiar la caché).

> ⚠️ **Credenciales reales versionadas.** Esta rama (`conexiones`) incluye los tres `.env` con
> `APP_KEY`, `DB_PASSWORD`, `REVERB_APP_SECRET`, tokens de PayPal/MercadoPago y `GEMINI_API_KEY`.
> Si el repo se comparte o se hace público, **rótalos** y muévelos a variables de entorno del CI
> (`cloudbuild.yaml` / secrets de Cloud Run) en vez del repo.
>
> Las **credenciales de Google van en placeholder** (`REEMPLAZA_CON_TU_...`): GitHub bloquea el
> push al detectarlas (push protection). Ponlas solo en tu `.env` local o en el secret manager.
> Lo ideal a futuro: `.env.example` con placeholders para todo.

---

## 3. Base de datos (MySQL)

| Entorno | Host | Puerto | Base | Usuario |
|---|---|---|---|---|
| Producción / local por defecto | `turntable.proxy.rlwy.net` | `12823` | `railway` | `root` |
| Pruebas locales (`.env.localtest`) | `127.0.0.1` | `3306` | `easy_order_test` | — |

Respaldo / restauración (dumps disponibles en la raíz del workspace):

```bash
mysql -h turntable.proxy.rlwy.net -P 12823 -u root -p railway < EorderRespaldo.sql
mysql -h 127.0.0.1 -P 3306 -u root -p easy_order_test < eodermx.sql
```

Comandos:

```bash
php artisan migrate            # aplica migraciones
php artisan db:seed            # no hay seeders versionados todavía
php artisan storage:link       # enlaza storage/app/public → public/storage (imágenes)
```

---

## 4. API y autenticación

- **Las rutas de la API están en `resources/routes/api.php`**, no en `routes/` (Laravel 12 las
  registra desde `bootstrap/app.php` con `withRouting(api: __DIR__.'/../resources/routes/api.php')`).
  Los websockets se autorizan en `resources/routes/channels.php`.
- Prefijo `/api` → el front la arma como `${VITE_API_URL}/api` (`src/config/api.ts`).
- Auth con **Sanctum** por token: el front manda `Authorization: Bearer <token>` y
  `X-Restaurante-Id` (restaurante activo) en cada request.
- CORS / sesión (para conectar un front nuevo, agregar su origen):

```env
FRONTEND_URL=http://localhost:5173
CORS_ALLOWED_ORIGINS=http://localhost:5173,http://localhost:3000,http://localhost:8080,http://localhost:8100,http://127.0.0.1:5173
SANCTUM_STATEFUL_DOMAINS=localhost:8000,localhost:5173
SESSION_DOMAIN=localhost
CORS_PATHS=api/*,sanctum/csrf-cookie,broadcasting/*
```

---

## 5. WebSockets (Reverb)

```bash
php artisan reverb:start --host=0.0.0.0 --port=8080   # local
```

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=411884
REVERB_APP_KEY=…        # debe ser idéntica a VITE_REVERB_APP_KEY del front
REVERB_APP_SECRET=…
REVERB_HOST=localhost   # prod: host de Cloud Run
REVERB_PORT=8080        # prod: 443 con wss
REVERB_SCHEME=http      # prod: https
```

El front se conecta con `src/plugins/echo.ts` (Pusher protocol) y autoriza el canal privado en
`POST /broadcasting/auth` con el token de Sanctum. Eventos típicos: `orden creada`,
`estado cambiado`, `productos agregados a estación`.

> ⚠️ **Puerto 8080 compartido:** Reverb local usa 8080 y evolution-api también
> (127.0.0.1:8080). No levantes los dos a la vez; cambia `REVERB_PORT` (y `VITE_REVERB_PORT`)
> o el puerto de evolution si necesitas ambos.

---

## 6. WhatsApp (evolution-api)

Corre en el repo `evolution-api` con Docker (`docker-compose.yaml`): API + panel (`:3000`) +
Redis + Postgres.

```env
WHATSAPP_PROVIDER=evolution
EVOLUTION_API_URL=http://localhost:8080   # prod: URL pública de la instancia
EVOLUTION_API_KEY=…                        # AUTHENTICATION_API_KEY de evolution-api
EVOLUTION_INSTANCE_NAME=eorder
WHATSAPP_RECIPIENT_NUMBER=522294848144
```

```bash
cd evolution-api && docker compose up -d      # API en 127.0.0.1:8080, panel en :3000
```

El back envía mensajes (avisos de orden, notificaciones al dueño) contra esa API; el número de
WhatsApp se vincula escaneando el QR en el panel de evolution.

---

## 7. Correo, pagos e IA

| Servicio | Variables | Notas |
|---|---|---|
| Correo (Gmail API) | `MAIL_MAILER=gmail-api`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REFRESH_TOKEN`, `MAIL_FROM_*` | Transporte `gmail-api` definido en `config/mail.php`. En el repo las tres `GOOGLE_*` están como `REEMPLAZA_CON_TU_...`: se sacan de Google Cloud Console → APIs y servicios → Credenciales, y el refresh token del flujo OAuth (playground/gcloud). Si se revoca el acceso hay que regenerarlo |
| PayPal | `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_MODE`, `PAYPAL_CURRENCY` | `MODE=sandbox` para pruebas |
| MercadoPago | `MERCADOPAGO_PUBLIC_KEY`, `MERCADOPAGO_ACCESS_TOKEN`, `MERCADOPAGO_WEBHOOK_SECRET`, `MERCADOPAGO_MODE` | El webhook debe poder llamar a `APP_URL` |
| Google login | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | `google/apiclient`; mismas credenciales del correo, también en placeholder en el repo |
| IA | `GEMINI_API_KEY` (solo en `.env.localtest`) | Si el chat/analítica lo usa en producción, falta la variable en `.env.cloudrun` |
| Imágenes | `FILESYSTEM_IMAGES_DISK`, `AWS_*` | En producción las imágenes van a S3/R2 |

---

## 8. Deploy (Cloud Run)

Archivos: `Dockerfile`, `docker-entrypoint.sh`, `cloudbuild.yaml`, `cloudbuild.prod.yaml`,
`cloudrun.yaml`, `docker-compose.yml` (local) y `railway.json`.

```bash
gcloud builds submit --config cloudbuild.prod.yaml     # imagen + deploy
# o con el Dockerfile directo:
docker build -t eorder-back . && docker run -p 8000:8080 --env-file .env.cloudrun eorder-back
```

El contenedor usa las variables de `cloudrun.yaml` / secrets del servicio; conviene
sincronizarlas con `.env.cloudrun` cada vez que cambie algo de conexión.

---

## 9. Levantarlo en local (paso a paso)

```bash
composer install
cp .env.example .env 2>/dev/null || true    # o usa el .env versionado en esta rama
php artisan key:generate                    # solo si APP_KEY viene vacío
php artisan migrate --force
php artisan storage:link
php artisan serve --host=0.0.0.0 --port=8000
php artisan reverb:start --host=0.0.0.0 --port=8080   # otra terminal
```

Comandos útiles:

```bash
php artisan optimize:clear     # limpia config/route/view cache (útil tras tocar el .env)
php artisan route:list         # verifica que /api/* esté cargado
php artisan tinker             # probar modelos
tail -f storage/logs/laravel.log
```

---

## 10. Checklist para conectar un entorno nuevo

1. `APP_URL` y `FRONTEND_URL` correctos (el front llama a `APP_URL/api`).
2. `CORS_ALLOWED_ORIGINS` y `SANCTUM_STATEFUL_DOMAINS` con el origen del front.
3. DB alcanzable + `php artisan migrate`.
4. `REVERB_*` con la misma key que el `VITE_REVERB_APP_KEY` del front, y el puerto/scheme correcto.
5. `evolution-api` arriba y `EVOLUTION_*` apuntando a él si se usan avisos de WhatsApp.
6. `php artisan storage:link` y el disco de imágenes bien configurado (las URLs deben ser públicas).
7. Prueba: crear una orden desde el front y ver que aparece en cocina/barra **sin recargar**
   (eso confirma API + Reverb + canales).
