# Stock behaviour & account system (purchase) – what is done and what is not

## 1. “Expose manager” / Account system on purchase

There is **no feature named “expose manager”** in the codebase.

What **does** exist is the **accounting (Chart of Accounts)** linked to products and purchases:

- Each **product** has:
  - **Income Account** (`sale_chartaccount_id`) – used when you **sell** (e.g. Invoice, POS in some flows).
  - **Expense Account** (`expense_chartaccount_id`) – used when you **purchase / expense** (Bills, Expenses).

- When you create a **Bill** or **Expense** with product lines:
  - The system uses the product’s **Expense Account** for the Chart of Accounts (e.g. `BillAccount`, `ExpenseAccount`).
  - So “account system purchase” = product’s **Expense Account** is used on Bills/Expenses.

- **Purchase** (vendor purchase) in `PurchaseController`:
  - Updates **inventory** (product quantity, warehouse stock, stock report).
  - It does **not** in this codebase post to the Chart of Accounts; it is inventory-only. Chart of Accounts posting for “purchase” side is done via **Bill** and **Expense** using the product’s **Expense Account**.

So: **what is done** = Expense Account on product is used for Bills/Expenses (account system for purchase side). **What is not** = no feature called “expose manager”.

---

## 2. Products that affect stock vs not (restaurant example)

### Your example: Pakistan Chicken (dish) and ingredients

- **Pakistan Chicken** = sellable dish (menu item). It should **not** reduce “dish” stock; only **ingredients** (chicken, potatoes, rice, etc.) should reduce stock when you sell it.
- **Chicken, Potatoes, Rice** = ingredients; they **do** affect stock (you buy them, store them, and they are deducted when you sell dishes that use them).

### How the system does this (what is implemented)

- All POS sellable items are **products** (type `product`). There is **no** separate “does not affect stock” checkbox on the product.
- Stock behaviour is driven by **Recipe / BOM** (Bill of Materials):

| Case | What happens on POS sale |
|------|---------------------------|
| Product **has a Recipe (BOM)** | The **dish’s own quantity is NOT deducted**. Only **ingredients** in the recipe are deducted (chicken, potatoes, rice, etc.), per the quantities defined in the recipe. |
| Product **has no Recipe** | The **product’s own quantity is deducted** (normal stock product). |

So:

- **Pakistan Chicken** (dish):
  - Create it as a **product** (so it appears in POS).
  - Add a **Recipe** for it: ingredients = Chicken, Potatoes, Rice, etc., with quantities per 1 unit of dish.
  - On sale: **only ingredients’ stock is reduced**; the dish itself does not affect its own stock. So effectively the dish “does not affect stock” and the ingredients “affect stock”.

Implementation details:

- **Recipe/BOM**: table `product_recipes` (product_id = dish, ingredient_product_id = stock item, quantity per 1 unit of dish).
- **POS**: When a sale is confirmed, `PosRecipeDeductionService::deductForSale()` is called:
  - If the product has recipe rows → it deducts **only the ingredients** (by recipe quantity × sold quantity).
  - If the product has no recipe → it deducts the **product’s own quantity**.

So: **what is done** = dishes with BOM do not affect their own stock; only ingredients affect stock. **What is not** = there is no explicit “This product does not affect stock” flag; you get the same behaviour by using a Recipe (no recipe = product affects stock; with recipe = only ingredients affect stock).

---

## 3. Short summary (kiya huawa hai / kiya nahi)

| Topic | What is done (kiya huawa hai) | What is not (kiya nahi) |
|-------|------------------------------|---------------------------|
| **Account system – purchase** | Product has **Expense Account**; Bills and Expenses use this for Chart of Accounts. | No feature named “expose manager”. Purchase (vendor) only does inventory, not Chart of Accounts posting. |
| **Dish vs ingredients – stock** | **Recipe/BOM**: if a product (e.g. Pakistan Chicken) has a recipe, on sale **only ingredients** (chicken, potatoes, rice) are deducted; the **dish’s quantity is not deducted**. So dish “does not affect stock,” ingredients “affect stock.” | No checkbox “does not affect stock”; behaviour is only: has recipe → deduct ingredients; no recipe → deduct product. |
| **Where to set BOM** | **Product Recipe** (e.g. Product Recipe menu): select the dish product, add ingredient products and quantity per 1 unit. | - |

---

## 4. How to set up “Pakistan Chicken” (dish, ingredients affect stock)

1. Create **ingredient products** (Chicken, Potatoes, Rice, etc.) as normal products. These will have quantity and will be used in Purchase to add stock.
2. Create **Pakistan Chicken** as a product (for POS). You do **not** need to maintain stock of “Pakistan Chicken” as an item.
3. Go to **Product Recipe** (BOM): select product **Pakistan Chicken**, add lines: Chicken (e.g. 250 g per 1 dish), Potatoes (e.g. 100 g), Rice (e.g. 150 g), etc.
4. When you sell 1 Pakistan Chicken in POS, the system will deduct 250 g Chicken, 100 g Potatoes, 150 g Rice (or whatever you defined), and **will not** deduct any “Pakistan Chicken” quantity.

This matches the restaurant behaviour you described: dish does not affect stock; ingredients do.
