/* TopTea · KDS — SOP 兜底绑定器（与主脚本同风格渲染）
 * [V1.0.002 修复] 调整 card，将数量和单位合并到 kds-measurement 容器中，实现在同一行显示。
 * [V1.0.003 修复] 调整卡片布局为 col-4 (一行3个)，并缩小图片和标题的内联样式。
 * [V1.0.004] 添加扫码功能 (startScan)
 * [V1.0.005] 修复 onScanSuccess，去除扫码结果中多余的引号
 *
 * [GEMINI KDS Image URL FIX]:
 * 1. card 增加 imgUrl 参数，并渲染 <img> 标签。
 *
 * [GEMINI SECURE IMAGE PROXY FIX (V1.1)]:
 * 1. imgTag src now points to 'api/get_image.php' proxy script.
 */

/**
 * [V4.1] 扫码成功的回调 (必须是全局函数)
 * @param {string} code 扫描到的二维码内容
 */
window.onScanSuccess = function(code) {
    if (code) {
        // START OF FIX (V4.1)
        // 净化扫码结果，去除前后可能存在的引号
        let sanitizedCode = String(code).trim();
        if (sanitizedCode.startsWith('"') && sanitizedCode.endsWith('"')) {
            // 如果字符串以 " 开头并以 " 结尾，则去除它们
            sanitizedCode = sanitizedCode.substring(1, sanitizedCode.length - 1);
        }
        // END OF FIX (V4.1)
        
        const $input = $("#sku-input, #kds_code_input").first();
        const $form = $("#sku-search-form");
        if ($input.length && $form.length) {
            $input.val(sanitizedCode); // <-- 使用净化后的 code
            $form.trigger('submit'); // 触发submit事件
        }
    }
};

/**
 * [V4] 扫码失败或取消的回调 (必须是全局函数)
 * @param {string} message 错误信息
 */
window.onScanError = function(message) {
    // 此时 showKdsAlert 可能还未加载，使用原生 alert 作为后备
    if (typeof showKdsAlert === 'function') {
        showKdsAlert("扫码失败: " + message, true);
    } else {
        alert("扫码失败: " + message);
    }
};


