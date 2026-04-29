<?php
session_start();

if (!isset($_SESSION["users_id"])) {
	header("Location: ../login.php");
	exit;
}

function build_safe_user_key($name, $username, $userId)
{
	$source = trim((string) $name);
	if ($source === "") {
		$source = trim((string) $username);
	}
	if ($source === "") {
		$source = "Farmer" . (string) $userId;
	}

	$safe = preg_replace('/[^A-Za-z0-9_-]+/', '', str_replace(' ', '', $source));
	if ($safe === "") {
		$safe = "Farmer" . (string) $userId;
	}

	return $safe;
}

function is_valid_date_string($date)
{
	if (!is_string($date) || $date === "") {
		return false;
	}

	$dt = DateTime::createFromFormat('Y-m-d', $date);
	return $dt instanceof DateTime && $dt->format('Y-m-d') === $date;
}

function is_valid_time_string($time)
{
	if (!is_string($time) || $time === "") {
		return true;
	}

	$dt = DateTime::createFromFormat('H:i', $time);
	return $dt instanceof DateTime && $dt->format('H:i') === $time;
}

function sort_tasks_by_schedule(&$tasks)
{
	usort($tasks, function ($a, $b) {
		$aDate = (string) ($a['date'] ?? '');
		$bDate = (string) ($b['date'] ?? '');
		if ($aDate !== $bDate) {
			return strcmp($aDate, $bDate);
		}

		$aTime = (string) ($a['time'] ?? '');
		$bTime = (string) ($b['time'] ?? '');
		if ($aTime === '' && $bTime !== '') {
			return 1;
		}
		if ($aTime !== '' && $bTime === '') {
			return -1;
		}

		if ($aTime !== $bTime) {
			return strcmp($aTime, $bTime);
		}

		return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
	});
}

function normalize_task_array($tasks)
{
	$allowedTypes = [
		'watering',
		'fertilizing',
		'spraying',
		'note',
		'corn-stage',
		'maintenance',
		'inspection'
	];

	$allowedPriorities = ['low', 'medium', 'high'];

	$normalized = [];
	if (!is_array($tasks)) {
		return $normalized;
	}

	foreach ($tasks as $task) {
		if (!is_array($task)) {
			continue;
		}

		$id = trim((string) ($task['id'] ?? ''));
		$title = trim((string) ($task['title'] ?? ''));
		$type = strtolower(trim((string) ($task['type'] ?? 'watering')));
		$date = trim((string) ($task['date'] ?? ''));
		$time = trim((string) ($task['time'] ?? ''));
		$notes = trim((string) ($task['notes'] ?? ''));
		$priority = strtolower(trim((string) ($task['priority'] ?? 'medium')));

		if ($id === '' || $title === '' || !is_valid_date_string($date)) {
			continue;
		}

		if (!in_array($type, $allowedTypes, true)) {
			$type = 'watering';
		}

		if (!is_valid_time_string($time)) {
			$time = '';
		}

		if (!in_array($priority, $allowedPriorities, true)) {
			$priority = 'medium';
		}

		$normalized[] = [
			'id' => $id,
			'title' => $title,
			'type' => $type,
			'date' => $date,
			'time' => $time,
			'notes' => $notes,
			'priority' => $priority,
			'completed' => (bool) ($task['completed'] ?? false)
		];
	}

	$unique = [];
	foreach ($normalized as $task) {
		$unique[$task['id']] = $task;
	}

	$result = array_values($unique);
	sort_tasks_by_schedule($result);

	return $result;
}

function has_auto_ai_generated_tasks($tasks)
{
	if (!is_array($tasks)) {
		return false;
	}

	foreach ($tasks as $task) {
		$notes = strtolower(trim((string) ($task['notes'] ?? '')));
		if (strpos($notes, '[autoai]') !== false) {
			return true;
		}
	}

	return false;
}

function save_tasks_payload($filePath, $tasks, $autoScheduleGenerated = false)
{
	payload:
	$payload = [
		'updatedAt' => date('c'),
		'autoScheduleGenerated' => (bool) $autoScheduleGenerated,
		'tasks' => $tasks
	];

	$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($json === false) {
		return false;
	}

	return file_put_contents($filePath, $json, LOCK_EX) !== false;
}

function load_tasks_payload($filePath)
{
	if (!is_file($filePath)) {
		return null;
	}

	$raw = file_get_contents($filePath);
	if ($raw === false) {
		return null;
	}

	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		return null;
	}

	$tasks = normalize_task_array($decoded['tasks'] ?? []);
	$storedGenerated = (bool) ($decoded['autoScheduleGenerated'] ?? false);
	return [
		'updatedAt' => (string) ($decoded['updatedAt'] ?? ''),
		'autoScheduleGenerated' => $storedGenerated || has_auto_ai_generated_tasks($tasks),
		'tasks' => $tasks
	];
}

function get_harvest_days_from_profile($profileData)
{
	$defaultDays = 95;
	if (!is_array($profileData)) {
		return $defaultDays;
	}

	$maxDays = (int) ($profileData['daysToHarvestMax'] ?? 0);
	$minDays = (int) ($profileData['daysToHarvestMin'] ?? 0);

	if ($maxDays > 0) {
		return max(30, min(180, $maxDays));
	}

	if ($minDays > 0) {
		return max(30, min(180, $minDays));
	}

	return $defaultDays;
}

function has_auto_timeline_tasks($tasks)
{
	if (!is_array($tasks)) {
		return false;
	}

	foreach ($tasks as $task) {
		$notes = strtolower(trim((string) ($task['notes'] ?? '')));
		if (strpos($notes, '[autotimeline]') !== false) {
			return true;
		}
	}

	return false;
}

function build_seed_tasks($plantingDate, $harvestDays = 95)
{
	$today = new DateTime('today');
	$base = null;

	if (is_valid_date_string($plantingDate)) {
		$base = DateTime::createFromFormat('Y-m-d', $plantingDate);
	}
	if (!$base) {
		$base = clone $today;
	}

	$harvestDays = max(30, min(180, (int) $harvestDays));

	$tasks = [];

	for ($dayNumber = 1; $dayNumber <= $harvestDays; $dayNumber += 1) {
		$date = clone $base;
		$date->modify('+' . ($dayNumber - 1) . ' day');

		if ($dayNumber === 1 || ($dayNumber % 3) === 0) {
			$tasks[] = [
				'id' => uniqid('task_', true),
				'title' => 'Watering Tasks',
				'type' => 'watering',
				'date' => $date->format('Y-m-d'),
				'time' => '06:30',
				'notes' => '[AutoTimeline] Day ' . $dayNumber . ': check moisture and water as needed.',
				'priority' => 'medium',
				'completed' => false
			];
		}

		if ($dayNumber >= 7 && (($dayNumber - 7) % 14) === 0) {
			$tasks[] = [
				'id' => uniqid('task_', true),
				'title' => 'Fertilizer Tasks',
				'type' => 'fertilizing',
				'date' => $date->format('Y-m-d'),
				'time' => '07:15',
				'notes' => '[AutoTimeline] Day ' . $dayNumber . ': apply fertilizer based on growth stage.',
				'priority' => 'medium',
				'completed' => false
			];
		}

		if ($dayNumber >= 10 && (($dayNumber - 10) % 10) === 0) {
			$tasks[] = [
				'id' => uniqid('task_', true),
				'title' => 'Spraying Tasks',
				'type' => 'spraying',
				'date' => $date->format('Y-m-d'),
				'time' => '08:00',
				'notes' => '[AutoTimeline] Day ' . $dayNumber . ': inspect pests and spray when needed.',
				'priority' => 'medium',
				'completed' => false
			];
		}
	}

	$stageCheckpoints = [
		1 => 'Planting / Seed',
		2 => 'Germination',
		8 => 'Emergence (VE)',
		15 => 'Early Vegetative (V1-V2)',
		25 => 'Vegetative Growth (V3-V5)',
		40 => 'Late Vegetative (V6-V9)',
		55 => 'Silking (R1)',
		65 => 'Grain Fill (R2-R3)',
		78 => 'Dough Stage (R4)',
		88 => 'Dent Stage (R5)',
		$harvestDays => 'Harvest Ready (R6)'
	];

	foreach ($stageCheckpoints as $dayNumber => $stageLabel) {
		if ($dayNumber < 1 || $dayNumber > $harvestDays) {
			continue;
		}

		$date = clone $base;
		$date->modify('+' . ($dayNumber - 1) . ' day');

		$tasks[] = [
			'id' => uniqid('task_', true),
			'title' => 'Corn Stages',
			'type' => 'corn-stage',
			'date' => $date->format('Y-m-d'),
			'time' => '06:45',
			'notes' => '[AutoTimeline] Day ' . $dayNumber . ': ' . $stageLabel,
			'priority' => 'low',
			'completed' => false
		];
	}

	sort_tasks_by_schedule($tasks);
	return $tasks;
}

function parse_activity_list($input)
{
	if (is_array($input)) {
		$list = $input;
	} else {
		$source = trim((string) $input);
		if ($source === '') {
			return [];
		}
		$list = preg_split('/[\r\n,;]+/', $source);
	}

	$result = [];
	if (!is_array($list)) {
		return $result;
	}

	foreach ($list as $item) {
		$clean = strtolower(trim((string) $item));
		if ($clean !== '') {
			$result[] = $clean;
		}
	}

	return array_values(array_unique($result));
}

function list_has_keyword($items, $keywords)
{
	if (!is_array($items) || !is_array($keywords)) {
		return false;
	}

	foreach ($items as $item) {
		$text = strtolower((string) $item);
		foreach ($keywords as $keyword) {
			if ($keyword !== '' && strpos($text, strtolower((string) $keyword)) !== false) {
				return true;
			}
		}
	}

	return false;
}

function normalize_priority($priority)
{
	$priority = strtolower(trim((string) $priority));
	if ($priority === 'high' || $priority === 'low') {
		return $priority;
	}
	return 'medium';
}

function build_recommendation_task($title, $type, DateTime $date, $time, $priority, $reason)
{
	$safeReason = trim((string) $reason);
	$safePriority = normalize_priority($priority);

	$notes = '[AutoAI] Reason: ' . $safeReason . ' | Priority: ' . ucfirst($safePriority);

	return [
		'id' => uniqid('task_', true),
		'title' => $title,
		'type' => $type,
		'date' => $date->format('Y-m-d'),
		'time' => $time,
		'notes' => $notes,
		'priority' => $safePriority,
		'completed' => false
	];
}

function build_auto_ai_tasks($input)
{
	$plantingDate = trim((string) ($input['plantingDate'] ?? ''));
	$daysToHarvest = (int) ($input['daysToHarvest'] ?? 95);
	$currentStage = strtolower(trim((string) ($input['currentGrowthStage'] ?? 'vegetative')));
	$weather = strtolower(trim((string) ($input['weatherCondition'] ?? 'normal')));
	$soilType = trim((string) ($input['soilType'] ?? ''));

	$daysToHarvest = max(30, min(180, $daysToHarvest));

	$today = new DateTime('today');
	$base = is_valid_date_string($plantingDate)
		? DateTime::createFromFormat('Y-m-d', $plantingDate)
		: clone $today;

	if (!$base) {
		$base = clone $today;
	}

	$completed = parse_activity_list($input['completedActivities'] ?? []);
	$delayed = parse_activity_list($input['delayedActivities'] ?? []);

	$isHeavyRain = preg_match('/heavy|storm|rain|wet|flood/', $weather) === 1;
	$isDry = preg_match('/dry|hot|drought|heat/', $weather) === 1;
	$pestRisk = preg_match('/pest|armyworm|borer|blight|fung|humid|risk/', $weather) === 1;

	$wateringInterval = 3;
	$wateringPriority = 'medium';
	$wateringReason = 'Maintain stable soil moisture during ' . ucfirst($currentStage) . ' stage.';

	if ($isHeavyRain) {
		$wateringInterval = 5;
		$wateringPriority = 'low';
		$wateringReason = 'Recent heavy rain; reduce watering to avoid root stress and waterlogging.';
	} elseif ($isDry) {
		$wateringInterval = 1;
		$wateringPriority = 'high';
		$wateringReason = 'Dry weather detected; increase irrigation frequency to protect crop vigor.';
	}

	if (list_has_keyword($delayed, ['water', 'irrigation'])) {
		$wateringInterval = max(1, $wateringInterval - 1);
		$wateringPriority = 'high';
		$wateringReason .= ' Previous watering activity was delayed, so a catch-up cycle is scheduled.';
	}

	$fertDaysOffset = max(10, min(16, (int) round($daysToHarvest * 0.18)));
	$fertInterval = max(12, min(20, (int) round($daysToHarvest * 0.22)));
	$fertPriority = ($currentStage === 'vegetative' || $currentStage === 'reproductive') ? 'high' : 'medium';
	$fertReason = 'Fertilizer is scheduled after early establishment, then repeated by growth window for better nutrient uptake.';

	if (list_has_keyword($completed, ['fertilizer', 'fertilizing'])) {
		$fertDaysOffset = max($fertDaysOffset + 6, 16);
		$fertPriority = 'medium';
		$fertReason .= ' A fertilizer activity is already completed recently, so the next dose is moved later.';
	}

	if (list_has_keyword($delayed, ['fertilizer', 'fertilizing'])) {
		$fertDaysOffset = max(7, $fertDaysOffset - 4);
		$fertInterval = max(10, $fertInterval - 2);
		$fertPriority = 'high';
		$fertReason .= ' Delayed fertilizer work detected, so next related feeding tasks are moved earlier.';
	}

	$sprayNeeded = $pestRisk || list_has_keyword($delayed, ['spray', 'pest', 'disease']);
	$sprayOffset = 3;
	$sprayInterval = 10;
	$sprayPriority = $pestRisk ? 'high' : 'medium';
	$sprayReason = 'Preventive crop protection monitoring.';

	if ($pestRisk) {
		$sprayReason = 'Pest or disease risk identified from weather condition; add protective spraying schedule.';
	}

	if (list_has_keyword($completed, ['spray', 'spraying'])) {
		$sprayOffset = 7;
		$sprayPriority = 'medium';
		$sprayReason .= ' A spraying activity is already completed, so the next schedule is deferred.';
	}

	if (list_has_keyword($delayed, ['spray', 'spraying', 'pest', 'disease'])) {
		$sprayOffset = 1;
		$sprayInterval = 7;
		$sprayPriority = 'high';
		$sprayReason .= ' Delayed crop protection task detected, so following spray tasks are accelerated.';
	}

	if ($soilType !== '') {
		$fertReason .= ' Soil type (' . $soilType . ') is considered in nutrient timing.';
	}

	$timelineStart = clone $base;
	$timelineEnd = clone $base;
	$timelineEnd->modify('+' . max($daysToHarvest - 1, 0) . ' day');

	$secondsDiff = $timelineEnd->getTimestamp() - $timelineStart->getTimestamp();
	$planDays = (int) floor($secondsDiff / 86400);
	if ($planDays < 0) {
		$planDays = 0;
	}

	$generated = [];

	$stageMap = [
		1 => 'Planting Seeds',
		2 => 'Germination Stage',
		max(3, (int) round($daysToHarvest * 0.16)) => 'Seedling Stage',
		max(4, (int) round($daysToHarvest * 0.30)) => 'Vegetative Stage',
		max(5, (int) round($daysToHarvest * 0.50)) => 'Rapid Vegetative Growth',
		max(6, (int) round($daysToHarvest * 0.68)) => 'Reproductive Stage (Tasseling/Silking)',
		max(7, (int) round($daysToHarvest * 0.84)) => 'Grain Filling Stage',
		$daysToHarvest => 'Harvest Stage'
	];

	ksort($stageMap);
	foreach ($stageMap as $dayNumber => $label) {
		$dayNumber = max(1, min($daysToHarvest, (int) $dayNumber));
		$offset = $dayNumber - 1;
		$stageDate = clone $timelineStart;
		$stageDate->modify('+' . $offset . ' day');

		$stageReason = 'Stage transition: ' . $label . '. Align watering, fertilizer, and monitoring with this growth phase.';
		$generated[] = build_recommendation_task($label, 'corn-stage', $stageDate, '06:40', 'medium', $stageReason);
	}

	for ($offset = 0; $offset <= $planDays; $offset += $wateringInterval) {
		$dayNumber = $offset + 1;
		if ($dayNumber >= $daysToHarvest) {
			continue;
		}
		if ($offset === 0 && list_has_keyword($completed, ['water', 'irrigation'])) {
			continue;
		}
		$date = clone $timelineStart;
		$date->modify('+' . $offset . ' day');
		$generated[] = build_recommendation_task('Watering Task', 'watering', $date, '06:30', $wateringPriority, $wateringReason);
	}

	for ($offset = $fertDaysOffset; $offset <= $planDays; $offset += $fertInterval) {
		$dayNumber = $offset + 1;
		if ($dayNumber >= $daysToHarvest) {
			continue;
		}
		$date = clone $timelineStart;
		$date->modify('+' . $offset . ' day');
		$generated[] = build_recommendation_task('Fertilizer Task', 'fertilizing', $date, '07:30', $fertPriority, $fertReason);
	}

	if ($sprayNeeded) {
		for ($offset = $sprayOffset; $offset <= $planDays; $offset += $sprayInterval) {
			$dayNumber = $offset + 1;
			if ($dayNumber >= $daysToHarvest) {
				continue;
			}
			$date = clone $timelineStart;
			$date->modify('+' . $offset . ' day');
			$generated[] = build_recommendation_task('Spraying Task', 'spraying', $date, '08:15', $sprayPriority, $sprayReason);
		}
	}

	sort_tasks_by_schedule($generated);
	return $generated;
}

