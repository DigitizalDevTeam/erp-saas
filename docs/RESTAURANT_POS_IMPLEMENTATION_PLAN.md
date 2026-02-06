# Restaurant POS – Implementation Plan & End-to-End Flow

This document gives a **step-by-step implementation plan** and the **final user flow** once all requirements are done. It is aligned with your existing ERP-SaaS codebase (Laravel, existing POS, areas, tables, shifts).

---

## Part A: What Already Exists (Quick Audit)

| Feature | Status | Notes |
|--------|--------|--------|
| Branch `pos_type` (retail / restaurant) | ✅ Done | `Branch::POS_TYPE_RESTAURANT` |
| Pos Areas (zones) | ✅ Done | `pos_areas` table, `PosArea` model |
| Pos Tables (per area) | ✅ Done | `pos_tables`, capacity, status (available/occupied/reserved) |
| Pos Shifts | ✅ Done | Open/close, opening/closing balance, `PosShiftController` |
| Table assignment to waiter (per shift) | ✅ Done | `pos_table_assignments`, assign/unassign |
| POS linked to table & shift | ✅ Done | `pos.table_id`, `pos.shift_id`, `order_type` |
| Restaurant layout API | ✅ Done | `getRestaurantLayout`, `getActiveShift`, `getBranchOpenShifts` |
| Role-based access (Cashier / Waiter / Manager) | ❌ Not done | Only "manage pos" permission |
| Payment types (Cash / Card / Credit) | ❌ Partial | `payment_method` exists; need cash/card/credit + terms for credit |
| Split bill | ❌ Not done | Single payment per POS today |
| Kitchen / Bar printer | ❌ Not done | Only receipt print exists |
| Cash-up & end-of-day reports | ❌ Partial | Shift close exists; need proper cash-up calc & EOD report |
| Separate POS Retail vs Restaurant UI | ❌ Partial | Same `pos.index` with mode; need clear separation |
| Offline mode + sync | ❌ Not done | To be designed |
| Reports (sales, category, shift, EOD) | ❌ Partial | Need dedicated restaurant reports |

---

## Part B: Implementation Steps (Order of Work)

### Phase 1 – Roles & Access (Login & Role Access)

1. **Define POS roles and permissions**
   - Create roles (or reuse): **Cashier**, **Waiter**, **Manager** (or map to existing roles + new permissions).
   - Define permissions, e.g.:
     - `pos restaurant full` → Cashier: tables, payments, reports, all actions.
     - `pos restaurant waiter` → Waiter: only assigned tables, take orders, send to kitchen/bar (no cash-up, no full reports).
     - `pos restaurant manager` → Manager: view all sales, reports, shifts, EOD; no need to take orders.
   - Assign permissions to roles in seeders and restrict routes/middleware by role.

2. **Restrict by role in controllers**
   - In `PosController`, `PosTableController`, `PosShiftController`, etc.:
     - Cashier: full access to restaurant POS, cash-up, close table.
     - Waiter: only access tables assigned to their current shift; can create/update orders, send to print; cannot close shift or do cash-up.
     - Manager: read-only / reports / shift list / EOD; optional: allow override (e.g. close table) via permission.

3. **Optional: POS-specific login / landing**
   - After login, redirect Cashier/Waiter to Restaurant POS dashboard (table layout); Manager to reports/dashboard.
   - Or single dashboard with menu items visible by role.

**Deliverable:** Login → user has role → sees only allowed menus and actions (Cashier / Waiter / Manager).

---

### Phase 2 – Table Management (Enhance Existing) ✅ Implemented

4. **Restaurant layout screen (front-end)** ✅
   - Dedicated route/view for Restaurant POS (e.g. `/pos/restaurant` or `/pos/restaurant/{branch_id}`).
   - Load areas + tables via existing `getRestaurantLayout`; show zones (Bar, Garden, Room1, etc.) and tables; show status (available / occupied / reserved).
   - Allow “open table” (set number of customers, optional) and mark table as occupied; create or reuse order context for that table. **Done:** `current_guests` on `pos_tables`, Open Table modal with guest count, PATCH `pos-table/{id}/status` with `guests_count`.

5. **Assign tables to waiters (already in place, wire UI)** ✅
   - Use existing `pos-shift.assign-table`: when a shift is open, manager/cashier assigns tables to that waiter.
   - On “Restaurant layout”, waiter sees only **their** assigned tables (filter by `PosTableAssignment` for current user’s open shift). **Done:** `getRestaurantLayout` filters tables by assigned shift when user has `pos restaurant waiter` only.
   - Cashier sees all tables.

6. **Transfer tables between waiters (shift change)** ✅
   - New action: “Transfer tables” from Waiter A to Waiter B (e.g. when Waiter B starts shift).
   - **Done:** `POST pos-shift/transfer-tables` (from_shift_id, to_shift_id, table_ids[]); Transfer Tables modal on shift show page (Cashier/Manager only).

**Deliverable:** Layout by zones → open table (guests count) → assign/transfer tables to waiters; waiter sees only assigned tables.

