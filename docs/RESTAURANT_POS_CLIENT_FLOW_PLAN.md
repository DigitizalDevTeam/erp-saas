# Restaurant POS – Client Flow Implementation Plan (English)

This plan maps the **client’s requested operational flow** to **what already exists** vs **what needs to be built**. It answers: what changes in the current flow, and what new modules to add.

---

## 1. Current State vs Client Requirements

### 1.1 User roles (system permissions)

| Client requirement | Current state | Action |
|--------------------|----------------|--------|
| **Waiter** – open table, add orders, send to production, view status, request bill, split, transfer, **cannot see other waiter’s tables** | Roles: Cashier, Waiter, Manager, Bar, Kitchen exist (`PosRestaurantRoleService`). Waiter sees only assigned tables. | **Refine**: Add explicit “Kitchen/Bar staff” **production-only** role (receive orders, mark in prep/ready only). Ensure waiter **cannot** see/edit tables not assigned to them (already filtered in layout). |
| **Cashier** – open register, see all tables, process payments, print receipts, **cash drop**, close register, shift report | Cashier has full POS. **No cash drop**. Shift close with cash-up exists. | **Add**: Cash drop (withdrawal during shift) module. **Enhance**: Shift report view for cashier. |
| **Manager/Supervisor** – full reports, discounts, **cancel with password**, reopen tables, manage users/stock, authorize refunds, **audit logs** | Manager permission exists. No cancel-with-password, no discount approval rules, no dedicated audit log for POS. | **Add**: Manager password for cancel; discount approval above X%; table reopen authorization; refund authorization; **audit log** for POS actions. |
| **Kitchen/Bar (production)** – receive orders, mark “In preparation”, “Ready”, view prep time | Bar/Kitchen roles exist but use same waiter permission. No order **line status** (pending → in prep → ready). | **Add**: **Order line status** (pending / in_preparation / ready / served / cancelled). **New**: Production screen (kitchen/bar) to receive and update status; optional prep time tracking. |

**Summary – role changes**

- **Existing**: Waiter, Cashier, Manager, Bar, Kitchen roles and waiter table filtering.
- **New/change**: Production-only role (or same role with production screen); cash drop; manager password for cancel/discount/reopen/refund; audit log; order line status + production UI.

---

## 2. Daily routine – cash register & table opening

| Client flow | Current state | Action |
|-------------|----------------|--------|
| **Cash register opening** – cashier logs in, enter opening float, system records time/operator/amount, status OPEN | Shift open with `opening_balance` exists (`PosShift`). | **Minor**: Ensure UI clearly shows “Cash register open” and forces opening float. Optional: rename/label as “Cash register” in restaurant flow. |
| **Table opening** – select table, “Open Table”, enter guests + optional customer name, status OPEN | Open table with `current_guests` exists. Optional customer name not always in UI. | **Optional**: Add optional “customer name” to open-table form and store (e.g. on `pos_tables` or first `pos` for that table). |

**Summary**

- Mostly **existing**. Small enhancements: opening float UX, optional customer name on open table.

---

## 3. Order creation

| Client flow | Current state | Action |
|-------------|----------------|--------|
| Waiter adds items by category (Food, Drinks, Desserts), quantity, notes, then **Send to production** | Categories and products exist. Notes possible. “Send to kitchen/bar” splits by `print_zone`. | **Change**: Treat “Send to production” as **persisting order lines with status = pending** and triggering kitchen/bar print. Today order is often finalised on payment; need **order-before-payment** flow with line status. |

**Summary**

- **Flow change**: Support “order now, pay later”: save order (and lines) when sent to production; deduct stock only on **payment/confirm** (or per your policy). Add **order line status** (see Section 5).

---

## 4. Kitchen / bar printing logic

| Client flow | Current state | Action |
|-------------|----------------|--------|
| Product has **Production destination**: Kitchen / Bar / Both. On “Send order”, split by destination and send to correct printer(s). | `product_services.print_zone` = kitchen \| bar \| null. Front-end splits and opens print dialogs (kitchen slip / bar slip). | **Enhance**: Add “both” (e.g. combo) so one product prints to kitchen and bar. **Optional**: `pos_printers` table exists – wire to real printer config (Phase 7 in existing plan). |

**Summary**

- **Existing** with `print_zone`. **Change**: Support “both” and ensure “Send to production” triggers this split and marks order as “Sent”.

---

## 5. Order status flow (new module)

| Client flow | Current state | Action |
|-------------|----------------|--------|
| States: **Pending → In preparation → Ready → Served → Cancelled**. Kitchen/Bar can set “In preparation” / “Ready”. Waiter gets notification when ready. | No line-level or order-level status for production. | **New module**: **Order line status**. Add `status` (and optional `sent_at`, `ready_at`) to order lines (e.g. `pos_products` or new `pos_order_lines`). Production screen: list orders by kitchen/bar, set status. Waiter UI: show status and optional “ready” notification (poll or push). |

