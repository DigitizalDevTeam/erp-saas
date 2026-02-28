# Restaurant POS – Order Types (Dine-in / Delivery / Takeaway) – Implementation Plan

## 1. Objective

- **Dine-in**: Current flow remains — user selects **Order Type = Dine In** → selects **Branch/Warehouse** → sees **tables grid** → selects a table → cart is tied to that table. No change to existing behaviour.
- **Delivery & Takeaway**: No table. User selects **Order Type = Delivery** or **Takeaway** → must enter **Customer Name, Email, Phone** (required for these types) → add products → Send to production / Pay. Cart and order are identified by **order context** (e.g. one active non-dine-in order per shift, or a list of open orders), not by table.

The whole restaurant POS flow that today is “table-centric” must support a second path: **order-type-centric** with **customer details** when table is not used.

---

## 2. Current State (Brief)

| Area | Current behaviour |
|------|-------------------|
| **Order type** | Dropdown exists (Dine In / Delivery / Takeaway) but flow is same for all; table is always used. |
| **Layout** | Branch → Tables grid → Click table → `loadRestaurantOrder(tableId, shiftId, branchId)`. |
| **Cart key** | Session key `pos_restaurant_cart` with key `tableId_shiftId`. |
| **getRestaurantOrder** | Requires `table_id`; returns order by `table_id` + `branch_id` or `shift_id`. |
| **sendToProduction** | Requires `table_id`, `branch_id`, `shift_id`; creates/updates Pos by `table_id` + `shift_id`. |
| **Pos model** | `table_id` nullable in DB; `customer_id` exists. No guest name/email/phone on `pos`. |

---

## 3. High-Level Behaviour by Order Type

| Order type | Table | Customer fields | Cart / order identity | Join table / Split bill |
|------------|--------|-----------------|------------------------|--------------------------|
| **Dine-in** | Required. User selects from grid. | Optional (can use Walk-in). | `tableId_shiftId` (existing). | Yes. |
| **Delivery** | Not used. | **Required**: Name, Email, Phone. | e.g. `delivery_shiftId` or single open order per shift. | No. |
| **Takeaway** | Not used. | **Required**: Name, Email, Phone. | e.g. `takeaway_shiftId` or single open order per shift. | No. |

---

## 4. Data Model Changes

### 4.1 `pos` table (recommended)

- Keep `table_id` **nullable** (already is).
- Add optional guest fields for delivery/takeaway (when not using `customer_id`):
  - `guest_name` (string, nullable)
  - `guest_email` (string, nullable)
  - `guest_phone` (string, nullable)

**Alternative:** Use only `customer_id` and ensure a Customer is created/selected for delivery/takeaway (name, email, phone from Customer). Then no new columns, but flow must always create/link Customer. For “quick” orders without full customer master, guest columns are simpler.

**Recommendation:** Add `guest_*` columns so that:
- Dine-in can keep using table (and optional customer_id for billing).
- Delivery/Takeaway can work without creating a Customer record; receipts and reports show guest name/email/phone.

### 4.2 Order identity for non-dine-in

- **Option A – Single active order per (shift + order_type):**  
  One open (unpaid) delivery order and one open takeaway order per shift. Simple; cart key e.g. `delivery_{shiftId}` / `takeaway_{shiftId}`. When user “starts” delivery, we load or create that one order.

- **Option B – Multiple open orders per shift:**  
  List of open delivery/takeaway orders; user selects “New delivery order” or selects an existing open order. Cart key could be `pos_id` once order is created, or a temporary key until first “Send to production”. More flexible, more UI (list of orders).

**Recommendation for Phase 1:** Option A (single active delivery, single active takeaway per shift). Easiest to wire and matches “one order at a time” for non-dine-in. Can later extend to Option B if needed.

---

## 5. Backend Changes (Summary)

### 5.1 Migration

- Add to `pos`: `guest_name`, `guest_email`, `guest_phone` (nullable strings).

### 5.2 Pos model

- Add `guest_name`, `guest_email`, `guest_phone` to `$fillable`.
- Accessors or helpers if you want one place for “display name” (guest_name vs customer name).

### 5.3 getRestaurantOrder (or new endpoint)

- **Dine-in:** Keep current behaviour: require `table_id`; return order by table + branch/shift.
- **Delivery/Takeaway:** New behaviour:
  - Accept `order_type` + `shift_id` + `branch_id` (no `table_id`).
  - Find open Pos: `order_type` = delivery/takeaway, `shift_id`, `branch_id`, `table_id` null, no payment.
  - Return that order’s lines + session cart for key `delivery_{shiftId}` / `takeaway_{shiftId}`.
  - If no order, return empty order lines + empty session items.