---

### Phase 3 – Order Management (Table → Order → Kitchen/Bar) ✅ Implemented

7. **Open order screen from table** ✅
   - From layout: click occupied table (or “New order” for that table) → open order screen with `table_id`, `shift_id`, and current waiter/cashier.
   - Load products by category (existing product/category structure); add to cart; set quantity; optional notes. **Already in place;** table selection and shift selection wired.

8. **Send order to Kitchen / Bar** ✅
   - Product-level flag: **Done** `product_services.print_zone` (kitchen | bar | null). Migration added.
   - On “Print kitchen slip”: **Done** cart items split by `data-print-zone`; kitchen items → Kitchen Slip print window; bar items → Bar Slip print window (separate window). Front-end only (browser print).
   - Optional: “Hold” order / persist on send can be added later (Phase 7 for printer config).

9. **Split bill (optional but in scope)** ✅
   - UI: “Split bill” modal already on order screen (split into N parts, amount per person). **Done.**
   - Data: **Done** `Pos` has `posPayments()` hasMany; multiple `pos_payments` per `pos_id` supported for split. Payment form can be extended to accept multiple payments (e.g. pay X per part).

**Deliverable:** Select table → add items → send to kitchen/bar → optional split bill; order stored with table_id & shift_id.

---

### Phase 4 – Payment & Cash Handling

10. **Payment types**
    - Extend payment method to: **Cash**, **Card**, **Credit** (and keep existing if any).
    - In `pos_payments` (or equivalent): store `payment_method` (cash/card/credit) and `amount` per payment; for **Credit**, store `credit_terms` (e.g. 15/30 days) and link to customer (invoice later from ERP).
    - UI: When closing table, select payment type(s) and amounts; if Credit, select customer and terms.

11. **Close table**
    - “Close table” = all orders for that table are paid (or explicitly closed as unpaid/cancelled). Mark table status back to `available`; optionally keep a “table session” for history.
    - Only Cashier (or Waiter if you allow) can close table; ensure role check.

12. **Cash-up (shift end)**
    - Done by Cashier at shift end.
    - From `pos_payments` for that shift: sum by payment type (cash in, card in, etc.); opening balance (cash float); closing balance (counted cash); difference = (opening + cash sales) − closing.
    - Store in `pos_shifts`: e.g. `cash_total`, `card_total`, `credit_total`, `closing_balance`, `difference`; or in a dedicated `pos_cash_up` table.
    - UI: “Cash-up” screen: show expected vs counted, record difference and notes.

13. **End-of-day (EOD)**
    - Manager reviews: total sales (all shifts closed for the day), summary by product/category, payment mix.
    - Can be a report (see Phase 6) with date = today and “EOD” summary; optional “Close day” button that locks the day (e.g. flag or EOD record).

**Deliverable:** Pay with Cash/Card/Credit → close table → cash-up at shift end → EOD report for manager.

---

### Phase 5 – Shift Management (Refinements)

14. **Shift change and table transfer**
    - When Waiter B starts shift: open new shift for B; assign tables to B (from “pool” or transfer from A). Implement “Transfer tables” (Step 6) so that tables from A’s shift are unassigned and assigned to B.
    - When Cashier closes shift: run cash-up (Step 12), then close shift; all assignments for that shift are closed (existing `unassigned_at`).

15. **Multiple waiters in sequence**
    - Already supported: multiple `pos_shifts` for same branch (different users); each shift has its own table assignments and orders. No change needed except UI to select “current shift” when taking order (already have `getBranchOpenShifts`).

**Deliverable:** Shift open/close and table transfer between waiters; multiple waiters per day supported.

---

### Phase 6 – POS Layout: Retail vs Restaurant

16. **Separate entry points**
    - **POS Retail:** Existing `pos.index` (or `/pos/retail`) — only stock + sales; no tables, no zones; branch filter = retail branches only (already partially there).
    - **POS Restaurant:** New route e.g. `/pos/restaurant` or `/pos/restaurant/{branch_id}` — shows branch selector (restaurant only) → table layout → order by table.
    - Menu/sidebar: two items, “POS Retail” and “POS Restaurant”; visibility by role and branch type.

17. **Optional: Price per zone**
    - If needed later: add `price_level_id` or multiplier per area; when adding product to order, apply zone price. Can be Phase 7.

**Deliverable:** Clear separation: Retail POS vs Restaurant POS; correct branches and menus per type.

---

### Phase 7 – Kitchen/Bar Printers (Detail)

18. **Printer configuration**
    - Table `pos_printers` (branch_id, name, type=kitchen|bar, connection: IP/port or “browser”), sort_order.
    - Settings UI per branch: add/edit/delete kitchen and bar printers.

19. **Print flow**
    - On “Send to kitchen” / “Send to bar”: build slip (items, table, notes); send to corresponding printer (network ESC/POS or open print dialog for browser). Prefer one template for kitchen and one for bar.

**Deliverable:** Kitchen and bar slips print correctly per order.

---

### Phase 8 – Offline Use (Optional, High Level)

