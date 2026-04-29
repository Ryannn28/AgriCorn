<?php
session_start();

if (!isset($_SESSION["users_id"])) {
    header("Location: ../login.php");
    exit;
}

if (isset($_GET["guide_id"], $_GET["file_mode"])) {
    $guideId = (int) $_GET["guide_id"];
    $fileMode = strtolower(trim((string) $_GET["file_mode"]));

    if ($guideId <= 0 || ($fileMode !== "view" && $fileMode !== "download")) {
        http_response_code(400);
        echo "Invalid file request.";
        exit;
    }

    try {
        require_once __DIR__ . "/../data/db_connect.php";

        $stmt = $conn->prepare("SELECT module_title, guide_file FROM guide_module WHERE guide_id = ? LIMIT 1");
        $stmt->bind_param("i", $guideId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;

        $relativePath = (string) ($row["guide_file"] ?? "");
        if ($relativePath === "") {
            throw new RuntimeException("Guide file not found.");
        }

        $normalizedRelativePath = str_replace("\\", "/", $relativePath);
        while (strpos($normalizedRelativePath, "../") === 0) {
            $normalizedRelativePath = substr($normalizedRelativePath, 3);
        }

        $baseGuidesDir = realpath(__DIR__ . "/../data/guides");
        $absolutePath = realpath(__DIR__ . "/../" . $normalizedRelativePath);

        if (
            !$baseGuidesDir ||
            !$absolutePath ||
            strpos($absolutePath, $baseGuidesDir) !== 0 ||
            !is_file($absolutePath)
        ) {
            throw new RuntimeException("Guide file does not exist.");
        }

        $fileName = basename($absolutePath);
        $mimeType = "application/octet-stream";
        if (function_exists("finfo_open")) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detectedMime = finfo_file($finfo, $absolutePath);
                if (is_string($detectedMime) && $detectedMime !== "") {
                    $mimeType = $detectedMime;
                }
                finfo_close($finfo);
            }
        }

        $disposition = $fileMode === "download" ? "attachment" : "inline";

        header("Content-Type: " . $mimeType);
        header("Content-Length: " . filesize($absolutePath));
        header("X-Content-Type-Options: nosniff");
        header("Content-Disposition: " . $disposition . "; filename=\"" . addcslashes($fileName, "\\\"") . "\"");

        readfile($absolutePath);
        exit;
    } catch (Throwable $error) {
        http_response_code(404);
        echo "File unavailable.";
        exit;
    }
}

function normalize_guide_relative_path(string $relativePath): string
{
    $normalized = str_replace("\\", "/", trim($relativePath));
    while (strpos($normalized, "../") === 0) {
        $normalized = substr($normalized, 3);
    }
    return $normalized;
}

function extract_text_from_docx(string $absolutePath): string
{
    if (!class_exists("ZipArchive")) {
        return "";
    }

    $zip = new ZipArchive();
    if ($zip->open($absolutePath) !== true) {
        return "";
    }

    $xml = $zip->getFromName("word/document.xml");
    $zip->close();

    if (!is_string($xml) || $xml === "") {
        return "";
    }

    $text = strip_tags($xml);
    return trim(preg_replace('/\s+/', ' ', $text) ?? "");
}

function extract_text_from_pdf_like_binary(string $absolutePath): string
{
    $raw = @file_get_contents($absolutePath);
    if (!is_string($raw) || $raw === "") {
        return "";
    }

    $clean = preg_replace('/[^\x20-\x7E]+/', ' ', $raw);
    if (!is_string($clean) || $clean === "") {
        return "";
    }

    return trim(preg_replace('/\s+/', ' ', $clean) ?? "");
}

function get_file_searchable_text(string $relativePath): string
{
    if ($relativePath === "") {
        return "";
    }

    $normalized = normalize_guide_relative_path($relativePath);
    $baseGuidesDir = realpath(__DIR__ . "/../data/guides");
    $absolutePath = realpath(__DIR__ . "/../" . $normalized);

    if (
        !$baseGuidesDir ||
        !$absolutePath ||
        strpos($absolutePath, $baseGuidesDir) !== 0 ||
        !is_file($absolutePath)
    ) {
        return "";
    }

    $ext = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
    $text = "";

    if (in_array($ext, ["txt", "md", "csv", "json", "xml", "html", "htm", "log"], true)) {
        $raw = @file_get_contents($absolutePath);
        if (is_string($raw)) {
            $text = $raw;
        }
    } elseif ($ext === "docx") {
        $text = extract_text_from_docx($absolutePath);
    } elseif ($ext === "pdf") {
        $text = extract_text_from_pdf_like_binary($absolutePath);
    }

    if ($text === "") {
        $text = basename($absolutePath);
    }

    $text = preg_replace('/\s+/', ' ', $text) ?? "";
    return trim(substr($text, 0, 30000));
}

