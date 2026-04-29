<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (!isset($_SESSION['users_id'])) {
    header('Location: ../login.php');
    exit;
}

$users_id = (int) $_SESSION['users_id'];
$user_name = trim((string) ($_SESSION['name'] ?? $_SESSION['username'] ?? 'Farmer'));

$prediction_result = null;
$display_image_src = null;
$display_image_name = '';
$related_guides = [];
$save_message = '';
if (isset($_SESSION['pest_save_msg'])) {
    $save_message = $_SESSION['pest_save_msg'];
    unset($_SESSION['pest_save_msg']);
}
$save_error = '';
$saved_history = [];

// Resolve executable and script paths from current project structure.
$python_executable_win = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'env' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
$python_executable_lin = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'env' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python';
$python_executable = file_exists($python_executable_win) ? $python_executable_win : $python_executable_lin;
$python_script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'predict.py';

function ensure_pest_results_table(mysqli $conn): bool {
    $sql = "CREATE TABLE IF NOT EXISTS pest_and_disease_results (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        users_id INT UNSIGNED NOT NULL,
        name VARCHAR(255) NOT NULL,
        result VARCHAR(255) NOT NULL,
        image VARCHAR(255) NOT NULL,
        action_recommended TEXT NOT NULL,
        related_guides LONGTEXT NOT NULL,
        date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_pdr_user_date (users_id, date_created)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if ($conn->query($sql) !== true) {
        return false;
    }

    $columnInfo = $conn->query("SHOW COLUMNS FROM pest_and_disease_results LIKE 'image'");
    if ($columnInfo instanceof mysqli_result) {
        $col = $columnInfo->fetch_assoc();
        if ($col) {
            $type = strtolower((string) ($col['Type'] ?? ''));
            if ($type !== '' && strpos($type, 'varchar') === false) {
                $conn->query("ALTER TABLE pest_and_disease_results MODIFY image VARCHAR(255) NOT NULL");
            }
        }
        $columnInfo->close();
    }

    // Ensure action_recommended exists (v1 to v2 transition)
    $columnInfo = $conn->query("SHOW COLUMNS FROM pest_and_disease_results LIKE 'action_recommended'");
    if ($columnInfo instanceof mysqli_result && $columnInfo->num_rows === 0) {
        $conn->query("ALTER TABLE pest_and_disease_results ADD COLUMN action_recommended TEXT NOT NULL AFTER image");
    }

    // Ensure related_guides exists (v2 to v3 transition)
    $columnInfo = $conn->query("SHOW COLUMNS FROM pest_and_disease_results LIKE 'related_guides'");
    if ($columnInfo instanceof mysqli_result && $columnInfo->num_rows === 0) {
        $conn->query("ALTER TABLE pest_and_disease_results ADD COLUMN related_guides LONGTEXT NOT NULL AFTER action_recommended");
    }

    return true;
}

function build_related_guides_payload(array $related_guides): string {
    $titleMap = [];
    foreach ($related_guides as $guide) {
        if (!is_array($guide)) {
            continue;
        }

        $guideTitle = trim((string) ($guide['module_title'] ?? 'Untitled Guide'));
        if ($guideTitle !== '') {
            $titleKey = strtolower($guideTitle);
            if (!isset($titleMap[$titleKey])) {
                $titleMap[$titleKey] = $guideTitle;
            }
        }
    }

    $titles = array_values($titleMap);
    $payload = implode(', ', $titles);
    return substr($payload, 0, 2000);
}

function optimize_image_data_url_for_storage(string $imageDataUrl, int $maxWidth = 1024, int $jpegQuality = 80, int $maxBytes = 220000): string {
    $imageDataUrl = trim($imageDataUrl);
    if ($imageDataUrl === '' || strpos($imageDataUrl, 'data:image/') !== 0) {
        return $imageDataUrl;
    }

    if (!preg_match('/^data:image\/[a-zA-Z0-9.+-]+;base64,/', $imageDataUrl)) {
        return $imageDataUrl;
    }

    if (strlen($imageDataUrl) <= $maxBytes) {
        return $imageDataUrl;
    }

    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return $imageDataUrl;
    }

    $parts = explode(',', $imageDataUrl, 2);
    if (count($parts) !== 2) {
        return $imageDataUrl;
    }

    $decoded = base64_decode($parts[1], true);
    if ($decoded === false) {
        return $imageDataUrl;
    }

    $src = @imagecreatefromstring($decoded);
    if (!$src) {
        return $imageDataUrl;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if ($srcW <= 0 || $srcH <= 0) {
        imagedestroy($src);
        return $imageDataUrl;
    }

    $candidateWidths = [$maxWidth, 900, 800, 720, 640, 560, 480, 420, 360, 320, 280];
    $candidateQualities = [max(45, min(90, $jpegQuality)), 72, 65, 58, 52, 48, 45];
    $bestDataUrl = $imageDataUrl;

    foreach ($candidateWidths as $targetWidth) {
        $targetWidth = (int) max(120, $targetWidth);
        $dstW = $srcW;
        $dstH = $srcH;
        if ($srcW > $targetWidth) {
            $ratio = $targetWidth / $srcW;
            $dstW = (int) max(1, round($srcW * $ratio));
            $dstH = (int) max(1, round($srcH * $ratio));
        }

        $dst = imagecreatetruecolor($dstW, $dstH);
        if (!$dst) {
            continue;
        }

        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        foreach ($candidateQualities as $quality) {
            ob_start();
            imagejpeg($dst, null, (int) $quality);
            $jpegData = ob_get_clean();
            if (!is_string($jpegData) || $jpegData === '') {
                continue;
            }

            $candidate = 'data:image/jpeg;base64,' . base64_encode($jpegData);
            if (strlen($candidate) < strlen($bestDataUrl)) {
                $bestDataUrl = $candidate;
            }

            if (strlen($candidate) <= $maxBytes) {
                imagedestroy($dst);
                imagedestroy($src);
                return $candidate;
            }
        }

        imagedestroy($dst);
    }

    imagedestroy($src);

    return strlen($bestDataUrl) < strlen($imageDataUrl) ? $bestDataUrl : $imageDataUrl;
}

function image_data_url_to_binary(string $imageDataUrl): string {
    $imageDataUrl = trim($imageDataUrl);
    if ($imageDataUrl === '') {
        return '';
    }

    if (strpos($imageDataUrl, 'data:image/') === 0 && strpos($imageDataUrl, ',') !== false) {
        $parts = explode(',', $imageDataUrl, 2);
        if (count($parts) === 2) {
            $decoded = base64_decode($parts[1], true);
            if ($decoded !== false) {
                return $decoded;
            }
        }
    }

    return $imageDataUrl;
}

function build_tiny_visible_data_url(string $imageDataUrl, int $targetWidth = 160, int $jpegQuality = 38): string {
    $imageDataUrl = trim($imageDataUrl);
    if ($imageDataUrl === '' || strpos($imageDataUrl, 'data:image/') !== 0 || strpos($imageDataUrl, ',') === false) {
        return '';
    }

    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return '';
    }

    $parts = explode(',', $imageDataUrl, 2);
    if (count($parts) !== 2) {
        return '';
    }

    $decoded = base64_decode($parts[1], true);
    if ($decoded === false) {
        return '';
    }

    $src = @imagecreatefromstring($decoded);
    if (!$src) {
        return '';
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if ($srcW <= 0 || $srcH <= 0) {
        imagedestroy($src);
        return '';
    }

    $targetWidth = (int) max(80, min(220, $targetWidth));
    $dstW = $srcW;
    $dstH = $srcH;
    if ($srcW > $targetWidth) {
        $ratio = $targetWidth / $srcW;
        $dstW = (int) max(1, round($srcW * $ratio));
        $dstH = (int) max(1, round($srcH * $ratio));
    }

    $dst = imagecreatetruecolor($dstW, $dstH);
    if (!$dst) {
        imagedestroy($src);
        return '';
    }

    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

    ob_start();
    imagejpeg($dst, null, (int) max(28, min(60, $jpegQuality)));
    $jpegData = ob_get_clean();

    imagedestroy($dst);
    imagedestroy($src);

    if (!is_string($jpegData) || $jpegData === '') {
        return '';
    }

    return 'data:image/jpeg;base64,' . base64_encode($jpegData);
}

function enforce_max_image_bytes(string $imageDataUrl, int $maxBytes = 60000): string {
    $imageDataUrl = trim($imageDataUrl);
    if ($imageDataUrl === '') {
        return $imageDataUrl;
    }

    $candidate = optimize_image_data_url_for_storage($imageDataUrl, 720, 65, max(12000, $maxBytes));
    $binary = image_data_url_to_binary($candidate);

    if ($binary !== '' && strlen($binary) <= $maxBytes) {
        return $candidate;
    }

    $candidate = optimize_image_data_url_for_storage($imageDataUrl, 420, 52, max(8000, (int) round($maxBytes * 0.8)));
    $binary = image_data_url_to_binary($candidate);

    if ($binary !== '' && strlen($binary) <= $maxBytes) {
        return $candidate;
    }

    // Final fallback: keep a visible tiny preview, never a white/blank placeholder.
    $tinyVisible = build_tiny_visible_data_url($imageDataUrl, 160, 34);
    if ($tinyVisible !== '') {
        $tinyBinary = image_data_url_to_binary($tinyVisible);
        if ($tinyBinary !== '' && strlen($tinyBinary) <= $maxBytes) {
            return $tinyVisible;
        }
    }

    return $candidate;
}

function image_value_to_data_url(string $storedImageValue): string {
    $storedImageValue = trim($storedImageValue);
    if ($storedImageValue === '') {
        return '';
    }

    if (strpos($storedImageValue, 'data:image/') === 0) {
        return $storedImageValue;
    }

    return 'data:image/jpeg;base64,' . base64_encode($storedImageValue);
}

