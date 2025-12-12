<?php
/**
 * Plugin Name: WC Quick Order Box
 * Description: فرم سفارش سریع با جستجوی محصول، فروشنده خواندنی، ثبت AJAX، چک موجودی، کاهش موجودی توسط WooCommerce. (بدون SMS) — فیلدهای نام/آدرس/موبایل اختیاری.
 * Author: Sepand & Narges
 * Version: 1.9.3
 */

if ( ! defined('ABSPATH') ) exit;

// نسخه داخلی برای مدیریت کش
if ( ! defined('QOF_VERSION') ) {
    define('QOF_VERSION', '1.9.3');
}

/*======================================
=  سازگاری با HPOS (Custom Order Tables)
======================================*/
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
});

/*======================================
=  تعویق ایمیل‌های تراکنشی
======================================*/
add_filter('woocommerce_defer_transactional_emails', '__return_true');

/*--------------------------------------
| Helpers
---------------------------------------*/
function qof_normalize_digits($s){
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    return str_replace($ar, $en, str_replace($fa, $en, (string)$s));
}

/*--------------------------------------
| Sellers (کُد → نام) — مرجع واحد
---------------------------------------*/
function qof_sellers() {
    return [
        '910' => 'هاجر رستمی',
        '911' => 'مهرنوش هاشمی',
        '912' => 'اسماعیل آرین پور',
        '913' => 'زهرا حاتمی',
        '914' => 'شعبه تهرانپارس',
        // '915' => 'نام فروشنده جدید',
    ];
}

/*--------------------------------------
| استخراج ظرفیت از اتربیوت pa_multi
---------------------------------------*/
function qof_capacity_from_product($product){
    $cap = 0;
    if ( $product->is_type('variation') ) {
        $slug = $product->get_meta('attribute_pa_multi', true);
        if ($slug !== '') {
            $slug = qof_normalize_digits($slug);
            if (preg_match('/(\d+)/', $slug, $m)) $cap = intval($m[1]);
        }
        if (!$cap) {
            $val = $product->get_attribute('pa_multi');
            if ($val !== '') {
                $val = qof_normalize_digits($val);
                if (preg_match('/(\d+)/', $val, $m)) $cap = intval($m[1]);
            }
        }
    } else {
        $val = $product->get_attribute('pa_multi');
        if ($val !== '') {
            $val = qof_normalize_digits($val);
            if (preg_match('/(\d+)/', $val, $m)) $cap = intval($m[1]);
        }
    }
    return $cap > 0 ? $cap : 0;
}

/*--------------------------------------
| Cache helpers
---------------------------------------*/
function qof_products_cache_key() {
    return 'qof_products_cache_' . QOF_VERSION;
}
function qof_bust_products_cache() {
    delete_transient( qof_products_cache_key() );
}
// هر تغییر موجودی/ذخیره‌ی استاک → کش پاک شود
add_action('woocommerce_reduce_order_stock', 'qof_bust_products_cache');
add_action('woocommerce_product_set_stock', 'qof_bust_products_cache');
add_action('woocommerce_variation_set_stock', 'qof_bust_products_cache');

/*--------------------------------------
| Enqueue فرانت
---------------------------------------*/
function qof_enqueue_front_assets() {
    wp_enqueue_script('jquery');
    if ( wp_script_is('selectWoo', 'registered') || wp_script_is('selectWoo', 'enqueued') ) {
        wp_enqueue_script('selectWoo');
        if ( wp_style_is('select2', 'registered') ) wp_enqueue_style('select2');
    } else {
        wp_enqueue_style('qof-select2', plugins_url('assets/select2.min.css', __FILE__), [], '4.1.0');
        wp_enqueue_script('qof-select2', plugins_url('assets/select2.min.js', __FILE__), ['jquery'], '4.1.0', true);
    }
}
add_action('wp_enqueue_scripts', 'qof_enqueue_front_assets');

/*--------------------------------------
| کش لیست محصولات برای کاهش بار
---------------------------------------*/
function qof_get_cached_products() {
    $cache_key = qof_products_cache_key();
    $products = get_transient($cache_key);
    if (false === $products) {
        $products = wc_get_products([
            'status' => 'publish',
            'limit'  => -1,
            'type'   => ['simple', 'variation'],
            'return' => 'objects',
        ]);
        set_transient($cache_key, $products, HOUR_IN_SECONDS);
    }
    return $products;
}

