<?php
session_start();

if (!isset($_SESSION["users_id"])) {
    header("Location: ../login.php");
    exit;
}

$userId = (int) ($_SESSION["users_id"] ?? 0);
$role = strtolower(trim((string) ($_SESSION["role"] ?? "")));
$isAdmin = $userId === 1 || $role === "admin";

if (!$isAdmin) {
    header("Location: ../Farmers Dashboard/farmer_dashboard.php");
    exit;
}

$totalFarmers = 0;
$activeUsers = 0;
$newFarmersThisMonth = 0;
$engagementRateLabel = "0% engagement rate";
$guideModuleTotal = 0;
$growthTrendLabels = [];
$growthTrendUserCounts = [];
$growthTrendGuideCounts = [];
$growthTrendMonths = [];
$mostPlantedLocationLabels = [];
$mostPlantedLocationCounts = [];
$recentLoginItems = [];
$topGuideItems = [];
$mostPestScanLabels = [];
$mostPestScanCounts = [];
$mostPlantMonthLabels = [];
$mostPlantMonthCounts = [];
$mostCornTypeLabels = [];
$mostCornTypeCounts = [];
$mostCornVarietyLabels = [];
$mostCornVarietyCounts = [];

if (!function_exists('formatLoginTimeAgo')) {
    function formatLoginTimeAgo($datetimeValue)
    {
        $timestamp = strtotime((string) $datetimeValue);
        if ($timestamp === false) {
            return 'Recently';
        }

        $diffSeconds = max(0, time() - $timestamp);
        if ($diffSeconds < 60) {
            return 'Just now';
        }

        $minutes = (int) floor($diffSeconds / 60);
        if ($minutes < 60) {
            return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago';
        }

        $hours = (int) floor($minutes / 60);
        if ($hours < 24) {
            return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
        }

        $days = (int) floor($hours / 24);
        if ($days < 7) {
            return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
        }

        return date('M j, Y', $timestamp);
    }
}

