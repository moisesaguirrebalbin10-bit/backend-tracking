## Backend Tracking – API de integración con WooCommerce

API backend en Laravel para gestionar órdenes sincronizadas desde WooCommerce, mantener su línea de tiempo de cambios de estado y exponer endpoints protegidos por JWT para consumo interno.

### Stack

- PHP 8.1+ / Laravel
- PostgreSQL
- JWT para autenticación (`JwtTokenService` + `RefreshTokenService`)
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
  - `JWT_TTL_SECONDS` (ej. 900)  
  - `JWT_REFRESH_TTL_SECONDS` (ej. 2592000)

- **WooCommerce**
  - `WOO_BASE_URL` – URL base de la tienda WooCommerce
  - `WOO_CONSUMER_KEY`
  - `WOO_CONSUMER_SECRET`
  - `WOO_WEBHOOK_SECRET` – usado para validar la firma del webhook
  - `WOO_TIMEOUT_SECONDS`

3. **Generar clave de aplicación**

```bash
php artisan key:generate
```

4. **Migraciones**

```bash
php artisan migrate
```

5. **Ejecutar el servidor**

```bash
php artisan serve
```

La API quedará disponible típicamente en `http://localhost:8000`.

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

### POST /api/v1/orders/{order}/status

Actualiza el estado de una orden y registra un evento en la línea de tiempo (`OrderTimeline`).

- **Headers**

`Authorization: Bearer {access_token}`

- **Parámetros de ruta**

- `order` – ID interno de la orden.

- **Body (JSON)**

```json
{
  "status": "completed",
  "message": "Actualizado manualmente por soporte"
}
```

Reglas:

- `status` – requerido, string, máx. 100 caracteres.
- `message` – opcional, string.

- **Respuesta 200**

Devuelve la orden actualizada.

---

## Endpoints de usuarios

### POST /api/v1/users

Crea un nuevo usuario.

- **Headers**

`Authorization: Bearer {access_token}`

- **Body (JSON)**

```json
{
  "name": "Nuevo Usuario",
  "email": "user@example.com",
  "password": "secret123",
  "is_admin": true
}
```

Reglas:

- `name` – requerido, string, máx. 255.
- `email` – requerido, email único (`unique:users,email`), máx. 255.
- `password` – requerido, string, mín. 8.
- `is_admin` – opcional, boolean.

- **Respuesta 201**

Devuelve el usuario creado.

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

