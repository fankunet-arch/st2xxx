/**
 * TopTea · KDS · SOP (修复版)
 * - 修复：在 fetchSop 的 error/fail 路径中，将 waiting 文本重置为初始状态。
 * - 修复：将 $.ajax.done/fail 中的 alert() 替换为 showKdsAlert(msg, true)。
 * - 修复：根据要求修改右上角提示语 (tip_waiting) 的内容。
 * - 修复：renderStaticUI 逻辑，正确替换 [data-i18n-key] 元素的文本，解决重复显示和未翻译问题。
 * - 修复：I18N 对象补全所有 sop_view.php 中使用的静态 key。
 * - 修复：语言逻辑统一使用 'kds_lang' ('zh-CN' / 'es-ES')
 * - 修复：语言切换事件选择器 (data-lang="zh-CN")
 * - 修复：默认语言确保为 'zh-CN'
 * - 语言切换（作用域化，杜绝误拦截导航；支持 ?lang=zh-CN / ?lang=es-ES、点击旗帜、快捷键 Alt+Z / Alt+E）
 * - 静态 UI 文案 i18n（不改模板：JS 动态套文案，placeholder / 按钮 / 分组标签 / 等待提示）
 * - 仅左侧精准隐藏旧“请输入编码/--/虚线”提示（不会再让整页变白）
 * - SOP 动态卡片渲染按三分组（底料/调杯/顶料）
 * - [V1.0.006 修复] 调整 renderCards 和 fetchSop 逻辑，以适应新的“等待查询”占位符。
 * - [V1.0.007 修复] 调整 renderLeft 函数，使其正确显示杯型 (cup_name)。
 * - [V1.0.008 修复] 调整 cardHTML，将数量和单位合并到 kds-measurement 容器中，实现在同一行显示。
 * - [V1.0.009 修复] 调整卡片布局为 col-4 (一行3个)，并缩小图片和标题的内联样式。
 * - [V1.0.010] 添加扫码功能 (startScan)
 * - [V1.0.011] 修复 onScanSuccess，去除扫码结果中多余的引号
 *
 * [GEMINI KDS Image URL FIX]:
 * 1. cardHTML 增加 imgUrl 参数，并渲染 <img> 标签。
 *
 * [GEMINI SECURE IMAGE PROXY FIX (V1.1)]:
 * 1. cardHTML src now points to 'api/get_image.php' proxy script.
 *
 * [GEMINI KDS GATEWAY (Phase 4.4)]:
 * 1. fetchSop() URL 切换到 kds_api_gateway.php
 */

/**
 * 扫码成功的回调 (必须是全局函数)
 * @param {string} code 扫描到的二维码内容 (可能包含引号)
 */
window.onScanSuccess = function(code) {
    if (code) {
        // START OF FIX (V10.1)
        // 净化扫码结果，去除前后可能存在的引号
        let sanitizedCode = String(code).trim();
        if (sanitizedCode.startsWith('"') && sanitizedCode.endsWith('"')) {
            // 如果字符串以 " 开头并以 " 结尾，则去除它们
            sanitizedCode = sanitizedCode.substring(1, sanitizedCode.length - 1);
        }
        // END OF FIX (V10.1)

        const $input = $("#sku-input, #kds_code_input").first();
        const $form = $("#sku-search-form");
        if ($input.length && $form.length) {
            $input.val(sanitizedCode); // <-- 使用净化后的 code
            $form.trigger('submit'); // 触发KDS已有的submit事件
        } else {
            console.error("onScanSuccess: 找不到输入框或表单。");
        }
    }
};

/**
 * [V10] 扫码失败或取消的回调 (必须是全局函数)
 * @param {string} message 错误信息
 */
window.onScanError = function(message) {
    if (typeof showKdsAlert === 'function') {
        showKdsAlert("扫码失败: " + message, true);
    } else {
        alert("扫码失败: " + message);
    }
};


