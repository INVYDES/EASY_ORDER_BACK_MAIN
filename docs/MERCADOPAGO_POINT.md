# Mercado Pago Point — Cobro en terminal desde la caja

Integración de terminales **Mercado Pago Point** al punto de venta de Easy Order.
Cada restaurante conecta **su propia cuenta** de Mercado Pago (OAuth de terceros),
por lo que el dinero de las ventas cae directo en la cuenta del cliente.

---

## 1. Cómo funciona (no es como una impresora térmica)

La impresora Star usa *push desde el dispositivo* (hace polling a
`/api/cloudprnt/{token}`). Point es al revés: es **server-to-server** contra la
nube de Mercado Pago. La caja **no** se conecta al terminal por USB/Bluetooth/LAN.

```
Tu backend ──(Orders API)──> Nube Mercado Pago ──> Terminal Point
                                                       │ (el cliente paga)
Tu backend <──(Webhook de MP)──────────────────────────┘
```

1. La caja pide a tu backend que cree un *Order* (`type=point`).
2. Mercado Pago lo carga automáticamente en el terminal.
3. El cliente paga en el terminal.
4. Mercado Pago llama al webhook → el backend cierra la orden y registra la venta
   en la caja, y avisa al frontend por Reverb.

---

## 2. Configuración de credenciales (una sola vez por plataforma)

En **Tus integraciones** de Mercado Pago crea una aplicación y guarda:

| Variable | Descripción |
|----------|-------------|
| `MERCADOPAGO_APP_ID` | Client ID de la aplicación (OAuth) |
| `MERCADOPAGO_CLIENT_SECRET` | Client Secret de la aplicación |
| `MERCADOPAGO_REDIRECT_URI` | `https://TU_API/api/mercadopago/oauth/callback` |
| `MERCADOPAGO_WEBHOOK_SECRET` | Clave secreta del webhook (validación de firma) |
| `MERCADOPAGO_API_URL` | `https://api.mercadopago.com` (por defecto) |
| `MERCADOPAGO_AUTHORIZE_URL` | `https://auth.mercadopago.com.mx/authorization` |

En el panel de la aplicación configura el webhook con el topic **order** apuntando a:

```
POST https://TU_API/api/mercadopago/point/webhook
```

---

## 3. Flujo de emparejamiento de un terminal (por cliente piloto)

### 3.1 El restaurante conecta su cuenta (OAuth)

1. El dueño entra a Caja → **Conectar Mercado Pago**.
2. El frontend llama `GET /api/caja/mercadopago/oauth/conectar` y redirige a la
   `authorization_url` devuelta.
3. Autoriza con su cuenta de Mercado Pago.
4. Mercado Pago regresa a `/api/mercadopago/oauth/callback`, donde guardamos sus
   tokens (cifrados) en `mercadopago_credenciales` para ese restaurante.
5. Se verifica con `GET /api/caja/mercadopago/oauth/estado`.

### 3.2 Crear sucursal (store) y caja (POS) en Mercado Pago

> **Regla clave:** cada POS admite **una sola** terminal en modo PDV. Si hay
> varias terminales, crea un POS por cada una.

Se puede hacer desde el panel de Mercado Pago o por API (con el token del
restaurante) `POST /users/{user_id}/stores` y `POST /v2/pos`.

### 3.3 Asociar el terminal (físico)

Lo hace el dueño desde la **app móvil** de Mercado Pago (no se puede automatizar):

1. Encender el terminal → "Inicia sesión en este dispositivo con tu cuenta".
2. Elegir *Soy el dueño* o *Soy colaborador*.
3. Aparece un QR en el terminal → escanearlo con la app de Mercado Pago.
4. Seleccionar la sucursal y el POS a asociar.
5. Activar el **modo PDV** (modo punto de venta integrado).

### 3.4 Sincronizar el terminal en Easy Order

1. `GET /api/caja/mercadopago/point/terminales` consulta la lista en Mercado Pago
   y la guarda en `mercadopago_terminales`.
