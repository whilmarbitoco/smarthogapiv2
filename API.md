# SmartHog API v2 — Complete API Reference

**Base URL:** `/api/v1`  
**Protocol:** HTTPS (HTTP in development)  
**Content Type:** `application/json`  

---

## Table of Contents

1. [Authentication](#1-authentication)
2. [Health Check](#2-health-check)
3. [Farms](#3-farms)
4. [Hog Pens](#4-hog-pens)
5. [Hogs](#5-hogs)
6. [Hog Daily Records](#6-hog-daily-records)
7. [Feeders](#7-feeders)
8. [Feeder Feed Type Mapping](#8-feeder-feed-type-mapping)
9. [Feeding Logs](#9-feeding-logs)
10. [Feeding Schedule](#10-feeding-schedule)
11. [Feeding Predictions](#11-feeding-predictions)
12. [IoT Devices](#12-iot-devices)
13. [Device Logs](#13-device-logs)
14. [Device Credentials](#14-device-credentials)
15. [Sensors](#15-sensors)
16. [Sensor Readings](#16-sensor-readings)
17. [ML Models](#17-ml-models)
18. [Prediction Cache](#18-prediction-cache)
19. [Alerts](#19-alerts)
20. [Daily Farm Reports](#20-daily-farm-reports)
21. [Analytics](#21-analytics)
22. [Webhook Logs](#22-webhook-logs)
23. [Sinric Sync](#23-sinric-sync)
24. [Database Schema Reference](#24-database-schema-reference)
25. [Common Errors](#25-common-errors)

---

## Standard Response Envelope

All API responses follow a consistent JSON envelope:

```json
{
  "success": true|false,
  "message": "Human-readable message",
  "data": { ... } | [ ... ] | null,
  "meta": {                          // Only on paginated responses
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "per_page": 25,
    "to": 25,
    "total": 125
  },
  "links": {                         // Only on paginated responses
    "first": "/api/v1/...?page=1",
    "last": "/api/v1/...?page=5",
    "prev": null,
    "next": "/api/v1/...?page=2"
  }
}
```

### Response Methods

| Method | HTTP Status | Description |
|--------|-------------|-------------|
| `ApiResponse::success()` | 200 | Success with optional data |
| `ApiResponse::created()` | 201 | Resource created |
| `ApiResponse::deleted()` | 200 | Resource deleted |
| `ApiResponse::error()` | 500 (default) | Error with message + optional error detail |
| `ApiResponse::paginated()` | 200 | Paginated collection with meta + links |

---

## 1. Authentication

All endpoints except `/auth/login` and `/ping` require authentication via a **Sanctum Bearer token**.

**Header format:**
```
Authorization: Bearer <your-sanctum-token>
```

---

### POST /api/v1/auth/login

Authenticate via SinricPro credentials. Returns a Sanctum token.

**Authentication:** None  
**Throttle:** 5 requests per minute

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `email` | string | yes | User email (must be valid email format) |
| `password` | string | yes | User password |

**Example Request:**
```json
{
  "email": "farmer@example.com",
  "password": "my-secret-password"
}
```

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "user": {
      "id": 1,
      "name": "John Farmer",
      "email": "farmer@example.com",
      "email_verified_at": null,
      "sinric_user_id": "sinric_abc123",
      "last_login_at": "2026-06-15T10:30:00.000000Z",
      "created_at": "2026-06-15T10:30:00.000000Z",
      "updated_at": "2026-06-15T10:30:00.000000Z"
    },
    "token": "1|abc123sanctumtoken..."
  }
}
```

**Example Response (401 — Sinric auth failed):**
```json
{
  "success": false,
  "message": "Invalid credentials",
  "error": "Sinric authentication failed"
}
```

**Possible Status Codes:** 200, 401, 422, 429

---

### POST /api/v1/auth/logout

Revoke the current Sanctum token.

**Authentication:** Bearer token required  
**Throttle:** 10 requests per minute

**Request Body:** None

**Example Response (200):**
```json
{
  "success": true,
  "message": "Logged out successfully",
  "data": null
}
```

**Possible Status Codes:** 200, 401, 429

---

### GET /api/v1/auth/me

Get the authenticated user's profile.

**Authentication:** Bearer token required

**Request Body:** None

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "id": 1,
    "name": "John Farmer",
    "email": "farmer@example.com",
    "email_verified_at": null,
    "sinric_user_id": "sinric_abc123",
    "last_login_at": "2026-06-15T10:30:00.000000Z",
    "created_at": "2026-06-15T10:30:00.000000Z",
    "updated_at": "2026-06-15T10:30:00.000000Z"
  }
}
```

**Possible Status Codes:** 200, 401

---

### POST /api/v1/auth/refresh-token

Refresh the SinricPro access token.

**Authentication:** Bearer token required  
**Throttle:** 10 requests per minute

**Request Body:** None

**Example Response (200):**
```json
{
  "success": true,
  "message": "Token refreshed successfully",
  "data": null
}
```

**Possible Status Codes:** 200, 401, 429

---

### POST /api/v1/auth/reject-token

Reject/revoke the SinricPro access token.

**Authentication:** Bearer token required  
**Throttle:** 10 requests per minute

**Request Body:** None

**Example Response (200):**
```json
{
  "success": true,
  "message": "Token rejected successfully",
  "data": null
}
```

**Possible Status Codes:** 200, 401, 429

---

## 2. Health Check

### GET /api/v1/ping

Simple health-check endpoint (no authentication required).

**Authentication:** None

**Example Response (200):**
```json
{
  "message": "pong"
}
```

### GET /

Root health-check (web route, not under `/api/v1`).

**Authentication:** None

**Example Response (200):**
```json
{
  "success": true,
  "message": "Smarthog API is running."
}
```

---

## 3. Farms

All farm endpoints require authentication.

---

### GET /api/v1/farms

List all farms owned by the authenticated user. Automatically syncs Sinric homes first.

**Authentication:** Bearer token required

**Query Parameters:** None (standard pagination applies)

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "user_id": 1,
      "location": "North Field",
      "timezone": "America/Chicago",
      "external_provider": "sinric",
      "external_home_id": "home_abc123",
      "external_metadata": null,
      "created_at": "2026-06-01T08:00:00.000000Z",
      "updated_at": "2026-06-15T10:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "per_page": 25,
    "to": 1,
    "total": 1
  },
  "links": {
    "first": "/api/v1/farms?page=1",
    "last": "/api/v1/farms?page=1",
    "prev": null,
    "next": null
  }
}
```

**Possible Status Codes:** 200, 401

---

### POST /api/v1/farms

Create a new farm. If the user has a Sinric access token, a Sinric home is also created.

**Authentication:** Bearer token required

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | no* | Sinric home name (required if `location` not provided) |
| `location` | string | no* | Farm location (required if `name` not provided, unique per user) |
| `timezone` | string | no | Farm timezone |
| `user_id` | integer | no | User ID (defaults to authenticated user) |
| `imageUrl` | string (url) | no | Image URL (max 2048 chars) |
| `external_provider` | string | no | External provider name |
| `external_home_id` | string | no | External home ID |
| `external_metadata` | array | no | External metadata |

> \* Either `name` (Sinric) or `location` must be provided.

**Example Request:**
```json
{
  "name": "Main Barn",
  "location": "North Field",
  "timezone": "America/Chicago"
}
```

**Example Response (201):**
```json
{
  "success": true,
  "message": "Created successfully",
  "data": {
    "id": 2,
    "user_id": 1,
    "location": "North Field",
    "timezone": "America/Chicago",
    "external_provider": "sinric",
    "external_home_id": "home_def456",
    "external_metadata": null,
    "created_at": "2026-06-15T11:00:00.000000Z",
    "updated_at": "2026-06-15T11:00:00.000000Z"
  }
}
```

**Possible Status Codes:** 201, 401, 422

---

### GET /api/v1/farms/{farm}

Get a single farm with details. Syncs data from Sinric if applicable.

**Authentication:** Bearer token required

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "id": 1,
    "user_id": 1,
    "location": "North Field",
    "timezone": "America/Chicago",
    "external_provider": "sinric",
    "external_home_id": "home_abc123",
    "external_metadata": null,
    "created_at": "2026-06-01T08:00:00.000000Z",
    "updated_at": "2026-06-15T10:00:00.000000Z"
  }
}
```

**Possible Status Codes:** 200, 401, 403, 404

---

### PUT /api/v1/farms/{farm}

Update a farm. Updates the Sinric home if applicable.

**Authentication:** Bearer token required

**Request Body:** (all fields optional for update)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | no | Sinric home name |
| `location` | string | no | Farm location (unique per user) |
| `timezone` | string | no | Farm timezone |
| `imageUrl` | string (url) | no | Image URL |
| `external_provider` | string | no | External provider name |
| `external_home_id` | string | no | External home ID |
| `external_metadata` | array | no | External metadata |

**Example Request:**
```json
{
  "location": "South Field",
  "timezone": "America/New_York"
}
```

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "id": 1,
    "user_id": 1,
    "location": "South Field",
    "timezone": "America/New_York",
    "external_provider": "sinric",
    "external_home_id": "home_abc123",
    "external_metadata": null,
    "created_at": "2026-06-01T08:00:00.000000Z",
    "updated_at": "2026-06-15T11:30:00.000000Z"
  }
}
```

**Possible Status Codes:** 200, 401, 403, 404, 422

---

### DELETE /api/v1/farms/{farm}

Delete a farm. Deletes the Sinric home if applicable. Cascades to all child records (hog pens, hogs, devices, etc.) within a database transaction.

**Authentication:** Bearer token required

**Example Response (200):**
```json
{
  "success": true,
  "message": "Deleted successfully",
  "data": null
}
```

**Possible Status Codes:** 200, 401, 403, 404

---

### GET /api/v1/farms-summary

Get a summary of all farms owned by the authenticated user.

**Authentication:** Bearer token required

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "user_id": 1,
      "location": "North Field",
      "timezone": "America/Chicago"
    }
  ]
}
```

**Possible Status Codes:** 200, 401

---

## 4. Hog Pens

### GET /api/v1/hogpens

List all hog pens. Also accessible at `/api/v1/hog-pens` and `/api/v1/sinric/rooms`. Automatically syncs Sinric rooms first.

**Authentication:** Bearer token required

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "farm_id": 1,
      "name": "Pen A",
      "capacity": 20,
      "status": 1,
      "external_provider": "sinric",
      "external_room_id": "room_abc123",
      "external_metadata": null,
      "created_at": "2026-06-01T08:00:00.000000Z",
      "updated_at": "2026-06-15T10:00:00.000000Z"
    }
  ],
  "meta": { ... },
  "links": { ... }
}
```

**Possible Status Codes:** 200, 401

---

### POST /api/v1/hogpens

Create a new hog pen. Also accessible at `/api/v1/hog-pens` and `/api/v1/sinric/rooms`. Creates a Sinric room if user has Sinric token.

**Authentication:** Bearer token required

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `farm_id` | integer | yes | Farm ID (can be auto-resolved from `homeId`/`home_id`) |
| `name` | string | yes | Hog pen name |
| `capacity` | integer | no | Hog pen capacity |
| `status` | integer | no | Hog pen status |
| `description` | string | no | Description (max 1000 chars) |
| `imageUrl` | string (url) | no | Image URL |
| `external_provider` | string | no | External provider |
| `external_room_id` | string | no | External room ID (auto-resolved from `id`/`roomId`/`room_id`) |
| `external_metadata` | array | no | External metadata |

**Example Request:**
```json
{
  "farm_id": 1,
  "name": "Pen B",
  "capacity": 15,
  "status": 1
}
```

**Example Response (201):**
```json
{
  "success": true,
  "message": "Created successfully",
  "data": {
    "id": 2,
    "farm_id": 1,
    "name": "Pen B",
    "capacity": 15,
    "status": 1,
    "external_provider": null,
    "external_room_id": null,
    "external_metadata": null,
    "created_at": "2026-06-15T12:00:00.000000Z",
    "updated_at": "2026-06-15T12:00:00.000000Z"
  }
}
```

**Possible Status Codes:** 201, 401, 422

---

### GET /api/v1/hogpens/{hogPen}

Get a single hog pen. Syncs from Sinric if applicable.

**Authentication:** Bearer token required

**Example Response (200):** Same shape as single item in list response.

**Possible Status Codes:** 200, 401, 403, 404

---

### PUT /api/v1/hogpens/{hogPen}

Update a hog pen. Updates the Sinric room if applicable.

**Authentication:** Bearer token required

**Request Body:** (all fields optional)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `farm_id` | integer | no | Farm ID |
| `name` | string | no | Hog pen name |
| `capacity` | integer | no | Capacity |
| `status` | integer | no | Status |
| `description` | string | no | Description |
| `imageUrl` | string (url) | no | Image URL |
| `external_metadata` | array | no | External metadata |

**Example Response (200):** Same shape as create response.

**Possible Status Codes:** 200, 401, 403, 404, 422

---

### PUT /api/v1/sinric/rooms

Update a hog pen by its Sinric room ID. Looks up the pen using the `external_room_id` field from the request body.

**Authentication:** Bearer token required

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `roomId` / `room_id` / `id` | string | yes | Sinric room ID (used to find the local hog pen) |
| `name` | string | no | Updated name |
| `capacity` | integer | no | Updated capacity |
| Other pen fields | varies | no | Other optional pen fields |

**Example Response (200):** Same shape as regular pen update.

**Possible Status Codes:** 200, 401, 403, 404, 422

---

### DELETE /api/v1/hogpens/{hogPen}

Delete a hog pen. Deletes the Sinric room if applicable. Cascades to all child records.

**Authentication:** Bearer token required

**Example Response (200):**
```json
{
  "success": true,
  "message": "Deleted successfully",
  "data": null
}
```

**Possible Status Codes:** 200, 401, 403, 404

---

### DELETE /api/v1/sinric/rooms/{roomId?}

Delete a hog pen by its Sinric room ID. If `roomId` is not provided, it is read from the request body (`roomId` or `id`).

**Authentication:** Bearer token required

**Example Response (200):**
```json
{
  "success": true,
  "message": "Deleted successfully",
  "data": null
}
```

**Possible Status Codes:** 200, 401, 403, 404

---

### GET /api/v1/farms/{farmId}/hog-pens-summary

Get a summary of all hog pens belonging to a specific farm.

**Authentication:** Bearer token required

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "farm_id": 1,
      "name": "Pen A",
      "capacity": 20,
      "status": 1
    }
  ]
}
```

**Possible Status Codes:** 200, 401

---

## 5. Hogs

### GET /api/v1/hogs

List all hogs owned by the authenticated user (paginated).

**Authentication:** Bearer token required

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "hog_pen_id": 1,
      "ear_tag_id": "ET-001",
      "breed": "Large White",
      "gender": "Female",
      "current_age": 120,
      "weight_current": 85.50,
      "created_at": "2026-06-01T08:00:00.000000Z",
      "updated_at": "2026-06-15T10:00:00.000000Z"
    }
  ],
  "meta": { ... },
  "links": { ... }
}
```

**Possible Status Codes:** 200, 401

---

### POST /api/v1/hogs

Create a new hog.

**Authentication:** Bearer token required

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `hog_pen_id` | integer | yes | Must exist in `hog_pens` table |
| `ear_tag_id` | string | yes | Ear tag identifier |
| `breed` | string | yes | Hog breed |
| `gender` | string | yes | Hog gender |
| `current_age` | integer | yes | Age in days (min: 0) |
| `weight_current` | numeric | yes | Current weight (kg) |

**Example Request:**
```json
{
  "hog_pen_id": 1,
  "ear_tag_id": "ET-002",
  "breed": "Duroc",
  "gender": "Male",
  "current_age": 90,
  "weight_current": 72.30
}
```

**Example Response (201):**
```json
{
  "success": true,
  "message": "Created successfully",
  "data": {
    "id": 2,
    "hog_pen_id": 1,
    "ear_tag_id": "ET-002",
    "breed": "Duroc",
    "gender": "Male",
    "current_age": 90,
    "weight_current": 72.30,
    "created_at": "2026-06-15T12:00:00.000000Z",
    "updated_at": "2026-06-15T12:00:00.000000Z"
  }
}
```

**Possible Status Codes:** 201, 401, 403, 422

---

### GET /api/v1/hogs/{hog}

Get a single hog.

**Authentication:** Bearer token required

**Possible Status Codes:** 200, 401, 403, 404

---

### PUT /api/v1/hogs/{hog}

Update a hog.

**Authentication:** Bearer token required

**Request Body:** (all fields optional)

| Field | Type | Description |
|-------|------|-------------|
| `hog_pen_id` | integer | Hog pen ID |
| `ear_tag_id` | string | Ear tag identifier |
| `breed` | string | Breed |
| `gender` | string | Gender |
| `current_age` | integer | Age in days |
| `weight_current` | numeric | Current weight |

**Possible Status Codes:** 200, 401, 403, 404, 422

---

### DELETE /api/v1/hogs/{hog}

Delete a hog.

**Authentication:** Bearer token required

**Example Response (200):**
```json
{
  "success": true,
  "message": "Deleted successfully",
  "data": null
}
```

**Possible Status Codes:** 200, 401, 403, 404

---

## 6. Hog Daily Records

### GET /api/v1/hog-daily-records

List daily records (paginated).

**Authentication:** Bearer token required

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "hog_id": 1,
      "hog_pen_id": 1,
      "weight": 85.50,
      "feed_consumed": 2.50,
      "health_status": "healthy",
      "temperature": 38.50,
      "activity_level": "normal",
      "notes": "Normal feeding behavior",
      "recorded_date": "2026-06-15T00:00:00.000000Z",
      "created_at": "2026-06-15T08:00:00.000000Z",
      "updated_at": "2026-06-15T08:00:00.000000Z"
    }
  ],
  "meta": { ... },
  "links": { ... }
}
```

**Possible Status Codes:** 200, 401

---

### POST /api/v1/hog-daily-records

Create a daily record.

**Authentication:** Bearer token required

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `hog_id` | integer | yes | Must exist in `hogs` table |
| `hog_pen_id` | integer | yes | Must exist in `hog_pens` table |
| `weight` | numeric | yes | Hog weight (kg) |
| `feed_consumed` | numeric | yes | Feed consumed (kg) |
| `health_status` | string | yes | Health status description |
| `temperature` | numeric | yes | Body temperature |
| `activity_level` | string | yes | Activity level description |
| `notes` | string | yes | Notes (max 255 chars) |
| `recorded_date` | date | yes | Date of record |

**Example Request:**
```json
{
  "hog_id": 1,
  "hog_pen_id": 1,
  "weight": 86.00,
  "feed_consumed": 2.75,
  "health_status": "healthy",
  "temperature": 38.60,
  "activity_level": "normal",
  "notes": "Good appetite",
  "recorded_date": "2026-06-16"
}
```

**Possible Status Codes:** 201, 401, 403, 422

---

### GET /api/v1/hog-daily-records/{hogDailyRecord}

**Possible Status Codes:** 200, 401, 403, 404

### PUT /api/v1/hog-daily-records/{hogDailyRecord}

**Possible Status Codes:** 200, 401, 403, 404, 422

### DELETE /api/v1/hog-daily-records/{hogDailyRecord}

**Possible Status Codes:** 200, 401, 403, 404

---

## 7. Feeders

### GET /api/v1/feeders

List feeders (paginated).

**Authentication:** Bearer token required

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "hog_pen_id": 1,
      "device_id": 1,
      "status": "active",
      "last_refill": "2026-06-10T08:00:00.000000Z",
      "created_at": "2026-06-01T08:00:00.000000Z",
      "updated_at": "2026-06-10T08:00:00.000000Z"
    }
  ],
  "meta": { ... },
  "links": { ... }
}
```

### POST /api/v1/feeders

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `hog_pen_id` | integer | yes | Must exist in `hog_pens` table |
| `device_id` | integer | yes | Must exist in `iot_devices` table |
| `status` | string | yes | Feeder status |
| `last_refill` | date | no | Last refill timestamp |

**Possible Status Codes:** 201, 401, 403, 422

### GET /api/v1/feeders/{feeder}

### PUT /api/v1/feeders/{feeder}

### DELETE /api/v1/feeders/{feeder}

All follow standard CRUD pattern.

---

## 8. Feeder Feed Type Mapping

### GET /api/v1/feeder-feed-type-mapping

List feed type mappings (paginated).

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "feeder_id": 1,
      "feed_type": "Grower Pellet",
      "relay_pin": 3,
      "max_duration_seconds": 30,
      "is_active": true,
      "created_at": "2026-06-01T08:00:00.000000Z",
      "updated_at": "2026-06-01T08:00:00.000000Z"
    }
  ]
}
```

### POST /api/v1/feeder-feed-type-mapping

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `feeder_id` | integer | yes | Must exist in `feeders` table |
| `feed_type` | string | yes | Feed type name (unique per feeder) |
| `relay_pin` | integer | no | Relay pin number |
| `max_duration_seconds` | integer | no | Max feed duration (default: 30, min: 1) |
| `is_active` | boolean | no | Whether mapping is active |

**Possible Status Codes:** 201, 422

Standard CRUD for GET/GET by ID/PUT/DELETE.

---

## 9. Feeding Logs

### GET /api/v1/feeding-logs

List feeding logs (paginated).

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "feeder_id": 1,
      "pen_id": 1,
      "feed_amount_given": 15.50,
      "triggered": "schedule",
      "created_at": "2026-06-15T08:00:00.000000Z",
      "updated_at": "2026-06-15T08:00:00.000000Z"
    }
  ]
}
```

### POST /api/v1/feeding-logs

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `feeder_id` | integer | yes | Must exist in `feeders` table |
| `pen_id` | integer | yes | Must exist in `hog_pens` table |
| `feed_amount_given` | numeric | yes | Amount of feed dispensed |
| `triggered` | string | yes | How feeding was triggered (e.g., "schedule", "manual") |

Standard CRUD.

---

## 10. Feeding Schedule

### GET /api/v1/feeding-schedule

List feeding schedules (paginated).

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "hog_pen_id": 1,
      "time": "2026-06-15T08:00:00.000000Z",
      "feed_amount": 25.00,
      "feed_type": "Grower Pellet",
      "mode": "auto",
      "feeding_times": ["08:00", "12:00", "18:00"],
      "daily_feeding_count": 3,
      "created_at": "2026-06-01T08:00:00.000000Z",
      "updated_at": "2026-06-01T08:00:00.000000Z"
    }
  ]
}
```

### POST /api/v1/feeding-schedule

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `hog_pen_id` | integer | yes | Must exist in `hog_pens` table |
| `time` | date | yes | Feeding time |
| `feed_amount` | numeric | yes | Feed amount |
| `feed_type` | string | no | Feed type |
| `mode` | string | no | Mode (default: "auto") |
| `feeding_times` | array | no | Array of feeding times |
| `daily_feeding_count` | integer | no | Number of feedings per day (min: 1) |

Standard CRUD.

---

## 11. Feeding Predictions

### POST /api/v1/feeding-predictions/generate

Generate a new feeding prediction for a hog pen. Calls the external FastAPI ML service, caches the result, and returns the prediction.

**Authentication:** Bearer token required

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `hog_pen_id` | integer | yes | Must exist in `hog_pens` table |
| `force_refresh` | boolean | no | Force fresh prediction (ignore cache) |
| `feeding_frequency` | integer | no | Feeding frequency per day (min: 2) |
| `feeding_times` | array | no | Array of exactly 3 feeding times |
| `feeding_times.*` | string | no | Each time value (max 20 chars) |
| `schedule_type` | string | no | Schedule type |

**Example Request:**
```json
{
  "hog_pen_id": 1,
  "force_refresh": true,
  "feeding_frequency": 3,
  "feeding_times": ["06:00", "12:00", "18:00"],
  "schedule_type": "standard"
}
```

**Example Response (200):**
```json
{
  "success": true,
  "message": "Feeding prediction generated successfully",
  "data": {
    "id": 5,
    "hog_pen_id": 1,
    "ml_model_id": 1,
    "predicted_feed_amount": 85.50,
    "confidence_score": 0.92,
    "model_used": "xgboost-v2",
    "confidence_level": "high",
    "confidence_reason": "Sufficient historical data available",
    "feed_recommendation": {
      "morning": 28.00,
      "afternoon": 28.50,
      "evening": 29.00
    },
    "feed_totals": {
      "total_daily": 85.50,
      "weekly": 598.50,
      "monthly": 2565.00
    },
    "weight_trend": {
      "current_avg": 72.30,
      "projected_avg": 78.50,
      "avg_daily_gain": 0.85
    },
    "pen_status": {
      "hog_count": 12,
      "active_count": 12,
      "sick_count": 0
    },
    "warnings": [],
    "alerts": [],
    "suggestions": [
      "Monitor feeder PF-03 for potential blockage"
    ],
    "fastapi_response": { ... },
    "predicted_at": "2026-06-15T12:00:00.000000Z",
    "created_at": "2026-06-15T12:00:00.000000Z",
    "updated_at": "2026-06-15T12:00:00.000000Z"
  }
}
```

**Possible Status Codes:** 200, 401, 403, 422, 502

---

### GET /api/v1/feeding-predictions

List feeding predictions (paginated).

### POST /api/v1/feeding-predictions

Create a prediction manually.

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `hog_pen_id` | integer | yes | Must exist in `hog_pens` table |
| `ml_model_id` | integer | yes | Must exist in `ml_models` table |
| `predicted_feed_amount` | numeric | yes | Predicted feed amount |
| `confidence_score` | numeric | yes | Confidence score (0-1) |
| `model_used` | string | no | Model identifier |
| `confidence_level` | string | no | Confidence level label |
| `confidence_reason` | string | no | Reason for confidence level |
| `feed_recommendation` | array | no | Feed recommendation data |
| `feed_totals` | array | no | Feed totals data |
| `weight_trend` | array | no | Weight trend data |
| `pen_status` | array | no | Pen status data |
| `warnings` | array | no | Warning messages |
| `alerts` | array | no | Alert messages |
| `suggestions` | array | no | Suggestions |
| `fastapi_response` | array | no | Raw FastAPI response |
| `predicted_at` | date | no | Prediction timestamp |

### GET /api/v1/feeding-predictions/{feedingPrediction}

### PUT /api/v1/feeding-predictions/{feedingPrediction}

### DELETE /api/v1/feeding-predictions/{feedingPrediction}

Standard CRUD.

---

## 12. IoT Devices

### GET /api/v1/iot-devices

List IoT devices (paginated). Automatically syncs Sinric devices first.

**Authentication:** Bearer token required

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "type": "feeder",
      "hog_pen_id": 1,
      "api_provider": "sinric",
      "status": "online",
      "external_provider": "sinric",
      "external_device_id": "device_abc123",
      "external_metadata": {
        "product": { "name": "Smart Feeder Pro", "code": "SF-200" },
        "isOnline": true
      },
      "created_at": "2026-06-01T08:00:00.000000Z",
      "updated_at": "2026-06-15T10:00:00.000000Z"
    }
  ],
  "meta": { ... },
  "links": { ... }
}
```

**Possible Status Codes:** 200, 401

---

### POST /api/v1/iot-devices

Create an IoT device. Creates a Sinric device if user has Sinric token.

**Authentication:** Bearer token required

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `hog_pen_id` | integer | yes | Must exist in `hog_pens` table |
| `type` | string | no* | Device type (required if `name` not provided) |
| `name` | string | no* | Sinric device name (required if `type` not provided) |
| `api_provider` | string | no | API provider |
| `status` | string | no | Device status |
| `description` | string | no | Description |
| `productId` | string | no | Sinric product ID |
| `roomId` | string | no | Sinric room ID |
| `macAddress` | string | no | MAC address |
| `lastConnectedSSID` | string | no | Last connected WiFi SSID |
| `hwVersion` | string | no | Hardware version |
| `swVersion` | string | no | Software version |
| `serialNumber` | string | no | Serial number |
| `lastIpAddress` | string | no | Last IP address |
| `customData` | string | no | Custom data |
| `accessKeyId` | string | no | Access key ID |
| `alias` | mixed | no | Device alias |
| `attributes` | array | no | Device attributes |
| `external_provider` | string | no | External provider |
| `external_device_id` | string | no | External device ID |
| `external_metadata` | array | no | External metadata |

> \* Either `type` or `name` must be provided.

**Possible Status Codes:** 201, 401, 403, 422

---

### GET /api/v1/iot-devices/{iotDevice}

Get a single IoT device. Syncs from Sinric if applicable.

**Possible Status Codes:** 200, 401, 403, 404

---

### PUT /api/v1/iot-devices/{iotDevice}

Update an IoT device. Updates the Sinric device if applicable.

**Possible Status Codes:** 200, 401, 403, 404, 422

---

### DELETE /api/v1/iot-devices/{iotDevice}

Delete an IoT device. Deletes the Sinric device if applicable.

**Possible Status Codes:** 200, 401, 403, 404

---

### GET /api/v1/devices/{deviceId}/action

Send an action command to a Sinric device.

**Authentication:** Bearer token required

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | yes | Action to send (e.g., "setPowerState", "setValue") |
| `value` | string | varies | Value for the action |

**Example Request:**
```
GET /api/v1/devices/device_abc123/action?action=setPowerState&value=on
```

**Example Response (200):**
```json
{
  "success": true,
  "message": "Action executed successfully",
  "data": null
}
```

**Possible Status Codes:** 200, 401, 403, 422, 502

---

## 13. Device Logs

### GET /api/v1/device-logs

List device logs (paginated).

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "device_id": 1,
      "action": "setPowerState",
      "response": "success",
      "created_at": "2026-06-15T08:00:00.000000Z",
      "updated_at": "2026-06-15T08:00:00.000000Z"
    }
  ]
}
```

### POST /api/v1/device-logs

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `device_id` | integer | yes | Must exist in `iot_devices` table |
| `action` | string | yes | Action performed |
| `response` | string | yes | Response from action |

Standard CRUD.

---

## 14. Device Credentials

### GET /api/v1/device-credentials

List device credentials (paginated).

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "user_id": 1,
      "iot_device_id": 1,
      "name": "Feeder API Key",
      "abilities": ["read", "write"],
      "last_used_at": null,
      "revoked_at": null,
      "created_at": "2026-06-01T08:00:00.000000Z",
      "updated_at": "2026-06-01T08:00:00.000000Z"
    }
  ]
}
```

> Note: `api_key` and `secret` fields are hidden from API responses.

### POST /api/v1/device-credentials

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | integer | yes | Must exist in `users` table |
| `iot_device_id` | integer | no | Must exist in `iot_devices` table (if provided) |
| `name` | string | yes | Credential name |
| `api_key` | string | yes | API key (unique) |
| `secret` | string | yes | Secret value |
| `abilities` | array | no | Array of permission abilities |
| `last_used_at` | date | no | Last used timestamp |
| `revoked_at` | date | no | Revocation timestamp |

Standard CRUD.

---

## 15. Sensors

### GET /api/v1/sensors

List sensors (paginated).

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "hog_pen_id": 1,
      "sensor_type": "temperature",
      "device_id": 1,
      "status": "active",
      "created_at": "2026-06-01T08:00:00.000000Z",
      "updated_at": "2026-06-01T08:00:00.000000Z"
    }
  ]
}
```

### POST /api/v1/sensors

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `hog_pen_id` | integer | yes | Must exist in `hog_pens` table |
| `sensor_type` | string | yes | Sensor type |
| `device_id` | integer | yes | Must exist in `iot_devices` table |
| `status` | string | yes | Sensor status |

Standard CRUD.

---

## 16. Sensor Readings

### GET /api/v1/sensor-readings

List sensor readings (paginated).

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "sensor_id": 1,
      "value": 25.50,
      "unit": "celsius",
      "created_at": "2026-06-15T08:00:00.000000Z",
      "updated_at": "2026-06-15T08:00:00.000000Z"
    }
  ]
}
```

### POST /api/v1/sensor-readings

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `sensor_id` | integer | yes | Must exist in `sensors` table |
| `value` | numeric | yes | Sensor reading value |
| `unit` | string | yes | Unit of measurement |

Standard CRUD.

---

## 17. ML Models

### GET /api/v1/ml-models

List ML models (paginated).

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "model_name": "XGBoost Feeder v2",
      "version": "2.1.0",
      "accuracy_score": 0.94,
      "created_at": "2026-06-01T08:00:00.000000Z",
      "updated_at": "2026-06-01T08:00:00.000000Z"
    }
  ]
}
```

