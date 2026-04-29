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

require_once __DIR__ . "/../data/db_connect.php";

function send_json_response(array $payload, int $statusCode = 200): void
{
	http_response_code($statusCode);
	header("Content-Type: application/json; charset=utf-8");
	echo json_encode($payload);
	exit;
}

function normalize_guide_mode(array $row): string
{
	if (!empty($row["external_link"])) return "external";
	if (!empty($row["guide_file"])) return "file";
	return "content";
}

function get_guide_modules(mysqli $conn): array
{
	// Migration Check: Add guide_file column if not exists
	$check_col = $conn->query("SHOW COLUMNS FROM `guide_module` LIKE 'guide_file'");
	if ($check_col && $check_col->num_rows === 0) {
		$conn->query("ALTER TABLE `guide_module` ADD COLUMN `guide_file` VARCHAR(255) DEFAULT NULL;");
	}

	// Ensure guides directory exists
	if (!is_dir(__DIR__ . "/../data/guides")) {
		mkdir(__DIR__ . "/../data/guides", 0777, true);
	}

	$sql = "SELECT guide_id, module_title, category, short_description, guide_content, external_link, guide_file, DATE_FORMAT(date_created, '%Y-%m-%d') AS createdDate, DATE_FORMAT(last_updated, '%Y-%m-%d') AS updatedDate FROM guide_module ORDER BY guide_id DESC";
	$result = $conn->query($sql);
	$modules = [];

	while ($row = $result->fetch_assoc()) {
		$modules[] = [
			"id" => (string) $row["guide_id"],
			"contentMode" => normalize_guide_mode($row),
			"title" => (string) ($row["module_title"] ?? ""),
			"category" => (string) ($row["category"] ?? "General"),
			"description" => (string) ($row["short_description"] ?? ""),
			"content" => (string) ($row["guide_content"] ?? ""),
			"link" => (string) ($row["external_link"] ?? ""),
			"filePath" => (string) ($row["guide_file"] ?? ""),
			"createdDate" => (string) ($row["createdDate"] ?? ""),
			"updatedDate" => (string) ($row["updatedDate"] ?? "")
		];
	}

	return $modules;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
	$rawInput = file_get_contents("php://input");
	$input = json_decode($rawInput, true);
	if (!is_array($input)) {
		$input = $_POST;
	}

	$action = strtolower(trim((string) ($input["action"] ?? "")));

	if ($action !== "") {
		try {
			if ($action === "add" || $action === "update") {
				$id = (int) ($input["id"] ?? 0);
				$title = trim((string) ($input["title"] ?? ""));
				$category = trim((string) ($input["category"] ?? ""));
				$description = trim((string) ($input["description"] ?? ""));
				$contentMode = trim((string) ($input["contentMode"] ?? ""));
				$content = trim((string) ($input["content"] ?? ""));
				$link = trim((string) ($input["link"] ?? ""));

				if ($title === "" || $category === "" || $description === "") {
					send_json_response(["success" => false, "message" => "Please complete all required fields."], 422);
				}

				if (!in_array($contentMode, ["content", "external", "file"])) {
					send_json_response(["success" => false, "message" => "Please choose a valid content source."], 422);
				}

				$contentValue = null;
				$linkValue = null;
				$fileValue = null;

				if ($contentMode === "content") {
					if ($content === "") {
						send_json_response(["success" => false, "message" => "Guide Content is required for this source type."], 422);
					}
					$contentValue = $content;
				} elseif ($contentMode === "external") {
					if ($link === "") {
						send_json_response(["success" => false, "message" => "External Link is required for this source type."], 422);
					}
					if (!filter_var($link, FILTER_VALIDATE_URL)) {
						send_json_response(["success" => false, "message" => "Please provide a valid URL."], 422);
					}
					$linkValue = $link;
				} else {
					// Handle File Upload
					$existingFile = null;
					if ($action === "update") {
						$res = $conn->query("SELECT guide_file FROM guide_module WHERE guide_id = $id");
						if ($row = $res->fetch_assoc()) $existingFile = $row["guide_file"];
					}

					if (isset($_FILES["guideFile"]) && $_FILES["guideFile"]["error"] === UPLOAD_ERR_OK) {
						$ext = pathinfo($_FILES["guideFile"]["name"], PATHINFO_EXTENSION);
						$fileName = uniqid("guide_", true) . "." . $ext;
						$uploadDir = __DIR__ . "/../data/guides/";
						if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
						
						if (move_uploaded_file($_FILES["guideFile"]["tmp_name"], $uploadDir . $fileName)) {
							$fileValue = "../data/guides/" . $fileName;
							// Delete old file if exists
							if ($existingFile && file_exists(__DIR__ . "/" . $existingFile)) {
								@unlink(__DIR__ . "/" . $existingFile);
							}
						} else {
							send_json_response(["success" => false, "message" => "Failed to save the uploaded file."], 500);
						}
					} else {
						if ($action === "add") {
							send_json_response(["success" => false, "message" => "Please upload a file for this source type."], 422);
						}
						$fileValue = $existingFile;
					}
				}

				if ($action === "add") {
					$stmt = $conn->prepare("INSERT INTO guide_module (module_title, category, short_description, guide_content, external_link, guide_file) VALUES (?, ?, ?, ?, ?, ?)");
					$stmt->bind_param("ssssss", $title, $category, $description, $contentValue, $linkValue, $fileValue);
					$stmt->execute();

					send_json_response([
						"success" => true,
						"message" => "Guide module added successfully.",
						"modules" => get_guide_modules($conn)
					]);
				}

				if ($id <= 0) {
					send_json_response(["success" => false, "message" => "Invalid guide module ID."], 422);
				}

				$stmt = $conn->prepare("UPDATE guide_module SET module_title = ?, category = ?, short_description = ?, guide_content = ?, external_link = ?, guide_file = ?, last_updated = NOW() WHERE guide_id = ?");
				$stmt->bind_param("ssssssi", $title, $category, $description, $contentValue, $linkValue, $fileValue, $id);
				$stmt->execute();

				send_json_response([
					"success" => true,
					"message" => "Guide module updated successfully.",
					"modules" => get_guide_modules($conn)
				]);
			}

			if ($action === "delete") {
				$id = (int) ($input["id"] ?? 0);
				if ($id <= 0) {
					send_json_response(["success" => false, "message" => "Invalid guide module ID."], 422);
				}

				// Delete file if exists
				$res = $conn->query("SELECT guide_file FROM guide_module WHERE guide_id = $id");
				if ($row = $res->fetch_assoc() && !empty($row["guide_file"])) {
					@unlink(__DIR__ . "/" . $row["guide_file"]);
				}

				$stmt = $conn->prepare("DELETE FROM guide_module WHERE guide_id = ?");
				$stmt->bind_param("i", $id);
				$stmt->execute();

				send_json_response([
					"success" => true,
					"message" => "Guide module deleted successfully.",
					"modules" => get_guide_modules($conn)
				]);
			}

			if ($action === "list") {
				send_json_response([
					"success" => true,
					"modules" => get_guide_modules($conn)
				]);
			}

			send_json_response(["success" => false, "message" => "Unsupported request action."], 400);
		} catch (Throwable $error) {
			send_json_response(["success" => false, "message" => "Unable to process your request right now."], 500);
		}
	}
}

