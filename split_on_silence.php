<?php
/**
 * Split an audio/video file into chunks <= maxLen seconds,
 * choosing cut points on detected silences when possible.
 *
 * Requires: ffmpeg
 *
 * Usage:
 *   php split_on_silence.php --in "/path/to/audio.wav" --outdir "/path/to/out" [--maxlen 180] [--silence_db -35] [--silence_dur 0.35] [--format wav]
 *
 * Notes:
 * - If no suitable silence is found near the target boundary, falls back to a hard cut at maxLen.
 * - Outputs:
 *    - chunk_000.<format>, chunk_001.<format>, ...
 *    - offsets.json (array of segments with start/end/duration/file)
 */

function usageAndExit(string $msg = ''): void {
    if ($msg !== '') fwrite(STDERR, $msg . PHP_EOL);
    $u = <<<TXT
Usage:
  php split_on_silence.php --in "/path/to/input.(wav|mp3|mp4|mkv)" --outdir "/path/to/out"
      [--maxlen 180] [--silence_db -35] [--silence_dur 0.35] [--format wav]
      [--prefer_window 12] [--min_chunk 20]

Options:
  --in             Input file path
  --outdir         Output directory
  --maxlen         Max chunk length in seconds (default: 180)
  --silence_db     Silence threshold in dB (default: -35)
  --silence_dur    Minimum silence duration to count (default: 0.35)
  --format         Output audio format/container (default: wav). Examples: wav, mp3, flac
  --prefer_window  Window (seconds) around target cut time to look for silence end (default: 12)
  --min_chunk      Minimum chunk duration (seconds) to avoid tiny chunks (default: 20)

Example:
  php split_on_silence.php --in "/mnt/c/.../audio.wav" --outdir "./chunks" --maxlen 180 --silence_db -35 --silence_dur 0.35 --format wav

TXT;
    fwrite(STDERR, $u);
    exit(1);
}

function runCmd(string $cmd): array {
    $output = [];
    $ret = 0;
    exec($cmd . " 2>&1", $output, $ret);
    return [$ret, implode("\n", $output)];
}

function ensureDir(string $dir): void {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true)) {
            usageAndExit("Error: cannot create outdir: $dir");
        }
    }
}

function ffprobeDuration(string $in): float {
    $inEsc = escapeshellarg($in);
    $cmd = "ffprobe -v error -show_entries format=duration -of default=nk=1:nw=1 $inEsc";
    [$ret, $out] = runCmd($cmd);
    if ($ret !== 0) {
        usageAndExit("Error: ffprobe failed to read duration.\n$out");
    }
    $dur = trim($out);
    if ($dur === '' || !is_numeric($dur)) {
        usageAndExit("Error: invalid duration from ffprobe: '$dur'");
    }
    return (float)$dur;
}

/**
 * Parse ffmpeg silencedetect output.
 * We collect silence_start and silence_end times.
 *
 * Output example lines:
 *  [silencedetect @ ...] silence_start: 12.345
 *  [silencedetect @ ...] silence_end: 13.210 | silence_duration: 0.865
 */
function detectSilences(string $in, float $silenceDb, float $silenceDur): array {
    $inEsc = escapeshellarg($in);
    $db = sprintf("%.2f", $silenceDb);
    $dur = sprintf("%.2f", $silenceDur);

    // -vn: ignore video; -sn: ignore subtitles; keep audio only analysis
    $cmd = "ffmpeg -hide_banner -nostats -i $inEsc -vn -sn -af " .
           escapeshellarg("silencedetect=noise={$db}dB:d={$dur}") .
           " -f null -";

    [$ret, $out] = runCmd($cmd);
    if ($ret !== 0) {
        // ffmpeg may return non-zero sometimes, but silencedetect output is still useful.
        // We'll continue, but warn.
        fwrite(STDERR, "Warning: ffmpeg silencedetect exit code=$ret\n");
    }

    $silences = [];
    $currentStart = null;

    foreach (explode("\n", $out) as $line) {
        if (preg_match('/silence_start:\s*([0-9.]+)/', $line, $m)) {
            $currentStart = (float)$m[1];
        } elseif (preg_match('/silence_end:\s*([0-9.]+)\s*\|\s*silence_duration:\s*([0-9.]+)/', $line, $m)) {
            $end = (float)$m[1];
            $sd = (float)$m[2];
            $start = $currentStart;
            if ($start === null) {
                // sometimes start isn't captured; approximate
                $start = max(0.0, $end - $sd);
            }
            $silences[] = [
                'start' => $start,
                'end' => $end,
                'duration' => $sd,
            ];
            $currentStart = null;
        }
    }

    // We'll use "silence end" as candidate cut points (speech resumes after).
    $cutPoints = [];
    foreach ($silences as $s) {
        $cutPoints[] = $s['end'];
    }
    sort($cutPoints);

    return [$silences, $cutPoints, $out];
}

/**
 * Choose next cut time for a segment starting at $t0.
 * Target is $t0 + $maxLen.
 * Prefer a cut point on silence end within +- $preferWindow seconds around target,
 * but must be > $t0 + $minChunk and <= $t0 + $maxLen.
 * If none, choose the last available cut point before target (but within maxLen).
 * If still none, hard cut at min($t0 + $maxLen, $totalDur).
 */