### POST /api/v1/ml-models

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `model_name` | string | yes | Model name |
| `version` | string | yes | Model version |
| `accuracy_score` | numeric | yes | Model accuracy score |

Standard CRUD. Note: ML Models do **not** have user-ownership scoping (no `BelongsToUser` trait).

---

## 18. Prediction Cache

### GET /api/v1/prediction-cache

List cached predictions (paginated).

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "prediction_type": "feeding",
      "pen_id": 1,
      "cache_key": "abc123def456hash",
      "data": {
        "predicted_feed_amount": 85.50,
        "confidence_score": 0.92
      },
      "expires_at": "2026-06-15T12:30:00.000000Z",
      "created_at": "2026-06-15T12:00:00.000000Z",
      "updated_at": "2026-06-15T12:00:00.000000Z"
    }
  ]
}
```

### POST /api/v1/prediction-cache

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `prediction_type` | string | yes | Type of prediction |
| `pen_id` | integer | yes | Must exist in `hog_pens` table |
| `cache_key` | string | yes | Unique cache key |
| `data` | array | yes | Cached prediction data |
| `expires_at` | date | no | Cache expiration timestamp |

Standard CRUD.

---

## 19. Alerts

### GET /api/v1/alerts

List alerts (paginated).

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "farm_id": 1,
      "hog_pen_id": 1,
      "type": "temperature_alert",
      "message": "Pen temperature exceeds threshold",
      "severity": "high",
      "status": "active",
      "created_at": "2026-06-15T08:00:00.000000Z",
      "updated_at": "2026-06-15T08:00:00.000000Z"
    }
  ]
}
```