$displayName = trim((string) ($_SESSION['name'] ?? ''));
$displayUsername = trim((string) ($_SESSION['username'] ?? ''));
if ($displayName === '') {
	$displayName = 'Farmer';
}
if ($displayUsername === '') {
	$displayUsername = 'farmer';
}

$displayHandle = '@' . ltrim($displayUsername, '@');

$initials = '';
$parts = preg_split('/\s+/', trim($displayName));
if (is_array($parts)) {
	foreach ($parts as $part) {
		if ($part === '') {
			continue;
		}
		$initials .= strtoupper(substr($part, 0, 1));
		if (strlen($initials) >= 2) {
			break;
		}
	}
}
if ($initials === '') {
	$initials = 'AC';
}

$safeUserKey = build_safe_user_key($_SESSION['name'] ?? '', $_SESSION['username'] ?? '', $_SESSION['users_id']);

$profileData = null;
try {
	require __DIR__ . '/../data/db_connect.php';
	$userId = (int) $_SESSION['users_id'];
	$stmtProfile = $conn->prepare(
		"SELECT planting_date, estimated_harvest_date, farm_location, area_value, area_unit, corn_type, corn_variety, number_of_packs, weight_of_packs, planting_density, seeds_per_hole, soil_type, estimated_seeds_range
		 FROM corn_profile
		 WHERE users_id = ? AND status = 'active'
		 ORDER BY corn_profile_id DESC
		 LIMIT 1"
	);

	if ($stmtProfile) {
		$stmtProfile->bind_param('i', $userId);
		$stmtProfile->execute();
		$dbProfile = $stmtProfile->get_result()->fetch_assoc();
		$stmtProfile->close();

		if (is_array($dbProfile)) {
			$plantingDate = trim((string) ($dbProfile['planting_date'] ?? ''));
			$estimatedHarvestDate = trim((string) ($dbProfile['estimated_harvest_date'] ?? ''));
			$harvestDays = 0;

			if ($plantingDate !== '' && $estimatedHarvestDate !== '') {
				$plantingDateObj = DateTime::createFromFormat('Y-m-d', $plantingDate);
				$harvestDateObj = DateTime::createFromFormat('Y-m-d', $estimatedHarvestDate);
				if ($plantingDateObj instanceof DateTime && $harvestDateObj instanceof DateTime) {
					$harvestDays = (int) $plantingDateObj->diff($harvestDateObj)->format('%a');
				}
			}

			$areaUnitDb = strtolower(trim((string) ($dbProfile['area_unit'] ?? 'hectare')));
			$areaUnitForm = $areaUnitDb === 'sqm' ? 'square-meters' : 'hectares';

			$profileData = [
				'plantingDate' => $plantingDate,
				'farmLocation' => (string) ($dbProfile['farm_location'] ?? ''),
				'typeOfCorn' => (string) ($dbProfile['corn_type'] ?? ''),
				'cornVariety' => (string) ($dbProfile['corn_variety'] ?? ''),
				'numberOfPacks' => (string) ((int) ($dbProfile['number_of_packs'] ?? 0)),
				'kgOfPacks' => (string) ((float) ($dbProfile['weight_of_packs'] ?? 0)),
				'areaUnit' => $areaUnitForm,
				'areaPlanted' => (string) ((float) ($dbProfile['area_value'] ?? 0)),
				'areaLength' => '',
				'areaWidth' => '',
				'plantingDensity' => (string) ((float) ($dbProfile['planting_density'] ?? 0)),
				'seedsPerHole' => (string) ((int) ($dbProfile['seeds_per_hole'] ?? 0)),
				'soilType' => (string) ($dbProfile['soil_type'] ?? ''),
				'estimatedSeeds' => (string) ($dbProfile['estimated_seeds_range'] ?? ''),
				'daysToHarvestMin' => $harvestDays > 0 ? $harvestDays : null,
				'daysToHarvestMax' => $harvestDays > 0 ? $harvestDays : null,
				'daysToHarvestLabel' => $harvestDays > 0 ? ($harvestDays . ' days') : ''
			];
		}
	}
} catch (Throwable $e) {
	$profileData = null;
}

$calendarDir = __DIR__ . '/../data/Corn Care Calendar';
if (!is_dir($calendarDir)) {
	mkdir($calendarDir, 0777, true);
}

$calendarFile = $calendarDir . '/' . $safeUserKey . '.json';
$taskPayload = load_tasks_payload($calendarFile);
$harvestDays = get_harvest_days_from_profile($profileData);

if (!$taskPayload) {
	$taskPayload = [
		'updatedAt' => date('c'),
		'autoScheduleGenerated' => false,
		'tasks' => []
	];
	save_tasks_payload($calendarFile, $taskPayload['tasks'], $taskPayload['autoScheduleGenerated']);
}

