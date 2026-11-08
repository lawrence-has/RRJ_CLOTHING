/* ── RRJ Clothing · app.js ─────────────────────────────── */

document.addEventListener('DOMContentLoaded', () => {

  /* ── Confirm-on-click ─────────────────────────────────── */
  document.addEventListener('click', e => {
    const el = e.target.closest('[data-confirm]');
    if (el && !confirm(el.dataset.confirm)) e.preventDefault();
  });

  /* ── Flash auto-dismiss (4 s) ─────────────────────────── */
  document.querySelectorAll('.flash').forEach(el => {
    setTimeout(() => {
      el.style.transition = 'opacity .5s, max-height .5s';
      el.style.opacity    = '0';
      el.style.maxHeight  = '0';
      el.style.padding    = '0';
      el.style.margin     = '0';
      setTimeout(() => el.remove(), 520);
    }, 4000);
  });

  /* ── Live table filter (data-filter="#selector") ─────── */
  document.addEventListener('input', e => {
    const inp = e.target;
    if (!inp.matches('[data-filter]')) return;
    const q   = inp.value.toLowerCase();
    const sel = inp.dataset.filter;
    document.querySelectorAll(sel + ' tr:not(.no-filter)').forEach((row, i) => {
      if (i === 0) return; // skip header row
      row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });

  /* ── Customer mobile nav toggle ───────────────────────── */
  const hamburger  = document.getElementById('hamburger');
  const mobileNav  = document.getElementById('mobileNav');
  if (hamburger && mobileNav) {
    hamburger.addEventListener('click', () => {
      mobileNav.classList.toggle('open');
      hamburger.setAttribute('aria-expanded', mobileNav.classList.contains('open'));
    });
    document.addEventListener('click', e => {
      if (!hamburger.contains(e.target) && !mobileNav.contains(e.target)) {
        mobileNav.classList.remove('open');
      }
    });
  }

  /* ── Staff sidebar mobile toggle ──────────────────────── */
  const sidebarToggle  = document.getElementById('sidebarToggle');
  const staffSidebar   = document.getElementById('staffSidebar');
  const sidebarOverlay = document.getElementById('sidebarOverlay');
  if (sidebarToggle && staffSidebar) {
    const openSidebar  = () => { staffSidebar.classList.add('open'); sidebarOverlay?.classList.add('open'); };
    const closeSidebar = () => { staffSidebar.classList.remove('open'); sidebarOverlay?.classList.remove('open'); };
    sidebarToggle.addEventListener('click', () => staffSidebar.classList.contains('open') ? closeSidebar() : openSidebar());
    sidebarOverlay?.addEventListener('click', closeSidebar);
  }

  /* ── Product detail: size / color selector ────────────── */
  document.querySelectorAll('.option-group').forEach(group => {
    group.querySelectorAll('.option').forEach(opt => {
      opt.addEventListener('click', () => {
        group.querySelectorAll('.option').forEach(o => o.classList.remove('active'));
        opt.classList.add('active');
        const field = group.dataset.field; // "size" or "color"
        if (field) {
          document.querySelectorAll(`input[name="${field}"]`).forEach(inp => inp.value = opt.dataset.value);
        }
      });
    });
  });

  /* ── Product detail: quantity stepper ────────────────── */
  const qtyDisplay = document.getElementById('qtyDisplay');
  const qtyInput   = document.getElementById('qtyInput');
  const qtyMax     = parseInt(qtyDisplay?.dataset.max || '99', 10);
  document.getElementById('qtyMinus')?.addEventListener('click', () => {
    let v = parseInt(qtyInput.value, 10);
    if (v > 1) { qtyInput.value = v - 1; qtyDisplay.textContent = v - 1; }
  });
  document.getElementById('qtyPlus')?.addEventListener('click', () => {
    let v = parseInt(qtyInput.value, 10);
    if (v < qtyMax) { qtyInput.value = v + 1; qtyDisplay.textContent = v + 1; }
  });

  /* ── Cart: quantity update (inline +/- buttons) ───────── */
  document.querySelectorAll('.cart-qty-minus, .cart-qty-plus').forEach(btn => {
    btn.addEventListener('click', () => {
      const row  = btn.closest('[data-cart-key]');
      const key  = row?.dataset.cartKey;
      const disp = row?.querySelector('.cart-qty-val');
      if (!key || !disp) return;
      let   qty  = parseInt(disp.textContent, 10);
      const max  = parseInt(disp.dataset.max || '99', 10);
      if (btn.classList.contains('cart-qty-minus')) qty = Math.max(1, qty - 1);
      else qty = Math.min(max, qty + 1);
      disp.textContent = qty;
      // Submit update via fetch
      fetch(`index.php?page=update_cart&key=${encodeURIComponent(key)}&qty=${qty}`, {method:'GET'})
        .then(r => r.json())
        .then(data => {
          if (data.item_total !== undefined) {
            const totalEl = row.querySelector('.cart-item-total');
            if (totalEl) totalEl.textContent = data.item_total;
          }
          if (data.cart_total !== undefined) {
            document.querySelectorAll('.cart-subtotal').forEach(el => el.textContent = data.cart_total);
            document.querySelectorAll('.cart-grand-total').forEach(el => el.textContent = data.cart_grand_total || data.cart_total);
          }
          if (data.cart_count !== undefined) {
            document.querySelectorAll('.cart-badge').forEach(el => el.textContent = data.cart_count);
          }
        })
        .catch(() => {});
    });
  });

  /* ── Checkout: dynamic shipping price update ──────────── */
  document.querySelectorAll('input[name="shipping_method"]').forEach(radio => {
    radio.addEventListener('change', () => {
      const price   = parseFloat(radio.dataset.price || '60');
      const label   = radio.dataset.label || radio.value;
      document.querySelectorAll('.shipping-cost-display').forEach(el => el.textContent = '₱' + price.toFixed(2));
      const sub     = parseFloat(document.getElementById('subtotalVal')?.dataset.value || '0');
      const grandEl = document.getElementById('grandTotalVal');
      if (grandEl) grandEl.textContent = '₱' + (sub + price).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    });
  });

  /* ── Modal helpers ────────────────────────────────────── */
  window.openModal = (id) => {
    document.getElementById(id)?.classList.add('open');
    document.body.style.overflow = 'hidden';
  };
  window.closeModal = (id) => {
    document.getElementById(id)?.classList.remove('open');
    document.body.style.overflow = '';
  };
  // Close on backdrop click
  document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', e => {
      if (e.target === backdrop) closeModal(backdrop.id);
    });
  });
  // Close on Escape
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop.open').forEach(m => closeModal(m.id));
  });

  /* ── Tabs ─────────────────────────────────────────────── */
  document.querySelectorAll('.tabs').forEach(tabBar => {
    tabBar.querySelectorAll('.tab-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const target  = btn.dataset.tab;
        const parent  = btn.closest('.tab-wrapper') || document;
        tabBar.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        parent.querySelectorAll('.tab-content').forEach(c => {
          c.classList.toggle('active', c.dataset.tab === target);
        });
      });
    });
  });

  /* ── Image file preview ───────────────────────────────── */
  document.querySelectorAll('input[type="file"][data-preview]').forEach(inp => {
    inp.addEventListener('change', () => {
      const prev = document.getElementById(inp.dataset.preview);
      if (!prev || !inp.files[0]) return;
      const reader = new FileReader();
      reader.onload = e => {
        prev.innerHTML = `<img src="${e.target.result}" alt="preview">`;
      };
      reader.readAsDataURL(inp.files[0]);
    });
  });

  /* ── Staff: order status badge color helper ───────────── */
  document.querySelectorAll('[data-status-badge]').forEach(el => {
    const map = {
      'order placed' : 'badge-pending',
      'processing'   : 'badge-processing',
      'shipped'      : 'badge-shipped',
      'delivered'    : 'badge-delivered',
      'cancelled'    : 'badge-cancelled',
      'active'       : '',
      'inactive'     : 'badge-inactive',
      'low stock'    : 'low',
    };
    const key = (el.textContent || '').toLowerCase().trim();
    if (map[key]) el.classList.add(map[key]);
  });

});

  /* ── Logout confirmation modal ────────────────────────── */
  (function () {
    // Inject modal HTML once
    const modal = document.createElement('div');
    modal.id        = 'logoutModal';
    modal.className = 'logout-modal-backdrop';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'logoutModalTitle');
    modal.innerHTML = `
      <div class="logout-modal">
        <div class="logout-modal-icon">
          <i class="bi bi-box-arrow-right"></i>
        </div>
        <h3 id="logoutModalTitle">Log out?</h3>
        <p>You'll need to sign in again to access the portal.</p>
        <div class="logout-modal-actions">
          <button class="btn secondary" id="logoutCancel">Cancel</button>
          <button class="btn" id="logoutConfirm">Yes, log out</button>
        </div>
      </div>`;
    document.body.appendChild(modal);

    let pendingForm = null;

    // Intercept any form with class "logout-form" or action="logout.php"
    document.addEventListener('submit', e => {
      const form = e.target.closest('form[action="logout.php"], form.logout-form');
      if (!form) return;
      e.preventDefault();
      pendingForm = form;
      modal.classList.add('open');
      document.getElementById('logoutConfirm').focus();
    });

    document.getElementById('logoutCancel').addEventListener('click', () => {
      modal.classList.remove('open');
      pendingForm = null;
    });

    document.getElementById('logoutConfirm').addEventListener('click', () => {
      modal.classList.remove('open');
      if (pendingForm) pendingForm.submit();
    });

    // Close on backdrop click or Escape
    modal.addEventListener('click', e => {
      if (e.target === modal) { modal.classList.remove('open'); pendingForm = null; }
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && modal.classList.contains('open')) {
        modal.classList.remove('open');
        pendingForm = null;
      }
    });
  })();