function get_pest_image_storage_dir(): string {
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'Pest and Disease Image';
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }
    }

    return $dir;
}

function sanitize_filename_base(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return 'scan';
    }

    $value = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $value);
    $value = trim((string) $value, '_');

    return $value !== '' ? strtolower($value) : 'scan';
}

function extension_from_data_url(string $imageDataUrl): string {
    if (preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,/', trim($imageDataUrl), $match)) {
        $type = strtolower((string) $match[1]);
        if ($type === 'jpeg' || $type === 'jpg') {
            return 'jpg';
        }
        if ($type === 'png') {
            return 'png';
        }
        if ($type === 'webp') {
            return 'webp';
        }
    }

    return 'jpg';
}

function save_scan_image_to_storage(string $imageDataUrl, string $nameHint = ''): string {
    $imageDataUrl = optimize_image_data_url_for_storage($imageDataUrl, 1024, 80, 260000);
    $binary = image_data_url_to_binary($imageDataUrl);
    if ($binary === '') {
        return '';
    }

    $dir = get_pest_image_storage_dir();
    if ($dir === '') {
        return '';
    }

    $ext = extension_from_data_url($imageDataUrl);
    $base = sanitize_filename_base($nameHint);

    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (Throwable $error) {
        $suffix = (string) mt_rand(10000000, 99999999);
    }

    $fileName = date('Ymd_His') . '_' . $base . '_' . $suffix . '.' . $ext;
    $filePath = $dir . DIRECTORY_SEPARATOR . $fileName;

    if (file_put_contents($filePath, $binary, LOCK_EX) === false) {
        return '';
    }

    return $fileName;
}

function image_value_to_web_src(string $storedImageValue): string {
    $storedImageValue = trim($storedImageValue);
    if ($storedImageValue === '') {
        return '';
    }

    if (strpos($storedImageValue, 'data:image/') === 0) {
        return $storedImageValue;
    }

    $fileName = basename($storedImageValue);
    if ($fileName === '') {
        return '';
    }

    $filePath = get_pest_image_storage_dir() . DIRECTORY_SEPARATOR . $fileName;
    if (!is_file($filePath)) {
        return '';
    }

    return '../data/Pest%20and%20Disease%20Image/' . rawurlencode($fileName);
}