$hasPlantingProfile = is_array($profileData)
	&& trim((string) ($profileData['typeOfCorn'] ?? '')) !== ''
	&& trim((string) ($profileData['cornVariety'] ?? '')) !== ''
	&& is_valid_date_string((string) ($profileData['plantingDate'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	header('Content-Type: application/json; charset=UTF-8');

	$rawBody = file_get_contents('php://input');
	$payload = json_decode($rawBody, true);
	$action = is_array($payload) ? (string) ($payload['action'] ?? '') : '';

	$currentPayload = load_tasks_payload($calendarFile);
	if (!$currentPayload) {
		$currentPayload = [
			'updatedAt' => date('c'),
			'autoScheduleGenerated' => false,
			'tasks' => []
		];
	}
	$tasks = $currentPayload['tasks'];
	$autoScheduleGenerated = (bool) ($currentPayload['autoScheduleGenerated'] ?? false);

	if ($action === 'upsert_task') {
		$taskInput = is_array($payload['task'] ?? null) ? $payload['task'] : null;
		if (!$taskInput) {
			http_response_code(422);
			echo json_encode(['success' => false, 'message' => 'Task data is required.']);
			exit;
		}

		$title = trim((string) ($taskInput['title'] ?? ''));
		$type = strtolower(trim((string) ($taskInput['type'] ?? 'watering')));
		$date = trim((string) ($taskInput['date'] ?? ''));
		$time = trim((string) ($taskInput['time'] ?? ''));
		$notes = trim((string) ($taskInput['notes'] ?? ''));
		$priority = normalize_priority($taskInput['priority'] ?? 'medium');
		$id = trim((string) ($taskInput['id'] ?? ''));

		if ($title === '' || !is_valid_date_string($date)) {
			http_response_code(422);
			echo json_encode(['success' => false, 'message' => 'Task title and date are required.']);
			exit;
		}

		$allowedTypes = ['watering', 'fertilizing', 'spraying', 'note', 'corn-stage', 'maintenance', 'inspection'];
		if (!in_array($type, $allowedTypes, true)) {
			$type = 'watering';
		}

		if (!is_valid_time_string($time)) {
			$time = '';
		}

		if ($id === '') {
			$id = uniqid('task_', true);
		}

		$found = false;
		$todayIso = date('Y-m-d');
		for ($i = 0; $i < count($tasks); $i += 1) {
			if ((string) ($tasks[$i]['id'] ?? '') === $id) {
				$existingDate = (string) ($tasks[$i]['date'] ?? '');
				if ($existingDate !== $todayIso || $date !== $todayIso) {
					http_response_code(409);
					echo json_encode(['success' => false, 'message' => 'You can edit tasks only on the current day.']);
					exit;
				}
				$tasks[$i]['title'] = $title;
				$tasks[$i]['type'] = $type;
				$tasks[$i]['date'] = $date;
				$tasks[$i]['time'] = $time;
				$tasks[$i]['notes'] = $notes;
				$tasks[$i]['priority'] = $priority;
				$tasks[$i]['completed'] = (bool) ($tasks[$i]['completed'] ?? false);
				$found = true;
				break;
			}
		}

		if (!$found) {
			$tasks[] = [
				'id' => $id,
				'title' => $title,
				'type' => $type,
				'date' => $date,
				'time' => $time,
				'notes' => $notes,
				'priority' => $priority,
				'completed' => false
			];
		}

		sort_tasks_by_schedule($tasks);
		$ok = save_tasks_payload($calendarFile, $tasks, $autoScheduleGenerated);
		if (!$ok) {
			http_response_code(500);
			echo json_encode(['success' => false, 'message' => 'Failed to save task file.']);
			exit;
		}

		echo json_encode(['success' => true, 'tasks' => $tasks]);
		exit;
	}

	if ($action === 'toggle_task') {
		$id = trim((string) ($payload['id'] ?? ''));
		if ($id === '') {
			http_response_code(422);
			echo json_encode(['success' => false, 'message' => 'Task id is required.']);
			exit;
		}

		$updated = false;
		$todayIso = date('Y-m-d');
		for ($i = 0; $i < count($tasks); $i += 1) {
			if ((string) ($tasks[$i]['id'] ?? '') === $id) {
				$current = (bool) ($tasks[$i]['completed'] ?? false);
				$taskDate = (string) ($tasks[$i]['date'] ?? '');
				if ($current && $taskDate !== '' && strcmp($taskDate, $todayIso) < 0) {
					http_response_code(409);
					echo json_encode([
						'success' => false,
						'message' => 'Past completed tasks are locked and cannot be unchecked.'
					]);
					exit;
				}
				$tasks[$i]['completed'] = !$current;
				$updated = true;
				break;
			}
		}

		if (!$updated) {
			http_response_code(404);
			echo json_encode(['success' => false, 'message' => 'Task not found.']);
			exit;
		}

		sort_tasks_by_schedule($tasks);
		if (!save_tasks_payload($calendarFile, $tasks, $autoScheduleGenerated)) {
			http_response_code(500);
			echo json_encode(['success' => false, 'message' => 'Failed to save task file.']);
			exit;
		}

		echo json_encode(['success' => true, 'tasks' => $tasks]);
		exit;
	}

	if ($action === 'delete_task') {
		$id = trim((string) ($payload['id'] ?? ''));
		if ($id === '') {
			http_response_code(422);
			echo json_encode(['success' => false, 'message' => 'Task id is required.']);
			exit;
		}

		$todayIso = date('Y-m-d');
		$taskToDelete = null;
		for ($i = 0; $i < count($tasks); $i += 1) {
			if ((string) ($tasks[$i]['id'] ?? '') === $id) {
				$taskToDelete = $tasks[$i];
				break;
			}
		}

		if (!$taskToDelete) {
			http_response_code(404);
			echo json_encode(['success' => false, 'message' => 'Task not found.']);
			exit;
		}

		if ((string) ($taskToDelete['date'] ?? '') !== $todayIso) {
			http_response_code(409);
			echo json_encode(['success' => false, 'message' => 'You can delete tasks only on the current day.']);
			exit;
		}

		$originalCount = count($tasks);
		$tasks = array_values(array_filter($tasks, function ($task) use ($id) {
			return (string) ($task['id'] ?? '') !== $id;
		}));

		if ($originalCount === count($tasks)) {
			http_response_code(404);
			echo json_encode(['success' => false, 'message' => 'Task not found.']);
			exit;
		}

		sort_tasks_by_schedule($tasks);
		if (!save_tasks_payload($calendarFile, $tasks, $autoScheduleGenerated)) {
			http_response_code(500);
			echo json_encode(['success' => false, 'message' => 'Failed to save task file.']);
			exit;
		}

		echo json_encode(['success' => true, 'tasks' => $tasks]);
		exit;
	}

	if ($action === 'generate_auto_schedule') {
		if ($autoScheduleGenerated) {
			http_response_code(409);
			echo json_encode([
				'success' => false,
				'message' => 'Auto schedule was already generated for this calendar.'
			]);
			exit;
		}

		if (!$hasPlantingProfile) {
			http_response_code(422);
			echo json_encode([
				'success' => false,
				'message' => 'Please complete your Corn Planting Profile first before generating an auto schedule.'
			]);
			exit;
		}

		$input = is_array($payload['input'] ?? null) ? $payload['input'] : [];

		$input['daysToHarvest'] = (int) ($input['daysToHarvest'] ?? $harvestDays);
		$input['plantingDate'] = trim((string) ($input['plantingDate'] ?? ($profileData['plantingDate'] ?? '')));

		$generatedTasks = build_auto_ai_tasks($input);

		$filteredTasks = array_values(array_filter($tasks, function ($task) {
			$notes = strtolower((string) ($task['notes'] ?? ''));
			return strpos($notes, '[autoai]') === false;
		}));

		$mergedTasks = array_merge($filteredTasks, $generatedTasks);
		$mergedTasks = normalize_task_array($mergedTasks);

		$autoScheduleGenerated = true;
		if (!save_tasks_payload($calendarFile, $mergedTasks, $autoScheduleGenerated)) {
			http_response_code(500);
			echo json_encode(['success' => false, 'message' => 'Failed to save generated schedule.']);
			exit;
		}

		echo json_encode([
			'success' => true,
			'tasks' => $mergedTasks,
			'autoScheduleGenerated' => $autoScheduleGenerated,
			'generatedCount' => count($generatedTasks)
		]);
		exit;
	}

	if ($action === 'replace_tasks') {
		$incomingTasks = $payload['tasks'] ?? null;
		if (!is_array($incomingTasks)) {
			http_response_code(422);
			echo json_encode(['success' => false, 'message' => 'Task list is required.']);
			exit;
		}

		$normalized = normalize_task_array($incomingTasks);
		sort_tasks_by_schedule($normalized);

		if (!save_tasks_payload($calendarFile, $normalized, $autoScheduleGenerated)) {
			http_response_code(500);
			echo json_encode(['success' => false, 'message' => 'Failed to save adjusted tasks.']);
			exit;
		}

		echo json_encode(['success' => true, 'tasks' => $normalized]);
		exit;
	}

	if ($action === 'get_tasks') {
		echo json_encode(['success' => true, 'tasks' => $tasks]);
		exit;
	}

	http_response_code(400);
	echo json_encode(['success' => false, 'message' => 'Unsupported action.']);
	exit;
}

$cropLabel = 'Corn Field';
if ($profileData && !empty($profileData['typeOfCorn']) && !empty($profileData['cornVariety'])) {
	$cropLabel = (string) $profileData['typeOfCorn'] . ' - ' . (string) $profileData['cornVariety'];
}

$plantingDateLabel = 'No planting date set';
if ($profileData && !empty($profileData['plantingDate']) && is_valid_date_string((string) $profileData['plantingDate'])) {
	$plantingDateLabel = date('F j, Y', strtotime((string) $profileData['plantingDate']));
}

$schedulerSeedInput = [
	'cornType' => (string) ($profileData['typeOfCorn'] ?? ''),
	'cornVariety' => (string) ($profileData['cornVariety'] ?? ''),
	'daysToHarvest' => (int) ($profileData['daysToHarvestMax'] ?? $harvestDays),
	'plantingDate' => (string) ($profileData['plantingDate'] ?? ''),
	'currentGrowthStage' => 'Vegetative',
	'soilType' => (string) ($profileData['soilType'] ?? ''),
	'weatherCondition' => '',
	'completedActivities' => [],
	'delayedActivities' => []
];

$schedulerSeedJson = json_encode($schedulerSeedInput, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
if ($schedulerSeedJson === false) {
	$schedulerSeedJson = '{}';
}

$hasPlantingProfileJson = $hasPlantingProfile ? 'true' : 'false';
$autoScheduleGeneratedJson = !empty($taskPayload['autoScheduleGenerated']) ? 'true' : 'false';

$initialTasksJson = json_encode($taskPayload['tasks'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
if ($initialTasksJson === false) {
	$initialTasksJson = '[]';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Calendar Tasks</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="../bootstrap5/css/bootstrap.min.css">
	<style>
		:root {
			--bg-main: #f3f8ed;
			--bg-soft: #fbfff8;
			--card: #ffffff;
			--line: rgba(89, 137, 85, 0.22);
			--line-strong: rgba(89, 137, 85, 0.38);
			--text: #203227;
			--muted: #6d7f72;
			--accent: #4f9155;
			--accent-soft: #d8f0d3;
			--warm: #f2cb6a;
			--warn: #d97706;
			--danger: #d11f48;
			--ok: #1f8f4f;
			--shadow-sm: 0 10px 24px rgba(32, 50, 39, 0.08);
			--shadow-md: 0 18px 48px rgba(32, 50, 39, 0.14);
			--radius-lg: 22px;
			--radius-md: 16px;
			--radius-sm: 12px;
		}

		* {
			box-sizing: border-box;
		}

		html,
		body {
			margin: 0;
			min-height: 100%;
			color: var(--text);
			font-family: "Manrope", "Segoe UI", Tahoma, sans-serif;
			background: var(--bg-main);
		}

		body {
			background-image:
				radial-gradient(circle at 10% 12%, rgba(124, 190, 123, 0.28), transparent 36%),
				radial-gradient(circle at 88% 86%, rgba(242, 203, 106, 0.26), transparent 38%),
				linear-gradient(135deg, #f2f9ea 0%, #fbfff8 48%, #fdf5de 100%);
			background-attachment: fixed;
			padding-bottom: 28px;
		}

		body.modal-open {
			overflow: hidden;
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
			color: var(--muted);
		}

		.hero {
			position: relative;
			overflow: hidden;
			border: 1px solid var(--line);
			border-radius: var(--radius-lg);
			padding: 24px;
			background:
				radial-gradient(circle at 82% -8%, rgba(242, 203, 106, 0.5), transparent 36%),
				linear-gradient(135deg, rgba(94, 149, 91, 0.16), rgba(255, 255, 255, 0.95));
			box-shadow: var(--shadow-md);
			margin-bottom: 16px;
		}

		.hero .glow {
			position: absolute;
			top: -34px;
			right: -26px;
			width: 180px;
			height: 180px;
			border-radius: 50%;
			background: rgba(94, 149, 91, 0.2);
			filter: blur(36px);
			pointer-events: none;
		}

		.hero-title {
			margin: 0;
			font-family: "Sora", "Manrope", sans-serif;
			font-size: clamp(1.28rem, 3.2vw, 1.9rem);
			line-height: 1.2;
			font-weight: 700;
		}

		.hero-sub {
			margin: 8px 0 0;
			max-width: 760px;
			font-size: 0.96rem;
			color: #4c6151;
		}

		.hero-meta {
			display: flex;
			gap: 8px;
			flex-wrap: wrap;
			margin-top: 14px;
		}

		.meta-pill {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			border: 1px solid var(--line);
			background: rgba(255, 255, 255, 0.86);
			padding: 6px 10px;
			border-radius: 999px;
			font-size: 0.8rem;
			font-weight: 700;
			color: #3a5240;
		}

		.stats-grid {
			display: grid;
			grid-template-columns: repeat(6, minmax(0, 1fr));
			gap: 10px;
			margin-bottom: 16px;
		}

		.stat-card {
			position: relative;
			overflow: hidden;
			border-radius: var(--radius-md);
			padding: 14px;
			background: linear-gradient(155deg, rgba(255, 255, 255, 0.98), rgba(240, 249, 236, 0.88));
			border: 1px solid var(--line);
			box-shadow: var(--shadow-sm);
			min-height: 118px;
			display: flex;
			flex-direction: column;
			align-items: center;
			justify-content: center;
			text-align: center;
			gap: 7px;
		}

		.stat-card::before {
			content: "";
			position: absolute;
			top: 0;
			left: 12px;
			right: 12px;
			height: 3px;
			border-radius: 0 0 8px 8px;
			background: linear-gradient(90deg, rgba(79, 145, 85, 0.25), rgba(242, 203, 106, 0.55), rgba(79, 145, 85, 0.25));
		}

		.stat-head {
			display: flex;
			flex-direction: column;
			align-items: center;
			justify-content: center;
			gap: 5px;
			width: 100%;
		}

		.stat-icon {
			width: 34px;
			height: 34px;
			border-radius: 8px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
		}

		.stat-icon svg {
			width: 18px;
			height: 18px;
			fill: currentColor;
		}

		.stat-icon.completed {
			background: rgba(31, 143, 79, 0.14);
			color: #1f8f4f;
		}

		.stat-icon.pending {
			background: rgba(217, 119, 6, 0.14);
			color: #b35d00;
		}

		.stat-icon.watering {
			background: rgba(45, 143, 194, 0.14);
			color: #1f6b98;
		}

		.stat-icon.fertilizing {
			background: rgba(242, 203, 106, 0.24);
			color: #916c10;
		}

		.stat-icon.spraying {
			background: rgba(89, 133, 242, 0.14);
			color: #2c4ea8;
		}

		.stat-icon.rate {
			background: rgba(162, 97, 214, 0.14);
			color: #6f33a6;
		}

		.stat-label {
			margin: 0;
			font-size: 0.79rem;
			color: var(--muted);
			font-weight: 700;
			line-height: 1.2;
		}

		.stat-value {
			margin: 0;
			font-size: clamp(1.2rem, 2.35vw, 1.65rem);
			font-family: "Sora", "Manrope", sans-serif;
			font-weight: 800;
			line-height: 1;
			letter-spacing: 0.01em;
		}

		.main-grid {
			display: grid;
			grid-template-columns: 1.12fr 0.88fr;
			gap: 14px;
		}

		.panel {
			border: 1px solid var(--line);
			border-radius: var(--radius-lg);
			background: var(--card);
			box-shadow: var(--shadow-sm);
		}

		.panel-head {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 8px;
			flex-wrap: wrap;
			padding: 16px 16px 8px;
		}

		.panel-head > div {
			min-width: 0;
		}

		.panel-title {
			margin: 0;
			font-family: "Sora", "Manrope", sans-serif;
			font-size: 1.03rem;
			font-weight: 600;
		}

		.panel-sub {
			margin: 2px 0 0;
			font-size: 0.8rem;
			color: var(--muted);
		}

		.month-nav {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 6px;
			border-radius: 12px;
			background: rgba(79, 145, 85, 0.08);
			border: 1px solid rgba(79, 145, 85, 0.15);
		}

		.nav-btn {
			width: 34px;
			height: 34px;
			border: 1px solid var(--line);
			border-radius: 9px;
			background: #fff;
			color: #33513a;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			cursor: pointer;
			transition: transform 0.16s ease, box-shadow 0.16s ease, background 0.16s ease;
		}

		.nav-btn:hover {
			transform: translateY(-1px);
			background: #f5faf4;
			box-shadow: 0 6px 14px rgba(32, 50, 39, 0.12);
		}

		.nav-btn svg {
			width: 16px;
			height: 16px;
			fill: currentColor;
		}

		.month-label {
			min-width: 146px;
			text-align: center;
			font-size: 0.92rem;
			font-weight: 700;
			padding: 7px 10px;
			border-radius: 10px;
			background: #ffffff;
			border: 1px solid rgba(79, 145, 85, 0.18);
			color: #34523a;
		}

		.calendar-panel {
			background: linear-gradient(165deg, rgba(255, 255, 255, 0.98), rgba(244, 251, 241, 0.92));
		}

		.calendar-panel .panel-head {
			padding-bottom: 12px;
			border-bottom: 1px dashed rgba(79, 145, 85, 0.25);
		}

		.calendar-scroll {
			overflow-x: auto;
			overflow-y: hidden;
			padding: 0 10px 12px;
			-webkit-overflow-scrolling: touch;
		}

		.week-row,
		.calendar-grid {
			display: grid;
			grid-template-columns: repeat(7, minmax(0, 1fr));
			gap: 6px;
			min-width: 560px;
		}

		.week-row {
			padding: 2px 0 0;
		}

		.week-day {
			text-align: center;
			font-size: 0.73rem;
			font-weight: 800;
			color: var(--muted);
			padding: 3px 0;
			letter-spacing: 0.03em;
			text-transform: uppercase;
		}

		.calendar-grid {
			padding: 10px 0 0;
		}

		.day-cell {
			position: relative;
			border: 1px solid var(--line);
			border-radius: 14px;
			background: linear-gradient(160deg, #ffffff, #f8fcf7);
			min-height: 78px;
			padding: 8px 7px;
			display: flex;
			flex-direction: column;
			align-items: flex-start;
			justify-content: space-between;
			cursor: pointer;
			transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
			overflow: hidden;
		}

		.day-cell:hover {
			transform: translateY(-1px);
			box-shadow: 0 8px 20px rgba(32, 50, 39, 0.09);
		}

		.day-cell.outside {
			opacity: 0.32;
			filter: blur(1.6px) saturate(0.75);
			transform: scale(0.985);
		}

		.day-cell.today {
			border-color: rgba(242, 203, 106, 0.9);
			box-shadow: inset 0 0 0 1px rgba(242, 203, 106, 0.56);
		}

		.day-cell.selected {
			background: linear-gradient(145deg, rgba(79, 145, 85, 0.14), rgba(255, 255, 255, 0.95));
			border-color: var(--line-strong);
		}

		.day-number {
			font-size: 0.82rem;
			font-weight: 700;
			width: 22px;
			height: 22px;
			border-radius: 999px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			background: rgba(79, 145, 85, 0.08);
			color: #2c4632;
		}

		.day-cell.today .day-number {
			background: rgba(242, 203, 106, 0.28);
			color: #7f6210;
		}

		.day-markers {
			align-self: flex-end;
			display: flex;
			gap: 2px;
			flex-wrap: wrap;
			justify-content: flex-end;
			max-width: 100%;
		}

		.day-marker {
			width: 12px;
			height: 12px;
			border-radius: 999px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			border: 1px solid rgba(79, 145, 85, 0.24);
			background: #eef6ee;
			color: #2f5c34;
		}

		.day-marker svg {
			width: 7px;
			height: 7px;
			fill: currentColor;
		}

		.day-marker.watering {
			background: rgba(45, 143, 194, 0.18);
			color: #1f6b98;
			border-color: rgba(31, 107, 152, 0.35);
		}

		.day-marker.fertilizing {
			background: rgba(242, 203, 106, 0.3);
			color: #916c10;
			border-color: rgba(145, 108, 16, 0.35);
		}

		.day-marker.spraying {
			background: rgba(89, 133, 242, 0.2);
			color: #2c4ea8;
			border-color: rgba(44, 78, 168, 0.35);
		}

		.day-marker.corn-stage {
			background: rgba(162, 97, 214, 0.2);
			color: #6f33a6;
			border-color: rgba(111, 51, 166, 0.35);
		}

		.day-marker.done {
			opacity: 0.55;
		}

		.day-marker.more {
			width: auto;
			min-width: 14px;
			height: 12px;
			padding: 0 3px;
			border-radius: 999px;
			font-size: 0.52rem;
			font-weight: 800;
			line-height: 1;
			background: rgba(79, 145, 85, 0.14);
			color: #2f5c34;
		}


		.right-stack {
			display: grid;
			gap: 14px;
		}

		.fixed-size-panel {
			height: 300px;
			display: flex;
			flex-direction: column;
		}

		.fixed-size-panel .task-list-shell,
		.fixed-size-panel .upcoming-shell {
			flex: 1;
			min-height: 0;
		}

		.form-shell,
		.task-list-shell,
		.upcoming-shell {
			padding: 12px;
		}

		.task-modal .form-shell {
			background: linear-gradient(180deg, rgba(79, 145, 85, 0.05), rgba(255, 255, 255, 0.96));
			border-top: 1px solid rgba(79, 145, 85, 0.1);
		}

		.entry-kind-shell {
			padding: 8px;
			border: 1px solid rgba(79, 145, 85, 0.18);
			border-radius: 12px;
			background: rgba(240, 247, 238, 0.9);
		}

		.entry-kind-toggle {
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 8px;
		}

		.entry-kind-btn {
			border: 1px solid rgba(79, 145, 85, 0.24);
			border-radius: 10px;
			padding: 8px 12px;
			font-size: 0.8rem;
			font-weight: 800;
			color: #3e5d44;
			background: #fff;
			cursor: pointer;
			transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
		}

		.entry-kind-btn:hover {
			transform: translateY(-1px);
			box-shadow: 0 8px 16px rgba(79, 145, 85, 0.16);
		}

		.entry-kind-btn.active {
			background: linear-gradient(145deg, #5aa060, #4f9155);
			color: #fff;
			border-color: rgba(79, 145, 85, 0.86);
			box-shadow: 0 10px 20px rgba(79, 145, 85, 0.22);
		}

		.entry-kind-hint {
			margin: 7px 2px 0;
			font-size: 0.72rem;
			font-weight: 700;
			color: #55705b;
		}

		.form-grid {
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 8px;
		}

		.field {
			display: grid;
			gap: 3px;
		}

		.field.full {
			grid-column: 1 / -1;
		}

		.field label {
			font-size: 0.74rem;
			font-weight: 700;
			color: #5b7060;
		}

		.field input,
		.field select,
		.field textarea {
			width: 100%;
			border-radius: 9px;
			border: 1px solid var(--line);
			background: #fff;
			padding: 9px 10px;
			font-size: 0.84rem;
			font-family: "Manrope", "Segoe UI", sans-serif;
			color: var(--text);
		}

		.field input:focus,
		.field select:focus,
		.field textarea:focus {
			outline: none;
			border-color: rgba(79, 145, 85, 0.8);
			box-shadow: 0 0 0 3px rgba(79, 145, 85, 0.16);
		}

		.field textarea {
			resize: vertical;
			min-height: 72px;
		}

		.actions {
			display: flex;
			gap: 8px;
			margin-top: 8px;
		}

		.btn-core {
			border: 0;
			border-radius: 10px;
			padding: 9px 12px;
			font-size: 0.8rem;
			font-weight: 800;
			cursor: pointer;
			transition: transform 0.16s ease, box-shadow 0.16s ease;
		}

		.btn-core:hover {
			transform: translateY(-1px);
		}

		.btn-primary {
			background: linear-gradient(145deg, #5aa060, #4f9155);
			color: #fff;
			box-shadow: 0 10px 20px rgba(79, 145, 85, 0.28);
		}

		.btn-soft {
			background: #f2f7ef;
			color: #39563f;
			border: 1px solid var(--line);
		}

		.add-task-btn {
			border: 0;
			border-radius: 12px;
			padding: 12px 18px;
			background: linear-gradient(145deg, #5aa060, #4f9155);
			color: #fff;
			font-size: 0.92rem;
			font-weight: 800;
			display: inline-flex;
			align-items: center;
			gap: 8px;
			box-shadow: 0 12px 24px rgba(79, 145, 85, 0.26);
			cursor: pointer;
			transition: transform 0.16s ease, box-shadow 0.16s ease;
		}

		.add-task-btn:hover {
			transform: translateY(-1px);
			box-shadow: 0 14px 28px rgba(79, 145, 85, 0.33);
		}

		.add-task-btn svg {
			width: 15px;
			height: 15px;
			fill: currentColor;
		}

		.add-task-btn.inline {
			padding: 9px 12px;
			font-size: 0.8rem;
			border-radius: 10px;
			box-shadow: 0 9px 18px rgba(79, 145, 85, 0.24);
			margin-left: auto;
		}

		.panel-actions {
			display: flex;
			align-items: center;
			gap: 8px;
			margin-left: auto;
			flex-wrap: wrap;
			justify-content: flex-end;
		}

		.ai-task-btn {
			border: 1px solid rgba(32, 78, 92, 0.22);
			border-radius: 10px;
			padding: 9px 12px;
			font-size: 0.8rem;
			font-weight: 800;
			color: #1d5360;
			background: linear-gradient(145deg, #d8f3f5, #bfe8ee);
			display: inline-flex;
			align-items: center;
			gap: 8px;
			cursor: pointer;
			box-shadow: 0 7px 16px rgba(27, 88, 103, 0.14);
			transition: transform 0.16s ease, box-shadow 0.16s ease;
		}

		.ai-task-btn:hover {
			transform: translateY(-1px);
			box-shadow: 0 10px 20px rgba(27, 88, 103, 0.22);
		}

		.ai-task-btn svg {
			width: 15px;
			height: 15px;
			fill: currentColor;
		}

		.auto-loading-wrap {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			gap: 8px;
		}

		.corn-loader {
			position: relative;
			width: 16px;
			height: 16px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
		}

		.corn-loader svg {
			width: 12px;
			height: 12px;
			fill: currentColor;
			animation: cornPulse 0.9s ease-in-out infinite;
		}

		.corn-loader::after {
			content: "";
			position: absolute;
			inset: -2px;
			border-radius: 999px;
			border: 2px solid currentColor;
			border-right-color: transparent;
			opacity: 0.62;
			animation: cornSpin 0.9s linear infinite;
		}

		@keyframes cornSpin {
			to {
				transform: rotate(360deg);
			}
		}

		@keyframes cornPulse {
			0%,
			100% {
				transform: scale(0.95);
			}
			50% {
				transform: scale(1.06);
			}
		}

		.ai-hint {
			margin: 0;
			font-size: 0.79rem;
			color: #4f666d;
		}

		.task-modal-backdrop {
			position: fixed;
			inset: 0;
			background: rgba(15, 28, 18, 0.38);
			backdrop-filter: blur(2px);
			display: none;
			align-items: center;
			justify-content: center;
			padding: 10px;
			z-index: 95;
		}

		.task-modal-backdrop.show {
			display: flex;
		}

		.task-modal {
			width: min(640px, 94vw);
			max-height: 88vh;
			overflow: auto;
			border: 1px solid var(--line);
			border-radius: 16px;
			background: #fff;
			box-shadow: 0 16px 36px rgba(20, 35, 23, 0.22);
		}

		.costing-prompt-backdrop {
			position: fixed;
			inset: 0;
			background: rgba(15, 28, 18, 0.42);
			backdrop-filter: blur(3px);
			display: none;
			align-items: center;
			justify-content: center;
			padding: 14px;
			z-index: 96;
		}

		.costing-prompt-backdrop.show {
			display: flex;
		}

		.costing-prompt {
			width: min(430px, 94vw);
			border: 1px solid rgba(79, 145, 85, 0.26);
			border-radius: 16px;
			background: linear-gradient(170deg, rgba(255, 255, 255, 0.98), rgba(243, 248, 237, 0.94));
			box-shadow: 0 24px 54px rgba(20, 35, 23, 0.24);
			overflow: hidden;
		}

		.costing-prompt-head {
			position: relative;
			padding: 16px 16px 12px;
			background: linear-gradient(135deg, rgba(127, 182, 133, 0.18), rgba(242, 203, 106, 0.16));
			display: flex;
			align-items: center;
			gap: 10px;
		}

		.costing-prompt-head::after {
			content: "";
			position: absolute;
			left: 16px;
			right: 16px;
			bottom: 0;
			height: 1px;
			background: rgba(79, 145, 85, 0.16);
		}

		.costing-prompt-icon {
			width: 38px;
			height: 38px;
			border-radius: 11px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			background: rgba(79, 145, 85, 0.16);
			color: #2f6d38;
			box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
		}

		.costing-prompt-icon svg {
			width: 20px;
			height: 20px;
			fill: currentColor;
		}

		.costing-prompt-title {
			margin: 0;
			font-size: 1rem;
			font-weight: 800;
			color: #203227;
			line-height: 1.35;
		}

		.costing-prompt-sub {
			margin: 0 0 12px;
			padding: 9px 11px;
			border-radius: 10px;
			font-size: 0.82rem;
			line-height: 1.4;
			color: #3e5a43;
			background: rgba(127, 182, 133, 0.1);
			border: 1px solid rgba(127, 182, 133, 0.2);
			text-align: center;
		}

		.costing-prompt-body {
			padding: 12px 16px 16px;
		}

		.costing-prompt-actions {
			display: flex;
			justify-content: center;
			gap: 10px;
			flex-wrap: nowrap;
		}

		.costing-prompt-btn {
			border: 0;
			border-radius: 12px;
			padding: 9px 14px;
			font-size: 0.88rem;
			font-weight: 800;
			min-width: 110px;
			cursor: pointer;
			transition: transform 0.16s ease, box-shadow 0.16s ease;
		}

		@media (max-width: 480px) {
			.costing-prompt-actions {
				flex-wrap: wrap;
			}

			.costing-prompt-btn {
				width: 100%;
			}
		}

		.costing-prompt-btn:hover {
			transform: translateY(-1px);
		}

		.costing-prompt-btn.primary {
			background: linear-gradient(145deg, #5aa060, #4f9155);
			color: #fff;
			box-shadow: 0 12px 24px rgba(79, 145, 85, 0.24);
		}

		.costing-prompt-btn.secondary {
			background: #f2f7ef;
			color: #39563f;
			border: 1px solid var(--line);
		}

		.modal-close {
			width: 34px;
			height: 34px;
			border: 1px solid var(--line);
			border-radius: 9px;
			background: #fff;
			color: #38573e;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			font-size: 1rem;
			font-weight: 700;
			cursor: pointer;
		}

		.selected-date {
			font-size: 0.84rem;
			font-weight: 700;
			color: #46624b;
			margin-bottom: 8px;
		}

		.task-list {
			display: grid;
			gap: 8px;
			height: 100%;
			overflow: auto;
			padding-right: 2px;
		}

		.task-item {
			border: 1px solid var(--line);
			border-radius: 12px;
			padding: 10px;
			background: #fff;
			display: grid;
			grid-template-columns: auto 1fr auto;
			gap: 9px;
			align-items: start;
		}

		.check {
			margin-top: 3px;
			accent-color: #4f9155;
			width: 16px;
			height: 16px;
		}

		.task-title {
			margin: 0;
			font-size: 0.89rem;
			font-weight: 800;
			line-height: 1.2;
		}

		.task-title.done {
			text-decoration: line-through;
			color: #78907d;
		}

		.task-meta {
			display: flex;
			gap: 6px;
			flex-wrap: wrap;
			margin-top: 6px;
		}

		.tag {
			display: inline-flex;
			align-items: center;
			padding: 4px 7px;
			border-radius: 7px;
			font-size: 0.66rem;
			font-weight: 800;
			line-height: 1;
		}

		.tag.watering {
			background: rgba(45, 143, 194, 0.12);
			color: #1f6b98;
		}

		.tag.fertilizing {
			background: rgba(242, 203, 106, 0.23);
			color: #916c10;
		}

		.tag.spraying {
			background: rgba(89, 133, 242, 0.14);
			color: #2c4ea8;
		}

		.tag.note {
			background: rgba(180, 194, 205, 0.22);
			color: #4f5f69;
		}

		.tag.corn-stage {
			background: rgba(162, 97, 214, 0.14);
			color: #6f33a6;
		}

		.tag.time {
			background: rgba(79, 145, 85, 0.13);
			color: #2f5b35;
		}

		.tag.maintenance {
			background: rgba(79, 145, 85, 0.13);
			color: #2f5b35;
		}

		.tag.inspection {
			background: rgba(209, 31, 72, 0.12);
			color: #a6193b;
		}

		.task-note {
			margin: 7px 0 0;
			font-size: 0.76rem;
			color: #5d725f;
		}

		.task-tools {
			display: flex;
			gap: 4px;
		}

		.mini-btn {
			border: 1px solid var(--line);
			background: #fff;
			border-radius: 8px;
			font-size: 0.67rem;
			font-weight: 800;
			padding: 5px 7px;
			cursor: pointer;
		}

		.mini-btn.edit {
			color: #305d34;
		}

		.mini-btn.delete {
			color: var(--danger);
		}

		.empty {
			padding: 16px;
			border-radius: 10px;
			border: 1px dashed var(--line);
			background: #f8fcf6;
			font-size: 0.82rem;
			color: #668069;
			text-align: center;
		}

		.upcoming-list {
			display: grid;
			gap: 7px;
			height: 100%;
			overflow: auto;
			padding-right: 2px;
		}

		.upcoming-item {
			display: grid;
			grid-template-columns: 78px 1fr auto;
			gap: 8px;
			align-items: center;
			padding: 9px 10px;
			border-radius: 10px;
			border: 1px solid var(--line);
			background: #fff;
		}

		.upcoming-date {
			font-size: 0.7rem;
			font-weight: 800;
			color: #46624b;
		}

		.upcoming-title {
			font-size: 0.82rem;
			font-weight: 700;
			line-height: 1.2;
		}

		.toast {
			position: fixed;
			right: 16px;
			bottom: 16px;
			max-width: 300px;
			padding: 10px 12px;
			border-radius: 10px;
			font-size: 0.82rem;
			font-weight: 700;
			color: #fff;
			z-index: 90;
			opacity: 0;
			pointer-events: none;
			transition: opacity 0.18s ease;
		}

		.toast.show {
			opacity: 1;
		}

		.toast.ok {
			background: #1f8f4f;
		}

		.toast.err {
			background: #c71c45;
		}

		@media (max-width: 1240px) {
			.stats-grid {
				grid-template-columns: repeat(3, minmax(0, 1fr));
			}

			.head-inner,
			.page-inner {
				padding-left: 18px;
				padding-right: 18px;
			}
		}

		@media (max-width: 1100px) {
			.stats-grid {
				grid-template-columns: repeat(2, minmax(0, 1fr));
			}


			.task-modal {
				width: 100%;
				max-height: 95vh;
			}
			.main-grid {
				display: flex;
				flex-direction: column;
				gap: 14px;
			}
			.right-stack {
				display: contents;
			}
			.tasks-panel { order: 1; }
			.calendar-panel { order: 2; }
			.upcoming-panel { order: 3; }

			.fixed-size-panel {
				height: 280px;
			}
		}

		@media (max-width: 640px) {
			body {
				background-attachment: scroll;
			}

			.head-inner,
			.page-inner {
				padding-left: 12px;
				padding-right: 12px;
			}

			.page-title {
				font-size: 1.25rem;
			}

			.page-sub {
				font-size: 0.82rem;
			}

			.form-grid {
				grid-template-columns: 1fr;
			}

			.stats-grid {
				grid-template-columns: repeat(2, minmax(0, 1fr));
				gap: 8px;
			}

			.stat-card {
				padding: 10px;
			}

			.stat-value {
				font-size: 1.1rem;
			}

			.month-label {
				min-width: 106px;
				font-size: 0.84rem;
			}

			.month-nav {
				width: 100%;
				justify-content: flex-end;
				padding: 4px;
			}

			.calendar-scroll {
				overflow-x: visible;
				padding-left: 0;
				padding-right: 0;
			}

			.week-row,
			.calendar-grid {
				min-width: 0;
				gap: 4px;
			}

			.week-day {
				font-size: 0.62rem;
				padding: 2px 0;
			}

			.day-cell {
				min-height: 56px;
				padding: 5px 4px;
				border-radius: 10px;
			}

			.day-number {
				font-size: 0.7rem;
				width: 19px;
				height: 19px;
			}

			.day-markers {
				gap: 1px;
			}

			.day-marker {
				width: 10px;
				height: 10px;
			}

			.day-marker svg {
				width: 6px;
				height: 6px;
			}

			.day-marker.more {
				height: 10px;
				min-width: 12px;
				font-size: 0.48rem;
			}


			.task-item {
				grid-template-columns: 1fr;
				gap: 6px;
			}

			.task-tools {
				justify-content: flex-end;
			}

			.upcoming-item {
				grid-template-columns: 1fr;
				gap: 5px;
			}

			.fixed-size-panel {
				height: auto;
				min-height: 210px;
			}

			.task-list,
			.upcoming-list {
				height: auto;
				max-height: 220px;
			}

			.task-modal-backdrop {
				padding: 0;
				align-items: flex-end;
			}

			.task-modal {
				width: 100%;
				max-height: 88vh;
				border-radius: 16px 16px 0 0;
			}

			.add-task-btn.inline {
				width: 100%;
				justify-content: center;
				margin-left: 0;
			}

			.panel-actions {
				width: 100%;
				margin-left: 0;
			}

			.ai-task-btn {
				width: 100%;
				justify-content: center;
			}
		}

		@media (max-width: 480px) {
			.stats-grid {
				grid-template-columns: repeat(2, 1fr);
				gap: 10px;
			}

			.task-list,
			.upcoming-list {
				max-height: 200px;
			}
		}
	</style>
</head>
<body>
	<header class="page-head">
		<div class="head-inner">
			<div class="head-row">
				<button class="back-ghost" type="button" id="backDashboard" title="Back to dashboard" aria-label="Back to dashboard">
					<svg viewBox="0 0 24 24"><path d="m15 5-7 7 7 7 1.5-1.5-5.5-5.5 5.5-5.5z"></path></svg>
				</button>

				<div>
					<h1 class="page-title">Calendar Tasks</h1>
					<p class="page-sub">Manage and schedule your farm tasks.</p>
				</div>
			</div>
		</div>
	</header>

	<main class="page-inner">
		<section class="stats-grid">
			<article class="stat-card">
				<div class="stat-head">
					<span class="stat-icon completed">
						<svg viewBox="0 0 24 24"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"></path></svg>
					</span>
					<p class="stat-label">Completed</p>
				</div>
				<p class="stat-value" id="statCompleted">0</p>
			</article>

			<article class="stat-card">
				<div class="stat-head">
					<span class="stat-icon pending">
						<svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm1 11h4v-2h-3V7h-2v6z"></path></svg>
					</span>
					<p class="stat-label">Pending</p>
				</div>
				<p class="stat-value" id="statPending">0</p>
			</article>

			<article class="stat-card">
				<div class="stat-head">
					<span class="stat-icon watering">
						<svg viewBox="0 0 24 24"><path d="M12 3s6 7 6 11a6 6 0 0 1-12 0c0-4 6-11 6-11z"></path></svg>
					</span>
					<p class="stat-label">Watering Tasks</p>
				</div>
				<p class="stat-value" id="statWatering">0</p>
			</article>

			<article class="stat-card">
				<div class="stat-head">
					<span class="stat-icon fertilizing">
						<svg viewBox="0 0 24 24"><path d="M7 20h10l-1-3H8l-1 3zm2-5h6l2-6H7l2 6zM10 4h4v3h-4z"></path></svg>
					</span>
					<p class="stat-label">Fertilizer Tasks</p>
				</div>
				<p class="stat-value" id="statFertilizer">0</p>
			</article>

			<article class="stat-card">
				<div class="stat-head">
					<span class="stat-icon spraying">
						<svg viewBox="0 0 24 24"><path d="M5 10h9v2H5v-2zm0 4h9v2H5v-2zm11-5h3l-1-3h-2v3zm0 7h2l1-3h-3v3zm-2-8h2v10h-2z"></path></svg>
					</span>
					<p class="stat-label">Spraying Tasks</p>
				</div>
				<p class="stat-value" id="statSpraying">0</p>
			</article>

			<article class="stat-card">
				<div class="stat-head">
					<span class="stat-icon rate">
						<svg viewBox="0 0 24 24"><path d="M3 17h2v3H3v-3zm4-7h2v10H7V10zm4-4h2v14h-2V6zm4 6h2v8h-2v-8zm4-8h2v16h-2V4z"></path></svg>
					</span>
					<p class="stat-label">Completion Rate</p>
				</div>
				<p class="stat-value" id="statRate">0%</p>
			</article>
		</section>

		<section class="main-grid">
			<div class="panel calendar-panel">
				<div class="panel-head">
					<div>
						<h3 class="panel-title">Monthly Task Calendar</h3>
						<p class="panel-sub">Tap any date to view and manage all field tasks.</p>
					</div>
					<div class="month-nav">
						<button class="nav-btn" type="button" id="prevMonth" aria-label="Previous month" title="Previous month">
							<svg viewBox="0 0 24 24"><path d="m15 5-7 7 7 7 1.4-1.4-5.6-5.6 5.6-5.6z"></path></svg>
						</button>
						<div class="month-label" id="monthLabel"></div>
						<button class="nav-btn" type="button" id="nextMonth" aria-label="Next month" title="Next month">
							<svg viewBox="0 0 24 24"><path d="m9 5-1.4 1.4 5.6 5.6-5.6 5.6L9 19l7-7z"></path></svg>
						</button>
					</div>
				</div>

				<div class="calendar-scroll">
					<div class="week-row">
						<div class="week-day">Mon</div>
						<div class="week-day">Tue</div>
						<div class="week-day">Wed</div>
						<div class="week-day">Thu</div>
						<div class="week-day">Fri</div>
						<div class="week-day">Sat</div>
						<div class="week-day">Sun</div>
					</div>

					<div class="calendar-grid" id="calendarGrid"></div>
				</div>

			</div>

			<div class="right-stack">
				<div class="panel fixed-size-panel tasks-panel">
					<div class="panel-head">
						<div>
							<h3 class="panel-title">Tasks on Selected Date</h3>
							<p class="panel-sub" id="selectedDateLabel">No date selected</p>
						</div>
						<div class="panel-actions">
							<button class="ai-task-btn" id="openAiScheduleModal" type="button">
								<svg viewBox="0 0 24 24"><path d="M12 2 9.8 8.2 3.5 10.4l6.3 2.2L12 19l2.2-6.4 6.3-2.2-6.3-2.2z"></path></svg>
								Generate Auto Schedule
							</button>
							<button class="add-task-btn inline" id="openTaskModal" type="button">
								<svg viewBox="0 0 24 24"><path d="M19 11H13V5h-2v6H5v2h6v6h2v-6h6z"></path></svg>
								Add Calendar Task
							</button>
						</div>
					</div>
					<div class="task-list-shell">
						<div class="task-list" id="selectedTaskList"></div>
					</div>
				</div>

				<div class="panel fixed-size-panel upcoming-panel">
					<div class="panel-head">
						<div>
							<h3 class="panel-title">Upcoming 7 Days</h3>
							<p class="panel-sub">Quick view of upcoming farm work.</p>
						</div>
					</div>
					<div class="upcoming-shell">
						<div class="upcoming-list" id="upcomingList"></div>
					</div>
				</div>
			</div>
		</section>
	</main>

	<div class="task-modal-backdrop" id="taskModalBackdrop">
		<div class="task-modal" role="dialog" aria-modal="true" aria-labelledby="formTitle">
			<div class="panel-head">
				<div>
					<h3 class="panel-title" id="formTitle">Add Calendar Task</h3>
					<p class="panel-sub">Create or edit a task for your selected field date.</p>
				</div>
				<button class="modal-close" id="closeTaskModal" type="button" aria-label="Close">x</button>
			</div>
			<div class="form-shell">
				<form id="taskForm" novalidate>
					<input type="hidden" id="taskId" value="">
					<input type="hidden" id="taskEntryKind" name="taskEntryKind" value="task">
					<div class="form-grid">
						<div class="field full">
							<div class="entry-kind-shell">
								<div class="entry-kind-toggle" id="taskEntryKindToggle" role="group" aria-label="Entry Type">
									<button type="button" class="entry-kind-btn active" data-kind="task">Task</button>
									<button type="button" class="entry-kind-btn" data-kind="note">Note</button>
								</div>
								<p class="entry-kind-hint" id="entryKindHint">Task mode includes category, time, and optional notes.</p>
							</div>
						</div>

						<div class="field full">
							<label for="taskTitle">Task Title</label>
							<input id="taskTitle" name="taskTitle" type="text" maxlength="120" placeholder="Example: Water field A before sunrise" required>
						</div>

						<div class="field" id="taskTypeField">
							<label for="taskType">Category</label>
							<select id="taskType" name="taskType">
								<option value="watering">Watering Tasks</option>
								<option value="fertilizing">Fertilizer Tasks</option>
								<option value="spraying">Spraying Tasks</option>
								<option value="corn-stage">Corn Stages</option>
							</select>
						</div>

						<div class="field" id="taskTimeField">
							<label for="taskTime">Time</label>
							<input id="taskTime" name="taskTime" type="time">
						</div>

						<div class="field">
							<label for="taskDate">Date</label>
							<input id="taskDate" name="taskDate" type="date" required>
						</div>

						<div class="field full">
							<label for="taskNotes">Notes</label>
							<textarea id="taskNotes" name="taskNotes" maxlength="280" placeholder="Optional task details"></textarea>
						</div>
					</div>

					<div class="actions">
						<button class="btn-core btn-primary" id="saveTaskBtn" type="submit">Save Task</button>
						<button class="btn-core btn-soft" id="clearTaskBtn" type="button">Clear</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<div class="task-modal-backdrop" id="aiScheduleModalBackdrop">
		<div class="task-modal" role="dialog" aria-modal="true" aria-labelledby="aiScheduleTitle">
			<div class="panel-head">
				<div>
					<h3 class="panel-title" id="aiScheduleTitle">Auto Corn Care Schedule</h3>
					<p class="panel-sub">AI assistant will generate stage notes plus Watering, Fertilizer, and Spraying tasks.</p>
				</div>
				<button class="modal-close" id="closeAiScheduleModal" type="button" aria-label="Close">x</button>
			</div>
			<div class="form-shell">
				<p class="ai-hint">Include completed and delayed activities (one per line or comma-separated) so next tasks can be adjusted automatically.</p>
				<form id="aiScheduleForm" novalidate>
					<div class="form-grid">
						<div class="field">
							<label for="aiCornType">Corn Type</label>
							<input id="aiCornType" name="aiCornType" type="text" maxlength="120" placeholder="Example: Sweet Corn">
						</div>

						<div class="field">
							<label for="aiCornVariety">Corn Variety</label>
							<input id="aiCornVariety" name="aiCornVariety" type="text" maxlength="120" placeholder="Example: Hybrid 101">
						</div>

						<div class="field">
							<label for="aiDaysToHarvest">Days to Harvest</label>
							<input id="aiDaysToHarvest" name="aiDaysToHarvest" type="number" min="30" max="180" step="1" required>
						</div>

						<div class="field">
							<label for="aiPlantingDate">Planting Date</label>
							<input id="aiPlantingDate" name="aiPlantingDate" type="date" required>
						</div>

						<div class="field">
							<label for="aiCurrentStage">Current Growth Stage</label>
							<select id="aiCurrentStage" name="aiCurrentStage">
								<option value="Seedling">Seedling</option>
								<option value="Vegetative">Vegetative</option>
								<option value="Reproductive">Reproductive</option>
								<option value="Harvest">Harvest</option>
							</select>
						</div>

						<div class="field">
							<label for="aiSoilType">Soil Type</label>
							<input id="aiSoilType" name="aiSoilType" type="text" maxlength="80" placeholder="Example: Loamy">
						</div>

						<div class="field full">
							<label for="aiWeatherCondition">Weather Condition</label>
							<input id="aiWeatherCondition" name="aiWeatherCondition" type="text" maxlength="160" placeholder="Example: Heavy rain this week, high humidity">
						</div>

						<div class="field full">
							<label for="aiCompletedActivities">Completed Activities</label>
							<textarea id="aiCompletedActivities" name="aiCompletedActivities" maxlength="500" placeholder="Example: Watering plot A, Fertilizer basal application"></textarea>
						</div>

						<div class="field full">
							<label for="aiDelayedActivities">Missed or Delayed Activities</label>
							<textarea id="aiDelayedActivities" name="aiDelayedActivities" maxlength="500" placeholder="Example: Spraying for armyworm, Second fertilizing round"></textarea>
						</div>
					</div>

					<div class="actions">
						<button class="btn-core btn-primary" id="generateAiScheduleBtn" type="submit">Generate Schedule</button>
						<button class="btn-core btn-soft" id="resetAiFormBtn" type="button">Reset</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<div class="costing-prompt-backdrop" id="costingPromptBackdrop" aria-hidden="true">
		<div class="costing-prompt" role="dialog" aria-modal="true" aria-labelledby="costingPromptTitle">
			<div class="costing-prompt-head">
				<div class="costing-prompt-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24"><path d="M12 2a1 1 0 0 1 1 1v1.07c2.94.49 5 2.26 5 4.93 0 1.2-.44 2.24-1.19 3.08-.72.81-1.72 1.39-2.94 1.87l-.87.33v4.54c1.88-.36 3.1-1.28 3.54-2.53a1 1 0 1 1 1.88.66c-.79 2.25-2.97 3.67-5.42 3.97V21a1 1 0 1 1-2 0v-1.08c-2.92-.44-5.07-1.99-5.55-4.44a1 1 0 1 1 1.96-.39c.32 1.6 1.74 2.75 3.59 3.09v-4.08l-.43-.14c-1.35-.43-2.54-1.02-3.38-1.93C5.7 12.1 5.2 11 5.2 9.67c0-2.9 2.24-4.83 5.8-5.38V3a1 1 0 0 1 1-1zm-1 4.66c-2.19.35-3.8 1.42-3.8 3.01 0 .72.24 1.31.7 1.79.52.54 1.34 1.01 2.33 1.35l.77.26V6.66zm2 10.64c1.99-.29 3.52-1.33 3.52-3.03 0-.78-.27-1.4-.8-1.9-.57-.54-1.42-.98-2.72-1.37V17.3z"/></svg>
				</div>
				<h3 class="costing-prompt-title" id="costingPromptTitle">Record this task in Costing?</h3>
			</div>
			<div class="costing-prompt-body">
				<p class="costing-prompt-sub" id="costingPromptSub">We’ll open Costing and prefill the expense type for you.</p>
				<div class="costing-prompt-actions">
					<button type="button" class="costing-prompt-btn secondary" id="costingPromptNoBtn">No</button>
					<button type="button" class="costing-prompt-btn primary" id="costingPromptYesBtn">Yes</button>
				</div>
			</div>
		</div>
	</div>

	<div class="costing-prompt-backdrop" id="missedTaskPromptBackdrop" aria-hidden="true">
		<div class="costing-prompt" role="dialog" aria-modal="true" aria-labelledby="missedTaskPromptTitle">
			<div class="costing-prompt-head">
				<div class="costing-prompt-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm1 5h-2v7h6v-2h-4V7z"/></svg>
				</div>
				<h3 class="costing-prompt-title" id="missedTaskPromptTitle">You have missed tasks.</h3>
			</div>
			<div class="costing-prompt-body">
				<p class="costing-prompt-sub">You have <strong id="missedTaskCount">0</strong> missed task(s). Do you want to adjust your upcoming tasks?</p>
				<div class="costing-prompt-actions">
					<button type="button" class="costing-prompt-btn secondary" id="missedTaskPromptKeepBtn">No, keep schedule</button>
					<button type="button" class="costing-prompt-btn primary" id="missedTaskPromptAdjustBtn">Yes, adjust tasks</button>
				</div>
			</div>
		</div>
	</div>

	<div class="costing-prompt-backdrop" id="deleteTaskPromptBackdrop" aria-hidden="true">
		<div class="costing-prompt" role="dialog" aria-modal="true" aria-labelledby="deleteTaskPromptTitle">
			<div class="costing-prompt-head">
				<div class="costing-prompt-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm1 13h-2v2h2zm0-8h-2v6h2z"/></svg>
				</div>
				<h3 class="costing-prompt-title" id="deleteTaskPromptTitle">Delete Task</h3>
			</div>
			<div class="costing-prompt-body">
				<p class="costing-prompt-sub" id="deleteTaskPromptSub">Delete this task from your calendar?</p>
				<div class="costing-prompt-actions">
					<button type="button" class="costing-prompt-btn secondary" id="deleteTaskPromptNoBtn">No</button>
					<button type="button" class="costing-prompt-btn primary" id="deleteTaskPromptYesBtn">Yes</button>
				</div>
			</div>
		</div>
	</div>

	<div class="toast" id="toast"></div>

	<script>
		(function () {
			var tasks = <?php echo $initialTasksJson; ?>;
			var schedulerSeed = <?php echo $schedulerSeedJson; ?>;
			var hasPlantingProfile = <?php echo $hasPlantingProfileJson; ?>;
			var autoScheduleGenerated = <?php echo $autoScheduleGeneratedJson; ?>;
			if (!Array.isArray(tasks)) {
				tasks = [];
			}
			if (!schedulerSeed || typeof schedulerSeed !== 'object') {
				schedulerSeed = {};
			}

			var monthLabel = document.getElementById('monthLabel');
			var calendarGrid = document.getElementById('calendarGrid');
			var selectedDateLabel = document.getElementById('selectedDateLabel');
			var selectedTaskList = document.getElementById('selectedTaskList');
			var upcomingList = document.getElementById('upcomingList');
			var toast = document.getElementById('toast');

			var statCompleted = document.getElementById('statCompleted');
			var statPending = document.getElementById('statPending');
			var statWatering = document.getElementById('statWatering');
			var statFertilizer = document.getElementById('statFertilizer');
			var statSpraying = document.getElementById('statSpraying');
			var statRate = document.getElementById('statRate');

			var taskForm = document.getElementById('taskForm');
			var formTitle = document.getElementById('formTitle');
			var openTaskModalBtn = document.getElementById('openTaskModal');
			var closeTaskModalBtn = document.getElementById('closeTaskModal');
			var taskModalBackdrop = document.getElementById('taskModalBackdrop');
			var taskId = document.getElementById('taskId');
			var taskEntryKind = document.getElementById('taskEntryKind');
			var taskEntryKindToggle = document.getElementById('taskEntryKindToggle');
			var taskEntryKindButtons = taskEntryKindToggle ? taskEntryKindToggle.querySelectorAll('[data-kind]') : [];
			var entryKindHint = document.getElementById('entryKindHint');
			var taskTitle = document.getElementById('taskTitle');
			var taskType = document.getElementById('taskType');
			var taskDate = document.getElementById('taskDate');
			var taskTime = document.getElementById('taskTime');
			var taskNotes = document.getElementById('taskNotes');
			var taskTypeField = document.getElementById('taskTypeField');
			var taskTimeField = document.getElementById('taskTimeField');
			var saveTaskBtn = document.getElementById('saveTaskBtn');
			var clearTaskBtn = document.getElementById('clearTaskBtn');

			var openAiScheduleModalBtn = document.getElementById('openAiScheduleModal');
			var closeAiScheduleModalBtn = document.getElementById('closeAiScheduleModal');
			var aiScheduleModalBackdrop = document.getElementById('aiScheduleModalBackdrop');
			var aiScheduleForm = document.getElementById('aiScheduleForm');
			var costingPromptBackdrop = document.getElementById('costingPromptBackdrop');
			var costingPromptNoBtn = document.getElementById('costingPromptNoBtn');
			var costingPromptYesBtn = document.getElementById('costingPromptYesBtn');
			var costingPromptTitle = document.getElementById('costingPromptTitle');
			var costingPromptSub = document.getElementById('costingPromptSub');
			var missedTaskPromptBackdrop = document.getElementById('missedTaskPromptBackdrop');
			var missedTaskPromptKeepBtn = document.getElementById('missedTaskPromptKeepBtn');
			var missedTaskPromptAdjustBtn = document.getElementById('missedTaskPromptAdjustBtn');
			var missedTaskCount = document.getElementById('missedTaskCount');
			var deleteTaskPromptBackdrop = document.getElementById('deleteTaskPromptBackdrop');
			var deleteTaskPromptNoBtn = document.getElementById('deleteTaskPromptNoBtn');
			var deleteTaskPromptYesBtn = document.getElementById('deleteTaskPromptYesBtn');
			var aiCornType = document.getElementById('aiCornType');
			var aiCornVariety = document.getElementById('aiCornVariety');
			var aiDaysToHarvest = document.getElementById('aiDaysToHarvest');
			var aiPlantingDate = document.getElementById('aiPlantingDate');
			var aiCurrentStage = document.getElementById('aiCurrentStage');
			var aiSoilType = document.getElementById('aiSoilType');
			var aiWeatherCondition = document.getElementById('aiWeatherCondition');
			var aiCompletedActivities = document.getElementById('aiCompletedActivities');
			var aiDelayedActivities = document.getElementById('aiDelayedActivities');
			var generateAiScheduleBtn = document.getElementById('generateAiScheduleBtn');
			var resetAiFormBtn = document.getElementById('resetAiFormBtn');
			var openAiScheduleModalDefaultHtml = openAiScheduleModalBtn ? String(openAiScheduleModalBtn.innerHTML || '').trim() : '';
			var generateAiScheduleDefaultHtml = generateAiScheduleBtn ? String(generateAiScheduleBtn.innerHTML || '').trim() : 'Generate Schedule';

			var currentMonthDate = new Date();
			currentMonthDate.setDate(1);

			var today = new Date();
			today.setHours(0, 0, 0, 0);

			var selectedDate = new Date();
			selectedDate.setHours(0, 0, 0, 0);
			var pendingCostingTask = null;
			var pendingCostingCheckbox = null;
			var pendingDeleteTaskId = null;
			var missedPromptShown = false;

			function dateToIso(date) {
				var year = date.getFullYear();
				var month = String(date.getMonth() + 1).padStart(2, '0');
				var day = String(date.getDate()).padStart(2, '0');
				return year + '-' + month + '-' + day;
			}

			function isoToDate(iso) {
				var parts = String(iso).split('-');
				if (parts.length !== 3) {
					return null;
				}
				var d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
				d.setHours(0, 0, 0, 0);
				if (Number.isNaN(d.getTime())) {
					return null;
				}
				return d;
			}

			function formatLongDate(iso) {
				var date = isoToDate(iso);
				if (!date) {
					return iso;
				}
				return date.toLocaleDateString('en-US', {
					weekday: 'long',
					year: 'numeric',
					month: 'long',
					day: 'numeric'
				});
			}

			function formatShortDate(iso) {
				var date = isoToDate(iso);
				if (!date) {
					return iso;
				}
				return date.toLocaleDateString('en-US', {
					month: 'short',
					day: 'numeric'
				});
			}

			function safeText(value) {
				return String(value)
					.replace(/&/g, '&amp;')
					.replace(/</g, '&lt;')
					.replace(/>/g, '&gt;')
					.replace(/"/g, '&quot;')
					.replace(/'/g, '&#039;');
			}

			function formatTypeLabel(type) {
				if (type === 'watering') {
					return 'Watering Tasks';
				}
				if (type === 'fertilizing') {
					return 'Fertilizer Tasks';
				}
				if (type === 'spraying') {
					return 'Spraying Tasks';
				}
				if (type === 'note') {
					return 'Note';
				}
				if (type === 'corn-stage') {
					return 'Corn Stages';
				}
				if (type === 'maintenance') {
					return 'Maintenance';
				}
				if (type === 'inspection') {
					return 'Inspection';
				}
				return String(type || 'Task');
			}

			function formatPriorityLabel(priority) {
				var normalized = String(priority || 'medium').toLowerCase();
				if (normalized === 'high') {
					return 'High';
				}
				if (normalized === 'low') {
					return 'Low';
				}
				return 'Medium';
			}

			function setTaskEntryKind(kind) {
				var normalized = String(kind || 'task') === 'note' ? 'note' : 'task';
				taskEntryKind.value = normalized;

				for (var i = 0; i < taskEntryKindButtons.length; i += 1) {
					var btnKind = String(taskEntryKindButtons[i].getAttribute('data-kind') || '');
					taskEntryKindButtons[i].classList.toggle('active', btnKind === normalized);
				}

				applyTaskEntryKindMode(normalized);
			}

			function setAiFormDefaults() {
				aiCornType.value = String(schedulerSeed.cornType || '');
				aiCornVariety.value = String(schedulerSeed.cornVariety || '');
				aiDaysToHarvest.value = String(schedulerSeed.daysToHarvest || 95);
				aiPlantingDate.value = String(schedulerSeed.plantingDate || dateToIso(new Date()));
				aiCurrentStage.value = String(schedulerSeed.currentGrowthStage || 'Vegetative');
				aiSoilType.value = String(schedulerSeed.soilType || '');
				aiWeatherCondition.value = String(schedulerSeed.weatherCondition || '');
				aiCompletedActivities.value = '';
				aiDelayedActivities.value = '';
			}

			function applyTaskEntryKindMode(mode) {
				var isNote = String(mode || 'task') === 'note';

				if (taskTypeField) {
					taskTypeField.style.display = isNote ? 'none' : '';
				}
				if (taskTimeField) {
					taskTimeField.style.display = isNote ? 'none' : '';
				}

				if (isNote) {
					taskType.value = 'note';
					taskTime.value = '';
					taskTitle.placeholder = 'Optional short title';
					taskNotes.placeholder = 'Write your note';
					taskTitle.required = false;
					if (entryKindHint) {
						entryKindHint.textContent = 'Note mode stores a simple dated note.';
					}
					return;
				}

				taskTitle.placeholder = 'Example: Water field A before sunrise';
				taskNotes.placeholder = 'Optional task details';
				taskTitle.required = true;
				if (entryKindHint) {
					entryKindHint.textContent = 'Task mode includes category, time, and optional notes.';
				}
				if (taskType.value === 'note') {
					taskType.value = 'watering';
				}
			}

			function getTasksForDate(iso) {
				return tasks
					.filter(function (task) {
						return String(task.date) === iso;
					})
					.sort(function (a, b) {
						if (a.time && !b.time) {
							return -1;
						}
						if (!a.time && b.time) {
							return 1;
						}
						if (a.time !== b.time) {
							return String(a.time).localeCompare(String(b.time));
						}
						return String(a.title).localeCompare(String(b.title));
					});
			}

			function isPastDate(iso) {
				var date = isoToDate(iso);
				return Boolean(date && date.getTime() < today.getTime());
			}

			function isPastDoneLocked(task) {
				if (!task || !task.completed) {
					return false;
				}
				return isPastDate(String(task.date || ''));
			}

			function isAdjustableTaskType(type) {
				var normalized = String(type || '').toLowerCase();
				return normalized === 'watering' || normalized === 'fertilizing' || normalized === 'spraying';
			}

			function getLatestAdjustDate() {
				var plantingIso = String(schedulerSeed.plantingDate || '');
				var plantingDate = isoToDate(plantingIso);
				if (!plantingDate) {
					return null;
				}

				var harvestDays = Math.max(30, Number(schedulerSeed.daysToHarvest || 95));
				var latest = new Date(plantingDate.getTime());
				latest.setDate(latest.getDate() + Math.floor(harvestDays) - 4);
				latest.setHours(0, 0, 0, 0);
				return latest;
			}

			function capIsoDateToLatest(iso, latestDate) {
				var parsed = isoToDate(iso);
				if (!parsed || !latestDate) {
					return iso;
				}
				if (parsed.getTime() > latestDate.getTime()) {
					return dateToIso(latestDate);
				}
				return iso;
			}

			function getMissedPendingTasks() {
				var latestDate = getLatestAdjustDate();
				if (latestDate && latestDate.getTime() < today.getTime()) {
					return [];
				}

				return tasks.filter(function (task) {
					return !Boolean(task.completed)
						&& isPastDate(String(task.date || ''))
						&& isAdjustableTaskType(task.type);
				});
			}

			function closeMissedTaskPrompt() {
				if (!missedTaskPromptBackdrop) {
					return;
				}
				missedTaskPromptBackdrop.classList.remove('show');
				if (!taskModalBackdrop.classList.contains('show') && !aiScheduleModalBackdrop.classList.contains('show') && !costingPromptBackdrop.classList.contains('show') && (!deleteTaskPromptBackdrop || !deleteTaskPromptBackdrop.classList.contains('show'))) {
					document.body.classList.remove('modal-open');
				}
			}

			function closeDeleteTaskPrompt() {
				if (!deleteTaskPromptBackdrop) {
					return;
				}
				deleteTaskPromptBackdrop.classList.remove('show');
				pendingDeleteTaskId = null;
				if (!taskModalBackdrop.classList.contains('show') && !aiScheduleModalBackdrop.classList.contains('show') && !costingPromptBackdrop.classList.contains('show') && !missedTaskPromptBackdrop.classList.contains('show')) {
					document.body.classList.remove('modal-open');
				}
			}

			function openDeleteTaskPrompt(taskId) {
				if (!deleteTaskPromptBackdrop || !taskId) {
					return;
				}
				pendingDeleteTaskId = String(taskId);
				if (deleteTaskPromptNoBtn) {
					deleteTaskPromptNoBtn.disabled = false;
				}
				if (deleteTaskPromptYesBtn) {
					deleteTaskPromptYesBtn.disabled = false;
				}
				deleteTaskPromptBackdrop.classList.add('show');
				document.body.classList.add('modal-open');
			}

			function openMissedTaskPrompt() {
				if (!missedTaskPromptBackdrop) {
					return;
				}
				var missed = getMissedPendingTasks();
				if (!missed.length) {
					return;
				}
				if (missedTaskCount) {
					missedTaskCount.textContent = String(missed.length);
				}
				missedTaskPromptBackdrop.classList.add('show');
				document.body.classList.add('modal-open');
			}

			function maybePromptMissedTasks() {
				if (missedPromptShown) {
					return;
				}
				if (!getMissedPendingTasks().length) {
					return;
				}
				missedPromptShown = true;
				openMissedTaskPrompt();
			}

			function buildAdjustedScheduleTasks() {
				var missed = getMissedPendingTasks();
				if (!missed.length) {
					return null;
				}

				var latestAdjustDate = getLatestAdjustDate();
				if (latestAdjustDate && latestAdjustDate.getTime() < today.getTime()) {
					return null;
				}

				var sortedMissed = missed.slice().sort(function (a, b) {
					var typeCompare = String(a.type || '').localeCompare(String(b.type || ''));
					if (typeCompare !== 0) {
						return typeCompare;
					}
					if (a.date !== b.date) {
						return String(a.date || '').localeCompare(String(b.date || ''));
					}
					return String(a.time || '99:99').localeCompare(String(b.time || '99:99'));
				});

				var missedById = {};
				var typeShift = {
					watering: 0,
					fertilizing: 0,
					spraying: 0
				};

				var typeOffset = {
					watering: 0,
					fertilizing: 0,
					spraying: 0
				};

				for (var i = 0; i < sortedMissed.length; i += 1) {
					var missedTask = sortedMissed[i];
					var missedType = String(missedTask.type || '').toLowerCase();
					if (!isAdjustableTaskType(missedType)) {
						continue;
					}

					typeShift[missedType] += 1;

					var moveDate = new Date(today.getTime());
					moveDate.setDate(moveDate.getDate() + typeOffset[missedType]);
					typeOffset[missedType] += 1;

					var moveIso = dateToIso(moveDate);
					if (latestAdjustDate) {
						moveIso = capIsoDateToLatest(moveIso, latestAdjustDate);
					}

					missedById[String(missedTask.id)] = moveIso;
				}

				return tasks.map(function (task) {
					var copy = Object.assign({}, task);
					if (copy.completed) {
						return copy;
					}

					var type = String(copy.type || '').toLowerCase();
					if (!isAdjustableTaskType(type)) {
						return copy;
					}

					var id = String(copy.id || '');
					if (Object.prototype.hasOwnProperty.call(missedById, id)) {
						copy.date = missedById[id];
						return copy;
					}

					var taskDate = isoToDate(copy.date);
					if (!taskDate) {
						return copy;
					}

					var shiftDays = Number(typeShift[type] || 0);
					if (shiftDays > 0 && taskDate.getTime() > today.getTime()) {
						taskDate.setDate(taskDate.getDate() + shiftDays);
						copy.date = dateToIso(taskDate);
						if (latestAdjustDate) {
							copy.date = capIsoDateToLatest(copy.date, latestAdjustDate);
						}
					}

					return copy;
				});
			}

			function daySummary(iso) {
				var list = getTasksForDate(iso);
				var done = list.filter(function (task) {
					return Boolean(task.completed);
				}).length;
				return {
					total: list.length,
					done: done
				};
			}

			function calendarTypeIcon(type) {
				if (type === 'watering') {
					return '<svg viewBox="0 0 24 24"><path d="M12 3s6 7 6 11a6 6 0 0 1-12 0c0-4 6-11 6-11z"></path></svg>';
				}
				if (type === 'fertilizing') {
					return '<svg viewBox="0 0 24 24"><path d="M7 20h10l-1-3H8l-1 3zm2-5h6l2-6H7l2 6zM10 4h4v3h-4z"></path></svg>';
				}
				if (type === 'spraying') {
					return '<svg viewBox="0 0 24 24"><path d="M5 10h9v2H5v-2zm0 4h9v2H5v-2zm11-5h3l-1-3h-2v3zm0 7h2l1-3h-3v3zm-2-8h2v10h-2z"></path></svg>';
				}
				if (type === 'corn-stage') {
					return '<svg viewBox="0 0 24 24"><path d="M8 18c0-4 2-7 4-9 2 2 4 5 4 9H8zm4-16c-2 2-5 6-5 11h10c0-5-3-9-5-11z"></path></svg>';
				}
				if (type === 'note') {
					return '<svg viewBox="0 0 24 24"><path d="M6 3h12v18H6V3zm2 4v2h8V7H8zm0 4v2h8v-2H8zm0 4v2h5v-2H8z"></path></svg>';
				}
				return '<svg viewBox="0 0 24 24"><path d="M12 5a7 7 0 1 0 7 7 7 7 0 0 0-7-7z"></path></svg>';
			}

			function buildDayMarkers(tasksForDay) {
				if (!tasksForDay || tasksForDay.length === 0) {
					return '';
				}

				var maxVisible = 4;
				var markers = [];
				for (var i = 0; i < Math.min(maxVisible, tasksForDay.length); i += 1) {
					var t = tasksForDay[i];
					var type = String(t.type || '');
					var doneClass = t.completed ? ' done' : '';
					markers.push(
						'<span class="day-marker ' + safeText(type) + doneClass + '" title="' + safeText(formatTypeLabel(type)) + '">' +
							calendarTypeIcon(type) +
						'</span>'
					);
				}

				if (tasksForDay.length > maxVisible) {
					markers.push('<span class="day-marker more" title="More tasks">+' + String(tasksForDay.length - maxVisible) + '</span>');
				}

				return '<span class="day-markers">' + markers.join('') + '</span>';
			}

			function showToast(message, isError) {
				toast.textContent = message;
				toast.classList.remove('ok');
				toast.classList.remove('err');
				toast.classList.add(isError ? 'err' : 'ok');
				toast.classList.add('show');
				window.setTimeout(function () {
					toast.classList.remove('show');
				}, 1600);
			}

			function api(action, data) {
				var body = Object.assign({ action: action }, data || {});
				return fetch('corn_care_calendar.php', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json'
					},
					body: JSON.stringify(body)
				})
				.then(function (response) {
					return response.json().then(function (json) {
						if (!response.ok || !json.success) {
							var message = json && json.message ? json.message : 'Request failed.';
							throw new Error(message);
						}
						return json;
					});
				});
			}

			function refreshStats() {
				var total = tasks.length;
				var done = tasks.filter(function (task) {
					return Boolean(task.completed);
				}).length;
				var pending = Math.max(total - done, 0);

				var wateringCount = tasks.filter(function (task) {
					return String(task.type) === 'watering';
				}).length;

				var fertilizerCount = tasks.filter(function (task) {
					return String(task.type) === 'fertilizing';
				}).length;

				var sprayingCount = tasks.filter(function (task) {
					return String(task.type) === 'spraying';
				}).length;

				var rate = total === 0 ? 0 : Math.round((done / total) * 100);

				statCompleted.textContent = String(done);
				statPending.textContent = String(pending);
				statWatering.textContent = String(wateringCount);
				statFertilizer.textContent = String(fertilizerCount);
				statSpraying.textContent = String(sprayingCount);
				statRate.textContent = String(rate) + '%';
			}

			function renderCalendar() {
				var monthName = currentMonthDate.toLocaleDateString('en-US', {
					month: 'long',
					year: 'numeric'
				});
				monthLabel.textContent = monthName;

				var year = currentMonthDate.getFullYear();
				var month = currentMonthDate.getMonth();
				var firstDay = new Date(year, month, 1);
				var firstDayWeekIndex = (firstDay.getDay() + 6) % 7;
				var daysInMonth = new Date(year, month + 1, 0).getDate();
				var daysInPrevMonth = new Date(year, month, 0).getDate();

				calendarGrid.innerHTML = '';

				for (var i = 0; i < 42; i += 1) {
					var dayNumber = 0;
					var cellDate = null;
					var outside = false;

					if (i < firstDayWeekIndex) {
						dayNumber = daysInPrevMonth - firstDayWeekIndex + i + 1;
						cellDate = new Date(year, month - 1, dayNumber);
						outside = true;
					} else if (i >= firstDayWeekIndex + daysInMonth) {
						dayNumber = i - (firstDayWeekIndex + daysInMonth) + 1;
						cellDate = new Date(year, month + 1, dayNumber);
						outside = true;
					} else {
						dayNumber = i - firstDayWeekIndex + 1;
						cellDate = new Date(year, month, dayNumber);
					}

					cellDate.setHours(0, 0, 0, 0);
					var iso = dateToIso(cellDate);
					var tasksForDay = getTasksForDate(iso);
					var isToday = cellDate.getTime() === today.getTime();
					var isSelected = cellDate.getTime() === selectedDate.getTime();

					var button = document.createElement('button');
					button.type = 'button';
					button.className = 'day-cell';
					if (outside) {
						button.classList.add('outside');
					}
					if (isToday) {
						button.classList.add('today');
					}
					if (isSelected) {
						button.classList.add('selected');
					}
					if (tasksForDay.length > 0) {
						button.classList.add('has-task');
					}

					button.setAttribute('data-date', iso);
					button.innerHTML = '<span class="day-number">' + dayNumber + '</span>';

					if (tasksForDay.length > 0) {
						button.innerHTML += buildDayMarkers(tasksForDay);
					}

					button.addEventListener('click', function () {
						var picked = this.getAttribute('data-date');
						var pickedDate = isoToDate(picked);
						if (!pickedDate) {
							return;
						}
						selectedDate = pickedDate;
						currentMonthDate = new Date(pickedDate.getFullYear(), pickedDate.getMonth(), 1);
						taskDate.value = picked;
						renderAll();
					});

					calendarGrid.appendChild(button);
				}
			}

			function renderSelectedDayList() {
				var selectedIso = dateToIso(selectedDate);
				var todayIso = dateToIso(today);
				selectedDateLabel.textContent = formatLongDate(selectedIso);
				var list = getTasksForDate(selectedIso);

				if (list.length === 0) {
					selectedTaskList.innerHTML = '<div class="empty">No tasks for this date yet. Click Add Calendar Task to create one.</div>';
					return;
				}

				selectedTaskList.innerHTML = '';
				for (var i = 0; i < list.length; i += 1) {
					var task = list[i];
					var item = document.createElement('article');
					item.className = 'task-item';
					var isStageNote = String(task.type) === 'corn-stage';
					var isSimpleNote = String(task.type) === 'note';

					var checkedAttr = task.completed ? ' checked' : '';
					var lockAttr = isPastDoneLocked(task) ? ' disabled title="Past completed task is locked"' : '';
					var canManage = String(task.date || '') === todayIso;
					var titleClass = task.completed ? 'task-title done' : 'task-title';
					var timeLabel = task.time ? task.time : 'No time';
					var note = task.notes ? '<p class="task-note">' + safeText(task.notes) + '</p>' : '';
					var checkboxHtml = (isStageNote || isSimpleNote)
						? '<span class="check" style="opacity:0;pointer-events:none;"></span>'
						: '<input type="checkbox" class="check" data-action="toggle" data-id="' + safeText(task.id) + '"' + checkedAttr + lockAttr + '>';
					var metaHtml = isSimpleNote
						? '<div class="task-meta"><span class="tag note">Note</span></div>'
						: '<div class="task-meta">' +
							'<span class="tag ' + safeText(task.type) + '">' + safeText(formatTypeLabel(task.type)) + '</span>' +
							'<span class="tag time">Priority: ' + safeText(formatPriorityLabel(task.priority)) + '</span>' +
							'<span class="tag time">' + safeText(timeLabel) + '</span>' +
						'</div>';
					var toolsHtml = canManage
						? '<div class="task-tools">' +
							'<button type="button" class="mini-btn edit" data-action="edit" data-id="' + safeText(task.id) + '">Edit</button>' +
							'<button type="button" class="mini-btn delete" data-action="delete" data-id="' + safeText(task.id) + '">Delete</button>' +
						'</div>'
						: '';

					item.innerHTML = '' +
						checkboxHtml +
						'<div>' +
							'<p class="' + titleClass + '">' + safeText(task.title) + '</p>' +
							metaHtml +
							note +
						'</div>' +
							toolsHtml;

					selectedTaskList.appendChild(item);
				}
			}

			function getExpenseTypeFromTask(task) {
				var type = String(task && task.type ? task.type : '').toLowerCase();
				if (type === 'watering') return 'Watering Expense';
				if (type === 'fertilizing') return 'Fertilizer Expense';
				if (type === 'spraying') return 'Spraying Expense';
				return '';
			}

			function queueCostingHandoff(task) {
				if (!task) return;
				var expenseType = getExpenseTypeFromTask(task);
				if (!expenseType) return;
				var expenseLabel = String(task.title || '').trim();

				try {
					localStorage.setItem('agricorn_costing_handoff', JSON.stringify({
						expenseType: expenseType,
						expenseLabel: expenseLabel || expenseType,
						taskTitle: String(task.title || ''),
						taskDate: String(task.date || ''),
						source: 'corn_care_calendar'
					}));
				} catch (error) {
					// Ignore storage failures and still allow completion.
				}
			}

			function openCostingTabAfterTask(task) {
				queueCostingHandoff(task);
				window.location.href = 'corn_planting_profile.php?tab=costing';
			}

			function renderUpcoming() {
				var start = new Date(today.getTime());
				var end = new Date(today.getTime());
				end.setDate(end.getDate() + 7);

				var upcoming = tasks
					.filter(function (task) {
						var date = isoToDate(task.date);
						if (!date) {
							return false;
						}
						return date >= start && date <= end;
					})
					.sort(function (a, b) {
						if (a.date !== b.date) {
							return String(a.date).localeCompare(String(b.date));
						}
						return String(a.time || '99:99').localeCompare(String(b.time || '99:99'));
					});

				if (upcoming.length === 0) {
					upcomingList.innerHTML = '<div class="empty">No upcoming tasks in the next 7 days.</div>';
					return;
				}

				upcomingList.innerHTML = '';
				for (var i = 0; i < Math.min(8, upcoming.length); i += 1) {
					var task = upcoming[i];
					var row = document.createElement('div');
					row.className = 'upcoming-item';
					row.innerHTML = '' +
						'<div class="upcoming-date">' + safeText(formatShortDate(task.date)) + '</div>' +
						'<div class="upcoming-title">' + safeText(task.title) + '</div>' +
						'<span class="tag ' + safeText(task.type) + '">' + safeText(formatTypeLabel(task.type)) + '</span>';
					upcomingList.appendChild(row);
				}
			}

			function hasAutoAiTasks() {
				for (var i = 0; i < tasks.length; i += 1) {
					var notes = String(tasks[i].notes || '').toLowerCase();
					if (notes.indexOf('[autoai]') !== -1) {
						autoScheduleGenerated = true;
						return true;
					}
				}
				return false;
			}

			function refreshAutoScheduleButtonVisibility() {
				if (!openAiScheduleModalBtn) {
					return;
				}

				if (autoScheduleGenerated || hasAutoAiTasks()) {
					openAiScheduleModalBtn.style.display = 'none';
					return;
				}

				openAiScheduleModalBtn.style.display = '';
			}

			function renderAll() {
				refreshAutoScheduleButtonVisibility();
				refreshStats();
				renderCalendar();
				renderSelectedDayList();
				renderUpcoming();
				maybePromptMissedTasks();
			}

			function openTaskModal() {
				taskModalBackdrop.classList.add('show');
				document.body.classList.add('modal-open');
				taskTitle.focus();
			}

			function closeTaskModal() {
				taskModalBackdrop.classList.remove('show');
				document.body.classList.remove('modal-open');
			}

			function inferCurrentGrowthStage(plantingIso, daysToHarvest) {
				var planted = isoToDate(plantingIso);
				if (!planted) {
					return 'Vegetative';
				}

				var msPerDay = 24 * 60 * 60 * 1000;
				var elapsed = Math.floor((today.getTime() - planted.getTime()) / msPerDay) + 1;
				var totalDays = Math.max(30, Number(daysToHarvest || 95));

				if (elapsed <= 14) {
					return 'Seedling';
				}
				if (elapsed <= Math.round(totalDays * 0.6)) {
					return 'Vegetative';
				}
				if (elapsed < totalDays) {
					return 'Reproductive';
				}
				return 'Harvest';
			}

			function openAiScheduleModal() {
				if (!hasPlantingProfile) {
					showToast('Complete Corn Planting Profile first before generating auto schedule.', true);
					return;
				}

				if (autoScheduleGenerated || hasAutoAiTasks()) {
					autoScheduleGenerated = true;
					refreshAutoScheduleButtonVisibility();
					showToast('Auto schedule has already been generated.', true);
					return;
				}

				var daysToHarvest = Number(schedulerSeed.daysToHarvest || 95);
				var plantingDate = String(schedulerSeed.plantingDate || '');
				var inferredStage = inferCurrentGrowthStage(plantingDate, daysToHarvest);

				var autoScheduleLoadingStartedAt = Date.now();
				setAutoScheduleLoading(true);
				api('generate_auto_schedule', {
					input: {
						cornType: String(schedulerSeed.cornType || ''),
						cornVariety: String(schedulerSeed.cornVariety || ''),
						daysToHarvest: daysToHarvest,
						plantingDate: plantingDate,
						currentGrowthStage: inferredStage,
						soilType: String(schedulerSeed.soilType || ''),
						weatherCondition: 'Normal field condition',
						completedActivities: '',
						delayedActivities: ''
					}
				})
				.then(function (result) {
					return waitForMinimumLoading(autoScheduleLoadingStartedAt, 3000).then(function () {
						return result;
					});
				})
				.then(function (result) {
					tasks = Array.isArray(result.tasks) ? result.tasks : [];
					autoScheduleGenerated = Boolean(result.autoScheduleGenerated || autoScheduleGenerated || hasAutoAiTasks());
					renderAll();
					showToast('Auto schedule generated: ' + String(Number(result.generatedCount || 0)) + ' tasks.', false);
				})
				.catch(function (error) {
					showToast(error.message || 'Unable to generate schedule.', true);
				})
				.finally(function () {
					setAutoScheduleLoading(false);
					refreshAutoScheduleButtonVisibility();
				});
			}

			function closeAiScheduleModal() {
				aiScheduleModalBackdrop.classList.remove('show');
				if (!taskModalBackdrop.classList.contains('show')) {
					document.body.classList.remove('modal-open');
				}
			}

			function openCostingPrompt(task, checkbox) {
				pendingCostingTask = task || null;
				pendingCostingCheckbox = checkbox || null;
				if (costingPromptTitle) {
					costingPromptTitle.textContent = 'Record this task in Costing?';
				}
				if (costingPromptSub) {
					costingPromptSub.textContent = 'We\'ll open Costing and prefill the expense type for you.';
				}
				costingPromptBackdrop.classList.add('show');
				document.body.classList.add('modal-open');
			}

			function closeCostingPrompt() {
				costingPromptBackdrop.classList.remove('show');
				pendingCostingTask = null;
				pendingCostingCheckbox = null;
				if (!taskModalBackdrop.classList.contains('show') && !aiScheduleModalBackdrop.classList.contains('show')) {
					document.body.classList.remove('modal-open');
				}
			}

			function resetForm(useSelectedDate) {
				taskId.value = '';
				formTitle.textContent = 'Add Calendar Task';
				setTaskEntryKind('task');
				taskTitle.value = '';
				taskType.value = 'watering';
				taskTime.value = '';
				taskNotes.value = '';
				if (useSelectedDate) {
					taskDate.value = dateToIso(selectedDate);
				}
				saveTaskBtn.textContent = 'Save Task';
			}

			function setFormForEdit(task) {
				taskId.value = String(task.id);
				formTitle.textContent = 'Edit Calendar Task';
				var entryKind = String(task.type || '') === 'note' ? 'note' : 'task';
				setTaskEntryKind(entryKind);
				taskTitle.value = String(task.title || '');
				taskType.value = String(task.type || (entryKind === 'note' ? 'note' : 'watering'));
				taskDate.value = String(task.date || '');
				taskTime.value = String(task.time || '');
				taskNotes.value = String(task.notes || '');
				saveTaskBtn.textContent = 'Update Task';
			}

			function getTaskById(id) {
				for (var i = 0; i < tasks.length; i += 1) {
					if (String(tasks[i].id) === String(id)) {
						return tasks[i];
					}
				}
				return null;
			}

			function setBusy(isBusy) {
				saveTaskBtn.disabled = isBusy;
				clearTaskBtn.disabled = isBusy;
			}

			function waitForMinimumLoading(startedAt, minimumMs) {
				var elapsed = Date.now() - Number(startedAt || 0);
				var remaining = Math.max(0, Number(minimumMs || 0) - elapsed);
				return new Promise(function (resolve) {
					window.setTimeout(resolve, remaining);
				});
			}

			function buildGeneratingMarkup(text) {
				var label = String(text || 'Generating...');
				return '' +
					'<span class="auto-loading-wrap">' +
						'<span class="corn-loader" aria-hidden="true">' +
							'<svg viewBox="0 0 24 24"><path d="M12 3c2 2.1 3 4.7 3 8.1 0 3.6-1.3 6.7-3 9.9-1.7-3.2-3-6.3-3-9.9C9 7.7 10 5.1 12 3zm-3.8 5.2C6.7 8.6 5.7 10.4 5.7 13c0 2.7 1.1 5.4 2.8 7.9-2.8-1.3-4.8-3.9-4.8-7.2 0-2.4 1.1-4.3 4.5-5.5zm7.6 0c3.4 1.2 4.5 3.1 4.5 5.5 0 3.3-2 5.9-4.8 7.2 1.7-2.5 2.8-5.2 2.8-7.9 0-2.6-1-4.4-2.5-4.8z"></path></svg>' +
						'</span>' +
						'<span>' + label + '</span>' +
					'</span>';
			}

			function setAutoScheduleLoading(isBusy) {
				var busy = !!isBusy;

				if (openAiScheduleModalBtn) {
					openAiScheduleModalBtn.disabled = busy;
					openAiScheduleModalBtn.setAttribute('aria-busy', busy ? 'true' : 'false');
					openAiScheduleModalBtn.innerHTML = busy
						? buildGeneratingMarkup('Generating...')
						: openAiScheduleModalDefaultHtml;
				}

				if (generateAiScheduleBtn) {
					generateAiScheduleBtn.disabled = busy;
					generateAiScheduleBtn.innerHTML = busy
						? buildGeneratingMarkup('Generating...')
						: generateAiScheduleDefaultHtml;
					generateAiScheduleBtn.setAttribute('aria-busy', busy ? 'true' : 'false');
				}

				if (resetAiFormBtn) {
					resetAiFormBtn.disabled = busy;
				}
			}

			function setAiBusy(isBusy) {
				setAutoScheduleLoading(isBusy);
			}

			taskForm.addEventListener('submit', function (event) {
				event.preventDefault();

				var entryKind = String(taskEntryKind.value || 'task');
				var title = taskTitle.value.trim();
				var notes = taskNotes.value.trim();
				var date = taskDate.value;
				var type = entryKind === 'note' ? 'note' : taskType.value;
				var time = entryKind === 'note' ? '' : taskTime.value;

				if (!date) {
					showToast('Please complete task date.', true);
					return;
				}

				if (entryKind === 'note') {
					if (!title && !notes) {
						showToast('Please add your note.', true);
						return;
					}
					if (!title) {
						title = 'Note';
					}
				} else if (!title) {
					showToast('Please complete task title and date.', true);
					return;
				}

				setBusy(true);
				api('upsert_task', {
					task: {
						id: taskId.value,
						title: title,
						type: type,
						date: date,
						time: time,
						notes: notes
					}
				})
				.then(function (result) {
					tasks = Array.isArray(result.tasks) ? result.tasks : [];
					selectedDate = isoToDate(date) || selectedDate;
					currentMonthDate = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1);
					resetForm(false);
					closeTaskModal();
					renderAll();
					showToast('Task saved.', false);
				})
				.catch(function (error) {
					showToast(error.message || 'Unable to save task.', true);
				})
				.finally(function () {
					setBusy(false);
				});
			});

			clearTaskBtn.addEventListener('click', function () {
				resetForm(true);
			});

			for (var i = 0; i < taskEntryKindButtons.length; i += 1) {
				taskEntryKindButtons[i].addEventListener('click', function () {
					setTaskEntryKind(String(this.getAttribute('data-kind') || 'task'));
				});
			}

			aiScheduleForm.addEventListener('submit', function (event) {
				event.preventDefault();

				if (!hasPlantingProfile) {
					showToast('Complete Corn Planting Profile first before generating auto schedule.', true);
					return;
				}

				if (!aiDaysToHarvest.value || !aiPlantingDate.value) {
					showToast('Days to harvest and planting date are required.', true);
					return;
				}

				if (autoScheduleGenerated || hasAutoAiTasks()) {
					autoScheduleGenerated = true;
					refreshAutoScheduleButtonVisibility();
					showToast('Auto schedule has already been generated.', true);
					return;
				}

				var autoScheduleLoadingStartedAt = Date.now();
				setAiBusy(true);
				api('generate_auto_schedule', {
					input: {
						cornType: aiCornType.value.trim(),
						cornVariety: aiCornVariety.value.trim(),
						daysToHarvest: Number(aiDaysToHarvest.value),
						plantingDate: aiPlantingDate.value,
						currentGrowthStage: aiCurrentStage.value,
						soilType: aiSoilType.value.trim(),
						weatherCondition: aiWeatherCondition.value.trim(),
						completedActivities: aiCompletedActivities.value,
						delayedActivities: aiDelayedActivities.value
					}
				})
				.then(function (result) {
					return waitForMinimumLoading(autoScheduleLoadingStartedAt, 3000).then(function () {
						return result;
					});
				})
				.then(function (result) {
					tasks = Array.isArray(result.tasks) ? result.tasks : [];
					autoScheduleGenerated = Boolean(result.autoScheduleGenerated || autoScheduleGenerated || hasAutoAiTasks());
					renderAll();
					closeAiScheduleModal();
					showToast('Auto schedule generated: ' + String(Number(result.generatedCount || 0)) + ' tasks.', false);
				})
				.catch(function (error) {
					showToast(error.message || 'Unable to generate schedule.', true);
				})
				.finally(function () {
					setAiBusy(false);
				});
			});

			resetAiFormBtn.addEventListener('click', function () {
				setAiFormDefaults();
			});

			selectedTaskList.addEventListener('click', function (event) {
				var target = event.target;
				if (!(target instanceof HTMLElement)) {
					return;
				}

				var action = target.getAttribute('data-action');
				var id = target.getAttribute('data-id');
				if (!action || !id) {
					return;
				}

				var actionableTask = getTaskById(id);
				if (!actionableTask) {
					showToast('Task no longer exists.', true);
					return;
				}

				if (String(actionableTask.date || '') !== dateToIso(today)) {
					showToast('Edit/Delete is allowed only for today\'s tasks.', true);
					return;
				}

				if (action === 'edit') {
					setFormForEdit(actionableTask);
					openTaskModal();
					return;
				}

				if (action === 'delete') {
					openDeleteTaskPrompt(id);
					return;
				}
			});

			selectedTaskList.addEventListener('change', function (event) {
				var target = event.target;
				if (!(target instanceof HTMLElement)) {
					return;
				}
				if (!target.classList.contains('check')) {
					return;
				}

				var id = target.getAttribute('data-id');
				if (!id) {
					return;
				}

				var task = getTaskById(id);
				if (!task) {
					showToast('Task no longer exists.', true);
					target.checked = !target.checked;
					return;
				}

				if (!target.checked && isPastDoneLocked(task)) {
					target.checked = true;
					showToast('Past completed tasks cannot be unchecked.', true);
					return;
				}

				if (target.checked && ['watering', 'fertilizing', 'spraying'].indexOf(String(task.type || '')) !== -1) {
					openCostingPrompt(task, target);
					return;
				}

				api('toggle_task', { id: id })
					.then(function (result) {
						tasks = Array.isArray(result.tasks) ? result.tasks : [];
						renderAll();
					})
					.catch(function (error) {
						showToast(error.message || 'Unable to update task.', true);
					});
			});

				if (costingPromptNoBtn) {
					costingPromptNoBtn.addEventListener('click', function () {
						var taskToMark = pendingCostingTask;
						if (pendingCostingCheckbox) {
							pendingCostingCheckbox.checked = false;
						}
						closeCostingPrompt();
						if (taskToMark && taskToMark.id) {
							api('toggle_task', { id: taskToMark.id })
								.then(function (result) {
									tasks = Array.isArray(result.tasks) ? result.tasks : [];
									renderAll();
									showToast('Task marked done.', false);
								})
								.catch(function (error) {
									showToast(error.message || 'Unable to update task.', true);
								});
						}
					});
				}

				if (costingPromptYesBtn) {
					costingPromptYesBtn.addEventListener('click', function () {
						var taskToCost = pendingCostingTask;
						if (pendingCostingCheckbox) {
							pendingCostingCheckbox.checked = false;
						}
						closeCostingPrompt();
						if (taskToCost && taskToCost.id) {
							api('toggle_task', { id: taskToCost.id })
								.then(function (result) {
									tasks = Array.isArray(result.tasks) ? result.tasks : [];
									renderAll();
									openCostingTabAfterTask(taskToCost);
								})
								.catch(function (error) {
									showToast(error.message || 'Unable to update task.', true);
								});
						}
					});
				}

				if (costingPromptBackdrop) {
					costingPromptBackdrop.addEventListener('click', function (event) {
						if (event.target === costingPromptBackdrop) {
							if (pendingCostingCheckbox) {
								pendingCostingCheckbox.checked = false;
							}
							closeCostingPrompt();
						}
					});
				}

				if (missedTaskPromptKeepBtn) {
					missedTaskPromptKeepBtn.addEventListener('click', function () {
						closeMissedTaskPrompt();
						showToast('You kept your current schedule.', false);
					});
				}

				if (missedTaskPromptAdjustBtn) {
					missedTaskPromptAdjustBtn.addEventListener('click', function () {
						var adjusted = buildAdjustedScheduleTasks();
						if (!adjusted) {
							closeMissedTaskPrompt();
							return;
						}

						missedTaskPromptAdjustBtn.disabled = true;
						if (missedTaskPromptKeepBtn) {
							missedTaskPromptKeepBtn.disabled = true;
						}

						api('replace_tasks', { tasks: adjusted })
							.then(function (result) {
								tasks = Array.isArray(result.tasks) ? result.tasks : [];
								renderAll();
								closeMissedTaskPrompt();
								showToast('Upcoming tasks were adjusted.', false);
							})
							.catch(function (error) {
								showToast(error.message || 'Unable to adjust tasks.', true);
							})
							.finally(function () {
								missedTaskPromptAdjustBtn.disabled = false;
								if (missedTaskPromptKeepBtn) {
									missedTaskPromptKeepBtn.disabled = false;
								}
							});
					});
				}

				if (missedTaskPromptBackdrop) {
					missedTaskPromptBackdrop.addEventListener('click', function (event) {
						if (event.target === missedTaskPromptBackdrop) {
							closeMissedTaskPrompt();
						}
					});
				}

				if (deleteTaskPromptNoBtn) {
					deleteTaskPromptNoBtn.addEventListener('click', function () {
						closeDeleteTaskPrompt();
					});
				}

				if (deleteTaskPromptYesBtn) {
					deleteTaskPromptYesBtn.addEventListener('click', function () {
						if (!pendingDeleteTaskId) {
							closeDeleteTaskPrompt();
							return;
						}

						deleteTaskPromptYesBtn.disabled = true;
						if (deleteTaskPromptNoBtn) {
							deleteTaskPromptNoBtn.disabled = true;
						}

						api('delete_task', { id: pendingDeleteTaskId })
							.then(function (result) {
								tasks = Array.isArray(result.tasks) ? result.tasks : [];
								renderAll();
								closeDeleteTaskPrompt();
								showToast('Task deleted from your calendar.', false);
							})
							.catch(function (error) {
								closeDeleteTaskPrompt();
								showToast(error.message || 'Unable to delete task.', true);
							});
					});
				}

				if (deleteTaskPromptBackdrop) {
					deleteTaskPromptBackdrop.addEventListener('click', function (event) {
						if (event.target === deleteTaskPromptBackdrop) {
							closeDeleteTaskPrompt();
						}
					});
				}

			document.getElementById('prevMonth').addEventListener('click', function () {
				currentMonthDate = new Date(currentMonthDate.getFullYear(), currentMonthDate.getMonth() - 1, 1);
				renderCalendar();
			});

			document.getElementById('nextMonth').addEventListener('click', function () {
				currentMonthDate = new Date(currentMonthDate.getFullYear(), currentMonthDate.getMonth() + 1, 1);
				renderCalendar();
			});

			document.addEventListener('keydown', function (event) {
				if (event.key === 'Escape' && costingPromptBackdrop && costingPromptBackdrop.classList.contains('show')) {
					if (pendingCostingCheckbox) {
						pendingCostingCheckbox.checked = false;
					}
					closeCostingPrompt();
					return;
				}

				if (event.key === 'Escape' && missedTaskPromptBackdrop && missedTaskPromptBackdrop.classList.contains('show')) {
					closeMissedTaskPrompt();
						return;
					}

					if (event.key === 'Escape' && deleteTaskPromptBackdrop && deleteTaskPromptBackdrop.classList.contains('show')) {
						closeDeleteTaskPrompt();
				}
			});

			document.getElementById('backDashboard').addEventListener('click', function () {
				var params = new URLSearchParams(window.location.search);
				var dashboardUrl = params.get('from') === 'features'
					? 'farmer_dashboard.php?view=features'
					: 'farmer_dashboard.php';
				window.location.href = dashboardUrl;
			});

			openTaskModalBtn.addEventListener('click', function () {
				resetForm(true);
				openTaskModal();
			});

			openAiScheduleModalBtn.addEventListener('click', function () {
				openAiScheduleModal();
			});

			closeTaskModalBtn.addEventListener('click', function () {
				closeTaskModal();
			});

			closeAiScheduleModalBtn.addEventListener('click', function () {
				closeAiScheduleModal();
			});

			taskModalBackdrop.addEventListener('click', function (event) {
				if (event.target === taskModalBackdrop) {
					closeTaskModal();
				}
			});

			aiScheduleModalBackdrop.addEventListener('click', function (event) {
				if (event.target === aiScheduleModalBackdrop) {
					closeAiScheduleModal();
				}
			});

			document.addEventListener('keydown', function (event) {
				if (event.key === 'Escape') {
					if (taskModalBackdrop.classList.contains('show')) {
						closeTaskModal();
					}
					if (aiScheduleModalBackdrop.classList.contains('show')) {
						closeAiScheduleModal();
					}
				}
			});

			var selectedIso = dateToIso(selectedDate);
			taskDate.value = selectedIso;

			currentMonthDate = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1);
			resetForm(true);
			setAiFormDefaults();
			if (!hasPlantingProfile) {
				openAiScheduleModalBtn.title = 'Complete Corn Planting Profile first';
			}
			refreshAutoScheduleButtonVisibility();
			renderAll();
		})();
	</script>
</body>
</html>