(function () {
  if (window.__KDS_SOP_FALLBACK__) return;
  window.__KDS_SOP_FALLBACK__ = true;

  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
  function lg(){const l=(document.documentElement.getAttribute('lang')||'').toLowerCase();return l.startsWith('es')?'es':'zh';}
  function pick(zh,es){return lg()==='es'?(es||zh):(zh||es);}

  const $input = $("#sku-input, #kds_code_input").first();
  const $form  = $("#sku-search-form");

  const $tip   = $("#kds-step-tip, .sop-tip, [data-role='sop-tip']").first()
                 .text(lg()==='es'?'Haz bien cada paso: mejoran la textura y la calidad.':'每步动作做到位，口感品质才会好。');

  const $codeTargets = $("#kds_code_big, #kds_product_code, .kds-sku-big, [data-role='product-code']");
  const $nameTargets = $("#kds_product_title, #kds_product_name, .kds-name-big, [data-role='product-name']");

  const $line1 = $("#kds_line1, .kds-line1, #kds_overview_line1").first();
  const $line2 = $("#kds_line2, .kds-line2, #kds_overview_line2").first();

  const $wrapBase = $("#cards-base"), $wrapMix = $("#cards-mixing"), $wrapTop = $("#cards-topping");
  const $waiting = $("#cards-waiting");

  const $tabBase = $("#tab-base, .kds-step-tab[data-step='base']").first();
  const $tabMix  = $("#tab-mixing, .kds-step-tab[data-step='mixing']").first();
  const $tabTop  = $("#tab-topping, .kds-step-tab[data-step='topping']").first();

  function normalizeCat(cat){
    const s=String(cat||'').toLowerCase();
    if(s.startsWith('mix')||s.includes('调')) return 'mixing';
    if(s.startsWith('top')||s.includes('顶')) return 'topping';
    return 'base';
  }
  function card(i,n,q,u, imgUrl){
    // [V3 修复]
    // 1. 栅格: col-xxl-6 col-xl-6 ... -> col-4 (强制3列)
    // 2. [圆角修复] 移除了 kds-card-thumb 上的内联 style
    // 3. 标题: font-size:1.6rem -> font-size:1.25rem
    // 4. 数量/单位字体大小在 kds_style.css 中修改
    
    // [GEMINI SECURE IMAGE PROXY FIX (V1.1)]
    // 检查 imgUrl 是否存在且不为空，如果存在，则构建指向 PHP 代理的 src
    const imgTag = (imgUrl && imgUrl.trim() !== '')
        ? `<img src="api/get_image.php?file=${esc(imgUrl)}" alt="${esc(n)}">` 
        : ''; // 如果 imgUrl 为空或 null，则不生成 <img> 标签

    return `
    <div class="col-4">
      <div class="kds-ingredient-card">
        <div class="step-number" style="position:absolute;left:16px;top:16px;background:#16a34a;color:#fff;width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-weight:900;">${i}</div>
        <div class="kds-card-thumb">${imgTag}</div>
        <div class="text-center" style="font-size:1.25rem;font-weight:900;letter-spacing:.6px;">${esc(n)}</div>
        <div class="kds-measurement text-center">
          <span class="kds-quantity">${esc(q)}</span>
          <span class="kds-unit-measure">${esc(u)}</span>
        </div>
      </div>
    </div>`;
  }
  function showTab(step){
    $(".kds-step-tab").removeClass("active");
    $(`.kds-step-tab[data-step='${step}']`).addClass("active");
    $wrapBase.addClass("d-none"); $wrapMix.addClass("d-none"); $wrapTop.addClass("d-none");
    if(step==='base') $wrapBase.removeClass("d-none");
    if(step==='mixing') $wrapMix.removeClass("d-none");
    if(step==='topping') $wrapTop.removeClass("d-none");
  }
  function bindTabs(){
    if($tabBase.length&&!$tabBase.data('step')) $tabBase.attr('data-step','base').addClass('kds-step-tab');
    if($tabMix.length &&!$tabMix.data('step'))  $tabMix.attr('data-step','mixing').addClass('kds-step-tab');
    if($tabTop.length &&!$tabTop.data('step'))  $tabTop.attr('data-step','topping').addClass('kds-step-tab');
    $(document).off('click.kdsStep','.kds-step-tab').on('click.kdsStep','.kds-step-tab',function(e){
      e.preventDefault(); showTab($(this).data('step'));
    });
  }

  function render(data){
    const p=data.product||{}, arr=data.recipe||[];
    if($codeTargets.length && p.product_code) $codeTargets.text(p.product_code);
    if($nameTargets.length) $nameTargets.text(pick(p.name_zh,p.name_es)||pick(p.title_zh,p.title_es)||'');

    const status=pick(p.status_name_zh,p.status_name_es)||'';
    if($line1.length){ if(status){$line1.text(status).show();}else{$line1.hide();} }
    const ice=pick(p.ice_name_zh,p.ice_name_es)||'', swt=pick(p.sweetness_name_zh,p.sweetness_name_es)||'';
    const parts=[]; if(ice)parts.push(ice); if(swt)parts.push(swt);
    if($line2.length){ if(parts.length){$line2.text(parts.join(' / ')).show();}else{$line2.hide();} }

    $wrapBase.empty(); $wrapMix.empty(); $wrapTop.empty();
    const isEs = lg()==='es';
    const gp={base:[],mixing:[],topping:[]};
    arr.forEach(r=>gp[normalizeCat(r.step_category)].push(r));

    let i=1; gp.base.forEach(r=>{$wrapBase.append(card(i++, isEs?(r.material_es||r.material_zh||'--'):(r.material_zh||r.material_es||'--'), String(r.quantity??''), isEs?(r.unit_es||r.unit_zh||''):(r.unit_zh||r.unit_es||''), r.image_url));});
    i=1; gp.mixing.forEach(r=>{$wrapMix.append(card(i++, isEs?(r.material_es||r.material_zh||'--'):(r.material_zh||r.material_es||'--'), String(r.quantity??''), isEs?(r.unit_es||r.unit_zh||''):(r.unit_zh||r.unit_es||''), r.image_url));});
    i=1; gp.topping.forEach(r=>{$wrapTop.append(card(i++, isEs?(r.material_es||r.material_zh||'--'):(r.material_zh||r.material_es||'--'), String(r.quantity??''), isEs?(r.unit_es||r.unit_zh||''):(r.unit_zh||r.unit_es||''), r.image_url));});

    if(gp.base.length) showTab('base'); else if(gp.mixing.length) showTab('mixing'); else if(gp.topping.length) showTab('topping'); else showTab('base');
  }

  function fetchSOP(code){
    if(!code) return;
    if($waiting.length) $waiting.text(lg()==='es'?'Esperando consulta…':'等待查询…').show();
    $.getJSON('api/sop_handler.php', {code: code}).done(function(res){
      if(!res || res.status!=='success' || !res.data){ alert(lg()==='es'?'Error del servidor':'查询失败：服务器错误'); return; }
      if($waiting.length) $waiting.hide();
      render({product:res.data.product||{}, recipe:res.data.recipe||[]});
    }).fail(function(){ alert(lg()==='es'?'Error del servidor':'查询失败：服务器错误'); });
  }

  bindTabs();

  // [V10] 绑定扫码按钮
  $(document).on("click", "#btn-scan-qr", function() {
      if (window.AndroidBridge && typeof window.AndroidBridge.startScan === 'function') {
          try {
              // 调用安卓接口，并指定全局回调函数的名字
              window.AndroidBridge.startScan('onScanSuccess', 'onScanError');
          } catch (e) {
              if (typeof showKdsAlert === 'function') {
                  showKdsAlert("调用扫码功能失败: " + e.message, true);
              } else {
                  alert("调用扫码功能失败: " + e.message);
              }
          }
      } else {
          if (typeof showKdsAlert === 'function') {
              showKdsAlert("扫码功能不可用。请在 TopTea 安卓设备上使用。", true);
          } else {
              alert("扫码功能不可用。请在 TopTea 安卓设备上使用。");
          }
      }
  });

  if($form.length){
    $form.on('submit', function(e){
      e.preventDefault(); const code=($input.val()||'').trim(); if(code) fetchSOP(code);
    });
  }
  if($input.length){
    $input.on('keydown', function(e){
      if(e.key==='Enter'){ e.preventDefault(); const code=($input.val()||'').trim(); if(code) fetchSOP(code); }
    });
  }
  if($input.length && ($input.val()||'').trim()){
    fetchSOP(($input.val()||'').trim());
  }
})();