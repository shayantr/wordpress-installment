<?php
/*
Plugin Name: WooCommerce Installment Payment UI
Description: افزودن قابلیت پیش‌پرداخت و نمایش اقساط برای محصولات ووکامرس.
Version: 1.1
Author: shayantr
*/

add_shortcode('installment_info', 'custom_installment_ui_for_products');
function custom_installment_ui_for_products() {
    global $product;
    ?>
    <button type="button" id="show_installment_box" class="button alt" style="margin-top:20px;">پرداخت قسطی</button>
    <div id="installment_box" style="display:none; margin-top:20px;">
        <label for="installment_down_payment">مبلغ پیش‌پرداخت (حداقل ۳۰٪):</label>
        <input type="number" id="installment_down_payment" step="1000000" required>
        <div id="installment_result"></div>
        <form method="post" id="installment_form">
            <input type="hidden" name="custom_installment_submit" value="1">
            <input type="hidden" name="product_id" value="<?php echo $product->get_id(); ?>">
            <input type="hidden" id="variation_id_input" name="variation_id" value="">
            <input type="hidden" id="hidden_down_payment" name="down_payment" value="">
            <button type="submit" class="button alt">ثبت پیش‌پرداخت و افزودن به سبد خرید</button>
        </form>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const installmentBtn = document.getElementById('show_installment_box');
        const box = document.getElementById('installment_box');
        const input = document.getElementById('installment_down_payment');
        const result = document.getElementById('installment_result');
        const hidden = document.getElementById('hidden_down_payment');
        const variationInput = document.getElementById('variation_id_input');
        let selectedPrice = 0;

        installmentBtn.addEventListener('click', function () {
            box.style.display = 'block';
        });

        jQuery('form.variations_form').on('found_variation', function (event, variation) {
            if (variation && variation.display_price) {
                selectedPrice = variation.display_price;
                variationInput.value = variation.variation_id;
                input.min = Math.floor(selectedPrice * 0.3);
                input.max = selectedPrice;
                input.value = "";
                result.innerHTML = '';
                hidden.value = '';
            }
        });

        input.addEventListener('input', function () {
            const down = parseFloat(this.value);
            const min = selectedPrice * 0.3;
            const remain = selectedPrice - down;
            const maxRemain = 60000000;

            if (!selectedPrice) {
                result.innerHTML = `<p style="color:red;">ابتدا یک ویژگی را انتخاب کنید.</p>`;
                hidden.value = '';
                return;
            }

            if (isNaN(down) || down < min || remain > maxRemain || down > selectedPrice) {
                result.innerHTML = `<p style="color:red;">پیش‌پرداخت باید حداقل ۳۰٪ مبلغ محصول باشد و باقی‌مانده‌ی پرداخت نباید بیشتر از ۶۰ میلیون تومان باشد.</p>`;
                hidden.value = '';
                return;
            }

            let html = `<p>مبلغ باقی‌مانده: ${remain.toLocaleString()} تومان</p>`;
            for (let months = 2; months <= 6; months++) {
                let monthly = months < 3 ? Math.round(remain * months * 0.06) : Math.round(remain * months * 0.055);
                let newMonthly = Math.ceil((monthly + remain) / months / 50000) * 50000;
                html += `<p>${months} ماهه: <strong>${newMonthly.toLocaleString()} تومان</strong></p>`;
            }

            result.innerHTML = html;
            hidden.value = down;
        });
    });
    </script>
    <?php
}

add_action('template_redirect', 'handle_custom_installment_add_to_cart');
function handle_custom_installment_add_to_cart() {
    if (!isset($_POST['custom_installment_submit'])) return;
    $product_id = intval($_POST['product_id']);
    $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
    $down_payment = isset($_POST['down_payment']) ? floatval($_POST['down_payment']) : 0;
    if (!$product_id || !$down_payment) return;
    $product = wc_get_product($variation_id ? $variation_id : $product_id);
    if (!$product) return;
    $price = floatval($product->get_price());
    $min_down = $price * 0.3;
    $max_remain = 60000000;
    $remain = $price - $down_payment;

    if ($down_payment < $min_down || $down_payment > $price || $remain > $max_remain) {
        wc_add_notice(__('پیش‌پرداخت معتبر نیست. لطفاً عددی وارد کنید که حداقل ۳۰٪ مبلغ محصول باشد، بیشتر از مبلغ کل نباشد و باقی‌مانده بیش از ۶۰ میلیون تومان نشود.'), 'error');
        wp_redirect(wc_get_cart_url());
        exit;
    }

    WC()->cart->add_to_cart($product_id, 1, $variation_id, [], [
        'custom_down_payment' => $down_payment,
        'unique_key' => md5(microtime())
    ]);
    wp_redirect(wc_get_cart_url());
    exit;
}

add_action('woocommerce_before_calculate_totals', 'override_price_with_down_payment', 10, 1);
function override_price_with_down_payment($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    foreach ($cart->get_cart() as $item) {
        if (isset($item['custom_down_payment'])) {
            $item['data']->set_price($item['custom_down_payment']);
        }
    }
}

// Label "پیش‌پرداخت" در سبد خرید
add_filter('woocommerce_cart_item_name', 'add_down_payment_label_to_cart_item', 10, 3);
function add_down_payment_label_to_cart_item($name, $cart_item, $cart_item_key) {
    if (isset($cart_item['custom_down_payment'])) {
        $name .= '<br><small style="color:#a00;">[پرداخت فعلی: پیش‌پرداخت]</small>';
    }
    return $name;
}

// نمایش در صفحه پرداخت و ایمیل سفارش
add_filter('woocommerce_order_item_name', 'add_down_payment_label_to_order_item', 10, 2);
function add_down_payment_label_to_order_item($name, $item) {
    if (isset($item['custom_down_payment'])) {
        $name .= '<br><small style="color:#a00;">[پرداخت شده: پیش‌پرداخت]</small>';
    }
    return $name;
}

// ذخیره در سفارش
add_action('woocommerce_checkout_create_order_line_item', 'save_down_payment_to_order_item_meta', 10, 4);
function save_down_payment_to_order_item_meta($item, $cart_item_key, $values, $order) {
    if (isset($values['custom_down_payment'])) {
        $item->add_meta_data('custom_down_payment', wc_price($values['custom_down_payment']));
    }
}
?>
