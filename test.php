<?php
// =================================================================================
// ✅ Ollama Hybrid Dashboard v9.0.1 "Hephaestus" - Production Backend (Automated & Documented)
// Author: An evolved AI assistant
// =================================================================================

// --- CONFIGURATION ---
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(0);

define('DASHBOARD_TITLE', 'Ollama Control Panel');
define('OLLAMA_API_BASE_URL', getenv('OLLAMA_HOST') ?: 'http://localhost:11434');
define('MODELS_DIR', __DIR__ . '/models');
define('TASK_LOG_FILE', __DIR__ . '/.task_log.json');
define('OLLAMA_BINARY_PATH', trim(shell_exec('which ollama')) ?: '/usr/local/bin/ollama');
define('CONFIG_FILE', __DIR__ . '/.config.json');

// --- API ROUTER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_post_request();
} elseif (isset($_GET['action']) && $_GET['action'] === 'stream_task') {
    stream_task();
}

function handle_post_request() {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    if (isset($_FILES['gguf_file'])) { $response = upload_gguf_files(); echo json_encode($response); exit; }

    $action = $input['action'] ?? null;
    $payload = $input['payload'] ?? [];

    if (!is_dir(MODELS_DIR) && !@mkdir(MODELS_DIR, 0775, true)) { /* fail silently, diagnostics will catch */ }
    
    $response = ['status' => 'error', 'message' => 'Invalid action.'];
    switch ($action) {
        case 'get_initial_state': $response = ['status' => 'success', 'data' => get_initial_state()]; break;
        case 'start_ollama_server': $response = start_ollama_server(); break;
        case 'stop_ollama_server': $response = stop_ollama_server(); break;
        case 'get_task_status': $response = get_task_status(); break;
        case 'pull_model': $response = pull_model($payload['name']); break;
        case 'push_model': $response = push_model($payload['source'], $payload['destination']); break;
        case 'create_model': $response = create_model($payload['name'], $payload['sourceFile']); break;
        case 'delete_model': $response = delete_model($payload['name'], $payload['sourceFile'] ?? null); break;
        case 'copy_model': $response = copy_model($payload['source'], $payload['destination']); break;
        case 'delete_gguf': $response = delete_gguf($payload['sourceFile']); break;
        case 'get_model_details': $response = get_model_details($payload['name']); break;
        case 'run_diagnostics': $response = ['status' => 'success', 'data' => run_diagnostics()]; break;
        case 'fix_permissions': $response = fix_permissions($payload['key']); break;
        case 'api_command': $response = api_command($payload['endpoint'], $payload['data']); break; // 🧠 New unified powerhouse endpoint
        case 'save_config': $response = save_config($payload); break;
        case 'stop_background_task': $response = stop_background_task(); break;
        // 🧠 New one-click automation actions
        case 'install_ollama': $response = install_ollama(); break;
        case 'set_all_permissions': $response = set_all_permissions(); break;
        case 'create_default_config': $response = create_default_config(); break;
    }
    echo json_encode($response);
    exit;
}

// --- CORE API & TASK FUNCTIONS ---

function api_request($endpoint, $method = 'GET', $data = null, $timeout = 5) { $url = OLLAMA_API_BASE_URL . $endpoint; $contextOptions = ['http' => ['method' => $method, 'header' => "Content-Type: application/json\r\n", 'content' => $data ? json_encode($data) : null, 'timeout' => $timeout, 'ignore_errors' => true]]; $context = stream_context_create($contextOptions); $response = @file_get_contents($url, false, $context); $http_code = $response ? (int)explode(' ', $http_response_header[0])[1] : 503; return ['code' => $http_code, 'body' => $response ? json_decode($response, true) : null, 'raw' => $response]; }
function run_background_task($command, $is_streamable = true) { stop_background_task(); $log_data = ['running' => true, 'pid' => null, 'output' => 'Starting task...']; file_put_contents(TASK_LOG_FILE, json_encode($log_data)); $temp_log = sys_get_temp_dir() . '/ollama_task.log'; $full_command = sprintf('%s > %s 2>&1 & echo $!', $command, escapeshellarg($temp_log)); $pid = trim(shell_exec($full_command)); $log_data['pid'] = $pid; $log_data['temp_log'] = $temp_log; file_put_contents(TASK_LOG_FILE, json_encode($log_data)); return ['status' => 'success', 'message' => 'Task started in background.', 'streamable' => $is_streamable]; }
function stream_task() { // 🧠 Merged stream_chat and other tasks into one SSE endpoint.
    header('Content-Type: text/event-stream'); header('Cache-Control: no-cache'); header('X-Accel-Buffering: no');
    $task_type = $_GET['task_type'] ?? 'log';
    if ($task_type === 'chat' && isset($_GET['payload'])) {
        $payload = json_decode($_GET['payload'], true); $payload['stream'] = true;
        $curl = curl_init(); curl_setopt_array($curl, [ CURLOPT_URL => OLLAMA_API_BASE_URL . '/api/chat', CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_WRITEFUNCTION => function($curl, $data) { $lines = explode("\n", trim($data)); foreach ($lines as $line) { if (empty($line)) continue; echo "data: " . $line . "\n\n"; if(ob_get_level() > 0) ob_flush(); flush(); } return strlen($data); } ]); @curl_exec($curl);
    } else { // Generic log file streaming
        $log_file = sys_get_temp_dir() . '/ollama_task.log';
        while(true) {
            if (connection_aborted()) break;
            $log_data = json_decode(@file_get_contents(TASK_LOG_FILE), true);
            if (!$log_data || !$log_data['running'] || !posix_getpgid($log_data['pid'])) {
                echo "event: done\ndata: {}\n\n";
                if(ob_get_level() > 0) ob_flush(); flush();
                break;
            }
            $current_output = file_exists($log_file) ? file_get_contents($log_file) : "Task is running...";
            echo "data: " . json_encode(['output' => $current_output]) . "\n\n";
            if(ob_get_level() > 0) ob_flush(); flush();
            sleep(1);
        }
    }
    exit;
}
function get_task_status() { if (!file_exists(TASK_LOG_FILE)) return ['status' => 'success', 'data' => ['running' => false, 'output' => '']]; $log_data = json_decode(file_get_contents(TASK_LOG_FILE), true); if (!$log_data['running'] || !$log_data['pid'] || !posix_getpgid($log_data['pid'])) { if ($log_data['running']) { $log_data['running'] = false; if(file_exists($log_data['temp_log'])) { $log_data['output'] = file_get_contents($log_data['temp_log']); unlink($log_data['temp_log']); } file_put_contents(TASK_LOG_FILE, json_encode($log_data)); } return ['status' => 'success', 'data' => ['running' => false, 'output' => $log_data['output']]]; } $current_output = file_exists($log_data['temp_log']) ? file_get_contents($log_data['temp_log']) : "Task is running..."; return ['status' => 'success', 'data' => ['running' => true, 'output' => $current_output]]; }
function stop_background_task() { if (!file_exists(TASK_LOG_FILE)) return ['status' => 'info', 'message' => 'No active task.']; $log_data = json_decode(file_get_contents(TASK_LOG_FILE), true); if ($log_data['running'] && $log_data['pid']) posix_kill($log_data['pid'], SIGKILL); @unlink(TASK_LOG_FILE); if (isset($log_data['temp_log'])) @unlink($log_data['temp_log']); shell_exec('pkill -f "ollama"'); return ['status' => 'success', 'message' => 'All background tasks have been forcefully stopped.']; }

// --- STATE & DATA FUNCTIONS ---
function get_initial_state() { $status = get_server_status(); $running_models = $status['running'] ? get_running_models() : []; $models = $status['running'] ? get_installed_models($running_models) : []; return [ 'serverStatus' => $status, 'installedModels' => $models, 'runningModels' => $running_models, 'config' => load_config(), 'systemInfo' => get_system_info(), ]; }
function get_server_status() { $response = api_request('/api/version', 'GET', null, 2); $is_running = $response['code'] === 200; $version = ($is_running && isset($response['body']['version'])) ? $response['body']['version'] : 'N/A'; return [ 'running' => $is_running, 'version' => $version ]; }
function get_installed_models($running_models_list = []) { $api_models_res = api_request('/api/tags'); $api_models = []; if ($api_models_res['code'] === 200) { foreach($api_models_res['body']['models'] as $model) { $model['status'] = 'pulled'; $api_models[$model['name']] = $model; } } $local_files = glob(MODELS_DIR . '/*.{gguf,bin}', GLOB_BRACE); foreach ($local_files as $file) { $filename = basename($file); $model_name = preg_replace('/\.gguf$|\.bin$/i', '', $filename) . ':latest'; if (isset($api_models[$model_name])) { $api_models[$model_name]['status'] = 'created'; $api_models[$model_name]['sourceFile'] = $filename; } else { $api_models[$model_name] = [ 'name' => $model_name, 'status' => 'pending', 'size' => filesize($file), 'modified_at' => date(DateTime::ATOM, filemtime($file)), 'details' => ['family' => 'unknown'], 'sourceFile' => $filename ]; } } foreach($running_models_list as $running_model) { if(isset($api_models[$running_model['name']])) { $api_models[$running_model['name']]['isRunning'] = true; } } return array_values($api_models); }
function get_running_models() { $res = api_request('/api/ps', 'GET', null, 10); return ($res['code'] === 200 && isset($res['body']['models'])) ? $res['body']['models'] : []; }
function get_system_info() { return [ 'os' => php_uname('s'), 'php_version' => phpversion(), 'disk_free' => @disk_free_space(__DIR__), 'disk_total' => @disk_total_space(__DIR__), 'web_user' => trim(shell_exec('whoami')), ]; }
function get_model_details($name) { $res = api_request('/api/show', 'POST', ['name' => $name]); if ($res['code'] !== 200) return ['status' => 'error', 'message' => 'Failed to get model details.']; return ['status' => 'success', 'data' => $res['body']]; }

// --- API COMMAND CENTER & SERVER MGMT ---
function api_command($endpoint, $data) {
    // 🧠 Evolution: This is now a master router for ALL API/Admin actions from the tester tab.
    $admin_actions_whitelist = ['start_ollama_server', 'stop_ollama_server', 'pull_model', 'push_model', 'copy_model', 'delete_model', 'create_model'];

    if (in_array($endpoint, $admin_actions_whitelist)) {
        // Route to internal PHP function
        switch($endpoint) {
            case 'start_ollama_server': return start_ollama_server();
            case 'stop_ollama_server': return stop_ollama_server();
            case 'pull_model': return pull_model($data['name'] ?? null);
            case 'push_model': return push_model($data['source'] ?? null, $data['destination'] ?? null);
            case 'copy_model': return copy_model($data['source'] ?? null, $data['destination'] ?? null);
            case 'delete_model': return delete_model($data['name'] ?? null, $data['sourceFile'] ?? null);
            case 'create_model': return create_model($data['name'] ?? null, $data['sourceFile'] ?? null);
        }
    } elseif (strpos($endpoint, '/api/') === 0) {
        // Proxy to Ollama API for non-streaming endpoints
        $method = in_array($endpoint, ['/api/tags']) ? 'GET' : 'POST';
        $res = api_request($endpoint, $method, $data, 20);
        return ['status' => 'success', 'data' => ['http_code' => $res['code'], 'body' => $res['body'] ?? json_decode($res['raw'], true) ?? $res['raw']]];
    }
    return ['status' => 'error', 'message' => 'Invalid or non-whitelisted endpoint in API Command Center.'];
}

