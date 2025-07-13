
<?php
/**
 * Ollama Powerhouse Dashboard (Hybrid Engine)
 * Merges file-based server management with a full-featured web UI.
 *
 * @version 1.0
 * @author 10x Ai Evolved Engineer (Google Gemini 2.5)
 */

// start of Configuration Block
define('DASHBOARD_TITLE', 'Ollama Server');
define('MODELS_DIR', __DIR__ . '/models');
define('MODEL_EXTENSIONS', ['gguf', 'bin']);
define('PID_FILE', __DIR__ . '/ollama.pid');
define('OLLAMA_LOG_FILE', __DIR__ . '/ollama.log');
define('CHATS_DIR', __DIR__ . '/chats');
define('SETTINGS_FILE', __DIR__ . '/ollama-dashboard-settings.json');
// end of Configuration Block

// start of Helper Functions
// 🧩 Functionality: Provides foundational utility functions for the dashboard.

// 🎯 Target Function: get_settings_with_auth()
// ✨💻✨: I am re-architecting this function to be self-healing. It will now detect a corrupted or malformed settings file, delete it, and regenerate a valid default file. This permanently resolves the "malformed settings" critical error.
function get_settings_with_auth(): array {
    // 1. Handle case where file does not exist (first run)
    if (!file_exists(SETTINGS_FILE)) {
        $default_preset = [
            'id' => 'default_' . time(),
            'name' => 'Default Preset',
            'temperature' => '0.8',
            'repeat_penalty' => '1.1',
            'top_k' => '40',
            'top_p' => '0.9',
            'system' => 'You are a helpful AI assistant.'
        ];
        $default_settings = [
            'theme' => 'dark',
            'api_password' => '',
            'presets' => [$default_preset],
            'active_preset_id' => $default_preset['id']
        ];
        file_put_contents(SETTINGS_FILE, json_encode($default_settings, JSON_PRETTY_PRINT));
        return $default_settings;
    }

    // 2. Handle case where file exists but may be corrupt
    $settings_content = file_get_contents(SETTINGS_FILE);
    $settings = json_decode($settings_content, true);

    // 3. Validate the decoded settings. Check for JSON errors or missing essential keys.
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($settings) || !isset($settings['presets']) || !isset($settings['active_preset_id'])) {
        // ✨ FIX: File is corrupted. Delete it and recall the function.
        // The next call will trigger the 'file does not exist' block and regenerate defaults.
        @unlink(SETTINGS_FILE);
        return get_settings_with_auth();
    }

    // 4. If validation passes, return the valid settings.
    return $settings;
}

function is_process_running($pid): bool {
    if (empty($pid)) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill(intval($pid), 0);
    }
    return !empty(trim(shell_exec("ps -p " . escapeshellarg($pid) . " -o pid=")));
}

function is_ollama_server_responsive(): bool {
    $ch = curl_init('http://127.0.0.1:11434');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code == 200;
}
// end of Helper Functions


// start of API Router
if (isset($_GET['action'])) {
    error_reporting(0);
    @ini_set('display_errors', 0);
    
    $settings = get_settings_with_auth();
    $api_password = $settings['api_password'] ?? '';
    $payload = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($_GET['action'] === 'stream_task') {
        stream_task_handler($payload);
        exit;
    }

    if (!empty($api_password) && ($payload['auth'] ?? '') !== $api_password) {
        header('Content-Type: application/json'); http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Invalid API Password provided.']); exit;
    }
    unset($payload['auth']);

    $action = $_GET['action'] ?? null;
    if (empty($payload) && isset($_REQUEST['payload'])) { $payload = json_decode($_REQUEST['payload'], true); }
    
    header('Content-Type: application/json');
    $response = [];
    switch ($action) {
        case 'get_initial_state': $response = get_initial_state(); break;
        case 'get_server_status': $response = check_status_api(); break;
        case 'get_models_state': $response = ['installed_models' => list_models_api(), 'running_models' => get_running_models(), 'local_files' => get_managed_models_info()]; break;
        case 'get_chat_list': $response = get_chat_history(); break;
        case 'stop_server': $response = stop_server_api(); break;
        case 'stop_all_tasks': $response = kill_all_ollama_api(); break;
        case 'run_diagnostics': $response = run_diagnostics(); break;
        case 'get_log_content': $response = ['log' => file_exists(OLLAMA_LOG_FILE) ? file_get_contents(OLLAMA_LOG_FILE) : 'Log file not found.']; break;
        case 'delete_model': $response = delete_model($payload['name'] ?? ''); break;
        case 'get_model_details': $response = get_model_details($payload['name'] ?? ''); break;
        case 'upload_gguf': $response = handle_gguf_upload(); break;
        case 'get_chat': $response = get_chat($payload['id'] ?? ''); break;
        case 'create_chat': $response = create_chat(); break;
        case 'delete_chat': $response = delete_chat($payload['id'] ?? ''); break;
        case 'update_chat_metadata': $response = update_chat_metadata($payload); break;
        case 'clear_all_chats': $response = clear_all_chats_api(); break;
        case 'save_assistant_message': $response = save_assistant_message($payload); break;
        case 'save_settings': $response = save_settings($payload); break;
        case 'execute_api_command': $response = execute_api_command($payload); break;
        default:
            http_response_code(400); $response = ['status' => 'error', 'message' => 'Invalid action.'];
    }
    echo json_encode($response);
    exit;
}
// end of API Router


