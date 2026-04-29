<?php
session_start();

if (!isset($_SESSION["users_id"])) {
    header("Location: ../login.php");
    exit;
}

$user_id = (int)$_SESSION["users_id"];
require_once __DIR__ . '/../data/db_connect.php';

function predict_growth_php(array $data): array {
    try {
        $planting_date_str = !empty($data['planting_date']) ? $data['planting_date'] : date('Y-m-d');
        $variety = strtolower((string)($data['corn_variety'] ?? 'Unknown'));
        $soil_type = strtolower((string)($data['soil_type'] ?? 'Loam'));
        $area_value = (float)($data['area_value'] ?? 1.0);
        $area_unit = strtolower((string)($data['area_unit'] ?? 'hectare'));
        $density = (float)($data['density'] ?? 60000);
        $seeds_per_hole = (int)($data['seeds_per_hole'] ?? 1);

        $area_in_ha = $area_unit === 'sqm' ? $area_value / 10000 : $area_value;

        $base_yield_ha = 5.5;
        $v_lower = $variety;
        $variety_factor = 1.0;
        $days_to_maturity = 110;

        if (strpos($v_lower, 'sweet') !== false) {
            if (strpos($v_lower, 'hybrid') !== false) $variety_factor = 0.8;
            elseif (strpos($v_lower, 'native') !== false) $variety_factor = 0.65;
            elseif (strpos($v_lower, 'opv') !== false) $variety_factor = 0.7;
            else $variety_factor = 0.75;
            $days_to_maturity = 75;
        } elseif (strpos($v_lower, 'yellow') !== false) {
            if (strpos($v_lower, 'hybrid') !== false) $variety_factor = 1.25;
            elseif (strpos($v_lower, 'feed') !== false) $variety_factor = 1.15;
            elseif (strpos($v_lower, 'native') !== false) $variety_factor = 0.95;
            else $variety_factor = 1.1;
            $days_to_maturity = 115;
        } elseif (strpos($v_lower, 'white') !== false) {
            if (strpos($v_lower, 'field') !== false) $variety_factor = 1.05;
            elseif (strpos($v_lower, 'native') !== false) $variety_factor = 0.9;
            else $variety_factor = 0.95;
            $days_to_maturity = 105;
        } elseif (strpos($v_lower, 'glutinous') !== false || strpos($v_lower, 'waxy') !== false) {
            $variety_factor = 0.85;
            $days_to_maturity = 90;
        } elseif (strpos($v_lower, 'popcorn') !== false) {
            $variety_factor = 0.55;
            $days_to_maturity = 100;
        } elseif (strpos($v_lower, 'baby') !== false) {
            $variety_factor = 0.4;
            $days_to_maturity = 60;
        } elseif (strpos($v_lower, 'hybrid') !== false) {
            $variety_factor = 1.2;
            $days_to_maturity = 115;
        }

        $soil_factor = 1.0;
        if (strpos($soil_type, 'loam') !== false) {
            $soil_factor = 1.15;
        } elseif (strpos($soil_type, 'clay') !== false) {
            $soil_factor = 0.9;
        } elseif (strpos($soil_type, 'sandy') !== false) {
            $soil_factor = 0.85;
        }

        $density_factor = 1.0;
        if ($density < 40000) {
            $density_factor = 0.8;
        } elseif ($density > 80000) {
            $density_factor = 0.85;
        }

        $seeds_factor = 1.0;
        if ($seeds_per_hole > 2) {
            $seeds_factor = 0.9;
        }

        $predicted_yield_ha = $base_yield_ha * $variety_factor * $soil_factor * $density_factor * $seeds_factor;
        $total_predicted_yield = $predicted_yield_ha * $area_in_ha;

        $planting_date = new DateTime($planting_date_str);
        $harvest_date = (clone $planting_date)->modify('+' . $days_to_maturity . ' days');

        $completeness = 0;
        if (!empty($data['corn_variety'])) $completeness += 20;
        if (!empty($data['soil_type'])) $completeness += 20;
        if (!empty($data['density'])) $completeness += 20;
        if (!empty($data['area_value'])) $completeness += 20;
        if (!empty($data['planting_date'])) $completeness += 20;

        $confidence = $completeness * 0.95;

        return [
            'status' => 'success',
            'prediction' => [
                'yield_tons_ha' => round($predicted_yield_ha, 2),
                'total_yield_tons' => round($total_predicted_yield, 2),
                'total_yield_sacks' => round($total_predicted_yield * 20, 1),
                'harvest_date' => $harvest_date->format('Y-m-d'),
                'days_to_maturity' => $days_to_maturity,
                'confidence' => sprintf('%.1f%%', $confidence),
                'factors' => [
                    'soil_coefficient' => $soil_factor,
                    'variety_coefficient' => $variety_factor,
                    'density_efficiency' => $density_factor
                ]
            ]
        ];
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function get_accuracy_level_label(float $confidencePercent): string {
    if ($confidencePercent >= 90) {
        return 'Very High';
    }
    if ($confidencePercent >= 75) {
        return 'High';
    }
    if ($confidencePercent >= 60) {
        return 'Moderate';
    }
    return 'Low';
}

function analyze_plan_success(array $profile, array $prediction, float $confidence_percent): array {
    $score = 0.0;
    $factors = [];
    
    // Factor 1: Corn Type Evaluation (20 points max)
    $corn_type = strtolower((string)($profile['corn_type'] ?? ''));
    $type_score = 15.0;
    if (strpos($corn_type, 'yellow') !== false || strpos($corn_type, 'hybrid') !== false) {
        $type_score = 18.0;
    } elseif (strpos($corn_type, 'sweet') !== false) {
        $type_score = 16.0;
    } elseif (strpos($corn_type, 'white') !== false) {
        $type_score = 14.0;
    }
    $factors[] = ['name' => 'Corn Type Selection', 'value' => $type_score, 'max' => 20];
    
    // Factor 2: Planting Density Evaluation (20 points max)
    $density = (float)($profile['planting_density'] ?? 60000);
    $density_score = 12.0;
    if ($density >= 45000 && $density <= 75000) {
        $density_score = 20.0;
    } elseif ($density >= 35000 && $density <= 85000) {
        $density_score = 16.0;
    } elseif ($density < 35000 || $density > 85000) {
        $density_score = 8.0;
    }
    $factors[] = ['name' => 'Planting Density Optimization', 'value' => $density_score, 'max' => 20];
    
    // Factor 3: Seeds Per Hole Evaluation (15 points max)
    $seeds_per_hole = (int)($profile['seeds_per_hole'] ?? 1);
    $seeds_score = 15.0;
    if ($seeds_per_hole > 3) {
        $seeds_score = 6.0;
    } elseif ($seeds_per_hole == 3) {
        $seeds_score = 10.0;
    }
    $factors[] = ['name' => 'Seeds Per Hole Efficiency', 'value' => $seeds_score, 'max' => 15];
    
    // Factor 4: Soil Type Evaluation (15 points max)
    $soil_type = strtolower((string)($profile['soil_type'] ?? ''));
    $soil_score = 10.0;
    if (strpos($soil_type, 'loam') !== false) {
        $soil_score = 15.0;
    } elseif (strpos($soil_type, 'sandy') !== false || strpos($soil_type, 'clay') !== false) {
        $soil_score = 11.0;
    }
    $factors[] = ['name' => 'Soil Type Suitability', 'value' => $soil_score, 'max' => 15];
    
    // Factor 5: Prediction Confidence (20 points max)
    $confidence_score = ($confidence_percent / 100) * 20;
    $factors[] = ['name' => 'Model Confidence Score', 'value' => $confidence_score, 'max' => 20];
    
    // Factor 6: Area Size (10 points max) - larger planned area with good management
    $area_value = (float)($profile['area_value'] ?? 1.0);
    $area_unit = strtolower((string)($profile['area_unit'] ?? 'hectare'));
    $area_ha = $area_unit === 'sqm' ? ($area_value / 10000) : $area_value;
    $area_score = 5.0;
    if ($area_ha >= 0.5 && $area_ha <= 5.0) {
        $area_score = 10.0;
    } elseif ($area_ha > 0.1 && $area_ha < 10.0) {
        $area_score = 7.5;
    }
    $factors[] = ['name' => 'Planned Area Scale', 'value' => $area_score, 'max' => 10];
    
    // Calculate total score
    foreach ($factors as $factor) {
        $score += $factor['value'];
    }
    
    // Determine success level and recommendations
    $success_level = 'Good';
    $recommendations = [];
    
    if ($score >= 95) {
        $success_level = 'Excellent';
        $recommendations[] = '✓ Your planting profile shows excellent conditions for a successful harvest.';
        $recommendations[] = '✓ All key parameters are optimally configured.';
        $recommendations[] = '→ Focus on timely maintenance and pest management.';
    } elseif ($score >= 80) {
        $success_level = 'Good';
        $recommendations[] = '✓ Your planting plan is well-structured with good potential.';
        if ($density_score < 15) {
            $recommendations[] = '→ Consider adjusting planting density to the 45,000-75,000 range.';
        }
        if ($seeds_score < 12) {
            $recommendations[] = '→ Reduce seeds per hole to 1-2 for better efficiency.';
        }
    } elseif ($score >= 65) {
        $success_level = 'Fair';
        $recommendations[] = '⚠ Your plan has moderate potential but needs optimization.';
        if ($density_score < 15) {
            $recommendations[] = '→ Primary: Adjust planting density within recommended range.';
        }
        if ($soil_score < 12) {
            $recommendations[] = '→ Improve soil preparation or consider soil amendments.';
        }
        if ($seeds_score < 12) {
            $recommendations[] = '→ Optimize seeds per hole ratio.';
        }
    } else {
        $success_level = 'Needs Improvement';
        $recommendations[] = '⚠ Your current plan has several areas that need attention.';
        $recommendations[] = '→ Review planting density, seed count, and soil conditions.';
        $recommendations[] = '→ Consider consulting with an agricultural extension officer.';
    }
    
    return [
        'score' => round($score, 1),
        'max_score' => 100,
        'percentage' => round(($score / 100) * 100, 1),
        'level' => $success_level,
        'factors' => $factors,
        'recommendations' => $recommendations
    ];
}

// --- Setup Individual Price Path ---
$profile_source = $_SESSION["name"] ?? $_SESSION["username"] ?? ("Farmer" . $user_id);
$profile_safe = preg_replace('/[^A-Za-z0-9_-]+/', '', str_replace(' ', '', $profile_source));
if ($profile_safe === "") $profile_safe = "Farmer" . $user_id;

$individual_prices_path = __DIR__ . '/../data/Market Prices/' . $profile_safe . '.json';
$global_prices_path = __DIR__ . '/../data/market_prices.json';
$market_price_history_dir = __DIR__ . '/../data/Market Price Data';
$market_price_history_path = $market_price_history_dir . '/' . $profile_safe . '.json';

function ml_load_market_price_history(string $path): array {
    if (!is_file($path)) {
        return [
            'updated_at' => '',
            'history_by_variety' => []
        ];
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [
            'updated_at' => '',
            'history_by_variety' => []
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'updated_at' => '',
            'history_by_variety' => []
        ];
    }

    if (!isset($decoded['history_by_variety']) || !is_array($decoded['history_by_variety'])) {
        $decoded['history_by_variety'] = [];
    }

    return $decoded;
}

function ml_upsert_daily_market_price(string $path, string $varietyKey, float $price): bool {
    $varietyKey = trim($varietyKey);
    if ($varietyKey === '') {
        return false;
    }

    $payload = ml_load_market_price_history($path);
    if (!isset($payload['history_by_variety'][$varietyKey]) || !is_array($payload['history_by_variety'][$varietyKey])) {
        $payload['history_by_variety'][$varietyKey] = [];
    }

    $today = date('Y-m-d');
    $updated = false;

    for ($i = 0; $i < count($payload['history_by_variety'][$varietyKey]); $i += 1) {
        $rowDate = (string) ($payload['history_by_variety'][$varietyKey][$i]['date'] ?? '');
        if ($rowDate === $today) {
            $payload['history_by_variety'][$varietyKey][$i]['price'] = round($price, 2);
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        $payload['history_by_variety'][$varietyKey][] = [
            'date' => $today,
            'price' => round($price, 2)
        ];
    }

    usort($payload['history_by_variety'][$varietyKey], function ($a, $b) {
        return strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? ''));
    });

    $payload['updated_at'] = date('c');

    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents($path, $json, LOCK_EX) !== false;
}

// --- Price Update Handler ---
$update_success = false;
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'save_prices') {
    // Load current data (prefer individual, fallback to global)
    $load_path = file_exists($individual_prices_path) ? $individual_prices_path : $global_prices_path;
    $current_data = json_decode(file_get_contents($load_path), true);
    
    $active_price_key = '';
    $active_price_value = null;

    foreach ($current_data['market_prices'] as $key => $details) {
        if (isset($_POST[$key . '_price'])) {
            $postedPrice = floatval($_POST[$key . '_price']);
            $current_data['market_prices'][$key]['price_per_kg'] = $postedPrice;
            $active_price_key = (string) $key;
            $active_price_value = $postedPrice;
        }
    }
    $current_data['last_updated'] = date('Y-m-d');
    
    // Ensure the directory exists
    $dir = dirname($individual_prices_path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    
    if (file_put_contents($individual_prices_path, json_encode($current_data, JSON_PRETTY_PRINT))) {
        $update_success = true;

        if ($active_price_key !== '' && $active_price_value !== null) {
            ml_upsert_daily_market_price($market_price_history_path, $active_price_key, (float) $active_price_value);
        }
    }
}
// ----------------------------
// ----------------------------

// 1. Fetch Planting Profile
$profile = null;
$stmt = $conn->prepare("SELECT * FROM corn_profile WHERE users_id = ? AND status = 'active' ORDER BY corn_profile_id DESC LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();

// 2. Fetch Costing Data (From DB)
$total_cost = 0;
$costing_stmt = $conn->prepare("SELECT COALESCE(SUM(cost), 0) AS total_cost FROM costing WHERE users_id = ?");
if ($costing_stmt) {
    $costing_stmt->bind_param("i", $user_id);
    $costing_stmt->execute();
    $costing_row = $costing_stmt->get_result()->fetch_assoc();
    $total_cost = (float)($costing_row['total_cost'] ?? 0);
    $costing_stmt->close();
}

$prediction = null;
$error = null;

if ($profile) {
    // Prepare data for Python
    $input_data = [
        "planting_date" => $profile['planting_date'],
        "corn_variety" => $profile['corn_variety'],
        "soil_type" => $profile['soil_type'],
        "area_value" => $profile['area_value'],
        "area_unit" => $profile['area_unit'],
        "density" => $profile['planting_density'],
        "seeds_per_hole" => $profile['seeds_per_hole']
    ];

    $json_input = json_encode($input_data);
    $base64_input = base64_encode($json_input);

    $res = null;
    $output = '';
    $python_executable = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'env' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
    $python_script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'predict_growth.py';

    if (file_exists($python_executable) && file_exists($python_script)) {
        $version_output = shell_exec(escapeshellarg($python_executable) . ' --version 2>&1');
        $broken_launcher = is_string($version_output) && stripos($version_output, 'No Python at') !== false;

        if (!$broken_launcher) {
            $command = escapeshellarg($python_executable) . ' ' . escapeshellarg($python_script) . ' ' . escapeshellarg($base64_input) . ' 2>&1';
            $output = (string)shell_exec($command);
            $res = json_decode($output, true);
        }
    }

    if (!is_array($res) || !isset($res['status'])) {
        $res = predict_growth_php($input_data);
    }

    if ($res && $res['status'] === 'success') {
        $prediction = $res['prediction'];
    } else {
        $error = $res['message'] ?? "Failed to execute AI model.";
        if ($error === "Failed to execute AI model." && trim($output) !== '') {
            $error .= " Details: " . trim($output);
        }
    }
}

// 3. Load Market Prices (Prefer Individual)
$load_path = file_exists($individual_prices_path) ? $individual_prices_path : $global_prices_path;
$prices_json = file_get_contents($load_path);
$prices_data = json_decode($prices_json, true);
$market_prices = $prices_data['market_prices'];

$area_in_hectares = 0.0;
if ($profile) {
    $profileAreaValue = (float) ($profile['area_value'] ?? 0);
    $profileAreaUnit = strtolower((string) ($profile['area_unit'] ?? 'hectare'));
    if ($profileAreaValue > 0) {
        $area_in_hectares = $profileAreaUnit === 'sqm' ? ($profileAreaValue / 10000) : $profileAreaValue;
    }
}

// Calculate Financials
// (total_cost is already loaded from JSON at the top)

$est_income = 0;
$variety_key = 'other';
$variety_label = $profile['corn_type'] ?? 'Other';
if ($profile) {
    $v_lower = strtolower($profile['corn_type'] ?? ''); // Switched from corn_variety to corn_type

    // Improved matching logic based on corn_type strings
    if (strpos($v_lower, 'sweet') !== false) {
        if (strpos($v_lower, 'hybrid') !== false) $variety_key = 'sweet_hybrid';
        elseif (strpos($v_lower, 'native') !== false) $variety_key = 'sweet_native';
        elseif (strpos($v_lower, 'opv') !== false) $variety_key = 'sweet_opv';
        else $variety_key = 'sweet_hybrid';
    } elseif (strpos($v_lower, 'yellow') !== false) {
        if (strpos($v_lower, 'hybrid') !== false) $variety_key = 'yellow_hybrid';
        elseif (strpos($v_lower, 'feed') !== false) $variety_key = 'yellow_feeds';
        elseif (strpos($v_lower, 'native') !== false) $variety_key = 'yellow_native';
        else $variety_key = 'yellow_hybrid';
    } elseif (strpos($v_lower, 'white') !== false) {
        if (strpos($v_lower, 'field') !== false) $variety_key = 'white_field';
        elseif (strpos($v_lower, 'native') !== false) $variety_key = 'white_native';
        else $variety_key = 'white_field';
    } elseif (strpos($v_lower, 'glutinous') !== false || strpos($v_lower, 'waxy') !== false) {
        $variety_key = 'glutinous';
    } elseif (strpos($v_lower, 'popcorn') !== false) {
        $variety_key = 'popcorn';
    } elseif (strpos($v_lower, 'baby') !== false) {
        $variety_key = 'baby_corn';
    }
}

if ($prediction && $profile) {
    $details = $market_prices[$variety_key] ?? $market_prices['other'];
    $p_per_kg = $details['price_per_kg'];
    $est_income = ($prediction['total_yield_tons'] * 1000) * $p_per_kg; 
}
$confidence_percent = 0.0;
if ($prediction && isset($prediction['confidence'])) {
    $confidence_percent = (float) str_replace('%', '', (string) $prediction['confidence']);
}
$accuracy_level_label = get_accuracy_level_label($confidence_percent);
$net_profit = $est_income - $total_cost;
$yield_tons_per_ha = $prediction ? (float) ($prediction['yield_tons_ha'] ?? 0) : 0.0;
$harvest_formula_text = $yield_tons_per_ha > 0 && $area_in_hectares > 0
    ? number_format($yield_tons_per_ha, 2) . ' tons/ha × ' . number_format($area_in_hectares, 2) . ' ha'
    : 'Model yield × planted area';

// Analyze plan success
$plan_analysis = null;
if ($profile && $prediction) {
    $plan_analysis = analyze_plan_success($profile, $prediction, $confidence_percent);
}

$requested_action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
if ($requested_action === 'financial_snapshot') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => true,
        'estimated_income' => round((float) $est_income, 2),
        'total_cost' => round((float) $total_cost, 2),
        'net_profit' => round((float) $net_profit, 2),
        'price_per_kg' => round((float) ($p_per_kg ?? 0), 2),
        'variety_label' => (string) $variety_label,
        'prediction_ready' => $prediction ? true : false
    ]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AgriCorn | Intelligence Center</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #7fb685;
            --secondary: #ffe599;
            --dark: #2c3e2e;
            --dark-alt: #1e2f21;
            --bg: #fafdf7;
            --glass: rgba(255, 255, 255, 0.85);
            --line: rgba(127, 182, 133, 0.2);
        }
        body {
            background: var(--bg);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
            color: var(--dark);
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(127, 182, 133, 0.08), transparent 50%),
                radial-gradient(circle at 90% 80%, rgba(255, 229, 153, 0.12), transparent 50%);
            min-height: 100vh;
            letter-spacing: 0.3px;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(127, 182, 133, 0.15);
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 8px 24px rgba(37, 56, 40, 0.08);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .page-head {
            position: sticky;
            top: 0;
            z-index: 40;
            border-bottom: 1px solid var(--line);
            box-shadow: 0 4px 14px rgba(37, 56, 40, 0.09);
            background: linear-gradient(90deg, rgba(127, 182, 133, 0.4), rgba(127, 182, 133, 0.35), rgba(255, 229, 153, 0.4));
            backdrop-filter: blur(8px);
        }
        .head-inner,
        .page-inner {
            max-width: 1440px;
            margin: 0 auto;
            padding-left: 24px;
            padding-right: 24px;
        }
        .head-inner {
            padding-top: 14px;
            padding-bottom: 14px;
        }
        .page-inner {
            padding-top: 24px;
            padding-bottom: 40px;
        }
        .head-row {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .back-ghost {
            width: 44px;
            height: 44px;
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: #2f4a32;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.18s ease;
        }
        .back-ghost:hover {
            background: rgba(127, 182, 133, 0.2);
        }
        .back-ghost svg {
            width: 24px;
            height: 24px;
            fill: currentColor;
        }
        .page-title {
            margin: 0;
            font-weight: 600;
            font-size: 1.65rem;
            line-height: 1.3;
            color: var(--dark-alt);
            letter-spacing: -0.3px;
        }
        .page-sub {
            margin: 4px 0 0;
            font-size: 0.88rem;
            color: #5f6f63;
            font-weight: 400;
        }
        .content-actions {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 16px;
        }
        .theme-btn {
            border-radius: 12px;
            padding: 10px 18px;
            border: 1px solid rgba(127, 182, 133, 0.35);
            background: linear-gradient(90deg, rgba(127, 182, 133, 0.16), rgba(255, 229, 153, 0.18));
            color: #2f4a32;
            box-shadow: 0 6px 16px rgba(37, 56, 40, 0.08);
        }
        .theme-btn:hover {
            color: #214026;
            background: linear-gradient(90deg, rgba(127, 182, 133, 0.22), rgba(255, 229, 153, 0.24));
            border-color: rgba(127, 182, 133, 0.5);
        }
        .dashboard-card {
            background: linear-gradient(180deg, rgba(255,255,255,0.92), rgba(247,251,244,0.88));
            border: 1px solid rgba(127, 182, 133, 0.18);
            border-radius: 22px;
            box-shadow: 0 10px 26px rgba(37, 56, 40, 0.08);
            overflow: hidden;
        }
        .dashboard-card-centered {
            text-align: center;
        }
        .dashboard-card-centered .section-label,
        .dashboard-card-centered .dashboard-title,
        .dashboard-card-centered .dashboard-sub,
        .dashboard-card-centered .section-meta-row {
            margin-left: auto;
            margin-right: auto;
            justify-content: center;
        }
        .dashboard-title {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--dark-alt);
            line-height: 1.25;
            letter-spacing: -0.2px;
        }
        .dashboard-sub {
            margin: 8px 0 0;
            font-size: 0.9rem;
            color: #5f6f63;
            line-height: 1.5;
            font-weight: 400;
        }
        .section-meta-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .section-meta-row.centered,
        .finance-title-row.centered,
        .tile-head-row.centered,
        .factor-title-row.centered,
        .confidence-eye-row.centered,
        .confidence-head.centered,
        .harvest-head-row.centered {
            justify-content: center;
        }
        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border-radius: 999px;
            border: 1px solid rgba(127, 182, 133, 0.22);
            background: rgba(127, 182, 133, 0.08);
            color: #29522f;
            font-size: 0.75rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .status-chip.is-blue {
            border-color: rgba(62, 124, 214, 0.18);
            background: rgba(62, 124, 214, 0.08);
            color: #214c8c;
        }
        .status-chip.is-gold {
            border-color: rgba(211, 160, 36, 0.22);
            background: rgba(255, 229, 153, 0.22);
            color: #8a5f00;
        }
        .section-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 12px;
            padding: 6px 11px;
            border-radius: 8px;
            background: linear-gradient(135deg, rgba(127, 182, 133, 0.1), rgba(127, 182, 133, 0.08));
            color: #2d5a34;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            border: 1px solid rgba(127, 182, 133, 0.12);
        }
        .section-label i {
            font-size: 0.9rem;
        }
        .metric-stack {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
        }
        .metric-card {
            padding: 15px 15px 16px;
            border-radius: 16px;
            background: linear-gradient(180deg, #ffffff, #f7fbf4);
            border: 1px solid rgba(127, 182, 133, 0.16);
            box-shadow: 0 6px 14px rgba(37, 56, 40, 0.05);
            min-height: 96px;
        }
        .metric-card.metric-card-center {
            text-align: center;
        }
        .metric-card.metric-card-center .metric-label,
        .metric-card.metric-card-center .metric-value,
        .metric-card.metric-card-center .metric-note {
            text-align: center;
        }
        .metric-card-harvest {
            position: relative;
        }
        .harvest-head-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }
        .eye-toggle-btn {
            flex-shrink: 0;
            width: 34px;
            height: 34px;
            border-radius: 999px;
            border: 1px solid rgba(127, 182, 133, 0.22);
            background: rgba(127, 182, 133, 0.08);
            color: #2d5a34;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.18s ease;
        }
        .eye-toggle-btn:hover {
            background: rgba(127, 182, 133, 0.18);
            transform: translateY(-1px);
        }
        .eye-toggle-btn i {
            font-size: 0.95rem;
        }
        .harvest-breakdown {
            display: none;
        }
        .metric-label {
            margin: 0;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #5f6f63;
        }
        .metric-value {
            margin: 8px 0 0;
            font-size: 1.45rem;
            font-weight: 800;
            line-height: 1.1;
            color: #214026;
        }
        .metric-note {
            margin: 4px 0 0;
            font-size: 0.78rem;
            color: #6b7c6e;
        }
        .mini-eye-btn {
            width: 28px;
            height: 28px;
            border-radius: 999px;
            border: 1px solid rgba(127, 182, 133, 0.22);
            background: rgba(127, 182, 133, 0.08);
            color: #2d5a34;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.18s ease;
            flex-shrink: 0;
        }
        .mini-eye-btn:hover {
            background: rgba(127, 182, 133, 0.18);
        }
        .mini-eye-btn i {
            font-size: 0.82rem;
        }
        .tile-head-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }
        .tile-breakdown {
            display: none;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed rgba(127, 182, 133, 0.18);
            font-size: 0.8rem;
            color: #5d6f61;
            line-height: 1.45;
        }
        .tile-breakdown.is-open {
            display: block;
        }
        .factor-list {
            display: grid;
            gap: 10px;
        }
        .factor-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px;
            border-radius: 16px;
            background: linear-gradient(180deg, #ffffff, #f8fbf5);
            border: 1px solid rgba(127, 182, 133, 0.16);
            box-shadow: 0 6px 14px rgba(37, 56, 40, 0.05);
        }
        .factor-item.centered {
            justify-content: center;
            text-align: center;
        }
        .factor-copy .stat-label {
            margin-bottom: 4px;
        }
        .factor-copy .fw-bold {
            color: #27452c;
        }
        .factor-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .factor-detail {
            display: none;
        }
        .timing-compact {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }
        .confidence-wrap {
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 16px;
            border: 1px solid rgba(127, 182, 133, 0.18);
            background: rgba(127, 182, 133, 0.08);
        }
        .confidence-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }
        .confidence-title {
            margin: 0;
            font-size: 0.8rem;
            font-weight: 800;
            color: #305735;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .confidence-value {
            font-size: 0.82rem;
            font-weight: 800;
            color: #29522f;
        }
        .confidence-eye-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .confidence-detail {
            display: none;
        }
        .timing-card {
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(255, 250, 236, 0.96));
            border: 1px solid rgba(255, 229, 153, 0.3);
            border-radius: 22px;
            box-shadow: 0 10px 26px rgba(37, 56, 40, 0.08);
        }
        .finance-card {
            background: linear-gradient(180deg, rgba(255,255,255,0.95), rgba(247,251,244,0.94));
            border: 1px solid rgba(127, 182, 133, 0.18);
            border-radius: 22px;
            box-shadow: 0 10px 26px rgba(37, 56, 40, 0.08);
        }
        .finance-card-centered {
            text-align: center;
        }
        .finance-card-centered .section-label,
        .finance-card-centered .dashboard-title,
        .finance-card-centered .dashboard-sub,
        .finance-card-centered .section-meta-row,
        .finance-card-centered .note-box {
            margin-left: auto;
            margin-right: auto;
        }
        .finance-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }
        .finance-tile {
            padding: 16px;
            border-radius: 18px;
            background: linear-gradient(180deg, #ffffff, #f8fbf5);
            border: 1px solid rgba(127, 182, 133, 0.16);
            box-shadow: 0 6px 14px rgba(37, 56, 40, 0.05);
            min-height: 130px;
        }
        .finance-tile.centered {
            text-align: center;
        }
        .finance-tile.centered .finance-title-row,
        .finance-tile.centered .small,
        .finance-tile.centered .finance-value {
            justify-content: center;
            text-align: center;
        }
        .finance-value {
            margin: 10px 0 0;
            font-size: 1.55rem;
            font-weight: 800;
            line-height: 1.1;
            color: #214026;
        }
        .finance-value.text-success {
            color: #1f8b3f !important;
        }
        .finance-value.text-danger {
            color: #c0392b !important;
        }
        .note-box {
            margin-top: 16px;
            padding: 13px 15px;
            border-radius: 14px;
            border: 1px solid rgba(127, 182, 133, 0.16);
            background: rgba(127, 182, 133, 0.08);
            color: #4c5f50;
        }
        .finance-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .finance-detail {
            display: none;
        }
        .detail-modal-mask {
            position: fixed;
            inset: 0;
            background: rgba(22, 32, 24, 0.45);
            backdrop-filter: blur(4px);
            z-index: 90;
            display: none;
        }
        .detail-modal-mask.show {
            display: block;
        }
        .detail-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.98);
            width: min(92vw, 460px);
            border-radius: 20px;
            border: 1px solid rgba(127, 182, 133, 0.24);
            background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(247,251,244,0.98));
            box-shadow: 0 24px 48px rgba(37, 56, 40, 0.22);
            padding: 18px;
            z-index: 91;
            opacity: 0;
            pointer-events: none;
            transition: all 0.18s ease;
        }
        .detail-modal.show {
            opacity: 1;
            pointer-events: auto;
            transform: translate(-50%, -50%) scale(1);
        }
        .detail-modal-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding-bottom: 12px;
            margin-bottom: 12px;
            border-bottom: 1px solid rgba(127, 182, 133, 0.16);
        }
        .detail-modal-title {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 800;
            color: #27452c;
        }
        .detail-modal-sub {
            margin: 4px 0 0;
            color: #6b7c6e;
            font-size: 0.88rem;
            line-height: 1.45;
        }
        .detail-modal-body {
            color: #405044;
            font-size: 0.92rem;
            line-height: 1.6;
        }
        .detail-modal-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 16px;
        }
        .modal-shell .modal-content {
            border: 1px solid rgba(127, 182, 133, 0.22) !important;
            border-radius: 22px;
            background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(247,251,244,0.96));
            box-shadow: 0 20px 40px rgba(37, 56, 40, 0.16);
        }
        .modal-headline {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 800;
            color: #27452c;
        }
        .modal-subline {
            margin: 6px 0 0;
            color: #6b7c6e;
            font-size: 0.92rem;
        }
        .modal-divider {
            height: 1px;
            background: rgba(127, 182, 133, 0.18);
            margin: 16px 0 18px;
        }
        .active-variety-box {
            padding: 14px 16px;
            border-radius: 16px;
            border: 1px solid rgba(127, 182, 133, 0.2);
            background: linear-gradient(135deg, rgba(127, 182, 133, 0.12), rgba(255, 229, 153, 0.14));
        }
        .price-input .input-group-text,
        .price-input .form-control {
            border-color: rgba(127, 182, 133, 0.24);
        }
        .price-input .input-group-text {
            background: #fff;
            color: #5f6f63;
            border-radius: 12px 0 0 12px;
        }
        .price-input .form-control {
            border-radius: 0 12px 12px 0;
        }
        .modal-actions .btn {
            border-radius: 12px;
        }
        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1;
            margin: 10px 0;
        }
        .stat-label {
            color: #5f6f63;
            font-weight: 600;
            font-size: 0.85rem;
            letter-spacing: 0.2px;
        }
        .factor-card {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            border-radius: 15px;
            background: white;
            margin-bottom: 10px;
            border: 1px solid #eee;
        }
        .factor-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        @media (max-width: 767px) {
            .head-inner,
            .page-inner {
                padding-left: 14px;
                padding-right: 14px;
            }
            .page-title {
                font-size: 1.3rem;
            }
            .metric-stack,
            .finance-grid {
                grid-template-columns: minmax(0, 1fr);
            }
            .content-actions {
                justify-content: stretch;
            }
            .content-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<header class="page-head">
    <div class="head-inner">
        <div class="head-row">
            <button class="back-ghost" type="button" aria-label="Back" onclick="window.location.href='farmer_dashboard.php?view=features';">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 5-7 7 7 7 1.5-1.5-5.5-5.5 5.5-5.5z"></path></svg>
            </button>
            <div>
                <h1 class="page-title">ML Growth Prediction</h1>
                <p class="page-sub">High-precision regression analytics for yield and harvest optimization.</p>
            </div>
        </div>
    </div>
</header>

<div class="page-inner">
    <?php if (!$profile): ?>
        <div class="glass-card text-center py-5">
            <i class="bi bi-file-earmark-plus display-1 text-muted"></i>
            <h3 class="mt-3 fw-bold">No Planting Profile Found</h3>
            <p class="text-muted">You need to complete your planting profile first to generate AI predictions.</p>
            <a href="corn_planting_profile.php" class="btn btn-success px-4 py-2 mt-2" style="border-radius: 10px;">Create Profile</a>
        </div>
    <?php elseif ($error): ?>
        <div class="alert alert-danger glass-card">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>AI Model Error:</strong> <?= htmlspecialchars($error) ?>
        </div>
    <?php else: ?>
        <div class="content-actions" style="justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
            <div style="display: inline-flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 12px; border: 1px solid rgba(62, 124, 214, 0.2); background: linear-gradient(135deg, rgba(62, 124, 214, 0.1), rgba(127, 182, 133, 0.08)); min-width: 220px;">
                <i class="bi bi-cpu-fill" style="color: #3e7cd6; font-size: 1rem;"></i>
                <div style="line-height: 1.2;">
                    <div style="font-size: 0.68rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: #5f6f63;">ML Accuracy</div>
                    <div style="font-size: 1rem; font-weight: 700; color: #2c4f8e;"><?= htmlspecialchars($prediction['confidence']) ?></div>
                </div>
                <div class="progress" style="height: 6px; width: 90px; border-radius: 6px; background: rgba(62, 124, 214, 0.14); overflow: hidden; margin-left: auto;">
                    <div class="progress-bar" style="background: #3e7cd6; width: <?= $prediction['confidence'] ?>; border-radius: 6px;"></div>
                </div>
            </div>
            <button class="btn theme-btn fw-bold d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#priceSettingsModal" type="button">
                <i class="bi bi-gear-fill"></i> Adjust Prices
            </button>
        </div>
        
        <!-- Main Prediction Overview -->
        <div class="glass-card dashboard-card dashboard-card-centered mb-4" style="background: linear-gradient(180deg, rgba(255,255,255,0.97), rgba(247,251,244,0.94)); border: 1px solid rgba(127, 182, 133, 0.16); padding: 32px; position: relative; overflow: hidden;">
            <div style="position: absolute; inset: 0; background: radial-gradient(circle at top right, rgba(127, 182, 133, 0.09), transparent 34%), radial-gradient(circle at bottom left, rgba(62, 124, 214, 0.06), transparent 30%); pointer-events: none;"></div>
            <div style="position: relative; z-index: 1;">
            <div class="section-label"><i class="bi bi-graph-up-arrow"></i> Prediction</div>
            <h5 class="dashboard-title" style="font-size: 1.4rem; margin-bottom: 6px;">Yield Forecast Summary</h5>
            <p class="dashboard-sub" style="margin-bottom: 24px; max-width: 720px; margin-left: auto; margin-right: auto;">AI-based estimate derived from your planting profile, harvest cycle, and model analysis.</p>
            
            <div class="row g-3">
                <!-- Primary Yield Metric -->
                <div class="col-md-4">
                    <div style="height: 100%; padding: 22px; border-radius: 16px; background: linear-gradient(180deg, rgba(31, 139, 63, 0.08), rgba(255, 255, 255, 0.92)); border: 1px solid rgba(31, 139, 63, 0.14); box-shadow: 0 10px 22px rgba(37, 56, 40, 0.05); display: flex; flex-direction: column;">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px;">
                            <div class="stat-label" style="font-size: 0.72rem; margin: 0; text-transform: uppercase; letter-spacing: 0.12em;">Estimated Total Yield</div>
                            <div style="width: 32px; height: 32px; border-radius: 10px; background: rgba(31, 139, 63, 0.12); color: #1f8b3f; display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-basket-fill"></i>
                            </div>
                        </div>
                        <div style="font-size: 2.8rem; font-weight: 700; color: #1f8b3f; line-height: 1; letter-spacing: -0.03em;">
                            <?= $prediction['total_yield_tons'] ?>
                        </div>
                        <div style="font-size: 0.8rem; font-weight: 500; color: #5f6f63; margin-top: 4px;">Metric tons</div>
                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(127, 182, 133, 0.1); font-size: 0.75rem; color: #5f6f63; font-weight: 500; line-height: 1.45;">
                            Equivalent to <?= $prediction['total_yield_sacks'] ?> sacks (50 kg each)
                        </div>
                    </div>
                </div>
                
                <!-- Yield Efficiency -->
                <div class="col-md-4">
                    <div style="height: 100%; padding: 22px; border-radius: 16px; background: linear-gradient(180deg, rgba(62, 124, 214, 0.08), rgba(255, 255, 255, 0.92)); border: 1px solid rgba(62, 124, 214, 0.14); box-shadow: 0 10px 22px rgba(37, 56, 40, 0.05); display: flex; flex-direction: column;">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px;">
                            <div class="stat-label" style="font-size: 0.72rem; margin: 0; text-transform: uppercase; letter-spacing: 0.12em;">Expected Yield per Hectare</div>
                            <div style="width: 32px; height: 32px; border-radius: 10px; background: rgba(62, 124, 214, 0.12); color: #3e7cd6; display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-bar-chart-fill"></i>
                            </div>
                        </div>
                        <div style="font-size: 2.8rem; font-weight: 700; color: #3e7cd6; line-height: 1; letter-spacing: -0.03em;">
                            <?= $prediction['yield_tons_ha'] ?>
                        </div>
                        <div style="font-size: 0.8rem; font-weight: 500; color: #5f6f63; margin-top: 4px;">Metric tons per hectare</div>
                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(62, 124, 214, 0.1); font-size: 0.75rem; color: #5f6f63; font-weight: 500; line-height: 1.45;">
                            Per-hectare productivity rate used in the forecast
                        </div>
                    </div>
                </div>

                <!-- Harvest Timing (Merged Into Prediction) -->
                <div class="col-md-4">
                    <div style="height: 100%; padding: 22px; border-radius: 16px; background: linear-gradient(180deg, rgba(212, 166, 0, 0.1), rgba(255, 255, 255, 0.92)); border: 1px solid rgba(212, 166, 0, 0.16); box-shadow: 0 10px 22px rgba(37, 56, 40, 0.05); display: flex; flex-direction: column;">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px;">
                            <div class="stat-label" style="font-size: 0.72rem; margin: 0; text-transform: uppercase; letter-spacing: 0.12em;">Forecast Harvest Date</div>
                            <div style="width: 32px; height: 32px; border-radius: 10px; background: rgba(212, 166, 0, 0.14); color: #8a5f00; display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-calendar-event"></i>
                            </div>
                        </div>
                        <div style="font-size: 1.8rem; font-weight: 700; color: #8a5f00; line-height: 1.15; letter-spacing: -0.02em;">
                            <?= date('M d, Y', strtotime($prediction['harvest_date'])) ?>
                        </div>
                        <div style="font-size: 0.8rem; font-weight: 500; color: #5f6f63; margin-top: 4px;">Estimated harvest date</div>
                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(212, 166, 0, 0.15); font-size: 0.75rem; color: #5f6f63; font-weight: 600; line-height: 1.45;">
                            <i class="bi bi-hourglass-split me-1"></i> <?= $prediction['days_to_maturity'] ?>-day maturity cycle
                        </div>
                    </div>
                </div>
                
            </div>
            </div>
        </div>

        <div class="row g-4 justify-content-center">
            <!-- Regression Factors -->
            <div class="col-lg-6">
                <div class="glass-card dashboard-card" style="padding: 24px; background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(247,251,244,0.95)); border: 1px solid rgba(127, 182, 133, 0.16);">
                    <div class="section-label"><i class="bi bi-sliders"></i> Model</div>
                    <h5 class="dashboard-title" style="margin-bottom: 6px;">Regression Analysis</h5>
                    <p class="dashboard-sub" style="font-size: 0.85rem; margin-bottom: 18px;">Environmental factors contributing to the forecasted yield</p>
                    
                    <div style="display: grid; gap: 12px;">
                        <div style="padding: 16px; border-radius: 12px; background: linear-gradient(90deg, rgba(31, 139, 63, 0.08), rgba(255,255,255,0.72)); border: 1px solid rgba(31, 139, 63, 0.12); display: flex; align-items: center; justify-content: space-between; box-shadow: 0 6px 14px rgba(37, 56, 40, 0.04);">
                            <div style="display: flex; align-items: center; gap: 12px; flex: 1;">
                                <div style="width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, rgba(31, 139, 63, 0.15), rgba(31, 139, 63, 0.08)); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: #1f8b3f;">
                                    <i class="bi bi-droplet-fill"></i>
                                </div>
                                <div>
                                    <div style="font-size: 0.88rem; font-weight: 600; color: #27452c;">Soil Efficiency</div>
                                    <div style="font-size: 0.74rem; color: #5f6f63; font-weight: 500;">Soil condition contribution to yield</div>
                                </div>
                            </div>
                            <div style="font-size: 1.4rem; font-weight: 700; color: #1f8b3f;">
                                <?= round($prediction['factors']['soil_coefficient'] * 100) ?>%
                            </div>
                        </div>
                        
                        <div style="padding: 16px; border-radius: 12px; background: linear-gradient(90deg, rgba(212, 166, 0, 0.08), rgba(255,255,255,0.72)); border: 1px solid rgba(212, 166, 0, 0.12); display: flex; align-items: center; justify-content: space-between; box-shadow: 0 6px 14px rgba(37, 56, 40, 0.04);">
                            <div style="display: flex; align-items: center; gap: 12px; flex: 1;">
                                <div style="width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, rgba(212, 166, 0, 0.15), rgba(212, 166, 0, 0.08)); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: #d4a600;">
                                    <i class="bi bi-flower1"></i>
                                </div>
                                <div>
                                    <div style="font-size: 0.88rem; font-weight: 600; color: #27452c;">Variety Potential</div>
                                    <div style="font-size: 0.74rem; color: #5f6f63; font-weight: 500;">Corn variety suitability and yield impact</div>
                                </div>
                            </div>
                            <div style="font-size: 1.4rem; font-weight: 700; color: #d4a600;">
                                <?= round($prediction['factors']['variety_coefficient'] * 100) ?>%
                            </div>
                        </div>
                        
                        <div style="padding: 16px; border-radius: 12px; background: linear-gradient(90deg, rgba(32, 168, 82, 0.08), rgba(255,255,255,0.72)); border: 1px solid rgba(32, 168, 82, 0.12); display: flex; align-items: center; justify-content: space-between; box-shadow: 0 6px 14px rgba(37, 56, 40, 0.04);">
                            <div style="display: flex; align-items: center; gap: 12px; flex: 1;">
                                <div style="width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, rgba(32, 168, 82, 0.15), rgba(32, 168, 82, 0.08)); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: #20a852;">
                                    <i class="bi bi-grid-3x3-gap-fill"></i>
                                </div>
                                <div>
                                    <div style="font-size: 0.88rem; font-weight: 600; color: #27452c;">Density Efficiency</div>
                                    <div style="font-size: 0.74rem; color: #5f6f63; font-weight: 500;">Plant population efficiency within the forecast range</div>
                                </div>
                            </div>
                            <div style="font-size: 1.4rem; font-weight: 700; color: #20a852;">
                                <?= round($prediction['factors']['density_efficiency'] * 100) ?>%
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Financial Projections -->
            <div class="col-lg-6">
                <div class="glass-card finance-card" style="padding: 24px; background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(247,251,244,0.95)); border: 1px solid rgba(127, 182, 133, 0.16);">
                    <div class="section-label"><i class="bi bi-cash-coin"></i> Financials</div>
                    <h5 class="dashboard-title" style="margin-bottom: 6px;">Financial Analysis</h5>
                    <p class="dashboard-sub" style="font-size: 0.85rem; margin-bottom: 18px;">Expected revenue and profit derived from the yield forecast</p>
                    
                    <div style="display: grid; gap: 12px;">
                        <div style="padding: 16px; border-radius: 12px; background: linear-gradient(90deg, rgba(62, 124, 214, 0.08), rgba(255,255,255,0.72)); border: 1px solid rgba(62, 124, 214, 0.12); display: flex; align-items: center; justify-content: space-between; box-shadow: 0 6px 14px rgba(37, 56, 40, 0.04);">
                            <div style="display: flex; align-items: center; gap: 12px; flex: 1;">
                                <div style="width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, rgba(62, 124, 214, 0.15), rgba(62, 124, 214, 0.08)); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: #3e7cd6;">
                                    <i class="bi bi-cash-stack"></i>
                                </div>
                                <div>
                                    <div style="font-size: 0.88rem; font-weight: 600; color: #27452c;">Estimated Gross Income</div>
                                    <div style="font-size: 0.74rem; color: #5f6f63; font-weight: 500;">@ ₱<?= number_format($p_per_kg, 2) ?>/kg • <?= htmlspecialchars($variety_label) ?></div>
                                </div>
                            </div>
                            <div style="font-size: 1.4rem; font-weight: 700; color: #3e7cd6; white-space: nowrap;">
                                ₱<?= number_format($est_income, 0) ?>
                            </div>
                        </div>

                        <div style="padding: 16px; border-radius: 12px; background: linear-gradient(90deg, rgba(192, 57, 43, 0.08), rgba(255,255,255,0.72)); border: 1px solid rgba(192, 57, 43, 0.12); display: flex; align-items: center; justify-content: space-between; box-shadow: 0 6px 14px rgba(37, 56, 40, 0.04);">
                            <div style="display: flex; align-items: center; gap: 12px; flex: 1;">
                                <div style="width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, rgba(192, 57, 43, 0.15), rgba(192, 57, 43, 0.08)); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: #c0392b;">
                                    <i class="bi bi-wallet2"></i>
                                </div>
                                <div>
                                    <div style="font-size: 0.88rem; font-weight: 600; color: #27452c;">Total Costs</div>
                                    <div style="font-size: 0.74rem; color: #5f6f63; font-weight: 500;">Current recorded planting expenses</div>
                                </div>
                            </div>
                            <div style="font-size: 1.4rem; font-weight: 700; color: #c0392b; white-space: nowrap;">
                                ₱<?= number_format($total_cost, 0) ?>
                            </div>
                        </div>

                        <div style="padding: 16px; border-radius: 12px; background: linear-gradient(90deg, rgba(32, 168, 82, 0.08), rgba(255,255,255,0.72)); border: 1px solid rgba(32, 168, 82, 0.12); display: flex; align-items: center; justify-content: space-between; box-shadow: 0 6px 14px rgba(37, 56, 40, 0.04);">
                            <div style="display: flex; align-items: center; gap: 12px; flex: 1;">
                                <div style="width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, rgba(32, 168, 82, 0.15), rgba(32, 168, 82, 0.08)); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: #20a852;">
                                    <i class="bi bi-graph-up-arrow"></i>
                                </div>
                                <div>
                                    <div style="font-size: 0.88rem; font-weight: 600; color: #27452c;">Projected Net Profit</div>
                                    <div style="font-size: 0.74rem; color: #5f6f63; font-weight: 500;">Estimated gross income minus total costs</div>
                                </div>
                            </div>
                            <div style="font-size: 1.4rem; font-weight: 700; color: #20a852; white-space: nowrap;">
                                ₱<?= number_format($net_profit, 0) ?>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>

            <!-- Plan Success Analysis Card -->
            <?php if ($plan_analysis): ?>
            <div class="col-lg-12">
                <div class="glass-card dashboard-card" style="background: linear-gradient(180deg, rgba(255,255,255,0.95), rgba(250,253,247,0.96)); border: 1px solid rgba(127, 182, 133, 0.15); padding: 28px;">
                    <div class="section-label"><i class="bi bi-graph-up"></i> Assessment</div>
                    <h5 class="dashboard-title" style="margin-bottom: 6px;">Planting Profile Evaluation</h5>
                    <p class="dashboard-sub" style="font-size: 0.85rem; margin-bottom: 22px;">Success likelihood based on environmental parameters and operational data</p>
                    
                    <div class="row g-4">
                        <!-- Success Score - Prominent -->
                        <div class="col-md-5">
                            <div style="padding: 24px; border-radius: 14px; background: linear-gradient(135deg, rgba(31, 139, 63, 0.12), rgba(255, 229, 153, 0.08)); border: 2px solid rgba(31, 139, 63, 0.22); text-align: center;">
                                <div class="stat-label" style="font-size: 0.72rem; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.1em;">Success Score</div>
                                <div style="font-size: 4rem; font-weight: 700; color: #1f8b3f; line-height: 1; margin-bottom: 8px;">
                                    <?= $plan_analysis['percentage'] ?>%
                                </div>
                                <div style="font-size: 1.1rem; font-weight: 700; color: var(--dark-alt); margin-bottom: 16px;">
                                    <?= htmlspecialchars($plan_analysis['level']) ?>
                                </div>
                                <div class="progress" style="height: 10px; border-radius: 8px; background: rgba(127, 182, 133, 0.12); overflow: hidden; margin-bottom: 12px;">
                                    <div class="progress-bar" style="background: linear-gradient(90deg, #1f8b3f, #20a852); width: <?= $plan_analysis['percentage'] ?>%; border-radius: 8px; transition: width 0.5s ease;"></div>
                                </div>
                                <div style="font-size: 0.78rem; color: #5f6f63; font-weight: 500;">
                                    <strong><?= $plan_analysis['score'] ?></strong> / <?= $plan_analysis['max_score'] ?> points
                                </div>
                            </div>
                        </div>

                        <!-- Factors Breakdown -->
                        <div class="col-md-7">
                            <div style="display: grid; gap: 10px;">
                                <?php 
                                // Generate explanations for each factor
                                $factor_explanations = [];
                                foreach ($plan_analysis['factors'] as $factor) {
                                    $name = $factor['name'];
                                    $value = $factor['value'];
                                    $max = $factor['max'];
                                    $percentage = ($value / $max) * 100;
                                    $explanation = '';
                                    
                                    if (strpos($name, 'Corn Type') !== false) {
                                        $corn_type = htmlspecialchars($profile['corn_type'] ?? 'Unknown');
                                        if ($value >= 18) $explanation = "$corn_type gets 18/20 (best choice)";
                                        elseif ($value >= 16) $explanation = "$corn_type gets 16/20 (good choice, ~80%)";
                                        elseif ($value >= 14) $explanation = "$corn_type gets 14/20 (acceptable, ~70%)";
                                        else $explanation = "$corn_type: other type";
                                    }
                                    elseif (strpos($name, 'Density') !== false) {
                                        $density = number_format((float)($profile['planting_density'] ?? 0), 0);
                                        if ($value >= 20) $explanation = "Density $density/ha is optimal (45K-75K range)";
                                        elseif ($value >= 16) $explanation = "Density $density/ha acceptable (35K-85K range)";
                                        elseif ($value == 8) $explanation = "⚠️ Density $density/ha is critically low (need 45K-75K)";
                                        else $explanation = "Density $density/ha needs adjustment";
                                    }
                                    elseif (strpos($name, 'Seeds Per Hole') !== false) {
                                        $seeds = (int)($profile['seeds_per_hole'] ?? 1);
                                        if ($value >= 15) $explanation = "$seeds seeds/hole is optimal (1-2 range) ✓";
                                        elseif ($value >= 10) $explanation = "$seeds seeds/hole acceptable";
                                        else $explanation = "$seeds seeds/hole too many (reduce to 1-2)";
                                    }
                                    elseif (strpos($name, 'Soil Type') !== false) {
                                        $soil = htmlspecialchars($profile['soil_type'] ?? 'Unknown');
                                        if ($value >= 15) $explanation = "$soil is ideal loam soil ✓";
                                        elseif ($value >= 11) $explanation = "$soil: acceptable but needs more care than loam";
                                        else $explanation = "$soil needs improvement or amendments";
                                    }
                                    elseif (strpos($name, 'Model Confidence') !== false) {
                                        $explanation = "System confidence: " . round($percentage) . "% (all data provided ✓)";
                                    }
                                    elseif (strpos($name, 'Area Scale') !== false) {
                                        $area = number_format($area_in_hectares, 2);
                                        if ($value >= 10) $explanation = "Area $area ha is optimal for quality management ✓";
                                        elseif ($value >= 7.5) $explanation = "Area $area ha acceptable";
                                        else $explanation = "Area $area ha too small for efficient management";
                                    }
                                    
                                    $factor_explanations[$name] = ['explanation' => $explanation, 'percentage' => round($percentage), 'factor' => $factor];
                                }
                                
                                foreach ($factor_explanations as $name => $data):
                                    $factor = $data['factor'];
                                    $percentage = ($factor['value'] / $factor['max']) * 100;
                                    $color = $percentage >= 80 ? '#1f8b3f' : ($percentage >= 60 ? '#d4a600' : '#c0392b');
                                    $icon = $percentage >= 80 ? '✓' : ($percentage >= 60 ? '⚠' : '✕');
                                ?>
                                <div style="padding: 12px 14px; border-radius: 12px; background: rgba(255,255,255,0.6); border: 1px solid rgba(127, 182, 133, 0.1); cursor: help;" title="<?= htmlspecialchars($data['explanation']) ?>">
                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                                        <div class="stat-label" style="font-size: 0.78rem; margin: 0; font-weight: 600;"><?= htmlspecialchars($factor['name']) ?></div>
                                        <span style="font-weight: 700; color: <?= $color ?>; font-size: 0.88rem;"><?= $icon ?> <?= round($percentage) ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 6px; border-radius: 6px; background: rgba(127, 182, 133, 0.1); overflow: hidden;">
                                        <div class="progress-bar" style="background: <?= $color ?>; width: <?= $percentage ?>%; border-radius: 6px;"></div>
                                    </div>
                                    <div style="font-size: 0.72rem; color: #6a7f6e; margin-top: 4px; line-height: 1.3; font-weight: 500;">
                                        <?= htmlspecialchars($data['explanation']) ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Recommendations -->
                    <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid rgba(127, 182, 133, 0.12);">
                        <h6 style="font-weight: 700; color: var(--dark-alt); margin-bottom: 12px; font-size: 0.95rem;">
                            <i class="bi bi-lightbulb-fill" style="color: #d4a600; margin-right: 8px;"></i> Recommendations
                        </h6>
                        <div style="display: grid; gap: 8px;">
                            <?php foreach ($plan_analysis['recommendations'] as $rec): ?>
                            <div style="padding: 10px 12px; border-radius: 10px; background: rgba(127, 182, 133, 0.08); border-left: 3px solid rgba(127, 182, 133, 0.25); font-size: 0.83rem; color: #4c5f50; line-height: 1.5; font-weight: 500;">
                                <?= htmlspecialchars($rec) ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Profile Summary -->
                    <div style="margin-top: 16px; padding: 12px 13px; border-radius: 10px; background: linear-gradient(135deg, rgba(62, 124, 214, 0.1), rgba(62, 124, 214, 0.06)); border: 1px solid rgba(62, 124, 214, 0.12); font-size: 0.77rem; color: #5f6f63; line-height: 1.5; font-weight: 500;">
                        <strong>Profile Data:</strong> <?= htmlspecialchars($profile['corn_type'] ?? 'N/A') ?> (<?= htmlspecialchars($profile['corn_variety'] ?? '—') ?>) • <?= number_format($area_in_hectares, 2) ?> ha • <?= number_format((float)($profile['planting_density'] ?? 0), 0) ?> plants/ha • <?= (int)($profile['seeds_per_hole'] ?? 0) ?> seeds/hole • <?= htmlspecialchars($profile['soil_type'] ?? 'N/A') ?> soil
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Price Settings Modal -->
<div class="modal fade modal-shell" id="priceSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content p-2">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-headline"><i class="bi bi-gear-fill me-2"></i> Price Configuration</h5>
                    <p class="modal-subline">Set the current market price for your planted variety to update your financial projections.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="" method="POST">
                <input type="hidden" name="action" value="save_prices">
                <div class="modal-body pt-0">
                    <div class="modal-divider"></div>
                    <?php 
                    // Only show the price input for the variety currently at the profile
                    $active_details = $market_prices[$variety_key] ?? $market_prices['other'];
                    ?>
                    <div class="mb-4">
                        <div class="active-variety-box mb-3">
                            <span class="stat-label small d-block mb-1">ACTIVE VARIETY</span>
                            <span class="fw-bold text-success h5"><?= htmlspecialchars($profile['corn_type'] ?? 'N/A') ?></span>
                        </div>
                        
                        <div class="mb-3 price-input">
                            <label class="form-label fw-semibold">Current Market Price (₱/kg)</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text">₱</span>
                                <input type="number" step="0.5" name="<?= $variety_key ?>_price" class="form-control fw-bold" value="<?= $active_details['price_per_kg'] ?>" required>
                            </div>
                            <div class="form-text small mt-2">Updating this price will recalculate your Gross Income and Net Profit instantly.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 modal-actions">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success px-4">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Success Toast -->
<?php if ($update_success): ?>
<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="successToast" class="toast show align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4000">
        <div class="d-flex">
            <div class="toast-body">
                <i class="bi bi-check-circle-fill me-2"></i> Market prices updated successfully!
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>
<script>
    setTimeout(function() {
        var toastEl = document.getElementById('successToast');
        var toast = new bootstrap.Toast(toastEl);
        toast.hide();
    }, 4000);
</script>
<?php endif; ?>

<div class="detail-modal-mask" id="detailModalMask"></div>
<div class="detail-modal" id="detailModal" role="dialog" aria-modal="true" aria-labelledby="detailModalTitle">
    <div class="detail-modal-head">
        <div>
            <h3 class="detail-modal-title" id="detailModalTitle">Details</h3>
            <p class="detail-modal-sub" id="detailModalSub">Explanation</p>
        </div>
        <button class="btn-close" type="button" id="closeDetailModalBtn" aria-label="Close"></button>
    </div>
    <div class="detail-modal-body" id="detailModalBody"></div>
    <div class="detail-modal-actions">
        <button class="btn btn-success btn-sm" type="button" id="detailModalOkBtn">Close</button>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function () {
        var buttons = document.querySelectorAll('[data-eye-target], #harvestBreakdownToggle');
        var modalMask = document.getElementById('detailModalMask');
        var modal = document.getElementById('detailModal');
        var modalTitle = document.getElementById('detailModalTitle');
        var modalSub = document.getElementById('detailModalSub');
        var modalBody = document.getElementById('detailModalBody');
        var closeBtn = document.getElementById('closeDetailModalBtn');
        var okBtn = document.getElementById('detailModalOkBtn');

        function closeModal() {
            modalMask.classList.remove('show');
            modal.classList.remove('show');
        }

        function openModal(title, subline, bodyHtml) {
            modalTitle.textContent = title;
            modalSub.textContent = subline;
            modalBody.innerHTML = bodyHtml;
            modalMask.classList.add('show');
            modal.classList.add('show');
        }

        function readTargetContent(targetId) {
            if (targetId === 'harvestBreakdown') {
                return {
                    title: 'Expected Total Harvest',
                    subline: 'How the harvest estimate was calculated',
                    bodyHtml: '<div style="display:grid; gap:10px;"><div class="harvest-breakdown-item"><span>Yield per hectare</span><strong>' + '<?= number_format($yield_tons_per_ha, 2) ?>' + ' tons/ha</strong></div><div class="harvest-breakdown-item"><span>Planted area</span><strong>' + '<?= number_format($area_in_hectares, 2) ?>' + ' ha</strong></div><div class="harvest-breakdown-item"><span>Formula</span><strong>' + '<?= htmlspecialchars($harvest_formula_text, ENT_QUOTES) ?>' + '</strong></div></div>'
                };
            }

            var target = document.getElementById(targetId);
            var text = target ? target.textContent.trim() : '';
            var labels = {
                sacksBreakdown: ['Total Sacks', 'The projected harvest converted to 50kg sacks for handling and planning.'],
                yieldBreakdown: ['Tons / Hectare', 'This is the yield efficiency per hectare before multiplying by the planted area.'],
                soilFactorDetail: ['Soil Efficiency', 'Based on the soil type in your planting profile and how it affects yield potential.'],
                varietyFactorDetail: ['Variety Potential', 'This reflects the expected strength of the planted corn variety in the prediction model.'],
                densityFactorDetail: ['Density Efficiency', 'This shows how the planting density affects the final predicted yield.'],
                confidenceDetail: ['ML Confidence Score', 'This is the model confidence score based on the completeness of your planting profile inputs.'],
                grossIncomeDetail: ['Estimated Gross Income', 'Gross income is computed from the predicted total harvest multiplied by the current market price per kilogram.'],
                costDetail: ['Total Input Costs', 'This is the sum of your saved costing rows, including seeds, labor, and additional inputs.'],
                profitDetail: ['Projected Net Profit', 'Net profit is estimated gross income minus total input costs.']
            };

            var pair = labels[targetId] || [targetId, text];
            return {
                title: pair[0],
                subline: 'Quick breakdown',
                bodyHtml: '<div style="color:#5d6f61; line-height:1.6;">' + pair[1] + '</div>'
            };
        }

        buttons.forEach(function (button) {
            var targetId = button.getAttribute('data-eye-target');
            if (!targetId && button.id === 'harvestBreakdownToggle') {
                targetId = 'harvestBreakdown';
            }

            if (!targetId) {
                return;
            }

            button.addEventListener('click', function () {
                var content = readTargetContent(targetId);
                openModal(content.title, content.subline, content.bodyHtml);
            });
        });

        modalMask.addEventListener('click', closeModal);
        closeBtn.addEventListener('click', closeModal);
        okBtn.addEventListener('click', closeModal);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });
    })();
</script>
</body>
</html>