function build_module_searchable_text(array $module): string
{
    $parts = [
        (string) ($module["title"] ?? ""),
        (string) ($module["description"] ?? ""),
        (string) ($module["content"] ?? ""),
        (string) ($module["category"] ?? ""),
        (string) ($module["filePath"] ?? ""),
        get_file_searchable_text((string) ($module["filePath"] ?? ""))
    ];

    $combined = implode(" ", $parts);
    $combined = preg_replace('/\s+/', ' ', $combined) ?? "";
    return trim($combined);
}

$defaultModules = [
    [
        "id" => "1",
        "title" => "Understanding Soil pH for Corn",
        "category" => "Soil Management",
        "description" => "Learn about optimal soil pH levels for corn cultivation and how to adjust them.",
        "content" => "Corn thrives in soil with a pH range of 5.8 to 7.0. Testing your soil regularly is crucial for maintaining optimal conditions. Soil pH affects nutrient availability - if pH is too low (acidic), add lime; if too high (alkaline), add sulfur. Test soil at least once per year, preferably in fall or early spring. Proper pH ensures maximum nutrient uptake and healthy plant growth.",
        "link" => "",
        "createdDate" => "2026-01-10",
        "updatedDate" => "2026-01-10",
        "searchableText" => ""
    ],
    [
        "id" => "2",
        "title" => "Nitrogen Application Timing",
        "category" => "Fertilizer",
        "description" => "Best practices for timing nitrogen fertilizer application in corn farming.",
        "content" => "Nitrogen is the most important nutrient for corn. Apply nitrogen in split applications to maximize efficiency and reduce leaching. First application should be at planting as starter fertilizer. Second application (side-dressing) when plants are 6-8 inches tall. Third application before tasseling for optimal ear development. Total nitrogen needs vary by soil type and yield goals, typically 150-200 lbs/acre for high-yield corn.",
        "link" => "",
        "createdDate" => "2026-01-15",
        "updatedDate" => "2026-02-20",
        "searchableText" => ""
    ],
    [
        "id" => "3",
        "title" => "Common Corn Pests Identification",
        "category" => "Pest Control",
        "description" => "Identify and manage the most common pests affecting corn crops.",
        "content" => "European corn borer, corn earworm, and fall armyworm are among the most damaging pests. Early detection is key to effective control. Scout fields weekly during growing season. Look for egg masses, leaf damage, and larvae. Use integrated pest management: cultural practices, biological controls, and targeted pesticide applications. Bt corn varieties provide excellent protection against many lepidopteran pests.",
        "link" => "",
        "createdDate" => "2026-01-20",
        "updatedDate" => "2026-01-20",
        "searchableText" => ""
    ],
    [
        "id" => "4",
        "title" => "Efficient Drip Irrigation for Corn",
        "category" => "Irrigation",
        "description" => "Implementing drip irrigation systems for water-efficient corn farming.",
        "content" => "Drip irrigation can reduce water usage by up to 50% while maintaining optimal soil moisture levels. Install drip lines 2-3 inches below soil surface for corn. Space emitters 12-18 inches apart for uniform coverage. Monitor soil moisture with sensors. Critical irrigation periods: germination, V6-V10 stages, tasseling, and grain filling. Drip systems also allow for fertigation - delivering nutrients through irrigation water.",
        "link" => "",
        "createdDate" => "2026-02-05",
        "updatedDate" => "2026-02-05",
        "searchableText" => ""
    ],
    [
        "id" => "5",
        "title" => "Determining Harvest Readiness",
        "category" => "Harvesting",
        "description" => "Signs and techniques to determine when corn is ready for harvest.",
        "content" => "Corn is ready for harvest when the moisture content reaches 20-25%. Look for kernel blackening at the base (black layer formation) which indicates physiological maturity. Husk should be dry and brown. Kernels should be dented and firm. Test moisture with a moisture meter. Harvest timing affects grain quality and storage. Early harvest may require drying; late harvest risks lodging and weather damage.",
        "link" => "",
        "createdDate" => "2026-02-15",
        "updatedDate" => "2026-03-01",
        "searchableText" => ""
    ]
];

for ($i = 0; $i < count($defaultModules); $i += 1) {
    $defaultModules[$i]["searchableText"] = build_module_searchable_text($defaultModules[$i]);
}

$modules = $defaultModules;