function fetch_saved_pest_results(mysqli $conn, int $users_id, int $limit = 20): array {
    $items = [];
    $limit = max(1, min(100, $limit));

    $stmt = $conn->prepare("SELECT id, name, result, image, action_recommended, related_guides, date_created
                            FROM pest_and_disease_results
                            WHERE users_id = ?
                            ORDER BY date_created DESC
                            LIMIT ?");
    if (!$stmt) {
        return $items;
    }

    $stmt->bind_param('ii', $users_id, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();

    return $items;
}

$postAction = isset($_POST['form_action']) ? trim((string) $_POST['form_action']) : 'analyze';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $postAction === 'analyze') {
    $temp_file_abs = null;

    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $display_image_name = pathinfo((string) ($_FILES['image']['name'] ?? ''), PATHINFO_FILENAME);
        $tmpPath = (string) ($_FILES['image']['tmp_name'] ?? '');
        $mimeType = (string) ($_FILES['image']['type'] ?? 'image/jpeg');
        $rawImage = $tmpPath !== '' && is_file($tmpPath) ? file_get_contents($tmpPath) : false;

        if ($rawImage === false) {
            $prediction_result = ['status' => 'error', 'message' => 'Failed to read uploaded image.'];
        } else {
            $display_image_src = 'data:' . $mimeType . ';base64,' . base64_encode($rawImage);
            $display_image_src = optimize_image_data_url_for_storage($display_image_src, 1024, 80, 260000);
            $tempPath = tempnam(sys_get_temp_dir(), 'agricorn_scan_');
            if ($tempPath === false) {
                $prediction_result = ['status' => 'error', 'message' => 'Unable to prepare temporary image file.'];
            } else {
                $temp_file_abs = $tempPath . '.jpg';
                if (!rename($tempPath, $temp_file_abs) || file_put_contents($temp_file_abs, $rawImage) === false) {
                    $prediction_result = ['status' => 'error', 'message' => 'Failed to prepare temporary image for analysis.'];
                }
            }
        }
    } elseif (!empty($_POST['camera_data'])) {
        $display_image_name = 'camera_scan';
        $cameraPayload = (string) $_POST['camera_data'];
        $display_image_src = optimize_image_data_url_for_storage($cameraPayload, 1024, 80, 260000);

        if (strpos($cameraPayload, ',') !== false) {
            list(, $cameraData) = explode(',', $cameraPayload, 2);
            $decoded = base64_decode($cameraData);
            if ($decoded !== false) {
                $tempPath = tempnam(sys_get_temp_dir(), 'agricorn_scan_');
                if ($tempPath === false) {
                    $prediction_result = ['status' => 'error', 'message' => 'Unable to prepare temporary image file.'];
                } else {
                    $temp_file_abs = $tempPath . '.png';
                    if (!rename($tempPath, $temp_file_abs) || file_put_contents($temp_file_abs, $decoded) === false) {
                        $prediction_result = ['status' => 'error', 'message' => 'Failed to prepare temporary camera image.'];
                    }
                }
            } else {
                $prediction_result = ['status' => 'error', 'message' => 'Invalid camera image data.'];
            }
        } else {
            $prediction_result = ['status' => 'error', 'message' => 'Camera payload format is invalid.'];
        }
    }

    if (isset($temp_file_abs) && !isset($prediction_result)) {
        if (!file_exists($python_executable)) {
            $prediction_result = ['status' => 'error', 'message' => 'Python runtime not found. Check env/Scripts/python.exe path.'];
        } elseif (!file_exists($python_script)) {
            $prediction_result = ['status' => 'error', 'message' => 'predict.py not found in data folder.'];
        } else {
            $version_output = shell_exec(escapeshellarg($python_executable) . ' --version 2>&1');
            $broken_launcher = is_string($version_output) && stripos($version_output, 'No Python at') !== false;

            if ($broken_launcher) {
                $prediction_result = ['status' => 'error', 'message' => 'Python environment is broken. Please recreate the project virtual environment.'];
            } else {
                $command = escapeshellarg($python_executable) . ' ' . escapeshellarg($python_script) . ' ' . escapeshellarg($temp_file_abs) . ' 2>&1';
                $output_raw = (string) shell_exec($command);
                $parsed = null;
                $json_start = strpos($output_raw, '{');
                $json_end = strrpos($output_raw, '}');
                if ($json_start !== false && $json_end !== false) {
                    $json_str = substr($output_raw, $json_start, $json_end - $json_start + 1);
                    $parsed = json_decode($json_str, true);
                }

                if (is_array($parsed)) {
                    $prediction_result = $parsed;
                } else {
                    $prediction_result = [
                        'status' => 'error',
                        'message' => 'AI returned an invalid response. Details: ' . trim($output_raw)
                    ];
                }
            }
        }

        if (is_file($temp_file_abs)) {
            @unlink($temp_file_abs);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $postAction === 'save_result') {
    $resultName = trim((string) ($_POST['result'] ?? ''));
    $imageSrc = trim((string) ($_POST['image'] ?? ''));
    $imageNameHint = trim((string) ($_POST['image_name'] ?? ''));
    $actionRecommended = trim((string) ($_POST['action_recommended'] ?? ''));
    $relatedGuidesPayload = trim((string) ($_POST['related_guides'] ?? ''));

    if ($relatedGuidesPayload !== '') {
        $parts = preg_split('/\s*,\s*/', $relatedGuidesPayload);
        $titleMap = [];
        if (is_array($parts)) {
            foreach ($parts as $part) {
                $title = trim((string) $part);
                if ($title === '') {
                    continue;
                }
                $key = strtolower($title);
                if (!isset($titleMap[$key])) {
                    $titleMap[$key] = $title;
                }
            }
        }
        $relatedGuidesPayload = implode(', ', array_values($titleMap));
        $relatedGuidesPayload = substr($relatedGuidesPayload, 0, 2000);
    }

    $savedImageFileName = '';
    if (strpos($imageSrc, 'data:image/') === 0) {
        $savedImageFileName = save_scan_image_to_storage($imageSrc, $imageNameHint);
    } else {
        $existingName = basename($imageNameHint);
        if ($existingName !== '') {
            $existingPath = get_pest_image_storage_dir() . DIRECTORY_SEPARATOR . $existingName;
            if (is_file($existingPath)) {
                $savedImageFileName = $existingName;
            }
        }
    }

    if ($resultName === '' || $imageSrc === '' || $actionRecommended === '' || $savedImageFileName === '') {
        $save_error = 'Unable to save. Missing result, image, or recommendation data.';
    } else {
        try {
            require __DIR__ . '/../data/db_connect.php';

            if (!ensure_pest_results_table($conn)) {
                $save_error = 'Unable to prepare pest and disease results table.';
            } else {
                $stmtCheck = $conn->prepare("SELECT id
                    FROM pest_and_disease_results
                    WHERE users_id = ?
                      AND result = ?
                      AND image = ?
                      AND action_recommended = ?
                      AND related_guides = ?
                    LIMIT 1");

                if (!$stmtCheck) {
                    $save_error = 'Unable to save result at the moment. DB prepare failed.';
                } else {
                    $stmtCheck->bind_param('issss', $users_id, $resultName, $savedImageFileName, $actionRecommended, $relatedGuidesPayload);
                    $stmtCheck->execute();
                    $resCheck = $stmtCheck->get_result();
                    $isDuplicate = $resCheck instanceof mysqli_result && $resCheck->num_rows > 0;
                    if ($resCheck instanceof mysqli_result) {
                        $resCheck->free();
                    }
                    $stmtCheck->close();

                    if ($isDuplicate) {
                        $save_message = 'You already saved this result.';
                    } else {
                        $stmtSave = $conn->prepare("INSERT INTO pest_and_disease_results
                            (users_id, name, result, image, action_recommended, related_guides)
                            VALUES (?, ?, ?, ?, ?, ?)");

                        if (!$stmtSave) {
                            $save_error = 'Unable to save result at the moment. DB prepare failed.';
                        } else {
                            $stmtSave->bind_param('isssss', $users_id, $user_name, $resultName, $savedImageFileName, $actionRecommended, $relatedGuidesPayload);
                            $ok = $stmtSave->execute();
                            $stmtError = (string) ($stmtSave->error ?? '');
                            $stmtSave->close();

                            if ($ok) {
                                $_SESSION['pest_save_msg'] = 'Result saved to history.';
                                header('Location: farmer_dashboard.php');
                                exit;
                            } else {
                                $save_error = 'Saving failed. ' . ($stmtError !== '' ? $stmtError : 'Please try again.');
                            }
                        }
                    }
                }
            }
        } catch (Throwable $error) {
            $save_error = 'Database error while saving result: ' . $error->getMessage();
        }

        $prediction_result = [
            'class' => $resultName,
            'confidence' => trim((string) ($_POST['confidence'] ?? '')),
            'status' => 'saved'
        ];
        $display_image_name = $savedImageFileName;
        $display_image_src = image_value_to_web_src($savedImageFileName);

        $related_guides = [];
        if ($relatedGuidesPayload !== '') {
            $pieces = preg_split('/\s*,\s*/', $relatedGuidesPayload);
            if (is_array($pieces)) {
                foreach ($pieces as $piece) {
                    $title = trim((string) $piece);
                    if ($title !== '') {
                        $related_guides[] = ['module_title' => $title];
                    }
                }
            }
        }
    }
}

/**
 * Detailed recommendations per detected class.
 */
function getRecSteps($class): array {
    $normalized = strtolower(str_replace(' ', '_', trim((string) $class)));

    $recs = [
        'aphids' => [
            'Inspect affected leaves and identify severity of infestation.',
            'Remove heavily infested leaves if damage is localized.',
            'Spray strong water on leaves to dislodge aphids.',
            'Apply approved insecticidal soap or recommended insecticide.',
            'Monitor plants after 3–5 days and repeat treatment if needed.'
        ],
        'army_worm' => [
            'Check leaves and whorls for larvae presence.',
            'Handpick and remove visible worms if infestation is light.',
            'Remove badly damaged leaves.',
            'Apply recommended biological or chemical insecticide.',
            'Reinspect the field after treatment and continue monitoring.'
        ],
        'black_cutworm' => [
            'Inspect damaged seedlings around affected area.',
            'Replace severely cut seedlings if needed.',
            'Remove larvae found near soil surface.',
            'Apply soil-directed insecticide around affected plants.',
            'Monitor neighboring plants for additional damage.'
        ],
        'corn_borer' => [
            'Inspect stalks and whorls for borer entry holes.',
            'Remove severely infested plant parts if possible.',
            'Apply recommended insecticide targeting larvae stage.',
            'Strengthen plant nutrition to reduce stress.',
            'Monitor plants weekly for new infestation signs.'
        ],
        'fall_army_worm' => [
            'Check whorls for larvae and feeding damage.',
            'Remove visible larvae manually when possible.',
            'Apply approved control such as Bt or recommended insecticide.',
            'Treat surrounding plants to prevent spread.',
            'Reassess damage after 3–5 days.'
        ],
        'grub' => [
            'Inspect soil around affected plants.',
            'Remove visible grubs manually.',
            'Apply soil treatment or recommended insecticide.',
            'Improve drainage and reduce excess moisture.',
            'Monitor plant recovery and check nearby plants.'
        ],
        'large_cutworm' => [
            'Inspect damaged plants and surrounding soil.',
            'Remove larvae found near plant base.',
            'Replace dead seedlings if severe.',
            'Apply appropriate insecticide treatment.',
            'Monitor nearby rows for further attack.'
        ],
        'mole_cricket' => [
            'Check soil for tunnels and root damage.',
            'Reduce excessive irrigation if soil is too wet.',
            'Apply recommended soil treatment.',
            'Repair damaged plant stands if needed.',
            'Monitor field for recurring activity.'
        ],
        'northern_corn_rootworm' => [
            'Inspect roots for feeding injury.',
            'Support affected plants if lodging occurs.',
            'Apply recommended soil insecticide.',
            'Reduce plant stress with proper fertilization.',
            'Continue monitoring root damage progression.'
        ],
        'peach_borer' => [
            'Inspect stems for boring signs.',
            'Remove heavily damaged tissues when possible.',
            'Apply approved treatment to affected plants.',
            'Destroy severely infested plants if necessary.',
            'Monitor nearby plants for spread.'
        ],
        'potosiabre_vitarsis' => [
            'Inspect plants for feeding damage.',
            'Remove visible insects manually if possible.',
            'Apply recommended insecticide.',
            'Remove heavily damaged plant parts.',
            'Monitor field after treatment.'
        ],
        'red_spider' => [
            'Inspect underside of leaves for mites.',
            'Remove badly infested leaves if localized.',
            'Improve irrigation to reduce plant stress.',
            'Apply recommended miticide.',
            'Recheck infestation after several days.'
        ],
        'western_corn_rootworm' => [
            'Inspect roots for injury symptoms.',
            'Support lodged plants if necessary.',
            'Apply recommended control treatment.',
            'Improve plant nutrition.',
            'Continue monitoring surrounding plants.'
        ],
        'white_margined_moth' => [
            'Inspect leaves for larvae feeding.',
            'Remove larvae manually when possible.',
            'Remove severely damaged leaves.',
            'Apply recommended insecticide.',
            'Monitor plants for new activity.'
        ],
        'wireworm' => [
            'Inspect soil near damaged roots.',
            'Remove visible larvae if possible.',
            'Apply approved soil treatment.',
            'Improve field drainage if needed.',
            'Monitor affected plants for recovery.'
        ],
        'yellow_cutworm' => [
            'Inspect seedlings and surrounding soil.',
            'Remove larvae near plant base.',
            'Replace dead seedlings if needed.',
            'Apply recommended insecticide.',
            'Monitor neighboring plants for new damage.'
        ],
        'blight' => [
            'Inspect leaves and assess severity.',
            'Remove heavily infected leaves if localized.',
            'Avoid overhead watering to reduce spread.',
            'Apply recommended fungicide.',
            'Monitor plants and repeat treatment if needed.'
        ],
        'common_rust' => [
            'Check leaves for rust pustules.',
            'Remove severely infected leaves if possible.',
            'Improve airflow around plants.',
            'Apply approved fungicide.',
            'Monitor disease spread weekly.'
        ],
        'gray_leaf_spot' => [
            'Inspect leaves for lesion spread.',
            'Remove heavily infected leaves when practical.',
            'Reduce excess moisture in the field.',
            'Apply recommended fungicide.',
            'Continue monitoring and reapply if necessary.'
        ],
        'healthy' => [
            'Continue regular crop monitoring.',
            'Maintain proper irrigation schedule.',
            'Apply scheduled fertilizer as needed.',
            'Keep field free from weeds.',
            'Inspect plants weekly for early problems.'
        ]
    ];

    return $recs[$normalized] ?? [
        'Inspect affected plants carefully and identify severity.',
        'Remove heavily damaged plant parts when possible.',
        'Apply approved treatment based on local agricultural guidance.',
        'Reduce crop stress through proper water and nutrient management.',
        'Monitor the field regularly and reassess after treatment.'
    ];
}

/**
 * Maps the detection class to a specific Farming Guide category
 */
function getGuideCategory($class) {
    $normalized = strtolower(str_replace(' ', '_', $class));
    $diseases = ['blight', 'common_rust', 'gray_leaf_spot'];
    
    if (in_array($normalized, $diseases)) {
        return "Corn Leaf Disease";
    }
    
    if ($normalized === 'healthy') {
        return "All";
    }

    return "Pest Control";
}

/**
 * Fetch related guides from guide_module based on detected class/category.
 */
function getRelatedGuides(string $class, int $limit = 4): array {
    $guides = [];
    $limit = max(1, min($limit, 8));

    $normalized = strtolower(str_replace(' ', '_', trim($class)));
    $keyword = str_replace('_', ' ', $normalized);
    $category = getGuideCategory($class);

    try {
        require __DIR__ . '/../data/db_connect.php';

        $sql = "SELECT guide_id, module_title, category, short_description, guide_file, external_link
                FROM guide_module
                WHERE (? = 'All' OR category = ?)
                  AND (
                        ? = ''
                        OR LOWER(module_title) LIKE CONCAT('%', ?, '%')
                        OR LOWER(short_description) LIKE CONCAT('%', ?, '%')
                        OR LOWER(guide_content) LIKE CONCAT('%', ?, '%')
                      )
                ORDER BY guide_id DESC
                LIMIT ?";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ssssssi', $category, $category, $keyword, $keyword, $keyword, $keyword, $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $guides[] = $row;
            }
            $stmt->close();
        }

        // Fallback: if keyword match is too strict, show latest guides in mapped category.
        if (count($guides) === 0) {
            $fallbackSql = "SELECT guide_id, module_title, category, short_description, guide_file, external_link
                            FROM guide_module
                            WHERE (? = 'All' OR category = ?)
                            ORDER BY guide_id DESC
                            LIMIT ?";
            $fallbackStmt = $conn->prepare($fallbackSql);
            if ($fallbackStmt) {
                $fallbackStmt->bind_param('ssi', $category, $category, $limit);
                $fallbackStmt->execute();
                $fallbackRes = $fallbackStmt->get_result();
                while ($row = $fallbackRes->fetch_assoc()) {
                    $guides[] = $row;
                }
                $fallbackStmt->close();
            }
        }
    } catch (Throwable $error) {
        return [];
    }

    return $guides;
}

if (is_array($prediction_result) && isset($prediction_result['class'])) {
    $related_guides = getRelatedGuides((string) $prediction_result['class'], 4);
}

try {
    require __DIR__ . '/../data/db_connect.php';
    if (ensure_pest_results_table($conn)) {
        $saved_history = fetch_saved_pest_results($conn, $users_id, 20);
    }
} catch (Throwable $error) {
    $saved_history = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corn Scanner AI Pro | Pest & Disease Identification</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #fdfdfd; min-height: 100vh; font-family: 'Segoe UI', sans-serif; margin: 0; padding: 0; }
        html,
        body {
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        html::-webkit-scrollbar,
        body::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }
        .page-head {
            position: sticky;
            top: 0;
            z-index: 40;
            border-bottom: 1px solid rgba(127, 182, 133, 0.28);
            box-shadow: 0 4px 14px rgba(37, 56, 40, 0.09);
            background: linear-gradient(90deg, rgba(127, 182, 133, 0.4), rgba(127, 182, 133, 0.35), rgba(255, 229, 153, 0.4));
            backdrop-filter: blur(8px);
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
        .head-title-wrap {
            flex: 1 1 auto;
            min-width: 0;
        }
        .history-icon-btn {
            width: 44px;
            height: 44px;
            border: 0;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.72);
            color: #2f4a32;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.18s ease, transform 0.18s ease;
            box-shadow: 0 6px 14px rgba(37, 56, 40, 0.12);
        }
        .history-icon-btn:hover {
            background: #ffffff;
            transform: translateY(-1px);
        }
        .history-icon-btn i {
            font-size: 1.15rem;
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
        .back-ghost:hover { background: rgba(127, 182, 133, 0.2); }
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
            color: #2c3e2e;
        }
        .page-sub {
            margin: 2px 0 0;
            font-size: 0.9rem;
            color: #6b7c6e;
        }
        .history-modal {
            position: fixed;
            inset: 0;
            z-index: 1200;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: rgba(18, 31, 20, 0.55);
        }
        .history-modal.show {
            display: flex;
        }
        .history-modal-card {
            width: min(860px, 100%);
            max-height: 88vh;
            overflow: auto;
            background: linear-gradient(180deg, #f8fcf7, #ffffff);
            border-radius: 20px;
            border: 1px solid rgba(127, 182, 133, 0.3);
            box-shadow: 0 18px 42px rgba(37, 56, 40, 0.24);
            padding: 18px;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .history-modal-card::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }
        .history-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }
        .history-modal-title {
            margin: 0;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #24452a;
            font-size: 1.06rem;
            font-weight: 800;
            letter-spacing: 0.01em;
        }
        .history-modal-close {
            width: 36px;
            height: 36px;
            border: 0;
            border-radius: 10px;
            background: #eff5ef;
            color: #2f4a32;
        }
        .history-stack {
            display: grid;
            gap: 12px;
        }
        .history-empty {
            margin: 0;
            padding: 14px;
            border-radius: 12px;
            border: 1px dashed rgba(127, 182, 133, 0.35);
            background: rgba(255, 255, 255, 0.85);
            color: #5d7261;
            font-size: 0.9rem;
            text-align: center;
        }
        .history-entry {
            padding: 12px;
            border-radius: 16px;
            border: 1px solid rgba(127, 182, 133, 0.24);
            background: linear-gradient(180deg, #ffffff, #f7fbf6);
            box-shadow: 0 8px 20px rgba(37, 56, 40, 0.08);
        }
        .history-entry-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
        }
        .history-entry-meta {
            min-width: 0;
            display: grid;
            gap: 4px;
        }
        .history-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            width: fit-content;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            border: 1px solid rgba(127, 182, 133, 0.38);
            color: #447a4c;
            background: rgba(232, 244, 231, 0.9);
        }
        .history-result {
            margin: 0;
            font-size: 1.06rem;
            line-height: 1.2;
            font-weight: 800;
            color: #214628;
        }
        .history-byline {
            margin: 0;
            font-size: 0.8rem;
            color: #607464;
            line-height: 1.4;
        }
        .history-thumb {
            width: 70px;
            height: 70px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid rgba(127, 182, 133, 0.35);
            box-shadow: 0 6px 14px rgba(37, 56, 40, 0.12);
            background: #edf5eb;
            flex: 0 0 auto;
        }
        .history-rec-box {
            margin-top: 8px;
            padding: 10px;
            border-radius: 12px;
            border: 1px solid rgba(127, 182, 133, 0.22);
            background: rgba(255, 255, 255, 0.92);
        }
        .history-section-title {
            margin: 0 0 6px;
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #5f7463;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .history-rec-list {
            margin: 0;
            padding-left: 1rem;
            display: grid;
            gap: 3px;
            font-size: 0.86rem;
            color: #35523b;
            line-height: 1.38;
        }
        .history-guides {
            margin-top: 10px;
            display: grid;
            gap: 8px;
        }
        .history-guide-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }
        .history-guide-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px;
            border-radius: 999px;
            border: 1px solid rgba(127, 182, 133, 0.28);
            background: #ffffff;
            color: #2f5a36;
            font-size: 0.78rem;
            font-weight: 700;
            line-height: 1.2;
        }
        .save-toast {
            position: fixed;
            right: 16px;
            bottom: 16px;
            max-width: min(360px, calc(100vw - 24px));
            padding: 11px 14px;
            border-radius: 12px;
            font-size: 0.84rem;
            font-weight: 700;
            color: #fff;
            z-index: 1300;
            opacity: 0;
            pointer-events: none;
            transform: translateY(10px);
            transition: opacity 0.2s ease, transform 0.2s ease;
            box-shadow: 0 12px 24px rgba(16, 36, 20, 0.28);
        }
        .save-toast.show {
            opacity: 1;
            transform: translateY(0);
        }
        .save-toast.ok {
            background: linear-gradient(90deg, #1f8f4f, #2ba860);
        }
        .save-toast.err {
            background: linear-gradient(90deg, #b81840, #cf2e54);
        }
        .banner-box {
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 16px 18px;
            margin-bottom: 18px;
            border-radius: 18px;
            border: 1px solid rgba(127, 182, 133, 0.3);
            background: linear-gradient(135deg, rgba(236, 248, 235, 0.95), rgba(255, 246, 214, 0.92));
            box-shadow: 0 10px 24px rgba(37, 56, 40, 0.08);
        }
        .banner-box::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 5px;
            background: linear-gradient(180deg, #2f8f45, #8db153);
        }
        .banner-box i {
            font-size: 1.35rem;
            color: #5f9a65;
            flex: 0 0 auto;
            margin-top: 2px;
            width: 36px;
            height: 36px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid rgba(127, 182, 133, 0.25);
        }
        .banner-copy {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .banner-title {
            margin: 0;
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #5f9a65;
        }
        .banner-text {
            margin: 0;
            font-size: 0.95rem;
            line-height: 1.5;
            color: #304634;
        }
        .panel-card {
            background: rgba(255, 255, 255, 0.82);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(127, 182, 133, 0.16);
            border-radius: 20px;
            padding: 24px;
            height: auto;
            min-height: 520px;
            position: relative;
            box-shadow: 0 10px 28px rgba(37, 56, 40, 0.08);
        }
        .scanner-layout {
            align-items: flex-start;
        }
        .scan-input-card {
            min-height: 520px;
            height: 520px;
            display: flex;
            flex-direction: column;
        }
        
        /* Loading Overlay */
        #loading-overlay { display: none; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255, 255, 255, 0.9); z-index: 99; border-radius: 12px; flex-direction: column; justify-content: center; align-items: center; }
        .loader-circle { width: 50px; height: 50px; border: 5px solid #f3f3f3; border-top: 5px solid #2d5a27; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .loading-text { margin-top: 15px; color: #2d5a27; font-weight: 600; font-size: 0.9rem; }

        .section-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(127, 182, 133, 0.12);
            color: #2d5a34;
            font-size: 0.74rem;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        .section-kicker i {
            font-size: 0.95rem;
        }
        .section-title {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 700;
            color: #27452c;
            line-height: 1.2;
        }
        .section-subtitle {
            margin: 6px 0 20px;
            color: #6b7c6e;
            font-size: 0.92rem;
            line-height: 1.45;
        }
        .action-btn {
            border: 1px solid rgba(127, 182, 133, 0.22);
            background: linear-gradient(180deg, #ffffff, #f7fbf4);
            border-radius: 14px;
            padding: 13px 14px;
            text-align: left;
            cursor: pointer;
            transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #445646;
            margin-bottom: 0;
            box-shadow: 0 6px 14px rgba(37, 56, 40, 0.05);
        }
        .action-btn:hover {
            transform: translateY(-1px);
            border-color: rgba(127, 182, 133, 0.4);
            box-shadow: 0 10px 18px rgba(37, 56, 40, 0.08);
            background: linear-gradient(180deg, #fff, #eef8ec);
        }
        .action-icon-wrap {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(127, 182, 133, 0.14);
            border: 1px solid rgba(127, 182, 133, 0.28);
            flex: 0 0 auto;
        }
        .action-icon-wrap i {
            font-size: 1.18rem;
            color: #2f8b43;
            margin: 0;
        }
        .action-copy {
            display: flex;
            flex-direction: column;
            gap: 1px;
            min-width: 0;
            flex: 1;
        }
        .action-btn-main {
            font-size: 0.93rem;
            font-weight: 800;
            color: #2e4a31;
            line-height: 1.2;
        }
        .action-btn-hint {
            font-size: 0.78rem;
            color: #69806e;
            line-height: 1.3;
        }
        .action-btn-arrow {
            font-size: 0.9rem;
            color: #7ba484;
            transition: transform 0.16s ease, color 0.16s ease;
            margin-left: auto;
        }
        .action-btn:hover .action-btn-arrow {
            transform: translateX(2px);
            color: #2f8b43;
        }
        .source-options {
            display: grid;
            gap: 12px;
        }
        .left-preview-pane {
            min-height: 320px;
            border-radius: 18px;
            border: 1px dashed rgba(127, 182, 133, 0.35);
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.9), rgba(247, 251, 244, 0.9));
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 12px;
        }
        .left-preview-frame {
            position: relative;
            border-radius: 14px;
            overflow: hidden;
        }
        .left-thinking-note {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 10px;
            padding: 0;
            border: none;
            background: transparent;
            text-align: center;
        }
        .left-loader-circle {
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #2d5a27;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            flex: 0 0 auto;
        }
        .left-loading-text {
            margin: 0;
            color: #2d5a27;
            font-weight: 600;
            font-size: 0.9rem;
            line-height: 1.2;
        }
        .mini-spin {
            width: 14px;
            height: 14px;
            border: 2px solid rgba(45, 90, 39, 0.2);
            border-top-color: #2d5a27;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            flex: 0 0 auto;
        }
        .left-preview-pane.is-processing .left-thinking-note {
            display: inline-flex;
        }
        #readyScanState.is-thinking .empty-kicker,
        #readyScanState.is-thinking .bug-icon,
        #readyScanState.is-thinking .empty-title,
        #readyScanState.is-thinking .empty-subtitle {
            display: none;
        }
        .ready-thinking-inline {
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            max-width: 340px;
            margin-top: 0;
            padding: 16px 14px;
            border-radius: 14px;
            border: 1px solid rgba(127, 182, 133, 0.32);
            background: linear-gradient(180deg, rgba(232, 243, 234, 0.95), rgba(255, 255, 255, 0.92));
            color: #2f5a34;
            text-align: center;
            box-shadow: 0 10px 24px rgba(37, 56, 40, 0.1);
        }
        #readyScanState.is-thinking .ready-thinking-inline {
            display: inline-flex;
        }
        .ready-thinking-title {
            margin: 0;
            font-size: 1rem;
            font-weight: 800;
            color: #2d5a27;
            line-height: 1.2;
        }
        .ready-thinking-sub {
            margin: 0;
            font-size: 0.82rem;
            color: #59705d;
            line-height: 1.45;
        }
        .thinking-dots {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .thinking-dots span {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #2d5a27;
            opacity: 0.28;
            animation: thinkingDot 1s ease-in-out infinite;
        }
        .thinking-dots span:nth-child(2) {
            animation-delay: 0.15s;
        }
        .thinking-dots span:nth-child(3) {
            animation-delay: 0.3s;
        }
        @keyframes thinkingDot {
            0%,
            80%,
            100% {
                transform: scale(0.75);
                opacity: 0.28;
            }
            40% {
                transform: scale(1);
                opacity: 1;
            }
        }
        .scan-line-overlay {
            position: absolute;
            left: 0;
            right: 0;
            top: 0;
            height: 3px;
            border-radius: 999px;
            background: linear-gradient(90deg, rgba(31, 139, 63, 0), rgba(31, 139, 63, 0.95), rgba(31, 139, 63, 0));
            box-shadow: 0 0 0 1px rgba(31, 139, 63, 0.18), 0 0 16px rgba(31, 139, 63, 0.5);
            pointer-events: none;
            opacity: 0;
            transform: translateY(0);
        }
        .camera-live-tip {
            display: none;
            align-items: flex-start;
            gap: 8px;
            padding: 8px 10px;
            margin: 0 0 8px;
            border-radius: 11px;
            border: 1px solid rgba(127, 182, 133, 0.4);
            background: linear-gradient(180deg, rgba(244, 251, 243, 0.94), rgba(232, 244, 230, 0.94));
            box-shadow: 0 8px 18px rgba(22, 44, 27, 0.18);
        }
        .left-preview-pane.is-camera-live .camera-live-tip {
            display: flex;
        }
        .camera-live-tip-icon {
            width: 24px;
            height: 24px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            color: #2b8f43;
            border: 1px solid rgba(127, 182, 133, 0.4);
            font-size: 0.82rem;
            flex: 0 0 auto;
        }
        .camera-live-tip-copy {
            min-width: 0;
        }
        .camera-live-tip-title {
            margin: 0;
            font-size: 0.69rem;
            font-weight: 800;
            letter-spacing: 0.07em;
            text-transform: uppercase;
            color: #48694f;
            line-height: 1.2;
        }
        .camera-live-tip-text {
            margin: 2px 0 0;
            font-size: 0.75rem;
            line-height: 1.35;
            color: #2f4933;
            font-weight: 600;
        }
        .left-preview-pane.is-scanning .scan-line-overlay {
            opacity: 1;
            animation: previewScanLine 1.8s linear infinite;
        }
        @keyframes previewScanLine {
            0% {
                transform: translateY(0);
            }
            100% {
                transform: translateY(247px);
            }
        }
        .left-preview-top {
            display: flex;
            justify-content: flex-end;
        }
        .mini-icon-btn {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            border: 1px solid rgba(127, 182, 133, 0.28);
            background: #fff;
            color: #2f6d38;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 10px rgba(37, 56, 40, 0.1);
            cursor: pointer;
            transition: transform 0.16s ease, box-shadow 0.16s ease;
        }
        .mini-icon-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 7px 14px rgba(37, 56, 40, 0.14);
        }
        .mini-icon-btn i {
            font-size: 1rem;
            line-height: 1;
        }
        .left-preview-media {
            width: 100%;
            min-height: 250px;
            max-height: 250px;
            object-fit: cover;
            border-radius: 14px;
            border: 2px solid rgba(45, 90, 39, 0.36);
            box-shadow: 0 10px 24px rgba(37, 56, 40, 0.12);
            background: #f1f7ef;
        }
        .scan-btn {
            border-radius: 12px;
            font-size: 0.92rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            min-height: 50px;
            box-shadow: 0 6px 16px rgba(33, 145, 80, 0.18);
        }
        .scan-btn:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            box-shadow: none;
        }
        .empty-state {
            min-height: 320px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 22px 14px;
            border-radius: 18px;
            border: 1px dashed rgba(127, 182, 133, 0.35);
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.9), rgba(247, 251, 244, 0.9));
        }
        .empty-kicker {
            margin: 0 0 10px;
            padding: 5px 10px;
            border-radius: 999px;
            border: 1px solid rgba(127, 182, 133, 0.35);
            background: rgba(255, 255, 255, 0.72);
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #628468;
        }
        .bug-icon {
            width: 78px;
            height: 78px;
            font-size: 1.9rem;
            color: #4f8f59;
            margin-bottom: 12px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(127, 182, 133, 0.34);
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(232, 243, 234, 0.96));
            box-shadow: 0 8px 18px rgba(37, 56, 40, 0.08);
        }
        .empty-title {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 700;
            color: #27452c;
        }
        .empty-subtitle {
            margin: 6px 0 0;
            max-width: 320px;
            color: #6b7c6e;
            font-size: 0.92rem;
            line-height: 1.5;
        }
        .result-card {
            padding: 16px;
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(127, 182, 133, 0.08), rgba(255, 229, 153, 0.1));
            border: 1px solid rgba(127, 182, 133, 0.2);
            text-align: left;
        }
        .result-panel {
            padding: 16px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(127, 182, 133, 0.16);
            box-shadow: 0 6px 14px rgba(37, 56, 40, 0.05);
        }
        .recommendation-timeline {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .recommendation-step {
            position: relative;
            display: grid;
            grid-template-columns: 34px 1fr;
            gap: 12px;
            align-items: start;
            padding-bottom: 12px;
        }
        .recommendation-step:last-child {
            padding-bottom: 0;
        }
        .recommendation-step:not(:last-child)::after {
            content: "";
            position: absolute;
            left: 16px;
            top: 36px;
            bottom: 3px;
            width: 2px;
            border-radius: 999px;
            background: linear-gradient(180deg, rgba(31, 139, 63, 0.55), rgba(31, 139, 63, 0.18));
        }
        .recommendation-index {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            border: 2px solid rgba(31, 139, 63, 0.45);
            background: linear-gradient(180deg, #ffffff, #eaf5e7);
            color: #1f8b3f;
            font-size: 0.85rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 3px 10px rgba(31, 139, 63, 0.12);
            line-height: 1;
            z-index: 1;
        }
        .recommendation-text {
            margin: 5px 0 0;
            font-size: 0.9rem;
            line-height: 1.45;
            color: #36523a;
            font-weight: 500;
        }
        .result-label {
            margin: 0 0 6px;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #6b7c6e;
        }
        .result-guide-btn {
            border-radius: 12px;
            font-size: 0.92rem;
            background: linear-gradient(90deg, #219150 0%, #2ecc71 100%);
            border: none;
            box-shadow: 0 6px 16px rgba(33, 145, 80, 0.18);
        }
        .related-guides-wrap {
            margin-top: 12px;
            padding: 12px;
            border-radius: 12px;
            border: 1px solid rgba(127, 182, 133, 0.2);
            background: rgba(255, 255, 255, 0.88);
            text-align: left;
        }
        .related-guides-title {
            margin: 0 0 8px;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #5f6f63;
        }
        .related-guides-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 8px;
        }
        .related-guide-item {
            border: 1px solid rgba(127, 182, 133, 0.18);
            background: #fff;
            border-radius: 10px;
            padding: 8px 10px;
        }
        .related-guide-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            color: #1f8b3f;
            font-weight: 700;
            font-size: 0.85rem;
        }
        .related-guide-link:hover {
            text-decoration: underline;
            color: #166534;
        }
        .related-guide-desc {
            margin: 4px 0 0;
            font-size: 0.78rem;
            color: #607164;
            line-height: 1.35;
        }
        .result-disclaimer {
            margin-top: 14px;
            padding: 12px 13px;
            border-radius: 12px;
            border: 1px solid rgba(217, 178, 74, 0.35);
            background: linear-gradient(180deg, rgba(255, 249, 230, 0.95), rgba(255, 255, 255, 0.96));
            color: #6a5a2a;
            font-size: 0.8rem;
            line-height: 1.45;
            text-align: left;
        }
        .disclaimer-title {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin: 0;
            font-size: 0.74rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #7a672f;
        }
        .disclaimer-title i {
            font-size: 0.88rem;
        }
        .disclaimer-text {
            margin: 6px 0 0;
            color: #6a5a2a;
            font-size: 0.8rem;
            line-height: 1.45;
        }
        #camera-video {
            width: 100%;
            border-radius: 14px;
            display: none;
            border: 2px solid rgba(45, 90, 39, 0.7);
            max-height: 260px;
            object-fit: cover;
            box-shadow: 0 10px 24px rgba(37, 56, 40, 0.14);
            background: #102112;
        }
        @media (max-width: 767px) {
            .head-inner { padding-left: 14px; padding-right: 14px; }
            .page-title { font-size: 1.3rem; }
            .banner-box {
                padding: 14px;
                margin-bottom: 14px;
            }
            .banner-box i {
                width: 32px;
                height: 32px;
                font-size: 1.1rem;
            }
            .banner-text {
                font-size: 0.88rem;
            }
            .scan-input-card {
                min-height: auto;
                height: auto;
            }
            .action-btn {
                padding: 12px;
            }
            .action-icon-wrap {
                width: 38px;
                height: 38px;
            }
            .action-btn-main {
                font-size: 0.89rem;
            }
            .action-btn-hint {
                font-size: 0.74rem;
            }
            .camera-live-tip {
                padding: 7px 8px;
                gap: 6px;
                margin-bottom: 7px;
            }
            .camera-live-tip-icon {
                width: 22px;
                height: 22px;
                font-size: 0.75rem;
            }
            .camera-live-tip-title {
                font-size: 0.64rem;
            }
            .camera-live-tip-text {
                font-size: 0.7rem;
            }
            .left-preview-pane {
                min-height: 250px;
            }
            .left-preview-media {
                min-height: 190px;
                max-height: 190px;
            }
            @keyframes previewScanLine {
                0% {
                    transform: translateY(0);
                }
                100% {
                    transform: translateY(187px);
                }
            }
        }
        .modal-mask {
            position: fixed;
            inset: 0;
            background: rgba(20, 29, 23, 0.4);
            backdrop-filter: blur(3px);
            z-index: 2000;
            display: none;
        }
        .modal-mask.show {
            display: block;
        }
        .custom-confirm-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.98);
            background: #fff;
            border-radius: 14px;
            width: min(92vw, 380px);
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            z-index: 2001;
            padding: 24px;
            opacity: 0;
            pointer-events: none;
            transition: all 0.2s ease;
        }
        .custom-confirm-modal.show {
            opacity: 1;
            pointer-events: auto;
            transform: translate(-50%, -50%) scale(1);
        }
        .confirm-title {
            margin: 0 0 10px;
            font-weight: 700;
            font-size: 1.2rem;
            color: #2c3e50;
        }
        .confirm-desc {
            margin: 0 0 20px;
            color: #5f6f63;
            font-size: 0.95rem;
            line-height: 1.5;
        }
        .confirm-btn-save {
            background-color: #ef4444 !important;
            border-color: #ef4444 !important;
            color: #fff !important;
        }
    </style>