$(function () {
  "use strict";

  /* ========================= I18N (修复：修改 tip_waiting, 增加 loading) ========================= */
  const I18N = {
    'zh-CN': {
      err: "查询失败：服务器错误",
      loading: "正在查询", // <--- 新增
      // 动态
      tip_waiting: "每步动作做到位，口感品质才会好。", // <--- 已按要求修改
      cards_waiting: "等待查询...",
      // 静态 UI (来自 sop_view.php)
      placeholder_sku: "输入产品编码...",
      info_enter_sku: "请先输入编码",
      btn_action_complete: "制茶完成",
      btn_action_report: "缺料申报",
      nav_prep: "物料制备",
      nav_expiry: "效期追踪",
      nav_guide: "制杯指引",
      btn_logout: "退出",
      step_base: "底料",
      step_mixing: "调杯",
      step_topping: "顶料",
    },
    'es-ES': {
      err: "Error del servidor",
      loading: "Consultando", // <--- 新增
      // 动态
      tip_waiting: "Haz bien cada paso: mejoran la textura y la calidad.", // <--- 已按要求修改
      cards_waiting: "Esperando consulta…",
      // 静态 UI (来自 sop_view.php)
      placeholder_sku: "Introducir código del producto...",
      info_enter_sku: "Introducir código",
      btn_action_complete: "Terminar",
      btn_action_report: "Informe de faltantes",
      nav_prep: "Preparación",
      nav_expiry: "Caducidad",
      nav_guide: "Guía SOP",
      btn_logout: "Salir",
      step_base: "Base",
      step_mixing: "Mezcla",
      step_topping: "Toppings",
    },
  };

  /* ========================= Lang helpers (修复：逻辑统一) ========================= */
  function qp(name) {
    const m = new RegExp("(^|&)" + name + "=([^&]*)(&|$)", "i").exec(
      window.location.search.slice(1)
    );
    return m ? decodeURIComponent(m[2]) : null;
  }

  // 初始化：URL 参数 → localStorage → html[lang]
  (function initLang() {
    const fromUrl = (qp("lang") || "").toLowerCase();
    if (fromUrl === "es-es" || fromUrl === "zh-cn") {
      localStorage.setItem("kds_lang", fromUrl); // 修复：使用 kds_lang
      document.documentElement.setAttribute("lang", fromUrl);
    } else {
      const saved = localStorage.getItem("kds_lang"); // 修复：使用 kds_lang
      if (saved === "es-ES" || saved === "zh-CN") {
        document.documentElement.setAttribute("lang", saved);
      } else {
        // 确保默认是 zh-CN
        document.documentElement.setAttribute("lang", "zh-CN");
        localStorage.setItem("kds_lang", "zh-CN");
      }
    }
  })();

  function getLang() {
    const htmlLang =
      (document.documentElement.getAttribute("lang") || "").toLowerCase();
    if (htmlLang.startsWith("es")) return "es-ES"; // 修复：返回 es-ES
    if (htmlLang.startsWith("zh")) return "zh-CN"; // 修复：返回 zh-CN
    const saved = localStorage.getItem("kds_lang"); // 修复：使用 kds_lang
    return saved === "es-ES" || saved === "zh-CN" ? saved : "zh-CN"; // 修复：默认 zh-CN
  }

  function t(key) {
    const lang = getLang();
    return (I18N[lang] || I18N['zh-CN'])[key] || key; // 修复：默认 zh-CN
  }

  function pick(zhVal, esVal) {
    return getLang() === "es-ES" ? esVal || zhVal : zhVal || esVal; // 修复：检查 es-ES
  }

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, (m) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#39;",
    })[m]);
  }

  function setLang(lang) {
    document.documentElement.setAttribute("lang", lang); // lang 已经是 zh-CN 或 es-ES
    localStorage.setItem("kds_lang", lang); // 修复：使用 kds_lang
    // 同步渲染静态 + 动态
    renderStaticUI();
    renderAll();
  }

  /* ========================= 语言切换（修复：选择器） =========================
   * 重点：不再用“全局捕获”去拦截任何点击，避免误伤导航/菜单。
   * 只对 “明确标注 data-lang 的元素” 进行事件委托；另外仅在 header 区域尝试自动标注旗帜。
   */

  // 1) 清理任何旧版捕获监听（如果存在）
  try {
    document.removeEventListener("click", window.__kdsLangCapture, true);
  } catch (_) {}

  // 2) 仅对 data-lang 元素做事件委托（全局冒泡，安全）
  $(document)
    .off("click.kds.lang", "[data-lang='zh-CN']") // 修复：选择器
    .on("click.kds.lang", "[data-lang='zh-CN']", function (e) { // 修复：选择器
      e.preventDefault();
      e.stopPropagation();
      setLang("zh-CN"); // 修复：值
    });

  $(document)
    .off("click.kds.lang.es", "[data-lang='es-ES']") // 修复：选择器
    .on("click.kds.lang.es", "[data-lang='es-ES']", function (e) { // 修复：选择器
      e.preventDefault();
      e.stopPropagation();
      setLang("es-ES"); // 修复：值
    });

  // 3) 仅在 header/导航区域内，自动给旗帜打 data-lang（避免误标一切元素）
  (function annotateHeaderFlags() {
    const header =
      document.querySelector("header, .topbar, .navbar, .header, .app-header, .kds-top-nav") || // 增加 .kds-top-nav
      document;
    const cands = header.querySelectorAll("img,svg,span,i,a,button,div");

    cands.forEach((el) => {
      const has = (el.getAttribute("data-lang") || "").toLowerCase();
      if (has === "zh-cn" || has === "es-es") return; // 修复：检查

      const label = (
        el.getAttribute("alt") ||
        el.getAttribute("title") ||
        el.textContent ||
        ""
      ).toLowerCase();
      const src = (el.getAttribute("src") || "").toLowerCase();
      let bg = "";
      try {
        bg = (getComputedStyle(el).backgroundImage || "").toLowerCase();
      } catch (_) {}

      const hay = `${label} ${src} ${bg} ${el.id || ""} ${
        el.className || ""
      }`.toLowerCase();

      // 命中非常明确的旗帜线索，才标记
      if (
        (/(flag|bandera)/.test(hay) &&
        (/(^|\W)(es|esp|spanish|espa)\b/.test(hay) || /\/es\b|spain/.test(hay)))
      ) {
        el.setAttribute("data-lang", "es-ES"); // 修复：值
      }
      if (
        (/(flag|bandera)/.test(hay) &&
        (/(^|\W)(zh|cn|china|中文|简体|汉)\b/.test(hay) || /\/cn\b|china/.test(hay)))
      ) {
        el.setAttribute("data-lang", "zh-CN"); // 修复：值
      }
    });
  })();

  // 4) 键盘快捷：Alt+Z 中文 / Alt+E 西语
  document.addEventListener(
    "keydown",
    function (e) {
      if (e.altKey && !e.ctrlKey && !e.shiftKey) {
        const k = (e.key || "").toLowerCase();
        if (k === "z") {
          e.preventDefault();
          setLang("zh-CN"); // 修复：值
        }
        if (k === "e") {
          e.preventDefault();
          setLang("es-ES"); // 修复：值
        }
      }
    },
    false
  );

  /* ========================= DOM refs ========================= */
  const $form = $("#sku-search-form");
  const $input = $("#sku-input, #kds_code_input").first();

  // $tip, $tabBase, $tabMix, $tabTop 在 renderStaticUI 中动态查找

  const $wrapBase = $("#cards-base");
  const $wrapMix = $("#cards-mixing");
  const $wrapTop = $("#cards-topping");
  const $allWraps = $wrapBase.add($wrapMix).add($wrapTop);
  // [V6 修复] 获取所有占位符
  const $allWaitingPlaceholders = $(".kds-waiting-placeholder");

  function leftHost() {
    return (
      $("#product-info-area, .kds-left, #left-panel, #kds_left").first()[0] ||
      $("#sku-search-form").closest(".col, .col-xxl-3, aside").first()[0] ||
      document.querySelector("aside") ||
      document.querySelector(".kds-left") ||
      document.body
    );
  }

  /* ========================= 仅左侧隐藏旧提示 ========================= */
  function removeLegacyHints() {
    const host = leftHost();
    if (!host) return;

    [
      "[data-i18n-key='info_enter_sku']", // 修复：现在这个 key 会被 renderStaticUI 处理，所以也要隐藏
      "[data-i18n='info_enter_sku']",
      ".kds-enter-code",
      ".kds-enter-code-wrapper",
      ".enter-code-hint",
      "#enter-code-hint",
      "#kds_enter_code_title",
    ].forEach((sel) =>
      host.querySelectorAll(sel).forEach((n) => {
        n.style.display = "none";
      })
    );

    Array.from(host.querySelectorAll("*")).forEach((el) => {
      if (el.children.length === 0) {
        const tx = (el.textContent || "").trim();
        if (tx === "--") el.style.display = "none";
        if (/^[-—\s]{3,}$/.test(tx)) el.style.display = "none";
        // "请先输入编码" 会被 i18n 替换，所以上面的选择器会隐藏它
      }
    });
  }

  /* ========================= 静态 UI 文案渲染 (修复) =========================
   * 查找所有带 [data-i18n-key] 的元素并替换其内容。
   */
  function renderStaticUI() {
    document.querySelectorAll("[data-i18n-key]").forEach((el) => {
      const key = el.getAttribute("data-i18n-key");
      const translation = t(key);
      if (translation && translation !== key) {
        if (
          el.tagName === "INPUT" ||
          el.tagName === "TEXTAREA"
        ) {
          if (key.includes("placeholder")) {
            el.setAttribute("placeholder", translation);
          } else {
            el.value = translation;
          }
        } else {
          el.textContent = translation;
        }
      }
    });

    // 激活旗帜状态
    const lang = getLang();
    document.querySelectorAll(".lang-flag, [data-lang]").forEach((el) => {
      if (el.getAttribute("data-lang") === lang) {
        el.classList.add("active");
      } else {
        el.classList.remove("active");
      }
    });
  }

  /* ========================= 状态 ========================= */
  let DATA = { product: {}, recipe: [] };

  /* ========================= 左侧（V7 修复：显示杯型） ========================= */
  function ensureLeft() {
    const host = leftHost();
    if (!host) return {};

    let code = document.querySelector(
      "#kds_code_big, .kds-sku-big, [data-role='product-code']"
    );
    if (!code) {
      code = document.createElement("div");
      code.id = "kds_code_big";
      code.className = "kds-cup-number"; // 使用新样式
      code.style.cssText = "margin: 0.25rem 0;"; // 微调
      host.insertBefore(code, host.firstChild);
    }

    let name = document.querySelector(
      "#kds_product_title, .kds-name-big, [data-role='product-name']"
    );
    if (!name) {
      name = document.createElement("h3");
      name.id = "kds_product_title";
      name.style.cssText = "font-weight:bold; margin-bottom: 1rem;";
      code.after(name);
    }
    
    // 确保容器存在
    let infoContainer = document.getElementById('kds-info-display-container');
    if (!infoContainer) {
        infoContainer = document.createElement("div");
        infoContainer.id = "kds-info-display-container";
        name.after(infoContainer);
    }

    let l1 = document.querySelector(
      "#kds_line1, .kds-line1, #kds_overview_line1"
    );
    if (!l1) {
      l1 = document.createElement("div");
      l1.id = "kds_line1";
      l1.className = "kds-info-display";
      infoContainer.appendChild(l1);
    }

    let l2 = document.querySelector(
      "#kds_line2, .kds-line2, #kds_overview_line2"
    );
    if (!l2) {
      l2 = document.createElement("div");
      l2.id = "kds_line2";
      l2.className = "kds-info-display";
      infoContainer.appendChild(l2);
    }

    return { code, name, l1, l2, infoContainer };
  }

  function renderLeft() {
    const nodes = ensureLeft();
    const p = DATA.product || {};

    if (nodes.code)
      nodes.code.textContent = p.product_code || p.product_no || "";
    if (nodes.name)
      nodes.name.textContent =
        pick(p.name_zh, p.name_es) ||
        pick(p.title_zh, p.title_es) ||
        "";
    
    // [V7 修复] L1 显示杯型
    const cupTxt = pick(p.cup_name_zh, p.cup_name_es) || "";
    if (nodes.l1) {
      nodes.l1.style.display = cupTxt ? "block" : "none";
      nodes.l1.textContent = cupTxt;
    }

    // [V7 修复] L2 显示 状态 / 冰 / 糖
    const statusTxt = pick(p.status_name_zh, p.status_name_es) || "";
    const ice = pick(p.ice_name_zh, p.ice_name_es) || "";
    const swt = pick(p.sweetness_name_zh, p.sweetness_name_es) || "";
    const parts = [];
    if (statusTxt) parts.push(statusTxt); // 状态 (例如: 冰沙)
    if (ice) parts.push(ice);       // 冰量 (例如: 少冰)
    if (swt) parts.push(swt);       // 甜度 (例如: 少糖)
    
    if (nodes.l2) {
      nodes.l2.style.display = parts.length ? "block" : "none";
      nodes.l2.textContent = parts.join(" / ");
    }
    
    // 如果两个都隐藏了，隐藏容器
    if (nodes.infoContainer) {
         nodes.infoContainer.style.display = (cupTxt || parts.length) ? "block" : "none";
    }

    removeLegacyHints();
  }

  /* ========================= 卡片渲染 (V9 修复) ========================= */
  const $tabWraps = {
    base: $wrapBase,
    mixing: $wrapMix,
    topping: $wrapTop,
  };

  function normalizeCat(cat) {
    const s = String(cat || "").toLowerCase();
    if (s.startsWith("mix") || s.includes("调")) return "mixing";
    if (s.startsWith("top") || s.includes("顶")) return "topping";
    return "base";
  }

  function cardHTML(i, name, qty, unit, imgUrl) {
    // [V3 修复]
    // 1. 栅格: col-xxl-6 col-xl-6 ... -> col-4 (强制3列)
    // 2. [圆角修复] 移除了 kds-card-thumb 上的内联 style
    // 3. 标题: font-size:1.6rem -> font-size:1.25rem
    // 4. 数量/单位字体大小在 kds_style.css 中修改
    
    // [GEMINI SECURE IMAGE PROXY FIX (V1.1)]
    // 检查 imgUrl 是否存在且不为空，如果存在，则构建指向 PHP 代理的 src
    const imgTag = (imgUrl && imgUrl.trim() !== '')
        ? `<img src="api/get_image.php?file=${esc(imgUrl)}" alt="${esc(name)}">` 
        : ''; // 如果 imgUrl 为空或 null，则不生成 <img> 标签
        
    return `
      <div class="col-4">
        <div class="kds-ingredient-card">
          <div class="step-number" style="position:absolute;left:16px;top:16px;background:#16a34a;color:#fff;width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-weight:900;">${i}</div>
          <div class="kds-card-thumb">${imgTag}</div>
          <div class="text-center" style="font-size:1.25rem;font-weight:900;letter-spacing:.6px;">${esc(
            name
          )}</div>
          <div class="kds-measurement text-center">
            <span class="kds-quantity">${esc(qty)}</span>
            <span class="kds-unit-measure">${esc(unit)}</span>
          </div>
        </div>
      </div>`;
  }

  // [V6 修复] 用于重置所有卡片区域到“等待查询”状态
  function resetCardContainers() {
    $allWraps.empty(); // 清空所有卡片
    $allWraps.each(function() {
      // 重新添加占位符
      $(this).html(
        `<div class="col-12 text-center text-muted pt-5 kds-waiting-placeholder">
           <h4 data-i18n-key="cards_waiting">${t("cards_waiting")}</h4>
         </div>`
      );
    });
    // 默认显示第一个 tab
    showTab("base");
  }

  // [V6 修复] showTab 逻辑简化
  function showTab(step) {
    $(".kds-step-tab").removeClass("active");
    $(`.kds-step-tab[data-step='${step}']`).addClass("active");
    $wrapBase.hide();
    $wrapMix.hide();
    $wrapTop.hide();
    // 确保step有效，否则回退到base
    const $targetWrap = $tabWraps[step] || $wrapBase;
    $targetWrap.show();
  }
  
  function renderCards() {
    // [V6 修复] 先清空所有内容，包括占位符
    $allWraps.empty(); 

    const gp = { base: [], mixing: [], topping: [] };
    (DATA.recipe || []).forEach((r) => gp[normalizeCat(r.step_category)].push(r));

    const isEs = getLang() === "es-ES"; // 修复：检查 es-ES

    let i = 1;
    gp.base.forEach((r) => {
      const name = isEs
        ? r.material_es || r.material_zh || "--"
        : r.material_zh || r.material_es || "--";
      const unit = isEs
        ? r.unit_es || r.unit_zh || ""
        : r.unit_zh || r.unit_es || "";
      const qty = r.quantity != null ? r.quantity : "";
      $wrapBase.append(cardHTML(i++, name, String(qty), unit, r.image_url)); // [KDS Image] Added r.image_url
    });

    i = 1;
    gp.mixing.forEach((r) => {
      const name = isEs
        ? r.material_es || r.material_zh || "--"
        : r.material_zh || r.material_es || "--";
      const unit = isEs
        ? r.unit_es || r.unit_zh || ""
        : r.unit_zh || r.unit_es || "";
      const qty = r.quantity != null ? r.quantity : "";
      $wrapMix.append(cardHTML(i++, name, String(qty), unit, r.image_url)); // [KDS Image] Added r.image_url
    });

    i = 1;
    gp.topping.forEach((r) => {
      const name = isEs
        ? r.material_es || r.material_zh || "--"
        : r.material_zh || r.material_es || "--";
      const unit = isEs
        ? r.unit_es || r.unit_zh || ""
        : r.unit_zh || r.unit_es || "";
      const qty = r.quantity != null ? r.quantity : "";
      $wrapTop.append(cardHTML(i++, name, String(qty), unit, r.image_url)); // [KDS Image] Added r.image_url
    });

    // [V6 修复] 如果某个分组没有内容，则显示“等待查询”（或“无内容”）
    if (gp.base.length === 0) {
      $wrapBase.html(`<div class="col-12 text-center text-muted pt-5 kds-waiting-placeholder"><h4 data-i18n-key="cards_waiting">${t("cards_waiting")}</h4></div>`);
    }
    if (gp.mixing.length === 0) {
      $wrapMix.html(`<div class="col-12 text-center text-muted pt-5 kds-waiting-placeholder"><h4 data-i18n-key="cards_waiting">${t("cards_waiting")}</h4></div>`);
    }
    if (gp.topping.length === 0) {
      $wrapTop.html(`<div class="col-12 text-center text-muted pt-5 kds-waiting-placeholder"><h4 data-i18n-key="cards_waiting">${t("cards_waiting")}</h4></div>`);
    }
    
    // 默认显示有内容的分组
    if (gp.base.length) showTab("base");
    else if (gp.mixing.length) showTab("mixing");
    else if (gp.topping.length) showTab("topping");
    else {
      // 如果所有都为空（例如P-Code查询），则全部显示“等待查询”并激活第一个 tab
      showTab("base");
    }
  }

  /* ========================= 标签可点 ========================= */
  function bindTabs() {
    // 动态查找 tabs
    const $tabBase = $("#tab-base, .kds-step-tab[data-step='base']").first();
    const $tabMix = $("#tab-mixing, .kds-step-tab[data-step='mixing']").first();
    const $tabTop = $("#tab-topping, .kds-step-tab[data-step='topping']").first();
    
    if ($tabBase.length && !$tabBase.data("step"))
      $tabBase.attr("data-step", "base").addClass("kds-step-tab");
    if ($tabMix.length && !$tabMix.data("step"))
      $tabMix.attr("data-step", "mixing").addClass("kds-step-tab");
    if ($tabTop.length && !$tabTop.data("step"))
      $tabTop.attr("data-step", "topping").addClass("kds-step-tab");

    $(document)
      .off("click.kdsStep", ".kds-step-tab")
      .on("click.kdsStep", ".kds-step-tab", function (e) {
        e.preventDefault();
        const step = $(this).data("step");
        // [V6 修复] 使用 showTab 函数来切换
        showTab(step);
      });
  }

  /* ========================= SOP Ajax (V6 修复) ========================= */
  function fetchSop(code) {
    if (!code) return;
    // [V6 修复] 设置所有占位符为 "loading"
    $allWaitingPlaceholders.find('[data-i18n-key]').text(t("loading") + " " + esc(code) + "...");
    $allWaitingPlaceholders.parent().show(); // 确保占位符可见
    $allWraps.hide(); // 隐藏卡片容器
    $tabWraps['base'].show(); // 默认显示第一个
    
    $.ajax({
      // --- [PHASE 4.4] MODIFICATION START ---
      url: "api/kds_api_gateway.php", // 切换到网关
      type: "GET",
      dataType: "json",
      data: { 
          res: 'sop',
          act: 'get',
          code: code 
      },
      // --- [PHASE 4.4] MODIFICATION END ---
    })
      .done(function (res) {
        if (!res || res.status !== "success" || !res.data) {
          const errorMsg = res.message || t("err");
          // [V6 修复] 在显示错误前，重置 waiting 文本
          $allWaitingPlaceholders.find('[data-i18n-key]').text(t("cards_waiting"));
          showKdsAlert(errorMsg, true); // 修复：使用 showKdsAlert
          return;
        }
        DATA = { product: res.data.product || {}, recipe: res.data.recipe || [] };
        // [V6 修复] 成功后 renderAll 会自动处理占位符的隐藏
        renderAll();
      })
      .fail(function (jqXHR, textStatus, errorThrown) {
        let errorMsg = t("err");
        if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
            errorMsg = jqXHR.responseJSON.message;
        }
        // [V6 修复] 在显示错误前，重置 waiting 文本
        $allWaitingPlaceholders.find('[data-i18n-key]').text(t("cards_waiting"));
        showKdsAlert(errorMsg, true); // 修复：使用 showKdsAlert
      });
  }

  /* ========================= 渲染入口 ========================= */
  function renderAll() {
    // 提示条由 renderStaticUI 控制
    renderLeft();
    renderCards();
  }

  /* ========================= 启动 ========================= */
  bindTabs();
  renderStaticUI(); // 先把静态 UI 换到当前语言
  removeLegacyHints();
  // [V6 修复] 初始状态由 HTML 决定，JS不再调用 resetCardContainers
  // resetCardContainers(); 

  // [V10] 绑定扫码按钮
  $(document).on("click", "#btn-scan-qr", function() {
      if (window.AndroidBridge && typeof window.AndroidBridge.startScan === 'function') {
          try {
              // 调用安卓接口，并指定全局回调函数的名字
              window.AndroidBridge.startScan('onScanSuccess', 'onScanError');
          } catch (e) {
              showKdsAlert("调用扫码功能失败: " + e.message, true);
          }
      } else {
          showKdsAlert("扫码功能不可用。请在 TopTea 安卓设备上使用。", true);
      }
  });

  // 表单提交 / 回车查询
  if ($form.length) {
    $form.on("submit", function (e) {
      e.preventDefault();
      const code = ($input.val() || "").trim();
      if (code) fetchSop(code);
    });
  }
  if ($input.length) {
    $input.on("keydown", function (e) {
      if (e.key === "Enter") {
        e.preventDefault();
        const code = ($input.val() || "").trim();
        if (code) fetchSop(code);
      }
    });
    // 输入框有初值则自动查询一次
    if (($input.val() || "").trim()) fetchSop(($input.val() || "").trim());
  }
});