if (!function_exists('buildUserLoginLabel')) {
    function buildUserLoginLabel(array $row, array $availableColumns)
    {
        $preferredFields = ['full_name', 'name', 'username', 'email'];
        foreach ($preferredFields as $fieldName) {
            if (isset($availableColumns[$fieldName])) {
                $value = trim((string) ($row[$fieldName] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        $firstName = isset($availableColumns['first_name']) ? trim((string) ($row['first_name'] ?? '')) : '';
        $lastName = isset($availableColumns['last_name']) ? trim((string) ($row['last_name'] ?? '')) : '';
        $combinedName = trim($firstName . ' ' . $lastName);
        if ($combinedName !== '') {
            return $combinedName;
        }

        $usersId = (int) ($row['users_id'] ?? 0);
        return $usersId > 0 ? 'User #' . $usersId : 'Unknown user';
    }
}

try {
    require_once __DIR__ . "/../data/db_connect.php";

    $availableColumns = [];
    $columnResult = $conn->query("SHOW COLUMNS FROM users");
    while ($columnRow = $columnResult->fetch_assoc()) {
        $fieldName = (string) ($columnRow["Field"] ?? "");
        if ($fieldName !== "") {
            $availableColumns[$fieldName] = true;
        }
    }

    $selectColumns = ["users_id", "date_created"];
    if (isset($availableColumns["status"])) {
        $selectColumns[] = "status";
    }
    if (isset($availableColumns["last_login_date"])) {
        $selectColumns[] = "last_login_date";
    }

    $sql = "SELECT " . implode(", ", $selectColumns) . " FROM users WHERE LOWER(TRIM(role)) = 'farmer'";
    $result = $conn->query($sql);

    $inactiveThresholdTimestamp = time() - (7 * 24 * 60 * 60);
    $currentYearMonth = date("Y-m");

    while ($row = $result->fetch_assoc()) {
        $totalFarmers += 1;

        $createdDate = trim((string) ($row["date_created"] ?? ""));
        if ($createdDate !== "" && strpos($createdDate, $currentYearMonth) === 0) {
            $newFarmersThisMonth += 1;
        }

        $statusRaw = strtolower(trim((string) ($row["status"] ?? "active")));
        $isMarkedInactive = in_array($statusRaw, ["inactive", "disabled", "deactivated", "suspended", "blocked", "archived"], true);

        $lastLoginDate = trim((string) ($row["last_login_date"] ?? ""));
        $referenceDate = $lastLoginDate !== "" ? $lastLoginDate : $createdDate;
        $isInactiveByLoginGap = false;

        if ($referenceDate !== "") {
            $referenceTimestamp = strtotime($referenceDate);
            if ($referenceTimestamp !== false) {
                $isInactiveByLoginGap = $referenceTimestamp <= $inactiveThresholdTimestamp;
            }
        }

        if (!($isMarkedInactive || $isInactiveByLoginGap)) {
            $activeUsers += 1;
        }
    }

    if ($totalFarmers > 0) {
        $engagementRateLabel = (string) round(($activeUsers / $totalFarmers) * 100) . "% engagement rate";
    }

    $guideCountResult = $conn->query("SELECT COUNT(*) AS total_count FROM guide_module");
    if ($guideCountResult) {
        $guideCountRow = $guideCountResult->fetch_assoc();
        $guideModuleTotal = (int) ($guideCountRow["total_count"] ?? 0);
    }

    $monthLabels = [];
    $monthKeys = [];
    $marchStartDate = new DateTime("first day of march this year");
    for ($monthIndex = 0; $monthIndex < 6; $monthIndex++) {
        $monthDate = (clone $marchStartDate)->modify("+{$monthIndex} month");
        $monthLabels[] = $monthDate->format("M");
        $monthKeys[] = $monthDate->format("Y-m");
    }

    $growthTrendMonths = $monthLabels;
    $growthTrendUserCounts = array_fill(0, count($monthKeys), 0);
    $growthTrendGuideCounts = array_fill(0, count($monthKeys), 0);

    $userMonthlySql = "SELECT DATE_FORMAT(date_created, '%Y-%m') AS month_key, COUNT(*) AS total_count
        FROM users
        WHERE LOWER(TRIM(role)) = 'farmer'
          AND date_created IS NOT NULL
          AND date_created <> ''
        GROUP BY DATE_FORMAT(date_created, '%Y-%m')";
    $userMonthlyResult = $conn->query($userMonthlySql);
    if ($userMonthlyResult) {
        while ($row = $userMonthlyResult->fetch_assoc()) {
            $monthKey = (string) ($row["month_key"] ?? "");
            $monthIndex = array_search($monthKey, $monthKeys, true);
            if ($monthIndex !== false) {
                $growthTrendUserCounts[$monthIndex] = (int) ($row["total_count"] ?? 0);
            }
        }
    }

    $guideMonthlySql = "SELECT DATE_FORMAT(date_created, '%Y-%m') AS month_key, COUNT(*) AS total_count
        FROM guide_module
        WHERE date_created IS NOT NULL
          AND date_created <> ''
        GROUP BY DATE_FORMAT(date_created, '%Y-%m')";
    $guideMonthlyResult = $conn->query($guideMonthlySql);
    if ($guideMonthlyResult) {
        while ($row = $guideMonthlyResult->fetch_assoc()) {
            $monthKey = (string) ($row["month_key"] ?? "");
            $monthIndex = array_search($monthKey, $monthKeys, true);
            if ($monthIndex !== false) {
                $growthTrendGuideCounts[$monthIndex] = (int) ($row["total_count"] ?? 0);
            }
        }
    }

    $topGuideSql = "SELECT
            module_title,
            category,
            DATE_FORMAT(COALESCE(last_updated, date_created), '%b %e, %Y') AS display_date,
            CASE
                WHEN last_updated IS NOT NULL AND last_updated <> '' THEN 'Updated'
                ELSE 'Added'
            END AS status_label
        FROM guide_module
        ORDER BY COALESCE(last_updated, date_created) DESC, guide_id DESC
        LIMIT 5";
    $topGuideResult = $conn->query($topGuideSql);
    if ($topGuideResult) {
        while ($row = $topGuideResult->fetch_assoc()) {
            $topGuideItems[] = [
                'title' => trim((string) ($row['module_title'] ?? 'Untitled guide')),
                'category' => trim((string) ($row['category'] ?? 'General')),
                'date' => trim((string) ($row['display_date'] ?? '')),
                'status' => trim((string) ($row['status_label'] ?? 'Added')),
            ];
        }
    }

    if (empty($topGuideItems)) {
        $topGuideItems = [[
            'title' => 'No guide modules yet',
            'category' => 'General',
            'date' => '',
            'status' => 'Added',
        ]];
    }

    $loginSelectColumns = ["users_id", "last_login_date"];
    foreach (["full_name", "name", "username", "email", "first_name", "last_name"] as $fieldName) {
        if (isset($availableColumns[$fieldName])) {
            $loginSelectColumns[] = $fieldName;
        }
    }
    $loginSelectColumns = array_values(array_unique($loginSelectColumns));

    $recentLoginSql = "SELECT " . implode(", ", $loginSelectColumns) . "
        FROM users
        WHERE last_login_date IS NOT NULL
          AND last_login_date <> ''
                ORDER BY CAST(last_login_date AS DATETIME) DESC, users_id DESC
                LIMIT 5";
    $recentLoginResult = $conn->query($recentLoginSql);
    if ($recentLoginResult) {
        while ($row = $recentLoginResult->fetch_assoc()) {
            $recentLoginItems[] = [
                'label' => buildUserLoginLabel($row, $availableColumns),
                'date' => trim((string) ($row['last_login_date'] ?? '')),
            ];
        }
    }

    $locationSql = "SELECT
            CASE
                WHEN farm_location IS NULL OR TRIM(farm_location) = '' THEN 'Unknown'
                ELSE TRIM(farm_location)
            END AS location_label,
            COUNT(*) AS total_count
        FROM corn_profile
        GROUP BY location_label
        ORDER BY total_count DESC, location_label ASC
        LIMIT 7";
    $locationResult = $conn->query($locationSql);
    if ($locationResult) {
        while ($row = $locationResult->fetch_assoc()) {
            $mostPlantedLocationLabels[] = (string) ($row["location_label"] ?? "Unknown");
            $mostPlantedLocationCounts[] = (int) ($row["total_count"] ?? 0);
        }
    }

    if (empty($mostPlantedLocationLabels)) {
        $mostPlantedLocationLabels = ["No data"];
        $mostPlantedLocationCounts = [0];
    }

    $pestScanSql = "SELECT
            CASE
                WHEN result IS NULL OR TRIM(result) = '' THEN 'Unknown'
                ELSE TRIM(result)
            END AS result_label,
            COUNT(*) AS total_count
        FROM pest_and_disease_results
        GROUP BY result_label
        ORDER BY total_count DESC, result_label ASC
        LIMIT 7";
    $pestScanResult = $conn->query($pestScanSql);
    if ($pestScanResult) {
        while ($row = $pestScanResult->fetch_assoc()) {
            $mostPestScanLabels[] = (string) ($row["result_label"] ?? "Unknown");
            $mostPestScanCounts[] = (int) ($row["total_count"] ?? 0);
        }
    }

    if (empty($mostPestScanLabels)) {
        $mostPestScanLabels = ["No data"];
        $mostPestScanCounts = [0];
    }

    $plantMonthSql = "SELECT
            DATE_FORMAT(planting_date, '%Y-%m') AS month_key,
            DATE_FORMAT(planting_date, '%b %Y') AS month_label,
            COUNT(*) AS total_count
        FROM corn_profile
        WHERE planting_date IS NOT NULL
          AND planting_date <> ''
        GROUP BY DATE_FORMAT(planting_date, '%Y-%m'), DATE_FORMAT(planting_date, '%b %Y')
                ORDER BY month_key ASC
        LIMIT 7";
    $plantMonthResult = $conn->query($plantMonthSql);
    if ($plantMonthResult) {
        while ($row = $plantMonthResult->fetch_assoc()) {
            $mostPlantMonthLabels[] = (string) ($row["month_label"] ?? "Unknown");
            $mostPlantMonthCounts[] = (int) ($row["total_count"] ?? 0);
        }
    }

    if (empty($mostPlantMonthLabels)) {
        $mostPlantMonthLabels = ["No data"];
        $mostPlantMonthCounts = [0];
    }

    $cornTypeSql = "SELECT
            CASE
                WHEN corn_type IS NULL OR TRIM(corn_type) = '' THEN 'Unknown'
                ELSE TRIM(corn_type)
            END AS corn_type_label,
            COUNT(*) AS total_count
        FROM corn_profile
        GROUP BY corn_type_label
        ORDER BY total_count DESC, corn_type_label ASC
        LIMIT 7";
    $cornTypeResult = $conn->query($cornTypeSql);
    if ($cornTypeResult) {
        while ($row = $cornTypeResult->fetch_assoc()) {
            $mostCornTypeLabels[] = (string) ($row["corn_type_label"] ?? "Unknown");
            $mostCornTypeCounts[] = (int) ($row["total_count"] ?? 0);
        }
    }

    if (empty($mostCornTypeLabels)) {
        $mostCornTypeLabels = ["No data"];
        $mostCornTypeCounts = [0];
    }

    $cornVarietySql = "SELECT
            CASE
                WHEN corn_variety IS NULL OR TRIM(corn_variety) = '' THEN 'Unknown'
                ELSE TRIM(corn_variety)
            END AS corn_variety_label,
            COUNT(*) AS total_count
        FROM corn_profile
        GROUP BY corn_variety_label
        ORDER BY total_count DESC, corn_variety_label ASC
        LIMIT 7";
    $cornVarietyResult = $conn->query($cornVarietySql);
    if ($cornVarietyResult) {
        while ($row = $cornVarietyResult->fetch_assoc()) {
            $mostCornVarietyLabels[] = (string) ($row["corn_variety_label"] ?? "Unknown");
            $mostCornVarietyCounts[] = (int) ($row["total_count"] ?? 0);
        }
    }

    if (empty($mostCornVarietyLabels)) {
        $mostCornVarietyLabels = ["No data"];
        $mostCornVarietyCounts = [0];
    }

    $conn->close();
} catch (Throwable $e) {
    $totalFarmers = 0;
    $activeUsers = 0;
    $newFarmersThisMonth = 0;
    $engagementRateLabel = "0% engagement rate";
    $guideModuleTotal = 0;
    $growthTrendMonths = [];
    $growthTrendUserCounts = [];
    $growthTrendGuideCounts = [];
    $mostPlantedLocationLabels = ["No data"];
    $mostPlantedLocationCounts = [0];
    $recentLoginItems = [];
    $topGuideItems = [[
        'title' => 'No guide modules yet',
        'category' => 'General',
        'date' => '',
        'status' => 'Added',
    ]];
    $mostPestScanLabels = ["No data"];
    $mostPestScanCounts = [0];
    $mostPlantMonthLabels = ["No data"];
    $mostPlantMonthCounts = [0];
    $mostCornTypeLabels = ["No data"];
    $mostCornTypeCounts = [0];
    $mostCornVarietyLabels = ["No data"];
    $mostCornVarietyCounts = [0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <link rel="stylesheet" href="../bootstrap5/css/bootstrap.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=Manrope:wght@400;500;600;700&display=swap');
        :root {
            --background: #fafdf7;
            --foreground: #2c3e2e;
            --card: #ffffff;
            --primary: #7fb685;
            --primary-foreground: #ffffff;
            --secondary: #ffe599;
            --secondary-foreground: #2c3e2e;
            --muted: #e8f3ea;
            --muted-foreground: #6b7c6e;
            --accent: #f3f8ef;
            --border: rgba(127, 182, 133, 0.28);
            --shadow-lg: 0 12px 28px rgba(34, 58, 39, 0.14);
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            height: 100%;
            min-height: 100%;
            font-family: "Manrope", "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: var(--foreground);
            background: linear-gradient(135deg, rgba(127, 182, 133, 0.08), rgba(250, 253, 247, 1), rgba(255, 229, 153, 0.12));
        }

        .app {
            display: flex;
            height: 100dvh;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(127, 182, 133, 0.06), rgba(250, 253, 247, 1), rgba(255, 229, 153, 0.08));
        }

        .sidebar {
            width: 80px;
            background: linear-gradient(180deg, rgba(127, 182, 133, 0.4), rgba(255, 229, 153, 0.4));
            border-right: 1px solid rgba(127, 182, 133, 0.35);
            transition: width 0.3s ease;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }

        .sidebar:not(.is-locked-collapsed):hover,
        .sidebar.is-pinned {
            width: 256px;
        }

        .side-head {
            padding: 24px 12px;
            border-bottom: 1px solid rgba(127, 182, 133, 0.35);
            display: flex;
            justify-content: center;
        }

        .brand-collapsed,
        .brand-expanded {
            transition: opacity 0.25s ease;
        }

        .brand-collapsed {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            opacity: 1;
        }

        .sidebar:not(.is-locked-collapsed):hover .brand-collapsed,
        .sidebar.is-pinned .brand-collapsed {
            opacity: 0;
            pointer-events: none;
            position: absolute;
        }

        .brand-expanded {
            display: none;
            text-align: center;
        }

        .sidebar:not(.is-locked-collapsed):hover .brand-expanded,
        .sidebar.is-pinned .brand-expanded {
            display: block;
        }

        .avatar-round {
            width: 48px;
            height: 48px;
            border-radius: 999px;
            border: 2px solid rgba(255, 255, 255, 0.35);
            background: rgba(127, 182, 133, 0.55);
            color: var(--primary-foreground);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            font-weight: 700;
        }

        .small-text {
            font-size: 0.75rem;
            font-weight: 600;
            color: rgba(44, 62, 46, 0.9);
        }

        .brand-expanded h2 {
            margin: 0;
            font-size: 1.22rem;
            font-weight: 700;
            color: #2c3e2e;
        }

        .brand-expanded p {
            margin: 4px 0 0;
            font-size: 0.76rem;
            color: #48624b;
        }

        .side-nav {
            padding: 14px 10px;
            display: grid;
            gap: 8px;
            flex: 1;
            align-content: start;
            grid-auto-rows: min-content;
        }

        .nav-btn {
            border: 0;
            background: transparent;
            color: #2c3e2e;
            border-radius: 10px;
            height: 50px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            gap: 0;
            justify-content: center;
            transition: all 0.2s ease;
            width: 100%;
        }

        .sidebar:not(.is-locked-collapsed):hover .nav-btn,
        .sidebar.is-pinned .nav-btn {
            padding: 0 12px;
            gap: 12px;
            justify-content: flex-start;
        }

        .nav-icon {
            width: 30px;
            height: 30px;
            border-radius: 10px;
            background: rgba(127, 182, 133, 0.16);
            color: #3f5f43;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .nav-icon svg {
            width: 17px;
            height: 17px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .nav-text {
            font-size: 0.9rem;
            font-weight: 600;
            opacity: 0;
            width: 0;
            overflow: hidden;
            white-space: nowrap;
            transition: opacity 0.2s ease;
        }

        .sidebar:not(.is-locked-collapsed):hover .nav-text,
        .sidebar.is-pinned .nav-text {
            opacity: 1;
            width: auto;
        }

        .nav-btn.active {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 6px 14px rgba(127, 182, 133, 0.35);
        }

        .nav-btn.active .nav-icon {
            background: rgba(255, 255, 255, 0.24);
            color: #fff;
        }

        .nav-btn.mobile-only {
            display: none;
        }

        .nav-btn:hover:not(.active) {
            background: rgba(127, 182, 133, 0.25);
        }

        .nav-btn:hover:not(.active) .nav-icon {
            background: rgba(127, 182, 133, 0.32);
            color: #2c3e2e;
        }

        .side-foot {
            padding: 12px 10px;
            border-top: 1px solid rgba(127, 182, 133, 0.35);
        }

        .main {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .main-head {
            background: linear-gradient(90deg, rgba(127, 182, 133, 0.4), rgba(127, 182, 133, 0.35), rgba(255, 229, 153, 0.4));
            border-bottom: 1px solid rgba(127, 182, 133, 0.3);
            box-shadow: 0 3px 10px rgba(34, 58, 39, 0.08);
            padding: 16px 24px;
        }

        .main-head h1 {
            margin: 0;
            font-size: 1.55rem;
            font-weight: 700;
            color: #2c3e2e;
            font-family: "Fraunces", "Manrope", serif;
        }

        .main-head p {
            margin: 2px 0 0;
            font-size: 0.9rem;
            color: #5a6f5d;
        }

        .main-body {
            flex: 1;
            overflow: auto;
            padding: 24px;
        }

        .stack {
            display: grid;
            gap: 24px;
        }

        .title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .title-row h2 {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: "Fraunces", "Manrope", serif;
        }

        .title-row h2 svg {
            width: 30px;
            height: 30px;
            fill: var(--primary);
        }

        .title-row .sub {
            margin: 2px 0 0;
            color: var(--muted-foreground);
            font-size: 0.9rem;
        }

        .outline-pill {
            border: 1px solid var(--border);
            border-radius: 999px;
            background: #fff;
            padding: 7px 12px;
            font-size: 0.8rem;
            color: #5c705f;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 24px;
        }

        .card {
            position: relative;
            overflow: hidden;
            border: 0;
            border-radius: 14px;
            box-shadow: var(--shadow-lg);
            padding: 16px;
            background: #fff;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 32px rgba(34, 58, 39, 0.18);
        }

        .card .orb {
            position: absolute;
            top: -20px;
            right: -20px;
            width: 132px;
            height: 132px;
            border-radius: 999px;
            filter: blur(32px);
            pointer-events: none;
        }

        .card-top {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
            z-index: 2;
        }

        .icon-box {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .icon-box svg {
            width: 24px;
            height: 24px;
            fill: currentColor;
        }

        .card-content {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            min-height: 112px;
        }

        .card-label {
            margin: 0;
            font-size: 0.86rem;
            font-weight: 600;
            color: var(--muted-foreground);
        }

        .card-value {
            margin: 6px 0 0;
            font-size: 2.15rem;
            font-weight: 700;
            line-height: 1;
            color: #2c3e2e;
        }

        .card-note {
            margin: auto 0 0;
            font-size: 0.78rem;
            color: var(--muted-foreground);
        }

        .trend {
            margin: auto 0 0;
            font-size: 0.78rem;
            font-weight: 700;
            color: #16a34a;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .trend svg {
            width: 13px;
            height: 13px;
            fill: currentColor;
        }

        .stat-blue {
            background: linear-gradient(145deg, rgba(59, 130, 246, 0.12), rgba(59, 130, 246, 0.04), #fff);
        }

        .stat-blue .orb { background: rgba(59, 130, 246, 0.2); }
        .stat-blue .icon-box { background: rgba(59, 130, 246, 0.12); color: #2563eb; }

        .stat-green {
            background: linear-gradient(145deg, rgba(34, 197, 94, 0.12), rgba(34, 197, 94, 0.04), #fff);
        }

        .stat-green .orb { background: rgba(34, 197, 94, 0.2); }
        .stat-green .icon-box { background: rgba(34, 197, 94, 0.12); color: #16a34a; }

        .stat-purple {
            background: linear-gradient(145deg, rgba(168, 85, 247, 0.12), rgba(168, 85, 247, 0.04), #fff);
        }

        .stat-purple .orb { background: rgba(168, 85, 247, 0.2); }
        .stat-purple .icon-box { background: rgba(168, 85, 247, 0.12); color: #9333ea; }

        .stat-orange {
            background: linear-gradient(145deg, rgba(249, 115, 22, 0.12), rgba(249, 115, 22, 0.04), #fff);
        }

        .stat-orange .orb { background: rgba(249, 115, 22, 0.2); }
        .stat-orange .icon-box { background: rgba(249, 115, 22, 0.14); color: #ea580c; }
        .stat-orange .card-value { color: #ea580c; }

        .panel-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 24px;
        }

        .panel-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 24px;
        }

        .panel {
            background: #fff;
            border-radius: 14px;
            border: 0;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }

        .panel.pad {
            padding: 16px;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .user-growth-header {
            align-items: flex-start;
            flex-wrap: nowrap;
        }

        .user-growth-header .panel-copy {
            min-width: 0;
            flex: 1;
        }

        .user-growth-header .panel-badge {
            margin-top: 2px;
            flex-shrink: 0;
        }

        .panel-title {
            margin: 0;
            font-size: 1.03rem;
            font-weight: 700;
            color: #2c3e2e;
        }

        .panel-subtitle {
            margin: 2px 0 0;
            font-size: 0.82rem;
            color: var(--muted-foreground);
        }

        .panel-badge {
            border: 1px solid var(--border);
            border-radius: 999px;
            background: #fff;
            font-size: 0.75rem;
            color: #546a57;
            padding: 5px 10px;
        }

        .chart-wrap {
            height: 318px;
        }

        .list-wrap {
            display: grid;
            gap: 10px;
        }

        .guide-row {
            border-radius: 10px;
            background: linear-gradient(90deg, rgba(243, 248, 239, 0.75), rgba(243, 248, 239, 0.4));
            padding: 10px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            transition: background 0.2s ease;
        }

        .guide-row:hover {
            background: linear-gradient(90deg, rgba(243, 248, 239, 0.95), rgba(243, 248, 239, 0.58));
        }

        .guide-left {
            min-width: 0;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .rank {
            width: 30px;
            height: 30px;
            border-radius: 999px;
            background: rgba(127, 182, 133, 0.14);
            color: #4d7b52;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 700;
            flex-shrink: 0;
        }

        .guide-title {
            margin: 0;
            font-size: 0.84rem;
            font-weight: 600;
            color: #2c3e2e;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 300px;
        }

        .guide-meta {
            margin: 2px 0 0;
            font-size: 0.76rem;
            color: var(--muted-foreground);
        }

        .guide-right {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.76rem;
            font-weight: 700;
            flex-shrink: 0;
        }

        .guide-right svg {
            width: 14px;
            height: 14px;
            fill: currentColor;
        }

        .guide-right.up { color: #16a34a; }
        .guide-right.down { color: #dc2626; }

        .activity-row {
            border-radius: 10px;
            background: linear-gradient(90deg, rgba(243, 248, 239, 0.75), rgba(243, 248, 239, 0.4));
            padding: 10px 12px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .activity-icon {
            width: 30px;
            height: 30px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .activity-icon svg {
            width: 15px;
            height: 15px;
            fill: currentColor;
        }

        .activity-blue {
            background: rgba(59, 130, 246, 0.12);
            color: #2563eb;
        }

        .activity-purple {
            background: rgba(168, 85, 247, 0.12);
            color: #9333ea;
        }

        .activity-title {
            margin: 0;
            font-size: 0.84rem;
            font-weight: 600;
            color: #2c3e2e;
        }

        .activity-meta {
            margin: 2px 0 0;
            font-size: 0.76rem;
            color: var(--muted-foreground);
        }

        .modal-mask {
            position: fixed;
            inset: 0;
            background: rgba(20, 29, 23, 0.4);
            backdrop-filter: blur(3px);
            z-index: 79;
            display: none;
        }

        .modal-mask.show {
            display: block;
        }

        .logout-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.98);
            background: #fff;
            border-radius: 14px;
            width: min(92vw, 420px);
            box-shadow: var(--shadow-lg);
            z-index: 80;
            padding: 20px;
            opacity: 0;
            pointer-events: none;
            transition: all 0.2s ease;
        }

        .logout-modal.show {
            opacity: 1;
            pointer-events: auto;
            transform: translate(-50%, -50%) scale(1);
        }

        .logout-title {
            margin: 0 0 8px;
            font-weight: 700;
            font-size: 1.15rem;
        }

        .logout-desc {
            margin: 0 0 18px;
            color: var(--muted-foreground);
            font-size: 0.92rem;
        }

        @media (max-width: 1220px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 991px) {
            .sidebar {
                display: flex;
                position: fixed;
                bottom: 0;
                left: 8px;
                right: 8px;
                width: calc(100% - 16px);
                height: 72px;
                flex-direction: row;
                align-items: center;
                justify-content: center;
                padding: 8px 12px;
                border-right: 0;
                border-top: 1px solid rgba(127, 182, 133, 0.3);
                background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(243,248,239,0.98));
                border-radius: 16px 16px 0 0;
                box-shadow: 0 -10px 22px rgba(34, 58, 39, 0.16);
                overflow: hidden;
                transition: none;
                z-index: 100;
            }

            .sidebar.is-pinned,
            .sidebar:not(.is-locked-collapsed):hover {
                width: calc(100% - 16px);
            }

            .side-head,
            .side-foot {
                display: none;
            }

            .side-nav {
                padding: 0;
                display: flex;
                flex-direction: row;
                gap: 8px;
                width: 100%;
                justify-content: space-around;
            }

            .nav-btn {
                height: auto;
                width: 64px;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 4px;
                padding: 6px 8px;
                border-radius: 12px;
            }

            .nav-icon {
                width: 28px;
                height: 28px;
                border-radius: 9px;
            }

            .nav-text {
                display: none;
            }

            .nav-btn.active::after {
                content: "";
                width: 6px;
                height: 6px;
                border-radius: 999px;
                background: #2c3e2e;
                display: block;
                margin-top: 2px;
                opacity: 0.6;
            }

            .nav-btn.mobile-only {
                display: inline-flex;
            }

            .main {
                padding-bottom: 84px;
            }

            .main-head,
            .main-body {
                padding-left: 14px;
                padding-right: 14px;
            }

            .panel-grid-3 {
                grid-template-columns: minmax(0, 1fr);
            }

            .panel-grid-2 {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        @media (max-width: 640px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .user-growth-header {
                flex-wrap: wrap;
            }

            .title-row h2 {
                font-size: 1.45rem;
            }

            .guide-title {
                max-width: 170px;
            }

            .chart-wrap {
                height: 280px;
            }
        }
    </style>
</head>
<body>
    <div class="app">
        <aside class="sidebar" aria-label="Admin navigation">
            <div class="side-head">
                <div class="brand-collapsed">
                    <span class="avatar-round">AC</span>
                    <span class="small-text">Admin</span>
                </div>
                <div class="brand-expanded">
                    <h2>AgriCorn Admin</h2>
                    <p>Management Panel</p>
                </div>
            </div>

            <nav class="side-nav">
                <button class="nav-btn active" type="button">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="5" rx="1"></rect><rect x="3" y="14" width="5" height="7" rx="1"></rect><rect x="10" y="12" width="11" height="9" rx="1"></rect></svg>
                    </span>
                    <span class="nav-text">Dashboard</span>
                </button>
                <button class="nav-btn" type="button" id="goUsersBtn">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4 20c0-3.3 3.6-6 8-6s8 2.7 8 6"></path></svg>
                    </span>
                    <span class="nav-text">User Management</span>
                </button>
                <button class="nav-btn" type="button" id="goGuidesBtn">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h7a3 3 0 0 1 3 3v11H7a3 3 0 0 0-3 3V5z"></path><path d="M20 5h-7a3 3 0 0 0-3 3v11h7a3 3 0 0 1 3 3V5z"></path></svg>
                    </span>
                    <span class="nav-text">Guide Modules</span>
                </button>
                <button class="nav-btn mobile-only" type="button" id="logoutBtnMobile" aria-label="Logout">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><path d="M16 17l5-5-5-5"></path><path d="M21 12H9"></path></svg>
                    </span>
                    <span class="nav-text">Logout</span>
                </button>
            </nav>

            <div class="side-foot">
                <button class="nav-btn" id="logoutBtn" type="button">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><path d="M16 17l5-5-5-5"></path><path d="M21 12H9"></path></svg>
                    </span>
                    <span class="nav-text">Logout</span>
                </button>
            </div>
        </aside>

        <div class="main">
            <header class="main-head">
                <h1>Admin Panel</h1>
                <p>Welcome back, Administrator</p>
            </header>

            <main class="main-body">
                <div class="stack">
                    <div class="title-row">
                        <div>
                            <h2>
                                <svg viewBox="0 0 24 24"><path d="M4 13h4l2-5 4 10 2-5h4"></path></svg>
                                Dashboard Overview
                            </h2>
                            <p class="sub">Monitor system statistics and user activity in real-time</p>
                        </div>
                        <span class="outline-pill">Last updated: Just now</span>
                    </div>

                    <section class="stats">
                        <article class="card stat-blue">
                            <div class="orb"></div>
                            <div class="card-top">
                                <span class="icon-box"><svg viewBox="0 0 24 24"><path d="M12 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0 2c4.4 0 8 2.2 8 5v2H4v-2c0-2.8 3.6-5 8-5z"></path></svg></span>
                                <svg viewBox="0 0 24 24" style="width:20px;height:20px;fill:#16a34a;"><path d="m4 12 4 4L20 4l-1.4-1.4L8 13.2l-2.6-2.6z"></path></svg>
                            </div>
                            <div class="card-content">
                                <p class="card-label">Total Farmers</p>
                                <p class="card-value"><?php echo number_format($totalFarmers); ?></p>
                                <p class="trend"><svg viewBox="0 0 24 24"><path d="m4 14 4-4 3 3 7-7 2 2-9 9-3-3-4 4z"></path></svg>+<?php echo number_format($newFarmersThisMonth); ?> this month</p>
                            </div>
                        </article>

                        <article class="card stat-green">
                            <div class="orb"></div>
                            <div class="card-top">
                                <span class="icon-box"><svg viewBox="0 0 24 24"><path d="M3 17h2v3H3v-3zm4-7h2v10H7V10zm4-4h2v14h-2V6zm4 6h2v8h-2v-8zm4-8h2v16h-2V4z"></path></svg></span>
                                <svg viewBox="0 0 24 24" style="width:20px;height:20px;fill:#16a34a;"><path d="m4 12 4 4L20 4l-1.4-1.4L8 13.2l-2.6-2.6z"></path></svg>
                            </div>
                            <div class="card-content">
                                <p class="card-label">Active Users</p>
                                <p class="card-value"><?php echo number_format($activeUsers); ?></p>
                                <p class="card-note"><?php echo htmlspecialchars($engagementRateLabel); ?></p>
                            </div>
                        </article>

                        <article class="card stat-purple">
                            <div class="orb"></div>
                            <div class="card-top">
                                <span class="icon-box"><svg viewBox="0 0 24 24"><path d="M4 4h8a3 3 0 0 1 3 3v13H7a3 3 0 0 0-3 3V4zm16 0h-8a3 3 0 0 0-3 3v13h8a3 3 0 0 1 3 3V4z"></path></svg></span>
                                <span class="panel-badge"><?php echo number_format($guideModuleTotal); ?> total</span>
                            </div>
                            <div class="card-content">
                                <p class="card-label">Guide Modules</p>
                                <p class="card-value"><?php echo number_format($guideModuleTotal); ?></p>
                                <p class="card-note">From the guide_module table</p>
                            </div>
                        </article>

                        <article class="card stat-orange">
                            <div class="orb"></div>
                            <div class="card-top">
                                <span class="icon-box"><svg viewBox="0 0 24 24"><path d="m4 16 4-4 3 3 6-7 3 3v-4h-4l2 2-5 6-3-3-6 6z"></path></svg></span>
                                <svg viewBox="0 0 24 24" style="width:20px;height:20px;fill:#16a34a;"><path d="m4 12 4 4L20 4l-1.4-1.4L8 13.2l-2.6-2.6z"></path></svg>
                            </div>
                            <div class="card-content">
                                <p class="card-label">Growth Rate</p>
                                <p class="card-value">+11.3%</p>
                                <p class="card-note">Monthly user growth</p>
                            </div>
                        </article>
                    </section>

                    <section class="panel-grid-3">
                        <article class="panel pad" style="grid-column: span 3;">
                            <div class="panel-header user-growth-header">
                                <div class="panel-copy">
                                    <h3 class="panel-title">User Growth Trend</h3>
                                    <p class="panel-subtitle">Total users and guide modules over 6 months</p>
                                </div>
                                <span class="panel-badge">6 Months</span>
                            </div>
                            <div class="chart-wrap"><canvas id="userGrowthChart"></canvas></div>
                        </article>
                    </section>

                    <section class="panel-grid-2">
                        <article class="panel pad">
                            <div class="panel-header">
                                <div>
                                    <h3 class="panel-title">Top Performing Guides</h3>
                                    <p class="panel-subtitle">Latest farming guides from the database</p>
                                </div>
                            </div>
                            <div class="list-wrap">
                                <?php foreach ($topGuideItems as $index => $guideItem): ?>
                                    <div class="guide-row">
                                        <div class="guide-left">
                                            <span class="rank"><?php echo number_format($index + 1); ?></span>
                                            <div>
                                                <p class="guide-title"><?php echo htmlspecialchars($guideItem['title']); ?></p>
                                                <p class="guide-meta"><?php echo htmlspecialchars($guideItem['category'] . ($guideItem['date'] !== '' ? ' • ' . $guideItem['date'] : '')); ?></p>
                                            </div>
                                        </div>
                                        <span class="guide-right up"><?php echo htmlspecialchars($guideItem['status']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </article>

                        <article class="panel pad">
                            <div class="panel-header">
                                <div>
                                    <h3 class="panel-title">Recent Logins</h3>
                                    <p class="panel-subtitle">Latest user login activity</p>
                                </div>
                            </div>

                            <div class="list-wrap">
                                <?php if (!empty($recentLoginItems)): ?>
                                    <?php foreach ($recentLoginItems as $loginItem): ?>
                                        <div class="activity-row">
                                            <span class="activity-icon activity-blue"><svg viewBox="0 0 24 24"><path d="M12 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0 2c4.4 0 8 2.2 8 5v2H4v-2c0-2.8 3.6-5 8-5z"></path></svg></span>
                                            <div>
                                                <p class="activity-title"><?php echo htmlspecialchars($loginItem['label']); ?></p>
                                                <p class="activity-meta"><?php echo htmlspecialchars(formatLoginTimeAgo($loginItem['date'])); ?> • <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($loginItem['date']))); ?></p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="activity-row">
                                        <span class="activity-icon activity-blue"><svg viewBox="0 0 24 24"><path d="M12 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0 2c4.4 0 8 2.2 8 5v2H4v-2c0-2.8 3.6-5 8-5z"></path></svg></span>
                                        <div>
                                            <p class="activity-title">No recent logins yet</p>
                                            <p class="activity-meta">Login data will appear here once users sign in</p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </article>
                    </section>

                    <section class="panel-grid-2">
                        <article class="panel pad">
                            <div class="panel-header">
                                <div>
                                    <h3 class="panel-title">Most Pest and Disease Scanned</h3>
                                    <p class="panel-subtitle">Scan results grouped from pest_and_disease_results</p>
                                </div>
                            </div>
                            <div class="chart-wrap" style="height: 300px;"><canvas id="pestScanChart"></canvas></div>
                        </article>

                        <article class="panel pad">
                            <div class="panel-header">
                                <div>
                                    <h3 class="panel-title">Most Plant Month</h3>
                                    <p class="panel-subtitle">Planting counts grouped from corn_profile.planting_date</p>
                                </div>
                            </div>
                            <div class="chart-wrap" style="height: 300px;"><canvas id="plantMonthChart"></canvas></div>
                        </article>
                    </section>

                    <section class="panel-grid-2">
                        <article class="panel pad">
                            <div class="panel-header">
                                <div>
                                    <h3 class="panel-title">Most Used Corn Type</h3>
                                    <p class="panel-subtitle">Grouped from corn_profile.corn_type</p>
                                </div>
                            </div>
                            <div class="chart-wrap" style="height: 300px;"><canvas id="cornTypeChart"></canvas></div>
                        </article>

                        <article class="panel pad">
                            <div class="panel-header">
                                <div>
                                    <h3 class="panel-title">Most Used Corn Variety</h3>
                                    <p class="panel-subtitle">Grouped from corn_profile.corn_variety</p>
                                </div>
                            </div>
                            <div class="chart-wrap" style="height: 300px;"><canvas id="cornVarietyChart"></canvas></div>
                        </article>
                    </section>

                    <article class="panel pad">
                        <div class="panel-header">
                            <div>
                                <h3 class="panel-title">Most Planted Locations</h3>
                                <p class="panel-subtitle">Count of corn profiles grouped by farm location</p>
                            </div>
                        </div>
                        <div class="chart-wrap" style="height: 320px;"><canvas id="mostPlantedLocationChart"></canvas></div>
                    </article>
                </div>
            </main>
        </div>
    </div>

    <div class="modal-mask" id="logoutModalMask"></div>
    <div class="logout-modal" id="logoutModal">
        <h3 class="logout-title">Log out</h3>
        <p class="logout-desc">Are you sure you want to log out? You will be redirected to the login page.</p>
        <div class="d-flex justify-content-end gap-2">
            <button class="btn btn-outline-secondary" id="cancelLogout" type="button">Cancel</button>
            <button class="btn btn-danger" id="confirmLogout" type="button">Log out</button>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
        (function () {
            var growthTrendLabels = <?php echo json_encode($growthTrendMonths, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            var growthTrendUserCounts = <?php echo json_encode($growthTrendUserCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            var growthTrendGuideCounts = <?php echo json_encode($growthTrendGuideCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            var mostPlantedLocationLabels = <?php echo json_encode($mostPlantedLocationLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            var mostPlantedLocationCounts = <?php echo json_encode($mostPlantedLocationCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            var mostPestScanLabels = <?php echo json_encode($mostPestScanLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            var mostPestScanCounts = <?php echo json_encode($mostPestScanCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            var mostPlantMonthLabels = <?php echo json_encode($mostPlantMonthLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            var mostPlantMonthCounts = <?php echo json_encode($mostPlantMonthCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            var mostCornTypeLabels = <?php echo json_encode($mostCornTypeLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            var mostCornTypeCounts = <?php echo json_encode($mostCornTypeCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            var mostCornVarietyLabels = <?php echo json_encode($mostCornVarietyLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            var mostCornVarietyCounts = <?php echo json_encode($mostCornVarietyCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            var sidebar = document.querySelector(".sidebar");
            if (sidebar) {
                sidebar.addEventListener("click", function (event) {
                    if (window.innerWidth <= 991) {
                        return;
                    }

                    if (event.target.closest(".nav-btn")) {
                        return;
                    }

                    if (sidebar.classList.contains("is-pinned")) {
                        sidebar.classList.remove("is-pinned");
                        sidebar.classList.add("is-locked-collapsed");
                        return;
                    }

                    sidebar.classList.remove("is-locked-collapsed");
                    sidebar.classList.add("is-pinned");
                });

                sidebar.addEventListener("mouseleave", function () {
                    sidebar.classList.remove("is-locked-collapsed");
                });
            }

            var logoutModal = document.getElementById("logoutModal");
            var logoutMask = document.getElementById("logoutModalMask");
            var cancelLogout = document.getElementById("cancelLogout");
            var confirmLogout = document.getElementById("confirmLogout");

            document.getElementById("goUsersBtn").addEventListener("click", function () {
                window.location.href = "./user_management.php";
            });

            document.getElementById("goGuidesBtn").addEventListener("click", function () {
                window.location.href = "./guide_module.php";
            });

            function openLogout() {
                logoutMask.classList.add("show");
                logoutModal.classList.add("show");
            }

            function closeLogout() {
                logoutMask.classList.remove("show");
                logoutModal.classList.remove("show");
            }

            document.getElementById("logoutBtn").addEventListener("click", openLogout);
            var logoutBtnMobile = document.getElementById("logoutBtnMobile");
            if (logoutBtnMobile) {
                logoutBtnMobile.addEventListener("click", openLogout);
            }
            cancelLogout.addEventListener("click", closeLogout);
            logoutMask.addEventListener("click", closeLogout);

            confirmLogout.addEventListener("click", function () {
                window.location.href = "../login.php";
            });

            document.addEventListener("keydown", function (event) {
                if (event.key === "Escape") {
                    closeLogout();
                }
            });

            if (!window.Chart) {
                return;
            }

            Chart.defaults.font.family = 'Inter, "Segoe UI", Tahoma, Geneva, Verdana, sans-serif';
            Chart.defaults.color = "#6b7c6e";

            new Chart(document.getElementById("userGrowthChart"), {
                type: "line",
                data: {
                    labels: growthTrendLabels,
                    datasets: [
                        {
                            label: "Users",
                            data: growthTrendUserCounts,
                            borderColor: "#7fb685",
                            backgroundColor: "rgba(127,182,133,0.25)",
                            tension: 0.35,
                            fill: true,
                            pointRadius: 3,
                            borderWidth: 2
                        },
                        {
                            label: "Guides",
                            data: growthTrendGuideCounts,
                            borderColor: "#d9b24a",
                            backgroundColor: "rgba(217,178,74,0.18)",
                            tension: 0.35,
                            fill: true,
                            pointRadius: 3,
                            borderWidth: 2,
                            yAxisID: "y1"
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: "bottom" }
                    },
                    scales: {
                        y: {
                            min: 10,
                            suggestedMax: 100,
                            ticks: {
                                stepSize: 10
                            },
                            grid: { color: "rgba(107,124,110,0.2)", borderDash: [3, 3] }
                        },
                        y1: {
                            position: "right",
                            min: 10,
                            suggestedMax: 100,
                            ticks: {
                                stepSize: 10
                            },
                            grid: { display: false }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });

            new Chart(document.getElementById("plantMonthChart"), {
                type: "line",
                data: {
                    labels: mostPlantMonthLabels,
                    datasets: [
                        {
                            label: "Plantings",
                            data: mostPlantMonthCounts,
                            borderColor: "rgba(22, 163, 74, 0.95)",
                            backgroundColor: "rgba(22, 163, 74, 0.18)",
                            tension: 0.35,
                            fill: true,
                            pointRadius: 4,
                            borderWidth: 2
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: "bottom" }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            },
                            grid: { color: "rgba(107,124,110,0.2)", borderDash: [3, 3] }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });

            new Chart(document.getElementById("cornTypeChart"), {
                type: "doughnut",
                data: {
                    labels: mostCornTypeLabels,
                    datasets: [
                        {
                            label: "Count",
                            data: mostCornTypeCounts,
                            backgroundColor: [
                                "rgba(22, 163, 74, 0.9)",
                                "rgba(127, 182, 133, 0.9)",
                                "rgba(246, 201, 65, 0.9)",
                                "rgba(217, 178, 74, 0.9)",
                                "rgba(148, 163, 184, 0.9)",
                                "rgba(245, 158, 11, 0.9)",
                                "rgba(34, 197, 94, 0.9)"
                            ],
                            borderColor: "#f8faf5",
                            borderWidth: 2,
                            hoverOffset: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: "bottom" }
                    },
                    cutout: "58%"
                }
            });

            new Chart(document.getElementById("cornVarietyChart"), {
                type: "doughnut",
                data: {
                    labels: mostCornVarietyLabels,
                    datasets: [
                        {
                            label: "Count",
                            data: mostCornVarietyCounts,
                            backgroundColor: [
                                "rgba(22, 163, 74, 0.9)",
                                "rgba(127, 182, 133, 0.9)",
                                "rgba(246, 201, 65, 0.9)",
                                "rgba(217, 178, 74, 0.9)",
                                "rgba(148, 163, 184, 0.9)",
                                "rgba(245, 158, 11, 0.9)",
                                "rgba(34, 197, 94, 0.9)"
                            ],
                            borderColor: "#f8faf5",
                            borderWidth: 2,
                            hoverOffset: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: "bottom" }
                    },
                    cutout: "58%"
                }
            });

            new Chart(document.getElementById("pestScanChart"), {
                type: "doughnut",
                data: {
                    labels: mostPestScanLabels,
                    datasets: [
                        {
                            label: "Scans",
                            data: mostPestScanCounts,
                            backgroundColor: [
                                "rgba(22, 163, 74, 0.9)",
                                "rgba(127, 182, 133, 0.9)",
                                "rgba(246, 201, 65, 0.9)",
                                "rgba(217, 178, 74, 0.9)",
                                "rgba(148, 163, 184, 0.9)",
                                "rgba(245, 158, 11, 0.9)",
                                "rgba(34, 197, 94, 0.9)"
                            ],
                            borderColor: "#f8faf5",
                            borderWidth: 2,
                            hoverOffset: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: "bottom"
                        }
                    },
                    cutout: "58%"
                }
            });

            new Chart(document.getElementById("mostPlantedLocationChart"), {
                type: "bar",
                data: {
                    labels: mostPlantedLocationLabels,
                    datasets: [
                        {
                            label: "Planted Profiles",
                            data: mostPlantedLocationCounts,
                            backgroundColor: "rgba(22, 163, 74, 0.88)",
                            borderRadius: 8,
                            borderSkipped: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: "y",
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            },
                            grid: { color: "rgba(107,124,110,0.2)", borderDash: [3, 3] }
                        },
                        y: {
                            grid: { display: false }
                        }
                    }
                }
            });
        })();
    </script>

</body>
</html>