/*--------------------------------------
| Shortcode: [quick_order_box key="910|911|913"]
---------------------------------------*/
add_shortcode('quick_order_box', function($atts){
    qof_enqueue_front_assets();
    if ( ! function_exists('wc_get_products') ) return '<div dir="rtl" style="color:#b91c1c">WooCommerce فعال نیست.</div>';

    $atts = shortcode_atts(['key' => ''], $atts, 'quick_order_box');
    $products = qof_get_cached_products();

    $make_label = function( $p ){
        if ( $p->is_type('variation') ) {
            $parent = wc_get_product( $p->get_parent_id() );
            $base   = $parent ? $parent->get_name() : ('Variation #'.$p->get_id());
            $attrs  = wc_get_formatted_variation( $p, true, true, false );
            $attrs  = trim( wp_strip_all_tags( (string) $attrs ) );
            $label  = trim( $base . ( $attrs ? ' – ' . $attrs : '' ) );
            if ( $label === '' ) $label = $p->get_name() ?: ('#'.$p->get_id());
            return $label;
        }
        $name = $p->get_name();
        return $name !== '' ? $name : ('#'.$p->get_id());
    };

    $bucketed = [];
    foreach ($products as $p){
        $stock = $p->get_stock_quantity();
        if ($stock === null) $stock = 0;
        $row = ['id'=>$p->get_id(),'label'=>$make_label($p),'stock'=>(int)$stock];
        $cap = qof_capacity_from_product($p); if ($cap <= 0) $cap = 0;
        $bucketed[$cap][] = $row;
    }
    foreach ($bucketed as $cap => &$list) usort($list, fn($a,$b)=>strcasecmp($a['label'],$b['label']));
    unset($list);

    $name_select_html = ''; $id_select_html = '';
    $all = []; $rendered_caps = []; $preferred_order = [4,6,8,12,0];

    foreach ($preferred_order as $cap) {
        if (!isset($bucketed[$cap])) continue;
        $group_label = $cap > 0 ? ($cap.' نفره') : 'سایر';
        $name_select_html .= '<optgroup label="'.esc_attr($group_label).'">';
        $id_select_html   .= '<optgroup label="'.esc_attr($group_label).'">';
        foreach ($bucketed[$cap] as $row) {
            $opt_text_name = $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $opt_text_id   = $row['id'] . ' — ' . $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $name_select_html .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'">'.esc_html($opt_text_name).'</option>';
            $id_select_html   .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'">'.esc_html($opt_text_id).'</option>';
            $all[] = $row;
        }
        $name_select_html .= '</optgroup>';
        $id_select_html   .= '</optgroup>';
        $rendered_caps[$cap] = true;
    }

    $others = [];
    foreach ($bucketed as $cap => $list) {
        if (isset($rendered_caps[$cap])) continue;
        foreach ($list as $row) { $others[] = $row; }
    }
    usort($others, fn($a,$b)=>strcasecmp($a['label'],$b['label']));
    if (!empty($others)) {
        $name_select_html .= '<optgroup label="'.esc_attr('سایر').'">';
        $id_select_html   .= '<optgroup label="'.esc_attr('سایر').'">';
        foreach ($others as $row) {
            $opt_text_name = $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $opt_text_id   = $row['id'] . ' — ' . $row['label'] . ' — [موجودی: ' . $row['stock'] . ']';
            $name_select_html .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'">'.esc_html($opt_text_name).'</option>';
            $id_select_html   .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'">'.esc_html($opt_text_id).'</option>';
            $all[] = $row;
        }
        $name_select_html .= '</optgroup>';
        $id_select_html   .= '</optgroup>';
    }

    ob_start(); ?>

    <style>
      #qof-form .row-flex{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
      #qof-form select{max-width:100%}
      #qof-form .w-id{width:220px}
      #qof-form .w-name{width:640px}
      #qof-form .qty-input{width:110px;text-align:center;font-size:18px;padding:6px}
      #qof-form .table-wrap{margin-top:10px;display:none;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden}
      #qof-form table{border-collapse:collapse;width:100%}
      #qof-form th,#qof-form td{padding:8px;border-top:1px solid #e5e7eb}
      #qof-form thead th{background:#f3f4f6;border-top:none}
      #qof-form .btn{cursor:pointer;border-radius:10px}
      #qof-form .btn-primary{border:1px solid #2563eb;background:#2563eb;color:#fff}
      #qof-form .btn-add{border:1px solid #10b981;background:#bbf7d0;color:#065f46;font-weight:600}
      #qof-form .addr-box{width:100%;min-height:96px;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px}
      #qof-form .helper{color:#6b7280;font-size:12px;margin-top:6px}
      #qof-form .stock-badge{font-size:13px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;padding:6px 10px;color:#1e40af}
      #qof-form .stock-badge.zero{background:#fee2e2;border-color:#fecaca;color:#991b1b}
      #qof-form .muted{opacity:.6;pointer-events:none}
      .select2-container .select2-results > .select2-results__options{max-height: 70vh !important;overscroll-behavior: contain;}
      .select2-dropdown{max-height: 75vh !important;overflow: auto !important;}
      .select2-search--dropdown .select2-search__field{padding:8px; font-size:14px; line-height:1.4;}
      #qof-msg{display:none;margin-bottom:8px;padding:10px;border-radius:8px;white-space:pre-line}
      #qof-msg.ok{display:block;background:#ecfdf5;border:1px solid #10b981;color:#065f46}
      #qof-msg.err{display:block;background:#fef2f2;border:1px solid #ef4444;color:#991b1b}
      @media (max-width: 768px){
        #qof-form .w-id{width:100%}
        #qof-form .w-name{width:100%}
        #qof-form .qty-input{width:88px;font-size:16px}
        #qof-form .btn-primary,#qof-form .btn-add{width:100%}
        #qof-form thead { display:none; }
        #qof-form tbody tr{display:flex; flex-wrap:wrap; gap:8px; padding:10px;}
        #qof-form tbody td{border:none; padding:0;}
        #qof-form .col-name{order:1; flex:1 1 100%; font-weight:600; font-size:14px;}
        #qof-form .col-qty{order:2; display:flex; align-items:center; gap:6px;}
        #qof-form .col-del{order:3; margin-inline-start:auto;}
        #qof-form .col-stock{order:4; font-size:12px; opacity:.8;}
        #qof-form .col-id{order:5; font-size:12px; opacity:.6;}
      }
    </style>

    <div id="qof-msg"></div>

    <form id="qof-form" dir="rtl" method="post" action="#" style="display:grid;gap:12px;align-items:center">

        <div class="row-flex">
            <label for="qof-sel-id" style="min-width:70px">ID:</label>
            <select id="qof-sel-id" class="w-id">
                <option value="">انتخاب ID</option>
                <?php echo $id_select_html; ?>
            </select>
            <label for="qof-sel-name" style="min-width:40px">محصول:</label>
            <select id="qof-sel-name" class="w-name">
                <option value="">انتخاب محصول</option>
                <?php echo $name_select_html; ?>
            </select>
        </div>

        <div class="row-flex" id="qof-stock-line" style="display:none">
            <span id="qof-stock-badge" class="stock-badge">موجودی: — | قابل افزودن: —</span>
        </div>

        <div class="row-flex">
          <span>تعداد:</span>
          <button type="button" id="qof-btn-dec" style="font-size:22px;padding:6px 12px" class="btn">➖</button>
          <input type="number" id="qof-qty" value="1" min="1" class="qty-input">
          <button type="button" id="qof-btn-inc" style="font-size:22px;padding:6px 12px" class="btn">➕</button>
          <button type="button" id="qof-btn-add" style="margin-inline-start:12px;padding:10px 16px" class="btn btn-add muted" disabled>➕ اضافه کردن</button>
        </div>

        <div class="row-flex" style="width:100%">
          <div style="flex:1; min-width:240px">
            <label for="qof-cust-name" style="display:block;margin-bottom:6px">نام و نام‌خانوادگی مشتری (اختیاری):</label>
            <input type="text" id="qof-cust-name" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px" placeholder="مثال: محسن رضایی">
          </div>
          <div style="flex:1; min-width:220px">
            <label for="qof-cust-phone" style="display:block;margin-bottom:6px">شماره موبایل (اختیاری):</label>
            <input type="tel" id="qof-cust-phone" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px" placeholder="۰۹۱۱۱۱۱۱۱۱۱">
          </div>
          <div style="flex:1; min-width:220px">
            <label for="qof-seller" style="display:block;margin-bottom:6px">فروشنده:</label>
            <input type="text" id="qof-seller" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;background:#f9fafb" readonly>
          </div>
        </div>

        <!-- فقط برای شعبه تهرانپارس: نوع تحویل -->
        <div class="row-flex" id="qof-delivery-wrap" style="width:100%;display:none;margin-top:4px">
          <span style="font-weight:600">نحوه تحویل:</span>
          <label style="display:flex;align-items:center;gap:4px">
            <input type="radio" name="qof_delivery" value="home" checked>
            <span>ارسال درب منزل (پست)</span>
          </label>
          <label style="display:flex;align-items:center;gap:4px">
            <input type="radio" name="qof_delivery" value="branch">
            <span>تحویل در شعبه تهرانپارس</span>
          </label>
        </div>

        <div class="table-wrap" id="qof-table-wrap">
          <table id="qof-items-table">
            <thead>
              <tr>
                <th style="width:110px">ID</th>
                <th>محصول</th>
                <th style="width:140px;text-align:center">موجودی فعلی</th>
                <th style="width:280px;text-align:center">تعداد (+/−)</th>
                <th style="width:100px;text-align:center">حذف</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>

        <div>
          <label for="qof-address" style="display:block;margin-bottom:6px">آدرس سفارش (اختیاری):</label>
          <textarea id="qof-address" class="addr-box" placeholder="اختیاری"></textarea>
          <div class="helper">برای فعال شدن «ثبت نهایی»، فقط کافی است حداقل یک آیتم اضافه شده باشد.</div>
        </div>

        <?php wp_nonce_field('qof_place_order_form','_wpnonce_qof'); ?>
        <input type="hidden" id="qof-hidden-items"   name="items" value="">
        <input type="hidden" id="qof-hidden-address" name="address" value="">
        <input type="hidden" id="qof-hidden-name"    name="cust_name" value="">
        <input type="hidden" id="qof-hidden-phone"   name="cust_phone" value="">
        <input type="hidden" id="qof-hidden-code"    name="user_code" value="">
        <!-- نوع تحویل برای ارسال از طریق AJAX -->
        <input type="hidden" id="qof-hidden-delivery" name="delivery_type" value="">

        <div>
          <button type="submit" id="qof-btn-save" style="margin-top:4px;padding:12px 18px" class="btn btn-primary" disabled>✅ ثبت نهایی سفارش</button>
        </div>
    </form>

    <script>
    jQuery(function($){
        const allProducts = <?php echo wp_json_encode($all); ?>;
        const defaultShortcodeKey = "<?php echo esc_js($atts['key']); ?>";
        const urlParams = new URLSearchParams(window.location.search);
        const urlKey = urlParams.get('key') || urlParams.get('code') || '';
        const userCode = urlKey || defaultShortcodeKey;

        // فروشنده‌ها
        const sellerMap = <?php echo wp_json_encode( qof_sellers(), JSON_UNESCAPED_UNICODE ); ?>;
        const sellerName = sellerMap[userCode] || "";
        $('#qof-seller').val(sellerName);

        // فقط برای شعبه تهرانپارس
        const isTehranpars = (sellerName === 'شعبه تهرانپارس' || userCode === '914');
        if (isTehranpars) {
            $('#qof-delivery-wrap').show();
        } else {
            $('#qof-delivery-wrap').hide();
            $('#qof-address').prop('readonly', false);
        }

        // هندل رادیوباتن برای شعبه تهرانپارس
        $('input[name="qof_delivery"]').on('change', function(){
            if (!isTehranpars) return;
            const val = $('input[name="qof_delivery"]:checked').val();
            if (val === 'branch') {
                $('#qof-address').val('باید به شعبه تهرانپارس تحویل گردد.');
                $('#qof-address').prop('readonly', true);
            } else {
                if ($('#qof-address').val() === 'باید به شعبه تهرانپارس تحویل گردد.') {
                    $('#qof-address').val('');
                }
                $('#qof-address').prop('readonly', false);
            }
        });

        const items = [];

        // --- Select2/SelectWoo init/destroy helpers ---
        const hasSelect = $.fn && ($.fn.selectWoo || $.fn.select2);
        function initSelect(sel){
            if(!hasSelect) return;
            const $el = $(sel);
            const opts = { placeholder: '', allowClear: true, width: 'resolve' };
            if ($.fn.selectWoo) { $el.selectWoo(opts); } else { $el.select2(opts); }
        }
        function destroySelect(sel){
            const $el = $(sel);
            try { if ($.fn.selectWoo && $el.data('select2')) $el.selectWoo('destroy'); } catch(e){}
            try { if ($.fn.select2  && $el.data('select2')) $el.select2('destroy'); } catch(e){}
        }
        function reinitSelect(sel){
            const $el = $(sel);
            const val = $el.val();
            destroySelect(sel);
            initSelect(sel);
            $el.val(val).trigger('change.select2');
        }
        function reinitBoth(){ reinitSelect('#qof-sel-id'); reinitSelect('#qof-sel-name'); }

        if (hasSelect){ initSelect('#qof-sel-id'); initSelect('#qof-sel-name'); }

        function findById(id){ return allProducts.find(p => String(p.id) === String(id)); }
        function findLabelById(id){ const f = findById(id); return f ? f.label : ''; }
        function baseStockById(id){ const f = findById(id); return f ? (f.stock||0) : 0; }
        function sumSelectedQty(id){ return items.filter(x=>String(x.id)===String(id)).reduce((a,b)=>a+(+b.qty||0),0); }
        function availableStock(id){ return Math.max(0, baseStockById(id) - sumSelectedQty(id)); }
        function getSelectedId(){ return $('#qof-sel-id').val() || $('#qof-sel-name').val() || ''; }

        function updateStockBadge(){
            const pid = getSelectedId();
            if(!pid){ $('#qof-stock-line').hide(); return; }
            const total = baseStockById(pid);
            const avail = availableStock(pid);
            const $line = $('#qof-stock-line'), $badge = $('#qof-stock-badge');
            $line.show();
            $badge.text('موجودی: '+total+' | قابل افزودن: '+avail);
            $badge.toggleClass('zero', avail===0);
        }

        function clampQty(val, max){
            val = parseInt(val||0,10);
            if (isNaN(val) || val<1) val = 1;
            if (max>=0) val = Math.min(val, max);
            return val;
        }

        function refreshAddButtonState(){
            const pid = getSelectedId();
            const avail = pid ? availableStock(pid) : 0;
            let qty = +$('#qof-qty').val();
            qty = isNaN(qty)?0:qty;
            const ok = (pid && avail>0 && qty>=1 && qty<=avail);
            $('#qof-btn-add').prop('disabled', !ok).toggleClass('muted', !ok);
        }

        function syncSelects(val){
            $('#qof-sel-id').val(val).trigger('change.select2');
            $('#qof-sel-name').val(val).trigger('change.select2');
        }

        function onSelectChange(){
            const pid = getSelectedId();
            updateStockBadge();
            if(!pid){ $('#qof-qty').val(1); refreshAddButtonState(); return; }
            const avail = availableStock(pid);
            if (avail===0){
                alert('موجودی این محصول صفر است.');
                $('#qof-qty').val(1);
            } else {
                $('#qof-qty').val(1);
            }
            refreshAddButtonState();
        }

        $('#qof-sel-id').on('change', function(){ syncSelects($(this).val()); onSelectChange(); });
        $('#qof-sel-name').on('change', function(){ syncSelects($(this).val()); onSelectChange(); });

        $('#qof-btn-inc').on('click', ()=>{
            const pid = getSelectedId(); if(!pid) return;
            const avail = availableStock(pid);
            const cur = +$('#qof-qty').val()||1;
            $('#qof-qty').val( Math.min(cur+1, Math.max(1, avail)) );
            refreshAddButtonState();
        });
        $('#qof-btn-dec').on('click', ()=>{
            const cur = +$('#qof-qty').val()||1;
            $('#qof-qty').val( Math.max(1, cur-1) );
            refreshAddButtonState();
        });
        $('#qof-qty').on('input change', ()=>{
            const pid = getSelectedId();
            const avail = pid ? availableStock(pid) : 0;
            $('#qof-qty').val( clampQty($('#qof-qty').val(), avail) );
            refreshAddButtonState();
        });

        function renderTable(){
            const $wrap = $('#qof-table-wrap');
            const tbody = $('#qof-items-table tbody').empty();
            if(items.length === 0){
                $wrap.hide(); checkReady(); updateStockBadge(); return;
            }
            $wrap.show();
            items.forEach((it,idx)=>{
                const pid = it.id;
                const stock = baseStockById(pid);
                const sumOthers = items.reduce((a,b,i)=> i===idx ? a : (String(b.id)===String(pid) ? a+(+b.qty||0) : a), 0);
                const rowMax = Math.max(0, stock - sumOthers);
                const $tr = $('<tr>');
                $tr.append(`<td class="col-id">${it.id}</td>`);
                $tr.append(`<td class="col-name">${it.name}</td>`);
                $tr.append(`<td class="col-stock" style="text-align:center">موجودی: ${stock}</td>`);
                const disableInc = it.qty >= rowMax;
                const qtyControls = $(`
                  <td class="col-qty" style="text-align:center">
                    <button class="qof-row-dec" data-i="${idx}" type="button" style="font-size:18px;padding:4px 10px;margin-inline:4px">➖</button>
                    <input type="number" class="qof-row-qty" data-i="${idx}" value="${it.qty}" min="1" ${rowMax>0?`max="${rowMax}"`:''} style="width:80px;text-align:center;font-size:16px;padding:4px">
                    <button class="qof-row-inc" data-i="${idx}" type="button" ${disableInc?'disabled':''} style="font-size:18px;padding:4px 10px;margin-inline:4px;${disableInc?'opacity:.6;cursor:not-allowed':''}">➕</button>
                  </td>
                `);
                $tr.append(qtyControls);
                $tr.append(`<td class="col-del" style="text-align:center"><button data-i="${idx}" type="button" class="qof-btn-del" style="cursor:pointer">❌</button></td>`);
                tbody.append($tr);
            });
            checkReady(); updateStockBadge();
        }

        // --- NEW: Helpers برای به‌روزرسانی موجودی UI بدون رفرش + ری‌اینیت Select2/SelectWoo ---
        function setOptionStock($sel, id, stock){
          const $opt = $sel.find('option[value="'+id+'"]');
          if($opt.length){
            $opt.attr('data-stock', stock);
            const t = $opt.text();
            const newText = t.replace(/(\[موجودی:\s*)(\d+)(\])/u, '$1'+stock+'$3');
            $opt.text(newText);
          }
        }
        function applyStocksToUI(stockMap){
          if(!stockMap) return;
          Object.keys(stockMap).forEach(function(id){
            const stock = parseInt(stockMap[id] || 0,10);
            const p = allProducts.find(x => String(x.id) === String(id));
            if(p){ p.stock = stock; }
            setOptionStock($('#qof-sel-id'),   id, stock);
            setOptionStock($('#qof-sel-name'), id, stock);
          });
          // ری‌اینیت اجباری برای نابود کردن کش داخلی Select2/SelectWoo
          reinitBoth();
          // آپدیت Badge و دکمه‌ها
          updateStockBadge();
          refreshAddButtonState();
        }

        $('#qof-btn-add').on('click', function(){
            const pid = getSelectedId();
            if(!pid) return alert('یک محصول انتخاب کن');
            const avail = availableStock(pid);
            if(avail<=0){ alert('موجودی کافی نیست.'); refreshAddButtonState(); return; }
            let qty = clampQty($('#qof-qty').val(), avail);
            if(qty<1){ alert('تعداد معتبر نیست.'); return; }
            const name  = findLabelById(pid) || '(بدون نام)';
            items.push({id: pid, name, qty});
            renderTable();
            syncSelects(''); $('#qof-qty').val(1); refreshAddButtonState();
        });

        $('#qof-items-table').on('click','.qof-row-inc', function(){
            const idx = +$(this).data('i');
            const pid = items[idx].id;
            const stock = baseStockById(pid);
            const sumOthers = items.reduce((a,b,i)=> i===idx ? a : (String(b.id)===String(pid) ? a+(+b.qty||0) : a), 0);
            const rowMax = Math.max(0, stock - sumOthers);
            if(items[idx].qty < rowMax){ items[idx].qty++; }
            renderTable();
        });
        $('#qof-items-table').on('click','.qof-row-dec', function(){
            const idx = +$(this).data('i');
            items[idx].qty = Math.max(1, (+items[idx].qty||1)-1);
            renderTable();
        });
        $('#qof-items-table').on('change input','.qof-row-qty', function(){
            const idx = +$(this).data('i');
            const pid = items[idx].id;
            const stock = baseStockById(pid);
            const sumOthers = items.reduce((a,b,i)=> i===idx ? a : (String(b.id)===String(pid) ? a+(+b.qty||0) : a), 0);
            const rowMax = Math.max(0, stock - sumOthers);
            const v = clampQty($(this).val(), rowMax);
            items[idx].qty = v;
            renderTable();
        });
        $('#qof-items-table').on('click','.qof-btn-del',function(){
            items.splice($(this).data('i'),1);
            renderTable();
        });

        function checkReady(){
            const hasItems = items.length > 0;
            $('#qof-btn-save').prop('disabled', !hasItems);
        }

        $('#qof-address,#qof-cust-name,#qof-cust-phone').on('input', checkReady);

        let submitting = false;
        $('#qof-form').on('submit', function(e){
            e.preventDefault();
            if (submitting) return false;
            if (items.length === 0){
                $('#qof-msg').removeClass('ok').addClass('err').text('هیچ آیتمی انتخاب نشده.').show();
                return false;
            }

            const address   = ($('#qof-address').val()||'').trim();
            const custName  = ($('#qof-cust-name').val()||'').trim();
            const custPhone = ($('#qof-cust-phone').val()||'').trim();

            // نوع تحویل برای شعبه تهرانپارس
            let deliveryType = '';
            if (isTehranpars) {
                deliveryType = $('input[name="qof_delivery"]:checked').val() || 'home';
            }

            $('#qof-hidden-items').val(JSON.stringify(items));
            $('#qof-hidden-address').val(address);
            $('#qof-hidden-name').val(custName);
            $('#qof-hidden-phone').val(custPhone);
            $('#qof-hidden-code').val(userCode);
            $('#qof-hidden-delivery').val(deliveryType);

            submitting = true;
            const $btn = $('#qof-btn-save');
            $btn.prop('disabled', true).css({opacity: 0.6, cursor: 'not-allowed'}).text('در حال ثبت سفارش...');

            $.ajax({
              url: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
              type: 'POST',
              dataType: 'json',
              data: {
                action: 'qof_place_order',
                _wpnonce_qof: $('input[name="_wpnonce_qof"]').val(),
                items: $('#qof-hidden-items').val(),
                address: $('#qof-hidden-address').val(),
                cust_name: $('#qof-hidden-name').val(),
                cust_phone: $('#qof-hidden-phone').val(),
                user_code: $('#qof-hidden-code').val(),
                delivery_type: $('#qof-hidden-delivery').val()
              }
            }).done(function(res){
              if(res && res.success && res.data && res.data.ok){
                 $('#qof-msg').removeClass('ok').removeClass('err'); // reset classes
                 $('#qof-msg').addClass('ok').text('سفارش با موفقیت ثبت شد. شماره سفارش: #'+res.data.order_id).show();

                 // NEW: به‌روزرسانی آنی موجودی‌ها + ری‌اینیت Select2/SelectWoo
                 if(res.data.stocks){
                    applyStocksToUI(res.data.stocks);
                 }

                 // پاکسازی فرم
                 items.splice(0, items.length);
                 $('#qof-address').val('').prop('readonly', false);
                 $('#qof-cust-name').val(''); $('#qof-cust-phone').val('');
                 if (isTehranpars) {
                    $('input[name="qof_delivery"][value="home"]').prop('checked', true);
                 }
                 renderTable(); updateStockBadge(); checkReady();
                 syncSelects('');
              } else {
                 const msg = (res && res.data && res.data.err) ? res.data.err : 'خطای نامشخص در ثبت سفارش.';
                 $('#qof-msg').removeClass('ok').addClass('err').text(msg).show();
              }
            }).fail(function(){
              $('#qof-msg').removeClass('ok').addClass('err').text('عدم ارتباط با سرور. دوباره تلاش کن.').show();
            }).always(function(){
              submitting = false;
              $btn.prop('disabled', false).css({opacity: 1, cursor: ''}).text('✅ ثبت نهایی سفارش');
            });
        });

        checkReady();
    });
    </script>
    <?php
    return ob_get_clean();
});