**Summary**

- **New**: Order/line status lifecycle and **production screen** for Kitchen/Bar. Optional: preparation time (e.g. `ready_at - sent_at`).

---

## 6. Stock movement (inventory control)

| Client flow | Current state | Action |
|-------------|----------------|--------|
| On **confirmed sale**, deduct stock by **recipe (BOM)** – e.g. 1× Grilled Chicken → −250g chicken, −10g salt, −5g spices. Units: kg, g, L, unit. **Low stock alert**. | POS deducts **product quantity** only (no recipe). Stock reports exist. | **New module**: **Recipe/BOM**. Tables: e.g. `product_recipe` (product_id, ingredient_product_id, quantity, unit). On payment/confirm: for each sold product, deduct ingredients per recipe. **Enhance**: Unit management; low-stock alerts (report or dashboard). |

**Summary**

- **New**: Recipe/BOM module and **ingredient-based deduction** on confirm sale. **Existing**: Product-level deduction can remain for products without recipe; recipe overrides when present.

---

## 7. Bill request & payment process

| Client flow | Current state | Action |
|-------------|----------------|--------|
| **Close table** → show total, split bill, discount (manager auth), select payment method. | Close table and split bill (multiple `pos_payments`) exist. Discount and manager auth not formalised. | **Add**: “Close table” as explicit action (show bill, then pay). **Add**: Discount approval (e.g. above X% requires manager password). **Enhance**: Payment methods. |
| **Payment methods**: Cash, Card, Mobile Money (M-Pesa), Bank Transfer, Mixed. Record method, print receipt, update register balance, close table. | `PosPayment`: cash, card, credit, mixed. No M-Pesa or Bank Transfer as explicit methods. | **Add**: Payment method options (e.g. `mpesa`, `bank_transfer`) in DB and UI. Receipt print and table close already exist. |

**Summary**

- **Changes**: Formal “Close table” → bill → pay flow; discount approval rule; add M-Pesa + Bank Transfer; ensure receipt and register balance updated (already largely there).

---

## 8. Cash drop (new module)

| Client flow | Current state | Action |
|-------------|----------------|--------|
| During shift: cashier can **withdraw** money from register (amount + reason), recorded with operator. | Not present. | **New module**: **Cash drop**. Table e.g. `pos_cash_drops` (shift_id, amount, reason, user_id, created_at). UI: “Cash drop” from shift/register screen; list drops in shift report. |

**Summary**

- **New**: Cash drop table + UI; link to shift and show in shift report.

---

## 9. Cash register closing

| Client flow | Current state | Action |
|-------------|----------------|--------|
| “Close register” → show total sales, expected cash, cashier enters counted cash, system shows difference (over/short), closing report, status CLOSED. | Shift close with opening/closing balance, cash_total, card_total, credit_total, difference, notes. | **Enhance**: In “expected cash”, **subtract cash drops**. Ensure closing report shows sales, expected cash, counted cash, difference. |

**Summary**

- **Existing** logic. **Change**: Include cash drops in expected-cash calculation and in report.

---

## 10. Reports & security

| Client requirement | Current state | Action |
|--------------------|----------------|--------|
| **Reports**: Daily sales, by product, by waiter, cancellations, discounts, best-selling, gross profit, inventory valuation, low stock, cash discrepancies. | Some POS/sales reports exist. No dedicated restaurant set. | **New/Enhance**: **Restaurant reports** – daily sales, sales by product/waiter, cancellations, discounts, best-selling, gross profit (if cost available), inventory/low stock, cash discrepancies (from shift difference). |
| **Security**: Cancel only with manager password; discount above X% with approval; table reopen with authorization; **audit trail**; RBAC. | RBAC via roles. No POS audit log, no cancel/discount/reopen auth. | **New**: **POS audit log** (who did what, when – open/close table, send order, cancel, discount, cash drop, close shift, etc.). **Add**: Manager password check for cancel, discount limit + approval, table reopen. |

**Summary**

- **New**: Audit log for POS actions; manager password for cancel; discount approval rule; table reopen authorization.
- **New/Enhance**: Set of manager reports as above.

---

## 11. Technical modules – summary

### Already in place (use as-is or small tweaks)

- User/role management (Waiter, Cashier, Manager, Bar, Kitchen)
- Floor/table management (areas, tables, open table, guests, assign/transfer)
- Product management + categories + `print_zone` (kitchen/bar)
- Cash register (shift open/close, opening float, cash-up totals)
- Payment recording (multi-payment/split); receipt print
- Basic stock deduction (product quantity) and warehouse

### New modules to add

