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

$farmers = [];

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

	$selectColumns = [
		"users_id",
		"name",
		"username",
		"address",
		"date_created"
	];

	if (isset($availableColumns["email"])) {
		$selectColumns[] = "email";
	}

	if (isset($availableColumns["phone"])) {
		$selectColumns[] = "phone";
	}

	if (isset($availableColumns["status"])) {
		$selectColumns[] = "status";
	}

	if (isset($availableColumns["last_login_date"])) {
		$selectColumns[] = "last_login_date";
	}

	$sql = "SELECT " . implode(", ", $selectColumns) . " FROM users WHERE LOWER(TRIM(role)) = 'farmer' ORDER BY users_id ASC";
	$result = $conn->query($sql);

	while ($row = $result->fetch_assoc()) {
		$statusRaw = strtolower(trim((string) ($row["status"] ?? "active")));
		$isMarkedInactive = in_array($statusRaw, ["inactive", "disabled", "deactivated", "suspended", "blocked", "archived"], true);

		$lastLoginValue = trim((string) ($row["last_login_date"] ?? ""));
		$referenceDate = $lastLoginValue !== "" ? $lastLoginValue : trim((string) ($row["date_created"] ?? ""));
		$isInactiveByLoginGap = false;

		if ($referenceDate !== "") {
			$referenceTimestamp = strtotime($referenceDate);
			if ($referenceTimestamp !== false) {
				$inactiveThresholdTimestamp = time() - (7 * 24 * 60 * 60);
				$isInactiveByLoginGap = $referenceTimestamp <= $inactiveThresholdTimestamp;
			}
		}

		$isInactive = $isMarkedInactive || $isInactiveByLoginGap;

		$farmers[] = [
			"id" => (string) ($row["users_id"] ?? ""),
			"name" => trim((string) ($row["name"] ?? "")),
			"address" => trim((string) ($row["address"] ?? "")),
			"username" => trim((string) ($row["username"] ?? "")),
			"status" => $isInactive ? "inactive" : "active",
			"registeredDate" => trim((string) ($row["date_created"] ?? "")),
			"email" => trim((string) ($row["email"] ?? "")),
			"lastLoginDate" => trim((string) ($row["last_login_date"] ?? ""))
		];
	}

	$conn->close();
} catch (Throwable $e) {
	$farmers = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>User Management | AgriCorn Admin</title>
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
			background: linear-gradient(135deg, rgba(127, 182, 133, 0.06), rgba(250, 253, 247, 1), rgba(255, 229, 153, 0.1));
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
			padding: 12px;
		}

		.page-stack {
			display: grid;
			gap: 12px;
		}

		.title-row {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 8px;
			flex-wrap: wrap;
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

		.status-row {
			display: inline-flex;
			gap: 12px;
			flex-wrap: wrap;
			align-items: center;
		}

		.status-pill {
			border: 0;
			border-radius: 14px;
			font-size: 0.9rem;
			padding: 8px 12px;
			font-weight: 700;
			display: inline-flex;
			flex-direction: row;
			align-items: center;
			gap: 8px;
			background: #fff;
			box-shadow: 0 6px 18px rgba(24,39,20,0.06);
			transition: transform .12s ease, box-shadow .12s ease;
		}

		.status-pill:hover {
			transform: translateY(-3px);
			box-shadow: 0 10px 22px rgba(24,39,20,0.08);
		}

		.status-pill svg {
			display: block;
			width: 18px;
			height: 18px;
			opacity: 0.95;
		}

		.status-pill.active {
			color: #ffffff;
			background: linear-gradient(180deg, var(--primary), #5fa06a);
		}

		.status-pill.inactive {
			color: #1f2937;
			background: linear-gradient(180deg, #eef2f7, #f8fafc);
		}

		.count-label {
			display: inline-flex;
			align-items: baseline;
			gap: 6px;
			white-space: nowrap;
		}

		.status-pill strong {
			display: inline-block;
			font-size: 1.15rem;
			line-height: 1;
		}

		.count-text {
			display: inline-block;
			font-size: 0.72rem;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			color: rgba(255,255,255,0.92);
		}

		.status-pill.inactive .count-text {
			color: var(--muted-foreground);
		}

		.panel {
			background: #fff;
			border: 0;
			border-radius: 14px;
			box-shadow: var(--shadow-lg);
			overflow: hidden;
		}

		.panel.pad {
			padding: 12px;
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

		.search-input {
			width: 100%;
			height: 40px;
			border: 1px solid rgba(127, 182, 133, 0.26);
			border-radius: 8px;
			padding: 0 12px 0 36px;
			font-size: 0.9rem;
			outline: none;
			transition: border-color 0.12s ease, box-shadow 0.12s ease;
		}

		.search-input:focus {
			border-color: rgba(127, 182, 133, 0.75);
			box-shadow: 0 0 0 4px rgba(127, 182, 133, 0.15);
		}

		.section-head {
			padding: 12px 12px 10px;
			border-bottom: 1px solid rgba(127, 182, 133, 0.2);
			display: flex;
			flex-direction: column;
			align-items: center;
			justify-content: center;
		}

		.section-title {
			margin: 0;
			font-size: 1.02rem;
			font-weight: 700;
			display: inline-flex;
			align-items: center;
			gap: 8px;
			color: #2c3e2e;
			justify-content: center;
		}

		.section-title svg {
			width: 18px;
			height: 18px;
			stroke: currentColor;
			fill: none;
			stroke-width: 2;
			stroke-linecap: round;
			stroke-linejoin: round;
		}

		.section-title.active {
			color: #16a34a;
		}

		.section-title.inactive {
			color: #dc2626;
		}

		.section-sub {
			margin: 6px 0 0;
			font-size: 0.84rem;
			color: var(--muted-foreground);
			text-align: center;
		}

		.table-wrap {
			overflow-x: auto;
			padding: 0 12px;
		}

		table {
			width: calc(100% - 24px);
			max-width: 100%;
			border-collapse: collapse;
			min-width: 520px;
			table-layout: fixed;
			margin: 0 auto;
		}

		th {
			text-align: left;
			padding: 6px 8px;
			font-size: 0.83rem;
			font-weight: 700;
			border-bottom: 1px solid rgba(127, 182, 133, 0.12);
			background: linear-gradient(90deg, rgba(127,182,133,0.04), rgba(250,253,247,0));
			color: #2c3e2e;
		}

		/* center table headers and cells to reduce left/right bias */
		th, td {
			text-align: center;
		}

		/* center name and action columns as requested */
		.name-cell {
			text-align: center;
		}

		/* ensure last column centered (override earlier right-align) */
		th:last-child, td:last-child {
			text-align: center;
		}

		/* column width hints for denser layout */
		/* tighter column width distribution */
		th:first-child, td:first-child { width: 18%; }
		th:nth-child(2), td:nth-child(2) { width: 44%; }
		th:nth-child(3), td:nth-child(3) { width: 18%; }
		th:last-child, td:last-child { width: 20%; }

		th:last-child,
		td:last-child {
			text-align: center;
		}

		td {
			padding: 6px 8px;
			border-bottom: 1px solid rgba(127, 182, 133, 0.12);
			font-size: 0.9rem;
			color: #2c3e2e;
			vertical-align: middle;
		}

		tbody tr:hover {
			background: rgba(243, 248, 239, 0.7);
		}

		.name-cell {
			font-weight: 600;
		}

		.address-cell {
			color: var(--muted-foreground);
			max-width: 320px;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		.action-btn {
			border: 0;
			background: transparent;
			color: #2c3e2e;
			border-radius: 10px;
			padding: 6px 10px;
			display: inline-flex;
			align-items: center;
			gap: 8px;
			font-size: 0.92rem;
			font-weight: 700;
			transition: background 0.12s ease, transform 0.12s ease;
		}

		.action-btn:hover {
			background: rgba(127, 182, 133, 0.12);
			transform: translateY(-1px);
		}

		.action-btn.btn-primary {
			background: linear-gradient(180deg, rgba(127,182,133,0.98), rgba(106,169,111,0.98));
			color: var(--primary-foreground);
			padding: 6px 12px;
			border-radius: 10px;
			box-shadow: 0 3px 8px rgba(34,58,39,0.06);
			font-weight: 600;
			border: 1px solid rgba(255,255,255,0.06);
		}

		.action-btn.btn-primary:hover {
			background: linear-gradient(180deg, rgba(106,169,111,0.98), rgba(92,150,98,0.98));
			box-shadow: 0 4px 10px rgba(34,58,39,0.08);
		}

		.action-btn svg {
			width: 15px;
			height: 15px;
			stroke: currentColor;
			fill: none;
			stroke-width: 2;
			stroke-linecap: round;
			stroke-linejoin: round;
		}

		.empty-row {
			text-align: center;
			padding: 28px 16px;
			color: var(--muted-foreground);
			font-size: 0.9rem;
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
			width: min(92vw, 680px);
			background: linear-gradient(180deg, #ffffff, #f9fcf6);
			border-radius: 16px;
			box-shadow: 0 18px 40px rgba(34, 58, 39, 0.18);
			z-index: 91;
			padding: 20px;
			opacity: 0;
			pointer-events: none;
			transition: all 0.2s ease;
		}

		.modal-box.show {
			opacity: 1;
			pointer-events: auto;
			transform: translate(-50%, -50%) scale(1);
		}

		.modal-title {
			margin: 0;
			font-size: 1.25rem;
			font-weight: 700;
			color: #2c3e2e;
		}

		.modal-sub {
			margin: 5px 0 0;
			color: var(--muted-foreground);
			font-size: 0.9rem;
		}

		.info-grid {
			margin-top: 16px;
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 12px;
		}

		.info-item {
			background: #ffffff;
			border: 1px solid rgba(127, 182, 133, 0.18);
			border-radius: 12px;
			padding: 12px 14px;
			box-shadow: 0 6px 16px rgba(34, 58, 39, 0.06);
		}

		.info-item.full {
			grid-column: span 2;
		}

		.info-label {
			margin: 0;
			font-size: 0.78rem;
			font-weight: 700;
			color: #6b7c6e;
			text-transform: none;
			letter-spacing: 0.02em;
		}

		.info-value {
			margin: 6px 0 0;
			font-size: 0.98rem;
			font-weight: 600;
			color: #2c3e2e;
		}

		.modal-actions {
			margin-top: 14px;
			display: flex;
			justify-content: flex-end;
			gap: 8px;
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
		}

		@media (max-width: 700px) {
			.status-row {
				width: 100%;
			}

			.status-pill {
				flex: 1;
				justify-content: center;
			}

			.info-grid {
				grid-template-columns: minmax(0, 1fr);
			}

			.info-item.full {
				grid-column: span 1;
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
				<button class="nav-btn active" type="button">
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
				<div class="page-stack">
					<section class="title-row">
						<div>
							<h2>User Management</h2>
							<p>View registered farmers and their accounts</p>
						</div>

						<div class="status-row">
							<span class="status-pill active" id="activeCountPill">
								<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="currentColor" opacity="0.15"></circle><path d="m8 12 2.4 2.4L16 8.8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>
								<span class="count-label" id="activeCountLabel"><strong>4</strong><span class="count-text">Active</span></span>
							</span>
							<span class="status-pill inactive" id="inactiveCountPill">
								<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="currentColor" opacity="0.15"></circle><path d="M8 8l8 8M16 8l-8 8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg>
								<span class="count-label" id="inactiveCountLabel"><strong>1</strong><span class="count-text">Inactive</span></span>
							</span>
						</div>
					</section>

					<section class="panel pad">
						<div class="search-wrap">
							<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
							<input id="searchInput" class="search-input" type="text" placeholder="Search by name, username, or address...">
						</div>
					</section>

					<section class="panel">
						<div class="section-head">
							<h3 class="section-title active">
								<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="m8 12 2.4 2.4L16 8.8"></path></svg>
								Active Farmers
							</h3>
							<p class="section-sub" id="activeSub">Currently active farmer accounts (4)</p>
						</div>
						<div class="table-wrap">
							<table aria-label="Active farmers table">
								<thead>
									<tr>
										<th>Name</th>
										<th>Address</th>
										<th>Username</th>
										<th>Actions</th>
									</tr>
								</thead>
								<tbody id="activeTableBody"></tbody>
							</table>
						</div>
					</section>

					<section class="panel">
						<div class="section-head">
							<h3 class="section-title inactive">
								<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M8 8l8 8M16 8l-8 8"></path></svg>
								Inactive Farmers
							</h3>
							<p class="section-sub" id="inactiveSub">Inactive farmer accounts (1)</p>
						</div>
						<div class="table-wrap">
							<table aria-label="Inactive farmers table">
								<thead>
									<tr>
										<th>Name</th>
										<th>Address</th>
										<th>Username</th>
										<th>Actions</th>
									</tr>
								</thead>
								<tbody id="inactiveTableBody"></tbody>
							</table>
						</div>
					</section>
				</div>
			</main>
		</div>
	</div>

	<div class="overlay" id="farmerInfoMask"></div>
	<div class="modal-box" id="farmerInfoModal" role="dialog" aria-modal="true" aria-labelledby="farmerInfoTitle">
		<h3 class="modal-title" id="farmerInfoTitle">Farmer Information</h3>
		<p class="modal-sub">Detailed information about the farmer account</p>

		<div class="info-grid">
			<div class="info-item">
				<p class="info-label">Name</p>
				<p class="info-value" id="infoName">-</p>
			</div>
			<div class="info-item">
				<p class="info-label">Username</p>
				<p class="info-value" id="infoUsername">-</p>
			</div>
			<div class="info-item">
				<p class="info-label">Address</p>
				<p class="info-value" id="infoAddress">-</p>
			</div>
			<div class="info-item">
				<p class="info-label">Last Login Date</p>
				<p class="info-value" id="infoLastLoginDate">-</p>
			</div>
			<div class="info-item full">
				<p class="info-label">Registration Date</p>
				<p class="info-value" id="infoDate">-</p>
			</div>
		</div>

		<div class="modal-actions">
			<button class="btn btn-outline-secondary" id="closeInfoBtn" type="button">Close</button>
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

	<script>
		(function () {
			var farmers = <?php echo json_encode($farmers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

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

			var searchInput = document.getElementById("searchInput");
			var activeCountLabel = document.getElementById("activeCountLabel");
			var inactiveCountLabel = document.getElementById("inactiveCountLabel");
			var activeSub = document.getElementById("activeSub");
			var inactiveSub = document.getElementById("inactiveSub");
			var activeTableBody = document.getElementById("activeTableBody");
			var inactiveTableBody = document.getElementById("inactiveTableBody");

			var farmerInfoMask = document.getElementById("farmerInfoMask");
			var farmerInfoModal = document.getElementById("farmerInfoModal");
			var closeInfoBtn = document.getElementById("closeInfoBtn");

			var infoName = document.getElementById("infoName");
			var infoUsername = document.getElementById("infoUsername");
			var infoAddress = document.getElementById("infoAddress");
			var infoLastLoginDate = document.getElementById("infoLastLoginDate");
			var infoDate = document.getElementById("infoDate");

			var logoutModal = document.getElementById("logoutModal");
			var logoutMask = document.getElementById("logoutModalMask");
			var cancelLogout = document.getElementById("cancelLogout");
			var confirmLogout = document.getElementById("confirmLogout");

			function formatDate(dateValue) {
				if (!dateValue) {
					return "-";
				}
				var parsed = new Date(String(dateValue).replace(" ", "T"));
				if (isNaN(parsed.getTime())) {
					return dateValue;
				}
				return parsed.toLocaleDateString("en-US", {
					year: "numeric",
					month: "long",
					day: "numeric"
				});
			}

			function formatDateTime(dateValue) {
				if (!dateValue) {
					return "-";
				}
				var parsed = new Date(String(dateValue).replace(" ", "T"));
				if (isNaN(parsed.getTime())) {
					return dateValue;
				}
				return parsed.toLocaleString("en-US", {
					year: "numeric",
					month: "long",
					day: "numeric",
					hour: "numeric",
					minute: "2-digit"
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

			function renderRows(rows, targetBody, emptyText) {
				if (!rows.length) {
					targetBody.innerHTML = '<tr><td colspan="4" class="empty-row">' + escapeHtml(emptyText) + "</td></tr>";
					return;
				}

				var html = "";
				for (var i = 0; i < rows.length; i += 1) {
					var row = rows[i];
					html +=
						'<tr>' +
							'<td class="name-cell">' + escapeHtml(row.name) + '</td>' +
							'<td class="address-cell">' + escapeHtml(row.address) + '</td>' +
							'<td>' + escapeHtml(row.username) + '</td>' +
							'<td>' +
								'<button class="action-btn btn-primary" type="button" data-view-id="' + escapeHtml(row.id) + '">' +
									'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z"></path><circle cx="12" cy="12" r="3"></circle></svg>' +
									'View' +
								'</button>' +
							'</td>' +
						'</tr>';
				}

				targetBody.innerHTML = html;
			}

			function filterFarmers(query) {
				var value = query.toLowerCase();
				return farmers.filter(function (item) {
					return (
						String(item.name || "").toLowerCase().indexOf(value) !== -1 ||
						String(item.username || "").toLowerCase().indexOf(value) !== -1 ||
						String(item.address || "").toLowerCase().indexOf(value) !== -1
					);
				});
			}

			function renderPage() {
				var query = searchInput.value.trim();
				var filtered = filterFarmers(query);

				var activeRows = filtered.filter(function (item) {
					return item.status === "active";
				});
				var inactiveRows = filtered.filter(function (item) {
					return item.status === "inactive";
				});

				activeCountLabel.innerHTML = '<strong>' + activeRows.length + '</strong><span class="count-text">Active</span>';
				inactiveCountLabel.innerHTML = '<strong>' + inactiveRows.length + '</strong><span class="count-text">Inactive</span>';
				activeCountLabel.setAttribute("aria-label", activeRows.length + " active accounts");
				inactiveCountLabel.setAttribute("aria-label", inactiveRows.length + " inactive accounts");
				activeSub.textContent = "Currently active farmer accounts (" + activeRows.length + ")";
				inactiveSub.textContent = "Inactive farmer accounts (" + inactiveRows.length + ")";

				renderRows(activeRows, activeTableBody, "No active farmers found matching your search");
				renderRows(inactiveRows, inactiveTableBody, "No inactive farmers found matching your search");
			}

			function openFarmerInfo(farmer) {
				if (!farmer) {
					return;
				}

				infoName.textContent = farmer.name;
				infoUsername.textContent = farmer.username;
				infoAddress.textContent = farmer.address;
				infoLastLoginDate.textContent = formatDateTime(farmer.lastLoginDate);
				infoDate.textContent = formatDate(farmer.registeredDate);

				farmerInfoMask.classList.add("show");
				farmerInfoModal.classList.add("show");
			}

			function closeFarmerInfo() {
				farmerInfoMask.classList.remove("show");
				farmerInfoModal.classList.remove("show");
			}

			function openLogout() {
				logoutMask.classList.add("show");
				logoutModal.classList.add("show");
			}

			function closeLogout() {
				logoutMask.classList.remove("show");
				logoutModal.classList.remove("show");
			}

			searchInput.addEventListener("input", renderPage);

			document.addEventListener("click", function (event) {
				var viewBtn = event.target.closest("[data-view-id]");
				if (!viewBtn) {
					return;
				}

				var id = viewBtn.getAttribute("data-view-id");
				var farmer = farmers.find(function (item) {
					return item.id === id;
				});

				openFarmerInfo(farmer);
			});

			closeInfoBtn.addEventListener("click", closeFarmerInfo);
			farmerInfoMask.addEventListener("click", closeFarmerInfo);

			document.getElementById("goDashboardBtn").addEventListener("click", function () {
				window.location.href = "./admin_dashboard.php";
			});

			document.getElementById("goGuidesBtn").addEventListener("click", function () {
				window.location.href = "./guide_module.php";
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
					closeFarmerInfo();
					closeLogout();
				}
			});

			renderPage();
		})();
	</script>
</body>
</html>