function start_ollama_server() { if (get_server_status()['running']) return ['status' => 'info', 'message' => 'Server already running.']; shell_exec(OLLAMA_BINARY_PATH . ' serve > /dev/null 2>&1 &'); $max_wait_time = 15; $start_time = time(); while (time() - $start_time < $max_wait_time) { if (get_server_status()['running']) return ['status' => 'success', 'message' => 'Server started successfully.']; usleep(500000); } return ['status' => 'error', 'message' => "Server did not respond within {$max_wait_time}s."]; }
function stop_ollama_server() { if (!get_server_status()['running']) return ['status' => 'info', 'message' => 'Server is already offline.']; shell_exec('pkill -f "ollama serve"'); $max_wait_time = 15; $start_time = time(); while (time() - $start_time < $max_wait_time) { if (!get_server_status()['running']) return ['status' => 'success', 'message' => 'Server has been stopped.']; usleep(500000); } return ['status' => 'warning', 'message' => "Stop command sent, but server did not shut down within {$max_wait_time}s."]; }
function pull_model($name) { if (empty($name)) return ['status' => 'error', 'message' => 'Model name is required.']; return run_background_task(OLLAMA_BINARY_PATH . ' pull ' . escapeshellarg($name)); }
function push_model($source, $destination) { if (empty($source) || empty($destination)) return ['status' => 'error', 'message' => 'Source and destination names are required.']; return run_background_task(OLLAMA_BINARY_PATH . ' push ' . escapeshellarg($destination)); }
function create_model($name, $source_file) { $source_path = MODELS_DIR . '/' . basename($source_file); if (!file_exists($source_path)) return ['status' => 'error', 'message' => 'Source GGUF file not found.']; $config = load_config(); $params = $config['globalParameters'] ?? []; $modelfile_content = "FROM " . escapeshellarg($source_path) . "\n\n"; $allowed_params = ['temperature', 'repeat_penalty', 'top_k', 'top_p']; foreach ($allowed_params as $param_key) { if (isset($params[$param_key]) && is_numeric($params[$param_key])) $modelfile_content .= "PARAMETER " . $param_key . " " . floatval($params[$param_key]) . "\n"; } if (!empty($params['system'])) $modelfile_content .= "\nSYSTEM \"\"\"" . addcslashes($params['system'], '"') . "\"\"\"\n"; $model_base_name = preg_replace('/:[^:]+$/', '', basename($name)); $modelfile_path = MODELS_DIR . '/' . $model_base_name . '.Modelfile'; file_put_contents($modelfile_path, trim($modelfile_content)); return run_background_task(OLLAMA_BINARY_PATH . ' create ' . escapeshellarg($name) . ' -f ' . escapeshellarg($modelfile_path)); }
function upload_gguf_files() { if (empty($_FILES['gguf_file'])) return ['status' => 'error', 'message' => 'No files uploaded.']; $files = $_FILES['gguf_file']; $uploaded_count = 0; foreach($files['tmp_name'] as $i => $tmp_name) { if ($files['error'][$i] === UPLOAD_ERR_OK) { $target_path = MODELS_DIR . '/' . basename($files['name'][$i]); if (move_uploaded_file($tmp_name, $target_path)) $uploaded_count++; } } return ['status' => 'success', 'message' => "$uploaded_count file(s) uploaded successfully."]; }
function delete_model($name, $source_file = null) { api_request('/api/delete', 'DELETE', ['name' => $name]); if ($source_file) { $path = MODELS_DIR . '/' . basename($source_file); if (file_exists($path)) @unlink($path); } $model_base_name = preg_replace('/:[^:]+$/', '', basename($name)); $modelfile_path = MODELS_DIR . '/' . $model_base_name . '.Modelfile'; if (file_exists($modelfile_path)) @unlink($modelfile_path); return ['status' => 'success', 'message' => "Model '$name' deletion initiated."]; }
function copy_model($source, $destination) { $res = api_request('/api/copy', 'POST', ['source' => $source, 'destination' => $destination]); if ($res['code'] !== 200) return ['status' => 'error', 'message' => 'Failed to copy model.']; return ['status' => 'success', 'message' => "Model copied to '$destination'."]; }
function delete_gguf($source_file) { $path = MODELS_DIR . '/' . basename($source_file); if (file_exists($path) && @unlink($path)) return ['status' => 'success', 'message' => "File '$source_file' deleted."]; return ['status' => 'error', 'message' => "Could not delete file."]; }
function load_config() { if (!file_exists(CONFIG_FILE)) return ['globalParameters' => []]; return json_decode(file_get_contents(CONFIG_FILE), true); }
function save_config($new_config) { if(file_put_contents(CONFIG_FILE, json_encode($new_config, JSON_PRETTY_PRINT))) return ['status' => 'success', 'message' => 'Settings saved.']; return ['status' => 'error', 'message' => 'Failed to save settings.']; }

// --- AUTOMATION, INSTALLATION & DIAGNOSTICS ---
function parse_ini_size_to_bytes($size_str) { $size_str = trim($size_str); $last = strtolower($size_str[strlen($size_str)-1]); $val = intval($size_str); switch($last) { case 'g': $val *= 1024; case 'm': $val *= 1024; case 'k': $val *= 1024; } return $val; }

function install_ollama() {
    // 🧠 Evolution: One-click Ollama installation as a background task.
    $command = 'curl -fsSL https://ollama.com/install.sh | sh';
    // This task requires sudo, which must be configured passwordless for the web user. Diagnostics will guide them.
    $full_command = 'sudo ' . $command;
    return run_background_task($full_command);
}
function set_all_permissions() {
    // 🧠 Evolution: One-click macro to fix all known permission issues.
    $web_user = trim(shell_exec('whoami'));
    $commands = [
        'models_dir_owner' => 'sudo chown -R ' . escapeshellarg($web_user) . ' ' . escapeshellarg(MODELS_DIR),
        'dashboard_dir_owner' => 'sudo chown -R ' . escapeshellarg($web_user) . ' ' . escapeshellarg(__DIR__),
    ];
    $output = "";
    foreach($commands as $key => $cmd) {
        $output .= "Attempting to fix: $key...\n";
        $output .= shell_exec($cmd . ' 2>&1');
        $output .= "\n";
    }
    return ['status' => 'success', 'message' => "All permission fixes attempted. Re-run diagnostics to verify.", 'data' => $output];
}
function fix_permissions($key) {
    // 🧠 Evolution: Targeted permission fixer based on diagnostic key.
    $web_user = trim(shell_exec('whoami'));
    $commands = [
        'models_dir_owner' => 'sudo chown -R ' . escapeshellarg($web_user) . ' ' . escapeshellarg(MODELS_DIR),
        'dashboard_dir_owner' => 'sudo chown -R ' . escapeshellarg($web_user) . ' ' . escapeshellarg(__DIR__),
    ];
    if (array_key_exists($key, $commands)) {
        shell_exec($commands[$key] . ' 2>&1');
        return ['status' => 'success', 'message' => "Fix attempted for '$key'. Please re-run diagnostics."];
    }
    return ['status' => 'error', 'message' => 'Unknown fix key.'];
}
function create_default_config() {
    if (file_exists(CONFIG_FILE)) return ['status' => 'info', 'message' => 'Config file already exists.'];
    $default_config = [
        'globalParameters' => [ 'temperature' => 0.8, 'repeat_penalty' => 1.1, 'top_k' => 40, 'top_p' => 0.9, 'system' => 'You are a helpful AI assistant.' ]
    ];
    if (file_put_contents(CONFIG_FILE, json_encode($default_config, JSON_PRETTY_PRINT))) {
        return ['status' => 'success', 'message' => 'Default .config.json created successfully.'];
    }
    return ['status' => 'error', 'message' => 'Failed to create .config.json. Check directory permissions.'];
}

