<?php
/**
 * Toptea Store - KDS
 * KDS Data Helper Functions (HELPER LIBRARY)
 * Engineer: Gemini | Date: 2025-10-31 | Revision: 5.2 (Fix best_adjust to select step_category)
 *
 * [GEMINI V6.0 KDS REFACTOR]:
 * - sop_handler.php 现已包含所有解析逻辑 (KdsSopParser V2) 和其依赖的函数。
 * - 此文件被清理，以避免函数重复定义。
 * - 移除了 parse_code, id_by_code, get_product, m_name, u_name, get_product_info_bilingual,
 * get_cup_names, get_ice_names, get_sweet_names, get_available_options。
 * - 保留了 base_recipe, norm_cat, best_adjust 作为通用助手。
 */

if (function_exists('base_recipe')) {
    return; // 防止重复加载
}

/* ───────────────── 引擎工具函数 (保留的通用函数) ───────────────── */

function base_recipe(PDO $pdo, int $pid): array {
  $sql="SELECT material_id,unit_id,quantity,step_category,sort_order
        FROM kds_product_recipes
        WHERE product_id=?
        ORDER BY sort_order, id";
  $st=$pdo->prepare($sql); $st->execute([$pid]); return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function norm_cat(string $c): string {
  $c = trim(mb_strtolower($c));
  if (in_array($c, ['base','底料','diliao'], true)) return 'base';
  if (in_array($c, ['mix','mixing','调杯','tiao','blend'], true)) return 'mixing';
  if (in_array($c, ['top','topping','顶料','dingliao'], true)) return 'topping';
  return 'mixing';
}

function best_adjust(PDO $pdo, int $pid, int $mid, ?int $cup, ?int $ice, ?int $sweet): ?array {
  $cond=["product_id=?","material_id=?"]; $args=[$pid,$mid]; $score=[];
  if ($cup!==null){ $cond[]="(cup_id IS NULL OR cup_id=?)"; $args[]=$cup; $score[]="(cup_id IS NOT NULL)"; } else { $cond[]="(cup_id IS NULL)"; }
  if ($ice!==null){ $cond[]="(ice_option_id IS NULL OR ice_option_id=?)"; $args[]=$ice; $score[]="(ice_option_id IS NOT NULL)"; } else { $cond[]="(ice_option_id IS NULL)"; }
  if ($sweet!==null){ $cond[]="(sweetness_option_id IS NULL OR sweetness_option_id=?)"; $args[]=$sweet; $score[]="(sweetness_option_id IS NOT NULL)"; } else { $cond[]="(sweetness_option_id IS NULL)"; }
  $scoreExpr=$score? implode(' + ',$score):'0';
  $sql="SELECT material_id,quantity,unit_id,step_category FROM kds_recipe_adjustments
        WHERE ".implode(' AND ',$cond)." ORDER BY {$scoreExpr} DESC, id DESC LIMIT 1";
  $st=$pdo->prepare($sql); $st->execute($args); $r=$st->fetch(PDO::FETCH_ASSOC); return $r?:null;
}
}