### 5.4 sendToProduction

- **Validation:** If `order_type` is `delivery` or `takeaway`:
  - `table_id` not required (or must be null).
  - Require guest (or customer) info: either `guest_name` + `guest_email` + `guest_phone`, or `customer_id`.
- **Create/update Pos:**
  - For delivery/takeaway: set `table_id` = null; set `guest_name`, `guest_email`, `guest_phone` from request.
  - For dine-in: keep current logic (table_id required).
- **Session cart key:** For non-dine-in, use e.g. `delivery_{shiftId}` or `takeaway_{shiftId}` instead of `tableId_shiftId`.

### 5.5 Remove from cart / update cart (session)

- Current remove/update uses `table_id` and `shift_id` in session key. For delivery/takeaway, use the same key convention `delivery_{shiftId}` / `takeaway_{shiftId}` in the same `pos_restaurant_cart` structure so existing “remove from cart” / “update quantity” logic can be reused with a different key.

### 5.6 Pay / Create invoice

- Already receives `pos_id` or creates from cart. Ensure when loading POS for payment we use:
  - Dine-in: by table (existing).
  - Delivery/Takeaway: by open order for that shift + order_type (no table). Receipt and invoice should show guest name/email/phone (from `pos` or from linked customer).

### 5.7 Join table / Split bill

- Only for **dine-in**. In UI, hide “Join table” and “Split bill” when order type is Delivery or Takeaway.

### 5.8 Cancel order / Close table

- Cancel order: already by `pos_id`; works for all types.
- “Close table”: only for dine-in; hide for delivery/takeaway.

---

## 6. Front-End (UI/UX) Changes

### 6.1 Order type selector

- **Position:** Keep at top (e.g. in `pos/restaurant.blade.php`): Branch, Warehouse, **Order Type**, then either Table or Customer block.
- **Behaviour:**
  - **Dine-in:** Show “Select table” label + table grid. Hide “Customer (Name / Email / Phone)” block.
  - **Delivery / Takeaway:** Hide table grid and “Select table”. Show a **Customer / Guest** block: Name, Email, Phone (all required for these two types). Optional: “New order” button to clear and start a new delivery/takeaway order (Phase 1: one active per shift, so “New” could clear cart and create new order on next Send to production).

### 6.2 Customer / Guest block (Delivery & Takeaway)

- Fields: **Name** (required), **Email** (required), **Phone** (required).
- Place near the order type dropdown (or under it). Only visible when order type is Delivery or Takeaway.
- Validate before “Send to production” and “Pay”: if order type is delivery/takeaway and (guest_name or guest_email or guest_phone) is empty, show validation message and do not submit.

### 6.3 Table grid

- Shown only when order type = **Dine-in**.
- When user switches to Delivery/Takeaway: clear current table selection; load “delivery” or “takeaway” cart (see below). Optionally clear cart if you want strict “one context at a time”.
- When user switches to Dine-in: show table grid again; if no table selected, show placeholder “Select table”.

### 6.4 Cart and load order

- **Dine-in:** Keep current: on table click → `loadRestaurantOrder(tableId, shiftId, branchId)`. Cart key = `tableId_shiftId`.
- **Delivery/Takeaway:** On order type change to Delivery/Takeaway (or on branch/shift available and no table):
  - Call a variant of getRestaurantOrder with `order_type=delivery` or `takeaway`, `shift_id`, `branch_id`, no `table_id`.
  - Backend returns the single open order (if any) + session items for `delivery_{shiftId}` / `takeaway_{shiftId}`.
  - Render cart from that response (same cart UI as dine-in).

### 6.5 Add to cart (products)

- **Dine-in:** Add to session with key `tableId_shiftId` (existing).
- **Delivery/Takeaway:** Add to session with key `delivery_{shiftId}` or `takeaway_{shiftId}`. Backend “add to cart” (or equivalent) must accept this key when `table_id` is missing.

### 6.6 Send to production

- **Dine-in:** Send `branch_id`, `table_id`, `shift_id`, `order_type`, items (existing).
- **Delivery/Takeaway:** Send `branch_id`, `shift_id`, `order_type`, `guest_name`, `guest_email`, `guest_phone`, items. Do not send `table_id` (or send null). Backend creates/updates Pos with `table_id` null and guest fields set.