function run_diagnostics() {
    $results = []; $web_user = trim(shell_exec('whoami'));
    $add_check = function(&$results, $status, $title, $message, $fix_key = null, $fixable = false) { $results[] = compact('status', 'title', 'message', 'fix_key', 'fixable'); };

    // Sudo checks for automated actions
    $chown_path = trim(shell_exec('which chown')); $can_sudo_chown = !empty(trim(shell_exec("sudo -n -l {$chown_path} 2>/dev/null")));
    $install_ollama_prereqs = ['curl', 'sh']; $can_install_ollama = true;
    foreach ($install_ollama_prereqs as $cmd) { if (empty(trim(shell_exec("sudo -n -l $(which {$cmd}) 2>/dev/null")))) { $can_install_ollama = false; break; } }
    
    // Core checks
    if (trim(shell_exec('which ollama'))) { $add_check($results, 'OK', 'Ollama Installation', 'Ollama binary found at: ' . trim(shell_exec('which ollama'))); }
    else { $msg = 'Ollama binary not found.'; if ($can_install_ollama) { $msg .= ' Automatic installation is available.'; $add_check($results, 'FAIL', 'Ollama Installation', $msg, 'install_ollama', true); } else { $msg .= ' To enable auto-install, grant passwordless sudo for curl and sh.'; $add_check($results, 'FAIL', 'Ollama Installation', $msg, 'install_ollama', false); } }
    
    // Directory permissions
    $dirs_to_check = [MODELS_DIR => ['Models Directory', 'models_dir_owner'], __DIR__ => ['Dashboard Directory', 'dashboard_dir_owner']];
    foreach($dirs_to_check as $dir => $info) { list($name, $key) = $info; if (!is_dir($dir)) @mkdir($dir, 0775, true); if (is_writable($dir)) { $add_check($results, 'OK', $name, 'Directory exists and is writable.'); } else { $msg = "Not writable by '{$web_user}'."; if ($can_sudo_chown) { $msg .= " Auto-fix available."; $add_check($results, 'FAIL', $name, $msg, $key, true); } else { $msg .= " Grant passwordless sudo for '{$chown_path}' to enable auto-fix."; $add_check($results, 'FAIL', $name, $msg, $key, false); } } }

    if (!file_exists(CONFIG_FILE)) { $add_check($results, 'WARN', 'Config File', '.config.json is missing. A default can be created automatically.', 'create_default_config', true); }
    else { $add_check($results, 'OK', 'Config File', '.config.json found.'); }

    // API & PHP checks
    if (get_server_status()['running']) { $add_check($results, 'OK', 'Ollama API', 'Successfully connected to ' . OLLAMA_API_BASE_URL); } else { $add_check($results, 'WARN', 'Ollama API', 'Could not connect to ' . OLLAMA_API_BASE_URL . '. Is the server running?'); }
    $upload_max_bytes = parse_ini_size_to_bytes(ini_get('upload_max_filesize')); $post_max_bytes = parse_ini_size_to_bytes(ini_get('post_max_size'));
    $add_check($results, ($upload_max_bytes >= 2147483648 ? 'OK' : 'WARN'), 'PHP Upload Max Filesize', 'Value: ' . ini_get('upload_max_filesize') . '. Recommend 2G+.');
    $add_check($results, ($post_max_bytes >= 2147483648 ? 'OK' : 'WARN'), 'PHP Post Max Size', 'Value: ' . ini_get('post_max_size') . '. Recommend 2G+.');
    
    return $results;
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title><?= htmlspecialchars(DASHBOARD_TITLE) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.8/dist/purify.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fira+Code&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-core.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/autoloader/prism-autoloader.min.js"></script>
    <style>/* CSS enhanced for Zeus Powerhouse Edition */:root { --bg-dark: #1e1e2e; --bg-medium: #28283d; --bg-light: #313244; --bg-surface: #181825; --fg-main: #cdd6f4; --fg-dim: #a6adc8; --fg-subtle: #6c7086; --accent-blue: #89b4fa; --accent-green: #a6e3a1; --accent-red: #f38ba8; --accent-yellow: #f9e2af; --accent-peach: #fab387; --accent-mauve: #cba6f7; --font-main: 'Inter', sans-serif; --font-mono: "Fira Code", monospace; --radius: 10px; --border-color: #45475a; --shadow-1: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24); --shadow-2: 0 3px 6px rgba(0,0,0,0.16), 0 3px 6px rgba(0,0,0,0.23); --shadow-3: 0 10px 20px rgba(0,0,0,0.19), 0 6px 6px rgba(0,0,0,0.23); --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); } * { box-sizing: border-box; } html { scroll-behavior: smooth; } body { margin: 0; font-family: var(--font-main); background-color: var(--bg-dark); color: var(--fg-main); font-size: 15px; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; } .app-container { display: flex; min-height: 100vh; } .sidebar { width: 250px; background: var(--bg-surface); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; padding: 0.75rem; flex-shrink: 0; } .sidebar-header { padding: 1rem; margin-bottom: 1rem; text-align: center;} .sidebar-header h1 { font-size: 1.6rem; margin: 0; color: var(--fg-main); font-weight: 700; letter-spacing: 1px; } .sidebar-header .version { font-size: 0.75rem; color: var(--fg-dim); } .sidebar-nav { flex-grow: 1; } .sidebar-nav a { display: flex; align-items: center; gap: 0.8rem; padding: 0.85rem 1.25rem; text-decoration: none; color: var(--fg-dim); border-radius: var(--radius); transition: var(--transition); font-weight: 500; margin-bottom: 0.25rem; } .sidebar-nav a:hover { background: var(--bg-light); color: var(--fg-main); } .sidebar-nav a.active { background: var(--accent-blue); color: var(--bg-dark); font-weight: 600; } .sidebar-nav i { width: 20px; text-align: center; } .main-content { flex-grow: 1; padding: 2rem; overflow-y: auto; } .view { display: none; animation: fadeIn 0.3s ease-out; } .view.active { display: block; } @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } } h2 { font-size: 2rem; margin: 0 0 2rem 0; color: var(--fg-main); border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; font-weight: 700; } h3 { font-size: 1.3rem; margin: 2rem 0 1.25rem 0; color: var(--accent-peach); font-weight: 600; } h4 { font-size: 1rem; margin: 1.5rem 0 0.75rem 0; color: var(--accent-mauve); } h3:first-child, h4:first-child { margin-top: 0; } .content-box { background: var(--bg-medium); padding: 2rem; border-radius: var(--radius); border: 1px solid var(--border-color); box-shadow: var(--shadow-2);} .content-box:not(:last-child) { margin-bottom: 2rem; } button { position: relative; overflow: hidden; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; font-family: var(--font-main); color: var(--fg-main); background: var(--bg-light); border: 1px solid var(--border-color); border-radius: var(--radius); padding: 0.6rem 1.2rem; font-size: 0.9rem; font-weight: 600; transition: var(--transition); cursor: pointer; user-select: none; box-shadow: var(--shadow-1); } button .btn-text { z-index: 1; } button:hover:not(:disabled) { transform: translateY(-2px); box-shadow: var(--shadow-2); border-color: var(--accent-mauve); } button:active:not(:disabled) { transform: translateY(0); box-shadow: var(--shadow-1); } button:disabled { opacity: 0.6; color: var(--fg-dim); cursor: not-allowed; border-color: #333; box-shadow: none; } button.action-required { animation: pulse-blue 2s infinite; } @keyframes pulse-blue { 0% { box-shadow: 0 0 0 0 rgba(137, 180, 250, 0.4); } 70% { box-shadow: 0 0 0 10px rgba(137, 180, 250, 0); } 100% { box-shadow: 0 0 0 0 rgba(137, 180, 250, 0); } } .primary-btn { background-color: var(--accent-blue); color: var(--bg-dark); border-color: var(--accent-blue); } .danger-btn { background-color: var(--accent-red); color: var(--bg-dark); border-color: var(--accent-red); } .warning-btn { background-color: var(--accent-yellow); color: var(--bg-dark); border-color: var(--accent-yellow); } .form-group { position: relative; margin-top: 1.25rem; } select, input, textarea { width: 100%; border: 1px solid var(--border-color); background: var(--bg-surface); padding: 0.9rem; border-radius: var(--radius); transition: var(--transition); color: var(--fg-main); font-family: var(--font-main); font-size: 0.95rem; } .form-group label { position: absolute; top: 0.9rem; left: 0.9rem; color: var(--fg-dim); pointer-events: none; transition: var(--transition); background: var(--bg-surface); padding: 0 0.5rem; } input:focus, textarea:focus { border-color: var(--accent-blue); } input:focus + label, input:not(:placeholder-shown) + label, textarea:focus + label, textarea:not(:placeholder-shown) + label { top: -0.65rem; left: 0.75rem; font-size: 0.75rem; color: var(--accent-blue); } pre { background: var(--bg-surface); padding: 1.25rem; border-radius: var(--radius); white-space: pre-wrap; word-break: break-all; max-height: 40vh; overflow-y: auto; font-family: var(--font-mono); font-size: 0.85em; border: 1px solid var(--border-color); position: relative; } .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(17, 17, 27, 0.6); backdrop-filter: blur(5px); display: none; justify-content: center; align-items: center; z-index: 1000; animation: fadeIn 0.3s; } .modal.active { display: flex; } .modal-content { background: var(--bg-medium); padding: 2rem; border-radius: var(--radius); width: 90%; max-width: 700px; max-height: 85vh; display: flex; flex-direction: column; border: 1px solid var(--border-color); box-shadow: var(--shadow-3); } .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.75rem; } .stat-card { background: var(--bg-medium); padding: 1.5rem; border-radius: var(--radius); border: 1px solid var(--border-color); transition: var(--transition); box-shadow: var(--shadow-2); } .stat-card:hover { transform: translateY(-4px); border-color: var(--accent-mauve); box-shadow: var(--shadow-3); } .stat-card-title { font-size: 0.9rem; font-weight: 500; color: var(--fg-dim); margin: 0 0 0.75rem 0; } .stat-card-value { font-size: 2.2rem; font-weight: 700; color: var(--fg-main); display: flex; align-items: center;} .status-light { width: 12px; height: 12px; border-radius: 50%; margin-right: 0.75rem;} .status-light.running { background-color: var(--accent-green); box-shadow: 0 0 8px var(--accent-green); } .status-light.stopped { background-color: var(--accent-red); } .status-light.pending { background-color: var(--accent-yellow); animation: pulse 1.5s infinite; } @keyframes pulse { 0% { box-shadow: 0 0 0 0 var(--accent-yellow); } 70% { box-shadow: 0 0 0 8px rgba(249, 226, 175, 0); } 100% { box-shadow: 0 0 0 0 rgba(249, 226, 175, 0); } } .models-list ul { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 1px; background: var(--border-color); border: 1px solid var(--border-color); border-radius: var(--radius); overflow: hidden;} .models-list li { background: var(--bg-medium); display: grid; grid-template-columns: 1fr auto; align-items: center; gap: 1rem; padding: 1rem 1.25rem; transition: background-color 0.2s; } .models-list li:hover { background-color: var(--bg-light); } .model-details .name { font-weight: 600; font-size: 1rem; color: var(--fg-main); word-break: break-all; } .model-details .meta { font-size: 0.8rem; color: var(--fg-dim); margin-top: 0.25rem; display: flex; flex-wrap: wrap; align-items:center; gap: 1rem; } .model-details .tag { padding: 0.2rem 0.5rem; border-radius: 5px; font-size: 0.7rem; font-weight: 600; } .tag.loaded { background-color: var(--accent-green); color: var(--bg-dark); } .tag.pulled { background-color: var(--accent-mauve); color: var(--bg-dark); } .tag.pending { background-color: var(--accent-yellow); color: var(--bg-dark); } .model-actions { display: flex; justify-content: flex-end; gap: 0.5rem; } .model-actions button { padding: 0.4rem 0.8rem; flex-shrink: 0; } #chat-layout { display: flex; gap: 1.25rem; height: calc(100vh - 12rem); } #chat-sidebar { width: 250px; flex-shrink: 0; background-color: var(--bg-surface); border-radius: var(--radius); display: flex; flex-direction: column; padding: 0.75rem; border: 1px solid var(--border-color); } #chat-main-panel { flex-grow: 1; display: flex; flex-direction: column; min-width: 0; } #chat-messages { flex-grow: 1; overflow-y: auto; padding: 1.25rem; background: var(--bg-surface); border-radius: var(--radius); display: flex; flex-direction: column; gap: 1.25rem; margin-bottom: 1rem; } .message-container { display: flex; flex-direction: column; } .message-container.user { align-items: flex-end; } .message-container.assistant { align-items: flex-start; } .message-bubble { max-width: 85%; padding: 0.8rem 1.2rem; } .message-container.user .message-bubble { background-color: var(--accent-blue); color: var(--bg-dark); border-radius: var(--radius) var(--radius) 0 var(--radius); } .message-container.assistant .message-bubble { background-color: var(--bg-light); border-radius: var(--radius) var(--radius) var(--radius) 0; } .message-bubble p:first-child { margin-top: 0; } .message-bubble p:last-child { margin-bottom: 0; } .message-bubble pre { position: relative; } .copy-to-clipboard-btn { position: absolute; top: 8px; right: 8px; background: var(--bg-dark); color: var(--fg-dim); border: 1px solid var(--border-color); opacity: 0; transition: opacity 0.2s; cursor: pointer; border-radius: 5px; padding: 4px 8px; font-size: 12px; } pre:hover .copy-to-clipboard-btn { opacity: 1; } .copy-to-clipboard-btn:hover { background-color: var(--accent-blue); color: var(--bg-dark); } .message-bubble .thinking-cursor { display: inline-block; width: 10px; height: 1.2em; background-color: var(--fg-main); animation: blink 1s step-end infinite; } @keyframes blink { from, to { background-color: transparent; } 50% { background-color: var(--fg-main); } } .control-group { display: flex; align-items: center; justify-content: space-between; background: var(--bg-surface); padding: 1.25rem; border-radius: var(--radius); border: 1px solid var(--border-color); } .control-group:not(:last-child) { margin-bottom: 1rem; }
    #chat-history-list { list-style: none; padding: 0; margin: 1rem 0; flex-grow: 1; overflow-y: auto; } .history-item { display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1rem; border-radius: var(--radius); cursor: pointer; transition: var(--transition); } .history-item:hover { background-color: var(--bg-light); } .history-item.active { background-color: var(--accent-blue); color: var(--bg-dark); font-weight: 500; } .history-title { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex-grow: 1; } .delete-chat-btn { background: none; border: none; color: var(--fg-dim); cursor: pointer; padding: 0.25rem 0.5rem; border-radius: 5px; transition: var(--transition); opacity: 0; } .history-item:hover .delete-chat-btn { opacity: 1; } .history-item.active .delete-chat-btn { color: var(--bg-dark); } .delete-chat-btn:hover { color: var(--accent-red); background-color: rgba(0,0,0,0.1); }
    .diag-item { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; padding: 1.25rem; border-bottom: 1px solid var(--border-color); } .diag-item:last-child { border-bottom: none; } .diag-info { flex-grow: 1; } .diag-info strong { color: var(--fg-main); } .diag-info span { color: var(--fg-dim); display: block; margin-top: 0.25rem; word-break: break-all; } .diag-status { font-weight: bold; padding: 0.2em 0.6em; border-radius: 5px; color: var(--bg-dark); } .diag-status-OK { background-color: var(--accent-green); } .diag-status-WARN { background-color: var(--accent-yellow); } .diag-status-FAIL { background-color: var(--accent-red); } .diag-status-INFO { background-color: var(--accent-blue); } .diag-action button { padding: 0.4rem 0.8rem; font-size: 0.8rem; } .auto-actions-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; }
    #external-api-docs { display: none; margin-top: 2rem; border-top: 1px solid var(--border-color); padding-top: 2rem; }
    </style>
</head>
<body>
<div class="app-container">
    <nav class="sidebar">
        <div class="sidebar-header"><h1>Ollama</h1><div class="version">v9.0.1</div></div>
        <div class="sidebar-nav">
            <a href="#" class="nav-link active" data-view="dashboard"><i class="fa-solid fa-chart-bar"></i><span class="btn-text">Dashboard</span></a>
            <a href="#" class="nav-link" data-view="chat"><i class="fa-solid fa-comments"></i><span class="btn-text">Chat</span></a>
            <a href="#" class="nav-link" data-view="models"><i class="fa-solid fa-box-archive"></i><span class="btn-text">Models</span></a>
            <a href="#" class="nav-link" data-view="server"><i class="fa-solid fa-satellite-dish"></i><span class="btn-text">Server</span></a>
            <a href="#" class="nav-link" data-view="settings"><i class="fa-solid fa-gears"></i><span class="btn-text">Settings</span></a>
            <a href="#" class="nav-link" data-view="api"><i class="fa-solid fa-code"></i><span class="btn-text">API Command</span></a>
            <a href="#" class="nav-link" data-view="diagnostics"><i class="fa-solid fa-wrench"></i><span class="btn-text">Setup & System</span></a>
        </div>
    </nav>
    <main class="main-content">
        <section id="view-dashboard" class="view active"><h2>Dashboard</h2><div class="stats-grid"><div class="stat-card"><div class="stat-card-title">Server Status</div><div id="db-server-status" class="stat-card-value"><span id="db-status-light" class="status-light"></span> <span id="db-status-text">Checking...</span></div></div><div class="stat-card"><div class="stat-card-title">Ollama Version</div><div id="db-ollama-version" class="stat-card-value">N/A</div></div><div class="stat-card"><div class="stat-card-title">Usable Models</div><div id="db-models-installed" class="stat-card-value">0</div></div><div class="stat-card"><div class="stat-card-title">Models in Memory</div><div id="db-models-running" class="stat-card-value">0</div></div></div><div class="content-box" style="margin-top: 2rem;"><h3>System Information</h3><div class="stats-grid" id="system-info-grid"></div></div></section>
        <section id="view-chat" class="view"><h2>Chat</h2><div id="chat-layout"><div id="chat-sidebar"><h3>Conversations</h3><ul id="chat-history-list"></ul><button id="new-chat-btn" class="primary-btn"><i class="fa-solid fa-plus"></i><span class="btn-text">New Chat</span></button></div><div id="chat-main-panel"><div style="display:flex; gap: 0.75rem; align-items: center;"><div class="form-group" style="flex-grow:1; margin-top:0;"><select id="active-model-select"></select></div></div><div id="chat-messages"><div class="message-container assistant"><div class="message-bubble">Welcome! Select a model and send a message to begin.</div></div></div>
        <form id="chat-form" style="display:flex; gap:0.75rem;"><div class="form-group" style="flex-grow:1; margin:0;"><textarea id="chat-prompt" rows="1" placeholder=" " required></textarea><label for="chat-prompt">Send a message...</label></div><button type="submit" id="btn-chat-send" class="primary-btn"><i class="fa-solid fa-paper-plane"></i></button><button type="button" id="btn-chat-stop" class="danger-btn" style="display:none;"><i class="fa-solid fa-stop"></i></button></form></div></div></section>
        <section id="view-models" class="view"><h2>Model Management</h2><div class="content-box"><h3>Pull Model from Registry</h3><form id="pull-model-form" style="display:flex; gap: 0.75rem;"><div class="form-group" style="flex-grow:1; margin:0;"><input type="text" id="pull-model-name" placeholder=" " required><label for="pull-model-name">e.g., llama3:latest</label></div><button type="submit" class="primary-btn"><i class="fa-solid fa-download"></i><span class="btn-text">Pull</span></button></form></div><div class="content-box models-list"><div style="display:flex; justify-content: space-between; align-items:center;"><h3 style="margin:0; padding:0; border:0; color:var(--accent-peach);">Local Models</h3><div style="display:flex; gap:0.5rem;"><button id="btn-sync-ggufs" title="Refresh list"><i class="fa-solid fa-rotate"></i><span class="btn-text">Refresh</span></button><button id="btn-upload-gguf" class="primary-btn"><i class="fa-solid fa-upload"></i><span class="btn-text">Upload GGUF</span></button></div><input type="file" id="gguf-file-input" accept=".gguf,.bin" style="display:none;" multiple></div><ul id="installed-model-list" style="margin-top:1.5rem"></ul></div></section>
        <section id="view-server" class="view"><h2>Server Control</h2><div class="content-box"><div class="control-group"><div id="server-status-indicator" class="stat-card-value" style="font-size: 1.1rem;"><span id="server-status-light" class="status-light"></span><span id="server-status-text">Checking...</span></div><div style="display:flex;gap:0.5rem;"><button id="btn-start-server"><i class="fa-solid fa-play"></i><span class="btn-text">Start Server</span></button><button id="btn-stop-server" class="danger-btn"><i class="fa-solid fa-stop"></i><span class="btn-text">Stop Server</span></button></div></div><div class="control-group"><span>Active background tasks (pull/create/push/install).</span><button id="btn-stop-all-tasks" class="danger-btn"><i class="fa-solid fa-ban"></i><span class="btn-text">Stop All Tasks</span></button></div></div><div class="content-box"><h3>Task Logs</h3><pre id="server-log-output">Task logs will appear here when a task is active.</pre></div></section>
        <section id="view-settings" class="view"><h2>Settings</h2><div class="content-box"><form id="settings-form"><h3>Global Chat Parameters</h3><p>Default settings for all chat sessions. These can be overridden by model-specific parameters.</p><div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;"><div class="form-group"><input type="number" id="setting-temperature" step="0.1" min="0" max="2" placeholder=" "><label for="setting-temperature">Temperature</label></div><div class="form-group"><input type="number" id="setting-repeat_penalty" step="0.1" min="0" placeholder=" "><label for="setting-repeat_penalty">Repeat Penalty</label></div><div class="form-group"><input type="number" id="setting-top_k" step="1" min="0" placeholder=" "><label for="setting-top_k">Top K</label></div><div class="form-group"><input type="number" id="setting-top_p" step="0.05" min="0" max="1" placeholder=" "><label for="setting-top_p">Top P</label></div></div><div class="form-group"><textarea id="setting-system" rows="4" placeholder=" "></textarea><label for="setting-system">System Prompt</label></div><button type="submit" class="primary-btn" style="margin-top:1.5rem;"><i class="fa-solid fa-save"></i><span class="btn-text">Save Settings</span></button></form></div></section>
        <section id="view-api" class="view"><h2>API Command Center</h2>
            <div class="content-box">
                <div style="display:flex; justify-content: space-between; align-items: center;">
                    <h3>Universal API & Admin Executor</h3>
                    <button id="btn-show-api-docs"><i class="fa-solid fa-book"></i><span class="btn-text">External API Guide</span></button>
                </div>
                <form id="api-command-form">
                    <div class="form-group" style="margin: 0;"><label for="api-endpoint-select" style="position: static; top:0; left:0; transform: none; font-size: 0.8rem; margin-bottom: 0.5rem; display: block; background: none; padding: 0;">Command Endpoint</label><select id="api-endpoint-select"></select></div>
                    <div class="form-group"><textarea id="api-command-payload" rows="8" placeholder=" "></textarea><label for="api-command-payload">JSON Payload</label></div>
                    <div style="display:flex; justify-content: space-between; align-items: center; margin-top:1.5rem;">
                        <button type="submit" class="primary-btn"><i class="fa-solid fa-bolt"></i><span class="btn-text">Execute Command</span></button>
                        <span id="api-command-status" style="font-family: var(--font-mono); color: var(--fg-dim);">Status: Idle</span>
                    </div>
                </form>
                <h4 style="margin-top: 2rem;">Generated cURL (for internal use)</h4>
                <pre id="api-curl-example"></pre>
                <h4 style="margin-top: 2rem;">Execution Result</h4>
                <pre id="api-command-output">Result will appear here...</pre>
                
                <div id="external-api-docs">
                    <h3>Calling the Chat API Externally</h3>
                    <p>This dashboard provides a streaming API endpoint that proxies requests to Ollama. This allows you to build applications that talk to this dashboard's backend instead of directly to Ollama. The primary benefit is that it respects the running state of the server managed by this dashboard.</p>
                    
                    <h4>Endpoint</h4>
                    <p>The API is exposed via a <strong>GET</strong> request to the main dashboard URL with specific query parameters.</p>
                    <pre><code><?= htmlspecialchars(str_replace(basename(__FILE__), '', $_SERVER['SCRIPT_URI'])) . basename(__FILE__) ?>?action=stream_task&task_type=chat&payload={URL_ENCODED_JSON}</code></pre>

                    <h4>Payload Structure</h4>
                    <p>The <code>payload</code> parameter must be a URL-encoded JSON object with the following structure. It's the same as Ollama's <code>/api/chat</code> endpoint.</p>
                    <pre><code class="language-json">{
  "model": "model-name:latest",
  "messages": [
    {
      "role": "user",
      "content": "Why is the sky blue?"
    }
  ],
  "stream": true
}</code></pre>

                    <h4>Response Format: Server-Sent Events (SSE)</h4>
                    <p>The API responds with a <code>text/event-stream</code>. Your client must be able to handle this format. Each message from the stream will be a JSON object from Ollama, prefixed with <code>data: </code> and ending with <code>\n\n</code>. You need to parse these events to reconstruct the full response.</p>
                    
                    <h4>Example: cURL (Command Line)</h4>
                    <pre><code class="language-bash"># 1. Create the JSON payload
PAYLOAD='{"model":"llama3:latest","messages":[{"role":"user","content":"Tell me a short story."}]}'

# 2. URL-encode the payload
ENCODED_PAYLOAD=$(python3 -c "import urllib.parse; print(urllib.parse.quote('''$PAYLOAD'''))")

# 3. Make the request
curl -N "<?= htmlspecialchars(str_replace(basename(__FILE__), '', $_SERVER['SCRIPT_URI'])) . basename(__FILE__) ?>?action=stream_task&task_type=chat&payload=$ENCODED_PAYLOAD"</code></pre>

                    <h4>Example: Python with `requests`</h4>
                    <pre><code class="language-python">import requests
import json

dashboard_url = "<?= htmlspecialchars(str_replace(basename(__FILE__), '', $_SERVER['SCRIPT_URI'])) . basename(__FILE__) ?>"

payload = {
    "model": "llama3:latest",
    "messages": [
        {"role": "user", "content": "What are the main benefits of using Python?"}
    ]
}

params = {
    "action": "stream_task",
    "task_type": "chat",
    "payload": json.dumps(payload)
}

try:
    with requests.get(dashboard_url, params=params, stream=True) as response:
        response.raise_for_status()  # Raise an exception for bad status codes
        
        print("Assistant: ", end="", flush=True)
        full_response = ""

        # The response is a stream of Server-Sent Events (SSE)
        for line in response.iter_lines():
            if line:
                decoded_line = line.decode('utf-8')
                if decoded_line.startswith('data: '):
                    # Extract the JSON part of the message
                    json_data_str = decoded_line[len('data: '):]
                    try:
                        data = json.loads(json_data_str)
                        if data.get("done") is not True:
                            token = data.get("message", {}).get("content", "")
                            print(token, end="", flush=True)
                            full_response += token
                    except json.JSONDecodeError:
                        # Sometimes a line might not be perfect JSON, ignore it
                        pass
        print("\\n--- End of Stream ---")

except requests.exceptions.RequestException as e:
    print(f"An error occurred: {e}")
</code></pre>

                    <h4>Example: PHP with `cURL`</h4>
                    <pre><code class="language-php"><?php
$dashboardUrl =  htmlspecialchars(str_replace(basename(__FILE__), '', $_SERVER['SCRIPT_URI'])) . basename(__FILE__);

$payload = [
    'model' => 'llama3:latest',
    'messages' => [
        ['role' => 'user', 'content' => 'Write a PHP script to connect to a MySQL database.']
    ]
];

$queryString = http_build_query([
    'action' => 'stream_task',
    'task_type' => 'chat',
    'payload' => json_encode($payload)
]);

$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $dashboardUrl . '?' . $queryString);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // We want to output the stream directly
curl_setopt($ch, CURLOPT_HEADER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Use in dev only

$fullResponse = '';

// This function will be called for each chunk of data received
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use (&$fullResponse) {
    // Each chunk can contain multiple SSE messages
    $lines = explode("\\n\\n", $data);

    foreach ($lines as $line) {
        if (strpos($line, 'data: ') === 0) {
            $jsonStr = substr($line, strlen('data: '));
            $jsonObj = json_decode(trim($jsonStr), true);

            if ($jsonObj && isset($jsonObj['message']['content'])) {
                $token = $jsonObj['message']['content'];
                echo $token; // Echo the token as it arrives
                flush(); // Flush the output buffer to the client
                $fullResponse .= $token;
            }
        }
    }
    return strlen($data); // Must return the number of bytes handled
});

