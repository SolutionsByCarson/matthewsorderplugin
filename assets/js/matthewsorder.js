(function ($) {
    'use strict';

    $(function () {
        if (typeof MOP === 'undefined') {
            return;
        }

        var $catalog = $('#mop-product-catalog');
        if (!$catalog.length) {
            return;  // Not on the create-order view.
        }

        var S = (MOP.strings || {});
        var $search     = $('#mop-product-search');
        var $noResults  = $('.js-mop-no-results');
        var $modal      = $('#mop-product-modal');
        var $modalQty   = $('#mop-modal-qty');
        var $modalTotal = $('.js-mop-modal-total');
        var $modalAdd   = $modal.find('.js-mop-modal-add');
        var $cartBody   = $('#mop-cart-body');
        var $cartHidden = $('#mop-cart-hidden');
        var $cartCount  = $('#mop-cart-count');
        var $form       = $('#mop-order-form');

        var cart = {};          // keyed by product_id
        var activeProduct = null;

        /* ----------------------------- search --------------------------- */

        function norm(s) {
            return (s == null ? '' : String(s)).toLowerCase();
        }

        $search.on('input', function () {
            var q = norm($(this).val()).trim();
            var anyVisible = false;

            $catalog.find('.mop-product-category').each(function () {
                var $cat = $(this);
                var visible = 0;
                $cat.find('.mop-product-item').each(function () {
                    var $item = $(this);
                    var hay = norm($item.data('desc')) + ' ' +
                              norm($item.data('fmm'))  + ' ' +
                              norm($item.data('category')) + ' ' +
                              norm($item.data('uom'));
                    var match = q === '' || hay.indexOf(q) !== -1;
                    $item.toggle(match);
                    if (match) visible++;
                });
                $cat.toggle(visible > 0);
                if (visible > 0) anyVisible = true;
            });

            $noResults.prop('hidden', anyVisible);
        });

        /* ----------------------------- modal ---------------------------- */

        function productDataFromElement($el) {
            return {
                id:       String($el.data('id')),
                fmm:      String($el.data('fmm') || ''),
                desc:     String($el.data('desc') || ''),
                uom:      String($el.data('uom') || ''),
                base:     String($el.data('base') || ''),
                factor:   parseFloat($el.data('factor')) || 1,
                category: String($el.data('category') || '')
            };
        }

        function openModalFor(product, existingQty) {
            activeProduct = product;

            $modal.find('.mop-modal__title').text(product.desc);
            $modal.find('.js-mop-modal-category').text(product.category || '—');
            $modal.find('.js-mop-modal-fmm').text(product.fmm);
            $modal.find('.js-mop-modal-uom').text(product.uom);
            $modal.find('.js-mop-modal-base').text(product.base + ' (× ' + product.factor + ')');

            var qty = existingQty && existingQty > 0 ? existingQty : 1;
            $modalQty.val(qty);
            $modalAdd.text(existingQty ? (S.update || 'Update quantity') : (S.add || 'Add to order'));

            updateModalTotal();
            $modal.prop('hidden', false);
            document.body.classList.add('mop-modal-open');

            setTimeout(function () {
                $modalQty.trigger('focus').trigger('select');
            }, 20);
        }

        function closeModal() {
            $modal.prop('hidden', true);
            document.body.classList.remove('mop-modal-open');
            activeProduct = null;
        }

        function updateModalTotal() {
            if (!activeProduct || !S.totalBase) {
                $modalTotal.text('');
                return;
            }
            var qty = parseFloat($modalQty.val()) || 0;
            if (qty <= 0) {
                $modalTotal.text('');
                return;
            }
            var total = +(qty * activeProduct.factor).toFixed(4);
            var msg = S.totalBase.replace('%1$s', total).replace('%2$s', activeProduct.base);
            $modalTotal.text(msg);
        }

        $catalog.on('click', '.js-mop-product-open', function () {
            var $item = $(this).closest('.mop-product-item');
            var data  = productDataFromElement($item);
            var existing = cart[data.id];
            openModalFor(data, existing ? existing.qty : 0);
        });

        $cartBody.on('click', '.js-mop-cart-modify', function () {
            var id = String($(this).data('id'));
            var entry = cart[id];
            if (entry) openModalFor(entry, entry.qty);
        });

        $cartBody.on('click', '.js-mop-cart-remove', function () {
            var id = String($(this).data('id'));
            delete cart[id];
            renderCart();
        });

        $modal.on('click', '.js-mop-modal-close', closeModal);
        $modal.on('click', function (e) {
            if (e.target === this) closeModal();
        });
        $(document).on('keydown', function (e) {
            if (!$modal.prop('hidden') && e.key === 'Escape') closeModal();
        });
        $modalQty.on('input', updateModalTotal);

        $modal.on('click', '.js-mop-modal-add', function () {
            if (!activeProduct) return;
            var qty = parseInt($modalQty.val(), 10);
            if (!qty || qty < 1) {
                alert(S.invalidQty || 'Quantity must be at least 1.');
                $modalQty.trigger('focus');
                return;
            }
            cart[activeProduct.id] = $.extend({}, activeProduct, { qty: qty });
            renderCart();
            closeModal();
        });

        /* ----------------------------- cart ----------------------------- */

        function renderCart() {
            var ids = Object.keys(cart);
            $cartCount.text(ids.length);

            if (ids.length === 0) {
                $cartBody.html(
                    '<tr class="mop-cart-empty"><td colspan="5">' +
                    escapeHtml(S.emptyCart || '') +
                    '</td></tr>'
                );
                $cartHidden.empty();
                return;
            }

            $cartBody.empty();
            $cartHidden.empty();

            ids.forEach(function (id) {
                var e = cart[id];
                var $row = $('<tr class="mop-cart-row">');

                var $desc = $('<td class="mop-cart-col-desc">')
                    .append($('<span class="mop-cart-row__desc">').text(e.desc));
                if (e.category) {
                    $desc.append($('<span class="mop-cart-row__category">').text(e.category));
                }

                $row.append($desc)
                    .append($('<td class="mop-cart-col-fmm">').text(e.fmm))
                    .append($('<td class="mop-cart-col-uom">').text(e.uom))
                    .append($('<td class="mop-cart-col-qty">').text(e.qty))
                    .append(
                        $('<td class="mop-cart-col-actions">')
                            .append(
                                $('<button type="button" class="mop-btn mop-btn--link mop-btn--sm js-mop-cart-modify">')
                                    .attr('data-id', id)
                                    .text(S.modify || 'Modify')
                            )
                            .append(
                                $('<button type="button" class="mop-btn mop-btn--link mop-btn--sm mop-btn--danger js-mop-cart-remove">')
                                    .attr('data-id', id)
                                    .text(S.remove || 'Remove')
                            )
                    );

                $cartBody.append($row);

                $cartHidden
                    .append($('<input type="hidden">').attr({ name: 'mop_line[' + id + '][product_id]', value: id }))
                    .append($('<input type="hidden">').attr({ name: 'mop_line[' + id + '][qty]', value: e.qty }));
            });
        }

        function escapeHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        /* -------------------------- form submit ------------------------- */

        // Guard: refuse to submit an empty cart. Everything else (cart lines,
        // account field validation) is enforced server-side.
        $form.on('submit', function (e) {
            if (!Object.keys(cart).length) {
                e.preventDefault();
                alert(S.emptyCart || 'No products added yet.');
            }
        });

        renderCart();
    });
})(jQuery);
