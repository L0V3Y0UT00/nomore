<?php
/**
 * Advanced Video Downloader v2.0 — Web (PHP) Edition
 * --------------------------------------------------
 * What this does (parity with your Bash script):
 * 1) Detects platform from Channel URL/Username (YouTube or TikTok)
 * 2) Uses yt-dlp --flat-playlist to extract video IDs
 * 3) Saves normalized URLs into a @<channel>_shorts.txt file
 * 4) Lets you pick any .txt file on server, preview contents with line numbers
 * 5) Choose a numeric range (start-end) to download only those items
 * 6) Downloads into data/<file_basename>_videos using mp4 pref
 * 7) Shows disk space; basic progress output (best-effort in web)
 *
 * Requirements on the server:
 * - PHP 8+
 * - shell_exec/system enabled (not disabled by hosting)
 * - yt-dlp in PATH (or set $YTDLP absolute path below)
 * - ffmpeg in PATH (for muxing bestvideo+bestaudio)
 *
 * NOTE: On shared hosting this may not work if exec is disabled.
 */

// ================== Config ==================
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
@ini_set('max_execution_time', '0');

ob_implicit_flush(true);
while (ob_get_level() > 0) { ob_end_flush(); }

$DATA_DIR = __DIR__ . '/data';
if (!is_dir($DATA_DIR)) { @mkdir($DATA_DIR, 0777, true); }

$YTDLP = 'yt-dlp';         // or absolute path e.g. /usr/local/bin/yt-dlp
$FFMPEG = 'ffmpeg';        // ensure ffmpeg exists in PATH
$TITLE = 'Advanced Video Downloader v2.0 — Web';
$SELF  = basename(__FILE__);

// ================== Helpers ==================
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function run($cmd) {
    // Run a shell command and return [exitCode, stdout, stderr]
    $descriptor = [1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = proc_open($cmd, $descriptor, $pipes);
    if (!\is_resource($proc)) return [1, '', 'proc_open failed'];
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code, $out, $err];
}
function platform_from_input(string $in): array {
    $in = trim($in);
    if (preg_match('/^[a-zA-Z0-9_.]+$/', $in)) {
        return ['tiktok', "https://www.tiktok.com/@$in"];
    }
    if (preg_match('#^https?://(www\.)?tiktok\.com/@[a-zA-Z0-9_.-]+$#', $in)) {
        return ['tiktok', $in];
    }
    if (preg_match('#^https?://#', $in)) {
        return ['youtube', $in];
    }
    return ['invalid', $in];
}
function normalize_urls(array $entries, string $platform, string $finalUrl): array {
    $out = [];
    foreach ($entries as $e) {
        if (!is_array($e)) continue;
        $id = $e['url'] ?? null; // yt-dlp flat returns id or url
        if (!$id) continue;
        if ($platform === 'tiktok') {
            $username = basename(parse_url($finalUrl, PHP_URL_PATH)); // @user
            $username = ltrim($username, '/');
            $out[] = "https://www.tiktok.com/$username/video/" . basename($id);
        } else {
            $out[] = "https://www.youtube.com/shorts/" . basename($id);
        }
    }
    return $out;
}
function human_bytes($bytes): string {
    $units = ['B','KB','MB','GB','TB'];
    $i = 0; $val = (float)$bytes;
    while ($val >= 1024 && $i < count($units)-1) { $val /= 1024; $i++; }
    return sprintf('%.2f %s', $val, $units[$i]);
}