### POST /api/v1/alerts

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `farm_id` | integer | yes | Must exist in `farms` table |
| `hog_pen_id` | integer | yes | Must exist in `hog_pens` table |
| `type` | string | yes | Alert type (max 50 chars) |
| `message` | string | yes | Alert message (max 1000 chars) |
| `severity` | enum | yes | Must be one of: `low`, `medium`, `high`, `critical` |
| `status` | enum | yes | Must be one of: `active`, `resolved` |

Standard CRUD.

---

## 20. Daily Farm Reports

### GET /api/v1/daily-farm-reports

List daily farm reports (paginated).

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "farm_id": 1,
      "total_feed_consumed": 250.00,
      "total_hogs": 50,
      "avg_weight": 75.30,
      "mortality_count": 0.00,
      "report_date": "2026-06-15T00:00:00.000000Z",
      "active_pens": 3,
      "avg_temperature": 25.50,
      "avg_humidity": 65.00,
      "alerts_triggered": 2,
      "sick_hogs": 1,
      "avg_weekly_weight_gain": 3.50,
      "created_at": "2026-06-15T08:00:00.000000Z",
      "updated_at": "2026-06-15T08:00:00.000000Z"
    }
  ]
}
```

### POST /api/v1/daily-farm-reports

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `farm_id` | integer | yes | Must exist in `farms` table |
| `total_feed_consumed` | numeric | yes | Total feed consumed |
| `total_hogs` | integer | yes | Total hog count |
| `avg_weight` | numeric | yes | Average weight |
| `mortality_count` | numeric | yes | Mortality count |
| `report_date` | date | yes | Report date (unique per farm) |
| `active_pens` | integer | no | Active pen count |
| `avg_temperature` | numeric | no | Average temperature |
| `avg_humidity` | numeric | no | Average humidity |
| `alerts_triggered` | integer | no | Number of alerts triggered |
| `sick_hogs` | integer | no | Number of sick hogs |
| `avg_weekly_weight_gain` | numeric | no | Average weekly weight gain |

Standard CRUD.

---

## 21. Analytics

All analytics endpoints require authentication.

---

### GET /api/v1/analytics/overview

Get an aggregated overview of the authenticated user's farm data.

**Authentication:** Bearer token required

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "total_farms": 2,
    "total_pens": 5,
    "total_hogs": 48,
    "total_devices": {
      "all": 8,
      "online": 6,
      "offline": 2
    },
    "active_alerts": 1,
    "today_feeding_logs": 12,
    "today_feeding_amount": 185.50,
    "recent_webhook_failures": 0
  }
}
```

