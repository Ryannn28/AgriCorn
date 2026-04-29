<?php
session_start();

if (!isset($_SESSION["users_id"])) {
	header("Location: ../login.php");
	exit;
}

$userId = (int) ($_SESSION["users_id"] ?? 0);

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "complete_harvest_profile") {
	try {
		require_once __DIR__ . "/../data/db_connect.php";

		if ((int) ($_POST["users_id"] ?? 0) !== $userId) {
			http_response_code(403);
			exit;
		}

		$stmt = $conn->prepare(
			"UPDATE corn_profile
			 SET status = 'completed'
			 WHERE users_id = ? AND status = 'active'
			 ORDER BY corn_profile_id DESC
			 LIMIT 1"
		);

		if (!$stmt) {
			http_response_code(500);
			exit;
		}

		$stmt->bind_param("i", $userId);
		$stmt->execute();
		$stmt->close();

		// Also remove the Corn Care Calendar file for this user's profile so calendar entries are cleared
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

		// If the client sent a summary payload, save it to data/Summary before performing cleanup.
		if (!empty($_POST['summary'])) {
			$summaryRaw = (string) $_POST['summary'];
			$summaryDecoded = json_decode($summaryRaw, true);
			$summaryDir = __DIR__ . "/../data/Summary/";
			if (!is_dir($summaryDir)) {
				if (!@mkdir($summaryDir, 0755, true)) {
					http_response_code(500);
					exit;
				}
			}
			$timeStamp = date('Ymd_His');
			$summaryFile = $summaryDir . $profileFileSafe . '_summary_' . $timeStamp . '.json';

			// Build a presentation-friendly header and promote common fields for easier consumption
			$presentation = [
				"header" => [
					"title" => "HARVEST OVERVIEW",
					"heroTitle" => "Final crop snapshot",
					"heroCopy" => "Everything important from this planting cycle, shown in one clean view before you continue to comparison and the next cycle."
				],
				"badge" => $summaryDecoded['step1']['hero']['badge'] ?? null
			];

			$promotedProfile = is_array($summaryDecoded['step1']['profile'] ?? null) ? $summaryDecoded['step1']['profile'] : null;
			$promotedForecast = is_array($summaryDecoded['step2']['prediction'] ?? null) ? $summaryDecoded['step2']['prediction'] : null;

			$toWrite = [
				"meta" => ["users_id" => $userId, "profile" => $profileFileSafe, "created_at" => date('c')],
				"presentation" => $presentation,
				"profile" => $promotedProfile,
				"forecast" => $promotedForecast,
				"summary" => is_array($summaryDecoded) ? $summaryDecoded : $summaryRaw
			];

			$written = @file_put_contents($summaryFile, json_encode($toWrite, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
			if ($written === false) {
				http_response_code(500);
				exit;
			}
		}
		$calendarTaskPath = __DIR__ . "/../data/Corn Care Calendar/" . $profileFileSafe . ".json";
		if (file_exists($calendarTaskPath)) {
			@unlink($calendarTaskPath);
		}

		// Remove saved planting profile and costing JSON files for a fresh start
		$savedPlantingProfilePath = __DIR__ . "/../data/Corn Profile/" . $profileFileSafe . ".json";
		$savedCostingPath = __DIR__ . "/../data/Corn Costing/" . $profileFileSafe . ".json";
		if (file_exists($savedPlantingProfilePath)) {
			@unlink($savedPlantingProfilePath);
		}
		if (file_exists($savedCostingPath)) {
			@unlink($savedCostingPath);
		}

		// Clear costing records in DB (costing table tied to users_id)
		try {
			$delStmt = $conn->prepare("DELETE FROM costing WHERE users_id = ?");
			if ($delStmt) {
				$delStmt->bind_param("i", $userId);
				$delStmt->execute();
				$delStmt->close();
			}
		} catch (Throwable $e) {
			// ignore deletion errors; calendar/profile update already done
		}

		// Remove lifecycle_journal entries and their uploaded images
		try {
			$selStmt = $conn->prepare("SELECT image_path FROM lifecycle_journal WHERE users_id = ?");
			if ($selStmt) {
				$selStmt->bind_param("i", $userId);
				$selStmt->execute();
				$res = $selStmt->get_result();
				while ($row = $res->fetch_assoc()) {
					$img = trim((string) ($row['image_path'] ?? ''));
					if ($img !== '') {
						// normalize path if it contains ../ prefix
						$imgName = basename($img);
						$imgPath = __DIR__ . "/../data/Lifecycle Stage Image/" . $imgName;
						if (is_file($imgPath)) @unlink($imgPath);
					}
				}
				$selStmt->close();
			}
			$delLife = $conn->prepare("DELETE FROM lifecycle_journal WHERE users_id = ?");
			if ($delLife) {
				$delLife->bind_param("i", $userId);
				$delLife->execute();
				$delLife->close();
			}
		} catch (Throwable $e) {
			// ignore lifecycle cleanup errors
		}

		// Remove pest_and_disease_results entries and their stored images
		try {
			$selPest = $conn->prepare("SELECT image FROM pest_and_disease_results WHERE users_id = ?");
			if ($selPest) {
				$selPest->bind_param("i", $userId);
				$selPest->execute();
				$resp = $selPest->get_result();
				while ($prow = $resp->fetch_assoc()) {
					$imgFile = trim((string) ($prow['image'] ?? ''));
					if ($imgFile !== '') {
						$imgPath = __DIR__ . "/../data/Pest and Disease Image/" . $imgFile;
						if (is_file($imgPath)) @unlink($imgPath);
					}
				}
				$selPest->close();
			}
			$delPest = $conn->prepare("DELETE FROM pest_and_disease_results WHERE users_id = ?");
			if ($delPest) {
				$delPest->bind_param("i", $userId);
				$delPest->execute();
				$delPest->close();
			}
		} catch (Throwable $e) {
			// ignore pest cleanup errors
		}

		http_response_code(200);
		exit;
	} catch (Throwable $e) {
		http_response_code(500);
		exit;
	}
}

$displayName = trim((string) ($_SESSION["name"] ?? ""));
$displayUsername = trim((string) ($_SESSION["username"] ?? ""));

if ($displayName === "") {
	$displayName = "Farmer";
}

if ($displayUsername === "") {
	$displayUsername = "farmer";
}

$displayHandle = "@" . ltrim($displayUsername, "@");
$displayRole = trim((string) ($_SESSION["role"] ?? ""));
if ($displayRole === "") {
	$displayRole = "Farmer";
}
$displayRoleLabel = ucwords(str_replace(["_", "-"], " ", strtolower($displayRole)));

$displayInitials = "";
$displayNameParts = preg_split('/\s+/', trim($displayName));
if (is_array($displayNameParts)) {
	foreach ($displayNameParts as $namePart) {
		$namePart = trim((string) $namePart);
		if ($namePart === "") {
			continue;
		}
		$displayInitials .= strtoupper(substr($namePart, 0, 1));
		if (strlen($displayInitials) >= 2) {
			break;
		}
	}
}

if ($displayInitials === "") {
	$displayInitials = strtoupper(substr($displayName, 0, 2));
}

if ($displayInitials === "") {
	$displayInitials = "FR";
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

$summaryHistoryFiles = glob(__DIR__ . "/../data/Summary/" . $profileFileSafe . "_summary_*.json");
if (!is_array($summaryHistoryFiles)) {
	$summaryHistoryFiles = [];
}

usort($summaryHistoryFiles, function ($left, $right) {
	$leftTime = is_file($left) ? (int) @filemtime($left) : 0;
	$rightTime = is_file($right) ? (int) @filemtime($right) : 0;
	return $rightTime <=> $leftTime;
});

$summaryHistoryEntries = [];
foreach ($summaryHistoryFiles as $summaryHistoryFile) {
	if (!is_file($summaryHistoryFile)) {
		continue;
	}
	$summaryHistoryRaw = file_get_contents($summaryHistoryFile);
	$summaryHistoryDecoded = json_decode((string) $summaryHistoryRaw, true);
	$summaryCreatedAt = '';
	if (is_array($summaryHistoryDecoded)) {
		$summaryCreatedAt = trim((string) ($summaryHistoryDecoded['meta']['created_at'] ?? ''));
	}
	if ($summaryCreatedAt === '') {
		$mtime = @filemtime($summaryHistoryFile);
		$summaryCreatedAt = $mtime !== false ? date('c', (int) $mtime) : '';
	}
	$summaryHistoryEntries[] = [
		'file' => basename($summaryHistoryFile),
		'created_at' => $summaryCreatedAt,
			'label' => $summaryCreatedAt !== '' ? date('M d, Y h:i A', strtotime($summaryCreatedAt)) : 'Unknown date',
			'summary' => is_array($summaryHistoryDecoded) ? $summaryHistoryDecoded : []
	];
}

$summaryHistoryCount = count($summaryHistoryFiles);
$summaryHistoryLatestLabel = "No completed cycle yet";
if ($summaryHistoryCount > 0) {
	$latestSummaryFile = null;
	$latestSummaryMTime = 0;
	foreach ($summaryHistoryFiles as $summaryHistoryFile) {
		if (!is_file($summaryHistoryFile)) {
			continue;
		}
		$fileTime = @filemtime($summaryHistoryFile);
		if ($fileTime !== false && $fileTime >= $latestSummaryMTime) {
			$latestSummaryMTime = (int) $fileTime;
			$latestSummaryFile = $summaryHistoryFile;
		}
	}
	if ($latestSummaryMTime > 0) {
		$summaryHistoryLatestLabel = date('M d, Y h:i A', $latestSummaryMTime);
	}
}

$savedPlantingProfile = null;
$savedPlantingProfilePath = __DIR__ . "/../data/Corn Profile/" . $profileFileSafe . ".json";
$savedCostingPath = __DIR__ . "/../data/Corn Costing/" . $profileFileSafe . ".json";
$calendarTaskPath = __DIR__ . "/../data/Corn Care Calendar/" . $profileFileSafe . ".json";
$marketPricesGlobalPath = __DIR__ . "/../data/market_prices.json";
$marketPricesIndividualPath = __DIR__ . "/../data/Market Prices/" . $profileFileSafe . ".json";
$marketPriceHistoryPath = __DIR__ . "/../data/Market Price Data/" . $profileFileSafe . ".json";
$marketPriceHistoryPathLower = __DIR__ . "/../data/Market Price Data/" . strtolower($profileFileSafe) . ".json";
$marketPricesLoadPath = file_exists($marketPricesIndividualPath) ? $marketPricesIndividualPath : $marketPricesGlobalPath;
$marketPricesJson = file_exists($marketPricesLoadPath) ? file_get_contents($marketPricesLoadPath) : '{"market_prices":{"other":{"price_per_kg":20}}}';

function dashboard_load_profile_from_db(int $usersId)
{
	try {
		require __DIR__ . "/../data/db_connect.php";

		$stmt = $conn->prepare(
			"SELECT planting_date, estimated_harvest_date, farm_location, area_value, area_unit, corn_type, corn_variety, number_of_packs, weight_of_packs, planting_density, seeds_per_hole, soil_type, estimated_seeds_range
			 FROM corn_profile
			 WHERE users_id = ? AND status = 'active'
			 ORDER BY corn_profile_id DESC
			 LIMIT 1"
		);

		if (!$stmt) {
			return null;
		}

		$stmt->bind_param("i", $usersId);
		$stmt->execute();
		$row = $stmt->get_result()->fetch_assoc();
		$stmt->close();

		if (!is_array($row)) {
			return null;
		}

		$plantingDate = trim((string) ($row["planting_date"] ?? ""));
		$estimatedHarvestDate = trim((string) ($row["estimated_harvest_date"] ?? ""));

		$harvestDays = 0;
		if ($plantingDate !== "" && $estimatedHarvestDate !== "") {
			$plantingDateObj = DateTime::createFromFormat('Y-m-d', $plantingDate);
			$harvestDateObj = DateTime::createFromFormat('Y-m-d', $estimatedHarvestDate);
			if ($plantingDateObj instanceof DateTime && $harvestDateObj instanceof DateTime) {
				$harvestDays = (int) $plantingDateObj->diff($harvestDateObj)->format('%a');
			}
		}

		$areaUnitDb = strtolower(trim((string) ($row["area_unit"] ?? "hectare")));
		$areaUnitForm = $areaUnitDb === "sqm" ? "square-meters" : "hectares";
		$areaValue = (float) ($row["area_value"] ?? 0);

		return [
			"plantingDate" => $plantingDate,
			"farmLocation" => (string) ($row["farm_location"] ?? ""),
			"typeOfCorn" => (string) ($row["corn_type"] ?? ""),
			"cornVariety" => (string) ($row["corn_variety"] ?? ""),
			"numberOfPacks" => (string) ((int) ($row["number_of_packs"] ?? 0)),
			"kgOfPacks" => (string) ((float) ($row["weight_of_packs"] ?? 0)),
			"areaUnit" => $areaUnitForm,
			"areaPlanted" => (string) $areaValue,
			"areaLength" => "",
			"areaWidth" => "",
			"plantingDensity" => (string) ((float) ($row["planting_density"] ?? 0)),
			"seedsPerHole" => (string) ((int) ($row["seeds_per_hole"] ?? 0)),
			"soilType" => (string) ($row["soil_type"] ?? ""),
			"estimatedSeeds" => (string) ($row["estimated_seeds_range"] ?? ""),
			"daysToHarvestMin" => $harvestDays > 0 ? $harvestDays : null,
			"daysToHarvestMax" => $harvestDays > 0 ? $harvestDays : null,
			"daysToHarvestLabel" => $harvestDays > 0 ? ($harvestDays . " days") : ""
		];
	} catch (Throwable $error) {
		return null;
	}
}

function dashboard_load_calendar_payload($filePath)
{
	if (!is_file($filePath)) {
		return ["updatedAt" => date("c"), "autoScheduleGenerated" => false, "tasks" => []];
	}

	$raw = file_get_contents($filePath);
	if ($raw === false) {
		return ["updatedAt" => date("c"), "autoScheduleGenerated" => false, "tasks" => []];
	}

	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		return ["updatedAt" => date("c"), "autoScheduleGenerated" => false, "tasks" => []];
	}

	$tasks = is_array($decoded["tasks"] ?? null) ? array_values($decoded["tasks"]) : [];
	return [
		"updatedAt" => (string) ($decoded["updatedAt"] ?? date("c")),
		"autoScheduleGenerated" => (bool) ($decoded["autoScheduleGenerated"] ?? false),
		"tasks" => $tasks
	];
}

function dashboard_save_calendar_payload($filePath, $payload)
{
	$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($json === false) {
		return false;
	}

	return file_put_contents($filePath, $json, LOCK_EX) !== false;
}

function dashboard_make_calendar_task($title, $type, $date, $notes)
{
	return [
		"id" => uniqid("task_", true),
		"title" => (string) $title,
		"type" => (string) $type,
		"date" => (string) $date,
		"time" => "",
		"notes" => (string) $notes,
		"priority" => "low",
		"completed" => false
	];
}

function dashboard_task_matches_heat_notice($task, $todayIso)
{
	if (!is_array($task) || (string) ($task["date"] ?? "") !== $todayIso) {
		return false;
	}

	$haystack = strtolower(trim((string) ($task["title"] ?? "") . " " . (string) ($task["notes"] ?? "")));
	return strpos($haystack, "watering reminder") !== false || strpos($haystack, "heat warning") !== false || strpos($haystack, "need to water") !== false;
}

function dashboard_has_today_watering_task($tasks, $todayIso)
{
	if (!is_array($tasks)) {
		return false;
	}

	foreach ($tasks as $task) {
		if (!is_array($task)) {
			continue;
		}
		if ((string) ($task["date"] ?? "") !== $todayIso) {
			continue;
		}
		if (strtolower(trim((string) ($task["type"] ?? ""))) === "watering") {
			return true;
		}
	}

	return false;
}

function dashboard_append_heat_task($calendarTaskPath, $temperature)
{
	$payload = dashboard_load_calendar_payload($calendarTaskPath);
	$tasks = is_array($payload["tasks"] ?? null) ? $payload["tasks"] : [];
	$todayIso = (new DateTime("today"))->format("Y-m-d");
	$temperature = (float) $temperature;
	$temperatureLabel = number_format($temperature, 1);
	$warningText = "Temperature is " . $temperatureLabel . "°C today. Need to water the corn.";

	foreach ($tasks as $task) {
		if (dashboard_task_matches_heat_notice($task, $todayIso)) {
			return [
				"success" => true,
				"message" => "Heat warning has already been recorded for today.",
				"tasks" => $tasks,
				"createdType" => "none"
			];
		}
	}

	$hasWatering = dashboard_has_today_watering_task($tasks, $todayIso);
	if ($hasWatering) {
		$tasks[] = dashboard_make_calendar_task("Watering Reminder", "note", $todayIso, $warningText);
		$createdType = "note";
		$message = "Watering reminder note added for today.";
	} else {
		$tasks[] = dashboard_make_calendar_task("Watering Task", "watering", $todayIso, $warningText);
		$createdType = "watering";
		$message = "Watering task added for today.";
	}

	usort($tasks, function ($a, $b) {
		$aDate = (string) ($a["date"] ?? "");
		$bDate = (string) ($b["date"] ?? "");
		if ($aDate !== $bDate) {
			return strcmp($aDate, $bDate);
		}
		return strcmp((string) ($a["title"] ?? ""), (string) ($b["title"] ?? ""));
	});

	if (!dashboard_save_calendar_payload($calendarTaskPath, [
		"updatedAt" => date("c"),
		"autoScheduleGenerated" => (bool) ($payload["autoScheduleGenerated"] ?? false),
		"tasks" => $tasks
	])) {
		return ["success" => false, "message" => "Unable to update calendar right now."];
	}

	return [
		"success" => true,
		"message" => $message,
		"tasks" => $tasks,
		"createdType" => $createdType
	];
}

function dashboard_get_total_cost_from_db(int $usersId): float
{
	try {
		require __DIR__ . "/../data/db_connect.php";
		$stmt = $conn->prepare("SELECT COALESCE(SUM(cost), 0) AS total_cost FROM costing WHERE users_id = ?");
		if (!$stmt) {
			return 0.0;
		}

		$stmt->bind_param("i", $usersId);
		$stmt->execute();
		$row = $stmt->get_result()->fetch_assoc();
		$stmt->close();

		return (float) ($row["total_cost"] ?? 0);
	} catch (Throwable $error) {
		return 0.0;
	}
}

function dashboard_get_latest_pest_detection_datetime(int $usersId): string
{
	try {
		require __DIR__ . "/../data/db_connect.php";
		$stmt = $conn->prepare("SELECT date_created FROM pest_and_disease_results WHERE users_id = ? ORDER BY date_created DESC LIMIT 1");
		if (!$stmt) {
			return "";
		}

		$stmt->bind_param("i", $usersId);
		$stmt->execute();
		$row = $stmt->get_result()->fetch_assoc();
		$stmt->close();

		return trim((string) ($row["date_created"] ?? ""));
	} catch (Throwable $error) {
		return "";
	}
}

function dashboard_get_market_price_key(array $profile): string
{
	$type = strtolower(trim((string) ($profile['typeOfCorn'] ?? $profile['corn_type'] ?? '')));
	$variety = strtolower(trim((string) ($profile['cornVariety'] ?? $profile['corn_variety'] ?? '')));
	$descriptor = trim($type . ' ' . $variety);
	if (strpos($descriptor, 'sweet') !== false) {
		if (strpos($descriptor, 'hybrid') !== false) return 'sweet_hybrid';
		if (strpos($descriptor, 'native') !== false) return 'sweet_native';
		if (strpos($descriptor, 'opv') !== false) return 'sweet_opv';
		return 'sweet_hybrid';
	}
	if (strpos($descriptor, 'yellow') !== false) {
		if (strpos($descriptor, 'hybrid') !== false) return 'yellow_hybrid';
		if (strpos($descriptor, 'feed') !== false) return 'yellow_feeds';
		if (strpos($descriptor, 'native') !== false) return 'yellow_native';
		return 'yellow_hybrid';
	}
	if (strpos($descriptor, 'white') !== false) {
		if (strpos($descriptor, 'native') !== false) return 'white_native';
		return 'white_field';
	}
	if (strpos($descriptor, 'glutinous') !== false || strpos($descriptor, 'waxy') !== false) return 'glutinous';
	if (strpos($descriptor, 'popcorn') !== false) return 'popcorn';
	if (strpos($descriptor, 'baby') !== false) return 'baby_corn';
	return 'other';
}

function dashboard_get_market_price_per_kg(array $profile, array $marketPrices): float
{
	$priceKey = dashboard_get_market_price_key($profile);
	$entry = is_array($marketPrices[$priceKey] ?? null) ? $marketPrices[$priceKey] : ($marketPrices['other'] ?? ['price_per_kg' => 20]);
	$price = (float) ($entry['price_per_kg'] ?? 20);
	return $price > 0 ? $price : 20.0;
}

function dashboard_get_area_sq_m(array $profile): float
{
	$unit = strtolower(trim((string) ($profile['areaUnit'] ?? $profile['area_unit'] ?? '')));
	$areaValue = (float) str_replace(',', '', (string) ($profile['areaPlanted'] ?? $profile['area_value'] ?? $profile['areaValue'] ?? 0));

	if (in_array($unit, ['hectares', 'hectare', 'ha'], true)) {
		return $areaValue > 0 ? $areaValue * 10000 : 0.0;
	}

	if ($areaValue > 0) {
		return $areaValue;
	}

	$length = (float) str_replace(',', '', (string) ($profile['areaLength'] ?? 0));
	$width = (float) str_replace(',', '', (string) ($profile['areaWidth'] ?? 0));
	if ($length > 0 && $width > 0) {
		return $length * $width;
	}

	return 0.0;
}

function dashboard_get_growth_projection_from_profile(array $profile): ?array
{
	$areaSqM = dashboard_get_area_sq_m($profile);
	if ($areaSqM <= 0) {
		return null;
	}

	$variety = strtolower(trim((string) ($profile['cornVariety'] ?? $profile['corn_variety'] ?? $profile['typeOfCorn'] ?? $profile['corn_type'] ?? '')));
	$soilType = strtolower(trim((string) ($profile['soilType'] ?? $profile['soil_type'] ?? 'Loam')));
	$density = (float) str_replace(',', '', (string) ($profile['plantingDensity'] ?? $profile['planting_density'] ?? 60000));
	$seedsPerHole = (int) round((float) str_replace(',', '', (string) ($profile['seedsPerHole'] ?? $profile['seeds_per_hole'] ?? 1)));
	$areaInHa = $areaSqM / 10000;
	$baseYieldHa = 5.5;
	$varietyFactor = 1.0;
	$daysToMaturity = 110;

	if (strpos($variety, 'sweet') !== false) {
		if (strpos($variety, 'hybrid') !== false) $varietyFactor = 0.8;
		elseif (strpos($variety, 'native') !== false) $varietyFactor = 0.65;
		elseif (strpos($variety, 'opv') !== false) $varietyFactor = 0.7;
		else $varietyFactor = 0.75;
		$daysToMaturity = 75;
	} elseif (strpos($variety, 'yellow') !== false) {
		if (strpos($variety, 'hybrid') !== false) $varietyFactor = 1.25;
		elseif (strpos($variety, 'feed') !== false) $varietyFactor = 1.15;
		elseif (strpos($variety, 'native') !== false) $varietyFactor = 0.95;
		else $varietyFactor = 1.1;
		$daysToMaturity = 115;
	} elseif (strpos($variety, 'white') !== false) {
		if (strpos($variety, 'field') !== false) $varietyFactor = 1.05;
		elseif (strpos($variety, 'native') !== false) $varietyFactor = 0.9;
		else $varietyFactor = 0.95;
		$daysToMaturity = 105;
	} elseif (strpos($variety, 'glutinous') !== false || strpos($variety, 'waxy') !== false) {
		$varietyFactor = 0.85;
		$daysToMaturity = 90;
	} elseif (strpos($variety, 'popcorn') !== false) {
		$varietyFactor = 0.55;
		$daysToMaturity = 100;
	} elseif (strpos($variety, 'baby') !== false) {
		$varietyFactor = 0.4;
		$daysToMaturity = 60;
	} elseif (strpos($variety, 'hybrid') !== false) {
		$varietyFactor = 1.2;
		$daysToMaturity = 115;
	}

	$soilFactor = 1.0;
	if (strpos($soilType, 'loam') !== false) {
		$soilFactor = 1.15;
	} elseif (strpos($soilType, 'clay') !== false) {
		$soilFactor = 0.9;
	} elseif (strpos($soilType, 'sandy') !== false) {
		$soilFactor = 0.85;
	}

	$densityFactor = 1.0;
	if ($density < 40000) {
		$densityFactor = 0.8;
	} elseif ($density > 80000) {
		$densityFactor = 0.85;
	}

	$seedsFactor = 1.0;
	if ($seedsPerHole > 2) {
		$seedsFactor = 0.9;
	}

	$predictedYieldPerHa = $baseYieldHa * $varietyFactor * $soilFactor * $densityFactor * $seedsFactor;
	$totalPredictedYield = $predictedYieldPerHa * $areaInHa;

	return [
		'totalYieldTons' => $totalPredictedYield,
		'yieldPerHa' => $predictedYieldPerHa,
		'daysToMaturity' => $daysToMaturity,
		'variety' => $variety
	];
}

function dashboard_get_estimated_income_from_profile(array $profile, array $marketPrices): ?float
{
	$projection = dashboard_get_growth_projection_from_profile($profile);
	if (!$projection) {
		return null;
	}

	$pricePerKg = dashboard_get_market_price_per_kg($profile, $marketPrices);
	$grossIncome = $projection['totalYieldTons'] * 1000 * $pricePerKg;
	return round($grossIncome, 2);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
	header("Content-Type: application/json; charset=utf-8");

	$rawInput = file_get_contents("php://input");
	$input = json_decode($rawInput, true);
	if (!is_array($input)) {
		$input = $_POST;
	}

	$action = trim((string) ($input["action"] ?? ""));

	if ($action === "void_planting_data") {
		$voidReason = trim((string) ($input["reason"] ?? ""));
		$voidNotes = trim((string) ($input["notes"] ?? ""));
		$confirmText = strtoupper(trim((string) ($input["confirmText"] ?? "")));

		if ($voidReason === "") {
			http_response_code(422);
			echo json_encode(["success" => false, "message" => "Please select a reason for voiding."]);
			exit;
		}

		if ($confirmText !== "VOID") {
			http_response_code(422);
			echo json_encode(["success" => false, "message" => "Type VOID to continue."]);
			exit;
		}

		$usersId = (int) $_SESSION["users_id"];
		$pathsToDelete = [
			$savedPlantingProfilePath,
			$savedCostingPath,
			$calendarTaskPath
		];

		$deletedFiles = 0;
		foreach ($pathsToDelete as $filePath) {
			if (is_file($filePath) && @unlink($filePath)) {
				$deletedFiles += 1;
			}
		}

		try {
			require __DIR__ . "/../data/db_connect.php";
			$conn->begin_transaction();

			$profileStmt = $conn->prepare("SELECT corn_profile_id, name FROM corn_profile WHERE users_id = ? AND status = 'active' ORDER BY corn_profile_id DESC LIMIT 1");
			$profileStmt->bind_param("i", $usersId);
			$profileStmt->execute();
			$profileRow = $profileStmt->get_result()->fetch_assoc();
			$profileStmt->close();

			$voidProfileId = (int) ($profileRow["corn_profile_id"] ?? 0);
			$voidProfileName = trim((string) ($profileRow["name"] ?? ""));
			if ($voidProfileName === "") {
				$voidProfileName = trim((string) ($_SESSION["name"] ?? $_SESSION["username"] ?? ""));
			}
			if ($voidProfileName === "") {
				$voidProfileName = "Farmer " . $usersId;
			}

			$conn->query(
				"CREATE TABLE IF NOT EXISTS `void_account` (
					`void_id` INT(11) NOT NULL AUTO_INCREMENT,
					`corn_profile_id` INT(11) NOT NULL,
					`users_id` INT(11) NOT NULL,
					`name` VARCHAR(150) NOT NULL,
					`reason` VARCHAR(255) NOT NULL,
					`notes` TEXT NULL,
					`date_void` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY (`void_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
			);

			// Always record a void_account entry so the action is auditable.
			$voidInsertStmt = $conn->prepare(
				"INSERT INTO void_account (corn_profile_id, users_id, name, reason, notes, date_void)
					VALUES (?, ?, ?, ?, ?, NOW())"
			);
			if ($voidInsertStmt) {
				$voidInsertStmt->bind_param("iisss", $voidProfileId, $usersId, $voidProfileName, $voidReason, $voidNotes);
				$voidInsertStmt->execute();
				$voidInsertStmt->close();
			}

			$voidProfileStmt = $conn->prepare("UPDATE corn_profile SET status = 'void' WHERE users_id = ? AND status = 'active'");
			$voidProfileStmt->bind_param("i", $usersId);
			$voidProfileStmt->execute();
			$voidProfileStmt->close();

			$deleteCostingStmt = $conn->prepare("DELETE FROM costing WHERE users_id = ?");
			$deleteCostingStmt->bind_param("i", $usersId);
			$deleteCostingStmt->execute();
			$deleteCostingStmt->close();

			$conn->commit();
		} catch (Throwable $error) {
			if (isset($conn) && $conn instanceof mysqli) {
				$conn->rollback();
			}

			http_response_code(500);
			echo json_encode(["success" => false, "message" => "Unable to void planting data right now."]);
			exit;
		}

		echo json_encode([
			"success" => true,
			"message" => "Planting data has been voided. Your account is now reset for a new planting cycle.",
			"deletedFiles" => $deletedFiles
		]);
		exit;
	}

	if ($action === "update_account_profile") {
		$usersId = (int) $_SESSION["users_id"];
		$fullName = trim((string) ($input["fullName"] ?? ""));
		$usernameRaw = trim((string) ($input["username"] ?? ""));
		$username = ltrim($usernameRaw, "@");

		$currentPassword = (string) ($input["currentPassword"] ?? "");
		$newPassword = (string) ($input["newPassword"] ?? "");
		$confirmNewPassword = (string) ($input["confirmNewPassword"] ?? "");

		if ($fullName === "") {
			http_response_code(422);
			echo json_encode(["success" => false, "message" => "Full Name is required."]);
			exit;
		}

		if (strlen($fullName) < 2) {
			http_response_code(422);
			echo json_encode(["success" => false, "message" => "Full Name must be at least 2 characters."]);
			exit;
		}

		if ($username === "") {
			http_response_code(422);
			echo json_encode(["success" => false, "message" => "Username is required."]);
			exit;
		}

		if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $username)) {
			http_response_code(422);
			echo json_encode(["success" => false, "message" => "Username must be 3-30 characters and use only letters, numbers, dot, underscore, or dash."]);
			exit;
		}

		$isChangingPassword = $currentPassword !== "" || $newPassword !== "" || $confirmNewPassword !== "";
		if ($isChangingPassword) {
			if ($currentPassword === "" || $newPassword === "" || $confirmNewPassword === "") {
				http_response_code(422);
				echo json_encode(["success" => false, "message" => "Complete all password fields to change your password."]);
				exit;
			}

			if (strlen($newPassword) < 6) {
				http_response_code(422);
				echo json_encode(["success" => false, "message" => "New password must be at least 6 characters."]);
				exit;
			}

			if ($newPassword !== $confirmNewPassword) {
				http_response_code(422);
				echo json_encode(["success" => false, "message" => "New password and confirmation do not match."]);
				exit;
			}
		}

		try {
			require __DIR__ . "/../data/db_connect.php";
			$conn->begin_transaction();

			$existingUsernameStmt = $conn->prepare("SELECT users_id FROM users WHERE username = ? AND users_id <> ? LIMIT 1");
			$existingUsernameStmt->bind_param("si", $username, $usersId);
			$existingUsernameStmt->execute();
			$existingUsername = $existingUsernameStmt->get_result()->fetch_assoc();
			$existingUsernameStmt->close();

			if ($existingUsername) {
				$conn->rollback();
				http_response_code(409);
				echo json_encode(["success" => false, "message" => "Username is already in use. Please choose another username."]);
				exit;
			}

			$passwordStmt = $conn->prepare("SELECT password FROM users WHERE users_id = ? LIMIT 1");
			$passwordStmt->bind_param("i", $usersId);
			$passwordStmt->execute();
			$accountRow = $passwordStmt->get_result()->fetch_assoc();
			$passwordStmt->close();

			if (!$accountRow) {
				$conn->rollback();
				http_response_code(404);
				echo json_encode(["success" => false, "message" => "Account not found."]);
				exit;
			}

			if ($isChangingPassword) {
				$storedPassword = (string) ($accountRow["password"] ?? "");
				$isValidPassword = password_verify($currentPassword, $storedPassword) || hash_equals($storedPassword, $currentPassword);
				if (!$isValidPassword) {
					$conn->rollback();
					http_response_code(422);
					echo json_encode(["success" => false, "message" => "Current password is incorrect."]);
					exit;
				}
			}

			if ($isChangingPassword) {
				$newHashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
				$updateStmt = $conn->prepare("UPDATE users SET name = ?, username = ?, password = ?, confirm_password = ? WHERE users_id = ? LIMIT 1");
				$updateStmt->bind_param("ssssi", $fullName, $username, $newHashedPassword, $newHashedPassword, $usersId);
			} else {
				$updateStmt = $conn->prepare("UPDATE users SET name = ?, username = ? WHERE users_id = ? LIMIT 1");
				$updateStmt->bind_param("ssi", $fullName, $username, $usersId);
			}

			$updateStmt->execute();
			$updateStmt->close();
			$conn->commit();

			$_SESSION["name"] = $fullName;
			$_SESSION["username"] = $username;

			echo json_encode([
				"success" => true,
				"message" => "Account details updated successfully.",
				"updatedName" => $fullName,
				"updatedUsername" => $username,
				"displayHandle" => "@" . $username
			]);
			exit;
		} catch (Throwable $error) {
			if (isset($conn) && $conn instanceof mysqli) {
				$conn->rollback();
			}

			http_response_code(500);
			echo json_encode(["success" => false, "message" => "Unable to update account right now."]);
			exit;
		}
	}

	if ($action === "weather_heat_watering_prompt") {
		$temperature = (float) ($input["temperature"] ?? 0);
		if ($temperature < 30) {
			http_response_code(422);
			echo json_encode(["success" => false, "message" => "Heat warning only applies when the temperature is 30°C or higher."]);
			exit;
		}

		$result = dashboard_append_heat_task($calendarTaskPath, $temperature);
		if (!($result["success"] ?? false)) {
			http_response_code(500);
			echo json_encode(["success" => false, "message" => (string) ($result["message"] ?? "Unable to update calendar right now.")]);
			exit;
		}

		echo json_encode([
			"success" => true,
			"message" => (string) ($result["message"] ?? "Weather reminder saved."),
			"createdType" => (string) ($result["createdType"] ?? "none")
		]);
		exit;
	}

	http_response_code(400);
	echo json_encode(["success" => false, "message" => "Unsupported request action."]);
	exit;
}

$savedPlantingProfile = dashboard_load_profile_from_db((int) $_SESSION["users_id"]);
$marketPricesData = json_decode($marketPricesJson, true);
if (!is_array($marketPricesData)) {
	$marketPricesData = ["market_prices" => ["other" => ["price_per_kg" => 20]]];
}
$marketPriceHistoryData = [];
$marketPriceHistoryLoadPath = is_file($marketPriceHistoryPath)
	? $marketPriceHistoryPath
	: (is_file($marketPriceHistoryPathLower) ? $marketPriceHistoryPathLower : '');
if ($marketPriceHistoryLoadPath !== '') {
	$marketPriceHistoryRaw = file_get_contents($marketPriceHistoryLoadPath);
	$marketPriceHistoryDecoded = json_decode((string) $marketPriceHistoryRaw, true);
	if (is_array($marketPriceHistoryDecoded)) {
		$marketPriceHistoryData = $marketPriceHistoryDecoded;
	}
}
$dashboardActiveMarketPriceKey = dashboard_get_market_price_key(is_array($savedPlantingProfile) ? $savedPlantingProfile : []);
$dashboardEstimatedIncomeValue = dashboard_get_estimated_income_from_profile(is_array($savedPlantingProfile) ? $savedPlantingProfile : [], is_array($marketPricesData["market_prices"] ?? null) ? $marketPricesData["market_prices"] : []);
$dashboardEstimatedIncomeLabel = $dashboardEstimatedIncomeValue !== null
	? "₱" . number_format((float) $dashboardEstimatedIncomeValue, 0)
	: "--";

$todayTaskNotificationCount = 0;
$todayTaskTitles = [];
$todayDateKey = (new DateTime("today"))->format("Y-m-d");

if (is_file($calendarTaskPath)) {
	$rawCalendarPayload = file_get_contents($calendarTaskPath);
	$decodedCalendarPayload = json_decode($rawCalendarPayload, true);
	$calendarTasks = is_array($decodedCalendarPayload) && is_array($decodedCalendarPayload["tasks"] ?? null)
		? $decodedCalendarPayload["tasks"]
		: [];

	foreach ($calendarTasks as $task) {
		if (!is_array($task)) {
			continue;
		}

		$taskDate = trim((string) ($task["date"] ?? ""));
		if ($taskDate !== $todayDateKey) {
			continue;
		}

		$isCompleted = (bool) ($task["completed"] ?? false);
		if ($isCompleted) {
			continue;
		}

		$todayTaskNotificationCount += 1;
		$taskTitle = trim((string) ($task["title"] ?? ""));
		if ($taskTitle !== "") {
			$todayTaskTitles[] = $taskTitle;
		}
	}
}

$todayTaskTitles = array_values(array_unique($todayTaskTitles));
$notificationBadgeLabel = $todayTaskNotificationCount > 99 ? "99+" : (string) $todayTaskNotificationCount;
$notificationTitleText = $todayTaskNotificationCount > 0
	? "You have " . $todayTaskNotificationCount . " pending corn care task" . ($todayTaskNotificationCount > 1 ? "s" : "") . " today"
	: "No pending corn care tasks today";

$calendarPayloadForMetrics = dashboard_load_calendar_payload($calendarTaskPath);
$calendarTasksForMetrics = is_array($calendarPayloadForMetrics["tasks"] ?? null) ? $calendarPayloadForMetrics["tasks"] : [];

$allCompletedTasks = 0;
$allPendingTasks = 0;

$todayDateObj = new DateTime("today");
$weekStartObj = (clone $todayDateObj)->modify('monday this week');
$weekEndObj = (clone $weekStartObj)->modify('+6 days');

foreach ($calendarTasksForMetrics as $taskMetric) {
	if (!is_array($taskMetric)) {
		continue;
	}

	$isCompletedTask = (bool) ($taskMetric["completed"] ?? false);
	$taskDateRaw = trim((string) ($taskMetric["date"] ?? ""));
	if ($isCompletedTask) {
		$allCompletedTasks += 1;
	} else {
		$allPendingTasks += 1;
	}
}

$latestTotalCostValue = dashboard_get_total_cost_from_db((int) $_SESSION["users_id"]);
$latestPestDetectionDate = dashboard_get_latest_pest_detection_datetime((int) $_SESSION["users_id"]);

$marketPriceMeta = json_decode($marketPricesJson, true);
$marketLastUpdatedRaw = trim((string) ($marketPriceMeta["last_updated"] ?? ""));

$recentProfileLabel = "No profile record yet";
if (!empty($savedPlantingProfile["plantingDate"])) {
	$recentProfileLabel = "Planting profile set on " . date('M d, Y', strtotime((string) $savedPlantingProfile["plantingDate"]));
}

$recentPredictionLabel = !empty($savedPlantingProfile["plantingDate"]) ? "No recorded prediction run yet" : "No profile data yet";
$recentPestLabel = $latestPestDetectionDate !== "" ? date('M d, Y h:i A', strtotime($latestPestDetectionDate)) : "No pest detection history yet";

$calendarUpdatedRaw = trim((string) ($calendarPayloadForMetrics["updatedAt"] ?? ""));
$recentCalendarLabel = $calendarUpdatedRaw !== "" ? date('M d, Y h:i A', strtotime($calendarUpdatedRaw)) : "No calendar updates yet";

$requestedDashboardView = trim((string) ($_GET["view"] ?? ""));
$startInFeatureView = $requestedDashboardView === "features";
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>AgriCorn Farmer Dashboard</title>
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
			--radius: 14px;
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
			background-image: linear-gradient(135deg, rgba(127, 182, 133, 0.08), rgba(250, 253, 247, 1), rgba(255, 229, 153, 0.14));
			background-repeat: no-repeat;
			background-size: cover;
			background-position: center;
			background-attachment: fixed;
		}

		.topbar {
			position: fixed;
			top: 0;
			left: 0;
			right: 0;
			width: 100%;
			z-index: 50;
			backdrop-filter: blur(8px);
			background: linear-gradient(90deg, rgba(127, 182, 133, 0.4), rgba(127, 182, 133, 0.35), rgba(255, 229, 153, 0.4));
			border-bottom: 1px solid var(--border);
			box-shadow: var(--shadow);
		}

		.topbar-inner {
			max-width: 1240px;
			margin: 0 auto;
			padding: 15px 18px;
		}

		.topbar-left {
			display: flex;
			align-items: center;
			gap: 12px;
			min-width: 0;
		}

		.user-identity-card {
			display: flex;
			align-items: center;
			gap: 10px;
			padding: 0;
			min-width: 0;
		}

		.avatar-badge {
			width: 52px;
			height: 52px;
			border-radius: 999px;
			background: #7fb685;
			color: #fff;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			font-size: 20px;
			font-weight: 800;
			box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.75), 0 8px 14px rgba(48, 96, 57, 0.2);
			letter-spacing: 0.03em;
			flex-shrink: 0;
		}

		.user-meta {
			min-width: 0;
		}

		.user-name {
			margin: 0;
			font-size: 1.1rem;
			font-weight: 700;
			line-height: 1.1;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		.user-handle {
			margin: 3px 0 0;
			font-size: 0.84rem;
			font-weight: 600;
			letter-spacing: 0.01em;
			color: var(--muted-foreground);
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		.icon-btn {
			width: 42px;
			height: 42px;
			border: 1px solid rgba(44, 62, 46, 0.18);
			border-radius: 10px;
			background: rgba(255, 255, 255, 0.72);
			display: inline-flex;
			align-items: center;
			justify-content: center;
			cursor: pointer;
			transition: all 0.2s ease;
			color: #304033;
		}

		.icon-btn:hover {
			transform: translateY(-1px);
			background: #ffffff;
			box-shadow: var(--shadow);
		}

		.icon-btn svg {
			width: 18px;
			height: 18px;
			fill: currentColor;
		}

		.notif-btn {
			position: relative;
		}

		.notif-badge {
			position: absolute;
			top: -6px;
			right: -6px;
			min-width: 18px;
			height: 18px;
			padding: 0 4px;
			border-radius: 999px;
			background: #dc3545;
			color: #fff;
			border: 2px solid #fff;
			font-size: 0.64rem;
			font-weight: 700;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			line-height: 1;
			box-shadow: 0 6px 12px rgba(220, 53, 69, 0.32);
		}

		.notif-btn.has-alert {
			animation: notifPulse 1.7s ease-in-out infinite;
		}

		@keyframes notifPulse {
			0%,
			100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
			50% { box-shadow: 0 0 0 6px rgba(220, 53, 69, 0.18); }
		}

		.notif-panel-ui {
			position: fixed;
			top: 76px;
			right: max(18px, calc((100vw - 1240px) / 2 + 18px));
			width: min(92vw, 374px);
			background: linear-gradient(180deg, #ffffff 0%, #fbfdf9 100%);
			border: 1px solid rgba(127, 182, 133, 0.28);
			border-radius: 16px;
			box-shadow: 0 20px 46px rgba(44, 74, 47, 0.2), 0 6px 16px rgba(44, 74, 47, 0.12);
			z-index: 78;
			overflow: hidden;
			opacity: 0;
			pointer-events: none;
			transform: translateY(-6px) scale(0.98);
			transition: opacity 0.18s ease, transform 0.18s ease;
		}

		.notif-panel-ui.show {
			opacity: 1;
			pointer-events: auto;
			transform: translateY(0) scale(1);
		}

		.notif-panel-head {
			padding: 12px 14px 10px;
			border-bottom: 1px solid rgba(127, 182, 133, 0.18);
			background: linear-gradient(180deg, rgba(127, 182, 133, 0.14), rgba(127, 182, 133, 0.02));
		}

		.notif-head-row {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 10px;
		}

		.notif-head-title-wrap {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			min-width: 0;
		}

		.notif-head-icon {
			width: 24px;
			height: 24px;
			border-radius: 8px;
			background: rgba(127, 182, 133, 0.24);
			color: #4f8b56;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			flex-shrink: 0;
		}

		.notif-head-icon svg {
			width: 14px;
			height: 14px;
			fill: currentColor;
		}

		.notif-count-chip {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 2px 8px;
			border-radius: 999px;
			font-size: 0.72rem;
			font-weight: 700;
			line-height: 1.4;
			background: rgba(127, 182, 133, 0.2);
			color: #2f5f34;
			border: 1px solid rgba(127, 182, 133, 0.35);
		}

		.notif-panel-title {
			margin: 0;
			font-size: 0.95rem;
			font-weight: 700;
			color: #2c3e2e;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		.notif-panel-sub {
			margin: 4px 0 0;
			font-size: 0.78rem;
			color: #5f6f63;
		}

		.notif-panel-body {
			padding: 10px 12px;
			max-height: 255px;
			overflow: auto;
		}

		.notif-panel-body::-webkit-scrollbar {
			width: 8px;
		}

		.notif-panel-body::-webkit-scrollbar-thumb {
			background: rgba(127, 182, 133, 0.4);
			border-radius: 999px;
		}

		.notif-list {
			list-style: none;
			margin: 0;
			padding: 0;
			display: grid;
			gap: 8px;
		}

		.notif-item {
			padding: 9px 10px;
			border-radius: 11px;
			border: 1px solid rgba(127, 182, 133, 0.2);
			background: rgba(248, 252, 246, 0.95);
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 10px;
			transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
		}

		.notif-item:hover {
			transform: translateY(-1px);
			box-shadow: 0 8px 14px rgba(45, 75, 50, 0.08);
			border-color: rgba(127, 182, 133, 0.4);
		}

		.notif-item-left {
			min-width: 0;
			display: grid;
			gap: 2px;
			flex: 1;
		}

		.notif-item-title {
			font-size: 0.8rem;
			color: #36503a;
			line-height: 1.3;
			font-weight: 600;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		.notif-item-meta {
			font-size: 0.72rem;
			font-weight: 600;
			color: #7a8f7d;
		}

		.notif-view-btn {
			width: 30px;
			height: 30px;
			border: 1px solid rgba(127, 182, 133, 0.36);
			border-radius: 8px;
			background: linear-gradient(180deg, #ffffff, #f7fbf4);
			color: #4d8553;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			cursor: pointer;
			flex-shrink: 0;
			transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease, transform 0.18s ease;
		}

		.notif-view-btn svg {
			width: 15px;
			height: 15px;
			fill: currentColor;
		}

		.notif-view-btn:hover {
			background: #eff8ed;
			border-color: rgba(127, 182, 133, 0.5);
			color: #4b8552;
			transform: translateY(-1px);
		}

		.notif-empty {
			padding: 14px 12px;
			border-radius: 10px;
			border: 1px dashed rgba(127, 182, 133, 0.35);
			background: #f8fcf6;
			font-size: 0.82rem;
			color: #607563;
			text-align: center;
		}

		.notif-panel-note {
			padding: 9px 12px 11px;
			border-top: 1px solid rgba(127, 182, 133, 0.15);
			font-size: 0.74rem;
			font-weight: 600;
			color: #708374;
			background: rgba(255, 255, 255, 0.8);
		}

		.icon-btn.logout {
			color: #d4183d;
		}

		.dashboard-wrap {
			max-width: 1240px;
			margin: 0 auto;
			padding: 104px 18px 38px;
		}

		.profile-setup-banner-wrap {
			margin-bottom: 16px;
		}

		.profile-setup-banner {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 14px;
			width: 100%;
			border: 1px solid rgba(234, 179, 8, 0.35);
			border-radius: 14px;
			background: linear-gradient(120deg, #fff8df 0%, #fff4cc 52%, #fff0bd 100%);
			color: #734d00;
			padding: 12px 14px;
			text-align: left;
			box-shadow: 0 10px 24px rgba(234, 179, 8, 0.14);
			cursor: pointer;
			transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
		}

		.profile-setup-banner:hover {
			transform: translateY(-1px);
			box-shadow: 0 14px 28px rgba(234, 179, 8, 0.2);
			border-color: rgba(217, 119, 6, 0.45);
		}

		.profile-setup-banner:focus-visible {
			outline: 3px solid rgba(217, 119, 6, 0.35);
			outline-offset: 2px;
		}

		.profile-setup-banner-main {
			display: flex;
			align-items: center;
			gap: 10px;
			min-width: 0;
		}

		.profile-setup-banner-icon {
			width: 34px;
			height: 34px;
			border-radius: 10px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			background: rgba(217, 119, 6, 0.16);
			color: #b45309;
			flex-shrink: 0;
		}

		.profile-setup-banner-icon svg {
			width: 18px;
			height: 18px;
			fill: currentColor;
		}

		.profile-setup-banner-text {
			font-size: 0.9rem;
			font-weight: 700;
			line-height: 1.35;
		}

		.profile-setup-banner-cta {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			font-size: 0.82rem;
			font-weight: 800;
			color: #92400e;
			white-space: nowrap;
		}

		.profile-setup-banner-cta svg {
			width: 14px;
			height: 14px;
			fill: currentColor;
		}

		.stat-card,
		.panel,
		.feature-card {
			border: none;
			border-radius: var(--radius);
			background: var(--card);
			box-shadow: var(--shadow);
			overflow: hidden;
		}

		.stat-card {
			position: relative;
			min-height: 174px;
			transition: transform 0.2s ease, box-shadow 0.2s ease;
		}

		.stat-card:hover,
		.feature-card:hover {
			transform: translateY(-2px);
			box-shadow: var(--shadow-lg);
		}

		.stat-card .blur-orb,
		.feature-card .blur-orb {
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

		.stat-icon {
			width: 44px;
			height: 44px;
			border-radius: 12px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
		}

		.stat-icon svg {
			width: 22px;
			height: 22px;
			fill: currentColor;
		}

		.status-badge {
			font-size: 0.74rem;
			border-radius: 999px;
			padding: 5px 10px;
			border: 1px solid rgba(0, 0, 0, 0.1);
			background: rgba(255, 255, 255, 0.8);
			font-weight: 600;
			color: #495a4c;
			white-space: nowrap;
		}

		.status-good {
			color: #1f8b3f;
			font-weight: 600;
			font-size: 0.78rem;
		}

		.status-good svg {
			width: 12px;
			height: 12px;
			fill: currentColor;
			vertical-align: -2px;
			margin-right: 4px;
		}

		.muted-copy {
			color: var(--muted-foreground);
			font-size: 0.8rem;
			margin: 0;
		}

		.metric-title {
			color: var(--muted-foreground);
			font-size: 0.85rem;
			font-weight: 600;
			margin-bottom: 4px;
		}

		.metric-value {
			font-size: clamp(2rem, 5vw, 2.25rem);
			font-weight: 700;
			line-height: 1;
			margin: 0;
		}

		.metric-value-small {
			font-size: clamp(1.25rem, 3.6vw, 1.55rem);
			line-height: 1.15;
		}

		.income-copy {
			margin: 8px 0 0;
			font-size: 0.8rem;
			color: #4f6552;
			font-weight: 600;
		}

		.income-copy span {
			font-weight: 700;
			color: #1f8b3f;
		}

		.panel {
			padding: 16px;
		}

		.panel-header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			margin-bottom: 10px;
			gap: 8px;
			flex-wrap: wrap;
		}

		.panel-title {
			margin: 0;
			font-size: 1.02rem;
			font-weight: 700;
		}

		.panel-subtitle {
			margin: 2px 0 0;
			color: var(--muted-foreground);
			font-size: 0.82rem;
		}

		.pill {
			font-size: 0.72rem;
			padding: 4px 9px;
			border-radius: 999px;
			border: 1px solid var(--border);
			color: #566658;
			background: rgba(255, 255, 255, 0.86);
			font-weight: 600;
		}

		.growth-box {
			background: linear-gradient(180deg, rgba(255, 249, 230, 0.42), rgba(255, 255, 255, 1));
			border: 2px solid rgba(127, 182, 133, 0.2);
			border-radius: 14px;
			padding: 22px;
			min-height: 240px;
			display: flex;
			align-items: center;
			justify-content: center;
		}

		.plant-visual {
			width: 160px;
			height: 180px;
			position: relative;
			display: flex;
			align-items: end;
			justify-content: center;
		}

		.plant-stem {
			width: 12px;
			height: 108px;
			border-radius: 10px;
			background: linear-gradient(180deg, #6bb174, #4c9657);
			position: absolute;
			bottom: 18px;
			left: 50%;
			transform: translateX(-50%);
		}

		.plant-base {
			width: 44px;
			height: 10px;
			border-radius: 8px;
			background: #7f5c36;
			position: absolute;
			bottom: 8px;
			left: 50%;
			transform: translateX(-50%);
		}

		.leaf {
			position: absolute;
			border-radius: 50px 50px 8px 8px;
			background: #62a56d;
		}

		.leaf.l1 {
			width: 28px;
			height: 60px;
			left: 39px;
			bottom: 72px;
			transform: rotate(-22deg);
		}

		.leaf.l2 {
			width: 30px;
			height: 66px;
			right: 39px;
			bottom: 76px;
			transform: rotate(22deg);
		}

		.leaf.l3 {
			width: 30px;
			height: 62px;
			left: 62px;
			bottom: 88px;
			transform: rotate(-4deg);
		}

		.cob {
			position: absolute;
			width: 28px;
			height: 52px;
			border-radius: 12px;
			background: linear-gradient(180deg, #f4cb63, #e8ae2d);
			box-shadow: 0 6px 12px rgba(167, 120, 27, 0.28);
			left: 50%;
			transform: translateX(-50%);
			bottom: 70px;
			display: none;
		}

		.tassel {
			position: absolute;
			width: 30px;
			height: 18px;
			border-radius: 7px;
			background: #efd074;
			left: 50%;
			transform: translateX(-50%);
			bottom: 128px;
			display: none;
		}

		.harvest-stack {
			display: none;
			position: absolute;
			bottom: 18px;
			left: 50%;
			transform: translateX(-50%);
			gap: 10px;
		}

		.harvest-stack span {
			width: 36px;
			height: 64px;
			border-radius: 10px;
			background: linear-gradient(180deg, #f8d584, #e8ae2d);
			box-shadow: 0 8px 14px rgba(162, 112, 24, 0.25);
			display: inline-block;
		}

		.stage-meta {
			text-align: center;
			display: grid;
			gap: 6px;
			padding: 14px 16px 16px;
			border-radius: 18px;
			border: 1px solid rgba(127, 182, 133, 0.18);
			background: linear-gradient(180deg, rgba(255, 255, 255, 0.9), rgba(243, 248, 239, 0.86));
			box-shadow: 0 8px 18px rgba(37, 56, 40, 0.05);
		}

		.day-pill {
			display: inline-block;
			width: fit-content;
			margin: 0 auto;
			background: var(--primary);
			color: #fff;
			border-radius: 999px;
			padding: 5px 13px;
			font-size: 0.72rem;
			font-weight: 700;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			margin-bottom: 0;
		}

		.crop-tag {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			border-radius: 999px;
			border: 1px solid rgba(255, 229, 153, 0.82);
			background: linear-gradient(180deg, rgba(255, 244, 197, 0.9), rgba(255, 229, 153, 0.28));
			padding: 8px 14px;
			font-size: 0.83rem;
			font-weight: 700;
			color: #6d5317;
			box-shadow: 0 6px 14px rgba(162, 112, 24, 0.08);
		}

		.crop-tag svg {
			width: 16px;
			height: 16px;
			fill: #d29d1a;
		}

		.stage-name {
			margin: 0;
			font-size: 1.24rem;
			font-weight: 800;
			line-height: 1.2;
			color: #223427;
			letter-spacing: -0.02em;
		}

		.stage-days {
			margin: 0;
			color: #5f6f63;
			font-size: 0.88rem;
			font-weight: 600;
		}

		.stage-date {
			margin: 0;
			color: #6b7c6e;
			font-size: 0.8rem;
			font-weight: 600;
		}

		.range-label {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			font-size: 0.72rem;
			font-weight: 700;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			color: #5f6f63;
			margin-bottom: 8px;
		}

		.stage-range {
			width: 100%;
			accent-color: #7fb685;
		}

		.overall-label {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			color: #5f6f63;
			font-size: 0.72rem;
			font-weight: 700;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			margin-bottom: 8px;
		}

		.overall-track {
			display: grid;
			grid-template-columns: repeat(8, minmax(0, 1fr));
			gap: 4px;
		}

		.overall-track span {
			height: 10px;
			border-radius: 8px;
			background: #dfe9e2;
			display: block;
		}

		.overall-track span.active {
			background: #7fb685;
		}

		.overall-track span.current {
			background: rgba(127, 182, 133, 0.58);
		}

		.chart-wrap {
			position: relative;
			width: 100%;
			height: 250px;
		}

		.chart-wrap.tall {
			height: 300px;
		}

		.chart-wrap.moisture-extended {
			height: 330px;
		}

		.market-chart-scroll {
			overflow-x: hidden;
			overflow-y: hidden;
		}

		.market-chart-inner {
			position: relative;
			height: 100%;
			min-width: 100%;
		}

		.floating-btn {
			position: fixed;
			top: 50%;
			transform: translateY(-50%);
			width: 56px;
			height: 56px;
			border: none;
			border-radius: 999px;
			background: #7fb685;
			color: #fff;
			box-shadow: var(--shadow-lg);
			z-index: 60;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			cursor: pointer;
			transition: transform 0.2s ease, background 0.2s ease;
		}

		.floating-btn svg {
			width: 22px;
			height: 22px;
			fill: currentColor;
		}

		.floating-btn:hover {
			transform: translateY(-50%) scale(1.04);
			background: #6aa872;
		}

		.floating-right {
			right: 18px;
		}

		.floating-left {
			left: 18px;
		}

		.feature-card {
			position: relative;
			cursor: pointer;
			transition: transform 0.2s ease, box-shadow 0.2s ease;
			min-height: 224px;
		}

		.feature-card .body {
			position: relative;
			z-index: 2;
			padding: 20px;
			height: 100%;
			display: flex;
			flex-direction: column;
		}

		.feature-tag {
			display: inline-flex;
			align-items: center;
			padding: 4px 10px;
			border-radius: 999px;
			font-size: 0.68rem;
			font-weight: 800;
			letter-spacing: 0.05em;
			text-transform: uppercase;
			margin-bottom: 10px;
			border: 1px solid rgba(127, 182, 133, 0.28);
			background: rgba(255, 255, 255, 0.72);
			color: #46634a;
		}

		.feature-icon {
			width: 44px;
			height: 44px;
			border-radius: 12px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			margin-bottom: 14px;
		}

		.feature-icon svg {
			width: 21px;
			height: 21px;
			fill: currentColor;
		}

		.feature-title {
			font-size: 1.02rem;
			font-weight: 700;
			margin-bottom: 8px;
			transition: color 0.2s ease;
		}

		.feature-desc {
			color: var(--muted-foreground);
			font-size: 0.9rem;
			line-height: 1.5;
			margin-bottom: auto;
		}

		.feature-cta {
			margin-top: 14px;
			font-size: 0.76rem;
			font-weight: 700;
			color: #46634a;
			display: inline-flex;
			align-items: center;
			gap: 6px;
		}

		.feature-cta svg {
			width: 14px;
			height: 14px;
			fill: currentColor;
		}

		.feature-bar {
			width: 0;
			height: 4px;
			border-radius: 999px;
			transition: width 0.4s ease;
			margin-top: 18px;
		}

		.feature-card:hover .feature-bar {
			width: 100%;
		}

		.feature-card:hover .feature-title {
			color: #5f9a65;
		}

		.modal-mask {
			position: fixed;
			inset: 0;
			background: rgba(20, 29, 23, 0.4);
			backdrop-filter: blur(3px);
			z-index: 79;
			display: none;
		}

		/* Void deleting overlay and toast */
		.void-confirm-modal {
			position: fixed;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%) scale(0.98);
			width: min(92vw, 430px);
			padding: 0;
			z-index: 82;
			background: transparent;
			opacity: 0;
			pointer-events: none;
			transition: all 0.2s ease;
		}

		.void-confirm-modal.show {
			opacity: 1;
			pointer-events: auto;
			transform: translate(-50%, -50%) scale(1);
		}

		.void-confirm-card {
			border: 1px solid rgba(220, 53, 69, 0.22);
			border-radius: 18px;
			background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(255, 246, 247, 0.98));
			box-shadow: 0 20px 50px rgba(69, 24, 31, 0.22);
			overflow: hidden;
		}

		.void-confirm-header {
			display: flex;
			align-items: center;
			gap: 12px;
			padding: 18px 18px 12px;
			background: linear-gradient(135deg, rgba(220, 53, 69, 0.12), rgba(255, 255, 255, 0));
			border-bottom: 1px solid rgba(220, 53, 69, 0.12);
		}

		.void-confirm-icon {
			width: 42px;
			height: 42px;
			border-radius: 14px;
			background: rgba(220, 53, 69, 0.12);
			color: #b92f43;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			flex-shrink: 0;
		}

		.void-confirm-icon svg,
		.void-delete-spinner svg {
			width: 20px;
			height: 20px;
			fill: currentColor;
		}

		.void-confirm-title {
			margin: 0;
			font-size: 1.08rem;
			font-weight: 800;
			color: #8d2733;
		}

		.void-confirm-sub {
			margin: 3px 0 0;
			font-size: 0.9rem;
			color: #6c3d43;
			line-height: 1.45;
		}

		.void-confirm-body {
			padding: 16px 18px 18px;
		}

		.void-confirm-note {
			margin: 0 0 14px;
			font-size: 0.9rem;
			color: #5d5d66;
		}

		.void-confirm-actions,
		.void-delete-actions {
			display: flex;
			justify-content: flex-end;
			gap: 10px;
			flex-wrap: wrap;
		}

		#voidDeletingOverlay .logout-modal {
			background: #fff;
			border-radius: 16px;
			border: 1px solid rgba(127, 182, 133, 0.18);
			box-shadow: 0 18px 50px rgba(28, 57, 35, 0.18);
		}

		#voidDeletingOverlay .logout-modal.show {
			transform: translate(-50%, -50%) scale(1);
		}

		.void-delete-spinner {
			width: 48px;
			height: 48px;
			border-radius: 50%;
			border: 4px solid rgba(127, 182, 133, 0.18);
			border-top-color: #7fb685;
			border-right-color: #d9e8dc;
			margin: 0 auto 12px;
			animation: voidSpin 0.9s linear infinite;
		}

		@keyframes voidSpin {
			to { transform: rotate(360deg); }
		}

		.void-delete-title {
			margin: 0 0 6px;
			font-weight: 800;
			font-size: 1.02rem;
			color: #2d3d30;
		}

		.void-delete-copy {
			margin: 0;
			color: #5f6f63;
			font-size: 0.9rem;
		}

		#voidToast {
			position: fixed;
			right: 18px;
			bottom: 18px;
			z-index: 120;
			max-width: min(92vw, 360px);
			opacity: 0;
			transform: translateY(14px);
			pointer-events: none;
			transition: opacity 0.22s ease, transform 0.22s ease;
		}

		#voidToast.show {
			opacity: 1;
			transform: translateY(0);
		}

		.void-toast-card {
			border-radius: 16px;
			border: 1px solid rgba(31, 139, 63, 0.18);
			background: linear-gradient(160deg, rgba(31, 139, 63, 0.98), rgba(24, 116, 53, 0.98));
			box-shadow: 0 16px 34px rgba(19, 94, 42, 0.24);
			color: #fff;
			padding: 14px 15px;
		}

		.void-toast-title {
			margin: 0;
			font-weight: 800;
			font-size: 0.95rem;
		}

		.void-toast-msg {
			margin: 6px 0 0;
			font-size: 0.88rem;
			line-height: 1.45;
			opacity: 0.95;
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

		.summary-modal {
			position: fixed;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%) scale(0.98);
			background: linear-gradient(165deg, #ffffff 0%, #f5fbf3 100%);
			border: 1px solid rgba(127, 182, 133, 0.24);
			border-radius: 16px;
			width: min(96vw, 920px);
			box-shadow: var(--shadow-lg);
			z-index: 80;
			padding: 20px;
			max-height: 90vh;
			overflow-y: auto;
			opacity: 0;
			pointer-events: none;
			transition: all 0.2s ease;
		}

		.summary-modal.show {
			opacity: 1;
			pointer-events: auto;
			transform: translate(-50%, -50%) scale(1);
		}

		.summary-modal-head {
			display: flex;
			justify-content: space-between;
			align-items: flex-start;
			gap: 10px;
			padding-bottom: 10px;
			margin-bottom: 10px;
			border-bottom: 1px solid rgba(127, 182, 133, 0.18);
		}

		.summary-history-chip {
			appearance: none;
			border: 1px solid rgba(127, 182, 133, 0.24);
			display: inline-flex;
			align-items: center;
			gap: 8px;
			margin-bottom: 10px;
			padding: 7px 12px;
			border-radius: 999px;
			background: rgba(127, 182, 133, 0.12);
			color: #4f7356;
			font-size: 0.8rem;
			font-weight: 700;
			line-height: 1;
			cursor: pointer;
			transition: transform 0.16s ease, background 0.16s ease, box-shadow 0.16s ease;
			text-align: left;
		}

		.summary-history-chip:hover,
		.summary-history-chip:focus-visible {
			background: rgba(127, 182, 133, 0.18);
			transform: translateY(-1px);
			box-shadow: 0 10px 22px rgba(95, 114, 100, 0.12);
			outline: none;
		}

		.summary-history-chip svg {
			width: 15px;
			height: 15px;
			flex: 0 0 auto;
		}

		.summary-history-chip span {
			display: inline-flex;
			align-items: center;
			gap: 4px;
		}

		.summary-history-modal {
			display: none;
			background: linear-gradient(165deg, #ffffff 0%, #f5fbf3 100%);
			border: 1px solid rgba(127, 182, 133, 0.24);
			border-radius: 16px;
			width: 100%;
			box-shadow: var(--shadow-lg);
			padding: 18px;
			margin-top: 14px;
		}

		.summary-history-modal.show {
			display: block;
		}

		.summary-history-head {
			display: flex;
			align-items: flex-start;
			justify-content: space-between;
			gap: 12px;
			margin-bottom: 14px;
		}

		.summary-history-title {
			margin: 0;
			font-size: 1rem;
			font-weight: 800;
		}

		.summary-history-sub {
			margin: 4px 0 0;
			font-size: 0.84rem;
			color: var(--muted-foreground);
			line-height: 1.4;
		}

		.summary-history-list {
			display: grid;
			gap: 10px;
			max-height: min(52vh, 420px);
			overflow: auto;
			padding-right: 4px;
		}

		.summary-history-item {
			padding: 16px;
			border-radius: 14px;
			background: rgba(255, 255, 255, 0.86);
			border: 1px solid rgba(127, 182, 133, 0.18);
			box-shadow: 0 10px 24px rgba(95, 114, 100, 0.06);
			overflow: hidden;
		}

		.summary-history-item-head {
			display: flex;
			align-items: flex-start;
			justify-content: space-between;
			gap: 12px;
			margin-bottom: 14px;
		}

		.summary-history-item strong {
			display: block;
			font-size: 0.96rem;
		}

		.summary-history-item span {
			display: block;
			font-size: 0.8rem;
			color: var(--muted-foreground);
			margin-top: 3px;
		}

		.summary-history-meta {
			display: grid;
			grid-template-columns: repeat(4, minmax(0, 1fr));
			gap: 10px;
			margin-bottom: 14px;
		}

		.summary-history-meta-row {
			padding: 10px 12px;
			border-radius: 14px;
			background: rgba(127, 182, 133, 0.08);
			border: 1px solid rgba(127, 182, 133, 0.14);
			min-height: 66px;
		}

		.summary-history-meta-label {
			display: block;
			font-size: 0.72rem;
			font-weight: 800;
			text-transform: uppercase;
			letter-spacing: 0.04em;
			color: #5f7f66;
			margin-bottom: 4px;
		}

		.summary-history-meta-value {
			display: block;
			font-size: 0.86rem;
			font-weight: 700;
			color: #324336;
			line-height: 1.35;
		}

		.summary-history-sections {
			display: grid;
			gap: 12px;
			margin-top: 0;
		}

		.summary-history-section {
			padding: 12px 13px;
			border-radius: 14px;
			background: rgba(255, 255, 255, 0.72);
			border: 1px solid rgba(127, 182, 133, 0.12);
			box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.72);
		}

		.summary-history-section-title {
			margin: 0 0 8px;
			font-size: 0.82rem;
			font-weight: 800;
			text-transform: uppercase;
			letter-spacing: 0.04em;
			color: #5a7360;
		}

		.summary-history-section-grid {
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 8px 12px;
		}

		.summary-history-section-grid .summary-history-meta-row {
			padding: 0;
			border: 0;
			background: transparent;
		}

		.summary-history-section-grid .summary-history-meta-label {
			margin-bottom: 2px;
		}

		.summary-history-section-grid .summary-history-meta-value {
			font-weight: 600;
		}

		.summary-history-note {
			margin-top: 0;
			padding: 10px 12px;
			border-radius: 12px;
			background: rgba(255, 243, 205, 0.38);
			border: 1px solid rgba(214, 171, 47, 0.2);
			font-size: 0.82rem;
			color: #705915;
			line-height: 1.45;
		}

		.summary-history-item + .summary-history-item {
			margin-top: 2px;
		}

		.summary-history-item:hover {
			border-color: rgba(127, 182, 133, 0.28);
			box-shadow: 0 12px 28px rgba(95, 114, 100, 0.08);
		}

		.summary-history-empty {
			padding: 14px;
			border-radius: 14px;
			background: rgba(255, 255, 255, 0.86);
			border: 1px dashed rgba(127, 182, 133, 0.28);
			color: var(--muted-foreground);
			font-size: 0.88rem;
		}

		@media (max-width: 640px) {
			.summary-history-modal {
				width: min(96vw, 520px);
				padding: 14px;
			}

			.summary-history-head {
				flex-direction: column;
				align-items: stretch;
			}

			.summary-history-item-head {
				flex-direction: column;
			}

			.summary-history-item .pill {
				align-self: flex-start;
			}

			.summary-history-meta {
				grid-template-columns: 1fr;
			}

			.summary-history-section-grid {
				grid-template-columns: 1fr;
			}

			.summary-history-section-title {
				margin-bottom: 10px;
			}

			.summary-history-section,
			.summary-history-note {
				padding-left: 11px;
				padding-right: 11px;
			}
		}

		.summary-modal-title {
			margin: 0;
			font-size: 1.12rem;
			font-weight: 800;
		}

		.summary-modal-sub {
			margin: 3px 0 0;
			font-size: 0.85rem;
			color: var(--muted-foreground);
			line-height: 1.45;
		}

		.summary-stage-pill-locked {
			background: rgba(107, 124, 110, 0.14);
			color: #5e6c61;
			border: 1px solid rgba(107, 124, 110, 0.22);
		}

		.summary-stage-pill-ready {
			background: rgba(34, 197, 94, 0.16);
			color: #1f8b3f;
			border: 1px solid rgba(34, 197, 94, 0.25);
		}

		.summary-stepper {
			display: grid;
			grid-template-columns: repeat(3, minmax(0, 1fr));
			gap: 10px;
			margin-bottom: 14px;
		}

		.summary-step-btn {
			position: relative;
			display: flex;
			align-items: center;
			gap: 10px;
			padding: 12px 14px;
			border-radius: 14px;
			border: 1px solid rgba(127, 182, 133, 0.18);
			background: rgba(255, 255, 255, 0.76);
			color: #526156;
			text-align: left;
			font-weight: 700;
			box-shadow: 0 8px 16px rgba(37, 56, 40, 0.05);
		}

		.summary-step-btn::before {
			content: "";
			position: absolute;
			left: 16px;
			right: 16px;
			top: 10px;
			height: 2px;
			background: linear-gradient(90deg, rgba(127, 182, 133, 0.16), rgba(127, 182, 133, 0.42));
			border-radius: 999px;
			opacity: 0.35;
		}

		.summary-step-btn .summary-step-number,
		.summary-step-btn .summary-step-label {
			position: relative;
			z-index: 1;
		}

		.summary-step-number {
			width: 32px;
			height: 32px;
			border-radius: 50%;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			background: #edf6ef;
			color: #6b7c6e;
			font-size: 0.82rem;
			font-weight: 800;
			flex-shrink: 0;
		}

		.summary-step-btn .summary-step-label {
			display: flex;
			flex-direction: column;
			gap: 2px;
			font-size: 0.88rem;
		}

		.summary-step-btn .summary-step-sub {
			font-size: 0.72rem;
			font-weight: 600;
			color: #7a897d;
		}

		.summary-step-btn.active {
			border-color: rgba(31, 139, 63, 0.28);
			background: linear-gradient(180deg, rgba(31, 139, 63, 0.12), rgba(255, 255, 255, 0.95));
			color: #1f8b3f;
		}

		.summary-step-btn.active .summary-step-number {
			background: #1f8b3f;
			color: #ffffff;
		}

		.summary-step-btn.completed {
			border-color: rgba(34, 197, 94, 0.22);
		}

		.summary-step-btn.completed .summary-step-number {
			background: rgba(34, 197, 94, 0.16);
			color: #1f8b3f;
		}

		.summary-step-panel {
			display: grid;
			gap: 10px;
		}

		.summary-hero-card,
		.summary-panel-card {
			border-radius: 16px;
			border: 1px solid rgba(127, 182, 133, 0.18);
			background: linear-gradient(180deg, rgba(255, 255, 255, 0.92), rgba(242, 248, 239, 0.94));
			box-shadow: 0 10px 22px rgba(37, 56, 40, 0.06);
		}

		.summary-hero-card {
			padding: 16px;
			display: grid;
			gap: 14px;
		}

		.summary-hero-top {
			display: flex;
			justify-content: space-between;
			align-items: flex-start;
			gap: 12px;
			flex-wrap: wrap;
		}

		.summary-hero-kicker {
			margin: 0 0 4px;
			font-size: 0.72rem;
			letter-spacing: 0.12em;
			text-transform: uppercase;
			font-weight: 800;
			color: #7b8a7d;
		}

		.summary-hero-title {
			margin: 0;
			font-size: 1.05rem;
			font-weight: 800;
			color: #24442c;
		}

		.summary-hero-copy {
			margin: 6px 0 0;
			font-size: 0.9rem;
			line-height: 1.55;
			color: #5a6b5d;
		}

		.summary-hero-badge {
			padding: 9px 12px;
			border-radius: 999px;
			background: rgba(31, 139, 63, 0.12);
			border: 1px solid rgba(31, 139, 63, 0.16);
			color: #1f8b3f;
			font-weight: 800;
			font-size: 0.76rem;
		}

		.summary-hero-stats {
			display: grid;
			grid-template-columns: repeat(4, minmax(0, 1fr));
			gap: 10px;
		}

		.summary-hero-stat {
			padding: 11px 12px;
			border-radius: 14px;
			background: rgba(255, 255, 255, 0.84);
			border: 1px solid rgba(127, 182, 133, 0.16);
		}

		.summary-hero-stat-label {
			display: block;
			font-size: 0.7rem;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			color: #6b7c6e;
			margin-bottom: 4px;
		}

		.summary-hero-stat-value {
			font-size: 1rem;
			font-weight: 800;
			color: #24442c;
		}

		.summary-panel-card {
			padding: 14px;
		}

		.summary-panel-title {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 10px;
			margin-bottom: 12px;
		}

		.summary-panel-title h4 {
			margin: 0;
			font-size: 0.98rem;
			font-weight: 800;
			color: #24442c;
		}

		.summary-panel-title span {
			font-size: 0.72rem;
			font-weight: 800;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			color: #7b8a7d;
		}

		.summary-card-grid {
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 10px;
		}

		.summary-card-grid .summary-row {
			margin: 0;
		}

		.summary-card-grid .summary-row-label {
			font-size: 0.8rem;
		}

		.summary-card-grid .summary-row-value {
			font-size: 0.86rem;
		}

		.summary-comparison-shell {
			display: grid;
			gap: 14px;
		}

		.summary-comparison-intro {
			padding: 14px 16px;
			border-radius: 16px;
			border: 1px solid rgba(127, 182, 133, 0.18);
			background: linear-gradient(135deg, rgba(31, 139, 63, 0.08), rgba(255, 255, 255, 0.95));
		}

		.summary-comparison-intro h4 {
			margin: 0 0 6px;
			font-size: 1rem;
			font-weight: 800;
			color: #24442c;
		}

		.summary-comparison-intro p {
			margin: 0;
			font-size: 0.9rem;
			line-height: 1.55;
			color: #5a6b5d;
		}

		.summary-comparison-shell {
			display: grid;
			gap: 14px;
		}

		.summary-comparison-table-wrap {
			overflow-x: auto;
			border-radius: 14px;
			border: 1px solid rgba(127, 182, 133, 0.18);
			background: rgba(255, 255, 255, 0.75);
		}

		.summary-comparison-table {
			width: 100%;
			border-collapse: collapse;
			min-width: 760px;
		}

		.summary-comparison-table th,
		.summary-comparison-table td {
			padding: 12px 14px;
			border-bottom: 1px solid rgba(127, 182, 133, 0.14);
			vertical-align: middle;
		}

		.summary-comparison-table th {
			font-size: 0.74rem;
			text-transform: uppercase;
			letter-spacing: 0.08em;
			color: #6b7c6e;
			background: rgba(127, 182, 133, 0.08);
		}

		.summary-comparison-table tbody tr:last-child td {
			border-bottom: none;
		}

		.summary-metric-name {
			font-weight: 700;
			color: #2d3d30;
		}

		.summary-predicted-value,
		.summary-difference-value,
		.summary-accuracy-value {
			font-weight: 700;
		}

		.summary-actual-input {
			width: 100%;
			min-width: 150px;
			padding: 9px 11px;
			border-radius: 10px;
			border: 1px solid rgba(127, 182, 133, 0.24);
			background: #ffffff;
			font-weight: 700;
			color: #2d3d30;
		}

		.summary-actual-input:focus {
			outline: none;
			border-color: rgba(31, 139, 63, 0.45);
			box-shadow: 0 0 0 3px rgba(31, 139, 63, 0.12);
		}

		.summary-analysis-card,
		.summary-thankyou-card {
			border-radius: 14px;
			border: 1px solid rgba(127, 182, 133, 0.18);
			background: linear-gradient(180deg, rgba(255, 255, 255, 0.86), rgba(242, 248, 239, 0.92));
			padding: 14px;
		}

		.summary-analysis-title,
		.summary-thanks-title {
			margin: 0 0 6px;
			font-size: 1rem;
			font-weight: 800;
			color: #24442c;
		}

		.summary-analysis-copy,
		.summary-thanks-copy {
			margin: 0;
			font-size: 0.9rem;
			line-height: 1.55;
			color: #5a6b5d;
		}

		.summary-analysis-grid {
			display: grid;
			grid-template-columns: repeat(3, minmax(0, 1fr));
			gap: 10px;
			margin-top: 12px;
		}

		.summary-analysis-stat {
			padding: 10px 12px;
			border-radius: 12px;
			background: rgba(255, 255, 255, 0.78);
			border: 1px solid rgba(127, 182, 133, 0.16);
		}

		.summary-analysis-stat-label {
			display: block;
			font-size: 0.72rem;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			color: #6b7c6e;
			margin-bottom: 4px;
		}

		.summary-analysis-stat-value {
			font-size: 0.98rem;
			font-weight: 800;
			color: #24442c;
		}

		.summary-step-footer {
			display: flex;
			justify-content: space-between;
			gap: 10px;
			flex-wrap: wrap;
			margin-top: 14px;
			padding-top: 12px;
			border-top: 1px solid rgba(127, 182, 133, 0.16);
		}

		.summary-footer-left,
		.summary-footer-right {
			display: flex;
			gap: 10px;
			flex-wrap: wrap;
		}

		.summary-nav-btn,
		.summary-new-cycle-btn {
			border-radius: 12px;
			font-weight: 700;
		}

		.summary-nav-btn {
			background: rgba(255, 255, 255, 0.9);
			border: 1px solid rgba(127, 182, 133, 0.22);
			color: #36513a;
		}

		.summary-nav-btn:hover,
		.summary-nav-btn:focus-visible {
			border-color: rgba(31, 139, 63, 0.3);
			color: #1f8b3f;
		}

		.summary-nav-btn:disabled {
			opacity: 0.45;
			cursor: not-allowed;
		}

		.summary-new-cycle-btn {
			background: linear-gradient(135deg, #1f8b3f, #3db15d);
			border: none;
			color: #ffffff;
			box-shadow: 0 8px 20px rgba(31, 139, 63, 0.22);
		}

		.summary-new-cycle-btn:hover,
		.summary-new-cycle-btn:focus-visible {
			background: linear-gradient(135deg, #197433, #2d9f4e);
			color: #ffffff;
		}

		.summary-thankyou-card {
			text-align: center;
			padding: 18px 16px;
			display: grid;
			justify-items: center;
			gap: 10px;
		}

		.summary-thankyou-copy-wrap {
			max-width: 520px;
		}

		.summary-thankyou-list {
			display: grid;
			grid-template-columns: repeat(3, minmax(0, 1fr));
			gap: 10px;
			width: 100%;
			margin-top: 4px;
		}

		.summary-thankyou-item {
			padding: 12px 14px;
			border-radius: 14px;
			background: rgba(255, 255, 255, 0.84);
			border: 1px solid rgba(127, 182, 133, 0.16);
			font-size: 0.84rem;
			font-weight: 700;
			color: #445447;
		}

		.summary-thankyou-item strong {
			display: block;
			font-size: 1rem;
			color: #1f8b3f;
			margin-top: 4px;
		}

		.summary-thankyou-icon {
			width: 64px;
			height: 64px;
			border-radius: 18px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			background: rgba(31, 139, 63, 0.12);
			color: #1f8b3f;
			margin-bottom: 10px;
		}

		.summary-thankyou-icon svg {
			width: 30px;
			height: 30px;
			fill: currentColor;
		}

		.summary-locked {
			border: 1px dashed rgba(127, 182, 133, 0.42);
			background: linear-gradient(145deg, rgba(255, 255, 255, 0.9), rgba(248, 252, 246, 0.8));
			border-radius: 12px;
			padding: 12px;
			font-size: 0.87rem;
			color: #556558;
		}

		.summary-locked-head {
			display: flex;
			align-items: center;
			gap: 8px;
			font-weight: 700;
			color: #3f4f43;
			margin-bottom: 9px;
		}

		.summary-locked-head svg {
			width: 16px;
			height: 16px;
			fill: #d97706;
			flex-shrink: 0;
		}

		.summary-locked-note {
			margin: 0 0 10px;
			font-size: 0.82rem;
			line-height: 1.45;
			color: #5c6e60;
		}

		.summary-locked-grid {
			display: grid;
			gap: 7px;
		}

		.summary-locked-row {
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 10px;
			padding: 8px 10px;
			border-radius: 10px;
			background: rgba(255, 255, 255, 0.78);
			border: 1px solid rgba(127, 182, 133, 0.2);
		}

		.summary-locked-label {
			font-size: 0.8rem;
			color: #65766a;
		}

		.summary-locked-value {
			font-size: 0.84rem;
			font-weight: 700;
			color: #2d3d30;
			text-align: right;
		}

		.summary-ready {
			display: grid;
			gap: 12px;
		}

		.summary-section-title {
			margin-top: 6px;
			font-size: 0.72rem;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			color: #6b7c6e;
			font-weight: 700;
		}

		.summary-row {
			display: flex;
			justify-content: space-between;
			gap: 12px;
			padding: 9px 10px;
			border-radius: 10px;
			background: rgba(255, 255, 255, 0.72);
			border: 1px solid rgba(127, 182, 133, 0.2);
		}

		.summary-row-label {
			font-size: 0.84rem;
			color: var(--muted-foreground);
		}

		.summary-row-value {
			font-size: 0.87rem;
			font-weight: 700;
			color: #2d3d30;
			text-align: right;
		}

		.summary-actions {
			margin-top: 12px;
			display: flex;
			justify-content: flex-end;
		}

		.summary-close-btn {
			border-radius: 12px;
			font-weight: 700;
			background: #219150;
			border: none;
			color: #ffffff;
			box-shadow: 0 4px 12px rgba(33, 145, 80, 0.25);
		}

		.summary-close-btn:hover,
		.summary-close-btn:focus-visible {
			background: #1d7f46;
			color: #ffffff;
		}

		.heat-prompt-modal {
			position: fixed;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%) scale(0.98);
			background: linear-gradient(160deg, #ffffff 0%, #f6fcf3 100%);
			border: 1px solid rgba(127, 182, 133, 0.26);
			border-radius: 16px;
			width: min(92vw, 460px);
			box-shadow: var(--shadow-lg);
			z-index: 80;
			padding: 18px;
			opacity: 0;
			pointer-events: none;
			transition: all 0.2s ease;
		}

		.heat-prompt-modal.show {
			opacity: 1;
			pointer-events: auto;
			transform: translate(-50%, -50%) scale(1);
		}

		.heat-prompt-head {
			display: flex;
			align-items: flex-start;
			justify-content: space-between;
			gap: 12px;
			padding-bottom: 12px;
			margin-bottom: 12px;
			border-bottom: 1px solid rgba(127, 182, 133, 0.18);
		}

		.heat-prompt-title-wrap {
			min-width: 0;
		}

		.heat-prompt-eyebrow {
			display: inline-flex;
			align-items: center;
			padding: 4px 10px;
			border-radius: 999px;
			background: rgba(255, 197, 91, 0.18);
			color: #8a5a00;
			font-size: 0.7rem;
			font-weight: 800;
			letter-spacing: 0.04em;
			text-transform: uppercase;
			margin-bottom: 6px;
		}

		.heat-prompt-title {
			margin: 0;
			font-size: 1.08rem;
			font-weight: 800;
			color: #27412b;
		}

		.heat-prompt-temp {
			flex-shrink: 0;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			min-width: 72px;
			padding: 9px 12px;
			border-radius: 14px;
			background: linear-gradient(160deg, #7fb685, #6cae74);
			color: #fff;
			font-size: 1.05rem;
			font-weight: 800;
			box-shadow: 0 10px 18px rgba(82, 135, 91, 0.22);
		}

		.heat-prompt-desc {
			margin: 0 0 14px;
			color: var(--muted-foreground);
			font-size: 0.93rem;
			line-height: 1.5;
		}

		.heat-prompt-note {
			margin: 10px 0 0;
			font-size: 0.8rem;
			color: #5f7062;
		}

		.heat-prompt-actions {
			display: flex;
			gap: 10px;
			justify-content: flex-end;
			flex-wrap: wrap;
		}

		.heat-prompt-actions .btn {
			min-width: 120px;
		}

		.profile-modal {
			position: fixed;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%) scale(0.98);
			background: linear-gradient(160deg, #ffffff 0%, #f8fcf6 60%, #fffdf3 100%);
			border: 1px solid rgba(127, 182, 133, 0.26);
			border-radius: 18px;
			width: min(94vw, 780px);
			max-height: 90vh;
			overflow: auto;
			box-shadow: var(--shadow-lg);
			z-index: 80;
			padding: 20px;
			opacity: 0;
			pointer-events: none;
			transition: all 0.2s ease;
		}

		.profile-modal.show {
			opacity: 1;
			pointer-events: auto;
			transform: translate(-50%, -50%) scale(1);
		}

		.profile-modal-head {
			display: flex;
			align-items: flex-start;
			justify-content: space-between;
			gap: 12px;
			padding: 14px;
			margin-bottom: 12px;
			border: 1px solid rgba(127, 182, 133, 0.24);
			border-radius: 14px;
			background: linear-gradient(135deg, rgba(127, 182, 133, 0.16), rgba(255, 229, 153, 0.16));
		}

		.profile-head-intro {
			display: flex;
			align-items: center;
			gap: 12px;
			min-width: 0;
		}

		.profile-head-badge {
			width: 44px;
			height: 44px;
			border-radius: 12px;
			background: linear-gradient(160deg, #6cae74, #7fb685);
			color: #fff;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			font-size: 1rem;
			font-weight: 800;
			letter-spacing: 0.06em;
			box-shadow: 0 10px 18px rgba(82, 135, 91, 0.28);
			flex-shrink: 0;
		}

		.profile-modal-title {
			margin: 0;
			font-size: 1.24rem;
			font-weight: 700;
		}

		.profile-modal-sub {
			margin: 4px 0 0;
			font-size: 0.9rem;
			color: var(--muted-foreground);
		}

		.profile-head-role {
			display: inline-flex;
			align-items: center;
			margin-top: 7px;
			padding: 4px 10px;
			border-radius: 999px;
			font-size: 0.72rem;
			font-weight: 700;
			color: #2f5f34;
			border: 1px solid rgba(127, 182, 133, 0.42);
			background: rgba(255, 255, 255, 0.75);
		}

		.profile-close-btn {
			border-radius: 12px;
			font-weight: 700;
			background: #219150;
			border: none;
			color: #ffffff;
			box-shadow: 0 4px 12px rgba(33, 145, 80, 0.25);
		}

		.profile-close-btn:hover,
		.profile-close-btn:focus-visible {
			background: #1d7f46;
			color: #ffffff;
		}

		.profile-tabs {
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 8px;
			padding: 10px;
			margin-bottom: 12px;
			border: 1px solid rgba(127, 182, 133, 0.2);
			border-radius: 12px;
			background: rgba(255, 255, 255, 0.66);
		}

		.profile-tab-btn {
			border: 1px solid rgba(127, 182, 133, 0.26);
			background: rgba(127, 182, 133, 0.08);
			border-radius: 10px;
			padding: 10px 12px;
			font-size: 0.9rem;
			font-weight: 700;
			color: #3d5440;
			cursor: pointer;
			transition: all 0.18s ease;
		}

		.profile-tab-btn.active {
			background: #7fb685;
			border-color: #7fb685;
			color: #fff;
		}

		.profile-tab-pane {
			display: none;
		}

		.profile-tab-pane.active {
			display: block;
		}

		.profile-overview-card {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 12px;
			padding: 12px 14px;
			border-radius: 12px;
			border: 1px solid rgba(127, 182, 133, 0.26);
			background: linear-gradient(160deg, rgba(127, 182, 133, 0.12), rgba(255, 255, 255, 0.92));
			margin-bottom: 12px;
		}

		.profile-overview-name {
			margin: 0;
			font-size: 1rem;
			font-weight: 800;
			color: #2f4632;
		}

		.profile-overview-meta {
			margin: 2px 0 0;
			font-size: 0.8rem;
			font-weight: 600;
			color: #5e7362;
		}

		.profile-overview-state {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 5px 11px;
			border-radius: 999px;
			font-size: 0.74rem;
			font-weight: 700;
			white-space: nowrap;
			border: 1px solid rgba(127, 182, 133, 0.34);
			background: rgba(214, 241, 220, 0.85);
			color: #2f6b3d;
		}

		.profile-overview-state.is-empty {
			border-color: rgba(220, 53, 69, 0.28);
			background: rgba(255, 239, 241, 0.86);
			color: #8c3540;
		}

		.profile-grid {
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 12px;
		}

		.profile-card {
			border: 1px solid rgba(127, 182, 133, 0.2);
			border-radius: 12px;
			padding: 15px;
			background: linear-gradient(140deg, rgba(127, 182, 133, 0.08), rgba(255, 255, 255, 1));
			box-shadow: 0 10px 20px rgba(49, 83, 55, 0.08);
		}

		.profile-card-title {
			margin: 0 0 10px;
			font-size: 0.95rem;
			font-weight: 700;
			color: #2d4431;
		}

		.profile-item {
			display: grid;
			grid-template-columns: 132px 1fr;
			gap: 10px;
			font-size: 0.86rem;
			padding: 8px 0;
			border-bottom: 1px dashed rgba(127, 182, 133, 0.24);
		}

		.profile-item:last-child {
			border-bottom: none;
		}

		.profile-credential-actions {
			padding-top: 10px;
		}

		.profile-pass-shortcut-btn {
			font-weight: 700;
			border-color: rgba(74, 116, 81, 0.45);
			color: #446349;
			background: rgba(255, 255, 255, 0.84);
		}

		.profile-pass-shortcut-btn:hover {
			border-color: rgba(74, 116, 81, 0.6);
			color: #35533a;
			background: #fff;
		}

		.profile-item-label {
			color: #59705d;
			font-weight: 600;
			font-size: 0.75rem;
			text-transform: uppercase;
			letter-spacing: 0.06em;
		}

		.profile-item-value {
			color: #2c3e2e;
			font-weight: 700;
			word-break: break-word;
		}

		.profile-role-pill,
		.profile-status-pill {
			display: inline-flex;
			align-items: center;
			padding: 3px 10px;
			border-radius: 999px;
			font-size: 0.76rem;
			font-weight: 700;
			border: 1px solid rgba(127, 182, 133, 0.34);
			background: rgba(242, 250, 238, 0.88);
			color: #2f6437;
		}

		.profile-status-pill.is-empty {
			border-color: rgba(220, 53, 69, 0.28);
			background: rgba(255, 239, 241, 0.85);
			color: #8b3540;
		}

		.profile-status-pill.is-ready {
			border-color: rgba(127, 182, 133, 0.4);
			background: rgba(218, 243, 223, 0.88);
			color: #2f6b3d;
		}

		.profile-account-card {
			grid-column: 1 / -1;
		}

		.account-settings-form {
			display: grid;
			gap: 12px;
		}

		.account-grid {
			display: grid;
			grid-template-columns: minmax(0, 1fr);
			gap: 10px 12px;
		}

		.account-form-label {
			font-size: 0.75rem;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.06em;
			color: #4f6552;
			margin-bottom: 5px;
		}

		.account-settings-form .form-control {
			border-color: rgba(127, 182, 133, 0.3);
			background: rgba(255, 255, 255, 0.96);
		}

		.account-settings-form .form-control:focus {
			border-color: rgba(127, 182, 133, 0.64);
			box-shadow: 0 0 0 0.22rem rgba(127, 182, 133, 0.18);
		}

		.account-note {
			margin: -2px 0 0;
			font-size: 0.75rem;
			font-weight: 600;
			color: #6a7f6e;
		}

		.account-pass-fields {
			display: grid;
			gap: 10px;
			padding: 12px;
			border: 1px solid rgba(127, 182, 133, 0.24);
			border-radius: 12px;
			background: linear-gradient(145deg, rgba(250, 254, 249, 0.96), rgba(241, 249, 239, 0.88));
		}

		.account-pass-grid {
			display: grid;
			grid-template-columns: minmax(0, 1fr);
			gap: 10px 12px;
		}

		.account-strength {
			display: none;
			padding: 8px 10px;
			border: 1px solid rgba(127, 182, 133, 0.26);
			border-radius: 9px;
			background: rgba(255, 255, 255, 0.9);
		}

		.account-strength.visible {
			display: block;
		}

		.account-strength-row {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 10px;
			font-size: 0.73rem;
			font-weight: 700;
			color: #5b7260;
		}

		.account-strength-label {
			font-weight: 800;
		}

		.account-strength-bar {
			margin-top: 6px;
			height: 8px;
			border-radius: 999px;
			background: #e8f2e9;
			overflow: hidden;
		}

		.account-strength-fill {
			width: 0;
			height: 100%;
			background: #ef4444;
			transition: width 0.2s ease, background 0.2s ease;
		}

		.account-input-error {
			border-color: #dc3545 !important;
			box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.15) !important;
		}

		.account-field-error {
			display: none;
			margin: 5px 0 0;
			font-size: 0.74rem;
			font-weight: 700;
			color: #dc3545;
		}

		.account-field-error.visible {
			display: block;
		}

		.account-form-actions {
			display: flex;
			justify-content: flex-end;
			flex-wrap: wrap;
			gap: 8px;
		}

		.account-cancel-btn {
			font-weight: 700;
		}

		.account-status-note {
			min-height: 40px;
			display: flex;
			align-items: center;
			padding: 8px 10px;
			border: 1px dashed rgba(127, 182, 133, 0.28);
			border-radius: 10px;
			background: rgba(239, 248, 237, 0.82);
			font-size: 0.82rem;
			font-weight: 600;
			color: #4a7150;
			transition: border-color 0.16s ease, background 0.16s ease, color 0.16s ease;
		}

		.account-status-note.is-error {
			border-color: rgba(220, 53, 69, 0.34);
			background: rgba(255, 238, 241, 0.84);
			color: #8d2733;
		}

		.account-status-note.is-success {
			border-color: rgba(127, 182, 133, 0.4);
			background: rgba(220, 243, 224, 0.85);
			color: #2e6a3b;
		}

		.account-save-btn {
			font-weight: 700;
			background: linear-gradient(160deg, #63a66b, #4f9358);
			border: 1px solid #4f9358;
			box-shadow: 0 10px 18px rgba(79, 147, 88, 0.2);
		}

		.account-save-btn:hover {
			background: linear-gradient(160deg, #589b61, #46864f);
			border-color: #46864f;
		}

		.void-start {
			border: 1px solid rgba(220, 53, 69, 0.28);
			background: linear-gradient(150deg, rgba(220, 53, 69, 0.14), rgba(255, 245, 245, 0.95));
			border-radius: 14px;
			padding: 16px;
			margin-bottom: 12px;
			box-shadow: 0 12px 24px rgba(164, 49, 63, 0.12);
		}

		.void-start-head {
			display: flex;
			align-items: flex-start;
			gap: 10px;
			margin-bottom: 8px;
		}

		.void-start-icon {
			width: 30px;
			height: 30px;
			border-radius: 10px;
			background: rgba(220, 53, 69, 0.18);
			color: #8d2733;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			flex-shrink: 0;
		}

		.void-start-icon svg {
			width: 16px;
			height: 16px;
			fill: currentColor;
		}

		.void-start h4 {
			margin: 0;
			font-size: 1rem;
			font-weight: 700;
			color: #8d2733;
		}

		.void-start p {
			margin: 0;
			font-size: 0.9rem;
			line-height: 1.5;
			color: #73464b;
		}

		.void-impact-list {
			list-style: none;
			margin: 12px 0 0;
			padding: 0;
			display: grid;
			gap: 7px;
		}

		.void-impact-item {
			display: flex;
			align-items: center;
			gap: 8px;
			font-size: 0.82rem;
			font-weight: 600;
			color: #7a4b50;
		}

		.void-impact-dot {
			width: 8px;
			height: 8px;
			border-radius: 999px;
			background: rgba(220, 53, 69, 0.65);
			box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.15);
			flex-shrink: 0;
		}

		.void-actions {
			display: flex;
			flex-wrap: wrap;
			gap: 8px;
			margin-top: 14px;
		}

		.void-btn-danger {
			font-weight: 700;
			background: linear-gradient(160deg, #d13d50, #b92f43);
			border: 1px solid #b92f43;
			box-shadow: 0 10px 18px rgba(180, 48, 66, 0.22);
		}

		.void-btn-danger:hover {
			background: linear-gradient(160deg, #c23649, #a72a3d);
			border-color: #a72a3d;
		}

		.void-btn-secondary {
			font-weight: 700;
			border-color: rgba(113, 128, 115, 0.45);
			color: #4f6353;
			background: rgba(255, 255, 255, 0.82);
		}

		.void-btn-secondary:hover {
			background: #fff;
			color: #3e5343;
			border-color: rgba(99, 114, 101, 0.55);
		}

		.void-form {
			border: 1px solid rgba(127, 182, 133, 0.24);
			border-radius: 14px;
			padding: 16px;
			background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(246, 251, 244, 0.95));
			box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.5);
		}

		.void-form-head {
			margin-bottom: 12px;
			padding-bottom: 11px;
			border-bottom: 1px dashed rgba(127, 182, 133, 0.3);
		}

		.void-form-title {
			margin: 0;
			font-size: 0.96rem;
			font-weight: 800;
			color: #304834;
		}

		.void-form-sub {
			margin: 3px 0 0;
			font-size: 0.79rem;
			color: #5f7463;
		}

		.void-form .form-label {
			font-size: 0.78rem;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.06em;
			color: #49614e;
			margin-bottom: 5px;
		}

		.void-form .form-select,
		.void-form .form-control {
			border-color: rgba(127, 182, 133, 0.3);
			background: rgba(255, 255, 255, 0.95);
		}

		.void-form .form-select:focus,
		.void-form .form-control:focus {
			border-color: rgba(127, 182, 133, 0.66);
			box-shadow: 0 0 0 0.22rem rgba(127, 182, 133, 0.18);
		}

		.void-form .form-check-label {
			font-size: 0.83rem;
			font-weight: 600;
			color: #516856;
		}

		.void-confirm-label {
			display: inline-flex;
			align-items: center;
			gap: 6px;
		}

		.void-confirm-token {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 1px 7px;
			border-radius: 8px;
			border: 1px solid rgba(220, 53, 69, 0.38);
			background: rgba(255, 237, 240, 0.95);
			color: #9a2c3a;
			font-size: 0.72rem;
			font-weight: 800;
			letter-spacing: 0.06em;
		}

		.void-status-note {
			min-height: 40px;
			display: flex;
			align-items: center;
			padding: 8px 10px;
			border: 1px dashed rgba(127, 182, 133, 0.26);
			border-radius: 10px;
			background: rgba(241, 249, 239, 0.72);
			font-size: 0.82rem;
			font-weight: 600;
			color: #507957;
			transition: border-color 0.16s ease, background 0.16s ease, color 0.16s ease;
		}

		.void-status-note.is-error {
			border-color: rgba(220, 53, 69, 0.35);
			background: rgba(255, 238, 241, 0.82);
			color: #8d2733;
		}

		.void-status-note.is-success {
			border-color: rgba(127, 182, 133, 0.4);
			background: rgba(220, 243, 224, 0.85);
			color: #2f6c3b;
		}

		/* Weather Widget Styles */
		.weather-widget {
			display: flex;
			flex-direction: column;
			gap: 15px;
			padding: 5px 0;
		}
		.weather-main {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 15px;
			padding-bottom: 5px;
		}
		.weather-temp-wrap {
			display: flex;
			align-items: flex-start;
			gap: 4px;
		}
		.weather-temp {
			font-size: 3.2rem;
			font-weight: 800;
			line-height: 1;
			color: #1f2937;
		}
		.weather-unit {
			font-size: 1.2rem;
			font-weight: 700;
			color: #6b7280;
			margin-top: 6px;
		}
		.weather-info {
			text-align: right;
		}
		.weather-condition {
			font-size: 1.1rem;
			font-weight: 700;
			color: #374151;
			text-transform: capitalize;
		}
		.weather-location {
			font-size: 0.85rem;
			font-weight: 600;
			color: #6b7280;
			display: flex;
			align-items: center;
			justify-content: flex-end;
			gap: 4px;
		}
		.weather-grid {
			display: grid;
			grid-template-columns: repeat(3, 1fr);
			gap: 10px;
			background: rgba(127, 182, 133, 0.08);
			border-radius: 14px;
			padding: 12px;
		}
		.weather-stat {
			display: flex;
			flex-direction: column;
			align-items: center;
			gap: 4px;
		}
		.weather-stat-label {
			font-size: 0.7rem;
			font-weight: 700;
			color: #4b5563;
			text-transform: uppercase;
			letter-spacing: 0.05em;
		}
		.weather-stat-value {
			font-size: 0.9rem;
			font-weight: 700;
			color: #065f46;
		}
		.weather-forecast {
			display: flex;
			gap: 10px;
			padding-top: 5px;
			overflow-x: auto;
			padding-bottom: 8px;
			justify-content: center;
			scrollbar-width: none; /* Hide scrollbar Firefox */
		}
		.weather-forecast::-webkit-scrollbar {
			display: none; /* Hide scrollbar Chrome/Safari */
		}
		.forecast-day {
			display: flex;
			flex-direction: column;
			align-items: center;
			gap: 6px;
			min-width: 68px;
			flex-shrink: 0;
			padding: 10px 8px;
			border-radius: 12px;
			background: rgba(255, 255, 255, 0.6);
			border: 1px solid rgba(127, 182, 133, 0.1);
			transition: transform 0.2s ease;
		}
		.forecast-day:hover {
			transform: translateY(-2px);
			background: #fff;
		}
		.forecast-name {
			font-size: 0.75rem;
			font-weight: 700;
			color: #4b5563;
		}
		.forecast-temp {
			font-size: 0.85rem;
			font-weight: 700;
			color: #1f2937;
		}
		.forecast-rain {
			font-size: 0.65rem;
			font-weight: 800;
			color: #2563eb;
			display: flex;
			align-items: center;
			gap: 2px;
		}
		.weather-icon-lg {
			width: 64px;
			height: 64px;
		}
		.weather-icon-sm {
			width: 24px;
			height: 24px;
		}

		/* Collapsible Weather Styles */
		.weather-collapse {
			max-height: 0;
			overflow: hidden;
			transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease, padding 0.3s ease;
			opacity: 0;
			padding: 0;
		}
		.weather-collapse.expanded {
			max-height: 500px;
			opacity: 1;
			padding-bottom: 10px;
		}
		.weather-toggle-btn {
			width: 32px;
			height: 32px;
			display: flex;
			align-items: center;
			justify-content: center;
			border-radius: 50%;
			background: rgba(127, 182, 133, 0.12);
			border: 1px solid rgba(127, 182, 133, 0.2);
			color: #4b5563;
			cursor: pointer;
			transition: all 0.25s ease;
		}
		.weather-toggle-btn:hover {
			background: rgba(127, 182, 133, 0.22);
			transform: scale(1.08);
		}
		.weather-toggle-btn svg {
			width: 18px;
			height: 18px;
			transition: transform 0.3s ease;
		}
		.weather-toggle-btn.is-active svg {
			transform: rotate(180deg);
		}

		/* Weather Icon Colors */
		.icon-sunny { color: #f59e0b !important; } /* Amber-500 */
		.icon-cloudy { color: #6b7280 !important; } /* Gray-500 */
		.icon-rainy { color: #3b82f6 !important; } /* Blue-500 */
		.icon-stormy { color: #4b5563 !important; } /* Gray-600 */
		.icon-partly { color: #f59e0b !important; } 

		.weather-icon-lg {
			width: 70px;
			height: 70px;
			object-fit: contain;
			filter: drop-shadow(0 4px 8px rgba(0,0,0,0.08));
			transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
		}
		.weather-icon-lg:hover {
			transform: scale(1.15) translateY(-5px);
		}
		.weather-icon-sm {
			width: 36px;
			height: 36px;
			object-fit: contain;
			filter: drop-shadow(0 2px 4px rgba(0,0,0,0.05));
		}

		@media (max-width: 991.98px) {
			.floating-btn {
				width: 50px;
				height: 50px;
			}

			.floating-right,
			.floating-left {
				bottom: 16px;
				top: auto;
				transform: none;
			}

			.floating-btn:hover {
				transform: scale(1.04);
			}

			.floating-right {
				right: 16px;
			}

			.floating-left {
				left: 16px;
			}

			.notif-panel-ui {
				top: 70px;
				right: 12px;
				width: min(94vw, 340px);
			}

			.profile-grid {
				grid-template-columns: minmax(0, 1fr);
			}

			.profile-modal-head {
				padding: 12px;
			}

			.profile-head-intro {
				align-items: flex-start;
			}

			.profile-overview-card {
				flex-direction: column;
				align-items: flex-start;
			}

			.profile-item {
				grid-template-columns: 1fr;
				gap: 4px;
			}

			.account-grid {
				grid-template-columns: minmax(0, 1fr);
			}

			.account-pass-grid {
				grid-template-columns: minmax(0, 1fr);
			}

			.profile-setup-banner {
				align-items: flex-start;
			}

			.profile-setup-banner-cta {
				white-space: normal;
				justify-content: flex-end;
				text-align: right;
			}

			.summary-modal {
				width: min(94vw, 640px);
				padding: 16px;
			}

			.summary-modal-head {
				flex-direction: column;
				align-items: flex-start;
				gap: 8px;
			}

			.summary-stepper {
				grid-template-columns: minmax(0, 1fr);
			}

			.summary-step-btn {
				padding: 10px 12px;
			}

			.summary-hero-top {
				flex-direction: column;
				align-items: flex-start;
			}

			.summary-hero-stats {
				grid-template-columns: repeat(2, minmax(0, 1fr));
			}

			.summary-card-grid {
				grid-template-columns: minmax(0, 1fr);
			}

			.summary-row,
			.summary-locked-row {
				flex-direction: column;
				align-items: flex-start;
			}

			.summary-row-value,
			.summary-locked-value {
				text-align: left;
			}

			.summary-thankyou-list {
				grid-template-columns: minmax(0, 1fr);
			}

		}
	</style>
</head>
<body>
	<div id="mainDashboardView" class="<?php echo $startInFeatureView ? "d-none" : ""; ?>">
		<header class="topbar">
			<div class="topbar-inner d-flex align-items-center justify-content-between gap-2 flex-wrap">
				<div class="topbar-left">
					<div class="user-identity-card">
						<div class="avatar-badge"><?php echo htmlspecialchars($displayInitials); ?></div>
						<div class="user-meta">
						<h2 class="user-name"><?php echo htmlspecialchars($displayName); ?></h2>
						<p class="user-handle"><?php echo htmlspecialchars($displayHandle); ?></p>
						</div>
					</div>
				</div>

				<div class="d-flex align-items-center gap-2">
					<button class="icon-btn notif-btn <?php echo $todayTaskNotificationCount > 0 ? "has-alert" : ""; ?>" title="<?php echo htmlspecialchars($notificationTitleText); ?>" type="button" id="notifBtnMain" aria-controls="notifPanel" aria-expanded="false">
						<svg viewBox="0 0 24 24"><path d="M12 2a6 6 0 0 0-6 6v3.1c0 .7-.2 1.4-.6 2L4 16h16l-1.4-2.9c-.4-.6-.6-1.3-.6-2V8a6 6 0 0 0-6-6zm0 20a3 3 0 0 0 2.8-2H9.2A3 3 0 0 0 12 22z"></path></svg>
						<?php if ($todayTaskNotificationCount > 0) { ?>
							<span class="notif-badge"><?php echo htmlspecialchars($notificationBadgeLabel); ?></span>
						<?php } ?>
					</button>
					<button class="icon-btn" title="Harvest Summary" type="button" id="summaryBtnMain">
						<svg viewBox="0 0 24 24"><path d="M4 19h16v2H4v-2zm1-2 4.5-6 3.5 4L18 8l1.5 1-6.2 8.6-3.6-4L6.6 18H5z"></path></svg>
					</button>
					<button class="icon-btn" title="Profile" type="button" id="profileBtnMain">
						<svg viewBox="0 0 24 24"><path d="M12 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0 2c4.4 0 8 2.2 8 5v2H4v-2c0-2.8 3.6-5 8-5z"></path></svg>
					</button>
					<button class="icon-btn logout" title="Logout" type="button" id="logoutBtnMain">
						<svg viewBox="0 0 24 24"><path d="M16 17v-3h-6v-4h6V7l5 5-5 5zM3 5h8V3H3a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8v-2H3V5z"></path></svg>
					</button>
				</div>
			</div>
		</header>

		<main class="dashboard-wrap">
			<div class="profile-setup-banner-wrap d-none" id="profileSetupNoteWrap">
				<button class="profile-setup-banner" type="button" id="profileSetupNote" aria-label="Set up corn planting profile">
					<span class="profile-setup-banner-main">
						<span class="profile-setup-banner-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M12 2 1 21h22L12 2zm1 15h-2v2h2v-2zm0-7h-2v5h2v-5z"></path></svg>
						</span>
						<span class="profile-setup-banner-text">Create your Corn Planting Profile first to view forecast, growth progress, and task analytics.</span>
					</span>
					<span class="profile-setup-banner-cta">
						Set up now
						<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7-1.4-1.4 5.6-5.6-5.6-5.6z"></path></svg>
					</span>
				</button>
			</div>

			<div class="row g-2 g-md-4 mb-4">
				<div class="col-6 col-md-6 col-xl-3">
					<article class="stat-card p-3 p-md-4 h-100" style="background: linear-gradient(145deg, rgba(34,197,94,0.1), rgba(34,197,94,0.03));">
						<div class="blur-orb" style="background: rgba(34,197,94,0.2);"></div>
						<div class="d-flex align-items-start justify-content-between mb-3">
							<span class="stat-icon" style="background: rgba(34,197,94,0.15); color: #1f8b3f;">
								<svg viewBox="0 0 24 24"><path d="M9 2v2h6V2h2v2h3v18H4V4h3V2h2zm9 8H6v10h12V10z"></path></svg>
							</span>
							<span class="status-badge">Forecast</span>
						</div>
						<p class="metric-title">Estimated Harvest Date</p>
						<p class="metric-value metric-value-small" id="estimatedHarvestDateValue">--</p>
						<p class="income-copy">Estimated Income: <span id="estimatedIncomeValue" data-server-value="<?php echo htmlspecialchars($dashboardEstimatedIncomeLabel, ENT_QUOTES); ?>"><?php echo htmlspecialchars($dashboardEstimatedIncomeLabel); ?></span></p>
					</article>
				</div>

				<div class="col-6 col-md-6 col-xl-3">
					<article class="stat-card p-3 p-md-4 h-100" style="background: linear-gradient(145deg, rgba(59,130,246,0.1), rgba(59,130,246,0.03));">
						<div class="blur-orb" style="background: rgba(59,130,246,0.2);"></div>
						<div class="d-flex align-items-start justify-content-between mb-3">
							<span class="stat-icon" style="background: rgba(59,130,246,0.15); color: #2563eb;">
								<svg viewBox="0 0 24 24"><path d="M3 17h2v3H3v-3zm4-7h2v10H7V10zm4-4h2v14h-2V6zm4 6h2v8h-2v-8zm4-8h2v16h-2V4z"></path></svg>
							</span>
							<span class="status-badge" id="growthBadge">--</span>
						</div>
						<p class="metric-title">Growth Progress</p>
						<p class="metric-value"><span id="growthProgressValue">--</span>%</p>
						<p class="muted-copy" id="growthProgressSubtext">Above average for season</p>
					</article>
				</div>

				<div class="col-6 col-md-6 col-xl-3">
					<article class="stat-card p-3 p-md-4 h-100" style="background: linear-gradient(145deg, rgba(245,158,11,0.1), rgba(245,158,11,0.03));">
						<div class="blur-orb" style="background: rgba(245,158,11,0.22);"></div>
						<div class="d-flex align-items-start justify-content-between mb-3">
							<span class="stat-icon" style="background: rgba(245,158,11,0.16); color: #d97706;">
								<svg viewBox="0 0 24 24"><path d="M9 2v2h6V2h2v2h3v18H4V4h3V2h2zm9 8H6v10h12V10z"></path></svg>
							</span>
							<span class="status-badge">Tasks</span>
						</div>
							<p class="metric-title">All Tasks</p>
						<p class="metric-value" id="tasksValue">--</p>
						<p class="muted-copy" id="tasksSummaryText"><span id="completedValue">--</span> completed, <span id="pendingValue">--</span> pending</p>
						<p class="muted-copy d-none" id="tasksEmptyHint">Create your planting profile first</p>
					</article>
				</div>

				<div class="col-6 col-md-6 col-xl-3">
					<article class="stat-card p-3 p-md-4 h-100" style="background: linear-gradient(145deg, rgba(249,115,22,0.1), rgba(249,115,22,0.03));">
						<div class="blur-orb" style="background: rgba(249,115,22,0.2);"></div>
						<div class="d-flex align-items-start justify-content-between mb-3">
							<span class="stat-icon" style="background: rgba(249,115,22,0.16); color: #ea580c;">
								<svg viewBox="0 0 24 24"><path d="M12 1 3 5v6c0 5 3.8 9.7 9 11 5.2-1.3 9-6 9-11V5l-9-4zm1 12h3l-4 6v-4H8l4-6v4z"></path></svg>
							</span>
							<span class="status-badge" id="harvestStatus">--</span>
						</div>
						<p class="metric-title">Until Harvest</p>
						<p class="metric-value" style="color: #ea580c;"><span id="daysUntilHarvestValue">--</span></p>
						<p class="muted-copy">days remaining</p>
					</article>
				</div>
			</div>

			<div class="row g-4 align-items-start">
				<div class="col-12 col-lg-6 col-xl-5">
					<div class="row g-4">
						<div class="col-12">
							<section class="panel h-100">
								<div class="panel-header">
									<h3 class="panel-title mb-0">Corn Growth Stages</h3>
									<span class="pill">Interactive</span>
								</div>

								<div class="growth-box mb-3">
									<div class="plant-visual" id="plantVisual">
										<div class="plant-stem" id="plantStem"></div>
										<div class="leaf l1" id="leaf1"></div>
										<div class="leaf l2" id="leaf2"></div>
										<div class="leaf l3" id="leaf3"></div>
										<div class="tassel" id="tassel"></div>
										<div class="cob" id="cob"></div>
										<div class="harvest-stack" id="harvestStack"><span></span><span></span></div>
										<div class="plant-base"></div>
									</div>
								</div>

								<div class="text-center mb-3">
									<span class="crop-tag" id="cropTagLabel">
										<svg viewBox="0 0 24 24"><path d="M8 18c0-4 2-7 4-9 2 2 4 5 4 9H8zm4-16c-2 2-5 6-5 11h10c0-5-3-9-5-11z"></path></svg>
										<span id="cropTagText">Sweet Corn (Hybrid) - Golden Bantam F1</span>
									</span>
								</div>

								<div class="stage-meta mb-3">
									<span class="day-pill">Day <span id="currentDayLabel">30</span></span>
									<h4 class="stage-name" id="stageName">Stage 2 - Germination</h4>
									<p class="stage-days" id="stageDays">Day 15-50</p>
									<p class="stage-date" id="stageDateLabel">--</p>
								</div>

								<div class="mb-3">
									<div class="range-label">Adjust Growth Day (Demo)</div>
									<input class="stage-range" type="range" id="growthDayRange" min="1" max="95" value="30">
								</div>

								<div>
									<div class="overall-label">Overall Progress</div>
									<div class="overall-track" id="overallTrack">
										<span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
									</div>
								</div>
							</section>
						</div>

					</div>
				</div>

				<div class="col-12 col-lg-6 col-xl-7">
					<div class="row g-4">
						<div class="col-12 mb-4">
							<section class="panel h-100">
								<div class="panel-header" style="flex-wrap: nowrap;">
									<div style="flex: 1; min-width: 0;">
										<h3 class="panel-title text-truncate">Real-Time Weather</h3>
										<p class="panel-subtitle text-truncate">
											<span id="weatherLocationName">Calatagan, Batangas</span>
											<span style="margin: 0 5px; opacity: 0.5;">•</span>
											<span id="weatherCurrentTime" style="font-weight: 700; color: #495a4c;">--:-- --</span>
										</p>
									</div>
									<button class="weather-toggle-btn" id="toggleWeatherBtn" title="Show/Hide Details" type="button">
										<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
											<path d="M6 9l6 6 6-6"></path>
										</svg>
									</button>
								</div>
								
								<div class="weather-widget">
									<!-- Always Visible Section (Stats & Big Temp) -->
									<div class="weather-main">
										<div class="weather-temp-wrap">
											<span class="weather-temp" id="currentTemp">--</span>
											<span class="weather-unit">°C</span>
										</div>
										<div class="weather-info">
											<div id="weatherMainIconWrap">
												<svg class="weather-icon-lg text-warning" viewBox="0 0 24 24" fill="currentColor">
													<circle cx="12" cy="12" r="5"></circle>
													<path d="M12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"></path>
												</svg>
											</div>
												<div class="weather-condition" id="weatherCondition">--</div>
											</div>
										</div>
									</div>

									<div class="weather-grid pb-2">
										<div class="weather-stat">
											<span class="weather-stat-label">Humidity</span>
											<div class="d-flex align-items-center gap-1">
												<svg style="width:12px;height:12px;color:#3b82f6;" viewBox="0 0 24 24" fill="currentColor"><path d="M12 21c-3.3 0-6-2.7-6-6 0-3.3 6-12 6-12s6 8.7 6 12c0 3.3-2.7 6-6 6z"></path></svg>
												<span class="weather-stat-value" id="weatherHumidity">--%</span>
											</div>
										</div>
										<div class="weather-stat">
											<span class="weather-stat-label">Wind</span>
											<div class="d-flex align-items-center gap-1">
												<svg style="width:14px;height:14px;color:#64748b;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2M12 12H2"></path></svg>
												<span class="weather-stat-value" id="weatherWind">-- km/h</span>
											</div>
										</div>
										<div class="weather-stat">
											<span class="weather-stat-label">UV Index</span>
											<div class="d-flex align-items-center gap-1">
												<svg style="width:14px;height:14px;color:#f59e0b;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32l1.41 1.41M2 12h2m16 0h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"></path></svg>
												<span class="weather-stat-value" id="weatherUV">--</span>
											</div>
										</div>
									</div>

									<!-- Collapsible Section (7-Day Forecast) -->
									<div id="weatherDetailsCollapse" class="weather-collapse">
										<div style="padding-top: 10px; border-top: 1px dashed rgba(127,182,133,0.2); margin-top: 5px;">
											<div class="weather-forecast" id="weatherForecastRow">
												<!-- Forecast items will be injected here -->
											</div>
										</div>
									</div>
									
									<div class="text-center mt-2 d-flex justify-content-between align-items-center px-1">
										<p class="panel-subtitle" id="weatherUpdateTime" style="font-size: 0.68rem; margin: 0;">Syncing...</p>
										<div class="d-flex align-items-center gap-2">
											<span style="font-size: 0.65rem; color: #9ca3af;">via Open-Meteo</span>
											<span style="font-size: 0.65rem; color: #7fb685; font-weight: 700;">LIVE</span>
										</div>
									</div>
								</div>
							</section>
						</div>

						<div class="col-12 mb-4">
							<section class="panel h-100">
								<div class="panel-header">
									<div>
										<h3 class="panel-title">Income Forecast Chart</h3>
										<p class="panel-subtitle">Estimated income, costs, and net profit</p>
									</div>
									<span class="pill">This Season</span>
								</div>
								<div class="chart-wrap"><canvas id="incomeForecastChart"></canvas></div>
							</section>
						</div>
					</div>
				</div>

				<div class="col-12">
					<section class="panel h-100">
						<div class="panel-header">
							<div>
								<h3 class="panel-title">Market Price Trend</h3>
								<p class="panel-subtitle">Price per kilo over time based on latest market update</p>
							</div>
							<span class="pill">Market</span>
						</div>
						<div class="chart-wrap moisture-extended market-chart-scroll" id="marketChartScrollWrap"><div class="market-chart-inner" id="marketChartInner"><canvas id="marketPriceTrendChart"></canvas></div></div>
					</section>
				</div>
			</div>
		</main>

		<button class="floating-btn floating-right" id="goToFeatures" aria-label="Go to Feature Dashboard" title="Go to Feature Dashboard">
			<svg viewBox="0 0 24 24"><path d="m9 5 7 7-7 7-1.5-1.5 5.5-5.5-5.5-5.5z"></path></svg>
		</button>
	</div>

	<div id="featureDashboardView" class="<?php echo $startInFeatureView ? "" : "d-none"; ?>">
		<header class="topbar">
			<div class="topbar-inner d-flex align-items-center justify-content-between gap-2 flex-wrap">
				<div class="topbar-left">
					<div class="user-identity-card">
						<div class="avatar-badge"><?php echo htmlspecialchars($displayInitials); ?></div>
						<div class="user-meta">
						<h2 class="user-name"><?php echo htmlspecialchars($displayName); ?></h2>
						<p class="user-handle"><?php echo htmlspecialchars($displayHandle); ?></p>
						</div>
					</div>
				</div>

				<div class="d-flex align-items-center gap-2">
					<button class="icon-btn notif-btn <?php echo $todayTaskNotificationCount > 0 ? "has-alert" : ""; ?>" title="<?php echo htmlspecialchars($notificationTitleText); ?>" type="button" id="notifBtnFeature" aria-controls="notifPanel" aria-expanded="false">
						<svg viewBox="0 0 24 24"><path d="M12 2a6 6 0 0 0-6 6v3.1c0 .7-.2 1.4-.6 2L4 16h16l-1.4-2.9c-.4-.6-.6-1.3-.6-2V8a6 6 0 0 0-6-6zm0 20a3 3 0 0 0 2.8-2H9.2A3 3 0 0 0 12 22z"></path></svg>
						<?php if ($todayTaskNotificationCount > 0) { ?>
							<span class="notif-badge"><?php echo htmlspecialchars($notificationBadgeLabel); ?></span>
						<?php } ?>
					</button>
					<button class="icon-btn" title="Harvest Summary" type="button" id="summaryBtnFeature">
						<svg viewBox="0 0 24 24"><path d="M4 19h16v2H4v-2zm1-2 4.5-6 3.5 4L18 8l1.5 1-6.2 8.6-3.6-4L6.6 18H5z"></path></svg>
					</button>
					<button class="icon-btn" title="Profile" type="button" id="profileBtnFeature">
						<svg viewBox="0 0 24 24"><path d="M12 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0 2c4.4 0 8 2.2 8 5v2H4v-2c0-2.8 3.6-5 8-5z"></path></svg>
					</button>
					<button class="icon-btn logout" title="Logout" type="button" id="logoutBtnFeature">
						<svg viewBox="0 0 24 24"><path d="M16 17v-3h-6v-4h6V7l5 5-5 5zM3 5h8V3H3a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8v-2H3V5z"></path></svg>
					</button>
				</div>
			</div>
		</header>

		<main class="dashboard-wrap">
			<div class="row g-3 g-md-4" id="featureCardsRow"></div>
		</main>

		<button class="floating-btn floating-left" id="backToMain" aria-label="Back to Main Dashboard" title="Back to Main Dashboard">
			<svg viewBox="0 0 24 24"><path d="m15 5-7 7 7 7 1.5-1.5-5.5-5.5 5.5-5.5z"></path></svg>
		</button>
	</div>

	<div class="notif-panel-ui" id="notifPanel" role="dialog" aria-label="Today notifications">
		<div class="notif-panel-head">
			<div class="notif-head-row">
				<div class="notif-head-title-wrap">
					<span class="notif-head-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24"><path d="M12 2a6 6 0 0 0-6 6v3.1c0 .7-.2 1.4-.6 2L4 16h16l-1.4-2.9c-.4-.6-.6-1.3-.6-2V8a6 6 0 0 0-6-6zm0 20a3 3 0 0 0 2.8-2H9.2A3 3 0 0 0 12 22z"></path></svg>
					</span>
					<h3 class="notif-panel-title">Corn Care Notifications</h3>
				</div>
				<span class="notif-count-chip"><?php echo (int) $todayTaskNotificationCount; ?> today</span>
			</div>
			<p class="notif-panel-sub"><?php echo htmlspecialchars($notificationTitleText); ?></p>
		</div>
		<div class="notif-panel-body">
			<?php if ($todayTaskNotificationCount > 0) { ?>
				<ul class="notif-list">
					<?php foreach (array_slice($todayTaskTitles, 0, 10) as $taskTitle) { ?>
						<li class="notif-item">
							<div class="notif-item-left">
								<span class="notif-item-title"><?php echo htmlspecialchars($taskTitle); ?></span>
								<span class="notif-item-meta">Pending today</span>
							</div>
							<button class="notif-view-btn" type="button" data-task-title="<?php echo htmlspecialchars($taskTitle, ENT_QUOTES); ?>" aria-label="View task details">
								<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c5.1 0 9.2 4.2 10.8 6.3.2.3.2.8 0 1.1C21.2 14.5 17.1 18.7 12 18.7S2.8 14.5 1.2 12.4c-.2-.3-.2-.8 0-1.1C2.8 9.2 6.9 5 12 5zm0 2C8.2 7 5 10 3.3 11.8 5 13.7 8.2 16.7 12 16.7s7-3 8.7-4.9C19 10 15.8 7 12 7zm0 2.2a2.8 2.8 0 1 1 0 5.6 2.8 2.8 0 0 1 0-5.6zm0 2a.8.8 0 1 0 0 1.6.8.8 0 0 0 0-1.6z"></path></svg>
							</button>
						</li>
					<?php } ?>
				</ul>
			<?php } else { ?>
				<div class="notif-empty">No pending corn care tasks today.</div>
			<?php } ?>
		</div>
		<div class="notif-panel-note">Tap the eye icon to view each task in calendar.</div>
	</div>

		<div class="modal-mask" id="profileModalMask"></div>
		<div class="profile-modal" id="profileModal" role="dialog" aria-modal="true" aria-labelledby="profileModalTitle">
			<div class="profile-modal-head">
				<div class="profile-head-intro">
					<div class="profile-head-badge"><?php echo htmlspecialchars($displayInitials); ?></div>
					<div>
							<h3 class="profile-modal-title" id="profileModalTitle">Profile</h3>
					</div>
				</div>
					<button class="btn profile-close-btn px-4" id="closeProfileModalBtn" type="button">Close</button>
			</div>

			<div class="profile-tabs" role="tablist" aria-label="Profile tabs">
				<button class="profile-tab-btn active" type="button" data-profile-tab="profile">Profile</button>
				<button class="profile-tab-btn" type="button" data-profile-tab="void">Void Planting Data</button>
			</div>

			<section class="profile-tab-pane active" id="profileTabPane">
				<div class="profile-grid">
					<div class="profile-card">
						<h4 class="profile-card-title">PROFILE AND CREDENTIALS</h4>
						<form class="account-settings-form" id="accountSettingsForm" autocomplete="off">
							<div class="account-grid">
								<div>
									<label class="form-label account-form-label" for="accountFullNameInput">Full Name</label>
									<input class="form-control" id="accountFullNameInput" type="text" value="<?php echo htmlspecialchars($displayName); ?>" required>
								</div>
								<div>
									<label class="form-label account-form-label" for="accountUsernameInput">Username</label>
									<input class="form-control" id="accountUsernameInput" type="text" value="<?php echo htmlspecialchars(ltrim($displayUsername, "@")); ?>" required>
								</div>
							</div>

							<div class="profile-credential-actions">
								<button class="btn btn-outline-secondary btn-sm profile-pass-shortcut-btn" id="profileChangePasswordShortcutBtn" type="button">Change Password</button>
							</div>

							<div class="account-pass-fields d-none" id="accountPasswordFields">
								<div class="account-pass-grid">
									<div>
										<label class="form-label account-form-label" for="accountCurrentPasswordInput">Current Password</label>
										<input class="form-control" id="accountCurrentPasswordInput" type="password" placeholder="Enter current password">
										<p class="account-field-error" id="accountCurrentPasswordError">Current password is incorrect.</p>
									</div>
									<div>
										<label class="form-label account-form-label" for="accountNewPasswordInput">New Password</label>
										<input class="form-control" id="accountNewPasswordInput" type="password" placeholder="At least 6 characters">
										<p class="account-field-error" id="accountNewPasswordLengthError">Password must be at least 6 characters.</p>
										<div class="account-strength" id="accountStrengthBox">
											<div class="account-strength-row">
												<span>Password strength:</span>
												<span class="account-strength-label" id="accountStrengthLabel"></span>
											</div>
											<div class="account-strength-bar">
												<div class="account-strength-fill" id="accountStrengthFill"></div>
											</div>
										</div>
									</div>
									<div>
										<label class="form-label account-form-label" for="accountConfirmPasswordInput">Confirm New Password</label>
										<input class="form-control" id="accountConfirmPasswordInput" type="password" placeholder="Re-enter new password">
										<p class="account-field-error" id="accountConfirmPasswordError">Passwords do not match.</p>
									</div>
								</div>
							</div>

							<div class="account-status-note" id="accountStatusNote"></div>
							<div class="account-form-actions">
								<button class="btn btn-outline-secondary account-cancel-btn d-none" id="accountCancelPasswordBtn" type="button">Cancel</button>
								<button class="btn btn-success account-save-btn" id="saveAccountBtn" type="submit">Save Changes</button>
							</div>
						</form>
					</div>

					<div class="profile-card">
						<h4 class="profile-card-title">Planting Profile Summary</h4>
						<div class="profile-item">
							<div class="profile-item-label">Corn Type</div>
							<div class="profile-item-value" id="summaryCornType">--</div>
						</div>
						<div class="profile-item">
							<div class="profile-item-label">Variety</div>
							<div class="profile-item-value" id="summaryCornVariety">--</div>
						</div>
						<div class="profile-item">
							<div class="profile-item-label">Planting Date</div>
							<div class="profile-item-value" id="summaryPlantingDate">--</div>
						</div>
						<div class="profile-item">
							<div class="profile-item-label">Farm Location</div>
							<div class="profile-item-value" id="summaryFarmLocation">--</div>
						</div>
						<div class="profile-item">
							<div class="profile-item-label">Area</div>
							<div class="profile-item-value" id="summaryArea">--</div>
						</div>
						<div class="profile-item">
							<div class="profile-item-label">Est. Harvest Date</div>
							<div class="profile-item-value" id="summaryHarvestDate">--</div>
						</div>
						<div class="profile-item">
							<div class="profile-item-label">Status</div>
							<div class="profile-item-value"><span class="profile-status-pill is-empty" id="summaryStatus">No profile saved yet</span></div>
						</div>
					</div>

				</div>
			</section>

			<section class="profile-tab-pane" id="voidTabPane">
				<div class="void-start" id="voidStartStep">
					<div class="void-start-head">
						<span class="void-start-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M12 2 1 21h22L12 2zm1 15h-2v2h2v-2zm0-8h-2v6h2V9z"></path></svg>
						</span>
						<div>
							<h4>Are you sure you want to continue?</h4>
							<p>Voiding planting data will clear current records and prepare your account for a new planting cycle.</p>
						</div>
					</div>
					<ul class="void-impact-list">
						<li class="void-impact-item"><span class="void-impact-dot"></span>Planting profile details will be removed</li>
						<li class="void-impact-item"><span class="void-impact-dot"></span>Costing records will be cleared</li>
						<li class="void-impact-item"><span class="void-impact-dot"></span>Corn care calendar tasks will be reset</li>
					</ul>
					<div class="void-actions">
						<button class="btn btn-danger void-btn-danger" id="voidContinueBtn" type="button">Yes, Continue</button>
						<button class="btn btn-outline-secondary void-btn-secondary" id="voidCancelBtn" type="button">Cancel</button>
					</div>
				</div>

				<form class="void-form d-none" id="voidFormStep">
					<div class="void-form-head">
						<h5 class="void-form-title">Reset Confirmation Form</h5>
						<p class="void-form-sub">Complete this form to verify your request before clearing planting data.</p>
					</div>
					<div class="mb-3">
						<label class="form-label" for="voidReasonSelect">Reason for voiding</label>
						<select class="form-select" id="voidReasonSelect" required>
							<option value="">Select reason</option>
							<option value="Wrong planting details">Wrong planting details</option>
							<option value="Restarting planting cycle">Restarting planting cycle</option>
							<option value="Farm area changed">Farm area changed</option>
							<option value="Other">Other</option>
						</select>
					</div>
					<div class="mb-3">
						<label class="form-label" for="voidNotesInput">Notes</label>
						<textarea class="form-control" id="voidNotesInput" rows="3" placeholder="Optional notes about this reset"></textarea>
					</div>
					<div class="mb-3">
						<label class="form-label void-confirm-label" for="voidConfirmInput">Type <span class="void-confirm-token">VOID</span> to confirm</label>
						<input class="form-control" id="voidConfirmInput" type="text" placeholder="VOID" required>
					</div>
					<div class="form-check mb-3">
						<input class="form-check-input" id="voidAcknowledgeCheck" type="checkbox">
						<label class="form-check-label" for="voidAcknowledgeCheck">I understand this action will clear my current planting data.</label>
					</div>
					<div class="void-status-note" id="voidStatusNote"></div>
					<div class="d-flex flex-wrap gap-2 justify-content-end">
						<button class="btn btn-outline-secondary void-btn-secondary" id="voidBackBtn" type="button">Back</button>
						<button class="btn btn-danger void-btn-danger" id="submitVoidBtn" type="submit">Void Planting Data</button>
					</div>
				</form>

				<div class="modal-mask" id="voidConfirmMask"></div>
				<div class="void-confirm-modal" id="voidConfirmAction" role="dialog" aria-modal="true" aria-labelledby="voidConfirmTitle">
					<div class="void-confirm-card">
						<div class="void-confirm-header">
							<div class="void-confirm-icon" aria-hidden="true">
								<svg viewBox="0 0 24 24"><path d="M12 2 1 21h22L12 2zm1 15h-2v2h2v-2zm0-7h-2v5h2v-5z"></path></svg>
							</div>
							<div>
								<h4 class="void-confirm-title" id="voidConfirmTitle">Are you sure?</h4>
								<p class="void-confirm-sub">This will delete your current planting data and prepare the account for a new start.</p>
							</div>
						</div>
						<div class="void-confirm-body">
							<p class="void-confirm-note">Planting profile, costing, and calendar records will be cleared.</p>
							<div class="void-confirm-actions">
								<button class="btn btn-outline-secondary void-btn-secondary" id="voidConfirmNoBtn" type="button">Cancel</button>
								<button class="btn btn-danger void-btn-danger" id="voidConfirmYesBtn" type="button">Yes, delete</button>
							</div>
						</div>
					</div>
				</div>

				<!-- Deleting overlay -->
				<div id="voidDeletingOverlay" class="modal-mask d-none" style="z-index:90">
					<div class="logout-modal" style="width:min(92vw, 340px);">
						<div style="text-align:center; padding:18px 8px;">
							<div class="void-delete-spinner" aria-hidden="true"></div>
							<p class="void-delete-title">Deleting planting data</p>
							<p class="void-delete-copy">Please wait while we clear your records.</p>
						</div>
					</div>
				</div>

				<!-- Toast -->
				<div id="voidToast" class="d-none" aria-live="polite" aria-atomic="true">
					<div class="void-toast-card">
						<div class="void-toast-title" id="voidToastTitle">Account deleted</div>
						<div class="void-toast-msg" id="voidToastMsg">Your planting data has been cleared. You can restart planting.</div>
					</div>
				</div>
			</section>
		</div>

	<div class="modal-mask" id="logoutModalMask"></div>
	<div class="logout-modal" id="logoutModal">
		<h3 class="logout-title">Logout</h3>
		<p class="logout-desc">Are you sure you want to logout? You will be redirected to the login page.</p>
		<div class="d-flex justify-content-end gap-2">
			<button class="btn btn-outline-secondary" id="cancelLogout" type="button">Cancel</button>
			<button class="btn btn-danger" id="confirmLogout" type="button">Logout</button>
		</div>
	</div>

	<div class="modal-mask" id="heatPromptMask"></div>
	<div class="heat-prompt-modal" id="heatPromptModal" role="dialog" aria-modal="true" aria-labelledby="heatPromptTitle">
		<div class="heat-prompt-head">
			<div class="heat-prompt-title-wrap">
				<div class="heat-prompt-eyebrow">Weather Alert</div>
				<h3 class="heat-prompt-title" id="heatPromptTitle">It&apos;s getting hot for the corn</h3>
			</div>
			<div class="heat-prompt-temp" id="heatPromptTemp">--&deg;C</div>
		</div>
		<p class="heat-prompt-desc" id="heatPromptDesc">Today&apos;s live temperature is high enough to suggest watering. Do you want to add a watering task for today?</p>
		<div class="heat-prompt-actions">
			<button class="btn btn-outline-secondary" id="heatPromptNoBtn" type="button">No</button>
			<button class="btn btn-success" id="heatPromptYesBtn" type="button">Yes, add watering task</button>
		</div>
		<p class="heat-prompt-note">If watering already exists today, we&apos;ll add a note instead.</p>
	</div>

	<div class="modal-mask" id="summaryModalMask"></div>
	<div class="summary-modal" id="summaryModal" role="dialog" aria-modal="true" aria-labelledby="summaryModalTitle">
		<div class="summary-modal-head">
			<div>
				<button class="summary-history-chip" id="summaryHistoryBtn" type="button" title="Completed cycles recorded from the Summary folder" aria-label="Open completed cycle history">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 3a9 9 0 1 0 8.94 10.06h-2.03A7 7 0 1 1 13 5v3l4-4-4-4v3z"></path><path d="M12 7v6l4 2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>
					<span>History <strong><?php echo (int) $summaryHistoryCount; ?></strong></span>
				</button>
				<h3 class="summary-modal-title" id="summaryModalTitle">Harvest Summary</h3>
				<p class="summary-modal-sub" id="summaryModalSub">Congratulations on finishing the cycle. You are ready to start a new one.</p>
			</div>
			<span class="pill summary-stage-pill-locked" id="summaryStagePill" title="<?php echo htmlspecialchars($summaryHistoryLatestLabel, ENT_QUOTES, 'UTF-8'); ?>">Locked</span>
		</div>

		<div class="summary-history-modal" id="summaryHistoryModal" role="region" aria-labelledby="summaryHistoryTitle">
			<div class="summary-history-head">
				<div>
					<h3 class="summary-history-title" id="summaryHistoryTitle">Completed Cycle History</h3>
					<p class="summary-history-sub">Dates and data are loaded from the saved summary files in the Summary folder.</p>
				</div>
				<button class="btn btn-sm btn-outline-secondary" id="closeSummaryHistoryBtn" type="button">Back</button>
			</div>
			<div class="summary-history-list">
				<?php if (!empty($summaryHistoryEntries)) : ?>
					<?php foreach ($summaryHistoryEntries as $historyEntry) : ?>
						<div class="summary-history-item">
							<?php
							$historySummary = is_array($historyEntry['summary'] ?? null) ? $historyEntry['summary'] : [];
							$historyHeader = is_array($historySummary['presentation']['header'] ?? null) ? $historySummary['presentation']['header'] : [];
							$historyStep1 = is_array($historySummary['summary']['step1'] ?? null) ? $historySummary['summary']['step1'] : [];
							$historyStep2 = is_array($historySummary['summary']['step2'] ?? null) ? $historySummary['summary']['step2'] : [];
							$historyHero = is_array($historyStep1['hero'] ?? null) ? $historyStep1['hero'] : [];
							$historyProfile = is_array($historyStep1['profile'] ?? null) ? $historyStep1['profile'] : [];
							$historyProgress = is_array($historyStep1['progress'] ?? null) ? $historyStep1['progress'] : [];
							$historyFinance = is_array($historyStep1['finance'] ?? null) ? $historyStep1['finance'] : [];
							$historyHealth = is_array($historyStep1['health'] ?? null) ? $historyStep1['health'] : [];
							$historyPrediction = is_array($historyStep2['prediction'] ?? null) ? $historyStep2['prediction'] : [];
							$historyActual = is_array($historyStep2['actual'] ?? null) ? $historyStep2['actual'] : [];
							$historyAccuracy = is_array($historyStep2['accuracy'] ?? null) ? $historyStep2['accuracy'] : [];
							$historyAnalysis = is_array($historyStep2['analysis'] ?? null) ? $historyStep2['analysis'] : [];
							?>
							<div class="summary-history-item-head">
								<div>
									<div class="summary-history-chip" style="margin-bottom:8px; padding:5px 10px; font-size:0.72rem; cursor:default;">
										<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 3a9 9 0 1 0 8.94 10.06h-2.03A7 7 0 1 1 13 5v3l4-4-4-4v3z"></path><path d="M12 7v6l4 2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>
										<span>Completed Cycle</span>
									</div>
									<strong><?php echo htmlspecialchars($historyHeader['title'] ?? 'Harvest Overview', ENT_QUOTES, 'UTF-8'); ?></strong>
									<span><?php echo htmlspecialchars($historyEntry['label'], ENT_QUOTES, 'UTF-8'); ?></span>
								</div>
								<div class="pill summary-stage-pill-locked">Completed</div>
							</div>

							<div class="summary-history-meta">
								<div class="summary-history-meta-row">
									<span class="summary-history-meta-label">Badge</span>
									<span class="summary-history-meta-value"><?php echo htmlspecialchars($historyHero['badge'] ?? 'Harvest Ready', ENT_QUOTES, 'UTF-8'); ?></span>
								</div>
								<div class="summary-history-meta-row">
									<span class="summary-history-meta-label">Stage</span>
									<span class="summary-history-meta-value"><?php echo htmlspecialchars($historyHero['stage'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></span>
								</div>
								<div class="summary-history-meta-row">
									<span class="summary-history-meta-label">Progress</span>
									<span class="summary-history-meta-value"><?php echo htmlspecialchars($historyHero['progress'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span>
								</div>
								<div class="summary-history-meta-row">
									<span class="summary-history-meta-label">Profit</span>
									<span class="summary-history-meta-value"><?php echo htmlspecialchars($historyHero['profit'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span>
								</div>
							</div>

							<div class="summary-history-sections">
								<div class="summary-history-section">
									<div class="summary-history-section-title">Field Details</div>
									<div class="summary-history-section-grid">
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Corn Type</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyProfile['cornType'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Variety</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyProfile['variety'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Planting Date</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyProfile['plantingDate'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Estimated Harvest Date</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyProfile['harvestDate'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Farm Location</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyProfile['farmLocation'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Area Planted</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyProfile['areaPlanted'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Soil Type</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyProfile['soilType'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Planting Density</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyProfile['plantingDensity'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Seeds per Hole</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyProfile['seedsPerHole'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Estimated Seeds</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyProfile['estimatedSeeds'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
									</div>
								</div>

								<div class="summary-history-section">
									<div class="summary-history-section-title">Progress and Finance</div>
									<div class="summary-history-section-grid">
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Current Stage</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyProgress['currentStage'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Growth Progress</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyProgress['growthProgress'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Tasks This Week</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyProgress['tasksThisWeek'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Tasks Overall</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyProgress['tasksOverall'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Estimated Income</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyFinance['estimatedIncome'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Total Cost</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyFinance['totalCost'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Net Profit</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyFinance['netProfit'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Market Updated</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyFinance['marketUpdated'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
									</div>
								</div>

								<div class="summary-history-section">
									<div class="summary-history-section-title">Comparison Analysis</div>
									<div class="summary-history-section-grid">
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Prediction Income</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyPrediction['income'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Actual Income</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyActual['income'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Income Accuracy</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyAccuracy['income'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Average Accuracy</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyAnalysis['averageAccuracy'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Biggest Variance</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyAnalysis['biggestVariance'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Overall Result</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyAnalysis['overallResult'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
									</div>
									<div class="summary-history-note"><?php echo htmlspecialchars($historyAnalysis['note'] ?? 'Saved summary data from this completed cycle.', ENT_QUOTES, 'UTF-8'); ?></div>
								</div>

								<div class="summary-history-section">
									<div class="summary-history-section-title">Recent Activity</div>
									<div class="summary-history-section-grid">
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Latest Pest/Disease Check</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyHealth['latestPestDetection'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
										<div class="summary-history-meta-row"><span class="summary-history-meta-label">Calendar Last Updated</span><span class="summary-history-meta-value"><?php echo htmlspecialchars($historyHealth['calendarUpdated'] ?? '--', ENT_QUOTES, 'UTF-8'); ?></span></div>
									</div>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="summary-history-empty">No completed cycle history yet.</div>
				<?php endif; ?>
			</div>
		</div>

		<div class="summary-locked" id="summaryLockedState">
			<div class="summary-locked-head">
				<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 2 7v6c0 5.3 3.8 8.7 10 9.9 6.2-1.2 10-4.6 10-9.9V7L12 2zm0 5a3 3 0 0 1 3 3v1h1a1 1 0 0 1 1 1v5a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1v-5a1 1 0 0 1 1-1h1v-1a3 3 0 0 1 3-3zm0 2a1 1 0 0 0-1 1v1h2v-1a1 1 0 0 0-1-1z"></path></svg>
				<span>Summary is currently locked</span>
			</div>
			<p class="summary-locked-note">Reach Harvest stage to view the final summary details for your current planting cycle.</p>
			<div class="summary-locked-grid">
				<div class="summary-locked-row">
					<span class="summary-locked-label">Current Stage</span>
					<span class="summary-locked-value" id="summaryLockedStageValue">--</span>
				</div>
				<div class="summary-locked-row">
					<span class="summary-locked-label">Growth Progress</span>
					<span class="summary-locked-value" id="summaryLockedGrowthValue">--</span>
				</div>
				<div class="summary-locked-row">
					<span class="summary-locked-label">Days Until Harvest</span>
					<span class="summary-locked-value" id="summaryLockedDaysValue">--</span>
				</div>
			</div>
		</div>

		<div class="summary-ready d-none" id="summaryReadyState">
			<div class="summary-stepper" role="tablist" aria-label="Harvest summary steps">
				<button class="summary-step-btn active" type="button" data-summary-step="1" aria-controls="summaryStepOne">
					<span class="summary-step-number">1</span>
					<span class="summary-step-label">Harvest Summary<span class="summary-step-sub">Final crop snapshot</span></span>
				</button>
				<button class="summary-step-btn" type="button" data-summary-step="2" aria-controls="summaryStepTwo">
					<span class="summary-step-number">2</span>
					<span class="summary-step-label">Yield Comparison<span class="summary-step-sub">Prediction vs actual results</span></span>
				</button>
				<button class="summary-step-btn" type="button" data-summary-step="3" aria-controls="summaryStepThree">
					<span class="summary-step-number">3</span>
					<span class="summary-step-label">New Cycle<span class="summary-step-sub">Start a fresh planting run</span></span>
				</button>
			</div>

			<div class="summary-step-panel" id="summaryStepOne">
				<div class="summary-hero-card">
					<div class="summary-hero-top">
						<div>
							<p class="summary-hero-kicker">Harvest Overview</p>
							<h4 class="summary-hero-title">Final crop snapshot</h4>
							<p class="summary-hero-copy">Everything important from this planting cycle, shown in one clean view before you continue to comparison and the next cycle.</p>
						</div>
						<span class="summary-hero-badge" id="summaryHeroBadge">Harvest Ready</span>
					</div>
					<div class="summary-hero-stats">
						<div class="summary-hero-stat">
							<span class="summary-hero-stat-label">Stage</span>
							<div class="summary-hero-stat-value" id="summaryHeroStageValue">--</div>
						</div>
						<div class="summary-hero-stat">
							<span class="summary-hero-stat-label">Progress</span>
							<div class="summary-hero-stat-value" id="summaryHeroProgressValue">--</div>
						</div>
						<div class="summary-hero-stat">
							<span class="summary-hero-stat-label">Tasks</span>
							<div class="summary-hero-stat-value" id="summaryHeroTasksValue">--</div>
						</div>
						<div class="summary-hero-stat">
							<span class="summary-hero-stat-label">Profit</span>
							<div class="summary-hero-stat-value" id="summaryHeroProfitValue">--</div>
						</div>
					</div>
				</div>

				<div class="summary-panel-card">
					<div class="summary-panel-title">
						<h4>Profile</h4>
						<span>Field details</span>
					</div>
					<div class="summary-card-grid">
						<div class="summary-row">
							<span class="summary-row-label">Corn Type</span>
							<span class="summary-row-value" id="summaryCornTypeValue">--</span>
						</div>
						<div class="summary-row">
							<span class="summary-row-label">Variety</span>
							<span class="summary-row-value" id="summaryCornVarietyValue">--</span>
						</div>
						<div class="summary-row">
							<span class="summary-row-label">Planting Date</span>
							<span class="summary-row-value" id="summaryPlantingDateValue">--</span>
						</div>
						<div class="summary-row">
							<span class="summary-row-label">Estimated Harvest Date</span>
							<span class="summary-row-value" id="summaryHarvestDateValue">--</span>
						</div>
						<div class="summary-row">
							<span class="summary-row-label">Farm Location</span>
							<span class="summary-row-value" id="summaryFarmLocationValue">--</span>
						</div>
						<div class="summary-row">
							<span class="summary-row-label">Area Planted</span>
							<span class="summary-row-value" id="summaryAreaValue">--</span>
						</div>
						<div class="summary-row">
							<span class="summary-row-label">Soil Type</span>
							<span class="summary-row-value" id="summarySoilTypeValue">--</span>
						</div>
						<div class="summary-row">
							<span class="summary-row-label">Planting Density</span>
							<span class="summary-row-value" id="summaryPlantingDensityValue">--</span>
						</div>
						<div class="summary-row">
							<span class="summary-row-label">Seeds per Hole</span>
							<span class="summary-row-value" id="summarySeedsPerHoleValue">--</span>
						</div>
						<div class="summary-row">
							<span class="summary-row-label">Estimated Seeds</span>
							<span class="summary-row-value" id="summaryEstimatedSeedsValue">--</span>
						</div>
					</div>
				</div>

				<div class="summary-panel-card">
					<div class="summary-panel-title">
						<h4>Progress</h4>
						<span>Growth status</span>
					</div>
					<div class="summary-card-grid">
						<div class="summary-row">
							<span class="summary-row-label">Current Stage</span>
							<span class="summary-row-value" id="summaryCurrentStageValue">--</span>
						</div>
						<div class="summary-row">
							<span class="summary-row-label">Growth Progress</span>
							<span class="summary-row-value" id="summaryGrowthValue">--</span>
						</div>
						<div class="summary-row">
							<span class="summary-row-label">All Tasks</span>
							<span class="summary-row-value" id="summaryTasksValue">--</span>
						</div>
						<div class="summary-row">
											<span class="summary-row-label">Completed / Pending</span>
							<span class="summary-row-value" id="summaryTasksOverallValue">--</span>
						</div>
					</div>
				</div>

				<div class="summary-panel-card">
					<div class="summary-panel-title">
						<h4>Finance</h4>
						<span>Harvest value</span>
					</div>
					<div class="summary-card-grid">
						<div class="summary-row">
							<span class="summary-row-label">Estimated Income</span>
							<span class="summary-row-value" id="summaryIncomeValue">--</span>
						</div>
						<div class="summary-row">
							<span class="summary-row-label">Total Cost</span>
							<span class="summary-row-value" id="summaryTotalCostValue">--</span>
						</div>
						<div class="summary-row">
							<span class="summary-row-label">Net Profit</span>
							<span class="summary-row-value" id="summaryNetProfitValue">--</span>
						</div>
						<div class="summary-row">
							<span class="summary-row-label">Market Price Updated</span>
							<span class="summary-row-value" id="summaryMarketUpdatedValue">--</span>
						</div>
					</div>
				</div>

				<div class="summary-panel-card">
					<div class="summary-panel-title">
						<h4>Health &amp; History</h4>
						<span>Recent activity</span>
					</div>
					<div class="summary-card-grid">
						<div class="summary-row">
							<span class="summary-row-label">Latest Pest/Disease Check</span>
							<span class="summary-row-value" id="summaryPestDetectionValue">--</span>
						</div>
						<div class="summary-row">
							<span class="summary-row-label">Calendar Last Updated</span>
							<span class="summary-row-value" id="summaryCalendarUpdatedValue">--</span>
						</div>
					</div>
				</div>
		</div>

		<div class="summary-step-panel d-none" id="summaryStepTwo">
			<div class="summary-comparison-shell">
				<div class="summary-comparison-intro">
					<h4>Yield Comparison</h4>
					<p>Estimate versus actual harvest performance. Income is entered by the farmer, total cost mirrors the planted cycle cost, and profit is calculated automatically from the final result.</p>
				</div>
				<div class="summary-comparison-table-wrap">
					<table class="summary-comparison-table">
						<thead>
							<tr>
								<th>Metric</th>
								<th>Prediction</th>
								<th>Actual</th>
								<th>Difference</th>
								<th>Accuracy</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td class="summary-metric-name">Income</td>
								<td class="summary-predicted-value" id="summaryPredictedIncomeValue">--</td>
								<td><input class="summary-actual-input" id="summaryActualIncomeInput" type="number" min="0" step="0.01" placeholder="Enter actual income"></td>
								<td class="summary-difference-value" id="summaryIncomeDifferenceValue">--</td>
								<td class="summary-accuracy-value" id="summaryIncomeAccuracyValue">--</td>
							</tr>
							<tr>
								<td class="summary-metric-name">Total Cost</td>
								<td class="summary-predicted-value" id="summaryPredictedCostValue">--</td>
								<td class="summary-predicted-value" id="summaryActualCostValue">--</td>
								<td class="summary-difference-value" id="summaryCostDifferenceValue">--</td>
								<td class="summary-accuracy-value" id="summaryCostAccuracyValue">--</td>
							</tr>
							<tr>
								<td class="summary-metric-name">Profit</td>
								<td class="summary-predicted-value" id="summaryPredictedProfitValue">--</td>
								<td class="summary-predicted-value" id="summaryActualProfitValue">--</td>
								<td class="summary-difference-value" id="summaryProfitDifferenceValue">--</td>
								<td class="summary-accuracy-value" id="summaryProfitAccuracyValue">--</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="summary-analysis-card">
					<h4 class="summary-analysis-title">Comparison Analysis</h4>
					<p class="summary-analysis-copy" id="yieldComparisonNote">Enter the actual income. Total cost will mirror the same planting cost data, and profit will be auto-calculated from the final numbers.</p>
					<div class="summary-analysis-grid">
						<div class="summary-analysis-stat">
							<span class="summary-analysis-stat-label">Average Accuracy</span>
							<div class="summary-analysis-stat-value" id="yieldAverageAccuracyValue">--</div>
						</div>
						<div class="summary-analysis-stat">
							<span class="summary-analysis-stat-label">Biggest Variance</span>
							<div class="summary-analysis-stat-value" id="yieldBiggestVarianceValue">--</div>
						</div>
						<div class="summary-analysis-stat">
							<span class="summary-analysis-stat-label">Overall Result</span>
							<div class="summary-analysis-stat-value" id="yieldOverallResultValue">--</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="summary-step-panel d-none" id="summaryStepThree">
			<div class="summary-thankyou-card">
				<div class="summary-thankyou-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2c-.7 1.2-1.4 2.1-2.1 2.8C8.3 6.5 7.5 8.6 7.5 11c0 4.1 2 7.4 4.5 11 2.5-3.6 4.5-6.9 4.5-11 0-2.4-.8-4.5-2.4-6.2C13.4 4.1 12.7 3.2 12 2zm0 5c1 0 1.8.8 1.8 1.8S13 10.6 12 10.6s-1.8-.8-1.8-1.8S11 7 12 7zm-2.6 5.1c.8.6 1.7 1 2.6 1s1.8-.4 2.6-1c-.2 1.9-1 4.1-2.6 6.7-1.6-2.6-2.4-4.8-2.6-6.7z"></path><path d="M9.5 8.4c-1.5-.2-2.9.1-4.2.9 1 .8 2.1 1.3 3.2 1.5.4-.9.7-1.7 1-2.4zm5 0c.3.7.6 1.5 1 2.4 1.1-.2 2.2-.7 3.2-1.5-1.3-.8-2.7-1.1-4.2-.9z"></path></svg>
				</div>
				<div class="summary-thankyou-copy-wrap">
					<h4 class="summary-thanks-title">Congratulations on your Harvest Cycle</h4>
					<p class="summary-thanks-copy">You completed the current cycle successfully. When you’re ready, start a new cycle to track fresh progress, costs, and harvest outcomes again.</p>
				</div>
				<div class="summary-thankyou-list">
					<div class="summary-thankyou-item">Cycle completed<strong>Harvest reached</strong></div>
					<div class="summary-thankyou-item">Ready for next start<strong>New planting profile</strong></div>
					<div class="summary-thankyou-item">Keep records fresh<strong>Track the next run</strong></div>
				</div>
			</div>
		</div>

		<div class="summary-step-footer">
			<div class="summary-footer-left">
				<button class="btn summary-nav-btn px-3" id="summaryPrevBtn" type="button">Back</button>
				<button class="btn summary-nav-btn px-3" id="summaryNextBtn" type="button">Next</button>
			</div>
			<div class="summary-footer-right">
				<button class="btn summary-new-cycle-btn px-4 d-none" id="summaryNewCycleBtn" type="button">New Cycle</button>
				<button class="btn summary-close-btn px-4" id="closeSummaryModalBtn" type="button">Close</button>
			</div>
		</div>
	</div>

	<!-- Confirm New Cycle Modal -->
	<div class="modal-mask" id="confirmNewCycleMask"></div>
	<div class="logout-modal" id="confirmNewCycleModal" role="dialog" aria-modal="true" aria-labelledby="confirmNewCycleTitle">
		<h3 class="logout-title" id="confirmNewCycleTitle">Start New Planting Cycle</h3>
		<p class="logout-desc">Are you sure you want to start a new planting cycle? Your current cycle will be marked as completed and archived.</p>
		<div class="d-flex justify-content-end gap-2">
			<button class="btn btn-outline-secondary" id="cancelNewCycleBtn" type="button">Cancel</button>
			<button class="btn btn-danger" id="confirmNewCycleBtnAction" type="button">Yes, start new cycle</button>
		</div>
	</div>

	<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
	<script>
		(function () {
			var marketPricesConfig = <?php echo $marketPricesJson; ?>.market_prices;
			var marketPriceHistoryData = <?php echo json_encode($marketPriceHistoryData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
			var activeMarketPriceKey = <?php echo json_encode($dashboardActiveMarketPriceKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
			var storedPlantingProfile = <?php echo json_encode($savedPlantingProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
			var hasSavedPlantingProfile = <?php echo $savedPlantingProfile ? 'true' : 'false'; ?>;
			var currentRoleLabel = <?php echo json_encode($displayRoleLabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
			var currentUserId = <?php echo (int) $_SESSION["users_id"]; ?>;
			var heatPromptMask = document.getElementById("heatPromptMask");
			var heatPromptModal = document.getElementById("heatPromptModal");
			var heatPromptTemp = document.getElementById("heatPromptTemp");
			var heatPromptDesc = document.getElementById("heatPromptDesc");
			var heatPromptYesBtn = document.getElementById("heatPromptYesBtn");
			var heatPromptNoBtn = document.getElementById("heatPromptNoBtn");
			var summaryBtnMain = document.getElementById("summaryBtnMain");
			var summaryBtnFeature = document.getElementById("summaryBtnFeature");
			var summaryModalMask = document.getElementById("summaryModalMask");
			var summaryModal = document.getElementById("summaryModal");
			var summaryHistoryBtn = document.getElementById("summaryHistoryBtn");
			var summaryHistoryModal = document.getElementById("summaryHistoryModal");
			var closeSummaryHistoryBtn = document.getElementById("closeSummaryHistoryBtn");
			var closeSummaryModalBtn = document.getElementById("closeSummaryModalBtn");
			var summaryLockedState = document.getElementById("summaryLockedState");
			var summaryReadyState = document.getElementById("summaryReadyState");
			var summaryStagePill = document.getElementById("summaryStagePill");
			var summaryModalSub = document.getElementById("summaryModalSub");
			var summaryLockedStageValue = document.getElementById("summaryLockedStageValue");
			var summaryLockedGrowthValue = document.getElementById("summaryLockedGrowthValue");
			var summaryLockedDaysValue = document.getElementById("summaryLockedDaysValue");
			var summaryCurrentStageValue = document.getElementById("summaryCurrentStageValue");
			var summaryHarvestDateValue = document.getElementById("summaryHarvestDateValue");
			var summaryPlantingDateValue = document.getElementById("summaryPlantingDateValue");
			var summaryCornTypeValue = document.getElementById("summaryCornTypeValue");
			var summaryCornVarietyValue = document.getElementById("summaryCornVarietyValue");
			var summaryFarmLocationValue = document.getElementById("summaryFarmLocationValue");
			var summaryAreaValue = document.getElementById("summaryAreaValue");
			var summarySoilTypeValue = document.getElementById("summarySoilTypeValue");
			var summaryPlantingDensityValue = document.getElementById("summaryPlantingDensityValue");
			var summarySeedsPerHoleValue = document.getElementById("summarySeedsPerHoleValue");
			var summaryEstimatedSeedsValue = document.getElementById("summaryEstimatedSeedsValue");
			var summaryIncomeValue = document.getElementById("summaryIncomeValue");
			var summaryTotalCostValue = document.getElementById("summaryTotalCostValue");
			var summaryNetProfitValue = document.getElementById("summaryNetProfitValue");
			var summaryTasksValue = document.getElementById("summaryTasksValue");
			var summaryTasksOverallValue = document.getElementById("summaryTasksOverallValue");
			var summaryGrowthValue = document.getElementById("summaryGrowthValue");
			var summaryMarketUpdatedValue = document.getElementById("summaryMarketUpdatedValue");
			var summaryPestDetectionValue = document.getElementById("summaryPestDetectionValue");
			var summaryCalendarUpdatedValue = document.getElementById("summaryCalendarUpdatedValue");
			var summaryStepButtons = document.querySelectorAll("[data-summary-step]");
			var summaryStepOne = document.getElementById("summaryStepOne");
			var summaryStepTwo = document.getElementById("summaryStepTwo");
			var summaryStepThree = document.getElementById("summaryStepThree");
			var summaryPrevBtn = document.getElementById("summaryPrevBtn");
			var summaryNextBtn = document.getElementById("summaryNextBtn");
			var summaryNewCycleBtn = document.getElementById("summaryNewCycleBtn");
			var summaryHeroBadge = document.getElementById("summaryHeroBadge");
			var summaryHeroStageValue = document.getElementById("summaryHeroStageValue");
			var summaryHeroProgressValue = document.getElementById("summaryHeroProgressValue");
			var summaryHeroTasksValue = document.getElementById("summaryHeroTasksValue");
			var summaryHeroProfitValue = document.getElementById("summaryHeroProfitValue");
			var summaryPredictedIncomeValue = document.getElementById("summaryPredictedIncomeValue");
			var summaryPredictedCostValue = document.getElementById("summaryPredictedCostValue");
			var summaryPredictedProfitValue = document.getElementById("summaryPredictedProfitValue");
			var summaryActualIncomeInput = document.getElementById("summaryActualIncomeInput");
			var summaryActualCostValue = document.getElementById("summaryActualCostValue");
			var summaryActualProfitValue = document.getElementById("summaryActualProfitValue");
			var summaryIncomeDifferenceValue = document.getElementById("summaryIncomeDifferenceValue");
			var summaryCostDifferenceValue = document.getElementById("summaryCostDifferenceValue");
			var summaryProfitDifferenceValue = document.getElementById("summaryProfitDifferenceValue");
			var summaryIncomeAccuracyValue = document.getElementById("summaryIncomeAccuracyValue");
			var summaryCostAccuracyValue = document.getElementById("summaryCostAccuracyValue");
			var summaryProfitAccuracyValue = document.getElementById("summaryProfitAccuracyValue");
			var yieldComparisonNote = document.getElementById("yieldComparisonNote");
			var yieldAverageAccuracyValue = document.getElementById("yieldAverageAccuracyValue");
			var yieldBiggestVarianceValue = document.getElementById("yieldBiggestVarianceValue");
			var yieldOverallResultValue = document.getElementById("yieldOverallResultValue");
			var defaultHarvestDays = 95;
			var selectedHarvestDays = defaultHarvestDays;
			var latestWeatherTemp = null;
			var heatPromptPrefix = "agricorn_heat_prompt_seen_";
			var latestStageVisual = "seed";
			var latestStageName = "--";
			var latestHarvestDateLabel = "--";
			var latestEstimatedIncomeLabel = "--";
			var latestTaskSummaryLabel = "--";
			var latestGrowthSummaryLabel = "--";
			var latestDaysUntilHarvestLabel = "--";
			var summaryCurrentStep = 1;
			var summaryForecastValues = {
				income: null,
				cost: null,
				profit: null
			};

			var stageTemplates = [
				{ stage: "Seed", visual: "seed", percent: 2 },
				{ stage: "Germination", visual: "sprout", percent: 5 },
				{ stage: "Emergence (VE)", visual: "sprout", percent: 9 },
				{ stage: "V1 - First leaf with visible collar", visual: "seedling", percent: 14 },
				{ stage: "V2 - Second leaf", visual: "seedling", percent: 19 },
				{ stage: "V3 - Third leaf", visual: "vegetative", percent: 24 },
				{ stage: "V4 - Fourth leaf", visual: "vegetative", percent: 29 },
				{ stage: "V5 - Fifth leaf", visual: "vegetative", percent: 34 },
				{ stage: "V6 - Sixth leaf", visual: "vegetative", percent: 39 },
				{ stage: "V7 - Seventh leaf", visual: "vegetative", percent: 44 },
				{ stage: "V8 - Eighth leaf", visual: "vegetative", percent: 49 },
				{ stage: "V9 - Ninth leaf", visual: "vegetative", percent: 55 },
				{ stage: "R1 - Silking", visual: "silking", percent: 62 },
				{ stage: "R2 - Blister", visual: "silking", percent: 69 },
				{ stage: "R3 - Milk", visual: "silking", percent: 76 },
				{ stage: "R4 - Dough", visual: "mature", percent: 83 },
				{ stage: "R5 - Dent", visual: "mature", percent: 91 },
				{ stage: "R6 - Physiological Maturity", visual: "mature", percent: 97 },
				{ stage: "Harvest", visual: "harvest", percent: 100 }
			];

			function clamp(value, min, max) {
				return Math.max(min, Math.min(max, value));
			}

			function buildStageDefinitions(harvestDays) {
				var defs = [];
				var startDay = 1;

				for (var i = 0; i < stageTemplates.length; i += 1) {
					var template = stageTemplates[i];
					var isLast = i === stageTemplates.length - 1;
					var maxDay = isLast
						? harvestDays
						: Math.max(startDay, Math.round(harvestDays * template.percent / 100));

					defs.push({
						number: i + 1,
						stage: template.stage,
						days: isLast ? "Day " + maxDay + "+" : "Day " + startDay + "-" + maxDay,
						visual: template.visual,
						max: maxDay
					});

					startDay = maxDay + 1;
				}

				return defs;
			}

			function loadSavedPlantingProfile() {
				if (!storedPlantingProfile || typeof storedPlantingProfile !== "object") {
					return null;
				}
				return storedPlantingProfile;
			}

			function getHarvestDaysFromProfile(profile) {
				if (!profile) {
					return defaultHarvestDays;
				}

				var maxDays = Number(profile.daysToHarvestMax || 0);
				var minDays = Number(profile.daysToHarvestMin || 0);

				if (maxDays > 0) {
					return maxDays;
				}
				if (minDays > 0) {
					return minDays;
				}

				return defaultHarvestDays;
			}

			function getInitialDayFromPlantingDate(profile, harvestDays) {
				if (!profile || !profile.plantingDate) {
					return Math.min(30, harvestDays);
				}

				var plantingDate = new Date(profile.plantingDate + "T00:00:00");
				if (Number.isNaN(plantingDate.getTime())) {
					return Math.min(30, harvestDays);
				}

				var today = new Date();
				today.setHours(0, 0, 0, 0);
				plantingDate.setHours(0, 0, 0, 0);

				var elapsedDays = Math.floor((today - plantingDate) / 86400000) + 1;
				return clamp(elapsedDays, 1, harvestDays);
			}

			function formatDateLabel(dateValue) {
				if (!(dateValue instanceof Date) || Number.isNaN(dateValue.getTime())) {
					return "--";
				}

				return dateValue.toLocaleDateString("en-PH", {
					year: "numeric",
					month: "short",
					day: "numeric"
				});
			}

			function toPositiveNumber(value) {
				if (typeof value === "string") {
					var cleaned = value.replace(/,/g, "").trim();
					var numericMatch = cleaned.match(/-?\d+(?:\.\d+)?/);
					value = numericMatch ? numericMatch[0] : cleaned;
				}
				var numeric = Number(value);
				if (!Number.isFinite(numeric) || numeric <= 0) {
					return 0;
				}
				return numeric;
			}

			function toAreaSqM(profile) {
				if (!profile) {
					return 0;
				}

				var unit = String(profile.areaUnit || profile.area_unit || "").toLowerCase();
				var areaValue = toPositiveNumber(profile.areaPlanted || profile.area_value || profile.areaValue);

				if (unit === "hectares" || unit === "hectare" || unit === "ha") {
					var hectares = areaValue;
					if (hectares > 0) {
						return hectares * 10000;
					}
				}

				var plantedSqM = areaValue;
				if (plantedSqM > 0 && unit !== "hectares" && unit !== "hectare" && unit !== "ha") {
					return plantedSqM;
				}

				if (plantedSqM > 0 && unit === "") {
					return plantedSqM;
				}

				var length = toPositiveNumber(profile.areaLength);
				var width = toPositiveNumber(profile.areaWidth);
				if (length > 0 && width > 0) {
					return length * width;
				}

				// Fallback for legacy profiles: approximate area from seed packs when direct area is missing.
				var packCount = toPositiveNumber(profile.numberOfPacks || profile.number_of_packs || profile.number_of_pack);
				var kgPerPack = toPositiveNumber(profile.kgOfPacks || profile.weightOfPacks || profile.weight_of_packs);
				if (packCount > 0 && kgPerPack > 0) {
					var totalSeedKg = packCount * kgPerPack;
					var approxHectares = totalSeedKg / 20; // Typical seeding rate fallback (~20 kg/ha)
					if (approxHectares > 0) {
						return approxHectares * 10000;
					}
				}

				return 0;
			}

			function getCurrentPricePerKg(profile) {
				if (!profile) {
					return Number(marketPricesConfig.other.price_per_kg || 0);
				}

				var cornType = String(profile.typeOfCorn || profile.corn_type || "").toLowerCase();
				var cornVariety = String(profile.cornVariety || profile.corn_variety || "").toLowerCase();
				var cornDescriptor = (cornType + " " + cornVariety).trim();
				var pricePerKg = Number(marketPricesConfig.other.price_per_kg || 0);

				if (cornDescriptor.indexOf("sweet") !== -1) {
					if (cornDescriptor.indexOf("hybrid") !== -1) pricePerKg = Number(marketPricesConfig.sweet_hybrid.price_per_kg || pricePerKg);
					else if (cornDescriptor.indexOf("native") !== -1) pricePerKg = Number(marketPricesConfig.sweet_native.price_per_kg || pricePerKg);
					else if (cornDescriptor.indexOf("opv") !== -1) pricePerKg = Number(marketPricesConfig.sweet_opv.price_per_kg || pricePerKg);
					else pricePerKg = Number(marketPricesConfig.sweet_hybrid.price_per_kg || pricePerKg);
				} else if (cornDescriptor.indexOf("yellow") !== -1) {
					if (cornDescriptor.indexOf("hybrid") !== -1) pricePerKg = Number(marketPricesConfig.yellow_hybrid.price_per_kg || pricePerKg);
					else if (cornDescriptor.indexOf("feed") !== -1) pricePerKg = Number(marketPricesConfig.yellow_feeds.price_per_kg || pricePerKg);
					else if (cornDescriptor.indexOf("native") !== -1) pricePerKg = Number(marketPricesConfig.yellow_native.price_per_kg || pricePerKg);
					else pricePerKg = Number(marketPricesConfig.yellow_hybrid.price_per_kg || pricePerKg);
				} else if (cornDescriptor.indexOf("white") !== -1) {
					if (cornDescriptor.indexOf("field") !== -1) pricePerKg = Number(marketPricesConfig.white_field.price_per_kg || pricePerKg);
					else if (cornDescriptor.indexOf("native") !== -1) pricePerKg = Number(marketPricesConfig.white_native.price_per_kg || pricePerKg);
					else pricePerKg = Number(marketPricesConfig.white_field.price_per_kg || pricePerKg);
				} else if (cornDescriptor.indexOf("glutinous") !== -1 || cornDescriptor.indexOf("waxy") !== -1) {
					pricePerKg = Number(marketPricesConfig.glutinous.price_per_kg || pricePerKg);
				} else if (cornDescriptor.indexOf("popcorn") !== -1) {
					pricePerKg = Number(marketPricesConfig.popcorn.price_per_kg || pricePerKg);
				} else if (cornDescriptor.indexOf("baby") !== -1) {
					pricePerKg = Number(marketPricesConfig.baby_corn.price_per_kg || pricePerKg);
				}

				return pricePerKg;
			}

			function getEstimatedIncomeValue(profile) {
				if (typeof syncedMachineLearningIncome === "number" && !Number.isNaN(syncedMachineLearningIncome) && syncedMachineLearningIncome >= 0) {
					return syncedMachineLearningIncome;
				}

				if (!profile) {
					return null;
				}

				var areaSqM = toAreaSqM(profile);
				if (areaSqM <= 0) {
					return null;
				}

				var cornVariety = String(profile.cornVariety || profile.corn_variety || profile.typeOfCorn || profile.corn_type || "").toLowerCase();
				var pricePerKg = getCurrentPricePerKg(profile);
				var yieldKgPerSqM = (cornVariety.indexOf("sweet") !== -1) ? 0.7 : 0.55;

				return Math.round(areaSqM * yieldKgPerSqM * pricePerKg);
			}

			function syncForecastIncomeFromMachineLearning() {
				isForecastIncomeSyncPending = true;
				return fetch("machine_learning.php?action=financial_snapshot", {
					method: "GET",
					headers: {
						"Accept": "application/json"
					}
				})
					.then(function (response) {
						if (!response.ok) {
							throw new Error("Unable to sync forecast income");
						}
						return response.json();
					})
					.then(function (payload) {
						if (!payload || payload.success !== true || payload.prediction_ready !== true) {
							isForecastIncomeSyncPending = false;
							if (dayRange && activePlantingProfile) {
								updateDashboard(Number(dayRange.value || 1));
							}
							return;
						}

						var income = Number(payload.estimated_income);
						var totalCost = Number(payload.total_cost);
						var netProfit = Number(payload.net_profit);
						if (Number.isNaN(income) || income < 0) {
							return;
						}
						if (Number.isNaN(totalCost) || totalCost < 0) {
							totalCost = Number(latestTotalCostValue || 0);
						}
						if (Number.isNaN(netProfit)) {
							netProfit = income - totalCost;
						}

						syncedMachineLearningIncome = income;
						latestTotalCostValue = totalCost;
						isForecastIncomeSyncPending = false;
						var incomeLabel = new Intl.NumberFormat("en-PH", {
							style: "currency",
							currency: "PHP",
							maximumFractionDigits: 0
						}).format(income);

						var estimatedIncomeNode = document.getElementById("estimatedIncomeValue");
						if (estimatedIncomeNode) {
							estimatedIncomeNode.textContent = incomeLabel;
							estimatedIncomeNode.setAttribute("data-server-value", incomeLabel);
						}

						if (typeof latestEstimatedIncomeLabel !== "undefined") {
							latestEstimatedIncomeLabel = incomeLabel;
						}

						if (typeof incomeForecastChart !== "undefined" && incomeForecastChart) {
							incomeForecastChart.data.datasets[0].data = [income, totalCost, netProfit];
							incomeForecastChart.update();
						}

						if (dayRange && activePlantingProfile) {
							updateDashboard(Number(dayRange.value || 1));
						}
					})
					.catch(function () {
						isForecastIncomeSyncPending = false;
						if (dayRange && activePlantingProfile) {
							updateDashboard(Number(dayRange.value || 1));
						}
						// Keep existing local fallback behavior when snapshot is unavailable.
					});
			}

			function getEstimatedHarvestDate(profile, harvestDays) {
				if (!profile || !profile.plantingDate) {
					return null;
				}

				var plantingDate = new Date(profile.plantingDate + "T00:00:00");
				if (Number.isNaN(plantingDate.getTime())) {
					return null;
				}

				plantingDate.setDate(plantingDate.getDate() + Math.max(harvestDays - 1, 0));
				return plantingDate;
			}

			function getStageCalendarDate(profile, day) {
				if (!profile || !profile.plantingDate) {
					return null;
				}

				var plantingDate = new Date(profile.plantingDate + "T00:00:00");
				if (Number.isNaN(plantingDate.getTime())) {
					return null;
				}

				var offset = Math.max((Number(day) || 1) - 1, 0);
				plantingDate.setDate(plantingDate.getDate() + offset);
				return plantingDate;
			}

			function summaryText(value, fallback) {
				var text = String(value || "").trim();
				return text !== "" ? text : fallback;
			}

			function formatSummaryDate(value) {
				if (!value) {
					return "--";
				}

				var parsed = new Date(String(value) + "T00:00:00");
				if (Number.isNaN(parsed.getTime())) {
					return "--";
				}

				return parsed.toLocaleDateString("en-PH", {
					year: "numeric",
					month: "short",
					day: "numeric"
				});
			}

			function formatAreaSummary(profile) {
				if (!profile) {
					return "--";
				}

				var unit = String(profile.areaUnit || "").toLowerCase();
				var formatter = new Intl.NumberFormat("en-PH", { maximumFractionDigits: 2 });

				if (unit === "hectares") {
					var hectares = toPositiveNumber(profile.areaPlanted);
					if (hectares > 0) {
						return formatter.format(hectares) + " hectare(s)";
					}
				}

				var areaSqM = toAreaSqM(profile);
				if (areaSqM > 0) {
					return formatter.format(areaSqM) + " sq m";
				}

				return "--";
			}

			function formatNumberValue(value, fallback, maxFractionDigits) {
				var numeric = toPositiveNumber(value);
				if (!numeric) {
					return fallback;
				}
				return numeric.toLocaleString("en-PH", {
					maximumFractionDigits: typeof maxFractionDigits === "number" ? maxFractionDigits : 0
				});
			}

			function formatCurrency(value) {
				if (typeof value !== "number" || Number.isNaN(value)) {
					return "--";
				}
				return new Intl.NumberFormat("en-PH", {
					style: "currency",
					currency: "PHP",
					maximumFractionDigits: 0
				}).format(value);
			}

			function parseCurrencyInput(value) {
				var cleaned = String(value == null ? "" : value).replace(/[^0-9.-]/g, "").trim();
				if (cleaned === "" || cleaned === "-" || cleaned === "." || cleaned === "-.") {
					return null;
				}
				var numeric = Number(cleaned);
				return Number.isFinite(numeric) ? numeric : null;
			}

			function formatSignedCurrency(value) {
				if (typeof value !== "number" || Number.isNaN(value)) {
					return "--";
				}
				var prefix = value > 0 ? "+" : value < 0 ? "-" : "";
				return prefix + formatCurrency(Math.abs(value));
			}

			function formatAccuracyValue(value) {
				if (typeof value !== "number" || Number.isNaN(value)) {
					return "--";
				}
				return Math.max(0, Math.round(value * 10) / 10).toFixed(1) + "%";
			}

			function calculateAccuracyPercent(predicted, actual) {
				if (typeof predicted !== "number" || Number.isNaN(predicted) || predicted <= 0) {
					return null;
				}
				if (typeof actual !== "number" || Number.isNaN(actual)) {
					return null;
				}
				return Math.max(0, 100 - (Math.abs(actual - predicted) / Math.abs(predicted)) * 100);
			}

			function getSummaryStepText(stepNumber) {
				if (stepNumber === 2) {
					return "Prediction vs actual harvest results.";
				}
				if (stepNumber === 3) {
					return "Start a fresh planting run for the next cycle.";
				}
				return "Congratulations! You successfully finished the cycle.";
			}

			function showSummaryStep(stepNumber) {
				summaryCurrentStep = Math.min(Math.max(stepNumber, 1), 3);

				if (summaryStepButtons && summaryStepButtons.length) {
					for (var i = 0; i < summaryStepButtons.length; i += 1) {
						var stepBtn = summaryStepButtons[i];
						var targetStep = Number(stepBtn.getAttribute("data-summary-step") || 1);
						stepBtn.classList.toggle("active", targetStep === summaryCurrentStep);
						stepBtn.classList.toggle("completed", targetStep < summaryCurrentStep);
					}
				}

				if (summaryStepOne) {
					summaryStepOne.classList.toggle("d-none", summaryCurrentStep !== 1);
				}
				if (summaryStepTwo) {
					summaryStepTwo.classList.toggle("d-none", summaryCurrentStep !== 2);
				}
				if (summaryStepThree) {
					summaryStepThree.classList.toggle("d-none", summaryCurrentStep !== 3);
				}

				if (summaryPrevBtn) {
					summaryPrevBtn.disabled = summaryCurrentStep === 1;
				}
				if (summaryNextBtn) {
					summaryNextBtn.classList.toggle("d-none", summaryCurrentStep === 3);
					summaryNextBtn.textContent = summaryCurrentStep === 1 ? "Continue" : "Finish Review";
				}
				if (summaryNewCycleBtn) {
					summaryNewCycleBtn.classList.toggle("d-none", summaryCurrentStep !== 3);
				}
				if (summaryModalSub) {
					summaryModalSub.textContent = getSummaryStepText(summaryCurrentStep);
				}
			}

			function renderYieldComparison() {
				var predictedIncome = Number(summaryForecastValues.income);
				var predictedCost = Number(summaryForecastValues.cost);
				var predictedProfit = Number(summaryForecastValues.profit);
				var actualIncome = parseCurrencyInput(summaryActualIncomeInput ? summaryActualIncomeInput.value : "");
				var actualCost = predictedCost;
				var actualProfit = typeof actualIncome === "number" && !Number.isNaN(actualIncome)
					? actualIncome - actualCost
					: null;

				if (summaryPredictedIncomeValue) {
					summaryPredictedIncomeValue.textContent = formatCurrency(predictedIncome);
				}
				if (summaryPredictedCostValue) {
					summaryPredictedCostValue.textContent = formatCurrency(predictedCost);
				}
				if (summaryPredictedProfitValue) {
					summaryPredictedProfitValue.textContent = formatCurrency(predictedProfit);
				}
				if (summaryActualCostValue) {
					summaryActualCostValue.textContent = formatCurrency(actualCost);
				}
				if (summaryActualProfitValue) {
					summaryActualProfitValue.textContent = actualProfit === null ? "--" : formatCurrency(actualProfit);
				}

				var metrics = [
					{
						actual: actualIncome,
						predicted: predictedIncome,
						differenceEl: summaryIncomeDifferenceValue,
						accuracyEl: summaryIncomeAccuracyValue,
						label: "income"
					},
					{
						actual: actualCost,
						predicted: predictedCost,
						differenceEl: summaryCostDifferenceValue,
						accuracyEl: summaryCostAccuracyValue,
						label: "cost"
					},
					{
						actual: actualProfit,
						predicted: predictedProfit,
						differenceEl: summaryProfitDifferenceValue,
						accuracyEl: summaryProfitAccuracyValue,
						label: "profit"
					}
				];

				var accuracyTotal = 0;
				var accuracyCount = 0;
				var biggestVarianceLabel = "--";
				var biggestVarianceValue = -1;
				var allFilled = true;
				var positiveResults = 0;
				var resultSummary = "Enter the actual income to compare this harvest.";

				for (var i = 0; i < metrics.length; i += 1) {
					var metric = metrics[i];
					if (typeof metric.actual !== "number" || Number.isNaN(metric.actual)) {
						allFilled = false;
						if (metric.differenceEl) {
							metric.differenceEl.textContent = "--";
						}
						if (metric.accuracyEl) {
							metric.accuracyEl.textContent = "--";
						}
						continue;
					}

					var difference = metric.actual - metric.predicted;
					var accuracy = calculateAccuracyPercent(metric.predicted, metric.actual);
					if (metric.differenceEl) {
						metric.differenceEl.textContent = formatSignedCurrency(difference);
					}
					if (metric.accuracyEl) {
						metric.accuracyEl.textContent = accuracy === null ? "--" : formatAccuracyValue(accuracy);
					}

					if (accuracy !== null) {
						accuracyTotal += accuracy;
						accuracyCount += 1;
					}

					var variance = Math.abs(difference);
					if (variance > biggestVarianceValue) {
						biggestVarianceValue = variance;
						biggestVarianceLabel = metric.label;
					}

					if (difference >= 0) {
						positiveResults += 1;
					}
				}

				if (yieldAverageAccuracyValue) {
					yieldAverageAccuracyValue.textContent = accuracyCount > 0 ? formatAccuracyValue(accuracyTotal / accuracyCount) : "--";
				}
				if (yieldBiggestVarianceValue) {
					yieldBiggestVarianceValue.textContent = biggestVarianceValue >= 0 && biggestVarianceLabel !== "--"
						? formatCurrency(biggestVarianceValue) + " on " + biggestVarianceLabel
						: "--";
				}
				if (yieldOverallResultValue) {
					if (allFilled) {
						if (positiveResults >= 2) {
							resultSummary = "Good match: actual results are close to or above forecast.";
						} else {
							resultSummary = "Review needed: actual results are below forecast on most metrics.";
						}
					} else {
						resultSummary = "Enter the actual income to compare this harvest.";
					}
					yieldOverallResultValue.textContent = resultSummary;
				}
				if (yieldComparisonNote) {
					yieldComparisonNote.textContent = allFilled
						? "Total cost is mirrored from your planting records, while profit is auto-calculated from the actual income you entered."
						: "Enter the actual income to see the difference and accuracy of this cycle.";
				}
			}

			function getActualDayFromProfile(profile, harvestDays) {
				if (!profile || !profile.plantingDate) {
					return null;
				}

				var plantingDate = new Date(profile.plantingDate + "T00:00:00");
				if (Number.isNaN(plantingDate.getTime())) {
					return null;
				}

				var today = new Date();
				today.setHours(0, 0, 0, 0);
				plantingDate.setHours(0, 0, 0, 0);
				var elapsedDays = Math.floor((today - plantingDate) / 86400000) + 1;
				if (!Number.isFinite(elapsedDays)) {
					return null;
				}
				if (elapsedDays < 1) {
					elapsedDays = 1;
				}
				return elapsedDays;
			}

			var stageDefinitions = buildStageDefinitions(defaultHarvestDays);
			var latestTotalCostValue = <?php echo json_encode((float) $latestTotalCostValue); ?>;
			var allCompletedTasks = <?php echo json_encode((int) $allCompletedTasks); ?>;
			var allPendingTasks = <?php echo json_encode((int) $allPendingTasks); ?>;
			var marketLastUpdatedLabel = <?php echo json_encode($marketLastUpdatedRaw !== "" ? date('M d, Y', strtotime($marketLastUpdatedRaw)) : 'No update date'); ?>;
			var latestPestDetectionLabel = <?php echo json_encode($recentPestLabel); ?>;
			var calendarUpdatedLabel = <?php echo json_encode($recentCalendarLabel); ?>;

			var featureCards = [
				{
					title: "Corn Planting Profile",
					description: "Record your corn type, variety, planting date, and field area details.",
					tag: "Profile Setup",
					bg: "linear-gradient(145deg, rgba(127,182,133,0.1), rgba(127,182,133,0.03))",
					iconBg: "rgba(127,182,133,0.18)",
					iconColor: "#5f9a65",
					bar: "#7fb685",
					blur: "rgba(127,182,133,0.2)",
					icon: "plant"
				},
				{
					title: "Lifecycle Stage Tracker",
					description: "Monitor stage-by-stage crop progress from seed emergence up to harvest.",
					tag: "Growth Tracking",
					bg: "linear-gradient(145deg, rgba(255,229,153,0.28), rgba(255,229,153,0.08))",
					iconBg: "rgba(255,229,153,0.55)",
					iconColor: "#8a6821",
					bar: "#d9b24a",
					blur: "rgba(255,229,153,0.3)",
					icon: "leaf"
				},
				{
					title: "Corn Care Calendar",
					description: "Plan and manage watering, fertilization, and routine field activities.",
					tag: "Task Planning",
					bg: "linear-gradient(145deg, rgba(127,182,133,0.1), rgba(127,182,133,0.03))",
					iconBg: "rgba(127,182,133,0.18)",
					iconColor: "#5f9a65",
					bar: "#7fb685",
					blur: "rgba(127,182,133,0.2)",
					icon: "calendar"
				},
				{
					title: "Corn Farming Guide",
					description: "Browse practical guides, best practices, and expert corn farming tips.",
					tag: "Knowledge Base",
					bg: "linear-gradient(145deg, rgba(255,229,153,0.28), rgba(255,229,153,0.08))",
					iconBg: "rgba(255,229,153,0.55)",
					iconColor: "#8a6821",
					bar: "#d9b24a",
					blur: "rgba(255,229,153,0.3)",
					icon: "book"
				},
				{
					title: "Pest & Disease Identification",
					description: "Detect common pests and diseases, then view suggested treatment actions.",
					tag: "Crop Scanner",
					bg: "linear-gradient(145deg, rgba(127,182,133,0.1), rgba(127,182,133,0.03))",
					iconBg: "rgba(127,182,133,0.18)",
					iconColor: "#5f9a65",
					bar: "#7fb685",
					blur: "rgba(127,182,133,0.2)",
					icon: "bug"
				},
				{
					title: "Growth and Harvest Forecasting",
					description: "Use model-based forecasts for growth trend, yield outlook, and harvest window.",
					tag: "Forecast",
					bg: "linear-gradient(145deg, rgba(255,229,153,0.28), rgba(255,229,153,0.08))",
					iconBg: "rgba(255,229,153,0.55)",
					iconColor: "#8a6821",
					bar: "#d9b24a",
					blur: "rgba(255,229,153,0.3)",
					icon: "ai"
				}
			];

			var dayRange = document.getElementById("growthDayRange");
			var currentDayLabel = document.getElementById("currentDayLabel");
			var stageName = document.getElementById("stageName");
			var stageDays = document.getElementById("stageDays");
			var stageDateLabel = document.getElementById("stageDateLabel");
			var cropTagText = document.getElementById("cropTagText");
			var growthProgressValue = document.getElementById("growthProgressValue");
			var growthBadge = document.getElementById("growthBadge");
			var estimatedHarvestDateValue = document.getElementById("estimatedHarvestDateValue");
			var estimatedIncomeValue = document.getElementById("estimatedIncomeValue");
			var daysUntilHarvestValue = document.getElementById("daysUntilHarvestValue");
			var tasksValue = document.getElementById("tasksValue");
			var completedValue = document.getElementById("completedValue");
			var pendingValue = document.getElementById("pendingValue");
			var tasksSummaryText = document.getElementById("tasksSummaryText");
			var tasksEmptyHint = document.getElementById("tasksEmptyHint");
			var growthProgressSubtext = document.getElementById("growthProgressSubtext");
			var harvestStatus = document.getElementById("harvestStatus");
			var profileSetupNoteWrap = document.getElementById("profileSetupNoteWrap");
			var profileSetupNote = document.getElementById("profileSetupNote");
			var activePlantingProfile = null;
			var syncedMachineLearningIncome = null;
			var isForecastIncomeSyncPending = true;

			var overallTrack = document.getElementById("overallTrack");

			var plantStem = document.getElementById("plantStem");
			var leaf1 = document.getElementById("leaf1");
			var leaf2 = document.getElementById("leaf2");
			var leaf3 = document.getElementById("leaf3");
			var tassel = document.getElementById("tassel");
			var cob = document.getElementById("cob");
			var harvestStack = document.getElementById("harvestStack");

			function getStage(day) {
				for (var i = 0; i < stageDefinitions.length; i += 1) {
					if (day <= stageDefinitions[i].max) {
						return stageDefinitions[i];
					}
				}
				return stageDefinitions[stageDefinitions.length - 1];
			}

			function getStageIndex(day) {
				for (var i = 0; i < stageDefinitions.length; i += 1) {
					if (day <= stageDefinitions[i].max) {
						return i;
					}
				}
				return stageDefinitions.length - 1;
			}

			function getStats(day) {
				var progressRatio = day / selectedHarvestDays;
				var growthProgress = Math.min(Math.round(progressRatio * 100), 100);
				var daysUntilHarvest = Math.max(selectedHarvestDays - day, 0);
				var totalTasks = progressRatio <= 0.2 ? 15 : progressRatio <= 0.55 ? 24 : progressRatio <= 0.85 ? 28 : 20;
				var completed = Math.round(totalTasks * (progressRatio >= 1 ? 1 : 0.75));
				var pending = totalTasks - completed;
				var status = progressRatio >= 1 ? "Ready to harvest" : progressRatio >= 0.85 ? "Nearing harvest" : "On track";
				var badge = progressRatio <= 0.2 ? "+12%" : progressRatio <= 0.55 ? "+8%" : progressRatio <= 0.85 ? "+5%" : "+2%";

				return {
					growthProgress: growthProgress,
					daysUntilHarvest: daysUntilHarvest,
					totalTasks: totalTasks,
					completed: completed,
					pending: pending,
					status: status,
					badge: badge
				};
			}

			function applyPlantVisual(stageVisual) {
				leaf1.style.display = "none";
				leaf2.style.display = "none";
				leaf3.style.display = "none";
				tassel.style.display = "none";
				cob.style.display = "none";
				harvestStack.style.display = "none";
				plantStem.style.display = "none";
				plantStem.style.background = "linear-gradient(180deg, #6bb174, #4c9657)";

				if (stageVisual === "seed") {
					return;
				}

				if (stageVisual === "sprout") {
					plantStem.style.display = "block";
					plantStem.style.height = "45px";
					leaf1.style.display = "block";
					leaf1.style.height = "28px";
					leaf1.style.width = "14px";
					leaf1.style.left = "58px";
					leaf1.style.bottom = "58px";
					leaf2.style.display = "block";
					leaf2.style.height = "28px";
					leaf2.style.width = "14px";
					leaf2.style.right = "58px";
					leaf2.style.bottom = "58px";
					return;
				}

				if (stageVisual === "seedling") {
					plantStem.style.display = "block";
					plantStem.style.height = "78px";
					leaf1.style.display = "block";
					leaf2.style.display = "block";
					return;
				}

				if (stageVisual === "vegetative") {
					plantStem.style.display = "block";
					plantStem.style.height = "105px";
					leaf1.style.display = "block";
					leaf2.style.display = "block";
					leaf3.style.display = "block";
					return;
				}

				if (stageVisual === "tasseling") {
					plantStem.style.display = "block";
					plantStem.style.height = "108px";
					leaf1.style.display = "block";
					leaf2.style.display = "block";
					leaf3.style.display = "block";
					tassel.style.display = "block";
					return;
				}

				if (stageVisual === "silking") {
					plantStem.style.display = "block";
					plantStem.style.height = "108px";
					leaf1.style.display = "block";
					leaf2.style.display = "block";
					leaf3.style.display = "block";
					tassel.style.display = "block";
					cob.style.display = "block";
					return;
				}

				if (stageVisual === "mature") {
					plantStem.style.display = "block";
					plantStem.style.height = "108px";
					plantStem.style.background = "linear-gradient(180deg, #6f8f74, #4f7757)";
					leaf1.style.display = "block";
					leaf2.style.display = "block";
					leaf3.style.display = "block";
					tassel.style.display = "block";
					cob.style.display = "block";
					return;
				}

				if (stageVisual === "harvest") {
					harvestStack.style.display = "flex";
				}
			}

			function updateOverallTrack(day) {
				var idx = getStageIndex(day);
				var trackPoints = overallTrack.querySelectorAll("span");
				for (var i = 0; i < trackPoints.length; i += 1) {
					trackPoints[i].classList.remove("active");
					trackPoints[i].classList.remove("current");
					if (i < idx) {
						trackPoints[i].classList.add("active");
					} else if (i === idx) {
						trackPoints[i].classList.add("current");
					}
				}
			}

			function renderOverallTrack() {
				overallTrack.innerHTML = "";
				overallTrack.style.gridTemplateColumns = "repeat(" + stageDefinitions.length + ", minmax(0, 1fr))";
				for (var i = 0; i < stageDefinitions.length; i += 1) {
					var point = document.createElement("span");
					point.title = stageDefinitions[i].stage;
					overallTrack.appendChild(point);
				}
			}

			function goToCornPlantingProfile() {
				var params = new URLSearchParams(window.location.search);
				var profileUrl = params.get("view") === "features"
					? "corn_planting_profile.php?from=features"
					: "corn_planting_profile.php";
				window.location.href = profileUrl;
			}

			function hasPlantingProfileData(profile) {
				if (!profile || typeof profile !== "object") {
					return false;
				}

				return String(profile.plantingDate || "").trim() !== "";
			}

			function setEmptyDashboardState() {
				currentDayLabel.textContent = "--";
				stageName.textContent = "No planting profile yet";
				stageDays.textContent = "Create your profile to start tracking.";
				if (stageDateLabel) {
					stageDateLabel.textContent = "--";
				}

				growthProgressValue.textContent = "--";
				growthBadge.textContent = "--";
				daysUntilHarvestValue.textContent = "--";
				tasksValue.textContent = "--";
				completedValue.textContent = "--";
				pendingValue.textContent = "--";
				harvestStatus.textContent = "No profile yet";

				estimatedHarvestDateValue.textContent = "--";
				estimatedIncomeValue.textContent = "--";

				if (growthProgressSubtext) {
					growthProgressSubtext.textContent = "Complete your planting profile to see season progress.";
				}

				if (tasksSummaryText) {
					tasksSummaryText.classList.add("d-none");
				}

				if (tasksEmptyHint) {
					tasksEmptyHint.classList.remove("d-none");
				}

				harvestStatus.style.color = "#6b7280";
				cropTagText.textContent = "No planting profile yet";
				applyPlantVisual("seed");
				updateOverallTrack(0);

				if (profileSetupNoteWrap) {
					profileSetupNoteWrap.classList.remove("d-none");
				}

				if (dayRange) {
					dayRange.value = "1";
					dayRange.disabled = true;
				}

				latestStageVisual = "seed";
				latestStageName = "No profile yet";
				latestHarvestDateLabel = "--";
				latestEstimatedIncomeLabel = "--";
				latestTaskSummaryLabel = "--";
				latestGrowthSummaryLabel = "--";
				latestDaysUntilHarvestLabel = "--";
			}

			function updateDashboard(day) {
				if (!hasPlantingProfileData(activePlantingProfile)) {
					setEmptyDashboardState();
					return;
				}

				if (profileSetupNoteWrap) {
					profileSetupNoteWrap.classList.add("d-none");
				}

				if (dayRange) {
					dayRange.disabled = false;
				}

				var stage = getStage(day);
				var stats = getStats(day);
				var stageLabel = "Stage " + String(stage.number || (getStageIndex(day) + 1)) + " - " + String(stage.stage || "");

				currentDayLabel.textContent = String(day);
				stageName.textContent = stageLabel;
				stageDays.textContent = stage.days;
				if (stageDateLabel) {
					var stageDate = getStageCalendarDate(activePlantingProfile, day);
					stageDateLabel.textContent = formatDateLabel(stageDate);
				}

				growthProgressValue.textContent = String(stats.growthProgress);
				growthBadge.textContent = stats.badge;
				daysUntilHarvestValue.textContent = String(stats.daysUntilHarvest);
				tasksValue.textContent = String(stats.totalTasks);
				var allTasksTotal = Number(allCompletedTasks) + Number(allPendingTasks);
				tasksValue.textContent = String(allTasksTotal);
				completedValue.textContent = String(allCompletedTasks);
				pendingValue.textContent = String(allPendingTasks);
				harvestStatus.textContent = stats.status;

				var estimatedHarvestDate = getEstimatedHarvestDate(activePlantingProfile, selectedHarvestDays);
				estimatedHarvestDateValue.textContent = formatDateLabel(estimatedHarvestDate);

				var estimatedIncome = getEstimatedIncomeValue(activePlantingProfile);
				var serverIncomeFallback = estimatedIncomeValue && estimatedIncomeValue.getAttribute("data-server-value") ? estimatedIncomeValue.getAttribute("data-server-value") : "--";
				var incomeLabel = serverIncomeFallback !== "" ? serverIncomeFallback : "--";
				if (isForecastIncomeSyncPending) {
					estimatedIncomeValue.textContent = "--";
				} else if (estimatedIncome === null) {
					estimatedIncomeValue.textContent = incomeLabel;
				} else {
					incomeLabel = new Intl.NumberFormat("en-PH", {
						style: "currency",
						currency: "PHP",
						maximumFractionDigits: 0
					}).format(estimatedIncome);
					estimatedIncomeValue.textContent = incomeLabel;
				}

				if (stats.status === "Ready to harvest") {
					harvestStatus.style.color = "#198754";
				} else if (stats.status === "Nearing harvest") {
					harvestStatus.style.color = "#ea580c";
				} else {
					harvestStatus.style.color = "#495a4c";
				}

				applyPlantVisual(stage.visual);
				updateOverallTrack(day);

				if (growthProgressSubtext) {
					growthProgressSubtext.textContent = "Above average for season";
				}

				if (tasksSummaryText) {
					tasksSummaryText.classList.remove("d-none");
				}

				if (tasksEmptyHint) {
					tasksEmptyHint.classList.add("d-none");
				}

				latestStageVisual = stage.visual;
				latestStageName = stageLabel;
				latestHarvestDateLabel = estimatedHarvestDateValue.textContent || "--";
				latestEstimatedIncomeLabel = incomeLabel;
				latestTaskSummaryLabel = String(allTasksTotal) + " (" + String(allCompletedTasks) + " completed, " + String(allPendingTasks) + " pending)";
				latestGrowthSummaryLabel = String(stats.growthProgress) + "%";
				latestDaysUntilHarvestLabel = String(stats.daysUntilHarvest) + " day" + (stats.daysUntilHarvest === 1 ? "" : "s");
			}

			function closeSummaryModal() {
				if (!summaryModal || !summaryModalMask) {
					return;
				}

				summaryModalMask.classList.remove("show");
				summaryModal.classList.remove("show");
			}

			function openSummaryHistory() {
				if (!summaryHistoryModal) {
					return;
				}

				summaryHistoryModal.classList.add("show");
				if (summaryLockedState) {
					summaryLockedState.classList.add("d-none");
				}
				if (summaryReadyState) {
					summaryReadyState.classList.add("d-none");
				}
				if (summaryStagePill) {
					summaryStagePill.classList.add("d-none");
				}
				if (summaryModalSub) {
					summaryModalSub.textContent = "Completed cycle records loaded from saved summary files.";
				}
			}

			function closeSummaryHistory() {
				if (!summaryHistoryModal) {
					return;
				}

				summaryHistoryModal.classList.remove("show");
				if (summaryStagePill) {
					summaryStagePill.classList.remove("d-none");
				}

				var hasProfile = hasPlantingProfileData(activePlantingProfile);
				var harvestDays = selectedHarvestDays || defaultHarvestDays;
				var actualDay = hasProfile ? getActualDayFromProfile(activePlantingProfile, harvestDays) : null;
				var isHarvestStage = hasProfile && actualDay !== null && actualDay >= harvestDays;

				if (summaryLockedState) {
					summaryLockedState.classList.toggle("d-none", isHarvestStage);
				}
				if (summaryReadyState) {
					summaryReadyState.classList.toggle("d-none", !isHarvestStage);
				}
				if (summaryModalSub) {
					summaryModalSub.textContent = summaryCurrentStep === 1
						? getSummaryStepText(summaryCurrentStep)
						: (summaryCurrentStep === 2 ? getSummaryStepText(2) : getSummaryStepText(3));
				}
			}

			function openSummaryModal() {
				if (!summaryModal || !summaryModalMask) {
					return;
				}

				var hasProfile = hasPlantingProfileData(activePlantingProfile);
				var harvestDays = selectedHarvestDays || defaultHarvestDays;
				var actualDay = hasProfile ? getActualDayFromProfile(activePlantingProfile, harvestDays) : null;
				var clampedDay = actualDay ? clamp(actualDay, 1, harvestDays) : 1;
				var stage = hasProfile ? getStage(clampedDay) : null;
				var stats = hasProfile ? getStats(clampedDay) : null;
				var stageLabel = hasProfile && stage
					? "Stage " + String(stage.number || (getStageIndex(clampedDay) + 1)) + " - " + String(stage.stage || "")
					: "No profile yet";
				var isHarvestStage = hasProfile && actualDay !== null && actualDay >= harvestDays;

				if (summaryModalSub) {
					summaryModalSub.textContent = isHarvestStage
						? getSummaryStepText(1)
						: "This report unlocks automatically once your crop reaches Harvest stage.";
				}

				if (summaryHeroBadge) {
					summaryHeroBadge.textContent = isHarvestStage ? "Harvest Ready" : "Locked Report";
				}

				if (summaryStagePill) {
					summaryStagePill.textContent = isHarvestStage ? "Harvest" : "Locked";
					summaryStagePill.classList.toggle("summary-stage-pill-ready", isHarvestStage);
					summaryStagePill.classList.toggle("summary-stage-pill-locked", !isHarvestStage);
				}

				if (summaryLockedState) {
					summaryLockedState.classList.toggle("d-none", isHarvestStage);
				}


				if (!isHarvestStage) {
					if (summaryLockedStageValue) {
						summaryLockedStageValue.textContent = stageLabel;
					}
					if (summaryLockedGrowthValue) {
						summaryLockedGrowthValue.textContent = stats ? String(stats.growthProgress) + "%" : "--";
					}
					if (summaryLockedDaysValue) {
						if (stats) {
							summaryLockedDaysValue.textContent = String(stats.daysUntilHarvest) + " day" + (stats.daysUntilHarvest === 1 ? "" : "s");
						} else {
							summaryLockedDaysValue.textContent = "--";
						}
					}
				}

				if (summaryReadyState) {
					summaryReadyState.classList.toggle("d-none", !isHarvestStage);
				}

				if (isHarvestStage) {
					var estimatedIncome = getEstimatedIncomeValue(activePlantingProfile);
					var forecastIncome = typeof estimatedIncome === "number" && !Number.isNaN(estimatedIncome)
						? estimatedIncome
						: parseCurrencyInput(latestEstimatedIncomeLabel);
					if (typeof forecastIncome !== "number" || Number.isNaN(forecastIncome)) {
						forecastIncome = 0;
					}
					var forecastCost = Number(latestTotalCostValue || 0);
					var forecastProfit = forecastIncome - forecastCost;

					summaryForecastValues.income = forecastIncome;
					summaryForecastValues.cost = forecastCost;
					summaryForecastValues.profit = forecastProfit;

					if (summaryActualIncomeInput) {
						summaryActualIncomeInput.value = "";
					}

					if (summaryCurrentStageValue) {
						summaryCurrentStageValue.textContent = stageLabel;
					}
					if (summaryPlantingDateValue) {
						summaryPlantingDateValue.textContent = formatSummaryDate(activePlantingProfile.plantingDate);
					}
					if (summaryHarvestDateValue) {
						summaryHarvestDateValue.textContent = formatDateLabel(getEstimatedHarvestDate(activePlantingProfile, harvestDays));
					}
					if (summaryCornTypeValue) {
						summaryCornTypeValue.textContent = summaryText(activePlantingProfile.typeOfCorn || activePlantingProfile.corn_type, "--");
					}
					if (summaryCornVarietyValue) {
						summaryCornVarietyValue.textContent = summaryText(activePlantingProfile.cornVariety || activePlantingProfile.corn_variety, "--");
					}
					if (summaryFarmLocationValue) {
						summaryFarmLocationValue.textContent = summaryText(activePlantingProfile.farmLocation || activePlantingProfile.farm_location, "--");
					}
					if (summaryAreaValue) {
						summaryAreaValue.textContent = formatAreaSummary(activePlantingProfile);
					}
					if (summarySoilTypeValue) {
						summarySoilTypeValue.textContent = summaryText(activePlantingProfile.soilType || activePlantingProfile.soil_type, "--");
					}
					if (summaryPlantingDensityValue) {
						summaryPlantingDensityValue.textContent = formatNumberValue(activePlantingProfile.plantingDensity || activePlantingProfile.planting_density, "--", 0);
					}
					if (summarySeedsPerHoleValue) {
						summarySeedsPerHoleValue.textContent = formatNumberValue(activePlantingProfile.seedsPerHole || activePlantingProfile.seeds_per_hole, "--", 0);
					}
					if (summaryEstimatedSeedsValue) {
						summaryEstimatedSeedsValue.textContent = summaryText(activePlantingProfile.estimatedSeeds || activePlantingProfile.estimated_seeds_range, "--");
					}

					var incomeLabel = typeof forecastIncome === "number" && !Number.isNaN(forecastIncome)
						? formatCurrency(forecastIncome)
						: latestEstimatedIncomeLabel;
					if (summaryIncomeValue) {
						summaryIncomeValue.textContent = incomeLabel || "--";
					}
					if (summaryTotalCostValue) {
						summaryTotalCostValue.textContent = formatCurrency(forecastCost);
					}
					if (summaryNetProfitValue) {
						summaryNetProfitValue.textContent = formatCurrency(forecastProfit);
					}
					if (summaryTasksValue) {
						summaryTasksValue.textContent = String(allCompletedTasks + allPendingTasks);
					}
					if (summaryTasksOverallValue) {
						summaryTasksOverallValue.textContent = String(allCompletedTasks) + " completed, " + String(allPendingTasks) + " pending";
					}
					if (summaryGrowthValue) {
						summaryGrowthValue.textContent = stats ? String(stats.growthProgress) + "%" : "--";
					}
					if (summaryMarketUpdatedValue) {
						summaryMarketUpdatedValue.textContent = summaryText(marketLastUpdatedLabel, "--");
					}
					if (summaryPestDetectionValue) {
						summaryPestDetectionValue.textContent = summaryText(latestPestDetectionLabel, "--");
					}
					if (summaryCalendarUpdatedValue) {
						summaryCalendarUpdatedValue.textContent = summaryText(calendarUpdatedLabel, "--");
					}
					if (summaryHeroStageValue) {
						summaryHeroStageValue.textContent = stageLabel;
					}
					if (summaryHeroProgressValue) {
						summaryHeroProgressValue.textContent = stats ? String(stats.growthProgress) + "%" : "--";
					}
					if (summaryHeroTasksValue) {
						summaryHeroTasksValue.textContent = stats ? String(stats.completed) + "/" + String(stats.totalTasks) : "--";
					}
					if (summaryHeroProfitValue) {
						summaryHeroProfitValue.textContent = formatCurrency(forecastProfit);
					}

					renderYieldComparison();
					showSummaryStep(1);
				}

				summaryModalMask.classList.add("show");
				summaryModal.classList.add("show");
			}

			dayRange.addEventListener("input", function () {
				updateDashboard(Number(dayRange.value));
			});

			function iconSvg(type) {
				if (type === "plant") {
					return '<svg viewBox="0 0 24 24"><path d="M12 2c-1.4 1.4-3 3.8-3 6.2 0 1.6.7 3 1.8 4H8a4 4 0 0 0-4 4v1h8v5h2v-5h8v-1a4 4 0 0 0-4-4h-2.8c1.1-1 1.8-2.4 1.8-4C17 5.8 15.4 3.4 14 2h-2z"></path></svg>';
				}
				if (type === "leaf") {
					return '<svg viewBox="0 0 24 24"><path d="M19.5 3.5C11 4.4 4.4 11 3.5 19.5c6.5-.7 12.7-3.9 16-9.1 1.2-1.8 1.8-3.9 2-6.9zM8 16c2.7-1.1 5-3 7-5.8"></path></svg>';
				}
				if (type === "calendar") {
					return '<svg viewBox="0 0 24 24"><path d="M7 2h2v2h6V2h2v2h3a2 2 0 0 1 2 2v13a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V6a2 2 0 0 1 2-2h3V2zm13 8H4v9a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-9z"></path></svg>';
				}
				if (type === "book") {
					return '<svg viewBox="0 0 24 24"><path d="M4 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v16a1 1 0 0 1-1.4.9L12 18.2 5.4 20.9A1 1 0 0 1 4 20V4zm3 2v10.9l5-2.1 5 2.1V6H7z"></path></svg>';
				}
				if (type === "bug") {
					return '<svg viewBox="0 0 24 24"><path d="M14 5.1V4a2 2 0 1 0-4 0v1.1A5 5 0 0 0 7 9v1H5l-2-2-1.4 1.4L3.2 11 1.6 12.6 3 14l2-2h2v2H5l-2 2 1.4 1.4L5 16h2v1a5 5 0 1 0 10 0v-1h2l2 2 1.4-1.4-1.6-1.6 1.6-1.6L21 12l-2 2h-2v-2h2l2-2-1.4-1.4L19 10h-2V9a5 5 0 0 0-3-3.9zM9 9a3 3 0 1 1 6 0v8a3 3 0 1 1-6 0V9z"></path></svg>';
				}
				return '<svg viewBox="0 0 24 24"><path d="M4 6h16v10H4zM2 18h20v2H2zm5-8h2v4H7zm4-2h2v6h-2zm4 3h2v3h-2z"></path></svg>';
			}

			var featureCardsRow = document.getElementById("featureCardsRow");
			function handleFeatureCardNavigation(title) {
				if (title === "Corn Planting Profile") {
					window.location.href = "corn_planting_profile.php?from=features";
					return;
				}

				if (title === "Lifecycle Stage Tracker") {
					window.location.href = "lifecycle_stage_tracker.php?from=features";
					return;
				}

				if (title === "Corn Care Calendar") {
					window.location.href = "corn_care_calendar.php?from=features";
					return;
				}

				if (title === "Corn Farming Guide") {
					window.location.href = "corn_farming_guide.php?from=features";
					return;
				}

				if (title === "Pest & Disease Identification") {
					window.location.href = "pest_disease_detection.php?from=features";
					return;
				}

				if (title === "Growth and Harvest Forecasting") {
					window.location.href = "machine_learning.php?from=features";
					return;
				}
			}

			for (var i = 0; i < featureCards.length; i += 1) {
				var f = featureCards[i];
				var col = document.createElement("div");
				col.className = "col-6 col-md-6 col-xl-4";
				col.innerHTML = '' +
					'<article class="feature-card" style="background:' + f.bg + ';">' +
						'<div class="blur-orb" style="background:' + f.blur + ';"></div>' +
						'<div class="body">' +
							'<span class="feature-tag">' + f.tag + '</span>' +
							'<span class="feature-icon" style="background:' + f.iconBg + '; color:' + f.iconColor + ';">' + iconSvg(f.icon) + '</span>' +
							'<div class="feature-title">' + f.title + '</div>' +
							'<div class="feature-desc">' + f.description + '</div>' +
							'<div class="feature-cta">Open Module <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7-1.5-1.5 5.5-5.5-5.5-5.5z"></path></svg></div>' +
							'<div class="feature-bar" style="background:' + f.bar + ';"></div>' +
						'</div>' +
					'</article>';
				featureCardsRow.appendChild(col);

				(function () {
					var featureTitle = f.title;
					var card = col.querySelector(".feature-card");
					card.setAttribute("role", "button");
					card.setAttribute("tabindex", "0");
					card.setAttribute("aria-label", featureTitle);

					card.addEventListener("click", function () {
						handleFeatureCardNavigation(featureTitle);
					});

					card.addEventListener("keydown", function (event) {
						if (event.key === "Enter" || event.key === " ") {
							event.preventDefault();
							handleFeatureCardNavigation(featureTitle);
						}
					});
				})();
			}

			var chartTextColor = "#6b7c6e";
			var incomeForecastChart = null;

			if (window.Chart) {
				Chart.defaults.color = chartTextColor;
				Chart.defaults.font.family = 'Inter, "Segoe UI", Tahoma, Geneva, Verdana, sans-serif';

				var incomeCtx = document.getElementById("incomeForecastChart");
				var marketCtx = document.getElementById("marketPriceTrendChart");

				if (incomeCtx) {
					var estimatedIncomeAmount = Number(getEstimatedIncomeValue(storedPlantingProfile) || 0);
					var totalCostValue = Number(latestTotalCostValue || 0);
					var netProfitValue = estimatedIncomeAmount - totalCostValue;

					incomeForecastChart = new Chart(incomeCtx, {
						type: "bar",
						data: {
							labels: ["Estimated Income", "Total Costs", "Net Profit"],
							datasets: [
								{
									data: [estimatedIncomeAmount, totalCostValue, netProfitValue],
									backgroundColor: [
										"rgba(127,182,133,0.9)",
										"rgba(217,178,74,0.88)",
										"rgba(79,139,86,0.9)"
									],
									borderColor: ["#5f9a65", "#b7891f", "#3f7646"],
									borderWidth: 1,
									borderRadius: 8,
									borderSkipped: false
								}
							]
						},
						options: {
							responsive: true,
							maintainAspectRatio: false,
							plugins: {
								legend: { display: false },
								tooltip: {
									callbacks: {
										label: function (ctx) {
											return new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP", maximumFractionDigits: 0 }).format(ctx.parsed.y || 0);
										}
									}
								}
							},
							scales: {
								y: {
									beginAtZero: true,
									grid: { color: "rgba(107,124,110,0.2)", borderDash: [3, 3] },
									ticks: {
										callback: function (value) {
											return "₱" + Number(value).toLocaleString("en-PH");
										}
									}
								},
								x: { grid: { display: false } }
							}
						}
					});
				}

				if (marketCtx) {
					var currentPricePerKg = Number(getCurrentPricePerKg(storedPlantingProfile) || 0);
					var today = new Date();
					today.setHours(0, 0, 0, 0);
					var marketLabels = [];
					var marketValues = [];

					var hasPlantingDate = !!(storedPlantingProfile && storedPlantingProfile.plantingDate);
					var chartStartDate = hasPlantingDate
						? new Date(storedPlantingProfile.plantingDate + "T00:00:00")
						: new Date(today);

					if (Number.isNaN(chartStartDate.getTime())) {
						chartStartDate = new Date(today);
					}

					var harvestDaysForChart = getHarvestDaysFromProfile(storedPlantingProfile);
					var chartHarvestDate = getEstimatedHarvestDate(storedPlantingProfile, harvestDaysForChart);
					var chartEndDate = today;

					if (chartHarvestDate instanceof Date && !Number.isNaN(chartHarvestDate.getTime())) {
						chartHarvestDate.setHours(0, 0, 0, 0);
						if (chartHarvestDate < chartEndDate) {
							chartEndDate = chartHarvestDate;
						}
					}

					if (chartStartDate > chartEndDate) {
						chartStartDate = new Date(chartEndDate);
					}

					var historyByVariety = marketPriceHistoryData && typeof marketPriceHistoryData === "object" && marketPriceHistoryData.history_by_variety
						? marketPriceHistoryData.history_by_variety
						: {};
					var activeHistoryRaw = Array.isArray(historyByVariety[activeMarketPriceKey])
						? historyByVariety[activeMarketPriceKey]
						: [];
					var activeHistory = [];
					for (var h = 0; h < activeHistoryRaw.length; h += 1) {
						var historyDate = String(activeHistoryRaw[h].date || "").trim();
						var historyPrice = Number(activeHistoryRaw[h].price);
						var historyDateObj = new Date(historyDate + "T00:00:00");
						if (historyDate !== "" && !Number.isNaN(historyDateObj.getTime()) && Number.isFinite(historyPrice) && historyPrice >= 0) {
							historyDateObj.setHours(0, 0, 0, 0);
							activeHistory.push({ date: historyDateObj, price: historyPrice });
						}
					}
					activeHistory.sort(function (a, b) {
						return a.date.getTime() - b.date.getTime();
					});

					var historyIndex = 0;
					var latestHistoryPrice = activeHistory.length > 0 ? activeHistory[0].price : currentPricePerKg;

					var cursorDate = new Date(chartStartDate);
					while (cursorDate <= chartEndDate) {
						while (historyIndex < activeHistory.length && activeHistory[historyIndex].date.getTime() <= cursorDate.getTime()) {
							latestHistoryPrice = activeHistory[historyIndex].price;
							historyIndex += 1;
						}

						marketLabels.push(cursorDate.toLocaleDateString("en-PH", {
							month: "short",
							day: "numeric"
						}));
						marketValues.push(latestHistoryPrice);
						cursorDate.setDate(cursorDate.getDate() + 1);
					}

					if (marketLabels.length === 0) {
						marketLabels = [today.toLocaleDateString("en-PH", { month: "short", day: "numeric" })];
						marketValues = [currentPricePerKg];
					}

					var marketChartScrollWrap = document.getElementById("marketChartScrollWrap");
					var marketChartInner = document.getElementById("marketChartInner");
					if (marketChartScrollWrap && marketChartInner) {
						var wrapWidth = marketChartScrollWrap.clientWidth || 700;
						var pointWidth = Math.max(56, Math.floor(wrapWidth / 10));

						if (marketLabels.length > 10) {
							marketChartInner.style.width = String(pointWidth * marketLabels.length) + "px";
							marketChartScrollWrap.style.overflowX = "auto";
						} else {
							marketChartInner.style.width = "100%";
							marketChartScrollWrap.style.overflowX = "hidden";
						}
					}

					new Chart(marketCtx, {
						type: "line",
						data: {
							labels: marketLabels,
							datasets: [
								{
									label: "Market Price per Kg",
									data: marketValues,
									borderColor: "#4f8b56",
									backgroundColor: "rgba(79,139,86,0.16)",
									fill: true,
									tension: 0.3,
									borderWidth: 2,
									pointRadius: 3,
									pointBackgroundColor: "#4f8b56",
									pointBorderColor: "#ffffff",
									pointBorderWidth: 1.5
								}
							]
						},
						options: {
							responsive: true,
							maintainAspectRatio: false,
							plugins: {
								legend: { display: false },
								tooltip: {
									callbacks: {
										label: function (ctx) {
											return "₱" + Number(ctx.parsed.y || 0).toFixed(2) + " / kg";
										}
									}
								}
							},
							scales: {
								y: {
									min: 10,
									max: 100,
									grid: { color: "rgba(107,124,110,0.2)", borderDash: [3, 3] },
									ticks: {
										stepSize: 10,
										callback: function (value) {
											return "₱" + Number(value).toFixed(0);
										}
									}
								},
								x: { grid: { display: false } }
							}
						}
					});
				}
			}

			var mainView = document.getElementById("mainDashboardView");
			var featureView = document.getElementById("featureDashboardView");
			var goToFeatures = document.getElementById("goToFeatures");
			var backToMain = document.getElementById("backToMain");

			function setDashboardView(view, syncUrl, smoothScroll) {
				var isFeatureView = view === "features";
				mainView.classList.toggle("d-none", isFeatureView);
				featureView.classList.toggle("d-none", !isFeatureView);
				goToFeatures.classList.toggle("d-none", isFeatureView);
				backToMain.classList.toggle("d-none", !isFeatureView);

				if (syncUrl) {
					var url = new URL(window.location.href);
					if (isFeatureView) {
						url.searchParams.set("view", "features");
					} else {
						url.searchParams.delete("view");
					}
					history.replaceState(null, "", url.toString());
				}

				if (smoothScroll) {
					window.scrollTo({ top: 0, behavior: "smooth" });
				}
			}

			function getRequestedDashboardView() {
				var params = new URLSearchParams(window.location.search);
				return params.get("view") === "features" ? "features" : "main";
			}

			goToFeatures.addEventListener("click", function () {
				setDashboardView("features", true, true);
			});

			backToMain.addEventListener("click", function () {
				setDashboardView("main", true, true);
			});

			setDashboardView(getRequestedDashboardView(), false, false);

			// --- WEATHER SERVICE START ---
			async function initWeather() {
				const tempEl = document.getElementById("currentTemp");
				const condEl = document.getElementById("weatherCondition");
				const humEl = document.getElementById("weatherHumidity");
				const windEl = document.getElementById("weatherWind");
				const uvEl = document.getElementById("weatherUV");
				const locEl = document.getElementById("weatherLocationName");
				const updateEl = document.getElementById("weatherUpdateTime");
				const coordEl = document.getElementById("coordDisplay");
				const forecastRow = document.getElementById("weatherForecastRow");
				const iconWrap = document.getElementById("weatherMainIconWrap");
				const toggleBtn = document.getElementById("toggleWeatherBtn");
				const collapseEl = document.getElementById("weatherDetailsCollapse");
				const liveTimeEl = document.getElementById("weatherCurrentTime");

				function updateLiveTime() {
					const now = new Date();
					liveTimeEl.textContent = now.toLocaleTimeString("en-PH", {
						hour: "numeric",
						minute: "2-digit",
						hour12: true
					});
				}
				updateLiveTime();
				setInterval(updateLiveTime, 60000);

				const iconBase = "https://cdn.jsdelivr.net/gh/mrdarrengriffin/google-weather-icons@master/sets/set-2";
				const weatherCodes = {
					// Clear & Cloudy
					0: { label: "Clear Sky", img: `${iconBase}/sunny.png`, nightImg: `${iconBase}/clear_night.png` },
					1: { label: "Mainly Clear", img: `${iconBase}/sunny.png`, nightImg: `${iconBase}/clear_night.png` },
					2: { label: "Partly Cloudy", img: `${iconBase}/partly_cloudy.png`, nightImg: `${iconBase}/partly_cloudy_night.png` },
					3: { label: "Overcast", img: `${iconBase}/cloudy.png` },
					// Fog
					45: { label: "Fog", img: `${iconBase}/haze_fog_dust_smoke.png` },
					48: { label: "Fog", img: `${iconBase}/haze_fog_dust_smoke.png` },
					// Drizzle
					51: { label: "Light Drizzle", img: `${iconBase}/drizzle.png` },
					53: { label: "Drizzle", img: `${iconBase}/drizzle.png` },
					55: { label: "Heavy Drizzle", img: `${iconBase}/drizzle.png` },
					56: { label: "Freezing Drizzle", img: `${iconBase}/sleet_hail.png` },
					57: { label: "Freezing Drizzle", img: `${iconBase}/sleet_hail.png` },
					// Rain
					61: { label: "Slight Rain", img: `${iconBase}/showers_rain.png` },
					63: { label: "Moderate Rain", img: `${iconBase}/showers_rain.png` },
					65: { label: "Heavy Rain", img: `${iconBase}/heavy_rain.png` },
					// Freezing
					66: { label: "Freezing Rain", img: `${iconBase}/sleet_hail.png` },
					67: { label: "Freezing Rain", img: `${iconBase}/sleet_hail.png` },
					// Snow
					71: { label: "Slight Snow", img: `${iconBase}/snow.png` },
					73: { label: "Moderate Snow", img: `${iconBase}/snow.png` },
					75: { label: "Heavy Snow", img: `${iconBase}/heavy_snow.png` },
					77: { label: "Snow Grains", img: `${iconBase}/snow.png` },
					// Showers
					80: { label: "Slight Showers", img: `${iconBase}/showers_rain.png` },
					81: { label: "Showers", img: `${iconBase}/showers_rain.png` },
					82: { label: "Violent Showers", img: `${iconBase}/heavy_rain.png` },
					// Snow Showers
					85: { label: "Snow Showers", img: `${iconBase}/snow_showers_snow.png` },
					86: { label: "Heavy Snow Showers", img: `${iconBase}/snow_showers_snow.png` },
					// Thunderstorm
					95: { label: "Thunderstorm", img: `${iconBase}/strong_tstorms.png` },
					96: { label: "Thunderstorm", img: `${iconBase}/strong_tstorms.png` },
					99: { label: "Thunderstorm", img: `${iconBase}/strong_tstorms.png` }
				};

				function isNight() {
					const hour = new Date().getHours();
					return hour < 6 || hour > 18;
				}

				function getIcon(code) {
					const data = weatherCodes[code] || weatherCodes[0];
					const src = (isNight() && data.nightImg) ? data.nightImg : data.img;
					return `<img class="weather-icon-lg" src="${src}" alt="${data.label}">`;
				}

				function getHeatPromptKey() {
					var todayKey = new Date().toISOString().slice(0, 10);
					return heatPromptPrefix + currentUserId + "_" + todayKey;
				}

				function closeHeatPrompt() {
					if (heatPromptMask) {
						heatPromptMask.classList.remove("show");
					}
					if (heatPromptModal) {
						heatPromptModal.classList.remove("show");
					}
				}

				function openHeatPrompt(tempValue) {
					if (!hasSavedPlantingProfile || !heatPromptMask || !heatPromptModal) {
						return;
					}

					var tempNumber = Number(tempValue);
					if (!Number.isFinite(tempNumber)) {
						return;
					}

					var promptKey = getHeatPromptKey();
					if (localStorage.getItem(promptKey)) {
						return;
					}

					localStorage.setItem(promptKey, "1");
					latestWeatherTemp = tempNumber;
					if (heatPromptTemp) {
						heatPromptTemp.textContent = Math.round(tempNumber) + "°C";
					}
					if (heatPromptDesc) {
							heatPromptDesc.textContent = "Today's live temperature is " + Math.round(tempNumber) + "°C. Do you want to add a watering task for today?";
					}
					heatPromptMask.classList.add("show");
					heatPromptModal.classList.add("show");
				}

				async function submitHeatPromptDecision() {
					if (!Number.isFinite(latestWeatherTemp)) {
						return;
					}

					if (heatPromptYesBtn) {
						heatPromptYesBtn.disabled = true;
						heatPromptYesBtn.textContent = "Saving...";
					}
					if (heatPromptNoBtn) {
						heatPromptNoBtn.disabled = true;
					}

					try {
						const res = await fetch(window.location.pathname + window.location.search, {
							method: "POST",
							headers: { "Content-Type": "application/json" },
							body: JSON.stringify({
								action: "weather_heat_watering_prompt",
								temperature: latestWeatherTemp
							})
						});
						const data = await res.json();
						if (!res.ok || !data || data.success !== true) {
							throw new Error((data && data.message) ? data.message : "Unable to update calendar right now.");
						}

						window.location.reload();
					} catch (error) {
						localStorage.removeItem(getHeatPromptKey());
						closeHeatPrompt();
						window.alert(error.message || "Unable to update calendar right now.");
					} finally {
						if (heatPromptYesBtn) {
							heatPromptYesBtn.disabled = false;
							heatPromptYesBtn.textContent = "Yes, add watering task";
						}
						if (heatPromptNoBtn) {
							heatPromptNoBtn.disabled = false;
						}
					}
				}

				if (heatPromptMask) {
					heatPromptMask.addEventListener("click", closeHeatPrompt);
				}
				if (heatPromptNoBtn) {
					heatPromptNoBtn.addEventListener("click", function () {
						closeHeatPrompt();
					});
				}
				if (heatPromptYesBtn) {
					heatPromptYesBtn.addEventListener("click", function () {
						submitHeatPromptDecision();
					});
				}

				async function fetchWeather(lat, lon, label) {
					try {
						const res = await fetch(`https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lon}&current=temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max&timezone=auto&forecast_days=8`);
						const data = await res.json();
						
						if (data && data.current && data.daily) {
							const cur = data.current;
							latestWeatherTemp = Number(cur.temperature_2m);
							tempEl.textContent = Math.round(cur.temperature_2m);
							condEl.textContent = (weatherCodes[cur.weather_code] || {label: "Mixed"}).label;
							humEl.textContent = cur.relative_humidity_2m + "%";
							windEl.textContent = Math.round(cur.wind_speed_10m) + " km/h";
							uvEl.textContent = "High"; 
							locEl.textContent = label;
							updateEl.textContent = "Last sync: " + new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
							iconWrap.innerHTML = getIcon(cur.weather_code);
							if (hasSavedPlantingProfile && latestWeatherTemp >= 30) {
								openHeatPrompt(latestWeatherTemp);
							}

							// Forecast
							forecastRow.innerHTML = "";
							const days = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
							for (let i = 1; i <= 7; i++) {
								const d = new Date();
								d.setDate(d.getDate() + i);
								const dayName = days[d.getDay()];
								const max = Math.round(data.daily.temperature_2m_max[i]);
								const rainProb = data.daily.precipitation_probability_max[i] || 0;
								const wCode = data.daily.weather_code[i];
								const wData = weatherCodes[wCode] || weatherCodes[0];
								const iconSmImg = wData.img;
								
								const item = document.createElement("div");
								item.className = "forecast-day";
								item.innerHTML = `
									<span class="forecast-name">${dayName}</span>
									<img class="weather-icon-sm" src="${iconSmImg}" alt="${wData.label}">
									<span class="forecast-temp">${max}°</span>
									<span class="forecast-rain">
										<svg style="width:8px;height:8px;" viewBox="0 0 24 24" fill="currentColor"><path d="M12 21c-3.3 0-6-2.7-6-6 0-3.3 6-12 6-12s6 8.7 6 12c0 3.3-2.7 6-6 6z"></path></svg>
										${rainProb}%
									</span>
								`;
								forecastRow.appendChild(item);
							}
						}
					} catch (e) {
						console.error("Weather error", e);
						updateEl.textContent = "Offline.";
					}
				}

				if (toggleBtn) {
					toggleBtn.addEventListener("click", () => {
						const isExpanded = collapseEl.classList.toggle("expanded");
						toggleBtn.classList.toggle("is-active", isExpanded);
						toggleBtn.title = isExpanded ? "Hide Forecast" : "Show Weekly Forecast";
					});
				}

				// Fixed to Calatagan, Batangas
				fetchWeather(13.8322, 120.6311, "Calatagan, Batangas");
			}

			if (mainView && !mainView.classList.contains("d-none")) {
				initWeather();
			}
			// --- WEATHER SERVICE END ---

			var profileModal = document.getElementById("profileModal");
			var profileModalMask = document.getElementById("profileModalMask");
			var closeProfileModalBtn = document.getElementById("closeProfileModalBtn");
			var profileTabButtons = document.querySelectorAll("[data-profile-tab]");
			var profileTabPane = document.getElementById("profileTabPane");
			var voidTabPane = document.getElementById("voidTabPane");

			var summaryCornType = document.getElementById("summaryCornType");
			var summaryCornVariety = document.getElementById("summaryCornVariety");
			var summaryPlantingDate = document.getElementById("summaryPlantingDate");
			var summaryFarmLocation = document.getElementById("summaryFarmLocation");
			var summaryArea = document.getElementById("summaryArea");
			var summaryHarvestDate = document.getElementById("summaryHarvestDate");
			var summaryStatus = document.getElementById("summaryStatus");
			var profileChangePasswordShortcutBtn = document.getElementById("profileChangePasswordShortcutBtn");

			var accountSettingsForm = document.getElementById("accountSettingsForm");
			var accountFullNameInput = document.getElementById("accountFullNameInput");
			var accountUsernameInput = document.getElementById("accountUsernameInput");
			var accountPasswordFields = document.getElementById("accountPasswordFields");
			var accountCurrentPasswordInput = document.getElementById("accountCurrentPasswordInput");
			var accountNewPasswordInput = document.getElementById("accountNewPasswordInput");
			var accountConfirmPasswordInput = document.getElementById("accountConfirmPasswordInput");
			var accountCurrentPasswordError = document.getElementById("accountCurrentPasswordError");
			var accountNewPasswordLengthError = document.getElementById("accountNewPasswordLengthError");
			var accountConfirmPasswordError = document.getElementById("accountConfirmPasswordError");
			var accountStrengthBox = document.getElementById("accountStrengthBox");
			var accountStrengthLabel = document.getElementById("accountStrengthLabel");
			var accountStrengthFill = document.getElementById("accountStrengthFill");
			var accountCancelPasswordBtn = document.getElementById("accountCancelPasswordBtn");
			var accountStatusNote = document.getElementById("accountStatusNote");
			var saveAccountBtn = document.getElementById("saveAccountBtn");
			var defaultSaveAccountText = saveAccountBtn ? saveAccountBtn.textContent : "Save Account Changes";
			var isPasswordChangeEnabled = false;

			var voidStartStep = document.getElementById("voidStartStep");
			var voidFormStep = document.getElementById("voidFormStep");
			var voidConfirmMask = document.getElementById("voidConfirmMask");
			var voidConfirmModal = document.getElementById("voidConfirmAction");
			var voidContinueBtn = document.getElementById("voidContinueBtn");
			var voidCancelBtn = document.getElementById("voidCancelBtn");
			var voidBackBtn = document.getElementById("voidBackBtn");
			var submitVoidBtn = document.getElementById("submitVoidBtn");
			var voidReasonSelect = document.getElementById("voidReasonSelect");
			var voidNotesInput = document.getElementById("voidNotesInput");
			var voidConfirmInput = document.getElementById("voidConfirmInput");
			var voidAcknowledgeCheck = document.getElementById("voidAcknowledgeCheck");
			var voidStatusNote = document.getElementById("voidStatusNote");
			var voidConfirmNoBtn = document.getElementById("voidConfirmNoBtn");
			var voidConfirmYesBtn = document.getElementById("voidConfirmYesBtn");
			var voidDeletingOverlay = document.getElementById("voidDeletingOverlay");
			var voidToast = document.getElementById("voidToast");
			var voidToastTitle = document.getElementById("voidToastTitle");
			var voidToastMsg = document.getElementById("voidToastMsg");
			var pendingVoidReason = "";
			var pendingVoidNotes = "";
			var pendingVoidConfirmText = "VOID";

			var logoutModal = document.getElementById("logoutModal");
			var logoutMask = document.getElementById("logoutModalMask");
			var cancelLogout = document.getElementById("cancelLogout");
			var confirmLogout = document.getElementById("confirmLogout");

			function showVoidStatus(message, isError) {
				if (!voidStatusNote) {
					return;
				}
				voidStatusNote.textContent = message || "";
				voidStatusNote.classList.toggle("is-error", !!(message && isError));
				voidStatusNote.classList.toggle("is-success", !!(message && !isError));
			}

			function showAccountStatus(message, isError, isSuccess) {
				if (!accountStatusNote) {
					return;
				}

				accountStatusNote.textContent = message || "";
				accountStatusNote.classList.toggle("is-error", !!(message && isError));
				accountStatusNote.classList.toggle("is-success", !!(message && !isError && isSuccess));
			}

			function setAccountFieldError(inputEl, errorEl, shouldShow, errorText) {
				if (inputEl) {
					inputEl.classList.toggle("account-input-error", !!shouldShow);
				}

				if (errorEl) {
					if (errorText) {
						errorEl.textContent = errorText;
					}
					errorEl.classList.toggle("visible", !!shouldShow);
				}
			}

			function getAccountPasswordStrength(password) {
				if (password === "") {
					return { strength: 0, label: "", color: "#ef4444" };
				}

				var points = 0;
				if (password.length >= 8) points += 25;
				if (password.length >= 12) points += 15;
				if (/[a-z]/.test(password)) points += 15;
				if (/[A-Z]/.test(password)) points += 15;
				if (/[0-9]/.test(password)) points += 15;
				if (/[^A-Za-z0-9]/.test(password)) points += 15;

				if (points <= 40) {
					return { strength: points, label: "Weak", color: "#ef4444" };
				}

				if (points <= 75) {
					return { strength: points, label: "Medium", color: "#f59e0b" };
				}

				return { strength: points, label: "Strong", color: "#16a34a" };
			}

			function resetAccountPasswordFieldState() {
				setAccountFieldError(accountCurrentPasswordInput, accountCurrentPasswordError, false);
				setAccountFieldError(accountNewPasswordInput, accountNewPasswordLengthError, false);
				setAccountFieldError(accountConfirmPasswordInput, accountConfirmPasswordError, false);

				if (accountStrengthBox) {
					accountStrengthBox.classList.remove("visible");
				}
				if (accountStrengthLabel) {
					accountStrengthLabel.textContent = "";
					accountStrengthLabel.style.color = "";
				}
				if (accountStrengthFill) {
					accountStrengthFill.style.width = "0%";
					accountStrengthFill.style.background = "#ef4444";
				}
			}

			function updateAccountPasswordStrength() {
				if (!isPasswordChangeEnabled || !accountNewPasswordInput) {
					resetAccountPasswordFieldState();
					return;
				}

				var passwordValue = String(accountNewPasswordInput.value || "");

				if (passwordValue === "") {
					if (accountStrengthBox) {
						accountStrengthBox.classList.remove("visible");
					}
					if (accountStrengthLabel) {
						accountStrengthLabel.textContent = "";
					}
					if (accountStrengthFill) {
						accountStrengthFill.style.width = "0%";
					}
					setAccountFieldError(accountNewPasswordInput, accountNewPasswordLengthError, false);
					return;
				}

				var strength = getAccountPasswordStrength(passwordValue);
				if (accountStrengthBox) {
					accountStrengthBox.classList.add("visible");
				}
				if (accountStrengthLabel) {
					accountStrengthLabel.textContent = strength.label;
					accountStrengthLabel.style.color = strength.color;
				}
				if (accountStrengthFill) {
					accountStrengthFill.style.width = Math.min(strength.strength, 100) + "%";
					accountStrengthFill.style.background = strength.color;
				}

				setAccountFieldError(
					accountNewPasswordInput,
					accountNewPasswordLengthError,
					passwordValue.length < 6,
					"Password must be at least 6 characters."
				);
			}

			function updateAccountConfirmPasswordState() {
				if (!isPasswordChangeEnabled || !accountConfirmPasswordInput || !accountNewPasswordInput) {
					setAccountFieldError(accountConfirmPasswordInput, accountConfirmPasswordError, false);
					return;
				}

				var newPasswordValue = String(accountNewPasswordInput.value || "");
				var confirmPasswordValue = String(accountConfirmPasswordInput.value || "");
				var hasMismatch = confirmPasswordValue !== "" && confirmPasswordValue !== newPasswordValue;
				setAccountFieldError(accountConfirmPasswordInput, accountConfirmPasswordError, hasMismatch, "Passwords do not match.");
			}

			function normalizeUsernameInput(value) {
				return String(value || "").trim().replace(/^@+/, "");
			}

			function applyAccountIdentity(name, username) {
				var normalizedName = String(name || "").trim();
				var normalizedUsername = normalizeUsernameInput(username);
				var displayHandle = "@" + normalizedUsername;

				var userNameNodes = document.querySelectorAll(".user-name");
				for (var i = 0; i < userNameNodes.length; i += 1) {
					userNameNodes[i].textContent = normalizedName;
				}

				var userHandleNodes = document.querySelectorAll(".user-handle");
				for (var j = 0; j < userHandleNodes.length; j += 1) {
					userHandleNodes[j].textContent = displayHandle;
				}

				if (accountFullNameInput) {
					accountFullNameInput.value = normalizedName;
				}

				if (accountUsernameInput) {
					accountUsernameInput.value = normalizedUsername;
				}
			}

			function setPasswordChangeEnabled(enabled) {
				isPasswordChangeEnabled = !!enabled;

				if (accountPasswordFields) {
					accountPasswordFields.classList.toggle("d-none", !isPasswordChangeEnabled);
				}

				if (profileChangePasswordShortcutBtn) {
					profileChangePasswordShortcutBtn.classList.toggle("d-none", isPasswordChangeEnabled);
				}

				if (accountCancelPasswordBtn) {
					accountCancelPasswordBtn.classList.toggle("d-none", !isPasswordChangeEnabled);
				}

				if (!isPasswordChangeEnabled) {
					if (accountCurrentPasswordInput) {
						accountCurrentPasswordInput.value = "";
					}
					if (accountNewPasswordInput) {
						accountNewPasswordInput.value = "";
					}
					if (accountConfirmPasswordInput) {
						accountConfirmPasswordInput.value = "";
					}
				}

				resetAccountPasswordFieldState();
			}

			function clearLifecycleJournalCache() {
				try {
					for (var i = window.localStorage.length - 1; i >= 0; i -= 1) {
						var key = window.localStorage.key(i);
						if (key && key.indexOf("lifecycle_stage_journal_") === 0) {
							window.localStorage.removeItem(key);
						}
					}
				} catch (error) {
					// Ignore localStorage cleanup issues and continue reset flow.
				}
			}

			function showVoidConfirmModal() {
				if (voidConfirmMask) {
					voidConfirmMask.classList.add("show");
				}
				if (voidConfirmModal) {
					voidConfirmModal.classList.add("show");
				}
			}

			function hideVoidConfirmModal() {
				if (voidConfirmMask) {
					voidConfirmMask.classList.remove("show");
				}
				if (voidConfirmModal) {
					voidConfirmModal.classList.remove("show");
				}
			}

			function showVoidDeletingOverlay() {
				if (voidDeletingOverlay) {
					voidDeletingOverlay.classList.remove("d-none");
					voidDeletingOverlay.classList.add("show");
				}
			}

			function hideVoidDeletingOverlay() {
				if (voidDeletingOverlay) {
					voidDeletingOverlay.classList.remove("show");
					voidDeletingOverlay.classList.add("d-none");
				}
			}

			function showVoidToast(title, message) {
				if (!voidToast) {
					return;
				}

				if (voidToastTitle) {
					voidToastTitle.textContent = title || "Account deleted";
				}
				if (voidToastMsg) {
					voidToastMsg.textContent = message || "Your planting data has been cleared. You can restart planting.";
				}

				voidToast.classList.remove("d-none");
				setTimeout(function () {
					voidToast.classList.add("show");
				}, 20);
			}

			function hideVoidToast() {
				if (!voidToast) {
					return;
				}

				voidToast.classList.remove("show");
				setTimeout(function () {
					voidToast.classList.add("d-none");
				}, 220);
			}

			function activateProfileTab(tabName) {
				var isProfile = tabName === "profile";
				profileTabPane.classList.toggle("active", isProfile);
				voidTabPane.classList.toggle("active", !isProfile);

				profileTabButtons.forEach(function (button) {
					button.classList.toggle("active", button.getAttribute("data-profile-tab") === tabName);
				});
			}

			function formatHarvestSummary(profile) {
				var days = getHarvestDaysFromProfile(profile);
				var harvestDate = getEstimatedHarvestDate(profile, days);
				if (!harvestDate) {
					return "--";
				}

				return harvestDate.toLocaleDateString("en-PH", {
					year: "numeric",
					month: "short",
					day: "numeric"
				});
			}

			function fillProfileSummary() {
				var profile = loadSavedPlantingProfile();

				function applySummaryState(hasProfile) {
					if (summaryStatus) {
						summaryStatus.classList.toggle("is-ready", hasProfile);
						summaryStatus.classList.toggle("is-empty", !hasProfile);
					}
				}

				if (!profile) {
					summaryCornType.textContent = "--";
					summaryCornVariety.textContent = "--";
					summaryPlantingDate.textContent = "--";
					summaryFarmLocation.textContent = "--";
					summaryArea.textContent = "--";
					summaryHarvestDate.textContent = "--";
					summaryStatus.textContent = "No profile saved yet";
					applySummaryState(false);
					return;
				}

				summaryCornType.textContent = summaryText(profile.typeOfCorn, "--");
				summaryCornVariety.textContent = summaryText(profile.cornVariety, "--");
				summaryPlantingDate.textContent = formatSummaryDate(profile.plantingDate);
				summaryFarmLocation.textContent = summaryText(profile.farmLocation, "--");
				summaryArea.textContent = formatAreaSummary(profile);
				summaryHarvestDate.textContent = formatHarvestSummary(profile);
				summaryStatus.textContent = "Profile saved";
				applySummaryState(true);
			}

			function resetVoidFlow() {
				voidStartStep.classList.remove("d-none");
				voidFormStep.classList.add("d-none");
				hideVoidConfirmModal();
				hideVoidDeletingOverlay();
				hideVoidToast();
				pendingVoidReason = "";
				pendingVoidNotes = "";
				pendingVoidConfirmText = "VOID";
				voidReasonSelect.value = "";
				voidNotesInput.value = "";
				voidConfirmInput.value = "";
				voidAcknowledgeCheck.checked = false;
				showVoidStatus("", false);
			}

			function openProfileModal() {
				fillProfileSummary();
				activateProfileTab("profile");
				resetVoidFlow();
				setPasswordChangeEnabled(false);
				showAccountStatus("", false, false);
				profileModalMask.classList.add("show");
				profileModal.classList.add("show");
			}

			function closeProfileModal() {
				profileModalMask.classList.remove("show");
				profileModal.classList.remove("show");
			}

			function openLogout() {
				logoutMask.classList.add("show");
				logoutModal.classList.add("show");
			}

			function closeLogout() {
				logoutMask.classList.remove("show");
				logoutModal.classList.remove("show");
			}

			document.getElementById("logoutBtnMain").addEventListener("click", openLogout);
			document.getElementById("logoutBtnFeature").addEventListener("click", openLogout);

			cancelLogout.addEventListener("click", closeLogout);
			logoutMask.addEventListener("click", closeLogout);

			confirmLogout.addEventListener("click", function () {
				window.location.href = "../login.php";
			});

			document.getElementById("profileBtnMain").addEventListener("click", openProfileModal);
			document.getElementById("profileBtnFeature").addEventListener("click", openProfileModal);

			closeProfileModalBtn.addEventListener("click", closeProfileModal);
			profileModalMask.addEventListener("click", closeProfileModal);

			profileTabButtons.forEach(function (button) {
				button.addEventListener("click", function () {
					activateProfileTab(button.getAttribute("data-profile-tab") || "profile");
				});
			});

			if (accountSettingsForm) {
				function submitAccountChanges(payload) {
					if (saveAccountBtn) {
						saveAccountBtn.disabled = true;
						saveAccountBtn.textContent = "Saving...";
					}
					showAccountStatus("Saving account changes...", false, false);

					fetch(window.location.pathname + window.location.search, {
						method: "POST",
						headers: {
							"Content-Type": "application/json"
						},
						body: JSON.stringify(payload)
					})
						.then(function (response) {
							return response.json().then(function (data) {
								return {
									ok: response.ok,
									data: data
								};
							});
						})
						.then(function (result) {
							if (!result.ok || !result.data.success) {
								throw new Error(result.data.message || "Unable to update account.");
							}

							applyAccountIdentity(result.data.updatedName || payload.fullName, result.data.updatedUsername || payload.username);
							setPasswordChangeEnabled(false);
							showAccountStatus(result.data.message || "Account details updated successfully.", false, true);
						})
						.catch(function (error) {
							var errorMessage = error && error.message ? error.message : "Unable to update account right now.";
							if (String(errorMessage).toLowerCase().indexOf("current password is incorrect") !== -1) {
								setAccountFieldError(accountCurrentPasswordInput, accountCurrentPasswordError, true, "Current password is incorrect.");
							}
							showAccountStatus(errorMessage, true, false);
						})
						.finally(function () {
							if (saveAccountBtn) {
								saveAccountBtn.disabled = false;
								saveAccountBtn.textContent = defaultSaveAccountText;
							}
						});
				}

				if (profileChangePasswordShortcutBtn) {
					profileChangePasswordShortcutBtn.addEventListener("click", function () {
						setPasswordChangeEnabled(true);
						showAccountStatus("", false, false);
						if (accountCurrentPasswordInput) {
							accountCurrentPasswordInput.focus();
						}
					});
				}

				if (accountCurrentPasswordInput) {
					accountCurrentPasswordInput.addEventListener("input", function () {
						setAccountFieldError(accountCurrentPasswordInput, accountCurrentPasswordError, false);
					});
				}

				if (accountNewPasswordInput) {
					accountNewPasswordInput.addEventListener("input", function () {
						updateAccountPasswordStrength();
						updateAccountConfirmPasswordState();
					});
				}

				if (accountConfirmPasswordInput) {
					accountConfirmPasswordInput.addEventListener("input", function () {
						updateAccountConfirmPasswordState();
					});
				}

				if (accountCancelPasswordBtn) {
					accountCancelPasswordBtn.addEventListener("click", function () {
						setPasswordChangeEnabled(false);
						showAccountStatus("", false, false);
					});
				}

				accountSettingsForm.addEventListener("submit", function (event) {
					event.preventDefault();

					var fullName = String(accountFullNameInput ? accountFullNameInput.value : "").trim();
					var username = normalizeUsernameInput(accountUsernameInput ? accountUsernameInput.value : "");
					var currentPassword = accountCurrentPasswordInput ? accountCurrentPasswordInput.value : "";
					var newPassword = accountNewPasswordInput ? accountNewPasswordInput.value : "";
					var confirmNewPassword = accountConfirmPasswordInput ? accountConfirmPasswordInput.value : "";

					if (fullName === "") {
						showAccountStatus("Full Name is required.", true, false);
						return;
					}

					if (fullName.length < 2) {
						showAccountStatus("Full Name must be at least 2 characters.", true, false);
						return;
					}

					if (username === "") {
						showAccountStatus("Username is required.", true, false);
						return;
					}

					if (!/^[A-Za-z0-9_.-]{3,30}$/.test(username)) {
						showAccountStatus("Username must be 3-30 characters and use only letters, numbers, dot, underscore, or dash.", true, false);
						return;
					}

					var wantsPasswordChange = isPasswordChangeEnabled;
					if (wantsPasswordChange) {
						setAccountFieldError(accountCurrentPasswordInput, accountCurrentPasswordError, false);
						updateAccountPasswordStrength();
						updateAccountConfirmPasswordState();

						if (currentPassword === "" || newPassword === "" || confirmNewPassword === "") {
							showAccountStatus("Complete all password fields to change your password.", true, false);
							if (currentPassword === "") {
								setAccountFieldError(accountCurrentPasswordInput, accountCurrentPasswordError, true, "Current password is required.");
							}
							if (newPassword === "") {
								setAccountFieldError(accountNewPasswordInput, accountNewPasswordLengthError, true, "New password is required.");
							}
							if (confirmNewPassword === "") {
								setAccountFieldError(accountConfirmPasswordInput, accountConfirmPasswordError, true, "Please confirm your new password.");
							}
							return;
						}

						if (newPassword.length < 6) {
							setAccountFieldError(accountNewPasswordInput, accountNewPasswordLengthError, true, "Password must be at least 6 characters.");
							showAccountStatus("New password must be at least 6 characters.", true, false);
							return;
						}

						if (newPassword !== confirmNewPassword) {
							setAccountFieldError(accountConfirmPasswordInput, accountConfirmPasswordError, true, "Passwords do not match.");
							showAccountStatus("New password and confirmation do not match.", true, false);
							return;
						}
					}

					submitAccountChanges({
						action: "update_account_profile",
						fullName: fullName,
						username: username,
						currentPassword: currentPassword,
						newPassword: newPassword,
						confirmNewPassword: confirmNewPassword
					});
				});
			}

			voidContinueBtn.addEventListener("click", function () {
				voidStartStep.classList.add("d-none");
				voidFormStep.classList.remove("d-none");
				showVoidStatus("Please complete the form to proceed.", false);
			});

			voidCancelBtn.addEventListener("click", function () {
				activateProfileTab("profile");
			});

			voidBackBtn.addEventListener("click", function () {
				voidFormStep.classList.add("d-none");
				voidStartStep.classList.remove("d-none");
				showVoidStatus("", false);
			});

			voidFormStep.addEventListener("submit", function (event) {
				event.preventDefault();

				var reason = (voidReasonSelect.value || "").trim();
				var notes = (voidNotesInput.value || "").trim();
				var confirmText = (voidConfirmInput.value || "").trim().toUpperCase();

				if (reason === "") {
					showVoidStatus("Select a reason for voiding.", true);
					return;
				}

				if (confirmText !== "VOID") {
					showVoidStatus("Type VOID to continue.", true);
					return;
				}

				if (!voidAcknowledgeCheck.checked) {
					showVoidStatus("Please check the acknowledgment box.", true);
					return;
				}

				pendingVoidReason = reason;
				pendingVoidNotes = notes;
				pendingVoidConfirmText = confirmText;

				showVoidConfirmModal();
				if (voidConfirmNoBtn) {
					voidConfirmNoBtn.focus();
				}
			});

			if (voidConfirmMask) {
				voidConfirmMask.addEventListener("click", hideVoidConfirmModal);
			}

			if (voidConfirmNoBtn) {
				voidConfirmNoBtn.addEventListener("click", hideVoidConfirmModal);
			}

			if (voidConfirmYesBtn) {
				voidConfirmYesBtn.addEventListener("click", function () {
					hideVoidConfirmModal();
					showVoidDeletingOverlay();

					submitVoidBtn.disabled = true;
					submitVoidBtn.textContent = "Deleting...";
					showVoidStatus("Deleting planting data...", false);

					var delayPromise = new Promise(function (resolve) {
						setTimeout(resolve, 5000);
					});

					var fetchPromise = fetch(window.location.pathname + window.location.search, {
						method: "POST",
						headers: { "Content-Type": "application/json" },
						body: JSON.stringify({ action: "void_planting_data", reason: pendingVoidReason, notes: pendingVoidNotes, confirmText: pendingVoidConfirmText })
					}).then(function (response) {
						return response.json().then(function (data) {
							return { ok: response.ok, data: data };
						});
					});

					Promise.all([delayPromise, fetchPromise])
						.then(function (values) {
							var result = values[1];
							if (!result.ok || !result.data.success) {
								throw new Error(result.data.message || "Unable to void planting data.");
							}

							hideVoidDeletingOverlay();
							storedPlantingProfile = null;
							activePlantingProfile = null;
							clearLifecycleJournalCache();
							showVoidToast("Account deleted", "Your planting data has been cleared. You can restart planting.");
							setTimeout(function () {
								hideVoidToast();
							}, 4500);
							setTimeout(function () {
								window.location.reload();
							}, 5500);
						})
						.catch(function (error) {
							hideVoidDeletingOverlay();
							showVoidStatus(error.message || "Unable to void planting data right now.", true);
						})
						.finally(function () {
							submitVoidBtn.disabled = false;
							submitVoidBtn.textContent = "Void Planting Data";
						});
				});
			}

			var notifBtnMain = document.getElementById("notifBtnMain");
			var notifBtnFeature = document.getElementById("notifBtnFeature");
			var notifPanel = document.getElementById("notifPanel");
			var notifViewButtons = notifPanel.querySelectorAll(".notif-view-btn");

			function closeNotifPanel() {
				notifPanel.classList.remove("show");
				notifBtnMain.setAttribute("aria-expanded", "false");
				notifBtnFeature.setAttribute("aria-expanded", "false");
			}

			function toggleNotifPanel(sourceBtn) {
				var willShow = !notifPanel.classList.contains("show");
				if (willShow) {
					notifPanel.classList.add("show");
					notifBtnMain.setAttribute("aria-expanded", sourceBtn === notifBtnMain ? "true" : "false");
					notifBtnFeature.setAttribute("aria-expanded", sourceBtn === notifBtnFeature ? "true" : "false");
					return;
				}
				closeNotifPanel();
			}

			notifBtnMain.addEventListener("click", function (event) {
				event.stopPropagation();
				toggleNotifPanel(notifBtnMain);
			});

			notifBtnFeature.addEventListener("click", function (event) {
				event.stopPropagation();
				toggleNotifPanel(notifBtnFeature);
			});

			if (summaryBtnMain) {
				summaryBtnMain.addEventListener("click", function () {
					openSummaryModal();
				});
			}

			if (summaryBtnFeature) {
				summaryBtnFeature.addEventListener("click", function () {
					openSummaryModal();
				});
			}

			if (closeSummaryModalBtn) {
				closeSummaryModalBtn.addEventListener("click", closeSummaryModal);
			}

			if (summaryHistoryBtn) {
				summaryHistoryBtn.addEventListener("click", function (event) {
					event.preventDefault();
					openSummaryHistory();
				});
			}

			if (closeSummaryHistoryBtn) {
				closeSummaryHistoryBtn.addEventListener("click", closeSummaryHistory);
			}

			if (summaryPrevBtn) {
				summaryPrevBtn.addEventListener("click", function () {
					showSummaryStep(summaryCurrentStep - 1);
				});
			}

			if (summaryNextBtn) {
				summaryNextBtn.addEventListener("click", function () {
					showSummaryStep(summaryCurrentStep + 1);
				});
			}

			if (summaryNewCycleBtn) {
				var confirmNewCycleMask = document.getElementById('confirmNewCycleMask');
				var confirmNewCycleModal = document.getElementById('confirmNewCycleModal');
				var cancelNewCycleBtn = document.getElementById('cancelNewCycleBtn');
				var confirmNewCycleBtnAction = document.getElementById('confirmNewCycleBtnAction');

				function openConfirmNewCycle() {
					if (confirmNewCycleMask) confirmNewCycleMask.classList.add('show');
					if (confirmNewCycleModal) confirmNewCycleModal.classList.add('show');
				}

				function closeConfirmNewCycle() {
					if (confirmNewCycleMask) confirmNewCycleMask.classList.remove('show');
					if (confirmNewCycleModal) confirmNewCycleModal.classList.remove('show');
				}

				summaryNewCycleBtn.addEventListener('click', function (e) {
					e.preventDefault();
					openConfirmNewCycle();
				});

				if (cancelNewCycleBtn) {
					cancelNewCycleBtn.addEventListener('click', function () {
						closeConfirmNewCycle();
					});
				}

				if (confirmNewCycleBtnAction) {
					confirmNewCycleBtnAction.addEventListener('click', function () {
						function readText(el) {
							return el ? String(el.textContent || "").trim() || null : null;
						}

						function readValue(el) {
							return el ? String(el.value || "").trim() || null : null;
						}

						// gather summary data from DOM (steps 1 and 2)
						var summary = {
							profile: window.activePlantingProfile || null,
							forecast: window.summaryForecastValues || null,
							step1: {
								hero: {
									badge: readText(summaryHeroBadge),
									stage: readText(summaryHeroStageValue),
									progress: readText(summaryHeroProgressValue),
									tasks: readText(summaryHeroTasksValue),
									profit: readText(summaryHeroProfitValue)
								},
								profile: {
									cornType: readText(summaryCornTypeValue),
									variety: readText(summaryCornVarietyValue),
									plantingDate: readText(summaryPlantingDateValue),
									harvestDate: readText(summaryHarvestDateValue),
									farmLocation: readText(summaryFarmLocationValue),
									areaPlanted: readText(summaryAreaValue),
									soilType: readText(summarySoilTypeValue),
									plantingDensity: readText(summaryPlantingDensityValue),
									seedsPerHole: readText(summarySeedsPerHoleValue),
									estimatedSeeds: readText(summaryEstimatedSeedsValue)
								},
								progress: {
									currentStage: readText(summaryCurrentStageValue),
									growthProgress: readText(summaryGrowthValue),
									tasksThisWeek: readText(summaryTasksValue),
									tasksOverall: readText(summaryTasksOverallValue)
								},
								finance: {
									estimatedIncome: readText(summaryIncomeValue),
									totalCost: readText(summaryTotalCostValue),
									netProfit: readText(summaryNetProfitValue),
									marketUpdated: readText(summaryMarketUpdatedValue)
								},
								health: {
									latestPestDetection: readText(summaryPestDetectionValue),
									calendarUpdated: readText(summaryCalendarUpdatedValue)
								}
							},
							step2: {
								prediction: {
									income: readText(summaryPredictedIncomeValue),
									cost: readText(summaryPredictedCostValue),
									profit: readText(summaryPredictedProfitValue)
								},
								actual: {
									income: readValue(summaryActualIncomeInput),
									cost: readText(summaryActualCostValue),
									profit: readText(summaryActualProfitValue)
								},
								difference: {
									income: readText(summaryIncomeDifferenceValue),
									cost: readText(summaryCostDifferenceValue),
									profit: readText(summaryProfitDifferenceValue)
								},
								accuracy: {
									income: readText(summaryIncomeAccuracyValue),
									cost: readText(summaryCostAccuracyValue),
									profit: readText(summaryProfitAccuracyValue)
								},
								analysis: {
									averageAccuracy: readText(yieldAverageAccuracyValue),
									biggestVariance: readText(yieldBiggestVarianceValue),
									overallResult: readText(yieldOverallResultValue),
									note: readText(yieldComparisonNote)
								}
							},
							generated_at: new Date().toISOString()
						};

						var xhr = new XMLHttpRequest();
						xhr.open('POST', window.location.pathname, true);
						xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
						xhr.onload = function () {
							closeConfirmNewCycle();
							if (xhr.status === 200) {
								window.location.href = 'corn_planting_profile.php';
								return;
							}

							alert('There was an error completing the harvest. Please try again.');
						};
						xhr.onerror = function () {
							closeConfirmNewCycle();
							alert('There was an error completing the harvest.');
						};

						var params = 'action=complete_harvest_profile&users_id=' + encodeURIComponent(currentUserId) + '&summary=' + encodeURIComponent(JSON.stringify(summary));
						xhr.send(params);
					});
				}
			}

			if (summaryStepButtons && summaryStepButtons.length) {
				for (var stepButtonIndex = 0; stepButtonIndex < summaryStepButtons.length; stepButtonIndex += 1) {
					summaryStepButtons[stepButtonIndex].addEventListener("click", function () {
						showSummaryStep(Number(this.getAttribute("data-summary-step") || 1));
					});
				}
			}

				[
					summaryActualIncomeInput
				].forEach(function (inputElement) {
				if (!inputElement) {
					return;
				}
				inputElement.addEventListener("input", renderYieldComparison);
			});

			if (summaryModalMask) {
				summaryModalMask.addEventListener("click", closeSummaryModal);
			}

			notifPanel.addEventListener("click", function (event) {
				event.stopPropagation();
			});

			notifViewButtons.forEach(function (button) {
				button.addEventListener("click", function (event) {
					event.stopPropagation();
					var taskTitle = button.getAttribute("data-task-title") || "";
					var url = "corn_care_calendar.php";
					if (taskTitle) {
						url += "?viewTask=" + encodeURIComponent(taskTitle);
					}
					window.location.href = url;
				});
			});

			document.addEventListener("click", function () {
				closeNotifPanel();
			});

			document.addEventListener("keydown", function (event) {
				if (event.key === "Escape") {
					closeNotifPanel();
					closeProfileModal();
					closeLogout();
					closeSummaryModal();
						closeHeatPrompt();
				}
			});

			var plantingProfile = loadSavedPlantingProfile();
			activePlantingProfile = plantingProfile;
			if (plantingProfile && plantingProfile.typeOfCorn && plantingProfile.cornVariety) {
				cropTagText.textContent = plantingProfile.typeOfCorn + " - " + plantingProfile.cornVariety;
			} else if (cropTagText) {
				cropTagText.textContent = "Sweet Corn (Hybrid) - Golden Bantam F1";
			}

			if (profileSetupNote) {
				profileSetupNote.addEventListener("click", goToCornPlantingProfile);
				profileSetupNote.addEventListener("keydown", function (event) {
					if (event.key === "Enter" || event.key === " ") {
						event.preventDefault();
						goToCornPlantingProfile();
					}
				});
			}

			selectedHarvestDays = getHarvestDaysFromProfile(plantingProfile);
			stageDefinitions = buildStageDefinitions(selectedHarvestDays);
			dayRange.max = String(selectedHarvestDays);

			renderOverallTrack();

			var startingDay = hasPlantingProfileData(plantingProfile)
				? getInitialDayFromPlantingDate(plantingProfile, selectedHarvestDays)
				: 1;
			dayRange.value = String(startingDay);
			syncForecastIncomeFromMachineLearning();
			updateDashboard(startingDay);
		})();
	</script>
</body>
</html>