echo "Assistant: ";
$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo "\\nError: " . curl_error($ch);
}

curl_close($ch);

echo "\\n--- End of Stream ---";
?>
</code></pre>
                </div>
            </div>
        </section>
        <section id="view-diagnostics" class="view"><h2>Setup & System Automation</h2>
             <div class="content-box"><h3>Automated Actions</h3><p>Use these actions to install, configure, and repair your dashboard environment. Ensure you have configured passwordless sudo for the web user for these to work.</p><div class="auto-actions-grid" id="auto-actions-container"></div></div>
            <div class="content-box"><h3>System Health Check</h3><p>This tool checks for common configuration and permission issues. Problems marked with <span class="diag-status-FAIL" style="padding:2px 5px; border-radius:3px;">FAIL</span> may offer an automatic fix.</p><div class="control-group" style="margin-bottom:0;"><span style="max-width: 60%;">Verify critical paths and PHP settings.</span><button id="btn-run-diagnostics" class="primary-btn"><i class="fa-solid fa-heart-pulse"></i><span class="btn-text">Run Checks</span></button></div><div id="diagnostics-results" style="margin-top:1.5rem; display:none; background: var(--bg-surface); border-radius:var(--radius); padding: 0.5rem 0;"></div></div>
        </section>
    </main>