20. **Strategy**
    - Use a PWA or local-first approach: orders stored in IndexedDB/localStorage when offline; sync to server when online.
    - Backend: idempotent “submit order” API (e.g. client sends `client_order_id` to avoid duplicates); endpoint to push pending orders and pull pending sync status.
    - Define “max offline hours” and “max MB” (e.g. 8 hours, 50 MB) and document for client; dev validates with realistic data.

**Deliverable:** Offline order capture and sync when internet restores; documented limits.

---

### Phase 9 – Reports & Analytics

21. **Reports to add**
    - **Sales report:** By date range, branch; total sales; filter by restaurant vs retail.
    - **Category-wise summary:** Sales by product category (and by zone if you have zone prices).
    - **Shift report:** Per shift: sales count, total, payment mix, opening/closing balance, difference.
    - **End-of-day balance & differences:** EOD summary (all shifts for the day, cash-up differences).
    - **POS & Restaurant sales in ERP:** Ensure POS sales (and invoices created from POS) are already in accounting; add any missing links (e.g. journal entry from daily POS summary).

**Deliverable:** Manager and Cashier can run these reports; data consistent with ERP.

---

## Part C: End-to-End Flow (After Implementation)

### 1. Login & role

- User logs in → role = Cashier / Waiter / Manager.
- **Cashier:** Sees “POS Restaurant”, “Tables”, “Shifts”, “Cash-up”, “Reports”.
- **Waiter:** Sees “POS Restaurant” and only “My tables” (assigned for current shift).
- **Manager:** Sees “Reports”, “Shifts”, “EOD”; can view all.

### 2. Table management

- Restaurant layout shows **zones** (Bar, Garden, Room1) and **tables**.
- Cashier/Manager assigns tables to waiters when opening a shift.
- Waiter sees only assigned tables; can “open” a table (enter guest count) → table becomes occupied.
- On shift change, tables can be transferred to the next waiter.

### 3. Order flow

- Cashier/Waiter selects table → order screen opens (table_id + shift_id).
- Select categories → add products → set quantity → optional notes.
- “Send order” → kitchen printer gets food items, bar printer gets drink items.
- Optional: add more items later (same order); optional: split bill (multiple payments or split by items).

### 4. Payment & close

- When table is ready to pay: select payment type(s) — Cash, Card, Credit (with terms if credit).
- Complete payment(s); optionally split across multiple payments.
- “Close table” → table status back to available; order(s) marked paid/closed.

### 5. Shift & cash-up

- At shift end, Cashier runs **cash-up**: enters counted cash; system shows expected (opening + cash sales) vs counted; difference and notes saved.
- Shift is closed; tables unassigned from that shift.
- **End-of-day:** Manager reviews total sales, category summary, shift report, and EOD balance/differences.

### 6. Retail vs Restaurant

- **POS Retail:** Separate entry; no tables; select branch/warehouse → add products → pay → done.
- **POS Restaurant:** Branch → layout → tables → orders → kitchen/bar print → pay → close table.

### 7. Offline (if implemented)

- When offline, orders are stored locally; when online, they sync to server; ERP receives them after sync.

### 8. Reports & ERP

- Sales, category, shift, and EOD reports available; POS and Restaurant sales integrated with ERP (invoices, stock, accounting).

---

## Summary Table: Requirement → Implementation Step

| # | Client requirement | Step(s) |
|---|---------------------|--------|
| 1 | Role-based login (Cashier, Waiter, Manager) | Phase 1 (steps 1–3) |
| 2 | Table management + zones | Phase 2 (steps 4–6); areas/tables exist, wire UI + transfer |
| 3 | Order taking + kitchen/bar printing | Phase 3 (steps 7–9), Phase 7 (steps 18–19) |
| 4 | Payment types + split bill | Phase 4 (steps 10–11) |
| 5 | Shift management + table transfer | Phase 5 (steps 14–15), Phase 2 (step 6) |
| 6 | Cash-up & end-of-day reports | Phase 4 (steps 12–13), Phase 9 (step 21) |
| 7 | Separate POS Retail vs Restaurant | Phase 6 (steps 16–17) |
| 8 | Optional offline + sync | Phase 8 (step 20) |
| 9 | Integration with ERP | Phase 9 (step 21); ensure existing POS→Invoice and stock links |

---

## Suggested development order

1. **Phase 1** (roles) – so all subsequent features can be gated correctly.  
2. **Phase 6** (separate Retail vs Restaurant UI) – clear entry and layout.  
3. **Phase 2** (table management UI + transfer) – full table flow.  
4. **Phase 3** (order screen + send to kitchen/bar) + **Phase 7** (printers) – core ordering.  
5. **Phase 4** (payment types, close table, cash-up, EOD) – money flow.  
6. **Phase 5** (shift refinements) – already mostly there.  
7. **Phase 9** (reports) – manager and EOD.  
8. **Phase 8** (offline) – last, after core flows are stable.

This gives the client a clear **implementation plan in steps** and a **concrete end-to-end flow** once the Restaurant POS requirements are done.