// ================== Routing ==================
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ================== UI Header ==================
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title><?=h($TITLE)?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
    :root { --bg:#0b1020; --card:#121832; --text:#e7ecff; --muted:#9aa7d9; --accent:#6ea8fe; --ok:#86efac; --warn:#fde047; --err:#fca5a5; }
    *{box-sizing:border-box} body{margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--text)}
    .wrap{max-width:1100px;margin:24px auto;padding:0 16px}
    .card{background:var(--card);border:1px solid #2b3763;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.25);padding:20px}
    h1{font-size:24px;margin:0 0 10px} .sub{color:var(--muted);margin-top:0}
    .row{display:grid;grid-template-columns:1fr;gap:16px}
    @media(min-width:900px){.row{grid-template-columns:1.2fr .8fr}}
    label{font-weight:600;display:block;margin:8px 0}
    input[type=text], input[type=number], select{width:100%;padding:12px 14px;border-radius:12px;border:1px solid #2b3763;background:#0e1430;color:var(--text)}
    button{appearance:none;border:0;border-radius:12px;padding:12px 16px;font-weight:700;color:#0b1020;background:var(--accent);cursor:pointer}
    button.secondary{background:#2b3763;color:var(--text)}
    .muted{color:var(--muted)} .ok{color:var(--ok)} .warn{color:var(--warn)} .err{color:var(--err)}
    pre.log{background:#0a0f22;border:1px solid #22305f;border-radius:12px;padding:12px;white-space:pre-wrap;max-height:420px;overflow:auto}
    .list{border:1px solid #2b3763;border-radius:12px;overflow:hidden}
    .list .item{display:flex;gap:12px;padding:10px 12px;border-top:1px solid #2b3763}
    .list .item:first-child{border-top:0}
    .grid{display:grid;grid-template-columns:48px 1fr;gap:12px}
    .section{margin-top:18px}
    .hint{font-size:12px;color:var(--muted)}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Advanced Video Downloader v2.0 — Web</h1>
    <p class="sub">YouTube & TikTok — extract shorts list, save to file, pick a range, and download with yt-dlp.</p>

    <div class="row">
      <div>
        <!-- Step 1: Extract playlist from URL/username -->
        <form method="post" class="section">
          <input type="hidden" name="action" value="extract">
          <label>Channel URL or Username</label>
          <input type="text" name="user_input" placeholder="e.g. https://www.youtube.com/@GoogleDevelopers or tiktok username" required>
          <div style="display:flex;gap:8px;margin-top:12px">
            <button type="submit">Extract Videos</button>
            <a class="hint" href="?">Reset</a>
          </div>
          <p class="hint">We run: <code>yt-dlp --flat-playlist --dump-single-json &lt;url&gt;</code>. Server must have yt-dlp & ffmpeg.</p>
        </form>

        <!-- Step 2: Pick a .txt file and range -->
        <form method="post" class="section">
          <input type="hidden" name="action" value="prepare_download">
          <label>Pick a saved list (.txt in /data)</label>
          <select name="file" required>
            <option value="">-- choose file --</option>
            <?php
            $txts = glob($DATA_DIR.'/*.txt');
            sort($txts);
            foreach ($txts as $f) {
                echo '<option value="'.h(basename($f)).'">'.h(basename($f))."</option>"; 
            }
            ?>
          </select>
          <div class="grid section">
            <div>
              <label>Start #</label>
              <input type="number" name="start" min="1" placeholder="e.g. 1">
            </div>
            <div>
              <label>End #</label>
              <input type="number" name="end" min="1" placeholder="e.g. 25">
            </div>
          </div>
          <div class="section" style="display:flex;gap:8px;align-items:center">
            <button type="submit" class="secondary">Preview File</button>
          </div>
        </form>
      </div>

      <div>
        <!-- Status / Environment Panel -->
        <div class="section">
          <h3>Environment</h3>
          <div class="list">
            <?php
              [$code1] = run("command -v ".$YTDLP);
              [$code2] = run("command -v ".$FFMPEG);
              $free = function_exists('disk_free_space') ? disk_free_space($DATA_DIR) : 0;
              $total= function_exists('disk_total_space') ? disk_total_space($DATA_DIR) : 0;
            ?>
            <div class="item"><strong>yt-dlp:</strong> <span class="<?= $code1===0?'ok':'err' ?>"><?= $code1===0?'Found':'Missing' ?></span></div>
            <div class="item"><strong>ffmpeg:</strong> <span class="<?= $code2===0?'ok':'err' ?>"><?= $code2===0?'Found':'Missing' ?></span></div>
            <div class="item"><strong>Data dir:</strong> <code><?= h($DATA_DIR) ?></code></div>
            <div class="item"><strong>Disk:</strong> <?= h(human_bytes($free)) ?> free / <?= h(human_bytes($total)) ?> total</div>
          </div>
        </div>
      </div>
    </div>

    <hr class="section" style="border-color:#2b3763;opacity:.3">

    <?php
    // ================== ACTION HANDLERS ==================
    if ($action === 'extract') {
        $input = trim($_POST['user_input'] ?? '');
        [$platform, $finalUrl] = platform_from_input($input);
        if ($platform === 'invalid') {
            echo '<p class="err">Invalid input.</p>';
        } else {
            echo '<h3>Extracting from platform: '.h($platform).'</h3>';
            $cmd = $YTDLP.' --flat-playlist --dump-single-json '.escapeshellarg($finalUrl).' 2> '.escapeshellarg($DATA_DIR.'/yt-dlp-error.log');
            [$code, $out, $err] = run($cmd);
            if ($code !== 0 || !$out) {
                echo '<p class="err">yt-dlp failed.</p>';
                $elog = @file_get_contents($DATA_DIR.'/yt-dlp-error.log');
                echo '<pre class="log">'.h($elog ?: $err).'</pre>';
            } else {
                file_put_contents($DATA_DIR.'/last_channel.json', $out);
                $json = json_decode($out, true);
                $title = $json['title'] ?? 'unknown_channel';
                $safeTitle = preg_replace('/[^a-zA-Z0-9_-]/', '_', $title);
                $outfile = '@'.$safeTitle.'_shorts.txt';

                $urls = normalize_urls($json['entries'] ?? [], $platform, $finalUrl);
                if (!$urls) {
                    echo '<p class="err">No URLs found.</p>';
                } else {
                    file_put_contents($DATA_DIR.'/'.$outfile, implode("\n", $urls));
                    echo '<p class="ok">Saved to <code>'.h($outfile).'</code></p>';
                    echo '<div class="list">';
                    foreach ($urls as $i => $u) {
                        echo '<div class="item"><span>#'.($i+1).'</span> <code style="word-break:break-all">'.h($u).'</code></div>';
                    }
                    echo '</div>';
                }
            }
        }
    }

    if ($action === 'prepare_download') {
        $file = basename(trim($_POST['file'] ?? ''));
        $start = max(1, (int)($_POST['start'] ?? 1));
        $end   = max($start, (int)($_POST['end'] ?? $start));

        $path = $DATA_DIR.'/'.$file;
        if (!is_file($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'txt') {
            echo '<p class="err">Invalid file selected.</p>';
        } else {
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            $total = count($lines);
            if ($start < 1) $start = 1;
            if ($end > $total) $end = $total;

            echo '<h3>Preview: '.h($file).' ('.$total.' lines)</h3>';
            echo '<div class="list">';
            foreach ($lines as $i => $line) {
                echo '<div class="item"><strong>'.($i+1).'.</strong> <code style="word-break:break-all">'.h($line).'</code></div>';
            }
            echo '</div>';

            // Download form
            echo '<form method="post" class="section">';
            echo '<input type="hidden" name="action" value="download">';
            echo '<input type="hidden" name="file" value="'.h($file).'">';
            echo '<div class="grid">';
            echo '  <div><label>Start #</label><input type="number" name="start" value="'.h($start).'" min="1"></div>';
            echo '  <div><label>End #</label><input type="number" name="end" value="'.h($end).'" min="1"></div>';
            echo '</div>';
            echo '<div class="section" style="display:flex;gap:8px;align-items:center">';
            echo '  <button type="submit">Start Downloads</button>';
            echo '  <a class="hint" href="?">Back</a>';
            echo '</div>';
            echo '</form>';
        }
    }

    if ($action === 'download') {
        $file = basename(trim($_POST['file'] ?? ''));
        $start = (int)($_POST['start'] ?? 1);
        $end   = (int)($_POST['end'] ?? 1);
        $path = $DATA_DIR.'/'.$file;

        if (!is_file($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'txt') {
            echo '<p class="err">Invalid file selected.</p>';
        } else {
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            $total = count($lines);
            if ($start < 1 || $end < $start || $end > $total) {
                echo '<p class="err">Invalid range: '.h($start).'-'.h($end).'</p>';
            } else {
                $slice = array_slice($lines, $start-1, $end-$start+1);
                $label = pathinfo($path, PATHINFO_FILENAME);
                $outdir = $DATA_DIR.'/'.$label.'_videos';
                if (!is_dir($outdir)) @mkdir($outdir, 0777, true);

                echo '<h3>Downloading '.count($slice).' videos → <code>'.h($outdir).'</code></h3>';
                echo '<pre class="log">';

                $i = 0; $n = count($slice);
                foreach ($slice as $u) {
                    $i++;
                    echo "\n[".$i."/".$n."] URL: ".h($u)."\n";
                    @ob_flush(); @flush();

                    // Build yt-dlp command (same format logic as Bash)
                    $tpl = $outdir.'/%(title).80s.%(ext)s';
                    $cmd = $YTDLP
                         . ' -q --progress '
                         . ' -f "bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]"'
                         . ' -o '.escapeshellarg($tpl)
                         . ' '.escapeshellarg($u);

                    [$code, $out, $err] = run($cmd);
                    if ($code === 0) {
                        echo "✔ Done\n";
                    } else {
                        echo "✖ Failed (exit $code)\n";
                        if ($err) echo $err."\n"; else if ($out) echo $out."\n";
                    }
                    @ob_flush(); @flush();
                }

                echo "\nAll tasks finished.\n";
                echo '</pre>';

                echo '<div class="section" style="display:flex;gap:8px">';
                echo '<a href="?" class="hint">Back</a>';
                echo '</div>';
            }
        }
    }
    ?>

  </div>
</div>
</body>
</html>