/*--------------------------------------
| ثبت AJAX: ایجاد سفارش + چک موجودی + کاهش موجودی توسط WooCommerce
---------------------------------------*/
add_action('wp_ajax_qof_place_order', 'qof_place_order_ajax');
add_action('wp_ajax_nopriv_qof_place_order', 'qof_place_order_ajax');

function qof_place_order_ajax(){
    if (function_exists('error_reporting')) { error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT); }

    if ( empty($_POST['_wpnonce_qof']) || ! wp_verify_nonce( wp_unslash($_POST['_wpnonce_qof']), 'qof_place_order_form') ) {
        wp_send_json_error(['err' => 'خطای امنیتی (Nonce).']);
    }

    $raw          = isset($_POST['items']) ? wp_unslash($_POST['items']) : '[]';
    $items        = json_decode($raw, true);
    $address      = isset($_POST['address'])       ? sanitize_textarea_field( wp_unslash($_POST['address']) ) : '';
    $cust_name    = isset($_POST['cust_name'])     ? sanitize_text_field( wp_unslash($_POST['cust_name']) )   : '';
    $cust_phone   = isset($_POST['cust_phone'])    ? sanitize_text_field( wp_unslash($_POST['cust_phone']) )  : '';
    $user_code    = isset($_POST['user_code'])     ? sanitize_text_field( wp_unslash($_POST['user_code']) )   : '';
    $delivery_type= isset($_POST['delivery_type']) ? sanitize_text_field( wp_unslash($_POST['delivery_type']) ) : '';

    if ( ! is_array($items) || empty($items) ) wp_send_json_error(['err'=>'هیچ آیتمی ارسال نشده است.']);

    // فروشنده‌ها
    $map    = qof_sellers();
    $seller = isset($map[$user_code]) ? $map[$user_code] : '';

    $user = wp_get_current_user();
    $uid  = (int) ($user->ID ?? 0);
    $ip   = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field( wp_unslash($_SERVER['REMOTE_ADDR']) ) : '';

    // تجمیع تعداد
    $req = []; $prod_cache = [];
    $get_prod = function($pid) use (&$prod_cache){
        $pid = (int)$pid;
        if (!isset($prod_cache[$pid])) $prod_cache[$pid] = wc_get_product($pid);
        return $prod_cache[$pid];
    };

    foreach($items as $it){
        $pid = isset($it['id'])  ? absint($it['id']) : 0;
        $qty = isset($it['qty']) ? (int) $it['qty']  : 0;
        if(!$pid || $qty<=0) continue;
        $req[$pid] = ($req[$pid] ?? 0) + $qty;
    }
    if(empty($req)) wp_send_json_error(['err'=>'آیتم معتبر یافت نشد.']);

    // چک موجودی سمت سرور
    $errors = [];
    foreach($req as $pid=>$qty){
        $product = $get_prod($pid);
        if( ! $product ){ $errors[] = "محصول #$pid یافت نشد."; continue; }
        $stock = (int) ($product->get_stock_quantity() ?? 0);
        if ( $qty > $stock ){
            $name = $product->get_name();
            $errors[] = sprintf('موجودی کافی برای «%s» نیست. درخواست: %d | موجودی: %d', $name, $qty, $stock);
        }
    }
    if(!empty($errors)) wp_send_json_error(['err'=>implode("\n", $errors)]);

    try{
        // ایجاد سفارش
        $order = wc_create_order([ 'customer_id' => $uid ?: 0 ]);
        foreach($req as $pid=>$qty){
            $product = $get_prod($pid);
            if( $product ) $order->add_product( $product, $qty );
        }

        $addr = [
            'first_name' => $cust_name,
            'last_name'  => '',
            'address_1'  => $address,
            'address_2'  => '',
            'city'       => '',
            'state'      => '',
            'postcode'   => '',
            'country'    => '',
            'phone'      => $cust_phone,
            'email'      => $uid ? ($user->user_email ?? '') : '',
        ];
        $order->set_address($addr, 'billing');
        $order->set_address($addr, 'shipping');

        $note_lines = [];
        if ($user_code)     $note_lines[] = 'کد کاربر: '.$user_code;
        if ($ip)            $note_lines[] = 'IP: '.$ip;
        if ($cust_name)     $note_lines[] = 'نام مشتری: '.$cust_name;
        if ($cust_phone)    $note_lines[] = 'موبایل مشتری: '.$cust_phone;
        if ($seller)        $note_lines[] = 'فروشنده: '.$seller;
        if ($delivery_type) {
            if ($delivery_type === 'branch') {
                $note_lines[] = 'نحوه تحویل: تحویل در شعبه تهرانپارس';
            } else {
                $note_lines[] = 'نحوه تحویل: ارسال درب منزل (پست)';
            }
        }
        $note_lines[] = 'ثبت از «WC Quick Order Box» (AJAX).';
        $order->add_order_note( implode(" | ", $note_lines) );
        if ($seller) $order->set_customer_note('فروشنده: '.$seller);

        if ($user_code)      $order->update_meta_data('_wc_qof_user_code', $user_code);
        if ($cust_name)      $order->update_meta_data('_wc_qof_customer_fullname', $cust_name);
        if ($cust_phone)     $order->update_meta_data('_wc_qof_customer_phone', $cust_phone);
        if ($delivery_type)  $order->update_meta_data('_wc_qof_delivery_type', $delivery_type);

        // محاسبهٔ مجموع‌ها
        if (method_exists($order, 'calculate_totals')) {
            try { $order->calculate_totals(false); } catch (\Throwable $e) { $order->calculate_totals(); }
        } else {
            $order->calculate_totals();
        }

        // چک نهایی موجودی قبل از کاهش
        $changed_errs = [];
        foreach ($req as $pid => $qty) {
            $p = $get_prod($pid);
            if (!$p) { $changed_errs[] = "محصول #$pid یافت نشد."; continue; }
            $cur = (int) ($p->get_stock_quantity() ?? 0);
            if ($qty > $cur) {
                $changed_errs[] = sprintf('موجودی «%s» همین الان تغییر کرد. درخواست: %d | موجودی فعلی: %d', $p->get_name(), $qty, $cur);
            }
        }
        if (!empty($changed_errs)) {
            $order->update_status('cancelled', 'لغو خودکار: تغییر موجودی هنگام ثبت نهایی (AJAX).');
            wp_send_json_error(['err'=>implode("\n", $changed_errs)]);
        }

        // ذخیره و کاهش موجودی توسط WooCommerce
        $order->update_status('processing', 'ثبت از فرم سفارش سریع (AJAX).', true);
        $order->save();

        $order_id = $order->get_id();

        if ( function_exists('wc_maybe_reduce_stock_levels') ) {
            wc_maybe_reduce_stock_levels( $order_id );
        }

        // موجودی‌های به‌روز بعد از کاهش را برگردان
        $newstocks = [];
        foreach (array_keys($req) as $pid) {
            $pp = wc_get_product($pid);
            $newstocks[$pid] = (int) ($pp ? ($pp->get_stock_quantity() ?? 0) : 0);
        }

        // پاک‌کردن کش محصولات
        qof_bust_products_cache();

        wp_send_json_success([
            'ok'       => 1,
            'order_id' => $order_id,
            'stocks'   => $newstocks,
        ]);

    } catch (Throwable $e){
        wp_send_json_error(['err'=>'خطا در ایجاد سفارش: '.$e->getMessage()]);
    }
}

