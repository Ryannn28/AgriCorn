<?php
session_start();

if (!isset($_SESSION["users_id"])) {
	header("Location: ../login.php");
	exit;
}

$fileNameSource = trim((string) ($_SESSION["name"] ?? ""));
if ($fileNameSource === "") {
	$fileNameSource = trim((string) ($_SESSION["username"] ?? ""));
}
if ($fileNameSource === "") {
	$fileNameSource = "Farmer" . (string) $_SESSION["users_id"];
}

$fileNameSafe = preg_replace('/[^A-Za-z0-9_-]+/', '', str_replace(' ', '', $fileNameSource));
if ($fileNameSafe === "") {
	$fileNameSafe = "Farmer" . (string) $_SESSION["users_id"];
}

$initialProfileData = null;

$marketPricesGlobalPath = __DIR__ . "/../data/market_prices.json";
$marketPricesIndividualPath = __DIR__ . "/../data/Market Prices/" . $fileNameSafe . ".json";
$marketPricesLoadPath = file_exists($marketPricesIndividualPath) ? $marketPricesIndividualPath : $marketPricesGlobalPath;
$marketPricesJson = file_exists($marketPricesLoadPath) ? file_get_contents($marketPricesLoadPath) : '{"market_prices":{"other":{"price_per_kg":20}}}';

$initialCostingData = null;

function loadProfileFromDatabase(int $usersId)
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
	} catch (Throwable $e) {
		return null;
	}
}

function loadCostingFromDatabase(int $usersId)
{
	try {
		require __DIR__ . "/../data/db_connect.php";

		$stmt = $conn->prepare("SELECT expense_type, cost FROM costing WHERE users_id = ?");
		if (!$stmt) {
			return null;
		}

		$stmt->bind_param("i", $usersId);
		$stmt->execute();
		$result = $stmt->get_result();

		$rows = [];
		$totalCost = 0.0;
		$extraCounter = 0;

		while ($dbRow = $result->fetch_assoc()) {
			$type = trim((string) ($dbRow["expense_type"] ?? ""));
			if ($type === "") {
				continue;
			}

			$costValue = (float) ($dbRow["cost"] ?? 0);
			if ($costValue < 0) {
				$costValue = 0;
			}

			$typeKey = strtolower($type);
			$id = "";
			$source = "additional";
			$extraId = "";

			if ($typeKey === "seeds") {
				$id = "seed";
				$source = "seed";
			} elseif ($typeKey === "labor") {
				$id = "labor";
				$source = "labor";
			} else {
				$extraCounter += 1;
				$id = "extra-db-" . (string) $extraCounter;
				$extraId = "db-" . (string) $extraCounter;
			}

			$rows[] = [
				"id" => $id,
				"source" => $source,
				"type" => $type,
				"cost" => round($costValue, 2),
				"extraId" => $extraId
			];

			$totalCost += $costValue;
		}

		$stmt->close();

		if (count($rows) === 0) {
			return null;
		}

		return [
			"rows" => $rows,
			"totalCost" => round($totalCost, 2),
			"estimatedIncome" => null,
			"savedAt" => date("c")
		];
	} catch (Throwable $e) {
		return null;
	}
}

$initialProfileData = loadProfileFromDatabase((int) $_SESSION["users_id"]);
$initialCostingData = loadCostingFromDatabase((int) $_SESSION["users_id"]);

function parseDecimalValue($value): float
{
	$clean = str_replace(",", "", trim((string) $value));
	if ($clean === "") {
		return 0.0;
	}

	return is_numeric($clean) ? (float) $clean : 0.0;
}

function parseIntValue($value): int
{
	$clean = str_replace(",", "", trim((string) $value));
	if ($clean === "") {
		return 0;
	}

	return is_numeric($clean) ? (int) round((float) $clean) : 0;
}

function parseAreaDetails(array $data): array
{
	$unitInput = (string) ($data["areaUnit"] ?? "hectares");
	if ($unitInput === "square-meters") {
		$length = parseDecimalValue($data["areaLength"] ?? "0");
		$width = parseDecimalValue($data["areaWidth"] ?? "0");
		$total = parseDecimalValue($data["areaPlanted"] ?? "0");

		if ($total <= 0 && $length > 0 && $width > 0) {
			$total = $length * $width;
		}

		if ($total < 0) {
			$total = 0;
		}

		return [
			"area_value" => round($total, 2),
			"area_unit" => "sqm",
			"length" => $length,
			"width" => $width,
			"total" => round($total, 2)
		];
	}

	$hectares = parseDecimalValue($data["areaPlanted"] ?? "0");
	if ($hectares < 0) {
		$hectares = 0;
	}

	return [
		"area_value" => round($hectares, 2),
		"area_unit" => "hectare",
		"length" => 0,
		"width" => 0,
		"total" => round($hectares, 2)
	];
}

