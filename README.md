# EasyOrder — Backend (API Laravel)

API REST en Laravel que alimenta la plataforma EasyOrder (eorder.mx): gestión de órdenes,
licencias, suscripciones, contactos y notificaciones (correo + WhatsApp + chatbot Gemini).

- **Producción (API):** https://easy-order-back-201452705980.us-central1.run.app
- **Frontend:** https://eorder.mx (repositorio `EASY_ORDER_FRONT_MAIN`)
- **Rama activa:** `Lags`

## Arquitectura de despliegue

```
                       ┌───────────────────────┐
                       │   Cloudflare Workers  │
                       │   Worker "eorder"     │
                       │   dominio eorder.mx   │
                       └──────────┬────────────┘
                                  │  HTTPS (API JSON)
                                  ▼
┌──────────────────┐    ┌─────────────────────────────────────────┐
│  Cloud Build     │    │  Cloud Run: easy-order-back             │
│  cloudbuild.yaml │───▶│  us-central1 · min-instances=1          │
│  build + push    │    │  2 vCPU / 1Gi · concurrency 80          │
└──────────────────┘    │  cpu-boost · cpu-throttling off         │
        │               └───────┬───────────────┬─────────────────┘
        ▼                       │               │
┌──────────────────┐            │               │
│ Artifact Registry│            ▼               ▼
│ cloud-run-source-│    ┌──────────────┐  ┌─────────────────────┐
│ deploy (Docker)  │    │ MySQL (Railway)│ │ Cloudflare R2 (S3) │
└──────────────────┘    │ railway / 12823│  │ bucket eorder-ing  │
                        └──────────────┘  └─────────────────────┘
                                  │
                                  │ secretKeyRef
                                  ▼
                        ┌─────────────────────────────────────────┐
                        │  Secret Manager (12 secretos eorder-*)   │
                        └─────────────────────────────────────────┘

Programado (notificaciones de suscripción por vencer):
┌────────────────────┐    ┌──────────────────────┐    ┌───────────────────────┐
│ Cloud Scheduler    │───▶│ Cloud Run Job        │───▶│ Gmail API (eorder.    │
│ easy-order-notif   │    │ easy-order-scheduler │    │ mexico@gmail.com)     │
│ diario 09:00 CDMX  │    │ artisan app:notificar│    └───────────────────────┘
└────────────────────┘    └──────────────────────┘
```

### Componentes

| Componente | Nombre / ID | Función |
|---|---|---|
| Servicio Cloud Run | `easy-order-back` | API HTTP (nginx + php-fpm en un contenedor) |
| Job Cloud Run | `easy-order-scheduler` | Tareas programadas (`php artisan app:notificar-suscripcion-por-vencer`) |
| Cloud Scheduler | `easy-order-notif` | Dispara el Job diariamente a las 09:00 (America/Mexico_City) |
| Cloud Build | `cloudbuild.yaml` | Build + push de imagen y deploy |
| Artifact Registry | `cloud-run-source-deploy` | Imágenes Docker (`easy-order-back:latest`) |
| Secret Manager | secretos `eorder-*` | Credenciales sensibles del runtime |
| Cloudflare Workers | `eorder` | SPA Vue + assets en `eorder.mx` |
| MySQL | Railway (`turntable.proxy.rlwy.net:12823`) | Base de datos principal (`railway`) + DB de contactos (`eorder_contactos`) |
| Cloudflare R2 | `eorder-ing` | Almacenamiento de imágenes (S3-compatible) |

### Secretos en Secret Manager

Montados en el servicio y el Job vía `--update-secrets` (gcloud), agente de servicio
`201452705980-compute@developer.gserviceaccount.com` con rol `secretmanager.secretAccessor`:

| Variable de entorno | Secreto | Uso |
|---|---|---|
| `APP_KEY` | `eorder-app-key` | Clave de cifrado Laravel |
| `DB_PASSWORD` | `eorder-db-password` | MySQL Railway |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | `eorder-r2-access-key` / `eorder-r2-secret-key` | Cloudflare R2 |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | `eorder-google-client-id` / `eorder-google-client-secret` | OAuth Google |
| `GOOGLE_REFRESH_TOKEN` | `eorder-gmail-refresh-token` | Gmail API (envío de correo, cuenta `eorder.mexico@gmail.com`) |
| `EVOLUTION_API_KEY` | `eorder-evolution-api-key` | WhatsApp (Evolution API) |
| `GEMINI_API_KEY` | `eorder-gemini-api-key` | Chatbot (Gemini, restringida a `generativelanguage.googleapis.com`) |
| `MERCADOPAGO_ACCESS_TOKEN` / `MERCADOPAGO_WEBHOOK_SECRET` | `eorder-mercadopago-access-token` / `eorder-mercadopago-webhook-secret` | Cobros de licencias (MercadoPago) |
| `REVERB_APP_SECRET` | `eorder-reverb-app-secret` | WebSockets (Laravel Reverb) |