2. Opcional: `POST /api/caja/mercadopago/point/terminales` para asignar alias
   ("Caja 1") y el ticket a imprimir:
   - `seller_ticket` → **el terminal imprime el ticket** (sustituye a la
     impresora térmica).
   - `no_ticket` → no imprime (usas tu impresora Star).

---

## 4. Cobro desde la caja

1. La caja abre el modal de cobro y elige el método **Terminal**.
2. `POST /api/caja/mercadopago/point/crear` con `{ orden_id, propina? }`.
   - Se crea el Order en MP, se guardan `mercadopago_order_id` /
     `mercadopago_payment_id` en la orden.
3. El frontend muestra "Esperando pago en el terminal…" y consulta
   `GET /api/caja/mercadopago/point/orden/{orderId}` cada 2.5 s.
4. Cuando el webhook confirma el pago, el backend:
   - marca la orden como `CERRADA` (método `mercadopago`),
   - registra el ingreso en la caja abierta (`caja_movimientos`),
   - emite `caja.actualizada` y `orden.actualizada` por Reverb.
5. El frontend cierra la venta sola.

### Cancelar / reembolsar

- `POST /api/caja/mercadopago/point/orden/{orderId}/cancelar`
  (en `at_terminal` la cancelación es asíncrona y se confirma por webhook).
- Reembolso total o parcial: disponible en el servicio (`refundOrder`), hasta 90 días.

---

## 5. Endpoints

### Públicos
| Método | Ruta | Descripción |
|--------|------|-------------|
| GET  | `/api/mercadopago/oauth/callback` | Retorno de OAuth |
| POST | `/api/mercadopago/point/webhook` | Notificaciones de la Orders API |

### Tenant (`auth:sanctum` + `tenant`)
| Método | Ruta | Permiso |
|--------|------|---------|
| GET  | `/api/caja/mercadopago/oauth/conectar` | `VER_CAJA` |
| GET  | `/api/caja/mercadopago/oauth/estado` | `VER_CAJA` |
| POST | `/api/caja/mercadopago/oauth/desconectar` | `EDITAR_CAJA` |
| GET  | `/api/caja/mercadopago/point/terminales` | `VER_CAJA` |
| POST | `/api/caja/mercadopago/point/terminales` | `EDITAR_CAJA` |
| DELETE | `/api/caja/mercadopago/point/terminales/{id}` | `EDITAR_CAJA` |
| POST | `/api/caja/mercadopago/point/crear` | `CREAR_ORDENES` |
| GET  | `/api/caja/mercadopago/point/orden/{orderId}` | `VER_CAJA` |
| POST | `/api/caja/mercadopago/point/orden/{orderId}/cancelar` | `CREAR_ORDENES` |

---

## 6. Archivos

| Archivo | Rol |
|---------|-----|
| `database/migrations/2026_10_08_000001_create_mercadopago_point_tables.php` | Tablas y columnas |
| `app/Models/MercadoPagoCredencial.php` | Tokens OAuth por restaurante |
| `app/Models/MercadoPagoTerminal.php` | Terminales por restaurante |
| `app/Services/MercadoPagoPointService.php` | OAuth + Orders API |
| `app/Http/Controllers/Api/PointOAuthController.php` | Conectar / estado / desconectar |
| `app/Http/Controllers/Api/PointController.php` | Terminales, cobro, webhook |
| `src/components/caja/paymentModal.vue` | Botón "Terminal" y espera |
| `src/components/caja/cajatiketcard.vue` | Confirmación del cobro |

---

## 7. Notas

- **Multi-tenant:** cada restaurante tiene su propia credencial y sus terminales.
- **Pruebas:** usa el Access Token y la cuenta de prueba de Mercado Pago antes de
  pasar a producción; los `live_mode` quedan registrados por restaurante.
- **Idempotencia:** el webhook y `finalizarVenta` son idempotentes (no duplican la venta).
- **Seguridad:** la firma del webhook se valida con `MERCADOPAGO_WEBHOOK_SECRET`;
  nunca se confía solo en el payload, siempre se consulta el estado real del Order.