function computeEstimatedHarvestDate(string $plantingDate, array $data): string
{
	$days = (int) ($data["daysToHarvestMin"] ?? 0);
	if ($days <= 0) {
		$days = (int) ($data["daysToHarvestMax"] ?? 0);
	}
	if ($days <= 0) {
		$days = 70;
	}

	$baseDate = DateTime::createFromFormat("Y-m-d", $plantingDate);
	if (!$baseDate) {
		return $plantingDate;
	}

	$baseDate->modify("+" . $days . " days");
	return $baseDate->format("Y-m-d");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
	$rawBody = file_get_contents("php://input");
	$payload = json_decode($rawBody, true);
	$action = is_array($payload) ? ($payload["action"] ?? "") : "";

	if ($action === "save_profile") {
		header("Content-Type: application/json; charset=UTF-8");

		$data = is_array($payload["data"] ?? null) ? $payload["data"] : null;
		if (!$data) {
			http_response_code(400);
			echo json_encode(["success" => false, "message" => "Invalid data payload."]);
			exit;
		}

		$requiredKeys = [
			"plantingDate",
			"farmLocation",
			"numberOfPacks",
			"kgOfPacks",
			"plantingDensity",
			"seedsPerHole",
			"soilType",
			"estimatedSeeds"
		];

		foreach ($requiredKeys as $key) {
			if (!isset($data[$key]) || trim((string) $data[$key]) === "") {
				http_response_code(422);
				echo json_encode(["success" => false, "message" => "Please fill in all required fields."]);
				exit;
			}
		}

		$areaUnit = (string) ($data["areaUnit"] ?? "hectares");
		if ($areaUnit === "hectares" && trim((string) ($data["areaPlanted"] ?? "")) === "") {
			http_response_code(422);
			echo json_encode(["success" => false, "message" => "Area planted is required."]);
			exit;
		}

		if ($areaUnit === "square-meters") {
			if (trim((string) ($data["areaLength"] ?? "")) === "" || trim((string) ($data["areaWidth"] ?? "")) === "") {
				http_response_code(422);
				echo json_encode(["success" => false, "message" => "Length and width are required for square meters."]);
				exit;
			}
		}

		$usersId = (int) $_SESSION["users_id"];
		$profileOwnerName = trim((string) ($_SESSION["name"] ?? ""));
		if ($profileOwnerName === "") {
			$profileOwnerName = trim((string) ($_SESSION["username"] ?? ""));
		}
		if ($profileOwnerName === "") {
			$profileOwnerName = "Farmer " . $usersId;
		}
		$plantingDate = trim((string) ($data["plantingDate"] ?? ""));
		$farmLocation = trim((string) ($data["farmLocation"] ?? ""));
		$cornType = trim((string) ($data["typeOfCorn"] ?? ""));
		$cornVariety = trim((string) ($data["cornVariety"] ?? ""));
		$numberOfPacks = max(0, parseIntValue($data["numberOfPacks"] ?? "0"));
		$weightOfPacks = max(0, parseDecimalValue($data["kgOfPacks"] ?? "0"));
		$plantingDensity = max(0, parseDecimalValue($data["plantingDensity"] ?? "0"));
		$seedsPerHole = max(0, parseIntValue($data["seedsPerHole"] ?? "0"));
		$soilType = trim((string) ($data["soilType"] ?? ""));
		$estimatedSeedsRange = trim((string) ($data["estimatedSeeds"] ?? ""));
		$areaDetails = parseAreaDetails($data);
		$areaValue = (float) $areaDetails["area_value"];
		$areaUnitDb = (string) $areaDetails["area_unit"];
		$estimatedHarvestDate = computeEstimatedHarvestDate($plantingDate, $data);

		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $plantingDate)) {
			http_response_code(422);
			echo json_encode(["success" => false, "message" => "Invalid planting date format."]);
			exit;
		}

		if ($areaDetails["area_value"] <= 0) {
			http_response_code(422);
			echo json_encode(["success" => false, "message" => "Area planted must be greater than zero."]);
			exit;
		}

		// Keep a single total area value for DB consistency.
		if ($areaUnit === "square-meters") {
			$data["areaPlanted"] = (string) $areaDetails["total"];
		}

		try {
			require __DIR__ . "/../data/db_connect.php";

			$conn->begin_transaction();

			$existingId = null;
			$checkStmt = $conn->prepare("SELECT corn_profile_id FROM corn_profile WHERE users_id = ? AND status = 'active' ORDER BY corn_profile_id DESC LIMIT 1");
			$checkStmt->bind_param("i", $usersId);
			$checkStmt->execute();
			$checkStmt->bind_result($existingId);
			$hasExisting = $checkStmt->fetch();
			$checkStmt->close();

			if ($hasExisting && $existingId !== null) {
				$updateStmt = $conn->prepare(
					"UPDATE corn_profile
					SET name = ?,
						planting_date = ?,
						estimated_harvest_date = ?,
						farm_location = ?,
						area_value = ?,
						area_unit = ?,
						corn_type = ?,
						corn_variety = ?,
						number_of_packs = ?,
						weight_of_packs = ?,
						planting_density = ?,
						seeds_per_hole = ?,
						soil_type = ?,
						estimated_seeds_range = ?
					WHERE corn_profile_id = ? AND users_id = ? AND status = 'active'"
				);
				$updateStmt->bind_param(
					"ssssdsssiddissii",
					$profileOwnerName,
					$plantingDate,
					$estimatedHarvestDate,
					$farmLocation,
					$areaValue,
					$areaUnitDb,
					$cornType,
					$cornVariety,
					$numberOfPacks,
					$weightOfPacks,
					$plantingDensity,
					$seedsPerHole,
					$soilType,
					$estimatedSeedsRange,
					$existingId,
					$usersId
				);
				$updateStmt->execute();
				$updateStmt->close();
			} else {
				$insertStmt = $conn->prepare(
					"INSERT INTO corn_profile (
						users_id,
						name,
						planting_date,
						estimated_harvest_date,
						farm_location,
						area_value,
						area_unit,
						corn_type,
						corn_variety,
						number_of_packs,
						weight_of_packs,
						planting_density,
						seeds_per_hole,
						soil_type,
						estimated_seeds_range,
						status
					) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
				);
				$status = "active";
				$insertStmt->bind_param(
					"issssdsssiddisss",
					$usersId,
					$profileOwnerName,
					$plantingDate,
					$estimatedHarvestDate,
					$farmLocation,
					$areaValue,
					$areaUnitDb,
					$cornType,
					$cornVariety,
					$numberOfPacks,
					$weightOfPacks,
					$plantingDensity,
					$seedsPerHole,
					$soilType,
					$estimatedSeedsRange,
					$status
				);
				$insertStmt->execute();
				$insertStmt->close();
			}

			$conn->commit();
		} catch (Throwable $e) {
			if (isset($conn) && $conn instanceof mysqli) {
				$conn->rollback();
			}

			http_response_code(500);
			echo json_encode(["success" => false, "message" => "Failed to save profile to database."]);
			exit;
		}

		echo json_encode(["success" => true]);
		exit;
	}

	if ($action === "save_costing") {
		header("Content-Type: application/json; charset=UTF-8");

		$data = is_array($payload["data"] ?? null) ? $payload["data"] : null;
		if (!$data) {
			http_response_code(400);
			echo json_encode(["success" => false, "message" => "Invalid costing payload."]);
			exit;
		}

		$rows = is_array($data["rows"] ?? null) ? $data["rows"] : [];
		$normalizedRows = [];

		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$type = trim((string) ($row["type"] ?? ""));
			$source = trim((string) ($row["source"] ?? ""));
			$cost = (float) ($row["cost"] ?? 0);

			if ($type === "") {
				continue;
			}

			if ($cost < 0) {
				$cost = 0;
			}

			$normalizedRows[] = [
				"id" => trim((string) ($row["id"] ?? "")),
				"source" => $source,
				"type" => $type,
				"cost" => round($cost, 2),
				"extraId" => trim((string) ($row["extraId"] ?? ""))
			];
		}

		$usersId = (int) $_SESSION["users_id"];

		try {
			require __DIR__ . "/../data/db_connect.php";

			$conn->begin_transaction();

			$deleteStmt = $conn->prepare("DELETE FROM costing WHERE users_id = ?");
			$deleteStmt->bind_param("i", $usersId);
			$deleteStmt->execute();
			$deleteStmt->close();

			if (count($normalizedRows) > 0) {
				$insertStmt = $conn->prepare("INSERT INTO costing (users_id, expense_type, cost) VALUES (?, ?, ?)");

				foreach ($normalizedRows as $row) {
					$expenseType = trim((string) ($row["type"] ?? ""));
					$costValue = (float) ($row["cost"] ?? 0);

					if ($expenseType === "") {
						continue;
					}

					if ($costValue < 0) {
						$costValue = 0;
					}

					$insertStmt->bind_param("isd", $usersId, $expenseType, $costValue);
					$insertStmt->execute();
				}

				$insertStmt->close();
			}

			$conn->commit();
		} catch (Throwable $e) {
			if (isset($conn) && $conn instanceof mysqli) {
				$conn->rollback();
			}

			http_response_code(500);
			echo json_encode(["success" => false, "message" => "Failed to save costing to database."]);
			exit;
		}

		echo json_encode(["success" => true]);
		exit;
	}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Corn Planting Profile</title>
	<link rel="stylesheet" href="../bootstrap5/css/bootstrap.min.css">
	<style>
		:root {
			--background: #fafdf7;
			--foreground: #2c3e2e;
			--primary: #7fb685;
			--secondary: #ffe599;
			--muted-foreground: #6b7c6e;
			--card: #ffffff;
			--accent: #f3f8ef;
			--line: rgba(127, 182, 133, 0.28);
			--input-line: #9ca3af;
			--shadow-sm: 0 4px 14px rgba(37, 56, 40, 0.09);
			--shadow-md: 0 10px 28px rgba(37, 56, 40, 0.14);
		}

		* {
			box-sizing: border-box;
		}

		html,
		body {
			margin: 0;
			min-height: 100%;
			color: var(--foreground);
			font-family: "Inter", "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
			background: var(--background);
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

		.tabs-shell {
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			padding: 4px;
			gap: 4px;
			margin-left: 2cm;
			margin-right: 2cm;
			border: 1px solid rgba(127, 182, 133, 0.2);
			border-radius: 12px;
			background: rgba(232, 243, 234, 0.8);
		}

		.tab-btn {
			border: 0;
			border-radius: 8px;
			padding: 11px 10px;
			font-weight: 600;
			font-size: 0.95rem;
			background: transparent;
			color: #4f6552;
			cursor: pointer;
			transition: all 0.2s ease;
		}

		.tab-btn.active {
			background: #fff;
			color: #2e4531;
			box-shadow: 0 2px 8px rgba(37, 56, 40, 0.12);
		}

		.tab-btn.disabled {
			opacity: 0.55;
			cursor: not-allowed;
		}

		.tab-content {
			display: none;
		}

		.tab-content.active {
			display: block;
		}

		.card-panel {
			border-radius: 12px;
			border: 1px solid var(--line);
			background: var(--card);
			box-shadow: var(--shadow-sm);
			overflow: hidden;
		}

		#plantingFormView,
		#contentCosting .card-panel {
			margin-left: 2cm;
			margin-right: 2cm;
		}

		#plantingCertificateView {
			margin-left: 2cm;
			margin-right: 2cm;
		}

		.card-head {
			padding: 22px 24px 8px;
		}

		.card-title {
			margin: 0;
			font-size: 1.5rem;
			font-weight: 600;
		}

		.card-sub {
			margin: 3px 0 0;
			font-size: 0.92rem;
			color: var(--muted-foreground);
		}

		.card-body {
			padding: 16px 24px 24px;
		}

		.vstack-6 > * + * {
			margin-top: 22px;
		}

		.label-strong {
			display: block;
			margin-bottom: 6px;
			font-size: 0.96rem;
			font-weight: 600;
		}

		.input-clean,
		.select-clean {
			width: 100%;
			min-height: 42px;
			padding: 8px 12px;
			border-radius: 8px;
			border: 2px solid var(--input-line);
			background: #fff;
			color: #2f4a32;
			outline: none;
		}

		.input-clean:focus,
		.select-clean:focus {
			border-color: var(--primary);
			box-shadow: 0 0 0 3px rgba(127, 182, 133, 0.2);
		}

		.input-clean[readonly] {
			background: #f3f4f6;
		}

		.select-clean option {
			background: #f3f8ef;
			color: #2f4a32;
		}

		.select-clean option:checked {
			background: #dbeedc;
			color: #23412a;
		}

		.area-radios {
			display: flex;
			align-items: center;
			gap: 24px;
			flex-wrap: nowrap;
		}

		.area-radios .form-check {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			margin: 0;
			padding-left: 0;
		}

		.area-radios .form-check-input {
			margin: 0;
			cursor: pointer;
			border-color: rgba(127, 182, 133, 0.55);
		}

		.area-radios .form-check-input:checked {
			background-color: var(--primary);
			border-color: var(--primary);
		}

		.area-radios .form-check-input:focus {
			box-shadow: 0 0 0 3px rgba(127, 182, 133, 0.24);
		}

		.area-radios .form-check-label {
			cursor: pointer;
			color: #355438;
			font-weight: 600;
		}

		.btn-save {
			width: 100%;
			min-height: 45px;
			border: 0;
			border-radius: 9px;
			background: var(--primary);
			color: #fff;
			font-weight: 600;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			gap: 8px;
			transition: filter 0.2s ease;
		}

		.btn-save:hover {
			filter: brightness(0.95);
		}

		.btn-outline-clean {
			min-height: 40px;
			padding: 8px 14px;
			border-radius: 9px;
			background: #fff;
			border: 1px solid var(--line);
			font-weight: 600;
			color: #2e4531;
			display: inline-flex;
			align-items: center;
			gap: 8px;
		}

		.certificate-card {
			border-radius: 12px;
			border: 4px solid rgba(127, 182, 133, 0.4);
			background: linear-gradient(135deg, #fff, rgba(127, 182, 133, 0.05), rgba(255, 229, 153, 0.1));
			box-shadow: var(--shadow-md);
		}

		.certificate-inner {
			padding: 32px;
		}

		.cert-head {
			text-align: center;
			margin-bottom: 30px;
			padding-bottom: 24px;
			border-bottom: 4px solid rgba(127, 182, 133, 0.2);
			display: flex;
			flex-direction: column;
			align-items: center;
			gap: 10px;
		}

		.cert-badge {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 6px 14px;
			border-radius: 999px;
			font-size: 0.72rem;
			font-weight: 700;
			letter-spacing: 0.1em;
			text-transform: uppercase;
			color: #2d5a34;
			border: 1px solid rgba(127, 182, 133, 0.4);
			background: rgba(127, 182, 133, 0.14);
		}

		.cert-title {
			margin: 2px 0 0;
			font-size: 1.9rem;
			font-weight: 700;
			letter-spacing: 0.03em;
			font-family: "Georgia", "Times New Roman", serif;
			color: #27452c;
		}

		.cert-sub {
			margin: 0;
			font-size: 0.9rem;
			font-weight: 600;
			color: var(--muted-foreground);
			letter-spacing: 0.04em;
			text-transform: uppercase;
		}

		.cert-divider {
			width: 100%;
			max-width: 360px;
			height: 12px;
			position: relative;
		}

		.cert-divider::before,
		.cert-divider::after {
			content: "";
			position: absolute;
			top: 50%;
			width: 42%;
			height: 2px;
			background: linear-gradient(90deg, rgba(127, 182, 133, 0.08), rgba(127, 182, 133, 0.6));
		}

		.cert-divider::before {
			left: 0;
		}

		.cert-divider::after {
			right: 0;
			transform: scaleX(-1);
		}

		.cert-divider span {
			position: absolute;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%);
			width: 10px;
			height: 10px;
			border-radius: 50%;
			background: #7fb685;
			box-shadow: 0 0 0 3px rgba(127, 182, 133, 0.2);
		}

		.cert-note {
			margin: 0;
			font-size: 0.82rem;
			color: var(--muted-foreground);
		}

		.cert-stack {
			display: grid;
			grid-template-columns: repeat(1, minmax(0, 1fr));
			gap: 24px;
		}

		.two-col {
			display: grid;
			grid-template-columns: repeat(1, minmax(0, 1fr));
			gap: 24px;
		}

		@media (min-width: 768px) {
			.two-col {
				grid-template-columns: repeat(2, minmax(0, 1fr));
			}
		}

		.cert-item {
			padding: 16px;
			border-radius: 10px;
			border: 2px solid rgba(127, 182, 133, 0.2);
			background: rgba(243, 248, 239, 0.7);
		}

		.cert-k {
			margin: 0;
			font-size: 0.73rem;
			text-transform: uppercase;
			letter-spacing: 0.08em;
			color: var(--muted-foreground);
		}

		.cert-v {
			margin: 4px 0 0;
			font-size: 1.12rem;
			font-weight: 600;
			word-break: break-word;
		}

		.cert-highlight {
			padding: 16px;
			border-radius: 10px;
			border: 2px solid rgba(127, 182, 133, 0.3);
			background: rgba(127, 182, 133, 0.1);
		}

		.cert-highlight .cert-v {
			font-size: 1.28rem;
			font-weight: 700;
			color: var(--primary);
		}

		.cert-foot {
			margin-top: 30px;
			padding-top: 20px;
			border-top: 4px solid rgba(127, 182, 133, 0.2);
			text-align: center;
			color: var(--muted-foreground);
			font-size: 0.86rem;
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 8px;
		}

		.cost-box {
			padding: 16px;
			border-radius: 10px;
			border: 2px solid rgba(127, 182, 133, 0.2);
			background: var(--accent);
		}

		.costing-layout {
			display: grid;
			grid-template-columns: minmax(0, 1.15fr) minmax(0, 0.85fr);
			gap: 20px;
			align-items: start;
		}

		.costing-col {
			min-width: 0;
		}

		.cost-empty {
			text-align: center;
			padding: 28px 10px;
			color: var(--muted-foreground);
		}

		.add-cost-row {
			display: flex;
			gap: 12px;
			align-items: flex-end;
		}

		.add-cost-row .col-flex {
			flex: 1;
		}

		.btn-icon-danger {
			width: 40px;
			height: 40px;
			border: 1px solid rgba(214, 57, 85, 0.38);
			border-radius: 8px;
			background: #fff;
			color: #d63955;
			display: inline-flex;
			align-items: center;
			justify-content: center;
		}

		.btn-icon-danger svg {
			width: 16px;
			height: 16px;
			fill: currentColor;
		}

		.cost-total {
			padding-top: 16px;
			border-top: 2px solid var(--line);
		}

		.cost-summary-grid {
			display: grid;
			gap: 10px;
		}

		.cost-summary-grid.top-row {
			grid-template-columns: repeat(3, minmax(0, 1fr));
			margin-bottom: 14px;
			align-items: stretch;
		}

		#contentCosting > .cost-summary-grid.top-row {
			margin-left: 2cm;
			margin-right: 2cm;
		}

		.cost-total-box {
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

		.cost-total-box.total {
			border-top: 4px solid #1f8b3f;
			background: linear-gradient(180deg, rgba(31, 139, 63, 0.14), rgba(255, 255, 255, 0.98));
		}

		.cost-total-box.income {
			border-top: 4px solid #1f8b3f;
			background: linear-gradient(180deg, rgba(31, 139, 63, 0.14), rgba(255, 255, 255, 0.98));
		}

		.cost-total-box.profit {
			border-top: 4px solid #1f8b3f;
			background: linear-gradient(180deg, rgba(31, 139, 63, 0.14), rgba(255, 255, 255, 0.98));
		}

		.cost-total-icon {
			width: 38px;
			height: 38px;
			border-radius: 11px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			margin-bottom: 2px;
		}

		.cost-total-icon svg {
			width: 18px;
			height: 18px;
			fill: currentColor;
		}

		.cost-total-box.total .cost-total-icon {
			background: rgba(31, 139, 63, 0.2);
			color: #1f8b3f;
		}

		.cost-total-box.income .cost-total-icon {
			background: rgba(31, 139, 63, 0.2);
			color: #1f8b3f;
		}

		.cost-total-box.profit .cost-total-icon {
			background: rgba(31, 139, 63, 0.2);
			color: #1f8b3f;
		}

		.cost-total-label {
			font-size: 0.78rem;
			text-transform: uppercase;
			letter-spacing: 0.06em;
			font-weight: 700;
			color: #5f6f63;
			margin: 0;
		}

		.cost-total-value {
			margin: 0;
			font-size: 1.65rem;
			line-height: 1;
			font-weight: 700;
			color: #1f8b3f;
		}

		.cost-income-value {
			margin: 0;
			font-size: 1.45rem;
			line-height: 1;
			font-weight: 700;
			color: #1f8b3f;
		}

		.cost-profit-value {
			margin: 0;
			font-size: 1.45rem;
			line-height: 1;
			font-weight: 700;
			color: #1f8b3f;
		}

		.cost-profit-value.negative {
			color: #c0392b;
		}

		.cost-profit-value.neutral {
			color: #5f6f63;
		}

		.cost-table-wrap {
			overflow-x: auto;
		}

		.cost-table {
			width: 100%;
			border-collapse: collapse;
			border: 1px solid var(--line);
			border-radius: 10px;
			overflow: hidden;
		}

		.cost-table th,
		.cost-table td {
			padding: 10px 12px;
			border-bottom: 1px solid rgba(127, 182, 133, 0.18);
			text-align: left;
			font-size: 0.92rem;
		}

		.cost-table thead th {
			background: rgba(127, 182, 133, 0.1);
			font-weight: 700;
		}

		.cost-table tbody tr:last-child td {
			border-bottom: 0;
		}

		.confirm-mask {
			position: fixed;
			inset: 0;
			background: rgba(0, 0, 0, 0.35);
			opacity: 0;
			visibility: hidden;
			transition: opacity 0.2s ease;
			z-index: 90;
		}

		.confirm-mask.show {
			opacity: 1;
			visibility: visible;
		}

		.confirm-box {
			position: fixed;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%) scale(0.96);
			width: min(92vw, 420px);
			background: #fff;
			border: 1px solid var(--line);
			border-radius: 12px;
			box-shadow: var(--shadow-md);
			padding: 16px;
			opacity: 0;
			visibility: hidden;
			transition: all 0.2s ease;
			z-index: 91;
		}

		.confirm-box.show {
			opacity: 1;
			visibility: visible;
			transform: translate(-50%, -50%) scale(1);
		}

		.confirm-title {
			margin: 0 0 8px;
			font-size: 1.1rem;
			font-weight: 700;
		}

		.confirm-message {
			margin: 0 0 14px;
			color: var(--muted-foreground);
		}

		.save-lock-note {
			font-weight: 600;
			color: #8a5a12;
		}

		.profile-toast {
			position: fixed;
			right: 18px;
			bottom: 18px;
			z-index: 120;
			max-width: min(340px, calc(100vw - 28px));
			padding: 10px 14px;
			border-radius: 12px;
			background: linear-gradient(135deg, #2f8f4f, #246f3b);
			color: #fff;
			font-size: 0.84rem;
			font-weight: 700;
			letter-spacing: 0.01em;
			line-height: 1.35;
			box-shadow: 0 12px 24px rgba(18, 35, 21, 0.24);
			opacity: 0;
			transform: translateY(12px) scale(0.98);
			transition: opacity 0.24s ease, transform 0.24s ease;
			pointer-events: none;
		}

		.profile-toast.show {
			opacity: 1;
			transform: translateY(0) scale(1);
		}

		.profile-toast.is-error {
			background: linear-gradient(135deg, #a43f3f, #7d2f2f);
		}

		@media (max-width: 767px) {
			.head-inner,
			.page-inner {
				padding-left: 14px;
				padding-right: 14px;
			}

			.tabs-shell,
			#contentCosting > .cost-summary-grid.top-row,
			#plantingFormView,
			#contentCosting .card-panel,
			#plantingCertificateView {
				margin-left: 0;
				margin-right: 0;
			}

			.profile-toast {
				right: 12px;
				left: 12px;
				bottom: 12px;
				max-width: none;
			}

			.card-head,
			.card-body {
				padding-left: 14px;
				padding-right: 14px;
			}

			.certificate-inner {
				padding: 18px 14px;
			}

			.cert-badge {
				font-size: 0.66rem;
				padding: 5px 10px;
			}

			.cert-title {
				font-size: 1.45rem;
			}

			.cert-sub {
				font-size: 0.78rem;
			}

			.cert-note {
				font-size: 0.76rem;
			}

			.add-cost-row {
				flex-direction: column;
				align-items: stretch;
			}

			.add-cost-row .col-flex {
				width: 100%;
			}

			.btn-icon-danger {
				width: 100%;
			}
		}

		@media (max-width: 991px) {
			.cost-summary-grid.top-row {
				grid-template-columns: minmax(0, 1fr);
			}

			.costing-layout {
				grid-template-columns: minmax(0, 1fr);
			}
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
					<h1 class="page-title">Corn Planting Profile</h1>
					<p class="page-sub">Manage your corn varieties and planting data</p>
				</div>
			</div>
		</div>
	</header>

	<main class="page-inner">
		<div class="tabs-shell mb-4" role="tablist">
			<button class="tab-btn active" id="tabPlanting" type="button">Planting Data Input</button>
			<button class="tab-btn disabled" id="tabCosting" type="button">Costing (Locked)</button>
		</div>

		<section class="tab-content active" id="contentPlanting">
			<div id="plantingFormView" class="card-panel">
				<div class="card-head">
					<h2 class="card-title">Enter Planting Information</h2>
					<p class="card-sub">Fill in all the details about your corn planting</p>
				</div>

				<div class="card-body vstack-6">
					<div class="row g-4">
						<div class="col-12 col-md-6">
							<label class="label-strong" for="plantingDate">Planting Date</label>
							<input class="input-clean" id="plantingDate" type="date">
						</div>
						<div class="col-12 col-md-6">
							<label class="label-strong" for="farmLocation">Farm Location</label>
							<select class="select-clean" id="farmLocation">
								<option value="">--Select Location--</option>
								<option value="Bagong Silang, Calatagan, Batangas">Bagong Silang, Calatagan, Batangas</option>
								<option value="Baha, Calatagan, Batangas">Baha, Calatagan, Batangas</option>
								<option value="Balibago, Calatagan, Batangas">Balibago, Calatagan, Batangas</option>
								<option value="Balitoc, Calatagan, Batangas">Balitoc, Calatagan, Batangas</option>
								<option value="Barangay 1, Calatagan, Batangas">Barangay 1, Calatagan, Batangas</option>
								<option value="Barangay 2, Calatagan, Batangas">Barangay 2, Calatagan, Batangas</option>
								<option value="Barangay 3, Calatagan, Batangas">Barangay 3, Calatagan, Batangas</option>
								<option value="Barangay 4, Calatagan, Batangas">Barangay 4, Calatagan, Batangas</option>
								<option value="Biga, Calatagan, Batangas">Biga, Calatagan, Batangas</option>
								<option value="Bucal, Calatagan, Batangas">Bucal, Calatagan, Batangas</option>
								<option value="Carlosa, Calatagan, Batangas">Carlosa, Calatagan, Batangas</option>
								<option value="Carretunan, Calatagan, Batangas">Carretunan, Calatagan, Batangas</option>
								<option value="Encarnacion, Calatagan, Batangas">Encarnacion, Calatagan, Batangas</option>
								<option value="Gulod, Calatagan, Batangas">Gulod, Calatagan, Batangas</option>
								<option value="Hukay, Calatagan, Batangas">Hukay, Calatagan, Batangas</option>
								<option value="Lucsuhin, Calatagan, Batangas">Lucsuhin, Calatagan, Batangas</option>
								<option value="Luya, Calatagan, Batangas">Luya, Calatagan, Batangas</option>
								<option value="Paraiso, Calatagan, Batangas">Paraiso, Calatagan, Batangas</option>
								<option value="Quilitisan, Calatagan, Batangas">Quilitisan, Calatagan, Batangas</option>
								<option value="Real, Calatagan, Batangas">Real, Calatagan, Batangas</option>
								<option value="Sambungan, Calatagan, Batangas">Sambungan, Calatagan, Batangas</option>
								<option value="Santa Ana, Calatagan, Batangas">Santa Ana, Calatagan, Batangas</option>
								<option value="Talibayog, Calatagan, Batangas">Talibayog, Calatagan, Batangas</option>
								<option value="Talisay, Calatagan, Batangas">Talisay, Calatagan, Batangas</option>
								<option value="Tanagan, Calatagan, Batangas">Tanagan, Calatagan, Batangas</option>
							</select>
						</div>
					</div>

					<div class="row g-4">
						<div class="col-12 col-md-6">
							<label class="label-strong" for="typeOfCorn">Type of Corn</label>
							<select class="select-clean" id="typeOfCorn">
								<option value="">--Select Type of Corn--</option>
								<option value="Sweet Corn (Hybrid)">Sweet Corn (Hybrid)</option>
								<option value="Sweet Corn (Native)">Sweet Corn (Native)</option>
								<option value="Sweet Corn (OPV)">Sweet Corn (OPV)</option>
								<option value="Glutinous / Waxy Corn">Glutinous / Waxy Corn</option>
								<option value="Yellow Corn (Hybrid)">Yellow Corn (Hybrid)</option>
								<option value="Yellow Corn (Feeds)">Yellow Corn (Feeds)</option>
								<option value="Yellow Corn (Native)">Yellow Corn (Native)</option>
								<option value="White Corn (Field)">White Corn (Field)</option>
								<option value="White Corn (Native)">White Corn (Native)</option>
								<option value="Popcorn">Popcorn</option>
								<option value="Baby Corn">Baby Corn</option>
								<option value="Other (Specify)">Other (Specify)</option>
							</select>
							<input class="input-clean mt-2 d-none" id="typeOfCornOther" type="text" placeholder="Specify type of corn">
						</div>
						<div class="col-12 col-md-6">
							<label class="label-strong" for="cornVariety">Corn Variety</label>
							<select class="select-clean" id="cornVariety">
								<option value="">--Select Variety--</option>
								<option value="Other (Specify)">Other (Specify)</option>
							</select>
							<input class="input-clean mt-2 d-none" id="cornVarietyOther" type="text" placeholder="Specify corn variety">
						</div>
					</div>

					<div class="row g-4">
						<div class="col-12 col-md-6">
							<label class="label-strong" for="numberOfPacks">Number of Packs</label>
							<input class="input-clean" id="numberOfPacks" type="number" min="0">
						</div>
						<div class="col-12 col-md-6">
							<label class="label-strong" for="kgOfPacks">Kg of Packs</label>
							<input class="input-clean" id="kgOfPacks" type="number" step="0.01" min="0">
						</div>
					</div>

					<div>
						<label class="label-strong">Area Planted</label>
						<div class="area-radios mb-3">
							<div class="form-check">
								<input class="form-check-input" type="radio" name="areaUnit" id="areaHectares" value="hectares" checked>
								<label class="form-check-label" for="areaHectares">Hectares</label>
							</div>
							<div class="form-check">
								<input class="form-check-input" type="radio" name="areaUnit" id="areaSquareMeters" value="square-meters">
								<label class="form-check-label" for="areaSquareMeters">Square Meters</label>
							</div>
						</div>

						<div id="hectaresWrap">
							<input class="input-clean" id="areaPlanted" type="number" step="0.01" min="0">
						</div>

						<div id="squareMetersWrap" class="d-none">
							<div class="row g-4">
								<div class="col-12 col-md-4">
									<label class="label-strong" for="areaLength">Length</label>
									<input class="input-clean" id="areaLength" type="number" step="0.01" min="0">
								</div>
								<div class="col-12 col-md-4">
									<label class="label-strong" for="areaWidth">Width</label>
									<input class="input-clean" id="areaWidth" type="number" step="0.01" min="0">
								</div>
								<div class="col-12 col-md-4">
									<label class="label-strong" for="areaTotal">Total (sq m)</label>
									<input class="input-clean" id="areaTotal" type="text" readonly>
								</div>
							</div>
						</div>
					</div>

					<div class="row g-4">
						<div class="col-12 col-md-6">
							<label class="label-strong" for="plantingDensity">Planting Density / Spacing</label>
							<input class="input-clean" id="plantingDensity" type="text">
						</div>
						<div class="col-12 col-md-6">
							<label class="label-strong" for="seedsPerHole">Seeds per Hill/Hole</label>
							<input class="input-clean" id="seedsPerHole" type="number" min="0">
						</div>
					</div>

					<div class="row g-4">
						<div class="col-12 col-md-6">
							<label class="label-strong" for="soilType">Soil Type</label>
							<select class="select-clean" id="soilType">
								<option value="">Select soil type</option>
								<option value="loamy">Loamy</option>
								<option value="sandy">Sandy</option>
								<option value="clay">Clay</option>
								<option value="silty">Silty</option>
								<option value="peaty">Peaty</option>
								<option value="chalky">Chalky</option>
							</select>
						</div>
						<div class="col-12 col-md-6">
							<label class="label-strong" for="estimatedSeeds">Estimated Number of Corn Seeds</label>
							<input class="input-clean" id="estimatedSeeds" type="text" placeholder="Auto-computed range or manual input">
							<p class="card-sub mt-2 mb-0" id="estimatedSeedsHint">Tip: Select Type of Corn and enter packs/kg to auto-compute seed range.</p>
						</div>
					</div>

					<button id="savePlantingBtn" class="btn-save" type="button">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7zm-5 16a3 3 0 1 1 0-6 3 3 0 0 1 0 6m3-10H5V5h10z"/></svg>
						Save Planting Data
					</button>
					<p class="card-sub mt-2 mb-0 save-lock-note">Note: Once saved, this planting data can no longer be edited.</p>
				</div>
			</div>

			<div id="plantingCertificateView" class="d-none">
				<article class="certificate-card">
					<div class="certificate-inner">
						<div class="cert-head">
							<p class="cert-badge">Verified Record</p>
							<h3 class="cert-title">Corn Planting Profile</h3>
							<p class="cert-sub">Official Planting Record Certificate</p>
							<div class="cert-divider" aria-hidden="true"><span></span></div>
							<p class="cert-note">Issued for documentation of registered planting details.</p>
						</div>

						<div class="cert-stack" id="certStack"></div>

						<div class="cert-foot" id="certGeneratedOn"></div>
					</div>
				</article>
			</div>
		</section>

		<section class="tab-content" id="contentCosting">
			<div class="cost-summary-grid top-row">
				<div class="cost-total-box total">
					<span class="cost-total-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24"><path d="M6 3h12a2 2 0 0 1 2 2v14l-4-2-4 2-4-2-4 2V5a2 2 0 0 1 2-2zm1 4v2h10V7H7zm0 4v2h7v-2H7z"></path></svg>
					</span>
					<span class="cost-total-label">Total Cost</span>
					<p class="cost-total-value" id="totalCostText">&#8369; 0.00</p>
				</div>
				<div class="cost-total-box income">
					<span class="cost-total-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24"><path d="M4 17h2v3H4v-3zm4-6h2v9H8v-9zm4-4h2v13h-2V7zm4 6h2v7h-2v-7zm4-10h2v17h-2V3z"></path></svg>
					</span>
					<span class="cost-total-label">Estimated Income</span>
					<p class="cost-income-value" id="estimatedIncomeText">N/A</p>
				</div>
				<div class="cost-total-box profit">
					<span class="cost-total-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24"><path d="m4 16 4-4 3 3 6-7 3 3v-4h-4l2 2-5 6-3-3-6 6z"></path></svg>
					</span>
					<span class="cost-total-label">Estimated Profit</span>
					<p class="cost-profit-value neutral" id="estimatedProfitText">N/A</p>
				</div>
			</div>

			<div class="card-panel">
				<div class="card-head">
					<h2 class="card-title">Planting Costs</h2>
					<p class="card-sub">Record all expenses related to this planting</p>
				</div>

				<div class="card-body">
					<div class="costing-layout">
						<div class="costing-col vstack-6">
							<div class="cost-table-wrap">
								<table class="cost-table">
									<thead>
										<tr>
											<th>Expense Type</th>
											<th>Cost</th>
											<th>Action</th>
										</tr>
									</thead>
									<tbody id="savedCostsTableBody">
										<tr>
											<td colspan="3" class="text-center text-secondary">No saved costs yet.</td>
										</tr>
									</tbody>
								</table>
							</div>
						</div>

						<div class="costing-col vstack-6">
							<div>
								<div class="d-flex align-items-center justify-content-between mb-3 gap-2 flex-wrap">
									<label class="label-strong mb-0">Additional Costs</label>
									<button class="btn-outline-clean" id="addCostBtn" type="button">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19 11H13V5h-2v6H5v2h6v6h2v-6h6z"/></svg>
										Add Cost Item
									</button>
								</div>

								<div class="vstack-6">
									<div class="cost-box" id="seedCostBox">
										<div class="d-flex align-items-center justify-content-between mb-2">
											<label class="label-strong mb-0" for="seedCost">Seeds Cost</label>
											<span class="small text-secondary-emphasis">Default cost calculated</span>
										</div>
										<input class="input-clean" id="seedCost" type="number" step="0.01" min="0" value="0">
									</div>

									<div class="cost-box" id="laborCostBox">
										<div class="d-flex align-items-center justify-content-between mb-2">
											<label class="label-strong mb-0" for="laborCost">Labor Cost</label>
											<span class="small text-secondary-emphasis">Default expense item</span>
										</div>
										<input class="input-clean" id="laborCost" type="number" step="0.01" min="0" value="0">
									</div>

									<div id="additionalCostsWrap" class="vstack-6"></div>
									<div id="emptyCostText" class="cost-empty">No additional costs added yet. Click "Add Cost Item" to add expenses.</div>

									<div>
										<button class="btn-save" id="saveCostsBtn" type="button">
											Save Costs
										</button>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</section>
	</main>

	<div class="profile-toast" id="profileToast" role="status" aria-live="polite" aria-atomic="true"></div>

	<div class="confirm-mask" id="confirmMask"></div>
	<div class="confirm-box" id="confirmBox" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
		<h3 class="confirm-title" id="confirmTitle">Please Confirm</h3>
		<p class="confirm-message" id="confirmMessage">Are you sure?</p>
		<div class="d-flex justify-content-end gap-2">
			<button class="btn btn-outline-secondary" id="confirmNo" type="button">Cancel</button>
			<button class="btn btn-success" id="confirmYes" type="button">Yes</button>
		</div>
	</div>

	<script>
		(function () {
			var tabPlanting = document.getElementById("tabPlanting");
			var tabCosting = document.getElementById("tabCosting");
			var contentPlanting = document.getElementById("contentPlanting");
			var contentCosting = document.getElementById("contentCosting");

			var plantingFormView = document.getElementById("plantingFormView");
			var plantingCertificateView = document.getElementById("plantingCertificateView");

			var areaHectares = document.getElementById("areaHectares");
			var areaSquareMeters = document.getElementById("areaSquareMeters");
			var hectaresWrap = document.getElementById("hectaresWrap");
			var squareMetersWrap = document.getElementById("squareMetersWrap");

			var areaLength = document.getElementById("areaLength");
			var areaWidth = document.getElementById("areaWidth");
			var areaTotal = document.getElementById("areaTotal");

			var typeOfCorn = document.getElementById("typeOfCorn");
			var cornVariety = document.getElementById("cornVariety");
			var typeOfCornOther = document.getElementById("typeOfCornOther");
			var cornVarietyOther = document.getElementById("cornVarietyOther");

			var savePlantingBtn = document.getElementById("savePlantingBtn");
			var certStack = document.getElementById("certStack");
			var certGeneratedOn = document.getElementById("certGeneratedOn");

			var seedCost = document.getElementById("seedCost");
			var seedCostBox = document.getElementById("seedCostBox");
			var laborCost = document.getElementById("laborCost");
			var laborCostBox = document.getElementById("laborCostBox");
			var addCostBtn = document.getElementById("addCostBtn");
			var saveCostsBtn = document.getElementById("saveCostsBtn");
			var additionalCostsWrap = document.getElementById("additionalCostsWrap");
			var emptyCostText = document.getElementById("emptyCostText");
			var savedCostsTableBody = document.getElementById("savedCostsTableBody");
			var totalCostText = document.getElementById("totalCostText");
			var estimatedIncomeText = document.getElementById("estimatedIncomeText");
			var estimatedProfitText = document.getElementById("estimatedProfitText");
			var estimatedSeeds = document.getElementById("estimatedSeeds");
			var estimatedSeedsHint = document.getElementById("estimatedSeedsHint");
			var confirmMask = document.getElementById("confirmMask");
			var confirmBox = document.getElementById("confirmBox");
			var confirmMessage = document.getElementById("confirmMessage");
			var confirmNo = document.getElementById("confirmNo");
			var confirmYes = document.getElementById("confirmYes");
			var profileToast = document.getElementById("profileToast");
			var profileToastTimer = null;
			var savedPlantingProfile = <?php echo json_encode($initialProfileData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
			var initialCostingData = <?php echo json_encode($initialCostingData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
			var marketPricesConfig = <?php echo $marketPricesJson; ?>.market_prices;
			var syncedMachineLearningIncome = null;
			var pendingCostingHandoff = null;
			try {
				pendingCostingHandoff = JSON.parse(localStorage.getItem('agricorn_costing_handoff') || 'null');
			} catch (error) {
				pendingCostingHandoff = null;
			}

			var costingUnlocked = false;
			var additionalCosts = [];
			var savedCostRows = [];
			var confirmOnYes = null;

			var varietyByType = {
				"Sweet Corn (Hybrid)": [
					{ name: "Sugar King F1", min: 70, max: 72, label: "70-72 days" },
					{ name: "Sweet Heart F1", min: 70, max: 75, label: "70-75 days" },
					{ name: "Super Sweet Sunshine", min: 70, max: 75, label: "70-75 days" },
					{ name: "Golden Bantam F1", min: 70, max: 75, label: "70-75 days" },
					{ name: "Silver Queen F1", min: 72, max: 75, label: "72-75 days" }
				],
				"Sweet Corn (Native)": [
					{ name: "Lagkitan", min: 70, max: 72, label: "70-72 days" },
					{ name: "Batangas Local Sweet", min: 70, max: 73, label: "70-73 days" },
					{ name: "Quezon Sweet", min: 70, max: 72, label: "70-72 days" },
					{ name: "Cavite Sweet", min: 70, max: 72, label: "70-72 days" }
				],
				"Sweet Corn (OPV)": [
					{ name: "Sugar Bon F2", min: 70, max: 75, label: "70-75 days" },
					{ name: "Super Sweet OPV", min: 70, max: 75, label: "70-75 days" },
					{ name: "Local OPV Sweet", min: 70, max: 72, label: "70-72 days" }
				],
				"Glutinous / Waxy Corn": [
					{ name: "Macapuno", min: 70, max: 70, label: "~70 days" },
					{ name: "Waxy White", min: 70, max: 72, label: "70-72 days" },
					{ name: "Waxy Yellow", min: 70, max: 72, label: "70-72 days" },
					{ name: "Laguna Waxy", min: 70, max: 72, label: "70-72 days" },
					{ name: "Los Banos Waxy", min: 70, max: 72, label: "70-72 days" }
				],
				"Yellow Corn (Hybrid)": [
					{ name: "NK6505", min: 100, max: 110, label: "100-110 days" },
					{ name: "Pioneer 31G98", min: 100, max: 105, label: "100-105 days" },
					{ name: "Dekalb DK6919", min: 105, max: 110, label: "105-110 days" },
					{ name: "DK8899S", min: 105, max: 110, label: "105-110 days" },
					{ name: "Golden Harvest YH103", min: 100, max: 110, label: "100-110 days" }
				],
				"Yellow Corn (Feeds)": [
					{ name: "Local Dent Corn 1", min: 100, max: 120, label: "100-120 days" },
					{ name: "Local Dent Corn 2", min: 100, max: 120, label: "100-120 days" },
					{ name: "Feed Hybrid A", min: 100, max: 115, label: "100-115 days" },
					{ name: "Feed Hybrid B", min: 100, max: 115, label: "100-115 days" }
				],
				"Yellow Corn (Native)": [
					{ name: "Batangas Yellow", min: 100, max: 110, label: "100-110 days" },
					{ name: "Quezon Yellow", min: 100, max: 110, label: "100-110 days" },
					{ name: "Cavite Yellow", min: 100, max: 110, label: "100-110 days" },
					{ name: "Mindoro Yellow", min: 100, max: 110, label: "100-110 days" }
				],
				"White Corn (Field)": [
					{ name: "Silver Queen", min: 90, max: 100, label: "90-100 days" },
					{ name: "Batangas White Dent", min: 90, max: 105, label: "90-105 days" },
					{ name: "Visayan White", min: 95, max: 110, label: "95-110 days" },
					{ name: "Ilocos White", min: 95, max: 110, label: "95-110 days" }
				],
				"White Corn (Native)": [
					{ name: "Local White Corn 1", min: 90, max: 105, label: "90-105 days" },
					{ name: "Local White Corn 2", min: 90, max: 105, label: "90-105 days" },
					{ name: "Visayan White Native", min: 95, max: 110, label: "95-110 days" }
				],
				"Popcorn": [
					{ name: "Local Popcorn", min: 90, max: 110, label: "90-110 days" },
					{ name: "Strawberry Popcorn", min: 95, max: 110, label: "95-110 days" },
					{ name: "Dakota Black", min: 100, max: 110, label: "100-110 days" },
					{ name: "Red Popcorn", min: 95, max: 110, label: "95-110 days" }
				],
				"Baby Corn": [
					{ name: "Minipop", min: 50, max: 65, label: "50-65 days" },
					{ name: "Early Hybrid Baby Corn 1", min: 50, max: 70, label: "50-70 days" },
					{ name: "Early Hybrid Baby Corn 2", min: 50, max: 70, label: "50-70 days" }
				]
			};

			function formatMoney(v) {
				var n = Number(v || 0);
				return "\u20B1 " + n.toLocaleString("en-US", {
					minimumFractionDigits: 2,
					maximumFractionDigits: 2
				});
			}

			function formatDate(iso) {
				return new Date(iso).toLocaleDateString("en-US", {
					year: "numeric",
					month: "long",
					day: "numeric"
				});
			}

			function typeLabel(value) {
				return value || "";
			}

			function soilLabel(value) {
				if (!value) {
					return "";
				}
				return value.charAt(0).toUpperCase() + value.slice(1);
			}

			function getSeedsPerKgRange(typeValue) {
				if (!typeValue) {
					return null;
				}

				if (
					typeValue === "Sweet Corn (Hybrid)" ||
					typeValue === "Sweet Corn (Native)" ||
					typeValue === "Sweet Corn (OPV)" ||
					typeValue === "Glutinous / Waxy Corn"
				) {
					return { min: 3500, max: 4500 };
				}

				if (
					typeValue === "Yellow Corn (Hybrid)" ||
					typeValue === "Yellow Corn (Feeds)" ||
					typeValue === "Yellow Corn (Native)" ||
					typeValue === "White Corn (Field)" ||
					typeValue === "White Corn (Native)"
				) {
					return { min: 3000, max: 4000 };
				}

				if (typeValue === "Popcorn" || typeValue === "Baby Corn") {
					return { min: 4000, max: 5000 };
				}

				return null;
			}

			function updateEstimatedSeedsField() {
				var packs = parseFloat(document.getElementById("numberOfPacks").value || "0");
				var kgPerPack = parseFloat(document.getElementById("kgOfPacks").value || "0");
				var rangePerKg = getSeedsPerKgRange(typeOfCorn.value);
				var canAuto = rangePerKg && packs > 0 && kgPerPack > 0;

				if (canAuto) {
					var totalKg = packs * kgPerPack;
					var minSeeds = Math.round(totalKg * rangePerKg.min);
					var maxSeeds = Math.round(totalKg * rangePerKg.max);
					estimatedSeeds.value = minSeeds.toLocaleString("en-US") + " - " + maxSeeds.toLocaleString("en-US");
					estimatedSeeds.readOnly = true;
					estimatedSeeds.dataset.mode = "auto";
					estimatedSeedsHint.textContent = "Auto-computed seed range from Type of Corn, Number of Packs, and Kg of Packs.";
					return;
				}

				if (estimatedSeeds.dataset.mode === "auto") {
					estimatedSeeds.value = "";
				}

				estimatedSeeds.readOnly = false;
				estimatedSeeds.dataset.mode = "manual";
				estimatedSeedsHint.textContent = "Manual mode: Type of Corn and Corn Variety are optional, so enter estimated seeds manually if auto-compute is unavailable.";
			}

			function getAreaUnit() {
				return areaSquareMeters.checked ? "square-meters" : "hectares";
			}

			function toggleAreaInput() {
				var sqm = getAreaUnit() === "square-meters";
				hectaresWrap.classList.toggle("d-none", sqm);
				squareMetersWrap.classList.toggle("d-none", !sqm);
			}

			function computeAreaTotal() {
				var l = parseFloat(areaLength.value || "0");
				var w = parseFloat(areaWidth.value || "0");
				var t = l * w;
				areaTotal.value = t.toLocaleString("en-US");
			}

			function getAreaText(data) {
				if (data.areaUnit === "hectares") {
					return data.areaPlanted + " Hectares";
				}

				var sqmFromArea = parseFloat(data.areaPlanted || "0") || 0;
				var l = parseFloat(data.areaLength || "0");
				var w = parseFloat(data.areaWidth || "0");
				var sqm = sqmFromArea > 0 ? sqmFromArea : l * w;

				if (l > 0 && w > 0) {
					return sqm.toLocaleString("en-US") + " sq m (" + l + "m x " + w + "m)";
				}

				return sqm.toLocaleString("en-US") + " sq m";
			}

			function getAreaHectares(data) {
				if (data.areaUnit === "hectares") {
					return parseFloat(data.areaPlanted || "0") || 0;
				}
				var l = parseFloat(data.areaLength || "0") || 0;
				var w = parseFloat(data.areaWidth || "0") || 0;
				return (l * w) / 10000;
			}

			function getAreaSqMeters(data) {
				if (!data) {
					return 0;
				}

				if (data.areaUnit === "hectares") {
					var hectares = parseFloat(data.areaPlanted || "0") || 0;
					return hectares > 0 ? hectares * 10000 : 0;
				}

				var areaPlantedSqM = parseFloat(data.areaPlanted || "0") || 0;
				if (areaPlantedSqM > 0) {
					return areaPlantedSqM;
				}

				var length = parseFloat(data.areaLength || "0") || 0;
				var width = parseFloat(data.areaWidth || "0") || 0;
				return length > 0 && width > 0 ? length * width : 0;
			}

			function getGrowthProjectionFromProfile(data) {
				if (!data) {
					return null;
				}

				var areaSqM = getAreaSqMeters(data);
				if (areaSqM <= 0) {
					return null;
				}

				var variety = String(data.cornVariety || data.typeOfCorn || "").toLowerCase();
				var soilType = String(data.soilType || "Loam").toLowerCase();
				var density = Number(data.plantingDensity || 60000);
				var seedsPerHole = Number(data.seedsPerHole || 1);
				var areaInHa = areaSqM / 10000;
				var baseYieldHa = 5.5;
				var varietyFactor = 1.0;
				var daysToMaturity = 110;

				if (variety.indexOf("sweet") !== -1) {
					if (variety.indexOf("hybrid") !== -1) varietyFactor = 0.8;
					else if (variety.indexOf("native") !== -1) varietyFactor = 0.65;
					else if (variety.indexOf("opv") !== -1) varietyFactor = 0.7;
					else varietyFactor = 0.75;
					daysToMaturity = 75;
				} else if (variety.indexOf("yellow") !== -1) {
					if (variety.indexOf("hybrid") !== -1) varietyFactor = 1.25;
					else if (variety.indexOf("feed") !== -1) varietyFactor = 1.15;
					else if (variety.indexOf("native") !== -1) varietyFactor = 0.95;
					else varietyFactor = 1.1;
					daysToMaturity = 115;
				} else if (variety.indexOf("white") !== -1) {
					if (variety.indexOf("field") !== -1) varietyFactor = 1.05;
					else if (variety.indexOf("native") !== -1) varietyFactor = 0.9;
					else varietyFactor = 0.95;
					daysToMaturity = 105;
				} else if (variety.indexOf("glutinous") !== -1 || variety.indexOf("waxy") !== -1) {
					varietyFactor = 0.85;
					daysToMaturity = 90;
				} else if (variety.indexOf("popcorn") !== -1) {
					varietyFactor = 0.55;
					daysToMaturity = 100;
				} else if (variety.indexOf("baby") !== -1) {
					varietyFactor = 0.4;
					daysToMaturity = 60;
				} else if (variety.indexOf("hybrid") !== -1) {
					varietyFactor = 1.2;
					daysToMaturity = 115;
				}

				var soilFactor = 1.0;
				if (soilType.indexOf("loam") !== -1) {
					soilFactor = 1.15;
				} else if (soilType.indexOf("clay") !== -1) {
					soilFactor = 0.9;
				} else if (soilType.indexOf("sandy") !== -1) {
					soilFactor = 0.85;
				}

				var densityFactor = 1.0;
				if (density < 40000) {
					densityFactor = 0.8;
				} else if (density > 80000) {
					densityFactor = 0.85;
				}

				var seedsFactor = 1.0;
				if (seedsPerHole > 2) {
					seedsFactor = 0.9;
				}

				var predictedYieldPerHa = baseYieldHa * varietyFactor * soilFactor * densityFactor * seedsFactor;
				var totalPredictedYield = predictedYieldPerHa * areaInHa;

				return {
					totalYieldTons: totalPredictedYield,
					yieldPerHa: predictedYieldPerHa,
					daysToMaturity: daysToMaturity,
					variety: variety
				};
			}

			function getMarketPriceKey(data) {
				// Match machine_learning.php pricing source: prioritize corn type label.
				var variety = String((data && (data.typeOfCorn || data.cornVariety)) || "").toLowerCase();
				if (variety.indexOf("sweet") !== -1) {
					if (variety.indexOf("hybrid") !== -1) return "sweet_hybrid";
					if (variety.indexOf("native") !== -1) return "sweet_native";
					if (variety.indexOf("opv") !== -1) return "sweet_opv";
					return "sweet_hybrid";
				}
				if (variety.indexOf("yellow") !== -1) {
					if (variety.indexOf("hybrid") !== -1) return "yellow_hybrid";
					if (variety.indexOf("feed") !== -1) return "yellow_feeds";
					if (variety.indexOf("native") !== -1) return "yellow_native";
					return "yellow_hybrid";
				}
				if (variety.indexOf("white") !== -1) {
					if (variety.indexOf("native") !== -1) return "white_native";
					return "white_field";
				}
				if (variety.indexOf("glutinous") !== -1 || variety.indexOf("waxy") !== -1) {
					return "glutinous";
				}
				if (variety.indexOf("popcorn") !== -1) {
					return "popcorn";
				}
				if (variety.indexOf("baby") !== -1) {
					return "baby_corn";
				}
				return "other";
			}

			function getMarketPricePerKg(data) {
				var priceKey = getMarketPriceKey(data);
				var priceEntry = marketPricesConfig && marketPricesConfig[priceKey] ? marketPricesConfig[priceKey] : marketPricesConfig.other;
				var price = Number(priceEntry && priceEntry.price_per_kg);
				return Number.isFinite(price) && price > 0 ? price : 20;
			}

			function getEstimatedIncomeFromProfile(data) {
				if (!data) {
					return null;
				}

				var projection = getGrowthProjectionFromProfile(data);
				if (!projection) {
					return null;
				}

				var pricePerKg = getMarketPricePerKg(data);
				var grossIncome = projection.totalYieldTons * 1000 * pricePerKg;

				return Number(grossIncome.toFixed(2));
			}

			function resolveEstimatedIncomeValue() {
				if (typeof syncedMachineLearningIncome === "number" && !Number.isNaN(syncedMachineLearningIncome) && syncedMachineLearningIncome >= 0) {
					return syncedMachineLearningIncome;
				}

				var source = savedPlantingProfile || collectData();
				var income = getEstimatedIncomeFromProfile(source);
				if (income !== null) {
					return income;
				}

				if (initialCostingData && initialCostingData.estimatedIncome !== null && initialCostingData.estimatedIncome !== undefined) {
					var storedIncome = Number(initialCostingData.estimatedIncome);
					if (!Number.isNaN(storedIncome) && storedIncome >= 0) {
						return storedIncome;
					}
				}

				return null;
			}

			function syncFinancialsFromMachineLearning() {
				return fetch("machine_learning.php?action=financial_snapshot", {
					method: "GET",
					headers: {
						"Accept": "application/json"
					}
				})
					.then(function (response) {
						if (!response.ok) {
							throw new Error("Unable to sync financial values.");
						}
						return response.json();
					})
					.then(function (payload) {
						if (!payload || payload.success !== true) {
							throw new Error("Invalid financial snapshot response.");
						}

						var income = Number(payload.estimated_income);
						if (!Number.isNaN(income) && income >= 0) {
							syncedMachineLearningIncome = income;
							updateTotalCost();
						}
					})
					.catch(function () {
						// Keep local calculation fallback if sync is unavailable.
					});
			}

			function updateEstimatedIncomeDisplay() {
				var income = resolveEstimatedIncomeValue();

				if (income === null) {
					estimatedIncomeText.textContent = "N/A";
					return null;
				}

				estimatedIncomeText.textContent = formatMoney(income);
				return income;
			}

			function updateEstimatedProfitDisplay(total, income) {
				estimatedProfitText.classList.remove("negative", "neutral");

				if (income === null) {
					estimatedProfitText.textContent = "N/A";
					estimatedProfitText.classList.add("neutral");
					return;
				}

				var profit = income - total;
				estimatedProfitText.textContent = formatMoney(profit);

				if (profit < 0) {
					estimatedProfitText.classList.add("negative");
				}
			}

			function saveCostingToFile(payload) {
				return fetch("corn_planting_profile.php", {
					method: "POST",
					headers: {
						"Content-Type": "application/json"
					},
					body: JSON.stringify({
						action: "save_costing",
						data: payload
					})
				})
					.then(function (response) {
						return response.json().then(function (result) {
							if (!response.ok || !result.success) {
								throw new Error(result.message || "Failed to save costing data.");
							}
							return result;
						});
					});
			}

			function buildCostingPayload() {
				return {
					rows: savedCostRows,
					totalCost: computeTotalCost(),
					estimatedIncome: resolveEstimatedIncomeValue()
				};
			}

			function persistCostingDataSilently() {
				var payload = buildCostingPayload();
				return saveCostingToFile(payload).then(function () {
					initialCostingData = payload;
				});
			}

			function findVarietyMeta(typeValue, varietyValue) {
				var list = varietyByType[typeValue] || [];
				for (var i = 0; i < list.length; i += 1) {
					if (list[i].name === varietyValue) {
						return list[i];
					}
				}
				return null;
			}

			function resolvedTypeOfCorn() {
				if (typeOfCorn.value === "Other (Specify)") {
					return typeOfCornOther.value.trim();
				}
				return typeOfCorn.value;
			}

			function resolvedCornVariety() {
				if (cornVariety.value === "Other (Specify)") {
					return cornVarietyOther.value.trim();
				}
				return cornVariety.value;
			}

			function collectData() {
				var areaUnit = getAreaUnit();
				var areaPlantedValue = document.getElementById("areaPlanted").value;

				if (areaUnit === "square-meters") {
					var length = parseFloat(areaLength.value || "0") || 0;
					var width = parseFloat(areaWidth.value || "0") || 0;
					var sqmTotal = length * width;
					areaPlantedValue = sqmTotal > 0 ? String(sqmTotal) : "";
				}

				return {
					plantingDate: document.getElementById("plantingDate").value,
					farmLocation: document.getElementById("farmLocation").value,
					typeOfCorn: resolvedTypeOfCorn(),
					cornVariety: resolvedCornVariety(),
					numberOfPacks: document.getElementById("numberOfPacks").value,
					kgOfPacks: document.getElementById("kgOfPacks").value,
					areaUnit: areaUnit,
					areaPlanted: areaPlantedValue,
					areaLength: areaLength.value,
					areaWidth: areaWidth.value,
					plantingDensity: document.getElementById("plantingDensity").value.trim(),
					seedsPerHole: document.getElementById("seedsPerHole").value,
					soilType: document.getElementById("soilType").value,
					estimatedSeeds: estimatedSeeds.value.trim()
				};
			}

			function validateData(data) {
				var required = [
					data.plantingDate,
					data.farmLocation,
					data.numberOfPacks,
					data.kgOfPacks,
					data.plantingDensity,
					data.seedsPerHole,
					data.soilType,
					data.estimatedSeeds
				];

				for (var i = 0; i < required.length; i += 1) {
					if (String(required[i]).trim() === "") {
						return false;
					}
				}

				if (data.areaUnit === "hectares") {
					return String(data.areaPlanted).trim() !== "";
				}

				return String(data.areaLength).trim() !== "" && String(data.areaWidth).trim() !== "";
			}

			function toggleOtherSpecifyFields() {
				var isTypeOther = typeOfCorn.value === "Other (Specify)";
				typeOfCornOther.classList.toggle("d-none", !isTypeOther);

				var isVarietyOther = cornVariety.value === "Other (Specify)";
				cornVarietyOther.classList.toggle("d-none", !isVarietyOther);
			}

			function updateCornVarietySuggestions() {
				var selectedType = typeOfCorn.value;
				var options = varietyByType[typeOfCorn.value] || [];
				var html = '<option value="">--Select Variety--</option>';
				for (var i = 0; i < options.length; i += 1) {
					html += '<option value="' + options[i].name + '">' + options[i].name + '</option>';
				}
				html += '<option value="Other (Specify)">Other (Specify)</option>';
				cornVariety.innerHTML = html;

				if (selectedType === "") {
					cornVariety.value = "";
				}

				toggleOtherSpecifyFields();
			}

			function savePlantingProfileToFile(data) {
				return fetch("corn_planting_profile.php", {
					method: "POST",
					headers: {
						"Content-Type": "application/json"
					},
					body: JSON.stringify({
						action: "save_profile",
						data: data
					})
				})
					.then(function (response) {
						return response.json().then(function (payload) {
							if (!response.ok || !payload.success) {
								throw new Error(payload.message || "Failed to save planting profile.");
							}
							return payload;
						});
					});
			}

			function hasOption(selectElement, value) {
				if (!value) {
					return false;
				}
				for (var i = 0; i < selectElement.options.length; i += 1) {
					if (selectElement.options[i].value === value) {
						return true;
					}
				}
				return false;
			}

			function fillFormFromData(data) {
				document.getElementById("plantingDate").value = data.plantingDate || "";
				document.getElementById("farmLocation").value = data.farmLocation || "";
				document.getElementById("numberOfPacks").value = data.numberOfPacks || "";
				document.getElementById("kgOfPacks").value = data.kgOfPacks || "";
				document.getElementById("areaPlanted").value = data.areaPlanted || "";
				areaLength.value = data.areaLength || "";
				areaWidth.value = data.areaWidth || "";
				document.getElementById("plantingDensity").value = data.plantingDensity || "";
				document.getElementById("seedsPerHole").value = data.seedsPerHole || "";
				document.getElementById("soilType").value = data.soilType || "";
				estimatedSeeds.value = data.estimatedSeeds || "";

				if (data.areaUnit === "square-meters") {
					areaSquareMeters.checked = true;
				} else {
					areaHectares.checked = true;
				}
				toggleAreaInput();
				computeAreaTotal();

				if (!data.typeOfCorn) {
					typeOfCorn.value = "";
					typeOfCornOther.value = "";
				} else if (hasOption(typeOfCorn, data.typeOfCorn)) {
					typeOfCorn.value = data.typeOfCorn;
					typeOfCornOther.value = "";
				} else {
					typeOfCorn.value = "Other (Specify)";
					typeOfCornOther.value = data.typeOfCorn || "";
				}

				updateCornVarietySuggestions();

				if (!data.cornVariety) {
					cornVariety.value = "";
					cornVarietyOther.value = "";
				} else if (hasOption(cornVariety, data.cornVariety)) {
					cornVariety.value = data.cornVariety;
					cornVarietyOther.value = "";
				} else {
					cornVariety.value = "Other (Specify)";
					cornVarietyOther.value = data.cornVariety || "";
				}

				toggleOtherSpecifyFields();
				updateEstimatedSeedsField();
			}

			function unlockCostingFromData(data) {
				costingUnlocked = true;
				tabCosting.classList.remove("disabled");
				tabCosting.textContent = "Costing";

				seedCost.value = (getAreaHectares(data) * 500).toString();
				updateTotalCost();
				updateEstimatedIncomeDisplay();
			}

			function makeCertPair(label, value) {
				return '<div class="cert-item"><p class="cert-k">' + label + '</p><p class="cert-v">' + value + "</p></div>";
			}

			function formatEstimatedSeeds(value) {
				var raw = String(value || "").trim();
				if (raw === "") {
					return "Not specified";
				}

				if (raw.indexOf("-") >= 0) {
					return raw + " seeds";
				}

				var numeric = Number(raw.replace(/,/g, ""));
				if (!Number.isNaN(numeric) && numeric > 0) {
					return Math.round(numeric).toLocaleString("en-US") + " seeds";
				}

				return raw;
			}

			function formatPacks(value) {
				var n = Number(value);
				if (Number.isNaN(n)) {
					return String(value || "") + " packs";
				}
				return n.toLocaleString("en-US") + (n === 1 ? " pack" : " packs");
			}

			function formatKg(value) {
				var n = Number(value);
				if (Number.isNaN(n)) {
					return String(value || "") + " kg";
				}
				return n.toLocaleString("en-US", { maximumFractionDigits: 2 }) + " kg";
			}

			function formatHarvestDate(data) {
				var planting = String(data.plantingDate || "").trim();
				var harvestDays = Number(data.daysToHarvestMax || data.daysToHarvestMin || 0);

				if (planting === "" || harvestDays <= 0) {
					return "Not available";
				}

				var plantingDate = new Date(planting + "T00:00:00");
				if (Number.isNaN(plantingDate.getTime())) {
					return "Not available";
				}

				plantingDate.setDate(plantingDate.getDate() + Math.max(harvestDays - 1, 0));
				return formatDate(plantingDate.toISOString().slice(0, 10));
			}

			function buildCertificate(data) {
				var html = "";
				html += '<div class="two-col">';
				html += makeCertPair("Planting Date", formatDate(data.plantingDate));
				html += makeCertPair("Estimated Harvest Date", formatHarvestDate(data));
				html += "</div>";

				html += '<div class="two-col">';
				html += makeCertPair("Farm Location", data.farmLocation);
				html += makeCertPair("Total Area Planted", getAreaText(data));
				html += "</div>";

				html += '<div class="two-col">';
				html += makeCertPair("Type of Corn", typeLabel(data.typeOfCorn) || "Not specified");
				html += makeCertPair("Corn Variety", data.cornVariety || "Not specified");
				html += "</div>";

				html += '<div class="two-col">';
				html += makeCertPair("Number of Packs", formatPacks(data.numberOfPacks));
				html += makeCertPair("Weight of Packs", formatKg(data.kgOfPacks));
				html += "</div>";

				html += '<div class="two-col">';
				html += makeCertPair("Planting Density / Spacing", data.plantingDensity);
				html += makeCertPair("Seeds per Hill/Hole", data.seedsPerHole + " seeds");
				html += "</div>";

				html += '<div class="two-col">';
				html += makeCertPair("Soil Type", soilLabel(data.soilType));
				html += makeCertPair("Estimated Corn Seeds", formatEstimatedSeeds(data.estimatedSeeds));
				html += "</div>";

				certStack.innerHTML = html;
				certGeneratedOn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19 4h-1V2h-2v2H8V2H6v2H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2m0 15H5V9h14z"/></svg>' +
					'<span>Profile Generated on ' + formatDate(new Date().toISOString().slice(0, 10)) + '</span>';
			}

			function openTab(tab) {
				var planting = tab === "planting";
				tabPlanting.classList.toggle("active", planting);
				tabCosting.classList.toggle("active", !planting);
				contentPlanting.classList.toggle("active", planting);
				contentCosting.classList.toggle("active", !planting);
			}

			function getRequestedTab() {
				var params = new URLSearchParams(window.location.search);
				return params.get("tab") || "";
			}

			function setBaseCostInputsVisible(visible) {
				seedCostBox.classList.toggle("d-none", !visible);
				laborCostBox.classList.toggle("d-none", !visible);
			}

			function openConfirm(message, onYes) {
				confirmMessage.textContent = message;
				confirmOnYes = onYes;
				confirmMask.classList.add("show");
				confirmBox.classList.add("show");
			}

			function closeConfirm() {
				confirmMask.classList.remove("show");
				confirmBox.classList.remove("show");
				confirmOnYes = null;
			}

			function showProfileToast(message, isError) {
				if (!profileToast || !message) {
					return;
				}

				profileToast.textContent = String(message);
				profileToast.classList.toggle("is-error", !!isError);
				profileToast.classList.remove("show");
				void profileToast.offsetWidth;
				profileToast.classList.add("show");

				if (profileToastTimer) {
					window.clearTimeout(profileToastTimer);
				}
				profileToastTimer = window.setTimeout(function () {
					profileToast.classList.remove("show");
				}, 2400);
			}

			function getPreparedAdditionalCosts() {
				var prepared = [];

				for (var i = 0; i < additionalCosts.length; i += 1) {
					var item = additionalCosts[i];
					var name = String(item.name || "").trim();
					var costRaw = String(item.cost || "").trim();

					if (name === "" && costRaw === "") {
						continue;
					}

					if (name === "" || costRaw === "") {
						throw new Error("Please complete all additional cost rows before saving.");
					}

					var costValue = parseFloat(costRaw);
					if (Number.isNaN(costValue) || costValue < 0) {
						throw new Error("Additional cost must be a valid amount.");
					}

					prepared.push({
						id: item.id,
						name: name,
						cost: costValue
					});
				}

				return prepared;
			}

			function buildSavedCostRows() {
				var rows = [];
				var seedValue = parseFloat(seedCost.value || "0") || 0;
				var laborValue = parseFloat(laborCost.value || "0") || 0;
				var extras = getPreparedAdditionalCosts();

				rows.push({ id: "seed", source: "seed", type: "Seeds", cost: seedValue });
				rows.push({ id: "labor", source: "labor", type: "Labor", cost: laborValue });

				for (var i = 0; i < extras.length; i += 1) {
					rows.push({
						id: "extra-" + extras[i].id,
						source: "additional",
						type: extras[i].name,
						cost: extras[i].cost,
						extraId: extras[i].id
					});
				}

				return rows;
			}

			function renderSavedCostsTable() {
				if (savedCostRows.length === 0) {
					savedCostsTableBody.innerHTML = '<tr><td colspan="3" class="text-center text-secondary">No saved costs yet.</td></tr>';
					return;
				}

				var html = "";
				for (var i = 0; i < savedCostRows.length; i += 1) {
					var row = savedCostRows[i];
					html += "<tr>";
					html += "<td>" + row.type + "</td>";
					html += "<td>" + formatMoney(row.cost) + "</td>";
					html += '<td><button class="btn btn-sm btn-outline-danger delete-saved-cost" type="button" data-id="' + row.id + '" data-source="' + row.source + '" data-extra-id="' + (row.extraId || "") + '" data-type-encoded="' + encodeURIComponent(row.type) + '">Delete</button></td>';
					html += "</tr>";
				}

				savedCostsTableBody.innerHTML = html;

				var deleteButtons = savedCostsTableBody.querySelectorAll(".delete-saved-cost");
				for (var d = 0; d < deleteButtons.length; d += 1) {
					(function () {
						var btn = deleteButtons[d];
						var rowId = btn.getAttribute("data-id");
						var source = btn.getAttribute("data-source");
						var extraId = btn.getAttribute("data-extra-id");
						var rowType = decodeURIComponent(btn.getAttribute("data-type-encoded") || "Expense");

						btn.addEventListener("click", function () {
							openConfirm('Are you sure you want to delete "' + rowType + '" expense? This will also remove it from your saved costing records.', function () {
								if (source === "seed") {
									seedCost.value = "0";
									seedCostBox.classList.remove("d-none");
								} else if (source === "labor") {
									laborCost.value = "0";
									laborCostBox.classList.remove("d-none");
								} else if (source === "additional" && extraId) {
									additionalCosts = additionalCosts.filter(function (item) {
										return item.id !== extraId;
									});
									renderCosts();
								}

								savedCostRows = savedCostRows.filter(function (row) {
									return row.id !== rowId;
								});

								renderSavedCostsTable();
								updateTotalCost();
								showProfileToast("Cost deleted successfully.", false);

								persistCostingDataSilently().catch(function (error) {
									showProfileToast(error.message || "Deleted in UI but failed to update saved costing records.", true);
								});
							});
						});
					})();
				}
			}

			function applyInitialCostingData() {
				if (!initialCostingData || !Array.isArray(initialCostingData.rows)) {
					return;
				}

				savedCostRows = [];

				for (var i = 0; i < initialCostingData.rows.length; i += 1) {
					var row = initialCostingData.rows[i];
					if (!row || typeof row !== "object") {
						continue;
					}

					var normalized = {
						id: String(row.id || ""),
						source: String(row.source || "additional"),
						type: String(row.type || "").trim(),
						cost: parseFloat(row.cost || "0") || 0,
						extraId: String(row.extraId || "")
					};

					if (normalized.type === "") {
						continue;
					}

					savedCostRows.push(normalized);
				}

				for (var r = 0; r < savedCostRows.length; r += 1) {
					if (savedCostRows[r].id === "seed") {
						seedCost.value = String(savedCostRows[r].cost || 0);
					}
					if (savedCostRows[r].id === "labor") {
						laborCost.value = String(savedCostRows[r].cost || 0);
					}
				}

				renderSavedCostsTable();
				setBaseCostInputsVisible(savedCostRows.length === 0);
				updateTotalCost();
			}

			function renderCosts() {
				if (additionalCosts.length === 0) {
					additionalCostsWrap.innerHTML = "";
					emptyCostText.classList.remove("d-none");
					updateTotalCost();
					return;
				}

				emptyCostText.classList.add("d-none");

				var html = "";
				for (var i = 0; i < additionalCosts.length; i += 1) {
					var item = additionalCosts[i];
					var isFixedExpense = !!item.fixedExpense;
					html += '<div class="add-cost-row" data-id="' + item.id + '">';
					html += '<div class="col-flex">';
					html += '<label class="label-strong">Expense Name</label>';
					html += '<input class="input-clean extra-name" type="text" value="' + (item.name || "") + '"' + (isFixedExpense ? ' readonly' : '') + '>';
					html += "</div>";
					html += '<div class="col-flex">';
					html += '<label class="label-strong">Cost</label>';
					html += '<input class="input-clean extra-cost" type="number" min="0" step="0.01" value="' + (item.cost || "") + '">';
					html += "</div>";
					if (!isFixedExpense) {
						html += '<button class="btn-icon-danger remove-extra" type="button" aria-label="Remove">';
						html += '<svg viewBox="0 0 24 24"><path d="M6 7h12v2H6V7zm2 3h8l-1 10H9L8 10zm2-5h4l1 1h4v2H5V6h4l1-1z"></path></svg>';
						html += "</button>";
					}
					html += "</div>";
				}

				additionalCostsWrap.innerHTML = html;

				var rows = additionalCostsWrap.querySelectorAll(".add-cost-row");
				for (var r = 0; r < rows.length; r += 1) {
					(function () {
						var row = rows[r];
						var id = row.getAttribute("data-id");
						var nameInput = row.querySelector(".extra-name");
						var costInput = row.querySelector(".extra-cost");
						var removeBtn = row.querySelector(".remove-extra");
						var isFixedExpense = !!(nameInput && nameInput.readOnly);

						if (!isFixedExpense) {
							nameInput.addEventListener("input", function () {
								for (var k = 0; k < additionalCosts.length; k += 1) {
									if (additionalCosts[k].id === id) {
										additionalCosts[k].name = nameInput.value;
										break;
									}
								}
							});
						}

						costInput.addEventListener("input", function () {
							for (var k = 0; k < additionalCosts.length; k += 1) {
								if (additionalCosts[k].id === id) {
									additionalCosts[k].cost = costInput.value;
									break;
								}
							}
							updateTotalCost();
						});

						if (removeBtn) {
							removeBtn.addEventListener("click", function () {
								openConfirm("Are you sure you want to delete this additional cost row?", function () {
									additionalCosts = additionalCosts.filter(function (x) {
										return x.id !== id;
									});
									renderCosts();
									showProfileToast("Cost row deleted.", false);
								});
							});
						}
					})();
				}

				updateTotalCost();
			}

			function insertPendingExpenseRow() {
				if (!pendingCostingHandoff || !pendingCostingHandoff.expenseType) {
					return;
				}

				var expenseType = String(pendingCostingHandoff.expenseType || '').trim();
				var expenseLabel = String(pendingCostingHandoff.expenseLabel || pendingCostingHandoff.taskTitle || expenseType || '').trim();
				if (expenseType === '') {
					return;
				}

				var exists = false;
				for (var i = 0; i < additionalCosts.length; i += 1) {
					var existingName = String(additionalCosts[i].name || '').trim().toLowerCase();
					if (existingName === expenseType.toLowerCase() || existingName === expenseLabel.toLowerCase()) {
						exists = true;
						break;
					}
				}

				if (!exists) {
					additionalCosts.unshift({
						id: 'handoff-' + String(Date.now()) + '-' + String(Math.random()).slice(2),
						name: expenseLabel || expenseType,
						cost: '',
						fixedExpense: true
					});
					renderCosts();
				}

				openTab('costing');

				window.setTimeout(function () {
					var firstRow = additionalCostsWrap.querySelector('.add-cost-row');
					if (!firstRow) return;
					var costInput = firstRow.querySelector('.extra-cost');
					if (costInput) {
						costInput.focus();
					}
				}, 0);

				try {
					localStorage.removeItem('agricorn_costing_handoff');
				} catch (error) {
					// ignore
				}
				pendingCostingHandoff = null;
			}

			function computeTotalCost() {
				var total = 0;

				if (savedCostRows.length > 0) {
					for (var s = 0; s < savedCostRows.length; s += 1) {
						total += parseFloat(savedCostRows[s].cost || "0") || 0;
					}

					for (var j = 0; j < additionalCosts.length; j += 1) {
						if (String(additionalCosts[j].name || "").trim() !== "") {
							total += parseFloat(additionalCosts[j].cost || "0") || 0;
						}
					}
				} else {
					var base = parseFloat(seedCost.value || "0") || 0;
					var labor = parseFloat(laborCost.value || "0") || 0;
					total = base + labor;
					for (var i = 0; i < additionalCosts.length; i += 1) {
						if (String(additionalCosts[i].name || "").trim() !== "") {
							total += parseFloat(additionalCosts[i].cost || "0") || 0;
						}
					}
				}

				return total;
			}

			function updateTotalCost() {
				var total = computeTotalCost();
				totalCostText.textContent = formatMoney(total);
				var income = updateEstimatedIncomeDisplay();
				updateEstimatedProfitDisplay(total, income);
			}

			tabPlanting.addEventListener("click", function () {
				openTab("planting");
			});

			tabCosting.addEventListener("click", function () {
				if (!costingUnlocked) {
					return;
				}
				openTab("costing");
			});

			areaHectares.addEventListener("change", toggleAreaInput);
			areaSquareMeters.addEventListener("change", toggleAreaInput);
			areaLength.addEventListener("input", computeAreaTotal);
			areaWidth.addEventListener("input", computeAreaTotal);
			document.getElementById("numberOfPacks").addEventListener("input", updateEstimatedSeedsField);
			document.getElementById("kgOfPacks").addEventListener("input", updateEstimatedSeedsField);

			confirmNo.addEventListener("click", closeConfirm);
			confirmMask.addEventListener("click", closeConfirm);
			confirmYes.addEventListener("click", function () {
				if (typeof confirmOnYes === "function") {
					confirmOnYes();
				}
				closeConfirm();
			});

			typeOfCorn.addEventListener("change", function () {
				if (typeOfCorn.value !== "Other (Specify)") {
					typeOfCornOther.value = "";
				}
				cornVarietyOther.value = "";
				updateCornVarietySuggestions();
				updateEstimatedSeedsField();
			});

			cornVariety.addEventListener("change", function () {
				if (cornVariety.value !== "Other (Specify)") {
					cornVarietyOther.value = "";
				}
				toggleOtherSpecifyFields();
				updateEstimatedSeedsField();
			});

			savePlantingBtn.addEventListener("click", function () {
				var data = collectData();
				if (!validateData(data)) {
					alert("Please fill in all fields before saving.");
					return;
				}

				var selectedType = typeOfCorn.value === "Other (Specify)" ? data.typeOfCorn : typeOfCorn.value;
				var selectedVariety = cornVariety.value === "Other (Specify)" ? data.cornVariety : cornVariety.value;
				var varietyMeta = findVarietyMeta(selectedType, selectedVariety);
				data.daysToHarvestMin = varietyMeta ? varietyMeta.min : null;
				data.daysToHarvestMax = varietyMeta ? varietyMeta.max : null;
				data.daysToHarvestLabel = varietyMeta ? varietyMeta.label : "";

				openConfirm("Are you sure the data you entered is correct? Once saved, this planting data can no longer be edited.", function () {
					savePlantingBtn.disabled = true;

					savePlantingProfileToFile(data)
						.then(function () {
							savedPlantingProfile = data;
							buildCertificate(data);
							plantingFormView.classList.add("d-none");
							plantingCertificateView.classList.remove("d-none");
							unlockCostingFromData(data);
							showProfileToast("Data Saved! Successfully recorded", false);
						})
						.catch(function (error) {
							alert(error.message || "Unable to save planting profile.");
						})
						.finally(function () {
							savePlantingBtn.disabled = false;
						});
				});
			});

			addCostBtn.addEventListener("click", function () {
				additionalCosts.push({
					id: String(Date.now()) + String(Math.random()),
					name: "",
					cost: ""
				});
				renderCosts();
			});

			saveCostsBtn.addEventListener("click", function () {
				openConfirm("Are you sure you want to save these cost entries?", function () {
					try {
						var rowsToSave = buildSavedCostRows();

						for (var i = 0; i < rowsToSave.length; i += 1) {
							var row = rowsToSave[i];

							if (row.source === "seed" || row.source === "labor") {
								var existingIndex = -1;
								for (var k = 0; k < savedCostRows.length; k += 1) {
									if (savedCostRows[k].id === row.id) {
										existingIndex = k;
										break;
									}
								}

								if (existingIndex >= 0) {
									savedCostRows[existingIndex] = row;
								} else {
									savedCostRows.push(row);
								}
							} else {
								savedCostRows.push(row);
							}
						}

						additionalCosts = [];
						renderCosts();
						renderSavedCostsTable();
						setBaseCostInputsVisible(false);
						updateTotalCost();

						var payload = buildCostingPayload();

						saveCostsBtn.disabled = true;
						saveCostingToFile(payload)
							.then(function () {
								initialCostingData = payload;
								showProfileToast("Costs saved successfully.", false);
							})
							.catch(function (error) {
								alert(error.message || "Unable to save costs to database.");
							})
							.finally(function () {
								saveCostsBtn.disabled = false;
							});
					} catch (error) {
						alert(error.message || "Unable to save costs.");
					}
				});
			});

			seedCost.addEventListener("input", updateTotalCost);
			laborCost.addEventListener("input", updateTotalCost);

			document.getElementById("backBtn").addEventListener("click", function () {
				var params = new URLSearchParams(window.location.search);
				var dashboardUrl = params.get("from") === "features"
					? "farmer_dashboard.php?view=features"
					: "farmer_dashboard.php";
				window.location.href = dashboardUrl;
			});

			toggleAreaInput();
			computeAreaTotal();
			updateCornVarietySuggestions();
			toggleOtherSpecifyFields();
			updateEstimatedSeedsField();
			renderCosts();

			if (savedPlantingProfile && validateData(savedPlantingProfile)) {
				fillFormFromData(savedPlantingProfile);
				buildCertificate(savedPlantingProfile);
				plantingFormView.classList.add("d-none");
				plantingCertificateView.classList.remove("d-none");
				unlockCostingFromData(savedPlantingProfile);
			}

			applyInitialCostingData();
			renderSavedCostsTable();
			updateEstimatedIncomeDisplay();
			syncFinancialsFromMachineLearning();

			if (pendingCostingHandoff && pendingCostingHandoff.expenseType) {
				openTab("costing");
				insertPendingExpenseRow();
			} else if (getRequestedTab() === "costing" && costingUnlocked) {
				openTab("costing");
			} else {
				openTab("planting");
			}
		})();
	</script>
</body>
</html>