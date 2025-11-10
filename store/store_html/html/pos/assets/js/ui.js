/**
 * ui.js — POS 核心 UI 渲染引擎 (V2.2 - Gating 修复版)
 *
 * - 修复：重建了被 cart.js 覆盖的 ui.js 文件。
 * - 实现 [RMS V2.2]：openCustomize 函数现在会检查产品的 allowed_ice_ids 和
 * allowed_sweetness_ids (来自 pos_data_loader.php)，
 * 并只渲染被允许的选项按钮。
 *
 * [GEMINI A1.jpg FIX 2.0 - JS]
 * 1. (问题 1) 修复 openCustomize，将产品名称添加到 .offcanvas-title
 * 2. (问题 2) 修复 Gating 渲染逻辑，确保 *第一个* 可见选项被 'checked'，
 * 3. (问题 3) 修复 updateCustomizePrice，使其更新正确的 #custom_item_price ID
 * 4. 修复所有选择器以匹配 index.php 中新修复的 ID。
 *
 * [GEMINI 购物车参数修复 V3.0]
 * 1. (问题 4) 修复 refreshCartUI，将冰量/糖度/加料的 ID/Code 映射回 STATE 中的 name_zh/name_es/label_zh/label_es。
 */

import { STATE } from './state.js';
import { t, fmtEUR, toast } from './utils.js';

const lang = () => STATE.lang || 'zh';

/**
 * [RMS V2.2] 核心实现：打开定制面板
 * (Gating 逻辑已注入)
 */
export function openCustomize(productId) {
    const product = STATE.products.find(p => p.id === productId);
    if (!product) {
        console.error("Product not found:", productId);
        return;
    }

    // [估清 需求1] 检查商品是否已估清
    if (product.is_sold_out) {
        toast('该商品已估清'); // 未来可以替换为 t()
        return;
    }

    const customizeOffcanvas = new bootstrap.Offcanvas('#customizeOffcanvas');
    const $canvas = $('#customizeOffcanvas');

    // 1. 绑定产品数据
    $canvas.data('product', product);
    // [GEMINI A1.jpg FIX 1] 将产品名称添加到标题栏
    $canvas.find('.offcanvas-title').text(`${t('customizing')}: ${lang() === 'es' ? product.title_es : product.title_zh}`);


    // 2. 渲染规格 (Variants)
    // [GEMINI A1.jpg FIX 4] 目标 ID 修正为 #variant_selector_list
    const $variantContainer = $canvas.find('#variant_selector_list').empty();
    if (!product.variants || product.variants.length === 0) {
        $variantContainer.html(`<div class="alert alert-danger">${t('choose_variant')}</div>`);
        return;
    }
    
    let defaultVariant = product.variants.find(v => v.is_default) || product.variants[0];
    product.variants.forEach(variant => {
        const variantHtml = `
            <input type="radio" class="btn-check" name="variant_selector" id="variant_${variant.id}" value="${variant.id}" ${variant.id === defaultVariant.id ? 'checked' : ''}>
            <label class="btn btn-pill" for="variant_${variant.id}">
                ${lang() === 'es' ? variant.name_es : variant.name_zh}
            </label>
        `;
        $variantContainer.append(variantHtml);
    });

    // 3. [RMS V2.2 GATING] 渲染冰量选项 (Ice)
    // [GEMINI A1.jpg FIX 4] 目标 ID 修正为 #ice_selector_list
    const $iceContainer = $canvas.find('#ice_selector_list').empty();
    const iceMasterList = STATE.iceOptions || [];
    const allowedIceIds = product.allowed_ice_ids; // null | number[]
    let visibleIceOptions = 0;

    // 遍历“主列表”
    iceMasterList.forEach((iceOpt) => {
        // Gating 检查:
        // 1. 如果 allowedIceIds 为 null (未设置规则)，则全部显示。
        // 2. 如果 allowedIceIds 是数组，则检查 id 是否在数组中。
        const isAllowed = (allowedIceIds === null) || (Array.isArray(allowedIceIds) && allowedIceIds.includes(iceOpt.id));
        
        if (isAllowed) {
            // [GEMINI A1.jpg FIX 2] 确保第一个可见选项被选中
            const isChecked = (visibleIceOptions === 0);
            visibleIceOptions++;
            const iceHtml = `
                <input type="radio" class="btn-check" name="ice" id="ice_${iceOpt.ice_code}" value="${iceOpt.ice_code}" ${isChecked ? 'checked' : ''}>
                <label class="btn btn-pill" for="ice_${iceOpt.ice_code}">
                    ${lang() === 'es' ? iceOpt.name_es : iceOpt.name_zh}
                </label>
            `;
            $iceContainer.append(iceHtml);
        }
    });
    // 如果 Gating 导致没有选项，则隐藏该部分
    $iceContainer.closest('.mb-4').toggle(visibleIceOptions > 0); // (使用 .mb-4 定位父元素)


    // 4. [RMS V2.2 GATING] 渲染糖度选项 (Sugar)
    // [GEMINI A1.jpg FIX 4] 目标 ID 修正为 #sugar_selector_list
    const $sugarContainer = $canvas.find('#sugar_selector_list').empty();
    const sugarMasterList = STATE.sweetnessOptions || [];
    const allowedSweetnessIds = product.allowed_sweetness_ids; // null | number[]
    let visibleSugarOptions = 0;

    // 遍历“主列表”
    sugarMasterList.forEach((sugarOpt) => {
        // Gating 检查:
        const isAllowed = (allowedSweetnessIds === null) || (Array.isArray(allowedSweetnessIds) && allowedSweetnessIds.includes(sugarOpt.id));

        if (isAllowed) {
            // [GEMINI A1.jpg FIX 2] 确保第一个可见选项被选中
            const isChecked = (visibleSugarOptions === 0);
            visibleSugarOptions++;
            const sugarHtml = `
                <input type="radio" class="btn-check" name="sugar" id="sugar_${sugarOpt.sweetness_code}" value="${sugarOpt.sweetness_code}" ${isChecked ? 'checked' : ''}>
                <label class="btn btn-pill" for="sugar_${sugarOpt.sweetness_code}">
                    ${lang() === 'es' ? sugarOpt.name_es : sugarOpt.name_zh}
                </label>
            `;
            $sugarContainer.append(sugarHtml);
        }
    });
    // 如果 Gating 导致没有选项，则隐藏该部分
    $sugarContainer.closest('.mb-4').toggle(visibleSugarOptions > 0); // (使用 .mb-4 定位父元素)


    // 5. 渲染加料 (Addons) - (Addons 不参与 Gating)
    renderAddons();
    
    // 6. 清空备注并更新价格
    $('#remark_input').val('');
    updateCustomizePrice(); // [GEMINI A1.jpg FIX 2] 此调用现在会基于默认选中的 Gating 选项正确计算价格
    customizeOffcanvas.show();
}