/*======================================
=  Shortcode: [qof_orders code="910"]
=  لیست سفارش‌های فروشنده + نمایش جزییات با کلیک روی شماره سفارش (AJAX)
======================================*/
add_shortcode('qof_orders', function($atts){
    if ( ! function_exists('wc_get_orders') ) {
        return '<div dir="rtl" style="color:#b91c1c">WooCommerce فعال نیست.</div>';
    }

    $atts = shortcode_atts([
        'code'     => '',
        'per_page' => '100',
        'status'   => '',
    ], $atts, 'qof_orders');

    $url_code = '';
    if (isset($_GET['code']))   $url_code = sanitize_text_field(wp_unslash($_GET['code']));
    if (isset($_GET['key']))    $url_code = sanitize_text_field(wp_unslash($_GET['key']));
    if (isset($_GET['seller'])) $url_code = sanitize_text_field(wp_unslash($_GET['seller']));
    $seller_code = $url_code !== '' ? $url_code : (string)$atts['code'];
    $seller_code = trim($seller_code);

    if ($seller_code === '') {
        return '<div dir="rtl" style="color:#b91c1c">کد فروشنده مشخص نشده است. از پارامتر <code>code</code> استفاده کنید.</div>';
    }

    $statuses = [];
    if (!empty($atts['status'])) {
        $statuses = array_filter(array_map('trim', explode(',', (string)$atts['status'])));
    } else {
        $statuses = ['pending','processing','on-hold','completed','cancelled','failed','refunded'];
    }

    $per_page = max(1, intval($atts['per_page']));

    $args = [
        'type'       => 'shop_order',
        'status'     => $statuses,
        'limit'      => $per_page,
        'orderby'    => 'date',
        'order'      => 'DESC',
        'return'     => 'objects',
        'meta_query' => [
            [
                'key'   => '_wc_qof_user_code',
                'value' => $seller_code,
            ]
        ],
    ];
    $orders = wc_get_orders($args);

    $nonce = wp_create_nonce('qof_orders_nonce');

    ob_start(); ?>
    <style>
      .qof-orders-wrap{direction:rtl}
      .qof-table{width:100%;border-collapse:collapse;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden}
      .qof-table th,.qof-table td{padding:10px;border-top:1px solid #e5e7eb;vertical-align:top}
      .qof-table thead th{background:#f3f4f6;border-top:none;text-align:right}
      .qof-order-link{color:#2563eb;cursor:pointer;text-decoration:underline}
      .qof-details{background:#f9fafb;padding:8px 12px;border:1px dashed #d1d5db;border-radius:8px;margin-top:6px;display:none}
      .qof-badge{display:inline-block;font-size:12px;padding:3px 8px;border:1px solid #d1d5db;border-radius:999px;background:#fff}
      .qof-muted{opacity:.7}
      @media (max-width: 768px){
        .qof-table th,.qof-table td{font-size:13px}
        .qof-details{font-size:13px}
      }
    </style>

    <div class="qof-orders-wrap" data-nonce="<?php echo esc_attr($nonce); ?>" data-seller="<?php echo esc_attr($seller_code); ?>">
      <div style="margin:8px 0 12px">
        <span class="qof-badge">کد فروشنده: <strong><?php echo esc_html($seller_code); ?></strong></span>
        <span class="qof-muted" style="margin-inline-start:10px">نمایش عمومی سفارش‌های ثبت‌شده با همین کد</span>
      </div>

      <?php if (empty($orders)) : ?>
        <div style="color:#374151;background:#f3f4f6;border:1px solid #e5e7eb;padding:10px;border-radius:8px">هیچ سفارشی یافت نشد.</div>
      <?php else: ?>
      <div class="qof-table-wrap" style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden">
        <table class="qof-table">
          <thead>
            <tr>
              <th style="width:120px">شماره سفارش</th>
              <th style="width:120px">وضعیت</th>
              <th style="width:160px">تاریخ</th>
              <th style="width:180px">نام مشتری</th>
              <th style="width:140px">موبایل</th>
              <th>آدرس</th>
              <th style="width:140px">جمع کل</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($orders as $order):
              $oid   = $order->get_id();
              $status= wc_get_order_status_name( $order->get_status() );
              $date  = $order->get_date_created() ? $order->get_date_created()->date_i18n('Y/m/d H:i') : '';
              $bn    = trim(($order->get_billing_first_name().' '.$order->get_billing_last_name()));
              $phone = $order->get_billing_phone();
              $addr1 = $order->get_billing_address_1();
              $total = $order->get_formatted_order_total();
          ?>
            <tr>
              <td>
                <a class="qof-order-link" data-oid="<?php echo esc_attr($oid); ?>" href="javascript:void(0)">#<?php echo esc_html($oid); ?></a>
                <div id="qof-details-<?php echo esc_attr($oid); ?>" class="qof-details"></div>
              </td>
              <td><?php echo esc_html($status); ?></td>
              <td><?php echo esc_html($date); ?></td>
              <td><?php echo esc_html($bn ?: '—'); ?></td>
              <td><?php echo esc_html($phone ?: '—'); ?></td>
              <td><?php echo esc_html($addr1 ?: '—'); ?></td>
              <td><?php echo wp_kses_post($total); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <script>
    (function(){
      const wrap = document.querySelector('.qof-orders-wrap');
      if(!wrap) return;
      const nonce = wrap.getAttribute('data-nonce');
      const seller = wrap.getAttribute('data-seller');

      function toggleDetails(oid, html){
        const box = document.getElementById('qof-details-'+oid);
        if(!box) return;
        if (html !== null){ box.innerHTML = html; box.style.display = 'block'; return; }
        box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
      }

      function loadingTpl(){ return '<div style="padding:6px 2px">در حال دریافت جزییات...</div>'; }

      function detailsTpl(data){
        if(!data || !data.items) return '<div style="color:#991b1b">خطا در دریافت اطلاعات.</div>';
        let html = '';
        html += '<div style="margin:4px 0 8px; font-weight:600">اقلام سفارش</div>';
        html += '<div style="overflow:auto"><table style="width:100%;border-collapse:collapse">';
        html += '<thead><tr><th style="text-align:right;border-bottom:1px solid #e5e7eb;padding:6px">محصول</th><th style="text-align:center;border-bottom:1px solid #e5e7eb;padding:6px;width:90px">تعداد</th><th style="text-align:center;border-bottom:1px solid #e5e7eb;padding:6px;width:120px">جمع جزء</th><th style="text-align:center;border-bottom:1px solid #e5e7eb;padding:6px;width:120px">مبلغ خط</th></tr></thead><tbody>';
        data.items.forEach(function(it){ html += '<tr><td style="padding:6px;border-bottom:1px solid #f3f4f6">'+(it.name||'')+'</td><td style="padding:6px;text-align:center;border-bottom:1px solid #f3f4f6">'+(it.qty||0)+'</td><td style="padding:6px;text-align:center;border-bottom:1px solid #f3f4f6">'+(it.subtotal||'')+'</td><td style="padding:6px;text-align:center;border-bottom:1px solid #f3f4f6">'+(it.total||'')+'</td></tr>'; });
        html += '</tbody></table></div>';

        if (data.totals_html) {
          html += '<div style="margin-top:8px"><span style="font-weight:600">جمع کل:</span> '+data.totals_html+'</div>';
        }
        return html;
      }

      wrap.addEventListener('click', function(e){
        const a = e.target.closest('.qof-order-link');
        if(!a) return;
        const oid = a.getAttribute('data-oid');
        const box = document.getElementById('qof-details-'+oid);
        if(!box) return;
        if (box.getAttribute('data-loaded') === '1'){ 
            toggleDetails(oid, null); 
            return; 
        }
        box.innerHTML = loadingTpl();
        box.style.display = 'block';

        const form = new FormData();
        form.append('action', 'qof_get_order_details');
        form.append('nonce',  nonce);
        form.append('seller', seller);
        form.append('order_id', oid);

        fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
          method: 'POST',
          credentials: 'same-origin',
          body: form
        })
        .then(r => r.json())
        .then(res => {
          if(res && res.success){
            box.innerHTML = detailsTpl(res.data || {});
            box.setAttribute('data-loaded','1');
          } else {
            const msg = (res && res.data && res.data.err) ? res.data.err : 'خطا در دریافت اطلاعات.';
            box.innerHTML = '<div style="color:#991b1b">'+msg+'</div>';
          }
        })
        .catch(() => {
          box.innerHTML = '<div style="color:#991b1b">عدم ارتباط با سرور.</div>';
        });
      });
    })();
    </script>
    <?php
    return ob_get_clean();
});

/*--------------------------------------
| AJAX: جزییات سفارش (فقط اگر متای کد فروشنده مطابق باشد)
---------------------------------------*/
add_action('wp_ajax_qof_get_order_details', 'qof_get_order_details');
add_action('wp_ajax_nopriv_qof_get_order_details', 'qof_get_order_details');

function qof_get_order_details(){
    try{
        if ( empty($_POST['nonce']) || ! wp_verify_nonce( wp_unslash($_POST['nonce']), 'qof_orders_nonce') ) {
            wp_send_json_error(['err'=>'خطای امنیتی (Nonce).']);
        }
        $seller   = isset($_POST['seller'])   ? sanitize_text_field( wp_unslash($_POST['seller']) )   : '';
        $order_id = isset($_POST['order_id']) ? absint( $_POST['order_id'] ) : 0;

        if (!$seller || !$order_id) wp_send_json_error(['err'=>'درخواست نامعتبر.']);

        $order = wc_get_order($order_id);
        if ( ! $order ) wp_send_json_error(['err'=>'سفارش یافت نشد.']);

        $meta_code = (string) $order->get_meta('_wc_qof_user_code', true);
        if ($meta_code === '' || $meta_code !== $seller){
            wp_send_json_error(['err'=>'دسترسی به این سفارش مجاز نیست.']);
        }

        $items_data = [];
        foreach ($order->get_items('line_item') as $item) {
            $name = $item->get_name();
            $qty  = (int) $item->get_quantity();
            $subtotal = wc_price( (float) $item->get_subtotal(), ['currency' => $order->get_currency()] );
            $total    = wc_price( (float) $item->get_total(),    ['currency' => $order->get_currency()] );
            $items_data[] = [
                'name'     => $name,
                'qty'      => $qty,
                'subtotal' => $subtotal,
                'total'    => $total,
            ];
        }

        $resp = [
            'items'       => $items_data,
            'totals_html' => $order->get_formatted_order_total(),
        ];
        wp_send_json_success($resp);
    } catch (Throwable $e){
        wp_send_json_error(['err'=>'خطا: '.$e->getMessage()]);
    }
}