// start of Core Logic & API Functions
function get_initial_state(): array { $status = check_status_api(); return [ 'status' => $status, 'version' => $status['responsive'] ? get_ollama_version() : 'N/A', 'system_info' => get_system_info(), 'installed_models' => $status['responsive'] ? list_models_api() : [], 'running_models' => $status['responsive'] ? get_running_models() : [], 'local_files' => get_managed_models_info(), 'chat_history' => get_chat_history(), 'settings' => get_settings_with_auth(), 'diagnostics' => get_auto_actions() ]; }
function check_status_api(): array { $status = ['running' => false, 'responsive' => false, 'pid' => null]; if (file_exists(PID_FILE)) { $pid = trim(file_get_contents(PID_FILE)); if (is_process_running($pid)) { $status['running'] = true; $status['pid'] = $pid; $status['responsive'] = is_ollama_server_responsive(); } else { @unlink(PID_FILE); } } return $status; }
function get_ollama_version(): string { $version_string = shell_exec('ollama --version 2>/dev/null'); if (empty($version_string)) return 'Unknown'; preg_match('/version is ([\d\.]+)/', $version_string, $matches); return $matches[1] ?? 'Unknown'; }
function get_system_info(): array { $mem = ['total' => 0, 'free' => 0, 'used' => 0]; if (strncasecmp(PHP_OS, 'WIN', 3) !== 0 && function_exists('shell_exec')) { $free = shell_exec('free'); if(!empty($free)){ $free = (string)trim($free); $free_arr = explode("\n", $free); $mem_data = preg_split("/[\s]+/", $free_arr[1]); if(count($mem_data) > 3) { $mem['total'] = round($mem_data[1] / 1024 / 1024, 2); $mem['used'] = round($mem_data[2] / 1024 / 1024, 2); $mem['free'] = round($mem_data[3] / 1024 / 1024, 2); } } } return [ 'php_version' => PHP_VERSION, 'os' => php_uname('s'), 'web_user' => function_exists('exec') ? trim(exec('whoami')) : 'N/A', 'script_path' => __DIR__, 'cpu_load' => function_exists('sys_getloadavg') ? sys_getloadavg()[0] : 'N/A', 'memory' => $mem, 'disk_free' => function_exists('disk_free_space') ? round(disk_free_space(__DIR__) / 1e9, 2) : 'N/A', 'disk_total' => function_exists('disk_total_space') ? round(disk_total_space(__DIR__) / 1e9, 2) : 'N/A' ]; }
function get_script_base_url(): string { $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http'; $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'; $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\'); return "{$protocol}://{$host}{$path}/"; }
function list_models_api(): array { $output = shell_exec('ollama list'); if (empty($output)) return []; $lines = array_filter(explode("\n", trim($output))); if (count($lines) < 2) return []; array_shift($lines); $models = []; foreach ($lines as $line) { $parts = preg_split('/\s{2,}/', trim($line)); if (count($parts) >= 4) { $model_details = json_decode(shell_exec('ollama show '.escapeshellarg($parts[0]).' --json'), true); $models[] = [ 'name' => $parts[0], 'id' => $parts[1], 'size' => (float) preg_replace('/[a-zA-Z]/', '', $parts[2]) * 1e9, 'modified' => $parts[3], 'details' => $model_details['details'] ?? ['family' => 'N/A'] ]; } } return $models; }
function get_running_models(): array { $output = shell_exec('ollama ps'); if (empty($output)) return []; $lines = array_filter(explode("\n", trim($output))); if (count($lines) < 2) return []; array_shift($lines); $names = []; foreach($lines as $line) { $parts = preg_split('/\s+/', $line); if(isset($parts[0])) $names[] = $parts[0]; } return $names; }
function get_managed_models_info(): array { if (!is_dir(MODELS_DIR)) { @mkdir(MODELS_DIR, 0755, true); return []; } $files = scandir(MODELS_DIR); $foundModels = []; foreach ($files as $file) { $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION)); if (in_array($extension, MODEL_EXTENSIONS, true)) { $modelPath = realpath(MODELS_DIR . '/' . $file); $ollamaModelName = pathinfo($modelPath, PATHINFO_FILENAME); $ollamaModelName = preg_replace('/[.\-](f16|f32|q[2-8](_[a-z_0-9k])?)$/i', '', $ollamaModelName); $ollamaModelName = strtolower(str_replace('_', '-', $ollamaModelName)) . ":latest"; $foundModels[] = ['path' => $modelPath, 'name' => $ollamaModelName, 'basename' => basename($modelPath)]; } } return $foundModels; }
function delete_model(string $name): array { if (empty($name)) return ['status' => 'error', 'message' => 'Model name not provided.']; exec('ollama rm ' . escapeshellarg($name) . ' 2>&1', $output, $return_var); if ($return_var === 0) return ['status' => 'success', 'message' => "Model '{$name}' deleted."]; return ['status' => 'error', 'message' => "Failed to delete model '{$name}'.", 'details' => implode("\n", $output)]; }
function get_model_details(string $name): array { if (empty($name) || !is_ollama_server_responsive()) return ['status' => 'error', 'message' => 'Server offline or model name missing.']; $details = json_decode(shell_exec('ollama show ' . escapeshellarg($name) . ' --json'), true); return ['status' => 'success', 'details' => $details]; }
function handle_gguf_upload() : array { if (!isset($_FILES['ggufFile'])) return ['status' => 'error', 'message' => 'No file uploaded.']; if (!is_dir(MODELS_DIR)) @mkdir(MODELS_DIR, 0755, true); $file = $_FILES['ggufFile']; if ($file['error'] !== UPLOAD_ERR_OK) return ['status' => 'error', 'message' => 'File upload error: ' . $file['error']]; $destination = MODELS_DIR . '/' . basename($file['name']); if (move_uploaded_file($file['tmp_name'], $destination)) return ['status' => 'success', 'message' => 'File uploaded. It will be imported on next server start/sync.']; return ['status' => 'error', 'message' => 'Failed to move uploaded file.']; }
function stop_server_api(): array { if (!file_exists(PID_FILE)) return ['status' => 'info', 'message' => 'Server not managed by this script (no PID file).']; $pid = trim(file_get_contents(PID_FILE)); if (is_process_running($pid)) { shell_exec("kill {$pid} 2>/dev/null"); sleep(1); if (is_process_running($pid)) shell_exec("kill -9 {$pid} 2>/dev/null"); } @unlink(PID_FILE); return ['status' => 'success', 'message' => 'Managed server stopped.']; }
function kill_all_ollama_api(): array { $find_pids_command = "ps aux | grep ollama | grep -v grep | awk '{print $2}'"; $pids_string = trim(shell_exec($find_pids_command) ?? ''); if (!empty($pids_string)) shell_exec("kill -9 " . $pids_string); if (file_exists(PID_FILE)) @unlink(PID_FILE); return ['status' => 'success', 'message' => 'All Ollama processes terminated.']; }
function execute_api_command(array $payload) : array { $endpoint = $payload['endpoint'] ?? ''; $json_payload = $payload['payload'] ?? '{}'; if (empty($endpoint)) return ['status' => 'error', 'message' => 'Endpoint cannot be empty.']; $command = "curl -s -X POST http://127.0.0.1:11434/api/{$endpoint} -d " . escapeshellarg($json_payload); $result = shell_exec($command); $json_result = json_decode($result, true); if (json_last_error() === JSON_ERROR_NONE) return ['status' => 'success', 'result' => $json_result, 'curl' => $command]; return ['status' => 'success', 'result' => $result, 'curl' => $command]; }
function run_diagnostics(): array { $results = []; $whoami = trim(shell_exec('whoami')); $results[] = ['check' => 'PHP Version', 'expected' => '>= 7.4', 'actual' => PHP_VERSION, 'status' => version_compare(PHP_VERSION, '7.4.0', '>=') ? 'OK' : 'FAIL', 'fixable' => false]; $results[] = ['check' => 'Ollama Command', 'expected' => 'Found in PATH', 'actual' => trim(shell_exec('command -v ollama')), 'status' => !empty(trim(shell_exec('command -v ollama'))) ? 'OK' : 'FAIL', 'fixable' => true, 'fix_id' => 'install_ollama']; $results[] = ['check' => 'cURL Command', 'expected' => 'Found in PATH', 'actual' => trim(shell_exec('command -v curl')), 'status' => !empty(trim(shell_exec('command -v curl'))) ? 'OK' : 'FAIL', 'fixable' => true, 'fix_id' => 'install_curl']; $results[] = ['check' => 'Git Command', 'expected' => 'Found in PATH', 'actual' => trim(shell_exec('command -v git')), 'status' => !empty(trim(shell_exec('command -v git'))) ? 'OK' : 'FAIL', 'fixable' => true, 'fix_id' => 'install_git']; $results[] = ['check' => 'Web User', 'expected' => 'Identified', 'actual' => $whoami, 'status' => !empty($whoami) ? 'INFO' : 'WARN', 'fixable' => false]; foreach ([MODELS_DIR, CHATS_DIR, __DIR__] as $dir) { if (!is_dir($dir)) @mkdir($dir, 0755, true); $results[] = ['check' => "Dir Writable: " . basename($dir), 'expected' => 'Writable', 'actual' => is_writable($dir) ? 'Writable' : 'Not Writable', 'status' => is_writable($dir) ? 'OK' : 'FAIL', 'fixable' => true, 'fix_id' => 'fix_permissions']; } $results[] = ['check' => 'shell_exec enabled', 'expected' => 'Enabled', 'actual' => function_exists('shell_exec') ? 'Enabled' : 'Disabled', 'status' => function_exists('shell_exec') ? 'OK' : 'FAIL', 'fixable' => false]; return $results; }
function get_auto_actions(): array { $actions = []; if (strpos(php_uname('s'), 'Linux') !== false || strpos(php_uname('s'), 'Darwin') !== false) { $actions[] = ['id' => 'install_ollama', 'title' => '📦 Install Ollama', 'description' => 'Downloads and runs the official Ollama installation script (requires sudo).', 'command' => "curl -fsSL https://ollama.com/install.sh | sh"]; } $pkg_manager_cmd = "if [ -x \"$(command -v apt)\" ]; then sudo apt update && sudo apt install -y %s; elif [ -x \"$(command -v yum)\" ]; then sudo yum install -y %s; elif [ -x \"$(command -v brew)\" ]; then brew install %s; else echo \"No supported package manager found.\"; fi"; $actions[] = ['id' => 'install_curl', 'title' => '📦 Install cURL', 'description' => 'Installs cURL via a supported package manager (apt/yum/brew, requires sudo).', 'command' => sprintf($pkg_manager_cmd, 'curl', 'curl', 'curl')]; $actions[] = ['id' => 'install_git', 'title' => '📦 Install Git', 'description' => 'Installs Git via a supported package manager (apt/yum/brew, requires sudo).', 'command' => sprintf($pkg_manager_cmd, 'git', 'git', 'git')]; $actions[] = ['id' => 'fix_permissions', 'title' => '🛠️ Fix Directory Permissions', 'description' => 'Sets correct owner/permissions for data directories (requires sudo).', 'command' => "chown -R \$(whoami) " . escapeshellarg(MODELS_DIR) . " " . escapeshellarg(CHATS_DIR) . " && chmod -R 755 " . escapeshellarg(MODELS_DIR) . " " . escapeshellarg(CHATS_DIR)]; $actions[] = ['id' => 'clear_ollama_log', 'title' => '🧹 Clear Ollama Log', 'description' => 'Truncates the ollama.log file in the script directory to 0 bytes.', 'command' => "truncate -s 0 " . escapeshellarg(OLLAMA_LOG_FILE)]; $actions[] = ['id' => 'self_update', 'title' => '⬆️ Self-Update Script', 'description' => 'Attempts to update this script by running "git pull" in its directory. Requires .git folder to be present.', 'command' => "cd " . escapeshellarg(__DIR__) . " && git pull"]; return $actions; }
function stream_task_handler(array $payload): void {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    ob_end_flush();

    $send = function($data) {
        echo "data: " . json_encode($data) . "\n\n";
        if(ob_get_level() > 0) ob_flush();
        flush();
    };
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['task_type'] ?? '') === 'chat') {
        $task_type = 'chat';
        $data = json_decode($_GET['payload'] ?? '{}', true);
        $settings = get_settings_with_auth();
        $api_password = $settings['api_password'] ?? '';
        if (!empty($api_password) && ($_GET['auth'] ?? '') !== $api_password) {
            $send(['error' => 'Unauthorized. Invalid API Password provided.']);
            $send(['done' => true]); exit;
        }
    } else {
        $task_type = $payload['task_type'] ?? '';
        $data = $payload['data'] ?? [];
    }

    $command = '';
    switch ($task_type) {
        case 'start_and_import':
            if (file_exists(PID_FILE) && is_process_running(trim(file_get_contents(PID_FILE)))) { $send(['log' => '❌ Server already running.']); break; }
            $send(['log' => '🚀 Launching Ollama server...']);
            $pid = trim(shell_exec("nohup ollama serve > " . escapeshellarg(OLLAMA_LOG_FILE) . " 2>&1 & echo $!"));
            if (empty($pid)) { $send(['log' => '❌ CRITICAL ERROR: Failed to launch process.']); break; }
            file_put_contents(PID_FILE, $pid);
            $send(['log' => "⏳ Process launched with PID: $pid. Waiting for API..."]); sleep(5);
            if (!is_process_running($pid) || !is_ollama_server_responsive()) { $send(['log' => '❌ CRITICAL ERROR: Server is not responsive. Check log file.']); shell_exec("kill -9 {$pid} 2>/dev/null"); @unlink(PID_FILE); break; }
            $send(['log' => "✅ Server active. Syncing models from " . basename(MODELS_DIR) . "/..."]);
            $modelsInfo = get_managed_models_info();
            if (empty($modelsInfo)) { $send(['log' => '✅ No model files found to import.']); break; }
            $installed_models_raw = shell_exec('ollama list');
            foreach ($modelsInfo as $modelInfo) {
                $ollamaModelName = $modelInfo['name'];
                $send(['log' => "\n---\nProcessing: " . $modelInfo['basename']]);
                if (strpos($installed_models_raw, $ollamaModelName) !== false) { $send(['log' => "✅ '$ollamaModelName' already exists. Skipping."]); continue; }
                $send(['log' => "⚙️ Importing '$ollamaModelName'. This may take a while..."]);
                $modelfilePath = sys_get_temp_dir() . '/Modelfile.tmp';
                file_put_contents($modelfilePath, "FROM " . escapeshellarg($modelInfo['path']));
                $createCommand = "ollama create " . escapeshellarg($ollamaModelName) . " -f " . escapeshellarg($modelfilePath);
                $proc = popen($createCommand . ' 2>&1', 'r');
                while ($proc && !feof($proc)) { $line = fgets($proc); if ($line) $send(['log' => trim($line)]); }
                if ($proc) pclose($proc);
                if (file_exists($modelfilePath)) @unlink($modelfilePath);
            }
            $send(['log' => "\n---\n✨ Server sync process complete!"]);
            break;
        case 'chat':
            if (!is_ollama_server_responsive()) { $send(['error' => "Ollama server is not responsive. Please start it on the Server tab."]); break; }
            $chat_payload = $data;
            $chat_payload['stream'] = true;
            
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => 'http://127.0.0.1:11434/api/chat',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($chat_payload),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_WRITEFUNCTION => function($curl_handle, $stream_data) use ($send) {
                    $lines = explode("\n", trim($stream_data));
                    foreach ($lines as $line) {
                        if (empty(trim($line))) continue;
                        $decoded_data = json_decode($line, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                           $send($decoded_data);
                        } else {
                           $send(['error' => 'Received invalid data from Ollama API: ' . trim($line)]);
                        }
                    }
                    return strlen($stream_data);
                }
            ]);
            @curl_exec($curl);
            if (curl_errno($curl)) { $send(['error' => 'cURL Error: ' . curl_error($curl)]); }
            curl_close($curl);
            break;
        case 'pull_model':
            if (empty($data['name'])) { $send(['log' => '❌ Model name cannot be empty.']); break; }
            $command = 'ollama pull ' . escapeshellarg($data['name']); break;
        case 'push_model':
            if (empty($data['source']) || empty($data['destination'])) { $send(['log' => '❌ Source and destination required.']); break; }
            if (strpos($data['destination'], '/') === false && $data['source'] !== $data['destination']) { shell_exec('ollama cp ' . escapeshellarg($data['source']) . ' ' . escapeshellarg($data['destination'])); }
            $command = 'ollama push ' . escapeshellarg($data['destination']); break;
        case 'purge_all':
            if (!is_ollama_server_responsive()) { $send(['log' => "❌ Server is offline."]); break; }
            $models_string = trim(shell_exec("ollama list | grep -v NAME | awk '{print $1}'") ?? '');
            if (empty($models_string)) { $send(['log' => '✅ No models found to purge.']); break; }
            $models_to_purge = array_filter(explode("\n", $models_string));
            $send(['log' => "🔥 Found " . count($models_to_purge) . " model(s) to purge."]);
            foreach ($models_to_purge as $modelName) {
                $send(['log' => " - Deleting '{$modelName}'..."]);
                exec("ollama rm " . escapeshellarg($modelName), $output, $return_var);
                $send(['log' => ($return_var === 0) ? " [OK]" : " [FAILED]"]);
            }
            break;
        case 'execute_auto_action':
            $actions = get_auto_actions();
            $command_to_run = '';
            foreach ($actions as $action) { if ($action['id'] === $data['id']) { $command_to_run = $action['command']; if (in_array($action['id'], ['install_ollama', 'install_curl', 'install_git', 'fix_permissions']) && strpos($command_to_run, 'sudo') !== 0) { $command_to_run = 'sudo ' . $command_to_run; } $command = $command_to_run; break; } }
            if (empty($command)) { $send(['log' => "❌ Unknown action ID."]); }
            break;
    }
    
    if ($command) {
        $proc = popen($command . ' 2>&1', 'r');
        while ($proc && !feof($proc)) {
            $line = fgets($proc);
            if ($line === false) continue;
            $send(['log' => trim($line)]);
        }
        if ($proc) pclose($proc);
    }
    
    $send(['done' => true]);
    exit;
}
function save_settings(array $settings_payload): array { file_put_contents(SETTINGS_FILE, json_encode($settings_payload, JSON_PRETTY_PRINT)); return ['status' => 'success', 'message' => 'Settings saved.', 'settings' => $settings_payload]; }
function get_chat_history(): array { if (!is_dir(CHATS_DIR)) @mkdir(CHATS_DIR, 0755, true); $files = @scandir(CHATS_DIR, SCANDIR_SORT_DESCENDING) ?: []; $history = []; foreach ($files as $file) { if (pathinfo($file, PATHINFO_EXTENSION) === 'json') { $content = json_decode(@file_get_contents(CHATS_DIR . '/' . $file), true); if ($content && isset($content['id'], $content['title'])) $history[] = ['id' => $content['id'], 'title' => $content['title']]; } } return $history; }
function create_chat(): array { if (!is_dir(CHATS_DIR)) @mkdir(CHATS_DIR, 0755, true); $id = 'chat_' . microtime(true) . '_' . bin2hex(random_bytes(4)); $chat = ['id' => $id, 'title' => 'New Conversation', 'created_at' => date('c'), 'messages' => [], 'metadata' => ['model' => '', 'preset_id' => '', 'preset_name' => '']]; file_put_contents(CHATS_DIR . '/' . $id . '.json', json_encode($chat, JSON_PRETTY_PRINT)); return ['status' => 'success', 'chat' => $chat]; }
function get_chat(string $id): ?array { $safe_id = basename($id); if (empty($safe_id)) return null; $file_path = CHATS_DIR . '/' . $safe_id . '.json'; if (!file_exists($file_path) || !is_readable($file_path)) return null; return json_decode(file_get_contents($file_path), true); }
function delete_chat(string $id): array { $safe_id = basename($id); if (empty($safe_id)) return ['status' => 'error']; $file_path = CHATS_DIR . '/' . $safe_id . '.json'; if (file_exists($file_path)) { @unlink($file_path); return ['status' => 'success']; } return ['status' => 'error']; }
function save_assistant_message(array $payload): array { $id = $payload['id'] ?? null; $message = $payload['message'] ?? null; if (!$id || !$message) return ['status' => 'error']; $file_path = CHATS_DIR . '/' . basename($id) . '.json'; if (!file_exists($file_path)) return ['status' => 'error']; $chat_data = json_decode(file_get_contents($file_path), true); $chat_data['messages'][] = $message; file_put_contents($file_path, json_encode($chat_data, JSON_PRETTY_PRINT)); return ['status' => 'success']; }
function update_chat_metadata(array $payload): array { $id = $payload['id'] ?? null; if (!$id) return ['status' => 'error', 'message' => 'ID required.']; $file_path = CHATS_DIR . '/' . basename($id) . '.json'; if (!file_exists($file_path)) return ['status' => 'error', 'message' => 'Chat not found.']; $chat_data = json_decode(file_get_contents($file_path), true); if (isset($payload['title'])) $chat_data['title'] = $payload['title']; if (isset($payload['metadata'])) $chat_data['metadata'] = array_merge($chat_data['metadata'] ?? [], $payload['metadata']); file_put_contents($file_path, json_encode($chat_data, JSON_PRETTY_PRINT)); return ['status' => 'success']; }
function clear_all_chats_api(): array { $files = glob(CHATS_DIR . '/*.json'); $deletedCount = 0; foreach ($files as $file) { if (is_file($file)) { @unlink($file); $deletedCount++; } } return ['status' => 'success', 'message' => "Cleared {$deletedCount} chat(s)."]; }
// end of Core Logic & API Functions
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title><?= htmlspecialchars(DASHBOARD_TITLE ?? 'Ollama Server') ?></title>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.8/dist/purify.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fira+Code&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css" id="prism-theme">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-core.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/autoloader/prism-autoloader.min.js"></script>
    <style>