/**
 * 渲染加料区 (在 openCustomize 时调用)
 */
export function renderAddons() {
    const $addonContainer = $('#addon_list').empty();
    if (!STATE.addons || STATE.addons.length === 0) {
        $addonContainer.html(`<p class="text-muted small">${t('no_addons_available')}</p>`);
        return;
    }
    STATE.addons.forEach(addon => {
        const addonHtml = `
            <div class="col-4 g-2">
                <div class="addon-chip" data-key="${addon.key}" data-price="${addon.price_eur}">
                    ${lang() === 'es' ? addon.label_es : addon.label_zh}
                    <small class="d-block text-muted">+${fmtEUR(addon.price_eur)}</small>
                </div>
            </div>
        `;
        $addonContainer.append(addonHtml);
    });
}

/**
 * 更新定制面板中的“当前价格”
 */
export function updateCustomizePrice() {
    const $canvas = $('#customizeOffcanvas');
    const product = $canvas.data('product');
    if (!product) return;

    const selectedVariantId = parseInt($('input[name="variant_selector"]:checked').val());
    const variant = product.variants.find(v => v.id === selectedVariantId);
    
    // [GEMINI A1.jpg FIX 2] 增加日志以防万一
    if (!variant) {
        // [GEMINI A1.jpg FIX 2] 这是控制台错误来源
        console.error("updateCustomizePrice: 未找到选中的 variant (ID: " + selectedVariantId + ")。价格将为0。");
        // [GEMINI A1.jpg FIX 3] 目标 ID 修正为 #custom_item_price
        $canvas.find('#custom_item_price').text(fmtEUR(0));
        return;
    }

    let currentPrice = parseFloat(variant.price_eur);
    
    $('#addon_list .addon-chip.active').each(function () {
        currentPrice += parseFloat($(this).data('price')) || 0;
    });

    // [GEMINI A1.jpg FIX 3] 目标 ID 修正为 #custom_item_price
    $canvas.find('#custom_item_price').text(fmtEUR(currentPrice));
}

/**
 * 渲染分类列表
 */
export function renderCategories() {
    const $container = $('#category_scroller');
    if (!$container.length) return;
    
    $container.empty();
    STATE.categories.forEach(cat => {
        $container.append(`
            <li class="nav-item">
                <a class="nav-link ${cat.key === STATE.active_category_key ? 'active' : ''}" href="#" data-cat="${cat.key}">
                    ${lang() === 'es' ? cat.label_es : cat.label_zh}
                </a>
            </li>
        `);
    });
}