</head>
<body>

<header class="page-head">
    <div class="head-inner">
        <div class="head-row">
            <button class="back-ghost" type="button" aria-label="Back" onclick="window.history.back();">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 5-7 7 7 7 1.5-1.5-5.5-5.5 5.5-5.5z"></path></svg>
            </button>
            <div class="head-title-wrap">
                <h1 class="page-title">Corn Scanner AI Pro (V3)</h1>
                <p class="page-sub">Identifying Diseases & Pests in Real-time</p>
            </div>
            <button id="openHistoryBtn" class="history-icon-btn" type="button" aria-label="Open saved history" title="Saved History">
                <i class="bi bi-clock-history"></i>
            </button>
        </div>
    </div>
</header>

<div class="container py-4">
    <div class="row g-4 scanner-layout">
        <div class="col-lg-6">
            <div class="panel-card shadow-sm scan-input-card">
                <div class="section-kicker"><i class="bi bi-upload"></i> Scan Input</div>
                <h2 class="section-title">Input Source</h2>
                <p class="section-subtitle">Choose how you want to analyze the plant.</p>

                <form action="" method="POST" enctype="multipart/form-data" id="mainForm">
                    <input type="hidden" name="form_action" value="analyze">
                    <input type="hidden" name="camera_data" id="camera_data">
                    <input id="galleryInput" type="file" name="image" accept="image/*" hidden onchange="queueGalleryImage(event)">

                    <div id="sourceOptions" class="source-options">
                        <label class="action-btn" for="galleryInput">
                            <span class="action-icon-wrap"><i class="bi bi-upload"></i></span>
                            <span class="action-copy">
                                <span class="action-btn-main">Upload from Gallery</span>
                                <span class="action-btn-hint">Use a clear photo from your gallery.</span>
                            </span>
                            <i class="bi bi-chevron-right action-btn-arrow" aria-hidden="true"></i>
                        </label>
                        <button type="button" class="action-btn w-100" onclick="startCamera()">
                            <span class="action-icon-wrap"><i class="bi bi-camera"></i></span>
                            <span class="action-copy">
                                <span class="action-btn-main">Open Camera</span>
                                <span class="action-btn-hint">Capture a live image for instant analysis.</span>
                            </span>
                            <i class="bi bi-chevron-right action-btn-arrow" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div id="leftPreviewPane" class="left-preview-pane d-none">
                        <div class="left-preview-top">
                            <button id="leftTopActionBtn" type="button" class="mini-icon-btn d-none" onclick="handleTopAction()" aria-label="Change source">
                                <i class="bi bi-arrow-repeat"></i>
                            </button>
                        </div>
                        <div class="camera-live-tip" aria-hidden="true">
                            <span class="camera-live-tip-icon"><i class="bi bi-sun"></i></span>
                            <div class="camera-live-tip-copy">
                                <p class="camera-live-tip-title">Camera Tip</p>
                                <p class="camera-live-tip-text">Ensure good lighting and keep the affected leaf or pest in clear focus.</p>
                            </div>
                        </div>
                        <div class="left-preview-frame">
                            <video id="leftPreviewVideo" class="left-preview-media d-none" autoplay playsinline></video>
                            <img id="leftPreviewImage" class="left-preview-media d-none" alt="Image preview before scan">
                            <div class="scan-line-overlay" aria-hidden="true"></div>
                        </div>
                        <div id="leftThinkingNote" class="left-thinking-note" role="status" aria-live="polite" aria-atomic="true">
                            <span class="left-loader-circle" aria-hidden="true"></span>
                            <span class="left-loading-text">AI is thinking...</span>
                        </div>
                    </div>

                    <button id="leftActionBtn" type="button" class="btn btn-success w-100 mt-2 scan-btn d-none" onclick="handleLeftAction()">Scan</button>
                </form>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="panel-card shadow-sm">
                <div id="loading-overlay">
                    <div class="loader-circle"></div>
                    <div class="loading-text">AI is thinking...</div>
                </div>

                <div class="section-kicker"><i class="bi bi-clipboard2-pulse"></i> Output</div>
                <h2 class="section-title">Results & Advice</h2>
                <p class="section-subtitle">Automated diagnosis and practical next steps based on the scan.</p>

                <div class="text-center">
                    <?php if ($display_image_src): ?>
                        <img src="<?= htmlspecialchars($display_image_src) ?>" class="img-fluid rounded-4 mb-3 border shadow-sm" style="max-height: 240px; width: 100%; object-fit: cover; border-color: rgba(127, 182, 133, 0.2) !important;">
                        
                        <?php if ($prediction_result && isset($prediction_result['class'])): ?>
                            <div class="result-card">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h4 class="fw-bold text-success mb-0"><?= $prediction_result['class'] ?></h4>
                                    <span class="badge bg-success small"><?= $prediction_result['confidence'] ?></span>
                                </div>
                                <div class="progress mt-2" style="height:6px;">
                                    <div class="progress-bar bg-success" style="width: <?= floatval($prediction_result['confidence']) ?>%"></div>
                                </div>
                                <div class="mt-4">
                                    <p class="result-label">Action Recommendation</p>
                                    <div class="result-panel small">
                                        <ol class="recommendation-timeline" aria-label="Action recommendation timeline">
                                            <?php foreach (getRecSteps((string) $prediction_result['class']) as $index => $step): ?>
                                                <li class="recommendation-step">
                                                    <span class="recommendation-index"><?= (int) $index + 1 ?></span>
                                                    <p class="recommendation-text"><?= htmlspecialchars((string) $step) ?></p>
                                                </li>
                                            <?php endforeach; ?>
                                        </ol>
                                    </div>
                                    <div class="mt-3">
                                        <div class="related-guides-wrap">
                                            <p class="related-guides-title">Related Guides</p>
                                            <?php if (!empty($related_guides)): ?>
                                                <ul class="related-guides-list">
                                                    <?php foreach ($related_guides as $guide): ?>
                                                        <?php
                                                            $guideId = (int) ($guide['guide_id'] ?? 0);
                                                            $guideTitle = trim((string) ($guide['module_title'] ?? 'Untitled Guide'));
                                                            $guideDesc = trim((string) ($guide['short_description'] ?? ''));
                                                            $guideCategory = trim((string) ($guide['category'] ?? 'All'));
                                                            $hasGuideFile = trim((string) ($guide['guide_file'] ?? '')) !== '';
                                                            $hasExternalLink = trim((string) ($guide['external_link'] ?? '')) !== '';
                                                            $guideHref = 'corn_farming_guide.php?category=' . urlencode($guideCategory);
                                                            if ($guideId > 0 && ($hasGuideFile || $hasExternalLink)) {
                                                                $guideHref = 'corn_farming_guide.php?guide_id=' . $guideId . '&file_mode=view';
                                                            }
                                                        ?>
                                                        <li class="related-guide-item">
                                                            <a class="related-guide-link" href="<?= htmlspecialchars($guideHref) ?>" target="_blank" rel="noopener noreferrer">
                                                                <i class="bi bi-book-half"></i>
                                                                <span><?= htmlspecialchars($guideTitle) ?></span>
                                                            </a>
                                                            <?php if ($guideDesc !== ''): ?>
                                                                <p class="related-guide-desc"><?= htmlspecialchars($guideDesc) ?></p>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <?php $guideCat = getGuideCategory($prediction_result['class']); ?>
                                                <ul class="related-guides-list">
                                                    <li class="related-guide-item">
                                                        <a class="related-guide-link" href="corn_farming_guide.php?category=<?= urlencode($guideCat) ?>" target="_blank" rel="noopener noreferrer">
                                                            <i class="bi bi-book-half"></i>
                                                            <span>Open matching guides in Farming Guide</span>
                                                        </a>
                                                    </li>
                                                </ul>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php
                                        $actionRecommendedText = implode("\n", getRecSteps((string) $prediction_result['class']));
                                        $guidesPayload = build_related_guides_payload($related_guides);
                                    ?>
                                    <form id="saveResultForm" action="" method="POST" class="mt-3">
                                        <input type="hidden" name="form_action" value="save_result">
                                        <input type="hidden" name="result" value="<?= htmlspecialchars((string) $prediction_result['class']) ?>">
                                        <input type="hidden" name="confidence" value="<?= htmlspecialchars((string) ($prediction_result['confidence'] ?? '')) ?>">
                                        <input type="hidden" name="image" value="<?= htmlspecialchars((string) $display_image_src) ?>">
                                        <input type="hidden" name="image_name" value="<?= htmlspecialchars((string) $display_image_name) ?>">
                                        <textarea name="action_recommended" hidden><?= htmlspecialchars($actionRecommendedText) ?></textarea>
                                        <textarea name="related_guides" hidden><?= htmlspecialchars($guidesPayload) ?></textarea>
                                        <button type="button" class="btn btn-success w-100 mt-2" onclick="showSaveConfirm()"><i class="bi bi-save2 me-2"></i>Save Results</button>
                                    </form>
                                </div>
                            </div>
                        <?php elseif (isset($prediction_result['status']) && $prediction_result['status'] === 'unsupported'): ?>
                            <div class="alert alert-warning small text-start">
                                <strong>Corn-only scan:</strong>
                                <?= htmlspecialchars((string) ($prediction_result['message'] ?? 'Please upload a clear image of a corn plant pest or disease.')) ?>
                            </div>
                        <?php elseif (isset($prediction_result['status']) && $prediction_result['status'] == 'error'): ?>
                            <div class="alert alert-danger small"><?= $prediction_result['message'] ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state" id="readyScanState">
                            <p class="empty-kicker">Output Panel</p>
                            <i class="bi bi-camera2 bug-icon"></i>
                            <p class="empty-title">Ready to scan</p>
                            <p class="empty-subtitle">The analysis will appear here once the image is processed and the model finishes scanning.</p>
                            <div class="ready-thinking-inline" id="readyThinkingInline">
                                <span class="mini-spin" aria-hidden="true"></span>
                                <p class="ready-thinking-title">AI is thinking...</p>
                                <p class="ready-thinking-sub">Scanning and analyzing the image. Please wait a moment.</p>
                                <span class="thinking-dots" aria-hidden="true"><span></span><span></span><span></span></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="result-disclaimer">
                        <p class="disclaimer-title"><i class="bi bi-shield-check" aria-hidden="true"></i>Important Reminder</p>
                        <p class="disclaimer-text">This result is a possible detection based on image analysis only. Please consult an agricultural expert or local technician for confirmation and proper treatment.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal-mask" id="saveConfirmMask"></div>