---

### GET /api/v1/analytics/devices

Get device status analytics. Also accessible at `/api/v1/analytics/devices/status`.

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "status_counts": {
      "online": 6,
      "offline": 2
    },
    "type_counts": {
      "feeder": 4,
      "sensor": 3,
      "controller": 1
    },
    "sinric_online": 6,
    "sinric_offline": 2,
    "offline_devices": [
      {
        "id": 2,
        "type": "feeder",
        "hog_pen_id": 1,
        "api_provider": "sinric",
        "status": "offline",
        "external_provider": "sinric",
        "external_device_id": "device_def456",
        "external_metadata": { ... },
        "created_at": "2026-06-01T08:00:00.000000Z",
        "updated_at": "2026-06-15T10:00:00.000000Z"
      }
    ]
  }
}
```

---

### GET /api/v1/analytics/feeding

Get feeding analytics.

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "total_logs": 250,
    "total_feed_amount": 3850.75,
    "today_logs": 12,
    "today_feed_amount": 185.50
  }
}
```

---

### GET /api/v1/analytics/farms/{farm}/summary

Get a detailed summary for a specific farm.

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "farm": {
      "id": 1,
      "location": "North Field",
      "timezone": "America/Chicago"
    },
    "pens": 3,
    "hogs": 25,
    "devices": {
      "all": 4,
      "by_status": { "online": 3, "offline": 1 },
      "by_type": { "feeder": 2, "sensor": 2 }
    },
    "alerts": {
      "by_severity": { "low": 0, "medium": 1, "high": 0, "critical": 0 },
      "by_status": { "active": 1, "resolved": 2 }
    },
    "feeding_last_7_days": [
      { "date": "2026-06-09", "total": 245.00 },
      { "date": "2026-06-10", "total": 252.00 },
      { "date": "2026-06-11", "total": 248.50 },
      { "date": "2026-06-12", "total": 260.00 },
      { "date": "2026-06-13", "total": 255.00 },
      { "date": "2026-06-14", "total": 258.00 },
      { "date": "2026-06-15", "total": 185.50 }
    ],
    "latest_report": {
      "report_date": "2026-06-15",
      "total_feed_consumed": 250.00,
      "total_hogs": 25,
      "avg_weight": 75.30
    },
    "latest_webhook_logs": []
  }
}
```

---

## 22. Webhook Logs

### GET /api/v1/webhook-logs

List webhook logs (paginated).

**Example Response (200):**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "url": "https://hooks.example.com/notify",
      "event": "prediction.completed",
      "payload": {
        "prediction_id": 5,
        "hog_pen_id": 1
      },
      "status": "sent",
      "error": null,
      "farm_id": 1,
      "created_at": "2026-06-15T12:00:00.000000Z",
      "updated_at": "2026-06-15T12:00:00.000000Z"
    }
  ]
}
```