/**
 * 渲染产品网格
 */
export function renderProducts() {
    const $grid = $('#product_grid');
    if (!$grid.length) return;
    
    $grid.empty();
    
    const searchText = $('#search_input').val().toLowerCase();
    
    const filteredProducts = STATE.products.filter(p => {
        const inCategory = p.category_key === STATE.active_category_key;
        if (!inCategory) return false;
        
        if (searchText) {
            return p.title_zh.toLowerCase().includes(searchText) || 
                   p.title_es.toLowerCase().includes(searchText);
            // 可以在这里添加 SKU 或拼音简称的搜索
        }
        return true;
    });

    if (filteredProducts.length === 0) {
        $grid.html(`<div class="col-12"><div class="alert alert-sheet">${t('no_products_in_category')}</div></div>`);
        return;
    }

    filteredProducts.forEach(p => {
        const defaultVariant = p.variants.find(v => v.is_default) || p.variants[0];
        
        // [估清 需求1] 检查 is_sold_out 状态
        const isSoldOut = (p.is_sold_out === 1 || p.is_sold_out === true);
        const soldOutClass = isSoldOut ? 'product-card-sold-out' : '';
        const soldOutBadge = isSoldOut ? '<span class="badge bg-danger position-absolute top-0 start-0 m-2">估清</span>' : '';
        // [估清 需求1] 添加 disabled 属性
        const disabledAttr = isSoldOut ? 'disabled' : '';

        $grid.append(`
            <div class="col">
                <div class="product-card ${soldOutClass}" data-id="${p.id}" ${disabledAttr}>
                    ${soldOutBadge}
                    <div class="product-title mb-1">${lang() === 'es' ? p.title_es : p.title_zh}</div>
                    <div class="product-price text-brand">${fmtEUR(defaultVariant.price_eur)}</div>
                </div>
            </div>
        `);
    });
}

/**
 * 刷新购物车UI
 */
export function refreshCartUI() {
    const $cartItems = $('#cart_items').empty();
    const $cartFooter = $('#cart_footer');
    
    if (STATE.cart.length === 0) {
        $cartItems.html(`<div class="alert alert-sheet">${t('tip_empty_cart')}</div>`);
        $cartFooter.hide();
        // [修复问题3] ID 从 #cart_badge 修正为 #cart_count
        $('#cart_count').text('0');
        return;
    }

    // --- START: 购物车参数修复 (V3.0) ---
    const currentLang = lang();
    const nameKey = currentLang === 'es' ? 'name_es' : 'name_zh';
    const addonLabelKey = currentLang === 'es' ? 'label_es' : 'label_zh';
    // --- END: 购物车参数修复 ---

    STATE.cart.forEach(item => {

        // --- START: 购物车参数修复 (V3.0) ---

        // 1. 查找冰量文本
        // (使用 == 进行松散比较，因为 item.ice 是字符串 "1"，而 ice_code 是数字 1)
        const iceOption = STATE.iceOptions.find(opt => opt.ice_code == item.ice);
        const iceText = iceOption ? iceOption[nameKey] : (item.ice || 'N/A');

        // 2. 查找糖度文本
        const sugarOption = STATE.sweetnessOptions.find(opt => opt.sweetness_code == item.sugar);
        const sugarText = sugarOption ? sugarOption[nameKey] : (item.sugar || 'N/A');
        
        // 3. 查找加料文本 (额外修复)
        const addonsText = (item.addons && item.addons.length > 0)
            ? item.addons.map(addonKey => {
                const addon = STATE.addons.find(a => a.key === addonKey); // 'key' 是 'addon_code'
                if (!addon) return addonKey; // 找不到则回退显示 Key
                return addon[addonLabelKey]; // 使用 label_zh 或 label_es
              }).join(', ')
            : 'N/A';
        
        // --- END: 购物车参数修复 ---

        $cartItems.append(`
            <div class="list-group-item">
                <div class="d-flex w-100">
                    <div>
                        <h6 class="mb-1">${item.title} (${item.variant_name})</h6>
                        <small class="text-muted">
                            ${t('ice')}: ${iceText} | ${t('sugar')}: ${sugarText} | 
                            ${t('addons')}: ${addonsText}
                        </small>
                        ${item.remark ? `<br><small class="text-info">${t('remark')}: ${item.remark}</small>` : ''}
                    </div>
                    <div class="ms-auto text-end">
                        <div class="fw-bold">${fmtEUR(item.unit_price_eur * item.qty)}</div>
                        <div class="qty-stepper mt-1">
                            <button class="btn btn-sm btn-outline-secondary" data-act="del" data-id="${item.id}"><i class="bi bi-trash"></i></button>
                            <button class="btn btn-sm btn-outline-secondary" data-act="dec" data-id="${item.id}"><i class="bi bi-dash"></i></button>
                            <span class="px-1">${item.qty}</span>
                            <button class="btn btn-sm btn-outline-secondary" data-act="inc" data-id="${item.id}"><i class="bi bi-plus"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        `);
    });

    const { subtotal, discount_amount, final_total } = STATE.calculatedCart;
    $('#cart_subtotal').text(fmtEUR(subtotal));
    $('#cart_discount').text(`-${fmtEUR(discount_amount)}`);
    $('#cart_final_total').text(fmtEUR(final_total));
    
    $cartFooter.show();
    // [修复问题3] ID 从 #cart_badge 修正为 #cart_count
    $('#cart_count').text(STATE.cart.length);
}

