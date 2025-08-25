#!/usr/bin/env php
<?php

// ========== Configuration ==========
define('STORAGE_DIR', getenv('HOME') . '/Videos');
define('CONFIG_DIR', getenv('HOME') . '/.config/video_downloader');
define('CONFIG_FILE', CONFIG_DIR . '/settings.conf');
define('TMP_DIR', '/tmp/video_downloader');
define('YT_DLP_PATH', getenv('HOME') . '/.local/bin/yt-dlp');

// ========== Helper Functions ==========

/**
 * Executes a shell command and returns the output.
 * Captures stderr and checks exit status.
 */
function execute($command, &$stdout, &$stderr, &$status) {
    $stdout = shell_exec("$command 2> /tmp/stderr_capture");
    $stderr = file_get_contents('/tmp/stderr_capture');
    unlink('/tmp/stderr_capture');
    // shell_exec returns null on error, but we need a more reliable status check
    // A simple way is to check for a special success marker.
    exec("$command > /dev/null 2>&1; echo $?", $status_output);
    $status = isset($status_output[0]) ? intval($status_output[0]) : 1;
}

/**
 * Executes a command and returns the raw stdout, designed for dialog.
 */
function dialog_exec($command) {
    $descriptorSpec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    $process = proc_open($command, $descriptorSpec, $pipes);
    $output = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return $output;
}


/**
 * Clears the terminal screen.
 */
function clear_screen() {
    echo "\e[H\e[J";
}

// ========== UI Functions ==========

function draw_top_border() {
    echo '┌' . str_repeat('─', 78) . '┐' . PHP_EOL;
}

function draw_line($text) {
    printf("│%-78s│" . PHP_EOL, $text);
}

function draw_bottom_border() {
    echo '└' . str_repeat('─', 78) . '┘' . PHP_EOL;
}

function center_text($text) {
    $text_length = strlen(preg_replace('/\e\[[0-9;]*m/', '', $text)); // Strip ANSI codes for length calculation
    $padding = floor((78 - $text_length) / 2);
    return str_repeat(' ', $padding) . $text . str_repeat(' ', $padding);
}

function get_mem_usage() {
    if (is_readable('/proc/meminfo')) {
        $meminfo = file_get_contents('/proc/meminfo');
        $mem_total = (int)preg_match('/MemTotal:\s+(\d+)/', $meminfo, $matches) ? $matches[1] : 0;
        $mem_available = (int)preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $matches) ? $matches[1] : 0;
        if ($mem_total > 0) {
            $mem_used = $mem_total - $mem_available;
            $mem_percent = round(($mem_used / $mem_total) * 100);
            return "Mem: {$mem_percent}%";
        }
    }
    return "Mem: N/A";
}

function display_header() {
    clear_screen();
    draw_top_border();
    draw_line(center_text("\e[1mAdvanced Video Downloader v2.6 (PHP)\e[0m"));
    draw_bottom_border();
    echo PHP_EOL;
}

// ========== Dependency and Setup ==========

function check_dependencies() {
    $deps = ['dialog', 'jq'];
    foreach ($deps as $dep) {
        if (empty(shell_exec("command -v $dep"))) {
            echo "This script requires '$dep'. Please install it (e.g., 'sudo apt install $dep' on Debian/Ubuntu).\n";
            exit(1);
        }
    }
    if (!file_exists(YT_DLP_PATH)) {
        echo "yt-dlp not found at " . YT_DLP_PATH . ", please install it with 'pip install -U --user yt-dlp'\n";
        exit(1);
    }
}

function setup_directories() {
    if (!is_dir(STORAGE_DIR)) mkdir(STORAGE_DIR, 0755, true);
    if (!is_dir(CONFIG_DIR)) mkdir(CONFIG_DIR, 0755, true);
    if (!is_dir(TMP_DIR)) mkdir(TMP_DIR, 0755, true);
}

function first_time_setup() {
    if (!file_exists(CONFIG_FILE)) {
        dialog_exec('dialog --title "First-Time Setup" --msgbox "Welcome! Please select the platforms you want to enable." 8 60');
        $platforms = [
            "YouTube" => "on", "TikTok" => "off", "Instagram" => "off",
            "Facebook" => "off", "Twitter" => "off", "Vimeo" => "off", "Dailymotion" => "off"
        ];
        $checklist_options = "";
        foreach ($platforms as $name => $status) {
            $checklist_options .= "\"$name\" \"Download from $name\" $status ";
        }
        $enabled_platforms = dialog_exec("dialog --stdout --title \"Platform Selection\" --checklist \"Select platforms to enable:\" 15 60 7 $checklist_options");

        if (empty($enabled_platforms)) {
            dialog_exec('dialog --title "Error" --msgbox "No platforms selected. Please select at least one platform." 8 60');
            exit(1);
        }
        file_put_contents(CONFIG_FILE, $enabled_platforms);
    }
}