<div class="custom-confirm-modal" id="saveConfirmModal">
    <h3 class="confirm-title">Save Results</h3>
    <p class="confirm-desc">Are you sure you want to save this analysis result? You will be redirected to the dashboard.</p>
    <div class="d-flex justify-content-end gap-2">
        <button class="btn btn-outline-secondary" onclick="hideSaveConfirm()" type="button" style="border-radius: 10px; font-weight: 600; padding: 8px 16px;">Cancel</button>
        <button class="btn btn-danger confirm-btn-save" onclick="executeSave()" type="button" style="border-radius: 10px; font-weight: 600; padding: 8px 16px; background-color: #ef4444; border: none; color: white;">Save</button>
    </div>
</div>
<div id="historyModal" class="history-modal" aria-hidden="true">
    <div class="history-modal-card" role="dialog" aria-modal="true" aria-labelledby="historyModalTitle">
        <div class="history-modal-head">
            <h5 id="historyModalTitle" class="history-modal-title"><i class="bi bi-clock-history"></i>Saved History</h5>
            <button id="closeHistoryBtn" type="button" class="history-modal-close" aria-label="Close history"><i class="bi bi-x-lg"></i></button>
        </div>

        <?php if (!empty($saved_history)): ?>
            <div class="history-stack">
                <?php foreach ($saved_history as $item): ?>
                    <?php
                        $historyImageSrc = image_value_to_web_src((string) ($item['image'] ?? ''));
                        $rawHistoryGuides = trim((string) ($item['related_guides'] ?? ''));
                        $historyGuides = [];
                        if ($rawHistoryGuides !== '') {
                            $decodedHistoryGuides = json_decode($rawHistoryGuides, true);
                            if (is_array($decodedHistoryGuides)) {
                                foreach ($decodedHistoryGuides as $guideValue) {
                                    if (is_array($guideValue)) {
                                        $text = trim((string) ($guideValue['title'] ?? ''));
                                    } else {
                                        $text = trim((string) $guideValue);
                                    }
                                    if ($text !== '') {
                                        $historyGuides[] = $text;
                                    }
                                }
                            } else {
                                $splitGuides = preg_split('/\s*,\s*/', $rawHistoryGuides);
                                if (is_array($splitGuides)) {
                                    foreach ($splitGuides as $guideValue) {
                                        $text = trim((string) $guideValue);
                                        if ($text !== '') {
                                            $historyGuides[] = $text;
                                        }
                                    }
                                }
                            }
                        }
                        $createdLabel = date('F j, Y g:i A', strtotime((string) ($item['date_created'] ?? 'now')));
                    ?>
                    <div class="history-entry">
                        <div class="history-entry-head">
                            <div class="history-entry-meta">
                                <span class="history-label"><i class="bi bi-save2"></i>Saved Scan</span>
                                <p class="history-result"><?= htmlspecialchars((string) ($item['result'] ?? 'Unknown')) ?></p>
                                <p class="history-byline">Saved by <?= htmlspecialchars((string) ($item['name'] ?? 'Farmer')) ?> • <?= htmlspecialchars($createdLabel) ?></p>
                            </div>
                            <?php if ($historyImageSrc !== ''): ?>
                                <img src="<?= htmlspecialchars($historyImageSrc) ?>" alt="Saved scan" class="history-thumb">
                            <?php endif; ?>
                        </div>
                        <?php
                            $historyRecText = trim((string) ($item['action_recommended'] ?? ''));
                            $historyRecLines = preg_split('/\r\n|\r|\n/', $historyRecText);
                            $historyRecLines = is_array($historyRecLines)
                                ? array_values(array_filter(array_map(static fn($line) => trim((string) $line), $historyRecLines), static fn($line) => $line !== ''))
                                : [];
                        ?>
                        <?php if (!empty($historyRecLines)): ?>
                            <div class="history-rec-box">
                                <p class="history-section-title"><i class="bi bi-shield-check"></i>Action Recommended</p>
                                <ul class="history-rec-list">
                                    <?php foreach ($historyRecLines as $recLine): ?>
                                        <li><?= htmlspecialchars($recLine) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($historyGuides)): ?>
                            <div class="history-guides">
                                <p class="history-section-title mb-0"><i class="bi bi-book"></i>Related Guides</p>
                                <div class="history-guide-chips">
                                    <?php foreach ($historyGuides as $historyGuideText): ?>
                                        <span class="history-guide-chip"><i class="bi bi-journal-text"></i><?= htmlspecialchars((string) $historyGuideText) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="history-empty">No saved pest or disease results yet.</p>
        <?php endif; ?>
    </div>
