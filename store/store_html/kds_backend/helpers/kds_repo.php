<?php
/**
 * Toptea Store - KDS 核心帮助库 (Repo)
 * 包含所有 KDS API 处理器共用的业务逻辑函数。
 * (迁移自 /kds/api/*)
 * Version: 1.0.0
 * Date: 2025-11-08
 */

/* -------------------------------------------------------------------------- */
/* 迁移自: kds/api/sop_handler.php [KdsSopParser, id_by_code, ...]            */
/*                                                               */
/* -------------------------------------------------------------------------- */

if (!class_exists('KdsSopParser')) {
    class KdsSopParser {
        private $pdo;
        private $store_id;
        private $rules = null; // 缓存规则

        public function __construct(PDO $pdo, int $store_id) {
            $this->pdo = $pdo;
            $this->store_id = $store_id;
        }

        /**
         * 加载此门店可用的所有SOP解析规则
         */
        private function loadRules(): void {
            if ($this->rules !== null) return;
            $sql = "
                SELECT * FROM kds_sop_query_rules
                WHERE is_active = 1
                  AND (store_id = :current_store_id OR store_id IS NULL)
                ORDER BY 
                  store_id DESC,
                  priority ASC
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':current_store_id' => $this->store_id]);
            $this->rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        /**
         * V1 解析器: "分隔符" 模式 (e.g., P-A-M-T)
         */
        private function _parseV1Delimiter(string $code, array $config): ?array {
            $format_str = $config['format'] ?? '';
            $separator = $config['separator'] ?? '-';
            $prefix = $config['prefix'] ?? '';

            if (!empty($prefix)) {
                if (strpos($code, $prefix) !== 0) return null;
                $code = substr($code, strlen($prefix));
            }

            $format_parts = explode('-', $format_str);
            $code_parts = explode($separator, $code);

            if (count($format_parts) !== count($code_parts)) return null;

            $result = ['p' => '', 'a' => null, 'm' => null, 't' => null, 'ord' => null];
            foreach ($format_parts as $index => $part_key) {
                $part_key_lower = strtolower($part_key);
                $value = $code_parts[$index] ?? null;
                
                if (array_key_exists($part_key_lower, $result)) {
                    if ($value !== null && $value !== '') {
                        $result[$part_key_lower] = $value;
                    }
                }
            }
            
            return (!empty($result['p'])) ? $result : null;
        }

        /**
         * V1 解析器: "键值对" 模式 (e.g., ?o=101&c=1)
         */
        private function _parseV1KeyValue(string $code, array $config): ?array {
            $code = ltrim($code, '?#/');
            parse_str($code, $query_params);
            if (empty($query_params)) return null;

            $result = ['p' => '', 'a' => null, 'm' => null, 't' => null, 'ord' => null];
            $p_key = $config['P_key'] ?? 'p';
            $a_key = $config['A_key'] ?? '';
            $m_key = $config['M_key'] ?? '';
            $t_key = $config['T_key'] ?? '';

            if (empty($query_params[$p_key])) return null;
            
            $result['p'] = $query_params[$p_key] ?? '';
            if (!empty($a_key) && !empty($query_params[$a_key])) $result['a'] = $query_params[$a_key];
            if (!empty($m_key) && !empty($query_params[$m_key])) $result['m'] = $query_params[$m_key];
            if (!empty($t_key) && !empty($query_params[$t_key])) $result['t'] = $query_params[$t_key];
            // V1 不支持 ORD
            
            return (!empty($result['p'])) ? $result : null;
        }

        /**
         * V2 解析器: "模板" 模式 (e.g., {ORD}|{P}-{A})
         */
        private function _parseTemplateV2(string $code, array $config): ?array {
            $template = $config['template'] ?? '';
            $mapping = $config['mapping'] ?? [];
            
            if (empty($template) || empty($mapping)) return null;

            // 1. 反转映射 [ "P" => "p", "A" => "a", ... ]
            $placeholder_to_key_map = [];
            foreach ($mapping as $key => $placeholder) {
                if (!empty($placeholder)) {
                    $placeholder_to_key_map[$placeholder] = $key;
                }
            }
            
            // 2. 将模板字符串转换为正则表达式
            $regex = '~^';
            $parts = preg_split('/(\{[a-zA-Z0-9_]+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
            $found_placeholders = [];
            
            foreach ($parts as $part) {
                if (preg_match('/^\{([a-zA-Z0-9_]+)\}$/', $part, $matches)) {
                    $placeholder = $matches[1]; // "P"
                    if (isset($placeholder_to_key_map[$placeholder])) {
                        // 命名捕获组 (?P<name>...)
                        $regex .= '(?P<' . $placeholder . '>.+?)';
                        $found_placeholders[] = $placeholder;
                    } else {
                        $regex .= preg_quote($part);
                    }
                } else {
                    $regex .= preg_quote($part);
                }
            }
            $regex .= '$~u'; // 结尾, u=utf8

            // 3. 执行匹配
            if (!preg_match($regex, $code, $matches)) {
                return null; // 不匹配
            }

            // 4. 映射结果
            $result = ['p' => '', 'a' => null, 'm' => null, 't' => null, 'ord' => null];
            $p_found = false;

            foreach ($found_placeholders as $placeholder) { // e.g., "P", "A"
                $value = $matches[$placeholder] ?? null;
                $key = $placeholder_to_key_map[$placeholder]; // e.g., "p", "a"
                
                if ($key === 'p' && !empty($value)) {
                    $result['p'] = $value;
                    $p_found = true;
                } elseif (array_key_exists($key, $result) && !empty($value)) {
                    $result[$key] = $value;
                }
            }

            return $p_found ? $result : null;
        }

        /**
         * 公共方法：解析查询码 (V2 智能路由)
         */
        public function parse(string $raw_code): ?array {
            $this->loadRules();
            
            $raw_code = trim($raw_code);
            if ($raw_code === '') {
                return null;
            }

            foreach ($this->rules as $rule) {
                try {
                    $config = json_decode($rule['config_json'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        error_log("KDS SOP Parser: Skipping rule ID {$rule['id']} due to invalid JSON.");
                        continue; 
                    }

                    $result = null;
                    
                    // [V2] 优先使用新模板解析器
                    if (isset($config['template'])) {
                        $result = $this->_parseTemplateV2($raw_code, $config);
                    } 
                    // [V1] 兼容旧的 DELIMITER 规则
                    elseif ($rule['extractor_type'] === 'DELIMITER') {
                        $result = $this->_parseV1Delimiter($raw_code, $config);
                    } 
                    // [V1] 兼容旧的 KEY_VALUE 规则
                    elseif ($rule['extractor_type'] === 'KEY_VALUE') {
                        $result = $this->_parseV1KeyValue($raw_code, $config);
                    }
                    
                    if ($result !== null) {
                        $result['raw'] = $raw_code;
                        return $result; // 立即返回第一个匹配项
                    }

                } catch (Throwable $e) {
                    error_log("KDS SOP Parser: Error in rule ID {$rule['id']}: " . $e->getMessage());
                }
            }

            return null;
        }
    }
} // end !class_exists KdsSopParser

if (!function_exists('id_by_code')) {
    function id_by_code(PDO $pdo, string $table, string $col, $val): ?int {
        if ($val === null || $val === '') return null;
        // [MODIFIED 2b] KDS 助手只查询 kds_ 表
        $kds_tables = ['kds_cups', 'kds_ice_options', 'kds_sweetness_options'];
        if (!in_array($table, $kds_tables)) {
             error_log("KDS REPO: id_by_code blocked access to non-kds table: $table");
             return null;
        }
        $st = $pdo->prepare("SELECT id FROM {$table} WHERE {$col}=? AND deleted_at IS NULL LIMIT 1"); $st->execute([$val]);
        $id = $st->fetchColumn(); return $id ? (int)$id : null;
    }
}
if (!function_exists('get_product')) {
    function get_product(PDO $pdo, string $p_code): ?array {
        $st = $pdo->prepare("SELECT id, product_code, status_id, is_active, deleted_at FROM kds_products WHERE product_code=? LIMIT 1"); $st->execute([$p_code]); $r = $st->fetch(PDO::FETCH_ASSOC); return $r ?: null;
    }
}
if (!function_exists('norm_cat')) {
    function norm_cat(string $c): string {
        $c = trim(mb_strtolower($c));
        if (in_array($c, ['base', '底料', 'diliao'], true)) return 'base';
        if (in_array($c, ['top', 'topping', '顶料', 'dingliao'], true)) return 'topping';
        return 'mixing';
    }
}
if (!function_exists('m_details')) {
    function m_details(PDO $pdo, int $mid): array {
        $st = $pdo->prepare("
            SELECT 
                m.image_url,
                mt_zh.material_name AS name_zh,
                mt_es.material_name AS name_es
            FROM kds_materials m
            LEFT JOIN kds_material_translations mt_zh ON m.id = mt_zh.material_id AND mt_zh.language_code = 'zh-CN'
            LEFT JOIN kds_material_translations mt_es ON m.id = mt_es.material_id AND mt_es.language_code = 'es-ES'
            WHERE m.id = ?
        ");
        $st->execute([$mid]);
        $data = $st->fetch(PDO::FETCH_ASSOC);

        return [
            'zh' => $data['name_zh'] ?? ('#' . $mid), 
            'es' => $data['name_es'] ?? ('#' . $mid),
            'image_url' => $data['image_url'] ?? null
        ];
    }
}
if (!function_exists('u_name')) {
    function u_name(PDO $pdo, int $uid): array {
        $st = $pdo->prepare("SELECT language_code, unit_name FROM kds_unit_translations WHERE unit_id=?");
        $st->execute([$uid]);
        $names = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        return ['zh' => $names['zh-CN'] ?? '', 'es' => $names['es-ES'] ?? ''];
    }
}
if (!function_exists('get_product_info_bilingual')) {
    function get_product_info_bilingual(PDO $pdo, int $pid, int $status_id): array {
        $st_prod = $pdo->prepare("SELECT pt_zh.product_name AS name_zh, pt_es.product_name AS name_es FROM kds_product_translations pt_zh LEFT JOIN kds_product_translations pt_es ON pt_zh.product_id = pt_es.product_id AND pt_es.language_code = 'es-ES' WHERE pt_zh.product_id = ? AND pt_zh.language_code = 'zh-CN'");
        $st_prod->execute([$pid]);
        $info = $st_prod->fetch(PDO::FETCH_ASSOC) ?: [];
        $st_status = $pdo->prepare("SELECT status_name_zh, status_name_es FROM kds_product_statuses WHERE id = ? AND deleted_at IS NULL");
        $st_status->execute([$status_id]);
        $status_names = $st_status->fetch(PDO::FETCH_ASSOC) ?: [];
        return array_merge($info, $status_names);
    }
}
if (!function_exists('get_cup_names_bilingual')) {
    function get_cup_names_bilingual(PDO $pdo, ?int $cid): array {
        if ($cid === null) return ['cup_name_zh' => null, 'cup_name_es' => null];
        $st = $pdo->prepare("SELECT cup_name, sop_description_zh, sop_description_es FROM kds_cups WHERE id = ?"); $st->execute([$cid]); $row = $st->fetch(PDO::FETCH_ASSOC);
        return ['cup_name_zh' => $row['sop_description_zh'] ?? $row['cup_name'] ?? null, 'cup_name_es' => $row['sop_description_es'] ?? $row['cup_name'] ?? null];
    }
}
if (!function_exists('get_ice_names_bilingual')) {
    function get_ice_names_bilingual(PDO $pdo, ?int $iid): array {
        if ($iid === null) return ['ice_name_zh' => null, 'ice_name_es' => null];
        $st = $pdo->prepare("SELECT language_code, ice_option_name FROM kds_ice_option_translations WHERE ice_option_id = ?"); $st->execute([$iid]);
        $names = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        return ['ice_name_zh' => $names['zh-CN'] ?? null, 'ice_name_es' => $names['es-ES'] ?? $names['zh-CN'] ?? null];
    }
}
if (!function_exists('get_sweet_names_bilingual')) {
    function get_sweet_names_bilingual(PDO $pdo, ?int $sid): array {
        if ($sid === null) return ['sweetness_name_zh' => null, 'sweetness_name_es' => null];
        $st = $pdo->prepare("SELECT language_code, sweetness_option_name FROM kds_sweetness_option_translations WHERE sweetness_option_id = ?"); $st->execute([$sid]);
        $names = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        return ['sweetness_name_zh' => $names['zh-CN'] ?? null, 'sweetness_name_es' => $names['es-ES'] ?? $names['zh-CN'] ?? null];
    }
}
if (!function_exists('check_gating')) {
    function check_gating(PDO $pdo, int $pid, ?int $cup_id, ?int $ice_id, ?int $sweet_id) {
        $ice_rules = $pdo->prepare("SELECT ice_option_id FROM kds_product_ice_options WHERE product_id = ?");
        $ice_rules->execute([$pid]);
        $allowed_ice_ids = $ice_rules->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($allowed_ice_ids)) {
            if ($ice_id === null) throw new Exception("此产品需要冰量 (M-code)，但未提供。", 403);
            if (!in_array($ice_id, $allowed_ice_ids)) throw new Exception("冰量 (M-code) 不适用于此产品。", 403);
        }
        $sweet_rules = $pdo->prepare("SELECT sweetness_option_id FROM kds_product_sweetness_options WHERE product_id = ?");
        $sweet_rules->execute([$pid]);
        $allowed_sweet_ids = $sweet_rules->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($allowed_sweet_ids)) {
            if ($sweet_id === null) throw new Exception("此产品需要甜度 (T-code)，但未提供。", 403);
            if (!in_array($sweet_id, $allowed_sweet_ids)) throw new Exception("甜度 (T-code) 不适用于此产品。", 403);
        }
    }
}
if (!function_exists('get_base_recipe')) {
    function get_base_recipe(PDO $pdo, int $pid): array {
        $st = $pdo->prepare("SELECT material_id, quantity, unit_id, step_category, sort_order FROM kds_product_recipes WHERE product_id = ? ORDER BY sort_order ASC, id ASC");
        $st->execute([$pid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $recipe_map = [];
        foreach ($rows as $row) {
            $recipe_map[(int)$row['material_id']] = [
                'material_id' => (int)$row['material_id'],
                'quantity' => (float)$row['quantity'],
                'unit_id' => (int)$row['unit_id'],
                'step_category' => norm_cat((string)$row['step_category']),
                'sort_order' => (int)$row['sort_order'],
                'source' => 'L1'
            ];
        }
        return $recipe_map;
    }
}
if (!function_exists('apply_global_rules')) {
    function apply_global_rules(PDO $pdo, array $recipe, ?int $cup, ?int $ice, ?int $sweet): array {
        $st = $pdo->prepare("SELECT * FROM kds_global_adjustment_rules WHERE is_active = 1 ORDER BY priority ASC, id ASC");
        $st->execute();
        $rules = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rules as $rule) {
            if ($rule['cond_cup_id'] !== null && $rule['cond_cup_id'] != $cup) continue;
            if ($rule['cond_ice_id'] !== null && $rule['cond_ice_id'] != $ice) continue;
            if ($rule['cond_sweet_id'] !== null && $rule['cond_sweet_id'] != $sweet) continue;
            $target_mid = (int)$rule['action_material_id'];
            if ($rule['cond_material_id'] !== null) {
                if ($rule['cond_material_id'] != $target_mid) continue;
                if (!isset($recipe[$target_mid])) continue;
            }
            if (!isset($recipe[$target_mid]) && $rule['action_type'] !== 'ADD_MATERIAL') {
                 continue;
            }
            $cond_base_gt = $rule['cond_base_gt'];
            $cond_base_lte = $rule['cond_base_lte'];
            if ($cond_base_gt !== null || $cond_base_lte !== null) {
                if (!isset($recipe[$target_mid])) {
                    continue; 
                }
                $base_quantity = (float)$recipe[$target_mid]['quantity'];
                if ($cond_base_gt !== null && !($base_quantity > (float)$cond_base_gt)) {
                    continue;
                }
                if ($cond_base_lte !== null && !($base_quantity <= (float)$cond_base_lte)) {
                    continue;
                }
            }
            $value = (float)$rule['action_value'];
            switch ($rule['action_type']) {
                case 'SET_VALUE':
                    $recipe[$target_mid]['quantity'] = $value;
                    $recipe[$target_mid]['source'] = 'L2-SET';
                    break;
                case 'ADD_MATERIAL':
                    if (!isset($recipe[$target_mid])) {
                        $recipe[$target_mid] = [
                            'material_id' => $target_mid,
                            'quantity' => $value,
                            'unit_id' => (int)$rule['action_unit_id'],
                            'step_category' => 'mixing',
                            'sort_order' => 500 + $target_mid,
                            'source' => 'L2-ADD'
                        ];
                    }
                    break;
                case 'CONDITIONAL_OFFSET':
                    $recipe[$target_mid]['quantity'] += $value;
                    $recipe[$target_mid]['source'] = 'L2-OFFSET';
                    break;
                case 'MULTIPLY_BASE':
                    $recipe[$target_mid]['quantity'] *= $value;
                    $recipe[$target_mid]['source'] = 'L2-MULTIPLY';
                    break;
            }
        }
        return $recipe;
    }
}
if (!function_exists('apply_overrides')) {
    function apply_overrides(PDO $pdo, int $pid, array $recipe, ?int $cup, ?int $ice, ?int $sweet): array {
        $st = $pdo->prepare("SELECT DISTINCT material_id FROM kds_recipe_adjustments WHERE product_id = ?");
        $st->execute([$pid]);
        $l3_material_ids = $st->fetchAll(PDO::FETCH_COLUMN);
        $all_mids_to_check = array_unique(array_merge(array_keys($recipe), $l3_material_ids));

        foreach ($all_mids_to_check as $mid) {
            $mid = (int)$mid;
            $adj = best_adjust_l3($pdo, $pid, $mid, $cup, $ice, $sweet);
            
            if ($adj) {
                $recipe[$mid] = [
                    'material_id' => $mid,
                    'quantity' => (float)$adj['quantity'],
                    'unit_id' => (int)$adj['unit_id'],
                    'step_category' => norm_cat((string)$adj['step_category']),
                    'sort_order' => $recipe[$mid]['sort_order'] ?? (600 + $mid),
                    'source' => 'L3-OVERRIDE'
                ];
            }
        }
        return $recipe;
    }
}
if (!function_exists('best_adjust_l3')) {
    function best_adjust_l3(PDO $pdo, int $pid, int $mid, ?int $cup, ?int $ice, ?int $sweet): ?array {
        $cond = ["product_id=?", "material_id=?"]; $args = [$pid, $mid]; $score = [];
        if ($cup !== null) { $cond[] = "(cup_id IS NULL OR cup_id=?)"; $args[] = $cup; $score[] = "(cup_id IS NOT NULL)"; } else { $cond[] = "(cup_id IS NULL)"; }
        if ($ice !== null) { $cond[] = "(ice_option_id IS NULL OR ice_option_id=?)"; $args[] = $ice; $score[] = "(ice_option_id IS NOT NULL)"; } else { $cond[] = "(ice_option_id IS NULL)"; }
        if ($sweet !== null) { $cond[] = "(sweetness_option_id IS NULL OR sweetness_option_id=?)"; $args[] = $sweet; $score[] = "(sweetness_option_id IS NOT NULL)"; } else { $cond[] = "(sweetness_option_id IS NULL)"; }
        $scoreExpr = $score ? implode(' + ', $score) : '0';
        $sql = "SELECT material_id, quantity, unit_id, step_category FROM kds_recipe_adjustments
                WHERE " . implode(' AND ', $cond) . " ORDER BY {$scoreExpr} DESC, id DESC LIMIT 1";
        $st = $pdo->prepare($sql); $st->execute($args); $r = $st->fetch(PDO::FETCH_ASSOC); return $r ?: null;
    }
}
if (!function_exists('get_available_options')) {
    function get_available_options(PDO $pdo, int $pid): array {
        $options = ['cups' => [], 'ice_options' => [], 'sweetness_options' => []];
        $cup_sql = "
            SELECT DISTINCT c.id, c.cup_code, c.cup_name, c.sop_description_zh, c.sop_description_es
            FROM kds_cups c
            JOIN pos_item_variants piv ON c.id = piv.cup_id
            JOIN pos_menu_items pmi ON piv.menu_item_id = pmi.id
            JOIN kds_products kp ON pmi.product_code = kp.product_code
            WHERE kp.id = ? AND c.deleted_at IS NULL AND piv.deleted_at IS NULL
        ";
        $stmt_cups = $pdo->prepare($cup_sql); $stmt_cups->execute([$pid]);
        $options['cups'] = $stmt_cups->fetchAll(PDO::FETCH_ASSOC);
        $ice_sql = "
            SELECT io.id, io.ice_code, iot_zh.ice_option_name AS name_zh, iot_es.ice_option_name AS name_es
            FROM kds_product_ice_options pio
            JOIN kds_ice_options io ON pio.ice_option_id = io.id
            LEFT JOIN kds_ice_option_translations iot_zh ON io.id = iot_zh.ice_option_id AND iot_zh.language_code = 'zh-CN'
            LEFT JOIN kds_ice_option_translations iot_es ON io.id = iot_es.ice_option_id AND iot_es.language_code = 'es-ES'
            WHERE pio.product_id = ? AND io.deleted_at IS NULL ORDER BY io.ice_code
        ";
        $stmt_ice = $pdo->prepare($ice_sql); $stmt_ice->execute([$pid]);
        $options['ice_options'] = $stmt_ice->fetchAll(PDO::FETCH_ASSOC);
        if (empty($options['ice_options'])) {
            $options['ice_options'] = $pdo->query("SELECT io.id, io.ice_code, iot_zh.ice_option_name AS name_zh, iot_es.ice_option_name AS name_es FROM kds_ice_options io LEFT JOIN kds_ice_option_translations iot_zh ON io.id = iot_zh.ice_option_id AND iot_zh.language_code = 'zh-CN' LEFT JOIN kds_ice_option_translations iot_es ON io.id = iot_es.ice_option_id AND iot_es.language_code = 'es-ES' WHERE io.deleted_at IS NULL ORDER BY io.ice_code")->fetchAll(PDO::FETCH_ASSOC);
        }
        $sweet_sql = "SELECT so.id, so.sweetness_code, sot_zh.sweetness_option_name AS name_zh, sot_es.sweetness_option_name AS name_es
                      FROM kds_product_sweetness_options pso
                      JOIN kds_sweetness_options so ON pso.sweetness_option_id = so.id
                      LEFT JOIN kds_sweetness_option_translations sot_zh ON so.id = sot_zh.sweetness_option_id AND sot_zh.language_code = 'zh-CN'
                      LEFT JOIN kds_sweetness_option_translations sot_es ON so.id = sot_es.sweetness_option_id AND sot_es.language_code = 'es-ES'
                      WHERE pso.product_id = ? AND so.deleted_at IS NULL ORDER BY so.sweetness_code";
        $stmt_sweet = $pdo->prepare($sweet_sql); $stmt_sweet->execute([$pid]);
        $options['sweetness_options'] = $stmt_sweet->fetchAll(PDO::FETCH_ASSOC);
        if (empty($options['sweetness_options'])) {
            $options['sweetness_options'] = $pdo->query("SELECT so.id, so.sweetness_code, sot_zh.sweetness_option_name AS name_zh, sot_es.sweetness_option_name AS name_es FROM kds_sweetness_options so LEFT JOIN kds_sweetness_option_translations sot_zh ON so.id = sot_zh.sweetness_option_id AND sot_zh.language_code = 'zh-CN' LEFT JOIN kds_sweetness_option_translations sot_es ON so.id = sot_es.sweetness_option_id AND sot_es.language_code = 'es-ES' WHERE so.deleted_at IS NULL ORDER BY so.sweetness_code")->fetchAll(PDO::FETCH_ASSOC);
        }
        return $options;
    }
}
if (!function_exists('get_base_recipe_bilingual')) {
    function get_base_recipe_bilingual(PDO $pdo, int $pid): array {
        $st = $pdo->prepare("
            SELECT r.material_id, r.quantity, r.unit_id, r.step_category
            FROM kds_product_recipes r
            WHERE r.product_id = ? ORDER BY r.sort_order ASC, r.id ASC
        ");
        $st->execute([$pid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        
        $recipe = [];
        foreach ($rows as $row) {
            $m_details = m_details($pdo, (int)$row['material_id']);
            $u_names = u_name($pdo, (int)$row['unit_id']);
            $recipe[] = [
                'material_id'   => (int)$row['material_id'],
                'material_zh' => $m_details['zh'],
                'material_es' => $m_details['es'],
                'image_url'   => $m_details['image_url'],
                'quantity'      => (float)$row['quantity'],
                'unit_id'       => (int)$row['unit_id'],
                'unit_zh'       => $u_names['zh'],
                'unit_es'       => $u_names['es'],
                'step_category' => norm_cat((string)$row['step_category'])
            ];
        }
        return $recipe;
    }
}

/* -------------------------------------------------------------------------- */
/* 迁移自: kds/api/record_expiry_item.php (Shim) [getMaterialById] */
/* -------------------------------------------------------------------------- */

if (!function_exists('getMaterialById')) {
    // 这个函数同时被 `record_expiry_item.php` (旧) 和 `sop_handler.php` (旧) 依赖
    function getMaterialById(PDO $pdo, int $id) {
        $sql = "
            SELECT 
                m.id, m.material_code, m.material_type, m.base_unit_id, 
                m.medium_unit_id, m.medium_conversion_rate,
                m.large_unit_id, m.large_conversion_rate,
                m.expiry_rule_type, m.expiry_duration,
                m.image_url,
                mt_zh.material_name AS name_zh, 
                mt_es.material_name AS name_es,
                ut_base.unit_name AS base_unit_name,
                ut_large.unit_name AS large_unit_name
            FROM kds_materials m 
            LEFT JOIN kds_material_translations mt_zh ON m.id = mt_zh.material_id AND mt_zh.language_code = 'zh-CN' 
            LEFT JOIN kds_material_translations mt_es ON m.id = mt_es.material_id AND mt_es.language_code = 'es-ES'
            LEFT JOIN kds_unit_translations ut_base ON m.base_unit_id = ut_base.unit_id AND ut_base.language_code = 'zh-CN'
            LEFT JOIN kds_unit_translations ut_large ON m.large_unit_id = ut_large.unit_id AND ut_large.language_code = 'zh-CN'
            WHERE m.id = ? AND m.deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        // 确保返回关联数组
        return $stmt->fetch(PDO::FETCH_ASSOC); 
    }
}