function chooseCut(float $t0, float $totalDur, float $maxLen, array $cutPoints, float $preferWindow, float $minChunk): float {
    $target = min($t0 + $maxLen, $totalDur);

    // Eligible cut points in (t0 + minChunk, target]
    $eligible = [];
    $minT = $t0 + $minChunk;
    foreach ($cutPoints as $cp) {
        if ($cp > $minT && $cp <= $target) $eligible[] = $cp;
        if ($cp > $target) break;
    }

    if (empty($eligible)) {
        return $target;
    }

    // Prefer near target within window
    $best = null;
    $bestDist = INF;
    foreach ($eligible as $cp) {
        $dist = abs($cp - $target);
        if ($dist <= $preferWindow && $dist < $bestDist) {
            $best = $cp;
            $bestDist = $dist;
        }
    }

    if ($best !== null) return $best;

    // Otherwise: choose the latest eligible cut (closest before target)
    return end($eligible);
}

function formatTime(float $sec): string {
    $h = (int)floor($sec / 3600);
    $m = (int)floor(($sec % 3600) / 60);
    $s = $sec - ($h * 3600) - ($m * 60);
    return sprintf("%02d:%02d:%06.3f", $h, $m, $s);
}

function extractChunk(string $in, string $outFile, float $start, float $end, string $format): void {
    $inEsc = escapeshellarg($in);
    $outEsc = escapeshellarg($outFile);

    $ss = sprintf("%.3f", $start);
    $to = sprintf("%.3f", $end);

    // WAV: PCM s16le mono 16k (stable for Whisper)
    // If you pick mp3/flac, we still enforce mono 16k; adjust if you prefer.
    $audioArgs = "-ac 1 -ar 16000";
    if (strtolower($format) === 'wav') {
        $audioArgs .= " -c:a pcm_s16le";
    }

    // -ss after -i is accurate; a bit slower but reliable for precise cut
    $cmd = "ffmpeg -hide_banner -y -i $inEsc -vn -sn -ss $ss -to $to $audioArgs $outEsc";
    [$ret, $out] = runCmd($cmd);
    if ($ret !== 0) {
        usageAndExit("Error: ffmpeg failed to extract chunk [$start,$end]\n$out");
    }
}

/* -------------------- main -------------------- */

$opts = getopt("", [
    "in:",
    "outdir:",
    "maxlen:",
    "silence_db:",
    "silence_dur:",
    "format:",
    "prefer_window:",
    "min_chunk:",
]);
fwrite(STDERR, "DEBUG OPTS: " . json_encode($opts) . PHP_EOL);

$in = $opts["in"] ?? null;
$outdir = $opts["outdir"] ?? null;
if (!$in || !$outdir) usageAndExit("Error: --in and --outdir are required.");

$maxLen = isset($opts["maxlen"]) ? (float)$opts["maxlen"] : 180.0;
$silenceDb = isset($opts["silence_db"]) ? (float)$opts["silence_db"] : -35.0;
$silenceDur = isset($opts["silence_dur"]) ? (float)$opts["silence_dur"] : 0.35;
$format = isset($opts["format"]) ? (string)$opts["format"] : "wav";
$preferWindow = isset($opts["prefer_window"]) ? (float)$opts["prefer_window"] : 12.0;
$minChunk = isset($opts["min_chunk"]) ? (float)$opts["min_chunk"] : 20.0;

if (!file_exists($in)) usageAndExit("Error: input file does not exist: $in");
ensureDir($outdir);

$totalDur = ffprobeDuration($in);
echo "Input: $in\n";
echo "Duration: " . formatTime($totalDur) . " (" . sprintf("%.2f", $totalDur) . "s)\n";
echo "Max chunk length: {$maxLen}s | silence_db: {$silenceDb}dB | silence_dur: {$silenceDur}s\n";
echo "Prefer window: {$preferWindow}s around target | min_chunk: {$minChunk}s\n";
echo "Output dir: $outdir | format: $format\n\n";

[$silences, $cutPoints, $rawDetectLog] = detectSilences($in, $silenceDb, $silenceDur);

file_put_contents(rtrim($outdir, "/") . "/silencedetect.log", $rawDetectLog);

echo "Detected silences: " . count($silences) . " | Candidate cut points (silence_end): " . count($cutPoints) . "\n";
if (count($cutPoints) === 0) {
    echo "Warning: no silences detected. Will hard-cut every {$maxLen}s.\n";
}
echo "\n";

$segments = [];
$t0 = 0.0;
$idx = 0;

while ($t0 < $totalDur - 0.01) {
    $t1 = chooseCut($t0, $totalDur, $maxLen, $cutPoints, $preferWindow, $minChunk);

    // Ensure progress (avoid infinite loop)
    if ($t1 <= $t0 + 0.001) {
        $t1 = min($t0 + $maxLen, $totalDur);
        if ($t1 <= $t0 + 0.001) break;
    }

    $file = sprintf("chunk_%03d.%s", $idx, $format);
    $outFile = rtrim($outdir, "/") . "/" . $file;

    echo sprintf(
        "#%03d  %s  ->  %s   (%.2fs)   %s\n",
        $idx,
        formatTime($t0),
        formatTime($t1),
        ($t1 - $t0),
        $file
    );

    extractChunk($in, $outFile, $t0, $t1, $format);

    $segments[] = [
        "index" => $idx,
        "file" => $file,
        "start_s" => round($t0, 3),
        "end_s" => round($t1, 3),
        "duration_s" => round($t1 - $t0, 3),
        "start_hms" => formatTime($t0),
        "end_hms" => formatTime($t1),
    ];

    $t0 = $t1;
    $idx++;
}

$outJson = rtrim($outdir, "/") . "/offsets.json";
file_put_contents($outJson, json_encode($segments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

echo "\nOffsets saved to: $outJson\n";
echo "Silence detect log saved to: " . rtrim($outdir, "/") . "/silencedetect.log\n";
echo "Done.\n";