:root { --font-main: 'Inter', sans-serif; --font-mono: "Fira Code", monospace; --radius: 8px; --transition: all 0.2s ease-in-out; }
:root[data-theme="dark"] { --bg-dark: #11111b; --bg-medium: #181825; --bg-light: #313244; --bg-surface: #1e1e2e; --fg-main: #cdd6f4; --fg-dim: #a6adc8; --fg-subtle: #6c7086; --border-color: #313244; --accent-blue: #89b4fa; --accent-green: #a6e3a1; --accent-red: #f38ba8; --accent-yellow: #f9e2af; --accent-mauve: #cba6f7; --text-on-accent: #11111b; }
:root[data-theme="light"] { --bg-dark: #eff1f5; --bg-medium: #e6e9ef; --bg-light: #dce0e8; --bg-surface: #ffffff; --fg-main: #4c4f69; --fg-dim: #5c5f77; --fg-subtle: #6c6f85; --border-color: #dce0e8; --accent-blue: #1e66f5; --accent-green: #40a02b; --accent-red: #d20f39; --accent-yellow: #df8e1d; --accent-mauve: #8839ef; --text-on-accent: #eff1f5; }
* { box-sizing: border-box; } html { scroll-behavior: smooth; }
body { margin: 0; font-family: var(--font-main); background-color: var(--bg-dark); color: var(--fg-main); font-size: 15px; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; transition: var(--transition); }
.app-container { display: flex; min-height: 100vh; }
.sidebar { width: 250px; background: var(--bg-surface); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; padding: 0.75rem; flex-shrink: 0; transition: var(--transition); }
.sidebar-header { padding: 1rem; margin-bottom: 1rem; text-align: center;} .sidebar-header h1 { font-size: 1.6rem; margin: 0; color: var(--fg-main); font-weight: 700; letter-spacing: 1px; } .sidebar-header .version { font-size: 0.75rem; color: var(--fg-dim); }
.sidebar-nav { flex-grow: 1; }
.sidebar-nav a { display: flex; align-items: center; gap: 0.8rem; padding: 0.85rem 1.25rem; text-decoration: none; color: var(--fg-dim); border-radius: var(--radius); transition: var(--transition); font-weight: 500; margin-bottom: 0.25rem; }
.sidebar-nav a:hover { background: var(--bg-light); color: var(--fg-main); }
.sidebar-nav a.active { background: var(--accent-blue); color: var(--text-on-accent); font-weight: 600; }
.sidebar-nav i { width: 20px; text-align: center; }
.sidebar-footer { margin-top: auto; padding: 1rem 0 0.5rem 0; border-top: 1px solid var(--border-color); }
.main-content { flex-grow: 1; padding: 2rem; overflow-y: auto; }
.view { display: none; animation: fadeIn 0.3s ease-out; } .view.active { display: block; } @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
h2, h3, h4 { color: var(--fg-main); font-weight: 600; } h2 { font-size: 2rem; margin: 0 0 2rem 0; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; font-weight: 700; } h3 { font-size: 1.3rem; margin: 2rem 0 1.25rem 0; } h4 { font-size: 1rem; margin: 1.5rem 0 0.75rem 0; } h3:first-child, h4:first-child { margin-top: 0; }
.content-box { background: var(--bg-surface); padding: 2rem; border-radius: var(--radius); border: 1px solid var(--border-color); } .content-box:not(:last-child) { margin-bottom: 2rem; } .content-box p { color: var(--fg-dim); line-height: 1.6; } .text-justify { text-align: justify; }
button { position: relative; overflow: hidden; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; font-family: var(--font-main); border: 1px solid var(--border-color); border-radius: var(--radius); padding: 0.6rem 1.2rem; font-size: 0.9rem; font-weight: 600; transition: var(--transition); cursor: pointer; user-select: none; background-color: var(--bg-light); color: var(--fg-main); }
button:hover:not(:disabled) { transform: translateY(-2px); border-color: var(--accent-mauve); }
button:active:not(:disabled) { transform: translateY(0); }
button:disabled { opacity: 0.5; cursor: not-allowed; }
.primary-btn { background-color: var(--accent-blue); color: var(--text-on-accent); border-color: var(--accent-blue); }
.danger-btn { background-color: var(--accent-red); color: var(--text-on-accent); border-color: var(--accent-red); }
.warning-btn { background-color: var(--accent-yellow); color: var(--text-on-accent); border-color: var(--accent-yellow); }
.form-group { position: relative; margin-top: 1.25rem; }
select, input, textarea { width: 100%; border: 1px solid var(--border-color); background: var(--bg-medium); padding: 0.9rem; border-radius: var(--radius); transition: var(--transition); color: var(--fg-main); font-family: var(--font-main); font-size: 0.95rem; }
select:disabled, input:disabled, textarea:disabled { opacity: 0.6; cursor: not-allowed; background-color: var(--bg-dark); }
textarea { resize: none; overflow-y: hidden; }
.form-group label { position: absolute; top: 0.9rem; left: 0.9rem; color: var(--fg-dim); pointer-events: none; transition: var(--transition); background: var(--bg-medium); padding: 0 0.5rem; }
input:focus, textarea:focus, select:focus { border-color: var(--accent-blue); outline:none; }
input:focus + label, input:not(:placeholder-shown) + label, textarea:focus + label, textarea:not(:placeholder-shown) + label, .form-group select:focus + label, .form-group select:not(:placeholder-shown) + label { top: -0.65rem; left: 0.75rem; font-size: 0.75rem; color: var(--accent-blue); }
.pre-wrapper { position: relative; margin-top: 1.5rem; } .pre-wrapper pre { margin: 0; } .pre-wrapper .copy-btn { position: absolute; top: 8px; right: 8px; background: var(--bg-dark); color: var(--fg-dim); border: 1px solid var(--border-color); opacity: 0; transition: opacity 0.2s; cursor: pointer; border-radius: 5px; padding: 4px 8px; font-size: 12px; } .pre-wrapper:hover .copy-btn { opacity: 1; }
.modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(17, 17, 27, 0.6); backdrop-filter: blur(5px); display: none; justify-content: center; align-items: center; z-index: 1000; animation: fadeIn 0.3s; } .modal.active { display: flex; }
.modal-content { background: var(--bg-surface); padding: 2rem; border-radius: var(--radius); width: 90%; max-width: 700px; max-height: 85vh; display: flex; flex-direction: column; border: 1px solid var(--border-color); }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }
.stat-card { background: var(--bg-surface); padding: 1.5rem; border-radius: var(--radius); border: 1px solid var(--border-color); transition: var(--transition); }
.stat-card:hover { transform: translateY(-4px); border-color: var(--accent-mauve); }
.stat-card-title { font-size: 0.9rem; font-weight: 500; color: var(--fg-dim); margin: 0 0 0.75rem 0; }
.stat-card-value { font-size: 2.2rem; font-weight: 700; color: var(--fg-main); display: flex; align-items: center;}
.stat-card-value.small { font-size: 1rem; font-weight: 400; align-items: flex-start; flex-direction: column; gap: 0.25rem; line-height: 1.4; word-break: break-all; }
.status-light { width: 12px; height: 12px; border-radius: 50%; margin-right: 0.75rem;} .status-light.running { background-color: var(--accent-green); box-shadow: 0 0 8px var(--accent-green); } .status-light.stopped { background-color: var(--accent-red); } .status-light.pending { background-color: var(--accent-yellow); animation: pulse 1.5s infinite; } @keyframes pulse { 0% { box-shadow: 0 0 0 0 var(--accent-yellow); } 70% { box-shadow: 0 0 0 8px rgba(249, 226, 175, 0); } 100% { box-shadow: 0 0 0 0 rgba(249, 226, 175, 0); } }
.models-list ul { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 1px; background: var(--border-color); border: 1px solid var(--border-color); border-radius: var(--radius); overflow: hidden;}
.models-list li { background: var(--bg-medium); display: grid; grid-template-columns: 1fr auto; align-items: center; gap: 1rem; padding: 1rem 1.25rem; transition: background-color 0.2s; } .models-list li:hover { background-color: var(--bg-light); }
.model-details .name { font-weight: 600; font-size: 1rem; color: var(--fg-main); word-break: break-all; }
.model-details .meta { font-size: 0.8rem; color: var(--fg-dim); margin-top: 0.25rem; display: flex; flex-wrap: wrap; align-items:center; gap: 0.5rem 1rem; }
.model-details .tag { padding: 0.2rem 0.5rem; border-radius: 5px; font-size: 0.7rem; font-weight: 600; color: var(--text-on-accent); }
.tag.in-memory { background-color: var(--accent-green); } .tag.installed { background-color: var(--accent-mauve); } .tag.on-disk { background-color: var(--accent-yellow); }
.model-actions { display: flex; justify-content: flex-end; gap: 0.5rem; } .model-actions button { padding: 0.4rem 0.8rem; flex-shrink: 0; }
#chat-layout { display: flex; gap: 1.25rem; height: calc(100vh - 12rem); }
#chat-sidebar { width: 250px; flex-shrink: 0; background-color: var(--bg-surface); border-radius: var(--radius); display: flex; flex-direction: column; padding: 0.75rem; border: 1px solid var(--border-color); }
#chat-main-panel { flex-grow: 1; display: flex; flex-direction: column; min-width: 0; }
#chat-messages { flex-grow: 1; overflow-y: auto; padding: 1.25rem; background: var(--bg-medium); border-radius: var(--radius); display: flex; flex-direction: column; gap: 1.25rem; margin-bottom: 1rem; }
#chat-header { display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1rem; }
#chat-metadata { flex-grow: 1; padding: 0.5rem 1rem; background-color: var(--bg-medium); border-radius: var(--radius); font-size: 0.8rem; color: var(--fg-dim); text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 1rem;}
.message-container { display: flex; flex-direction: column; } .message-container.user { align-items: flex-end; } .message-container.assistant { align-items: flex-start; }
.message-bubble { max-width: 85%; padding: 0.8rem 1.2rem; border-radius: var(--radius); } .message-container.user .message-bubble { background-color: var(--accent-blue); color: var(--text-on-accent); border-bottom-right-radius: 0; } .message-container.assistant .message-bubble { background-color: var(--bg-light); color: var(--fg-main); border-bottom-left-radius: 0; }
.message-bubble p:first-child { margin-top: 0; } .message-bubble p:last-child { margin-bottom: 0; }
.message-bubble .thinking-cursor { display: inline-block; width: 10px; height: 1.2em; background-color: var(--fg-main); animation: blink 1s step-end infinite; } @keyframes blink { from, to { background-color: transparent; } 50% { background-color: var(--fg-main); } }
.control-group { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; background: var(--bg-surface); padding: 1.25rem; border-radius: var(--radius); border: 1px solid var(--border-color); } .control-group p { text-align: justify; margin: 0; flex-basis: 100%; color: var(--fg-dim); } .control-group.with-p { padding-bottom: 0.75rem; } .control-group .action-area { margin-left: auto; }
#chat-history-list { list-style: none; padding: 0; margin: 1rem 0; flex-grow: 1; overflow-y: auto; }
.history-item { display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1rem; border-radius: var(--radius); cursor: pointer; transition: var(--transition); } .history-item:hover { background-color: var(--bg-light); } .history-item.active { background-color: var(--accent-blue); color: var(--text-on-accent); font-weight: 500; }
.history-item-actions { display: flex; gap: 0.25rem; opacity: 0; transition: var(--transition); } .history-item:hover .history-item-actions { opacity: 1; }
.history-item-btn { background: none; border: none; color: inherit; cursor: pointer; padding: 0.25rem 0.5rem; border-radius: 5px; transition: var(--transition); } .history-item-btn:hover { color: var(--accent-mauve) !important; background-color: rgba(0,0,0,0.1); } .history-item-btn.delete-chat-btn:hover { color: var(--accent-red) !important; }
.history-title { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex-grow: 1; }
#api-history-list { list-style: none; padding: 0; margin-top: 1.5rem; max-height: 250px; overflow-y: auto; } .api-history-item { display: flex; align-items: center; justify-content: space-between; padding: 0.75rem; border-radius: var(--radius); background-color: var(--bg-medium); gap: 0.5rem; } .api-history-item:not(:last-child) { margin-bottom: 0.5rem; } .api-history-item span { font-family: var(--font-mono); font-size: 0.85rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.diag-item { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; padding: 1.25rem; border-bottom: 1px solid var(--border-color); } .diag-item:last-child { border-bottom: none; }
.diag-info strong { color: var(--fg-main); } .diag-info span { color: var(--fg-dim); display: block; margin-top: 0.25rem; word-break: break-all; }
.diag-status { font-weight: bold; padding: 0.2em 0.6em; border-radius: 5px; color: var(--text-on-accent); } .diag-status-OK { background-color: var(--accent-green); } .diag-status-WARN { background-color: var(--accent-yellow); } .diag-status-FAIL { background-color: var(--accent-red); } .diag-status-INFO { background-color: var(--accent-blue); }
.diag-action button.action-required { animation: pulse-yellow 2s infinite; } @keyframes pulse-yellow { 0% { box-shadow: 0 0 0 0 var(--accent-yellow); } 70% { box-shadow: 0 0 0 10px rgba(249, 226, 175, 0); } 100% { box-shadow: 0 0 0 0 rgba(249, 226, 175, 0); } }
.auto-actions-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; }
#toast { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background-color: var(--accent-green); color: var(--text-on-accent); padding: 12px 24px; border-radius: var(--radius); z-index: 2000; opacity: 0; visibility: hidden; transition: opacity 0.3s, visibility 0.3s, transform 0.3s; font-weight: 600; }
</style>
</head>
<body data-theme="dark">
<div class="app-container">
    <nav class="sidebar">
        <div class="sidebar-header"><h1><?= htmlspecialchars(DASHBOARD_TITLE) ?></h1></div>
        <div class="sidebar-nav">
            <a href="#" class="nav-link active" data-view="dashboard"><i class="fa-solid fa-chart-bar"></i><span class="btn-text">Dashboard</span></a>
            <a href="#" class="nav-link" data-view="chat"><i class="fa-solid fa-comments"></i><span class="btn-text">Chat</span></a>
            <a href="#" class="nav-link" data-view="models"><i class="fa-solid fa-box-archive"></i><span class="btn-text">Models</span></a>
            <a href="#" class="nav-link" data-view="server"><i class="fa-solid fa-satellite-dish"></i><span class="btn-text">Server</span></a>
            <a href="#" class="nav-link" data-view="settings"><i class="fa-solid fa-gears"></i><span class="btn-text">Settings</span></a>
            <a href="#" class="nav-link" data-view="api"><i class="fa-solid fa-code"></i><span class="btn-text">API Command</span></a>
            <a href="#" class="nav-link" data-view="diagnostics"><i class="fa-solid fa-wrench"></i><span class="btn-text">Setup & System</span></a>
        </div>
        <div class="sidebar-footer">
            <button id="theme-toggle" style="width:100%"><i class="fa-solid fa-circle-half-stroke"></i><span class="btn-text">Toggle Theme</span></button>
        </div>
    </nav>
    <main class="main-content">
        <section id="view-dashboard" class="view active"><h2>Dashboard</h2><div class="stats-grid"><div class="stat-card"><div class="stat-card-title">Server Status</div><div id="db-server-status" class="stat-card-value"><span id="db-status-light" class="status-light"></span> <span id="db-status-text">Checking...</span></div></div><div class="stat-card"><div class="stat-card-title">Ollama Version</div><div id="db-ollama-version" class="stat-card-value">N/A</div></div><div class="stat-card"><div class="stat-card-title">Installed Models</div><div id="db-models-installed" class="stat-card-value">0</div></div><div class="stat-card"><div class="stat-card-title">Models in Memory</div><div id="db-models-running" class="stat-card-value">0</div></div></div><div class="content-box" style="margin-top: 2rem;"><h3>System Information</h3><div class="stats-grid" id="system-info-grid"></div></div></section>
        <section id="view-chat" class="view"><h2>Chat</h2><div id="chat-layout"><div id="chat-sidebar"><h3>Conversations</h3><ul id="chat-history-list"></ul><div class="sidebar-footer"><button id="new-chat-btn" class="primary-btn" style="width:100%"><i class="fa-solid fa-plus"></i><span class="btn-text">New Chat</span></button><button id="clear-history-btn" class="danger-btn" style="width:100%; margin-top:0.5rem;"><i class="fa-solid fa-trash"></i><span class="btn-text">Clear All</span></button></div></div><div id="chat-main-panel"><div id="chat-header"><div class="form-group" style="flex-grow:1; margin-top:0;"><select id="active-model-select"></select></div><div class="form-group" style="flex-grow:1; margin-top:0;"><select id="chat-preset-select"></select></div></div><div id="chat-metadata"></div><div id="chat-messages"><div class="message-container assistant"><div class="message-bubble">Welcome! Select a model and send a message to begin.</div></div></div>
        <form id="chat-form" style="display:flex; gap:0.75rem;"><div class="form-group" style="flex-grow:1; margin:0;"><textarea id="chat-prompt" rows="1" placeholder=" " required></textarea><label for="chat-prompt">Send a message...</label></div><button type="submit" id="btn-chat-send" class="primary-btn"><i class="fa-solid fa-paper-plane"></i></button></form></div></div></section>
        <section id="view-models" class="view"><h2>Model Management</h2><div class="content-box"><h3>Pull Model from Registry</h3><form id="pull-model-form" style="display:flex; gap: 0.75rem;"><div class="form-group" style="flex-grow:1; margin:0;"><input type="text" id="pull-model-name" placeholder=" " required><label for="pull-model-name">e.g., llama3:latest</label></div><button type="submit" class="primary-btn"><i class="fa-solid fa-download"></i><span class="btn-text">Pull</span></button></form></div><div class="content-box models-list"><div style="display:flex; justify-content: space-between; align-items:center;"><h3 style="margin:0; padding:0; border:0;">Local & Installed Models</h3><div style="display:flex; gap:0.5rem;"><button id="btn-refresh-models" title="Refresh list"><i class="fa-solid fa-rotate"></i><span class="btn-text">Refresh</span></button><button id="btn-upload-gguf" class="primary-btn"><i class="fa-solid fa-upload"></i><span class="btn-text">Upload GGUF</span></button></div><input type="file" id="gguf-file-input" accept=".gguf,.bin" style="display:none;"></div><ul id="installed-model-list" style="margin-top:1.5rem"></ul></div></section>
        <section id="view-server" class="view"><h2>Server Control</h2><div class="content-box"><div class="control-group"><div id="server-status-indicator" class="stat-card-value" style="font-size: 1.1rem;"><span id="server-status-light" class="status-light"></span><span id="server-status-text">Checking...</span></div><div class="action-area" style="display:flex;gap:0.5rem;"><button id="btn-start-server" class="primary-btn"><i class="fa-solid fa-play"></i><span class="btn-text">Start / Sync Server</span></button><button id="btn-stop-server" class="warning-btn"><i class="fa-solid fa-stop"></i><span class="btn-text">Stop Server</span></button></div></div><div class="control-group with-p"><p>Terminate all background Ollama processes and tasks on the system immediately. This is a force-kill and is more aggressive than stopping the server.</p><div class="action-area"><button id="btn-stop-all-tasks" class="danger-btn"><i class="fa-solid fa-ban"></i><span class="btn-text">Kill All</span></button></div></div><div class="control-group with-p"><p>Permanently delete ALL models currently installed and recognized by Ollama. This action cannot be undone and will require re-pulling or re-importing models.</p><div class="action-area"><button id="btn-purge-all" class="danger-btn"><i class="fa-solid fa-skull"></i><span class="btn-text">Purge All</span></button></div></div></div><div class="content-box"><h3>Server Log <button id="btn-refresh-log" style="padding: 0.3rem 0.6rem; font-size: 0.8rem; margin-left: 1rem;"><i class="fa-solid fa-rotate"></i></button></h3><pre id="server-log-output">Server log will appear here.</pre></div></section>
        <section id="view-settings" class="view"><h2>Settings</h2><div class="content-box">
            <h3>Preset Management</h3>
            <div style="display:flex; gap:1rem; align-items:center;">
                <div class="form-group" style="flex-grow:1; margin-top:0;">
                    <select id="setting-preset-select"></select>
                </div>
                <button id="btn-save-preset" class="primary-btn" disabled><i class="fa-solid fa-save"></i><span class="btn-text">Save</span></button>
                <button id="btn-save-preset-as-new"><i class="fa-solid fa-plus"></i><span class="btn-text">Save As New</span></button>
                <button id="btn-delete-preset" class="danger-btn"><i class="fa-solid fa-trash"></i></button>
            </div>
            <form id="settings-form">
                <h3 style="margin-top: 2.5rem;">Chat Parameters</h3><p class="text-justify">Set the parameters for the selected preset.</p>
                <div class="form-group"><input type="text" id="setting-preset-name" placeholder=" " required><label for="setting-preset-name">Preset Name</label></div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;"><div class="form-group"><input type="number" id="setting-temperature" step="0.1" min="0" max="2" placeholder=" "><label for="setting-temperature">Temperature</label></div><div class="form-group"><input type="number" id="setting-repeat_penalty" step="0.1" min="0" placeholder=" "><label for="setting-repeat_penalty">Repeat Penalty</label></div><div class="form-group"><input type="number" id="setting-top_k" step="1" min="0" placeholder=" "><label for="setting-top_k">Top K</label></div><div class="form-group"><input type="number" id="setting-top_p" step="0.05" min="0" max="1" placeholder=" "><label for="setting-top_p">Top P</label></div></div><div class="form-group"><textarea id="setting-system" rows="4" placeholder=" "></textarea><label for="setting-system">System Prompt</label></div>
            </form>
        </div>
        <div class="content-box">
            <h3>Global Settings</h3>
            <div class="form-group"><input type="password" id="setting-api_password" placeholder=" "><label for="setting-api_password">API Password (optional)</label></div>
            <button id="btn-save-globals" class="primary-btn" style="margin-top:1.5rem;"><i class="fa-solid fa-save"></i><span class="btn-text">Save Global Settings</span></button>
        </div>
        </section>
        <section id="view-api" class="view"><h2>API Command Center</h2>
            <div class="content-box">
                <div style="display:flex; justify-content: space-between; align-items: center;"><h3>Universal API & Admin Executor</h3><button id="btn-show-api-docs"><i class="fa-solid fa-book"></i><span class="btn-text">External API Guide</span></button></div>
                <p class="text-justify">Directly interact with the Ollama API. Select an endpoint, fill in the JSON payload, and execute. The cURL command for your request will be generated below for external use.</p>
                <form id="api-command-form">
                    <div style="display:flex; gap: 0.5rem; align-items: center;"><div class="form-group" style="flex-grow:1; margin:0;"><select id="api-endpoint-select"><option>tags</option><option>ps</option><option>show</option><option>delete</option><option>copy</option><option>pull</option><option>push</option><option>generate</option><option>chat</option></select></div><button type="button" id="btn-prettify-json" title="Format JSON"><i class="fa-solid fa-align-left"></i></button></div>
                    <div class="form-group"><textarea id="api-command-payload" rows="8" placeholder=" "></textarea><label for="api-command-payload">JSON Payload</label></div>
                    <div style="display:flex; justify-content: space-between; align-items: center; margin-top:1.5rem;"><button type="submit" class="primary-btn"><i class="fa-solid fa-bolt"></i><span class="btn-text">Execute Command</span></button><span id="api-command-status" style="font-family: var(--font-mono); color: var(--fg-dim);">Status: Idle</span></div>
                </form>
                <div class="pre-wrapper"><h4>Generated cURL (for internal use)</h4><pre id="api-curl-example"></pre><button class="copy-btn"><i class="fa-solid fa-copy"></i> Copy</button></div>
                <div class="pre-wrapper"><h4>Execution Result</h4><pre id="api-command-output">Result will appear here...</pre><button class="copy-btn"><i class="fa-solid fa-copy"></i> Copy</button></div>
                <div id="external-api-docs" style="display:none; margin-top: 2rem; border-top: 1px solid var(--border-color); padding-top: 2rem;">
                    <h3>Calling the Dashboard Proxy API Externally</h3>
                    <p class="text-justify">This dashboard provides a streaming API endpoint that proxies requests to Ollama, respecting the server state managed here. This is ideal for building external applications that need to interact with your local models without managing the Ollama server process themselves.</p>
                    <h4>Endpoint</h4><pre><code><?= htmlspecialchars(get_script_base_url()) . basename(__FILE__) . '?action=stream_task' ?></code></pre>
                    <h4>Payload Structure (POST Body)</h4><pre><code class="language-json">{ "task_type": "chat", "auth": "YOUR_API_PASSWORD", "data": { "model": "model-name:latest", "messages": [ { "role": "user", "content": "Why is the sky blue?" } ] } }</code></pre>
                </div>
            </div>
            <div class="content-box"><h3>API Command History</h3><ul id="api-history-list"><li>No commands in history.</li></ul></div>
        </section>
        <section id="view-diagnostics" class="view"><h2>Setup & System Automation</h2>
             <div class="content-box"><h3>Automated Actions</h3><p class="text-justify">Use these one-click actions to install, configure, and repair your dashboard environment. Actions requiring elevated permissions (sudo) are run at your own risk and may prompt for a password if your web user (`<?= htmlspecialchars(trim(shell_exec('whoami'))) ?>`) does not have passwordless sudo configured.</p><div class="auto-actions-grid" id="auto-actions-container"></div></div>
            <div class="content-box"><h3>System Health Check</h3><p class="text-justify">This tool checks for common configuration and permission issues. Problems marked with <span class="diag-status-FAIL" style="color:var(--text-on-accent); padding:2px 5px; border-radius:3px;">FAIL</span> may prevent the dashboard from working correctly and may offer an automatic fix.</p><div class="control-group" style="margin-bottom:0;"><span>Verify critical paths and PHP settings.</span><button id="btn-run-diagnostics" class="primary-btn"><i class="fa-solid fa-heart-pulse"></i><span class="btn-text">Run Checks</span></button></div><div id="diagnostics-results" style="margin-top:1.5rem; display:none; background: var(--bg-surface); border-radius:var(--radius); padding: 0.5rem 0;"></div></div>
        </section>
    </main>
