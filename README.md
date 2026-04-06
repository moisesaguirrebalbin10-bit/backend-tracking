## Backend Tracking – API de integración con WooCommerce

API backend en Laravel para gestionar órdenes sincronizadas desde WooCommerce, mantener su línea de tiempo de cambios de estado y exponer endpoints protegidos por JWT para consumo interno.

### Stack

- PHP 8.1+ / Laravel
- PostgreSQL
- JWT para autenticación (`JwtTokenService` + `RefreshTokenService`).
  Se agregó endpoint `POST /api/v1/auth/logout` que invalida el refresh token enviado en el cuerpo.
- Integración WooCommerce vía `WooCommerceClient` (Guzzle)

---

## Requisitos

- PHP 8.1 o superior
- Composer
- PostgreSQL
- Extensión `phpredis` (para Redis, si se usa)

---

## Configuración del proyecto

1. **Clonar e instalar dependencias**

```bash
composer install
```

2. **Variables de entorno**

Copiar el archivo de ejemplo y completar valores:

```bash
cp .env.example .env
```

Configurar al menos:

- **App / entorno**
  - `APP_NAME`
  - `APP_ENV`
  - `APP_URL`

- **Base de datos (PostgreSQL)**
  - `DB_CONNECTION=pgsql`
  - `DB_HOST`
  - `DB_PORT`
  - `DB_DATABASE`
  - `DB_USERNAME`
  - `DB_PASSWORD`

- **JWT**
  - `JWT_SECRET`  
  - `JWT_ISSUER`  
  - `JWT_AUDIENCE`  
  - `JWT_TTL_SECONDS` (ej. 3600 para 1 hora; por defecto 900)
  - `JWT_REFRESH_TTL_SECONDS` (duración del refresh token en segundos)

  - `JWT_REFRESH_TTL_SECONDS` (ej. 2592000)

- **WooCommerce**
  - `WOO_BASE_URL` – URL base de la tienda WooCommerce
  - `WOO_CONSUMER_KEY`
  - `WOO_CONSUMER_SECRET`
  - `WOO_WEBHOOK_SECRET` – usado para validar la firma del webhook
  - `WOO_TIMEOUT_SECONDS`
  - `WOO_SYNC_OVERLAP_MINUTES` – solapamiento para incremental por `date_modified`
  - `WOO_SYNC_BOOTSTRAP_LOOKBACK_MINUTES` – ventana inicial si aún no existe checkpoint

- **Bsale**
  - `BSALE_BASE_URL`
  - `BSALE_TOKEN`
  - `BSALE_BATCH_SIZE`
  - `BSALE_SYNC_OVERLAP_MINUTES` – solapamiento para incremental por `generationDate`
  - `BSALE_SYNC_BOOTSTRAP_LOOKBACK_MINUTES` – ventana inicial si aún no existe checkpoint

3. **Generar clave de aplicación**

```bash
php artisan key:generate
```

4. **Migraciones**

```bash
php artisan migrate
```

### Usuarios operativos de prueba

Puedes crear o actualizar cuentas listas para el flujo operativo usando cualquiera de estas opciones:

```bash
php artisan db:seed --class=OperationalUsersSeeder
php artisan users:seed-operational --password="secret123"
```

Se generan estas cuentas base:

- `empaquetador`: `operaciones.empaquetador@tracking.local`
- `despachador`: `operaciones.despachador@tracking.local`
- `delivery`: `delivery.pruebas@tracking.local`

Variables opcionales:

- `OPERATIONAL_USERS_DEFAULT_PASSWORD`
- `OPERATIONAL_PACKER_EMAIL`
- `OPERATIONAL_DISPATCHER_EMAIL`
- `OPERATIONAL_DELIVERY_EMAIL`

5. **Ejecutar el servidor**

```bash
php artisan serve
```

La API quedará disponible típicamente en `http://localhost:8000`.

---

## Sincronización programada

Se implementó un esquema de sincronización en dos capas:

- **Full sync** para reconciliación completa.
- **Incremental sync** cada minuto usando checkpoints persistidos en BD.

### Comandos disponibles

```bash
php artisan sync:woo:full
php artisan sync:woo:incremental
php artisan sync:bsale:full
php artisan sync:bsale:incremental
```

WooCommerce también acepta un subconjunto de tiendas:

```bash
php artisan sync:woo:full ezzeta,otra-tienda
php artisan sync:woo:incremental ezzeta
```

### Schedule configurado

- `sync:woo:incremental` cada minuto usando `modified_after`.
- `sync:bsale:incremental` cada minuto usando `generationDate`.
- `sync:woo:full` diariamente a las `02:30`.
- `sync:bsale:full` diariamente a las `03:00`.

### Notas operativas