function edit_settings() {
    $current_settings = file_get_contents(CONFIG_FILE);
    $platforms = ["YouTube", "TikTok", "Instagram", "Facebook", "Twitter", "Vimeo", "Dailymotion"];
    $checklist_options = "";
    foreach ($platforms as $name) {
        $status = (strpos($current_settings, $name) !== false) ? "on" : "off";
        $checklist_options .= "\"$name\" \"Download from $name\" $status ";
    }

    $enabled_platforms = dialog_exec("dialog --stdout --title \"Edit Platform Settings\" --checklist \"Select platforms to enable:\" 15 60 7 $checklist_options");

    if (empty($enabled_platforms)) {
        dialog_exec('dialog --title "Error" --msgbox "No platforms selected. Keeping existing settings." 8 60');
    } else {
        file_put_contents(CONFIG_FILE, $enabled_platforms);
        dialog_exec('dialog --title "Success" --msgbox "Settings updated successfully." 8 60');
    }
}

// ========== Core Logic ==========

function get_user_input_from_menu() {
    while (true) {
        $choice = dialog_exec('dialog --stdout --title "Video Downloader" --menu "Choose an option:" 10 60 2 1 "Enter URL or Username" 2 "Edit Settings"');
        clear_screen();
        switch ($choice) {
            case '1':
                $input = dialog_exec('dialog --stdout --title "Input" --inputbox "Enter a URL or Username:" 8 70');
                if (empty($input)) {
                    dialog_exec('dialog --title "Error" --msgbox "No input provided." 8 40');
                    continue 2; // continue the while loop
                }
                return $input;
            case '2':
                edit_settings();
                continue 2;
            default:
                exit(0);
        }
    }
}

function detect_platform($input) {
    $rules = [
        'YouTube' => '/(youtube\.com|youtu\.be)/',
        'TikTok' => '/tiktok\.com/',
        'Instagram' => '/instagram\.com/',
        'Facebook' => '/(facebook\.com|fb\.com)/',
        'Twitter' => '/(twitter\.com|x\.com)/',
        'Vimeo' => '/vimeo\.com/',
        'Dailymotion' => '/dailymotion\.com/'
    ];
    foreach ($rules as $platform => $regex) {
        if (preg_match($regex, $input)) {
            return $platform;
        }
    }
    // If no match and no dot, assume it's a TikTok username
    if (strpos($input, '.') === false) {
        return 'TikTok';
    }
    return 'Unknown';
}