</div>
<div id="progress-modal" class="modal"><div class="modal-content"><div style="display:flex; justify-content: space-between; align-items: center;"><h3 id="progress-title" style="margin:0;">Task in Progress...</h3><button id="progress-stop-btn" class="danger-btn"><i class="fa-solid fa-ban"></i><span class="btn-text">Stop Task</span></button></div><pre id="progress-log" style="margin-top:1.5rem; flex-grow:1; white-space: pre-wrap; word-break: break-all;">Starting...</pre><div style="text-align:right; margin-top:1rem;"><button id="progress-close-btn" style="display:none;" class="primary-btn"><i class="fa-solid fa-check"></i><span class="btn-text">Close</span></button></div></div></div>
<div id="model-details-modal" class="modal"><div class="modal-content"><h3 id="model-details-title">Model Details</h3><h4 style="margin-top:2rem;">Parameters</h4><pre id="model-details-params"></pre><h4>Modelfile</h4><pre id="model-details-modelfile"></pre><div style="text-align:right; margin-top:1rem;"><button id="model-details-close-btn"><span class="btn-text">Close</span></button></div></div></div>
<div id="push-model-modal" class="modal"><div class="modal-content"><form id="push-model-form"><h3 id="push-model-title">Push Model to Registry</h3><p>Push model <strong id="push-model-source-name"></strong> to a registry.</p><div class="form-group"><input type="text" id="push-model-destination" required placeholder=" "><label for="push-model-destination">Destination Name (e.g., user/model)</label></div><div style="text-align:right; margin-top:1.5rem; display:flex; gap:0.5rem; justify-content:flex-end;"><button type="button" id="push-model-cancel-btn"><span class="btn-text">Cancel</span></button><button type="submit" class="primary-btn"><i class="fa-solid fa-upload"></i><span class="btn-text">Push</span></button></div></form></div></div>
<div id="toast"></div>