> Las variables no sensibles (CORS, sesiones, mailer, hosts, etc.) se administran con
> `--env-vars-file env-run.json` (archivo local, gitignored).

## Cómo desplegar

### Backend (Cloud Run)

Opción directa (recomendada; usa la imagen `:latest` ya construida):

```bash
cd EASY_ORDER_BACK_MAIN
gcloud run deploy easy-order-back \
  --project project-edf05916-52e7-4a8f-a6a --region us-central1 \
  --image us-central1-docker.pkg.dev/project-edf05916-52e7-4a8f-a6a/cloud-run-source-deploy/easy-order-back:latest \
  --platform=managed --allow-unauthenticated \
  --cpu=2 --memory=1Gi --min-instances=1 --max-instances=10 \
  --concurrency=80 --timeout=300 --cpu-boost --no-cpu-throttling \
  --env-vars-file env-run.json \
  --update-secrets 'APP_KEY=eorder-app-key:latest,DB_PASSWORD=eorder-db-password:latest,AWS_ACCESS_KEY_ID=eorder-r2-access-key:latest,AWS_SECRET_ACCESS_KEY=eorder-r2-secret-key:latest,GOOGLE_CLIENT_ID=eorder-google-client-id:latest,GOOGLE_CLIENT_SECRET=eorder-google-client-secret:latest,GOOGLE_REFRESH_TOKEN=eorder-gmail-refresh-token:latest,EVOLUTION_API_KEY=eorder-evolution-api-key:latest,GEMINI_API_KEY=eorder-gemini-api-key:latest,REVERB_APP_SECRET=eorder-reverb-app-secret:latest,MERCADOPAGO_ACCESS_TOKEN=eorder-mercadopago-access-token:latest,MERCADOPAGO_WEBHOOK_SECRET=eorder-mercadopago-webhook-secret:latest'
```

Opción por Cloud Build (compila la imagen desde cero y ejecuta el step de deploy):

```bash
gcloud builds submit --config cloudbuild.yaml --project project-edf05916-52e7-4a8f-a6a .
```

Notas:
- `cloudbuild.yaml` no contiene secretos: las variables sensibles van con `--update-secrets`
  y las no sensibles con `--update-env-vars`.
- `cloudbuild.prod.yaml` (local, gitignored) conserva el cloudbuild con los valores reales.
- El `Dockerfile` crea `storage/framework/*` en el build (git los ignora pero Laravel los
  necesita para vistas compiladas, cache y sesiones; sin esto fallan los correos renderizados).
- Tras desplegar la imagen por primera vez, ejecutar `php artisan migrate --force` si hay
  migraciones pendientes.

### Frontend (Cloudflare)

```bash
cd EASY_ORDER_FRONT_MAIN
npm run build        # vite build + prerender (SITE_URL=https://eorder.mx)
npx wrangler deploy  # Worker "eorder" → eorder.mx
```

### Ejecutar el Job manualmente

```bash
gcloud run jobs execute easy-order-scheduler \
  --project project-edf05916-52e7-4a8f-a6a --region us-central1 --wait
```

## Formulario de contacto y notificaciones

- `POST /api/contacto` (pública, con honeypot + validación): guarda la solicitud en
  `eorder_contactos.solicitudes_contacto` (conexión `contactos`), genera folio por trigger
  de la base, y avisa al equipo por correo (`MAIL_CONTACT_RECIPIENT`) y WhatsApp.
- `app:notificar-suscripcion-por-vencer`: notifica a propietarios con licencias que vencen
  en ≤ 7 días y aún no notificadas (`notificado_vencimiento_at`); corre diariamente a las
  09:00 (CDMX) vía Cloud Scheduler → Cloud Run Job.
- El envío real usa el mailer custom `gmail-api` (transport Gmail API con refresh token).

## Desarrollo local

```bash
composer install
cp .env.example .env   # y completar credenciales locales
php artisan key:generate
php artisan migrate
php artisan serve
```

- `.env`, `.env.cloudrun`, `cloudrun.yaml`, `cloudbuild.prod.yaml`, `get_gmail_token.php`,
  `gmail_token_server.php`, `gmail_oauth.log`, `.gmail_refresh_token.txt` y `env-run.json`
  están gitignored y excluidos del contexto de build (`.dockerignore`) por contener credenciales.
- Para (re)generar el refresh token de Gmail: `php get_gmail_token.php` y seguir el flujo
  OAuth con la cuenta `eorder.mexico@gmail.com` (requiere las URIs `http://localhost:8888`
  y `http://localhost:8888/callback` registradas en el cliente OAuth, y la app OAuth en
  modo Externo con la cuenta como usuario de prueba).

## Seguridad

- Ningún secreto vive en el repositorio; se administran con Secret Manager.
- El cliente OAuth de Google y la pantalla de consentimiento pertenecen al proyecto
  `project-edf05916-52e7-4a8f-a6a` (eOrder).
- La llave de Gemini está restringida por API; rota las llaves desde
  **APIs y servicios → Credenciales** y actualiza el secreto correspondiente en
  Secret Manager (`gcloud secrets versions add ...`).
