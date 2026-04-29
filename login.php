<?php
session_start();

$activeTab = "login";
$loginError = "";
$signupError = "";
$signupSuccess = false;

$loginUsernameValue = "";
$signupNameValue = "";
$signupAddressValue = "";
$signupUsernameValue = "";

$signupAddressOptions = [
	"Bagong Silang, Calatagan, Batangas",
	"Baha, Calatagan, Batangas",
	"Balibago, Calatagan, Batangas",
	"Balitoc, Calatagan, Batangas",
	"Barangay 1, Calatagan, Batangas",
	"Barangay 2, Calatagan, Batangas",
	"Barangay 3, Calatagan, Batangas",
	"Barangay 4, Calatagan, Batangas",
	"Biga, Calatagan, Batangas",
	"Bucal, Calatagan, Batangas",
	"Carlosa, Calatagan, Batangas",
	"Carretunan, Calatagan, Batangas",
	"Encarnacion, Calatagan, Batangas",
	"Gulod, Calatagan, Batangas",
	"Hukay, Calatagan, Batangas",
	"Lucsuhin, Calatagan, Batangas",
	"Luya, Calatagan, Batangas",
	"Paraiso, Calatagan, Batangas",
	"Quilitisan, Calatagan, Batangas",
	"Real, Calatagan, Batangas",
	"Sambungan, Calatagan, Batangas",
	"Santa Ana, Calatagan, Batangas",
	"Talibayog, Calatagan, Batangas",
	"Talisay, Calatagan, Batangas",
	"Tanagan, Calatagan, Batangas"
];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["login_submit"])) {
	$username = trim($_POST["username"] ?? "");
	$password = $_POST["password"] ?? "";
	$loginUsernameValue = $username;

	if ($username === "" || $password === "") {
		$loginError = "Please enter both username and password.";
	} else {
		try {
			require_once __DIR__ . "/data/db_connect.php";

			$stmt = $conn->prepare("SELECT users_id, role, name, username, password FROM users WHERE username = ? LIMIT 1");
			$stmt->bind_param("s", $username);
			$stmt->execute();
			$result = $stmt->get_result();
			$user = $result->fetch_assoc();

			if ($user) {
				$storedPassword = (string) $user["password"];
				$isValidPassword = password_verify($password, $storedPassword) || hash_equals($storedPassword, $password);

				if ($isValidPassword) {
					try {
						$loginUpdateStmt = $conn->prepare("UPDATE users SET last_login_date = NOW() WHERE users_id = ? LIMIT 1");
						$userIdValue = (int) $user["users_id"];
						$loginUpdateStmt->bind_param("i", $userIdValue);
						$loginUpdateStmt->execute();
						$loginUpdateStmt->close();
					} catch (Throwable $ignore) {
						// Keep login successful even if last_login_date update fails.
					}

					$_SESSION["users_id"] = (int) $user["users_id"];
					$_SESSION["role"] = $user["role"];
					$_SESSION["name"] = $user["name"];
					$_SESSION["username"] = $user["username"];

					if ((int) $user["users_id"] === 1) {
						header("Location: Admin Dashboard/admin_dashboard.php");
						exit;
					}

					header("Location: Farmers Dashboard/farmer_dashboard.php");
					exit;
				}
			}

			$loginError = "Invalid username or password.";
			$stmt->close();
			$conn->close();
		} catch (Throwable $e) {
			$loginError = "Database connection error. Please try again.";
		}
	}
} elseif ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["signup_submit"])) {
	$activeTab = "signup";
	$signupNameValue = trim($_POST["name"] ?? "");
	$signupAddressValue = trim($_POST["address"] ?? "");
	$signupUsernameValue = trim($_POST["signup_username"] ?? "");
	$signupPassword = $_POST["signup_password"] ?? "";
	$signupConfirmPassword = $_POST["confirm_password"] ?? "";
	if (!in_array($signupAddressValue, $signupAddressOptions, true)) {
		$signupAddressValue = "";
	}

	if ($signupNameValue === "" || $signupAddressValue === "" || $signupUsernameValue === "" || $signupPassword === "" || $signupConfirmPassword === "") {
		$signupError = "Please complete all sign up fields.";
	} elseif (strlen($signupPassword) < 6) {
		$signupError = "Password must be at least 6 characters.";
	} elseif ($signupPassword !== $signupConfirmPassword) {
		$signupError = "Passwords do not match.";
	} else {
		try {
			require_once __DIR__ . "/data/db_connect.php";

			$checkStmt = $conn->prepare("SELECT users_id FROM users WHERE username = ? LIMIT 1");
			$checkStmt->bind_param("s", $signupUsernameValue);
			$checkStmt->execute();
			$existingUser = $checkStmt->get_result()->fetch_assoc();
			$checkStmt->close();

			if ($existingUser) {
				$signupError = "Username already exists. Please choose another username.";
			} else {
				$role = "farmer";
				$dateCreated = date("Y-m-d");
				$hashedPassword = password_hash($signupPassword, PASSWORD_DEFAULT);

				$insertStmt = $conn->prepare("INSERT INTO users (role, name, username, address, password, confirm_password, date_created) VALUES (?, ?, ?, ?, ?, ?, ?)");
				$insertStmt->bind_param(
					"sssssss",
					$role,
					$signupNameValue,
					$signupUsernameValue,
					$signupAddressValue,
					$hashedPassword,
					$hashedPassword,
					$dateCreated
				);
				$insertStmt->execute();
				$insertStmt->close();

				$signupSuccess = true;
				$activeTab = "login";
				$signupNameValue = "";
				$signupAddressValue = "";
				$signupUsernameValue = "";
			}

			$conn->close();
		} catch (Throwable $e) {
			$signupError = "Unable to create account right now. Please try again.";
		}
	}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<link rel="stylesheet" href="bootstrap5/css/bootstrap.min.css">
	<title>AgriCorn Login</title>
	<style>
		:root {
			--background: #fafdf7;
			--foreground: #2c3e2e;
			--card: #ffffff;
			--card-foreground: #2c3e2e;
			--primary: #7fb685;
			--primary-foreground: #ffffff;
			--secondary: #ffe599;
			--secondary-foreground: #2c3e2e;
			--muted: #e8f3ea;
			--muted-foreground: #6b7c6e;
			--accent: #fff9e6;
			--destructive: #d4183d;
			--border: rgba(127, 182, 133, 0.2);
			--input-background: #f5faf6;
			--ring: rgba(127, 182, 133, 0.28);
			--radius: 12px;
			--shadow-xl: 0 20px 42px rgba(34, 58, 39, 0.2);
			--shadow-2xl: 0 24px 58px rgba(30, 54, 35, 0.25);
		}

		* {
			box-sizing: border-box;
		}

		html,
		body {
			height: 100%;
			margin: 0;
			min-height: 100%;
			font-family: "Inter", "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
			color: var(--foreground);
			background: var(--background);
		}

		html {
			overflow-y: auto;
			-ms-overflow-style: none;
			scrollbar-width: none;
		}

		html::-webkit-scrollbar {
			width: 0;
			height: 0;
		}

		body {
			min-height: 100vh;
			display: flex;
			align-items: flex-start;
			justify-content: center;
			padding: clamp(16px, 2.4vh, 24px) 16px 22px;
			overflow-y: auto;
			overflow-x: hidden;
			position: relative;
			-ms-overflow-style: none;
			scrollbar-width: none;
		}

		body::-webkit-scrollbar {
			width: 0;
			height: 0;
		}

		.bg-layer {
			position: fixed;
			inset: 0;
			pointer-events: none;
			z-index: 0;
			background: linear-gradient(135deg, rgba(127, 182, 133, 0.2) 0%, rgba(250, 253, 247, 1) 45%, rgba(255, 229, 153, 0.2) 100%);
		}

		.orb {
			position: fixed;
			border-radius: 50%;
			filter: blur(56px);
			pointer-events: none;
		}

		.orb-1 {
			top: -120px;
			right: -120px;
			width: 600px;
			height: 600px;
			background: linear-gradient(135deg, rgba(127, 182, 133, 0.3), rgba(134, 239, 172, 0.18));
		}

		.orb-2 {
			bottom: -120px;
			left: -120px;
			width: 500px;
			height: 500px;
			background: linear-gradient(45deg, rgba(255, 229, 153, 0.34), rgba(250, 204, 21, 0.15));
		}

		.orb-3 {
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%);
			width: 400px;
			height: 400px;
			background: linear-gradient(90deg, rgba(134, 239, 172, 0.1), rgba(253, 224, 71, 0.1));
		}

		.back-button {
			position: fixed;
			top: 18px;
			left: 18px;
			z-index: 30;
			width: 42px;
			height: 42px;
			border: none;
			border-radius: 999px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			color: var(--muted-foreground);
			background: rgba(255, 255, 255, 0.78);
			box-shadow: 0 10px 26px rgba(44, 62, 46, 0.18);
			cursor: pointer;
			transition: all 0.25s ease;
			backdrop-filter: blur(6px);
		}

		.back-button:hover {
			color: var(--foreground);
			transform: translateY(-1px);
			background: linear-gradient(90deg, rgba(127, 182, 133, 0.22), rgba(255, 229, 153, 0.25));
			box-shadow: 0 14px 28px rgba(44, 62, 46, 0.2);
		}

		.auth-wrap {
			width: 100%;
			max-width: 430px;
			position: relative;
			z-index: 5;
		}

		.brand {
			text-align: center;
			margin-bottom: 26px;
		}

		.brand-icon {
			width: 100px;
			height: 100px;
			margin: 0 auto 16px;
			display: flex;
			align-items: center;
			justify-content: center;
			transition: transform 0.3s ease;
		}
		.brand-icon:hover {
			transform: scale(1.05);
		}

		.brand-icon svg {
			width: 45px;
			height: 45px;
			fill: #ffffff;
		}

		.brand h1 {
			margin: 0 0 8px;
			font-size: clamp(28px, 4vw, 38px);
			font-weight: 700;
			line-height: 1.15;
		}

		.brand-agri {
			color: var(--primary);
			font-weight: 700;
		}
		.brand-corn {
			color: #f6c941;
			font-weight: 700;
		}

		.brand p {
			margin: 0;
			color: var(--muted-foreground);
			font-size: 14px;
		}

		.card-shell {
			position: relative;
		}

		.card-glow {
			position: absolute;
			inset: -14px;
			border-radius: 28px;
			background: linear-gradient(90deg, rgba(127, 182, 133, 0.24), rgba(96, 165, 121, 0.12), rgba(255, 229, 153, 0.22));
			filter: blur(20px);
			opacity: 0.65;
			pointer-events: none;
		}

		.card {
			position: relative;
			border: 1px solid var(--border);
			border-radius: 16px;
			background: linear-gradient(145deg, rgba(255, 255, 255, 0.95), rgba(255, 255, 255, 0.9));
			box-shadow: var(--shadow-2xl);
			backdrop-filter: blur(6px);
			overflow: hidden;
		}

		.tab-strip {
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 6px;
			margin: 14px;
			padding: 4px;
			border: 1px solid rgba(127, 182, 133, 0.25);
			border-radius: 12px;
			background: linear-gradient(90deg, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0.95));
		}

		.tab-btn {
			height: 40px;
			border: none;
			border-radius: 10px;
			color: var(--muted-foreground);
			font-weight: 600;
			font-size: 14px;
			cursor: pointer;
			background: transparent;
			transition: all 0.25s ease;
		}

		.tab-btn.active {
			color: #ffffff;
			background: linear-gradient(90deg, var(--primary), #2f9950);
			box-shadow: 0 10px 18px rgba(47, 153, 80, 0.35);
		}

		.panel {
			display: none;
			padding: 20px 22px 24px;
		}

		.panel.active {
			display: block;
			animation: panelIn 0.26s ease;
		}

		.panel-title {
			margin: 2px 0 2px;
			font-size: 30px;
			font-weight: 700;
			line-height: 1.2;
			text-align: center;
			background: linear-gradient(90deg, var(--primary), var(--secondary));
			-webkit-background-clip: text;
			background-clip: text;
			color: transparent;
		}

		.panel-subtitle {
			margin: 0 0 18px;
			text-align: center;
			color: var(--muted-foreground);
			font-size: 14px;
		}

		form {
			margin: 0;
		}

		.form-stack {
			display: grid;
			gap: 14px;
		}

		.field {
			display: grid;
			gap: 7px;
		}

		.field label {
			font-size: 14px;
			font-weight: 600;
			color: var(--foreground);
		}

		.field-wrap {
			position: relative;
		}

		.select-wrap {
			position: relative;
		}

		.field-wrap svg {
			position: absolute;
			left: 11px;
			top: 50%;
			transform: translateY(-50%);
			width: 18px;
			height: 18px;
			fill: var(--primary);
			opacity: 0.95;
			pointer-events: none;
		}

		.input {
			width: 100%;
			height: 46px;
			border: 1px solid rgba(127, 182, 133, 0.24);
			border-radius: 10px;
			background: rgba(245, 250, 246, 0.75);
			color: var(--foreground);
			outline: none;
			padding: 0 13px 0 38px;
			font-size: 14px;
			transition: all 0.2s ease;
		}

		.select-wrap .input {
			appearance: none;
			-webkit-appearance: none;
			-moz-appearance: none;
			padding-right: 42px;
			cursor: pointer;
		}

		.select-wrap::after {
			content: "";
			position: absolute;
			right: 14px;
			top: 50%;
			transform: translateY(-45%);
			width: 10px;
			height: 10px;
			border-right: 2px solid var(--primary);
			border-bottom: 2px solid var(--primary);
			pointer-events: none;
			transform-origin: center;
			rotate: 45deg;
			opacity: 0.9;
		}

		.select-wrap:focus-within::after {
			border-color: #187f3a;
		}

		.select-wrap .input option {
			background: #ffffff;
			color: var(--foreground);
		}

		.select-wrap .input option:disabled,
		.select-wrap .input option[value=""] {
			color: #8ea093;
		}

		.input::placeholder {
			color: #93a797;
		}

		.input:focus {
			border-color: rgba(127, 182, 133, 0.75);
			box-shadow: 0 0 0 3px var(--ring);
			background: #ffffff;
		}

		.input.input-error {
			border-color: rgba(212, 24, 61, 0.65);
			box-shadow: 0 0 0 3px rgba(212, 24, 61, 0.15);
		}

		.strength {
			display: none;
			gap: 7px;
			margin-top: 2px;
		}

		.strength.visible {
			display: grid;
		}

		.strength-row {
			display: flex;
			justify-content: space-between;
			align-items: center;
			font-size: 12px;
			color: var(--muted-foreground);
		}

		.strength-label {
			font-weight: 700;
		}

		.bar {
			width: 100%;
			height: 6px;
			background: #dfe7e1;
			border-radius: 100px;
			overflow: hidden;
		}

		.bar-fill {
			width: 0;
			height: 100%;
			background: #ef4444;
			transition: width 0.25s ease, background-color 0.25s ease;
		}

		.error-text {
			margin: 0;
			font-size: 12px;
			color: var(--destructive);
			display: none;
		}

		.error-text.visible {
			display: block;
		}

		.main-btn {
			width: 100%;
			height: 48px;
			border: none;
			border-radius: 10px;
			color: #ffffff;
			font-weight: 700;
			font-size: 15px;
			cursor: pointer;
			margin-top: 2px;
			background: linear-gradient(90deg, var(--primary), #1ea54a, #187f3a);
			box-shadow: 0 16px 26px rgba(31, 132, 59, 0.32);
			transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
		}

		.main-btn:hover {
			transform: translateY(-1px);
			box-shadow: 0 20px 34px rgba(28, 119, 53, 0.36);
		}

		.main-btn:disabled {
			opacity: 0.6;
			cursor: not-allowed;
			transform: none;
		}

		.password-toggle {
			position: absolute;
			right: 12px;
			top: 50%;
			transform: translateY(-50%);
			background: none;
			border: none;
			padding: 0;
			cursor: pointer;
			color: var(--primary);
			display: flex;
			align-items: center;
			justify-content: center;
			opacity: 0.7;
			transition: opacity 0.2s;
			z-index: 10;
		}
		.password-toggle:hover {
			opacity: 1;
		}
		.password-toggle svg {
			width: 18px;
			height: 18px;
			position: static !important;
			transform: none !important;
			fill: none !important;
			stroke: currentColor !important;
		}

		.divider {
			position: relative;
			margin: 4px 0;
			text-align: center;
			color: var(--muted-foreground);
			font-size: 11px;
			text-transform: uppercase;
			letter-spacing: 0.06em;
		}

		.divider::before {
			content: "";
			position: absolute;
			top: 50%;
			left: 0;
			right: 0;
			height: 1px;
			background: rgba(127, 182, 133, 0.3);
			transform: translateY(-50%);
		}

		.divider span {
			position: relative;
			padding: 0 10px;
			background: var(--card);
		}

		.switch-text {
			margin: 0;
			text-align: center;
			color: var(--muted-foreground);
			font-size: 14px;
		}

		.switch-text button {
			border: none;
			background: none;
			padding: 0;
			font: inherit;
			font-weight: 700;
			cursor: pointer;
			background: linear-gradient(90deg, var(--primary), var(--secondary));
			-webkit-background-clip: text;
			background-clip: text;
			color: transparent;
		}

		.terms {
			margin: 18px 0 0;
			text-align: center;
			color: var(--muted-foreground);
			font-size: 13px;
		}

		.modal {
			position: fixed;
			inset: 0;
			display: none;
			align-items: center;
			justify-content: center;
			z-index: 55;
			padding: 18px;
		}

		.modal.open {
			display: flex;
		}

		.modal-overlay {
			position: absolute;
			inset: 0;
			background: rgba(24, 33, 26, 0.36);
			backdrop-filter: blur(4px);
		}

		.modal-card {
			position: relative;
			width: 100%;
			max-width: 420px;
			border: 1px solid var(--border);
			border-radius: 16px;
			background: rgba(255, 255, 255, 0.92);
			box-shadow: var(--shadow-2xl);
			padding: 24px 22px;
			text-align: center;
			animation: panelIn 0.24s ease;
		}

		.modal-icon {
			width: 66px;
			height: 66px;
			margin: 0 auto 12px;
			border-radius: 999px;
			display: flex;
			align-items: center;
			justify-content: center;
			background: linear-gradient(145deg, #24b556, #19883e);
			box-shadow: 0 14px 30px rgba(19, 128, 54, 0.32);
		}

		.modal-icon svg {
			width: 37px;
			height: 37px;
			fill: #ffffff;
		}

		.modal h2 {
			margin: 0 0 8px;
			font-size: 28px;
			line-height: 1.2;
		}

		.modal p {
			margin: 0 0 20px;
			color: var(--muted-foreground);
			line-height: 1.45;
		}

		.icon-danger {
			fill: var(--destructive) !important;
		}

		@keyframes panelIn {
			from {
				opacity: 0;
				transform: translateY(8px) scale(0.99);
			}
			to {
				opacity: 1;
				transform: translateY(0) scale(1);
			}
		}

		@media (max-width: 540px) {
			body {
				padding: 12px;
			}

			.back-button {
				top: 12px;
				left: 12px;
			}

			.panel {
				padding: 16px 16px 20px;
			}

			.brand-icon {
				width: 72px;
				height: 72px;
			}

			.brand-icon svg {
				width: 39px;
				height: 39px;
			}
		}

		@media (min-width: 992px) {
			.auth-wrap {
				margin-top: 8px;
			}
		}
	</style>
</head>
<body class="d-flex justify-content-center">
	<div class="bg-layer"></div>
	<div class="orb orb-1"></div>
	<div class="orb orb-2"></div>
	<div class="orb orb-3"></div>

	<button class="back-button btn p-0" id="backButton" aria-label="Back to home">&#8592;</button>

	<main class="auth-wrap container-fluid px-0 px-sm-2">
		<section class="brand text-center">
			<div class="brand-icon" aria-hidden="true">
				<img src="agricorn.png" alt="AgriCorn Logo" style="width: 100%; height: 100%; object-fit: contain;">
			</div>
			<h1>Welcome to <span class="brand-agri">Agri</span><span class="brand-corn">Corn</span></h1>
			<p>Your intelligent farming companion</p>
		</section>

		<section class="card-shell">
			<div class="card-glow"></div>

			<div class="card mx-auto">
				<div class="tab-strip" role="tablist" aria-label="Authentication tabs">
					<button class="tab-btn <?php echo $activeTab === "login" ? "active" : ""; ?>" id="tabLogin" data-target="panelLogin" role="tab" aria-selected="<?php echo $activeTab === "login" ? "true" : "false"; ?>">Login</button>
					<button class="tab-btn <?php echo $activeTab === "signup" ? "active" : ""; ?>" id="tabSignup" data-target="panelSignup" role="tab" aria-selected="<?php echo $activeTab === "signup" ? "true" : "false"; ?>">Sign Up</button>
				</div>

				<div class="panel <?php echo $activeTab === "login" ? "active" : ""; ?>" id="panelLogin" role="tabpanel" aria-labelledby="tabLogin">
					<?php if ($loginError !== "") : ?>
						<p class="error-text visible" style="text-align:center;"><?php echo htmlspecialchars($loginError); ?></p>
					<?php endif; ?>

					<form id="loginForm" class="form-stack" autocomplete="on" method="post" action="">
						<div class="field">
							<label for="loginUsername">Username</label>
							<div class="field-wrap">
								<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
									<path d="M12 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0 2c4.4 0 8 2.2 8 5v2H4v-2c0-2.8 3.6-5 8-5z"></path>
								</svg>
								<input class="input" id="loginUsername" name="username" type="text" placeholder="Enter your username" value="<?php echo htmlspecialchars($loginUsernameValue); ?>" required>
							</div>
						</div>

						<div class="field">
							<label for="loginPassword">Password</label>
							<div class="field-wrap">
								<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
									<path d="M17 8h-1V6a4 4 0 1 0-8 0v2H7a2 2 0 0 0-2 2v10h14V10a2 2 0 0 0-2-2zm-7-2a2 2 0 1 1 4 0v2h-4V6zm2 8a2 2 0 0 1 1 3.7V19h-2v-1.3a2 2 0 0 1 1-3.7z"></path>
								</svg>
								<input class="input" id="loginPassword" name="password" type="password" placeholder="Enter your password" required style="padding-right: 42px;">
								<button type="button" class="password-toggle" onclick="togglePasswordVisibility('loginPassword', this)">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
								</button>
							</div>
						</div>

						<button class="main-btn" type="submit" name="login_submit">Login</button>

						<div class="divider"><span>Or</span></div>

						<p class="switch-text">Don't have an account? <button type="button" id="toSignup">Sign up here</button></p>
					</form>
				</div>

				<div class="panel <?php echo $activeTab === "signup" ? "active" : ""; ?>" id="panelSignup" role="tabpanel" aria-labelledby="tabSignup">
					<h2 class="panel-title">Create Account</h2>
					<p class="panel-subtitle">Start your smart farming journey today</p>
					<?php if ($signupError !== "") : ?>
						<p class="error-text visible" style="text-align:center;"><?php echo htmlspecialchars($signupError); ?></p>
					<?php endif; ?>

					<form id="signupForm" class="form-stack" autocomplete="on" method="post" action="">
						<div class="field">
							<label for="signupName">Full Name</label>
							<div class="field-wrap">
								<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
									<path d="M12 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0 2c4.4 0 8 2.2 8 5v2H4v-2c0-2.8 3.6-5 8-5z"></path>
								</svg>
								<input class="input" id="signupName" name="name" type="text" placeholder="Enter your full name" value="<?php echo htmlspecialchars($signupNameValue); ?>" required>
							</div>
						</div>

						<div class="field">
							<label for="signupAddress">Address</label>
							<div class="field-wrap select-wrap">
								<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
									<path d="M12 2a7 7 0 0 1 7 7c0 4.6-7 13-7 13S5 13.6 5 9a7 7 0 0 1 7-7zm0 9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z"></path>
								</svg>
								<select class="input" id="signupAddress" name="address" required>
									<option value="">Select your barangay</option>
									<?php foreach ($signupAddressOptions as $signupAddressOption) { ?>
										<option value="<?php echo htmlspecialchars($signupAddressOption, ENT_QUOTES); ?>" <?php echo $signupAddressValue === $signupAddressOption ? "selected" : ""; ?>><?php echo htmlspecialchars($signupAddressOption); ?></option>
									<?php } ?>
								</select>
							</div>
						</div>

						<div class="field">
							<label for="signupUsername">Username</label>
							<div class="field-wrap">
								<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
									<path d="M12 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0 2c4.4 0 8 2.2 8 5v2H4v-2c0-2.8 3.6-5 8-5z"></path>
								</svg>
								<input class="input" id="signupUsername" name="signup_username" type="text" placeholder="Choose a username" value="<?php echo htmlspecialchars($signupUsernameValue); ?>" required>
							</div>
						</div>

						<div class="field">
							<label for="signupPassword">Password</label>
							<div class="field-wrap">
								<svg id="iconSignupPassword" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
									<path d="M17 8h-1V6a4 4 0 1 0-8 0v2H7a2 2 0 0 0-2 2v10h14V10a2 2 0 0 0-2-2zm-7-2a2 2 0 1 1 4 0v2h-4V6zm2 8a2 2 0 0 1 1 3.7V19h-2v-1.3a2 2 0 0 1 1-3.7z"></path>
								</svg>
								<input class="input" id="signupPassword" name="signup_password" type="password" placeholder="Create a password" minlength="6" required style="padding-right: 42px;">
								<button type="button" class="password-toggle" onclick="togglePasswordVisibility('signupPassword', this)">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
								</button>
							</div>
							<p class="error-text" id="passwordLengthError">Password must be at least 6 characters</p>

							<div class="strength" id="strengthBox">
								<div class="strength-row">
									<span>Password strength:</span>
									<span class="strength-label" id="strengthLabel"></span>
								</div>
								<div class="bar">
									<div class="bar-fill" id="strengthFill"></div>
								</div>
							</div>
						</div>

						<div class="field">
							<label for="signupConfirm">Confirm Password</label>
							<div class="field-wrap">
								<svg id="iconSignupConfirm" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
									<path d="M17 8h-1V6a4 4 0 1 0-8 0v2H7a2 2 0 0 0-2 2v10h14V10a2 2 0 0 0-2-2zm-7-2a2 2 0 1 1 4 0v2h-4V6zm2 8a2 2 0 0 1 1 3.7V19h-2v-1.3a2 2 0 0 1 1-3.7z"></path>
								</svg>
								<input class="input" id="signupConfirm" name="confirm_password" type="password" placeholder="Confirm your password" required style="padding-right: 42px;">
								<button type="button" class="password-toggle" onclick="togglePasswordVisibility('signupConfirm', this)">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
								</button>
							</div>
							<p class="error-text" id="passwordError">Passwords do not match</p>
						</div>

						<button class="main-btn" id="signupSubmit" type="submit" name="signup_submit">Create Account</button>

						<div class="divider"><span>Or</span></div>

						<p class="switch-text">Already have an account? <button type="button" id="toLogin">Login here</button></p>
					</form>
				</div>
			</div>

			<p class="terms">By continuing, you agree to AgriCorn's Terms of Service and Privacy Policy</p>
		</section>
	</main>

	<div class="modal" id="successModal" aria-hidden="true">
		<div class="modal-overlay" id="modalCloseOverlay"></div>
		<div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
			<div class="modal-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
					<path d="M9 16.2l-3.5-3.6L4 14.2l5 5 11-11-1.5-1.4z"></path>
				</svg>
			</div>
			<h2 id="modalTitle">Account Successfully Created!</h2>
			<p>Your AgriCorn account has been successfully created. You need to login first to go to dashboard.</p>
			<button class="main-btn" id="goToLogin">Go to Login</button>
		</div>
	</div>

	<script>
		function togglePasswordVisibility(inputId, btn) {
			var input = document.getElementById(inputId);
			var icon = btn.querySelector('svg');
			if (input.type === "password") {
				input.type = "text";
				btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
			} else {
				input.type = "password";
				btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
			}
		}

		(function () {
			var tabLogin = document.getElementById("tabLogin");
			var tabSignup = document.getElementById("tabSignup");
			var panelLogin = document.getElementById("panelLogin");
			var panelSignup = document.getElementById("panelSignup");
			var toSignup = document.getElementById("toSignup");
			var toLogin = document.getElementById("toLogin");

			var loginForm = document.getElementById("loginForm");
			var signupForm = document.getElementById("signupForm");
			var signupPassword = document.getElementById("signupPassword");
			var signupConfirm = document.getElementById("signupConfirm");
			var signupSubmit = document.getElementById("signupSubmit");
			var passwordLengthError = document.getElementById("passwordLengthError");
			var iconSignupPassword = document.getElementById("iconSignupPassword");
			var passwordError = document.getElementById("passwordError");
			var iconSignupConfirm = document.getElementById("iconSignupConfirm");

			var strengthBox = document.getElementById("strengthBox");
			var strengthLabel = document.getElementById("strengthLabel");
			var strengthFill = document.getElementById("strengthFill");

			var successModal = document.getElementById("successModal");
			var goToLogin = document.getElementById("goToLogin");
			var modalCloseOverlay = document.getElementById("modalCloseOverlay");
			var backButton = document.getElementById("backButton");
			var initialTab = "<?php echo $activeTab; ?>";
			var shouldOpenSignupSuccess = <?php echo $signupSuccess ? "true" : "false"; ?>;

			function setTab(which) {
				var isLogin = which === "login";

				tabLogin.classList.toggle("active", isLogin);
				tabSignup.classList.toggle("active", !isLogin);
				tabLogin.setAttribute("aria-selected", isLogin ? "true" : "false");
				tabSignup.setAttribute("aria-selected", isLogin ? "false" : "true");

				panelLogin.classList.toggle("active", isLogin);
				panelSignup.classList.toggle("active", !isLogin);
			}

			function getStrength(password) {
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

				if (points <= 70) {
					return { strength: points, label: "Medium", color: "#eab308" };
				}

				return { strength: points, label: "Strong", color: "#22c55e" };
			}

			function validateConfirmPassword() {
				var pass = signupPassword.value;
				var confirm = signupConfirm.value;
				var match = confirm === "" || pass === confirm;
				var hasMinLength = pass === "" || pass.length >= 6;
				var showMinLengthError = pass !== "" && pass.length < 6;

				signupPassword.classList.toggle("input-error", showMinLengthError);
				passwordLengthError.classList.toggle("visible", showMinLengthError);
				iconSignupPassword.classList.toggle("icon-danger", showMinLengthError);
				signupConfirm.classList.toggle("input-error", !match);
				passwordError.classList.toggle("visible", !match);
				iconSignupConfirm.classList.toggle("icon-danger", !match);
				signupSubmit.disabled = !match || !hasMinLength;

				return match && hasMinLength;
			}

			function updateStrengthUI() {
				var password = signupPassword.value;
				var result = getStrength(password);

				if (password === "") {
					strengthBox.classList.remove("visible");
					strengthLabel.textContent = "";
					strengthFill.style.width = "0";
					return;
				}

				strengthBox.classList.add("visible");
				strengthLabel.textContent = result.label;
				strengthLabel.style.color = result.color;
				strengthFill.style.width = result.strength + "%";
				strengthFill.style.backgroundColor = result.color;
			}

			function openModal() {
				successModal.classList.add("open");
				successModal.setAttribute("aria-hidden", "false");
			}

			function closeModal() {
				successModal.classList.remove("open");
				successModal.setAttribute("aria-hidden", "true");
			}

			tabLogin.addEventListener("click", function () {
				setTab("login");
			});

			tabSignup.addEventListener("click", function () {
				setTab("signup");
			});

			toSignup.addEventListener("click", function () {
				setTab("signup");
			});

			toLogin.addEventListener("click", function () {
				setTab("login");
			});

			signupPassword.addEventListener("input", function () {
				updateStrengthUI();
				validateConfirmPassword();
			});

			signupConfirm.addEventListener("input", validateConfirmPassword);

			signupForm.addEventListener("submit", function (event) {
				if (!validateConfirmPassword()) {
					event.preventDefault();
					return;
				}
			});

			goToLogin.addEventListener("click", function () {
				closeModal();
				setTab("login");
			});

			modalCloseOverlay.addEventListener("click", closeModal);

			backButton.addEventListener("click", function () {
				window.location.href = "index.php";
			});

			setTab(initialTab);
			updateStrengthUI();
			validateConfirmPassword();

			if (shouldOpenSignupSuccess) {
				openModal();
			}
		})();
	</script>
</body>
</html>