- Para WooCommerce, el cursor incremental se actualiza con `date_modified_gmt` de cada pedido.
- Para Bsale, `documents` no expone un `updatedAt` utilizable; el incremental se resuelve con `generationDate` y comparación por `fingerprint` local.
- Antes de usar los schedules en producción, ejecutar al menos un full sync inicial.

---

## Autenticación

La mayoría de los endpoints bajo `/api/v1` requieren autenticación mediante JWT usando el middleware `jwt`.

- **Header de autorización**

```http
Authorization: Bearer {access_token}
```

### POST /api/v1/auth/login

Inicia sesión y devuelve un access token y un refresh token.

- **Body (JSON)**

```json
{
  "email": "admin@example.com",
  "password": "secret123"
}
```

- **Respuesta 200**

```json
{
  "access_token": "jwt_access_token",
  "token_type": "Bearer",
  "expires_in": 900,
  "refresh_token": "refresh_token",
  "user": {
    "id": 1,
    "name": "Admin",
    "email": "admin@example.com",
    "is_admin": true
  }
}
```

### POST /api/v1/auth/refresh

Renueva el access token a partir de un `refresh_token` válido.

- **Body (JSON)**

```json
{
  "refresh_token": "refresh_token_actual"
}
```

- **Respuesta 200**

```json
{
  "access_token": "nuevo_jwt_access_token",
  "token_type": "Bearer",
  "expires_in": 900,
  "refresh_token": "nuevo_refresh_token"
}
```

### GET /api/v1/me

Devuelve la información del usuario autenticado.

- **Headers**

`Authorization: Bearer {access_token}`

- **Respuesta 200**

```json
{
  "user": {
    "id": 1,
    "name": "Admin",
    "email": "admin@example.com",
    "is_admin": true
  }
}
```

---

## Endpoints de órdenes

### GET /api/v1/orders

Lista paginada de órdenes (modelo `Order`) ordenadas por `id` descendente.

- **Headers**

`Authorization: Bearer {access_token}`

- **Query params opcionales**

Paginación estándar de Laravel (`page`, etc.) – tamaño por defecto: 50.

- **Respuesta 200 (ejemplo simplificado)**

```json
{
  "data": [
    {
      "id": 1,
      "external_id": "123",
      "status": "processing",
      "total": 120.5,
      "currency": "USD",
      "customer_name": "John Doe",
      "meta": {},
      "synced_at": "2026-03-03T12:00:00Z"
    }
  ],
  "current_page": 1,
  "per_page": 50,
  "total": 1
}
```

### POST /api/v1/orders/sync

Punto de entrada para iniciar una sincronización de órdenes desde WooCommerce.

- **Headers**

`Authorization: Bearer {access_token}`

- **Body (JSON)**

```json
{
  "from_date": "2026-03-01",
  "to_date": "2026-03-03"
}
```

Parámetros:

- `from_date` – fecha inicial (opcional, `date`)
- `to_date` – fecha final (opcional, `date`, `after_or_equal:from_date`)

- **Respuesta 202**

```json
{
  "message": "Sync started"
}
```

> Nota: la implementación interna de la sincronización (`OrderService::syncFromWooCommerce`) puede ampliarse para usar `WooCommerceClient` y poblar la tabla `orders`.

### GET /api/v1/orders/{order}/available-transitions

Devuelve las transiciones permitidas para el usuario autenticado según rol, estado actual y asignación.

- **Headers**

`Authorization: Bearer {access_token}`

- **Respuesta 200**

```json
{
  "current_status": {
    "value": "empaquetado",
    "label": "Empaquetado"
  },
  "available_transitions": [
    {
      "value": "despachado",
      "label": "Despachado",
      "requires_delivery_user_id": true
    },
    {
      "value": "error_en_pedido",
      "label": "Error en Pedido",
      "requires_delivery_user_id": false
    }
  ]
}
```

### PUT /api/v1/orders/{order}/status

Actualiza el estado de una orden y registra un evento en la línea de tiempo (`OrderTimeline`).

- **Headers**

`Authorization: Bearer {access_token}`

- **Parámetros de ruta**

- `order` – ID interno de la orden.

- **Body (JSON)**

```json
{
  "status": "despachado",
  "delivery_user_id": 12,
  "error_reason": null
}
```

Reglas:

- `status` – requerido. Estados soportados por flujo interno: `en_proceso`, `empaquetado`, `despachado`, `en_camino`, `entregado`, `error_en_pedido`, `cancelado`.
- `delivery_user_id` – requerido cuando `status=despachado`; debe pertenecer a un usuario con rol `delivery`.
- `error_reason` – requerido cuando `status=error_en_pedido`.
- `evidence_image` o `delivery_image` – opcionales según el flujo que use frontend para adjuntar evidencia.

Reglas de autorización operativa:

- `admin`: puede ejecutar cualquier transición válida del grafo.
- `empaquetador`: solo puede ver su cola `en_proceso` y mover a `empaquetado` o `error_en_pedido`.
- `despachador`: solo puede ver su cola `empaquetado` y mover a `despachado` o `error_en_pedido`.
- `delivery`: solo puede ver órdenes asignadas a su usuario y mover `despachado -> en_camino` y `en_camino -> entregado`, además de `error_en_pedido`.

- **Respuesta 200**

```json
{
  "message": "Order status updated successfully",
  "order": {
    "id": 2130,
    "status": "despachado",
    "assigned_delivery_user_id": 12,
    "logs": []
  }
}
```

- **Respuesta 403**

```json
{
  "message": "El usuario Operaciones Despacho (despachador) no puede pasar el pedido #2130 de despachado a entregado.",
  "error": "FORBIDDEN_ORDER_TRANSITION"
}
```

- **Respuesta 422**

```json
{
  "message": "delivery_user_id is required when marking an order as despachado.",
  "error": "INVALID_STATUS_TRANSITION"
}
```

---

## Dashboard operacional

Estos endpoints son la fuente recomendada para dashboard, cola operativa, detalle y métricas. Frontend no debe consultar históricos remotos de Bsale o Woo para este módulo.

### GET /api/v1/dashboard/orders

Lista unificada de Woo (`orders`) y Bsale (`bsale_documents`) ya normalizada para UI.

- **Headers**

`Authorization: Bearer {access_token}`

- **Query params**

- `source=all|woo|bsale`
- `scope=all|my_queue`
- `period=day|week|month|range`
- `date_from=YYYY-MM-DD` y `date_to=YYYY-MM-DD` cuando `period=range`
- `search`
- `status`
- `page`
- `per_page`

Notas de comportamiento:

- Para `empaquetador`, `despachador` y `delivery`, si no se envía `scope`, backend fuerza `my_queue`.
- `bsale` siempre se entrega como `readonly=true`.
- `delivery` solo recibe órdenes Woo asignadas a `assigned_delivery_user_id = auth()->id()` y con estado `despachado` o `en_camino`.

- **Respuesta 200 (ejemplo)**

```json
{
  "current_page": 1,
  "data": [
    {
      "source": "woo",
      "source_record_id": 2130,
      "readonly": false,
      "order_number": "30847",
      "bsale_receipt": "B002-1452",
      "customer_name": "Kevin Alexander López Chilón",
      "ordered_at": "2026-03-21 18:59:48",
      "delivery_date": null,
      "delivered_at": null,
      "location": "CAJAMARCA",
      "total": 100,
      "status": {
        "value": "despachado",
        "label": "Despachado",
        "raw": "despachado"
      },
      "assigned_delivery_user_id": 12,
      "assigned_delivery_name": "Delivery Pruebas",
      "vendor_name": "ezzeta",
      "store_slug": "ezzeta",
      "detail_endpoint": "/api/v1/dashboard/orders/woo/2130"
    }
  ],
  "per_page": 50,
  "total": 1
}
```

### GET /api/v1/dashboard/orders/metrics

Usa los mismos filtros que el listado y devuelve agregados del período/cola visible.

- **Respuesta 200**

```json
{
  "filters": {
    "source": "all",
    "scope": "my_queue",
    "period": "day",
    "status": null,
    "search": ""
  },
  "metrics": {
    "total_orders": 4,
    "delivered_orders": 1,
    "in_process_orders": 2,
    "error_orders": 1,
    "cancelled_orders": 0,
    "total_amount": 296.99
  }
}
```

### GET /api/v1/dashboard/orders/{source}/{id}

Devuelve el detalle ya normalizado para la modal de UI.

- `source` soporta `woo` y `bsale`.
- Para `bsale`, solo `admin` puede abrir el detalle.
- Para `woo`, el detalle respeta visibilidad por cola/rol.

- **Respuesta 200 para Woo (ejemplo)**

```json
{
  "order": {
    "source": "woo",
    "readonly": false,
    "id": 2130,
    "external_id": "30847",
    "order_number": "30847",
    "bsale_receipt": "B002-1452",
    "status": {
      "value": "despachado",
      "label": "Despachado",
      "raw": "processing",
      "woo_label": "En Proceso"
    },
    "assigned_delivery_user_id": 12,
    "assigned_delivery_name": "Delivery Pruebas",
    "assigned_delivery": {
      "id": 12,
      "name": "Delivery Pruebas",
      "email": "delivery.pruebas@tracking.local"
    },
    "dispatch_date": null,
    "location": "CAJAMARCA",
    "customer": {
      "name": "Kevin Alexander López Chilón",
      "document": "74146498",
      "email": "n00224750@upn.pe",
      "phone": "903373607"
    },
    "seller": {
      "name": "ezzeta",
      "issue_date": "2026-03-21",
      "receipt": "B002-1452"
    },
    "payment": {
      "methods": [
        {
          "method": "Yape",
          "amount": 100
        }
      ],
      "total": 100
    },
    "products": [],
    "allowed_transitions": [
      {
        "value": "en_camino",
        "label": "En Camino",
        "requires_delivery_user_id": false
      },
      {
        "value": "error_en_pedido",
        "label": "Error en Pedido",
        "requires_delivery_user_id": false
      }
    ],
    "meta": {
      "store_slug": "ezzeta",
      "woo_status": "processing",
      "ordered_at": "2026-03-21T18:59:48-05:00",
      "delivered_at": null
    }
  }
}
```