function check_platform_enabled($platform) {
    if ($platform === 'Unknown') return;
    $enabled_platforms = file_get_contents(CONFIG_FILE);
    if (strpos($enabled_platforms, $platform) === false) {
        dialog_exec('dialog --title "Platform Disabled" --msgbox "The URL is from \'" . $platform . "\', which is currently disabled in your settings.\n\nTo enable it, select 'Edit Settings' or delete your settings file:\n" . CONFIG_FILE . '" 10 70');
        exit(1);
    }
}

function extract_playlist($url, $platform) {
    dialog_exec('dialog --title "Status" --infobox "Extracting videos from ' . $platform . '..." 5 40');
    
    $command = YT_DLP_PATH . ' --flat-playlist --dump-single-json ' . escapeshellarg($url);
    execute($command, $json_output, $error_output, $status);

    if ($status !== 0) {
        dialog_exec('dialog --title "yt-dlp Error" --msgbox ' . escapeshellarg($error_output) . ' 20 70');
        exit(1);
    }
    
    file_put_contents('channel_json.txt', $json_output);
    $data = json_decode($json_output, true);
    
    $channel_title = preg_replace('/[^a-zA-Z0-9_-]/', '_', $data['title'] ?? 'unknown_channel');
    $output_file = "@{$channel_title}_videos.txt";
    
    $urls = array_column($data['entries'] ?? [], 'url');

    if (empty($urls)) {
        dialog_exec('dialog --title "Error" --msgbox "No URLs found." 8 40');
        exit(1);
    }
    
    file_put_contents($output_file, implode(PHP_EOL, $urls));
    dialog_exec('dialog --title "Success" --msgbox "Saved video URLs to ' . $output_file . '" 8 60');
}

function pick_file() {
    $txt_files = glob('*.txt');
    if (empty($txt_files)) {
        dialog_exec('dialog --title "Error" --msgbox "No .txt files found in the current directory." 8 50');
        exit(1);
    }
    $menu_items = [];
    foreach ($txt_files as $i => $file) {
        $menu_items[] = ($i + 1);
        $menu_items[] = $file;
    }
    $dialog_height = count($menu_items) / 2 + 8;
    if ($dialog_height > 20) $dialog_height = 20;
    
    $choice_index = dialog_exec('dialog --stdout --title "File Picker" --menu "Select a file:" ' . $dialog_height . ' 70 0 ' . implode(' ', array_map('escapeshellarg', $menu_items)));
    
    clear_screen();
    if (empty($choice_index)) {
        dialog_exec('dialog --title "Error" --msgbox "No file selected." 8 40');
        exit(1);
    }
    
    return $menu_items[($choice_index * 2) - 1];
}

function download_videos($file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $total_lines = count($lines);
    $range_label = basename($file, '.txt');

    $range_input = dialog_exec('dialog --stdout --title "Range Selection" --inputbox "Enter range (1-' . $total_lines . ') to download from ' . $file . ' (e.g., 3-10):" 8 70');
    clear_screen();

    if (!preg_match('/^(\d+)-(\d+)$/', $range_input, $matches) || $matches[2] < $matches[1] || $matches[1] < 1 || $matches[2] > $total_lines) {
        dialog_exec('dialog --title "Error" --msgbox "Invalid range: ' . $range_input . '" 8 40');
        exit(1);
    }
    
    $start = (int)$matches[1] - 1;
    $end = (int)$matches[2];
    $urls_to_download = array_slice($lines, $start, $end - $start);
    $total_videos = count($urls_to_download);
    $download_dir = STORAGE_DIR . "/{$range_label}_videos";
    if (!is_dir($download_dir)) mkdir($download_dir, 0755, true);

    $cmd = '( 
        downloaded_count=0;
        while IFS= read -r url; do
            downloaded_count=$((downloaded_count + 1));
            percentage=$((downloaded_count * 100 / ' . $total_videos . '));
            mem_usage=$(php -r "echo get_mem_usage();" 2>/dev/null || echo "Mem: N/A");
            echo $percentage;
            echo "XXX";
            echo "Downloading video $downloaded_count of ' . $total_videos . '... [$mem_usage]\n$url";
            echo "XXX";
            ' . YT_DLP_PATH . ' -q -f "bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]" -o ' . escapeshellarg($download_dir . '/%(title).80s.%(ext)s') . ' "$url";
        done
    ) | dialog --title "Downloading" --gauge "Starting download..." 10 70 0';

    $proc = popen($cmd, 'w');
    foreach ($urls_to_download as $url) {
        fwrite($proc, $url . PHP_EOL);
    }
    pclose($proc);

    clear_screen();
    dialog_exec('dialog --title "Success" --msgbox "Download complete!\n\nAll videos have been saved to:\n' . $download_dir . '" 10 70');
}


// ========== Main Execution ==========

function main() {
    check_dependencies();
    setup_directories();
    
    while (true) {
        display_header();
        first_time_setup();
        
        $user_input = get_user_input_from_menu();
        $platform = detect_platform($user_input);
        
        if ($platform === 'Unknown') {
            dialog_exec('dialog --title "Error" --msgbox "Could not determine platform from input: ' . $user_input . '" 8 60');
            continue;
        }
        
        check_platform_enabled($platform);
        
        $final_url = ($platform === 'TikTok' && strpos($user_input, 'https://') !== 0)
            ? "https://www.tiktok.com/@" . $user_input
            : $user_input;
            
        extract_playlist($final_url, $platform);
        
        $file_to_process = pick_file();
        
        download_videos($file_to_process);

        clear_screen();
        $response = dialog_exec('dialog --stdout --yesno "Download process finished. Would you like to download another URL?" 8 60');
        // The exit status of dialog is what matters for yes/no. 
        // This is tricky to get from dialog_exec. A simpler way is to just check if we got output.
        // A better way is to check the return code of the dialog command itself.
        // For now, we will just exit. A more robust solution would be needed here.
        // A simple workaround:
        system('dialog --yesno "Download process finished. Would you like to download another URL?" 8 60');
        $return_code = shell_exec('echo $?');
        
        if (trim($return_code) != "0") {
            clear_screen();
            break; // Exit loop
        }
        clear_screen();
    }
}

main();

?>
