# POS Offline Use – Limits & Sync (Phase 8)

This document describes the **offline POS** strategy, sync API, and recommended limits.

## Strategy

- **PWA / local-first:** When the POS runs in the browser, orders can be stored locally (e.g. in **localStorage** or **IndexedDB**) when the device is offline.
- When the connection is restored, the client **pushes** pending orders to the server via the **sync** API.
- The server supports **idempotent** submission: the client sends a unique `client_order_id` per sale so that duplicate submissions (e.g. retries) do not create duplicate POS records.

## Recommended Limits

| Limit              | Value   | Notes |
|-------------------|---------|--------|
| **Max offline hours** | 8 hours | Do not rely on offline mode beyond this without reconnecting. |
| **Max offline data**  | 50 MB   | Approximate cap for pending orders + cached products in local storage. |

These are **documented limits** for support and deployment. Development should validate behaviour with realistic data within these bounds.

## Sync API

### 1. Push pending orders: `POST /pos/sync`

- **Auth:** Required (session).
- **Body (JSON):**
  ```json
  {
    "sales": [
      {
        "client_order_id": "uuid-or-unique-string",
        "vc_name": "...",
        "warehouse_name": 1,
        "discount": 0,
        "payment_method": "cash",
        "date": "Y-m-d",
        "pos_order_type": "dine_in",
        "pos_table_id": null,
        "pos_shift_id": null,
        "cart": [ { "id", "name", "quantity", "price", "tax", "subtotal", ... } ]
      }
    ]
  }
  ```
- **Idempotency:** If `client_order_id` is present and a POS record with that `client_order_id` already exists for the same company, that sale is **skipped** and counted as successfully synced (no duplicate created).
- **Response:**
  ```json
  {
    "code": 200,
    "success": ":count sale(s) synced successfully.",
    "synced": 2,
    "total": 2,
    "errors": []
  }
  ```

### 2. Pull sync status: `GET or POST /pos/sync-status`

- **Auth:** Required.
- **Input:** `client_order_ids` – array of client-generated IDs (query for GET: `?client_order_ids[]=id1&client_order_ids[]=id2` or JSON body for POST).
- **Response:**
  ```json
  {
    "code": 200,
    "synced": ["id1", "id2"]
  }
  ```
- **Use:** The client can call this when back online to see which of its pending IDs are already on the server, then remove those from the local pending queue and only push the rest with `/pos/sync`.

## Client implementation notes

1. **Generate a unique `client_order_id`** per sale (e.g. UUID or `timestamp-random`) before saving offline.
2. **Store pending sales** in localStorage/IndexedDB with their `client_order_id`.
3. **On reconnect:** optionally call `sync-status` with all pending `client_order_ids`, then push only unsynced sales to `pos/sync`.
4. **On successful sync:** remove synced items from local pending storage.
5. **Respect limits:** avoid storing more than ~50 MB of pending data and do not assume offline operation beyond ~8 hours without validation.

## Backend behaviour

- **`pos.client_order_id`** is stored when provided; duplicate `client_order_id` for the same company is treated as “already completed” and no duplicate POS/payment is created.
- **Sync status** endpoint returns only IDs that exist on the server for the current user’s company.