---

## Endpoints de usuarios

### POST /api/v1/users

Crea un nuevo usuario.

- **Headers**

`Authorization: Bearer {access_token}`

Solo un usuario administrador puede ejecutar este endpoint.

- **Body (JSON)**

```json
{
  "name": "Nuevo Usuario",
  "email": "user@example.com",
  "password": "secret123",
  "role": "delivery",
  "is_admin": false
}
```

Reglas:

- `name` – requerido, string, máx. 255.
- `email` – requerido, email único (`unique:users,email`), máx. 255.
- `password` – requerido, string, mín. 8.
- `role` – opcional, uno de: `admin`, `vendedor_redes`, `ventas_web`, `empaquetador`, `despachador`, `delivery`.
- `is_admin` – opcional, boolean.

- **Respuesta 201**

Devuelve el usuario creado.

### GET /api/v1/users

Lista usuarios para tabla de administración e incluye indicador `is_active` según su actividad reciente.

- **Headers**

`Authorization: Bearer {access_token}`

Un usuario `admin` puede listar todos los usuarios. Un usuario `despachador` puede listar solo usuarios `delivery` para la asignación de despacho.

- **Query params opcionales**

- `search` – filtra por nombre o correo.
- `per_page` – paginación (10 a 100, por defecto 20).
- `window_seconds` – ventana para considerar un usuario activo (30 a 3600, por defecto 120).
- `role` – filtro opcional por rol. Si el actor es `despachador`, solo se permite `role=delivery`.

- **Respuesta 200 (ejemplo simplificado)**

```json
{
  "window_seconds": 120,
  "role_filter": "delivery",
  "active_users": 2,
  "total_users": 8,
  "users": {
    "data": [
      {
        "id": 1,
        "name": "Admin",
        "email": "admin@example.com",
        "role": "admin",
        "is_admin": true,
        "last_seen_at": "2026-03-14T15:10:00Z",
        "is_active": true,
        "created_at": "2026-03-03T16:00:00Z"
      }
    ]
  }
}
```

### POST /api/v1/users/heartbeat

Actualiza actividad del usuario autenticado (útil para estado en vivo al probar WebSockets y presencia).

- **Headers**

`Authorization: Bearer {access_token}`

- **Respuesta 200**

```json
{
  "user_id": 1,
  "is_active": true,
  "last_seen_at": "2026-03-14T15:10:30Z"
}
```

---

## Webhook de WooCommerce

### POST /api/v1/webhooks/woocommerce

Endpoint para recibir webhooks de WooCommerce y mantener sincronizado el estado de las órdenes.

- **Headers**

- `X-WC-Webhook-Signature`: firma HMAC-SHA256 en base64 del body del request, usando `WOO_WEBHOOK_SECRET` como clave.

Si `WOO_WEBHOOK_SECRET` está vacío en configuración, no se realiza validación de firma.

- **Body**

Se espera el payload estándar de un webhook de orden de WooCommerce, por ejemplo:

```json
{
  "id": 123,
  "status": "processing",
  "currency": "USD",
  "total": "120.50",
  "...": "otros campos"
}
```

- `id` se mapea a `external_id` en el modelo `Order`.
- Si la orden no existe, se crea con `external_id`, `status`, `currency` y `total`.
- Se registra una entrada en `OrderTimeline` con:
  - `status` (el del payload)
  - `message`: `"Webhook WooCommerce"`
  - `source`: `"webhook"`
  - `occurred_at`: fecha/hora actual (UTC)

- **Respuesta 200**

```json
{
  "ok": true
}
```

---

## Modelos principales

- **Order**
  - `external_id`, `status`, `total`, `currency`, `customer_name`, `meta`, `synced_at`.
  - Relación: `hasMany(OrderTimeline::class)` como `timelines`.

- **OrderTimeline**
  - `order_id`, `status`, `message`, `source`, `occurred_at`.
  - Relación: `belongsTo(Order::class)` como `order`.

---

## Notas de desarrollo

- Autenticación basada en JWT: revisar servicios en `App\Services\Auth`.
- Integración WooCommerce centralizada en `App\Services\Integrations\WooCommerceClient`.
- Webhooks validados con firma HMAC usando `WOO_WEBHOOK_SECRET`.

