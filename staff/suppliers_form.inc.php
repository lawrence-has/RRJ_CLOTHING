<form method="post" action="suppliers.php">
  <?= csrf_field() ?>
  <input type="hidden" name="save_supplier" value="1">
  <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

  <div class="form-grid">
    <div class="form-group">
      <label>Supplier Name *</label>
      <input name="name" required maxlength="160" value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g. ABC Garments Co.">
    </div>
    <div class="form-group">
      <label>Contact Person</label>
      <input name="contact_name" maxlength="120" value="<?= e($edit['contact_name'] ?? '') ?>" placeholder="e.g. Juan Reyes">
    </div>
    <div class="form-group">
      <label>Email Address</label>
      <input type="email" name="email" maxlength="160" value="<?= e($edit['email'] ?? '') ?>" placeholder="supplier@example.com">
    </div>
    <div class="form-group">
      <label>Phone Number</label>
      <input name="phone" maxlength="60" value="<?= e($edit['phone'] ?? '') ?>" placeholder="e.g. 0917 123 4567">
    </div>
    <div class="form-group">
      <label>Status</label>
      <select name="status">
        <option value="Active"   <?= ($edit['status'] ?? 'Active') === 'Active'   ? 'selected' : '' ?>>Active</option>
        <option value="Inactive" <?= ($edit['status'] ?? '') === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </div>
  </div>

  <div class="form-group">
    <label>Address</label>
    <textarea name="address" rows="2" placeholder="Supplier's business address…"><?= e($edit['address'] ?? '') ?></textarea>
  </div>

  <div class="form-group">
    <label>Notes</label>
    <textarea name="notes" rows="2" placeholder="Lead times, payment terms, special instructions…"><?= e($edit['notes'] ?? '') ?></textarea>
  </div>

  <!-- Product linkage -->
  <div class="form-group">
    <label>Products Supplied</label>
    <div class="form-hint" style="margin-bottom:10px">Check all products this supplier provides. Optionally enter the unit cost (your purchase price).</div>
    <div style="border:1px solid var(--line);border-radius:8px;overflow:hidden">
      <table style="margin:0">
        <tr><th style="width:40px"></th><th>Product</th><th>Category</th><th style="width:160px">Unit Cost (₱)</th></tr>
        <?php foreach ($allProducts as $p):
            $isLinked = isset($editLinkedProducts[$p['id']]);
            $cost     = $isLinked ? $editLinkedProducts[$p['id']] : '';
        ?>
          <tr>
            <td style="text-align:center">
              <input type="checkbox" name="products[]" value="<?= (int)$p['id'] ?>"
                     id="prod_<?= (int)$p['id'] ?>"
                     <?= $isLinked ? 'checked' : '' ?>>
            </td>
            <td><label for="prod_<?= (int)$p['id'] ?>" style="cursor:pointer;font-weight:600"><?= e($p['name']) ?></label></td>
            <td class="muted"><?= e($p['category']) ?></td>
            <td>
              <input type="number" name="unit_costs[<?= (int)$p['id'] ?>]"
                     step="0.01" min="0" placeholder="0.00"
                     value="<?= $cost !== '' ? e((string)$cost) : '' ?>"
                     style="width:130px">
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$allProducts): ?>
          <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:16px">No active products found</td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <div style="display:flex;gap:10px;margin-top:4px">
    <button class="btn"><?= isset($edit) && $edit ? 'Save Changes' : 'Add Supplier' ?></button>
    <?php if (isset($edit) && $edit): ?>
      <a href="suppliers.php" class="btn secondary">Cancel</a>
    <?php endif; ?>
  </div>
</form>