<!-- ✨💻✨ 10x Evolved Engineer: Final Powerhouse JS Logic v14.5.0 (Self-Healing Settings & Chat Fix) -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    // 🎯 Target: Central state object for the entire application.
    const state = { server: { running: false, responsive: false, pid: null, version: 'N/A' }, systemInfo: {}, models: { installed: [], running: [], localFiles: [] }, chat: { currentId: null, metadata: {}, messages: [], history: [], isStreaming: false }, settings: { presets: [], active_preset_id: null }, diagnostics: { checks: [], actions: [] }, apiHistory: [], currentStreamController: null, };
    // 🎯 Target: Single object holding all DOM element references for efficiency.
    const dom = { navLinks: document.querySelectorAll('.nav-link'), views: document.querySelectorAll('.view'), dbStatusLight: document.getElementById('db-status-light'), dbStatusText: document.getElementById('db-status-text'), dbOllamaVersion: document.getElementById('db-ollama-version'), dbModelsInstalled: document.getElementById('db-models-installed'), dbModelsRunning: document.getElementById('db-models-running'), systemInfoGrid: document.getElementById('system-info-grid'), serverStatusLight: document.getElementById('server-status-light'), serverStatusText: document.getElementById('server-status-text'), btnStartServer: document.getElementById('btn-start-server'), btnStopServer: document.getElementById('btn-stop-server'), btnStopAllTasks: document.getElementById('btn-stop-all-tasks'), btnPurgeAll: document.getElementById('btn-purge-all'), serverLogOutput: document.getElementById('server-log-output'), btnRefreshLog: document.getElementById('btn-refresh-log'), pullModelForm: document.getElementById('pull-model-form'), installedModelList: document.getElementById('installed-model-list'), btnRefreshModels: document.getElementById('btn-refresh-models'), btnUploadGGUF: document.getElementById('btn-upload-gguf'), ggufFileInput: document.getElementById('gguf-file-input'), activeModelSelect: document.getElementById('active-model-select'), chatHistoryList: document.getElementById('chat-history-list'), newChatBtn: document.getElementById('new-chat-btn'), clearHistoryBtn: document.getElementById('clear-history-btn'), chatMessages: document.getElementById('chat-messages'), chatForm: document.getElementById('chat-form'), chatPrompt: document.getElementById('chat-prompt'), btnChatSend: document.getElementById('btn-chat-send'), chatPresetSelect: document.getElementById('chat-preset-select'), chatMetadata: document.getElementById('chat-metadata'), settingsForm: document.getElementById('settings-form'), settingPresetSelect: document.getElementById('setting-preset-select'), btnSavePreset: document.getElementById('btn-save-preset'), btnSavePresetAsNew: document.getElementById('btn-save-preset-as-new'), btnDeletePreset: document.getElementById('btn-delete-preset'), settingApiPassword: document.getElementById('setting-api_password'), btnSaveGlobals: document.getElementById('btn-save-globals'), apiCommandForm: document.getElementById('api-command-form'), apiEndpointSelect: document.getElementById('api-endpoint-select'), apiCommandPayload: document.getElementById('api-command-payload'), btnPrettifyJson: document.getElementById('btn-prettify-json'), apiCurlExample: document.getElementById('api-curl-example'), apiCommandOutput: document.getElementById('api-command-output'), apiCommandStatus: document.getElementById('api-command-status'), apiHistoryList: document.getElementById('api-history-list'), btnShowApiDocs: document.getElementById('btn-show-api-docs'), externalApiDocs: document.getElementById('external-api-docs'), btnRunDiagnostics: document.getElementById('btn-run-diagnostics'), diagnosticsResults: document.getElementById('diagnostics-results'), autoActionsContainer: document.getElementById('auto-actions-container'), progressModal: document.getElementById('progress-modal'), progressTitle: document.getElementById('progress-title'), progressLog: document.getElementById('progress-log'), progressCloseBtn: document.getElementById('progress-close-btn'), progressStopBtn: document.getElementById('progress-stop-btn'), modelDetailsModal: document.getElementById('model-details-modal'), modelDetailsTitle: document.getElementById('model-details-title'), modelDetailsParams: document.getElementById('model-details-params'), modelDetailsModelfile: document.getElementById('model-details-modelfile'), modelDetailsCloseBtn: document.getElementById('model-details-close-btn'), pushModelModal: document.getElementById('push-model-modal'), pushModelForm: document.getElementById('push-model-form'), pushModelSourceName: document.getElementById('push-model-source-name'), pushModelDestination: document.getElementById('push-model-destination'), pushModelCancelBtn: document.getElementById('push-model-cancel-btn'), themeToggle: document.getElementById('theme-toggle'), toast: document.getElementById('toast'), };
    
    // 🧩 Functionality: Handles all communication with the PHP backend.
    const api = { 
        async call(action, payload = {}) { try { if (action !== 'get_initial_state') payload.auth = state.settings?.api_password || ''; const response = await fetch(`?action=${action}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }); const text = await response.text(); if (!response.ok) { let errorMsg = `API Error ${response.status}`; try { const errJson = JSON.parse(text); errorMsg = errJson.message || errorMsg; } catch(e){} throw new Error(errorMsg); } return JSON.parse(text); } catch (error) { console.error(`API call failed for ${action}:`, error); showToast(error.message, 'error'); return null; } }, 
        startStream(payload) {
            if (state.currentStreamController) {
                if (state.currentStreamController instanceof EventSource) { state.currentStreamController.close(); } 
                else if (state.currentStreamController.abort) { state.currentStreamController.abort(); }
            }
            payload.auth = state.settings?.api_password || '';

            if (payload.task_type === 'chat') {
                const queryParams = new URLSearchParams({
                    action: 'stream_task',
                    task_type: 'chat',
                    payload: JSON.stringify(payload.data),
                    auth: payload.auth
                });
                const eventSource = new EventSource(`?${queryParams.toString()}`);
                state.currentStreamController = eventSource;
                
                eventSource.onmessage = (event) => {
                    try {
                        const data = JSON.parse(event.data);
                        if (data.done) {
                            streamActions.onComplete(payload.task_type);
                        } else {
                            streamActions.onMessage(data, payload.task_type);
                        }
                    } catch (e) {
                        console.error("Stream parse error:", e, "on data:", event.data);
                        streamActions.onMessage({ error: `Stream Parse Error: ${event.data}` }, payload.task_type);
                    }
                };
                eventSource.onerror = (err) => {
                    streamActions.onError(err);
                    eventSource.close();
                };
            } else { // Original fetch-based logic for non-chat tasks
                const controller = new AbortController();
                state.currentStreamController = controller;
                fetch(`?action=stream_task`, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload), signal: controller.signal }).then(response => {
                    const reader = response.body.getReader();
                    const decoder = new TextDecoder();
                    function processText({ done, value }) {
                        if (done) { streamActions.onComplete(payload.task_type); return; }
                        const chunk = decoder.decode(value, { stream: true });
                        const lines = chunk.split('\n\n');
                        lines.forEach(line => {
                            if (line.startsWith('data: ')) {
                                try {
                                    const data = JSON.parse(line.substring(6));
                                    if (data.done) streamActions.onComplete(payload.task_type);
                                    else streamActions.onMessage(data, payload.task_type);
                                } catch (e) { console.error("Stream parse error:", e, "on line:", line); }
                            }
                        });
                        reader.read().then(processText);
                    }
                    reader.read().then(processText);
                }).catch(err => { if (err.name !== 'AbortError') streamActions.onError(err); });
            }
        } 
    };
    
    // 🧩 Functionality: Manages all UI rendering and updates based on the central state.
    const ui = {
        renderAll() { this.renderDashboard(); this.renderServerControls(); this.renderModels(); this.renderChat(); this.renderSettings(); this.renderDiagnostics(); this.renderApiHistory(); },
        renderDashboard() { const { running, responsive } = state.server; dom.dbStatusLight.className = `status-light ${running ? (responsive ? 'running' : 'pending') : 'stopped'}`; dom.dbStatusText.textContent = running ? (responsive ? 'Running' : 'Starting...') : 'Stopped'; dom.dbOllamaVersion.textContent = state.server.version; dom.dbModelsInstalled.textContent = state.models.installed.length; dom.dbModelsRunning.textContent = state.models.running.length; this.renderSystemInfo(); },
        renderSystemInfo() { const info = state.systemInfo; const memText = info.memory.total > 0 ? `${info.memory.used} / ${info.memory.total} GB` : 'N/A'; const diskText = info.disk_total > 0 ? `${(info.disk_total - info.disk_free).toFixed(2)} / ${info.disk_total} GB` : 'N/A'; dom.systemInfoGrid.innerHTML = ` <div class="stat-card"><div class="stat-card-title">CPU Load</div><div class="stat-card-value">${info.cpu_load || 'N/A'}</div></div> <div class="stat-card"><div class="stat-card-title">Memory Usage</div><div class="stat-card-value small">${memText}</div></div> <div class="stat-card"><div class="stat-card-title">Disk Usage</div><div class="stat-card-value small">${diskText}</div></div> <div class="stat-card"><div class="stat-card-title">Web User</div><div class="stat-card-value small">${info.web_user || 'N/A'}</div></div> `; },
        renderServerControls() { const { running, responsive, pid } = state.server; dom.serverStatusLight.className = `status-light ${running ? (responsive ? 'running' : 'pending') : 'stopped'}`; dom.serverStatusText.textContent = running ? (responsive ? `Running (PID: ${pid})` : 'Starting...') : 'Stopped'; dom.btnStartServer.disabled = running; dom.btnStopServer.disabled = !running; dom.btnPurgeAll.disabled = !responsive; },
        renderModels() { dom.installedModelList.innerHTML = ''; const allKnownModels = new Map(); state.models.localFiles.forEach(file => allKnownModels.set(file.name, { name: file.name, onDisk: true, installed: false, running: false, details: { size: 'N/A', family: 'N/A' } })); state.models.installed.forEach(model => { const entry = allKnownModels.get(model.name) || { name: model.name, onDisk: false, details: {} }; entry.installed = true; entry.running = state.models.running.includes(model.name); entry.details.size = (model.size / 1e9).toFixed(2) + ' GB'; entry.details.family = model.details.family || 'N/A'; allKnownModels.set(model.name, entry); }); if (allKnownModels.size === 0) { dom.installedModelList.innerHTML = `<li>No models found. Upload a GGUF file or pull one from the registry.</li>`; return; } allKnownModels.forEach(model => { let statusTag = ''; if (model.running) { statusTag = `<span class="tag in-memory">In Memory</span>`; } if (model.installed) { statusTag += ` <span class="tag installed">Installed</span>`; } else if (model.onDisk) { statusTag = `<span class="tag on-disk">On Disk Only</span>`; } const li = document.createElement('li'); li.innerHTML = `<div class="model-details"><div class="name">${model.name}</div><div class="meta"><span><i class="fa-solid fa-database"></i> ${model.details.size}</span><span><i class="fa-solid fa-tag"></i> ${model.details.family}</span>${statusTag}</div></div><div class="model-actions"><button class="btn-model-details" title="Details" ${!model.installed ? 'disabled' : ''}><i class="fa-solid fa-circle-info"></i></button><button class="btn-model-push" title="Push to Registry" ${!model.installed ? 'disabled' : ''}><i class="fa-solid fa-upload"></i></button><button class="danger-btn btn-model-delete" title="Delete from Ollama" ${!model.installed ? 'disabled' : ''}><i class="fa-solid fa-trash-can"></i></button></div>`; if(model.installed) { li.querySelector('.btn-model-delete').onclick = (e) => modelActions.delete(model.name, e.currentTarget); li.querySelector('.btn-model-details').onclick = () => modelActions.showDetails(model.name); li.querySelector('.btn-model-push').onclick = () => modelActions.showPushModal(model.name); } dom.installedModelList.appendChild(li); }); },
        renderChat() { const currentModel = dom.activeModelSelect.value; dom.activeModelSelect.innerHTML = state.models.installed.map(m => `<option value="${m.name}" ${m.name === currentModel ? 'selected' : ''}>${m.name}</option>`).join(''); if (dom.activeModelSelect.innerHTML === '') dom.activeModelSelect.innerHTML = `<option>No models installed</option>`; this.renderChatHistory(); this.renderChatMetadata(); },
        renderChatHistory() { dom.chatHistoryList.innerHTML = ''; state.chat.history.forEach(chat => { const li = document.createElement('li'); li.className = 'history-item'; li.dataset.chatId = chat.id; if (chat.id === state.chat.currentId) li.classList.add('active'); li.innerHTML = `<span class="history-title">${chat.title}</span><div class="history-item-actions"><button class="history-item-btn rename-chat-btn" title="Rename Chat"><i class="fa-solid fa-pen-to-square"></i></button><button class="history-item-btn delete-chat-btn" title="Delete Chat"><i class="fa-solid fa-trash-can"></i></button></div>`; li.querySelector('.history-title').onclick = () => chatActions.load(chat.id); li.querySelector('.rename-chat-btn').onclick = (e) => { e.stopPropagation(); chatActions.rename(chat.id, chat.title); }; li.querySelector('.delete-chat-btn').onclick = (e) => { e.stopPropagation(); chatActions.delete(chat.id); }; dom.chatHistoryList.appendChild(li); }); },
        renderChatMessages() { dom.chatMessages.innerHTML = ''; state.chat.messages.forEach(msg => this.addChatMessage(msg.role, msg.content)); if(state.chat.messages.length === 0) { dom.chatMessages.innerHTML = `<div class="message-container assistant"><div class="message-bubble">Welcome! Select a model and send a message to begin.</div></div>`; } },
        renderChatMetadata() { const meta = state.chat.metadata; if (meta && meta.model) { dom.chatMetadata.innerHTML = `Model: <strong>${meta.model}</strong>  |  Preset: <strong>${meta.preset_name || 'Unknown'}</strong>`; dom.chatMetadata.style.display = 'block'; } else { dom.chatMetadata.style.display = 'none'; } },
        renderSettings() { const s = state.settings; if(!s || !s.presets) return; dom.settingApiPassword.value = s.api_password || ''; const presetOptions = s.presets.map(p => `<option value="${p.id}" ${p.id === s.active_preset_id ? 'selected' : ''}>${p.name}</option>`).join(''); dom.settingPresetSelect.innerHTML = presetOptions; dom.chatPresetSelect.innerHTML = presetOptions; dom.chatPresetSelect.value = s.active_preset_id; this.populatePresetForm(s.active_preset_id); },
        populatePresetForm(presetId) { const s = state.settings; if (!s || !s.presets) return; const preset = s.presets.find(p => p.id === presetId); if (preset) { dom.settingsForm['setting-preset-name'].value = preset.name; dom.settingsForm['setting-temperature'].value = preset.temperature; dom.settingsForm['setting-repeat_penalty'].value = preset.repeat_penalty; dom.settingsForm['setting-top_k'].value = preset.top_k; dom.settingsForm['setting-top_p'].value = preset.top_p; dom.settingsForm['setting-system'].value = preset.system; dom.btnSavePreset.disabled = true; } },
        renderDiagnostics() { dom.autoActionsContainer.innerHTML = ''; if (!state.diagnostics.actions || state.diagnostics.actions.length === 0) { dom.autoActionsContainer.innerHTML = `<p>No automated actions are available for your system.</p>`; return; } state.diagnostics.actions.forEach(action => { const card = document.createElement('div'); card.className = 'content-box'; card.style.padding = '1.5rem'; card.innerHTML = `<h4 style="margin-top:0;">${action.title}</h4><p class="text-justify" style="color:var(--fg-dim); font-size:0.9rem; flex-grow:1;">${action.description}</p><button class="warning-btn" data-action-id="${action.id}">Execute</button>`; card.querySelector('button').onclick = () => systemActions.runAutoAction(action.id); dom.autoActionsContainer.appendChild(card); }); },
        renderApiHistory() { dom.apiHistoryList.innerHTML = ''; if (state.apiHistory.length === 0) { dom.apiHistoryList.innerHTML = '<li>No commands in history.</li>'; return; } state.apiHistory.forEach((item, index) => { const li = document.createElement('li'); li.className = 'api-history-item'; li.innerHTML = `<span><strong>${item.endpoint}</strong>: ${item.payload.substring(0, 50)}...</span><div class="action-area"><button title="Re-run"><i class="fa-solid fa-play"></i></button><button title="Delete" class="danger-btn"><i class="fa-solid fa-trash-can"></i></button></div>`; li.querySelector('button[title="Re-run"]').onclick = () => apiActions.rerunHistory(index); li.querySelector('button[title="Delete"]').onclick = () => apiActions.deleteHistory(index); dom.apiHistoryList.appendChild(li); }); },
        addChatMessage(role, content, isStreaming=false) { const container = document.createElement('div'); container.className = `message-container ${role}`; const bubble = document.createElement('div'); bubble.className = 'message-bubble'; if(isStreaming) { bubble.id = 'streaming-bubble'; bubble.dataset.fullResponse = ''; bubble.innerHTML = content; } else { bubble.innerHTML = DOMPurify.sanitize(marked.parse(content)); bubble.querySelectorAll('pre code').forEach(el => Prism.highlightElement(el)); bubble.querySelectorAll('pre').forEach(pre => { const wrapper = document.createElement('div'); wrapper.className = 'pre-wrapper'; pre.parentNode.insertBefore(wrapper, pre); wrapper.appendChild(pre); const btn = document.createElement('button'); btn.className = 'copy-btn'; btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy'; btn.onclick = () => { navigator.clipboard.writeText(pre.querySelector('code').innerText); showToast('Copied to clipboard!'); }; wrapper.appendChild(btn); }); } container.appendChild(bubble); dom.chatMessages.appendChild(container); dom.chatMessages.scrollTop = dom.chatMessages.scrollHeight; return bubble; },
        toggleChatControls(isStreaming) { dom.chatPrompt.disabled = isStreaming; dom.activeModelSelect.disabled = isStreaming; dom.chatPresetSelect.disabled = isStreaming; if (isStreaming) { dom.btnChatSend.innerHTML = '<i class="fa-solid fa-stop"></i> Stop'; dom.btnChatSend.classList.add('danger-btn'); } else { dom.btnChatSend.innerHTML = '<i class="fa-solid fa-paper-plane"></i>'; dom.btnChatSend.classList.remove('danger-btn'); } },
        setButtonLoading(button, isLoading) { if (!button) return; const icon = button.querySelector('i'); if (isLoading) { button.dataset.originalIcon = icon.className; icon.className = 'fa-solid fa-spinner fa-spin'; button.disabled = true; } else { if(button.dataset.originalIcon) icon.className = button.dataset.originalIcon; button.disabled = false; } },
    };

    const chatActions = {
        async send() {
            try {
                if (state.chat.isStreaming) {
                    return;
                }

                const prompt = dom.chatPrompt.value.trim();
                const model = dom.activeModelSelect.value;
                const presetId = dom.chatPresetSelect.value;

                if (!prompt || !model || model === 'No models installed') {
                    showToast('Please select a model and enter a prompt.', 'error');
                    console.error('Chat send failed: prompt or model missing.', { prompt, model });
                    return;
                }

                if (!state.chat.currentId) {
                    await this.new();
                    if (!state.chat.currentId) {
                        showToast('Error: Could not create a new chat session.', 'error');
                        console.error('Chat send failed: this.new() did not set a chat ID.');
                        return;
                    }
                }
                
                if (!state.settings || !Array.isArray(state.settings.presets)) {
                    showToast('Critical Error: Settings or presets are malformed.', 'error');
                    console.error('Chat send failed: state.settings.presets is not an array.', state.settings);
                    return;
                }
                const preset = state.settings.presets.find(p => p.id === presetId);
                if (!preset) {
                    showToast('Error: Could not find the selected preset.', 'error');
                    console.error('Chat send failed: preset not found for ID:', presetId);
                    return;
                }

                if (!state.server.responsive) {
                    showToast('Ollama server is not responsive. Please start it.', 'error');
                    console.error('Chat send failed: server not responsive.');
                    return;
                }

                const userMessage = { role: 'user', content: prompt };
                state.chat.messages.push(userMessage);
                dom.chatPrompt.value = '';
                dom.chatPrompt.style.height = 'auto';
                ui.addChatMessage(userMessage.role, userMessage.content);
                const streamingBubble = ui.addChatMessage('assistant', '<span class="thinking-cursor"></span>', true);
                if (!streamingBubble) {
                    showToast('Critical UI Error: Could not create message bubble.', 'error');
                    return;
                }

                state.chat.isStreaming = true;
                ui.toggleChatControls(true);

                if (state.chat.messages.length === 1) { // isFirstMessage logic corrected
                    const newTitle = prompt.length > 40 ? prompt.substring(0, 40) + '...' : prompt;
                    const metadata = { model, preset_id: preset.id, preset_name: preset.name };
                    await api.call('update_chat_metadata', { id: state.chat.currentId, title: newTitle, metadata });
                    state.chat.metadata = metadata;
                    ui.renderChatMetadata();
                    await this.refreshHistory();
                }

                const conversationHistory = state.chat.messages.slice(-10);
                const messagesForApi = [...conversationHistory];
                if (preset.system) {
                    messagesForApi.unshift({role: 'system', content: preset.system});
                }

                const apiDataPayload = {
                    model: model,
                    messages: messagesForApi,
                    options: {
                        temperature: parseFloat(preset.temperature),
                        repeat_penalty: parseFloat(preset.repeat_penalty),
                        top_k: parseInt(preset.top_k),
                        top_p: parseFloat(preset.top_p),
                    }
                };
                
                api.startStream({ task_type: 'chat', data: apiDataPayload });

            } catch (error) {
                showToast('A critical error occurred. Check console for details.', 'error');
                console.error('FATAL ERROR in chatActions.send:', error);
                state.chat.isStreaming = false;
                ui.toggleChatControls(false);
                const bubble = document.getElementById('streaming-bubble');
                if(bubble) bubble.remove();
            }
        },
        async new() { const res = await api.call('create_chat'); if (res?.status === 'success') { state.chat.currentId = res.chat.id; state.chat.messages = []; state.chat.metadata = {}; ui.renderChatMessages(); ui.renderChatMetadata(); await this.refreshHistory(); } },
        async load(id) { if(state.chat.isStreaming) return; const chatData = await api.call('get_chat', { id }); if (chatData) { state.chat.currentId = chatData.id; state.chat.messages = chatData.messages || []; state.chat.metadata = chatData.metadata || {}; if(state.chat.metadata.model) dom.activeModelSelect.value = state.chat.metadata.model; if(state.chat.metadata.preset_id) dom.chatPresetSelect.value = state.chat.metadata.preset_id; ui.renderChatMessages(); ui.renderChatMetadata(); dom.chatHistoryList.querySelectorAll('.history-item').forEach(item => item.classList.toggle('active', item.dataset.chatId === id)); } },
        async rename(id, oldTitle) { const newTitle = prompt("Enter new name for chat:", oldTitle); if (newTitle && newTitle.trim() !== oldTitle) { await api.call('update_chat_metadata', { id, title: newTitle.trim() }); await this.refreshHistory(); } },
        async delete(id) { if (!confirm('Delete this chat?')) return; const res = await api.call('delete_chat', {id}); if (res?.status === 'success') { if (state.chat.currentId === id) await this.new(); else await this.refreshHistory(); showToast('Chat deleted.', 'success'); } },
        async clearAll() { if (!confirm('DANGER: This will delete ALL chat conversations permanently. Continue?')) return; const res = await api.call('clear_all_chats'); if (res?.status === 'success') { await this.new(); showToast(res.message, 'success'); } },
        async refreshHistory() { const history = await api.call('get_chat_list'); if (history) { state.chat.history = history; ui.renderChatHistory(); } },
    };

    const streamActions = {
        stop() {
            if (state.currentStreamController) {
                if (state.currentStreamController instanceof EventSource) { state.currentStreamController.close(); } 
                else if (state.currentStreamController.abort) { state.currentStreamController.abort(); }
            }
            this.onComplete('manual_stop');
        },
        onMessage(data, task_type) {
            if (task_type === 'chat') {
                const bubble = document.getElementById('streaming-bubble');
                if (!bubble) return; 
                if (data.message?.content) {
                    if (bubble.querySelector('.thinking-cursor')) bubble.innerHTML = '';
                    bubble.append(document.createTextNode(data.message.content));
                    bubble.dataset.fullResponse += data.message.content;
                } else if (data.error) {
                    bubble.innerHTML = `<p style="color: var(--accent-red);"><strong>Stream Error:</strong> ${DOMPurify.sanitize(data.error)}</p>`;
                    this.onComplete('chat_error'); 
                }
                dom.chatMessages.scrollTop = dom.chatMessages.scrollHeight;
            } else if (data.log) {
                dom.progressLog.textContent += data.log + '\n';
                dom.progressLog.scrollTop = dom.progressLog.scrollHeight;
            }
        },
        onComplete(task_type) {
            const wasChatStreaming = state.chat.isStreaming;
            const isError = ['chat_error', 'error'].includes(task_type);
            if (wasChatStreaming) {
                state.chat.isStreaming = false;
                ui.toggleChatControls(false);
                const bubble = document.getElementById('streaming-bubble');
                if (bubble) {
                    if (!isError) { 
                        const fullResponse = bubble.dataset.fullResponse;
                        bubble.remove();
                        if (fullResponse) {
                            let savedContent = fullResponse;
                            if(task_type === 'manual_stop') {
                                savedContent += '\n\n*(Generation stopped by user.)*';
                            }
                            const assistantMessage = { role: 'assistant', content: savedContent };
                            state.chat.messages.push(assistantMessage); 
                            api.call('save_assistant_message', { id: state.chat.currentId, message: assistantMessage });
                            ui.addChatMessage('assistant', savedContent);
                        } else if (task_type === 'manual_stop') {
                            ui.addChatMessage('assistant', '*(Generation stopped by user.)*');
                        }
                    } else {
                        bubble.id = ''; 
                    }
                }
            } else { 
                if (task_type === 'manual_stop') {
                    dom.progressLog.textContent += '\n--- TASK STOPPED BY USER ---';
                } else if (!isError) {
                    dom.progressLog.textContent += '\n--- TASK COMPLETE ---';
                    if(['pull_model', 'push_model', 'start_and_import', 'purge_all'].includes(task_type)) {
                        modelActions.refresh();
                        systemActions.refreshStatus();
                    } else {
                        systemActions.refreshStatus();
                    }
                }
                dom.progressCloseBtn.style.display = 'inline-flex';
            }
            state.currentStreamController = null;
        },
        onError(err) {
            if (state.chat.isStreaming) {
                const bubble = document.getElementById('streaming-bubble');
                if (bubble) {
                     bubble.innerHTML = `<p style="color: var(--accent-red);"><strong>Connection Error:</strong> A network issue occurred. The server may be offline or unreachable.</p>`;
                } else {
                    ui.addChatMessage('assistant', `<p style="color: var(--accent-red);"><strong>Connection Error:</strong> A network issue occurred.</p>`);
                }
                this.onComplete('chat_error');
            } else {
                dom.progressLog.textContent += '\n--- STREAM ERROR ---\n' + err.message;
                this.onComplete('error');
            }
        },
    };

    const modelActions = {
        async pull() { if (!state.server.responsive) return showToast('Ollama server is not running. Please start it from the Server tab.', 'error'); app.runStreamTask('pull_model', 'Pulling Model...', { name: dom.pullModelForm.elements['pull-model-name'].value }); dom.pullModelForm.reset(); },
        async delete(name, button) { if (confirm(`Delete ${name} from Ollama? This is permanent.`)) { ui.setButtonLoading(button, true); try { const res = await api.call('delete_model', { name }); if (res) { showToast(res.message, res.status); await this.refresh(); } } finally { ui.setButtonLoading(button, false); } } },
        async refresh() { const data = await api.call('get_models_state'); if (data) { state.models = { ...state.models, ...data }; ui.renderModels(); ui.renderChat(); } },
        async showDetails(name) { showToast(`Fetching details for ${name}...`, 'info'); const res = await api.call('get_model_details', { name }); if (res?.status === 'success') { dom.modelDetailsTitle.textContent = name; dom.modelDetailsParams.textContent = res.details.details ? JSON.stringify(res.details.details, null, 2) : 'Not available.'; dom.modelDetailsModelfile.textContent = res.details.modelfile || 'Not available.'; dom.modelDetailsModal.classList.add('active'); } else { showToast(res?.message || 'Could not fetch details.', 'error'); } },
        showPushModal(name) { dom.pushModelSourceName.textContent = name; dom.pushModelDestination.value = name; dom.pushModelModal.classList.add('active'); },
        async push() { if (!state.server.responsive) return showToast('Ollama server is not running. Please start it from the Server tab.', 'error'); const source = dom.pushModelSourceName.textContent; const dest = dom.pushModelDestination.value.trim(); if(!dest) return; dom.pushModelModal.classList.remove('active'); app.runStreamTask('push_model', `Pushing model to ${dest}`, { source: source, destination: dest }); },
        async uploadGGUF() { const file = dom.ggufFileInput.files[0]; if (!file) return; const formData = new FormData(); formData.append('ggufFile', file); showToast(`Uploading ${file.name}...`, 'info'); try { const response = await fetch(`?action=upload_gguf`, { method: 'POST', body: formData }); const res = await response.json(); showToast(res.message, res.status); if(res.status === 'success') this.refresh(); } catch (e) { showToast('Upload failed. Check server logs and file permissions.', 'error'); } finally { dom.ggufFileInput.value = ''; } },
    };

    const settingsActions = {
        async saveGlobal(button) { ui.setButtonLoading(button, true); try { const newSettings = { ...state.settings, api_password: dom.settingApiPassword.value }; const res = await api.call('save_settings', newSettings); if (res) { state.settings = res.settings; showToast(res.message, res.status); } } finally { ui.setButtonLoading(button, false); } },
        async savePreset(button) { ui.setButtonLoading(button, true); try { const presetId = dom.settingPresetSelect.value; const presetIndex = state.settings.presets.findIndex(p => p.id === presetId); if(presetIndex === -1) return; state.settings.presets[presetIndex] = { ...state.settings.presets[presetIndex], name: dom.settingsForm['setting-preset-name'].value, temperature: dom.settingsForm['setting-temperature'].value, repeat_penalty: dom.settingsForm['setting-repeat_penalty'].value, top_k: dom.settingsForm['setting-top_k'].value, top_p: dom.settingsForm['setting-top_p'].value, system: dom.settingsForm['setting-system'].value }; const res = await api.call('save_settings', state.settings); if(res) { state.settings = res.settings; ui.renderSettings(); showToast('Preset saved!', res.status); } } finally { ui.setButtonLoading(button, false); } },
        async savePresetAsNew() { const newName = prompt("Enter name for new preset:", dom.settingsForm['setting-preset-name'].value + " Copy"); if(!newName) return; const newPreset = { id: 'preset_' + Date.now(), name: newName, temperature: dom.settingsForm['setting-temperature'].value, repeat_penalty: dom.settingsForm['setting-repeat_penalty'].value, top_k: dom.settingsForm['setting-top_k'].value, top_p: dom.settingsForm['setting-top_p'].value, system: dom.settingsForm['setting-system'].value }; state.settings.presets.push(newPreset); state.settings.active_preset_id = newPreset.id; const res = await api.call('save_settings', state.settings); if(res) { state.settings = res.settings; ui.renderSettings(); showToast('Preset saved as new!', res.status); } },
        async deletePreset(button) { if (state.settings.presets.length <= 1) { showToast('Cannot delete the last preset.', 'error'); return; } const presetId = dom.settingPresetSelect.value; if (confirm(`Are you sure you want to delete the preset "${state.settings.presets.find(p=>p.id===presetId).name}"?`)) { ui.setButtonLoading(button, true); try { state.settings.presets = state.settings.presets.filter(p => p.id !== presetId); state.settings.active_preset_id = state.settings.presets[0].id; const res = await api.call('save_settings', state.settings); if (res) { state.settings = res.settings; ui.renderSettings(); showToast('Preset deleted.', res.status); } } finally { ui.setButtonLoading(button, false); } } },
    };

    const apiActions = {
        async execute() { if (!state.server.responsive) return showToast('Ollama server is not running. Please start it from the Server tab.', 'error'); const payload = dom.apiCommandPayload.value; try { JSON.parse(payload || '{}'); } catch(e) { showToast('Payload is not valid JSON.', 'error'); return; } dom.apiCommandStatus.textContent = "Executing..."; const res = await api.call('execute_api_command', { endpoint: dom.apiEndpointSelect.value, payload }); if (res) { this.addHistory(dom.apiEndpointSelect.value, payload); dom.apiCurlExample.textContent = res.curl || 'N/A'; dom.apiCommandOutput.textContent = typeof res.result === 'string' ? res.result : JSON.stringify(res.result, null, 2); Prism.highlightAll(); dom.apiCommandStatus.textContent = `Status: ${res.status}`; } },
        addHistory(endpoint, payload) { state.apiHistory.unshift({ endpoint, payload }); if (state.apiHistory.length > 10) state.apiHistory.pop(); localStorage.setItem('apiHistory', JSON.stringify(state.apiHistory)); ui.renderApiHistory(); },
        deleteHistory(index) { state.apiHistory.splice(index, 1); localStorage.setItem('apiHistory', JSON.stringify(state.apiHistory)); ui.renderApiHistory(); },
        rerunHistory(index) { const item = state.apiHistory[index]; dom.apiEndpointSelect.value = item.endpoint; dom.apiCommandPayload.value = item.payload; this.execute(); },
        prettifyJson() { try { const ugly = dom.apiCommandPayload.value; const obj = JSON.parse(ugly); const pretty = JSON.stringify(obj, null, 2); dom.apiCommandPayload.value = pretty; } catch (e) { showToast('Invalid JSON.', 'error'); } },
        updatePayloadExample() { const examples = { tags: {}, ps: {}, show: {name: "llama3:latest"}, delete: {name: "llama3:latest"}, copy: {source: "llama3:latest", destination: "llama3-backup:latest"}, pull: {name: "llama3:latest", stream: false}, push: {name: "my-model:latest", stream: false}, generate: {model: "llama3:latest", prompt: "Why is the sky blue?", stream: false}, chat: {model: "llama3:latest", messages: [{role: "user", content: "Why is the sky blue?"}], stream: false} }; dom.apiCommandPayload.value = JSON.stringify(examples[dom.apiEndpointSelect.value] || {}, null, 2); },
    };

    const systemActions = {
        async runAutoAction(id) { if (confirm(`Execute this action? This may require sudo and is run at your own risk.`)) app.runStreamTask('execute_auto_action', `Executing: ${id}`, {id: id}); },
        async runDiagnostics(button) { ui.setButtonLoading(button, true); try { const res = await api.call('run_diagnostics'); if(res) this.renderHealthChecks(res); } finally { ui.setButtonLoading(button, false); } },
        renderHealthChecks(results) { dom.diagnosticsResults.innerHTML = ''; results.forEach(res => { const item = document.createElement('div'); item.className = 'diag-item'; item.innerHTML = `<div class="diag-info"><strong>${res.check}</strong><span>Expected: ${res.expected} | Actual: ${res.actual || 'Not Found'}</span></div><div class="diag-status diag-status-${res.status}">${res.status}</div> ${res.fixable && res.status !== 'OK' ? `<div class="diag-action"><button data-fix-id="${res.fix_id}" class="warning-btn">Attempt Fix</button></div>` : ''}`; if (res.fixable && res.status !== 'OK') { const btn = item.querySelector('button'); btn.onclick = () => this.runAutoAction(res.fix_id); if (res.fix_id === 'fix_permissions') btn.classList.add('action-required'); } dom.diagnosticsResults.appendChild(item); }); dom.diagnosticsResults.style.display = 'block'; },
        async startServer() { app.runStreamTask('start_and_import', 'Starting Server & Syncing Models...'); },
        async stopServer() { await api.call('stop_server'); this.refreshStatus(); },
        async killAll() { if(confirm('DANGER: This will kill ALL ollama processes. Continue?')) { await api.call('stop_all_tasks'); this.refreshStatus(); } },
        async purgeAll() { if (!state.server.responsive) return showToast('Ollama server is not running.', 'error'); if(confirm('DANGER: This will delete ALL installed models. Continue?')) app.runStreamTask('purge_all', 'Purging All Installed Models...'); },
        async refreshStatus() { const status = await api.call('get_server_status'); if(status) { state.server = { ...state.server, ...status }; ui.renderDashboard(); ui.renderServerControls(); } },
        async refreshLog() { const res = await api.call('get_log_content'); if(res) dom.serverLogOutput.textContent = res.log || 'Log is empty.'; },
    };

    // 🧩 Functionality: The main application controller. Handles initialization and event binding.
    const app = {
        async init() {
            this.setupEventListeners();
            this.loadTheme();
            state.apiHistory = JSON.parse(localStorage.getItem('apiHistory') || '[]');
            showToast('Initializing Powerhouse...', 'info');
            const initialState = await api.call('get_initial_state');
            if (initialState) {
                Object.assign(state.server, initialState.status, { version: initialState.version });
                state.systemInfo = initialState.system_info;
                Object.assign(state.models, { installed: initialState.installed_models, running: initialState.running_models, localFiles: initialState.local_files });
                state.chat.history = initialState.chat_history;
                state.settings = initialState.settings;
                state.diagnostics.actions = initialState.diagnostics;
                ui.renderAll();
                if (state.chat.history.length > 0) { await chatActions.load(state.chat.history[0].id); } else { await chatActions.new(); }
                apiActions.updatePayloadExample();
            } else {
                dom.dbStatusText.textContent = 'Initialization Failed. Check console.';
            }
        },
        runStreamTask(task_type, title, data = {}) {
            dom.progressTitle.textContent = title;
            dom.progressLog.textContent = 'Starting task...\n';
            dom.progressModal.classList.add('active');
            dom.progressCloseBtn.style.display = 'none';
            api.startStream({ task_type, data });
        },
        loadTheme() { const theme = localStorage.getItem('theme') || 'dark'; document.documentElement.setAttribute('data-theme', theme); const prismTheme = document.getElementById('prism-theme'); if (theme === 'light') { prismTheme.href = 'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism.min.css'; } else { prismTheme.href = 'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css'; } },
        toggleTheme() { const currentTheme = document.documentElement.getAttribute('data-theme'); const newTheme = currentTheme === 'dark' ? 'light' : 'dark'; document.documentElement.setAttribute('data-theme', newTheme); localStorage.setItem('theme', newTheme); this.loadTheme(); },
        setupEventListeners() {
            dom.navLinks.forEach(link => { link.onclick = (e) => { e.preventDefault(); const viewId = `view-${link.dataset.view}`; dom.views.forEach(v => v.classList.toggle('active', v.id === viewId)); dom.navLinks.forEach(l => l.classList.toggle('active', l === link)); }});
            
            dom.chatForm.onsubmit = (e) => {
                e.preventDefault();
                if (state.chat.isStreaming) {
                    streamActions.stop();
                } else {
                    chatActions.send();
                }
            };

            dom.chatPrompt.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); dom.chatForm.requestSubmit(); } });
            dom.chatPrompt.addEventListener('input', () => { dom.chatPrompt.style.height = 'auto'; dom.chatPrompt.style.height = (dom.chatPrompt.scrollHeight) + 'px'; });
            dom.newChatBtn.onclick = () => chatActions.new();
            dom.clearHistoryBtn.onclick = () => chatActions.clearAll();
            dom.btnStartServer.onclick = () => systemActions.startServer();
            dom.btnStopServer.onclick = () => systemActions.stopServer();
            dom.btnStopAllTasks.onclick = () => systemActions.killAll();
            dom.btnPurgeAll.onclick = () => systemActions.purgeAll();
            dom.btnRefreshLog.onclick = (e) => systemActions.refreshLog(e.currentTarget);
            dom.pullModelForm.onsubmit = (e) => { e.preventDefault(); modelActions.pull(); };
            dom.btnRefreshModels.onclick = (e) => { ui.setButtonLoading(e.currentTarget, true); modelActions.refresh().finally(() => ui.setButtonLoading(e.currentTarget, false)); };
            dom.btnUploadGGUF.onclick = () => dom.ggufFileInput.click();
            dom.ggufFileInput.onchange = () => modelActions.uploadGGUF();
            dom.settingPresetSelect.onchange = (e) => { state.settings.active_preset_id = e.target.value; ui.populatePresetForm(e.target.value); };
            dom.settingsForm.oninput = () => { dom.btnSavePreset.disabled = false; };
            dom.btnSavePreset.onclick = (e) => settingsActions.savePreset(e.currentTarget);
            dom.btnSavePresetAsNew.onclick = () => settingsActions.savePresetAsNew();
            dom.btnDeletePreset.onclick = (e) => settingsActions.deletePreset(e.currentTarget);
            dom.btnSaveGlobals.onclick = (e) => settingsActions.saveGlobal(e.currentTarget);
            dom.apiCommandForm.onsubmit = (e) => { e.preventDefault(); apiActions.execute(); };
            dom.btnPrettifyJson.onclick = () => apiActions.prettifyJson();
            dom.apiEndpointSelect.onchange = () => apiActions.updatePayloadExample();
            dom.btnShowApiDocs.onclick = () => { dom.externalApiDocs.style.display = dom.externalApiDocs.style.display === 'none' ? 'block' : 'none'; };
            dom.btnRunDiagnostics.onclick = (e) => systemActions.runDiagnostics(e.currentTarget);
            dom.themeToggle.onclick = () => this.toggleTheme();
            dom.progressCloseBtn.onclick = () => dom.progressModal.classList.remove('active');
            dom.progressStopBtn.onclick = () => streamActions.stop();
            dom.modelDetailsCloseBtn.onclick = () => dom.modelDetailsModal.classList.remove('active');
            dom.pushModelForm.onsubmit = (e) => { e.preventDefault(); modelActions.push(); };
            dom.pushModelCancelBtn.onclick = () => dom.pushModelModal.classList.remove('active');
        }
    };

    function showToast(message, type = 'success') {
        clearTimeout(window.toastTimer);
        const toast = dom.toast;
        toast.textContent = message;
        let color;
        switch (type) {
            case 'error': color = 'red'; break;
            case 'info': color = 'blue'; break;
            case 'success':
            default: color = 'green'; break;
        }
        toast.style.backgroundColor = `var(--accent-${color})`;
        toast.style.color = 'var(--text-on-accent)';
        toast.style.opacity = 1;
        toast.style.visibility = 'visible';
        window.toastTimer = setTimeout(() => {
            toast.style.opacity = 0;
            toast.style.visibility = 'hidden';
        }, 4000);
    }
    
    app.init();
});
</script>
</body>
</html>