### 6.7 Pay button

- Same as now: enabled when cart has items. Pay flow uses `pos_id` (from current order). For delivery/takeaway, current order is the one loaded by shift + order_type (no table). Ensure payment/invoice creation uses guest name/email/phone on receipt.

### 6.8 Buttons to hide for Delivery/Takeaway

- **Join table**
- **Split bill**
- **Close table** (if shown for current table)

Show them only when order type is **Dine-in** and a table is selected.

### 6.9 Empty cart / New order

- For delivery/takeaway, “Empty cart” clears session cart for `delivery_{shiftId}` / `takeaway_{shiftId}` and optionally clears the guest fields so user can start a new order. If you keep one active order per shift, “Empty cart” can also reset the open Pos (e.g. cancel and allow new one) or just clear cart and keep same Pos; define clearly.

---

## 7. API / Routes Summary

| Action | Dine-in | Delivery/Takeaway |
|--------|--------|--------------------|
| **Get order** | `getRestaurantOrder`: `table_id` + `branch_id` or `shift_id` | Same endpoint: `order_type` + `shift_id` + `branch_id`, no `table_id`; return open order for that type+shift. |
| **Send to production** | `sendToProduction`: `table_id` required | `sendToProduction`: `table_id` null; `guest_name`, `guest_email`, `guest_phone` required. |
| **Add to cart** | Session key `tableId_shiftId` | Session key `delivery_{shiftId}` / `takeaway_{shiftId}`. |
| **Remove/update cart** | Same key | Same key convention. |

---

## 8. Implementation Order (Recommended)

1. **Migration** – Add `guest_name`, `guest_email`, `guest_phone` to `pos`. Update Pos model.
2. **Backend – getRestaurantOrder** – Support request without `table_id` when `order_type` is delivery/takeaway; return open order for that shift + order_type and session cart for `delivery_{shiftId}` / `takeaway_{shiftId}`.
3. **Backend – sendToProduction** – Allow `table_id` null when order_type is delivery/takeaway; validate and save guest fields; use session key `delivery_{shiftId}` / `takeaway_{shiftId}` for clearing cart.
4. **Backend – Cart (add/remove/update)** – Ensure session cart supports keys `delivery_{shiftId}` and `takeaway_{shiftId}` (e.g. same store, different key). Any route that currently uses `table_id` + `shift_id` for cart key must accept an alternative key for non-dine-in.
5. **Front-end – Order type switch** – On change: if Dine-in → show table grid, require table selection, load order by table. If Delivery/Takeaway → hide table grid, show guest fields, load order by shift + order_type.
6. **Front-end – Guest block** – Add Name/Email/Phone inputs; show only for Delivery/Takeaway; validate before send and pay.
7. **Front-end – Cart key** – When order type is delivery/takeaway, use `delivery_{shiftId}` / `takeaway_{shiftId}` in all cart operations (add product, remove, update qty).
8. **Front-end – Hide Join table / Split bill / Close table** for non-dine-in.
9. **Pay / Invoice** – Ensure guest name/email/phone are shown on receipt and invoice for delivery/takeaway (from `pos` or customer).
10. **Testing** – Dine-in flow unchanged; full flow for Delivery and Takeaway (add items, send to production, pay, print receipt with guest info).

---

## 9. Edge Cases to Handle

- **Switch order type with items in cart:** Decide: clear cart and show confirmation, or keep cart and only switch context (then table vs guest must be consistent; clearing is safer).
- **Delivery/Takeaway without guest info:** Block “Send to production” and “Pay” until Name, Email, Phone are filled; show inline validation.
- **Multiple branches:** Same as now: branch + shift; for delivery/takeaway the “open order” is per branch + shift + order_type.
- **Receipt/print:** For delivery/takeaway, print guest name, phone, email and address (if you add address later) on kitchen slip and customer receipt.

---

## 10. Optional Later Enhancements

- **Address for delivery:** Add `guest_address` (or use Customer address) and show on receipt and for delivery staff.
- **Multiple open delivery/takeaway orders per shift:** List of open orders; select one or “New order”; cart key by `pos_id` after first save.
- **Customer lookup:** For delivery/takeaway, optional “Select customer” that fills name/email/phone from Customer master instead of typing guest fields.

This plan keeps the existing dine-in flow intact and adds a clean, professional path for Delivery and Takeaway with required customer/guest details and no table dependency.
