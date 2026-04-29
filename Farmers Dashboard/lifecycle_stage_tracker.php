<?php
session_start();

if (!isset($_SESSION["users_id"])) {
	header("Location: ../login.php");
	exit;
}

// --- Lifecycle API Consolidation START ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $user_id = (int)$_SESSION["users_id"];
    require_once __DIR__ . '/../data/db_connect.php';

    // Initialize Table if not exists
    $init_sql = "CREATE TABLE IF NOT EXISTS `lifecycle_journal` (
        `lifecycle_id` INT(11) AUTO_INCREMENT PRIMARY KEY,
        `users_id` INT(11) NOT NULL,
        `stage_number` INT(11) NOT NULL,
        `journal_text` TEXT DEFAULT NULL,
        `image_path` VARCHAR(255) DEFAULT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `user_stage` (`users_id`, `stage_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $conn->query($init_sql);

    // Migration Check: Rename id to lifecycle_id if it still exists
    $check_col = $conn->query("SHOW COLUMNS FROM `lifecycle_journal` LIKE 'id'");
    if ($check_col && $check_col->num_rows > 0) {
        $conn->query("ALTER TABLE `lifecycle_journal` CHANGE `id` `lifecycle_id` INT(11) AUTO_INCREMENT;");
    }

    $action = $_GET['action'];
    switch ($action) {
        case 'load':
            $stage_number = (int)($_GET['stage_number'] ?? 0);
            $stmt = $conn->prepare("SELECT journal_text, image_path FROM lifecycle_journal WHERE users_id = ? AND stage_number = ? LIMIT 1");
            $stmt->bind_param("ii", $user_id, $stage_number);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            echo json_encode([
                'success' => true,
                'journal' => $row['journal_text'] ?? '',
                'image_path' => $row['image_path'] ?? null
            ]);
            break;

        case 'save':
            $input = json_decode(file_get_contents('php://input'), true);
            $stage_number = (int)($input['stage_number'] ?? 0);
            $journal = $input['journal'] ?? '';
            $image_path = $input['image_path'] ?? null;

            $stmt = $conn->prepare("INSERT INTO lifecycle_journal (users_id, stage_number, journal_text, image_path) 
                                    VALUES (?, ?, ?, ?) 
                                    ON DUPLICATE KEY UPDATE journal_text = VALUES(journal_text), image_path = IFNULL(VALUES(image_path), image_path)");
            $stmt->bind_param("iiss", $user_id, $stage_number, $journal, $image_path);
            echo json_encode(['success' => $stmt->execute()]);
            break;

        case 'upload':
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
				$upload_dir = dirname(__DIR__) . '/data/Lifecycle Stage Image/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                $filename = 'crop_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $filename)) {
						echo json_encode(['success' => true, 'path' => '../data/Lifecycle Stage Image/' . $filename]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Move failed']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'No file']);
            }
            break;
    }
    $conn->close();
    exit;
}
// --- Lifecycle API Consolidation END ---

$displayName = trim((string) ($_SESSION["name"] ?? ""));
$displayUsername = trim((string) ($_SESSION["username"] ?? ""));

if ($displayName === "") {
	$displayName = "Farmer";
}

if ($displayUsername === "") {
	$displayUsername = "farmer";
}

$displayHandle = "@" . ltrim($displayUsername, "@");

$initials = "";
$tokens = preg_split('/\s+/', trim($displayName));
if (is_array($tokens)) {
	foreach ($tokens as $token) {
		if ($token === "") {
			continue;
		}
		$initials .= strtoupper(substr($token, 0, 1));
		if (strlen($initials) >= 2) {
			break;
		}
	}
}
if ($initials === "") {
	$initials = "AC";
}

$profileFileSource = trim((string) ($_SESSION["name"] ?? ""));
if ($profileFileSource === "") {
	$profileFileSource = trim((string) ($_SESSION["username"] ?? ""));
}
if ($profileFileSource === "") {
	$profileFileSource = "Farmer" . (string) $_SESSION["users_id"];
}

$profileFileSafe = preg_replace('/[^A-Za-z0-9_-]+/', '', str_replace(' ', '', $profileFileSource));
if ($profileFileSafe === "") {
	$profileFileSafe = "Farmer" . (string) $_SESSION["users_id"];
}

$savedPlantingProfilePath = __DIR__ . "/../data/Corn Profile/" . $profileFileSafe . ".json";
$savedPlantingProfile = null;
$hasPlantingProfile = false;
$todayLifecycleStageNumber = 0;

$calculateTodayLifecycleStage = static function (string $plantingDateRaw, int $harvestDays): int {
	$harvestDays = $harvestDays > 0 ? $harvestDays : 95;
	$plantingDate = DateTime::createFromFormat('Y-m-d', $plantingDateRaw);
	if (!$plantingDate) {
		try {
			$plantingDate = new DateTime($plantingDateRaw);
		} catch (Exception $e) {
			return 0;
		}
	}

	$today = new DateTime('today');
	$plantingDate->setTime(0, 0, 0);
	$elapsedDays = (int) $plantingDate->diff($today)->format('%r%a');
	$elapsedDays = max($elapsedDays + 1, 1);
	$elapsedDays = min($elapsedDays, $harvestDays);

	$stagePercents = [2, 5, 9, 14, 19, 24, 29, 34, 39, 44, 49, 55, 62, 69, 76, 83, 91, 97, 100];
	$startDay = 1;
	$stageNumber = count($stagePercents);
	for ($stageIdx = 0; $stageIdx < count($stagePercents); $stageIdx += 1) {
		$percent = (int) $stagePercents[$stageIdx];
		$isLast = $stageIdx === (count($stagePercents) - 1);
		$maxDay = $isLast
			? $harvestDays
			: max($startDay, (int) round($harvestDays * $percent / 100));

		if ($elapsedDays <= $maxDay) {
			$stageNumber = $stageIdx + 1;
			break;
		}

		$startDay = $maxDay + 1;
	}

	return $stageNumber;
};

try {
	require_once __DIR__ . '/../data/db_connect.php';
	$userId = (int) $_SESSION["users_id"];
	$stmtProfile = $conn->prepare("SELECT planting_date, estimated_harvest_date FROM corn_profile WHERE users_id = ? AND status = 'active' ORDER BY corn_profile_id DESC LIMIT 1");
	if ($stmtProfile) {
		$stmtProfile->bind_param("i", $userId);
		$stmtProfile->execute();
		$resultProfile = $stmtProfile->get_result();
		$dbProfile = $resultProfile ? $resultProfile->fetch_assoc() : null;
		$stmtProfile->close();

		$plantingDateDb = trim((string) ($dbProfile["planting_date"] ?? ""));
		if ($plantingDateDb !== "") {
			$hasPlantingProfile = true;

			$estimatedHarvestDb = trim((string) ($dbProfile["estimated_harvest_date"] ?? ""));
			$harvestDays = 95;
			if ($estimatedHarvestDb !== "") {
				$plantingDateObj = DateTime::createFromFormat('Y-m-d', $plantingDateDb);
				$harvestDateObj = DateTime::createFromFormat('Y-m-d', $estimatedHarvestDb);
				if ($plantingDateObj instanceof DateTime && $harvestDateObj instanceof DateTime) {
					$harvestDays = (int) $plantingDateObj->diff($harvestDateObj)->format('%a');
					if ($harvestDays <= 0) {
						$harvestDays = 95;
					}
				}
			}

			$savedPlantingProfile = [
				"plantingDate" => $plantingDateDb,
				"daysToHarvestMax" => $harvestDays
			];
			$todayLifecycleStageNumber = $calculateTodayLifecycleStage($plantingDateDb, $harvestDays);
		}
	}
} catch (Throwable $e) {
	// Keep fallback silent and continue if DB is unavailable.
}

$stages = [
	["number" => 1, "name" => "Seed", "day" => "Seed", "description" => "Seed is selected and prepared before water uptake begins", "visual" => "Seed", "visualType" => "seed"],
	["number" => 2, "name" => "Germination", "day" => "Germination", "description" => "Seed absorbs water and starts initial root and shoot growth", "visual" => "Sprout", "visualType" => "sprout"],
	["number" => 3, "name" => "Emergence (VE)", "day" => "Emergence", "description" => "Shoot emerges above the soil surface", "visual" => "Sprout", "visualType" => "sprout"],
	["number" => 4, "name" => "V1 - First leaf with visible collar", "day" => "Vegetative", "description" => "First true leaf with visible collar appears", "visual" => "Seedling", "visualType" => "seedling"],
	["number" => 5, "name" => "V2 - Second leaf", "day" => "Vegetative", "description" => "Second leaf collar is visible", "visual" => "Seedling", "visualType" => "seedling"],
	["number" => 6, "name" => "V3 - Third leaf", "day" => "Vegetative", "description" => "Third leaf stage with stronger root anchoring", "visual" => "Vegetative", "visualType" => "vegetative"],
	["number" => 7, "name" => "V4 - Fourth leaf", "day" => "Vegetative", "description" => "Leaf area expands and nutrient demand increases", "visual" => "Vegetative", "visualType" => "vegetative"],
	["number" => 8, "name" => "V5 - Fifth leaf", "day" => "Vegetative", "description" => "Plant grows taller with more vigorous canopy", "visual" => "Vegetative", "visualType" => "vegetative"],
	["number" => 9, "name" => "V6 - Sixth leaf", "day" => "Vegetative", "description" => "Rapid vegetative growth continues", "visual" => "Vegetative", "visualType" => "vegetative"],
	["number" => 10, "name" => "V7 - Seventh leaf", "day" => "Vegetative", "description" => "Canopy development and nutrient uptake stay high", "visual" => "Vegetative", "visualType" => "vegetative"],
	["number" => 11, "name" => "V8 - Eighth leaf", "day" => "Vegetative", "description" => "Plant height and biomass accumulation increase", "visual" => "Vegetative", "visualType" => "vegetative"],
	["number" => 12, "name" => "V9 - Ninth leaf", "day" => "Vegetative", "description" => "Final common vegetative checkpoint before reproductive transition", "visual" => "Vegetative", "visualType" => "vegetative"],
	["number" => 13, "name" => "R1 - Silking", "day" => "Reproductive", "description" => "Silks become visible and pollination occurs", "visual" => "Silking", "visualType" => "silking"],
	["number" => 14, "name" => "R2 - Blister", "day" => "Reproductive", "description" => "Kernels are white and filled with clear liquid", "visual" => "Silking", "visualType" => "silking"],
	["number" => 15, "name" => "R3 - Milk", "day" => "Reproductive", "description" => "Kernels contain milky fluid during grain fill", "visual" => "Silking", "visualType" => "silking"],
	["number" => 16, "name" => "R4 - Dough", "day" => "Reproductive", "description" => "Kernels become starchy and dough-like", "visual" => "Mature", "visualType" => "mature"],
	["number" => 17, "name" => "R5 - Dent", "day" => "Reproductive", "description" => "Kernel tops dent as starch accumulation continues", "visual" => "Mature", "visualType" => "mature"],
	["number" => 18, "name" => "R6 - Physiological Maturity", "day" => "Reproductive", "description" => "Black layer forms and kernels are fully mature", "visual" => "Mature", "visualType" => "mature"],
	["number" => 19, "name" => "Harvest", "day" => "Final", "description" => "Crop is ready for harvest and post-harvest handling", "visual" => "Harvest", "visualType" => "harvest"],
];

$stageCompletionByNumber = [];
$todayLifecycleStageNames = [];
$todayLifecycleNotificationCount = 0;

if ($hasPlantingProfile) {
	require_once __DIR__ . '/../data/db_connect.php';

	$init_sql = "CREATE TABLE IF NOT EXISTS `lifecycle_journal` (
		`lifecycle_id` INT(11) AUTO_INCREMENT PRIMARY KEY,
		`users_id` INT(11) NOT NULL,
		`stage_number` INT(11) NOT NULL,
		`journal_text` TEXT DEFAULT NULL,
		`image_path` VARCHAR(255) DEFAULT NULL,
		`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		UNIQUE KEY `user_stage` (`users_id`, `stage_number`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
	$conn->query($init_sql);

	$user_id = (int) $_SESSION["users_id"];
	$stmt = $conn->prepare("SELECT stage_number, journal_text, image_path, DATE(updated_at) = CURDATE() AS is_today FROM lifecycle_journal WHERE users_id = ?");
	if ($stmt) {
		$stmt->bind_param("i", $user_id);
		$stmt->execute();
		$result = $stmt->get_result();

		$stageNameByNumber = [];
		foreach ($stages as $stage) {
			$stageNameByNumber[(int) ($stage["number"] ?? 0)] = (string) ($stage["name"] ?? "Stage");
		}

		while ($row = $result->fetch_assoc()) {
			$stageNumber = (int) ($row["stage_number"] ?? 0);
			$journalText = trim((string) ($row["journal_text"] ?? ""));
			$imagePath = trim((string) ($row["image_path"] ?? ""));
			$isDone = ($journalText !== "") || ($imagePath !== "");

			if ($isDone) {
				$stageCompletionByNumber[$stageNumber] = true;
				$isToday = (int) ($row["is_today"] ?? 0) === 1;
				if ($isToday) {
					$todayLifecycleStageNames[] = $stageNameByNumber[$stageNumber] ?? ("Stage " . $stageNumber);
				}
			}
		}

		$stmt->close();
	}

	$todayLifecycleStageNames = array_values(array_unique($todayLifecycleStageNames));
	$todayLifecycleNotificationCount = count($todayLifecycleStageNames);
	$conn->close();
}

$stageCardStates = [];

$todayUnlockStage = $hasPlantingProfile ? max(min($todayLifecycleStageNumber, count($stages)), 1) : 0;

foreach ($stages as $stage) {
	$stageNumber = (int) ($stage["number"] ?? 0);
	$isDone = !empty($stageCompletionByNumber[$stageNumber]);
	$isLocked = !$hasPlantingProfile || (!$isDone && $stageNumber > $todayUnlockStage);

	$stageCardStates[$stageNumber] = [
		"done" => $isDone,
		"locked" => $isLocked
	];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Lifecycle Stage Tracker</title>
	<link rel="stylesheet" href="../bootstrap5/css/bootstrap.min.css">
	<style>
		:root {
			--background: #fafdf7;
			--foreground: #2c3e2e;
			--card: #ffffff;
			--primary: #7fb685;
			--secondary: #ffe599;
			--muted: #e8f3ea;
			--muted-foreground: #6b7c6e;
			--border: rgba(127, 182, 133, 0.3);
			--line: rgba(127, 182, 133, 0.28);
			--radius: 14px;
			--shadow-sm: 0 4px 14px rgba(37, 56, 40, 0.09);
			--shadow-md: 0 10px 28px rgba(37, 56, 40, 0.14);
			--shadow: 0 12px 28px rgba(34, 58, 39, 0.12);
			--shadow-lg: 0 18px 44px rgba(34, 58, 39, 0.18);
		}

		* {
			box-sizing: border-box;
		}

		html,
		body {
			height: 100%;
			min-height: 100%;
			margin: 0;
			background: var(--background);
			color: var(--foreground);
			font-family: "Inter", "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
			overflow-x: hidden;
			scrollbar-width: none;
			-ms-overflow-style: none;
		}

		html::-webkit-scrollbar,
		body::-webkit-scrollbar {
			width: 0;
			height: 0;
		}

		body {
			background-image:
				radial-gradient(circle at 12% 18%, rgba(127, 182, 133, 0.2), transparent 42%),
				radial-gradient(circle at 86% 82%, rgba(255, 229, 153, 0.22), transparent 40%),
				linear-gradient(135deg, rgba(127, 182, 133, 0.14), rgba(250, 253, 247, 1) 46%, rgba(255, 229, 153, 0.2));
			background-repeat: no-repeat;
			background-size: cover;
			background-position: center;
			background-attachment: fixed;
		}

		.page-head {
			position: sticky;
			top: 0;
			z-index: 40;
			border-bottom: 1px solid var(--line);
			box-shadow: var(--shadow-sm);
			background: linear-gradient(90deg, rgba(127, 182, 133, 0.4), rgba(127, 182, 133, 0.35), rgba(255, 229, 153, 0.4));
			backdrop-filter: blur(8px);
		}

		.head-inner,
		.page-inner {
			max-width: 1280px;
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

		.header-stage-title {
			font-size: 1.18rem;
			font-weight: 700;
			margin: 0 8px 0 0;
			color: var(--foreground);
		}

		.header-stage-pill {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 6px 12px;
			border-radius: 999px;
			background: rgba(255,255,255,0.96);
			color: var(--foreground);
			font-weight: 700;
			font-size: 0.95rem;
			border: 1px solid rgba(127,182,133,0.12);
			box-shadow: var(--shadow-sm);
			margin-left: 12px;
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
			font-weight: 700;
			font-size: 1.55rem;
			line-height: 1.2;
		}

		.page-sub {
			margin: 2px 0 0;
			font-size: 0.9rem;
			color: var(--muted-foreground);
		}

		.tracker-alert {
			display: flex;
			align-items: flex-start;
			justify-content: space-between;
			gap: 12px;
			padding: 12px 14px;
			border-radius: 12px;
			border: 1px solid rgba(127, 182, 133, 0.36);
			background: rgba(127, 182, 133, 0.1);
			margin-bottom: 14px;
		}

		.tracker-alert.profile-required {
			align-items: center;
			border-color: rgba(217, 178, 74, 0.58);
			background: linear-gradient(135deg, rgba(255, 239, 180, 0.84), rgba(255, 229, 153, 0.48));
			box-shadow: 0 10px 24px rgba(122, 90, 18, 0.1);
			position: relative;
			overflow: hidden;
			padding: 14px 16px;
		}

		.tracker-alert.profile-required::before {
			content: "";
			position: absolute;
			left: 0;
			top: 0;
			bottom: 0;
			width: 5px;
			background: linear-gradient(180deg, #d9b24a, #b2861f);
		}

		.profile-required-body {
			display: flex;
			align-items: flex-start;
			gap: 12px;
			min-width: 0;
		}

		.profile-required-icon {
			width: 34px;
			height: 34px;
			border-radius: 10px;
			background: rgba(217, 178, 74, 0.28);
			color: #7a5a12;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			flex: 0 0 auto;
			box-shadow: inset 0 0 0 1px rgba(122, 90, 18, 0.2);
		}

		.profile-required-icon svg {
			width: 18px;
			height: 18px;
			fill: currentColor;
		}

		.tracker-alert.profile-required .tracker-alert-title {
			font-size: 1rem;
			color: #5f4710;
		}

		.tracker-alert.profile-required .tracker-alert-sub {
			font-size: 0.85rem;
			color: #6b5422;
		}

		.profile-required-cta {
			border-radius: 10px;
			padding: 7px 14px;
			font-weight: 700;
			white-space: nowrap;
			box-shadow: 0 8px 18px rgba(47, 109, 56, 0.22);
		}

		.profile-required-cta:hover {
			transform: translateY(-1px);
		}

		.tracker-alert-title {
			margin: 0;
			font-size: 0.95rem;
			font-weight: 700;
			color: #2f4a32;
		}

		.tracker-alert-sub {
			margin: 2px 0 0;
			font-size: 0.84rem;
			color: #4b5e4e;
		}

		.stage-summary-card {
			padding: 14px 12px;
			border-radius: 12px;
			border: 1px solid rgba(127, 182, 133, 0.24);
			background: linear-gradient(180deg, #ffffff, #f7fbf4);
			box-shadow: 0 6px 14px rgba(37, 56, 40, 0.08);
			display: flex;
			flex-direction: column;
			align-items: center;
			justify-content: center;
			text-align: center;
			gap: 6px;
			min-height: 146px;
		}

		.stage-summary-card {
			cursor: default;
		}

		.stage-summary-card.is-green,
		.stage-summary-card.is-gold {
			border-top: 4px solid #1f8b3f;
			background: linear-gradient(180deg, rgba(31, 139, 63, 0.14), rgba(255, 255, 255, 0.98));
		}

		.stage-summary-row {
			display: grid;
			grid-template-columns: repeat(3, minmax(0, 1fr));
			gap: 10px;
			margin-bottom: 14px;
		}

		.stage-summary-content {
			justify-content: center;
			gap: 6px;
			padding: 0;
			display: flex;
			flex-direction: column;
			align-items: center;
		}

		.stage-summary-icon {
			width: 38px;
			height: 38px;
			border-radius: 11px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			margin-bottom: 2px;
			background: rgba(31, 139, 63, 0.2);
			color: #1f8b3f;
		}

		.stage-summary-icon svg {
			width: 18px;
			height: 18px;
			fill: currentColor;
		}

		.stage-summary-label {
			margin: 0;
			font-size: 0.78rem;
			font-weight: 700;
			letter-spacing: 0.06em;
			text-transform: uppercase;
			color: #5f6f63;
			text-align: center;
		}

		.stage-summary-value {
			margin: 0;
			font-size: 1.65rem;
			font-weight: 700;
			line-height: 1;
			color: #1f8b3f;
			text-align: center;
		}

		.stage-summary-hint {
			margin: 0;
			font-size: 0.76rem;
			color: #5f6e62;
			text-align: center;
		}

		.panel,
		.stage-card {
			border: none;
			border-radius: var(--radius);
			background: var(--card);
			box-shadow: var(--shadow);
			overflow: hidden;
		}

		.panel {
			position: relative;
			padding: 22px;
			border: 1px solid var(--border);
			background: linear-gradient(120deg, rgba(255, 255, 255, 0.95), rgba(232, 243, 234, 0.72));
		}

		.panel .blur-orb {
			position: absolute;
			top: -18px;
			right: -18px;
			width: 118px;
			height: 118px;
			border-radius: 50%;
			filter: blur(26px);
			opacity: 0.65;
			pointer-events: none;
			background: rgba(127, 182, 133, 0.28);
		}

		.grid-wrap {
			display: grid;
			grid-template-columns: repeat(6, minmax(0, 1fr));
			gap: 12px;
			margin-top: 14px;
		}

		.stage-card {
			position: relative;
			transition: transform 0.2s ease, box-shadow 0.2s ease;
			min-height: 204px;
			cursor: pointer;
			text-align: left;
			padding: 0;
			width: 100%;
			border: 0;
			outline: none;
		}

		.stage-card:hover {
			transform: translateY(-2px);
			box-shadow: var(--shadow-lg);
		}

		.stage-card:focus-visible {
			box-shadow: 0 0 0 3px rgba(127, 182, 133, 0.3), var(--shadow-lg);
		}

		.stage-card .blur-orb {
			position: absolute;
			top: -18px;
			right: -18px;
			width: 118px;
			height: 118px;
			border-radius: 50%;
			filter: blur(26px);
			opacity: 0.65;
			pointer-events: none;
		}

		.stage-body {
			position: relative;
			z-index: 2;
			padding: 14px;
			height: 100%;
			display: flex;
			flex-direction: column;
			gap: 8px;
		}

		.card-green {
			background: linear-gradient(145deg, rgba(127, 182, 133, 0.1), rgba(127, 182, 133, 0.03));
		}

		.card-gold {
			background: linear-gradient(145deg, rgba(255, 229, 153, 0.28), rgba(255, 229, 153, 0.08));
		}

		/* Themed panel variants to align detail panels with lifecycle cards */
		.panel.card-green {
			background: linear-gradient(145deg, rgba(127, 182, 133, 0.08), rgba(127, 182, 133, 0.02));
			border: 1px solid rgba(127, 182, 133, 0.12);
			color: var(--foreground);
			box-shadow: 0 6px 18px rgba(37,56,40,0.05);
		}

		.panel.card-gold {
			background: linear-gradient(145deg, rgba(255, 229, 153, 0.2), rgba(255, 229, 153, 0.04));
			border: 1px solid rgba(217, 178, 74, 0.12);
			color: var(--foreground);
			box-shadow: 0 6px 18px rgba(60,44,10,0.05);
		}

		.stage-head {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 10px;
			margin-bottom: 2px;
		}

		.stage-head-right {
			display: inline-flex;
			align-items: center;
			gap: 8px;
		}

		.stage-pill {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 5px 10px;
			border-radius: 999px;
			font-size: 0.76rem;
			font-weight: 700;
			letter-spacing: 0.02em;
			text-transform: uppercase;
			background: rgba(127, 182, 133, 0.2);
			color: #2f5a36;
		}

		.day-pill {
			padding: 4px 9px;
			border-radius: 999px;
			font-size: 0.72rem;
			font-weight: 700;
			background: rgba(255, 204, 64, 0.34);
			color: #705314;
		}

		.stage-status {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 4px 8px;
			border-radius: 999px;
			font-size: 0.68rem;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.03em;
			white-space: nowrap;
		}

		.stage-status-done {
			background: rgba(127, 182, 133, 0.24);
			color: #2f6d38;
			width: 24px;
			height: 24px;
			padding: 0;
		}

		.stage-status-icon {
			width: 13px;
			height: 13px;
			fill: none;
			stroke: currentColor;
			stroke-width: 2.6;
			stroke-linecap: round;
			stroke-linejoin: round;
		}

		.stage-status-locked {
			background: rgba(148, 163, 184, 0.25);
			color: #4b5563;
		}

		.stage-card.is-locked {
			opacity: 0.88;
			cursor: not-allowed;
		}

		.stage-card.is-locked:hover {
			transform: none;
			box-shadow: var(--shadow);
		}

		.stage-card.is-locked .feature-bar {
			width: 0 !important;
		}

		.stage-card.is-locked .stage-name {
			color: #5f6e62;
		}

		.stage-name {
			font-size: 0.94rem;
			font-weight: 700;
			line-height: 1.3;
			margin-bottom: 0;
			text-align: center;
			transition: color 0.2s ease;
			display: -webkit-box;
			-webkit-line-clamp: 2;
			-webkit-box-orient: vertical;
			overflow: hidden;
		}

		.stage-desc {
			color: var(--muted-foreground);
			font-size: 0.9rem;
			line-height: 1.5;
			margin-bottom: auto;
			display: none;
		}

		.stage-visual {
			border: 1px solid rgba(127, 182, 133, 0.28);
			border-radius: 10px;
			background: rgba(255, 255, 255, 0.62);
			min-height: 74px;
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 10px;
			padding: 10px;
			margin-bottom: 0;
		}

		.stage-visual-name {
			display: none;
		}

		.mini-growth-visual {
			width: 74px;
			height: 56px;
			position: relative;
			display: flex;
			align-items: end;
			justify-content: center;
			flex: 0 0 74px;
		}

		.mini-seed {
			position: absolute;
			bottom: 8px;
			width: 16px;
			height: 10px;
			border-radius: 8px;
			background: #8b6338;
			display: none;
		}

		.mini-stem {
			width: 6px;
			height: 30px;
			border-radius: 8px;
			background: linear-gradient(180deg, #6bb174, #4c9657);
			position: absolute;
			bottom: 8px;
			left: 50%;
			transform: translateX(-50%);
			display: none;
		}

		.mini-base {
			width: 22px;
			height: 5px;
			border-radius: 5px;
			background: #7f5c36;
			position: absolute;
			bottom: 4px;
			left: 50%;
			transform: translateX(-50%);
		}

		.mini-leaf {
			position: absolute;
			border-radius: 50px 50px 8px 8px;
			background: #62a56d;
			display: none;
		}

		.mini-leaf.l1 {
			width: 12px;
			height: 24px;
			left: 18px;
			bottom: 20px;
			transform: rotate(-22deg);
		}

		.mini-leaf.l2 {
			width: 12px;
			height: 26px;
			right: 18px;
			bottom: 22px;
			transform: rotate(22deg);
		}

		.mini-leaf.l3 {
			width: 12px;
			height: 24px;
			left: 31px;
			bottom: 28px;
			transform: rotate(-4deg);
		}

		.mini-cob {
			position: absolute;
			width: 11px;
			height: 21px;
			border-radius: 8px;
			background: linear-gradient(180deg, #f4cb63, #e8ae2d);
			box-shadow: 0 3px 8px rgba(167, 120, 27, 0.28);
			left: 50%;
			transform: translateX(-50%);
			bottom: 24px;
			display: none;
		}

		.mini-tassel {
			position: absolute;
			width: 12px;
			height: 8px;
			border-radius: 4px;
			background: #efd074;
			left: 50%;
			transform: translateX(-50%);
			bottom: 38px;
			display: none;
		}

		.mini-harvest {
			display: none;
			position: absolute;
			bottom: 8px;
			left: 50%;
			transform: translateX(-50%);
			gap: 4px;
		}

		.mini-harvest span {
			width: 12px;
			height: 22px;
			border-radius: 6px;
			background: linear-gradient(180deg, #f8d584, #e8ae2d);
			box-shadow: 0 4px 8px rgba(162, 112, 24, 0.25);
			display: inline-block;
		}

		.stage-visual.visual-seed .mini-seed {
			display: block;
		}

		.stage-visual.visual-sprout .mini-stem {
			display: block;
			height: 16px;
		}

		.stage-visual.visual-sprout .mini-leaf.l1,
		.stage-visual.visual-sprout .mini-leaf.l2 {
			display: block;
			width: 9px;
			height: 15px;
			bottom: 20px;
		}

		.stage-visual.visual-seedling .mini-stem,
		.stage-visual.visual-vegetative .mini-stem,
		.stage-visual.visual-tasseling .mini-stem,
		.stage-visual.visual-silking .mini-stem,
		.stage-visual.visual-mature .mini-stem {
			display: block;
		}

		.stage-visual.visual-seedling .mini-leaf.l1,
		.stage-visual.visual-seedling .mini-leaf.l2 {
			display: block;
		}

		.stage-visual.visual-vegetative .mini-leaf,
		.stage-visual.visual-tasseling .mini-leaf,
		.stage-visual.visual-silking .mini-leaf,
		.stage-visual.visual-mature .mini-leaf {
			display: block;
		}

		.stage-visual.visual-tasseling .mini-tassel,
		.stage-visual.visual-silking .mini-tassel,
		.stage-visual.visual-mature .mini-tassel {
			display: block;
		}

		.stage-visual.visual-silking .mini-cob,
		.stage-visual.visual-mature .mini-cob {
			display: block;
		}

		.stage-visual.visual-mature .mini-stem {
			background: linear-gradient(180deg, #6f8f74, #4f7757);
		}

		.stage-visual.visual-harvest .mini-harvest {
			display: flex;
		}

		.feature-bar {
			width: 0;
			height: 4px;
			border-radius: 999px;
			transition: width 0.4s ease;
			margin-top: auto;
		}

		.stage-card:hover .feature-bar {
			width: 100%;
		}

		.stage-card:hover .stage-name {
			color: #5f9a65;
		}

		.corn-marker {
			font-size: 2rem;
			line-height: 1;
			color: #7d611b;
		}

		#detailView .panel:not(.card-green):not(.card-gold) {
			background: #ffffff;
		}

		.detail-title {
			margin: 0;
			font-size: 1.45rem;
			font-weight: 700;
		}

		.detail-sub {
			margin: 2px 0 0;
			font-size: 0.9rem;
			color: var(--muted-foreground);
		}

		.detail-label {
			font-size: 0.82rem;
			font-weight: 700;
			color: #4b5e4e;
			text-transform: uppercase;
			letter-spacing: 0.02em;
			margin-bottom: 4px;
		}

		.detail-value {
			font-size: 1rem;
			font-weight: 600;
			color: #263b2a;
			margin-bottom: 12px;
		}

		.expected-visual-box {
			width: 100%;
			min-height: 260px;
			border-radius: 12px;
			border: 1px solid rgba(127, 182, 133, 0.38);
			background: linear-gradient(145deg, rgba(127, 182, 133, 0.12), rgba(255, 229, 153, 0.18));
			display: flex;
			flex-direction: column;
			align-items: center;
			justify-content: center;
			padding: 14px;
			margin-bottom: 8px;
		}

		.expected-growth-visual {
			width: 100%;
			height: 220px;
			min-height: 220px;
			display:flex;
			align-items:center;
			justify-content:center;
			background: rgba(255, 255, 255, 0.52);
		}

		.expected-stage-image {
			width: 100%;
			height: 100%;
			object-fit: contain;
			border-radius: 10px;
			background: rgba(255, 255, 255, 0.72);
			padding: 8px;
		}

		.expected-image-fallback {
			width: 100%;
			display: flex;
			align-items: center;
			justify-content: center;
		}

		.expected-growth-visual .mini-growth-visual {
			width: 120px;
			height: 90px;
			flex: 0 0 120px;
			transform: scale(1.2);
			transform-origin: center bottom;
		}

		.photo-preview {
			width: 100%;
			min-height: 260px;
			border: 1px dashed rgba(127, 182, 133, 0.45);
			border-radius: 12px;
			display: flex;
			align-items: center;
			justify-content: center;
			background: rgba(232, 243, 234, 0.55);
			overflow: hidden;
			padding: 14px;
		}

		/* Stage info and journal blocks inside detail view */
		.stage-info {
			padding: 14px;
			border-radius: 10px;
			background: rgba(255, 255, 255, 0.66);
			border: 1px solid rgba(127,182,133,0.12);
		}

		.stage-journal {
			padding: 14px;
			border-radius: 10px;
			background: rgba(255, 255, 255, 0.66);
			border: 1px solid rgba(127,182,133,0.08);
		}

		/* Keep all 4 detail cards aligned to selected stage theme */
		#detailView .panel.card-green.expected-block,
		#detailView .panel.card-green.stage-info,
		#detailView .panel.card-green.upload-block,
		#detailView .panel.card-green.stage-journal {
			background: linear-gradient(145deg, rgba(127, 182, 133, 0.14), rgba(127, 182, 133, 0.06));
			border-color: rgba(127, 182, 133, 0.28);
		}

		#detailView .panel.card-gold.expected-block,
		#detailView .panel.card-gold.stage-info,
		#detailView .panel.card-gold.upload-block,
		#detailView .panel.card-gold.stage-journal {
			background: linear-gradient(145deg, rgba(255, 229, 153, 0.24), rgba(255, 229, 153, 0.1));
			border-color: rgba(217, 178, 74, 0.28);
		}

		#detailView .panel.card-green .expected-visual-box,
		#detailView .panel.card-green .photo-preview {
			background: rgba(127, 182, 133, 0.12);
			border-color: rgba(127, 182, 133, 0.4);
		}

		#detailView .panel.card-gold .expected-visual-box,
		#detailView .panel.card-gold .photo-preview {
			background: rgba(255, 229, 153, 0.2);
			border-color: rgba(217, 178, 74, 0.42);
		}

		#detailView .panel.card-green .photo-empty-state,
		#detailView .panel.card-gold .photo-empty-state {
			background: rgba(255, 255, 255, 0.84);
		}

		.photo-preview img {
			width: 100%;
			height: 100%;
			max-height: 100%;
			object-fit: contain;
			display: none;
			cursor: zoom-in;
			border-radius: 10px;
			background: rgba(255, 255, 255, 0.72);
			padding: 6px;
		}

		.photo-lightbox {
			position: fixed;
			inset: 0;
			z-index: 1300;
			display: none;
			align-items: center;
			justify-content: center;
			background: rgba(17, 24, 19, 0.82);
			backdrop-filter: blur(3px);
			padding: 18px;
		}

		.photo-lightbox.active {
			display: flex;
		}

		.photo-lightbox-img {
			max-width: min(96vw, 980px);
			max-height: 86vh;
			object-fit: contain;
			border-radius: 12px;
			box-shadow: 0 18px 42px rgba(0, 0, 0, 0.42);
			background: #fff;
			padding: 8px;
		}

		.photo-lightbox-close {
			position: absolute;
			top: 16px;
			right: 16px;
			width: 40px;
			height: 40px;
			border: 0;
			border-radius: 999px;
			background: rgba(255, 255, 255, 0.18);
			color: #fff;
			font-size: 1.5rem;
			line-height: 1;
			cursor: pointer;
		}

		.photo-lightbox-close:hover {
			background: rgba(255, 255, 255, 0.28);
		}

		.photo-placeholder {
			width: 100%;
			height: 100%;
			display: flex;
			align-items: center;
			justify-content: center;
		}

		.photo-empty-state {
			width: 100%;
			max-width: 320px;
			text-align: center;
			padding: 16px;
			border-radius: 12px;
			background: rgba(255, 255, 255, 0.75);
			border: 1px solid rgba(127, 182, 133, 0.2);
		}

		.photo-empty-icon {
			width: 34px;
			height: 34px;
			margin: 0 auto 8px;
			color: #5a8f61;
			opacity: 0.95;
		}

		.photo-empty-icon svg {
			width: 100%;
			height: 100%;
			stroke: currentColor;
			fill: none;
			stroke-width: 1.8;
			stroke-linecap: round;
			stroke-linejoin: round;
		}

		.photo-empty-text {
			color: #4d6150;
			font-size: 0.92rem;
			font-weight: 600;
			margin-bottom: 12px;
		}

		.photo-empty-actions {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			gap: 8px;
			flex-wrap: wrap;
		}

		.journal-textarea {
			width: 100%;
			min-height: 130px;
			border-radius: 10px;
			border: 1px solid rgba(127, 182, 133, 0.35);
			padding: 12px;
			resize: vertical;
			font-size: 0.95rem;
			color: #2c3e2e;
			background: #ffffff;
		}

		.journal-textarea:focus {
			outline: none;
			border-color: #7fb685;
			box-shadow: 0 0 0 3px rgba(127, 182, 133, 0.18);
		}

		.journal-actions {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 10px;
			margin-top: 10px;
		}

		.save-note {
			font-size: 0.82rem;
			font-weight: 600;
			color: #4f7c55;
			min-height: 1.2em;
		}

		.tracker-toast {
			position: fixed;
			right: 18px;
			bottom: 18px;
			z-index: 2200;
			max-width: min(320px, calc(100vw - 24px));
			padding: 10px 14px;
			border-radius: 12px;
			font-size: 0.83rem;
			font-weight: 700;
			letter-spacing: 0.01em;
			line-height: 1.35;
			color: #ffffff;
			box-shadow: 0 12px 24px rgba(19, 38, 24, 0.26);
			opacity: 0;
			transform: translateY(12px) scale(0.98);
			transition: opacity 0.24s ease, transform 0.24s ease;
			pointer-events: none;
		}

		.tracker-toast.show {
			opacity: 1;
			transform: translateY(0) scale(1);
		}

		.tracker-toast.is-success {
			background: linear-gradient(135deg, #2f8f4f, #246f3b);
		}

		.tracker-toast.is-warning {
			background: linear-gradient(135deg, #a67f1e, #7f5f10);
		}

		.tracker-toast.is-info {
			background: linear-gradient(135deg, #4f6b53, #3e5543);
		}

		/* Photo upload loading state */
		.photo-loading-overlay {
			position: absolute;
			inset: 0;
			background: rgba(255, 255, 255, 0.7);
			display: none;
			flex-direction: column;
			align-items: center;
			justify-content: center;
			z-index: 10;
			border-radius: 12px;
			backdrop-filter: blur(2px);
		}
		.spinner-border {
			color: #7fb685;
			width: 2.2rem;
			height: 2.2rem;
		}

		/* Persistent Action Bar */
		.photo-actions-bar {
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 12px;
			padding: 10px;
			margin-top: 12px;
			background: rgba(255, 255, 255, 0.4);
			border-radius: 12px;
			border: 1px solid rgba(127, 182, 133, 0.1);
		}

		/* Detail view: equalize left/right blocks and make top/bottom blocks match */
		#detailView .row.g-3 {
			align-items: stretch;
		}
		#detailView .col-12.col-lg-6 {
			display: flex;
			flex-direction: column;
		}
		#detailView .col-12.col-lg-6 > .d-flex.h-100,
		#detailView .col-12.col-lg-6 > .d-flex {
			flex: 1 1 auto;
			display: flex;
			flex-direction: column;
			gap: 12px;
		}
		#detailView .expected-block,
		#detailView .upload-block {
			display: flex;
			flex-direction: column;
			gap: 12px;
			flex: 1 1 auto;
			min-height: 430px;
		}
		#detailView .expected-visual-box,
		#detailView .photo-preview {
			flex: 1 1 auto;
			display: flex;
			align-items: center;
			justify-content: center;
			min-height: 260px;
			height: 260px;
		}
		#detailView .expected-block .text-center {
			min-height: 52px;
			display: flex;
			align-items: center;
			justify-content: center;
		}
		#detailView .photo-actions-bar {
			min-height: 52px;
			margin-top: 8px;
		}
		#detailView .stage-info,
		#detailView .stage-journal {
			flex: 1 1 auto;
			display: flex;
			flex-direction: column;
			justify-content: flex-start;
		}
		#detailView .stage-info .detail-label,
		#detailView .stage-journal .detail-label {
			font-size: 0.78rem;
			color: #4b5e4e;
			text-transform: none;
			margin-bottom: 6px;
		}
		#detailView .stage-info .detail-value,
		#detailView .stage-journal .detail-value {
			font-size: 0.98rem;
			font-weight: 600;
			color: #263b2a;
		}
		@media (max-width: 991.98px) {
			#detailView .col-12.col-lg-6 > .d-flex.h-100,
			#detailView .col-12.col-lg-6 > .d-flex {
				flex: initial;
			}
			#detailView .expected-visual-box,
			#detailView .photo-preview {
				min-height: 180px;
				height: 220px;
			}
			#detailView .expected-block,
			#detailView .upload-block {
				min-height: auto;
			}
		}

		@media (max-width: 576px) {
			.page-title {
				font-size: 1.35rem;
			}

			.panel {
				padding: 16px;
			}

			.stage-card {
				min-height: 188px;
			}

			.stage-summary-card {
				min-height: 122px;
			}

			.stage-summary-value {
				font-size: 1.2rem;
			}

			.stage-summary-hint {
				font-size: 0.7rem;
			}

			.stage-summary-icon {
				width: 34px;
				height: 34px;
			}

			.stage-summary-icon svg {
				width: 16px;
				height: 16px;
			}
		}

		@media (max-width: 1399.98px) {
			.grid-wrap {
				grid-template-columns: repeat(5, minmax(0, 1fr));
			}
		}

		@media (max-width: 1199.98px) {
			.grid-wrap {
				grid-template-columns: repeat(4, minmax(0, 1fr));
			}
		}

		@media (max-width: 991.98px) {
			.stage-summary-row {
				grid-template-columns: repeat(2, minmax(0, 1fr));
			}

			.grid-wrap {
				grid-template-columns: repeat(3, minmax(0, 1fr));
			}
		}

		@media (max-width: 767.98px) {
			.stage-summary-row {
				grid-template-columns: repeat(1, minmax(0, 1fr));
			}

			.grid-wrap {
				grid-template-columns: repeat(2, minmax(0, 1fr));
			}

			.tracker-alert.profile-required {
				align-items: flex-start;
				flex-direction: column;
			}

			.profile-required-cta {
				width: 100%;
				text-align: center;
			}
		}

		@media (max-width: 479.98px) {
			.grid-wrap {
				grid-template-columns: repeat(1, minmax(0, 1fr));
			}

			.tracker-toast {
				right: 12px;
				left: 12px;
				bottom: 12px;
				max-width: none;
			}
		}

		/* Camera Modal Styles */
		.cam-modal {
			position: fixed;
			inset: 0;
			z-index: 2000;
			background: rgba(18, 28, 20, 0.94);
			backdrop-filter: blur(12px);
			display: none;
			padding: 20px;
			align-items: center;
			justify-content: center;
		}
		.cam-modal.active {
			display: flex;
			animation: camIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
		}
		.cam-content {
			width: 100%;
			max-width: 500px;
			background: #ffffff;
			border-radius: 20px;
			overflow: hidden;
			box-shadow: 0 30px 60px rgba(0,0,0,0.5);
			position: relative;
			display: flex;
			flex-direction: column;
		}
		.cam-view-wrap {
			position: relative;
			width: 100%;
			background: #000;
			aspect-ratio: 4/3;
			overflow: hidden;
		}
		#camVideo {
			width: 100%;
			height: 100%;
			object-fit: cover;
		}
		#camCanvas {
			display: none;
		}
		#camPreview {
			width: 100%;
			height: 100%;
			object-fit: cover;
			display: none;
		}
		.cam-controls {
			padding: 20px;
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 12px;
			background: #fdfdfd;
		}
		.snap-btn {
			width: 64px;
			height: 64px;
			border-radius: 50%;
			border: 4px solid #fff;
			background: linear-gradient(135deg, #7fb685, #22a34f);
			box-shadow: 0 8px 20px rgba(34, 163, 79, 0.4);
			cursor: pointer;
			transition: all 0.2s ease;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 0;
		}
		.snap-btn:hover {
			transform: scale(1.05);
		}
		.snap-btn:active {
			transform: scale(0.95);
		}
		.close-cam {
			position: absolute;
			top: 12px;
			right: 12px;
			width: 36px;
			height: 36px;
			border-radius: 50%;
			background: rgba(0,0,0,0.4);
			border: none;
			color: #fff;
			display: flex;
			align-items: center;
			justify-content: center;
			cursor: pointer;
			z-index: 10;
			backdrop-filter: blur(4px);
		}
		@keyframes camIn {
			from { opacity: 0; transform: scale(0.98); }
			to { opacity: 1; transform: scale(1); }
		}

		/* Success Modal Styles */
		.modal-content.premium-modal {
			border: none;
			border-radius: 20px !important;
			background: rgba(255, 255, 255, 0.9) !important;
			backdrop-filter: blur(12px);
			box-shadow: 0 25px 55px rgba(0,0,0,0.15) !important;
		}
		.modal-header.no-border {
			border: none;
			padding-bottom: 0;
		}
		.modal-footer.no-border {
			border: none;
			padding-top: 0;
		}
		.success-icon-wrap {
			width: 64px;
			height: 64px;
			background: #e8f3ea;
			color: #7fb685;
			border-radius: 50%;
			display: flex;
			align-items: center;
			justify-content: center;
			margin: 0 auto 16px;
			font-size: 28px;
			box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
		}
		.modal-body h4 {
			color: #2c3e2e;
			font-weight: 800;
		}
	</style>
</head>
<body>
	<header class="page-head">
		<div class="head-inner">
			<div class="head-row">
				<button class="back-ghost" id="backBtn" aria-label="Back">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 5-7 7 7 7 1.5-1.5-5.5-5.5 5.5-5.5z"></path></svg>
				</button>
				<div>
					<h1 class="page-title">Lifecycle Stage Tracker</h1>
					<p class="page-sub">Monitor each growth stage from seed to harvest</p>
				</div>
					<div id="headerStageTitle" class="header-stage-title d-none" aria-hidden="true"></div>
					<div id="headerStagePill" class="header-stage-pill d-none" aria-hidden="true">Stage 1 of 19</div>
			</div>
		</div>
	</header>

	<main class="page-inner">
		<div class="stage-summary-row" id="stageSummaryRow">
			<article class="stage-summary-card is-green" aria-hidden="true">
				<div class="stage-summary-content">
					<span class="stage-summary-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24"><path d="M6 3h12a2 2 0 0 1 2 2v14l-4-2-4 2-4-2-4 2V5a2 2 0 0 1 2-2zm1 4v2h10V7H7zm0 4v2h7v-2H7z"></path></svg>
					</span>
					<p class="stage-summary-label">No Notes/Image Yet</p>
					<p class="stage-summary-value" id="summaryMissingCount">0</p>
					<p class="stage-summary-hint">Stages still missing entries</p>
				</div>
			</article>
			<article class="stage-summary-card is-green" aria-hidden="true">
				<div class="stage-summary-content">
					<span class="stage-summary-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24"><path d="M12 2a5 5 0 0 1 5 5v2h1a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3v-7a3 3 0 0 1 3-3h1V7a5 5 0 0 1 5-5zm3 7V7a3 3 0 1 0-6 0v2h6z"></path></svg>
					</span>
					<p class="stage-summary-label">Locked Stages</p>
					<p class="stage-summary-value" id="summaryLockedCount">0</p>
					<p class="stage-summary-hint">Stages not yet accessible</p>
				</div>
			</article>
			<article class="stage-summary-card is-green" aria-hidden="true">
				<div class="stage-summary-content">
					<span class="stage-summary-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24"><path d="m4 16 4-4 3 3 6-7 3 3v-4h-4l2 2-5 6-3-3-6 6z"></path></svg>
					</span>
					<p class="stage-summary-label">Completion Rate</p>
					<p class="stage-summary-value" id="summaryCompletionRate">0%</p>
					<p class="stage-summary-hint">Stages with notes or image</p>
				</div>
			</article>
		</div>

		<section class="panel mb-4" id="gridView">
			<div class="blur-orb"></div>

			<?php if (!$hasPlantingProfile) { ?>
				<div class="tracker-alert profile-required" id="profileRequiredNotice">
					<div class="profile-required-body">
						<span class="profile-required-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M12 2 1 21h22L12 2zm1 15h-2v2h2zm0-8h-2v6h2z"></path></svg>
						</span>
						<div>
						<p class="tracker-alert-title">Corn Planting Profile Required</p>
						<p class="tracker-alert-sub">All lifecycle stages are locked. Complete your Corn Planting Profile first to unlock stage tracking.</p>
						</div>
					</div>
					<a href="corn_planting_profile.php" class="btn btn-sm btn-success profile-required-cta">Create Profile</a>
				</div>
			<?php } ?>

			<div class="grid-wrap">
				<?php foreach ($stages as $index => $stage): ?>
					<?php
						$isGold = ($index % 2) === 1;
						$cardClass = $isGold ? "card-gold" : "card-green";
						$barColor = $isGold ? "#d9b24a" : "#7fb685";
						$blurColor = $isGold ? "rgba(255,229,153,0.3)" : "rgba(127,182,133,0.2)";
						$stageNumber = (int) ($stage["number"] ?? 0);
						$cardState = $stageCardStates[$stageNumber] ?? ["done" => false, "locked" => true];
						$isDone = !empty($cardState["done"]);
						$isLocked = !empty($cardState["locked"]);
						$lockClass = $isLocked ? " is-locked" : "";
					?>

					<button
						type="button"
						class="stage-card <?php echo $cardClass . $lockClass; ?>"
						data-stage-index="<?php echo (int) $index; ?>"
						data-stage-number="<?php echo $stageNumber; ?>"
						data-locked="<?php echo $isLocked ? "1" : "0"; ?>"
						data-done="<?php echo $isDone ? "1" : "0"; ?>"
						aria-disabled="<?php echo $isLocked ? "true" : "false"; ?>"
						title="<?php echo htmlspecialchars($stage["name"]); ?>"
					>
						<div class="blur-orb" style="background: <?php echo $blurColor; ?>;"></div>
						<div class="stage-body">
							<div class="stage-head">
								<span class="stage-pill">Stage <?php echo (int) $stage["number"]; ?></span>
								<div class="stage-head-right">
									<?php if ($isDone) { ?>
										<span class="stage-status stage-status-done" aria-label="Done" title="Done"><svg class="stage-status-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg></span>
									<?php } ?>
									<span class="day-pill"><?php echo htmlspecialchars($stage["day"]); ?></span>
								</div>
							</div>

							<div class="stage-visual visual-<?php echo htmlspecialchars($stage["visualType"]); ?>">
								<div class="mini-growth-visual" aria-hidden="true">
									<div class="mini-seed"></div>
									<div class="mini-stem"></div>
									<div class="mini-leaf l1"></div>
									<div class="mini-leaf l2"></div>
									<div class="mini-leaf l3"></div>
									<div class="mini-tassel"></div>
									<div class="mini-cob"></div>
									<div class="mini-harvest"><span></span><span></span></div>
									<div class="mini-base"></div>
								</div>
							</div>

							<div class="stage-name"><?php echo htmlspecialchars($stage["name"]); ?></div>
							<div class="stage-desc"><?php echo htmlspecialchars($stage["description"]); ?></div>
							<div class="feature-bar" style="background: <?php echo $barColor; ?>;"></div>
						</div>
					</button>
				<?php endforeach; ?>
			</div>
		</section>

		<section id="detailView" class="d-none">
			<div class="panel mb-3">
				<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
					<div>
						<h2 class="detail-title" id="detailStageName">Seed</h2>
					</div>
				</div>

				<div class="row g-3">
					<div class="col-12 col-lg-6">
						<div class="d-flex flex-column h-100 gap-3">
							<div class="panel expected-block">
								<div class="detail-label">Expected Visual</div>
								<div class="expected-visual-box">
									<div class="stage-visual expected-growth-visual visual-seed" id="detailExpectedVisualBox">
										<img id="detailExpectedVisualImage" class="expected-stage-image d-none" alt="Expected stage visual">
										<div class="expected-image-fallback" id="detailExpectedVisualFallback">
											<div class="mini-growth-visual" aria-hidden="true">
												<div class="mini-seed"></div>
												<div class="mini-stem"></div>
												<div class="mini-leaf l1"></div>
												<div class="mini-leaf l2"></div>
												<div class="mini-leaf l3"></div>
												<div class="mini-tassel"></div>
												<div class="mini-cob"></div>
												<div class="mini-harvest"><span></span><span></span></div>
												<div class="mini-base"></div>
											</div>
										</div>
									</div>
								</div>
								<div class="text-center">
									<div class="detail-value" id="detailExpectedVisual"></div>
								</div>
							</div>

							<div class="panel stage-info">
								<div class="detail-label">Stage Information</div>
								<div class="row">
									<div class="col-4 detail-label" style="margin-bottom:6px;">Stage</div>
									<div class="col-8 detail-value" id="detailInfoStage">Seed</div>
									<div class="col-4 detail-label" style="margin-bottom:6px;">Timeline</div>
									<div class="col-8 detail-value" id="detailInfoTimeline">Seed</div>
									<div class="col-12 detail-label mt-2">Description</div>
									<div class="col-12 detail-value mb-0" id="detailInfoDescription">Seed is selected and prepared before water uptake begins</div>
								</div>
							</div>
						</div>
					</div>

					<div class="col-12 col-lg-6">
						<div class="d-flex flex-column h-100 gap-3">
							<div class="panel upload-block">
								<div class="detail-label">Actual Photo of Corn</div>
								<div class="photo-preview mb-2" id="detailPhotoArea" style="position:relative;">
									<div class="photo-loading-overlay" id="photoLoadingOverlay">
										<div class="spinner-border" role="status"></div>
										<div style="margin-top:8px; font-size: 0.85rem; font-weight:600; color:#5a8f61;">Processing...</div>
									</div>
									<img id="detailPhotoPreview" alt="Corn stage photo preview">
									<div class="photo-placeholder" id="detailPhotoPlaceholder">
										<div class="photo-empty-state">
											<div class="photo-empty-icon" aria-hidden="true">
												<svg viewBox="0 0 24 24">
													<path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v9A2.5 2.5 0 0 1 17.5 19h-11A2.5 2.5 0 0 1 4 16.5z"></path>
													<path d="M8.5 11.5h7"></path>
													<path d="M12 8v7"></path>
												</svg>
											</div>
											<div class="photo-empty-text">Photo records of your crop</div>
										</div>
									</div>
								</div>
								<div class="photo-actions-bar">
									<button type="button" class="btn btn-success btn-sm px-3" id="uploadPhotoBtn">Upload Photo</button>
									<button type="button" class="btn btn-outline-success btn-sm px-3" id="takePhotoBtn">Take Photo</button>
								</div>
								<input type="file" id="uploadPhotoInput" accept="image/*" class="d-none">
								<input type="file" id="takePhotoInput" accept="image/*" capture="environment" class="d-none">
							</div>

							<div class="panel stage-journal">
								<div class="detail-label">Journal Entry</div>
								<textarea class="journal-textarea" id="detailJournal" placeholder="Write your observations about this stage (optional)..."></textarea>
								<div class="journal-actions">
									<button type="button" class="btn btn-success btn-sm" id="saveStageBtn">Save</button>
									<span class="save-note" id="saveStageNote" aria-live="polite"></span>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</section>

		<!-- Camera Modal -->
		<div class="cam-modal" id="cameraModal">
			<div class="cam-content">
				<button type="button" class="close-cam" id="closeCamBtn">&times;</button>
				<div class="cam-view-wrap">
					<video id="camVideo" autoplay playsinline muted></video>
					<canvas id="camCanvas"></canvas>
					<img id="camPreview" src="" alt="Captured preview">
				</div>
				<div class="cam-controls">
					<button type="button" class="btn btn-outline-secondary" id="retakeBtn" style="display:none;">Retake</button>
					<button type="button" class="snap-btn" id="snapBtn">
						<div style="width:48px;height:48px;border-radius:50%;border:2px solid rgba(255,255,255,0.4);"></div>
					</button>
					<button type="button" class="btn btn-success" id="usePhotoBtn" style="display:none;">Use Photo</button>
				</div>
			</div>
		</div>

		<div class="photo-lightbox" id="photoLightbox" aria-modal="true" role="dialog" aria-label="Expanded crop photo">
			<button class="photo-lightbox-close" id="photoLightboxClose" type="button" aria-label="Close expanded photo">&times;</button>
			<img class="photo-lightbox-img" id="photoLightboxImage" alt="Expanded crop photo preview">
		</div>

		<div class="tracker-toast is-info" id="trackerToast" role="status" aria-live="polite" aria-atomic="true"></div>
	</main>

	<script src="../bootstrap5/js/bootstrap.bundle.min.js"></script>
	<script>
		(function () {
			var backBtn = document.getElementById("backBtn");
			var headerStagePill = document.getElementById("headerStagePill");
			var headerStageTitle = document.getElementById("headerStageTitle");
			var pageTitleEl = document.querySelector('.page-title');
			var pageSubEl = document.querySelector('.page-sub');
			var stageData = <?php echo json_encode($stages, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
			var hasPlantingProfile = <?php echo $hasPlantingProfile ? 'true' : 'false'; ?>;
			var todayLifecycleStageNumber = <?php echo (int) $todayLifecycleStageNumber; ?>;
			var stageCardStates = <?php echo json_encode($stageCardStates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
			var todayLifecycleStageNames = <?php echo json_encode($todayLifecycleStageNames, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?> || [];
			var gridView = document.getElementById("gridView");
			var detailView = document.getElementById("detailView");
			var cardButtons = document.querySelectorAll(".stage-card[data-stage-index]");
			var lifecycleNotifTitle = document.getElementById("lifecycleNotifTitle");
			var lifecycleNotifSub = document.getElementById("lifecycleNotifSub");
			var trackerToast = document.getElementById("trackerToast");
			var trackerToastTimer = null;
			var summaryMissingCount = document.getElementById("summaryMissingCount");
			var summaryLockedCount = document.getElementById("summaryLockedCount");
			var summaryCompletionRate = document.getElementById("summaryCompletionRate");

			var detailStageName = document.getElementById("detailStageName");
			var detailExpectedVisual = document.getElementById("detailExpectedVisual");
			var detailExpectedVisualBox = document.getElementById("detailExpectedVisualBox");
			var detailExpectedVisualImage = document.getElementById("detailExpectedVisualImage");
			var detailExpectedVisualFallback = document.getElementById("detailExpectedVisualFallback");
			var detailInfoStage = document.getElementById("detailInfoStage");
			var detailInfoTimeline = document.getElementById("detailInfoTimeline");
			var detailInfoDescription = document.getElementById("detailInfoDescription");

			var expectedBlock = document.querySelector('.expected-block');
			var stageInfoBlock = document.querySelector('.stage-info');
			var uploadBlock = document.querySelector('.upload-block');
			var journalBlock = document.querySelector('.stage-journal');

			var detailPhotoPreview = document.getElementById("detailPhotoPreview");
			var detailPhotoPlaceholder = document.getElementById("detailPhotoPlaceholder");
			var photoLoadingOverlay = document.getElementById("photoLoadingOverlay");
			var photoLightbox = document.getElementById("photoLightbox");
			var photoLightboxImage = document.getElementById("photoLightboxImage");
			var photoLightboxClose = document.getElementById("photoLightboxClose");
			var uploadPhotoBtn = document.getElementById("uploadPhotoBtn");
			var takePhotoBtn = document.getElementById("takePhotoBtn");
			var uploadPhotoInput = document.getElementById("uploadPhotoInput");
			var takePhotoInput = document.getElementById("takePhotoInput");
			var detailJournal = document.getElementById("detailJournal");
			var saveStageBtn = document.getElementById("saveStageBtn");
			var saveStageNote = document.getElementById("saveStageNote");

			// Camera elements
			var cameraModal = document.getElementById("cameraModal");
			var camVideo = document.getElementById("camVideo");
			var camCanvas = document.getElementById("camCanvas");
			var camPreview = document.getElementById("camPreview");
			var snapBtn = document.getElementById("snapBtn");
			var retakeBtn = document.getElementById("retakeBtn");
			var usePhotoBtn = document.getElementById("usePhotoBtn");
			var closeCamBtn = document.getElementById("closeCamBtn");
			var camStream = null;

			var activeStageIndex = null;
			var currentImagePath = null;

			function getStageState(stageNumber) {
				return stageCardStates[String(stageNumber)] || { done: false, locked: true };
			}

			function setStageState(stageNumber, done) {
				var key = String(stageNumber);
				var state = stageCardStates[key] || { done: false, locked: true };
				state.done = !!done;
				stageCardStates[key] = state;
			}

			function recomputeStageLocks() {
				var todayUnlockStage = hasPlantingProfile
					? Math.max(1, Math.min(Number(todayLifecycleStageNumber) || 1, stageData.length))
					: 0;

				for (var i = 0; i < stageData.length; i += 1) {
					var number = Number(stageData[i].number);
					var state = getStageState(number);
					state.locked = !hasPlantingProfile || (!state.done && number > todayUnlockStage);
					stageCardStates[String(number)] = state;
				}
			}

			function applyStageCardStates() {
				for (var i = 0; i < cardButtons.length; i += 1) {
					var btn = cardButtons[i];
					var stageNumber = Number(btn.getAttribute("data-stage-number"));
					var state = getStageState(stageNumber);

					btn.setAttribute("data-locked", state.locked ? "1" : "0");
					btn.setAttribute("data-done", state.done ? "1" : "0");
					btn.setAttribute("aria-disabled", state.locked ? "true" : "false");
					btn.classList.toggle("is-locked", !!state.locked);

					var statusNode = btn.querySelector(".stage-status");
					if (state.done) {
						if (!statusNode) {
							statusNode = document.createElement("span");
							statusNode.className = "stage-status";
							var headRight = btn.querySelector(".stage-head-right");
							if (headRight) headRight.insertBefore(statusNode, headRight.firstChild);
						}
						statusNode.className = "stage-status stage-status-done";
						statusNode.setAttribute("aria-label", "Done");
						statusNode.setAttribute("title", "Done");
						statusNode.innerHTML = '<svg class="stage-status-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>';
					} else if (statusNode) {
						statusNode.remove();
					}
				}

				refreshStageSummaryCards();
			}

			function refreshStageSummaryCards() {
				if (!summaryMissingCount || !summaryLockedCount || !summaryCompletionRate) {
					return;
				}

				var total = stageData.length;
				var doneCount = 0;
				var lockedCount = 0;
				var missingUnlockedCount = 0;

				for (var i = 0; i < total; i += 1) {
					var stageNumber = Number(stageData[i].number);
					var state = getStageState(stageNumber);
					if (state.done) {
						doneCount += 1;
					}
					if (state.locked) {
						lockedCount += 1;
					} else if (!state.done) {
						missingUnlockedCount += 1;
					}
				}

				var completionRate = total > 0 ? Math.round((doneCount / total) * 100) : 0;

				summaryMissingCount.textContent = String(missingUnlockedCount);
				summaryLockedCount.textContent = String(lockedCount);
				summaryCompletionRate.textContent = String(completionRate) + "%";
			}

			function refreshLifecycleNotification() {
				if (!lifecycleNotifTitle || !lifecycleNotifSub) {
					return;
				}

				var count = todayLifecycleStageNames.length;
				if (count > 0) {
					lifecycleNotifTitle.textContent = "You updated " + count + " lifecycle stage" + (count > 1 ? "s" : "") + " today";
					lifecycleNotifSub.textContent = todayLifecycleStageNames.slice(0, 6).join(", ");
				} else {
					lifecycleNotifTitle.textContent = "No lifecycle stage updates yet today";
					lifecycleNotifSub.textContent = "Your stage entries today will appear here as notifications.";
				}
			}

			function markStageUpdatedToday(stageName) {
				var cleaned = String(stageName || "").trim();
				if (cleaned === "") {
					return;
				}
				if (todayLifecycleStageNames.indexOf(cleaned) === -1) {
					todayLifecycleStageNames.push(cleaned);
				}
				refreshLifecycleNotification();
			}

			function setSaveNote(message, isError) {
				if (!saveStageNote) return;
				saveStageNote.textContent = message || "";
				saveStageNote.style.color = isError ? "#9a3b3b" : "#4f7c55";
			}

			function showTrackerToast(message, type) {
				if (!trackerToast || !message) {
					return;
				}

				trackerToast.textContent = String(message);
				trackerToast.classList.remove("is-success", "is-warning", "is-info");
				if (type === "warning") {
					trackerToast.classList.add("is-warning");
				} else if (type === "info") {
					trackerToast.classList.add("is-info");
				} else {
					trackerToast.classList.add("is-success");
				}

				trackerToast.classList.remove("show");
				void trackerToast.offsetWidth;
				trackerToast.classList.add("show");

				if (trackerToastTimer) {
					window.clearTimeout(trackerToastTimer);
				}
				trackerToastTimer = window.setTimeout(function () {
					trackerToast.classList.remove("show");
				}, 2400);
			}

			function togglePhotoPreview(hasImage, src) {
				if (hasImage) {
					detailPhotoPreview.src = src;
					detailPhotoPreview.style.display = "block";
					detailPhotoPlaceholder.style.display = "none";
				} else {
					detailPhotoPreview.src = "";
					detailPhotoPreview.style.display = "none";
					detailPhotoPlaceholder.style.display = "flex";
				}
				updatePhotoActionState(hasImage);
			}

			function updatePhotoActionState(hasImage) {
				if (!uploadPhotoBtn || !takePhotoBtn) return;

				if (hasImage) {
					uploadPhotoBtn.textContent = "Retake";
					takePhotoBtn.style.display = "none";
				} else {
					uploadPhotoBtn.textContent = "Upload Photo";
					takePhotoBtn.style.display = "";
				}
			}

			function normalizeImagePath(path) {
				if (!path) return null;
				if (path.indexOf('uploads/lifecycle/') === 0) {
					return '../data/Lifecycle Stage Image/' + path.substring('uploads/lifecycle/'.length);
				}
				if (path.indexOf('Lifecycle Stage Image/') === 0) {
					return '../data/' + path;
				}
				return path;
			}

			function openPhotoLightbox(src) {
				if (!photoLightbox || !photoLightboxImage || !src) {
					return;
				}

				photoLightboxImage.src = src;
				photoLightbox.classList.add("active");
			}

			function closePhotoLightbox() {
				if (!photoLightbox || !photoLightboxImage) {
					return;
				}

				photoLightbox.classList.remove("active");
				photoLightboxImage.removeAttribute("src");
			}

			function resizeImageFileToExpected(file) {
				return new Promise(function (resolve) {
					if (!file || !file.type || file.type.indexOf("image/") !== 0) {
						resolve(file);
						return;
					}

					var reader = new FileReader();
					reader.onload = function (event) {
						var img = new Image();
						img.onload = function () {
							var targetWidth = Math.max(Math.round(detailExpectedVisualBox ? detailExpectedVisualBox.clientWidth : 360), 240);
							var targetHeight = 200;

							var canvas = document.createElement("canvas");
							canvas.width = targetWidth;
							canvas.height = targetHeight;
							var ctx = canvas.getContext("2d");
							if (!ctx) {
								resolve(file);
								return;
							}

							ctx.fillStyle = "#ffffff";
							ctx.fillRect(0, 0, targetWidth, targetHeight);

							var scale = Math.min(targetWidth / img.width, targetHeight / img.height);
							var drawWidth = Math.max(1, Math.round(img.width * scale));
							var drawHeight = Math.max(1, Math.round(img.height * scale));
							var dx = Math.round((targetWidth - drawWidth) / 2);
							var dy = Math.round((targetHeight - drawHeight) / 2);

							ctx.drawImage(img, dx, dy, drawWidth, drawHeight);

							canvas.toBlob(function (blob) {
								if (!blob) {
									resolve(file);
									return;
								}

								resolve(new File([blob], "resized_" + (file.name || "photo.jpg"), { type: "image/jpeg" }));
							}, "image/jpeg", 0.88);
						};

						img.onerror = function () {
							resolve(file);
						};

						img.src = String(event.target && event.target.result ? event.target.result : "");
					};

					reader.onerror = function () {
						resolve(file);
					};

					reader.readAsDataURL(file);
				});
			}

			async function loadSavedData(stageIndex) {
				var stage = stageData[stageIndex];
				if (!stage) return;
				setSaveNote("Loading state...", false);
				try {
					const response = await fetch('lifecycle_stage_tracker.php?action=load&stage_number=' + stage.number);
					const data = await response.json();
					if (data.success) {
						detailJournal.value = data.journal || "";
						currentImagePath = normalizeImagePath(data.image_path);
						togglePhotoPreview(!!currentImagePath, currentImagePath);
						var hasContent = String(data.journal || "").trim() !== "" || !!currentImagePath;
						setStageState(stage.number, hasContent);
						recomputeStageLocks();
						applyStageCardStates();
						setSaveNote("", false);
					}
				} catch (err) {
					setSaveNote("Failed to load saved data", true);
				}
			}

			async function saveCurrentStage(silent) {
				if (activeStageIndex === null) return;
				var stage = stageData[activeStageIndex];
				if (!silent) setSaveNote("Saving...", false);
				try {
					const response = await fetch('lifecycle_stage_tracker.php?action=save', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({
							stage_number: stage.number,
							journal: detailJournal.value,
							image_path: currentImagePath
						})
					});
					const data = await response.json();
					if (data.success && !silent) {
						var isDone = String(detailJournal.value || "").trim() !== "" || !!currentImagePath;
						setStageState(stage.number, isDone);
						recomputeStageLocks();
						applyStageCardStates();
						if (isDone) {
							markStageUpdatedToday(stage.name);
						}
						setSaveNote("Saved", false);
						showTrackerToast("Data Saved! Successfully recorded", "success");
					} else if (data.success) {
						var silentDone = String(detailJournal.value || "").trim() !== "" || !!currentImagePath;
						setStageState(stage.number, silentDone);
						recomputeStageLocks();
						applyStageCardStates();
						if (silentDone) {
							markStageUpdatedToday(stage.name);
						}
					}
				} catch (err) {
					setSaveNote("Save failed", true);
				}
			}

			async function handlePhotoUpload(file) {
				if (!file) return;
				photoLoadingOverlay.style.display = "flex";
				var processedFile = await resizeImageFileToExpected(file);
				var formData = new FormData();
				formData.append('photo', processedFile || file);
				try {
					const response = await fetch('lifecycle_stage_tracker.php?action=upload', {
						method: 'POST',
						body: formData
					});
					const data = await response.json();
					if (data.success) {
						currentImagePath = data.path;
						togglePhotoPreview(true, currentImagePath);
						if (activeStageIndex !== null) {
							var activeStage = stageData[activeStageIndex];
							setStageState(activeStage.number, true);
							recomputeStageLocks();
							applyStageCardStates();
							markStageUpdatedToday(activeStage.name);
						}
						await saveCurrentStage(true);
						setSaveNote("Photo updated and saved", false);
						showTrackerToast("Data Saved! Successfully recorded", "success");
					} else {
						alert(data.message || "Upload failed");
					}
				} catch (err) {
					alert("Upload failed. Please check connection.");
				} finally {
					photoLoadingOverlay.style.display = "none";
					uploadPhotoInput.value = "";
				}
			}

			// CAMERA LOGIC
			async function openCamera() {
				cameraModal.classList.add("active");
				snapBtn.style.display = "flex";
				retakeBtn.style.display = "none";
				usePhotoBtn.style.display = "none";
				camPreview.style.display = "none";
				camVideo.style.display = "block";
				
				try {
					camStream = await navigator.mediaDevices.getUserMedia({
						video: { facingMode: "environment" },
						audio: false
					});
					camVideo.srcObject = camStream;
				} catch (err) {
					console.error("Camera error:", err);
					alert("Could not access camera. Please check permissions.");
					closeCamera();
				}
			}

			function closeCamera() {
				if (camStream) {
					camStream.getTracks().forEach(track => track.stop());
					camStream = null;
				}
				cameraModal.classList.remove("active");
			}

			function capturePhoto() {
				var context = camCanvas.getContext('2d');
				camCanvas.width = camVideo.videoWidth;
				camCanvas.height = camVideo.videoHeight;
				context.drawImage(camVideo, 0, 0, camCanvas.width, camCanvas.height);
				
				var dataUrl = camCanvas.toDataURL('image/jpeg');
				camPreview.src = dataUrl;
				camPreview.style.display = "block";
				camVideo.style.display = "none";
				
				snapBtn.style.display = "none";
				retakeBtn.style.display = "block";
				usePhotoBtn.style.display = "block";
			}

			function useCapturedPhoto() {
				camCanvas.toBlob(function(blob) {
					var file = new File([blob], "captured_corn.jpg", { type: "image/jpeg" });
					handlePhotoUpload(file);
					closeCamera();
				}, 'image/jpeg', 0.85);
			}

			function applyVisualTypeClass(target, visualType) {
				var visualClasses = ["visual-seed", "visual-sprout", "visual-seedling", "visual-vegetative", "visual-tasseling", "visual-silking", "visual-mature", "visual-harvest"];
				visualClasses.forEach(cls => target.classList.remove(cls));
				target.classList.add("visual-" + (visualType || "seed"));
			}

			function getExpectedStageImageFile(stage) {
				if (!stage) {
					return null;
				}

				var byNumber = {
					1: "Seed.png",
					2: "Germination.png",
					3: "Emergence (VE).png",
					4: "V1 - First leaf with visible collar.png",
					5: "V2 - Second leaf.png",
					6: "V3 - Third leaf.png",
					7: "V4 - Fourth leaf.png",
					8: "V5- Fifth leaf.png",
					9: "V6 - Sixth leaf.png",
					10: "V7 - Seventh leaf.png",
					11: "V8 - Eighth leaf.png",
					12: "V9 - Ninth leaf.png",
					13: "R1 - Silking.png",
					14: "R2 - Blister.png",
					15: "R3 - Milk.png",
					16: "R4 - Dough.png",
					17: "R5 - Dent.png",
					18: "R6 - Physiological Maturity.png",
					19: "Harvest.png"
				};

				return byNumber[Number(stage.number)] || null;
			}

			function renderExpectedVisualImage(stage) {
				if (!detailExpectedVisualImage || !detailExpectedVisualFallback) {
					return;
				}

				var imageFile = getExpectedStageImageFile(stage);
				if (!imageFile) {
					detailExpectedVisualImage.classList.add("d-none");
					detailExpectedVisualFallback.classList.remove("d-none");
					detailExpectedVisualImage.removeAttribute("src");
					return;
				}

				detailExpectedVisualImage.onload = function () {
					detailExpectedVisualImage.classList.remove("d-none");
					detailExpectedVisualFallback.classList.add("d-none");
				};

				detailExpectedVisualImage.onerror = function () {
					detailExpectedVisualImage.classList.add("d-none");
					detailExpectedVisualFallback.classList.remove("d-none");
				};

				detailExpectedVisualImage.src = "../data/Corn Stages/" + encodeURIComponent(imageFile);
				detailExpectedVisualImage.alt = (stage.name || "Stage") + " expected visual";
			}

			function renderStageDetail(stageIndex) {
				var stage = stageData[stageIndex];
				if (!stage) return;
				activeStageIndex = stageIndex;
				currentImagePath = null;
				updatePhotoActionState(false);

				if (headerStageTitle) {
					headerStageTitle.textContent = stage.name;
					headerStageTitle.classList.remove('d-none');
				}
				if (headerStagePill) {
					headerStagePill.textContent = "Stage " + stage.number + " of " + stageData.length;
					headerStagePill.classList.remove('d-none');
				}

				var isGold = (stageIndex % 2) === 1;
				var addCls = isGold ? 'card-gold' : 'card-green';
				[expectedBlock, stageInfoBlock, uploadBlock, journalBlock].forEach(function (el) {
					if (!el) return;
					el.classList.remove('card-green', 'card-gold');
					el.classList.add(addCls);
				});

				if (pageTitleEl) pageTitleEl.classList.add('d-none');
				if (pageSubEl) pageSubEl.classList.add('d-none');
				
				detailStageName.textContent = stage.name;
				detailExpectedVisual.textContent = stage.visual || stage.name;
				applyVisualTypeClass(detailExpectedVisualBox, stage.visualType);
				renderExpectedVisualImage(stage);
				detailInfoStage.textContent = stage.name;
				detailInfoTimeline.textContent = stage.day;
				detailInfoDescription.textContent = stage.description;

				loadSavedData(stageIndex);

				gridView.classList.add("d-none");
				detailView.classList.remove("d-none");
				window.scrollTo({ top: 0, behavior: "smooth" });
			}

			function showGridView() {
				detailView.classList.add("d-none");
				gridView.classList.remove("d-none");
				if (headerStageTitle) headerStageTitle.classList.add('d-none');
				if (headerStagePill) headerStagePill.classList.add('d-none');
				if (pageTitleEl) pageTitleEl.classList.remove('d-none');
				if (pageSubEl) pageSubEl.classList.remove('d-none');
				activeStageIndex = null;
				setSaveNote("", false);
				window.scrollTo({ top: 0, behavior: "smooth" });
			}

			for (var i = 0; i < cardButtons.length; i += 1) {
				cardButtons[i].addEventListener("click", function () {
					if (this.getAttribute("data-locked") === "1") {
						if (!hasPlantingProfile) {
							showTrackerToast("Complete your Corn Planting Profile first to unlock stages.", "warning");
						} else {
							showTrackerToast("You haven't reached this stage yet.", "warning");
						}
						return;
					}
					renderStageDetail(Number(this.getAttribute("data-stage-index")));
				});
			}

			recomputeStageLocks();
			applyStageCardStates();
			refreshLifecycleNotification();

			if (backBtn) {
				backBtn.addEventListener("click", function () {
					if (gridView && !gridView.classList.contains("d-none")) {
						var params = new URLSearchParams(window.location.search);
						window.location.href = params.get("from") === "features" ? "farmer_dashboard.php?view=features" : "farmer_dashboard.php";
					} else {
						showGridView();
					}
				});
			}

			uploadPhotoBtn.addEventListener("click", () => uploadPhotoInput.click());
			takePhotoBtn.addEventListener("click", openCamera);

			if (detailPhotoPreview) {
				detailPhotoPreview.addEventListener("click", function () {
					if (detailPhotoPreview.style.display === "none" || !detailPhotoPreview.src) {
						return;
					}
					openPhotoLightbox(detailPhotoPreview.src);
				});
			}

			if (photoLightboxClose) {
				photoLightboxClose.addEventListener("click", closePhotoLightbox);
			}

			if (photoLightbox) {
				photoLightbox.addEventListener("click", function (event) {
					if (event.target === photoLightbox) {
						closePhotoLightbox();
					}
				});
			}
			
			uploadPhotoInput.addEventListener("change", function () {
				if (this.files && this.files[0]) handlePhotoUpload(this.files[0]);
			});

			if (takePhotoInput) {
				takePhotoInput.addEventListener("change", function () {
					if (this.files && this.files[0]) handlePhotoUpload(this.files[0]);
				});
			}

			snapBtn.addEventListener("click", capturePhoto);
			retakeBtn.addEventListener("click", () => {
				camPreview.style.display = "none";
				camVideo.style.display = "block";
				snapBtn.style.display = "flex";
				retakeBtn.style.display = "none";
				usePhotoBtn.style.display = "none";
			});
			usePhotoBtn.addEventListener("click", useCapturedPhoto);
			closeCamBtn.addEventListener("click", closeCamera);

			if (saveStageBtn) {
				saveStageBtn.addEventListener("click", () => saveCurrentStage(false));
			}

			document.addEventListener("keydown", function (event) {
				if (event.key === "Escape") {
					closePhotoLightbox();
				}
			});
		})();
	</script>
</body>
</html>