### POST /api/v1/webhook-logs

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `url` | string (url) | yes | Webhook URL |
| `event` | string | yes | Event name |
| `payload` | array | yes | Webhook payload |
| `status` | enum | no | Must be `sent` or `failed` |
| `error` | string | no | Error message (if failed) |
| `farm_id` | integer | no | Must exist in `farms` table (if provided) |

Standard CRUD.

---

## 23. Sinric Sync

### POST /api/v1/sinric/sync

Manually trigger a full sync from SinricPro (homes → rooms → devices).

**Authentication:** Bearer token required

**Example Response (200):**
```json
{
  "success": true,
  "message": "Sinric data synced successfully",
  "data": {
    "homes_synced": 2,
    "rooms_synced": 5,
    "devices_synced": 8
  }
}
```

**Possible Status Codes:** 200, 401

---

## 24. Database Schema Reference

### Entity Relationship Diagram (Conceptual)

```
users (1) ──< farms (N) ──< hog_pens (N) ──< hogs (N) ──< hog_daily_records (N)
                    │              │
                    │              ├──< iot_devices (N) ──< device_logs (N)
                    │              │       │              < device_commands (N)
                    │              │       │              < device_credentials (N)
                    │              │       │              < sensors (N) ──< sensor_readings (N)
                    │              │       │              < feeders (N) ──< feeder_feed_type_mapping (N)
                    │              │       │                              < feeding_logs (N)
                    │              │       │                              < feeding_queue (N)
                    │              │       │
                    │              │       └──< feeding_schedule (N)
                    │              │          < feeding_predictions (N)
                    │              │          < prediction_cache (N)
                    │              │
                    │              └──< alerts (N)
                    │
                    ├──< daily_farm_reports (N)
                    ├──< webhook_logs (N)
                    └──< device_credentials (N)
```