</div>
<div class="save-toast" id="saveToast" role="status" aria-live="polite" aria-atomic="true"></div>

<script>
    const mainForm = document.getElementById('mainForm');
    const galleryInput = document.getElementById('galleryInput');
    const sourceOptions = document.getElementById('sourceOptions');
    const leftPreviewPane = document.getElementById('leftPreviewPane');
    const leftPreviewVideo = document.getElementById('leftPreviewVideo');
    const leftPreviewImage = document.getElementById('leftPreviewImage');
    const leftTopActionBtn = document.getElementById('leftTopActionBtn');
    const leftActionBtn = document.getElementById('leftActionBtn');
    const leftThinkingNote = document.getElementById('leftThinkingNote');
    const cameraDataInput = document.getElementById('camera_data');
    const loading = document.getElementById('loading-overlay');
    const readyScanState = document.getElementById('readyScanState');
    const openHistoryBtn = document.getElementById('openHistoryBtn');
    const closeHistoryBtn = document.getElementById('closeHistoryBtn');
    const historyModal = document.getElementById('historyModal');
    const saveToast = document.getElementById('saveToast');
    const pageSaveMessage = <?= json_encode((string) $save_message) ?>;
    const pageSaveError = <?= json_encode((string) $save_error) ?>;

    let queuedSource = 'none'; // none | gallery | camera-live | camera-captured
    let queuedGalleryObjectUrl = null;
    let capturedCameraData = '';
    let isSubmittingScan = false;
    let saveToastTimer = null;
    const MIN_LEFT_SCAN_PREVIEW_MS = 900;

    function showSaveToast(message, isError) {
        if (!saveToast || !message) {
            return;
        }

        saveToast.textContent = String(message);
        saveToast.classList.remove('ok', 'err');
        saveToast.classList.add(isError ? 'err' : 'ok');
        saveToast.classList.add('show');

        if (saveToastTimer) {
            window.clearTimeout(saveToastTimer);
        }

        saveToastTimer = window.setTimeout(function () {
            saveToast.classList.remove('show');
        }, 1700);
    }

    function startLoading() {
        if (isSubmittingScan) {
            return;
        }
        isSubmittingScan = true;

        if (leftPreviewPane && !leftPreviewPane.classList.contains('d-none')) {
            leftPreviewPane.classList.add('is-scanning');
            leftPreviewPane.classList.add('is-processing');
        }
        if (leftActionBtn) {
            leftActionBtn.disabled = true;
        }
        if (leftTopActionBtn) {
            leftTopActionBtn.disabled = true;
        }
        if (readyScanState) {
            readyScanState.classList.add('is-thinking');
        }
    }

    function stopCameraStream() {
        if (!leftPreviewVideo || !leftPreviewVideo.srcObject) {
            return;
        }
        const tracks = leftPreviewVideo.srcObject.getTracks();
        for (let i = 0; i < tracks.length; i += 1) {
            tracks[i].stop();
        }
        leftPreviewVideo.srcObject = null;
        leftPreviewVideo.classList.add('d-none');
    }

    function clearQueuedGalleryPreview() {
        if (queuedGalleryObjectUrl) {
            URL.revokeObjectURL(queuedGalleryObjectUrl);
            queuedGalleryObjectUrl = null;
        }
        if (leftPreviewImage) {
            leftPreviewImage.removeAttribute('src');
        }
    }

    function setTopAction(config) {
        if (!leftTopActionBtn) {
            return;
        }

        if (!config) {
            leftTopActionBtn.classList.add('d-none');
            leftTopActionBtn.removeAttribute('data-action');
            leftTopActionBtn.removeAttribute('title');
            leftTopActionBtn.innerHTML = '<i class="bi bi-arrow-repeat"></i>';
            return;
        }

        leftTopActionBtn.classList.remove('d-none');
        leftTopActionBtn.setAttribute('data-action', config.action);
        leftTopActionBtn.setAttribute('title', config.title);
        leftTopActionBtn.setAttribute('aria-label', config.title);
        leftTopActionBtn.innerHTML = '<i class="bi ' + config.icon + '"></i>';
    }

    function setLeftState(mode) {
        queuedSource = mode;

        if (!sourceOptions || !leftPreviewPane || !leftActionBtn || !leftPreviewImage || !leftPreviewVideo) {
            return;
        }

        sourceOptions.classList.toggle('d-none', mode !== 'none');
        leftPreviewPane.classList.toggle('d-none', mode === 'none');
        leftPreviewPane.classList.remove('is-scanning');
        leftPreviewPane.classList.remove('is-processing');
        leftPreviewPane.classList.remove('is-camera-live');
        if (leftActionBtn) {
            leftActionBtn.disabled = false;
        }
        if (leftTopActionBtn) {
            leftTopActionBtn.disabled = false;
        }

        if (mode === 'none') {
            leftActionBtn.classList.add('d-none');
            leftActionBtn.textContent = 'Scan';
            leftPreviewVideo.classList.add('d-none');
            leftPreviewImage.classList.add('d-none');
            setTopAction(null);
            return;
        }

        leftActionBtn.classList.remove('d-none');

        if (mode === 'gallery') {
            leftPreviewVideo.classList.add('d-none');
            leftPreviewImage.classList.remove('d-none');
            leftActionBtn.textContent = 'Scan';
            setTopAction({ action: 'reupload', title: 'Re-upload image', icon: 'bi-upload' });
            return;
        }

        if (mode === 'camera-live') {
            leftPreviewVideo.classList.remove('d-none');
            leftPreviewImage.classList.add('d-none');
            leftActionBtn.textContent = 'Capture';
            leftPreviewPane.classList.add('is-camera-live');
            setTopAction({ action: 'recapture', title: 'Recapture', icon: 'bi-camera' });
            return;
        }

        if (mode === 'camera-captured') {
            leftPreviewVideo.classList.add('d-none');
            leftPreviewImage.classList.remove('d-none');
            leftActionBtn.textContent = 'Scan';
            setTopAction({ action: 'recapture', title: 'Recapture', icon: 'bi-camera' });
        }
    }

    function queueGalleryImage(event) {
        const file = event && event.target && event.target.files ? event.target.files[0] : null;
        if (!file) {
            return;
        }

        stopCameraStream();
        capturedCameraData = '';
        if (cameraDataInput) {
            cameraDataInput.value = '';
        }

        clearQueuedGalleryPreview();

        if (leftPreviewImage) {
            queuedGalleryObjectUrl = URL.createObjectURL(file);
            leftPreviewImage.src = queuedGalleryObjectUrl;
        }

        setLeftState('gallery');
    }

    async function openCameraStream() {
        if (!leftPreviewVideo) {
            alert("Camera preview is not available right now.");
            return;
        }

        try {
            stopCameraStream();
            const s = await navigator.mediaDevices.getUserMedia({video:{facingMode:"environment"}});
            leftPreviewVideo.srcObject = s;
            if (galleryInput) {
                galleryInput.value = '';
            }
            clearQueuedGalleryPreview();
            capturedCameraData = '';
            if (cameraDataInput) {
                cameraDataInput.value = '';
            }
            setLeftState('camera-live');
        } catch (err) {
            alert("Camera access denied or not available.");
        }
    }

    async function startCamera() {
        if (isSubmittingScan) {
            return;
        }

        await openCameraStream();
    }

    function captureCameraFrame() {
        if (!leftPreviewVideo || !leftPreviewVideo.videoWidth || !leftPreviewVideo.videoHeight || !leftPreviewImage) {
            alert("Camera is not ready yet. Please try again.");
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.width = leftPreviewVideo.videoWidth;
        canvas.height = leftPreviewVideo.videoHeight;
        canvas.getContext('2d').drawImage(leftPreviewVideo, 0, 0);
        capturedCameraData = canvas.toDataURL('image/jpeg', 0.72);
        leftPreviewImage.src = capturedCameraData;

        stopCameraStream();
        if (cameraDataInput) {
            cameraDataInput.value = capturedCameraData;
        }

        setLeftState('camera-captured');
    }

    function scanQueuedSource() {
        if (!mainForm || !cameraDataInput) {
            return;
        }

        if (queuedSource === 'gallery') {
            if (!galleryInput || !galleryInput.files || !galleryInput.files[0]) {
                alert("Please select an image from gallery first.");
                return;
            }
            cameraDataInput.value = '';
            startLoading();
            window.setTimeout(function () {
                mainForm.submit();
            }, MIN_LEFT_SCAN_PREVIEW_MS);
            return;
        }

        if (queuedSource === 'camera-captured') {
            if (!capturedCameraData) {
                alert("Please capture image first.");
                return;
            }
            cameraDataInput.value = capturedCameraData;
            startLoading();
            window.setTimeout(function () {
                mainForm.submit();
            }, MIN_LEFT_SCAN_PREVIEW_MS);
            return;
        }

        if (queuedSource === 'camera-live') {
            captureCameraFrame();
            return;
        }

        alert("Please upload from gallery or open camera first.");
    }

    function handleTopAction() {
        if (!leftTopActionBtn) {
            return;
        }

        const action = leftTopActionBtn.getAttribute('data-action');
        if (action === 'reupload') {
            if (galleryInput) {
                galleryInput.value = '';
                galleryInput.click();
            }
            return;
        }

        if (action === 'recapture') {
            startCamera();
        }
    }

    function handleLeftAction() {
        if (queuedSource === 'camera-live') {
            captureCameraFrame();
            return;
        }

        scanQueuedSource();
    }

    window.addEventListener('beforeunload', function () {
        stopCameraStream();
        if (leftThinkingNote) {
            leftThinkingNote.style.display = 'none';
        }
    });

    function openHistoryModal() {
        if (!historyModal) {
            return;
        }
        historyModal.classList.add('show');
        historyModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeHistoryModal() {
        if (!historyModal) {
            return;
        }
        historyModal.classList.remove('show');
        historyModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    if (openHistoryBtn) {
        openHistoryBtn.addEventListener('click', openHistoryModal);
    }
    if (closeHistoryBtn) {
        closeHistoryBtn.addEventListener('click', closeHistoryModal);
    }
    if (historyModal) {
        historyModal.addEventListener('click', function (event) {
            if (event.target === historyModal) {
                closeHistoryModal();
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && historyModal && historyModal.classList.contains('show')) {
            closeHistoryModal();
        }
    });

    if (pageSaveMessage) {
        showSaveToast(pageSaveMessage, false);
    } else if (pageSaveError) {
        showSaveToast(pageSaveError, true);
    }

    function showSaveConfirm() {
        const mask = document.getElementById('saveConfirmMask');
        const modal = document.getElementById('saveConfirmModal');
        if (mask && modal) {
            mask.classList.add('show');
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    }

    function hideSaveConfirm() {
        const mask = document.getElementById('saveConfirmMask');
        const modal = document.getElementById('saveConfirmModal');
        if (mask && modal) {
            mask.classList.remove('show');
            modal.classList.remove('show');
            document.body.style.overflow = '';
        }
    }

    function executeSave() {
        const form = document.getElementById('saveResultForm');
        if (form) {
            form.submit();
        }
    }
</script>
</body>
</html>