try {
    require_once __DIR__ . "/../data/db_connect.php";
    $result = $conn->query("SELECT guide_id, module_title, category, short_description, guide_content, external_link, guide_file, DATE_FORMAT(date_created, '%Y-%m-%d') AS created_date, DATE_FORMAT(last_updated, '%Y-%m-%d') AS updated_date FROM guide_module ORDER BY guide_id DESC");
    $dbModules = [];

    while ($row = $result->fetch_assoc()) {
        $module = [
            "id" => (string) ($row["guide_id"] ?? ""),
            "title" => (string) ($row["module_title"] ?? ""),
            "category" => (string) ($row["category"] ?? "General"),
            "description" => (string) ($row["short_description"] ?? ""),
            "content" => (string) ($row["guide_content"] ?? ""),
            "link" => (string) ($row["external_link"] ?? ""),
            "filePath" => (string) ($row["guide_file"] ?? ""),
            "createdDate" => (string) ($row["created_date"] ?? ""),
            "updatedDate" => (string) ($row["updated_date"] ?? "")
        ];

        $module["searchableText"] = build_module_searchable_text($module);
        $dbModules[] = $module;
    }

    if (!empty($dbModules)) {
        $modules = $dbModules;
    }
} catch (Throwable $error) {
    $modules = $defaultModules;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corn Farming Guide | AgriCorn</title>
    <link rel="stylesheet" href="../bootstrap5/css/bootstrap.min.css">
    <style>
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
            --accent: #fff9e6;
            --accent-foreground: #2c3e2e;
            --border: rgba(127, 182, 133, 0.2);
            --ring: #7fb685;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            min-height: 100%;
            font-family: "Inter", "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, rgba(127, 182, 133, 0.05), var(--background), rgba(255, 229, 153, 0.12));
            color: var(--foreground);
        }

        .page {
            min-height: 100dvh;
        }

        .page-head {
            position: sticky;
            top: 0;
            z-index: 40;
            border-bottom: 1px solid rgba(127, 182, 133, 0.3);
            background: linear-gradient(90deg, rgba(127, 182, 133, 0.4), rgba(127, 182, 133, 0.35), rgba(255, 229, 153, 0.4));
            box-shadow: 0 4px 12px rgba(34, 58, 39, 0.12);
            backdrop-filter: blur(4px);
        }

        .head-inner {
            max-width: 1280px;
            margin: 0 auto;
            padding: 14px 24px;
        }

        .head-row {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .back-ghost {
            width: 44px;
            height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: #2f4a32;
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
            font-size: 1.55rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .page-sub {
            margin: 2px 0 0;
            font-size: 0.9rem;
            color: var(--muted-foreground);
        }

        .page-body {
            max-width: 1280px;
            margin: 0 auto;
            padding: 24px 24px 36px;
            display: grid;
            gap: 18px;
        }

        .panel {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 12px 28px rgba(34, 58, 39, 0.12);
        }

        .search-panel {
            padding: 22px;
            display: grid;
            grid-template-columns: minmax(0, 1fr) 220px 220px;
            gap: 12px;
            align-items: center;
        }


        .search-wrap {
            position: relative;
        }

        .search-wrap svg {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            stroke: var(--muted-foreground);
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .search-input,
        .category-select {
            width: 100%;
            border: 1px solid rgba(127, 182, 133, 0.3);
            border-radius: 10px;
            outline: none;
            font-size: 0.92rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            background: #fff;
        }

        .search-input {
            width: 100%;
            height: 46px;
            padding: 0 12px 0 40px;
        }

        .category-select {
            height: 46px;
            padding: 0 12px;
        }

        .search-input:focus,
        .category-select:focus {
            border-color: rgba(127, 182, 133, 0.75);
            box-shadow: 0 0 0 4px rgba(127, 182, 133, 0.15);
        }

        .results-text {
            font-size: 0.85rem;
            color: var(--muted-foreground);
        }

        .module-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
        }

        .module-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 8px 22px rgba(34, 58, 39, 0.1);
            cursor: pointer;
            transition: all 0.25s ease;
            display: grid;
            grid-template-rows: 1fr auto;
            height: 100%;
        }

        .module-card:hover {
            border-color: rgba(127, 182, 133, 0.55);
            box-shadow: 0 14px 28px rgba(34, 58, 39, 0.15);
            transform: translateY(-2px);
        }

        .card-body {
            padding: 16px 16px 8px;
            text-align: center;
            display: flex;
            flex-direction: column;
        }

        .card-head {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .card-icon {
            width: 46px;
            height: 46px;
            border-radius: 11px;
            background: rgba(127, 182, 133, 0.12);
            color: var(--primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: transform 0.2s ease, background 0.2s ease;
        }

        .card-icon svg {
            width: 23px;
            height: 23px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 3px 10px;
            font-size: 0.73rem;
            font-weight: 700;
            border: 1px solid;
            line-height: 1.2;
        }

        .badge svg {
            width: 13px;
            height: 13px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            flex-shrink: 0;
        }

        .badge.soil { background: #fef3c7; color: #b45309; border-color: #fde68a; }
        .badge.fertilizer { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
        .badge.pest { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
        .badge.irrigation { background: #dbeafe; color: #1d4ed8; border-color: #bfdbfe; }
        .badge.harvesting { background: #f3e8ff; color: #7e22ce; border-color: #e9d5ff; }
        .badge.general { background: #f3f4f6; color: #374151; border-color: #e5e7eb; }
        .badge.disease { background: #fff7ed; color: #c2410c; border-color: #fed7aa; }

        .card-title {
            margin: 12px 0 7px;
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.38;
            transition: color 0.2s ease;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            min-height: calc(1.38em * 2);
        }

        .module-card:hover .card-title {
            color: var(--primary);
        }

        .module-card:hover .card-icon {
            transform: scale(1.06);
            background: rgba(127, 182, 133, 0.2);
        }

        .card-desc {
            margin: 0;
            font-size: 0.84rem;
            color: var(--muted-foreground);
            line-height: 1.54;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            min-height: calc(1.54em * 3);
        }

        .source-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border: 1px solid;
            border-radius: 999px;
            padding: 3px 8px;
            font-size: 0.67rem;
            font-weight: 800;
            letter-spacing: 0.02em;
            margin-top: 10px;
            margin-left: auto;
            margin-right: auto;
        }

        .source-chip svg {
            width: 12px;
            height: 12px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            flex-shrink: 0;
        }

        .source-chip.content {
            background: #eef8ec;
            border-color: rgba(22, 101, 52, 0.25);
            color: #166534;
        }

        .source-chip.external {
            background: #eff6ff;
            border-color: rgba(29, 78, 216, 0.25);
            color: #1d4ed8;
        }

        .source-chip.file {
            background: #fff7ed;
            border-color: rgba(180, 83, 9, 0.25);
            color: #b45309;
        }

        .card-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin: 10px 0 0;
            padding-top: 8px;
            border-top: 1px dashed rgba(127, 182, 133, 0.28);
            font-size: 0.72rem;
            color: #718474;
            font-weight: 600;
        }

        .card-foot {
            padding: 0 16px 14px;
        }

        .learn-btn {
            width: 100%;
            height: 38px;
            border-radius: 10px;
            border: 1px solid var(--border);
            margin-left: auto;
            margin-right: auto;
            background: #fff;
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            display: flex;
            justify-content: center;
        }

        .empty {
            width: auto;
            min-width: 132px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 30px;
            text-align: center;
            color: var(--muted-foreground);
        }

        .modal-mask {
            position: fixed;
            inset: 0;
            background: rgba(20, 29, 23, 0.45);
            backdrop-filter: blur(3px);
            display: none;
            z-index: 89;
        }

        .modal-mask.show {
            display: block;
        }

        .modal {
            position: fixed;
            top: 50%;
            left: 50%;
            display: block;
            transform: translate(-50%, -50%) scale(0.98);
            width: min(90vw, 700px);
            height: auto;
            max-height: 84vh;
            overflow: auto;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 16px 34px rgba(30, 47, 33, 0.24);
            padding: 16px;
            opacity: 0;
            pointer-events: none;
            z-index: 90;
            transition: all 0.2s ease;
        }

        .modal.show {
            opacity: 1;
            pointer-events: auto;
            transform: translate(-50%, -50%) scale(1);
        }

        .modal-head {
            display: grid;
            gap: 8px;
            padding: 12px;
            border: 1px solid rgba(127, 182, 133, 0.26);
            border-radius: 12px;
            background: linear-gradient(130deg, rgba(127, 182, 133, 0.15), rgba(255, 229, 153, 0.16));
        }

        .modal-title-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        #modalTitle {
            font-size: 1.14rem;
            font-weight: 800;
            line-height: 1.25;
        }

        .modal-icon {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            background: rgba(127, 182, 133, 0.12);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            flex-shrink: 0;
        }

        .modal-icon svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .modal-desc {
            margin: 0;
            font-size: 0.88rem;
            color: #4d6351;
            line-height: 1.5;
        }

        .modal-body {
            padding-top: 12px;
            display: grid;
            gap: 12px;
        }

        .content-block {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 13px;
            background: var(--accent);
        }

        .content-label {
            margin: 0 0 8px;
            font-size: 0.92rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .content-label svg {
            width: 14px;
            height: 14px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .content-copy {
            margin: 0;
            font-size: 0.84rem;
            line-height: 1.55;
            white-space: pre-wrap;
        }

        .resource-block {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 13px;
            background: #f9fcf7;
        }

        .resource-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .resource-link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 700;
            border: 1px solid rgba(127, 182, 133, 0.36);
            border-radius: 8px;
            padding: 9px 10px;
            min-height: 40px;
            background: #fff;
            color: #35643b;
            transition: all 0.18s ease;
        }

        .resource-link:hover {
            border-color: rgba(127, 182, 133, 0.55);
            background: #eef8ec;
            color: #28592f;
        }

        .resource-link.view {
            border-color: rgba(59, 130, 246, 0.35);
            color: #1d4ed8;
        }

        .resource-link.view:hover {
            background: #eff6ff;
            border-color: rgba(37, 99, 235, 0.48);
        }

        .resource-link.download {
            border-color: rgba(217, 119, 6, 0.34);
            color: #b45309;
        }

        .resource-link.download:hover {
            background: #fff7ed;
            border-color: rgba(180, 83, 9, 0.45);
        }

        .resource-link svg {
            width: 14px;
            height: 14px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
        }

        .meta-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            font-size: 0.75rem;
            color: #4f6553;
        }

        .meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid rgba(127, 182, 133, 0.3);
            background: #f6fbf4;
            font-weight: 700;
            line-height: 1;
        }

        .meta-pill svg {
            width: 13px;
            height: 13px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .modal-close {
            height: 38px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: var(--primary);
            color: #fff;
            font-size: 0.82rem;
            font-weight: 700;
            line-height: 1;
            justify-self: end;
            min-width: 132px;
            padding: 0 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            white-space: nowrap;
            border-color: var(--primary);
            cursor: pointer;
        }

        .modal-close svg {
            width: 14px;
            height: 14px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            flex-shrink: 0;
        }

        .modal-close span {
            display: inline-block;
            white-space: nowrap;
        }

        .modal-close:hover {
            background: #6aa472;
            color: #fff;
        }

        @media (max-width: 980px) {
            .search-panel {
                grid-template-columns: 1fr;
            }

            .module-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 720px) {
            .head-inner,
            .page-body {
                padding-left: 14px;
                padding-right: 14px;
            }

            .page-title {
                font-size: 1.25rem;
            }

            .module-grid {
                grid-template-columns: minmax(0, 1fr);
            }

            .meta-row {
                flex-direction: column;
                align-items: flex-start;
            }

            .resource-actions {
                grid-template-columns: 1fr;
            }

            .resource-link {
                width: 100%;
                justify-content: center;
            }

            .modal-close {
                width: 100%;
                justify-self: stretch;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <header class="page-head">
            <div class="head-inner">
                <div class="head-row">
                    <button class="back-ghost" id="backBtn" type="button" aria-label="Back to features">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 5-7 7 7 7 1.5-1.5-5.5-5.5 5.5-5.5z"></path></svg>
                    </button>
                    <div>
                        <h1 class="page-title">Corn Farming Guide</h1>
                        <p class="page-sub">Expert tips and best practices for successful farming</p>
                    </div>
                </div>
            </div>
        </header>

        <main class="page-body">
            <section class="panel search-panel">
                <div class="search-wrap">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                    <input class="search-input" id="searchInput" type="text" placeholder="Search farming guides...">
                </div>
                <select class="category-select" id="categoryFilter" aria-label="Filter by category"></select>
                <select class="category-select" id="sourceFilter" aria-label="Filter by source type"></select>
            </section>

            <div class="results-text" id="resultCount"></div>

            <section class="module-grid" id="moduleGrid"></section>
        </main>
    </div>

    <div class="modal-mask" id="modalMask"></div>
    <div class="modal" id="moduleModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-head">
            <div class="modal-title-row">
                <span class="modal-icon" id="modalTitleIcon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5h7a3 3 0 0 1 3 3v11H7a3 3 0 0 0-3 3V5z"></path><path d="M20 5h-7a3 3 0 0 0-3 3v11h7a3 3 0 0 1 3 3V5z"></path></svg></span>
                <span id="modalTitle"></span>
            </div>
            <span id="modalCategoryBadge" class="badge general"></span>
            <p class="modal-desc" id="modalDescription"></p>
        </div>

        <div class="modal-body">
            <div class="content-block" id="contentBlock">
                <p class="content-label"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h7a3 3 0 0 1 3 3v11H7a3 3 0 0 0-3 3V5z"></path><path d="M20 5h-7a3 3 0 0 0-3 3v11h7a3 3 0 0 1 3 3V5z"></path></svg><span>Guide Content</span></p>
                <p class="content-copy" id="modalContent"></p>
            </div>

            <div class="resource-block" id="resourceBlock" style="display:none;">
                <p class="content-label"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3h7v7"></path><path d="M10 14 21 3"></path><path d="M21 14v7h-7"></path><path d="M3 10v11h11"></path></svg><span>Additional Resources</span></p>
                <div class="resource-actions">
                    <a class="resource-link" id="modalExternalLink" href="#" target="_blank" rel="noopener noreferrer">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3h7v7"></path><path d="M10 14 21 3"></path><path d="M21 14v7h-7"></path><path d="M3 10v11h11"></path></svg>
                        View External Guide
                    </a>
                    <a class="resource-link view" id="modalFileViewLink" href="#" target="_blank" rel="noopener noreferrer">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        View Resource File
                    </a>
                    <a class="resource-link download" id="modalFileDownloadLink" href="#">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        Download Resource File
                    </a>
                </div>
            </div>

            <div class="meta-row" id="modalMeta"></div>

            <button class="modal-close" id="closeModalBtn" type="button"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg><span>Close Guide</span></button>
        </div>
    </div>

    <script>
        (function () {
            var modules = <?php echo json_encode($modules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            
            // Auto-filter based on URL parameter (from Scanner)
            var urlParams = new URLSearchParams(window.location.search);
            var selectedCategory = urlParams.get('category') || "All";
            var selectedSource = urlParams.get('source') || "All Sources";
            
            var selectedModule = null;
            var visibleModules = [];

            var backBtn = document.getElementById("backBtn");
            var searchInput = document.getElementById("searchInput");
            var categoryFilter = document.getElementById("categoryFilter");
            var sourceFilter = document.getElementById("sourceFilter");
            var resultCount = document.getElementById("resultCount");
            var moduleGrid = document.getElementById("moduleGrid");

            var modalMask = document.getElementById("modalMask");
            var moduleModal = document.getElementById("moduleModal");
            var modalTitleIcon = document.getElementById("modalTitleIcon");
            var modalTitle = document.getElementById("modalTitle");
            var modalCategoryBadge = document.getElementById("modalCategoryBadge");
            var modalDescription = document.getElementById("modalDescription");
            var contentBlock = document.getElementById("contentBlock");
            var modalContent = document.getElementById("modalContent");
            var resourceBlock = document.getElementById("resourceBlock");
            var modalExternalLink = document.getElementById("modalExternalLink");
            var modalFileViewLink = document.getElementById("modalFileViewLink");
            var modalFileDownloadLink = document.getElementById("modalFileDownloadLink");
            var modalMeta = document.getElementById("modalMeta");
            var closeModalBtn = document.getElementById("closeModalBtn");

            function escapeHtml(text) {
                return String(text || "")
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/\"/g, "&quot;")
                    .replace(/'/g, "&#039;");
            }

            function categoryBadgeClass(category) {
                if (category === "Soil Management") return "soil";
                if (category === "Fertilizer") return "fertilizer";
                if (category === "Pest Control") return "pest";
                if (category === "Irrigation") return "irrigation";
                if (category === "Harvesting") return "harvesting";
                if (category === "Corn Leaf Disease") return "disease";
                return "general";
            }

            function getCategoryIcon(category) {
                if (category === "Soil Management") {
                    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v8"></path><path d="M8 8c0-2.2 1.8-4 4-4"></path><path d="M16 8c0-2.2-1.8-4-4-4"></path><path d="M4 15c2 0 3 1 4 2 1-1 2-2 4-2s3 1 4 2c1-1 2-2 4-2"></path><path d="M4 19c2 0 3 1 4 2 1-1 2-2 4-2s3 1 4 2c1-1 2-2 4-2"></path></svg>';
                }

                if (category === "Fertilizer") {
                    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5h8"></path><path d="M7 9h10"></path><path d="M6 13h12"></path><path d="M10 17h4"></path><path d="M12 3v18"></path></svg>';
                }

                if (category === "Pest Control") {
                    return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="13" r="3"></circle><path d="M12 10V5"></path><path d="M9.5 6.5 12 5l2.5 1.5"></path><path d="M6 11H3"></path><path d="M18 11h3"></path><path d="M7 16l-2 2"></path><path d="M17 16l2 2"></path></svg>';
                }

                if (category === "Irrigation") {
                    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3s4 4.5 4 8a4 4 0 1 1-8 0c0-3.5 4-8 4-8z"></path><path d="M5 18c2 0 3 1 4 2 1-1 2-2 3-2s2 1 3 2c1-1 2-2 4-2"></path></svg>';
                }

                if (category === "Harvesting") {
                    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v16"></path><path d="M9 8c-2 0-3.5 1.5-3.5 3.5S7 15 9 15"></path><path d="M15 8c2 0 3.5 1.5 3.5 3.5S17 15 15 15"></path><path d="M9 19h6"></path></svg>';
                }

                if (category === "Corn Leaf Disease") {
                    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 14c0-6 6-10 16-10 0 10-4 16-10 16-3.5 0-6-2.5-6-6z"></path><path d="M8 16 16 8"></path><circle cx="14" cy="12" r="1"></circle><circle cx="11" cy="14" r="1"></circle></svg>';
                }

                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h7a3 3 0 0 1 3 3v11H7a3 3 0 0 0-3 3V5z"></path><path d="M20 5h-7a3 3 0 0 0-3 3v11h7a3 3 0 0 1 3 3V5z"></path></svg>';
            }

            function getSourceTypeIcon(sourceType) {
                if (sourceType === "External Link") {
                    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3h7v7"></path><path d="M10 14 21 3"></path><path d="M21 14v7h-7"></path><path d="M3 10v11h11"></path></svg>';
                }

                if (sourceType === "File Resource") {
                    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path></svg>';
                }

                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h7a3 3 0 0 1 3 3v11H7a3 3 0 0 0-3 3V5z"></path><path d="M20 5h-7a3 3 0 0 0-3 3v11h7a3 3 0 0 1 3 3V5z"></path></svg>';
            }

            function formatDate(dateValue) {
                if (!dateValue) {
                    return "-";
                }
                var parsed = new Date(String(dateValue) + "T00:00:00");
                if (isNaN(parsed.getTime())) {
                    return String(dateValue);
                }
                return parsed.toLocaleDateString("en-US");
            }

            function buildGuideFileUrl(item, mode) {
                if (!item || !item.id) {
                    return item && item.filePath ? item.filePath : "#";
                }

                return "corn_farming_guide.php?guide_id=" + encodeURIComponent(String(item.id)) + "&file_mode=" + encodeURIComponent(mode);
            }

            function getCategories() {
                var found = { All: true };
                for (var i = 0; i < modules.length; i += 1) {
                    found[modules[i].category] = true;
                }
                return Object.keys(found);
            }

            function getSourceType(item) {
                var hasFile = String(item.filePath || "").trim() !== "";
                var hasExternalLink = String(item.link || "").trim() !== "";

                if (hasFile) {
                    return "File Resource";
                }

                if (hasExternalLink) {
                    return "External Link";
                }

                return "Guide Content";
            }

            function sourceTypeClass(sourceType) {
                if (sourceType === "External Link") {
                    return "external";
                }

                if (sourceType === "File Resource") {
                    return "file";
                }

                return "content";
            }


            function getSourceFilters() {
                return ["All Sources", "Guide Content", "External Link", "File Resource"];
            }

            function filteredModules() {
                var query = searchInput.value.trim().toLowerCase();
                return modules.filter(function (item) {
                    var title = (item.title || "").toLowerCase();
                    var desc = (item.description || "").toLowerCase();
                    var content = (item.content || "").toLowerCase();
                    var searchableText = (item.searchableText || "").toLowerCase();
                    var matchesText =
                        title.indexOf(query) !== -1 ||
                        desc.indexOf(query) !== -1 ||
                        content.indexOf(query) !== -1 ||
                        searchableText.indexOf(query) !== -1;
                    var matchesCategory = selectedCategory === "All" || item.category === selectedCategory;
                    var sourceType = getSourceType(item);
                    var matchesSource = selectedSource === "All Sources" || sourceType === selectedSource;
                    return matchesText && matchesCategory && matchesSource;
                });
            }

            function renderCategories() {
                var categories = getCategories();
                var html = "";
                for (var i = 0; i < categories.length; i += 1) {
                    var category = categories[i];
                    var label = category === "All" ? "All Categories" : category;
                    html += '<option value="' + escapeHtml(category) + '">' + escapeHtml(label) + '</option>';
                }
                categoryFilter.innerHTML = html;
                categoryFilter.value = selectedCategory;
                if (categoryFilter.value !== selectedCategory) {
                    selectedCategory = "All";
                    categoryFilter.value = "All";
                }
            }

            function renderSourceFilters() {
                var sources = getSourceFilters();
                var html = "";

                for (var i = 0; i < sources.length; i += 1) {
                    var source = sources[i];
                    html += '<option value="' + escapeHtml(source) + '">' + escapeHtml(source) + '</option>';
                }

                sourceFilter.innerHTML = html;
                sourceFilter.value = selectedSource;
                if (sourceFilter.value !== selectedSource) {
                    selectedSource = "All Sources";
                    sourceFilter.value = "All Sources";
                }
            }

            function renderModules() {
                var rows = filteredModules();
                visibleModules = rows.slice();
                resultCount.textContent = "Showing " + rows.length + " of " + modules.length + " module" + (modules.length !== 1 ? "s" : "");

                if (!rows.length) {
                    visibleModules = [];
                    moduleGrid.innerHTML = '<div class="empty">No modules found<br><small>Try adjusting your search or filter criteria</small></div>';
                    return;
                }

                var html = "";
                for (var i = 0; i < rows.length; i += 1) {
                    var item = rows[i];
                    var sourceType = getSourceType(item);
                    var sourceClass = sourceTypeClass(sourceType);
                    html +=
                        '<article class="module-card" data-index="' + i + '" data-id="' + escapeHtml(item.id) + '">' +
                            '<div class="card-body">' +
                                '<div class="card-head">' +
                                    '<span class="card-icon">' + getCategoryIcon(item.category) + '</span>' +
                                    '<span class="badge ' + categoryBadgeClass(item.category) + '">' + getCategoryIcon(item.category) + '<span>' + escapeHtml(item.category || "General") + '</span></span>' +
                                '</div>' +
                                '<h3 class="card-title">' + escapeHtml(item.title) + '</h3>' +
                                '<p class="card-desc">' + escapeHtml(item.description) + '</p>' +
                                '<span class="source-chip ' + sourceClass + '">' + getSourceTypeIcon(sourceType) + '<span>' + escapeHtml(sourceType) + '</span></span>' +
                            '</div>' +
                            '<div class="card-foot"><button class="learn-btn" type="button">Learn More</button></div>' +
                        '</article>';
                }

                moduleGrid.innerHTML = html;
            }

            function openModal(item) {
                selectedModule = item;
                modalTitle.textContent = item.title || "Guide Module";
                modalTitleIcon.innerHTML = getCategoryIcon(item.category || "General");
                modalDescription.textContent = item.description || "";
                modalCategoryBadge.className = "badge " + categoryBadgeClass(item.category || "General");
                modalCategoryBadge.innerHTML = getCategoryIcon(item.category || "General") + '<span>' + escapeHtml(item.category || "General") + '</span>';

                if (item.link || item.filePath) {
                    resourceBlock.style.display = "block";
                    modalExternalLink.style.display = item.link ? "inline-flex" : "none";
                    modalExternalLink.href = item.link || "#";
                    modalFileViewLink.style.display = item.filePath ? "inline-flex" : "none";
                    modalFileDownloadLink.style.display = item.filePath ? "inline-flex" : "none";
                    modalFileViewLink.href = buildGuideFileUrl(item, "view");
                    modalFileDownloadLink.href = buildGuideFileUrl(item, "download");
                } else {
                    resourceBlock.style.display = "none";
                }

                if (item.content) {
                    contentBlock.style.display = "block";
                    modalContent.textContent = item.content;
                } else {
                    contentBlock.style.display = "none";
                    modalContent.textContent = "";
                }

                var created = '<span class="meta-pill"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M16 2v4"></path><path d="M8 2v4"></path><path d="M3 10h18"></path></svg><span>Created: ' + escapeHtml(formatDate(item.createdDate)) + '</span></span>';
                var updated = (item.updatedDate && item.updatedDate !== item.createdDate)
                    ? '<span class="meta-pill"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3.2-6.9"></path><path d="M21 3v6h-6"></path></svg><span>Updated: ' + escapeHtml(formatDate(item.updatedDate)) + '</span></span>'
                    : "";
                modalMeta.innerHTML = created + updated;

                modalMask.classList.add("show");
                moduleModal.classList.add("show");
            }

            function closeModal() {
                selectedModule = null;
                modalMask.classList.remove("show");
                moduleModal.classList.remove("show");
            }

            backBtn.addEventListener("click", function () {
                window.location.href = "farmer_dashboard.php?view=features";
            });

            searchInput.addEventListener("input", renderModules);

            categoryFilter.addEventListener("change", function () {
                selectedCategory = categoryFilter.value || "All";
                renderCategories();
                renderModules();
            });

            sourceFilter.addEventListener("change", function () {
                selectedSource = sourceFilter.value || "All Sources";
                renderSourceFilters();
                renderModules();
            });

            moduleGrid.addEventListener("click", function (event) {
                var card = event.target.closest("[data-id]");
                if (!card) {
                    return;
                }

                var cardIndex = parseInt(card.getAttribute("data-index"), 10);
                if (!isNaN(cardIndex) && visibleModules[cardIndex]) {
                    openModal(visibleModules[cardIndex]);
                    return;
                }

                var id = card.getAttribute("data-id");
                for (var i = 0; i < modules.length; i += 1) {
                    if (String(modules[i].id) === id) {
                        openModal(modules[i]);
                        break;
                    }
                }
            });

            closeModalBtn.addEventListener("click", closeModal);
            modalMask.addEventListener("click", closeModal);

            document.addEventListener("keydown", function (event) {
                if (event.key === "Escape") {
                    closeModal();
                }
            });

            renderCategories();
            renderSourceFilters();
            renderModules();
        })();
    </script>
</body>
</html>