/**
 * 更新会员UI
 */
export function updateMemberUI() {
    const $container = $('#member_section');
    if (STATE.activeMember) {
        $container.find('#member_info').show();
        $container.find('#member_search').hide();
        $container.find('#member_name').text(STATE.activeMember.first_name || STATE.activeMember.phone_number);
        $container.find('#member_points').text(STATE.activeMember.points_balance || 0);
        $container.find('#member_level').text(STATE.lang === 'es' ? (STATE.activeMember.level_name_es || 'N/A') : (STATE.activeMember.level_name_zh || 'N/A'));
        $('#points_to_redeem_input').prop('disabled', false);
        $('#apply_points_btn').prop('disabled', false);
    } else {
        $container.find('#member_info').hide();
        $container.find('#member_search').show();
        $('#member_search_phone').val('');
        $('#points_to_redeem_input').val('').prop('disabled', true);
        $('#apply_points_btn').prop('disabled', true);
        $('#points_feedback').text('');
    }
    // 渲染积分兑换规则
    renderRedemptionRules();
}

/**
 * 渲染积分兑换规则
 */
function renderRedemptionRules() {
    const $container = $('#available_rewards_list').empty();
    if (!STATE.activeMember || !STATE.redemptionRules || STATE.redemptionRules.length === 0) {
        $container.html(`<small class="text-muted">${t('no_available_rewards')}</small>`);
        return;
    }

    const memberPoints = parseFloat(STATE.activeMember.points_balance || 0);
    let visibleRules = 0;

    STATE.redemptionRules.forEach(rule => {
        const pointsRequired = parseFloat(rule.points_required);
        const canAfford = memberPoints >= pointsRequired;
        const rewardText = (lang() === 'es' ? rule.rule_name_es : rule.rule_name_zh);
        
        // 检查此规则是否已被应用 (TODO: 将来需要更复杂的检查)
        const isApplied = (STATE.activeRedemptionRuleId === rule.id);

        const ruleHtml = `
            <div class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                    <span class="fw-bold">${rewardText}</span>
                    <small class="d-block text-muted">${t('requires_points', { points: pointsRequired })}</small>
                </div>
                <button class="btn btn-sm ${isApplied ? 'btn-success' : 'btn-outline-primary'} btn-redeem-reward" 
                        data-rule-id="${rule.id}" 
                        ${!canAfford && !isApplied ? 'disabled' : ''}>
                    ${isApplied ? t('redemption_applied') : (canAfford ? t('points_redeem_button') : t('points_insufficient'))}
                </button>
            </div>
        `;
        $container.append(ruleHtml);
        visibleRules++;
    });

    if (visibleRules === 0) {
         $container.html(`<small class="text-muted">${t('no_available_rewards')}</small>`);
    }
}


/**
 * 应用国际化 (I18N)
 */
export function applyI18N() {
    $('[data-i18n]').each(function () {
        const key = $(this).data('i18n');
        $(this).text(t(key));
    });
    $('[data-i18n-placeholder]').each(function () {
        const key = $(this).data('i1im-placeholder');
        $(this).attr('placeholder', t(key));
    });

    // [修复问题1的I18N] 动态翻译新增的模态框
    // (因为它们是静态HTML，所以此函数会捕获它们)
    document.querySelectorAll('[data-i18n-key]').forEach(el => {
        const key = el.getAttribute('data-i18n-key');
        const translation = t(key);
        if (translation && translation !== key) {
            // 特殊处理带 <strong> 的 P1
            if (key === 'availability_decision_p1') {
                const count = document.getElementById('sold_out_snapshot_count')?.textContent || '0';
                el.innerHTML = translation.replace('<strong id="sold_out_snapshot_count">0</strong>', `<strong id="sold_out_snapshot_count">${count}</strong>`);
            } else {
                el.textContent = translation;
            }
        }
    });
}