$guideModules = get_guide_modules($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Guide Module Management | AgriCorn Admin</title>
	<link rel="stylesheet" href="../bootstrap5/css/bootstrap.min.css">
	<style>
		@import url('https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=Manrope:wght@400;500;600;700&display=swap');
		:root {
			--background: #fafdf7;
			--foreground: #2c3e2e;
			--primary: #7fb685;
			--primary-foreground: #ffffff;
			--secondary: #ffe599;
			--muted-foreground: #6b7c6e;
			--accent: #f3f8ef;
			--border: rgba(127, 182, 133, 0.28);
			--shadow-lg: 0 12px 28px rgba(34, 58, 39, 0.14);
			--card-glow: 0 12px 26px rgba(34, 58, 39, 0.12);
		}

		* {
			box-sizing: border-box;
		}

		html,
		body {
			margin: 0;
			height: 100%;
			font-family: "Manrope", "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
			color: var(--foreground);
			background: radial-gradient(circle at top, rgba(127, 182, 133, 0.12), transparent 60%),
				linear-gradient(135deg, rgba(127, 182, 133, 0.06), rgba(250, 253, 247, 1), rgba(255, 229, 153, 0.12));
		}

		.app {
			display: flex;
			height: 100dvh;
			overflow: hidden;
			background: linear-gradient(135deg, rgba(127, 182, 133, 0.06), rgba(250, 253, 247, 1), rgba(255, 229, 153, 0.1));
		}

		.sidebar {
			width: 80px;
			background: linear-gradient(180deg, rgba(127, 182, 133, 0.4), rgba(255, 229, 153, 0.4));
			border-right: 1px solid rgba(127, 182, 133, 0.35);
			transition: width 0.3s ease;
			display: flex;
			flex-direction: column;
			flex-shrink: 0;
			position: relative;
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
			justify-content: flex-start;
			gap: 12px;
			padding: 0 12px;
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

		.page-stack {
			display: grid;
			gap: 24px;
		}

		.title-row {
			display: flex;
			align-items: flex-start;

		.learn-btn.external {
			background: rgba(59, 130, 246, 0.14);
			border-color: rgba(59, 130, 246, 0.34);
			color: #1d4ed8;
		}

		.learn-btn.file {
			background: rgba(139, 92, 246, 0.14);
			border-color: rgba(139, 92, 246, 0.34);
			color: #7c3aed;
		}
			justify-content: space-between;
			gap: 12px;
			flex-wrap: wrap;
		}


		.learn-btn.external:hover {
			background: rgba(59, 130, 246, 0.22);
			border-color: rgba(59, 130, 246, 0.5);
		}

		.learn-btn.file:hover {
			background: rgba(139, 92, 246, 0.22);
			border-color: rgba(139, 92, 246, 0.5);
		}
		.title-row h2 {
			margin: 0;
			font-size: 2rem;
			font-weight: 700;
			color: #2c3e2e;
			font-family: "Fraunces", "Manrope", serif;
		}

		.title-row p {
			margin: 6px 0 0;
			color: var(--muted-foreground);
			font-size: 0.95rem;
		}

		.btn-add {
			border: 0;
			border-radius: 10px;
			background: var(--primary);
			color: #fff;
			height: 42px;
			padding: 0 16px;
			font-size: 0.9rem;
			font-weight: 600;
			display: inline-flex;
			align-items: center;
			gap: 8px;
			box-shadow: 0 8px 20px rgba(127, 182, 133, 0.35);
			transition: transform 0.2s ease, box-shadow 0.2s ease;
		}

		.btn-add:hover {
			transform: translateY(-1px);
			box-shadow: 0 12px 24px rgba(127, 182, 133, 0.4);
		}

		.btn-add svg {
			width: 15px;
			height: 15px;
			stroke: currentColor;
			fill: none;
			stroke-width: 2;
			stroke-linecap: round;
			stroke-linejoin: round;
		}

		/* Override Bootstrap success to match theme */
		.btn-success {
			background: var(--primary) !important;
			border-color: var(--primary) !important;
			color: var(--primary-foreground) !important;
		}

		.btn-success:hover,
		.btn-success:focus {
			background: #6aa96f !important;
			border-color: #6aa96f !important;
			color: var(--primary-foreground) !important;
		}

		.btn-outline-secondary {
			border-color: rgba(127, 182, 133, 0.28) !important;
			color: #2c3e2e !important;
			background: transparent !important;
		}

		.filters {
			background: #fff;
			border-radius: 14px;
			box-shadow: var(--shadow-lg);
			border: 0;
			padding: 18px;
			display: grid;
			grid-template-columns: minmax(0, 1fr) 220px;
			gap: 12px;
		}

		.module-toolbar {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 12px;
			padding: 0 4px;
		}

		.module-toolbar h3 {
			margin: 0;
			font-size: 1.15rem;
			font-weight: 700;
			color: #2c3e2e;
			font-family: "Fraunces", "Manrope", serif;
		}

		.module-toolbar p {
			margin: 2px 0 0;
			font-size: 0.85rem;
			color: var(--muted-foreground);
		}

		.module-tip {
			font-size: 0.82rem;
			color: #4f6852;
			background: rgba(127, 182, 133, 0.1);
			border: 1px solid rgba(127, 182, 133, 0.28);
			padding: 6px 10px;
			border-radius: 999px;
			white-space: nowrap;
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
			stroke: #889a8b;
			fill: none;
			stroke-width: 2;
			stroke-linecap: round;
			stroke-linejoin: round;
		}

		.search-input,
		.category-select,
		.form-input,
		.form-select,
		.form-textarea {
			width: 100%;
			border: 1px solid rgba(127, 182, 133, 0.3);
			border-radius: 10px;
			outline: none;
			font-size: 0.92rem;
			transition: border-color 0.2s ease, box-shadow 0.2s ease;
			background: #fff;
		}

		.search-input {
			height: 46px;
			padding: 0 14px 0 40px;
		}

		.category-select,
		.form-select,
		.form-input {
			height: 46px;
			padding: 0 12px;
		}

		.form-textarea {
			min-height: 92px;
			padding: 10px 12px;
			resize: vertical;
		}

		.search-input:focus,
		.category-select:focus,
		.form-input:focus,
		.form-select:focus,
		.form-textarea:focus {
			border-color: rgba(127, 182, 133, 0.75);
			box-shadow: 0 0 0 4px rgba(127, 182, 133, 0.15);
		}

		.module-grid {
			display: grid;
			grid-template-columns: repeat(4, minmax(0, 1fr));
			gap: 18px;
		}

		.module-card {
			background: #fff;
			border-radius: 14px;
			box-shadow: 0 8px 22px rgba(34, 58, 39, 0.1);
			border: 1px solid var(--border);
			overflow: hidden;
			transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
			display: grid;
			grid-template-rows: 1fr auto;
			cursor: pointer;
			animation: riseIn 0.35s ease both;
			height: 100%;
		}

		.module-card:hover {
			transform: translateY(-2px);
			box-shadow: 0 16px 32px rgba(34, 58, 39, 0.2);
			border-color: rgba(127, 182, 133, 0.55);
		}

		.card-body {
			padding: 16px;
			display: flex;
			flex-direction: column;
			gap: 10px;
			height: 100%;
		}

		.card-head {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 10px;
			flex-wrap: wrap;
		}

		.card-icon {
			width: 44px;
			height: 44px;
			border-radius: 12px;
			border: 1px solid transparent;
			background: rgba(127, 182, 133, 0.12);
			color: var(--primary);
			display: inline-flex;
			align-items: center;
			justify-content: center;
			transition: transform 0.2s ease, background 0.2s ease;
		}

		.module-card:hover .card-icon {
			transform: scale(1.05);
		}

		.card-icon svg {
			width: 20px;
			height: 20px;
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
			border: 1px solid;
			border-radius: 999px;
			padding: 4px 10px;
			font-size: 0.72rem;
			font-weight: 700;
		}

		.category-badge {
			border: 1px solid;
			border-radius: 999px;
			padding: 4px 10px;
			font-size: 0.75rem;
			font-weight: 700;
		}

		.card-title {
			margin: 0;
			font-size: 1.02rem;
			font-weight: 800;
			line-height: 1.35;
			color: #2c3e2e;
			font-family: "Fraunces", "Manrope", serif;
			display: -webkit-box;
			-webkit-line-clamp: 2;
			-webkit-box-orient: vertical;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		.module-card:hover .card-title {
			color: #2f6a37;
		}

		.card-desc {
			margin: 0;
			font-size: 0.86rem;
			line-height: 1.5;
			color: #4a5e4e;
			display: -webkit-box;
			-webkit-line-clamp: 3;
			-webkit-box-orient: vertical;
			overflow: hidden;
		}

		.card-desc.is-muted {
			color: #7a8f7c;
			font-style: italic;
		}

		.source-chip {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			font-size: 0.74rem;
			font-weight: 700;
			padding: 4px 9px;
			border-radius: 999px;
			border: 1px solid;
			width: fit-content;
			align-self: flex-start;
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
			gap: 10px;
			font-size: 0.74rem;
			color: var(--muted-foreground);
			margin-top: auto;
			padding-top: 8px;
			border-top: 1px dashed rgba(127, 182, 133, 0.28);
			flex-wrap: wrap;
		}

		.card-actions {
			display: inline-flex;
			gap: 4px;
			margin-left: auto;
		}

		.icon-btn {
			border: 0;
			background: transparent;
			width: 30px;
			height: 30px;
			border-radius: 8px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			color: #49624c;
			transition: background 0.2s ease, color 0.2s ease;
		}

		.icon-btn svg {
			width: 16px;
			height: 16px;
			stroke: currentColor;
			fill: none;
			stroke-width: 2;
			stroke-linecap: round;
			stroke-linejoin: round;
		}

		.icon-btn:hover {
			background: rgba(127, 182, 133, 0.16);
			color: #2c3e2e;
		}

		.icon-btn.danger:hover {
			background: #fee2e2;
			color: #b91c1c;
		}

		.card-foot {
			padding: 0 16px 16px;
			display: flex;
			align-items: center;
			justify-content: flex-start;
			border-top: 1px solid rgba(127, 182, 133, 0.2);
		}

		.learn-btn {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			gap: 6px;
			width: 100%;
			height: 38px;
			border-radius: 10px;
			border: 1px solid var(--primary);
			background: var(--primary);
			color: #fff;
			font-size: 0.82rem;
			font-weight: 700;
			text-decoration: none;
			cursor: pointer;
			transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
		}

		.learn-btn:hover {
			background: #6aa96f;
			border-color: #6aa96f;
			transform: translateY(-1px);
		}

		.learn-btn.external {
			background: var(--primary);
			border-color: var(--primary);
		}

		.learn-btn.file {
			background: var(--primary);
			border-color: var(--primary);
		}

		.learn-btn.external:hover {
			background: #6aa96f;
			border-color: #6aa96f;
		}

		.learn-btn.file:hover {
			background: #6aa96f;
			border-color: #6aa96f;
		}

		.learn-btn svg {
			width: 14px;
			height: 14px;
			stroke: currentColor;
			fill: none;
			stroke-width: 2;
			stroke-linecap: round;
			stroke-linejoin: round;
		}


		@keyframes riseIn {
			from {
				opacity: 0;
				transform: translateY(8px);
			}
			to {
				opacity: 1;
				transform: translateY(0);
			}
		}

		.empty-state {
			grid-column: 1 / -1;
			background: #fff;
			border-radius: 14px;
			box-shadow: var(--shadow-lg);
			padding: 30px 20px;
			text-align: center;
			color: var(--muted-foreground);
		}

		.empty-state svg {
			width: 44px;
			height: 44px;
			stroke: #9ab09d;
			fill: none;
			stroke-width: 1.7;
			stroke-linecap: round;
			stroke-linejoin: round;
			margin-bottom: 12px;
		}

		.overlay {
			position: fixed;
			inset: 0;
			background: rgba(20, 29, 23, 0.4);
			backdrop-filter: blur(3px);
			z-index: 90;
			display: none;
		}

		.overlay.show {
			display: block;
		}

		.modal-box {
			position: fixed;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%) scale(0.98);
			width: min(92vw, 760px);
			max-height: 90vh;
			overflow: auto;
			background: linear-gradient(180deg, #ffffff, #fcfefb);
			border-radius: 14px;
			border: 1px solid rgba(127, 182, 133, 0.28);
			box-shadow: var(--shadow-lg);
			z-index: 91;
			padding: 18px;
			opacity: 0;
			pointer-events: none;
			transition: all 0.2s ease;
		}

		.modal-box.show {
			opacity: 1;
			pointer-events: auto;
			transform: translate(-50%, -50%) scale(1);
		}

		.view-module-modal {
			width: min(92vw, 620px);
		}

		.view-shell {
			margin-top: 16px;
			display: grid;
			gap: 12px;
		}

		.view-summary {
			display: grid;
			gap: 8px;
			justify-items: center;
			text-align: center;
		}

		.view-badges {
			display: flex;
			justify-content: center;
			align-items: center;
			gap: 8px;
			flex-wrap: wrap;
		}

		.view-description,
		.view-content {
			margin: 0;
			font-size: 0.9rem;
			line-height: 1.55;
			color: #3c5340;
		}

		.view-block {
			background: linear-gradient(180deg, rgba(245, 250, 246, 0.9), rgba(255, 255, 255, 0.96));
			border: 1px solid rgba(127, 182, 133, 0.24);
			border-radius: 12px;
			padding: 12px;
		}

		.view-created {
			text-align: center;
			font-size: 0.8rem;
			color: #607662;
			background: rgba(127, 182, 133, 0.12);
			border: 1px dashed rgba(127, 182, 133, 0.35);
			padding: 6px 12px;
			border-radius: 999px;
			font-weight: 600;
		}

		.view-actions {
			display: flex;
			justify-content: center;
			gap: 10px;
			flex-wrap: wrap;
		}

		.view-btn {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			gap: 6px;
			border-radius: 10px;
			border: 1px solid var(--primary);
			background: var(--primary);
			color: #fff;
			padding: 8px 14px;
			font-size: 0.86rem;
			font-weight: 700;
			text-decoration: none;
			transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
		}

		.view-btn:hover {
			background: #6aa96f;
			border-color: #6aa96f;
			transform: translateY(-1px);
		}

		.view-btn.outline {
			background: #fff;
			color: #2c3e2e;
			border-color: rgba(127, 182, 133, 0.5);
		}

		.view-btn.outline:hover {
			background: rgba(127, 182, 133, 0.12);
			border-color: rgba(127, 182, 133, 0.6);
		}

		.view-btn svg {
			width: 16px;
			height: 16px;
			stroke: currentColor;
			fill: none;
			stroke-width: 2;
			stroke-linecap: round;
			stroke-linejoin: round;
		}

		.modal-title {
			margin: 0;
			font-size: 1.1rem;
			font-weight: 700;
		}

		.modal-sub {
			margin: 5px 0 0;
			color: var(--muted-foreground);
			font-size: 0.88rem;
		}

		.modal-head {
			padding: 12px 14px;
			border-radius: 12px;
			background: linear-gradient(90deg, rgba(127, 182, 133, 0.18), rgba(255, 229, 153, 0.22));
			border: 1px solid rgba(127, 182, 133, 0.28);
		}

		.form-grid {
			margin-top: 16px;
			display: grid;
			gap: 14px;
		}

		.form-row-two {
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 12px;
		}

		.field-block {
			background: linear-gradient(180deg, rgba(245, 250, 246, 0.9), rgba(255, 255, 255, 0.95));
			border: 1px solid rgba(127, 182, 133, 0.24);
			border-radius: 12px;
			padding: 12px;
		}

		.field-block.source-block {
			background: linear-gradient(180deg, rgba(243, 248, 239, 0.95), rgba(255, 255, 255, 0.98));
		}

		.field-label {
			margin: 0 0 6px;
			font-size: 0.84rem;
			font-weight: 700;
			color: #365039;
		}

		.mode-switch {
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 10px;
		}

		.mode-option {
			border: 1px solid rgba(127, 182, 133, 0.3);
			border-radius: 12px;
			padding: 12px;
			display: flex;
			align-items: flex-start;
			gap: 10px;
			cursor: pointer;
			background: #fff;
			transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
		}

		.mode-option:hover {
			background: #f8fcf9;
			border-color: rgba(127, 182, 133, 0.45);
		}

		.mode-option input {
			margin-top: 2px;
			flex-shrink: 0;
		}

		.mode-icon {
			width: 30px;
			height: 30px;
			border-radius: 9px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			flex-shrink: 0;
			background: rgba(127, 182, 133, 0.14);
			color: #3a5f3f;
		}

		.mode-icon svg {
			width: 16px;
			height: 16px;
			stroke: currentColor;
			fill: none;
			stroke-width: 2;
			stroke-linecap: round;
			stroke-linejoin: round;
		}

		.mode-copy {
			min-width: 0;
		}

		.mode-title {
			display: block;
			font-size: 0.88rem;
			font-weight: 700;
			color: #304d34;
		}

		.mode-desc {
			display: block;
			margin-top: 2px;
			font-size: 0.77rem;
			line-height: 1.4;
			color: var(--muted-foreground);
		}

		.mode-option.active {
			border-color: rgba(127, 182, 133, 0.8);
			box-shadow: 0 0 0 4px rgba(127, 182, 133, 0.15);
			background: #f5faf6;
		}

		.mode-option.active .mode-icon {
			background: rgba(127, 182, 133, 0.24);
		}

		.field-hint {
			margin-top: 6px;
			font-size: 0.77rem;
			color: var(--muted-foreground);
		}

		.source-placeholder {
			margin-top: 8px;
			padding: 10px 12px;
			border-radius: 10px;
			font-size: 0.8rem;
			color: #57705a;
			background: rgba(127, 182, 133, 0.1);
			border: 1px dashed rgba(127, 182, 133, 0.35);
		}

		.form-section.is-hidden {
			display: none;
		}

		.is-hidden {
			display: none !important;
		}

		.meta-badges {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			flex-wrap: wrap;
		}

		.source-badge {
			border: 1px solid;
			border-radius: 999px;
			padding: 4px 9px;
			font-size: 0.72rem;
			font-weight: 700;
		}

		.source-badge.content {
			background: rgba(127, 182, 133, 0.15);
			border-color: rgba(127, 182, 133, 0.35);
			color: #2f6a37;
		}

		.source-badge.external {
			background: rgba(59, 130, 246, 0.14);
			border-color: rgba(59, 130, 246, 0.34);
			color: #1d4ed8;
		}

		.source-badge.file {
			background: rgba(139, 92, 246, 0.14);
			border-color: rgba(139, 92, 246, 0.34);
			color: #7c3aed;
		}

		.form-actions {
			margin-top: 16px;
			display: flex;
			justify-content: flex-end;
			gap: 8px;
			padding-top: 12px;
			border-top: 1px solid rgba(127, 182, 133, 0.2);
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

		.logout-modal,
		.delete-modal {
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

		.logout-modal.show,
		.delete-modal.show {
			opacity: 1;
			pointer-events: auto;
			transform: translate(-50%, -50%) scale(1);
		}

		.logout-title,
		.delete-title {
			margin: 0 0 8px;
			font-weight: 700;
			font-size: 1.15rem;
		}

		.logout-desc,
		.delete-desc {
			margin: 0 0 18px;
			color: var(--muted-foreground);
			font-size: 0.92rem;
		}

		.cat-soil {
			background: #fef3c7;
			color: #b45309;
			border-color: #fde68a;
		}

		.cat-fertilizer {
			background: #dcfce7;
			color: #166534;
			border-color: #bbf7d0;
		}

		.cat-pest {
			background: #fee2e2;
			color: #b91c1c;
			border-color: #fecaca;
		}

		.cat-irrigation {
			background: #dbeafe;
			color: #1d4ed8;
			border-color: #bfdbfe;
		}

		.cat-harvesting {
			background: #f3e8ff;
			color: #7e22ce;
			border-color: #e9d5ff;
		}

		.cat-general {
			background: #f3f4f6;
			color: #374151;
			border-color: #e5e7eb;
		}

		.cat-disease {
			background: #fff7ed;
			color: #c2410c;
			border-color: #fed7aa;
		}

		.card-icon.cat-soil,
		.card-icon.cat-fertilizer,
		.card-icon.cat-pest,
		.card-icon.cat-irrigation,
		.card-icon.cat-harvesting,
		.card-icon.cat-general,
		.card-icon.cat-disease {
			background: rgba(127, 182, 133, 0.12);
			color: var(--primary);
			border-color: rgba(127, 182, 133, 0.25);
		}

		/* Success Modal Styles */
		.modal-content.premium-modal {
			border: none !important;
			border-radius: 20px !important;
			background: rgba(255, 255, 255, 0.9) !important;
			backdrop-filter: blur(12px) !important;
			box-shadow: 0 25px 55px rgba(0,0,0,0.15) !important;
		}
		.modal-header.no-border {
			border: none !important;
			padding-bottom: 0 !important;
		}
		.modal-footer.no-border {
			border: none !important;
			padding-top: 0 !important;
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
		.warning-icon-wrap {
			width: 64px;
			height: 64px;
			background: #fef3c7;
			color: #d97706;
			border-radius: 50%;
			display: flex;
			align-items: center;
			justify-content: center;
			margin: 0 auto 16px;
			font-size: 28px;
			box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
		}
		.confirm-icon-wrap {
			width: 64px;
			height: 64px;
			background: #ecfdf5;
			color: #10b981;
			border-radius: 50%;
			display: flex;
			align-items: center;
			justify-content: center;
			margin: 0 auto 16px;
			font-size: 28px;
		}

		@media (max-width: 1200px) {
			.module-grid {
				grid-template-columns: repeat(3, minmax(0, 1fr));
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

			.title-row h2 {
				font-size: 1.7rem;
			}

			.filters {
				grid-template-columns: 1fr;
			}

			.module-toolbar {
				flex-direction: column;
				align-items: flex-start;
			}

			.module-grid {
				grid-template-columns: repeat(2, minmax(0, 1fr));
			}
		}

		@media (max-width: 700px) {
			.module-grid {
				grid-template-columns: minmax(0, 1fr);
			}

			.mode-switch {
				grid-template-columns: 1fr;
			}

			.form-row-two {
				grid-template-columns: 1fr;
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
				<button class="nav-btn" type="button" id="goDashboardBtn">
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
				<button class="nav-btn active" type="button">
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
				<div class="page-stack">
					<section class="title-row">
						<div>
							<h2>Guide Module Management</h2>
							<p>Create and manage farming guide content</p>
						</div>
						<button class="btn-add" id="addModuleBtn" type="button">
							<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg>
							Add New Module
						</button>
					</section>

					<section class="filters">
						<div class="search-wrap">
							<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
							<input class="search-input" id="searchInput" type="text" placeholder="Search modules...">
						</div>
						<select class="category-select" id="categoryFilter" aria-label="Filter by category">
							<option value="all">All Categories</option>
							<option value="Soil Management">Soil Management</option>
							<option value="Fertilizer">Fertilizer</option>
							<option value="Pest Control">Pest Control</option>
							<option value="Irrigation">Irrigation</option>
							<option value="Harvesting">Harvesting</option>
							<option value="Corn Leaf Disease">Corn Leaf Disease</option>
							<option value="General">General</option>
						</select>
					</section>

					<section class="module-toolbar" aria-live="polite">
						<div>
							<h3>Module Library</h3>
							<p>Showing <span id="moduleCount">0</span> modules</p>
						</div>
						<span class="module-tip">Tip: Click a card to preview</span>
					</section>

					<section class="module-grid" id="moduleGrid"></section>
				</div>
			</main>
		</div>
	</div>

	<div class="overlay" id="moduleFormMask"></div>
	<div class="modal-box" id="moduleFormModal" role="dialog" aria-modal="true" aria-labelledby="moduleFormTitle">
		<div class="modal-head">
			<h3 class="modal-title" id="moduleFormTitle">Add New Guide Module</h3>
			<p class="modal-sub" id="moduleFormSub">Create a new farming guide for farmers to access</p>
		</div>

		<div class="form-grid">
			<div class="form-row-two">
				<div class="field-block">
					<p class="field-label">Module Title *</p>
					<input class="form-input" id="moduleTitle" type="text" placeholder="e.g., Understanding Soil pH">
				</div>
				<div class="field-block">
					<p class="field-label">Category *</p>
					<select class="form-select" id="moduleCategory">
						<option value="">Select a category</option>
						<option value="Soil Management">Soil Management</option>
						<option value="Fertilizer">Fertilizer</option>
						<option value="Pest Control">Pest Control</option>
						<option value="Irrigation">Irrigation</option>
						<option value="Harvesting">Harvesting</option>
						<option value="Corn Leaf Disease">Corn Leaf Disease</option>
						<option value="General">General</option>
					</select>
				</div>
			</div>
			<div class="field-block">
				<p class="field-label">Short Description *</p>
				<textarea class="form-textarea" id="moduleDescription" rows="2" placeholder="Brief description of the guide module"></textarea>
			</div>
			<div class="field-block source-block">
				<p class="field-label">Content Source *</p>
				<div class="mode-switch" id="contentModeSwitch">
					<label class="mode-option" id="modeContentLabel">
						<input type="radio" name="contentMode" value="content">
						<span class="mode-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M4 5h7a3 3 0 0 1 3 3v11H7a3 3 0 0 0-3 3V5z"></path><path d="M20 5h-7a3 3 0 0 0-3 3v11h7a3 3 0 0 1 3 3V5z"></path></svg>
						</span>
						<span class="mode-copy">
							<span class="mode-title">Guide Content</span>
							<span class="mode-desc">Admin writes the complete guide text directly.</span>
						</span>
					</label>
					<label class="mode-option" id="modeExternalLabel">
						<input type="radio" name="contentMode" value="external">
						<span class="mode-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M14 3h7v7"></path><path d="M10 14 21 3"></path><path d="M21 14v7h-7"></path><path d="M3 10v11h11"></path></svg>
						</span>
						<span class="mode-copy">
							<span class="mode-title">External Link</span>
							<span class="mode-desc">Admin provides a URL that opens the guide page.</span>
						</span>
					</label>
					<label class="mode-option" id="modeFileLabel">
						<input type="radio" name="contentMode" value="file">
						<span class="mode-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
						</span>
						<span class="mode-copy">
							<span class="mode-title">File Download</span>
							<span class="mode-desc">Admin uploads a file (PDF, Doc, etc.) for farmers.</span>
						</span>
					</label>
				</div>
				<p class="field-hint">Choose one source: write the guide here, or point users to an external link.</p>
				<div class="source-placeholder" id="sourcePlaceholder">Select a source type first to show its input field.</div>
			</div>
			<div class="form-section is-hidden field-block" id="contentSection">
				<p class="field-label">Guide Content *</p>
				<textarea class="form-textarea" id="moduleContent" rows="8" placeholder="Full guide content with detailed information"></textarea>
			</div>
			<div class="form-section is-hidden field-block" id="externalLinkSection">
				<p class="field-label">External Link *</p>
				<input class="form-input" id="moduleLink" type="text" placeholder="e.g., https://example.com/guide">
			</div>
			<div class="form-section is-hidden field-block" id="fileSection">
				<p class="field-label">Upload Guide File *</p>
				<input class="form-input" id="moduleFile" type="file" accept=".pdf,.doc,.docx,.jpg,.png">
				<p id="existingFileName" class="field-hint mt-2 mb-0" style="color: #2f6a37; font-weight: 600;"></p>
			</div>
		</div>

		<div class="form-actions">
			<button class="btn btn-outline-secondary" id="cancelModuleBtn" type="button">Cancel</button>
			<button class="btn btn-success" id="saveModuleBtn" type="button">Add Module</button>
		</div>
	</div>

	<div class="overlay" id="viewModuleMask"></div>
	<div class="modal-box view-module-modal" id="viewModuleModal" role="dialog" aria-modal="true" aria-labelledby="viewModuleTitle">
		<div class="modal-head">
			<h3 class="modal-title" id="viewModuleTitle">Guide Module Details</h3>
			<p class="modal-sub">Review module information before opening resources.</p>
		</div>
		<div class="view-shell">
			<div class="view-summary">
				<div class="view-badges">
					<span class="category-badge cat-general" id="viewModuleCategory">General</span>
					<span class="source-badge content" id="viewModuleSource">Guide Content</span>
				</div>
				<p class="view-created" id="viewModuleCreated">Created: -</p>
			</div>
			<div class="view-block">
				<p class="field-label">Short Description</p>
				<p class="view-description" id="viewModuleDescription"></p>
			</div>
			<div class="view-block" id="viewContentBlock">
				<p class="field-label">Guide Content</p>
				<p class="view-content" id="viewModuleContent"></p>
			</div>
			<div class="view-actions is-hidden" id="viewLinkWrap">
				<a class="view-btn" id="openExternalLinkBtn" href="#" target="_blank" rel="noopener noreferrer">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3h7v7"></path><path d="M10 14 21 3"></path><path d="M21 14v7h-7"></path><path d="M3 10v11h11"></path></svg>
					Open External Link
				</a>
			</div>
			<div class="view-actions is-hidden" id="viewFileWrap">
				<a class="view-btn" id="viewFileBtn" href="#" target="_blank" rel="noopener noreferrer">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4z"></path><path d="M8 9h8"></path><path d="M8 13h6"></path></svg>
					View File
				</a>
				<a class="view-btn outline" id="downloadFileBtn" href="#" download>
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
					Download File
				</a>
			</div>
		</div>
		<div class="form-actions">
			<button class="btn btn-outline-secondary" id="closeViewModuleBtn" type="button">Close</button>
		</div>
	</div>

	<div class="modal-mask" id="deleteModalMask"></div>
	<div class="delete-modal" id="deleteModal">
		<h3 class="delete-title">Delete Guide Module</h3>
		<p class="delete-desc">Are you sure you want to delete this guide module? This action cannot be undone.</p>
		<div class="d-flex justify-content-end gap-2">
			<button class="btn btn-outline-secondary" id="cancelDelete" type="button">Cancel</button>
			<button class="btn btn-danger" id="confirmDelete" type="button">Delete</button>
		</div>
	</div>

	<div class="modal-mask" id="logoutModalMask"></div>
	<div class="logout-modal" id="logoutModal">
		<h3 class="logout-title">Are you sure you want to logout?</h3>
		<p class="logout-desc">This action will end your session and log you out of the system.</p>
		<div class="d-flex justify-content-end gap-2">
			<button class="btn btn-outline-secondary" id="cancelLogout" type="button">Cancel</button>
			<button class="btn btn-danger" id="confirmLogout" type="button">Logout</button>
		</div>
	</div>

	<!-- Success Modal -->
	<div class="modal fade" id="successModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content premium-modal">
				<div class="modal-header no-border">
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body text-center pb-4">
					<div class="success-icon-wrap">
						<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
					</div>
					<h4 class="mb-2">Success!</h4>
					<p class="text-muted mb-0" id="successModalMessage">Successfully recorded.</p>
				</div>
				<div class="modal-footer no-border justify-content-center pb-4">
					<button type="button" class="btn btn-success px-5" data-bs-dismiss="modal" style="border-radius: 12px; font-weight: 600;">Great</button>
				</div>
			</div>
		</div>
	</div>

	<!-- Info/Warning Modal -->
	<div class="modal fade" id="infoModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content premium-modal">
				<div class="modal-header no-border">
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body text-center pb-4">
					<div class="warning-icon-wrap" id="infoModalIcon">
						<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
					</div>
					<h4 class="mb-2" id="infoModalTitle">Attention</h4>
					<p class="text-muted mb-0" id="infoModalMessage">Please check your inputs.</p>
				</div>
				<div class="modal-footer no-border justify-content-center pb-4">
					<button type="button" class="btn btn-warning px-5" data-bs-dismiss="modal" style="border-radius: 12px; font-weight: 600; color: #fff;">Understood</button>
				</div>
			</div>
		</div>
	</div>

	<!-- Confirm Action Modal -->
	<div class="modal fade" id="confirmActionModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content premium-modal">
				<div class="modal-header no-border">
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body text-center pb-0">
					<div class="confirm-icon-wrap">
						<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
					</div>
					<h4 class="mb-2" id="confirmActionTitle">Confirm Action</h4>
					<p class="text-muted mb-0" id="confirmActionMessage">Are you sure you want to proceed?</p>
				</div>
				<div class="modal-footer no-border justify-content-center py-4 gap-2">
					<button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal" style="border-radius: 12px; font-weight: 600;">Cancel</button>
					<button type="button" class="btn btn-success px-4" id="btnDoConfirmAction" style="border-radius: 12px; font-weight: 600;">Yes, Proceed</button>
				</div>
			</div>
		</div>
	</div>

	<script src="../bootstrap5/js/bootstrap.bundle.min.js"></script>

	<script>
		/* Version 1.0.2 - Premium Modal Update */
		var serverModules = <?php echo json_encode($guideModules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

		(function () {
			function normalizeModules(list) {
				var normalized = [];
				var source = Array.isArray(list) ? list : [];

				for (var i = 0; i < source.length; i += 1) {
					var item = source[i] || {};
					var mode = item.contentMode;
					if (mode !== "content" && mode !== "external" && mode !== "file") {
						if (item.filePath) mode = "file";
						else if (item.link) mode = "external";
						else mode = "content";
					}

					normalized.push({
						id: String(item.id || ""),
						contentMode: mode,
						title: item.title || "",
						category: item.category || "General",
						description: item.description || "",
						content: item.content || "",
						createdDate: item.createdDate || "",
						updatedDate: item.updatedDate || item.createdDate || "",
						link: item.link || "",
						filePath: item.filePath || ""
					});
				}

				return normalized;
			}

			function syncModules(action, data) {
				var formData = new FormData();
				formData.append("action", action);
				for (var key in data) {
					if (data.hasOwnProperty(key)) {
						formData.append(key, data[key]);
					}
				}

				return fetch(window.location.pathname, {
					method: "POST",
					body: formData
				}).then(function (response) {
					return response.json().then(function (respData) {
						if (!response.ok || !respData || !respData.success) {
							throw new Error((respData && respData.message) ? respData.message : "Unable to process your request.");
						}
						return respData;
					});
				});
			}

			var modules = normalizeModules(serverModules);
			var editingId = null;
			var deletingId = null;

			var searchInput = document.getElementById("searchInput");
			var categoryFilter = document.getElementById("categoryFilter");
			var moduleGrid = document.getElementById("moduleGrid");
			var moduleCount = document.getElementById("moduleCount");

			var addModuleBtn = document.getElementById("addModuleBtn");
			var moduleFormMask = document.getElementById("moduleFormMask");
			var moduleFormModal = document.getElementById("moduleFormModal");
			var moduleFormTitle = document.getElementById("moduleFormTitle");
			var moduleFormSub = document.getElementById("moduleFormSub");
			var moduleTitle = document.getElementById("moduleTitle");
			var moduleCategory = document.getElementById("moduleCategory");
			var moduleDescription = document.getElementById("moduleDescription");
			var contentModeInputs = document.querySelectorAll('input[name="contentMode"]');
			var modeContentLabel = document.getElementById("modeContentLabel");
			var modeExternalLabel = document.getElementById("modeExternalLabel");
			var sourcePlaceholder = document.getElementById("sourcePlaceholder");
			var contentSection = document.getElementById("contentSection");
			var externalLinkSection = document.getElementById("externalLinkSection");
			var moduleContent = document.getElementById("moduleContent");
			var moduleLink = document.getElementById("moduleLink");
			var cancelModuleBtn = document.getElementById("cancelModuleBtn");
			var saveModuleBtn = document.getElementById("saveModuleBtn");

			var deleteModalMask = document.getElementById("deleteModalMask");
			var deleteModal = document.getElementById("deleteModal");
			var cancelDelete = document.getElementById("cancelDelete");
			var confirmDelete = document.getElementById("confirmDelete");

			var viewModuleMask = document.getElementById("viewModuleMask");
			var viewModuleModal = document.getElementById("viewModuleModal");
			var viewModuleTitle = document.getElementById("viewModuleTitle");
			var viewModuleCategory = document.getElementById("viewModuleCategory");
			var viewModuleSource = document.getElementById("viewModuleSource");
			var viewModuleCreated = document.getElementById("viewModuleCreated");
			var viewModuleDescription = document.getElementById("viewModuleDescription");
			var viewContentBlock = document.getElementById("viewContentBlock");
			var viewModuleContent = document.getElementById("viewModuleContent");
			var viewLinkWrap = document.getElementById("viewLinkWrap");
			var openExternalLinkBtn = document.getElementById("openExternalLinkBtn");
			var viewFileWrap = document.getElementById("viewFileWrap");
			var viewFileBtn = document.getElementById("viewFileBtn");
			var downloadFileBtn = document.getElementById("downloadFileBtn");
			var closeViewModuleBtn = document.getElementById("closeViewModuleBtn");

			var modeFileLabel = document.getElementById("modeFileLabel");
			var fileSection = document.getElementById("fileSection");
			var moduleFile = document.getElementById("moduleFile");
			var existingFileName = document.getElementById("existingFileName");

			var logoutModal = document.getElementById("logoutModal");
			var logoutMask = document.getElementById("logoutModalMask");
			var cancelLogout = document.getElementById("cancelLogout");
			var confirmLogout = document.getElementById("confirmLogout");

			var successModalEl = document.getElementById("successModal");
			var successModal = successModalEl ? new bootstrap.Modal(successModalEl) : null;
			var successModalMessage = document.getElementById("successModalMessage");

			var infoModalEl = document.getElementById("infoModal");
			var infoModal = infoModalEl ? new bootstrap.Modal(infoModalEl) : null;
			var infoModalMessage = document.getElementById("infoModalMessage");

			var confirmActionModalEl = document.getElementById("confirmActionModal");
			var confirmActionModal = confirmActionModalEl ? new bootstrap.Modal(confirmActionModalEl) : null;
			var confirmActionMessage = document.getElementById("confirmActionMessage");
			var btnDoConfirmAction = document.getElementById("btnDoConfirmAction");

			function showSuccess(msg) {
				console.log("Showing Success Modal: " + msg);
				if (successModal) {
					successModalMessage.textContent = msg || "Successfully recorded.";
					successModal.show();
				}
			}

			function showWarning(msg) {
				console.log("Showing Warning Modal: " + msg);
				if (infoModal) {
					infoModalMessage.textContent = msg || "Please check your input.";
					infoModal.show();
				}
			}

			var onConfirmCallback = null;
			function showConfirm(msg, callback) {
				console.log("Showing Confirm Modal: " + msg);
				if (confirmActionModal) {
					confirmActionMessage.textContent = msg || "Are you sure?";
					onConfirmCallback = callback;
					confirmActionModal.show();
				}
			}

			if (btnDoConfirmAction) {
				btnDoConfirmAction.addEventListener("click", function() {
					if (onConfirmCallback) onConfirmCallback();
					onConfirmCallback = null;
					confirmActionModal.hide();
				});
			}

			function escapeHtml(text) {
				return String(text)
					.replace(/&/g, "&amp;")
					.replace(/</g, "&lt;")
					.replace(/>/g, "&gt;")
					.replace(/\"/g, "&quot;")
					.replace(/'/g, "&#039;");
			}

			function formatDate(dateValue) {
				var parsed = new Date(dateValue + "T00:00:00");
				if (isNaN(parsed.getTime())) {
					return dateValue;
				}
				return parsed.toLocaleDateString("en-US");
			}

			function categoryClass(category) {
				if (category === "Soil Management") return "cat-soil";
				if (category === "Fertilizer") return "cat-fertilizer";
				if (category === "Pest Control") return "cat-pest";
				if (category === "Irrigation") return "cat-irrigation";
				if (category === "Harvesting") return "cat-harvesting";
				if (category === "Corn Leaf Disease") return "cat-disease";
				return "cat-general";
			}

			function getSelectedContentMode() {
				for (var i = 0; i < contentModeInputs.length; i += 1) {
					if (contentModeInputs[i].checked) {
						return contentModeInputs[i].value;
					}
				}
				return "";
			}

			function setContentMode(mode) {
				var selectedMode = (mode === "content" || mode === "external" || mode === "file") ? mode : "";

				for (var i = 0; i < contentModeInputs.length; i += 1) {
					contentModeInputs[i].checked = contentModeInputs[i].value === selectedMode;
				}

				if (!selectedMode) {
					contentSection.classList.add("is-hidden");
					externalLinkSection.classList.add("is-hidden");
					modeContentLabel.classList.remove("active");
					modeExternalLabel.classList.remove("active");
					sourcePlaceholder.classList.remove("is-hidden");
					return;
				}

				if (selectedMode === "content") {
					contentSection.classList.remove("is-hidden");
					externalLinkSection.classList.add("is-hidden");
					fileSection.classList.add("is-hidden");
					modeContentLabel.classList.add("active");
					modeExternalLabel.classList.remove("active");
					modeFileLabel.classList.remove("active");
					sourcePlaceholder.classList.add("is-hidden");
					return;
				}

				if (selectedMode === "external") {
					contentSection.classList.add("is-hidden");
					externalLinkSection.classList.remove("is-hidden");
					fileSection.classList.add("is-hidden");
					modeContentLabel.classList.remove("active");
					modeExternalLabel.classList.add("active");
					modeFileLabel.classList.remove("active");
					sourcePlaceholder.classList.add("is-hidden");
					return;
				}

				contentSection.classList.add("is-hidden");
				externalLinkSection.classList.add("is-hidden");
				fileSection.classList.remove("is-hidden");
				modeContentLabel.classList.remove("active");
				modeExternalLabel.classList.remove("active");
				modeFileLabel.classList.add("active");
				sourcePlaceholder.classList.add("is-hidden");
			}

			function clearForm() {
				moduleTitle.value = "";
				moduleCategory.value = "";
				moduleDescription.value = "";
				moduleContent.value = "";
				moduleLink.value = "";
				moduleFile.value = "";
				existingFileName.textContent = "";
				setContentMode("");
			}

			function openModuleForm(mode, moduleItem) {
				if (mode === "add") {
					editingId = null;
					moduleFormTitle.textContent = "Add New Guide Module";
					moduleFormSub.textContent = "Create a new farming guide for farmers to access";
					saveModuleBtn.textContent = "Add Module";
					clearForm();
				} else {
					editingId = moduleItem.id;
					moduleFormTitle.textContent = "Edit Guide Module";
					moduleFormSub.textContent = "Update the guide module information";
					saveModuleBtn.textContent = "Save Changes";
					moduleTitle.value = moduleItem.title;
					moduleCategory.value = moduleItem.category;
					moduleDescription.value = moduleItem.description;
					moduleContent.value = moduleItem.content;
					moduleLink.value = moduleItem.link || "";
					moduleFile.value = "";
					existingFileName.textContent = moduleItem.filePath ? "Current file: " + moduleItem.filePath.split("/").pop() : "";
					setContentMode(moduleItem.contentMode || "content");
				}

				moduleFormMask.classList.add("show");
				moduleFormModal.classList.add("show");
			}

			function closeModuleForm() {
				moduleFormMask.classList.remove("show");
				moduleFormModal.classList.remove("show");
			}

			function openDeleteModal(id) {
				deletingId = id;
				deleteModalMask.classList.add("show");
				deleteModal.classList.add("show");
			}

			function closeDeleteModal() {
				deletingId = null;
				deleteModalMask.classList.remove("show");
				deleteModal.classList.remove("show");
			}

			function openViewModal(moduleItem) {
				if (!moduleItem) {
					return;
				}

				var isExternal = moduleItem.contentMode === "external";
				var isFile = moduleItem.contentMode === "file";
				viewModuleTitle.textContent = moduleItem.title || "Guide Module Details";
				viewModuleCategory.className = "category-badge " + categoryClass(moduleItem.category || "General");
				viewModuleCategory.textContent = moduleItem.category || "General";
				
				var sourceClass = "content";
				var sourceText = "Guide Content";
				if (isExternal) { sourceClass = "external"; sourceText = "External Link"; }
				else if (isFile) { sourceClass = "file"; sourceText = "File Download"; }

				viewModuleSource.className = "source-badge " + sourceClass;
				viewModuleSource.textContent = sourceText;
				viewModuleCreated.textContent = "Created: " + formatDate(moduleItem.createdDate || "-");
				viewModuleDescription.textContent = moduleItem.description || "No description available.";

				viewContentBlock.classList.add("is-hidden");
				viewLinkWrap.classList.add("is-hidden");
				viewFileWrap.classList.add("is-hidden");

				if (isExternal) {
					viewLinkWrap.classList.remove("is-hidden");
					openExternalLinkBtn.href = moduleItem.link || "#";
				} else if (isFile) {
					viewFileWrap.classList.remove("is-hidden");
					viewFileBtn.href = moduleItem.filePath || "#";
					downloadFileBtn.href = moduleItem.filePath || "#";
				} else {
					viewContentBlock.classList.remove("is-hidden");
					viewModuleContent.textContent = moduleItem.content || "No guide content provided.";
				}

				viewModuleMask.classList.add("show");
				viewModuleModal.classList.add("show");
			}

			function closeViewModal() {
				viewModuleMask.classList.remove("show");
				viewModuleModal.classList.remove("show");
			}

			function openLogout() {
				logoutMask.classList.add("show");
				logoutModal.classList.add("show");
			}

			function closeLogout() {
				logoutMask.classList.remove("show");
				logoutModal.classList.remove("show");
			}

			function filteredModules() {
				var query = searchInput.value.trim().toLowerCase();
				var selected = categoryFilter.value;

				return modules.filter(function (item) {
					var textMatch = item.title.toLowerCase().indexOf(query) !== -1 ||
						item.description.toLowerCase().indexOf(query) !== -1;
					var categoryMatch = selected === "all" || item.category === selected;
					return textMatch && categoryMatch;
				});
			}

			function renderModules() {
				var rows = filteredModules();

				if (moduleCount) {
					moduleCount.textContent = rows.length;
				}

				if (!rows.length) {
					moduleGrid.innerHTML =
						'<div class="empty-state">' +
							'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h7a3 3 0 0 1 3 3v11H7a3 3 0 0 0-3 3V5z"></path><path d="M20 5h-7a3 3 0 0 0-3 3v11h7a3 3 0 0 1 3 3V5z"></path></svg>' +
							'<p>No guide modules found</p>' +
						'</div>';
					return;
				}

				var html = "";
				for (var i = 0; i < rows.length; i += 1) {
					var item = rows[i];
					var catClass = categoryClass(item.category);
					var isExternal = item.contentMode === "external";
					var isFile = item.contentMode === "file";
					
					var sourceLabel = "Guide Content";
					var sourceClass = "source-chip content";
					if (isExternal) { sourceLabel = "External Link"; sourceClass = "source-chip external"; }
					else if (isFile) { sourceLabel = "File Download"; sourceClass = "source-chip file"; }

					var descriptionText = item.description ? escapeHtml(item.description) : "";
					var descriptionHtml = descriptionText
						? '<p class="card-desc">' + descriptionText + '</p>'
						: '<p class="card-desc is-muted">No description provided.</p>';

					var cardClass = "module-card";
					var linkHtml = '<button class="learn-btn" type="button">View Details</button>';
					
					if (isExternal && item.link) {
						linkHtml = '<a class="learn-btn external" href="' + escapeHtml(item.link) + '" target="_blank" rel="noopener noreferrer"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3h7v7"></path><path d="M10 14 21 3"></path><path d="M21 14v7h-7"></path><path d="M3 10v11h11"></path></svg>View External Guide</a>';
					} else if (isFile && item.filePath) {
						linkHtml = '<a class="learn-btn file" href="' + escapeHtml(item.filePath) + '" download><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>Download File</a>';
					}

					html +=
						'<article class="' + cardClass + '" data-id="' + escapeHtml(item.id) + '">' +
							'<div class="card-body">' +
								'<div class="card-head">' +
									'<span class="card-icon ' + catClass + '">' +
									'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h7a3 3 0 0 1 3 3v11H7a3 3 0 0 0-3 3V5z"></path><path d="M20 5h-7a3 3 0 0 0-3 3v11h7a3 3 0 0 1 3 3V5z"></path></svg>' +
									'</span>' +
									'<span class="badge ' + catClass + '">' + escapeHtml(item.category) + '</span>' +
								'</div>' +
								'<h3 class="card-title">' + escapeHtml(item.title) + '</h3>' +
								descriptionHtml +
								'<span class="' + sourceClass + '">' + sourceLabel + '</span>' +
								'<div class="card-meta">' +
									'<span>Created ' + escapeHtml(formatDate(item.createdDate)) + '</span>' +
									(item.updatedDate ? '<span>Updated ' + escapeHtml(formatDate(item.updatedDate)) + '</span>' : '') +
									'<span class="card-actions">' +
										'<button class="icon-btn" type="button" data-action="edit" data-id="' + escapeHtml(item.id) + '" aria-label="Edit module">' +
											'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"></path><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"></path></svg>' +
										'</button>' +
										'<button class="icon-btn danger" type="button" data-action="delete" data-id="' + escapeHtml(item.id) + '" aria-label="Delete module">' +
											'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"></path><path d="M8 6V4h8v2"></path><path d="M19 6l-1 14H6L5 6"></path><path d="M10 11v6M14 11v6"></path></svg>' +
										'</button>' +
									'</span>' +
								'</div>' +
							'</div>' +
							'<div class="card-foot">' +
								linkHtml +
							'</div>' +
						'</article>';
				}

				moduleGrid.innerHTML = html;
			}

			addModuleBtn.addEventListener("click", function () {
				openModuleForm("add");
			});

			for (var modeIndex = 0; modeIndex < contentModeInputs.length; modeIndex += 1) {
				contentModeInputs[modeIndex].addEventListener("change", function () {
					setContentMode(this.value);
				});
			}

			cancelModuleBtn.addEventListener("click", closeModuleForm);
			moduleFormMask.addEventListener("click", closeModuleForm);

			saveModuleBtn.addEventListener("click", function () {
				var selectedMode = getSelectedContentMode();
				var payload = {
					contentMode: selectedMode,
					title: moduleTitle.value.trim(),
					category: moduleCategory.value,
					description: moduleDescription.value.trim(),
					content: moduleContent.value.trim(),
					link: moduleLink.value.trim()
				};

				if (!payload.title || !payload.category || !payload.description) {
					showWarning("Please complete all required fields.");
					return;
				}

				if (!payload.contentMode) {
					showWarning("Please choose a Content Source (Guide Content or External Link).");
					return;
				}

				if (payload.contentMode === "content") {
					if (!payload.content) {
						showWarning("Please enter Guide Content.");
						return;
					}
					payload.link = "";
				} else if (payload.contentMode === "external") {
					if (!payload.link) {
						showWarning("Please enter an External Link.");
						return;
					}
					try {
						new URL(payload.link);
					} catch (error) {
						showWarning("Please enter a valid URL (example: https://example.com/guide).");
						return;
					}
					payload.content = "";
				} else {
					// File Mode
					if (!editingId && (!moduleFile.files || !moduleFile.files[0])) {
						showWarning("Please upload a file.");
						return;
					}
					payload.content = "";
					payload.link = "";
					if (moduleFile.files && moduleFile.files[0]) {
						payload.guideFile = moduleFile.files[0];
					}
				}

				var confirmText = editingId
					? "Are you sure you want to save changes to this guide module?"
					: "Are you sure you want to add this guide module?";

				showConfirm(confirmText, function() {
					var action = editingId ? "update" : "add";
					var previousText = saveModuleBtn.textContent;
					saveModuleBtn.disabled = true;
					saveModuleBtn.textContent = editingId ? "Saving..." : "Adding...";

					syncModules(action, {
						id: editingId,
						title: payload.title,
						category: payload.category,
						description: payload.description,
						contentMode: payload.contentMode,
						content: payload.content,
						link: payload.link,
						guideFile: payload.guideFile
					}).then(function (response) {
						modules = normalizeModules(response.modules || []);
						closeModuleForm();
						renderModules();
						showSuccess(response.message || (editingId ? "Guide module updated successfully." : "Guide module added successfully."));
					}).catch(function (error) {
						showWarning(error.message || "Unable to save this guide module right now.");
					}).finally(function () {
						saveModuleBtn.disabled = false;
						saveModuleBtn.textContent = previousText;
					});
				});
			});

			moduleGrid.addEventListener("click", function (event) {
				var button = event.target.closest("[data-action]");
				if (!button) {
					if (event.target.closest("a")) {
						return;
					}
					var card = event.target.closest("[data-id]");
					if (card) {
						var viewId = card.getAttribute("data-id");
						var selectedModule = modules.find(function (item) {
							return item.id === viewId;
						});
						openViewModal(selectedModule || null);
					}
					return;
				}

				var action = button.getAttribute("data-action");
				var id = button.getAttribute("data-id");
				var current = modules.find(function (item) {
					return item.id === id;
				});

				if (!current) {
					return;
				}

				if (action === "edit") {
					openModuleForm("edit", current);
					return;
				}

				if (action === "delete") {
					openDeleteModal(id);
				}
			});

			cancelDelete.addEventListener("click", closeDeleteModal);
			deleteModalMask.addEventListener("click", closeDeleteModal);

			confirmDelete.addEventListener("click", function () {
				if (!deletingId) {
					return;
				}

				confirmDelete.disabled = true;
				var oldDeleteText = confirmDelete.textContent;
				confirmDelete.textContent = "Deleting...";

				syncModules("delete", { id: deletingId }).then(function (response) {
					modules = normalizeModules(response.modules || []);
					closeDeleteModal();
					renderModules();
					showSuccess(response.message || "Guide module deleted successfully.");
				}).catch(function (error) {
					showWarning(error.message || "Unable to delete this guide module right now.");
				}).finally(function () {
					confirmDelete.disabled = false;
					confirmDelete.textContent = oldDeleteText;
				});
			});

			searchInput.addEventListener("input", renderModules);
			categoryFilter.addEventListener("change", renderModules);

			closeViewModuleBtn.addEventListener("click", closeViewModal);
			viewModuleMask.addEventListener("click", closeViewModal);

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

			document.getElementById("goDashboardBtn").addEventListener("click", function () {
				window.location.href = "./admin_dashboard.php";
			});

			document.getElementById("goUsersBtn").addEventListener("click", function () {
				window.location.href = "./user_management.php";
			});

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
					closeModuleForm();
					closeViewModal();
					closeDeleteModal();
					closeLogout();
				}
			});

			setContentMode("");
			renderModules();
		})();
	</script>
</body>
</html>