### Table Schemas

#### `users`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| name | string | NOT NULL |
| email | string | NOT NULL, UNIQUE |
| email_verified_at | timestamp | nullable |
| password | string | NOT NULL, hashed |
| remember_token | string | nullable |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |
| sinric_user_id | string | nullable, UNIQUE |
| access_token | text | nullable, encrypted |
| refresh_token | text | nullable, encrypted |
| last_login_at | timestamp | nullable |

#### `farms`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| user_id | bigint | FK → users.id, NOT NULL, INDEX |
| location | string | NOT NULL |
| timezone | string | NOT NULL |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |
| external_provider | string | nullable |
| external_home_id | string | nullable |
| external_metadata | json | nullable |

Indexes: `(external_provider, external_home_id)`, `(user_id, created_at)`  
Unique: `(user_id, location)`

#### `hog_pens`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| farm_id | bigint | FK → farms.id, NOT NULL, INDEX |
| name | string | NOT NULL |
| capacity | integer | NOT NULL |
| status | integer | NOT NULL |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |
| external_provider | string | nullable |
| external_room_id | string | nullable |
| external_metadata | json | nullable |

Indexes: `(external_provider, external_room_id)`, `(farm_id, created_at)`

#### `hogs`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| hog_pen_id | bigint | FK → hog_pens.id, NOT NULL, INDEX |
| ear_tag_id | string | NOT NULL |
| breed | string | NOT NULL |
| gender | string | NOT NULL |
| current_age | integer | NOT NULL |
| weight_current | decimal(8,2) | NOT NULL |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |

Index: `(hog_pen_id, created_at)`

#### `hog_daily_records`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| hog_id | bigint | FK → hogs.id, NOT NULL, INDEX |
| hog_pen_id | bigint | FK → hog_pens.id, NOT NULL, INDEX |
| weight | decimal(8,2) | NOT NULL |
| feed_consumed | decimal(8,2) | NOT NULL |
| health_status | string | NOT NULL |
| temperature | decimal(8,2) | NOT NULL |
| activity_level | string | NOT NULL |
| notes | string | NOT NULL |
| recorded_date | timestamp | NOT NULL |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |

#### `iot_devices`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| type | string | NOT NULL |
| hog_pen_id | bigint | FK → hog_pens.id, NOT NULL, INDEX |
| api_provider | string | NOT NULL |
| status | string | NOT NULL |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |
| external_provider | string | nullable |
| external_device_id | string | nullable |
| external_metadata | json | nullable |

Indexes: `(external_provider, external_device_id)`, `(hog_pen_id, created_at)`

#### `feeders`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| hog_pen_id | bigint | FK → hog_pens.id, NOT NULL, INDEX |
| device_id | bigint | FK → iot_devices.id, NOT NULL |
| status | string | NOT NULL |
| last_refill | timestamp | nullable |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |

Index: `(hog_pen_id, created_at)`

#### `feeder_feed_type_mapping`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| feeder_id | bigint | FK → feeders.id, CASCADE DELETE |
| feed_type | string | NOT NULL |
| relay_pin | integer | nullable |
| max_duration_seconds | integer | DEFAULT 30 |
| is_active | boolean | DEFAULT true |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |

Unique: `(feeder_id, feed_type)`

#### `feeding_logs`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| feeder_id | bigint | FK → feeders.id, NOT NULL, INDEX |
| pen_id | bigint | FK → hog_pens.id, NOT NULL, INDEX |
| feed_amount_given | decimal(8,2) | NOT NULL |
| triggered | string | NOT NULL |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |

Indexes: `(feeder_id, created_at)`, `(pen_id, created_at)`

#### `feeding_schedule`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| hog_pen_id | bigint | FK → hog_pens.id, CASCADE DELETE |
| time | timestamp | NOT NULL |
| feed_amount | decimal(8,2) | NOT NULL |
| feed_type | string | nullable |
| mode | string | DEFAULT 'auto' |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |
| feeding_times | json | nullable |
| daily_feeding_count | smallint | DEFAULT 1, INDEX |

Index: `(hog_pen_id, created_at)`

#### `feeding_predictions`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| hog_pen_id | bigint | FK → hog_pens.id, NOT NULL, INDEX |
| ml_model_id | bigint | FK → ml_models.id, NOT NULL, INDEX |
| predicted_feed_amount | decimal(8,2) | NOT NULL |
| confidence_score | decimal(8,2) | NOT NULL |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |
| model_used | string | nullable |
| confidence_level | string | nullable |
| confidence_reason | text | nullable |
| feed_recommendation | json | nullable |
| feed_totals | json | nullable |
| weight_trend | json | nullable |
| pen_status | json | nullable |
| warnings | json | nullable |
| alerts | json | nullable |
| suggestions | json | nullable |
| fastapi_response | json | nullable |
| predicted_at | timestamp | nullable |

Index: `(hog_pen_id, created_at)`

#### `feeding_queue`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| feeder_id | bigint | FK → feeders.id, CASCADE DELETE |
| hog_pen_id | bigint | FK → hog_pens.id, CASCADE DELETE |
| feed_type | string | NOT NULL |
| scheduled_at | timestamp | NOT NULL |
| actual_feed_time | timestamp | nullable |
| status | string | DEFAULT 'pending' |
| duration_seconds | integer | DEFAULT 30 |
| amount_dispensed | decimal(8,2) | nullable |
| error_message | text | nullable |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |

Indexes: `(feeder_id, scheduled_at)`, `(hog_pen_id, status, scheduled_at)`, `(status, scheduled_at)`

#### `device_logs`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| device_id | bigint | FK → iot_devices.id, NOT NULL, INDEX |
| action | string | NOT NULL |
| response | string | NOT NULL |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |

Index: `(device_id, created_at)`

#### `device_commands`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| iot_device_id | bigint | FK → iot_devices.id, CASCADE DELETE |
| action | string | NOT NULL |
| payload | json | nullable |
| status | string | DEFAULT 'pending', CHECK: (pending, processing, completed, failed) |
| executed_at | timestamp | nullable |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |

Index: `(iot_device_id, status, created_at)`

#### `device_credentials`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| user_id | bigint | FK → users.id, CASCADE DELETE |
| iot_device_id | bigint | FK → iot_devices.id, NULL ON DELETE |
| name | string | NOT NULL |
| api_key | string | NOT NULL, UNIQUE |
| secret | string | NOT NULL |
| abilities | json | nullable |
| last_used_at | timestamp | nullable |
| revoked_at | timestamp | nullable |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |

Index: `(iot_device_id, revoked_at)`

#### `sensors`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| hog_pen_id | bigint | FK → hog_pens.id, NOT NULL, INDEX |
| sensor_type | string | NOT NULL |
| device_id | bigint | FK → iot_devices.id, NOT NULL, INDEX |
| status | string | NOT NULL |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |

Index: `(hog_pen_id, created_at)`

#### `sensor_readings`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| sensor_id | bigint | FK → sensors.id, NOT NULL, INDEX |
| value | decimal(8,2) | NOT NULL |
| unit | string | NOT NULL |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |

Index: `(sensor_id, created_at)`

#### `ml_models`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| model_name | string | NOT NULL |
| version | string | NOT NULL |
| accuracy_score | decimal(8,2) | NOT NULL |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |

#### `prediction_cache`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| prediction_type | string | NOT NULL |
| pen_id | bigint | FK → hog_pens.id, CASCADE DELETE |
| cache_key | string | NOT NULL, UNIQUE |
| data | json | NOT NULL |
| expires_at | timestamp | nullable |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |

Indexes: `(expires_at)`, `(prediction_type, pen_id)`

#### `alerts`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| farm_id | bigint | FK → farms.id, NOT NULL, INDEX |
| hog_pen_id | bigint | FK → hog_pens.id, NOT NULL, INDEX |
| type | string | NOT NULL |
| message | string | NOT NULL |
| severity | string | NOT NULL |
| status | string | NOT NULL |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |

Indexes: `(farm_id, created_at)`, `(hog_pen_id, created_at)`

#### `daily_farm_reports`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| farm_id | bigint | FK → farms.id, NOT NULL, INDEX |
| total_feed_consumed | decimal(8,2) | NOT NULL |
| total_hogs | integer | NOT NULL |
| avg_weight | decimal(8,2) | NOT NULL |
| mortality_count | decimal(8,2) | NOT NULL |
| report_date | timestamp | NOT NULL |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |
| active_pens | integer | DEFAULT 0 |
| avg_temperature | decimal(8,2) | DEFAULT 0 |
| avg_humidity | decimal(8,2) | DEFAULT 0 |
| alerts_triggered | integer | DEFAULT 0 |
| sick_hogs | integer | DEFAULT 0 |
| avg_weekly_weight_gain | decimal(8,2) | DEFAULT 0 |

Unique: `(farm_id, report_date)`
Index: `(farm_id, report_date)`

#### `webhook_logs`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| url | string | NOT NULL |
| event | string | NOT NULL, INDEX |
| payload | json | NOT NULL |
| status | string | DEFAULT 'sent', INDEX, CHECK: (sent, failed) |
| error | text | nullable |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |
| farm_id | bigint | FK → farms.id, CASCADE DELETE, nullable |

Index: `(farm_id, created_at)`

#### `personal_access_tokens`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| tokenable_type | string | NOT NULL |
| tokenable_id | bigint | NOT NULL, INDEX |
| name | string | NOT NULL |
| token | string(64) | NOT NULL, UNIQUE |
| abilities | text | nullable |
| last_used_at | timestamp | nullable |
| expires_at | timestamp | nullable |
| created_at | timestamp | nullable |
| updated_at | timestamp | nullable |

#### `cache`

| Column | Type | Constraints |
|--------|------|-------------|
| key | string | PK |
| value | mediumText | NOT NULL |
| expiration | bigint | NOT NULL, INDEX |

#### `cache_locks`

| Column | Type | Constraints |
|--------|------|-------------|
| key | string | PK |
| owner | string | NOT NULL |
| expiration | bigint | NOT NULL, INDEX |

#### `jobs`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| queue | string | NOT NULL, INDEX |
| payload | longText | NOT NULL |
| attempts | smallint | NOT NULL |
| reserved_at | integer | nullable |
| available_at | integer | NOT NULL |
| created_at | integer | NOT NULL |

#### `job_batches`

| Column | Type | Constraints |
|--------|------|-------------|
| id | string | PK |
| name | string | NOT NULL |
| total_jobs | integer | NOT NULL |
| pending_jobs | integer | NOT NULL |
| failed_jobs | integer | NOT NULL |
| failed_job_ids | longText | NOT NULL |
| options | mediumText | nullable |
| cancelled_at | integer | nullable |
| created_at | integer | NOT NULL |
| finished_at | integer | nullable |

#### `failed_jobs`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| uuid | string | NOT NULL, UNIQUE |
| connection | text | NOT NULL |
| queue | text | NOT NULL |
| payload | text | NOT NULL |
| exception | text | NOT NULL |
| failed_at | timestamp | NOT NULL, DEFAULT CURRENT_TIMESTAMP |

#### `password_reset_tokens`

| Column | Type | Constraints |
|--------|------|-------------|
| email | string | PK |
| token | string | NOT NULL |
| created_at | timestamp | nullable |

#### `sessions`

| Column | Type | Constraints |
|--------|------|-------------|
| id | string | PK |
| user_id | bigint | nullable, INDEX |
| ip_address | string(45) | nullable |
| user_agent | text | nullable |
| payload | longText | NOT NULL |
| last_activity | integer | NOT NULL, INDEX |

---

## 25. Common Errors

### 401 — Unauthenticated

Returned when a Bearer token is missing or invalid.

```json
{
  "message": "Unauthenticated."
}
```

### 403 — Forbidden

Returned when the authenticated user does not own the requested resource.

```json
{
  "success": false,
  "message": "This action is unauthorized.",
  "error": null
}
```

### 404 — Not Found

Returned when the requested resource does not exist.

```json
{
  "message": "",
  "exception": "Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException",
  ...
}
```

### 422 — Validation Error

Returned when request data fails validation.

```json
{
  "message": "The hog pen ID field is required. (and 1 more error)",
  "errors": {
    "hog_pen_id": ["The hog pen ID field is required."],
    "name": ["The name field is required."]
  }
}
```

### 429 — Too Many Requests

Returned when the rate limit is exceeded.

```json
{
  "message": "Too Many Attempts."
}
```

### 500 — Server Error

Returned for unexpected server errors.

```json
{
  "success": false,
  "message": "Server error",
  "error": null
}
```

### 502 — Bad Gateway (ML Service)

Returned when the external FastAPI ML prediction service is unreachable.

```json
{
  "success": false,
  "message": "Failed to connect to the prediction service. Please try again later.",
  "error": null
}
```

---

## Rate Limiting

| Endpoint | Limit |
|----------|-------|
| `POST /auth/login` | 5 requests per minute |
| `POST /auth/logout` | 10 requests per minute |
| `POST /auth/refresh-token` | 10 requests per minute |
| `POST /auth/reject-token` | 10 requests per minute |
| All other authenticated endpoints | Not throttled |

---

*End of API Reference — SmartHog API v2*