1. **Order line status & production flow**
   - Order/line status: pending → in_preparation → ready → served | cancelled
   - Production screen (Kitchen/Bar): receive orders, set status, optional prep time
   - Waiter: view status, “ready” notification

2. **Recipe/BOM module**
   - Recipe per product (ingredients + quantities + units)
   - On confirmed sale: ingredient-based deduction
   - Unit management (kg, g, L, unit); optional low-stock alert

3. **Cash drop**
   - Table + API + UI: withdraw amount + reason during shift
   - Show in shift report; factor into expected cash at close

4. **Payment methods extension**
   - M-Pesa, Bank Transfer (and any others) in DB and payment UI

5. **Security & control**
   - Manager password for: cancel order, discount above X%, table reopen, refund
   - POS audit log (all important actions with user, time, details)

6. **Restaurant reports**
   - Daily sales, by product, by waiter, cancellations, discounts, best-selling, gross profit, inventory/low stock, cash discrepancies

### Optional / later

- **Kitchen/Bar printers**: `pos_printers` exists; wire to real printers (browser vs network)
- **Offline mode**: design and implement per existing Phase 8 plan

---

## 12. PHASES (Implementation Order)

### Phase 1 – Order line status & production screen
- Add order line status to `pos_products`: pending → in_preparation → ready → served | cancelled.
- Add columns: `status`, `sent_at`, `ready_at`, `print_zone` on `pos_products`.
- **Production screen**: Kitchen/Bar view listing orders/lines by zone (kitchen or bar), update status to “In preparation” / “Ready”.
- **Send to production**: Waiter action saves order + lines (create/update Pos, create PosProduct with status pending), triggers kitchen/bar print.
- Waiter order view: show line status; optional “ready” notification (e.g. polling).
- **Deliverable**: Full order status lifecycle and production screen; “Send to production” persists and prints.

### Phase 2 – Cash drop
- New table `pos_cash_drops` (shift_id, amount, reason, user_id).
- UI: Cash drop form from shift/register; list drops in shift report.
- Shift close: expected cash = opening + cash sales − cash drops.
- **Deliverable**: Cashier can record withdrawals during shift; closing report includes drops.

### Phase 3 – Payment methods & close table
- Add payment methods: M-Pesa, Bank Transfer in `pos_payments` and payment UI.
- Formal “Close table” flow: show bill → split/discount → pay → close table.
- **Deliverable**: All requested payment methods; clear close-table UX.

### Phase 4 – Security & audit
- Manager password for: cancel order, discount above X%, table reopen, refund.
- POS audit log: table and API for who did what, when (open/close table, send order, cancel, discount, cash drop, close shift).
- **Deliverable**: Approval rules and full audit trail for POS actions.

### Phase 5 – Recipe/BOM & stock
- Recipe module: product → ingredients (quantity, unit). On confirmed sale, deduct by recipe.
- Unit management; low-stock alert (report or dashboard).
- **Deliverable**: Ingredient-based deduction; low-stock visibility.

### Phase 6 – Discount approval rule
- Setting: discount above X% requires manager approval (password or permission).
- **Deliverable**: Discounts above threshold need manager auth.

### Phase 7 – Restaurant reports
- Manager dashboard: daily sales, by product, by waiter, cancellations, discounts, best-selling, gross profit, inventory/low stock, cash discrepancies.
- **Deliverable**: Full set of restaurant reports for managers.

### Phase 8 – Polish & optional
- “Close table” UX refinements; optional customer name on open table; `print_zone` “both” (kitchen + bar); prep time display; expected cash including cash drops in closing report.
- Optional: Kitchen/Bar printer config; offline sync.
- **Deliverable**: UX polish and optional features.

---

## 13. Flow summary (after implementation)

1. **Open cash register** (cashier, opening float)  
2. **Open table** (waiter/cashier, guests ± customer name)  
3. **Add order** → **Send to production** → print kitchen/bar (by `print_zone`), lines status = pending  
4. **Kitchen/Bar**: mark lines in preparation → ready  
5. **Stock**: on **payment/confirm** → deduct by recipe (or product quantity); low stock alerts  
6. **Waiter**: mark served when delivered  
7. **Request bill** → **Close table** → split bill, discount (with approval if > X%)  
8. **Payment** (Cash/Card/M-Pesa/Bank Transfer/Mixed) → receipt, update register  
9. **Cash drop** (optional, during shift)  
10. **Close register** (count cash, difference, closing report)  
11. **Manager**: reports, audit log, authorize cancel/discount/reopen/refund  

This plan keeps existing behaviour where it matches the client flow and clearly lists **new modules** (order status, production screen, recipe/BOM, cash drop, audit, reports) and **flow changes** (order-before-pay, discount/cancel/reopen auth, payment methods).