</div>
<div id="progress-modal" class="modal"><div class="modal-content"><div style="display:flex; justify-content: space-between; align-items: center;"><h3 id="progress-title" style="margin:0;">Task in Progress...</h3><button id="progress-stop-btn" class="danger-btn"><i class="fa-solid fa-ban"></i><span class="btn-text">Stop Task</span></button></div><pre id="progress-log" style="margin-top:1.5rem; flex-grow:1;">Starting...</pre><div style="text-align:right; margin-top:1rem;"><button id="progress-close-btn" style="display:none;"><i class="fa-solid fa-check"></i><span class="btn-text">Close</span></button></div></div></div>
<div id="model-details-modal" class="modal"><div class="modal-content"><h3 id="model-details-title">Model Details</h3><h4 style="margin-top:2rem;">Parameters</h4><pre id="model-details-params"></pre><h4>Modelfile</h4><pre id="model-details-modelfile"></pre><div style="text-align:right; margin-top:1rem;"><button id="model-details-close-btn"><span class="btn-text">Close</span></button></div></div></div>
<div id="push-model-modal" class="modal"><div class="modal-content"><form id="push-model-form"><h3 id="push-model-title">Push Model to Registry</h3><p>Push model <strong id="push-model-source-name"></strong> to a registry. The destination name must include the registry, e.g., <strong>registry.example.com/library/my-model:latest</strong>.</p><div class="form-group"><input type="text" id="push-model-destination" required placeholder=" "><label for="push-model-destination">Destination Name (e.g., user/model)</label></div><div style="text-align:right; margin-top:1.5rem; display:flex; gap:0.5rem; justify-content:flex-end;"><button type="button" class="subtle-btn" id="push-model-cancel-btn"><span class="btn-text">Cancel</span></button><button type="submit" class="primary-btn"><i class="fa-solid fa-upload"></i><span class="btn-text">Push</span></button></div></form></div></div>
<div id="toast" style="position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background-color: var(--accent-green); color: var(--bg-dark); padding: 12px 24px; border-radius: var(--radius); box-shadow: var(--shadow-3); z-index: 2000; opacity: 0; visibility: hidden; transition: opacity 0.3s, visibility 0.3s, transform 0.3s; font-weight: 600;"></div>
<script>
// 🧠 Evolution: JS rewritten for the Hephaestus Automated & Documented Edition.
(() => {
    "use strict";
    const appState = { activeView: 'dashboard', server: { isRunning: false, version: "N/A", isTransitioning: false }, models: { installed: [], running: [] }, config: { globalParameters: {} }, systemInfo: {}, isAwaitingResponse: false, activeTaskPoller: null, chat: { history: [], activeConversationId: null, eventSource: null }, commandCenter: { eventSource: null } };
    const CHAT_HISTORY_KEY = 'ollamaDashboard_chatHistory_v9';
    const DOMElements = {
        mainContent: document.querySelector(".main-content"),
        sidebar: document.querySelector(".sidebar"), navLinks: document.querySelectorAll(".nav-link"), views: document.querySelectorAll(".view"), toast: document.getElementById("toast"),
        dashboard: { statusLight: document.getElementById("db-status-light"), statusText: document.getElementById("db-status-text"), version: document.getElementById("db-ollama-version"), installedCount: document.getElementById("db-models-installed"), runningCount: document.getElementById("db-models-running"), systemInfoGrid: document.getElementById("system-info-grid") },
        chat: { historyList: document.getElementById("chat-history-list"), newChatBtn: document.getElementById("new-chat-btn"), activeModelSelect: document.getElementById("active-model-select"), messages: document.getElementById("chat-messages"), form: document.getElementById("chat-form"), prompt: document.getElementById("chat-prompt"), sendBtn: document.getElementById("btn-chat-send"), stopBtn: document.getElementById("btn-chat-stop") },
        models: { pullForm: document.getElementById("pull-model-form"), pullName: document.getElementById("pull-model-name"), installedList: document.getElementById("installed-model-list"), uploadBtn: document.getElementById("btn-upload-gguf"), fileInput: document.getElementById("gguf-file-input"), syncGgufBtn: document.getElementById("btn-sync-ggufs") },
        server: { statusLight: document.getElementById("server-status-light"), statusText: document.getElementById("server-status-text"), startBtn: document.getElementById("btn-start-server"), stopBtn: document.getElementById("btn-stop-server"), stopTasksBtn: document.getElementById("btn-stop-all-tasks"), logOutput: document.getElementById("server-log-output") },
        settings: { form: document.getElementById("settings-form"), inputs: { temperature: document.getElementById('setting-temperature'), repeat_penalty: document.getElementById('setting-repeat_penalty'), top_k: document.getElementById('setting-top_k'), top_p: document.getElementById('setting-top_p'), system: document.getElementById('setting-system') } },
        api: { form: document.getElementById('api-command-form'), endpointSelect: document.getElementById('api-endpoint-select'), payload: document.getElementById('api-command-payload'), output: document.getElementById('api-command-output'), status: document.getElementById('api-command-status'), curlExample: document.getElementById('api-curl-example'), showDocsBtn: document.getElementById('btn-show-api-docs'), docs: document.getElementById('external-api-docs') },
        diagnostics: { runBtn: document.getElementById("btn-run-diagnostics"), results: document.getElementById("diagnostics-results"), actionsContainer: document.getElementById("auto-actions-container") },
        progressModal: { root: document.getElementById("progress-modal"), title: document.getElementById("progress-title"), log: document.getElementById("progress-log"), stopBtn: document.getElementById("progress-stop-btn"), closeBtn: document.getElementById("progress-close-btn") },
        detailsModal: { root: document.getElementById("model-details-modal"), title: document.getElementById("model-details-title"), params: document.getElementById("model-details-params"), modelfile: document.getElementById("model-details-modelfile"), closeBtn: document.getElementById("model-details-close-btn") },
        pushModal: { root: document.getElementById("push-model-modal"), form: document.getElementById("push-model-form"), sourceName: document.getElementById("push-model-source-name"), destination: document.getElementById("push-model-destination"), cancelBtn: document.getElementById("push-model-cancel-btn") }
    };
    const api = { async call(action, payload = {}) { try { const response = await fetch(window.location.pathname, { method: "POST", headers: { "Content-Type": "application/json", "Accept": "application/json" }, body: JSON.stringify({ action, payload }) }); if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`); const data = await response.json(); if (data.status === "error") throw new Error(data.message); const quietActions = ['get_initial_state', 'get_task_status', 'run_diagnostics']; if (data.message && !quietActions.includes(action)) ui.showToast(data.message, data.status || "success"); return data; } catch (e) { ui.showToast(e.message, "error"); throw e; } }, async upload(formData) { try { ui.showToast("Uploading file(s)...", "info"); const response = await fetch(window.location.pathname, { method: "POST", body: formData }); const data = await response.json(); if (data.message) ui.showToast(data.message, data.status); if (data.status === "error") throw new Error(data.message); return data; } catch (e) { ui.showToast(e.message, "error"); throw e; } } };
    const ui = {
        renderAll() { this.renderDashboardView(); this.renderChatView(); this.renderModelsView(); this.renderServerView(); this.renderSettingsView(); this.updateApiCommandCenter(); },
        updateView(viewId) { appState.activeView = viewId; DOMElements.views.forEach(v => v.classList.toggle("active", v.id === `view-${viewId}`)); DOMElements.navLinks.forEach(l => l.classList.toggle("active", l.dataset.view === viewId)) },
        showToast(message, type = "success") { const t = DOMElements.toast; t.textContent = message; t.style.backgroundColor = `var(--accent-${type === 'error' ? 'red' : type === 'info' ? 'blue' : 'green'})`; t.style.visibility = 'visible'; t.style.opacity = 1; t.style.transform = 'translateX(-50%) translateY(0)'; setTimeout(() => { t.style.opacity = 0; t.style.visibility = 'hidden'; t.style.transform = 'translateX(-50%) translateY(20px)'; }, 4000); },
        showModal(id) { document.getElementById(id).classList.add('active'); }, hideModal(id) { document.getElementById(id).classList.remove('active'); },
        renderDashboardView() { const { dashboard: db } = DOMElements; const { isRunning, version } = appState.server; const usableModels = appState.models.installed.filter(m => ['created', 'pulled'].includes(m.status)); db.statusLight.className = `status-light ${isRunning ? "running" : "stopped"}`; db.statusText.textContent = isRunning ? "Online" : "Offline"; db.version.textContent = version; db.installedCount.textContent = usableModels.length; db.runningCount.textContent = appState.models.running.length; const si = appState.systemInfo; db.systemInfoGrid.innerHTML = `<div class="stat-card"><div class="stat-card-title">Operating System</div><div class="stat-card-value" style="font-size:1.1rem; word-break:break-all;">${si.os}</div></div><div class="stat-card"><div class="stat-card-title">PHP Version</div><div class="stat-card-value">${si.php_version}</div></div><div class="stat-card"><div class="stat-card-title">Disk Space</div><div class="stat-card-value">${filesize_formatted(si.disk_free)} free</div></div><div class="stat-card"><div class="stat-card-title">Web User</div><div class="stat-card-value">${si.web_user}</div></div>`; },
        renderChatView() { this.renderChatHistorySidebar(); this.renderActiveModelSelect(DOMElements.chat.activeModelSelect); this.renderActiveConversation(); const isChatting = appState.isAwaitingResponse; const isServerOffline = !appState.server.isRunning || appState.server.isTransitioning; DOMElements.chat.prompt.disabled = isChatting || isServerOffline; DOMElements.chat.sendBtn.style.display = isChatting ? 'none' : 'inline-flex'; DOMElements.chat.stopBtn.style.display = isChatting ? 'inline-flex' : 'none'; DOMElements.chat.sendBtn.disabled = isServerOffline; let placeholder = "Send a message..."; if (appState.server.isTransitioning) placeholder = "Server state is changing..."; else if (!appState.server.isRunning) placeholder = "Server is offline."; else if (appState.models.installed.filter(m => ['created', 'pulled'].includes(m.status)).length === 0) placeholder = "No usable models found."; else if (isChatting) placeholder = "Waiting for response..."; DOMElements.chat.prompt.labels[0].textContent = placeholder; },
        renderModelsView() {
            const list = DOMElements.models.installedList; list.innerHTML = ''; const isServerOffline = !appState.server.isRunning || appState.server.isTransitioning;
            if (isServerOffline) { list.innerHTML = `<li style="text-align:center; padding: 2rem;">Server is offline or transitioning. Model management is unavailable.</li>`; DOMElements.models.pullForm.querySelector('button').disabled = true; return; }
            DOMElements.models.pullForm.querySelector('button').disabled = false;
            if (!appState.models.installed.length) { list.innerHTML = '<li style="text-align:center; padding: 2rem;">No models found. Pull a model or upload a GGUF file.</li>'; return; }
            appState.models.installed.sort((a, b) => a.name.localeCompare(b.name)).forEach(m => {
                const li = document.createElement('li'); const size = m.size ? filesize_formatted(m.size) : 'N/A'; const family = m.details?.family || 'unknown'; let statusTag, actions; const safeName = m.name.replace(/"/g, '\"'); const safeSourceFile = m.sourceFile ? m.sourceFile.replace(/"/g, '\"') : '';
                switch (m.status) {
                    case 'pending': statusTag = `<span class="tag pending">File Only</span>`; actions = `<button class="primary-btn action-required" data-action="create" data-name="${safeName}" data-sourcefile="${safeSourceFile}"><i class="fa-solid fa-magic-wand-sparkles"></i><span class="btn-text">Create</span></button> <button class="danger-btn" data-action="delete_source" data-sourcefile="${safeSourceFile}"><i class="fa-solid fa-trash"></i></button>`; break;
                    case 'pulled': case 'created': statusTag = m.isRunning ? `<span class="tag loaded">Loaded</span>` : (m.status === 'pulled' ? `<span class="tag pulled">Pulled</span>` : `<span class="tag" style="background-color:var(--accent-blue); color:var(--bg-dark);">Created</span>`); actions = `<button title="Details" data-action="details" data-name="${safeName}"><i class="fa-solid fa-info-circle"></i></button> <button title="Copy" data-action="copy" data-name="${safeName}"><i class="fa-solid fa-copy"></i></button> <button title="Push" data-action="push" data-name="${safeName}"><i class="fa-solid fa-upload"></i></button> <button class="danger-btn" title="Delete" data-action="delete" data-name="${safeName}" data-sourcefile="${safeSourceFile}"><i class="fa-solid fa-trash"></i></button>`; break;
                }
                li.innerHTML = `<div class="model-details"><div class="name">${m.name}</div><div class="meta">${statusTag}<span><i class="fa-solid fa-database" style="margin-right:0.3rem"></i>${size}</span><span><i class="fa-solid fa-users" style="margin-right:0.3rem"></i>${family}</span></div></div><div class="model-actions">${actions}</div>`;
                list.appendChild(li);
            });
        },
        renderServerView() { const { server: srv } = DOMElements; const { isRunning, isTransitioning } = appState.server; srv.statusLight.className = `status-light ${isTransitioning ? "pending" : isRunning ? "running" : "stopped"}`; if (isTransitioning) { srv.statusText.textContent = isRunning ? 'Stopping...' : 'Starting...'; } else { srv.statusText.textContent = isRunning ? "Online" : "Offline"; } srv.startBtn.disabled = isRunning || isTransitioning; srv.stopBtn.disabled = !isRunning || isTransitioning; },
        renderSettingsView() { const params = appState.config.globalParameters || {}; for (const key in DOMElements.settings.inputs) { if (params.hasOwnProperty(key)) { DOMElements.settings.inputs[key].value = params[key]; } } },
        updateApiCommandCenter() {
            const { endpointSelect, payload, curlExample } = DOMElements.api;
            const selectedEndpoint = endpointSelect.value;
            const command = commandCenter.getCommand(selectedEndpoint);
            payload.value = command.payload ? JSON.stringify(command.payload, null, 2) : '';
            curlExample.innerHTML = `<button class="copy-to-clipboard-btn"><i class="fa-solid fa-copy"></i></button><code>${command.curl}</code>`;
            Prism.highlightElement(curlExample.querySelector('code'));
        },
        renderDiagnostics(data) {
            const { results: resultsEl, actionsContainer } = DOMElements.diagnostics; 
            resultsEl.style.display = 'block'; resultsEl.innerHTML = ''; actionsContainer.innerHTML = '';
            const statusIcons = { OK: '✅', FAIL: '❌', INFO: 'ℹ️', WARN: '⚠️' };
            const fixableActions = {};
            data.forEach(item => {
                const itemDiv = document.createElement('div'); itemDiv.className = 'diag-item'; let actionHtml = '';
                if(item.fixable) {
                    fixableActions[item.fix_key] = item.title;
                    actionHtml = `<div class="diag-action"><button class="warning-btn" data-fix-key="${item.fix_key}"><i class="fa-solid fa-screwdriver-wrench"></i><span class="btn-text">Fix Now</span></button></div>`;
                }
                itemDiv.innerHTML = `<div class="diag-info"><strong><span class="diag-status diag-status-${item.status}">${statusIcons[item.status]} ${item.title}</span></strong><span>${item.message}</span></div>${actionHtml}`;
                resultsEl.appendChild(itemDiv);
            });
            // Populate auto actions
            if (fixableActions['install_ollama']) actionsContainer.innerHTML += `<button class="primary-btn" data-auto-action="install_ollama"><i class="fa-solid fa-download"></i><span class="btn-text">Install/Update Ollama</span></button>`;
            if (Object.keys(fixableActions).some(k => k.includes('owner'))) actionsContainer.innerHTML += `<button class="primary-btn" data-auto-action="set_all_permissions"><i class="fa-solid fa-user-shield"></i><span class="btn-text">Set All File Permissions</span></button>`;
            if (fixableActions['create_default_config']) actionsContainer.innerHTML += `<button class="primary-btn" data-auto-action="create_default_config"><i class="fa-solid fa-file-code"></i><span class="btn-text">Create Default Config</span></button>`;
        },
        renderActiveModelSelect(select) { const currentModel = select.value; select.innerHTML = ""; const usableModels = appState.models.installed.filter(m => ['created', 'pulled'].includes(m.status)); if (usableModels.length > 0) { usableModels.forEach(m => select.innerHTML += `<option value="${m.name}">${m.name}</option>`); select.value = currentModel && usableModels.some(m => m.name === currentModel) ? currentModel : usableModels[0].name; } else { select.innerHTML = '<option value="">No usable models</option>'; } },
        renderChatHistorySidebar() { const list = DOMElements.chat.historyList; list.innerHTML = ""; appState.chat.history.forEach(convo => { const li = document.createElement("li"); li.dataset.id = convo.id; li.className = `history-item ${convo.id === appState.chat.activeConversationId ? "active" : ""}`; const safeTitle = convo.title.replace(/</g, "<").replace(/>/g, ">"); li.innerHTML = `<span class="history-title" title="${safeTitle}">${safeTitle}</span><button class="delete-chat-btn" data-id="${convo.id}" title="Delete chat"><i class="fa-solid fa-trash-can"></i></button>`; list.appendChild(li); }); },
        renderActiveConversation() { const messagesDiv = DOMElements.chat.messages; messagesDiv.innerHTML = ""; const activeConvo = appState.chat.history.find(c => c.id === appState.chat.activeConversationId); if (activeConvo && activeConvo.messages.length) activeConvo.messages.forEach(msg => this.addMessageToChat(msg.role, msg.content)); else { messagesDiv.innerHTML = '<div class="message-container assistant"><div class="message-bubble">Welcome! Select a model and send a message to begin.</div></div>'; } messagesDiv.scrollTop = messagesDiv.scrollHeight; },
        addMessageToChat(role, content) { const messagesDiv = DOMElements.chat.messages; if (messagesDiv.children.length === 1 && messagesDiv.children[0].textContent.includes("Welcome!")) messagesDiv.innerHTML = '';  const messageContainer = document.createElement("div"); messageContainer.className = `message-container ${role}`; const messageBubble = document.createElement("div"); messageBubble.className = "message-bubble"; const sanitized = DOMPurify.sanitize(content); messageBubble.innerHTML = marked.parse(sanitized, { breaks: true }); messageBubble.querySelectorAll('pre').forEach(pre => { if(!pre.querySelector('.copy-to-clipboard-btn')) pre.innerHTML = `<button class="copy-to-clipboard-btn"><i class="fa-solid fa-copy"></i></button><code>${pre.textContent}</code>`; }); messageBubble.querySelectorAll('pre code').forEach(el => Prism.highlightElement(el)); messageContainer.appendChild(messageBubble); messagesDiv.appendChild(messageContainer); messagesDiv.scrollTop = messagesDiv.scrollHeight; return messageBubble; },
        async showModelDetails(name) { const modal = DOMElements.detailsModal; modal.title.textContent = `Details for ${name}`; modal.params.textContent = 'Loading...'; modal.modelfile.textContent = 'Loading...'; this.showModal('model-details-modal'); try { const { data } = await api.call('get_model_details', { name }); modal.params.textContent = data.parameters || 'No parameters defined.'; modal.modelfile.textContent = data.modelfile || 'Modelfile not available.'; } catch (e) { modal.params.textContent = `Error: ${e.message}`; } }
    };
    const main = {
        async initialize() { commandCenter.initialize(); this.setupEventListeners(); chat.loadHistory(); await this.refreshData(true); this.startPolling(); },
        async refreshData(isInitialLoad = false) { try { const { data } = await api.call("get_initial_state"); appState.server.isRunning = data.serverStatus.running; appState.server.version = data.serverStatus.version; appState.models.installed = data.installedModels; appState.models.running = data.runningModels; appState.config = data.config; appState.systemInfo = data.systemInfo; ui.renderAll(); if (isInitialLoad) ui.showToast("Dashboard loaded successfully.", "success"); } catch (error) { console.error("Failed to refresh data:", error); appState.server.isRunning = false; ui.renderAll(); ui.showToast("Could not connect to backend.", "error"); } },
        startPolling() { appState.activeTaskPoller = setInterval(() => this.pollTaskStatus(), 2000); },
        async startBackgroundTask(action, payload, title) {
            const res = await api.call(action, payload);
            if (res.streamable) {
                ui.showModal("progress-modal");
                DOMElements.progressModal.title.textContent = title;
                DOMElements.progressModal.log.textContent = 'Starting task...';
                DOMElements.progressModal.stopBtn.style.display = 'inline-flex';
                DOMElements.progressModal.closeBtn.style.display = 'none';
                
                appState.commandCenter.eventSource = new EventSource(`${window.location.pathname}?action=stream_task`);
                appState.commandCenter.eventSource.onmessage = (event) => {
                    const data = JSON.parse(event.data);
                    DOMElements.progressModal.log.textContent = data.output;
                    DOMElements.progressModal.log.scrollTop = DOMElements.progressModal.log.scrollHeight;
                };
                appState.commandCenter.eventSource.addEventListener('done', () => {
                    appState.commandCenter.eventSource.close();
                    DOMElements.progressModal.stopBtn.style.display = 'none';
                    DOMElements.progressModal.closeBtn.style.display = 'inline-flex';
                    main.refreshData();
                });
            }
        },
        async pollTaskStatus() { /* Replaced by SSE streaming */ },
        setupEventListeners() {
            DOMElements.sidebar.addEventListener("click", e => { const navLink = e.target.closest(".nav-link"); if (navLink) { e.preventDefault(); ui.updateView(navLink.dataset.view); } });
            DOMElements.mainContent.addEventListener("click", e => { const btn = e.target.closest('.copy-to-clipboard-btn'); if (btn) { const code = btn.closest('pre').querySelector('code').innerText; navigator.clipboard.writeText(code).then(() => ui.showToast('Copied to clipboard!', 'info')); } });
            const handleServerAction = async (action) => { appState.server.isTransitioning = true; ui.renderServerView(); ui.renderModelsView(); ui.renderChatView(); try { await api.call(action); } finally { appState.server.isTransitioning = false; await this.refreshData(); } };
            DOMElements.server.startBtn.addEventListener("click", () => handleServerAction('start_ollama_server'));
            DOMElements.server.stopBtn.addEventListener("click", () => { if(confirm("Stop the server and unload all models?")) handleServerAction('stop_ollama_server'); });
            DOMElements.server.stopTasksBtn.addEventListener("click", () => api.call('stop_background_task'));
            DOMElements.chat.form.addEventListener("submit", e => { e.preventDefault(); chat.submit(); }); DOMElements.chat.stopBtn.addEventListener("click", () => chat.stop()); DOMElements.chat.newChatBtn.addEventListener("click", () => chat.new());
            DOMElements.chat.historyList.addEventListener("click", e => { const target = e.target; const deleteBtn = target.closest('.delete-chat-btn'); if (deleteBtn) { e.stopPropagation(); chat.delete(deleteBtn.dataset.id); return; } const li = target.closest("li"); if (li && li.dataset.id) chat.activate(li.dataset.id); });
            DOMElements.models.installedList.addEventListener("click", async e => { const button = e.target.closest("button"); if (!button) return; e.stopPropagation(); button.disabled = true; const { action, name, sourcefile } = button.dataset; try { switch (action) { case "delete": if (confirm(`Permanently delete model "${name}"?` + (sourcefile ? `\n\nThis will ALSO DELETE the source file "${sourcefile}" from your disk.` : ''))) { await api.call("delete_model", { name, sourceFile: sourcefile }); await this.refreshData(); } break; case "delete_source": if (confirm(`Delete source file "${sourcefile}" permanently?`)) { await api.call("delete_gguf", { sourceFile: sourcefile }); await this.refreshData(); } break; case "details": await ui.showModelDetails(name); break; case "copy": const dest = prompt(`Copy model "${name}" to new name:`, `${name}-copy`); if (dest) await main.startBackgroundTask('copy_model', {source: name, destination: dest}, 'Copying Model...'); break; case "push": const { pushModal: pModal } = DOMElements; pModal.sourceName.textContent = name; pModal.destination.value = name; ui.showModal('push-model-modal'); break; case "create": await main.startBackgroundTask("create_model", { name, sourceFile: sourcefile }, `Creating model: ${name}`); break; } } finally { button.disabled = false; } });
            DOMElements.pushModal.form.addEventListener('submit', async e => { e.preventDefault(); const source = DOMElements.pushModal.sourceName.textContent; const destination = DOMElements.pushModal.destination.value.trim(); if (source && destination) { ui.hideModal('push-model-modal'); await main.startBackgroundTask('push_model', { source, destination }, `Pushing model: ${destination}`); } });
            DOMElements.models.pullForm.addEventListener("submit", e => { e.preventDefault(); const name = DOMElements.models.pullName.value.trim(); if (name) main.startBackgroundTask("pull_model", { name }, `Pulling model: ${name}`); DOMElements.models.pullName.value = ''; });
            DOMElements.models.syncGgufBtn.addEventListener("click", () => { ui.showToast("Refreshing data...", "info"); this.refreshData(); });
            DOMElements.models.uploadBtn.addEventListener("click", () => DOMElements.models.fileInput.click());
            DOMElements.models.fileInput.addEventListener("change", async e => { if (!e.target.files.length) return; const formData = new FormData(); Array.from(e.target.files).forEach(file => formData.append("gguf_file[]", file)); try { await api.upload(formData); await this.refreshData(); } catch (err) {} finally { e.target.value = null; } });
            const runDiagnostics = async () => { const diag = DOMElements.diagnostics; diag.results.style.display = "block"; diag.results.innerHTML = "Running checks..."; try { const { data } = await api.call("run_diagnostics"); ui.renderDiagnostics(data); } catch (e) { diag.results.textContent = "Error running diagnostics: " + e.message; }};
            DOMElements.diagnostics.runBtn.addEventListener("click", runDiagnostics);
            DOMElements.diagnostics.results.addEventListener('click', async (e) => { const button = e.target.closest('[data-fix-key]'); if (button) { button.disabled = true; button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>'; await api.call('fix_permissions', {key: button.dataset.fixKey}); await runDiagnostics(); }});
            DOMElements.diagnostics.actionsContainer.addEventListener('click', async(e) => { const button = e.target.closest('button[data-auto-action]'); if(!button) return; const action = button.dataset.autoAction; await main.startBackgroundTask(action, {}, `Running: ${action}`); });
            DOMElements.api.form.addEventListener('submit', e => { e.preventDefault(); commandCenter.execute(); });
            DOMElements.api.endpointSelect.addEventListener('change', () => ui.updateApiCommandCenter());
            DOMElements.api.showDocsBtn.addEventListener('click', () => { const docs = DOMElements.api.docs; docs.style.display = docs.style.display === 'block' ? 'none' : 'block'; if(docs.style.display === 'block') { Prism.highlightAllUnder(docs); } });
            DOMElements.settings.form.addEventListener('submit', async (e) => { e.preventDefault(); const settings = { globalParameters: {} }; for(const key in DOMElements.settings.inputs) { settings.globalParameters[key] = DOMElements.settings.inputs[key].value; }; await api.call('save_config', settings); });
            [DOMElements.progressModal.closeBtn, DOMElements.detailsModal.closeBtn, DOMElements.pushModal.cancelBtn].forEach(btn => btn.addEventListener('click', (e) => ui.hideModal(e.target.closest('.modal').id)));
            DOMElements.progressModal.stopBtn.addEventListener('click', () => api.call('stop_background_task'));
        }
    };
    const chat = {
        loadHistory() { const history = localStorage.getItem(CHAT_HISTORY_KEY); appState.chat.history = history ? JSON.parse(history) : []; if (appState.chat.history.length === 0) this.new(false); else appState.chat.activeConversationId = appState.chat.history[0].id; },
        saveHistory() { localStorage.setItem(CHAT_HISTORY_KEY, JSON.stringify(appState.chat.history)); },
        new(render = true) { this.stop(); const newConvo = { id: crypto.randomUUID(), title: "New Chat", created: Date.now(), messages: [] }; appState.chat.history.unshift(newConvo); appState.chat.activeConversationId = newConvo.id; this.saveHistory(); if (render) ui.renderChatView(); },
        activate(id) { if (appState.isAwaitingResponse) return; this.stop(); appState.chat.activeConversationId = id; ui.renderChatView(); },
        delete(id) { if (!confirm("Are you sure you want to delete this conversation?")) return; const convoIndex = appState.chat.history.findIndex(c => c.id === id); if (convoIndex > -1) { appState.chat.history.splice(convoIndex, 1); if (appState.chat.activeConversationId === id) { appState.chat.activeConversationId = appState.chat.history.length > 0 ? appState.chat.history[0].id : null; if (!appState.chat.activeConversationId) this.new(false); } this.saveHistory(); ui.renderChatView(); } },
        async submit() {
            const prompt = DOMElements.chat.prompt.value.trim(); const activeModel = DOMElements.chat.activeModelSelect.value; if (!prompt || !activeModel || appState.isAwaitingResponse) return;
            let activeConvo = appState.chat.history.find(c => c.id === appState.chat.activeConversationId); if (!activeConvo) { this.new(); activeConvo = appState.chat.history[0]; }
            if (activeConvo.messages.length === 0) activeConvo.title = prompt.length > 30 ? prompt.substring(0, 30) + "..." : prompt;
            activeConvo.messages.push({ role: "user", content: prompt });
            ui.addMessageToChat("user", prompt); DOMElements.chat.prompt.value = ""; DOMElements.chat.prompt.style.height = 'auto';
            appState.isAwaitingResponse = true; ui.renderChatView();
            const assistantMessageBubble = ui.addMessageToChat("assistant", '<span class="thinking-cursor"></span>'); let accumulatedContent = "";
            const payload = { model: activeModel, messages: activeConvo.messages.filter(m => m.role !== 'error') };
            appState.commandCenter.eventSource = new EventSource(`${window.location.pathname}?action=stream_task&task_type=chat&payload=${encodeURIComponent(JSON.stringify(payload))}`);
            appState.commandCenter.eventSource.onmessage = (event) => { const lineData = JSON.parse(event.data); if (lineData.message && lineData.message.content) { accumulatedContent += lineData.message.content; const sanitized = DOMPurify.sanitize(accumulatedContent + '<span class="thinking-cursor"></span>'); assistantMessageBubble.innerHTML = marked.parse(sanitized, { breaks: true }); DOMElements.chat.messages.scrollTop = DOMElements.chat.messages.scrollHeight; } };
            const finalize = (errorMsg = null) => { const finalContent = accumulatedContent.trim(); let finalHtml = finalContent ? marked.parse(DOMPurify.sanitize(finalContent), { breaks: true }) : ''; if(errorMsg) finalHtml += `<p style="color:var(--accent-red)">${errorMsg}</p>`; assistantMessageBubble.innerHTML = finalHtml; if(finalContent) activeConvo.messages.push({ role: "assistant", content: finalContent }); if(errorMsg) activeConvo.messages.push({ role: "error", content: errorMsg }); assistantMessageBubble.querySelectorAll('pre').forEach(pre => { if(!pre.querySelector('.copy-to-clipboard-btn')) pre.innerHTML = `<button class="copy-to-clipboard-btn"><i class="fa-solid fa-copy"></i></button><code>${pre.textContent}</code>`; }); assistantMessageBubble.querySelectorAll('pre code').forEach(el => Prism.highlightElement(el)); this.stop(); };
            appState.commandCenter.eventSource.addEventListener('done', () => finalize()); appState.commandCenter.eventSource.onerror = () => finalize("Error: Connection to stream was lost.");
        },
        stop() { if (appState.commandCenter.eventSource) { appState.commandCenter.eventSource.close(); appState.commandCenter.eventSource = null; } appState.isAwaitingResponse = false; this.saveHistory(); ui.renderChatView(); },
    };
    const commandCenter = {
        commands: {},
        initialize() {
            const { endpointSelect } = DOMElements.api;
            this.commands = {
                '/api/chat': { group: 'Ollama API', label: 'Chat (stream)', payload: { model: 'model-name:latest', messages: [{ role: 'user', content: 'Why is the sky blue?' }], stream: true }, streamable: true },
                '/api/generate': { group: 'Ollama API', label: 'Generate (stream)', payload: { model: 'model-name:latest', prompt: 'Once upon a time', stream: true }, streamable: true },
                '/api/embeddings': { group: 'Ollama API', label: 'Embeddings', payload: { model: 'model-name:latest', prompt: 'This is a test sentence.' } },
                '/api/tags': { group: 'Ollama API', label: 'List Models (GET)', method: 'GET', payload: null },
                'start_ollama_server': { group: 'Dashboard Admin', label: 'Start Ollama Server', payload: null },
                'stop_ollama_server': { group: 'Dashboard Admin', label: 'Stop Ollama Server', payload: null },
                'pull_model': { group: 'Dashboard Admin', label: 'Pull Model', payload: { name: 'llama3:latest' }, streamable: true },
            };
            const groupedEndpoints = Object.entries(this.commands).reduce((acc, [key, val]) => {
                (acc[val.group] = acc[val.group] || []).push({key, label: val.label});
                return acc;
            }, {});
            endpointSelect.innerHTML = '';
            for(const group in groupedEndpoints) {
                const optgroup = document.createElement('optgroup');
                optgroup.label = group;
                groupedEndpoints[group].forEach(cmd => { optgroup.innerHTML += `<option value="${cmd.key}">${cmd.label}</option>`});
                endpointSelect.appendChild(optgroup);
            }
        },
        getCommand(key) {
            const command = this.commands[key];
            const payloadString = command.payload ? JSON.stringify(command.payload, null, 2) : "{}";
            const curlMethod = command.method === 'GET' ? '-X GET' : `-d '${payloadString}'`;
            const curl = `curl http://localhost:11434${key} ${curlMethod}`;
            // For admin actions, we generate a mock curl command to the dashboard itself.
            const selfCurl = `curl ${window.location.origin}${window.location.pathname} -H "Content-Type: application/json" -d '{\n  "action": "api_command",\n  "payload": {\n    "endpoint": "${key}",\n    "data": ${payloadString}\n  }\n}'`;
            return { ...command, curl: key.startsWith('/api/') ? curl : selfCurl };
        },
        async execute() {
            const { api: apiEl } = DOMElements;
            let payload; try { payload = apiEl.payload.value ? JSON.parse(apiEl.payload.value) : null; } catch (e) { ui.showToast('Invalid JSON in payload.', 'error'); return; }
            
            const endpoint = apiEl.endpointSelect.value;
            const command = this.commands[endpoint];
            
            if (command.streamable) { // Handles Chat, Generate, Pull, etc.
                if (endpoint === '/api/chat' || endpoint === '/api/generate') {
                    // Use chat's dedicated streaming UI
                    chat.stop();
                    ui.updateView('chat');
                    DOMElements.chat.activeModelSelect.value = payload.model;
                    DOMElements.chat.prompt.value = payload.messages?.[0]?.content || payload.prompt;
                    chat.submit();
                    ui.showToast(`Switched to Chat tab to run streaming command.`, 'info');
                } else {
                    // Use the progress modal for other background tasks
                    await main.startBackgroundTask(endpoint, payload, `Running: ${command.label}`);
                }
            } else { // Handles non-streaming Admin and Ollama API calls
                apiEl.output.textContent = 'Executing command...';
                apiEl.status.textContent = 'Status: Fetching...';
                try {
                    const response = await api.call('api_command', { endpoint: endpoint, data: payload });
                    apiEl.output.textContent = JSON.stringify(response, null, 2);
                    apiEl.status.textContent = 'Status: Done.';
                    if(endpoint === 'start_ollama_server' || endpoint === 'stop_ollama_server') await main.refreshData();
                } catch(e) {
                    apiEl.output.textContent = e.message;
                    apiEl.status.textContent = 'Status: Error.';
                } finally { Prism.highlightElement(apiEl.output); }
            }
        }
    };
    function filesize_formatted(bytes) { if (!isFinite(bytes) || bytes <= 0) return "0 B"; const i = Math.floor(Math.log(bytes) / Math.log(1024)); return `${parseFloat((bytes / Math.pow(1024, i)).toFixed(2))} ${["B", "KB", "MB", "GB", "TB"][i]}`; }
    main.initialize();
})();
</script>
</body>
</html>